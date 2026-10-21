#!/bin/bash
set -euo pipefail
umask 077
# Bash's built-in clock avoids starting a process for each checkpoint. Report
# elapsed wall time (not CPU time); never log request data or credentials.
startup_epoch=${EPOCHREALTIME/./}
startup_log() {
    local now=${EPOCHREALTIME/./}
    local elapsed=$(( (now - startup_epoch) / 1000 ))
    TZ=UTC printf '[startup] %(%Y-%m-%dT%H:%M:%S)T.%03dZ +%dms %s\n' \
        "$((now / 1000000))" "$((now / 1000 % 1000))" "$elapsed" "$1" >&2
}
startup_log 'launcher started'
cd /opt/app/build/wallabag
mkdir -p /var/lib/wallabag/{runtime,data,images} /var/lib/nginx/{body,fastcgi,proxy} /var/log/nginx /var/run/php /var/lib/php/sessions
# Preserve the bridge's HTTP_PROXY for the proxy daemon itself. Only PHP uses
# the local TLS-intercepting adapter; it trusts that adapter's generated CA.
export POWERBOX_PROXY_PORT=3000 POWERBOX_WEBSOCKET_PORT=3001
export CA_CERT_PATH=/var/lib/wallabag/proxy-ca.pem
export DB_TYPE=sqlite3 DB_URI=/var/lib/wallabag/proxy.sqlite
rm -f "$CA_CERT_PATH" /var/run/php/php8.2-fpm.sock
/opt/app/build/powerbox-http-proxy &
proxy_pid=$!
startup_log 'HTTP proxy launched'
# Install cleanup before library initialization, which can fail independently.
cleanup() {
    local pid
    for pid in "$proxy_pid" "${redis_pid:-}" "${redis_ready_pid:-}" "${import_pid:-}" "${php_pid:-}" "${nginx_pid:-}"; do
        [[ -z "$pid" ]] || kill "$pid" 2>/dev/null || true
    done
}
trap cleanup EXIT
if [[ ! -f /var/lib/wallabag/secret || ! -f /var/lib/wallabag/data/library.sqlite ]]; then
    startup_log 'initializing new library'
    php /opt/app/integration/bootstrap.php
fi
startup_log 'library available'
WALLABAG_SECRET="$(cat /var/lib/wallabag/secret)"
[[ ${#WALLABAG_SECRET} -ge 32 ]]
export WALLABAG_SECRET
export WALLABAG_DATABASE=/var/lib/wallabag/data/library.sqlite
if ! cmp -s /opt/app/build/runtime-version /var/lib/wallabag/runtime-version; then
    startup_log 'clearing private caches after package change'
    # Old releases put the whole compiled application here. Only private caches
    # remain writable now; the shared cache lives in the read-only package.
    rm -rf /var/lib/wallabag/runtime/cache
fi
mkdir -p /var/lib/wallabag/runtime/cache/{jms_serializer,hateoas,rulerz,fos_rest}
if ! cmp -s /opt/app/build/schema-version /var/lib/wallabag/schema-version; then
    startup_log 'applying database migrations'
    php bin/console doctrine:migrations:migrate --env=prod --no-debug --no-interaction --allow-no-migration --quiet
    cp /opt/app/build/schema-version /var/lib/wallabag/schema-version
fi
cp /opt/app/build/runtime-version /var/lib/wallabag/runtime-version
startup_log 'cache and schema checks complete'
# Apply fixed settings once per package, including upgrades of existing libraries.
# Record success separately so an interrupted startup retries the configuration.
if ! cmp -s /opt/app/build/runtime-version /var/lib/wallabag/import-settings-version; then
    php /opt/app/integration/import-settings.php
    cp /opt/app/build/runtime-version /var/lib/wallabag/import-settings-version
fi
startup_log 'import settings checked'
mkdir -p /var/lib/wallabag/{redis,imports}
/usr/bin/redis-server /opt/app/integration/redis.conf &
redis_pid=$!
startup_log 'Redis launched'
# Observe Redis independently of certificate generation so its readiness time
# does not include time spent waiting for the proxy.
(
    for i in {1..500}; do
        if /usr/bin/redis-cli -s /var/run/wallabag-redis.sock ping >/dev/null 2>&1; then
            startup_log 'Redis ready (PING succeeded)'
            exit 0
        fi
        kill -0 "$redis_pid"
        sleep .02
    done
    exit 1
) &
redis_ready_pid=$!
# FPM can initialize before the CA exists; nginx starts only after it is available.
/usr/sbin/php-fpm8.2 --nodaemonize --fpm-config /etc/php/8.2/fpm/php-fpm.conf \
    -d curl.cainfo="$CA_CERT_PATH" -d openssl.cafile="$CA_CERT_PATH" &
php_pid=$!
startup_log 'PHP-FPM launched'
for i in {1..500}; do
    [[ -s "$CA_CERT_PATH" ]] && break
    kill -0 "$proxy_pid"
    sleep .02
done
[[ -s "$CA_CERT_PATH" ]]
startup_log 'proxy CA certificate available'
wait "$redis_ready_pid"
php /opt/app/integration/import-worker.php &
import_pid=$!
startup_log 'import worker launched'
for i in {1..500}; do
    [[ -S /var/run/php/php8.2-fpm.sock ]] && break
    kill -0 "$php_pid"
    sleep .02
done
[[ -S /var/run/php/php8.2-fpm.sock ]]
startup_log 'PHP-FPM socket available'
/usr/sbin/nginx -c /opt/app/integration/nginx.conf -g 'daemon off;' &
nginx_pid=$!
startup_log 'nginx launched'
# A request without bridge permissions must be rejected before PHP. This checks
# the listener without warming Symfony or triggering application work.
for i in {1..500}; do
    if (exec 3<>/dev/tcp/127.0.0.1/8000
        printf 'GET / HTTP/1.0\r\nHost: localhost\r\n\r\n' >&3
        IFS= read -r -t 1 status <&3
        [[ $status == 'HTTP/1.1 403 '* || $status == 'HTTP/1.0 403 '* ]]) 2>/dev/null; then
        startup_log 'HTTP ready (permission check passed); first page rendering happens next'
        break
    fi
    kill -0 "$nginx_pid"
    sleep .02
done
wait -n "$proxy_pid" "$redis_pid" "$import_pid" "$php_pid" "$nginx_pid"
