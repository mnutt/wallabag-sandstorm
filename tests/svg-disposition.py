#!/usr/bin/env python3
"""Run in the build VM: python3 tests/svg-disposition.py.
Exercise the real nginx config with isolated files and a loopback-only listener.
"""
from pathlib import Path
import http.client
import socket
import subprocess
import tempfile
import time

source = Path('integration/nginx.conf').read_text()
with tempfile.TemporaryDirectory(prefix='wallabag-svg-') as directory:
    root = Path(directory)
    for name in ('image.svg', 'IMAGE.SVG'):
        (root / name).write_text('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>')
    (root / 'image.png').write_bytes(b'png fixture')
    (root / 'page.html').write_text('<p>hello</p>')
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    config = source.replace('listen 8000;', f'listen 127.0.0.1:{port};')
    config = config.replace('/opt/app/build/wallabag/web', str(root))
    config = config.replace('/var/run/nginx.pid', str(root / 'nginx.pid'))
    config = config.replace('/var/log/nginx/error.log', str(root / 'error.log'))
    for name in ('body', 'fastcgi', 'proxy'):
        config = config.replace('/var/lib/nginx/' + name, str(root / name))
    (root / 'nginx.conf').write_text(config)
    process = subprocess.Popen(['nginx', '-c', str(root / 'nginx.conf'), '-g', 'daemon off;'])
    def request(path, method='GET', permission='access'):
        conn = http.client.HTTPConnection('127.0.0.1', port, timeout=3)
        try:
            conn.request(method, path, headers={'X-Sandstorm-Permissions': permission})
            response = conn.getresponse()
            return response.status, dict(response.getheaders()), response.read()
        finally:
            conn.close()
    try:
        for attempt in range(50):
            try:
                request('/page.html')
                break
            except ConnectionRefusedError:
                time.sleep(0.1)
        for path in ('/image.svg', '/IMAGE.SVG', '/image.svg?download=0'):
            for method in ('GET', 'HEAD'):
                status, headers, body = request(path, method)
                assert status == 200, (path, status)
                assert headers['Content-Type'] == 'image/svg+xml', headers
                assert headers['Content-Disposition'] == 'attachment; filename="image.svg"', headers
                if method == 'HEAD':
                    assert not body
        for path in ('/image.png', '/page.html'):
            assert 'Content-Disposition' not in request(path)[1]
        assert request('/image.svg', permission='feeds')[0] == 403
        print('PASS: nginx SVG GET/HEAD attachment, uppercase extension, query string, unaffected PNG/HTML, permission enforcement')
    finally:
        process.terminate()
        process.wait(timeout=5)
