<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { lang } from '../lib/i18n'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { track } from '../lib/analytics'
import { badge, useViewMode, usePager } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import RichEditor from '../components/RichEditor.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('maintenance')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))

const CAT_META = {
  plumbing: { ico: '🔧', label: 'Plumbing', cls: 'b-blue' },
  electrical: { ico: '⚡', label: 'Electrical', cls: 'b-orange' },
  appliance: { ico: '🔌', label: 'Appliance', cls: 'b-green' },
  structural: { ico: '🏗️', label: 'Structural', cls: 'b-gray' },
  pest: { ico: '🐜', label: 'Pest', cls: 'b-purple' },
  other: { ico: '📋', label: 'Other', cls: 'b-gray' },
}
const catMeta = (c) => CAT_META[c] || CAT_META.other
const PRIO_META = { low: { ico: '🟢', label: 'Low' }, medium: { ico: '🟡', label: 'Medium' }, high: { ico: '🟠', label: 'High' }, urgent: { ico: '🔴', label: 'Urgent' } }
const prioMeta = (p) => PRIO_META[p] || { ico: '🟡', label: p || '—' }

const requests = computed(() => data.list('maintenance_requests'))
const unitsAll = computed(() => data.list('units'))
const propsAll = computed(() => data.list('properties'))
const tenantsAll = computed(() => data.list('tenants'))
const leasesAll = computed(() => data.list('leases'))
const partners = computed(() => data.list('partners'))

const openCount = computed(() => requests.value.filter(r => ['Open', 'Assigned', 'In Progress'].includes(r.status)).length)
const resolvedCount = computed(() => requests.value.filter(r => ['Resolved', 'Closed'].includes(r.status)).length)
const urgentCount = computed(() => requests.value.filter(r => r.priority === 'urgent' && !['Resolved', 'Closed'].includes(r.status)).length)
const estCost = computed(() => requests.value.reduce((a, r) => a + (r.cost_estimate || 0), 0))
const actCost = computed(() => requests.value.reduce((a, r) => a + (r.actual_cost || 0), 0))

function unitName(pid) { return unitsAll.value.find(u => u.id === pid)?.name || pid || '' }
function unitProp(pid) { return unitsAll.value.find(u => u.id === pid)?.p || '' }
function propName(pid) { return propsAll.value.find(p => p.id === pid)?.name || pid || '' }
function tenantOfUnit(uid) { const l = leasesAll.value.find(l => l.u === uid && ['Active', 'Pending Registration'].includes(String(l.status).toLowerCase() === 'active' ? 'Active' : String(l.status))); return l ? tenantsAll.value.find(t => t.id === l.t) : null }
function tenantName(tid) { return tenantsAll.value.find(t => t.id === tid)?.name || tid || '' }
function partnerName(con) { return partners.value.find(p => p.id === con)?.name || con || '—' }
function money(n) { return '৳' + Math.round(n || 0).toLocaleString('en-IN') }
function fmtTs(ts) { if (!ts) return '—'; return String(ts).replace('T', ' ').slice(0, 16) }

const query = ref('')
const statusFilter = ref('')
const prioFilter = ref('')
const statusOptions = computed(() => ['Open', 'Assigned', 'In Progress', 'Resolved', 'Closed'])
const filtered = computed(() => {
  let out = requests.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(r => JSON.stringify(r).toLowerCase().includes(q) || unitName(r.unit).toLowerCase().includes(q) || propName(r.prop).toLowerCase().includes(q) || (r.title || '').toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(r => r.status === statusFilter.value)
  if (prioFilter.value) out = out.filter(r => r.priority === prioFilter.value)
  return [...out].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

// ── raise modal ──
const raiseModal = ref(false)
const raiseForm = ref({ unit: '', category: 'plumbing', priority: 'medium', title: '', desc: '' })
function openRaise() {
  raiseForm.value = { unit: unitsAll.value[0]?.id || '', category: 'plumbing', priority: 'medium', title: '', desc: '' }
  raiseModal.value = true
}
async function submitRaise() {
  const f = raiseForm.value
  if (!f.unit) { window.__krToast?.('❌ Select a unit'); return }
  if (!f.title.trim()) { window.__krToast?.('❌ Title is required'); return }
  const r = await apiCall('app-maintenance', { action: 'create', unit: f.unit, category: f.category, priority: f.priority, title: f.title.trim(), desc: f.desc.trim() })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  raiseModal.value = false
  window.__krToast?.('✅ ' + (r.id || 'Request') + ' raised')
  await data.bootstrap()
  track('maintenance_ticket_created', { category: f.category, priority: f.priority })
}

// ── drawer ──
const sel = ref(null)
const assignForm = ref({ assigned_to: '', vendor: '' })
const costForm = ref({ cost_estimate: 0, actual_cost: 0, charge_to: 'owner' })
function openDetail(r) {
  sel.value = r
  assignForm.value = { assigned_to: r.assigned_to || '', vendor: r.vendor || '' }
  costForm.value = { cost_estimate: r.cost_estimate || 0, actual_cost: r.actual_cost || 0, charge_to: r.charge_to || 'owner' }
}
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const r = requests.value.find(x => x.id === id); if (r) openDetail(r) }
}, { immediate: true })

async function doStatus(r, newStatus) {
  const res = await apiCall('app-maintenance', { action: 'status', id: r.id, status: newStatus })
  if (res && res.ok === false) { window.__krToast?.('❌ ' + (res.error || 'Failed')); return }
  window.__krToast?.('✅ ' + r.id + ' → ' + newStatus, 'ok')
  await data.bootstrap()
  refreshSel()
}
async function doAssign() {
  const f = assignForm.value
  if (!f.assigned_to && !f.vendor) { window.__krToast?.('❌ Enter a technician or vendor'); return }
  const res = await apiCall('app-maintenance', { action: 'assign', id: sel.value.id, assigned_to: f.assigned_to.trim(), vendor: f.vendor.trim() })
  if (res && res.ok === false) { window.__krToast?.('❌ ' + (res.error || 'Failed')); return }
  window.__krToast?.('✅ Assigned', 'ok')
  await data.bootstrap()
  refreshSel()
}
async function doCost() {
  const f = costForm.value
  const res = await apiCall('app-maintenance', { action: 'cost', id: sel.value.id, cost_estimate: parseInt(f.cost_estimate) || 0, actual_cost: parseInt(f.actual_cost) || 0, charge_to: f.charge_to })
  if (res && res.ok === false) { window.__krToast?.('❌ ' + (res.error || 'Failed')); return }
  window.__krToast?.('✅ Costs saved (actual > 0 auto-resolves)', 'ok')
  await data.bootstrap()
  refreshSel()
}
function refreshSel() {
  if (!sel.value) return
  const fresh = requests.value.find(x => x.id === sel.value.id)
  if (fresh) {
    sel.value = fresh
    assignForm.value = { assigned_to: fresh.assigned_to || '', vendor: fresh.vendor || '' }
    costForm.value = { cost_estimate: fresh.cost_estimate || 0, actual_cost: fresh.actual_cost || 0, charge_to: fresh.charge_to || 'owner' }
  }
}
const selUnit = computed(() => sel.value ? unitsAll.value.find(u => u.id === sel.value.unit) : null)
const selTenant = computed(() => sel.value ? tenantsAll.value.find(t => t.id === sel.value.tenant) : null)
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🔧 {{ lang === 'bn' ? 'মেইনটেন্যান্স' : 'Maintenance' }}</h1>
        <div class="sub">{{ requests.length }} requests · {{ openCount }} open · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" placeholder="Search title, unit, property…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:210px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="prioFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All priorities</option>
          <option v-for="(m, k) in PRIO_META" :key="k" :value="k">{{ m.ico }} {{ m.label }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
      </CompactFilters>
        <button v-if="canManage" @click="openRaise" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Raise request</button>
      </div>
    </div>

    <div class="stats">
      <div class="stat"><div class="s-label"><span class="s-ico">🔧</span>Open</div><div class="s-value">{{ openCount }}</div><div class="s-trend">{{ requests.length }} total</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">✅</span>Resolved</div><div class="s-value">{{ resolvedCount }}</div><div class="s-trend">done</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">🔴</span>Urgent</div><div class="s-value" :style="urgentCount ? 'color:var(--danger)' : ''">{{ urgentCount }}</div><div class="s-trend">not resolved</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">💰</span>Est. cost</div><div class="s-value">{{ money(estCost) }}</div><div class="s-trend">actual {{ money(actCost) }}</div></div>
    </div>

    <!-- GRID -->
    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px">
      <div v-for="r in paged" :key="r.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(r)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">{{ catMeta(r.category).ico }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="badge(r.status)" style="background:#ffffff">{{ r.status }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ r.id }}</div>
          <div v-if="r.priority === 'urgent'" style="position:absolute;top:10px;right:12px;font-size:19px" title="Urgent">🔴</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div>
            <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px">{{ r.title || '—' }} <span class="c-sub" style="font-weight:500">· {{ unitName(r.unit) }}</span></div>
            <div class="c-sub" style="margin-top:3px;font-size:12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden" v-html="r.desc || '—'"></div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="catMeta(r.category).cls">{{ catMeta(r.category).ico }} {{ catMeta(r.category).label }}</span>
            <span class="badge b-gray">{{ prioMeta(r.priority).ico }} {{ prioMeta(r.priority).label }}</span>
            <span v-if="r.vendor" class="badge b-blue">🧰 {{ partnerName(r.vendor) }}</span>
            <span v-if="r.actual_cost" class="badge b-orange">{{ money(r.actual_cost) }}</span>
          </div>
          <div style="display:flex;gap:13px;font-size:12px;flex-wrap:wrap;margin-top:auto">
            <span class="c-sub">📅 {{ fmtTs(r.ts) }}</span>
            <span class="c-sub">🏷 {{ r.charge_to || '—' }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Title</th><th>Unit</th><th>Property</th><th>Category</th><th>Priority</th><th>Vendor</th><th>Cost</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="r in paged" :key="r.id" style="cursor:pointer" @click="openDetail(r)">
              <td style="font-weight:700;white-space:nowrap">{{ r.id }}</td>
              <td style="white-space:normal;min-width:180px">{{ r.title || '—' }}</td>
              <td style="white-space:nowrap"><a @click.stop="go('/units', { open: r.unit })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ unitName(r.unit) }}</a></td>
              <td style="white-space:nowrap" class="c-sub">{{ propName(r.prop) }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="catMeta(r.category).cls">{{ catMeta(r.category).label }}</span></td>
              <td style="white-space:nowrap">{{ prioMeta(r.priority).ico }} {{ prioMeta(r.priority).label }}</td>
              <td style="white-space:nowrap">{{ partnerName(r.vendor) }}</td>
              <td style="white-space:nowrap">{{ r.actual_cost ? money(r.actual_cost) : (r.cost_estimate ? money(r.cost_estimate) + ' est' : '—') }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="badge(r.status)">{{ r.status }}</span></td>
            </tr>
            <tr v-if="!filtered.length"><td colspan="9" style="text-align:center;color:var(--text-mute);padding:30px">No requests found.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No maintenance requests found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- raise modal -->
    <template v-if="raiseModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="raiseModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(480px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">🔧 Raise maintenance request</div>
          <button @click="raiseModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 22px;display:flex;flex-direction:column;gap:12px">
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Unit *</div>
            <select v-model="raiseForm.unit" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
              <option v-for="u in unitsAll" :key="u.id" :value="u.id">{{ u.name }} · {{ propName(u.p) }}</option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Category</div>
              <select v-model="raiseForm.category" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option v-for="(m, k) in CAT_META" :key="k" :value="k">{{ m.ico }} {{ m.label }}</option>
              </select>
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Priority</div>
              <select v-model="raiseForm.priority" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option v-for="(m, k) in PRIO_META" :key="k" :value="k">{{ m.ico }} {{ m.label }}</option>
              </select>
            </div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Title *</div>
            <input v-model="raiseForm.title" placeholder="e.g. Kitchen sink leakage" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Description</div>
            <RichEditor v-model="raiseForm.desc" placeholder="What's the issue?" :min-height="'120px'" style="margin-top:5px" />
          </div>
          <button @click="submitRaise" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">＋ Raise request</button>
        </div>
      </div>
    </template>

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(560px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:110px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:44px">{{ catMeta(sel.category).ico }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="badge(sel.status)" style="background:#ffffff">{{ sel.status }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span v-if="sel.priority === 'urgent'" class="badge" style="background:#ffffff;color:var(--danger)">🔴 Urgent</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.title || '—' }}</h2>
          <div class="c-sub" style="margin-top:3px">🚪 {{ unitName(sel.unit) }} · {{ propName(sel.prop) }} · 📅 {{ fmtTs(sel.ts) }} · by {{ sel.created_by || '—' }}</div>

          <div style="display:flex;gap:6px;flex-wrap:wrap;margin:12px 0">
            <span class="badge" :class="catMeta(sel.category).cls">{{ catMeta(sel.category).ico }} {{ catMeta(sel.category).label }}</span>
            <span class="badge b-gray">{{ prioMeta(sel.priority).ico }} {{ prioMeta(sel.priority).label }}</span>
            <button v-if="selUnit" class="btn-ghost" style="padding:4px 10px;font-size:12px" @click="go('/units', { open: sel.unit })">↗ {{ unitName(sel.unit) }}</button>
            <button v-if="selTenant" class="btn-ghost" style="padding:4px 10px;font-size:12px" @click="go('/tenants', { open: selTenant.id })">↗ {{ selTenant.name }}</button>
          </div>

          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin:14px 0">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Est. cost</div>
              <div style="font-size:15px;font-weight:800;margin-top:2px">{{ sel.cost_estimate ? money(sel.cost_estimate) : '—' }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Actual</div>
              <div style="font-size:15px;font-weight:800;margin-top:2px">{{ sel.actual_cost ? money(sel.actual_cost) : '—' }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Vendor</div>
              <div style="font-size:14px;font-weight:800;margin-top:2px">{{ partnerName(sel.vendor) }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Charge to</div>
              <div style="font-size:14px;font-weight:800;margin-top:2px">{{ sel.charge_to || '—' }}</div>
            </div>
          </div>

          <div v-if="sel.desc" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:7px">📝 Issue</div>
            <div style="font-size:13.5px;line-height:1.6" v-html="sel.desc"></div>
          </div>

          <template v-if="canManage">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">🔄 Status</div>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <button v-for="s in statusOptions" :key="s" @click="doStatus(sel, s)" :style="sel.status === s ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : ''" class="btn-ghost" style="padding:7px 11px;font-size:12px;font-weight:800">{{ s }}</button>
              </div>
            </div>

            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">🧰 Assign work</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                <input v-model="assignForm.assigned_to" placeholder="Technician name" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
                <input v-model="assignForm.vendor" placeholder="Vendor / contractor" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
              </div>
              <button @click="doAssign" style="margin-top:9px;width:100%;padding:9px;border:none;border-radius:9px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">Assign</button>
            </div>

            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">💰 Costs</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                <div>
                  <div class="c-sub" style="font-size:10.5px;margin-bottom:3px">Estimate (৳)</div>
                  <input v-model.number="costForm.cost_estimate" type="number" min="0" style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
                </div>
                <div>
                  <div class="c-sub" style="font-size:10.5px;margin-bottom:3px">Actual (৳)</div>
                  <input v-model.number="costForm.actual_cost" type="number" min="0" style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
                </div>
              </div>
              <div style="display:flex;gap:8px;margin-top:9px">
                <select v-model="costForm.charge_to" style="flex:1;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-size:12.5px;font-family:inherit;outline:none">
                  <option value="owner">Owner</option>
                  <option value="tenant">Tenant</option>
                  <option value="service">Service</option>
                  <option value="insurance">Insurance</option>
                </select>
                <button @click="doCost" style="padding:8px 16px;border:none;border-radius:9px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">Save</button>
              </div>
            </div>
          </template>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.d-cover .badge { background: #ffffff; }
</style>
