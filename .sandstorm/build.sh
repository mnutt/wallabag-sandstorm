#!/bin/bash
set -euo pipefail
# Patch order is part of the integration (see each patch's dependency comments).
export LC_ALL=C
cd /opt/app
source upstream.lock
mkdir -p .sandstorm/caches build
if [[ ! -f dist/wallabagger-source.sha256 ]] || ! sha256sum --check --status dist/wallabagger-source.sha256; then
    echo 'Run extension/build.sh on the host before building the app to prepare current browser-extension downloads.' >&2
    exit 1
fi
for browser in chrome firefox; do
    test -f "dist/wallabagger-sandstorm-$browser.zip"
done
# Concurrent spktool sessions share generated files; never rebuild them together.
exec 9>.sandstorm/caches/build.lock
flock 9
fetch() {
    local url=$1 checksum=$2 target=$3
    if [[ ! -f "$target" ]]; then
        curl --fail --location --retry 3 "$url" -o "$target.partial"
        mv "$target.partial" "$target"
    fi
    echo "$checksum  $target" | sha256sum --check --status
}
proxy_key=$({ printf '%s\n' "$PROXY_URL" "$PROXY_SHA256"; sha256sum patches/proxy-*.patch integration/proxy-network.go .sandstorm/build.sh; go version; } | sha256sum | cut -d' ' -f1)
if [[ ! -x build/powerbox-http-proxy || ! -f build/proxy-version || "$(cat build/proxy-version)" != "$proxy_key" ]]; then
    fetch "$PROXY_URL" "$PROXY_SHA256" .sandstorm/caches/powerbox-http-proxy.tar.gz
    rm -rf build/proxy-source
    mkdir -p build/proxy-source
    tar -xzf .sandstorm/caches/powerbox-http-proxy.tar.gz -C build/proxy-source --strip-components=1
    for patch in patches/proxy-*.patch; do
        patch -d build/proxy-source -p1 --batch --forward --fuzz=0 --no-backup-if-mismatch < "$patch"
    done
    cp integration/proxy-network.go build/proxy-source/
    (cd build/proxy-source && go build -trimpath -o ../powerbox-http-proxy .)
    printf '%s\n' "$proxy_key" > build/proxy-version
else
    echo 'HTTP adapter unchanged; reusing binary.'
fi
# Reuse the capability proxy's pinned module graph for the small background API helper.
(cd build/proxy-source && go build -trimpath -ldflags='-s -w' -o ../import-notify /opt/app/integration/import-notify.go)
app_key=$({ printf '%s\n' "$WALLABAG_URL" "$WALLABAG_SHA256"; sha256sum integration/* patches/wallabag-*.patch .sandstorm/build.sh dist/wallabagger-sandstorm-*.zip; php -v; php -m; } | sha256sum | cut -d' ' -f1)
if [[ ! -f build/app-version || "$(cat build/app-version)" != "$app_key" || ! -f build/library-seed.sqlite || ! -f build/wallabag-cache/prod/appAppKernelProdContainer.php ]]; then
    fetch "$WALLABAG_URL" "$WALLABAG_SHA256" ".sandstorm/caches/wallabag-$WALLABAG_VERSION.tar.gz"
    # These are exclusively generated outputs, never an upstream checkout.
    rm -rf build/wallabag build/wallabag-cache
    rm -f build/library-seed.sqlite build/library-seed.sqlite-journal build/app-version
    mkdir -p build/wallabag
    tar -xzf ".sandstorm/caches/wallabag-$WALLABAG_VERSION.tar.gz" -C build/wallabag --strip-components=1
    for patch in patches/wallabag-*.patch; do
        patch -d build/wallabag -p1 --batch --forward --fuzz=0 --no-backup-if-mismatch < "$patch"
    done
    cp integration/agrat.gitbook.io.txt build/wallabag/vendor/j0k3r/graby-site-config/
    cp integration/security.yml build/wallabag/app/config/security.yml
    cp integration/SandstormAuthenticator.php build/wallabag/app/
    cp integration/{sandstorm.js,sandstorm-feeds.js,sandstorm-extensions.js,sandstorm-network.js,powerbox-http-proxy.js} build/wallabag/web/
    mkdir -p build/wallabag/web/extensions
    cp dist/wallabagger-sandstorm-{chrome,firefox}.zip build/wallabag/web/extensions/
    cp integration/warm.css build/wallabag/web/custom.css
    cp integration/warm.js build/wallabag/web/
    cp integration/SandstormFeedController.php build/wallabag/src/Wallabag/CoreBundle/Controller/
    cp integration/SandstormExtensionController.php build/wallabag/src/Wallabag/CoreBundle/Controller/
    cp integration/SandstormThemeController.php build/wallabag/src/Wallabag/CoreBundle/Controller/
    cp integration/SandstormImportController.php build/wallabag/src/Wallabag/CoreBundle/Controller/
    cp integration/SandstormTheme.php build/wallabag/src/Wallabag/CoreBundle/Helper/
    cp integration/SandstormThemeSubscriber.php build/wallabag/src/Wallabag/CoreBundle/Event/Subscriber/
    cp integration/SandstormImportSubscriber.php build/wallabag/src/Wallabag/CoreBundle/Event/Subscriber/
    mkdir -p build/wallabag/src/Wallabag/CoreBundle/Resources/views/Sandstorm
    cp integration/feeds.html.twig build/wallabag/src/Wallabag/CoreBundle/Resources/views/Sandstorm/
    cp integration/extensions.html.twig build/wallabag/src/Wallabag/CoreBundle/Resources/views/Sandstorm/
    cp integration/{imports,import-network}.html.twig build/wallabag/src/Wallabag/CoreBundle/Resources/views/Sandstorm/
    rm -rf build/wallabag/var build/wallabag/data build/wallabag/web/assets/images
    rm -f build/wallabag/app/config/parameters.yml
    ln -s /var/lib/wallabag/runtime build/wallabag/var
    ln -s /var/lib/wallabag/data build/wallabag/data
    mkdir -p build/wallabag/web/assets
    ln -s /var/lib/wallabag/images build/wallabag/web/assets/images
    rm -rf build/wallabag/web/uploads/import
    mkdir -p build/wallabag/web/uploads
    # Static config with lazy env placeholders: no grain secrets in the package.
    php integration/parameters.php > build/wallabag/app/config/parameters.yml
    # VM-only scratch directories. These are not Sandstorm grain storage.
    if [[ ! -d /var/lib/wallabag ]]; then
        sudo install -d -o "$(id -u)" -g "$(id -g)" /var/lib/wallabag
    fi
    mkdir -p /var/lib/wallabag/{runtime/logs,data,images}
    export WALLABAG_DATABASE=/opt/app/build/library-seed.sqlite
    export WALLABAG_SECRET=build-time-placeholder-not-a-grain-secret
    echo 'Preparing database seed and shared production cache…'
    (cd build/wallabag && php -d opcache.enable_cli=1 bin/console wallabag:install --env=prod --no-debug --no-interaction --quiet)
    php -d opcache.enable_cli=1 integration/initialize.php
    (cd build/wallabag && php -d opcache.enable_cli=1 bin/console doctrine:migrations:migrate --env=prod --no-debug --no-interaction --allow-no-migration --quiet)
    (cd build/wallabag && php -d opcache.enable_cli=1 bin/console cache:warmup --env=prod --no-debug --quiet)
    sqlite3 build/library-seed.sqlite 'VACUUM;'
    { printf '%s\n' "$WALLABAG_VERSION"; sha256sum build/wallabag/app/DoctrineMigrations/*.php; } | sha256sum | cut -d' ' -f1 > build/schema-version
    printf '%s\n' "$app_key" > build/app-version
else
    echo 'wallabag unchanged; reusing application, database seed, and production cache.'
fi
{ cat build/app-version build/proxy-version; sha256sum .sandstorm/launcher.sh; } | sha256sum | cut -d' ' -f1 > build/runtime-version
python3 .sandstorm/collect-licenses.py
