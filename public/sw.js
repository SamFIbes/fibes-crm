const cacheName = 'fibes-tech-v1';

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname !== '/tech' && !url.pathname.startsWith('/build/')) {
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
