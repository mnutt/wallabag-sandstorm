// Requires Playwright and a disposable grain running the updated package.
// WALLABAG_WEBKEY_FILE=/path/to/private.json node tests/extension.cjs
// Private JSON: {"webkey":"https://api-…/#TOKEN","grainUrl":"https://…/grain/ID"}
// Tests create/delete articles. Never commit the credential file.
// A temporary extension copy pregrants only the API and fixture hosts, bypassing
// native permission prompts in headless CI. Real permission/popup UI was also
// tested manually. Production manifest remains unchanged.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
(async () => {
  const credentials = JSON.parse(fs.readFileSync(process.env.WALLABAG_WEBKEY_FILE));
  const webkey = new URL(credentials.webkey);
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'wallabagger-test-'));
  const extension = path.join(root, 'extension');
  fs.cpSync(path.resolve('dist/wallabagger-sandstorm-chrome'), extension, { recursive: true });
  const manifestPath = path.join(extension, 'manifest.json');
  const manifest = JSON.parse(fs.readFileSync(manifestPath));
  manifest.host_permissions = [webkey.origin + '/*', 'https://capture-test.invalid/*'];
  fs.writeFileSync(manifestPath, JSON.stringify(manifest));
  let context;
  try {
    context = await chromium.launchPersistentContext(path.join(root, 'profile'), {
      executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true,
      args: ['--no-sandbox', `--disable-extensions-except=${extension}`, `--load-extension=${extension}`,
        '--host-resolver-rules=MAP *.local.sandstorm.io 127.0.0.1, MAP local.sandstorm.io 127.0.0.1'],
    });
    context.setDefaultTimeout(20000);
    const worker = context.serviceWorkers()[0] || await context.waitForEvent('serviceworker');
    const extensionId = new URL(worker.url()).host;
    const options = await context.newPage();
    await options.goto(`chrome-extension://${extensionId}/options.html`);
    await options.locator('#webkey').fill(credentials.webkey);
    await options.locator('#grain').fill(credentials.grainUrl);
    await options.getByRole('button', { name: 'Connect', exact: true }).click();
    try { await options.getByText(/^Connected to wallabag 2/).waitFor({ timeout: 120000 }); }
    catch (error) { throw new Error('Connection failed: ' + await options.locator('#status').innerText()); }
    assert.equal(await options.locator('#webkey').inputValue(), '');
    const article = await context.newPage();
    const articleUrl = `https://capture-test.invalid/automated-${Date.now()}`;
    await context.route('https://capture-test.invalid/**', route => route.fulfill({
      contentType: 'text/html', body: '<!doctype html><html><head><title>Browser capture regression</title></head><body><article><h1>Browser capture regression</h1>' +
        '<p>This browser-captured article cannot be retrieved by the server. Its contents must survive without an outbound website grant. This paragraph contains enough text to exercise article preservation.</p>'.repeat(10) +
        '<img src="https://never-granted.invalid/image.png"><script>window.testScript=true</script><a href="javascript:alert(1)">Unsafe link</a></article></body></html>',
    }));
    await context.route('https://never-granted.invalid/**', route => route.abort());
    await article.goto(articleUrl);
    await article.bringToFront();
    const result = await options.evaluate(async url => {
      const tabs = await chrome.tabs.query({ active: true, currentWindow: true });
      if (tabs[0]?.url !== url) throw new Error('Fixture tab is not active');
      const port = chrome.runtime.connect({ name: 'popup' });
      return new Promise((resolve, reject) => {
        const timer = setTimeout(() => { port.disconnect(); reject(new Error('Browser capture timed out')); }, 20000);
        port.onMessage.addListener(message => {
          if (message.response === 'background-port-is-connected') port.postMessage({ request: 'save', tab: tabs[0] });
          if (['article', 'error'].includes(message.response)) {
            clearTimeout(timer); port.disconnect(); resolve(message);
          }
        });
      });
    }, articleUrl);
    assert.equal(result.response, 'article', result.error?.message);
    const id = result.article.id;
    console.log('PASS: setup and browser capture');
    const checks = await options.evaluate(async ({ id, articleUrl }) => {
      const { WallabagApi } = await import('./js/wallabag-api.js');
      const { Logger } = await import('./js/utils/logger.js');
      const api = new WallabagApi(new Logger('regression'));
      await api.init();
      const headers = { Authorization: 'Bearer ' + api.data.ApiToken, 'Content-Type': 'application/json' };
      const request = async (suffix, init = {}) => {
        try { return await fetch(api.data.Url + suffix, { headers, credentials: 'omit', signal: AbortSignal.timeout(15000), ...init }); }
        catch (error) { throw new Error(`${init.method || 'GET'} ${suffix}: ${error.message}`); }
      };
      const saved = await (await request(`/api/entries/${id}.json`)).json();
      await api.saveTitle(id, 'Edited by browser extension');
      await api.saveStarred(id, 1);
      await api.saveArchived(id, 1);
      await api.saveTags(id, 'extension-regression');
      const edited = await (await request(`/api/entries/${id}.json`)).json();
      const empty = await request('/api/entries.json', { method: 'POST', body: JSON.stringify({ url: articleUrl, content: '', sandstorm_capture: true }) });
      let urlOnlyRejected = false;
      try { await api.savePage({ url: articleUrl }); } catch (e) { urlOnlyRejected = e.message.includes('browser tab'); }
      const noAuth = await request('/api/entries.json', { headers: {} });
      await api.deleteArticle(id);
      const missing = await request(`/api/entries/${id}.json`);
      api.data.ApiToken = 'intentionally-invalid-test-token';
      let invalidRejected = false;
      try { await api.checkUrl(); } catch (e) { invalidRejected = /HTTP (401|403)/.test(e.message); }
      return {
        title: saved.title, hasText: saved.content.includes('browser-captured article'),
        hasScript: /<script|(?:href|src)=["']\s*javascript:/i.test(saved.content), editedTitle: edited.title,
        starred: edited.is_starred, archived: edited.is_archived, tags: edited.tags.map(t => t.label),
        emptyStatus: empty.status, urlOnlyRejected, noAuthStatus: noAuth.status,
        deletedStatus: missing.status, invalidRejected, grainUrl: api.data.GrainUrl,
      };
    }, { id, articleUrl });
    assert.equal(checks.title, 'Browser capture regression');
    assert.equal(checks.hasText, true);
    assert.equal(checks.hasScript, false);
    assert.equal(checks.editedTitle, 'Edited by browser extension');
    assert.equal(checks.starred, 1); assert.equal(checks.archived, 1);
    assert.ok(checks.tags.includes('extension-regression'));
    assert.equal(checks.emptyStatus, 400); assert.equal(checks.urlOnlyRejected, true);
    assert.ok([401, 403].includes(checks.noAuthStatus));
    assert.equal(checks.deletedStatus, 404); assert.equal(checks.invalidRejected, true);
    assert.equal(checks.grainUrl, credentials.grainUrl.replace(/\/$/, ''));
    console.log('PASS: setup, browser capture, sanitization, title/tags/star/archive/delete, missing/invalid auth, empty capture, URL-only rejection, stable grain URL');
  } finally {
    if (context) await context.close();
    fs.rmSync(root, { recursive: true, force: true });
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
