/* Original line drawings. Keep ligature text intact for wallabag's JS actions.
 * Unknown icons retain their upstream glyph rather than losing a control.
 */
(() => {
  'use strict';
  const paths = {
    add: 'M12 5v14M5 12h14',
    clear: 'm6 6 12 12M6 18 18 6',
    done: 'm4 12 5 5L20 6',
    arrow_back: 'm12 5-7 7 7 7M5 12h15',
    search: 'M16 10a6 6 0 1 1-12 0 6 6 0 0 1 12 0Zm-1 5 6 6',
    menu: 'M4 6h16M4 12h16M4 18h16',
    filter_list: 'M4 6h16M7 12h10M10 18h4',
    file_download: 'M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5',
    import_export: 'M7 3v16m-4-4 4 4 4-4M17 21V5m-4 4 4-4 4 4',
    account_circle: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-2a8 8 0 0 1 16 0v2',
    star: 'm12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9Z',
    delete: 'M3 6h18M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7M14 10v7',
    label: 'M3 4h8l10 10-7 7L3 10Zm4 3h.01',
    timer: 'M20 13a8 8 0 1 1-16 0 8 8 0 0 1 16 0ZM9 2h6M12 5V2m0 6v5l3 2',
    today: 'M4 5h16v16H4ZM8 3v4M16 3v4M4 10h16',
    comment: 'M3 4h18v13H9l-6 4ZM7 8h10M7 12h7',
    more_vert: 'M12 5h.01M12 12h.01M12 19h.01',
    launch: 'M14 3h7v7m0-7L10 14M10 3H3v18h18v-7',
    link: 'm10 14 4-4m-6 6-2 2a3 3 0 0 1-4-4l5-5a3 3 0 0 1 4 0m2 6a3 3 0 0 0 4 0l5-5a3 3 0 0 0-4-4l-2 2',
    refresh: 'M20 10a8 8 0 1 0 0 5M20 4v6h-6',
    create: 'm4 16-1 5 5-1L21 7l-4-4ZM14 6l4 4',
    view_list: 'M8 5h13M8 12h13M8 19h13M3 5h.01M3 12h.01M3 19h.01',
    view_module: 'M3 3h7v7H3ZM14 3h7v7h-7ZM3 14h7v7H3ZM14 14h7v7h-7Z',
    library_add_check: 'M4 3h16v18H4Zm4 9 3 3 5-6',
    brightness_high: 'M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM12 2v2m0 16v2M2 12h2m16 0h2M5 5l1 1m12 12 1 1M5 19l1-1M18 6l1-1',
    brightness_low: 'M20 15A9 9 0 0 1 9 3a9 9 0 1 0 11 12Z',
    brightness_auto: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM12 3v18',
    casino: 'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2ZM7 7h.01M17 7h.01M12 12h.01M7 17h.01M17 17h.01',
    rss_feed: 'M4 11a9 9 0 0 1 9 9M4 4a16 16 0 0 1 16 16M4 20h.01',
    language: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM3 12h18M12 3c-5 5-5 13 0 18 5-5 5-13 0-18Z',
    print: 'M6 8V3h12v5M6 17H3V8h18v9h-3M6 14h12v7H6Z',
    share: 'm8 11 8-5M8 13l8 5M8 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0ZM22 4a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm0 16a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    mail: 'M3 5h18v14H3Zm0 0 9 8 9-8',
    error: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM12 6v7m0 4h.01',
    send: 'm3 3 18 9-18 9 3-9Zm3 9h15',
    vpn_key: 'M11 8a5 5 0 1 1-10 0 5 5 0 0 1 10 0Zm-1 3 11 10m-4-4 3-3m-6 0 3-3',
  };
  const aliases = { close: 'clear', label_outline: 'label', mode_edit: 'create', person: 'account_circle', brightness_medium: 'brightness_auto', star_border: 'star', star_outline: 'star', archive: 'file_download' };
  paths.unarchive = 'M12 16V4m-5 5 5-5 5 5M4 16v5h16v-5';
  for (const [alias, original] of Object.entries(aliases)) paths[alias] = paths[original];
  const icons = Object.fromEntries(Object.entries(paths).map(([name, path]) => [
    name, `url("data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="${name === 'star' ? 'black' : 'none'}" stroke="black" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="${path}"/></svg>`)}")`,
  ]));
  function decorate(icon) {
    const name = icon.textContent.trim();
    if (icons[name]) {
      icon.dataset.warmIcon = name;
      icon.style.setProperty('--warm-icon', icons[name]);
    } else {
      delete icon.dataset.warmIcon;
      icon.style.removeProperty('--warm-icon');
    }
    const control = icon.closest('a, button');
    if (control && !control.hasAttribute('aria-label')) {
      const label = control.dataset.tooltip || control.title;
      if (label) control.setAttribute('aria-label', label);
    }
  }
  function scan(node) {
    if (node.nodeType !== Node.ELEMENT_NODE) return;
    if (node.matches('.material-icons')) decorate(node);
    node.querySelectorAll('.material-icons').forEach(decorate);
  }
  scan(document.body);
  const footer = document.querySelector('footer.page-footer');
  const footerRow = footer?.querySelector('.footer-copyright .row');
  if (footerRow) {
    // Measure the actual translated text, rather than guessing a viewport cutoff.
    const updateFooter = () => {
      const columns = [...footerRow.children];
      const gap = parseFloat(getComputedStyle(footerRow).columnGap) || 0;
      const requiredWidth = columns.reduce((width, column) => width + column.getBoundingClientRect().width, 0)
        + gap * Math.max(0, columns.length - 1);
      footer.toggleAttribute('data-warm-collapsed', requiredWidth > footerRow.clientWidth);
    };
    const resizeObserver = new ResizeObserver(updateFooter);
    resizeObserver.observe(footerRow);
    [...footerRow.children].forEach(column => resizeObserver.observe(column));
    updateFooter();
  }
  // Materialize waits 225ms, then animates for another 225ms before hiding.
  // Hide synchronously on a real control exit; its next hover still owns
  // positioning, the entrance delay, and restoring visibility.
  document.addEventListener('mouseleave', event => {
    if (!(event.target instanceof Element) || !event.target.matches('[data-tooltip-id]')) return;
    const tooltip = document.getElementById(event.target.dataset.tooltipId);
    if (tooltip) tooltip.style.visibility = 'hidden';
  }, true);
  new MutationObserver(records => {
    for (const record of records) {
      const target = record.target.nodeType === Node.TEXT_NODE ? record.target.parentElement : record.target;
      if (target?.matches('.material-icons')) decorate(target);
      record.addedNodes.forEach(scan);
    }
  }).observe(document.body, { childList: true, subtree: true, characterData: true });
})();
