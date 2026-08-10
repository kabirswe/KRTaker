<script setup>
import { computed } from 'vue'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'

const data = useDataStore()
const auth = useAuthStore()

const money = n => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const list = k => data.list(k)
const total = k => list(k).length

// ── Role awareness ──
const role = computed(() => data.previewRole || data.user?.role || 'owner')
const isTenant = computed(() => role.value === 'tenant')
const isPartner = computed(() => role.value === 'partner')
const isResident = computed(() => isTenant.value || isPartner.value)
const isStaff = computed(() => !isResident.value)

const roleLabel = computed(() => {
  const m = { superadmin: 'Super Admin', owner: 'Owner', manager: 'Property Manager', staff: 'Staff', tenant: 'Tenant', partner: 'Partner' }
  return m[role.value] || role.value
})

const today = computed(() => new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))
const firstName = computed(() => (data.user?.name || '').split(' ')[0] || 'there')

// ── Portfolio stats (staff/owner view) ──
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

// ── Financial health (staff/owner) ──
const totalBilled = computed(() => list('invoices').reduce((a, i) => a + (i.net || 0), 0))
const totalCollected = computed(() => list('payments').reduce((a, p) => a + (p.amount || 0), 0))
const outstanding = computed(() => Math.max(0, totalBilled.value - totalCollected.value))
const collectRate = computed(() => totalBilled.value ? Math.min(100, Math.round(totalCollected.value / totalBilled.value * 100)) : 0)
const invByStatus = computed(() => {
  const m = {}
  list('invoices').forEach(i => { const k = i.status || 'Pending'; m[k] = (m[k] || 0) + 1 })
  return m
})
const statusPct = (k) => { const n = invByStatus.value[k] || 0; const tot = list('invoices').length || 1; return Math.round(n / tot * 100) }
const statusBadge = (k) => {
  const m = { Paid: 'b-green', Active: 'b-green', Success: 'b-green', Unpaid: 'b-orange', Overdue: 'b-red', 'Awaiting Payment': 'b-orange', Pending: 'b-orange' }
  return m[k] || 'b-gray'
}

// ── Monthly billing chart (pure SVG) ──
const monthly = computed(() => {
  const m = {}
  list('invoices').forEach(i => { const k = i.m || ''; m[k] = (m[k] || 0) + (i.net || 0) })
  return Object.entries(m).sort().slice(-6).map(([mm, v]) => ({ m: mm, v }))
})
const chartMax = computed(() => Math.max(...monthly.value.map(x => x.v), 1))
const barH = (v) => Math.max(4, Math.round(v / chartMax.value * 110))
const barW = computed(() => monthly.value.length ? Math.floor(520 / monthly.value.length) : 60)

// ── Upcoming lease expiries (staff/owner) ──
const leaseEnd = (l) => l.end || l.until || l.to || ''
const expiring = computed(() => list('leases')
  .map(l => ({ ...l, _end: leaseEnd(l) }))
  .filter(l => l._end)
  .map(l => ({ ...l, _days: Math.round((new Date(l._end) - Date.now()) / 86400000) }))
  .filter(l => l._days >= 0 && l._days <= 90)
  .sort((a, b) => a._days - b._days)
  .slice(0, 5))
const daysLabel = (d) => d === 0 ? 'today' : d === 1 ? 'tomorrow' : `${d} days`
const unitName = (id) => list('units').find(u => u.id === id)?.name || id || '—'
const tenantName = (id) => list('tenants').find(t => t.id === id)?.name || id || '—'

// ── Tenant / partner personal view ──
const myLease = computed(() => list('leases')[0] || null)
const myUnit = computed(() => (myLease.value && list('units').find(u => u.id === myLease.value.u)) || null)
const myProp = computed(() => (myUnit.value && list('properties').find(p => p.id === myUnit.value.p)) || null)
const myInvoices = computed(() => list('invoices'))
const myDue = computed(() => myInvoices.value.filter(i => (i.status || '').match(/unpaid|overdue|pending|awaiting/i)).reduce((a, i) => a + (i.net || 0), 0))
const myPaid = computed(() => list('payments').reduce((a, p) => a + (p.amount || 0), 0))
const myTickets = computed(() => list('tickets'))
const myOpenTickets = computed(() => myTickets.value.filter(t => t.status === 'Open'))
const myPayouts = computed(() => list('vendor_payouts').reduce((a, p) => a + (p.amount || 0), 0))
const myPartnerInvoices = computed(() => list('partner_invoices'))

const openTickets = computed(() => list('tickets').filter(t => t.status === 'Open'))
const recentReceipts = computed(() => [...list('receipts')].sort((a, b) => (b.date || '').localeCompare(a.date || '')).slice(0, 5))

// ── Quick actions ──
const actions = computed(() => {
  if (isTenant.value) return [
    { label: 'Pay rent', ico: '💳', to: '/invoices' },
    { label: 'Raise ticket', ico: '🔧', to: '/maintenance' },
    { label: 'My lease', ico: '📄', to: '/leases' },
    { label: 'Ask AI', ico: '🤖', to: '/ai' },
  ]
  if (isPartner.value) return [
    { label: 'My tickets', ico: '🔧', to: '/maintenance' },
    { label: 'Invoices', ico: '🧾', to: '/partner-invoices' },
    { label: 'Payouts', ico: '💰', to: '/vendor-payouts' },
    { label: 'Ask AI', ico: '🤖', to: '/ai' },
  ]
  return [
    { label: 'New invoice', ico: '🧾', to: '/invoices' },
    { label: 'Add tenant', ico: '👤', to: '/tenants' },
    { label: 'Maintenance', ico: '🔧', to: '/maintenance' },
    { label: 'Reports', ico: '📈', to: '/analytics' },
    { label: 'Legal', ico: '⚖️', to: '/legal' },
    { label: 'Ask AI', ico: '🤖', to: '/ai' },
  ]
})

function refresh() { data.bootstrap() }
</script>

<template>
  <div>
    <!-- Offline banner -->
    <div v-if="data.offline" class="panel" style="margin-bottom:16px;padding:14px 18px;border-left:4px solid var(--warn)">
      <b>📡 You're offline</b> — showing the last-loaded snapshot. Reconnect and refresh to get live data.
    </div>

    <!-- Refresh hint -->
    <div v-if="data.loading" class="panel" style="margin-bottom:16px;padding:12px 18px;border-left:4px solid var(--accent)">
      🔄 Refreshing data…
    </div>

    <!-- Hero -->
    <div class="page-head">
      <div>
        <h1>{{ isResident ? 'My home' : 'Overview' }}</h1>
        <div class="sub">
          {{ isResident ? `${firstName}, here's your ${roleLabel.toLowerCase()} view — ${today}.` : `Welcome back, ${firstName} — ${today}.` }}
        </div>
      </div>
      <div class="head-actions">
        <span class="badge" :class="role === 'superadmin' ? 'b-blue' : isResident ? 'b-green' : 'b-gray'">{{ roleLabel }}</span>
        <button @click="refresh" class="btn-ghost" title="Refresh data">🔄 Refresh</button>
      </div>
    </div>

    <!-- Quick actions -->
    <div class="quick" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
      <router-link v-for="a in actions" :key="a.label" :to="a.to" class="chip" style="display:inline-flex;align-items:center;gap:7px;padding:9px 15px;border:1px solid var(--border);border-radius:12px;background:var(--bg-alt);font-size:13px;font-weight:600;color:var(--text);text-decoration:none">
        <span>{{ a.ico }}</span>{{ a.label }}
      </router-link>
    </div>

    <!-- ── STAFF / OWNER PORTFOLIO VIEW ── -->
    <template v-if="isStaff">
      <div class="stats">
        <div v-for="s in stats" :key="s.label" class="stat">
          <div class="s-label"><span class="s-ico">{{ s.ico }}</span>{{ s.label }}</div>
          <div class="s-value">{{ s.value }}</div>
        </div>
      </div>

      <div class="grid grid-2">
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">📊</span>Monthly billing</div></div>
          <div class="panel-b">
            <div v-if="monthly.length">
              <svg :viewBox="`0 0 560 150`" style="width:100%;height:auto;display:block">
                <line v-for="i in [0,1,2,3]" :key="i" x1="0" :y1="12 + i * 32" x2="560" :y2="12 + i * 32" stroke="var(--border)" stroke-width="1" stroke-dasharray="3 4"/>
                <g v-for="(b, i) in monthly" :key="b.m">
                  <rect :x="30 + i * (barW + 12)" :y="120 - barH(b.v)" :width="barW" :height="barH(b.v)" rx="6" fill="var(--accent)" opacity="0.9">
                    <title>{{ b.m }} — {{ money(b.v) }}</title>
                  </rect>
                  <text :x="30 + i * (barW + 12) + barW / 2" y="136" text-anchor="middle" font-size="10" fill="var(--text-mute)">{{ b.m.slice(2) }}</text>
                  <text :x="30 + i * (barW + 12) + barW / 2" :y="110 - barH(b.v)" text-anchor="middle" font-size="9" fill="var(--text-mute)">{{ money(b.v).replace('৳', '৳') }}</text>
                </g>
              </svg>
            </div>
            <div v-else class="c-sub">No invoice data yet.</div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">💰</span>Financial health</div></div>
          <div class="panel-b">
            <div class="kv"><span class="k">Total billed</span><span class="v">{{ money(totalBilled) }}</span></div>
            <div class="kv"><span class="k">Collected</span><span class="v">{{ money(totalCollected) }}</span></div>
            <div class="kv"><span class="k">Outstanding</span><span class="v" :style="{ color: outstanding ? 'var(--danger)' : 'var(--ok)' }">{{ money(outstanding) }}</span></div>
            <div class="kv"><span class="k">Collection rate</span><span class="v">{{ collectRate }}%</span></div>
            <div style="height:8px;border-radius:6px;background:var(--bg-alt);overflow:hidden;margin:10px 0 14px">
              <div :style="{ width: collectRate + '%', height: '100%', background: collectRate > 75 ? 'var(--ok)' : collectRate > 40 ? 'var(--warn)' : 'var(--danger)', borderRadius: 6 }"></div>
            </div>
            <div v-if="Object.keys(invByStatus).length" style="display:flex;flex-wrap:wrap;gap:6px">
              <span v-for="(n, k) in invByStatus" :key="k" class="badge" :class="statusBadge(k)" style="font-size:11px">{{ k }} · {{ n }} ({{ statusPct(k) }}%)</span>
            </div>
          </div>
        </div>
      </div>

      <div class="grid grid-2" style="margin-top:18px">
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">🔧</span>Open tickets</div><router-link to="/maintenance" class="link">View all →</router-link></div>
          <div class="panel-b">
            <div v-if="openTickets.length">
              <div v-for="t in openTickets.slice(0, 5)" :key="t.id" style="padding:9px 0;border-bottom:1px dashed var(--border);font-size:13px">
                <div style="font-weight:700">{{ t.id }} — {{ t.desc }}</div>
                <div class="c-sub">{{ t.liab }} liability · {{ t.con || 'unassigned' }}</div>
              </div>
            </div>
            <div v-else class="c-sub">No open tickets 🎉</div>
          </div>
        </div>

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
      </div>

      <div v-if="expiring.length" class="panel" style="margin-top:18px">
        <div class="panel-h"><div class="t"><span class="pi">⏳</span>Leases expiring soon</div><router-link to="/leases" class="link">All leases →</router-link></div>
        <div class="panel-b">
          <div class="tbl-wrap">
            <table class="kr">
              <thead><tr><th>Lease</th><th>Unit</th><th>Tenant</th><th>Rent</th><th>Expires</th></tr></thead>
              <tbody>
                <tr v-for="l in expiring" :key="l.id">
                  <td style="font-weight:700">{{ l.id }}</td>
                  <td>{{ unitName(l.u) }}</td>
                  <td>{{ tenantName(l.t) }}</td>
                  <td>{{ money(l.rent) }}/mo</td>
                  <td><span class="badge" :class="l._days <= 14 ? 'b-red' : l._days <= 45 ? 'b-orange' : 'b-gray'">{{ l._end }} · {{ daysLabel(l._days) }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>

    <!-- ── TENANT VIEW ── -->
    <template v-else-if="isTenant">
      <div class="stats" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
        <div class="stat">
          <div class="s-label"><span class="s-ico">🏠</span>My unit</div>
          <div class="s-value" style="font-size:17px">{{ myUnit?.name || myLease?.u || '—' }}</div>
        </div>
        <div class="stat">
          <div class="s-label"><span class="s-ico">🏢</span>Property</div>
          <div class="s-value" style="font-size:17px">{{ myProp?.name || '—' }}</div>
        </div>
        <div class="stat">
          <div class="s-label"><span class="s-ico">📄</span>Rent / month</div>
          <div class="s-value" style="font-size:17px">{{ money(myLease?.rent || myUnit?.rent) }}</div>
        </div>
        <div class="stat">
          <div class="s-label"><span class="s-ico">🧾</span>Due balance</div>
          <div class="s-value" :style="{ fontSize: '17px', color: myDue ? 'var(--danger)' : 'var(--ok)' }">{{ money(myDue) }}</div>
        </div>
        <div class="stat">
          <div class="s-label"><span class="s-ico">💳</span>Paid to date</div>
          <div class="s-value" style="font-size:17px">{{ money(myPaid) }}</div>
        </div>
        <div class="stat">
          <div class="s-label"><span class="s-ico">🔧</span>Open tickets</div>
          <div class="s-value" style="font-size:17px">{{ myOpenTickets.length }}</div>
        </div>
      </div>

      <div class="grid grid-2">
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">🧾</span>My invoices</div><router-link to="/invoices" class="link">View all →</router-link></div>
          <div class="panel-b">
            <div v-if="myInvoices.length" class="tbl-wrap">
              <table class="kr">
                <thead><tr><th>ID</th><th>Month</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                  <tr v-for="i in [...myInvoices].sort((a,b)=>(b.m||'').localeCompare(a.m||'')).slice(0,6)" :key="i.id">
                    <td style="font-weight:700">{{ i.id }}</td>
                    <td>{{ i.m }}</td>
                    <td>{{ money(i.net) }}</td>
                    <td><span class="badge" :class="statusBadge(i.status || 'Pending')">{{ i.status || 'Pending' }}</span></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else class="c-sub">No invoices yet.</div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">🔧</span>My tickets</div><router-link to="/maintenance" class="link">View all →</router-link></div>
          <div class="panel-b">
            <div v-if="myTickets.length">
              <div v-for="t in myTickets.slice(0, 5)" :key="t.id" style="padding:9px 0;border-bottom:1px dashed var(--border);font-size:13px">
                <div style="font-weight:700">{{ t.id }} — {{ t.desc }}</div>
                <div class="c-sub">{{ t.liab }} liability · <span class="badge" :class="t.status === 'Open' ? 'b-red' : 'b-green'" style="font-size:10px">{{ t.status }}</span></div>
              </div>
            </div>
            <div v-else class="c-sub">No tickets yet.</div>
          </div>
        </div>
      </div>
    </template>

    <!-- ── PARTNER VIEW ── -->
    <template v-else>
      <div class="stats" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
        <div class="stat">
          <div class="s-label"><span class="s-ico">🔧</span>My tickets</div>
          <div class="s-value" style="font-size:17px">{{ myTickets.length }}</div>
        </div>
        <div class="stat">
          <div class="s-label"><span class="s-ico">✅</span>Open</div>
          <div class="s-value" style="font-size:17px">{{ myOpenTickets.length }}</div>
        </div>
        <div class="stat">
          <div class="s-label"><span class="s-ico">🧾</span>Invoices</div>
          <div class="s-value" style="font-size:17px">{{ myPartnerInvoices.length }}</div>
        </div>
        <div class="stat">
          <div class="s-label"><span class="s-ico">💰</span>Payouts</div>
          <div class="s-value" style="font-size:17px">{{ money(myPayouts) }}</div>
        </div>
      </div>

      <div class="grid grid-2">
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">🔧</span>Assigned tickets</div><router-link to="/maintenance" class="link">View all →</router-link></div>
          <div class="panel-b">
            <div v-if="myTickets.length">
              <div v-for="t in myTickets.slice(0, 6)" :key="t.id" style="padding:9px 0;border-bottom:1px dashed var(--border);font-size:13px">
                <div style="font-weight:700">{{ t.id }} — {{ t.desc }}</div>
                <div class="c-sub">{{ t.liab }} liability · <span class="badge" :class="t.status === 'Open' ? 'b-red' : 'b-green'" style="font-size:10px">{{ t.status }}</span></div>
              </div>
            </div>
            <div v-else class="c-sub">No tickets assigned yet.</div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">🧾</span>Partner invoices</div><router-link to="/partner-invoices" class="link">View all →</router-link></div>
          <div class="panel-b">
            <div v-if="myPartnerInvoices.length" class="tbl-wrap">
              <table class="kr">
                <thead><tr><th>ID</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                  <tr v-for="i in myPartnerInvoices.slice(0, 6)" :key="i.id">
                    <td style="font-weight:700">{{ i.id }}</td>
                    <td>{{ money(i.amount) }}</td>
                    <td><span class="badge" :class="statusBadge(i.status || 'Pending')">{{ i.status || 'Pending' }}</span></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else class="c-sub">No invoices yet.</div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
