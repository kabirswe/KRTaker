<script setup>
import { ref, computed, onMounted } from 'vue'
import { apiCall } from '../api/client'
import LineChart from '../components/charts/LineChart.vue'
import Donut from '../components/charts/Donut.vue'
import HBars from '../components/charts/HBars.vue'
import ScrollTabs from '../components/ScrollTabs.vue'
import CompactFilters from '../components/CompactFilters.vue'

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const C = { blue: '#2F80ED', green: '#12a150', amber: '#f6a609', orange: '#e67e22', red: '#e74c3c', purple: '#8e5cf7', teal: '#14b8a6', pink: '#ec4899' }
const shortMonth = (m) => { const [y, mm] = String(m || '').split('-'); const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']; return names[(parseInt(mm) || 1) - 1] + ' ' + String(y).slice(2) }
const shortDate = (d) => String(d || '').slice(5)

const TABS = [
  ['overview', '📊 Overview'], ['cashflow', '💸 Cashflow'], ['collections', '💳 Collections'], ['expenses', '🧾 Expenses'],
  ['maintenance', '🔧 Maintenance'], ['tenants', '👥 Tenants'], ['aging', '⏳ Aging'], ['vacancy', '🏚️ Vacancy'],
  ['forecast', '🔮 Forecast'], ['board', '📋 Board'],
]
const tab = ref('overview')
const loading = ref(false)
const err = ref('')
const toast = ref('')
const now = new Date()
const month = ref(now.toISOString().slice(0, 7))

function exportCsv(name, headers, rows) {
  const esc = (v) => { const s = String(v ?? ''); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const csv = [headers.map(esc).join(','), ...rows.map((r) => r.map(esc).join(','))].join('\n')
  const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob); a.download = name + '.csv'
  document.body.appendChild(a); a.click(); a.remove()
  setTimeout(() => URL.revokeObjectURL(a.href), 500)
}
const flash = (m) => { toast.value = m; setTimeout(() => toast.value = '', 4000) }

// ── Overview: P&L per property ──
const pnl = ref(null)
async function loadPnl() {
  const r = await apiCall('app-analytics', { action: 'pnl', month: month.value })
  if (r.ok) pnl.value = r; else if (!err.value) err.value = r.error || 'Failed to load P&L.'
}
const pnlRows = computed(() => pnl.value?.properties || [])
const pnlTotals = computed(() => pnl.value?.totals || {})

// ── Trends ──
const trends = ref(null)
async function loadTrends() {
  const r = await apiCall('app-analytics', { action: 'trends', months: 12 })
  if (r.ok) trends.value = r
}

// ── Aging ──
const aging = ref(null)
async function loadAging() {
  const r = await apiCall('app-analytics', { action: 'aging' })
  if (r.ok) aging.value = r.buckets
}
const agingSegs = computed(() => {
  const b = aging.value || {}
  return [
    { label: 'Current', value: b.current || 0, color: C.green },
    { label: '30 days', value: b.d30 || 0, color: C.amber },
    { label: '60 days', value: b.d60 || 0, color: C.orange },
    { label: '90+ days', value: b.d90 || 0, color: C.red },
  ]
})
const agingTotal = computed(() => aging.value?.total || 0)

// ── Vacancy ──
const vacancy = ref(null)
async function loadVacancy() {
  const r = await apiCall('app-analytics', { action: 'vacancy' })
  if (r.ok) vacancy.value = r
}

// ── Forecast ──
const forecast = ref(null)
async function loadForecast() {
  const r = await apiCall('app-analytics', { action: 'forecast' })
  if (r.ok) forecast.value = r
}
const fcMonths = computed(() => forecast.value?.months || [])

// ── Cashflow ──
const cashflow = ref(null)
async function loadCashflow() {
  const r = await apiCall('app-analytics', { action: 'cashflow', months: 12 })
  if (r.ok) cashflow.value = r
}
const cfMonths = computed(() => cashflow.value?.months || [])
const cfLabels = computed(() => cfMonths.value.map((m) => shortMonth(m.month)))

// ── Collections ──
const collections = ref(null)
async function loadCollections() {
  const r = await apiCall('app-analytics', { action: 'collections', months: 12 })
  if (r.ok) collections.value = r
}
const METHOD_COLORS = { bKash: C.pink, Nagad: C.orange, Rocket: C.teal, Cash: C.green, 'Bank Transfer': C.blue, Cheque: C.purple, Card: C.amber, SSLCommerz: C.red, Manual: C.blue }
const methodSegs = computed(() => (collections.value?.by_method || []).map((m) => ({ label: m.method, value: m.amount, color: METHOD_COLORS[m.method] || C.blue })))

// ── Expenses ──
const expenses = ref(null)
async function loadExpenses() {
  const r = await apiCall('app-analytics', { action: 'expenses', months: 12 })
  if (r.ok) expenses.value = r
}
const CAT_COLORS = { plumbing: C.blue, electrical: C.amber, structural: C.red, appliance: C.purple, 'hvac': C.teal, other: '#8A94A6' }
const expCatSegs = computed(() => (expenses.value?.by_category || []).map((c) => ({ label: c.category, value: c.cost, color: CAT_COLORS[c.category] || C.blue })))
const expVendorRows = computed(() => (expenses.value?.by_vendor || []).map((v) => ({ label: v.vendor, value: v.cost, sub: v.n + ' job' + (v.n === 1 ? '' : 's') })))

// ── Tenants (scorecards) ──
const scores = ref(null)
async function loadScores() {
  const r = await apiCall('app-analytics', { action: 'scores' })
  if (r.ok) scores.value = r
}
const BAND_COLORS = { Excellent: C.green, Good: C.blue, Fair: C.orange, Risky: C.red }
const bandSegs = computed(() => Object.entries(scores.value?.bands || {}).filter(([, v]) => v > 0).map(([k, v]) => ({ label: k, value: v, color: BAND_COLORS[k] || C.blue })))

// ── Occupancy ──
const occupancy = ref(null)
async function loadOccupancy() {
  const r = await apiCall('app-analytics', { action: 'occupancy' })
  if (r.ok) occupancy.value = r
}
const occBars = computed(() => (occupancy.value?.properties || []).map((p) => ({ label: p.name, value: p.occupancy, sub: p.leased + '/' + p.units + ' leased' })))

// ── Maintenance ──
const maintenance = ref(null)
async function loadMaintenance() {
  const r = await apiCall('app-analytics', { action: 'maintenance' })
  if (r.ok) maintenance.value = r
}
const mStatusRows = computed(() => (maintenance.value?.by_status || []).map((s) => ({ label: s.status, value: s.n })))
const mPrioRows = computed(() => (maintenance.value?.by_priority || []).map((s) => ({ label: s.priority, value: s.n, color: s.priority === 'urgent' ? C.red : s.priority === 'high' ? C.orange : s.priority === 'medium' ? C.amber : C.blue })))

// ── Board reports ──
const boards = ref([])
const boardMd = ref('')
const boardId = ref('')
const boardMonth = ref('')
async function loadBoards() {
  const r = await apiCall('app-analytics', { action: 'boards' })
  if (r.ok) boards.value = r.reports || []
}
async function genBoard() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-analytics', { action: 'board', month: month.value })
    if (!r.ok) { err.value = r.error || 'Board generation failed.'; return }
    boardId.value = r.id; boardMonth.value = r.month; boardMd.value = r.markdown || ''
    flash(`📊 ${r.id} generated for ${r.month}`)
    await loadBoards()
  } catch (e) { err.value = e.message } finally { loading.value = false }
}
function viewBoard(b) {
  boardId.value = b.id; boardMonth.value = b.month; boardMd.value = '…'
  apiCall('app-analytics', { action: 'board', month: b.month }).then((r) => {
    if (r.ok) { boardId.value = r.id; boardMonth.value = r.month; boardMd.value = r.markdown || '' }
  })
}

// ── loaders ──
const LOADERS = {
  overview: () => Promise.allSettled([loadPnl(), loadCashflow(), loadAging(), loadOccupancy(), loadCollections(), loadForecast()]),
  cashflow: loadCashflow, collections: loadCollections, expenses: loadExpenses, maintenance: loadMaintenance,
  tenants: loadScores, aging: loadAging, vacancy: loadVacancy, forecast: loadForecast, board: loadBoards,
}
async function switchTab(t) {
  tab.value = t
  err.value = ''
  loading.value = true
  try {
    const fn = LOADERS[t]
    if (fn) await fn()
  } catch (e) { err.value = e.message } finally { loading.value = false }
}
onMounted(() => { switchTab('overview') })

// ── CSV exports ──
function csvOverview() {
  exportCsv('analytics-pnl', ['Property', 'Type', 'Gross', 'Collected', 'TDS', 'Service', 'Expenses', 'Net'],
    pnlRows.value.map((p) => [p.name, p.type, p.gross, p.collected, p.tds, p.service, p.expenses, p.net]))
}
function csvCashflow() {
  exportCsv('analytics-cashflow', ['Month', 'Income', 'Expenses', 'Net', 'Cumulative'],
    cfMonths.value.map((m) => [m.month, m.income, m.expenses, m.net, m.cumulative]))
}
function csvCollections() {
  exportCsv('analytics-collections', ['Month', 'Issued', 'Collected', 'Rate %'],
    (collections.value?.by_month || []).map((m) => [m.month, m.issued, m.collected, m.rate]))
}
function csvExpenses() {
  exportCsv('analytics-expenses', ['Category', 'Jobs', 'Cost'],
    (expenses.value?.by_category || []).map((c) => [c.category, c.n, c.cost]))
}
function csvMaintenance() {
  exportCsv('analytics-maintenance', ['ID', 'Title', 'Status', 'Priority', 'Open Days'],
    (maintenance.value?.aging || []).map((a) => [a.id, a.title, a.status, a.priority, a.days]))
}
function csvTenants() {
  exportCsv('analytics-at-risk-tenants', ['ID', 'Name', 'Band', 'Score', 'Overdue', 'On-time %', 'Tenure mo', 'Tickets open'],
    (scores.value?.at_risk || []).map((t) => [t.id, t.name, t.band, t.score, t.overdue, t.on_time, t.tenure, t.tickets_open]))
}
function csvOccupancy() {
  exportCsv('analytics-occupancy', ['Property', 'Units', 'Leased', 'Vacant', 'Occupancy %', 'Rent roll', 'Vacancy loss'],
    (occupancy.value?.properties || []).map((p) => [p.name, p.units, p.leased, p.vacant, p.occupancy, p.rent_roll, p.vacancy_loss]))
}

// KPI card row helper (value can be colored)
const kpiStyle = (c) => c ? { color: c } : {}

// ── Markdown → HTML (board report, beautified executive pack) ──
// Converts the backend's board_report_md into a styled report:
//   hero header, KPI card grid (exec summary), icon section headers,
//   table.kr with total-row highlight, stacked aging bar, vacancy tiles,
//   expiring-lease badge, footer. Zero deps — string building only.
function mdToHtml(md) {
  const lines = String(md || '').split('\n')
  let html = '', inUl = false, inTable = false
  let section = ''
  let kpiBuf = []   // exec-summary KPI cards
  let vacBuf = []   // vacancy-loss tiles
  const closeUl = () => { if (inUl) { html += '</ul>\n'; inUl = false } }
  const closeTable = () => { if (inTable) { html += '</tbody></table>\n'; inTable = false } }
  const inline = (s) => s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/`([^`]+)`/g, '<code>$1</code>')
  const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  const flushBufs = () => {
    if (kpiBuf.length) { html += '<div class="br-kpis">' + kpiBuf.join('') + '</div>\n'; kpiBuf = [] }
    if (vacBuf.length) { html += '<div class="br-vac-grid">' + vacBuf.join('') + '</div>\n'; vacBuf = [] }
  }
  const secKey = (t) => {
    const l = t.toLowerCase()
    if (l.includes('executive')) return 'exec'
    if (l.includes('p&l') || l.includes('profit')) return 'pnl'
    if (l.includes('cashflow') || l.includes('cash flow')) return 'cash'
    if (l.includes('collection')) return 'coll'
    if (l.includes('occupancy') || l.includes('renewal')) return 'occ'
    if (l.includes('maintenance')) return 'mnt'
    if (l.includes('at-risk') || l.includes('at risk')) return 'risk'
    if (l.includes('aging')) return 'aging'
    if (l.includes('vacancy')) return 'vac'
    if (l.includes('forecast')) return 'fc'
    return ''
  }
  const SEC_ICON = { exec: '📊', pnl: '🧾', cash: '💸', coll: '💳', occ: '🏠', mnt: '🔧', risk: '⚠️', aging: '⏳', vac: '🏚️', fc: '🔮' }
  for (const raw of lines) {
    const line = raw.trimEnd()
    if (!line.trim()) { closeUl(); closeTable(); continue }
    if (/^\|.*\|$/.test(line.trim())) {
      closeUl()
      const cells = line.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim())
      if (!inTable) {
        inTable = true
        html += '<table class="kr br-table"><thead><tr>' + cells.map((c) => '<th>' + inline(c.replace(/^:+|:+$/g, '')) + '</th>').join('') + '</tr></thead><tbody>\n'
      } else if (cells.every((c) => /^:?-+:?$/.test(c))) {
        // separator row — skip
      } else {
        const isTotal = /^\*\*Total\*\*$/.test(cells[0])
        html += '<tr' + (isTotal ? ' class="br-total"' : '') + '>' + cells.map((c) => '<td>' + inline(c) + '</td>').join('') + '</tr>\n'
      }
      continue
    }
    closeTable()
    if (/^### /.test(line)) { closeUl(); html += '<h4>' + inline(line.slice(4)) + '</h4>\n'; continue }
    if (/^## /.test(line)) {
      flushBufs(); closeUl()
      const t = line.slice(3)
      section = secKey(t)
      html += '<h3 class="br-h"><span class="br-h-ic">' + (SEC_ICON[section] || '📋') + '</span>' + inline(t) + '</h3>\n'
      continue
    }
    if (/^# /.test(line)) {
      flushBufs(); closeUl()
      const t = line.slice(2)
      const m = t.split('—')
      const title = (m[0] || t).trim()
      const month = m[1] ? m[1].trim() : ''
      html += '<div class="br-hero"><div class="br-hero-badge">📋 Board report</div><div class="br-hero-title">' + esc(title) + '</div>' + (month ? '<div class="br-hero-month">' + esc(month) + '</div>' : '') + '</div>\n'
      continue
    }
    if (/^---$/.test(line.trim())) { flushBufs(); closeUl(); html += '<div class="br-div"></div>\n'; continue }
    if (/^- /.test(line)) {
      if (!inUl) { html += '<ul class="br-list">\n'; inUl = true }
      const item = line.slice(2)
      // Executive summary bullets → KPI cards (split on · for multi-metric lines)
      if (section === 'exec' && item.includes(':')) {
        closeUl()
        const parts = item.split('·').map((p) => p.trim()).filter(Boolean)
        for (const p of parts) {
          const mm = p.match(/^(.+?):\s*(.+)$/)
          if (!mm) continue
          const label = mm[1].trim(), val = mm[2].trim()
          const l = label.toLowerCase()
          let tone = ''
          if (/^৳-/.test(val)) tone = ' neg'
          else if (l.includes('arrears') || l.includes('loss') || l.includes('risk')) tone = ' warn'
          else if (l.includes('rate') || l.includes('occupancy') || l.includes('collected') || l.includes('net') || l.includes('income')) tone = ' pos'
          kpiBuf.push('<div class="br-kpi' + tone + '"><div class="br-kpi-l">' + esc(label) + '</div><div class="br-kpi-v">' + inline(val) + '</div></div>')
        }
        continue
      }
      // Aging → stacked horizontal bar
      if (section === 'aging' && item.includes('Current:')) {
        closeUl()
        const g = {}
        item.split('·').forEach((p) => {
          const mm = p.match(/^(.+?):\s*৳?([\d,]+)/)
          if (mm) g[mm[1].trim().replace('+', '')] = parseInt(mm[2].replace(/,/g, ''), 10) || 0
        })
        const segs = [['Current', g['Current'] || 0, '#2F80ED'], ['30d', g['30d'] || 0, '#F59E0B'], ['60d', g['60d'] || 0, '#F97316'], ['90d+', g['90d+'] || 0, '#E74C3C']]
        html += '<div class="br-aging"><div class="br-aging-bar">' + segs.map(([l, v, c]) => '<div class="br-aging-seg" style="flex:' + (v || 0) + ';background:' + c + '" title="' + l + ' ' + money(v) + '"></div>').join('') + '</div><div class="br-aging-legend">' + segs.map(([l, v, c]) => '<span><i style="background:' + c + '"></i>' + l + ': ' + money(v) + '</span>').join('') + '</div></div>\n'
        continue
      }
      // Vacancy loss bullets → tiles
      if (section === 'vac' && item.includes('market')) {
        closeUl()
        const mm = item.match(/^(.+?)\s*·\s*(.+?)\s*·\s*market\s*(.+)$/)
        if (mm) { vacBuf.push('<div class="br-vac"><b>' + esc(mm[1].trim()) + '</b><span>' + esc(mm[2].trim()) + '</span><i>' + inline(mm[3].trim()) + '</i></div>'); continue }
      }
      html += '<li>' + inline(item) + '</li>\n'
      continue
    }
    closeUl()
    if (/^\*\*.+\*\*$/.test(line.trim())) {
      html += '<div class="br-badge-line">' + inline(line.trim()) + '</div>\n'
      continue
    }
    if (/^Generated /.test(line.trim())) {
      html += '<div class="br-foot">⚡ ' + inline(line.trim()) + '</div>\n'
      continue
    }
    html += '<p>' + inline(line) + '</p>\n'
  }
  flushBufs(); closeUl(); closeTable()
  return html
}

// ── Print current report ──
function printReport() {
  document.body.classList.add('print-analytics')
  window.print()
  document.body.classList.remove('print-analytics')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>📈 Analytics</h1>
        <div class="sub">Portfolio intelligence — P&amp;L, cashflow, collections, expenses, tenants &amp; risk</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="month" type="month" style="padding:9px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none" @change="switchTab(tab)">
        <button class="btn-ghost" @click="switchTab(tab)" :disabled="loading">{{ loading ? '⏳…' : '🔄 Refresh' }}</button>
        <button class="btn-ghost" @click="printReport" title="Print this report">🖨 Print</button>
      </CompactFilters>
      </div>
    </div>

    <div v-if="toast" style="padding:10px 14px;border-radius:10px;background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.35);margin-bottom:14px;font-weight:700;font-size:13.5px">{{ toast }}</div>
    <div v-if="err" style="padding:10px 14px;border-radius:10px;background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.35);margin-bottom:14px;font-weight:600;font-size:13.5px">⚠️ {{ err }}</div>

    <ScrollTabs style="gap:6px">
      <button v-for="t in TABS" :key="t[0]" class="btn-ghost" :style="tab === t[0] ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : ''" @click="switchTab(t[0])">{{ t[1] }}</button>
    </ScrollTabs>

    <!-- ══ OVERVIEW ══ -->
    <template v-if="tab === 'overview'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🧾</span>Gross rent</div><div class="s-value">{{ money(pnlTotals.gross) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💰</span>Collected</div><div class="s-value">{{ money(pnlTotals.collected) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🎯</span>Net</div><div class="s-value" :style="kpiStyle((pnlTotals.net || 0) >= 0 ? C.green : C.red)">{{ money(pnlTotals.net) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💳</span>Collection rate</div><div class="s-value">{{ forecast?.collection_rate ?? '—' }}%</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🏠</span>Occupancy</div><div class="s-value">{{ occupancy?.occupancy ?? trends?.occupancy ?? 0 }}%</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🏚️</span>Vacancy loss</div><div class="s-value" style="color:var(--danger)">{{ money(occupancy?.vacancy_loss ?? vacancy?.monthly_loss) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⏳</span>Arrears</div><div class="s-value" style="color:var(--danger)">{{ money(agingTotal) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💸</span>12-mo net flow</div><div class="s-value" :style="kpiStyle((cashflow?.total_net || 0) >= 0 ? C.green : C.red)">{{ money(cashflow?.total_net) }}</div></div>
      </div>
      <div class="panel">
        <div class="panel-h">
          <div class="t"><span class="pi">💸</span>Cash flow · income vs expenses</div>
          <button class="btn-ghost" style="padding:5px 10px;font-size:11.5px" @click="csvCashflow">⬇ CSV</button>
        </div>
        <div class="panel-b">
          <LineChart :series="[
            { name: 'Income', color: C.blue, points: cfMonths.map(m => m.income) },
            { name: 'Expenses', color: C.red, points: cfMonths.map(m => m.expenses) },
          ]" :labels="cfLabels" :fmt="money" />
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">⏰</span>Arrears aging · {{ money(agingTotal) }}</div></div>
          <div class="panel-b"><Donut :segments="agingSegs" center-label="arrears" :center-value="money(agingTotal)" :fmt="money" /></div>
        </div>
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">💳</span>Payment methods</div></div>
          <div class="panel-b"><Donut :segments="methodSegs" center-label="collected" :center-value="money((collections?.by_method || []).reduce((s, m) => s + m.amount, 0))" :fmt="money" /></div>
        </div>
      </div>
      <div class="panel">
        <div class="panel-h">
          <div class="t"><span class="pi">🏢</span>Profit &amp; loss · {{ pnl?.month ? shortMonth(pnl.month) : shortMonth(month) }}</div>
          <button class="btn-ghost" style="padding:5px 10px;font-size:11.5px" @click="csvOverview">⬇ CSV</button>
        </div>
        <div class="tbl-wrap">
          <table class="kr">
            <thead><tr><th>Property</th><th>Gross</th><th>Collected</th><th>TDS</th><th>Service</th><th>Expenses</th><th>Net</th></tr></thead>
            <tbody>
              <tr v-for="p in pnlRows" :key="p.prop">
                <td><span class="c-name">{{ p.name }}</span><div class="c-sub">{{ p.type }}</div></td>
                <td>{{ money(p.gross) }}</td><td>{{ money(p.collected) }}</td><td>{{ money(p.tds) }}</td>
                <td>{{ money(p.service) }}</td><td>{{ money(p.expenses) }}</td>
                <td style="font-weight:800" :style="kpiStyle(p.net >= 0 ? C.green : C.red)">{{ money(p.net) }}</td>
              </tr>
              <tr v-if="!pnlRows.length"><td colspan="7" class="m">No data for this month.</td></tr>
            </tbody>
            <tfoot v-if="pnlRows.length">
              <tr style="background:var(--bg-alt);font-weight:800">
                <td>Total</td><td>{{ money(pnlTotals.gross) }}</td><td>{{ money(pnlTotals.collected) }}</td>
                <td>{{ money(pnlTotals.tds) }}</td><td>{{ money(pnlTotals.service) }}</td><td>{{ money(pnlTotals.expenses) }}</td>
                <td :style="{ color: (pnlTotals.net || 0) >= 0 ? C.green : C.red }">{{ money(pnlTotals.net) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </template>

    <!-- ══ CASHFLOW ══ -->
    <template v-if="tab === 'cashflow'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">📥</span>12-mo income</div><div class="s-value">{{ money(cashflow?.total_income) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📤</span>12-mo expenses</div><div class="s-value" style="color:var(--danger)">{{ money(cashflow?.total_expenses) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🎯</span>Net flow</div><div class="s-value" :style="kpiStyle((cashflow?.total_net || 0) >= 0 ? C.green : C.red)">{{ money(cashflow?.total_net) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⚖️</span>Expense ratio</div><div class="s-value">{{ cashflow?.expense_ratio ?? 0 }}%</div></div>
      </div>
      <div class="panel">
        <div class="panel-h">
          <div class="t"><span class="pi">💸</span>Income vs expenses · 12 months</div>
          <button class="btn-ghost" style="padding:5px 10px;font-size:11.5px" @click="csvCashflow">⬇ CSV</button>
        </div>
        <div class="panel-b">
          <LineChart :series="[
            { name: 'Income', color: C.blue, points: cfMonths.map(m => m.income) },
            { name: 'Expenses', color: C.red, points: cfMonths.map(m => m.expenses) },
          ]" :labels="cfLabels" :fmt="money" />
        </div>
      </div>
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">📈</span>Cumulative net position</div></div>
        <div class="panel-b">
          <LineChart :series="[{ name: 'Cumulative', color: C.green, points: cfMonths.map(m => m.cumulative) }]" :labels="cfLabels" :fmt="money" :area="false" />
        </div>
      </div>
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">📅</span>Monthly detail</div></div>
        <div class="tbl-wrap">
          <table class="kr">
            <thead><tr><th>Month</th><th>Income</th><th>Expenses</th><th>Net</th><th>Cumulative</th></tr></thead>
            <tbody>
              <tr v-for="m in cfMonths" :key="m.month">
                <td><span class="c-name">{{ shortMonth(m.month) }}</span></td>
                <td>{{ money(m.income) }}</td><td>{{ money(m.expenses) }}</td>
                <td :style="{ fontWeight: 800, color: m.net >= 0 ? C.green : C.red }">{{ money(m.net) }}</td>
                <td :style="{ fontWeight: 700, color: m.cumulative >= 0 ? 'var(--text)' : C.red }">{{ money(m.cumulative) }}</td>
              </tr>
              <tr v-if="!cfMonths.length"><td colspan="5" class="m">No data.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ══ COLLECTIONS ══ -->
    <template v-if="tab === 'collections'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">✅</span>On-time rate</div><div class="s-value" :style="kpiStyle((collections?.on_time_rate || 0) >= 70 ? C.green : C.orange)">{{ collections?.on_time_rate ?? 0 }}%</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🐢</span>Avg days late</div><div class="s-value">{{ collections?.avg_days_late ?? 0 }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⚠️</span>Late amount</div><div class="s-value" style="color:var(--danger)">{{ money(collections?.late_amount) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🧾</span>Payments</div><div class="s-value">{{ collections?.payments ?? 0 }}</div></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
        <div class="panel">
          <div class="panel-h">
            <div class="t"><span class="pi">💳</span>By payment method</div>
            <button class="btn-ghost" style="padding:5px 10px;font-size:11.5px" @click="csvCollections">⬇ CSV</button>
          </div>
          <div class="panel-b"><Donut :segments="methodSegs" center-label="collected" :center-value="money((collections?.by_method || []).reduce((s, m) => s + m.amount, 0))" :fmt="money" /></div>
        </div>
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">📈</span>Collection rate · 12 months</div></div>
          <div class="panel-b">
            <div v-for="m in collections?.by_month || []" :key="m.month" style="display:flex;align-items:center;gap:10px;margin-bottom:7px">
              <span style="width:60px;font-size:11.5px;font-weight:700;color:var(--text-mute)">{{ shortMonth(m.month) }}</span>
              <div style="flex:1;height:11px;background:var(--bg-alt);border-radius:6px;overflow:hidden">
                <div :style="{ width: Math.min(100, m.rate) + '%', height: '100%', background: m.rate >= 80 ? C.green : m.rate >= 60 ? C.amber : C.red, borderRadius: 6 }"></div>
              </div>
              <span style="width:86px;text-align:right;font-size:11.5px;font-weight:700">{{ money(m.collected) }} <span style="color:var(--text-mute);font-weight:600">· {{ m.rate }}%</span></span>
            </div>
            <div v-if="!(collections?.by_month || []).length" class="c-sub">No data.</div>
          </div>
        </div>
      </div>
    </template>

    <!-- ══ EXPENSES ══ -->
    <template v-if="tab === 'expenses'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">💰</span>Total cost</div><div class="s-value">{{ money(expenses?.total_all) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">✅</span>Paid out</div><div class="s-value">{{ money(expenses?.total_paid) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⏳</span>Open est.</div><div class="s-value" style="color:var(--danger)">{{ money(expenses?.estimated_open) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🛠️</span>Avg job cost</div><div class="s-value">{{ money(expenses?.avg_job_cost) }}</div></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
        <div class="panel">
          <div class="panel-h">
            <div class="t"><span class="pi">🗂️</span>By category</div>
            <button class="btn-ghost" style="padding:5px 10px;font-size:11.5px" @click="csvExpenses">⬇ CSV</button>
          </div>
          <div class="panel-b"><Donut :segments="expCatSegs" center-label="total" :center-value="money(expenses?.total_all)" :fmt="money" /></div>
        </div>
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">🧑‍🔧</span>Top vendors by cost</div></div>
          <div class="panel-b"><HBars :rows="expVendorRows" color="#e67e22" :fmt="money" /></div>
        </div>
      </div>
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">📅</span>Paid expense trend · 12 months</div></div>
        <div class="panel-b">
          <LineChart :series="[{ name: 'Expenses', color: C.orange, points: (expenses?.trend || []).map(t => t.cost) }]" :labels="(expenses?.trend || []).map(t => shortMonth(t.month))" :fmt="money" />
        </div>
      </div>
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">🏢</span>By property</div></div>
        <div class="tbl-wrap">
          <table class="kr">
            <thead><tr><th>Property</th><th>Jobs</th><th>Open</th><th>Cost</th></tr></thead>
            <tbody>
              <tr v-for="p in expenses?.by_property || []" :key="p.prop">
                <td><span class="c-name">{{ p.name || p.prop }}</span></td><td>{{ p.n }}</td>
                <td><span :style="{ color: p.open ? C.orange : C.green, fontWeight: 700 }">{{ p.open }}</span></td>
                <td>{{ money(p.cost) }}</td>
              </tr>
              <tr v-if="!(expenses?.by_property || []).length"><td colspan="4" class="m">No expenses.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ══ MAINTENANCE ══ -->
    <template v-if="tab === 'maintenance'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">💰</span>Total cost</div><div class="s-value">{{ money(maintenance?.total_cost) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🔧</span>Open tickets</div><div class="s-value" style="color:var(--danger)">{{ maintenance?.open_count ?? 0 }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">✅</span>Resolved</div><div class="s-value">{{ maintenance?.done_count ?? 0 }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⏱️</span>Avg resolve</div><div class="s-value">{{ maintenance?.avg_resolve_days ?? 0 }} d</div></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">🚦</span>By status</div></div>
          <div class="panel-b">
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
              <span v-for="s in maintenance?.by_status || []" :key="s.status" style="padding:6px 12px;border-radius:20px;font-size:12px;font-weight:800;background:rgba(47,128,237,.1);color:var(--text)">{{ s.status }} · {{ s.n }}</span>
              <span v-if="!(maintenance?.by_status || []).length" class="c-sub">No tickets.</span>
            </div>
            <HBars :rows="mPrioRows" empty="No priorities." />
          </div>
        </div>
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">🧾</span>Charge to</div></div>
          <div class="panel-b">
            <table class="kr compact">
              <thead><tr><th>Party</th><th>Jobs</th><th>Cost</th></tr></thead>
              <tbody>
                <tr v-for="c in maintenance?.by_charge || []" :key="c.charge_to">
                  <td><span class="c-name">{{ c.charge_to }}</span></td><td>{{ c.n }}</td><td>{{ money(c.cost) }}</td>
                </tr>
                <tr v-if="!(maintenance?.by_charge || []).length"><td colspan="3" class="m">No data.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="panel">
        <div class="panel-h">
          <div class="t"><span class="pi">⏰</span>Oldest open tickets</div>
          <button class="btn-ghost" style="padding:5px 10px;font-size:11.5px" @click="csvMaintenance">⬇ CSV</button>
        </div>
        <div class="tbl-wrap">
          <table class="kr">
            <thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Priority</th><th>Open days</th></tr></thead>
            <tbody>
              <tr v-for="a in maintenance?.aging || []" :key="a.id">
                <td><span class="c-name">{{ a.id }}</span></td>
                <td>{{ a.title }}</td>
                <td><span class="badge" :class="(a.status === 'Open' || a.status === 'In Progress') ? 'b-orange' : a.status === 'Pending' ? 'b-blue' : 'b-gray'">{{ a.status }}</span></td>
                <td><span class="badge" :class="a.priority === 'urgent' ? 'b-red' : a.priority === 'high' ? 'b-orange' : a.priority === 'medium' ? 'b-blue' : 'b-gray'">{{ a.priority }}</span></td>
                <td :style="{ fontWeight: 800, color: a.days > 14 ? C.red : a.days > 7 ? C.orange : 'var(--text)' }">{{ a.days }}d</td>
              </tr>
              <tr v-if="!(maintenance?.aging || []).length"><td colspan="5" class="m">All caught up 🎉</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ══ TENANTS ══ -->
    <template v-if="tab === 'tenants'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">👥</span>Scored tenants</div><div class="s-value">{{ scores?.total ?? 0 }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🎯</span>Avg score</div><div class="s-value">{{ scores?.avg_score ?? 0 }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⚠️</span>At risk</div><div class="s-value" style="color:var(--danger)">{{ (scores?.at_risk || []).length }}</div></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">🏆</span>Risk distribution</div></div>
          <div class="panel-b"><Donut :segments="bandSegs" center-label="tenants" :center-value="String(scores?.total ?? 0)" :fmt="(v) => String(v)" /></div>
        </div>
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">🎚️</span>Band legend</div></div>
          <div class="panel-b">
            <div v-for="(v, k) in scores?.bands || {}" :key="k" style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px dashed var(--border)">
              <span style="display:inline-flex;align-items:center;gap:8px;font-weight:700;font-size:13px"><i :style="{ width: 10, height: 10, borderRadius: 3, background: BAND_COLORS[k], display: 'inline-block' }"></i>{{ k }}</span>
              <span style="font-weight:800">{{ v }}</span>
            </div>
            <div v-if="!scores?.total" class="c-sub">No tenants scored.</div>
          </div>
        </div>
      </div>
      <div class="panel">
        <div class="panel-h">
          <div class="t"><span class="pi">🚨</span>At-risk tenants (Fair / Risky)</div>
          <button class="btn-ghost" style="padding:5px 10px;font-size:11.5px" @click="csvTenants">⬇ CSV</button>
        </div>
        <div class="tbl-wrap">
          <table class="kr">
            <thead><tr><th>Tenant</th><th>Band</th><th>Score</th><th>Overdue</th><th>On-time</th><th>Tenure</th><th>Tickets</th></tr></thead>
            <tbody>
              <tr v-for="t in scores?.at_risk || []" :key="t.id">
                <td><span class="c-name">{{ t.name }}</span><div class="c-sub">{{ t.id }} · {{ t.kind }}</div></td>
                <td><span class="badge" :class="t.band === 'Risky' ? 'b-red' : t.band === 'Fair' ? 'b-orange' : t.band === 'Good' ? 'b-blue' : 'b-green'">{{ t.band }}</span></td>
                <td style="font-weight:800" :style="{ color: BAND_COLORS[t.band] }">{{ t.score }}</td>
                <td :style="{ color: t.overdue ? C.red : 'var(--text)', fontWeight: 600 }">{{ t.overdue || '—' }}</td><td>{{ t.on_time }}%</td><td>{{ t.tenure }} mo</td>
                <td :style="{ color: t.tickets_open ? C.orange : 'var(--text-mute)' }">{{ t.tickets_open }}</td>
              </tr>
              <tr v-if="!(scores?.at_risk || []).length"><td colspan="7" class="m">No at-risk tenants 🎉</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ══ AGING ══ -->
    <template v-if="tab === 'aging'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">⏳</span>Total arrears</div><div class="s-value" style="color:var(--danger)">{{ money(agingTotal) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⚠️</span>90+ days</div><div class="s-value" style="color:var(--danger)">{{ money(aging.value?.d90) }}</div></div>
      </div>
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">⏰</span>Outstanding by age</div></div>
        <div class="panel-b"><Donut :segments="agingSegs" center-label="arrears" :center-value="money(agingTotal)" :fmt="money" /></div>
      </div>
    </template>

    <!-- ══ VACANCY ══ -->
    <template v-if="tab === 'vacancy'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🏚️</span>Vacant units</div><div class="s-value">{{ occupancy?.vacant ?? vacancy?.count ?? 0 }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📉</span>Monthly loss</div><div class="s-value" style="color:var(--danger)">{{ money(occupancy?.vacancy_loss ?? vacancy?.monthly_loss) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📅</span>Annual loss</div><div class="s-value" style="color:var(--danger)">{{ money((occupancy?.vacancy_loss ?? (vacancy?.monthly_loss || 0)) * 12) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🔑</span>Rent roll</div><div class="s-value">{{ money(occupancy?.rent_roll) }}</div></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
        <div class="panel">
          <div class="panel-h">
            <div class="t"><span class="pi">🏢</span>Occupancy by property</div>
            <button class="btn-ghost" style="padding:5px 10px;font-size:11.5px" @click="csvOccupancy">⬇ CSV</button>
          </div>
          <div class="panel-b"><HBars :rows="occBars" color="#2F80ED" :fmt="(v) => v + '%'" /></div>
        </div>
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">⏳</span>Leases expiring · 90 days</div></div>
          <div class="tbl-wrap">
            <table class="kr compact">
              <thead><tr><th>Lease</th><th>Unit</th><th>Tenant</th><th>Expires</th></tr></thead>
              <tbody>
                <tr v-for="e in occupancy?.expiries || []" :key="e.id">
                  <td><span class="c-name">{{ e.id }}</span></td><td>{{ e.unit }}</td><td>{{ e.tenant }}</td>
                  <td style="font-weight:700" :style="{ color: C.orange }">{{ shortDate(e.end) }}</td>
                </tr>
                <tr v-if="!(occupancy?.expiries || []).length"><td colspan="4" class="m">No expiries in the next 90 days.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">🏚️</span>Vacant units</div></div>
        <div class="tbl-wrap">
          <table class="kr">
            <thead><tr><th>Unit</th><th>Property</th><th>Rent</th></tr></thead>
            <tbody>
              <tr v-for="u in vacancy?.units || []" :key="u.id">
                <td><span class="c-name">{{ u.name }}</span><div class="c-sub">{{ u.id }}</div></td>
                <td>{{ u.prop }}</td><td>{{ money(u.rent) }}</td>
              </tr>
              <tr v-if="!(vacancy?.units || []).length"><td colspan="3" class="m">No vacant units 🎉</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ══ FORECAST ══ -->
    <template v-if="tab === 'forecast'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">📈</span>Collection rate</div><div class="s-value">{{ forecast?.collection_rate || 0 }}%</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💵</span>Avg / month</div><div class="s-value">{{ money(forecast?.avg_collected) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🗓️</span>12-mo forecast</div><div class="s-value">{{ money(forecast?.total_forecast) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⚠️</span>Top risk</div><div class="s-value" style="font-size:12px;color:var(--danger)">{{ forecast?.top_risk || '—' }}</div></div>
      </div>
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">🔮</span>Next 12 months projection</div></div>
        <div class="panel-b">
          <LineChart :series="[
            { name: 'Expected', color: C.blue, points: fcMonths.map(m => m.expected) },
            { name: 'Collected', color: C.green, points: fcMonths.map(m => m.collected) },
          ]" :labels="fcMonths.map(m => shortMonth(m.month))" :fmt="money" />
        </div>
      </div>
    </template>

    <!-- ══ BOARD ══ -->
    <template v-if="tab === 'board'">
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">📋</span>Board report · {{ boardMonth || month }}</div>
          <div style="display:flex;gap:8px;align-items:center">
            <button class="btn-ghost" style="padding:8px 14px;font-size:12.5px" :disabled="!boardMd" @click="printReport">🖨 Print</button>
            <button class="btn-primary" style="padding:8px 14px;font-size:12.5px" :disabled="loading" @click="genBoard">＋ Generate</button>
          </div>
        </div>
        <div class="panel-b" style="max-height:72vh;overflow:auto;padding:22px 24px" id="boardReport">
          <div v-if="boardMd" class="board-report" v-html="mdToHtml(boardMd)"></div>
          <div v-else class="c-sub">Generate a board report — an executive summary of this month's portfolio (P&amp;L, collections, arrears, renewals, risks).</div>
        </div>
      </div>
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">🗂️</span>Past reports</div></div>
        <div class="tbl-wrap">
          <table class="kr">
            <thead><tr><th>ID</th><th>Month</th><th>Created by</th><th>When</th><th></th></tr></thead>
            <tbody>
              <tr v-for="b in boards" :key="b.id">
                <td><span class="c-name">{{ b.id }}</span></td><td>{{ b.month }}</td><td>{{ b.created_by }}</td><td>{{ b.ts }}</td>
                <td><button class="btn-ghost" style="padding:4px 10px;font-size:11.5px" @click="viewBoard(b)">👁 View</button></td>
              </tr>
              <tr v-if="!boards.length"><td colspan="5" class="m">No reports yet.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
