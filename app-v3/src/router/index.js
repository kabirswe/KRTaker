import { createRouter, createWebHashHistory } from 'vue-router'
import { pageView } from '../lib/analytics'
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
  { path: '/leads', name: 'leads', component: () => import('../views/LeadsView.vue') },
  { path: '/support', name: 'support', component: () => import('../views/SupportView.vue') },
  { path: '/compliance', name: 'compliance', component: () => import('../views/ComplianceView.vue') },
  { path: '/legal', name: 'legal', component: () => import('../views/LegalView.vue') },
  { path: '/utility-bills', name: 'utility-bills', component: () => import('../views/UtilityBillsView.vue') },
  { path: '/meter-readings', name: 'meter-readings', component: () => import('../views/MeterReadingsView.vue') },
  { path: '/partner-invoices', redirect: '/vendors' },
  { path: '/vendor-payouts', redirect: '/vendors' },
  { path: '/remittances', name: 'remittances', component: () => import('../views/RemittancesView.vue') },
  { path: '/statements', name: 'statements', component: () => import('../views/StatementsView.vue') },
  { path: '/templates', name: 'templates', component: () => import('../views/TemplatesView.vue') },
  { path: '/onboarding', name: 'onboarding', component: () => import('../views/OnboardingView.vue') },
  { path: '/concierge', name: 'concierge', component: () => import('../views/ConciergeView.vue') },
  { path: '/documents', name: 'documents', component: () => import('../views/DocumentsView.vue') },
  { path: '/referrals', name: 'referrals', component: () => import('../views/ReferralsView.vue') },
  { path: '/nid', name: 'nid', component: () => import('../views/NidView.vue') },
  { path: '/insurance', name: 'insurance', component: () => import('../views/InsuranceView.vue') },
  { path: '/holding-taxes', name: 'holding-taxes', component: () => import('../views/HoldingTaxesView.vue') },
  { path: '/collections', name: 'collections', component: () => import('../views/CollectionView.vue') },
  { path: '/gate-visits', name: 'gate-visits', component: () => import('../views/GateVisitsView.vue') },
  { path: '/staff-attendance', name: 'staff-attendance', component: () => import('../views/StaffAttendanceView.vue') },
  { path: '/staff-payroll', name: 'staff-payroll', component: () => import('../views/StaffPayrollView.vue') },
  { path: '/land', name: 'land', component: () => import('../views/LandView.vue') },
  { path: '/build', name: 'build', component: () => import('../views/BuildView.vue') },
  { path: '/samity', name: 'samity', component: () => import('../views/SamityView.vue') },
  { path: '/fire-safety', name: 'fire-safety', component: () => import('../views/FireSafetyView.vue') },
  { path: '/inspections', name: 'inspections', component: () => import('../views/InspectionsView.vue') },
  { path: '/kyc', name: 'kyc', component: () => import('../views/KycView.vue') },
  { path: '/building-systems', name: 'building-systems', component: () => import('../views/BuildingSystemsView.vue') },
  { path: '/analytics', name: 'analytics', component: () => import('../views/AnalyticsView.vue') },
  // ── Hub dashboards (V2.0.7) — old routes above stay for deep links ──
  { path: '/finance', name: 'finance', component: () => import('../views/FinanceView.vue') },
  { path: '/portfolio', name: 'portfolio', component: () => import('../views/PortfolioView.vue') },
  { path: '/bms', name: 'bms', component: () => import('../views/BmsView.vue') },
  { path: '/community', name: 'community', component: () => import('../views/CommunityView.vue') },
  { path: '/society', name: 'society', component: () => import('../views/SocietyView.vue') },
  { path: '/legal-hub', name: 'legal-hub', component: () => import('../views/LegalHubView.vue') },
  { path: '/secure', name: 'secure', component: () => import('../views/SecureView.vue') },
  { path: '/notifications', name: 'notifications', component: () => import('../views/NotificationsView.vue') },
  { path: '/ai', name: 'ai', component: () => import('../views/AiView.vue') },
  { path: '/portal', name: 'portal', component: () => import('../views/PortalView.vue') },
  { path: '/premium', name: 'premium', component: () => import('../views/PremiumView.vue') },
  { path: '/accounts', name: 'accounts', component: () => import('../views/AccountsView.vue') },
  // ── Module views (custom) ──
  { path: '/settings', name: 'settings', component: () => import('../views/SettingsView.vue') },
  { path: '/reminders', name: 'reminders', component: () => import('../views/RemindersView.vue') },
  { path: '/wiki', name: 'wiki', component: () => import('../views/WikiView.vue') },
  // V2.27: guided first-login setup for new subscribers (full-screen, no shell chrome)
  { path: '/setup', name: 'setup', component: () => import('../views/SetupView.vue'), meta: { setup: true } },
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

// V2.31.4: SPA navigation-race fix. The old guard did
//   if (!data.loaded && !data.loading) await data.bootstrap()
// which RACES when two navigations fire close together (nav A suspends on
// await fetchMe(), nav B starts). Nav B sees data.loading=true (A's bootstrap
// in flight) → skips the await → mounts its view with loaded=false → blank
// content that never re-renders. Fix: share ONE in-flight bootstrap promise so
// every navigation awaits the same completion (mounts only after data is ready).
let bootPromise = null
let mePromise = null

function ensureBootstrap(data) {
  if (data.loaded) return Promise.resolve(true)
  if (!bootPromise) {
    bootPromise = data.bootstrap().finally(() => { bootPromise = null })
  }
  return bootPromise
}

function ensureMe(auth) {
  if (auth.user) return Promise.resolve(true)
  if (!mePromise) {
    mePromise = auth.fetchMe().finally(() => { mePromise = null })
  }
  return mePromise
}

// Auth + data guard: no token → login; token but no data → bootstrap before render.
// Also restore the user identity on reload (token persists in localStorage but auth.user
// is null after a page refresh — without fetchMe every role gate that reads
// auth.user?.role would be off until a fresh login).
router.beforeEach(async (to) => {
  const auth = useAuthStore()
  const data = useDataStore()
  if (to.meta.public) return true
  // Preserve the intended destination (incl. deep-link query like ?gw=&sid= or ?open=)
  // so it survives the login detour — a lost query silently breaks gateway callbacks.
  if (!auth.isAuthed) return { name: 'login', query: { redirect: to.fullPath } }
  try { await ensureMe(auth) } catch (e) { /* bootstrap below still validates the token */ }
  if (!data.loaded) {
    const ok = await ensureBootstrap(data)
    if (!ok && !data.offline) {
      auth.clear()
      return { name: 'login', query: { redirect: to.fullPath } }
    }
  }
  // V2.27: guided setup for new subscribers — an owner workspace with zero
  // properties and no done/skip marker is routed through the /setup wizard
  // on first arrival at the dashboard.
  if (to.name === 'dashboard' && needsSetup(auth, data)) return { name: 'setup' }
  return true
})

// True when the signed-in account is a subscriber owner that has not completed
// (or skipped) the guided setup yet. Backed by the server-side `setup_at`
// marker on the subscriber row (V2.27) — new accounts get an empty marker,
// existing active accounts were backfilled at migration, and completing the
// wizard sets it. `krtaker_onboard_skip` is a per-browser convenience so
// "skip for now" doesn't nag again on the same device.
function needsSetup(auth, data) {
  const u = auth.user || {}
  if ((u.kind || '') !== 'sub') return false
  if (u.role !== 'owner' && u.role !== 'property_owner') return false
  if (auth.isImpersonating) return false
  if (u.setup_at) return false
  try {
    if (localStorage.getItem('krtaker_onboard_done')) return false
    if (localStorage.getItem('krtaker_onboard_skip')) return false
  } catch (e) { return false }
  return true
}

// V2.18: GA4 page-view tracking for the hash router (fires after every
// navigation — full loads, hash changes, and guard redirects alike).
router.afterEach((to) => {
  const title = (typeof to.name === 'string' ? to.name.replace(/-/g, ' ') : 'page')
  pageView(title.charAt(0).toUpperCase() + title.slice(1), to.fullPath)
})

export default router
