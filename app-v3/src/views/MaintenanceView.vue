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
const viewMode = useViewMode('maintenance')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'service_manager'].includes(auth.user?.role || ''))
const query = ref('')
const statusFilter = ref('')

const tickets = computed(() => data.list('tickets'))
const unitsAll = computed(() => data.list('units'))
const propsAll = computed(() => data.list('properties'))
const tenantsAll = computed(() => data.list('tenants'))
const leasesAll = computed(() => data.list('leases'))
const partners = computed(() => data.list('partners'))

const openCount = computed(() => tickets.value.filter(t => t.status === 'Open').length)
const pendingPay = computed(() => tickets.value.filter(t => t.status === 'Awaiting Payment').length)
const resolvedCount = computed(() => tickets.value.filter(t => ['Resolved', 'Closed', 'Completed'].includes(t.status)).length)
const estCost = computed(() => tickets.value.reduce((a, t) => a + (t.cost || 0), 0))

function unitName(pid) { return unitsAll.value.find(u => u.id === pid)?.name || pid || '' }
function unitProp(pid) { return unitsAll.value.find(u => u.id === pid)?.p || '' }
function propName(pid) { return propsAll.value.find(p => p.id === pid)?.name || pid || '' }
function tenantOfUnit(uid) { const l = leasesAll.value.find(l => l.u === uid && String(l.status).toLowerCase() === 'active'); return l ? tenantsAll.value.find(t => t.id === l.t) : null }
function partnerName(con) { return partners.value.find(p => p.id === con)?.name || con || '—' }
function money(n) { return '৳' + Math.round(n || 0).toLocaleString('en-IN') }

const statusOptions = computed(() => [...new Set(tickets.value.map(t => t.status).filter(Boolean))].sort())

const filtered = computed(() => {
  let out = tickets.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(t => JSON.stringify(t).toLowerCase().includes(q) || unitName(t.u).toLowerCase().includes(q) || propName(unitProp(t.u)).toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(t => t.status === statusFilter.value)
  return [...out].sort((a, b) => String(b.reported || '').localeCompare(String(a.reported || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

// ── drawer ──
const sel = ref(null)
function openDetail(t) { sel.value = t }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const t = tickets.value.find(x => x.id === id); if (t) openDetail(t) }
}, { immediate: true })
const selUnit = computed(() => sel.value ? unitsAll.value.find(u => u.id === sel.value.u) : null)
const selTenant = computed(() => sel.value ? tenantOfUnit(sel.value.u) : null)
const selPartner = computed(() => sel.value ? partners.value.find(p => p.id === sel.value.con) : null)

async function updateStatus(t, newStatus) {
  const r = await apiCall('app-crud', { action: 'update', collection: 'tickets', id: t.id, data: { status: newStatus } })
  if (r.ok) { t.status = newStatus; window.__krToast?.('Ticket ' + t.id + ' → ' + newStatus, 'ok') }
  else window.__krToast?.(r.error || 'Update failed', 'error')
}
async function setCost(t) {
  const v = prompt('Estimated cost (৳) for ' + t.id + ':', t.cost || '')
  if (v === null) return
  const n = Number(v)
  if (isNaN(n) || n < 0) { window.__krToast?.('Enter a valid amount', 'error'); return }
  const r = await apiCall('app-crud', { action: 'update', collection: 'tickets', id: t.id, data: { cost: Math.round(n) } })
  if (r.ok) { t.cost = Math.round(n); window.__krToast?.(`💰 ${t.id} cost set to ${money(n)}`, 'ok') }
  else window.__krToast?.(r.error || 'Update failed', 'error')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🔧 Maintenance</h1>
        <div class="sub">{{ tickets.length }} tickets · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search tickets, unit, property…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
      </div>
    </div>

    <div class="stats">
      <div class="stat"><div class="s-label"><span class="s-ico">🔧</span>Open</div><div class="s-value">{{ openCount }}</div><div class="s-trend">{{ tickets.length }} total</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">⏳</span>Awaiting payment</div><div class="s-value">{{ pendingPay }}</div><div class="s-trend">need invoice</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">✅</span>Resolved</div><div class="s-value">{{ resolvedCount }}</div><div class="s-trend">done</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">💰</span>Est. cost</div><div class="s-value">{{ money(estCost) }}</div><div class="s-trend">all tickets</div></div>
    </div>

    <!-- GRID -->
    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px">
      <div v-for="t in paged" :key="t.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(t)">
        <div style="height:76px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:30px">🔧</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="badge(t.status)">{{ t.status }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ t.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div>
            <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px">{{ unitName(t.u) }} <span class="c-sub" style="font-weight:500">· {{ propName(unitProp(t.u)) }}</span></div>
            <div class="c-sub" style="margin-top:3px;font-size:12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ t.desc }}</div>
          </div>
          <div style="display:flex;gap:12px;font-size:12px;flex-wrap:wrap">
            <span class="c-sub">📅 {{ t.reported || '—' }}</span>
            <span class="c-sub">🏷 {{ t.liab || '—' }}</span>
            <span class="c-sub">🧰 {{ partnerName(t.con) }}</span>
            <span class="c-sub">💰 {{ t.cost ? money(t.cost) : '—' }}</span>
          </div>
          <div v-if="canManage" style="display:flex;gap:6px;border-top:1px solid var(--border);padding-top:9px;margin-top:auto">
            <select :value="t.status" @click.stop @change="updateStatus(t, $event.target.value)" style="flex:1;padding:6px 8px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);color:var(--text);font-size:12px;font-family:inherit;outline:none">
              <option>Open</option>
              <option>In Progress</option>
              <option>Awaiting Payment</option>
              <option>Resolved</option>
              <option>Closed</option>
            </select>
            <button class="btn-ghost" style="padding:6px 9px;font-size:11.5px" @click.stop="setCost(t)" title="Set cost">💰</button>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Unit</th><th>Property</th><th>Issue</th><th>Reported</th><th>Liability</th><th>Contractor</th><th>Cost</th><th>Status</th><th v-if="canManage">Action</th></tr></thead>
          <tbody>
            <tr v-for="t in paged" :key="t.id" style="cursor:pointer" @click="openDetail(t)">
              <td style="font-weight:700;white-space:nowrap">{{ t.id }}</td>
              <td style="white-space:nowrap"><a @click.stop="go('/units', { open: t.u })" style="color:var(--text);cursor:pointer;text-decoration:underline dotted">{{ unitName(t.u) }}</a></td>
              <td style="white-space:nowrap" class="c-sub">{{ propName(unitProp(t.u)) }}</td>
              <td style="white-space:normal;min-width:200px">{{ t.desc }}</td>
              <td style="white-space:nowrap">{{ t.reported }}</td>
              <td style="white-space:nowrap">{{ t.liab }}</td>
              <td style="white-space:nowrap">{{ partnerName(t.con) }}</td>
              <td style="white-space:nowrap">{{ t.cost ? money(t.cost) : '—' }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="badge(t.status)">{{ t.status }}</span></td>
              <td v-if="canManage" style="white-space:nowrap">
                <select :value="t.status" @click.stop @change="updateStatus(t, $event.target.value)" style="padding:5px 8px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:12px;font-family:inherit">
                  <option>Open</option>
                  <option>In Progress</option>
                  <option>Awaiting Payment</option>
                  <option>Resolved</option>
                  <option>Closed</option>
                </select>
              </td>
            </tr>
            <tr v-if="!filtered.length"><td :colspan="canManage ? 10 : 9" style="text-align:center;color:var(--text-mute);padding:30px">No tickets found.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No tickets found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(560px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:110px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:44px">🔧</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="badge(sel.status)">{{ sel.status }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ unitName(sel.u) }} <span class="c-sub" style="font-weight:500">· {{ propName(unitProp(sel.u)) }}</span></h2>
          <div class="c-sub" style="margin-top:3px">📅 Reported {{ sel.reported || '—' }} · 🏷 {{ sel.liab || '—' }}</div>

          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin:16px 0">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Est. cost</div>
              <div style="font-size:15px;font-weight:800;margin-top:2px">{{ sel.cost ? money(sel.cost) : '—' }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Contractor</div>
              <div style="font-size:15px;font-weight:800;margin-top:2px">{{ partnerName(sel.con) }}</div>
            </div>
            <div v-if="selTenant" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px;cursor:pointer" @click="go('/tenants', { open: selTenant.id })">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Tenant ↗</div>
              <div style="font-size:15px;font-weight:800;margin-top:2px;color:var(--primary)">{{ selTenant.name }}</div>
            </div>
          </div>

          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:7px">📝 Issue</div>
            <div style="font-size:13.5px;line-height:1.6">{{ sel.desc }}</div>
          </div>

          <div v-if="canManage" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
            <select :value="sel.status" @change="updateStatus(sel, $event.target.value)" style="flex:1;min-width:160px;padding:8px 10px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:12.5px;font-family:inherit;outline:none">
              <option>Open</option>
              <option>In Progress</option>
              <option>Awaiting Payment</option>
              <option>Resolved</option>
              <option>Closed</option>
            </select>
            <button class="btn-ghost" style="padding:8px 14px;font-size:12.5px" @click="setCost(sel)">💰 Set cost</button>
            <button class="btn-ghost" style="padding:8px 14px;font-size:12.5px" @click="go('/units', { open: sel.u })">🚪 Unit ↗</button>
          </div>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>
  </div>
</template>
