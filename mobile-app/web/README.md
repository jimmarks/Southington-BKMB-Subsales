# BKMB Subsales — PWA client

This folder contains a minimal PWA scaffold intended to run as a client-side web app and provide:

- Team login (team-name + access code)
- Offline-capable order entry
- Queued order sync when online
- Installable PWA (manifest + service worker)

Files created:

- `index.html` — simple UI for login and order entry
- `app.js` — client logic (stores queued orders in IndexedDB/localStorage, syncs when online)
- `service-worker.js` — caches app shell for offline usage
- `manifest.json` — PWA manifest

Behavior and design notes

- Orders are stored locally (IndexedDB preferred, localStorage fallback). Each order is given a local id.
- When the device returns online, the client will POST queued orders to the WordPress REST API at `/wp-json/order-manager/v1/orders`.
  - The client will include `X-Team-Name` and `X-Access-Code` headers saved during login.
- The PWA install prompt is captured via `beforeinstallprompt` and shown after a successful login.

How to test locally

1. From `mobile-app` install a simple static server if you don't already have one, e.g. `npm i -g serve`.
2. Serve the `web` folder:

```bash
cd mobile-app/web
serve -s .
```

3. Open the provided URL in a browser. Login with a team name and code, create orders, and test offline by toggling the browser's network.

Notes and next steps

- You may want to wire `API_BASE_URL` into `app.js` or create a small settings screen to change it; currently it looks at `localStorage.API_BASE_URL`.
- For robust background sync consider using the Background Sync API (requires HTTPS + supported browsers) or server-side webhooks.
- I intentionally kept the scaffold minimal and dependency-free so you can integrate it into whatever web build system you prefer (React, Next.js, etc.).
