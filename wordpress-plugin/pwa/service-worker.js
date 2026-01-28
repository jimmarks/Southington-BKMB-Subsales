// Subsales PWA service worker with offline-first support
const CACHE_NAME = 'subsales-pwa-v2026-01-28-teamid-fix';
// Resolve asset URLs relative to the service worker's scope so the SW works when the plugin
// is served from a nested path (e.g. /subsales-portal/). We build absolute URLs at runtime.
const ASSETS = [
  './',
  './index.html',
  './app.js',
  './styles.css',
  './manifest.json',
  './pwa-logger.js',
  './session-tracking.js',
  './icons/icon-192.svg',
  './icons/icon-512.svg'
];

self.addEventListener('install', event => {

  event.waitUntil((async ()=>{
    try{
      const base = self.registration.scope;

      const urls = ASSETS.map(p => new URL(p, base).toString());

      const cache = await caches.open(CACHE_NAME);
      // Cache each asset individually with error handling
      for(const u of urls){
        try{ 
          await cache.add(u); 

        }catch(e){ 
          console.warn('[SW] Cache add failed for', u, e); 
        }
      }

    }catch(e){ 
      console.warn('[SW] Install error', e); 
    }
  })());
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => {
      return Promise.all(
        keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
      );
    }).then(() => self.clients.claim())
  );
});

// Cache-first strategy for PWA assets, network-first for API calls
self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;
  
  const url = new URL(event.request.url);
  const pathname = url.pathname;
  
  // Determine if this is a PWA asset (HTML, JS, CSS, icons, manifest)
  const isPWAAsset = pathname.endsWith('.html') || 
                     pathname.endsWith('.js') || 
                     pathname.endsWith('.css') || 
                     pathname.endsWith('.json') || 
                     pathname.endsWith('.svg') || 
                     pathname.endsWith('.png') ||
                     pathname.endsWith('.jpg') ||
                     pathname.endsWith('.ico');
  
  const isAPICall = pathname.includes('/wp-json/') || pathname.includes('/api/');
  
  // Navigation requests - serve from cache offline-first
  if (event.request.mode === 'navigate') {
    event.respondWith(
      caches.open(CACHE_NAME).then(cache => {
        const base = self.registration.scope;
        const indexUrl = new URL('./index.html', base).toString();
        
        return fetch(event.request)
          .then(res => {
            cache.put(event.request, res.clone());
            return res;
          })
          .catch(() => {
            // Offline - try cache
            return cache.match(indexUrl).then(cached => {
              if (cached) return cached;
              return cache.match(base).then(baseCached => baseCached || new Response(
                '<!DOCTYPE html><html><head><title>Offline</title></head><body><h1>Offline</h1><p>Unable to load the page.</p></body></html>',
                { status: 503, headers: { 'Content-Type': 'text/html' } }
              ));
            });
          });
      })
    );
    return;
  }
  
  if (isPWAAsset) {
    // Cache-first for PWA assets (offline-first)
    event.respondWith(
      caches.match(event.request).then(cached => {
        if (cached) {

          // Return cached version, but update cache in background
          fetch(event.request).then(res => {
            if (res && res.ok) {
              caches.open(CACHE_NAME).then(cache => cache.put(event.request, res));
            }
          }).catch(() => {});
          return cached;
        }

        // Not in cache, try network
        return fetch(event.request).then(res => {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(event.request, copy));
          }
          return res;
        }).catch(err => {
          console.error('[SW] Fetch failed:', event.request.url, err);
          return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
        });
      })
    );
  } else if (isAPICall) {
    // Network-first for API calls (fresh data priority)
    event.respondWith(
      fetch(event.request).then(res => {
        // Don't cache API responses to avoid stale data
        return res;
      }).catch(() => {
        // API failed offline, return error response

        return new Response(JSON.stringify({ error: 'Offline', offline: true }), {
          status: 503,
          statusText: 'Service Unavailable',
          headers: { 'Content-Type': 'application/json' }
        });
      })
    );
  } else {
    // Default: network with cache fallback for other resources
    event.respondWith(
      fetch(event.request).then(res => {
        if (res && res.ok) {
          const copy = res.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, copy));
        }
        return res;
      }).catch(() => caches.match(event.request))
    );
  }
});

// Allow the page to trigger an immediate SW activation
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
