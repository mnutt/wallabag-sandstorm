// Sandstorm's outer URL must follow navigation inside the app iframe.
(() => {
    const syncPath = () => window.parent.postMessage({
        setPath: location.pathname + location.search + location.hash,
    }, '*');
    syncPath();
    window.addEventListener('pageshow', syncPath);
    window.addEventListener('hashchange', syncPath);
    window.addEventListener('popstate', syncPath);
    for (const name of ['pushState', 'replaceState']) {
        const original = history[name];
        history[name] = function (...args) {
            const result = original.apply(this, args);
            syncPath();
            return result;
        };
    }
})();

// The native controls still apply the theme immediately. Serialize saves so a
// quick sequence of clicks cannot persist an older choice after a newer one.
const themeToken = document.querySelector('meta[name="sandstorm-theme-token"]');
if (themeToken) {
    let pendingThemeSave = Promise.resolve();
    document.addEventListener('click', event => {
        const control = event.target.closest('.js-theme-toggle[data-theme]');
        if (!control) return;
        const theme = control.dataset.theme;
        pendingThemeSave = pendingThemeSave.then(async () => {
            const response = await fetch('/sandstorm/theme', {
                method: 'POST', keepalive: true,
                body: new URLSearchParams({theme, _token: themeToken.content}),
            });
            if (!response.ok) throw new Error('Theme preference could not be saved.');
            document.querySelector('#sandstorm-theme-error')?.remove();
        }).catch(() => {
            if (document.querySelector('#sandstorm-theme-error')) return;
            const status = document.createElement('p');
            status.id = 'sandstorm-theme-error';
            status.setAttribute('role', 'alert');
            status.textContent = 'Your theme changed, but could not be saved. Please choose it again to retry.';
            document.querySelector('#content').prepend(status);
        });
    });
}

// Keep the current document (and Powerbox websocket) alive while saving a URL.
document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!form.matches('form[name="entry"]') || !form.querySelector('[name="entry[url]"]') ||
        new URL(form.action).pathname !== '/new-entry') return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (form.dataset.saving) return;
    form.dataset.saving = 'true';
    const button = form.querySelector('[type="submit"]');
    if (button) button.disabled = true;
    const status = document.createElement('p');
    status.setAttribute('role', 'status');
    status.textContent = 'Saving article… Approve website access if Sandstorm asks.';
    document.querySelector('#content').prepend(status);
    try {
        const response = await fetch(form.action, {method: 'POST', body: new FormData(form)});
        if (!response.ok) throw new Error(`Saving failed (${response.status}).`);
        // Upstream renders form validation errors with HTTP 200. Navigating to
        // that response URL would discard both the error and the entered URL.
        if (!response.redirected) {
            // Read only the form's error text; never insert response HTML into
            // the live document. In particular, distinguish CSRF/session errors
            // from invalid URLs rather than telling everyone to check the URL.
            const result = new DOMParser().parseFromString(await response.text(), 'text/html');
            const returnedForm = result.querySelector('form[name="entry"]');
            const errors = returnedForm ? Array.from(returnedForm.querySelectorAll('.black-text li'),
                error => error.textContent.trim()).filter(Boolean) : [];
            // A rejected form can carry a fresh token after the session expires.
            // Preserve the user's URL and allow an explicit retry with that token.
            const token = form.querySelector('input[name="entry[_token]"]');
            const freshToken = returnedForm?.querySelector('input[name="entry[_token]"]');
            if (token && freshToken) token.value = freshToken.value;
            throw new Error(errors.length ? errors.join(' ') : 'Article could not be saved. Reload the page and try again.');
        }
        window.location.assign(response.url);
    } catch (error) {
        status.textContent = error.message + ' Please try again.';
        delete form.dataset.saving;
        if (button) button.disabled = false;
    }
}, true);

// External-reader setup is available to library collaborators.
const accountMenu = document.querySelector('#dropdown-account');
if (accountMenu) {
    for (const [path, label, glyph] of [
        ['/sandstorm/extensions', 'Browser extensions', 'extension'],
        ['/sandstorm/feeds', 'RSS subscriptions', 'rss_feed'],
    ]) {
        const item = document.createElement('li');
        const link = document.createElement('a');
        link.href = path;
        const icon = document.createElement('i');
        icon.className = 'material-icons';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = glyph;
        link.append(icon, document.createTextNode(label));
        item.append(link);
        accountMenu.prepend(item);
    }
}
