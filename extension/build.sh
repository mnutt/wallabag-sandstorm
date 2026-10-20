#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
source extension/upstream.lock
mkdir -p .sandstorm/caches build dist
archive=".sandstorm/caches/wallabagger-$WALLABAGGER_VERSION.tar.gz"
if [[ ! -f "$archive" ]]; then
    curl --fail --location --retry 3 "$WALLABAGGER_URL" -o "$archive.partial"
    mv "$archive.partial" "$archive"
fi
echo "$WALLABAGGER_SHA256  $archive" | sha256sum --check --status
work=$(mktemp -d "$PWD/build/wallabagger.XXXXXX")
trap 'rm -rf "$work"' EXIT
tar -xzf "$archive" -C "$work" --strip-components=1
patch -d "$work" -p1 --fuzz=0 < extension/wallabagger-sandstorm.patch
cp extension/options.html "$work/wallabagger/options.html"
cp extension/sandstorm-options.css "$work/wallabagger/"
cp extension/sandstorm-options.js "$work/wallabagger/js/"
for browser in chrome firefox; do
    node "$work/manifest-builder/manifest.mjs" "$browser" > /dev/null
    python3 - "$work" "$browser" <<'PY'
import json, pathlib, shutil, sys, zipfile
work, browser = pathlib.Path(sys.argv[1]), sys.argv[2]
manifest = work/'wallabagger/manifest.json'
m = json.loads(manifest.read_text())
m['name'] = 'Wallabagger for Sandstorm'
m['options_ui']['page'] = 'options.html'
m['optional_host_permissions'] = ['http://*/*', 'https://*/*']
if browser == 'firefox':
    m['browser_specific_settings']['gecko']['id'] = 'wallabagger-sandstorm@wallabag-sandstorm.local'
manifest.write_text(json.dumps(m, indent=2)+'\n')
dest = pathlib.Path('dist')/('wallabagger-sandstorm-'+browser)
if dest.exists(): shutil.rmtree(dest)
shutil.copytree(work/'wallabagger', dest)
for name in ['LICENSE', 'LICENSE.md', 'LICENSE.txt', 'COPYING.md']:
    if (work/name).exists(): shutil.copy(work/name, dest/name)
shutil.copy('extension/README.md', dest/'SANDSTORM.md')
with zipfile.ZipFile(str(dest)+'.zip', 'w', zipfile.ZIP_DEFLATED) as z:
    for p in sorted(dest.rglob('*')):
        if p.is_file(): z.write(p, p.relative_to(dest))
print(dest)
PY
done
