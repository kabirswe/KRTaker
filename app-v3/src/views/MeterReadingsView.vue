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
const viewMode = useViewMode('meter-readings')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))
const readingsAll = computed(() => data.list('meter_readings'))
const unitsAll = computed(() => data.list('units'))
const tenantsAll = computed(() => data.list('tenants'))

const unitName = (uid) => unitsAll.value.find(u => u.id === uid)?.name || uid || '—'
const unitProp = (uid) => unitsAll.value.find(u => u.id === uid)?.p || ''
const propName = (pid) => data.list('properties').find(p => p.id === pid)?.name || pid || ''
const tenantName = (tid) => tenantsAll.value.find(t => t.id === tid)?.name || tid || ''
const TYPE_META = { electric: { ico: '⚡', label: 'Electric' }, water: { ico: '💧', label: 'Water' }, gas: { ico: '🔥', label: 'Gas' } }
const typeMeta = (t) => TYPE_META[t] || { ico: '🧾', label: t || 'Other' }
const UNIT_LABEL = { electric: 'kWh', water: 'gal', gas: 'm³' }
const unitLabel = (t) => UNIT_LABEL[t] || ''
function monthLabel(m) { if (!m) return '—'; const [y, mo] = m.split('-'); const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']; return (names[parseInt(mo, 10) - 1] || mo) + ' ' + y }
function curMonth() { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') }

// ── KPIs ──
const kpis = computed(() => {
  const rs = readingsAll.value
  const units = new Set(rs.map(r => r.unit).filter(Boolean)).size
  const months = new Set(rs.map(r => r.month).filter(Boolean))
  const latest = months.size ? [...months].sort().reverse()[0] : ''
  const lCount = latest ? rs.filter(r => r.month === latest).length : 0
  const byType = {}
  rs.forEach(r => { const t = r.type || 'other'; byType[t] = (byType[t] || 0) + 1 })
  const topType = Object.entries(byType).sort((a, b) => b[1] - a[1])[0]
  return [
    { label: 'Readings', ico: '📏', value: rs.length, trend: 'meter entries' },
    { label: 'Units covered', ico: '🚪', value: units, trend: 'metered units' },
    { label: 'Months', ico: '📅', value: months.size, trend: latest ? 'latest ' + monthLabel(latest) : '' },
    { label: 'Latest month', ico: '🆕', value: monthLabel(latest), trend: lCount + ' readings logged' },
    { label: 'Top type', ico: typeMeta(topType?.[0]).ico, value: topType ? typeMeta(topType[0]).label : '—', trend: topType ? topType[1] + ' readings' : '' },
    { label: 'Logged', ico: '🕒', value: rs.filter(r => r.ts).length, trend: 'with timestamps' },
  ]
})

// ── filters / sort ──
const query = ref('')
const typeFilter = ref('')
const monthFilter = ref('')
const sortBy = ref('month')
const typeOptions = computed(() => [...new Set(readingsAll.value.map(r => r.type).filter(Boolean))].sort())
const monthOptions = computed(() => [...new Set(readingsAll.value.map(r => r.month).filter(Boolean))].sort().reverse())
const filtered = computed(() => {
  let out = readingsAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(r => r.id.toLowerCase().includes(q) || unitName(r.unit).toLowerCase().includes(q) || tenantName(r.tenant).toLowerCase().includes(q) || (r.note || '').toLowerCase().includes(q))
  if (typeFilter.value) out = out.filter(r => r.type === typeFilter.value)
  if (monthFilter.value) out = out.filter(r => r.month === monthFilter.value)
  const get = (r) => sortBy.value === 'reading' ? (r.reading || 0) : (r.month || '')
  return [...out].sort((a, b) => typeof get(a) === 'string' ? String(get(b)).localeCompare(String(get(a))) : get(b) - get(a))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)
function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const lines = [['id', 'unit', 'tenant', 'type', 'reading', 'month', 'note', 'ts'].map(esc).join(',')]
  rows.forEach(r => lines.push([r.id, unitName(r.unit), tenantName(r.tenant), r.type, r.reading, r.month, r.note, r.ts].map(esc).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'meter-readings.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── record reading modal ──
const recModal = ref(false)
const recForm = ref({ unit: '', type: 'electric', month: curMonth(), reading: '', note: '' })
function openRecord() {
  recForm.value = { unit: unitsAll.value[0]?.id || '', type: 'electric', month: curMonth(), reading: '', note: '' }
  recModal.value = true
}
async function submitReading() {
  const f = recForm.value
  if (!f.unit) { window.__krToast?.('❌ Select a unit'); return }
  const n = parseInt(f.reading, 10)
  if (isNaN(n) || n < 0) { window.__krToast?.('❌ Enter a valid reading'); return }
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(f.month)) { window.__krToast?.('❌ Month must be YYYY-MM'); return }
  const r = await apiCall('app-meter-submit', { unit: f.unit, type: f.type, reading: n, month: f.month, note: f.note.trim() })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  recModal.value = false
  window.__krToast?.('✅ Reading saved · ' + (r.id || ''), 'ok')
  await data.bootstrap()
}

// ── drawer ──
const sel = ref(null)
function openDetail(r) { sel.value = r }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const r = readingsAll.value.find(x => x.id === id); if (r) openDetail(r) }
}, { immediate: true })
const selUnit = computed(() => sel.value ? unitsAll.value.find(u => u.id === sel.value.unit) : null)
const selTenant = computed(() => sel.value ? tenantsAll.value.find(t => t.id === sel.value.tenant) : null)
// previous reading for same unit+type (to show consumption delta)
const prevReading = computed(() => {
  if (!sel.value) return null
  const rs = readingsAll.value.filter(r => r.unit === sel.value.unit && r.type === sel.value.type && r.id !== sel.value.id && (r.month || '') < (sel.value.month || ''))
  return rs.sort((a, b) => String(b.month).localeCompare(String(a.month)))[0] || null
})
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>📏 Meter readings</h1>
        <div class="sub">{{ readingsAll.length }} readings · {{ kpis[1]?.value || 0 }} units covered · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search reading, unit, tenant…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:200px">
        <select v-model="typeFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All types</option>
          <option v-for="t in typeOptions" :key="t" :value="t">{{ typeMeta(t).label }}</option>
        </select>
        <select v-model="monthFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All months</option>
          <option v-for="m in monthOptions" :key="m" :value="m">{{ monthLabel(m) }}</option>
        </select>
        <select v-model="sortBy" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="month">Sort: Month</option>
          <option value="reading">Sort: Reading</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
        <button v-if="canManage" @click="openRecord" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Record reading</button>
      </div>
    </div>

    <div class="stats">
      <div v-for="k in kpis" :key="k.label" class="stat">
        <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
        <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
        <div class="s-trend">{{ k.trend }}</div>
      </div>
    </div>

    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px">
      <div v-for="r in paged" :key="r.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(r)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">{{ typeMeta(r.type).ico }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" style="background:#ffffff">{{ typeMeta(r.type).label }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ r.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div>
            <div style="font-weight:800;font-size:18px;letter-spacing:-.3px">{{ (r.reading ?? '—').toLocaleString('en-IN') }} <span class="c-sub" style="font-size:12px;font-weight:600">{{ unitLabel(r.type) }}</span></div>
            <div class="c-sub" style="margin-top:2px">🚪 {{ unitName(r.unit) }} · 📅 {{ monthLabel(r.month) }}</div>
          </div>
          <div style="display:flex;gap:13px;font-size:12px;flex-wrap:wrap">
            <span class="c-sub">👤 {{ tenantName(r.tenant) || '—' }}</span>
            <span class="c-sub">🕒 {{ (r.ts || '').slice(0, 10) || '—' }}</span>
          </div>
          <div v-if="r.note" style="font-size:11.5px;color:var(--text-mute);line-height:1.45;margin-top:auto">📝 {{ r.note }}</div>
        </div>
      </div>
    </div>
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>Reading</th><th>Type</th><th>Unit</th><th>Property</th><th>Tenant</th><th>Month</th><th>Value</th><th>Note</th></tr></thead>
          <tbody>
            <tr v-for="r in paged" :key="r.id" style="cursor:pointer" @click="openDetail(r)">
              <td style="white-space:nowrap"><b>{{ r.id }}</b></td>
              <td style="white-space:nowrap">{{ typeMeta(r.type).ico }} {{ typeMeta(r.type).label }}</td>
              <td style="white-space:nowrap"><a @click.stop="go('/units', { open: r.unit })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ unitName(r.unit) }}</a></td>
              <td style="white-space:nowrap" class="c-sub">{{ propName(unitProp(r.unit)) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ tenantName(r.tenant) || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ monthLabel(r.month) }}</td>
              <td style="font-weight:700;white-space:nowrap">{{ (r.reading ?? '—').toLocaleString('en-IN') }} {{ unitLabel(r.type) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ r.note || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No meter readings found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- record reading modal -->
    <template v-if="recModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="recModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(460px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">📏 Record meter reading</div>
          <button @click="recModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 22px;display:flex;flex-direction:column;gap:12px">
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Unit *</div>
            <select v-model="recForm.unit" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
              <option v-for="u in unitsAll" :key="u.id" :value="u.id">{{ u.name }} · {{ propName(u.p) }}</option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Type</div>
              <select v-model="recForm.type" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option v-for="(m, k) in TYPE_META" :key="k" :value="k">{{ m.ico }} {{ m.label }}</option>
              </select>
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Month *</div>
              <input v-model="recForm.month" type="month" style="width:100%;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Reading *</div>
            <input v-model="recForm.reading" type="number" min="0" placeholder="e.g. 5120" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:14px;font-weight:700;outline:none">
            <div class="c-sub" style="font-size:11px;margin-top:4px">Cannot be lower than the previous reading for this unit + type.</div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Note</div>
            <input v-model="recForm.note" placeholder="Optional — e.g. owner reading" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          </div>
          <button @click="submitReading" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">💾 Save reading</button>
          <div class="c-sub" style="font-size:11px;text-align:center">Saving again for the same unit + type + month updates the value (UPSERT).</div>
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
            <span class="badge" style="background:#ffffff">{{ typeMeta(sel.type).label }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ typeMeta(sel.type).label }} reading · {{ monthLabel(sel.month) }}</h2>
          <div class="c-sub" style="margin-top:3px">🚪 <a @click.stop="go('/units', { open: sel.unit })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ unitName(sel.unit) }}</a><template v-if="selTenant"> · 👤 <a @click.stop="go('/tenants', { open: sel.tenant })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ selTenant.name }}</a></template></div>

          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Reading</div>
              <div style="font-size:15px;font-weight:800;margin-top:2px">{{ (sel.reading ?? '—').toLocaleString('en-IN') }} {{ unitLabel(sel.type) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Month</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ monthLabel(sel.month) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Type</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ typeMeta(sel.type).label }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Logged</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ (sel.ts || '').slice(0, 10) || '—' }}</div>
            </div>
          </div>

          <div v-if="prevReading" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">📈 Consumption vs {{ monthLabel(prevReading.month) }}</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;font-size:12.5px">
              <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:9px 11px;text-align:center">
                <div class="c-sub" style="font-size:10px;text-transform:uppercase">Previous</div>
                <b>{{ (prevReading.reading ?? '—').toLocaleString('en-IN') }}</b>
              </div>
              <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:9px 11px;text-align:center">
                <div class="c-sub" style="font-size:10px;text-transform:uppercase">Current</div>
                <b>{{ (sel.reading ?? '—').toLocaleString('en-IN') }}</b>
              </div>
              <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:9px 11px;text-align:center">
                <div class="c-sub" style="font-size:10px;text-transform:uppercase">Used</div>
                <b style="color:var(--primary)">{{ Math.max(0, (sel.reading || 0) - (prevReading.reading || 0)).toLocaleString('en-IN') }} {{ unitLabel(sel.type) }}</b>
              </div>
            </div>
          </div>

          <div v-if="sel.note" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">📝 Note</div>
            <div style="font-size:12.5px">{{ sel.note }}</div>
          </div>

          <div v-if="selUnit" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">🚪 Unit</div>
            <div style="font-weight:800;font-size:14px;cursor:pointer" @click="go('/units', { open: sel.unit })">{{ selUnit.name }} ↗</div>
            <div class="c-sub" style="font-size:11.5px;margin-top:3px">{{ selUnit.sqft || '—' }} sqft · rent {{ (selUnit.rent || 0).toLocaleString('en-IN') }}/mo</div>
          </div>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* White badges only on cover/header areas (same convention as Properties/Units) */
.d-cover .badge { background: #ffffff; }
</style>
