<script setup>
import { computed, ref, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import { apiCall } from '../api/client'

const auth = useAuthStore()
const data = useDataStore()
const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const t = (s) => s

const canManage = computed(() => ['superadmin', 'owner', 'manager', 'accountant'].includes(auth.user?.role || ''))

/* ── tabs ── */
const tab = ref('dashboard')
const TABS = [
  ['dashboard', '📊', 'Dashboard'],
  ['shops', '🏪', 'Shops'],
  ['bills', '🧾', 'Bills & Collections'],
  ['meters', '⚡', 'Meters'],
  ['ledger', '📒', 'Ledger'],
]

/* ── month picker (shared) ── */
const now = new Date()
const month = ref(now.toISOString().slice(0, 7))
const prevMonth = () => { const d = new Date(month.value + '-01'); d.setMonth(d.getMonth() - 1); month.value = d.toISOString().slice(0, 7) }
const nextMonth = () => { const d = new Date(month.value + '-01'); d.setMonth(d.getMonth() + 1); month.value = d.toISOString().slice(0, 7) }

/* ── config ── */
const config = ref({ mall_name: '', elec_unit_rate: 8, water_unit_rate: 30, late_fee_pct: 5, due_day: 10 })
const cfgDirty = ref(false)
async function loadConfig() {
  const r = await apiCall('mall', { action: 'config-get' })
  if (r.ok) config.value = { ...config.value, ...r.config }
}
async function saveConfig() {
  const r = await apiCall('mall', { action: 'config-set', ...config.value })
  if (r.ok) { cfgDirty.value = false; window.__krToast?.('⚙️ Mall settings saved', 'ok') }
  else window.__krToast?.(r.error || 'Save failed', 'err')
}

/* ── shops ── */
const shops = computed(() => data.list('shops'))
const shopQuery = ref('')
const shopStatus = ref('')
const filteredShops = computed(() => shops.value.filter(s => {
  if (shopStatus.value && s.status !== shopStatus.value) return false
  const q = shopQuery.value.toLowerCase()
  if (!q) return true
  return [s.no, s.floor, s.owner_name, s.owner_mobile, s.id].join(' ').toLowerCase().includes(q)
}))
const modal = ref(null)
const form = ref({})
function openAdd() { form.value = { status: 'Active', sqft: 0, service_rate: 0, opening_balance: 0 }; modal.value = { mode: 'add' } }
function openEdit(s) {
  form.value = { no: s.no || '', floor: s.floor || '', sqft: s.sqft || 0, owner_name: s.owner_name || '', owner_mobile: s.owner_mobile || '', owner_nid: s.owner_nid || '', status: s.status || 'Active', service_rate: s.service_rate || 0, opening_balance: s.opening_balance || 0 }
  modal.value = { mode: 'edit', id: s.id }
}
const saving = ref(false)
async function saveShop() {
  if (!form.value.no.trim() || !form.value.owner_name.trim()) { window.__krToast?.('Shop no and owner name required.', 'err'); return }
  saving.value = true
  try {
    const payload = {
      no: form.value.no.trim(), floor: form.value.floor.trim(), sqft: Number(form.value.sqft) || 0,
      owner_name: form.value.owner_name.trim(), owner_mobile: form.value.owner_mobile.trim(), owner_nid: form.value.owner_nid.trim(),
      status: form.value.status, service_rate: Number(form.value.service_rate) || 0, opening_balance: Number(form.value.opening_balance) || 0,
    }
    const r = await apiCall('app-crud', {
      action: modal.value.mode === 'edit' ? 'update' : 'create', collection: 'shops',
      ...(modal.value.mode === 'edit' ? { id: modal.value.id } : {}), data: payload,
    })
    if (r.ok) { window.__krToast?.(modal.value.mode === 'edit' ? '✏️ Shop updated' : '✅ Shop created', 'ok'); modal.value = null; await data.bootstrap() }
    else window.__krToast?.(r.error || 'Save failed.', 'err')
  } finally { saving.value = false }
}
async function deleteShop(s) {
  if (!window.confirm(`Delete shop ${s.no}?`)) return
  const r = await apiCall('app-crud', { action: 'delete', collection: 'shops', id: s.id, data: {} })
  if (r.ok) { window.__krToast?.('🗑️ Shop deleted', 'ok'); await data.bootstrap() }
  else window.__krToast?.(r.error || 'Delete failed.', 'err')
}
const STATUS = { Active: '🟢 Active', Closed: '🔴 Closed', Vacant: '⚪ Vacant' }
const shopById = (id) => shops.value.find(s => s.id === id)

/* ── bills ── */
const bills = ref([])
const billsTotals = ref({})
const billKind = ref('')
const billStatus = ref('')
const billsBusy = ref(false)
async function loadBills() {
  billsBusy.value = true
  try {
    const r = await apiCall('mall', { action: 'bills', month: month.value, kind: billKind.value, status: billStatus.value })
    if (r.ok) { bills.value = r.bills; billsTotals.value = r.totals }
  } finally { billsBusy.value = false }
}
async function generateBills() {
  if (!window.confirm(`Generate service-charge bills for ${month.value}? (existing bills are kept)`)) return
  billsBusy.value = true
  try {
    const r = await apiCall('mall', { action: 'bill-generate', month: month.value })
    window.__krToast?.(r.ok ? `✅ ${r.created} bills generated (${r.skipped} existing)` : (r.error || 'Failed'), r.ok ? 'ok' : 'err')
    if (r.ok) await loadBills()
  } finally { billsBusy.value = false }
}
const payModal = ref(null)
const payForm = ref({})
function openPay(b) { payForm.value = { amount: Number(b.amount) + Number(b.fine || 0), method: 'cash', ref: '' }; payModal.value = b }
async function savePay() {
  if (!payModal.value || Number(payForm.value.amount) <= 0) return
  const r = await apiCall('mall', { action: 'collect', bill_id: payModal.value.id, amount: Number(payForm.value.amount), method: payForm.value.method, ref: payForm.value.ref })
  if (r.ok) { window.__krToast?.(`💵 Collected — receipt ${r.receipt}`, 'ok'); payModal.value = null; await loadBills() }
  else window.__krToast?.(r.error || 'Collection failed.', 'err')
}
const finesBusy = ref(false)
async function calcFines() {
  finesBusy.value = true
  try {
    const r = await apiCall('mall', { action: 'fine-calc', month: month.value })
    window.__krToast?.(r.ok ? `💸 Late fees applied to ${r.count} bills (৳${r.total_fine} total @ ${r.pct}%)` : (r.error || 'Failed'), r.ok ? 'ok' : 'err')
    if (r.ok) await loadBills()
  } finally { finesBusy.value = false }
}
const recModal = ref(null)
const recData = ref(null)
async function openReceipt(b) {
  const r = await apiCall('mall', { action: 'receipt', bill_id: b.id })
  if (r.ok) { recData.value = r; recModal.value = b }
  else window.__krToast?.(r.error || 'Receipt load failed.', 'err')
}
function printReceipt() { window.print() }
const isOverdue = (b) => b.due_date && b.status === 'Unpaid' && new Date(b.due_date) < new Date()
const KIND_LABEL = { service: '🧾 Service', elec: '⚡ Electricity', water: '💧 Water' }

/* ── meters ── */
const meterForm = ref({ shop: '', type: 'elec', reading: 0, month: month.value })
async function saveMeter() {
  if (!meterForm.value.shop || Number(meterForm.value.reading) <= 0) { window.__krToast?.('Shop and reading required.', 'err'); return }
  const r = await apiCall('mall', { action: 'meter', shop: meterForm.value.shop, type: meterForm.value.type, reading: Number(meterForm.value.reading), month: meterForm.value.month })
  if (r.ok) { window.__krToast?.(`✅ Reading saved — ${r.units} units billed`, 'ok'); meterForm.value.reading = 0; await loadBills() }
  else window.__krToast?.(r.error || 'Meter save failed.', 'err')
}

/* ── ledger & dashboard ── */
const ledger = ref(null)
const dash = ref(null)
async function loadLedger() { const r = await apiCall('mall', { action: 'ledger', month: month.value }); if (r.ok) ledger.value = r }
async function loadDash() { const r = await apiCall('mall', { action: 'dashboard', month: month.value }); if (r.ok) dash.value = r }

const dashKpis = computed(() => {
  if (!dash.value) return []
  const k = dash.value.kpi || {}
  return [
    { label: 'Collected', ico: '💵', value: money(k.collected) },
    { label: 'Outstanding', ico: '⏳', value: money(k.outstanding), ok: !k.outstanding },
    { label: 'Unpaid bills', ico: '🧾', value: k.unpaid_bills || 0, ok: !k.unpaid_bills },
    { label: 'Shops', ico: '🏪', value: `${dash.value.shops.active} / ${dash.value.shops.total} active` },
  ]
})

/* ── tab switching ── */
function switchTab(x) {
  tab.value = x
  if (x === 'bills') loadBills()
  if (x === 'ledger') loadLedger()
  if (x === 'dashboard') loadDash()
  if (x === 'meters') meterForm.value.month = month.value
}

onMounted(async () => {
  await loadConfig(); await loadDash()
})
</script>

<template>
  <div class="page">
    <div class="pg-head">
      <div>
        <h1>🏬 {{ config.mall_name ? config.mall_name : 'Mall Management' }}</h1>
        <p style="color:var(--mut);font-size:12.5px;margin-top:2px">
          Service charges · elec/water sub-meter billing · collections · ledger — for shopping malls &amp; commercial buildings
        </p>
      </div>
      <div class="pg-actions">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700">
          📅 <input type="month" v-model="month" @change="switchTab(tab)" style="padding:8px 10px;border-radius:9px;border:1px solid var(--brd);background:var(--card);color:var(--txt)" />
        </label>
        <button class="btn" @click="openAdd" v-if="tab === 'shops' && canManage">＋ Add Shop</button>
      </div>
    </div>

    <div class="tabs" style="display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 18px">
      <button v-for="tb in TABS" :key="tb[0]" class="chip" :class="{ active: tab === tb[0] }" @click="switchTab(tb[0])">{{ tb[1] }} {{ t(tb[2]) }}</button>
    </div>

    <!-- ── DASHBOARD ── -->
    <div v-if="tab === 'dashboard'">
      <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px">
        <div v-for="k in dashKpis" :key="k.label" class="stat" :class="{ warn: k.ok === false }" style="background:var(--card);border:1px solid var(--brd);border-radius:14px;padding:16px">
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--mut)">{{ k.ico }} {{ k.label }}</div>
          <div style="font-size:20px;font-weight:800;margin-top:6px">{{ k.value }}</div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px" class="dash-cols">
        <div class="card" style="background:var(--card);border:1px solid var(--brd);border-radius:14px;padding:16px">
          <h3 style="font-size:14px;margin-bottom:12px">🚨 Top defaulters — {{ month }}</h3>
          <table class="kr" v-if="dash && dash.defaulters.length">
            <thead><tr><th>Shop</th><th>Owner</th><th style="text-align:right">Due</th></tr></thead>
            <tbody>
              <tr v-for="d in dash.defaulters" :key="d.id">
                <td><b>{{ d.no }}</b> <small style="color:var(--mut)">· {{ d.floor }}</small></td>
                <td>{{ d.owner_name }}</td>
                <td style="text-align:right;color:var(--danger,#ef4444);font-weight:700">{{ money(d.due) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-else style="color:var(--mut);font-size:13px">🎉 No outstanding bills this month.</p>
        </div>
        <div class="card" style="background:var(--card);border:1px solid var(--brd);border-radius:14px;padding:16px">
          <h3 style="font-size:14px;margin-bottom:12px">📉 Expenses by category — {{ month }}</h3>
          <table class="kr" v-if="dash && dash.expense_cats.length">
            <thead><tr><th>Category</th><th style="text-align:right">Amount</th></tr></thead>
            <tbody>
              <tr v-for="c in dash.expense_cats" :key="c.cat">
                <td>{{ c.cat }}</td>
                <td style="text-align:right;font-weight:700">{{ money(c.total) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-else style="color:var(--mut);font-size:13px">No expenses recorded for this month.</p>
        </div>
      </div>
    </div>

    <!-- ── SHOPS ── -->
    <div v-if="tab === 'shops'">
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <input v-model="shopQuery" placeholder="🔍 Search shop no / owner / mobile…" style="padding:9px 14px;border-radius:10px;border:1px solid var(--brd);background:var(--card);color:var(--txt);min-width:240px" />
        <select v-model="shopStatus" style="padding:9px 12px;border-radius:10px;border:1px solid var(--brd);background:var(--card);color:var(--txt)">
          <option value="">All statuses</option>
          <option v-for="(v, k) in STATUS" :key="k" :value="k">{{ v }}</option>
        </select>
      </div>
      <table class="kr">
        <thead><tr><th>Shop</th><th>Floor</th><th>Sqft</th><th>Owner</th><th>Mobile</th><th>Status</th><th style="text-align:right">Rate/mo</th><th></th></tr></thead>
        <tbody>
          <tr v-for="s in filteredShops" :key="s.id">
            <td><b>{{ s.no }}</b><br /><small style="color:var(--mut)">{{ s.id }}</small></td>
            <td>{{ s.floor }}</td>
            <td>{{ (s.sqft || 0).toLocaleString('en-IN') }}</td>
            <td>{{ s.owner_name || '—' }}</td>
            <td>{{ s.owner_mobile || '—' }}</td>
            <td><span class="badge" :class="{ paid: s.status === 'Active', due: s.status === 'Closed' }">{{ STATUS[s.status] || s.status }}</span></td>
            <td style="text-align:right;font-weight:700">{{ money(s.service_rate) }}</td>
            <td style="text-align:right;white-space:nowrap">
              <button class="btn sm" @click="openEdit(s)" v-if="canManage">✏️</button>
              <button class="btn sm danger" @click="deleteShop(s)" v-if="canManage">🗑️</button>
            </td>
          </tr>
          <tr v-if="!filteredShops.length"><td colspan="8" style="text-align:center;color:var(--mut);padding:24px">No shops yet — add your first shop with ＋ Add Shop. Opening balance can be entered for legacy dues.</td></tr>
        </tbody>
      </table>
      <p style="color:var(--mut);font-size:12px;margin-top:10px">💡 Rate/mo = flat service charge per shop (overrides per-sqft). Shop owners collect their own rent — service charge &amp; utilities are billed here.</p>
    </div>

    <!-- ── BILLS & COLLECTIONS ── -->
    <div v-if="tab === 'bills'">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button class="btn" :disabled="billsBusy" @click="generateBills" v-if="canManage">⚙️ Generate service-charge bills</button>
        <button class="btn ghost" :disabled="finesBusy" @click="calcFines" v-if="canManage" title="Apply late payment fines to overdue unpaid bills">💸 Compute late fees</button>
        <select v-model="billKind" @change="loadBills" style="padding:9px 12px;border-radius:10px;border:1px solid var(--brd);background:var(--card);color:var(--txt)">
          <option value="">All kinds</option>
          <option v-for="(v, k) in KIND_LABEL" :key="k" :value="k">{{ v }}</option>
        </select>
        <select v-model="billStatus" @change="loadBills" style="padding:9px 12px;border-radius:10px;border:1px solid var(--brd);background:var(--card);color:var(--txt)">
          <option value="">All statuses</option>
          <option>Unpaid</option><option>Paid</option>
        </select>
        <span style="margin-left:auto;font-size:13px;color:var(--mut)">Billed <b style="color:var(--txt)">{{ money(billsTotals.billed) }}</b> · Collected <b style="color:var(--ok,#10b981)">{{ money(billsTotals.collected) }}</b> · Fines {{ money(billsTotals.fines) }}</span>
      </div>
      <table class="kr">
        <thead><tr><th>Bill #</th><th>Shop</th><th>Floor</th><th>Kind</th><th style="text-align:right">Amount</th><th>Due</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <tr v-for="b in bills" :key="b.id">
            <td><small style="color:var(--mut)">#{{ b.id }}</small></td>
            <td><b>{{ b.shop_no || b.shop }}</b></td>
            <td>{{ b.shop_floor || '—' }}</td>
            <td>{{ KIND_LABEL[b.kind] || b.kind }}</td>
            <td style="text-align:right;font-weight:700">{{ money(b.amount) }}<span v-if="b.fine" style="color:#ef4444;font-size:11px"> +{{ money(b.fine) }} fine</span></td>
            <td style="font-size:12px;color:var(--mut)">{{ b.due_date }}<span v-if="isOverdue(b)" style="color:#ef4444;font-weight:700"> · overdue</span></td>
            <td><span class="badge" :class="{ paid: b.status === 'Paid', due: b.status === 'Unpaid' }">{{ b.status }}</span></td>
            <td style="text-align:right;white-space:nowrap">
              <button class="btn sm" v-if="b.status === 'Unpaid' && canManage" @click="openPay(b)">💵 Collect</button>
              <button class="btn sm ghost" v-if="b.status === 'Paid'" @click="openReceipt(b)" title="View / print receipt">🖨️</button>
            </td>
          </tr>
          <tr v-if="!bills.length"><td colspan="8" style="text-align:center;color:var(--mut);padding:24px">No bills for {{ month }} — press ⚙️ Generate to create monthly service-charge bills for all active shops.</td></tr>
        </tbody>
      </table>
    </div>

    <!-- ── METERS ── -->
    <div v-if="tab === 'meters'">
      <div class="card" style="background:var(--card);border:1px solid var(--brd);border-radius:14px;padding:18px;max-width:560px">
        <h3 style="font-size:14px;margin-bottom:12px">⚡ Sub-meter reading → auto bill (custodial fund)</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <label style="font-size:12px;color:var(--mut)">Shop
            <select v-model="meterForm.shop" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)">
              <option value="">Select shop…</option>
              <option v-for="s in shops.filter(x => x.status === 'Active')" :key="s.id" :value="s.id">{{ s.no }} — {{ s.floor }} ({{ s.owner_name }})</option>
            </select>
          </label>
          <label style="font-size:12px;color:var(--mut)">Type
            <select v-model="meterForm.type" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)">
              <option value="elec">⚡ Electricity</option>
              <option value="water">💧 Water</option>
            </select>
          </label>
          <label style="font-size:12px;color:var(--mut)">Month
            <input type="month" v-model="meterForm.month" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" />
          </label>
          <label style="font-size:12px;color:var(--mut)">Meter reading (this month)
            <input type="number" v-model.number="meterForm.reading" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" />
          </label>
        </div>
        <button class="btn" style="margin-top:14px" :disabled="saving" @click="saveMeter">💾 Save reading &amp; generate bill</button>
        <p style="color:var(--mut);font-size:12px;margin-top:10px">Units = reading − previous reading × rate ({{ money(config.elec_unit_rate) }}/unit elec, {{ money(config.water_unit_rate) }}/unit water). Collected amounts are <b>custodial</b> — forwarded to DESCO/WASA, tracked separately from service charges.</p>
      </div>
    </div>

    <!-- ── LEDGER ── -->
    <div v-if="tab === 'ledger'">
      <div v-if="ledger" style="display:grid;gap:16px">
        <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px">
          <div class="stat" v-for="k in ledger.by_kind" :key="k.kind" style="background:var(--card);border:1px solid var(--brd);border-radius:14px;padding:16px">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--mut)">{{ KIND_LABEL[k.kind] || k.kind }}</div>
            <div style="font-size:16px;font-weight:800;margin-top:6px">{{ money(k.collected) }} <small style="color:var(--mut);font-weight:400">/ {{ money(k.billed) }} billed</small></div>
          </div>
          <div class="stat" style="background:var(--card);border:1px solid var(--brd);border-radius:14px;padding:16px">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--mut)">📉 Expenses</div>
            <div style="font-size:16px;font-weight:800;margin-top:6px;color:#ef4444">{{ money(ledger.expenses) }}</div>
          </div>
        </div>
        <div class="card" style="background:var(--card);border:1px solid var(--brd);border-radius:14px;padding:16px">
          <h3 style="font-size:14px;margin-bottom:12px">🏪 Per-shop ledger — {{ ledger.month }}</h3>
          <table class="kr">
            <thead><tr><th>Shop</th><th>Owner</th><th style="text-align:right">Service paid/billed</th><th style="text-align:right">Elec paid/billed</th><th style="text-align:right">Water paid/billed</th></tr></thead>
            <tbody>
              <tr v-for="s in ledger.per_shop" :key="s.id">
                <td><b>{{ s.no }}</b> <small style="color:var(--mut)">· {{ s.floor }}</small></td>
                <td>{{ s.owner_name || '—' }}</td>
                <td style="text-align:right">{{ money(s.sc_paid) }} / {{ money(s.sc_billed) }}</td>
                <td style="text-align:right">{{ money(s.el_paid) }} / {{ money(s.el_billed) }}</td>
                <td style="text-align:right">{{ money(s.w_paid) }} / {{ money(s.w_billed) }}</td>
              </tr>
              <tr v-if="!ledger.per_shop.length"><td colspan="5" style="text-align:center;color:var(--mut);padding:20px">No shops yet.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <p v-else style="color:var(--mut)">Loading ledger…</p>
    </div>

    <!-- ⚙️ settings card (mall name + rates) -->
    <div class="card" style="background:var(--card);border:1px solid var(--brd);border-radius:14px;padding:18px;margin-top:22px">
      <h3 style="font-size:14px;margin-bottom:12px">⚙️ Mall settings</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
        <label style="font-size:12px;color:var(--mut)">Mall name
          <input v-model="config.mall_name" placeholder="e.g. Razzak Plaza" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" @input="cfgDirty = true" />
        </label>
        <label style="font-size:12px;color:var(--mut)">Elec rate (৳/unit)
          <input type="number" v-model.number="config.elec_unit_rate" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" @input="cfgDirty = true" />
        </label>
        <label style="font-size:12px;color:var(--mut)">Water rate (৳/unit)
          <input type="number" v-model.number="config.water_unit_rate" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" @input="cfgDirty = true" />
        </label>
        <label style="font-size:12px;color:var(--mut)">Due day of month
          <input type="number" v-model.number="config.due_day" min="1" max="28" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" @input="cfgDirty = true" />
        </label>
      </div>
      <button class="btn" style="margin-top:14px" :disabled="!cfgDirty" @click="saveConfig">💾 Save settings</button>
    </div>

    <!-- ── modals ── -->
    <div v-if="modal" class="overlay" @click.self="modal = null">
      <div class="modal" style="max-width:560px">
        <h3 style="margin-bottom:14px">{{ modal.mode === 'edit' ? '✏️ Edit shop' : '➕ New shop' }}</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <label style="font-size:12px;color:var(--mut)">Shop no *<input v-model="form.no" placeholder="e.g. A-101" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" /></label>
          <label style="font-size:12px;color:var(--mut)">Floor<input v-model="form.floor" placeholder="e.g. 2nd" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" /></label>
          <label style="font-size:12px;color:var(--mut)">Size (sqft)<input type="number" v-model.number="form.sqft" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" /></label>
          <label style="font-size:12px;color:var(--mut)">Service rate (৳/mo)<input type="number" v-model.number="form.service_rate" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" /></label>
          <label style="font-size:12px;color:var(--mut)">Owner name *<input v-model="form.owner_name" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" /></label>
          <label style="font-size:12px;color:var(--mut)">Owner mobile<input v-model="form.owner_mobile" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" /></label>
          <label style="font-size:12px;color:var(--mut)">Owner NID<input v-model="form.owner_nid" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" /></label>
          <label style="font-size:12px;color:var(--mut)">Status
            <select v-model="form.status" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)">
              <option v-for="(v, k) in STATUS" :key="k" :value="k">{{ v }}</option>
            </select>
          </label>
          <label style="font-size:12px;color:var(--mut)">Opening balance (৳)<input type="number" v-model.number="form.opening_balance" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" /></label>
        </div>
        <div style="display:flex;gap:10px;margin-top:18px">
          <button class="btn" style="flex:1" :disabled="saving" @click="saveShop">{{ saving ? 'Saving…' : '💾 Save shop' }}</button>
          <button class="btn ghost" @click="modal = null">Cancel</button>
        </div>
      </div>
    </div>

    <div v-if="payModal" class="overlay" @click.self="payModal = null">
      <div class="modal" style="max-width:420px">
        <h3 style="margin-bottom:6px">💵 Collect — {{ payModal.shop_no }} ({{ KIND_LABEL[payModal.kind] }})</h3>
        <p style="color:var(--mut);font-size:12.5px;margin-bottom:14px">{{ payModal.month }} · bill #{{ payModal.id }}</p>
        <label style="font-size:12px;color:var(--mut)">Amount (৳)<input type="number" v-model.number="payForm.amount" min="1" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" /></label>
        <p v-if="payModal && payModal.fine" style="font-size:12px;color:#ef4444;margin-top:8px">⚠️ Includes late fee of {{ money(payModal.fine) }} (bill overdue)</p>
        <label style="font-size:12px;color:var(--mut);display:block;margin-top:10px">Method
          <select v-model="payForm.method" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)">
            <option value="cash">💵 Cash</option><option value="bank">🏦 Bank</option><option value="bkash">📱 bKash</option><option value="nagad">📱 Nagad</option>
          </select>
        </label>
        <label style="font-size:12px;color:var(--mut);display:block;margin-top:10px">Reference (trx no / note)<input v-model="payForm.ref" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--brd);background:var(--bg);color:var(--txt)" /></label>
        <div style="display:flex;gap:10px;margin-top:18px">
          <button class="btn" style="flex:1" @click="savePay">💾 Save collection</button>
          <button class="btn ghost" @click="payModal = null">Cancel</button>
        </div>
      </div>
    </div>

    <!-- ── receipt modal (printable) ── -->
    <div v-if="recModal" class="overlay" @click.self="recModal = null">
      <div class="modal" style="max-width:460px">
        <div id="receiptPrint">
          <div style="text-align:center;border-bottom:2px dashed var(--brd);padding-bottom:12px;margin-bottom:14px">
            <div style="font-size:17px;font-weight:800">{{ recData.mall_name || 'MALL MANAGEMENT' }}</div>
            <div style="font-size:12px;color:var(--mut)">Money Receipt · Service Collection</div>
          </div>
          <table style="width:100%;font-size:13.5px;line-height:2">
            <tbody>
            <tr><td style="color:var(--mut)">Receipt No</td><td style="text-align:right;font-weight:700">{{ recData.payment.receipt }}</td></tr>
            <tr><td style="color:var(--mut)">Date</td><td style="text-align:right">{{ (recData.payment.created_at || '').slice(0, 16) }}</td></tr>
            <tr><td style="color:var(--mut)">Shop</td><td style="text-align:right;font-weight:700">{{ recData.bill.shop_no }} · {{ recData.bill.shop_floor }} floor</td></tr>
            <tr><td style="color:var(--mut)">Owner</td><td style="text-align:right">{{ recData.bill.owner_name || '—' }}</td></tr>
            <tr><td style="color:var(--mut)">Month</td><td style="text-align:right">{{ recData.bill.month }}</td></tr>
            <tr><td style="color:var(--mut)">Charge</td><td style="text-align:right">{{ KIND_LABEL[recData.bill.kind] }}</td></tr>
            <tr><td style="color:var(--mut)">Amount</td><td style="text-align:right;font-weight:700">{{ money(recData.bill.amount) }}</td></tr>
            <tr v-if="recData.bill.fine"><td style="color:var(--mut)">Late fee</td><td style="text-align:right;color:#ef4444">{{ money(recData.bill.fine) }}</td></tr>
            <tr><td style="color:var(--mut)">Paid via</td><td style="text-align:right">{{ recData.payment.method }} <span v-if="recData.payment.ref" style="color:var(--mut)">({{ recData.payment.ref }})</span></td></tr>
            </tbody>
          </table>
          <div style="display:flex;justify-content:space-between;margin-top:18px;padding-top:10px;border-top:1px solid var(--brd);font-size:12px;color:var(--mut)">
            <span>Received by: ________________</span><span>Signature: ____________</span>
          </div>
        </div>
        <div style="display:flex;gap:10px;margin-top:18px">
          <button class="btn" style="flex:1" @click="printReceipt">🖨️ Print receipt</button>
          <button class="btn ghost" @click="recModal = null">Close</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style>
@media print {
  body * { visibility: hidden !important; }
  #receiptPrint, #receiptPrint * { visibility: visible !important; }
  #receiptPrint { position: fixed; left: 0; top: 0; width: 100%; background: #fff; color: #111; padding: 24px; }
}
</style>
