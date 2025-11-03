/* Simple service worker for offline support and background sync-like behavior
   - Caches app shell (index.html, app.js, manifest, icons)
   - Stores POSTed orders in IndexedDB when offline (app handles this) and syncs when back online
*/

const CACHE_NAME = 'bkmb-pwa-v1';
const ASSETS = [
  '/',
  '/index.html',
  '/app.js',
  '/manifest.json',
  '/icons/icon-192.png',
  '/icons/icon-512.png'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
    ))
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  const req = event.request;
  // Network-first for API POSTs is handled by the client; for GETs try network then cache
  if (req.method === 'GET') {
    event.respondWith(
      fetch(req).then(res => {
        // put a copy in cache
        const copy = res.clone();
        caches.open(CACHE_NAME).then(cache => cache.put(req, copy));
        return res;
      }).catch(() => caches.match(req).then(r => r || caches.match('/index.html')))
    );
  }
});

// Optional: listen for messages from client to trigger sync attempts
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
/* Simple service worker for offline support and background sync-like behavior
   - Caches app shell (index.html, app.js, manifest, icons)
   - Stores POSTed orders in IndexedDB when offline (app handles this) and syncs when back online
*/

const CACHE_NAME = 'bkmb-pwa-v1';
const ASSETS = [
  '/',
  '/index.html',
  '/app.js',
  '/manifest.json',
  '/icons/icon-192.png',
  '/icons/icon-512.png'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS))
  );
  /* Simple service worker for offline support and background sync-like behavior
     - Caches app shell (index.html, app.js, manifest, icons)
     - Stores POSTed orders in IndexedDB when offline (app handles this) and syncs when back online
  */

  const CACHE_NAME = 'bkmb-pwa-v1';
  const ASSETS = [
    '/',
    '/index.html',
    '/app.js',
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png'
  ];

  self.addEventListener('install', event => {
    event.waitUntil(
      caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS))
    );
    self.skipWaiting();
  });

  self.addEventListener('activate', event => {
    event.waitUntil(
      caches.keys().then(keys => Promise.all(
        keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
      ))
    );
    self.clients.claim();
  });

  self.addEventListener('fetch', event => {
    const req = event.request;
    // Network-first for API POSTs is handled by the client; for GETs try network then cache
    if (req.method === 'GET') {
      event.respondWith(
        fetch(req).then(res => {
          // put a copy in cache
          const copy = res.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(req, copy));
          return res;
        }).catch(() => caches.match(req).then(r => r || caches.match('/index.html')))
      );
    }
  });

  // Optional: listen for messages from client to trigger sync attempts
  self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
      self.skipWaiting();
    }
  });
