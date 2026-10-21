import { browser } from './browser-polyfill.js';
import { WallabagApi } from './wallabag-api.js';
import { Logger } from './utils/logger.js';

const status = document.querySelector('#status');
const stored = (await browser.storage.local.get('wallabagdata')).wallabagdata;
if (stored?.Sandstorm) {
    document.querySelector('#grain').value = stored.GrainUrl || '';
    status.textContent = `Connected to ${stored.Url}. Paste a new webkey to reconnect.`;
}
function safeUrl(value) {
    const url = new URL(value);
    if (!['https:', 'http:'].includes(url.protocol) || url.username || url.password) {
        throw new Error('Use an HTTP or HTTPS address without a username or password.');
    }
    if (url.protocol === 'http:' && !['localhost', '127.0.0.1', 'local.sandstorm.io'].includes(url.hostname) && !url.hostname.endsWith('.local.sandstorm.io')) {
        throw new Error('Use HTTPS for your Sandstorm server. HTTP is allowed only for local development.');
    }
    return url;
}
let connecting = false;
document.querySelector('#connect').addEventListener('submit', async event => {
    event.preventDefault();
    if (connecting) return;
    connecting = true;
    const button = event.target.querySelector('button');
    button.disabled = true;
    try {
        const webkey = safeUrl(document.querySelector('#webkey').value.trim());
        const token = webkey.hash.slice(1);
        if (!token || webkey.search || webkey.pathname !== '/') throw new Error('Paste the complete API webkey, including its #token.');
        const grain = safeUrl(document.querySelector('#grain').value.trim());
        // Sandstorm's address bar may currently point to an article or filter.
        const match = grain.pathname.match(/^\/grain\/([A-Za-z0-9_-]+)(?:\/|$)/);
        if (!match) throw new Error('Use this library’s Sandstorm address containing /grain/ID.');
        const grainUrl = grain.origin + '/grain/' + match[1];
        // Request only this API host, inside the user gesture.
        const granted = await browser.permissions.request({ origins: [webkey.origin + '/*'] });
        if (!granted) throw new Error('Access to this API host is required to connect.');
        status.textContent = 'Checking connection…';
        const api = new WallabagApi(new Logger('sandstorm-setup'));
        await api.init();
        Object.assign(api.data, api.defaultValues, {
            Sandstorm: true, Url: webkey.origin, ApiToken: token,
            GrainUrl: grainUrl, FetchLocallyByDefault: true,
            isTokenExpired: false, isFetchPermissionGranted: true
        });
        await api.checkUrl();
        await api.getTags(); // Verify authenticated access, not just a version response.
        await browser.storage.local.set({ wallabagdata: api.data });
        const port = browser.runtime.connect({ name: 'setup' });
        port.onMessage.addListener(message => {
            if (message.response === 'setup-save') port.disconnect();
        });
        port.postMessage({ request: 'setup-save', data: api.data });
        document.querySelector('#webkey').value = '';
        status.textContent = `Connected to wallabag ${api.data.ApiVersion}. Open an article and click Wallabagger to save it.`;
    } catch (error) {
        status.textContent = error.message;
    } finally {
        connecting = false;
        button.disabled = false;
    }
});

document.querySelector('#image-access').addEventListener('click', async () => {
    try {
        const granted = await browser.permissions.request({origins:['http://*/*', 'https://*/*']});
        status.textContent = granted ? 'Image downloads from other sites are enabled.' : 'Image access was not granted. Pages will still save.';
    } catch (error) { status.textContent = error.message; }
});
