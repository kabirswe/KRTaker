<script setup>
import { computed, ref, onMounted, watch } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import { useRoute } from 'vue-router'
import { apiCall } from '../api/client'
import { money, monthLabel, badge } from '../lib/ui'
import ScrollTabs from '../components/ScrollTabs.vue'

const route = useRoute()

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
function openAdd() { form.value = { status: 'Active', sqft: 0, service_rate: 0, opening_balance: 0, owner_id: 0, space_type: 'Shop', occupancy: 'Owner' }; modal.value = { mode: 'add', title: '➕ New shop' } }
function openEdit(s) {
  form.value = { no: s.no || '', floor: s.floor || '', sqft: s.sqft || 0, owner_name: s.owner_name || '', owner_mobile: s.owner_mobile || '', owner_nid: s.owner_nid || '', status: s.status || 'Active', service_rate: s.service_rate || 0, opening_balance: s.opening_balance || 0, owner_id: s.owner_id || 0, space_type: s.space_type || 'Shop', occupancy: s.occupancy || 'Owner' }
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
      owner_id: Number(form.value.owner_id) || 0, space_type: form.value.space_type || 'Shop', occupancy: form.value.occupancy || 'Owner',
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
  if (!config.value.late_fees_enabled) { window.__krToast?.('Late fees are disabled in ⚙️ Settings → Billing rules', 'err'); return }
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
/* receipt logo: modern/classic use the dark variant (colored band), minimal the light one */
const recLogo = computed(() => {
  const b = recData.value?.brand
  if (!b) return ''
  return (b.invoice_template === 'minimal' ? (b.logo || b.logo_dark) : (b.logo_dark || b.logo)) || ''
})

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
    window.__krToast?.('👤 Profile updated', 'ok')
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
  if (!staffForm.value.name.trim()) { window.__krToast?.('Name required.', 'err'); return }
  const action = staffModal.value.mode === 'edit' ? 'staff-update' : 'staff-add'
  const r = await apiCall('mall', { action, ...staffForm.value, salary: Number(staffForm.value.salary) || 0, ...(staffModal.value.mode === 'edit' ? { id: staffModal.value.id } : {}) })
  if (r.ok) { window.__krToast?.(staffModal.value.mode === 'edit' ? '✏️ Staff updated' : '✅ Staff added', 'ok'); staffModal.value = null; await loadStaff() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delStaff(s) {
  if (!window.confirm(`Remove staff "${s.name}"? (salary history is kept)`)) return
  const r = await apiCall('mall', { action: 'staff-del', id: s.id })
  if (r.ok) { window.__krToast?.('🗑️ Staff removed', 'ok'); await loadStaff() }
}
function openSal(s) {
  const paid = salaryHistory.value.some(h => h.staff_id === s.id)
  if (paid) { window.__krToast?.(`Salary already paid for ${monthLabel(month.value)}`, 'err'); return }
  salForm.value = { staff_id: s.id, staff_name: s.name, amount: s.salary, method: 'cash', note: '' }
  salModal.value = s
}
async function saveSalary() {
  if (!salModal.value || Number(salForm.value.amount) <= 0) return
  const r = await apiCall('mall', { action: 'salary-pay', staff_id: salForm.value.staff_id, month: month.value, amount: Number(salForm.value.amount), method: salForm.value.method, note: salForm.value.note })
  if (r.ok) { window.__krToast?.(`💸 ${r.staff} — ${money(r.amount)} paid`, 'ok'); salModal.value = null; await loadStaff(); await loadDash(); await loadLedger() }
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
  window.__krToast?.(`⬇ ${filename} exported`, 'ok')
}
function exportStaff() {
  exportCsv('staff-' + month.value + '.csv',
    ['Name', 'Designation', 'Phone', 'NID', 'Join date', 'Salary', 'Status', 'Salaries paid', 'Total paid'],
    staff.value.map(s => [s.name, s.designation, s.phone, s.nid, s.join_date, s.salary, s.status, s.salaries_paid, s.salaries_total]))
}
function exportBills() {
  exportCsv('bills-' + month.value + '.csv',
    ['Bill', 'Shop', 'Floor', 'Kind', 'Amount', 'Fine', 'Due date', 'Status'],
    bills.value.map(b => [b.id, b.shop_no, b.shop_floor, b.kind, b.amount, b.fine || 0, b.due_date, b.status]))
}
function exportLedger() {
  exportCsv('ledger-' + month.value + '.csv',
    ['Shop', 'Floor', 'Service paid/billed', 'Elec paid/billed', 'Water paid/billed', 'Due'],
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
  { cap: 'Shops master (add/edit/delete)', r: [1, 1, 1, 1, 0] },
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
  if (userModal.value.mode === 'add' && !userForm.value.name.trim()) { window.__krToast?.('Name required.', 'err'); return }
  const r = await apiCall('mall', payload)
  if (r.ok) { window.__krToast?.(userModal.value.mode === 'edit' ? '✏️ User updated' : '✅ User created', 'ok'); userModal.value = null; await loadUsers() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
function openReset(u) { resetForm.value = { id: u.id, name: u.name, password: '' }; resetModal.value = u }
async function saveReset() {
  if (resetForm.value.password.length < 8) { window.__krToast?.('Password must be at least 8 characters.', 'err'); return }
  const r = await apiCall('mall', { action: 'user-resetpw', id: resetForm.value.id, password: resetForm.value.password })
  if (r.ok) { window.__krToast?.(`🔑 Password reset for ${resetForm.value.name}`, 'ok'); resetModal.value = null; resetForm.value = { id: 0, name: '', password: '' } }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delUser(u) {
  if (!window.confirm(`Disable user "${u.name}" (${u.email})? Their audit trail stays.`)) return
  const r = await apiCall('mall', { action: 'user-del', id: u.id })
  if (r.ok) { window.__krToast?.('🗑️ User disabled', 'ok'); await loadUsers() }
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
  if (r.ok) { budgetDirty.value = false; window.__krToast?.('🎯 Budget saved', 'ok') }
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
const MEETING_TYPES = ['AGM', 'Executive', 'Emergency', 'Budget']
async function loadCommittee() { const r = await apiCall('mall', { action: 'committee' }); if (r.ok) committee.value = r }
function openMemberAdd() { memberForm.value = { role: 'Member', name: '', shop: '', phone: '', email: '', term: '', active: 1 }; memberModal.value = { mode: 'add', title: '➕ New committee member' } }
function openMemberEdit(m) {
  memberForm.value = { role: m.role, name: m.name, shop: m.shop, phone: m.phone, email: m.email, term: m.term, active: m.active }
  memberModal.value = { mode: 'edit', title: '✏️ Edit member', id: m.id }
}
async function saveMember() {
  if (!memberForm.value.name.trim()) { window.__krToast?.('Name required.', 'err'); return }
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
  if (!meetingForm.value.title.trim()) { window.__krToast?.('Title required.', 'err'); return }
  const r = await apiCall('mall', { action: 'meeting-add', ...meetingForm.value })
  if (r.ok) { window.__krToast?.('✅ Meeting recorded', 'ok'); meetingModal.value = false; await loadCommittee() }
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
  if (!resForm.value.title.trim()) { window.__krToast?.('Title required.', 'err'); return }
  const r = await apiCall('mall', { action: 'resolution-add', ...resForm.value, passed: resForm.value.passed ? 1 : 0 })
  if (r.ok) { window.__krToast?.('📜 Resolution recorded', 'ok'); resModal.value = false; await loadCommittee() }
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
  if (f.size > 700 * 1024) { window.__krToast?.('Logo too large — max 700KB.', 'err'); return }
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
  if (!ownerForm.value.name.trim()) { window.__krToast?.('Name required.', 'err'); return }
  const action = ownerModal.value.mode === 'edit' ? 'owner-update' : 'owner-add'
  const r = await apiCall('mall', { action, ...ownerForm.value, ...(ownerModal.value.mode === 'edit' ? { id: ownerModal.value.id } : {}) })
  if (r.ok) { window.__krToast?.(ownerModal.value.mode === 'edit' ? '✏️ Owner updated' : '✅ Owner added', 'ok'); ownerModal.value = null; await loadOwners(); if (tab.value === 'shops') loadShops() }
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
function openTenantAdd() { tenantForm.value = { name: '', phone: '', email: '', nid: '', address: '', employer: '', notes: '' }; tenantModal.value = { mode: 'add', title: '➕ New tenant' } }
function openTenantEdit(t) { tenantForm.value = { ...t }; tenantModal.value = { mode: 'edit', title: '✏️ Edit tenant', id: t.id } }
async function saveTenant() {
  if (!tenantForm.value.name.trim()) { window.__krToast?.('Name required.', 'err'); return }
  const action = tenantModal.value.mode === 'edit' ? 'tenant-update' : 'tenant-add'
  const r = await apiCall('mall', { action, ...tenantForm.value, ...(tenantModal.value.mode === 'edit' ? { id: tenantModal.value.id } : {}) })
  if (r.ok) { window.__krToast?.(tenantModal.value.mode === 'edit' ? '✏️ Tenant updated' : '✅ Tenant added', 'ok'); tenantModal.value = null; await loadTenants() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delTenant(t) {
  if (!window.confirm(`Delete tenant "${t.name}"?`)) return
  const r = await apiCall('mall', { action: 'tenant-del', id: t.id })
  if (r.ok) { window.__krToast?.('🗑️ Tenant deleted', 'ok'); await loadTenants() }
}
function openAgrAdd() { agrForm.value = { shop: '', tenant_id: 0, rent: 0, start_date: new Date().toISOString().slice(0, 10), end_date: '', advance_months: 0, due_day: 5, rent_collection: 0, status: 'Active', notes: '' }; agrModal.value = true }
async function saveAgreement() {
  if (!agrForm.value.shop) { window.__krToast?.('Shop required.', 'err'); return }
  const r = await apiCall('mall', { action: 'agreement-add', ...agrForm.value, rent_collection: agrForm.value.rent_collection ? 1 : 0 })
  if (r.ok) { window.__krToast?.('✅ Agreement saved', 'ok'); agrModal.value = false; await loadAgreements() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delAgreement(a) {
  if (!window.confirm(`Delete agreement for ${a.shop}?`)) return
  const r = await apiCall('mall', { action: 'agreement-del', id: a.id })
  if (r.ok) { window.__krToast?.('🗑️ Agreement deleted', 'ok'); await loadAgreements() }
}
function openRentCollect(a) { rentForm.value = { agreement_id: a.id, month: new Date().toISOString().slice(0, 7), amount: a.rent, method: 'cash', ref: '' }; rentModal.value = a }
async function saveRent() {
  const r = await apiCall('mall', { action: 'rent-collect', ...rentForm.value })
  if (r.ok) { window.__krToast?.(`✅ Rent collected — ${r.receipt}`, 'ok'); rentModal.value = null; await loadAgreements() }
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
  if (!vendorForm.value.name.trim()) { window.__krToast?.('Name required.', 'err'); return }
  const action = vendorModal.value.mode === 'edit' ? 'vendor-update' : 'vendor-add'
  const r = await apiCall('mall', { action, ...vendorForm.value, ...(vendorModal.value.mode === 'edit' ? { id: vendorModal.value.id } : {}) })
  if (r.ok) { window.__krToast?.(vendorModal.value.mode === 'edit' ? '✏️ Vendor updated' : '✅ Vendor added', 'ok'); vendorModal.value = null; await loadVendors() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
async function delVendor(v) {
  if (!window.confirm(`Delete vendor "${v.name}"?`)) return
  const r = await apiCall('mall', { action: 'vendor-del', id: v.id })
  if (r.ok) { window.__krToast?.('🗑️ Vendor deleted', 'ok'); await loadVendors() }
}
async function openVendorPay(v) {
  vendorPayForm.value = { vendor_id: v.id, amount: 0, method: 'bank', ref: '', note: '' }
  vendorPayModal.value = v
  const r = await apiCall('mall', { action: 'vendor-payments', vendor_id: v.id })
  if (r.ok) vendorPayments.value = r.payments
}
async function saveVendorPay() {
  if (!vendorPayForm.value.amount || vendorPayForm.value.amount <= 0) { window.__krToast?.('Amount required.', 'err'); return }
  const r = await apiCall('mall', { action: 'vendor-payment-add', ...vendorPayForm.value })
  if (r.ok) { window.__krToast?.('💸 Payment recorded', 'ok'); await openVendorPay(vendorPayModal.value); await loadVendors() }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}

/* ══════════ LICENSE (super admin reserved for the vendor) ══════════ */
const license = ref(null)
const licenseDirty = ref(false)
const isSuperAdmin = computed(() => ['superadmin'].includes(auth.user?.role || ''))
async function loadLicense() { const r = await apiCall('mall', { action: 'license-get' }); if (r.ok) license.value = r.license }
async function saveLicense() {
  const r = await apiCall('mall', { action: 'license-set', ...license.value })
  if (r.ok) { licenseDirty.value = false; window.__krToast?.('🔑 License updated', 'ok') }
  else window.__krToast?.(r.error || 'Failed.', 'err')
}
const licenseBadge = computed(() => {
  const l = license.value
  if (!l) return ''
  if (l.plan === 'One-off') return '🟢 One-off license'
  if (l.expiry && l.expiry < new Date().toISOString().slice(0, 10)) return '🔴 Expired ' + l.expiry
  return '🟢 ' + l.plan + (l.expiry ? ' · till ' + l.expiry : '')
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
  if (x === 'bills') loadBills()
  if (x === 'ledger') loadLedger()
  if (x === 'meters') { meterForm.value.month = month.value; loadMeters() }
  if (x === 'expenses') loadExpenses()
  if (x === 'complaints') loadComplaints()
  if (x === 'assets') loadAssets()
  if (x === 'notices') loadNotices()
  if (x === 'audit') loadAudit()
  if (x === 'staff') loadStaff()
  if (x === 'users') loadUsers()
  if (x === 'committee') loadCommittee()
  if (x === 'owners') loadOwners()
  if (x === 'rent') { loadTenants(); loadAgreements() }
  if (x === 'vendors') loadVendors()
  if (x === 'settings') { loadBudget(); loadLicense() }
  if (x === 'dashboard') { loadDash(); loadBalances() }
}

onMounted(async () => { await loadConfig(); await loadDash(); loadBalances() })

/* deep-links from global search: /mall?tab=<tab> */
watch(() => route.query.tab, (t) => { if (t && TABS.some(x => x[0] === t)) switchTab(t) }, { immediate: true })
</script>

<template>
  <div>
    <!-- page-head teleports INTO .topbar-in → actions ride the sticky header;
         the brand/title lives in the sidebar (property identity) -->
    <Teleport to=".topbar-in">
      <div class="page-head">
        <div class="head-actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:6px;background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:5px 8px">
            <button @click="shiftMonth(-1)" style="border:none;background:none;cursor:pointer;font-weight:800;color:var(--text)">◀</button>
            <input type="month" v-model="month" @change="switchTab(tab)" style="padding:6px 8px;border:none;background:transparent;color:var(--text);font-weight:700;font-size:13px;outline:none;font-family:inherit" />
            <button @click="shiftMonth(1)" style="border:none;background:none;cursor:pointer;font-weight:800;color:var(--text)">▶</button>
          </div>
          <button v-if="tab === 'shops' && canManage" @click="openAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Add shop</button>
        </div>
      </div>
    </Teleport>

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
      <div v-if="balances" class="stats" style="margin-top:0">
        <div v-for="(b, m) in balances" :key="m" v-show="m !== 'total'" class="stat">
          <div class="s-label"><span class="s-ico">{{ { cash: '💵', bank: '🏦', bkash: '📱', nagad: '📱' }[m] || '💰' }}</span>{{ b.label }}</div>
          <div class="s-value" :style="b.balance < 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(b.balance) }}</div>
          <div class="s-trend">in {{ money(b.in) }} · out {{ money(b.out) }}</div>
        </div>
        <div class="stat"><div class="s-label"><span class="s-ico">💰</span>Total balance</div><div class="s-value">{{ money(balances.total) }}</div><div class="s-trend">across all methods (spec 3.7)</div></div>
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
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
              <h3 style="font-size:14px">📉 Expenses by category — {{ monthLabel(month) }}</h3>
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
            <thead><tr><th>Shop</th><th>Floor</th><th>Sqft</th><th>Owner</th><th>Mobile</th><th>Type</th><th>Status</th><th style="text-align:right">Rate/mo</th><th></th></tr></thead>
            <tbody>
              <tr v-for="s in filteredShops" :key="s.id">
                <td><b>{{ s.no }}</b><br /><small style="color:var(--text-mute)">{{ s.id }}</small></td>
                <td>{{ s.floor }}</td>
                <td>{{ (s.sqft || 0).toLocaleString('en-IN') }}</td>
                <td>{{ s.owner_name || '—' }}</td>
                <td>{{ s.owner_mobile || '—' }}</td>
                <td><span class="badge b-gray" style="font-size:10px">{{ s.space_type || 'Shop' }}</span><br /><span class="badge" :class="{ Owner: 'b-green', Rented: 'b-blue', Vacant: 'b-gray' }[s.occupancy] || 'b-gray'" style="font-size:10px;margin-top:2px">{{ s.occupancy || 'Owner' }}</span></td>
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
        <div class="stat">
          <div class="s-label"><span class="s-ico">💸</span>Late fees</div>
          <div class="s-value" :style="config.late_fees_enabled ? '' : 'color:var(--text-mute);font-size:16px'">{{ config.late_fees_enabled ? money(billsTotals.fines) : 'OFF' }}</div>
          <div class="s-trend">{{ config.late_fees_enabled ? `${config.late_fee_pct}% · ${config.late_fee_grace}d grace · min ৳${config.late_fee_min} · cap ${config.late_fee_max_pct}%` : 'disabled in ⚙️ Settings' }}</div>
        </div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="generateBills" :disabled="billsBusy" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">⚙️ Generate service-charge bills</button>
        <button v-if="canManage" @click="calcFines" :disabled="finesBusy || !config.late_fees_enabled" class="btn-ghost" :title="config.late_fees_enabled ? 'Apply late payment fines to overdue bills' : 'Late fees are disabled in ⚙️ Settings → Billing rules'">💸 Compute late fees</button>
        <button v-if="canManage" @click="clearFines" class="btn-ghost" title="Remove all computed fines for this month">🧹 Clear fines</button>
        <button @click="exportBills" class="btn-ghost" title="Download this month's bills as Excel-compatible CSV">⬇ CSV</button>
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
          <div style="display:flex;gap:8px;align-items:center">
            <span class="badge b-red" style="font-size:12px">Total {{ money(expTotal) }}</span>
            <button @click="exportExpenses" class="btn-ghost" style="font-size:12px">⬇ CSV</button>
          </div>
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

    <!-- ═══════ STAFF & SALARIES ═══════ -->
    <template v-if="tab === 'staff'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🧑‍💼</span>Total staff</div><div class="s-value">{{ staff.length }}</div><div class="s-trend">{{ staffMeta.active }} active</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🟢</span>Active</div><div class="s-value" style="color:var(--ok)">{{ staffMeta.active }}</div><div class="s-trend">on payroll</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💸</span>Monthly payroll</div><div class="s-value">{{ money(staffMeta.payroll_monthly) }}</div><div class="s-trend">active staff salaries</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📅</span>Paid this month</div><div class="s-value">{{ salaryHistory.length }}</div><div class="s-trend">{{ monthLabel(month) }}</div></div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openStaffAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Add staff</button>
        <button @click="exportStaff" class="btn-ghost">⬇ CSV</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Office staff &amp; security guards — monthly salary entry posts to the expense ledger (spec 3.4)</span>
      </div>
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>Name</th><th>Designation</th><th>Phone</th><th>Joined</th><th style="text-align:right">Salary/mo</th><th>Status</th><th style="text-align:right">Paid</th><th></th></tr></thead>
            <tbody>
              <tr v-for="s in staff" :key="s.id">
                <td><b>{{ s.name }}</b><br /><small style="color:var(--text-mute)">{{ s.nid || '' }}</small></td>
                <td><span class="badge b-blue">{{ s.designation }}</span></td>
                <td>{{ s.phone || '—' }}</td>
                <td style="font-size:12px;color:var(--text-mute)">{{ s.join_date || '—' }}</td>
                <td style="text-align:right;font-weight:800">{{ money(s.salary) }}</td>
                <td><span class="badge" :class="badge(s.status)">{{ s.status }}</span></td>
                <td style="text-align:right;font-size:12px;color:var(--text-mute)">{{ s.salaries_paid || 0 }}× {{ money(s.salaries_total) }}</td>
                <td style="text-align:right;white-space:nowrap">
                  <button v-if="canManage && s.status === 'Active'" @click="openSal(s)" title="Pay monthly salary" style="padding:6px 12px;border:none;border-radius:8px;background:var(--ok);color:#fff;font-size:12px;font-weight:800;cursor:pointer">💸 Pay salary</button>
                  <button v-if="canManage" @click="openStaffEdit(s)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px;margin-left:4px">✏️</button>
                  <button v-if="canManage" @click="delStaff(s)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:5px 9px;cursor:pointer;font-size:12px;margin-left:4px">🗑️</button>
                </td>
              </tr>
              <tr v-if="!staff.length"><td colspan="8" style="text-align:center;color:var(--text-mute);padding:28px">No staff yet — add security guards &amp; office staff with ＋ Add staff.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="panel" style="padding:16px;margin-top:16px" v-if="salaryHistory.length">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <h3 style="font-size:14px">🧾 Salary payments — {{ monthLabel(month) }}</h3>
          <button @click="exportSalaries" class="btn-ghost" style="font-size:12px">⬇ CSV</button>
        </div>
        <div class="tbl-wrap" style="max-height:240px">
          <table class="kr">
            <thead><tr><th>Staff</th><th>Designation</th><th>Method</th><th>Note</th><th style="text-align:right">Amount</th></tr></thead>
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
          <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
          <div class="s-value">{{ k.value }}</div>
          <div class="s-trend">{{ k.trend || '' }}</div>
        </div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManageUsers" @click="openUserAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Add system user</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Role-based access control (spec 3.8) — users are assigned a role; each role sees only what it may do</span>
      </div>
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>User</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead>
            <tbody>
              <tr v-for="u in users" :key="u.id">
                <td><b>{{ u.name }}</b><span v-if="u.self" class="badge b-blue" style="margin-left:6px;font-size:10px">you</span></td>
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
              <tr v-if="!users.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:28px">No system users yet.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="panel" style="padding:16px;margin-top:16px">
        <h3 style="font-size:14px;margin-bottom:4px">🎭 Role access matrix</h3>
        <p style="font-size:12px;color:var(--text-mute);margin-bottom:10px">What each role can do in this system — enforced server-side on every action</p>
        <div class="tbl-wrap" style="max-height:none">
          <table class="kr">
            <thead><tr><th>Capability</th><th v-for="c in ROLE_COLS" :key="c" style="text-align:center">{{ c }}</th></tr></thead>
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
        <div class="stat"><div class="s-label"><span class="s-ico">🏛️</span>Committee members</div><div class="s-value">{{ committee.counts.members }}</div><div class="s-trend">{{ committee.counts.active }} active</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">👑</span>Office bearers</div><div class="s-value">{{ committee.members.filter(m => m.role !== 'Member' && m.active).length }}</div><div class="s-trend">chairman · secretary · treasurer</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📅</span>Meetings</div><div class="s-value">{{ committee.counts.meetings }}</div><div class="s-trend">{{ committee.counts.agm }} AGM</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📜</span>Resolutions</div><div class="s-value">{{ committee.counts.resolutions }}</div><div class="s-trend">passed &amp; archived (spec 3.11)</div></div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openMemberAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Add member</button>
        <button v-if="canManage" @click="openMeetingAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--ok);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">📅 Log meeting</button>
        <button v-if="canManage" @click="openResAdd()" style="padding:9px 14px;border:none;border-radius:10px;background:var(--bg-alt);border:1px solid var(--border);color:var(--text);font-size:12.5px;font-weight:800;cursor:pointer">📜 Add resolution</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">{{ config.mall_name || 'Mall' }} Market Owners' Committee — term 2024–2026</span>
      </div>
      <!-- office bearers grid -->
      <div v-if="committee && committee.members.length" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px;margin-bottom:16px">
        <div v-for="m in committee.members" :key="m.id" class="panel chip" style="padding:15px;display:flex;gap:12px;align-items:center;cursor:default">
          <div style="width:44px;height:44px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:#fff" :style="{ background: memberColor(m) }">{{ memberAvatar(m) }}</div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:800;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ m.name }}</div>
            <div style="display:flex;align-items:center;gap:6px;margin-top:3px;flex-wrap:wrap">
              <span class="badge" :class="{ Chairman: 'b-red', 'Vice Chairman': 'b-orange', Secretary: 'b-blue', Treasurer: 'b-green', Member: 'b-gray' }[m.role] || 'b-gray'">{{ m.role }}</span>
              <span v-if="!m.active" class="badge b-red" style="font-size:10px">inactive</span>
            </div>
            <div style="font-size:11px;color:var(--text-mute);margin-top:4px">{{ m.shop ? 'Shop ' + m.shop : 'Independent' }}<span v-if="m.phone"> · {{ m.phone }}</span></div>
          </div>
          <div v-if="canManage" style="display:flex;flex-direction:column;gap:4px;flex-shrink:0">
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
              <div v-if="m.agenda" style="font-size:12px;color:var(--text-mute);margin-top:6px"><b>Agenda:</b> {{ m.agenda }}</div>
              <div v-if="m.decisions" style="font-size:12px;margin-top:4px"><b>Decisions:</b> {{ m.decisions }}</div>
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
        <div class="stat"><div class="s-label"><span class="s-ico">🏢</span>Owners</div><div class="s-value">{{ ownerCounts.total || owners.length }}</div><div class="s-trend">persons + entities</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🏭</span>Companies / entities</div><div class="s-value">{{ ownerCounts.companies || 0 }}</div><div class="s-trend">company, bank, trust…</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🏪</span>Spaces owned</div><div class="s-value">{{ owners.reduce((s, o) => s + o.shops, 0) }}</div><div class="s-trend">one owner can own many</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">👥</span>Multi-space owners</div><div class="s-value">{{ owners.filter(o => o.shops > 1).length }}</div><div class="s-trend">portfolio owners</div></div>
      </div>
      <div style="display:flex;gap:10px;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openOwnerAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Add owner</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Flexible ownership — a building can be owned by one person, many persons, or companies/banks. Owner-occupied spaces only bear the service charge.</span>
      </div>
      <div v-if="owners.length" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px">
        <div v-for="o in owners" :key="o.id" class="panel chip" style="padding:15px;display:flex;gap:12px;align-items:center;cursor:pointer" @click="openOwnerProfile(o)">
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
      <div v-else class="panel" style="padding:24px;text-align:center;color:var(--text-mute)">No owners yet — add persons &amp; entities, then assign spaces in 🏪 Shops.</div>
    </template>

    <!-- ═══════ RENT & TENANTS ═══════ -->
    <template v-if="tab === 'rent'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🧑‍🤝‍🧑</span>Tenants</div><div class="s-value">{{ tenants.length }}</div><div class="s-trend">occupants (KRTaker-style profile)</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📄</span>Rental agreements</div><div class="s-value">{{ agreements.length }}</div><div class="s-trend">{{ agreements.filter(a => a.rent_collection).length }} with rent collection</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💵</span>Rent collected</div><div class="s-value" style="color:var(--ok)">{{ money(rentStats.collected) }}</div><div class="s-trend">optional service for owners</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⏳</span>Rent outstanding</div><div class="s-value" :style="rentStats.outstanding > 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(rentStats.outstanding) }}</div><div class="s-trend">due months × rent</div></div>
      </div>
      <div style="display:flex;gap:10px;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openTenantAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Add tenant</button>
        <button v-if="canManage" @click="openAgrAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--ok);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">📄 New agreement</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Rent collection is an <b>optional service</b> — owners may collect rent themselves; the committee can manage it on request.</span>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1.35fr;gap:16px" class="rt-grid">
        <div class="panel" style="padding:16px">
          <h3 style="font-size:14px;margin-bottom:10px">🧑‍🤝‍🧑 Tenants / occupants</h3>
          <div v-if="tenants.length" style="display:flex;flex-direction:column;gap:8px">
            <div v-for="t in tenants" :key="t.id" style="border:1px solid var(--border);border-radius:12px;padding:11px 13px;display:flex;gap:10px;align-items:center">
              <div style="width:34px;height:34px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff" :style="{ background: memberColor({ id: t.id, name: t.name }) }">{{ memberAvatar({ name: t.name }) }}</div>
              <div style="flex:1;min-width:0">
                <div style="font-weight:800;font-size:13px">{{ t.name }}</div>
                <div style="font-size:11px;color:var(--text-mute)">{{ t.phone }}<span v-if="t.nid"> · NID {{ t.nid }}</span><span v-if="t.employer"> · {{ t.employer }}</span></div>
                <span v-if="t.agreements" class="badge b-blue" style="font-size:10px;margin-top:3px">{{ t.agreements }} active agreement(s)</span>
              </div>
              <div v-if="canManage" style="display:flex;flex-direction:column;gap:4px;flex-shrink:0">
                <button @click="openTenantEdit(t)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:4px 8px;cursor:pointer;font-size:11px">✏️</button>
                <button @click="delTenant(t)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:4px 8px;cursor:pointer;font-size:11px">🗑️</button>
              </div>
            </div>
          </div>
          <p v-else style="color:var(--text-mute);font-size:12.5px">No tenants yet.</p>
        </div>
        <div class="panel" style="padding:16px">
          <h3 style="font-size:14px;margin-bottom:10px">📄 Rental agreements</h3>
          <div v-if="agreements.length" style="display:flex;flex-direction:column;gap:9px">
            <div v-for="a in agreements" :key="a.id" style="border:1px solid var(--border);border-radius:12px;padding:12px 14px">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <b style="font-size:13px">{{ a.shop }}</b>
                <span class="badge" :class="{ Active: 'b-green', Expired: 'b-gray', Terminated: 'b-red' }[a.status] || 'b-gray'">{{ a.status }}</span>
                <span v-if="a.rent_collection" class="badge b-blue" style="font-size:10px">committee collects rent</span>
                <span v-else class="badge b-gray" style="font-size:10px">owner collects</span>
                <span style="flex:1"></span>
                <span style="font-weight:800;font-size:13px">{{ money(a.rent) }}/mo</span>
              </div>
              <div style="font-size:11.5px;color:var(--text-mute);margin-top:5px">{{ a.tenant_name || '—' }} · {{ a.start_date }}<span v-if="a.end_date"> → {{ a.end_date }}</span><span v-if="a.advance_months"> · {{ a.advance_months }} mo advance</span></div>
              <div v-if="a.rent_collection" style="display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap">
                <span style="font-size:12px;font-weight:700" :style="a.rent_due > 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ a.due_months }} mo due · {{ money(a.rent_due) }}</span>
                <span style="font-size:11px;color:var(--text-mute)">{{ a.paid_months }} mo paid</span>
                <button v-if="canManage" @click="openRentCollect(a)" style="margin-left:auto;padding:7px 13px;border:none;border-radius:9px;background:var(--ok);color:#fff;font-size:12px;font-weight:800;cursor:pointer">💵 Collect rent</button>
                <button v-if="canManage" @click="delAgreement(a)" style="border:1px solid var(--border);background:var(--bg-alt);border-radius:8px;padding:6px 9px;cursor:pointer;font-size:11px">🗑️</button>
              </div>
            </div>
          </div>
          <p v-else style="color:var(--text-mute);font-size:12.5px">No rental agreements — add one to track the occupant, rent &amp; term.</p>
        </div>
      </div>
    </template>

    <!-- ═══════ VENDORS ═══════ -->
    <template v-if="tab === 'vendors'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🧰</span>Vendors</div><div class="s-value">{{ vendors.length }}</div><div class="s-trend">profiles + ledgers</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💸</span>Total paid</div><div class="s-value" style="color:var(--danger)">{{ money(vendorsTotal) }}</div><div class="s-trend">payment tracking</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🔧</span>Categories</div><div class="s-value">{{ new Set(vendors.map(v => v.category).filter(Boolean)).size }}</div><div class="s-trend">security, lift, AC…</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📑</span>Payments</div><div class="s-value">{{ vendors.reduce((s, v) => s + v.payments, 0) }}</div><div class="s-trend">every payment tracked</div></div>
      </div>
      <div style="display:flex;gap:10px;align-items:center;margin-bottom:14px">
        <button v-if="canManage" @click="openVendorAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Add vendor</button>
        <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Vendor profile · payment ledger · every payout tracked with method &amp; reference.</span>
      </div>
      <div v-if="vendors.length" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px">
        <div v-for="v in vendors" :key="v.id" class="panel chip" style="padding:15px;display:flex;gap:12px;align-items:center">
          <div style="width:44px;height:44px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff" :style="{ background: memberColor({ id: v.id, name: v.name }) }">{{ memberAvatar({ name: v.name }) }}</div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:800;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ v.name }}</div>
            <div style="display:flex;align-items:center;gap:6px;margin-top:3px;flex-wrap:wrap">
              <span class="badge b-gray" style="font-size:10px">{{ v.category }}</span>
              <span class="badge b-red" style="font-size:10px">{{ money(v.paid) }} paid</span>
            </div>
            <div style="font-size:11px;color:var(--text-mute);margin-top:4px">{{ v.contact_person ? v.contact_person + ' · ' : '' }}{{ v.phone }}<span v-if="v.payments"> · {{ v.payments }} payments</span></div>
          </div>
          <div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0">
            <button v-if="canManage" @click="openVendorPay(v)" style="padding:6px 10px;border:none;border-radius:8px;background:var(--ok);color:#fff;font-size:11.5px;font-weight:800;cursor:pointer">💸 Pay</button>
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
            <div class="s-label"><span class="s-ico">📉</span>Expenses</div>
            <div class="s-value" style="color:var(--danger);font-size:18px">{{ money(ledger.expenses) }}</div>
            <div class="s-trend">all categories</div>
          </div>
        </div>
        <div class="panel" style="padding:16px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <h3 style="font-size:14px">🏪 Per-shop ledger — {{ monthLabel(ledger.month) }}</h3>
            <div style="display:flex;gap:8px;align-items:center">
              <span class="badge b-green" style="font-size:12px">Net balance {{ money(Number(ledger.by_kind.reduce((s, k) => s + k.collected, 0)) - Number(ledger.expenses)) }}</span>
              <button @click="exportLedger" class="btn-ghost" style="font-size:12px">⬇ CSV</button>
            </div>
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
      <!-- ═══════ CUSTODIAL RECONCILIATION (spec 3.3) ═══════ -->
      <div v-if="recon" class="panel" style="padding:16px;margin-top:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">
          <h3 style="font-size:14px">⚡💧 Custodial fund reconciliation — DESCO/WASA (spec 3.3)</h3>
          <span class="badge" :class="recon.current_balance >= 0 ? 'b-green' : 'b-orange'" style="font-size:12px">{{ monthLabel(recon.month) }}: {{ recon.current_balance >= 0 ? 'forward ' : 'shortfall ' }}{{ money(Math.abs(recon.current_balance)) }}</span>
        </div>
        <p style="font-size:12px;color:var(--text-mute);margin-bottom:12px">Shop collections for electricity &amp; water are <b>custodial</b> — collected from shop owners, forwarded to the utility. Compare with the DESCO / WASA main bills paid.</p>
        <div class="tbl-wrap" style="max-height:300px">
          <table class="kr">
            <thead><tr><th></th><th style="text-align:right">Elec collected</th><th style="text-align:right">Water collected</th><th style="text-align:right">DESCO bill paid</th><th style="text-align:right">WASA bill paid</th><th style="text-align:right">Balance</th></tr></thead>
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
                <td><b>All time</b></td>
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
      <p v-else style="color:var(--text-mute)">Loading ledger…</p>
    </template>

    <!-- ═══════ SETTINGS ═══════ -->
    <template v-if="tab === 'settings'">
      <div v-if="canManage" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px">
        <div class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:12px">🏬 Mall profile</h3>
          <label style="font-size:12px;color:var(--text-mute)">Mall name
            <input v-model="config.mall_name" placeholder="e.g. Razzak Plaza" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Address
            <input v-model="config.mall_address" placeholder="e.g. 42 Motijheel C/A, Dhaka 1000" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
          </label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px">
            <label style="font-size:12px;color:var(--text-mute)">Phone
              <input v-model="config.mall_phone" placeholder="e.g. 02-9551234" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Email
              <input v-model="config.mall_email" placeholder="office@razzakplaza.com" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Chairman
              <input v-model="config.chairman" placeholder="e.g. Alhaj Md. Abdul Razzak" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Secretary
              <input v-model="config.secretary" placeholder="e.g. Md. Shahidullah" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
          </div>
        </div>
        <div class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:12px">⚖️ Billing rules</h3>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">Elec rate (৳/unit)
              <input type="number" v-model.number="config.elec_unit_rate" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Water rate (৳/unit)
              <input type="number" v-model.number="config.water_unit_rate" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Due day of month
              <input type="number" v-model.number="config.due_day" min="1" max="28" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <div style="display:flex;align-items:flex-end;padding-bottom:6px">
              <label style="font-size:12px;color:var(--text-mute);display:flex;align-items:center;gap:9px;cursor:pointer">
                <span class="lf-switch" :class="{ on: !!config.late_fees_enabled }" @click="config.late_fees_enabled = config.late_fees_enabled ? 0 : 1; cfgDirty = true" style="width:40px;height:22px;border-radius:99px;background:config.late_fees_enabled ? 'var(--ok,#27AE60)' : 'var(--border,#cbd5e1)';position:relative;transition:background .15s;flex-shrink:0">
                  <span style="position:absolute;top:2px;left:config.late_fees_enabled ? '20px' : '2px';width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:left .15s"></span>
                </span>
                <b :style="config.late_fees_enabled ? '' : 'color:var(--danger)'">{{ config.late_fees_enabled ? 'Late fees ON' : 'Late fees OFF' }}</b>
              </label>
            </div>
          </div>
          <div v-if="config.late_fees_enabled" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border)">
            <label style="font-size:12px;color:var(--text-mute)">Fine rate (% of bill)
              <input type="number" v-model.number="config.late_fee_pct" min="0" max="100" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Grace days (after due date)
              <input type="number" v-model.number="config.late_fee_grace" min="0" max="60" title="Days after the due date before a fine applies" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Minimum fine (৳)
              <input type="number" v-model.number="config.late_fee_min" min="0" title="Even small bills pay at least this fine" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Max cap (% of bill)
              <input type="number" v-model.number="config.late_fee_max_pct" min="1" max="100" title="A fine can never exceed this % of the bill" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
            </label>
          </div>
          <p style="font-size:11.5px;color:var(--text-mute);margin-top:10px">💡 Fines auto-apply to unpaid bills past the due date (+ grace) when you press <b>💸 Compute late fees</b> on the Bills tab. Rounded to the nearest ৳5.</p>
        </div>
        <div class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:4px">🖨️ Invoice settings &amp; property logo</h3>
          <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">Branding used on printed receipts — logo, template &amp; prefix. The sidebar keeps the product brand; the property name &amp; logo live on the document.</p>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <div style="font-size:12px;color:var(--text-mute);margin-bottom:6px">Logo (light background)</div>
              <div style="display:flex;align-items:center;gap:10px">
                <div style="width:56px;height:56px;border-radius:10px;border:1px dashed var(--border);background:var(--bg-alt);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
                  <img v-if="config.mall_logo" :src="config.mall_logo" alt="logo" style="max-width:100%;max-height:100%;object-fit:contain" />
                  <span v-else style="font-size:18px;opacity:.4">🖼️</span>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px">
                  <label style="font-size:11.5px;color:var(--primary);font-weight:700;cursor:pointer">⬆ Upload<input type="file" accept="image/*" style="display:none" @change="onLogoPick($event, 'mall_logo')" /></label>
                  <button v-if="config.mall_logo" @click="removeLogo('mall_logo')" style="border:none;background:none;color:var(--danger);font-size:11px;cursor:pointer;text-align:left">🗑 Remove</button>
                </div>
              </div>
              <div style="font-size:10.5px;color:var(--text-mute);margin-top:5px">White paper (minimal template), light areas</div>
            </div>
            <div>
              <div style="font-size:12px;color:var(--text-mute);margin-bottom:6px">Logo (dark background)</div>
              <div style="display:flex;align-items:center;gap:10px">
                <div style="width:56px;height:56px;border-radius:10px;border:1px dashed var(--border);background:#1e3a5f;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
                  <img v-if="config.mall_logo_dark" :src="config.mall_logo_dark" alt="logo dark" style="max-width:100%;max-height:100%;object-fit:contain" />
                  <span v-else style="font-size:18px;opacity:.4">🖼️</span>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px">
                  <label style="font-size:11.5px;color:var(--primary);font-weight:700;cursor:pointer">⬆ Upload<input type="file" accept="image/*" style="display:none" @change="onLogoPick($event, 'mall_logo_dark')" /></label>
                  <button v-if="config.mall_logo_dark" @click="removeLogo('mall_logo_dark')" style="border:none;background:none;color:var(--danger);font-size:11px;cursor:pointer;text-align:left">🗑 Remove</button>
                </div>
              </div>
              <div style="font-size:10.5px;color:var(--text-mute);margin-top:5px">Colored bands (classic/modern). Falls back to the normal logo.</div>
            </div>
          </div>
          <div style="font-size:12px;color:var(--text-mute);margin:14px 0 6px">Receipt template</div>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
            <button v-for="t in INVOICE_TEMPLATES" :key="t.key" @click="config.invoice_template = t.key; cfgDirty = true"
              :title="t.desc" style="padding:10px 8px;border-radius:10px;cursor:pointer;font-size:11.5px;font-weight:800;text-align:center;font-family:inherit"
              :style="config.invoice_template === t.key ? 'background:var(--primary);color:#fff;border:2px solid var(--primary)' : 'background:var(--bg-alt);color:var(--text);border:2px solid var(--border)'">
              {{ t.name }}
            </button>
          </div>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:14px">Receipt / invoice prefix
            <input v-model="config.invoice_prefix" maxlength="8" placeholder="RCT" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;font-weight:700;text-transform:uppercase" @input="cfgDirty = true" />
          </label>
        </div>
        <div class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:4px">🔑 License &amp; plan</h3>
          <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">The solution is sold as <b>one-off, yearly subscription/license, or user/monthly</b>. The <b>super admin</b> account is reserved for the vendor (Mall Manager by Deshik Lab) — the owning company, somity/committee or private owner manages day-to-day operations.</p>
          <div v-if="license" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">Plan
              <select v-model="license.plan" :disabled="!isSuperAdmin" @change="licenseDirty = true" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="p in ['One-off', 'Yearly', 'Monthly']" :key="p" :value="p">{{ p }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Expiry
              <input type="date" v-model="license.expiry" :disabled="!isSuperAdmin" @change="licenseDirty = true" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">User seats
              <input type="number" v-model.number="license.seats" min="1" :disabled="!isSuperAdmin" @input="licenseDirty = true" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">License holder
              <input v-model="license.holder" :disabled="!isSuperAdmin" placeholder="e.g. Razzak Plaza Owners' Committee" @input="licenseDirty = true" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </div>
          <div v-if="license" style="display:flex;align-items:center;gap:10px;margin-top:14px;flex-wrap:wrap">
            <span class="badge" :class="license.plan === 'One-off' ? 'b-green' : license.expiry && license.expiry < new Date().toISOString().slice(0, 10) ? 'b-red' : 'b-blue'" style="font-size:11px">{{ licenseBadge }}</span>
            <button v-if="isSuperAdmin && licenseDirty" @click="saveLicense" style="padding:9px 16px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">🔑 Save license</button>
            <span v-if="!isSuperAdmin" style="font-size:11px;color:var(--text-mute)">🔒 Only the super admin (vendor) can change the license.</span>
          </div>
        </div>
        <div class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:12px">🏦 Bank details (shown on receipts)</h3>
          <label style="font-size:12px;color:var(--text-mute)">Bank name
            <input v-model="config.bank_name" placeholder="e.g. Islami Bank Bangladesh PLC" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Account title
            <input v-model="config.bank_account_title" placeholder="e.g. Razzak Plaza Owners' Committee" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Account number
            <input v-model="config.bank_account_no" placeholder="e.g. 205-123-4567" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" @input="cfgDirty = true" />
          </label>
        </div>
        <div class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:12px">🧾 Receipt note</h3>
          <label style="font-size:12px;color:var(--text-mute)">Footer line on printed receipts
            <textarea v-model="config.receipt_note" rows="3" placeholder="e.g. Service charges are payable by the 10th of every month. Thank you for your cooperation." style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical" @input="cfgDirty = true"></textarea>
          </label>
        </div>
        <div class="panel" style="padding:18px">
          <h3 style="font-size:14px;margin-bottom:4px">🎯 Monthly budget (spec 3.7)</h3>
          <p style="font-size:11.5px;color:var(--text-mute);margin-bottom:12px">Set a budget per expense category — the dashboard compares actual vs budget each month. Leave ৳0 to skip a category.</p>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <label v-for="c in EXP_CATEGORIES" :key="c" style="font-size:11.5px;color:var(--text-mute)">{{ c }}
              <input type="number" min="0" step="500" :value="budget[c] ?? 0" @input="budget[c] = Number($event.target.value) || 0; budgetDirty = true" style="width:100%;margin-top:3px;padding:9px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </div>
          <div style="display:flex;align-items:center;gap:10px;margin-top:14px">
            <button @click="saveBudget" :disabled="!budgetDirty" style="padding:10px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">💾 Save budget</button>
            <span style="font-size:12px;color:var(--text-mute)">Total {{ money(budgetTotal) }}/mo</span>
          </div>
        </div>
      </div>
      <div v-if="canManage" style="margin-top:14px">
        <button @click="saveConfig" :disabled="!cfgDirty" style="padding:11px 22px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save mall settings</button>
        <span v-if="cfgDirty" style="margin-left:10px;font-size:12px;color:var(--text-mute)">Unsaved changes…</span>
      </div>

      <!-- 👤 Profile management -->
      <div class="panel" style="padding:18px;margin-top:16px;max-width:560px">
        <h3 style="font-size:14px;margin-bottom:4px">👤 My profile</h3>
        <p style="font-size:12px;color:var(--text-mute);margin-bottom:14px">Logged in as <b>{{ auth.user?.email }}</b> · role: <span class="badge b-blue">{{ auth.user?.role }}</span> — full profile &amp; preferences also available from the ⚙️ icon (top right)</p>
        <label style="font-size:12px;color:var(--text-mute)">Display name
          <input v-model="profForm.name" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px">
          <label style="font-size:12px;color:var(--text-mute)">Current password
            <input type="password" v-model="profForm.old_password" autocomplete="current-password" placeholder="required to change password" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute)">New password
            <input type="password" v-model="profForm.new_password" autocomplete="new-password" placeholder="min 8 characters" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
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
            <label style="font-size:12px;color:var(--text-mute)">Shop no *<input v-model="form.no" placeholder="e.g. A-101" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Floor<input v-model="form.floor" placeholder="e.g. Ground" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Size (sqft)<input type="number" v-model.number="form.sqft" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Service rate (৳/mo)<input type="number" v-model.number="form.service_rate" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Owner name *<input v-model="form.owner_name" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Owner mobile<input v-model="form.owner_mobile" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Owner NID<input v-model="form.owner_nid" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Owner (directory)
              <select v-model="form.owner_id" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option :value="0">— standalone (name above) —</option>
                <option v-for="o in owners" :key="o.id" :value="o.id">{{ o.name }} ({{ o.type }})</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Space type
              <select v-model="form.space_type" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="t in SPACE_TYPES" :key="t" :value="t">{{ t }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Occupancy
              <select v-model="form.occupancy" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="o in OCCUPANCIES" :key="o" :value="o">{{ { Owner: 'Owner-occupied (service charge only)', Rented: 'Rented to a tenant', Vacant: 'Vacant' }[o] }}</option>
              </select>
            </label>
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

    <!-- ═══════ STAFF MODAL ═══════ -->
    <div v-if="staffModal" class="overlay" @click.self="staffModal = null">
      <div class="modal" style="max-width:560px">
        <div class="modal-h"><div class="t">{{ staffModal.title }}</div><button class="close" @click="staffModal = null">✕</button></div>
        <div class="modal-b">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">Full name *<input v-model="staffForm.name" placeholder="e.g. Md. Karim" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Designation
              <select v-model="staffForm.designation" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="d in DESIGNATIONS" :key="d" :value="d">{{ d }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Mobile<input v-model="staffForm.phone" placeholder="e.g. 01711-000000" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">NID<input v-model="staffForm.nid" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Join date<input type="date" v-model="staffForm.join_date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Monthly salary (৳)<input type="number" v-model.number="staffForm.salary" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
            <label style="font-size:12px;color:var(--text-mute)">Status
              <select v-model="staffForm.status" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="st in ['Active', 'On Leave', 'Resigned']" :key="st" :value="st">{{ st }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Notes<input v-model="staffForm.notes" placeholder="Shift, remarks…" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveStaff" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save staff</button>
            <button @click="staffModal = null" class="btn-ghost" style="padding:11px 18px">Cancel</button>
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
          <label style="font-size:12px;color:var(--text-mute)">Amount (৳)<input type="number" v-model.number="salForm.amount" min="1" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Paid via
            <select v-model="salForm.method" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <option value="cash">💵 Cash</option><option value="bank">🏦 Bank</option><option value="bkash">📱 bKash</option><option value="nagad">📱 Nagad</option>
            </select>
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Note<input v-model="salForm.note" placeholder="Optional — voucher / remark" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" /></label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveSalary" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--ok);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💸 Confirm payment</button>
            <button @click="salModal = null" class="btn-ghost" style="padding:11px 18px">Cancel</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ USER MODAL ═══════ -->
    <div v-if="userModal" class="overlay" @click.self="userModal = null">
      <div class="modal" style="max-width:480px">
        <div class="modal-h"><div class="t">{{ userModal.title }}</div><button class="close" @click="userModal = null">✕</button></div>
        <div class="modal-b">
          <label style="font-size:12px;color:var(--text-mute)">Full name *
            <input v-model="userForm.name" placeholder="e.g. Md. Shahidullah" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <template v-if="userModal.mode === 'add'">
            <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Email *
              <input v-model="userForm.email" type="email" placeholder="e.g. secretary@razzakplaza.com" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Temporary password *
              <input v-model="userForm.password" type="text" placeholder="min 8 characters — user changes it on first login" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </template>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Role
            <select v-model="userForm.role" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <option v-for="r in USER_ROLES" :key="r" :value="r">{{ { owner: '👑 Owner (committee chairman)', manager: '🧑‍💼 Manager', accountant: '🧮 Accountant', collector: '💵 Collector (field staff)' }[r] || r }}</option>
            </select>
          </label>
          <label v-if="userModal.mode === 'edit'" style="font-size:12px;color:var(--text-mute);display:flex;align-items:center;gap:8px;margin-top:12px;padding-bottom:8px">
            <input type="checkbox" v-model="userForm.active" style="width:16px;height:16px" /> Active (can log in)
          </label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveUser" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save user</button>
            <button @click="userModal = null" class="btn-ghost" style="padding:11px 18px">Cancel</button>
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
          <label style="font-size:12px;color:var(--text-mute)">New password
            <input v-model="resetForm.password" type="text" placeholder="min 8 characters" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveReset" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">🔑 Reset password</button>
            <button @click="resetModal = null" class="btn-ghost" style="padding:11px 18px">Cancel</button>
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
            <label style="font-size:12px;color:var(--text-mute)">Full name *
              <input v-model="memberForm.name" placeholder="e.g. Alhaj Md. Abdul Razzak" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Role
              <select v-model="memberForm.role" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="r in COMMITTEE_ROLES" :key="r" :value="r">{{ { Chairman: '👑 Chairman', 'Vice Chairman': '👑 Vice Chairman', Secretary: '📝 Secretary', Treasurer: '💰 Treasurer', Member: '👤 Executive Member' }[r] || r }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Shop (owner of)
              <select v-model="memberForm.shop" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option value="">Independent / no shop</option>
                <option v-for="s in shops" :key="s.id" :value="s.no">{{ s.no }} — {{ s.owner_name }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Phone
              <input v-model="memberForm.phone" placeholder="e.g. 01711-000000" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Email
              <input v-model="memberForm.email" type="email" placeholder="optional" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Term
              <input v-model="memberForm.term" placeholder="e.g. 2024–2026" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute);display:flex;align-items:center;gap:8px;padding-bottom:8px">
              <input type="checkbox" v-model="memberForm.active" style="width:16px;height:16px" /> Active on the committee
            </label>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveMember" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save member</button>
            <button @click="memberModal = null" class="btn-ghost" style="padding:11px 18px">Cancel</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ MEETING MODAL ═══════ -->
    <div v-if="meetingModal" class="overlay" @click.self="meetingModal = false">
      <div class="modal" style="max-width:560px">
        <div class="modal-h"><div class="t">📅 Log meeting</div><button class="close" @click="meetingModal = false">✕</button></div>
        <div class="modal-b">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">Date
              <input type="date" v-model="meetingForm.date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Type
              <select v-model="meetingForm.type" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="t in MEETING_TYPES" :key="t" :value="t">{{ t }}</option>
              </select>
            </label>
          </div>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Title *
            <input v-model="meetingForm.title" placeholder="e.g. Annual General Meeting 2026" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Agenda
            <textarea v-model="meetingForm.agenda" rows="2" placeholder="Agenda items…" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea>
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Decisions
            <textarea v-model="meetingForm.decisions" rows="2" placeholder="What was decided…" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea>
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Minutes / notes
            <textarea v-model="meetingForm.minutes" rows="3" placeholder="Full minutes or notes (stored as the governance record)…" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea>
          </label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveMeeting" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--ok);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save meeting</button>
            <button @click="meetingModal = false" class="btn-ghost" style="padding:11px 18px">Cancel</button>
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
            <label style="font-size:12px;color:var(--text-mute)">Number
              <input v-model="resForm.number" placeholder="RES-2026-01" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Date
              <input type="date" v-model="resForm.date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </div>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Title *
            <input v-model="resForm.title" placeholder="e.g. 5% service charge increase from October" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Resolution text
            <textarea v-model="resForm.body" rows="3" placeholder="The full resolution text — archived as the governance record…" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea>
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Linked meeting (optional)
            <select v-model="resForm.meeting_id" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
              <option :value="0">— none —</option>
              <option v-for="m in committee?.meetings || []" :key="m.id" :value="m.id">#{{ m.id }} · {{ m.title }} ({{ m.date }})</option>
            </select>
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:flex;align-items:center;gap:8px;margin-top:12px;padding-bottom:8px">
            <input type="checkbox" v-model="resForm.passed" style="width:16px;height:16px" /> Passed by the committee
          </label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveResolution" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save resolution</button>
            <button @click="resModal = false" class="btn-ghost" style="padding:11px 18px">Cancel</button>
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
            <label style="font-size:12px;color:var(--text-mute)">Name / entity name *
              <input v-model="ownerForm.name" placeholder="e.g. Rahim Uddin or Rahim Traders Ltd" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Type
              <select v-model="ownerForm.type" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="t in OWNER_TYPES" :key="t" :value="t">{{ t }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Phone
              <input v-model="ownerForm.phone" placeholder="e.g. 01711-000000" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Email
              <input v-model="ownerForm.email" type="email" placeholder="optional" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">NID (person) / TIN
              <input v-model="ownerForm.nid" placeholder="optional" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Trade license (company)
              <input v-model="ownerForm.trade_license" placeholder="optional" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Contact person
              <input v-model="ownerForm.contact_person" placeholder="for companies" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Address
              <input v-model="ownerForm.address" placeholder="optional" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </div>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Notes
            <textarea v-model="ownerForm.notes" rows="2" placeholder="e.g. owns A-101 &amp; B-201; self-occupies A-101" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;resize:vertical"></textarea>
          </label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveOwner" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save owner</button>
            <button @click="ownerModal = null" class="btn-ghost" style="padding:11px 18px">Cancel</button>
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
    <div v-if="tenantModal" class="overlay" @click.self="tenantModal = null">
      <div class="modal" style="max-width:520px">
        <div class="modal-h"><div class="t">{{ tenantModal.title }}</div><button class="close" @click="tenantModal = null">✕</button></div>
        <div class="modal-b">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <label style="font-size:12px;color:var(--text-mute)">Full name *
              <input v-model="tenantForm.name" placeholder="e.g. Abdul Kader" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Phone
              <input v-model="tenantForm.phone" placeholder="e.g. 01800-000000" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">NID
              <input v-model="tenantForm.nid" placeholder="optional" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Email
              <input v-model="tenantForm.email" type="email" placeholder="optional" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Employer / business
              <input v-model="tenantForm.employer" placeholder="e.g. Mobile accessories shop" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Address
              <input v-model="tenantForm.address" placeholder="optional" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveTenant" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save tenant</button>
            <button @click="tenantModal = null" class="btn-ghost" style="padding:11px 18px">Cancel</button>
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
            <label style="font-size:12px;color:var(--text-mute)">Space *
              <select v-model="agrForm.shop" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option value="">— choose space —</option>
                <option v-for="s in shops" :key="s.id" :value="s.no">{{ s.no }} · {{ s.space_type || 'Shop' }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Tenant
              <select v-model="agrForm.tenant_id" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option :value="0">— choose tenant —</option>
                <option v-for="t in tenants" :key="t.id" :value="t.id">{{ t.name }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Monthly rent (৳)
              <input type="number" v-model.number="agrForm.rent" min="0" placeholder="e.g. 25000" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Advance months
              <input type="number" v-model.number="agrForm.advance_months" min="0" placeholder="e.g. 3" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Start date
              <input type="date" v-model="agrForm.start_date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">End date (optional)
              <input type="date" v-model="agrForm.end_date" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Rent due day
              <input type="number" v-model.number="agrForm.due_day" min="1" max="28" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Status
              <select v-model="agrForm.status" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="s in ['Active', 'Expired', 'Terminated']" :key="s" :value="s">{{ s }}</option>
              </select>
            </label>
          </div>
          <label style="font-size:12px;color:var(--text-mute);display:flex;align-items:center;gap:8px;margin-top:12px;cursor:pointer;background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:11px 13px">
            <input type="checkbox" v-model="agrForm.rent_collection" style="width:16px;height:16px" />
            <span><b>Committee collects rent</b> <span style="color:var(--text-mute)">— optional service: the owner gets rent collected on their behalf</span></span>
          </label>
          <div style="display:flex;gap:10px;margin-top:16px">
            <button @click="saveAgreement" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--ok);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save agreement</button>
            <button @click="agrModal = false" class="btn-ghost" style="padding:11px 18px">Cancel</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════ RENT COLLECT MODAL ═══════ -->
    <div v-if="rentModal" class="overlay" @click.self="rentModal = null">
      <div class="modal" style="max-width:440px">
        <div class="modal-h"><div class="t">💵 Collect rent — {{ rentModal.shop }}</div><button class="close" @click="rentModal = null">✕</button></div>
        <div class="modal-b">
          <p style="font-size:12.5px;color:var(--text-mute);margin-bottom:12px">Recording rent for <b>{{ rentModal.tenant_name }}</b> ({{ money(rentModal.rent) }}/mo). Receipt <b>RNT-…</b> auto-generated.</p>
          <label style="font-size:12px;color:var(--text-mute);display:block">Month
            <input type="month" v-model="rentForm.month" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px">
            <label style="font-size:12px;color:var(--text-mute)">Amount
              <input type="number" v-model.number="rentForm.amount" min="0" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Method
              <select v-model="rentForm.method" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="m in ['cash', 'bank', 'bkash', 'nagad']" :key="m" :value="m">{{ m }}</option>
              </select>
            </label>
          </div>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Reference
            <input v-model="rentForm.ref" placeholder="optional" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveRent" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--ok);color:#fff;font-size:13px;font-weight:800;cursor:pointer">✅ Record rent</button>
            <button @click="rentModal = null" class="btn-ghost" style="padding:11px 18px">Cancel</button>
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
            <label style="font-size:12px;color:var(--text-mute)">Vendor name *
              <input v-model="vendorForm.name" placeholder="e.g. Otis Elevator Co." style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Category
              <select v-model="vendorForm.category" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="c in VENDOR_CATS" :key="c" :value="c">{{ c }}</option>
              </select>
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Contact person
              <input v-model="vendorForm.contact_person" placeholder="optional" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Phone
              <input v-model="vendorForm.phone" placeholder="e.g. 02-9551234" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Email
              <input v-model="vendorForm.email" type="email" placeholder="optional" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Address
              <input v-model="vendorForm.address" placeholder="optional" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
          </div>
          <div style="display:flex;gap:10px;margin-top:18px">
            <button @click="saveVendor" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save vendor</button>
            <button @click="vendorModal = null" class="btn-ghost" style="padding:11px 18px">Cancel</button>
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
            <label style="font-size:12px;color:var(--text-mute)">Amount (৳)
              <input type="number" v-model.number="vendorPayForm.amount" min="0" placeholder="e.g. 8500" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
            </label>
            <label style="font-size:12px;color:var(--text-mute)">Method
              <select v-model="vendorPayForm.method" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px">
                <option v-for="m in ['bank', 'cash', 'bkash', 'nagad', 'cheque']" :key="m" :value="m">{{ m }}</option>
              </select>
            </label>
          </div>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Reference / cheque no
            <input v-model="vendorPayForm.ref" placeholder="optional" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <label style="font-size:12px;color:var(--text-mute);display:block;margin-top:10px">Note (what is this for?)
            <input v-model="vendorPayForm.note" placeholder="e.g. Lift AMC — August" style="width:100%;margin-top:4px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px" />
          </label>
          <div style="display:flex;gap:10px;margin-top:16px">
            <button @click="saveVendorPay" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--ok);color:#fff;font-size:13px;font-weight:800;cursor:pointer">✅ Record payment</button>
          </div>
          <div v-if="vendorPayments.length" style="margin-top:16px">
            <div style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Payment ledger</div>
            <div v-for="p in vendorPayments" :key="p.id" style="display:flex;align-items:center;gap:8px;border-bottom:1px dashed var(--border);padding:7px 0;font-size:12.5px">
              <b>{{ money(p.amount) }}</b>
              <span class="badge b-gray" style="font-size:10px">{{ p.method }}</span>
              <span style="color:var(--text-mute);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ p.note }}</span>
              <span style="color:var(--text-mute);font-size:11px">{{ (p.ts || '').slice(0, 10) }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

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
              <div class="rc-sub">Money Receipt · Service Collection</div>
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
              <div class="rc-title" style="color:#fff">MONEY<br />RECEIPT</div>
            </div>
            <!-- ═══ MINIMAL: monochrome — logo/name left, title right, hairline ═══ -->
            <div v-else class="rc-head rc-minimal">
              <div class="rc-min-left">
                <img v-if="recLogo" :src="recLogo" alt="logo" style="max-height:36px;max-width:120px;object-fit:contain" />
                <div class="rc-name">{{ recData.brand.mall_name || 'MALL MANAGEMENT' }}</div>
                <div class="rc-meta">{{ recData.brand.mall_address }}<span v-if="recData.brand.mall_phone"> · ☎ {{ recData.brand.mall_phone }}</span><span v-if="recData.brand.mall_email"> · {{ recData.brand.mall_email }}</span></div>
              </div>
              <div class="rc-title">MONEY RECEIPT</div>
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
                <tr v-if="recData.brand.bank_name"><td style="color:var(--text-mute)">Bank</td><td style="text-align:right">{{ recData.brand.bank_name }}<span v-if="recData.brand.bank_account_no"> · A/C {{ recData.brand.bank_account_no }}</span></td></tr>
                <tr v-if="recData.brand.bank_account_title"><td style="color:var(--text-mute)">A/C title</td><td style="text-align:right">{{ recData.brand.bank_account_title }}</td></tr>
              </tbody>
            </table>
            <div style="display:flex;justify-content:space-between;margin-top:18px;padding-top:10px;border-top:1px solid var(--border);font-size:12px;color:var(--text-mute)">
              <span>Received by: ________________<span v-if="recData.brand.secretary"><br /><small style="font-size:10.5px">{{ recData.brand.secretary }} — Secretary</small></span></span>
              <span>Chairman: ________________<span v-if="recData.brand.chairman"><br /><small style="font-size:10.5px">{{ recData.brand.chairman }}</small></span></span>
            </div>
            <div v-if="recData.brand.receipt_note" style="margin-top:12px;padding-top:8px;border-top:1px dashed var(--border);font-size:11px;color:var(--text-mute);text-align:center">{{ recData.brand.receipt_note }}</div>
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
}
@media (max-width: 900px) { .dash-grid { grid-template-columns: 1fr !important; } }
@media (max-width: 900px) { .cm-grid { grid-template-columns: 1fr !important; } }
</style>
