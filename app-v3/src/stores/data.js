import { defineStore } from 'pinia'
import { apiCall } from '../api/client'

// Holds the app-bootstrap payload: user, catalog, subscription, collections (all tables).
// Mirrors dashboard-v2 LIVE.db — the single source of data for the whole SPA.
export const useDataStore = defineStore('data', {
  state: () => ({
    user: null,
    catalog: [],
    subscription: null,
    package: null,
    db: {},        // collections keyed by table name
    _platform: {},
    loaded: false,
    loading: false,
    error: '',
    offline: false,
  }),
  getters: {
    has: (s) => (key) => Array.isArray(s.db[key]),
    list: (s) => (key) => Array.isArray(s.db[key]) ? s.db[key] : [],
    // Role-switch preview state (subordinate switching like v2 tb-user)
    previewRole: (s) => s._previewRole || s.user?.role || 'owner',
  },
  actions: {
    async bootstrap() {
      this.loading = true; this.error = ''
      try {
        const r = await apiCall('app-bootstrap', {})
        if (r.ok) {
          this.user = r.user
          this.catalog = r.catalog || []
          this.subscription = r.subscription || null
          this.package = r.package || null
          const collections = r.collections || {}
          this.db = collections
          this._platform = collections._platform || {}
          this.loaded = true
          this.offline = false
          return true
        }
        if (r._status === 401) { this.error = 'Session expired — please log in.'; return false }
        this.error = r.error || 'Failed to load data.'
        return false
      } catch (e) {
        // Offline / network failure → keep last-loaded snapshot (PWA offline mode)
        this.offline = true
        this.error = 'You are offline — showing the last-loaded data.'
        return this.loaded
      } finally { this.loading = false }
    },
    setPreviewRole(role) { this._previewRole = role },
  },
})
