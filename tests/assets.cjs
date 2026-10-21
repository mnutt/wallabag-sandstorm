// Run like smoke.cjs against an installed disposable grain. Checks headers and
// actual transfer sizes through Sandstorm, not just nginx's direct response.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
  assert.ok(process.env.GRAIN_URL && process.env.SANDSTORM_STORAGE_STATE);
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true,
    args: ['--no-sandbox', '--host-resolver-rules=MAP *.local.sandstorm.io 127.0.0.1, MAP local.sandstorm.io 127.0.0.1'],
  });
  try {
    const context = await browser.newContext({ storageState: process.env.SANDSTORM_STORAGE_STATE });
    const page = await context.newPage();
    const bundleResponse = page.waitForResponse(r => new URL(r.url()).pathname === '/wallassets/material.js');
    await page.goto(process.env.GRAIN_URL);
    await page.frameLocator('iframe').first().locator('#content').waitFor({ timeout: 120000 });
    const frame = page.frames().find(f => f.url().includes('ui-'));
    await frame.waitForLoadState('load');
    const bundle = await bundleResponse;
    assert.equal(bundle.status(), 200);
    assert.equal(bundle.headers()['content-encoding'], 'gzip');
    assert.ok(bundle.headers().etag);
    const metrics = () => frame.evaluate(() => performance.getEntriesByType('resource')
      .filter(e => /\/wallassets\/material\.(js|css)$/.test(new URL(e.name).pathname))
      .map(e => ({ path: new URL(e.name).pathname, ms: Math.round(e.duration),
        transferred: e.transferSize, encoded: e.encodedBodySize, decoded: e.decodedBodySize })));
    const cold = await metrics();
    assert.equal(cold.length, 2);
    for (const asset of cold) assert.ok(asset.encoded < asset.decoded / 2, asset.path);
    const html = await frame.goto(frame.url());
    assert.equal(html.headers()['content-encoding'], undefined, 'Do not compress token-bearing HTML');
    for (const header of ['x-sandstorm-app-bootstrap-ms', 'x-sandstorm-app-kernel-ms',
      'x-sandstorm-app-upstream-header-seconds']) {
      assert.match(html.headers()[header] || '', /^\d+(\.\d+)?$/, header);
    }
    console.log('PHP timings:', Object.fromEntries(Object.entries(html.headers())
      .filter(([name]) => name.startsWith('x-sandstorm-app-'))));
    const repeat = await metrics();
    for (const asset of repeat) assert.ok(asset.transferred < 2000, 'Reuse cached body: ' + asset.path);
    console.log(JSON.stringify({ cold, repeat }, null, 2));
    console.log('PASS static compression, ETags, repeat-load reuse, uncompressed HTML');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
