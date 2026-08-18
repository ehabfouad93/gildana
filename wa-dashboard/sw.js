/**
 * Gildana dashboard service worker.
 *
 * Caching policy is deliberately conservative:
 *   • Static assets (CSS, icons, manifest) → cache-first, they are versioned by ?v=mtime.
 *   • Everything else → network-first, falling back to an offline page.
 *
 * HTML is NEVER cached. These pages render one client's contacts, campaigns and
 * conversations; a cached copy could be shown to the wrong account after a logout/login
 * on a shared phone, or long after the data changed. Only the offline shell is stored.
 */
const VERSION = 'gildana-v2';
const OFFLINE = './offline.html';
const PRECACHE = [OFFLINE, './assets/icons/icon-192.png', './manifest.webmanifest'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

const isStatic = (url) => /\.(css|js|png|jpg|jpeg|svg|webp|woff2?)$/i.test(url.pathname)
                       || url.pathname.endsWith('manifest.webmanifest');

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;                       // never touch POSTs
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;        // let cross-origin through
  // Auth and logout must always hit the network.
  if (/logout\.php|login/i.test(url.pathname)) return;

  if (isStatic(url)) {
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res.ok) { const copy = res.clone(); caches.open(VERSION).then((c) => c.put(req, copy)); }
        return res;
      }).catch(() => hit))
    );
    return;
  }

  // Pages: network-first, offline page as the fallback. Nothing is written to the cache.
  event.respondWith(
    fetch(req).catch(() =>
      caches.match(OFFLINE).then((p) => p || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } }))
    )
  );
});


/* ─────────────────────────────────────────────
   PUSH
   Pushes carry no payload (see includes/push.php), so the worker asks the server for the
   unread count. userVisibleOnly:true means we MUST show a notification either way, so any
   failure falls back to a generic message rather than showing nothing.
───────────────────────────────────────────── */
const NOTIF_TAG = 'gildana-inbox';   // fixed tag → the OS REPLACES rather than stacks

async function showInboxNotification() {
  let title = 'New WhatsApp message';
  let body  = 'Open Gildana to reply.';
  try {
    const sub = await self.registration.pushManager.getSubscription();
    if (sub) {
      const res = await fetch('./push_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ endpoint: sub.endpoint })
      });
      if (res.ok) {
        const d = await res.json();
        const n = parseInt(d.count, 10);
        if (n > 0) {
          title = n === 1 ? '1 new message' : n + ' new messages';
          body  = 'Tap to open your inbox.';
        }
      }
    }
  } catch (e) { /* keep the generic text */ }

  return self.registration.showNotification(title, {
    body: body,
    icon: './assets/icons/icon-192.png',
    badge: './assets/icons/icon-192.png',
    tag: NOTIF_TAG,
    renotify: true,
    data: { url: './client/inbox.php' }
  });
}

self.addEventListener('push', (event) => {
  event.waitUntil(showInboxNotification());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || './client/inbox.php';
  event.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    // Reuse an open window when there is one, rather than piling up tabs.
    for (const c of all) {
      if ('focus' in c) { await c.focus(); if ('navigate' in c) { try { await c.navigate(target); } catch (e) {} } return; }
    }
    if (self.clients.openWindow) await self.clients.openWindow(target);
  })());
});
