<script setup>
import { computed, ref } from 'vue'
import { useDataStore } from '../stores/data'
import { apiCall } from '../api/client'

const data = useDataStore()
const query = ref('')

const tickets = computed(() => data.list('tickets'))
const partners = computed(() => data.list('partners'))

const openCount = computed(() => tickets.value.filter(t => t.status === 'Open').length)
const pendingPay = computed(() => tickets.value.filter(t => t.status === 'Awaiting Payment').length)
const resolvedCount = computed(() => tickets.value.filter(t => ['Resolved', 'Closed', 'Completed'].includes(t.status)).length)
const estCost = computed(() => tickets.value.reduce((a, t) => a + (t.cost || 0), 0))

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return tickets.value
  return tickets.value.filter(t => JSON.stringify(t).toLowerCase().includes(q))
})

function unitName(pid) { return data.list('units').find(u => u.id === pid)?.name || pid || '' }
function partnerName(con) { return con || '—' }
function money(n) { return '৳' + Math.round(n || 0).toLocaleString('en-IN') }
function badge(st) {
  const map = { Open: 'b-red', 'Awaiting Payment': 'b-orange', 'In Progress': 'b-blue', Resolved: 'b-green', Closed: 'b-gray', Completed: 'b-green' }
  return map[st] || 'b-gray'
}

async function updateStatus(t, newStatus) {
  const r = await apiCall('app-crud', { action: 'update', collection: 'tickets', id: t.id, data: { status: newStatus } })
  if (r.ok) { t.status = newStatus; window.__krToast?.('Ticket ' + t.id + ' → ' + newStatus, 'ok') }
  else window.__krToast?.(r.error || 'Update failed', 'error')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🔧 Maintenance</h1>
        <div class="sub">{{ tickets.length }} tickets · live from API</div>
      </div>
      <div class="head-actions">
        <input v-model="query" placeholder="Search tickets…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
      </div>
    </div>

    <div class="stats">
      <div class="stat"><div class="s-label"><span class="s-ico">🔧</span>Open</div><div class="s-value">{{ openCount }}</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">⏳</span>Awaiting payment</div><div class="s-value">{{ pendingPay }}</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">✅</span>Resolved</div><div class="s-value">{{ resolvedCount }}</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">💰</span>Est. cost</div><div class="s-value">{{ money(estCost) }}</div></div>
    </div>

    <div class="panel">
      <div class="tbl-wrap">
        <table class="kr">
          <thead>
            <tr><th>ID</th><th>Unit</th><th>Description</th><th>Reported</th><th>Liability</th><th>Contractor</th><th>Cost</th><th>Status</th><th>Action</th></tr>
          </thead>
          <tbody>
            <tr v-for="t in filtered" :key="t.id">
              <td style="font-weight:700">{{ t.id }}</td>
              <td>{{ unitName(t.u) }}</td>
              <td style="white-space:normal;min-width:200px">{{ t.desc }}</td>
              <td>{{ t.reported }}</td>
              <td>{{ t.liab }}</td>
              <td>{{ partnerName(t.con) }}</td>
              <td>{{ t.cost ? money(t.cost) : '—' }}</td>
              <td><span class="badge" :class="badge(t.status)">{{ t.status }}</span></td>
              <td>
                <select :value="t.status" @change="updateStatus(t, $event.target.value)" style="padding:5px 8px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:12px;font-family:inherit">
                  <option>Open</option>
                  <option>In Progress</option>
                  <option>Awaiting Payment</option>
                  <option>Resolved</option>
                  <option>Closed</option>
                </select>
              </td>
            </tr>
            <tr v-if="!filtered.length"><td :colspan="9" style="text-align:center;color:var(--text-mute);padding:30px">No tickets found.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
