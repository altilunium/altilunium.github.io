const CACHE = "c-v1";

const ASSETS = [
    "./",
    "./index.html",
    "./manifest.json",
    "./tailwind.js"
];

self.addEventListener("install", event => {
    event.waitUntil(
        caches.open(CACHE).then(cache => cache.addAll(ASSETS))
    );
});

self.addEventListener("fetch", event => {
    event.respondWith(
        caches.match(event.request).then(res => {
            return res || fetch(event.request);
        })
    );
});
