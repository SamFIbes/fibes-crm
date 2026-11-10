const cacheName = 'fibes-static-v2';

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== cacheName).map((key) => caches.delete(key)))));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== cacheName).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Hashed build assets only. Never cache HTML, API, or customer pages such as /tech.
    if (url.origin !== self.location.origin || !url.pathname.startsWith('/build/')) {
        return;
    }

    event.respondWith(
        fetch(request)
            .then((response) => {
                const copy = response.clone();
                caches.open(cacheName).then((cache) => cache.put(request, copy));

                return response;
            })
            .catch(() => caches.match(request).then((cached) => cached || Response.error())),
    );
});
