<script setup>
import { computed, ref, watch } from 'vue'
import { t } from '../lib/i18n'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { useViewMode, usePager } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('gate-visits')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))
const visitsAll = computed(() => data.list('gate_visits'))
const propsAll = computed(() => data.list('properties'))
const propName = (pid) => propsAll.value.find(p => p.id === pid)?.name || pid || ''
const unitName = (uid) => data.list('units').find(u => u.id === uid)?.name || uid || ''

const TYPE_META = {
  visitor: { ico: '🚶', label: 'Visitor', cls: 'b-blue' },
  delivery: { ico: '📦', label: 'Delivery', cls: 'b-orange' },
  worker: { ico: '🛠️', label: 'Worker', cls: 'b-green' },
  vendor: { ico: '🧰', label: 'Vendor', cls: 'b-purple' },
  vehicle: { ico: '🚗', label: 'Vehicle', cls: 'b-gray' },
  default: { ico: '🚪', label: 'Entry', cls: 'b-gray' },
}
const typeMeta = (t) => TYPE_META[t] || TYPE_META.default
const fmtTs = (ts) => { if (!ts) return '—'; const d = new Date(String(ts).replace(' ', 'T')); if (isNaN(d)) return String(ts).slice(0, 16); return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) }

// ── KPIs ──
const kpis = computed(() => {
  const vs = visitsAll.value
  const inside = vs.filter(v => v.status === 'Inside').length
  const out = vs.filter(v => v.status === 'Out').length
  const flagged = vs.filter(v => v.flagged).length
  const types = new Set(vs.map(v => v.vtype).filter(Boolean)).size
  const today = new Date().toISOString().slice(0, 10)
  const tCount = vs.filter(v => (v.check_in || '').startsWith(today) || (v.ts || '').startsWith(today)).length
  return [
    { label: 'Visits', ico: '🚪', value: vs.length, trend: 'gate entries logged' },
    { label: 'Inside', ico: '🟦', value: inside, trend: inside ? 'on the premises now' : 'none inside', ok: inside <= 3 },
    { label: 'Out', ico: '✅', value: out, trend: 'checked out' },
    { label: 'Flagged', ico: '🚩', value: flagged, trend: flagged ? 'watchlist matches!' : 'no watchlist hits', ok: flagged === 0 },
    { label: 'Types', ico: '🗂️', value: types, trend: 'visitor · delivery · worker · vendor · vehicle' },
    { label: 'Today', ico: '📅', value: tCount, trend: 'entries today' },
  ]
})

// ── filters ──
const query = ref('')
const typeFilter = ref('')
const statusFilter = ref('')
const flaggedOnly = ref(false)
const typeOptions = computed(() => [...new Set(visitsAll.value.map(v => v.vtype).filter(Boolean))].sort())
const statusOptions = computed(() => [...new Set(visitsAll.value.map(v => v.status).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = visitsAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(v => JSON.stringify(v).toLowerCase().includes(q) || (propName(v.prop) || '').toLowerCase().includes(q))
  if (typeFilter.value) out = out.filter(v => (v.vtype || '') === typeFilter.value)
  if (statusFilter.value) out = out.filter(v => (v.status || '') === statusFilter.value)
  if (flaggedOnly.value) out = out.filter(v => v.flagged)
  return [...out].sort((a, b) => String(b.check_in || b.ts || '').localeCompare(String(a.check_in || a.ts || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'gate-visits.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── check-in modal ──
const cinModal = ref(false)
const cinForm = ref({ prop: '', vtype: 'visitor', name: '', phone: '', vehicle_no: '', unit: '', purpose: '', host_name: '', notes: '' })
function openCheckIn() {
  cinForm.value = { prop: propsAll.value[0]?.id || '', vtype: 'visitor', name: '', phone: '', vehicle_no: '', unit: '', purpose: '', host_name: '', notes: '' }
  cinModal.value = true
}
async function submitCheckIn() {
  const f = cinForm.value
  if (!f.prop) { window.__krToast?.('❌ Select a property'); return }
  if (!f.name.trim() && !f.vehicle_no.trim()) { window.__krToast?.('❌ Name or vehicle number required'); return }
  const r = await apiCall('app-gate', { action: 'visit-create', prop: f.prop, vtype: f.vtype, name: f.name.trim(), phone: f.phone.trim(), vehicle_no: f.vehicle_no.trim(), unit: f.unit, purpose: f.purpose.trim(), host_name: f.host_name.trim(), notes: f.notes.trim() })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  cinModal.value = false
  window.__krToast?.(r.flagged ? '🚩 ' + (r.id || 'Entry') + ' checked in — WATCHLIST MATCH!' : '✅ ' + (r.id || 'Entry') + ' checked in', r.flagged ? 'error' : 'ok')
  await data.bootstrap()
}

// ── drawer ──
const sel = ref(null)
function openDetail(v) { sel.value = v }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const v = visitsAll.value.find(x => x.id === id); if (v) openDetail(v) }
}, { immediate: true })
function propRef(v) { return v.prop ? { path: '/properties', query: { open: v.prop } } : null }
function unitRef(v) { return v.unit ? { path: '/units', query: { open: v.unit } } : null }
function detailFields(row) {
  const skip = new Set(['id', 'prop', 'vtype', 'name', 'status', 'check_in', 'check_out', 'flagged'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
async function checkOut(v) {
  if (v.status !== 'Inside') { window.__krToast?.('Already checked out'); return }
  const r = await apiCall('app-gate', { action: 'visit-out', id: v.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('✅ ' + v.id + ' checked out', 'ok')
  await data.bootstrap()
  if (sel.value) { const fresh = visitsAll.value.find(x => x.id === sel.value.id); if (fresh) sel.value = fresh }
}
async function delVisit(v) {
  if (!window.confirm('Delete gate entry ' + v.id + ' (' + (v.name || v.vehicle_no || '') + ')?')) return
  const r = await apiCall('app-gate', { action: 'visit-delete', id: v.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('🗑 Deleted')
  closeDetail()
  await data.bootstrap()
}

// ── watchlist ──
const wlOpen = ref(false)
const wlItems = ref([])
const wlForm = ref({ vehicle_no: '', name: '', reason: '' })
async function loadWatchlist() {
  const r = await apiCall('app-gate', { action: 'watchlist-list' })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  wlItems.value = r.watchlist || []
  wlOpen.value = true
}
async function addWatch() {
  const f = wlForm.value
  if (!f.vehicle_no.trim() && !f.name.trim()) { window.__krToast?.('❌ Vehicle or name required'); return }
  const r = await apiCall('app-gate', { action: 'watchlist-create', vehicle_no: f.vehicle_no.trim(), name: f.name.trim(), reason: f.reason.trim() })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  wlForm.value = { vehicle_no: '', name: '', reason: '' }
  window.__krToast?.('🚨 Added ' + (r.id || ''), 'ok')
  await loadWatchlist()
  await data.bootstrap()
}
async function delWatch(w) {
  if (!window.confirm('Remove ' + (w.vehicle_no || w.name || w.id) + ' from watchlist?')) return
  const r = await apiCall('app-gate', { action: 'watchlist-delete', id: w.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('🗑 Removed')
  await loadWatchlist()
  await data.bootstrap()
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('🚪 Gate Visits') }}</h1>
        <div class="sub">{{ visitsAll.length }} entries · {{ kpis[1]?.value || 0 }} inside · {{ kpis[3]?.value || 0 }} flagged</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" :placeholder="t('Search name, vehicle, property…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:200px">
        <select v-model="typeFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All types') }}</option>
          <option v-for="t in typeOptions" :key="t" :value="t">{{ typeMeta(t).label }}</option>
        </select>
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All statuses') }}</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <button @click="flaggedOnly = !flaggedOnly" class="btn-ghost" :style="flaggedOnly ? 'background:var(--danger);color:#fff;border-color:var(--danger)' : ''" :title="t('Watchlist matches only')">🚩 {{ flaggedOnly ? 'Flagged only' : 'All entries' }}</button>
        <button @click="loadWatchlist" class="btn-ghost" :title="t('Watchlist')">🚨 {{ t('Watchlist') }}</button>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" :title="t('Download CSV')">⬇ CSV</button>
      </CompactFilters>
        <button @click="openCheckIn" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ {{ t('Check-in') }}</button>
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
      <div v-for="v in paged" :key="v.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(v)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">{{ typeMeta(v.vtype).ico }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="typeMeta(v.vtype).cls" style="background:#ffffff">{{ typeMeta(v.vtype).label }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ v.id }}</div>
          <div v-if="v.flagged" style="position:absolute;top:10px;right:12px;font-size:20px" :title="t('Watchlist match')">🚩</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ v.name || '—' }}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span v-if="v.prop" class="badge b-blue">{{ propName(v.prop) }}</span>
            <span v-if="v.vehicle_no" class="badge b-gray">🚗 {{ v.vehicle_no }}</span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="v.status === 'Inside' ? 'b-blue' : 'b-gray'">{{ v.status || '—' }}</span>
            <span v-if="v.unit" class="badge b-gray">{{ unitName(v.unit) }}</span>
          </div>
          <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
            <span>🕐 {{ fmtTs(v.check_in) }}</span>
            <span v-if="v.check_out">→ {{ fmtTs(v.check_out) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>{{ t('ID') }}</th><th>{{ t('Visitor') }}</th><th>{{ t('Type') }}</th><th>{{ t('Property') }}</th><th>{{ t('Vehicle') }}</th><th>{{ t('Check-in') }}</th><th>{{ t('Status') }}</th><th v-if="canManage">{{ t('Action') }}</th></tr></thead>
          <tbody>
            <tr v-for="v in paged" :key="v.id" style="cursor:pointer" @click="openDetail(v)">
              <td style="font-weight:700;white-space:nowrap">{{ v.id }} <template v-if="v.flagged">🚩</template></td>
              <td style="white-space:nowrap">{{ v.name || '—' }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="typeMeta(v.vtype).cls">{{ typeMeta(v.vtype).label }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ v.prop ? propName(v.prop) : '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ v.vehicle_no || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ fmtTs(v.check_in) }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="v.status === 'Inside' ? 'b-blue' : 'b-gray'">{{ v.status || '—' }}</span></td>
              <td v-if="canManage" style="white-space:nowrap">
                <button v-if="v.status === 'Inside'" @click.stop="checkOut(v)" :title="t('Check out')" style="background:none;border:none;font-size:14px;cursor:pointer">🚪</button>
                <button @click.stop="delVisit(v)" :title="t('Delete')" style="background:none;border:none;font-size:15px;cursor:pointer">🗑</button>
              </td>
            </tr>
            <tr v-if="!filtered.length"><td :colspan="canManage ? 8 : 7" style="text-align:center;color:var(--text-mute);padding:30px">{{ t('No visits found.') }}</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No visits found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- check-in modal -->
    <template v-if="cinModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="cinModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(500px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">🚪 {{ t('Check-in') }} entry</div>
          <button @click="cinModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 22px;display:flex;flex-direction:column;gap:12px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Property *</div>
              <select v-model="cinForm.prop" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option v-for="p in propsAll" :key="p.id" :value="p.id">{{ p.id }} · {{ p.name }}</option>
              </select>
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Type') }}</div>
              <select v-model="cinForm.vtype" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option v-for="(m, k) in TYPE_META" :key="k" :value="k">{{ m.ico }} {{ m.label }}</option>
              </select>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Name') }}</div>
              <input v-model="cinForm.name" :placeholder="t('Visitor name')" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Phone') }}</div>
              <input v-model="cinForm.phone" placeholder="01XXXXXXXXX" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Vehicle no') }}</div>
              <input v-model="cinForm.vehicle_no" :placeholder="t('DHAKA-METRO-1234')" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Unit') }}</div>
              <select v-model="cinForm.unit" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option value="">— none —</option>
                <option v-for="u in data.list('units')" :key="u.id" :value="u.id">{{ u.name }}</option>
              </select>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Purpose') }}</div>
              <input v-model="cinForm.purpose" placeholder="Meeting, delivery…" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Host') }}</div>
              <input v-model="cinForm.host_name" :placeholder="t('Host name')" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Notes') }}</div>
            <textarea v-model="cinForm.notes" rows="2" :placeholder="t('Optional')" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none;resize:vertical"></textarea>
          </div>
          <button @click="submitCheckIn" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">🚪 Check in</button>
          <div class="c-sub" style="font-size:11px;text-align:center">Auto-flagged 🚩 if vehicle/name matches the watchlist</div>
        </div>
      </div>
    </template>

    <!-- watchlist modal -->
    <template v-if="wlOpen">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="wlOpen = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(540px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">🚨 Gate watchlist <span class="badge b-danger" style="margin-left:8px">{{ wlItems.length }}</span></div>
          <button @click="wlOpen = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 22px;max-height:72vh;overflow-y:auto">
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 14px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">➕ Add to watchlist</div>
            <div style="display:flex;flex-direction:column;gap:7px">
              <input v-model="wlForm.vehicle_no" :placeholder="t('Vehicle number (e.g. DHAKA-METRO-9999)')" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
              <input v-model="wlForm.name" :placeholder="t('Person / name')" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
              <input v-model="wlForm.reason" :placeholder="t('Reason (e.g. suspicious activity)')" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
              <button @click="addWatch" style="padding:9px;border:none;border-radius:9px;background:var(--danger);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">🚨 Add to watchlist</button>
            </div>
          </div>
          <div v-if="!wlItems.length" style="padding:28px;text-align:center;color:var(--text-mute)">{{ t('Watchlist is empty.') }}</div>
          <div v-for="w in wlItems" :key="w.id" style="display:flex;gap:10px;align-items:flex-start;background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:11px 13px;margin-bottom:8px">
            <div style="font-size:19px">🚨</div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:800;font-size:13px">{{ w.vehicle_no || '—' }}<template v-if="w.name"> · {{ w.name }}</template></div>
              <div class="c-sub" style="font-size:11.5px;margin-top:2px">{{ w.reason || '—' }}</div>
            </div>
            <div style="display:flex;flex-direction:column;gap:5px;align-items:flex-end">
              <span class="badge" :class="w.active ? 'b-danger' : 'b-gray'">{{ w.active ? 'Active' : 'Off' }}</span>
              <button v-if="canManage" @click="delWatch(w)" :title="t('Remove')" style="background:none;border:none;font-size:14px;cursor:pointer">🗑</button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(580px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">{{ typeMeta(sel.vtype).ico }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ typeMeta(sel.vtype).label }}</span>
            <span v-if="sel.flagged" class="badge" style="background:#ffffff;color:var(--danger)">🚩 {{ t('Watchlist') }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.name || '—' }}</h2>
          <div class="c-sub" style="margin-top:4px;font-size:12.5px">{{ sel.vtype || '—' }} · created by {{ sel.created_by || '—' }}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin:12px 0">
            <span class="badge" :class="sel.status === 'Inside' ? 'b-blue' : 'b-gray'">{{ sel.status || '—' }}</span>
            <button v-if="propRef(sel)" class="btn-ghost" style="padding:4px 10px;font-size:12px" @click="go(propRef(sel).path, propRef(sel).query)">↗ {{ propName(sel.prop) }}</button>
            <button v-if="unitRef(sel)" class="btn-ghost" style="padding:4px 10px;font-size:12px" @click="go(unitRef(sel).path, unitRef(sel).query)">↗ {{ unitName(sel.unit) }}</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px 18px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Check-in') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtTs(sel.check_in) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Check-out') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtTs(sel.check_out) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Phone') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.phone || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Vehicle') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.vehicle_no || '—' }}</div>
            </div>
            <div v-if="sel.purpose" style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Purpose') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.purpose }}</div>
            </div>
            <div v-if="sel.host_name" style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Host') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.host_name }}</div>
            </div>
          </div>
          <div v-if="sel.notes" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;font-size:13px;line-height:1.65">{{ sel.notes }}</div>
          <div v-for="[k, v] in detailFields(sel)" :key="k" style="font-size:13px;margin-bottom:8px">
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ k.replace(/_/g, ' ') }}</div>
            <div style="font-weight:600;word-break:break-word;margin-top:1px">{{ String(v) }}</div>
          </div>
          <div v-if="canManage" style="display:flex;gap:8px;margin-top:16px">
            <button v-if="sel.status === 'Inside'" @click="checkOut(sel)" style="flex:1;padding:10px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">🚪 {{ t('Check out') }}</button>
            <button @click="delVisit(sel)" style="flex:1;padding:10px;border:none;border-radius:10px;background:var(--danger);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">🗑 {{ t('Delete') }} entry</button>
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
