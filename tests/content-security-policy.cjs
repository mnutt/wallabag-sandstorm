// Real browser enforcement: existing remote markup must not reach the network.
const {chromium} = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
(async () => {
  const patch = fs.readFileSync('patches/wallabag-ui-content-security-policy.patch', 'utf8');
  const policy = patch.match(/Content-Security-Policy" content="([^"]+)"/)[1];
  const browser = await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless:true, args:['--no-sandbox']});
  try {
    const page = await browser.newPage();
    const remote = [];
    const png = fs.readFileSync('tests/fixtures/captured-image.png');
    await page.route('http://**/*', route => {
      const url = new URL(route.request().url());
      if (url.hostname === 'remote.invalid') remote.push(url.href);
      if (url.pathname === '/local.png') return route.fulfill({contentType:'image/png', body:png});
      if (url.hostname === 'shell.invalid') return route.fulfill({body:'credentials'});
      return route.fulfill({contentType:'text/html', headers:{
        // Sandstorm's policy deliberately allows remote images/media and its shell frame.
        'Content-Security-Policy': "default-src 'none'; img-src * data: blob:; media-src * data: blob:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src 'self'; frame-src 'self' http://shell.invalid",
      }, body:`<html><head><meta http-equiv="Content-Security-Policy" content="${policy}"></head><body>
        <img id="local" src="/local.png"><img src="http://remote.invalid/image.png">
        <picture><source srcset="http://remote.invalid/responsive.png"><img src="/local.png"></picture>
        <div style="background-image:url(http://remote.invalid/background.png);width:10px;height:10px"></div>
        <video preload="auto" src="http://remote.invalid/video.mp4" poster="http://remote.invalid/poster.png"></video>
        <audio preload="auto" src="http://remote.invalid/audio.mp3"></audio>
        <object data="http://remote.invalid/object"></object>
        <iframe src="http://shell.invalid/offer"></iframe>
        </body></html>`});
    });
    await page.goto('http://library.invalid/');
    await page.waitForFunction(() => document.querySelector('#local').naturalWidth > 0);
    await page.waitForFunction(() => document.querySelector('video').error && document.querySelector('audio').error);
    assert.equal(page.frames().some(frame => frame.url() === 'http://shell.invalid/offer'), true);
    assert.deepEqual(remote, [], 'Remote content reached the network');
    console.log('PASS: remote images, srcset, CSS backgrounds, video, audio, poster and objects blocked; local images and Sandstorm credential iframe allowed');
  } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode = 1;});
