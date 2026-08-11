<script setup>
import { computed, ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { badge, money, fmtTs, monthLabel } from '../lib/ui'

const router = useRouter()
const go = (path, q) => router.push({ path, query: q })

const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))

// ── live API data (app-vendors) ──
const loading = ref(false)
const err = ref('')
const partners = ref([])
const jobs = ref([])
const invoices = ref([])
const payouts = ref([])
const market = ref([])

async function load() {
  loading.value = true; err.value = ''
  try {
    const [a, b, c, d, e] = await Promise.all([
      apiCall('app-vendors', { action: 'list' }),
      apiCall('app-vendors', { action: 'jobs' }),
      apiCall('app-vendors', { action: 'invoice-list' }),
      apiCall('app-vendors', { action: 'payout-list' }),
      apiCall('app-vendors', { action: 'market' }),
    ])
    if (a.ok) partners.value = a.partners || []
    if (b.ok) jobs.value = b.jobs || []
    if (c.ok) invoices.value = c.invoices || []
    if (d.ok) payouts.value = d.payouts || []
    if (e.ok) market.value = e.pros || []
  } finally { loading.value = false }
}
onMounted(load)

// ── tabs ──
const tab = ref('partners')
const tabs = [
  { id: 'partners', l: '🛠️ Partners' },
  { id: 'jobs', l: '🔧 Jobs' },
  { id: 'invoices', l: '🧾 Invoices' },
  { id: 'payouts', l: '💵 Payouts' },
]

// ── KPIs ──
const kpis = computed(() => {
  const ps = partners.value
  const active = ps.filter(p => String(p.status || '').toLowerCase() === 'active')
  const totJobs = ps.reduce((s, p) => s + (p.jobs || 0), 0)
  const avgRate = ps.length ? (ps.reduce((s, p) => s + (p.rating || 0), 0) / ps.length).toFixed(1) : '—'
  const totPaid = payouts.value.reduce((s, x) => s + (x.amount || 0), 0)
  const openJobs = jobs.value.filter(j => !['Closed', 'Resolved'].includes(j.status || '')).length
  const pendingInvoices = invoices.value.filter(i => i.status === 'Submitted').length
  return [
    { label: 'Partners', ico: '🛠️', value: ps.length, trend: active.length + ' active' },
    { label: 'Open jobs', ico: '🔧', value: openJobs, trend: 'need action', ok: openJobs === 0 },
    { label: 'Pending invoices', ico: '🧾', value: pendingInvoices, trend: 'awaiting decision' },
    { label: 'Avg rating', ico: '⭐', value: avgRate, trend: 'of 5.0' },
    { label: 'Total jobs', ico: '📈', value: totJobs, trend: 'all-time' },
    { label: 'Payouts', ico: '💰', value: money(totPaid), trend: 'paid to vendors' },
  ]
})

// ── filters ──
const q = ref('')
const tradeFilter = ref('')
const statusFilter = ref('')
const tradeOptions = computed(() => [...new Set(partners.value.map(p => p.trade).filter(Boolean))].sort())
const statusOptions = computed(() => [...new Set(partners.value.map(p => p.status).filter(Boolean))].sort())
const filteredPartners = computed(() => {
  let out = partners.value
  const s = q.value.trim().toLowerCase()
  if (s) out = out.filter(p => p.id.toLowerCase().includes(s) || p.name.toLowerCase().includes(s) || (p.trade || '').toLowerCase().includes(s))
  if (tradeFilter.value) out = out.filter(p => p.trade === tradeFilter.value)
  if (statusFilter.value) out = out.filter(p => p.status === statusFilter.value)
  return [...out].sort((a, b) => (b.jobs || 0) - (a.jobs || 0))
})

const jq = ref('')
const jStatus = ref('')
const jStatusOptions = computed(() => [...new Set(jobs.value.map(j => j.status).filter(Boolean))].sort())
const filteredJobs = computed(() => {
  let out = jobs.value
  const s = jq.value.trim().toLowerCase()
  if (s) out = out.filter(j => JSON.stringify(j).toLowerCase().includes(s))
  if (jStatus.value) out = out.filter(j => j.status === jStatus.value)
  return [...out].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || '')))
})

const iq = ref('')
const iStatus = ref('')
const iStatusOptions = computed(() => [...new Set(invoices.value.map(i => i.status).filter(Boolean))].sort())
const filteredInvoices = computed(() => {
  let out = invoices.value
  const s = iq.value.trim().toLowerCase()
  if (s) out = out.filter(i => JSON.stringify(i).toLowerCase().includes(s))
  if (iStatus.value) out = out.filter(i => i.status === iStatus.value)
  return [...out].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || '')))
})

const pq = ref('')
const filteredPayouts = computed(() => {
  let out = payouts.value
  const s = pq.value.trim().toLowerCase()
  if (s) out = out.filter(x => JSON.stringify(x).toLowerCase().includes(s))
  return [...out].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || '')))
})

// ── job status flow: Open → Assigned → In Progress → Resolved (→ QC → Closed) ──
async function jobStatus(id, status) {
  const r = await apiCall('app-vendors', { action: 'job-status', id, status })
  if (!r.ok) { alert(r.error || 'Update failed'); return }
  await load()
}
async function jobQc(id) {
  if (!confirm(`Run QC on job ${id} and close it?`)) return
  const r = await apiCall('app-vendors', { action: 'job-qc', id })
  if (!r.ok) { alert(r.error || 'QC failed'); return }
  await load()
}

// ── invoices: submit / decide / pay ──
const showInvoiceForm = ref(false)
const invoiceForm = ref({ job: '', amount: '', desc: '' })
const jobOptions = computed(() => jobs.value.filter(j => j.vendor).map(j => ({ id: j.id, title: j.title || j.id })))
function openInvoiceAdd() { invoiceForm.value = { job: jobOptions.value[0]?.id || '', amount: '', desc: '' }; showInvoiceForm.value = true }
async function submitInvoice() {
  const f = invoiceForm.value
  if (!f.job || !f.amount) { alert('Job and amount are required.'); return }
  const job = jobs.value.find(j => j.id === f.job)
  const vendorName = (job && (job.vendor || job.assigned_to)) || ''
  const partner = partners.value.find(p => p.name === vendorName)?.id || partners.value[0]?.id || ''
  const r = await apiCall('app-vendors', { action: 'invoice-submit', job: f.job, amount: parseInt(f.amount, 10) || 0, desc: f.desc.trim(), partner })
  if (!r.ok) { alert(r.error || 'Submit failed'); return }
  showInvoiceForm.value = false
  await load()
}
async function invoiceDecide(id, verdict) {
  const r = await apiCall('app-vendors', { action: 'invoice-decide', id, verdict })
  if (!r.ok) { alert(r.error || 'Review failed'); return }
  await load()
}
async function invoicePay(id) {
  if (!confirm(`Mark invoice ${id} as paid?`)) return
  const r = await apiCall('app-vendors', { action: 'invoice-pay', id })
  if (!r.ok) { alert(r.error || 'Payment failed'); return }
  await load()
}

// ── payouts ──
const showPayoutForm = ref(false)
const payoutForm = ref({ partner: '', month: '', amount: '', status: 'Paid', method: 'Bank', ref: '' })
function openPayoutAdd() { payoutForm.value = { partner: partners.value[0]?.id || '', month: new Date().toISOString().slice(0, 7), amount: '', status: 'Paid', method: 'Bank', ref: '' }; showPayoutForm.value = true }
async function recordPayout() {
  const f = payoutForm.value
  if (!f.partner || !f.amount) { alert('Partner and amount are required.'); return }
  const r = await apiCall('app-vendors', { action: 'payout-record', partner: f.partner, month: f.month, amount: parseInt(f.amount, 10) || 0, status: f.status, method: f.method, ref: f.ref.trim() })
  if (!r.ok) { alert(r.error || 'Record failed'); return }
  showPayoutForm.value = false
  await load()
}

const partnerName = (pid) => partners.value.find(p => p.id === pid)?.name || pid || '—'
const initials = (n) => String(n || '?').split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase()
const avatarColor = (s) => { let h = 0; for (const c of String(s || '')) h = (h * 31 + c.charCodeAt(0)) % 360; return `hsl(${h},62%,45%)` }
const payoutsOf = (p) => payouts.value.filter(x => x.partner === p.id)
function exportCsv(kind) {
  const rows = kind === 'partners' ? filteredPartners.value : kind === 'jobs' ? filteredJobs.value : kind === 'invoices' ? filteredInvoices.value : filteredPayouts.value
  if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = kind + '.csv'; a.click(); URL.revokeObjectURL(a.href)
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🛠️ Vendors</h1>
        <div class="sub">{{ partners.length }} partners · {{ jobs.length }} jobs · {{ invoices.length }} invoices · {{ payouts.length }} payouts · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <template v-if="tab === 'partners'">
          <input v-model="q" placeholder="Search partner, trade…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:210px">
          <select v-model="tradeFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All trades</option>
            <option v-for="t in tradeOptions" :key="t" :value="t">{{ t }}</option>
          </select>
          <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All statuses</option>
            <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
          </select>
          <button v-if="filteredPartners.length" @click="exportCsv('partners')" class="btn-ghost">⬇ CSV</button>
        </template>
        <template v-else-if="tab === 'jobs'">
          <input v-model="jq" placeholder="Search jobs…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:190px">
          <select v-model="jStatus" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All statuses</option>
            <option v-for="s in jStatusOptions" :key="s" :value="s">{{ s }}</option>
          </select>
          <button v-if="filteredJobs.length" @click="exportCsv('jobs')" class="btn-ghost">⬇ CSV</button>
        </template>
        <template v-else-if="tab === 'invoices'">
          <input v-model="iq" placeholder="Search invoices…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:190px">
          <select v-model="iStatus" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All statuses</option>
            <option v-for="s in iStatusOptions" :key="s" :value="s">{{ s }}</option>
          </select>
          <button v-if="canManage" @click="openInvoiceAdd" class="btn-primary" style="display:inline-flex;align-items:center;gap:6px">🧾 Submit invoice</button>
          <button v-if="filteredInvoices.length" @click="exportCsv('invoices')" class="btn-ghost">⬇ CSV</button>
        </template>
        <template v-else>
          <input v-model="pq" placeholder="Search payouts…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:190px">
          <button v-if="canManage" @click="openPayoutAdd" class="btn-primary" style="display:inline-flex;align-items:center;gap:6px">💵 Record payout</button>
          <button v-if="filteredPayouts.length" @click="exportCsv('payouts')" class="btn-ghost">⬇ CSV</button>
        </template>
      </div>
    </div>

    <div v-if="err" class="panel" style="padding:18px;color:var(--danger)">⚠️ {{ err }}</div>
    <div v-if="loading && !partners.length" class="panel" style="padding:22px;text-align:center;color:var(--text-mute)">Loading…</div>

    <div class="stats">
      <div v-for="k in kpis" :key="k.label" class="stat">
        <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
        <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
        <div class="s-trend">{{ k.trend }}</div>
      </div>
    </div>

    <!-- tabs -->
    <div style="display:flex;gap:8px;margin:18px 0 14px;flex-wrap:wrap">
      <button v-for="t in tabs" :key="t.id" @click="tab = t.id"
        :style="tab === t.id ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'"
        style="padding:9px 16px;border:none;border-radius:10px;font-size:13px;font-weight:800;cursor:pointer">{{ t.l }}</button>
    </div>

    <!-- PARTNERS -->
    <template v-if="tab === 'partners'">
      <div v-if="filteredPartners.length" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px">
        <div v-for="p in filteredPartners" :key="p.id" class="panel chip" style="overflow:hidden;display:flex;flex-direction:column">
          <div style="height:84px;position:relative;background:var(--grad)">
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:800;color:#fff;text-shadow:0 2px 6px rgba(0,0,0,.35)">{{ initials(p.name) }}</div>
            <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
              <span class="badge" :class="badge(p.status)">{{ p.status }}</span>
            </div>
            <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ p.id }}</div>
          </div>
          <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
            <div>
              <div style="font-weight:800;font-size:15px;letter-spacing:-.2px">{{ p.name }}</div>
              <div class="c-sub" style="margin-top:2px">🔧 {{ p.trade || '—' }}</div>
            </div>
            <div style="display:flex;gap:13px;font-size:12px;flex-wrap:wrap">
              <span class="c-sub" title="Rating">⭐ {{ p.rating || 0 }}/5</span>
              <span class="c-sub" title="Jobs">🔧 {{ p.jobs || 0 }} jobs</span>
              <span v-if="p.open_jobs" class="c-sub" title="Open jobs" style="color:var(--danger)">⏳ {{ p.open_jobs }} open</span>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
              <span class="badge b-blue">💰 {{ money(p.paid_total || 0) }} paid</span>
              <span v-if="p.sub_email" class="badge b-green">📧 portal</span>
              <span v-if="p.avg_rating" class="badge b-orange">★ {{ p.avg_rating }}</span>
            </div>
          </div>
        </div>
      </div>
      <div v-if="!filteredPartners.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No partners found.</div>
    </template>

    <!-- JOBS -->
    <template v-if="tab === 'jobs'">
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap">
          <table class="kr" style="width:100%">
            <thead><tr><th>Job</th><th>Vendor</th><th>Unit</th><th>Category</th><th>Priority</th><th>Cost</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="j in filteredJobs" :key="j.id">
                <td style="white-space:nowrap"><b>{{ j.id }}</b><div class="c-sub" style="font-size:11.5px">{{ j.title || '—' }}</div></td>
                <td style="white-space:nowrap">{{ j.vendor || j.assigned_to || '—' }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ j.unit_name || j.unit || '—' }}<template v-if="j.property_name"> · {{ j.property_name }}</template></td>
                <td style="white-space:nowrap" class="c-sub">{{ j.category || '—' }}</td>
                <td style="white-space:nowrap"><span class="badge" :class="j.priority === 'urgent' ? 'b-red' : j.priority === 'high' ? 'b-orange' : 'b-gray'">{{ j.priority || '—' }}</span></td>
                <td style="white-space:nowrap" class="c-sub">{{ j.actual_cost ? money(j.actual_cost) : (j.cost_estimate ? 'est ' + money(j.cost_estimate) : '—') }}</td>
                <td style="white-space:nowrap"><span class="badge" :class="badge(j.status)">{{ j.status }}</span></td>
                <td style="white-space:nowrap">
                  <div style="display:flex;gap:6px;justify-content:flex-end;align-items:center">
                    <template v-if="canManage">
                      <button v-if="j.status === 'Assigned'" @click="jobStatus(j.id, 'In Progress')" class="btn-ghost" style="padding:4px 9px;font-size:11.5px">▶ Start</button>
                      <button v-else-if="j.status === 'In Progress'" @click="jobStatus(j.id, 'Resolved')" class="btn-ghost" style="padding:4px 9px;font-size:11.5px">✓ Resolve</button>
                      <button v-else-if="j.status === 'Resolved'" @click="jobQc(j.id)" class="btn-ghost" style="padding:4px 9px;font-size:11.5px">✅ QC & close</button>
                      <button v-else-if="j.status === 'Open'" @click="jobStatus(j.id, 'Assigned')" class="btn-ghost" style="padding:4px 9px;font-size:11.5px">🔧 Assign</button>
                    </template>
                    <span v-if="j.pay_release" class="c-sub" style="font-size:10.5px">🔓</span>
                    <span v-if="j.pay_paid" class="c-sub" style="font-size:10.5px">💵</span>
                    <span v-if="j.qc_by" class="c-sub" style="font-size:10.5px">QC: {{ j.qc_by }}</span>
                  </div>
                </td>
              </tr>
              <tr v-if="!filteredJobs.length"><td colspan="8" style="text-align:center;color:var(--text-mute);padding:22px">No jobs.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- INVOICES -->
    <template v-if="tab === 'invoices'">
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap">
          <table class="kr" style="width:100%">
            <thead><tr><th>Invoice</th><th>Partner</th><th>Job</th><th>Amount</th><th>Status</th><th>Decided</th><th></th></tr></thead>
            <tbody>
              <tr v-for="i in filteredInvoices" :key="i.id">
                <td style="white-space:nowrap"><b>{{ i.id }}</b></td>
                <td style="white-space:nowrap">{{ i.partner_name || partnerName(i.partner) }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ i.job || '—' }}<template v-if="i.job_title"> · {{ i.job_title }}</template></td>
                <td style="white-space:nowrap;font-weight:700">{{ money(i.amount) }}</td>
                <td style="white-space:nowrap"><span class="badge" :class="badge(i.status)">{{ i.status }}</span></td>
                <td style="white-space:nowrap" class="c-sub">{{ i.decided_by ? i.decided_by + (i.decided_at ? ' · ' + fmtTs(i.decided_at) : '') : '—' }}</td>
                <td style="white-space:nowrap">
                  <div style="display:flex;gap:6px;justify-content:flex-end">
                    <template v-if="canManage && i.status === 'Submitted'">
                      <button @click="invoiceDecide(i.id, 'approve')" class="btn-ghost" style="padding:4px 9px;font-size:11.5px;color:var(--ok)">✓ Approve</button>
                      <button @click="invoiceDecide(i.id, 'reject')" class="btn-ghost" style="padding:4px 9px;font-size:11.5px;color:var(--danger)">✕ Reject</button>
                    </template>
                    <button v-if="canManage && i.status === 'Approved'" @click="invoicePay(i.id)" class="btn-ghost" style="padding:4px 9px;font-size:11.5px">💵 Pay</button>
                  </div>
                </td>
              </tr>
              <tr v-if="!filteredInvoices.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">No invoices.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- PAYOUTS -->
    <template v-if="tab === 'payouts'">
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap">
          <table class="kr" style="width:100%">
            <thead><tr><th>ID</th><th>Partner</th><th>Month</th><th>Method</th><th>Ref</th><th>Amount</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="p in filteredPayouts" :key="p.id">
                <td style="white-space:nowrap;font-weight:700">{{ p.id }}</td>
                <td style="white-space:nowrap">{{ p.partner_name || partnerName(p.partner) }}</td>
                <td style="white-space:nowrap">{{ monthLabel(p.month) }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ p.method || '—' }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ p.ref || '—' }}</td>
                <td style="white-space:nowrap;font-weight:700">{{ money(p.amount) }}</td>
                <td style="white-space:nowrap"><span class="badge" :class="badge(p.status)">{{ p.status }}</span></td>
              </tr>
              <tr v-if="!filteredPayouts.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">No payouts recorded.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- submit invoice modal -->
    <template v-if="showInvoiceForm">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="showInvoiceForm = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(500px,94vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:90px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">🧾 Submit vendor invoice</div>
          <button @click="showInvoiceForm = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Job *</label>
            <select v-model="invoiceForm.job" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
              <option v-for="j in jobOptions" :key="j.id" :value="j.id">{{ j.id }} — {{ j.title }}</option>
            </select>
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Amount (৳) *</label>
            <input v-model="invoiceForm.amount" type="number" min="0" placeholder="0" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Description</label>
            <textarea v-model="invoiceForm.desc" rows="3" placeholder="Work done, materials…" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px;resize:vertical"></textarea>
          </div>
          <button @click="submitInvoice" class="btn-primary" style="margin-top:4px">🧾 Submit</button>
        </div>
      </div>
    </template>

    <!-- record payout modal -->
    <template v-if="showPayoutForm">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="showPayoutForm = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(500px,94vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:90px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">💵 Record vendor payout</div>
          <button @click="showPayoutForm = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Partner *</label>
            <select v-model="payoutForm.partner" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
              <option v-for="p in partners" :key="p.id" :value="p.id">{{ p.id }} — {{ p.name }}</option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Month</label>
              <input v-model="payoutForm.month" type="month" style="width:100%;padding:8px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Amount (৳) *</label>
              <input v-model="payoutForm.amount" type="number" min="0" placeholder="0" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Status</label>
              <select v-model="payoutForm.status" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
                <option value="Paid">Paid</option>
                <option value="Scheduled">Scheduled</option>
                <option value="Pending">Pending</option>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Method</label>
              <select v-model="payoutForm.method" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
                <option value="Bank">Bank</option>
                <option value="bKash">bKash</option>
                <option value="Nagad">Nagad</option>
                <option value="Cash">Cash</option>
              </select>
            </div>
          </div>
          <div>
            <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Ref</label>
            <input v-model="payoutForm.ref" placeholder="e.g. NPSB-44002" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
          </div>
          <button @click="recordPayout" class="btn-primary" style="margin-top:4px">💵 Record</button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.d-cover .badge { background: #ffffff; }
</style>
