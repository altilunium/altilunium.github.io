self.addEventListener('push', event => {
    const data = event.data ? event.data.json() : {};
    const title = data.title || 'Location Search Alert';
    const options = {
        body: data.body || 'A new search query was executed on your server.',
        icon: '/icon.png',
        badge: '/badge.png'
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil(
        clients.openWindow('/')
    );
});
