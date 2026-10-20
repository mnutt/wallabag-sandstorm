// Relay only replies to outstanding Powerbox requests from our parent shell.
(() => {
    const pending = new Set();
    let socket;
    function connect() {
        const url = new URL('/_sandstorm/websocket', window.location.href);
        url.protocol = url.protocol === 'https:' ? 'wss:' : 'ws:';
        socket = new WebSocket(url);
        socket.onmessage = ({data}) => {
            const message = JSON.parse(data);
            if (!message.powerboxRequest) return;
            pending.add(message.powerboxRequest.rpcId);
            window.parent.postMessage(message, '*');
        };
        socket.onclose = () => {
            pending.clear();
            setTimeout(connect, 1000);
        };
    }
    window.addEventListener('message', (event) => {
        if (event.source !== window.parent || !event.data ||
            !pending.has(event.data.rpcId) || socket.readyState !== WebSocket.OPEN) return;
        pending.delete(event.data.rpcId);
        socket.send(JSON.stringify(event.data));
    });
    connect();
})();
