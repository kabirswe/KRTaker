// Mall Manager service worker — offline support (spec 3.8.1)
// Hand-written (no build step). Network-first for everything; on failure
// serve the last cached copy → the app shell + last-loaded data stay usable
// without internet, and POST writes are handled by the client-side queue
// (see api/client.js) which replays when the connection returns.
const CACHE = 'mall-shell-v1'

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((c) => c.addAll(['./'])).then(() => self.skipWaiting())
  )
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  )
})

self.addEventListener('fetch', (event) => {
  const req = event.request
  // Only same-origin GETs are cached (writes must reach the server;
  // the client queues them when offline).
  if (req.method !== 'GET') return
  const u = new URL(req.url)
  if (u.origin !== self.location.origin) return
  // Never cache the API on a write-less GET that should stay fresh in dev —
  // still network-first: cached copy only kicks in when offline.
  event.respondWith(
    fetch(req)
      .then((res) => {
        if (res && res.status === 200 && (res.type === 'basic' || res.type === 'cors')) {
          const copy = res.clone()
          caches.open(CACHE).then((c) => c.put(req, copy))
        }
        return res
      })
      .catch(() =>
        caches.match(req).then((hit) => hit || caches.match('./'))
      )
  )
})

// Mall-branded push notifications (kept for parity with the app shell)
self.addEventListener('push', (event) => {
  let data = { title: 'Mall Manager', body: '', url: './', icon: './favicon.svg' }
  try {
    if (event.data) {
      const p = event.data.json()
      if (p && typeof p === 'object') data = { title: p.title || data.title, body: p.body || '', url: p.url || data.url, icon: p.icon || data.icon }
    }
  } catch (e) { if (event.data) data.body = event.data.text() }
  event.waitUntil(
    self.registration.showNotification(data.title, { body: data.body, icon: data.icon, badge: data.icon, data: { url: data.url }, vibrate: [120, 60, 120] })
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = (event.notification.data && event.notification.data.url) || './'
  event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const client of list) {
      if ('focus' in client) { try { client.navigate(url) } catch (e) {} return client.focus() }
    }
    return clients.openWindow(url)
  }))
})
