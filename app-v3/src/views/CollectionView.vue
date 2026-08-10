<script setup>
import { computed, ref } from 'vue'
import { useDataStore } from '../stores/data'
import { badge, useViewMode } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'

const props = defineProps({ collection: { type: String, default: '' } })
const data = useDataStore()

const viewMode = useViewMode('col_' + (props.collection || 'x'))
const rows = computed(() => data.list(props.collection))
const query = ref('')
const statusFilter = ref('')
const page = ref(1)
const PAGE_SIZE = 15
const expanded = ref({})

// Friendly title + icon per collection (covers all bootstrap collections)
const META = {
  properties: { title: 'Properties', ico: '🏢' },
  units: { title: 'Units', ico: '🚪' },
  tenants: { title: 'Tenants', ico: '👤' },
  leases: { title: 'Leases', ico: '📄' },
  invoices: { title: 'Invoices', ico: '🧾' },
  receipts: { title: 'Receipts', ico: '📎' },
  payments: { title: 'Payments', ico: '💳' },
  tickets: { title: 'Maintenance', ico: '🔧' },
  partners: { title: 'Vendors / Partners', ico: '🧰' },
  staff: { title: 'Staff', ico: '👥' },
  support: { title: 'Support', ico: '🎧' },
  cases: { title: 'Cases', ico: '⚖️' },
  notices: { title: 'Notice Board', ico: '📢' },
  leads: { title: 'Leads', ico: '📥' },
  compliance_items: { title: 'Compliance', ico: '⚖️' },
  utility_bills: { title: 'Utility Bills', ico: '🔌' },
  meter_readings: { title: 'Meter Readings', ico: '📊' },
  partner_invoices: { title: 'Partner Invoices', ico: '🧾' },
  vendor_payouts: { title: 'Vendor Payouts', ico: '💰' },
  remittances: { title: 'Remittances', ico: '🌍' },
  onboarding_apps: { title: 'Onboarding', ico: '📋' },
  concierge_requests: { title: 'Legal Concierge', ico: '🗂️' },
  documents: { title: 'Documents', ico: '📁' },
  referrals: { title: 'Referrals', ico: '🤝' },
  nid_verifications: { title: 'NID Verifications', ico: '🪪' },
  insurance_policies: { title: 'Insurance', ico: '🛡️' },
  holding_taxes: { title: 'Holding Taxes', ico: '🏛️' },
  gate_visits: { title: 'Gate Visits', ico: '🚪' },
  staff_attendance: { title: 'Staff Attendance', ico: '👷' },
  staff_payroll: { title: 'Staff Payroll', ico: '💰' },
  land_parcels: { title: 'Land Guard', ico: '🛰️' },
  build_projects: { title: 'Build Watch', ico: '🏗️' },
  samity_members: { title: 'Kalyan Samity', ico: '🏘️' },
  gateway_tx: { title: 'Gateway Transactions', ico: '💳' },
  maintenance_requests: { title: 'Maintenance Requests', ico: '🔧' },
  renewal_requests: { title: 'Renewals', ico: '🔄' },
  statement_payouts: { title: 'Statement Payouts', ico: '💰' },
  settlement_reports: { title: 'Settlement Reports', ico: '📑' },
  vendor_ratings: { title: 'Vendor Ratings', ico: '⭐' },
}

// Which key to show as the primary "Name/Title" column per collection
const NAME_KEY = {
  properties: 'name', units: 'name', tenants: 'name', leases: 'id', invoices: 'id',
  receipts: 'id', payments: 'id', tickets: 'desc', partners: 'name', staff: 'name',
  support: 'subject', cases: 'title', notices: 'title', leads: 'name',
  compliance_items: 'title', utility_bills: 'id', meter_readings: 'id',
  partner_invoices: 'id', vendor_payouts: 'id', remittances: 'id',
  onboarding_apps: 'name', concierge_requests: 'subject', documents: 'title',
  referrals: 'id', nid_verifications: 'id', insurance_policies: 'policy_no',
  holding_taxes: 'id', gate_visits: 'id', staff_attendance: 'id',
  staff_payroll: 'id', land_parcels: 'name', build_projects: 'name',
  samity_members: 'name', gateway_tx: 'id', maintenance_requests: 'id',
  renewal_requests: 'id', statement_payouts: 'id', settlement_reports: 'id',
  vendor_ratings: 'id',
}

const STATUS_KEY = ['status', 'state', 'liab', 'result', 'decision']

function unitName(pid) { return data.list('units').find(u => u.id === pid)?.name || pid || '' }
function propName(pid) { return data.list('properties').find(p => p.id === pid)?.name || pid || '' }
function tenantName(tid) { return data.list('tenants').find(t => t.id === tid)?.name || tid || '' }
function partnerName(pid) { return data.list('partners').find(p => p.id === pid)?.name || pid || '' }
function money(n) { return '৳' + Math.round(n || 0).toLocaleString('en-IN') }

// Human-friendly detail line per collection
function sub(row) {
  switch (props.collection) {
    case 'properties': return `${row.type || ''} · ${row.jur || ''} · ${row.holding || ''}`
    case 'units': return `${propName(row.p)} · ${row.sqft || 0} sqft · ${row.rent ? money(row.rent) + '/mo' : ''}`
    case 'tenants': return `${row.kind || 'Individual'} · ${row.phone || ''}`
    case 'leases': return `${unitName(row.u)} · ${tenantName(row.t)} · ${money(row.rent)}/mo`
    case 'invoices': return `${row.m || ''} · ${money(row.net)} · lease ${row.l || ''}`
    case 'receipts': return `${row.method || ''} · ${row.date || ''} · inv ${row.inv || ''}`
    case 'payments': return `${row.method || ''} · ${row.ref || ''} · ${row.date || ''}`
    case 'tickets': return `${unitName(row.u)} · ${row.liab || ''} liability · con: ${partnerName(row.con) || '—'}`
    case 'partners': return `${row.trade || ''} · ★${row.rating || 0} · ${row.jobs || 0} jobs`
    case 'staff': return `${row.role || ''} · ${row.dept || ''}`
    case 'support': return `${row.from_t || ''} · ${row.prio || ''}`
    case 'cases': return `${row.type || ''} · ${row.opened || ''} · lease ${row.ref_lease || '—'}`
    case 'notices': return `${row.ts || ''}${row.pinned ? ' · 📌 pinned' : ''}`
    case 'leads': return `${row.source || row.mod || ''} · ${row.phone || ''}`
    case 'compliance_items': return `${row.module || ''} · expires ${row.expiry_date || '—'}`
    case 'utility_bills': return `${unitName(row.unit)} · ${row.month || ''} · ${money(row.amount)}`
    case 'meter_readings': return `${unitName(row.unit)} · ${row.month || ''} · ${row.reading != null ? row.reading + ' ' + (row.unit_type || '') : ''}`
    case 'partner_invoices': return `${money(row.amount)} · ${partnerName(row.partner) || ''}`
    case 'vendor_payouts': return `${row.month || ''} · ${money(row.amount)}`
    case 'remittances': return `${row.month || ''} · ${money(row.amount)}`
    case 'onboarding_apps': return `${row.app_type || ''} · ${row.stage || row.status || ''}`
    case 'concierge_requests': return `${tenantName(row.tenant) || ''} · ${row.service || ''}`
    case 'documents': return `${row.ref || ''} · ${row.ts || ''}`
    case 'referrals': return `${row.user_email || ''} · ${row.ts || ''}`
    case 'nid_verifications': return `${tenantName(row.tenant) || ''} · ${row.result || row.status || ''}`
    case 'insurance_policies': return `${row.type || ''} · ${row.tenant ? tenantName(row.tenant) : ''}`
    case 'holding_taxes': return `${row.prop ? propName(row.prop) : ''} · ${row.due || ''}`
    case 'gate_visits': return `${row.visitor || ''} · ${row.date || ''}`
    case 'staff_attendance': return `${row.staff || ''} · ${row.date || ''} · ${row.status || ''}`
    case 'staff_payroll': return `${row.staff || ''} · ${row.month || ''} · ${money(row.net || row.amount)}`
    case 'land_parcels': return `${row.jur || ''} · ${row.khatian || ''}`
    case 'build_projects': return `${row.type || ''} · ${row.status || ''}`
    case 'samity_members': return `${row.unit || ''} · ${row.role || ''}`
    case 'gateway_tx': return `${row.method || ''} · ${money(row.amount)} · ${row.status || ''}`
    case 'maintenance_requests': return `${unitName(row.unit)} · ${row.category || ''}`
    case 'renewal_requests': return `${row.lease || ''} · ${row.decision || row.status || ''}`
    case 'statement_payouts': return `${row.month || ''} · ${money(row.amount)}`
    case 'settlement_reports': return `${row.month || ''} · ${row.status || ''}`
    case 'vendor_ratings': return `${partnerName(row.partner) || ''} · ★${row.rating || 0}`
    default: return Object.values(row).slice(1, 3).map(String).join(' · ')
  }
}

// ── Search + status filter ──
const statuses = computed(() => {
  const s = new Set()
  const k = statusKey.value
  rows.value.forEach(r => { const v = r[k]; if (v !== undefined && v !== null && v !== '') s.add(String(v)) })
  return [...s].sort()
})

const filtered = computed(() => {
  let out = rows.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(r => JSON.stringify(r).toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(r => String(r[statusKey.value] || '') === statusFilter.value)
  return out
})

// ── Pagination ──
const pageCount = computed(() => Math.max(1, Math.ceil(filtered.value.length / PAGE_SIZE)))
const paged = computed(() => {
  const start = (page.value - 1) * PAGE_SIZE
  return filtered.value.slice(start, start + PAGE_SIZE)
})
const rangeLabel = computed(() => {
  if (!filtered.value.length) return '0 records'
  const from = (page.value - 1) * PAGE_SIZE + 1
  const to = Math.min(page.value * PAGE_SIZE, filtered.value.length)
  return `${from}–${to} of ${filtered.value.length}`
})
function setPage(p) { page.value = Math.min(Math.max(1, p), pageCount.value) }

// ── Row expand → show every field ──
const MONEY_KEYS = ['rent', 'net', 'amount', 'paid', 'due', 'advance', 'deposit', 'salary', 'bonus', 'fine', 'price', 'value', 'balance', 'total']
function expand(rowId) { expanded.value[rowId] = !expanded.value[rowId] }
function detailFields(row) {
  const skip = new Set(['id', statusKey.value])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '' && typeof v !== 'object')
}
function fmtVal(k, v) {
  const s = String(v)
  if (MONEY_KEYS.includes(k) && !isNaN(parseFloat(s))) return money(parseFloat(s))
  return s.length > 80 ? s.slice(0, 80) + '…' : s
}

// ── CSV export ──
function exportCsv() {
  const data2 = filtered.value
  if (!data2.length) return
  const cols = [...new Set(data2.flatMap(r => Object.keys(r)))]
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const lines = [cols.map(esc).join(',')]
  data2.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = `${props.collection}.csv`
  a.click()
  URL.revokeObjectURL(a.href)
}

const meta = computed(() => META[props.collection] || { title: props.collection, ico: '📋' })
const nameKey = computed(() => NAME_KEY[props.collection] || 'id')
const statusKey = computed(() => {
  const r = rows.value[0]
  if (!r) return 'status'
  return STATUS_KEY.find(k => r[k] !== undefined) || 'status'
})

const val = (r, k) => r[k] === undefined || r[k] === null || r[k] === '' ? '—' : String(r[k])

// ── per-collection icon for grid cards ──
const ico = (r) => {
  const m = meta.value
  if (m.ico) return m.ico
  return '📋'
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ meta.ico }} {{ meta.title }}</h1>
        <div class="sub">{{ rows.length }} records · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap">
        <input v-model="query" placeholder="Search…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:210px">
        <select v-if="statuses.length > 1" v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
      </div>
    </div>

    <!-- GRID -->
    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px">
      <div v-for="r in paged" :key="r.id || val(r, nameKey)" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="expand(r.id || val(r, nameKey))">
        <div style="height:74px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:30px">{{ ico(r) }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span v-if="val(r, statusKey) !== '—'" class="badge" :class="badge(val(r, statusKey))">{{ val(r, statusKey) }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ r.id || '—' }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:8px">
          <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ val(r, nameKey) }}</div>
          <div class="c-sub" style="font-size:12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ sub(r) }}</div>
          <div v-if="expanded[r.id || val(r, nameKey)]" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px 14px;margin-top:4px">
            <div v-for="[k, v] in detailFields(r)" :key="k" style="font-size:12px">
              <div style="color:var(--text-mute);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ k.replace(/_/g, ' ') }}</div>
              <div style="font-weight:600;word-break:break-word">{{ fmtVal(k, v) }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr">
          <thead>
            <tr>
              <th style="width:28px"></th>
              <th>ID</th>
              <th>Name / Title</th>
              <th>Details</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="r in paged" :key="r.id || val(r, nameKey)">
              <tr style="cursor:pointer" @click="expand(r.id || val(r, nameKey))">
                <td style="text-align:center">{{ expanded[r.id || val(r, nameKey)] ? '▾' : '▸' }}</td>
                <td style="font-weight:700">{{ r.id || '—' }}</td>
                <td>{{ val(r, nameKey) }}</td>
                <td class="c-sub">{{ sub(r) }}</td>
                <td><span class="badge" :class="badge(val(r, statusKey))">{{ val(r, statusKey) }}</span></td>
              </tr>
              <tr v-if="expanded[r.id || val(r, nameKey)]">
                <td colspan="5" style="background:var(--bg-alt);padding:14px 18px">
                  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px 20px">
                    <div v-for="[k, v] in detailFields(r)" :key="k" style="font-size:12.5px">
                      <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ k.replace(/_/g, ' ') }}</div>
                      <div style="font-weight:600;word-break:break-word">{{ fmtVal(k, v) }}</div>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
            <tr v-if="!filtered.length">
              <td :colspan="5" style="text-align:center;color:var(--text-mute);padding:30px">No records found{{ query ? ' for “' + query + '”' : '' }}.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No records found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />
  </div>
</template>
