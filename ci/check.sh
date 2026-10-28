#!/bin/bash
# Run inside the CI container or a provisioned build VM with Node installed.
set -euo pipefail
cd /opt/app
case "${1:-}" in
    '')
        bash extension/build.sh
        bash .sandstorm/build.sh
        ;;
    --tests-only) ;;
    *) echo 'Usage: ci/check.sh [--tests-only]' >&2; exit 2 ;;
esac

python3 tests/patches.py
bash tests/proxy-startup.sh
node tests/capture-images.mjs

# Explicit list: other scripts require a running Sandstorm instance/browser or
# disposable grain arguments and must not be accidentally invoked without them.
for test in \
    browser-import import-recovery import-diagnostics import-upload \
    code-blocks inline-spacing gitbook-extraction html-base \
    captured-images images csrf-scheme theme-state extraction-status; do
    echo "Running tests/$test.php"
    php "tests/$test.php"
done
for test in redirect-origin svg-disposition import-upload-http; do
    echo "Running tests/$test.py"
    python3 "tests/$test.py"
done
