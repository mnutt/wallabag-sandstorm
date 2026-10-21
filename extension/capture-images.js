// Fetch only images selected from the saved tab. No API token or page cookies are attached.
export async function capturePageImages(event) {
    if (!Array.isArray(event.images) || typeof event.content !== 'string') return event.content;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 12000);
    let remaining = 12 * 1024 * 1024;
    let next = 0;
    let content = event.content;
    const escapeAttribute = value => value.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');
    const tasks = event.images.slice(0, 32);
    async function worker() {
        while (next < tasks.length) {
            const {placeholder, url} = tasks[next++];
            if (typeof placeholder !== 'string' || !/^https:\/\/sandstorm-image\.invalid\/[a-f0-9-]+\/\d+\.image$/.test(placeholder)) continue;
            let replacement = 'data:,';
            try {
                const parsed = new URL(url);
                if (!['https:', 'http:'].includes(parsed.protocol) || parsed.username || parsed.password || remaining <= 0 || controller.signal.aborted) throw Error('Skip image');
                const response = await fetch(url, {credentials:'omit', redirect:'error', referrerPolicy:'no-referrer', signal:controller.signal});
                if (!response.ok || !response.body) throw Error('Image unavailable');
                const type = (response.headers.get('content-type') || '').split(';')[0].trim().toLowerCase();
                if (!/^image\/(png|jpeg|gif|webp|avif|svg\+xml)$/.test(type) || Number(response.headers.get('content-length')) > 3 * 1024 * 1024) {
                    await response.body.cancel(); throw Error('Unsupported image');
                }
                const reader = response.body.getReader();
                const chunks = [];
                let size = 0;
                try {
                    while (true) {
                        const {done, value} = await reader.read();
                        if (done) break;
                        size += value.length;
                        remaining -= value.length;
                        if (size > 3 * 1024 * 1024 || remaining < 0) throw Error('Image budget exceeded');
                        chunks.push(value);
                    }
                } finally { await reader.cancel(); }
                const bytes = new Uint8Array(size);
                let offset = 0;
                for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.length; }
                let binary = '';
                for (let i = 0; i < bytes.length; i += 16384) binary += String.fromCharCode(...bytes.subarray(i, i + 16384));
                replacement = `data:${type};base64,${btoa(binary)}`;
            } catch (_) { /* Leave an inert image when browser access or fetching fails. */ }
            content = content.split(placeholder).join(escapeAttribute(replacement));
        }
    }
    try { await Promise.all([worker(), worker(), worker()]); return content; }
    finally { clearTimeout(timer); }
}
