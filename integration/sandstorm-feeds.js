(() => {
    const button = document.querySelector('#feed-connect');
    const status = document.querySelector('#feed-status');
    const frame = document.querySelector('#feed-credentials');
    let pending;
    window.addEventListener('message', event => {
        if (event.source !== window.parent || !pending || event.data?.rpcId !== pending) return;
        pending = null;
        button.disabled = false;
        if (event.data.error) { status.textContent = event.data.error; return; }
        if (typeof event.data.uri !== 'string') return;
        const uri = new URL(event.data.uri);
        if (!['https:', 'http:'].includes(uri.protocol)) return;
        frame.src = uri.href;
        frame.hidden = false;
        status.textContent = 'Copy these credentials into your RSS reader.';
    });
    button.addEventListener('click', () => {
        const kind = document.querySelector('#feed-kind').value;
        const tag = document.querySelector('#feed-tag').value.trim();
        const path = `/feeds/${kind}.xml?origin=${encodeURIComponent(location.protocol + '//')}$API_HOST` +
            (tag ? `&tag=${encodeURIComponent(tag)}` : '');
        pending = 'wallabag-feed-' + Date.now() + '-' + Math.random().toString(36).slice(2);
        button.disabled = true;
        status.textContent = 'Creating feed-only credentials…';
        window.parent.postMessage({ renderTemplate: {
            rpcId: pending,
            roleAssignment: { roleId: 1 },
            petname: 'RSS reader — ' + kind + (tag ? ' — ' + tag : ''),
            clipboardButton: 'left',
            template: `Feed URL: ${location.protocol}//$API_HOST${path}\nUsername: sandstorm\nPassword: $API_TOKEN\n\nCombined subscription URL:\n${location.protocol}//sandstorm:$API_TOKEN@$API_HOST${path}`,
        } }, '*');
    });
})();
