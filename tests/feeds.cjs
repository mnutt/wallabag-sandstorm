// Disposable grain required. Creates/deletes fixture entries.
// GRAIN_URL=... SANDSTORM_STORAGE_STATE=... WALLABAG_FEED_FILE=/private/feed.json node tests/feeds.cjs
// feed.json: {"feedUrl":"https://api-…/feeds/unread.xml?origin=https%3A%2F%2Fapi-…", "token":"…"}
// Credentials must have the Feed reader role. Keep this file outside the repository.
const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
(async () => {
  const credentials = JSON.parse(fs.readFileSync(process.env.WALLABAG_FEED_FILE));
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true,
    args: ['--no-sandbox', '--host-resolver-rules=MAP *.local.sandstorm.io 127.0.0.1, MAP local.sandstorm.io 127.0.0.1'] });
  const ids = [];
  let frame;
  try {
    const context = await browser.newContext({ storageState: process.env.SANDSTORM_STORAGE_STATE });
    async function externalRequest(url, options = {}) {
      try { return await context.request.get(url, { ...options, maxRetries: 2 }); }
      catch (_) { throw new Error('External RSS request failed; credential-bearing request details omitted.'); }
    }
    const page = await context.newPage();
    await page.goto(process.env.GRAIN_URL);
    await page.frameLocator('iframe').first().locator('#content').first().waitFor({ timeout: 120000 });
    frame = page.frames().find(f => f.url().includes('ui-'));
    const tag = `rss-test-${Date.now()}`;
    let tagSlug;
    for (const [suffix, archive, starred] of [['unread', 0, 1], ['archive', 1, 0]]) {
      const entry = await frame.evaluate(async ({ tag, suffix, archive, starred }) => {
        const r = await fetch('/api/entries.json', { method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ url: `https://rss-test.invalid/${tag}/${suffix}`, title: `RSS & <XML> ]]> ${suffix}`,
            content: '<article><p>' + 'RSS captured text &amp; safe markup. '.repeat(40) + '</p></article>',
            sandstorm_capture: true, tags: tag, archive, starred }) });
        if (!r.ok) throw new Error(`Fixture creation: ${r.status}`);
        return r.json();
      }, { tag, suffix, archive, starred });
      ids.push(entry.id);
      tagSlug = entry.tags.find(t => t.label === tag).slug;
    }
    const result = await page.evaluate(async ({ credentials, tag }) => {
      const origin = new URL(credentials.feedUrl).origin;
      const auth = 'Basic ' + btoa('sandstorm:' + credentials.token);
      async function request(path, method = 'GET', authorization = auth) {
        const r = await fetch(origin + path, { method, credentials: 'omit', headers: authorization ? { Authorization: authorization } : {}, signal: AbortSignal.timeout(15000) });
        return { status: r.status, type: r.headers.get('Content-Type'), text: await r.text() };
      }
      async function feed(kind, extra = {}, authorization = auth) {
        const params = new URLSearchParams({ origin, tag, ...extra });
        const r = await request(`/feeds/${kind}.xml?${params}`, 'GET', authorization);
        const doc = new DOMParser().parseFromString(r.text, 'application/xml');
        return { ...r, valid: !doc.querySelector('parsererror'),
          titles: [...doc.querySelectorAll('item > title')].map(e => e.textContent),
          guids: [...doc.querySelectorAll('item > guid')].map(e => e.textContent),
          descriptions: [...doc.querySelectorAll('item > description')].map(e => e.textContent),
          next: [...doc.getElementsByTagNameNS('http://www.w3.org/2005/Atom', 'link')].find(e => e.getAttribute('rel') === 'next')?.getAttribute('href'),
          self: doc.querySelector('channel > link')?.textContent };
      }
      const all = await feed('all');
      const unread = await feed('unread');
      const archive = await feed('archive');
      const starred = await feed('starred');
      const bearer = await feed('all', {}, 'Bearer ' + credentials.token);
      const first = await feed('all', { limit: '1' });
      const unrecognized = await feed('all', { limit: '1', access_token: 'DO-NOT-REFLECT-THIS' });
      const second = await feed('all', { limit: '1', page: '2' });
      const empty = await feed('all', { tag: tag + '-missing' });
      const statuses = {};
      for (const path of ['/api/entries.json', '/api/version', '/', '/config', '/sandstorm/feeds', '/assets/images/example.png']) {
        statuses[path] = (await request(path)).status;
      }
      statuses.postFeed = (await request('/feeds/all.xml', 'POST')).status;
      statuses.postApi = (await request('/api/entries.json', 'POST')).status;
      statuses.invalidLimit = (await request('/feeds/all.xml?limit=101')).status;
      statuses.invalidPage = (await request('/feeds/all.xml?page=-1')).status;
      statuses.invalidOrigin = (await request('/feeds/all.xml?origin=javascript:alert(1)')).status;
      for (const parameter of ['tag', 'origin', 'limit', 'page']) {
        statuses['invalidArray_' + parameter] = (await request(`/feeds/all.xml?${parameter}[]=value`)).status;
      }
      const head = await request('/feeds/all.xml', 'HEAD');
      return { all, unread, archive, starred, bearer, first, second, empty, unrecognized, statuses, head,
        leakedToken: all.text.includes(credentials.token) };
    }, { credentials, tag: tagSlug });
    result.statuses.noAuth = (await externalRequest(credentials.feedUrl)).status();
    const spoof = await externalRequest(new URL(credentials.feedUrl).origin + '/api/entries.json', {
      headers: { Authorization: 'Basic ' + Buffer.from('sandstorm:' + credentials.token).toString('base64'), 'X-Sandstorm-Permissions': 'access' }
    });
    assert.equal(spoof.status(), 403, 'Spoofed permission header');
    const combined = new URL(credentials.feedUrl);
    combined.username = 'sandstorm'; combined.password = credentials.token;
    try { assert.equal((await externalRequest(combined.href)).status(), 200); }
    catch (_) { throw new Error('Subscription URL with embedded Basic credentials failed'); }
    for (const name of ['all', 'unread', 'archive', 'starred', 'bearer', 'first', 'second', 'empty']) {
      assert.equal(result[name].status, 200, name);
      assert.ok(result[name].valid, `${name}: invalid XML`);
      assert.match(result[name].type, /^application\/rss\+xml/);
    }
    assert.equal(result.all.guids.length, 2);
    assert.deepEqual(result.unread.titles, ['RSS & <XML> ]]> unread']);
    assert.deepEqual(result.archive.titles, ['RSS & <XML> ]]> archive']);
    assert.deepEqual(result.starred.guids, result.unread.guids);
    assert.deepEqual(result.bearer.guids, result.all.guids);
    assert.equal(result.first.guids.length, 1); assert.equal(result.second.guids.length, 1);
    assert.notEqual(result.first.guids[0], result.second.guids[0]);
    assert.ok(result.first.next.startsWith(new URL(credentials.feedUrl).origin + '/feeds/all.xml?'));
    assert.ok(result.all.self.startsWith(new URL(credentials.feedUrl).origin));
    assert.equal(result.empty.guids.length, 0);
    assert.ok(result.all.descriptions.every(html => html.includes('RSS captured text')));
    assert.equal(result.leakedToken, false);
    assert.equal(result.unrecognized.status, 200);
    assert.equal(result.unrecognized.text.includes('DO-NOT-REFLECT-THIS'), false);
    for (const [name, status] of Object.entries(result.statuses)) {
      if (name.startsWith('invalid')) assert.equal(status, 400, name);
      else if (name === 'noAuth') assert.ok([401, 403].includes(status), name);
      else assert.equal(status, 403, name);
    }
    assert.equal(result.head.status, 200); assert.equal(result.head.text, '');
    console.log('PASS: RSS XML, full text, filters, tags, pagination, stable IDs, Basic/Bearer auth, HEAD, feed-only isolation, validation, and no token disclosure');
  } finally {
    if (frame) for (const id of ids) {
      await frame.evaluate(id => fetch(`/api/entries/${id}.json`, { method: 'DELETE' }), id).catch(() => {});
    }
    await browser.close();
  }
})().catch(e => { console.error(e); process.exitCode = 1; });
