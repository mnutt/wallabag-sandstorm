(() => {
    const status = document.querySelector('#network-status');
    const connect = document.querySelector('#network-authorize');
    const disconnect = document.querySelector('#network-disconnect');
    const automatic = document.querySelector('#network-auto-disconnect');
    let descriptor;
    let configured = false;
    let pending;
    let working = false;
    const busy = value => {
        working = value;
        connect.disabled = disconnect.disabled = automatic.disabled = value;
    };
    async function request(method = 'GET', body) {
        const response = await fetch('/_sandstorm/network', {
            method, headers: {'X-Sandstorm-App-Network': '1', 'Content-Type': 'application/json'},
            body: body && JSON.stringify(body),
        });
        if (!response.ok) throw new Error(await response.text());
        return method === 'GET' ? response.json() : undefined;
    }
    async function refresh() {
        const state = await request();
        descriptor = state.descriptor;
        configured = state.configured;
        connect.style.display = configured ? 'none' : '';
        disconnect.style.display = configured ? '' : 'none';
        disconnect.hidden = !configured;
        if (configured) automatic.checked = state.autoDisconnect;
        status.textContent = configured
            ? (state.valid
                ? (state.autoDisconnect ? 'Connected. Access will be removed when the current or next import finishes.'
                    : 'Connected. Disconnect here when you no longer need unrestricted internet access.')
                : 'The network grant is unavailable or revoked. Disconnect it to return to per-site approvals.')
            : 'Disconnected. New websites require individual approval.';
    }
    connect.addEventListener('click', () => {
        busy(true);
        pending = 'wallabag-network-' + Array.from(crypto.getRandomValues(new Uint32Array(4)), n => n.toString(16)).join('-');
        status.textContent = 'Choose an IP network in Sandstorm.';
        window.parent.postMessage({powerboxRequest: {
            rpcId: pending, query: [descriptor],
            saveLabel: {defaultText: 'wallabag: unrestricted internet access'},
        }}, '*');
    });
    window.addEventListener('message', async event => {
        if (event.source !== window.parent || !pending || event.data?.rpcId !== pending) return;
        pending = undefined;
        try {
            if (event.data.token) await request('POST', {token: event.data.token, autoDisconnect: automatic.checked});
            await refresh();
            if (!event.data.token) status.textContent = event.data.error || 'Connection cancelled. New websites still require approval.';
        } catch (error) { status.textContent = error.message; }
        finally { busy(false); }
    });
    disconnect.addEventListener('click', async () => {
        busy(true);
        try { await request('DELETE'); await refresh(); }
        catch (error) { status.textContent = error.message; }
        finally { busy(false); }
    });
    automatic.addEventListener('change', async () => {
        if (!configured) return;
        busy(true);
        try { await request('PATCH', {autoDisconnect: automatic.checked}); await refresh(); }
        catch (error) { automatic.checked = !automatic.checked; status.textContent = error.message; }
        finally { busy(false); }
    });
    busy(true);
    refresh().then(() => busy(false)).catch(error => { status.textContent = error.message; });
    // Completion happens in the worker, including with this page closed. Reflect
    // automatic revocation or a collaborator's settings changes while it is open.
    setInterval(async () => {
        if (working || document.hidden) return;
        busy(true);
        try { await refresh(); }
        catch (error) { status.textContent = error.message; }
        finally { busy(false); }
    }, 5000);
})();
