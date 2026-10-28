package main

// Optional IpNetwork transport. Website-scoped grants remain the default.
import (
	"bytes"
	"context"
	"database/sql"
	"encoding/base64"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"

	capnp "capnproto.org/go/capnp/v3"
	"capnproto.org/go/capnp/v3/rpc"
	"sandstorm.org/go/tempest/capnp/grain"
	"sandstorm.org/go/tempest/capnp/ip"
	"sandstorm.org/go/tempest/capnp/powerbox"
	bridge "sandstorm.org/go/tempest/capnp/sandstorm-http-bridge"
	"sandstorm.org/go/tempest/pkg/exp/util/bytestream"
)

const networkKey = "sandstorm:ip-network"
const networkAutoKey = "sandstorm:ip-network-auto-disconnect"

var networkAPIOpener = openNetworkAPI

type networkAccess struct {
	mu     sync.Mutex
	active map[*rpc.Conn]context.CancelFunc
}

func openNetworkAPI(ctx context.Context) (*rpc.Conn, grain.SandstormApi, func(), error) {
	socket, err := (&net.Dialer{}).DialContext(ctx, "unix", "/tmp/sandstorm-api")
	if err != nil {
		return nil, grain.SandstormApi{}, nil, err
	}
	conn := rpc.NewConn(rpc.NewStreamTransport(socket), nil)
	b := bridge.SandstormHttpBridge(conn.Bootstrap(ctx))
	defer b.Release()
	future, release := b.GetSandstormApi(ctx, nil)
	return conn, future.Api(), release, nil
}

// Bound the bridge call as well as RPC operations so a stalled claim cannot
// hold the settings lock indefinitely.
func claimNetwork(ctx context.Context, result *PowerboxResult) (string, error) {
	body, err := json.Marshal(map[string]interface{}{
		"requestToken": result.ClaimToken, "requiredPermissions": []string{"access"},
	})
	if err != nil {
		return "", err
	}
	req, err := http.NewRequestWithContext(ctx, "POST", "http://http-bridge/session/"+result.SessionId+"/claim", bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", "application/json")
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return "", errors.New("bridge rejected network claim")
	}
	var saved struct {
		Cap string `json:"cap"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 65536)).Decode(&saved); err != nil {
		return "", err
	}
	if saved.Cap == "" {
		return "", errors.New("bridge returned no capability")
	}
	if _, err := base64.StdEncoding.DecodeString(saved.Cap); err != nil {
		return "", err
	}
	return saved.Cap, nil
}

func validNetworkToken(ctx context.Context, token string) (bool, error) {
	data, err := base64.StdEncoding.DecodeString(token)
	if err != nil {
		return false, err
	}
	conn, api, release, err := networkAPIOpener(ctx)
	if err != nil {
		return false, err
	}
	defer conn.Close()
	defer release()
	restored, done := api.Restore(ctx, func(p grain.SandstormApi_restore_Params) error { return p.SetToken(data) })
	defer done()
	_, err = restored.Struct()
	return err == nil, err
}

func networkDescriptor() string {
	msg, seg, err := capnp.NewMessage(capnp.SingleSegment(nil))
	chkfatal(err)
	desc, err := powerbox.NewRootPowerboxDescriptor(seg)
	chkfatal(err)
	tags, err := desc.NewTags(1)
	chkfatal(err)
	tags.At(0).SetId(ip.IpNetwork_TypeID)
	tag, err := ip.NewIpNetwork_PowerboxTag(seg)
	chkfatal(err)
	encryption, err := tag.NewEncryption()
	chkfatal(err)
	encryption.SetNone()
	chkfatal(tags.At(0).SetValue(tag.ToPtr()))
	data, err := msg.MarshalPacked()
	chkfatal(err)
	return base64.StdEncoding.EncodeToString(data)
}

// Served only through the authenticated nginx ingress. A custom header prevents
// cross-origin form submissions; no CORS permission is granted.
func (s Server) NetworkHandler() http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		allowed := false
		for _, p := range strings.Split(r.Header.Get("X-Sandstorm-Permissions"), ",") {
			if strings.TrimSpace(p) == "access" {
				allowed = true
			}
		}
		if !allowed || r.Header.Get("X-Sandstorm-Session-Id") == "" {
			http.Error(w, "Forbidden", 403)
			return
		}
		if r.Method != "GET" && r.Header.Get("X-Sandstorm-App-Network") != "1" {
			http.Error(w, "Forbidden", 403)
			return
		}
		s.network.mu.Lock()
		defer s.network.mu.Unlock()
		ctx, cancel := context.WithTimeout(r.Context(), 15*time.Second)
		defer cancel()
		token, err := s.storage.GetTokenFor(networkKey)
		if err != nil && !errors.Is(err, sql.ErrNoRows) {
			http.Error(w, "Cannot read network settings", 500)
			return
		}
		switch r.Method {
		case "GET":
			auto, err := s.autoDisconnect()
			if err != nil {
				http.Error(w, "Cannot read network settings", 500)
				return
			}
			valid := false
			if token != "" {
				valid, _ = validNetworkToken(ctx, token)
			}
			w.Header().Set("Content-Type", "application/json")
			w.Header().Set("Cache-Control", "no-store")
			json.NewEncoder(w).Encode(map[string]interface{}{"configured": token != "", "valid": valid, "descriptor": networkDescriptor(), "autoDisconnect": auto})
		case "POST":
			if token != "" {
				http.Error(w, "Disconnect the existing network first", 409)
				return
			}
			var body struct {
				Token          string `json:"token"`
				AutoDisconnect bool   `json:"autoDisconnect"`
			}
			if json.NewDecoder(http.MaxBytesReader(w, r.Body, 16384)).Decode(&body) != nil || body.Token == "" {
				http.Error(w, "Missing claim token", 400)
				return
			}
			saved, err := claimNetwork(ctx, &PowerboxResult{ClaimToken: body.Token, SessionId: r.Header.Get("X-Sandstorm-Session-Id")})
			if err != nil || saved == "" {
				http.Error(w, "Could not claim network access", 502)
				return
			}
			if err = s.saveNetwork(saved, body.AutoDisconnect); err != nil {
				http.Error(w, "Could not save network access", 500)
				return
			}
			w.WriteHeader(204)
		case "PATCH":
			if token == "" {
				http.Error(w, "No network connected", 409)
				return
			}
			var body struct {
				AutoDisconnect bool `json:"autoDisconnect"`
			}
			if json.NewDecoder(http.MaxBytesReader(w, r.Body, 1024)).Decode(&body) != nil {
				http.Error(w, "Invalid setting", 400)
				return
			}
			if err := s.saveNetwork(token, body.AutoDisconnect); err != nil {
				http.Error(w, "Could not save setting", 500)
				return
			}
			w.WriteHeader(204)
		case "DELETE":
			if err := s.disconnectNetwork(ctx, token); err != nil {
				http.Error(w, "Could not revoke network access; retry disconnecting", 502)
				return
			}
			w.WriteHeader(204)
		default:
			w.Header().Set("Allow", "GET, POST, PATCH, DELETE")
			http.Error(w, "Method not allowed", 405)
		}
	})
}

// Caller holds network.mu, serializing settings changes and completion events.
func (s Server) autoDisconnect() (bool, error) {
	value, err := s.storage.GetTokenFor(networkAutoKey)
	if errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	return value == "1", err
}

func (s Server) saveNetwork(token string, auto bool) error {
	tx, err := s.storage.db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if _, err = tx.Exec("DELETE FROM powerbox_proxy_tokens WHERE url IN (?, ?)", networkKey, networkAutoKey); err != nil {
		return err
	}
	value := "0"
	if auto {
		value = "1"
	}
	for key, data := range map[string]string{networkKey: token, networkAutoKey: value} {
		if _, err = tx.Exec("INSERT INTO powerbox_proxy_tokens (url, token) VALUES (?, ?)", key, data); err != nil {
			return err
		}
	}
	return tx.Commit()
}

func (s Server) disconnectNetwork(ctx context.Context, token string) error {
	// Retain the durable token on failure so manual or automatic revocation can retry.
	for conn, cancel := range s.network.active {
		cancel()
		conn.Close()
	}
	if token != "" {
		data, err := base64.StdEncoding.DecodeString(token)
		if err != nil {
			return err
		}
		conn, api, release, err := networkAPIOpener(ctx)
		if err != nil {
			return err
		}
		defer conn.Close()
		defer release()
		result, done := api.Drop(ctx, func(p grain.SandstormApi_drop_Params) error { return p.SetToken(data) })
		_, err = result.Struct()
		done()
		if err != nil {
			return err
		}
	}
	_, err := s.storage.db.Exec("DELETE FROM powerbox_proxy_tokens WHERE url IN (?, ?)", networkKey, networkAutoKey)
	return err
}

// Only the local import worker calls this endpoint. nginx does not route this
// path to the proxy; browser/API callers cannot spoof an import completion.
func (s Server) ImportCompleteHandler() http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "POST" {
			http.Error(w, "Method not allowed", 405)
			return
		}
		s.network.mu.Lock()
		defer s.network.mu.Unlock()
		auto, err := s.autoDisconnect()
		if err != nil {
			http.Error(w, "Cannot read network settings", 500)
			return
		}
		if !auto {
			w.WriteHeader(204)
			return
		}
		token, err := s.storage.GetTokenFor(networkKey)
		if errors.Is(err, sql.ErrNoRows) {
			w.WriteHeader(204)
			return
		}
		ctx, cancel := context.WithTimeout(r.Context(), 10*time.Second)
		defer cancel()
		if err != nil || s.disconnectNetwork(ctx, token) != nil {
			http.Error(w, "Could not disconnect network", 502)
			return
		}
		w.WriteHeader(204)
	})
}

// Each request restores the capability afresh. No connection pool can outlive
// revocation; closing its RPC connection also terminates the response stream.
func (s Server) networkRequest(req *http.Request) (*http.Response, error, bool) {
	s.network.mu.Lock()
	token, err := s.storage.GetTokenFor(networkKey)
	if errors.Is(err, sql.ErrNoRows) {
		s.network.mu.Unlock()
		return nil, nil, false
	}
	if err != nil {
		s.network.mu.Unlock()
		return nil, err, true
	}
	tokenBytes, err := base64.StdEncoding.DecodeString(token)
	if err != nil {
		s.network.mu.Unlock()
		return nil, err, true
	}
	ctx, cancel := context.WithTimeout(req.Context(), 5*time.Minute)
	conn, api, release, err := networkAPIOpener(ctx)
	if err != nil {
		cancel()
		s.network.mu.Unlock()
		return nil, err, true
	}
	s.network.active[conn] = cancel
	s.network.mu.Unlock()
	var once sync.Once
	cleanup := func() {
		once.Do(func() {
			cancel()
			release()
			conn.Close()
			s.network.mu.Lock()
			delete(s.network.active, conn)
			s.network.mu.Unlock()
		})
	}
	transport := &http.Transport{
		// Intentionally no HTTP_PROXY: all DNS and TCP go through the capability.
		DisableKeepAlives:     true,
		TLSHandshakeTimeout:   30 * time.Second,
		ResponseHeaderTimeout: 60 * time.Second,
		DialContext: func(_ context.Context, _, address string) (net.Conn, error) {
			host, portText, err := net.SplitHostPort(address)
			if err != nil {
				return nil, err
			}
			port, err := strconv.ParseUint(portText, 10, 16)
			if err != nil {
				return nil, err
			}
			restored, finishRestore := api.Restore(ctx, func(p grain.SandstormApi_restore_Params) error { return p.SetToken(tokenBytes) })
			defer finishRestore()
			result, err := restored.Struct()
			if err != nil {
				return nil, err
			}
			capability := ip.IpNetwork(result.Cap())
			remote, finishHost := capability.GetRemoteHostByName(ctx, func(p ip.IpNetwork_getRemoteHostByName_Params) error { return p.SetAddress(host) })
			defer finishHost()
			tcp, finishPort := remote.Host().GetTcpPort(ctx, func(p ip.IpRemoteHost_getTcpPort_Params) error { p.SetPortNum(uint16(port)); return nil })
			defer finishPort()
			streamCtx, stopStream := context.WithCancel(ctx)
			incoming, downstream := bytestream.Pipe()
			connected, finishConnect := tcp.Port().Connect(streamCtx, func(p ip.TcpPort_connect_Params) error { return p.SetDownstream(downstream.AddRef()) })
			downstream.Release()
			if _, err := connected.Struct(); err != nil {
				stopStream()
				incoming.Close()
				finishConnect()
				return nil, err
			}
			upstream := connected.Upstream().AddRef()
			writer := bytestream.ToWriteCloser(streamCtx, upstream)
			client, relay := net.Pipe()
			var closeOnce sync.Once
			closeStream := func() { closeOnce.Do(func() { stopStream(); relay.Close(); incoming.Close(); finishConnect() }) }
			go func() { io.Copy(relay, incoming); closeStream() }()
			go func() { defer upstream.Release(); io.Copy(writer, relay); closeStream() }()
			go func() { <-ctx.Done(); closeStream() }()
			return client, nil
		},
	}
	clone := req.Clone(ctx)
	clone.RequestURI = ""
	clone.Header.Del("Proxy-Authorization")
	resp, err := transport.RoundTrip(clone)
	if err != nil {
		cleanup()
		return nil, err, true
	}
	resp.Body = &networkBody{ReadCloser: resp.Body, cleanup: cleanup}
	return resp, nil, true
}

type networkBody struct {
	io.ReadCloser
	cleanup func()
}

func (b *networkBody) Close() error { err := b.ReadCloser.Close(); b.cleanup(); return err }
