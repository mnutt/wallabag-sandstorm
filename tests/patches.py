#!/usr/bin/env python3
"""Check patches against cached, checksum-pinned archives without touching a dev build.

Run in the build VM: python3 tests/patches.py --group proxy --group wallabag
Run where Node is available: python3 tests/patches.py --group extension
--compare-ref additionally proves that a documentation/context-only edit leaves the
patched source identical to that produced by the selected git revision.
"""
import argparse
import hashlib
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parent.parent
FLAGS = ['-p1', '--batch', '--forward', '--fuzz=0', '--no-backup-if-mismatch']


def run(args, **kwargs):
    return subprocess.run(args, check=True, capture_output=True, **kwargs)


def lock(path):
    return dict(line.split('=', 1) for line in (ROOT / path).read_text().splitlines()
                if line and not line.startswith('#'))


def targets(patches):
    result = set()
    for text in patches:
        for path in re.findall(r'^\+\+\+ b/([^\t\n]+)', text, re.M):
            if Path(path).is_absolute() or '..' in Path(path).parts:
                raise ValueError('Unsafe patch target: ' + path)
            result.add(path)
    return result


def apply(archive, patches, directory):
    paths = targets(patches)
    # Only these text files are needed for patch/syntax checks. Do not unpack
    # executable dependencies or write into build/ while spk dev may be using it.
    with tarfile.open(archive) as tar:
        for member in tar:
            relative = member.name.partition('/')[2]
            if relative in paths and member.isfile():
                dest = directory / relative
                dest.parent.mkdir(parents=True, exist_ok=True)
                dest.write_bytes(tar.extractfile(member).read())
    for patch in patches:
        run(['patch', '-d', str(directory), *FLAGS], input=patch.encode())
    return {path: (directory / path).read_bytes() for path in paths}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--compare-ref')
    parser.add_argument('--group', choices=['proxy', 'wallabag', 'extension'], action='append',
                        help='May be repeated; defaults to all (requires PHP, Go and Node).')
    args = parser.parse_args()
    app = lock('upstream.lock')
    extension = lock('extension/upstream.lock')
    groups = [
        ('proxy', 'powerbox-http-proxy.tar.gz', app['PROXY_SHA256'], sorted(ROOT.glob('patches/proxy-*.patch'))),
        ('wallabag', 'wallabag-' + app['WALLABAG_VERSION'] + '.tar.gz', app['WALLABAG_SHA256'], sorted(ROOT.glob('patches/wallabag-*.patch'))),
        ('extension', 'wallabagger-' + extension['WALLABAGGER_VERSION'] + '.tar.gz', extension['WALLABAGGER_SHA256'], [ROOT / 'extension/wallabagger-sandstorm.patch']),
    ]
    with tempfile.TemporaryDirectory(prefix='wallabag-patches-') as temp:
        for name, filename, checksum, files in groups:
            if args.group and name not in args.group:
                continue
            archive = ROOT / '.sandstorm/caches' / filename
            if hashlib.sha256(archive.read_bytes()).hexdigest() != checksum:
                raise ValueError('Archive checksum mismatch: ' + filename)
            patches = [path.read_text() for path in files]
            for path, patch in zip(files, patches):
                assert patch.startswith('# Target:') and '\n# Purpose:' in patch, f'Missing intent: {path}'
            directory = Path(temp) / name
            directory.mkdir()
            output = apply(archive, patches, directory)
            for path, data in output.items():
                if path.endswith('.php'):
                    run(['php', '-l', str(directory / path)])
                elif path.endswith('.js'):
                    run(['node', '--input-type=module', '--check'], input=data)
                elif path.endswith('.go'):
                    run(['gofmt', '-e', str(directory / path)])
            # Refuse an already-applied patch, rather than silently undoing it.
            for patch in patches:
                result = subprocess.run(['patch', '-d', str(directory), *FLAGS, '--dry-run'],
                                        input=patch.encode(), capture_output=True)
                assert result.returncode != 0, 'Patch unexpectedly applies twice: ' + name
            if args.compare_ref:
                old = [run(['git', 'show', f'{args.compare_ref}:{path.relative_to(ROOT)}'], cwd=ROOT).stdout.decode()
                       for path in files]
                previous = Path(temp) / (name + '-previous')
                previous.mkdir()
                assert output == apply(archive, old, previous), f'Patched source differs from {args.compare_ref}: {name}'
            print(f'PASS: {name}: {len(patches)} documented patches, exact context, syntax, repeat-apply rejection'
                  + (f', identical output to {args.compare_ref}' if args.compare_ref else ''))


if __name__ == '__main__':
    try:
        main()
    except subprocess.CalledProcessError as error:
        print(error.stdout.decode(errors='replace'))
        print(error.stderr.decode(errors='replace'))
        raise
