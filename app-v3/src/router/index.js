import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'

// Hash-based routing (no server rewrites on shared cPanel).
// Generic collection routes map each module to its bootstrap table.
const routes = [
  { path: '/login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { public: true } },
  { path: '/', redirect: '/dashboard' },
  { path: '/dashboard', name: 'dashboard', component: () => import('../views/DashboardView.vue') },
  // ── Core CRUD collections ──
  { path: '/properties', name: 'properties', component: () => import('../views/PropertiesView.vue') },
  { path: '/units', name: 'units', component: () => import('../views/UnitsView.vue') },
  { path: '/tenants', name: 'tenants', component: () => import('../views/TenantsView.vue') },
  { path: '/leases', name: 'leases', component: () => import('../views/LeasesView.vue') },
  { path: '/invoices', name: 'invoices', component: () => import('../views/InvoicesView.vue') },
  { path: '/receipts', name: 'receipts', component: () => import('../views/ReceiptsView.vue') },
  { path: '/payments', name: 'payments', component: () => import('../views/PaymentsView.vue') },
  { path: '/maintenance', name: 'maintenance', component: () => import('../views/MaintenanceView.vue') },
  { path: '/vendors', name: 'vendors', component: () => import('../views/VendorsView.vue') },
  { path: '/staff', name: 'staff', component: () => import('../views/StaffView.vue') },
  { path: '/cases', name: 'cases', component: () => import('../views/CasesView.vue') },
  { path: '/notices', name: 'notices', component: () => import('../views/NoticesView.vue') },
  { path: '/leads', name: 'leads', component: () => import('../views/CollectionView.vue'), props: { collection: 'leads' } },
  { path: '/support', name: 'support', component: () => import('../views/CollectionView.vue'), props: { collection: 'support' } },
  { path: '/compliance', name: 'compliance', component: () => import('../views/ComplianceView.vue') },
  { path: '/legal', name: 'legal', component: () => import('../views/LegalView.vue') },
  { path: '/utility-bills', name: 'utility-bills', component: () => import('../views/UtilityBillsView.vue') },
  { path: '/meter-readings', name: 'meter-readings', component: () => import('../views/MeterReadingsView.vue') },
  { path: '/partner-invoices', name: 'partner-invoices', component: () => import('../views/CollectionView.vue'), props: { collection: 'partner_invoices' } },
  { path: '/vendor-payouts', name: 'vendor-payouts', component: () => import('../views/CollectionView.vue'), props: { collection: 'vendor_payouts' } },
  { path: '/remittances', name: 'remittances', component: () => import('../views/CollectionView.vue'), props: { collection: 'remittances' } },
  { path: '/onboarding', name: 'onboarding', component: () => import('../views/CollectionView.vue'), props: { collection: 'onboarding_apps' } },
  { path: '/concierge', name: 'concierge', component: () => import('../views/CollectionView.vue'), props: { collection: 'concierge_requests' } },
  { path: '/documents', name: 'documents', component: () => import('../views/DocumentsView.vue') },
  { path: '/referrals', name: 'referrals', component: () => import('../views/CollectionView.vue'), props: { collection: 'referrals' } },
  { path: '/nid', name: 'nid', component: () => import('../views/CollectionView.vue'), props: { collection: 'nid_verifications' } },
  { path: '/insurance', name: 'insurance', component: () => import('../views/CollectionView.vue'), props: { collection: 'insurance_policies' } },
  { path: '/holding-taxes', name: 'holding-taxes', component: () => import('../views/CollectionView.vue'), props: { collection: 'holding_taxes' } },
  { path: '/gate-visits', name: 'gate-visits', component: () => import('../views/CollectionView.vue'), props: { collection: 'gate_visits' } },
  { path: '/staff-attendance', name: 'staff-attendance', component: () => import('../views/CollectionView.vue'), props: { collection: 'staff_attendance' } },
  { path: '/staff-payroll', name: 'staff-payroll', component: () => import('../views/CollectionView.vue'), props: { collection: 'staff_payroll' } },
  { path: '/land', name: 'land', component: () => import('../views/CollectionView.vue'), props: { collection: 'land_parcels' } },
  { path: '/build', name: 'build', component: () => import('../views/CollectionView.vue'), props: { collection: 'build_projects' } },
  { path: '/samity', name: 'samity', component: () => import('../views/CollectionView.vue'), props: { collection: 'samity_members' } },
  { path: '/analytics', name: 'analytics', component: () => import('../views/AnalyticsView.vue') },
  { path: '/ai', name: 'ai', component: () => import('../views/AiView.vue') },
  // ── Module views (custom) ──
  { path: '/settings', name: 'settings', component: () => import('../views/SettingsView.vue') },
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

// Auth + data guard: no token → login; token but no data → bootstrap before render.
// Also restore the user identity on reload (token persists in localStorage but auth.user
// is null after a page refresh — without fetchMe every role gate that reads
// auth.user?.role would be off until a fresh login).
router.beforeEach(async (to) => {
  const auth = useAuthStore()
  const data = useDataStore()
  if (to.meta.public) return true
  if (!auth.isAuthed) return { name: 'login' }
  if (!auth.user) {
    try { await auth.fetchMe() } catch (e) { /* bootstrap below still validates the token */ }
  }
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
