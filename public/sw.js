const CACHE = 'membrum-v1';

const PRECACHE = [
    '/dashboard',
    '/mitt-kort',
    '/offline',
];

// ─── Install: pre-cache shell ────────────────────────────────────────────────
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE).then(cache => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
    );
});

// ─── Activate: remove old caches ─────────────────────────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// ─── Fetch strategy ──────────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    if (url.origin !== location.origin) return;
    if (request.method !== 'GET') return;
    if (url.pathname.startsWith('/livewire')) return;

    // Static assets — cache first
    if (
        url.pathname.startsWith('/build/') ||
        url.pathname.match(/\.(png|ico|svg|webp|woff2?)$/)
    ) {
        event.respondWith(
            caches.match(request).then(cached => cached || fetch(request).then(response => {
                const clone = response.clone();
                caches.open(CACHE).then(cache => cache.put(request, clone));
                return response;
            }))
        );
        return;
    }

    // Navigation — network first, fall back to cache then offline page
    if (request.headers.get('Accept')?.includes('text/html')) {
        event.respondWith(
            fetch(request)
                .then(response => {
                    const clone = response.clone();
                    caches.open(CACHE).then(cache => cache.put(request, clone));
                    return response;
                })
                .catch(() => caches.match(request).then(cached => cached || caches.match('/offline')))
        );
    }
});
