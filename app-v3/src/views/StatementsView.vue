<script setup>
// 💰 Statements + ⚙️ Rent Config (V2.11.0 redesign)
// Finance hub tab — monthly owner statements (per-property P&L, line items,
// payouts) + per-property rent configuration. Pure frontend redesign; the
// API contract (app-statements / app-rent-config-*) is unchanged.
import { ref, computed, onMounted } from 'vue'
import { apiCall } from '../api/client'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'

const data = useDataStore()
const auth = useAuthStore()
const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')

const tab = ref('statements')

// ── month navigator ──
const now = new Date()
const month = ref(now.toISOString().slice(0, 7))
const monthLabel = computed(() => {
  const [y, mo] = month.value.split('-')
  return (['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][parseInt(mo, 10) - 1] || mo) + ' ' + y
})
function shiftMonth(d) {
  const [y, mo] = month.value.split('-').map(Number)
  const dt = new Date(y, mo - 1 + d, 1)
  month.value = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0')
  loadList()
}
const isCurrentMonth = computed(() => month.value === now.toISOString().slice(0, 7))

const loading = ref(false)
const err = ref('')
const toast = ref('')
const netColor = (n) => (n || 0) >= 0 ? '#12a150' : 'var(--danger)'
const netStyle = (n) => 'color:' + netColor(n)

// ── statements ──
const list = ref([])          // [{prop,name,type,gross,collected,tds,service,expenses,net,payout}]
async function loadList() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-statements', { action: 'list', month: month.value })
    if (!r.ok) { err.value = r.error || 'Failed to load statements.'; return }
    list.value = r.statements || []
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}
const totals = computed(() => {
  const t = { gross: 0, collected: 0, tds: 0, service: 0, expenses: 0, net: 0 }
  list.value.forEach(s => { t.gross += s.gross || 0; t.collected += s.collected || 0; t.tds += s.tds || 0; t.service += s.service || 0; t.expenses += s.expenses || 0; t.net += s.net || 0 })
  return t
})
const collectRate = computed(() => totals.value.gross > 0 ? Math.round((totals.value.collected / totals.value.gross) * 100) : 0)
const barPct = (s) => (s.gross > 0 ? Math.min(100, Math.round(((s.collected || 0) / s.gross) * 100)) : 0)
const shortName = (s) => (s.name || s.prop || 'P').split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
const propTint = (s) => {
  const tints = ['#3b82f6', '#8b5cf6', '#0ea5e9', '#f59e0b', '#10b981', '#ef4444', '#ec4899', '#14b8a6']
  let h = 0; for (const c of (s.prop || 'P')) h = (h * 31 + c.charCodeAt(0)) >>> 0
  return tints[h % tints.length]
}

// ── detail drawer ──
const sel = ref(null)         // statement detail
const detail = ref(null)      // { lines[], expense_items[], payout, ... }
const busy = ref(false)
async function openDetail(s) {
  sel.value = s; detail.value = null; err.value = ''
  busy.value = true
  try {
    const r = await apiCall('app-statements', { action: 'detail', prop: s.prop, month: month.value })
    if (!r.ok) { err.value = r.error || 'Failed to load detail.'; return }
    detail.value = r
  } catch (e) { err.value = e.message }
  finally { busy.value = false }
}
const dTot = (f) => (detail.value?.lines || []).reduce((a, l) => a + (l[f] || 0), 0)

// ── payout modal ──
const pay = ref(null)
const payOpen = ref(false)
function openPayout(s) {
  pay.value = {
    prop: s.prop, name: s.name, month: month.value,
    amount: s.net || 0, status: s.payout?.status || 'Scheduled',
    method: s.payout?.method || 'Bank', ref: s.payout?.ref || '',
  }
  payOpen.value = true
}
async function savePayout() {
  if (!pay.value || pay.value.amount == null) return
  if (!confirm(`Record ${pay.value.status} payout of ${money(pay.value.amount)} for ${pay.value.name} (${pay.value.month})?`)) return
  err.value = ''; busy.value = true
  try {
    const r = await apiCall('app-statements', {
      action: 'payout', prop: pay.value.prop, month: pay.value.month,
      amount: Math.round(+pay.value.amount || 0), status: pay.value.status,
      method: pay.value.method, ref: pay.value.ref,
    })
    if (!r.ok) { err.value = r.error || 'Payout failed.'; return }
    payOpen.value = false
    toast.value = `✅ Payout recorded for ${pay.value.name}`
    setTimeout(() => toast.value = '', 4000)
    await loadList()
    await data.bootstrap()
    if (sel.value) await openDetail(sel.value)
  } catch (e) { err.value = e.message }
  finally { busy.value = false }
}

// local payout ledger (from bootstrap)
const payouts = computed(() => (data.list('statement_payouts') || [])
  .filter(p => !month.value || (p.month || '').startsWith(month.value.slice(0, 7)))
  .sort((a, b) => String(b.month).localeCompare(String(a.month))))
const paidCount = computed(() => payouts.value.filter(p => p.status === 'Paid').length)
const payoutTotal = computed(() => payouts.value.reduce((a, p) => a + (p.amount || 0), 0))

// ── rent config ──
const rentCfg = ref([])        // [{prop, property, config, mix, units}]
const rentLoading = ref(false)
const rentSaving = ref(false)
const editProp = ref(null)
const editForm = ref({})
const canEditRent = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))

async function loadRentConfig() {
  rentLoading.value = true; err.value = ''
  try {
    const r = await apiCall('app-rent-config-get')
    if (!r.ok) { err.value = r.error || 'Failed to load rent config.'; return }
    rentCfg.value = r.rent_configs || []
  } catch (e) { err.value = e.message }
  finally { rentLoading.value = false }
}
function openEdit(p) {
  editProp.value = p.prop
  editForm.value = {
    service_charge_pct: p.config.service_charge_pct || 0,
    utility_advance: p.config.utility_advance || 0,
    parking_fee: p.config.parking_fee || 0,
    escalation_pct: p.config.escalation_pct || 0,
    advance_months: p.config.advance_months || 0,
    due_day: p.config.due_day || 5,
    late_fee_pct: p.config.late_fee_pct || 0,
    rent_per_sqft: p.config.rent_per_sqft || 0,
    notes: p.config.notes || '',
  }
}
async function saveRentConfig() {
  if (!editProp.value) return
  if (!confirm(`Save rent configuration for this property?`)) return
  rentSaving.value = true; err.value = ''
  try {
    const r = await apiCall('app-rent-config-save', { prop: editProp.value, config: editForm.value })
    if (!r.ok) { err.value = r.error || 'Failed to save rent config.'; return }
    editProp.value = null
    toast.value = '✅ Rent config saved'
    setTimeout(() => toast.value = '', 4000)
    await loadRentConfig()
  } catch (e) { err.value = e.message }
  finally { rentSaving.value = false }
}
const mixBar = (p) => {
  const base = p.mix?.base || 0, svc = p.mix?.service_charge || 0, pk = p.mix?.parking || 0
  const tot = base + svc + pk || 1
  return {
    base: Math.round((base / tot) * 100),
    svc: Math.round((svc / tot) * 100),
    pk: Math.max(0, 100 - Math.round((base / tot) * 100) - Math.round((svc / tot) * 100)),
  }
}
const rcTotals = computed(() => ({
  base: rentCfg.value.reduce((a, p) => a + (p.mix?.base || 0), 0),
  service: rentCfg.value.reduce((a, p) => a + (p.mix?.service_charge || 0), 0),
  parking: rentCfg.value.reduce((a, p) => a + (p.mix?.parking || 0), 0),
  total: rentCfg.value.reduce((a, p) => a + (p.mix?.total || 0), 0),
}))
const avgEsc = computed(() => {
  const n = rentCfg.value.filter(p => p.config?.escalation_pct).length
  return n ? Math.round((rentCfg.value.reduce((a, p) => a + (+(p.config?.escalation_pct) || 0), 0) / n) * 10) / 10 : 0
})

onMounted(loadList)
</script>

<template>
  <div>
    <!-- ══ HEADER ══ -->
    <div class="page-head">
      <div>
        <h1>💰 Statements &amp; Rent Config</h1>
        <div class="sub">Monthly owner statements — per-property P&amp;L, line items, payouts &amp; rent settings</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <div style="display:flex;align-items:center;gap:6px;background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:4px">
          <button class="btn-ghost" style="padding:5px 10px;font-size:12.5px" @click="shiftMonth(-1)">◀</button>
          <input v-model="month" type="month" style="padding:7px 10px;border:none;background:transparent;font-family:inherit;font-size:13px;font-weight:700;color:var(--text);outline:none" @change="loadList">
          <button class="btn-ghost" style="padding:5px 10px;font-size:12.5px" @click="shiftMonth(1)">▶</button>
        </div>
        <button v-if="!isCurrentMonth" class="btn-ghost" style="font-size:12.5px" @click="month = now.toISOString().slice(0, 7); loadList()">Today</button>
        <button class="btn-ghost" @click="loadList">🔄 Refresh</button>
      </div>
    </div>

    <div v-if="toast" style="padding:10px 14px;border-radius:10px;background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.35);margin-bottom:14px;font-weight:700;font-size:13.5px">{{ toast }}</div>
    <div v-if="err" style="padding:10px 14px;border-radius:10px;background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.35);margin-bottom:14px;font-weight:600;font-size:13.5px">⚠️ {{ err }}</div>

    <!-- Tabs -->
    <div style="display:flex;gap:8px;margin-bottom:16px">
      <button @click="tab = 'statements'" :style="tab === 'statements' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'" style="padding:9px 16px;border:none;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer">💰 Statements</button>
      <button @click="tab = 'rentconfig'; loadRentConfig()" :style="tab === 'rentconfig' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'" style="padding:9px 16px;border:none;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer">⚙️ Rent Config</button>
    </div>

    <!-- ══ STATEMENTS TAB ══ -->
    <template v-if="tab === 'statements'">
      <!-- Hero summary -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:14px">
        <div class="stat" style="border-left:4px solid #3b82f6">
          <div class="s-label"><span class="s-ico">🧾</span>Gross rent</div>
          <div class="s-value">{{ money(totals.gross) }}</div>
          <div class="s-trend">{{ monthLabel }}</div>
        </div>
        <div class="stat" style="border-left:4px solid #10b981">
          <div class="s-label"><span class="s-ico">💰</span>Collected</div>
          <div class="s-value">{{ money(totals.collected) }}</div>
          <div class="s-trend" style="display:flex;align-items:center;gap:8px;margin-top:4px">
            <div style="flex:1;height:6px;background:var(--bg-alt);border-radius:99px;overflow:hidden">
              <div :style="{ width: collectRate + '%', height: '100%', background: 'linear-gradient(90deg,#10b981,#34d399)', borderRadius: '99px' }"></div>
            </div>
            <b>{{ collectRate }}%</b>
          </div>
        </div>
        <div class="stat" style="border-left:4px solid #f59e0b">
          <div class="s-label"><span class="s-ico">📉</span>TDS + Service</div>
          <div class="s-value">{{ money(totals.tds + totals.service) }}</div>
          <div class="s-trend">deductions</div>
        </div>
        <div class="stat" style="border-left:4px solid #ef4444">
          <div class="s-label"><span class="s-ico">🛠️</span>Expenses</div>
          <div class="s-value">{{ money(totals.expenses) }}</div>
          <div class="s-trend">owner-charged</div>
        </div>
        <div class="stat" style="border-left:4px solid #8b5cf6">
          <div class="s-label"><span class="s-ico">🎯</span>Net payout</div>
          <div class="s-value" :style="netStyle(totals.net)">{{ money(totals.net) }}</div>
          <div class="s-trend">{{ list.length }} propert{{ list.length === 1 ? 'y' : 'ies' }}</div>
        </div>
      </div>

      <!-- Statements table -->
      <div class="panel">
        <div class="panel-h">
          <div class="t"><span class="pi">🏢</span>Statements · {{ monthLabel }}</div>
          <span class="badge b-gray" style="font-size:11px">{{ list.length }} propert{{ list.length === 1 ? 'y' : 'ies' }}</span>
        </div>
        <div v-if="loading" style="padding:36px;text-align:center;color:var(--text-mute)">Loading…</div>
        <div v-else-if="!list.length" style="padding:44px;text-align:center;color:var(--text-mute)">No statements for {{ monthLabel }}. Pick another month.</div>
        <div v-else class="tbl-wrap">
          <table>
            <thead><tr><th>Property</th><th>Gross</th><th>Collected</th><th>TDS</th><th>Service</th><th>Expenses</th><th>Net</th><th>Payout</th><th></th></tr></thead>
            <tbody>
              <tr v-for="s in list" :key="s.prop">
                <td>
                  <div style="display:flex;align-items:center;gap:10px">
                    <div style="width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff;flex-shrink:0" :style="{ background: propTint(s) }">{{ shortName(s) }}</div>
                    <div>
                      <div class="c-name">{{ s.name }}</div>
                      <div class="c-sub" style="font-size:11px">{{ s.type }}</div>
                    </div>
                  </div>
                </td>
                <td style="font-weight:700">{{ money(s.gross) }}</td>
                <td>
                  <div style="font-weight:700">{{ money(s.collected) }}</div>
                  <div style="width:72px;height:5px;background:var(--bg-alt);border-radius:99px;overflow:hidden;margin-top:3px">
                    <div :style="{ width: barPct(s) + '%', height: '100%', background: '#10b981', borderRadius: '99px' }"></div>
                  </div>
                </td>
                <td>{{ money(s.tds) }}</td>
                <td>{{ money(s.service) }}</td>
                <td>{{ money(s.expenses) }}</td>
                <td style="font-weight:800" :style="netStyle(s.net)">{{ money(s.net) }}</td>
                <td>
                  <span v-if="s.payout" class="badge" :class="s.payout.status === 'Paid' ? 'b-green' : 'b-orange'" style="font-size:11px">{{ s.payout.status }} {{ money(s.payout.amount) }}</span>
                  <span v-else class="badge b-gray" style="font-size:11px">—</span>
                </td>
                <td style="white-space:nowrap">
                  <button class="btn-ghost" style="padding:4px 10px;font-size:11.5px" @click="openDetail(s)">👁 Detail</button>
                  <button v-if="['superadmin','owner','accountant'].includes(auth.user?.role || '')" class="btn-ghost" style="padding:4px 10px;font-size:11.5px" @click="openPayout(s)">💸 Payout</button>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr style="background:var(--bg-alt);font-weight:800">
                <td style="padding:10px 12px">Total</td>
                <td>{{ money(totals.gross) }}</td>
                <td>{{ money(totals.collected) }}</td>
                <td>{{ money(totals.tds) }}</td>
                <td>{{ money(totals.service) }}</td>
                <td>{{ money(totals.expenses) }}</td>
                <td :style="netStyle(totals.net)">{{ money(totals.net) }}</td>
                <td colspan="2"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Payout ledger -->
      <div class="panel" style="margin-top:14px">
        <div class="panel-h">
          <div class="t"><span class="pi">💸</span>Payout ledger · {{ monthLabel }}</div>
          <span v-if="payouts.length" class="badge b-gray" style="font-size:11px">{{ paidCount }}/{{ payouts.length }} paid · {{ money(payoutTotal) }}</span>
        </div>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>Property</th><th>Month</th><th>Amount</th><th>Status</th><th>Method</th><th>Ref</th></tr></thead>
            <tbody>
              <tr v-for="p in payouts" :key="p.prop + p.month">
                <td><span class="c-name">{{ p.prop }}</span></td>
                <td>{{ p.month }}</td>
                <td style="font-weight:700">{{ money(p.amount) }}</td>
                <td><span class="badge" :class="p.status === 'Paid' ? 'b-green' : 'b-orange'" style="font-size:11px">{{ p.status }}</span></td>
                <td>{{ p.method || '—' }}</td>
                <td style="font-family:monospace;font-size:12px">{{ p.ref || '—' }}</td>
              </tr>
              <tr v-if="!payouts.length"><td colspan="6" class="m">No payouts recorded for this month.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- detail drawer -->
      <div v-if="sel" class="overlay" @click.self="sel = null">
        <div class="drawer">
          <div class="modal-h">
            <span class="t" style="display:flex;align-items:center;gap:10px">
              <span style="width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;color:#fff" :style="{ background: propTint(sel) }">{{ shortName(sel) }}</span>
              {{ detail?.name || sel.name }} · {{ monthLabel }}
            </span>
            <button class="close" @click="sel = null">✕</button>
          </div>
          <div v-if="busy && !detail" style="padding:30px;text-align:center;color:var(--text-mute)">Loading…</div>
          <template v-else-if="detail">
            <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
              <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
                <div style="background:var(--bg-alt);border-radius:12px;padding:12px 14px">
                  <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute)">Gross</div>
                  <div style="font-weight:800;font-size:16px;margin-top:2px">{{ money(detail.gross) }}</div>
                </div>
                <div style="background:var(--bg-alt);border-radius:12px;padding:12px 14px">
                  <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute)">Collected</div>
                  <div style="font-weight:800;font-size:16px;margin-top:2px;color:#10b981">{{ money(detail.collected) }}</div>
                </div>
                <div style="background:var(--bg-alt);border-radius:12px;padding:12px 14px">
                  <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute)">Net</div>
                  <div style="font-weight:800;font-size:16px;margin-top:2px" :style="netStyle(detail.net)">{{ money(detail.net) }}</div>
                </div>
                <div style="background:var(--bg-alt);border-radius:12px;padding:12px 14px">
                  <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute)">TDS</div>
                  <div style="font-weight:700;font-size:14px;margin-top:2px">{{ money(detail.tds) }}</div>
                </div>
                <div style="background:var(--bg-alt);border-radius:12px;padding:12px 14px">
                  <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute)">Service</div>
                  <div style="font-weight:700;font-size:14px;margin-top:2px">{{ money(detail.service) }}</div>
                </div>
                <div style="background:var(--bg-alt);border-radius:12px;padding:12px 14px">
                  <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute)">Expenses</div>
                  <div style="font-weight:700;font-size:14px;margin-top:2px;color:var(--danger)">{{ money(detail.expenses) }}</div>
                </div>
              </div>

              <div style="display:flex;align-items:center;gap:8px;margin:18px 0 8px">
                <span style="font-weight:800;font-size:13px">📄 Line items</span>
                <span class="badge b-gray" style="font-size:10.5px">{{ detail.lines?.length || 0 }}</span>
              </div>
              <div class="tbl-wrap">
                <table>
                  <thead><tr><th>Invoice</th><th>Unit</th><th>Tenant</th><th>Gross</th><th>Collected</th><th>TDS</th><th>Service</th><th>Net</th><th>Status</th></tr></thead>
                  <tbody>
                    <tr v-for="l in detail.lines || []" :key="l.inv">
                      <td><span class="c-name">{{ l.inv }}</span></td>
                      <td>{{ l.unit_name }}</td>
                      <td>{{ l.tenant_name }}</td>
                      <td>{{ money(l.gross) }}</td>
                      <td>{{ money(l.collected) }}</td>
                      <td>{{ money(l.tds) }}</td>
                      <td>{{ money(l.service) }}</td>
                      <td style="font-weight:700">{{ money(l.net) }}</td>
                      <td><span class="badge" :class="l.inv_status === 'Paid' ? 'b-green' : 'b-orange'" style="font-size:11px">{{ l.inv_status }}</span></td>
                    </tr>
                    <tr v-if="!(detail.lines || []).length"><td colspan="9" class="m">No invoices this month.</td></tr>
                  </tbody>
                  <tfoot v-if="(detail.lines || []).length">
                    <tr style="background:var(--bg-alt);font-weight:700">
                      <td colspan="3" style="padding:8px 12px">Total</td>
                      <td>{{ money(dTot('gross')) }}</td>
                      <td>{{ money(dTot('collected')) }}</td>
                      <td>{{ money(dTot('tds')) }}</td>
                      <td>{{ money(dTot('service')) }}</td>
                      <td>{{ money(dTot('net')) }}</td>
                      <td></td>
                    </tr>
                  </tfoot>
                </table>
              </div>

              <div style="display:flex;align-items:center;gap:8px;margin:18px 0 8px">
                <span style="font-weight:800;font-size:13px">🛠️ Expenses</span>
                <span class="badge b-gray" style="font-size:10.5px">{{ detail.expense_items?.length || 0 }}</span>
              </div>
              <div class="tbl-wrap">
                <table>
                  <thead><tr><th>Ticket</th><th>Title</th><th>Category</th><th>Amount</th></tr></thead>
                  <tbody>
                    <tr v-for="e in detail.expense_items || []" :key="e.id">
                      <td><span class="c-name">{{ e.id }}</span></td>
                      <td>{{ e.title }}</td>
                      <td>{{ e.category || '—' }}</td>
                      <td style="font-weight:700">{{ money(e.actual_cost) }}</td>
                    </tr>
                    <tr v-if="!(detail.expense_items || []).length"><td colspan="4" class="m">No owner-charged expenses this month.</td></tr>
                  </tbody>
                </table>
              </div>

              <div style="display:flex;align-items:center;gap:8px;margin:18px 0 8px">
                <span style="font-weight:800;font-size:13px">💸 Payout</span>
              </div>
              <div v-if="detail.payout" style="background:var(--bg-alt);border-radius:12px;padding:12px 14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <span class="badge" :class="detail.payout.status === 'Paid' ? 'b-green' : 'b-orange'">{{ detail.payout.status }}</span>
                <span style="font-weight:800;font-size:15px">{{ money(detail.payout.amount) }}</span>
                <span v-if="detail.payout.method" class="c-sub">via {{ detail.payout.method }}</span>
                <span v-if="detail.payout.ref" class="c-sub" style="font-family:monospace">{{ detail.payout.ref }}</span>
              </div>
              <div v-else class="c-sub">No payout recorded for this month.</div>
            </div>
            <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
              <button v-if="['superadmin','owner','accountant'].includes(auth.user?.role || '')" class="btn-primary" style="padding:9px 16px;font-size:13px" @click="openPayout({ ...sel, net: detail.net, payout: detail.payout, name: detail.name })">💸 Record payout</button>
              <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="sel = null">Close</button>
            </div>
          </template>
        </div>
      </div>

      <!-- payout modal -->
      <div v-if="payOpen" class="overlay" @click.self="payOpen = false">
        <div class="modal">
          <div class="modal-h"><span class="t">💸 Payout · {{ pay.name }} · {{ pay.month }}</span><button class="close" @click="payOpen = false">✕</button></div>
          <div style="padding:18px 20px;display:flex;flex-direction:column;gap:12px">
            <div class="form-field"><label>Amount (৳)</label><input v-model="pay.amount" type="number" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
            <div class="form-field"><label>Status</label>
              <select v-model="pay.status" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
                <option>Scheduled</option><option>Paid</option>
              </select>
            </div>
            <div class="form-field"><label>Method</label>
              <select v-model="pay.method" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
                <option>Bank</option><option>bKash</option><option>Nagad</option><option>Cheque</option><option>Cash</option>
              </select>
            </div>
            <div class="form-field"><label>Reference</label><input v-model="pay.ref" placeholder="trx ID, cheque no…" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
          </div>
          <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
            <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="payOpen = false">Cancel</button>
            <button class="btn-primary" style="padding:9px 16px;font-size:13px" :disabled="busy" @click="savePayout">Save payout</button>
          </div>
        </div>
      </div>
    </template>

    <!-- ══ RENT CONFIG TAB ══ -->
    <template v-if="tab === 'rentconfig'">
      <div v-if="rentLoading" class="panel" style="padding:36px;text-align:center;color:var(--text-mute)">Loading…</div>
      <template v-else>
        <!-- KPI row -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:14px">
          <div class="stat" style="border-left:4px solid #3b82f6">
            <div class="s-label"><span class="s-ico">🏢</span>Properties</div>
            <div class="s-value">{{ rentCfg.length }}</div>
            <div class="s-trend">configured</div>
          </div>
          <div class="stat" style="border-left:4px solid #8b5cf6">
            <div class="s-label"><span class="s-ico">🧮</span>Base rent</div>
            <div class="s-value">{{ money(rcTotals.base) }}</div>
            <div class="s-trend">monthly</div>
          </div>
          <div class="stat" style="border-left:4px solid #f59e0b">
            <div class="s-label"><span class="s-ico">🧹</span>Service charges</div>
            <div class="s-value">{{ money(rcTotals.service) }}</div>
            <div class="s-trend">+ parking {{ money(rcTotals.parking) }}</div>
          </div>
          <div class="stat" style="border-left:4px solid #10b981">
            <div class="s-label"><span class="s-ico">🎯</span>Rent mix total</div>
            <div class="s-value">{{ money(rcTotals.total) }}</div>
            <div class="s-trend">avg escalation {{ avgEsc }}%</div>
          </div>
        </div>

        <!-- property config cards -->
        <div v-if="!rentCfg.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No properties found.</div>
        <div v-for="p in rentCfg" :key="p.prop" class="panel" style="padding:18px 20px;margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:12px">
              <div style="width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;color:#fff;flex-shrink:0" :style="{ background: propTint(p) }">{{ shortName(p) }}</div>
              <div>
                <div style="font-weight:800;font-size:15px">{{ p.property }}</div>
                <div class="c-sub" style="font-size:12px;margin-top:2px">{{ p.prop }} · {{ p.units }} units</div>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
              <div style="text-align:right">
                <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute)">Monthly mix</div>
                <div style="font-weight:800;font-size:15px;margin-top:1px">{{ money(p.mix?.total) }}</div>
              </div>
              <button v-if="canEditRent" class="btn-ghost" style="padding:8px 14px;font-size:12.5px" @click="openEdit(p)">✏️ Edit</button>
            </div>
          </div>

          <!-- rent mix stacked bar -->
          <div style="display:flex;height:10px;border-radius:99px;overflow:hidden;margin:14px 0 4px;background:var(--bg-alt)">
            <div :style="{ width: mixBar(p).base + '%', background: '#3b82f6' }" title="Base rent"></div>
            <div :style="{ width: mixBar(p).svc + '%', background: '#f59e0b' }" title="Service charge"></div>
            <div :style="{ width: mixBar(p).pk + '%', background: '#10b981' }" title="Parking"></div>
          </div>
          <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:11px;color:var(--text-mute);margin-bottom:12px">
            <span><span style="display:inline-block;width:8px;height:8px;border-radius:99px;background:#3b82f6;margin-right:4px"></span>Base {{ money(p.mix?.base) }}</span>
            <span><span style="display:inline-block;width:8px;height:8px;border-radius:99px;background:#f59e0b;margin-right:4px"></span>Service {{ money(p.mix?.service_charge) }}</span>
            <span><span style="display:inline-block;width:8px;height:8px;border-radius:99px;background:#10b981;margin-right:4px"></span>Parking {{ money(p.mix?.parking) }}</span>
          </div>

          <!-- config chips -->
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px">
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Service charge</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ p.config.service_charge_pct || 0 }}%</div>
            </div>
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Utility advance</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ money(p.config.utility_advance) }}</div>
            </div>
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Parking fee</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ money(p.config.parking_fee) }}</div>
            </div>
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Escalation</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ p.config.escalation_pct || 0 }}%</div>
            </div>
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Advance months</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ p.config.advance_months || 0 }}</div>
            </div>
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Due day</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ p.config.due_day || 5 }}</div>
            </div>
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Late fee</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ p.config.late_fee_pct || 0 }}%</div>
            </div>
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Rent / sqft</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ money(p.config.rent_per_sqft) }}</div>
            </div>
          </div>
          <div v-if="p.config.notes" style="background:#f59e0b14;border:1px solid #f59e0b33;border-radius:10px;padding:8px 12px;font-size:12px;margin-top:10px">📝 {{ p.config.notes }}</div>
        </div>

        <!-- edit modal -->
        <div v-if="editProp" class="overlay" @click.self="editProp = null">
          <div class="modal" style="max-width:560px">
            <div class="modal-h"><span class="t">⚙️ Rent config · {{ rentCfg.find(p => p.prop === editProp)?.property }}</span><button class="close" @click="editProp = null">✕</button></div>
            <div style="padding:18px 20px;display:grid;grid-template-columns:1fr 1fr;gap:12px;overflow-y:auto;max-height:60vh">
              <div class="form-field"><label>Service charge (%)</label><input v-model="editForm.service_charge_pct" type="number" step="0.1" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field"><label>Utility advance (৳)</label><input v-model="editForm.utility_advance" type="number" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field"><label>Parking fee (৳)</label><input v-model="editForm.parking_fee" type="number" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field"><label>Escalation (%)</label><input v-model="editForm.escalation_pct" type="number" step="0.1" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field"><label>Advance months</label><input v-model="editForm.advance_months" type="number" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field"><label>Due day</label><input v-model="editForm.due_day" type="number" min="1" max="31" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field"><label>Late fee (%)</label><input v-model="editForm.late_fee_pct" type="number" step="0.1" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field"><label>Rent / sqft (৳)</label><input v-model="editForm.rent_per_sqft" type="number" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field" style="grid-column:1/-1"><label>Notes</label><textarea v-model="editForm.notes" rows="2" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;resize:vertical"></textarea></div>
            </div>
            <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
              <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="editProp = null">Cancel</button>
              <button class="btn-primary" style="padding:9px 16px;font-size:13px" :disabled="rentSaving" @click="saveRentConfig">💾 Save config {{ rentSaving ? '…' : '' }}</button>
            </div>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>
