<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { apiCall } from '../api/client'
import { useViewMode, usePager, money, avatarColor, initials, fmtTs, today } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('staff')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const staffAll = computed(() => data.list('building_staff'))
const propsList = computed(() => data.list('properties'))
const propName = (pid) => propsList.value.find(p => p.id === pid)?.name || pid || ''
const role = computed(() => (data.user || {}).role || '')
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'svc_mgr', 'hr'].includes(role.value))

const ROLE_META = {
  guard: { ico: '🚨', cls: 'b-blue', label: 'Guard' },
  cleaner: { ico: '🧹', cls: 'b-gray', label: 'Cleaner' },
  caretaker: { ico: '🏠', cls: 'b-orange', label: 'Caretaker' },
  driver: { ico: '🚗', cls: 'b-blue', label: 'Driver' },
  supervisor: { ico: '🧑‍💼', cls: 'b-green', label: 'Supervisor' },
  security: { ico: '🛡️', cls: 'b-red', label: 'Security' },
  other: { ico: '👤', cls: 'b-gray', label: 'Other' },
}
const roleMeta = (r) => ROLE_META[r] || ROLE_META.other
const SHIFT_META = { day: '☀️ Day', night: '🌙 Night', rotating: '🔄 Rotating' }
const stCls = (s) => s === 'active' ? 'b-green' : (s === 'on_leave' ? 'b-orange' : (s === 'terminated' ? 'b-red' : 'b-gray'))
const stLabel = (s) => s === 'active' ? 'Active' : (s === 'on_leave' ? 'On leave' : (s === 'terminated' ? 'Terminated' : (s || '—')))

// ── property scoping ──
const propFilter = ref('')
const inProp = (s) => !propFilter.value || (s.prop || '') === propFilter.value

// ── KPIs ──
const kpis = computed(() => {
  const ss = staffAll.value.filter(inProp)
  const active = ss.filter(s => s.status === 'active').length
  const leave = ss.filter(s => s.status === 'on_leave').length
  const term = ss.filter(s => s.status === 'terminated').length
  const roles = new Set(ss.map(s => s.role).filter(Boolean)).size
  const avg = ss.length ? Math.round(ss.reduce((a, s) => a + (s.monthly_salary || 0), 0) / ss.length) : 0
  return [
    { label: 'Staff', ico: '👥', value: ss.length, trend: 'building team roster' },
    { label: 'Active', ico: '✅', value: active, trend: active === ss.length ? 'all on duty' : active + ' of ' + ss.length, ok: active >= ss.length * 0.6 },
    { label: 'On leave', ico: '🏖️', value: leave, trend: leave ? 'away from post' : 'none', ok: leave === 0 },
    { label: 'Terminated', ico: '⛔', value: term, trend: term ? 'removed' : 'none', ok: term === 0 },
    { label: 'Roles', ico: '🧑‍💼', value: roles, trend: 'positions covered' },
    { label: 'Avg salary', ico: '💰', value: avg ? money(avg) : '—', trend: 'per month' },
  ]
})

// ── filters ──
const query = ref('')
const roleFilter = ref('')
const statusFilter = ref('')
const roleOptions = computed(() => [...new Set(staffAll.value.map(s => s.role).filter(Boolean))].sort())
const statusOptions = computed(() => [...new Set(staffAll.value.map(s => s.status).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = staffAll.value.filter(inProp)
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(s => JSON.stringify(s).toLowerCase().includes(q))
  if (roleFilter.value) out = out.filter(s => (s.role || '') === roleFilter.value)
  if (statusFilter.value) out = out.filter(s => (s.status || '') === statusFilter.value)
  return [...out].sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'staff.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── staff writes ──
const staffModal = ref(false)
const staffForm = ref({ id: '', name: '', role: 'guard', shift: 'day', phone: '', join_date: today(), monthly_salary: '', prop: '', notes: '' })
function openStaffModal(s) {
  staffForm.value = s
    ? { id: s.id, name: s.name || '', role: s.role || 'guard', shift: s.shift || 'day', phone: s.phone || '', join_date: s.join_date || today(), monthly_salary: s.monthly_salary || '', prop: s.prop || propFilter.value || '', notes: s.notes || '' }
    : { id: '', name: '', role: 'guard', shift: 'day', phone: '', join_date: today(), monthly_salary: '', prop: propFilter.value || propsList.value[0]?.id || '', notes: '' }
  staffModal.value = true
}
async function saveStaff() {
  const f = staffForm.value
  if (!f.name.trim()) { window.__krToast?.('❌ Name is required'); return }
  const body = { action: f.id ? 'staff-save' : 'staff-create', id: f.id, name: f.name.trim(), role: f.role, shift: f.shift, phone: f.phone.trim(), join_date: f.join_date || today(), monthly_salary: parseInt(f.monthly_salary) || 0, prop: f.prop, notes: f.notes.trim() }
  const r = await apiCall('app-staffwatch', body)
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  staffModal.value = false
  window.__krToast?.('✅ Staff saved' + (r.id ? ' · ' + r.id : ''))
  await data.bootstrap()
}
async function setStaffStatus(s, status) {
  if (s.status === status) return
  const r = await apiCall('app-staffwatch', { action: 'staff-status', id: s.id, status })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('✅ ' + s.name + ' → ' + stLabel(status))
  await data.bootstrap()
}
async function delStaff(s) {
  if (!window.confirm('Delete staff ' + s.name + '? This removes their attendance and payroll records too.')) return
  const r = await apiCall('app-staffwatch', { action: 'staff-delete', id: s.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('🗑 Deleted')
  closeDetail()
  await data.bootstrap()
}

// ── drawer ──
const sel = ref(null)
function openDetail(s) { sel.value = s }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const s = staffAll.value.find(x => x.id === id); if (s) openDetail(s) }
}, { immediate: true })
function detailFields(row) {
  const skip = new Set(['id', 'name', 'role', 'shift', 'phone', 'join_date', 'monthly_salary', 'prop', 'status', 'notes', 'owner_email'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>👷 Staff</h1>
        <div class="sub">{{ kpis[0]?.value || 0 }} building staff · {{ kpis[1]?.value || 0 }} active · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <select v-model="propFilter" title="Manage this property" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;font-weight:700;color:var(--text);outline:none">
          <option value="">🏢 All properties</option>
          <option v-for="p in propsList" :key="p.id" :value="p.id">{{ p.id }} · {{ p.name }}</option>
        </select>
        <input v-model="query" placeholder="Search name, phone…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:180px">
        <select v-model="roleFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All roles</option>
          <option v-for="r in roleOptions" :key="r" :value="r">{{ roleMeta(r).label }}</option>
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
      </CompactFilters>
        <button v-if="canManage" @click="openStaffModal()" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Add staff</button>
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
      <div v-for="s in paged" :key="s.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(s)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">{{ roleMeta(s.role).ico }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="stCls(s.status)" style="background:#ffffff">{{ stLabel(s.status) }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ s.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="display:flex;align-items:center;gap:10px">
            <div style="width:34px;height:34px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff" :style="{ background: avatarColor(s.id) }">{{ initials(s.name) }}</div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ s.name || '—' }}</div>
              <div class="c-sub" style="font-size:12px">{{ roleMeta(s.role).label }}<template v-if="s.prop"> · {{ propName(s.prop) }}</template></div>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="roleMeta(s.role).cls">{{ roleMeta(s.role).ico }} {{ roleMeta(s.role).label }}</span>
            <span v-if="s.shift" class="badge b-gray">{{ SHIFT_META[s.shift] || s.shift }}</span>
            <span v-if="s.monthly_salary" class="badge b-orange">{{ money(s.monthly_salary) }}/mo</span>
          </div>
          <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
            <span v-if="s.phone">📞 {{ s.phone }}</span>
            <span>📅 {{ (s.join_date || '').slice(0, 10) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Name</th><th>Role</th><th>Shift</th><th>Phone</th><th>Salary</th><th>Property</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <tr v-for="s in paged" :key="s.id" style="cursor:pointer" @click="openDetail(s)">
              <td style="font-weight:700;white-space:nowrap">{{ s.id }}</td>
              <td style="white-space:nowrap">{{ s.name || '—' }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="roleMeta(s.role).cls">{{ roleMeta(s.role).ico }} {{ roleMeta(s.role).label }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ SHIFT_META[s.shift] || s.shift || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ s.phone || '—' }}</td>
              <td style="white-space:nowrap">{{ money(s.monthly_salary) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ s.prop ? propName(s.prop) : '—' }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="stCls(s.status)">{{ stLabel(s.status) }}</span></td>
              <td style="white-space:nowrap">
                <button v-if="canManage" @click.stop="openStaffModal(s)" title="Edit" style="background:none;border:none;font-size:14px;cursor:pointer">✏️</button>
                <button v-if="canManage" @click.stop="delStaff(s)" title="Delete" style="background:none;border:none;font-size:15px;cursor:pointer">🗑</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No staff found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- staff modal -->
    <template v-if="staffModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="staffModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(480px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">{{ staffForm.id ? '✏️ Edit staff' : '👷 Add staff' }}</div>
          <button @click="staffModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;display:flex;flex-direction:column;gap:13px">
          <div>
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Name *</div>
            <input v-model="staffForm.name" placeholder="Full name" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Role</div>
              <select v-model="staffForm.role" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none">
                <option v-for="(meta, r) in ROLE_META" :key="r" :value="r">{{ meta.ico }} {{ meta.label }}</option>
              </select>
            </div>
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Shift</div>
              <select v-model="staffForm.shift" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none">
                <option v-for="(label, sh) in SHIFT_META" :key="sh" :value="sh">{{ label }}</option>
              </select>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Phone</div>
              <input v-model="staffForm.phone" placeholder="01XXXXXXXXX" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Monthly salary (৳)</div>
              <input v-model="staffForm.monthly_salary" type="number" min="0" placeholder="e.g. 12000" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Join date</div>
              <input v-model="staffForm.join_date" type="date" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Property</div>
              <select v-model="staffForm.prop" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none">
                <option value="">—</option>
                <option v-for="p in propsList" :key="p.id" :value="p.id">{{ p.id }} · {{ p.name }}</option>
              </select>
            </div>
          </div>
          <div>
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Notes</div>
            <input v-model="staffForm.notes" placeholder="e.g. Main gate — day shift" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
          </div>
          <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:4px">
            <button @click="staffModal = false" class="btn-ghost" style="padding:9px 16px;font-size:13px">Cancel</button>
            <button @click="saveStaff" style="padding:9px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Save staff</button>
          </div>
        </div>
      </div>
    </template>

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(560px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">{{ roleMeta(sel.role).ico }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" :class="stCls(sel.status)" style="background:#ffffff">{{ stLabel(sel.status) }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <div style="display:flex;align-items:center;gap:14px">
            <div style="width:58px;height:58px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px;color:#fff" :style="{ background: avatarColor(sel.id) }">{{ initials(sel.name) }}</div>
            <div>
              <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.name || '—' }}</h2>
              <div class="c-sub" style="margin-top:4px;font-size:12.5px">{{ roleMeta(sel.role).label }}<template v-if="sel.prop"> · {{ propName(sel.prop) }}</template></div>
            </div>
          </div>
          <div v-if="canManage" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
            <button class="btn-ghost" style="padding:8px 14px;font-size:12.5px;font-weight:700" @click="openStaffModal(sel)">✏️ Edit</button>
            <button v-if="sel.status !== 'active'" style="padding:8px 14px;font-size:12.5px;font-weight:700;border:none;border-radius:10px;background:var(--ok);color:#fff;cursor:pointer" @click="setStaffStatus(sel, 'active')">✅ Activate</button>
            <button v-if="sel.status !== 'on_leave'" style="padding:8px 14px;font-size:12.5px;font-weight:700;border:none;border-radius:10px;background:rgba(230,126,34,.15);color:var(--warn);cursor:pointer" @click="setStaffStatus(sel, 'on_leave')">🏖 On leave</button>
            <button v-if="sel.status !== 'terminated'" style="padding:8px 14px;font-size:12.5px;font-weight:700;border:none;border-radius:10px;background:rgba(231,76,60,.12);color:var(--danger);cursor:pointer" @click="setStaffStatus(sel, 'terminated')">⛔ Terminate</button>
            <button style="padding:8px 14px;font-size:12.5px;font-weight:700;border:none;border-radius:10px;background:rgba(231,76,60,.12);color:var(--danger);cursor:pointer" @click="delStaff(sel)">🗑 Delete</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px 18px;margin-top:16px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Phone</div>
              <div style="font-weight:700;margin-top:1px"><a v-if="sel.phone" :href="'tel:' + sel.phone" style="color:var(--primary)">{{ sel.phone }}</a><template v-else>—</template></div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Shift</div>
              <div style="font-weight:700;margin-top:1px">{{ SHIFT_META[sel.shift] || sel.shift || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Salary</div>
              <div style="font-weight:700;margin-top:1px">{{ money(sel.monthly_salary) }}/mo</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Joined</div>
              <div style="font-weight:700;margin-top:1px">{{ (sel.join_date || '').slice(0, 10) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Property</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.prop ? propName(sel.prop) : '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Owner</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.owner_email || '—' }}</div>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin:14px 0">
            <button class="btn-ghost" style="padding:6px 12px;font-size:12px" @click="go('/staff-attendance')">⏱️ Attendance</button>
            <button class="btn-ghost" style="padding:6px 12px;font-size:12px" @click="go('/staff-payroll')">💰 Payroll</button>
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
