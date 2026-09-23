const CACHE = "c-v2";

const ASSETS = [
    "./",
    "./index.html",
    "./manifest.json",
    "./tailwind.js",
    "./icon-192.png",
    "./icon-512.png",
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
