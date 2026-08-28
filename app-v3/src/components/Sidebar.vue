<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue'
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
    name: b.site_name || 'Mall Manager',
    mark: b.logo_text || 'MM',
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

// Mall Manager Edition — nav trimmed to the committee product only.
// (KRTaker residential modules — portfolio, finance, BMS, community, legal,
//  safety & security — are intentionally NOT exposed here.)
// V2.0: all Mall tabs live IN the sidebar, organised in sub-groups.
const GROUPS = [
  {
    id: 'mall', label: 'Mall Management',
    groups: [
      { sub: 'Overview', ico: '📊', items: [
        ['dashboard', '📊', 'Dashboard', 'Collections, outstanding, expenses & budget for the month'],
        ['ledger', '📒', 'Ledger', 'Per-space paid vs billed, by-kind summary, DESCO/WASA custodial reconciliation'],
      ]},
      { sub: 'Spaces & Owners', ico: '🏪', items: [
        ['space', '🏪', 'Spaces', 'All commercial spaces — owners, space types, occupancy, service rates'],
        ['owners', '🏢', 'Owners', 'Persons & entities who own spaces; multi-space portfolios'],
        ['rent', '🧾', 'Rent & Tenants', 'Tenant profiles, rental agreements & optional rent collection'],
      ]},
      { sub: 'Billing', ico: '🧾', items: [
        ['bills', '🧾', 'Bills & Collections', 'Monthly service-charge bills, collections, receipts & late fees'],
        ['meters', '⚡', 'Meters', 'Sub-meter readings → automatic electricity / water bills'],
      ]},
      { sub: 'Accounting', ico: '🏦', items: [
        ['coa', '🏦', 'Chart of Accounts', 'Account list by type with balances'],
        ['journal', '📖', 'Journal', 'Debit / credit journal entries'],
        ['trial', '⚖️', 'Trial Balance', 'Balanced debit vs credit summary'],
        ['pnl', '📊', 'P&L Statement', 'Monthly income statement (auto-posted)'],
      ]},
      { sub: 'Operations', ico: '📉', items: [
        ['expenses', '📉', 'Expenses', 'Lift, DESCO/WASA, security, salaries & other spending'],
        ['complaints', '🔧', 'Complaints', 'Space owner issues — open → in progress → resolved'],
        ['assets', '🛠️', 'Assets & AMC', 'Mall assets with AMC & warranty tracking'],
        ['vendors', '🧰', 'Vendors', 'Vendor profiles, ledgers & payment tracking'],
      ]},
      { sub: 'Governance', ico: '🏛️', items: [
        ['committee', '🏛️', 'Committee', 'Bearers, meeting register & resolutions'],
        ['notices', '📢', 'Notices', 'Committee announcements to all owners'],
        ['staff', '🧑‍💼', 'Staff', 'Office staff & security guards, salaries'],
        ['users', '👥', 'Users & Roles', 'System users, roles & the access matrix'],
      ]},
      { sub: 'System', ico: '⚙️', items: [
        ['audit', '📋', 'Audit', 'Who did what, when'],
        ['settings', '⚙️', 'Settings', 'Property profile, billing rules, invoice & license'],
      ]},
    ],
  },
  { id: 'help', label: 'Help', items: [['wiki', '📚', 'Wiki & Help', 'Product guide, feature walkthroughs, FAQs and troubleshooting help'], ['backup', '💾', 'Backup & Restore', 'Download a full JSON backup of your workspace data or restore from a previous export']] },
]

const VIEW_ROUTES = {
  mall: '/mall',
  dashboard: { path: '/mall', query: { tab: 'dashboard' } },
  space: { path: '/mall', query: { tab: 'space' } },
  bills: { path: '/mall', query: { tab: 'bills' } },
  meters: { path: '/mall', query: { tab: 'meters' } },
  expenses: { path: '/mall', query: { tab: 'expenses' } },
  complaints: { path: '/mall', query: { tab: 'complaints' } },
  assets: { path: '/mall', query: { tab: 'assets' } },
  notices: { path: '/mall', query: { tab: 'notices' } },
  audit: { path: '/mall', query: { tab: 'audit' } },
  staff: { path: '/mall', query: { tab: 'staff' } },
  users: { path: '/mall', query: { tab: 'users' } },
  committee: { path: '/mall', query: { tab: 'committee' } },
  owners: { path: '/mall', query: { tab: 'owners' } },
  rent: { path: '/mall', query: { tab: 'rent' } },
  vendors: { path: '/mall', query: { tab: 'vendors' } },
  coa: { path: '/mall', query: { tab: 'coa' } },
  journal: { path: '/mall', query: { tab: 'journal' } },
  trial: { path: '/mall', query: { tab: 'trial' } },
  pnl: { path: '/mall', query: { tab: 'pnl' } },
  settings: { path: '/mall', query: { tab: 'settings' } },
  wiki: '/wiki', backup: '/backup',
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
const MALL_TABS = new Set(['dashboard', 'space', 'bills', 'meters', 'coa', 'journal', 'trial', 'pnl', 'expenses', 'complaints', 'assets', 'notices', 'audit', 'staff', 'users', 'committee', 'owners', 'rent', 'vendors', 'ledger', 'settings'])
const can = (mod) => {
  const user = auth.user || data.user
  if (!user) return true
  // Wiki/Help is available to every role — no module gate.
  if (mod === 'wiki') return true
  // Mall Manager tabs are always available to every mall role (the role
  // matrix gates ACTIONS, not navigation).
  if (MALL_TABS.has(mod)) return true
  // V2.39.3: tenant role is portal-scoped — owner hub menus are hidden entirely.
  if (user.role === 'tenant' && TENANT_HIDDEN.has(mod)) return false
  const mods = user.role_modules ? (user.role_modules[user.role] || user.modules || []) : []
  if (HUB_MODS[mod]) return mods.some(m => HUB_MODS[mod].includes(m))
  return mods.includes(mod)
}

const groups = computed(() =>
  GROUPS.map(g => ({
    ...g,
    label: t(g.label),
    items: g.items ? g.items.filter(i => can(i[0])) : undefined,
    groups: g.groups
      ? g.groups.map(sg => ({ ...sg, items: sg.items.filter(i => can(i[0])) })).filter(sg => sg.items.length)
      : undefined,
  })).filter(g => g.items ? g.items.length : (g.groups && g.groups.length))
)

// V2.39.3: the sb-bottom role-switch button/modal was removed — subordinate
// switching lives in the topbar user menu (tb-user chip). Only the
// impersonation "back to my account" escape hatch remains in the sidebar.
const switching = ref(false)

const go = (view) => {
  const r = VIEW_ROUTES[view]
  if (r) router.push(r)
  else router.push({ path: '/mall', query: { stub: view } })
  emit('close')
}

/* ── collapsible sub-groups (accordion: the ACTIVE sub-group stays open) ── */
const openSub = ref(0)   // index of the expanded sub-group (-1 = all collapsed)
const activeSub = computed(() => {
  const t = route.query.tab
  const g = groups.value.find(x => x.groups)
  if (!g) return 0
  const idx = g.groups.findIndex(sg => sg.items.some(i => i[0] === t))
  return idx === -1 ? 0 : idx
})
watch(() => route.query.tab, () => { openSub.value = activeSub.value }, { immediate: true })
function toggleSub(i) { openSub.value = openSub.value === i ? -1 : i }
/* rail click: expand the sidebar, open that sub-group, go to its first tab */
function railOpen(si) {
  const g = groups.value.find(x => x.groups)
  if (!g || !g.groups[si]) return
  collapsed.value = false
  openSub.value = si
  go(g.groups[si].items[0][0])
}

/* ── collapsible sidebar (persisted) ── */
const collapsed = ref((() => { try { return localStorage.getItem('mm_sb_collapsed') === '1' } catch (e) { return false } })())
function toggleCollapsed() {
  collapsed.value = !collapsed.value
  try { localStorage.setItem('mm_sb_collapsed', collapsed.value ? '1' : '0') } catch (e) {}
}

/* ── advanced tooltips ── */
const tip = ref({ show: false, x: 0, y: 0, title: '', desc: '', ico: '' })
let tipTimer = null
function tipEnter(e, item) {
  const el = e.currentTarget
  const rect = el.getBoundingClientRect()
  const label = item[2]
  const desc = item[3] || ''
  clearTimeout(tipTimer)
  tipTimer = setTimeout(() => {
    tip.value = {
      show: true,
      x: rect.right + 12,
      y: Math.min(rect.top + rect.height / 2, window.innerHeight - 120),
      title: label,
      desc,
      ico: item[1],
      rightSide: true,
    }
  }, 280)
}
function tipLeave() { clearTimeout(tipTimer); tip.value = { ...tip.value, show: false } }
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
  <aside class="sidebar" :class="{ open, collapsed }">
    <div class="sb-logo">
      <img v-if="sbLogo.img" :src="sbLogo.img" :alt="sbLogo.name" class="sb-logo-img" :style="{ height: sbLogo.h + 'px' }">
      <div v-else class="logo-mark" :style="sbLogo.grad ? { background: sbLogo.grad } : {}">{{ sbLogo.mark }}</div>
      <div class="brand" v-if="!sbLogo.img || sbLogo.showTitle">{{ sbLogo.name }}<small>Mall & Commercial Building Management</small></div>
      <button class="sb-collapse" @click="toggleCollapsed" :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'" :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'">{{ collapsed ? '⏵' : '⏴' }}</button>
    </div>
    <div class="sb-scroll">
      <template v-for="g in groups" :key="g.id">
        <div class="sb-group">{{ g.label }}</div>
        <!-- collapsed rail: ALL sub-groups as icons (click → expand + open) -->
        <div v-if="collapsed && g.groups" v-for="(sg, si) in g.groups" :key="si" class="sb-item" :class="{ active: activeSub === si }" @click="railOpen(si)" @mouseenter="tipEnter($event, [sg.ico || sg.items[0][1], sg.ico || sg.items[0][1], sg.sub, sg.items.map(i => i[2]).join(' · ')])" @mouseleave="tipLeave" @focus="tipEnter($event, [sg.ico || sg.items[0][1], sg.ico || sg.items[0][1], sg.sub, sg.items.map(i => i[2]).join(' · ')])" @blur="tipLeave">
          <span class="ic">{{ sg.ico || sg.items[0][1] }}</span><span class="lbl">{{ sg.sub }}</span>
        </div>
        <!-- expanded: collapsible sub-groups + leaf items -->
        <template v-else-if="g.groups">
          <template v-for="(sg, si) in g.groups" :key="si">
            <div class="sb-sub" :class="{ open: openSub === si, active: activeSub === si }" @click="toggleSub(si)" :title="(openSub === si ? 'Collapse' : 'Expand') + ' ' + sg.sub">
              <span>{{ sg.sub }}</span><span class="sb-caret">{{ openSub === si ? '▾' : '▸' }}</span>
            </div>
            <template v-if="openSub === si">
              <div v-for="i in sg.items" :key="i[0]" class="sb-item" :class="{ active: activeFor(i[0]) }" @click="go(i[0])"
                   @mouseenter="tipEnter($event, i)" @mouseleave="tipLeave" @focus="tipEnter($event, i)" @blur="tipLeave">
                <span class="ic">{{ i[1] }}</span><span class="lbl">{{ t(i[2]) }}</span>
              </div>
            </template>
          </template>
        </template>
        <!-- flat groups (Help) -->
        <div v-else v-for="i in g.items" :key="i[0]" class="sb-item" :class="{ active: activeFor(i[0]) }" @click="go(i[0])"
             @mouseenter="tipEnter($event, i)" @mouseleave="tipLeave" @focus="tipEnter($event, i)" @blur="tipLeave">
          <span class="ic">{{ i[1] }}</span><span class="lbl">{{ t(i[2]) }}</span>
        </div>
      </template>
    </div>
    <div class="sb-bottom">
      <!-- V2.37: user identity card removed — show software credit & version only -->
      <div class="sb-credit">
        <div class="sc-brand">{{ sbLogo.mark }} Mall Manager</div>
        <div class="sc-ver">v2.0 · Mall & Commercial Edition</div>
        <div class="sc-copy">© {{ new Date().getFullYear() }} Mall Manager — dedicated on-premise / online software</div>
      </div>
      <template v-if="auth.isImpersonating">
        <button class="role-switch-btn" style="background:var(--primary-light);color:var(--primary-dark);border:1px solid var(--primary)" :disabled="switching" @click="backToMe()">↩ {{ t('Back to my account') }}</button>
      </template>
    </div>
    <!-- Advanced tooltip -->
    <div v-if="tip.show" class="sb-tooltip" :style="{ left: tip.x + 'px', top: tip.y + 'px' }">
      <div class="tt-title">{{ tip.ico }} {{ tip.title }}</div>
      <div v-if="tip.desc" class="tt-desc">{{ tip.desc }}</div>
    </div>
  </aside>
</template>
