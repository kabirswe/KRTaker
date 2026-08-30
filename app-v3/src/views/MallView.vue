<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import html2canvas from 'html2canvas'
import { jsPDF } from 'jspdf'
import SearchableSelect from '../components/SearchableSelect.vue'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import { t, bnd } from '../lib/i18n'
import { useRoute } from 'vue-router'
import { apiCall } from '../api/client'
import { money, monthLabel, badge } from '../lib/ui'

const route = useRoute()

const auth = useAuthStore()
const data = useDataStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'accountant'].includes(auth.user?.role || ''))
const canDecideApprovals = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))
const showApprovals = ref(false)
const waivers = ref([])
const voids = ref([])
const pendingApprovals = computed(() => waivers.value.filter(w => w.status === 'Pending').length + voids.value.filter(v => v.status === 'Pending').length)
async function loadApprovals() {
  const [r1, r2] = await Promise.all([apiCall('mall', { action: 'waivers' }), apiCall('mall', { action: 'payment-voids' })])
  if (r1.ok) waivers.value = r1.waivers
  if (r2.ok) voids.value = r2.voids
}
const waiverModal = ref(null)
const waiverForm = ref({})
function openWaiver(b) { waiverForm.value = { bill_id: b.id, shop: b.shop_no || b.shop, month: b.month, max: Number(b.amount), amount: Math.min(Number(b.amount), 500), reason: '' }; waiverModal.value = { bill: b } }
async function requestWaiver() {
  const f = waiverForm.value
  if (!f.amount || f.amount <= 0 || f.amount > f.max || !f.reason.trim()) { window.__krToast?.('Enter a valid amount (≤ ' + money(f.max) + ') and a reason.', 'err'); return }
  const r = await apiCall('mall', { action: 'waiver-request', bill_id: f.bill_id, amount: Number(f.amount), reason: f.reason.trim() })
  if (r.ok) { window.__krToast?.(t('💸 Waiver requested — pending admin approval'), 'ok'); waiverModal.value = null; await loadApprovals() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function decideWaiver(w, approve) {
  if (!window.confirm(`${approve ? 'Approve' : 'Reject'} the ৳${w.amount} waiver for ${w.shop}?`)) return
  const r = await apiCall('mall', { action: 'waiver-decide', id: w.id, approve: approve ? 1 : 0 })
  if (r.ok) { window.__krToast?.(approve ? '✅ Waiver approved — ledger adjusted' : '⛔ Waiver rejected', 'ok'); await loadApprovals(); await loadBills() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function requestVoid(p) {
  const reason = window.prompt(`Void receipt ${p.receipt} (৳${p.amount})?\nReason (required) — the admin must approve this:`, 'Wrong entry / correction')
  if (reason === null) return
  if (!reason.trim()) { window.__krToast?.(t('Reason required.'), 'err'); return }
  const r = await apiCall('mall', { action: 'payment-void-request', payment_id: p.id, reason: reason.trim() })
  if (r.ok) { window.__krToast?.(t('🔒 Void requested — pending admin approval (receipt lock)'), 'ok'); await loadApprovals() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function decideVoid(v, approve) {
  if (!window.confirm(`${approve ? 'Approve' : 'Reject'} voiding ${v.receipt || v.payment_receipt} (৳${v.amount})?`)) return
  const r = await apiCall('mall', { action: 'payment-void-decide', id: v.id, approve: approve ? 1 : 0 })
  if (r.ok) { window.__krToast?.(approve ? '✅ Void approved — bill reverted to unpaid' : '⛔ Void rejected', 'ok'); await loadApprovals(); await loadBills(); await loadPayments?.() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
const isCollector = computed(() => auth.user?.role === 'collector')
const canCollect = computed(() => canManage.value || isCollector.value)

/* ── tabs ── */
const tab = ref('dashboard')
const TABS = [
  ['dashboard', '📊', 'Dashboard'],
  ['analytics', '📈', 'Analytics'],
  ['space', '🏪', 'Spaces'],
  ['bills', '🧾', 'Bills & Collections'],
  ['invoices', '🧾', 'Invoices'],
  ['payments', '💳', 'Payments'],
  ['meters', '⚡', 'Meters'],
  ['coa', '🏦', 'Chart of Accounts'],
  ['journal', '📖', 'Journal'],
  ['trial', '⚖️', 'Trial Balance'],
  ['pnl', '📊', 'P&L Statement'],
  ['pl', '🧾', 'Party Ledger'],
  ['statements', '💰', 'Statements'],
  ['cashflow', '🔄', 'Cashflow'],
  ['reconcile', '🔁', 'Reconcile'],
  ['expenses', '📉', 'Expenses'],
  ['complaints', '🔧', 'Complaints'],
  ['assets', '🛠️', 'Assets & AMC'],
  ['notices', '📢', 'Notices'],
  ['audit', '📋', 'Audit'],
  ['staff', '🧑‍💼', 'Staff'],
  ['users', '👥', 'Users & Roles'],
  ['committee', '🏛️', 'Committee'],
  ['owners', '🏢', 'Owners'],
  ['rent', '🧾', 'Rent & Tenants'],
  ['vendors', '🧰', 'Vendors'],
  ['ledger', '📒', 'Ledger'],
  ['settings', '⚙️', 'Settings'],
]
const month = ref(new Date().toISOString().slice(0, 7))
const shiftMonth = (d) => { const m = new Date(month.value + '-01'); m.setMonth(m.getMonth() + d); month.value = m.toISOString().slice(0, 7); switchTab(tab.value) }

/* ── config ── */
const config = ref({ mall_name: '', elec_unit_rate: 8, water_unit_rate: 30, late_fee_pct: 5, due_day: 10, rent_advance_default: 2, rent_due_day: 10, rent_statement_note: '', bill_model_default: 'fixed', rate_default: 0, rate_sqft_default: 0, util_heads: [], income_heads: [] })
const utilHeadInput = ref('')
const incomeHeadInput = ref('')
function addUtilHead() { const v = utilHeadInput.value.trim(); if (!v) return; if (!config.value.util_heads) config.value.util_heads = []; if (!config.value.util_heads.includes(v)) config.value.util_heads.push(v); utilHeadInput.value = ''; cfgDirty.value = true }
function addIncomeHead() { const v = incomeHeadInput.value.trim(); if (!v) return; if (!config.value.income_heads) config.value.income_heads = []; if (!config.value.income_heads.includes(v)) config.value.income_heads.push(v); incomeHeadInput.value = ''; cfgDirty.value = true }
/* SMS engine (ported from KRTaker) */
const smsCfg = ref({ enabled: 0, provider: 'log', api_key: '', sender_id: 'Mall Manager', api_url: '', recipients: 'both', log: [] })
const smsTestPhone = ref('')
async function loadSmsCfg() { const r = await apiCall('mall', { action: 'sms' }); if (r.ok) smsCfg.value = r }
async function saveSmsCfg() {
  const r = await apiCall('mall', { action: 'sms', sub: 'config-save', enabled: smsCfg.value.enabled ? 1 : 0, provider: smsCfg.value.provider, api_key: smsCfg.value.api_key, sender_id: smsCfg.value.sender_id, api_url: smsCfg.value.api_url, recipients: smsCfg.value.recipients || 'both' })
  if (r.ok) { smsCfg.value = { ...smsCfg.value, ...r }; window.__krToast?.(r.enabled ? '📱 SMS enabled' : '📱 SMS disabled', 'ok') }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function sendTestSms() {
  if (!smsTestPhone.value.trim()) { window.__krToast?.(t('Enter a phone number to test.'), 'err'); return }
  const r = await apiCall('mall', { action: 'sms', sub: 'send-test', phone: smsTestPhone.value.trim() })
  if (r.ok) { window.__krToast?.(`${t('📱 Test SMS')} ${r.status} (${r.ref})`, 'ok'); await loadSmsCfg() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
/* ══════════ SETTINGS TABS (redesigned) ══════════ */
const SETTINGS_TABS = [
  { id: 'profile', ic: '🏢', label: 'Profile & receipts' },
  { id: 'billing', ic: '⚡', label: 'Billing & utilities' },
  { id: 'fines', ic: '⚖️', label: 'Fines & budget' },
  { id: 'accounts', ic: '📊', label: 'Account mapping' },
  { id: 'sms', ic: '📱', label: 'SMS & alerts' },
  { id: 'governance', ic: '🏛️', label: 'Governance & license' },
  { id: 'account', ic: '👤', label: 'My account' },
]
const settingsTab = ref('profile')
const cfgDirty = ref(false)
async function loadConfig() {
  const r = await apiCall('mall', { action: 'config-get' })
  if (r.ok) config.value = { ...config.value, ...r.config }
}
async function saveConfig() {
  const r = await apiCall('mall', { action: 'config-set', ...config.value })
  if (r.ok) { cfgDirty.value = false; window.__krToast?.(t('⚙️ Settings saved'), 'ok') }
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
    { label: 'Collected', ico: '💵', value: money(k.collected), trend: `${rate}% ${t('of billed')}` },
    { label: 'Outstanding', ico: '⏳', value: money(k.outstanding), trend: `${k.unpaid_bills || 0} ${t('unpaid bills')}`, ok: !k.outstanding },
    { label: 'Expenses', ico: '📉', value: money(dash.value.expense_total), trend: t('this month') },
    { label: 'Spaces', ico: '🏪', value: `${dash.value.shops.active} / ${dash.value.shops.total}`, trend: `${dash.value.shops.total - dash.value.shops.active} ${t('inactive')}` },
    { label: 'Today collected', ico: '📅', value: money(dash.value.today ? dash.value.today.collected : 0), trend: `${dash.value.today ? dash.value.today.count : 0} ${t('receipts today')}` },
    { label: 'All dues till today', ico: '⚠️', value: money(dash.value.all_due ? dash.value.all_due.total : 0), trend: `${dash.value.all_due ? dash.value.all_due.bills : 0} ${t('unpaid bills')}`, ok: !(dash.value.all_due && dash.value.all_due.total > 0) },
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
function openAdd() { form.value = { status: 'Active', sqft: 0, service_rate: 0, opening_balance: 0, owner_id: 0, space_type: 'Shop', occupancy: 'Owner', bill_model: 'fixed', rate_sqft: 0, util_included: 0 }; modal.value = { mode: 'add', title: '➕ New Space' } }
function openEdit(s) {
  form.value = { no: s.no || '', floor: s.floor || '', sqft: s.sqft || 0, owner_name: s.owner_name || '', owner_mobile: s.owner_mobile || '', owner_nid: s.owner_nid || '', status: s.status || 'Active', service_rate: s.service_rate || 0, opening_balance: s.opening_balance || 0, owner_id: s.owner_id || 0, space_type: s.space_type || 'Shop', occupancy: s.occupancy || 'Owner', bill_model: s.bill_model || 'fixed', rate_sqft: s.rate_sqft || 0, util_included: s.util_included || 0 }
  modal.value = { mode: 'edit', title: '✏️ Edit Space', id: s.id }
}
const saving = ref(false)
/* afterAdd: when a dropdown's "＋ Add" creates a new entity, auto-select it
   in the originating form once the create modal saves. */
const afterAdd = ref(null) // { form, field, find: () => value }
function setAfterAdd(form, field, find) { afterAdd.value = { form, field, find } }
function applyAfterAdd() {
  if (!afterAdd.value) return
  try {
    const v = afterAdd.value.find()
    if (v !== undefined && v !== null && v !== '') afterAdd.value.form[afterAdd.value.field] = v
  } catch (e) {}
  afterAdd.value = null
}
async function saveShop() {
  if (!(form.value.no || '').trim() || !(form.value.owner_name || '').trim()) { window.__krToast?.(t('Space no and owner name required.'), 'err'); return }
  saving.value = true
  try {
    const payload = {
      no: form.value.no.trim(), floor: (form.value.floor || '').trim(), sqft: Number(form.value.sqft) || 0,
      owner_name: (form.value.owner_name || '').trim(), owner_mobile: (form.value.owner_mobile || '').trim(), owner_nid: (form.value.owner_nid || '').trim(),
      status: form.value.status, service_rate: Number(form.value.service_rate) || 0, opening_balance: Number(form.value.opening_balance) || 0,
      owner_id: Number(form.value.owner_id) || 0, space_type: form.value.space_type || 'Shop', occupancy: form.value.occupancy || 'Owner',
      bill_model: form.value.bill_model || 'fixed', rate_sqft: Number(form.value.rate_sqft) || 0, util_included: form.value.util_included ? 1 : 0,
    }
    const r = await apiCall('app-crud', {
      action: modal.value.mode === 'edit' ? 'update' : 'create', collection: 'shops',
      ...(modal.value.mode === 'edit' ? { id: modal.value.id } : {}), data: payload,
    })
    if (r.ok) { window.__krToast?.(modal.value.mode === 'edit' ? '✏️ Space updated' : '✅ Space created', 'ok'); modal.value = null; await data.bootstrap(); applyAfterAdd() }
    else window.__krToast?.(r.error || 'Save failed.', 'err')
  } finally { saving.value = false }
}
async function deleteShop(s) {
  if (!window.confirm(`Delete shop ${s.no}?`)) return
  const r = await apiCall('app-crud', { action: 'delete', collection: 'shops', id: s.id, data: {} })
  if (r.ok) { window.__krToast?.(t('🗑️ Space deleted'), 'ok'); await data.bootstrap() }
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
  if (!config.value.late_fees_enabled) { window.__krToast?.(t('Late fees are disabled in ⚙️ Settings → Billing rules'), 'err'); return }
  finesBusy.value = true
  try {
    const r = await apiCall('mall', { action: 'fine-calc', month: month.value })
    window.__krToast?.(r.ok ? `💸 Late fees applied to ${r.count} bills (${money(r.total_fine)} @ ${r.pct}%, ${r.grace}d grace)` : (r.error || 'Failed'), r.ok ? 'ok' : 'err')
    if (r.ok) await loadBills()
  } finally { finesBusy.value = false }
}
async function clearFines() {
  if (!window.confirm(`Remove all computed late fees for ${monthLabel(month.value)}?`)) return
  const r = await apiCall('mall', { action: 'fine-clear', month: month.value })
  window.__krToast?.(r.ok ? `🧹 Cleared ${r.cleared} fines (${money(r.amount)} removed)` : (r.error || 'Failed'), r.ok ? 'ok' : 'err')
  if (r.ok) await loadBills()
}
const isOverdue = (b) => b.due_date && b.status === 'Unpaid' && new Date(b.due_date) < new Date()

/* ── Invoices & Payments views ── */
const invList = ref([]); const invSummary = ref(null); const invStatus = ref(''); const invShop = ref(''); const invDetail = ref(null)
const payList = ref([]); const paySummary = ref(null); const payShop = ref(''); const payMethod = ref(''); const payStatus = ref('')
const payQuick = ref(null); const payQuickBills = ref([])
const quickInfo = ref(null)
async function loadQuickInfo() {
  if (!payQuick.value) { quickInfo.value = null; return }
  const r = await apiCall('mall', { action: 'space-bill-info', shop: payQuick.value, month: month.value })
  if (r.ok) quickInfo.value = r
}
const payHistory = ref(null)
async function loadPayHistory() {
  if (!payShop.value) { payHistory.value = null; return }
  const r = await apiCall('mall', { action: 'shop-payments-history', shop: payShop.value })
  if (r.ok) payHistory.value = r
}
watch(payShop, () => { loadPayHistory() })

const printTmpl = ref('a4')   /* 'a4' | 'a5' | 'half' */
const printOrient = ref('portrait') /* 'portrait' | 'landscape' */
const PRINT_TPL = {
  'a4':    { page: 'A4 portrait',  zoom: 1.0,  two: false },
  'a5p':   { page: 'A5 portrait',  zoom: 0.72, two: false },
  'a5l':   { page: 'A5 landscape', zoom: 0.62, two: false },
  'halfp': { page: 'A4 portrait',  zoom: 0.50, two: true },
  'halfl': { page: 'A4 landscape', zoom: 0.72, two: true },
}
const printPageCss = {
  'a4': '@page { size: A4 portrait; margin: 6mm }',
  'a5p': '@page { size: A5 portrait; margin: 5mm }',
  'a5l': '@page { size: A5 landscape; margin: 5mm }',
  'halfp': '@page { size: A4 portrait; margin: 6mm }',
  'halfl': '@page { size: A4 landscape; margin: 6mm }',
}
const effTmpl = computed(() => printTmpl.value === 'a4' ? 'a4' : printTmpl.value + (printOrient.value === 'landscape' ? 'l' : 'p'))

const shopOpts = computed(() => (shops.value || []).map(s => ({ value: s.id, label: `${s.no} — ${s.owner_name || ''}` })))
const payShopLabel = computed(() => { const s = (shops.value || []).find(x => x.id === payShop.value); return s ? `${s.no} — ${s.owner_name || ''}` : payShop.value })
async function loadInvoices() {
  const r = await apiCall('mall', { action: 'invoices', month: month.value, shop: invShop.value, status: invStatus.value })
  if (r.ok) { invList.value = r.invoices || []; invSummary.value = r.summary || null }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function loadPayments() {
  const r = await apiCall('mall', { action: 'payments-list', month: month.value, shop: payShop.value, method: payMethod.value, status: payStatus.value })
  if (r.ok) { payList.value = r.payments || []; paySummary.value = r.summary || null; loadDash() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function openPayQuick() {
  payQuick.value = null; payQuickBills.value = []
  if (!bills.value.length) await loadBills()
  payQuick.value = 'SH-001'
  refreshQuickBills()
  loadQuickInfo()
}
async function refreshQuickBills() {
  payQuickBills.value = []
  if (!payQuick.value) return
  try {
    const r = await apiCall('mall', { action: 'shop-unpaid-bills', shop: payQuick.value })
    if (r.ok && r.bills) { payQuickBills.value = r.bills; return }
  } catch (e) { /* fall through */ }
  payQuickBills.value = (bills.value || []).filter(b => b.shop === payQuick.value && b.status !== 'Paid')
}
function startCollectFromQuick(b) { payQuick.value = null; payQuickBills.value = []; openPay(b) }
async function voidPayment(p) {
  const reason = window.prompt(t('Reason for void…'))
  if (!reason) return
  const r = await apiCall('mall', { action: 'payment-void-request', payment_id: p.id, reason })
  if (r.ok) { window.__krToast?.(t('🔒 Void requested — pending admin approval (receipt lock)'), 'ok'); await loadPayments() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
const INV_ST = ['Unpaid', 'Partial', 'Paid']
const PAY_METHODS = ['cash', 'bank', 'bkash', 'nagad']
const PAY_ST = ['Approved', 'Pending', 'Voided']
const payModal = ref(null)
const payForm = ref({})
function openPay(b) { payForm.value = { amount: Number(b.amount) + Number(b.fine || 0), method: 'cash', method_acct: defaultPayAcct(), ref: '', payer: b.owner_name || '' }; payModal.value = b }
async function savePay() {
  if (!payModal.value || Number(payForm.value.amount) <= 0) return
  const saved = payModal.value
  const r = await apiCall('mall', { action: 'collect', bill_id: payModal.value.id, amount: Number(payForm.value.amount), method: payAcctMethod(payForm.value.method_acct), method_acct: payForm.value.method_acct || 0, ref: payForm.value.ref })
  if (r.ok) { window.__krToast?.(`${t('💵 Collected')} — ${t('receipt')} ${r.receipt}`, 'ok'); payModal.value = null; await loadBills(); await loadDash(); await loadPayments(); await loadPayHistory(); openReceipt(saved) }
  else window.__krToast?.(r.error || 'Collection failed.', 'err')
}
const recModal = ref(null)
const recData = ref(null)
async function openReceipt(b) {
  const r = await apiCall('mall', { action: 'receipt', bill_id: b.id })
  if (r.ok) { recData.value = r; recModal.value = b }
  else window.__krToast?.(r.error || 'Receipt load failed.', 'err')
}
/* spec 3.11: combined bill — all charges for a space & month on ONE print,
   with breakdown, due date + fine rule, and three signature lines */
/* ── invoice form data + HTML (shared by print / PDF / preview) ── */
async function invoiceFormData(b) {
  const r = await apiCall('mall', { action: 'combined-bill', shop: b.shop, month: b.month })
  if (!r.ok) { window.__krToast?.(r.error || 'Failed.', 'err'); return null }
  const d = r
  if (!lastReadings.value.length) await loadMeters()
  const rds = (lastReadings.value || []).filter(x => x.shop === d.shop.id && x.type === 'elec').sort((a, z) => (z.id || 0) - (a.id || 0))
  const cur = rds[0], prev = rds[1]
  const billSvc = d.bills.find(x => x.kind === 'service') || {}
  const billWat = d.bills.find(x => x.kind === 'water') || {}
  return {
    d,
    curReading: cur ? cur.reading : '',
    prevReading: prev ? prev.reading : '',
    units: cur && prev ? Math.max(0, Number(cur.reading) - Number(prev.reading)) : (cur ? (cur.units || '') : ''),
    curDate: cur ? String(cur.date || '').slice(0, 10) : '',
    prevDate: prev ? String(prev.date || '').slice(0, 10) : '',
    billSvc,
    svcAmt: Number(billSvc.amount || 0),
    elecAmt: Number((d.bills.find(x => x.kind === 'elec') || {}).amount || 0),
    misc: Number(billWat.amount || 0) + d.bills.reduce((s, x) => s + Number(x.fine || 0), 0),
    rate: Number(config.value.elec_unit_rate || 0),
    issued: (d.bills[0] && d.bills[0].created_at || '').slice(0, 10),
    due: (d.bills[0] && d.bills[0].due_date) || '',
  }
}
function invoiceFormHTML(x, zoom) {
  const { d, curReading, prevReading, units, curDate, prevDate, billSvc, svcAmt, elecAmt, misc, rate, issued, due } = x
  const bn = (n) => '৳' + Number(n || 0).toLocaleString('en-IN')
  const dline = (label, val, bold) => `<div style="flex:1;display:flex;align-items:baseline;gap:6px;min-width:0"><span style="font-size:13px;white-space:nowrap">${label}</span><span style="flex:1;border-bottom:1px dotted #000;min-width:40px"></span><span style="font-size:13px;font-weight:${bold ? 800 : 400};white-space:nowrap">${val || '…'}</span></div>`
  const frow = (num, left, right) => `<div style="display:flex;gap:28px;margin-top:9px">${num ? `<span style="font-size:13px;font-weight:800;width:16px;flex-shrink:0">${num}</span>` : ''}${left}${right}</div>`
  return `<div style="width:190mm;zoom:${zoom};background:#fff;border:2px solid #111;margin:0 auto;font-family:'Noto Serif Bengali',serif">
    <div style="text-align:center;padding:16px 10px 10px">
      <div style="font-size:22px;font-weight:800">${(config.value.mall_name || 'Mall Manager')}</div>
      <div style="font-size:12.5px;color:#555;margin-top:5px">${config.value.mall_address || ''}${config.value.mall_phone ? ((config.value.mall_address ? ' · ☎ ' : '☎ ') + config.value.mall_phone) : ''}</div>
    </div>
    <div style="background:#7f1d1d;color:#fff;text-align:center;padding:13px 10px;font-size:18px;font-weight:800;letter-spacing:.4px">বিদ্যুৎ/সার্ভিস চার্জ এবং অন্যান্য বিল</div>
    <div style="padding:22px 26px 26px">
      <div style="display:flex;justify-content:space-between;font-size:13.5px;font-weight:800;border-bottom:1px solid #000;padding-bottom:8px;margin-bottom:4px">
        <span>নং- ${billSvc.id ? 'BILL-' + billSvc.id : d.shop.no}</span>
        <span>মাস: ${monthLabel(d.month)}</span>
      </div>
      ${frow('১.', dline('ক্রেতার নাম', d.shop.owner_name || '—', true), dline('দোকান নং', d.shop.no, true))}
      ${frow('২.', dline('বর্তমান রিডিং', curReading || ''), dline('তারিখ', curDate || ''))}
      ${frow('৩.', dline('পূর্ববর্তী রিডিং', prevReading || ''), dline('তারিখ', prevDate || ''))}
      ${frow('৪.', dline('ব্যবহৃত ইউনিট', units !== '' ? units : ''), dline('দোকানের আয়তন', d.shop.sqft ? d.shop.sqft + ' বর্গফুট' : ''))}
      ${frow('৫.', dline('প্রতি ইউনিটের মূল্য', rate ? bn(rate) : ''), dline('হিসাবে মোট টাকা', elecAmt ? bn(elecAmt) : '', true))}
      ${frow('৬.', dline('প্রতি স্কয়ারফিট সার্ভিস চার্জ', svcAmt ? bn(svcAmt) : ''), dline('হিসাবে মোট সার্ভিস চার্জ', svcAmt ? bn(svcAmt) : '', true))}
      ${frow('৭.', dline('বিল ইস্যুর তারিখ', issued || ''), dline('পরিশোধের তারিখ', due || ''))}
      ${frow('৮.', dline('বকেয়া পাওনা মোট', d.due ? bn(d.due) : ''), dline('টাকা', d.due ? bn(d.due) : ''))}
      ${frow('৯.', dline('বিবিধ (পানি/ফাইন)', misc ? bn(misc) : ''), dline('ও বিবিধ টাকা', misc ? bn(misc) : ''))}
      ${frow('১০.', dline('মোট টাকা', bn(d.total), true), dline('', ''))}
      <div style="margin-top:18px;border-top:1px solid #000;padding-top:10px;font-size:12.5px;line-height:2">
        <div>১। নির্ধারিত তারিখে বিল পরিশোধ করিয়া লাইন সচল রাখিতে সহায়তা করুন।</div>
        <div>২। প্রতি মাসের ২০ (বিশ) তারিখের মধ্যে সমস্ত বিল পরিশোধ করুন। অন্যথায় বিদ্যুৎ লাইন বিচ্ছিন্ন করা হইবে।</div>
        <div>৩। ২০ (বিশ) তারিখের মধ্যে বিল পরিশোধ না করিলে সার্ভিস চার্জ সহ বিল পরিশোধ করিতে হইবে।</div>
        <div>৪। আপনার সহযোগীতা একান্ত কাম্য।</div>
      </div>
      <div style="display:flex;justify-content:space-between;margin-top:34px;text-align:center;font-size:13px">
        <div style="flex:1">____________________________<br /><b>বিল প্রস্তুতকারী</b><br /><small style="font-size:11px;color:#555">${(recData.value && recData.value.user_name) || ''}</small></div>
        <div style="flex:1">____________________________<br /><b>সাধারণ সম্পাদক</b><br /><small style="font-size:11px;color:#555">${config.value.secretary || ''}</small></div>
      </div>
    </div>
  </div>`
}
function composePrintHTML(form, tmplId) {
  const t = PRINT_TPL[tmplId]
  return t.two
    ? `<div style="display:flex;gap:6mm;justify-content:center;align-items:flex-start;width:100%">${form}${form}</div>`
    : `<div style="display:flex;justify-content:center;width:100%">${form}</div>`
}
function setPrintPage(tmplId) {
  let s = document.getElementById('printTmplCss')
  if (!s) { s = document.createElement('style'); s.id = 'printTmplCss'; document.head.appendChild(s) }
  s.textContent = printPageCss[tmplId]
}
function printAreaEl() {
  let area = document.getElementById('printArea')
  if (!area) { area = document.createElement('div'); area.id = 'printArea'; document.body.appendChild(area) }
  return area
}
async function printCombined(b) {
  const x = await invoiceFormData(b)
  if (!x) return
  const form = invoiceFormHTML(x, PRINT_TPL[effTmpl.value].zoom)
  setPrintPage(effTmpl.value)
  printAreaEl().innerHTML = composePrintHTML(form, effTmpl.value)
  window.print()
}
async function downloadInvoice(b) {
  const x = await invoiceFormData(b)
  if (!x) return
  const tpl = PRINT_TPL[effTmpl.value]
  const form = invoiceFormHTML(x, tpl.zoom)
  printAreaEl().innerHTML = composePrintHTML(form, effTmpl.value)
  window.__krToast?.(t('Rendering PDF…'), 'ok')
  const canvas = await html2canvas(printAreaEl(), { scale: 2, backgroundColor: '#ffffff', useCORS: true })
  const landscape = tpl.page.includes('landscape')
  const pw = landscape ? 297 : 210, ph = landscape ? 210 : 297
  const pdf = new jsPDF({ orientation: landscape ? 'l' : 'p', unit: 'mm', format: [pw, ph] })
  const img = canvas.toDataURL('image/jpeg', 0.95)
  const cw = canvas.width, ch = canvas.height
  const s = Math.min(pw / cw, ph / ch)
  const dw = cw * s, dh = ch * s
  pdf.addImage(img, 'JPEG', (pw - dw) / 2, (ph - dh) / 2, dw, dh, undefined, 'FAST')
  pdf.save(`invoice-${(x.d.shop.no || x.d.shop.id).replace(/[^\w-]/g, '')}-${x.d.month}.pdf`)
}
/* invoice preview (detail modal) — renders the form scaled to fit */
const invPreviewHtml = ref('')
const invPreviewZoom = ref(1)
const invPreviewWrap = ref(null)
const invShowTable = ref(false)
const invPdfBusy = ref(false)
function fitPreview() {
  const w = invPreviewWrap.value?.clientWidth || 720
  invPreviewZoom.value = Math.min(1, w / 720)
}
async function openInvDetail(iv) {
  invDetail.value = iv
  invShowTable.value = false
  invPreviewHtml.value = ''
  const x = await invoiceFormData(iv)
  if (!x) return
  invPreviewHtml.value = invoiceFormHTML(x, 1)
  await nextTick()
  fitPreview()
}
async function downloadInvDetail() {
  invPdfBusy.value = true
  try { await downloadInvoice(invDetail.value) } finally { invPdfBusy.value = false }
}
function printReceipt() { window.print() }
/* receipt logo: modern/classic use the dark variant (colored band), minimal the light one */
const recLogo = computed(() => {
  const b = recData.value?.brand
  if (!b) return ''
  return (b.invoice_template === 'minimal' ? (b.logo || b.logo_dark) : (b.logo_dark || b.logo)) || ''
})

/* ══════════ METERS ══════════ */
const meterForm = ref({ shop: '', type: 'elec', reading: 0, month: '', photo: '', photoName: '' })
const lastReadings = ref([])
const meterPhotoView = ref('')
async function loadMeters() {
  const r = await apiCall('mall', { action: 'readings', month: meterForm.value.month || month.value })
  if (r.ok) lastReadings.value = r.readings
}
function onMeterPhotoPick(e) {
  const f = e.target.files[0]; if (!f) return
  if (f.size > 1500000) { window.__krToast?.(t('Photo too large — max 1.5 MB.'), 'err'); return }
  const rd = new FileReader()
  rd.onload = () => { meterForm.value.photo = rd.result; meterForm.value.photoName = f.name }
  rd.readAsDataURL(f)
}
async function saveMeter() {
  if (!meterForm.value.shop || Number(meterForm.value.reading) <= 0) { window.__krToast?.(t('Space and reading required.'), 'err'); return }
  if (!meterForm.value.photo) { window.__krToast?.(t('📸 A meter photo is required (spec 3.3).'), 'err'); return }
  const r = await apiCall('mall', { action: 'meter', shop: meterForm.value.shop, type: meterForm.value.type, reading: Number(meterForm.value.reading), month: meterForm.value.month || month.value, photo: meterForm.value.photo })
  if (r.ok) { window.__krToast?.(`${t('✅ Reading saved')} — ${r.units} ${t('units')}` + (r.flag ? ' ⚠️ anomaly flagged' : ''), 'ok'); meterForm.value.reading = 0; meterForm.value.photo = ''; meterForm.value.photoName = ''; await loadMeters(); await loadBills() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}

/* ══════════ EXPENSES ══════════ */
const expForm = ref({ category: 'Lift Maintenance', vendor: '', amount: 0, method: 'bank', method_acct: 1010, note: '', voucher: '', voucherName: '' })
const incForm = ref({ category: 'Parking Fee', amount: 0, method: 'cash', method_acct: 1010, note: '' })
const expVoucherView = ref('')
function onExpVoucherPick(e) {
  const f = e.target.files[0]; if (!f) return
  if (f.size > 1500000) { window.__krToast?.(t('File too large — max 1.5 MB.'), 'err'); return }
  const rd = new FileReader()
  rd.onload = () => { expForm.value.voucher = rd.result; expForm.value.voucherName = f.name }
  rd.readAsDataURL(f)
}
const expenses = ref([])
const expTotal = ref(0)
const incomeList = ref([])
const incomeTotal = ref(0)
const EXP_CATEGORIES = ['Lift Maintenance', 'Escalator', 'Common Electricity (DESCO)', 'AC Servicing', 'Generator / Fuel', 'Cleaning', 'Security', 'Staff Salary', 'Repairs', 'Other']
/* configurable heads from Settings → Service billing config extend the pickers */
const expCategories = computed(() => {
  const extra = (config.value.util_heads || []).filter(h => !EXP_CATEGORIES.includes(h))
  return [...EXP_CATEGORIES, ...extra]
})
const incCategories = computed(() => (config.value.income_heads || []).length ? config.value.income_heads : ['Parking Fee', 'Community Hall Rent', 'Common Space Rent', 'Other Income'])
async function loadExpenses() {
  const r = await apiCall('mall', { action: 'expenses', month: month.value })
  if (r.ok) { expenses.value = r.expenses; expTotal.value = r.total; incomeList.value = r.income || []; incomeTotal.value = r.income_total || 0 }
}
async function saveExpense() {
  if (Number(expForm.value.amount) <= 0) { window.__krToast?.(t('Amount required.'), 'err'); return }
  const r = await apiCall('mall', { action: 'expense-add', category: expForm.value.category, vendor: expForm.value.vendor, amount: Number(expForm.value.amount), method: payAcctMethod(expForm.value.method_acct), method_acct: expForm.value.method_acct || 0, note: expForm.value.note, voucher: expForm.value.voucher })
  if (r.ok) { window.__krToast?.(t('📉 Expense recorded'), 'ok'); expForm.value = { category: 'Lift Maintenance', vendor: '', amount: 0, method: 'bank', method_acct: 1010, note: '', voucher: '', voucherName: '' }; await loadExpenses(); await loadDash() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function saveIncome() {
  if (Number(incForm.value.amount) <= 0 || !incForm.value.category) { window.__krToast?.(t('Head and amount required.'), 'err'); return }
  const r = await apiCall('mall', { action: 'income-add', cat: incForm.value.category, amount: Number(incForm.value.amount), method: payAcctMethod(incForm.value.method_acct), method_acct: incForm.value.method_acct || 0, note: incForm.value.note, month: month.value })
  if (r.ok) { window.__krToast?.(`${incForm.value.category} ৳${incForm.value.amount} ${t('recorded')}`, 'ok'); incForm.value = { category: incCategories.value[0] || 'Parking Fee', amount: 0, method: 'cash', method_acct: 1010, note: '' }; await loadExpenses(); await loadDash() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delExpense(e) {
  if (!window.confirm('Delete this expense?')) return
  const r = await apiCall('mall', { action: 'expense-del', id: e.id })
  if (r.ok) { window.__krToast?.(t('🗑️ Expense deleted'), 'ok'); await loadExpenses(); await loadDash() }
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
  if (!compForm.value.shop || !compForm.value.subject.trim()) { window.__krToast?.(t('Space and subject required.'), 'err'); return }
  const r = await apiCall('mall', { action: 'complaint-add', shop: compForm.value.shop, subject: compForm.value.subject, descr: compForm.value.descr, priority: compForm.value.priority })
  if (r.ok) { window.__krToast?.(t('🔧 Complaint logged'), 'ok'); compModal.value = null; await loadComplaints() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function setCompStatus(c, st) {
  const note = st === 'Resolved' ? window.prompt('Resolution note (optional):', '') : ''
  if (st === 'Resolved' && note === null) return
  const r = await apiCall('mall', { action: 'complaint-status', id: c.id, status: st, note: note || '' })
  if (r.ok) { window.__krToast?.(`${c.id} → ${bnd(st)}`, 'ok'); await loadComplaints() }
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
  if (!assetForm.value.name.trim()) { window.__krToast?.(t('Asset name required.'), 'err'); return }
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
const amcDays = (a) => a.days_left === null ? null : (a.days_left < 0 ? `${Math.abs(a.days_left)} ${t('d ago')}` : `${a.days_left} ${t('d left')}`)
const amcBadge = (a) => a.days_left === null ? 'b-gray' : a.days_left <= 0 ? 'b-red' : a.days_left <= 30 ? 'b-orange' : 'b-green'

/* ══════════ NOTICES (spec 3.9) ══════════ */
const notices = ref([])
const noticeModal = ref(null)
const noticeForm = ref({})
async function loadNotices() { const r = await apiCall('mall', { action: 'notices' }); if (r.ok) notices.value = r.notices }
function openNoticeAdd() { noticeForm.value = { title: '', body: '', date: new Date().toISOString().slice(0, 10), pinned: false }; noticeModal.value = true }
async function saveNotice() {
  if (!noticeForm.value.title.trim()) { window.__krToast?.(t('Title required.'), 'err'); return }
  const r = await apiCall('mall', { action: 'notice-add', title: noticeForm.value.title, body: noticeForm.value.body, date: noticeForm.value.date, pinned: noticeForm.value.pinned ? 1 : 0 })
  if (r.ok) { window.__krToast?.(t('📢 Notice posted'), 'ok'); noticeModal.value = false; await loadNotices() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delNotice(n) {
  if (!window.confirm(`Delete notice "${n.title}"?`)) return
  const r = await apiCall('mall', { action: 'notice-del', id: n.id })
  if (r.ok) { window.__krToast?.(t('🗑️ Notice deleted'), 'ok'); await loadNotices() }
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
/* spec 3.9: one-tap SMS blast — remind all defaulters or broadcast a notice */
async function sendBlast(mode, text) {
  const label = mode === 'notice' ? 'broadcast this notice to ALL owners & tenants' : 'send dues-reminder SMS to ALL defaulting spaces'
  if (!window.confirm(`📲 ${label}? (uses the recipient setting from Settings → SMS)`)) return
  const r = await apiCall('mall', { action: 'sms', sub: 'send-blast', mode, text: text || '' })
  if (r.ok) window.__krToast?.(`${t('📲 Blast done')} — ${r.sent}/${r.targets} ${t('SMS sent')}` + (r.failed ? `, ${r.failed} ${t('failed')}` : ''), r.failed ? 'err' : 'ok')
  else window.__krToast?.(r.error || 'Failed.', 'err')
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

/* ══════════ PROFILE (app-profile) ══════════ */
const profForm = ref({ name: auth.user?.name || '', old_password: '', new_password: '' })
const profSaving = ref(false)
const profMsg = ref('')
async function saveProfile() {
  profSaving.value = true; profMsg.value = ''
  const body = {}
  if (profForm.value.name.trim() && profForm.value.name.trim() !== (auth.user?.name || '')) body.name = profForm.value.name.trim()
  if (profForm.value.new_password) { body.old_password = profForm.value.old_password; body.new_password = profForm.value.new_password }
  if (!Object.keys(body).length) { profMsg.value = 'Nothing to update.'; profSaving.value = false; return }
  const r = await apiCall('app-profile', body)
  profSaving.value = false
  if (r.ok) {
    window.__krToast?.(t('👤 Profile updated'), 'ok')
    profForm.value.old_password = ''; profForm.value.new_password = ''
    profMsg.value = '✓ Saved. Password change signs you out of other devices.'
    if (auth.user) auth.user.name = profForm.value.name.trim()
  } else profMsg.value = '✗ ' + (r.error || 'Update failed.')
}

/* ══════════ STAFF & SALARIES (spec 3.4) ══════════ */
const staff = ref([])
const staffMeta = ref({ payroll_monthly: 0, active: 0 })
const staffModal = ref(null)
const staffForm = ref({})
const salModal = ref(null)
const salForm = ref({})
const salaryHistory = ref([])
const DESIGNATIONS = ['Security Guard', 'Office Staff', 'Accountant', 'Cleaner', 'Lift Operator', 'Electrician', 'Plumber', 'Supervisor', 'Manager']
async function loadStaff() {
  const [s, h] = await Promise.all([
    apiCall('mall', { action: 'staff-list' }),
    apiCall('mall', { action: 'salaries', month: month.value }),
  ])
  if (s.ok) { staff.value = s.staff; staffMeta.value = { payroll_monthly: s.payroll_monthly, active: s.active } }
  if (h.ok) salaryHistory.value = h.salaries
}
function openStaffAdd() { staffForm.value = { name: '', designation: 'Security Guard', phone: '', nid: '', join_date: '', salary: 0, status: 'Active', notes: '' }; staffModal.value = { mode: 'add', title: '➕ New staff' } }
function openStaffEdit(s) {
  staffForm.value = { name: s.name, designation: s.designation, phone: s.phone, nid: s.nid, join_date: s.join_date, salary: s.salary, status: s.status, notes: s.notes }
  staffModal.value = { mode: 'edit', title: '✏️ Edit staff', id: s.id }
}
async function saveStaff() {
  if (!staffForm.value.name.trim()) { window.__krToast?.(t('Name required.'), 'err'); return }
  const action = staffModal.value.mode === 'edit' ? 'staff-update' : 'staff-add'
  const r = await apiCall('mall', { action, ...staffForm.value, salary: Number(staffForm.value.salary) || 0, ...(staffModal.value.mode === 'edit' ? { id: staffModal.value.id } : {}) })
  if (r.ok) { window.__krToast?.(staffModal.value.mode === 'edit' ? '✏️ Staff updated' : '✅ Staff added', 'ok'); staffModal.value = null; await loadStaff() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delStaff(s) {
  if (!window.confirm(`Remove staff "${s.name}"? (salary history is kept)`)) return
  const r = await apiCall('mall', { action: 'staff-del', id: s.id })
  if (r.ok) { window.__krToast?.(t('🗑️ Staff removed'), 'ok'); await loadStaff() }
}
function openSal(s) {
  const paid = salaryHistory.value.some(h => h.staff_id === s.id)
  if (paid) { window.__krToast?.(`${t('Salary already paid for')} ${monthLabel(month.value)}`, 'err'); return }
  salForm.value = { staff_id: s.id, staff_name: s.name, amount: s.salary, method: 'cash', method_acct: defaultPayAcct(), note: '' }
  salModal.value = s
}
async function saveSalary() {
  if (!salModal.value || Number(salForm.value.amount) <= 0) return
  const r = await apiCall('mall', { action: 'salary-pay', staff_id: salForm.value.staff_id, month: month.value, amount: Number(salForm.value.amount), method: payAcctMethod(salForm.value.method_acct), method_acct: salForm.value.method_acct || 0, note: salForm.value.note })
  if (r.ok) { window.__krToast?.(`${r.staff} — ${money(r.amount)} ${t('paid')}`, 'ok'); salModal.value = null; await loadStaff(); await loadDash(); await loadLedger() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}

/* ══════════ CUSTODIAL RECON + BALANCES (spec 3.3/3.7) ══════════ */
const recon = ref(null)
const balances = ref(null)
async function loadRecon() { const r = await apiCall('mall', { action: 'recon', month: month.value }); if (r.ok) recon.value = r }
async function loadBalances() { const r = await apiCall('mall', { action: 'balances' }); if (r.ok) balances.value = r.balances }

/* ══════════ CSV EXPORT (spec 3.7) ══════════ */
function csvCell(v) { const s = String(v ?? ''); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
function exportCsv(filename, headers, rows) {
  const csv = [headers.join(','), ...rows.map(r => r.map(csvCell).join(','))].join('\n')
  const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob); a.download = filename; a.click()
  URL.revokeObjectURL(a.href)
  window.__krToast?.(`⬇ ${filename} ${t('exported')}`, 'ok')
}
function exportStaff() {
  exportCsv('staff-' + month.value + '.csv',
    ['Name', 'Designation', 'Phone', 'NID', 'Join date', 'Salary', 'Status', 'Salaries paid', 'Total paid'],
    staff.value.map(s => [s.name, s.designation, s.phone, s.nid, s.join_date, s.salary, s.status, s.salaries_paid, s.salaries_total]))
}
function exportBills() {
  exportCsv('bills-' + month.value + '.csv',
    ['Bill', 'Space', 'Floor', 'Kind', 'Amount', 'Fine', 'Due date', 'Status'],
    bills.value.map(b => [b.id, b.shop_no, b.shop_floor, b.kind, b.amount, b.fine || 0, b.due_date, b.status]))
}
function exportLedger() {
  exportCsv('ledger-' + month.value + '.csv',
    ['Space', 'Floor', 'Service paid/billed', 'Elec paid/billed', 'Water paid/billed', 'Due'],
    (ledger.value?.per_shop || []).map(s => [s.no, s.floor, `${s.sc_paid}/${s.sc_billed}`, `${s.el_paid}/${s.el_billed}`, `${s.w_paid}/${s.w_billed}`, (s.sc_billed - s.sc_paid) + (s.el_billed - s.el_paid) + (s.w_billed - s.w_paid)]))
}
function exportExpenses() {
  exportCsv('expenses-' + month.value + '.csv',
    ['Date', 'Category', 'Vendor', 'Note', 'Method', 'Amount'],
    expenses.value.map(e => [e.date, e.category, e.vendor, e.note, e.method, e.amount]))
}
function exportSalaries() {
  exportCsv('salaries-' + month.value + '.csv',
    ['Staff', 'Designation', 'Month', 'Amount', 'Method', 'Paid on'],
    salaryHistory.value.map(h => [h.staff_name, h.designation, h.month, h.amount, h.method, h.ts]))
}

/* ══════════ SYSTEM USERS & RBAC (spec 3.8) ══════════ */
const users = ref([])
const userModal = ref(null)
const userForm = ref({})
const resetModal = ref(null)
const resetForm = ref({})
const canManageUsers = computed(() => ['superadmin', 'owner'].includes(auth.user?.role || ''))
const USER_ROLES = ['owner', 'manager', 'accountant', 'collector']
const ROLE_MATRIX = [
  { cap: 'View dashboard / ledger / reports', r: [1, 1, 1, 1, 1] },
  { cap: 'Collect payments + receipts', r: [1, 1, 1, 1, 1] },
  { cap: 'Enter meter readings', r: [1, 1, 1, 1, 1] },
  { cap: 'Spaces master (add/edit/delete)', r: [1, 1, 1, 1, 0] },
  { cap: 'Generate monthly bills + late fees', r: [1, 1, 1, 1, 0] },
  { cap: 'Expenses entry', r: [1, 1, 1, 1, 0] },
  { cap: 'Complaints / assets / notices / staff', r: [1, 1, 1, 1, 0] },
  { cap: 'Mall settings (rates, bank, receipt)', r: [1, 1, 1, 1, 0] },
  { cap: 'System user management (RBAC)', r: [1, 1, 0, 0, 0] },
]
const ROLE_COLS = ['superadmin', 'owner', 'manager', 'accountant', 'collector']
async function loadUsers() { const r = await apiCall('mall', { action: 'users' }); if (r.ok) users.value = r.users }
function openUserAdd() { userForm.value = { name: '', email: '', password: '', role: 'collector' }; userModal.value = { mode: 'add', title: '➕ New system user' } }
function openUserEdit(u) {
  userForm.value = { name: u.name, email: u.email, role: u.role, active: !!u.active }
  userModal.value = { mode: 'edit', title: '✏️ Edit user', id: u.id }
}
async function saveUser() {
  const payload = userModal.value.mode === 'edit'
    ? { action: 'user-update', id: userModal.value.id, name: userForm.value.name, role: userForm.value.role, active: userForm.value.active ? 1 : 0 }
    : { action: 'user-add', name: userForm.value.name, email: userForm.value.email, password: userForm.value.password, role: userForm.value.role }
  if (userModal.value.mode === 'add' && !userForm.value.name.trim()) { window.__krToast?.(t('Name required.'), 'err'); return }
  const r = await apiCall('mall', payload)
  if (r.ok) { window.__krToast?.(userModal.value.mode === 'edit' ? '✏️ User updated' : '✅ User created', 'ok'); userModal.value = null; await loadUsers() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
function openReset(u) { resetForm.value = { id: u.id, name: u.name, password: '' }; resetModal.value = u }
async function saveReset() {
  if (resetForm.value.password.length < 8) { window.__krToast?.(t('Password must be at least 8 characters.'), 'err'); return }
  const r = await apiCall('mall', { action: 'user-resetpw', id: resetForm.value.id, password: resetForm.value.password })
  if (r.ok) { window.__krToast?.(`${t('🔑 Password reset for')} ${resetForm.value.name}`, 'ok'); resetModal.value = null; resetForm.value = { id: 0, name: '', password: '' } }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delUser(u) {
  if (!window.confirm(`Disable user "${u.name}" (${u.email})? Their audit trail stays.`)) return
  const r = await apiCall('mall', { action: 'user-del', id: u.id })
  if (r.ok) { window.__krToast?.(t('🗑️ User disabled'), 'ok'); await loadUsers() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
const userKpis = computed(() => {
  const active = users.value.filter(u => u.active).length
  const roles = {}
  users.value.forEach(u => { roles[u.role] = (roles[u.role] || 0) + 1 })
  return [
    { label: 'System users', ico: '👥', value: users.value.length },
    { label: 'Active', ico: '🟢', value: active },
    { label: 'Roles in use', ico: '🎭', value: Object.keys(roles).length, trend: Object.keys(roles).map(r => `${r}×${roles[r]}`).join(' · ') },
  ]
})

/* ══════════ BUDGET (spec 3.7) ══════════ */
const budget = ref({})
const budgetDirty = ref(false)
async function loadBudget() { const r = await apiCall('mall', { action: 'budget-get' }); if (r.ok) { budget.value = r.budget || {} } }
async function saveBudget() {
  const r = await apiCall('mall', { action: 'budget-set', budget: budget.value })
  if (r.ok) { budgetDirty.value = false; window.__krToast?.(t('🎯 Budget saved'), 'ok') }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
const budgetTotal = computed(() => Object.values(budget.value).reduce((s, v) => s + (Number(v) || 0), 0))

/* ══════════ COMMITTEE / SOMITY (spec 3.11) ══════════ */
const committee = ref(null)
const memberModal = ref(null)
const memberForm = ref({})
const meetingModal = ref(null)
const meetingForm = ref({})
const resModal = ref(null)
const resForm = ref({})
const COMMITTEE_ROLES = ['Chairman', 'Vice Chairman', 'Secretary', 'Treasurer', 'Member']
const committeeRoles = ref([])   // DYNAMIC — managed in ⚙️ Settings → Committee roles
const roleEdit = ref(false)
const roleDraft = ref('')
function addRole() { const n = roleDraft.value.trim(); if (!n || committeeRoles.value.includes(n)) return; committeeRoles.value.push(n); roleDraft.value = '' }
function delRole(r) { committeeRoles.value = committeeRoles.value.filter(x => x !== r) }
async function saveRoles() { const r = await apiCall('mall', { action: 'committee-roles-set', roles: committeeRoles.value }); if (r.ok) { window.__krToast?.(t('🏛️ Committee roles updated'), 'ok'); roleEdit.value = false } else window.__krToast?.(r.error || 'Failed.', 'err') }
const MEETING_TYPES = ['AGM', 'Executive', 'Emergency', 'Budget']
async function loadCommittee() { const r = await apiCall('mall', { action: 'committee' }); if (r.ok) { committee.value = r; committeeRoles.value = r.roles || [] } }
function openMemberAdd() { memberForm.value = { role: 'Member', name: '', shop: '', phone: '', email: '', term: '', active: 1 }; memberModal.value = { mode: 'add', title: '➕ New committee member' } }
function openMemberEdit(m) {
  memberForm.value = { role: m.role, name: m.name, shop: m.shop, phone: m.phone, email: m.email, term: m.term, active: m.active }
  memberModal.value = { mode: 'edit', title: '✏️ Edit member', id: m.id }
}
async function saveMember() {
  if (!memberForm.value.name.trim()) { window.__krToast?.(t('Name required.'), 'err'); return }
  const action = memberModal.value.mode === 'edit' ? 'committee-update' : 'committee-add'
  const r = await apiCall('mall', { action, ...memberForm.value, active: memberForm.value.active ? 1 : 0, ...(memberModal.value.mode === 'edit' ? { id: memberModal.value.id } : {}) })
  if (r.ok) { window.__krToast?.(memberModal.value.mode === 'edit' ? '✏️ Member updated' : '✅ Member added', 'ok'); memberModal.value = null; await loadCommittee() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delMember(m) {
  if (!window.confirm(`Remove ${m.name} from the committee?`)) return
  const r = await apiCall('mall', { action: 'committee-del', id: m.id })
  if (r.ok) { window.__krToast?.('🗑️ Member removed', 'ok'); await loadCommittee() }
}
function openMeetingAdd() { meetingForm.value = { date: new Date().toISOString().slice(0, 10), type: 'Executive', title: '', agenda: '', decisions: '', minutes: '' }; meetingModal.value = true }
async function saveMeeting() {
  if (!meetingForm.value.title.trim()) { window.__krToast?.(t('Title required.'), 'err'); return }
  const r = await apiCall('mall', { action: 'meeting-add', ...meetingForm.value })
  if (r.ok) { window.__krToast?.(t('✅ Meeting recorded'), 'ok'); meetingModal.value = false; await loadCommittee(); applyAfterAdd() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delMeeting(m) {
  if (!window.confirm(`Delete meeting "${m.title}"? Linked resolutions stay (unlinked).`)) return
  const r = await apiCall('mall', { action: 'meeting-del', id: m.id })
  if (r.ok) { window.__krToast?.('🗑️ Meeting deleted', 'ok'); await loadCommittee() }
}
function openResAdd(meetingId = 0) {
  const last = (committee.value?.resolutions || [])[0]?.number || ''
  const nextNum = last ? 'RES-' + (parseInt(String(last).replace(/\D/g, ''), 10) + 1) : 'RES-2026-01'
  resForm.value = { meeting_id: meetingId, number: nextNum, title: '', body: '', date: new Date().toISOString().slice(0, 10), passed: 1 }
  resModal.value = true
}
async function saveResolution() {
  if (!resForm.value.title.trim()) { window.__krToast?.(t('Title required.'), 'err'); return }
  const r = await apiCall('mall', { action: 'resolution-add', ...resForm.value, passed: resForm.value.passed ? 1 : 0 })
  if (r.ok) { window.__krToast?.(t('📜 Resolution recorded'), 'ok'); resModal.value = false; await loadCommittee() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delResolution(r2) {
  if (!window.confirm(`Delete resolution ${r2.number}?`)) return
  const r = await apiCall('mall', { action: 'resolution-del', id: r2.id })
  if (r.ok) { window.__krToast?.('🗑️ Resolution deleted', 'ok'); await loadCommittee() }
}
const memberAvatar = (m) => String(m.name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase()
const memberColor = (m) => {
  const cols = ['#2F80ED', '#27AE60', '#E67E22', '#9B59B6', '#E74C3C', '#16A085']
  let h = 0; for (const c of String(m.id || m.name)) h = (h * 31 + c.charCodeAt(0)) >>> 0
  return cols[h % cols.length]
}

/* ══════════ PROPERTY BRAND (invoice templates + logo, Al Bayan pattern) ══════════ */
const INVOICE_TEMPLATES = [
  { key: 'classic', name: 'Classic', desc: 'Centered — logo above the mall name, dashed divider.' },
  { key: 'modern', name: 'Modern', desc: 'Gradient band — logo & name left, MONEY RECEIPT right.' },
  { key: 'minimal', name: 'Minimal', desc: 'Monochrome — logo/name left, title right, hairline.' },
]
function onLogoPick(e, key) {
  const f = e.target.files?.[0]
  if (!f) return
  if (f.size > 700 * 1024) { window.__krToast?.(t('Logo too large — max 700KB.'), 'err'); return }
  const rd = new FileReader()
  rd.onload = () => { config.value[key] = rd.result; cfgDirty.value = true }
  rd.readAsDataURL(f)
}
function removeLogo(key) { config.value[key] = ''; cfgDirty.value = true }

/* ══════════ OWNERS / OWNERSHIP ══════════ */
const owners = ref([])
const ownerCounts = ref({})
const ownerModal = ref(null)
const ownerForm = ref({})
const ownerProfile = ref(null)
const OWNER_TYPES = ['Person', 'Company', 'Bank', 'NGO', 'Govt / Authority', 'Trust']
const SPACE_TYPES = ['Shop', 'Showroom', 'Store Room', 'Office Space', 'Community Hall', 'Convention Centre', 'Hospital / Clinic', 'Bank Branch', 'Supermarket', 'Mega Market', 'Food Court', 'Restaurant', 'Multiplex', 'Gaming Zone', 'Other']
const OCCUPANCIES = ['Owner', 'Rented', 'Vacant']
const ownerName = (id) => owners.value.find(o => o.id === id)?.name || '—'
async function loadOwners() { const r = await apiCall('mall', { action: 'owners' }); if (r.ok) { owners.value = r.owners; ownerCounts.value = r.counts } }
function openOwnerAdd() { ownerForm.value = { type: 'Person', name: '', contact_person: '', phone: '', email: '', nid: '', trade_license: '', address: '', notes: '' }; ownerModal.value = { mode: 'add', title: '➕ New owner' } }
function openOwnerEdit(o) {
  ownerForm.value = { ...o }
  ownerModal.value = { mode: 'edit', title: '✏️ Edit owner', id: o.id }
}
async function saveOwner() {
  if (!ownerForm.value.name.trim()) { window.__krToast?.(t('Name required.'), 'err'); return }
  const action = ownerModal.value.mode === 'edit' ? 'owner-update' : 'owner-add'
  const r = await apiCall('mall', { action, ...ownerForm.value, ...(ownerModal.value.mode === 'edit' ? { id: ownerModal.value.id } : {}) })
  if (r.ok) { window.__krToast?.(ownerModal.value.mode === 'edit' ? '✏️ Owner updated' : '✅ Owner added', 'ok'); ownerModal.value = null; await loadOwners(); if (tab.value === 'shops') loadShops(); applyAfterAdd() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delOwner(o) {
  if (!window.confirm(`Delete owner "${o.name}"?`)) return
  const r = await apiCall('mall', { action: 'owner-del', id: o.id })
  window.__krToast?.(r.ok ? '🗑️ Owner deleted' : (r.error || 'Failed.'), r.ok ? 'ok' : 'err')
  if (r.ok) await loadOwners()
}
async function openOwnerProfile(o) {
  const r = await apiCall('mall', { action: 'owner-profile', id: o.id })
  if (r.ok) ownerProfile.value = r
}

/* ══════════ TENANTS + RENTAL AGREEMENTS (rent = optional service) ══════════ */
const tenants = ref([])
const agreements = ref([])
const rentStats = ref({})
const tenantModal = ref(null)
const tenantForm = ref({})
const agrModal = ref(null)
const agrForm = ref({})
const rentModal = ref(null)
const rentForm = ref({})
async function loadTenants() { const r = await apiCall('mall', { action: 'tenants' }); if (r.ok) tenants.value = r.tenants }
async function loadAgreements() { const r = await apiCall('mall', { action: 'agreements' }); if (r.ok) { agreements.value = r.agreements; rentStats.value = { collected: r.rent_collected, outstanding: r.rent_outstanding } } }
async function exitRequest(a) {
  if (!window.confirm(`Request exit for ${a.tenant_name || 'tenant'} at ${a.shop}?`)) return
  const r = await apiCall('mall', { action: 'exit-request', id: a.id })
  if (r.ok) { window.__krToast?.(`🚪 Exit requested — pending approval${r.dues > 0 ? ' (⚠️ ৳' + r.dues + ' dues will block the NOC)' : ''}`, 'ok'); await loadAgreements() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function exitApprove(a) {
  if (!window.confirm(`Approve exit + generate NOC for ${a.tenant_name || 'tenant'} at ${a.shop}?`)) return
  const r = await apiCall('mall', { action: 'exit-approve', id: a.id })
  if (r.ok) { window.__krToast?.(`${t('✅ Exited')} — ${r.noc_no} ${t('generated')}`, 'ok'); await loadAgreements() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
function printNoc(a) {
  const m = config.value.mall_name || 'Mall Manager'
  const html = `<div style="font-family:serif;max-width:640px;margin:0 auto;padding:28px;border:2px solid #111;border-radius:6px">
    <div style="text-align:center;border-bottom:2px solid #111;padding-bottom:12px;margin-bottom:16px">
      <div style="font-size:20px;font-weight:800">NO OBJECTION CERTIFICATE (NOC)</div>
      <div style="font-size:12px;margin-top:3px">${m}${config.value.mall_address ? ' — ' + config.value.mall_address : ''}</div>
    </div>
    <div style="font-size:13px;line-height:1.7">
      <p>NOC No: <b>${a.noc_no}</b> &nbsp;&nbsp; Date: ${new Date().toLocaleDateString('en-GB')}</p>
      <p>This is to certify that <b>${a.tenant_name || '—'}</b>, occupant of <b>${a.shop}</b>${a.start_date ? ' (agreement ' + a.start_date + (a.end_date ? ' → ' + a.end_date : '') + ')' : ''}, has cleared all dues (service charge + electricity + water) payable to ${m}.</p>
      <p>Accordingly, ${m} has <b>no objection</b> to the said occupant vacating the space, and the premises stand released from all claims of the society.</p>
      <p style="margin-top:22px">Authorized signature: ________________________</p>
      <p style="font-size:11px;color:#555">This NOC is auto-generated by Mall Manager when the shop has zero outstanding dues (spec 3.6.1).</p>
    </div>
  </div>`
  let area = document.getElementById('printArea')
  if (!area) { area = document.createElement('div'); area.id = 'printArea'; document.body.appendChild(area) }
  area.innerHTML = html
  window.print()
}
function openTenantAdd() { tenantForm.value = { name: '', phone: '', email: '', nid: '', address: '', employer: '', notes: '', kind: 'Individual', father_name: '', mother_name: '', present_address: '', permanent_address: '', city: '', business_name: '', occupation: '', emergency_contact: '', photo: '', family: '', company: '', tags: '', joined_at: '' }; tenantModal.value = { mode: 'add', title: '➕ New tenant' } }
function tenLines(v) { return String(v || '').split(/\r?\n/).map(s => s.trim()).filter(Boolean) }
function openTenantEdit(t) { tenantForm.value = { ...t }; tenantModal.value = { mode: 'edit', title: '✏️ Edit tenant', id: t.id } }
async function saveTenant() {
  if (!tenantForm.value.name.trim()) { window.__krToast?.(t('Name required.'), 'err'); return }
  const action = tenantModal.value.mode === 'edit' ? 'tenant-update' : 'tenant-add'
  const r = await apiCall('mall', { action, ...tenantForm.value, ...(tenantModal.value.mode === 'edit' ? { id: tenantModal.value.id } : {}) })
  if (r.ok) { window.__krToast?.(tenantModal.value.mode === 'edit' ? '✏️ Tenant updated' : '✅ Tenant added', 'ok'); tenantModal.value = null; await loadTenants(); applyAfterAdd() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function saveTenantDrawer() {
  if (!tenantForm.value.name.trim()) { window.__krToast?.(t('Name required.'), 'err'); return }
  const r = await apiCall('mall', { action: 'tenant-update', id: tDrawer.value.tenant.id, ...tenantForm.value })
  if (r.ok) {
    window.__krToast?.('✏️ Tenant updated', 'ok')
    const id = tDrawer.value.tenant.id
    const fresh = await apiCall('mall', { action: 'tenant-detail', id })
    if (fresh.ok) { tDrawer.value = fresh; eTab.value = 'overview' }
    await loadTenants(); applyAfterAdd()
  } else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delTenant(t) {
  if (!window.confirm(`Delete tenant "${t.name}"?`)) return
  const r = await apiCall('mall', { action: 'tenant-del', id: t.id })
  if (r.ok) { window.__krToast?.(t('🗑️ Tenant deleted'), 'ok'); await loadTenants() }
}
function openAgrAdd() { agrForm.value = { shop: '', tenant_id: 0, rent: 0, start_date: new Date().toISOString().slice(0, 10), end_date: '', advance_months: 0, due_day: 5, rent_collection: 0, status: 'Active', notes: '' }; agrModal.value = true }
async function saveAgreement() {
  if (!agrForm.value.shop) { window.__krToast?.(t('Space required.'), 'err'); return }
  const r = await apiCall('mall', { action: 'agreement-add', ...agrForm.value, rent_collection: agrForm.value.rent_collection ? 1 : 0 })
  if (r.ok) { window.__krToast?.(t('✅ Agreement saved'), 'ok'); agrModal.value = false; await loadAgreements() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delAgreement(a) {
  if (!window.confirm(`Delete agreement for ${a.shop}?`)) return
  const r = await apiCall('mall', { action: 'agreement-del', id: a.id })
  if (r.ok) { window.__krToast?.(t('🗑️ Agreement deleted'), 'ok'); await loadAgreements() }
}
function openRentCollect(a) { rentForm.value = { agreement_id: a.id, month: new Date().toISOString().slice(0, 7), amount: a.rent, method: 'cash', method_acct: defaultPayAcct(), ref: '' }; rentModal.value = a }
async function saveRent() {
  const r = await apiCall('mall', { action: 'rent-collect', ...rentForm.value })
  if (r.ok) { window.__krToast?.(`${t('✅ Rent collected')} — ${r.receipt}`, 'ok'); rentModal.value = null; await loadAgreements() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}

/* ══════════ VENDORS ══════════ */
const vendors = ref([])
const vendorsTotal = ref(0)
const vendorModal = ref(null)
const vendorForm = ref({})
const vendorPayModal = ref(null)
const vendorPayForm = ref({})
const vendorPayments = ref([])
const VENDOR_CATS = ['Security', 'Lift / Escalator', 'AC / HVAC', 'Generator', 'Cleaning', 'Electrical', 'Supplies', 'Repair & Maintenance', 'Other']
async function loadVendors() { const r = await apiCall('mall', { action: 'vendors' }); if (r.ok) { vendors.value = r.vendors; vendorsTotal.value = r.total_paid } }
function openVendorAdd() { vendorForm.value = { category: 'Repair & Maintenance', name: '', contact_person: '', phone: '', email: '', address: '', notes: '' }; vendorModal.value = { mode: 'add', title: '➕ New vendor' } }
function openVendorEdit(v) { vendorForm.value = { ...v }; vendorModal.value = { mode: 'edit', title: '✏️ Edit vendor', id: v.id } }
async function saveVendor() {
  if (!vendorForm.value.name.trim()) { window.__krToast?.(t('Name required.'), 'err'); return }
  const action = vendorModal.value.mode === 'edit' ? 'vendor-update' : 'vendor-add'
  const r = await apiCall('mall', { action, ...vendorForm.value, ...(vendorModal.value.mode === 'edit' ? { id: vendorModal.value.id } : {}) })
  if (r.ok) { window.__krToast?.(vendorModal.value.mode === 'edit' ? '✏️ Vendor updated' : '✅ Vendor added', 'ok'); vendorModal.value = null; await loadVendors(); applyAfterAdd() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delVendor(v) {
  if (!window.confirm(`Delete vendor "${v.name}"?`)) return
  const r = await apiCall('mall', { action: 'vendor-del', id: v.id })
  if (r.ok) { window.__krToast?.(t('🗑️ Vendor deleted'), 'ok'); await loadVendors() }
}
async function openVendorPay(v) {
  vendorPayForm.value = { vendor_id: v.id, amount: 0, method: 'bank', method_acct: 1020, ref: '', note: '' }
  vendorPayModal.value = v
  const r = await apiCall('mall', { action: 'vendor-payments', vendor_id: v.id })
  if (r.ok) vendorPayments.value = r.payments
}
async function saveVendorPay() {
  if (!vendorPayForm.value.amount || vendorPayForm.value.amount <= 0) { window.__krToast?.(t('Amount required.'), 'err'); return }
  const r = await apiCall('mall', { action: 'vendor-payment-add', vendor_id: vendorPayForm.value.vendor_id, amount: Number(vendorPayForm.value.amount), method: payAcctMethod(vendorPayForm.value.method_acct), method_acct: vendorPayForm.value.method_acct || 0, ref: vendorPayForm.value.ref, note: vendorPayForm.value.note })
  if (r.ok) { window.__krToast?.(t('💸 Payment recorded'), 'ok'); await openVendorPay(vendorPayModal.value); await loadVendors() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}

/* ══════════ LICENSE (super admin reserved for the vendor) ══════════ */
const license = ref(null)
const licenseDirty = ref(false)
const isSuperAdmin = computed(() => ['superadmin'].includes(auth.user?.role || ''))
async function loadLicense() { const r = await apiCall('mall', { action: 'license-get' }); if (r.ok) license.value = r.license }
async function saveLicense() {
  const r = await apiCall('mall', { action: 'license-set', ...license.value })
  if (r.ok) { licenseDirty.value = false; window.__krToast?.(t('🔑 License updated'), 'ok') }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
const licenseBadge = computed(() => {
  const l = license.value
  if (!l) return ''
  if (l.plan === 'One-off') return '🟢 One-off license'
  if (l.expiry && l.expiry < new Date().toISOString().slice(0, 10)) return '🔴 Expired ' + l.expiry
  return '🟢 ' + l.plan + (l.expiry ? ' · till ' + l.expiry : '')
})

/* ══════════ SPACES: list/grid toggle + detail drawer (Units-style) ══════════ */
const spaceView = ref('table')
const drawer = ref(null)         // space-detail payload
const drawerTab = ref('overview')
const drawerLoading = ref(false)
async function openSpaceDetail(s) {
  drawerLoading.value = true
  drawerTab.value = 'overview'
  const r = await apiCall('mall', { action: 'space-detail', id: s.id })
  if (r.ok) drawer.value = r
  drawerLoading.value = false
}
function closeSpaceDetail() { drawer.value = null }
const drawerOwner = computed(() => {
  if (!drawer.value) return null
  const d = drawer.value
  return d.owner || { name: d.shop.owner_name || '—', phone: d.shop.owner_mobile || '—', nid: d.shop.owner_nid || '' }
})

/* ══════════ ENTITY DRAWERS (Units-style: Vendors / Staff / Tenants / Members / Owners) ══════════ */
const vDrawer = ref(null)
const sDrawer = ref(null)
const tDrawer = ref(null)
const mDrawer = ref(null)
const oDrawer = ref(null)
const eTab = ref('overview')     // shared sub-tab for entity drawers
async function openVendorDrawer(v) { eTab.value = 'overview'; const r = await apiCall('mall', { action: 'vendor-detail', id: v.id }); if (r.ok) vDrawer.value = r }
async function openStaffDrawer(s) { eTab.value = 'overview'; const r = await apiCall('mall', { action: 'staff-detail', id: s.id }); if (r.ok) sDrawer.value = r }
async function openTenantDrawer(t) { eTab.value = 'overview'; const r = await apiCall('mall', { action: 'tenant-detail', id: t.id }); if (r.ok) tDrawer.value = r }
function openMemberDrawer(m) { eTab.value = 'overview'; mDrawer.value = m }
async function openOwnerDrawer(o) { eTab.value = 'overview'; const r = await apiCall('mall', { action: 'owner-profile', id: o.id }); if (r.ok) oDrawer.value = r }
function closeEntityDrawers() { vDrawer.value = sDrawer.value = tDrawer.value = mDrawer.value = oDrawer.value = null }
/* ── entity deep-links: click a name anywhere → its drawer ── */
function linkShop(row) {
  if (!row) return
  const id = row.shop || row.id || row.shop_id
  if (!id) return
  openSpaceDetail(typeof row === 'object' && row.id === id ? row : { id })
}
function linkOwner(row) {
  if (!row) return
  let id = row.owner_id || row.ownerId
  if (!id && (row.shop || row.id)) { const sh = (shops.value || []).find(x => x.id === (row.shop || row.id)); id = sh && sh.owner_id }
  if (!id && row.owner_name) { const o = (owners.value || []).find(x => x.name === row.owner_name); if (o) return openOwnerDrawer(o) }
  if (!id) { window.__krToast?.(t('Owner profile not linked.'), 'err'); return }
  openOwnerDrawer({ id })
}
function linkTenant(row) {
  if (!row) return
  let id = row.tenant_id || row.tenantId
  if (id && !/^\d+$/.test(String(id))) id = 0   // legacy agreements store the tenant NAME in tenant_id
  if (!id && row.tenant_name) { const f = (tenants.value || []).find(x => x.name === row.tenant_name); if (f) return openTenantDrawer(f) }
  if (!id) { window.__krToast?.(t('Tenant profile not linked.'), 'err'); return }
  openTenantDrawer({ id })
}
function drawerStats(d, rows) {
  return d
}
/* meters: current billed amount for the selected space + month */
const spaceBillInfo = ref(null)
async function loadSpaceBillInfo() {
  if (!meterForm.value.shop) { spaceBillInfo.value = null; return }
  const r = await apiCall('mall', { action: 'space-bill-info', shop: meterForm.value.shop, month: month.value })
  if (r.ok) spaceBillInfo.value = r
}
watch(() => meterForm.value.shop, () => { loadSpaceBillInfo() })
watch(month, () => { if (meterForm.value.shop) loadSpaceBillInfo() })
const meterBilled = computed(() => {
  if (!meterForm.value.shop) return null
  const rows = bills.value.filter(b => b.shop == meterForm.value.shop && b.month === month.value)
  if (!rows.length) return null
  const byKind = { service: 0, elec: 0, water: 0 }
  rows.forEach(b => { byKind[b.kind] = (byKind[b.kind] || 0) + Number(b.amount) })
  return { total: byKind.service + byKind.elec + byKind.water, ...byKind, count: rows.length }
})
/* staff: grid/list toggle (like Spaces) */
const staffView = ref('table')

/* ══════════ BASIC ACCOUNTING: COA / Journal / Trial ══════════ */
const ACCOUNT_TYPES = ['Asset', 'Liability', 'Equity', 'Income', 'Expense']
const TYPE_ICONS = { Asset: '💵', Liability: '🏦', Equity: '⚖️', Income: '📈', Expense: '📉' }
const TYPE_PLURAL = { Asset: 'Assets', Liability: 'Liabilities', Equity: 'Equity', Income: 'Income', Expense: 'Expenses' }
const accounts = ref([])
const accountModal = ref(null)
const accountForm = ref({})
const journal = ref(null)
const jModal = ref(null)
const jForm = ref({})
const trial = ref(null)
const pnl = ref(null)
async function loadPnl() { const r = await apiCall('mall', { action: 'pnl', month: month.value }); if (r.ok) pnl.value = r }
async function loadAccounts() { const r = await apiCall('mall', { action: 'accounts' }); if (r.ok) accounts.value = r.accounts }
async function loadJournal() { const r = await apiCall('mall', { action: 'journal', from: jFrom.value, to: jTo.value }); if (r.ok) journal.value = r }
const jFrom = ref('')
const jTo = ref('')
async function loadTrial() { const r = await apiCall('mall', { action: 'trial' }); if (r.ok) trial.value = r.accounts }
function openAccountAdd() { accountForm.value = { code: '', name: '', type: 'Asset', opening: 0, active: 1, subsidiary: 0, subs_type: '', is_group: 0, parent: '', note: '' }; accountModal.value = { mode: 'add', title: '➕ New account' } }
function openAccountEdit(x) { accountForm.value = { ...x }; accountModal.value = { mode: 'edit', title: '✏️ Edit account' } }
async function saveAccount() {
  const f = accountForm.value
  if (!f.name || !f.name.trim()) { window.__krToast?.(t('Account name required.'), 'err'); return }
  const r = await apiCall('mall', { action: 'account-save', id: f.id || 0, code: f.code, name: f.name, type: f.type, opening: Number(f.opening) || 0, active: f.active ? 1 : 0, subsidiary: f.subsidiary ? 1 : 0, subs_type: f.subs_type || '', is_group: f.is_group ? 1 : 0, parent: f.parent || '', note: f.note })
  if (r.ok) { window.__krToast?.(accountModal.value.mode === 'edit' ? '✏️ Account updated' : '✅ Account added', 'ok'); accountModal.value = null; await loadAccounts() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delAccount(x) {
  if (!window.confirm(`Delete account "${x.name}"?`)) return
  const r = await apiCall('mall', { action: 'account-del', id: x.id })
  if (r.ok) { window.__krToast?.(t('🗑️ Account deleted'), 'ok'); await loadAccounts() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function openJournalAdd() {
  jForm.value = { date: month.value + '-10', ref: '', note: '', voucher: '', voucherName: '',
    lines: [{ account: 0, side: 'debit', amount: '', subValue: '', subType: '', subName: '' }, { account: 0, side: 'credit', amount: '', subValue: '', subType: '', subName: '' }] }
  jModal.value = { mode: 'add' }
}
function addJLine() { jForm.value.lines.push({ account: 0, side: 'debit', amount: '', subValue: '', subType: '', subName: '' }) }
function delJLine(i) { if (jForm.value.lines.length > 2) jForm.value.lines.splice(i, 1) }
const jDrTotal = computed(() => (jForm.value.lines || []).reduce((s, l) => s + (l.side === 'debit' ? Number(l.amount) || 0 : 0), 0))
const jCrTotal = computed(() => (jForm.value.lines || []).reduce((s, l) => s + (l.side === 'credit' ? Number(l.amount) || 0 : 0), 0))
const jBalanced = computed(() => jDrTotal.value === jCrTotal.value && jDrTotal.value > 0)
function onJoucherPick(e) {
  const f = e.target.files[0]; if (!f) return
  if (f.size > 800000) { window.__krToast?.(t('Image too large — max 800 KB.'), 'err'); return }
  const rd = new FileReader()
  rd.onload = () => { jForm.value.voucher = rd.result; jForm.value.voucherName = f.name }
  rd.readAsDataURL(f)
}
async function saveJournal() {
  if (!jBalanced.value) { window.__krToast?.(t('The voucher must balance — debit total = credit total.'), 'err'); return }
  const lines = jForm.value.lines.map(l => ({ account: l.account, debit: l.side === 'debit' ? Number(l.amount) || 0 : 0, credit: l.side === 'credit' ? Number(l.amount) || 0 : 0, subsidiary_type: l.subType || '', subsidiary_name: l.subName || '' })).filter(l => l.account && (l.debit > 0 || l.credit > 0))
  const r = await apiCall('mall', { action: 'journal-add', date: jForm.value.date, ref: jForm.value.ref, note: jForm.value.note, voucher: jForm.value.voucher, lines })
  if (r.ok) { window.__krToast?.(`${t('✅ Voucher')} ${r.ref} ${t('posted — pending approval')}`, 'ok'); jModal.value = null; await loadJournal() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function journalDecision(id, approve) {
  const r = await apiCall('mall', { action: approve ? 'journal-approve' : 'journal-reject', id })
  if (r.ok) { window.__krToast?.(approve ? '✅ Entry approved' : '⛔ Entry rejected', 'ok'); await loadJournal() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
const journalFilter = ref('All')
const voucherView = ref(null)
const journalVouchers = computed(() => {
  const rows = journal.value?.entries || []
  const groups = {}
  rows.forEach(e => { (groups[e.ref] = groups[e.ref] || []).push(e) })
  return Object.values(groups).map(g => {
    const f = g[0]
    return { ref: f.ref, date: f.date, note: f.note, created_by: f.created_by, status: f.status,
             approved_by: f.approved_by, approved_at: f.approved_at, voucher: f.voucher,
             lines: g, dr: g.reduce((s, x) => s + Number(x.debit), 0), cr: g.reduce((s, x) => s + Number(x.credit), 0) }
  }).filter(v => journalFilter.value === 'All' || v.status === journalFilter.value)
})
const jStatusBadge = { Pending: 'b-orange', Approved: 'b-green', Rejected: 'b-red' }
const myName = computed(() => (data.user && data.user.name) || (auth.user && auth.user.name) || '')

/* ══════════ PARTY LEDGER (Vendor / Owner / Tenant / Staff) ══════════ */
const partyType = ref('vendor')
const partyId = ref(0)
const partyLedger = ref(null)
const plFrom = ref('')
const plTo = ref('')
async function loadPartyLedger() {
  if (!partyId.value) return
  const r = await apiCall('mall', { action: 'party-ledger', type: partyType.value, id: partyId.value, from: plFrom.value, to: plTo.value })
  if (r.ok) partyLedger.value = r
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
function pickParty(type) {
  if (partyType.value !== type) { partyType.value = type; partyId.value = 0; partyLedger.value = null }
}
const partyOptions = computed(() => {
  if (partyType.value === 'vendor') return vendors.value.map(v => ({ value: v.id, label: v.name + ' (' + v.category + ')' }))
  if (partyType.value === 'owner') return owners.value.map(o => ({ value: o.id, label: o.name + (o.type ? ' (' + o.type + ')' : '') }))
  if (partyType.value === 'tenant') return tenants.value.map(t => ({ value: t.id, label: t.name + (t.phone ? ' · ' + t.phone : '') }))
  return staff.value.map(s => ({ value: s.id, label: s.name + ' (' + s.designation + ')' }))
})

/* ══════════ EXPORT / PRINT (accounting-wide) ══════════ */
function csvDownload(filename, headers, rows) {
  const esc = v => '"' + String(v ?? '').replace(/"/g, '""') + '"'
  const csv = '\uFEFF' + [headers, ...rows].map(r => r.map(esc).join(',')).join('\r\n')
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
  a.download = filename
  a.click()
}
function printTable(title, el) {
  let area = document.getElementById('printArea')
  if (!area) { area = document.createElement('div'); area.id = 'printArea'; document.body.appendChild(area) }
  area.innerHTML = `<h2 style="margin:0 0 4px;font-size:18px">${title}</h2>
    <p style="margin:0 0 14px;color:#555;font-size:12px">${config.mall_name || 'Mall Manager'} · ${new Date().toLocaleString()}</p>` + el.outerHTML
  window.print()
}
function exportAccountsCsv() {
  csvDownload('chart-of-accounts.csv', ['Code', 'Account', 'Type', 'Opening', 'Debits', 'Credits', 'Balance'],
    accounts.value.map(a => [a.code, a.name, a.type, a.opening, a.total_debit, a.total_credit, a.balance]))
}
function exportJournalCsv() {
  csvDownload('journal.csv', ['Ref', 'Date', 'Account', 'Type', 'Debit', 'Credit', 'Status', 'Note', 'By'],
    (journal.value?.entries || []).map(e => [e.ref, e.date, e.account_name, e.account_type, e.debit, e.credit, e.status, e.note, e.created_by]))
}
function exportTrialCsv() {
  csvDownload('trial-balance.csv', ['Code', 'Account', 'Type', 'Opening', 'Debit', 'Credit', 'Balance'],
    (trial.value || []).map(a => [a.code, a.name, a.type, a.opening, a.debit, a.credit, a.balance]))
}
function exportPnlCsv() {
  const rows = [...(pnl.value?.income || []).map(i => ['Income', i.name, i.amount]), ...(pnl.value?.expense || []).map(e => ['Expense', e.name, e.amount])]
  csvDownload('pnl-' + month.value + '.csv', ['Type', 'Account', 'Amount'], rows)
}
function exportPartyCsv() {
  csvDownload('party-ledger.csv', ['Date', 'Particulars', 'Method', 'Debit', 'Credit', 'Balance'],
    (partyLedger.value?.rows || []).map(r => [r.date, r.particulars, r.method, r.debit, r.credit, r.balance]))
}
function exportStatementCsv() {
  csvDownload('statement-' + (stData.value?.party.name || 'party').replace(/\s+/g, '-').toLowerCase() + '.csv', ['Date', 'Particulars', 'Method', 'Debit', 'Credit', 'Balance'],
    [[stData.value?.opening || 0], ...(stData.value?.rows || []).map(r => [r.date, r.particulars, r.method, r.debit, r.credit, r.balance])])
}

/* ══════════ COA IMPROVEMENTS: search / filter / per-account drawer / mapping ══════════ */
const acctQuery = ref('')
const acctActiveOnly = ref(false)
const filteredAccounts = computed(() => accounts.value.filter(a => {
  if (acctActiveOnly.value && !a.active) return false
  if (!acctQuery.value) return true
  const q = acctQuery.value.toLowerCase()
  return (a.name + ' ' + (a.code || '') + ' ' + a.type).toLowerCase().includes(q)
}))
const acctDrawer = ref(null)
async function openAccountLedger(a) {
  const r = await apiCall('mall', { action: 'account-ledger', id: a.id })
  if (r.ok) acctDrawer.value = r
}
const acctMap = ref({})
const acctDefaults = ref({})
async function loadAcctMap() {
  const r = await apiCall('mall', { action: 'acct-map' })
  if (r.ok) { acctMap.value = r.map || {}; acctDefaults.value = r.defaults || {} }
}
async function saveAcctMap() {
  const r = await apiCall('mall', { action: 'acct-map-set', map: acctMap.value })
  if (r.ok) window.__krToast?.(t('📊 Account mapping saved — new Smart Ledger posts use it'), 'ok')
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
function resetAcctMap() {
  if (!window.confirm('Reset ALL account mappings back to the built-in defaults?')) return
  acctMap.value = {}
  window.__krToast?.(t('All mappings reset to defaults — press 💾 Save mapping to persist'), 'ok')
}
/* config-driven mapping groups: expense rows follow the editable util/common
   heads (same list as the Expenses tab), income heads + Smart Ledger flows
   are mappable too */
const MAP_GROUPS = computed(() => [
  { key: 'exp:', label: '📉 Expense categories', rows: expCategories.value },
  { key: 'ven:', label: '🧰 Vendor categories', rows: ['Lift / Escalator', 'Security', 'AC / HVAC', 'Generator', 'Cleaning', 'Electrical', 'General'] },
  { key: 'met:', label: '💳 Payment methods', rows: ['cash', 'bank', 'bkash', 'nagad'] },
  { key: 'inc:', label: '💰 Income heads', rows: (config.value.income_heads || []).length ? config.value.income_heads : ['Parking Fee', 'Community Hall Rent', 'Common Space Rent', 'Advertisement / Hoarding', 'Other Income'] },
  { key: 'flow:', label: '⚡ Smart Ledger flows', rows: [
    { k: 'service', label: 'Service charge income (bills + collections)' },
    { k: 'utility', label: 'Utility income (elec / water sub-meter)' },
    { k: 'rent', label: 'Rent income (tenant collections)' },
    { k: 'fine', label: 'Late-fee / fine income' },
    { k: 'ar', label: 'Accounts receivable (space dues)' },
    { k: 'staff', label: 'Staff salary (expense)' },
  ] },
])
const acctKey = (g, r) => g.key + (typeof r === 'string' ? r : r.k)
const acctLabel = (g, r) => (typeof r === 'string' ? r : r.label)
/* ── multiple banks & mobile banking: payment-account picker (COA-driven) ── */
const PAY_ACCT_FALLBACK = [
  { id: 1010, code: '1010', name: 'Cash in Hand', method: 'cash' },
  { id: 1020, code: '1020', name: 'Bank Account', method: 'bank' },
  { id: 1021, code: '1021', name: 'Brac Bank Account', method: 'bank' },
  { id: 1022, code: '1022', name: 'EBL Account', method: 'bank' },
  { id: 1030, code: '1030', name: 'bKash', method: 'bkash' },
  { id: 1031, code: '1031', name: 'bKash (Business)', method: 'bkash' },
  { id: 1032, code: '1032', name: 'Nagad Account', method: 'nagad' },
]
const payAccounts = computed(() => {
  const accs = (accounts.value && accounts.value.length) ? accounts.value : PAY_ACCT_FALLBACK
  return accs
    .filter(a => a.code === '1010' || /^102\d*$/.test(a.code || '') || /^103\d*$/.test(a.code || ''))
    .map(a => ({ id: a.id, code: a.code, name: a.name, method: a.code === '1010' ? 'cash' : /^102/.test(a.code || '') ? 'bank' : (a.code === '1030' || a.code === '1031') ? 'bkash' : 'nagad' }))
})
const payGroups = computed(() => [
  { label: '💵 Cash', items: payAccounts.value.filter(a => a.method === 'cash') },
  { label: '🏦 Banks', items: payAccounts.value.filter(a => a.method === 'bank') },
  { label: '📱 Mobile banking', items: payAccounts.value.filter(a => a.method === 'bkash' || a.method === 'nagad') },
].filter(g => g.items.length))
function payAcctMethod(id) { const a = payAccounts.value.find(x => x.id == id); return a ? a.method : 'cash' }
function defaultPayAcct() { const c = payAccounts.value.find(a => a.method === 'cash'); return c ? c.id : 1010 }
function payAcctLabel(id) { const a = payAccounts.value.find(x => x.id == id); return a ? (a.code + ' — ' + a.name) : '' }
/* effective-account resolution for the mapping summary */
function acctNameById(id) { const a = accounts.value.find(x => x.id == id); return a ? (a.code ? a.code + ' — ' : '') + a.name : '—' }
/* flat searchable account list (code — name · path) for the searchable
   dropdowns — GROUP (heading) accounts are excluded: they cannot be posted
   to, so they never appear as a pickable account */
const accountOptions = computed(() => {
  const accs = accounts.value || []
  const byCode = {}; accs.forEach(a => { byCode[a.code] = a })
  const path = (code, seen = 0) => {
    const cur = byCode[code]; if (!cur || !cur.parent || !byCode[cur.parent] || seen > 4) return ''
    const p = byCode[cur.parent].name + ' › ' + path(cur.parent, seen + 1)
    return p
  }
  return accs.filter(a => !a.is_group).map(a => ({ value: a.id, label: (a.code ? a.code + ' — ' : '') + a.name + (a.parent && byCode[a.parent] ? ' · ' + path(a.code) : '') }))
})
/* group (heading) accounts — for the parent picker in the account modal */
const groupAccountOptions = computed(() => (accounts.value || []).filter(a => a.is_group).map(a => ({ value: a.code, label: a.code + ' — ' + a.name })))
function acctSelectOptions(g, r) {
  const k = acctKey(g, r)
  const def = acctDefaults.value[k]
  return [{ value: 0, label: '— default —' + (def ? ' (' + def + ')' : '') }, ...accountOptions.value]
}
/* ── multi-level COA tree: group totals (all accounts) + expand/collapse ── */
const collapsedGroups = ref(new Set())
function toggleGroup(code) { const s = new Set(collapsedGroups.value); s.has(code) ? s.delete(code) : s.add(code); collapsedGroups.value = s }
const groupTotals = computed(() => {
  const accs = accounts.value || []
  const children = {}; accs.forEach(a => { (children[a.parent || ''] = children[a.parent || ''] || []).push(a) })
  const tot = {}
  const sum = code => {
    let d = 0, c = 0, b = 0
    for (const ch of children[code] || []) {
      if (ch.is_group) { const s = sum(ch.code); d += s.d; c += s.c; b += s.b }
      else { d += Number(ch.total_debit) || 0; c += Number(ch.total_credit) || 0; b += Number(ch.balance) || 0 }
    }
    tot[code] = { d, c, b }
    return tot[code]
  }
  accs.filter(a => a.is_group).forEach(g => sum(g.code))
  return tot
})
const coaRows = computed(() => {
  const accs = filteredAccounts.value
  if (acctQuery.value.trim()) return accs.map(a => ({ a, depth: 0, isGroup: !!a.is_group, sub: null }))
  const byParent = {}; accs.forEach(a => { (byParent[a.parent || '__root__'] = byParent[a.parent || '__root__'] || []).push(a) })
  const rows = []
  const walk = (parent, depth) => {
    const kids = (byParent[parent] || []).slice().sort((x, y) => (x.code || '').localeCompare(y.code || ''))
    for (const k of kids) {
      const isGroup = !!k.is_group
      rows.push({ a: k, depth, isGroup, sub: isGroup ? groupTotals.value[k.code] || { d: 0, c: 0, b: 0 } : null })
      if (isGroup && !collapsedGroups.value.has(k.code)) walk(k.code, depth + 1)
    }
  }
  walk('__root__', 0)
  return rows
})
const acctDefaultLabels = computed(() => {
  const o = {}
  for (const g of MAP_GROUPS.value) for (const r of g.rows) o[acctKey(g, r)] = (typeof r === 'string' ? r : r.label)
  return o
})
/* subsidiary (party) picker for voucher lines */
const subPartyOptions = computed(() => [
  ...(vendors.value || []).map(v => ({ value: 'vendor:' + v.id, label: '🧰 ' + v.name, type: 'vendor', name: v.name })),
  ...(owners.value || []).map(o => ({ value: 'owner:' + o.id, label: '🏢 ' + o.name, type: 'owner', name: o.name })),
  ...(tenants.value || []).map(t => ({ value: 'tenant:' + t.id, label: '🧑‍🤝‍🧑 ' + t.name, type: 'tenant', name: t.name })),
  ...(staff.value || []).map(s => ({ value: 'staff:' + s.id, label: '🧑‍💼 ' + s.name, type: 'staff', name: s.name })),
])
function isSubLedgerAccount(accId) { const a = accounts.value.find(x => x.id == accId); return !!(a && a.subsidiary) }
/* party options filtered to the account's sub-ledger party type (if set) */
function subOptionsFor(line) {
  const a = accounts.value.find(x => x.id == line.account)
  const t = a?.subs_type || ''
  return t ? subPartyOptions.value.filter(o => o.type === t) : subPartyOptions.value
}
function onJLineAccountChange(line) {
  const a = accounts.value.find(x => x.id == line.account)
  const t = a?.subs_type || ''
  if (line.subValue && t) { const o = subPartyOptions.value.find(x => x.value === line.subValue); if (o && o.type !== t) { line.subValue = ''; line.subName = ''; line.subType = '' } }
}
function subsTypeLabel(t) { return { vendor: '🧰 Vendor', owner: '🏢 Owner', tenant: '🧑🤝🧑 Tenant', staff: '🧑💼 Staff' }[t] || 'Any party' }
function onSubPick(val, line) {
  const opt = subPartyOptions.value.find(o => o.value === val)
  line.subValue = val
  line.subType = opt ? opt.type : ''
  line.subName = opt ? opt.name : ''
}

/* ══════════ ANALYTICS / CASHFLOW / STATEMENTS / RECONCILE ══════════ */
const analytics = ref(null)
const analyticsMonths = ref(12)
async function loadAnalytics() { const r = await apiCall('mall', { action: 'analytics', months: analyticsMonths.value }); if (r.ok) analytics.value = r }
const cashflow = ref(null)
const cashflowMonths = ref(12)
async function loadCashflow() { const r = await apiCall('mall', { action: 'cashflow', months: cashflowMonths.value }); if (r.ok) cashflow.value = r }
/* statements: party-ledger with a date window */
const stType = ref('owner')
const stId = ref(0)
const stFrom = ref(month.value + '-01')
const stTo = ref(month.value + '-31')
const stData = ref(null)
const stOptions = computed(() => {
  if (stType.value === 'vendor') return vendors.value.map(v => ({ value: v.id, label: v.name + ' (' + v.category + ')' }))
  if (stType.value === 'owner') return owners.value.map(o => ({ value: o.id, label: o.name + (o.type ? ' (' + o.type + ')' : '') }))
  if (stType.value === 'tenant') return tenants.value.map(t => ({ value: t.id, label: t.name + (t.phone ? ' · ' + t.phone : '') }))
  return staff.value.map(s => ({ value: s.id, label: s.name + ' (' + s.designation + ')' }))
})
function pickStType(type) { if (stType.value !== type) { stType.value = type; stId.value = 0; stData.value = null } }
async function loadStatement() {
  if (!stId.value) return
  const r = await apiCall('mall', { action: 'party-ledger', type: stType.value, id: stId.value, from: stFrom.value, to: stTo.value })
  if (r.ok) stData.value = r
}
/* reconcile: utility custodial + bank/cash check */
const bankRecon = ref(null)
async function loadReconcile() {
  const [r1, r2] = await Promise.all([apiCall('mall', { action: 'recon' }), apiCall('mall', { action: 'balances' })])
  if (r1.ok && r2.ok) bankRecon.value = { recon: r1, balances: r2.balances }
}
const stmtBalances = ref({})
function stmtDiff(m) {
  const b = bankRecon.value?.balances[m]?.balance || 0
  const st = Number(stmtBalances.value[m]) || 0
  if (!stmtBalances.value[m] && stmtBalances.value[m] !== 0) return null
  return st - b
}
/* ── bank statement import & reconciliation (spec 3.7) ── */
const stmtAcctId = ref(0)
const stmtCsvText = ref('')
const stmtFileName = ref('')
const stmtPreview = ref(null)
const stmtResult = ref(null)
const stmtRows = ref([])
function onStmtFilePick(e) {
  const f = e.target.files[0]; if (!f) return
  stmtFileName.value = f.name
  const rd = new FileReader()
  rd.onload = () => { stmtCsvText.value = String(rd.result || ''); stmtPreview.value = null; stmtResult.value = null }
  rd.readAsText(f)
}
async function parseStmt() {
  if (!stmtAcctId.value) { window.__krToast?.(t('Pick a bank account first.'), 'err'); return }
  if (!stmtCsvText.value.trim()) { window.__krToast?.(t('Choose a CSV file first.'), 'err'); return }
  const r = await apiCall('mall', { action: 'bank-stmt-parse', csv: stmtCsvText.value })
  if (r.ok) stmtPreview.value = r
  else window.__krToast?.(r.error || 'Parse failed.', 'err')
}
async function importStmt() {
  if (!stmtPreview.value) return
  const r = await apiCall('mall', { action: 'bank-stmt-import', acct_id: stmtAcctId.value, rows: stmtPreview.value.rows })
  if (r.ok) { stmtResult.value = r; stmtPreview.value = null; await loadStmt(); await loadReconcile() }
  else window.__krToast?.(r.error || 'Import failed.', 'err')
}
async function loadStmt() {
  if (!stmtAcctId.value) return
  const r = await apiCall('mall', { action: 'bank-stmt-list', acct_id: stmtAcctId.value })
  if (r.ok) stmtRows.value = r.rows
}
async function delStmtBatch(batch) {
  if (!window.confirm('Delete this imported statement batch?')) return
  const r = await apiCall('mall', { action: 'bank-stmt-del', batch })
  if (r.ok) { window.__krToast?.('🗑️ Statement batch deleted', 'ok'); await loadStmt() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
function exportStmtCsv() {
  const rows = stmtRows.value.map(x => [x.stmt_date, x.descr, x.out, x.inn, x.balance, x.matched ? 'Matched ' + x.matched_ref : 'Unmatched']).map(r => r.map(c => '"' + String(c ?? '').replace(/"/g, '""') + '"').join(',')).join('\n')
  const csv = '\uFEFFDate,Description,Debit,Credit,Balance,Match\n' + rows
  const blob = new Blob([csv], { type: 'text/csv' })
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'bank-statement-' + stmtAcctId.value + '.csv'; a.click()
  URL.revokeObjectURL(a.href)
}
function printStmt() {
  const rows = stmtRows.value.map(x => `<tr style="border-bottom:1px solid #ddd"><td style="padding:6px 8px;font-size:12px">${x.stmt_date}</td><td style="padding:6px 8px;font-size:12px">${x.descr}</td><td style="padding:6px 8px;text-align:right;font-size:12px">${x.out ? '৳' + Number(x.out).toLocaleString('en-IN') : ''}</td><td style="padding:6px 8px;text-align:right;font-size:12px">${x.inn ? '৳' + Number(x.inn).toLocaleString('en-IN') : ''}</td><td style="padding:6px 8px;text-align:right;font-size:12px">${x.balance ? '৳' + Number(x.balance).toLocaleString('en-IN') : ''}</td><td style="padding:6px 8px;text-align:center;font-size:11px">${x.matched ? '✅ ' + x.matched_ref : '⚠️'}</td></tr>`).join('')
  const html = `<div style="font-family:sans-serif;padding:20px;max-width:760px;margin:0 auto">
    <div style="text-align:center;margin-bottom:14px"><b style="font-size:16px">Bank statement — ${config.mall_name || 'Mall Manager'}</b><br/><small>${payAcctLabel(stmtAcctId.value)} · ${stmtRows.length} lines</small></div>
    <table style="width:100%;border-collapse:collapse">${rows}</table></div>`
  let area = document.getElementById('printArea')
  if (!area) { area = document.createElement('div'); area.id = 'printArea'; document.body.appendChild(area) }
  area.innerHTML = html
  window.print()
}
const stmtBatches = computed(() => [...new Set(stmtRows.value.map(x => x.batch).filter(Boolean))])
const stmtTotals = computed(() => ({
  in: stmtRows.value.reduce((s, x) => s + Number(x.inn || 0), 0),
  out: stmtRows.value.reduce((s, x) => s + Number(x.out || 0), 0),
  matched: stmtRows.value.filter(x => x.matched).length,
  unmatched: stmtRows.value.filter(x => !x.matched).length,
}))
const alerts = ref(null)
async function loadAlerts() { const r = await apiCall('mall', { action: 'alerts' }); if (r.ok) alerts.value = r }
async function sendDuesAlert(shop, kind, months) {
  if (!window.confirm(`📲 Send the ${kind === 'disconnect' ? 'disconnection-risk' : 'high-dues'} SMS alert for ${shop.no} (৳${Number(shop.due).toLocaleString('en-IN')}) to the owner & tenant?`)) return
  const r = await apiCall('mall', { action: 'sms', sub: 'send-alert', shop: shop.id, kind, months: months || shop.months || 1 })
  if (r.ok) window.__krToast?.(`${t('📲 Alert SMS sent to')} ${r.sent}/${r.total} ${t('recipient(s)')}`, 'ok')
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
function billRiskMonths(b) {
  if (!b || b.status !== 'Unpaid' || !b.month) return 0
  const [y, m] = b.month.split('-').map(Number)
  const now = new Date()
  return Math.max(0, (now.getFullYear() - y) * 12 + (now.getMonth() + 1) - m)
}
function isDisconnectRisk(b) { return billRiskMonths(b) >= (config.disconnect_months || 3) }
/* spec 3.3: effective elec rate calculator (DESCO bill ÷ units) */
const effCalc = ref({ main_bill: 0, month: '', result: null })
async function calcEffectiveRate() {
  if (!effCalc.value.main_bill || Number(effCalc.value.main_bill) <= 0) { window.__krToast?.(t('Enter the DESCO main bill amount.'), 'err'); return }
  const r = await apiCall('mall', { action: 'effective-rate', main_bill: Number(effCalc.value.main_bill), month: effCalc.value.month || month.value })
  if (r.ok) effCalc.value.result = r
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
function useEffRate(rate) { config.elec_unit_rate = rate; cfgDirty.value = true; window.__krToast?.(`${t('⚡ Elec rate set to')} ৳${rate}/unit — ${t('hit Save')}`, 'ok') }
const maxOf = (arr, key) => Math.max(...arr.map(x => Number(x[key]) || 0))
const maxOfN = (arr) => Math.max(...(arr || []).map(x => Number(x.n) || 0))
const pctOf = (v, arr) => { const t = (arr || []).reduce((s, x) => s + Number(x.total), 0); return t ? Math.round(Number(v) / t * 100) : 0 }
const METHOD_ICONS = { cash: '💵', bank: '🏦', bkash: '📱', nagad: '📱' }
async function delJournal(x) {
  if (!window.confirm(`Delete journal entry #${x.id}?`)) return
  const r = await apiCall('mall', { action: 'journal-del', id: x.id })
  if (r.ok) { window.__krToast?.('🗑️ Entry deleted', 'ok'); await loadJournal() }
}
const coaStats = computed(() => {
  const s = { total: accounts.value.length, byType: {}, sumD: 0, sumC: 0, assets: 0, liab: 0, equity: 0, income: 0, exp: 0 }
  accounts.value.forEach(a => {
    s.byType[a.type] = (s.byType[a.type] || 0) + 1
    s.sumD += a.total_debit || 0; s.sumC += a.total_credit || 0
    if (a.type === 'Asset') s.assets += a.balance
    if (a.type === 'Liability') s.liab += a.balance
    if (a.type === 'Equity') s.equity += a.balance
    if (a.type === 'Income') s.income += a.balance
    if (a.type === 'Expense') s.exp += a.balance
  })
  return s
})

/* ══════════ LEDGER ══════════ */
const ledger = ref(null)
async function loadLedger() {
  const [l, r] = await Promise.all([
    apiCall('mall', { action: 'ledger', month: month.value }),
    apiCall('mall', { action: 'recon', month: month.value }),
  ])
  if (l.ok) ledger.value = l
  if (r.ok) recon.value = r
}

/* ── tab switching ── */
function switchTab(x) {
  tab.value = x
  if (x === 'dashboard') loadDash()
  if (x === 'bills') { loadBills(); loadApprovals() }
  if (x === 'invoices') loadInvoices()
  if (x === 'payments') loadPayments()
  if (x === 'ledger') loadLedger()
  if (x === 'meters') { meterForm.value.month = month.value; loadMeters(); loadBills() }
  if (x === 'expenses') { loadExpenses(); loadVendors() }
  if (x === 'complaints') loadComplaints()
  if (x === 'assets') loadAssets()
  if (x === 'coa') { loadAccounts(); loadAcctMap() }
  if (x === 'journal') { loadJournal(); loadAccounts(); loadVendors(); loadOwners(); loadTenants(); loadStaff() }
  if (x === 'trial') loadTrial()
  if (x === 'pnl') loadPnl()
  if (x === 'analytics') loadAnalytics()
  if (x === 'cashflow') loadCashflow()
  if (x === 'statements') { if (!vendors.length) loadVendors(); if (!owners.length) loadOwners(); if (!tenants.length) loadTenants(); if (!staff.length) loadStaff() }
  if (x === 'reconcile') loadReconcile()
  if (x === 'settings') { loadSmsCfg(); loadAcctMap(); loadAccounts() }
  if (x === 'pl') { if (!vendors.length) loadVendors(); if (!owners.length) loadOwners(); if (!tenants.length) loadTenants(); if (!staff.length) loadStaff(); loadPartyLedger() }
  if (x === 'notices') loadNotices()
  if (x === 'audit') loadAudit()
  if (x === 'staff') loadStaff()
  if (x === 'users') loadUsers()
  if (x === 'committee') loadCommittee()
  if (x === 'owners') loadOwners()
  if (x === 'rent') { loadTenants(); loadAgreements() }
  if (x === 'vendors') loadVendors()
  if (x === 'settings') { loadBudget(); loadLicense() }
  if (x === 'dashboard') { loadDash(); loadBalances(); loadAlerts() }
}

const offlinePending = ref(window.__mallOffline?.count?.() || 0)
const offlineNow = ref(typeof navigator !== 'undefined' && navigator.onLine === false)
function refreshOfflineState() { offlinePending.value = window.__mallOffline?.count?.() || 0; offlineNow.value = navigator.onLine === false }
async function syncNow() {
  const r = await window.__mallOffline?.sync?.()
  refreshOfflineState()
  if (r > 0) { window.__krToast?.(`${t('📡 Synced')} ${r} ${t('offline entries')}`, 'ok'); await switchTab(tab) }
  else if (r === 0) window.__krToast?.(t('📡 Nothing to sync'), 'ok')
}
onMounted(async () => { await loadConfig(); await loadDash(); loadBalances() })

/* deep-links from global search: /mall?tab=<tab> */
watch(() => route.query.tab, (t) => { if (t && TABS.some(x => x[0] === t)) switchTab(t) }, { immediate: true })
/* offline state listeners (spec 3.8.1) */
window.addEventListener('online', refreshOfflineState)
window.addEventListener('offline', refreshOfflineState)
window.addEventListener('mall-offline-queue', refreshOfflineState)
onBeforeUnmount(() => {
  window.removeEventListener('online', refreshOfflineState)
  window.removeEventListener('offline', refreshOfflineState)
  window.removeEventListener('mall-offline-queue', refreshOfflineState)
})
</script>

<template>
  <div>
    <!-- page-head teleports INTO .topbar-in → actions ride the sticky header;
         the brand/title lives in the sidebar (property identity) -->
    <Teleport to=".topbar-in">
      <div class="page-head">
        <div class="head-actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <button v-if="offlinePending > 0" @click="syncNow" title="Offline entries waiting to sync (spec 3.8.1)" style="display:flex;align-items:center;gap:6px;border:none;background:var(--primary);color:#fff;border-radius:99px;padding:6px 12px;font-size:12px;font-weight:800;cursor:pointer">📡 Sync <span style="background:#fff;color:var(--primary);border-radius:99px;padding:0 6px;font-size:11px">{{ offlinePending }}</span></button>
          <span v-if="offlineNow" style="display:flex;align-items:center;gap:6px;background:rgba(242,153,74,.15);border:1px solid #F2994A;color:#B96B1B;border-radius:99px;padding:6px 12px;font-size:12px;font-weight:800">📴 Offline — entries will sync automatically</span>
          <div style="display:flex;align-items:center;gap:6px;background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:5px 8px">
            <button @click="shiftMonth(-1)" style="border:none;background:none;cursor:pointer;font-weight:800;color:var(--text)">◀</button>
            <input type="month" v-model="month" @change="switchTab(tab)" style="padding:6px 8px;border:none;background:transparent;color:var(--text);font-weight:700;font-size:13px;outline:none;font-family:inherit" />
            <button @click="shiftMonth(1)" style="border:none;background:none;cursor:pointer;font-weight:800;color:var(--text)">▶</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Navigation moved to the sidebar (MALL MANAGEMENT sub-groups) — the
         route watch below switches tabs from /mall?tab=<id> deep links. -->
    <!-- ═══════ DASHBOARD ═══════ -->
    <template v-if="tab === 'dashboard'">
      <!-- 🚨 high-dues + disconnection-risk alerts (spec 3.9 + 3.11) -->
      <div v-if="alerts && (alerts.disconnect_risk.length || alerts.high_dues.length)" style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px">
        <div v-if="alerts.disconnect_risk.length" style="border:1px solid var(--danger);background:rgba(235,87,87,.07);border-radius:14px;padding:13px 16px">
          <div style="font-size:12.5px;font-weight:800;color:var(--danger);margin-bottom:8px">{{ t('⛔ Disconnection risk') }} — {{ alerts.disconnect_risk.length }} {{ t('space(s)') }} {{ t('overdue') }} {{ alerts.disconnect_months }}+ {{ t('months') }}</div>
          <div style="display:flex;flex-wrap:wrap;gap:8px">
            <span v-for="s in alerts.disconnect_risk" :key="s.id" style="display:flex;align-items:center;gap:8px;background:var(--card);border:1px solid var(--border);border-radius:99px;padding:5px 8px 5px 12px;font-size:12px">
              <b>{{ s.no }}</b> <span style="color:var(--text-mute)">{{ s.months }}mo · ৳{{ Number(s.due).toLocaleString('en-IN') }}</span>
              <button @click="sendDuesAlert(s, 'disconnect')" title="SMS disconnection warning to owner & tenant" style="border:none;background:var(--danger);color:#fff;border-radius:99px;padding:4px 10px;font-size:11px;font-weight:800;cursor:pointer">📲 {{ t('Alert') }}</button>
            </span>
          </div>
        </div>
        <div v-if="alerts.high_dues.length" style="border:1px solid #F2994A;background:rgba(242,153,74,.08);border-radius:14px;padding:13px 16px">
          <div style="font-size:12.5px;font-weight:800;color:#B96B1B;margin-bottom:8px">{{ t('🚨 High dues') }} — {{ alerts.high_dues.length }} {{ t('space(s)') }} {{ t('overdue') }} {{ alerts.high_months }}+ {{ t('months') }}</div>
          <div style="display:flex;flex-wrap:wrap;gap:8px">
            <span v-for="s in alerts.high_dues" :key="s.id" style="display:flex;align-items:center;gap:8px;background:var(--card);border:1px solid var(--border);border-radius:99px;padding:5px 8px 5px 12px;font-size:12px">
              <b>{{ s.no }}</b> <span style="color:var(--text-mute)">{{ s.months }}mo · ৳{{ Number(s.due).toLocaleString('en-IN') }}</span>
              <button @click="sendDuesAlert(s, 'high')" title="SMS dues reminder to owner & tenant" style="border:none;background:#F2994A;color:#fff;border-radius:99px;padding:4px 10px;font-size:11px;font-weight:800;cursor:pointer">{{ t('📲 Remind') }}</button>
            </span>
          </div>
        </div>
        <div v-if="alerts.amc_due?.length" style="border:1px solid #9B51E0;background:rgba(155,81,224,.08);border-radius:14px;padding:13px 16px">
          <div style="font-size:12.5px;font-weight:800;color:#7B2CBF;margin-bottom:8px">{{ t('🛠️ AMC / servicing contract expiring (spec 3.5)') }}</div>
          <div style="display:flex;flex-wrap:wrap;gap:8px">
            <span v-for="a in alerts.amc_due" :key="a.id" style="display:flex;align-items:center;gap:8px;background:var(--card);border:1px solid var(--border);border-radius:99px;padding:5px 8px 5px 12px;font-size:12px">
              <b>{{ a.name }}</b>
              <span :style="a.days_left < 0 ? 'color:var(--danger);font-weight:700' : 'color:var(--text-mute)'">{{ a.days_left < 0 ? t('expired') + ' ' + Math.abs(a.days_left) + t('d ago') : a.days_left + t('d left') }} · {{ a.contract_until }}</span>
            </span>
          </div>
        </div>
      </div>
      <div class="stats">
        <div v-for="k in dashKpis" :key="k.label" class="stat">
          <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ bnd(k.label) }}</div>
          <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
          <div class="s-trend">{{ k.trend }}</div>
        </div>
      </div>
      <div v-if="balances" class="stats" style="margin-top:0">
        <div v-for="(b, m) in balances" :key="m" v-show="m !== 'total'" class="stat">
          <div class="s-label"><span class="s-ico">{{ { cash: '💵', bank: '🏦', bkash: '📱', nagad: '📱' }[m] || '💰' }}</span>{{ bnd(b.label) }}</div>
          <div class="s-value" :style="b.balance < 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(b.balance) }}</div>
          <div class="s-trend">{{ t('in') }} {{ money(b.in) }} · {{ t('out') }} {{ money(b.out) }}</div>
        </div>
        <div class="stat"><div class="s-label"><span class="s-ico">💰</span>{{ t('Total balance') }}</div><div class="s-value">{{ money(balances.total) }}</div><div class="s-trend">{{ t('across all methods (spec 3.7)') }}</div></div>
      </div>
      <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:16px" class="dash-grid">
        <div class="panel" style="padding:16px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
            <h3 style="font-size:14px">{{ t('🚨 Top defaulters') }} — {{ monthLabel(month) }}</h3>
            <span v-if="dash" class="badge b-orange">{{ (dash.kpi || {}).unpaid_bills || 0 }} {{ t('unpaid') }}</span>
          </div>
          <div class="tbl-wrap" v-if="dash && dash.defaulters.length" style="max-height:300px">
            <table class="kr">
              <thead><tr><th>{{ t('Space') }}</th><th>{{ t('Owner') }}</th><th style="text-align:right">{{ t('Due') }}</th></tr></thead>
              <tbody>
                <tr v-for="d in dash.defaulters" :key="d.id">
                  <td><span class="elink" @click.stop="linkShop(d)"><b>{{ d.no }}</b></span> <small style="color:var(--text-mute)">· {{ d.floor }}</small></td>
                  <td><span class="elink" @click.stop="linkOwner(d)">{{ d.owner_name }}</span></td>
                  <td style="text-align:right;color:var(--danger);font-weight:800">{{ money(d.due) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else style="color:var(--text-mute);font-size:13px">{{ t('🎉 No outstanding bills this month.') }}</p>
        </div>
        <div style="display:flex;flex-direction:column;gap:16px">
          <div class="panel" style="padding:16px;flex:1">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
              <h3 style="font-size:14px">{{ t('📉 Expenses by category') }} — {{ monthLabel(month) }}</h3>
              <span v-if="dash && dash.budget && dash.budget.total" class="badge" :class="dash.budget.used <= dash.budget.total ? 'b-green' : 'b-red'" style="font-size:11px">budget {{ money(dash.budget.used) }} / {{ money(dash.budget.total) }}</span>
            </div>
            <div v-if="dash && dash.expense_cats.length" style="display:flex;flex-direction:column;gap:9px">
              <div v-for="c in dash.expense_cats" :key="c.cat">
                <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:3px">
                  <span style="color:var(--text)">{{ c.cat }}</span>
                  <b :style="c.budget != null && Number(c.total) > c.budget ? 'color:var(--danger)' : ''">{{ money(c.total) }}<span v-if="c.budget != null" style="color:var(--text-mute);font-weight:500"> / {{ money(c.budget) }}</span></b>
                </div>
                <div style="height:6px;border-radius:99px;background:var(--bg-alt);overflow:hidden">
                  <div :style="{ width: Math.min(100, Math.round(Number(c.total) / Math.max(c.budget ?? c.total, 1) * 100)) + '%', background: c.budget != null && Number(c.total) > c.budget ? 'var(--danger)' : 'var(--primary)', height: '100%' }"></div>
                </div>
              </div>
            </div>
            <p v-else style="color:var(--text-mute);font-size:13px">{{ t('No expenses recorded this month.') }}</p>
          </div>
          <div class="panel" style="padding:16px">
            <h3 style="font-size:14px;margin-bottom:10px">{{ t('🕘 Recent collections') }}</h3>
            <div v-if="payments.length" style="display:flex;flex-direction:column;gap:8px">
              <div v-for="p in payments.slice(0, 5)" :key="p.id" style="display:flex;justify-content:space-between;font-size:12.5px">
                <span><span class="elink" @click.stop="linkShop(p)"><b>{{ p.shop_no }}</b></span> · {{ bnd(p.method) }} <small style="color:var(--text-mute)">({{ p.receipt }})</small></span>
                <b style="color:var(--ok)">{{ money(p.amount) }}</b>
              </div>
            </div>
            <p v-else style="color:var(--text-mute);font-size:13px">{{ t('No collections yet this month.') }}</p>
          </div>
        </div>
      </div>
    </template>

    <!-- ═══════ ANALYTICS (KRTaker-style trends) ═══════ -->
    <template v-if="tab === 'analytics'">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <h3 style="font-size:14px">📈 Analytics</h3>
        <span style="margin-left:auto;display:flex;gap:6px">
          <button v-for="n in [6, 12, 24]" :key="n" @click="analyticsMonths = n; loadAnalytics()" :style="analyticsMonths === n ? 'background:var(--primary);color:#fff' : ''" class="btn-ghost" style="font-size:12px">{{ n }}M</button>
        </span>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:12px;margin-bottom:16px">
        <div class="stat"><div class="s-label"><span class="s-ico">🧾</span>{{ t('Billed (period)') }}</div><div class="s-value">{{ analytics ? money(analytics.total_billed) : money(0) }}</div><div class="s-trend">{{ analyticsMonths }} months</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💵</span>{{ t('Collected') }}</div><div class="s-value" style="color:var(--ok)">{{ analytics ? money(analytics.total_collected) : money(0) }}</div><div class="s-trend">{{ analytics ? analytics.collection_rate : 0 }}% collection rate</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🏦</span>{{ t('Open balance') }}</div><div class="s-value" style="color:var(--danger)">{{ analytics ? money(analytics.total_billed - analytics.total_collected) : money(0) }}</div><div class="s-trend">{{ t('outstanding') }}</div></div>
      </div>
      <div class="panel" style="padding:16px;margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <b style="font-size:12.5px">{{ t('Billed vs collected (per month)') }}</b>
          <span style="display:flex;gap:12px;font-size:11px;color:var(--text-mute)"><i style="width:10px;height:10px;border-radius:3px;background:#2F80ED;display:inline-block;margin-right:4px"></i>{{ t('Billed') }}<i style="width:10px;height:10px;border-radius:3px;background:#27AE60;display:inline-block;margin:0 4px 0 10px"></i>{{ t('Collected') }}</span>
        </div>
        <div v-if="analytics" style="display:flex;align-items:flex-end;gap:6px;height:170px;border-bottom:1px solid var(--border);padding-top:6px">
          <div v-for="s in analytics.months" :key="s.month" style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;height:100%;justify-content:flex-end">
            <div style="width:100%;max-width:26px;border-radius:4px 4px 0 0;background:#2F80ED;height:{{ s.billed ? Math.max(3, Math.round(s.billed / Math.max(maxOf(analytics.months, 'billed'), 1) * 140)) : 2 }}px" :title="s.month + ' billed ' + money(s.billed)"></div>
            <div style="width:100%;max-width:26px;border-radius:4px 4px 0 0;background:#27AE60;height:{{ s.collected ? Math.max(3, Math.round(s.collected / Math.max(maxOf(analytics.months, 'billed'), 1) * 140)) : 2 }}px" :title="s.month + ' collected ' + money(s.collected)"></div>
            <span style="font-size:9px;color:var(--text-mute);transform:rotate(-40deg);white-space:nowrap;margin-top:2px">{{ s.month.slice(5) + '/' + s.month.slice(2, 4) }}</span>
          </div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:14px" class="an-grid">
        <div class="panel" style="padding:16px">
          <b style="font-size:12.5px">📉 Expense mix ({{ analyticsMonths }} months)</b>
          <div style="margin-top:12px;display:flex;flex-direction:column;gap:9px">
            <div v-for="c in analytics?.expense_cats || []" :key="c.cat">
              <div style="display:flex;justify-content:space-between;font-size:11.5px;margin-bottom:3px"><span>{{ c.cat }}</span><b>{{ money(c.total) }}</b></div>
              <div style="height:7px;border-radius:99px;background:var(--bg-alt);overflow:hidden"><div style="height:100%;border-radius:99px;background:linear-gradient(90deg,#EB5757,#F2994A);width:{{ pctOf(c.total, analytics.expense_cats) }}%"></div></div>
            </div>
            <div v-if="!(analytics?.expense_cats || []).length" style="color:var(--text-mute);font-size:12px">{{ t('No expenses recorded yet.') }}</div>
          </div>
        </div>
        <div class="panel" style="padding:16px">
          <b style="font-size:12.5px">🏪 Occupancy</b>
          <div style="margin-top:12px;display:flex;flex-direction:column;gap:9px">
            <div v-for="o in analytics?.occupancy || []" :key="o.occupancy">
              <div style="display:flex;justify-content:space-between;font-size:11.5px;margin-bottom:3px"><span>{{ o.occupancy }}</span><b>{{ o.n }} space{{ o.n > 1 ? 's' : '' }}</b></div>
              <div style="height:7px;border-radius:99px;background:var(--bg-alt);overflow:hidden"><div style="height:100%;border-radius:99px;background:linear-gradient(90deg,#2F80ED,#9B51E0);width:{{ o.n / Math.max(maxOfN(analytics.occupancy), 1) * 100 }}%"></div></div>
            </div>
            <div v-if="!(analytics?.occupancy || []).length" style="color:var(--text-mute);font-size:12px">{{ t('No active spaces.') }}</div>
          </div>
        </div>
        <div class="panel" style="padding:16px">
          <b style="font-size:12.5px">🚨 Top defaulters</b>
          <div style="margin-top:10px;display:flex;flex-direction:column">
            <div v-for="d in analytics?.defaulters || []" :key="d.no" style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px dashed var(--border);font-size:12px">
              <div><b>{{ d.no }}</b> <small style="color:var(--text-mute)">· {{ d.owner_name }}</small></div>
              <b style="color:var(--danger)">{{ money(d.due) }}</b>
            </div>
            <div v-if="!(analytics?.defaulters || []).length" style="color:var(--text-mute);font-size:12px;padding:8px 0">🎉 Nothing outstanding!</div>
          </div>
        </div>
      </div>
    </template>

    <!-- ═══════ SHOPS ═══════ -->
    <template v-if="tab === 'space'">
      <div class="stats">
        <div v-for="k in shopKpis" :key="k.label" class="stat">
          <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ bnd(k.label) }}</div>
          <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
          <div class="s-trend">{{ k.trend || '' }}</div>
        </div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
          <button v-if="canManage" @click="openAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('＋ Add Space') }}</button>
          <input v-model="shopQuery" :placeholder="t('🔍 Search shop no / owner / mobile…')" style="padding:9px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);min-width:240px;font-family:inherit;font-size:13px;outline:none" />
        <select v-model="shopStatus" style="padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          <option value="">{{ t('All statuses') }}</option>
          <option v-for="(v, k) in { Active: '🟢 Active', Closed: '🔴 Closed', Vacant: '⚪ Vacant' }" :key="k" :value="k">{{ bnd(v) }}</option>
        </select>
        <span style="margin-left:auto;display:flex;gap:4px;border:1px solid var(--border);border-radius:10px;padding:3px;background:var(--bg-alt)">
          <button @click="spaceView = 'table'" :style="spaceView === 'table' ? 'background:var(--primary);color:#fff' : 'background:transparent;color:var(--text-mute)'" style="border:none;border-radius:8px;padding:6px 11px;font-size:12px;font-weight:800;cursor:pointer">{{ t('☰ List') }}</button>
          <button @click="spaceView = 'grid'" :style="spaceView === 'grid' ? 'background:var(--primary);color:#fff' : 'background:transparent;color:var(--text-mute)'" style="border:none;border-radius:8px;padding:6px 11px;font-size:12px;font-weight:800;cursor:pointer">{{ t('⊞ Grid') }}</button>
        </span>
      </div>
      <div v-if="spaceView === 'table'" class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>{{ t('Space') }}</th><th>{{ t('Floor') }}</th><th>{{ t('Sqft') }}</th><th>{{ t('Owner') }}</th><th>{{ t('Mobile') }}</th><th>{{ t('Type') }}</th><th>{{ t('Status') }}</th><th style="text-align:right">{{ t('Rate/mo') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="s in filteredShops" :key="s.id" style="cursor:pointer" @click="openSpaceDetail(s)">
                <td><b>{{ s.no }}</b><br /><small style="color:var(--text-mute)">{{ s.id }}</small></td>
                <td>{{ s.floor }}</td>
                <td>{{ (s.sqft || 0).toLocaleString('en-IN') }}</td>
                <td><span class="elink" @click.stop="linkOwner(s)">{{ s.owner_name || '—' }}</span></td>
                <td>{{ s.owner_mobile || '—' }}</td>
                <td><span class="badge b-gray" style="font-size:10px">{{ s.space_type || 'Shop' }}</span><br /><span class="badge" :class="{ Owner: 'b-green', Rented: 'b-blue', Vacant: 'b-gray' }[s.occupancy] || 'b-gray'" style="font-size:10px;margin-top:2px">{{ s.occupancy || 'Owner' }}</span></td>
                <td><span class="badge" :class="badge(s.status)">{{ bnd(s.status) }}</span></td>
                <td style="text-align:right;font-weight:800">{{ money(s.service_rate) }}</td>
                <td style="text-align:right;white-space:nowrap" @click.stop>
                  <button @click="openSpaceDetail(s)" title="Details" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px">👁</button>
                  <button v-if="canManage" @click="openEdit(s)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px">✏️</button>
                  <button v-if="canManage" @click="deleteShop(s)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px;margin-left:4px">🗑️</button>
                </td>
              </tr>
              <tr v-if="!filteredShops.length"><td colspan="8" style="text-align:center;color:var(--text-mute);padding:28px">No spaces yet — add your first space with ＋ Add Space. Opening balance covers legacy dues.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <!-- grid view -->
      <div v-else-if="spaceView === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px">
        <div v-for="s in filteredShops" :key="s.id" class="panel chip" style="padding:15px;cursor:pointer" @click="openSpaceDetail(s)">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
            <div style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#2F80ED,#27AE60);color:#fff;font-size:17px;display:flex;align-items:center;justify-content:center;flex-shrink:0">🏪</div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:800;font-size:14px">{{ s.no }}</div>
              <div style="font-size:11px;color:var(--text-mute)">{{ s.floor }} floor · {{ (s.sqft || 0).toLocaleString('en-IN') }} sqft</div>
            </div>
            <span class="badge" :class="badge(s.status)">{{ bnd(s.status) }}</span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
            <span class="badge b-gray" style="font-size:10px">{{ s.space_type || 'Shop' }}</span>
            <span class="badge" :class="{ Owner: 'b-green', Rented: 'b-blue', Vacant: 'b-gray' }[s.occupancy] || 'b-gray'" style="font-size:10px">{{ s.occupancy || 'Owner' }}</span>
          </div>
          <div style="font-size:11.5px;color:var(--text-mute);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><span class="elink" @click.stop="linkOwner(s)">👤 {{ s.owner_name || '—' }}</span><span v-if="s.owner_mobile"> · {{ s.owner_mobile }}</span></div>
          <div style="display:flex;align-items:center;margin-top:8px;border-top:1px dashed var(--border);padding-top:8px">
            <span style="font-size:11px;color:var(--text-mute)">{{ t('Rate/mo') }}</span>
            <b style="margin-left:auto;font-size:13.5px">{{ money(s.service_rate) }}</b>
          </div>
        </div>
        <div v-if="!filteredShops.length" class="panel" style="padding:24px;text-align:center;color:var(--text-mute)">{{ t('No spaces match the filters.') }}</div>
      </div>
      <p style="color:var(--text-mute);font-size:12px;margin-top:10px">💡 Rate/mo = flat service charge per space. Space owners collect their own rent — service charges &amp; utilities are billed here.</p>
    </template>

    <!-- ═══════ BILLS & COLLECTIONS ═══════ -->
    <template v-if="tab === 'bills'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🧾</span>{{ t('Billed') }}</div><div class="s-value">{{ money(billsTotals.billed) }}</div><div class="s-trend">{{ bills.length }} bills</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💵</span>{{ t('Collected') }}</div><div class="s-value" style="color:var(--ok)">{{ money(billsTotals.collected) }}</div><div class="s-trend">{{ payments.length }} receipts</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⏳</span>{{ t('Outstanding') }}</div><div class="s-value" :style="Number(billsTotals.billed) - Number(billsTotals.collected) > 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(Number(billsTotals.billed) - Number(billsTotals.collected)) }}</div><div class="s-trend">{{ t('after collections') }}</div></div>
        <div class="stat">
          <div class="s-label"><span class="s-ico">💸</span>{{ t('Late fees') }}</div>
          <div class="s-value" :style="config.late_fees_enabled ? '' : 'color:var(--text-mute);font-size:16px'">{{ config.late_fees_enabled ? money(billsTotals.fines) : 'OFF' }}</div>
          <div class="s-trend">{{ config.late_fees_enabled ? `${config.late_fee_pct}% · ${config.late_fee_grace}d grace · min ৳${config.late_fee_min} · cap ${config.late_fee_max_pct}%` : 'disabled in ⚙️ Settings' }}</div>
        </div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="generateBills" :disabled="billsBusy" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('⚙️ Generate service-charge bills') }}</button>
        <button v-if="canManage" @click="calcFines" :disabled="finesBusy || !config.late_fees_enabled" class="btn-ghost" :title="config.late_fees_enabled ? 'Apply late payment fines to overdue bills' : t('Late fees are disabled in ⚙️ Settings → Billing rules')">{{ t('💸 Compute late fees') }}</button>
        <button v-if="canManage" @click="sendBlast('remind')" class="btn-ghost" title="Send a dues-reminder SMS to every space with unpaid bills (spec 3.9)">{{ t('📲 Remind all defaulters') }}</button>
        <button v-if="canManage" @click="clearFines" class="btn-ghost" title="Remove all computed fines for this month">{{ t('🧹 Clear fines') }}</button>
        <button @click="exportBills" class="btn-ghost" title="Download this month's bills as Excel-compatible CSV">{{ t('⬇ CSV') }}</button>
        <button @click="loadApprovals(); showApprovals = !showApprovals" class="btn-ghost" style="font-size:12px">🛡️ Waivers &amp; voids <span v-if="pendingApprovals" class="badge b-red" style="font-size:10px">{{ pendingApprovals }}</span></button>
        <select v-model="billKind" @change="loadBills" style="padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          <option value="">{{ t('All kinds') }}</option>
          <option v-for="(v, k) in { service: '🧾 Service', elec: '⚡ Electricity', water: '💧 Water' }" :key="k" :value="k">{{ bnd(v) }}</option>
        </select>
        <select v-model="billStatus" @change="loadBills" style="padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          <option value="">{{ t('All statuses') }}</option><option>{{ t('Unpaid') }}</option><option>{{ t('Paid') }}</option>
        </select>
      </div>
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>{{ t('#') }}</th><th>{{ t('Space') }}</th><th>{{ t('Floor') }}</th><th>{{ t('Kind') }}</th><th style="text-align:right">{{ t('Amount') }}</th><th>{{ t('Due') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="b in bills" :key="b.id">
                <td><small style="color:var(--text-mute)">{{ b.id }}</small></td>
                <td><span class="elink" @click.stop="linkShop(b)"><b>{{ b.shop_no || b.shop }}</b></span></td>
                <td>{{ b.shop_floor || '—' }}</td>
                <td>{{ bnd({ service: '🧾 Service', elec: '⚡ Electricity', water: '💧 Water' }[b.kind] || b.kind) }}</td>
                <td style="text-align:right;font-weight:800">{{ money(b.amount) }}<span v-if="b.fine" style="color:var(--danger);font-size:11px"> +{{ money(b.fine) }} fine</span></td>
                <td style="font-size:12px;color:var(--text-mute)">{{ b.due_date }}<span v-if="isDisconnectRisk(b)" class="badge b-red" style="margin-left:6px" title="Overdue {{ billRiskMonths(b) }} months — disconnection risk (spec 3.11)">{{ t('⛔ disconnect risk') }}</span><span v-else-if="isOverdue(b)" class="badge b-red" style="margin-left:6px">{{ t('overdue') }}</span></td>
                <td><span class="badge" :class="badge(b.status)">{{ bnd(b.status) }}</span></td>
                <td style="text-align:right;white-space:nowrap">
                  <button v-if="b.status === 'Unpaid' && b.owner_mobile" @click="waRemind(b)" title="Send WhatsApp reminder to the shop owner" style="padding:6px 10px;border:1px solid #25D366;color:#1faa53;background:rgba(37,211,102,.08);border-radius:8px;cursor:pointer;font-size:12px;font-weight:700">{{ t('📲 Remind') }}</button>
                  <button v-if="b.status === 'Unpaid' && canCollect" @click="openPay(b)" style="padding:6px 12px;border:none;border-radius:8px;background:var(--primary);color:#fff;font-size:12px;font-weight:800;cursor:pointer;margin-left:4px">{{ t('💵 Collect') }}</button>
                  <button v-if="b.status === 'Unpaid' && canManage" @click="openWaiver(b)" title="Request a discount / waiver (two-level approval)" style="padding:6px 10px;border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;cursor:pointer;font-size:12px;margin-left:4px">{{ t('💸 Waiver') }}</button>
                  <button v-if="b.status === 'Paid'" @click="openReceipt(b)" title="View / print receipt" style="padding:6px 10px;border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;cursor:pointer;font-size:12px;margin-left:4px">{{ t('🖨️ Receipt') }}</button>
                  <button @click="printCombined(b)" title="Combined bill — all charges for this space & month in one print (spec 3.11)" style="padding:6px 10px;border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;cursor:pointer;font-size:12px;margin-left:4px">{{ t('📄 Combined') }}</button>
                </td>
              </tr>
              <tr v-if="!bills.length"><td colspan="8" style="text-align:center;color:var(--text-mute);padding:28px">No bills for {{ monthLabel(month) }} — press ⚙️ Generate to create monthly service-charge bills for all active spaces.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="panel" style="padding:16px;margin-top:16px" v-if="payments.length">
        <h3 style="font-size:14px;margin-bottom:10px">🕘 Collection history — {{ monthLabel(month) }}</h3>
        <div class="tbl-wrap" style="max-height:260px">
          <table class="kr">
            <thead><tr><th>{{ t('Receipt') }}</th><th>{{ t('Space') }}</th><th>{{ t('Kind') }}</th><th>{{ t('Method') }}</th><th>{{ t('Ref') }}</th><th style="text-align:right">{{ t('Amount') }}</th></tr></thead>
            <tbody>
              <tr v-for="p in payments" :key="p.id">
                <td><b>{{ p.receipt }}</b></td>
                <td>{{ p.shop_no }} · {{ p.shop_floor }}</td>
                <td>{{ { service: '🧾 Service', elec: '⚡ Electricity', water: '💧 Water' }[p.kind] || p.kind }}</td>
                <td><span class="badge b-blue">{{ bnd(p.method) }}</span></td>
                <td style="color:var(--text-mute)">{{ p.ref || '—' }}</td>
                <td style="text-align:right;font-weight:800;color:var(--ok)">{{ money(p.amount) }}</td>
                <td style="text-align:right;white-space:nowrap">
                  <button v-if="!p.voided && canManage" @click="requestVoid(p)" title="Request to void this receipt (admin approval — receipt lock)" style="padding:6px 9px;border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;cursor:pointer;font-size:11px">{{ t('🔒 Void') }}</button>
                  <span v-if="p.voided" class="badge b-red" style="font-size:10px">{{ t('voided') }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <!-- ═══ WAIVERS & VOIDS approval panel (spec 3.2 — receipt lock + two-level discount) ═══ -->
      <div v-if="showApprovals" class="panel" style="padding:16px;margin-top:16px">
        <h3 style="font-size:14px;margin-bottom:4px">🛡️ Waivers &amp; payment voids — approval trail</h3>
        <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:10px">Collection staff / accountant <b>{{ t('requests') }}</b> — the admin (president / general secretary) <b>{{ t('decides') }}</b>. Only approved waivers/voids touch the ledger; everything is logged for the committee report.</p>
        <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin:12px 0 6px">💸 Waiver requests</div>
        <div v-if="waivers.length" style="display:flex;flex-direction:column;gap:6px">
          <div v-for="w in waivers" :key="w.id" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;padding:9px 12px;border-radius:10px;background:var(--bg-alt)">
            <b>{{ w.shop }}</b> <span class="badge b-gray" style="font-size:10px">{{ w.month }}</span>
            <span style="font-weight:800;color:var(--danger)">৳{{ Number(w.amount).toLocaleString('en-IN') }}</span>
            <small style="color:var(--text-mute);flex:1;min-width:120px">{{ w.reason }}</small>
            <small style="color:var(--text-mute)">by {{ w.requested_by }}<template v-if="w.decided_by"> · {{ w.decided_by }}</template></small>
            <span class="badge" :class="badge(w.status)" style="font-size:10px">{{ bnd(w.status) }}</span>
            <template v-if="w.status === 'Pending' && canDecideApprovals">
              <button @click="decideWaiver(w, 1)" style="padding:6px 11px;border:none;border-radius:8px;background:var(--ok);color:#fff;font-size:11.5px;font-weight:800;cursor:pointer">{{ t('✅ Approve') }}</button>
              <button @click="decideWaiver(w, 0)" style="padding:6px 11px;border:none;border-radius:8px;background:var(--danger);color:#fff;font-size:11.5px;font-weight:800;cursor:pointer">{{ t('⛔ Reject') }}</button>
            </template>
          </div>
        </div>
        <p v-else style="font-size:12px;color:var(--text-mute)">{{ t('No waiver requests.') }}</p>
        <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin:14px 0 6px">🔒 Payment void requests</div>
        <div v-if="voids.length" style="display:flex;flex-direction:column;gap:6px">
          <div v-for="v in voids" :key="v.id" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;padding:9px 12px;border-radius:10px;background:var(--bg-alt)">
            <b>{{ v.receipt || v.payment_receipt }}</b> <span style="font-weight:800;color:var(--danger)">৳{{ Number(v.amount).toLocaleString('en-IN') }}</span>
            <small style="color:var(--text-mute);flex:1;min-width:120px">{{ v.reason }}</small>
            <small style="color:var(--text-mute)">by {{ v.requested_by }}<template v-if="v.decided_by"> · {{ v.decided_by }}</template></small>
            <span class="badge" :class="badge(v.status)" style="font-size:10px">{{ bnd(v.status) }}</span>
            <template v-if="v.status === 'Pending' && canDecideApprovals">
              <button @click="decideVoid(v, 1)" style="padding:6px 11px;border:none;border-radius:8px;background:var(--ok);color:#fff;font-size:11.5px;font-weight:800;cursor:pointer">{{ t('✅ Approve') }}</button>
              <button @click="decideVoid(v, 0)" style="padding:6px 11px;border:none;border-radius:8px;background:var(--danger);color:#fff;font-size:11.5px;font-weight:800;cursor:pointer">{{ t('⛔ Reject') }}</button>
            </template>
          </div>
        </div>
        <p v-else style="font-size:12px;color:var(--text-mute)">{{ t('No void requests.') }}</p>
      </div>
    </template>

    <!-- ═══════ CHART OF ACCOUNTS ═══════ -->
    <template v-if="tab === 'coa'">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px">
        <div class="stat"><div class="s-label"><span class="s-ico">📒</span>{{ t('Accounts') }}</div><div class="s-value">{{ coaStats.total }}</div><div class="s-trend">{{ t('chart of accounts') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💵</span>{{ t('Assets') }}</div><div class="s-value" style="color:var(--ok)">{{ money(coaStats.assets) }}</div><div class="s-trend">{{ coaStats.byType.Asset || 0 }} accounts</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🏦</span>{{ t('Liabilities') }}</div><div class="s-value" style="color:var(--danger)">{{ money(coaStats.liab) }}</div><div class="s-trend">{{ coaStats.byType.Liability || 0 }} accounts</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📈</span>{{ t('Income') }}</div><div class="s-value">{{ money(coaStats.income) }}</div><div class="s-trend">{{ coaStats.byType.Income || 0 }} accounts</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📉</span>{{ t('Expenses') }}</div><div class="s-value">{{ money(coaStats.exp) }}</div><div class="s-trend">{{ coaStats.byType.Expense || 0 }} accounts</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⚖️</span>{{ t('Equity') }}</div><div class="s-value">{{ money(coaStats.equity) }}</div><div class="s-trend">{{ coaStats.byType.Equity || 0 }} accounts</div></div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openAccountAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('＋ Add account') }}</button>
        <input v-model="acctQuery" :placeholder="t('🔍 Search account / code / type…')" style="padding:9px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);min-width:220px;font-family:inherit;font-size:13px;outline:none" />
        <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--text-mute);cursor:pointer">
          <input type="checkbox" v-model="acctActiveOnly" style="accent-color:var(--primary)" /> Active only
        </label>
        <span style="margin-left:auto;display:flex;gap:6px">
          <button @click="exportAccountsCsv" class="btn-ghost" style="font-size:12px">{{ t('⬇ CSV') }}</button>
          <button @click="printTable('Chart of Accounts — ' + (config.mall_name || 'Mall'), $refs.coaTbl)" class="btn-ghost" style="font-size:12px">{{ t('🖨️ Print') }}</button>
        </span>
      </div>
      <p style="font-size:11.5px;color:var(--text-mute);margin:-6px 0 12px">Click any account row to open its <b>{{ t('account ledger') }}</b> — approved entries + sub-ledgers (🧾 = control account with subsidiary tracking: AR, utility payables, AP).</p>
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr" ref="coaTbl">
            <thead><tr><th>{{ t('Code') }}</th><th>{{ t('Account') }}</th><th>{{ t('Type') }}</th><th style="text-align:right">{{ t('Opening') }}</th><th style="text-align:right">{{ t('Debits') }}</th><th style="text-align:right">{{ t('Credits') }}</th><th style="text-align:right">{{ t('Balance') }}</th><th></th></tr></thead>
            <tbody>
              <template v-for="aty in ACCOUNT_TYPES" :key="aty">
                <tr v-if="coaRows.some(r => r.a.type === aty)" style="background:var(--bg-alt)">
                  <td colspan="8" style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--text-mute)">{{ TYPE_ICONS[aty] }} {{ TYPE_PLURAL[aty] || (aty + 's') }}</td>
                </tr>
                <tr v-for="r in coaRows.filter(x => x.a.type === aty)" :key="r.a.id"
                    :style="r.isGroup ? 'background:color-mix(in srgb, var(--bg-alt) 55%, transparent);cursor:pointer' : 'cursor:pointer'"
                    @click="r.isGroup ? toggleGroup(r.a.code) : openAccountLedger(r.a)">
                  <td :style="{ fontFamily: 'monospace', fontSize: '11.5px', color: 'var(--text-mute)', paddingLeft: (12 + r.depth * 20) + 'px' }">{{ r.a.code || '—' }}</td>
                  <td v-if="r.isGroup" style="font-weight:800">
                    <span style="display:inline-block;width:16px;color:var(--text-mute)">{{ collapsedGroups.has(r.a.code) ? '▸' : '▾' }}</span>
                    {{ r.a.name }}
                    <span class="badge b-gray" style="font-size:9px;margin-left:4px">{{ t('GROUP') }}</span>
                    <br /><small style="color:var(--text-mute);font-weight:400">{{ r.a.note || '' }}</small>
                  </td>
                  <td v-else>
                    <span style="display:inline-block;width:16px"></span>
                    <b>{{ r.a.name }}</b> <span v-if="r.a.subsidiary" title="Control account with sub-ledgers" style="cursor:help;font-size:12px">🧾</span><template v-if="r.a.subsidiary"> <span class="badge b-blue" style="font-size:9.5px;padding:2px 7px">{{ subsTypeLabel(r.a.subs_type) }}</span></template><br /><small style="color:var(--text-mute)">{{ r.a.note || '' }}</small>
                  </td>
                  <td v-if="r.isGroup"><span class="badge b-gray" style="font-size:10px">{{ r.a.type }}</span></td>
                  <td v-else><span class="badge" :class="{ Asset: 'b-green', Liability: 'b-red', Equity: 'b-orange', Income: 'b-blue', Expense: 'b-gray' }[r.a.type] || 'b-gray'" style="font-size:10px">{{ r.a.type }}</span></td>
                  <td style="text-align:right;font-size:12px" :style="r.isGroup ? 'color:var(--text-mute)' : ''">{{ r.isGroup ? '—' : money(r.a.opening) }}</td>
                  <td style="text-align:right;font-size:12px" :style="r.isGroup ? 'font-weight:800' : ''">{{ r.isGroup ? money(r.sub.d) : money(r.a.total_debit) }}</td>
                  <td style="text-align:right;font-size:12px" :style="r.isGroup ? 'font-weight:800' : ''">{{ r.isGroup ? money(r.sub.c) : money(r.a.total_credit) }}</td>
                  <td style="text-align:right;font-weight:800" :style="r.isGroup ? '' : (r.a.balance < 0 ? 'color:var(--danger)' : '')">{{ r.isGroup ? money(r.sub.b) : money(r.a.balance) }}</td>
                  <td style="text-align:right;white-space:nowrap" @click.stop>
                    <button v-if="canManage && !r.isGroup" @click="openAccountEdit(r.a)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px">✏️</button>
                    <button v-if="canManage && !r.isGroup" @click="delAccount(r.a)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px;margin-left:4px">🗑️</button>
                    <button v-if="canManage && r.isGroup" @click.stop="openAccountEdit(r.a)" title="Edit group heading" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px">✏️</button>
                  </td>
                </tr>
              </template>
              <tr v-if="filteredAccounts.length" style="border-top:2px solid var(--border)">
                <td colspan="3" style="font-weight:800">TOTAL ({{ filteredAccounts.length }} accounts)</td>
                <td style="text-align:right;font-weight:800">{{ money(filteredAccounts.reduce((s, a) => s + Number(a.opening), 0)) }}</td>
                <td style="text-align:right;font-weight:800">{{ money(filteredAccounts.reduce((s, a) => s + Number(a.total_debit), 0)) }}</td>
                <td style="text-align:right;font-weight:800">{{ money(filteredAccounts.reduce((s, a) => s + Number(a.total_credit), 0)) }}</td>
                <td style="text-align:right;font-weight:800">{{ money(filteredAccounts.reduce((s, a) => s + Number(a.balance), 0)) }}</td>
                <td></td>
              </tr>
              <tr v-if="!filteredAccounts.length"><td colspan="8" style="text-align:center;color:var(--text-mute);padding:28px">No accounts match{{ acctQuery ? ' "' + acctQuery + '"' : '' }}.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ═══════ JOURNAL (double-entry vouchers + approval) ═══════ -->
    <template v-if="tab === 'journal'">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px">
        <div class="stat"><div class="s-label"><span class="s-ico">📖</span>{{ t('Entries') }}</div><div class="s-value">{{ journal ? journal.entries.length : 0 }}</div><div class="s-trend">{{ t('journal lines') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⏳</span>{{ t('Pending approval') }}</div><div class="s-value" style="color:var(--danger)">{{ journal ? journal.counts.pending : 0 }}</div><div class="s-trend">{{ t('awaiting review') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💸</span>{{ t('Total debit') }}</div><div class="s-value" style="color:var(--danger)">{{ journal ? money(journal.total_debit) : money(0) }}</div><div class="s-trend">{{ t('approved only') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💰</span>{{ t('Total credit') }}</div><div class="s-value" style="color:var(--ok)">{{ journal ? money(journal.total_credit) : money(0) }}</div><div class="s-trend">{{ t('approved only') }}</div></div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openJournalAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('＋ New voucher (double entry)') }}</button>
        <input type="date" v-model="jFrom" @change="loadJournal" style="padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12px" />
        <span style="color:var(--text-mute);font-size:12px">→</span>
        <input type="date" v-model="jTo" @change="loadJournal" style="padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12px" />
        <span style="display:flex;gap:6px;margin-left:auto">
          <button @click="exportJournalCsv" class="btn-ghost" style="font-size:12px">{{ t('⬇ CSV') }}</button>
          <button @click="printTable('Journal — ' + (config.mall_name || 'Mall'), $refs.journalArea)" class="btn-ghost" style="font-size:12px">{{ t('🖨️ Print') }}</button>
        </span>
        <div style="display:flex;gap:4px;border:1px solid var(--border);border-radius:10px;padding:3px;background:var(--bg-alt)">
          <button v-for="f in ['All', 'Pending', 'Approved', 'Rejected']" :key="f" @click="journalFilter = f"
            :style="journalFilter === f ? 'background:var(--primary);color:#fff' : 'background:transparent;color:var(--text-mute)'"
            style="border:none;border-radius:8px;padding:6px 11px;font-size:12px;font-weight:800;cursor:pointer">{{ f }}<template v-if="f === 'Pending' && journal && journal.counts.pending"> ({{ journal.counts.pending }})</template></button>
        </div>
      </div>
      <p style="font-size:12px;color:var(--text-mute);margin-bottom:14px">⚡ Every voucher is <b>{{ t('double-entry') }}</b> (debit total = credit total) and goes through <b>{{ t('approval') }}</b> — pending entries do not appear in the COA, trial balance or P&amp;L until approved. Smart-Ledger posts are auto-approved.</p>
      <div style="display:flex;flex-direction:column;gap:12px" ref="journalArea">
        <div v-for="v in journalVouchers" :key="v.ref" class="panel" style="padding:0;overflow:hidden">
          <div style="display:flex;align-items:center;gap:10px;padding:11px 16px;border-bottom:1px solid var(--border);flex-wrap:wrap;background:var(--bg-alt)">
            <b style="font-family:monospace;font-size:12.5px">{{ v.ref }}</b>
            <span style="font-size:12px;color:var(--text-mute)">{{ v.date }}</span>
            <span class="badge" :class="jStatusBadge[v.status] || 'b-gray'" style="font-size:10px">{{ bnd(v.status) }}</span>
            <span v-if="v.voucher" @click="voucherView = v.voucher" title="View attached receipt / voucher" style="cursor:pointer;font-size:13px">📎</span>
            <span style="margin-left:auto;font-size:11.5px;color:var(--text-mute)">by {{ v.created_by }}<template v-if="v.approved_by"> · {{ v.status === 'Approved' ? '✅' : '⛔' }} {{ v.approved_by }} <small>{{ (v.approved_at || '').slice(0, 10) }}</small></template></span>
            <div style="display:flex;gap:6px">
              <template v-if="v.status === 'Pending'">
                <button v-if="canManage && myName && myName !== v.created_by" @click="journalDecision(v.lines[0].id, true)" title="Approve" style="padding:5px 12px;border:none;border-radius:8px;background:var(--ok);color:#fff;font-size:11.5px;font-weight:800;cursor:pointer">{{ t('✅ Approve') }}</button>
                <button v-if="canManage && myName && myName !== v.created_by" @click="journalDecision(v.lines[0].id, false)" title="Reject" style="padding:5px 12px;border:none;border-radius:8px;background:var(--danger);color:#fff;font-size:11.5px;font-weight:800;cursor:pointer">{{ t('⛔ Reject') }}</button>
                <span v-if="myName === v.created_by" style="font-size:11px;color:var(--text-mute)">🔒 awaiting review by another manager</span>
              </template>
              <button v-if="canManage && v.status !== 'Approved'" @click="delJournal(v.lines[0])" title="Delete" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:4px 8px;cursor:pointer;font-size:11px">🗑️</button>
            </div>
          </div>
          <div class="tbl-wrap" style="max-height:none">
            <table class="kr">
              <thead><tr><th>{{ t('Account') }}</th><th>{{ t('Type') }}</th><th style="text-align:right">{{ t('Debit') }}</th><th style="text-align:right">{{ t('Credit') }}</th></tr></thead>
              <tbody>
                <tr v-for="e in v.lines" :key="e.id">
                  <td><b>{{ e.account_name || '—' }}</b> <span v-if="e.subsidiary" style="font-size:11px;color:var(--text-mute)">🧾 {{ e.subsidiary }}</span></td>
                  <td><span class="badge" :class="{ Asset: 'b-green', Liability: 'b-red', Equity: 'b-orange', Income: 'b-blue', Expense: 'b-gray' }[e.account_type] || 'b-gray'" style="font-size:9px">{{ e.account_type }}</span></td>
                  <td style="text-align:right;font-weight:800;color:var(--danger)">{{ e.debit ? money(e.debit) : '' }}</td>
                  <td style="text-align:right;font-weight:800;color:var(--ok)">{{ e.credit ? money(e.credit) : '' }}</td>
                </tr>
                <tr v-if="v.note" style="background:var(--bg-alt)"><td colspan="4" style="font-size:12px;color:var(--text-mute)">📝 {{ v.note }}</td></tr>
              </tbody>
              <tfoot v-if="v.lines.length > 1" style="border-top:2px solid var(--border)">
                <tr><td colspan="2" style="font-weight:800">{{ t('TOTAL') }}</td>
                  <td style="text-align:right;font-weight:800;color:var(--danger)">{{ money(v.dr) }}</td>
                  <td style="text-align:right;font-weight:800;color:var(--ok)">{{ money(v.cr) }}</td></tr>
              </tfoot>
            </table>
          </div>
        </div>
        <div v-if="!journalVouchers.length" class="panel" style="padding:28px;text-align:center;color:var(--text-mute)">No journal entries{{ journalFilter !== 'All' ? ' with status ' + journalFilter : '' }} yet.</div>
      </div>
      <!-- voucher lightbox -->
      <div v-if="voucherView" style="position:fixed;inset:0;background:rgba(10,20,40,.85);z-index:260;display:flex;align-items:center;justify-content:center;padding:24px" @click="voucherView = null">
        <img :src="voucherView" alt="Receipt / voucher" style="max-width:100%;max-height:92vh;border-radius:12px;box-shadow:0 10px 60px rgba(0,0,0,.5)" />
        <button @click="voucherView = null" style="position:absolute;top:16px;right:16px;width:36px;height:36px;border-radius:50%;border:none;background:rgba(255,255,255,.2);color:#fff;font-size:16px;font-weight:800;cursor:pointer">✕</button>
      </div>
    </template>

    <!-- ═══════ TRIAL BALANCE ═══════ -->
    <template v-if="tab === 'trial'">
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-bottom:10px">
        <button @click="exportTrialCsv" class="btn-ghost" style="font-size:12px">{{ t('⬇ CSV') }}</button>
        <button @click="printTable('Trial Balance — ' + (config.mall_name || 'Mall'), $refs.trialTbl)" class="btn-ghost" style="font-size:12px">{{ t('🖨️ Print') }}</button>
      </div>
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr" ref="trialTbl">
            <thead><tr><th>{{ t('Code') }}</th><th>{{ t('Account') }}</th><th>{{ t('Type') }}</th><th style="text-align:right">{{ t('Opening') }}</th><th style="text-align:right">{{ t('Debit') }}</th><th style="text-align:right">{{ t('Credit') }}</th><th style="text-align:right">{{ t('Balance') }}</th></tr></thead>
            <tbody>
              <template v-for="t in ACCOUNT_TYPES" :key="t">
                <tr v-if="trial && trial.some(a => a.type === t)" style="background:var(--bg-alt)">
                  <td colspan="7" style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--text-mute)">{{ TYPE_ICONS[t] }} {{ TYPE_PLURAL[t] || (t + 's') }}</td>
                </tr>
                <tr v-for="a in (trial || []).filter(x => x.type === t)" :key="a.id">
                  <td style="font-family:monospace;font-size:11.5px;color:var(--text-mute)">{{ a.code || '—' }}</td>
                  <td><b>{{ a.name }}</b></td>
                  <td><span class="badge" :class="{ Asset: 'b-green', Liability: 'b-red', Equity: 'b-orange', Income: 'b-blue', Expense: 'b-gray' }[a.type] || 'b-gray'" style="font-size:10px">{{ a.type }}</span></td>
                  <td style="text-align:right;font-size:12px">{{ money(a.opening) }}</td>
                  <td style="text-align:right;font-size:12px">{{ money(a.debit) }}</td>
                  <td style="text-align:right;font-size:12px">{{ money(a.credit) }}</td>
                  <td style="text-align:right;font-weight:800" :style="a.balance < 0 ? 'color:var(--danger)' : ''">{{ money(a.balance) }}</td>
                </tr>
              </template>
              <tr v-if="trial" style="border-top:2px solid var(--border)">
                <td colspan="4" style="font-weight:800">{{ t('TOTAL') }}</td>
                <td style="text-align:right;font-weight:800">{{ money(trial.reduce((s, a) => s + Number(a.debit), 0)) }}</td>
                <td style="text-align:right;font-weight:800">{{ money(trial.reduce((s, a) => s + Number(a.credit), 0)) }}</td>
                <td style="text-align:right;font-weight:800">{{ money(trial.reduce((s, a) => s + Number(a.balance), 0)) }}</td>
              </tr>
              <tr v-if="trial && !trial.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:28px">{{ t('No accounts.') }}</td></tr>
            </tbody>
          </table>
        </div>
        <p style="font-size:11.5px;color:var(--text-mute);padding:10px 16px">💡 Debit total should equal credit total (including the opening-balance equity entry) — that is the trial balance.</p>
      </div>
    </template>

    <!-- ═══════ P&L STATEMENT ═══════ -->
    <template v-if="tab === 'pnl'">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px">
        <div class="stat"><div class="s-label"><span class="s-ico">📈</span>{{ t('Income') }}</div><div class="s-value" style="color:var(--ok)">{{ pnl ? money(pnl.total_income) : money(0) }}</div><div class="s-trend">{{ monthLabel(month) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📉</span>{{ t('Expenses') }}</div><div class="s-value" style="color:var(--danger)">{{ pnl ? money(pnl.total_expense) : money(0) }}</div><div class="s-trend">{{ monthLabel(month) }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⚖️</span>{{ t('Net result') }}</div><div class="s-value" :style="(pnl ? pnl.net : 0) >= 0 ? 'color:var(--ok)' : 'color:var(--danger)'">{{ pnl ? money(pnl.net) : money(0) }}</div><div class="s-trend">{{ (pnl ? pnl.net : 0) >= 0 ? 'surplus' : 'deficit' }} for the month</div></div>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-bottom:10px">
        <button @click="exportPnlCsv" class="btn-ghost" style="font-size:12px">{{ t('⬇ CSV') }}</button>
        <button @click="printTable('P&L Statement — ' + monthLabel(month) + ' — ' + (config.mall_name || 'Mall'), $refs.pnlArea)" class="btn-ghost" style="font-size:12px">{{ t('🖨️ Print') }}</button>
      </div>
      <p style="font-size:12px;color:var(--text-mute);margin-bottom:14px">⚡ <b>{{ t('Smart Ledger') }}</b> — every collection, expense, salary, vendor payment, rent and bill now auto-posts to the Chart of Accounts. This statement is built from those journal entries for {{ monthLabel(month) }}.</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px" class="pnl-grid" ref="pnlArea">
        <div class="panel" style="overflow:hidden">
          <h3 style="font-size:13px;font-weight:800;padding:12px 16px;background:rgba(39,174,96,.08);color:var(--ok);border-bottom:1px solid var(--border)">📈 INCOME</h3>
          <div class="tbl-wrap" style="max-height:340px">
            <table class="kr">
              <thead><tr><th>{{ t('Account') }}</th><th style="text-align:right">{{ t('Amount') }}</th></tr></thead>
              <tbody>
                <tr v-for="i in (pnl ? pnl.income : [])" :key="i.code + i.name">
                  <td><b>{{ i.name }}</b></td>
                  <td style="text-align:right;font-weight:800;color:var(--ok)">{{ money(i.amount) }}</td>
                </tr>
                <tr v-if="!pnl || !pnl.income.length"><td colspan="2" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No income entries for this month yet.') }}</td></tr>
              </tbody>
            </table>
          </div>
          <div style="display:flex;justify-content:space-between;font-weight:800;padding:10px 16px;border-top:2px solid var(--border);color:var(--ok)"><span>{{ t('TOTAL INCOME') }}</span><span>{{ pnl ? money(pnl.total_income) : money(0) }}</span></div>
        </div>
        <div class="panel" style="overflow:hidden">
          <h3 style="font-size:13px;font-weight:800;padding:12px 16px;background:rgba(235,87,87,.08);color:var(--danger);border-bottom:1px solid var(--border)">📉 EXPENSES</h3>
          <div class="tbl-wrap" style="max-height:340px">
            <table class="kr">
              <thead><tr><th>{{ t('Account') }}</th><th style="text-align:right">{{ t('Amount') }}</th></tr></thead>
              <tbody>
                <tr v-for="e in (pnl ? pnl.expense : [])" :key="e.code + e.name">
                  <td><b>{{ e.name }}</b></td>
                  <td style="text-align:right;font-weight:800;color:var(--danger)">{{ money(e.amount) }}</td>
                </tr>
                <tr v-if="!pnl || !pnl.expense.length"><td colspan="2" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No expense entries for this month yet.') }}</td></tr>
              </tbody>
            </table>
          </div>
          <div style="display:flex;justify-content:space-between;font-weight:800;padding:10px 16px;border-top:2px solid var(--border);color:var(--danger)"><span>{{ t('TOTAL EXPENSES') }}</span><span>{{ pnl ? money(pnl.total_expense) : money(0) }}</span></div>
        </div>
      </div>
    </template>

    <!-- ═══════ PARTY LEDGER (Vendor / Owner / Tenant / Staff) ═══════ -->
    <template v-if="tab === 'pl'">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <div style="display:flex;gap:4px;border:1px solid var(--border);border-radius:10px;padding:3px;background:var(--bg-alt)">
          <button v-for="t in [{id:'vendor',ic:'🧰',label:'Vendor'},{id:'owner',ic:'🏢',label:'Owner'},{id:'tenant',ic:'🧑‍🤝‍🧑',label:'Tenant'},{id:'staff',ic:'🧑‍💼',label:'Staff'}]" :key="t.id" @click="pickParty(t.id)"
            :style="partyType === t.id ? 'background:var(--primary);color:#fff' : 'background:transparent;color:var(--text-mute)'"
            style="border:none;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:800;cursor:pointer">{{ t.ic }} {{ t.label }}</button>
        </div>
        <div style="min-width:240px;flex:1;max-width:360px">
          <SearchableSelect v-model="partyId" :options="partyOptions" :placeholder="'Select ' + partyType + '…'" style="width:100%" @update:modelValue="loadPartyLedger" />
        </div>
        <input type="date" v-model="plFrom" @change="loadPartyLedger" style="padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12px" />
        <span style="color:var(--text-mute);font-size:12px">→</span>
        <input type="date" v-model="plTo" @change="loadPartyLedger" style="padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12px" />
      </div>
      <div v-if="partyLedger" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px">
        <div class="stat"><div class="s-label"><span class="s-ico">👤</span>{{ t('Party') }}</div><div class="s-value" style="font-size:15px">{{ partyLedger.party.name }}</div><div class="s-trend">{{ partyLedger.label }}<template v-if="partyLedger.party.phone"> · {{ partyLedger.party.phone }}</template></div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📒</span>{{ t('Transactions') }}</div><div class="s-value">{{ partyLedger.count }}</div><div class="s-trend">{{ t('ledger lines') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⚖️</span>{{ t('Balance') }}</div><div class="s-value" :style="partyLedger.closing > 0 ? 'color:var(--danger)' : partyLedger.closing < 0 ? 'color:var(--ok)' : ''">{{ money(partyLedger.closing) }}</div><div class="s-trend">{{ partyLedger.closing > 0 ? 'receivable / paid (Dr)' : partyLedger.closing < 0 ? 'credit side (Cr)' : 'settled' }}</div></div>
      </div>
      <div v-if="partyLedger" class="panel" style="overflow:hidden">
        <div style="display:flex;align-items:center;gap:8px;padding:12px 16px;flex-wrap:wrap">
          <h3 style="font-size:14px">{{ partyLedger.label }} ledger — {{ partyLedger.party.name }}</h3>
          <span style="margin-left:auto;display:flex;gap:6px">
            <button @click="exportPartyCsv" class="btn-ghost" style="font-size:12px">{{ t('⬇ CSV') }}</button>
            <button @click="printTable(partyLedger.label + ' ledger — ' + partyLedger.party.name, $refs.partyTbl)" class="btn-ghost" style="font-size:12px">{{ t('🖨️ Print') }}</button>
          </span>
        </div>
        <div class="tbl-wrap" style="max-height:480px">
          <table class="kr" ref="partyTbl">
            <thead><tr><th>{{ t('Date') }}</th><th>{{ t('Particulars') }}</th><th>{{ t('Method') }}</th><th style="text-align:right">{{ t('Debit') }}</th><th style="text-align:right">{{ t('Credit') }}</th><th style="text-align:right">{{ t('Balance') }}</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in partyLedger.rows" :key="i">
                <td style="font-size:12px">{{ r.date }}</td>
                <td style="font-size:12.5px">{{ r.particulars }}</td>
                <td><span class="badge b-gray" style="font-size:10px">{{ r.method || '—' }}</span></td>
                <td style="text-align:right;font-weight:700;color:var(--danger)">{{ r.debit ? money(r.debit) : '' }}</td>
                <td style="text-align:right;font-weight:700;color:var(--ok)">{{ r.credit ? money(r.credit) : '' }}</td>
                <td style="text-align:right;font-weight:800" :style="r.balance > 0 ? 'color:var(--danger)' : r.balance < 0 ? 'color:var(--ok)' : ''">{{ money(r.balance) }}</td>
              </tr>
              <tr v-if="!partyLedger.rows.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:24px">{{ t('No ledger transactions for this party.') }}</td></tr>
            </tbody>
            <tfoot style="border-top:2px solid var(--border)">
              <tr><td colspan="3" style="font-weight:800">{{ t('CLOSING BALANCE') }}</td>
                <td style="text-align:right;font-weight:800;color:var(--danger)">{{ money(partyLedger.rows.reduce((s, r) => s + r.debit, 0)) }}</td>
                <td style="text-align:right;font-weight:800;color:var(--ok)">{{ money(partyLedger.rows.reduce((s, r) => s + r.credit, 0)) }}</td>
                <td style="text-align:right;font-weight:800">{{ money(partyLedger.closing) }}</td></tr>
            </tfoot>
          </table>
        </div>
      </div>
      <div v-else class="panel" style="padding:28px;text-align:center;color:var(--text-mute)">Pick a {{ partyType }} to open their accounts ledger — bills &amp; collections for owners, expenses &amp; payments for vendors, rent for tenants, salaries for staff.</div>
    </template>

    <!-- ═══════ STATEMENTS (period statements, bank-style) ═══════ -->
    <template v-if="tab === 'statements'">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <div style="display:flex;gap:4px;border:1px solid var(--border);border-radius:10px;padding:3px;background:var(--bg-alt)">
          <button v-for="stb in [{id:'owner',ic:'🏢',label:'Owner'},{id:'tenant',ic:'🧑‍🤝‍🧑',label:'Tenant'},{id:'vendor',ic:'🧰',label:'Vendor'},{id:'staff',ic:'🧑‍💼',label:'Staff'}]" :key="stb.id" @click="pickStType(stb.id)"
            :style="stType === stb.id ? 'background:var(--primary);color:#fff' : 'background:transparent;color:var(--text-mute)'"
            style="border:none;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:800;cursor:pointer">{{ stb.ic }} {{ t(stb.label) }}</button>
        </div>
        <div style="min-width:240px;flex:1;max-width:360px">
          <SearchableSelect v-model="stId" :options="stOptions" :placeholder="'Select ' + stType + '…'" style="width:100%" @update:modelValue="loadStatement" />
        </div>
        <input type="date" v-model="stFrom" @change="loadStatement" style="padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12px" />
        <span style="color:var(--text-mute);font-size:12px">→</span>
        <input type="date" v-model="stTo" @change="loadStatement" style="padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12px" />
      </div>
      <div v-if="stData" class="panel" style="overflow:hidden">
        <div style="display:flex;align-items:center;gap:8px;padding:14px 16px;flex-wrap:wrap;background:var(--bg-alt)">
          <div>
            <h3 style="font-size:14.5px">💰 Statement — {{ stData.party.name }}</h3>
            <p style="font-size:11.5px;color:var(--text-mute);margin-top:2px">{{ config.mall_name || 'Mall Manager' }}{{ config.mall_address ? ' · ' + config.mall_address : '' }}<template v-if="stData.from"> · period {{ stData.from }} → {{ stData.to }}</template></p>
          </div>
          <span style="margin-left:auto;display:flex;gap:6px">
            <button @click="exportStatementCsv" class="btn-ghost" style="font-size:12px">{{ t('⬇ CSV') }}</button>
            <button @click="printTable('Statement — ' + stData.party.name, $refs.stTbl)" class="btn-ghost" style="font-size:12px">{{ t('🖨️ Print') }}</button>
          </span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;padding:12px 16px">
          <div class="stat" style="margin:0"><div class="s-label"><span class="s-ico">🚩</span>{{ t('Opening (before period)') }}</div><div class="s-value">{{ money(stData.opening) }}</div><div class="s-trend">{{ t('balance brought forward') }}</div></div>
          <div class="stat" style="margin:0"><div class="s-label"><span class="s-ico">📒</span>{{ t('Transactions') }}</div><div class="s-value">{{ stData.count }}</div><div class="s-trend">{{ t('in period') }}</div></div>
          <div class="stat" style="margin:0"><div class="s-label"><span class="s-ico">⚖️</span>{{ t('Closing') }}</div><div class="s-value" :style="stData.closing < 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(stData.closing) }}</div><div class="s-trend">{{ stData.closing < 0 ? 'credit side (Cr)' : 'debit side (Dr)' }}</div></div>
        </div>
        <div class="tbl-wrap" style="max-height:440px">
          <table class="kr" ref="stTbl">
            <thead><tr><th>{{ t('Date') }}</th><th>{{ t('Particulars') }}</th><th>{{ t('Method') }}</th><th style="text-align:right">{{ t('Debit') }}</th><th style="text-align:right">{{ t('Credit') }}</th><th style="text-align:right">{{ t('Balance') }}</th></tr></thead>
            <tbody>
              <tr><td style="font-weight:800">—</td><td style="font-weight:800">{{ t('OPENING BALANCE') }}</td><td></td><td></td><td></td><td style="text-align:right;font-weight:800">{{ money(stData.opening) }}</td></tr>
              <tr v-for="(r, i) in stData.rows" :key="i">
                <td style="font-size:12px">{{ r.date }}</td>
                <td style="font-size:12.5px">{{ r.particulars }}</td>
                <td><span class="badge b-gray" style="font-size:10px">{{ r.method || '—' }}</span></td>
                <td style="text-align:right;font-weight:700;color:var(--danger)">{{ r.debit ? money(r.debit) : '' }}</td>
                <td style="text-align:right;font-weight:700;color:var(--ok)">{{ r.credit ? money(r.credit) : '' }}</td>
                <td style="text-align:right;font-weight:800" :style="r.balance < 0 ? 'color:var(--danger)' : ''">{{ money(r.balance) }}</td>
              </tr>
              <tr v-if="!stData.rows.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:20px">{{ t('No transactions in this period.') }}</td></tr>
              <tr style="border-top:2px solid var(--border)"><td style="font-weight:800">—</td><td style="font-weight:800">{{ t('CLOSING BALANCE') }}</td><td></td><td style="text-align:right;font-weight:800;color:var(--danger)">{{ money(stData.rows.reduce((s, r) => s + r.debit, 0)) }}</td><td style="text-align:right;font-weight:800;color:var(--ok)">{{ money(stData.rows.reduce((s, r) => s + r.credit, 0)) }}</td><td style="text-align:right;font-weight:800">{{ money(stData.closing) }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div v-else class="panel" style="padding:28px;text-align:center;color:var(--text-mute)">Pick a {{ stType }} and a period to build their statement — opening balance, transactions and closing balance.</div>
    </template>

    <!-- ═══════ CASHFLOW ═══════ -->
    <template v-if="tab === 'cashflow'">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <h3 style="font-size:14px">🔄 Cashflow</h3>
        <span style="margin-left:auto;display:flex;gap:6px">
          <button v-for="n in [6, 12, 24]" :key="n" @click="cashflowMonths = n; loadCashflow()" :style="cashflowMonths === n ? 'background:var(--primary);color:#fff' : ''" class="btn-ghost" style="font-size:12px">{{ n }}M</button>
        </span>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:12px;margin-bottom:16px">
        <div class="stat"><div class="s-label"><span class="s-ico">📥</span>{{ t('Money in (period)') }}</div><div class="s-value" style="color:var(--ok)">{{ cashflow ? money(cashflow.period_in) : money(0) }}</div><div class="s-trend">{{ cashflowMonths }} months</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📤</span>{{ t('Money out') }}</div><div class="s-value" style="color:var(--danger)">{{ cashflow ? money(cashflow.period_out) : money(0) }}</div><div class="s-trend">expenses + vendor payouts</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⚖️</span>{{ t('Net flow') }}</div><div class="s-value" :style="(cashflow ? cashflow.period_in - cashflow.period_out : 0) >= 0 ? 'color:var(--ok)' : 'color:var(--danger)'">{{ cashflow ? money(cashflow.period_in - cashflow.period_out) : money(0) }}</div><div class="s-trend">{{ t('in − out') }}</div></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:14px">
        <div v-for="m in cashflow?.methods || []" :key="m.method" class="panel" style="padding:14px">
          <div style="display:flex;align-items:center;gap:8px"><span style="font-size:19px">{{ METHOD_ICONS[m.method] || '💳' }}</span><b style="text-transform:capitalize">{{ m.method }}</b><span style="margin-left:auto;font-size:11px;color:var(--text-mute)">{{ t('all-time') }}</span></div>
          <div style="display:flex;justify-content:space-between;margin-top:9px;font-size:11.5px"><span style="color:var(--ok)">In {{ money(m.in) }}</span><span style="color:var(--danger)">Out {{ money(m.out) }}</span></div>
          <div style="font-size:15px;font-weight:800;margin-top:5px" :style="m.balance < 0 ? 'color:var(--danger)' : 'color:var(--ok)'">Balance {{ money(m.balance) }}</div>
        </div>
      </div>
      <div class="panel" style="padding:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <b style="font-size:12.5px">{{ t('Monthly in vs out') }}</b>
          <span style="display:flex;gap:12px;font-size:11px;color:var(--text-mute)"><i style="width:10px;height:10px;border-radius:3px;background:#27AE60;display:inline-block;margin-right:4px"></i>In<i style="width:10px;height:10px;border-radius:3px;background:#EB5757;display:inline-block;margin:0 4px 0 10px"></i>{{ t('Out') }}</span>
        </div>
        <div v-if="cashflow" style="display:flex;align-items:flex-end;gap:6px;height:170px;border-bottom:1px solid var(--border);padding-top:6px">
          <div v-for="s in cashflow.series" :key="s.month" style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;height:100%;justify-content:flex-end">
            <div style="width:100%;max-width:26px;border-radius:4px 4px 0 0;background:#EB5757;height:{{ s.out ? Math.max(3, Math.round(s.out / Math.max(maxOf(cashflow.series, 'in'), 1) * 140)) : 2 }}px" :title="s.month + ' out ' + money(s.out)"></div>
            <div style="width:100%;max-width:26px;border-radius:4px 4px 0 0;background:#27AE60;height:{{ s.in ? Math.max(3, Math.round(s.in / Math.max(maxOf(cashflow.series, 'in'), 1) * 140)) : 2 }}px" :title="s.month + ' in ' + money(s.in)"></div>
            <span style="font-size:9px;color:var(--text-mute);transform:rotate(-40deg);white-space:nowrap;margin-top:2px">{{ s.month.slice(5) + '/' + s.month.slice(2, 4) }}</span>
          </div>
        </div>
        <div style="margin-top:14px">
          <b style="font-size:12px;color:var(--text-mute)">{{ t('Running balance by month') }}</b>
          <div style="display:flex;flex-direction:column;gap:5px;margin-top:8px">
            <div v-for="s in cashflow?.series || []" :key="s.month" style="display:flex;justify-content:space-between;font-size:11.5px;padding:5px 0;border-bottom:1px dashed var(--border)">
              <span>{{ s.month }}</span><b :style="s.balance < 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(s.balance) }}</b>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ═══════ RECONCILE (custodial utilities + bank/cash) ═══════ -->
    <template v-if="tab === 'reconcile'">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:14px" class="rc-grid">
        <div class="panel" style="padding:18px;overflow:hidden">
          <h3 style="font-size:14px;margin-bottom:4px">⚡ Utility income vs cost <small style="color:var(--text-mute);font-weight:400">(own-income model, spec 3.3)</small></h3>
          <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">Elec/water collections are the society's <b>{{ t('own income') }}</b>; the main DESCO/WASA bills are expenses in the same ledger — <b>not</b> a custodial pass-through fund. Profit/loss = collected − paid.</p>
          <div v-if="bankRecon?.recon">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">{{ bankRecon.recon.month }}</div>
            <div v-for="r in [
              { label: '⚡ Elec collected (income)', v: bankRecon.recon.current.elec_collected },
              { label: '💧 Water collected (income)', v: bankRecon.recon.current.water_collected },
              { label: '🔌 DESCO main bill (expense)', v: bankRecon.recon.current.desco_paid },
              { label: '🚰 WASA main bill (expense)', v: bankRecon.recon.current.wasa_paid },
            ]" :key="r.label" style="display:flex;justify-content:space-between;align-items:center;font-size:12.5px;padding:9px 12px;border-radius:10px;background:var(--bg-alt);margin-bottom:6px">
              <span>{{ r.label }}</span><b>{{ money(r.v) }}</b>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:12.5px;padding:10px 12px;border-radius:10px;background:rgba(47,128,237,.08);border:1px solid var(--border);margin-top:8px">
              <span>⚖️ Utility profit/loss this month</span><b :style="(bankRecon.recon.utility_pnl?.current || 0) >= 0 ? 'color:var(--ok)' : 'color:var(--danger)'">{{ money(bankRecon.recon.utility_pnl?.current || 0) }}</b>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:11.5px;padding:8px 12px;color:var(--text-mute)">
              <span>⚡ Elec only: sub-meter collections − DESCO bill (spec 3.3)</span><b :style="(bankRecon.recon.elec_pnl?.current || 0) >= 0 ? 'color:var(--ok)' : 'color:var(--danger)'">{{ money(bankRecon.recon.elec_pnl?.current || 0) }}</b>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:11.5px;padding:8px 12px;color:var(--text-mute)">
              <span>All-time utility profit/loss</span><b :style="(bankRecon.recon.utility_pnl?.all_time || 0) >= 0 ? 'color:var(--ok)' : 'color:var(--danger)'">{{ money(bankRecon.recon.utility_pnl?.all_time || 0) }}</b>
            </div>
          </div>
        </div>
        <div class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:4px">🏦 Bank / cash balance check</h3>
          <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">Compare the system balance with your actual bank statement / cash count. The difference shows uncleared or unrecorded items.</p>
          <div v-for="m in ['cash', 'bank', 'bkash', 'nagad']" :key="m" style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:12.5px;margin-bottom:6px">
              <b style="text-transform:capitalize">{{ METHOD_ICONS[m] }} {{ m }}</b>
              <span style="color:var(--text-mute);font-size:11.5px">System balance <b :style="(bankRecon?.balances[m]?.balance || 0) < 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(bankRecon?.balances[m]?.balance || 0) }}</b></span>
            </div>
            <input v-model="stmtBalances[m]" type="number" :placeholder="'Actual ' + m + ' statement / count…'" style="width:100%;padding:9px 11px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12.5px" />
            <div v-if="stmtDiff(m) !== null" style="font-size:11.5px;margin-top:5px" :style="stmtDiff(m) === 0 ? 'color:var(--ok)' : 'color:var(--danger)'">
              {{ stmtDiff(m) === 0 ? '✅ Reconciled — matches the books' : (stmtDiff(m) > 0 ? '⚠️ Books are SHORT by ' + money(stmtDiff(m)) + ' — check uncleared / unrecorded' : '⚠️ Books are OVER by ' + money(-stmtDiff(m))) }}
            </div>
          </div>
        </div>
      </div>
      <!-- ═══════ BANK STATEMENT IMPORT & RECONCILIATION (spec 3.7) ═══════ -->
      <div class="panel" style="padding:18px;margin-top:16px">
        <h3 style="font-size:14px;margin-bottom:4px">📥 Bank statement import &amp; reconciliation</h3>
        <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">Upload your bank / mobile-banking statement CSV (any bank export — Date, Description, Debit, Credit, Balance). The system parses it, imports it and <b>{{ t('auto-matches') }}</b> each line against your books (amount + ±3-day window). Unmatched lines need a manual check — they are either missing entries or bank-only items.</p>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          <select v-model="stmtAcctId" @change="loadStmt" style="padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
            <option :value="0" disabled>{{ t('Bank account…') }}</option>
            <option v-for="a in payAccounts.filter(x => x.method === 'bank')" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
          </select>
          <label style="padding:9px 14px;border-radius:10px;border:1px dashed var(--primary);color:var(--primary);font-size:12.5px;font-weight:800;cursor:pointer;background:rgba(47,128,237,.06)">
            📄 {{ stmtFileName || 'Choose CSV file…' }}
            <input type="file" accept=".csv,.txt" style="display:none" @change="onStmtFilePick" />
          </label>
          <button @click="parseStmt" style="padding:9px 16px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('🔍 Preview') }}</button>
          <button v-if="stmtBatches.length" @click="loadStmt" class="btn-ghost" style="font-size:12px">{{ t('🔄 Refresh') }}</button>
          <span v-if="stmtResult" style="font-size:12px;color:var(--text-mute)">Last import: <b>{{ stmtResult.imported }}</b> lines · <b style="color:var(--ok)">{{ stmtResult.matched }}</b> matched · <b style="color:var(--danger)">{{ stmtResult.unmatched }}</b> unmatched</span>
        </div>
        <!-- import result summary -->
        <div v-if="stmtResult" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:14px">
          <div class="stat"><div class="s-label"><span class="s-ico">🧾</span>{{ t('Statement balance') }}</div><div class="s-value">{{ money(stmtResult.statement_balance) }}</div><div class="s-trend">{{ t("last line's balance") }}</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">📒</span>{{ t('System balance') }}</div><div class="s-value">{{ money(stmtResult.system_balance) }}</div><div class="s-trend">{{ t('books for this account') }}</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">⚖️</span>{{ t('Difference') }}</div><div class="s-value" :style="stmtResult.difference === 0 ? 'color:var(--ok)' : 'color:var(--danger)'">{{ money(stmtResult.difference) }}</div><div class="s-trend">{{ stmtResult.difference === 0 ? '✅ reconciled' : 'unreconciled — see unmatched below' }}</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">🔍</span>{{ t('Matched') }}</div><div class="s-value" style="color:var(--ok)">{{ stmtResult.matched }}<small style="font-size:11px;color:var(--text-mute)"> / {{ stmtResult.imported }}</small></div><div class="s-trend">{{ stmtResult.unmatched }} unmatched lines</div></div>
        </div>
        <!-- imported statement -->
        <div v-if="stmtRows.length" style="margin-top:14px">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px">Imported statements — {{ stmtTotals.matched }} ✅ matched · {{ stmtTotals.unmatched }} ⚠️ unmatched · in ৳{{ Number(stmtTotals.in).toLocaleString('en-IN') }} · out ৳{{ Number(stmtTotals.out).toLocaleString('en-IN') }}</div>
            <span style="margin-left:auto;display:flex;gap:6px">
              <button @click="exportStmtCsv" class="btn-ghost" style="font-size:12px">{{ t('⬇ CSV') }}</button>
              <button @click="printStmt" class="btn-ghost" style="font-size:12px">{{ t('🖨️ Print') }}</button>
            </span>
          </div>
          <div class="tbl-wrap" style="max-height:340px">
            <table class="kr">
              <thead><tr><th>{{ t('Date') }}</th><th>{{ t('Description') }}</th><th style="text-align:right">{{ t('Debit') }}</th><th style="text-align:right">{{ t('Credit') }}</th><th style="text-align:right">{{ t('Balance') }}</th><th>{{ t('Match') }}</th></tr></thead>
              <tbody>
                <tr v-for="x in stmtRows" :key="x.id">
                  <td style="font-size:12px">{{ x.stmt_date }}</td>
                  <td style="font-size:12px">{{ x.descr }}</td>
                  <td style="text-align:right;font-size:12px">{{ x.out ? money(x.out) : '—' }}</td>
                  <td style="text-align:right;font-size:12px;color:var(--ok)">{{ x.inn ? money(x.inn) : '—' }}</td>
                  <td style="text-align:right;font-size:12px;color:var(--text-mute)">{{ x.balance ? money(x.balance) : '—' }}</td>
                  <td><span class="badge" :class="x.matched ? 'b-green' : 'b-red'" style="font-size:10px">{{ x.matched ? '✅ ' + x.matched_ref : '⚠️ unmatched' }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="stmtBatches.length" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
            <button v-for="batch in stmtBatches" :key="batch" @click="delStmtBatch(batch)" class="btn-ghost" style="font-size:11.5px">🗑️ Delete batch {{ batch }}</button>
          </div>
        </div>
        <p v-else-if="stmtAcctId" style="font-size:12px;color:var(--text-mute);margin-top:12px">{{ t('No statements imported for this account yet.') }}</p>
      </div>
      <!-- ═══════ STATEMENT PREVIEW MODAL ═══════ -->
      <div v-if="stmtPreview" class="overlay" @click.self="stmtPreview = null">
        <div class="modal" style="max-width:720px">
          <div class="modal-h"><div class="t">🔍 Statement preview — {{ stmtFileName || 'CSV' }}</div><button class="close" @click="stmtPreview = null">✕</button></div>
          <div class="modal-b">
            <p style="font-size:12px;color:var(--text-mute);margin-bottom:10px"><b>{{ stmtPreview.rows.length }}</b> rows detected · in ৳{{ Number(stmtPreview.total_in).toLocaleString('en-IN') }} · out ৳{{ Number(stmtPreview.total_out).toLocaleString('en-IN') }} — check the columns look right, then import.</p>
            <div class="tbl-wrap" style="max-height:300px">
              <table class="kr">
                <thead><tr><th>{{ t('Date') }}</th><th>{{ t('Description') }}</th><th style="text-align:right">{{ t('Debit') }}</th><th style="text-align:right">{{ t('Credit') }}</th><th style="text-align:right">{{ t('Balance') }}</th></tr></thead>
                <tbody>
                  <tr v-for="(r, i) in stmtPreview.rows.slice(0, 40)" :key="i">
                    <td style="font-size:12px">{{ r.date }}</td><td style="font-size:12px">{{ r.descr }}</td>
                    <td style="text-align:right;font-size:12px">{{ r.out ? money(r.out) : '—' }}</td>
                    <td style="text-align:right;font-size:12px;color:var(--ok)">{{ r.in ? money(r.in) : '—' }}</td>
                    <td style="text-align:right;font-size:12px;color:var(--text-mute)">{{ r.balance ? money(r.balance) : '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-if="stmtPreview.rows.length > 40" style="font-size:11.5px;color:var(--text-mute);margin-top:6px">…and {{ stmtPreview.rows.length - 40 }} more rows (imported in full).</p>
            <div style="display:flex;gap:10px;margin-top:14px">
              <button @click="importStmt" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">✅ Import &amp; auto-match</button>
              <button @click="stmtPreview = null" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ═══════ INVOICES ═══════ -->
    <template v-if="tab === 'invoices'">
      <div class="page-head">
        <div class="ph-t">
          <div class="ph-ttl">🧾 {{ t('Invoices') }}</div>
          <div class="ph-sub">{{ t('Combined bills per space — line items, totals & print') }}</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <button @click="monthNav(-1)" class="btn-ghost" style="padding:6px 10px;font-size:12px">◀</button>
          <div style="min-width:108px;text-align:center;font-weight:800;font-size:13px">{{ monthLabel(month) }}</div>
          <button @click="monthNav(1)" class="btn-ghost" style="padding:6px 10px;font-size:12px">▶</button>
          <select v-model="invStatus" @change="loadInvoices" style="padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12.5px">
            <option value="">{{ t('All statuses') }}</option>
            <option v-for="s in INV_ST" :key="s" :value="s">{{ t(s) }}</option>
          </select>
          <SearchableSelect v-model="invShop" :options="shopOpts" :placeholder="t('All spaces')" @change="loadInvoices" class="inv-ssel" style="width:220px" />
          <button @click="loadInvoices" class="btn-ghost" style="padding:8px 12px;font-size:12px">🔄 {{ t('Refresh') }}</button>
          <select v-model="printTmpl" title="{{ t('Print template') }}" style="padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12px;font-weight:800">
            <option value="a4">A4 · {{ t('current') }}</option>
            <option value="a5">A5</option>
            <option value="half">½ + ½ A4</option>
          </select>
          <select v-if="printTmpl !== 'a4'" v-model="printOrient" title="{{ t('Orientation') }}" style="padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12px;font-weight:800">
            <option value="portrait">{{ t('Portrait') }}</option>
            <option value="landscape">{{ t('Landscape') }}</option>
          </select>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px">
        <div class="panel" style="padding:14px"><div style="font-size:11px;color:var(--text-mute);font-weight:800">{{ t('Billed') }}</div><div style="font-size:17px;font-weight:800;margin-top:3px">{{ money(invSummary?.billed || 0) }}</div></div>
        <div class="panel" style="padding:14px"><div style="font-size:11px;color:var(--text-mute);font-weight:800">{{ t('Collected') }}</div><div style="font-size:17px;font-weight:800;margin-top:3px;color:var(--ok)">{{ money(invSummary?.collected || 0) }}</div></div>
        <div class="panel" style="padding:14px"><div style="font-size:11px;color:var(--text-mute);font-weight:800">{{ t('Outstanding') }}</div><div style="font-size:17px;font-weight:800;margin-top:3px;color:var(--danger)">{{ money(invSummary?.outstanding || 0) }}</div></div>
        <div class="panel" style="padding:14px"><div style="font-size:11px;color:var(--text-mute);font-weight:800">{{ t('Invoices') }}</div><div style="font-size:17px;font-weight:800;margin-top:3px">{{ invSummary?.count || 0 }}</div></div>
      </div>

      <div class="panel" style="padding:0;overflow:hidden">
        <div class="tbl-wrap">
          <table class="kr">
            <thead><tr>
              <th>{{ t('Invoice') }}</th><th>{{ t('Space') }}</th><th class="inv-hide-sm">{{ t('Owner') }}</th>
              <th class="inv-hide-sm" style="text-align:right">🧾 {{ t('Service') }}</th><th class="inv-hide-sm" style="text-align:right">⚡</th><th class="inv-hide-sm" style="text-align:right">💧</th>
              <th class="inv-hide-sm" style="text-align:right">⚠️ {{ t('Fine') }}</th><th style="text-align:right">{{ t('Total') }}</th><th style="text-align:right">{{ t('Due') }}</th>
              <th>{{ t('Status') }}</th><th></th>
            </tr></thead>
            <tbody>
              <tr v-for="iv in invList" :key="iv.shop">
                <td style="font-weight:800;font-size:12px">{{ iv.ref }}</td>
                <td style="font-size:12.5px;font-weight:700"><span class="elink" @click.stop="linkShop(iv)">{{ iv.shop_no }}</span> <small style="color:var(--text-mute)">· {{ iv.shop_floor || '—' }}</small></td>
                <td class="inv-hide-sm" style="font-size:12px"><span class="elink" @click.stop="linkOwner(iv)">{{ iv.owner_name || '—' }}</span></td>
                <td class="inv-hide-sm" style="text-align:right;font-size:12px">{{ iv.items.service ? money(iv.items.service) : '—' }}</td>
                <td class="inv-hide-sm" style="text-align:right;font-size:12px">{{ iv.items.elec ? money(iv.items.elec) : '—' }}</td>
                <td class="inv-hide-sm" style="text-align:right;font-size:12px">{{ iv.items.water ? money(iv.items.water) : '—' }}</td>
                <td class="inv-hide-sm" style="text-align:right;font-size:12px">{{ iv.fines ? money(iv.fines) : '—' }}</td>
                <td style="text-align:right;font-weight:800;font-size:12.5px">{{ money(iv.total) }}</td>
                <td :style="iv.due > 0 ? 'text-align:right;font-size:12.5px;color:var(--danger);font-weight:700' : 'text-align:right;font-size:12.5px;color:var(--ok);font-weight:700'">{{ iv.due ? money(iv.due) : '—' }}</td>
                <td><span class="badge" :class="badge(iv.status)">{{ bnd(iv.status) }}</span></td>
                <td style="text-align:right;white-space:nowrap">
                  <button @click="printCombined(iv)" title="🖨️ Print" style="background:none;border:none;font-size:15px;cursor:pointer">🖨️</button>
                  <button @click="openInvDetail(iv)" title="👁 {{ t('Details') }}" style="background:none;border:none;font-size:15px;cursor:pointer">👁</button>
                </td>
              </tr>
              <tr v-if="!invList.length"><td colspan="11" style="text-align:center;padding:26px;color:var(--text-mute);font-size:12.5px">{{ t('No invoices for this month.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

            <div v-if="invDetail" class="overlay" @click.self="invDetail = null">
        <div class="modal" style="max-width:780px">
          <div class="modal-h"><div class="t">🧾 {{ t('Invoice') }} — {{ invDetail.ref }}</div><button @click="invDetail = null" style="background:none;border:none;font-size:16px;cursor:pointer">✕</button></div>
          <div class="modal-b">
            <div v-if="!invPreviewHtml" style="text-align:center;padding:20px;color:var(--text-mute);font-size:13px">{{ t('Loading preview…') }}</div>
            <div v-else ref="invPreviewWrap" style="overflow:auto;max-height:54vh;background:#fff;border:1px solid var(--border);border-radius:10px;padding:10px">
              <div :style="{ zoom: invPreviewZoom }" style="margin:0 auto" v-html="invPreviewHtml"></div>
            </div>
            <div v-if="invShowTable" style="margin-top:14px">
              <div style="font-size:12px;color:var(--text-mute);font-weight:800;margin-bottom:6px">{{ t('Itemized breakdown') }}</div>
              <div class="tbl-wrap">
                <table class="kr" style="font-size:12.5px">
                  <thead><tr><th>{{ t('Item') }}</th><th style="text-align:right">{{ t('Amount') }}</th><th style="text-align:right">⚠️ {{ t('Fine') }}</th><th>{{ t('Status') }}</th></tr></thead>
                  <tbody>
                    <template v-for="(k, i) in ['service', 'elec', 'water']" :key="k">
                      <tr v-if="invDetail.items[k] || invDetail.fines">
                        <td>{{ k === 'service' ? '🧾 ' + t('Service charge') : k === 'elec' ? '⚡ ' + t('Electricity') : '💧 ' + t('Water') }}</td>
                        <td style="text-align:right">{{ invDetail.items[k] ? money(invDetail.items[k]) : '—' }}</td>
                        <td style="text-align:right">{{ k === 'service' && invDetail.fines ? money(invDetail.fines) : '—' }}</td>
                        <td><span class="badge" :class="badge(invDetail.status)">{{ bnd(invDetail.status) }}</span></td>
                      </tr>
                    </template>
                    <tr style="border-top:2px solid var(--border)">
                      <td style="font-weight:800">{{ t('Total') }}</td><td style="text-align:right;font-weight:800">{{ money(invDetail.total) }}</td><td></td><td></td>
                    </tr>
                    <tr>
                      <td style="font-weight:800;color:var(--ok)">{{ t('Paid') }}</td><td style="text-align:right;color:var(--ok);font-weight:800">{{ money(invDetail.paid) }}</td><td></td><td></td>
                    </tr>
                    <tr>
                      <td style="font-weight:800;color:var(--danger)">{{ t('Due') }}</td><td style="text-align:right;color:var(--danger);font-weight:800">{{ money(invDetail.due) }}</td><td></td><td></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;margin-top:14px;flex-wrap:wrap">
              <select v-model="printTmpl" style="padding:9px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12px;font-weight:800">
                <option value="a4">A4 · {{ t('current') }}</option>
                <option value="a5">A5</option>
                <option value="half">½ + ½ A4</option>
              </select>
              <select v-if="printTmpl !== 'a4'" v-model="printOrient" style="padding:9px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12px;font-weight:800">
                <option value="portrait">{{ t('Portrait') }}</option>
                <option value="landscape">{{ t('Landscape') }}</option>
              </select>
              <button @click="downloadInvDetail" :disabled="invPdfBusy" style="padding:10px 14px;border:none;border-radius:10px;background:#2F80ED;color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">⬇ {{ invPdfBusy ? t('Rendering PDF…') : t('PDF') }}</button>
              <button @click="printCombined(invDetail)" style="padding:10px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">🖨️ {{ t('Print') }}</button>
              <button @click="invShowTable = !invShowTable" class="btn-ghost" style="padding:10px 12px;font-size:12px">👁 {{ t('Itemized') }}</button>
              <button @click="invDetail = null" class="btn-ghost" style="padding:10px 14px">{{ t('Close') }}</button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ═══════ PAYMENTS ═══════ -->
    <template v-if="tab === 'payments'">
      <div class="page-head">
        <div class="ph-t">
          <div class="ph-ttl">💳 {{ t('Payments') }}</div>
          <div class="ph-sub">{{ t('All receipts — record, view, void & print') }}</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <button @click="monthNav(-1)" class="btn-ghost" style="padding:6px 10px;font-size:12px">◀</button>
          <div style="min-width:108px;text-align:center;font-weight:800;font-size:13px">{{ monthLabel(month) }}</div>
          <button @click="monthNav(1)" class="btn-ghost" style="padding:6px 10px;font-size:12px">▶</button>
          <SearchableSelect v-model="payShop" :options="shopOpts" :placeholder="t('All spaces')" @change="loadPayments" class="inv-ssel" style="width:200px" />
          <select v-model="payMethod" @change="loadPayments" style="padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12.5px">
            <option value="">{{ t('All methods') }}</option>
            <option v-for="m in PAY_METHODS" :key="m" :value="m">{{ bnd(m) }}</option>
          </select>
          <select v-model="payStatus" @change="loadPayments" style="padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12.5px">
            <option value="">{{ t('All statuses') }}</option>
            <option v-for="s in PAY_ST" :key="s" :value="s">{{ bnd(s) }}</option>
          </select>
          <button v-if="canManage" @click="openPayQuick" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">💵 {{ t('Collect') }}</button>
          <button @click="loadPayments" class="btn-ghost" style="padding:8px 12px;font-size:12px">🔄 {{ t('Refresh') }}</button>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px">
        <div class="panel" style="padding:14px"><div style="font-size:11px;color:var(--text-mute);font-weight:800">{{ t('Total received') }}</div><div style="font-size:17px;font-weight:800;margin-top:3px">{{ money(paySummary?.total || 0) }}</div></div>
        <div class="panel" style="padding:14px"><div style="font-size:11px;color:var(--text-mute);font-weight:800">{{ t('Net (after voids)') }}</div><div style="font-size:17px;font-weight:800;margin-top:3px;color:var(--ok)">{{ money(paySummary?.net || 0) }}</div></div>
        <div class="panel" style="padding:14px"><div style="font-size:11px;color:var(--text-mute);font-weight:800">🔒 {{ t('Voided') }}</div><div style="font-size:17px;font-weight:800;margin-top:3px;color:var(--danger)">{{ money(paySummary?.voided || 0) }}</div></div>
        <div class="panel" style="padding:14px"><div style="font-size:11px;color:var(--text-mute);font-weight:800">{{ t('Receipts') }}</div><div style="font-size:17px;font-weight:800;margin-top:3px">{{ paySummary?.count || 0 }}</div></div>
        <div class="panel" style="padding:14px;background:linear-gradient(135deg,#eff6ff,#f0fdf4)"><div style="font-size:11px;color:var(--text-mute);font-weight:800">📅 {{ t('Today collected') }}</div><div style="font-size:17px;font-weight:800;margin-top:3px;color:var(--ok)">{{ money(dash?.today?.collected || 0) }}</div><div style="font-size:11px;color:var(--text-mute);margin-top:2px">{{ dash?.today?.count || 0 }} {{ t('receipts today') }}</div></div>
        <div class="panel" style="padding:14px;background:linear-gradient(135deg,#fef2f2,#fff7ed)"><div style="font-size:11px;color:var(--text-mute);font-weight:800">⚠️ {{ t('All dues till today') }}</div><div style="font-size:17px;font-weight:800;margin-top:3px" :style="(dash?.all_due?.total || 0) > 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(dash?.all_due?.total || 0) }}</div><div style="font-size:11px;color:var(--text-mute);margin-top:2px">{{ dash?.all_due?.bills || 0 }} {{ t('unpaid bills') }}</div></div>
      </div>

      <div class="panel" style="padding:0;overflow:hidden">
        <div class="tbl-wrap">
          <table class="kr">
            <thead><tr>
              <th>{{ t('Receipt') }}</th><th>{{ t('Date') }}</th><th>{{ t('Space') }}</th><th>{{ t('Payer') }}</th>
              <th>{{ t('Method') }}</th><th style="text-align:right">{{ t('Amount') }}</th><th>{{ t('Status') }}</th><th></th>
            </tr></thead>
            <tbody>
              <tr v-for="p in payList" :key="p.ptype + '-' + p.id">
                <td style="font-weight:800;font-size:12px">{{ p.receipt }}</td>
                <td style="font-size:12px">{{ (p.stamp || '').slice(0, 10) }}</td>
                <td style="font-size:12.5px;font-weight:700"><span class="elink" @click.stop="linkShop(p)">{{ p.shop_no }}</span> <small style="color:var(--text-mute)">· {{ p.shop_floor || '—' }}</small></td>
                <td style="font-size:12px"><span class="elink" @click.stop="linkOwner(p)">{{ p.payer || '—' }}</span></td>
                <td style="font-size:12px">{{ bnd(p.method) }}<small v-if="p.acct_name" style="color:var(--text-mute)"> · {{ p.acct_name }}</small></td>
                <td style="text-align:right;font-weight:800;font-size:12.5px">{{ money(p.amount) }}</td>
                <td><span class="badge" :class="p.status === 'Approved' ? 'b-green' : p.status === 'Pending' ? 'b-amber' : 'b-red'">{{ bnd(p.status) }}</span></td>
                <td style="text-align:right;white-space:nowrap">
                  <button v-if="p.ptype === 'service' && p.bill_id" @click="openReceipt({ id: p.bill_id })" title="🖨️ {{ t('Receipt') }}" style="background:none;border:none;font-size:15px;cursor:pointer">🖨️</button>
                  <button v-if="p.status === 'Approved' && p.ptype === 'service' && canManage" @click="voidPayment(p)" title="🔒 {{ t('Void') }}" style="background:none;border:none;font-size:14px;cursor:pointer">🔒</button>
                </td>
              </tr>
              <tr v-if="!payList.length"><td colspan="8" style="text-align:center;padding:26px;color:var(--text-mute);font-size:12.5px">{{ t('No payments for this month.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="payHistory && payShop" class="panel" style="padding:16px;margin-top:14px">
        <h3 style="font-size:14px;margin-bottom:6px">📜 {{ t('Payment history') }} — <span class="elink" @click.stop="linkShop(payShop)">{{ payShopLabel }}</span> <small style="color:var(--text-mute);font-weight:600">· {{ t('all months') }} ({{ payHistory.count }})</small></h3>
        <div style="display:flex;gap:16px;font-size:12px;margin-bottom:10px;color:var(--text-mute)">
          <span>{{ t('Total') }}: <b style="color:var(--ink)">{{ money(payHistory.total) }}</b></span>
          <span>{{ t('Net (after voids)') }}: <b style="color:var(--ok)">{{ money(payHistory.net) }}</b></span>
        </div>
        <div class="tbl-wrap" style="max-height:320px">
          <table class="kr" style="font-size:12px">
            <thead><tr><th>{{ t('Receipt') }}</th><th>{{ t('Date') }}</th><th>{{ t('Month') }}</th><th>{{ t('Payer') }}</th><th>{{ t('Method') }}</th><th style="text-align:right">{{ t('Amount') }}</th><th>{{ t('Status') }}</th></tr></thead>
            <tbody>
              <tr v-for="h in payHistory.rows" :key="h.ptype + '-' + h.id">
                <td style="font-weight:800;font-size:11.5px">{{ h.receipt }}</td>
                <td style="font-size:11.5px">{{ (h.created_at || h.stamp || '').slice(0, 10) }}</td>
                <td style="font-size:11.5px">{{ h.month ? monthLabel(h.month) : '—' }}</td>
                <td style="font-size:12px">{{ h.payer || '—' }}</td>
                <td style="font-size:12px">{{ bnd(h.method) }}<small v-if="h.acct_name" style="color:var(--text-mute)"> · {{ h.acct_name }}</small></td>
                <td style="text-align:right;font-weight:800">{{ money(h.amount) }}</td>
                <td><span class="badge" :class="h.voided ? 'b-red' : 'b-green'">{{ h.voided ? t('Voided') : t('Approved') }}</span></td>
              </tr>
              <tr v-if="!payHistory.rows.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No payments recorded for this space yet.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="payQuick" class="overlay" @click.self="payQuick = null">
        <div class="modal" style="max-width:440px">
          <div class="modal-h"><div class="t">💵 {{ t('Collect payment') }}</div><button @click="payQuick = null" style="background:none;border:none;font-size:16px;cursor:pointer">✕</button></div>
          <div class="modal-b">
            <label style="font-size:12px;color:var(--text-mute);display:block;margin-bottom:6px">{{ t('Space *') }}</label>
            <SearchableSelect v-model="payQuick" :options="shopOpts" placeholder="Select space…" @change="refreshQuickBills(); loadQuickInfo()" />
            <div v-if="quickInfo" style="margin-top:10px;display:flex;flex-direction:column;gap:6px;font-size:12px">
              <div v-if="quickInfo.due.total > 0" style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:8px 10px">⚠️ <b style="color:var(--danger)">{{ t('Total due') }}: {{ money(quickInfo.due.total) }}</b> <small style="color:var(--text-mute)">· {{ quickInfo.due.count }} {{ t('unpaid') }}</small>
                <div v-if="quickInfo.due.unpaid_months.length" style="margin-top:4px;display:flex;gap:5px;flex-wrap:wrap"><span v-for="m in quickInfo.due.unpaid_months" :key="m" class="badge b-red">{{ monthLabel(m) }}</span></div>
              </div>
              <div v-else style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:8px 10px">✅ {{ t('No dues') }} <small style="color:var(--text-mute)">· {{ t('fully paid') }}</small></div>
            </div>
            <div v-if="payQuickBills.length" style="margin-top:14px">
              <div style="font-size:12px;color:var(--text-mute);font-weight:800;margin-bottom:6px">{{ t('Unpaid bills') }}</div>
              <div v-for="b in payQuickBills" :key="b.id" @click="startCollectFromQuick(b)" style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border:1px solid var(--border);border-radius:10px;margin-bottom:6px;cursor:pointer;background:var(--bg-alt)">
                <span style="font-size:12.5px;font-weight:700">{{ b.kind === 'service' ? '🧾 ' + t('Service') : b.kind === 'elec' ? '⚡ ' + t('Electricity') : '💧 ' + t('Water') }} <small style="color:var(--text-mute);font-weight:600">· {{ monthLabel(b.month) }}</small></span>
                <span style="font-size:12.5px;font-weight:800">{{ money(Number(b.amount) + Number(b.fine || 0)) }}</span>
              </div>
            </div>
            <p v-else style="font-size:12.5px;color:var(--text-mute);text-align:center;padding:16px">{{ t('No unpaid bills for this space this month.') }}</p>
          </div>
        </div>
      </div>
    </template>


    <!-- ═══════ METERS ═══════ -->
    <template v-if="tab === 'meters'">
      <div class="panel" style="padding:18px">
        <h3 style="font-size:14px;margin-bottom:6px">⚡ Sub-meter reading → auto bill</h3>
        <p style="color:var(--text-mute);font-size:12.5px;margin-bottom:14px">Units = reading − previous reading × rate ({{ money(config.elec_unit_rate) }}/unit elec, {{ money(config.water_unit_rate) }}/unit water). Elec/water collections are the society's <b>{{ t('own income') }}</b> — the main DESCO/WASA bills are expenses in the same ledger (spec 3.3).</p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Space') }}
            <SearchableSelect v-model="meterForm.shop" :options="shops.filter(x => x.status === 'Active').map(s => ({ value: s.id, label: s.no + ' — ' + s.floor + ' (' + s.owner_name + ')' }))" :placeholder="t('Select space…')" allow-add add-label="New space" @add="setAfterAdd(meterForm, 'shop', () => data.list('shops').find(s => s.no === form.no?.trim())?.id); openAdd()" style="margin-top:4px" />
            
          </label>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Type') }}
            <select v-model="meterForm.type" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <option value="elec">{{ t('⚡ Electricity') }}</option><option value="water">{{ t('💧 Water') }}</option>
            </select>
          </label>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Month') }}
            <input type="month" v-model="meterForm.month" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Meter reading') }}
            <input type="number" v-model.number="meterForm.reading" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);grid-column:1/-1">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px"><span>📸 Meter photo <b style="color:var(--danger)">*</b> (spec 3.3 — mandatory)</span><span v-if="meterForm.photoName" style="color:var(--ok);font-size:11.5px">✅ {{ meterForm.photoName }}</span></div>
            <input type="file" accept="image/*" capture="environment" @change="onMeterPhotoPick" style="width:100%;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12.5px" />
          </label>
        </div>
        <button @click="saveMeter" :disabled="saving" style="margin-top:14px;padding:10px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save reading &amp; generate bill</button>
        <div v-if="spaceBillInfo" class="meter-info" style="margin-top:16px;display:grid;grid-template-columns:1fr 1fr 1.6fr;gap:12px;align-items:start">
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:9px 11px">
                <div style="font-size:11px;font-weight:800;color:var(--text-mute);margin-bottom:5px">💡 {{ t('This month') }} — {{ monthLabel(month) }} <template v-if="spaceBillInfo.shop">· {{ spaceBillInfo.shop.no }} <small>({{ spaceBillInfo.shop.owner_name }})</small></template></div>
                <div v-if="spaceBillInfo.current.length" style="display:flex;flex-direction:column;gap:3px;font-size:12px">
                  <div v-for="b in spaceBillInfo.current" :key="b.id" style="display:flex;justify-content:space-between;gap:8px;align-items:center">
                    <span>{{ b.kind === 'service' ? '🧾 ' + t('Service') : b.kind === 'elec' ? '⚡ ' + t('Electricity') : '💧 ' + t('Water') }} <small v-if="b.fine" style="color:var(--danger)">+{{ money(b.fine) }} {{ t('fine') }}</small></span>
                    <span style="font-weight:700">{{ money(Number(b.amount) + Number(b.fine || 0)) }} <span class="badge" :class="badge(b.status)" style="margin-left:4px">{{ bnd(b.status) }}</span></span>
                  </div>
                  <div style="display:flex;justify-content:space-between;border-top:1px dashed var(--border);padding-top:4px;font-weight:800;font-size:12.5px">{{ t('Total') }} <span style="color:var(--primary)">{{ money(meterBilled ? meterBilled.total : 0) }}</span></div>
                </div>
                <div v-else style="font-size:12px;color:var(--text-mute)">{{ t('No bill for this space in') }} {{ monthLabel(month) }} — {{ t('the reading will create one.') }}</div>
              </div>
              <div v-if="spaceBillInfo.due.total > 0" style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:9px 11px;font-size:12px">
                ⚠️ <b style="color:var(--danger)">{{ t('Total due') }}: {{ money(spaceBillInfo.due.total) }}</b>
                <span style="color:var(--text-mute)"> — {{ spaceBillInfo.due.count }} {{ t('unpaid bill(s)') }}</span>
                <div v-if="spaceBillInfo.due.unpaid_months.length" style="margin-top:5px;display:flex;gap:5px;flex-wrap:wrap">
                  <span v-for="m in spaceBillInfo.due.unpaid_months" :key="m" class="badge b-red">{{ monthLabel(m) }}</span>
                </div>
              </div>
              <div v-else-if="spaceBillInfo.history.length" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:9px 11px;font-size:12px">✅ {{ t('No dues — this space is fully paid.') }}</div>
              <div v-if="spaceBillInfo.history.length" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:9px 11px">
                <div style="font-size:11px;font-weight:800;color:var(--text-mute);margin-bottom:5px">📜 {{ t('Bill history') }} <small style="font-weight:600">({{ t('last 6 months') }})</small></div>
                <div class="tbl-wrap" style="max-height:200px;overflow:auto">
                  <table class="kr" style="font-size:11.5px">
                    <thead><tr><th>{{ t('Month') }}</th><th style="text-align:right">🧾</th><th style="text-align:right">⚡</th><th style="text-align:right">💧</th><th style="text-align:right">⚠️</th><th style="text-align:right">{{ t('Total') }}</th><th>{{ t('Status') }}</th></tr></thead>
                    <tbody>
                      <tr v-for="h in spaceBillInfo.history.slice(0, 6)" :key="h.month">
                        <td style="font-weight:700">{{ monthLabel(h.month) }}</td>
                        <td style="text-align:right">{{ h.service ? money(h.service) : '—' }}</td>
                        <td style="text-align:right">{{ h.elec ? money(h.elec) : '—' }}</td>
                        <td style="text-align:right">{{ h.water ? money(h.water) : '—' }}</td>
                        <td style="text-align:right">{{ h.fine ? money(h.fine) : '—' }}</td>
                        <td style="text-align:right;font-weight:800">{{ money(h.total) }}</td>
                        <td><span class="badge" :class="badge(h.status)">{{ bnd(h.status) }}</span></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
        </div>
      </div>
      <div class="panel" style="padding:16px;margin-top:16px">
        <h3 style="font-size:14px;margin-bottom:10px">📋 Readings — {{ monthLabel(meterForm.month || month) }}</h3>
        <div class="tbl-wrap" style="max-height:280px">
          <table class="kr">
            <thead><tr><th>{{ t('Space') }}</th><th>{{ t('Type') }}</th><th style="text-align:right">{{ t('Reading') }}</th><th style="text-align:right">{{ t('Units') }}</th><th>{{ t('Billed') }}</th><th>{{ t('Photo') }}</th><th>{{ t('Flag') }}</th></tr></thead>
            <tbody>
              <tr v-for="r in lastReadings" :key="r.id">
                <td><b>{{ r.no || r.shop }}</b></td>
                <td>{{ r.type === 'elec' ? '⚡ Electricity' : '💧 Water' }}</td>
                <td style="text-align:right">{{ r.reading.toLocaleString('en-IN') }}</td>
                <td style="text-align:right;font-weight:800">{{ r.units.toLocaleString('en-IN') }}</td>
                <td>{{ money((r.units || 0) * (r.type === 'elec' ? config.elec_unit_rate : config.water_unit_rate)) }}</td>
                <td><button v-if="r.photo" @click="meterPhotoView = r.photo" style="border:none;background:none;cursor:pointer;font-size:15px" title="View meter photo">📎</button><span v-else style="color:var(--text-mute);font-size:11px">—</span></td>
                <td><span v-if="r.flag" class="badge b-red" style="font-size:10px" title="Reading is 200%+ above last month">⚠️ anomaly</span><span v-else style="color:var(--text-mute);font-size:11px">ok</span></td>
              </tr>
              <tr v-if="!lastReadings.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:24px">{{ t('No readings yet this month.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
    <!-- meter photo lightbox -->
    <template v-if="meterPhotoView">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.7);z-index:300;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px" @click="meterPhotoView = ''">
        <img :src="meterPhotoView" style="max-width:92vw;max-height:78vh;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.5)" />
        <button style="padding:9px 18px;border:none;border-radius:10px;background:#fff;color:#111;font-weight:800;cursor:pointer">{{ t('✕ Close') }}</button>
      </div>
    </template>

    <!-- ═══════ EXPENSES ═══════ -->
    <template v-if="tab === 'expenses'">
      <div class="panel" style="padding:18px">
        <h3 style="font-size:14px;margin-bottom:14px">📉 Record an expense — {{ monthLabel(month) }}</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Category') }}
            <SearchableSelect v-model="expForm.category" :options="expCategories.map(c => ({ value: c, label: c }))" :placeholder="t('— choose category —')" style="margin-top:4px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Vendor / supplier') }}
            <SearchableSelect v-model="expForm.vendor" :options="vendors.map(v => ({ value: v.name, label: v.name + ' (' + v.category + ')' }))" :placeholder="t('— choose vendor —')" allow-add add-label="New vendor" @add="setAfterAdd(expForm, 'vendor', () => vendors.find(v => v.name === vendorForm.name?.trim())?.name); openVendorAdd()" style="margin-top:4px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Amount (৳)') }}
            <input type="number" v-model.number="expForm.amount" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Paid via') }}
            <select v-model="expForm.method_acct" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <optgroup v-for="g in payGroups" :key="g.label" :label="g.label">
                <option v-for="a in g.items" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
              </optgroup>
            </select>
          </label>
          <label style="font-size:12px;color:var(--text-mute);grid-column:1/-1">{{ t('Note (voucher / invoice)') }}
            <input v-model="expForm.note" :placeholder="t('e.g. Monthly lift AMC — invoice #88412')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);grid-column:1/-1">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px"><span>📎 Voucher / invoice photo (optional)</span><span v-if="expForm.voucherName" style="color:var(--ok);font-size:11.5px">✅ {{ expForm.voucherName }}</span></div>
            <input type="file" accept="image/*" @change="onExpVoucherPick" style="width:100%;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12.5px" />
          </label>
        </div>
        <button @click="saveExpense" style="margin-top:14px;padding:10px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💾 Record expense') }}</button>
      </div>
      <div class="panel" style="padding:18px;margin-top:16px">
        <h3 style="font-size:14px;margin-bottom:4px">💰 Record other income — {{ monthLabel(month) }}</h3>
        <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">Additional income heads like parking fee, community hall / common space rent, advertisement — auto-posts to the Chart of Accounts.</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Income head') }}
            <SearchableSelect v-model="incForm.category" :options="incCategories.map(c => ({ value: c, label: c }))" :placeholder="t('— choose income head —')" style="margin-top:4px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Amount (৳)') }}
            <input type="number" v-model.number="incForm.amount" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Received via') }}
            <select v-model="incForm.method_acct" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <optgroup v-for="g in payGroups" :key="g.label" :label="g.label">
                <option v-for="a in g.items" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
              </optgroup>
            </select>
          </label>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Note') }}
            <input v-model="incForm.note" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
        </div>
        <button @click="saveIncome" style="margin-top:14px;padding:10px 18px;border:none;border-radius:10px;background:var(--ok,#27AE60);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💾 Record income') }}</button>
      </div>
      <div class="panel" style="padding:16px;margin-top:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <h3 style="font-size:14px">💰 Other income — {{ monthLabel(month) }}</h3>
          <span class="badge b-green" style="font-size:12px">Total {{ money(incomeTotal) }}</span>
        </div>
        <div class="tbl-wrap" style="max-height:200px">
          <table class="kr">
            <thead><tr><th>{{ t('Date') }}</th><th>{{ t('Head') }}</th><th>{{ t('Note') }}</th><th>{{ t('Method') }}</th><th style="text-align:right">{{ t('Amount') }}</th></tr></thead>
            <tbody>
              <tr v-for="e in incomeList" :key="e.id">
                <td style="font-size:12px;color:var(--text-mute)">{{ (e.date || '').slice(0, 10) }}</td>
                <td><b>{{ bnd(e.category) }}</b></td>
                <td style="color:var(--text-mute)">{{ e.note || '—' }}</td>
                <td><span class="badge b-blue">{{ bnd(e.method) }}</span></td>
                <td style="text-align:right;font-weight:800;color:var(--ok)">{{ money(e.amount) }}</td>
              </tr>
              <tr v-if="!incomeList.length"><td colspan="5" style="text-align:center;color:var(--text-mute);padding:20px">No other income recorded for {{ monthLabel(month) }}.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="panel" style="padding:16px;margin-top:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <h3 style="font-size:14px">🧾 Expense ledger — {{ monthLabel(month) }}</h3>
          <div style="display:flex;gap:8px;align-items:center">
            <span class="badge b-red" style="font-size:12px">Total {{ money(expTotal) }}</span>
            <button @click="exportExpenses" class="btn-ghost" style="font-size:12px">{{ t('⬇ CSV') }}</button>
          </div>
        </div>
        <div class="tbl-wrap" style="max-height:300px">
          <table class="kr">
            <thead><tr><th>{{ t('Date') }}</th><th>{{ t('Category') }}</th><th>{{ t('Vendor') }}</th><th>{{ t('Note') }}</th><th>{{ t('Method') }}</th><th style="text-align:right">{{ t('Amount') }}</th><th>{{ t('Voucher') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="e in expenses" :key="e.id">
                <td style="font-size:12px;color:var(--text-mute)">{{ (e.date || '').slice(0, 10) }}</td>
                <td><b>{{ bnd(e.category) }}</b></td>
                <td>{{ e.vendor || '—' }}</td>
                <td style="color:var(--text-mute)">{{ e.note || '—' }}</td>
                <td><span class="badge b-blue">{{ bnd(e.method) }}</span></td>
                <td style="text-align:right;font-weight:800;color:var(--danger)">{{ money(e.amount) }}</td>
                <td><button v-if="e.voucher" @click="expVoucherView = e.voucher" style="border:none;background:none;cursor:pointer;font-size:15px" title="View voucher">📎</button><span v-else style="color:var(--text-mute);font-size:11px">—</span></td>
                <td style="text-align:right"><button v-if="canManage" @click="delExpense(e)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px">🗑️</button></td>
              </tr>
              <tr v-if="!expenses.length"><td colspan="8" style="text-align:center;color:var(--text-mute);padding:24px">No expenses recorded for {{ monthLabel(month) }}.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
    <!-- expense voucher lightbox -->
    <template v-if="expVoucherView">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.7);z-index:300;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px" @click="expVoucherView = ''">
        <img :src="expVoucherView" style="max-width:92vw;max-height:78vh;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.5)" />
        <button style="padding:9px 18px;border:none;border-radius:10px;background:#fff;color:#111;font-weight:800;cursor:pointer">{{ t('✕ Close') }}</button>
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
        <div class="stat"><div class="s-label"><span class="s-ico">📋</span>{{ t('Total logged') }}</div><div class="s-value">{{ compCounts.Open + compCounts['In Progress'] + compCounts.Resolved }}</div><div class="s-trend">{{ t('all time') }}</div></div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openCompAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('＋ Log complaint') }}</button>
        <select v-model="compStatus" @change="loadComplaints" style="padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          <option value="">{{ t('All statuses') }}</option><option>{{ t('Open') }}</option><option>{{ t('In Progress') }}</option><option>{{ t('Resolved') }}</option>
        </select>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Space owners report issues (lift / AC / light…) — committee tracks Open → In Progress → Resolved</span>
      </div>
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>{{ t('#') }}</th><th>{{ t('Space') }}</th><th>{{ t('Subject') }}</th><th>{{ t('Priority') }}</th><th>{{ t('Opened') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="c in complaints" :key="c.id">
                <td><small style="color:var(--text-mute)">{{ c.id }}</small></td>
                <td><b>{{ c.shop_no || c.shop }}</b> <small style="color:var(--text-mute)">· {{ c.shop_floor }}</small></td>
                <td>{{ c.subject }}<br /><small style="color:var(--text-mute)">{{ c.descr }}</small></td>
                <td><span class="badge" :class="{ Low: 'b-gray', Normal: 'b-blue', High: 'b-orange', Urgent: 'b-red' }[c.priority] || 'b-gray'">{{ c.priority }}</span></td>
                <td style="font-size:12px;color:var(--text-mute)">{{ (c.opened_at || '').slice(0, 10) }}</td>
                <td><span class="badge" :class="badge(c.status)">{{ bnd(c.status) }}</span></td>
                <td style="text-align:right;white-space:nowrap">
                  <template v-if="canManage">
                    <button v-if="c.status === 'Open'" @click="setCompStatus(c, 'In Progress')" style="padding:5px 9px;border:none;border-radius:8px;background:var(--primary);color:#fff;font-size:11.5px;font-weight:800;cursor:pointer">{{ t('▶ Start') }}</button>
                    <button v-if="c.status !== 'Resolved'" @click="setCompStatus(c, 'Resolved')" style="padding:5px 9px;border:none;border-radius:8px;background:var(--ok);color:#fff;font-size:11.5px;font-weight:800;cursor:pointer;margin-left:4px">{{ t('✓ Resolve') }}</button>
                    <button @click="delComplaint(c)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 8px;cursor:pointer;font-size:11px;margin-left:4px">🗑️</button>
                  </template>
                </td>
              </tr>
              <tr v-if="!complaints.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:28px">{{ t('No complaints — log the first one with ＋ Log complaint.') }}</td></tr>
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
        <span class="badge b-red">{{ t('renew soon') }}</span>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openAssetAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('＋ Add asset') }}</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Lifts, escalators, generators, fire extinguishers — service contracts &amp; warranty with auto reminders</span>
      </div>
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>{{ t('Asset') }}</th><th>{{ t('Type') }}</th><th>{{ t('Location') }}</th><th>{{ t('Vendor') }}</th><th>{{ t('Installed') }}</th><th>{{ t('Warranty until') }}</th><th>{{ t('AMC until') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="a in assets" :key="a.id">
                <td><b>{{ a.name }}</b></td>
                <td><span class="badge b-blue">{{ a.type }}</span></td>
                <td>{{ a.location || '—' }}</td>
                <td>{{ a.vendor || '—' }}</td>
                <td style="font-size:12px;color:var(--text-mute)">{{ a.install_date || '—' }}</td>
                <td style="font-size:12px;color:var(--text-mute)">{{ a.warranty_until || '—' }}</td>
                <td style="font-size:12px">{{ a.contract_until || '—' }} <span v-if="amcDays(a)" class="badge" :class="amcBadge(a)" style="margin-left:4px">{{ amcDays(a) }}</span></td>
                <td><span class="badge" :class="badge(a.status)">{{ bnd(a.status) }}</span></td>
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
        <button v-if="canManage" @click="openNoticeAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('📢 Post notice') }}</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">{{ t('Committee announcements for shop owners — pinned notices stay on top') }}</span>
      </div>
      <div v-if="notices.length" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px">
        <div v-for="n in notices" :key="n.id" class="panel chip" style="padding:16px;border-left:3px solid" :style="n.pinned ? 'border-left-color:var(--primary)' : 'border-left-color:var(--border)'">
          <div style="display:flex;align-items:flex-start;gap:10px">
            <span style="font-size:18px">{{ n.pinned ? '📌' : '📢' }}</span>
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <b style="font-size:14px">{{ n.title }}</b>
                <span v-if="n.pinned" class="badge b-blue" style="font-size:10px">{{ t('PINNED') }}</span>
              </div>
              <div style="font-size:12.5px;color:var(--text);margin-top:6px;white-space:pre-wrap">{{ n.body || '—' }}</div>
              <div style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:11.5px;color:var(--text-mute);flex-wrap:wrap">
                <span>📅 {{ n.date }}</span><span>· by {{ n.author || '—' }}</span>
                <span style="flex:1"></span>
                <template v-if="canManage">
                  <button @click="togglePin(n)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:4px 9px;cursor:pointer;font-size:11.5px">{{ n.pinned ? 'Unpin' : '📌 Pin' }}</button>
                  <button @click="sendBlast('notice', (n.title + ' — ' + (n.body || '')).slice(0, 150))" style="border:1px solid #25D366;color:#1faa53;background:rgba(37,211,102,.08);border-radius:8px;padding:4px 9px;cursor:pointer;font-size:11.5px" title="SMS this notice to all owners & tenants (spec 3.9)">{{ t('📲 SMS broadcast') }}</button>
                  <button @click="delNotice(n)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:4px 9px;cursor:pointer;font-size:11.5px">🗑️</button>
                </template>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="panel" style="padding:30px;text-align:center;color:var(--text-mute)">{{ t('No notices yet — post the first announcement with 📢 Post notice.') }}</div>
    </template>

    <!-- ═══════ AUDIT TRAIL ═══════ -->
    <template v-if="tab === 'audit'">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <input v-model="auditQ" :placeholder="t('🔍 Search user / action / module…')" @keyup.enter="loadAudit" style="padding:9px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);min-width:260px;font-family:inherit;font-size:13px;outline:none" />
        <button @click="loadAudit" class="btn-ghost">{{ t('Search') }}</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Who did what, when — collections, expenses, complaints, assets, notices, logins</span>
      </div>
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>{{ t('When') }}</th><th>{{ t('User') }}</th><th>{{ t('Action') }}</th><th>{{ t('Module') }}</th><th>{{ t('Entity') }}</th><th>{{ t('Details') }}</th></tr></thead>
            <tbody>
              <tr v-for="r in auditRows" :key="r.id">
                <td style="font-size:12px;color:var(--text-mute)">{{ (r.ts || '').slice(0, 16) }}</td>
                <td><b>{{ r.user }}</b></td>
                <td><span class="badge" :class="auditBadge(r)">{{ r.action }}</span></td>
                <td style="color:var(--text-mute)">{{ r.module }}</td>
                <td style="color:var(--text-mute)">{{ r.entity }}</td>
                <td style="color:var(--text-mute);max-width:340px;overflow:hidden;text-overflow:ellipsis">{{ r.details || '' }}</td>
              </tr>
              <tr v-if="!auditRows.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:28px">{{ t('No activity recorded yet.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ═══════ STAFF & SALARIES ═══════ -->
    <template v-if="tab === 'staff'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🧑‍💼</span>{{ t('Total staff') }}</div><div class="s-value">{{ staff.length }}</div><div class="s-trend">{{ staffMeta.active }} active</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🟢</span>{{ t('Active') }}</div><div class="s-value" style="color:var(--ok)">{{ staffMeta.active }}</div><div class="s-trend">{{ t('on payroll') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💸</span>{{ t('Monthly payroll') }}</div><div class="s-value">{{ money(staffMeta.payroll_monthly) }}</div><div class="s-trend">{{ t('active staff salaries') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📅</span>{{ t('Paid this month') }}</div><div class="s-value">{{ salaryHistory.length }}</div><div class="s-trend">{{ monthLabel(month) }}</div></div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openStaffAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('＋ Add staff') }}</button>
        <button @click="exportStaff" class="btn-ghost">{{ t('⬇ CSV') }}</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Office staff &amp; security guards — monthly salary entry posts to the expense ledger (spec 3.4)</span>
        <span style="display:flex;gap:4px;border:1px solid var(--border);border-radius:10px;padding:3px;background:var(--bg-alt)">
          <button @click="staffView = 'table'" :style="staffView === 'table' ? 'background:var(--primary);color:#fff' : 'background:transparent;color:var(--text-mute)'" style="border:none;border-radius:8px;padding:6px 11px;font-size:12px;font-weight:800;cursor:pointer">{{ t('☰ List') }}</button>
          <button @click="staffView = 'grid'" :style="staffView === 'grid' ? 'background:var(--primary);color:#fff' : 'background:transparent;color:var(--text-mute)'" style="border:none;border-radius:8px;padding:6px 11px;font-size:12px;font-weight:800;cursor:pointer">{{ t('⊞ Grid') }}</button>
        </span>
      </div>
      <div v-if="staffView === 'table'" class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>{{ t('Name') }}</th><th>{{ t('Designation') }}</th><th>{{ t('Phone') }}</th><th>{{ t('Joined') }}</th><th style="text-align:right">{{ t('Salary/mo') }}</th><th>{{ t('Status') }}</th><th style="text-align:right">{{ t('Paid') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="s in staff" :key="s.id" style="cursor:pointer" @click="openStaffDrawer(s)">
                <td><b>{{ s.name }}</b><br /><small style="color:var(--text-mute)">{{ s.nid || '' }}</small></td>
                <td><span class="badge b-blue">{{ s.designation }}</span></td>
                <td>{{ s.phone || '—' }}</td>
                <td style="font-size:12px;color:var(--text-mute)">{{ s.join_date || '—' }}</td>
                <td style="text-align:right;font-weight:800">{{ money(s.salary) }}</td>
                <td><span class="badge" :class="badge(s.status)">{{ bnd(s.status) }}</span></td>
                <td style="text-align:right;font-size:12px;color:var(--text-mute)">{{ s.salaries_paid || 0 }}× {{ money(s.salaries_total) }}</td>
                <td style="text-align:right;white-space:nowrap" @click.stop>
                  <button v-if="canManage && s.status === 'Active'" @click="openSal(s)" title="Pay monthly salary" style="padding:6px 12px;border:none;border-radius:8px;background:var(--ok);color:#fff;font-size:12px;font-weight:800;cursor:pointer">{{ t('💸 Pay salary') }}</button>
                  <button v-if="canManage" @click="openStaffEdit(s)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px;margin-left:4px">✏️</button>
                  <button v-if="canManage" @click="delStaff(s)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px;margin-left:4px">🗑️</button>
                </td>
              </tr>
              <tr v-if="!staff.length"><td colspan="8" style="text-align:center;color:var(--text-mute);padding:28px">{{ t('No staff yet — add security guards &amp; office staff.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <!-- staff grid view -->
      <div v-else-if="staffView === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px">
        <div v-for="s in staff" :key="s.id" class="panel chip" style="padding:15px;cursor:pointer" @click="openStaffDrawer(s)">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
            <div style="width:42px;height:42px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff" :style="{ background: memberColor({ id: s.id, name: s.name }) }">{{ memberAvatar({ name: s.name }) }}</div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:800;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ s.name }}</div>
              <div style="font-size:11px;color:var(--text-mute)">{{ s.designation }}<span v-if="s.phone"> · {{ s.phone }}</span></div>
            </div>
            <span class="badge" :class="badge(s.status)">{{ bnd(s.status) }}</span>
          </div>
          <div style="display:flex;align-items:center;border-top:1px dashed var(--border);padding-top:8px">
            <span style="font-size:11px;color:var(--text-mute)">{{ t('Salary/mo') }}</span>
            <b style="margin-left:auto;font-size:13.5px">{{ money(s.salary) }}</b>
            <span style="margin-left:12px;font-size:11px;color:var(--text-mute)">{{ s.salaries_paid || 0 }}× paid</span>
          </div>
        </div>
        <div v-if="!staff.length" class="panel" style="padding:24px;text-align:center;color:var(--text-mute)">{{ t('No staff yet.') }}</div>
      </div>
      <div class="panel" style="padding:16px;margin-top:16px" v-if="salaryHistory.length">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <h3 style="font-size:14px">🧾 Salary payments — {{ monthLabel(month) }}</h3>
          <button @click="exportSalaries" class="btn-ghost" style="font-size:12px">{{ t('⬇ CSV') }}</button>
        </div>
        <div class="tbl-wrap" style="max-height:240px">
          <table class="kr">
            <thead><tr><th>{{ t('Staff') }}</th><th>{{ t('Designation') }}</th><th>{{ t('Method') }}</th><th>{{ t('Note') }}</th><th style="text-align:right">{{ t('Amount') }}</th></tr></thead>
            <tbody>
              <tr v-for="h in salaryHistory" :key="h.id">
                <td><b>{{ h.staff_name }}</b></td>
                <td style="color:var(--text-mute)">{{ h.designation }}</td>
                <td><span class="badge b-blue">{{ h.method }}</span></td>
                <td style="color:var(--text-mute)">{{ h.note || '—' }}</td>
                <td style="text-align:right;font-weight:800;color:var(--danger)">{{ money(h.amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ═══════ USERS & ROLES ═══════ -->
    <template v-if="tab === 'users'">
      <div class="stats">
        <div v-for="k in userKpis" :key="k.label" class="stat">
          <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ bnd(k.label) }}</div>
          <div class="s-value">{{ k.value }}</div>
          <div class="s-trend">{{ k.trend || '' }}</div>
        </div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManageUsers" @click="openUserAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('＋ Add system user') }}</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Role-based access control (spec 3.8) — users are assigned a role; each role sees only what it may do</span>
      </div>
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>{{ t('User') }}</th><th>{{ t('Email') }}</th><th>{{ t('Role') }}</th><th>{{ t('Status') }}</th><th>{{ t('Last login') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="u in users" :key="u.id">
                <td><b>{{ u.name }}</b><span v-if="u.self" class="badge b-blue" style="margin-left:6px;font-size:10px">{{ t('you') }}</span></td>
                <td style="color:var(--text-mute)">{{ u.email }}</td>
                <td><span class="badge" :class="{ superadmin: 'b-red', owner: 'b-green', manager: 'b-blue', accountant: 'b-orange', collector: 'b-gray' }[u.role] || 'b-gray'">{{ u.role }}</span></td>
                <td><span class="badge" :class="u.active ? 'b-green' : 'b-red'">{{ u.active ? 'Active' : 'Disabled' }}</span></td>
                <td style="font-size:12px;color:var(--text-mute)">{{ u.last_login || 'never' }}</td>
                <td style="text-align:right;white-space:nowrap">
                  <template v-if="canManageUsers && !u.self">
                    <button @click="openReset(u)" title="Reset password" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px">🔑</button>
                    <button @click="openUserEdit(u)" title="Edit role / status" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px;margin-left:4px">✏️</button>
                    <button @click="delUser(u)" title="Disable user" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px;margin-left:4px">🗑️</button>
                  </template>
                  <small v-else style="color:var(--text-mute)">—</small>
                </td>
              </tr>
              <tr v-if="!users.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:28px">{{ t('No system users yet.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="panel" style="padding:16px;margin-top:16px">
        <h3 style="font-size:14px;margin-bottom:4px">🎭 Role access matrix</h3>
        <p style="font-size:12px;color:var(--text-mute);margin-bottom:10px">What each role can do in this system — enforced server-side on every action</p>
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>{{ t('Capability') }}</th><th v-for="c in ROLE_COLS" :key="c" style="text-align:center">{{ c }}</th></tr></thead>
            <tbody>
              <tr v-for="row in ROLE_MATRIX" :key="row.cap">
                <td>{{ row.cap }}</td>
                <td v-for="(v, i) in row.r" :key="i" style="text-align:center">
                  <span v-if="v" style="color:var(--ok);font-weight:800">✓</span><span v-else style="color:var(--text-mute)">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ═══════ COMMITTEE / SOMITY ═══════ -->
    <template v-if="tab === 'committee'">
      <div v-if="committee" class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🏛️</span>{{ t('Committee members') }}</div><div class="s-value">{{ committee.counts.members }}</div><div class="s-trend">{{ committee.counts.active }} active</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">👑</span>{{ t('Office bearers') }}</div><div class="s-value">{{ committee.members.filter(m => m.role !== 'Member' && m.active).length }}</div><div class="s-trend">{{ t('chairman · secretary · treasurer') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📅</span>{{ t('Meetings') }}</div><div class="s-value">{{ committee.counts.meetings }}</div><div class="s-trend">{{ committee.counts.agm }} AGM</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📜</span>{{ t('Resolutions') }}</div><div class="s-value">{{ committee.counts.resolutions }}</div><div class="s-trend">{{ t('passed &amp; archived (spec 3.11)') }}</div></div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openMemberAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('＋ Add member') }}</button>
        <button v-if="canManage" @click="openMeetingAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--ok);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('📅 Log meeting') }}</button>
        <button v-if="canManage" @click="openResAdd()" style="padding:9px 14px;border:none;border-radius:10px;background:var(--bg-alt);border:1px solid var(--border);color:var(--text);font-size:12.5px;font-weight:800;cursor:pointer">{{ t('📜 Add resolution') }}</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">{{ config.mall_name || 'Mall' }} Market Owners' Committee — term 2024–2026</span>
      </div>
      <!-- office bearers grid -->
      <div v-if="committee && committee.members.length" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px;margin-bottom:16px">
        <div v-for="m in committee.members" :key="m.id" class="panel chip" style="padding:15px;display:flex;gap:12px;align-items:center;cursor:pointer" @click="openMemberDrawer(m)">
          <div style="width:44px;height:44px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:#fff" :style="{ background: memberColor(m) }">{{ memberAvatar(m) }}</div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:800;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ m.name }}</div>
            <div style="display:flex;align-items:center;gap:6px;margin-top:3px;flex-wrap:wrap">
              <span class="badge" :class="{ Chairman: 'b-red', 'Vice Chairman': 'b-orange', Secretary: 'b-blue', Treasurer: 'b-green', Member: 'b-gray' }[m.role] || 'b-gray'">{{ m.role }}</span>
              <span v-if="!m.active" class="badge b-red" style="font-size:10px">{{ t('inactive') }}</span>
            </div>
            <div style="font-size:11px;color:var(--text-mute);margin-top:4px">{{ m.shop ? 'Space ' + m.shop : 'Independent' }}<span v-if="m.phone"> · {{ m.phone }}</span></div>
          </div>
          <div v-if="canManage" style="display:flex;flex-direction:column;gap:4px;flex-shrink:0" @click.stop>
            <button @click="openMemberEdit(m)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:4px 8px;cursor:pointer;font-size:11px">✏️</button>
            <button @click="delMember(m)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:4px 8px;cursor:pointer;font-size:11px">🗑️</button>
          </div>
        </div>
      </div>
      <div v-else class="panel" style="padding:24px;text-align:center;color:var(--text-mute);margin-bottom:16px">No committee members yet — add the chairman, secretary &amp; treasurer with ＋ Add member.</div>

      <!-- meetings + resolutions -->
      <div style="display:grid;grid-template-columns:1.15fr 1fr;gap:16px" class="cm-grid">
        <div class="panel" style="padding:16px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h3 style="font-size:14px">📅 Meeting register</h3>
            <span v-if="committee" class="badge b-blue" style="font-size:11px">{{ committee.counts.meetings }} meetings</span>
          </div>
          <div v-if="committee && committee.meetings.length" style="display:flex;flex-direction:column;gap:10px">
            <div v-for="m in committee.meetings" :key="m.id" style="border:1px solid var(--border);border-radius:12px;padding:12px 14px">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span class="badge" :class="{ AGM: 'b-red', Executive: 'b-blue', Emergency: 'b-orange', Budget: 'b-green' }[m.type] || 'b-gray'">{{ m.type }}</span>
                <b style="font-size:13px">{{ m.title }}</b>
                <span style="font-size:11.5px;color:var(--text-mute)">📅 {{ m.date }}</span>
                <span style="flex:1"></span>
                <button v-if="canManage" @click="delMeeting(m)" title="Delete meeting" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:4px 8px;cursor:pointer;font-size:11px">🗑️</button>
              </div>
              <div v-if="m.agenda" style="font-size:12px;color:var(--text-mute);margin-top:6px"><b>{{ t('Agenda:') }}</b> {{ m.agenda }}</div>
              <div v-if="m.decisions" style="font-size:12px;margin-top:4px"><b>{{ t('Decisions:') }}</b> {{ m.decisions }}</div>
              <div v-if="m.minutes" style="font-size:12px;color:var(--text-mute);margin-top:4px;white-space:pre-wrap">{{ m.minutes }}</div>
              <div style="font-size:10.5px;color:var(--text-mute);margin-top:6px">recorded by {{ m.created_by }}</div>
            </div>
          </div>
          <p v-else style="color:var(--text-mute);font-size:12.5px">No meetings logged yet — record AGM &amp; executive meetings with 📅 Log meeting.</p>
        </div>
        <div class="panel" style="padding:16px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h3 style="font-size:14px">📜 Resolutions</h3>
            <span v-if="committee" class="badge b-green" style="font-size:11px">{{ committee.counts.resolutions }} archived</span>
          </div>
          <div v-if="committee && committee.resolutions.length" style="display:flex;flex-direction:column;gap:10px">
            <div v-for="r in committee.resolutions" :key="r.id" style="border:1px solid var(--border);border-radius:12px;padding:12px 14px">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span class="badge b-gray" style="font-size:10.5px">{{ r.number }}</span>
                <b style="font-size:13px">{{ r.title }}</b>
                <span style="font-size:11.5px;color:var(--text-mute)">{{ r.date }}</span>
                <span style="flex:1"></span>
                <span class="badge" :class="r.passed ? 'b-green' : 'b-red'" style="font-size:10px">{{ r.passed ? 'passed' : 'not passed' }}</span>
                <button v-if="canManage" @click="delResolution(r)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:4px 8px;cursor:pointer;font-size:11px">🗑️</button>
              </div>
              <div v-if="r.body" style="font-size:12px;color:var(--text-mute);margin-top:6px">{{ r.body }}</div>
              <div v-if="r.meeting_id" style="font-size:10.5px;color:var(--text-mute);margin-top:5px">from meeting #{{ r.meeting_id }}</div>
            </div>
          </div>
          <p v-else style="color:var(--text-mute);font-size:12.5px">No resolutions yet — AGM resolutions are archived here as the governance record (spec 3.11).</p>
        </div>
      </div>
    </template>

    <!-- ═══════ OWNERS / OWNERSHIP ═══════ -->
    <template v-if="tab === 'owners'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🏢</span>{{ t('Owners') }}</div><div class="s-value">{{ ownerCounts.total || owners.length }}</div><div class="s-trend">persons + entities</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🏭</span>{{ t('Companies / entities') }}</div><div class="s-value">{{ ownerCounts.companies || 0 }}</div><div class="s-trend">{{ t('company, bank, trust…') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🏪</span>{{ t('Spaces owned') }}</div><div class="s-value">{{ owners.reduce((s, o) => s + o.shops, 0) }}</div><div class="s-trend">{{ t('one owner can own many') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">👥</span>{{ t('Multi-space owners') }}</div><div class="s-value">{{ owners.filter(o => o.shops > 1).length }}</div><div class="s-trend">{{ t('portfolio owners') }}</div></div>
      </div>
      <div style="display:flex;gap:10px;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openOwnerAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('＋ Add owner') }}</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Flexible ownership — a building can be owned by one person, many persons, or companies/banks. Owner-occupied spaces only bear the service charge.</span>
      </div>
      <div v-if="owners.length" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px">
        <div v-for="o in owners" :key="o.id" class="panel chip" style="padding:15px;display:flex;gap:12px;align-items:center;cursor:pointer" @click="openOwnerDrawer(o)">
          <div style="width:44px;height:44px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff" :style="{ background: memberColor({ id: o.id, name: o.name }) }">{{ memberAvatar({ name: o.name }) }}</div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:800;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ o.name }}</div>
            <div style="display:flex;align-items:center;gap:6px;margin-top:3px;flex-wrap:wrap">
              <span class="badge" :class="{ Person: 'b-blue', Company: 'b-red', Bank: 'b-green', NGO: 'b-orange', Trust: 'b-purple' }[o.type] || 'b-gray'">{{ o.type }}</span>
              <span v-if="o.shops > 1" class="badge b-orange" style="font-size:10px">{{ o.shops }} spaces</span>
              <span v-else-if="o.shops === 1" class="badge b-gray" style="font-size:10px">1 space</span>
            </div>
            <div style="font-size:11px;color:var(--text-mute);margin-top:4px">{{ o.phone }}<span v-if="o.trade_license"> · TL {{ o.trade_license }}</span></div>
          </div>
          <div v-if="canManage" style="display:flex;flex-direction:column;gap:4px;flex-shrink:0" @click.stop>
            <button @click="openOwnerEdit(o)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:4px 8px;cursor:pointer;font-size:11px">✏️</button>
            <button @click="delOwner(o)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:4px 8px;cursor:pointer;font-size:11px">🗑️</button>
          </div>
        </div>
      </div>
      <div v-else class="panel" style="padding:24px;text-align:center;color:var(--text-mute)">No owners yet — add persons &amp; entities, then assign spaces in 🏪 Spaces.</div>
    </template>

    <!-- ═══════ RENT & TENANTS ═══════ -->
    <template v-if="tab === 'rent'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🧑‍🤝‍🧑</span>{{ t('Tenants') }}</div><div class="s-value">{{ tenants.length }}</div><div class="s-trend">{{ t('occupants (KRTaker-style profile)') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📄</span>{{ t('Rental agreements') }}</div><div class="s-value">{{ agreements.length }}</div><div class="s-trend">{{ agreements.filter(a => a.rent_collection).length }} with rent collection</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💵</span>{{ t('Rent collected') }}</div><div class="s-value" style="color:var(--ok)">{{ money(rentStats.collected) }}</div><div class="s-trend">{{ t('optional service for owners') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⏳</span>{{ t('Rent outstanding') }}</div><div class="s-value" :style="rentStats.outstanding > 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(rentStats.outstanding) }}</div><div class="s-trend">{{ t('due months × rent') }}</div></div>
      </div>
      <div style="display:flex;gap:10px;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openTenantAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('＋ Add tenant') }}</button>
        <button v-if="canManage" @click="openAgrAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--ok);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('📄 New agreement') }}</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Rent collection is an <b>{{ t('optional service') }}</b> — owners may collect rent themselves; the committee can manage it on request.</span>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1.35fr;gap:16px" class="rt-grid">
        <div class="panel" style="padding:16px">
          <h3 style="font-size:14px;margin-bottom:10px">🧑‍🤝‍🧑 Tenants / occupants</h3>
          <div v-if="tenants.length" style="display:flex;flex-direction:column;gap:8px">
            <div v-for="t in tenants" :key="t.id" style="border:1px solid var(--border);border-radius:12px;padding:11px 13px;display:flex;gap:10px;align-items:center;cursor:pointer" @click="openTenantDrawer(t)">
              <div style="width:34px;height:34px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff" :style="{ background: memberColor({ id: t.id, name: t.name }) }">{{ memberAvatar({ name: t.name }) }}</div>
              <div style="flex:1;min-width:0">
                <div style="font-weight:800;font-size:13px">{{ t.name }}</div>
                <div style="font-size:11px;color:var(--text-mute)">{{ t.phone }}<span v-if="t.nid"> · NID {{ t.nid }}</span><span v-if="t.employer"> · {{ t.employer }}</span></div>
                <span v-if="t.agreements" class="badge b-blue" style="font-size:10px;margin-top:3px">{{ t.agreements }} active agreement(s)</span>
              </div>
              <div v-if="canManage" style="display:flex;flex-direction:column;gap:4px;flex-shrink:0" @click.stop>
                <button @click="openTenantEdit(t)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:4px 8px;cursor:pointer;font-size:11px">✏️</button>
                <button @click="delTenant(t)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:4px 8px;cursor:pointer;font-size:11px">🗑️</button>
              </div>
            </div>
          </div>
          <p v-else style="color:var(--text-mute);font-size:12.5px">{{ t('No tenants yet.') }}</p>
        </div>
        <div class="panel" style="padding:16px">
          <h3 style="font-size:14px;margin-bottom:10px">📄 Rental agreements</h3>
          <div v-if="agreements.length" style="display:flex;flex-direction:column;gap:9px">
            <div v-for="a in agreements" :key="a.id" style="border:1px solid var(--border);border-radius:12px;padding:12px 14px">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <b style="font-size:13px">{{ a.shop }}</b>
                <span class="badge" :class="{ Active: 'b-green', 'Exit-Requested': 'b-orange', Exited: 'b-gray', Expired: 'b-gray', Terminated: 'b-red' }[a.status] || 'b-gray'">{{ bnd(a.status) }}</span>
                <span v-if="a.noc_no" class="badge b-blue" style="font-size:10px">📄 {{ a.noc_no }}</span>
                <span v-if="a.rent_collection" class="badge b-blue" style="font-size:10px">{{ t('committee collects rent') }}</span>
                <span v-else class="badge b-gray" style="font-size:10px">{{ t('owner collects') }}</span>
                <span style="flex:1"></span>
                <span style="font-weight:800;font-size:13px">{{ money(a.rent) }}/mo</span>
              </div>
              <div style="font-size:11.5px;color:var(--text-mute);margin-top:5px"><span class="elink" @click.stop="linkTenant(a)">{{ a.tenant_name || '—' }}</span> · {{ a.start_date }}<span v-if="a.end_date"> → {{ a.end_date }}</span><span v-if="a.advance_months"> · {{ a.advance_months }} mo advance</span></div>
              <div v-if="a.shop_due > 0" style="font-size:11.5px;font-weight:800;color:var(--danger);margin-top:6px">⚠️ Shop outstanding: {{ money(a.shop_due) }} — NOC blocked until settled</div>
              <div style="display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap">
                <span v-if="a.rent_collection" style="font-size:12px;font-weight:700" :style="a.rent_due > 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ a.due_months }} mo due · {{ money(a.rent_due) }}</span>
                <span v-if="a.rent_collection" style="font-size:11px;color:var(--text-mute)">{{ a.paid_months }} mo paid</span>
                <button v-if="canManage && a.rent_collection && a.status === 'Active'" @click="openRentCollect(a)" style="padding:7px 13px;border:none;border-radius:9px;background:var(--ok);color:#fff;font-size:12px;font-weight:800;cursor:pointer">{{ t('💵 Collect rent') }}</button>
                <span style="flex:1"></span>
                <button v-if="canManage && a.status === 'Active'" @click="exitRequest(a)" style="padding:7px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:11.5px;font-weight:800;cursor:pointer">{{ t('🚪 Exit request') }}</button>
                <button v-if="canManage && a.status === 'Exit-Requested'" @click="exitApprove(a)" style="padding:7px 12px;border:none;border-radius:9px;background:var(--primary);color:#fff;font-size:11.5px;font-weight:800;cursor:pointer">{{ t('✅ Approve exit + NOC') }}</button>
                <button v-if="a.status === 'Exited' && a.noc_no" @click="printNoc(a)" style="padding:7px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:11.5px;font-weight:800;cursor:pointer">{{ t('🖨️ NOC') }}</button>
                <button v-if="canManage && a.status === 'Active'" @click="delAgreement(a)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:6px 9px;cursor:pointer;font-size:11px">🗑️</button>
              </div>
            </div>
          </div>
          <p v-else style="color:var(--text-mute);font-size:12.5px">{{ t('No rental agreements — add one to track the occupant, rent &amp; term.') }}</p>
        </div>
      </div>
    </template>

    <!-- ═══════ VENDORS ═══════ -->
    <template v-if="tab === 'vendors'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🧰</span>{{ t('Vendors') }}</div><div class="s-value">{{ vendors.length }}</div><div class="s-trend">profiles + ledgers</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💸</span>{{ t('Total paid') }}</div><div class="s-value" style="color:var(--danger)">{{ money(vendorsTotal) }}</div><div class="s-trend">{{ t('payment tracking') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🔧</span>{{ t('Categories') }}</div><div class="s-value">{{ new Set(vendors.map(v => v.category).filter(Boolean)).size }}</div><div class="s-trend">{{ t('security, lift, AC…') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📑</span>{{ t('Payments') }}</div><div class="s-value">{{ vendors.reduce((s, v) => s + v.payments, 0) }}</div><div class="s-trend">{{ t('every payment tracked') }}</div></div>
      </div>
      <div style="display:flex;gap:10px;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openVendorAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('＋ Add vendor') }}</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Vendor profile · payment ledger · every payout tracked with method &amp; reference.</span>
      </div>
      <div v-if="vendors.length" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px">
        <div v-for="v in vendors" :key="v.id" class="panel chip" style="padding:15px;display:flex;gap:12px;align-items:center;cursor:pointer" @click="openVendorDrawer(v)">
          <div style="width:44px;height:44px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff" :style="{ background: memberColor({ id: v.id, name: v.name }) }">{{ memberAvatar({ name: v.name }) }}</div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:800;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ v.name }}</div>
            <div style="display:flex;align-items:center;gap:6px;margin-top:3px;flex-wrap:wrap">
              <span class="badge b-gray" style="font-size:10px">{{ v.category }}</span>
              <span class="badge b-red" style="font-size:10px">{{ money(v.paid) }} paid</span>
            </div>
            <div style="font-size:11px;color:var(--text-mute);margin-top:4px">{{ v.contact_person ? v.contact_person + ' · ' : '' }}{{ v.phone }}<span v-if="v.payments"> · {{ v.payments }} payments</span></div>
          </div>
          <div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0" @click.stop>
            <button v-if="canManage" @click="openVendorPay(v)" style="padding:6px 10px;border:none;border-radius:8px;background:var(--ok);color:#fff;font-size:11.5px;font-weight:800;cursor:pointer">{{ t('💸 Pay') }}</button>
            <div v-if="canManage" style="display:flex;gap:4px">
              <button @click="openVendorEdit(v)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:3px 7px;cursor:pointer;font-size:11px">✏️</button>
              <button @click="delVendor(v)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:3px 7px;cursor:pointer;font-size:11px">🗑️</button>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="panel" style="padding:24px;text-align:center;color:var(--text-mute)">No vendors yet — security agencies, lift/AC contractors, cleaners &amp; suppliers.</div>
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
            <div class="s-label"><span class="s-ico">📉</span>{{ t('Expenses') }}</div>
            <div class="s-value" style="color:var(--danger);font-size:18px">{{ money(ledger.expenses) }}</div>
            <div class="s-trend">{{ t('all categories') }}</div>
          </div>
        </div>
        <div class="panel" style="padding:16px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <h3 style="font-size:14px">🏪 Per-space ledger — {{ monthLabel(ledger.month) }}</h3>
            <div style="display:flex;gap:8px;align-items:center">
              <span class="badge b-green" style="font-size:12px">Net balance {{ money(Number(ledger.by_kind.reduce((s, k) => s + k.collected, 0)) - Number(ledger.expenses)) }}</span>
              <button @click="exportLedger" class="btn-ghost" style="font-size:12px">{{ t('⬇ CSV') }}</button>
              <button @click="printTable('Ledger — ' + monthLabel(ledger.month) + ' — ' + (config.mall_name || 'Mall'), $refs.ledgerTbl)" class="btn-ghost" style="font-size:12px">{{ t('🖨️ Print') }}</button>
            </div>
          </div>
          <div class="tbl-wrap" style="max-height:420px">
            <table class="kr" ref="ledgerTbl">
              <thead><tr><th>{{ t('Space') }}</th><th>{{ t('Owner') }}</th><th style="text-align:right">{{ t('Service') }}</th><th style="text-align:right">{{ t('Elec') }}</th><th style="text-align:right">{{ t('Water') }}</th><th style="text-align:right">{{ t('Total due') }}</th><th>{{ t('Status') }}</th></tr></thead>
              <tbody>
                <tr v-for="s in ledger.per_shop" :key="s.id">
                  <td><b>{{ s.no }}</b> <small style="color:var(--text-mute)">· {{ s.floor }}</small></td>
                  <td><span class="elink" @click.stop="linkOwner(s)">{{ s.owner_name || '—' }}</span></td>
                  <td style="text-align:right">{{ money(s.sc_paid) }}<small style="color:var(--text-mute)"> / {{ money(s.sc_billed) }}</small></td>
                  <td style="text-align:right">{{ money(s.el_paid) }}<small style="color:var(--text-mute)"> / {{ money(s.el_billed) }}</small></td>
                  <td style="text-align:right">{{ money(s.w_paid) }}<small style="color:var(--text-mute)"> / {{ money(s.w_billed) }}</small></td>
                  <td style="text-align:right;font-weight:800" :style="(s.sc_billed - s.sc_paid) + (s.el_billed - s.el_paid) + (s.w_billed - s.w_paid) > 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money((s.sc_billed - s.sc_paid) + (s.el_billed - s.el_paid) + (s.w_billed - s.w_paid)) }}</td>
                  <td><span class="badge" :class="(s.sc_billed - s.sc_paid) + (s.el_billed - s.el_paid) + (s.w_billed - s.w_paid) > 0 ? 'b-orange' : 'b-green'">{{ (s.sc_billed - s.sc_paid) + (s.el_billed - s.el_paid) + (s.w_billed - s.w_paid) > 0 ? 'Due' : 'Clear' }}</span></td>
                </tr>
                <tr v-if="!ledger.per_shop.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:24px">{{ t('No shops yet.') }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <!-- ═══════ CUSTODIAL RECONCILIATION (spec 3.3) ═══════ -->
      <div v-if="recon" class="panel" style="padding:16px;margin-top:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">
          <h3 style="font-size:14px">⚡💧 Custodial fund reconciliation — DESCO/WASA (spec 3.3)</h3>
          <span class="badge" :class="recon.current_balance >= 0 ? 'b-green' : 'b-orange'" style="font-size:12px">{{ monthLabel(recon.month) }}: {{ recon.current_balance >= 0 ? 'forward ' : 'shortfall ' }}{{ money(Math.abs(recon.current_balance)) }}</span>
        </div>
        <p style="font-size:12px;color:var(--text-mute);margin-bottom:12px">Space collections for electricity &amp; water are <b>{{ t('custodial') }}</b> — collected from space owners, forwarded to the utility. Compare with the DESCO / WASA main bills paid.</p>
        <div class="tbl-wrap" style="max-height:300px">
          <table class="kr">
            <thead><tr><th></th><th style="text-align:right">{{ t('Elec collected') }}</th><th style="text-align:right">{{ t('Water collected') }}</th><th style="text-align:right">{{ t('DESCO bill paid') }}</th><th style="text-align:right">{{ t('WASA bill paid') }}</th><th style="text-align:right">{{ t('Balance') }}</th></tr></thead>
            <tbody>
              <tr>
                <td><b>{{ monthLabel(recon.month) }}</b></td>
                <td style="text-align:right">{{ money(recon.current.elec_collected) }}</td>
                <td style="text-align:right">{{ money(recon.current.water_collected) }}</td>
                <td style="text-align:right">{{ money(recon.current.desco_paid) }}</td>
                <td style="text-align:right">{{ money(recon.current.wasa_paid) }}</td>
                <td style="text-align:right;font-weight:800" :style="recon.current_balance >= 0 ? 'color:var(--ok)' : 'color:var(--danger)'">{{ money(recon.current_balance) }}</td>
              </tr>
              <tr style="background:var(--bg-alt)">
                <td><b>{{ t('All time') }}</b></td>
                <td style="text-align:right">{{ money(recon.all_time.elec_collected) }}</td>
                <td style="text-align:right">{{ money(recon.all_time.water_collected) }}</td>
                <td style="text-align:right">{{ money(recon.all_time.desco_paid) }}</td>
                <td style="text-align:right">{{ money(recon.all_time.wasa_paid) }}</td>
                <td style="text-align:right;font-weight:800" :style="recon.all_time_balance >= 0 ? 'color:var(--ok)' : 'color:var(--danger)'">{{ money(recon.all_time_balance) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <p v-else style="color:var(--text-mute)">{{ t('Loading ledger…') }}</p>
    </template>

    <!-- ═══════ SETTINGS ═══════ -->
    <template v-if="tab === 'settings'">
      <!-- ═══ redesigned: sticky tab bar + save (scrolls horizontally on mobile) ═══ -->
      <div style="position:sticky;top:0;z-index:40;background:var(--bg);padding:10px 0 12px;display:flex;gap:7px;align-items:center;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch" class="set-tabs">
        <button v-for="st in SETTINGS_TABS" :key="st.id" @click="settingsTab = st.id" :style="settingsTab === st.id ? 'background:var(--primary);color:#fff;box-shadow:0 4px 14px rgba(47,128,237,.35)' : 'background:var(--bg-alt);color:var(--text-mute);border:1px solid var(--border)'" style="padding:8px 14px;border-radius:99px;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap;flex-shrink:0;transition:all .15s">{{ st.ic }} {{ t(st.label) }}</button>
        <button v-if="canManage && cfgDirty" @click="saveConfig" style="margin-left:auto;padding:8px 16px;border:none;border-radius:99px;background:var(--ok,#27AE60);color:#fff;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap;flex-shrink:0">{{ t('💾 Save changes') }}</button>
      </div>
      <div v-if="canManage" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px">
        <div v-if="settingsTab === 'profile'" class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:12px">🏬 Mall profile</h3>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Mall name') }}
            <input v-model="config.mall_name" :placeholder="t('e.g. Razzak Plaza')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Address') }}
            <input v-model="config.mall_address" :placeholder="t('e.g. 42 Motijheel C/A, Dhaka 1000')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
          </label>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-top:10px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Phone') }}
              <input v-model="config.mall_phone" :placeholder="t('e.g. 02-9551234')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Email') }}
              <input v-model="config.mall_email" :placeholder="t('office@razzakplaza.com')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Chairman') }}
              <input v-model="config.chairman" :placeholder="t('e.g. Alhaj Md. Abdul Razzak')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Secretary') }}
              <input v-model="config.secretary" :placeholder="t('e.g. Md. Shahidullah')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
          </div>
        </div>
        <div v-if="settingsTab === 'billing'" class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:4px">⚡ Utility costing (manual)</h3>
          <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">Set the utility rates manually — they apply when a sub-meter reading generates the electricity / water bill.</p>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Elec rate (৳/unit)') }}
              <input type="number" v-model.number="config.elec_unit_rate" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Water rate (৳/unit)') }}
              <input type="number" v-model.number="config.water_unit_rate" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Due day of month') }}
              <input type="number" v-model.number="config.due_day" min="1" max="28" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('🚨 High-dues alert after (months)') }}
              <input type="number" v-model.number="config.high_dues_months" min="1" max="12" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('⛔ Disconnection risk after (months)') }}
              <input type="number" v-model.number="config.disconnect_months" min="1" max="12" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
          </div>
          <!-- spec 3.3: effective elec rate calculator -->
          <div style="margin-top:14px;border:1px dashed var(--border);border-radius:12px;padding:13px 15px;background:rgba(47,128,237,.04)">
            <div style="font-size:12.5px;font-weight:800;margin-bottom:6px">⚡ Effective elec rate calculator <small style="color:var(--text-mute);font-weight:400">(spec 3.3 — DESCO main bill ÷ total sub-meter units)</small></div>
            <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:10px">Put this month's DESCO main bill (unit charge + demand + VAT + system loss) in — the system pulls the month's total sub-meter units and suggests a shop rate slightly above the effective cost.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
              <input v-model.number="effCalc.main_bill" type="number" min="0" :placeholder="t('DESCO main bill ৳…')" style="flex:1;min-width:160px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
              <input v-model="effCalc.month" type="month" style="padding:9px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12.5px" />
              <button @click="calcEffectiveRate" style="padding:10px 16px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('Calculate') }}</button>
            </div>
            <div v-if="effCalc.result" style="margin-top:12px;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
              <span style="font-size:12.5px">Total units: <b>{{ effCalc.result.total_units.toLocaleString('en-IN') }}</b> · Effective cost: <b style="color:var(--primary)">৳{{ effCalc.result.effective_rate }}/unit</b></span>
              <span v-for="s in effCalc.result.suggested" :key="s.margin" style="display:inline-flex;align-items:center;gap:6px;background:var(--card);border:1px solid var(--border);border-radius:99px;padding:4px 6px 4px 12px;font-size:12px">
                <b>{{ s.margin }}% margin → ৳{{ s.rate }}</b>
                <button @click="useEffRate(s.rate)" style="border:none;background:var(--primary);color:#fff;border-radius:99px;padding:4px 10px;font-size:11px;font-weight:800;cursor:pointer">{{ t('Use') }}</button>
              </span>
            </div>
          </div>
        </div>
        <div v-if="settingsTab === 'billing'" class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:4px">💳 Rent &amp; statements config</h3>
          <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">{{ t('Defaults used when creating tenant agreements and printed statements.') }}</p>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Advance months (default)') }}
              <input type="number" v-model.number="config.rent_advance_default" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Rent due day of month') }}
              <input type="number" v-model.number="config.rent_due_day" min="1" max="28" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute);grid-column:1/-1">{{ t('Statement footer note') }}
              <input v-model="config.rent_statement_note" placeholder="e.g. Please pay within the due date — 5% late fee applies after the grace period." style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
          </div>
        </div>
        <div v-if="settingsTab === 'billing'" class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:4px">🧾 Service billing config</h3>
          <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">How occupants are charged: fixed flat rate, per sqft, or with metered utilities folded into the service bill. Per-space overrides live on each space.</p>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Default billing model') }}
              <select v-model="config.bill_model_default" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @change="cfgDirty = true">
                <option value="fixed">{{ t('Fixed (flat monthly)') }}</option>
                <option value="sqft">{{ t('Per sqft (rate × size)') }}</option>
                <option value="fixed+util">{{ t('Fixed + utilities') }}</option>
                <option value="sqft+util">{{ t('Per sqft + utilities') }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Default flat rate (৳/mo)') }}
              <input type="number" v-model.number="config.rate_default" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Default per-sqft rate (৳/sqft/mo)') }}
              <input type="number" v-model.number="config.rate_sqft_default" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">🔌 Common monthly utilities / expenses</div>
              <p style="font-size:11px;color:var(--text-mute);margin-bottom:8px">Generator fuel, common-area electricity, society membership, waste management, lift contract, internet, security, TV… — these become expense categories.</p>
              <div style="display:flex;flex-wrap:wrap;gap:6px">
                <span v-for="(h, i) in config.util_heads || []" :key="h" class="badge b-blue" style="font-size:11px">{{ h }} <button @click="config.util_heads.splice(i, 1); cfgDirty = true" style="border:none;background:none;color:inherit;cursor:pointer;font-weight:800">✕</button></span>
              </div>
              <div style="display:flex;gap:6px;margin-top:8px">
                <input v-model="utilHeadInput" :placeholder="t('Add head…')" style="flex:1;padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12px" @keydown.enter="addUtilHead" />
                <button @click="addUtilHead" class="btn-ghost" style="font-size:12px">{{ t('＋ Add') }}</button>
              </div>
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">💰 Additional income heads</div>
              <p style="font-size:11px;color:var(--text-mute);margin-bottom:8px">Parking fee, community hall / common space rent, advertisement… — these become income categories.</p>
              <div style="display:flex;flex-wrap:wrap;gap:6px">
                <span v-for="(h, i) in config.income_heads || []" :key="h" class="badge b-green" style="font-size:11px">{{ h }} <button @click="config.income_heads.splice(i, 1); cfgDirty = true" style="border:none;background:none;color:inherit;cursor:pointer;font-weight:800">✕</button></span>
              </div>
              <div style="display:flex;gap:6px;margin-top:8px">
                <input v-model="incomeHeadInput" :placeholder="t('Add head…')" style="flex:1;padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12px" @keydown.enter="addIncomeHead" />
                <button @click="addIncomeHead" class="btn-ghost" style="font-size:12px">{{ t('＋ Add') }}</button>
              </div>
            </div>
          </div>
        </div>
        <div v-if="settingsTab === 'fines'" class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:4px">⚖️ Late fees &amp; fines (manual configuration)</h3>
          <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">{{ t('Full control over the late-payment fine rules engine.') }}</p>
          <div style="display:flex;align-items:center;gap:9px;margin-bottom:10px">
            <label style="font-size:12px;color:var(--text-mute);display:flex;align-items:center;gap:9px;cursor:pointer">
              <span class="lf-switch" :class="{ on: !!config.late_fees_enabled }" @click="config.late_fees_enabled = config.late_fees_enabled ? 0 : 1; cfgDirty = true" style="width:40px;height:22px;border-radius:99px;background:config.late_fees_enabled ? 'var(--ok,#27AE60)' : 'var(--border,#cbd5e1)';position:relative;transition:background .15s;flex-shrink:0">
                <span style="position:absolute;top:2px;left:config.late_fees_enabled ? '20px' : '2px';width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:left .15s"></span>
              </span>
              <b :style="config.late_fees_enabled ? '' : 'color:var(--danger)'">{{ config.late_fees_enabled ? 'Late fees ON' : 'Late fees OFF' }}</b>
            </label>
          </div>
          <div v-if="config.late_fees_enabled" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Fine rate (% of bill)') }}
              <input type="number" v-model.number="config.late_fee_pct" min="0" max="100" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Grace days (after due date)') }}
              <input type="number" v-model.number="config.late_fee_grace" min="0" max="60" title="Days after the due date before a fine applies" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Minimum fine (৳)') }}
              <input type="number" v-model.number="config.late_fee_min" min="0" title="Even small bills pay at least this fine" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Max cap (% of bill)') }}
              <input type="number" v-model.number="config.late_fee_max_pct" min="1" max="100" title="A fine can never exceed this % of the bill" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
          </div>
          <p style="font-size:11.5px;color:var(--text-mute);margin-top:10px">💡 Fines auto-apply to unpaid bills past the due date (+ grace) when you press <b>{{ t('💸 Compute late fees') }}</b> on the Bills tab. Rounded to the nearest ৳5.</p>
        </div>
        <div v-if="settingsTab === 'sms'" class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:4px">📱 SMS &amp; notifications (KRTaker engine)</h3>
          <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">Auto SMS receipt confirmation to shop owners &amp; tenants on every collection, plus reminders and alerts. <b>{{ t('Log') }}</b> provider just records messages (testing); <b>{{ t('bulksmsbd') }}</b> sends for real.</p>
          <div style="display:flex;align-items:center;gap:9px;margin-bottom:12px">
            <span class="lf-switch" :class="{ on: !!smsCfg.enabled }" @click="smsCfg.enabled = smsCfg.enabled ? 0 : 1" style="width:44px;height:24px;border-radius:99px;background:smsCfg.enabled ? 'var(--ok,#27AE60)' : 'var(--border,#cbd5e1)';position:relative;transition:background .15s;cursor:pointer;flex-shrink:0">
              <span style="position:absolute;top:2px;left:smsCfg.enabled ? '22px' : '2px';width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:left .15s"></span>
            </span>
            <b :style="smsCfg.enabled ? 'color:var(--ok)' : ''">{{ smsCfg.enabled ? 'SMS enabled' : 'SMS disabled' }}</b>
            <button @click="saveSmsCfg" style="margin-left:auto;padding:8px 16px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('💾 Save') }}</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Provider') }}
              <select v-model="smsCfg.provider" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option value="log">{{ t('Log (testing — no real send)') }}</option>
                <option value="bulksmsbd">{{ t('bulksmsbd (real gateway)') }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Sender ID') }}
              <input v-model="smsCfg.sender_id" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('API URL') }}
              <input v-model="smsCfg.api_url" :placeholder="t('https://api.bulksmsbd.com/smsapi')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('📨 Recipients') }} <small style="color:var(--text-mute)">(spec 3.1 — who gets SMS receipts &amp; alerts)</small>
              <select v-model="smsCfg.recipients" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option value="both">👥 Owner &amp; tenant (both)</option>
                <option value="owner">{{ t('👤 Owner only') }}</option>
                <option value="tenant">{{ t('🧑‍💼 Tenant only') }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute);grid-column:1/-1">{{ t('API key') }}
              <input v-model="smsCfg.api_key" :placeholder="smsCfg.masked ? '•••• saved — type to replace' : ''" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </div>
          <div style="display:flex;gap:8px;margin-top:12px;align-items:center">
            <input v-model="smsTestPhone" :placeholder="t('Test phone (01XXXXXXXXX)…')" style="flex:1;padding:9px 11px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12.5px" />
            <button @click="sendTestSms" class="btn-ghost" style="font-size:12px">{{ t('📤 Send test') }}</button>
          </div>
          <div v-if="smsCfg.log && smsCfg.log.length" style="margin-top:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">{{ t('Recent SMS log') }}</div>
            <div style="display:flex;flex-direction:column;gap:5px;max-height:160px;overflow-y:auto">
              <div v-for="l in smsCfg.log" :key="l.id" style="display:flex;justify-content:space-between;gap:8px;font-size:11px;padding:6px 9px;border-radius:8px;background:var(--bg-alt)">
                <span style="color:var(--text-mute)">{{ l.ts }} · {{ l.to_phone }}</span>
                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" :title="l.message">{{ l.message }}</span>
                <b :style="l.status === 'sent' ? 'color:var(--ok)' : 'color:var(--danger)'">{{ l.status }}</b>
              </div>
            </div>
          </div>
        </div>
        <div v-if="settingsTab === 'accounts'" class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:4px">📊 Account mapping (Smart Ledger)</h3>
          <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">Choose which COA account each expense category, vendor category, payment method, income head and Smart Ledger flow posts to automatically. The <b>— default —</b> option keeps the built-in rule (shown in brackets); overrides apply to every new posting.</p>
          <!-- default configurations at a glance: effective account per rule (blue = overridden) -->
          <div v-if="Object.keys(acctDefaultLabels).length" style="margin-bottom:14px;border:1px solid var(--border);border-radius:12px;overflow:hidden">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;padding:9px 12px;background:var(--bg-alt);border-bottom:1px solid var(--border)">🧾 Default configurations — effective account per rule <small style="text-transform:none;font-weight:400">(blue = overridden; change &amp; save below)</small></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:6px;padding:10px 12px">
              <div v-for="(label, k) in acctDefaultLabels" :key="k" style="display:flex;align-items:center;gap:8px;font-size:11.5px">
                <span style="color:var(--text-mute);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" :title="label">{{ label }}</span>
                <span class="badge" :class="acctMap[k] ? 'b-blue' : 'b-gray'" style="font-size:10px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" :title="acctMap[k] ? acctNameById(acctMap[k]) : (acctDefaults[k] || '—')">{{ acctMap[k] ? acctNameById(acctMap[k]) : (acctDefaults[k] || '—') }}</span>
              </div>
            </div>
          </div>
          <div v-for="g in MAP_GROUPS" :key="g.key" style="margin-bottom:12px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">{{ g.label }} <small style="font-weight:400;text-transform:none">({{ g.rows.length }})</small></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px">
              <label v-for="r in g.rows" :key="acctKey(g, r)" style="font-size:11.5px;color:var(--text-mute)">
                {{ acctLabel(g, r) }}
                <SearchableSelect :model-value="acctMap[acctKey(g, r)] ?? 0" :options="acctSelectOptions(g, r)" :placeholder="t('Account…')" @update:modelValue="v => acctMap[acctKey(g, r)] = v" />
              </label>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;margin-top:4px">
            <button @click="saveAcctMap" style="padding:9px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('💾 Save mapping') }}</button>
            <button @click="resetAcctMap" class="btn-ghost" style="font-size:12px">{{ t('♻️ Reset all to defaults') }}</button>
            <small style="color:var(--text-mute);font-size:11px">Saved rules: {{ Object.keys(acctMap).filter(k => acctMap[k]).length }}</small>
          </div>
        </div>
        <div v-if="settingsTab === 'governance'" class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:4px">🏛️ Committee roles (dynamic)</h3>
          <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">Manage the role list used when adding committee members — add, rename or remove roles freely.</p>
          <div v-if="!roleEdit" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
            <span v-for="r in committeeRoles.length ? committeeRoles : COMMITTEE_ROLES" :key="r" class="badge b-blue" style="font-size:11px">{{ r }}</span>
            <button v-if="canManage" @click="roleEdit = true" class="btn-ghost" style="font-size:12px;margin-left:auto">{{ t('✏️ Manage roles') }}</button>
          </div>
          <div v-else>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px">
              <span v-for="r in committeeRoles.length ? committeeRoles : COMMITTEE_ROLES" :key="r" style="display:inline-flex;align-items:center;gap:6px;background:var(--bg-alt);border:1px solid var(--border);border-radius:99px;padding:4px 8px 4px 12px;font-size:12px;font-weight:700">
                {{ r }} <button @click="delRole(r)" title="Remove role" style="border:none;background:var(--danger);color:#fff;width:16px;height:16px;border-radius:50%;font-size:10px;line-height:1;cursor:pointer;font-weight:800">✕</button>
              </span>
            </div>
            <div style="display:flex;gap:8px">
              <input v-model="roleDraft" @keydown.enter="addRole" :placeholder="t('New role, e.g. Auditor')" style="flex:1;padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none" />
              <button @click="addRole" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary-light);color:var(--primary-dark);font-size:12.5px;font-weight:800;cursor:pointer">{{ t('＋ Add') }}</button>
            </div>
            <div style="display:flex;gap:8px;margin-top:10px">
              <button @click="saveRoles" style="padding:9px 16px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('💾 Save roles') }}</button>
              <button @click="roleEdit = false" class="btn-ghost" style="font-size:12.5px">{{ t('Cancel') }}</button>
            </div>
          </div>
        </div>
        <div v-if="settingsTab === 'profile'" class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:4px">🖨️ Invoice settings &amp; property logo</h3>
          <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">Branding used on printed receipts — logo, template &amp; prefix. The sidebar keeps the product brand; the property name &amp; logo live on the document.</p>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px">
            <div>
              <div style="font-size:12px;color:var(--text-mute);margin-bottom:6px">{{ t('Logo (light background)') }}</div>
              <div style="display:flex;align-items:center;gap:10px">
                <div style="width:56px;height:56px;border-radius:10px;border:1px dashed var(--border);background:var(--bg-alt);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
                  <img v-if="config.mall_logo" :src="config.mall_logo" alt="logo" style="max-width:100%;max-height:100%;object-fit:contain" />
                  <span v-else style="font-size:18px;opacity:.4">🖼️</span>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px">
                  <label style="font-size:11.5px;color:var(--primary);font-weight:700;cursor:pointer">{{ t('⬆ Upload') }}<input type="file" accept="image/*" style="display:none" @change="onLogoPick($event, 'mall_logo')" /></label>
                  <button v-if="config.mall_logo" @click="removeLogo('mall_logo')" style="border:none;background:none;color:var(--danger);font-size:11px;cursor:pointer;text-align:left">{{ t('🗑 Remove') }}</button>
                </div>
              </div>
              <div style="font-size:10.5px;color:var(--text-mute);margin-top:5px">{{ t('White paper (minimal template), light areas') }}</div>
            </div>
            <div>
              <div style="font-size:12px;color:var(--text-mute);margin-bottom:6px">{{ t('Logo (dark background)') }}</div>
              <div style="display:flex;align-items:center;gap:10px">
                <div style="width:56px;height:56px;border-radius:10px;border:1px dashed var(--border);background:#1e3a5f;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
                  <img v-if="config.mall_logo_dark" :src="config.mall_logo_dark" alt="logo dark" style="max-width:100%;max-height:100%;object-fit:contain" />
                  <span v-else style="font-size:18px;opacity:.4">🖼️</span>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px">
                  <label style="font-size:11.5px;color:var(--primary);font-weight:700;cursor:pointer">{{ t('⬆ Upload') }}<input type="file" accept="image/*" style="display:none" @change="onLogoPick($event, 'mall_logo_dark')" /></label>
                  <button v-if="config.mall_logo_dark" @click="removeLogo('mall_logo_dark')" style="border:none;background:none;color:var(--danger);font-size:11px;cursor:pointer;text-align:left">{{ t('🗑 Remove') }}</button>
                </div>
              </div>
              <div style="font-size:10.5px;color:var(--text-mute);margin-top:5px">{{ t('Colored bands (classic/modern). Falls back to the normal logo.') }}</div>
            </div>
          </div>
          <div style="font-size:12px;color:var(--text-mute);margin:14px 0 6px">{{ t('Receipt template') }}</div>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
            <button v-for="t in INVOICE_TEMPLATES" :key="t.key" @click="config.invoice_template = t.key; cfgDirty = true"
              :title="t.desc" style="padding:10px 8px;border-radius:10px;cursor:pointer;font-size:11.5px;font-weight:800;text-align:center;font-family:inherit"
              :style="config.invoice_template === t.key ? 'background:var(--primary);color:#fff;border:2px solid var(--primary)' : 'background:var(--bg-alt);color:var(--text);border:2px solid var(--border)'">
              {{ t.name }}
            </button>
          </div>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:14px">{{ t('Receipt / invoice prefix') }}
            <input v-model="config.invoice_prefix" maxlength="8" :placeholder="t('RCT')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;font-weight:700;text-transform:uppercase" @input="cfgDirty = true" />
          </label>
        </div>
        <div v-if="settingsTab === 'governance'" class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:4px">🔑 License &amp; plan</h3>
          <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">The solution is sold as <b>{{ t('one-off, yearly subscription/license, or user/monthly') }}</b>. The <b>{{ t('super admin') }}</b> account is reserved for the vendor (Mall Manager by Deshik Lab) — the owning company, somity/committee or private owner manages day-to-day operations.</p>
          <div v-if="license" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Plan') }}
              <select v-model="license.plan" :disabled="!isSuperAdmin" @change="licenseDirty = true" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="p in ['One-off', 'Yearly', 'Monthly']" :key="p" :value="p">{{ bnd(p) }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Expiry') }}
              <input type="date" v-model="license.expiry" :disabled="!isSuperAdmin" @change="licenseDirty = true" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('User seats') }}
              <input type="number" v-model.number="license.seats" min="1" :disabled="!isSuperAdmin" @input="licenseDirty = true" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('License holder') }}
              <input v-model="license.holder" :disabled="!isSuperAdmin" placeholder="e.g. Razzak Plaza Owners' Committee" @input="licenseDirty = true" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </div>
          <div v-if="license" style="display:flex;align-items:center;gap:10px;margin-top:14px;flex-wrap:wrap">
            <span class="badge" :class="license.plan === 'One-off' ? 'b-green' : license.expiry && license.expiry < new Date().toISOString().slice(0, 10) ? 'b-red' : 'b-blue'" style="font-size:11px">{{ licenseBadge }}</span>
            <button v-if="isSuperAdmin && licenseDirty" @click="saveLicense" style="padding:9px 16px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('🔑 Save license') }}</button>
            <span v-if="!isSuperAdmin" style="font-size:11px;color:var(--text-mute)">🔒 Only the super admin (vendor) can change the license.</span>
          </div>
        </div>
        <div v-if="settingsTab === 'profile'" class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:12px">🏦 Bank details (shown on receipts)</h3>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Bank name') }}
            <input v-model="config.bank_name" :placeholder="t('e.g. Islami Bank Bangladesh PLC')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Account title') }}
            <input v-model="config.bank_account_title" placeholder="e.g. Razzak Plaza Owners' Committee" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Account number') }}
            <input v-model="config.bank_account_no" :placeholder="t('e.g. 205-123-4567')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
          </label>
        </div>
        <div v-if="settingsTab === 'profile'" class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:12px">🧾 Receipt note</h3>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Footer line on printed receipts') }}
            <textarea v-model="config.receipt_note" rows="3" placeholder="e.g. Service charges are payable by the 10th of every month. Thank you for your cooperation." style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical" @input="cfgDirty = true"></textarea>
          </label>
        </div>
        <div v-if="settingsTab === 'fines'" class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:4px">🎯 Monthly budget (spec 3.7)</h3>
          <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">Set a budget per expense category — the dashboard compares actual vs budget each month. Leave ৳0 to skip a category.</p>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px">
            <label v-for="c in EXP_CATEGORIES" :key="c" style="font-size:11.5px;color:var(--text-mute)">{{ c }}
              <input type="number" min="0" step="500" :value="budget[c] ?? 0" @input="budget[c] = Number($event.target.value) || 0; budgetDirty = true" style="width:100%;margin-top:3px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </div>
          <div style="display:flex;align-items:center;gap:10px;margin-top:14px">
            <button @click="saveBudget" :disabled="!budgetDirty" style="padding:10px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">{{ t('💾 Save budget') }}</button>
            <span style="font-size:12px;color:var(--text-mute)">Total {{ money(budgetTotal) }}/mo</span>
          </div>
        </div>
      </div>
      <div v-if="canManage && cfgDirty" style="margin-top:14px">
        <button @click="saveConfig" style="padding:11px 22px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💾 Save mall settings') }}</button>
        <span style="margin-left:10px;font-size:12px;color:var(--text-mute)">{{ t('Unsaved changes…') }}</span>
      </div>

      <!-- 👤 Profile management (own tab) -->
      <div v-if="settingsTab === 'account'" class="panel" style="padding:18px;margin-top:16px;max-width:560px">
        <h3 style="font-size:14px;margin-bottom:4px">👤 My profile</h3>
        <p style="font-size:12px;color:var(--text-mute);margin-bottom:14px">Logged in as <b>{{ auth.user?.email }}</b> · role: <span class="badge b-blue">{{ auth.user?.role }}</span> — full profile &amp; preferences also available from the ⚙️ icon (top right)</p>
        <label style="font-size:12px;color:var(--text-mute)">{{ t('Display name') }}
          <input v-model="profForm.name" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
        </label>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-top:10px">
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Current password') }}
            <input type="password" v-model="profForm.old_password" autocomplete="current-password" :placeholder="t('required to change password')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('New password') }}
            <input type="password" v-model="profForm.new_password" autocomplete="new-password" :placeholder="t('min 8 characters')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
        </div>
        <div style="display:flex;align-items:center;gap:12px;margin-top:14px">
          <button @click="saveProfile" :disabled="profSaving" style="padding:10px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ profSaving ? 'Saving…' : '💾 Update profile' }}</button>
          <span v-if="profMsg" style="font-size:12px;color:var(--text-mute)">{{ profMsg }}</span>
        </div>
      </div>
    </template>

    <!-- ═══════ SHOP MODAL ═══════ -->
    <div v-if="modal" class="overlay" @click.self="modal = null">
      <div class="modal">
        <div class="modal-h"><div class="t">{{ modal.title }}</div><button class="close" @click="modal = null">✕</button></div>
        <div class="modal-b">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Space no *') }}<input v-model="form.no" :placeholder="t('e.g. A-101')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Floor') }}<input v-model="form.floor" :placeholder="t('e.g. Ground')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Size (sqft)') }}<input type="number" v-model.number="form.sqft" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Service rate (৳/mo)') }}<input type="number" v-model.number="form.service_rate" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Billing model') }}
              <select v-model="form.bill_model" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option value="fixed">{{ t('Fixed (flat monthly)') }}</option>
                <option value="sqft">{{ t('Per sqft (rate × size)') }}</option>
                <option value="fixed+util">{{ t('Fixed + utilities (metered)') }}</option>
                <option value="sqft+util">{{ t('Per sqft + utilities (metered)') }}</option>
              </select>
            </label>
            <label v-if="form.bill_model === 'sqft' || form.bill_model === 'sqft+util'" style="font-size:12px;color:var(--text-mute)">{{ t('Rate per sqft (৳/sqft/mo)') }}
              <input type="number" v-model.number="form.rate_sqft" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label v-if="form.bill_model === 'fixed+util' || form.bill_model === 'sqft+util'" style="font-size:12px;color:var(--text-mute);display:flex;align-items:center;gap:8px;padding-top:18px;cursor:pointer">
              <input type="checkbox" v-model="form.util_included" style="accent-color:var(--primary)" /> ⚡ Include utilities (elec + water) in the service bill
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Owner name *') }}<input v-model="form.owner_name" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Owner mobile') }}<input v-model="form.owner_mobile" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Owner NID') }}<input v-model="form.owner_nid" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Owner (directory)') }}
              <SearchableSelect v-model="form.owner_id" :options="owners.map(o => ({ value: o.id, label: o.name + ' (' + o.type + ')' }))" :placeholder="t('— standalone (name above) —')" allow-add add-label="New owner" @add="setAfterAdd(form, 'owner_id', () => owners.find(o => o.name === ownerForm.name?.trim())?.id); openOwnerAdd()" style="margin-top:4px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Space type') }}
              <select v-model="form.space_type" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="t in SPACE_TYPES" :key="t" :value="t">{{ bnd(t) }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Occupancy') }}
              <select v-model="form.occupancy" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="o in OCCUPANCIES" :key="o" :value="o">{{ { Owner: 'Owner-occupied (service charge only)', Rented: 'Rented to a tenant', Vacant: 'Vacant' }[o] }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Status') }}
              <select v-model="form.status" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="(v, k) in { Active: '🟢 Active', Closed: '🔴 Closed', Vacant: '⚪ Vacant' }" :key="k" :value="k">{{ bnd(v) }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Opening balance (৳)') }}<input type="number" v-model.number="form.opening_balance" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveShop" :disabled="saving" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ saving ? 'Saving…' : '💾 Save shop' }}</button>
            <button @click="modal = null" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ WAIVER MODAL (two-level approval — spec 3.2) ═══════ -->
    <div v-if="waiverModal" class="overlay" @click.self="waiverModal = null">
      <div class="modal" style="max-width:420px">
        <div class="modal-h"><div class="t">💸 Waiver / discount request</div><button class="close" @click="waiverModal = null">✕</button></div>
        <div class="modal-b">
          <p style="color:var(--text-mute);font-size:12.5px;margin-bottom:12px">{{ waiverForm.shop }} · {{ monthLabel(waiverForm.month) }} · bill {{ money(waiverForm.max) }}</p>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Waiver amount (৳)') }}
            <input type="number" v-model.number="waiverForm.amount" min="1" :max="waiverForm.max" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Reason *') }}
            <textarea v-model="waiverForm.reason" rows="2" :placeholder="t('e.g. shop closed 10 days for renovation — committee case #12')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea>
          </label>
          <p style="font-size:11.5px;color:var(--text-mute);margin-top:10px">🛡️ Two-level approval — this request goes <b>{{ t('Pending') }}</b>; only the admin (president / general secretary) can approve it into the ledger. Every request is logged for the committee report.</p>
          <div style="display:flex;gap:10px;margin-top:14px">
            <button @click="requestWaiver" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('📨 Submit request') }}</button>
            <button @click="waiverModal = null" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ COLLECT MODAL ═══════ -->
    <div     <div v-if="payModal" class="overlay" @click.self="payModal = null">
      <div class="modal" style="max-width:460px">
        <div class="modal-h"><div class="t">💵 {{ t('Collect payment') }} — {{ payModal.shop_no }}</div><button class="close" @click="payModal = null">✕</button></div>
        <div class="modal-b">
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px;margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
              <div style="font-weight:800;font-size:14px">{{ { service: '🧾 ' + t('Service'), elec: '⚡ ' + t('Electricity'), water: '💧 ' + t('Water') }[payModal.kind] || payModal.kind }}</div>
              <span class="badge" :class="badge(payModal.status)">{{ bnd(payModal.status) }}</span>
            </div>
            <div style="display:flex;gap:14px;margin-top:8px;font-size:12px;color:var(--text-mute);flex-wrap:wrap">
              <span>📅 {{ monthLabel(payModal.month) }}</span>
              <span>#{{ payModal.id }}</span>
              <span v-if="payModal.due_date">{{ t('Due') }}: {{ payModal.due_date }}</span>
              <span v-if="payModal.owner_mobile">📱 {{ payModal.owner_mobile }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;border-top:1px dashed var(--border);margin-top:9px;padding-top:9px;font-size:12.5px">
              <span style="color:var(--text-mute)">{{ t('Bill amount') }}: {{ money(payModal.amount) }}</span>
              <span v-if="payModal.fine" style="color:var(--danger)">⚠️ {{ t('Late fee') }}: +{{ money(payModal.fine) }}</span>
              <b style="font-size:14px">{{ t('Total') }}: {{ money(Number(payModal.amount) + Number(payModal.fine || 0)) }}</b>
            </div>
          </div>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-bottom:6px">{{ t('Payer (owner)') }}</label>
          <input v-model="payForm.payer" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:12px">{{ t('Amount (৳)') }}</label>
          <div style="display:flex;gap:8px;align-items:center;margin-top:4px">
            <input type="number" v-model.number="payForm.amount" min="1" style="flex:1;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:15px;font-weight:800" />
            <button @click="payForm.amount = Number(payModal.amount) + Number(payModal.fine || 0)" class="btn-ghost" style="padding:9px 12px;font-size:12px">{{ t('Full') }}</button>
            <button @click="payForm.amount = Math.round((Number(payModal.amount) + Number(payModal.fine || 0)) / 2)" class="btn-ghost" style="padding:9px 12px;font-size:12px">½</button>
          </div>
          <p v-if="payModal.fine" style="font-size:12px;color:var(--danger);margin-top:6px">⚠️ {{ t('Includes late fee of') }} {{ money(payModal.fine) }} ({{ t('bill overdue') }})</p>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:12px">{{ t('Method') }}
            <select v-model="payForm.method_acct" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <optgroup v-for="g in payGroups" :key="g.label" :label="g.label">
                <option v-for="a in g.items" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
              </optgroup>
            </select>
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:12px">{{ t('Reference (trx no / note)') }}<input v-model="payForm.ref" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="savePay" style="flex:1;padding:12px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">{{ t('💾 Save & print receipt') }}</button>
            <button @click="payModal = null" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

<!-- ═══════ COMPLAINT MODAL ═══════ -->
    <div v-if="compModal" class="overlay" @click.self="compModal = null">
      <div class="modal" style="max-width:480px">
        <div class="modal-h"><div class="t">{{ compModal.title }}</div><button class="close" @click="compModal = null">✕</button></div>
        <div class="modal-b">
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Space *') }}
            <SearchableSelect v-model="compForm.shop" :options="shops.filter(x => x.status === 'Active').map(s => ({ value: s.id, label: s.no + ' — ' + s.floor + ' (' + s.owner_name + ')' }))" :placeholder="t('Select space…')" allow-add add-label="New space" @add="setAfterAdd(compForm, 'shop', () => data.list('shops').find(s => s.no === form.no?.trim())?.id); openAdd()" style="margin-top:4px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Subject *') }}<input v-model="compForm.subject" :placeholder="t('e.g. Lift not working on 2nd floor')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Details') }}<textarea v-model="compForm.descr" rows="2" :placeholder="t('Describe the issue…')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea></label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Priority') }}
            <select v-model="compForm.priority" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <option v-for="p in ['Low', 'Normal', 'High', 'Urgent']" :key="p" :value="p">{{ bnd(p) }}</option>
            </select>
          </label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveComplaint" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('🔧 Log complaint') }}</button>
            <button @click="compModal = null" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
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
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Asset name *') }}<input v-model="assetForm.name" :placeholder="t('e.g. Passenger Lift 1')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Type') }}
              <select v-model="assetForm.type" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="t in ASSET_TYPES" :key="t" :value="t">{{ bnd(t) }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Location') }}<input v-model="assetForm.location" :placeholder="t('e.g. Block A, near main entrance')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Vendor / service provider') }}<input v-model="assetForm.vendor" :placeholder="t('e.g. Otis Elevator')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Install date') }}<input type="date" v-model="assetForm.install_date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Warranty until') }}<input type="date" v-model="assetForm.warranty_until" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('AMC / contract until') }}<input type="date" v-model="assetForm.contract_until" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Cost (৳)') }}<input type="number" v-model.number="assetForm.cost" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Status') }}
              <select v-model="assetForm.status" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="st in ['Active', 'Under Service', 'Out of Service']" :key="st" :value="st">{{ bnd(st) }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute);grid-column:1/-1">{{ t('Note') }}<input v-model="assetForm.note" :placeholder="t('Any notes…')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveAsset" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💾 Save asset') }}</button>
            <button @click="assetModal = null" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ NOTICE MODAL ═══════ -->
    <div v-if="noticeModal" class="overlay" @click.self="noticeModal = false">
      <div class="modal" style="max-width:480px">
        <div class="modal-h"><div class="t">{{ t('📢 Post notice') }}</div><button class="close" @click="noticeModal = false">✕</button></div>
        <div class="modal-b">
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Title *') }}<input v-model="noticeForm.title" :placeholder="t('e.g. Generator maintenance on Sunday 10am–2pm')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Details') }}<textarea v-model="noticeForm.body" rows="3" :placeholder="t('Full announcement…')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea></label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Date') }}<input type="date" v-model="noticeForm.date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute);display:flex;align-items:flex-end;gap:8px;padding-bottom:8px"><input type="checkbox" v-model="noticeForm.pinned" style="width:16px;height:16px" /> 📌 Pin to top</label>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveNotice" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('📢 Post') }}</button>
            <button @click="noticeModal = false" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ STAFF MODAL ═══════ -->
    <div v-if="staffModal" class="overlay" @click.self="staffModal = null">
      <div class="modal" style="max-width:560px">
        <div class="modal-h"><div class="t">{{ staffModal.title }}</div><button class="close" @click="staffModal = null">✕</button></div>
        <div class="modal-b">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Full name *') }}<input v-model="staffForm.name" :placeholder="t('e.g. Md. Karim')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Designation') }}
              <select v-model="staffForm.designation" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="d in DESIGNATIONS" :key="d" :value="d">{{ bnd(d) }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Mobile') }}<input v-model="staffForm.phone" :placeholder="t('e.g. 01711-000000')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('NID') }}<input v-model="staffForm.nid" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Join date') }}<input type="date" v-model="staffForm.join_date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Monthly salary (৳)') }}<input type="number" v-model.number="staffForm.salary" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Status') }}
              <select v-model="staffForm.status" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="st in ['Active', 'On Leave', 'Resigned']" :key="st" :value="st">{{ bnd(st) }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Notes') }}<input v-model="staffForm.notes" :placeholder="t('Shift, remarks…')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveStaff" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💾 Save staff') }}</button>
            <button @click="staffModal = null" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ SALARY MODAL ═══════ -->
    <div v-if="salModal" class="overlay" @click.self="salModal = null">
      <div class="modal" style="max-width:420px">
        <div class="modal-h"><div class="t">💸 Pay salary — {{ salForm.staff_name }}</div><button class="close" @click="salModal = null">✕</button></div>
        <div class="modal-b">
          <p style="color:var(--text-mute);font-size:12.5px;margin-bottom:12px">{{ monthLabel(month) }} · {{ salModal.designation }}</p>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Amount (৳)') }}<input type="number" v-model.number="salForm.amount" min="1" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Paid via') }}
            <select v-model="salForm.method_acct" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <optgroup v-for="g in payGroups" :key="g.label" :label="g.label">
                <option v-for="a in g.items" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
              </optgroup>
            </select>
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Note') }}<input v-model="salForm.note" :placeholder="t('Optional — voucher / remark')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveSalary" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--ok);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💸 Confirm payment') }}</button>
            <button @click="salModal = null" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ USER MODAL ═══════ -->
    <div v-if="userModal" class="overlay" @click.self="userModal = null">
      <div class="modal" style="max-width:480px">
        <div class="modal-h"><div class="t">{{ userModal.title }}</div><button class="close" @click="userModal = null">✕</button></div>
        <div class="modal-b">
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Full name *') }}
            <input v-model="userForm.name" :placeholder="t('e.g. Md. Shahidullah')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <template v-if="userModal.mode === 'add'">
            <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Email *') }}
              <input v-model="userForm.email" type="email" :placeholder="t('e.g. secretary@razzakplaza.com')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Temporary password *') }}
              <input v-model="userForm.password" type="text" :placeholder="t('min 8 characters — user changes it on first login')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </template>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Role') }}
            <select v-model="userForm.role" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <option v-for="r in USER_ROLES" :key="r" :value="r">{{ { owner: '👑 Owner (committee chairman)', manager: '🧑‍💼 Manager', accountant: '🧮 Accountant', collector: '💵 Collector (field staff)' }[r] || r }}</option>
            </select>
          </label>
          <label v-if="userModal.mode === 'edit'" style="font-size:12px;color:var(--text-mute);display:flex;align-items:center;gap:8px;margin-top:12px;padding-bottom:8px">
            <input type="checkbox" v-model="userForm.active" style="width:16px;height:16px" /> Active (can log in)
          </label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveUser" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💾 Save user') }}</button>
            <button @click="userModal = null" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ RESET PASSWORD MODAL ═══════ -->
    <div v-if="resetModal" class="overlay" @click.self="resetModal = null">
      <div class="modal" style="max-width:420px">
        <div class="modal-h"><div class="t">🔑 Reset password — {{ resetForm.name }}</div><button class="close" @click="resetModal = null">✕</button></div>
        <div class="modal-b">
          <p style="color:var(--text-mute);font-size:12.5px;margin-bottom:12px">Sets a new temporary password. The user's other sessions are signed out.</p>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('New password') }}
            <input v-model="resetForm.password" type="text" :placeholder="t('min 8 characters')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveReset" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('🔑 Reset password') }}</button>
            <button @click="resetModal = null" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ COMMITTEE MEMBER MODAL ═══════ -->
    <div v-if="memberModal" class="overlay" @click.self="memberModal = null">
      <div class="modal" style="max-width:520px">
        <div class="modal-h"><div class="t">{{ memberModal.title }}</div><button class="close" @click="memberModal = null">✕</button></div>
        <div class="modal-b">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Full name *') }}
              <input v-model="memberForm.name" :placeholder="t('e.g. Alhaj Md. Abdul Razzak')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Role') }}
              <select v-model="memberForm.role" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="r in committeeRoles.length ? committeeRoles : COMMITTEE_ROLES" :key="r" :value="r">{{ { Chairman: '👑 Chairman', 'Vice Chairman': '👑 Vice Chairman', Secretary: '📝 Secretary', Treasurer: '💰 Treasurer', Member: '👤 Executive Member' }[r] || r }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Space (owner of)') }}
              <SearchableSelect v-model="memberForm.shop" :options="shops.map(s => ({ value: s.no, label: s.no + ' — ' + s.owner_name }))" :placeholder="t('Independent / no shop')" allow-add add-label="New space" @add="setAfterAdd(memberForm, 'shop', () => data.list('shops').find(s => s.no === form.no?.trim())?.no); openAdd()" style="margin-top:4px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Phone') }}
              <input v-model="memberForm.phone" :placeholder="t('e.g. 01711-000000')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Email') }}
              <input v-model="memberForm.email" type="email" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Term') }}
              <input v-model="memberForm.term" :placeholder="t('e.g. 2024–2026')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute);display:flex;align-items:center;gap:8px;padding-bottom:8px">
              <input type="checkbox" v-model="memberForm.active" style="width:16px;height:16px" /> Active on the committee
            </label>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveMember" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💾 Save member') }}</button>
            <button @click="memberModal = null" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ MEETING MODAL ═══════ -->
    <div v-if="meetingModal" class="overlay" @click.self="meetingModal = false">
      <div class="modal" style="max-width:560px">
        <div class="modal-h"><div class="t">{{ t('📅 Log meeting') }}</div><button class="close" @click="meetingModal = false">✕</button></div>
        <div class="modal-b">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Date') }}
              <input type="date" v-model="meetingForm.date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Type') }}
              <select v-model="meetingForm.type" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="t in MEETING_TYPES" :key="t" :value="t">{{ bnd(t) }}</option>
              </select>
            </label>
          </div>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Title *') }}
            <input v-model="meetingForm.title" :placeholder="t('e.g. Annual General Meeting 2026')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Agenda') }}
            <textarea v-model="meetingForm.agenda" rows="2" :placeholder="t('Agenda items…')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea>
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Decisions') }}
            <textarea v-model="meetingForm.decisions" rows="2" :placeholder="t('What was decided…')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea>
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Minutes / notes') }}
            <textarea v-model="meetingForm.minutes" rows="3" :placeholder="t('Full minutes or notes (stored as the governance record)…')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea>
          </label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveMeeting" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--ok);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💾 Save meeting') }}</button>
            <button @click="meetingModal = false" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ RESOLUTION MODAL ═══════ -->
    <div v-if="resModal" class="overlay" @click.self="resModal = false">
      <div class="modal" style="max-width:520px">
        <div class="modal-h"><div class="t">📜 New resolution</div><button class="close" @click="resModal = false">✕</button></div>
        <div class="modal-b">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Number') }}
              <input v-model="resForm.number" :placeholder="t('RES-2026-01')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Date') }}
              <input type="date" v-model="resForm.date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </div>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Title *') }}
            <input v-model="resForm.title" :placeholder="t('e.g. 5% service charge increase from October')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Resolution text') }}
            <textarea v-model="resForm.body" rows="3" :placeholder="t('The full resolution text — archived as the governance record…')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea>
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Linked meeting (optional)') }}
            <SearchableSelect v-model="resForm.meeting_id" :options="(committee?.meetings || []).map(m => ({ value: m.id, label: '#' + m.id + ' · ' + m.title + ' (' + m.date + ')' }))" :placeholder="t('— none —')" allow-add add-label="New meeting" @add="setAfterAdd(resForm, 'meeting_id', () => committee?.meetings?.find(m => m.title === meetingForm.title?.trim())?.id); openMeetingAdd()" style="margin-top:4px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:flex;align-items:center;gap:8px;margin-top:12px;padding-bottom:8px">
            <input type="checkbox" v-model="resForm.passed" style="width:16px;height:16px" /> Passed by the committee
          </label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveResolution" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💾 Save resolution') }}</button>
            <button @click="resModal = false" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ OWNER MODAL ═══════ -->
    <div v-if="ownerModal" class="overlay" @click.self="ownerModal = null">
      <div class="modal" style="max-width:540px">
        <div class="modal-h"><div class="t">{{ ownerModal.title }}</div><button class="close" @click="ownerModal = null">✕</button></div>
        <div class="modal-b">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Name / entity name *') }}
              <input v-model="ownerForm.name" :placeholder="t('e.g. Rahim Uddin or Rahim Traders Ltd')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Type') }}
              <select v-model="ownerForm.type" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="t in OWNER_TYPES" :key="t" :value="t">{{ bnd(t) }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Phone') }}
              <input v-model="ownerForm.phone" :placeholder="t('e.g. 01711-000000')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Email') }}
              <input v-model="ownerForm.email" type="email" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('NID (person) / TIN') }}
              <input v-model="ownerForm.nid" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Trade license (company)') }}
              <input v-model="ownerForm.trade_license" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Contact person') }}
              <input v-model="ownerForm.contact_person" :placeholder="t('for companies')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Address') }}
              <input v-model="ownerForm.address" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </div>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Notes') }}
            <textarea v-model="ownerForm.notes" rows="2" placeholder="e.g. owns A-101 &amp; B-201; self-occupies A-101" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea>
          </label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveOwner" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💾 Save owner') }}</button>
            <button @click="ownerModal = null" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ OWNER PROFILE MODAL ═══════ -->
    <div v-if="ownerProfile" class="overlay" @click.self="ownerProfile = null">
      <div class="modal" style="max-width:640px">
        <div class="modal-h"><div class="t">🏢 {{ ownerProfile.owner.name }}</div><button class="close" @click="ownerProfile = null">✕</button></div>
        <div class="modal-b">
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <span class="badge" :class="{ Person: 'b-blue', Company: 'b-red', Bank: 'b-green' }[ownerProfile.owner.type] || 'b-gray'">{{ ownerProfile.owner.type }}</span>
            <span v-if="ownerProfile.owner.phone" class="badge b-gray">{{ ownerProfile.owner.phone }}</span>
            <span v-if="ownerProfile.owner.trade_license" class="badge b-gray">TL {{ ownerProfile.owner.trade_license }}</span>
            <span class="badge b-orange">{{ ownerProfile.shops.length }} space(s)</span>
            <span class="badge" :class="ownerProfile.total_due > 0 ? 'b-red' : 'b-green'">due {{ money(ownerProfile.total_due) }}</span>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px;max-height:52vh;overflow-y:auto">
            <div v-for="s in ownerProfile.shops" :key="s.id" style="border:1px solid var(--border);border-radius:12px;padding:11px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
              <b style="font-size:13px">{{ s.no }} <span style="color:var(--text-mute);font-weight:500">· {{ s.floor }} floor</span></b>
              <span class="badge b-gray" style="font-size:10px">{{ s.space_type }}</span>
              <span class="badge" :class="{ Owner: 'b-green', Rented: 'b-blue', Vacant: 'b-gray' }[s.occupancy] || 'b-gray'">{{ s.occupancy }}</span>
              <span style="flex:1"></span>
              <span style="font-size:11.5px;color:var(--text-mute)">paid {{ money(s.paid) }}</span>
              <span style="font-size:12.5px;font-weight:800" :style="s.due > 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(s.due) }} due</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ TENANT MODAL ═══════ -->
    <div     <div v-if="tenantModal" class="overlay" @click.self="tenantModal = null">
      <div class="modal" style="max-width:660px">
        <div class="modal-h"><div class="t">{{ tenantModal.title }}</div><button class="close" @click="tenantModal = null">✕</button></div>
        <div class="modal-b" style="max-height:74vh;overflow-y:auto">
          <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">👤 {{ t('Identity') }}</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Full name *') }}
              <input v-model="tenantForm.name" :placeholder="t('e.g. Abdul Kader')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Type') }}
              <select v-model="tenantForm.kind" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option value="Individual">{{ t('Individual') }}</option>
                <option value="Corporate">{{ t('Corporate') }}</option>
                <option value="Company">{{ t('Company') }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Father name') }}
              <input v-model="tenantForm.father_name" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Mother name') }}
              <input v-model="tenantForm.mother_name" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('NID') }}
              <input v-model="tenantForm.nid" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Phone') }}
              <input v-model="tenantForm.phone" :placeholder="t('e.g. 01800-000000')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Email') }}
              <input v-model="tenantForm.email" type="email" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Emergency contact') }}
              <input v-model="tenantForm.emergency_contact" :placeholder="t('e.g. 01700-000000 (wife/brother)')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </div>
          <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin:16px 0 8px">📍 {{ t('Address') }}</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Present address') }}
              <input v-model="tenantForm.present_address" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Permanent address') }}
              <input v-model="tenantForm.permanent_address" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('City') }}
              <input v-model="tenantForm.city" :placeholder="t('e.g. Dhaka')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Joined') }}
              <input v-model="tenantForm.joined_at" type="date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </div>
          <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin:16px 0 8px">🏪 {{ t('Business & occupation') }}</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Business name') }}
              <input v-model="tenantForm.business_name" :placeholder="t('e.g. Rahman Mobile Gallery')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Occupation') }}
              <input v-model="tenantForm.occupation" :placeholder="t('e.g. Business / Service')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Tags') }}
              <input v-model="tenantForm.tags" :placeholder="t('comma separated, e.g. vip, wholesale')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </div>
          <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin:16px 0 8px">👨‍👩‍👧 {{ t('Family members') }} <small style="text-transform:none;font-weight:600">({{ t('one per line — Name, Relation') }})</small></div>
          <textarea v-model="tenantForm.family" rows="3" :placeholder="t('e.g. Fatema Begum, Wife')" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea>
          <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin:16px 0 8px">🏢 {{ t('Company profile') }} <small style="text-transform:none;font-weight:600">({{ t('Corporate tenants — one line: Label, Value') }})</small></div>
          <textarea v-model="tenantForm.company" rows="3" :placeholder="t('e.g. Trade license, TR-2026-1122')" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea>
          <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin:16px 0 8px">📝 {{ t('Notes') }}</div>
          <textarea v-model="tenantForm.notes" rows="2" :placeholder="t('optional')" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveTenant" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💾 Save tenant') }}</button>
            <button @click="tenantModal = null" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

<!-- ═══════ AGREEMENT MODAL ═══════ -->
    <div v-if="agrModal" class="overlay" @click.self="agrModal = false">
      <div class="modal" style="max-width:540px">
        <div class="modal-h"><div class="t">📄 New rental agreement</div><button class="close" @click="agrModal = false">✕</button></div>
        <div class="modal-b">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Space *') }}
              <SearchableSelect v-model="agrForm.shop" :options="shops.map(s => ({ value: s.no, label: s.no + ' · ' + (s.space_type || 'Shop') }))" :placeholder="t('— choose space —')" allow-add add-label="New space" @add="setAfterAdd(agrForm, 'shop', () => data.list('shops').find(s => s.no === form.no?.trim())?.no); openAdd()" style="margin-top:4px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Tenant') }}
              <SearchableSelect v-model="agrForm.tenant_id" :options="tenants.map(t => ({ value: t.id, label: t.name }))" :placeholder="t('— choose tenant —')" allow-add add-label="New tenant" @add="setAfterAdd(agrForm, 'tenant_id', () => tenants.find(t => t.name === tenantForm.name?.trim())?.id); openTenantAdd()" style="margin-top:4px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Monthly rent (৳)') }}
              <input type="number" v-model.number="agrForm.rent" min="0" :placeholder="t('e.g. 25000')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Advance months') }}
              <input type="number" v-model.number="agrForm.advance_months" min="0" :placeholder="t('e.g. 3')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Start date') }}
              <input type="date" v-model="agrForm.start_date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('End date (optional)') }}
              <input type="date" v-model="agrForm.end_date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Rent due day') }}
              <input type="number" v-model.number="agrForm.due_day" min="1" max="28" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Status') }}
              <select v-model="agrForm.status" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="s in ['Active', 'Expired', 'Terminated']" :key="s" :value="s">{{ bnd(s) }}</option>
              </select>
            </label>
          </div>
          <label style="font-size:12px;color:var(--text-mute);display:flex;align-items:center;gap:8px;margin-top:12px;cursor:pointer;background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:11px 13px">
            <input type="checkbox" v-model="agrForm.rent_collection" style="width:16px;height:16px" />
            <span><b>{{ t('Committee collects rent') }}</b> <span style="color:var(--text-mute)">— optional service: the owner gets rent collected on their behalf</span></span>
          </label>
          <div style="display:flex;gap:10px;margin-top:16px">
            <button @click="saveAgreement" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--ok);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💾 Save agreement') }}</button>
            <button @click="agrModal = false" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ RENT COLLECT MODAL ═══════ -->
    <div v-if="rentModal" class="overlay" @click.self="rentModal = null">
      <div class="modal" style="max-width:440px">
        <div class="modal-h"><div class="t">💵 Collect rent — {{ rentModal.shop }}</div><button class="close" @click="rentModal = null">✕</button></div>
        <div class="modal-b">
          <p style="font-size:12.5px;color:var(--text-mute);margin-bottom:12px">Recording rent for <b>{{ rentModal.tenant_name }}</b> ({{ money(rentModal.rent) }}/mo). Receipt <b>{{ t('RNT-…') }}</b> auto-generated.</p>
          <label style="font-size:12px;color:var(--text-mute);display:block">{{ t('Month') }}
            <input type="month" v-model="rentForm.month" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Amount') }}
              <input type="number" v-model.number="rentForm.amount" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Method') }}
              <select v-model="rentForm.method_acct" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <optgroup v-for="g in payGroups" :key="g.label" :label="g.label">
                  <option v-for="a in g.items" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                </optgroup>
              </select>
            </label>
          </div>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Reference') }}
            <input v-model="rentForm.ref" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveRent" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--ok);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('✅ Record rent') }}</button>
            <button @click="rentModal = null" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ VENDOR MODAL ═══════ -->
    <div v-if="vendorModal" class="overlay" @click.self="vendorModal = null">
      <div class="modal" style="max-width:520px">
        <div class="modal-h"><div class="t">{{ vendorModal.title }}</div><button class="close" @click="vendorModal = null">✕</button></div>
        <div class="modal-b">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Vendor name *') }}
              <input v-model="vendorForm.name" :placeholder="t('e.g. Otis Elevator Co.')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Category') }}
              <select v-model="vendorForm.category" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="c in VENDOR_CATS" :key="c" :value="c">{{ bnd(c) }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Contact person') }}
              <input v-model="vendorForm.contact_person" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Phone') }}
              <input v-model="vendorForm.phone" :placeholder="t('e.g. 02-9551234')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Email') }}
              <input v-model="vendorForm.email" type="email" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Address') }}
              <input v-model="vendorForm.address" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveVendor" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💾 Save vendor') }}</button>
            <button @click="vendorModal = null" class="btn-ghost" style="padding:11px 18px">{{ t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ VENDOR PAY MODAL ═══════ -->
    <div v-if="vendorPayModal" class="overlay" @click.self="vendorPayModal = null">
      <div class="modal" style="max-width:500px">
        <div class="modal-h"><div class="t">💸 Pay {{ vendorPayModal.name }}</div><button class="close" @click="vendorPayModal = null">✕</button></div>
        <div class="modal-b">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Amount (৳)') }}
              <input type="number" v-model.number="vendorPayForm.amount" min="0" :placeholder="t('e.g. 8500')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">{{ t('Method') }}
              <select v-model="vendorPayForm.method_acct" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <optgroup v-for="g in payGroups" :key="g.label" :label="g.label">
                  <option v-for="a in g.items" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                </optgroup>
              </select>
            </label>
          </div>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Reference / cheque no') }}
            <input v-model="vendorPayForm.ref" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">{{ t('Note (what is this for?)') }}
            <input v-model="vendorPayForm.note" :placeholder="t('e.g. Lift AMC — August')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <div style="display:flex;gap:10px;margin-top:16px">
            <button @click="saveVendorPay" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--ok);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('✅ Record payment') }}</button>
          </div>
          <div v-if="vendorPayments.length" style="margin-top:16px">
            <div style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">{{ t('Payment ledger') }}</div>
            <div v-for="p in vendorPayments" :key="p.id" style="display:flex;align-items:center;gap:8px;border-bottom:1px dashed var(--border);padding:7px 0;font-size:12.5px">
              <b>{{ money(p.amount) }}</b>
              <span class="badge b-gray" style="font-size:10px">{{ bnd(p.method) }}</span>
              <span style="color:var(--text-mute);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ p.note }}</span>
              <span style="color:var(--text-mute);font-size:11px">{{ (p.ts || '').slice(0, 10) }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ ACCOUNT LEDGER DRAWER (per-account + sub-ledgers) ═══════ -->
    <template v-if="acctDrawer">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:210" @click="acctDrawer = null"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(600px,94vw);background:var(--card);z-index:211;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div style="height:104px;background:linear-gradient(135deg,#2F80ED,#9B51E0);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:42px;opacity:.9">📒</div>
          <button @click="acctDrawer = null" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:rgba(255,255,255,.2);color:#fff;border:none">{{ acctDrawer.account.type }}</span>
            <span v-if="acctDrawer.account.subsidiary" class="badge" style="background:rgba(255,255,255,.2);color:#fff;border:none">🧾 sub-ledger control</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:19px;font-weight:800;letter-spacing:-.3px">{{ acctDrawer.account.name }}</h2>
          <div class="c-sub" style="margin-top:3px">{{ TYPE_ICONS[acctDrawer.account.type] }} {{ acctDrawer.account.type }}<template v-if="acctDrawer.account.code"> · {{ acctDrawer.account.code }}</template><template v-if="acctDrawer.account.opening"> · opening {{ money(acctDrawer.account.opening) }}</template></div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Entries') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ acctDrawer.entries.length }}</div></div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Total debit') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px;color:var(--danger)">{{ money(acctDrawer.total_debit) }}</div></div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Total credit') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px;color:var(--ok)">{{ money(acctDrawer.total_credit) }}</div></div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Balance') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px" :style="acctDrawer.balance < 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(acctDrawer.balance) }}</div></div>
          </div>
          <!-- SUB-LEDGERS for control accounts -->
          <template v-if="acctDrawer.subs && acctDrawer.subs.length">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">🧾 Sub-ledgers (per party)</div>
            <div v-for="s in acctDrawer.subs" :key="s.subsidiary" style="display:flex;align-items:center;gap:8px;border:1px solid var(--border);border-radius:10px;padding:9px 12px;margin-bottom:8px;background:var(--bg-alt)">
              <span style="font-size:13px">{{ { vendor: '🧰', owner: '🏢', tenant: '🧑‍🤝‍🧑', staff: '🧑‍💼' }[s.subsidiary_type] || '👤' }}</span>
              <b style="flex:1;font-size:13px">{{ s.subsidiary }}</b>
              <span style="font-size:11px;color:var(--text-mute)">Dr {{ money(s.d) }} / Cr {{ money(s.c) }}</span>
              <b style="font-size:13px" :style="s.balance < 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(s.balance) }}</b>
            </div>
          </template>
          <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin:14px 0 8px">Approved entries ({{ acctDrawer.entries.length }})</div>
          <div class="tbl-wrap" style="max-height:340px">
            <table class="kr" style="width:100%">
              <thead><tr><th>{{ t('Ref') }}</th><th>{{ t('Date') }}</th><th>{{ t('Debit') }}</th><th>{{ t('Credit') }}</th><th>{{ t('Subsidiary') }}</th><th>{{ t('Note') }}</th></tr></thead>
              <tbody>
                <tr v-for="e in acctDrawer.entries" :key="e.id">
                  <td style="font-family:monospace;font-size:11px">{{ e.ref }}</td>
                  <td style="font-size:11.5px">{{ e.date }}</td>
                  <td style="text-align:right;font-weight:700;color:var(--danger)">{{ e.debit ? money(e.debit) : '' }}</td>
                  <td style="text-align:right;font-weight:700;color:var(--ok)">{{ e.credit ? money(e.credit) : '' }}</td>
                  <td style="font-size:11.5px">{{ e.subsidiary || '—' }}</td>
                  <td style="font-size:11px;color:var(--text-mute);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" :title="e.note">{{ e.note }}</td>
                </tr>
                <tr v-if="!acctDrawer.entries.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No approved entries for this account yet.') }}</td></tr>
              </tbody>
            </table>
          </div>
          <div style="height:20px"></div>
        </div>
      </div>
    </template>

    <!-- ═══════ ACCOUNT MODAL ═══════ -->
    <div v-if="accountModal" class="overlay" @click.self="accountModal = null">
      <div class="modal" style="max-width:420px">
        <div class="modal-h"><div class="t">{{ accountModal.title }}</div><button @click="accountModal = null" class="x">✕</button></div>
        <div class="modal-b" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Code') }}
            <input v-model="accountForm.code" :placeholder="t('e.g. 5080')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Type') }}
            <select v-model="accountForm.type" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <option v-for="t in ACCOUNT_TYPES" :key="t" :value="t">{{ TYPE_ICONS[t] }} {{ t }}</option>
            </select>
          </label>
          <label style="font-size:12px;color:var(--text-mute);grid-column:1/-1">{{ t('Account name *') }}
            <input v-model="accountForm.name" :placeholder="t('e.g. Generator Fuel')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Opening balance (৳)') }}
            <input type="number" v-model.number="accountForm.opening" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:flex;align-items:flex-end;gap:9px;cursor:pointer;padding-bottom:8px">
            <span class="lf-switch" :class="{ on: !!accountForm.active }" @click="accountForm.active = accountForm.active ? 0 : 1" style="width:40px;height:22px;border-radius:99px;background:accountForm.active ? 'var(--ok,#27AE60)' : 'var(--border,#cbd5e1)';position:relative;transition:background .15s;flex-shrink:0">
              <span style="position:absolute;top:2px;left:accountForm.active ? '20px' : '2px';width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:left .15s"></span>
            </span>
            <b :style="accountForm.active ? '' : 'color:var(--danger)'">{{ accountForm.active ? 'Active' : 'Inactive' }}</b>
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:flex;align-items:flex-end;gap:9px;cursor:pointer;padding-bottom:8px" title="Control accounts (AR, AP, utility payables) track sub-ledgers per party — the voucher lines get a 🧾 party picker">
            <span class="lf-switch" :class="{ on: !!accountForm.subsidiary }" @click="accountForm.subsidiary = accountForm.subsidiary ? 0 : 1" style="width:40px;height:22px;border-radius:99px;background:accountForm.subsidiary ? 'var(--ok,#27AE60)' : 'var(--border,#cbd5e1)';position:relative;transition:background .15s;flex-shrink:0">
              <span style="position:absolute;top:2px;left:accountForm.subsidiary ? '20px' : '2px';width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:left .15s"></span>
            </span>
            <b :style="accountForm.subsidiary ? '' : ''">🧾 Sub-ledger control</b>
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:flex;align-items:flex-end;gap:9px;cursor:pointer;padding-bottom:8px" title="Group headings (e.g. 1000 Assets) cannot be posted to — children roll up into them">
            <span class="lf-switch" :class="{ on: !!accountForm.is_group }" @click="accountForm.is_group = accountForm.is_group ? 0 : 1" style="width:40px;height:22px;border-radius:99px;background:accountForm.is_group ? 'var(--ok,#27AE60)' : 'var(--border,#cbd5e1)';position:relative;transition:background .15s;flex-shrink:0">
              <span style="position:absolute;top:2px;left:accountForm.is_group ? '20px' : '2px';width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:left .15s"></span>
            </span>
            <b :style="accountForm.is_group ? 'color:var(--ok)' : ''">🧩 Group account (heading)</b>
          </label>
          <label v-if="!accountForm.is_group" style="font-size:12px;color:var(--text-mute)">
            Parent group
            <SearchableSelect v-model="accountForm.parent" :options="groupAccountOptions" :placeholder="t('— top level —')" style="margin-top:4px" />
          </label>
          <label v-if="accountForm.subsidiary" style="font-size:12px;color:var(--text-mute)">
            🧾 Party type
            <select v-model="accountForm.subs_type" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <option value="">{{ t('Any party') }}</option>
              <option value="vendor">{{ t('🧰 Vendors') }}</option>
              <option value="owner">{{ t('🏢 Owners') }}</option>
              <option value="tenant">{{ t('🧑🤝🧑 Tenants') }}</option>
              <option value="staff">🧑💼 Staff</option>
            </select>
          </label>
          <label style="font-size:12px;color:var(--text-mute);grid-column:1/-1">{{ t('Note') }}
            <input v-model="accountForm.note" :placeholder="t('optional')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
        </div>
        <div class="modal-b" style="display:flex;gap:8px;justify-content:flex-end">
          <button @click="accountModal = null" class="btn-ghost">{{ t('Cancel') }}</button>
          <button @click="saveAccount" style="padding:9px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💾 Save account') }}</button>
        </div>
      </div>
    </div>

    <!-- ═══════ JOURNAL VOUCHER MODAL (double entry + attachment) ═══════ -->
    <div v-if="jModal" class="overlay" @click.self="jModal = null">
      <div class="modal" style="max-width:560px">
        <div class="modal-h"><div class="t">📖 New journal voucher <small style="color:var(--text-mute);font-weight:600">(double entry)</small></div><button @click="jModal = null" class="x">✕</button></div>
        <div class="modal-b" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Date') }}
            <input type="date" v-model="jForm.date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute)">{{ t('Reference (auto JV-####)') }}
            <input v-model="jForm.ref" :placeholder="t('JV-00001')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);grid-column:1/-1">{{ t('Voucher lines') }} <small style="color:var(--text-mute)">— debit total must equal credit total</small></label>
          <div style="grid-column:1/-1;display:flex;flex-direction:column;gap:8px">
            <div v-for="(l, i) in jForm.lines" :key="i" style="display:flex;gap:8px;align-items:center">
              <div style="flex:1;min-width:0">
                <SearchableSelect :model-value="l.account || 0" :options="accountOptions" :placeholder="t('Account… (search by code or name)')" @update:modelValue="v => { l.account = v; onJLineAccountChange(l) }" />
              </div>
              <select v-model="l.side" style="padding:9px 8px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12.5px">
                <option value="debit">Dr</option><option value="credit">Cr</option>
              </select>
              <input type="number" v-model.number="l.amount" min="0" placeholder="৳" style="width:100px;padding:9px 10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12.5px" />
              <div v-if="isSubLedgerAccount(l.account)" style="width:170px;flex-shrink:0">
                <SearchableSelect :model-value="l.subValue" :options="subOptionsFor(l)" :placeholder="t('🧾 Party…')" @update:modelValue="onSubPick($event, l)" />
              </div>
              <span v-if="l.subName" style="font-size:11px;color:var(--text-mute);white-space:nowrap">🧾 {{ l.subName }}</span>
              <button @click="delJLine(i)" title="Remove line" style="border:none;background:none;color:var(--danger);font-size:15px;cursor:pointer;font-weight:800">✕</button>
            </div>
            <button @click="addJLine" style="align-self:flex-start;padding:7px 12px;border:1px dashed var(--border);background:none;border-radius:10px;color:var(--primary);font-size:12px;font-weight:800;cursor:pointer">{{ t('＋ Add line') }}</button>
          </div>
          <div style="grid-column:1/-1;display:flex;align-items:center;gap:10px;background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:9px 12px">
            <span style="font-size:12px;color:var(--text-mute)">{{ t('Balance:') }}</span>
            <b style="font-size:13px;color:var(--danger)">Dr {{ money(jDrTotal) }}</b>
            <span style="color:var(--text-mute)">=</span>
            <b style="font-size:13px;color:var(--ok)">Cr {{ money(jCrTotal) }}</b>
            <b v-if="jBalanced" style="margin-left:auto;color:var(--ok);font-size:12px">✅ Balanced</b>
            <b v-else style="margin-left:auto;color:var(--danger);font-size:12px">⚠️ Not balanced ({{ money(Math.abs(jDrTotal - jCrTotal)) }} off)</b>
          </div>
          <label style="font-size:12px;color:var(--text-mute);grid-column:1/-1">{{ t('Note / description') }}
            <input v-model="jForm.note" :placeholder="t('e.g. Generator fuel for August')" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <div style="grid-column:1/-1;display:flex;align-items:center;gap:12px;border:1px dashed var(--border);border-radius:10px;padding:10px 12px">
            <div style="flex:1;min-width:0">
              <div style="font-size:12px;color:var(--text-mute);font-weight:700">🧾 Receipt / voucher attachment <small>(optional, max 800 KB)</small></div>
              <label style="font-size:11.5px;color:var(--primary);font-weight:700;cursor:pointer">{{ t('⬆ Upload image') }}<input type="file" accept="image/*" style="display:none" @change="onJoucherPick($event)" /></label>
              <div v-if="jForm.voucherName" style="font-size:11px;color:var(--text-mute)">📎 {{ jForm.voucherName }}</div>
            </div>
            <img v-if="jForm.voucher" :src="jForm.voucher" alt="preview" style="width:64px;height:64px;object-fit:cover;border-radius:10px;border:1px solid var(--border)" />
            <button v-if="jForm.voucher" @click="jForm.voucher = ''; jForm.voucherName = ''" style="border:none;background:none;color:var(--danger);font-size:11px;cursor:pointer">{{ t('🗑 Remove') }}</button>
          </div>
        </div>
        <div class="modal-b" style="display:flex;gap:8px;justify-content:flex-end">
          <button @click="jModal = null" class="btn-ghost">{{ t('Cancel') }}</button>
          <button @click="saveJournal" :disabled="!jBalanced" :style="jBalanced ? '' : 'opacity:.45;cursor:not-allowed'" style="padding:9px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('📤 Submit for approval') }}</button>
        </div>
      </div>
    </div>

    <!-- ═══════ SPACE DETAIL DRAWER (Units-style, tab by tab) ═══════ -->
    <template v-if="drawer">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:200" @click="closeSpaceDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(640px,94vw);background:var(--card);z-index:201;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div style="height:118px;background:linear-gradient(135deg,#2F80ED,#27AE60);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px;opacity:.9">🏪</div>
          <button @click="closeSpaceDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="badge(drawer.shop.status)">{{ drawer.shop.status }}</span>
            <span v-if="drawer.shop.space_type" class="badge b-white" style="background:rgba(255,255,255,.2);color:#fff;border:none">{{ drawer.shop.space_type }}</span>
            <span class="badge" :class="{ Owner: 'b-green', Rented: 'b-blue', Vacant: 'b-gray' }[drawer.shop.occupancy] || 'b-gray'">{{ drawer.shop.occupancy }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ drawer.shop.no }} <span style="font-size:13px;color:var(--text-mute);font-weight:600">· {{ drawer.shop.id }}</span></h2>
          <div class="c-sub" style="margin-top:3px"><span class="elink" @click.stop="linkOwner(drawer.shop)">🏢 {{ drawer.shop.owner_name || '—' }}</span><template v-if="drawer.shop.floor"> · {{ drawer.shop.floor }} floor</template><template v-if="drawer.shop.sqft"> · {{ Number(drawer.shop.sqft).toLocaleString('en-IN') }} sqft</template></div>

          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Rate / month') }}</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ money(drawer.shop.service_rate) }}</div>
              <div class="c-sub" style="font-size:10px">{{ { fixed: 'Fixed', sqft: 'Per sqft', 'fixed+util': 'Fixed + utilities', 'sqft+util': 'Per sqft + utilities' }[drawer.shop.bill_model] || 'Fixed' }}<template v-if="drawer.shop.bill_model === 'sqft' || drawer.shop.bill_model === 'sqft+util'"> · {{ money(drawer.shop.rate_sqft) }}/sqft</template><template v-if="(drawer.shop.bill_model === 'fixed+util' || drawer.shop.bill_model === 'sqft+util') && drawer.shop.util_included"> · ⚡ util incl.</template></div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Opening balance') }}</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ money(drawer.shop.opening_balance) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Total paid') }}</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px;color:var(--ok)">{{ money(drawer.total_paid) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Due') }}</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px" :style="drawer.total_due > 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(drawer.total_due) }}</div>
            </div>
          </div>

          <div style="display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:14px;flex-wrap:wrap">
            <button v-for="t in [{id:'overview',label:'Overview',ico:'📋'},{id:'owner',label:'Owner',ico:'🏢'},{id:'rent',label:'Rent',ico:'📄'},{id:'bills',label:'Bills',ico:'🧾'},{id:'meters',label:'Meters',ico:'⚡'},{id:'complaints',label:'Complaints',ico:'🔧'}]" :key="t.id" @click="drawerTab = t.id"
              style="padding:9px 14px;border:none;background:none;font-size:13px;font-weight:700;cursor:pointer;border-bottom:2px solid transparent;color:var(--text-mute)"
              :style="drawerTab === t.id ? 'color:var(--primary);border-bottom-color:var(--primary)' : ''">
              {{ t.ico }} {{ t.label }} <span style="opacity:.7">({{ t.id === 'overview' ? '' : t.id === 'owner' ? (drawer.owner ? 1 : 0) : t.id === 'rent' ? drawer.agreements.length : t.id === 'bills' ? drawer.bills.length : t.id === 'meters' ? drawer.readings.length : drawer.complaints.length }})</span>
            </button>
          </div>

          <!-- OVERVIEW -->
          <div v-if="drawerTab === 'overview'" style="display:grid;grid-template-columns:1fr 1fr;gap:9px 16px">
            <div v-for="r in [
              ['Space no', drawer.shop.no], ['Floor', drawer.shop.floor || '—'], ['Size', (drawer.shop.sqft ? Number(drawer.shop.sqft).toLocaleString('en-IN') + ' sqft' : '—')],
              ['Space type', drawer.shop.space_type || 'Shop'], ['Occupancy', drawer.shop.occupancy || '—'], ['Status', drawer.shop.status],
              ['Service rate', money(drawer.shop.service_rate) + '/mo'], ['Opening balance', money(drawer.shop.opening_balance)],
              ['Owner', drawer.shop.owner_name || '—'], ['Owner mobile', drawer.shop.owner_mobile || '—'],
              ['Owner NID', drawer.shop.owner_nid || '—'], ['Bills (all months)', drawer.bills.length],
            ]" :key="r[0]" style="display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed var(--border);padding:7px 0;font-size:12.5px">
              <span style="color:var(--text-mute)">{{ r[0] }}</span><b style="text-align:right">{{ r[1] }}</b>
            </div>
          </div>

          <!-- OWNER -->
          <div v-else-if="drawerTab === 'owner'" class="drawer-tbl-wrap">
            <div v-if="drawerOwner" style="display:flex;gap:12px;align-items:center;border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:12px">
              <div style="width:48px;height:48px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:#fff" :style="{ background: memberColor({ id: 1, name: drawerOwner.name }) }">{{ memberAvatar({ name: drawerOwner.name }) }}</div>
              <div style="flex:1;min-width:0">
                <div style="font-weight:800;font-size:14px">{{ drawerOwner.name }}</div>
                <div style="font-size:11.5px;color:var(--text-mute)">{{ drawerOwner.type || 'Owner' }}<span v-if="drawerOwner.phone"> · {{ drawerOwner.phone }}</span><span v-if="drawerOwner.email"> · {{ drawerOwner.email }}</span></div>
              </div>
            </div>
            <div v-if="drawerOwner" style="display:grid;grid-template-columns:1fr 1fr;gap:9px 16px">
              <div v-for="r in [
                ['Type', drawerOwner.type || '—'], ['Phone', drawerOwner.phone || '—'], ['Email', drawerOwner.email || '—'],
                ['NID / TIN', drawerOwner.nid || '—'], ['Trade license', drawerOwner.trade_license || '—'], ['Contact person', drawerOwner.contact_person || '—'],
                ['Address', drawerOwner.address || '—'], ['Notes', drawerOwner.notes || '—'],
              ]" :key="r[0]" style="display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed var(--border);padding:7px 0;font-size:12.5px">
                <span style="color:var(--text-mute)">{{ r[0] }}</span><b style="text-align:right">{{ r[1] }}</b>
              </div>
            </div>
            <p v-else style="color:var(--text-mute);font-size:13px;padding:10px 0">{{ t('No owner record — add one from 🏢 Owners and link it in the Space form.') }}</p>
          </div>

          <!-- RENT -->
          <div v-else-if="drawerTab === 'rent'" class="drawer-tbl-wrap">
            <table class="kr" style="width:100%">
              <thead><tr><th>{{ t('Space') }}</th><th>{{ t('Tenant') }}</th><th>{{ t('Rent/mo') }}</th><th>{{ t('Term') }}</th><th>{{ t('Advance') }}</th><th>{{ t('Collection') }}</th><th>{{ t('Status') }}</th></tr></thead>
              <tbody>
                <tr v-for="a in drawer.agreements" :key="a.id">
                  <td style="font-weight:700"><span class="elink" @click.stop="linkShop(a)">{{ a.shop }}</span></td>
                  <td><span class="elink" @click.stop="linkTenant(a)">{{ a.tenant_name || '—' }}</span></td>
                  <td style="font-weight:700">{{ money(a.rent) }}</td>
                  <td style="font-size:12px">{{ a.start_date }}<template v-if="a.end_date"> → {{ a.end_date }}</template></td>
                  <td>{{ a.advance_months }} mo</td>
                  <td><span class="badge" :class="a.rent_collection ? 'b-blue' : 'b-gray'" style="font-size:10px">{{ a.rent_collection ? 'committee collects' : 'owner collects' }}</span></td>
                  <td><span class="badge" :class="badge(a.status)">{{ bnd(a.status) }}</span></td>
                </tr>
                <tr v-if="!drawer.agreements.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No rental agreement for this space.') }}</td></tr>
              </tbody>
            </table>
            <div v-if="drawer.rent_payments.length" style="margin-top:12px">
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">{{ t('Rent collections') }}</div>
              <div v-for="p in drawer.rent_payments" :key="p.id" style="display:flex;gap:8px;align-items:center;border-bottom:1px dashed var(--border);padding:7px 0;font-size:12.5px">
                <b>{{ money(p.amount) }}</b><span class="badge b-gray" style="font-size:10px">{{ bnd(p.method) }}</span>
                <span style="color:var(--text-mute);flex:1">{{ p.receipt }} · {{ p.month }}</span>
              </div>
            </div>
          </div>

          <!-- BILLS -->
          <div v-else-if="drawerTab === 'bills'" class="drawer-tbl-wrap">
            <table class="kr" style="width:100%">
              <thead><tr><th>{{ t('Bill') }}</th><th>{{ t('Month') }}</th><th>{{ t('Kind') }}</th><th>{{ t('Amount') }}</th><th>{{ t('Fine') }}</th><th>{{ t('Due') }}</th><th>{{ t('Status') }}</th></tr></thead>
              <tbody>
                <tr v-for="b in drawer.bills" :key="b.id">
                  <td style="font-weight:700">{{ b.id }}</td>
                  <td>{{ b.month }}</td>
                  <td>{{ bnd({ service: 'Service', elec: 'Electricity', water: 'Water' }[b.kind] || b.kind) }}</td>
                  <td style="font-weight:700">{{ money(b.amount) }}</td>
                  <td>{{ money(b.fine) }}</td>
                  <td style="font-size:12px">{{ b.due_date }}</td>
                  <td><span class="badge" :class="badge(b.status)">{{ bnd(b.status) }}</span></td>
                </tr>
                <tr v-if="!drawer.bills.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No bills for this space yet.') }}</td></tr>
              </tbody>
            </table>
            <div v-if="drawer.payments.length" style="margin-top:12px">
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">{{ t('Collection history') }}</div>
              <table class="kr" style="width:100%">
                <thead><tr><th>{{ t('Receipt') }}</th><th>{{ t('Month') }}</th><th>{{ t('Kind') }}</th><th>{{ t('Method') }}</th><th style="text-align:right">{{ t('Amount') }}</th></tr></thead>
                <tbody>
                  <tr v-for="p in drawer.payments" :key="p.id">
                    <td style="font-weight:700">{{ p.receipt }}</td><td>{{ p.month }}</td><td>{{ bnd(p.kind) }}</td><td>{{ bnd(p.method) }}</td><td style="text-align:right;font-weight:700">{{ money(p.amount) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- METERS -->
          <div v-else-if="drawerTab === 'meters'" class="drawer-tbl-wrap">
            <table class="kr" style="width:100%">
              <thead><tr><th>{{ t('Month') }}</th><th>{{ t('Type') }}</th><th style="text-align:right">{{ t('Reading') }}</th><th style="text-align:right">{{ t('Units') }}</th><th style="text-align:right">{{ t('Billed') }}</th></tr></thead>
              <tbody>
                <tr v-for="r in drawer.readings" :key="r.id">
                  <td>{{ r.month }}</td>
                  <td>{{ r.type === 'elec' ? '⚡ Electricity' : '💧 Water' }}</td>
                  <td style="text-align:right">{{ Number(r.reading).toLocaleString('en-IN') }}</td>
                  <td style="text-align:right">{{ Number(r.units).toLocaleString('en-IN') }}</td>
                  <td style="text-align:right;font-weight:700">{{ money(r.billed) }}</td>
                </tr>
                <tr v-if="!drawer.readings.length"><td colspan="5" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No meter readings for this space.') }}</td></tr>
              </tbody>
            </table>
          </div>

          <!-- COMPLAINTS -->
          <div v-else class="drawer-tbl-wrap">
            <table class="kr" style="width:100%">
              <thead><tr><th>{{ t('#') }}</th><th>{{ t('Subject') }}</th><th>{{ t('Priority') }}</th><th>{{ t('Opened') }}</th><th>{{ t('Status') }}</th></tr></thead>
              <tbody>
                <tr v-for="c in drawer.complaints" :key="c.id">
                  <td style="font-weight:700">{{ c.id }}</td>
                  <td>{{ c.subject }}</td>
                  <td><span class="badge" :class="c.priority === 'High' ? 'b-red' : c.priority === 'Urgent' ? 'b-red' : c.priority === 'Low' ? 'b-gray' : 'b-orange'" style="font-size:10px">{{ c.priority }}</span></td>
                  <td style="font-size:12px">{{ (c.created_at || '').slice(0, 10) }}</td>
                  <td><span class="badge" :class="badge(c.status)">{{ bnd(c.status) }}</span></td>
                </tr>
                <tr v-if="!drawer.complaints.length"><td colspan="5" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No complaints for this space.') }}</td></tr>
              </tbody>
            </table>
          </div>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>

    <!-- ═══════ ENTITY DRAWERS (Vendor / Staff / Tenant / Member / Owner) ═══════ -->
    <template v-if="vDrawer || sDrawer || tDrawer || mDrawer || oDrawer">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:210" @click="closeEntityDrawers"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(600px,94vw);background:var(--card);z-index:211;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <!-- VENDOR -->
        <template v-if="vDrawer">
          <div style="height:104px;background:linear-gradient(135deg,#F2994A,#EB5757);position:relative;flex-shrink:0">
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:42px;opacity:.9">🧰</div>
            <button @click="closeEntityDrawers" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
            <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
              <span class="badge" style="background:rgba(255,255,255,.2);color:#fff;border:none">{{ vDrawer.vendor.category }}</span>
            </div>
          </div>
          <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
            <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ vDrawer.vendor.name }}</h2>
            <div class="c-sub" style="margin-top:3px">🧰 Vendor · {{ vDrawer.vendor.category }}<span v-if="vDrawer.vendor.contact_person"> · {{ vDrawer.vendor.contact_person }}</span></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Total paid') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px;color:var(--danger)">{{ money(vDrawer.total_paid) }}</div></div>
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Payments') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ vDrawer.payments.length }}</div></div>
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Ledger expenses') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ money(vDrawer.total_expenses) }}</div></div>
            </div>
            <div style="display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:14px;flex-wrap:wrap">
              <button v-for="t in [{id:'overview',label:'Overview',ico:'📋'},{id:'payments',label:'Payments',ico:'💸'},{id:'expenses',label:'Expenses',ico:'📉'}]" :key="t.id" @click="eTab = t.id" style="padding:9px 14px;border:none;background:none;font-size:13px;font-weight:700;cursor:pointer;border-bottom:2px solid transparent;color:var(--text-mute)" :style="eTab === t.id ? 'color:var(--primary);border-bottom-color:var(--primary)' : ''">{{ t.ico }} {{ t.label }} <span style="opacity:.7">({{ t.id === 'overview' ? '' : t.id === 'payments' ? vDrawer.payments.length : vDrawer.expenses.length }})</span></button>
            </div>
            <div v-if="eTab === 'overview'" style="display:grid;grid-template-columns:1fr 1fr;gap:9px 16px">
              <div v-for="r in [['Category', vDrawer.vendor.category || '—'], ['Contact person', vDrawer.vendor.contact_person || '—'], ['Phone', vDrawer.vendor.phone || '—'], ['Email', vDrawer.vendor.email || '—'], ['Address', vDrawer.vendor.address || '—'], ['Notes', vDrawer.vendor.notes || '—']]" :key="r[0]" style="display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed var(--border);padding:7px 0;font-size:12.5px"><span style="color:var(--text-mute)">{{ r[0] }}</span><b style="text-align:right">{{ r[1] }}</b></div>
            </div>
            <div v-else-if="eTab === 'payments'" class="drawer-tbl-wrap">
              <table class="kr" style="width:100%"><thead><tr><th>{{ t('Amount') }}</th><th>{{ t('Method') }}</th><th>{{ t('Ref') }}</th><th>{{ t('Note') }}</th><th>{{ t('Date') }}</th></tr></thead><tbody>
                <tr v-for="p in vDrawer.payments" :key="p.id"><td style="font-weight:700">{{ money(p.amount) }}</td><td>{{ bnd(p.method) }}</td><td>{{ p.ref || '—' }}</td><td style="font-size:12px">{{ p.note }}</td><td style="font-size:12px;color:var(--text-mute)">{{ (p.ts || '').slice(0, 10) }}</td></tr>
                <tr v-if="!vDrawer.payments.length"><td colspan="5" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No payments recorded.') }}</td></tr>
              </tbody></table>
            </div>
            <div v-else class="drawer-tbl-wrap">
              <table class="kr" style="width:100%"><thead><tr><th>{{ t('Date') }}</th><th>{{ t('Label') }}</th><th>{{ t('Method') }}</th><th style="text-align:right">{{ t('Amount') }}</th></tr></thead><tbody>
                <tr v-for="e in vDrawer.expenses" :key="e.id"><td style="font-size:12px;color:var(--text-mute)">{{ (e.ts || '').slice(0, 10) }}</td><td style="font-size:12.5px">{{ e.label }}</td><td>{{ bnd(e.method) }}</td><td style="text-align:right;font-weight:700">{{ money(e.amount) }}</td></tr>
                <tr v-if="!vDrawer.expenses.length"><td colspan="4" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No ledger expenses linked to this vendor.') }}</td></tr>
              </tbody></table>
            </div>
          </div>
        </template>
        <!-- STAFF -->
        <template v-else-if="sDrawer">
          <div style="height:104px;background:linear-gradient(135deg,#2F80ED,#9B51E0);position:relative;flex-shrink:0">
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:42px;opacity:.9">🧑‍💼</div>
            <button @click="closeEntityDrawers" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          </div>
          <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
            <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sDrawer.staff.name }}</h2>
            <div class="c-sub" style="margin-top:3px">🧑‍💼 {{ sDrawer.staff.designation }} · joined {{ sDrawer.staff.join_date || '—' }}</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Salary / month') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ money(sDrawer.staff.salary) }}</div></div>
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Total paid') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px;color:var(--ok)">{{ money(sDrawer.total_paid) }}</div></div>
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Salaries') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ sDrawer.salaries.length }}</div></div>
            </div>
            <div style="display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:14px;flex-wrap:wrap">
              <button v-for="t in [{id:'overview',label:'Overview',ico:'📋'},{id:'salaries',label:'Salaries',ico:'💰'}]" :key="t.id" @click="eTab = t.id" style="padding:9px 14px;border:none;background:none;font-size:13px;font-weight:700;cursor:pointer;border-bottom:2px solid transparent;color:var(--text-mute)" :style="eTab === t.id ? 'color:var(--primary);border-bottom-color:var(--primary)' : ''">{{ t.ico }} {{ t.label }} <span style="opacity:.7">({{ t.id === 'overview' ? '' : sDrawer.salaries.length }})</span></button>
            </div>
            <div v-if="eTab === 'overview'" style="display:grid;grid-template-columns:1fr 1fr;gap:9px 16px">
              <div v-for="r in [['Designation', sDrawer.staff.designation || '—'], ['Phone', sDrawer.staff.phone || '—'], ['NID', sDrawer.staff.nid || '—'], ['Joined', sDrawer.staff.join_date || '—'], ['Status', sDrawer.staff.status], ['Salary/mo', money(sDrawer.staff.salary)]]" :key="r[0]" style="display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed var(--border);padding:7px 0;font-size:12.5px"><span style="color:var(--text-mute)">{{ r[0] }}</span><b style="text-align:right">{{ r[1] }}</b></div>
            </div>
            <div v-else class="drawer-tbl-wrap">
              <table class="kr" style="width:100%"><thead><tr><th>{{ t('Month') }}</th><th>{{ t('Amount') }}</th><th>{{ t('Method') }}</th><th>{{ t('Paid on') }}</th></tr></thead><tbody>
                <tr v-for="x in sDrawer.salaries" :key="x.id"><td style="font-weight:700">{{ x.month }}</td><td style="font-weight:700">{{ money(x.amount) }}</td><td>{{ x.method }}</td><td style="font-size:12px;color:var(--text-mute)">{{ (x.ts || '').slice(0, 10) }}</td></tr>
                <tr v-if="!sDrawer.salaries.length"><td colspan="4" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No salary payments yet.') }}</td></tr>
              </tbody></table>
            </div>
          </div>
        </template>
        <!-- TENANT -->
        <template v-else-if="tDrawer">
          <div style="height:104px;background:linear-gradient(135deg,#27AE60,#2D9CDB);position:relative;flex-shrink:0">
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:42px;opacity:.9">🧑‍🤝‍🧑</div>
            <button @click="closeEntityDrawers" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          </div>
          <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
            <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ tDrawer.tenant.name }}</h2>
            <div class="c-sub" style="margin-top:3px">🧑‍🤝‍🧑 Tenant<template v-if="tDrawer.tenant.employer"> · {{ tDrawer.tenant.employer }}</template></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Agreements') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ tDrawer.agreements.length }}</div></div>
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Rent collected') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px;color:var(--ok)">{{ money(tDrawer.rent_total) }}</div></div>
            </div>
            <div style="display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:14px;flex-wrap:wrap">
              <button v-for="t in [{id:'overview',label:'Overview',ico:'📋'},{id:'agreements',label:'Agreements',ico:'📄'},{id:'edit',label:'Edit',ico:'✏️'}]" :key="t.id" @click="eTab = t.id; if (t.id === 'edit') tenantForm.value = { ...tDrawer.tenant }" style="padding:9px 14px;border:none;background:none;font-size:13px;font-weight:700;cursor:pointer;border-bottom:2px solid transparent;color:var(--text-mute)" :style="eTab === t.id ? 'color:var(--primary);border-bottom-color:var(--primary)' : ''">{{ t.ico }} {{ t.label }} <span style="opacity:.7">({{ t.id === 'overview' ? '' : t.id === 'agreements' ? tDrawer.agreements.length : '' }})</span></button>
            </div>
            <div v-if="eTab === 'overview'">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:9px 16px">
                <div v-for="r in [[t('Type'), tDrawer.tenant.kind || 'Individual'], [t('Father name'), tDrawer.tenant.father_name || '—'], [t('Mother name'), tDrawer.tenant.mother_name || '—'], ['NID', tDrawer.tenant.nid || '—'], [t('Phone'), tDrawer.tenant.phone || '—'], [t('Email'), tDrawer.tenant.email || '—'], [t('Emergency contact'), tDrawer.tenant.emergency_contact || '—'], [t('Business name'), tDrawer.tenant.business_name || '—'], [t('Occupation'), tDrawer.tenant.occupation || tDrawer.tenant.employer || '—'], [t('City'), tDrawer.tenant.city || '—'], [t('Joined'), tDrawer.tenant.joined_at || '—'], [t('Tags'), tDrawer.tenant.tags || '—']]" :key="r[0]" style="display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed var(--border);padding:7px 0;font-size:12.5px"><span style="color:var(--text-mute)">{{ r[0] }}</span><b style="text-align:right">{{ r[1] }}</b></div>
              </div>
              <div v-if="tDrawer.tenant.present_address || tDrawer.tenant.permanent_address" style="margin-top:8px">
                <div v-if="tDrawer.tenant.present_address" style="display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed var(--border);padding:7px 0;font-size:12.5px"><span style="color:var(--text-mute)">{{ t('Present address') }}</span><b style="text-align:right">{{ tDrawer.tenant.present_address }}</b></div>
                <div v-if="tDrawer.tenant.permanent_address" style="display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed var(--border);padding:7px 0;font-size:12.5px"><span style="color:var(--text-mute)">{{ t('Permanent address') }}</span><b style="text-align:right">{{ tDrawer.tenant.permanent_address }}</b></div>
              </div>
              <div v-if="tenLines(tDrawer.tenant.family).length" style="margin-top:10px">
                <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">👨‍👩‍👧 {{ t('Family members') }}</div>
                <div v-for="m in tenLines(tDrawer.tenant.family)" :key="m" style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px dashed var(--border);font-size:12.5px"><span style="width:26px;height:26px;border-radius:50%;background:var(--bg-alt);display:flex;align-items:center;justify-content:center;font-size:11px">👤</span>{{ m }}</div>
              </div>
              <div v-if="tenLines(tDrawer.tenant.company).length" style="margin-top:10px">
                <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">🏢 {{ t('Company profile') }}</div>
                <div v-for="m in tenLines(tDrawer.tenant.company)" :key="m" style="display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed var(--border);padding:6px 0;font-size:12.5px"><span style="color:var(--text-mute)">{{ m.split(',')[0].trim() }}</span><b style="text-align:right">{{ m.split(',').slice(1).join(',').trim() }}</b></div>
              </div>
              <div v-if="tDrawer.tenant.notes" style="margin-top:10px;background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:12.5px;color:var(--text-mute)">📝 {{ tDrawer.tenant.notes }}</div>
            </div>
            <div v-else-if="eTab === 'edit'" style="display:flex;flex-direction:column;gap:10px">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <label style="font-size:12px;color:var(--text-mute)">{{ t('Full name *') }}<input v-model="tenantForm.name" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
                <label style="font-size:12px;color:var(--text-mute)">{{ t('Type') }}<select v-model="tenantForm.kind" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px"><option value="Individual">{{ t('Individual') }}</option><option value="Corporate">{{ t('Corporate') }}</option><option value="Company">{{ t('Company') }}</option></select></label>
                <label style="font-size:12px;color:var(--text-mute)">{{ t('Father name') }}<input v-model="tenantForm.father_name" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
                <label style="font-size:12px;color:var(--text-mute)">{{ t('Mother name') }}<input v-model="tenantForm.mother_name" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
                <label style="font-size:12px;color:var(--text-mute)">NID<input v-model="tenantForm.nid" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
                <label style="font-size:12px;color:var(--text-mute)">{{ t('Phone') }}<input v-model="tenantForm.phone" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
                <label style="font-size:12px;color:var(--text-mute)">{{ t('Email') }}<input v-model="tenantForm.email" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
                <label style="font-size:12px;color:var(--text-mute)">{{ t('Emergency contact') }}<input v-model="tenantForm.emergency_contact" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
                <label style="font-size:12px;color:var(--text-mute)">{{ t('Business name') }}<input v-model="tenantForm.business_name" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
                <label style="font-size:12px;color:var(--text-mute)">{{ t('Occupation') }}<input v-model="tenantForm.occupation" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
                <label style="font-size:12px;color:var(--text-mute)">{{ t('Present address') }}<input v-model="tenantForm.present_address" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
                <label style="font-size:12px;color:var(--text-mute)">{{ t('Permanent address') }}<input v-model="tenantForm.permanent_address" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
                <label style="font-size:12px;color:var(--text-mute)">{{ t('City') }}<input v-model="tenantForm.city" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
                <label style="font-size:12px;color:var(--text-mute)">{{ t('Joined') }}<input v-model="tenantForm.joined_at" type="date" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
                <label style="font-size:12px;color:var(--text-mute)">{{ t('Tags') }}<input v-model="tenantForm.tags" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
              </div>
              <label style="font-size:12px;color:var(--text-mute)">{{ t('Family members') }} <small>({{ t('one per line — Name, Relation') }})</small><textarea v-model="tenantForm.family" rows="2" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea></label>
              <label style="font-size:12px;color:var(--text-mute)">{{ t('Company profile') }} <small>({{ t('one line: Label, Value') }})</small><textarea v-model="tenantForm.company" rows="2" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea></label>
              <label style="font-size:12px;color:var(--text-mute)">{{ t('Notes') }}<textarea v-model="tenantForm.notes" rows="2" style="width:100%;margin-top:4px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea></label>
              <div style="display:flex;gap:10px;margin-top:6px">
                <button @click="saveTenantDrawer" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('💾 Save changes') }}</button>
                <button @click="eTab = 'overview'" class="btn-ghost" style="padding:11px 16px">{{ t('Cancel') }}</button>
              </div>
            </div>
            <div v-else class="drawer-tbl-wrap">
              <table class="kr" style="width:100%"><thead><tr><th>{{ t('Space') }}</th><th>{{ t('Rent/mo') }}</th><th>{{ t('Term') }}</th><th>{{ t('Advance') }}</th><th>{{ t('Status') }}</th></tr></thead><tbody>
                <tr v-for="a in tDrawer.agreements" :key="a.id"><td style="font-weight:700">{{ a.shop }}</td><td style="font-weight:700">{{ money(a.rent) }}</td><td style="font-size:12px">{{ a.start_date }}<template v-if="a.end_date"> → {{ a.end_date }}</template></td><td>{{ a.advance_months }} mo</td><td><span class="badge" :class="badge(a.status)">{{ bnd(a.status) }}</span></td></tr>
                <tr v-if="!tDrawer.agreements.length"><td colspan="5" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No agreements for this tenant.') }}</td></tr>
              </tbody></table>
              <div v-if="tDrawer.rent_payments.length" style="margin-top:12px">
                <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">{{ t('Rent collections') }}</div>
                <div v-for="p in tDrawer.rent_payments" :key="p.id" style="display:flex;gap:8px;align-items:center;border-bottom:1px dashed var(--border);padding:7px 0;font-size:12.5px"><b>{{ money(p.amount) }}</b><span class="badge b-gray" style="font-size:10px">{{ bnd(p.method) }}</span><span style="color:var(--text-mute);flex:1">{{ p.receipt }} · {{ p.month }}<template v-if="p.shop"> · {{ p.shop }}</template></span></div>
              </div>
            </div>
          </div>
        </template>
        <!-- COMMITTEE MEMBER -->
        <template v-else-if="mDrawer">
          <div style="height:104px;background:linear-gradient(135deg,#EB5757,#F2994A);position:relative;flex-shrink:0">
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:42px;opacity:.9">🏛️</div>
            <button @click="closeEntityDrawers" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          </div>
          <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
            <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ mDrawer.name }}</h2>
            <div class="c-sub" style="margin-top:3px">🏛️ Committee member · term {{ mDrawer.term || '—' }}</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Role') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ mDrawer.role }}</div></div>
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Space') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ mDrawer.shop || 'Independent' }}</div></div>
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Status') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px" :style="mDrawer.active ? 'color:var(--ok)' : 'color:var(--danger)'">{{ mDrawer.active ? 'Active' : 'Inactive' }}</div></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:9px 16px">
              <div v-for="r in [['Role', mDrawer.role], ['Phone', mDrawer.phone || '—'], ['Email', mDrawer.email || '—'], ['Space (owner of)', mDrawer.shop || 'Independent'], ['Term', mDrawer.term || '—'], ['Status', mDrawer.active ? 'Active' : 'Inactive']]" :key="r[0]" style="display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed var(--border);padding:7px 0;font-size:12.5px"><span style="color:var(--text-mute)">{{ r[0] }}</span><b style="text-align:right">{{ r[1] }}</b></div>
            </div>
          </div>
        </template>
        <!-- OWNER -->
        <template v-else-if="oDrawer">
          <div style="height:104px;background:linear-gradient(135deg,#2D9CDB,#27AE60);position:relative;flex-shrink:0">
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:42px;opacity:.9">🏢</div>
            <button @click="closeEntityDrawers" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
            <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap"><span class="badge" style="background:rgba(255,255,255,.2);color:#fff;border:none">{{ oDrawer.owner.type }}</span></div>
          </div>
          <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
            <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ oDrawer.owner.name }}</h2>
            <div class="c-sub" style="margin-top:3px">🏢 {{ oDrawer.owner.type }}<span v-if="oDrawer.owner.phone"> · {{ oDrawer.owner.phone }}</span></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Spaces') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ oDrawer.shops.length }}</div></div>
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Total paid') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px;color:var(--ok)">{{ money(oDrawer.total_paid) }}</div></div>
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px"><div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Due') }}</div><div style="font-size:14.5px;font-weight:800;margin-top:2px" :style="oDrawer.total_due > 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(oDrawer.total_due) }}</div></div>
            </div>
            <div style="display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:14px;flex-wrap:wrap">
              <button v-for="t in [{id:'overview',label:'Overview',ico:'📋'},{id:'spaces',label:'Spaces',ico:'🏪'}]" :key="t.id" @click="eTab = t.id" style="padding:9px 14px;border:none;background:none;font-size:13px;font-weight:700;cursor:pointer;border-bottom:2px solid transparent;color:var(--text-mute)" :style="eTab === t.id ? 'color:var(--primary);border-bottom-color:var(--primary)' : ''">{{ t.ico }} {{ t.label }} <span style="opacity:.7">({{ t.id === 'overview' ? '' : oDrawer.shops.length }})</span></button>
            </div>
            <div v-if="eTab === 'overview'" style="display:grid;grid-template-columns:1fr 1fr;gap:9px 16px">
              <div v-for="r in [['Type', oDrawer.owner.type || '—'], ['Phone', oDrawer.owner.phone || '—'], ['Email', oDrawer.owner.email || '—'], ['NID / TIN', oDrawer.owner.nid || '—'], ['Trade license', oDrawer.owner.trade_license || '—'], ['Contact person', oDrawer.owner.contact_person || '—'], ['Address', oDrawer.owner.address || '—'], ['Notes', oDrawer.owner.notes || '—']]" :key="r[0]" style="display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed var(--border);padding:7px 0;font-size:12.5px"><span style="color:var(--text-mute)">{{ r[0] }}</span><b style="text-align:right">{{ r[1] }}</b></div>
            </div>
            <div v-else class="drawer-tbl-wrap">
              <table class="kr" style="width:100%"><thead><tr><th>{{ t('Space') }}</th><th>{{ t('Floor') }}</th><th>{{ t('Type') }}</th><th>{{ t('Occupancy') }}</th><th style="text-align:right">{{ t('Paid') }}</th><th style="text-align:right">{{ t('Due') }}</th></tr></thead><tbody>
                <tr v-for="s in oDrawer.shops" :key="s.id"><td style="font-weight:700">{{ s.no }}</td><td>{{ s.floor }}</td><td>{{ s.space_type }}</td><td><span class="badge" :class="{ Owner: 'b-green', Rented: 'b-blue', Vacant: 'b-gray' }[s.occupancy] || 'b-gray'" style="font-size:10px">{{ s.occupancy }}</span></td><td style="text-align:right">{{ money(s.paid) }}</td><td style="text-align:right;font-weight:800" :style="s.due > 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(s.due) }}</td></tr>
                <tr v-if="!oDrawer.shops.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No spaces linked to this owner.') }}</td></tr>
              </tbody></table>
            </div>
          </div>
        </template>
      </div>
    </template>

    <!-- ═══════ RECEIPT MODAL ═══════ -->
    <div v-if="recModal" class="overlay" @click.self="recModal = null">
      <div class="modal" style="max-width:460px">
        <div class="modal-h"><div class="t">🖨️ Money receipt</div><button class="close" @click="recModal = null">✕</button></div>
        <div class="modal-b">
          <div id="receiptPrint" :class="'tpl-' + (recData.brand.invoice_template || 'classic')">
            <!-- ═══ CLASSIC: centered header, logo above the name ═══ -->
            <div v-if="(recData.brand.invoice_template || 'classic') === 'classic'" class="rc-head rc-classic">
              <img v-if="recLogo" :src="recLogo" alt="logo" style="max-height:52px;max-width:140px;object-fit:contain;margin:0 auto 8px" />
              <div class="rc-name">{{ recData.brand.mall_name || 'MALL MANAGEMENT' }}</div>
              <div class="rc-sub">{{ t('Money Receipt · Service Collection') }}</div>
              <div v-if="recData.brand.mall_address" class="rc-meta">{{ recData.brand.mall_address }}</div>
              <div v-if="recData.brand.mall_phone || recData.brand.mall_email" class="rc-meta">☎ {{ recData.brand.mall_phone }}<span v-if="recData.brand.mall_email"> · ✉ {{ recData.brand.mall_email }}</span></div>
            </div>
            <!-- ═══ MODERN: accent band — logo left, name left, title right ═══ -->
            <div v-else-if="recData.brand.invoice_template === 'modern'" class="rc-head rc-modern">
              <div class="rc-band-left">
                <img v-if="recLogo" :src="recLogo" alt="logo" style="max-height:40px;max-width:120px;object-fit:contain" />
                <div v-if="!recLogo" class="rc-name" style="color:#fff">{{ recData.brand.mall_name || 'MALL MANAGEMENT' }}</div>
                <div class="rc-band-sub">{{ recData.brand.mall_address }}<span v-if="recData.brand.mall_phone"> · ☎ {{ recData.brand.mall_phone }}</span><span v-if="recData.brand.mall_email"> · {{ recData.brand.mall_email }}</span></div>
              </div>
              <div class="rc-title" style="color:#fff">{{ t('MONEY') }}<br />{{ t('RECEIPT') }}</div>
            </div>
            <!-- ═══ MINIMAL: monochrome — logo/name left, title right, hairline ═══ -->
            <div v-else class="rc-head rc-minimal">
              <div class="rc-min-left">
                <img v-if="recLogo" :src="recLogo" alt="logo" style="max-height:36px;max-width:120px;object-fit:contain" />
                <div class="rc-name">{{ recData.brand.mall_name || 'MALL MANAGEMENT' }}</div>
                <div class="rc-meta">{{ recData.brand.mall_address }}<span v-if="recData.brand.mall_phone"> · ☎ {{ recData.brand.mall_phone }}</span><span v-if="recData.brand.mall_email"> · {{ recData.brand.mall_email }}</span></div>
              </div>
              <div class="rc-title">{{ t('MONEY RECEIPT') }}</div>
            </div>
            <table style="width:100%;font-size:13.5px;line-height:2">
              <tbody>
                <tr><td style="color:var(--text-mute)">{{ t('Receipt No') }}</td><td style="text-align:right;font-weight:800">{{ recData.payment.receipt }}</td></tr>
                <tr><td style="color:var(--text-mute)">{{ t('Date') }}</td><td style="text-align:right">{{ (recData.payment.created_at || '').slice(0, 16) }}</td></tr>
                <tr><td style="color:var(--text-mute)">{{ t('Space') }}</td><td style="text-align:right;font-weight:800">{{ recData.bill.shop_no }} · {{ recData.bill.shop_floor }} floor</td></tr>
                <tr><td style="color:var(--text-mute)">{{ t('Owner') }}</td><td style="text-align:right">{{ recData.bill.owner_name || '—' }}</td></tr>
                <tr><td style="color:var(--text-mute)">{{ t('Month') }}</td><td style="text-align:right">{{ monthLabel(recData.bill.month) }}</td></tr>
                <tr><td style="color:var(--text-mute)">{{ t('Charge') }}</td><td style="text-align:right">{{ { service: 'Service charge', elec: 'Electricity (sub-meter)', water: 'Water (sub-meter)' }[recData.bill.kind] }}</td></tr>
                <tr><td style="color:var(--text-mute)">{{ t('Amount') }}</td><td style="text-align:right;font-weight:800">{{ money(recData.bill.amount) }}</td></tr>
                <tr v-if="recData.bill.fine"><td style="color:var(--text-mute)">{{ t('Late fee') }}</td><td style="text-align:right;color:var(--danger)">{{ money(recData.bill.fine) }}</td></tr>
                <tr><td style="color:var(--text-mute)">{{ t('Paid via') }}</td><td style="text-align:right">{{ recData.payment.method }} <span v-if="recData.pay_acct_name" style="font-weight:800">· {{ recData.pay_acct_name }}</span> <span v-if="recData.payment.ref" style="color:var(--text-mute)">({{ recData.payment.ref }})</span></td></tr>
                <tr v-if="recData.brand.bank_name"><td style="color:var(--text-mute)">{{ t('Bank') }}</td><td style="text-align:right">{{ recData.brand.bank_name }}<span v-if="recData.brand.bank_account_no"> · A/C {{ recData.brand.bank_account_no }}</span></td></tr>
                <tr v-if="recData.brand.bank_account_title"><td style="color:var(--text-mute)">{{ t('A/C title') }}</td><td style="text-align:right">{{ recData.brand.bank_account_title }}</td></tr>
              </tbody>
            </table>
            <div style="display:flex;justify-content:space-between;margin-top:18px;padding-top:10px;border-top:1px solid var(--border);font-size:12px;color:var(--text-mute)">
              <span>{{ t('Prepared by: ________________') }}<br /><small style="font-size:10.5px">{{ recData.user_name || '—' }} — {{ recData.brand.invoice_prefix || 'Bill' }} preparer</small></span>
              <span>{{ t('Secretary: ________________') }}<span v-if="recData.brand.secretary"><br /><small style="font-size:10.5px">{{ recData.brand.secretary }} — General Secretary</small></span></span>
              <span>{{ t('President: ________________') }}<span v-if="recData.brand.chairman"><br /><small style="font-size:10.5px">{{ recData.brand.chairman }} — Chairman</small></span></span>
            </div>
            <div v-if="recData.brand.receipt_note" style="margin-top:12px;padding-top:8px;border-top:1px dashed var(--border);font-size:11px;color:var(--text-mute);text-align:center">{{ recData.brand.receipt_note }}</div>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="printReceipt" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('🖨️ Print receipt') }}</button>
            <button @click="recModal = null" class="btn-ghost" style="padding:11px 18px">{{ t('Close') }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style>
/* ── Receipt / invoice templates (Al Bayan pattern: classic · modern · minimal) ── */
.rc-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}
.rc-classic{flex-direction:column;text-align:center;border-bottom:2px dashed var(--border);padding-bottom:12px}
.rc-classic .rc-name{font-size:17px;font-weight:800}
.rc-classic .rc-sub{font-size:12px;color:var(--text-mute)}
.rc-meta{font-size:11.5px;color:var(--text-mute);margin-top:2px;text-align:center}
.rc-modern{background:linear-gradient(135deg,#1e3a5f 0%,#2F80ED 100%);border-radius:10px;padding:14px 16px;color:#fff}
.rc-modern .rc-band-left{display:flex;flex-direction:column;gap:3px;min-width:0}
.rc-modern .rc-band-sub{font-size:10.5px;opacity:.85;line-height:1.5}
.rc-title{font-size:15px;font-weight:900;letter-spacing:.5px;text-align:right;white-space:nowrap}
.rc-modern .rc-title{font-size:17px;line-height:1.15}
.rc-minimal{border-bottom:1px solid #c8c8c8;padding-bottom:10px}
.rc-minimal .rc-min-left{display:flex;flex-direction:column;gap:2px;min-width:0}
.rc-minimal .rc-name{font-size:15px;font-weight:800}
.rc-minimal .rc-title{color:#1f2937}
#receiptPrint.tpl-modern .rc-meta{color:#fff}
@media print {
  body * { visibility: hidden !important; }
  #receiptPrint, #receiptPrint * { visibility: visible !important; }
  #receiptPrint { position: fixed; left: 0; top: 0; width: 100%; background: #fff; color: #111; padding: 24px; }
  #printArea, #printArea * { visibility: visible !important; }
  #printArea { position: fixed; left: 0; top: 0; width: 100%; background: #fff; color: #111; padding: 20px 24px; }
  #printArea table.kr th { background: #f1f5f9 !important; color: #0f172a !important; border-color: #cbd5e1 !important; }
  #printArea table.kr td, #printArea table.kr th { border-color: #cbd5e1 !important; color: #0f172a !important; }
}
@media (max-width: 900px) { .dash-grid { grid-template-columns: 1fr !important; } }
@media (max-width: 900px) { .cm-grid { grid-template-columns: 1fr !important; } }
@media (max-width: 800px) { .pnl-grid { grid-template-columns: 1fr !important; } }
@media (max-width: 900px) { .an-grid { grid-template-columns: 1fr !important; } }
@media (max-width: 900px) { .rc-grid { grid-template-columns: 1fr !important; } }
.set-tabs::-webkit-scrollbar { display: none; }
@media (max-width: 640px) {
  .set-tabs { margin: 0 -12px; padding-left: 12px; padding-right: 12px; }
  .set-tabs button { font-size: 11px !important; padding: 7px 12px !important; }
}

@media (max-width: 640px) {
  .inv-hide-sm { display: none !important; }
  .inv-ssel { width: 100% !important; }
  .page-head select { max-width: 132px; }
  .modal { max-width: 96vw !important; }
}

.elink { cursor: pointer; color: var(--primary); font-weight: 600; }
.elink:hover { text-decoration: underline; }

.meter-info { grid-template-columns: 1fr 1fr 1.6fr; }
@media (max-width: 900px) { .meter-info { grid-template-columns: 1fr !important; } }
</style>
