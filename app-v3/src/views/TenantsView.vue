<script setup>
import { computed, ref } from 'vue'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))

const tenantsAll = computed(() => data.list('tenants'))
const leasesAll = computed(() => data.list('leases'))
const unitsAll = computed(() => data.list('units'))
const propsAll = computed(() => data.list('properties'))
const invoicesAll = computed(() => data.list('invoices'))
const ticketsAll = computed(() => data.list('tickets'))
const utilsAll = computed(() => data.list('utility_bills'))

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const propName = (pid) => propsAll.value.find(p => p.id === pid)?.name || pid || '—'
const unitName = (uid) => unitsAll.value.find(u => u.id === uid)?.name || uid || '—'

function badge(st) {
  const map = { Active: 'b-green', Leased: 'b-green', Paid: 'b-green', Success: 'b-green', Verified: 'b-green', Completed: 'b-green', Approved: 'b-green', Open: 'b-red', Unpaid: 'b-orange', Overdue: 'b-red', Vacant: 'b-gray', 'Maintenance': 'b-orange', Expired: 'b-gray', Terminated: 'b-red', 'In Progress': 'b-blue', 'Pending Registration': 'b-orange', Pending: 'b-orange', Rejected: 'b-red' }
  return map[st] || 'b-gray'
}
// deterministic avatar color from id
const AV_COLORS = ['#2F80ED', '#27AE60', '#E67E22', '#9B59B6', '#E74C3C', '#16A085', '#8E44AD', '#2980B9']
function avatarColor(id) { let h = 0; for (const c of String(id)) h = (h * 31 + c.charCodeAt(0)) >>> 0; return AV_COLORS[h % AV_COLORS.length] }
function initials(name) { return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() }
function maskNid(nid) { return nid ? String(nid).replace(/^(.{4}).*(.{4})$/, '$1••••$2') : '—' }
function leaseDaysLeft(l) { if (!l?.end) return null; return Math.round((new Date(l.end) - Date.now()) / 86400000) }

// ── joins ──
const leasesOfTenant = (t) => leasesAll.value.filter(l => l.t === t.id)
const activeLeaseOf = (t) => leasesOfTenant(t).find(l => String(l.status).toLowerCase() === 'active') || leasesOfTenant(t)[0]
const unitsOfTenant = (t) => { const us = new Set(leasesOfTenant(t).map(l => l.u)); return unitsAll.value.filter(u => us.has(u.id)) }
const invoicesOfTenant = (t) => { const ls = new Set(leasesOfTenant(t).map(l => l.id)); return invoicesAll.value.filter(i => ls.has(i.l)) }
const ticketsOfTenant = (t) => { const us = new Set(unitsOfTenant(t).map(u => u.id)); return ticketsAll.value.filter(x => us.has(x.u)) }
const utilsOfTenant = (t) => { const us = new Set(unitsOfTenant(t).map(u => u.id)); return utilsAll.value.filter(b => us.has(b.unit)) }
function monthlyRent(t) { return leasesOfTenant(t).filter(l => String(l.status).toLowerCase() === 'active').reduce((s, l) => s + (l.rent || 0), 0) }
function outstanding(t) { return invoicesOfTenant(t).filter(i => String(i.status).toLowerCase() !== 'paid').reduce((s, i) => s + (i.net || 0), 0) }
function collectionRateT(t) {
  const invs = invoicesOfTenant(t); if (!invs.length) return null
  const paid = invs.filter(i => String(i.status).toLowerCase() === 'paid').reduce((s, i) => s + (i.net || 0), 0)
  const tot = invs.reduce((s, i) => s + (i.net || 0), 0)
  return tot ? Math.round(paid / tot * 100) : null
}

// ── KPIs ──
const kpis = computed(() => {
  const indiv = tenantsAll.value.filter(t => String(t.kind).toLowerCase() === 'individual').length
  const corp = tenantsAll.value.filter(t => String(t.kind).toLowerCase() === 'corporate').length
  const nrb = tenantsAll.value.filter(t => String(t.nrb) === '1').length
  const active = leasesAll.value.filter(l => String(l.status).toLowerCase() === 'active').length
  const rentRoll = leasesAll.value.filter(l => String(l.status).toLowerCase() === 'active').reduce((s, l) => s + (l.rent || 0), 0)
  const oust = invoicesAll.value.filter(i => String(i.status).toLowerCase() !== 'paid').reduce((s, i) => s + (i.net || 0), 0)
  return [
    { label: 'Tenants', ico: '👥', value: tenantsAll.value.length, trend: `${indiv} individual · ${corp} corporate` },
    { label: 'NRB clients', ico: '🌍', value: nrb, trend: 'non-resident Bangladeshi', ok: true },
    { label: 'Active leases', ico: '📄', value: active, trend: 'of ' + leasesAll.value.length },
    { label: 'Rent roll / mo', ico: '💵', value: money(rentRoll), trend: 'active leases' },
    { label: 'Outstanding', ico: '⏳', value: money(oust), trend: 'unpaid invoices', ok: oust === 0 },
    { label: 'Open tickets', ico: '🔧', value: ticketsAll.value.filter(t => String(t.status).toLowerCase() === 'open').length, trend: 'tenant units', ok: true },
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
const tab = ref('leases')
function openDetail(t) { sel.value = t; tab.value = 'leases' }
function closeDetail() { sel.value = null }
const selLeases = computed(() => sel.value ? leasesOfTenant(sel.value) : [])
const selUnits = computed(() => sel.value ? unitsOfTenant(sel.value) : [])
const selInvoices = computed(() => sel.value ? invoicesOfTenant(sel.value) : [])
const selTickets = computed(() => sel.value ? ticketsOfTenant(sel.value) : [])
const selUtils = computed(() => sel.value ? utilsOfTenant(sel.value) : [])
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
            <span class="badge" :class="badge(t.kind === 'Corporate' ? 'Active' : '')">{{ t.kind }}</span>
            <span v-if="String(t.nrb) === '1'" class="badge b-blue">🌍 NRB</span>
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
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(620px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" :style="`height:110px;position:relative;flex-shrink:0;background:linear-gradient(135deg,${avatarColor(sel.id)},#1E5EB8)`">
          <div style="position:absolute;left:20px;bottom:14px;display:flex;align-items:center;gap:13px">
            <div :style="`width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.22);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px;border:2px solid rgba(255,255,255,.5)`">{{ initials(sel.name) }}</div>
            <div>
              <div style="color:#fff;font-size:19px;font-weight:800;text-shadow:0 1px 4px rgba(0,0,0,.35)">{{ sel.name }}</div>
              <div style="display:flex;gap:6px;margin-top:4px;flex-wrap:wrap">
                <span class="badge">{{ sel.kind }}</span>
                <span v-if="String(sel.nrb) === '1'" class="badge b-blue">🌍 NRB</span>
              </div>
            </div>
          </div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <div class="c-sub" style="margin-top:2px">{{ sel.id }} · {{ sel.email || '—' }} · {{ sel.phone || '—' }} · NID {{ maskNid(sel.nid) }}</div>

          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
            <div v-for="s in selStats" :key="s.label" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ s.label }}</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ s.v }}</div>
            </div>
          </div>

          <div style="display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:14px;flex-wrap:wrap">
            <button v-for="t in [{id:'leases',label:'Leases',ico:'📄'},{id:'units',label:'Units',ico:'🚪'},{id:'invoices',label:'Invoices',ico:'🧾'},{id:'tickets',label:'Tickets',ico:'🔧'},{id:'utils',label:'Utilities',ico:'🔌'}]" :key="t.id" @click="tab = t.id"
              style="padding:9px 14px;border:none;background:none;font-size:13px;font-weight:700;cursor:pointer;border-bottom:2px solid transparent;color:var(--text-mute)"
              :style="tab === t.id ? 'color:var(--primary);border-bottom-color:var(--primary)' : ''">
              {{ t.ico }} {{ t.label }} <span style="opacity:.7">({{ t.id === 'leases' ? selLeases.length : t.id === 'units' ? selUnits.length : t.id === 'invoices' ? selInvoices.length : t.id === 'tickets' ? selTickets.length : selUtils.length }})</span>
            </button>
          </div>

          <table v-if="tab === 'leases'" class="kr" style="width:100%">
            <thead><tr><th>Lease</th><th>Unit</th><th>Property</th><th>Rent</th><th>Start</th><th>End</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="l in selLeases" :key="l.id">
                <td style="font-weight:700">{{ l.id }}</td>
                <td>{{ unitName(l.u) }}</td>
                <td>{{ propName(unitsAll.find(u => u.id === l.u)?.p) }}</td>
                <td style="font-weight:700">{{ money(l.rent) }}/mo</td>
                <td>{{ l.start || '—' }}</td>
                <td>{{ l.end || '—' }} <span v-if="leaseDaysLeft(l) !== null && l.status === 'Active'" class="c-sub">({{ leaseDaysLeft(l) }}d)</span></td>
                <td><span class="badge" :class="badge(l.status)">{{ l.status }}</span></td>
              </tr>
              <tr v-if="!selLeases.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">No leases.</td></tr>
            </tbody>
          </table>

          <table v-else-if="tab === 'units'" class="kr" style="width:100%">
            <thead><tr><th>Unit</th><th>Property</th><th>Floor</th><th>sqft</th><th>Rent</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="u in selUnits" :key="u.id">
                <td style="font-weight:700">{{ u.name }}</td>
                <td>{{ propName(u.p) }}</td>
                <td>{{ u.floor || '—' }}</td>
                <td>{{ (u.sqft || 0).toLocaleString('en-IN') }}</td>
                <td style="font-weight:700">{{ money(u.rent) }}</td>
                <td><span class="badge" :class="badge(u.status)">{{ u.status }}</span></td>
              </tr>
              <tr v-if="!selUnits.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:22px">No units.</td></tr>
            </tbody>
          </table>

          <table v-else-if="tab === 'invoices'" class="kr" style="width:100%">
            <thead><tr><th>Invoice</th><th>Month</th><th>Lease</th><th>Gross</th><th>TDS</th><th>Net</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="i in selInvoices" :key="i.id">
                <td style="font-weight:700">{{ i.id }}</td><td>{{ i.m || '—' }}</td><td>{{ i.l }}</td><td>{{ money(i.gross) }}</td><td>{{ money(i.tds) }}</td><td style="font-weight:700">{{ money(i.net) }}</td>
                <td><span class="badge" :class="badge(i.status)">{{ i.status }}</span></td>
              </tr>
              <tr v-if="!selInvoices.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">No invoices.</td></tr>
            </tbody>
          </table>

          <table v-else-if="tab === 'tickets'" class="kr" style="width:100%">
            <thead><tr><th>Ticket</th><th>Unit</th><th>Issue</th><th>Reported</th><th>Liability</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="x in selTickets" :key="x.id">
                <td style="font-weight:700">{{ x.id }}</td><td>{{ unitName(x.u) }}</td><td>{{ x.desc }}</td><td>{{ x.reported || '—' }}</td><td>{{ x.liab || '—' }}</td>
                <td><span class="badge" :class="badge(x.status)">{{ x.status }}</span></td>
              </tr>
              <tr v-if="!selTickets.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:22px">No maintenance tickets.</td></tr>
            </tbody>
          </table>

          <table v-else class="kr" style="width:100%">
            <thead><tr><th>Bill</th><th>Unit</th><th>Type</th><th>Month</th><th>Usage</th><th>Amount</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="b in selUtils" :key="b.id">
                <td style="font-weight:700">{{ b.id }}</td><td>{{ unitName(b.unit) }}</td><td>{{ b.type }}</td><td>{{ b.month || '—' }}</td><td>{{ b.usage ?? '—' }}</td><td style="font-weight:700">{{ money(b.amount) }}</td>
                <td><span class="badge" :class="badge(b.status)">{{ b.status }}</span></td>
              </tr>
              <tr v-if="!selUtils.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">No utility bills.</td></tr>
            </tbody>
          </table>
          <div style="height:24px"></div>
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
/* White badges only on cover/header areas (same convention as Properties) */
.t-cover .badge,
.d-cover .badge {
  background: #ffffff;
}
</style>
