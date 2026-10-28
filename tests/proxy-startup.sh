#!/bin/bash
# Run inside the build VM. Use an isolated copy of the pinned proxy source.
set -euo pipefail
cd /opt/app
source upstream.lock
archive=.sandstorm/caches/powerbox-http-proxy.tar.gz
echo "$PROXY_SHA256  $archive" | sha256sum --check --status
scratch=$(mktemp -d)
trap 'rm -rf "$scratch"' EXIT
tar -xzf "$archive" -C "$scratch" --strip-components=1
for patch in patches/proxy-*.patch; do
    patch -d "$scratch" -p1 --batch --forward --fuzz=0 < "$patch"
done
cp integration/proxy-network.go "$scratch/"
cp tests/proxy-network_test.go "$scratch/network_test.go"
cp tests/proxy-response_test.go "$scratch/response_test.go"
cp tests/proxy-startup_test.go "$scratch/startup_test.go"
cd "$scratch"
# main.go reads these at package initialization; the tests use their own listener.
export POWERBOX_PROXY_PORT=3000 POWERBOX_WEBSOCKET_PORT=3001
export CA_CERT_PATH="$scratch/ca.pem" DB_TYPE=sqlite3 DB_URI="$scratch/proxy.sqlite"
go test -timeout 30s -v . -count=1
