/*
 * Keeps the player working without a connection.
 *
 * - The page and its assets are kept in the "shell" cache, so the player opens offline.
 * - Chapters the player has downloaded live in the "audio" cache under
 *   /offline/audio/{chapter}, and are served from there (including the byte
 *   ranges the audio element asks for when seeking) whatever signature the
 *   request carries.
 * - Synced text is fetched from the network when possible and kept for offline use.
 */

const SHELL_CACHE = 'shell-v1';
const AUDIO_CACHE = 'audio-v1';
const TEXT_CACHE = 'text-v1';
const KEEP = [SHELL_CACHE, AUDIO_CACHE, TEXT_CACHE];
const NAVIGATION_TIMEOUT_MS = 4000;

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            for (const name of await caches.keys()) {
                if (!KEEP.includes(name)) {
                    await caches.delete(name);
                }
            }

            await self.clients.claim();
        })(),
    );
});

// The page sends the URLs it loaded so the shell can be saved on the first visit.
self.addEventListener('message', (event) => {
    if (event.data?.type === 'cache-shell') {
        event.waitUntil(cacheShell(event.data.urls));
    }
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    const audio = url.pathname.match(/^\/audio\/(\d+)$/);

    if (audio) {
        event.respondWith(audioResponse(request, audio[1]));
    } else if (/^\/text\/\d+$/.test(url.pathname)) {
        event.respondWith(networkFirst(request, TEXT_CACHE, url.pathname));
    } else if (request.mode === 'navigate' && url.pathname === '/') {
        event.respondWith(networkFirst(request, SHELL_CACHE, '/', NAVIGATION_TIMEOUT_MS));
    } else if (/^\/(build|images)\//.test(url.pathname) || /\.(webmanifest|png|ico)$/.test(url.pathname)) {
        event.respondWith(cacheFirst(request));
    }
});

async function cacheShell(urls) {
    const cache = await caches.open(SHELL_CACHE);

    await Promise.all(
        urls.map(async (url) => {
            const key = new URL(url, self.location.origin).pathname === '/' ? '/' : url;

            if (await cache.match(key)) {
                return;
            }

            try {
                const response = await fetch(url, { credentials: 'same-origin' });

                if (response.ok) {
                    await cache.put(key, response);
                }
            } catch {
                // Offline or blocked; it'll be cached the next time it loads.
            }
        }),
    );
}

async function cacheFirst(request) {
    const cache = await caches.open(SHELL_CACHE);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok) {
        await cache.put(request, response.clone());
    }

    return response;
}

async function networkFirst(request, cacheName, key, timeout = 0) {
    const cache = await caches.open(cacheName);

    try {
        const response = await withTimeout(fetch(request), timeout);

        if (response.ok) {
            await cache.put(key, response.clone());
        }

        return response;
    } catch (error) {
        const cached = await cache.match(key);

        if (cached) {
            return cached;
        }

        throw error;
    }
}

function withTimeout(promise, ms) {
    if (!ms) {
        return promise;
    }

    return Promise.race([
        promise,
        new Promise((resolve, reject) => setTimeout(() => reject(new Error('Timed out')), ms)),
    ]);
}

/**
 * Serve a downloaded chapter, answering Range requests with 206 responses so
 * the audio element can seek. Chapters that aren't downloaded go to the network.
 */
async function audioResponse(request, key) {
    const cached = await caches.match(`/offline/audio/${key}`, { cacheName: AUDIO_CACHE });

    if (!cached) {
        return fetch(request);
    }

    const blob = await cached.blob();
    const size = blob.size;
    const headers = {
        'Content-Type': cached.headers.get('Content-Type') || 'audio/mpeg',
        'Accept-Ranges': 'bytes',
    };
    const range = request.headers.get('Range');

    if (!range) {
        return new Response(blob, { status: 200, headers: { ...headers, 'Content-Length': String(size) } });
    }

    const bounds = parseRange(range, size);

    if (!bounds) {
        return new Response(null, { status: 416, headers: { ...headers, 'Content-Range': `bytes */${size}` } });
    }

    const [start, end] = bounds;

    return new Response(blob.slice(start, end + 1), {
        status: 206,
        headers: {
            ...headers,
            'Content-Length': String(end - start + 1),
            'Content-Range': `bytes ${start}-${end}/${size}`,
        },
    });
}

/**
 * Parse a single "bytes=" range into inclusive [start, end], or null if it
 * can't be satisfied.
 */
function parseRange(header, size) {
    const match = /^bytes=(\d*)-(\d*)$/.exec(header.trim());

    if (!match || (match[1] === '' && match[2] === '')) {
        return null;
    }

    let start;
    let end;

    if (match[1] === '') {
        // A suffix range: the last N bytes.
        start = Math.max(0, size - Number(match[2]));
        end = size - 1;
    } else {
        start = Number(match[1]);
        end = match[2] === '' ? size - 1 : Math.min(Number(match[2]), size - 1);
    }

    return start <= end && start < size ? [start, end] : null;
}
