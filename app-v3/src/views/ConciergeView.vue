<script setup>
import { computed, ref, watch, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall, apiUpload, apiBlob } from '../api/client'
import { useViewMode, usePager, money, fmtTs } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('concierge')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'svc_mgr', 'legal', 'accountant', 'crm'].includes(auth.user?.role || ''))
const parcelName = (pid) => data.list('land_parcels').find(p => p.id === pid)?.district || pid || ''
const propName = (pid) => data.list('properties').find(p => p.id === pid)?.name || pid || ''
const parcelOptions = computed(() => data.list('land_parcels').map(p => ({ id: p.id, name: p.name || p.district || p.id })))
const propOptions = computed(() => data.list('properties').map(p => ({ id: p.id, name: p.name })))

// ── live API data (app-concierge) ──
const loading = ref(false)
const err = ref('')
const reqAll = ref([])
const holdings = ref([])
const cfg = ref({})

const SERVICES = [
  { v: 'namjari', l: 'Namjari / Mutation', ico: '🔄' },
  { v: 'e_porcha', l: 'e-Porcha', ico: '📜' },
  { v: 'khatian', l: 'Khatian', ico: '🧾' },
  { v: 'holding_tax', l: 'Holding Tax', ico: '🏛️' },
  { v: 'registration', l: 'Registration', ico: '📝' },
]
const DOC_KINDS = [
  { v: 'application', l: 'Application', ico: '📄' },
  { v: 'porcha', l: 'Porcha', ico: '🗺️' },
  { v: 'khatian', l: 'Khatian', ico: '🧾' },
  { v: 'mutation_cert', l: 'Mutation cert', ico: '📜' },
  { v: 'holding_bill', l: 'Holding bill', ico: '🏛️' },
  { v: 'nid', l: 'NID', ico: '🪪' },
  { v: 'other', l: 'Other', ico: '📎' },
]
const docKind = (k) => DOC_KINDS.find(x => x.v === k) || { v: k, l: k || 'Other', ico: '📎' }
// Fee/est-days preview from concierge config (keys: namjari_fee, e_porcha_fee, khatian_fee, holding_tax_fee, registration_fee + *_days)
const feePreview = computed(() => {
  const f = form.value.service
  const fee = cfg.value[f + '_fee']
  const days = cfg.value[f + '_days']
  if (fee === undefined && days === undefined) return null
  return { fee: fee !== undefined ? money(fee) : null, days: days !== undefined ? days + ' days' : null }
})
const svc = (s) => SERVICES.find(x => x.v === s) || { v: s, l: s || 'Service', ico: '🛎️' }
const svcBadgeCls = (s) => ({ namjari: 'b-blue', e_porcha: 'b-orange', khatian: 'b-gray', holding_tax: 'b-blue', registration: 'b-purple' }[s] || 'b-gray')

const STATUSES = ['Submitted', 'Under_Review', 'Docs_Requested', 'In_Progress', 'Awaiting_Fee', 'Completed', 'Rejected', 'Cancelled']
const stCls = (s) => s === 'Completed' ? 'b-green' : (s === 'In_Progress' || s === 'Under_Review' || s === 'Docs_Requested' ? 'b-blue' : (s === 'Awaiting_Fee' ? 'b-orange' : (s === 'Rejected' || s === 'Cancelled' ? 'b-red' : 'b-gray')))
const stLabel = (s) => String(s || '—').replace(/_/g, ' ')
const parseTimeline = (r) => {
  const t = r && r.timeline
  if (Array.isArray(t)) return t
  if (typeof t === 'string') { try { const p = JSON.parse(t); return Array.isArray(p) ? p : [] } catch { return [] } }
  return []
}

async function load() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-concierge', { action: 'summary' })
    if (!r.ok) { err.value = r.error || 'Failed to load concierge data.'; return }
    reqAll.value = (r.requests && r.requests.rows) || r.requests || []
    holdings.value = (r.holdings && r.holdings.rows) || r.holdings || []
    cfg.value = r.config || {}
  } finally { loading.value = false }
}
onMounted(load)

// ── KPIs ──
const kpis = computed(() => {
  const rs = reqAll.value
  const active = rs.filter(r => ['In_Progress', 'Under_Review', 'Docs_Requested'].includes(r.status)).length
  const awaitFee = rs.filter(r => r.status === 'Awaiting_Fee' || (r.fee_status === 'unpaid' && r.fee > 0))
  const done = rs.filter(r => r.status === 'Completed').length
  const collected = rs.filter(r => r.fee_status === 'paid').reduce((s, r) => s + (r.fee || 0), 0)
  const pendingHt = holdings.value.filter(h => h.status === 'Due' || h.status === 'Overdue').length
  return [
    { label: 'Requests', ico: '🛎️', value: rs.length, trend: 'legal concierge services' },
    { label: 'In progress', ico: '⏳', value: active, trend: active ? 'under review / working' : 'none', ok: active === 0 },
    { label: 'Awaiting fee', ico: '🧾', value: awaitFee.length, trend: awaitFee.length ? money(awaitFee.reduce((s, r) => s + (r.fee || 0), 0)) + ' pending' : 'none', ok: awaitFee.length === 0 },
    { label: 'Completed', ico: '✅', value: done, trend: done ? 'delivered' : 'none yet', ok: done > 0 },
    { label: 'Fees collected', ico: '💵', value: money(collected), trend: 'paid service fees' },
    { label: 'Holding taxes', ico: '🏛️', value: holdings.value.length, trend: pendingHt ? pendingHt + ' due/overdue' : 'all settled', ok: pendingHt === 0 },
  ]
})

// ── filters ──
const query = ref('')
const statusFilter = ref('')
const svcFilter = ref('')
const statusOptions = computed(() => [...new Set(reqAll.value.map(r => r.status).filter(Boolean))].sort())
const svcOptions = SERVICES.map(s => s.v)
const filtered = computed(() => {
  let out = reqAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(r => JSON.stringify(r).toLowerCase().includes(q) || (parcelName(r.parcel) || '').toLowerCase().includes(q) || (propName(r.prop) || '').toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(r => (r.status || '') === statusFilter.value)
  if (svcFilter.value) out = out.filter(r => (r.service || '') === svcFilter.value)
  return [...out].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[\",\n]/.test(s) ? '\"' + s.replace(/\"/g, '\"\"') + '\"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'concierge.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── request form ──
const showForm = ref(false)
const form = ref({ service: 'namjari', parcel: '', prop: '', district: '', upazila: '', mouza: '', khatian: '', dag: '', notes: '' })
function openForm() { form.value = { service: 'namjari', parcel: parcelOptions.value[0]?.id || '', prop: '', district: '', upazila: '', mouza: '', khatian: '', dag: '', notes: '' }; showForm.value = true }
async function createRequest() {
  const f = form.value
  const payload = { action: 'request-create', service: f.service, parcel: f.parcel, prop: f.prop, district: f.district.trim(), upazila: f.upazila.trim(), mouza: f.mouza.trim(), khatian: f.khatian.trim(), dag: f.dag.trim(), notes: f.notes.trim() }
  const r = await apiCall('app-concierge', payload)
  if (!r.ok) { alert(r.error || 'Create failed'); return }
  showForm.value = false
  await load()
}

// ── drawer actions ──
const sel = ref(null)
const busy = ref('')
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const r = reqAll.value.find(x => x.id === id); if (r) openDetail(r) }
}, { immediate: true })
function refLinks(r) {
  const out = []
  if (r.parcel) out.push({ label: '🗺️ Land ' + r.parcel, path: '/land', q: r.parcel })
  if (r.prop) out.push({ label: '🏢 ' + propName(r.prop), path: '/properties', q: r.prop })
  return out
}
function detailFields(row) {
  const skip = new Set(['id', 'service', 'status', 'timeline', 'parcel', 'prop', 'fee', 'fee_status', 'assigned_to', 'notes', 'district', 'upazila', 'mouza', 'khatian', 'dag'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
async function setStatus(r, status) {
  busy.value = r.id
  const res = await apiCall('app-concierge', { action: 'request-status', id: r.id, status })
  busy.value = ''
  if (!res.ok) { alert(res.error || 'Status update failed'); return }
  sel.value = { ...sel.value, status }
  await load()
}
async function genFee(r) {
  busy.value = r.id
  const res = await apiCall('app-concierge', { action: 'request-fee', id: r.id })
  busy.value = ''
  if (!res.ok) { alert(res.error || 'Fee generation failed'); return }
  await load()
}
const showEventForm = ref(false)
const eventNote = ref('')
function openEventForm() { eventNote.value = ''; showEventForm.value = true }
async function addEvent() {
  if (!eventNote.value.trim()) return
  const res = await apiCall('app-concierge', { action: 'request-event', id: sel.value.id, note: eventNote.value.trim() })
  if (!res.ok) { alert(res.error || 'Add event failed'); return }
  showEventForm.value = false
  await load()
}

// ── documents (concierge_docs via app-concierge doc-*) ──
const docs = ref([])
const docsLoading = ref(false)
const showDocForm = ref(false)
const docKindVal = ref('other')
const docFile = ref(null)
async function loadDocs(id) {
  if (!id) return
  docsLoading.value = true
  try {
    const r = await apiCall('app-concierge', { action: 'doc-list', id })
    if (r.ok) docs.value = r.docs || []
    else docs.value = []
  } finally { docsLoading.value = false }
}
async function openDetail(r) { sel.value = r; await loadDocs(r.id) }
function openDocForm() { docKindVal.value = 'other'; docFile.value = null; showDocForm.value = true }
async function uploadDoc() {
  if (!docFile.value) { alert('Choose a file first.'); return }
  const fd = new FormData()
  fd.append('request', sel.value.id)
  fd.append('kind', docKindVal.value)
  fd.append('file', docFile.value)
  const r = await apiUpload('app-concierge?action=doc-upload', fd)
  if (!r.ok) { alert(r.error || 'Upload failed'); return }
  showDocForm.value = false
  await loadDocs(sel.value.id)
}
async function removeDoc(d) {
  if (!confirm(`Remove ${d.id} (${d.name})?`)) return
  const r = await apiCall('app-concierge', { action: 'doc-remove', id: d.id })
  if (!r.ok) { alert(r.error || 'Remove failed'); return }
  await loadDocs(sel.value.id)
}
function downloadDoc(d) {
  apiBlob('app-concierge?action=doc-download&id=' + d.id).then(url => {
    if (!url) { alert('Download failed.'); return }
    const a = document.createElement('a')
    a.href = url; a.download = d.name || d.id; a.click()
    setTimeout(() => URL.revokeObjectURL(url), 4000)
  })
}
const docFileChange = (e) => { docFile.value = e.target.files?.[0] || null }

// ── holding tax ──
const showHtForm = ref(false)
const htForm = ref({ city_corp: 'DSCC', ward: '', holding_no: '', fy: '', annual_value: '', rate_pct: '7', due_date: '' })
function openHtForm() { htForm.value = { city_corp: 'DSCC', ward: '', holding_no: '', fy: '', annual_value: '', rate_pct: '7', due_date: '' }; showHtForm.value = true }
async function createHolding() {
  const f = htForm.value
  if (!f.holding_no.trim() || !f.fy.trim()) { alert('Holding no and FY are required.'); return }
  const r = await apiCall('app-concierge', { action: 'holding-create', city_corp: f.city_corp, ward: f.ward.trim(), holding_no: f.holding_no.trim(), fy: f.fy.trim(), annual_value: +f.annual_value || 0, rate_pct: +f.rate_pct || 0, due_date: f.due_date })
  if (!r.ok) { alert(r.error || 'Create failed'); return }
  showHtForm.value = false
  await load()
}
const showPayForm = ref(false)
const payFor = ref(null)
const payReceipt = ref('')
function openPayForm(h) { payFor.value = h; payReceipt.value = ''; showPayForm.value = true }
async function payHolding() {
  if (!payReceipt.value.trim()) return
  const r = await apiCall('app-concierge', { action: 'holding-pay', id: payFor.value.id, receipt_no: payReceipt.value.trim() })
  if (!r.ok) { alert(r.error || 'Pay failed'); return }
  showPayForm.value = false
  await load()
}
const htStatusCls = (h) => h.status === 'Paid' ? 'b-green' : (h.status === 'Overdue' ? 'b-red' : 'b-orange')
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🗂️ Legal Concierge</h1>
        <div class="sub">{{ reqAll.length }} service requests · {{ holdings.length }} holding taxes · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search parcel, property, ref…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="svcFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All services</option>
          <option v-for="s in svcOptions" :key="s" :value="s">{{ svc(s).label }}</option>
        </select>
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ stLabel(s) }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="canManage" @click="openForm" class="btn-primary" style="display:inline-flex;align-items:center;gap:6px">＋ New request</button>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
      </div>
    </div>

    <div v-if="err" class="panel" style="padding:18px;color:var(--danger)">⚠️ {{ err }}</div>
    <div v-if="loading && !reqAll.length" class="panel" style="padding:22px;text-align:center;color:var(--text-mute)">Loading…</div>

    <template v-if="!loading || reqAll.length">
      <div class="stats">
        <div v-for="k in kpis" :key="k.label" class="stat">
          <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
          <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
          <div class="s-trend">{{ k.trend }}</div>
        </div>
      </div>

      <!-- REQUESTS -->
      <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-top:18px">
        <div v-for="r in paged" :key="r.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(r)">
          <div style="height:84px;position:relative;background:var(--grad)">
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">{{ svc(r.service).ico }}</div>
            <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
              <span class="badge" :class="stCls(r.status)" style="background:#ffffff">{{ stLabel(r.status) }}</span>
            </div>
            <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ r.id }}</div>
          </div>
          <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
            <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px">{{ svc(r.service).label }}</div>
            <div class="c-sub" style="font-size:12px">{{ r.district || '—' }}<template v-if="r.upazila"> · {{ r.upazila }}</template><template v-if="r.mouza"> · {{ r.mouza }}</template></div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <span v-if="r.khatian" class="badge b-gray">{{ r.khatian }}</span>
              <span v-if="r.dag" class="badge b-gray">{{ r.dag }}</span>
              <span v-if="r.parcel" class="badge b-blue">🗺️ {{ parcelName(r.parcel) }}</span>
              <span v-if="r.prop" class="badge b-gray">{{ propName(r.prop) }}</span>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <span class="badge b-orange">Fee {{ money(r.fee) }}</span>
              <span class="badge" :class="r.fee_status === 'paid' ? 'b-green' : 'b-gray'">{{ (r.fee_status || '—').toUpperCase() }}</span>
              <span v-if="r.assigned_to" class="badge b-blue">👤 {{ r.assigned_to }}</span>
            </div>
            <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
              <span>🕓 {{ fmtTs(r.ts) }}</span>
              <span v-if="parseTimeline(r).length">📌 {{ parseTimeline(r).length }} events</span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden;margin-top:18px">
        <div class="tbl-wrap">
          <table class="kr" style="width:100%">
            <thead><tr><th>ID</th><th>Service</th><th>Location</th><th>Ref</th><th>Fee</th><th>Assigned</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="r in paged" :key="r.id" style="cursor:pointer" @click="openDetail(r)">
                <td style="font-weight:700;white-space:nowrap">{{ r.id }}</td>
                <td style="white-space:nowrap">{{ svc(r.service).ico }} {{ svc(r.service).label }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ r.district || '—' }}<template v-if="r.upazila"> · {{ r.upazila }}</template></td>
                <td style="white-space:nowrap" class="c-sub">{{ r.service_ref || '—' }}</td>
                <td style="white-space:nowrap">{{ money(r.fee) }} <span class="c-sub" style="font-size:10.5px">{{ (r.fee_status || '').toUpperCase() }}</span></td>
                <td style="white-space:nowrap" class="c-sub">{{ r.assigned_to || '—' }}</td>
                <td style="white-space:nowrap"><span class="badge" :class="stCls(r.status)">{{ stLabel(r.status) }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute);margin-top:18px">No requests found{{ query ? ' for “' + query + '”' : '' }}.</div>

      <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

      <!-- HOLDING TAXES -->
      <div class="panel" style="overflow:hidden;margin-top:18px">
        <div class="panel-h" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:14px 18px;border-bottom:1px solid var(--border)">
          <b style="font-size:15px">🏛️ Holding taxes</b>
          <span class="c-sub" style="font-size:12px">{{ holdings.length }} records</span>
          <div style="margin-left:auto">
            <button v-if="canManage" @click="openHtForm" class="btn-ghost" style="padding:7px 12px;font-size:12px">＋ Add holding</button>
          </div>
        </div>
        <div class="tbl-wrap">
          <table class="kr" style="width:100%">
            <thead><tr><th>ID</th><th>City corp</th><th>Ward</th><th>Holding no</th><th>FY</th><th>Annual value</th><th>Tax</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="h in holdings" :key="h.id">
                <td style="white-space:nowrap;font-weight:700">{{ h.id }}</td>
                <td style="white-space:nowrap">{{ h.city_corp || '—' }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ h.ward || '—' }}</td>
                <td style="white-space:nowrap">{{ h.holding_no || '—' }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ h.fy || '—' }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ money(h.annual_value) }}</td>
                <td style="white-space:nowrap;font-weight:700">{{ money(h.tax_amount) }} <span class="c-sub" style="font-size:10.5px">({{ money(h.paid_amount) }} paid)</span></td>
                <td style="white-space:nowrap"><span class="badge" :class="htStatusCls(h)">{{ h.status }}</span></td>
                <td style="white-space:nowrap">
                  <button v-if="canManage && h.status !== 'Paid'" @click="openPayForm(h)" class="btn-ghost" style="padding:4px 9px;font-size:11.5px">💳 Pay</button>
                </td>
              </tr>
              <tr v-if="!holdings.length"><td colspan="9" style="text-align:center;color:var(--text-mute);padding:20px">No holding tax records.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(600px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">{{ svc(sel.service).ico }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" :class="stCls(sel.status)" style="background:#ffffff">{{ stLabel(sel.status) }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ svc(sel.service).ico }} {{ svc(sel.service).label }}</h2>
          <div class="c-sub" style="margin-top:4px;font-size:12.5px">{{ sel.owner_email || '—' }} · requested {{ fmtTs(sel.ts) }}</div>
          <div v-if="refLinks(sel).length" style="display:flex;gap:6px;flex-wrap:wrap;margin:12px 0">
            <button v-for="l in refLinks(sel)" :key="l.label" class="btn-ghost" style="padding:6px 12px;font-size:12px" @click="go(l.path, { open: l.q })">{{ l.label }}</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px 18px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">District</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.district || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Upazila</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.upazila || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Mouza</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.mouza || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Khatian / Dag</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.khatian || '—' }}<template v-if="sel.khatian && sel.dag"> · </template>{{ sel.dag || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Assigned to</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.assigned_to || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Est. days</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.est_days ? sel.est_days + ' days' : '—' }}</div>
            </div>
          </div>
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
              <div>
                <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Service fee</div>
                <div style="font-weight:800;font-size:22px;margin-top:2px">{{ money(sel.fee) }}</div>
              </div>
              <span class="badge" :class="sel.fee_status === 'paid' ? 'b-green' : 'b-orange'">{{ (sel.fee_status || '—').toUpperCase() }}</span>
            </div>
            <div v-if="sel.fee_paid_at" class="c-sub" style="font-size:12px;margin-top:6px">Paid {{ fmtTs(sel.fee_paid_at) }}</div>
            <div v-if="sel.service_ref" class="c-sub" style="font-size:12px;margin-top:4px">Ref: {{ sel.service_ref }}</div>
          </div>
          <div v-if="canManage" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">⚙️ Manage</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <select v-model="sel.status" :disabled="busy === sel.id" style="padding:7px 9px;border:1px solid var(--border);border-radius:9px;background:var(--card);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
                <option v-for="s in STATUSES" :key="s" :value="s">{{ stLabel(s) }}</option>
              </select>
              <button :disabled="busy === sel.id" @click="setStatus(sel, sel.status)" class="btn-ghost" style="padding:7px 12px;font-size:12px">💾 Set status</button>
              <button :disabled="busy === sel.id" @click="genFee(sel)" class="btn-ghost" style="padding:7px 12px;font-size:12px">🧾 Generate fee</button>
              <button @click="openEventForm" class="btn-ghost" style="padding:7px 12px;font-size:12px">📝 Add event</button>
            </div>
          </div>
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
              <span style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">📎 Documents</span>
              <span class="c-sub" style="font-size:11px">{{ docs.length }}</span>
              <span style="margin-left:auto">
                <button v-if="canManage && !['Completed', 'Rejected', 'Cancelled'].includes(sel.status)" @click="openDocForm" class="btn-ghost" style="padding:5px 10px;font-size:11.5px">＋ Upload</button>
              </span>
            </div>
            <div v-if="docsLoading" class="c-sub" style="font-size:12px;padding:6px 0">Loading…</div>
            <div v-else-if="!docs.length" class="c-sub" style="font-size:12px;padding:6px 0">No documents attached.</div>
            <div v-else style="display:flex;flex-direction:column;gap:6px">
              <div v-for="d in docs" :key="d.id" style="display:flex;align-items:center;gap:8px;font-size:12.5px;padding:5px 0;border-bottom:1px dashed var(--border)">
                <span style="font-size:14px">{{ docKind(d.kind).ico }}</span>
                <span style="font-weight:700;white-space:nowrap">{{ d.id }}</span>
                <span class="c-sub" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ d.name }}</span>
                <span class="c-sub" style="font-size:10.5px">{{ Math.max(1, Math.round((d.size || 0) / 1024)) }} KB</span>
                <button @click="downloadDoc(d)" class="btn-ghost" style="padding:3px 8px;font-size:11px" title="Download">⬇</button>
                <button v-if="canManage" @click="removeDoc(d)" class="btn-ghost" style="padding:3px 8px;font-size:11px;color:var(--danger,#e74c3c)" title="Remove">🗑</button>
              </div>
            </div>
          </div>
          <div v-if="sel.notes" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;font-size:13px;line-height:1.65">{{ sel.notes }}</div>
          <div v-if="parseTimeline(sel).length" style="margin:14px 0">
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">Activity · {{ parseTimeline(sel).length }} events</div>
            <div style="display:flex;flex-direction:column;gap:0">
              <div v-for="(e, i) in parseTimeline(sel)" :key="i" style="display:flex;gap:12px;padding:8px 0;border-bottom:1px dashed var(--border)">
                <div style="font-size:15px;line-height:1.4">🕓</div>
                <div style="flex:1">
                  <div style="font-size:13px;font-weight:600;line-height:1.45">{{ e.action }}</div>
                  <div class="c-sub" style="font-size:11.5px;margin-top:2px">{{ e.by || '—' }} · {{ fmtTs(e.ts || e.t) }}</div>
                </div>
              </div>
            </div>
          </div>
          <div v-for="[k, v] in detailFields(sel)" :key="k" style="font-size:13px;margin-bottom:8px">
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ k.replace(/_/g, ' ') }}</div>
            <div style="font-weight:600;word-break:break-word;margin-top:1px">{{ String(v) }}</div>
          </div>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>

    <!-- new request modal -->
    <template v-if="showForm">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="showForm = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(520px,94vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:90px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">＋ New concierge request</div>
          <button @click="showForm = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Service *</label>
            <select v-model="form.service" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
              <option v-for="s in SERVICES" :key="s.v" :value="s.v">{{ s.l }}</option>
            </select>
            <div v-if="feePreview" style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
              <span class="badge b-green" style="font-size:12px">💰 Fee {{ feePreview.fee }}</span>
              <span class="badge b-blue" style="font-size:12px">⏱ Est. {{ feePreview.days }}</span>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Land parcel</label>
              <select v-model="form.parcel" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
                <option value="">— none —</option>
                <option v-for="p in parcelOptions" :key="p.id" :value="p.id">{{ p.name }} ({{ p.id }})</option>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Property</label>
              <select v-model="form.prop" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
                <option value="">— none —</option>
                <option v-for="p in propOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">District</label>
              <input v-model="form.district" placeholder="e.g. Mymensingh" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Upazila</label>
              <input v-model="form.upazila" placeholder="e.g. Trishal" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Mouza</label>
              <input v-model="form.mouza" placeholder="e.g. Kanthal" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Khatian / Dag</label>
              <div style="display:flex;gap:8px;margin-top:5px">
                <input v-model="form.khatian" placeholder="KH-…" style="flex:1;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
                <input v-model="form.dag" placeholder="DAG-…" style="flex:1;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              </div>
            </div>
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Notes</label>
            <textarea v-model="form.notes" rows="3" placeholder="e.g. mutation after inheritance" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px;resize:vertical"></textarea>
          </div>
          <button @click="createRequest" class="btn-primary" style="margin-top:4px">🛎️ Submit request</button>
        </div>
      </div>
    </template>

    <!-- event modal -->
    <template v-if="showEventForm">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="showEventForm = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(480px,94vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:90px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">📝 Add event — {{ sel?.id }}</div>
          <button @click="showEventForm = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Note *</label>
            <textarea v-model="eventNote" rows="4" placeholder="e.g. documents submitted to AC land office" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px;resize:vertical"></textarea>
          </div>
          <button @click="addEvent" class="btn-primary" style="margin-top:4px">💾 Add to timeline</button>
        </div>
      </div>
    </template>

    <!-- doc upload modal -->
    <template v-if="showDocForm">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="showDocForm = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(480px,94vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:90px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">📎 Upload document — {{ sel?.id }}</div>
          <button @click="showDocForm = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Kind</label>
            <select v-model="docKindVal" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
              <option v-for="k in DOC_KINDS" :key="k.v" :value="k.v">{{ k.ico }} {{ k.l }}</option>
            </select>
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">File (image / PDF, ≤8 MB)</label>
            <input type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" @change="docFileChange" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
          </div>
          <button @click="uploadDoc" class="btn-primary" style="margin-top:4px">⬆ Upload document</button>
        </div>
      </div>
    </template>

    <!-- holding create modal -->
    <template v-if="showHtForm">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="showHtForm = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(500px,94vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:90px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">＋ Add holding tax</div>
          <button @click="showHtForm = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:13px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">City corp</label>
              <select v-model="htForm.city_corp" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
                <option value="DSCC">DSCC</option>
                <option value="DNCC">DNCC</option>
                <option value="CCC">CCC</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Ward</label>
              <input v-model="htForm.ward" placeholder="e.g. 33" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Holding no *</label>
              <input v-model="htForm.holding_no" placeholder="e.g. 777" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">FY *</label>
              <input v-model="htForm.fy" placeholder="e.g. 2026-27" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Annual value</label>
              <input v-model="htForm.annual_value" type="number" min="0" placeholder="0" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Rate %</label>
              <input v-model="htForm.rate_pct" type="number" min="0" step="0.1" placeholder="7" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Due date</label>
              <input v-model="htForm.due_date" type="date" style="width:100%;padding:8px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
            </div>
          </div>
          <button @click="createHolding" class="btn-primary" style="margin-top:4px">🏛️ Save holding</button>
        </div>
      </div>
    </template>

    <!-- holding pay modal -->
    <template v-if="showPayForm">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="showPayForm = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(460px,94vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:90px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">💳 Pay holding — {{ payFor?.id }}</div>
          <button @click="showPayForm = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Receipt no *</label>
            <input v-model="payReceipt" placeholder="e.g. DSCC-RCP-4412" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
          </div>
          <button @click="payHolding" class="btn-primary" style="margin-top:4px">💳 Mark paid</button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.d-cover .badge { background: #ffffff; }
</style>
