<script setup>
// ─────────────────────────────────────────────────────────────
// BMS Hub (V2.0.7) — one dashboard for building management.
// Merged from the old 8-item BMS group into a single /bms page
// with tabs (like Finance):
//   Overview · Maintenance · Gate Visits · Staff · Attendance ·
//   Payroll · Meter Readings · Utility Bills · Samity
// Overview = BMS command center (KPIs from bootstrap, instant).
// Other tabs embed the existing views via lazy components
// (KeepAlive + :key preserves state between switches).
// ─────────────────────────────────────────────────────────────
import { ref, computed, defineAsyncComponent, watch } from 'vue'
import { t } from '../lib/i18n'
import { useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import ScrollTabs from '../components/ScrollTabs.vue'

const route = useRoute()
const data = useDataStore()

const TAB_ORDER = [
  ['overview', '📊', 'Overview'],
  ['maintenance', '🔧', 'Maintenance'],
  ['gate', '🚪', 'Gate Visits'],
  ['staff', '👷', 'Staff'],
  ['attendance', '⏱️', 'Attendance'],
  ['payroll', '💵', 'Payroll'],
  ['meter', '⚡', 'Meter Readings'],
  ['utilities', '🔌', 'Utility Bills'],
]

const VIEWS = {
  maintenance: defineAsyncComponent(() => import('./MaintenanceView.vue')),
  gate: defineAsyncComponent(() => import('./GateVisitsView.vue')),
  staff: defineAsyncComponent(() => import('./StaffView.vue')),
  attendance: defineAsyncComponent(() => import('./StaffAttendanceView.vue')),
  payroll: defineAsyncComponent(() => import('./StaffPayrollView.vue')),
  meter: defineAsyncComponent(() => import('./MeterReadingsView.vue')),
  utilities: defineAsyncComponent(() => import('./UtilityBillsView.vue')),
}

const tab = ref('overview')
watch(() => route.query.tab, (t) => {
  if (t && TAB_ORDER.some(([k]) => k === t)) tab.value = t
}, { immediate: true })

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const today = () => new Date().toISOString().slice(0, 10)
const thisMonth = () => new Date().toISOString().slice(0, 7)

// ── Overview: instant KPIs from the bootstrap store ──
const mntAll = computed(() => data.list('maintenance_requests'))
const gateAll = computed(() => data.list('gate_visits'))
const staffAll = computed(() => data.list('staff'))
const attAll = computed(() => data.list('staff_attendance'))
const payAll = computed(() => data.list('staff_payroll'))
const meterAll = computed(() => data.list('meter_readings'))
const utilAll = computed(() => data.list('utility_bills'))

const openMnt = computed(() => mntAll.value.filter(r => ['Open', 'Assigned', 'In Progress'].includes(r.status)).length)
const urgentMnt = computed(() => mntAll.value.filter(r => r.priority === 'urgent' && !['Resolved', 'Closed'].includes(r.status)).length)
const activeStaff = computed(() => staffAll.value.filter(s => s.status === 'active').length)
const onLeave = computed(() => staffAll.value.filter(s => s.status === 'on_leave').length)
const presentToday = computed(() => attAll.value.filter(a => a.work_date === today() && a.status === 'present').length)
const lateToday = computed(() => attAll.value.filter(a => a.work_date === today() && a.status === 'late').length)
const payThisMonth = computed(() => payAll.value.filter(p => (p.month || '').startsWith(thisMonth())).reduce((s, p) => s + (Number(p.amount) || 0), 0))
const utilThisMonth = computed(() => utilAll.value.filter(u => (u.month || '').startsWith(thisMonth())).reduce((s, u) => s + (Number(u.amount) || 0), 0))

const kpis = computed(() => ({
  open: openMnt.value, urgent: urgentMnt.value, staff: staffAll.value.length, active: activeStaff.value,
  present: presentToday.value, late: lateToday.value, payroll: payThisMonth.value, util: utilThisMonth.value,
  gate: gateAll.value.length, meter: meterAll.value.length, onLeave: onLeave.value,
}))

const recentMnt = computed(() => [...mntAll.value].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || ''))).slice(0, 5))
const recentGate = computed(() => [...gateAll.value].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || ''))).slice(0, 5))

const goTab = (t) => { tab.value = t }
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('🔧 BMS') }}</h1>
        <div class="sub">Building management — maintenance, gate, staff, attendance, payroll, meters &amp; utilities · one dashboard</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-ghost" @click="goTab('maintenance')" title="Create a new maintenance request — opens the Maintenance tab">➕ New request</button>
      </div>
    </div>

    <!-- Tabs -->
    <ScrollTabs>
      <button v-for="[k, ico, l] in TAB_ORDER" :key="k" @click="goTab(k)"
        :style="tab === k ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'">
        {{ ico }} {{ t(l) }}
      </button>
    </ScrollTabs>

    <!-- ══ OVERVIEW ══ -->
    <template v-if="tab === 'overview'">
      <!-- KPI cards -->
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🔧</span>Open maintenance</div><div class="s-value" :style="kpis.open > 0 ? 'color:#f39c12' : ''">{{ kpis.open }}</div><div class="s-trend">{{ kpis.urgent }} urgent</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">👷</span>Staff</div><div class="s-value">{{ kpis.staff }}</div><div class="s-trend">{{ kpis.active }} active · {{ kpis.onLeave }} on leave</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⏱️</span>Present today</div><div class="s-value" style="color:var(--ok,#12a150)">{{ kpis.present }}</div><div class="s-trend">{{ kpis.late }} late</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💵</span>Payroll this month</div><div class="s-value">{{ money(kpis.payroll) }}</div><div class="s-trend">{{ thisMonth() }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🔌</span>Utility bills</div><div class="s-value">{{ money(kpis.util) }}</div><div class="s-trend">{{ thisMonth() }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🚪</span>Gate visits</div><div class="s-value">{{ kpis.gate }}</div><div class="s-trend">{{ kpis.meter }} meter readings · {{ kpis.onLeave }} on leave</div></div>
      </div>

      <!-- Quick actions -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0">
        <button @click="goTab('maintenance')" style="padding:9px 15px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:12.5px;cursor:pointer" title="Maintenance requests and work orders">🔧 Maintenance</button>
        <button @click="goTab('gate')" class="btn-ghost" title="Visitor and gate visit log">🚪 Gate Visits</button>
        <button @click="goTab('staff')" class="btn-ghost" title="Building staff directory">👷 Staff</button>
        <button @click="goTab('attendance')" class="btn-ghost" title="Staff attendance tracking">⏱️ Attendance</button>
        <button @click="goTab('payroll')" class="btn-ghost" title="Staff payroll management">💵 Payroll</button>
        <button @click="goTab('meter')" class="btn-ghost" title="Meter readings">⚡ Meters</button>
        <button @click="goTab('utilities')" class="btn-ghost" title="Utility bills (electric, gas, water)">🔌 Utilities</button>
      </div>

      <!-- lists -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;margin-bottom:14px">
        <div class="panel" style="padding:16px 18px">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">🔧 Recent maintenance</div>
          <div v-for="r in recentMnt" :key="r.id" style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed var(--border);font-size:12.5px">
            <div style="overflow:hidden">
              <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ r.title || r.id }}</div>
              <div class="c-sub" style="font-size:11px">{{ r.category || '' }} · unit {{ r.unit || '' }}</div>
            </div>
            <div style="text-align:right;white-space:nowrap">
              <span class="badge" :class="['Resolved','Closed'].includes(r.status) ? 'badge-green' : 'badge-blue'">{{ r.status || '—' }}</span>
            </div>
          </div>
          <div v-if="!recentMnt.length" style="padding:14px 0;text-align:center;color:var(--text-mute);font-size:12.5px">No maintenance requests yet.</div>
        </div>
        <div class="panel" style="padding:16px 18px">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">🚪 Recent gate visits</div>
          <div v-for="g in recentGate" :key="g.id" style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed var(--border);font-size:12.5px">
            <div style="overflow:hidden">
              <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ g.name || g.id }}</div>
              <div class="c-sub" style="font-size:11px">{{ g.vtype || '' }} · {{ g.purpose || '' }} · unit {{ g.unit || '' }}</div>
            </div>
            <div style="text-align:right;white-space:nowrap">
              <span class="badge" :class="g.status === 'Checked out' ? 'badge-green' : 'badge-blue'">{{ g.status || '—' }}</span>
            </div>
          </div>
          <div v-if="!recentGate.length" style="padding:14px 0;text-align:center;color:var(--text-mute);font-size:12.5px">No gate visits yet.</div>
        </div>
      </div>
    </template>

    <!-- ══ EMBEDDED MODULES (KeepAlive + :key preserves each tab's state) ══ -->
    <KeepAlive>
      <component :is="VIEWS[tab]" :key="tab" v-if="tab !== 'overview' && VIEWS[tab]" />
    </KeepAlive>
  </div>
</template>
