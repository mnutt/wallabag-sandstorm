(() => {
    const button = document.querySelector('#extension-connect');
    const status = document.querySelector('#extension-status');
    const frame = document.querySelector('#extension-credentials');
    let pending;
    window.addEventListener('message', event => {
        if (event.source !== window.parent || !pending || event.data?.rpcId !== pending) return;
        pending = null;
        button.disabled = false;
        if (event.data.error) { status.textContent = event.data.error; return; }
        try {
            const uri = new URL(event.data.uri);
            if (!['https:', 'http:'].includes(uri.protocol) || uri.username || uri.password) throw new Error();
            frame.src = uri.href;
            frame.hidden = false;
            status.textContent = 'Copy the API webkey into Wallabagger’s Options.';
        } catch (_) {
            status.textContent = 'Sandstorm returned an invalid credentials URL. Please try again.';
        }
    });
    button.addEventListener('click', () => {
        pending = 'wallabag-extension-' + Date.now() + '-' + Math.random().toString(36).slice(2);
        button.disabled = true;
        frame.hidden = true;
        frame.removeAttribute('src');
        status.textContent = 'Creating extension credentials…';
        window.parent.postMessage({ renderTemplate: {
            rpcId: pending,
            roleAssignment: { roleId: 0 },
            petname: 'Wallabagger browser extension',
            clipboardButton: 'left',
            template: `${location.protocol}//$API_HOST/#$API_TOKEN`,
        } }, '*');
    });
})();
