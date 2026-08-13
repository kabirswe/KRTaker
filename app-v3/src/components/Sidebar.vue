<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import { ROLES, roleLabel, GROUP_LABEL } from '../lib/roles'
import { getBranding, brandUrl, brandSlotSize, brandTitleOn } from '../api/client'
import { t } from '../lib/i18n'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const data = useDataStore()

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
const GROUPS = [
  { id: 'overview', label: 'Overview', items: [['portal', '🏠', 'My Portal'], ['dashboard', '📊', 'Overview'], ['analytics', '📈', 'Analytics'], ['ai', '🤖', 'AI Caretaker (KR)'], ['wiki', '📚', 'Wiki & Help'], ['backup', '💾', 'Backup & Restore']] },
  { id: 'portfolio', label: 'Portfolio', items: [['portfolio', '🏢', 'Portfolio']] },
  { id: 'finance', label: 'Finance', items: [['finance', '💰', 'Finance']] },
  { id: 'bms', label: 'BMS', items: [['bms', '🔧', 'BMS']] },
  { id: 'community', label: 'Community', items: [['community', '📢', 'Community'], ['society', '🏘️', 'Society']] },
  { id: 'legal', label: 'Legal', items: [['legalhub', '⚖️', 'Legal']] },
  { id: 'ops', label: 'Operations', items: [['vendors', '🧰', 'Vendors']] },
  { id: 'secure', label: 'Safety & Security', items: [['secure', '🏠', 'Safety & Security']] },
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
  samity: '/samity', caretaker: '/dashboard',
}

// Module gating follows the EFFECTIVE user (updates after a real role switch).
// V2.0.6: 'finance' is a frontend alias — shows when the user has ANY finance module.
// V2.0.7: hub aliases show when the user has ANY module inside that hub.
const FINANCE_MODS = ['invoices', 'receipts', 'payments', 'recon', 'taxes', 'remit', 'statements', 'subscriptions', 'accounts', 'receive', 'expense', 'withdraw', 'deposit', 'reconcile']
const PORTFOLIO_MODS = ['properties', 'units', 'tenants', 'leases', 'insurance', 'onboarding', 'leads', 'documents', 'templates']
const BMS_MODS = ['maintenance', 'gate', 'staff', 'attendance', 'payroll', 'meter', 'utilities', 'samity']
const COMMUNITY_MODS = ['notices', 'referrals', 'trust', 'support']
const LEGAL_MODS = ['compliance', 'legal', 'cases', 'concierge']
const SECURE_MODS = ['smarthome', 'land', 'build', 'firesafety', 'kyc', 'inspections', 'health', 'systems', 'nrb']
const SOCIETY_MODS = ['parking', 'bookings', 'voting', 'forums', 'events']
const HUB_MODS = { finance: FINANCE_MODS, portfolio: PORTFOLIO_MODS, bms: BMS_MODS, community: COMMUNITY_MODS, legalhub: LEGAL_MODS, secure: SECURE_MODS, society: SOCIETY_MODS }
const can = (mod) => {
  const user = auth.user || data.user
  if (!user) return true
  // Wiki/Help is available to every role — no module gate.
  if (mod === 'wiki') return true
  const mods = user.role_modules ? (user.role_modules[user.role] || user.modules || []) : []
  if (HUB_MODS[mod]) return mods.some(m => HUB_MODS[mod].includes(m))
  return mods.includes(mod)
}

const groups = computed(() =>
  GROUPS.map(g => ({ ...g, label: t(g.label), items: g.items.filter(i => can(i[0])) })).filter(g => g.items.length)
)

// Subordinate-only roles (strictly below the signed-in user's rank)
const roles = computed(() => ROLES.filter(r => auth.canSwitchTo(r.id)))
const roleGroups = computed(() => {
  const map = {}
  roles.value.forEach(r => { (map[r.group] = map[r.group] || []).push(r) })
  return Object.entries(map)
})

const initials = computed(() => {
  const n = (data.user || auth.user)?.name || ''
  return n.replace(/[^A-Za-z]/g, '').slice(0, 2).toUpperCase()
})
const roleName = computed(() => t(roleLabel((data.user || auth.user)?.role)))

const openRoles = ref(false)
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
async function pick(r) {
  if (switching.value) return
  switching.value = true
  try {
    const res = await auth.viewAs(r.email)
    if (!res.ok) {
      window.__krToast?.('❌ ' + (res.error || t('Switch failed')))
      return
    }
    await data.bootstrap()
    data.setPreviewRole(r.id)
    window.__krToast?.(auth.isImpersonating ? `👁 ${t('Viewing as')} ${r.role}` : t('Switched to') + ' ' + t(r.role))
  } finally {
    switching.value = false
    openRoles.value = false
    emit('close')
  }
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
        <div v-for="i in g.items" :key="i[0]" class="sb-item" :class="{ active: activeFor(i[0]) }" @click="go(i[0])">
          <span class="ic">{{ i[1] }}</span>{{ t(i[2]) }}
        </div>
      </template>
    </div>
    <div class="sb-bottom">
      <div class="role-card">
        <div class="role-ava">{{ initials }}</div>
        <div>
          <div class="rc-name">{{ (data.user || auth.user)?.name }}</div>
          <div class="rc-role">{{ roleName }}<span v-if="auth.isImpersonating" style="color:var(--warn)"> · 👁</span></div>
        </div>
      </div>
      <template v-if="auth.isImpersonating">
        <button class="role-switch-btn" style="background:var(--primary-light);color:var(--primary-dark);border:1px solid var(--primary)" :disabled="switching" @click="backToMe()">↩ {{ t('Back to my account') }}</button>
      </template>
      <button v-else class="role-switch-btn" @click="openRoles = true">🔀 {{ t('Switch role') }}</button>
    </div>

    <!-- Subordinate role switch modal -->
    <div v-if="openRoles" class="overlay" @click.self="openRoles = false">
      <div class="modal">
        <div class="modal-h"><span class="t">🔀 {{ t('Switch role') }}</span><button class="close" @click="openRoles = false">✕</button></div>
        <div v-if="switching" style="padding:20px;text-align:center;color:var(--text-mute)">{{ t('Loading') }}</div>
        <div v-else class="role-grid">
          <template v-for="([g, items]) in roleGroups" :key="g">
            <div v-if="roleGroups.length > 1" style="grid-column:1/-1;font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--text-mute);margin-top:4px">{{ t(GROUP_LABEL[g] || g) }}</div>
            <div v-for="r in items" :key="r.id" class="role-opt" :class="{ active: r.id === (data.user || auth.user)?.role }" @click="pick(r)">
              <div class="ro-ic">{{ r.ico }}</div>
              <div class="ro-t">{{ t(r.role) }}</div>
              <div class="ro-d">{{ t(r.desc) }}</div>
            </div>
          </template>
        </div>
      </div>
    </div>
  </aside>
</template>
