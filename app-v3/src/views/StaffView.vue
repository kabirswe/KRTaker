<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { badge, useViewMode, usePager, initials, avatarColor } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('staff')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const staffAll = computed(() => data.list('staff'))

// ── KPIs ──
const kpis = computed(() => {
  const ss = staffAll.value
  const active = ss.filter(s => (s.status || '').toLowerCase() === 'active').length
  const prob = ss.filter(s => (s.status || '').toLowerCase().includes('probation')).length
  const depts = new Set(ss.map(s => s.dept).filter(Boolean)).size
  const roles = new Set(ss.map(s => s.role).filter(Boolean)).size
  return [
    { label: 'Staff', ico: '👥', value: ss.length, trend: 'team members' },
    { label: 'Active', ico: '✅', value: active, trend: active === ss.length ? 'all onboard' : active + ' of ' + ss.length + ' active', ok: active >= ss.length * 0.6 },
    { label: 'Departments', ico: '🏢', value: depts, trend: 'teams across the org' },
    { label: 'Roles', ico: '🧑‍💼', value: roles, trend: 'distinct positions' },
    { label: 'Probation', ico: '🕐', value: prob, trend: prob ? 'in probation period' : 'none in probation', ok: prob === 0 },
    { label: 'Status', ico: '🟢', value: active ? 'Healthy' : '—', trend: 'team is staffed' },
  ]
})

// ── filters / sort ──
const query = ref('')
const deptFilter = ref('')
const statusFilter = ref('')
const sortBy = ref('name')
const depts = computed(() => [...new Set(staffAll.value.map(s => s.dept).filter(Boolean))].sort())
const statuses = computed(() => [...new Set(staffAll.value.map(s => s.status).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = staffAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(s => JSON.stringify(s).toLowerCase().includes(q))
  if (deptFilter.value) out = out.filter(s => (s.dept || '') === deptFilter.value)
  if (statusFilter.value) out = out.filter(s => (s.status || '') === statusFilter.value)
  return [...out].sort((a, b) => String(a[sortBy.value] || '').localeCompare(String(b[sortBy.value] || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[\",\n]/.test(s) ? '\"' + s.replace(/\"/g, '\"\"') + '\"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'staff.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(s) { sel.value = s }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const s = staffAll.value.find(x => x.id === id); if (s) openDetail(s) }
}, { immediate: true })

function detailFields(row) {
  const skip = new Set(['id', 'name'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>👥 Staff</h1>
        <div class="sub">{{ staffAll.length }} team members · {{ kpis[1]?.value || 0 }} active · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search name, role, dept…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="deptFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All departments</option>
          <option v-for="d in depts" :key="d" :value="d">{{ d }}</option>
        </select>
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="sortBy" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="name">Sort: Name</option>
          <option value="role">Sort: Role</option>
          <option value="dept">Sort: Dept</option>
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
    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:16px">
      <div v-for="s in paged" :key="s.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(s)">
        <div style="height:86px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:30px;font-weight:800;color:#fff;text-shadow:0 2px 6px rgba(0,0,0,.35)">👥</div>
          <div style="position:absolute;top:10px;left:12px">
            <span class="badge" style="background:#ffffff">{{ s.status || 'Active' }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ s.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:10px">
          <div style="display:flex;align-items:center;gap:11px">
            <div :style="{ width:'44px', height:'44px', borderRadius:'50%', background: avatarColor(s.id), color:'#fff', display:'flex', alignItems:'center', justifyContent:'center', fontWeight:'800', fontSize:'15px', flexShrink:0 }">{{ initials(s.name) }}</div>
            <div style="min-width:0">
              <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ s.name }}</div>
              <div class="c-sub" style="font-size:12px;margin-top:2px">{{ s.role || '—' }}</div>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
            <span class="badge" :class="badge(s.status)">{{ s.status || '—' }}</span>
            <span v-if="s.dept" class="badge b-blue">{{ s.dept }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Name</th><th>Role</th><th>Dept</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="s in paged" :key="s.id" style="cursor:pointer" @click="openDetail(s)">
              <td style="font-weight:700;white-space:nowrap">{{ s.id }}</td>
              <td>
                <div style="display:flex;align-items:center;gap:9px">
                  <div :style="{ width:'28px', height:'28px', borderRadius:'50%', background: avatarColor(s.id), color:'#fff', display:'flex', alignItems:'center', justifyContent:'center', fontWeight:'800', fontSize:'11px', flexShrink:0 }">{{ initials(s.name) }}</div>
                  <span style="font-weight:700">{{ s.name }}</span>
                </div>
              </td>
              <td class="c-sub">{{ s.role || '—' }}</td>
              <td><span class="badge b-blue">{{ s.dept || '—' }}</span></td>
              <td><span class="badge" :class="badge(s.status)">{{ s.status || '—' }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No staff found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(560px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:20px;top:26px;display:flex;align-items:center;gap:14px">
            <div :style="{ width:'58px', height:'58px', borderRadius:'50%', background: avatarColor(sel.id), color:'#fff', display:'flex', alignItems:'center', justifyContent:'center', fontWeight:'800', fontSize:'20px', border:'3px solid rgba(255,255,255,.5)' }">{{ initials(sel.name) }}</div>
            <div>
              <div style="color:#fff;font-weight:800;font-size:19px;letter-spacing:-.3px;text-shadow:0 1px 4px rgba(0,0,0,.4)">{{ sel.name }}</div>
              <div style="color:rgba(255,255,255,.85);font-size:12.5px;margin-top:2px">{{ sel.role || '—' }}</div>
            </div>
          </div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:20px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.dept || '—' }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:8px 18px;margin-top:2px">
            <div v-for="[k, v] in detailFields(sel)" :key="k" style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ k.replace(/_/g, ' ') }}</div>
              <div style="font-weight:700;word-break:break-word;margin-top:1px">{{ String(v) }}</div>
            </div>
          </div>
          <div style="margin-top:18px;border-top:1px solid var(--border);padding-top:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:9px">Related</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn-ghost" style="padding:8px 13px;font-size:12.5px" @click="go('/staff-attendance')">👷 Attendance</button>
              <button class="btn-ghost" style="padding:8px 13px;font-size:12.5px" @click="go('/staff-payroll')">💰 Payroll</button>
              <button class="btn-ghost" style="padding:8px 13px;font-size:12.5px" @click="go('/notices')">📢 Notices</button>
            </div>
          </div>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>
  </div>
</template>
