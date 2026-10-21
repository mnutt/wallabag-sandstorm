// Tests real Chromium tab injection and image upload collection without a running grain.
const {chromium} = require('playwright');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'capture-tab-'));
  let context;
  try {
    const extension = path.join(root, 'extension');
    fs.cpSync(path.resolve('dist/wallabagger-sandstorm-chrome'), extension, {recursive:true});
    const manifest = JSON.parse(fs.readFileSync(path.join(extension, 'manifest.json')));
    manifest.host_permissions = ['http://capture-test.invalid/*'];
    fs.writeFileSync(path.join(extension, 'manifest.json'), JSON.stringify(manifest));
    context = await chromium.launchPersistentContext(path.join(root,'profile'), {
      executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless:true,
      args:['--no-sandbox', '--disable-extensions-except='+extension, '--load-extension='+extension]
    });
    const worker = context.serviceWorkers()[0] || await context.waitForEvent('serviceworker');
    const options = await context.newPage();
    await options.goto('chrome-extension://' + new URL(worker.url()).host + '/options.html');
    const png = fs.readFileSync(path.join(__dirname,'fixtures/captured-image.png'));
    await context.route('http://capture-test.invalid/**', route => route.fulfill({
      contentType:'text/html', body:'<html><body><article><h1>Image capture</h1><picture><source srcset="/large.png"><img src="/fallback.png"></picture><img src="/failed.png"><img src="/logo.svg"></article></body></html>'
    }));
    await context.route('http://capture-test.invalid/large.png', route => route.fulfill({contentType:'image/png',body:png}));
    await context.route('http://capture-test.invalid/logo.svg', route => route.fulfill({contentType:'image/svg+xml', body:'<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100"/></svg>'}));
    await context.route('http://capture-test.invalid/failed.png', route => route.abort());
    const article = await context.newPage();
    await article.goto('http://capture-test.invalid/article');
    const captured = await options.evaluate(async base64 => {
      globalThis.browser = chrome;
      globalThis.fetch = async (url, init) => {
        if (init.credentials !== 'omit' || init.headers) throw Error('Unsafe request');
        if (url.endsWith('/logo.svg')) return new Response('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"/>', {headers:{'content-type':'image/svg+xml'}});
        if (!url.endsWith('/large.png')) throw Error('Image unavailable');
        return new Response(Uint8Array.from(atob(base64), c => c.charCodeAt(0)), {headers:{'content-type':'image/png'}});
      };
      const {BrowserContentFetch} = await import('./js/browser-content-fetch/browser-content-fetch.js');
      const capture = new BrowserContentFetch(chrome, {log(){}}, {isRestrictedPage:() => false}, {isSiteToFetchLocally:async () => true});
      const tab = (await chrome.tabs.query({url:'http://capture-test.invalid/*'}))[0];
      return await new Promise((resolve,reject) => {
        const timer = setTimeout(() => reject(Error('Capture timed out')), 15000);
        capture.handle(tab, (url, reset, title, content) => {clearTimeout(timer);resolve(content);}).catch(reject);
      });
    }, png.toString('base64'));
    assert.ok(captured.includes('data:image/png;base64,' + png.toString('base64')));
    assert.ok(captured.includes('src="data:,"'));
    assert.ok(captured.includes('data:image/svg+xml;base64,'));
    assert.ok(captured.includes('data-sandstorm-original-src="http://capture-test.invalid/large.png"'));
    assert.ok(!captured.includes('sandstorm-image.invalid'));
    assert.ok(!captured.includes('<source'));
    console.log('PASS: real Chromium injection on HTTP page, responsive image selection, byte upload and fallback.');
  } finally {
    if (context) await context.close();
    fs.rmSync(root,{recursive:true,force:true});
  }
})().catch(error => {console.error(error);process.exitCode=1;});
