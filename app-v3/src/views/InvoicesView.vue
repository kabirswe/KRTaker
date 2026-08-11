<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { badge, useViewMode, usePager } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('invoices')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'accountant'].includes(auth.user?.role || ''))

const invoicesAll = computed(() => data.list('invoices'))
const leasesAll = computed(() => data.list('leases'))
const tenantsAll = computed(() => data.list('tenants'))
const unitsAll = computed(() => data.list('units'))
const propsAll = computed(() => data.list('properties'))
const paymentsAll = computed(() => data.list('payments'))
const receiptsAll = computed(() => data.list('receipts'))

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const tenantName = (tid) => tenantsAll.value.find(t => t.id === tid)?.name || tid || '—'
const unitName = (uid) => unitsAll.value.find(u => u.id === uid)?.name || uid || '—'
const propName = (pid) => propsAll.value.find(p => p.id === pid)?.name || pid || '—'
const leaseOf = (inv) => leasesAll.value.find(l => l.id === inv.l) || null
const tenantOf = (inv) => { const l = leaseOf(inv); return l ? tenantName(l.t) : '—' }
const unitOf = (inv) => { const l = leaseOf(inv); return l ? unitName(l.u) : '—' }
const propOf = (inv) => { const l = leaseOf(inv); if (!l) return ''; return unitsAll.value.find(u => u.id === l.u)?.p || '' }

function invPaid(inv) { return paymentsAll.value.filter(p => p.inv === inv.id && String(p.status).toLowerCase() === 'success').reduce((s, p) => s + (p.amount || 0), 0) }
function invDue(inv) { return Math.max(0, (inv.net || 0) - invPaid(inv)) }
function invStatusRow(inv) { return invDue(inv) <= 0 ? 'Paid' : (invPaid(inv) > 0 ? 'Partial' : 'Unpaid') }
function monthLabel(m) { if (!m) return '—'; const [y, mo] = m.split('-'); const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']; return (names[parseInt(mo, 10) - 1] || mo) + ' ' + y }
const paysOfInv = (inv) => paymentsAll.value.filter(p => p.inv === inv.id).sort((a, b) => String(b.date).localeCompare(String(a.date)))
const rcpOfInv = (inv) => receiptsAll.value.filter(r => r.inv === inv.id).sort((a, b) => String(b.date).localeCompare(String(a.date)))

// ── KPIs ──
const kpis = computed(() => {
  const paid = invoicesAll.value.filter(i => invStatusRow(i) === 'Paid')
  const partial = invoicesAll.value.filter(i => invStatusRow(i) === 'Partial')
  const unpaid = invoicesAll.value.filter(i => invStatusRow(i) === 'Unpaid')
  const tot = invoicesAll.value.reduce((s, i) => s + (i.net || 0), 0)
  const col = invoicesAll.value.reduce((s, i) => s + invPaid(i), 0)
  const due = invoicesAll.value.reduce((s, i) => s + invDue(i), 0)
  const rate = tot ? Math.round(col / tot * 100) : 0
  const thisM = new Date().toISOString().slice(0, 7)
  const mDue = invoicesAll.value.filter(i => i.m === thisM).reduce((s, i) => s + invDue(i), 0)
  return [
    { label: 'Invoices', ico: '🧾', value: invoicesAll.value.length, trend: `${paid.length} paid` },
    { label: 'Billed', ico: '📊', value: money(tot), trend: 'total net' },
    { label: 'Collected', ico: '💳', value: money(col), trend: rate + '% collection' },
    { label: 'Outstanding', ico: '⏳', value: money(due), trend: due ? 'needs follow-up' : 'all clear', ok: due === 0 },
    { label: 'This month due', ico: '📅', value: money(mDue), trend: monthLabel(thisM) },
    { label: 'Partial', ico: '🧩', value: partial.length, trend: partial.length ? 'partly paid' : 'none', ok: partial.length === 0 },
  ]
})

// ── filters / sort ──
const query = ref('')
const statusFilter = ref('')
const monthFilter = ref('')
const propFilter = ref('')
const sortBy = ref('m')
const statusOptions = ['Paid', 'Partial', 'Unpaid']
const monthOptions = computed(() => [...new Set(invoicesAll.value.map(i => i.m).filter(Boolean))].sort().reverse())
const propOptions = computed(() => propsAll.value.map(p => ({ id: p.id, name: p.name })))
const filtered = computed(() => {
  let out = invoicesAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(i => i.id.toLowerCase().includes(q) || i.l.toLowerCase().includes(q) || tenantOf(i).toLowerCase().includes(q) || unitOf(i).toLowerCase().includes(q) || (i.m || '').includes(q))
  if (statusFilter.value) out = out.filter(i => invStatusRow(i) === statusFilter.value)
  if (monthFilter.value) out = out.filter(i => i.m === monthFilter.value)
  if (propFilter.value) out = out.filter(i => propOf(i) === propFilter.value)
  const get = (i) => sortBy.value === 'net' ? (i.net || 0) : sortBy.value === 'due' ? invDue(i) : (i.m || '')
  return [...out].sort((a, b) => typeof get(a) === 'string' ? String(get(b)).localeCompare(String(get(a))) : get(b) - get(a))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)
function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const lines = [['id', 'lease', 'month', 'tenant', 'unit', 'net', 'paid', 'due', 'status'].map(esc).join(',')]
  rows.forEach(r => lines.push([r.id, r.l, r.m, tenantOf(r), unitOf(r), r.net, invPaid(r), invDue(r), invStatusRow(r)].map(esc).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'invoices.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(i) { sel.value = i }
function closeDetail() { sel.value = null }
// deep link: /invoices?open=INV-001[&pay=1]  (pay=1 → auto-open the payment modal; used by invoice email pay_url)
watch(() => route.query.open, (id) => {
  if (id) {
    const i = invoicesAll.value.find(x => x.id === id)
    if (i) { openDetail(i); if (route.query.pay === '1' && invDue(i) > 0) openPay(i) }
  }
}, { immediate: true })
const selPays = computed(() => sel.value ? paysOfInv(sel.value) : [])
const selRcps = computed(() => sel.value ? rcpOfInv(sel.value) : [])
const selTenantObj = computed(() => { const l = sel.value ? leaseOf(sel.value) : null; return l ? tenantsAll.value.find(t => t.id === l.t) : null })

// ── payment modal ──
const payModal = ref(null)
const paySaving = ref(false)
const PAY_METHODS = ['Manual', 'Cash', 'Bank Transfer', 'Cheque', 'bKash', 'Nagad', 'Rocket', 'Card']
function openPay(i) { payModal.value = { inv: i, amount: Math.max(0, invDue(i)), date: new Date().toISOString().slice(0, 10), method: 'Manual', sig: '' } }
async function submitPay() {
  const m = payModal.value
  if (!m || !m.amount || m.amount <= 0) { window.__krToast?.('Enter a positive amount', 'error'); return }
  paySaving.value = true
  try {
    const r = await apiCall('app-invoice-pay', { invoice_id: m.inv.id, amount: Math.round(m.amount), date: m.date, method: m.method, sig: m.sig })
    if (r.ok) { window.__krToast?.(`💳 ${m.inv.id} → ${r.status} (paid ৳${(r.paid || 0).toLocaleString('en-IN')})`, 'ok'); payModal.value = null; await data.bootstrap() }
    else window.__krToast?.(r.error || 'Payment failed', 'error')
  } finally { paySaving.value = false }
}

// ── actions ──
const emailBusy = ref('')
async function emailInv(i) {
  emailBusy.value = i.id
  try {
    const r = await apiCall('app-invoice-email', { invoice_id: i.id })
    if (r.ok) {
      if (r.emailed === false) window.__krToast?.(r.suppressed ? `📧 Suppressed — ${r.reason || ''}` : '📧 No email on file', 'error')
      else window.__krToast?.(`📧 ${i.id} emailed to ${r.to || ''}`, 'ok')
    } else window.__krToast?.(r.error || 'Email failed', 'error')
  } finally { emailBusy.value = '' }
}
const printBusy = ref('')
async function printInv(i) {
  printBusy.value = i.id
  try {
    const res = await fetch('https://krtaker.com/api/app-invoice-print?id=' + encodeURIComponent(i.id), { headers: { Authorization: 'Bearer ' + (auth.token || '') } })
    if (res.ok) {
      const blob = await res.blob()
      window.open(URL.createObjectURL(blob), '_blank')
    } else window.__krToast?.('Could not render invoice (HTTP ' + res.status + ')', 'error')
  } catch (e) { window.__krToast?.('Network error printing invoice', 'error') }
  finally { printBusy.value = '' }
}

// ── auto-generate modal (2026-08-12) ──
const autoModal = ref(null)     // { month, email }
const autoPreview = ref(null)   // dry-run result from app-invoice-auto
const autoBusy = ref(false)
const autoRunBusy = ref(false)
const thisMonth = () => new Date().toISOString().slice(0, 7)
function openAuto() {
  autoModal.value = { month: thisMonth(), email: true }
  autoPreview.value = null
  refreshAutoPreview()
}
function closeAuto() { autoModal.value = null; autoPreview.value = null }
async function refreshAutoPreview() {
  const m = autoModal.value
  if (!m) return
  autoBusy.value = true
  try {
    const r = await apiCall('app-invoice-auto', { month: m.month })
    if (r.ok) autoPreview.value = r
    else window.__krToast?.(r.error || 'Preview failed', 'error')
  } finally { autoBusy.value = false }
}
async function runAuto() {
  const m = autoModal.value
  if (!m) return
  autoRunBusy.value = true
  try {
    const r = await apiCall('app-invoice-auto', { month: m.month, commit: 1, send: m.email ? 1 : 0 })
    if (r.ok) {
      const parts = [`⚡ Created ${r.created} invoice(s) for ${monthLabel(r.month)}`]
      if (r.skipped) parts.push(`${r.skipped} already billed`)
      if (r.queued) parts.push(`📧 ${r.queued} emailed`)
      if (r.no_email) parts.push(`${r.no_email} no email`)
      if (r.suppressed_docs) parts.push(`${r.suppressed_docs} docs off`)
      if (r.suppressed_optout) parts.push(`${r.suppressed_optout} opted out`)
      window.__krToast?.(parts.join(' · '), r.created ? 'ok' : 'info')
      closeAuto()
      await data.bootstrap()
    } else window.__krToast?.(r.error || 'Generate failed', 'error')
  } finally { autoRunBusy.value = false }
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🧾 Invoices</h1>
        <div class="sub">{{ invoicesAll.length }} invoices · {{ money(kpis[2]?.value || 0) }} collected · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search invoice, tenant, unit…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
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
          <option value="m">Sort: Month</option>
          <option value="net">Sort: Net</option>
          <option value="due">Sort: Due</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
        <button v-if="canManage" @click="openAuto" class="btn-primary" style="padding:9px 16px;font-size:12.5px" title="Generate rent invoices for a month from active leases">⚡ Auto-generate</button>
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
      <div v-for="i in paged" :key="i.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(i)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">🧾</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="badge(invStatusRow(i))">{{ invStatusRow(i) }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ i.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div>
            <div style="font-weight:800;font-size:15px;letter-spacing:-.2px">{{ monthLabel(i.m) }}</div>
            <div class="c-sub" style="margin-top:2px">👤 {{ tenantOf(i) }} · 🚪 {{ unitOf(i) }} · {{ propName(propOf(i)) }}</div>
          </div>
          <div style="display:flex;gap:13px;font-size:12px;flex-wrap:wrap">
            <span class="c-sub" title="Gross">📊 {{ money(i.gross) }}<template v-if="i.tds"> − TDS {{ money(i.tds) }}</template></span>
            <span class="c-sub" title="Net">🧾 {{ money(i.net) }}</span>
            <span class="c-sub" :style="invDue(i) > 0 ? 'color:var(--danger);font-weight:800' : ''" title="Due">⏳ {{ money(invDue(i)) }}</span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
            <span class="badge b-green">💳 {{ money(invPaid(i)) }}</span>
            <span class="badge b-gray">📄 {{ i.l }}</span>
          </div>
          <div v-if="canManage && invDue(i) > 0" style="display:flex;gap:6px;border-top:1px solid var(--border);padding-top:9px">
            <button class="btn-ghost" style="flex:1;justify-content:center;padding:6px 10px;font-size:12px" @click.stop="openPay(i)">💳 Record payment</button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>Invoice</th><th>Month</th><th>Tenant</th><th>Lease / Unit</th><th>Gross</th><th>TDS</th><th>Net</th><th>Paid</th><th>Due</th><th>Status</th><th v-if="canManage && filtered.some(i => invDue(i) > 0)">Action</th></tr></thead>
          <tbody>
            <tr v-for="i in paged" :key="i.id" style="cursor:pointer" @click="openDetail(i)">
              <td style="white-space:nowrap"><b>{{ i.id }}</b></td>
              <td style="white-space:nowrap">{{ monthLabel(i.m) }}</td>
              <td style="white-space:nowrap"><a @click.stop="go('/tenants', { open: leaseOf(i)?.t })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ tenantOf(i) }}</a></td>
              <td style="white-space:nowrap" class="c-sub"><a @click.stop="go('/leases', { open: i.l })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ i.l }}</a> · {{ unitOf(i) }}</td>
              <td style="white-space:nowrap">{{ money(i.gross) }}</td>
              <td style="white-space:nowrap">{{ money(i.tds) }}</td>
              <td style="font-weight:700;white-space:nowrap">{{ money(i.net) }}</td>
              <td style="white-space:nowrap;color:var(--ok)">{{ money(invPaid(i)) }}</td>
              <td :style="invDue(i) > 0 ? 'color:var(--danger);font-weight:800;white-space:nowrap' : 'white-space:nowrap'">{{ money(invDue(i)) }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="badge(invStatusRow(i))">{{ invStatusRow(i) }}</span></td>
              <td v-if="canManage && filtered.some(i => invDue(i) > 0)" style="white-space:nowrap">
                <button v-if="invDue(i) > 0" class="btn-ghost" style="padding:4px 9px;font-size:11px" @click.stop="openPay(i)">💳 Pay</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No invoices found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(620px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">🧾</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="badge(invStatusRow(sel))">{{ invStatusRow(sel) }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.l }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.id }} · {{ monthLabel(sel.m) }}</h2>
          <div class="c-sub" style="margin-top:3px">👤 <a @click.stop="go('/tenants', { open: leaseOf(sel)?.t })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ tenantOf(sel) }}</a> · 🚪 <a @click.stop="go('/units', { open: leaseOf(sel)?.u })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ unitOf(sel) }}</a> · 🏢 {{ propName(propOf(sel)) }}</div>

          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Gross</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ money(sel.gross) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">TDS</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ money(sel.tds) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Net</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ money(sel.net) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Paid</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px;color:var(--ok)">{{ money(invPaid(sel)) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Due</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px" :style="invDue(sel) > 0 ? 'color:var(--danger)' : ''">{{ money(invDue(sel)) }}</div>
            </div>
          </div>

          <div v-if="canManage" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
            <button v-if="invDue(sel) > 0" class="btn-primary" style="padding:8px 15px;font-size:12.5px" @click="openPay(sel)">💳 Record payment</button>
            <button class="btn-ghost" style="padding:8px 15px;font-size:12.5px" :disabled="emailBusy === sel.id" @click="emailInv(sel)">{{ emailBusy === sel.id ? 'Emailing…' : '📧 Email invoice' }}</button>
            <button class="btn-ghost" style="padding:8px 15px;font-size:12.5px" :disabled="printBusy === sel.id" @click="printInv(sel)">{{ printBusy === sel.id ? 'Rendering…' : '🖨️ Print / PDF' }}</button>
          </div>

          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">👤 Tenant</div>
            <div style="font-weight:800;font-size:14px;cursor:pointer" @click="go('/tenants', { open: leaseOf(sel)?.t })">{{ tenantOf(sel) }} ↗</div>
            <div class="c-sub" style="font-size:11.5px;margin-top:3px">{{ selTenantObj?.phone || '—' }} · {{ selTenantObj?.kind || '—' }}<template v-if="selTenantObj?.nrb"> · NRB</template></div>
            <div class="c-sub" style="font-size:11.5px;margin-top:2px">Lease <a @click.stop="go('/leases', { open: sel.l })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ sel.l }}</a> · 🚪 <a @click.stop="go('/units', { open: leaseOf(sel)?.u })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ unitOf(sel) }}</a> · 🏢 {{ propName(propOf(sel)) }}</div>
          </div>

          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">💳 Payments</div>
            <div class="drawer-tbl-wrap">
              <table class="kr" style="width:100%">
              <thead><tr><th>ID</th><th>Date</th><th>Method</th><th>Ref</th><th>Amount</th><th>Status</th></tr></thead>
              <tbody>
                <tr v-for="p in selPays" :key="p.id">
                  <td style="font-weight:700">{{ p.id }}</td>
                  <td>{{ p.date }}</td>
                  <td>{{ p.method }}</td>
                  <td>{{ p.ref || '—' }}</td>
                  <td>{{ money(p.amount) }}</td>
                  <td><span class="badge" :class="badge(p.status)">{{ p.status }}</span></td>
                </tr>
                <tr v-if="!selPays.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:16px">No payments recorded for this invoice.</td></tr>
              </tbody>
            </table>
            </div>
          </div>

          <div v-if="selRcps.length" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">📎 Receipts</div>
            <div v-for="r in selRcps" :key="r.id" style="display:flex;justify-content:space-between;font-size:12.5px;padding:3px 0">
              <span>{{ r.id }} · {{ r.date }} · {{ r.method }}<template v-if="r.sig"> · {{ r.sig }}</template></span>
              <b>{{ money(r.amount) }}</b>
            </div>
          </div>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>

    <!-- payment modal -->
    <template v-if="payModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="payModal = null"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(400px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">💳 Record payment — {{ payModal.inv.id }}</h3>
          <button @click="payModal = null" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Amount (৳) *</label>
            <input v-model.number="payModal.amount" type="number" min="0" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:14px;font-weight:800;color:var(--text);outline:none">
            <div class="c-sub" style="font-size:11px;margin-top:4px">Remaining due: ৳{{ invDue(payModal.inv).toLocaleString('en-IN') }}</div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Date</label>
              <input v-model="payModal.date" type="date" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            </div>
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Method</label>
              <select v-model="payModal.method" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
                <option v-for="m in PAY_METHODS" :key="m" :value="m">{{ m }}</option>
              </select>
            </div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Reference / signature</label>
            <input v-model="payModal.sig" placeholder="e.g. BK-7f2a, cheque no…" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="payModal = null">Cancel</button>
          <button class="btn-primary" :disabled="paySaving" @click="submitPay" style="padding:9px 18px">{{ paySaving ? 'Recording…' : '💳 Record payment' }}</button>
        </div>
      </div>
    </template>

    <!-- auto-generate modal -->
    <template v-if="autoModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="closeAuto"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(460px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">⚡ Auto-generate invoices</h3>
          <button @click="closeAuto" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Billing month</label>
            <input v-model="autoModal.month" type="month" @change="refreshAutoPreview" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <div class="c-sub" style="font-size:11px;margin-top:4px">One Unpaid invoice per Active lease for this month — leases already billed are skipped.</div>
          </div>
          <label style="display:flex;align-items:center;gap:9px;font-size:13px;cursor:pointer">
            <input v-model="autoModal.email" type="checkbox" style="width:16px;height:16px;accent-color:var(--primary)">
            <span><b>Email tenants</b> <span class="c-sub">— queue invoice email (respects docs switch + opt-outs)</span></span>
          </label>
          <div v-if="autoPreview" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:12px 14px;font-size:12.5px;line-height:1.7">
            <div v-if="autoBusy" class="c-sub">Refreshing…</div>
            <template v-else>
              <div><b>{{ monthLabel(autoPreview.month) }}</b> — <b>{{ autoPreview.created }}</b> new · <b>{{ autoPreview.skipped }}</b> already billed · {{ autoPreview.not_in_month }} outside lease term · total rent <b>৳{{ (autoPreview.total_rent || 0).toLocaleString('en-IN') }}</b></div>
              <div v-if="autoPreview.invoices?.length" style="max-height:150px;overflow-y:auto;margin-top:8px;border-top:1px solid var(--border);padding-top:6px">
                <div v-for="inv in autoPreview.invoices" :key="inv.lease" style="display:flex;justify-content:space-between;gap:10px;padding:2px 0;border-bottom:1px dashed var(--border);font-size:12px">
                  <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ inv.tenant }}</span>
                  <span class="c-sub" style="white-space:nowrap">{{ inv.lease }}</span>
                  <b style="white-space:nowrap">{{ money(inv.rent) }}</b>
                </div>
              </div>
              <div v-else-if="!autoPreview.created" class="c-sub" style="margin-top:4px">Nothing new for this month — all active leases already billed 🎉</div>
            </template>
          </div>
          <div v-else class="c-sub" style="font-size:12px">{{ autoBusy ? 'Loading preview…' : 'Enter a month to preview.' }}</div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="refreshAutoPreview" :disabled="autoBusy" style="font-size:12px">↻ Preview</button>
          <button class="btn-ghost" @click="closeAuto">Cancel</button>
          <button class="btn-primary" :disabled="autoRunBusy || !autoPreview?.created" @click="runAuto" style="padding:9px 18px">{{ autoRunBusy ? 'Generating…' : (autoModal.email ? '⚡ Generate + email' : '⚡ Generate only') }}</button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* White badges only on cover/header areas (same convention as Properties/Units) */
.d-cover .badge { background: #ffffff; }
</style>
