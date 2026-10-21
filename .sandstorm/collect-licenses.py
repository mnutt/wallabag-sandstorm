#!/usr/bin/env python3
"""Retain bundled runtime/library license notices without copying build tooling."""
import json
from pathlib import Path
import shutil
import subprocess

root = Path('/opt/app')
output = root / 'build/licenses'
output.mkdir(parents=True, exist_ok=True)
shutil.copyfile(root / 'LICENSE', output / 'integration-MIT.txt')
shutil.copyfile(root / 'build/proxy-source/license.txt', output / 'powerbox-http-proxy-Apache-2.0.txt')
# Debian copyright files are small; retaining the provisioned environment's
# notices also covers dynamically loaded PHP extensions not exercised by tracing.
for source in Path('/usr/share/doc').glob('*/copyright'):
    if source.is_file():
        dest = output / 'debian' / source.parent.name / 'copyright'
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, dest)
# Include notices from the pinned adapter's module dependencies and Go itself.
data = subprocess.check_output(['go', 'list', '-m', '-json', 'all'], cwd=root / 'build/proxy-source').decode()
decoder = json.JSONDecoder()
while data.strip():
    module, offset = decoder.raw_decode(data.lstrip())
    data = data.lstrip()[offset:]
    directory = module.get('Dir')
    if not directory:
        continue
    for source in Path(directory).iterdir():
        if source.is_file() and source.name.lower().startswith(('license', 'copying', 'notice')):
            dest = output / 'go' / module['Path'] / source.name
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, dest)
goroot = subprocess.check_output(['go', 'env', 'GOROOT']).decode().strip()
go_license = Path(goroot) / 'LICENSE'
if go_license.is_file():
    shutil.copyfile(go_license, output / 'go-LICENSE.txt')
else:
    # Debian relocates the Go distribution's license into its copyright file.
    package = Path(goroot).name.replace('go-', 'golang-') + '-go'
    shutil.copyfile(Path('/usr/share/doc') / package / 'copyright', output / 'go-LICENSE.txt')
