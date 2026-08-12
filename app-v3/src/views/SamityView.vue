<script setup>
import { computed, ref, watch } from 'vue'
import { t } from '../lib/i18n'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { apiCall } from '../api/client'
import { useViewMode, usePager, money, fmtTs, avatarColor, initials, monthLabel, today } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'
import ScrollTabs from '../components/ScrollTabs.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('samity')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const propsList = computed(() => data.list('properties'))
const propName = (pid) => propsList.value.find(p => p.id === pid)?.name || pid || ''
const role = computed(() => (data.user || {}).role || '')
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'svc_mgr'].includes(role.value))

// ── property scoping dropdown ──
const propFilter = ref('')
const inProp = (row) => !propFilter.value || (row.prop || '') === propFilter.value

// ── tabs ──
const TABS = [
  ['committee', '👥', 'Committee'],
  ['bills', '🧾', 'Bills'],
  ['collection', '💳', 'Collection'],
  ['expenses', '💸', 'Expenses'],
  ['report', '🖨', 'Report'],
  ['settings', '⚙️', 'Settings'],
]
const tab = ref('committee')

const ROLE_META = {
  Chairman: { ico: '👑', cls: 'b-blue' },
  Secretary: { ico: '📝', cls: 'b-gray' },
  Treasurer: { ico: '💰', cls: 'b-orange' },
  Member: { ico: '👤', cls: 'b-gray' },
}
const roleMeta = (r) => ROLE_META[r] || { ico: '👤', cls: 'b-gray' }
const stCls = (s) => s === 'active' ? 'b-green' : 'b-gray'
const bStCls = (s) => s === 'Paid' ? 'b-green' : (s === 'Partial' ? 'b-orange' : 'b-gray')
const METHOD_TINT = { bKash: '#e2136e', Nagad: '#f6921e', Rocket: '#8c3494', Bank: '#1f6feb', Cheque: '#8957e5', Cash: '#12a150', Card: '#c2410c' }
const methodTint = (m) => { const c = METHOD_TINT[m] || '#5b6b8c'; return { background: c + '22', color: c, border: '1px solid ' + c + '44' } }
const EXP_CAT = {
  maintenance: { ico: '🔧', cls: 'b-blue', label: 'Maintenance' },
  utility: { ico: '💡', cls: 'b-orange', label: 'Utility' },
  repair: { ico: '🛠️', cls: 'b-green', label: 'Repair' },
  cleaning: { ico: '🧹', cls: 'b-gray', label: 'Cleaning' },
  event: { ico: '🎉', cls: 'b-blue', label: 'Event' },
  security: { ico: '🛡️', cls: 'b-red', label: 'Security' },
  other: { ico: '📦', cls: 'b-gray', label: 'Other' },
}
const expCat = (c) => EXP_CAT[c] || EXP_CAT.other

// ── collections (raw) ──
const memAll = computed(() => data.list('samity_members'))
const billsAll = computed(() => data.list('samity_bills'))
const colsAll = computed(() => data.list('samity_collections'))
const expAll = computed(() => data.list('samity_expenses'))

// ── KPIs (respect property filter) ──
const kpis = computed(() => {
  const ms = memAll.value.filter(inProp)
  const bs = billsAll.value.filter(inProp)
  const active = ms.filter(m => m.status === 'active').length
  const bearers = ms.filter(m => m.role && m.role !== 'Member').length
  const flats = ms.filter(m => /flat/i.test(m.notes || '')).length
  const since = ms.map(m => m.since_date).filter(Boolean).sort()
  const billsDue = bs.filter(b => b.status !== 'Paid').reduce((s, b) => s + (b.amount || 0), 0)
  return [
    { label: 'Members', ico: '🏘️', value: ms.length, trend: 'samity roster' },
    { label: 'Active', ico: '✅', value: active, trend: active === ms.length ? 'all active' : active + ' of ' + ms.length, ok: active === ms.length },
    { label: 'Office bearers', ico: '⭐', value: bearers, trend: 'chairman · secretary · treasurer' },
    { label: 'Flat owners', ico: '🏠', value: flats, trend: 'resident members' },
    { label: 'Bill balance', ico: '🧾', value: money(billsDue), trend: 'unpaid society bills', ok: billsDue === 0 },
    { label: 'Since', ico: '📅', value: since.length ? since[0] : '—', trend: propFilter.value ? propName(propFilter.value) : 'earliest membership' },
  ]
})

// ── Committee filters ──
const query = ref('')
const roleFilter = ref('')
const roleOptions = computed(() => [...new Set(memAll.value.map(m => m.role).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = memAll.value.filter(inProp)
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(m => JSON.stringify(m).toLowerCase().includes(q))
  if (roleFilter.value) out = out.filter(m => (m.role || '') === roleFilter.value)
  const rank = { Chairman: 0, Secretary: 1, Treasurer: 2, Member: 3 }
  return [...out].sort((a, b) => (rank[a.role] ?? 9) - (rank[b.role] ?? 9) || String(a.since_date || '').localeCompare(String(b.since_date || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

// ── Committee writes ──
const memberModal = ref(false)
const memberForm = ref({ id: '', name: '', role: 'Member', phone: '', since_date: today(), status: 'active', notes: '', prop: '' })
function openMemberModal(m) {
  memberForm.value = m
    ? { id: m.id, name: m.name || '', role: m.role || 'Member', phone: m.phone || '', since_date: m.since_date || today(), status: m.status || 'active', notes: m.notes || '', prop: m.prop || propFilter.value || propsList.value[0]?.id || '' }
    : { id: '', name: '', role: 'Member', phone: '', since_date: today(), status: 'active', notes: '', prop: propFilter.value || propsList.value[0]?.id || '' }
  memberModal.value = true
}
async function saveMember() {
  const f = memberForm.value
  if (!f.name.trim()) { window.__krToast?.('❌ Name is required'); return }
  const r = await apiCall('app-samity', { action: f.id ? 'member-save' : 'member-create', id: f.id, name: f.name.trim(), role: f.role, phone: f.phone.trim(), since_date: f.since_date || today(), status: f.status, notes: f.notes.trim(), prop: f.prop })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  memberModal.value = false
  window.__krToast?.('✅ Member saved')
  await data.bootstrap()
}
async function delMember(m) {
  if (!window.confirm('Delete member ' + m.name + '?')) return
  const r = await apiCall('app-samity', { action: 'member-delete', id: m.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('🗑 Deleted')
  closeDetail()
  await data.bootstrap()
}

function exportCsv(rows, name) {
  if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = name + '.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── Bills tab ──
const bq = ref('')
const bStatus = ref('')
const bMonth = ref('')
const bStatusOptions = computed(() => [...new Set(billsAll.value.map(b => b.status).filter(Boolean))].sort())
const bMonthOptions = computed(() => [...new Set(billsAll.value.map(b => b.month).filter(Boolean))].sort().reverse())
const billsFiltered = computed(() => {
  let out = billsAll.value.filter(inProp)
  const q = bq.value.trim().toLowerCase()
  if (q) out = out.filter(b => JSON.stringify(b).toLowerCase().includes(q))
  if (bStatus.value) out = out.filter(b => (b.status || '') === bStatus.value)
  if (bMonth.value) out = out.filter(b => (b.month || '') === bMonth.value)
  return [...out].sort((a, b) => String(b.month || '').localeCompare(String(a.month || '')) || String(a.id || '').localeCompare(String(b.id || '')))
})
const bTotal = computed(() => billsFiltered.value.reduce((s, b) => s + (b.amount || 0), 0))
const bPaid = computed(() => billsFiltered.value.filter(b => b.status === 'Paid').reduce((s, b) => s + (b.amount || 0), 0))
const bUnpaid = computed(() => billsFiltered.value.filter(b => b.status !== 'Paid').reduce((s, b) => s + (b.amount || 0), 0))

// ── Collection tab ──
const cq = ref('')
const cMethod = ref('')
const cMethodOptions = computed(() => [...new Set(colsAll.value.map(c => c.method).filter(Boolean))].sort())
const colsFiltered = computed(() => {
  let out = colsAll.value.filter(inProp)
  const q = cq.value.trim().toLowerCase()
  if (q) out = out.filter(c => JSON.stringify(c).toLowerCase().includes(q))
  if (cMethod.value) out = out.filter(c => (c.method || '') === cMethod.value)
  return [...out].sort((a, b) => String(b.collected_at || '').localeCompare(String(a.collected_at || '')))
})
const cTotal = computed(() => colsFiltered.value.reduce((s, c) => s + (c.amount || 0), 0))
const cByMethod = computed(() => {
  const map = {}
  colsFiltered.value.forEach(c => { const m = c.method || 'Other'; map[m] = (map[m] || 0) + (c.amount || 0) })
  return Object.entries(map)
})

// ── Bills tab writes ──
const billModal = ref(false)
const billForm = ref({ unit: '', month: today().slice(0, 7), amount: '', due_date: today().slice(0, 7) + '-05', note: '' })
const unitsForProp = computed(() => {
  let out = data.list('units')
  if (propFilter.value) out = out.filter(u => u.p === propFilter.value)
  return out
})
function openBillModal() {
  billForm.value = { unit: unitsForProp.value[0]?.id || '', month: today().slice(0, 7), amount: '', due_date: today().slice(0, 7) + '-05', note: '' }
  billModal.value = true
}
async function addBill() {
  const f = billForm.value
  if (!f.unit) { window.__krToast?.('❌ Select a unit'); return }
  if (!(parseInt(f.amount) > 0)) { window.__krToast?.('❌ Amount is required'); return }
  const r = await apiCall('app-samity', { action: 'bill-create', unit: f.unit, month: f.month || today().slice(0, 7), amount: parseInt(f.amount), due_date: f.due_date || today().slice(0, 7) + '-05', note: f.note.trim() })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  billModal.value = false
  window.__krToast?.('✅ Bill issued')
  await data.bootstrap()
}
async function delBill(b) {
  if (!window.confirm('Delete bill ' + b.id + '?')) return
  const r = await apiCall('app-samity', { action: 'bill-delete', id: b.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('🗑 Deleted')
  await data.bootstrap()
}

// ── Collection tab writes ──
const collModal = ref(false)
const collForm = ref({ bill: '', amount: '', method: 'Cash', collected_at: today(), note: '' })
const collBills = computed(() => billsAll.value.filter(inProp).filter(b => (b.status || '') !== 'Paid'))
const remainingOf = (bid) => {
  const b = billsAll.value.find(x => x.id === bid); if (!b) return 0
  const got = colsAll.value.filter(c => c.bill === bid).reduce((s, c) => s + (c.amount || 0), 0)
  return Math.max(0, (b.amount || 0) - got)
}
function openCollModal() {
  collForm.value = { bill: collBills.value[0]?.id || '', amount: '', method: 'Cash', collected_at: today(), note: '' }
  collModal.value = true
}
async function addCollection() {
  const f = collForm.value
  if (!f.bill) { window.__krToast?.('❌ Select a bill'); return }
  if (!(parseInt(f.amount) > 0)) { window.__krToast?.('❌ Amount is required'); return }
  const r = await apiCall('app-samity', { action: 'collection-create', bill: f.bill, amount: parseInt(f.amount), method: f.method, collected_at: f.collected_at || today(), note: f.note.trim() })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  collModal.value = false
  window.__krToast?.('✅ Collection recorded')
  await data.bootstrap()
}
async function delCollection(c) {
  if (!window.confirm('Delete collection ' + c.id + '?')) return
  const r = await apiCall('app-samity', { action: 'collection-delete', id: c.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('🗑 Deleted')
  await data.bootstrap()
}

// ── Settings config (live from API) ──
const samityCfg = ref({ alert_days: 7, default_charge: 3000 })
const cfgLoaded = ref(false)
async function loadCfg() {
  const r = await apiCall('app-samity', { action: 'config-get' })
  if (r && r.config) samityCfg.value = { ...samityCfg.value, ...r.config }
  cfgLoaded.value = true
}
async function saveCfg() {
  const r = await apiCall('app-samity', { action: 'config-save', alert_days: Math.max(1, Math.min(120, samityCfg.value.alert_days || 7)), default_charge: Math.max(1, Math.min(100000, samityCfg.value.default_charge || 3000)) })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('✅ Settings saved')
}
watch(tab, (t) => { if (t === 'settings' && !cfgLoaded.value) loadCfg() })

// ── Expenses tab ──
const eq = ref('')
const eCat = ref('')
const eCatOptions = computed(() => [...new Set(expAll.value.map(e => e.category).filter(Boolean))].sort())
const expFiltered = computed(() => {
  let out = expAll.value.filter(inProp)
  const q = eq.value.trim().toLowerCase()
  if (q) out = out.filter(e => JSON.stringify(e).toLowerCase().includes(q))
  if (eCat.value) out = out.filter(e => (e.category || '') === eCat.value)
  return [...out].sort((a, b) => String(b.exp_date || '').localeCompare(String(a.exp_date || '')) || String(b.ts || '').localeCompare(String(a.ts || '')))
})
const eTotal = computed(() => expFiltered.value.reduce((s, e) => s + (e.amount || 0), 0))
const eMonth = computed(() => {
  const ym = today().slice(0, 7)
  return expFiltered.value.filter(e => String(e.exp_date || '').slice(0, 7) === ym).reduce((s, e) => s + (e.amount || 0), 0)
})
const eTopCat = computed(() => {
  const map = {}
  expFiltered.value.forEach(e => { const c = e.category || 'other'; map[c] = (map[c] || 0) + (e.amount || 0) })
  const sorted = Object.entries(map).sort((a, b) => b[1] - a[1])
  return sorted.length ? sorted[0] : null
})

const expModal = ref(false)
const expForm = ref({ title: '', category: 'maintenance', amount: '', exp_date: today(), prop: '', note: '' })
function openExpModal() {
  expForm.value = { title: '', category: 'maintenance', amount: '', exp_date: today(), prop: propFilter.value || propsList.value[0]?.id || '', note: '' }
  expModal.value = true
}
async function addExpense() {
  const f = expForm.value
  if (!f.title.trim()) { window.__krToast?.('❌ Title is required'); return }
  if (!(parseInt(f.amount) > 0)) { window.__krToast?.('❌ Amount is required'); return }
  const r = await apiCall('app-samity', { action: 'expense-create', title: f.title.trim(), category: f.category, amount: parseInt(f.amount), exp_date: f.exp_date || today(), prop: f.prop, note: f.note.trim() })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  expModal.value = false
  window.__krToast?.('✅ Expense recorded')
  await data.bootstrap()
}
async function delExpense(e) {
  if (!window.confirm('Delete expense ' + e.id + ' — ' + (e.title || '') + '?')) return
  const r = await apiCall('app-samity', { action: 'expense-delete', id: e.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('🗑 Deleted')
  await data.bootstrap()
}

// ── Report tab ──
const rMonth = ref('')
const rMonths = computed(() => [...new Set(billsAll.value.map(b => b.month).filter(Boolean))].sort().reverse())
const report = computed(() => {
  const month = rMonth.value
  const bills = billsAll.value.filter(inProp).filter(b => !month || b.month === month)
  const colls = colsAll.value.filter(inProp).filter(c => !month || String(c.collected_at || '').slice(0, 7) === month)
  const exps = expAll.value.filter(inProp).filter(e => !month || String(e.exp_date || '').slice(0, 7) === month)
  const issued = bills.reduce((s, b) => s + (b.amount || 0), 0)
  const collected = colls.reduce((s, c) => s + (c.amount || 0), 0)
  const expTotal = exps.reduce((s, e) => s + (e.amount || 0), 0)
  const outstanding = Math.max(0, issued - collected)
  return { bills, colls, exps, issued, collected, expTotal, net: Math.max(0, collected - expTotal), outstanding, rate: issued ? Math.round((collected / issued) * 100) : 0 }
})
function printReport() {
  document.body.classList.add('print-samity')
  window.print()
  document.body.classList.remove('print-samity')
}

// ── Settings tab ──
const settings = computed(() => {
  const amts = billsAll.value.map(b => b.amount).filter(Boolean)
  const defCharge = amts.length ? [...new Set(amts)].sort((a, b) => billsAll.value.filter(b => b.amount === a).length - billsAll.value.filter(b => b.amount === b).length).pop() : 0
  const dueDays = [...new Set(billsAll.value.map(b => String(b.due_date || '').slice(8, 10)).filter(Boolean))]
  const months = [...new Set(billsAll.value.map(b => b.month).filter(Boolean))].sort()
  return {
    defCharge, dueDay: dueDays.join(', ') || '—', months,
    members: memAll.value.length, active: memAll.value.filter(m => m.status === 'active').length,
    bills: billsAll.value.length, collections: colsAll.value.length, expenses: expAll.value.length,
  }
})

// ── drawer ──
const sel = ref(null)
function openDetail(m) { tab.value = 'committee'; sel.value = m }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const m = memAll.value.find(x => x.id === id); if (m) openDetail(m) }
}, { immediate: true })
function detailFields(row) {
  const skip = new Set(['id', 'name', 'role', 'phone', 'since_date', 'status', 'notes', 'owner_email', 'prop'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('🏘️ Samity') }}</h1>
        <div class="sub">{{ kpis[0]?.value || 0 }} members · {{ kpis[4]?.value || '৳0' }} bill balance · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <CompactFilters>
        <select v-model="propFilter" title="Manage this property" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;font-weight:700;color:var(--text);outline:none">
          <option value="">🏢 All properties</option>
          <option v-for="p in propsList" :key="p.id" :value="p.id">{{ p.id }} · {{ p.name }}</option>
        </select>
        <template v-if="tab === 'committee'">
          <input v-model="query" :placeholder="t('Search name, phone, flat…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:200px">
          <select v-model="roleFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All roles</option>
            <option v-for="r in roleOptions" :key="r" :value="r">{{ r }}</option>
          </select>
          <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
            <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
            <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
          </div>
          <button v-if="filtered.length" @click="exportCsv(filtered, 'samity-members')" class="btn-ghost" title="Download CSV">⬇ CSV</button>
          <button v-if="canManage" @click="openMemberModal()" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Add member</button>
        </template>
        <template v-else-if="tab === 'report'">
          <select v-model="rMonth" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All months</option>
            <option v-for="m in rMonths" :key="m" :value="m">{{ monthLabel(m) }}</option>
          </select>
          <button @click="printReport" class="btn-ghost" title="Print report">🖨 Print</button>
        </template>
        <template v-else-if="tab === 'bills'">
          <input v-model="bq" placeholder="Search bill, unit…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:180px">
          <select v-model="bMonth" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All months</option>
            <option v-for="m in bMonthOptions" :key="m" :value="m">{{ monthLabel(m) }}</option>
          </select>
          <select v-model="bStatus" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All statuses</option>
            <option v-for="s in bStatusOptions" :key="s" :value="s">{{ s }}</option>
          </select>
          <button v-if="billsFiltered.length" @click="exportCsv(billsFiltered, 'samity-bills')" class="btn-ghost" title="Download CSV">⬇ CSV</button>
          <button v-if="canManage" @click="openBillModal" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Add bill</button>
        </template>
        <template v-else-if="tab === 'collection'">
          <input v-model="cq" placeholder="Search receipt, bill…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:180px">
          <select v-model="cMethod" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All methods</option>
            <option v-for="m in cMethodOptions" :key="m" :value="m">{{ m }}</option>
          </select>
          <button v-if="colsFiltered.length" @click="exportCsv(colsFiltered, 'samity-collections')" class="btn-ghost" title="Download CSV">⬇ CSV</button>
          <button v-if="canManage" @click="openCollModal" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Record collection</button>
        </template>
        <template v-else-if="tab === 'expenses'">
          <input v-model="eq" placeholder="Search title, note…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:180px">
          <select v-model="eCat" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All categories</option>
            <option v-for="c in eCatOptions" :key="c" :value="c">{{ expCat(c).label }}</option>
          </select>
          <button v-if="expFiltered.length" @click="exportCsv(expFiltered, 'samity-expenses')" class="btn-ghost" title="Download CSV">⬇ CSV</button>
          <button v-if="canManage" @click="openExpModal" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Add expense</button>
        </template>
        </CompactFilters>
      </div>
    </div>

    <!-- tab bar -->
    <ScrollTabs style="gap:6px;margin-bottom:18px;border-bottom:1px solid var(--border);padding-bottom:10px">
      <button v-for="[id, ico, label] in TABS" :key="id" @click="tab = id"
        :style="tab === id ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'"
        style="padding:8px 14px;border:none;border-radius:10px;font-size:12.5px;font-weight:800;cursor:pointer">{{ ico }} {{ label }}</button>
    </ScrollTabs>

    <div class="stats">
      <div v-for="k in kpis" :key="k.label" class="stat">
        <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
        <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
        <div class="s-trend">{{ k.trend }}</div>
      </div>
    </div>

    <!-- ── 👥 COMMITTEE ── -->
    <template v-if="tab === 'committee'">
      <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px">
        <div v-for="m in paged" :key="m.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(m)">
          <div style="padding:16px 15px 0;display:flex;align-items:center;gap:12px">
            <div style="width:46px;height:46px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;color:#fff" :style="{ background: avatarColor(m.id) }">{{ initials(m.name) }}</div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ m.name || '—' }}</div>
              <div class="c-sub" style="font-size:12px">since {{ m.since_date || '—' }}<template v-if="m.prop"> · {{ propName(m.prop) }}</template></div>
            </div>
            <span class="badge" :class="stCls(m.status)">{{ m.status || '—' }}</span>
          </div>
          <div style="padding:12px 15px 14px;flex:1;display:flex;flex-direction:column;gap:9px">
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <span class="badge" :class="roleMeta(m.role).cls">{{ roleMeta(m.role).ico }} {{ m.role || '—' }}</span>
              <span v-if="m.phone" class="badge b-blue">📞 {{ m.phone }}</span>
            </div>
            <div v-if="m.notes" class="c-sub" style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ m.notes }}</div>
            <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
              <span>🆔 {{ m.id }}</span>
              <span>📅 {{ fmtTs(m.ts) }}</span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
        <div class="tbl-wrap">
          <table class="kr" style="width:100%">
            <thead><tr><th>ID</th><th>Member</th><th>Role</th><th>Phone</th><th>Property</th><th>Since</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="m in paged" :key="m.id" style="cursor:pointer" @click="openDetail(m)">
                <td style="font-weight:700;white-space:nowrap">{{ m.id }}</td>
                <td style="white-space:nowrap">{{ m.name || '—' }}</td>
                <td style="white-space:nowrap"><span class="badge" :class="roleMeta(m.role).cls">{{ roleMeta(m.role).ico }} {{ m.role || '—' }}</span></td>
                <td style="white-space:nowrap" class="c-sub">{{ m.phone || '—' }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ m.prop ? propName(m.prop) : '—' }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ m.since_date || '—' }}</td>
                <td style="white-space:nowrap"><span class="badge" :class="stCls(m.status)">{{ m.status || '—' }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No members found{{ query ? ' for “' + query + '”' : '' }}.</div>
      <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />
    </template>

    <!-- ── 🧾 BILLS ── -->
    <template v-else-if="tab === 'bills'">
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Issued</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px">{{ money(bTotal) }}</div>
        </div>
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Paid</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px;color:var(--ok)">{{ money(bPaid) }}</div>
        </div>
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Unpaid</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px" :style="bUnpaid ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(bUnpaid) }}</div>
        </div>
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Bills</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px">{{ billsFiltered.length }}</div>
        </div>
      </div>
      <div v-if="billsFiltered.length" class="panel" style="overflow:hidden">
        <div class="tbl-wrap">
          <table class="kr" style="width:100%">
            <thead><tr><th>ID</th><th>Unit</th><th>Month</th><th>Amount</th><th>Due date</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="b in billsFiltered" :key="b.id">
                <td style="font-weight:700;white-space:nowrap">{{ b.id }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ b.unit || '—' }}</td>
                <td style="white-space:nowrap">{{ monthLabel(b.month) }}</td>
                <td style="white-space:nowrap;font-weight:700">{{ money(b.amount) }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ b.due_date || '—' }}</td>
                <td style="white-space:nowrap"><span class="badge" :class="bStCls(b.status)">{{ b.status || '—' }}</span></td>
                <td style="white-space:nowrap">
                  <button v-if="canManage" @click.stop="delBill(b)" title="Delete" style="background:none;border:none;font-size:15px;cursor:pointer">🗑</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div v-else class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No bills found for the current filters.</div>
    </template>

    <!-- ── 💳 COLLECTION ── -->
    <template v-else-if="tab === 'collection'">
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Collected</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px;color:var(--ok)">{{ money(cTotal) }}</div>
        </div>
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Entries</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px">{{ colsFiltered.length }}</div>
        </div>
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Methods</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px">{{ cByMethod.length }}</div>
        </div>
        <div v-for="[m, amt] in cByMethod" :key="m" style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ m }}</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px"><span class="badge" :style="methodTint(m)">{{ m }}</span></div>
          <div style="font-weight:700;font-size:13px;margin-top:3px">{{ money(amt) }}</div>
        </div>
      </div>
      <div v-if="colsFiltered.length" class="panel" style="overflow:hidden">
        <div class="tbl-wrap">
          <table class="kr" style="width:100%">
            <thead><tr><th>ID</th><th>Bill</th><th>Amount</th><th>Method</th><th>Receipt</th><th>Collected</th><th>Note</th><th></th></tr></thead>
            <tbody>
              <tr v-for="c in colsFiltered" :key="c.id">
                <td style="font-weight:700;white-space:nowrap">{{ c.id }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ c.bill || '—' }}</td>
                <td style="white-space:nowrap;font-weight:700">{{ money(c.amount) }}</td>
                <td style="white-space:nowrap"><span class="badge" :style="methodTint(c.method)">{{ c.method || '—' }}</span></td>
                <td style="white-space:nowrap" class="c-sub">{{ c.receipt_no || '—' }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ fmtTs(c.collected_at) }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ c.note || '—' }}</td>
                <td style="white-space:nowrap">
                  <button v-if="canManage" @click.stop="delCollection(c)" title="Delete" style="background:none;border:none;font-size:15px;cursor:pointer">🗑</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div v-else class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No collections found for the current filters.</div>
    </template>

    <!-- ── 💸 EXPENSES ── -->
    <template v-else-if="tab === 'expenses'">
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Total spent</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px" :style="eTotal ? 'color:var(--danger)' : ''">{{ money(eTotal) }}</div>
        </div>
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">This month</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px">{{ money(eMonth) }}</div>
        </div>
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Entries</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px">{{ expFiltered.length }}</div>
        </div>
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Top category</div>
          <div v-if="eTopCat" style="margin-top:3px"><span class="badge" :class="expCat(eTopCat[0]).cls">{{ expCat(eTopCat[0]).ico }} {{ expCat(eTopCat[0]).label }}</span></div>
          <div v-else class="c-sub" style="font-size:12px;margin-top:3px">—</div>
        </div>
      </div>
      <div v-if="expFiltered.length" class="panel" style="overflow:hidden">
        <div class="tbl-wrap">
          <table class="kr" style="width:100%">
            <thead><tr><th>ID</th><th>Category</th><th>Title</th><th>Amount</th><th>Date</th><th>Property</th><th>Note</th><th></th></tr></thead>
            <tbody>
              <tr v-for="e in expFiltered" :key="e.id">
                <td style="font-weight:700;white-space:nowrap">{{ e.id }}</td>
                <td style="white-space:nowrap"><span class="badge" :class="expCat(e.category).cls">{{ expCat(e.category).ico }} {{ expCat(e.category).label }}</span></td>
                <td style="white-space:nowrap;font-weight:600">{{ e.title || '—' }}</td>
                <td style="white-space:nowrap;font-weight:700" :style="'color:var(--danger)'">{{ money(e.amount) }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ e.exp_date || '—' }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ e.prop ? propName(e.prop) : '—' }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ e.note || '—' }}</td>
                <td style="white-space:nowrap">
                  <button v-if="canManage" @click.stop="delExpense(e)" title="Delete" style="background:none;border:none;font-size:15px;cursor:pointer">🗑</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div v-else class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No expenses recorded yet<template v-if="canManage"> — hit “＋ Add expense” to log the first one</template>.</div>
    </template>

    <!-- ── 🖨 REPORT ── -->
    <template v-else-if="tab === 'report'">
      <div class="report-area panel" style="padding:26px 28px;overflow:hidden">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;border-bottom:2px solid var(--border);padding-bottom:16px">
          <div>
            <div style="font-size:20px;font-weight:800;letter-spacing:-.3px">🏘️ Samity Society Report</div>
            <div class="c-sub" style="font-size:12.5px;margin-top:3px">{{ rMonth ? monthLabel(rMonth) : 'All months' }}<template v-if="propFilter.value"> · {{ propName(propFilter.value) }}</template> · generated {{ today() }} · KRTaker</div>
          </div>
          <div style="text-align:right">
            <div class="c-sub" style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Collection rate</div>
            <div style="font-weight:800;font-size:26px" :style="report.rate >= 80 ? 'color:var(--ok)' : 'color:var(--warn)'">{{ report.rate }}%</div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:18px 0">
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:11px 13px">
            <div class="c-sub" style="font-size:10.5px;font-weight:800;text-transform:uppercase">Bills issued</div>
            <div style="font-weight:800;font-size:17px;margin-top:2px">{{ report.bills.length }} · {{ money(report.issued) }}</div>
          </div>
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:11px 13px">
            <div class="c-sub" style="font-size:10.5px;font-weight:800;text-transform:uppercase">Collected</div>
            <div style="font-weight:800;font-size:17px;margin-top:2px;color:var(--ok)">{{ money(report.collected) }}</div>
          </div>
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:11px 13px">
            <div class="c-sub" style="font-size:10.5px;font-weight:800;text-transform:uppercase">Expenses</div>
            <div style="font-weight:800;font-size:17px;margin-top:2px" :style="report.expTotal ? 'color:var(--danger)' : ''">{{ money(report.expTotal) }}</div>
          </div>
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:11px 13px">
            <div class="c-sub" style="font-size:10.5px;font-weight:800;text-transform:uppercase">Net fund</div>
            <div style="font-weight:800;font-size:17px;margin-top:2px" :style="report.net ? 'color:var(--ok)' : ''">{{ money(report.net) }}</div>
          </div>
        </div>
        <div style="font-size:13px;font-weight:800;margin:18px 0 8px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Committee</div>
        <table class="kr" style="width:100%">
          <thead><tr><th>Name</th><th>Role</th><th>Flat</th><th>Phone</th></tr></thead>
          <tbody>
            <tr v-for="m in memAll.filter(inProp)" :key="m.id">
              <td style="white-space:nowrap;font-weight:600">{{ m.name || '—' }}</td>
              <td style="white-space:nowrap">{{ m.role || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ (m.notes || '').replace(/—.*/, '') }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ m.phone || '—' }}</td>
            </tr>
          </tbody>
        </table>
        <div style="font-size:13px;font-weight:800;margin:18px 0 8px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Bills · {{ report.bills.length }}</div>
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Unit</th><th>Month</th><th>Amount</th><th>Due</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="b in report.bills" :key="b.id">
              <td style="white-space:nowrap;font-weight:600">{{ b.id }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ b.unit || '—' }}</td>
              <td style="white-space:nowrap">{{ monthLabel(b.month) }}</td>
              <td style="white-space:nowrap">{{ money(b.amount) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ b.due_date || '—' }}</td>
              <td style="white-space:nowrap">{{ b.status || '—' }}</td>
            </tr>
          </tbody>
        </table>
        <div style="font-size:13px;font-weight:800;margin:18px 0 8px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Collections · {{ report.colls.length }}</div>
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Bill</th><th>Amount</th><th>Method</th><th>Receipt</th><th>Date</th></tr></thead>
          <tbody>
            <tr v-for="c in report.colls" :key="c.id">
              <td style="white-space:nowrap;font-weight:600">{{ c.id }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ c.bill || '—' }}</td>
              <td style="white-space:nowrap">{{ money(c.amount) }}</td>
              <td style="white-space:nowrap">{{ c.method || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ c.receipt_no || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ fmtTs(c.collected_at) }}</td>
            </tr>
          </tbody>
        </table>
        <div v-if="report.exps.length" style="font-size:13px;font-weight:800;margin:18px 0 8px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Expenses · {{ report.exps.length }}</div>
        <table v-if="report.exps.length" class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Category</th><th>Title</th><th>Amount</th><th>Date</th></tr></thead>
          <tbody>
            <tr v-for="e in report.exps" :key="e.id">
              <td style="white-space:nowrap;font-weight:600">{{ e.id }}</td>
              <td style="white-space:nowrap">{{ expCat(e.category).label }}</td>
              <td style="white-space:nowrap">{{ e.title || '—' }}</td>
              <td style="white-space:nowrap">{{ money(e.amount) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ e.exp_date || '—' }}</td>
            </tr>
          </tbody>
        </table>
        <div class="c-sub" style="font-size:11.5px;margin-top:18px;text-align:center">Generated by KRTaker app-v3 · {{ today() }} · Samity module report</div>
      </div>
    </template>

    <!-- ── ⚙️ SETTINGS ── -->
    <template v-else-if="tab === 'settings'">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px">
        <div class="panel" style="overflow:hidden">
          <div style="padding:14px 16px;font-weight:800;font-size:14px;border-bottom:1px solid var(--border)">⚙️ Billing defaults</div>
          <div style="padding:14px 16px;display:flex;flex-direction:column;gap:13px">
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Monthly service charge (৳/unit)</div>
              <input v-model.number="samityCfg.default_charge" type="number" min="1" :disabled="!canManage" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:14px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Due alert days</div>
              <input v-model.number="samityCfg.alert_days" type="number" min="1" max="120" :disabled="!canManage" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:14px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
            <div v-if="canManage" style="display:flex;gap:8px;margin-top:2px">
              <button @click="saveCfg" style="padding:9px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save settings</button>
            </div>
            <div style="font-size:11.5px;color:var(--text-mute);line-height:1.6">Derived from live data: due day {{ settings.dueDay }} · months {{ settings.months.map(monthLabel).join(', ') || '—' }} · {{ settings.bills }} bills / {{ settings.collections }} collections / {{ settings.expenses }} expenses</div>
          </div>
        </div>
        <div class="panel" style="overflow:hidden">
          <div style="padding:14px 16px;font-weight:800;font-size:14px;border-bottom:1px solid var(--border)">👥 Committee roles</div>
          <div style="padding:14px 16px;display:flex;flex-direction:column;gap:12px">
            <div v-for="(meta, role) in ROLE_META" :key="role" style="display:flex;align-items:center;gap:10px">
              <span class="badge" :class="meta.cls">{{ meta.ico }} {{ role }}</span>
              <span class="c-sub" style="font-size:12px">{{ memAll.filter(m => m.role === role && inProp(m)).length }} member(s)</span>
            </div>
          </div>
        </div>
        <div class="panel" style="overflow:hidden">
          <div style="padding:14px 16px;font-weight:800;font-size:14px;border-bottom:1px solid var(--border)">🏘️ Society</div>
          <div style="padding:14px 16px;display:flex;flex-direction:column;gap:12px">
            <div style="display:flex;justify-content:space-between;gap:10px">
              <span class="c-sub" style="font-size:12.5px">Members</span>
              <span style="font-weight:700">{{ settings.members }} total · {{ settings.active }} active</span>
            </div>
            <div style="display:flex;justify-content:space-between;gap:10px">
              <span class="c-sub" style="font-size:12.5px">Owner account</span>
              <span style="font-weight:700">{{ memAll[0]?.owner_email || '—' }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;gap:10px">
              <span class="c-sub" style="font-size:12.5px">Scoped property</span>
              <span style="font-weight:700">{{ propFilter.value ? propName(propFilter.value) : 'All properties' }}</span>
            </div>
            <div style="font-size:12px;color:var(--text-mute);line-height:1.6;margin-top:4px">Values are derived from live society data. Billing, collections, expenses and member edits are managed through the platform API.</div>
          </div>
        </div>
      </div>
    </template>

    <!-- member modal -->
    <template v-if="memberModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="memberModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(480px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">{{ memberForm.id ? '✏️ Edit member' : '👥 Add member' }}</div>
          <button @click="memberModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;display:flex;flex-direction:column;gap:13px">
          <div>
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Name *</div>
            <input v-model="memberForm.name" placeholder="Full name" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Role</div>
              <select v-model="memberForm.role" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none">
                <option v-for="(meta, r) in ROLE_META" :key="r" :value="r">{{ meta.ico }} {{ r }}</option>
              </select>
            </div>
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Status</div>
              <select v-model="memberForm.status" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none">
                <option value="active">✅ Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Phone</div>
              <input v-model="memberForm.phone" placeholder="01XXXXXXXXX" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Member since</div>
              <input v-model="memberForm.since_date" type="date" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
          </div>
          <div>
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Property</div>
            <select v-model="memberForm.prop" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none">
              <option value="">—</option>
              <option v-for="p in propsList" :key="p.id" :value="p.id">{{ p.id }} · {{ p.name }}</option>
            </select>
          </div>
          <div>
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Notes</div>
            <input v-model="memberForm.notes" placeholder="e.g. Flat 4B — owner" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
          </div>
          <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:4px">
            <button @click="memberModal = false" class="btn-ghost" style="padding:9px 16px;font-size:13px">Cancel</button>
            <button @click="saveMember" style="padding:9px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save member</button>
          </div>
        </div>
      </div>
    </template>

    <!-- bill modal -->
    <template v-if="billModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="billModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(480px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">🧾 Issue society bill</div>
          <button @click="billModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;display:flex;flex-direction:column;gap:13px">
          <div>
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Unit *</div>
            <select v-model="billForm.unit" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none">
              <option v-for="u in unitsForProp" :key="u.id" :value="u.id">{{ u.id }} · {{ u.name }}<template v-if="u.p"> ({{ propName(u.p) }})</template></option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Month *</div>
              <input v-model="billForm.month" type="month" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Amount (৳) *</div>
              <input v-model="billForm.amount" type="number" min="1" placeholder="e.g. 3000" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
          </div>
          <div>
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Due date</div>
            <input v-model="billForm.due_date" type="date" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
          </div>
          <div>
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Note</div>
            <input v-model="billForm.note" placeholder="Optional…" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
          </div>
          <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:4px">
            <button @click="billModal = false" class="btn-ghost" style="padding:9px 16px;font-size:13px">Cancel</button>
            <button @click="addBill" style="padding:9px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Issue bill</button>
          </div>
        </div>
      </div>
    </template>

    <!-- collection modal -->
    <template v-if="collModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="collModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(480px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">💳 Record collection</div>
          <button @click="collModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;display:flex;flex-direction:column;gap:13px">
          <div>
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Bill *</div>
            <select v-model="collForm.bill" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none" @change="collForm.amount = remainingOf(collForm.bill) || ''">
              <option v-for="b in collBills" :key="b.id" :value="b.id">{{ b.id }} · {{ b.unit }} · {{ monthLabel(b.month) }} · ৳{{ (b.amount || 0).toLocaleString('en-IN') }} <template v-if="remainingOf(b.id) < (b.amount || 0)">(remaining ৳{{ remainingOf(b.id).toLocaleString('en-IN') }})</template></option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Amount (৳) *</div>
              <input v-model="collForm.amount" type="number" min="1" placeholder="Remaining auto-fills" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Method</div>
              <select v-model="collForm.method" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none">
                <option v-for="m in ['Cash', 'bKash', 'Nagad', 'Bank']" :key="m" :value="m">{{ m }}</option>
              </select>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Date</div>
              <input v-model="collForm.collected_at" type="date" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Note</div>
              <input v-model="collForm.note" placeholder="Optional…" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
          </div>
          <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:4px">
            <button @click="collModal = false" class="btn-ghost" style="padding:9px 16px;font-size:13px">Cancel</button>
            <button @click="addCollection" style="padding:9px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Record payment</button>
          </div>
        </div>
      </div>
    </template>

    <!-- expense modal -->
    <template v-if="expModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="expModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(480px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">💸 Add society expense</div>
          <button @click="expModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;display:flex;flex-direction:column;gap:13px">
          <div>
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Title *</div>
            <input v-model="expForm.title" placeholder="e.g. Lift maintenance, Common-area cleaning…" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Category</div>
              <select v-model="expForm.category" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none">
                <option v-for="(meta, c) in EXP_CAT" :key="c" :value="c">{{ meta.ico }} {{ meta.label }}</option>
              </select>
            </div>
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Amount (৳) *</div>
              <input v-model="expForm.amount" type="number" min="1" placeholder="e.g. 2500" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Date</div>
              <input v-model="expForm.exp_date" type="date" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Property</div>
              <select v-model="expForm.prop" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none">
                <option value="">—</option>
                <option v-for="p in propsList" :key="p.id" :value="p.id">{{ p.id }} · {{ p.name }}</option>
              </select>
            </div>
          </div>
          <div>
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Note</div>
            <input v-model="expForm.note" placeholder="Optional details…" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
          </div>
          <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:4px">
            <button @click="expModal = false" class="btn-ghost" style="padding:9px 16px;font-size:13px">Cancel</button>
            <button @click="addExpense" style="padding:9px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save expense</button>
          </div>
        </div>
      </div>
    </template>

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(600px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">🏘️</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" :class="stCls(sel.status)" style="background:#ffffff">{{ sel.status || '—' }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <div style="display:flex;align-items:center;gap:14px">
            <div style="width:58px;height:58px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px;color:#fff" :style="{ background: avatarColor(sel.id) }">{{ initials(sel.name) }}</div>
            <div>
              <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.name || '—' }}</h2>
              <div class="c-sub" style="margin-top:4px;font-size:12.5px">member since {{ sel.since_date || '—' }}<template v-if="sel.prop"> · {{ propName(sel.prop) }}</template></div>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:14px">
            <span class="badge" :class="roleMeta(sel.role).cls" style="font-size:13px;padding:6px 12px">{{ roleMeta(sel.role).ico }} {{ sel.role || '—' }}</span>
            <span v-if="sel.phone" class="badge b-blue" style="font-size:13px;padding:6px 12px">📞 {{ sel.phone }}</span>
          </div>
          <div v-if="canManage" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
            <button class="btn-ghost" style="padding:8px 14px;font-size:12.5px;font-weight:700" @click="openMemberModal(sel)">✏️ Edit member</button>
            <button style="padding:8px 14px;font-size:12.5px;font-weight:700;border:none;border-radius:10px;background:rgba(231,76,60,.12);color:var(--danger);cursor:pointer" @click="delMember(sel)">🗑 Delete</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px 18px;margin-top:16px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Phone</div>
              <div style="font-weight:700;margin-top:1px"><a v-if="sel.phone" :href="'tel:' + sel.phone" style="color:var(--primary)">{{ sel.phone }}</a><template v-else>—</template></div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Since</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.since_date || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Recorded</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtTs(sel.ts) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Owner</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.owner_email || '—' }}</div>
            </div>
          </div>
          <div v-if="sel.notes" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;font-size:13px;line-height:1.65">{{ sel.notes }}</div>
          <div v-for="[k, v] in detailFields(sel)" :key="k" style="font-size:13px;margin-bottom:8px">
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ k.replace(/_/g, ' ') }}</div>
            <div style="font-weight:600;word-break:break-word;margin-top:1px">{{ String(v) }}</div>
          </div>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.d-cover .badge { background: #ffffff; }
</style>
