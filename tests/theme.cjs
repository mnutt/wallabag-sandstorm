// Read-only UI checks, except switching list/grid view and restoring it afterwards.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
  assert.ok(process.env.GRAIN_URL && process.env.SANDSTORM_STORAGE_STATE,
    'Set GRAIN_URL and SANDSTORM_STORAGE_STATE. Use a grain with an unread article.');
  const browser = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true,
    args: ['--no-sandbox', '--host-resolver-rules=MAP *.local.sandstorm.io 127.0.0.1, MAP local.sandstorm.io 127.0.0.1'],
  });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    storageState: process.env.SANDSTORM_STORAGE_STATE,
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  let frame, originalListMode, switched = false;
  const screenshot = async name => {
    if (!process.env.SCREENSHOT_DIR) return;
    fs.mkdirSync(process.env.SCREENSHOT_DIR, { recursive: true });
    await frame.locator('body').screenshot({ path: `${process.env.SCREENSHOT_DIR}/${name}.png` });
  };
  const toggleTheme = async theme => {
    await frame.locator('#news_menu').click();
    await frame.locator(`.js-theme-toggle[data-theme="${theme}"]`).click();
    // Allow upstream menu/color transitions to finish before screenshots.
    await page.waitForTimeout(400);
  };
  const checkPaper = async expected => {
    await frame.waitForFunction(color => getComputedStyle(document.body).backgroundColor === color, expected);
    assert.ok(await frame.locator('.entries .chip a, .entries .card-entry-labels a').evaluateAll(tags =>
      tags.every(tag => getComputedStyle(tag).color === getComputedStyle(document.body).color)),
    'Entry tag text should use the current theme’s ink color.');
  };
  const checkHeaderFields = async () => {
    for (const [action, type] of [['add', 'url'], ['search', 'text']]) {
      await frame.locator(`#nav-btn-${action}`).click();
      const input = frame.locator(`.nav-panel-${action} input[type="${type}"]`);
      await input.focus();
      for (const focused of [true, false]) {
        if (!focused) await input.blur();
        assert.deepEqual(await input.evaluate(el => {
          const style = getComputedStyle(el);
          return {
            borders: ['Top', 'Right', 'Bottom', 'Left'].map(side => [style[`border${side}Width`], style[`border${side}Style`]]),
            shadow: style.boxShadow,
          };
        }), { borders: Array(4).fill(['1px', 'solid']), shadow: 'none' });
      }
      await frame.locator(`.nav-panel-${action} .close`).click();
    }
  };
  const checkActionAlignment = async () => {
    assert.ok(await frame.locator('.card-action .tools, .card-stacked .tools-list li').evaluateAll(rows =>
      rows.every(row => {
        const centers = [...row.querySelectorAll('.material-icons')].map(icon => icon.getBoundingClientRect())
          .filter(rect => rect.width && rect.height).map(rect => rect.y + rect.height / 2);
        return centers.length < 2 || Math.max(...centers) - Math.min(...centers) < 1;
      })), 'Entry action icons must share a vertical center.');
  };
  const checkArticleTags = async () => assert.ok(await frame.locator('#article .entry-info .chip').evaluateAll(chips =>
    chips.every(chip => {
      const button = chip.querySelector('.chip-action');
      if (!button) return true;
      const label = chip.querySelector('.chip-label').getBoundingClientRect();
      const action = button.getBoundingClientRect();
      const icon = button.querySelector('.material-icons').getBoundingClientRect();
      return Math.abs(label.top - action.top) < 1 && Math.abs(label.height - action.height) < 1
        && Math.abs((action.top + action.bottom - icon.top - icon.bottom) / 2) < 1;
    })), 'Article tag delete buttons must match the label height and center their icons.');
  const switchView = async () => {
    await Promise.all([
      frame.waitForNavigation(),
      frame.locator('form[action*="/config/view-mode"]').getByRole('button').click(),
    ]);
  };
  try {
    await page.goto(process.env.GRAIN_URL);
    await page.frameLocator('iframe').first().locator('#content').waitFor({ timeout: 120000 });
    frame = page.frames().find(f => f.url().includes('ui-'));
    assert.ok(frame);
    await frame.locator('.entries [data-entry-id]').first().waitFor();
    assert.equal(await frame.locator('link[href$="custom.css"]').count(), 1);
    assert.deepEqual(await frame.evaluate(async () => [
      (await fetch('/config')).status,
      (await fetch('/config/view-mode')).status,
      (await fetch('/config/view-mode', { method: 'POST' })).status,
    ]), [403, 405, 400], 'Account configuration stays blocked and view switching requires POST and CSRF.');
    assert.equal(await frame.locator('#nav-btn-add i[data-warm-icon="add"]').count(), 1);
    assert.ok(await frame.locator('.nav-panel-buttom').evaluate(el => {
      const visible = [...el.children].filter(child => child.getBoundingClientRect().width > 0);
      return visible.every(child => Math.abs(child.getBoundingClientRect().top - visible[0].getBoundingClientRect().top) < 2);
    }), 'Toolbar actions should share one row.');
    assert.ok(await frame.locator('#nav-btn-add').evaluate(el => el.getBoundingClientRect().width <= 60),
      'The add button must not stretch into the available header space.');
    await frame.locator('#nav-btn-add').hover();
    assert.deepEqual(await frame.locator('#nav-btn-add').evaluate(el => ({
      transition: getComputedStyle(el).transitionDuration,
      highlighted: ['rgb(234, 229, 220)', 'rgb(48, 49, 42)'].includes(getComputedStyle(el).backgroundColor),
      radius: getComputedStyle(el).borderRadius,
    })), { transition: '0s', highlighted: true, radius: '3px' });
    const tooltipId = await frame.locator('#nav-btn-add').getAttribute('data-tooltip-id');
    const tooltip = frame.locator(`[id="${tooltipId}"]`);
    await tooltip.waitFor({ state: 'visible' });
    for (const delay of [0, 80, 160]) {
      if (delay) await page.waitForTimeout(delay);
      assert.deepEqual(await tooltip.evaluate(el => ({
        opacity: getComputedStyle(el).opacity,
        transform: getComputedStyle(el).transform,
        backdrop: getComputedStyle(el.querySelector('.backdrop')).display,
      })), { opacity: '1', transform: 'none', backdrop: 'none' });
    }
    await frame.locator('.nav-panel-top h1').hover();
    assert.equal(await tooltip.evaluate(el => getComputedStyle(el).visibility), 'hidden',
      'Tooltip must hide immediately, without waiting for Materialize’s exit timer.');
    await frame.locator('#nav-btn-add').hover();
    await tooltip.waitFor({ state: 'visible' });
    await frame.locator('.nav-panel-top h1').hover();
    assert.equal(await tooltip.evaluate(el => getComputedStyle(el).visibility), 'hidden');
    originalListMode = await frame.locator('.entries').evaluate(el => el.classList.contains('collection'));
    await checkActionAlignment();
    assert.ok(await frame.locator('footer.page-footer').isVisible());
    assert.ok(await frame.locator('footer .row').evaluate(row => {
      const bounds = row.getBoundingClientRect();
      const first = row.firstElementChild.getBoundingClientRect();
      const last = row.lastElementChild.getBoundingClientRect();
      return Math.abs(first.left - bounds.left) < 1 && Math.abs(last.right - bounds.right) < 1
        && first.right < last.left;
    }), 'Footer stats and credits should align to opposite edges without overlapping.');
    await toggleTheme('light');
    await checkPaper('rgb(250, 248, 244)');
    await checkHeaderFields();
    await screenshot('library-light');
    await toggleTheme('dark');
    await checkPaper('rgb(36, 36, 31)');
    await checkHeaderFields();
    await frame.goto(frame.url());
    await checkPaper('rgb(36, 36, 31)'); // Cookie survives a document navigation.
    await screenshot('library-dark');
    await toggleTheme('auto');
    await page.emulateMedia({ colorScheme: 'light' });
    await checkPaper('rgb(250, 248, 244)');
    await page.emulateMedia({ colorScheme: 'dark' });
    await checkPaper('rgb(36, 36, 31)');
    await switchView();
    switched = true;
    assert.notEqual(await frame.locator('.entries').evaluate(el => el.classList.contains('collection')), originalListMode);
    await checkActionAlignment();
    assert.ok(await frame.locator('.entries .card-title a, .entries a.card-title').first()
      .evaluate(el => getComputedStyle(el).fontFamily === getComputedStyle(document.body).fontFamily),
    'Library entry titles should use the UI sans serif.');
    await page.emulateMedia({ colorScheme: 'light' });
    await checkPaper('rgb(250, 248, 244)');
    await page.emulateMedia({ colorScheme: 'dark' });
    await checkPaper('rgb(36, 36, 31)');
    await screenshot('alternate-view');
    assert.ok(await frame.locator('.card-entry-labels li').evaluateAll(tags => tags.every(tag =>
      getComputedStyle(tag).borderTopLeftRadius === '0px' && getComputedStyle(tag).borderBottomLeftRadius === '0px')));
    await switchView();
    switched = false;
    await page.setViewportSize({ width: 390, height: 844 });
    await checkActionAlignment();
    await checkHeaderFields();
    await frame.waitForFunction(() => {
      const footer = document.querySelector('footer.page-footer');
      return footer.hasAttribute('data-warm-collapsed') && footer.getBoundingClientRect().height === 0;
    });
    await page.setViewportSize({ width: 1440, height: 1000 });
    await frame.locator('footer.page-footer').waitFor({ state: 'visible' });
    await page.setViewportSize({ width: 390, height: 844 });
    await screenshot('library-mobile');
    assert.ok(await frame.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await frame.locator('#nav-btn-search').click();
    assert.ok(await frame.locator('.nav-panel-search').isVisible());
    await frame.goto(new URL('/', frame.url()).href);
    await frame.locator('#button_filters a').click();
    await page.waitForTimeout(400);
    assert.ok(await frame.locator('#filters').isVisible());
    await screenshot('filters-mobile');
    await frame.goto(new URL('/', frame.url()).href);
    await frame.locator('.card-title a, a.card-title').first().click();
    await frame.locator('#article article').waitFor();
    await checkArticleTags();
    assert.ok(await frame.locator('#article article').evaluate(el => getComputedStyle(el).fontFamily.includes('Georgia')));
    assert.ok(await frame.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    const floatingMenu = frame.locator('.fixed-action-btn');
    const checkFloatingIcons = async () => assert.ok(await floatingMenu.locator('.btn-floating').evaluateAll(buttons =>
      buttons.filter(button => getComputedStyle(button).visibility !== 'hidden').every(button => {
        const control = button.getBoundingClientRect();
        const icon = button.querySelector('.material-icons').getBoundingClientRect();
        return Math.abs((control.top + control.bottom - icon.top - icon.bottom) / 2) < 1
          && Math.abs((control.left + control.right - icon.left - icon.right) / 2) < 1;
      })), 'Mobile reader menu icons should be centered in their buttons.');
    await checkFloatingIcons();
    await floatingMenu.locator('[data-toggle="actions"]').click();
    await page.waitForTimeout(400);
    await checkFloatingIcons();
    for (const [scheme, expected] of [['light', 'rgb(41, 41, 35)'], ['dark', 'rgb(238, 233, 223)']]) {
      await page.emulateMedia({ colorScheme: scheme });
      await frame.waitForFunction(dark => document.documentElement.classList.contains('dark-theme') === dark, scheme === 'dark');
      assert.ok(await frame.locator('.fixed-action-btn .material-icons:not([data-warm-icon="star"]), .js-entry-nav-top .material-icons:not([data-warm-icon="star"])')
        .evaluateAll((icons, color) => icons.length > 0 && icons.every(icon => getComputedStyle(icon).color === color), expected),
      `Mobile reader icons must use the ${scheme} theme's text color.`);
    }
    await screenshot('reader-mobile');
    await page.setViewportSize({ width: 1440, height: 1000 });
    await frame.locator('#article > header').hover();
    await checkArticleTags();
    const rail = frame.locator('.reader-mode');
    await frame.waitForFunction(() => document.querySelector('.reader-mode').getBoundingClientRect().width === 48);
    assert.ok(await rail.locator('.collapsible-header').evaluateAll(headers => headers.every(header => {
      const bounds = header.getBoundingClientRect();
      const icon = header.querySelector('.material-icons').getBoundingClientRect();
      return Math.abs((bounds.left + bounds.right - icon.left - icon.right) / 2) < 1;
    })), 'Collapsed reader sidebar icons should have equal horizontal padding.');
    const collapsedIconPositions = await rail.locator('.collapsible-header > .material-icons')
      .evaluateAll(icons => icons.map(icon => icon.getBoundingClientRect().x));
    await rail.hover();
    await frame.waitForFunction(() => document.querySelector('.reader-mode').getBoundingClientRect().width === 300);
    const expandedIconPositions = await rail.locator('.collapsible-header > .material-icons')
      .evaluateAll(icons => icons.map(icon => icon.getBoundingClientRect().x));
    assert.deepEqual(expandedIconPositions, collapsedIconPositions, 'Icons must stay in place as the sidebar expands.');
    assert.ok(await rail.locator('.collapsible-header > span').evaluateAll(labels =>
      labels.every(label => label.scrollWidth <= label.clientWidth && label.getClientRects().length === 1)),
    'Expanded sidebar labels should fit on one line without clipping.');
    const exportHeader = rail.locator('.collapsible-header').filter({ has: frame.locator('[data-warm-icon="file_download"]') });
    await exportHeader.click();
    const exportOptions = exportHeader.locator('..').locator('.collapsible-body');
    await exportOptions.waitFor({ state: 'visible' });
    await frame.locator('#article > header').hover();
    await exportOptions.waitFor({ state: 'hidden' });
    await rail.hover();
    await exportOptions.waitFor({ state: 'visible' });
    await frame.locator('#article > header').hover();
    await screenshot('reader-dark');
    assert.deepEqual(errors, []);
    console.log('PASS: packaged assets, light/dark/automatic themes, persistence, grid/list, mobile search/filters, reader, and no JS errors.');
  } finally {
    if (switched && frame) {
      await frame.goto(new URL('/', frame.url()).href);
      await switchView();
    }
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
