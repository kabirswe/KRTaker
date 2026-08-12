import { defineStore } from 'pinia'
import { apiCall, botFields, attachHumanTokens } from '../api/client'
import { HIERARCHY, canViewAs } from '../lib/roles'

const TOKEN_KEY = 'krtaker' + '_dash_' + 'token'  // same key as dashboard-v2
const ORIG_KEY = 'krtaker_orig_token'  // real token while viewing-as a subordinate

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: (() => { try { return localStorage.getItem(TOKEN_KEY) || '' } catch (e) { return '' } })(),
    user: null,
    need2fa: false,
    loading: false,
    error: '',
    // True once the persisted token has been validated against /app-me (or cleared).
    // App.vue shows the login screen until this is set — a stale token must never
    // render the app shell (empty sidebar, no login form).
    validated: false,
    // View-as (subordinate impersonation) state
    impersonator: '',
    impExpires: '',
  }),
  getters: {
    isAuthed: (s) => !!s.token,
    role: (s) => s.user?.role || '',
    isStaff: (s) => !!s.user?.is_staff,
    isImpersonating: (s) => !!s.impersonator,
    // Three-group hierarchy for subordinate switching (matches server app-view-as)
    rank: (s) => HIERARCHY[s.user?.role || '']?.r ?? 0,
    canSwitchTo: (s) => (roleId) => canViewAs(s.user?.role || '', roleId),
  },
  actions: {
    async login(email, password, twofaCode = '', extra = {}) {
      this.loading = true; this.error = ''; this.need2fa = false
      try {
        const body = { email, password, ...botFields(), ...extra }
        // Optional reCAPTCHA v3 / Turnstile tokens — added only when configured
        await attachHumanTokens(body)
        if (twofaCode) body['2fa_code'] = twofaCode
        const r = await apiCall('app-login', body)
        if (r.ok) {
          this.token = r.token
          this.user = r.user
          this.impersonator = ''
          this.impExpires = ''
          this.validated = true
          try { localStorage.setItem(TOKEN_KEY, r.token) } catch (e) {}
          try { localStorage.removeItem(ORIG_KEY) } catch (e) {}
          return { ok: true }
        }
        if (r.need_2fa) { this.need2fa = true; return { ok: false, need2fa: true, method: r.method || 'totp', email_hint: r.email_hint || '' } }
        this.error = r.error || 'Invalid email or password.'
        this.validated = true
        return { ok: false, error: this.error }
      } finally { this.loading = false }
    },
    // Real subordinate switch: server issues a 30-min temp token scoped to the target user.
    // Save our real token, swap in the temp token, then fetch the target's identity.
    async viewAs(email) {
      const r = await apiCall('app-view-as', { email })
      if (!r.ok) return { ok: false, error: r.error || 'Switch failed.' }
      if (!this.impersonator) {
        try { localStorage.setItem(ORIG_KEY, this.token) } catch (e) {}
      }
      this.token = r.token
      try { localStorage.setItem(TOKEN_KEY, r.token) } catch (e) {}
      this.impersonator = r.impersonator || ''
      this.impExpires = r.expires_at || ''
      const me = await this.fetchMe()
      if (!me) { this.user = r.user }
      return { ok: true, expires_at: r.expires_at, user: r.user }
    },
    // Return to the real account: restore the saved token + identity.
    async backToMe() {
      const orig = (() => { try { return localStorage.getItem(ORIG_KEY) || '' } catch (e) { return '' } })()
      if (!orig) return { ok: false }
      this.token = orig
      try { localStorage.setItem(TOKEN_KEY, orig) } catch (e) {}
      try { localStorage.removeItem(ORIG_KEY) } catch (e) {}
      this.impersonator = ''
      this.impExpires = ''
      const me = await this.fetchMe()
      return { ok: !!me }
    },
    async fetchMe() {
      const r = await apiCall('app-me')
      if (r.ok) { this.user = r.user; this.validated = true; return true }
      // Invalid/expired token: drop it so App.vue falls back to the login screen
      // instead of rendering an empty shell (the "login form not showing" bug).
      this.user = null
      this.validated = true
      this.token = ''
      try { localStorage.removeItem(TOKEN_KEY) } catch (e) {}
      return false
    },
    async logout() {
      try { await apiCall('app-logout', {}) } catch (e) {}
      this.clear()
    },
    clear() {
      this.token = ''; this.user = null; this.need2fa = false
      this.impersonator = ''; this.impExpires = ''
      this.validated = true
      try { localStorage.removeItem(TOKEN_KEY) } catch (e) {}
      try { localStorage.removeItem(ORIG_KEY) } catch (e) {}
    },
  },
})
