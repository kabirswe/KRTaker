<script setup>
// Safety/Service/Maintenance Inspections + Scheduler (V2.9.0)
// Inside Safety & Security — one-off inspections with pass/fail checklists,
// recurring schedules that auto-materialize due inspections, completion sign-off.
import { computed, ref, onMounted } from 'vue'
import { t } from '../lib/i18n'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { badge, fmtTs } from '../lib/ui'
import RichEditor from '../components/RichEditor.vue'
import CompactFilters from '../components/CompactFilters.vue'
import ScrollTabs from '../components/ScrollTabs.vue'

const data = useDataStore()
const auth = useAuthStore()

const canManage = computed(() => ['superadmin', 'owner', 'manager', 'svc_mgr'].includes(auth.user?.role || ''))
const propsAll = computed(() => data.list('properties'))
const propName = (pid) => propsAll.value.find(p => p.id === pid)?.name || (pid ? '#' + pid : '—')
const propOptions = computed(() => propsAll.value.map(p => ({ id: p.id, name: p.name })))

const TYPES = [
  { v: 'safety', l: '🧯 Safety', cls: 'b-orange' },
  { v: 'service', l: '🛎️ Service', cls: 'b-blue' },
  { v: 'maintenance', l: '🔧 Maintenance', cls: 'b-purple' },
]
const typeLabel = (ty) => t(TYPES.find(x => x.v === ty)?.l || ty || '—')
const typeCls = (t) => TYPES.find(x => x.v === t)?.cls || 'b-gray'
const STATUS = [
  { v: 'scheduled', l: t('Scheduled'), cls: 'b-blue' },
  { v: 'in_progress', l: t('In progress'), cls: 'b-orange' },
  { v: 'passed', l: '✅ Passed', cls: 'b-green' },
  { v: 'failed', l: '❌ Failed', cls: 'b-red' },
]
const statusMeta = (s) => STATUS.find(x => x.v === s) || { v: s, l: s, cls: 'b-gray' }
const fmtDate = (d) => { if (!d) return '—'; const t = new Date(String(d).slice(0, 10) + 'T00:00:00'); return isNaN(t) ? String(d).slice(0, 10) : t.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) }

// ── data ──
const loading = ref(false)
const err = ref('')
const toast = ref('')
const summary = ref(null)
const list = ref([])
const scheds = ref([])
const fType = ref('')
const fStatus = ref('')
const q = ref('')
const sel = ref(null)         // inspection drawer
const showNew = ref(false)
const showSched = ref(false)
const schedSel = ref(null)    // schedule drawer
const tab = ref('inspections') // inspections | schedules

const dueCount = computed(() => (summary.value?.overdue || 0) + (summary.value?.due_soon || 0))

async function load() {
  loading.value = true; err.value = ''
  try {
    const [s, l, sc] = await Promise.all([
      apiCall('app-inspections', { action: 'summary' }),
      apiCall('app-inspections', { action: 'list', itype: fType.value, status: fStatus.value }),
      apiCall('app-inspections', { action: 'schedule-list' }),
    ])
    if (!s.ok) { err.value = s.error || t('Failed to load.'); return }
    summary.value = s
    if (l.ok) list.value = l.list || []
    if (sc.ok) scheds.value = sc.list || []
  } finally { loading.value = false }
}
function flash(m) { toast.value = m; setTimeout(() => toast.value = '', 3500) }

function filtered() {
  let out = list.value
  if (q.value) out = out.filter(x => (x.title || '').toLowerCase().includes(q.value.toLowerCase()) || (x.code || '').toLowerCase().includes(q.value.toLowerCase()) || (x.assignee || '').toLowerCase().includes(q.value.toLowerCase()))
  return out
}

// ── create / edit form ──
const form = ref({ id: 0, itype: 'safety', title: '', property_id: 0, assignee: '', scheduled_at: '', checklist: [], findings: '' })
function newForm() {
  form.value = { id: 0, itype: 'safety', title: '', property_id: propsAll.value[0]?.id || 0, assignee: '', scheduled_at: new Date().toISOString().slice(0, 10), checklist: [{ item: '', pass: null }], findings: '' }
  showNew.value = true; showSched.value = false; schedSel.value = null
}
function editForm(insp) {
  let cl = []
  try { cl = typeof insp.checklist === 'string' ? JSON.parse(insp.checklist || '[]') : (insp.checklist || []) } catch (e) { cl = [] }
  form.value = { id: insp.id, itype: insp.itype || 'safety', title: insp.title || '', property_id: Number(insp.property_id) || 0, assignee: insp.assignee || '', scheduled_at: (insp.scheduled_at || '').slice(0, 10), checklist: cl.length ? cl : [{ item: '', pass: null }], findings: insp.findings || '' }
  showNew.value = true; showSched.value = false; schedSel.value = null
}
function addCheckRow() { form.value.checklist.push({ item: '', pass: null }) }
function rmCheckRow(i) { form.value.checklist.splice(i, 1) }

async function saveForm() {
  err.value = ''
  if (!form.value.title.trim()) { err.value = 'Title is required.'; return }
  const cl = form.value.checklist.filter(c => (c.item || '').trim())
  loading.value = true
  try {
    const r = await apiCall('app-inspections', { action: form.value.id ? 'update' : 'create', ...form.value, checklist: cl, title: form.value.title.trim(), findings: form.value.findings })
    if (!r.ok) { err.value = r.error || t('Failed to save.'); return }
    flash(form.value.id ? '💾 Inspection updated' : `✅ ${r.code || 'INS'} created`)
    showNew.value = false; await load()
  } finally { loading.value = false }
}

// ── complete (sign-off) ──
const completing = ref(false)
async function completeInspection(passed) {
  err.value = ''
  if (!sel.value) return
  completing.value = true
  try {
    const cl = (sel.value.checklist || []).map(c => ({ item: c.item, pass: c.pass === null ? passed : c.pass }))
    const r = await apiCall('app-inspections', { action: 'complete', id: sel.value.id, status: passed ? 'passed' : 'failed', checklist: cl, findings: sel.value.findings || '' })
    if (!r.ok) { err.value = r.error || 'Failed.'; return }
    flash(passed ? '✅ Inspection passed — sign-off recorded' : '❌ Inspection failed — follow-up required')
    sel.value = null; await load()
  } finally { completing.value = false }
}
async function delInspection() {
  if (!sel.value) return
  loading.value = true
  try {
    const r = await apiCall('app-inspections', { action: 'delete', id: sel.value.id })
    if (r.ok) { flash('🗑️ Deleted'); sel.value = null; await load() } else err.value = r.error || t('Delete failed.')
  } finally { loading.value = false }
}
function parseCl(x) {
  let cl = []
  try { cl = typeof x === 'string' ? JSON.parse(x || '[]') : (x || []) } catch (e) { cl = [] }
  return Array.isArray(cl) ? cl : []
}
function toggleItem(insp, i) {
  const cl = parseCl(insp.checklist)
  const cur = cl[i]?.pass
  cl[i] = { ...cl[i], pass: cur === true ? false : cur === false ? null : true }
  insp.checklist = cl
}
function clScore(insp) {
  const cl = parseCl(insp.checklist)
  const done = cl.filter(c => c.pass !== null)
  return cl.length ? `${done.filter(c => c.pass).length}/${cl.length}` : '—'
}
function openDetail(x) { sel.value = { ...x, checklist: parseCl(x.checklist) } }

// ── schedules ──
const sForm = ref({ id: 0, itype: 'safety', title: '', property_id: 0, assignee: '', interval_days: 30, next_due: '', active: true, checklist: [] })
function newSched() {
  sForm.value = { id: 0, itype: 'safety', title: '', property_id: propsAll.value[0]?.id || 0, assignee: '', interval_days: 30, next_due: new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10), active: true, checklist: [{ item: '', pass: null }] }
  schedSel.value = { mode: 'new' }
}
function editSched(s) {
  let cl = []
  try { cl = typeof s.checklist === 'string' ? JSON.parse(s.checklist || '[]') : (s.checklist || []) } catch (e) { cl = [] }
  sForm.value = { id: Number(s.id), itype: s.itype || 'safety', title: s.title || '', property_id: Number(s.property_id) || 0, assignee: s.assignee || '', interval_days: Number(s.interval_days) || 30, next_due: (s.next_due || '').slice(0, 10), active: Number(s.active) === 1, checklist: cl.length ? cl : [{ item: '', pass: null }] }
  schedSel.value = { mode: 'edit', id: Number(s.id) }
}
async function saveSched() {
  err.value = ''
  if (!sForm.value.title.trim()) { err.value = 'Title is required.'; return }
  const cl = sForm.value.checklist.filter(c => (c.item || '').trim())
  loading.value = true
  try {
    const r = await apiCall('app-inspections', { action: 'schedule-save', ...sForm.value, checklist: cl, title: sForm.value.title.trim() })
    if (!r.ok) { err.value = r.error || t('Failed to save schedule.'); return }
    flash('🔁 Schedule saved')
    schedSel.value = null; await load()
  } finally { loading.value = false }
}
async function delSched(s) {
  loading.value = true
  try {
    const r = await apiCall('app-inspections', { action: 'schedule-delete', id: s.id })
    if (r.ok) { flash('🔁 Schedule disabled'); await load() } else err.value = r.error || 'Failed.'
  } finally { loading.value = false }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('🔍 Inspections') }}</h1>
        <div class="sub">{{ t('Safety / Service / Maintenance checks + recurring scheduler · sign-off with pass-fail checklists') }}</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-ghost" @click="showSched = !showSched; showNew = false">🔁 Schedules</button>
        <button v-if="canManage" class="btn-primary" @click="newForm">＋ New inspection</button>
      </div>
    </div>

    <div v-if="toast" style="background:rgba(39,174,96,.1);color:#1e8e4d;padding:10px 13px;border-radius:9px;font-size:12.5px;font-weight:700;margin-bottom:12px">{{ toast }}</div>
    <div v-if="err" class="auth-err show">{{ err }}</div>

    <!-- KPIs -->
    <div class="stats" style="margin-bottom:14px">
      <div class="stat"><div class="s-label"><span class="s-ico">📋</span>{{ t('Total') }}</div><div class="s-value">{{ summary?.total ?? '—' }}</div><div class="s-trend">{{ summary?.by_type?.safety ?? 0 }} safety · {{ summary?.by_type?.service ?? 0 }} service · {{ summary?.by_type?.maintenance ?? 0 }} maint</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">⏰</span>{{ t('Overdue') }}</div><div class="s-value" :style="(summary?.overdue ?? 0) > 0 ? 'color:var(--danger,#e74c3c)' : ''">{{ summary?.overdue ?? 0 }}</div><div class="s-trend">{{ summary?.due_soon ?? 0 }} due in 7 days</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">✅</span>{{ t('Passed') }}</div><div class="s-value" style="color:#1e8e4d">{{ summary?.by_status?.passed ?? 0 }}</div><div class="s-trend">{{ summary?.by_status?.failed ?? 0 }} failed</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">🔁</span>{{ t('Schedules') }}</div><div class="s-value">{{ summary?.schedules_active ?? 0 }}</div><div class="s-trend">active recurring</div></div>
    </div>

    <!-- tabs -->
    <ScrollTabs style="margin-bottom:14px">
      <button @click="tab='inspections'" :style="tab==='inspections' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'" style="padding:8px 14px;border:none;border-radius:9px;font-weight:800;font-size:12.5px;cursor:pointer">📋 Inspections ({{ list.length }})</button>
      <button @click="tab='schedules'" :style="tab==='schedules' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'" style="padding:8px 14px;border:none;border-radius:9px;font-weight:800;font-size:12.5px;cursor:pointer">🔁 Scheduler ({{ scheds.length }})</button>
    </ScrollTabs>

    <!-- ══ INSPECTIONS ══ -->
    <template v-if="tab === 'inspections'">
      <!-- filters -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <CompactFilters>
        <button v-for="t in [{v:'',l:t('All')}, ...TYPES]" :key="t.v" @click="fType = t.v; load()"
          :style="fType === t.v ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'"
          style="padding:7px 13px;border:none;border-radius:9px;font-weight:800;font-size:12px;cursor:pointer">{{ t.l }}</button>
        <span style="flex:1"></span>
        <input v-model="q" :placeholder="t('Search code / title / assignee…')" style="padding:8px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none;min-width:200px">
        </CompactFilters>
      </div>

      <div v-if="loading && !list.length" style="text-align:center;padding:40px;color:var(--text-mute)">Loading…</div>
      <div v-else-if="!filtered().length" style="text-align:center;padding:40px;color:var(--text-mute);font-size:13px">{{ t('No inspections yet — create one or set up a recurring schedule.') }}</div>

      <div style="display:flex;flex-direction:column;gap:9px">
        <div v-for="x in filtered()" :key="x.id" @click="openDetail(x)"
          style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:13px 15px;display:flex;gap:12px;align-items:center;cursor:pointer;transition:box-shadow .15s">
          <div style="width:44px;height:44px;border-radius:11px;background:var(--bg-alt);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">{{ typeLabel(x.itype).split(' ')[0] }}</div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:800;font-size:13.5px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              {{ x.title || '—' }}
              <span class="badge" :class="typeCls(x.itype)">{{ typeLabel(x.itype) }}</span>
            </div>
            <div class="c-sub" style="font-size:12px;margin-top:2px">{{ x.code || 'INS-—' }} · {{ propName(x.property_id) }} · 👤 {{ x.assignee || 'unassigned' }} · 📅 {{ fmtDate(x.scheduled_at) }}</div>
          </div>
          <div style="text-align:right;flex-shrink:0">
            <span class="badge" :class="statusMeta(x.status).cls">{{ statusMeta(x.status).l }}</span>
            <div class="c-sub" style="font-size:11px;margin-top:3px">checklist {{ clScore(x) }}</div>
          </div>
        </div>
      </div>
    </template>

    <!-- ══ SCHEDULER ══ -->
    <template v-else>
      <div class="c-sub" style="font-size:12.5px;margin-bottom:12px">Recurring inspections are auto-created when a schedule's next due date arrives — one open inspection per schedule until completed, then the next cycle is queued.</div>
      <div v-if="canManage" style="margin-bottom:12px"><button class="btn-primary" @click="newSched">＋ New schedule</button></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:12px">
        <div v-for="s in scheds" :key="s.id" style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 16px">
          <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">
            <div style="font-weight:800;font-size:13.5px">{{ s.title }}</div>
            <span class="badge" :class="Number(s.active) === 1 ? 'b-green' : 'b-gray'">{{ Number(s.active) === 1 ? t('Active') : 'Paused' }}</span>
          </div>
          <div class="c-sub" style="font-size:12px;margin-top:3px"><span class="badge" :class="typeCls(s.itype)">{{ typeLabel(s.itype) }}</span> · {{ propName(s.property_id) }}</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:11px 0;font-size:12.5px">
            <div style="background:var(--bg-alt);border-radius:9px;padding:8px 10px"><div class="c-sub" style="font-size:10.5px;font-weight:800;text-transform:uppercase">{{ t('Every') }}</div><div style="font-weight:800">{{ s.interval_days }} days</div></div>
            <div style="background:var(--bg-alt);border-radius:9px;padding:8px 10px"><div class="c-sub" style="font-size:10.5px;font-weight:800;text-transform:uppercase">{{ t('Next due') }}</div><div style="font-weight:800;color:var(--primary)">{{ fmtDate(s.next_due) }}</div></div>
          </div>
          <div class="c-sub" style="font-size:11.5px">👤 {{ s.assignee || 'unassigned' }} · last run {{ fmtDate(s.last_run) }}</div>
          <div v-if="canManage" style="display:flex;gap:8px;margin-top:11px">
            <button @click="editSched(s)" style="flex:1;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);font-weight:800;font-size:12px;cursor:pointer">✏️ Edit</button>
            <button @click="delSched(s)" style="padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--danger,#e74c3c);font-weight:800;font-size:12px;cursor:pointer">⏸</button>
          </div>
        </div>
        <div v-if="!scheds.length" style="grid-column:1/-1;text-align:center;padding:36px;color:var(--text-mute);font-size:13px">No schedules yet — e.g. “Fire extinguisher check · every 30 days”</div>
      </div>
    </template>

    <!-- ══ NEW / EDIT INSPECTION DRAWER ══ -->
    <div v-if="showNew" class="overlay" @click.self="showNew = false">
      <div class="drawer">
        <div style="padding:18px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <div style="font-weight:800;font-size:15px">{{ form.id ? '✏️ Edit inspection' : '＋ New inspection' }}</div>
          <button @click="showNew = false" style="background:none;border:none;font-size:17px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Type') }}</label>
            <div style="display:flex;gap:7px;margin-top:5px;flex-wrap:wrap">
              <button v-for="t in TYPES" :key="t.v" @click="form.itype = t.v"
                :style="form.itype === t.v ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'"
                style="padding:7px 13px;border:none;border-radius:9px;font-weight:800;font-size:12px;cursor:pointer">{{ t.l }}</button>
            </div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Title *</label>
            <input v-model="form.title" placeholder="e.g. Fire extinguisher pressure check" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Property') }}</label>
              <select v-model="form.property_id" style="width:100%;margin-top:5px;padding:9px 10px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
                <option :value="0">— None —</option>
                <option v-for="p in propOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </div>
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Scheduled date') }}</label>
              <input v-model="form.scheduled_at" type="date" style="width:100%;margin-top:5px;padding:8px 10px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            </div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Assignee') }}</label>
            <input v-model="form.assignee" placeholder="e.g. Rahim Steel Works / Shakil" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <div style="display:flex;justify-content:space-between;align-items:center">
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Checklist') }}</label>
              <button @click="addCheckRow" style="padding:5px 11px;border:1px dashed var(--border);border-radius:8px;background:transparent;font-weight:800;font-size:12px;cursor:pointer">＋ item</button>
            </div>
            <div v-for="(c, i) in form.checklist" :key="i" style="display:flex;gap:8px;margin-top:7px;align-items:center">
              <input v-model="c.item" placeholder="e.g. Pressure gauge in green zone" style="flex:1;padding:8px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
              <button v-if="form.checklist.length > 1" @click="rmCheckRow(i)" style="background:none;border:none;color:var(--danger,#e74c3c);cursor:pointer;font-size:14px">✕</button>
            </div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Findings / notes') }}</label>
            <RichEditor v-model="form.findings" placeholder="Initial notes, scope, references…" :min-height="'110px'" style="margin-top:5px" />
          </div>
          <div v-if="err" class="auth-err show">{{ err }}</div>
          <button @click="saveForm" :disabled="loading" style="padding:12px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">{{ loading ? 'Saving…' : '💾 Save inspection' }}</button>
        </div>
      </div>
    </div>

    <!-- ══ DETAIL DRAWER ══ -->
    <div v-if="sel" class="overlay" @click.self="sel = null">
      <div class="drawer">
        <div style="padding:18px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:10px">
          <div>
            <div style="font-weight:800;font-size:15px">{{ sel.title }}</div>
            <div class="c-sub" style="font-size:12px">{{ sel.code }} · {{ fmtTs(sel.created_at) }}</div>
          </div>
          <button @click="sel = null" style="background:none;border:none;font-size:17px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1">
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
            <span class="badge" :class="typeCls(sel.itype)">{{ typeLabel(sel.itype) }}</span>
            <span class="badge" :class="statusMeta(sel.status).cls">{{ statusMeta(sel.status).l }}</span>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-bottom:14px;font-size:12.5px">
            <div style="background:var(--bg-alt);border-radius:9px;padding:9px 11px"><div class="c-sub" style="font-size:10.5px;font-weight:800;text-transform:uppercase">{{ t('Property') }}</div><div style="font-weight:700">{{ propName(sel.property_id) }}</div></div>
            <div style="background:var(--bg-alt);border-radius:9px;padding:9px 11px"><div class="c-sub" style="font-size:10.5px;font-weight:800;text-transform:uppercase">{{ t('Assignee') }}</div><div style="font-weight:700">{{ sel.assignee || '—' }}</div></div>
            <div style="background:var(--bg-alt);border-radius:9px;padding:9px 11px"><div class="c-sub" style="font-size:10.5px;font-weight:800;text-transform:uppercase">{{ t('Scheduled') }}</div><div style="font-weight:700">{{ fmtDate(sel.scheduled_at) }}</div></div>
            <div style="background:var(--bg-alt);border-radius:9px;padding:9px 11px"><div class="c-sub" style="font-size:10.5px;font-weight:800;text-transform:uppercase">{{ t('Completed') }}</div><div style="font-weight:700">{{ sel.completed_at ? fmtTs(sel.completed_at) : '—' }} <template v-if="sel.completed_by">by {{ sel.completed_by }}</template></div></div>
          </div>

          <!-- checklist -->
          <div style="font-weight:800;font-size:13px;margin-bottom:8px">✅ Checklist · {{ clScore(sel) }}</div>
          <div v-if="!sel.checklist || !sel.checklist.length" class="c-sub" style="font-size:12px;margin-bottom:12px">{{ t('No checklist items.') }}</div>
          <div v-for="(c, i) in (sel.checklist || [])" :key="i" @click="toggleItem(sel, i)"
            style="display:flex;gap:10px;align-items:center;padding:9px 11px;border:1px solid var(--border);border-radius:9px;margin-bottom:6px;cursor:pointer;background:var(--card)">
            <span style="width:24px;height:24px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0"
              :style="c.pass === true ? 'background:rgba(39,174,96,.15);color:#1e8e4d' : c.pass === false ? 'background:rgba(231,76,60,.12);color:#e74c3c' : 'background:var(--bg-alt);color:var(--text-mute)'">
              {{ c.pass === true ? '✓' : c.pass === false ? '✕' : '○' }}
            </span>
            <span style="font-size:13px;font-weight:600">{{ c.item }}</span>
          </div>
          <div v-if="canManage && ['scheduled','in_progress'].includes(sel.status)" class="c-sub" style="font-size:11.5px;margin:4px 0 12px">Tap an item to mark pass → fail → unchecked.</div>

          <!-- findings -->
          <div v-if="sel.findings" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 15px;margin:12px 0;font-size:13px;line-height:1.65" v-html="sel.findings"></div>

          <div v-if="canManage && ['scheduled','in_progress'].includes(sel.status)" style="display:flex;gap:9px;margin-top:14px;flex-wrap:wrap">
            <button @click="completeInspection(true)" :disabled="completing" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">✅ Pass &amp; sign off</button>
            <button @click="completeInspection(false)" :disabled="completing" style="flex:1;padding:11px;border:none;border-radius:10px;background:var(--danger,#e74c3c);color:#fff;font-size:13px;font-weight:800;cursor:pointer">❌ Fail</button>
          </div>
          <div style="display:flex;gap:9px;margin-top:14px;flex-wrap:wrap">
            <button v-if="canManage" @click="editForm(sel); sel = null" style="flex:1;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--card);font-weight:800;font-size:12.5px;cursor:pointer">✏️ Edit</button>
            <button v-if="canManage" @click="delInspection" style="flex:1;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--card);color:var(--danger,#e74c3c);font-weight:800;font-size:12.5px;cursor:pointer">🗑️ Delete</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ SCHEDULE DRAWER ══ -->
    <div v-if="schedSel" class="overlay" @click.self="schedSel = null">
      <div class="drawer">
        <div style="padding:18px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <div style="font-weight:800;font-size:15px">{{ schedSel.mode === 'new' ? '＋ New schedule' : '✏️ Edit schedule' }}</div>
          <button @click="schedSel = null" style="background:none;border:none;font-size:17px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Type') }}</label>
            <div style="display:flex;gap:7px;margin-top:5px;flex-wrap:wrap">
              <button v-for="t in TYPES" :key="t.v" @click="sForm.itype = t.v"
                :style="sForm.itype === t.v ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'"
                style="padding:7px 13px;border:none;border-radius:9px;font-weight:800;font-size:12px;cursor:pointer">{{ t.l }}</button>
            </div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Title *</label>
            <input v-model="sForm.title" placeholder="e.g. Fire extinguisher pressure check" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Property') }}</label>
              <select v-model="sForm.property_id" style="width:100%;margin-top:5px;padding:9px 10px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
                <option :value="0">— None —</option>
                <option v-for="p in propOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </div>
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Every (days)') }}</label>
              <input v-model.number="sForm.interval_days" type="number" min="1" max="3650" style="width:100%;margin-top:5px;padding:8px 10px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            </div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('First due date') }}</label>
            <input v-model="sForm.next_due" type="date" style="width:100%;margin-top:5px;padding:8px 10px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Assignee') }}</label>
            <input v-model="sForm.assignee" placeholder="e.g. Rahim Steel Works / Shakil" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;cursor:pointer"><input type="checkbox" v-model="sForm.active" style="accent-color:var(--primary)"> {{ t('Active (auto-creates inspections when due)') }}</label>
          <div>
            <div style="display:flex;justify-content:space-between;align-items:center">
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Checklist template') }}</label>
              <button @click="sForm.checklist.push({ item: '', pass: null })" style="padding:5px 11px;border:1px dashed var(--border);border-radius:8px;background:transparent;font-weight:800;font-size:12px;cursor:pointer">＋ item</button>
            </div>
            <div v-for="(c, i) in sForm.checklist" :key="i" style="display:flex;gap:8px;margin-top:7px;align-items:center">
              <input v-model="c.item" placeholder="e.g. Pressure gauge in green zone" style="flex:1;padding:8px 11px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
              <button v-if="sForm.checklist.length > 1" @click="sForm.checklist.splice(i, 1)" style="background:none;border:none;color:var(--danger,#e74c3c);cursor:pointer;font-size:14px">✕</button>
            </div>
          </div>
          <div v-if="err" class="auth-err show">{{ err }}</div>
          <button @click="saveSched" :disabled="loading" style="padding:12px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">{{ loading ? 'Saving…' : '💾 Save schedule' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
