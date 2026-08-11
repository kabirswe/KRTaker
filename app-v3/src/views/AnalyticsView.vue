<script setup>
import { ref, computed, onMounted } from 'vue'
import { apiCall } from '../api/client'

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const tab = ref('overview')
const loading = ref(false)
const err = ref('')
const toast = ref('')

// ── shared month ──
const now = new Date()
const month = ref(now.toISOString().slice(0, 7))

// ── Overview: P&L per property (app-analytics pnl) ──
const pnl = ref(null)
async function loadPnl() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-analytics', { action: 'pnl', month: month.value })
    if (!r.ok) { err.value = r.error || 'Failed to load P&L.'; return }
    pnl.value = r
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}
const pnlRows = computed(() => pnl.value?.properties || [])
const pnlTotals = computed(() => pnl.value?.totals || {})

// ── Trends: 12-month issued vs collected ──
const trends = ref(null)
async function loadTrends() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-analytics', { action: 'trends', months: 12 })
    if (!r.ok) { err.value = r.error || 'Failed to load trends.'; return }
    trends.value = r
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}
const trendMonths = computed(() => trends.value?.months || [])
const trendMax = computed(() => Math.max(1, ...trendMonths.value.map(m => Math.max(m.issued, m.collected))))

// ── Aging: 30/60/90+ buckets ──
const aging = ref(null)
async function loadAging() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-analytics', { action: 'aging' })
    if (!r.ok) { err.value = r.error || 'Failed to load aging.'; return }
    aging.value = r.buckets
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}
const agingBuckets = computed(() => {
  const b = aging.value || {}
  return [
    ['Current month', b.current || 0, '#12a150'],
    ['1 month late', b.d30 || 0, '#f6a609'],
    ['2 months late', b.d60 || 0, '#e67e22'],
    ['3+ months late', b.d90 || 0, '#e74c3c'],
  ]
})
const agingTotal = computed(() => aging.value?.total || 0)
const agingMax = computed(() => Math.max(1, ...agingBuckets.value.map(x => x[1])))

// ── Vacancy ──
const vacancy = ref(null)
async function loadVacancy() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-analytics', { action: 'vacancy' })
    if (!r.ok) { err.value = r.error || 'Failed to load vacancy.'; return }
    vacancy.value = r
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}

// ── Forecast ──
const forecast = ref(null)
async function loadForecast() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-analytics', { action: 'forecast' })
    if (!r.ok) { err.value = r.error || 'Failed to load forecast.'; return }
    forecast.value = r
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}
const fcMonths = computed(() => forecast.value?.months || [])
const fcMax = computed(() => Math.max(1, ...fcMonths.value.map(m => Math.max(m.expected, m.collected))))

// ── Board reports ──
const boards = ref([])
const boardMd = ref('')
const boardId = ref('')
const boardMonth = ref('')
async function loadBoards() {
  try {
    const r = await apiCall('app-analytics', { action: 'boards' })
    if (r.ok) boards.value = r.reports || []
  } catch (e) { /* non-fatal */ }
}
async function genBoard() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-analytics', { action: 'board', month: month.value })
    if (!r.ok) { err.value = r.error || 'Board generation failed.'; return }
    boardId.value = r.id; boardMonth.value = r.month; boardMd.value = r.markdown || ''
    toast.value = `📊 ${r.id} generated for ${r.month}`
    setTimeout(() => toast.value = '', 4000)
    await loadBoards()
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}
function viewBoard(b) {
  boardId.value = b.id; boardMonth.value = b.month; boardMd.value = '…'
  apiCall('app-analytics', { action: 'board', month: b.month }).then(r => {
    if (r.ok) { boardId.value = r.id; boardMonth.value = r.month; boardMd.value = r.markdown || '' }
  })
}

function switchTab(t) {
  tab.value = t
  if (t === 'overview') loadPnl()
  else if (t === 'trends') loadTrends()
  else if (t === 'aging') loadAging()
  else if (t === 'vacancy') loadVacancy()
  else if (t === 'forecast') loadForecast()
  else if (t === 'board') loadBoards()
}

onMounted(() => { loadPnl(); loadTrends(); loadAging(); loadVacancy(); loadForecast(); loadBoards() })
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>📈 Analytics</h1>
        <div class="sub">P&amp;L, trends, arrears aging, vacancy &amp; forecast — live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="month" type="month" style="padding:9px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none" @change="switchTab(tab)">
        <button class="btn-ghost" @click="switchTab(tab)">🔄 Refresh</button>
      </div>
    </div>

    <div v-if="toast" style="padding:10px 14px;border-radius:10px;background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.35);margin-bottom:14px;font-weight:700;font-size:13.5px">{{ toast }}</div>
    <div v-if="err" style="padding:10px 14px;border-radius:10px;background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.35);margin-bottom:14px;font-weight:600;font-size:13.5px">⚠️ {{ err }}</div>

    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">
      <button v-for="t in [['overview','📊 Overview'],['trends','📅 Trends'],['aging','⏳ Aging'],['vacancy','🏚️ Vacancy'],['forecast','🔮 Forecast'],['board','📋 Board']]" :key="t[0]" class="btn-ghost" :style="tab === t[0] ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : ''" @click="switchTab(t[0])">{{ t[1] }}</button>
    </div>

    <!-- Overview: P&L -->
    <template v-if="tab === 'overview'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🧾</span>Gross rent</div><div class="s-value">{{ money(pnlTotals.gross) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💰</span>Collected</div><div class="s-value">{{ money(pnlTotals.collected) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📉</span>TDS</div><div class="s-value">{{ money(pnlTotals.tds) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🧹</span>Service</div><div class="s-value">{{ money(pnlTotals.service) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🛠️</span>Expenses</div><div class="s-value">{{ money(pnlTotals.expenses) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🎯</span>Net</div><div class="s-value" :style="{ color: (pnlTotals.net || 0) >= 0 ? 'var(--ok,#12a150)' : 'var(--danger)' }">{{ money(pnlTotals.net) }}</div></div>
      </div>
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">🏢</span>Profit &amp; loss · {{ pnl?.month || month }}</div></div>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>Property</th><th>Gross</th><th>Collected</th><th>TDS</th><th>Service</th><th>Expenses</th><th>Net</th></tr></thead>
            <tbody>
              <tr v-for="p in pnlRows" :key="p.prop">
                <td><span class="c-name">{{ p.name }}</span><div class="c-sub">{{ p.type }}</div></td>
                <td>{{ money(p.gross) }}</td>
                <td>{{ money(p.collected) }}</td>
                <td>{{ money(p.tds) }}</td>
                <td>{{ money(p.service) }}</td>
                <td>{{ money(p.expenses) }}</td>
                <td style="font-weight:800" :style="{ color: p.net >= 0 ? '#12a150' : 'var(--danger)' }">{{ money(p.net) }}</td>
              </tr>
              <tr v-if="!pnlRows.length"><td colspan="7" class="m">No data for this month.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- Trends -->
    <template v-if="tab === 'trends'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🏠</span>Units</div><div class="s-value">{{ trends?.units || 0 }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🔑</span>Leased</div><div class="s-value">{{ trends?.leased || 0 }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📊</span>Occupancy</div><div class="s-value">{{ trends?.occupancy || 0 }}%</div></div>
      </div>
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">📅</span>Issued vs collected · last 12 months</div></div>
        <div class="panel-b">
          <div v-for="m in trendMonths" :key="m.month" style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
            <span style="width:64px;font-size:11.5px;font-weight:700;color:var(--text-mute)">{{ m.month }}</span>
            <div style="flex:1;display:flex;gap:3px;align-items:center">
              <div :style="{ width: (m.issued / trendMax * 100) + '%', minWidth: '2px', height: 16, background: 'var(--primary)', opacity: .85, borderRadius: 4 }" :title="'Issued ' + money(m.issued)"></div>
              <div :style="{ width: (m.collected / trendMax * 100) + '%', minWidth: '2px', height: 16, background: '#12a150', opacity: .75, borderRadius: 4 }" :title="'Collected ' + money(m.collected)"></div>
            </div>
            <span style="width:120px;text-align:right;font-size:11.5px">{{ money(m.issued) }} / {{ money(m.collected) }}</span>
          </div>
          <div v-if="!trendMonths.length" class="c-sub">No data.</div>
        </div>
      </div>
    </template>

    <!-- Aging -->
    <template v-if="tab === 'aging'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">⏳</span>Total arrears</div><div class="s-value" style="color:var(--danger)">{{ money(agingTotal) }}</div></div>
      </div>
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">⏰</span>Outstanding by age</div></div>
        <div class="panel-b">
          <div v-for="[label, val, color] in agingBuckets" :key="label" style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;font-size:12.5px;font-weight:700;margin-bottom:4px"><span>{{ label }}</span><span>{{ money(val) }}</span></div>
            <div style="height:10px;background:var(--bg-alt);border-radius:6px;overflow:hidden"><div :style="{ width: (val / agingMax * 100) + '%', height: '100%', background: color, borderRadius: 6 }"></div></div>
          </div>
          <div v-if="!agingTotal" class="c-sub">All paid 🎉</div>
        </div>
      </div>
    </template>

    <!-- Vacancy -->
    <template v-if="tab === 'vacancy'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🏚️</span>Vacant units</div><div class="s-value">{{ vacancy?.count || 0 }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📉</span>Monthly loss</div><div class="s-value" style="color:var(--danger)">{{ money(vacancy?.monthly_loss) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📅</span>Annual loss</div><div class="s-value" style="color:var(--danger)">{{ money(vacancy?.annual_loss) }}</div></div>
      </div>
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">🏚️</span>Vacant units</div></div>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>Unit</th><th>Property</th><th>Rent</th></tr></thead>
            <tbody>
              <tr v-for="u in vacancy?.units || []" :key="u.id">
                <td><span class="c-name">{{ u.name }}</span><div class="c-sub">{{ u.id }}</div></td>
                <td>{{ u.prop }}</td>
                <td>{{ money(u.rent) }}</td>
              </tr>
              <tr v-if="!(vacancy?.units || []).length"><td colspan="3" class="m">No vacant units 🎉</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- Forecast -->
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
          <div v-for="m in fcMonths" :key="m.month" style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
            <span style="width:64px;font-size:11.5px;font-weight:700;color:var(--text-mute)">{{ m.month }}</span>
            <div style="flex:1;display:flex;gap:3px;align-items:center">
              <div :style="{ width: (m.expected / fcMax * 100) + '%', minWidth: '2px', height: 14, background: 'var(--primary)', opacity: .8, borderRadius: 4 }" :title="'Expected ' + money(m.expected)"></div>
              <div :style="{ width: (m.collected / fcMax * 100) + '%', minWidth: '2px', height: 14, background: '#12a150', opacity: .7, borderRadius: 4 }" :title="'Collected ' + money(m.collected)"></div>
            </div>
            <span style="width:120px;text-align:right;font-size:11.5px">{{ money(m.expected) }} / {{ money(m.collected) }}</span>
          </div>
          <div v-if="!fcMonths.length" class="c-sub">No data.</div>
        </div>
      </div>
    </template>

    <!-- Board -->
    <template v-if="tab === 'board'">
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">📋</span>Board report · {{ boardMonth || month }}</div>
          <button class="btn-primary" style="padding:8px 14px;font-size:12.5px" :disabled="loading" @click="genBoard">＋ Generate</button>
        </div>
        <div class="panel-b" style="max-height:420px;overflow:auto">
          <pre v-if="boardMd" style="white-space:pre-wrap;font-family:inherit;font-size:12.5px;line-height:1.6;color:var(--text);margin:0">{{ boardMd }}</pre>
          <div v-else class="c-sub">Generate a board report — an executive summary of this month's portfolio (P&amp;L, collections, arrears, renewals, risks).</div>
        </div>
      </div>
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">🗂️</span>Past reports</div></div>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>ID</th><th>Month</th><th>Created by</th><th>When</th><th></th></tr></thead>
            <tbody>
              <tr v-for="b in boards" :key="b.id">
                <td><span class="c-name">{{ b.id }}</span></td>
                <td>{{ b.month }}</td>
                <td>{{ b.created_by }}</td>
                <td>{{ b.ts }}</td>
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
