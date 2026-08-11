<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { badge, useViewMode, usePager, fmtTs } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import RichEditor from '../components/RichEditor.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('cases')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'legal'].includes(auth.user?.role || ''))
const casesAll = computed(() => data.list('cases'))
const eventsAll = computed(() => data.list('case_events'))

const TYPE_META = {
  eviction: { ico: '🚪', label: 'Eviction', cls: 'b-red' },
  arrears: { ico: '💰', label: 'Rent arrears', cls: 'b-orange' },
  dispute: { ico: '⚖️', label: 'Dispute', cls: 'b-blue' },
  default: { ico: '⚖️', label: 'Legal', cls: 'b-gray' },
}
const typeMeta = (t) => TYPE_META[t] || TYPE_META.default
const daysUntil = (d) => { if (!d) return null; const t = new Date(String(d).slice(0, 10) + 'T00:00:00'); if (isNaN(t)) return null; return Math.round((t - Date.now()) / 86400000) }
const fmtDate = (d) => { if (!d) return '—'; const t = new Date(String(d).slice(0, 10) + 'T00:00:00'); return isNaN(t) ? String(d).slice(0, 10) : t.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) }
const hearingNote = (c) => {
  const n = daysUntil(c.next_hearing)
  if (n === null) return ''
  if (n < 0) return 'overdue by ' + (-n) + 'd'
  if (n === 0) return 'today'
  return n + 'd away'
}

// ── KPIs ──
const kpis = computed(() => {
  const cs = casesAll.value
  const open = cs.filter(c => (c.status || '').toLowerCase() === 'open').length
  const types = new Set(cs.map(c => c.type).filter(Boolean)).size
  const lawyers = new Set(cs.map(c => c.lawyer).filter(Boolean)).size
  const hearings = cs.map(c => c.next_hearing).filter(Boolean).sort()
  const next = hearings[0] || ''
  const n = daysUntil(next)
  return [
    { label: 'Cases', ico: '⚖️', value: cs.length, trend: 'legal matters tracked' },
    { label: 'Open', ico: '🟥', value: open, trend: open === cs.length ? 'all active' : open + ' of ' + cs.length, ok: open <= 2 },
    { label: 'Types', ico: '🗂️', value: types, trend: 'eviction · arrears · dispute' },
    { label: 'Lawyers', ico: '👨‍⚖️', value: lawyers, trend: 'counsel assigned' },
    { label: 'Next hearing', ico: '📅', value: next ? fmtDate(next) : '—', trend: next ? (n !== null ? (n < 0 ? 'overdue by ' + (-n) + 'd' : (n === 0 ? 'today' : n + 'd away')) : '') : 'none scheduled' },
    { label: 'Stages', ico: '🔄', value: cs.length ? 'Active' : '—', trend: 'case pipeline live' },
  ]
})

// ── filters / sort ──
const query = ref('')
const typeFilter = ref('')
const statusFilter = ref('')
const sortBy = ref('opened')
const typeOptions = computed(() => [...new Set(casesAll.value.map(c => c.type).filter(Boolean))].sort())
const statusOptions = computed(() => [...new Set(casesAll.value.map(c => c.status).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = casesAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(c => JSON.stringify(c).toLowerCase().includes(q))
  if (typeFilter.value) out = out.filter(c => (c.type || '') === typeFilter.value)
  if (statusFilter.value) out = out.filter(c => (c.status || '') === statusFilter.value)
  const get = (c) => sortBy.value === 'next_hearing' ? (c.next_hearing || '9999') : (c.opened || '')
  return [...out].sort((a, b) => String(get(a)).localeCompare(String(get(b))))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 10)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[\",\n]/.test(s) ? '\"' + s.replace(/\"/g, '\"\"') + '\"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'cases.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer + timeline ──
const sel = ref(null)
function openDetail(c) { sel.value = c }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const c = casesAll.value.find(x => x.id === id); if (c) openDetail(c) }
}, { immediate: true })
const timeline = computed(() => {
  if (!sel.value) return []
  return eventsAll.value
    .filter(e => e.case_id === sel.value.id)
    .sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || '')))
})
const evIco = (t) => ({ stage: '🔄', hearing: '⚖️', note: '📝', doc: '📎' }[t] || '📌')

function leaseRef(c) { return c.ref_lease ? { path: '/leases', query: { open: c.ref_lease } } : null }
function detailFields(row) {
  const skip = new Set(['id', 'title', 'ref_lease', 'type', 'status', 'stage'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}

// ── writes ──
const CASE_STATUSES = ['Open', 'Hearing', 'Negotiation', 'Closed', 'Won', 'Lost']
const caseModal = ref(false)
const caseForm = ref({ ref_lease: '', type: 'eviction', title: '', stage: 'Notice', lawyer: '', next_hearing: '', notes: '' })
function openCase() {
  caseForm.value = { ref_lease: data.list('leases')[0]?.id || '', type: 'eviction', title: '', stage: 'Notice', lawyer: '', next_hearing: '', notes: '' }
  caseModal.value = true
}
async function submitCase() {
  const f = caseForm.value
  if (!f.ref_lease) { window.__krToast?.('❌ Select a lease'); return }
  const r = await apiCall('app-legal', { action: 'case-create', ref_lease: f.ref_lease, type: f.type, title: f.title.trim(), stage: f.stage.trim(), lawyer: f.lawyer.trim(), next_hearing: f.next_hearing, notes: f.notes.trim() })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  caseModal.value = false
  window.__krToast?.('✅ ' + (r.id || 'Case') + ' opened', 'ok')
  await data.bootstrap()
}
const updForm = ref({ stage: '', status: 'Open', lawyer: '', next_hearing: '', notes: '', note: '' })
function openUpdate(c) {
  updForm.value = { stage: c.stage || '', status: c.status || 'Open', lawyer: c.lawyer || '', next_hearing: c.next_hearing || '', notes: c.notes || '', note: '' }
}
async function saveUpdate() {
  const f = updForm.value
  const r = await apiCall('app-legal', { action: 'case-update', id: sel.value.id, stage: f.stage.trim(), status: f.status, lawyer: f.lawyer.trim(), next_hearing: f.next_hearing, notes: f.notes.trim(), note: f.note.trim() })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('✅ ' + sel.value.id + ' updated', 'ok')
  await data.bootstrap()
  refreshSel()
}
const evForm = ref({ ev_type: 'note', body: '' })
async function addEvent() {
  const f = evForm.value
  if (!f.body.trim()) { window.__krToast?.('❌ Event body is required'); return }
  const r = await apiCall('app-legal', { action: 'case-event', id: sel.value.id, ev_type: f.ev_type, body: f.body.trim() })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  evForm.value = { ev_type: 'note', body: '' }
  window.__krToast?.('✅ Timeline event added', 'ok')
  await data.bootstrap()
  refreshSel()
}
function refreshSel() {
  if (!sel.value) return
  const fresh = casesAll.value.find(x => x.id === sel.value.id)
  if (fresh) sel.value = fresh
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>⚖️ Cases</h1>
        <div class="sub">{{ casesAll.length }} legal cases · {{ kpis[1]?.value || 0 }} open · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search title, notes, lawyer…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="typeFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All types</option>
          <option v-for="t in typeOptions" :key="t" :value="t">{{ typeMeta(t).label }}</option>
        </select>
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="sortBy" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="opened">Sort: Opened</option>
          <option value="next_hearing">Sort: Next hearing</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
        <button v-if="canManage" @click="openCase" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ New case</button>
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
      <div v-for="c in paged" :key="c.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(c)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">{{ typeMeta(c.type).ico }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="typeMeta(c.type).cls" style="background:#ffffff">{{ typeMeta(c.type).label }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ c.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ c.title }}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="badge(c.status)">{{ c.status || '—' }}</span>
            <span v-if="c.stage" class="badge b-blue">{{ c.stage }}</span>
            <span v-if="c.ref_lease" class="badge b-gray">{{ c.ref_lease }}</span>
          </div>
          <div style="display:flex;gap:13px;font-size:12px;flex-wrap:wrap;margin-top:auto">
            <span class="c-sub">👨‍⚖️ {{ c.lawyer || '—' }}</span>
            <span class="c-sub">📅 {{ fmtDate(c.next_hearing) }} <template v-if="hearingNote(c)">· {{ hearingNote(c) }}</template></span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Case</th><th>Type</th><th>Status</th><th>Stage</th><th>Next hearing</th></tr></thead>
          <tbody>
            <tr v-for="c in paged" :key="c.id" style="cursor:pointer" @click="openDetail(c)">
              <td style="font-weight:700;white-space:nowrap">{{ c.id }}</td>
              <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ c.title }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="typeMeta(c.type).cls">{{ typeMeta(c.type).label }}</span></td>
              <td style="white-space:nowrap"><span class="badge" :class="badge(c.status)">{{ c.status || '—' }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ c.stage || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ fmtDate(c.next_hearing) }} <template v-if="hearingNote(c)">({{ hearingNote(c) }})</template></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No cases found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- new case modal -->
    <template v-if="caseModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="caseModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(500px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">⚖️ Open new case</div>
          <button @click="caseModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 22px;display:flex;flex-direction:column;gap:12px">
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Lease *</div>
            <select v-model="caseForm.ref_lease" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
              <option v-for="l in data.list('leases')" :key="l.id" :value="l.id">{{ l.id }} · rent {{ l.rent }}</option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Type</div>
              <select v-model="caseForm.type" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option value="eviction">🚪 Eviction</option>
                <option value="arrears">💰 Rent arrears</option>
                <option value="damages">🛠️ Damages</option>
                <option value="other">⚖️ Other</option>
              </select>
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Stage</div>
              <input v-model="caseForm.stage" placeholder="e.g. Notice / Filing" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Title</div>
            <input v-model="caseForm.title" placeholder="e.g. Eviction — Sultana Rahman (L-007)" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Lawyer</div>
              <input v-model="caseForm.lawyer" placeholder="Counsel name" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Next hearing</div>
              <input v-model="caseForm.next_hearing" type="date" style="width:100%;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Notes</div>
            <RichEditor v-model="caseForm.notes" placeholder="Optional" :min-height="'100px'" style="margin-top:5px" />
          </div>
          <button @click="submitCase" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">⚖️ Open case</button>
        </div>
      </div>
    </template>

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(620px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">{{ typeMeta(sel.type).ico }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ typeMeta(sel.type).label }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:19px;font-weight:800;letter-spacing:-.3px;line-height:1.35">{{ sel.title }}</h2>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin:12px 0">
            <span class="badge" :class="badge(sel.status)">{{ sel.status || '—' }}</span>
            <span v-if="sel.stage" class="badge b-blue">{{ sel.stage }}</span>
            <button v-if="leaseRef(sel)" class="btn-ghost" style="padding:4px 10px;font-size:12px" @click="go(leaseRef(sel).path, leaseRef(sel).query)">↗ Lease {{ sel.ref_lease }}</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px 18px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Opened</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtDate(sel.opened) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Next hearing</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtDate(sel.next_hearing) }} <template v-if="hearingNote(sel)">({{ hearingNote(sel) }})</template></div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Lawyer</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.lawyer || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Updated</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtTs(sel.updated_at) }}</div>
            </div>
          </div>
          <div v-if="sel.notes" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;font-size:13px;line-height:1.65" v-html="sel.notes"></div>
          <div v-for="[k, v] in detailFields(sel)" :key="k" style="font-size:13px;margin-bottom:8px">
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ k.replace(/_/g, ' ') }}</div>
            <div style="font-weight:600;word-break:break-word;margin-top:1px">{{ String(v) }}</div>
          </div>
          <template v-if="canManage">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin:14px 0">
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">✏️ Update case</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                <div>
                  <div class="c-sub" style="font-size:10.5px;margin-bottom:3px">Stage</div>
                  <input v-model="updForm.stage" placeholder="e.g. Hearing" style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
                </div>
                <div>
                  <div class="c-sub" style="font-size:10.5px;margin-bottom:3px">Status</div>
                  <select v-model="updForm.status" style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-size:12.5px;font-family:inherit;outline:none">
                    <option v-for="s in CASE_STATUSES" :key="s" :value="s">{{ s }}</option>
                  </select>
                </div>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px">
                <input v-model="updForm.lawyer" placeholder="Lawyer" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
                <input v-model="updForm.next_hearing" type="date" style="padding:7px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
              </div>
              <RichEditor v-model="updForm.notes" placeholder="Notes" :min-height="'100px'" style="margin-top:8px" />
              <input v-model="updForm.note" placeholder="Note for the timeline (optional)" style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none;margin-top:8px">
              <button @click="saveUpdate" style="margin-top:10px;width:100%;padding:9px;border:none;border-radius:9px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">💾 Save update</button>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">📝 Add timeline event</div>
              <div style="display:flex;gap:8px">
                <select v-model="evForm.ev_type" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-size:12.5px;font-family:inherit;outline:none">
                  <option value="note">📝 Note</option>
                  <option value="hearing">⚖️ Hearing</option>
                  <option value="stage">🔄 Stage</option>
                  <option value="doc">📎 Doc</option>
                </select>
                <input v-model="evForm.body" placeholder="What happened?" style="flex:1;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
              </div>
              <button @click="addEvent" style="margin-top:9px;width:100%;padding:9px;border:none;border-radius:9px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Add event</button>
            </div>
          </template>
          <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:10px">Timeline · {{ timeline.length }} events</div>
            <div v-if="!timeline.length" class="c-sub" style="font-size:12.5px">No events recorded.</div>
            <div v-for="e in timeline" :key="e.id" style="display:flex;gap:12px;padding:8px 0;border-bottom:1px solid var(--border)">
              <div style="width:30px;height:30px;border-radius:50%;background:var(--bg-alt);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0">{{ evIco(e.ev_type) }}</div>
              <div style="min-width:0">
                <div style="font-size:13px;line-height:1.5;word-break:break-word">{{ e.body }}</div>
                <div class="c-sub" style="font-size:11.5px;margin-top:2px">{{ e.actor || '—' }} · {{ fmtTs(e.ts) }}</div>
              </div>
            </div>
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
