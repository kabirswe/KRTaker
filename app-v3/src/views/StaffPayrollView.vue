<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useViewMode, usePager, money, monthLabel } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('staff-payroll')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const pyAll = computed(() => data.list('staff_payroll'))
const staffName = (id) => {
  if (!id) return '—'
  const bs = data.list('building_staff').find(s => s.id === id)
  if (bs) return bs.name
  const st = data.list('staff').find(s => s.id === id)
  return st ? st.name : id
}
const stCls = (s) => s === 'Paid' ? 'b-green' : (s === 'Draft' ? 'b-orange' : (s === 'Processing' ? 'b-blue' : 'b-gray'))
const netOf = (p) => (p.salary || 0) + (p.overtime || 0) + (p.bonus || 0) - (p.advance_deduction || 0)
const fmtDate = (d) => { if (!d) return '—'; const t = new Date(String(d).slice(0, 10) + 'T00:00:00'); return isNaN(t) ? String(d).slice(0, 10) : t.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) }

// ── KPIs ──
const kpis = computed(() => {
  const ps = pyAll.value
  const net = ps.reduce((s, p) => s + (p.net ?? netOf(p)), 0)
  const paid = ps.filter(p => p.status === 'Paid').length
  const paidAmt = ps.filter(p => p.status === 'Paid').reduce((s, p) => s + (p.net ?? netOf(p)), 0)
  const draft = ps.filter(p => p.status === 'Draft').length
  const months = new Set(ps.map(p => p.month).filter(Boolean)).size
  const avg = ps.length ? Math.round(net / ps.length) : 0
  return [
    { label: 'Payrolls', ico: '💰', value: ps.length, trend: 'payrun entries' },
    { label: 'Net total', ico: '💸', value: money(net), trend: 'across ' + months + ' month(s)' },
    { label: 'Paid', ico: '✅', value: paid, trend: paid ? money(paidAmt) + ' settled' : 'none paid', ok: paid > 0 },
    { label: 'Draft', ico: '📝', value: draft, trend: draft ? 'awaiting approval' : 'none in draft', ok: draft === 0 },
    { label: 'Months', ico: '🗓️', value: months, trend: 'payroll periods' },
    { label: 'Avg net', ico: '📊', value: money(avg), trend: 'per payslip' },
  ]
})

// ── filters ──
const query = ref('')
const statusFilter = ref('')
const monthFilter = ref('')
const statusOptions = computed(() => [...new Set(pyAll.value.map(p => p.status).filter(Boolean))].sort())
const monthOptions = computed(() => [...new Set(pyAll.value.map(p => p.month).filter(Boolean))].sort().reverse())
const filtered = computed(() => {
  let out = pyAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(p => JSON.stringify(p).toLowerCase().includes(q) || (staffName(p.staff) || '').toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(p => (p.status || '') === statusFilter.value)
  if (monthFilter.value) out = out.filter(p => (p.month || '') === monthFilter.value)
  return [...out].sort((a, b) => String(b.month || '').localeCompare(String(a.month || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[\",\n]/.test(s) ? '\"' + s.replace(/\"/g, '\"\"') + '\"' : s }
  const lines = [['id', 'staff', 'staff_name', 'month', 'salary', 'overtime', 'bonus', 'advance_deduction', 'absent_days', 'daily_rate', 'net', 'status', 'paid_at'].map(esc).join(',')]
  rows.forEach(p => lines.push([p.id, p.staff, staffName(p.staff), p.month, p.salary, p.overtime, p.bonus, p.advance_deduction, p.absent_days, p.daily_rate, p.net ?? netOf(p), p.status, p.paid_at].map(esc).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'staff-payroll.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(p) { sel.value = p }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const p = pyAll.value.find(x => x.id === id); if (p) openDetail(p) }
}, { immediate: true })
function detailFields(row) {
  const skip = new Set(['id', 'staff', 'month', 'status', 'salary', 'overtime', 'bonus', 'advance_deduction', 'absent_days', 'daily_rate', 'net'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>💰 Staff Payroll</h1>
        <div class="sub">{{ pyAll.length }} payslips · {{ kpis[2]?.value || 0 }} paid · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search staff…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:200px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="monthFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All months</option>
          <option v-for="m in monthOptions" :key="m" :value="m">{{ monthLabel(m) }}</option>
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
      <div v-for="p in paged" :key="p.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(p)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:30px">💵</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="stCls(p.status)" style="background:#ffffff">{{ p.status || '—' }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:13px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ money(p.net ?? netOf(p)) }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px">{{ staffName(p.staff) }}</div>
          <div class="c-sub" style="font-size:12px">{{ p.id }} · {{ monthLabel(p.month) }}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
            <span class="badge b-gray">Salary {{ money(p.salary) }}</span>
            <span v-if="p.overtime" class="badge b-blue">+OT {{ money(p.overtime) }}</span>
            <span v-if="p.bonus" class="badge b-green">+Bonus {{ money(p.bonus) }}</span>
            <span v-if="p.advance_deduction" class="badge b-red">−Adv {{ money(p.advance_deduction) }}</span>
          </div>
          <div style="display:flex;gap:13px;font-size:11.5px" class="c-sub">
            <span v-if="p.paid_at">✅ paid {{ fmtDate(p.paid_at) }}</span>
            <span v-else>📝 not yet paid</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Staff</th><th>Month</th><th>Salary</th><th>OT</th><th>Bonus</th><th>Deduction</th><th>Net</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="p in paged" :key="p.id" style="cursor:pointer" @click="openDetail(p)">
              <td style="font-weight:700;white-space:nowrap">{{ p.id }}</td>
              <td style="white-space:nowrap">{{ staffName(p.staff) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ monthLabel(p.month) }}</td>
              <td style="white-space:nowrap">{{ money(p.salary) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ money(p.overtime) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ money(p.bonus) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ p.advance_deduction ? '−' + money(p.advance_deduction) : '—' }}</td>
              <td style="white-space:nowrap;font-weight:700">{{ money(p.net ?? netOf(p)) }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="stCls(p.status)">{{ p.status || '—' }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No payslips found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(560px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:44px">💵</div>
          <div style="position:absolute;left:20px;top:36px;right:60px;text-align:center">
            <div style="color:#fff;font-weight:800;font-size:26px;text-shadow:0 2px 6px rgba(0,0,0,.4)">{{ money(sel.net ?? netOf(sel)) }}</div>
          </div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ monthLabel(sel.month) }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ staffName(sel.staff) }}</h2>
          <div class="c-sub" style="margin-top:4px;font-size:12.5px">{{ sel.id }} · {{ monthLabel(sel.month) }} <template v-if="sel.paid_at">· ✅ paid {{ fmtDate(sel.paid_at) }}</template></div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin:14px 0">
            <span class="badge" :class="stCls(sel.status)">{{ sel.status || '—' }}</span>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px 18px;border-top:1px solid var(--border);padding-top:14px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Base salary</div>
              <div style="font-weight:700;margin-top:1px">{{ money(sel.salary) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Overtime</div>
              <div style="font-weight:700;margin-top:1px;color:var(--ok)">{{ sel.overtime ? '+' + money(sel.overtime) : '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Bonus</div>
              <div style="font-weight:700;margin-top:1px;color:var(--ok)">{{ sel.bonus ? '+' + money(sel.bonus) : '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Advance deduction</div>
              <div style="font-weight:700;margin-top:1px;color:var(--danger)">{{ sel.advance_deduction ? '−' + money(sel.advance_deduction) : '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Absent days</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.absent_days ?? '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Daily rate</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.daily_rate ? money(sel.daily_rate) + '/day' : '—' }}</div>
            </div>
          </div>
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:12px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Net pay</span>
            <span style="font-weight:800;font-size:17px">{{ money(sel.net ?? netOf(sel)) }}</span>
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
