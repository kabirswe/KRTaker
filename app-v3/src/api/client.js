// KRTaker API client — mirrors dashboard-v2.html apiCall() semantics.
// Base: relative '../api/' (same as v2) → works in dev (proxy) and prod (cPanel).
import { useAuthStore } from '../stores/auth'

const API_BASE = '../api/'

export async function apiCall(path, data = null) {
  const opts = { method: data ? 'POST' : 'GET', headers: {} }
  if (data) opts.headers['Content-Type'] = 'application/json'
  const auth = useAuthStore()
  if (auth.token) opts.headers['Authorization'] = 'Bearer ' + auth.token
  if (data) opts.body = JSON.stringify(data)

  // Transient failures (SQLite lock, LiteSpeed 503, empty body) → retry with backoff.
  let last = null
  for (let i = 0; i < 3; i++) {
    try {
      const res = await fetch(API_BASE + path, opts)
      const text = await res.text()
      let j = null
      try { j = JSON.parse(text) } catch (e) { j = null }
      if (!j) {
        if (res.status === 401 && path !== 'app-login') { auth.clear(); location.hash = '#/login' }
        return { ok: false, error: 'Empty response from server.', _status: res.status }
      }
      j._status = res.status
      if (j.ok === false && (res.status === 503 || res.status === 500 || res.status === 504)) {
        last = j; await sleep(700 * (i + 1)); continue
      }
      return j
    } catch (e) {
      last = { ok: false, error: 'Network error — please try again.', _net: true }
      await sleep(700 * (i + 1))
    }
  }
  return last || { ok: false, error: 'Request failed.' }
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)) }

// ── Bot guard (mirrors api/src/064_router.php bot_guard_check) ──
// PoW: sha256(window:nonce) with >= N leading zero bits, window = floor(epoch/300).
// Difficulty from admin_cfg bot_pow_bits (default 12); server accepts ±1 window.
export function powSolve(difficulty = 12) {
  const win = Math.floor(Date.now() / 1000 / 300)
  for (let w = win; w >= win - 1; w--) {
    for (let n = 0; n < (1 << 22); n++) {
      const nonce = n.toString(16)
      const hex = sha256Sync(w + ':' + nonce)
      let leading = 0
      for (const c of hex) {
        const v = parseInt(c, 16)
        if (v === 0) { leading += 4; continue }
        leading += 4 - v.toString(2).length   // leading zeros in the 4-bit nibble
        break
      }
      if (leading >= difficulty) return { pow: nonce, window: w }
    }
  }
  return { pow: '', window: win }
}

// Bot-guard fields for form endpoints: hp (honeypot, empty), ft (page-load ms, >2s old), pow.
export function botFields() {
  const ft = (typeof window.__krtFt === 'number' ? window.__krtFt : Date.now())
  return { hp: '', ft, pow: powSolve(12).pow }
}

// Tiny sync SHA-256 (hex). No WebCrypto dependency needed for the PoW loop speed;
// this is a compact pure-JS implementation.
function sha256Sync(ascii) {
  function rightRotate(v, a) { return (v >>> a) | (v << (32 - a)) }
  const mathPow = Math.pow
  const maxWord = mathPow(2, 32)
  let result = ''
  const words = []
  const asciiBitLength = ascii.length * 8
  let hash = (sha256Sync.h = sha256Sync.h || [])
  const k = (sha256Sync.k = sha256Sync.k || [])
  let primeCounter = k.length
  const isComposite = {}
  for (let candidate = 2; primeCounter < 64; candidate++) {
    if (!isComposite[candidate]) {
      for (let i = 0; i < 313; i += candidate) isComposite[i] = candidate
      hash[primeCounter] = (mathPow(candidate, 0.5) * maxWord) | 0
      k[primeCounter++] = (mathPow(candidate, 1 / 3) * maxWord) | 0
    }
  }
  ascii += '\x80'
  while (ascii.length % 64 - 56) ascii += '\x00'
  for (let i = 0; i < ascii.length; i++) {
    const j = ascii.charCodeAt(i)
    if (j >> 8) return ''
    words[i >> 2] |= j << ((3 - i) % 4) * 8
  }
  words[words.length] = (asciiBitLength / maxWord) | 0
  words[words.length] = asciiBitLength
  for (let j = 0; j < words.length;) {
    const w = words.slice(j, j += 16)
    const oldHash = hash.slice(0, 8)
    for (let i = 0; i < 64; i++) {
      const w15 = w[i - 15], w2 = w[i - 2]
      const a = hash[0], e = hash[4]
      const temp1 = hash[7]
        + (rightRotate(e, 6) ^ rightRotate(e, 11) ^ rightRotate(e, 25))
        + ((e & hash[5]) ^ (~e & hash[6]))
        + k[i]
        + (w[i] = (i < 16) ? w[i] : (w[i - 16]
          + (rightRotate(w15, 7) ^ rightRotate(w15, 18) ^ (w15 >>> 3))
          + w[i - 7]
          + (rightRotate(w2, 17) ^ rightRotate(w2, 19) ^ (w2 >>> 10))) | 0)
      const temp2 = (rightRotate(a, 2) ^ rightRotate(a, 13) ^ rightRotate(a, 22))
        + ((a & hash[1]) ^ (a & hash[2]) ^ (hash[1] & hash[2]))
      hash = [(temp1 + temp2) | 0].concat(hash)
      hash[4] = (hash[4] + temp1) | 0
    }
    for (let i = 0; i < 8; i++) hash[i] = (hash[i] + oldHash[i]) | 0
  }
  for (let i = 0; i < 8; i++) {
    for (let j = 3; j + 1; j--) {
      const b = (hash[i] >> (j * 8)) & 255
      result += ((b < 16) ? 0 : '') + b.toString(16)
    }
  }
  return result
}
