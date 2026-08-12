<script setup>
import { computed, ref, watch } from 'vue'
import { t } from '../lib/i18n'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { badge, useViewMode, usePager } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('utility-bills')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'accountant'].includes(auth.user?.role || ''))
const canEditTariff = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))
const billsAll = computed(() => data.list('utility_bills'))
const unitsAll = computed(() => data.list('units'))
const tenantsAll = computed(() => data.list('tenants'))
const propsAll = computed(() => data.list('properties'))

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const unitName = (uid) => unitsAll.value.find(u => u.id === uid)?.name || uid || '—'
const unitProp = (uid) => unitsAll.value.find(u => u.id === uid)?.p || ''
const propName = (pid) => propsAll.value.find(p => p.id === pid)?.name || pid || ''
const tenantName = (tid) => tenantsAll.value.find(t => t.id === tid)?.name || tid || ''
const TYPE_META = { electric: { ico: '⚡', label: 'Electric' }, water: { ico: '💧', label: 'Water' }, gas: { ico: '🔥', label: 'Gas' } }
const typeMeta = (t) => TYPE_META[t] || { ico: '🧾', label: t || 'Other' }
const UNIT_LABEL = { electric: 'kWh', water: 'gal', gas: 'm³' }
const unitLabel = (t) => UNIT_LABEL[t] || ''
function monthLabel(m) { if (!m) return '—'; const [y, mo] = m.split('-'); const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']; return (names[parseInt(mo, 10) - 1] || mo) + ' ' + y }
function curMonth() { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') }

// ── KPIs ──
const kpis = computed(() => {
  const bs = billsAll.value
  const tot = bs.reduce((s, b) => s + (b.amount || 0), 0)
  const unpaid = bs.filter(b => String(b.status).toLowerCase() !== 'paid').reduce((s, b) => s + (b.amount || 0), 0)
  const paid = bs.filter(b => String(b.status).toLowerCase() === 'paid').length
  const thisM = curMonth()
  const mTot = bs.filter(b => b.month === thisM).reduce((s, b) => s + (b.amount || 0), 0)
  const usage = bs.reduce((s, b) => s + (b.usage || 0), 0)
  const byType = {}
  bs.forEach(b => { const t = b.type || 'other'; byType[t] = (byType[t] || 0) + 1 })
  const topType = Object.entries(byType).sort((a, b) => b[1] - a[1])[0]
  return [
    { label: 'Utility bills', ico: '🧾', value: bs.length, trend: paid + ' paid' },
    { label: 'Total billed', ico: '💰', value: money(tot), trend: 'all months' },
    { label: 'Unpaid', ico: '⏳', value: money(unpaid), trend: unpaid ? 'needs collection' : 'all clear', ok: unpaid === 0 },
    { label: 'This month', ico: '📅', value: money(mTot), trend: monthLabel(thisM) },
    { label: 'Usage', ico: '📈', value: usage.toLocaleString('en-IN'), trend: 'units consumed' },
    { label: 'Top type', ico: typeMeta(topType?.[0]).ico, value: topType ? typeMeta(topType[0]).label : '—', trend: topType ? topType[1] + ' bills' : '' },
  ]
})

// ── filters / sort ──
const query = ref('')
const typeFilter = ref('')
const monthFilter = ref('')
const statusFilter = ref('')
const sortBy = ref('month')
const typeOptions = computed(() => [...new Set(billsAll.value.map(b => b.type).filter(Boolean))].sort())
const monthOptions = computed(() => [...new Set(billsAll.value.map(b => b.month).filter(Boolean))].sort().reverse())
const statusOptions = computed(() => [...new Set(billsAll.value.map(b => b.status).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = billsAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(b => b.id.toLowerCase().includes(q) || unitName(b.unit).toLowerCase().includes(q) || tenantName(b.tenant).toLowerCase().includes(q) || (b.type || '').toLowerCase().includes(q))
  if (typeFilter.value) out = out.filter(b => b.type === typeFilter.value)
  if (monthFilter.value) out = out.filter(b => b.month === monthFilter.value)
  if (statusFilter.value) out = out.filter(b => b.status === statusFilter.value)
  const get = (b) => sortBy.value === 'amount' ? (b.amount || 0) : sortBy.value === 'usage' ? (b.usage || 0) : (b.month || '')
  return [...out].sort((a, b) => typeof get(a) === 'string' ? String(get(b)).localeCompare(String(get(a))) : get(b) - get(a))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)
function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const lines = [['id', 'unit', 'tenant', 'type', 'month', 'prev', 'curr', 'usage', 'rate', 'standing', 'amount', 'status'].map(esc).join(',')]
  rows.forEach(b => lines.push([b.id, unitName(b.unit), tenantName(b.tenant), b.type, b.month, b.prev_reading, b.curr_reading, b.usage, b.rate, b.standing, b.amount, b.status].map(esc).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'utility-bills.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── generate modal ──
const genModal = ref(false)
const genForm = ref({ unit: '', type: 'electric', month: curMonth() })
const genPreview = ref(null)
function openGenerate() {
  genForm.value = { unit: unitsAll.value[0]?.id || '', type: 'electric', month: curMonth() }
  genPreview.value = null
  genModal.value = true
}
async function previewBill() {
  const f = genForm.value
  if (!f.unit || !/^\d{4}-(0[1-9]|1[0-2])$/.test(f.month)) { window.__krToast?.('❌ Select unit and month'); return }
  const r = await apiCall('app-utility-bill-preview', { unit: f.unit, type: f.type, month: f.month })
  if (r && r.ok === false) { genPreview.value = null; window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  genPreview.value = r.bill
}
async function generateBill() {
  const f = genForm.value
  const r = await apiCall('app-utility-bill-generate', { unit: f.unit, type: f.type, month: f.month })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  genModal.value = false
  window.__krToast?.('✅ ' + (r.bill?.id || 'Bill') + ' generated · ' + money(r.bill?.amount), 'ok')
  await data.bootstrap()
}

// ── batch run modal ──
const batchModal = ref(false)
const batchForm = ref({ month: curMonth(), prop: '' })
const batchResult = ref(null)
function openBatch() {
  batchForm.value = { month: curMonth(), prop: '' }
  batchResult.value = null
  batchModal.value = true
}
async function runBatch() {
  const f = batchForm.value
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(f.month)) { window.__krToast?.('❌ Month must be YYYY-MM'); return }
  const r = await apiCall('app-utility-run', { month: f.month, prop: f.prop || undefined })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  batchResult.value = r
  window.__krToast?.('⚡ Batch done: ' + r.generated + ' generated · ' + r.updated + ' updated · ' + money(r.total_amount), 'ok')
  await data.bootstrap()
}

// ── tariffs modal ──
const tarModal = ref(false)
const tarList = ref([])
async function openTariffs() {
  const r = await apiCall('app-utility-tariff-get')
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  tarList.value = Object.values(r.tariffs || {})
  tarModal.value = true
}
async function saveTariffs() {
  const payload = {}
  tarList.value.forEach(t => { payload[t.type] = { rate: parseInt(t.rate) || 0, standing: parseInt(t.standing) || 0, enabled: t.enabled ? 1 : 0 } })
  const r = await apiCall('app-utility-tariff-save', { tariffs: payload })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('✅ Tariffs saved', 'ok')
  await data.bootstrap()
}

// ── drawer ──
const sel = ref(null)
function openDetail(b) { sel.value = b }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const b = billsAll.value.find(x => x.id === id); if (b) openDetail(b) }
}, { immediate: true })
const selUnit = computed(() => sel.value ? unitsAll.value.find(u => u.id === sel.value.unit) : null)
const selTenant = computed(() => sel.value ? tenantsAll.value.find(t => t.id === sel.value.tenant) : null)
async function payBill(b) {
  if (['Paid', 'Void'].includes(b.status)) { window.__krToast?.('Already ' + b.status); return }
  const r = await apiCall('app-utility-bill-pay', { id: b.id, action: 'pay' })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('💰 ' + b.id + ' marked paid', 'ok')
  await data.bootstrap()
  refreshBill()
}
async function voidBill(b) {
  if (['Paid', 'Void'].includes(b.status)) { window.__krToast?.('Already ' + b.status); return }
  if (!window.confirm('Void bill ' + b.id + ' (' + money(b.amount) + ')?')) return
  const r = await apiCall('app-utility-bill-pay', { id: b.id, action: 'void' })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('⛔ ' + b.id + ' voided', 'ok')
  await data.bootstrap()
  refreshBill()
}
function refreshBill() {
  if (!sel.value) return
  const fresh = billsAll.value.find(x => x.id === sel.value.id)
  if (fresh) sel.value = fresh
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('🧾 Utility bills') }}</h1>
        <div class="sub">{{ billsAll.length }} bills · {{ kpis[1]?.value || '৳0' }} billed · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" :placeholder="t('Search bill, unit, tenant…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:190px">
        <select v-model="typeFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All types</option>
          <option v-for="t in typeOptions" :key="t" :value="t">{{ typeMeta(t).label }}</option>
        </select>
        <select v-model="monthFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All months</option>
          <option v-for="m in monthOptions" :key="m" :value="m">{{ monthLabel(m) }}</option>
        </select>
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="sortBy" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="month">Sort: Month</option>
          <option value="amount">Sort: Amount</option>
          <option value="usage">Sort: Usage</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
        <button @click="openTariffs" class="btn-ghost" title="Utility tariffs">🏷️ Tariffs</button>
      </CompactFilters>
        <template v-if="canManage">
          <button @click="openBatch" class="btn-ghost" title="Generate bills for all leased units">⚡ Batch run</button>
          <button @click="openGenerate" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Generate bill</button>
        </template>
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
      <div v-for="b in paged" :key="b.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(b)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">{{ typeMeta(b.type).ico }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="badge(b.status)" style="background:#ffffff">{{ b.status }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ b.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div>
            <div style="font-weight:800;font-size:18px;letter-spacing:-.3px">{{ money(b.amount) }}</div>
            <div class="c-sub" style="margin-top:2px">{{ typeMeta(b.type).label }} · {{ monthLabel(b.month) }} · 🚪 {{ unitName(b.unit) }}</div>
          </div>
          <div style="display:flex;gap:13px;font-size:12px;flex-wrap:wrap">
            <span class="c-sub" title="Usage">📈 {{ (b.usage || 0).toLocaleString('en-IN') }} {{ unitLabel(b.type) }}</span>
            <span class="c-sub" title="Rate">🏷️ ৳{{ b.rate || 0 }}/{{ unitLabel(b.type) }}</span>
            <span class="c-sub" title="Tenant">👤 {{ tenantName(b.tenant) || '—' }}</span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
            <span v-if="b.paid_at" class="badge b-green">✅ paid {{ b.paid_at }}</span>
            <span v-else-if="b.status === 'Void'" class="badge b-gray">⛔ voided</span>
            <span v-else class="badge b-gray">⏳ not paid</span>
          </div>
          <div v-if="canManage && !['Paid', 'Void'].includes(b.status)" style="display:flex;gap:6px;border-top:1px solid var(--border);padding-top:9px;margin-top:auto">
            <button @click.stop="payBill(b)" style="flex:1;padding:7px;border:none;border-radius:8px;background:var(--ok);color:#fff;font-size:12px;font-weight:800;cursor:pointer">💰 Mark paid</button>
            <button @click.stop="voidBill(b)" style="padding:7px 10px;border:none;border-radius:8px;background:var(--bg-alt);color:var(--text-mute);font-size:12px;font-weight:800;cursor:pointer" title="Void">⛔</button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>Bill</th><th>Type</th><th>Unit</th><th>Property</th><th>Tenant</th><th>Month</th><th>Usage</th><th>Amount</th><th>Status</th><th v-if="canManage">Action</th></tr></thead>
          <tbody>
            <tr v-for="b in paged" :key="b.id" style="cursor:pointer" @click="openDetail(b)">
              <td style="white-space:nowrap"><b>{{ b.id }}</b></td>
              <td style="white-space:nowrap">{{ typeMeta(b.type).ico }} {{ typeMeta(b.type).label }}</td>
              <td style="white-space:nowrap"><a @click.stop="go('/units', { open: b.unit })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ unitName(b.unit) }}</a></td>
              <td style="white-space:nowrap" class="c-sub">{{ propName(unitProp(b.unit)) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ tenantName(b.tenant) || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ monthLabel(b.month) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ (b.usage || 0).toLocaleString('en-IN') }} {{ unitLabel(b.type) }}</td>
              <td style="font-weight:700;white-space:nowrap">{{ money(b.amount) }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="badge(b.status)">{{ b.status }}</span></td>
              <td v-if="canManage" style="white-space:nowrap">
                <button v-if="!['Paid', 'Void'].includes(b.status)" @click.stop="payBill(b)" title="Mark paid" style="background:none;border:none;font-size:14px;cursor:pointer">💰</button>
                <button v-if="!['Paid', 'Void'].includes(b.status)" @click.stop="voidBill(b)" title="Void" style="background:none;border:none;font-size:14px;cursor:pointer">⛔</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No utility bills found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- generate modal -->
    <template v-if="genModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="genModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(480px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">🧾 Generate utility bill</div>
          <button @click="genModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 22px;display:flex;flex-direction:column;gap:12px">
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Unit *</div>
            <select v-model="genForm.unit" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
              <option v-for="u in unitsAll" :key="u.id" :value="u.id">{{ u.name }} · {{ propName(u.p) }}</option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Type</div>
              <select v-model="genForm.type" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option v-for="(m, k) in TYPE_META" :key="k" :value="k">{{ m.ico }} {{ m.label }}</option>
              </select>
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Month *</div>
              <input v-model="genForm.month" type="month" style="width:100%;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
          </div>
          <button @click="previewBill" style="padding:10px;border:none;border-radius:10px;background:var(--bg-alt);color:var(--text);font-size:12.5px;font-weight:800;cursor:pointer">👁 Preview calculation</button>
          <div v-if="genPreview" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">📈 Preview</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;font-size:12.5px">
              <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:8px 10px;text-align:center">
                <div class="c-sub" style="font-size:10px;text-transform:uppercase">Prev</div>
                <b>{{ (genPreview.prev_reading || 0).toLocaleString('en-IN') }}</b>
              </div>
              <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:8px 10px;text-align:center">
                <div class="c-sub" style="font-size:10px;text-transform:uppercase">Curr</div>
                <b>{{ (genPreview.curr_reading || 0).toLocaleString('en-IN') }}</b>
              </div>
              <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:8px 10px;text-align:center">
                <div class="c-sub" style="font-size:10px;text-transform:uppercase">Usage</div>
                <b>{{ (genPreview.usage || 0).toLocaleString('en-IN') }}</b>
              </div>
            </div>
            <div style="font-size:12.5px;margin-top:9px;text-align:center">💰 {{ (genPreview.usage || 0).toLocaleString('en-IN') }} × ৳{{ genPreview.rate }} = <b style="color:var(--primary)">{{ money(genPreview.amount) }}</b></div>
          </div>
          <button @click="generateBill" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">🧾 Generate bill</button>
          <div class="c-sub" style="font-size:11px;text-align:center">Requires a meter reading for this unit + type + month. Regenerating updates the bill (UPSERT).</div>
        </div>
      </div>
    </template>

    <!-- batch run modal -->
    <template v-if="batchModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="batchModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(440px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">⚡ Batch utility run</div>
          <button @click="batchModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 22px;display:flex;flex-direction:column;gap:12px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Month *</div>
              <input v-model="batchForm.month" type="month" style="width:100%;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Property</div>
              <select v-model="batchForm.prop" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option value="">All properties</option>
                <option v-for="p in propsAll" :key="p.id" :value="p.id">{{ p.id }} · {{ p.name }}</option>
              </select>
            </div>
          </div>
          <button @click="runBatch" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">⚡ Run for all leased units</button>
          <div v-if="batchResult" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px">
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;text-align:center;font-size:12.5px">
              <div>
                <div class="c-sub" style="font-size:10px;text-transform:uppercase">Generated</div>
                <b style="color:var(--ok)">{{ batchResult.generated }}</b>
              </div>
              <div>
                <div class="c-sub" style="font-size:10px;text-transform:uppercase">Updated</div>
                <b>{{ batchResult.updated }}</b>
              </div>
              <div>
                <div class="c-sub" style="font-size:10px;text-transform:uppercase">Skipped</div>
                <b>{{ batchResult.skipped }}</b>
              </div>
            </div>
            <div style="font-size:13px;margin-top:10px;text-align:center">💰 Total billed <b style="color:var(--primary)">{{ money(batchResult.total_amount) }}</b></div>
          </div>
          <div class="c-sub" style="font-size:11px;text-align:center">Generates electric/water/gas bills for every leased unit with a meter reading. Skips units with no reading.</div>
        </div>
      </div>
    </template>

    <!-- tariffs modal -->
    <template v-if="tarModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="tarModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(480px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">🏷️ Utility tariffs</div>
          <button @click="tarModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 22px;display:flex;flex-direction:column;gap:12px">
          <div v-for="t in tarList" :key="t.type" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
            <div style="display:flex;align-items:center;gap:8px">
              <span style="font-size:18px">{{ typeMeta(t.type).ico }}</span>
              <div style="flex:1;font-weight:800;font-size:13.5px">{{ typeMeta(t.type).label }}</div>
              <label style="display:flex;align-items:center;gap:5px;font-size:11.5px;color:var(--text-mute);font-weight:700">
                <input type="checkbox" v-model="t.enabled"> Enabled
              </label>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:9px">
              <div>
                <div class="c-sub" style="font-size:10.5px;margin-bottom:3px">Rate (৳/{{ t.unit_label }})</div>
                <input v-model.number="t.rate" type="number" min="0" :disabled="!canEditTariff" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
              </div>
              <div>
                <div class="c-sub" style="font-size:10.5px;margin-bottom:3px">Standing (৳)</div>
                <input v-model.number="t.standing" type="number" min="0" :disabled="!canEditTariff" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
              </div>
            </div>
          </div>
          <button v-if="canEditTariff" @click="saveTariffs" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">💾 Save tariffs</button>
          <button v-else style="padding:9px;border:none;border-radius:10px;background:var(--bg-alt);color:var(--text-mute);font-size:12.5px;font-weight:800;cursor:default">View only — owners/managers can edit</button>
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
            <span class="badge" :class="badge(sel.status)" style="background:#ffffff">{{ sel.status }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ typeMeta(sel.type).label }} bill · {{ monthLabel(sel.month) }}</h2>
          <div class="c-sub" style="margin-top:3px">🚪 <a @click.stop="go('/units', { open: sel.unit })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ unitName(sel.unit) }}</a><template v-if="selTenant"> · 👤 <a @click.stop="go('/tenants', { open: sel.tenant })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ selTenant.name }}</a></template></div>

          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Amount</div>
              <div style="font-size:15px;font-weight:800;margin-top:2px">{{ money(sel.amount) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Usage</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ (sel.usage || 0).toLocaleString('en-IN') }} {{ unitLabel(sel.type) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Rate</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">৳{{ sel.rate || 0 }}/{{ unitLabel(sel.type) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Standing</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ money(sel.standing || 0) }}</div>
            </div>
          </div>

          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">📈 Meter readings</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;font-size:12.5px">
              <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:9px 11px;text-align:center">
                <div class="c-sub" style="font-size:10px;text-transform:uppercase">Previous</div>
                <b>{{ (sel.prev_reading ?? '—').toLocaleString?.('en-IN') ?? sel.prev_reading ?? '—' }}</b>
              </div>
              <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:9px 11px;text-align:center">
                <div class="c-sub" style="font-size:10px;text-transform:uppercase">Current</div>
                <b>{{ (sel.curr_reading ?? '—').toLocaleString?.('en-IN') ?? sel.curr_reading ?? '—' }}</b>
              </div>
              <div style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:9px 11px;text-align:center">
                <div class="c-sub" style="font-size:10px;text-transform:uppercase">Consumed</div>
                <b>{{ (sel.usage || 0).toLocaleString('en-IN') }}</b>
              </div>
            </div>
            <div class="c-sub" style="font-size:11.5px;margin-top:8px">💰 {{ (sel.usage || 0).toLocaleString('en-IN') }} × ৳{{ sel.rate || 0 }}<template v-if="sel.standing"> + ৳{{ sel.standing }} standing</template> = {{ money(sel.amount) }}</div>
            <div v-if="sel.note" class="c-sub" style="font-size:11.5px;margin-top:4px">📝 {{ sel.note }}</div>
          </div>

          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">🔄 Status</div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <span class="badge" :class="badge(sel.status)">{{ sel.status }}</span>
              <span v-if="sel.paid_at" class="c-sub" style="font-size:12px">✅ paid on {{ sel.paid_at }}</span>
              <span v-else class="c-sub" style="font-size:12px">⏳ awaiting payment</span>
            </div>
            <div v-if="canManage && !['Paid', 'Void'].includes(sel.status)" style="display:flex;gap:8px;margin-top:10px">
              <button @click="payBill(sel)" style="flex:1;padding:10px;border:none;border-radius:10px;background:var(--ok);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">💰 Mark paid</button>
              <button @click="voidBill(sel)" style="flex:1;padding:10px;border:none;border-radius:10px;background:var(--danger);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">⛔ Void bill</button>
            </div>
          </div>

          <div v-if="selUnit" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">🚪 Unit</div>
            <div style="font-weight:800;font-size:14px;cursor:pointer" @click="go('/units', { open: sel.unit })">{{ selUnit.name }} ↗</div>
            <div class="c-sub" style="font-size:11.5px;margin-top:3px">{{ selUnit.sqft || '—' }} sqft · rent {{ money(selUnit.rent) }}/mo</div>
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
