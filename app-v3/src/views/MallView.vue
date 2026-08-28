<script setup>
import { computed, ref, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import { apiCall } from '../api/client'
import { money, monthLabel, badge } from '../lib/ui'
import ScrollTabs from '../components/ScrollTabs.vue'

const auth = useAuthStore()
const data = useDataStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'accountant'].includes(auth.user?.role || ''))
const isCollector = computed(() => auth.user?.role === 'collector')
const canCollect = computed(() => canManage.value || isCollector.value)

/* ── tabs ── */
const tab = ref('dashboard')
const TABS = [
  ['dashboard', '📊', 'Dashboard'],
  ['shops', '🏪', 'Shops'],
  ['bills', '🧾', 'Bills & Collections'],
  ['meters', '⚡', 'Meters'],
  ['expenses', '📉', 'Expenses'],
  ['complaints', '🔧', 'Complaints'],
  ['assets', '🛠️', 'Assets & AMC'],
  ['notices', '📢', 'Notices'],
  ['audit', '📋', 'Audit'],
  ['ledger', '📒', 'Ledger'],
]
const month = ref(new Date().toISOString().slice(0, 7))
const shiftMonth = (d) => { const m = new Date(month.value + '-01'); m.setMonth(m.getMonth() + d); month.value = m.toISOString().slice(0, 7); switchTab(tab.value) }

/* ── config ── */
const config = ref({ mall_name: '', elec_unit_rate: 8, water_unit_rate: 30, late_fee_pct: 5, due_day: 10 })
const cfgDirty = ref(false)
async function loadConfig() {
  const r = await apiCall('mall', { action: 'config-get' })
  if (r.ok) config.value = { ...config.value, ...r.config }
}
async function saveConfig() {
  const r = await apiCall('mall', { action: 'config-set', ...config.value })
  if (r.ok) { cfgDirty.value = false; window.__krToast?.('⚙️ Settings saved', 'ok') }
  else window.__krToast?.(r.error || 'Save failed', 'err')
}

/* ══════════ DASHBOARD ══════════ */
const dash = ref(null)
const loadingDash = ref(false)
async function loadDash() {
  loadingDash.value = true
  try {
    const [d, p] = await Promise.all([
      apiCall('mall', { action: 'dashboard', month: month.value }),
      apiCall('mall', { action: 'payments', month: month.value }),
    ])
    if (d.ok) dash.value = d
    if (p.ok) payments.value = p.payments
  } finally { loadingDash.value = false }
}
const dashKpis = computed(() => {
  if (!dash.value) return []
  const k = dash.value.kpi || {}
  const rate = k.billed ? Math.round(k.collected / k.billed * 100) : 0
  return [
    { label: 'Collected', ico: '💵', value: money(k.collected), trend: `${rate}% of billed` },
    { label: 'Outstanding', ico: '⏳', value: money(k.outstanding), trend: `${k.unpaid_bills || 0} unpaid bills`, ok: !k.outstanding },
    { label: 'Expenses', ico: '📉', value: money(dash.value.expense_total), trend: 'this month' },
    { label: 'Shops', ico: '🏪', value: `${dash.value.shops.active} / ${dash.value.shops.total}`, trend: `${dash.value.shops.total - dash.value.shops.active} inactive` },
  ]
})

/* ══════════ SHOPS ══════════ */
const shops = computed(() => data.list('shops'))
const shopQuery = ref('')
const shopStatus = ref('')
const filteredShops = computed(() => shops.value.filter(s => {
  if (shopStatus.value && s.status !== shopStatus.value) return false
  const q = shopQuery.value.toLowerCase()
  if (!q) return true
  return [s.no, s.floor, s.owner_name, s.owner_mobile, s.id].join(' ').toLowerCase().includes(q)
}))
const shopKpis = computed(() => {
  const a = shops.value.filter(s => s.status === 'Active')
  const v = shops.value.filter(s => s.status === 'Vacant')
  const c = shops.value.filter(s => s.status === 'Closed')
  const potential = a.reduce((s, x) => s + (x.service_rate || 0), 0)
  return [
    { label: 'Total shops', ico: '🏪', value: shops.value.length },
    { label: 'Active', ico: '🟢', value: a.length, trend: `${potential ? money(potential) : 0} /mo potential` },
    { label: 'Vacant', ico: '⚪', value: v.length, ok: v.length === 0 },
    { label: 'Closed', ico: '🔴', value: c.length, ok: c.length === 0 },
  ]
})
const modal = ref(null)
const form = ref({})
function openAdd() { form.value = { status: 'Active', sqft: 0, service_rate: 0, opening_balance: 0 }; modal.value = { mode: 'add', title: '➕ New shop' } }
function openEdit(s) {
  form.value = { no: s.no || '', floor: s.floor || '', sqft: s.sqft || 0, owner_name: s.owner_name || '', owner_mobile: s.owner_mobile || '', owner_nid: s.owner_nid || '', status: s.status || 'Active', service_rate: s.service_rate || 0, opening_balance: s.opening_balance || 0 }
  modal.value = { mode: 'edit', title: '✏️ Edit shop', id: s.id }
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

/* ══════════ BILLS & COLLECTIONS ══════════ */
const bills = ref([])
const billsTotals = ref({})
const payments = ref([])
const billKind = ref('')
const billStatus = ref('')
const billsBusy = ref(false)
async function loadBills() {
  billsBusy.value = true
  try {
    const [b, p] = await Promise.all([
      apiCall('mall', { action: 'bills', month: month.value, kind: billKind.value, status: billStatus.value }),
      apiCall('mall', { action: 'payments', month: month.value }),
    ])
    if (b.ok) { bills.value = b.bills; billsTotals.value = b.totals }
    if (p.ok) payments.value = p.payments
  } finally { billsBusy.value = false }
}
async function generateBills() {
  if (!window.confirm(`Generate service-charge bills for ${monthLabel(month.value)}? (existing bills are kept)`)) return
  billsBusy.value = true
  try {
    const r = await apiCall('mall', { action: 'bill-generate', month: month.value })
    window.__krToast?.(r.ok ? `✅ ${r.created} bills generated (${r.skipped} existing)` : (r.error || 'Failed'), r.ok ? 'ok' : 'err')
    if (r.ok) await loadBills()
  } finally { billsBusy.value = false }
}
const finesBusy = ref(false)
async function calcFines() {
  finesBusy.value = true
  try {
    const r = await apiCall('mall', { action: 'fine-calc', month: month.value })
    window.__krToast?.(r.ok ? `💸 Late fees applied to ${r.count} bills (${money(r.total_fine)} @ ${r.pct}%)` : (r.error || 'Failed'), r.ok ? 'ok' : 'err')
    if (r.ok) await loadBills()
  } finally { finesBusy.value = false }
}
const isOverdue = (b) => b.due_date && b.status === 'Unpaid' && new Date(b.due_date) < new Date()
const payModal = ref(null)
const payForm = ref({})
function openPay(b) { payForm.value = { amount: Number(b.amount) + Number(b.fine || 0), method: 'cash', ref: '' }; payModal.value = b }
async function savePay() {
  if (!payModal.value || Number(payForm.value.amount) <= 0) return
  const r = await apiCall('mall', { action: 'collect', bill_id: payModal.value.id, amount: Number(payForm.value.amount), method: payForm.value.method, ref: payForm.value.ref })
  if (r.ok) { window.__krToast?.(`💵 Collected — receipt ${r.receipt}`, 'ok'); payModal.value = null; await loadBills(); await loadDash() }
  else window.__krToast?.(r.error || 'Collection failed.', 'err')
}
const recModal = ref(null)
const recData = ref(null)
async function openReceipt(b) {
  const r = await apiCall('mall', { action: 'receipt', bill_id: b.id })
  if (r.ok) { recData.value = r; recModal.value = b }
  else window.__krToast?.(r.error || 'Receipt load failed.', 'err')
}
function printReceipt() { window.print() }

/* ══════════ METERS ══════════ */
const meterForm = ref({ shop: '', type: 'elec', reading: 0, month: '' })
const lastReadings = ref([])
async function loadMeters() {
  const r = await apiCall('mall', { action: 'readings', month: meterForm.value.month || month.value })
  if (r.ok) lastReadings.value = r.readings
}
async function saveMeter() {
  if (!meterForm.value.shop || Number(meterForm.value.reading) <= 0) { window.__krToast?.('Shop and reading required.', 'err'); return }
  const r = await apiCall('mall', { action: 'meter', shop: meterForm.value.shop, type: meterForm.value.type, reading: Number(meterForm.value.reading), month: meterForm.value.month || month.value })
  if (r.ok) { window.__krToast?.(`✅ Reading saved — ${r.units} units billed`, 'ok'); meterForm.value.reading = 0; await loadMeters(); await loadBills() }
  else window.__krToast?.(r.error || 'Meter save failed.', 'err')
}

/* ══════════ EXPENSES ══════════ */
const expForm = ref({ category: 'Lift Maintenance', vendor: '', amount: 0, method: 'bank', note: '' })
const expenses = ref([])
const expTotal = ref(0)
const EXP_CATEGORIES = ['Lift Maintenance', 'Escalator', 'Common Electricity (DESCO)', 'AC Servicing', 'Generator / Fuel', 'Cleaning', 'Security', 'Staff Salary', 'Repairs', 'Other']
async function loadExpenses() {
  const r = await apiCall('mall', { action: 'expenses', month: month.value })
  if (r.ok) { expenses.value = r.expenses; expTotal.value = r.total }
}
async function saveExpense() {
  if (Number(expForm.value.amount) <= 0) { window.__krToast?.('Amount required.', 'err'); return }
  const r = await apiCall('mall', { action: 'expense-add', category: expForm.value.category, vendor: expForm.value.vendor, amount: Number(expForm.value.amount), method: expForm.value.method, note: expForm.value.note })
  if (r.ok) { window.__krToast?.('📉 Expense recorded', 'ok'); expForm.value = { category: 'Lift Maintenance', vendor: '', amount: 0, method: 'bank', note: '' }; await loadExpenses(); await loadDash() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delExpense(e) {
  if (!window.confirm('Delete this expense?')) return
  const r = await apiCall('mall', { action: 'expense-del', id: e.id })
  if (r.ok) { window.__krToast?.('🗑️ Expense deleted', 'ok'); await loadExpenses(); await loadDash() }
}

/* ══════════ COMPLAINTS (spec 3.6) ══════════ */
const complaints = ref([])
const compCounts = ref({ Open: 0, 'In Progress': 0, Resolved: 0 })
const compStatus = ref('')
const compModal = ref(null)
const compForm = ref({})
async function loadComplaints() {
  const r = await apiCall('mall', { action: 'complaints', status: compStatus.value })
  if (r.ok) { complaints.value = r.complaints; compCounts.value = r.counts }
}
function openCompAdd() { compForm.value = { shop: '', subject: '', descr: '', priority: 'Normal' }; compModal.value = { mode: 'add', title: '➕ New complaint' } }
async function saveComplaint() {
  if (!compForm.value.shop || !compForm.value.subject.trim()) { window.__krToast?.('Shop and subject required.', 'err'); return }
  const r = await apiCall('mall', { action: 'complaint-add', shop: compForm.value.shop, subject: compForm.value.subject, descr: compForm.value.descr, priority: compForm.value.priority })
  if (r.ok) { window.__krToast?.('🔧 Complaint logged', 'ok'); compModal.value = null; await loadComplaints() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function setCompStatus(c, st) {
  const note = st === 'Resolved' ? window.prompt('Resolution note (optional):', '') : ''
  if (st === 'Resolved' && note === null) return
  const r = await apiCall('mall', { action: 'complaint-status', id: c.id, status: st, note: note || '' })
  if (r.ok) { window.__krToast?.(`🔧 ${c.id} → ${st}`, 'ok'); await loadComplaints() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delComplaint(c) {
  if (!window.confirm(`Delete complaint #${c.id}?`)) return
  const r = await apiCall('mall', { action: 'complaint-del', id: c.id })
  if (r.ok) { window.__krToast?.('🗑️ Deleted', 'ok'); await loadComplaints() }
}

/* ══════════ ASSETS & AMC (spec 3.5) ══════════ */
const assets = ref([])
const amcReminders = ref([])
const assetModal = ref(null)
const assetForm = ref({})
const ASSET_TYPES = ['Lift', 'Escalator', 'Generator', 'Fire Extinguisher', 'AC Unit', 'CCTV', 'Pump', 'Other']
async function loadAssets() {
  const r = await apiCall('mall', { action: 'assets' })
  if (r.ok) { assets.value = r.assets; amcReminders.value = r.reminders }
}
function openAssetAdd() { assetForm.value = { name: '', type: 'Lift', location: '', vendor: '', install_date: '', warranty_until: '', contract_until: '', cost: 0, status: 'Active', note: '' }; assetModal.value = { mode: 'add', title: '➕ New asset' } }
function openAssetEdit(a) {
  assetForm.value = { name: a.name, type: a.type, location: a.location, vendor: a.vendor, install_date: a.install_date, warranty_until: a.warranty_until, contract_until: a.contract_until, cost: a.cost, status: a.status, note: a.note }
  assetModal.value = { mode: 'edit', title: '✏️ Edit asset', id: a.id }
}
async function saveAsset() {
  if (!assetForm.value.name.trim()) { window.__krToast?.('Asset name required.', 'err'); return }
  const action = assetModal.value.mode === 'edit' ? 'asset-update' : 'asset-add'
  const payload = { ...assetForm.value, cost: Number(assetForm.value.cost) || 0, ...(assetModal.value.mode === 'edit' ? { id: assetModal.value.id } : {}) }
  const r = await apiCall('mall', { action, ...payload })
  if (r.ok) { window.__krToast?.(assetModal.value.mode === 'edit' ? '✏️ Asset updated' : '✅ Asset added', 'ok'); assetModal.value = null; await loadAssets() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delAsset(a) {
  if (!window.confirm(`Delete asset "${a.name}"?`)) return
  const r = await apiCall('mall', { action: 'asset-del', id: a.id })
  if (r.ok) { window.__krToast?.('🗑️ Deleted', 'ok'); await loadAssets() }
}
const amcDays = (a) => a.days_left === null ? null : (a.days_left < 0 ? `expired ${Math.abs(a.days_left)}d ago` : `${a.days_left}d left`)
const amcBadge = (a) => a.days_left === null ? 'b-gray' : a.days_left <= 0 ? 'b-red' : a.days_left <= 30 ? 'b-orange' : 'b-green'

/* ══════════ NOTICES (spec 3.9) ══════════ */
const notices = ref([])
const noticeModal = ref(null)
const noticeForm = ref({})
async function loadNotices() { const r = await apiCall('mall', { action: 'notices' }); if (r.ok) notices.value = r.notices }
function openNoticeAdd() { noticeForm.value = { title: '', body: '', date: new Date().toISOString().slice(0, 10), pinned: false }; noticeModal.value = true }
async function saveNotice() {
  if (!noticeForm.value.title.trim()) { window.__krToast?.('Title required.', 'err'); return }
  const r = await apiCall('mall', { action: 'notice-add', title: noticeForm.value.title, body: noticeForm.value.body, date: noticeForm.value.date, pinned: noticeForm.value.pinned ? 1 : 0 })
  if (r.ok) { window.__krToast?.('📢 Notice posted', 'ok'); noticeModal.value = false; await loadNotices() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delNotice(n) {
  if (!window.confirm(`Delete notice "${n.title}"?`)) return
  const r = await apiCall('mall', { action: 'notice-del', id: n.id })
  if (r.ok) { window.__krToast?.('🗑️ Notice deleted', 'ok'); await loadNotices() }
}
async function togglePin(n) {
  const r = await apiCall('mall', { action: 'notice-pin', id: n.id, pinned: n.pinned ? 0 : 1 })
  if (r.ok) { window.__krToast?.(n.pinned ? '📌 Unpinned' : '📌 Pinned to top', 'ok'); await loadNotices() }
}

/* ══════════ AUDIT TRAIL ══════════ */
const auditRows = ref([])
const auditQ = ref('')
const auditBusy = ref(false)
async function loadAudit() {
  auditBusy.value = true
  try { const r = await apiCall('mall', { action: 'audit', q: auditQ.value }); if (r.ok) auditRows.value = r.audit } finally { auditBusy.value = false }
}
const auditBadge = (a) => {
  if (['Login', 'Logout'].includes(a.action)) return 'b-gray'
  if (a.action.includes('delete')) return 'b-red'
  if (['collect', 'Expense', 'Notice', 'Receipt'].some(x => a.action.includes(x))) return 'b-green'
  return 'b-blue'
}

/* ══════════ WHATSAPP REMINDER (spec 3.10 bill reminders) ══════════ */
function waPhone(m) {
  let d = String(m || '').replace(/[^\d]/g, '')
  if (d.startsWith('0')) d = d.slice(1)
  if (!d.startsWith('880')) d = '880' + d
  return d
}
function waRemind(b) {
  if (!b.owner_mobile) return
  const kindTxt = b.kind === 'elec' ? 'বিদ্যুৎ বিল' : b.kind === 'water' ? 'পানি বিল' : 'সার্ভিস চার্জ'
  const msg = `শ্রদ্ধেয় ${b.owner_name || 'দোকান মালিক'},\n\n` +
    `${config.value.mall_name || 'আমাদের মার্কেট'}র ${b.shop_no} (${b.shop_floor} তলা) দোকানের ${monthLabel(b.month)} মাসের ${kindTxt} বাবদ ${money(b.amount)} টাকা বকেয়া আছে।` +
    (Number(b.fine) ? ` সাথে দেরি ফি ${money(b.fine)} টাকা।` : '') +
    `\n\nঅনুগ্রহ করে ${b.due_date ? b.due_date + ' এর মধ্যে ' : ''}পরিশোধ করুন। ধন্যবাদ।\n— ${config.value.mall_name || 'পরিচালনা কমিটি'}`
  window.open('https://wa.me/' + waPhone(b.owner_mobile) + '?text=' + encodeURIComponent(msg), '_blank')
}

/* ══════════ LEDGER ══════════ */
const ledger = ref(null)
async function loadLedger() { const r = await apiCall('mall', { action: 'ledger', month: month.value }); if (r.ok) ledger.value = r }

/* ── tab switching ── */
function switchTab(x) {
  tab.value = x
  if (x === 'dashboard') loadDash()
  if (x === 'bills') loadBills()
  if (x === 'ledger') loadLedger()
  if (x === 'meters') { meterForm.value.month = month.value; loadMeters() }
  if (x === 'expenses') loadExpenses()
  if (x === 'complaints') loadComplaints()
  if (x === 'assets') loadAssets()
  if (x === 'notices') loadNotices()
  if (x === 'audit') loadAudit()
}

onMounted(async () => { await loadConfig(); await loadDash() })
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🏬 {{ config.mall_name || 'Mall Management' }}</h1>
        <div class="sub">Service charges · elec/water sub-meter billing · collections · expenses · ledger</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:6px;background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:5px 8px">
          <button @click="shiftMonth(-1)" style="border:none;background:none;cursor:pointer;font-weight:800;color:var(--text)">◀</button>
          <input type="month" v-model="month" @change="switchTab(tab)" style="padding:6px 8px;border:none;background:transparent;color:var(--text);font-weight:700;font-size:13px;outline:none;font-family:inherit" />
          <button @click="shiftMonth(1)" style="border:none;background:none;cursor:pointer;font-weight:800;color:var(--text)">▶</button>
        </div>
        <button v-if="tab === 'shops' && canManage" @click="openAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Add shop</button>
      </div>
    </div>

    <ScrollTabs style="gap:6px;margin-bottom:18px;border-bottom:1px solid var(--border);padding-bottom:10px">
      <button v-for="[id, ico, label] in TABS" :key="id" @click="switchTab(id)"
        :style="tab === id ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'"
        style="padding:8px 14px;border:none;border-radius:10px;font-size:12.5px;font-weight:800;cursor:pointer">{{ ico }} {{ label }}</button>
    </ScrollTabs>

    <!-- ═══════ DASHBOARD ═══════ -->
    <template v-if="tab === 'dashboard'">
      <div class="stats">
        <div v-for="k in dashKpis" :key="k.label" class="stat">
          <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
          <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
          <div class="s-trend">{{ k.trend }}</div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:16px" class="dash-grid">
        <div class="panel" style="padding:16px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
            <h3 style="font-size:14px">🚨 Top defaulters — {{ monthLabel(month) }}</h3>
            <span v-if="dash" class="badge b-orange">{{ (dash.kpi || {}).unpaid_bills || 0 }} unpaid</span>
          </div>
          <div class="tbl-wrap" v-if="dash && dash.defaulters.length" style="max-height:300px">
            <table class="kr">
              <thead><tr><th>Shop</th><th>Owner</th><th style="text-align:right">Due</th></tr></thead>
              <tbody>
                <tr v-for="d in dash.defaulters" :key="d.id">
                  <td><b>{{ d.no }}</b> <small style="color:var(--text-mute)">· {{ d.floor }}</small></td>
                  <td>{{ d.owner_name }}</td>
                  <td style="text-align:right;color:var(--danger);font-weight:800">{{ money(d.due) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else style="color:var(--text-mute);font-size:13px">🎉 No outstanding bills this month.</p>
        </div>
        <div style="display:flex;flex-direction:column;gap:16px">
          <div class="panel" style="padding:16px;flex:1">
            <h3 style="font-size:14px;margin-bottom:12px">📉 Expenses by category — {{ monthLabel(month) }}</h3>
            <div v-if="dash && dash.expense_cats.length" style="display:flex;flex-direction:column;gap:9px">
              <div v-for="c in dash.expense_cats" :key="c.cat">
                <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:3px">
                  <span style="color:var(--text)">{{ c.cat }}</span><b>{{ money(c.total) }}</b>
                </div>
                <div style="height:6px;border-radius:99px;background:var(--bg-alt);overflow:hidden">
                  <div :style="{ width: Math.min(100, Math.round(c.total / (dash.expense_cats[0].total || 1) * 100)) + '%', background: 'var(--primary)', height: '100%' }"></div>
                </div>
              </div>
            </div>
            <p v-else style="color:var(--text-mute);font-size:13px">No expenses recorded this month.</p>
          </div>
          <div class="panel" style="padding:16px">
            <h3 style="font-size:14px;margin-bottom:10px">🕘 Recent collections</h3>
            <div v-if="payments.length" style="display:flex;flex-direction:column;gap:8px">
              <div v-for="p in payments.slice(0, 5)" :key="p.id" style="display:flex;justify-content:space-between;font-size:12.5px">
                <span><b>{{ p.shop_no }}</b> · {{ p.method }} <small style="color:var(--text-mute)">({{ p.receipt }})</small></span>
                <b style="color:var(--ok)">{{ money(p.amount) }}</b>
              </div>
            </div>
            <p v-else style="color:var(--text-mute);font-size:13px">No collections yet this month.</p>
          </div>
        </div>
      </div>
    </template>

    <!-- ═══════ SHOPS ═══════ -->
    <template v-if="tab === 'shops'">
      <div class="stats">
        <div v-for="k in shopKpis" :key="k.label" class="stat">
          <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
          <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
          <div class="s-trend">{{ k.trend || '' }}</div>
        </div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <input v-model="shopQuery" placeholder="🔍 Search shop no / owner / mobile…" style="padding:9px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);min-width:240px;font-family:inherit;font-size:13px;outline:none" />
        <select v-model="shopStatus" style="padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          <option value="">All statuses</option>
          <option v-for="(v, k) in { Active: '🟢 Active', Closed: '🔴 Closed', Vacant: '⚪ Vacant' }" :key="k" :value="k">{{ v }}</option>
        </select>
      </div>
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>Shop</th><th>Floor</th><th>Sqft</th><th>Owner</th><th>Mobile</th><th>Status</th><th style="text-align:right">Rate/mo</th><th></th></tr></thead>
            <tbody>
              <tr v-for="s in filteredShops" :key="s.id">
                <td><b>{{ s.no }}</b><br /><small style="color:var(--text-mute)">{{ s.id }}</small></td>
                <td>{{ s.floor }}</td>
                <td>{{ (s.sqft || 0).toLocaleString('en-IN') }}</td>
                <td>{{ s.owner_name || '—' }}</td>
                <td>{{ s.owner_mobile || '—' }}</td>
                <td><span class="badge" :class="badge(s.status)">{{ s.status }}</span></td>
                <td style="text-align:right;font-weight:800">{{ money(s.service_rate) }}</td>
                <td style="text-align:right;white-space:nowrap">
                  <button v-if="canManage" @click="openEdit(s)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px">✏️</button>
                  <button v-if="canManage" @click="deleteShop(s)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px;margin-left:4px">🗑️</button>
                </td>
              </tr>
              <tr v-if="!filteredShops.length"><td colspan="8" style="text-align:center;color:var(--text-mute);padding:28px">No shops yet — add your first shop with ＋ Add shop. Opening balance covers legacy dues.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <p style="color:var(--text-mute);font-size:12px;margin-top:10px">💡 Rate/mo = flat service charge per shop. Shop owners collect their own rent — service charges &amp; utilities are billed here.</p>
    </template>

    <!-- ═══════ BILLS & COLLECTIONS ═══════ -->
    <template v-if="tab === 'bills'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🧾</span>Billed</div><div class="s-value">{{ money(billsTotals.billed) }}</div><div class="s-trend">{{ bills.length }} bills</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💵</span>Collected</div><div class="s-value" style="color:var(--ok)">{{ money(billsTotals.collected) }}</div><div class="s-trend">{{ payments.length }} receipts</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⏳</span>Outstanding</div><div class="s-value" :style="Number(billsTotals.billed) - Number(billsTotals.collected) > 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(Number(billsTotals.billed) - Number(billsTotals.collected)) }}</div><div class="s-trend">after collections</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💸</span>Late fees</div><div class="s-value">{{ money(billsTotals.fines) }}</div><div class="s-trend">{{ config.late_fee_pct }}% of overdue bills</div></div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="generateBills" :disabled="billsBusy" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">⚙️ Generate service-charge bills</button>
        <button v-if="canManage" @click="calcFines" :disabled="finesBusy" class="btn-ghost" title="Apply late payment fines to overdue unpaid bills">💸 Compute late fees</button>
        <select v-model="billKind" @change="loadBills" style="padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          <option value="">All kinds</option>
          <option v-for="(v, k) in { service: '🧾 Service', elec: '⚡ Electricity', water: '💧 Water' }" :key="k" :value="k">{{ v }}</option>
        </select>
        <select v-model="billStatus" @change="loadBills" style="padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          <option value="">All statuses</option><option>Unpaid</option><option>Paid</option>
        </select>
      </div>
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>#</th><th>Shop</th><th>Floor</th><th>Kind</th><th style="text-align:right">Amount</th><th>Due</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="b in bills" :key="b.id">
                <td><small style="color:var(--text-mute)">{{ b.id }}</small></td>
                <td><b>{{ b.shop_no || b.shop }}</b></td>
                <td>{{ b.shop_floor || '—' }}</td>
                <td>{{ { service: '🧾 Service', elec: '⚡ Electricity', water: '💧 Water' }[b.kind] || b.kind }}</td>
                <td style="text-align:right;font-weight:800">{{ money(b.amount) }}<span v-if="b.fine" style="color:var(--danger);font-size:11px"> +{{ money(b.fine) }} fine</span></td>
                <td style="font-size:12px;color:var(--text-mute)">{{ b.due_date }}<span v-if="isOverdue(b)" class="badge b-red" style="margin-left:6px">overdue</span></td>
                <td><span class="badge" :class="badge(b.status)">{{ b.status }}</span></td>
                <td style="text-align:right;white-space:nowrap">
                  <button v-if="b.status === 'Unpaid' && b.owner_mobile" @click="waRemind(b)" title="Send WhatsApp reminder to the shop owner" style="padding:6px 10px;border:1px solid #25D366;color:#1faa53;background:rgba(37,211,102,.08);border-radius:8px;cursor:pointer;font-size:12px;font-weight:700">📲 Remind</button>
                  <button v-if="b.status === 'Unpaid' && canCollect" @click="openPay(b)" style="padding:6px 12px;border:none;border-radius:8px;background:var(--primary);color:#fff;font-size:12px;font-weight:800;cursor:pointer;margin-left:4px">💵 Collect</button>
                  <button v-if="b.status === 'Paid'" @click="openReceipt(b)" title="View / print receipt" style="padding:6px 10px;border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;cursor:pointer;font-size:12px;margin-left:4px">🖨️ Receipt</button>
                </td>
              </tr>
              <tr v-if="!bills.length"><td colspan="8" style="text-align:center;color:var(--text-mute);padding:28px">No bills for {{ monthLabel(month) }} — press ⚙️ Generate to create monthly service-charge bills for all active shops.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="panel" style="padding:16px;margin-top:16px" v-if="payments.length">
        <h3 style="font-size:14px;margin-bottom:10px">🕘 Collection history — {{ monthLabel(month) }}</h3>
        <div class="tbl-wrap" style="max-height:260px">
          <table class="kr">
            <thead><tr><th>Receipt</th><th>Shop</th><th>Kind</th><th>Method</th><th>Ref</th><th style="text-align:right">Amount</th></tr></thead>
            <tbody>
              <tr v-for="p in payments" :key="p.id">
                <td><b>{{ p.receipt }}</b></td>
                <td>{{ p.shop_no }} · {{ p.shop_floor }}</td>
                <td>{{ { service: '🧾 Service', elec: '⚡ Electricity', water: '💧 Water' }[p.kind] || p.kind }}</td>
                <td><span class="badge b-blue">{{ p.method }}</span></td>
                <td style="color:var(--text-mute)">{{ p.ref || '—' }}</td>
                <td style="text-align:right;font-weight:800;color:var(--ok)">{{ money(p.amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ═══════ METERS ═══════ -->
    <template v-if="tab === 'meters'">
      <div class="panel" style="padding:18px;max-width:640px">
        <h3 style="font-size:14px;margin-bottom:6px">⚡ Sub-meter reading → auto bill</h3>
        <p style="color:var(--text-mute);font-size:12.5px;margin-bottom:14px">Units = reading − previous reading × rate ({{ money(config.elec_unit_rate) }}/unit elec, {{ money(config.water_unit_rate) }}/unit water). Collected amounts are <b>custodial</b> — forwarded to DESCO/WASA, tracked separately from service charges.</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <label style="font-size:12px;color:var(--text-mute)">Shop
            <select v-model="meterForm.shop" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <option value="">Select shop…</option>
              <option v-for="s in shops.filter(x => x.status === 'Active')" :key="s.id" :value="s.id">{{ s.no }} — {{ s.floor }} ({{ s.owner_name }})</option>
            </select>
          </label>
          <label style="font-size:12px;color:var(--text-mute)">Type
            <select v-model="meterForm.type" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <option value="elec">⚡ Electricity</option><option value="water">💧 Water</option>
            </select>
          </label>
          <label style="font-size:12px;color:var(--text-mute)">Month
            <input type="month" v-model="meterForm.month" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute)">Meter reading
            <input type="number" v-model.number="meterForm.reading" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
        </div>
        <button @click="saveMeter" :disabled="saving" style="margin-top:14px;padding:10px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save reading &amp; generate bill</button>
      </div>
      <div class="panel" style="padding:16px;margin-top:16px">
        <h3 style="font-size:14px;margin-bottom:10px">📋 Readings — {{ monthLabel(meterForm.month || month) }}</h3>
        <div class="tbl-wrap" style="max-height:280px">
          <table class="kr">
            <thead><tr><th>Shop</th><th>Type</th><th style="text-align:right">Reading</th><th style="text-align:right">Units</th><th>Billed</th></tr></thead>
            <tbody>
              <tr v-for="r in lastReadings" :key="r.id">
                <td><b>{{ r.no || r.shop }}</b></td>
                <td>{{ r.type === 'elec' ? '⚡ Electricity' : '💧 Water' }}</td>
                <td style="text-align:right">{{ r.reading.toLocaleString('en-IN') }}</td>
                <td style="text-align:right;font-weight:800">{{ r.units.toLocaleString('en-IN') }}</td>
                <td>{{ money((r.units || 0) * (r.type === 'elec' ? config.elec_unit_rate : config.water_unit_rate)) }}</td>
              </tr>
              <tr v-if="!lastReadings.length"><td colspan="5" style="text-align:center;color:var(--text-mute);padding:24px">No readings yet this month.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ═══════ EXPENSES ═══════ -->
    <template v-if="tab === 'expenses'">
      <div class="panel" style="padding:18px;max-width:640px">
        <h3 style="font-size:14px;margin-bottom:14px">📉 Record an expense — {{ monthLabel(month) }}</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <label style="font-size:12px;color:var(--text-mute)">Category
            <select v-model="expForm.category" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <option v-for="c in EXP_CATEGORIES" :key="c" :value="c">{{ c }}</option>
            </select>
          </label>
          <label style="font-size:12px;color:var(--text-mute)">Vendor / supplier
            <input v-model="expForm.vendor" placeholder="e.g. Otis Elevator, DESCO" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute)">Amount (৳)
            <input type="number" v-model.number="expForm.amount" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute)">Paid via
            <select v-model="expForm.method" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <option value="cash">💵 Cash</option><option value="bank">🏦 Bank</option><option value="bkash">📱 bKash</option><option value="nagad">📱 Nagad</option>
            </select>
          </label>
          <label style="font-size:12px;color:var(--text-mute);grid-column:1/-1">Note (voucher / invoice)
            <input v-model="expForm.note" placeholder="e.g. Monthly lift AMC — invoice #88412" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
        </div>
        <button @click="saveExpense" style="margin-top:14px;padding:10px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Record expense</button>
      </div>
      <div class="panel" style="padding:16px;margin-top:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <h3 style="font-size:14px">🧾 Expense ledger — {{ monthLabel(month) }}</h3>
          <span class="badge b-red" style="font-size:12px">Total {{ money(expTotal) }}</span>
        </div>
        <div class="tbl-wrap" style="max-height:300px">
          <table class="kr">
            <thead><tr><th>Date</th><th>Category</th><th>Vendor</th><th>Note</th><th>Method</th><th style="text-align:right">Amount</th><th></th></tr></thead>
            <tbody>
              <tr v-for="e in expenses" :key="e.id">
                <td style="font-size:12px;color:var(--text-mute)">{{ (e.date || '').slice(0, 10) }}</td>
                <td><b>{{ e.category }}</b></td>
                <td>{{ e.vendor || '—' }}</td>
                <td style="color:var(--text-mute)">{{ e.note || '—' }}</td>
                <td><span class="badge b-blue">{{ e.method }}</span></td>
                <td style="text-align:right;font-weight:800;color:var(--danger)">{{ money(e.amount) }}</td>
                <td style="text-align:right"><button v-if="canManage" @click="delExpense(e)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px">🗑️</button></td>
              </tr>
              <tr v-if="!expenses.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:24px">No expenses recorded for {{ monthLabel(month) }}.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ═══════ COMPLAINTS ═══════ -->
    <template v-if="tab === 'complaints'">
      <div class="stats">
        <div v-for="(n, st) in compCounts" :key="st" class="stat">
          <div class="s-label"><span class="s-ico">{{ { 'Open': '🔴', 'In Progress': '🔵', 'Resolved': '🟢' }[st] || '🔧' }}</span>{{ st }}</div>
          <div class="s-value" :style="st === 'Resolved' ? 'color:var(--ok)' : st === 'Open' ? 'color:var(--danger)' : ''">{{ n }}</div>
          <div class="s-trend">{{ st === 'Open' ? 'need attention' : st === 'In Progress' ? 'being handled' : 'closed' }}</div>
        </div>
        <div class="stat"><div class="s-label"><span class="s-ico">📋</span>Total logged</div><div class="s-value">{{ compCounts.Open + compCounts['In Progress'] + compCounts.Resolved }}</div><div class="s-trend">all time</div></div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openCompAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Log complaint</button>
        <select v-model="compStatus" @change="loadComplaints" style="padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          <option value="">All statuses</option><option>Open</option><option>In Progress</option><option>Resolved</option>
        </select>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Shop owners report issues (lift / AC / light…) — committee tracks Open → In Progress → Resolved</span>
      </div>
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>#</th><th>Shop</th><th>Subject</th><th>Priority</th><th>Opened</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="c in complaints" :key="c.id">
                <td><small style="color:var(--text-mute)">{{ c.id }}</small></td>
                <td><b>{{ c.shop_no || c.shop }}</b> <small style="color:var(--text-mute)">· {{ c.shop_floor }}</small></td>
                <td>{{ c.subject }}<br /><small style="color:var(--text-mute)">{{ c.descr }}</small></td>
                <td><span class="badge" :class="{ Low: 'b-gray', Normal: 'b-blue', High: 'b-orange', Urgent: 'b-red' }[c.priority] || 'b-gray'">{{ c.priority }}</span></td>
                <td style="font-size:12px;color:var(--text-mute)">{{ (c.opened_at || '').slice(0, 10) }}</td>
                <td><span class="badge" :class="badge(c.status)">{{ c.status }}</span></td>
                <td style="text-align:right;white-space:nowrap">
                  <template v-if="canManage">
                    <button v-if="c.status === 'Open'" @click="setCompStatus(c, 'In Progress')" style="padding:5px 9px;border:none;border-radius:8px;background:var(--primary);color:#fff;font-size:11.5px;font-weight:800;cursor:pointer">▶ Start</button>
                    <button v-if="c.status !== 'Resolved'" @click="setCompStatus(c, 'Resolved')" style="padding:5px 9px;border:none;border-radius:8px;background:var(--ok);color:#fff;font-size:11.5px;font-weight:800;cursor:pointer;margin-left:4px">✓ Resolve</button>
                    <button @click="delComplaint(c)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 8px;cursor:pointer;font-size:11px;margin-left:4px">🗑️</button>
                  </template>
                </td>
              </tr>
              <tr v-if="!complaints.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:28px">No complaints — log the first one with ＋ Log complaint.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ═══════ ASSETS & AMC ═══════ -->
    <template v-if="tab === 'assets'">
      <div v-if="amcReminders.length" style="margin-bottom:14px;padding:13px 16px;border-radius:12px;background:rgba(235,87,87,.08);border:1px solid rgba(235,87,87,.3);display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <span style="font-size:14px">⏰</span>
        <div style="flex:1;min-width:220px">
          <b style="font-size:13px">{{ amcReminders.length }} AMC contract{{ amcReminders.length > 1 ? 's' : '' }} expiring within 30 days</b>
          <div style="font-size:12px;color:var(--text-mute);margin-top:2px">{{ amcReminders.map(a => a.name + ' (' + a.contract_until + ')').join(' · ') }}</div>
        </div>
        <span class="badge b-red">renew soon</span>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openAssetAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Add asset</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Lifts, escalators, generators, fire extinguishers — service contracts &amp; warranty with auto reminders</span>
      </div>
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>Asset</th><th>Type</th><th>Location</th><th>Vendor</th><th>Installed</th><th>Warranty until</th><th>AMC until</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="a in assets" :key="a.id">
                <td><b>{{ a.name }}</b></td>
                <td><span class="badge b-blue">{{ a.type }}</span></td>
                <td>{{ a.location || '—' }}</td>
                <td>{{ a.vendor || '—' }}</td>
                <td style="font-size:12px;color:var(--text-mute)">{{ a.install_date || '—' }}</td>
                <td style="font-size:12px;color:var(--text-mute)">{{ a.warranty_until || '—' }}</td>
                <td style="font-size:12px">{{ a.contract_until || '—' }} <span v-if="amcDays(a)" class="badge" :class="amcBadge(a)" style="margin-left:4px">{{ amcDays(a) }}</span></td>
                <td><span class="badge" :class="badge(a.status)">{{ a.status }}</span></td>
                <td style="text-align:right;white-space:nowrap">
                  <button v-if="canManage" @click="openAssetEdit(a)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px">✏️</button>
                  <button v-if="canManage" @click="delAsset(a)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px;margin-left:4px">🗑️</button>
                </td>
              </tr>
              <tr v-if="!assets.length"><td colspan="9" style="text-align:center;color:var(--text-mute);padding:28px">No assets yet — add lifts, generators and fire extinguishers with ＋ Add asset.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ═══════ NOTICES ═══════ -->
    <template v-if="tab === 'notices'">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openNoticeAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">📢 Post notice</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Committee announcements for shop owners — pinned notices stay on top</span>
      </div>
      <div v-if="notices.length" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px">
        <div v-for="n in notices" :key="n.id" class="panel chip" style="padding:16px;border-left:3px solid" :style="n.pinned ? 'border-left-color:var(--primary)' : 'border-left-color:var(--border)'">
          <div style="display:flex;align-items:flex-start;gap:10px">
            <span style="font-size:18px">{{ n.pinned ? '📌' : '📢' }}</span>
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <b style="font-size:14px">{{ n.title }}</b>
                <span v-if="n.pinned" class="badge b-blue" style="font-size:10px">PINNED</span>
              </div>
              <div style="font-size:12.5px;color:var(--text);margin-top:6px;white-space:pre-wrap">{{ n.body || '—' }}</div>
              <div style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:11.5px;color:var(--text-mute);flex-wrap:wrap">
                <span>📅 {{ n.date }}</span><span>· by {{ n.author || '—' }}</span>
                <span style="flex:1"></span>
                <template v-if="canManage">
                  <button @click="togglePin(n)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:4px 9px;cursor:pointer;font-size:11.5px">{{ n.pinned ? 'Unpin' : '📌 Pin' }}</button>
                  <button @click="delNotice(n)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:4px 9px;cursor:pointer;font-size:11.5px">🗑️</button>
                </template>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="panel" style="padding:30px;text-align:center;color:var(--text-mute)">No notices yet — post the first announcement with 📢 Post notice.</div>
    </template>

    <!-- ═══════ AUDIT TRAIL ═══════ -->
    <template v-if="tab === 'audit'">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <input v-model="auditQ" placeholder="🔍 Search user / action / module…" @keyup.enter="loadAudit" style="padding:9px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);min-width:260px;font-family:inherit;font-size:13px;outline:none" />
        <button @click="loadAudit" class="btn-ghost">Search</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Who did what, when — collections, expenses, complaints, assets, notices, logins</span>
      </div>
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>When</th><th>User</th><th>Action</th><th>Module</th><th>Entity</th><th>Details</th></tr></thead>
            <tbody>
              <tr v-for="r in auditRows" :key="r.id">
                <td style="font-size:12px;color:var(--text-mute)">{{ (r.ts || '').slice(0, 16) }}</td>
                <td><b>{{ r.user }}</b></td>
                <td><span class="badge" :class="auditBadge(r)">{{ r.action }}</span></td>
                <td style="color:var(--text-mute)">{{ r.module }}</td>
                <td style="color:var(--text-mute)">{{ r.entity }}</td>
                <td style="color:var(--text-mute);max-width:340px;overflow:hidden;text-overflow:ellipsis">{{ r.details || '' }}</td>
              </tr>
              <tr v-if="!auditRows.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:28px">No activity recorded yet.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ═══════ LEDGER ═══════ -->
    <template v-if="tab === 'ledger'">
      <div v-if="ledger">
        <div class="stats">
          <div v-for="k in ledger.by_kind" :key="k.kind" class="stat">
            <div class="s-label"><span class="s-ico">{{ { service: '🧾', elec: '⚡', water: '💧' }[k.kind] || '💰' }}</span>{{ { service: 'Service charges', elec: 'Electricity (custodial)', water: 'Water (custodial)' }[k.kind] || k.kind }}</div>
            <div class="s-value" style="font-size:18px">{{ money(k.collected) }} <small style="color:var(--text-mute);font-weight:500">/ {{ money(k.billed) }}</small></div>
            <div class="s-trend">{{ k.billed ? Math.round(k.collected / k.billed * 100) : 0 }}% collected</div>
          </div>
          <div class="stat">
            <div class="s-label"><span class="s-ico">📉</span>Expenses</div>
            <div class="s-value" style="color:var(--danger);font-size:18px">{{ money(ledger.expenses) }}</div>
            <div class="s-trend">all categories</div>
          </div>
        </div>
        <div class="panel" style="padding:16px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <h3 style="font-size:14px">🏪 Per-shop ledger — {{ monthLabel(ledger.month) }}</h3>
            <span class="badge b-green" style="font-size:12px">Net balance {{ money(Number(ledger.by_kind.reduce((s, k) => s + k.collected, 0)) - Number(ledger.expenses)) }}</span>
          </div>
          <div class="tbl-wrap" style="max-height:420px">
            <table class="kr">
              <thead><tr><th>Shop</th><th>Owner</th><th style="text-align:right">Service</th><th style="text-align:right">Elec</th><th style="text-align:right">Water</th><th style="text-align:right">Total due</th><th>Status</th></tr></thead>
              <tbody>
                <tr v-for="s in ledger.per_shop" :key="s.id">
                  <td><b>{{ s.no }}</b> <small style="color:var(--text-mute)">· {{ s.floor }}</small></td>
                  <td>{{ s.owner_name || '—' }}</td>
                  <td style="text-align:right">{{ money(s.sc_paid) }}<small style="color:var(--text-mute)"> / {{ money(s.sc_billed) }}</small></td>
                  <td style="text-align:right">{{ money(s.el_paid) }}<small style="color:var(--text-mute)"> / {{ money(s.el_billed) }}</small></td>
                  <td style="text-align:right">{{ money(s.w_paid) }}<small style="color:var(--text-mute)"> / {{ money(s.w_billed) }}</small></td>
                  <td style="text-align:right;font-weight:800" :style="(s.sc_billed - s.sc_paid) + (s.el_billed - s.el_paid) + (s.w_billed - s.w_paid) > 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money((s.sc_billed - s.sc_paid) + (s.el_billed - s.el_paid) + (s.w_billed - s.w_paid)) }}</td>
                  <td><span class="badge" :class="(s.sc_billed - s.sc_paid) + (s.el_billed - s.el_paid) + (s.w_billed - s.w_paid) > 0 ? 'b-orange' : 'b-green'">{{ (s.sc_billed - s.sc_paid) + (s.el_billed - s.el_paid) + (s.w_billed - s.w_paid) > 0 ? 'Due' : 'Clear' }}</span></td>
                </tr>
                <tr v-if="!ledger.per_shop.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:24px">No shops yet.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <p v-else style="color:var(--text-mute)">Loading ledger…</p>
    </template>

    <!-- ═══════ SETTINGS ═══════ -->
    <div class="panel" style="padding:18px;margin-top:20px">
      <h3 style="font-size:14px;margin-bottom:12px">⚙️ Mall settings</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
        <label style="font-size:12px;color:var(--text-mute)">Mall name
          <input v-model="config.mall_name" placeholder="e.g. Razzak Plaza" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
        </label>
        <label style="font-size:12px;color:var(--text-mute)">Elec rate (৳/unit)
          <input type="number" v-model.number="config.elec_unit_rate" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
        </label>
        <label style="font-size:12px;color:var(--text-mute)">Water rate (৳/unit)
          <input type="number" v-model.number="config.water_unit_rate" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
        </label>
        <label style="font-size:12px;color:var(--text-mute)">Due day of month
          <input type="number" v-model.number="config.due_day" min="1" max="28" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
        </label>
      </div>
      <button @click="saveConfig" :disabled="!cfgDirty" style="margin-top:14px;padding:10px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save settings</button>
    </div>

    <!-- ═══════ SHOP MODAL ═══════ -->
    <div v-if="modal" class="overlay" @click.self="modal = null">
      <div class="modal">
        <div class="modal-h"><div class="t">{{ modal.title }}</div><button class="close" @click="modal = null">✕</button></div>
        <div class="modal-b">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">Shop no *<input v-model="form.no" placeholder="e.g. A-101" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Floor<input v-model="form.floor" placeholder="e.g. Ground" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Size (sqft)<input type="number" v-model.number="form.sqft" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Service rate (৳/mo)<input type="number" v-model.number="form.service_rate" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Owner name *<input v-model="form.owner_name" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Owner mobile<input v-model="form.owner_mobile" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Owner NID<input v-model="form.owner_nid" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Status
              <select v-model="form.status" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="(v, k) in { Active: '🟢 Active', Closed: '🔴 Closed', Vacant: '⚪ Vacant' }" :key="k" :value="k">{{ v }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Opening balance (৳)<input type="number" v-model.number="form.opening_balance" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveShop" :disabled="saving" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ saving ? 'Saving…' : '💾 Save shop' }}</button>
            <button @click="modal = null" class="btn-ghost" style="padding:11px 18px">Cancel</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ COLLECT MODAL ═══════ -->
    <div v-if="payModal" class="overlay" @click.self="payModal = null">
      <div class="modal" style="max-width:420px">
        <div class="modal-h"><div class="t">💵 Collect — {{ payModal.shop_no }} ({{ { service: 'Service', elec: 'Electricity', water: 'Water' }[payModal.kind] }})</div><button class="close" @click="payModal = null">✕</button></div>
        <div class="modal-b">
          <p style="color:var(--text-mute);font-size:12.5px;margin-bottom:12px">{{ monthLabel(payModal.month) }} · bill #{{ payModal.id }}</p>
          <label style="font-size:12px;color:var(--text-mute)">Amount (৳)<input type="number" v-model.number="payForm.amount" min="1" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          <p v-if="payModal.fine" style="font-size:12px;color:var(--danger);margin-top:8px">⚠️ Includes late fee of {{ money(payModal.fine) }} (bill overdue)</p>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Method
            <select v-model="payForm.method" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <option value="cash">💵 Cash</option><option value="bank">🏦 Bank</option><option value="bkash">📱 bKash</option><option value="nagad">📱 Nagad</option>
            </select>
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Reference (trx no / note)<input v-model="payForm.ref" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="savePay" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save collection</button>
            <button @click="payModal = null" class="btn-ghost" style="padding:11px 18px">Cancel</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ COMPLAINT MODAL ═══════ -->
    <div v-if="compModal" class="overlay" @click.self="compModal = null">
      <div class="modal" style="max-width:480px">
        <div class="modal-h"><div class="t">{{ compModal.title }}</div><button class="close" @click="compModal = null">✕</button></div>
        <div class="modal-b">
          <label style="font-size:12px;color:var(--text-mute)">Shop *
            <select v-model="compForm.shop" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <option value="">Select shop…</option>
              <option v-for="s in shops.filter(x => x.status === 'Active')" :key="s.id" :value="s.id">{{ s.no }} — {{ s.floor }} ({{ s.owner_name }})</option>
            </select>
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Subject *<input v-model="compForm.subject" placeholder="e.g. Lift not working on 2nd floor" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Details<textarea v-model="compForm.descr" rows="2" placeholder="Describe the issue…" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea></label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Priority
            <select v-model="compForm.priority" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <option v-for="p in ['Low', 'Normal', 'High', 'Urgent']" :key="p" :value="p">{{ p }}</option>
            </select>
          </label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveComplaint" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">🔧 Log complaint</button>
            <button @click="compModal = null" class="btn-ghost" style="padding:11px 18px">Cancel</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ ASSET MODAL ═══════ -->
    <div v-if="assetModal" class="overlay" @click.self="assetModal = null">
      <div class="modal" style="max-width:560px">
        <div class="modal-h"><div class="t">{{ assetModal.title }}</div><button class="close" @click="assetModal = null">✕</button></div>
        <div class="modal-b">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">Asset name *<input v-model="assetForm.name" placeholder="e.g. Passenger Lift 1" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Type
              <select v-model="assetForm.type" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="t in ASSET_TYPES" :key="t" :value="t">{{ t }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Location<input v-model="assetForm.location" placeholder="e.g. Block A, near main entrance" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Vendor / service provider<input v-model="assetForm.vendor" placeholder="e.g. Otis Elevator" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Install date<input type="date" v-model="assetForm.install_date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Warranty until<input type="date" v-model="assetForm.warranty_until" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">AMC / contract until<input type="date" v-model="assetForm.contract_until" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Cost (৳)<input type="number" v-model.number="assetForm.cost" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Status
              <select v-model="assetForm.status" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="st in ['Active', 'Under Service', 'Out of Service']" :key="st" :value="st">{{ st }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute);grid-column:1/-1">Note<input v-model="assetForm.note" placeholder="Any notes…" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveAsset" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save asset</button>
            <button @click="assetModal = null" class="btn-ghost" style="padding:11px 18px">Cancel</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ NOTICE MODAL ═══════ -->
    <div v-if="noticeModal" class="overlay" @click.self="noticeModal = false">
      <div class="modal" style="max-width:480px">
        <div class="modal-h"><div class="t">📢 Post notice</div><button class="close" @click="noticeModal = false">✕</button></div>
        <div class="modal-b">
          <label style="font-size:12px;color:var(--text-mute)">Title *<input v-model="noticeForm.title" placeholder="e.g. Generator maintenance on Sunday 10am–2pm" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Details<textarea v-model="noticeForm.body" rows="3" placeholder="Full announcement…" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea></label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px">
            <label style="font-size:12px;color:var(--text-mute)">Date<input type="date" v-model="noticeForm.date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute);display:flex;align-items:flex-end;gap:8px;padding-bottom:8px"><input type="checkbox" v-model="noticeForm.pinned" style="width:16px;height:16px" /> 📌 Pin to top</label>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveNotice" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">📢 Post</button>
            <button @click="noticeModal = false" class="btn-ghost" style="padding:11px 18px">Cancel</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ RECEIPT MODAL ═══════ -->
    <div v-if="recModal" class="overlay" @click.self="recModal = null">
      <div class="modal" style="max-width:460px">
        <div class="modal-h"><div class="t">🖨️ Money receipt</div><button class="close" @click="recModal = null">✕</button></div>
        <div class="modal-b">
          <div id="receiptPrint">
            <div style="text-align:center;border-bottom:2px dashed var(--border);padding-bottom:12px;margin-bottom:14px">
              <div style="font-size:17px;font-weight:800">{{ recData.mall_name || 'MALL MANAGEMENT' }}</div>
              <div style="font-size:12px;color:var(--text-mute)">Money Receipt · Service Collection</div>
            </div>
            <table style="width:100%;font-size:13.5px;line-height:2">
              <tbody>
                <tr><td style="color:var(--text-mute)">Receipt No</td><td style="text-align:right;font-weight:800">{{ recData.payment.receipt }}</td></tr>
                <tr><td style="color:var(--text-mute)">Date</td><td style="text-align:right">{{ (recData.payment.created_at || '').slice(0, 16) }}</td></tr>
                <tr><td style="color:var(--text-mute)">Shop</td><td style="text-align:right;font-weight:800">{{ recData.bill.shop_no }} · {{ recData.bill.shop_floor }} floor</td></tr>
                <tr><td style="color:var(--text-mute)">Owner</td><td style="text-align:right">{{ recData.bill.owner_name || '—' }}</td></tr>
                <tr><td style="color:var(--text-mute)">Month</td><td style="text-align:right">{{ monthLabel(recData.bill.month) }}</td></tr>
                <tr><td style="color:var(--text-mute)">Charge</td><td style="text-align:right">{{ { service: 'Service charge', elec: 'Electricity (sub-meter)', water: 'Water (sub-meter)' }[recData.bill.kind] }}</td></tr>
                <tr><td style="color:var(--text-mute)">Amount</td><td style="text-align:right;font-weight:800">{{ money(recData.bill.amount) }}</td></tr>
                <tr v-if="recData.bill.fine"><td style="color:var(--text-mute)">Late fee</td><td style="text-align:right;color:var(--danger)">{{ money(recData.bill.fine) }}</td></tr>
                <tr><td style="color:var(--text-mute)">Paid via</td><td style="text-align:right">{{ recData.payment.method }} <span v-if="recData.payment.ref" style="color:var(--text-mute)">({{ recData.payment.ref }})</span></td></tr>
              </tbody>
            </table>
            <div style="display:flex;justify-content:space-between;margin-top:18px;padding-top:10px;border-top:1px solid var(--border);font-size:12px;color:var(--text-mute)">
              <span>Received by: ________________</span><span>Signature: ____________</span>
            </div>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="printReceipt" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">🖨️ Print receipt</button>
            <button @click="recModal = null" class="btn-ghost" style="padding:11px 18px">Close</button>
          </div>
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
@media (max-width: 900px) { .dash-grid { grid-template-columns: 1fr !important; } }
</style>
