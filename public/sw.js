// Service worker for timetable change notifications (see
// App\Push\ChangeNotifier). It only shows pushes and opens the dashboard
// when one is tapped - no fetch handler, no offline cache, so the pages
// themselves load exactly as they would without it.
//
// It has to live at the site root (not under /assets/) to be allowed to
// control the whole site, and is deliberately not versioned with ?v= -
// browsers re-check a service worker script byte for byte on their own.

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (error) {
        data = { body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(self.registration.showNotification(data.title || '', {
        body: data.body || '',
        icon: '/icon-192.png',
        badge: '/icon-192.png',
        data: { url: data.url || '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/', self.location.origin).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if ('navigate' in client) {
                try {
                    await client.focus();
                    await client.navigate(url);
                    return;
                } catch (error) {
                    // Not controlled by this worker (yet) - fall through to a new window.
                }
            }
        }
        await self.clients.openWindow(url);
    })());
});
