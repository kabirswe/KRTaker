<script setup>
import { computed, ref, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import { apiCall } from '../api/client'
import { badge, money, fmtTs, monthLabel } from '../lib/ui'

const router = useRouter()
const route = useRoute()
const go = (path, q) => router.push({ path, query: q })

const auth = useAuthStore()
const data = useDataStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'svc_mgr'].includes(auth.user?.role || ''))
const isOwner = computed(() => ['superadmin', 'owner'].includes(auth.user?.role || ''))
const isPartner = computed(() => (auth.user?.role || '') === 'partner')
const meName = computed(() => auth.user?.name || '')
const meEmail = computed(() => auth.user?.email || '')

// ── live API data (app-vendors) ──
const loading = ref(false)
const err = ref('')
const partners = ref([])
const jobs = ref([])
const invoices = ref([])
const payouts = ref([])
const market = ref([])
const svcJobs = ref([])
const cats = ref([])
const toast = ref('')

const notify = (m) => { toast.value = m; setTimeout(() => toast.value = '', 4000) }

async function load() {
  loading.value = true; err.value = ''
  try {
    const [a, b, c, d, e, f] = await Promise.all([
      apiCall('app-vendors', { action: 'list' }),
      apiCall('app-vendors', { action: 'jobs' }),
      apiCall('app-vendors', { action: 'invoice-list' }),
      apiCall('app-vendors', { action: 'payout-list' }),
      apiCall('app-vendors', { action: 'market' }),
      apiCall('app-vendors', { action: 'rfq-list' }),
    ])
    if (a.ok) partners.value = a.partners || []
    if (b.ok) jobs.value = b.jobs || []
    if (c.ok) invoices.value = c.invoices || []
    if (d.ok) payouts.value = d.payouts || []
    if (e.ok) market.value = e.pros || []
    if (f.ok) { svcJobs.value = f.jobs || []; if (f.cats) cats.value = f.cats }
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}
onMounted(load)

// ── tabs ──
const tab = ref(route.query.tab || 'partners')
const tabs = [
  { id: 'partners', l: '🛠️ Partners' },
  { id: 'board', l: '📋 Service Board' },
  { id: 'jobs', l: '🔧 Jobs' },
  { id: 'invoices', l: '🧾 Invoices' },
  { id: 'payouts', l: '💵 Payouts' },
]
function setTab(t) { tab.value = t; router.replace({ query: { ...route.query, tab: t } }) }

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

// ── partners filters (category chips!) ──
const q = ref('')
const tradeFilter = ref('')
const statusFilter = ref('')
const catFilter = ref('')
watch(() => route.query.q, (v) => { if (v) q.value = String(v) }, { immediate: true })
const tradeOptions = computed(() => [...new Set(partners.value.map(p => p.trade).filter(Boolean))].sort())
const statusOptions = computed(() => [...new Set(partners.value.map(p => p.status).filter(Boolean))].sort())
const catOptions = computed(() => cats.value.length ? cats.value : [...new Set(partners.value.map(p => p.cat).filter(Boolean))].sort())
const catCounts = computed(() => {
  const m = {}
  partners.value.forEach(p => { const c = p.cat || 'General / Other'; m[c] = (m[c] || 0) + 1 })
  return m
})
const filteredPartners = computed(() => {
  let out = partners.value
  const s = q.value.trim().toLowerCase()
  if (s) out = out.filter(p => p.id.toLowerCase().includes(s) || p.name.toLowerCase().includes(s) || (p.trade || '').toLowerCase().includes(s) || (p.cat || '').toLowerCase().includes(s))
  if (catFilter.value) out = out.filter(p => (p.cat || 'General / Other') === catFilter.value)
  if (tradeFilter.value) out = out.filter(p => p.trade === tradeFilter.value)
  if (statusFilter.value) out = out.filter(p => p.status === statusFilter.value)
  return [...out].sort((a, b) => (b.jobs || 0) - (a.jobs || 0))
})

// ── service board ──
const bq = ref('')
const bCat = ref('')
const STATUS_META = {
  pending: { l: '⏳ Pending', ico: '🕐', color: '#E67E22', hint: 'Awaiting owner approval' },
  open: { l: '📢 Open RFQ', ico: '📢', color: '#2F80ED', hint: 'Partners can quote' },
  offers: { l: '💬 Offers', ico: '💬', color: '#8E44AD', hint: 'Quotes received' },
  awarded: { l: '📋 Work Order', ico: '📋', color: '#16A085', hint: 'Awarded — start work' },
  in_progress: { l: '🔧 In Progress', ico: '🔧', color: '#D35400', hint: 'Work underway' },
  resolved: { l: '✅ Resolved', ico: '✅', color: '#27AE60', hint: 'Ready to close' },
  closed: { l: '🏁 Closed', ico: '🏁', color: '#7F8C8D', hint: 'Done' },
  cancelled: { l: '🚫 Cancelled', ico: '🚫', color: '#C0392B', hint: 'Cancelled' },
}
const BOARD_COLS = ['pending', 'open', 'offers', 'awarded', 'in_progress', 'resolved']
const filteredSvc = computed(() => {
  let out = svcJobs.value
  const s = bq.value.trim().toLowerCase()
  if (s) out = out.filter(j => (j.title || '').toLowerCase().includes(s) || (j.id || '').toLowerCase().includes(s) || (j.requester || '').toLowerCase().includes(s) || (j.wo_no || '').toLowerCase().includes(s))
  if (bCat.value) out = out.filter(j => j.cat === bCat.value)
  return out
})
const colJobs = (st) => filteredSvc.value.filter(j => j.status === st)
const boardKpis = computed(() => {
  const all = svcJobs.value
  return [
    { l: 'Pending approval', v: all.filter(j => j.status === 'pending').length, c: '#E67E22' },
    { l: 'Open RFQ', v: all.filter(j => j.status === 'open').length, c: '#2F80ED' },
    { l: 'Offers in', v: all.filter(j => j.status === 'offers').length, c: '#8E44AD' },
    { l: 'Active work orders', v: all.filter(j => ['awarded', 'in_progress'].includes(j.status)).length, c: '#16A085' },
    { l: 'Budget exposure', v: money(all.filter(j => j.budget_amount > 0).reduce((s, j) => s + j.budget_amount, 0)), c: '#1A2433' },
    { l: 'Completed', v: all.filter(j => ['resolved', 'closed'].includes(j.status)).length, c: '#27AE60' },
  ]
})
const budgetLabel = (j) => {
  if (j.budget_type === 'fixed') return '🎯 Fixed ' + money(j.budget_amount)
  if (j.budget_type === 'tentative') return '~ Tentative ' + money(j.budget_amount)
  return '💬 Name your price'
}

// ── job drawer ──
const selJob = ref(null)     // full job from rfq-get
const selOffers = ref([])
const selMt = ref(null)
const drawerOpen = ref(false)
const drawerLoading = ref(false)
async function openJob(j) {
  drawerLoading.value = true; err.value = ''
  try {
    const r = await apiCall('app-vendors', { action: 'rfq-get', id: j.id })
    if (!r.ok) { err.value = r.error || 'Failed to load.'; return }
    selJob.value = r.job; selOffers.value = r.offers || []; selMt.value = r.mt || null
    offerForm.value = { kind: 'accept', amount: r.job.budget_amount > 0 ? r.job.budget_amount : '', note: '' }
    drawerOpen.value = true
  } catch (e) { err.value = e.message }
  finally { drawerLoading.value = false }
}
const myOffer = computed(() => {
  if (!isPartner.value || !selOffers.value.length) return null
  return selOffers.value.find(o => o.partner_name === meName.value) || null
})
const pendingOffers = computed(() => selOffers.value.filter(o => o.status === 'pending'))
const bestOffer = computed(() => {
  const ps = pendingOffers.value.filter(o => o.amount > 0)
  return ps.length ? Math.min(...ps.map(o => o.amount)) : 0
})

// offer form (partner)
const offerForm = ref({ kind: 'accept', amount: '', note: '' })
async function submitOffer() {
  if (!selJob.value) return
  const f = offerForm.value
  const amt = parseInt(f.amount, 10) || 0
  if (f.kind === 'counter' && amt <= 0) { notify('⚠️ Enter your counter amount'); return }
  const r = await apiCall('app-vendors', { action: 'offer-create', job: selJob.value.id, kind: f.kind, amount: amt || undefined, note: f.note.trim() })
  if (!r.ok) { notify('⚠️ ' + (r.error || 'Offer failed')); return }
  notify(`✅ Offer ${r.id} submitted (${money(r.amount)})`)
  drawerOpen.value = false
  await load()
}
async function withdrawOffer(o) {
  if (!confirm(`Withdraw offer ${o.id}?`)) return
  const r = await apiCall('app-vendors', { action: 'offer-withdraw', id: o.id })
  if (!r.ok) { notify('⚠️ ' + (r.error || 'Failed')); return }
  notify('↩ Offer withdrawn')
  await refreshDrawer()
}
async function decideOffer(o, verdict) {
  if (!confirm(`Accept offer ${o.id} from ${o.partner_name} and issue a work order?`)) return
  const r = await apiCall('app-vendors', { action: 'offer-decide', id: o.id, verdict })
  if (!r.ok) { notify('⚠️ ' + (r.error || 'Failed')); return }
  notify(verdict === 'accept' ? `✅ Work order ${r.wo_no} issued — ${money(r.amount)}` : '✕ Offer rejected')
  drawerOpen.value = false
  await load()
}
async function refreshDrawer() {
  if (!selJob.value) return
  const r = await apiCall('app-vendors', { action: 'rfq-get', id: selJob.value.id })
  if (r.ok) { selJob.value = r.job; selOffers.value = r.offers || []; selMt.value = r.mt || null }
}
async function rfqAction(action, label) {
  if (!selJob.value) return
  if (!confirm(`${label} ${selJob.value.id}?`)) return
  const r = await apiCall('app-vendors', { action, id: selJob.value.id })
  if (!r.ok) { notify('⚠️ ' + (r.error || 'Failed')); return }
  notify(`✅ ${selJob.value.id} ${label}`)
  drawerOpen.value = false
  await load()
}
async function rfqStatus(status, label) {
  if (!selJob.value) return
  if (!confirm(`${label} ${selJob.value.id}?`)) return
  const r = await apiCall('app-vendors', { action: 'rfq-status', id: selJob.value.id, status })
  if (!r.ok) { notify('⚠️ ' + (r.error || 'Failed')); return }
  notify(`✅ ${selJob.value.id} → ${label}`)
  drawerOpen.value = false
  await load()
}

// ── new service task modal ──
const newOpen = ref(false)
const newForm = ref({})
const unitsList = computed(() => data.list('units').map(u => ({ id: u.id, name: u.name + (u.floor ? ' · Floor ' + u.floor : '') })))
const propsList = computed(() => data.list('properties').map(p => ({ id: p.id, name: p.name })))
const mtCandidates = computed(() => jobs.value.filter(j => j.status === 'Open' && !j.vendor).map(j => ({ id: j.id, title: j.title })))
function openNew() {
  newForm.value = { title: '', cat: cats.value[0] || 'General / Other', desc: '', unit: '', prop: '', budget_type: 'tentative', budget_amount: '', deadline: '', notes: '', from_mt: '' }
  newOpen.value = true
}
async function createJob() {
  const f = newForm.value
  if (!f.title.trim()) { notify('⚠️ Title required'); return }
  const amt = parseInt(f.budget_amount, 10) || 0
  const r = await apiCall('app-vendors', {
    action: 'rfq-create', title: f.title.trim(), cat: f.cat, desc: f.desc.trim(),
    unit: f.unit, prop: f.prop, budget_type: f.budget_type, budget_amount: amt || undefined,
    deadline: f.deadline, notes: f.notes.trim(), from_mt: f.from_mt || undefined,
  })
  if (!r.ok) { notify('⚠️ ' + (r.error || 'Create failed')); return }
  notify(`✅ ${r.id} created — ${r.status === 'open' ? 'posted for quotation' : 'pending owner approval'}`)
  newOpen.value = false
  await load()
}
async function postFromMt(mtId) {
  if (!confirm(`Post ${mtId} as an RFQ for partners to quote?`)) return
  const r = await apiCall('app-vendors', { action: 'rfq-from-mt', from_mt: mtId })
  if (!r.ok) { notify('⚠️ ' + (r.error || 'Failed')); return }
  notify(`✅ ${r.id} posted from ${mtId}`)
  await load()
}

// ── edit service task (owner/manager on pending/open) ──
const editOpen = ref(false)
const editForm = ref({})
function openEdit() {
  const j = selJob.value
  editForm.value = { id: j.id, title: j.title, cat: j.cat, desc: j.desc || '', unit: j.unit || '', prop: j.prop || '', budget_type: j.budget_type, budget_amount: j.budget_amount || '', deadline: j.deadline || '', notes: j.notes || '' }
  editOpen.value = true
}
async function saveEdit() {
  const f = editForm.value
  const amt = parseInt(f.budget_amount, 10) || 0
  const r = await apiCall('app-vendors', { action: 'rfq-edit', id: f.id, title: f.title, cat: f.cat, desc: f.desc, unit: f.unit, prop: f.prop, budget_type: f.budget_type, budget_amount: amt || undefined, deadline: f.deadline, notes: f.notes })
  if (!r.ok) { notify('⚠️ ' + (r.error || 'Save failed')); return }
  notify('✅ Task updated')
  editOpen.value = false
  await refreshDrawer(); await load()
}

// ── existing jobs / invoices / payouts (unchanged logic) ──
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

async function jobStatus(id, status) {
  const r = await apiCall('app-vendors', { action: 'job-status', id, status })
  if (!r.ok) { notify('⚠️ ' + (r.error || 'Update failed')); return }
  await load()
}
async function jobQc(id) {
  if (!confirm(`Run QC on job ${id} and close it?`)) return
  const r = await apiCall('app-vendors', { action: 'job-qc', id })
  if (!r.ok) { notify('⚠️ ' + (r.error || 'QC failed')); return }
  await load()
}

const showInvoiceForm = ref(false)
const invoiceForm = ref({ job: '', amount: '', desc: '' })
const jobOptions = computed(() => jobs.value.filter(j => j.vendor).map(j => ({ id: j.id, title: j.title || j.id })))
function openInvoiceAdd() { invoiceForm.value = { job: jobOptions.value[0]?.id || '', amount: '', desc: '' }; showInvoiceForm.value = true }
async function submitInvoice() {
  const f = invoiceForm.value
  if (!f.job || !f.amount) { notify('⚠️ Job and amount are required.'); return }
  const job = jobs.value.find(j => j.id === f.job)
  const vendorName = (job && (job.vendor || job.assigned_to)) || ''
  const partner = partners.value.find(p => p.name === vendorName)?.id || partners.value[0]?.id || ''
  const r = await apiCall('app-vendors', { action: 'invoice-submit', job: f.job, amount: parseInt(f.amount, 10) || 0, desc: f.desc.trim(), partner })
  if (!r.ok) { notify('⚠️ ' + (r.error || 'Submit failed')); return }
  showInvoiceForm.value = false
  await load()
}
async function invoiceDecide(id, verdict) {
  const r = await apiCall('app-vendors', { action: 'invoice-decide', id, verdict })
  if (!r.ok) { notify('⚠️ ' + (r.error || 'Review failed')); return }
  await load()
}
async function invoicePay(id) {
  if (!confirm(`Mark invoice ${id} as paid?`)) return
  const r = await apiCall('app-vendors', { action: 'invoice-pay', id })
  if (!r.ok) { notify('⚠️ ' + (r.error || 'Payment failed')); return }
  await load()
}

const showPayoutForm = ref(false)
const payoutForm = ref({ partner: '', month: '', amount: '', status: 'Paid', method: 'Bank', ref: '' })
function openPayoutAdd() { payoutForm.value = { partner: partners.value[0]?.id || '', month: new Date().toISOString().slice(0, 7), amount: '', status: 'Paid', method: 'Bank', ref: '' }; showPayoutForm.value = true }
async function recordPayout() {
  const f = payoutForm.value
  if (!f.partner || !f.amount) { notify('⚠️ Partner and amount are required.'); return }
  const r = await apiCall('app-vendors', { action: 'payout-record', partner: f.partner, month: f.month, amount: parseInt(f.amount, 10) || 0, status: f.status, method: f.method, ref: f.ref.trim() })
  if (!r.ok) { notify('⚠️ ' + (r.error || 'Record failed')); return }
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
        <div class="sub">{{ partners.length }} partners · {{ svcJobs.length }} service tasks · {{ jobs.length }} maintenance jobs · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <template v-if="tab === 'partners'">
          <input v-model="q" placeholder="Search partner, trade, category…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
          <button v-if="filteredPartners.length" @click="exportCsv('partners')" class="btn-ghost">⬇ CSV</button>
        </template>
        <template v-else-if="tab === 'board'">
          <input v-model="bq" placeholder="Search tasks, requester, WO…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:210px">
          <select v-model="bCat" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">All categories</option>
            <option v-for="c in cats" :key="c" :value="c">{{ c }}</option>
          </select>
          <button v-if="canManage" @click="openNew" class="btn-primary" style="display:inline-flex;align-items:center;gap:6px">＋ New service task</button>
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

    <div v-if="toast" class="vend-toast">{{ toast }}</div>
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
      <button v-for="t in tabs" :key="t.id" @click="setTab(t.id)"
        :style="tab === t.id ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'"
        style="padding:9px 16px;border:none;border-radius:10px;font-size:13px;font-weight:800;cursor:pointer">{{ t.l }}</button>
    </div>

    <!-- ── PARTNERS (category chips) ── -->
    <template v-if="tab === 'partners'">
      <div class="chip-row">
        <button class="chip" :class="{ on: catFilter === '' }" @click="catFilter = ''">All <b>{{ partners.length }}</b></button>
        <button v-for="c in catOptions" :key="c" class="chip" :class="{ on: catFilter === c }" @click="catFilter = catFilter === c ? '' : c">{{ c }} <b>{{ catCounts[c] || 0 }}</b></button>
        <div style="flex:1"></div>
        <select v-model="tradeFilter" style="padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
          <option value="">All trades</option>
          <option v-for="t in tradeOptions" :key="t" :value="t">{{ t }}</option>
        </select>
        <select v-model="statusFilter" style="padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
      </div>
      <div v-if="filteredPartners.length" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px">
        <div v-for="p in filteredPartners" :key="p.id" class="panel chip" style="overflow:hidden;display:flex;flex-direction:column">
          <div style="height:84px;position:relative;background:var(--grad)">
            <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:800;color:#fff;text-shadow:0 2px 6px rgba(0,0,0,.35)">{{ initials(p.name) }}</div>
            <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
              <span class="badge" :class="badge(p.status)">{{ p.status }}</span>
              <span v-if="p.cat" class="badge b-blue">{{ p.cat }}</span>
            </div>
            <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ p.id }}</div>
          </div>
          <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
            <div>
              <div style="font-weight:800;font-size:15px;letter-spacing:-.2px">{{ p.name }}</div>
              <div class="c-sub" style="margin-top:2px">🔧 {{ p.trade || '—' }}<template v-if="p.phone"> · 📞 {{ p.phone }}</template></div>
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

    <!-- ── SERVICE BOARD ── -->
    <template v-if="tab === 'board'">
      <div class="board-kpis">
        <div v-for="k in boardKpis" :key="k.l" class="bkpi">
          <span class="bkpi-dot" :style="{ background: k.c }"></span>
          <span class="bkpi-v">{{ k.v }}</span>
          <span class="bkpi-l">{{ k.l }}</span>
        </div>
      </div>

      <div v-if="isOwner && mtCandidates.length" class="mt-poster">
        <span>🧰 Maintenance jobs waiting for a vendor — post them for quotation:</span>
        <button v-for="m in mtCandidates.slice(0, 4)" :key="m.id" class="btn-ghost" style="padding:5px 11px;font-size:11.5px" @click="postFromMt(m.id)">📢 {{ m.id }} · {{ m.title.slice(0, 26) }}</button>
      </div>

      <div class="board">
        <div v-for="col in BOARD_COLS" :key="col" class="bcol" :style="{ '--col': STATUS_META[col].color }">
          <div class="bcol-h">
            <span class="bcol-dot" :style="{ background: STATUS_META[col].color }"></span>
            <b>{{ STATUS_META[col].l }}</b>
            <span class="bcol-count">{{ colJobs(col).length }}</span>
          </div>
          <div class="bcol-sub">{{ STATUS_META[col].hint }}</div>
          <div class="bcol-body">
            <div v-for="j in colJobs(col)" :key="j.id" class="bjcard" @click="openJob(j)">
              <div class="bj-top">
                <span class="bj-id">{{ j.id }}</span>
                <span v-if="j.wo_no" class="bj-wo">📋 {{ j.wo_no }}</span>
                <span v-if="j.offer_count" class="bj-offers">💬 {{ j.offer_count }}</span>
              </div>
              <div class="bj-title">{{ j.title }}</div>
              <div class="bj-cat">{{ j.cat }}</div>
              <div class="bj-meta">
                <span class="bj-budget">{{ budgetLabel(j) }}</span>
                <span v-if="j.best_offer" class="bj-best">best {{ money(j.best_offer) }}</span>
              </div>
              <div class="bj-foot">
                <span class="bj-req">{{ j.requester || '—' }}<template v-if="j.requester_role"> · {{ j.requester_role }}</template></span>
                <span v-if="j.deadline" class="bj-dl">⏰ {{ j.deadline }}</span>
              </div>
              <div v-if="j.awarded_partner" class="bj-award">🏆 {{ j.awarded_partner }}<template v-if="j.awarded_amount"> · {{ money(j.awarded_amount) }}</template></div>
            </div>
            <div v-if="!colJobs(col).length" class="bcol-empty">{{ STATUS_META[col].hint }}</div>
          </div>
        </div>
      </div>
    </template>

    <!-- ── JOBS (maintenance) ── -->
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
                      <button v-if="j.status === 'Open' && isOwner" @click="postFromMt(j.id)" class="btn-ghost" style="padding:4px 9px;font-size:11.5px">📢 Post RFQ</button>
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

    <!-- ── INVOICES ── -->
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

    <!-- ── PAYOUTS ── -->
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

    <!-- ── SERVICE TASK DRAWER ── -->
    <template v-if="drawerOpen && selJob">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="drawerOpen = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(560px,96vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:100px;background:linear-gradient(135deg,#1A2433,#2F80ED);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px">
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
              <span style="font-size:18px;font-weight:800;color:#fff">{{ selJob.id }}</span>
              <span v-if="selJob.wo_no" class="badge" style="background:#16A085;color:#fff">{{ selJob.wo_no }}</span>
              <span class="badge" :style="{ background: STATUS_META[selJob.status].color, color: '#fff' }">{{ STATUS_META[selJob.status].l }}</span>
            </div>
            <div style="font-size:13px;color:rgba(255,255,255,.85);font-weight:600">{{ selJob.title }}</div>
          </div>
          <button @click="drawerOpen = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:14px">
          <!-- meta chips -->
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <span class="badge b-blue">{{ selJob.cat }}</span>
            <span class="badge b-gray">👤 {{ selJob.requester || '—' }} · {{ selJob.requester_role }}</span>
            <span v-if="selJob.deadline" class="badge b-orange">⏰ {{ selJob.deadline }}</span>
            <span v-if="selJob.created_at" class="badge b-gray">📅 {{ fmtTs(selJob.created_at) }}</span>
            <span v-if="selJob.mt_id" class="badge b-purple">🔗 {{ selJob.mt_id }}</span>
          </div>

          <!-- budget box -->
          <div class="budget-box" :class="selJob.budget_type">
            <div class="bb-label">Budget</div>
            <div class="bb-line">
              <span class="bb-type">{{ selJob.budget_type === 'fixed' ? '🎯 Fixed rate' : selJob.budget_type === 'tentative' ? '~ Tentative budget' : '💬 Open quote' }}</span>
              <b>{{ selJob.budget_amount ? money(selJob.budget_amount) : 'Partners name their price' }}</b>
            </div>
            <div v-if="bestOffer" class="bb-best">Best offer so far: <b>{{ money(bestOffer) }}</b> ({{ pendingOffers.length }} quote{{ pendingOffers.length === 1 ? '' : 's' }})</div>
          </div>

          <!-- description -->
          <div v-if="selJob.desc" class="drawer-sec">
            <div class="ds-h">📝 Description</div>
            <div style="font-size:13px;color:var(--text);line-height:1.65;white-space:pre-wrap">{{ selJob.desc }}</div>
          </div>
          <div v-if="selJob.notes" class="drawer-sec">
            <div class="ds-h">📌 Notes</div>
            <div style="font-size:12.5px;color:var(--text-mute);white-space:pre-wrap">{{ selJob.notes }}</div>
          </div>

          <!-- location -->
          <div v-if="selJob.unit_name || selJob.property_name" class="drawer-sec">
            <div class="ds-h">📍 Location</div>
            <div style="font-size:13px">{{ selJob.property_name || '—' }}<template v-if="selJob.unit_name"> · {{ selJob.unit_name }} ({{ selJob.unit }})</template></div>
          </div>

          <!-- awarded -->
          <div v-if="selJob.awarded_partner" class="award-box">
            <div class="ab-ico">🏆</div>
            <div>
              <div style="font-weight:800;font-size:14px">{{ selJob.awarded_partner }}</div>
              <div class="c-sub" style="font-size:12px">Awarded {{ selJob.wo_no }} · {{ money(selJob.awarded_amount) }}<template v-if="selJob.wo_at"> · {{ fmtTs(selJob.wo_at) }}</template></div>
            </div>
          </div>

          <!-- offers -->
          <div class="drawer-sec">
            <div class="ds-h">💬 Offers ({{ selOffers.length }})</div>
            <div v-if="!selOffers.length" class="c-sub" style="font-size:12.5px;padding:6px 0">No offers yet.</div>
            <div v-for="o in selOffers" :key="o.id" class="offer-row" :class="o.status">
              <div class="off-ava" :style="{ background: avatarColor(o.partner_name) }">{{ initials(o.partner_name) }}</div>
              <div style="flex:1;min-width:0">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                  <b style="font-size:13px">{{ o.partner_name }}</b>
                  <span class="badge" :class="o.kind === 'accept' ? 'b-green' : 'b-orange'">{{ o.kind === 'accept' ? '✓ Accepts budget' : '⇄ Counter offer' }}</span>
                  <span class="badge" :class="badge(o.status)">{{ o.status }}</span>
                </div>
                <div style="font-size:13px;font-weight:800;color:var(--primary);margin-top:2px">{{ o.amount ? money(o.amount) : 'Budget rate' }}</div>
                <div v-if="o.note" class="c-sub" style="font-size:12px;line-height:1.5">{{ o.note }}</div>
                <div class="c-sub" style="font-size:10.5px;margin-top:2px">{{ o.id }} · {{ fmtTs(o.created_at) }}</div>
              </div>
              <div style="display:flex;flex-direction:column;gap:5px;align-items:flex-end">
                <button v-if="isOwner && o.status === 'pending'" class="btn-ghost" style="padding:4px 10px;font-size:11.5px;color:var(--ok)" @click="decideOffer(o, 'accept')">✓ Award</button>
                <button v-if="isOwner && o.status === 'pending'" class="btn-ghost" style="padding:4px 10px;font-size:11.5px;color:var(--danger)" @click="decideOffer(o, 'reject')">✕</button>
                <button v-if="isPartner && o.partner_name === meName && o.status === 'pending'" class="btn-ghost" style="padding:4px 10px;font-size:11.5px" @click="withdrawOffer(o)">↩</button>
              </div>
            </div>
          </div>

          <!-- partner offer form -->
          <div v-if="isPartner && ['open', 'offers'].includes(selJob.status)" class="offer-form">
            <div class="ds-h">✍️ Make your offer</div>
            <div style="display:flex;gap:8px;margin:8px 0">
              <button class="chip" :class="{ on: offerForm.kind === 'accept' }" @click="offerForm.kind = 'accept'; offerForm.amount = selJob.budget_amount > 0 ? selJob.budget_amount : ''">✓ Accept{{ selJob.budget_amount ? ' ' + money(selJob.budget_amount) : '' }}</button>
              <button class="chip" :class="{ on: offerForm.kind === 'counter' }" @click="offerForm.kind = 'counter'">⇄ Counter offer</button>
            </div>
            <div v-if="offerForm.kind === 'counter'" style="margin-bottom:8px">
              <label class="lbl">Your amount (৳) *</label>
              <input v-model="offerForm.amount" type="number" min="1" placeholder="e.g. 95000" class="fld">
            </div>
            <label class="lbl">Note (optional)</label>
            <textarea v-model="offerForm.note" rows="2" placeholder="Timeline, materials, inclusions…" class="fld" style="resize:vertical"></textarea>
            <button class="btn-primary" style="margin-top:8px;width:100%" @click="submitOffer">💬 Submit offer</button>
            <div v-if="myOffer" class="c-sub" style="font-size:11.5px;margin-top:6px">You already have {{ myOffer.id }} ({{ myOffer.status }}) — resubmitting replaces it.</div>
          </div>

          <!-- approval box (owner) -->
          <div v-if="isOwner && selJob.status === 'pending'" class="approve-box">
            <div>⏳ Requested by <b>{{ selJob.requester }}</b> ({{ selJob.requester_role }}) — awaiting your approval to post for quotation.</div>
            <div style="display:flex;gap:8px;margin-top:10px">
              <button class="btn-primary" style="padding:8px 16px;font-size:13px" @click="rfqAction('rfq-approve', 'approved & posted')">✅ Approve & post</button>
              <button class="btn-ghost" style="padding:8px 16px;font-size:13px;color:var(--danger)" @click="rfqAction('rfq-cancel', 'cancelled')">🚫 Cancel</button>
            </div>
          </div>
        </div>

        <!-- footer actions -->
        <div v-if="isOwner" style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <template v-if="['pending', 'open'].includes(selJob.status)">
            <button class="btn-ghost" style="padding:8px 14px;font-size:12.5px" @click="openEdit">✏️ Edit</button>
          </template>
          <template v-if="selJob.status === 'awarded'">
            <button class="btn-primary" style="padding:8px 14px;font-size:12.5px" @click="rfqStatus('in_progress', 'started work')">🔧 Start work</button>
          </template>
          <template v-if="selJob.status === 'in_progress'">
            <button class="btn-primary" style="padding:8px 14px;font-size:12.5px" @click="rfqStatus('resolved', 'marked resolved')">✅ Mark resolved</button>
          </template>
          <template v-if="selJob.status === 'resolved'">
            <button class="btn-primary" style="padding:8px 14px;font-size:12.5px" @click="rfqStatus('closed', 'closed')">🏁 Close</button>
            <a v-if="selJob.mt_id" :href="'#/jobs'" @click="drawerOpen = false" class="btn-ghost" style="padding:8px 14px;font-size:12.5px;text-decoration:none">🔗 QC in Jobs</a>
          </template>
          <template v-if="['open', 'offers'].includes(selJob.status)">
            <button class="btn-ghost" style="padding:8px 14px;font-size:12.5px;color:var(--danger)" @click="rfqAction('rfq-cancel', 'cancelled')">🚫 Cancel task</button>
          </template>
        </div>
      </div>
    </template>

    <!-- ── NEW SERVICE TASK MODAL ── -->
    <template v-if="newOpen">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="newOpen = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(540px,96vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:90px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">📢 New service task / quotation</div>
          <button @click="newOpen = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:13px">
          <div v-if="mtCandidates.length" class="mt-poster" style="flex-direction:column;align-items:flex-start">
            <span style="font-size:12px;font-weight:800">Or convert a maintenance job already requested (tenant/manager):</span>
            <div style="display:flex;flex-wrap:wrap;gap:6px">
              <button v-for="m in mtCandidates.slice(0, 6)" :key="m.id" class="btn-ghost" style="padding:5px 10px;font-size:11.5px" @click="newForm.from_mt = newForm.from_mt === m.id ? '' : m.id; newForm.title = m.title" :style="newForm.from_mt === m.id ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : ''">{{ m.id }} · {{ m.title.slice(0, 22) }}</button>
            </div>
          </div>
          <div>
            <label class="lbl">Title *</label>
            <input v-model="newForm.title" placeholder="e.g. Roof waterproofing — Block A" class="fld">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label class="lbl">Category</label>
              <select v-model="newForm.cat" class="fld">
                <option v-for="c in cats" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>
            <div>
              <label class="lbl">Property</label>
              <select v-model="newForm.prop" class="fld">
                <option value="">— Any —</option>
                <option v-for="p in propsList" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </div>
          </div>
          <div>
            <label class="lbl">Unit</label>
            <select v-model="newForm.unit" class="fld">
              <option value="">— Any —</option>
              <option v-for="u in unitsList" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </div>
          <div>
            <label class="lbl">Description</label>
            <textarea v-model="newForm.desc" rows="3" placeholder="Scope of work, materials, expectations…" class="fld" style="resize:vertical"></textarea>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label class="lbl">Budget</label>
              <select v-model="newForm.budget_type" class="fld">
                <option value="tentative">~ Tentative budget</option>
                <option value="fixed">🎯 Fixed rate</option>
                <option value="quote">💬 Ask partners to quote</option>
              </select>
            </div>
            <div v-if="newForm.budget_type !== 'quote'">
              <label class="lbl">Amount (৳) *</label>
              <input v-model="newForm.budget_amount" type="number" min="1" placeholder="e.g. 85000" class="fld">
            </div>
            <div v-else>
              <label class="lbl">Deadline</label>
              <input v-model="newForm.deadline" type="date" class="fld">
            </div>
          </div>
          <div v-if="newForm.budget_type !== 'quote'">
            <label class="lbl">Deadline</label>
            <input v-model="newForm.deadline" type="date" class="fld">
          </div>
          <div>
            <label class="lbl">Notes</label>
            <input v-model="newForm.notes" placeholder="Internal notes (not visible to partners)" class="fld">
          </div>
          <div class="c-sub" style="font-size:11.5px">ℹ️ {{ isOwner ? 'You are the owner — this task posts for quotation immediately.' : 'Your request goes to the owner for approval before partners can quote.' }}</div>
          <button @click="createJob" class="btn-primary" style="margin-top:4px">📢 {{ isOwner ? 'Create & post' : 'Submit for approval' }}</button>
        </div>
      </div>
    </template>

    <!-- ── EDIT SERVICE TASK MODAL ── -->
    <template v-if="editOpen && editForm">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="editOpen = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(540px,96vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:90px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">✏️ Edit {{ editForm.id }}</div>
          <button @click="editOpen = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:13px">
          <div>
            <label class="lbl">Title *</label>
            <input v-model="editForm.title" class="fld">
          </div>
          <div>
            <label class="lbl">Category</label>
            <select v-model="editForm.cat" class="fld">
              <option v-for="c in cats" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>
          <div>
            <label class="lbl">Description</label>
            <textarea v-model="editForm.desc" rows="3" class="fld" style="resize:vertical"></textarea>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label class="lbl">Budget</label>
              <select v-model="editForm.budget_type" class="fld">
                <option value="tentative">~ Tentative budget</option>
                <option value="fixed">🎯 Fixed rate</option>
                <option value="quote">💬 Ask partners to quote</option>
              </select>
            </div>
            <div v-if="editForm.budget_type !== 'quote'">
              <label class="lbl">Amount (৳) *</label>
              <input v-model="editForm.budget_amount" type="number" min="1" class="fld">
            </div>
          </div>
          <div>
            <label class="lbl">Deadline</label>
            <input v-model="editForm.deadline" type="date" class="fld">
          </div>
          <button @click="saveEdit" class="btn-primary" style="margin-top:4px">💾 Save</button>
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
            <label class="lbl">Job *</label>
            <select v-model="invoiceForm.job" class="fld">
              <option v-for="j in jobOptions" :key="j.id" :value="j.id">{{ j.id }} — {{ j.title }}</option>
            </select>
          </div>
          <div>
            <label class="lbl">Amount (৳) *</label>
            <input v-model="invoiceForm.amount" type="number" min="0" placeholder="0" class="fld">
          </div>
          <div>
            <label class="lbl">Description</label>
            <textarea v-model="invoiceForm.desc" rows="3" placeholder="Work done, materials…" class="fld" style="resize:vertical"></textarea>
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
            <label class="lbl">Partner *</label>
            <select v-model="payoutForm.partner" class="fld">
              <option v-for="p in partners" :key="p.id" :value="p.id">{{ p.id }} — {{ p.name }}</option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label class="lbl">Month</label>
              <input v-model="payoutForm.month" type="month" class="fld">
            </div>
            <div>
              <label class="lbl">Amount (৳) *</label>
              <input v-model="payoutForm.amount" type="number" min="0" placeholder="0" class="fld">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label class="lbl">Status</label>
              <select v-model="payoutForm.status" class="fld">
                <option value="Paid">Paid</option>
                <option value="Scheduled">Scheduled</option>
                <option value="Pending">Pending</option>
              </select>
            </div>
            <div>
              <label class="lbl">Method</label>
              <select v-model="payoutForm.method" class="fld">
                <option value="Bank">Bank</option>
                <option value="bKash">bKash</option>
                <option value="Nagad">Nagad</option>
                <option value="Cash">Cash</option>
              </select>
            </div>
          </div>
          <div>
            <label class="lbl">Ref</label>
            <input v-model="payoutForm.ref" placeholder="e.g. NPSB-44002" class="fld">
          </div>
          <button @click="recordPayout" class="btn-primary" style="margin-top:4px">💵 Record</button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.d-cover .badge { background: #ffffff; }
.vend-toast { padding:10px 14px; border-radius:10px; margin-bottom:14px; font-weight:700; font-size:13.5px; background:rgba(46,204,113,.12); border:1px solid rgba(46,204,113,.35) }

/* category chips */
.chip-row { display:flex; gap:7px; flex-wrap:wrap; align-items:center; margin-bottom:14px }
.chip { border:1px solid var(--border); background:var(--bg-alt); color:var(--text-mute); border-radius:99px; padding:6px 13px; font-size:12px; font-weight:600; cursor:pointer; transition:all .15s }
.chip b { font-weight:800; margin-left:3px }
.chip.on { border-color:var(--primary); color:var(--primary); background:rgba(47,128,237,.08) }

/* board */
.board-kpis { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px }
.bkpi { display:flex; align-items:center; gap:7px; background:var(--bg-alt); border:1px solid var(--border); border-radius:12px; padding:8px 13px; font-size:12.5px }
.bkpi-dot { width:9px; height:9px; border-radius:50%; flex:none }
.bkpi-v { font-weight:800; font-size:15px; color:var(--text) }
.bkpi-l { color:var(--text-mute) }

.mt-poster { display:flex; gap:8px; flex-wrap:wrap; align-items:center; background:rgba(47,128,237,.06); border:1px dashed rgba(47,128,237,.4); border-radius:12px; padding:10px 14px; margin-bottom:14px; font-size:12.5px; color:var(--text-mute) }

.board { display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:12px; align-items:start }
.bcol { background:var(--bg-alt); border:1px solid var(--border); border-radius:14px; overflow:hidden; display:flex; flex-direction:column }
.bcol-h { display:flex; align-items:center; gap:7px; padding:11px 13px 4px }
.bcol-h b { font-size:13px; color:var(--text) }
.bcol-dot { width:10px; height:10px; border-radius:3px; flex:none }
.bcol-count { margin-left:auto; background:var(--bg); border:1px solid var(--border); border-radius:99px; padding:1px 9px; font-size:11.5px; font-weight:800; color:var(--text-mute) }
.bcol-sub { padding:0 13px 9px; font-size:10.5px; color:var(--text-mute); border-bottom:1px solid var(--border) }
.bcol-body { padding:10px; display:flex; flex-direction:column; gap:9px; flex:1 }
.bcol-empty { font-size:11px; color:var(--text-mute); text-align:center; padding:14px 6px; border:1px dashed var(--border); border-radius:10px }

.bjcard { background:var(--card); border:1px solid var(--border); border-left:3px solid var(--col); border-radius:11px; padding:10px 11px; cursor:pointer; transition:transform .12s, box-shadow .12s }
.bjcard:hover { transform:translateY(-1px); box-shadow:0 5px 14px rgba(16,24,40,.09) }
.bj-top { display:flex; gap:6px; align-items:center; margin-bottom:5px }
.bj-id { font-size:10.5px; font-weight:800; color:var(--text-mute); letter-spacing:.3px }
.bj-wo { font-size:10px; font-weight:800; color:#16A085; background:rgba(22,160,133,.1); border-radius:5px; padding:1px 6px }
.bj-offers { font-size:10px; font-weight:800; color:#8E44AD; background:rgba(142,68,173,.1); border-radius:5px; padding:1px 6px }
.bj-title { font-size:12.5px; font-weight:700; color:var(--text); line-height:1.4; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden }
.bj-cat { font-size:10.5px; color:var(--primary); margin-top:3px; font-weight:700 }
.bj-meta { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:6px }
.bj-budget { font-size:11px; font-weight:700; color:var(--text); background:var(--bg-alt); border:1px solid var(--border); border-radius:6px; padding:2px 7px }
.bj-best { font-size:10.5px; font-weight:800; color:#1E8449 }
.bj-foot { display:flex; gap:6px; justify-content:space-between; align-items:center; margin-top:7px }
.bj-req { font-size:10.5px; color:var(--text-mute) }
.bj-dl { font-size:10.5px; color:var(--text-mute) }
.bj-award { margin-top:7px; font-size:11px; font-weight:800; color:#16A085; background:rgba(22,160,133,.1); border-radius:7px; padding:4px 8px }

/* drawer */
.budget-box { border-radius:12px; padding:12px 14px; border:1px solid var(--border); background:var(--bg-alt) }
.budget-box.fixed { border-color:rgba(22,160,133,.5); background:rgba(22,160,133,.06) }
.budget-box.tentative { border-color:rgba(230,126,34,.5); background:rgba(230,126,34,.06) }
.budget-box.quote { border-color:rgba(142,68,173,.5); background:rgba(142,68,173,.06) }
.bb-label { font-size:10px; font-weight:800; color:var(--text-mute); text-transform:uppercase; letter-spacing:.4px }
.bb-line { display:flex; justify-content:space-between; align-items:center; margin-top:6px; font-size:13.5px }
.bb-type { font-weight:700; color:var(--text) }
.bb-line b { font-size:15px; color:var(--primary) }
.bb-best { margin-top:7px; font-size:12px; color:#1E8449 }

.drawer-sec .ds-h { font-size:11px; font-weight:800; color:var(--text-mute); text-transform:uppercase; letter-spacing:.4px; margin-bottom:6px }
.award-box { display:flex; gap:11px; align-items:center; background:rgba(22,160,133,.08); border:1px solid rgba(22,160,133,.35); border-radius:12px; padding:12px 14px }
.ab-ico { font-size:22px }
.offer-row { display:flex; gap:10px; align-items:flex-start; border:1px solid var(--border); border-radius:12px; padding:10px 12px; background:var(--card); margin-bottom:8px }
.offer-row.accepted { border-color:rgba(22,160,133,.5); background:rgba(22,160,133,.06) }
.offer-row.rejected { opacity:.55 }
.off-ava { width:34px; height:34px; border-radius:50%; color:#fff; font-weight:800; font-size:12px; display:flex; align-items:center; justify-content:center; flex:none }
.offer-form { background:var(--bg-alt); border:1px dashed var(--border); border-radius:12px; padding:12px 14px }
.approve-box { background:rgba(230,126,34,.08); border:1px solid rgba(230,126,34,.4); border-radius:12px; padding:12px 14px; font-size:13px }
.lbl { font-size:11px; font-weight:800; color:var(--text-mute); text-transform:uppercase; letter-spacing:.3px; display:block; margin-bottom:5px }
.fld { width:100%; padding:9px 11px; border:1px solid var(--border); border-radius:10px; background:var(--bg-alt); font-family:inherit; font-size:13px; color:var(--text); outline:none }
</style>
