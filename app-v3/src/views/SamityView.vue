<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useViewMode, usePager, money, fmtTs, avatarColor, initials, monthLabel, today } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('samity')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const memAll = computed(() => data.list('samity_members'))
const billsAll = computed(() => data.list('samity_bills'))
const colsAll = computed(() => data.list('samity_collections'))

// ── tabs ──
const TABS = [
  ['committee', '👥', 'Committee'],
  ['bills', '🧾', 'Bills'],
  ['collection', '💳', 'Collection'],
  ['report', '🖨', 'Report'],
  ['settings', '⚙️', 'Settings'],
]
const tab = ref('committee')

const ROLE_META = {
  Chairman: { ico: '👑', cls: 'b-blue' },
  Secretary: { ico: '📝', cls: 'b-gray' },
  Treasurer: { ico: '💰', cls: 'b-orange' },
  Member: { ico: '👤', cls: 'b-gray' },
}
const roleMeta = (r) => ROLE_META[r] || { ico: '👤', cls: 'b-gray' }
const stCls = (s) => s === 'active' ? 'b-green' : 'b-gray'
const bStCls = (s) => s === 'Paid' ? 'b-green' : (s === 'Partial' ? 'b-orange' : 'b-gray')
const METHOD_TINT = { bKash: '#e2136e', Nagad: '#f6921e', Rocket: '#8c3494', Bank: '#1f6feb', Cheque: '#8957e5', Cash: '#12a150', Card: '#c2410c' }
const methodTint = (m) => { const c = METHOD_TINT[m] || '#5b6b8c'; return { background: c + '22', color: c, border: '1px solid ' + c + '44' } }

// ── KPIs ──
const kpis = computed(() => {
  const ms = memAll.value
  const active = ms.filter(m => m.status === 'active').length
  const bearers = ms.filter(m => m.role && m.role !== 'Member').length
  const flats = ms.filter(m => /flat/i.test(m.notes || '')).length
  const phones = ms.filter(m => m.phone).length
  const since = ms.map(m => m.since_date).filter(Boolean).sort()
  const billsDue = billsAll.value.filter(b => b.status !== 'Paid').reduce((s, b) => s + (b.amount || 0), 0)
  return [
    { label: 'Members', ico: '🏘️', value: ms.length, trend: 'samity roster' },
    { label: 'Active', ico: '✅', value: active, trend: active === ms.length ? 'all active' : active + ' of ' + ms.length, ok: active === ms.length },
    { label: 'Office bearers', ico: '⭐', value: bearers, trend: 'chairman · secretary · treasurer' },
    { label: 'Flat owners', ico: '🏠', value: flats, trend: 'resident members' },
    { label: 'Bill balance', ico: '🧾', value: money(billsDue), trend: 'unpaid society bills' },
    { label: 'Since', ico: '📅', value: since.length ? since[0] : '—', trend: 'earliest membership' },
  ]
})

// ── Committee filters ──
const query = ref('')
const roleFilter = ref('')
const roleOptions = computed(() => [...new Set(memAll.value.map(m => m.role).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = memAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(m => JSON.stringify(m).toLowerCase().includes(q))
  if (roleFilter.value) out = out.filter(m => (m.role || '') === roleFilter.value)
  const rank = { Chairman: 0, Secretary: 1, Treasurer: 2, Member: 3 }
  return [...out].sort((a, b) => (rank[a.role] ?? 9) - (rank[b.role] ?? 9) || String(a.since_date || '').localeCompare(String(b.since_date || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv(rows, name) {
  if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = name + '.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── Bills tab ──
const bq = ref('')
const bStatus = ref('')
const bMonth = ref('')
const bStatusOptions = computed(() => [...new Set(billsAll.value.map(b => b.status).filter(Boolean))].sort())
const bMonthOptions = computed(() => [...new Set(billsAll.value.map(b => b.month).filter(Boolean))].sort().reverse())
const billsFiltered = computed(() => {
  let out = billsAll.value
  const q = bq.value.trim().toLowerCase()
  if (q) out = out.filter(b => JSON.stringify(b).toLowerCase().includes(q))
  if (bStatus.value) out = out.filter(b => (b.status || '') === bStatus.value)
  if (bMonth.value) out = out.filter(b => (b.month || '') === bMonth.value)
  return [...out].sort((a, b) => String(b.month || '').localeCompare(String(a.month || '')) || String(a.id || '').localeCompare(String(b.id || '')))
})
const bTotal = computed(() => billsFiltered.value.reduce((s, b) => s + (b.amount || 0), 0))
const bPaid = computed(() => billsFiltered.value.filter(b => b.status === 'Paid').reduce((s, b) => s + (b.amount || 0), 0))
const bUnpaid = computed(() => billsFiltered.value.filter(b => b.status !== 'Paid').reduce((s, b) => s + (b.amount || 0), 0))

// ── Collection tab ──
const cq = ref('')
const cMethod = ref('')
const cMethodOptions = computed(() => [...new Set(colsAll.value.map(c => c.method).filter(Boolean))].sort())
const colsFiltered = computed(() => {
  let out = colsAll.value
  const q = cq.value.trim().toLowerCase()
  if (q) out = out.filter(c => JSON.stringify(c).toLowerCase().includes(q))
  if (cMethod.value) out = out.filter(c => (c.method || '') === cMethod.value)
  return [...out].sort((a, b) => String(b.collected_at || '').localeCompare(String(a.collected_at || '')))
})
const cTotal = computed(() => colsFiltered.value.reduce((s, c) => s + (c.amount || 0), 0))
const cByMethod = computed(() => {
  const map = {}
  colsFiltered.value.forEach(c => { const m = c.method || 'Other'; map[m] = (map[m] || 0) + (c.amount || 0) })
  return Object.entries(map)
})

// ── Report tab ──
const rMonth = ref('')
const rMonths = computed(() => [...new Set(billsAll.value.map(b => b.month).filter(Boolean))].sort().reverse())
const report = computed(() => {
  const month = rMonth.value
  const bills = billsAll.value.filter(b => !month || b.month === month)
  const colls = colsAll.value.filter(c => !month || String(c.collected_at || '').slice(0, 7) === month)
  const issued = bills.reduce((s, b) => s + (b.amount || 0), 0)
  const collected = colls.reduce((s, c) => s + (c.amount || 0), 0)
  const paidBills = bills.filter(b => b.status === 'Paid').reduce((s, b) => s + (b.amount || 0), 0)
  const outstanding = Math.max(0, issued - collected)
  return { bills, colls, issued, collected, paidBills, outstanding, rate: issued ? Math.round((collected / issued) * 100) : 0 }
})
function printReport() {
  document.body.classList.add('print-samity')
  window.print()
  document.body.classList.remove('print-samity')
}

// ── Settings tab ──
const settings = computed(() => {
  const amts = billsAll.value.map(b => b.amount).filter(Boolean)
  const defCharge = amts.length ? [...new Set(amts)].sort((a, b) => billsAll.value.filter(b => b.amount === a).length - billsAll.value.filter(b => b.amount === b).length).pop() : 0
  const dueDays = [...new Set(billsAll.value.map(b => String(b.due_date || '').slice(8, 10)).filter(Boolean))]
  const months = [...new Set(billsAll.value.map(b => b.month).filter(Boolean))].sort()
  return {
    defCharge, dueDay: dueDays.join(', ') || '—', months,
    members: memAll.value.length, active: memAll.value.filter(m => m.status === 'active').length,
    bills: billsAll.value.length, collections: colsAll.value.length,
  }
})

// ── drawer ──
const sel = ref(null)
function openDetail(m) { tab.value = 'committee'; sel.value = m }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const m = memAll.value.find(x => x.id === id); if (m) openDetail(m) }
}, { immediate: true })
function detailFields(row) {
  const skip = new Set(['id', 'name', 'role', 'phone', 'since_date', 'status', 'notes', 'owner_email'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🏘️ Samity</h1>
        <div class="sub">{{ memAll.length }} members · {{ kpis[4]?.value || '৳0' }} bill balance · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <template v-if="tab === 'committee'">
          <input v-model="query" placeholder="Search name, phone, flat…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:200px">
          <select v-model="roleFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All roles</option>
            <option v-for="r in roleOptions" :key="r" :value="r">{{ r }}</option>
          </select>
          <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
            <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
            <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
          </div>
          <button v-if="filtered.length" @click="exportCsv(filtered, 'samity-members')" class="btn-ghost" title="Download CSV">⬇ CSV</button>
        </template>
        <template v-else-if="tab === 'bills'">
          <input v-model="bq" placeholder="Search bill, unit…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:180px">
          <select v-model="bMonth" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All months</option>
            <option v-for="m in bMonthOptions" :key="m" :value="m">{{ monthLabel(m) }}</option>
          </select>
          <select v-model="bStatus" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All statuses</option>
            <option v-for="s in bStatusOptions" :key="s" :value="s">{{ s }}</option>
          </select>
          <button v-if="billsFiltered.length" @click="exportCsv(billsFiltered, 'samity-bills')" class="btn-ghost" title="Download CSV">⬇ CSV</button>
        </template>
        <template v-else-if="tab === 'collection'">
          <input v-model="cq" placeholder="Search receipt, bill…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:180px">
          <select v-model="cMethod" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All methods</option>
            <option v-for="m in cMethodOptions" :key="m" :value="m">{{ m }}</option>
          </select>
          <button v-if="colsFiltered.length" @click="exportCsv(colsFiltered, 'samity-collections')" class="btn-ghost" title="Download CSV">⬇ CSV</button>
        </template>
        <template v-else-if="tab === 'report'">
          <select v-model="rMonth" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All months</option>
            <option v-for="m in rMonths" :key="m" :value="m">{{ monthLabel(m) }}</option>
          </select>
          <button @click="printReport" class="btn-ghost" title="Print report">🖨 Print</button>
        </template>
      </div>
    </div>

    <!-- tab bar -->
    <div class="tabs" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px;border-bottom:1px solid var(--border);padding-bottom:10px">
      <button v-for="[id, ico, label] in TABS" :key="id" @click="tab = id"
        :style="tab === id ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'"
        style="padding:8px 14px;border:none;border-radius:10px;font-size:12.5px;font-weight:800;cursor:pointer">{{ ico }} {{ label }}</button>
    </div>

    <div class="stats">
      <div v-for="k in kpis" :key="k.label" class="stat">
        <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
        <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
        <div class="s-trend">{{ k.trend }}</div>
      </div>
    </div>

    <!-- ── 👥 COMMITTEE ── -->
    <template v-if="tab === 'committee'">
      <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px">
        <div v-for="m in paged" :key="m.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(m)">
          <div style="padding:16px 15px 0;display:flex;align-items:center;gap:12px">
            <div style="width:46px;height:46px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;color:#fff" :style="{ background: avatarColor(m.id) }">{{ initials(m.name) }}</div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ m.name || '—' }}</div>
              <div class="c-sub" style="font-size:12px">since {{ m.since_date || '—' }}</div>
            </div>
            <span class="badge" :class="stCls(m.status)">{{ m.status || '—' }}</span>
          </div>
          <div style="padding:12px 15px 14px;flex:1;display:flex;flex-direction:column;gap:9px">
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <span class="badge" :class="roleMeta(m.role).cls">{{ roleMeta(m.role).ico }} {{ m.role || '—' }}</span>
              <span v-if="m.phone" class="badge b-blue">📞 {{ m.phone }}</span>
            </div>
            <div v-if="m.notes" class="c-sub" style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ m.notes }}</div>
            <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
              <span>🆔 {{ m.id }}</span>
              <span>📅 {{ fmtTs(m.ts) }}</span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
        <div class="tbl-wrap">
          <table class="kr" style="width:100%">
            <thead><tr><th>ID</th><th>Member</th><th>Role</th><th>Phone</th><th>Since</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="m in paged" :key="m.id" style="cursor:pointer" @click="openDetail(m)">
                <td style="font-weight:700;white-space:nowrap">{{ m.id }}</td>
                <td style="white-space:nowrap">{{ m.name || '—' }}</td>
                <td style="white-space:nowrap"><span class="badge" :class="roleMeta(m.role).cls">{{ roleMeta(m.role).ico }} {{ m.role || '—' }}</span></td>
                <td style="white-space:nowrap" class="c-sub">{{ m.phone || '—' }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ m.since_date || '—' }}</td>
                <td style="white-space:nowrap"><span class="badge" :class="stCls(m.status)">{{ m.status || '—' }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No members found{{ query ? ' for “' + query + '”' : '' }}.</div>
      <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />
    </template>

    <!-- ── 🧾 BILLS ── -->
    <template v-else-if="tab === 'bills'">
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Issued</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px">{{ money(bTotal) }}</div>
        </div>
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Paid</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px;color:var(--ok)">{{ money(bPaid) }}</div>
        </div>
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Unpaid</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px" :style="bUnpaid ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(bUnpaid) }}</div>
        </div>
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Bills</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px">{{ billsFiltered.length }}</div>
        </div>
      </div>
      <div v-if="billsFiltered.length" class="panel" style="overflow:hidden">
        <div class="tbl-wrap">
          <table class="kr" style="width:100%">
            <thead><tr><th>ID</th><th>Unit</th><th>Month</th><th>Amount</th><th>Due date</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="b in billsFiltered" :key="b.id">
                <td style="font-weight:700;white-space:nowrap">{{ b.id }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ b.unit || '—' }}</td>
                <td style="white-space:nowrap">{{ monthLabel(b.month) }}</td>
                <td style="white-space:nowrap;font-weight:700">{{ money(b.amount) }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ b.due_date || '—' }}</td>
                <td style="white-space:nowrap"><span class="badge" :class="bStCls(b.status)">{{ b.status || '—' }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div v-else class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No bills found for the current filters.</div>
    </template>

    <!-- ── 💳 COLLECTION ── -->
    <template v-else-if="tab === 'collection'">
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Collected</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px;color:var(--ok)">{{ money(cTotal) }}</div>
        </div>
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Entries</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px">{{ colsFiltered.length }}</div>
        </div>
        <div style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Methods</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px">{{ cByMethod.length }}</div>
        </div>
        <div v-for="[m, amt] in cByMethod" :key="m" style="flex:1;min-width:140px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
          <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ m }}</div>
          <div style="font-weight:800;font-size:18px;margin-top:2px"><span class="badge" :style="methodTint(m)">{{ m }}</span></div>
          <div style="font-weight:700;font-size:13px;margin-top:3px">{{ money(amt) }}</div>
        </div>
      </div>
      <div v-if="colsFiltered.length" class="panel" style="overflow:hidden">
        <div class="tbl-wrap">
          <table class="kr" style="width:100%">
            <thead><tr><th>ID</th><th>Bill</th><th>Amount</th><th>Method</th><th>Receipt</th><th>Collected</th><th>Note</th></tr></thead>
            <tbody>
              <tr v-for="c in colsFiltered" :key="c.id">
                <td style="font-weight:700;white-space:nowrap">{{ c.id }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ c.bill || '—' }}</td>
                <td style="white-space:nowrap;font-weight:700">{{ money(c.amount) }}</td>
                <td style="white-space:nowrap"><span class="badge" :style="methodTint(c.method)">{{ c.method || '—' }}</span></td>
                <td style="white-space:nowrap" class="c-sub">{{ c.receipt_no || '—' }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ fmtTs(c.collected_at) }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ c.note || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div v-else class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No collections found for the current filters.</div>
    </template>

    <!-- ── 🖨 REPORT ── -->
    <template v-else-if="tab === 'report'">
      <div class="report-area panel" style="padding:26px 28px;overflow:hidden">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;border-bottom:2px solid var(--border);padding-bottom:16px">
          <div>
            <div style="font-size:20px;font-weight:800;letter-spacing:-.3px">🏘️ Samity Society Report</div>
            <div class="c-sub" style="font-size:12.5px;margin-top:3px">{{ rMonth ? monthLabel(rMonth) : 'All months' }} · generated {{ today() }} · KRTaker</div>
          </div>
          <div style="text-align:right">
            <div class="c-sub" style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Collection rate</div>
            <div style="font-weight:800;font-size:26px" :style="report.rate >= 80 ? 'color:var(--ok)' : 'color:var(--warn)'">{{ report.rate }}%</div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:18px 0">
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:11px 13px">
            <div class="c-sub" style="font-size:10.5px;font-weight:800;text-transform:uppercase">Bills issued</div>
            <div style="font-weight:800;font-size:17px;margin-top:2px">{{ report.bills.length }} · {{ money(report.issued) }}</div>
          </div>
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:11px 13px">
            <div class="c-sub" style="font-size:10.5px;font-weight:800;text-transform:uppercase">Collected</div>
            <div style="font-weight:800;font-size:17px;margin-top:2px;color:var(--ok)">{{ money(report.collected) }}</div>
          </div>
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:11px 13px">
            <div class="c-sub" style="font-size:10.5px;font-weight:800;text-transform:uppercase">Outstanding</div>
            <div style="font-weight:800;font-size:17px;margin-top:2px" :style="report.outstanding ? 'color:var(--danger)' : 'color:var(--ok)'">{{ money(report.outstanding) }}</div>
          </div>
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:11px 13px">
            <div class="c-sub" style="font-size:10.5px;font-weight:800;text-transform:uppercase">Members</div>
            <div style="font-weight:800;font-size:17px;margin-top:2px">{{ settings.active }} / {{ settings.members }} active</div>
          </div>
        </div>
        <div style="font-size:13px;font-weight:800;margin:18px 0 8px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Committee</div>
        <table class="kr" style="width:100%">
          <thead><tr><th>Name</th><th>Role</th><th>Flat</th><th>Phone</th></tr></thead>
          <tbody>
            <tr v-for="m in memAll" :key="m.id">
              <td style="white-space:nowrap;font-weight:600">{{ m.name || '—' }}</td>
              <td style="white-space:nowrap">{{ m.role || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ (m.notes || '').replace(/—.*/, '') }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ m.phone || '—' }}</td>
            </tr>
          </tbody>
        </table>
        <div style="font-size:13px;font-weight:800;margin:18px 0 8px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Bills · {{ report.bills.length }}</div>
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Unit</th><th>Month</th><th>Amount</th><th>Due</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="b in report.bills" :key="b.id">
              <td style="white-space:nowrap;font-weight:600">{{ b.id }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ b.unit || '—' }}</td>
              <td style="white-space:nowrap">{{ monthLabel(b.month) }}</td>
              <td style="white-space:nowrap">{{ money(b.amount) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ b.due_date || '—' }}</td>
              <td style="white-space:nowrap">{{ b.status || '—' }}</td>
            </tr>
          </tbody>
        </table>
        <div style="font-size:13px;font-weight:800;margin:18px 0 8px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Collections · {{ report.colls.length }}</div>
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Bill</th><th>Amount</th><th>Method</th><th>Receipt</th><th>Date</th></tr></thead>
          <tbody>
            <tr v-for="c in report.colls" :key="c.id">
              <td style="white-space:nowrap;font-weight:600">{{ c.id }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ c.bill || '—' }}</td>
              <td style="white-space:nowrap">{{ money(c.amount) }}</td>
              <td style="white-space:nowrap">{{ c.method || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ c.receipt_no || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ fmtTs(c.collected_at) }}</td>
            </tr>
          </tbody>
        </table>
        <div class="c-sub" style="font-size:11.5px;margin-top:18px;text-align:center">Generated by KRTaker app-v3 · {{ today() }} · Samity module report</div>
      </div>
    </template>

    <!-- ── ⚙️ SETTINGS ── -->
    <template v-else-if="tab === 'settings'">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px">
        <div class="panel" style="overflow:hidden">
          <div style="padding:14px 16px;font-weight:800;font-size:14px;border-bottom:1px solid var(--border)">⚙️ Billing defaults</div>
          <div style="padding:14px 16px;display:flex;flex-direction:column;gap:12px">
            <div style="display:flex;justify-content:space-between;gap:10px">
              <span class="c-sub" style="font-size:12.5px">Monthly service charge</span>
              <span style="font-weight:800">{{ money(settings.defCharge) }}<span class="c-sub" style="font-size:11px">/unit</span></span>
            </div>
            <div style="display:flex;justify-content:space-between;gap:10px">
              <span class="c-sub" style="font-size:12.5px">Due day of month</span>
              <span style="font-weight:700">{{ settings.dueDay }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;gap:10px">
              <span class="c-sub" style="font-size:12.5px">Billing months covered</span>
              <span style="font-weight:700">{{ settings.months.map(monthLabel).join(', ') || '—' }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;gap:10px">
              <span class="c-sub" style="font-size:12.5px">Bills / Collections</span>
              <span style="font-weight:700">{{ settings.bills }} / {{ settings.collections }}</span>
            </div>
          </div>
        </div>
        <div class="panel" style="overflow:hidden">
          <div style="padding:14px 16px;font-weight:800;font-size:14px;border-bottom:1px solid var(--border)">👥 Committee roles</div>
          <div style="padding:14px 16px;display:flex;flex-direction:column;gap:12px">
            <div v-for="(meta, role) in ROLE_META" :key="role" style="display:flex;align-items:center;gap:10px">
              <span class="badge" :class="meta.cls">{{ meta.ico }} {{ role }}</span>
              <span class="c-sub" style="font-size:12px">{{ memAll.filter(m => m.role === role).length }} member(s)</span>
            </div>
          </div>
        </div>
        <div class="panel" style="overflow:hidden">
          <div style="padding:14px 16px;font-weight:800;font-size:14px;border-bottom:1px solid var(--border)">🏘️ Society</div>
          <div style="padding:14px 16px;display:flex;flex-direction:column;gap:12px">
            <div style="display:flex;justify-content:space-between;gap:10px">
              <span class="c-sub" style="font-size:12.5px">Members</span>
              <span style="font-weight:700">{{ settings.members }} total · {{ settings.active }} active</span>
            </div>
            <div style="display:flex;justify-content:space-between;gap:10px">
              <span class="c-sub" style="font-size:12.5px">Owner account</span>
              <span style="font-weight:700">{{ memAll[0]?.owner_email || '—' }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;gap:10px">
              <span class="c-sub" style="font-size:12.5px">Default charge</span>
              <span style="font-weight:700">{{ money(settings.defCharge) }}</span>
            </div>
            <div style="font-size:12px;color:var(--text-mute);line-height:1.6;margin-top:4px">Values are derived from live society data. Billing, collections and member edits are managed through the platform API.</div>
          </div>
        </div>
      </div>
    </template>

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(600px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">🏘️</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" :class="stCls(sel.status)" style="background:#ffffff">{{ sel.status || '—' }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <div style="display:flex;align-items:center;gap:14px">
            <div style="width:58px;height:58px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px;color:#fff" :style="{ background: avatarColor(sel.id) }">{{ initials(sel.name) }}</div>
            <div>
              <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.name || '—' }}</h2>
              <div class="c-sub" style="margin-top:4px;font-size:12.5px">member since {{ sel.since_date || '—' }}</div>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:14px">
            <span class="badge" :class="roleMeta(sel.role).cls" style="font-size:13px;padding:6px 12px">{{ roleMeta(sel.role).ico }} {{ sel.role || '—' }}</span>
            <span v-if="sel.phone" class="badge b-blue" style="font-size:13px;padding:6px 12px">📞 {{ sel.phone }}</span>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px 18px;margin-top:16px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Phone</div>
              <div style="font-weight:700;margin-top:1px"><a v-if="sel.phone" :href="'tel:' + sel.phone" style="color:var(--primary)">{{ sel.phone }}</a><template v-else>—</template></div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Since</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.since_date || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Recorded</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtTs(sel.ts) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Owner</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.owner_email || '—' }}</div>
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
