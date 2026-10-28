// Use an existing disposable grain and an administrator's browser state.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
(async () => {
    assert.ok(process.env.GRAIN_URL && process.env.SANDSTORM_STORAGE_STATE);
    const grain = process.env.GRAIN_URL.replace(/(\/grain\/[^/]+).*/, '$1');
    const grainId = new URL(grain).pathname.split('/')[2];
    const browser = await chromium.launch({
        executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true,
        args: ['--no-sandbox', '--host-resolver-rules=MAP *.local.sandstorm.io 127.0.0.1, MAP local.sandstorm.io 127.0.0.1'],
    });
    try {
        for (const path of ['/import/', '/import', '/import/?test=1#network-status']) {
            const context = await browser.newContext({storageState: process.env.SANDSTORM_STORAGE_STATE});
            try {
                const page = await context.newPage();
                await page.goto(grain + path);
                const iframe = page.locator(`#grain-frame-${grainId}`);
                await iframe.waitFor({timeout:120000});
                const frame = await (await iframe.elementHandle()).contentFrame();
                await frame.locator('#network-status').waitFor({timeout:120000});
                const url = new URL(frame.url());
                assert.equal(url.port, new URL(grain).port);
                assert.equal(url.pathname, '/import/');
                if (path.includes('?')) {
                    assert.equal(url.search, '?test=1');
                    assert.equal(url.hash, '#network-status');
                }
                console.log(`PASS: direct grain link ${path}`);
            } finally { await context.close(); }
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
