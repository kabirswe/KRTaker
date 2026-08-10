<script setup>
import { computed, ref, watch } from 'vue'
import { useDataStore } from '../stores/data'
import { apiCall } from '../api/client'

const props = defineProps({ collection: { type: String, default: '' } })
const data = useDataStore()

const rows = computed(() => data.list(props.collection))
const query = ref('')

// Friendly title + icon per collection
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
}

// Human-friendly sub for each row (matches v2 searchIndex style)
function sub(row) {
  switch (props.collection) {
    case 'properties': return `${row.type || ''} · ${row.jur || ''} · ${row.holding || ''}`
    case 'units': return `${unitName(row.p)} · ${row.sqft || 0} sqft · ${row.status || ''}`
    case 'tenants': return `${row.kind || 'Individual'} · ${row.phone || ''}`
    case 'leases': return `${unitName(row.u)} · ${row.start || ''} → ${row.end || ''}`
    case 'invoices': return `${row.m || ''} · ${money(row.net)} · ${row.status || ''}`
    case 'receipts': return `${row.method || ''} · ${row.date || ''}`
    case 'payments': return `${row.method || ''} · ${row.ref || ''} · ${row.date || ''}`
    case 'tickets': return `${unitName(row.u)} · ${row.liab || ''} liability · ${row.status || ''}`
    case 'partners': return `${row.trade || ''} · ★${row.rating || 0} · ${row.jobs || 0} jobs`
    case 'staff': return `${row.role || ''} · ${row.dept || ''}`
    case 'support': return `${row.from_t || ''} · ${row.status || ''} · ${row.prio || ''}`
    case 'cases': return `${row.type || ''} · ${row.status || ''} · ${row.opened || ''}`
    case 'notices': return `${row.ts || ''}`
    default: return Object.values(row).slice(1, 3).join(' · ')
  }
}

function unitName(pid) { return data.list('units').find(u => u.id === pid)?.name || pid || '' }
function money(n) { return '৳' + Math.round(n || 0).toLocaleString('en-IN') }

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return rows.value
  return rows.value.filter(r => JSON.stringify(r).toLowerCase().includes(q))
})

const colCount = computed(() => {
  const r = rows.value[0]
  return r ? Object.keys(r).length : 3
})

function badge(st) {
  const map = { Paid: 'b-green', Active: 'b-green', Success: 'b-green', Leased: 'b-green', Open: 'b-red', Unpaid: 'b-orange', Overdue: 'b-red', 'In Progress': 'b-blue', 'Awaiting Payment': 'b-orange', 'Pending Registration': 'b-orange', Vacant: 'b-gray', Expired: 'b-gray', Terminated: 'b-red', Approved: 'b-green' }
  return map[st] || 'b-gray'
}

const meta = computed(() => META[props.collection] || { title: props.collection, ico: '📋' })
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
            <tr v-for="r in filtered" :key="r.id">
              <td style="font-weight:700">{{ r.id }}</td>
              <td>{{ r.name || r.title || r.subject || r.desc || r.id }}</td>
              <td class="c-sub">{{ sub(r) }}</td>
              <td><span class="badge" :class="badge(r.status)">{{ r.status || '—' }}</span></td>
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
