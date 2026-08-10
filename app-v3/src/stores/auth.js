import { defineStore } from 'pinia'
import { apiCall, botFields } from '../api/client'
import { ROLE_RANK } from '../lib/roles'

const TOKEN_KEY = 'krtaker' + '_dash_' + 'token'  // same key as dashboard-v2
const ORIG_KEY = 'krtaker_orig_token'  // real token while viewing-as a subordinate

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: (() => { try { return localStorage.getItem(TOKEN_KEY) || '' } catch (e) { return '' } })(),
    user: null,
    need2fa: false,
    loading: false,
    error: '',
    // View-as (subordinate impersonation) state
    impersonator: '',
    impExpires: '',
  }),
  getters: {
    isAuthed: (s) => !!s.token,
    role: (s) => s.user?.role || 'owner',
    isStaff: (s) => !!s.user?.is_staff,
    isImpersonating: (s) => !!s.impersonator,
    // Role hierarchy for subordinate switching (matches dashboard-v2 ROLE_RANK)
    rank: (s) => ROLE_RANK[s.user?.role || 'owner'] ?? 0,
    canSwitchTo: (s) => (roleId) => {
      const them = ROLE_RANK[roleId]
      return them !== undefined && them > 0 && them < s.rank
    },
  },
  actions: {
    async login(email, password, twofaCode = '') {
      this.loading = true; this.error = ''; this.need2fa = false
      try {
        const body = { email, password, ...botFields() }
        if (twofaCode) body['2fa_code'] = twofaCode
        const r = await apiCall('app-login', body)
        if (r.ok) {
          this.token = r.token
          this.user = r.user
          this.impersonator = ''
          this.impExpires = ''
          try { localStorage.setItem(TOKEN_KEY, r.token) } catch (e) {}
          try { localStorage.removeItem(ORIG_KEY) } catch (e) {}
          return { ok: true }
        }
        if (r.need_2fa) { this.need2fa = true; return { ok: false, need2fa: true } }
        this.error = r.error || 'Invalid email or password.'
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
      if (r.ok) { this.user = r.user; return true }
      return false
    },
    async logout() {
      try { await apiCall('app-logout', {}) } catch (e) {}
      this.clear()
    },
    clear() {
      this.token = ''; this.user = null; this.need2fa = false
      this.impersonator = ''; this.impExpires = ''
      try { localStorage.removeItem(TOKEN_KEY) } catch (e) {}
      try { localStorage.removeItem(ORIG_KEY) } catch (e) {}
    },
  },
})
