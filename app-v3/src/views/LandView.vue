<script setup>
import { computed, ref, watch } from 'vue'
import { t } from '../lib/i18n'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useViewMode, usePager, fmtTs } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('land')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const parcelsAll = computed(() => data.list('land_parcels'))
const eventsAll = computed(() => data.list('land_events'))

const STATUS_CLS = { Secure: 'b-green', 'Needs Review': 'b-orange', Encroached: 'b-red', default: 'b-gray' }
const stCls = (s) => STATUS_CLS[s] || STATUS_CLS.default
const RISK_CLS = { low: 'b-green', medium: 'b-orange', high: 'b-red', default: 'b-gray' }
const MONITOR_LABEL = { quarterly: 'Quarterly', semi_annual: 'Semi-annual', annual: 'Annual', default: '—' }
const EV_ICO = { created: '🆕', visit: '📍', note: '📝', alert: '🚨', default: '📌' }
const areaDecimal = (a) => { const m = String(a || '').match(/([\d.]+)/); return m ? parseFloat(m[1]) : 0 }

// ── KPIs ──
const kpis = computed(() => {
  const ps = parcelsAll.value
  const secure = ps.filter(p => p.status === 'Secure').length
  const review = ps.filter(p => p.status === 'Needs Review').length
  const encroached = ps.filter(p => p.status === 'Encroached').length
  const totalArea = ps.reduce((s, p) => s + areaDecimal(p.area), 0)
  const districts = new Set(ps.map(p => p.district).filter(Boolean)).size
  return [
    { label: 'Parcels', ico: '🛰️', value: ps.length, trend: 'land parcels tracked' },
    { label: 'Secure', ico: '✅', value: secure, trend: 'no concerns' },
    { label: t('Needs review'), ico: '⚠️', value: review, trend: review ? 'field check needed' : 'none', ok: review === 0 },
    { label: 'Encroached', ico: '🚨', value: encroached, trend: encroached ? 'HIGH PRIORITY' : 'none', ok: encroached === 0 },
    { label: 'Area', ico: '📐', value: totalArea ? totalArea + ' dec' : '—', trend: 'total declared' },
    { label: 'Districts', ico: '🗺️', value: districts, trend: 'jurisdictions' },
  ]
})

// ── filters ──
const query = ref('')
const statusFilter = ref('')
const districtFilter = ref('')
const statusOptions = computed(() => [...new Set(parcelsAll.value.map(p => p.status).filter(Boolean))].sort())
const districtOptions = computed(() => [...new Set(parcelsAll.value.map(p => p.district).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = parcelsAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(p => JSON.stringify(p).toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(p => (p.status || '') === statusFilter.value)
  if (districtFilter.value) out = out.filter(p => (p.district || '') === districtFilter.value)
  return [...out].sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[\",\n]/.test(s) ? '\"' + s.replace(/\"/g, '\"\"') + '\"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'land.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(p) { sel.value = p }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const p = parcelsAll.value.find(x => x.id === id); if (p) openDetail(p) }
}, { immediate: true })
const timeline = computed(() => {
  if (!sel.value) return []
  return eventsAll.value
    .filter(e => e.parcel === sel.value.id)
    .sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || '')))
})
function mapsUrl(p) { return (p.lat && p.lng) ? 'https://maps.google.com/?q=' + p.lat + ',' + p.lng : null }
function detailFields(row) {
  const skip = new Set(['id', 'name', 'status', 'risk', 'notes', 'lat', 'lng', 'monitor'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('🛰️ Land Guard') }}</h1>
        <div class="sub">{{ parcelsAll.length }} parcels · {{ kpis[3]?.value || 0 }} encroached · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" :placeholder="t('Search name, khatian, dag…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All statuses') }}</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="districtFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All districts') }}</option>
          <option v-for="d in districtOptions" :key="d" :value="d">{{ d }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" :title="t('Download CSV')">⬇ CSV</button>
      </CompactFilters>
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
      <div v-for="p in paged" :key="p.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(p)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">🛰️</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="stCls(p.status)" style="background:#ffffff">{{ p.status || '—' }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ p.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ p.name }}</div>
          <div class="c-sub" style="font-size:12px">{{ p.district || '—' }}<template v-if="p.upazila"> · {{ p.upazila }}</template><template v-if="p.mouza"> · {{ p.mouza }}</template></div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="RISK_CLS[p.risk] || 'b-gray'">Risk: {{ p.risk || '—' }}</span>
            <span v-if="p.area" class="badge b-gray">📐 {{ p.area }}</span>
            <span class="badge b-blue">{{ MONITOR_LABEL[p.monitor] || '—' }}</span>
          </div>
          <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
            <span>🗂 {{ p.khatian || '—' }}</span>
            <span>📍 {{ p.dag || '—' }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>{{ t('Parcel') }}</th><th>{{ t('District') }}</th><th>{{ t('Khatian') }}</th><th>{{ t('Dag') }}</th><th>{{ t('Area') }}</th><th>{{ t('Status') }}</th><th>{{ t('Risk') }}</th></tr></thead>
          <tbody>
            <tr v-for="p in paged" :key="p.id" style="cursor:pointer" @click="openDetail(p)">
              <td style="font-weight:700;white-space:nowrap">{{ p.id }}</td>
              <td style="white-space:nowrap">{{ p.name }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ p.district || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ p.khatian || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ p.dag || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ p.area || '—' }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="stCls(p.status)">{{ p.status || '—' }}</span></td>
              <td style="white-space:nowrap"><span class="badge" :class="RISK_CLS[p.risk] || 'b-gray'">{{ p.risk || '—' }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No parcels found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(620px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">🛰️</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.status || '—' }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.name }}</h2>
          <div class="c-sub" style="margin-top:4px;font-size:12.5px">{{ sel.district || '—' }}<template v-if="sel.upazila"> · {{ sel.upazila }}</template><template v-if="sel.mouza"> · {{ sel.mouza }}</template></div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin:12px 0">
            <span class="badge" :class="RISK_CLS[sel.risk] || 'b-gray'">Risk: {{ sel.risk || '—' }}</span>
            <span class="badge b-blue">{{ MONITOR_LABEL[sel.monitor] || '—' }}</span>
            <a v-if="mapsUrl(sel)" :href="mapsUrl(sel)" target="_blank" rel="noopener" style="text-decoration:none"><span class="badge b-green" style="cursor:pointer">🗺️ View on map</span></a>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px 18px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Area') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.area || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Khatian') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.khatian || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Dag') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.dag || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Monitor') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ MONITOR_LABEL[sel.monitor] || '—' }}</div>
            </div>
            <div v-if="sel.lat && sel.lng" style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Coordinates') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.lat }}, {{ sel.lng }}</div>
            </div>
          </div>
          <div v-if="sel.notes" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;font-size:13px;line-height:1.65">{{ sel.notes }}</div>
          <div v-for="[k, v] in detailFields(sel)" :key="k" style="font-size:13px;margin-bottom:8px">
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t(k.replace(/_/g, ' ')) }}</div>
            <div style="font-weight:600;word-break:break-word;margin-top:1px">{{ String(v) }}</div>
          </div>
          <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:10px">Activity · {{ timeline.length }} events</div>
            <div v-if="!timeline.length" class="c-sub" style="font-size:12.5px">{{ t('No events recorded.') }}</div>
            <div v-for="e in timeline" :key="e.id" style="display:flex;gap:12px;padding:8px 0;border-bottom:1px solid var(--border)">
              <div style="width:30px;height:30px;border-radius:50%;background:var(--bg-alt);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0">{{ EV_ICO[e.ev_type] || EV_ICO.default }}</div>
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
