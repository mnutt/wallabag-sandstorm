package main

import (
	"crypto/tls"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"
)

type responseTransport struct {
	close   bool
	length  int64
	chunked bool
}

func (r responseTransport) RoundTrip(req *http.Request) (*http.Response, error) {
	var encoding []string
	if r.chunked {
		encoding = []string{"chunked"}
	}
	return &http.Response{StatusCode: 200, Proto: "HTTP/1.1", ProtoMajor: 1, ProtoMinor: 1,
		Header: make(http.Header), Body: io.NopCloser(strings.NewReader("article body")),
		TransferEncoding: encoding, ContentLength: r.length, Close: r.close, Request: req}, nil
}

// Exercise the real CONNECT/TLS handler, including EOF-delimited responses.
// Go represents upstream Connection: close in Response.Close, not Header.
func TestConnectResponseCompletion(t *testing.T) {
	original := http.DefaultTransport
	defer func() { http.DefaultTransport = original }()
	srv, _ := networkTestServer(t, "")
	var err error
	srv.spoofer, err = GenSpoofer()
	if err != nil {
		t.Fatal(err)
	}
	if err := srv.storage.SetTokenFor("https://example.test", "fixture"); err != nil {
		t.Fatal(err)
	}
	proxy := httptest.NewServer(srv.ProxyHandler())
	defer proxy.Close()
	proxyURL, _ := url.Parse(proxy.URL)
	for _, test := range []struct {
		name    string
		close   bool
		length  int64
		chunked bool
	}{
		{"close-delimited", true, -1, false}, {"known-length-close", true, 12, false},
		{"chunked-keepalive", false, -1, true}, {"decoded-unknown-length", false, -1, false},
	} {
		t.Run(test.name, func(t *testing.T) {
			http.DefaultTransport = responseTransport{test.close, test.length, test.chunked}
			transport := &http.Transport{Proxy: http.ProxyURL(proxyURL), TLSClientConfig: &tls.Config{InsecureSkipVerify: true}}
			defer transport.CloseIdleConnections()
			client := &http.Client{Transport: transport, Timeout: 2 * time.Second}
			resp, err := client.Get("https://example.test/article")
			if err != nil {
				t.Fatal(err)
			}
			defer resp.Body.Close()
			body, err := io.ReadAll(resp.Body)
			if err != nil {
				t.Fatalf("response stalled after receiving %q: %v", body, err)
			}
			if string(body) != "article body" {
				t.Fatalf("unexpected body %q", body)
			}
		})
	}
}
