// KRTaker app-v3 service worker (injectManifest mode — V2.14)
// Precache + runtime caching identical to the old generateSW output, PLUS
// web-push handlers (push / notificationclick).
import { precacheAndRoute, cleanupOutdatedCaches } from 'workbox-precaching'
import { registerRoute } from 'workbox-routing'
import { NetworkFirst } from 'workbox-strategies'

precacheAndRoute(self.__WB_MANIFEST)
cleanupOutdatedCaches()

// SPA shell: network-first so updates land immediately; fallback to cache offline.
// Match BOTH the bare directory URL (/app-v3/) and explicit .html paths — the
// bare URL used to fall through to the precache (stale shell) and never update.
registerRoute(
  ({ url }) => url.origin === self.location.origin && (url.pathname.endsWith('.html') || url.pathname.endsWith('/')),
  new NetworkFirst({ cacheName: 'krtaker-shell', networkTimeoutSeconds: 3 })
)
// Bootstrap data: network-first; offline → last-loaded snapshot (read-only banner).
registerRoute(
  ({ url }) => url.pathname.includes('/api/app-bootstrap'),
  new NetworkFirst({ cacheName: 'krtaker-data', networkTimeoutSeconds: 4 })
)

// Update flow (matches the old generated SW): take over + let main.js reload once.
self.skipWaiting()
self.addEventListener('activate', (event) => {
  // Claim open tabs immediately so the freshly-activated SW controls them NOW —
  // without this, an already-open tab keeps the old shell until the next visit.
  event.waitUntil(self.clients.claim())
})
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting()
})

// ── Web push (V2.14) ─────────────────────────────────────────────
self.addEventListener('push', (event) => {
  let data = { title: 'KRTaker', body: '', url: './', icon: 'icons/icon-192.png', tag: 'kr-push' }
  try {
    if (event.data) {
      const p = event.data.json()
      if (p && typeof p === 'object') {
        data = {
          title: p.title || data.title,
          body: p.body || '',
          url: p.url || './',
          icon: p.icon || data.icon,
          tag: p.tag || 'kr-push',
        }
      }
    }
  } catch (e) {
    if (event.data) data.body = event.data.text()
  }
  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      icon: data.icon,
      badge: 'icons/icon-192.png',
      tag: data.tag,
      data: { url: data.url },
      vibrate: [120, 60, 120],
    })
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = (event.notification.data && event.notification.data.url) || './'
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const client of list) {
        if ('focus' in client) {
          try { client.navigate(url) } catch (e) { /* same-origin guard */ }
          return client.focus()
        }
      }
      return clients.openWindow(url)
    })
  )
})
