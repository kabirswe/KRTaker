<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { badge, useViewMode, usePager } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('leads')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
// Server-side app-leads gate: superadmin, owner, manager, crm
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'crm'].includes(auth.user?.role || ''))
const leadsAll = computed(() => data.list('leads'))

const SOURCE_ICO = { listing: '🏠', referral: '🤝', other: '📣', walkin: '🚶', social: '📱', default: '📥' }
const sourceIco = (s) => SOURCE_ICO[s] || SOURCE_ICO.default
const STATUS_ORDER = ['New', 'Contacted', 'Viewing', 'Applied', 'Leased', 'Lost']
const propName = (pid) => data.list('properties').find(p => p.id === pid)?.name || pid || ''
const fmtTs = (ts) => { if (!ts) return '—'; return String(ts).replace('T', ' ').slice(0, 16) }

// ── KPIs ──
const kpis = computed(() => {
  const ls = leadsAll.value
  const fresh = ls.filter(l => l.status === 'New').length
  const active = ls.filter(l => ['Contacted', 'Viewing', 'Applied'].includes(l.status)).length
  const won = ls.filter(l => l.status === 'Leased').length
  const lost = ls.filter(l => l.status === 'Lost').length
  const thisM = new Date().toISOString().slice(0, 7)
  const mNew = ls.filter(l => (l.ts || '').startsWith(thisM) && l.status === 'New').length
  const conv = ls.length ? Math.round((won / ls.length) * 100) : 0
  return [
    { label: 'Leads', ico: '📥', value: ls.length, trend: 'total captured' },
    { label: 'New', ico: '🆕', value: fresh, trend: fresh ? 'awaiting first contact' : 'all worked', ok: fresh <= 3 },
    { label: 'Active', ico: '🔄', value: active, trend: 'contacted · viewing · applied' },
    { label: 'Leased', ico: '✅', value: won, trend: conv + '% conversion' },
    { label: 'Lost', ico: '❌', value: lost, trend: lost ? 'did not convert' : 'none lost', ok: lost === 0 },
    { label: 'New this mo', ico: '📅', value: mNew, trend: 'captured in ' + thisM.slice(0, 7) },
  ]
})

// ── filters / sort ──
const query = ref('')
const statusFilter = ref('')
const sourceFilter = ref('')
const statusOptions = computed(() => STATUS_ORDER.filter(s => leadsAll.value.some(l => l.status === s)))
const sourceOptions = computed(() => [...new Set(leadsAll.value.map(l => l.source).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = leadsAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(l => JSON.stringify(l).toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(l => (l.status || '') === statusFilter.value)
  if (sourceFilter.value) out = out.filter(l => (l.source || '') === sourceFilter.value)
  const rank = (l) => STATUS_ORDER.indexOf(l.status) === -1 ? 99 : STATUS_ORDER.indexOf(l.status)
  return [...out].sort((a, b) => rank(a) - rank(b) || String(b.ts || '').localeCompare(String(a.ts || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[\",\n]/.test(s) ? '\"' + s.replace(/\"/g, '\"\"') + '\"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'leads.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(l) { sel.value = l }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const l = leadsAll.value.find(x => x.id === id); if (l) openDetail(l) }
}, { immediate: true })

// ── status / assign (app-leads) ──
const busy = ref('')
async function setStatus(l, status) {
  busy.value = l.id + ':' + status
  try {
    const r = await apiCall('app-leads', { action: 'status', id: l.id, status })
    if (r.ok) { window.__krToast?.(`${l.id} → ${status}`, 'ok'); await data.bootstrap(); if (sel.value) sel.value = leadsAll.value.find(x => x.id === sel.value.id) || sel.value }
    else window.__krToast?.(r.error || 'Update failed', 'error')
  } finally { busy.value = '' }
}
async function assignLead(l) {
  busy.value = l.id + ':assign'
  try {
    const r = await apiCall('app-leads', { action: 'assign', id: l.id, assigned_to: l.assigned_to || '', notes: l.notes || '' })
    if (r.ok) { window.__krToast?.(`${l.id} assigned`, 'ok'); await data.bootstrap(); if (sel.value) sel.value = leadsAll.value.find(x => x.id === sel.value.id) || sel.value }
    else window.__krToast?.(r.error || 'Assign failed', 'error')
  } finally { busy.value = '' }
}

function propRef(l) { return l.prop ? { path: '/properties', query: { open: l.prop } } : null }
function detailFields(row) {
  const skip = new Set(['id', 'name', 'status', 'source', 'message', 'assigned_to', 'notes'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>📥 Leads</h1>
        <div class="sub">{{ leadsAll.length }} leads · {{ kpis[1]?.value || 0 }} new · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search name, email, message…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="sourceFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All sources</option>
          <option v-for="s in sourceOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
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
    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px">
      <div v-for="l in paged" :key="l.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(l)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">{{ sourceIco(l.source) }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="badge(l.status)" style="background:#ffffff">{{ l.status || 'New' }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ l.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="display:flex;align-items:center;gap:9px">
            <div style="min-width:0">
              <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ l.name }}</div>
              <div class="c-sub" style="font-size:12px;margin-top:1px">{{ l.phone || '—' }} · {{ l.email || '—' }}</div>
            </div>
          </div>
          <div class="c-sub" style="font-size:12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.5">{{ l.message || '—' }}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span v-if="l.prop" class="badge b-blue">{{ propName(l.prop) }}</span>
            <span v-if="l.source" class="badge b-gray">{{ l.source }}</span>
            <span v-if="l.assigned_to" class="badge b-orange">👤 {{ l.assigned_to }}</span>
          </div>
          <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
            <span>🕒 {{ (l.ts || '').slice(0, 10) }}</span>
            <span v-if="l.updated_at">↻ {{ (l.updated_at || '').slice(0, 10) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Lead</th><th>Source</th><th>Property</th><th>Status</th><th>Assigned</th></tr></thead>
          <tbody>
            <tr v-for="l in paged" :key="l.id" style="cursor:pointer" @click="openDetail(l)">
              <td style="font-weight:700;white-space:nowrap">{{ l.id }}</td>
              <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <b>{{ l.name }}</b>
                <span class="c-sub"> · {{ l.phone || '—' }}</span>
              </td>
              <td style="white-space:nowrap"><span class="badge b-gray">{{ sourceIco(l.source) }} {{ l.source || '—' }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ l.prop ? propName(l.prop) : '—' }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="badge(l.status)">{{ l.status || '—' }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ l.assigned_to || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No leads found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(580px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">{{ sourceIco(sel.source) }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.status || 'New' }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.name }}</h2>
          <div class="c-sub" style="margin-top:4px;font-size:12.5px">{{ sourceIco(sel.source) }} {{ sel.source || '—' }} · 🕒 {{ fmtTs(sel.ts) }}</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:8px 18px;margin-top:14px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Phone</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.phone || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Email</div>
              <div style="font-weight:700;margin-top:1px;word-break:break-word">{{ sel.email || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Property</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.prop ? propName(sel.prop) : '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Captured</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtTs(sel.ts) }}</div>
            </div>
            <div v-if="sel.budget" style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Budget</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.budget }}</div>
            </div>
            <div v-if="sel.move_in" style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Move-in</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.move_in }}</div>
            </div>
          </div>
          <div v-if="sel.message" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;font-size:13px;line-height:1.65">{{ sel.message }}</div>

          <!-- manage block -->
          <div v-if="canManage" style="border-top:1px solid var(--border);padding-top:14px;margin-top:4px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:9px">Update pipeline</div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <button v-for="s in STATUS_ORDER" :key="s" class="btn-ghost" :style="sel.status === s ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : ''" :disabled="busy === sel.id + ':' + s" style="padding:6px 11px;font-size:12px" @click="setStatus(sel, s)">{{ s }}</button>
            </div>
            <div style="margin-top:13px;display:flex;gap:8px;align-items:center">
              <input v-model="sel.assigned_to" placeholder="Assign to (e.g. Arif Chowdhury)" style="flex:1;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
              <button class="btn-primary" style="padding:8px 14px;font-size:12px" :disabled="busy === sel.id + ':assign'" @click="assignLead(sel)">{{ busy === sel.id + ':assign' ? 'Saving…' : 'Save' }}</button>
            </div>
            <input v-model="sel.notes" placeholder="Notes…" style="width:100%;margin-top:8px;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
            <button v-if="propRef(sel)" class="btn-ghost" style="padding:7px 12px;font-size:12px;margin-top:10px" @click="go(propRef(sel).path, propRef(sel).query)">↗ {{ propName(sel.prop) }}</button>
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
