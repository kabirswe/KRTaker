<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import { roleLabel } from '../lib/roles'
import { getBranding, brandUrl, brandSlotSize, brandTitleOn } from '../api/client'
import { t } from '../lib/i18n'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const data = useDataStore()

// V2.37: software version shown in the sidebar credit block (kept in sync with package.json).
const APP_VERSION = '2.37'

// ── Dynamic branding (app-theme): dash_header logo (dark variant), site name,
// brand colors. Falls back to the classic "KR" mark + KRTaker text.
const brand = ref({})
const dark = ref(false)
let themeObs = null
function syncTheme() { dark.value = (document.documentElement.getAttribute('data-theme') === 'dark') }
async function loadBranding() { brand.value = await getBranding() }
const sbLogo = computed(() => {
  const b = brand.value
  const img = dark.value ? (b.dash_header_dark || b.dash_header) : b.dash_header
  return {
    img: img ? brandUrl(img) : '',
    h: brandSlotSize(b, 'dash_header', 38),
    showTitle: brandTitleOn(b, 'dash_header'),
    name: b.site_name || 'KRTaker',
    mark: b.logo_text || 'KR',
    grad: b.primary ? ('linear-gradient(135deg,' + b.primary + ',' + (b.secondary || b.primary) + ')') : '',
  }
})
onMounted(() => {
  loadBranding()
  syncTheme()
  themeObs = new MutationObserver(syncTheme)
  themeObs.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] })
})
onBeforeUnmount(() => { if (themeObs) themeObs.disconnect() })

const props = defineProps({ open: Boolean })
const emit = defineEmits(['close'])

// Same GROUPS as dashboard-v2 (v3 consolidation: legal cluster grouped, vendor finance merged into Vendors workspace)
// V2.0.6: Finance + Accounts groups merged into ONE Finance hub (/finance with tabs) — fewer menu entries.
// V2.0.7: Portfolio, BMS, Community, Legal, Safety&Security all merged into hub dashboards with tabs.
// V2.37: each menu item carries a detailed tooltip (desc) shown on hover.
const GROUPS = [
  { id: 'overview', label: 'Overview', items: [['portal', '🏠', 'My Portal', 'Tenant-facing portal: leases, invoices, payments, tickets and documents for your rental units'], ['dashboard', '📊', 'Overview', 'Key metrics at a glance — occupancy, collections, dues, maintenance and alerts for your portfolio'], ['analytics', '📈', 'Analytics', 'Business intelligence: P&L, cashflow, occupancy, aging, expenses, maintenance and board reports'], ['ai', '🤖', 'AI Caretaker (KR)', 'Ask KR anything — vacancy checks, rent summaries, notices, renewals and portfolio insights in plain language'], ['wiki', '📚', 'Wiki & Help', 'Product guide, feature walkthroughs, FAQs and troubleshooting help'], ['backup', '💾', 'Backup & Restore', 'Download a full JSON backup of your workspace data or restore from a previous export']] },
  { id: 'portfolio', label: 'Portfolio', items: [['portfolio', '🏢', 'Portfolio', 'All properties, units, tenants, leases, insurance, leads, documents and templates in one hub']] },
  { id: 'finance', label: 'Finance', items: [['finance', '💰', 'Finance', 'Invoices, receipts, payments, taxes, remittances, statements, subscriptions and accounts in one hub']] },
  { id: 'bms', label: 'BMS', items: [['bms', '🔧', 'BMS', 'Building management: maintenance, staff, attendance, payroll, meters, utilities and gate access']] },
  { id: 'community', label: 'Community', items: [['community', '📢', 'Community', 'Notices, referrals, NID trust, support tickets and society features for your residents'], ['society', '🏘️', 'Society', 'Society management: parking, bookings, voting, forums, events and samity accounts']] },
  { id: 'legal', label: 'Legal', items: [['legalhub', '⚖️', 'Legal', 'Compliance, legal cases, concierge services and NID verifications in one hub']] },
  { id: 'ops', label: 'Operations', items: [['vendors', '🧰', 'Vendors', 'Manage service vendors, vendor payouts, ratings and maintenance contracts']] },
  { id: 'secure', label: 'Safety & Security', items: [['secure', '🏠', 'Safety & Security', 'Smart locks, CCTV, land records, construction, fire safety, health and building systems']] },
]

const VIEW_ROUTES = {
  dashboard: '/dashboard', analytics: '/analytics', ai: '/ai', finance: '/finance', portal: '/portal',
  portfolio: '/portfolio', bms: '/bms', community: '/community', society: '/society', legalhub: '/legal-hub', secure: '/secure',
  properties: '/properties', units: '/units', tenants: '/tenants', leases: '/leases', insurance: '/insurance',
  onboarding: '/onboarding', leads: '/leads', documents: '/documents', templates: '/templates',
  invoices: '/invoices', receipts: '/receipts', payments: '/payments',
  taxes: '/holding-taxes', remit: '/remittances', recon: '/collections', statements: '/statements', subscriptions: '/premium',
  accounts: '/accounts', receive: { path: '/accounts', query: { tab: 'receive' } }, expense: { path: '/accounts', query: { tab: 'expense' } },
  withdraw: { path: '/accounts', query: { tab: 'withdraw' } }, deposit: { path: '/accounts', query: { tab: 'deposit' } },
  reconcile: { path: '/accounts', query: { tab: 'reconcile' } },
  notices: '/notices', referrals: '/referrals', trust: '/nid', support: '/support', wiki: '/wiki',
  maintenance: '/maintenance', vendors: '/vendors', utilities: '/utility-bills',
  staff: '/staff', attendance: '/staff-attendance', payroll: '/staff-payroll', meter: '/meter-readings',
  compliance: '/compliance', legal: '/legal', cases: '/cases', concierge: '/concierge',
  smart: '/building-systems', smarthome: '/building-systems', land: '/land', build: '/build', gate: '/gate-visits',
  firesafety: '/fire-safety', staffwatch: '/staff-attendance',
  samity: '/society?tab=samity', caretaker: '/dashboard',
}

// Module gating follows the EFFECTIVE user (updates after a real role switch).
// V2.0.6: 'finance' is a frontend alias — shows when the user has ANY finance module.
// V2.0.7: hub aliases show when the user has ANY module inside that hub.
// V2.39.3: tenants never see the owner hub menus — invoices/maintenance live in
// My Portal + Overview instead; Society is gated by the owner's plan modules.
const TENANT_HIDDEN = new Set(['finance', 'bms', 'portfolio', 'legalhub', 'analytics'])
const FINANCE_MODS = ['invoices', 'receipts', 'payments', 'recon', 'taxes', 'remit', 'statements', 'subscriptions', 'accounts', 'receive', 'expense', 'withdraw', 'deposit', 'reconcile']
const PORTFOLIO_MODS = ['properties', 'units', 'tenants', 'leases', 'insurance', 'onboarding', 'leads', 'documents', 'templates']
const BMS_MODS = ['maintenance', 'gate', 'staff', 'attendance', 'payroll', 'meter', 'utilities']
const COMMUNITY_MODS = ['notices', 'referrals', 'trust', 'support']
const LEGAL_MODS = ['compliance', 'legal', 'cases', 'concierge']
const SECURE_MODS = ['smarthome', 'land', 'build', 'firesafety', 'kyc', 'inspections', 'health', 'systems', 'nrb']
const SOCIETY_MODS = ['parking', 'bookings', 'voting', 'forums', 'events', 'samity']
const HUB_MODS = { finance: FINANCE_MODS, portfolio: PORTFOLIO_MODS, bms: BMS_MODS, community: COMMUNITY_MODS, legalhub: LEGAL_MODS, secure: SECURE_MODS, society: SOCIETY_MODS }
const can = (mod) => {
  const user = auth.user || data.user
  if (!user) return true
  // Wiki/Help is available to every role — no module gate.
  if (mod === 'wiki') return true
  // V2.39.3: tenant role is portal-scoped — owner hub menus are hidden entirely.
  if (user.role === 'tenant' && TENANT_HIDDEN.has(mod)) return false
  const mods = user.role_modules ? (user.role_modules[user.role] || user.modules || []) : []
  if (HUB_MODS[mod]) return mods.some(m => HUB_MODS[mod].includes(m))
  return mods.includes(mod)
}

const groups = computed(() =>
  GROUPS.map(g => ({ ...g, label: t(g.label), items: g.items.filter(i => can(i[0])) })).filter(g => g.items.length)
)

// V2.39.3: the sb-bottom role-switch button/modal was removed — subordinate
// switching lives in the topbar user menu (tb-user chip). Only the
// impersonation "back to my account" escape hatch remains in the sidebar.
const switching = ref(false)

const go = (view) => {
  const r = VIEW_ROUTES[view]
  if (r) router.push(r)
  else router.push({ path: '/dashboard', query: { stub: view } })
  emit('close')
}
// Active highlight: string routes compare path; query-tab routes (Accounts submenu) also require the tab
const activeFor = (view) => {
  const r = VIEW_ROUTES[view]
  if (typeof r === 'string') return route.path === r
  if (!r) return false
  return route.path === r.path && (r.query?.tab ? route.query.tab === r.query.tab : !route.query.tab)
}
async function backToMe() {
  switching.value = true
  try {
    await auth.backToMe()
    await data.bootstrap()
    data.setPreviewRole(auth.user?.role || 'owner')
    window.__krToast?.('Back to ' + roleLabel(auth.user?.role))
  } finally {
    switching.value = false
  }
}
</script>

<template>
  <aside class="sidebar" :class="{ open }">
    <div class="sb-logo">
      <img v-if="sbLogo.img" :src="sbLogo.img" :alt="sbLogo.name" class="sb-logo-img" :style="{ height: sbLogo.h + 'px' }">
      <div v-else class="logo-mark" :style="sbLogo.grad ? { background: sbLogo.grad } : {}">{{ sbLogo.mark }}</div>
      <div class="brand" v-if="!sbLogo.img || sbLogo.showTitle">{{ sbLogo.name }}<small>Key Responsibility Taker</small></div>
    </div>
    <div class="sb-scroll">
      <template v-for="g in groups" :key="g.id">
        <div class="sb-group">{{ g.label }}</div>
        <div v-for="i in g.items" :key="i[0]" class="sb-item" :class="{ active: activeFor(i[0]) }" @click="go(i[0])" :title="i[3] || t(i[2])">
          <span class="ic">{{ i[1] }}</span>{{ t(i[2]) }}
        </div>
      </template>
    </div>
    <div class="sb-bottom">
      <!-- V2.37: user identity card removed — show software credit & version only -->
      <div class="sb-credit">
        <div class="sc-brand">{{ sbLogo.mark }} KRTaker</div>
        <div class="sc-ver">v{{ APP_VERSION }} · Key Responsibility Taker</div>
        <div class="sc-copy">© {{ new Date().getFullYear() }} KRTaker — managed buildings, made simple</div>
      </div>
      <template v-if="auth.isImpersonating">
        <button class="role-switch-btn" style="background:var(--primary-light);color:var(--primary-dark);border:1px solid var(--primary)" :disabled="switching" @click="backToMe()">↩ {{ t('Back to my account') }}</button>
      </template>
    </div>
  </aside>
</template>
