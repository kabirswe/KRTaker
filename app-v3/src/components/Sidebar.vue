<script setup>
import { computed, ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import { ROLES, roleLabel, GROUP_LABEL } from '../lib/roles'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const data = useDataStore()

const props = defineProps({ open: Boolean })
const emit = defineEmits(['close'])

// Same GROUPS as dashboard-v2 (v3 consolidation: legal cluster grouped, vendor finance merged into Vendors workspace)
const GROUPS = [
  { id: 'overview', label: 'Overview', items: [['dashboard', '📊', 'Overview'], ['analytics', '📈', 'Analytics'], ['ai', '🤖', 'AI Caretaker (KR)']] },
  { id: 'portfolio', label: 'Portfolio', items: [['properties', '🏢', 'Properties'], ['units', '🚪', 'Units'], ['tenants', '👤', 'Tenants'], ['leases', '📄', 'Leases'], ['insurance', '🛡️', 'Insurance'], ['onboarding', '📋', 'Onboarding'], ['leads', '📥', 'Leads'], ['documents', '📁', 'Documents'], ['templates', '🗂️', 'Templates']] },
  { id: 'finance', label: 'Finance', items: [['invoices', '🧾', 'Invoices'], ['receipts', '📎', 'Receipts'], ['payments', '💳', 'Payments'], ['recon', '📮', 'Collections'], ['taxes', '🏛️', 'Holding Taxes'], ['remit', '🌍', 'Remittances'], ['statements', '💰', 'Statements']] },
  { id: 'bms', label: 'BMS', items: [['maintenance', '🔧', 'Maintenance'], ['gate', '🚪', 'Gate Visits'], ['staff', '👷', 'Staff'], ['attendance', '⏱️', 'Attendance'], ['payroll', '💵', 'Payroll'], ['meter', '⚡', 'Meter Readings'], ['utilities', '🔌', 'Utility Bills'], ['samity', '🏘️', 'Samity']] },
  { id: 'community', label: 'Community', items: [['notices', '📢', 'Notice Board'], ['referrals', '🤝', 'Referrals'], ['trust', '🪪', 'NID & Trust'], ['support', '🎧', 'Support']] },
  { id: 'legal', label: 'Legal', items: [['compliance', '⚖️', 'Compliance'], ['legal', '📜', 'Legal Engine'], ['cases', '👨‍⚖️', 'Cases'], ['concierge', '🗂️', 'Legal Concierge']] },
  { id: 'ops', label: 'Operations', items: [['vendors', '🧰', 'Vendors']] },
  { id: 'secure', label: 'Safety & Security', items: [['smarthome', '🏠', 'Building Systems'], ['land', '🛰️', 'Land Guard'], ['build', '🏗️', 'Build Watch'], ['firesafety', '🧯', 'Fire Safety']] },
  { id: 'admin', label: 'Admin', items: [['caretaker', '👑', 'Caretaker']] },
]

const VIEW_ROUTES = {
  dashboard: '/dashboard', analytics: '/analytics', ai: '/ai',
  properties: '/properties', units: '/units', tenants: '/tenants', leases: '/leases', insurance: '/insurance',
  onboarding: '/onboarding', leads: '/leads', documents: '/documents', templates: '/templates',
  invoices: '/invoices', receipts: '/receipts', payments: '/payments',
  taxes: '/holding-taxes', remit: '/remittances', recon: '/collections', statements: '/statements',
  notices: '/notices', referrals: '/referrals', trust: '/nid', support: '/support',
  maintenance: '/maintenance', vendors: '/vendors', utilities: '/utility-bills',
  staff: '/staff', attendance: '/staff-attendance', payroll: '/staff-payroll', meter: '/meter-readings',
  compliance: '/compliance', legal: '/legal', cases: '/cases', concierge: '/concierge',
  smart: '/building-systems', smarthome: '/building-systems', land: '/land', build: '/build', gate: '/gate-visits',
  firesafety: '/fire-safety', staffwatch: '/staff-attendance',
  samity: '/samity', caretaker: '/dashboard',
}

// Module gating follows the EFFECTIVE user (updates after a real role switch).
const can = (mod) => {
  const user = auth.user || data.user
  if (!user) return true
  if (user.role_modules) return (user.role_modules[user.role] || user.modules || []).includes(mod)
  return true
}

const groups = computed(() =>
  GROUPS.map(g => ({ ...g, items: g.items.filter(i => can(i[0])) })).filter(g => g.items.length)
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
const roleName = computed(() => roleLabel((data.user || auth.user)?.role))

const openRoles = ref(false)
const switching = ref(false)

function go(view) {
  const r = VIEW_ROUTES[view]
  if (r) router.push(r)
  else router.push({ path: '/dashboard', query: { stub: view } })
  emit('close')
}
async function pick(r) {
  if (switching.value) return
  switching.value = true
  try {
    const res = await auth.viewAs(r.email)
    if (!res.ok) {
      window.__krToast?.('❌ ' + (res.error || 'Switch failed'))
      return
    }
    await data.bootstrap()
    data.setPreviewRole(r.id)
    window.__krToast?.(auth.isImpersonating ? `👁 Viewing as ${r.role}` : 'Switched to ' + r.role)
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
          <div class="rc-role">{{ roleName }}<span v-if="auth.isImpersonating" style="color:var(--warn)"> · 👁</span></div>
        </div>
      </div>
      <template v-if="auth.isImpersonating">
        <button class="role-switch-btn" style="background:var(--primary-light);color:var(--primary-dark);border:1px solid var(--primary)" :disabled="switching" @click="backToMe()">↩ Back to my account</button>
      </template>
      <button v-else class="role-switch-btn" @click="openRoles = true">🔀 Switch role</button>
    </div>

    <!-- Subordinate role switch modal -->
    <div v-if="openRoles" class="overlay" @click.self="openRoles = false">
      <div class="modal">
        <div class="modal-h"><span class="t">🔀 Switch to subordinate user</span><button class="close" @click="openRoles = false">✕</button></div>
        <div v-if="switching" style="padding:20px;text-align:center;color:var(--text-mute)">Switching…</div>
        <div v-else class="role-grid">
          <template v-for="([g, items]) in roleGroups" :key="g">
            <div v-if="roleGroups.length > 1" style="grid-column:1/-1;font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--text-mute);margin-top:4px">{{ GROUP_LABEL[g] || g }}</div>
            <div v-for="r in items" :key="r.id" class="role-opt" :class="{ active: r.id === (data.user || auth.user)?.role }" @click="pick(r)">
              <div class="ro-ic">{{ r.ico }}</div>
              <div class="ro-t">{{ r.role }}</div>
              <div class="ro-d">{{ r.desc }}</div>
            </div>
          </template>
        </div>
      </div>
    </div>
  </aside>
</template>
