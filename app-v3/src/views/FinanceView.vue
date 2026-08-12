<script setup>
// ─────────────────────────────────────────────────────────────
// Finance Hub (V2.0.6) — one dashboard for everything money.
// Merged from the old 8-item Finance group + 6-item Accounts
// group into a single /finance page with tabs (like Accounts):
//   Overview · Invoices · Receipts · Payments · Collections ·
//   Remittances · Statements · Holding Tax · Subscriptions · Accounts
// Overview = finance command center (KPIs + cashflow + collections
// + aging + recent activity). Other tabs embed the existing views
// via lazy components (KeepAlive preserves state between switches).
// Tab is synced with ?tab= so each sidebar sub-item can deep-link.
// ─────────────────────────────────────────────────────────────
import { ref, computed, defineAsyncComponent, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { apiCall } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import LineChart from '../components/charts/LineChart.vue'
import Donut from '../components/charts/Donut.vue'
import HBars from '../components/charts/HBars.vue'
import ScrollTabs from '../components/ScrollTabs.vue'

const route = useRoute()
const auth = useAuthStore()
const data = useDataStore()

const TAB_ORDER = [
  ['overview', '📊', 'Overview'],
  ['invoices', '🧾', 'Invoices'],
  ['receipts', '📎', 'Receipts'],
  ['payments', '💳', 'Payments'],
  ['collections', '📮', 'Collections'],
  ['remittances', '🌍', 'Remittances'],
  ['statements', '💰', 'Statements'],
  ['taxes', '🏛️', 'Holding Tax'],
  ['subscriptions', '💎', 'Subscriptions'],
  ['accounts', '💱', 'Accounts'],
]

const VIEWS = {
  invoices: defineAsyncComponent(() => import('./InvoicesView.vue')),
  receipts: defineAsyncComponent(() => import('./ReceiptsView.vue')),
  payments: defineAsyncComponent(() => import('./PaymentsView.vue')),
  collections: defineAsyncComponent(() => import('./CollectionView.vue')),
  remittances: defineAsyncComponent(() => import('./RemittancesView.vue')),
  statements: defineAsyncComponent(() => import('./StatementsView.vue')),
  taxes: defineAsyncComponent(() => import('./HoldingTaxesView.vue')),
  subscriptions: defineAsyncComponent(() => import('./PremiumView.vue')),
  accounts: defineAsyncComponent(() => import('./AccountsView.vue')),
}

const tab = ref('overview')
watch(() => route.query.tab, (t) => {
  if (t && TAB_ORDER.some(([k]) => k === t)) tab.value = t
}, { immediate: true })

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const shortMonth = (m) => { if (!m) return ''; const [y, mo] = m.split('-'); return (['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][parseInt(mo, 10) - 1] || mo) + ' ' + String(y).slice(2) }
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'accountant'].includes(auth.user?.role || ''))

// ── Overview: client-side KPIs from the bootstrap store ──
const invAll = computed(() => data.list('invoices'))
const payAll = computed(() => data.list('payments'))
const tenAll = computed(() => data.list('tenants'))
const leaAll = computed(() => data.list('leases'))

const invPaid = (i) => payAll.value.filter(p => p.inv === i.id && String(p.status).toLowerCase() === 'success').reduce((s, p) => s + (p.amount || 0), 0)
const invDue = (i) => Math.max(0, (i.net || 0) - invPaid(i))
const thisMonth = () => new Date().toISOString().slice(0, 7)

const kpis = computed(() => {
  const gross = invAll.value.reduce((s, i) => s + (i.net || 0), 0)
  const col = invAll.value.reduce((s, i) => s + invPaid(i), 0)
  const due = invAll.value.reduce((s, i) => s + invDue(i), 0)
  const m = thisMonth()
  const mDue = invAll.value.filter(i => i.m === m).reduce((s, i) => s + invDue(i), 0)
  const unpaidN = invAll.value.filter(i => invDue(i) > 0).length
  return {
    gross, collected: col, outstanding: due, rate: gross ? Math.round(col / gross * 100) : 0,
    monthDue: mDue, unpaidN,
  }
})

const tenantOf = (inv) => { const l = leaAll.value.find(x => x.id === inv.l); return l ? (tenAll.value.find(t => t.id === l.t)?.name || l.t) : '—' }

const recentPayments = computed(() => payAll.value
  .filter(p => String(p.status).toLowerCase() === 'success')
  .sort((a, b) => String(b.date).localeCompare(String(a.date)))
  .slice(0, 8)
  .map(p => ({ ...p, invId: p.inv, invNet: invAll.value.find(i => i.id === p.inv)?.net || 0, tenant: (() => { const i = invAll.value.find(x => x.id === p.inv); return i ? tenantOf(i) : '—' })() })))

const unpaidList = computed(() => invAll.value
  .filter(i => invDue(i) > 0)
  .sort((a, b) => invDue(b) - invDue(a))
  .slice(0, 7)
  .map(i => ({ ...i, paid: invPaid(i), due: invDue(i), tenant: tenantOf(i) })))

// ── Overview: analytics + accounts (live API) ──
const ovLoading = ref(false)
const ovErr = ref('')
const cashflow = ref(null)      // {months:[{month,income,expenses,net,cumulative}],...}
const collections = ref(null)   // {by_method, on_time_rate, avg_days_late, late_amount,...}
const aging = ref(null)         // {current,d30,d60,d90,total}
const acct = ref(null)          // app-accounts summary

async function loadOverview() {
  ovLoading.value = true; ovErr.value = ''
  try {
    const [cf, coll, ag, acc] = await Promise.all([
      apiCall('app-analytics', { action: 'cashflow', months: 12 }),
      apiCall('app-analytics', { action: 'collections', months: 12 }),
      apiCall('app-analytics', { action: 'aging' }),
      apiCall('app-accounts', { action: 'summary' }),
    ])
    if (!cf.ok) throw new Error(cf.error || 'cashflow failed')
    if (!coll.ok) throw new Error(coll.error || 'collections failed')
    if (!ag.ok) throw new Error(ag.error || 'aging failed')
    if (!acc.ok) throw new Error(acc.error || 'accounts failed')
    cashflow.value = cf
    collections.value = coll
    aging.value = ag
    acct.value = acc
  } catch (e) { ovErr.value = e.message }
  finally { ovLoading.value = false }
}

// chart data
const flowSeries = computed(() => {
  const months = (cashflow.value?.months || []).map(m => m.month)
  return [
    { name: 'Income', color: '#12a150', points: (cashflow.value?.months || []).map(m => m.income) },
    { name: 'Expenses', color: '#e74c3c', points: (cashflow.value?.months || []).map(m => m.expenses) },
  ].map(s => ({ ...s, points: s.points.length === months.length ? s.points : [] }))
})
const flowLabels = computed(() => (cashflow.value?.months || []).map(m => shortMonth(m.month)))
const methodSegs = computed(() => (collections.value?.by_method || []).map((m, i) => ({
  label: m.method || '—', value: m.amount || 0,
  color: ['#4361ee', '#12a150', '#f59e0b', '#e74c3c', '#7c3aed', '#0891b2', '#64748b'][i % 7],
})))
const agingRows = computed(() => {
  const a = (aging.value && aging.value.buckets) || {}
  return [
    { label: 'Current', value: a.current || 0, color: '#4361ee' },
    { label: '30d', value: a.d30 || 0, color: '#f59e0b' },
    { label: '60d', value: a.d60 || 0, color: '#f97316' },
    { label: '90d+', value: a.d90 || 0, color: '#e74c3c' },
  ]
})
const agingTotal = computed(() => (aging.value && aging.value.buckets && aging.value.buckets.total) || 0)
const aging90 = computed(() => (aging.value && aging.value.buckets && aging.value.buckets.d90) || 0)

const goTab = (t) => { tab.value = t }
const refreshAll = () => { loadOverview(); window.__krToast?.('🔄 Refreshing finance data…') }
onMounted(loadOverview)
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>💰 Finance</h1>
        <div class="sub">Everything money — invoices, payments, collections, remittances, statements &amp; accounts · one dashboard</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-ghost" @click="refreshAll">🔄 Refresh</button>
      </div>
    </div>

    <!-- Tabs -->
    <ScrollTabs>
      <button v-for="[k, ico, l] in TAB_ORDER" :key="k" @click="goTab(k)"
        :style="tab === k ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'">
        {{ ico }} {{ l }}
      </button>
    </ScrollTabs>

    <!-- ══ OVERVIEW ══ -->
    <template v-if="tab === 'overview'">
      <div v-if="ovLoading" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">Loading finance overview…</div>
      <template v-else>
        <div v-if="ovErr" style="padding:10px 14px;border-radius:10px;background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.35);margin-bottom:14px;font-weight:600;font-size:13.5px">⚠️ {{ ovErr }}</div>

        <!-- KPI cards -->
        <div class="stats">
          <div class="stat"><div class="s-label"><span class="s-ico">🧾</span>Gross billed</div><div class="s-value">{{ money(kpis.gross) }}</div><div class="s-trend">{{ invAll.length }} invoices</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">💳</span>Collected</div><div class="s-value" style="color:var(--ok,#12a150)">{{ money(kpis.collected) }}</div><div class="s-trend">{{ kpis.rate }}% collection rate</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">⏳</span>Outstanding</div><div class="s-value" :style="kpis.outstanding > 0 ? 'color:var(--danger,#e74c3c)' : ''">{{ money(kpis.outstanding) }}</div><div class="s-trend">{{ kpis.unpaidN }} unpaid invoice(s)</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">📅</span>This month due</div><div class="s-value">{{ money(kpis.monthDue) }}</div><div class="s-trend">{{ shortMonth(thisMonth()) }}</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">⏰</span>Arrears (aging)</div><div class="s-value" :style="agingTotal > 0 ? 'color:#f39c12' : ''">{{ money(agingTotal) }}</div><div class="s-trend">90d+ {{ money(aging90) }}</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">💼</span>Cash balance</div><div class="s-value" :style="(acct?.totals?.balance || 0) >= 0 ? 'color:var(--ok,#12a150)' : 'color:var(--danger,#e74c3c)'">{{ money(acct?.totals?.balance || 0) }}</div><div class="s-trend">{{ acct?.totals?.count || 0 }} transactions</div></div>
        </div>

        <!-- Quick actions -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0">
          <button @click="goTab('invoices')" style="padding:9px 15px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:12.5px;cursor:pointer">⚡ Auto-generate invoices</button>
          <button @click="goTab('invoices')" class="btn-ghost">💳 Record payment</button>
          <button @click="goTab('collections')" class="btn-ghost">📮 Collections &amp; recon</button>
          <button v-if="canManage" @click="goTab('accounts')" class="btn-ghost">📥 Post receive</button>
          <button v-if="canManage" @click="goTab('accounts')" class="btn-ghost">📤 Post expense</button>
          <button @click="goTab('accounts')" class="btn-ghost">💱 Accounts ledger</button>
          <button @click="goTab('subscriptions')" class="btn-ghost">💎 Subscriptions</button>
        </div>

        <!-- charts row -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;margin-bottom:14px">
          <div class="panel" style="padding:16px 18px">
            <div style="font-weight:800;font-size:13.5px;margin-bottom:4px">📈 Cashflow — 12 months</div>
            <div class="c-sub" style="font-size:11.5px;margin-bottom:12px">income vs expenses · net {{ money(cashflow?.total_net || 0) }} · expense ratio {{ cashflow?.expense_ratio || 0 }}%</div>
            <LineChart :series="flowSeries" :labels="flowLabels" :fmt="money" :height="180" />
          </div>
          <div class="panel" style="padding:16px 18px">
            <div style="font-weight:800;font-size:13.5px;margin-bottom:4px">💳 Collections by method</div>
            <div class="c-sub" style="font-size:11.5px;margin-bottom:12px">on-time {{ collections?.on_time_rate || 0 }}% · avg {{ collections?.avg_days_late || 0 }}d late · late {{ money(collections?.late_amount || 0) }}</div>
            <Donut :segments="methodSegs" :size="170" :thickness="26" center-label="Collected" :center-value="money(collections ? collections.by_method.reduce((s, m) => s + (m.amount || 0), 0) : 0)" :fmt="money" />
          </div>
        </div>

        <!-- aging + lists -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;margin-bottom:14px">
          <div class="panel" style="padding:16px 18px">
            <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">⏰ Arrears aging</div>
            <HBars :rows="agingRows" :fmt="money" />
          </div>
          <div class="panel" style="padding:16px 18px">
            <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">⏳ Unpaid invoices <span class="c-sub">— top by due</span></div>
            <div v-for="i in unpaidList" :key="i.id" style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed var(--border);font-size:12.5px">
              <div style="overflow:hidden">
                <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ i.tenant }}</div>
                <div class="c-sub" style="font-size:11px">{{ i.id }} · {{ shortMonth(i.m) }}</div>
              </div>
              <div style="text-align:right;white-space:nowrap">
                <div style="font-weight:800;color:var(--danger,#e74c3c)">{{ money(i.due) }}</div>
                <div class="c-sub" style="font-size:11px">of {{ money(i.net) }}</div>
              </div>
            </div>
            <div v-if="!unpaidList.length" style="padding:14px 0;text-align:center;color:var(--text-mute);font-size:12.5px">All invoices paid 🎉</div>
          </div>
          <div class="panel" style="padding:16px 18px">
            <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">📥 Recent payments</div>
            <div v-for="p in recentPayments" :key="p.id" style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed var(--border);font-size:12.5px">
              <div style="overflow:hidden">
                <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ p.tenant }}</div>
                <div class="c-sub" style="font-size:11px">{{ p.id }} · {{ p.date }} · {{ p.method }}</div>
              </div>
              <div style="text-align:right;white-space:nowrap">
                <div style="font-weight:800;color:var(--ok,#12a150)">+ {{ money(p.amount) }}</div>
                <div class="c-sub" style="font-size:11px">{{ p.invId }}</div>
              </div>
            </div>
            <div v-if="!recentPayments.length" style="padding:14px 0;text-align:center;color:var(--text-mute);font-size:12.5px">No payments yet.</div>
          </div>
        </div>
      </template>
    </template>

    <!-- ══ EMBEDDED MODULES (KeepAlive + :key preserves each tab's state) ══ -->
    <KeepAlive>
      <component :is="VIEWS[tab]" :key="tab" v-if="tab !== 'overview' && VIEWS[tab]" />
    </KeepAlive>
  </div>
</template>
