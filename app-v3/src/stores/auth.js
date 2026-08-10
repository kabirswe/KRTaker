import { defineStore } from 'pinia'
import { apiCall, botFields } from '../api/client'

const TOKEN_KEY = 'krtaker_dash_token'  // same key as dashboard-v2 → sessions carry over

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: (() => { try { return localStorage.getItem(TOKEN_KEY) || '' } catch (e) { return '' } })(),
    user: null,
    need2fa: false,
    loading: false,
    error: '',
  }),
  getters: {
    isAuthed: (s) => !!s.token,
    role: (s) => s.user?.role || 'owner',
    isStaff: (s) => !!s.user?.is_staff,
    // Role hierarchy for subordinate switching (matches dashboard-v2 ROLE_RANK)
    rank: (s) => ({ superadmin: 100, owner: 90, manager: 80, svc_mgr: 60, legal: 60, crm: 60, accountant: 60, hr: 60, tenant: 20, partner: 20 })[s.user?.role || 'owner'] ?? 0,
    canSwitchTo: (s) => (roleId) => {
      const ranks = { superadmin: 100, owner: 90, manager: 80, svc_mgr: 60, legal: 60, crm: 60, accountant: 60, hr: 60, tenant: 20, partner: 20 }
      return ranks[roleId] !== undefined && ranks[roleId] < s.rank
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
          try { localStorage.setItem(TOKEN_KEY, r.token) } catch (e) {}
          return { ok: true }
        }
        if (r.need_2fa) { this.need2fa = true; return { ok: false, need2fa: true } }
        this.error = r.error || 'Invalid email or password.'
        return { ok: false, error: this.error }
      } finally { this.loading = false }
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
      try { localStorage.removeItem(TOKEN_KEY) } catch (e) {}
    },
  },
})
