<script setup>
import { computed, ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const data = useDataStore()

const props = defineProps({ open: Boolean })
const emit = defineEmits(['close'])

// Same GROUPS as dashboard-v2
const GROUPS = [
  { id: 'overview', label: 'Overview', items: [['dashboard', '📊', 'Overview'], ['analytics', '📈', 'Analytics'], ['ai', '🤖', 'AI Caretaker (KR)']] },
  { id: 'portfolio', label: 'Portfolio', items: [['properties', '🏢', 'Properties'], ['units', '🚪', 'Units'], ['tenants', '👤', 'Tenants'], ['leases', '📄', 'Leases'], ['onboarding', '📋', 'Onboarding'], ['leads', '📥', 'Leads'], ['documents', '📁', 'Documents'], ['templates', '🗂️', 'Templates']] },
  { id: 'finance', label: 'Finance', items: [['invoices', '🧾', 'Invoices'], ['receipts', '📎', 'Receipts'], ['payments', '💳', 'Payments'], ['taxes', '🏛️', 'Taxes'], ['statements', '💰', 'Statements'], ['remit', '🌍', 'Remittances'], ['nrb', '🌐', 'NRB Hub'], ['subscriptions', '💠', 'Subscriptions']] },
  { id: 'community', label: 'Community', items: [['notices', '📢', 'Notice Board'], ['referrals', '🤝', 'Referrals'], ['trust', '🪪', 'Trust Engine']] },
  { id: 'ops', label: 'Operations', items: [['maintenance', '🔧', 'Maintenance'], ['vendors', '🧰', 'Vendors'], ['utilities', '🔌', 'Utilities'], ['compliance', '⚖️', 'Compliance'], ['legal', '📜', 'Legal Engine']] },
  { id: 'secure', label: 'Safety & Security', items: [['smarthome', '🔐', 'Smart Home'], ['land', '🛰️', 'Land Guard'], ['build', '🏗️', 'Build Watch'], ['gate', '🚪', 'Gate Watch'], ['firesafety', '🧯', 'Fire Safety'], ['systems', '⚙️', 'Systems Watch'], ['staffwatch', '👷', 'Staff Watch'], ['health', '🌦️', 'Health Check']] },
  { id: 'admin', label: 'Admin', items: [['caretaker', '👑', 'Caretaker']] },
]

// Ported views get real routes; not-yet-ported modules show the stub banner.
const VIEW_ROUTES = {
  dashboard: '/dashboard', properties: '/properties', units: '/units', tenants: '/tenants',
  leases: '/leases', invoices: '/invoices', receipts: '/receipts', payments: '/payments',
  maintenance: '/maintenance', vendors: '/vendors', notices: '/notices',
}

const ROLES = [
  { id: 'owner', role: 'Property Owner', ico: '🏠', desc: 'Portfolio-wide view across every building' },
  { id: 'manager', role: 'Property Manager', ico: '🗝️', desc: 'Day-to-day ops on assigned properties' },
  { id: 'tenant', role: 'Tenant', ico: '🔑', desc: 'Invoices, receipts, repairs — your side' },
  { id: 'partner', role: 'Service Partner', ico: '🛠️', desc: 'Jobs, QC feedback, payouts' },
  { id: 'svc_mgr', role: 'Service Manager', ico: '✅', desc: 'Quality control & SLA across partners' },
  { id: 'legal', role: 'Legal Counsel', ico: '⚖️', desc: 'Registrations, PRCA cases, compliance docket' },
  { id: 'crm', role: 'CRM & Help Desk', ico: '🎧', desc: 'Tickets, CSAT, tenant onboarding, leads' },
  { id: 'accountant', role: 'Accountant', ico: '💰', desc: 'Cash flow, TDS, invoices, aging' },
  { id: 'hr', role: 'HR & Admin', ico: '👥', desc: 'Staff, onboarding, org admin' },
]

const can = (mod) => {
  const user = data.user || auth.user
  if (!user) return true
  if (user.role_modules) return (user.role_modules[data.previewRole] || []).includes(mod)
  return true
}

const groups = computed(() =>
  GROUPS.map(g => ({ ...g, items: g.items.filter(i => can(i[0])) })).filter(g => g.items.length)
)

// Subordinate-only roles (strictly below the signed-in user's rank)
const roles = computed(() => ROLES.filter(r => auth.canSwitchTo(r.id)))

const initials = computed(() => {
  const n = (data.user || auth.user)?.name || ''
  return n.replace(/[^A-Za-z]/g, '').slice(0, 2).toUpperCase()
})

const openRoles = ref(false)

function go(view) {
  const r = VIEW_ROUTES[view]
  if (r) router.push(r)
  else router.push({ path: '/dashboard', query: { stub: view } })
  emit('close')
}
function pick(r) {
  data.setPreviewRole(r.id)
  openRoles.value = false
  emit('close')
  if (typeof window.__krToast === 'function') window.__krToast('Switched to ' + r.role)
}
</script>

<template>
  <aside class="sidebar" :class="{ open }">
    <div class="sb-logo">
      <div class="logo-mark">KR</div>
      <div class="brand">KRTaker<small>Key Responsibility Taker</small></div>
    </div>
    <div class="sb-scroll">
      <template v-for="g in groups" :key="g.id">
        <div class="sb-group">{{ g.label }}</div>
        <div v-for="i in g.items" :key="i[0]" class="sb-item" :class="{ active: route.path === (VIEW_ROUTES[i[0]] || '') }" @click="go(i[0])">
          <span class="ic">{{ i[1] }}</span>{{ i[2] }}
        </div>
      </template>
    </div>
    <div class="sb-bottom">
      <div class="role-card">
        <div class="role-ava">{{ initials }}</div>
        <div>
          <div class="rc-name">{{ (data.user || auth.user)?.name }}</div>
          <div class="rc-role">{{ data.previewRole }}</div>
        </div>
      </div>
      <button class="role-switch-btn" @click="openRoles = true">🔀 Switch role</button>
    </div>

    <!-- Subordinate role switch modal -->
    <div v-if="openRoles" class="overlay" @click.self="openRoles = false">
      <div class="modal">
        <div class="modal-h"><span class="t">🔀 Switch to subordinate user</span><button class="close" @click="openRoles = false">✕</button></div>
        <div class="role-grid">
          <div v-for="r in roles" :key="r.id" class="role-opt" :class="{ active: r.id === data.previewRole }" @click="pick(r)">
            <div class="ro-ic">{{ r.ico }}</div>
            <div class="ro-t">{{ r.role }}</div>
            <div class="ro-d">{{ r.desc }}</div>
          </div>
        </div>
      </div>
    </div>
  </aside>
</template>
