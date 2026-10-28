package main

import (
	"context"
	"database/sql"
	"encoding/base64"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	capnp "capnproto.org/go/capnp/v3"
	"capnproto.org/go/capnp/v3/rpc"
	"sandstorm.org/go/tempest/capnp/grain"
	"sandstorm.org/go/tempest/capnp/ip"
	"sandstorm.org/go/tempest/capnp/powerbox"
	"sandstorm.org/go/tempest/pkg/exp/util/bytestream"
)

type testNetworkAPI struct {
	grain.SandstormApi_Server
	target   string
	revoked  atomic.Bool
	failDrop atomic.Bool
}

func (a *testNetworkAPI) Restore(ctx context.Context, call grain.SandstormApi_restore) error {
	if a.revoked.Load() {
		return errors.New("revoked")
	}
	result, err := call.AllocResults()
	if err != nil {
		return err
	}
	network := ip.IpNetwork_ServerToClient(&testIPNetwork{target: a.target})
	return result.SetCap(capnp.Client(network))
}
func (a *testNetworkAPI) Drop(ctx context.Context, call grain.SandstormApi_drop) error {
	if a.failDrop.Load() {
		return errors.New("drop failed")
	}
	a.revoked.Store(true)
	_, err := call.AllocResults()
	return err
}

type testIPNetwork struct {
	ip.IpNetwork_Server
	target string
}

func (n *testIPNetwork) GetRemoteHostByName(ctx context.Context, call ip.IpNetwork_getRemoteHostByName) error {
	host, _ := call.Args().Address()
	if host != "example.test" {
		return errors.New("unexpected host: " + host)
	}
	result, err := call.AllocResults()
	if err != nil {
		return err
	}
	remote := ip.IpRemoteHost_ServerToClient(&testRemoteHost{target: n.target})
	return result.SetHost(remote)
}

type testRemoteHost struct {
	ip.IpRemoteHost_Server
	target string
}

func (h *testRemoteHost) GetTcpPort(ctx context.Context, call ip.IpRemoteHost_getTcpPort) error {
	if call.Args().PortNum() != 80 && call.Args().PortNum() != 443 {
		return errors.New("unexpected port")
	}
	result, err := call.AllocResults()
	if err != nil {
		return err
	}
	port := ip.TcpPort_ServerToClient(&testTCPPort{target: h.target})
	return result.SetPort(port)
}

type testTCPPort struct {
	ip.TcpPort_Server
	target string
}

func (p *testTCPPort) Connect(ctx context.Context, call ip.TcpPort_connect) error {
	socket, err := net.Dial("tcp", p.target)
	if err != nil {
		return err
	}
	downstream := call.Args().Downstream().AddRef()
	// The request context ends when Connect returns; the stream lives longer.
	go func() {
		defer downstream.Release()
		defer socket.Close()
		writer := bytestream.ToWriteCloser(context.Background(), downstream)
		io.Copy(writer, socket)
		writer.Close()
	}()
	result, err := call.AllocResults()
	if err != nil {
		return err
	}
	upstream := bytestream.FromWriteCloser(socket)
	return result.SetUpstream(upstream)
}

func networkTestServer(t *testing.T, target string) (Server, *testNetworkAPI) {
	t.Helper()
	db, err := sql.Open("sqlite3", ":memory:")
	if err != nil {
		t.Fatal(err)
	}
	db.SetMaxOpenConns(1)
	t.Cleanup(func() { db.Close() })
	storage, err := NewStorage(db)
	if err != nil {
		t.Fatal(err)
	}
	srv := NewServer(storage, nil)
	api := &testNetworkAPI{target: target}
	original := networkAPIOpener
	networkAPIOpener = func(ctx context.Context) (*rpc.Conn, grain.SandstormApi, func(), error) {
		local, remote := net.Pipe()
		server := rpc.NewConn(rpc.NewStreamTransport(remote), &rpc.Options{BootstrapClient: capnp.Client(grain.SandstormApi_ServerToClient(api))})
		client := rpc.NewConn(rpc.NewStreamTransport(local), nil)
		capability := grain.SandstormApi(client.Bootstrap(ctx))
		return client, capability, func() { capability.Release(); server.Close() }, nil
	}
	t.Cleanup(func() { networkAPIOpener = original })
	return srv, api
}
func configureNetwork(t *testing.T, srv Server) {
	t.Helper()
	if err := srv.storage.SetTokenFor(networkKey, base64.StdEncoding.EncodeToString([]byte("test-network"))); err != nil {
		t.Fatal(err)
	}
}
func networkControl(srv Server, method, permissions, session, header string) *httptest.ResponseRecorder {
	req := httptest.NewRequest(method, "/_sandstorm/network", nil)
	req.Header.Set("X-Sandstorm-Permissions", permissions)
	req.Header.Set("X-Sandstorm-Session-Id", session)
	req.Header.Set("X-Sandstorm-App-Network", header)
	result := httptest.NewRecorder()
	srv.NetworkHandler().ServeHTTP(result, req)
	return result
}
func TestNetworkControls(t *testing.T) {
	srv, _ := networkTestServer(t, "")
	for _, c := range []struct{ method, permissions, session, header string }{
		{"GET", "feeds", "session", "1"}, {"POST", "access", "session", ""},
		{"DELETE", "access", "session", ""}, {"GET", "access", "", "1"},
	} {
		if got := networkControl(srv, c.method, c.permissions, c.session, c.header).Code; got != 403 {
			t.Fatalf("%+v: %d", c, got)
		}
	}
	status := networkControl(srv, "GET", "feeds, access", "session", "")
	if status.Code != 200 || !strings.Contains(status.Body.String(), `"configured":false`) {
		t.Fatal(status)
	}
	data, err := base64.StdEncoding.DecodeString(networkDescriptor())
	if err != nil {
		t.Fatal(err)
	}
	msg, err := capnp.UnmarshalPacked(data)
	if err != nil {
		t.Fatal(err)
	}
	desc, err := powerbox.ReadRootPowerboxDescriptor(msg)
	if err != nil {
		t.Fatal(err)
	}
	tags, _ := desc.Tags()
	if tags.Len() != 1 || tags.At(0).Id() != ip.IpNetwork_TypeID {
		t.Fatal("wrong descriptor")
	}
}
func TestNetworkTransportAndDisconnect(t *testing.T) {
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Proxy-Authorization") != "" {
			t.Error("proxy credentials leaked")
		}
		w.Write([]byte("network article"))
	}))
	defer upstream.Close()
	srv, api := networkTestServer(t, strings.TrimPrefix(upstream.URL, "http://"))
	req := httptest.NewRequest("GET", "http://example.test/article", nil)
	if _, _, configured := srv.networkRequest(req); configured {
		t.Fatal("network enabled by default")
	}
	configureNetwork(t, srv)
	req.Header.Set("Proxy-Authorization", "secret")
	resp, err, configured := srv.networkRequest(req)
	if err != nil || !configured {
		t.Fatalf("request: %v, %v", configured, err)
	}
	body, err := io.ReadAll(resp.Body)
	resp.Body.Close()
	if err != nil || string(body) != "network article" {
		t.Fatalf("body: %q %v", body, err)
	}
	// New Server instances restore the saved setting (as after a grain restart).
	restarted := NewServer(srv.storage, nil)
	resp, err, _ = restarted.networkRequest(req)
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	if got := networkControl(srv, "DELETE", "access", "session", "1").Code; got != 204 {
		t.Fatal(got)
	}
	if !api.revoked.Load() {
		t.Fatal("durable capability was not dropped")
	}
	if _, _, configured := srv.networkRequest(req); configured {
		t.Fatal("did not return to per-site access")
	}
	configureNetwork(t, srv)
	if _, err, configured := srv.networkRequest(req); !configured || err == nil {
		t.Fatal("revoked grant should fail without prompting or direct networking")
	}
}
func TestNetworkDisconnectStopsActiveDownload(t *testing.T) {
	finished := make(chan struct{})
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("start"))
		w.(http.Flusher).Flush()
		select {
		case <-r.Context().Done():
		case <-finished:
		}
	}))
	defer upstream.Close()
	defer close(finished)
	srv, _ := networkTestServer(t, strings.TrimPrefix(upstream.URL, "http://"))
	configureNetwork(t, srv)
	resp, err, _ := srv.networkRequest(httptest.NewRequest("GET", "http://example.test/slow", nil))
	if err != nil {
		t.Fatal(err)
	}
	done := make(chan error, 1)
	go func() { _, err := io.ReadAll(resp.Body); resp.Body.Close(); done <- err }()
	if got := networkControl(srv, "DELETE", "access", "session", "1").Code; got != 204 {
		t.Fatal(got)
	}
	select {
	case err := <-done:
		if err == nil {
			t.Fatal("download completed despite revocation")
		}
	case <-time.After(3 * time.Second):
		t.Fatal("download survived disconnect")
	}
}
func TestNetworkTLSVerification(t *testing.T) {
	upstream := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte("bad certificate")) }))
	defer upstream.Close()
	srv, _ := networkTestServer(t, strings.TrimPrefix(upstream.URL, "https://"))
	configureNetwork(t, srv)
	_, err, configured := srv.networkRequest(httptest.NewRequest("GET", "https://example.test/", nil))
	if !configured || err == nil || !strings.Contains(err.Error(), "certificate") {
		t.Fatalf("TLS certificate accepted: %v", err)
	}
}

type networkClaimTransport func(*http.Request) (*http.Response, error)

func (f networkClaimTransport) RoundTrip(r *http.Request) (*http.Response, error) { return f(r) }
func TestNetworkClaimPermissionsAndErrors(t *testing.T) {
	original := http.DefaultClient
	defer func() { http.DefaultClient = original }()
	for _, c := range []struct {
		code  int
		body  string
		valid bool
	}{
		{200, `{"cap":"dG9rZW4="}`, true}, {403, `{"cap":"dG9rZW4="}`, false},
		{200, `{}`, false}, {200, `{"cap":"invalid!"}`, false},
	} {
		http.DefaultClient = &http.Client{Transport: networkClaimTransport(func(r *http.Request) (*http.Response, error) {
			body, _ := io.ReadAll(r.Body)
			if !strings.Contains(string(body), `"requiredPermissions":["access"]`) || !strings.Contains(string(body), `"requestToken":"claim"`) {
				t.Errorf("wrong claim: %s", body)
			}
			if r.URL.Path != "/session/session/claim" {
				t.Errorf("wrong session: %s", r.URL.Path)
			}
			if _, ok := r.Context().Deadline(); !ok {
				t.Error("claim has no deadline")
			}
			return &http.Response{StatusCode: c.code, Body: io.NopCloser(strings.NewReader(c.body))}, nil
		})}
		ctx, cancel := context.WithTimeout(context.Background(), time.Second)
		_, err := claimNetwork(ctx, &PowerboxResult{ClaimToken: "claim", SessionId: "session"})
		cancel()
		if (err == nil) != c.valid {
			t.Errorf("claim %d %s: %v", c.code, c.body, err)
		}
	}
}

func TestNetworkAutomaticDisconnect(t *testing.T) {
	srv, api := networkTestServer(t, "")
	configureNetwork(t, srv)
	complete := func() int {
		result := httptest.NewRecorder()
		srv.ImportCompleteHandler().ServeHTTP(result, httptest.NewRequest("POST", "/_sandstorm/network/import-complete", nil))
		return result.Code
	}
	if got := complete(); got != 204 || api.revoked.Load() {
		t.Fatal("completion revoked a manual grant", got)
	}
	// The checkbox can be changed after connecting; mutations require the same
	// permission and anti-CSRF header as connection and disconnection.
	patch := httptest.NewRequest("PATCH", "/_sandstorm/network", strings.NewReader(`{"autoDisconnect":true}`))
	patch.Header.Set("X-Sandstorm-Permissions", "access")
	patch.Header.Set("X-Sandstorm-Session-Id", "session")
	patch.Header.Set("X-Sandstorm-App-Network", "1")
	result := httptest.NewRecorder()
	srv.NetworkHandler().ServeHTTP(result, patch)
	if result.Code != 204 {
		t.Fatal(result)
	}
	restarted := NewServer(srv.storage, nil)
	if auto, err := restarted.autoDisconnect(); !auto || err != nil {
		t.Fatal("auto setting did not persist", err)
	}
	api.failDrop.Store(true)
	if got := complete(); got != 502 {
		t.Fatal("failed revocation not reported", got)
	}
	if _, err := srv.storage.GetTokenFor(networkKey); err != nil {
		t.Fatal("lost token needed for retry", err)
	}
	api.failDrop.Store(false)
	if got := complete(); got != 204 || !api.revoked.Load() {
		t.Fatal("automatic disconnect failed", got)
	}
	if _, err := srv.storage.GetTokenFor(networkKey); !errors.Is(err, sql.ErrNoRows) {
		t.Fatal("network grant retained", err)
	}
	if auto, err := srv.autoDisconnect(); auto || err != nil {
		t.Fatal("auto setting retained", err)
	}
	if got := complete(); got != 204 {
		t.Fatal("completion retry not idempotent", got)
	}
}
