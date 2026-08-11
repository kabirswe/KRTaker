<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useViewMode, usePager, money, fmtTs } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('concierge')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const reqAll = computed(() => data.list('concierge_requests'))
const parcelName = (pid) => data.list('land_parcels').find(p => p.id === pid)?.district || pid || ''
const propName = (pid) => data.list('properties').find(p => p.id === pid)?.name || pid || ''

const SERVICE_META = {
  e_porcha: { ico: '📜', label: 'e-Porcha' },
  namjari: { ico: '🗺️', label: 'Namjari' },
  holding_tax: { ico: '🏛️', label: 'Holding tax' },
  mutation: { ico: '🔄', label: 'Mutation' },
  khatian: { ico: '🧾', label: 'Khatian' },
  porcha: { ico: '📜', label: 'Porcha' },
}
const svc = (s) => SERVICE_META[s] || { ico: '🛎️', label: s || 'Service' }
const stCls = (s) => s === 'Completed' ? 'b-green' : (s === 'In_Progress' || s === 'Under_Review' ? 'b-blue' : (s === 'Awaiting_Fee' ? 'b-orange' : (s === 'Rejected' ? 'b-red' : 'b-gray')))
const stLabel = (s) => String(s || '—').replace(/_/g, ' ')
const parseTimeline = (r) => { try { const t = JSON.parse(r.timeline || '[]'); return Array.isArray(t) ? t : [] } catch { return [] } }

// ── KPIs ──
const kpis = computed(() => {
  const rs = reqAll.value
  const active = rs.filter(r => r.status === 'In_Progress' || r.status === 'Under_Review').length
  const awaitFee = rs.filter(r => r.status === 'Awaiting_Fee')
  const done = rs.filter(r => r.status === 'Completed').length
  const collected = rs.filter(r => r.fee_status === 'paid').reduce((s, r) => s + (r.fee || 0), 0)
  const districts = new Set(rs.map(r => r.district).filter(Boolean)).size
  return [
    { label: 'Requests', ico: '🛎️', value: rs.length, trend: 'land concierge services' },
    { label: 'In progress', ico: '⏳', value: active, trend: active ? 'under review / working' : 'none', ok: active === 0 },
    { label: 'Awaiting fee', ico: '🧾', value: awaitFee.length, trend: awaitFee.length ? money(awaitFee.reduce((s, r) => s + (r.fee || 0), 0)) + ' pending' : 'none', ok: awaitFee.length === 0 },
    { label: 'Completed', ico: '✅', value: done, trend: done ? 'delivered' : 'none yet', ok: done > 0 },
    { label: 'Fees collected', ico: '💵', value: money(collected), trend: 'paid service fees' },
    { label: 'Districts', ico: '📍', value: districts, trend: 'service coverage' },
  ]
})

// ── filters ──
const query = ref('')
const statusFilter = ref('')
const svcFilter = ref('')
const statusOptions = computed(() => [...new Set(reqAll.value.map(r => r.status).filter(Boolean))].sort())
const svcOptions = computed(() => [...new Set(reqAll.value.map(r => r.service).filter(Boolean))].sort())
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
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'concierge.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(r) { sel.value = r }
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
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🛎️ Concierge</h1>
        <div class="sub">{{ reqAll.length }} service requests · {{ kpis[3]?.value || 0 }} completed · live from API</div>
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

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
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
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No requests found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

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
          <div v-if="sel.notes" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;font-size:13px;line-height:1.65">{{ sel.notes }}</div>
          <div v-if="parseTimeline(sel).length" style="margin:14px 0">
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">Activity · {{ parseTimeline(sel).length }} events</div>
            <div style="display:flex;flex-direction:column;gap:0">
              <div v-for="(e, i) in parseTimeline(sel)" :key="i" style="display:flex;gap:12px;padding:8px 0;border-bottom:1px dashed var(--border)">
                <div style="font-size:15px;line-height:1.4">🕓</div>
                <div style="flex:1">
                  <div style="font-size:13px;font-weight:600;line-height:1.45">{{ e.action }}</div>
                  <div class="c-sub" style="font-size:11.5px;margin-top:2px">{{ e.by || '—' }} · {{ fmtTs(e.ts) }}</div>
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
  </div>
</template>

<style scoped>
.d-cover .badge { background: #ffffff; }
</style>
