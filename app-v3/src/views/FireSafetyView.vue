<script setup>
import { computed, ref, watch, onMounted } from 'vue'
import { t } from '../lib/i18n'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { badge, useViewMode, money, fmtTs } from '../lib/ui'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('firesafety')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))
const canDelete = computed(() => ['superadmin', 'owner'].includes(auth.user?.role || ''))

// ── live API data (app-firesafety) ──
const loading = ref(false)
const err = ref('')
const assets = ref([])
const incidents = ref([])
const plans = ref([])
const contacts = ref([])
const cfg = ref({})
const sm = ref({})

const propsAll = computed(() => data.list('properties'))
const propName = (pid) => propsAll.value.find(p => p.id === pid)?.name || pid || '—'
const propOptions = computed(() => propsAll.value.map(p => ({ id: p.id, name: p.name })))

const ASSET_TYPES = [
  { v: 'extinguisher', l: 'Fire extinguisher' },
  { v: 'detector', l: 'Smoke detector' },
  { v: 'alarm', l: 'Fire alarm' },
  { v: 'sprinkler', l: 'Sprinkler system' },
  { v: 'hose', l: 'Hose reel' },
  { v: 'blanket', l: 'Fire blanket' },
  { v: 'exit', l: 'Emergency exit' },
  { v: 'other', l: 'Other' },
]
const assetTypeLabel = (t) => ASSET_TYPES.find(x => x.v === t)?.l || t || '—'

const INCIDENT_TYPES = ['fire', 'electrical', 'gas', 'smoke', 'other']
const SEVERITIES = ['low', 'medium', 'high', 'critical']
const sevCls = (s) => s === 'critical' || s === 'high' ? 'b-red' : s === 'medium' ? 'b-orange' : 'b-gray'
const fireCap = (s) => s ? String(s).replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase()) : '—'

const fmtDate = (d) => { if (!d) return '—'; const t = new Date(String(d).slice(0, 10) + 'T00:00:00'); return isNaN(t) ? String(d).slice(0, 10) : t.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) }

async function load() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-firesafety', { action: 'summary' })
    if (!r.ok) { err.value = r.error || 'Failed to load fire safety data.'; return }
    const s = r.summary || {}
    sm.value = s
    assets.value = s.assets || []
    incidents.value = s.incidents || []
    plans.value = s.plans || []
    contacts.value = s.contacts || []
    cfg.value = r.config || s.config || {}
  } finally { loading.value = false }
}
onMounted(load)

// ── KPIs ──
const kpis = computed(() => {
  const a = assets.value
  const active = a.filter(x => String(x.status || '').toLowerCase() === 'active').length
  const expired = a.filter(x => x.expired).length
  const expiring = a.filter(x => x.expiring).length
  const overdue = a.filter(x => x.inspection_overdue).length
  const incOpen = incidents.value.filter(i => !['Resolved', 'Closed'].includes(i.status || '')).length
  return [
    { label: 'Assets', ico: '🧯', value: a.length, trend: active + ' active' },
    { label: 'Expired', ico: '⚠️', value: expired, trend: 'need replacement', ok: expired === 0 },
    { label: 'Expiring', ico: '⏳', value: expiring, trend: 'within ' + (cfg.value.expiry_alert_days || 60) + 'd', ok: expiring === 0 },
    { label: 'Inspection', ico: '🔍', value: overdue, trend: 'overdue', ok: overdue === 0 },
    { label: 'Open incidents', ico: '🚨', value: incOpen, trend: 'in progress', ok: incOpen === 0 },
    { label: 'Plans', ico: '🗺️', value: plans.value.length, trend: (sm.value.plans_active || 0) + ' active' },
  ]
})

// ── asset filters ──
const aQuery = ref('')
const aStatus = ref('')
const aType = ref('')
const aProp = ref('')
const aTypeOptions = computed(() => [...new Set(assets.value.map(a => a.asset_type).filter(Boolean))])
const aStatusOptions = computed(() => [...new Set(assets.value.map(a => a.status || 'active').filter(Boolean))])
const aPropOptions = computed(() => [...new Set(assets.value.map(a => a.prop).filter(Boolean))])
const filteredAssets = computed(() => {
  let out = assets.value
  const q = aQuery.value.trim().toLowerCase()
  if (q) out = out.filter(a => JSON.stringify(a).toLowerCase().includes(q))
  if (aStatus.value) out = out.filter(a => (a.status || 'active') === aStatus.value)
  if (aType.value) out = out.filter(a => a.asset_type === aType.value)
  if (aProp.value) out = out.filter(a => a.prop === aProp.value)
  return [...out].sort((x, y) => String(x.id).localeCompare(String(y.id)))
})

// ── incident filters ──
const iQuery = ref('')
const iSev = ref('')
const iStatus = ref('')
const iSevOptions = SEVERITIES
const iStatusOptions = computed(() => [...new Set(incidents.value.map(i => i.status || '').filter(Boolean))])
const filteredIncidents = computed(() => {
  let out = incidents.value
  const q = iQuery.value.trim().toLowerCase()
  if (q) out = out.filter(i => JSON.stringify(i).toLowerCase().includes(q))
  if (iSev.value) out = out.filter(i => i.severity === iSev.value)
  if (iStatus.value) out = out.filter(i => i.status === iStatus.value)
  return [...out].sort((x, y) => String(y.ts || '').localeCompare(String(x.ts || '')))
})

// ── asset form ──
const showAssetForm = ref(false)
const editingAsset = ref(null)
const assetForm = ref({ asset_type: 'extinguisher', location: '', prop: '', model: '', serial_no: '', install_date: '', expiry_date: '', status: 'active', notes: '' })
function openAssetAdd() { editingAsset.value = null; assetForm.value = { asset_type: 'extinguisher', location: '', prop: propOptions.value[0]?.id || '', model: '', serial_no: '', install_date: '', expiry_date: '', status: 'active', notes: '' }; showAssetForm.value = true }
function openAssetEdit(a) { editingAsset.value = a; assetForm.value = { asset_type: a.asset_type || 'extinguisher', location: a.location || '', prop: a.prop || '', model: a.model || '', serial_no: a.serial_no || '', install_date: a.install_date || '', expiry_date: a.expiry_date || '', status: a.status || 'active', notes: a.notes || '' }; showAssetForm.value = true }
async function saveAsset() {
  const f = assetForm.value
  if (!f.location.trim()) { alert('Location is required.'); return }
  const payload = { action: editingAsset.value ? 'asset-save' : 'asset-create', id: editingAsset.value?.id, asset_type: f.asset_type, location: f.location.trim(), prop: f.prop, model: f.model.trim(), serial_no: f.serial_no.trim(), install_date: f.install_date, expiry_date: f.expiry_date, status: f.status, notes: f.notes.trim() }
  const r = await apiCall('app-firesafety', payload)
  if (!r.ok) { alert(r.error || 'Save failed'); return }
  showAssetForm.value = false
  await load()
}
async function inspectAsset(a) {
  if (!confirm(`Mark ${a.id} as inspected?`)) return
  const r = await apiCall('app-firesafety', { action: 'asset-inspect', id: a.id })
  if (!r.ok) { alert(r.error || 'Inspect failed'); return }
  await load()
}
async function deleteAsset(a) {
  if (!confirm(`Delete ${a.id} (${a.model || assetTypeLabel(a.asset_type)})? This cannot be undone.`)) return
  const r = await apiCall('app-firesafety', { action: 'asset-delete', id: a.id })
  if (!r.ok) { alert(r.error || 'Delete failed'); return }
  await load()
}

// ── incident form ──
const showIncidentForm = ref(false)
const editingIncident = ref(null)
const incidentForm = ref({ incident_type: 'fire', severity: 'medium', location: '', prop: '', description: '' })
function openIncidentAdd() { editingIncident.value = null; incidentForm.value = { incident_type: 'fire', severity: 'medium', location: '', prop: propOptions.value[0]?.id || '', description: '' }; showIncidentForm.value = true }
function openIncidentEdit(i) { editingIncident.value = i; incidentForm.value = { incident_type: i.incident_type || 'fire', severity: i.severity || 'medium', location: i.location || '', prop: i.prop || '', description: i.description || '' }; showIncidentForm.value = true }
async function saveIncident() {
  const f = incidentForm.value
  if (!f.location.trim()) { alert('Location is required.'); return }
  const payload = { action: editingIncident.value ? 'incident-save' : 'incident-create', id: editingIncident.value?.id, incident_type: f.incident_type, severity: f.severity, location: f.location.trim(), prop: f.prop, description: f.description.trim() }
  const r = await apiCall('app-firesafety', payload)
  if (!r.ok) { alert(r.error || 'Save failed'); return }
  showIncidentForm.value = false
  await load()
}
async function advanceIncident(i) {
  const r = await apiCall('app-firesafety', { action: 'incident-status', id: i.id })
  if (!r.ok) { alert(r.error || 'Advance failed'); return }
  await load()
}
const showIncidentEvent = ref(false)
const eventFor = ref(null)
const eventNote = ref('')
function openEventForm(i) { eventFor.value = i; eventNote.value = ''; showIncidentEvent.value = true }
async function saveEvent() {
  if (!eventNote.value.trim()) return
  const r = await apiCall('app-firesafety', { action: 'incident-event', id: eventFor.value.id, note: eventNote.value.trim() })
  if (!r.ok) { alert(r.error || 'Add event failed'); return }
  showIncidentEvent.value = false
  await load()
}
async function deleteIncident(i) {
  if (!confirm(`Delete incident ${i.id}? This cannot be undone.`)) return
  const r = await apiCall('app-firesafety', { action: 'incident-delete', id: i.id })
  if (!r.ok) { alert(r.error || 'Delete failed'); return }
  await load()
}
function timelineOf(i) {
  if (Array.isArray(i.timeline_arr)) return i.timeline_arr
  try { const p = JSON.parse(i.timeline || '[]'); return Array.isArray(p) ? p : [] } catch (e) { return [] }
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('🧯 Fire Safety') }}</h1>
        <div class="sub">{{ assets.length }} assets · {{ incidents.length }} incidents · {{ plans.length }} plans · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="aQuery" :placeholder="t('Search assets…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:200px">
        <button v-if="canManage" @click="openIncidentAdd" class="btn-ghost" style="display:inline-flex;align-items:center;gap:6px">🚨 Report incident</button>
      </CompactFilters>
        <button v-if="canManage" @click="openAssetAdd" class="btn-primary" style="display:inline-flex;align-items:center;gap:6px">＋ Add asset</button>
      </div>
    </div>

    <div v-if="err" class="panel" style="padding:18px;color:var(--danger)">⚠️ {{ err }}</div>
    <div v-if="loading" class="panel" style="padding:22px;text-align:center;color:var(--text-mute)">Loading…</div>

    <template v-if="!loading && !err">
      <div class="stats">
        <div v-for="k in kpis" :key="k.label" class="stat">
          <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
          <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
          <div class="s-trend">{{ k.trend }}</div>
        </div>
      </div>

      <!-- ASSETS -->
      <div class="panel" style="overflow:hidden;margin-top:18px">
        <div class="panel-h" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:14px 18px;border-bottom:1px solid var(--border)">
          <b style="font-size:15px">🧯 Assets</b>
          <span class="c-sub" style="font-size:12px">{{ filteredAssets.length }} shown</span>
          <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
            <select v-model="aStatus" style="padding:7px 9px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
              <option value="">{{ t('All statuses') }}</option>
              <option v-for="s in aStatusOptions" :key="s" :value="s">{{ fireCap(s) }}</option>
            </select>
            <select v-model="aType" style="padding:7px 9px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
              <option value="">{{ t('All types') }}</option>
              <option v-for="t in aTypeOptions" :key="t" :value="t">{{ assetTypeLabel(t) }}</option>
            </select>
            <select v-model="aProp" style="padding:7px 9px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
              <option value="">{{ t('All properties') }}</option>
              <option v-for="p in aPropOptions" :key="p" :value="p">{{ propName(p) }}</option>
            </select>
          </div>
        </div>
        <div class="tbl-wrap">
          <table class="kr" style="width:100%">
            <thead><tr><th>{{ t('Asset') }}</th><th>{{ t('Type') }}</th><th>{{ t('Property') }}</th><th>{{ t('Location') }}</th><th>{{ t('Expiry') }}</th><th>{{ t('Inspection') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="a in filteredAssets" :key="a.id">
                <td style="white-space:nowrap"><b>{{ a.id }}</b><div class="c-sub" style="font-size:11.5px">{{ a.model || '—' }}<template v-if="a.serial_no"> · {{ a.serial_no }}</template></div></td>
                <td style="white-space:nowrap"><span class="badge b-blue">{{ assetTypeLabel(a.asset_type) }}</span></td>
                <td style="white-space:nowrap" class="c-sub">{{ propName(a.prop) }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ a.location || '—' }}</td>
                <td style="white-space:nowrap">
                  {{ fmtDate(a.expiry_date) }}
                  <span v-if="a.expired" class="badge b-red">{{ t('EXPIRED') }}</span>
                  <span v-else-if="a.expiring" class="badge b-orange">expiring {{ a.days_to_expiry }}d</span>
                </td>
                <td style="white-space:nowrap">
                  {{ fmtDate(a.next_inspection) }}
                  <span v-if="a.inspection_overdue" class="badge b-red">overdue</span>
                </td>
                <td style="white-space:nowrap"><span class="badge" :class="badge(a.status)">{{ fireCap(a.status) }}</span></td>
                <td style="white-space:nowrap">
                  <div style="display:flex;gap:6px;justify-content:flex-end">
                    <button v-if="canManage" @click="openAssetEdit(a)" class="btn-ghost" style="padding:4px 9px;font-size:11.5px">✏️</button>
                    <button v-if="canManage" @click="inspectAsset(a)" class="btn-ghost" style="padding:4px 9px;font-size:11.5px" :title="t('Mark inspected')">✅ Inspect</button>
                    <button v-if="canDelete" @click="deleteAsset(a)" class="btn-ghost" style="padding:4px 9px;font-size:11.5px;color:var(--danger)" :title="t('Delete')">🗑</button>
                  </div>
                </td>
              </tr>
              <tr v-if="!filteredAssets.length"><td colspan="8" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No fire assets found.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- INCIDENTS -->
      <div class="panel" style="overflow:hidden;margin-top:18px">
        <div class="panel-h" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:14px 18px;border-bottom:1px solid var(--border)">
          <b style="font-size:15px">🚨 Incidents</b>
          <span class="c-sub" style="font-size:12px">{{ filteredIncidents.length }} shown</span>
          <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
            <input v-model="iQuery" placeholder="Search incidents…" style="padding:7px 9px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none;width:160px">
            <select v-model="iSev" style="padding:7px 9px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
              <option value="">{{ t('All severities') }}</option>
              <option v-for="s in iSevOptions" :key="s" :value="s">{{ fireCap(s) }}</option>
            </select>
            <select v-model="iStatus" style="padding:7px 9px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
              <option value="">{{ t('All statuses') }}</option>
              <option v-for="s in iStatusOptions" :key="s" :value="s">{{ s }}</option>
            </select>
          </div>
        </div>
        <div style="padding:8px 0">
          <div v-for="i in filteredIncidents" :key="i.id" class="row-item" style="padding:12px 18px;border-bottom:1px solid var(--border)">
            <div class="ri-ic">{{ i.severity === 'critical' || i.severity === 'high' ? '🔴' : '🟠' }}</div>
            <div class="ri-body">
              <div class="ri-t">
                <b>{{ i.id }}</b> — {{ fireCap(i.incident_type) }}
                <span class="badge" :class="sevCls(i.severity)">{{ fireCap(i.severity) }}</span>
                <span class="badge" :class="['Resolved', 'Closed'].includes(i.status) ? 'b-gray' : 'b-blue'">{{ i.status }}</span>
              </div>
              <div class="ri-s">{{ i.location || '—' }}<template v-if="i.property_name"> · {{ i.property_name }}</template><template v-if="i.occurred_at"> · {{ fmtTs(i.occurred_at) }}</template></div>
              <div class="ri-s" v-if="i.description">{{ i.description }}</div>
              <div class="ri-s" v-if="timelineOf(i).length">
                <b>{{ t('Timeline:') }}</b>
                <span v-for="(e, ei) in timelineOf(i)" :key="ei" style="display:inline-block;margin-right:8px">• {{ e.t?.slice(0, 16) }} {{ e.note || '' }}<template v-if="e.by"> ({{ e.by }})</template></span>
              </div>
            </div>
            <div style="display:flex;gap:6px;align-items:flex-start;flex-wrap:wrap">
              <button v-if="canManage && !['Resolved', 'Closed'].includes(i.status)" @click="advanceIncident(i)" class="btn-ghost" style="padding:4px 9px;font-size:11.5px">⏩ Advance</button>
              <button v-if="canManage" @click="openEventForm(i)" class="btn-ghost" style="padding:4px 9px;font-size:11.5px">📝 Event</button>
              <button v-if="canManage" @click="openIncidentEdit(i)" class="btn-ghost" style="padding:4px 9px;font-size:11.5px">✏️</button>
              <button v-if="canDelete" @click="deleteIncident(i)" class="btn-ghost" style="padding:4px 9px;font-size:11.5px;color:var(--danger)">🗑</button>
            </div>
          </div>
          <div v-if="!filteredIncidents.length" style="padding:24px;text-align:center;color:var(--text-mute)">{{ t('No incidents reported.') }}</div>
        </div>
      </div>

      <!-- PLANS + CONTACTS -->
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-top:18px">
        <div class="panel" style="overflow:hidden">
          <div class="panel-h" style="padding:13px 16px;border-bottom:1px solid var(--border);font-weight:800;font-size:14px">🗺️ Evacuation plans</div>
          <div style="padding:12px 16px">
            <div v-for="p in plans" :key="p.id" class="row-item" style="border:none;padding:7px 0">
              <div class="ri-ic">🗺️</div>
              <div class="ri-body">
                <div class="ri-t"><b>{{ p.id }}</b> · {{ p.name || '—' }} <span class="badge" :class="badge(p.status)">{{ p.status }}</span></div>
                <div class="ri-s" v-if="p.drill_date">Last drill: {{ fmtDate(p.drill_date) }}<span v-if="p.drill_overdue" class="badge b-red" style="margin-left:6px">drill overdue</span></div>
              </div>
            </div>
            <div v-if="!plans.length" style="color:var(--text-mute);font-size:12.5px">{{ t('No evacuation plans on file.') }}</div>
          </div>
        </div>
        <div class="panel" style="overflow:hidden">
          <div class="panel-h" style="padding:13px 16px;border-bottom:1px solid var(--border);font-weight:800;font-size:14px">📞 Emergency contacts</div>
          <div style="padding:12px 16px">
            <div v-for="c in contacts" :key="c.id" class="row-item" style="border:none;padding:7px 0">
              <div class="ri-ic">📞</div>
              <div class="ri-body">
                <div class="ri-t"><b>{{ c.name || c.id }}</b> <span class="c-sub" style="font-size:12px">{{ c.role || '' }}</span></div>
                <div class="ri-s" v-if="c.phone">📱 {{ c.phone }}<template v-if="c.alt_phone"> · {{ c.alt_phone }}</template></div>
              </div>
            </div>
            <div v-if="!contacts.length" style="color:var(--text-mute);font-size:12.5px">{{ t('No emergency contacts.') }}</div>
          </div>
        </div>
      </div>
    </template>

    <!-- asset modal -->
    <template v-if="showAssetForm">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="showAssetForm = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(520px,94vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:90px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">{{ editingAsset ? '✏️ Edit asset' : '＋ Add asset' }}</div>
          <button @click="showAssetForm = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Type') }}</label>
            <select v-model="assetForm.asset_type" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
              <option v-for="t in ASSET_TYPES" :key="t.v" :value="t.v">{{ t.l }}</option>
            </select>
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Location *</label>
            <input v-model="assetForm.location" placeholder="e.g. Lobby — ground floor" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Property') }}</label>
            <select v-model="assetForm.prop" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
              <option v-for="p in propOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Model') }}</label>
              <input v-model="assetForm.model" :placeholder="t('ABC-4kg')" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Serial no.') }}</label>
              <input v-model="assetForm.serial_no" placeholder="SN-…" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Install date') }}</label>
              <input v-model="assetForm.install_date" type="date" style="width:100%;padding:8px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Expiry date') }}</label>
              <input v-model="assetForm.expiry_date" type="date" style="width:100%;padding:8px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
            </div>
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Status') }}</label>
            <select v-model="assetForm.status" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
              <option value="active">{{ t('Active') }}</option>
              <option value="maintenance">{{ t('Maintenance') }}</option>
              <option value="expired">{{ t('Expired') }}</option>
              <option value="retired">{{ t('Retired') }}</option>
            </select>
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Notes') }}</label>
            <textarea v-model="assetForm.notes" rows="2" :placeholder="t('Optional notes')" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px;resize:vertical"></textarea>
          </div>
          <button @click="saveAsset" class="btn-primary" style="margin-top:4px">💾 Save asset</button>
        </div>
      </div>
    </template>

    <!-- incident modal -->
    <template v-if="showIncidentForm">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="showIncidentForm = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(520px,94vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:90px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">{{ editingIncident ? '✏️ Edit incident' : '🚨 Report incident' }}</div>
          <button @click="showIncidentForm = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:13px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Type') }}</label>
              <select v-model="incidentForm.incident_type" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
                <option v-for="t in INCIDENT_TYPES" :key="t" :value="t">{{ fireCap(t) }}</option>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Severity') }}</label>
              <select v-model="incidentForm.severity" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
                <option v-for="s in SEVERITIES" :key="s" :value="s">{{ fireCap(s) }}</option>
              </select>
            </div>
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Location *</label>
            <input v-model="incidentForm.location" placeholder="e.g. Kitchen — 3rd floor" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Property') }}</label>
            <select v-model="incidentForm.prop" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
              <option v-for="p in propOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Description') }}</label>
            <textarea v-model="incidentForm.description" rows="3" :placeholder="t('What happened?')" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px;resize:vertical"></textarea>
          </div>
          <button @click="saveIncident" class="btn-primary" style="margin-top:4px">💾 Save incident</button>
        </div>
      </div>
    </template>

    <!-- event modal -->
    <template v-if="showIncidentEvent">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="showIncidentEvent = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(480px,94vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:90px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">📝 Add event — {{ eventFor?.id }}</div>
          <button @click="showIncidentEvent = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Note *</label>
            <textarea v-model="eventNote" rows="4" placeholder="e.g. Fire Service arrived — crew on site" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px;resize:vertical"></textarea>
          </div>
          <button @click="saveEvent" class="btn-primary" style="margin-top:4px">💾 Add to timeline</button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.row-item { display: flex; gap: 12px; align-items: flex-start; }
.ri-ic { font-size: 20px; flex-shrink: 0; margin-top: 2px; }
.ri-t { font-size: 13.5px; font-weight: 700; }
.ri-s { font-size: 12.5px; color: var(--text-mute); margin-top: 2px; line-height: 1.55; }
.d-cover .badge { background: #ffffff; }
</style>
