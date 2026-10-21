// Use a disposable grain with an unread article. Restores automatic theme.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
  assert.ok(process.env.GRAIN_URL && process.env.SANDSTORM_STORAGE_STATE);
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true,
    args: ['--no-sandbox', '--host-resolver-rules=MAP *.local.sandstorm.io 127.0.0.1, MAP local.sandstorm.io 127.0.0.1'],
  });
  let context, page, frame;
  const errors = [];
  const open = async (url = process.env.GRAIN_URL) => {
    context = await browser.newContext({ storageState: process.env.SANDSTORM_STORAGE_STATE, colorScheme: 'light' });
    page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(url);
    await ready();
  };
  const ready = async () => {
    await page.frameLocator('iframe').first().locator('#content').waitFor({ timeout: 120000 });
    frame = page.frames().find(f => f.url().includes('ui-'));
    assert.ok(frame);
  };
  const choose = async theme => {
    const saved = page.waitForResponse(r => r.url().endsWith('/sandstorm/theme') && r.request().method() === 'POST');
    // Use the real native control, independent of which menu currently contains it.
    await frame.locator(`.js-theme-toggle[data-theme="${theme}"]`).first().evaluate(el => el.click());
    assert.equal((await saved).status(), 204);
  };
  const dark = () => frame.locator('html').evaluate(el => el.classList.contains('dark-theme'));
  try {
    await open();
    const link = frame.locator('.entries a[href*="/view/"]').first();
    await link.click();
    await frame.waitForURL('**/view/*');
    const articlePath = new URL(frame.url()).pathname;
    await page.waitForURL(url => url.pathname.endsWith(articlePath));
    await frame.evaluate(() => history.replaceState(null, '', location.pathname + '?test=persist%20query#article'));
    await page.waitForURL(url => url.search === '?test=persist%20query' && url.hash === '#article');
    const deepLink = page.url();
    await page.reload();
    await ready();
    assert.equal(new URL(frame.url()).pathname, articlePath);
    assert.equal(new URL(frame.url()).search, '?test=persist%20query');
    assert.equal(new URL(frame.url()).hash, '#article');
    await frame.evaluate(() => { location.hash = 'content'; });
    await page.waitForURL(url => url.hash === '#content');
    await page.goBack();
    await page.waitForURL(url => url.hash === '#article');
    await choose('dark');
    assert.equal(await dark(), true);
    const oldOrigin = new URL(frame.url()).origin;
    await context.close();
    // New context carries only Sandstorm login state, with no app cookies/storage.
    await open(deepLink);
    assert.notEqual(new URL(frame.url()).origin, oldOrigin);
    assert.equal(await dark(), true, 'Dark theme must survive a new app origin');
    assert.equal(new URL(frame.url()).pathname, articlePath);
    assert.deepEqual(await frame.evaluate(async () => {
      const token = document.querySelector('meta[name="sandstorm-theme-token"]').content;
      return [
        (await fetch('/sandstorm/theme')).status,
        (await fetch('/sandstorm/theme', {method:'POST', body:new URLSearchParams({theme:'light'})})).status,
        (await fetch('/sandstorm/theme', {method:'POST', body:new URLSearchParams({theme:'invalid', _token:token})})).status,
        (await fetch('/sandstorm/theme', {method:'POST', body:new URLSearchParams({theme:'light', '_token[]':'invalid'})})).status,
        (await fetch('/sandstorm/theme', {method:'POST', body:new URLSearchParams({'theme[]':'dark', _token:token})})).status,
      ];
    }), [405, 403, 400, 403, 400]);
    await choose('light');
    await context.close();
    await open(deepLink);
    assert.equal(await dark(), false);
    await choose('auto');
    await page.emulateMedia({ colorScheme: 'dark' });
    await frame.waitForFunction(() => document.documentElement.classList.contains('dark-theme'));
    await context.close();
    await open(deepLink);
    assert.equal(await dark(), false, 'Auto must use this browser’s light color scheme');
    await page.emulateMedia({ colorScheme: 'dark' });
    await frame.waitForFunction(() => document.documentElement.classList.contains('dark-theme'));
    assert.deepEqual(errors, []);
    console.log('PASS: deep links, query strings, fragments, reload/back, new origins, dark/light/auto persistence, and CSRF validation.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
