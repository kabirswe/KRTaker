<script setup>
import { computed, ref } from 'vue'
import { useDataStore } from '../stores/data'

const props = defineProps({ collection: { type: String, default: '' } })
const data = useDataStore()

const rows = computed(() => data.list(props.collection))
const query = ref('')

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
    case 'tickets': return `${unitName(row.u)} · ${row.liab || ''} liability · con: ${row.con || '—'}`
    case 'partners': return `${row.trade || ''} · ★${row.rating || 0} · ${row.jobs || 0} jobs`
    case 'staff': return `${row.role || ''} · ${row.dept || ''}`
    case 'support': return `${row.from_t || ''} · ${row.prio || ''}`
    case 'cases': return `${row.type || ''} · ${row.opened || ''} · lease ${row.ref_lease || '—'}`
    case 'notices': return `${row.ts || ''}${row.pinned ? ' · 📌 pinned' : ''}`
    case 'leads': return `${row.source || row.mod || ''} · ${row.phone || ''}`
    case 'compliance_items': return `${row.module || ''} · expires ${row.expiry_date || '—'}`
    case 'utility_bills': return `${unitName(row.unit)} · ${row.month || ''} · ${money(row.amount)}`
    case 'meter_readings': return `${unitName(row.unit)} · ${row.month || ''} · ${row.reading != null ? row.reading + ' ' + (row.unit_type || '') : ''}`
    case 'partner_invoices': return `${money(row.amount)} · ${row.partner || ''}`
    case 'vendor_payouts': return `${row.month || ''} · ${money(row.amount)}`
    case 'remittances': return `${row.month || ''} · ${money(row.amount)}`
    case 'onboarding_apps': return `${row.app_type || ''} · ${row.stage || row.status || ''}`
    case 'concierge_requests': return `${row.tenant || ''} · ${row.service || ''}`
    case 'documents': return `${row.ref || ''} · ${row.ts || ''}`
    case 'referrals': return `${row.user_email || ''} · ${row.ts || ''}`
    case 'nid_verifications': return `${row.tenant || ''} · ${row.result || row.status || ''}`
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
    case 'vendor_ratings': return `${row.partner || ''} · ★${row.rating || 0}`
    default: return Object.values(row).slice(1, 3).map(String).join(' · ')
  }
}

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return rows.value
  return rows.value.filter(r => JSON.stringify(r).toLowerCase().includes(q))
})

const meta = computed(() => META[props.collection] || { title: props.collection, ico: '📋' })
const nameKey = computed(() => NAME_KEY[props.collection] || 'id')
const statusKey = computed(() => {
  const r = rows.value[0]
  if (!r) return 'status'
  return STATUS_KEY.find(k => r[k] !== undefined) || 'status'
})

function badge(st) {
  const map = { Paid: 'b-green', Active: 'b-green', Success: 'b-green', Leased: 'b-green', Open: 'b-red', Unpaid: 'b-orange', Overdue: 'b-red', 'In Progress': 'b-blue', 'Awaiting Payment': 'b-orange', 'Pending Registration': 'b-orange', Vacant: 'b-gray', Expired: 'b-gray', Terminated: 'b-red', Approved: 'b-green', Verified: 'b-green', Completed: 'b-green', Pending: 'b-orange', Rejected: 'b-red' }
  return map[st] || 'b-gray'
}

const val = (r, k) => r[k] === undefined || r[k] === null || r[k] === '' ? '—' : String(r[k])
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ meta.ico }} {{ meta.title }}</h1>
        <div class="sub">{{ rows.length }} records · live from API</div>
      </div>
      <div class="head-actions">
        <input v-model="query" placeholder="Search…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
      </div>
    </div>

    <div class="panel">
      <div class="tbl-wrap">
        <table class="kr">
          <thead>
            <tr>
              <th>ID</th>
              <th>Name / Title</th>
              <th>Details</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in filtered" :key="r.id || val(r, nameKey)">
              <td style="font-weight:700">{{ r.id || '—' }}</td>
              <td>{{ val(r, nameKey) }}</td>
              <td class="c-sub">{{ sub(r) }}</td>
              <td><span class="badge" :class="badge(val(r, statusKey))">{{ val(r, statusKey) }}</span></td>
            </tr>
            <tr v-if="!filtered.length">
              <td :colspan="4" style="text-align:center;color:var(--text-mute);padding:30px">No records found{{ query ? ' for “' + query + '”' : '' }}.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
