// Run against a disposable grain: this saves articles and restarts the grain.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
  assert.ok(process.env.GRAIN_URL && process.env.SANDSTORM_STORAGE_STATE,
    'Set GRAIN_URL and SANDSTORM_STORAGE_STATE (Playwright login state).');
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true,
    args: ['--no-sandbox', '--host-resolver-rules=MAP *.local.sandstorm.io 127.0.0.1, MAP local.sandstorm.io 127.0.0.1'],
  });
  try {
    const context = await browser.newContext({ storageState: process.env.SANDSTORM_STORAGE_STATE });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(process.env.GRAIN_URL);
    const content = page.frameLocator('iframe').first().locator('#content').first();
    await content.waitFor({ timeout: 120000 });
    let frame = page.frames().find(frame => frame.url().includes('ui-'));
    assert.ok(frame);
    assert.equal(await frame.locator('input[type="password"]').count(), 0);
    // Account management is unavailable even to the grain owner.
    assert.equal(await frame.evaluate(async () => (await fetch('/config')).status), 403);
    const title = 'A Bold GNU Head by Aurélio A. Heckert - GNU Project';
    await frame.locator('#nav-btn-add').click();
    // Exercise a server-side form rejection (HTTP 200) without discarding input.
    await frame.locator('#entry_url').fill('https://form-validation.invalid/article');
    const csrf = frame.locator('form[name="entry"] input[name="entry[_token]"]');
    await csrf.evaluate(input => { input.value = 'invalid-test-token'; });
    await frame.locator('form[name="entry"]').evaluate(form => {
      form.requestSubmit();
    });
    await frame.getByRole('status').filter({ hasText: 'The CSRF token is invalid.' }).waitFor();
    assert.equal(await frame.locator('#entry_url').inputValue(), 'https://form-validation.invalid/article');
    assert.equal(await frame.locator('form[name="entry"] button[type="submit"]').isEnabled(), true);
    assert.notEqual(await csrf.inputValue(), 'invalid-test-token');
    await frame.locator('#entry_url').fill('https://www.gnu.org/graphics/heckert_gnu.html');
    await frame.locator('form[name="entry"] button[type="submit"]').click();
    // A fresh grain asks for a grant. An already-granted grain should reuse it.
    await Promise.race([
      page.getByText('Use https://www.gnu.org', { exact: true }).waitFor({ timeout: 30000 }),
      frame.getByRole('link', { name: title, exact: true }).waitFor({ timeout: 30000 }),
      frame.locator('#content h1').filter({ hasText: title }).waitFor({ timeout: 30000 }),
    ]);
    const grant = page.getByText('Use https://www.gnu.org', { exact: true });
    if (await grant.isVisible()) await grant.click();
    if (!/\/view\/\d+$/.test(frame.url())) {
      await frame.getByRole('link', { name: title, exact: true }).click({ timeout: 120000 });
      await frame.waitForURL('**/view/*');
    }
    await frame.waitForFunction(() => {
      const image = document.querySelector('#content img');
      return image && image.getAttribute('src').startsWith('/assets/images/') && image.complete && image.naturalWidth > 0;
    });
    const articlePath = new URL(frame.url()).pathname;
    await Promise.all([
      frame.waitForNavigation({ timeout: 120000 }),
      page.getByText('Restart', { exact: true }).click(),
    ]);
    await content.waitFor({ timeout: 120000 });
    frame = page.frames().find(frame => frame.url().includes('ui-'));
    await frame.goto(new URL(articlePath, frame.url()).href);
    await frame.waitForFunction(() => {
      const image = document.querySelector('#content img');
      return image && image.complete && image.naturalWidth > 0;
    });
    assert.ok((await frame.locator('#content').first().innerText()).includes(title));
    assert.deepEqual(errors, []);
    if (process.env.SCREENSHOT) await page.screenshot({ path: process.env.SCREENSHOT });
    console.log('PASS: automatic login, account-route denial, HTTPS fetch, local images, and restart persistence.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
