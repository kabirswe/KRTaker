<script setup>
import { computed, ref, watch } from 'vue'
import { t } from '../lib/i18n'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall, apiUpload, apiBlob } from '../api/client'
import { badge, useViewMode, usePager } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'
import ScrollTabs from '../components/ScrollTabs.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('leases')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))

const leasesAll = computed(() => data.list('leases'))
const tenantsAll = computed(() => data.list('tenants'))
const unitsAll = computed(() => data.list('units'))
const propsAll = computed(() => data.list('properties'))
const invoicesAll = computed(() => data.list('invoices'))
const paymentsAll = computed(() => data.list('payments'))
const renewalsAll = computed(() => data.list('renewal_requests'))
const docsAll = computed(() => data.list('documents'))

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const fmtSize = (b) => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : b > 1024 ? Math.round(b / 1024) + ' KB' : (b || 0) + ' B'
const fmtTs = (ts) => ts ? String(ts).replace('T', ' ').slice(0, 16) : '—'
const tenantName = (tid) => tenantsAll.value.find(t => t.id === tid)?.name || tid || '—'
const unitName = (uid) => unitsAll.value.find(u => u.id === uid)?.name || uid || '—'
const propName = (pid) => propsAll.value.find(p => p.id === pid)?.name || pid || '—'
const unitProp = (uid) => unitsAll.value.find(u => u.id === uid)?.p || ''
const tenantPhone = (tid) => tenantsAll.value.find(t => t.id === tid)?.phone || '—'
const tenantKind = (tid) => tenantsAll.value.find(t => t.id === tid)?.kind || '—'

function daysLeft(l) { if (!l?.end) return null; return Math.round((new Date(l.end) - Date.now()) / 86400000) }
function invPaid(inv) { return paymentsAll.value.filter(p => p.inv === inv.id && String(p.status).toLowerCase() === 'success').reduce((s, p) => s + (p.amount || 0), 0) }
function invDue(inv) { return Math.max(0, (inv.net || 0) - invPaid(inv)) }
function invStatusRow(inv) { return invDue(inv) <= 0 ? 'Paid' : (invPaid(inv) > 0 ? 'Partial' : 'Unpaid') }
const renewalsOf = (l) => renewalsAll.value.filter(r => r.lease === l.id).sort((a, b) => String(b.ts).localeCompare(String(a.ts)))
const docsOfLease = (l) => docsAll.value.filter(d => d.kind === 'lease' && d.ref === l.id)

// ── KPIs ──
const kpis = computed(() => {
  const active = leasesAll.value.filter(l => String(l.status).toLowerCase() === 'active')
  const pending = leasesAll.value.filter(l => String(l.status).toLowerCase() === 'pending registration')
  const ended = leasesAll.value.filter(l => String(l.status).toLowerCase() === 'ended')
  const expiring = leasesAll.value.filter(l => { const d = daysLeft(l); return d !== null && d >= 0 && d <= 90 && String(l.status).toLowerCase() === 'active' })
  const rentRoll = active.reduce((s, l) => s + (l.rent || 0), 0)
  const adv = leasesAll.value.reduce((s, l) => s + (l.adv || 0), 0)
  const unreg = leasesAll.value.filter(l => !(l.res == 1) && !l.reg_office).length
  return [
    { label: 'Leases', ico: '📄', value: leasesAll.value.length, trend: `${active.length} active` },
    { label: 'Active rent roll', ico: '💵', value: money(rentRoll) + '/mo', trend: `${active.length} active leases` },
    { label: 'Pending registration', ico: '📋', value: pending.length, trend: pending.length ? 'needs reg' : 'all clear', ok: pending.length === 0 },
    { label: 'Expiring ≤ 90d', ico: '⏳', value: expiring.length, trend: expiring.length ? 'renew soon' : 'none', ok: expiring.length === 0 },
    { label: 'Unregistered', ico: '🪪', value: unreg, trend: unreg ? 'TPA §107' : 'all registered', ok: unreg === 0 },
    { label: 'Deposits held', ico: '🏦', value: money(adv), trend: 'advance / security' },
  ]
})

// ── filters / sort ──
const query = ref('')
const statusFilter = ref('')
const propFilter = ref('')
const sortBy = ref('id')
const statusOptions = computed(() => [...new Set(leasesAll.value.map(l => l.status).filter(Boolean))].sort())
const propOptions = computed(() => propsAll.value.map(p => ({ id: p.id, name: p.name })))
const filtered = computed(() => {
  let out = leasesAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(l => l.id.toLowerCase().includes(q) || tenantName(l.t).toLowerCase().includes(q) || unitName(l.u).toLowerCase().includes(q) || propName(unitProp(l.u)).toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(l => l.status === statusFilter.value)
  if (propFilter.value) out = out.filter(l => unitProp(l.u) === propFilter.value)
  const get = (l) => sortBy.value === 'rent' ? (l.rent || 0) : sortBy.value === 'end' ? (l.end || '') : l.id
  return [...out].sort((a, b) => typeof get(a) === 'string' ? String(get(a)).localeCompare(String(get(b))) : get(a) - get(b))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 10)
function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const cols = ['id', 't', 'u', 'start', 'end', 'rent', 'adv', 'res', 'status']
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(c === 't' ? tenantName(r.t) : c === 'u' ? unitName(r.u) : r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'leases.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
const tab = ref('overview')
function openDetail(l) { sel.value = l; tab.value = 'overview'; loadHovo(); loadStmt() }
function closeDetail() { sel.value = null }
// deep link: /leases?open=L-001
watch(() => route.query.open, (id) => {
  if (id) { const l = leasesAll.value.find(x => x.id === id); if (l) openDetail(l) }
}, { immediate: true })
const selInvoices = computed(() => sel.value ? invoicesAll.value.filter(i => i.l === sel.value.id).sort((a, b) => String(b.m).localeCompare(String(a.m))) : [])
const selPaidTotal = computed(() => selInvoices.value.reduce((s, i) => s + invPaid(i), 0))
const selNetTotal = computed(() => selInvoices.value.reduce((s, i) => s + (i.net || 0), 0))
const selRenewals = computed(() => sel.value ? renewalsOf(sel.value) : [])
const selDocs = computed(() => sel.value ? docsOfLease(sel.value) : [])
const selTenant = computed(() => sel.value ? tenantsAll.value.find(t => t.id === sel.value.t) : null)
const selUnit = computed(() => sel.value ? unitsAll.value.find(u => u.id === sel.value.u) : null)

// handover checklists
const hovoList = ref([])
const hovoLoading = ref(false)
async function loadHovo() {
  if (!sel.value) return
  hovoLoading.value = true
  try {
    const r = await apiCall('app-hando-list', { lease: sel.value.id })
    if (r.ok) hovoList.value = r.checklists || []
  } catch (e) { hovoList.value = [] }
  finally { hovoLoading.value = false }
}

// statement (compact)
const stmt = ref(null)
const stmtLoading = ref(false)
async function loadStmt() {
  if (!sel.value) return
  stmt.value = null; stmtLoading.value = true
  try {
    const r = await apiCall('app-moveout', { lease: sel.value.id, action: 'prepare' })
    if (r.ok) stmt.value = r.settlement
  } catch (e) {}
  finally { stmtLoading.value = false }
}

// ── offer renewal modal ──
const offerModal = ref(null)
const offerSaving = ref(false)
function openOffer() {
  if (!sel.value) return
  offerModal.value = { lease: sel.value.id, months: 12, escalation: 0, new_rent: sel.value.rent || 0, note: '' }
}
async function submitOffer() {
  const m = offerModal.value
  if (!m.months || m.months <= 0) { window.__krToast?.('Enter months', 'error'); return }
  if (!m.new_rent || m.new_rent <= 0) { window.__krToast?.('Enter new rent', 'error'); return }
  offerSaving.value = true
  try {
    const r = await apiCall('app-renewal-offer', { lease: m.lease, months: m.months, new_rent: Math.round(m.new_rent), note: m.note })
    if (r.ok) { window.__krToast?.(`🔄 ${r.id} offered to tenant`, 'ok'); offerModal.value = null; await data.bootstrap() }
    else window.__krToast?.(r.error || 'Offer failed', 'error')
  } finally { offerSaving.value = false }
}

// ── edit / create lease ──
const form = ref(null)
const formErr = ref('')
const saving = ref(false)
const LEASE_STATUSES = ['Active', 'Pending Registration', 'Ended', 'Terminated']
function blankLease() { return { u: '', t: '', start: today(), end: '', rent: '', adv: '', res: 0, reg_office: '', reg_deed: '', status: 'Active' } }
function today() { return new Date().toISOString().slice(0, 10) }
function openAdd() { form.value = blankLease(); formErr.value = '' }
function openEdit(l) {
  form.value = { u: l.u, t: l.t, start: l.start, end: l.end, rent: l.rent, adv: l.adv, res: l.res, reg_office: l.reg_office, reg_deed: l.reg_deed, status: l.status }
  formErr.value = ''
}
async function saveLease() {
  const f = form.value
  if (!f.t || !f.u) { formErr.value = 'Tenant and unit are required.'; return }
  if (!f.start || !f.end || !f.rent) { formErr.value = 'Start, end and rent are required.'; return }
  saving.value = true
  try {
    const payload = { ...f, rent: Math.round(f.rent), adv: Math.round(f.adv || 0), res: f.res ? 1 : 0 }
    const r = sel.value
      ? await apiCall('app-crud', { action: 'update', collection: 'leases', id: sel.value.id, data: payload })
      : await apiCall('app-crud', { action: 'create', collection: 'leases', data: payload })
    if (r.ok) { window.__krToast?.(sel.value ? '📄 Lease updated' : `📄 ${r.id || 'Lease'} created`, 'ok'); form.value = null; await data.bootstrap(); if (!sel.value) openDetail(leasesAll.value.find(l => l.id === r.id) || leasesAll.value[0]) }
    else formErr.value = r.error || 'Save failed.'
  } finally { saving.value = false }
}
async function delLease(l) {
  if (!confirm(`Delete ${l.id}? This cannot be undone (invoices/documents may reference it).`)) return
  const r = await apiCall('app-crud', { action: 'delete', collection: 'leases', id: l.id, data: {} })
  if (r.ok) { window.__krToast?.(`🗑️ ${l.id} deleted`, 'ok'); if (sel.value?.id === l.id) closeDetail(); await data.bootstrap() }
  else window.__krToast?.(r.error || 'Delete failed', 'error')
}

// ── payment modal ──
const payModal = ref(null)
const paySaving = ref(false)
const PAY_METHODS = ['Manual', 'Cash', 'Bank Transfer', 'Cheque', 'bKash', 'Nagad', 'Rocket', 'Card']
function openPay(i) { payModal.value = { inv: i, amount: Math.max(0, invDue(i)), date: today(), method: 'Manual', sig: '' } }
async function submitPay() {
  const m = payModal.value
  if (!m || !m.amount || m.amount <= 0) { window.__krToast?.('Enter a positive amount', 'error'); return }
  paySaving.value = true
  try {
    const r = await apiCall('app-invoice-pay', { invoice_id: m.inv.id, amount: Math.round(m.amount), date: m.date, method: m.method, sig: m.sig })
    if (r.ok) { window.__krToast?.(`💳 ${m.inv.id} → ${r.status} (paid ৳${(r.paid || 0).toLocaleString('en-IN')})`, 'ok'); payModal.value = null; await data.bootstrap() }
    else window.__krToast?.(r.error || 'Payment failed', 'error')
  } finally { paySaving.value = false }
}

// ── documents ──
const docUploading = ref(false)
const DOC_TYPES = [
  { id: 'agreement', label: '📄 Agreement & lease papers' },
  { id: 'utility', label: '⚡ Utility papers & bills' },
  { id: 'legal', label: '⚖️ Legal documents' },
  { id: 'tax', label: '🧾 Tax & khajna' },
  { id: 'community', label: '🏘 Community / society' },
  { id: 'other', label: '📁 Other' },
]
const docTypeLabel = (id) => (DOC_TYPES.find(t => t.id === id) || {}).label || id || '—'
async function onDocPick(e) {
  const f = e.target.files && e.target.files[0]
  e.target.value = ''
  if (!f || !sel.value) return
  const fd = new FormData()
  fd.append('file', f); fd.append('kind', 'lease'); fd.append('ref', sel.value.id); fd.append('cat', 'agreement')
  docUploading.value = true
  try {
    const r = await apiUpload('app-doc-upload', fd)
    if (r.ok) { window.__krToast?.('📎 Agreement attached to ' + sel.value.id, 'ok'); await data.bootstrap() }
    else window.__krToast?.(r.error || 'Upload failed', 'error')
  } finally { docUploading.value = false }
}
async function viewDoc(d) {
  const url = await apiBlob('app-doc-view?id=' + encodeURIComponent(d.id))
  if (url) window.open(url, '_blank')
  else window.__krToast?.('Could not open document', 'error')
}
async function downloadDoc(d) {
  const url = await apiBlob('app-doc-download?id=' + encodeURIComponent(d.id))
  if (url) { const a = document.createElement('a'); a.href = url; a.download = d.name || d.id; a.click() }
  else window.__krToast?.('Could not download document', 'error')
}
async function delDoc(d) {
  if (!confirm(`Delete document "${d.name}"?`)) return
  const r = await apiCall('app-doc-delete', { id: d.id })
  if (r.ok) { window.__krToast?.('🗑️ Document deleted', 'ok'); await data.bootstrap() }
  else window.__krToast?.(r.error || 'Delete failed', 'error')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('📄 Leases') }}</h1>
        <div class="sub">{{ leasesAll.length }} leases · {{ kpis[1]?.value || '' }} active rent roll · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" :placeholder="t('Search lease, tenant, unit…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="propFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All properties</option>
          <option v-for="p in propOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="sortBy" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="id">Sort: ID</option>
          <option value="end">Sort: End date</option>
          <option value="rent">Sort: Rent</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
      </CompactFilters>
        <button v-if="canManage" @click="openAdd" class="btn-primary" style="padding:9px 16px" title="Create a new lease agreement">＋ New lease</button>
      </div>
    </div>

    <div class="stats">
      <div v-for="k in kpis" :key="k.label" class="stat">
        <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
        <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
        <div class="s-trend">{{ k.trend }}</div>
      </div>
    </div>

    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px">
      <div v-for="l in paged" :key="l.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(l)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">📄</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="badge(l.status)">{{ l.status }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ l.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div>
            <div style="font-weight:800;font-size:15px;letter-spacing:-.2px">👤 {{ tenantName(l.t) }}</div>
            <div class="c-sub" style="margin-top:2px">🚪 {{ unitName(l.u) }} · 🏢 {{ propName(unitProp(l.u)) }}</div>
          </div>
          <div style="display:flex;gap:13px;font-size:12px;flex-wrap:wrap">
            <span class="c-sub" title="Rent">💵 {{ money(l.rent) }}/mo</span>
            <span class="c-sub" title="Advance">🏦 {{ money(l.adv) }}</span>
            <span class="c-sub" title="Term">{{ l.start }} → {{ l.end }}</span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
            <span v-if="String(l.status).toLowerCase() === 'active' && daysLeft(l) !== null" class="badge" :class="daysLeft(l) <= 90 ? 'b-orange' : 'b-gray'">⏳ {{ daysLeft(l) }}d left</span>
            <span class="badge" :class="l.res == 1 ? 'b-green' : 'b-orange'">{{ l.res == 1 ? '🪪 Registered' : '📋 Unregistered' }}</span>
            <span v-if="renewalsOf(l).filter(r => r.status === 'Pending').length" class="badge b-blue">🔄 {{ renewalsOf(l).filter(r => r.status === 'Pending').length }} pending</span>
            <span class="badge b-gray">🧾 {{ invoicesAll.filter(i => i.l === l.id).length }}</span>
          </div>
          <div v-if="canManage" style="display:flex;gap:6px;border-top:1px solid var(--border);padding-top:9px">
            <button class="btn-ghost" style="flex:1;justify-content:center;padding:6px 10px;font-size:12px" @click.stop="openEdit(l)">✏️ Edit</button>
            <button class="btn-ghost" style="padding:6px 10px;font-size:12px" @click.stop="openOffer">🔄 Offer</button>
            <button class="btn-ghost" style="padding:6px 10px;font-size:12px;color:var(--danger)" @click.stop="delLease(l)">🗑️</button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>{{ t('Lease') }}</th><th>{{ t('Tenant') }}</th><th>{{ t('Unit / Property') }}</th><th>{{ t('Rent / mo') }}</th><th>{{ t('Term') }}</th><th>{{ t('Days left') }}</th><th>{{ t('Registration') }}</th><th>{{ t('Invoices') }}</th><th>{{ t('Status') }}</th><th v-if="canManage">{{ t('Actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="l in paged" :key="l.id" style="cursor:pointer" @click="openDetail(l)">
              <td style="white-space:nowrap"><b>{{ l.id }}</b></td>
              <td style="white-space:nowrap"><a @click.stop="go('/tenants', { open: l.t })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ tenantName(l.t) }}</a></td>
              <td style="white-space:nowrap" class="c-sub">{{ unitName(l.u) }} · {{ propName(unitProp(l.u)) }}</td>
              <td style="font-weight:700;white-space:nowrap">{{ money(l.rent) }}</td>
              <td style="white-space:nowrap">{{ l.start }} → {{ l.end }}</td>
              <td style="white-space:nowrap">{{ String(l.status).toLowerCase() === 'active' && daysLeft(l) !== null ? daysLeft(l) + 'd' : '—' }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="l.res == 1 ? 'b-green' : 'b-orange'">{{ l.res == 1 ? '🪪 Reg' : '📋 Unreg' }}</span></td>
              <td style="white-space:nowrap">{{ invoicesAll.filter(i => i.l === l.id).length }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="badge(l.status)">{{ l.status }}</span></td>
              <td v-if="canManage" style="white-space:nowrap">
                <button class="btn-ghost" style="padding:4px 9px;font-size:11px" @click.stop="openEdit(l)">✏️</button>
                <button class="btn-ghost" style="padding:4px 9px;font-size:11px" @click.stop="openOffer">🔄</button>
                <button class="btn-ghost" style="padding:4px 9px;font-size:11px;color:var(--danger)" @click.stop="delLease(l)">🗑️</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No leases found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(640px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">📄</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="badge(sel.status)">{{ sel.status }}</span>
            <span v-if="daysLeft(sel) !== null && String(sel.status).toLowerCase() === 'active'" class="badge" :class="daysLeft(sel) <= 90 ? 'b-orange' : 'b-green'">⏳ {{ daysLeft(sel) }}d left</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.id }} · {{ tenantName(sel.t) }}</h2>
          <div class="c-sub" style="margin-top:3px">🚪 {{ unitName(sel.u) }} · 🏢 {{ propName(unitProp(sel.u)) }} · {{ sel.start }} → {{ sel.end }}</div>

          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Rent / mo</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ money(sel.rent) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Advance</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ money(sel.adv) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Invoiced</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ money(selNetTotal) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Collected</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ money(selPaidTotal) }}</div>
            </div>
          </div>

          <div style="display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:14px;flex-wrap:wrap">
            <ScrollTabs style="gap:6px;border-bottom:none;margin-bottom:0">
            <button v-for="t in [{id:'overview',label:'Overview',ico:'🏠'},{id:'payments',label:'Payments',ico:'💳'},{id:'documents',label:'Documents',ico:'📎'},{id:'handover',label:'Handover',ico:'📦'}]" :key="t.id" @click="tab = t.id"
              style="padding:9px 14px;border:none;background:none;font-size:13px;font-weight:700;cursor:pointer;border-bottom:2px solid transparent;color:var(--text-mute)"
              :style="tab === t.id ? 'color:var(--primary);border-bottom-color:var(--primary)' : ''">
              {{ t.ico }} {{ t.label }} <span style="opacity:.7">({{ t.id === 'overview' ? 2 : t.id === 'payments' ? selInvoices.length : t.id === 'documents' ? selDocs.length : hovoList.length }})</span>
            </button>
            </ScrollTabs>
          </div>

          <!-- OVERVIEW -->
          <div v-if="tab === 'overview'" style="display:flex;flex-direction:column;gap:12px">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px;cursor:pointer" @click="go('/tenants', { open: sel.t })">
                <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:7px">👤 Tenant ↗</div>
                <div style="font-weight:800;font-size:14px;color:var(--primary)">{{ selTenant?.name || tenantName(sel.t) }}</div>
                <div class="c-sub" style="font-size:11.5px;margin-top:3px">{{ selTenant?.phone || tenantPhone(sel.t) }} · {{ selTenant?.kind || tenantKind(sel.t) }}<template v-if="selTenant?.nrb"> · NRB</template></div>
                <div class="c-sub" style="font-size:11.5px;margin-top:2px">🪪 {{ selTenant?.nid || '—' }}</div>
              </div>
              <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px;cursor:pointer" @click="go('/units', { open: sel.u })">
                <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:7px">🚪 Unit ↗</div>
                <div style="font-weight:800;font-size:14px;color:var(--primary)">{{ selUnit?.name || unitName(sel.u) }}</div>
                <div class="c-sub" style="font-size:11.5px;margin-top:3px">🏢 {{ propName(unitProp(sel.u)) }}<template v-if="selUnit?.floor"> · {{ selUnit.floor }} floor</template></div>
                <div class="c-sub" style="font-size:11.5px;margin-top:2px">📐 {{ (selUnit?.sqft || 0).toLocaleString('en-IN') }} sqft · {{ selUnit?.status || '—' }}</div>
              </div>
            </div>

            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 15px">
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">🪪 Registration (TPA §107)</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12.5px">
                <div><span class="c-sub">Status</span><br><b :style="sel.res == 1 ? 'color:var(--ok)' : 'color:var(--danger)'">{{ sel.res == 1 ? '✅ Registered' : '⚠️ Not registered' }}</b></div>
                <div><span class="c-sub">Reg. office</span><br><b>{{ sel.reg_office || '—' }}</b></div>
                <div><span class="c-sub">Deed no.</span><br><b>{{ sel.reg_deed || '—' }}</b></div>
                <div><span class="c-sub">Term</span><br><b>{{ sel.start }} → {{ sel.end }}</b></div>
              </div>
            </div>

            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 15px">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">🔄 Renewals</div>
                <button v-if="canManage" class="btn-primary" style="padding:5px 12px;font-size:11.5px" @click="openOffer" title="Offer a renewal to the tenant">＋ Offer renewal</button>
              </div>
              <div v-if="!selRenewals.length" class="c-sub" style="font-size:12px">No renewal requests yet.</div>
              <div v-for="r in selRenewals" :key="r.id" style="display:flex;justify-content:space-between;align-items:center;gap:8px;background:var(--card);border:1px solid var(--border);border-radius:9px;padding:8px 11px;margin-bottom:7px">
                <div style="flex:1;min-width:0">
                  <div style="font-weight:700;font-size:12.5px">{{ r.id }} · {{ r.months }} months @ {{ money(r.new_rent) }}/mo</div>
                  <div class="c-sub" style="font-size:11px">{{ r.ts }}<template v-if="r.note"> · {{ r.note }}</template></div>
                </div>
                <span class="badge" :class="badge(r.status)">{{ r.status }}</span>
              </div>
            </div>

            <div v-if="stmtLoading" class="c-sub" style="font-size:12px">Loading statement…</div>
            <div v-else-if="stmt" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 15px">
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">🧾 Move-out statement</div>
              <div style="display:flex;justify-content:space-between;font-size:12.5px;padding:2px 0">
                <span>Status</span><span class="badge" :class="badge(stmt.status)">{{ stmt.status }}</span>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:12.5px;padding:2px 0">
                <span>Total due</span><b>৳{{ ((stmt.totals?.total_due) || 0).toLocaleString('en-IN') }}</b>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:12.5px;padding:2px 0">
                <span>Balance {{ stmt.status === 'DUE' ? 'payable' : 'refund' }}</span>
                <b :style="stmt.status === 'DUE' ? 'color:var(--danger)' : 'color:var(--ok)'">৳{{ ((stmt.status === 'DUE' ? stmt.totals?.balance : stmt.totals?.refund) || 0).toLocaleString('en-IN') }}</b>
              </div>
              <div class="c-sub" style="font-size:11px;margin-top:6px">Finalize move-out (with editable deductions) from the tenant drawer → 🧾 Settlement.</div>
            </div>
          </div>

          <!-- PAYMENTS -->
          <div v-if="tab === 'payments'">
            <div class="drawer-tbl-wrap">
              <table class="kr" style="width:100%">
              <thead><tr><th>Invoice</th><th>Month</th><th>Net</th><th>Paid</th><th>Due</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <tr v-for="i in selInvoices" :key="i.id">
                  <td style="font-weight:700"><a @click.stop="go('/invoices', { open: i.id })" style="color:var(--primary);cursor:pointer;text-decoration:none;font-weight:800">{{ i.id }}</a> <span class="c-sub" style="font-size:10.5px">↗</span></td>
                  <td>{{ i.m }}</td>
                  <td>{{ money(i.net) }}</td>
                  <td style="color:var(--ok)">{{ money(invPaid(i)) }}</td>
                  <td :style="invDue(i) > 0 ? 'color:var(--danger);font-weight:800' : ''">{{ money(invDue(i)) }}</td>
                  <td><span class="badge" :class="badge(invStatusRow(i))">{{ invStatusRow(i) }}</span></td>
                  <td>
                    <button v-if="canManage && invDue(i) > 0" class="btn-ghost" style="padding:4px 9px;font-size:11.5px" @click.stop="openPay(i)">💳 Pay</button>
                  </td>
                </tr>
                <tr v-if="!selInvoices.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:20px">No invoices for this lease yet.</td></tr>
              </tbody>
            </table>
            </div>
            <div class="c-sub" style="font-size:11.5px;margin-top:9px">Collected {{ money(selPaidTotal) }} of {{ money(selNetTotal) }} invoiced across {{ selInvoices.length }} invoice(s).</div>
          </div>

          <!-- DOCUMENTS -->
          <div v-if="tab === 'documents'">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">📎 Agreement documents</div>
              <label v-if="canManage" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;background:var(--primary);color:#fff;font-size:11.5px;font-weight:800">
                {{ docUploading ? 'Uploading…' : '⬆ Attach scanned agreement' }}
                <input type="file" style="display:none" @change="onDocPick">
              </label>
            </div>
            <div v-if="!selDocs.length" class="c-sub" style="font-size:12px;padding:8px 0">No scanned agreement attached to this lease yet.</div>
            <div v-for="d in selDocs" :key="d.id" style="display:flex;align-items:center;gap:10px;background:var(--bg-alt);border:1px solid var(--border);border-radius:9px;padding:9px 12px;margin-bottom:8px">
              <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ d.name }}</div>
                <div class="c-sub" style="font-size:11px">{{ d.id }} · {{ docTypeLabel(d.cat) }} · {{ fmtSize(d.size) }} · {{ fmtTs(d.ts) }}</div>
              </div>
              <button class="btn-ghost" style="padding:5px 10px;font-size:11.5px" @click="viewDoc(d)">👁 View</button>
              <button class="btn-ghost" style="padding:5px 10px;font-size:11.5px" @click="downloadDoc(d)">⬇</button>
              <button v-if="canManage" class="btn-ghost" style="padding:5px 10px;font-size:11.5px;color:var(--danger)" @click="delDoc(d)">🗑️</button>
            </div>
          </div>

          <!-- HANDOVER -->
          <div v-if="tab === 'handover'">
            <div class="c-sub" style="font-size:11.5px;margin-bottom:9px">Move-in / move-out checklists for this lease. Full editing lives in the tenant drawer → 📦 Handover.</div>
            <div v-if="hovoLoading" class="c-sub" style="font-size:12px">Loading…</div>
            <div v-else-if="!hovoList.length" class="c-sub" style="font-size:12px;padding:8px 0">No handover checklists yet.</div>
            <div v-for="h in hovoList" :key="h.id" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:11px 13px;margin-bottom:8px">
              <div style="display:flex;justify-content:space-between;align-items:center">
                <div style="font-weight:800;font-size:13px">{{ h.id }} · {{ h.kind === 'move_in' ? '🚪 Move-in' : '📦 Move-out' }}</div>
                <span class="badge" :class="badge(h.status)">{{ h.status }}</span>
              </div>
              <div class="c-sub" style="font-size:11.5px;margin-top:3px">{{ h.ts }} · {{ (typeof h.items === 'string' ? JSON.parse(h.items || '[]').length : (h.items || []).length) }} items · {{ h.created_by || '—' }}</div>
            </div>
          </div>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>

    <!-- offer modal -->
    <template v-if="offerModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="offerModal = null"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(440px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">🔄 Renewal offer — {{ offerModal.lease }}</h3>
          <button @click="offerModal = null" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Months</label>
            <input v-model.number="offerModal.months" type="number" min="1" max="36" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">New rent (৳/month)</label>
            <input v-model.number="offerModal.new_rent" type="number" min="0" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Note (conditions)</label>
            <textarea v-model="offerModal.note" rows="2" placeholder="e.g. new parking rate applies, service charge 5%…" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;resize:vertical"></textarea>
          </div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="offerModal = null">Cancel</button>
          <button class="btn-primary" :disabled="offerSaving" @click="submitOffer" style="padding:9px 18px">{{ offerSaving ? 'Sending…' : '📤 Send offer to tenant' }}</button>
        </div>
      </div>
    </template>

    <!-- edit / create lease modal -->
    <template v-if="form">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="form = null"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(520px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">{{ sel ? '✏️ Edit lease ' + sel.id : '📄 New lease' }}</h3>
          <button @click="form = null" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:grid;grid-template-columns:1fr 1fr;gap:13px">
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Tenant *</label>
            <select v-model="form.t" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
              <option value="">Select tenant…</option>
              <option v-for="t in tenantsAll" :key="t.id" :value="t.id">{{ t.name }} ({{ t.id }})</option>
            </select>
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Unit *</label>
            <select v-model="form.u" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
              <option value="">Select unit…</option>
              <option v-for="u in unitsAll" :key="u.id" :value="u.id">{{ u.name }} ({{ u.id }}) · {{ propName(u.p) }}</option>
            </select>
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Start *</label>
            <input v-model="form.start" type="date" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">End *</label>
            <input v-model="form.end" type="date" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Rent (৳/mo) *</label>
            <input v-model.number="form.rent" type="number" min="0" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Advance (৳)</label>
            <input v-model.number="form.adv" type="number" min="0" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Status</label>
            <select v-model="form.status" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
              <option v-for="s in LEASE_STATUSES" :key="s" :value="s">{{ s }}</option>
            </select>
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Registered</label>
            <select v-model="form.res" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
              <option :value="1">Yes — registered</option>
              <option :value="0">No — not yet</option>
            </select>
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Reg. office</label>
            <input v-model="form.reg_office" placeholder="e.g. Dhaka Sub-registry" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Deed no.</label>
            <input v-model="form.reg_deed" placeholder="e.g. 3850/2026" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
          </div>
          <div v-if="formErr" style="grid-column:1/-1;color:var(--danger);font-size:12.5px">{{ formErr }}</div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="form = null">Cancel</button>
          <button class="btn-primary" :disabled="saving" @click="saveLease" style="padding:9px 18px">{{ saving ? 'Saving…' : '💾 Save lease' }}</button>
        </div>
      </div>
    </template>

    <!-- payment modal -->
    <template v-if="payModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="payModal = null"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(400px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">💳 Record payment — {{ payModal.inv.id }}</h3>
          <button @click="payModal = null" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Amount (৳) *</label>
            <input v-model.number="payModal.amount" type="number" min="0" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:14px;font-weight:800;color:var(--text);outline:none">
            <div class="c-sub" style="font-size:11px;margin-top:4px">Remaining due: ৳{{ invDue(payModal.inv).toLocaleString('en-IN') }}</div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Date</label>
              <input v-model="payModal.date" type="date" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            </div>
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Method</label>
              <select v-model="payModal.method" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
                <option v-for="m in PAY_METHODS" :key="m" :value="m">{{ m }}</option>
              </select>
            </div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Reference / signature</label>
            <input v-model="payModal.sig" placeholder="e.g. BK-7f2a, cheque no…" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="payModal = null">Cancel</button>
          <button class="btn-primary" :disabled="paySaving" @click="submitPay" style="padding:9px 18px">{{ paySaving ? 'Recording…' : '💳 Record payment' }}</button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* White badges only on cover/header areas (same convention as Properties/Units) */
.d-cover .badge { background: #ffffff; }
</style>
