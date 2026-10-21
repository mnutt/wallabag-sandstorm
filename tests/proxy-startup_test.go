package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/rsa"
	"encoding/pem"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
	"time"
)

func TestStartupCertificates(t *testing.T) {
	started := time.Now()
	spoofer, err := GenSpoofer()
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("proxy CA and leaf key generation: %s", time.Since(started))
	key, ok := spoofer.leafKey.Private.(*ecdsa.PrivateKey)
	if !ok || key.Curve != elliptic.P256() {
		t.Fatal("expected an ECDSA P-256 leaf key")
	}
	config, err := spoofer.TLSConfig("localhost")
	if err != nil {
		t.Fatal(err)
	}
	server := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte("verified TLS"))
	}))
	server.TLS = config
	server.StartTLS()
	defer server.Close()
	caPath := filepath.Join(t.TempDir(), "ca.pem")
	if err := os.WriteFile(caPath, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: spoofer.RawCACert()}), 0600); err != nil {
		t.Fatal(err)
	}
	// Exercise the actual PHP/cURL client with normal certificate verification.
	// The proxy's existing leaf certificates identify hosts by common name.
	php := `$c = curl_init($argv[1]); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_CAINFO => $argv[2], CURLOPT_RESOLVE => [$argv[3]], CURLOPT_TIMEOUT => 5]); $body = curl_exec($c); if ($body !== 'verified TLS') { fwrite(STDERR, curl_error($c)); exit(1); }`
	port := server.Listener.Addr().(*net.TCPAddr).Port
	for _, host := range []string{"localhost", "wrong.example"} {
		url := fmt.Sprintf("https://%s:%d/", host, port)
		resolve := fmt.Sprintf("%s:%d:127.0.0.1", host, port)
		output, err := exec.Command("php", "-r", php, url, caPath, resolve).CombinedOutput()
		if host == "localhost" && err != nil {
			t.Fatalf("PHP/cURL rejected ECDSA TLS: %v: %s", err, output)
		}
		if host != "localhost" && err == nil {
			t.Fatal("PHP/cURL accepted the wrong hostname")
		}
	}
	started = time.Now()
	for i := 0; i < 2; i++ {
		if _, err := rsa.GenerateKey(rand.Reader, 2048); err != nil {
			t.Fatal(err)
		}
	}
	t.Logf("previous two RSA-2048 keys alone: %s", time.Since(started))
}
