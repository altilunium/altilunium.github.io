const CACHE = "chronoo-v1";

const ASSETS = [
    "./",
    "./index.html",
    "./manifest.json",
    "./dexie.min.js",
    "./cropper.min.js",
    "./cropper.min.css",
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