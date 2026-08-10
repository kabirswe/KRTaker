import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'

// Hash-based routing (no server rewrites on shared cPanel).
const routes = [
  { path: '/login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { public: true } },
  { path: '/', redirect: '/dashboard' },
  { path: '/dashboard', name: 'dashboard', component: () => import('../views/DashboardView.vue') },
  { path: '/properties', name: 'properties', component: () => import('../views/CollectionView.vue'), props: { collection: 'properties' } },
  { path: '/units', name: 'units', component: () => import('../views/CollectionView.vue'), props: { collection: 'units' } },
  { path: '/tenants', name: 'tenants', component: () => import('../views/CollectionView.vue'), props: { collection: 'tenants' } },
  { path: '/leases', name: 'leases', component: () => import('../views/CollectionView.vue'), props: { collection: 'leases' } },
  { path: '/invoices', name: 'invoices', component: () => import('../views/CollectionView.vue'), props: { collection: 'invoices' } },
  { path: '/receipts', name: 'receipts', component: () => import('../views/CollectionView.vue'), props: { collection: 'receipts' } },
  { path: '/payments', name: 'payments', component: () => import('../views/CollectionView.vue'), props: { collection: 'payments' } },
  { path: '/maintenance', name: 'maintenance', component: () => import('../views/CollectionView.vue'), props: { collection: 'tickets' } },
  { path: '/vendors', name: 'vendors', component: () => import('../views/CollectionView.vue'), props: { collection: 'partners' } },
  { path: '/staff', name: 'staff', component: () => import('../views/CollectionView.vue'), props: { collection: 'staff' } },
  { path: '/cases', name: 'cases', component: () => import('../views/CollectionView.vue'), props: { collection: 'cases' } },
  { path: '/notices', name: 'notices', component: () => import('../views/CollectionView.vue'), props: { collection: 'notices' } },
  { path: '/settings', name: 'settings', component: () => import('../views/SettingsView.vue') },
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

// Auth + data guard: no token → login; token but no data → bootstrap before render.
router.beforeEach(async (to) => {
  const auth = useAuthStore()
  const data = useDataStore()
  if (to.meta.public) return true
  if (!auth.isAuthed) return { name: 'login' }
  if (!data.loaded && !data.loading) {
    const ok = await data.bootstrap()
    if (!ok && !data.offline) {
      auth.clear()
      return { name: 'login' }
    }
  }
  return true
})

export default router
