<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { badge, useViewMode, usePager } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('payments')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const paymentsAll = computed(() => data.list('payments'))
const invoicesAll = computed(() => data.list('invoices'))
const leasesAll = computed(() => data.list('leases'))
const tenantsAll = computed(() => data.list('tenants'))
const unitsAll = computed(() => data.list('units'))
const propsAll = computed(() => data.list('properties'))

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const tenantName = (tid) => tenantsAll.value.find(t => t.id === tid)?.name || tid || '—'
const unitName = (uid) => unitsAll.value.find(u => u.id === uid)?.name || uid || '—'
const propName = (pid) => propsAll.value.find(p => p.id === pid)?.name || pid || '—'
const leaseOf = (inv) => leasesAll.value.find(l => l.id === inv?.l) || null
const tenantOf = (inv) => { const l = leaseOf(inv); return l ? tenantName(l.t) : '—' }
const unitOf = (inv) => { const l = leaseOf(inv); return l ? unitName(l.u) : '—' }
const propOf = (inv) => { const l = leaseOf(inv); if (!l) return ''; return unitsAll.value.find(u => u.id === l.u)?.p || '' }
const invOf = (p) => invoicesAll.value.find(i => i.id === p.inv) || null
function monthLabel(m) { if (!m) return '—'; const [y, mo] = m.split('-'); const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']; return (names[parseInt(mo, 10) - 1] || mo) + ' ' + y }
const METHOD_TINT = { bKash: '#e2136e', Nagad: '#f6921e', Rocket: '#8c3494', Bank: '#1f6feb', 'Bank Transfer': '#1f6feb', Cheque: '#8957e5', Cash: '#12a150', Card: '#c2410c', Manual: '#6b7280' }
const methodColor = (m) => METHOD_TINT[m] || '#6b7280'

// ── KPIs ──
const kpis = computed(() => {
  const ps = paymentsAll.value
  const tot = ps.reduce((s, p) => s + (p.amount || 0), 0)
  const success = ps.filter(p => String(p.status).toLowerCase() === 'success').reduce((s, p) => s + (p.amount || 0), 0)
  const rate = tot ? Math.round(success / tot * 100) : 0
  const thisM = new Date().toISOString().slice(0, 7)
  const mTot = ps.filter(p => (p.date || '').startsWith(thisM)).reduce((s, p) => s + (p.amount || 0), 0)
  const byMethod = {}
  ps.forEach(p => { byMethod[p.method || 'Manual'] = (byMethod[p.method || 'Manual'] || 0) + (p.amount || 0) })
  const topMethod = Object.entries(byMethod).sort((a, b) => b[1] - a[1])[0]
  const okCount = ps.filter(p => String(p.status).toLowerCase() === 'success').length
  return [
    { label: 'Payments', ico: '💳', value: ps.length, trend: okCount + ' succeeded' },
    { label: 'Total', ico: '💰', value: money(tot), trend: 'all payment attempts' },
    { label: 'Success rate', ico: '✅', value: rate + '%', trend: money(success) + ' settled', ok: rate === 100 },
    { label: 'This month', ico: '📅', value: money(mTot), trend: monthLabel(thisM) },
    { label: 'Top method', ico: '🏦', value: topMethod ? topMethod[0] : '—', trend: topMethod ? money(topMethod[1]) : '' },
    { label: 'Settled', ico: '✔️', value: money(success), trend: okCount + ' of ' + ps.length + ' payments' },
  ]
})

// ── filters / sort ──
const query = ref('')
const statusFilter = ref('')
const methodFilter = ref('')
const monthFilter = ref('')
const propFilter = ref('')
const sortBy = ref('date')
const statusOptions = computed(() => [...new Set(paymentsAll.value.map(p => p.status).filter(Boolean))].sort())
const methodOptions = computed(() => [...new Set(paymentsAll.value.map(p => p.method).filter(Boolean))].sort())
const monthOptions = computed(() => [...new Set(paymentsAll.value.map(p => (p.date || '').slice(0, 7)).filter(Boolean))].sort().reverse())
const propOptions = computed(() => propsAll.value.map(p => ({ id: p.id, name: p.name })))
const filtered = computed(() => {
  let out = paymentsAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(p => p.id.toLowerCase().includes(q) || p.inv.toLowerCase().includes(q) || tenantOf(invOf(p)).toLowerCase().includes(q) || unitOf(invOf(p)).toLowerCase().includes(q) || (p.ref || '').toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(p => p.status === statusFilter.value)
  if (methodFilter.value) out = out.filter(p => p.method === methodFilter.value)
  if (monthFilter.value) out = out.filter(p => (p.date || '').startsWith(monthFilter.value))
  if (propFilter.value) out = out.filter(p => propOf(invOf(p)) === propFilter.value)
  const get = (p) => sortBy.value === 'amount' ? (p.amount || 0) : (p.date || '')
  return [...out].sort((a, b) => typeof get(a) === 'string' ? String(get(b)).localeCompare(String(get(a))) : get(b) - get(a))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)
function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const lines = [['id', 'invoice', 'tenant', 'unit', 'amount', 'method', 'ref', 'date', 'status'].map(esc).join(',')]
  rows.forEach(p => lines.push([p.id, p.inv, tenantOf(invOf(p)), unitOf(invOf(p)), p.amount, p.method, p.ref, p.date, p.status].map(esc).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'payments.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(p) { sel.value = p }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const p = paymentsAll.value.find(x => x.id === id); if (p) openDetail(p) }
}, { immediate: true })
const selInv = computed(() => sel.value ? invOf(sel.value) : null)
const selTenantObj = computed(() => { const l = sel.value ? leaseOf(invOf(sel.value)) : null; return l ? tenantsAll.value.find(t => t.id === l.t) : null })
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>💳 Payments</h1>
        <div class="sub">{{ paymentsAll.length }} payments · {{ money(kpis[1]?.value || 0) }} total · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search payment, invoice, tenant…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="methodFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All methods</option>
          <option v-for="m in methodOptions" :key="m" :value="m">{{ m }}</option>
        </select>
        <select v-model="monthFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All months</option>
          <option v-for="m in monthOptions" :key="m" :value="m">{{ monthLabel(m) }}</option>
        </select>
        <select v-model="propFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All properties</option>
          <option v-for="p in propOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <select v-model="sortBy" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="date">Sort: Date</option>
          <option value="amount">Sort: Amount</option>
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

    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px">
      <div v-for="p in paged" :key="p.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(p)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">💳</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="badge(p.status)">{{ p.status }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ p.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div>
            <div style="font-weight:800;font-size:18px;letter-spacing:-.3px">{{ money(p.amount) }}</div>
            <div class="c-sub" style="margin-top:2px">👤 {{ tenantOf(invOf(p)) }} · 🚪 {{ unitOf(invOf(p)) }} · {{ propName(propOf(invOf(p))) }}</div>
          </div>
          <div style="display:flex;gap:13px;font-size:12px;flex-wrap:wrap">
            <span class="c-sub" title="Invoice">🧾 <a @click.stop="go('/invoices', { open: p.inv })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ p.inv }}</a></span>
            <span class="c-sub" title="Ref">🔖 {{ p.ref || '—' }}</span>
            <span class="c-sub" title="Date">📅 {{ p.date || '—' }}</span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
            <span class="badge" :style="{ background: methodColor(p.method) + '22', color: methodColor(p.method), border: '1px solid ' + methodColor(p.method) + '44' }">{{ p.method || 'Manual' }}</span>
          </div>
        </div>
      </div>
    </div>
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>Payment</th><th>Invoice</th><th>Tenant</th><th>Unit</th><th>Amount</th><th>Method</th><th>Ref</th><th>Date</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="p in paged" :key="p.id" style="cursor:pointer" @click="openDetail(p)">
              <td style="white-space:nowrap"><b>{{ p.id }}</b></td>
              <td style="white-space:nowrap"><a @click.stop="go('/invoices', { open: p.inv })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ p.inv }}</a></td>
              <td style="white-space:nowrap"><a @click.stop="go('/tenants', { open: leaseOf(invOf(p))?.t })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ tenantOf(invOf(p)) }}</a></td>
              <td style="white-space:nowrap" class="c-sub">{{ unitOf(invOf(p)) }}</td>
              <td style="font-weight:700;white-space:nowrap">{{ money(p.amount) }}</td>
              <td style="white-space:nowrap"><span class="badge" :style="{ background: methodColor(p.method) + '22', color: methodColor(p.method) }">{{ p.method || 'Manual' }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ p.ref || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ p.date || '—' }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="badge(p.status)">{{ p.status }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No payments found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(620px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">💳</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="badge(sel.status)" style="background:#ffffff">{{ sel.status }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.inv }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.id }}</h2>
          <div class="c-sub" style="margin-top:3px">👤 <a @click.stop="go('/tenants', { open: leaseOf(invOf(sel))?.t })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ tenantOf(invOf(sel)) }}</a> · 🚪 <a @click.stop="go('/units', { open: leaseOf(invOf(sel))?.u })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ unitOf(invOf(sel)) }}</a> · 🏢 {{ propName(propOf(invOf(sel))) }}</div>

          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Amount</div>
              <div style="font-size:15px;font-weight:800;margin-top:2px">{{ money(sel.amount) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Method</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ sel.method || 'Manual' }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Reference</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px;overflow:hidden;text-overflow:ellipsis">{{ sel.ref || '—' }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Date</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ sel.date || '—' }}</div>
            </div>
          </div>

          <div v-if="selInv" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">🧾 Invoice</div>
            <div style="font-weight:800;font-size:14px;cursor:pointer" @click="go('/invoices', { open: selInv.id })">{{ selInv.id }} ↗</div>
            <div class="c-sub" style="font-size:11.5px;margin-top:3px">{{ selInv.m ? monthLabel(selInv.m) : '—' }} · {{ money(selInv.net) }} net · lease <a @click.stop="go('/leases', { open: selInv.l })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ selInv.l }}</a></div>
          </div>

          <div v-if="selTenantObj" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">👤 Tenant</div>
            <div style="font-weight:800;font-size:14px;cursor:pointer" @click="go('/tenants', { open: selTenantObj.id })">{{ selTenantObj.name }} ↗</div>
            <div class="c-sub" style="font-size:11.5px;margin-top:3px">{{ selTenantObj.phone || '—' }} · {{ selTenantObj.kind || '—' }}<template v-if="selTenantObj.nrb"> · NRB</template></div>
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
