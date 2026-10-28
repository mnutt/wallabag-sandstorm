#!/usr/bin/env python3
"""Run in the build VM; check redirect origins through real nginx and PHP-FPM."""
from pathlib import Path
import http.client
import socket
import subprocess
import tempfile
import time

source = Path('integration/nginx.conf').read_text()
autoload = Path('build/wallabag/vendor/autoload.php').resolve()
with tempfile.TemporaryDirectory(prefix='wallabag-redirect-') as directory:
    root = Path(directory)
    (root / 'app.php').write_text("""<?php
require %s;
$request = Symfony\\Component\\HttpFoundation\\Request::createFromGlobals();
$controller = new Symfony\\Bundle\\FrameworkBundle\\Controller\\RedirectController();
$controller->urlRedirectAction($request, '/import/', true)->send();
""" % repr(str(autoload)))
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    config = source.replace('listen 8000;', f'listen 127.0.0.1:{port};')
    config = config.replace('/opt/app/build/wallabag/web', str(root))
    config = config.replace('/var/run/nginx.pid', str(root / 'nginx.pid'))
    config = config.replace('/var/log/nginx/error.log', str(root / 'error.log'))
    config = config.replace('/var/run/php/php8.2-fpm.sock', str(root / 'php.sock'))
    for name in ('body', 'fastcgi', 'proxy'):
        config = config.replace('/var/lib/nginx/' + name, str(root / name))
    (root / 'nginx.conf').write_text(config)
    (root / 'fpm.conf').write_text(f'''[global]
error_log = {root}/php.log
daemonize = no
[www]
listen = {root}/php.sock
pm = static
pm.max_children = 1
''')
    processes = []
    try:
        processes.append(subprocess.Popen(['php-fpm8.2', '-F', '-y', str(root / 'fpm.conf')]))
        for attempt in range(50):
            if (root / 'php.sock').exists():
                break
            time.sleep(0.1)
        processes.append(subprocess.Popen(['nginx', '-c', str(root / 'nginx.conf'), '-g', 'daemon off;']))
        for scheme, host in [('http', 'ui-test.local.sandstorm.io:6091'),
                             ('https', 'ui-test.example:8443'),
                             ('https', 'ui-test.example'), ('http', 'ui-test.example')]:
            for attempt in range(50):
                conn = http.client.HTTPConnection('127.0.0.1', port, timeout=3)
                try:
                    conn.request('GET', '/import?test=1', headers={
                        'Host': host, 'X-Forwarded-Proto': scheme,
                        'X-Sandstorm-Base-Path': f'{scheme}://{host}',
                        'X-Sandstorm-Permissions': 'access'})
                    response = conn.getresponse()
                    body = response.read()
                    break
                except ConnectionRefusedError:
                    time.sleep(0.1)
                finally:
                    conn.close()
            assert response.status == 301, (response.status, body)
            expected = f'{scheme}://{host}/import/?test=1'
            assert response.getheader('Location') == expected, (response.getheader('Location'), expected)
        print('PASS: Symfony redirects preserve HTTP/HTTPS origins, custom ports, and queries through nginx/PHP-FPM')
    finally:
        for process in reversed(processes):
            process.terminate()
            process.wait(timeout=5)
