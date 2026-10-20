#!/bin/bash
set -euo pipefail
umask 077
cd /opt/app/build/wallabag
mkdir -p /var/lib/wallabag/{runtime,data,images} /var/lib/nginx/{body,fastcgi,proxy} /var/log/nginx /var/run/php /var/lib/php/sessions
if [[ ! -f /var/lib/wallabag/secret || ! -f /var/lib/wallabag/data/library.sqlite ]]; then
    php /opt/app/integration/bootstrap.php
fi
WALLABAG_SECRET="$(cat /var/lib/wallabag/secret)"
[[ ${#WALLABAG_SECRET} -ge 32 ]]
export WALLABAG_SECRET
export WALLABAG_DATABASE=/var/lib/wallabag/data/library.sqlite
if ! cmp -s /opt/app/build/runtime-version /var/lib/wallabag/runtime-version; then
    # Old releases put the whole compiled application here. Only private caches
    # remain writable now; the shared cache lives in the read-only package.
    rm -rf /var/lib/wallabag/runtime/cache
fi
mkdir -p /var/lib/wallabag/runtime/cache/{jms_serializer,hateoas,rulerz,fos_rest}
if ! cmp -s /opt/app/build/schema-version /var/lib/wallabag/schema-version; then
    php bin/console doctrine:migrations:migrate --env=prod --no-debug --no-interaction --allow-no-migration --quiet
    cp /opt/app/build/schema-version /var/lib/wallabag/schema-version
fi
cp /opt/app/build/runtime-version /var/lib/wallabag/runtime-version
# Preserve the bridge's HTTP_PROXY for the proxy daemon itself. Only PHP uses
# the local TLS-intercepting adapter; it trusts that adapter's generated CA.
export POWERBOX_PROXY_PORT=3000 POWERBOX_WEBSOCKET_PORT=3001
export CA_CERT_PATH=/var/lib/wallabag/proxy-ca.pem
export DB_TYPE=sqlite3 DB_URI=/var/lib/wallabag/proxy.sqlite
rm -f "$CA_CERT_PATH" /var/run/php/php8.2-fpm.sock
/opt/app/build/powerbox-http-proxy &
proxy_pid=$!
trap 'kill "$proxy_pid" "${php_pid:-}" "${nginx_pid:-}" 2>/dev/null || true' EXIT
for i in {1..100}; do
    [[ -s "$CA_CERT_PATH" ]] && break
    kill -0 "$proxy_pid"
    sleep .1
done
[[ -s "$CA_CERT_PATH" ]]
/usr/sbin/php-fpm8.2 --nodaemonize --fpm-config /etc/php/8.2/fpm/php-fpm.conf \
    -d curl.cainfo="$CA_CERT_PATH" -d openssl.cafile="$CA_CERT_PATH" &
php_pid=$!
for i in {1..100}; do
    [[ -S /var/run/php/php8.2-fpm.sock ]] && break
    kill -0 "$php_pid"
    sleep .1
done
[[ -S /var/run/php/php8.2-fpm.sock ]]
/usr/sbin/nginx -c /opt/app/integration/nginx.conf -g 'daemon off;' &
nginx_pid=$!
wait -n "$proxy_pid" "$php_pid" "$nginx_pid"
