<script setup>
import { computed, ref } from 'vue'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'

const data = useDataStore()
const auth = useAuthStore()

// ── RBAC: who can create/edit/delete properties ──
const canManage = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))

const propsAll = computed(() => data.list('properties'))
const unitsAll = computed(() => data.list('units'))
const leasesAll = computed(() => data.list('leases'))
const tenantsAll = computed(() => data.list('tenants'))
const invoicesAll = computed(() => data.list('invoices'))
const ticketsAll = computed(() => data.list('tickets'))
const utilsAll = computed(() => data.list('utility_bills'))

// ── helpers ──
const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
function bigMoney(n) {
  n = n || 0
  if (n >= 1e7) return '৳' + (n / 1e7).toFixed(1).replace(/\.0$/, '') + ' Cr'
  if (n >= 1e5) return '৳' + (n / 1e5).toFixed(1).replace(/\.0$/, '') + ' L'
  return money(n)
}
const tenantName = (tid) => tenantsAll.value.find(t => t.id === tid)?.name || tid || '—'
const unitName = (uid) => unitsAll.value.find(u => u.id === uid)?.name || uid || '—'

function photoUrl(p) {
  if (!p.photo) return ''
  return p.photo.startsWith('/') ? 'https://krtaker.com' + p.photo : p.photo
}
function mapLink(p) {
  if (p.lat && p.lng) return `https://www.google.com/maps?q=${p.lat},${p.lng}`
  if (p.address) return `https://www.google.com/maps?q=${encodeURIComponent(p.address)}`
  return ''
}
function badge(st) {
  const map = { Active: 'b-green', Leased: 'b-green', Paid: 'b-green', Success: 'b-green', Verified: 'b-green', Completed: 'b-green', Approved: 'b-green', Open: 'b-red', Unpaid: 'b-orange', Overdue: 'b-red', Vacant: 'b-gray', Expired: 'b-gray', Terminated: 'b-red', 'In Progress': 'b-blue', Pending: 'b-orange', 'Awaiting Payment': 'b-orange', Rejected: 'b-red' }
  return map[st] || 'b-gray'
}
const TYPE_EMOJI = { Flat: '🏢', Commercial: '🏬', Plot: '🗺️', Industrial: '🏭', Warehouse: '📦', Residential: '🏠' }

// ── per-property aggregates ──
const unitsOf = (p) => unitsAll.value.filter(u => u.p === p.id)
const leasesOf = (p) => { const us = new Set(unitsOf(p).map(u => u.id)); return leasesAll.value.filter(l => us.has(l.u)) }
const invoicesOf = (p) => { const ls = new Set(leasesOf(p).map(l => l.id)); return invoicesAll.value.filter(i => ls.has(i.l)) }
const ticketsOf = (p) => { const us = new Set(unitsOf(p).map(u => u.id)); return ticketsAll.value.filter(t => us.has(t.u)) }
const utilsOf = (p) => { const us = new Set(unitsOf(p).map(u => u.id)); return utilsAll.value.filter(b => us.has(b.unit)) }

function leasedCount(p) { return unitsOf(p).filter(u => String(u.status).toLowerCase() === 'leased').length }
function rentRoll(p) { return unitsOf(p).filter(u => String(u.status).toLowerCase() === 'leased').reduce((s, u) => s + (u.rent || 0), 0) }
function occupancyPct(p) { const u = unitsOf(p); return u.length ? Math.round(leasedCount(p) / u.length * 100) : 0 }
function openTickets(p) { return ticketsOf(p).filter(t => String(t.status).toLowerCase() === 'open').length }
function tenantsOf(p) { return new Set(leasesOf(p).map(l => l.t).filter(Boolean)).size }
function collectionRate(p) {
  const invs = invoicesOf(p)
  if (!invs.length) return null
  const paid = invs.filter(i => String(i.status).toLowerCase() === 'paid').reduce((s, i) => s + (i.net || 0), 0)
  const tot = invs.reduce((s, i) => s + (i.net || 0), 0)
  return tot ? Math.round(paid / tot * 100) : null
}
function leaseDaysLeft(l) {
  if (!l.end) return null
  const d = (new Date(l.end) - Date.now()) / 86400000
  return Math.round(d)
}

// ── global KPIs ──
const kpis = computed(() => {
  const totalSqft = propsAll.value.reduce((s, p) => s + (p.sqft || 0), 0)
  const portValue = propsAll.value.reduce((s, p) => s + (p.value || 0), 0)
  const leased = unitsAll.value.filter(u => String(u.status).toLowerCase() === 'leased').length
  const vacant = unitsAll.value.length - leased
  const rentRoll = unitsAll.value.filter(u => String(u.status).toLowerCase() === 'leased').reduce((s, u) => s + (u.rent || 0), 0)
  const open = ticketsAll.value.filter(t => String(t.status).toLowerCase() === 'open').length
  return [
    { label: 'Properties', ico: '🏢', value: propsAll.value.length, trend: `${propsAll.value.filter(p => p.status === 'Active').length} active` },
    { label: 'Total area', ico: '📐', value: totalSqft.toLocaleString('en-IN') + ' sqft', trend: 'across portfolio' },
    { label: 'Portfolio value', ico: '💎', value: bigMoney(portValue), trend: 'current market value' },
    { label: 'Units', ico: '🚪', value: `${leased} / ${unitsAll.value.length}`, trend: `${vacant} vacant`, ok: vacant === 0 },
    { label: 'Rent roll / mo', ico: '💵', value: money(rentRoll), trend: 'leased units' },
    { label: 'Open tickets', ico: '🔧', value: open, trend: open ? 'need attention' : 'all clear', ok: open === 0 },
  ]
})

// ── filters + sort ──
const query = ref('')
const typeFilter = ref('')
const jurFilter = ref('')
const statusFilter = ref('')
const sortBy = ref('name')

const typeOptions = computed(() => [...new Set(propsAll.value.map(p => p.type).filter(Boolean))].sort())
const jurOptions = computed(() => [...new Set(propsAll.value.map(p => p.jur).filter(Boolean))].sort())
const statusOptions = computed(() => [...new Set(propsAll.value.map(p => p.status).filter(Boolean))].sort())

const filtered = computed(() => {
  let out = propsAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(p => JSON.stringify(p).toLowerCase().includes(q))
  if (typeFilter.value) out = out.filter(p => p.type === typeFilter.value)
  if (jurFilter.value) out = out.filter(p => p.jur === jurFilter.value)
  if (statusFilter.value) out = out.filter(p => p.status === statusFilter.value)
  const key = sortBy.value
  const get = (p) => key === 'rentRoll' ? rentRoll(p) : key === 'units' ? unitsOf(p).length : p[key] || 0
  return [...out].sort((a, b) => (typeof get(a) === 'string' ? String(get(a)).localeCompare(String(get(b))) : get(a) - get(b)))
})

function exportCsv() {
  const rows = filtered.value
  if (!rows.length) return
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob); a.download = 'properties.csv'; a.click()
  URL.revokeObjectURL(a.href)
}

// ── drawer (detail) ──
const sel = ref(null)
const tab = ref('units')
function openDetail(p) { sel.value = p; tab.value = 'units' }
function closeDetail() { sel.value = null }
const detailTabs = [
  { id: 'units', label: 'Units', ico: '🚪' },
  { id: 'leases', label: 'Leases', ico: '📄' },
  { id: 'invoices', label: 'Invoices', ico: '🧾' },
  { id: 'tickets', label: 'Tickets', ico: '🔧' },
  { id: 'utils', label: 'Utilities', ico: '🔌' },
]
const selUnits = computed(() => sel.value ? unitsOf(sel.value) : [])
const selLeases = computed(() => sel.value ? leasesOf(sel.value) : [])
const selInvoices = computed(() => sel.value ? invoicesOf(sel.value) : [])
const selTickets = computed(() => sel.value ? ticketsOf(sel.value) : [])
const selUtils = computed(() => sel.value ? utilsOf(sel.value) : [])
const selStats = computed(() => {
  if (!sel.value) return []
  const p = sel.value
  const invs = invoicesOf(p)
  const paidNet = invs.filter(i => String(i.status).toLowerCase() === 'paid').reduce((s, i) => s + (i.net || 0), 0)
  const totNet = invs.reduce((s, i) => s + (i.net || 0), 0)
  const openCost = ticketsOf(p).filter(t => String(t.status).toLowerCase() === 'open').reduce((s, t) => s + (t.cost || 0), 0)
  return [
    { label: 'Units', v: unitsOf(p).length },
    { label: 'Occupancy', v: occupancyPct(p) + '%' },
    { label: 'Rent roll', v: money(rentRoll(p)) + '/mo' },
    { label: 'Invoiced', v: money(totNet) },
    { label: 'Collected', v: money(paidNet) },
    { label: 'Open tickets', v: ticketsOf(p).filter(t => String(t.status).toLowerCase() === 'open').length },
    { label: 'Open cost', v: money(openCost) },
    { label: 'Lease expiries', v: selLeases.value.filter(l => leaseDaysLeft(l) !== null && leaseDaysLeft(l) <= 90).length },
  ]
})

// ── add / edit / delete ──
const modal = ref(null) // null | {mode:'add'} | {mode:'edit', p}
const form = ref({})
const saving = ref(false)
const formErr = ref('')

function openAdd() {
  form.value = { name: '', type: 'Flat', jur: 'Dhaka North', holding: '', sqft: '', value: '', status: 'Active', address: '', description: '', featured: false, published: true }
  formErr.value = ''; modal.value = { mode: 'add' }
}
function openEdit(p) {
  form.value = { name: p.name || '', type: p.type || 'Flat', jur: p.jur || 'Dhaka North', holding: p.holding || '', sqft: p.sqft || '', value: p.value || '', status: p.status || 'Active', address: p.address || '', description: p.description || '', featured: String(p.featured) === '1', published: String(p.published) === '1' }
  formErr.value = ''; modal.value = { mode: 'edit', p }
}
function closeModal() { modal.value = null; formErr.value = '' }

async function submitForm() {
  if (!form.value.name.trim()) { formErr.value = 'Property name is required.'; return }
  formErr.value = ''
  saving.value = true
  try {
    const payload = {
      name: form.value.name.trim(),
      type: form.value.type,
      jur: form.value.jur,
      holding: form.value.holding.trim(),
      sqft: form.value.sqft ? Number(form.value.sqft) : 0,
      value: form.value.value ? Number(form.value.value) : 0,
      status: form.value.status,
      address: form.value.address.trim(),
      description: form.value.description.trim(),
      featured: form.value.featured ? '1' : '0',
      published: form.value.published ? 1 : 0,
    }
    const r = await apiCall('app-crud', {
      action: modal.value.mode === 'edit' ? 'update' : 'create',
      collection: 'properties',
      ...(modal.value.mode === 'edit' ? { id: modal.value.p.id } : {}),
      data: payload,
    })
    if (r.ok) {
      window.__krToast?.(modal.value.mode === 'edit' ? `✏️ ${modal.value.p.id} updated` : '✅ Property created', 'ok')
      closeModal()
      await data.bootstrap()
      if (modal.value?.p) openDetail(data.list('properties').find(x => x.id === modal.value.p.id))
    } else {
      formErr.value = r.error || 'Save failed.'
    }
  } finally { saving.value = false }
}

async function delProperty(p) {
  if (!confirm(`Delete ${p.name} (${p.id})? This cannot be undone.`)) return
  const r = await apiCall('app-crud', { action: 'delete', collection: 'properties', id: p.id, data: {} })
  if (r.ok) {
    window.__krToast?.(`🗑️ ${p.id} deleted`, 'ok')
    if (sel.value?.id === p.id) closeDetail()
    await data.bootstrap()
  } else {
    window.__krToast?.(r.error || 'Delete failed', 'error')
  }
}

async function togglePublish(p) {
  const next = String(p.published) === '1' ? 0 : 1
  const r = await apiCall('app-crud', { action: 'update', collection: 'properties', id: p.id, data: { published: next } })
  if (r.ok) { p.published = next; window.__krToast?.(next ? '👁 Published' : '🙈 Unpublished', 'ok') }
  else window.__krToast?.(r.error || 'Update failed', 'error')
}
async function toggleFeatured(p) {
  const next = String(p.featured) === '1' ? '0' : '1'
  const r = await apiCall('app-crud', { action: 'update', collection: 'properties', id: p.id, data: { featured: next } })
  if (r.ok) { p.featured = next; window.__krToast?.(next === '1' ? '⭐ Featured' : 'Unfeatured', 'ok') }
  else window.__krToast?.(r.error || 'Update failed', 'error')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🏢 Properties</h1>
        <div class="sub">{{ propsAll.length }} properties · {{ unitsAll.length }} units · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search name, address, holding…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:230px">
        <select v-model="typeFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All types</option>
          <option v-for="t in typeOptions" :key="t" :value="t">{{ t }}</option>
        </select>
        <select v-model="jurFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All jurisdictions</option>
          <option v-for="j in jurOptions" :key="j" :value="j">{{ j }}</option>
        </select>
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="sortBy" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="name">Sort: Name</option>
          <option value="value">Sort: Value</option>
          <option value="sqft">Sort: Area</option>
          <option value="rentRoll">Sort: Rent roll</option>
          <option value="units">Sort: Units</option>
        </select>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
        <button v-if="canManage" @click="openAdd" class="btn-primary" style="padding:9px 16px">＋ New property</button>
      </div>
    </div>

    <!-- KPI row -->
    <div class="stats">
      <div v-for="k in kpis" :key="k.label" class="stat">
        <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
        <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
        <div class="s-trend">{{ k.trend }}</div>
      </div>
    </div>

    <!-- property cards -->
    <div v-if="filtered.length" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px">
      <div v-for="p in filtered" :key="p.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(p)">
        <!-- cover -->
        <div style="height:110px;position:relative;background:var(--grad)">
          <img v-if="photoUrl(p)" :src="photoUrl(p)" alt="" loading="lazy"
               style="width:100%;height:100%;object-fit:cover" @error="$event.target.style.display='none'">
          <div v-if="!photoUrl(p)" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:40px">{{ TYPE_EMOJI[p.type] || '🏢' }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="badge(p.status)">{{ p.status }}</span>
            <span v-if="String(p.featured) === '1'" class="badge b-purple">⭐ Featured</span>
            <span v-if="String(p.published) === '0'" class="badge b-gray">🙈 Hidden</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ p.id }}</div>
        </div>
        <!-- body -->
        <div style="padding:14px 16px;flex:1;display:flex;flex-direction:column;gap:10px">
          <div>
            <div style="font-weight:800;font-size:15.5px;letter-spacing:-.2px">{{ p.name }}</div>
            <div class="c-sub" style="margin-top:2px">{{ TYPE_EMOJI[p.type] || '' }} {{ p.type }} · {{ p.jur }}<template v-if="p.holding"> · {{ p.holding }}</template></div>
          </div>
          <div style="display:flex;gap:14px;font-size:12px">
            <span class="c-sub" title="Area">📐 {{ (p.sqft || 0).toLocaleString('en-IN') }} sqft</span>
            <span class="c-sub" title="Value">💎 {{ bigMoney(p.value) }}</span>
            <span class="c-sub" title="Rent roll">💵 {{ money(rentRoll(p)) }}/mo</span>
          </div>
          <!-- occupancy bar -->
          <div>
            <div style="display:flex;justify-content:space-between;font-size:11px;font-weight:700;margin-bottom:4px">
              <span class="c-sub">{{ leasedCount(p) }} / {{ unitsOf(p).length }} units leased</span>
              <span :style="occupancyPct(p) >= 70 ? 'color:var(--ok)' : occupancyPct(p) >= 40 ? 'color:var(--warn)' : 'color:var(--danger)'">{{ occupancyPct(p) }}%</span>
            </div>
            <div style="height:5px;border-radius:3px;background:var(--bg-alt);overflow:hidden">
              <div :style="`width:${occupancyPct(p)}%;height:100%;border-radius:3px;background:${occupancyPct(p) >= 70 ? 'var(--ok)' : occupancyPct(p) >= 40 ? 'var(--warn)' : 'var(--danger)'}`"></div>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
            <span v-if="openTickets(p)" class="badge b-red">🔧 {{ openTickets(p) }} open</span>
            <span v-if="collectionRate(p) !== null" class="badge" :class="collectionRate(p) >= 70 ? 'b-green' : 'b-orange'">💳 {{ collectionRate(p) }}% collected</span>
            <span class="badge b-blue">{{ leasesOf(p).length }} leases</span>
            <span class="badge b-gray">{{ tenantsOf(p) }} tenants</span>
          </div>
          <div v-if="canManage" style="display:flex;gap:6px;border-top:1px solid var(--border);padding-top:10px">
            <button class="btn-ghost" style="flex:1;justify-content:center;padding:6px 10px;font-size:12px" @click.stop="togglePublish(p)">{{ String(p.published) === '1' ? '🙈 Unpublish' : '👁 Publish' }}</button>
            <button class="btn-ghost" style="flex:1;justify-content:center;padding:6px 10px;font-size:12px" @click.stop="toggleFeatured(p)">{{ String(p.featured) === '1' ? '☆ Unfeature' : '⭐ Feature' }}</button>
            <button class="btn-ghost" style="padding:6px 10px;font-size:12px" @click.stop="openEdit(p)">✏️</button>
            <button class="btn-ghost" style="padding:6px 10px;font-size:12px;color:var(--danger)" @click.stop="delProperty(p)">🗑️</button>
          </div>
        </div>
      </div>
    </div>
    <div v-else class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">
      No properties found{{ query ? ' for “' + query + '”' : '' }}.
    </div>

    <!-- detail drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(640px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <!-- header -->
        <div style="height:130px;background:var(--grad);position:relative;flex-shrink:0">
          <img v-if="photoUrl(sel)" :src="photoUrl(sel)" alt="" style="width:100%;height:100%;object-fit:cover" @error="$event.target.style.display='none'">
          <div v-if="!photoUrl(sel)" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:52px">{{ TYPE_EMOJI[sel.type] || '🏢' }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="badge(sel.status)">{{ sel.status }}</span>
            <span v-if="String(sel.featured) === '1'" class="badge b-purple">⭐ Featured</span>
            <span v-if="String(sel.published) === '1'" class="badge b-green">👁 Published</span>
            <span v-else class="badge b-gray">🙈 Hidden</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.name }}</h2>
          <div class="c-sub" style="margin-top:3px">{{ sel.id }} · {{ TYPE_EMOJI[sel.type] || '' }} {{ sel.type }} · {{ sel.jur }}<template v-if="sel.holding"> · {{ sel.holding }}</template></div>
          <div v-if="sel.address" class="c-sub" style="margin-top:2px">📍 {{ sel.address }}</div>
          <div v-if="mapLink(sel)" style="margin-top:6px"><a :href="mapLink(sel)" target="_blank" rel="noopener" style="font-size:12.5px;font-weight:700">🗺️ Open in Google Maps →</a></div>

          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin:16px 0">
            <div v-for="s in selStats" :key="s.label" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ s.label }}</div>
              <div style="font-size:15.5px;font-weight:800;margin-top:2px">{{ s.v }}</div>
            </div>
          </div>

          <p v-if="sel.description" style="font-size:13px;color:var(--text);line-height:1.6;margin:0 0 16px">{{ sel.description }}</p>

          <!-- tabs -->
          <div style="display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:14px;flex-wrap:wrap">
            <button v-for="t in detailTabs" :key="t.id" @click="tab = t.id"
              style="padding:9px 14px;border:none;background:none;font-size:13px;font-weight:700;cursor:pointer;border-bottom:2px solid transparent;color:var(--text-mute)"
              :style="tab === t.id ? 'color:var(--primary);border-bottom-color:var(--primary)' : ''">
              {{ t.ico }} {{ t.label }} <span style="opacity:.7">({{ t.id === 'units' ? selUnits.length : t.id === 'leases' ? selLeases.length : t.id === 'invoices' ? selInvoices.length : t.id === 'tickets' ? selTickets.length : selUtils.length }})</span>
            </button>
          </div>

          <!-- units tab -->
          <table v-if="tab === 'units'" class="kr" style="width:100%">
            <thead><tr><th>Unit</th><th>Floor</th><th>sqft</th><th>Rent</th><th>Status</th><th>Tenant</th><th>Lease ends</th></tr></thead>
            <tbody>
              <tr v-for="u in selUnits" :key="u.id">
                <td style="font-weight:700">{{ u.name }}</td>
                <td>{{ u.floor || '—' }}</td>
                <td>{{ (u.sqft || 0).toLocaleString('en-IN') }}</td>
                <td style="font-weight:700">{{ money(u.rent) }}</td>
                <td><span class="badge" :class="badge(u.status)">{{ u.status }}</span></td>
                <td>{{ tenantName(selLeases.find(l => l.u === u.id)?.t) }}</td>
                <td>{{ selLeases.find(l => l.u === u.id)?.end || '—' }}</td>
              </tr>
              <tr v-if="!selUnits.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">No units for this property.</td></tr>
            </tbody>
          </table>

          <!-- leases tab -->
          <table v-else-if="tab === 'leases'" class="kr" style="width:100%">
            <thead><tr><th>Lease</th><th>Unit</th><th>Tenant</th><th>Rent</th><th>Start</th><th>End</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="l in selLeases" :key="l.id">
                <td style="font-weight:700">{{ l.id }}</td>
                <td>{{ unitName(l.u) }}</td>
                <td>{{ tenantName(l.t) }}</td>
                <td style="font-weight:700">{{ money(l.rent) }}/mo</td>
                <td>{{ l.start || '—' }}</td>
                <td>{{ l.end || '—' }} <span v-if="leaseDaysLeft(l) !== null && l.status === 'Active'" class="c-sub">({{ leaseDaysLeft(l) }}d)</span></td>
                <td><span class="badge" :class="badge(l.status)">{{ l.status }}</span></td>
              </tr>
              <tr v-if="!selLeases.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">No leases for this property.</td></tr>
            </tbody>
          </table>

          <!-- invoices tab -->
          <table v-else-if="tab === 'invoices'" class="kr" style="width:100%">
            <thead><tr><th>Invoice</th><th>Month</th><th>Lease</th><th>Gross</th><th>TDS</th><th>Net</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="i in selInvoices" :key="i.id">
                <td style="font-weight:700">{{ i.id }}</td>
                <td>{{ i.m || '—' }}</td>
                <td>{{ i.l }}</td>
                <td>{{ money(i.gross) }}</td>
                <td>{{ money(i.tds) }}</td>
                <td style="font-weight:700">{{ money(i.net) }}</td>
                <td><span class="badge" :class="badge(i.status)">{{ i.status }}</span></td>
              </tr>
              <tr v-if="!selInvoices.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">No invoices for this property.</td></tr>
            </tbody>
          </table>

          <!-- tickets tab -->
          <table v-else-if="tab === 'tickets'" class="kr" style="width:100%">
            <thead><tr><th>Ticket</th><th>Unit</th><th>Issue</th><th>Reported</th><th>Liability</th><th>Cost</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="t in selTickets" :key="t.id">
                <td style="font-weight:700">{{ t.id }}</td>
                <td>{{ unitName(t.u) }}</td>
                <td>{{ t.desc }}</td>
                <td>{{ t.reported || '—' }}</td>
                <td>{{ t.liab || '—' }}</td>
                <td>{{ money(t.cost) }}</td>
                <td><span class="badge" :class="badge(t.status)">{{ t.status }}</span></td>
              </tr>
              <tr v-if="!selTickets.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">No maintenance tickets.</td></tr>
            </tbody>
          </table>

          <!-- utilities tab -->
          <table v-else class="kr" style="width:100%">
            <thead><tr><th>Bill</th><th>Unit</th><th>Type</th><th>Month</th><th>Usage</th><th>Amount</th><th>Status</th></tr></thead>
            <tbody>
              <tr v-for="b in selUtils" :key="b.id">
                <td style="font-weight:700">{{ b.id }}</td>
                <td>{{ unitName(b.unit) }}</td>
                <td>{{ b.type }}</td>
                <td>{{ b.month || '—' }}</td>
                <td>{{ b.usage ?? '—' }}</td>
                <td style="font-weight:700">{{ money(b.amount) }}</td>
                <td><span class="badge" :class="badge(b.status)">{{ b.status }}</span></td>
              </tr>
              <tr v-if="!selUtils.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">No utility bills.</td></tr>
            </tbody>
          </table>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>

    <!-- add / edit modal -->
    <template v-if="modal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="closeModal"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(520px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto">
        <div style="padding:20px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:16px;font-weight:800">{{ modal.mode === 'edit' ? '✏️ Edit ' + modal.p.id : '＋ New property' }}</h3>
          <button @click="closeModal" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:grid;grid-template-columns:1fr 1fr;gap:13px">
          <div style="grid-column:1/-1">
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Name *</label>
            <input v-model="form.name" placeholder="e.g. Green View Residency" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Type</label>
            <select v-model="form.type" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option v-for="t in ['Flat','Commercial','Plot','Industrial','Warehouse','Residential']" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Jurisdiction</label>
            <select v-model="form.jur" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option v-for="j in [...new Set([...jurOptions, 'Dhaka North', 'Dhaka South', 'Chattogram'])]" :key="j" :value="j">{{ j }}</option>
            </select>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Holding / plot no</label>
            <input v-model="form.holding" placeholder="e.g. 12/5, Mirpur-10" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Area (sqft)</label>
            <input v-model="form.sqft" type="number" min="0" placeholder="18000" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Market value (৳)</label>
            <input v-model="form.value" type="number" min="0" placeholder="82000000" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div style="grid-column:1/-1">
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Address</label>
            <input v-model="form.address" placeholder="Full address" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div style="grid-column:1/-1">
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Description</label>
            <textarea v-model="form.description" rows="3" placeholder="Short description" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;resize:vertical"></textarea>
          </div>
          <div style="grid-column:1/-1;display:flex;gap:18px">
            <label style="display:flex;align-items:center;gap:7px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" v-model="form.published" style="accent-color:var(--primary)"> Published on listing</label>
            <label style="display:flex;align-items:center;gap:7px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" v-model="form.featured" style="accent-color:var(--primary)"> ⭐ Featured</label>
          </div>
          <div v-if="formErr" style="grid-column:1/-1;color:var(--danger);font-size:12.5px;font-weight:600">{{ formErr }}</div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="closeModal">Cancel</button>
          <button class="btn-primary" :disabled="saving" @click="submitForm" style="padding:9px 18px">{{ saving ? 'Saving…' : modal.mode === 'edit' ? 'Save changes' : 'Create property' }}</button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* Property card badges: white background (keep colored text from .b-* classes) */
.panel.chip .badge {
  background: #ffffff;
}
</style>
