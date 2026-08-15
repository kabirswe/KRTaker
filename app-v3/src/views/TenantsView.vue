<script setup>
import { computed, ref, watch, onUnmounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { lang, t } from '../lib/i18n'
import { useAuthStore } from '../stores/auth'
import { apiCall, apiUpload, apiBlob } from '../api/client'
import { badge, useViewMode, usePager } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'
import ImportWizard from '../components/ImportWizard.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('tenants')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const role = computed(() => auth.user?.role || '')
const canManage = computed(() => ['superadmin', 'owner', 'manager'].includes(role.value))
const canNote = computed(() => ['superadmin', 'owner'].includes(role.value))            // private note = owner only
const canPay = computed(() => ['superadmin', 'owner', 'manager', 'accountant'].includes(role.value))

const tenantsAll = computed(() => data.list('tenants'))
const leasesAll = computed(() => data.list('leases'))
const unitsAll = computed(() => data.list('units'))
const propsAll = computed(() => data.list('properties'))
const invoicesAll = computed(() => data.list('invoices'))
const ticketsAll = computed(() => data.list('tickets'))
const utilsAll = computed(() => data.list('utility_bills'))
const receiptsAll = computed(() => data.list('receipts'))
const maintAll = computed(() => data.list('maintenance_requests'))
const docsAll = computed(() => data.list('documents'))
const nidVerifs = computed(() => data.list('nid_verifications'))
const thanaForms = computed(() => data.list('thana_forms'))
const renewalsAll = computed(() => data.list('renewal_requests'))
const metersAll = computed(() => data.list('meter_readings'))

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const propName = (pid) => propsAll.value.find(p => p.id === pid)?.name || pid || '—'
const unitName = (uid) => unitsAll.value.find(u => u.id === uid)?.name || uid || '—'
// deterministic avatar color from id
const AV_COLORS = ['#2F80ED', '#27AE60', '#E67E22', '#9B59B6', '#E74C3C', '#16A085', '#8E44AD', '#2980B9']
function avatarColor(id) { let h = 0; for (const c of String(id)) h = (h * 31 + c.charCodeAt(0)) >>> 0; return AV_COLORS[h % AV_COLORS.length] }
function initials(name) { return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() }
function maskNid(nid) { return nid ? String(nid).replace(/^(.{4}).*(.{4})$/, '$1••••$2') : '—' }
function leaseDaysLeft(l) { if (!l?.end) return null; return Math.round((new Date(l.end) - Date.now()) / 86400000) }
const today = () => new Date().toISOString().slice(0, 10)
const fmtSize = (b) => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : b > 1024 ? Math.round(b / 1024) + ' KB' : (b || 0) + ' B'
const fmtTs = (ts) => ts ? String(ts).replace('T', ' ').slice(0, 16) : '—'

// ── joins ──
const leasesOfTenant = (t) => leasesAll.value.filter(l => l.t === t.id)
const activeLeaseOf = (t) => leasesOfTenant(t).find(l => String(l.status).toLowerCase() === 'active') || leasesOfTenant(t)[0]
const unitsOfTenant = (t) => { const us = new Set(leasesOfTenant(t).map(l => l.u)); return unitsAll.value.filter(u => us.has(u.id)) }
const invoicesOfTenant = (t) => { const ls = new Set(leasesOfTenant(t).map(l => l.id)); return invoicesAll.value.filter(i => ls.has(i.l)) }
const ticketsOfTenant = (t) => { const us = new Set(unitsOfTenant(t).map(u => u.id)); return ticketsAll.value.filter(x => us.has(x.u)) }
const utilsOfTenant = (t) => { const us = new Set(unitsOfTenant(t).map(u => u.id)); return utilsAll.value.filter(b => us.has(b.unit)) }
const maintOfTenant = (t) => { const us = new Set(unitsOfTenant(t).map(u => u.id)); return maintAll.value.filter(m => us.has(m.unit) || m.tenant === t.id) }
const renewalsOfTenant = (t) => { const ls = new Set(leasesOfTenant(t).map(l => l.id)); return renewalsAll.value.filter(r => ls.has(r.lease)) }
const metersOfTenant = (t) => { const us = new Set(unitsOfTenant(t).map(u => u.id)); return metersAll.value.filter(m => us.has(m.unit)) }
const leaseDocsOf = (leaseId) => docsAll.value.filter(d => d.kind === 'lease' && d.ref === leaseId)
function monthlyRent(t) { return leasesOfTenant(t).filter(l => String(l.status).toLowerCase() === 'active').reduce((s, l) => s + (l.rent || 0), 0) }
function outstanding(t) { return invoicesOfTenant(t).filter(i => String(i.status).toLowerCase() !== 'paid').reduce((s, i) => s + (i.net || 0), 0) }
function collectionRateT(t) {
  const invs = invoicesOfTenant(t); if (!invs.length) return null
  const paid = invs.filter(i => String(i.status).toLowerCase() === 'paid').reduce((s, i) => s + (i.net || 0), 0)
  const tot = invs.reduce((s, i) => s + (i.net || 0), 0)
  return tot ? Math.round(paid / tot * 100) : null
}

// ── verification badges ──
const nidVerifiedOf = (t) => nidVerifs.value.some(v => v.tenant === t.id && String(v.status).toLowerCase() === 'verified')
const thanaVerifiedOf = (t) => thanaForms.value.some(f => f.tenant === t.id && String(f.status).toLowerCase() === 'verified')

// ── trust tier badges (bharakhata parity: Bronze → Platinum) ──
const TIER = {
  Platinum: { ico: '💎', color: '#2f80ed' },
  Gold:     { ico: '🥇', color: '#d4a017' },
  Silver:   { ico: '🥈', color: '#8f9aa5' },
  Bronze:   { ico: '🥉', color: '#b87333' },
}
function trustTier(t) {
  const nid = nidVerifiedOf(t), thana = thanaVerifiedOf(t)
  const rate = collectionRateT(t)
  if (nid && thana && rate !== null && rate >= 90) return 'Platinum'
  if (nid && thana) return 'Gold'
  if (nid || thana || (rate !== null && rate >= 75)) return 'Silver'
  return null
}
const tierStyle = (name) => { const x = TIER[name] || TIER.Bronze; return { background: x.color + '22', color: x.color, border: '1px solid ' + x.color + '55', fontWeight: 800 } }
const tierLabel = (name) => { const x = TIER[name] || TIER.Bronze; return (x.ico + ' ' + name).trim() }

// ── payment (partial) ──
const receiptsOfInv = (invId) => receiptsAll.value.filter(r => r.inv === invId)
const paidOfInvoice = (i) => receiptsOfInv(i.id).reduce((s, r) => s + (r.amount || 0), 0)
const invRemaining = (i) => (i.net || 0) - paidOfInvoice(i)
const invStatus = (i) => String(i.status).toLowerCase() === 'paid' ? 'Paid' : paidOfInvoice(i) > 0 ? 'Partial' : 'Unpaid'

// ── KPIs ──
const kpis = computed(() => {
  const indiv = tenantsAll.value.filter(t => String(t.kind).toLowerCase() === 'individual').length
  const corp = tenantsAll.value.filter(t => String(t.kind).toLowerCase() === 'corporate').length
  const nrb = tenantsAll.value.filter(t => String(t.nrb) === '1').length
  const active = leasesAll.value.filter(l => String(l.status).toLowerCase() === 'active').length
  const rentRoll = leasesAll.value.filter(l => String(l.status).toLowerCase() === 'active').reduce((s, l) => s + (l.rent || 0), 0)
  const oust = invoicesAll.value.filter(i => String(i.status).toLowerCase() !== 'paid').reduce((s, i) => s + (i.net || 0), 0)
  const verified = tenantsAll.value.filter(t => nidVerifiedOf(t) || thanaVerifiedOf(t)).length
  return [
    { label: 'Tenants', ico: '👥', value: tenantsAll.value.length, trend: `${indiv} individual · ${corp} corporate` },
    { label: 'NRB clients', ico: '🌍', value: nrb, trend: 'non-resident Bangladeshi', ok: true },
    { label: 'Verified', ico: '🪪', value: verified, trend: 'NID / Thana verified', ok: true },
    { label: 'Active leases', ico: '📄', value: active, trend: 'of ' + leasesAll.value.length },
    { label: 'Rent roll / mo', ico: '💵', value: money(rentRoll), trend: 'active leases' },
    { label: 'Outstanding', ico: '⏳', value: money(oust), trend: 'unpaid invoices', ok: oust === 0 },
  ]
})

// ── filters / sort ──
const query = ref('')
const kindFilter = ref('')
const nrbOnly = ref(false)
const propFilter = ref('')
const sortBy = ref('name')
const kindOptions = computed(() => [...new Set(tenantsAll.value.map(t => t.kind).filter(Boolean))].sort())
const propOptions = computed(() => propsAll.value.map(p => ({ id: p.id, name: p.name })))

const filtered = computed(() => {
  let out = tenantsAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(t => JSON.stringify(t).toLowerCase().includes(q) || unitsOfTenant(t).map(u => unitName(u.id)).join(' ').toLowerCase().includes(q) || propName(unitsOfTenant(t)[0]?.p).toLowerCase().includes(q))
  if (kindFilter.value) out = out.filter(t => t.kind === kindFilter.value)
  if (nrbOnly.value) out = out.filter(t => String(t.nrb) === '1')
  if (propFilter.value) { const us = new Set(unitsAll.value.filter(u => u.p === propFilter.value).map(u => u.id)); out = out.filter(t => unitsOfTenant(t).some(u => us.has(u.id))) }
  const get = (t) => sortBy.value === 'rent' ? monthlyRent(t) : sortBy.value === 'outstanding' ? outstanding(t) : (t.name || '')
  return [...out].sort((a, b) => typeof get(a) === 'string' ? String(get(a)).localeCompare(String(get(b))) : get(b) - get(a))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value
  if (!rows.length) return
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'tenants.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
const tab = ref('profile')
const TABS = [
  { id: 'profile', label: 'Profile', ico: '👤' },
  { id: 'leases', label: 'Lease & Unit', ico: '📄' },
  { id: 'billing', label: 'Billing', ico: '💳' },
  { id: 'tickets', label: 'Tickets & Maint.', ico: '🔧' },
  { id: 'hando', label: 'Handover', ico: '📦' },
  { id: 'chat', label: 'Chat', ico: '💬' },
  { id: 'docs', label: 'Documents', ico: '📎' },
]
const tabCount = (id) => id === 'profile' ? '·' : id === 'leases' ? selLeases.value.length : id === 'billing' ? selInvoices.value.length + selUtils.value.length : id === 'tickets' ? selTickets.value.length + selMaint.value.length : id === 'hando' ? hovoList.value.length : id === 'chat' ? chatMsgs.value.length : selDocs.value.length
function openDetail(t) { sel.value = t; tab.value = 'profile' }
function closeDetail() { sel.value = null }
function reResolveSel() { if (sel.value) sel.value = tenantsAll.value.find(t => t.id === sel.value.id) || sel.value }
// deep link: /tenants?open=T-001 opens that tenant's drawer
watch(() => route.query.open, (id) => {
  if (id) { const t = tenantsAll.value.find(x => x.id === id); if (t) openDetail(t) }
}, { immediate: true })
watch(tenantsAll, reResolveSel, { deep: true })

const selLeases = computed(() => sel.value ? leasesOfTenant(sel.value) : [])
const selUnits = computed(() => sel.value ? unitsOfTenant(sel.value) : [])
const selInvoices = computed(() => sel.value ? invoicesOfTenant(sel.value) : [])
const selTickets = computed(() => sel.value ? ticketsOfTenant(sel.value) : [])
const selUtils = computed(() => sel.value ? utilsOfTenant(sel.value) : [])
const selMaint = computed(() => sel.value ? maintOfTenant(sel.value) : [])
const selRenewals = computed(() => sel.value ? renewalsOfTenant(sel.value) : [])
const selMeters = computed(() => sel.value ? metersOfTenant(sel.value) : [])
const selDocs = computed(() => sel.value ? docsAll.value.filter(d => d.kind === 'tenant' && d.ref === sel.value.id) : [])
const selStats = computed(() => {
  if (!sel.value) return []
  const invs = selInvoices.value
  const paid = invs.filter(i => String(i.status).toLowerCase() === 'paid').reduce((s, i) => s + (i.net || 0), 0)
  const tot = invs.reduce((s, i) => s + (i.net || 0), 0)
  return [
    { label: 'Units', v: selUnits.value.length },
    { label: 'Rent / mo', v: money(monthlyRent(sel.value)) },
    { label: 'Invoiced', v: money(tot) },
    { label: 'Paid', v: money(paid) },
    { label: 'Outstanding', v: money(outstanding(sel.value)) },
    { label: 'Collection', v: collectionRateT(sel.value) !== null ? collectionRateT(sel.value) + '%' : '—' },
    { label: 'Open tickets', v: selTickets.value.filter(t => String(t.status).toLowerCase() === 'open').length },
    { label: 'Kind', v: sel.value.kind + (String(sel.value.nrb) === '1' ? ' · NRB' : '') },
  ]
})
// subtitle line under the header — active lease/unit info (more useful than id · masked NID · portal)
const selSubtitle = computed(() => {
  if (!sel.value) return ''
  const al = activeLeaseOf(sel.value)
  if (al) {
    const u = unitsAll.value.find(x => x.id === al.u)
    const d = leaseDaysLeft(al)
    const days = d === null ? '' : d < 0 ? ` · expired ${-d}d ago` : ` · ${d}d left`
    return `📄 ${al.id} · 🚪 ${u ? u.name : al.u} · 🏢 ${propName(u?.p)} · until ${al.end || '—'}${days}`
  }
  return `No active lease · ${sel.value.sub_email ? 'portal: ' + sel.value.sub_email : 'no portal account'}`
})
// ── single-line scrollable tabs ──
const tabScroll = ref(null)
function scrollTabs(dir) { if (tabScroll.value) tabScroll.value.scrollBy({ left: dir * 150, behavior: 'smooth' }) }

// ── photo ──
const photoUrls = new Map()
const selPhoto = ref('')
const uploadingPhoto = ref(false)
async function loadPhoto() {
  const t = sel.value
  if (!t || !t.photo) { selPhoto.value = ''; return }
  if (photoUrls.has(t.id)) { selPhoto.value = photoUrls.get(t.id); return }
  const url = await apiBlob('app-photo?action=view&target=tenant&id=' + encodeURIComponent(t.id))
  if (url) { photoUrls.set(t.id, url); selPhoto.value = url }
}
async function onPhotoPick(e) {
  const f = e.target.files && e.target.files[0]
  e.target.value = ''
  if (!f || !sel.value) return
  const fd = new FormData()
  fd.append('id', sel.value.id)
  fd.append('file', f)
  uploadingPhoto.value = true
  try {
    const r = await apiUpload('app-photo?action=tenant-upload', fd)
    if (r.ok) { window.__krToast?.('📸 Photo updated', 'ok'); photoUrls.delete(sel.value.id); await data.bootstrap(); reResolveSel(); loadPhoto() }
    else window.__krToast?.(r.error || 'Photo upload failed', 'error')
  } finally { uploadingPhoto.value = false }
}

// ── family info (individual) / company profile (corporate) ──
const family = ref([])
const company = ref({})
const famSaving = ref(false)
const coSaving = ref(false)
function parseJson(s, fb) { try { const v = JSON.parse(s); return Array.isArray(v) ? v : fb } catch (e) { return fb } }
function loadFamilyCompany() {
  if (!sel.value) { family.value = []; company.value = {}; return }
  family.value = parseJson(sel.value.family, [])
  const c = parseJson(sel.value.company, {})
  company.value = c && typeof c === 'object' && !Array.isArray(c) ? c : {}
}
const FAM_FIELDS = [
  { k: 'name', label: 'Name', ph: 'Spouse / child name', w: '2fr' },
  { k: 'relation', label: 'Relation', ph: 'Spouse / Son / Daughter…', w: '1fr' },
  { k: 'nid', label: 'NID', ph: 'Optional', w: '1.4fr' },
  { k: 'dob', label: 'DOB', ph: 'YYYY-MM-DD', w: '1fr' },
  { k: 'phone', label: 'Phone', ph: '01xxx', w: '1fr' },
  { k: 'occupation', label: 'Occupation', ph: 'Optional', w: '1fr' },
]
/* redesigned entry: modal form per member (add / edit) */
const famModal = ref(null)   // { index: null|i, m: {...} }
function emptyFamMember() { return { name: '', relation: '', nid: '', dob: '', phone: '', occupation: '' } }
function openFamAdd() { famModal.value = { index: null, m: emptyFamMember() } }
function openFamEdit(i) { famModal.value = { index: i, m: { ...family.value[i] } } }
function saveFamMember() {
  const fm = famModal.value
  if (!fm) return
  if (!fm.m.name.trim()) { window.__krToast?.('Member name is required', 'error'); return }
  if (fm.index === null) family.value.push({ ...fm.m })
  else family.value[fm.index] = { ...fm.m }
  famModal.value = null
  saveFamily()
}
function removeFamilyRow(i) {
  if (!confirm(`Remove ${family.value[i]?.name || 'this member'} from family?`)) return
  family.value.splice(i, 1)
  saveFamily()
}
async function saveFamily() {
  if (!sel.value) return
  famSaving.value = true
  try {
    const clean = family.value.filter(m => m.name || m.relation || m.phone)
    const r = await apiCall('app-crud', { action: 'update', collection: 'tenants', id: sel.value.id, data: { family: JSON.stringify(clean) } })
    if (r.ok) { window.__krToast?.('👨‍👩‍👧 Family info saved', 'ok'); await data.bootstrap(); reResolveSel() }
    else window.__krToast?.(r.error || 'Save failed', 'error')
  } finally { famSaving.value = false }
}
const CO_FIELDS = [
  { k: 'trade', label: 'Trade / Business type', ph: 'e.g. Garments, Trading, NGO…', w: '1fr' },
  { k: 'bin', label: 'BIN / TIN / Reg. no', ph: 'e.g. BIN 001234567-0101', w: '1fr' },
  { k: 'address', label: 'Registered address', ph: 'Company address', w: 'full' },
  { k: 'city', label: 'City', ph: 'Dhaka', w: '0.6fr' },
  { k: 'contact_person', label: 'Contact person', ph: 'Authorized person name', w: '1fr' },
  { k: 'phone', label: 'Phone', ph: '01xxx', w: '0.8fr' },
  { k: 'email', label: 'Email', ph: 'office@company.com', w: '1fr' },
  { k: 'industry', label: 'Industry', ph: 'e.g. Textile', w: '0.8fr' },
  { k: 'website', label: 'Website', ph: 'https://…', w: '1fr' },
]
async function saveCompany() {
  if (!sel.value) return
  coSaving.value = true
  try {
    const r = await apiCall('app-crud', { action: 'update', collection: 'tenants', id: sel.value.id, data: { company: JSON.stringify(company.value) } })
    if (r.ok) { window.__krToast?.('🏢 Company profile saved', 'ok'); await data.bootstrap(); reResolveSel() }
    else window.__krToast?.(r.error || 'Save failed', 'error')
  } finally { coSaving.value = false }
}

// ── notices (vacant / warning / general / due) ──
const noticeModal = ref(null)
const noticeSaving = ref(false)
const NOTICE_PRESETS = [
  { id: 'eviction', label: '🚫 Eviction notice', title: 'Eviction notice', body: 'This is a formal notice that you are required to vacate the premises by the end of the notice period, in accordance with the tenancy agreement and applicable law. Please settle all outstanding dues and complete the move-out handover checklist before the vacating date.' },
  { id: 'warning', label: '⚠️ Warning', title: 'Formal warning', body: 'Please be advised to comply with the terms of the tenancy agreement. Repeated non-compliance may lead to further action.' },
  { id: 'due', label: '💰 Payment due', title: 'Rent payment due', body: 'Kindly clear your outstanding rent at the earliest. Late payment charges may apply as per the agreement.' },
  { id: 'general', label: '📋 General notice', title: 'Notice to tenants', body: '' },
]
function openNotice() { noticeModal.value = { preset: 'general', title: 'Notice to tenants', body: '', vacateBy: '' } }
function applyPreset() {
  const p = NOTICE_PRESETS.find(x => x.id === noticeModal.value.preset)
  if (p) { noticeModal.value.title = p.title; noticeModal.value.body = p.body; if (p.id !== 'eviction') noticeModal.value.vacateBy = '' }
}
async function sendNotice() {
  const m = noticeModal.value
  if (!m.title.trim()) { window.__krToast?.('Notice title required', 'error'); return }
  noticeSaving.value = true
  try {
    let body = m.body.trim()
    if (m.preset === 'eviction' && m.vacateBy) body += `\n\n🚪 Vacate by: ${m.vacateBy} (soft deadline — settle dues and complete handover before this date).`
    const r = await apiCall('app-notice-create', { title: m.title.trim(), body })
    if (r.ok) { window.__krToast?.(`📢 ${r.id} posted`, 'ok'); noticeModal.value = null; await data.bootstrap() }
    else window.__krToast?.(r.error || 'Failed to post notice', 'error')
  } finally { noticeSaving.value = false }
}

// ── payment reminder ──
const remindSending = ref(false)
async function sendReminder() {
  if (!sel.value) return
  if (!confirm(`Send a payment reminder to ${sel.value.name} (email + board notice)?`)) return
  remindSending.value = true
  try {
    const r = await apiCall('app-tenant-remind', { tenant_id: sel.value.id })
    if (r.ok) {
      const parts = []
      if (r.total_due > 0) parts.push(`${r.invoices} due · ৳${(r.total_due || 0).toLocaleString('en-IN')}`)
      if (r.total_upcoming > 0) parts.push(`upcoming ৳${(r.total_upcoming || 0).toLocaleString('en-IN')}`)
      window.__krToast?.(`🔔 Reminder sent — ${parts.join(' + ') || 'nothing due'}${r.emailed ? ' + email' : ''}`, 'ok')
    }
    else window.__krToast?.(r.error || 'Reminder failed', 'error')
  } finally { remindSending.value = false }
}

// ── GDPR data export (V2.20): download this tenant's personal data as JSON ──
const gdprBusy = ref(false)
async function exportGdpr() {
  if (!sel.value) return
  gdprBusy.value = true
  try {
    const res = await fetch('https://krtaker.com/api/app-gdpr-export?t=' + encodeURIComponent(sel.value.id), { headers: { Authorization: 'Bearer ' + (auth.token || '') } })
    if (!res.ok) { window.__krToast?.('Export failed (HTTP ' + res.status + ')', 'error'); return }
    const blob = await res.blob()
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'krtaker_gdpr_' + sel.value.id + '.json'; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000)
    window.__krToast?.('📦 Tenant data exported', 'ok')
  } catch (e) { window.__krToast?.('Export failed: ' + e.message, 'error') }
  finally { gdprBusy.value = false }
}

// ── lease extension (renewal) ──
const offerModal = ref(null)
const offerSaving = ref(false)
function openOffer() {
  const active = selLeases.value.filter(l => !['Expired', 'Terminated'].includes(l.status))
  const l = active[0] || selLeases.value[0]
  if (!l) { window.__krToast?.('No lease to extend', 'error'); return }
  offerModal.value = { lease: l.id, months: 12, escalation: '', new_rent: '', note: '' }
}
async function submitOffer() {
  const m = offerModal.value
  if (!m.lease || !m.months) { window.__krToast?.('Lease and months required', 'error'); return }
  offerSaving.value = true
  try {
    const payload = { lease: m.lease, months: parseInt(m.months), note: m.note || '' }
    if (m.new_rent) payload.new_rent = parseInt(m.new_rent)
    else if (m.escalation !== '' && m.escalation !== null) payload.escalation = parseFloat(m.escalation)
    const r = await apiCall('app-renewal-offer', payload)
    if (r.ok) { window.__krToast?.(`📄 ${r.id} offered — ৳${(r.new_rent || 0).toLocaleString('en-IN')}/mo`, 'ok'); offerModal.value = null; await data.bootstrap() }
    else window.__krToast?.(r.error || 'Offer failed', 'error')
  } finally { offerSaving.value = false }
}
async function decideRenewal(r, action) {
  if (action === 'reject' && !confirm(`Reject renewal ${r.id}?`)) return
  const res = await apiCall('app-renewal-decide', { id: r.id, action })
  if (res.ok) { window.__krToast?.(`📄 ${r.id} → ${res.status}`, 'ok'); await data.bootstrap() }
  else window.__krToast?.(res.error || 'Failed', 'error')
}

// ── maintenance approve / status / cost ──
async function setMaintStatus(m, status) {
  const r = await apiCall('app-maintenance', { action: 'status', id: m.id, status })
  if (r.ok) { window.__krToast?.(`🔧 ${m.id} → ${status}`, 'ok'); await data.bootstrap() }
  else window.__krToast?.(r.error || 'Failed', 'error')
}
const costModal = ref(null)
const costSaving = ref(false)
function openCost(m) { costModal.value = { id: m.id, cost_estimate: m.cost_estimate || 0, actual_cost: m.actual_cost || 0, charge_to: m.charge_to || 'owner' } }
async function submitCost() {
  const m = costModal.value
  costSaving.value = true
  try {
    const r = await apiCall('app-maintenance', { action: 'cost', id: m.id, cost_estimate: parseInt(m.cost_estimate) || 0, actual_cost: parseInt(m.actual_cost) || 0, charge_to: m.charge_to })
    if (r.ok) { window.__krToast?.('💰 Cost updated', 'ok'); costModal.value = null; await data.bootstrap() }
    else window.__krToast?.(r.error || 'Failed', 'error')
  } finally { costSaving.value = false }
}

// ── utility billing (postpaid meter) ──
const meterModal = ref(null)
const meterSaving = ref(false)
const billModal = ref(null)
const billSaving = ref(false)
const UTIL_TYPES = ['electric', 'gas', 'water']
const unitOptions = computed(() => selUnits.value.map(u => ({ id: u.id, name: unitName(u.id) })))
function openMeter() {
  const u = unitOptions.value[0]
  meterModal.value = { unit: u ? u.id : '', type: 'electric', month: new Date().toISOString().slice(0, 7), reading: '', note: '' }
}
async function submitMeter() {
  const m = meterModal.value
  if (!m.unit || !m.reading) { window.__krToast?.('Unit and reading required', 'error'); return }
  meterSaving.value = true
  try {
    const r = await apiCall('app-meter-submit', { unit: m.unit, type: m.type, month: m.month, reading: parseInt(m.reading), note: m.note || '' })
    if (r.ok) { window.__krToast?.(`⚡ ${r.id} recorded`, 'ok'); meterModal.value = null; await data.bootstrap() }
    else window.__krToast?.(r.error || 'Failed', 'error')
  } finally { meterSaving.value = false }
}
function openBill() {
  const u = unitOptions.value[0]
  billModal.value = { unit: u ? u.id : '', type: 'electric', month: new Date().toISOString().slice(0, 7), curr_reading: '' }
}
async function generateBill() {
  const m = billModal.value
  if (!m.unit || !m.month) { window.__krToast?.('Unit and month required', 'error'); return }
  billSaving.value = true
  try {
    const payload = { unit: m.unit, type: m.type, month: m.month }
    if (m.curr_reading) payload.curr_reading = parseInt(m.curr_reading)
    const r = await apiCall('app-utility-bill-generate', payload)
    if (r.ok) { window.__krToast?.(`🧾 ${r.bill.id} generated — ৳${(r.bill.amount || 0).toLocaleString('en-IN')}`, 'ok'); billModal.value = null; await data.bootstrap() }
    else window.__krToast?.(r.error || 'Failed', 'error')
  } finally { billSaving.value = false }
}

// ── handover checklist ──
const hovoList = ref([])
const hovoSel = ref(null)
const hovoLoading = ref(false)
const hovoSaving = ref(false)
async function loadHovo() {
  if (!sel.value) return
  hovoLoading.value = true
  try {
    const ls = new Set(leasesOfTenant(sel.value).map(l => l.id))
    const r = await apiCall('app-hando-list', {})
    if (r.ok) hovoList.value = (r.checklists || []).filter(c => ls.has(c.lease))
  } finally { hovoLoading.value = false }
}
async function createHovo(lease, kind) {
  const r = await apiCall('app-hando-create', { lease, kind })
  if (r.ok) { window.__krToast?.(`📦 ${r.id} created`, 'ok'); await loadHovo(); openHovo(r.id) }
  else window.__krToast?.(r.error || 'Failed', 'error')
}
async function openHovo(id) {
  const r = await apiCall('app-hando-get', { id })
  if (r.ok) { hovoSel.value = r.checklist; hovoSel.value.items = r.checklist.items || [] }
  else window.__krToast?.(r.error || 'Failed to load checklist', 'error')
}
function addHovoItem() { if (hovoSel.value) hovoSel.value.items.push({ id: 'x' + Date.now(), label: '', checked: 0, note: '' }) }
function removeHovoItem(i) { if (hovoSel.value) hovoSel.value.items.splice(i, 1) }
async function saveHovo() {
  if (!hovoSel.value) return
  hovoSaving.value = true
  try {
    const r = await apiCall('app-hando-save', { id: hovoSel.value.id, items: hovoSel.value.items })
    if (r.ok) { window.__krToast?.(`📦 ${hovoSel.value.id} saved — ${r.done}/${r.total} → ${r.status}`, 'ok'); await loadHovo() }
    else window.__krToast?.(r.error || 'Save failed', 'error')
  } finally { hovoSaving.value = false }
}
const hovoForLease = (leaseId) => hovoList.value.filter(c => c.lease === leaseId)

// ── agreement / lease paper viewer ──
const agreeModal = ref(null)
function openAgree(l) { agreeModal.value = l }
async function viewDoc(d) {
  const url = await apiBlob('app-doc-view?id=' + encodeURIComponent(d.id))
  if (url) window.open(url, '_blank')
  else window.__krToast?.('Could not load document', 'error')
}
async function downloadDoc(d) {
  const url = await apiBlob('app-doc-download?id=' + encodeURIComponent(d.id))
  if (url) { const a = document.createElement('a'); a.href = url; a.download = d.name || d.id; a.click() }
  else window.__krToast?.('Could not download document', 'error')
}

// ── private note (owner only) ──
const noteText = ref('')
const noteLoaded = ref(false)
const noteSaving = ref(false)
async function loadNote() {
  if (!sel.value || !canNote.value) return
  noteLoaded.value = false
  const r = await apiCall('app-tenant-note', { tenant_id: sel.value.id })
  if (r.ok) noteText.value = r.note || ''
  noteLoaded.value = true
}
async function saveNote() {
  if (!sel.value) return
  noteSaving.value = true
  try {
    const r = await apiCall('app-tenant-note-save', { tenant_id: sel.value.id, note: noteText.value })
    if (r.ok) window.__krToast?.('🔒 Private note saved', 'ok')
    else window.__krToast?.(r.error || 'Save failed', 'error')
  } finally { noteSaving.value = false }
}

// ── tenant score ──
const scoreData = ref(null)
const scoreLoading = ref(false)
const scoreLoaded = ref(false)
async function loadScore() {
  if (!sel.value || scoreLoaded.value) return
  scoreLoading.value = true
  try {
    const r = await apiCall('app-score-detail', { t: sel.value.id })
    if (r.ok) { scoreData.value = r; scoreLoaded.value = true }
  } finally { scoreLoading.value = false }
}
const scorePct = computed(() => (scoreData.value?.score ?? 0) + '%')
const scoreModal = ref(false)   // key-indicator drill-down modal

// ── settlement (move-out statement) ──
const settleModal = ref(null)
const settleLoading = ref(false)
const settleDeducts = ref([])          // editable damage deductions [{label, amount}]
const settleApplyDeposit = ref(true)
const settleDate = ref(new Date().toISOString().slice(0, 10))
const settleRecomputing = ref(false)
const settleFinalizing = ref(false)
async function openSettle() {
  if (!sel.value || !selLeases.value.length) { window.__krToast?.('No lease for this tenant.', 'error'); return }
  const lease = selLeases.value.find(l => ['Active', 'Pending Registration'].includes(String(l.status).toLowerCase())) || selLeases.value[0]
  settleLoading.value = true
  try {
    const r = await apiCall('app-moveout', { lease: lease.id, action: 'prepare' })
    if (r.ok) {
      settleModal.value = r
      settleDeducts.value = (r.settlement?.sections?.damages || []).map(d => ({ label: d.label, amount: d.amount }))
      settleApplyDeposit.value = true
      settleDate.value = new Date().toISOString().slice(0, 10)
    } else window.__krToast?.(r.error || 'Failed to load settlement', 'error')
  } finally { settleLoading.value = false }
}
function addDeduct() { settleDeducts.value.push({ label: '', amount: 0 }) }
function rmDeduct(i) { settleDeducts.value.splice(i, 1) }
async function recomputeSettle() {
  if (!settleModal.value) return
  settleRecomputing.value = true
  try {
    const r = await apiCall('app-moveout', { lease: settleModal.value.lease?.id, action: 'prepare', deductions: settleDeducts.value.filter(d => d.label && d.amount > 0), apply_deposit: settleApplyDeposit.value })
    if (r.ok) settleModal.value = r
    else window.__krToast?.(r.error || 'Recompute failed', 'error')
  } finally { settleRecomputing.value = false }
}
async function finalizeSettle() {
  if (!settleModal.value) return
  const s = settleModal.value.settlement
  const bal = s?.status === 'DUE' ? s?.totals?.balance : 0
  if (!confirm(`Finalize move-out for ${settleModal.value.tenant?.name} (${settleModal.value.lease?.id}) on ${settleDate.value}?\n\nBalance payable: ৳${(bal || 0).toLocaleString('en-IN')}\n\nThe lease will be closed, the settlement report recorded, and the move-out handover marked complete. This cannot be undone.`)) return
  settleFinalizing.value = true
  try {
    const r = await apiCall('app-moveout', { lease: settleModal.value.lease?.id, action: 'close', move_out_date: settleDate.value, deductions: settleDeducts.value.filter(d => d.label && d.amount > 0), apply_deposit: settleApplyDeposit.value })
    if (r.ok) {
      window.__krToast?.(`🚪 Move-out finalized — ${r.settlement?.id || 'SET-??'} (${r.settlement?.status || ''})`, 'ok')
      settleModal.value = null
      await data.bootstrap(); reResolveSel()
    } else window.__krToast?.(r.error || 'Move-out failed', 'error')
  } finally { settleFinalizing.value = false }
}

// ── chat ──
const chatMsgs = ref([])
const chatText = ref('')
const chatLoading = ref(false)
const chatSending = ref(false)
const chatBox = ref(null)
async function loadChat() {
  if (!sel.value) return
  chatLoading.value = true
  try {
    const r = await apiCall('app-tenant-chat', { tenant_id: sel.value.id })
    if (r.ok) chatMsgs.value = r.messages || []
  } finally { chatLoading.value = false; scrollChat() }
}
async function sendChat() {
  const t = chatText.value.trim()
  if (!t || !sel.value) return
  chatSending.value = true
  try {
    const r = await apiCall('app-tenant-chat-send', { tenant_id: sel.value.id, body: t })
    if (r.ok) { chatText.value = ''; await loadChat() }
    else window.__krToast?.(r.error || 'Send failed', 'error')
  } finally { chatSending.value = false }
}
function scrollChat() { requestAnimationFrame(() => { if (chatBox.value) chatBox.value.scrollTop = chatBox.value.scrollHeight }) }

// ── documents ──
const docUploading = ref(false)
const docCat = ref('other')                       // selected document type for uploads
const DOC_TYPES = [
  { id: 'other', label: '📁 Other' },
  { id: 'agreement', label: '📄 Agreement & lease papers' },
  { id: 'utility', label: '⚡ Utility papers & bills' },
  { id: 'legal', label: '⚖️ Legal documents' },
  { id: 'tax', label: '🧾 Tax & khajna' },
  { id: 'community', label: '🏘 Community / society' },
]
const docTypeLabel = (id) => (DOC_TYPES.find(t => t.id === id) || {}).label || id || '—'
async function onDocPick(e) {
  const f = e.target.files && e.target.files[0]
  e.target.value = ''
  if (!f || !sel.value) return
  const fd = new FormData()
  fd.append('file', f)
  fd.append('kind', 'tenant')
  fd.append('ref', sel.value.id)
  fd.append('cat', docCat.value || 'other')
  docUploading.value = true
  try {
    const r = await apiUpload('app-doc-upload', fd)
    if (r.ok) { window.__krToast?.('📎 Document uploaded (' + docTypeLabel(docCat.value) + ')', 'ok'); await data.bootstrap(); reResolveSel() }
    else window.__krToast?.(r.error || 'Upload failed', 'error')
  } finally { docUploading.value = false }
}
// Attach a scanned lease paper to the LEASE (kind='lease' → shows in the agreement viewer).
const agreeUploading = ref(false)
async function attachAgreement(e) {
  const f = e.target.files && e.target.files[0]
  e.target.value = ''
  if (!f || !agreeModal.value) return
  const fd = new FormData()
  fd.append('file', f)
  fd.append('kind', 'lease')
  fd.append('ref', agreeModal.value.id)
  fd.append('cat', 'agreement')
  agreeUploading.value = true
  try {
    const r = await apiUpload('app-doc-upload', fd)
    if (r.ok) { window.__krToast?.('📎 Agreement attached to ' + agreeModal.value.id, 'ok'); await data.bootstrap() }
    else window.__krToast?.(r.error || 'Upload failed', 'error')
  } finally { agreeUploading.value = false }
}
async function delDoc(d) {
  if (!confirm(`Delete document "${d.name}"?`)) return
  const r = await apiCall('app-doc-delete', { id: d.id })
  if (r.ok) { window.__krToast?.('🗑️ Document deleted', 'ok'); await data.bootstrap(); reResolveSel() }
  else window.__krToast?.(r.error || 'Delete failed', 'error')
}
// ── document type filter + re-categorize ──
const docFilter = ref('all')
const filteredDocs = computed(() => docFilter.value === 'all' ? selDocs.value : selDocs.value.filter(d => d.cat === docFilter.value))
const docCatBusy = ref('')
async function setDocCat(d, cat) {
  if (!d || d.cat === cat) return
  docCatBusy.value = d.id
  try {
    const r = await apiCall('app-doc-cat', { id: d.id, cat })
    if (r.ok) { window.__krToast?.('🏷️ ' + d.id + ' → ' + docTypeLabel(cat), 'ok'); await data.bootstrap(); reResolveSel() }
    else window.__krToast?.(r.error || 'Could not re-categorize', 'error')
  } finally { docCatBusy.value = '' }
}

// ── partial payment modal ──
const payModal = ref(null)
const paySaving = ref(false)
const PAY_METHODS = ['Manual', 'Cash', 'Bank Transfer', 'Cheque', 'bKash', 'Nagad', 'Rocket', 'Card']
function openPay(i) {
  const rem = invRemaining(i)
  payModal.value = { inv: i, amount: Math.max(0, rem), date: today(), method: 'Manual', sig: '' }
}
async function submitPay() {
  const m = payModal.value
  if (!m || !m.amount || m.amount <= 0) { window.__krToast?.('Enter a positive amount', 'error'); return }
  paySaving.value = true
  try {
    const r = await apiCall('app-invoice-pay', { invoice_id: m.inv.id, amount: Math.round(m.amount), date: m.date, method: m.method, sig: m.sig })
    if (r.ok) {
      window.__krToast?.(`💳 ${m.inv.id} → ${r.status} (paid ৳${(r.paid || 0).toLocaleString('en-IN')})`, 'ok')
      payModal.value = null
      await data.bootstrap(); reResolveSel()
    } else window.__krToast?.(r.error || 'Payment failed', 'error')
  } finally { paySaving.value = false }
}

// ── drawer lifecycle ──
watch(sel, () => {
  selPhoto.value = ''; noteText.value = ''; noteLoaded.value = false
  scoreData.value = null; scoreLoaded.value = false
  chatMsgs.value = []; chatText.value = ''
  hovoSel.value = null; hovoList.value = []
  family.value = []; company.value = {}
  if (sel.value) { loadPhoto(); loadNote(); loadScore(); loadChat(); loadHovo(); loadFamilyCompany() }
})
watch(tab, (t) => {
  if (t === 'profile' && sel.value) { if (!noteLoaded.value) loadNote(); if (!scoreLoaded.value) loadScore() }
  if (t === 'chat' && sel.value) loadChat()
  if (t === 'hando' && sel.value) loadHovo()
})
let chatTimer = null
watch(tab, (t) => {
  if (t === 'chat') { if (!chatTimer) chatTimer = setInterval(() => { if (sel.value && tab.value === 'chat') loadChat() }, 8000) }
  else if (chatTimer) { clearInterval(chatTimer); chatTimer = null }
})
onUnmounted(() => { if (chatTimer) clearInterval(chatTimer) })

// ── CRUD ──
const modal = ref(null)
const form = ref({})
const saving = ref(false)
const formErr = ref('')
const provisionInfo = ref(null)   // V2.39: temp portal credentials after creating a tenant

// ── CSV import (GO-LIVE 4.1) ──
const showImport = ref(false)
function openImport() { showImport.value = true }
// GO-LIVE 4.1: /tenants?import=1 opens the CSV import wizard directly (empty-state CTA)
watch(() => route.query.import, (v) => {
  if (String(v) === '1') showImport.value = true
}, { immediate: true })
function refreshAll() { data.bootstrap() }
function openAdd() {
  form.value = { name: '', phone: '', email: '', nid: '', nrb: false, kind: 'Individual', sub_email: '' }
  formErr.value = ''; modal.value = { mode: 'add' }
}
function openEdit(t) {
  form.value = { name: t.name || '', phone: t.phone || '', email: t.email || '', nid: t.nid || '', nrb: String(t.nrb) === '1', kind: t.kind || 'Individual', sub_email: t.sub_email || '' }
  formErr.value = ''; modal.value = { mode: 'edit', t }
}
function closeModal() { modal.value = null; formErr.value = '' }
async function submitForm() {
  if (!form.value.name.trim()) { formErr.value = 'Tenant name is required.'; return }
  formErr.value = ''; saving.value = true
  try {
    const payload = { name: form.value.name.trim(), phone: form.value.phone.trim(), email: form.value.email.trim(), nid: form.value.nid.trim(), nrb: form.value.nrb ? 1 : 0, kind: form.value.kind, sub_email: (form.value.sub_email || '').trim() }
    const r = await apiCall('app-crud', {
      action: modal.value.mode === 'edit' ? 'update' : 'create', collection: 'tenants',
      ...(modal.value.mode === 'edit' ? { id: modal.value.t.id } : {}), data: payload,
    })
    if (r.ok) {
      window.__krToast?.(modal.value.mode === 'edit' ? `✏️ ${modal.value.t.id} updated` : '✅ Tenant created', 'ok')
      if (modal.value.mode !== 'edit' && r.provision?.provisioned) {
        provisionInfo.value = { name: form.value.name.trim(), ...r.provision }
      } else if (r.provision?.provisioned) {
        window.__krToast?.('🔑 Portal account created — welcome email sent', 'ok')
      }
      closeModal(); await data.bootstrap()
    } else formErr.value = r.error || 'Save failed.'
  } finally { saving.value = false }
}
async function delTenant(t) {
  if (!confirm(`Delete ${t.name} (${t.id})? This cannot be undone.`)) return
  const r = await apiCall('app-crud', { action: 'delete', collection: 'tenants', id: t.id, data: {} })
  if (r.ok) { window.__krToast?.(`🗑️ ${t.id} deleted`, 'ok'); if (sel.value?.id === t.id) closeDetail(); await data.bootstrap() }
  else window.__krToast?.(r.error || 'Delete failed', 'error')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>👥 {{ lang === 'bn' ? 'ভাড়াটিয়া' : 'Tenants' }}</h1>
        <div class="sub">{{ tenantsAll.length }} tenants · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" :placeholder="t('Search name, email, unit…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="kindFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All kinds') }}</option>
          <option v-for="k in kindOptions" :key="k" :value="k">{{ k }}</option>
        </select>
        <select v-model="propFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All properties') }}</option>
          <option v-for="p in propOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <select v-model="sortBy" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="name">{{ t('Sort: Name') }}</option>
          <option value="rent">{{ t('Sort: Rent') }}</option>
          <option value="outstanding">{{ t('Sort: Outstanding') }}</option>
        </select>
        <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;cursor:pointer;padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt)">
          <input type="checkbox" v-model="nrbOnly" style="accent-color:var(--primary)"> 🌍 NRB only
        </label>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" :title="t('Download CSV')">⬇ CSV</button>
      </CompactFilters>
        <button v-if="canManage" @click="openAdd" class="btn-primary" style="padding:9px 16px" :title="t('Register a new tenant')">＋ New tenant</button>
        <button v-if="canManage" @click="openImport" class="btn-ghost" style="padding:9px 16px;font-weight:700" :title="t('Bulk import tenants from CSV')">⬆ Import</button>
      </div>
    </div>

    <div class="stats">
      <div v-for="k in kpis" :key="k.label" class="stat">
        <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
        <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
        <div class="s-trend">{{ k.trend }}</div>
      </div>
    </div>

    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:16px">
      <div v-for="t in paged" :key="t.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(t)">
        <div class="t-cover" :style="`height:82px;position:relative;background:linear-gradient(135deg,${avatarColor(t.id)},#1E5EB8)`">
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge">{{ t.kind }}</span>
            <span v-if="String(t.nrb) === '1'" class="badge b-blue">🌍 NRB</span>
            <span v-if="trustTier(t)" class="badge" :style="tierStyle(trustTier(t))">{{ tierLabel(trustTier(t)) }}</span>
            <span v-if="nidVerifiedOf(t)" class="badge b-green">🪪 {{ t('NID') }} ✓</span>
            <span v-if="thanaVerifiedOf(t)" class="badge b-green">🏛 Thana ✓</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ t.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="display:flex;gap:11px;align-items:center">
            <div :style="`width:40px;height:40px;border-radius:50%;background:${avatarColor(t.id)};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;flex-shrink:0`">{{ initials(t.name) }}</div>
            <div style="min-width:0">
              <div style="font-weight:800;font-size:15px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ t.name }}</div>
              <div class="c-sub" style="margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ t.email || t.phone || '—' }}</div>
            </div>
          </div>
          <div v-if="unitsOfTenant(t).length" style="font-size:12px" class="c-sub">
            🚪 {{ unitsOfTenant(t).map(u => unitName(u.id)).join(', ') }} · 🏢 {{ propName(unitsOfTenant(t)[0].p) }}
          </div>
          <div v-if="activeLeaseOf(t)" style="font-size:12px;display:flex;justify-content:space-between;align-items:center;background:var(--bg-alt);border:1px solid var(--border);border-radius:9px;padding:7px 10px">
            <span>💵 <b>{{ money(monthlyRent(t)) }}/mo</b></span>
            <span class="c-sub">{{ activeLeaseOf(t).end ? 'until ' + activeLeaseOf(t).end + (activeLeaseOf(t).status === 'Active' ? ` (${leaseDaysLeft(activeLeaseOf(t))}d)` : '') : '' }}</span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
            <span v-if="outstanding(t) > 0" class="badge b-red">⏳ {{ money(outstanding(t)) }} due</span>
            <span v-if="collectionRateT(t) !== null && outstanding(t) === 0" class="badge b-green">✅ {{ t('Paid') }} up</span>
            <span v-if="ticketsOfTenant(t).some(x => String(x.status).toLowerCase() === 'open')" class="badge b-orange">🔧 open ticket</span>
          </div>
          <div v-if="canManage" style="display:flex;gap:6px;border-top:1px solid var(--border);padding-top:9px">
            <button class="btn-ghost" style="flex:1;justify-content:center;padding:6px 10px;font-size:12px" @click.stop="openEdit(t)">✏️ Edit</button>
            <button class="btn-ghost" style="padding:6px 10px;font-size:12px;color:var(--danger)" @click.stop="delTenant(t)">🗑️</button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>{{ t('Tenant') }}</th><th>{{ t('Kind') }}</th><th>{{ t('Unit / Property') }}</th><th>{{ t('Rent / mo') }}</th><th>{{ t('Lease end') }}</th><th>{{ t('Outstanding') }}</th><th>{{ t('Collection') }}</th><th>{{ t('Tickets') }}</th><th v-if="canManage">{{ t('Actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="t in paged" :key="t.id" style="cursor:pointer" @click="openDetail(t)">
              <td style="white-space:nowrap">
                <div style="display:flex;align-items:center;gap:9px">
                  <div :style="`width:30px;height:30px;border-radius:50%;background:${avatarColor(t.id)};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;flex-shrink:0`">{{ initials(t.name) }}</div>
                  <div style="min-width:0">
                    <div style="font-weight:800;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:190px">{{ t.name }}</div>
                    <div class="c-sub" style="font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:190px">{{ t.email || t.phone || '—' }}</div>
                  </div>
                </div>
              </td>
              <td style="white-space:nowrap">
                <span class="badge">{{ t.kind }}</span>
                <span v-if="String(t.nrb) === '1'" class="badge b-blue">🌍 NRB</span>
              </td>
              <td style="white-space:nowrap" class="c-sub">{{ unitsOfTenant(t).map(u => unitName(u.id)).join(', ') || '—' }}<template v-if="unitsOfTenant(t).length"> · {{ propName(unitsOfTenant(t)[0].p) }}</template></td>
              <td style="font-weight:700;white-space:nowrap">{{ money(monthlyRent(t)) }}</td>
              <td style="white-space:nowrap">{{ activeLeaseOf(t)?.end || '—' }}<template v-if="activeLeaseOf(t)?.status === 'Active' && activeLeaseOf(t)?.end"> ({{ leaseDaysLeft(activeLeaseOf(t)) }}d)</template></td>
              <td :style="outstanding(t) > 0 ? 'color:var(--danger);font-weight:800;white-space:nowrap' : 'color:var(--ok);font-weight:800;white-space:nowrap'">{{ outstanding(t) > 0 ? money(outstanding(t)) : '✅ Paid' }}</td>
              <td style="white-space:nowrap">{{ collectionRateT(t) !== null ? collectionRateT(t) + '%' : '—' }}</td>
              <td style="white-space:nowrap">{{ ticketsOfTenant(t).length ? '🔧 ' + ticketsOfTenant(t).length : '—' }}</td>
              <td v-if="canManage" style="white-space:nowrap">
                <button class="btn-ghost" style="padding:4px 9px;font-size:11px" @click.stop="openEdit(t)">✏️</button>
                <button class="btn-ghost" style="padding:4px 9px;font-size:11px;color:var(--danger)" @click.stop="delTenant(t)">🗑️</button>
              </td>
            </tr>
            <tr v-if="!filtered.length"><td :colspan="canManage ? 9 : 8" style="text-align:center;color:var(--text-mute);padding:30px">No tenants found{{ query ? ' for “' + query + '”' : '' }}.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No tenants found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(720px,96vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" :style="`height:118px;position:relative;flex-shrink:0;background:linear-gradient(135deg,${avatarColor(sel.id)},#1E5EB8)`">
          <div style="position:absolute;left:20px;bottom:16px;display:flex;align-items:center;gap:14px">
            <div style="position:relative">
              <img v-if="selPhoto" :src="selPhoto" alt="" style="width:58px;height:58px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.6);background:#fff">
              <div v-else :style="`width:58px;height:58px;border-radius:50%;background:rgba(255,255,255,.22);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:21px;border:2px solid rgba(255,255,255,.5)`">{{ initials(sel.name) }}</div>
              <label v-if="canManage" :title="t('Upload / change photo')" style="position:absolute;right:-4px;bottom:-4px;width:24px;height:24px;border-radius:50%;background:#fff;color:#1E5EB8;display:flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.25);border:1px solid #dbe3f0">
                <span v-if="!uploadingPhoto">📷</span><span v-else style="font-size:10px">…</span>
                <input type="file" accept="image/*" style="display:none" @change="onPhotoPick">
              </label>
            </div>
            <div>
              <div style="color:#fff;font-size:19px;font-weight:800;text-shadow:0 1px 4px rgba(0,0,0,.35)">{{ sel.name }}</div>
              <div style="display:flex;gap:6px;margin-top:5px;flex-wrap:wrap">
                <span class="badge">{{ sel.kind }}</span>
                <span v-if="String(sel.nrb) === '1'" class="badge b-blue">🌍 NRB</span>
                <span v-if="nidVerifiedOf(sel)" class="badge b-green">🪪 {{ t('NID') }} Verified</span>
                <span v-if="thanaVerifiedOf(sel)" class="badge b-green">🏛 Thana Verified</span>
                <span v-if="!nidVerifiedOf(sel) && !thanaVerifiedOf(sel)" class="badge b-gray">{{ t('Verification pending') }}</span>
              </div>
              <div style="margin-top:5px;display:flex;gap:8px;flex-wrap:wrap">
                <a v-if="sel.phone" :href="'tel:' + sel.phone.replace(/[^+\d]/g, '')" style="color:#fff;font-size:12px;font-weight:800;text-decoration:none;background:rgba(255,255,255,.22);padding:4px 10px;border-radius:20px;border:1px solid rgba(255,255,255,.35)">📞 {{ sel.phone }}</a>
                <a v-if="sel.email" :href="'mailto:' + sel.email" style="color:#fff;font-size:12px;font-weight:800;text-decoration:none;background:rgba(255,255,255,.18);padding:4px 10px;border-radius:20px;border:1px solid rgba(255,255,255,.3)">✉️ {{ sel.email }}</a>
              </div>
            </div>
          </div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <div class="c-sub" style="margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ selSubtitle }}</div>

          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
            <div v-for="s in selStats" :key="s.label" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ s.label }}</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ s.v }}</div>
            </div>
          </div>

          <div style="display:flex;align-items:center;gap:4px;border-bottom:1px solid var(--border);margin-bottom:14px">
            <button class="tab-arrow" @click="scrollTabs(-1)" :title="t('Scroll tabs')">◀</button>
            <div ref="tabScroll" class="tab-scroll" style="display:flex;gap:6px;overflow-x:auto;flex:1">
              <button v-for="t in TABS" :key="t.id" @click="tab = t.id"
                style="padding:9px 14px;border:none;background:none;font-size:13px;font-weight:700;cursor:pointer;border-bottom:2px solid transparent;color:var(--text-mute);white-space:nowrap;flex-shrink:0"
                :style="tab === t.id ? 'color:var(--primary);border-bottom-color:var(--primary)' : ''">
                {{ t.ico }} {{ t.label }} <span style="opacity:.7">({{ tabCount(t.id) }})</span>
              </button>
            </div>
            <button class="tab-arrow" @click="scrollTabs(1)" :title="t('Scroll tabs')">▶</button>
          </div>

          <!-- PROFILE -->
          <template v-if="tab === 'profile'">
            <!-- owner actions -->
            <div v-if="canManage" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
              <button class="btn-ghost" style="padding:8px 14px;font-size:12.5px" @click="openEdit(sel)">✏️ Edit profile</button>
              <button class="btn-ghost" style="padding:8px 14px;font-size:12.5px" @click="openNotice">📢 Send notice</button>
              <button class="btn-ghost" style="padding:8px 14px;font-size:12.5px" :disabled="settleLoading" @click="openSettle">{{ settleLoading ? 'Loading…' : '🧾 Settlement' }}</button>
              <button class="btn-primary" style="padding:8px 14px;font-size:12.5px" :disabled="remindSending" @click="sendReminder">{{ remindSending ? 'Sending…' : '🔔 Payment reminder' }}</button>
              <button class="btn-ghost" style="padding:8px 14px;font-size:12.5px" :disabled="gdprBusy" @click="exportGdpr" title="Download this tenant's personal data (GDPR export)">{{ gdprBusy ? 'Exporting…' : '📦 Data export' }}</button>
            </div>

            <!-- Tenant score -->
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px">
              <div style="display:flex;align-items:center;gap:14px;background:var(--bg-alt);border:1px solid var(--border);border-radius:14px;padding:16px 18px;flex:1;min-width:250px">
                <div :style="`width:74px;height:74px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;flex-direction:column;background:conic-gradient(${scoreData?.band_color || '#2F80ED'} ${scorePct}, rgba(127,146,178,.15) 0);position:relative`">
                  <div style="position:absolute;inset:7px;border-radius:50%;background:var(--card);display:flex;align-items:center;justify-content:center;flex-direction:column">
                    <div style="font-size:19px;font-weight:900;line-height:1">{{ scoreData?.score ?? '–' }}</div>
                    <div style="font-size:8.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.4px">score</div>
                  </div>
                </div>
                <div style="min-width:0;flex:1">
                  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                    <div style="font-weight:800;font-size:15px">{{ t('Tenant') }} score — <span :style="`color:${scoreData?.band_color || 'var(--text)'}`">{{ scoreData?.band || '…' }}</span><span v-if="trustTier(sel)" class="badge" :style="tierStyle(trustTier(sel))" style="margin-left:8px;font-size:10.5px">{{ tierLabel(trustTier(sel)) }}</span></div>
                    <button v-if="scoreData && !scoreLoading" class="btn-ghost" style="padding:6px 12px;font-size:11.5px" @click="scoreModal = true">📊 Key indicators</button>
                  </div>
                  <div v-if="scoreLoading" class="c-sub" style="margin-top:4px">Calculating…</div>
                  <template v-else-if="scoreData">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 16px;margin-top:8px">
                      <div v-for="(v, k) in scoreData.factors" :key="k" style="display:flex;align-items:center;gap:6px;font-size:11px">
                        <span style="text-transform:capitalize;font-weight:700;color:var(--text-mute);min-width:62px">{{ k }}</span>
                        <div style="flex:1;height:5px;border-radius:3px;background:rgba(127,146,178,.18);overflow:hidden"><div :style="`width:${v}%;height:100%;background:${scoreData.band_color}`"></div></div>
                        <b style="font-size:10.5px">{{ v }}</b>
                      </div>
                    </div>
                    <div v-for="tip in scoreData.tips" :key="tip" style="font-size:11px;color:var(--text-mute);margin-top:4px">💡 {{ tip }}</div>
                  </template>
                </div>
              </div>
            </div>

            <!-- Verification -->
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
              <div style="flex:1;min-width:220px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 15px">
                <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">🪪 {{ t('NID / BIN') }} verification</div>
                <div style="margin-top:7px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <span class="badge" :class="nidVerifiedOf(sel) ? 'b-green' : 'b-gray'">{{ nidVerifiedOf(sel) ? '✓ Verified' : 'Not verified' }}</span>
                  <span class="c-sub" style="font-size:11.5px">{{ t('NID') }} {{ maskNid(sel.nid) }}</span>
                </div>
              </div>
              <div style="flex:1;min-width:220px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 15px">
                <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">🏛 Thana verification</div>
                <div style="margin-top:7px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <span class="badge" :class="thanaVerifiedOf(sel) ? 'b-green' : 'b-gray'">{{ thanaVerifiedOf(sel) ? '✓ Verified' : 'Not verified' }}</span>
                  <span v-if="thanaForms.filter(f => f.tenant === sel.id).length" class="c-sub" style="font-size:11.5px">{{ thanaForms.filter(f => f.tenant === sel.id).slice(-1)[0].status }} form</span>
                </div>
              </div>
            </div>

            <!-- Family info (individual) -->
            <div v-if="String(sel.kind).toLowerCase() === 'individual'" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 15px;margin-bottom:12px">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
                <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">👨‍👩‍👧 Family information</div>
                <button v-if="canManage" class="btn-primary" style="padding:6px 14px;font-size:12px" @click="openFamAdd" :title="t('Add a family member to this tenant')">＋ Add member</button>
              </div>
              <div v-if="!family.length" class="c-sub" style="font-size:12px;padding:6px 0 10px">{{ t('No family members recorded yet.') }}</div>
              <div style="display:flex;flex-direction:column;gap:8px">
                <div v-for="(m, i) in family" :key="i" style="display:flex;align-items:center;gap:11px;background:var(--card);border:1px solid var(--border);border-radius:11px;padding:10px 13px">
                  <div style="width:38px;height:38px;border-radius:50%;background:var(--grad);color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;flex-shrink:0">{{ (m.name || '?').trim()[0]?.toUpperCase() || '?' }}</div>
                  <div style="flex:1;min-width:0">
                    <div style="font-weight:800;font-size:13px">{{ m.name }} <span class="c-sub" style="font-weight:500">· {{ m.relation || '—' }}</span></div>
                    <div class="c-sub" style="font-size:11px;margin-top:2px">
                      {{ [m.phone ? '📞 ' + m.phone : '', m.dob ? '🎂 ' + m.dob : '', m.nid ? '🪪 ' + m.nid : '', m.occupation || ''].filter(Boolean).join(' · ') || 'No details' }}
                    </div>
                  </div>
                  <div v-if="canManage" style="display:flex;gap:6px">
                    <button class="btn-ghost" style="padding:5px 10px;font-size:11.5px" @click="openFamEdit(i)">✏️</button>
                    <button class="btn-ghost" style="padding:5px 10px;font-size:11.5px;color:var(--danger)" @click="removeFamilyRow(i)">🗑️</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Company profile (corporate) -->
            <div v-else-if="String(sel.kind).toLowerCase() === 'corporate'" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 15px;margin-bottom:12px">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">🏢 Company profile</div>
                <button v-if="canManage" class="btn-primary" style="padding:6px 14px;font-size:12px" :disabled="coSaving" @click="saveCompany">{{ coSaving ? 'Saving…' : 'Save' }}</button>
              </div>
              <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px">
                <div v-for="f in CO_FIELDS" :key="f.k" :style="f.w === 'full' ? 'grid-column:1/-1' : ''">
                  <label style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ f.label }}</label>
                  <input v-model="company[f.k]" :placeholder="f.ph" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
                </div>
              </div>
            </div>

            <!-- Private note (owner only) -->
            <div v-if="canNote" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 15px;margin-bottom:6px">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">🔒 Private note (owner only)</div>
                <button class="btn-primary" :disabled="noteSaving || !noteLoaded" @click="saveNote" style="padding:6px 14px;font-size:12px">{{ noteSaving ? 'Saving…' : 'Save' }}</button>
              </div>
              <textarea v-model="noteText" rows="3" placeholder="Internal note — visible only to the owner / superadmin…" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;background:var(--card);font-family:inherit;font-size:13px;color:var(--text);outline:none;resize:vertical"></textarea>
              <div v-if="!noteLoaded" class="c-sub" style="font-size:11px;margin-top:5px">Loading…</div>
            </div>
          </template>

          <!-- LEASE & UNIT (merged) -->
          <div v-else-if="tab === 'leases'">
            <div class="drawer-tbl-wrap">
              <table class="kr" style="width:100%">
              <thead><tr><th>{{ t('Lease') }}</th><th>{{ t('Unit') }}</th><th>{{ t('Property') }}</th><th>{{ t('Floor') }}</th><th>sqft</th><th>{{ t('Rent') }}</th><th>{{ t('Start') }}</th><th>{{ t('End') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
              <tbody>
                <tr v-for="l in selLeases" :key="l.id">
                  <td style="font-weight:700"><a @click.stop="go('/leases', { open: l.id })" style="color:var(--primary);cursor:pointer;text-decoration:none;font-weight:800">{{ l.id }}</a> <span class="c-sub" style="font-size:10.5px">↗</span></td>
                  <td><a @click.stop="go('/units', { open: l.u })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ unitName(l.u) }}</a></td>
                  <td>{{ propName(unitsAll.find(u => u.id === l.u)?.p) }}</td>
                  <td>{{ unitsAll.find(u => u.id === l.u)?.floor || '—' }}</td>
                  <td>{{ ((unitsAll.find(u => u.id === l.u)?.sqft) || 0).toLocaleString('en-IN') }}</td>
                  <td style="font-weight:700">{{ money(l.rent) }}/mo</td>
                  <td>{{ l.start || '—' }}</td>
                  <td>{{ l.end || '—' }} <span v-if="leaseDaysLeft(l) !== null && l.status === 'Active'" class="c-sub">({{ leaseDaysLeft(l) }}d)</span></td>
                  <td><span class="badge" :class="badge(l.status)">{{ l.status }}</span></td>
                  <td><button class="btn-ghost" style="padding:5px 9px;font-size:11.5px" @click.stop="openAgree(l)" :title="t('Lease paper / agreement')">📄</button></td>
                </tr>
                <tr v-if="!selLeases.length"><td colspan="10" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No leases / units.') }}</td></tr>
              </tbody>
            </table>
            </div>

            <!-- lease extension -->
            <div v-if="canManage" style="margin-top:22px">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px">
                <div style="font-size:12px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">📄 {{ t('Lease') }} extension / renewal</div>
                <button class="btn-primary" style="padding:6px 12px;font-size:12px" @click="openOffer" :title="t('Offer a lease extension to this tenant')">＋ Extension offer</button>
              </div>
              <div v-if="!selRenewals.length" class="c-sub" style="font-size:12px;padding:4px 0 8px">{{ t('No renewal requests. Tenants can request extension from their portal, or you can send an offer above.') }}</div>
              <div v-for="r in selRenewals" :key="r.id" style="display:flex;align-items:center;gap:10px;background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:10px 13px;margin-bottom:8px;flex-wrap:wrap">
                <div style="font-weight:800;font-size:13px">{{ r.id }}</div>
                <div class="c-sub" style="font-size:12px">lease {{ r.lease }} · +{{ r.months }} mo · ৳{{ (r.new_rent || 0).toLocaleString('en-IN') }}/mo</div>
                <span class="badge" :class="badge(r.status)">{{ r.status }}</span>
                <div v-if="r.note" class="c-sub" style="font-size:11.5px;flex-basis:100%">💬 {{ r.note }}</div>
                <div v-if="['Pending', 'Offered'].includes(r.status)" style="margin-left:auto;display:flex;gap:6px">
                  <button class="btn-primary" style="padding:5px 12px;font-size:11.5px" @click="decideRenewal(r, 'approve')">✅ Approve</button>
                  <button class="btn-ghost" style="padding:5px 12px;font-size:11.5px;color:var(--danger)" @click="decideRenewal(r, 'reject')">❌ Reject</button>
                </div>
              </div>
            </div>
          </div>

          <!-- BILLING (invoices + utilities merged) -->
          <div v-else-if="tab === 'billing'">
            <div style="display:flex;justify-content:space-between;align-items:center;margin:4px 0 8px">
              <div style="font-size:12px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">🧾 Invoices</div>
              <div class="c-sub" style="font-size:11.5px">{{ selInvoices.length }} · outstanding {{ money(selInvoices.filter(i => invStatus(i) !== 'Paid').reduce((s, i) => s + invRemaining(i), 0)) }}</div>
            </div>
            <div class="drawer-tbl-wrap">
              <table class="kr" style="width:100%">
              <thead><tr><th>{{ t('Invoice') }}</th><th>{{ t('Month') }}</th><th>{{ t('Lease') }}</th><th>{{ t('Net') }}</th><th>{{ t('Paid') }}</th><th>{{ t('Remaining') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
              <tbody>
                <tr v-for="i in selInvoices" :key="i.id">
                  <td style="font-weight:700"><a @click.stop="go('/invoices', { open: i.id })" style="color:var(--primary);cursor:pointer;text-decoration:none;font-weight:800">{{ i.id }}</a> <span class="c-sub" style="font-size:10.5px">↗</span></td>
                  <td>{{ i.m || '—' }}</td>
                  <td><a @click.stop="go('/leases', { open: i.l })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ i.l }}</a></td>
                  <td style="font-weight:700">{{ money(i.net) }}</td>
                  <td>{{ paidOfInvoice(i) > 0 ? money(paidOfInvoice(i)) : '—' }}</td>
                  <td :style="invRemaining(i) > 0 ? 'color:var(--danger);font-weight:800' : ''">{{ invRemaining(i) > 0 ? money(invRemaining(i)) : '—' }}</td>
                  <td><span class="badge" :class="badge(invStatus(i))">{{ invStatus(i) }}</span></td>
                  <td><button v-if="canPay && invRemaining(i) > 0" @click.stop="openPay(i)" class="btn-primary" style="padding:5px 11px;font-size:11.5px">💳 Pay</button></td>
                </tr>
                <tr v-if="!selInvoices.length"><td colspan="8" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No invoices.') }}</td></tr>
              </tbody>
            </table>
            </div>

            <div style="font-size:12px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin:20px 0 8px">🔌 Utility bills</div>
            <div class="drawer-tbl-wrap">
              <table class="kr" style="width:100%">
              <thead><tr><th>{{ t('Bill') }}</th><th>{{ t('Unit') }}</th><th>{{ t('Type') }}</th><th>{{ t('Month') }}</th><th>{{ t('Usage') }}</th><th>{{ t('Amount') }}</th><th>{{ t('Status') }}</th></tr></thead>
              <tbody>
                <tr v-for="b in selUtils" :key="b.id">
                  <td style="font-weight:700">{{ b.id }}</td><td>{{ unitName(b.unit) }}</td><td>{{ b.type }}</td><td>{{ b.month || '—' }}</td><td>{{ b.usage ?? '—' }}</td><td style="font-weight:700">{{ money(b.amount) }}</td>
                  <td><span class="badge" :class="badge(b.status)">{{ b.status }}</span></td>
                </tr>
                <tr v-if="!selUtils.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:18px">{{ t('No utility bills.') }}</td></tr>
              </tbody>
            </table>
            </div>

            <!-- postpaid meter billing -->
            <div v-if="canManage" style="margin-top:20px">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px">
                <div style="font-size:12px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">⚡ Postpaid meter billing</div>
                <div style="display:flex;gap:8px">
                  <button class="btn-ghost" style="padding:6px 12px;font-size:12px" @click="openMeter" :title="t('Log a new utility meter reading')">＋ Meter reading</button>
                  <button class="btn-primary" style="padding:6px 12px;font-size:12px" @click="openBill">🧾 Generate bill</button>
                </div>
              </div>
              <div class="drawer-tbl-wrap">
                <table class="kr" style="width:100%">
                <thead><tr><th>{{ t('Reading') }}</th><th>{{ t('Unit') }}</th><th>{{ t('Type') }}</th><th>{{ t('Month') }}</th><th>{{ t('Reading') }}</th><th>{{ t('Note') }}</th></tr></thead>
                <tbody>
                  <tr v-for="m in selMeters" :key="m.id">
                    <td style="font-weight:700">{{ m.id }}</td><td>{{ unitName(m.unit) }}</td><td>{{ m.type }}</td><td>{{ m.month }}</td><td style="font-weight:700">{{ m.reading }}</td><td class="c-sub">{{ m.note || '—' }}</td>
                  </tr>
                  <tr v-if="!selMeters.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:18px">{{ t('No meter readings yet — submit readings, then generate postpaid bills.') }}</td></tr>
                </tbody>
              </table>
              </div>
            </div>
          </div>

          <!-- TICKETS & MAINTENANCE (merged) -->
          <div v-else-if="tab === 'tickets'">
            <div style="font-size:12px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin:4px 0 8px">🔧 Maintenance tickets</div>
            <div class="drawer-tbl-wrap">
              <table class="kr" style="width:100%">
              <thead><tr><th>{{ t('Ticket') }}</th><th>{{ t('Unit') }}</th><th>{{ t('Issue') }}</th><th>{{ t('Reported') }}</th><th>{{ t('Liability') }}</th><th>{{ t('Status') }}</th></tr></thead>
              <tbody>
                <tr v-for="x in selTickets" :key="x.id">
                  <td style="font-weight:700"><a @click.stop="go('/maintenance', { open: x.id })" style="color:var(--primary);cursor:pointer;text-decoration:none;font-weight:800">{{ x.id }}</a> <span class="c-sub" style="font-size:10.5px">↗</span></td><td><a @click.stop="go('/units', { open: x.u })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ unitName(x.u) }}</a></td><td>{{ x.desc }}</td><td>{{ x.reported || '—' }}</td><td>{{ x.liab || '—' }}</td>
                  <td><span class="badge" :class="badge(x.status)">{{ x.status }}</span></td>
                </tr>
                <tr v-if="!selTickets.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:18px">{{ t('No maintenance tickets.') }}</td></tr>
              </tbody>
            </table>
            </div>

            <div style="font-size:12px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin:20px 0 8px">🛠 Maintenance requests</div>
            <div class="drawer-tbl-wrap">
              <table class="kr" style="width:100%">
              <thead><tr><th>{{ t('Req') }}</th><th>{{ t('Unit') }}</th><th>{{ t('Title') }}</th><th>{{ t('Category') }}</th><th>{{ t('Priority') }}</th><th>{{ t('Est. cost') }}</th><th>{{ t('Status') }}</th><th v-if="canManage">{{ t('Actions') }}</th></tr></thead>
              <tbody>
                <tr v-for="m in selMaint" :key="m.id">
                  <td style="font-weight:700">{{ m.id }}</td><td>{{ unitName(m.unit) }}</td><td>{{ m.title }}</td><td>{{ m.category || '—' }}</td>
                  <td><span class="badge" :class="m.priority === 'high' ? 'b-red' : m.priority === 'medium' ? 'b-orange' : 'b-gray'">{{ m.priority || '—' }}</span></td>
                  <td>{{ money(m.cost_estimate) }}</td>
                  <td><span class="badge" :class="badge(m.status)">{{ m.status }}</span></td>
                  <td v-if="canManage" style="white-space:nowrap">
                    <button v-if="m.status === 'Open'" class="btn-primary" style="padding:4px 10px;font-size:11px" @click="setMaintStatus(m, 'In Progress')">✅ Approve</button>
                    <button class="btn-ghost" style="padding:4px 9px;font-size:11px" @click="openCost(m)" :title="t('Set cost')">💰</button>
                    <select :value="m.status" @change="setMaintStatus(m, $event.target.value)" style="padding:4px 6px;border:1px solid var(--border);border-radius:7px;background:var(--bg-alt);font-size:11px;color:var(--text);outline:none">
                      <option v-for="s in ['Open', 'Assigned', 'In Progress', 'Resolved', 'Closed']" :key="s" :value="s">{{ s }}</option>
                    </select>
                  </td>
                </tr>
                <tr v-if="!selMaint.length"><td :colspan="canManage ? 8 : 7" style="text-align:center;color:var(--text-mute);padding:18px">{{ t('No maintenance requests.') }}</td></tr>
              </tbody>
            </table>
            </div>
          </div>

          <!-- HANDOVER -->
          <div v-else-if="tab === 'hando'">
            <div v-if="hovoLoading" class="c-sub" style="font-size:12px;text-align:center;padding:20px">Loading checklists…</div>
            <template v-else>
              <div v-for="l in selLeases" :key="l.id" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 15px;margin-bottom:12px">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                  <div style="font-weight:800;font-size:13px">📦 {{ l.id }} — {{ unitName(l.u) }} <span class="c-sub" style="font-weight:500">({{ l.status }})</span></div>
                  <div v-if="canManage" style="display:flex;gap:6px">
                    <button v-if="!hovoForLease(l.id).some(c => c.kind === 'move_in')" class="btn-ghost" style="padding:5px 11px;font-size:11.5px" @click="createHovo(l.id, 'move_in')">＋ Move-in checklist</button>
                    <button v-if="!hovoForLease(l.id).some(c => c.kind === 'move_out')" class="btn-ghost" style="padding:5px 11px;font-size:11.5px" @click="createHovo(l.id, 'move_out')">＋ Move-out checklist</button>
                  </div>
                </div>
                <div v-if="!hovoForLease(l.id).length" class="c-sub" style="font-size:12px;margin-top:8px">No handover checklist yet — create one to track keys, deposits, utility cards, furnishings…</div>
                <div v-for="c in hovoForLease(l.id)" :key="c.id" style="display:flex;align-items:center;gap:10px;margin-top:9px;flex-wrap:wrap">
                  <span class="badge" :class="c.kind === 'move_in' ? 'b-green' : 'b-orange'">{{ c.kind === 'move_in' ? '🚪 Move-in' : '🏁 Move-out' }}</span>
                  <span class="badge" :class="badge(c.status)">{{ c.status }}</span>
                  <div style="flex:1;min-width:120px;height:6px;border-radius:3px;background:rgba(127,146,178,.18);overflow:hidden">
                    <div :style="`width:${c.items?.length ? Math.round(c.items.filter(x => x.checked).length / c.items.length * 100) : 0}%;height:100%;background:var(--primary)`"></div>
                  </div>
                  <span class="c-sub" style="font-size:11.5px">{{ c.items?.filter(x => x.checked).length || 0 }}/{{ c.items?.length || 0 }}</span>
                  <button class="btn-primary" style="padding:5px 12px;font-size:11.5px" @click="openHovo(c.id)">✏️ Edit</button>
                </div>
              </div>
            </template>

            <!-- checklist editor -->
            <template v-if="hovoSel">
              <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:64" @click="hovoSel = null"></div>
              <div style="position:fixed;top:0;right:0;bottom:0;width:min(480px,94vw);background:var(--card);z-index:65;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
                <div style="padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
                  <h3 style="font-size:14.5px;font-weight:800">📦 {{ hovoSel.id }} <span class="badge" :class="hovoSel.kind === 'move_in' ? 'b-green' : 'b-orange'">{{ hovoSel.kind === 'move_in' ? 'Move-in' : 'Move-out' }}</span> <span class="c-sub" style="font-weight:500">{{ unitName(selLeases.find(l => l.id === hovoSel.lease)?.u) }}</span></h3>
                  <button @click="hovoSel = null" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
                </div>
                <div style="padding:14px 18px;overflow-y:auto;flex:1">
                  <div class="c-sub" style="font-size:11.5px;margin-bottom:10px">Items received from / handed over to the tenant (keys, utility cards, advance/security money, furnishings…). Tick what is done, add notes, and add custom items as needed.</div>
                  <div v-for="(it, i) in hovoSel.items" :key="it.id" style="display:flex;gap:8px;align-items:center;background:var(--bg-alt);border:1px solid var(--border);border-radius:9px;padding:8px 10px;margin-bottom:8px">
                    <input type="checkbox" v-model="it.checked" :true-value="1" :false-value="0" style="accent-color:var(--primary);flex-shrink:0">
                    <div style="flex:1;min-width:0">
                      <input v-model="it.label" placeholder="Item (e.g. AC unit, keys ×3, utility cards…)" style="width:100%;padding:6px 8px;border:1px solid transparent;border-radius:7px;background:transparent;font-family:inherit;font-size:12.5px;color:var(--text);outline:none" :class="it.checked ? 'done-item' : ''">
                      <input v-model="it.note" placeholder="Note (condition, count, amount…)" style="width:100%;margin-top:3px;padding:5px 8px;border:1px solid transparent;border-radius:7px;background:rgba(127,146,178,.08);font-family:inherit;font-size:11px;color:var(--text-mute);outline:none">
                    </div>
                    <button class="btn-ghost" style="padding:5px 8px;font-size:11px;color:var(--danger);flex-shrink:0" @click="removeHovoItem(i)">✕</button>
                  </div>
                  <button v-if="canManage" class="btn-ghost" style="width:100%;justify-content:center;padding:9px;font-size:12px;margin-top:4px" @click="addHovoItem">＋ Add item</button>
                </div>
                <div style="padding:14px 18px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px">
                  <button class="btn-ghost" @click="hovoSel = null">{{ t('Close') }}</button>
                  <button class="btn-primary" :disabled="hovoSaving" @click="saveHovo" style="padding:9px 18px">{{ hovoSaving ? 'Saving…' : '💾 Save checklist' }}</button>
                </div>
              </div>
            </template>
          </div>

          <!-- CHAT -->
          <div v-else-if="tab === 'chat'" style="display:flex;flex-direction:column;height:calc(100% - 8px)">
            <div ref="chatBox" style="flex:1;overflow-y:auto;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:9px;min-height:220px;max-height:46vh">
              <div v-if="chatLoading && !chatMsgs.length" class="c-sub" style="font-size:12px;text-align:center;padding:30px 0">Loading thread…</div>
              <template v-else-if="chatMsgs.length">
                <div v-for="m in chatMsgs" :key="m.id" :style="`max-width:82%;padding:9px 13px;border-radius:13px;font-size:13px;line-height:1.45;${m.sender_role === 'tenant' ? 'align-self:flex-start;background:var(--card);border:1px solid var(--border);border-bottom-left-radius:3px' : 'align-self:flex-end;background:var(--primary);color:#fff;border-bottom-right-radius:3px'}`">
                  <div style="font-size:10px;font-weight:800;opacity:.75;margin-bottom:2px">{{ m.sender_role === 'tenant' ? (m.sender || 'Tenant') : (m.sender || 'Staff') }} · {{ m.ts ? String(m.ts).replace('T', ' ').slice(0, 16) : '' }}</div>
                  <div style="white-space:pre-wrap;word-break:break-word">{{ m.body }}</div>
                </div>
              </template>
              <div v-else class="c-sub" style="font-size:12px;text-align:center;padding:30px 0">{{ t('No messages yet — start the conversation.') }}</div>
            </div>
            <div style="display:flex;gap:8px;margin-top:10px">
              <input v-model="chatText" @keyup.enter="sendChat" placeholder="Type a message…" style="flex:1;padding:10px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <button class="btn-primary" :disabled="chatSending || !chatText.trim()" @click="sendChat" style="padding:10px 18px">➤</button>
            </div>
          </div>

          <!-- DOCUMENTS -->
          <div v-else-if="tab === 'docs'">
            <div style="display:flex;justify-content:space-between;align-items:center;margin:4px 0 10px;flex-wrap:wrap;gap:8px">
              <div style="font-size:12px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">📎 {{ t('Tenant') }} documents</div>
              <div v-if="canManage" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <select v-model="docCat" :title="t('Document type')" style="padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:12px;color:var(--text);outline:none;max-width:210px">
                  <option v-for="t in DOC_TYPES" :key="t.id" :value="t.id">{{ t.label }}</option>
                </select>
                <label style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800">
                  {{ docUploading ? 'Uploading…' : '⬆ Upload document' }}
                  <input type="file" style="display:none" @change="onDocPick">
                </label>
              </div>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
              <button class="btn-ghost" :style="docFilter === 'all' ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : ''" style="padding:5px 11px;font-size:11.5px;border-radius:999px" @click="docFilter = 'all'">All ({{ selDocs.length }})</button>
              <button v-for="t in DOC_TYPES" :key="t.id" class="btn-ghost" :style="docFilter === t.id ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : ''" style="padding:5px 11px;font-size:11.5px;border-radius:999px" @click="docFilter = t.id">{{ t.label.replace(/^[^ ]+ /, '') }} ({{ selDocs.filter(d => d.cat === t.id).length }})</button>
            </div>
            <div class="drawer-tbl-wrap">
              <table class="kr" style="width:100%">
              <thead><tr><th>{{ t('Doc') }}</th><th>{{ t('Name') }}</th><th>{{ t('Type') }}</th><th>{{ t('Size') }}</th><th>{{ t('Uploaded') }}</th><th>By</th><th></th></tr></thead>
              <tbody>
                <tr v-for="d in filteredDocs" :key="d.id">
                  <td style="font-weight:700">{{ d.id }}</td>
                  <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ d.name }}</td>
                  <td>
                    <select v-if="canManage" :value="d.cat" :disabled="docCatBusy === d.id" :title="t('Change type')" style="padding:3px 5px;border:1px solid var(--border);border-radius:7px;background:var(--bg-alt);font-family:inherit;font-size:10.5px;color:var(--text);outline:none;max-width:150px" @change="setDocCat(d, $event.target.value)">
                      <option v-for="t in DOC_TYPES" :key="t.id" :value="t.id">{{ t.label }}</option>
                    </select>
                    <span v-else class="badge b-gray" style="font-size:10px">{{ docTypeLabel(d.cat) }}</span>
                  </td>
                  <td>{{ fmtSize(d.size) }}</td>
                  <td>{{ fmtTs(d.ts) }}</td>
                  <td>{{ d.uploaded_by || '—' }}</td>
                  <td style="white-space:nowrap">
                    <button class="btn-ghost" style="padding:4px 9px;font-size:11.5px" @click="viewDoc(d)" :title="t('View')">👁</button>
                    <button class="btn-ghost" style="padding:4px 9px;font-size:11.5px" @click="downloadDoc(d)" :title="t('Download')">⬇</button>
                    <button v-if="canManage" class="btn-ghost" style="padding:4px 9px;font-size:11.5px;color:var(--danger)" @click="delDoc(d)" :title="t('Delete')">🗑️</button>
                  </td>
                </tr>
                <tr v-if="!filteredDocs.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">{{ docFilter === 'all' ? 'No documents yet — upload NID copy, agreement, references…' : 'No documents in this category yet.' }}</td></tr>
              </tbody>
            </table>
            </div>
          </div>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>

    <!-- lease agreement viewer modal -->
    <template v-if="agreeModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="agreeModal = null"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(560px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">📄 {{ t('Lease') }} paper — {{ agreeModal.id }}</h3>
          <button @click="agreeModal = null" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:16px 22px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-bottom:16px">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:9px;padding:9px 11px">
              <div style="font-size:10px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Registration office') }}</div>
              <div style="font-size:12.5px;font-weight:700;margin-top:2px">{{ agreeModal.reg_office || '—' }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:9px;padding:9px 11px">
              <div style="font-size:10px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Registration deed') }}</div>
              <div style="font-size:12.5px;font-weight:700;margin-top:2px">{{ agreeModal.reg_deed || '—' }}</div>
            </div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">📎 Agreement documents</div>
            <label v-if="canManage" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:9px;background:var(--primary);color:#fff;font-size:11.5px;font-weight:800">
              {{ agreeUploading ? 'Uploading…' : '⬆ Attach scanned agreement' }}
              <input type="file" style="display:none" @change="attachAgreement">
            </label>
          </div>
          <div v-if="!leaseDocsOf(agreeModal.id).length" class="c-sub" style="font-size:12px;padding:8px 0">{{ t('No scanned agreement attached to this lease yet. Click') }} <b>{{ t('Attach scanned agreement') }}</b> to upload the signed lease paper.</div>
          <div v-for="d in leaseDocsOf(agreeModal.id)" :key="d.id" style="display:flex;align-items:center;gap:10px;background:var(--bg-alt);border:1px solid var(--border);border-radius:9px;padding:9px 12px;margin-bottom:8px">
            <div style="flex:1;min-width:0">
              <div style="font-weight:700;font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ d.name }}</div>
              <div class="c-sub" style="font-size:11px">{{ d.id }} · {{ fmtSize(d.size) }} · {{ fmtTs(d.ts) }}</div>
            </div>
            <button class="btn-ghost" style="padding:5px 10px;font-size:11.5px" @click="viewDoc(d)">👁 {{ t('View') }}</button>
            <button class="btn-ghost" style="padding:5px 10px;font-size:11.5px" @click="downloadDoc(d)">⬇</button>
          </div>
        </div>
      </div>
    </template>

    <!-- notice modal -->
    <template v-if="noticeModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="noticeModal = null"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(480px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">📢 Send notice</h3>
          <button @click="noticeModal = null" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Template') }}</label>
            <select v-model="noticeModal.preset" @change="applyPreset" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option v-for="p in NOTICE_PRESETS" :key="p.id" :value="p.id">{{ p.label }}</option>
            </select>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Title') }} *</label>
            <input v-model="noticeModal.title" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Body') }}</label>
            <textarea v-model="noticeModal.body" rows="4" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;resize:vertical"></textarea>
          </div>
          <div v-if="noticeModal.preset === 'eviction'">
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">🚪 Vacate by (date)</label>
            <input v-model="noticeModal.vacateBy" type="date" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <div class="c-sub" style="font-size:11px;margin-top:4px">{{ t('Appended to the notice body as the vacating deadline.') }}</div>
          </div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="noticeModal = null">{{ t('Cancel') }}</button>
          <button class="btn-primary" :disabled="noticeSaving" @click="sendNotice" style="padding:9px 18px">{{ noticeSaving ? 'Posting…' : '📢 Post notice' }}</button>
        </div>
      </div>
    </template>

    <!-- renewal offer modal -->
    <template v-if="offerModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="offerModal = null"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(460px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">📄 {{ t('Lease') }} extension offer</h3>
          <button @click="offerModal = null" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:flex;flex-direction:column;gap:12px">
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Lease') }}</label>
            <select v-model="offerModal.lease" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option v-for="l in selLeases" :key="l.id" :value="l.id">{{ l.id }} — {{ unitName(l.u) }} (৳{{ (l.rent || 0).toLocaleString('en-IN') }}/mo)</option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Months *</label>
              <input v-model.number="offerModal.months" type="number" min="1" max="36" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            </div>
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Rent escalation %') }}</label>
              <input v-model.number="offerModal.escalation" type="number" min="0" max="100" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            </div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">OR new rent (৳/mo, optional)</label>
            <input v-model.number="offerModal.new_rent" type="number" min="0" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <div class="c-sub" style="font-size:11px;margin-top:4px">Conditions: enter escalation % or a fixed new rent — the tenant sees the offer in their portal and accepts/declines.</div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Conditions / note') }}</label>
            <textarea v-model="offerModal.note" rows="2" placeholder="e.g. new parking rate applies, service charge 5%…" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;resize:vertical"></textarea>
          </div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="offerModal = null">{{ t('Cancel') }}</button>
          <button class="btn-primary" :disabled="offerSaving" @click="submitOffer" style="padding:9px 18px">{{ offerSaving ? 'Sending…' : '📤 Send offer' }}</button>
        </div>
      </div>
    </template>

    <!-- meter reading modal -->
    <template v-if="meterModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="meterModal = null"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(430px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">⚡ Enter meter reading</h3>
          <button @click="meterModal = null" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:flex;flex-direction:column;gap:12px">
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Unit') }}</label>
              <select v-model="meterModal.unit" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
                <option v-for="u in unitOptions" :key="u.id" :value="u.id">{{ u.name }}</option>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Type') }}</label>
              <select v-model="meterModal.type" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
                <option v-for="t in UTIL_TYPES" :key="t" :value="t">{{ t }}</option>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Month') }}</label>
              <input v-model="meterModal.month" type="month" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
            </div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Reading') }} *</label>
            <input v-model.number="meterModal.reading" type="number" min="0" placeholder="e.g. 1250" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;font-weight:800;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Note (optional)') }}</label>
            <input v-model="meterModal.note" placeholder="e.g. self-reading by tenant" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="meterModal = null">{{ t('Cancel') }}</button>
          <button class="btn-primary" :disabled="meterSaving" @click="submitMeter" style="padding:9px 18px">{{ meterSaving ? 'Saving…' : '💾 Save reading' }}</button>
        </div>
      </div>
    </template>

    <!-- utility bill generate modal -->
    <template v-if="billModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="billModal = null"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(430px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">🧾 Generate utility bill</h3>
          <button @click="billModal = null" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:flex;flex-direction:column;gap:12px">
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Unit') }}</label>
              <select v-model="billModal.unit" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
                <option v-for="u in unitOptions" :key="u.id" :value="u.id">{{ u.name }}</option>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Type') }}</label>
              <select v-model="billModal.type" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
                <option v-for="t in UTIL_TYPES" :key="t" :value="t">{{ t }}</option>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Month') }}</label>
              <input v-model="billModal.month" type="month" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
            </div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Current reading (optional override)') }}</label>
            <input v-model.number="billModal.curr_reading" type="number" min="0" :placeholder="t('Leave empty to use the saved meter reading')" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <div class="c-sub" style="font-size:11px;margin-top:4px">{{ t('Usage') }} = current − previous reading × tariff rate + standing charge (postpaid meter).</div>
          </div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="billModal = null">{{ t('Cancel') }}</button>
          <button class="btn-primary" :disabled="billSaving" @click="generateBill" style="padding:9px 18px">{{ billSaving ? 'Generating…' : '🧾 Generate' }}</button>
        </div>
      </div>
    </template>

    <!-- maintenance cost modal -->
    <template v-if="costModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="costModal = null"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(420px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">💰 Maintenance cost — {{ costModal.id }}</h3>
          <button @click="costModal = null" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:flex;flex-direction:column;gap:12px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Estimate (৳)</label>
              <input v-model.number="costModal.cost_estimate" type="number" min="0" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            </div>
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Actual (৳)</label>
              <input v-model.number="costModal.actual_cost" type="number" min="0" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            </div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Charge to') }}</label>
            <select v-model="costModal.charge_to" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option value="owner">{{ t('Owner') }}</option><option value="tenant">{{ t('Tenant') }}</option><option value="service">{{ t('Service') }}</option><option value="insurance">{{ t('Insurance') }}</option>
            </select>
          </div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="costModal = null">{{ t('Cancel') }}</button>
          <button class="btn-primary" :disabled="costSaving" @click="submitCost" style="padding:9px 18px">{{ costSaving ? 'Saving…' : '💾 Save cost' }}</button>
        </div>
      </div>
    </template>

    <!-- partial payment modal -->
    <template v-if="payModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="payModal = null"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(430px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15.5px;font-weight:800">💳 Receive payment — {{ payModal.inv.id }}</h3>
          <button @click="payModal = null" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:flex;flex-direction:column;gap:12px">
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;text-align:center">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:9px 6px">
              <div style="font-size:9.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Invoice') }}</div>
              <div style="font-size:13px;font-weight:800;margin-top:2px">{{ money(payModal.inv.net) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:9px 6px">
              <div style="font-size:9.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Paid') }}</div>
              <div style="font-size:13px;font-weight:800;margin-top:2px;color:var(--ok)">{{ money(paidOfInvoice(payModal.inv)) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:9px 6px">
              <div style="font-size:9.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">{{ t('Remaining') }}</div>
              <div style="font-size:13px;font-weight:800;margin-top:2px;color:var(--danger)">{{ money(invRemaining(payModal.inv)) }}</div>
            </div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Amount (partial OK)') }}</label>
            <input v-model.number="payModal.amount" type="number" min="1" :max="invRemaining(payModal.inv)" style="width:100%;margin-top:5px;padding:10px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:14px;font-weight:800;color:var(--text);outline:none">
            <div class="c-sub" style="font-size:11px;margin-top:4px">{{ t('You can pay part of the invoice — status becomes') }} <b>{{ t('Partial') }}</b> until fully settled.</div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Date') }}</label>
              <input v-model="payModal.date" type="date" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            </div>
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Method') }}</label>
              <select v-model="payModal.method" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
                <option v-for="m in PAY_METHODS" :key="m" :value="m">{{ m }}</option>
              </select>
            </div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Note / reference (optional)') }}</label>
            <input v-model="payModal.sig" placeholder="bKash trx ID, cheque no, note…" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="payModal = null">{{ t('Cancel') }}</button>
          <button class="btn-primary" :disabled="paySaving || !payModal.amount || payModal.amount <= 0" @click="submitPay" style="padding:9px 18px">{{ paySaving ? 'Recording…' : `Receive ${money(payModal.amount)}` }}</button>
        </div>
      </div>
    </template>

    <!-- modal -->
    <template v-if="modal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="closeModal"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(500px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto">
        <div style="padding:20px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:16px;font-weight:800">{{ modal.mode === 'edit' ? '✏️ Edit ' + modal.t.id : '＋ New tenant' }}</h3>
          <button @click="closeModal" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:grid;grid-template-columns:1fr 1fr;gap:13px">
          <div style="grid-column:1/-1">
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Name') }} *</label>
            <input v-model="form.name" placeholder="e.g. Rafiqul Islam" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Kind') }}</label>
            <select v-model="form.kind" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option v-for="k in ['Individual','Corporate']" :key="k" :value="k">{{ k }}</option>
            </select>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Phone') }}</label>
            <input v-model="form.phone" placeholder="01711-223344" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Email') }}</label>
            <input v-model="form.email" type="email" placeholder="name@email.com" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Portal email') }}</label>
            <input v-model="form.sub_email" type="email" placeholder="tenant portal login (optional)" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <div class="c-sub" style="font-size:10.5px;margin-top:3px">Sets the tenant's login for the portal (login + invoice/chat access).</div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('NID / BIN') }}</label>
            <input v-model="form.nid" placeholder="1990123456789" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div style="display:flex;align-items:center">
            <label style="display:flex;align-items:center;gap:7px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" v-model="form.nrb" style="accent-color:var(--primary)"> 🌍 NRB (non-resident)</label>
          </div>
          <div v-if="formErr" style="grid-column:1/-1;color:var(--danger);font-size:12.5px;font-weight:600">{{ formErr }}</div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="closeModal">{{ t('Cancel') }}</button>
          <button class="btn-primary" :disabled="saving" @click="submitForm" style="padding:9px 18px">{{ saving ? 'Saving…' : modal.mode === 'edit' ? 'Save changes' : 'Create tenant' }}</button>
        </div>
      </div>
    </template>

    <!-- family member entry modal -->
    <template v-if="famModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="famModal = null"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(520px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">{{ famModal.index === null ? '👤 Add family member' : '✏️ Edit family member' }}</h3>
          <button @click="famModal = null" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:grid;grid-template-columns:1fr 1fr;gap:13px">
          <div style="grid-column:1/-1">
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Full name *</label>
            <input v-model="famModal.m.name" placeholder="e.g. Nazma Begum" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Relation') }}</label>
            <select v-model="famModal.m.relation" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option value="" disabled>{{ t('Select relation') }}</option>
              <option v-for="r in ['Spouse','Son','Daughter','Father','Mother','Brother','Sister','Other']" :key="r" :value="r">{{ r }}</option>
            </select>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Date of birth') }}</label>
            <input v-model="famModal.m.dob" type="date" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Phone') }}</label>
            <input v-model="famModal.m.phone" placeholder="01711-223344" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('NID') }}</label>
            <input v-model="famModal.m.nid" :placeholder="t('Optional')" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Occupation') }}</label>
            <input v-model="famModal.m.occupation" :placeholder="t('Optional')" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="famModal = null">{{ t('Cancel') }}</button>
          <button class="btn-primary" :disabled="famSaving" @click="saveFamMember" style="padding:9px 18px">{{ famSaving ? 'Saving…' : '💾 Save member' }}</button>
        </div>
      </div>
    </template>

    <!-- key indicators (score detail) modal -->
    <template v-if="scoreModal && scoreData">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="scoreModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(560px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">📊 Key indicators — {{ scoreData.tenant?.name || sel.name }}</h3>
          <button @click="scoreModal = false" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:flex;flex-direction:column;gap:16px">
          <div style="display:flex;align-items:center;gap:14px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px">
            <div :style="`width:58px;height:58px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:conic-gradient(${scoreData.band_color} ${scorePct}, rgba(127,146,178,.15) 0)`">
              <div style="width:44px;height:44px;border-radius:50%;background:var(--card);display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:900">{{ scoreData.score }}</div>
            </div>
            <div>
              <div style="font-weight:800;font-size:15px">{{ scoreData.band }}</div>
              <div class="c-sub" style="font-size:11.5px">{{ t('Weighted: 40% payment · 20% stability · 20% care · 20% compliance') }}</div>
            </div>
          </div>
          <div v-for="(fkey, fi) in ['payment','stability','care','compliance']" :key="fkey" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
              <div style="font-weight:800;font-size:13px;text-transform:capitalize">{{ fkey }} <span class="c-sub" style="font-weight:500">· {{ scoreData.detail?.weights?.[fkey] || 20 }}% weight</span></div>
              <b :style="`color:${scoreData.band_color}`">{{ scoreData.factors[fkey] }}</b>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px 14px;font-size:11.5px">
              <template v-if="fkey === 'payment'">
                <div>{{ t('Invoices:') }} <b>{{ scoreData.detail?.stats?.unpaid !== undefined ? (scoreData.detail.stats.unpaid + ' unpaid') : '—' }}</b></div>
                <div>{{ t('On-time rate:') }} <b>{{ scoreData.detail?.stats?.on_time_rate ?? '—' }}%</b></div>
                <div>{{ t('Overdue:') }} <b>{{ scoreData.detail?.stats?.overdue ?? '—' }}</b></div>
                <div>{{ t('Avg days late:') }} <b>{{ scoreData.detail?.stats?.avg_days_late ?? '—' }}</b></div>
              </template>
              <template v-else-if="fkey === 'stability'">
                <div>{{ t('Tenure:') }} <b>{{ scoreData.detail?.stats?.tenure_months ?? '—' }} mo</b></div>
                <div>{{ t('Approved renewals:') }} <b>{{ scoreData.detail?.stats?.renewals ?? '—' }}</b></div>
              </template>
              <template v-else-if="fkey === 'care'">
                <div>{{ t('Tickets:') }} <b>{{ scoreData.detail?.stats?.tickets_total ?? '—' }}</b></div>
                <div>{{ t('Resolved:') }} <b>{{ scoreData.detail?.stats?.tickets_open !== undefined ? ((scoreData.detail.stats.tickets_total || 0) - scoreData.detail.stats.tickets_open) : '—' }}</b></div>
                <div>{{ t('Meter months:') }} <b>{{ scoreData.detail?.stats?.meter_months ?? '—' }}</b></div>
              </template>
              <template v-else>
                <div>{{ t('Handover:') }} <b>{{ scoreData.detail?.stats?.handover_done ? '✓ Completed' : '—' }}</b></div>
                <div>{{ t('Registration:') }} <b>{{ scoreData.detail?.stats?.reg_ok ? '✓ Ok' : 'Pending' }}</b></div>
                <div>{{ t('Documents:') }} <b>{{ scoreData.detail?.stats?.docs ?? '—' }}</b></div>
              </template>
            </div>
          </div>
          <div style="font-size:11.5px;color:var(--text-mute);background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px">
            <div style="font-weight:800;color:var(--text);text-transform:uppercase;font-size:10.5px;letter-spacing:.3px;margin-bottom:6px">💡 Suggestions</div>
            <div v-for="tip in scoreData.tips" :key="tip" style="margin-top:3px">{{ tip }}</div>
          </div>
        </div>
      </div>
    </template>

    <!-- settlement statement modal (move-out) -->
    <template v-if="settleModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="settleModal = null"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(620px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">🧾 Settlement statement — {{ settleModal.tenant?.name }}</h3>
          <button @click="settleModal = null" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:flex;flex-direction:column;gap:14px">
          <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
            <span class="badge" :class="settleModal.settlement?.status === 'NO_DUES' ? 'b-green' : settleModal.settlement?.status === 'SETTLED' ? 'b-blue' : 'b-orange'" style="font-size:11.5px">{{ settleModal.settlement?.status || '—' }}</span>
            <span class="c-sub" style="font-size:12px">{{ settleModal.lease?.id }} · {{ settleModal.unit?.name }} · {{ settleModal.unit?.property }} · rent ৳{{ (settleModal.lease?.rent || 0).toLocaleString('en-IN') }}/mo</span>
          </div>

          <div style="display:flex;gap:10px;flex-wrap:wrap;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 15px;align-items:center">
            <div>
              <label style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Move-out date') }}</label>
              <input v-model="settleDate" type="date" style="display:block;margin-top:4px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
            </div>
            <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;cursor:pointer;padding-top:14px">
              <input v-model="settleApplyDeposit" type="checkbox" style="width:15px;height:15px;accent-color:var(--primary)">
              <b>{{ t('Apply advance / security deposit') }}</b> <span class="c-sub" style="font-size:11px">(৳{{ ((settleModal.settlement?.deposit) || 0).toLocaleString('en-IN') }} available)</span>
            </label>
            <div style="flex:1"></div>
            <button class="btn-ghost" style="padding:8px 14px;font-size:12px" :disabled="settleRecomputing" @click="recomputeSettle">{{ settleRecomputing ? 'Recomputing…' : '⟳ Recompute' }}</button>
          </div>

          <div v-if="settleModal.settlement?.sections?.rent_arrears?.length" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">💰 {{ t('Rent') }} arrears</div>
            <div v-for="a in settleModal.settlement.sections.rent_arrears" :key="a.invoice" style="display:flex;justify-content:space-between;font-size:12.5px;padding:3px 0">
              <span>{{ a.invoice }} · {{ a.month }} <span class="c-sub">(net ৳{{ (a.net || 0).toLocaleString('en-IN') }}, paid ৳{{ (a.paid || 0).toLocaleString('en-IN') }})</span></span>
              <b style="color:var(--danger)">৳{{ (a.due || 0).toLocaleString('en-IN') }}</b>
            </div>
          </div>
          <div v-if="settleModal.settlement?.sections?.utility_dues?.length" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">⚡ Utility dues</div>
            <div v-for="u in settleModal.settlement.sections.utility_dues" :key="u.id" style="display:flex;justify-content:space-between;font-size:12.5px;padding:3px 0">
              <span>{{ u.id }} · {{ u.type }} · {{ u.month }} <span class="c-sub">({{ u.usage }} units)</span></span>
              <b style="color:var(--danger)">৳{{ (u.amount || 0).toLocaleString('en-IN') }}</b>
            </div>
          </div>
          <div v-if="settleModal.settlement?.sections?.damages?.length" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">🔧 Damage deductions</div>
            <div v-for="d in settleModal.settlement.sections.damages" :key="d.label" style="display:flex;justify-content:space-between;font-size:12.5px;padding:3px 0">
              <span>{{ d.label }}</span>
              <b style="color:var(--danger)">৳{{ (d.amount || 0).toLocaleString('en-IN') }}</b>
            </div>
          </div>
          <div v-if="!settleModal.settlement?.sections?.rent_arrears?.length && !settleModal.settlement?.sections?.utility_dues?.length && !settleModal.settlement?.sections?.damages?.length" class="c-sub" style="font-size:12.5px;padding:4px 0">{{ t('No outstanding dues — all settled.') }}</div>

          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">🔧 Damage deductions (editable)</div>
              <button class="btn-ghost" style="padding:4px 10px;font-size:11.5px" @click="addDeduct">＋ Add</button>
            </div>
            <div v-if="!settleDeducts.length" class="c-sub" style="font-size:12px;padding:2px 0 4px">No deductions — add e.g. broken window, repainting, missing keys…</div>
            <div v-for="(dd, di) in settleDeducts" :key="di" style="display:flex;gap:8px;align-items:center;margin-bottom:7px">
              <input v-model="dd.label" placeholder="Reason (e.g. repainting, damaged AC…)" style="flex:1;padding:7px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
              <input v-model.number="dd.amount" type="number" min="0" placeholder="৳ amount" style="width:110px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
              <button class="btn-ghost" style="padding:5px 9px;font-size:11.5px;color:var(--danger)" @click="rmDeduct(di)">✕</button>
            </div>
            <div class="c-sub" style="font-size:11px">{{ t('Click') }} <b>⟳ Recompute</b> above to refresh the statement with these deductions.</div>
          </div>

          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">{{ t('Summary') }}</div>
            <div v-for="(row, rk) in [['Rent arrears', 'rent'], ['Utility dues', 'utility'], ['Damage deductions', 'damages']]" :key="rk" style="display:flex;justify-content:space-between;font-size:12.5px;padding:3px 0">
              <span>{{ row[0] }}</span><b>৳{{ ((settleModal.settlement?.totals?.[row[1]]) || 0).toLocaleString('en-IN') }}</b>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12.5px;padding:3px 0;border-top:1px solid var(--border);margin-top:4px;padding-top:7px">
              <span>{{ t('Total due') }}</span><b style="color:var(--danger)">৳{{ ((settleModal.settlement?.totals?.total_due) || 0).toLocaleString('en-IN') }}</b>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12.5px;padding:3px 0">
              <span>{{ t('Advance / security deposit') }}</span><b>৳{{ ((settleModal.settlement?.deposit) || 0).toLocaleString('en-IN') }}</b>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12.5px;padding:3px 0">
              <span>{{ t('Deposit applied') }}</span><b>− ৳{{ ((settleModal.settlement?.deposit_applied) || 0).toLocaleString('en-IN') }}</b>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:14px;font-weight:900;padding:6px 0 0;border-top:1px solid var(--border);margin-top:5px">
              <span>{{ settleModal.settlement?.status === 'DUE' ? 'Balance payable by tenant' : 'Refundable to tenant' }}</span>
              <span :style="`color:${settleModal.settlement?.status === 'DUE' ? 'var(--danger)' : 'var(--ok)'}`">৳{{ ((settleModal.settlement?.status === 'DUE' ? settleModal.settlement?.totals?.balance : settleModal.settlement?.totals?.refund) || 0).toLocaleString('en-IN') }}</span>
            </div>
          </div>
          <div class="c-sub" style="font-size:11px">Statement #{{ settleModal.settlement?.id }} · {{ settleModal.settlement?.generated_at }} · move-out handover: {{ settleModal.handover?.id || '—' }} ({{ settleModal.handover?.status || '—' }}) · open tickets: {{ settleModal.open_tickets?.length || 0 }}</div>
        </div>
        <div style="padding:15px 22px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
          <button class="btn-ghost" @click="settleModal = null">{{ t('Close') }}</button>
          <div style="display:flex;gap:10px">
            <button class="btn-ghost" style="padding:9px 16px;font-size:12.5px" :disabled="settleRecomputing" @click="recomputeSettle">{{ settleRecomputing ? 'Recomputing…' : '⟳ Recompute' }}</button>
            <button style="padding:9px 18px;font-size:12.5px;font-weight:800;border:none;border-radius:10px;background:var(--danger);color:#fff;cursor:pointer;display:inline-flex;align-items:center;gap:6px" :disabled="settleFinalizing || settleModal.lease?.status === 'Ended'" @click="finalizeSettle">
              {{ settleFinalizing ? 'Finalizing…' : (settleModal.lease?.status === 'Ended' ? '✓ Already ended' : '🚪 Finalize move-out') }}
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- V2.39: temp portal credentials after creating a tenant with an email -->
    <div v-if="provisionInfo" class="overlay" @click.self="provisionInfo = null">
      <div class="drawer" style="max-width:520px">
        <div class="modal-h"><span class="t">🔑 Portal account created</span><button class="close" @click="provisionInfo = null">✕</button></div>
        <div style="padding:20px 22px">
          <div style="font-size:14px;line-height:1.7;margin-bottom:14px">
            <b>{{ provisionInfo.name }}</b>'s portal login is ready and a welcome email was sent to <b>{{ provisionInfo.email }}</b>.
            Keep this temporary password safe — they can change it after their first login.
          </div>
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:16px 18px;display:flex;flex-direction:column;gap:8px;margin-bottom:16px">
            <div style="font-size:13px"><span style="color:var(--text-mute)">{{ t('Portal:') }}</span> <b>krtaker.com/app-v3</b> → My Portal</div>
            <div style="font-size:13px"><span style="color:var(--text-mute)">{{ t('Login email:') }}</span> <b>{{ provisionInfo.email }}</b></div>
            <div style="font-size:13px"><span style="color:var(--text-mute)">{{ t('Temporary password:') }}</span> <b style="font-family:monospace;font-size:14px">{{ provisionInfo.temp_password }}</b></div>
            <div v-if="provisionInfo.mail" style="font-size:12px;color:var(--warn)">⚠️ {{ provisionInfo.mail }}</div>
          </div>
          <div style="display:flex;justify-content:flex-end;gap:8px">
            <button class="btn-primary" style="padding:9px 18px;font-size:13px" @click="provisionInfo = null">{{ t('Got it') }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <ImportWizard v-if="showImport" collection="tenants" :on-done="refreshAll" @close="showImport = false" />
</template>

<style scoped>
/* White badges only on cover/header areas (same convention as Properties/Units) */
.t-cover .badge,
.d-cover .badge {
  background: #ffffff;
}
/* Single-line scrollable tab bar */
.tab-scroll {
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.tab-scroll::-webkit-scrollbar {
  display: none;
}
.tab-arrow {
  flex-shrink: 0;
  width: 26px;
  height: 26px;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--bg-alt);
  color: var(--text-mute);
  font-size: 11px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  transition: background .15s, color .15s;
}
.tab-arrow:hover {
  background: var(--primary);
  color: #fff;
  border-color: var(--primary);
}
.done-item {
  text-decoration: line-through;
  opacity: .6;
}
</style>
