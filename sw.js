// Memoir service worker: cache static assets (app CSS/JS, CDN libraries,
// fonts) with stale-while-revalidate. PHP pages and the API always hit the
// network — note data is never cached.
const CACHE = 'memoir-static-v1';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    const isStatic =
        (url.origin === location.origin && url.pathname.includes('/assets/')) ||
        url.hostname === 'cdnjs.cloudflare.com' ||
        url.hostname === 'fonts.googleapis.com' ||
        url.hostname === 'fonts.gstatic.com';
    if (!isStatic) return;

    event.respondWith((async () => {
        const cache = await caches.open(CACHE);
        const hit = await cache.match(request);
        const network = fetch(request).then(resp => {
            if (resp.ok) cache.put(request, resp.clone());
            return resp;
        }).catch(() => hit);
        return hit || network;
    })());
});
