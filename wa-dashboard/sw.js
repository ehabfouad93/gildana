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
const VERSION = 'gildana-v1';
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
