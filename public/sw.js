/*
 * ServerScope service worker: makes the app installable, keeps the built
 * assets for fast starts, shows an offline page instead of a browser error,
 * and receives push notifications. It never caches pages or data: monitoring
 * must always be live, and pages are personal.
 */
const VERSION = 'ss-v2';
const OFFLINE = '/offline.html';
// Not /icons/: many Apache servers map that path to their own icon folder.
const ICON = '/app-icons/icon-192.png';

// A file that cannot be cached must not stop the worker from installing
// (push and the app depend on it); each one is tried on its own.
self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION)
        .then((cache) => Promise.allSettled([OFFLINE, ICON].map((path) => cache.add(path))))
        .then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) return;

    // Pages: always from the network; the offline page only when there is none.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));

        return;
    }

    // Built assets have a hash in their name: cache first, they never change.
    if (url.pathname.startsWith('/build/assets/') || url.pathname.startsWith('/app-icons/')) {
        event.respondWith(caches.open(VERSION).then(async (cache) => {
            const hit = await cache.match(request);

            if (hit) return hit;

            const response = await fetch(request);

            if (response.ok) cache.put(request, response.clone());

            return response;
        }));
    }
});

// Push: { title, body, url, tag } from App\Jobs\SendPushNotification.
self.addEventListener('push', (event) => {
    let data = {};

    try { data = event.data ? event.data.json() : {}; } catch { data = { title: 'ServerScope', body: event.data?.text() ?? '' }; }

    event.waitUntil(self.registration.showNotification(data.title || 'ServerScope', {
        body: data.body || '',
        icon: ICON,
        badge: ICON,
        tag: data.tag || undefined,
        renotify: Boolean(data.tag),
        data: { url: data.url || '/dashboard' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/dashboard', self.location.origin);

    // Only links into ServerScope itself.
    if (target.origin !== self.location.origin) return;

    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        const open = windows.find((w) => new URL(w.url).origin === target.origin);

        return open ? open.focus().then((w) => w.navigate(target.href)) : self.clients.openWindow(target.href);
    }));
});
