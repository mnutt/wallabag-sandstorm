"""Run in the VM: verify the real nginx rules block legacy raw uploads."""
import pathlib, socket, subprocess, tempfile, time, urllib.request, urllib.error
with tempfile.TemporaryDirectory(prefix='import-http-') as tmp:
    root = pathlib.Path(tmp)
    web = root / 'web'
    (web / 'uploads/import').mkdir(parents=True)
    (web / 'uploads/import/42.html').write_text('<script>alert(1)</script>')
    (web / 'ok.txt').write_text('ok')
    for name in ['body', 'fastcgi', 'proxy']:
        (root / name).mkdir()
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    config = pathlib.Path('integration/nginx.conf').read_text()
    config = config.replace('listen 8000;', f'listen 127.0.0.1:{port};')
    config = config.replace('/opt/app/build/wallabag/web', str(web))
    config = config.replace('/var/run/nginx.pid', str(root / 'nginx.pid'))
    config = config.replace('/var/log/nginx/error.log', str(root / 'error.log'))
    config = config.replace('/var/lib/nginx/', tmp + '/')
    (root / 'nginx.conf').write_text(config)
    process = subprocess.Popen(['/usr/sbin/nginx', '-c', str(root / 'nginx.conf'), '-g', 'daemon off;'])
    def request(path):
        req = urllib.request.Request(f'http://127.0.0.1:{port}' + path, headers={'X-Sandstorm-Permissions': 'access'})
        try:
            with urllib.request.urlopen(req, timeout=1) as response:
                return response.status, response.read()
        except urllib.error.HTTPError as error:
            return error.code, error.read()
    try:
        for attempt in range(50):
            try:
                assert request('/ok.txt') == (200, b'ok')
                break
            except urllib.error.URLError:
                time.sleep(.05)
        else:
            raise RuntimeError('nginx did not start')
        for path in ['/uploads/import', '/uploads/import/42.html', '/uploads/%69mport/42.html']:
            status, body = request(path)
            assert status == 404 and b'<script>' not in body, (path, status)
        print('PASS: nginx serves ordinary assets and blocks raw import uploads, including encoded paths.')
    finally:
        process.terminate()
        process.wait(timeout=5)
