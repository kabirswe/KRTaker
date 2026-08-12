<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { apiCall, apiBase } from '../api/client'
import { useViewMode, usePager, fmtTs, maskNid, avatarColor, initials } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('nid')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const nvAll = computed(() => data.list('nid_verifications'))
const tenantName = (tid) => data.list('tenants').find(t => t.id === tid)?.name || tid || ''

const stCls = (s) => s === 'verified' ? 'b-green' : (s === 'unverified' ? 'b-orange' : (s === 'mismatch' ? 'b-red' : 'b-gray'))
const okBadge = (v) => v ? 'b-green' : 'b-red'

// ── KPIs ──
const kpis = computed(() => {
  const vs = nvAll.value
  const verified = vs.filter(v => v.status === 'verified').length
  const unverified = vs.filter(v => v.status === 'unverified').length
  const mismatch = vs.filter(v => v.status === 'mismatch').length
  const ck = vs.filter(v => v.checksum_ok).length
  const age = vs.filter(v => v.age_ok).length
  const tenants = new Set(vs.map(v => v.tenant).filter(Boolean)).size
  return [
    { label: 'Verifications', ico: '🪪', value: vs.length, trend: 'NID checks run' },
    { label: 'Verified', ico: '✅', value: verified, trend: verified ? 'identity confirmed' : 'none', ok: verified > 0 },
    { label: 'Unverified', ico: '⚠️', value: unverified, trend: unverified ? 'pending manual check' : 'none', ok: unverified === 0 },
    { label: 'Mismatch', ico: '🚨', value: mismatch, trend: mismatch ? 'check-digit failures' : 'none', ok: mismatch === 0 },
    { label: 'Checksum OK', ico: '🧮', value: ck, trend: 'valid check digits' },
    { label: 'Age OK', ico: '🎂', value: age, trend: tenants + ' tenants screened' },
  ]
})

// ── filters ──
const query = ref('')
const statusFilter = ref('')
const statusOptions = computed(() => [...new Set(nvAll.value.map(v => v.status).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = nvAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(v => JSON.stringify(v).toLowerCase().includes(q) || (tenantName(v.tenant) || '').toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(v => (v.status || '') === statusFilter.value)
  return [...out].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'nid.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(v) { sel.value = v }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const v = nvAll.value.find(x => x.id === id); if (v) openDetail(v) }
}, { immediate: true })
function detailFields(row) {
  const skip = new Set(['id', 'tenant', 'nid', 'dob', 'status', 'method', 'checksum_ok', 'age_ok', 'verified_by', 'verified_at', 'notes'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}

// ══ DMP Thana / Tenant Information Forms (app-trust tif-*) ══
const tab = ref('nid')
const tfItems = ref([])
const tfLoading = ref(false)
const tfErr = ref('')
const isStaff = computed(() => ['superadmin', 'owner', 'manager', 'legal', 'accountant', 'svc_mgr'].includes(data.user?.role || ''))
const tfStatusCls = (s) => s === 'Verified' ? 'b-green' : (s === 'Submitted' ? 'b-blue' : (s === 'Draft' ? 'b-orange' : 'b-gray'))

async function loadTf() {
  tfLoading.value = true; tfErr.value = ''
  try {
    const r = await apiCall('app-trust', { action: 'tif-list' })
    if (!r.ok) { tfErr.value = r.error || 'Failed to load thana forms.'; return }
    tfItems.value = r.items || []
  } catch (e) { tfErr.value = e.message }
  finally { tfLoading.value = false }
}

// create
const tfTenant = ref('')
const tfCreating = ref(false)
async function createTf() {
  if (isStaff.value && !tfTenant.value) { tfErr.value = 'Select a tenant first.'; return }
  tfCreating.value = true; tfErr.value = ''
  try {
    const body = { action: 'tif-create' }
    if (isStaff.value) body.tenant = tfTenant.value
    const r = await apiCall('app-trust', body)
    if (!r.ok) { tfErr.value = r.error || 'Create failed.'; return }
    await loadTf()
    // open the edit form with the default payload
    const f = tfItems.value.find(x => x.id === r.id)
    openTfEdit(f || { id: r.id, tenant: tfTenant.value, payload: r.payload })
    tfTenant.value = ''
  } catch (e) { tfErr.value = e.message }
  finally { tfCreating.value = false }
}

// edit
const tfEdit = ref(null)     // { id, tenant_name, unit_name, property_name, thana, district, status, payload }
const tfSaving = ref(false)
function openTfEdit(f) {
  tfEdit.value = {
    id: f.id, tenant_name: f.tenant_name || f.tenant || '', thana: f.thana || '', district: f.district || '',
    status: f.status || 'Draft', payload: JSON.parse(JSON.stringify(f.payload || {})),
  }
}
const TF_FIELDS = [
  ['name', 'Full name'], ['nid', 'NID'], ['dob', 'Date of birth'], ['phone', 'Phone'],
  ['father', "Father's name"], ['mother', "Mother's name"], ['profession', 'Profession'], ['employer', 'Employer'],
  ['present_flat', 'Present flat'], ['present_road', 'Present road'], ['present_area', 'Present area'],
  ['permanent_address', 'Permanent address'], ['spouse', 'Spouse name'], ['spouse_phone', 'Spouse phone'],
  ['family_count', 'Family members'], ['ref1_name', 'Referee 1 name'], ['ref1_phone', 'Referee 1 phone'],
  ['ref1_address', 'Referee 1 address'], ['ref2_name', 'Referee 2 name'], ['ref2_phone', 'Referee 2 phone'],
  ['ref2_address', 'Referee 2 address'], ['landlord_name', 'Landlord name'], ['landlord_nid', 'Landlord NID'],
  ['landlord_phone', 'Landlord phone'], ['move_in', 'Move-in date'], ['lease_term', 'Lease term'],
  ['vehicle', 'Vehicle'], ['remarks', 'Remarks'],
]
async function saveTf() {
  if (!tfEdit.value) return
  tfSaving.value = true; tfErr.value = ''
  try {
    const body = { action: 'tif-save', id: tfEdit.value.id, thana: tfEdit.value.thana, district: tfEdit.value.district }
    for (const [k] of TF_FIELDS) body[k] = tfEdit.value.payload[k] || ''
    const r = await apiCall('app-trust', body)
    if (!r.ok) { tfErr.value = r.error || 'Save failed.'; return }
    tfEdit.value = null
    await loadTf()
  } catch (e) { tfErr.value = e.message }
  finally { tfSaving.value = false }
}
async function submitTf(f) {
  if (!confirm(`Submit form ${f.id} to the thana? Status will move to Submitted (locked for verification).`)) return
  tfErr.value = ''
  const r = await apiCall('app-trust', { action: 'tif-submit', id: f.id })
  if (!r.ok) { tfErr.value = r.error || 'Submit failed.'; return }
  await loadTf()
}
async function verifyTf(f, verdict) {
  if (!confirm(`${verdict === 'approve' ? 'Approve' : 'Reject'} form ${f.id}?`)) return
  tfErr.value = ''
  const r = await apiCall('app-trust', { action: 'tif-verify', id: f.id, verdict })
  if (!r.ok) { tfErr.value = r.error || 'Verification failed.'; return }
  await loadTf()
}
async function printTf(f) {
  tfErr.value = ''
  try {
    const res = await fetch(apiBase() + 'app-trust', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + localStorage.getItem('krtaker_dash_token') },
      body: JSON.stringify({ action: 'tif-print', id: f.id }),
    })
    const html = await res.text()
    if (!html || html.startsWith('{')) { tfErr.value = 'Print failed.'; return }
    const w = window.open('', '_blank')
    if (!w) { tfErr.value = 'Pop-up blocked — allow pop-ups for print.'; return }
    w.document.write(html); w.document.close(); w.focus()
    setTimeout(() => { try { w.print() } catch (e) {} }, 600)
  } catch (e) { tfErr.value = e.message }
}

// ══ Print settings (font size / gap / letter-spacing / padding / margin / position nudge) — GLOBAL for all DMP Thana forms, admin only ══
const PRINT_CFG_FIELDS = [
  ['fs', 'Font size (px)', 6, 40, 0.5],
  ['lh', 'Line gap (line-height)', 0.5, 4, 0.05],
  ['ls', 'Letter spacing (px)', -5, 20, 0.25],
  ['pd', 'Padding (px)', 0, 30, 0.5],
  ['mg', 'Page margin (mm)', 0, 40, 0.5],
  ['px', 'Position X (%)', -50, 50, 0.5],
  ['py', 'Position Y (%)', -50, 50, 0.5],
]
const tfCfg = ref(false)
const tfCfgForm = ref({})
const tfCfgSaving = ref(false)
const tfCfgErr = ref('')
const tfCfgDefaults = ref(null)
const tfCfgDirty = ref(false)
const previewHtml = ref('')
const previewDoc = ref(null)
const previewLoading = ref(false)
const previewErr = ref('')
const isAdmin = computed(() => ['superadmin', 'super_admin', 'admin', 'owner', 'manager'].includes(data.user?.role || ''))

async function openPrintCfg() {
  tfCfgErr.value = ''
  previewErr.value = ''
  tfCfg.value = true
  tfCfgForm.value = {}
  tfCfgDirty.value = false
  try {
    const r = await apiCall('app-trust', { action: 'tif-print-cfg-global' })
    if (!r.ok) { tfCfgErr.value = r.error || 'Failed to load print settings.'; return }
    tfCfgForm.value = { ...(r.global || r.defaults || {}) }
    tfCfgDefaults.value = r.defaults || null
    // fetch a live preview of the official form with a sample tenant's data
    const sample = tfItems.value.find(f => f.payload?.name || f.tenant_name) || tfItems.value[0]
    if (sample) {
      previewLoading.value = true
      try {
        const res = await fetch(apiBase() + 'app-trust', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + localStorage.getItem('krtaker_dash_token') },
          body: JSON.stringify({ action: 'tif-print', id: sample.id, preview: 1 }),
        })
        const html = await res.text()
        if (html && !html.startsWith('{')) previewHtml.value = html
        else previewErr.value = 'Preview unavailable.'
      } catch (e) { previewErr.value = e.message }
      finally { previewLoading.value = false }
    } else {
      previewErr.value = 'No forms yet — create one to see the preview.'
    }
  } catch (e) { tfCfgErr.value = e.message }
}
const cfgSliderStyle = 'flex:1;accent-color:var(--primary)'
function onCfgInput(k, val) {
  tfCfgForm.value[k] = val
  tfCfgDirty.value = true
  applyCfgPreview()
}
function applyCfgPreview() {
  const d = previewDoc.value
  if (!d) return
  const f = tfCfgForm.value
  const el = d.documentElement
  el.style.setProperty('--fs', (f.fs ?? 12.5) + 'px')
  el.style.setProperty('--lh', f.lh ?? 1.15)
  el.style.setProperty('--ls', (f.ls ?? 0) + 'px')
  el.style.setProperty('--pd', (f.pd ?? 0) + 'px')
  el.style.setProperty('--mg', (f.mg ?? 7.5) + 'mm')
  el.style.setProperty('--px', (f.px ?? 0) + '%')
  el.style.setProperty('--py', (f.py ?? 0) + '%')
}
function onPreviewLoad() {
  const ifr = document.querySelector('.print-preview-iframe')
  previewDoc.value = ifr && ifr.contentDocument ? ifr.contentDocument : null
  applyCfgPreview()
}
async function savePrintCfg() {
  if (!tfCfg.value) return
  tfCfgSaving.value = true; tfCfgErr.value = ''
  try {
    const r = await apiCall('app-trust', { action: 'tif-print-cfg-global', mode: 'save', cfg: tfCfgForm.value })
    if (!r.ok) { tfCfgErr.value = r.error || 'Failed to save settings.'; return }
    tfCfgDirty.value = false
    tfCfg.value = false
    toast?.('Print settings saved — applies to all DMP Thana forms')
  } catch (e) { tfCfgErr.value = e.message }
  finally { tfCfgSaving.value = false }
}
async function resetPrintCfg() {
  tfCfgForm.value = { ...(tfCfgDefaults.value || {}) }
  tfCfgDirty.value = true
  applyCfgPreview()
}
// Default — restore the exact factory defaults (original column-wise layout), independent of any saved config
function defaultPrintCfg() {
  tfCfgForm.value = { fs: 12.5, lh: 1.15, ls: 0, pd: 0, mg: 7.5, px: 0, py: 0 }
  tfCfgDirty.value = true
  applyCfgPreview()
}
</script>

<template>
  <div>
    <template v-if="tab === 'nid'">
    <div class="page-head">
      <div>
        <h1>🪪 NID Verification</h1>
        <div class="sub">{{ nvAll.length }} verifications · {{ kpis[1]?.value || 0 }} verified · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" placeholder="Search tenant, NID…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
      </CompactFilters>
      </div>
    </div>

    <!-- Tabs -->
    <div style="display:flex;gap:8px;margin-bottom:16px">
      <button @click="tab = 'nid'" :style="tab === 'nid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'" style="padding:9px 16px;border:none;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer">🪪 NID Checks</button>
      <button @click="tab = 'thana'; loadTf()" :style="tab === 'thana' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'" style="padding:9px 16px;border:none;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer">📋 Thana Forms</button>
    </div>

    <div class="stats">
      <div v-for="k in kpis" :key="k.label" class="stat">
        <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
        <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
        <div class="s-trend">{{ k.trend }}</div>
      </div>
    </div>

    <!-- GRID -->
    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px">
      <div v-for="v in paged" :key="v.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(v)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">🪪</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="stCls(v.status)" style="background:#ffffff">{{ v.status || '—' }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ v.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="display:flex;align-items:center;gap:10px">
            <div style="width:34px;height:34px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff" :style="{ background: avatarColor(v.tenant || v.id) }">{{ initials(tenantName(v.tenant)) }}</div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:800;font-size:14px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ tenantName(v.tenant) }}</div>
              <div class="c-sub" style="font-size:11.5px">{{ v.tenant }}</div>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge b-gray" style="font-family:monospace">{{ maskNid(v.nid) }}</span>
            <span v-if="v.method" class="badge b-blue">{{ v.method }}</span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span v-if="v.dob" class="badge b-gray">🎂 {{ v.dob }}</span>
            <span class="badge" :class="okBadge(v.checksum_ok)">{{ v.checksum_ok ? '✅ Checksum' : '❌ Checksum' }}</span>
            <span class="badge" :class="okBadge(v.age_ok)">{{ v.age_ok ? '✅ Age' : '❌ Age' }}</span>
          </div>
          <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
            <span>🕓 {{ fmtTs(v.ts) }}</span>
            <span v-if="v.verified_by">✅ {{ v.verified_by }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Tenant</th><th>NID</th><th>DOB</th><th>Method</th><th>Checks</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="v in paged" :key="v.id" style="cursor:pointer" @click="openDetail(v)">
              <td style="font-weight:700;white-space:nowrap">{{ v.id }}</td>
              <td style="white-space:nowrap">{{ tenantName(v.tenant) }}</td>
              <td style="white-space:nowrap;font-family:monospace" class="c-sub">{{ maskNid(v.nid) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ v.dob || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ v.method || '—' }}</td>
              <td style="white-space:nowrap">{{ v.checksum_ok ? '✅' : '❌' }} {{ v.age_ok ? '✅' : '❌' }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="stCls(v.status)">{{ v.status || '—' }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No verifications found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(600px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">🪪</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" :class="stCls(sel.status)" style="background:#ffffff">{{ sel.status || '—' }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <div style="display:flex;align-items:center;gap:14px">
            <div style="width:58px;height:58px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px;color:#fff" :style="{ background: avatarColor(sel.tenant || sel.id) }">{{ initials(tenantName(sel.tenant)) }}</div>
            <div>
              <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ tenantName(sel.tenant) }}</h2>
              <div class="c-sub" style="margin-top:4px;font-size:12.5px">checked {{ fmtTs(sel.ts) }}</div>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:14px">
            <button class="btn-ghost" style="padding:6px 12px;font-size:12px" @click="go('/tenants', { open: sel.tenant })">👤 Tenant {{ sel.tenant }}</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px 18px;margin-top:14px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">NID number</div>
              <div style="font-weight:700;margin-top:1px;font-family:monospace">{{ maskNid(sel.nid) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Date of birth</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.dob || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Method</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.method || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Verified by</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.verified_by || '—' }}<template v-if="sel.verified_at"> · {{ fmtTs(sel.verified_at) }}</template></div>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin:14px 0">
            <span class="badge" :class="okBadge(sel.checksum_ok)">{{ sel.checksum_ok ? '✅ Check digit valid' : '❌ Check digit mismatch' }}</span>
            <span class="badge" :class="okBadge(sel.age_ok)">{{ sel.age_ok ? '✅ Age OK' : '❌ Age check failed' }}</span>
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
    </template>

    <!-- ══ THANA FORMS TAB ══ -->
    <template v-if="tab === 'thana'">
      <div class="page-head">
        <div>
          <h1>📋 DMP Thana Forms</h1>
          <div class="sub">Tenant information forms for thana submission · create, submit, verify, print</div>
        </div>
        <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn-ghost" @click="loadTf()">🔄 Refresh</button>
          <button v-if="isAdmin" class="btn-ghost" title="Print settings — applies to all DMP Thana forms (admin only)" @click="openPrintCfg()">⚙️ Print settings</button>
        </div>
      </div>
      <div v-if="tfErr" style="padding:10px 14px;border-radius:10px;background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.35);margin-bottom:14px;font-weight:600;font-size:13.5px">⚠️ {{ tfErr }}</div>

      <!-- create bar -->
      <div v-if="isStaff" class="panel" style="padding:16px 18px;margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <div style="font-weight:800;font-size:13.5px">＋ New DMP form</div>
        <select v-model="tfTenant" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;flex:1;min-width:200px">
          <option value="">Select tenant…</option>
          <option v-for="t in data.list('tenants')" :key="t.id" :value="t.id">{{ t.name }} ({{ t.id }})</option>
        </select>
        <button @click="createTf" :disabled="tfCreating || !tfTenant" style="padding:9px 14px;border:none;border-radius:9px;background:var(--primary);color:#fff;font-weight:800;font-size:12.5px;cursor:pointer">Create {{ tfCreating ? '…' : '' }}</button>
      </div>

      <div v-if="tfLoading" class="panel" style="padding:36px;text-align:center;color:var(--text-mute)">Loading…</div>
      <div v-else-if="!tfItems.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No thana forms yet{{ isStaff ? ' — create one above' : '' }}.</div>
      <div v-else class="panel" style="overflow:hidden">
        <div class="tbl-wrap">
          <table class="kr" style="width:100%">
            <thead><tr><th>ID</th><th>Tenant</th><th>Unit</th><th>Property</th><th>Thana</th><th>District</th><th>Status</th><th>Created</th><th></th></tr></thead>
            <tbody>
              <tr v-for="f in tfItems" :key="f.id">
                <td style="font-weight:700;white-space:nowrap">{{ f.id }}</td>
                <td style="white-space:nowrap">{{ f.tenant_name || f.tenant }}</td>
                <td class="c-sub" style="white-space:nowrap">{{ f.unit_name || f.unit || '—' }}</td>
                <td class="c-sub" style="white-space:nowrap">{{ f.property_name || f.prop || '—' }}</td>
                <td class="c-sub">{{ f.thana || '—' }}</td>
                <td class="c-sub">{{ f.district || '—' }}</td>
                <td><span class="badge" :class="tfStatusCls(f.status)">{{ f.status }}</span></td>
                <td class="c-sub" style="white-space:nowrap">{{ fmtTs(f.ts) }}</td>
                <td style="white-space:nowrap">
                  <button v-if="f.status !== 'Verified'" class="btn-ghost" style="padding:4px 9px;font-size:11.5px" @click="openTfEdit(f)">✏️ Edit</button>
                  <button v-if="f.status === 'Draft'" class="btn-ghost" style="padding:4px 9px;font-size:11.5px" @click="submitTf(f)">📤 Submit</button>
                  <button v-if="f.status === 'Submitted' && isStaff" class="btn-ghost" style="padding:4px 9px;font-size:11.5px;color:var(--ok,#12a150)" @click="verifyTf(f, 'approve')">✅ Approve</button>
                  <button v-if="f.status === 'Submitted' && isStaff" class="btn-ghost" style="padding:4px 9px;font-size:11.5px;color:var(--danger)" @click="verifyTf(f, 'reject')">❌ Reject</button>
                  <button v-if="f.payload?.name || f.tenant_name" class="btn-ghost" style="padding:4px 9px;font-size:11.5px" @click="printTf(f)">🖨 Print</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- edit modal -->
      <div v-if="tfEdit" class="overlay" @click.self="tfEdit = null">
        <div class="drawer">
          <div class="modal-h"><span class="t">📋 {{ tfEdit.id }} · {{ tfEdit.tenant_name }}</span><button class="close" @click="tfEdit = null">✕</button></div>
          <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
              <div class="form-field"><label>Thana</label><input v-model="tfEdit.thana" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field"><label>District</label><input v-model="tfEdit.district" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div v-for="[k, label] in TF_FIELDS" :key="k" class="form-field" :class="{ 'span-2': k === 'remarks' || k === 'permanent_address' }">
                <label>{{ label }}</label>
                <textarea v-if="k === 'remarks'" v-model="tfEdit.payload[k]" rows="2" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;resize:vertical"></textarea>
                <input v-else v-model="tfEdit.payload[k]" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              </div>
            </div>
            <div style="height:16px"></div>
          </div>
          <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;flex-shrink:0">
            <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="tfEdit = null">Cancel</button>
            <button class="btn-primary" style="padding:9px 16px;font-size:13px" :disabled="tfSaving" @click="saveTf">💾 Save form {{ tfSaving ? '…' : '' }}</button>
          </div>
        </div>
      </div>

      <!-- print settings modal (global, admin only, live preview) -->
      <div v-if="tfCfg" class="overlay" @click.self="tfCfg = false">
        <div class="drawer" style="max-width:1080px">
          <div class="modal-h"><span class="t">⚙️ Print settings · all DMP Thana forms</span><button class="close" @click="tfCfg = false">✕</button></div>
          <div style="padding:16px 20px 0;overflow-y:auto;flex:1;display:flex;gap:20px">
            <!-- left: sliders -->
            <div style="flex:0 0 320px">
              <div v-if="tfCfgErr" style="padding:8px 12px;border-radius:9px;background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.35);margin-bottom:12px;font-weight:600;font-size:12.5px">⚠️ {{ tfCfgErr }}</div>
              <div class="c-sub" style="margin-bottom:10px;font-size:12px">These apply to the print of <b>every</b> DMP Thana form (template is the official DMP file — adjust how the filled values sit on it). Only admins can change these.</div>
              <div v-for="[k, label, mn, mx, st] in PRINT_CFG_FIELDS" :key="k" style="margin-bottom:12px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px">
                  <label style="font-size:12.5px;font-weight:700">{{ label }}</label>
                  <input v-model.number="tfCfgForm[k]" type="number" :step="st" :min="mn" :max="mx" style="width:78px;padding:5px 8px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none;text-align:center" @input="tfCfgDirty = true; applyCfgPreview()">
                </div>
                <input :value="tfCfgForm[k]" type="range" :min="mn" :max="mx" :step="st" :style="cfgSliderStyle" @input="onCfgInput(k, parseFloat($event.target.value))">
              </div>
              <div v-if="tfCfgDefaults" class="c-sub" style="font-size:11.5px;background:var(--bg-alt);border:1px solid var(--border);border-radius:9px;padding:8px 10px;margin-bottom:4px">↺ Default: fs {{ tfCfgDefaults.fs }} · gap {{ tfCfgDefaults.lh }} · margin {{ tfCfgDefaults.mg }}mm — “Reset” restores the original column-wise layout.</div>
              <div style="height:8px"></div>
            </div>
            <!-- right: live preview -->
            <div style="flex:1;min-width:0;display:flex;flex-direction:column">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                <span style="font-size:12.5px;font-weight:700">👁 Live preview — changes apply instantly</span>
                <span v-if="previewLoading" class="c-sub" style="font-size:11.5px">loading…</span>
              </div>
              <div v-if="previewErr" style="padding:10px 14px;border-radius:9px;background:var(--bg-alt);border:1px solid var(--border);font-size:12.5px;color:var(--text-mute)">{{ previewErr }}</div>
              <div v-else style="flex:1;background:#525659;border-radius:10px;padding:14px;overflow:auto;display:flex;justify-content:center;align-items:flex-start">
                <div style="flex:0 0 auto;background:#fff;box-shadow:0 2px 14px rgba(0,0,0,.35);width:640px;height:905px;overflow:hidden;position:relative">
                  <iframe v-if="previewHtml" :srcdoc="previewHtml" @load="onPreviewLoad" class="print-preview-iframe" style="width:852px;height:1205px;border:none;transform:scale(0.75);transform-origin:top left"></iframe>
                  <div v-else style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-mute);font-size:13px">Loading preview…</div>
                </div>
              </div>
            </div>
          </div>
          <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap;flex-shrink:0">
            <button class="btn-ghost" style="padding:9px 14px;font-size:12.5px" @click="defaultPrintCfg">⏮ Default</button>
            <button class="btn-ghost" style="padding:9px 14px;font-size:12.5px" @click="resetPrintCfg">↺ Reset</button>
            <div style="flex:1"></div>
            <button class="btn-ghost" style="padding:9px 14px;font-size:12.5px" @click="tfCfg = false">Cancel</button>
            <button class="btn-primary" style="padding:9px 16px;font-size:12.5px" :disabled="tfCfgSaving || !tfCfgDirty" @click="savePrintCfg">💾 Save {{ tfCfgSaving ? '…' : '' }}</button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.d-cover .badge { background: #ffffff; }
</style>
