<script setup>
import { computed, ref, watch } from 'vue'
import { t } from '../lib/i18n'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { apiCall } from '../api/client'
import { useViewMode, usePager, money, monthLabel, today } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'

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
const role = computed(() => (data.user || {}).role || '')
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'svc_mgr', 'hr'].includes(role.value))
const staffList = computed(() => data.list('building_staff'))

// ── payroll writes ──
const pyModal = ref(false)
const pyForm = ref({ staff: '', month: today().slice(0, 7), overtime: '', bonus: '', advance_deduction: '' })
function openPyModal(p) {
  pyForm.value = p
    ? { staff: p.staff || '', month: p.month || today().slice(0, 7), overtime: p.overtime || '', bonus: p.bonus || '', advance_deduction: p.advance_deduction || '' }
    : { staff: staffList.value[0]?.id || '', month: today().slice(0, 7), overtime: '', bonus: '', advance_deduction: '' }
  pyModal.value = true
}
async function generatePayroll() {
  const f = pyForm.value
  if (!f.staff) { window.__krToast?.('❌ Select staff'); return }
  if (!f.month) { window.__krToast?.('❌ Month is required'); return }
  const r = await apiCall('app-staffwatch', { action: 'payroll-create', staff: f.staff, month: f.month, overtime: parseInt(f.overtime) || 0, bonus: parseInt(f.bonus) || 0, advance_deduction: parseInt(f.advance_deduction) || 0 })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || t('Failed'))); return }
  pyModal.value = false
  window.__krToast?.('✅ Payslip ' + (r.id || '') + ' · net ' + money(r.net ?? 0) + (r.absent_days ? ' · ' + r.absent_days + ' absent' : ''))
  await data.bootstrap()
}
async function payPayroll(p) {
  if (!window.confirm(t('Mark {id} as paid for {name}?').replace('{id}', p.id).replace('{name}', staffName(p.staff)))) return
  const r = await apiCall('app-staffwatch', { action: 'payroll-pay', id: p.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || t('Failed'))); return }
  window.__krToast?.('✅ Marked paid')
  await data.bootstrap()
}
async function delPayroll(p) {
  if (!window.confirm(t('Delete payslip ') + p.id + '?')) return
  const r = await apiCall('app-staffwatch', { action: 'payroll-delete', id: p.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || t('Failed'))); return }
  window.__krToast?.('🗑 Deleted')
  closeDetail()
  await data.bootstrap()
}

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
    { label: t('Net total'), ico: '💸', value: money(net), trend: 'across ' + months + ' month(s)' },
    { label: 'Paid', ico: '✅', value: paid, trend: paid ? money(paidAmt) + t(' settled') : t('none paid'), ok: paid > 0 },
    { label: 'Draft', ico: '📝', value: draft, trend: draft ? t('awaiting approval') : t('none in draft'), ok: draft === 0 },
    { label: 'Months', ico: '🗓️', value: months, trend: 'payroll periods' },
    { label: t('Avg net'), ico: '📊', value: money(avg), trend: 'per payslip' },
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
        <h1>{{ t('💰 Staff Payroll') }}</h1>
        <div class="sub">{{ pyAll.length }} payslips · {{ kpis[2]?.value || 0 }} paid · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" :placeholder="t('Search staff…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:200px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All statuses') }}</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="monthFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All months') }}</option>
          <option v-for="m in monthOptions" :key="m" :value="m">{{ monthLabel(m) }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" :title="t('Download CSV')">⬇ CSV</button>
      </CompactFilters>
        <button v-if="canManage" @click="openPyModal()" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Generate payslip</button>
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
            <span v-if="p.bonus" class="badge b-green">+{{ t('Bonus') }} {{ money(p.bonus) }}</span>
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
          <thead><tr><th>{{ t('ID') }}</th><th>{{ t('Staff') }}</th><th>{{ t('Month') }}</th><th>{{ t('Salary') }}</th><th>{{ t('OT') }}</th><th>{{ t('Bonus') }}</th><th>{{ t('Deduction') }}</th><th>{{ t('Net') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
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
              <td style="white-space:nowrap">
                <button v-if="canManage && p.status !== 'Paid'" @click.stop="payPayroll(p)" :title="t('Mark paid')" style="background:none;border:none;font-size:14px;cursor:pointer">💰</button>
                <button v-if="canManage" @click.stop="openPyModal(p)" :title="t('Recompute')" style="background:none;border:none;font-size:14px;cursor:pointer">✏️</button>
                <button v-if="canManage" @click.stop="delPayroll(p)" :title="t('Delete')" style="background:none;border:none;font-size:15px;cursor:pointer">🗑</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No payslips found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- payroll modal -->
    <template v-if="pyModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="pyModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(480px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">{{ pyForm.staff && staffList.find(s => s.id === pyForm.staff) ? '✏️ Recompute payslip' : '💰 Generate payslip' }}</div>
          <button @click="pyModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;display:flex;flex-direction:column;gap:13px">
          <div>
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Staff *</div>
            <select v-model="pyForm.staff" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none">
              <option v-for="s in staffList" :key="s.id" :value="s.id">{{ s.id }} · {{ s.name }}<template v-if="s.monthly_salary"> · {{ money(s.monthly_salary) }}/mo</template></option>
            </select>
          </div>
          <div>
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">Month *</div>
            <input v-model="pyForm.month" type="month" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">{{ t('Overtime') }}</div>
              <input v-model="pyForm.overtime" type="number" min="0" placeholder="0" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">{{ t('Bonus') }}</div>
              <input v-model="pyForm.bonus" type="number" min="0" placeholder="0" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px">{{ t('Advance') }}</div>
              <input v-model="pyForm.advance_deduction" type="number" min="0" placeholder="0" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none;box-sizing:border-box">
            </div>
          </div>
          <div style="font-size:12px;color:var(--text-mute);line-height:1.6">Net = salary + overtime + bonus − advance − absent-day deductions. {{ t('Absent days') }} and daily rate are computed automatically from attendance.</div>
          <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:4px">
            <button @click="pyModal = false" class="btn-ghost" style="padding:9px 16px;font-size:13px">{{ t('Cancel') }}</button>
            <button @click="generatePayroll" style="padding:9px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13px;font-weight:800;cursor:pointer">💾 Generate</button>
          </div>
        </div>
      </div>
    </template>

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
          <div v-if="canManage" style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px">
            <button v-if="sel.status !== 'Paid'" style="padding:8px 14px;font-size:12.5px;font-weight:700;border:none;border-radius:10px;background:var(--ok);color:#fff;cursor:pointer" @click="payPayroll(sel)">💰 {{ t('Mark paid') }}</button>
            <button class="btn-ghost" style="padding:8px 14px;font-size:12.5px;font-weight:700" @click="openPyModal(sel)">✏️ {{ t('Recompute') }}</button>
            <button style="padding:8px 14px;font-size:12.5px;font-weight:700;border:none;border-radius:10px;background:rgba(231,76,60,.12);color:var(--danger);cursor:pointer" @click="delPayroll(sel)">🗑 {{ t('Delete') }}</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px 18px;border-top:1px solid var(--border);padding-top:14px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Base salary') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ money(sel.salary) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Overtime') }}</div>
              <div style="font-weight:700;margin-top:1px;color:var(--ok)">{{ sel.overtime ? '+' + money(sel.overtime) : '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Bonus') }}</div>
              <div style="font-weight:700;margin-top:1px;color:var(--ok)">{{ sel.bonus ? '+' + money(sel.bonus) : '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Advance deduction') }}</div>
              <div style="font-weight:700;margin-top:1px;color:var(--danger)">{{ sel.advance_deduction ? '−' + money(sel.advance_deduction) : '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Absent days') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.absent_days ?? '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Daily rate') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.daily_rate ? money(sel.daily_rate) + '/day' : '—' }}</div>
            </div>
          </div>
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:12px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Net pay') }}</span>
            <span style="font-weight:800;font-size:17px">{{ money(sel.net ?? netOf(sel)) }}</span>
          </div>
          <div v-for="[k, v] in detailFields(sel)" :key="k" style="font-size:13px;margin-bottom:8px">
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t(k.replace(/_/g, ' ')) }}</div>
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
