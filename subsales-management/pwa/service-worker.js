// Subsales PWA service worker
const CACHE_NAME = 'subsales-pwa-v2025-12-01-debug';
// Resolve asset URLs relative to the service worker's scope so the SW works when the plugin
// is served from a nested path (e.g. /subsales-portal/). We build absolute URLs at runtime.
const ASSETS = [
  './',
  './index.html',
  './app.js',
  './manifest.json',
  './icons/icon-192.svg',
  './icons/icon-512.svg'
];

self.addEventListener('install', event => {
  event.waitUntil((async ()=>{
    try{
      const base = (self.registration && self.registration.scope) ? self.registration.scope : '/';
      const urls = ASSETS.map(p => new URL(p, base).toString());
      const cache = await caches.open(CACHE_NAME);
      // Use addAll but protect against single-failure by adding individually and ignoring 404s
      for(const u of urls){
        try{ await cache.add(u); }catch(e){ console.warn('sw cache add failed', u, e); }
      }
    }catch(e){ console.warn('sw install error', e); }
  })());
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
  if (event.request.method !== 'GET') return;
  event.respondWith(
    fetch(event.request).then(res => {
      const copy = res.clone();
      caches.open(CACHE_NAME).then(cache => cache.put(event.request, copy));
      return res;
    }).catch(() => caches.match(event.request).then(r => r || caches.match('/pwa/index.html')))
  );
});

// Allow the page to trigger an immediate SW activation
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
