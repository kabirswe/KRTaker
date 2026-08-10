<script setup>
import { computed, ref, watch, onUnmounted, nextTick } from 'vue'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall, apiUpload, apiBlob } from '../api/client'

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

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const propName = (pid) => propsAll.value.find(p => p.id === pid)?.name || pid || '—'
const unitName = (uid) => unitsAll.value.find(u => u.id === uid)?.name || uid || '—'

function badge(st) {
  const map = { Active: 'b-green', Leased: 'b-green', Paid: 'b-green', Success: 'b-green', Verified: 'b-green', Approved: 'b-green', Completed: 'b-green', Open: 'b-red', Unpaid: 'b-orange', Partial: 'b-orange', Overdue: 'b-red', Vacant: 'b-gray', 'Maintenance': 'b-orange', Expired: 'b-gray', Terminated: 'b-red', 'In Progress': 'b-blue', 'Pending Registration': 'b-orange', Pending: 'b-orange', Rejected: 'b-red' }
  return map[st] || 'b-gray'
}
// deterministic avatar color from id
const AV_COLORS = ['#2F80ED', '#27AE60', '#E67E22', '#9B59B6', '#E74C3C', '#16A085', '#8E44AD', '#2980B9']
function avatarColor(id) { let h = 0; for (const c of String(id)) h = (h * 31 + c.charCodeAt(0)) >>> 0; return AV_COLORS[h % AV_COLORS.length] }
function initials(name) { return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() }
function maskNid(nid) { return nid ? String(nid).replace(/^(.{4}).*(.{4})$/, '$1••••$2') : '—' }
function leaseDaysLeft(l) { if (!l?.end) return null; return Math.round((new Date(l.end) - Date.now()) / 86400000) }
const today = () => new Date().toISOString().slice(0, 10)

// ── joins ──
const leasesOfTenant = (t) => leasesAll.value.filter(l => l.t === t.id)
const activeLeaseOf = (t) => leasesOfTenant(t).find(l => String(l.status).toLowerCase() === 'active') || leasesOfTenant(t)[0]
const unitsOfTenant = (t) => { const us = new Set(leasesOfTenant(t).map(l => l.u)); return unitsAll.value.filter(u => us.has(u.id)) }
const invoicesOfTenant = (t) => { const ls = new Set(leasesOfTenant(t).map(l => l.id)); return invoicesAll.value.filter(i => ls.has(i.l)) }
const ticketsOfTenant = (t) => { const us = new Set(unitsOfTenant(t).map(u => u.id)); return ticketsAll.value.filter(x => us.has(x.u)) }
const utilsOfTenant = (t) => { const us = new Set(unitsOfTenant(t).map(u => u.id)); return utilsAll.value.filter(b => us.has(b.unit)) }
const maintOfTenant = (t) => { const us = new Set(unitsOfTenant(t).map(u => u.id)); return maintAll.value.filter(m => us.has(m.unit) || m.tenant === t.id) }
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
  { id: 'chat', label: 'Chat', ico: '💬' },
  { id: 'docs', label: 'Documents', ico: '📎' },
]
const tabCount = (id) => id === 'profile' ? '·' : id === 'leases' ? selLeases.value.length : id === 'billing' ? selInvoices.value.length + selUtils.value.length : id === 'tickets' ? selTickets.value.length + selMaint.value.length : id === 'chat' ? chatMsgs.value.length : selDocs.value.length
function openDetail(t) { sel.value = t; tab.value = 'profile' }
function closeDetail() { sel.value = null }
function reResolveSel() { if (sel.value) sel.value = tenantsAll.value.find(t => t.id === sel.value.id) || sel.value }

const selLeases = computed(() => sel.value ? leasesOfTenant(sel.value) : [])
const selUnits = computed(() => sel.value ? unitsOfTenant(sel.value) : [])
const selInvoices = computed(() => sel.value ? invoicesOfTenant(sel.value) : [])
const selTickets = computed(() => sel.value ? ticketsOfTenant(sel.value) : [])
const selUtils = computed(() => sel.value ? utilsOfTenant(sel.value) : [])
const selMaint = computed(() => sel.value ? maintOfTenant(sel.value) : [])
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
async function onDocPick(e) {
  const f = e.target.files && e.target.files[0]
  e.target.value = ''
  if (!f || !sel.value) return
  const fd = new FormData()
  fd.append('file', f)
  fd.append('kind', 'tenant')
  fd.append('ref', sel.value.id)
  fd.append('cat', 'other')
  docUploading.value = true
  try {
    const r = await apiUpload('app-doc-upload', fd)
    if (r.ok) { window.__krToast?.('📎 Document uploaded', 'ok'); await data.bootstrap(); reResolveSel() }
    else window.__krToast?.(r.error || 'Upload failed', 'error')
  } finally { docUploading.value = false }
}
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
async function delDoc(d) {
  if (!confirm(`Delete document "${d.name}"?`)) return
  const r = await apiCall('app-doc-delete', { id: d.id })
  if (r.ok) { window.__krToast?.('🗑️ Document deleted', 'ok'); await data.bootstrap(); reResolveSel() }
  else window.__krToast?.(r.error || 'Delete failed', 'error')
}
const fmtSize = (b) => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : b > 1024 ? Math.round(b / 1024) + ' KB' : (b || 0) + ' B'
const fmtTs = (ts) => ts ? String(ts).replace('T', ' ').slice(0, 16) : '—'

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
  if (sel.value) { loadPhoto(); loadNote(); loadScore(); loadChat() }
})
watch(tab, (t) => {
  if (t === 'profile' && sel.value) { if (!noteLoaded.value) loadNote(); if (!scoreLoaded.value) loadScore() }
  if (t === 'chat' && sel.value) loadChat()
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
function openAdd() {
  form.value = { name: '', phone: '', email: '', nid: '', nrb: false, kind: 'Individual' }
  formErr.value = ''; modal.value = { mode: 'add' }
}
function openEdit(t) {
  form.value = { name: t.name || '', phone: t.phone || '', email: t.email || '', nid: t.nid || '', nrb: String(t.nrb) === '1', kind: t.kind || 'Individual' }
  formErr.value = ''; modal.value = { mode: 'edit', t }
}
function closeModal() { modal.value = null; formErr.value = '' }
async function submitForm() {
  if (!form.value.name.trim()) { formErr.value = 'Tenant name is required.'; return }
  formErr.value = ''; saving.value = true
  try {
    const payload = { name: form.value.name.trim(), phone: form.value.phone.trim(), email: form.value.email.trim(), nid: form.value.nid.trim(), nrb: form.value.nrb ? 1 : 0, kind: form.value.kind }
    const r = await apiCall('app-crud', {
      action: modal.value.mode === 'edit' ? 'update' : 'create', collection: 'tenants',
      ...(modal.value.mode === 'edit' ? { id: modal.value.t.id } : {}), data: payload,
    })
    if (r.ok) {
      window.__krToast?.(modal.value.mode === 'edit' ? `✏️ ${modal.value.t.id} updated` : '✅ Tenant created', 'ok')
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
        <h1>👥 Tenants</h1>
        <div class="sub">{{ tenantsAll.length }} tenants · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search name, email, unit…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="kindFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All kinds</option>
          <option v-for="k in kindOptions" :key="k" :value="k">{{ k }}</option>
        </select>
        <select v-model="propFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All properties</option>
          <option v-for="p in propOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <select v-model="sortBy" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="name">Sort: Name</option>
          <option value="rent">Sort: Rent</option>
          <option value="outstanding">Sort: Outstanding</option>
        </select>
        <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;cursor:pointer;padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt)">
          <input type="checkbox" v-model="nrbOnly" style="accent-color:var(--primary)"> 🌍 NRB only
        </label>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
        <button v-if="canManage" @click="openAdd" class="btn-primary" style="padding:9px 16px">＋ New tenant</button>
      </div>
    </div>

    <div class="stats">
      <div v-for="k in kpis" :key="k.label" class="stat">
        <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
        <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
        <div class="s-trend">{{ k.trend }}</div>
      </div>
    </div>

    <div v-if="filtered.length" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:16px">
      <div v-for="t in filtered" :key="t.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(t)">
        <div class="t-cover" :style="`height:82px;position:relative;background:linear-gradient(135deg,${avatarColor(t.id)},#1E5EB8)`">
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge">{{ t.kind }}</span>
            <span v-if="String(t.nrb) === '1'" class="badge b-blue">🌍 NRB</span>
            <span v-if="nidVerifiedOf(t)" class="badge b-green">🪪 NID ✓</span>
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
            <span v-if="collectionRateT(t) !== null && outstanding(t) === 0" class="badge b-green">✅ Paid up</span>
            <span v-if="ticketsOfTenant(t).some(x => String(x.status).toLowerCase() === 'open')" class="badge b-orange">🔧 open ticket</span>
          </div>
          <div v-if="canManage" style="display:flex;gap:6px;border-top:1px solid var(--border);padding-top:9px">
            <button class="btn-ghost" style="flex:1;justify-content:center;padding:6px 10px;font-size:12px" @click.stop="openEdit(t)">✏️ Edit</button>
            <button class="btn-ghost" style="padding:6px 10px;font-size:12px;color:var(--danger)" @click.stop="delTenant(t)">🗑️</button>
          </div>
        </div>
      </div>
    </div>
    <div v-else class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No tenants found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(680px,96vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" :style="`height:118px;position:relative;flex-shrink:0;background:linear-gradient(135deg,${avatarColor(sel.id)},#1E5EB8)`">
          <div style="position:absolute;left:20px;bottom:16px;display:flex;align-items:center;gap:14px">
            <div style="position:relative">
              <img v-if="selPhoto" :src="selPhoto" alt="" style="width:58px;height:58px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.6);background:#fff">
              <div v-else :style="`width:58px;height:58px;border-radius:50%;background:rgba(255,255,255,.22);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:21px;border:2px solid rgba(255,255,255,.5)`">{{ initials(sel.name) }}</div>
              <label v-if="canManage" title="Upload / change photo" style="position:absolute;right:-4px;bottom:-4px;width:24px;height:24px;border-radius:50%;background:#fff;color:#1E5EB8;display:flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.25);border:1px solid #dbe3f0">
                <span v-if="!uploadingPhoto">📷</span><span v-else style="font-size:10px">…</span>
                <input type="file" accept="image/*" style="display:none" @change="onPhotoPick">
              </label>
            </div>
            <div>
              <div style="color:#fff;font-size:19px;font-weight:800;text-shadow:0 1px 4px rgba(0,0,0,.35)">{{ sel.name }}</div>
              <div style="display:flex;gap:6px;margin-top:5px;flex-wrap:wrap">
                <span class="badge">{{ sel.kind }}</span>
                <span v-if="String(sel.nrb) === '1'" class="badge b-blue">🌍 NRB</span>
                <span v-if="nidVerifiedOf(sel)" class="badge b-green">🪪 NID Verified</span>
                <span v-if="thanaVerifiedOf(sel)" class="badge b-green">🏛 Thana Verified</span>
                <span v-if="!nidVerifiedOf(sel) && !thanaVerifiedOf(sel)" class="badge b-gray">Verification pending</span>
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
          <div class="c-sub" style="margin-top:2px">{{ sel.id }} · NID {{ maskNid(sel.nid) }} · {{ sel.sub_email || 'no portal account' }}</div>

          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
            <div v-for="s in selStats" :key="s.label" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ s.label }}</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ s.v }}</div>
            </div>
          </div>

          <div style="display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:14px;flex-wrap:wrap">
            <button v-for="t in TABS" :key="t.id" @click="tab = t.id"
              style="padding:9px 14px;border:none;background:none;font-size:13px;font-weight:700;cursor:pointer;border-bottom:2px solid transparent;color:var(--text-mute)"
              :style="tab === t.id ? 'color:var(--primary);border-bottom-color:var(--primary)' : ''">
              {{ t.ico }} {{ t.label }} <span style="opacity:.7">({{ tabCount(t.id) }})</span>
            </button>
          </div>

          <!-- PROFILE -->
          <template v-if="tab === 'profile'">
            <!-- Tenant score -->
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px">
              <div style="display:flex;align-items:center;gap:14px;background:var(--bg-alt);border:1px solid var(--border);border-radius:14px;padding:16px 18px;flex:1;min-width:250px">
                <div :style="`width:74px;height:74px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;flex-direction:column;background:conic-gradient(${scoreData?.band_color || '#2F80ED'} ${scorePct}, rgba(127,146,178,.15) 0);position:relative`">
                  <div style="position:absolute;inset:7px;border-radius:50%;background:var(--card);display:flex;align-items:center;justify-content:center;flex-direction:column">
                    <div style="font-size:19px;font-weight:900;line-height:1">{{ scoreData?.score ?? '–' }}</div>
                    <div style="font-size:8.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.4px">score</div>
                  </div>
                </div>
                <div style="min-width:0">
                  <div style="font-weight:800;font-size:15px">Tenant score — <span :style="`color:${scoreData?.band_color || 'var(--text)'}`">{{ scoreData?.band || '…' }}</span></div>
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
                <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">🪪 NID / BIN verification</div>
                <div style="margin-top:7px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <span class="badge" :class="nidVerifiedOf(sel) ? 'b-green' : 'b-gray'">{{ nidVerifiedOf(sel) ? '✓ Verified' : 'Not verified' }}</span>
                  <span class="c-sub" style="font-size:11.5px">NID {{ maskNid(sel.nid) }}</span>
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
          <table v-else-if="tab === 'leases'" class="kr" style="width:100%">
            <thead><tr><th>Lease</th><th>Unit</th><th>Property</th><th>Floor</th><th>sqft</th><th>Rent</th><th>Start</th><th>End</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="l in selLeases" :key="l.id">
                <td style="font-weight:700">{{ l.id }}</td>
                <td>{{ unitName(l.u) }}</td>
                <td>{{ propName(unitsAll.find(u => u.id === l.u)?.p) }}</td>
                <td>{{ unitsAll.find(u => u.id === l.u)?.floor || '—' }}</td>
                <td>{{ ((unitsAll.find(u => u.id === l.u)?.sqft) || 0).toLocaleString('en-IN') }}</td>
                <td style="font-weight:700">{{ money(l.rent) }}/mo</td>
                <td>{{ l.start || '—' }}</td>
                <td>{{ l.end || '—' }} <span v-if="leaseDaysLeft(l) !== null && l.status === 'Active'" class="c-sub">({{ leaseDaysLeft(l) }}d)</span></td>
                <td><span class="badge" :class="badge(l.status)">{{ l.status }}</span></td>
              </tr>
              <tr v-if="!selLeases.length"><td colspan="9" style="text-align:center;color:var(--text-mute);padding:22px">No leases / units.</td></tr>
            </tbody>
          </table>

          <!-- BILLING (invoices + utilities merged) -->
          <div v-else-if="tab === 'billing'">
            <div style="display:flex;justify-content:space-between;align-items:center;margin:4px 0 8px">
              <div style="font-size:12px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">🧾 Invoices</div>
              <div class="c-sub" style="font-size:11.5px">{{ selInvoices.length }} · outstanding {{ money(selInvoices.filter(i => invStatus(i) !== 'Paid').reduce((s, i) => s + invRemaining(i), 0)) }}</div>
            </div>
            <table class="kr" style="width:100%">
              <thead><tr><th>Invoice</th><th>Month</th><th>Lease</th><th>Net</th><th>Paid</th><th>Remaining</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <tr v-for="i in selInvoices" :key="i.id">
                  <td style="font-weight:700">{{ i.id }}</td>
                  <td>{{ i.m || '—' }}</td>
                  <td>{{ i.l }}</td>
                  <td style="font-weight:700">{{ money(i.net) }}</td>
                  <td>{{ paidOfInvoice(i) > 0 ? money(paidOfInvoice(i)) : '—' }}</td>
                  <td :style="invRemaining(i) > 0 ? 'color:var(--danger);font-weight:800' : ''">{{ invRemaining(i) > 0 ? money(invRemaining(i)) : '—' }}</td>
                  <td><span class="badge" :class="badge(invStatus(i))">{{ invStatus(i) }}</span></td>
                  <td><button v-if="canPay && invRemaining(i) > 0" @click="openPay(i)" class="btn-primary" style="padding:5px 11px;font-size:11.5px">💳 Pay</button></td>
                </tr>
                <tr v-if="!selInvoices.length"><td colspan="8" style="text-align:center;color:var(--text-mute);padding:22px">No invoices.</td></tr>
              </tbody>
            </table>

            <div style="font-size:12px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin:20px 0 8px">🔌 Utility bills</div>
            <table class="kr" style="width:100%">
              <thead><tr><th>Bill</th><th>Unit</th><th>Type</th><th>Month</th><th>Usage</th><th>Amount</th><th>Status</th></tr></thead>
              <tbody>
                <tr v-for="b in selUtils" :key="b.id">
                  <td style="font-weight:700">{{ b.id }}</td><td>{{ unitName(b.unit) }}</td><td>{{ b.type }}</td><td>{{ b.month || '—' }}</td><td>{{ b.usage ?? '—' }}</td><td style="font-weight:700">{{ money(b.amount) }}</td>
                  <td><span class="badge" :class="badge(b.status)">{{ b.status }}</span></td>
                </tr>
                <tr v-if="!selUtils.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">No utility bills.</td></tr>
              </tbody>
            </table>
          </div>

          <!-- TICKETS & MAINTENANCE (merged) -->
          <div v-else-if="tab === 'tickets'">
            <div style="font-size:12px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin:4px 0 8px">🔧 Maintenance tickets</div>
            <table class="kr" style="width:100%">
              <thead><tr><th>Ticket</th><th>Unit</th><th>Issue</th><th>Reported</th><th>Liability</th><th>Status</th></tr></thead>
              <tbody>
                <tr v-for="x in selTickets" :key="x.id">
                  <td style="font-weight:700">{{ x.id }}</td><td>{{ unitName(x.u) }}</td><td>{{ x.desc }}</td><td>{{ x.reported || '—' }}</td><td>{{ x.liab || '—' }}</td>
                  <td><span class="badge" :class="badge(x.status)">{{ x.status }}</span></td>
                </tr>
                <tr v-if="!selTickets.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:18px">No maintenance tickets.</td></tr>
              </tbody>
            </table>

            <div style="font-size:12px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin:20px 0 8px">🛠 Maintenance requests</div>
            <table class="kr" style="width:100%">
              <thead><tr><th>Req</th><th>Unit</th><th>Title</th><th>Category</th><th>Priority</th><th>Est. cost</th><th>Status</th></tr></thead>
              <tbody>
                <tr v-for="m in selMaint" :key="m.id">
                  <td style="font-weight:700">{{ m.id }}</td><td>{{ unitName(m.unit) }}</td><td>{{ m.title }}</td><td>{{ m.category || '—' }}</td>
                  <td><span class="badge" :class="m.priority === 'high' ? 'b-red' : m.priority === 'medium' ? 'b-orange' : 'b-gray'">{{ m.priority || '—' }}</span></td>
                  <td>{{ money(m.cost_estimate) }}</td>
                  <td><span class="badge" :class="badge(m.status)">{{ m.status }}</span></td>
                </tr>
                <tr v-if="!selMaint.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:18px">No maintenance requests.</td></tr>
              </tbody>
            </table>
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
              <div v-else class="c-sub" style="font-size:12px;text-align:center;padding:30px 0">No messages yet — start the conversation.</div>
            </div>
            <div style="display:flex;gap:8px;margin-top:10px">
              <input v-model="chatText" @keyup.enter="sendChat" placeholder="Type a message…" style="flex:1;padding:10px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <button class="btn-primary" :disabled="chatSending || !chatText.trim()" @click="sendChat" style="padding:10px 18px">➤</button>
            </div>
          </div>

          <!-- DOCUMENTS -->
          <div v-else-if="tab === 'docs'">
            <div style="display:flex;justify-content:space-between;align-items:center;margin:4px 0 10px;flex-wrap:wrap;gap:8px">
              <div style="font-size:12px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">📎 Tenant documents</div>
              <label v-if="canManage" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800">
                {{ docUploading ? 'Uploading…' : '⬆ Upload document' }}
                <input type="file" style="display:none" @change="onDocPick">
              </label>
            </div>
            <table class="kr" style="width:100%">
              <thead><tr><th>Doc</th><th>Name</th><th>Size</th><th>Uploaded</th><th>By</th><th></th></tr></thead>
              <tbody>
                <tr v-for="d in selDocs" :key="d.id">
                  <td style="font-weight:700">{{ d.id }}</td>
                  <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ d.name }}</td>
                  <td>{{ fmtSize(d.size) }}</td>
                  <td>{{ fmtTs(d.ts) }}</td>
                  <td>{{ d.uploaded_by || '—' }}</td>
                  <td style="white-space:nowrap">
                    <button class="btn-ghost" style="padding:4px 9px;font-size:11.5px" @click="viewDoc(d)" title="View">👁</button>
                    <button class="btn-ghost" style="padding:4px 9px;font-size:11.5px" @click="downloadDoc(d)" title="Download">⬇</button>
                    <button v-if="canManage" class="btn-ghost" style="padding:4px 9px;font-size:11.5px;color:var(--danger)" @click="delDoc(d)" title="Delete">🗑️</button>
                  </td>
                </tr>
                <tr v-if="!selDocs.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:22px">No documents yet — upload NID copy, agreement, references…</td></tr>
              </tbody>
            </table>
          </div>
          <div style="height:24px"></div>
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
              <div style="font-size:9.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Invoice</div>
              <div style="font-size:13px;font-weight:800;margin-top:2px">{{ money(payModal.inv.net) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:9px 6px">
              <div style="font-size:9.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Paid</div>
              <div style="font-size:13px;font-weight:800;margin-top:2px;color:var(--ok)">{{ money(paidOfInvoice(payModal.inv)) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:9px 6px">
              <div style="font-size:9.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Remaining</div>
              <div style="font-size:13px;font-weight:800;margin-top:2px;color:var(--danger)">{{ money(invRemaining(payModal.inv)) }}</div>
            </div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Amount (partial OK)</label>
            <input v-model.number="payModal.amount" type="number" min="1" :max="invRemaining(payModal.inv)" style="width:100%;margin-top:5px;padding:10px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:14px;font-weight:800;color:var(--text);outline:none">
            <div class="c-sub" style="font-size:11px;margin-top:4px">You can pay part of the invoice — status becomes <b>Partial</b> until fully settled.</div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
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
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Note / reference (optional)</label>
            <input v-model="payModal.sig" placeholder="bKash trx ID, cheque no, note…" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="payModal = null">Cancel</button>
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
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Name *</label>
            <input v-model="form.name" placeholder="e.g. Rafiqul Islam" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Kind</label>
            <select v-model="form.kind" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option v-for="k in ['Individual','Corporate']" :key="k" :value="k">{{ k }}</option>
            </select>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Phone</label>
            <input v-model="form.phone" placeholder="01711-223344" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Email</label>
            <input v-model="form.email" type="email" placeholder="name@email.com" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">NID / BIN</label>
            <input v-model="form.nid" placeholder="1990123456789" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div style="display:flex;align-items:center">
            <label style="display:flex;align-items:center;gap:7px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" v-model="form.nrb" style="accent-color:var(--primary)"> 🌍 NRB (non-resident)</label>
          </div>
          <div v-if="formErr" style="grid-column:1/-1;color:var(--danger);font-size:12.5px;font-weight:600">{{ formErr }}</div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="closeModal">Cancel</button>
          <button class="btn-primary" :disabled="saving" @click="submitForm" style="padding:9px 18px">{{ saving ? 'Saving…' : modal.mode === 'edit' ? 'Save changes' : 'Create tenant' }}</button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* White badges only on cover/header areas (same convention as Properties/Units) */
.t-cover .badge,
.d-cover .badge {
  background: #ffffff;
}
</style>
