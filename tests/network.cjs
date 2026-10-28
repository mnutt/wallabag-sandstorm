// Use a fresh disposable grain and an administrator's browser state.
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
        const context = await browser.newContext({storageState: process.env.SANDSTORM_STORAGE_STATE,
            viewport: {width:1400,height:1000}});
        let page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const getFrame = async () => {
            const iframe = page.locator(`#grain-frame-${grainId}`);
            await iframe.waitFor({timeout:120000});
            return (await iframe.elementHandle()).contentFrame();
        };
        await page.goto(grain);
        let frame = await getFrame();
        await frame.locator('#nav-btn-add').waitFor({timeout:120000});
        const settings = async () => {
            await frame.goto(new URL('/import/', frame.url()).href);
            await frame.locator('#network-status').filter({hasText:/Disconnected\.|Connected\.|unavailable/}).waitFor();
        };
        await settings();
        await frame.locator('#network-status').filter({hasText:'Disconnected.'}).waitFor({timeout:120000});
        assert.equal(await frame.locator('#network-disconnect').isVisible(), false);
        assert.equal(await frame.evaluate(async () => (await fetch('/_sandstorm/network', {method:'DELETE'})).status), 403);
        assert.equal(await frame.evaluate(async () => (await fetch('/_sandstorm/network/import-complete', {method:'POST'})).status), 404);
        const connect = async () => {
            await frame.getByRole('button',{name:'Connect IpNetwork',exact:true}).click();
            await page.getByText('Admin: grant all outgoing network access',{exact:true}).click();
            await frame.locator('#network-status').filter({hasText:'Connected.'}).waitFor();
        };
        await connect();
        assert.equal(await frame.locator('#network-authorize').isVisible(), false);
        await frame.locator('#nav-btn-add').click();
        await frame.locator('#entry_url').fill('https://example.com/');
        await frame.locator('form[name="entry"] button[type="submit"]').click();
        const title = 'Example Domain';
        await frame.getByRole('link', {name:title,exact:true}).waitFor({timeout:60000});
        assert.equal(await page.getByText('Use https://example.com',{exact:true}).count(), 0);
        await frame.getByRole('link',{name:title,exact:true}).click();
        await frame.waitForURL('**/view/*');
        assert.match(await frame.locator('#content').innerText(), /Example Domain/);
        await Promise.all([frame.waitForNavigation({timeout:120000}), page.getByText('Restart',{exact:true}).click()]);
        frame = await getFrame();
        await settings();
        assert.match(await frame.locator('#network-status').innerText(), /^Connected\./);
        await frame.getByRole('button',{name:'Disconnect IpNetwork',exact:true}).click();
        await frame.locator('#network-status').filter({hasText:'Disconnected.'}).waitFor();

        // Opt in before connecting, then finish an actual queued import. Saved
        // article content avoids depending on a second external website here.
        await frame.locator('label[for="network-auto-disconnect"]').click();
        assert.equal(await frame.locator('#network-auto-disconnect').isChecked(), true);
        await connect();
        await frame.locator('#network-status').filter({hasText:'current or next import finishes'}).waitFor();
        await frame.goto(new URL('/import/wallabag-v2', frame.url()).href);
        await frame.locator('input[type=file]').setInputFiles({name:'network-test.json',mimeType:'application/json',
            buffer:Buffer.from(JSON.stringify([{url:'https://example.net/network-test-'+Date.now(),title:'Automatic network disconnect',
                content:'<p>Saved content for the automatic disconnection test.</p>',mimetype:'text/html',language:'en',
                is_archived:0,is_starred:0,tags:[]}]))});
        await frame.getByRole('button',{name:'Upload file',exact:true}).click();
        await frame.waitForURL(url => !url.pathname.includes('/import/'));
        await page.close();
        page = await context.newPage();
        page.on('pageerror',error => errors.push(error.message));
        await page.goto(grain);
        frame = await getFrame();
        await frame.locator('#nav-btn-add').waitFor({timeout:120000});
        await settings();
        await frame.locator('#network-status').filter({hasText:'Disconnected.'}).waitFor({timeout:60000});
        const state = await frame.evaluate(async () => (await fetch('/_sandstorm/network')).json());
        assert.equal(state.configured, false);
        assert.equal(state.autoDisconnect, false);
        await frame.locator('#nav-btn-add').click();
        await frame.locator('#entry_url').fill('https://example.org/');
        await frame.locator('form[name="entry"] button[type="submit"]').click();
        await page.getByText('Use https://example.org',{exact:true}).waitFor({timeout:30000});
        assert.deepEqual(errors, []);
        console.log('PASS: HTTPS without prompts, restart persistence, manual disconnect, automatic disconnect after background import, restored per-site prompts.');
    } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode=1;});
