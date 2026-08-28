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
const GROUPS = [
  { id: 'mall', label: 'Mall Management', items: [['mall', '🏬', 'Mall Management', 'Shops, service charges, elec/water meters, collections, expenses, complaints, assets, notices, audit & ledger for shopping malls and commercial buildings']] },
  { id: 'help', label: 'Help', items: [['wiki', '📚', 'Wiki & Help', 'Product guide, feature walkthroughs, FAQs and troubleshooting help'], ['backup', '💾', 'Backup & Restore', 'Download a full JSON backup of your workspace data or restore from a previous export']] },
]

const VIEW_ROUTES = {
  mall: '/mall', wiki: '/wiki', backup: '/backup',
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
  else router.push({ path: '/mall', query: { stub: view } })
  emit('close')
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
        <div v-for="i in g.items" :key="i[0]" class="sb-item" :class="{ active: activeFor(i[0]) }" @click="go(i[0])"
             @mouseenter="tipEnter($event, i)" @mouseleave="tipLeave" @focus="tipEnter($event, i)" @blur="tipLeave">
          <span class="ic">{{ i[1] }}</span><span class="lbl">{{ t(i[2]) }}</span>
        </div>
      </template>
    </div>
    <div class="sb-bottom">
      <!-- V2.37: user identity card removed — show software credit & version only -->
      <div class="sb-credit">
        <div class="sc-brand">{{ sbLogo.mark }} Mall Manager</div>
        <div class="sc-ver">v1.0 · Mall & Commercial Edition</div>
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
