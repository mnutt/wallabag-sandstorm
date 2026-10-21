// Run against a disposable installed-package grain (this imports articles and restarts it).
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
  assert.ok(process.env.GRAIN_URL && process.env.SANDSTORM_STORAGE_STATE);
  const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium',
    headless: true, args: ['--no-sandbox', '--host-resolver-rules=MAP *.local.sandstorm.io 127.0.0.1, MAP local.sandstorm.io 127.0.0.1']});
  try {
    const context = await browser.newContext({storageState: process.env.SANDSTORM_STORAGE_STATE});
    const page = await context.newPage();
    page.setDefaultTimeout(30000);
    const grain = process.env.GRAIN_URL.replace(/\/$/, '');
    const open = async path => {
      await page.goto(grain + path);
      await page.frameLocator('iframe').first().locator('#content').waitFor({timeout: 60000});
      return page.frames().find(f => f.url().includes('ui-'));
    };
    const waitForCounts = (frame, queued, processing, failed) => frame.waitForFunction(expected =>
      ['queued', 'processing', 'failed'].every((key, i) =>
        Number(document.querySelector(`[data-import-count="${key}"] strong`)?.textContent) === expected[i]),
      [queued, processing, failed]);
    let frame = await open('/sandstorm/imports');
    assert.match(new URL(frame.url()).pathname, /^\/import\/?$/);
    assert.equal(await frame.locator('#dropdown-account a[href="/sandstorm/imports"]').count(), 0);
    assert.equal(await frame.locator('.sandstorm-import-notice').count(), 1);
    await frame.locator('.sandstorm-import-notice').getByRole('button', {name:'Pause', exact:true}).waitFor();
    const initialCount = await frame.evaluate(async () => {
      const html = await (await fetch('/')).text();
      const text = new DOMParser().parseFromString(html, 'text/html').body.textContent;
      return Number((text.match(/There are (\d+) entries/) || [0, 0])[1]);
    });
    assert.equal(await frame.evaluate(async () => (await fetch('/sandstorm/imports', {
      method: 'POST', body: new URLSearchParams({action:'pause', _token:'bad'})})).status), 403);
    await frame.getByRole('button', {name:'Pause', exact:true}).click();
    assert.match(new URL(frame.url()).pathname, /^\/import\/?$/);
    await frame.getByRole('heading', {name:'Imports are paused', exact:true}).waitFor();
    await frame.goto(new URL('/import/wallabag-v2', frame.url()).href);
    const prefix = 'https://example.org/async-' + Date.now() + '/';
    const entries = Array.from({length:30}, (_, i) => ({url:prefix+i, title:'Background import '+i,
      content:'<p>Preserved article text with <strong>formatting</strong>.</p>', mimetype:'text/html',
      language:'en', is_archived:0, is_starred:0, tags:[]}));
    await frame.locator('input[type=file]').setInputFiles({name:'wallabag.json', mimeType:'application/json',
      buffer:Buffer.from(JSON.stringify(entries))});
    await frame.getByRole('button', {name:'Upload file', exact:true}).click();
    await frame.waitForURL(url => !url.pathname.includes('/import/'));
    await frame.goto(new URL('/sandstorm/imports', frame.url()).href);
    await waitForCounts(frame, 30, 0, 0);
    await Promise.all([
      frame.waitForNavigation({timeout:60000}),
      page.getByText('Restart', {exact:true}).click(),
    ]);
    await page.frameLocator('iframe').first().getByRole('heading', {name:'Imports are paused', exact:true}).waitFor({timeout:60000});
    frame = page.frames().find(f => f.url().includes('ui-'));
    await waitForCounts(frame, 30, 0, 0);
    await frame.getByRole('button', {name:'Resume', exact:true}).click();
    await frame.getByRole('button', {name:'Pause', exact:true}).waitFor();
    // Leave the grain entirely. Background completion must not require a live session.
    await page.goto('http://local.sandstorm.io:6091/apps');
    await page.getByTitle('Notifications', {exact:true}).click();
    await page.locator('a.notification-item').filter({hasText:'completed an import'})
      .and(page.locator('a[href="' + new URL(grain).pathname + '/sandstorm/imports"]'))
      .waitFor({timeout:120000});
    frame = await open('/sandstorm/imports');
    await waitForCounts(frame, 0, 0, 0);
    await frame.goto(new URL('/', frame.url()).href);
    assert.ok((await frame.locator('#content').innerText()).includes('There are ' + (initialCount + 30) + ' entries'));
    await frame.getByRole('link', {name:'Background import 29', exact:true}).first().click();
    await frame.waitForURL('**/view/*');
    assert.match(await frame.locator('#content').innerText(), /Preserved article text with formatting/);
    console.log('PASS: CSRF, asynchronous upload, pause, Redis persistence across restart, resume, and completion notification after closing the grain.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
