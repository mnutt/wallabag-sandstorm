// Optional before/after check for CSS refactors. No server or library mutations.
// Supply THEME_BASELINE_CSS when running theme.cjs; the candidate defaults to warm.css.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const baseline = fs.readFileSync(process.env.THEME_BASELINE_CSS, 'utf8');
const candidate = fs.readFileSync(
  process.env.THEME_CANDIDATE_CSS || path.join(__dirname, '../integration/warm.css'), 'utf8');

module.exports = async function compareStyles(frame, name) {
  await frame.evaluate(() => document.fonts.ready);
  const result = await frame.evaluate(({ baseline, candidate }) => {
    const links = [...document.querySelectorAll('link[rel="stylesheet"]')]
      .filter(link => new URL(link.href).pathname.endsWith('/custom.css'));
    if (!links.length) throw new Error('The theme stylesheet is missing.');
    const disabled = links.map(link => link.disabled);
    const theme = document.createElement('style');
    const freeze = document.createElement('style');
    // Compare settled appearance. theme.cjs separately tests hover and tooltip timing.
    freeze.textContent = '*, *::before, *::after { transition: none !important; animation: none !important; }';
    document.head.append(theme, freeze);
    links.forEach(link => { link.disabled = true; });
    try {
      const elements = [...document.querySelectorAll('body, body *')]
        .filter(el => !el.matches('script, style, link'));
      const pseudos = [null, '::before', '::after'];
      const properties = [...getComputedStyle(document.body)].filter(name => !name.startsWith('--'));
      const snapshot = () => elements.map(el => {
        const rect = el.getBoundingClientRect();
        return {
          bounds: [rect.x, rect.y, rect.width, rect.height],
          styles: pseudos.map(pseudo => {
            const style = getComputedStyle(el, pseudo);
            return properties.map(property => style.getPropertyValue(property));
          }),
        };
      });
      theme.textContent = baseline;
      const before = snapshot();
      theme.textContent = candidate;
      const after = snapshot();
      const differences = [];
      let changed = 0;
      const record = difference => {
        changed++;
        if (differences.length < 20) differences.push(difference);
      };
      elements.forEach((el, index) => {
        const selector = `${el.tagName.toLowerCase()}${el.id ? `#${el.id}` : ''}.${[...el.classList].join('.')}`;
        if (before[index].bounds.some((value, side) => Math.abs(value - after[index].bounds[side]) > .01)) {
          record({ selector, property: 'bounds', before: before[index].bounds, after: after[index].bounds });
        }
        pseudos.forEach((pseudo, p) => properties.forEach((property, i) => {
          const oldValue = before[index].styles[p][i];
          const newValue = after[index].styles[p][i];
          if (oldValue !== newValue) record({ selector, pseudo, property, before: oldValue, after: newValue });
        }));
      });
      return { elements: elements.length, changed, differences };
    } finally {
      theme.remove();
      freeze.remove();
      links.forEach((link, index) => { link.disabled = disabled[index]; });
    }
  }, { baseline, candidate });
  assert.equal(result.changed, 0, `${name}: computed styles changed:\n${JSON.stringify(result.differences, null, 2)}`);
  console.log(`UNCHANGED: ${name} (${result.elements} elements, including pseudo-elements).`);
};
