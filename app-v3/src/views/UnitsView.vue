<script setup>
import { computed, ref, watch } from 'vue'
import { lang, t } from '../lib/i18n'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { badge, useViewMode, usePager } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'
import ScrollTabs from '../components/ScrollTabs.vue'
import ImportWizard from '../components/ImportWizard.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('units')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))

const unitsAll = computed(() => data.list('units'))
const propsAll = computed(() => data.list('properties'))
const leasesAll = computed(() => data.list('leases'))
const tenantsAll = computed(() => data.list('tenants'))
const invoicesAll = computed(() => data.list('invoices'))
const ticketsAll = computed(() => data.list('tickets'))
const utilsAll = computed(() => data.list('utility_bills'))

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const propName = (pid) => propsAll.value.find(p => p.id === pid)?.name || pid || '—'
const tenantName = (tid) => tenantsAll.value.find(t => t.id === tid)?.name || tid || '—'
const propType = (pid) => propsAll.value.find(p => p.id === pid)?.type || ''
const UNIT_EMOJI = { Flat: '🏢', Commercial: '🏬', Plot: '🗺️', Industrial: '🏭', Warehouse: '📦', Residential: '🏠' }

// ── joins ──
const leasesOfUnit = (u) => leasesAll.value.filter(l => l.u === u.id)
const activeLease = (u) => leasesOfUnit(u).find(l => String(l.status).toLowerCase() === 'active') || leasesOfUnit(u)[0]
const selLeaseFor = (u) => activeLease(u)
const invoicesOfUnit = (u) => { const ls = new Set(leasesOfUnit(u).map(l => l.id)); return invoicesAll.value.filter(i => ls.has(i.l)) }
const ticketsOfUnit = (u) => ticketsAll.value.filter(t => t.u === u.id)
const utilsOfUnit = (u) => utilsAll.value.filter(b => b.unit === u.id)
function leaseDaysLeft(l) { if (!l?.end) return null; return Math.round((new Date(l.end) - Date.now()) / 86400000) }
function collectionRateUnit(u) {
  const invs = invoicesOfUnit(u); if (!invs.length) return null
  const paid = invs.filter(i => String(i.status).toLowerCase() === 'paid').reduce((s, i) => s + (i.net || 0), 0)
  const tot = invs.reduce((s, i) => s + (i.net || 0), 0)
  return tot ? Math.round(paid / tot * 100) : null
}

// ── KPIs ──
const kpis = computed(() => {
  const leased = unitsAll.value.filter(u => String(u.status).toLowerCase() === 'leased').length
  const vacant = unitsAll.value.filter(u => String(u.status).toLowerCase() === 'vacant').length
  const maint = unitsAll.value.filter(u => String(u.status).toLowerCase() === 'maintenance').length
  const occ = unitsAll.value.length ? Math.round(leased / unitsAll.value.length * 100) : 0
  const rentRoll = unitsAll.value.filter(u => String(u.status).toLowerCase() === 'leased').reduce((s, u) => s + (u.rent || 0), 0)
  const avgSqft = unitsAll.value.length ? Math.round(unitsAll.value.reduce((s, u) => s + (u.sqft || 0), 0) / unitsAll.value.length) : 0
  const open = ticketsAll.value.filter(t => String(t.status).toLowerCase() === 'open').length
  return [
    { label: 'Units', ico: '🚪', value: unitsAll.value.length, trend: `${propsAll.value.length} properties` },
    { label: 'Occupancy', ico: '📈', value: leased + ' / ' + unitsAll.value.length, trend: occ + '% leased', ok: occ >= 70 },
    { label: 'Vacant', ico: '🈳', value: vacant, trend: maint ? maint + t(' in maintenance') : t('all clear'), ok: vacant === 0 },
    { label: t('Rent roll / mo'), ico: '💵', value: money(rentRoll), trend: 'leased units' },
    { label: t('Avg size'), ico: '📐', value: avgSqft.toLocaleString('en-IN') + ' sqft', trend: t('per unit') },
    { label: t('Open tickets'), ico: '🔧', value: open, trend: open ? t('need attention') : t('all clear'), ok: open === 0 },
  ]
})

// ── filters / sort ──
const query = ref('')
const propFilter = ref('')
const statusFilter = ref('')
const sortBy = ref('name')
const propOptions = computed(() => propsAll.value.map(p => ({ id: p.id, name: p.name })))
const statusOptions = computed(() => [...new Set(unitsAll.value.map(u => u.status).filter(Boolean))].sort())

const filtered = computed(() => {
  let out = unitsAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(u => JSON.stringify(u).toLowerCase().includes(q) || propName(u.p).toLowerCase().includes(q) || tenantName(activeLease(u)?.t).toLowerCase().includes(q))
  if (propFilter.value) out = out.filter(u => u.p === propFilter.value)
  if (statusFilter.value) out = out.filter(u => u.status === statusFilter.value)
  const get = (u) => sortBy.value === 'rent' ? (u.rent || 0) : sortBy.value === 'sqft' ? (u.sqft || 0) : sortBy.value === 'property' ? propName(u.p) : (u.name || '')
  return [...out].sort((a, b) => typeof get(a) === 'string' ? String(get(a)).localeCompare(String(get(b))) : get(a) - get(b))
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
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'units.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
const tab = ref('lease')
function openDetail(u) { sel.value = u; tab.value = 'lease' }
function closeDetail() { sel.value = null }
// deep link: /units?open=U-001
watch(() => route.query.open, (id) => {
  if (id) { const u = unitsAll.value.find(x => x.id === id); if (u) openDetail(u) }
}, { immediate: true })
// GO-LIVE 4.1: /units?import=1 opens the CSV import wizard directly (empty-state CTA)
// deferred: showImport const is initialized later in setup, so an immediate call would throw
watch(() => route.query.import, (v) => {
  if (String(v) === '1') setTimeout(() => { showImport.value = true }, 0)
}, { immediate: true })
const selLease = computed(() => sel.value ? activeLease(sel.value) : null)
const selInvoices = computed(() => sel.value ? invoicesOfUnit(sel.value) : [])
const selTickets = computed(() => sel.value ? ticketsOfUnit(sel.value) : [])
const selUtils = computed(() => sel.value ? utilsOfUnit(sel.value) : [])
const selStats = computed(() => {
  if (!sel.value) return []
  const invs = selInvoices.value
  const paid = invs.filter(i => String(i.status).toLowerCase() === 'paid').reduce((s, i) => s + (i.net || 0), 0)
  const tot = invs.reduce((s, i) => s + (i.net || 0), 0)
  return [
    { label: 'Property', v: propName(sel.value.p) },
    { label: 'Floor', v: sel.value.floor || '—' },
    { label: 'Area', v: (sel.value.sqft || 0).toLocaleString('en-IN') + ' sqft' },
    { label: 'Rent', v: money(sel.value.rent) + '/mo' },
    { label: 'Tenant', v: selLease.value ? tenantName(selLease.value.t) : '—' },
    { label: 'Lease', v: selLease.value ? selLease.value.id : '—' },
    { label: 'Invoiced', v: money(tot) },
    { label: 'Collected', v: money(paid) },
  ]
})

// ── CRUD ──
const modal = ref(null)
const form = ref({})
const saving = ref(false)
const formErr = ref('')
const furnOpts = ['', '0', '1']

// ── CSV import (GO-LIVE 4.1) ──
const showImport = ref(false)
function openImport() { showImport.value = true }
function refreshAll() { data.bootstrap() }

function openAdd() {
  form.value = { name: '', p: propsAll.value[0]?.id || '', floor: '', sqft: '', rent: '', status: 'Vacant', beds: '', baths: '', furnished: '' }
  formErr.value = ''; modal.value = { mode: 'add' }
}
function openEdit(u) {
  form.value = { name: u.name || '', p: u.p || '', floor: u.floor || '', sqft: u.sqft || '', rent: u.rent || '', status: u.status || 'Vacant', beds: u.beds || '', baths: u.baths || '', furnished: u.furnished || '' }
  formErr.value = ''; modal.value = { mode: 'edit', u }
}
function closeModal() { modal.value = null; formErr.value = '' }
async function submitForm() {
  if (!form.value.name.trim() || !form.value.p) { formErr.value = 'Unit name and property are required.'; return }
  formErr.value = ''; saving.value = true
  try {
    const payload = {
      name: form.value.name.trim(), p: form.value.p, floor: form.value.floor.trim(),
      sqft: form.value.sqft ? Number(form.value.sqft) : 0, rent: form.value.rent ? Number(form.value.rent) : 0,
      status: form.value.status, beds: form.value.beds, baths: form.value.baths, furnished: form.value.furnished,
    }
    const r = await apiCall('app-crud', {
      action: modal.value.mode === 'edit' ? 'update' : 'create', collection: 'units',
      ...(modal.value.mode === 'edit' ? { id: modal.value.u.id } : {}), data: payload,
    })
    if (r.ok) {
      window.__krToast?.(modal.value.mode === 'edit' ? `✏️ ${modal.value.u.id} updated` : '✅ Unit created', 'ok')
      closeModal(); await data.bootstrap()
    } else formErr.value = r.error || t('Save failed.')
  } finally { saving.value = false }
}
async function delUnit(u) {
  if (!confirm(`Delete ${u.name} (${u.id})? This cannot be undone.`)) return
  const r = await apiCall('app-crud', { action: 'delete', collection: 'units', id: u.id, data: {} })
  if (r.ok) { window.__krToast?.(`🗑️ ${u.id} deleted`, 'ok'); if (sel.value?.id === u.id) closeDetail(); await data.bootstrap() }
  else window.__krToast?.(r.error || t('Delete failed'), 'error')
}
async function setStatus(u, st) {
  const r = await apiCall('app-crud', { action: 'update', collection: 'units', id: u.id, data: { status: st } })
  if (r.ok) { u.status = st; window.__krToast?.(`${u.id} → ${st}`, 'ok') }
  else window.__krToast?.(r.error || t('Update failed'), 'error')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('🚪 Units') }}</h1>
        <div class="sub">{{ unitsAll.length }} units · {{ propsAll.length }} properties · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" :placeholder="t('Search unit, property, tenant…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="propFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All properties') }}</option>
          <option v-for="p in propOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All statuses') }}</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="sortBy" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="name">{{ t('Sort: Name') }}</option>
          <option value="property">{{ t('Sort: Property') }}</option>
          <option value="rent">{{ t('Sort: Rent') }}</option>
          <option value="sqft">{{ t('Sort: Area') }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" :title="t('Download CSV')">⬇ CSV</button>
      </CompactFilters>
        <button v-if="canManage" @click="openAdd" class="btn-primary" style="padding:9px 16px" :title="t('Add a new unit (flat/shop) to a property')">＋ New unit</button>
        <button v-if="canManage" @click="openImport" class="btn-ghost" style="padding:9px 16px;font-weight:700" :title="t('Bulk import units from CSV')">⬆ Import</button>
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
      <div v-for="u in paged" :key="u.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(u)">
        <div class="u-cover" style="height:88px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:34px">{{ UNIT_EMOJI[propType(u.p)] || '🚪' }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="badge(u.status)">{{ u.status }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ u.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div>
            <div style="font-weight:800;font-size:15px;letter-spacing:-.2px">{{ u.name }}</div>
            <div class="c-sub" style="margin-top:2px">🏢 {{ propName(u.p) }}<template v-if="u.floor && u.floor !== '—'"> · {{ u.floor }} floor</template></div>
          </div>
          <div style="display:flex;gap:13px;font-size:12px">
            <span class="c-sub" :title="t('Area')">📐 {{ (u.sqft || 0).toLocaleString('en-IN') }} sqft</span>
            <span class="c-sub" :title="t('Rent')">💵 {{ money(u.rent) }}/mo</span>
            <span v-if="u.beds && u.beds !== '0'" class="c-sub" :title="t('Beds')">🛏️ {{ u.beds }}</span>
            <span v-if="u.baths && u.baths !== '0'" class="c-sub" :title="t('Baths')">🛁 {{ u.baths }}</span>
            <span v-if="String(u.furnished) === '1'" class="c-sub" :title="t('Furnished')">🛋️</span>
          </div>
          <div v-if="selLeaseFor(u)" style="font-size:12px;display:flex;justify-content:space-between;align-items:center;background:var(--bg-alt);border:1px solid var(--border);border-radius:9px;padding:7px 10px">
            <span>👤 {{ tenantName(selLeaseFor(u).t) }}</span>
            <span v-if="selLeaseFor(u).end" class="c-sub">{{ selLeaseFor(u).end }}<template v-if="selLeaseFor(u).status === 'Active'"> ({{ leaseDaysLeft(selLeaseFor(u)) }}d)</template></span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
            <span v-if="collectionRateUnit(u) !== null" class="badge" :class="collectionRateUnit(u) >= 70 ? 'b-green' : 'b-orange'">💳 {{ collectionRateUnit(u) }}%</span>
            <span v-if="ticketsOfUnit(u).length" class="badge" :class="ticketsOfUnit(u).some(t => String(t.status).toLowerCase() === 'open') ? 'b-red' : 'b-gray'">🔧 {{ ticketsOfUnit(u).length }}</span>
          </div>
          <div v-if="canManage" style="display:flex;gap:6px;border-top:1px solid var(--border);padding-top:9px">
            <button class="btn-ghost" style="flex:1;justify-content:center;padding:6px 10px;font-size:12px" @click.stop="setStatus(u, String(u.status).toLowerCase() === 'leased' ? 'Vacant' : 'Leased')">{{ String(u.status).toLowerCase() === 'leased' ? '🈳 Mark vacant' : '📌 Mark leased' }}</button>
            <button class="btn-ghost" style="padding:6px 10px;font-size:12px" @click.stop="openEdit(u)">✏️</button>
            <button class="btn-ghost" style="padding:6px 10px;font-size:12px;color:var(--danger)" @click.stop="delUnit(u)">🗑️</button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>{{ t('Unit') }}</th><th>{{ t('Property') }}</th><th>{{ t('Status') }}</th><th>{{ t('Rent / mo') }}</th><th>{{ t('Tenant') }}</th><th>{{ t('Lease end') }}</th><th>{{ t('Collection') }}</th><th>{{ t('Tickets') }}</th><th v-if="canManage">{{ t('Actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="u in paged" :key="u.id" style="cursor:pointer" @click="openDetail(u)">
              <td style="white-space:nowrap"><b>{{ u.name }}</b> <span class="c-sub">{{ u.id }}</span><template v-if="u.floor && u.floor !== '—'"> · {{ u.floor }} floor</template></td>
              <td style="white-space:nowrap" class="c-sub">🏢 {{ propName(u.p) }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="badge(u.status)">{{ u.status }}</span></td>
              <td style="font-weight:700;white-space:nowrap">{{ money(u.rent) }}</td>
              <td style="white-space:nowrap">{{ selLeaseFor(u) ? tenantName(selLeaseFor(u).t) : '—' }}</td>
              <td style="white-space:nowrap">{{ selLeaseFor(u)?.end || '—' }}<template v-if="selLeaseFor(u)?.end && selLeaseFor(u).status === 'Active'"> ({{ leaseDaysLeft(selLeaseFor(u)) }}d)</template></td>
              <td style="white-space:nowrap">{{ collectionRateUnit(u) !== null ? collectionRateUnit(u) + '%' : '—' }}</td>
              <td style="white-space:nowrap">{{ ticketsOfUnit(u).length ? '🔧 ' + ticketsOfUnit(u).length : '—' }}</td>
              <td v-if="canManage" style="white-space:nowrap">
                <button class="btn-ghost" style="padding:4px 9px;font-size:11px" @click.stop="setStatus(u, String(u.status).toLowerCase() === 'leased' ? 'Vacant' : 'Leased')">{{ String(u.status).toLowerCase() === 'leased' ? '🈳' : '📌' }}</button>
                <button class="btn-ghost" style="padding:4px 9px;font-size:11px" @click.stop="openEdit(u)">✏️</button>
                <button class="btn-ghost" style="padding:4px 9px;font-size:11px;color:var(--danger)" @click.stop="delUnit(u)">🗑️</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">
      <div style="font-size:44px;margin-bottom:10px">🚪</div>
      <div style="font-size:17px;font-weight:800;color:var(--text);margin-bottom:6px">{{ lang === 'bn' ? 'এখনো কোনো ইউনিট নেই' : 'No units yet' }}</div>
      <div style="font-size:13px;max-width:420px;margin:0 auto 18px;line-height:1.6">{{ query ? (lang === 'bn' ? `“${query}” এর জন্য কিছু পাওয়া যায়নি` : `Nothing found for “${query}”`) : (lang === 'bn' ? 'প্রপার্টি নির্বাচন করে প্রথম ইউনিট যোগ করুন — ভাড়া, ফ্লোর ও মিটার যুক্ত থাকবে।' : 'Add your first unit to a property — attach rent, floor and meters as you go.') }}</div>
      <div v-if="canManage" style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center">
        <button @click="openAdd" class="btn-primary" style="padding:10px 20px">{{ lang === 'bn' ? '＋ প্রথম ইউনিট যোগ করুন' : '＋ Add your first unit' }}</button>
        <button @click="openImport" class="btn-ghost" style="padding:10px 20px;font-weight:700">{{ lang === 'bn' ? '⬆ CSV ইমপোর্ট' : '⬆ Import from CSV' }}</button>
      </div>
    </div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(620px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">{{ UNIT_EMOJI[propType(sel.p)] || '🚪' }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="badge(sel.status)">{{ sel.status }}</span>
            <span v-if="selLease" class="badge" :class="badge(selLease.status)">{{ selLease.id }} · {{ selLease.status }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.name }}</h2>
          <div class="c-sub" style="margin-top:3px">{{ sel.id }} · 🏢 {{ propName(sel.p) }}<template v-if="sel.floor && sel.floor !== '—'"> · {{ sel.floor }} floor</template></div>

          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
            <div v-for="s in selStats" :key="s.label" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ s.label }}</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ s.v }}</div>
            </div>
          </div>

          <div style="display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:14px;flex-wrap:wrap">
            <ScrollTabs style="gap:6px;border-bottom:none;margin-bottom:0">
            <button v-for="t in [{id:'lease',label:'Lease',ico:'📄'},{id:'invoices',label:'Invoices',ico:'🧾'},{id:'tickets',label:'Tickets',ico:'🔧'},{id:'utils',label:'Utilities',ico:'🔌'}]" :key="t.id" @click="tab = t.id"
              style="padding:9px 14px;border:none;background:none;font-size:13px;font-weight:700;cursor:pointer;border-bottom:2px solid transparent;color:var(--text-mute)"
              :style="tab === t.id ? 'color:var(--primary);border-bottom-color:var(--primary)' : ''">
              {{ t.ico }} {{ t.label }} <span style="opacity:.7">({{ t.id === 'lease' ? (selLease ? 1 : 0) : t.id === 'invoices' ? selInvoices.length : t.id === 'tickets' ? selTickets.length : selUtils.length }})</span>
            </button>
            </ScrollTabs>
          </div>

          <div v-if="tab === 'lease'" class="drawer-tbl-wrap">
            <table class="kr" style="width:100%">
            <thead><tr><th>{{ t('Lease') }}</th><th>{{ t('Tenant') }}</th><th>{{ t('Start') }}</th><th>{{ t('End') }}</th><th>{{ t('Advance') }}</th><th>{{ t('Registered') }}</th><th>{{ t('Status') }}</th></tr></thead>
            <tbody>
              <tr v-if="selLease">
                <td style="font-weight:700">{{ selLease.id }}</td>
                <td>{{ tenantName(selLease.t) }}</td>
                <td>{{ selLease.start || '—' }}</td>
                <td>{{ selLease.end || '—' }} <span v-if="leaseDaysLeft(selLease) !== null && selLease.status === 'Active'" class="c-sub">({{ leaseDaysLeft(selLease) }}d)</span></td>
                <td>{{ money(selLease.adv) }}</td>
                <td>{{ selLease.reg_office ? selLease.reg_office + ' · ' + (selLease.reg_deed || '') : '—' }}</td>
                <td><span class="badge" :class="badge(selLease.status)">{{ selLease.status }}</span></td>
              </tr>
              <tr v-else><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No lease for this unit.') }}</td></tr>
            </tbody>
          </table>
          </div>

          <div v-else-if="tab === 'invoices'" class="drawer-tbl-wrap">
            <table class="kr" style="width:100%">
            <thead><tr><th>{{ t('Invoice') }}</th><th>{{ t('Month') }}</th><th>{{ t('Gross') }}</th><th>{{ t('TDS') }}</th><th>{{ t('Net') }}</th><th>{{ t('Status') }}</th></tr></thead>
            <tbody>
              <tr v-for="i in selInvoices" :key="i.id">
                <td style="font-weight:700"><a @click.stop="go('/invoices', { open: i.id })" style="color:var(--primary);cursor:pointer;text-decoration:none;font-weight:800">{{ i.id }}</a> <span class="c-sub" style="font-size:10.5px">↗</span></td><td>{{ i.m || '—' }}</td><td>{{ money(i.gross) }}</td><td>{{ money(i.tds) }}</td><td style="font-weight:700">{{ money(i.net) }}</td>
                <td><span class="badge" :class="badge(i.status)">{{ i.status }}</span></td>
              </tr>
              <tr v-if="!selInvoices.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No invoices.') }}</td></tr>
            </tbody>
          </table>
          </div>

          <div v-else-if="tab === 'tickets'" class="drawer-tbl-wrap">
            <table class="kr" style="width:100%">
            <thead><tr><th>{{ t('Ticket') }}</th><th>{{ t('Issue') }}</th><th>{{ t('Reported') }}</th><th>{{ t('Liability') }}</th><th>{{ t('Cost') }}</th><th>{{ t('Status') }}</th></tr></thead>
            <tbody>
              <tr v-for="t in selTickets" :key="t.id">
                <td style="font-weight:700"><a @click.stop="go('/maintenance', { open: t.id })" style="color:var(--primary);cursor:pointer;text-decoration:none;font-weight:800">{{ t.id }}</a> <span class="c-sub" style="font-size:10.5px">↗</span></td><td>{{ t.desc }}</td><td>{{ t.reported || '—' }}</td><td>{{ t.liab || '—' }}</td><td>{{ money(t.cost) }}</td>
                <td><span class="badge" :class="badge(t.status)">{{ t.status }}</span></td>
              </tr>
              <tr v-if="!selTickets.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No maintenance tickets.') }}</td></tr>
            </tbody>
          </table>
          </div>

          <div v-else class="drawer-tbl-wrap">
            <table class="kr" style="width:100%">
            <thead><tr><th>{{ t('Bill') }}</th><th>{{ t('Type') }}</th><th>{{ t('Month') }}</th><th>{{ t('Usage') }}</th><th>{{ t('Amount') }}</th><th>{{ t('Status') }}</th></tr></thead>
            <tbody>
              <tr v-for="b in selUtils" :key="b.id">
                <td style="font-weight:700">{{ b.id }}</td><td>{{ b.type }}</td><td>{{ b.month || '—' }}</td><td>{{ b.usage ?? '—' }}</td><td style="font-weight:700">{{ money(b.amount) }}</td>
                <td><span class="badge" :class="badge(b.status)">{{ b.status }}</span></td>
              </tr>
              <tr v-if="!selUtils.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No utility bills.') }}</td></tr>
            </tbody>
          </table>
          </div>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>

    <!-- modal -->
    <template v-if="modal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="closeModal"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(500px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto">
        <div style="padding:20px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:16px;font-weight:800">{{ modal.mode === 'edit' ? '✏️ Edit ' + modal.u.id : '＋ New unit' }}</h3>
          <button @click="closeModal" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:grid;grid-template-columns:1fr 1fr;gap:13px">
          <div style="grid-column:1/-1">
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Unit name *</label>
            <input v-model="form.name" placeholder="e.g. Flat 3B" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div style="grid-column:1/-1">
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Property *</label>
            <select v-model="form.p" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option v-for="p in propOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Floor') }}</label>
            <input v-model="form.floor" placeholder="3rd" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Area (sqft)') }}</label>
            <input v-model="form.sqft" type="number" min="0" placeholder="1450" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Rent (৳/mo)</label>
            <input v-model="form.rent" type="number" min="0" placeholder="25000" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Status') }}</label>
            <select v-model="form.status" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option v-for="s in ['Vacant','Leased','Maintenance']" :key="s" :value="s">{{ s }}</option>
            </select>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Beds') }}</label>
            <input v-model="form.beds" placeholder="3" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Baths') }}</label>
            <input v-model="form.baths" placeholder="2" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">{{ t('Furnished') }}</label>
            <select v-model="form.furnished" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option value="">—</option><option value="0">No</option><option value="1">{{ t('Yes') }}</option>
            </select>
          </div>
          <div v-if="formErr" style="grid-column:1/-1;color:var(--danger);font-size:12.5px;font-weight:600">{{ formErr }}</div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="closeModal">{{ t('Cancel') }}</button>
          <button class="btn-primary" :disabled="saving" @click="submitForm" style="padding:9px 18px">{{ saving ? 'Saving…' : modal.mode === 'edit' ? 'Save changes' : 'Create unit' }}</button>
        </div>
      </div>
    </template>

    <ImportWizard v-if="showImport" collection="units" :on-done="refreshAll" @close="showImport = false" />
  </div>
</template>

<style scoped>
/* White badges only on cover/header areas (same convention as Properties) */
.u-cover .badge,
.d-cover .badge {
  background: #ffffff;
}
</style>
