<script setup>
import { ref, computed, onMounted } from 'vue'
import { apiCall } from '../api/client'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'

const data = useDataStore()
const auth = useAuthStore()
const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')

const tab = ref('statements')

// ── rent config (app-rent-config-get / save) ──
const rentCfg = ref([])        // [{prop, property, config, mix, units}]
const rentLoading = ref(false)
const rentSaving = ref(false)
const editProp = ref(null)     // prop id being edited
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

const now = new Date()
const month = ref(now.toISOString().slice(0, 7))
const loading = ref(false)
const err = ref('')
const toast = ref('')
const netColor = (n) => (n || 0) >= 0 ? '#12a150' : 'var(--danger)'
const netStyle = (n) => 'color:' + netColor(n)

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

// ── payout modal ──
const pay = ref(null)         // { amount, status, method, ref }
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

onMounted(loadList)
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>💰 Statements</h1>
        <div class="sub">Monthly owner statements — per property P&amp;L, line items &amp; payouts</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="month" type="month" style="padding:9px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none" @change="loadList">
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

    <template v-if="tab === 'statements'">
    <div class="stats">
      <div class="stat"><div class="s-label"><span class="s-ico">🧾</span>Gross</div><div class="s-value">{{ money(totals.gross) }}</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">💰</span>Collected</div><div class="s-value">{{ money(totals.collected) }}</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">📉</span>TDS + Service</div><div class="s-value">{{ money(totals.tds + totals.service) }}</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">🛠️</span>Expenses</div><div class="s-value">{{ money(totals.expenses) }}</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">🎯</span>Net</div><div class="s-value">{{ money(totals.net) }}</div></div>
    </div>

    <div class="panel">
      <div class="panel-h"><div class="t"><span class="pi">🏢</span>Statements · {{ month }}</div></div>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>Property</th><th>Gross</th><th>Collected</th><th>TDS</th><th>Service</th><th>Expenses</th><th>Net</th><th>Payout</th><th></th></tr></thead>
          <tbody>
            <tr v-for="s in list" :key="s.prop">
              <td><span class="c-name">{{ s.name }}</span><div class="c-sub">{{ s.type }}</div></td>
              <td>{{ money(s.gross) }}</td>
              <td>{{ money(s.collected) }}</td>
              <td>{{ money(s.tds) }}</td>
              <td>{{ money(s.service) }}</td>
              <td>{{ money(s.expenses) }}</td>
              <td style="font-weight:800">{{ money(s.net) }}</td>
              <td>
                <span v-if="s.payout" class="badge" :class="s.payout.status === 'Paid' ? 'b-green' : 'b-orange'">{{ s.payout.status }} {{ money(s.payout.amount) }}</span>
                <span v-else class="badge b-gray">—</span>
              </td>
              <td style="white-space:nowrap">
                <button class="btn-ghost" style="padding:4px 10px;font-size:11.5px" @click="openDetail(s)">👁 Detail</button>
                <button v-if="['superadmin','owner','accountant'].includes(auth.user?.role || '')" class="btn-ghost" style="padding:4px 10px;font-size:11.5px" @click="openPayout(s)">💸 Payout</button>
              </td>
            </tr>
            <tr v-if="!list.length"><td colspan="9" class="m">No statements for this month.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel">
      <div class="panel-h"><div class="t"><span class="pi">💸</span>Payout ledger</div></div>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>Property</th><th>Month</th><th>Amount</th><th>Status</th><th>Method</th><th>Ref</th></tr></thead>
          <tbody>
            <tr v-for="p in payouts" :key="p.prop + p.month">
              <td><span class="c-name">{{ p.prop }}</span></td>
              <td>{{ p.month }}</td>
              <td style="font-weight:700">{{ money(p.amount) }}</td>
              <td><span class="badge" :class="p.status === 'Paid' ? 'b-green' : 'b-orange'">{{ p.status }}</span></td>
              <td>{{ p.method || '—' }}</td>
              <td>{{ p.ref || '—' }}</td>
            </tr>
            <tr v-if="!payouts.length"><td colspan="6" class="m">No payouts recorded.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- detail drawer -->
    <div v-if="sel" class="overlay" @click.self="sel = null">
      <div class="drawer">
        <div class="modal-h"><span class="t">💰 {{ detail?.name || sel.name }} · {{ month }}</span><button class="close" @click="sel = null">✕</button></div>
        <div v-if="busy && !detail" style="padding:30px;text-align:center;color:var(--text-mute)">Loading…</div>
        <template v-else-if="detail">
          <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
            <div class="stats" style="grid-template-columns:repeat(2,1fr)">
              <div class="stat"><div class="s-label"><span class="s-ico">🧾</span>Gross</div><div class="s-value">{{ money(detail.gross) }}</div></div>
              <div class="stat"><div class="s-label"><span class="s-ico">💰</span>Collected</div><div class="s-value">{{ money(detail.collected) }}</div></div>
              <div class="stat"><div class="s-label"><span class="s-ico">📉</span>TDS</div><div class="s-value">{{ money(detail.tds) }}</div></div>
              <div class="stat"><div class="s-label"><span class="s-ico">🧹</span>Service</div><div class="s-value">{{ money(detail.service) }}</div></div>
              <div class="stat"><div class="s-label"><span class="s-ico">🛠️</span>Expenses</div><div class="s-value">{{ money(detail.expenses) }}</div></div>
              <div class="stat"><div class="s-label"><span class="s-ico">🎯</span>Net</div><div class="s-value">{{ money(detail.net) }}</div></div>
            </div>

            <div style="font-weight:800;font-size:13px;margin:18px 0 8px">📄 Line items ({{ detail.lines?.length || 0 }})</div>
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
                    <td><span class="badge" :class="l.inv_status === 'Paid' ? 'b-green' : 'b-orange'">{{ l.inv_status }}</span></td>
                  </tr>
                  <tr v-if="!(detail.lines || []).length"><td colspan="9" class="m">No invoices this month.</td></tr>
                </tbody>
              </table>
            </div>

            <div style="font-weight:800;font-size:13px;margin:18px 0 8px">🛠️ Expenses ({{ detail.expense_items?.length || 0 }})</div>
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

            <div style="font-weight:800;font-size:13px;margin:18px 0 8px">💸 Payout</div>
            <div v-if="detail.payout" class="kv" style="margin-bottom:6px"><span class="k">Recorded</span><span class="v">{{ detail.payout.status }} · {{ money(detail.payout.amount) }}{{ detail.payout.method ? ' · ' + detail.payout.method : '' }}{{ detail.payout.ref ? ' · ' + detail.payout.ref : '' }}</span></div>
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
        <div class="stats">
          <div class="stat"><div class="s-label"><span class="s-ico">🏢</span>Properties</div><div class="s-value">{{ rentCfg.length }}</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">🧮</span>Total base rent</div><div class="s-value">{{ money(rentCfg.reduce((a, p) => a + (p.mix?.base || 0), 0)) }}</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">🧹</span>Service charges</div><div class="s-value">{{ money(rentCfg.reduce((a, p) => a + (p.mix?.service_charge || 0), 0)) }}</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">🅿️</span>Parking</div><div class="s-value">{{ money(rentCfg.reduce((a, p) => a + (p.mix?.parking || 0), 0)) }}</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">🎯</span>Rent mix total</div><div class="s-value">{{ money(rentCfg.reduce((a, p) => a + (p.mix?.total || 0), 0)) }}</div></div>
        </div>

        <div v-for="p in rentCfg" :key="p.prop" class="panel" style="padding:18px;margin-bottom:16px">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
            <div>
              <div style="font-weight:800;font-size:15px">{{ p.property }}</div>
              <div class="c-sub" style="font-size:12px;margin-top:2px">{{ p.prop }} · {{ p.units }} units · base {{ money(p.mix?.base) }} → mix {{ money(p.mix?.total) }} (service {{ money(p.mix?.service_charge) }} + parking {{ money(p.mix?.parking) }})</div>
            </div>
            <button v-if="canEditRent" class="btn-ghost" style="padding:8px 14px;font-size:12.5px" @click="openEdit(p)">✏️ Edit config</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px 16px;margin-top:14px">
            <div style="font-size:12.5px">
              <div style="color:var(--text-mute);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Service charge</div>
              <div style="font-weight:700;margin-top:1px">{{ p.config.service_charge_pct || 0 }}%</div>
            </div>
            <div style="font-size:12.5px">
              <div style="color:var(--text-mute);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Utility advance</div>
              <div style="font-weight:700;margin-top:1px">{{ money(p.config.utility_advance) }}</div>
            </div>
            <div style="font-size:12.5px">
              <div style="color:var(--text-mute);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Parking fee</div>
              <div style="font-weight:700;margin-top:1px">{{ money(p.config.parking_fee) }}</div>
            </div>
            <div style="font-size:12.5px">
              <div style="color:var(--text-mute);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Escalation</div>
              <div style="font-weight:700;margin-top:1px">{{ p.config.escalation_pct || 0 }}%</div>
            </div>
            <div style="font-size:12.5px">
              <div style="color:var(--text-mute);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Advance months</div>
              <div style="font-weight:700;margin-top:1px">{{ p.config.advance_months || 0 }}</div>
            </div>
            <div style="font-size:12.5px">
              <div style="color:var(--text-mute);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Due day</div>
              <div style="font-weight:700;margin-top:1px">{{ p.config.due_day || 5 }}</div>
            </div>
            <div style="font-size:12.5px">
              <div style="color:var(--text-mute);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Late fee</div>
              <div style="font-weight:700;margin-top:1px">{{ p.config.late_fee_pct || 0 }}%</div>
            </div>
            <div style="font-size:12.5px">
              <div style="color:var(--text-mute);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Rent / sqft</div>
              <div style="font-weight:700;margin-top:1px">{{ money(p.config.rent_per_sqft) }}</div>
            </div>
          </div>
          <div v-if="p.config.notes" class="c-sub" style="font-size:12px;margin-top:10px">📝 {{ p.config.notes }}</div>
        </div>

        <div v-if="!rentCfg.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No properties found.</div>

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
