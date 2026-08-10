<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'

const data = useDataStore()
const auth = useAuthStore()
const route = useRoute()

const money = n => '৳' + Math.round(n || 0).toLocaleString('en-IN')

const list = (k) => data.list(k)
const total = (k) => list(k).length

const stats = computed(() => [
  { label: 'Properties', ico: '🏢', value: total('properties') },
  { label: 'Units', ico: '🚪', value: total('units') },
  { label: 'Tenants', ico: '👤', value: total('tenants') },
  { label: 'Leases', ico: '📄', value: total('leases') },
  { label: 'Invoices', ico: '🧾', value: total('invoices') },
  { label: 'Receipts', ico: '📎', value: total('receipts') },
  { label: 'Payments', ico: '💳', value: total('payments') },
  { label: 'Open tickets', ico: '🔧', value: list('tickets').filter(t => t.status === 'Open').length },
])

const monthly = computed(() => {
  const inv = list('invoices')
  const m = {}
  inv.forEach(i => { const k = i.m || ''; m[k] = (m[k] || 0) + (i.net || 0) })
  return Object.entries(m).sort().slice(-6).map(([k, v]) => ({ m: k, v }))
})

const totalCollected = computed(() => list('payments').reduce((a, p) => a + (p.amount || 0), 0))
const openTickets = computed(() => list('tickets').filter(t => t.status === 'Open'))
const recentReceipts = computed(() => [...list('receipts')].sort((a, b) => (b.date || '').localeCompare(a.date || '')).slice(0, 5))

const stub = route.query.stub || ''
</script>

<template>
  <div>
    <!-- Not-yet-ported module banner -->
    <div v-if="stub" class="panel" style="margin-bottom:18px;padding:18px;border-left:4px solid var(--warn)">
      <div style="font-weight:800;font-size:15px">🛠️ Module “{{ stub }}” — porting in progress</div>
      <div class="c-sub" style="margin-top:4px">This view is being migrated from dashboard-v2. Use the v2 dashboard until it lands (Phase 3).</div>
    </div>

    <div class="page-head">
      <div>
        <h1>Overview</h1>
        <div class="sub">Welcome back — here's what's happening across your portfolio</div>
      </div>
    </div>

    <div class="stats">
      <div v-for="s in stats" :key="s.label" class="stat">
        <div class="s-label"><span class="s-ico">{{ s.ico }}</span>{{ s.label }}</div>
        <div class="s-value">{{ s.value }}</div>
      </div>
    </div>

    <div class="grid grid-2">
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">🧾</span>Monthly billing</div></div>
        <div class="panel-b">
          <div v-if="monthly.length" class="tbl-wrap">
            <table class="kr">
              <thead><tr><th>Month</th><th>Billed</th><th>Invoices</th></tr></thead>
              <tbody>
                <tr v-for="r in monthly" :key="r.m">
                  <td>{{ r.m }}</td>
                  <td>{{ money(r.v) }}</td>
                  <td>{{ list('invoices').filter(i => i.m === r.m).length }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="c-sub">No invoice data yet.</div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">🔧</span>Open tickets</div><router-link to="/maintenance" class="link">View all →</router-link></div>
        <div class="panel-b">
          <div v-if="openTickets.length">
            <div v-for="t in openTickets.slice(0, 5)" :key="t.id" style="padding:9px 0;border-bottom:1px dashed var(--border);font-size:13px">
              <div style="font-weight:700">{{ t.id }} — {{ t.desc }}</div>
              <div class="c-sub">{{ t.liab }} liability · {{ t.status }}</div>
            </div>
          </div>
          <div v-else class="c-sub">No open tickets 🎉</div>
        </div>
      </div>
    </div>

    <div class="grid grid-2" style="margin-top:18px">
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">📎</span>Recent receipts</div></div>
        <div class="panel-b">
          <div v-if="recentReceipts.length" class="tbl-wrap">
            <table class="kr">
              <thead><tr><th>ID</th><th>Date</th><th>Method</th><th>Amount</th></tr></thead>
              <tbody>
                <tr v-for="r in recentReceipts" :key="r.id">
                  <td>{{ r.id }}</td>
                  <td>{{ r.date }}</td>
                  <td>{{ r.method }}</td>
                  <td>{{ money(r.amount) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="c-sub">No receipts yet.</div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">💰</span>Collections</div></div>
        <div class="panel-b">
          <div class="kv"><span class="k">Total collected</span><span class="v">{{ money(totalCollected) }}</span></div>
          <div class="kv"><span class="k">Payments recorded</span><span class="v">{{ total('payments') }}</span></div>
          <div class="kv"><span class="k">Signed in as</span><span class="v">{{ data.user?.name }} ({{ data.previewRole }})</span></div>
          <div class="kv"><span class="k">Plan</span><span class="v">{{ data.subscription?.plan_name || (auth.user?.is_staff ? 'Enterprise' : 'Trial') }}</span></div>
        </div>
      </div>
    </div>
  </div>
</template>
