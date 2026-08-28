// KRTaker GA4 product analytics (V2.18) — lightweight gtag/dataLayer wrapper.
// GA4 id is baked in here AND in index.html's gtag snippet; if the snippet is
// blocked/absent (ad-block, offline), track() still pushes to dataLayer safely.
// Usage:  import { track } from '../lib/analytics'
//         track('invoice_created', { amount: 120000 })

const GA4_ID = 'G-C68G5Q03ZT'

export function track(name, params = {}) {
  try {
    const dl = window.dataLayer = window.dataLayer || []
    // Strip undefined values (JSON.stringify drops them; keep payload clean).
    const clean = {}
    for (const k of Object.keys(params)) if (params[k] !== undefined && params[k] !== null) clean[k] = params[k]
    dl.push({ event: name, ...clean })
    // gtag() may be undefined if the loader script hasn't run / was blocked.
    if (typeof window.gtag === 'function') {
      try { window.gtag('event', name, clean) } catch (e) { /* no-op */ }
    }
  } catch (e) { /* analytics must never break the app */ }
}

// Page view for the hash router — fired from router.afterEach.
export function pageView(title, path) {
  try {
    const dl = window.dataLayer = window.dataLayer || []
    dl.push({
      event: 'page_view',
      page_title: title || document.title,
      page_location: window.location.href,
      page_path: path || (window.location.hash || '/'),
    })
    if (typeof window.gtag === 'function') {
      try { window.gtag('event', 'page_view', { page_title: title || document.title, page_location: window.location.href, page_path: path || window.location.hash || '/' }) } catch (e) { /* no-op */ }
    }
  } catch (e) { /* no-op */ }
}

export { GA4_ID }
