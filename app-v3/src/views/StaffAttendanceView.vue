<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useViewMode, usePager } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('staff-attendance')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const attAll = computed(() => data.list('staff_attendance'))
const staffName = (id) => {
  if (!id) return '—'
  const bs = data.list('building_staff').find(s => s.id === id)
  if (bs) return bs.name
  const st = data.list('staff').find(s => s.id === id)
  return st ? st.name : id
}
const stCls = (s) => s === 'present' ? 'b-green' : (s === 'absent' ? 'b-red' : (s === 'late' ? 'b-orange' : 'b-gray'))
const stLabel = (s) => s === 'present' ? 'Present' : (s === 'absent' ? 'Absent' : (s === 'late' ? 'Late' : (s || '—')))
const fmtDate = (d) => { if (!d) return '—'; const t = new Date(String(d).slice(0, 10) + 'T00:00:00'); return isNaN(t) ? String(d).slice(0, 10) : t.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) }
function hoursWorked(a) {
  if (!a.check_in || !a.check_out || a.status !== 'present') return null
  const [hi, mi] = a.check_in.split(':').map(Number)
  const [ho, mo] = a.check_out.split(':').map(Number)
  let m = (ho * 60 + mo) - (hi * 60 + mi)
  if (m < 0) m += 1440
  return (m / 60).toFixed(1) + 'h'
}

// ── KPIs ──
const kpis = computed(() => {
  const as = attAll.value
  const present = as.filter(a => a.status === 'present').length
  const absent = as.filter(a => a.status === 'absent').length
  const late = as.filter(a => a.status === 'late').length
  const staffN = new Set(as.map(a => a.staff).filter(Boolean)).size
  const days = new Set(as.map(a => (a.work_date || '').slice(0, 7)).filter(Boolean)).size
  const last = [...as].sort((a, b) => String(b.work_date).localeCompare(String(a.work_date)))[0]
  const rate = as.length ? Math.round((present / as.length) * 100) : 0
  return [
    { label: 'Records', ico: '👷', value: as.length, trend: 'attendance entries' },
    { label: 'Present', ico: '✅', value: present, trend: rate + '% attendance rate' },
    { label: 'Absent', ico: '❌', value: absent, trend: absent ? 'missed shifts' : 'none absent', ok: absent <= 2 },
    { label: 'Late', ico: '⏰', value: late, trend: late ? 'late check-ins' : 'none late' },
    { label: 'Staff', ico: '👥', value: staffN, trend: 'people tracked' },
    { label: 'Months', ico: '🗓️', value: days, trend: last ? 'latest ' + fmtDate(last.work_date) : '' },
  ]
})

// ── filters ──
const query = ref('')
const statusFilter = ref('')
const staffFilter = ref('')
const statusOptions = computed(() => [...new Set(attAll.value.map(a => a.status).filter(Boolean))].sort())
const staffOptions = computed(() => [...new Set(attAll.value.map(a => a.staff).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = attAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(a => JSON.stringify(a).toLowerCase().includes(q) || (staffName(a.staff) || '').toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(a => (a.status || '') === statusFilter.value)
  if (staffFilter.value) out = out.filter(a => (a.staff || '') === staffFilter.value)
  return [...out].sort((a, b) => String(b.work_date || '').localeCompare(String(a.work_date || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 15)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[\",\n]/.test(s) ? '\"' + s.replace(/\"/g, '\"\"') + '\"' : s }
  const lines = [['id', 'staff', 'staff_name', 'work_date', 'check_in', 'check_out', 'status', 'notes'].map(esc).join(',')]
  rows.forEach(a => lines.push([a.id, a.staff, staffName(a.staff), a.work_date, a.check_in, a.check_out, a.status, a.notes].map(esc).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'staff-attendance.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(a) { sel.value = a }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const a = attAll.value.find(x => x.id === id); if (a) openDetail(a) }
}, { immediate: true })
function detailFields(row) {
  const skip = new Set(['id', 'staff', 'work_date', 'status'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>👷 Staff Attendance</h1>
        <div class="sub">{{ attAll.length }} records · {{ kpis[1]?.value || 0 }} present · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search staff, notes…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:200px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ stLabel(s) }}</option>
        </select>
        <select v-model="staffFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All staff</option>
          <option v-for="s in staffOptions" :key="s" :value="s">{{ staffName(s) }}</option>
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
    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px">
      <div v-for="a in paged" :key="a.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(a)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">{{ a.status === 'present' ? '✅' : (a.status === 'absent' ? '❌' : '⏰') }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="stCls(a.status)" style="background:#ffffff">{{ stLabel(a.status) }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ a.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px">{{ staffName(a.staff) }}</div>
          <div class="c-sub" style="font-size:12px">📅 {{ fmtDate(a.work_date) }}</div>
          <div style="display:flex;gap:10px;font-size:12.5px;flex-wrap:wrap">
            <span class="badge b-gray">🕐 {{ a.check_in || '—' }} → {{ a.check_out || '—' }}</span>
            <span v-if="hoursWorked(a)" class="badge b-blue">{{ hoursWorked(a) }}</span>
          </div>
          <div v-if="a.notes" class="c-sub" style="font-size:12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-top:auto">{{ a.notes }}</div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Staff</th><th>Date</th><th>Check-in</th><th>Check-out</th><th>Hours</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="a in paged" :key="a.id" style="cursor:pointer" @click="openDetail(a)">
              <td style="font-weight:700;white-space:nowrap">{{ a.id }}</td>
              <td style="white-space:nowrap">{{ staffName(a.staff) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ fmtDate(a.work_date) }}</td>
              <td style="white-space:nowrap">{{ a.check_in || '—' }}</td>
              <td style="white-space:nowrap">{{ a.check_out || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ hoursWorked(a) || '—' }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="stCls(a.status)">{{ stLabel(a.status) }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No attendance records found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(540px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">{{ sel.status === 'present' ? '✅' : (sel.status === 'absent' ? '❌' : '⏰') }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ stLabel(sel.status) }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ staffName(sel.staff) }}</h2>
          <div class="c-sub" style="margin-top:4px;font-size:12.5px">📅 {{ fmtDate(sel.work_date) }}</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px 18px;margin-top:14px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Check-in</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.check_in || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Check-out</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.check_out || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Hours</div>
              <div style="font-weight:700;margin-top:1px">{{ hoursWorked(sel) || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Staff ID</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.staff || '—' }}</div>
            </div>
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
  </div>
</template>

<style scoped>
.d-cover .badge { background: #ffffff; }
</style>
