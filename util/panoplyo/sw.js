self.addEventListener('install', (e) => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', (e) => {
    if (e.request.url.includes('wikidata.org')) return;
    e.respondWith(
        caches.match(e.request).then((res) => res || fetch(e.request).catch(() => new Response('Offline')))
    );
});