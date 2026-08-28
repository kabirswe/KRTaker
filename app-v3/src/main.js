import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './styles/main.css'

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#app')

// ── Service worker: versioned URL + auto-upgrade + reload-once ─────────────
// Why: every deploy ships a new sw.js with a new precache list, but a browser
// that holds an OLD service worker keeps serving the OLD shell (old bundle,
// possibly the pre-fix relative-API build) → login POSTs into the
// test.krtaker.com/api 404 hole → "Empty response from server".
// Fix: (1) versioned script URL forces the browser to fetch a fresh sw.js on
// every visit, (2) the generated SW calls self.skipWaiting() so the new SW
// activates immediately, (3) on controllerchange we reload ONCE so a
// long-open tab upgrades to the fresh shell right away (no manual refresh).
if ('serviceWorker' in navigator) {
  const regSW = () => {
    const SW_URL = 'sw.js?v=' + new Date().toISOString().slice(0, 16)
    navigator.serviceWorker.register(SW_URL, { scope: './', updateViaCache: 'none' })
      .then(reg => {
        // New SW already installed & waiting → tell it to take over now.
        if (reg.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' })
        reg.addEventListener('updatefound', () => {
          const nw = reg.installing
          if (!nw) return
          nw.addEventListener('statechange', () => {
            if (nw.state === 'installed' && navigator.serviceWorker.controller) {
              nw.postMessage({ type: 'SKIP_WAITING' })
            }
          })
        })
        // New SW claimed the page → reload once to run the fresh shell.
        navigator.serviceWorker.addEventListener('controllerchange', () => {
          if (!sessionStorage.getItem('kr_sw_reloaded')) {
            sessionStorage.setItem('kr_sw_reloaded', '1')
            window.location.reload()
          }
        })
      })
      .catch(() => { /* SW unavailable (private mode / blocked) — app still works online */ })
  }
  // Module scripts run after DOM parse — register immediately, but if for any
  // reason we're here pre-load, defer to the load event.
  if (document.readyState === 'loading') window.addEventListener('load', regSW)
  else regSW()
}
