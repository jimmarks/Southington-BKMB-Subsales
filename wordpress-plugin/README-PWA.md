# BKMB Subsales — Plugin PWA Integration

This plugin now includes a simple PWA client under `wordpress-plugin/pwa/` that can be embedded into any WordPress page using the shortcode:

```
[bkmb_subsales_pwa]
```

What this provides
- A minimal client UI for team login and offline order capture.
- Local queueing of orders (IndexedDB with localStorage fallback).
- Syncs queued orders to the plugin REST API at `/wp-json/order-manager/v1/orders` when online.
- A service worker and manifest to enable installation as a PWA (scope limited to the plugin's directory).

How to use
1. Activate the plugin.
2. Create a new WordPress page and add the `[bkmb_subsales_pwa]` shortcode.
3. Visit the page on the frontend to use the PWA client. After login the install prompt will be offered when available.

Notes and limitations
- Because the service worker lives under the plugin directory, its scope is limited to the plugin folder. If you require root-scope service worker behavior for the whole site, the service worker file must be served from the site root (e.g., via a mu-plugin that writes the file to the root) — this is intentionally not done here for safety.
- The client posts to the plugin's REST endpoints and uses `X-Team-Name` and `X-Access-Code` headers to authenticate.
- Consider adding HTTPS (recommended) and implementing Background Sync for more robust background delivery when the browser is closed.
