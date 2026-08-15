<script setup>
import { computed, ref, watch, reactive } from 'vue'
import { t } from '../lib/i18n'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall, apiUpload, apiBlob, apiBase } from '../api/client'
import { useViewMode, usePager, money, fmtTs } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('build')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))

const KIND_META = { renovation: { ico: '🛠️', label: 'Renovation', cls: 'b-blue' }, construction: { ico: '🏗️', label: 'Construction', cls: 'b-orange' }, repair: { ico: '🔧', label: 'Repair', cls: 'b-green' }, default: { ico: '🏗️', label: 'Project', cls: 'b-gray' } }
const kindMeta = (k) => KIND_META[k] || KIND_META.default
const KIND_OPTS = ['construction', 'renovation', 'repair']
const stCls = (s) => s === 'In_Progress' ? 'b-blue' : (s === 'Completed' ? 'b-green' : (s === 'Pending' ? 'b-orange' : (s === 'On_Hold' ? 'b-gray' : 'b-gray')))
const stLabel = (s) => String(s || '—').replace(/_/g, ' ')
const PHASES = { foundation: 'Foundation', structure: 'Structure', electrical: 'Electrical', plumbing: 'Plumbing', finishing: 'Finishing', handover: 'Handover' }
const EXP_CATS = { material: { ico: '🧱', label: 'Material' }, labour: { ico: '👷', label: 'Labour' }, permit: { ico: '📜', label: 'Permit' }, design: { ico: '📐', label: 'Design' }, other: { ico: '📋', label: 'Other' } }
const MEDIA_KINDS = { photo: { ico: '📷', label: 'Photo' }, video: { ico: '🎬', label: 'Video' }, doc: { ico: '📄', label: 'Document' } }
const daysTo = (d) => { if (!d) return null; const t = new Date(String(d).slice(0, 10) + 'T00:00:00'); if (isNaN(t)) return null; return Math.round((t - Date.now()) / 86400000) }
const fmtDate = (d) => { if (!d) return '—'; const t = new Date(String(d).slice(0, 10) + 'T00:00:00'); return isNaN(t) ? String(d).slice(0, 10) : t.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) }
function nowLocal() { const d = new Date(); const p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}` }
function toTs(v) { return v ? String(v).replace('T', ' ') : '' }
const endNote = (p) => {
  const n = daysTo(p.target_end)
  if (n === null) return ''
  if (p.status === 'Completed') return 'completed'
  if (n < 0) return 'overdue by ' + (-n) + 'd'
  if (n === 0) return 'due today'
  return n + 'd left'
}

// ── data loading (enriched summary from API, bootstrap fallback) ──
const projs = ref([])
const alerts = ref([])
const cfg = ref({ milestone_alert_days: 14, budget_overrun_pct: 10, default_contractor: '' })
const loading = ref(false)
async function load() {
  loading.value = true
  try {
    const r = await apiCall('app-build', { action: 'summary' })
    if (r && r.ok) {
      projs.value = r.projects || []
      alerts.value = r.alerts || []
      cfg.value = r.config || cfg.value
    } else {
      projs.value = data.list('build_projects')
      alerts.value = []
    }
  } catch (e) {
    projs.value = data.list('build_projects')
    alerts.value = []
  } finally { loading.value = false }
}
async function afterMutation() {
  await load()
  try { await data.bootstrap() } catch (e) {}
  refreshDetail()
}
const propName = (pid) => data.list('properties').find(p => p.id === pid)?.name || pid || ''
const propsAll = computed(() => data.list('properties'))

// ── KPIs ──
const kpis = computed(() => {
  const ps = projs.value
  const prog = ps.filter(p => p.status === 'In_Progress').length
  const done = ps.filter(p => p.status === 'Completed').length
  const budget = ps.reduce((s, p) => s + (Number(p.budget_total) || 0), 0)
  const spent = ps.reduce((s, p) => s + (Number(p.spent) || 0), 0)
  const overdue = ps.filter(p => p.status === 'In_Progress' && daysTo(p.target_end) !== null && daysTo(p.target_end) < 0).length
  return [
    { label: 'Projects', ico: '🏗️', value: ps.length, trend: 'build works tracked' },
    { label: 'In progress', ico: '🔵', value: prog, trend: prog ? 'active sites' : 'none active' },
    { label: 'Completed', ico: '✅', value: done, trend: done ? 'finished' : 'none yet' },
    { label: 'Budget', ico: '💰', value: money(budget), trend: 'total planned spend' },
    { label: 'Spent', ico: '🧾', value: money(spent), trend: budget ? Math.round(spent / budget * 100) + '% of budget used' : 'no spend yet' },
    { label: 'Overdue', ico: '⏰', value: overdue, trend: overdue ? 'past target end' : 'on schedule', ok: overdue === 0 },
  ]
})

// ── filters ──
const query = ref('')
const kindFilter = ref('')
const statusFilter = ref('')
const kindOptions = computed(() => [...new Set(projs.value.map(p => p.kind).filter(Boolean))].sort())
const statusOptions = computed(() => [...new Set(projs.value.map(p => p.status).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = projs.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(p => JSON.stringify(p).toLowerCase().includes(q) || (propName(p.prop) || '').toLowerCase().includes(q))
  if (kindFilter.value) out = out.filter(p => (p.kind || '') === kindFilter.value)
  if (statusFilter.value) out = out.filter(p => (p.status || '') === statusFilter.value)
  return [...out].sort((a, b) => String(b.updated_at || b.ts || '').localeCompare(String(a.updated_at || a.ts || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 10)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'build.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── project modal (create / edit) ──
const projModal = ref(false)
const projForm = ref({})
function emptyProj() { return { title: '', kind: 'construction', prop: '', budget_total: '', start_date: '', target_end: '', contractor: cfg.value.default_contractor || '', architect: '', site_address: '', notes: '' } }
function openNew() { projForm.value = emptyProj(); projModal.value = true }
function openEdit(p) { projForm.value = { title: p.title, kind: p.kind, prop: p.prop || '', budget_total: p.budget_total, start_date: p.start_date, target_end: p.target_end, contractor: p.contractor || '', architect: p.architect || '', site_address: p.site_address || '', notes: p.notes || '', _id: p.id }; projModal.value = true }
async function saveProject() {
  const f = projForm.value
  if (!f.title.trim()) { window.__krToast?.('❌ Project title is required'); return }
  const editing = !!f._id
  const body = { action: editing ? 'save' : 'create', title: f.title.trim(), kind: f.kind, budget_total: parseInt(f.budget_total) || 0, start_date: f.start_date, target_end: f.target_end, contractor: f.contractor.trim(), architect: f.architect.trim(), site_address: f.site_address.trim(), notes: f.notes.trim() }
  if (f.prop) body.prop = f.prop
  if (editing) body.id = f._id
  const r = await apiCall('app-build', body)
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  projModal.value = false
  window.__krToast?.('✅ ' + (editing ? f._id : r.id) + ' saved', 'ok')
  await afterMutation()
  if (editing && sel.value) { const fresh = projs.value.find(x => x.id === sel.value.id); if (fresh) sel.value = fresh }
}

// ── project status / delete / report ──
const PROJ_NEXT = { Planning: ['In_Progress'], In_Progress: ['On_Hold', 'Completed', 'Cancelled'], On_Hold: ['In_Progress', 'Completed', 'Cancelled'], Completed: [], Cancelled: [] }
const NEXT_BTN = { In_Progress: { ico: '▶', label: 'Start work' }, On_Hold: { ico: '⏸', label: 'Hold' }, Completed: { ico: '✅', label: 'Complete' }, Cancelled: { ico: '✖', label: 'Cancel' }, }
const nextStatuses = (p) => (PROJ_NEXT[p.status] || []).map(s => ({ to: s, ...NEXT_BTN[s] }))
async function setStatus(p, to) {
  if (to === 'Completed' && !confirm('Mark ' + p.id + ' as Completed?')) return
  if (to === 'Cancelled' && !confirm('Cancel project ' + p.id + '?')) return
  const r = await apiCall('app-build', { action: 'status', id: p.id, status: to })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('✅ ' + p.id + ' → ' + stLabel(to), 'ok')
  await afterMutation()
}
async function askDeleteProject(p) {
  if (!confirm('Delete project ' + p.id + ' (' + p.title + ')?\nThis removes its milestones, expenses and media too.')) return
  const r = await apiCall('app-build', { action: 'delete', id: p.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  closeDetail()
  window.__krToast?.('🗑 ' + p.id + ' deleted')
  await load()
}
async function openReport(p) {
  const url = await apiBlob('app-build?action=report&id=' + p.id)
  if (url) window.open(url, '_blank')
  else window.__krToast?.('❌ Could not generate report')
}

// ── drawer + detail ──
const sel = ref(null)
const detail = ref({ milestones: [], expenses: [], media: [] })
const blobMap = reactive({})
function openDetail(p) {
  if (!p) return
  sel.value = p
  refreshDetail()
}
function closeDetail() { sel.value = null; detail.value = { milestones: [], expenses: [], media: [] } }
watch(() => route.query.open, (id) => {
  if (id) { const p = projs.value.find(x => x.id === id); if (p) openDetail(p) }
}, { immediate: true })
async function refreshDetail() {
  if (!sel.value) return
  const r = await apiCall('app-build', { action: 'get', id: sel.value.id })
  if (r && r.ok) {
    detail.value = { milestones: r.milestones || [], expenses: r.expenses || [], media: r.media || [] }
    if (r.project) sel.value = r.project
    loadMediaBlobs()
  }
}
const milestones = computed(() => [...(detail.value.milestones || [])].sort((a, b) => String(a.target_date || '').localeCompare(String(b.target_date || ''))))
const expenses = computed(() => [...(detail.value.expenses || [])].sort((a, b) => String(b.spent_on || '').localeCompare(String(a.spent_on || ''))))
const mediaList = computed(() => detail.value.media || [])
const expTotal = computed(() => expenses.value.reduce((s, e) => s + (Number(e.amount) || 0), 0))
const expPaid = computed(() => expenses.value.filter(e => e.paid).reduce((s, e) => s + (Number(e.amount) || 0), 0))
const projAlerts = computed(() => (alerts.value || []).filter(a => a.ref === sel.value?.id))
async function loadMediaBlobs() {
  for (const md of mediaList.value) {
    if (blobMap[md.id] !== undefined || md.kind === 'doc') continue
    blobMap[md.id] = null
    blobMap[md.id] = await apiBlob('app-build?action=media-view&id=' + md.id)
  }
}
async function openMedia(md) {
  let url = blobMap[md.id]
  if (!url && md.kind !== 'doc') { url = await apiBlob('app-build?action=media-view&id=' + md.id); if (url) blobMap[md.id] = url }
  if (!url) { window.__krToast?.('❌ Could not load file'); return }
  window.open(url, '_blank')
}
function propRef(p) { return p.prop ? { path: '/properties', query: { open: p.prop } } : null }
function detailFields(row) {
  const skip = new Set(['id', 'title', 'kind', 'status', 'budget_total', 'notes', 'owner_email', 'ts', 'updated_at', 'prop', 'spent', 'milestones_total', 'milestones_done', 'milestones_overdue', 'milestones_due_soon', 'progress', 'budget_used_pct', 'budget_variance', 'days_left', 'over_budget', 'media_count', 'property_name'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}

// ── milestone modal ──
const msModal = ref(false)
const msForm = ref({})
function emptyMs() { return { title: '', phase: 'structure', target_date: '', cost: '', paid: 0, notes: '' } }
function openMsNew() { msForm.value = emptyMs(); msModal.value = true }
function openMsEdit(m) { msForm.value = { title: m.title, phase: m.phase, target_date: m.target_date, cost: m.cost, paid: m.paid ? 1 : 0, notes: m.notes || '', _id: m.id }; msModal.value = true }
async function saveMilestone() {
  const f = msForm.value
  if (!f.title.trim()) { window.__krToast?.('❌ Milestone title is required'); return }
  const editing = !!f._id
  const body = { action: editing ? 'milestone-save' : 'milestone-create', title: f.title.trim(), phase: f.phase, target_date: f.target_date, cost: parseInt(f.cost) || 0, paid: f.paid ? 1 : 0, notes: f.notes.trim() }
  if (editing) body.id = f._id; else body.project = sel.value.id
  const r = await apiCall('app-build', body)
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  msModal.value = false
  window.__krToast?.('✅ ' + (r.id || 'Milestone') + ' saved', 'ok')
  await afterMutation()
}
const MS_NEXT = { Pending: ['In_Progress', 'Skipped'], In_Progress: ['Completed'], Completed: [], Skipped: [] }
const MS_BTN = { In_Progress: { ico: '▶', label: 'Start' }, Skipped: { ico: '⏭', label: 'Skip' }, Completed: { ico: '✅', label: 'Done' } }
async function msStatus(m, to) {
  const r = await apiCall('app-build', { action: 'milestone-status', id: m.id, status: to })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('✅ ' + m.id + ' → ' + stLabel(to), 'ok')
  await afterMutation()
}
async function askDeleteMilestone(m) {
  if (!confirm('Delete milestone ' + m.id + ' (' + m.title + ')?')) return
  const r = await apiCall('app-build', { action: 'milestone-delete', id: m.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('🗑 ' + m.id + ' deleted')
  await afterMutation()
}

// ── expense modal ──
const expModal = ref(false)
const expForm = ref({})
function emptyExp() { return { label: '', amount: '', category: 'material', spent_on: '', paid: 0, notes: '' } }
function openExpNew() { expForm.value = emptyExp(); expModal.value = true }
function openExpEdit(x) { expForm.value = { label: x.label, amount: x.amount, category: x.category, spent_on: x.spent_on, paid: x.paid ? 1 : 0, notes: x.notes || '', _id: x.id }; expModal.value = true }
async function saveExpense() {
  const f = expForm.value
  if (!f.label.trim() || !(parseInt(f.amount) > 0)) { window.__krToast?.('❌ Label and a positive amount are required'); return }
  const editing = !!f._id
  const body = { action: editing ? 'expense-save' : 'expense-add', label: f.label.trim(), amount: parseInt(f.amount) || 0, category: f.category, spent_on: f.spent_on, paid: f.paid ? 1 : 0, notes: f.notes.trim() }
  if (editing) body.id = f._id; else body.project = sel.value.id
  const r = await apiCall('app-build', body)
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  expModal.value = false
  window.__krToast?.('✅ ' + (r.id || 'Expense') + ' saved', 'ok')
  await afterMutation()
}
async function togglePaid(x) {
  const r = await apiCall('app-build', { action: 'expense-save', id: x.id, label: x.label, amount: x.amount, category: x.category, spent_on: x.spent_on, paid: x.paid ? 0 : 1, notes: x.notes || '' })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('✅ ' + x.id + ' ' + (x.paid ? 'marked unpaid' : 'marked paid'), 'ok')
  await afterMutation()
}
async function askDeleteExpense(x) {
  if (!confirm('Delete expense ' + x.id + ' (' + x.label + ')?')) return
  const r = await apiCall('app-build', { action: 'expense-delete', id: x.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('🗑 ' + x.id + ' deleted')
  await afterMutation()
}

// ── media upload ──
const uploadForm = ref({ kind: 'photo', name: '', captured_at: nowLocal(), geo: '' })
const uploadFile = ref(null)
const fileInput = ref(null)
function pickFile(e) { uploadFile.value = e.target.files?.[0] || null; if (uploadFile.value && !uploadForm.value.name) uploadForm.value.name = uploadFile.value.name }
async function uploadMedia() {
  if (!uploadFile.value) { window.__krToast?.('❌ Choose a file first'); return }
  const fd = new FormData()
  fd.append('project', sel.value.id)
  fd.append('kind', uploadForm.value.kind)
  fd.append('name', uploadForm.value.name.trim() || uploadFile.value.name)
  fd.append('file', uploadFile.value)
  fd.append('captured_at', toTs(uploadForm.value.captured_at) || '')
  if (uploadForm.value.geo.trim()) fd.append('geo', uploadForm.value.geo.trim())
  const r = await apiUpload('app-build?action=media-upload', fd)
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Upload failed')); return }
  uploadFile.value = null
  uploadForm.value = { kind: 'photo', name: '', captured_at: nowLocal(), geo: '' }
  window.__krToast?.('✅ ' + (r.id || 'Media') + ' uploaded', 'ok')
  await afterMutation()
}
async function askDeleteMedia(md) {
  if (!confirm('Delete ' + md.id + ' (' + md.name + ')?')) return
  const r = await apiCall('app-build', { action: 'media-delete', id: md.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  delete blobMap[md.id]
  window.__krToast?.('🗑 ' + md.id + ' deleted')
  await afterMutation()
}

// ── config ──
const cfgModal = ref(false)
const cfgForm = ref({})
function openCfg() { cfgForm.value = { milestone_alert_days: cfg.value.milestone_alert_days, budget_overrun_pct: cfg.value.budget_overrun_pct, default_contractor: cfg.value.default_contractor || '' }; cfgModal.value = true }
async function saveCfg() {
  const r = await apiCall('app-build', { action: 'config-save', milestone_alert_days: parseInt(cfgForm.value.milestone_alert_days) || 14, budget_overrun_pct: parseInt(cfgForm.value.budget_overrun_pct) || 10, default_contractor: cfgForm.value.default_contractor.trim() })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  cfgModal.value = false
  window.__krToast?.('✅ Settings saved', 'ok')
  await load()
}

load()
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('🏗️ Build Watch') }}</h1>
        <div class="sub">{{ projs.length }} projects · {{ kpis[3]?.value || 0 }} budget · {{ kpis[4]?.value || 0 }} spent</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" :placeholder="t('Search title, contractor, site…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:210px">
        <select v-model="kindFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All kinds') }}</option>
          <option v-for="k in kindOptions" :key="k" :value="k">{{ kindMeta(k).label }}</option>
        </select>
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All statuses') }}</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ stLabel(s) }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" :title="t('Download CSV')">⬇ CSV</button>
        <button v-if="canManage" @click="openCfg" class="btn-ghost" :title="t('Build Watch settings')">⚙ Settings</button>
      </CompactFilters>
        <button v-if="canManage" @click="openNew" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ New project</button>
      </div>
    </div>

    <!-- alerts -->
    <div v-if="alerts.length" style="display:flex;flex-direction:column;gap:7px;margin-bottom:14px">
      <div v-for="(a, i) in alerts" :key="i" @click="openDetail(projs.find(p => p.id === a.ref))" style="cursor:pointer;display:flex;gap:10px;align-items:center;background:var(--card);border:1px solid var(--border);border-left:4px solid #e67e22;border-radius:10px;padding:10px 14px">
        <span style="font-size:16px">{{ a.severity === 'warning' ? '⚠️' : 'ℹ️' }}</span>
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:800">{{ a.title }}</div>
          <div class="c-sub" style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ a.body }}</div>
        </div>
        <span class="badge" :class="stCls(projs.find(p => p.id === a.ref)?.status || '')">{{ a.ref }}</span>
      </div>
    </div>

    <div class="stats">
      <div v-for="k in kpis" :key="k.label" class="stat">
        <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
        <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
        <div class="s-trend">{{ k.trend }}</div>
      </div>
    </div>

    <!-- GRID -->
    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px">
      <div v-for="p in paged" :key="p.id" class="panel" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column;border-radius:14px" @click="openDetail(p)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">{{ kindMeta(p.kind).ico }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="kindMeta(p.kind).cls" style="background:#ffffff">{{ kindMeta(p.kind).label }}</span>
            <span v-if="p.over_budget" class="badge b-red" style="background:#ffffff">⚠ over budget</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ p.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ p.title }}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="stCls(p.status)">{{ stLabel(p.status) }}</span>
            <span v-if="p.prop" class="badge b-blue">{{ p.prop ? propName(p.prop) : '' }}</span>
            <span v-if="p.media_count" class="badge b-gray">📷 {{ p.media_count }}</span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge b-gray">💰 {{ money(p.budget_total) }}</span>
            <span class="badge b-orange">🧾 {{ money(p.spent) }}</span>
            <span v-if="p.contractor" class="badge b-purple">🧰 {{ p.contractor }}</span>
          </div>
          <div>
            <div style="display:flex;justify-content:space-between;font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">
              <span>Progress · {{ p.progress || 0 }}%</span>
              <span>{{ p.milestones_done || 0 }}/{{ p.milestones_total || 0 }} milestones</span>
            </div>
            <div style="height:7px;background:var(--bg-alt);border-radius:99px;overflow:hidden">
              <div :style="'width:' + Math.min(100, p.progress || 0) + '%;height:100%;background:linear-gradient(90deg,#16a085,#27ae60);border-radius:99px'"></div>
            </div>
          </div>
          <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
            <span>📅 {{ fmtDate(p.target_end) }}</span>
            <template v-if="endNote(p)"><span :style="endNote(p).includes('overdue') ? 'color:var(--danger)' : ''">{{ endNote(p) }}</span></template>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>{{ t('Project') }}</th><th>{{ t('Kind') }}</th><th>{{ t('Property') }}</th><th>{{ t('Budget') }}</th><th>{{ t('Spent') }}</th><th>{{ t('Progress') }}</th><th>{{ t('Contractor') }}</th><th>{{ t('Target end') }}</th><th>{{ t('Status') }}</th></tr></thead>
          <tbody>
            <tr v-for="p in paged" :key="p.id" style="cursor:pointer" @click="openDetail(p)">
              <td style="font-weight:700;white-space:nowrap">{{ p.id }}</td>
              <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ p.title }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="kindMeta(p.kind).cls">{{ kindMeta(p.kind).label }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ p.prop ? propName(p.prop) : '—' }}</td>
              <td style="white-space:nowrap;font-weight:700">{{ money(p.budget_total) }}</td>
              <td style="white-space:nowrap" :style="p.over_budget ? 'color:var(--danger);font-weight:700' : ''">{{ money(p.spent) }}</td>
              <td style="white-space:nowrap">
                <span class="badge" :class="(p.progress || 0) >= 100 ? 'b-green' : 'b-gray'">{{ p.progress || 0 }}%</span>
              </td>
              <td style="white-space:nowrap" class="c-sub">{{ p.contractor || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ fmtDate(p.target_end) }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="stCls(p.status)">{{ stLabel(p.status) }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No projects found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- project modal (create / edit) -->
    <template v-if="projModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="projModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(520px,94vw);max-height:92vh;overflow-y:auto;background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28)">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--card);z-index:1">
          <div style="font-weight:800;font-size:15.5px">{{ projForm._id ? '✏️ Edit project' : '🏗️ New build project' }}</div>
          <button @click="projModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 22px;display:flex;flex-direction:column;gap:12px">
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Title *</div>
            <input v-model="projForm.title" placeholder="e.g. Roof renovation of 12, Road 7" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Kind') }}</div>
              <select v-model="projForm.kind" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option v-for="k in KIND_OPTS" :key="k" :value="k">{{ kindMeta(k).ico }} {{ kindMeta(k).label }}</option>
              </select>
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Property') }}</div>
              <select v-model="projForm.prop" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option value="">— none —</option>
                <option v-for="pr in propsAll" :key="pr.id" :value="pr.id">{{ pr.name }}</option>
              </select>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Budget (৳)</div>
              <input v-model="projForm.budget_total" type="number" min="0" placeholder="0" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Contractor') }}</div>
              <input v-model="projForm.contractor" :placeholder="t('Firm name')" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Start date') }}</div>
              <input v-model="projForm.start_date" type="date" style="width:100%;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Target end') }}</div>
              <input v-model="projForm.target_end" type="date" style="width:100%;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Architect') }}</div>
            <input v-model="projForm.architect" :placeholder="t('Architect / designer name')" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Site address') }}</div>
            <input v-model="projForm.site_address" placeholder="📍 Site location" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Notes') }}</div>
            <textarea v-model="projForm.notes" rows="3" placeholder="Scope, constraints, anything else…" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none;resize:vertical"></textarea>
          </div>
          <button @click="saveProject" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">{{ projForm._id ? '💾 Save changes' : '＋ Create project' }}</button>
        </div>
      </div>
    </template>

    <!-- milestone modal -->
    <template v-if="msModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="msModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(480px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28)">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">{{ msForm._id ? '✏️ Edit milestone' : '🏁 New milestone' }}</div>
          <button @click="msModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 22px;display:flex;flex-direction:column;gap:12px">
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Title *</div>
            <input v-model="msForm.title" placeholder="e.g. Roof slab casting" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Phase') }}</div>
              <select v-model="msForm.phase" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option v-for="(l, k) in PHASES" :key="k" :value="k">{{ l }}</option>
              </select>
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Target date') }}</div>
              <input v-model="msForm.target_date" type="date" style="width:100%;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Cost (৳)</div>
              <input v-model="msForm.cost" type="number" min="0" placeholder="0" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Paid') }}</div>
              <select v-model="msForm.paid" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option :value="0">{{ t('Unpaid') }}</option>
                <option :value="1">{{ t('Paid') }}</option>
              </select>
            </div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Notes') }}</div>
            <textarea v-model="msForm.notes" rows="2" :placeholder="t('Optional detail')" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none;resize:vertical"></textarea>
          </div>
          <button @click="saveMilestone" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">{{ msForm._id ? '💾 Save milestone' : '🏁 Add milestone' }}</button>
        </div>
      </div>
    </template>

    <!-- expense modal -->
    <template v-if="expModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="expModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(460px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28)">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">{{ expForm._id ? '✏️ Edit expense' : '🧾 New expense' }}</div>
          <button @click="expModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 22px;display:flex;flex-direction:column;gap:12px">
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Label *</div>
            <input v-model="expForm.label" placeholder="e.g. Cement 50 bags" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Amount (৳) *</div>
              <input v-model="expForm.amount" type="number" min="0" placeholder="0" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Category') }}</div>
              <select v-model="expForm.category" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option v-for="(m, k) in EXP_CATS" :key="k" :value="k">{{ m.ico }} {{ m.label }}</option>
              </select>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Spent on') }}</div>
              <input v-model="expForm.spent_on" type="date" style="width:100%;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Paid') }}</div>
              <select v-model="expForm.paid" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option :value="0">{{ t('Unpaid') }}</option>
                <option :value="1">{{ t('Paid') }}</option>
              </select>
            </div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Notes') }}</div>
            <textarea v-model="expForm.notes" rows="2" :placeholder="t('Optional')" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none;resize:vertical"></textarea>
          </div>
          <button @click="saveExpense" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">{{ expForm._id ? '💾 Save expense' : '🧾 Add expense' }}</button>
        </div>
      </div>
    </template>

    <!-- config modal -->
    <template v-if="cfgModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="cfgModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(440px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28)">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">⚙ Build Watch settings</div>
          <button @click="cfgModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 22px;display:flex;flex-direction:column;gap:12px">
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Milestone alert days') }}</div>
            <input v-model="cfgForm.milestone_alert_days" type="number" min="1" max="120" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            <div class="c-sub" style="font-size:11.5px;margin-top:4px">{{ t('Warn when a milestone is due within this many days.') }}</div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Budget overrun %') }}</div>
            <input v-model="cfgForm.budget_overrun_pct" type="number" min="1" max="50" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            <div class="c-sub" style="font-size:11.5px;margin-top:4px">{{ t('Flag a project when spend exceeds budget by this percentage.') }}</div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Default contractor') }}</div>
            <input v-model="cfgForm.default_contractor" :placeholder="t('Firm name')" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            <div class="c-sub" style="font-size:11.5px;margin-top:4px">{{ t('Prefilled when creating a new project.') }}</div>
          </div>
          <button @click="saveCfg" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">💾 Save settings</button>
        </div>
      </div>
    </template>

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(700px,96vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:44px">{{ kindMeta(sel.kind).ico }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ kindMeta(sel.kind).label }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:19px;font-weight:800;letter-spacing:-.3px;line-height:1.35">{{ sel.title }}</h2>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin:10px 0">
            <span class="badge" :class="stCls(sel.status)">{{ stLabel(sel.status) }}</span>
            <button v-if="propRef(sel)" class="btn-ghost" style="padding:4px 10px;font-size:12px" @click="go(propRef(sel).path, propRef(sel).query)">↗ {{ sel.prop ? propName(sel.prop) : '' }}</button>
          </div>

          <!-- status flow + actions -->
          <div v-if="canManage" style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:10px">
            <button v-for="ns in nextStatuses(sel)" :key="ns.to" @click="setStatus(sel, ns.to)" style="padding:7px 12px;border:none;border-radius:9px;background:var(--primary);color:#fff;font-size:12px;font-weight:800;cursor:pointer">{{ ns.ico }} {{ ns.label }}</button>
            <button @click="openEdit(sel)" style="padding:7px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:12px;font-weight:800;cursor:pointer">✏️ Edit</button>
            <button @click="openReport(sel)" style="padding:7px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:12px;font-weight:800;cursor:pointer">🖨 Report</button>
            <button v-if="canManage" @click="askDeleteProject(sel)" style="padding:7px 12px;border:1px solid #fecaca;border-radius:9px;background:#fef2f2;color:var(--danger);font-size:12px;font-weight:800;cursor:pointer">🗑 Delete</button>
          </div>
          <div v-else style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:10px">
            <button @click="openReport(sel)" style="padding:7px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:12px;font-weight:800;cursor:pointer">🖨 Report</button>
          </div>

          <!-- stat tiles -->
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;margin-bottom:12px">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:9px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Budget') }}</div>
              <div style="font-weight:800;font-size:14.5px;margin-top:2px">{{ money(sel.budget_total) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:9px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Spent') }}</div>
              <div style="font-weight:800;font-size:14.5px;margin-top:2px" :style="sel.over_budget ? 'color:var(--danger)' : ''">{{ money(sel.spent) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:9px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Remaining') }}</div>
              <div style="font-weight:800;font-size:14.5px;margin-top:2px" :style="(sel.budget_variance || 0) < 0 ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(sel.budget_variance) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:9px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Milestones') }}</div>
              <div style="font-weight:800;font-size:14.5px;margin-top:2px">{{ sel.milestones_done || 0 }}/{{ sel.milestones_total || 0 }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:9px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Media') }}</div>
              <div style="font-weight:800;font-size:14.5px;margin-top:2px">{{ sel.media_count || 0 }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:9px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Days left') }}</div>
              <div style="font-weight:800;font-size:14.5px;margin-top:2px" :style="sel.days_left !== null && sel.days_left < 0 ? 'color:var(--danger)' : ''">{{ sel.days_left !== null && sel.days_left !== undefined ? sel.days_left + 'd' : '—' }}</div>
            </div>
          </div>

          <!-- progress bars -->
          <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
            <div>
              <div style="display:flex;justify-content:space-between;font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px"><span>{{ t('Milestone progress') }}</span><span>{{ sel.progress || 0 }}%</span></div>
              <div style="height:8px;background:var(--bg-alt);border-radius:99px;overflow:hidden"><div :style="'width:' + Math.min(100, sel.progress || 0) + '%;height:100%;background:linear-gradient(90deg,#16a085,#27ae60);border-radius:99px'"></div></div>
            </div>
            <div>
              <div style="display:flex;justify-content:space-between;font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px"><span>{{ t('Budget used') }}</span><span>{{ sel.budget_used_pct || 0 }}%</span></div>
              <div style="height:8px;background:var(--bg-alt);border-radius:99px;overflow:hidden"><div :style="'width:' + Math.min(100, sel.budget_used_pct || 0) + '%;height:100%;background:' + (sel.over_budget ? '#e74c3c' : 'linear-gradient(90deg,#2980b9,#2f80ed)') + ';border-radius:99px'"></div></div>
            </div>
          </div>

          <!-- per-project alerts -->
          <div v-if="projAlerts.length" style="display:flex;flex-direction:column;gap:6px;margin-bottom:12px">
            <div v-for="(a, i) in projAlerts" :key="i" style="display:flex;gap:8px;align-items:center;background:#fef9ec;border:1px solid #f5e6b8;border-radius:9px;padding:8px 12px;font-size:12.5px">
              <span>{{ a.severity === 'warning' ? '⚠️' : 'ℹ️' }}</span>
              <b>{{ a.title }}</b><span class="c-sub">— {{ a.body.replace(sel.id + ' — ', '') }}</span>
            </div>
          </div>

          <!-- info grid -->
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px 18px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Start') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtDate(sel.start_date) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Target end') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtDate(sel.target_end) }} <template v-if="endNote(sel)"><span :style="endNote(sel).includes('overdue') ? 'color:var(--danger)' : ''">({{ endNote(sel) }})</span></template></div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Contractor') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.contractor || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Architect') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.architect || '—' }}</div>
            </div>
          </div>
          <div v-if="sel.site_address" class="c-sub" style="font-size:12.5px;margin-top:10px">📍 {{ sel.site_address }}</div>
          <div v-if="sel.notes" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;font-size:13px;line-height:1.65">{{ sel.notes }}</div>

          <!-- milestones -->
          <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:14px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:9px">
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">🏁 Milestones · {{ milestones.length }} · {{ sel.milestones_done || 0 }} done</div>
              <button v-if="canManage" @click="openMsNew" style="padding:5px 10px;border:none;border-radius:8px;background:var(--primary);color:#fff;font-size:11.5px;font-weight:800;cursor:pointer">＋ Add milestone</button>
            </div>
            <div v-if="!milestones.length" class="c-sub" style="font-size:12.5px">{{ t('No milestones yet — add one to start tracking progress.') }}</div>
            <div v-else class="drawer-tbl-wrap" style="overflow:auto;max-height:44vh">
              <table class="kr" style="width:100%">
                <thead><tr><th>{{ t('Milestone') }}</th><th>{{ t('Phase') }}</th><th>{{ t('Target') }}</th><th>{{ t('Cost') }}</th><th>{{ t('Status') }}</th><th v-if="canManage">{{ t('Actions') }}</th></tr></thead>
                <tbody>
                  <tr v-for="m in milestones" :key="m.id">
                    <td style="white-space:nowrap"><b>{{ m.title }}</b><div v-if="m.notes" class="c-sub" style="font-size:11px;font-weight:500;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ m.notes }}</div></td>
                    <td style="white-space:nowrap" class="c-sub">{{ PHASES[m.phase] || m.phase || '—' }}</td>
                    <td style="white-space:nowrap" class="c-sub">{{ fmtDate(m.target_date) }}</td>
                    <td style="white-space:nowrap">{{ money(m.cost) }} <span v-if="m.paid" class="badge b-green" style="font-size:9.5px;padding:1px 6px">paid</span></td>
                    <td style="white-space:nowrap"><span class="badge" :class="stCls(m.status)">{{ stLabel(m.status) }}</span></td>
                    <td v-if="canManage" style="white-space:nowrap">
                      <div style="display:flex;gap:4px;align-items:center">
                        <button v-for="ns in (MS_NEXT[m.status] || [])" :key="ns" @click="msStatus(m, ns)" :title="MS_BTN[ns].label" style="padding:3px 8px;border:none;border-radius:7px;background:var(--primary);color:#fff;font-size:10.5px;font-weight:800;cursor:pointer">{{ MS_BTN[ns].ico }} {{ MS_BTN[ns].label }}</button>
                        <button @click="openMsEdit(m)" :title="t('Edit')" style="padding:3px 7px;border:1px solid var(--border);border-radius:7px;background:var(--bg-alt);color:var(--text);font-size:10.5px;font-weight:800;cursor:pointer">✏️</button>
                        <button @click="askDeleteMilestone(m)" :title="t('Delete')" style="padding:3px 7px;border:1px solid #fecaca;border-radius:7px;background:#fef2f2;color:var(--danger);font-size:10.5px;font-weight:800;cursor:pointer">🗑</button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- expenses -->
          <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:14px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:9px">
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">🧾 Expenses · {{ expenses.length }} · {{ money(expTotal) }} total · {{ money(expPaid) }} paid</div>
              <button v-if="canManage" @click="openExpNew" style="padding:5px 10px;border:none;border-radius:8px;background:var(--primary);color:#fff;font-size:11.5px;font-weight:800;cursor:pointer">＋ Add expense</button>
            </div>
            <div v-if="!expenses.length" class="c-sub" style="font-size:12.5px">{{ t('No expenses recorded.') }}</div>
            <div v-else class="drawer-tbl-wrap" style="overflow:auto;max-height:40vh">
              <table class="kr" style="width:100%">
                <thead><tr><th>{{ t('Label') }}</th><th>{{ t('Category') }}</th><th>{{ t('Spent on') }}</th><th>{{ t('Amount') }}</th><th>{{ t('Paid') }}</th><th v-if="canManage">{{ t('Actions') }}</th></tr></thead>
                <tbody>
                  <tr v-for="e in expenses" :key="e.id">
                    <td style="white-space:nowrap"><b>{{ e.label }}</b><div v-if="e.notes" class="c-sub" style="font-size:11px;font-weight:500;max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ e.notes }}</div></td>
                    <td style="white-space:nowrap"><span class="badge" :class="e.category === 'labour' ? 'b-orange' : (e.category === 'material' ? 'b-blue' : 'b-gray')">{{ EXP_CATS[e.category]?.ico || '' }} {{ e.category || '—' }}</span></td>
                    <td style="white-space:nowrap" class="c-sub">{{ fmtDate(e.spent_on) }}</td>
                    <td style="white-space:nowrap;font-weight:700">{{ money(e.amount) }}</td>
                    <td style="white-space:nowrap">
                      <button v-if="canManage" @click="togglePaid(e)" style="padding:3px 9px;border:none;border-radius:7px;font-size:10.5px;font-weight:800;cursor:pointer;background:var(--bg-alt);color:var(--text)"><span :class="e.paid ? 'b-green' : 'b-red'">{{ e.paid ? '✅ Paid' : '⏳ Unpaid' }}</span></button>
                      <span v-else class="badge" :class="e.paid ? 'b-green' : 'b-red'">{{ e.paid ? 'Paid' : 'Unpaid' }}</span>
                    </td>
                    <td v-if="canManage" style="white-space:nowrap">
                      <div style="display:flex;gap:4px;align-items:center">
                        <button @click="openExpEdit(e)" :title="t('Edit')" style="padding:3px 7px;border:1px solid var(--border);border-radius:7px;background:var(--bg-alt);color:var(--text);font-size:10.5px;font-weight:800;cursor:pointer">✏️</button>
                        <button @click="askDeleteExpense(e)" :title="t('Delete')" style="padding:3px 7px;border:1px solid #fecaca;border-radius:7px;background:#fef2f2;color:var(--danger);font-size:10.5px;font-weight:800;cursor:pointer">🗑</button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- media -->
          <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:9px">📷 Site media · {{ mediaList.length }}</div>
            <div v-if="canManage" style="display:flex;flex-direction:column;gap:8px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px;margin-bottom:12px">
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <select v-model="uploadForm.kind" style="padding:8px 10px;border:1px solid var(--border);border-radius:9px;background:var(--card);color:var(--text);font-size:12.5px;font-family:inherit;outline:none">
                  <option v-for="(m, k) in MEDIA_KINDS" :key="k" :value="k">{{ m.ico }} {{ m.label }}</option>
                </select>
                <input ref="fileInput" type="file" accept="image/*,.mp4,.pdf,.doc,.docx" style="display:none" @change="pickFile">
                <button @click="fileInput?.click()" style="padding:8px 13px;border:1px solid var(--border);border-radius:9px;background:var(--card);color:var(--text);font-size:12.5px;font-weight:800;cursor:pointer">📂 {{ uploadFile ? uploadFile.name.slice(0, 28) + (uploadFile.name.length > 28 ? '…' : '') : 'Choose file' }}</button>
                <input v-model="uploadForm.name" :placeholder="t('Caption (defaults to file name)')" style="flex:1;min-width:150px;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <input v-model="uploadForm.captured_at" type="datetime-local" style="padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
                <input v-model="uploadForm.geo" placeholder="📍 Geo tag (optional)" style="flex:1;min-width:140px;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
                <button @click="uploadMedia" style="padding:8px 16px;border:none;border-radius:9px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">⬆ Upload</button>
              </div>
            </div>
            <div v-if="!mediaList.length" class="c-sub" style="font-size:12.5px">{{ t('No site media yet — upload progress photos or documents.') }}</div>
            <div v-else style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px">
              <div v-for="md in mediaList" :key="md.id" style="border:1px solid var(--border);border-radius:11px;overflow:hidden;background:var(--card)">
                <div style="height:88px;position:relative;background:var(--bg-alt);cursor:pointer;display:flex;align-items:center;justify-content:center;overflow:hidden" @click="openMedia(md)">
                  <img v-if="md.kind === 'photo' && blobMap[md.id]" :src="blobMap[md.id]" style="width:100%;height:100%;object-fit:cover" alt="">
                  <span v-else style="font-size:26px">{{ MEDIA_KINDS[md.kind]?.ico || '📄' }}</span>
                  <span v-if="md.kind === 'video'" style="position:absolute;font-size:18px">🎬</span>
                </div>
                <div style="padding:6px 8px;display:flex;align-items:center;justify-content:space-between;gap:4px">
                  <span style="font-size:10.5px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" :title="md.name">{{ md.name }}</span>
                  <button v-if="canManage" @click="askDeleteMedia(md)" :title="t('Delete')" style="padding:2px 6px;border:none;border-radius:6px;background:#fef2f2;color:var(--danger);font-size:10px;font-weight:800;cursor:pointer;flex-shrink:0">🗑</button>
                </div>
                <div class="c-sub" style="padding:0 8px 6px;font-size:9.5px">{{ md.id }}<template v-if="md.geo"> · 📍{{ md.geo }}</template></div>
              </div>
            </div>
          </div>

          <div v-for="[k, v] in detailFields(sel)" :key="k" style="font-size:13px;margin-top:9px">
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
