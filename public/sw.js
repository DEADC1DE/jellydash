// Service worker for Jellydash.
//
// Two jobs:
//   1. Its presence (with a fetch handler) lets Android install Jellydash as a
//      real standalone PWA (a WebAPK) with its own window, no browser UI,
//      and a maskable icon, instead of a plain browser shortcut.
//   2. It receives Web Push messages (playback alerts) and shows them, even
//      when the app is closed.
// It deliberately does NOT pre-cache anything: the dashboard is live data and
// assets are already cache-busted, so there is nothing to go stale.

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', () => {
    // Intentionally a no-op. Its presence is all Chrome needs to treat the app
    // as installable; by NOT intercepting the launch navigation we avoid a
    // service-worker cold start in the critical path, which was causing the
    // blank flash between the splash screen and the page. The browser handles
    // every request directly, exactly as before install.
});

// A playback alert arrived. The payload is JSON built by PlaybackNotifier.
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }

    const title = data.title || 'Jellydash';
    const options = {
        body: data.body || '',
        icon: '/assets/img/icon-192.png',
        badge: '/assets/img/icon-192.png',
        tag: data.tag || 'jellydash',
        data: { url: data.url || '/now-playing' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// Tapping a notification focuses an open Jellydash window (or opens one).
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || '/now-playing';

    event.waitUntil((async () => {
        const clientList = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of clientList) {
            if ('focus' in client) {
                await client.focus();
                if ('navigate' in client) {
                    try {
                        await client.navigate(target);
                    } catch (e) {
                        // Cross-origin or detached client; ignore.
                    }
                }
                return;
            }
        }
        if (self.clients.openWindow) {
            await self.clients.openWindow(target);
        }
    })());
});
