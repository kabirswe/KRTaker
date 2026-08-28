<script setup>
import { computed, ref, onMounted } from 'vue'
import { t } from '../lib/i18n'
import { apiCall, apiUpload, apiBlob } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { badge } from '../lib/ui'
import ScrollTabs from '../components/ScrollTabs.vue'

const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'svc_mgr'].includes(auth.user?.role || ''))

const tab = ref('smart')
const loading = ref(false)
const err = ref('')
const toast = ref('')

// ── Smart Home (app-smarthome) ──
const locks = ref([])
const cams = ref([])
const smartCfg = ref({})
async function loadSmart() {
  const r = await apiCall('app-smarthome', { action: 'summary' })
  if (!r.ok) { err.value = r.error || 'Failed to load smart home.'; return }
  locks.value = (r.locks && r.locks.rows) || []
  cams.value = (r.cameras && r.cameras.rows) || []
  smartCfg.value = r.config || {}
}

// ── Systems Watch (app-systems) ──
const sysAssets = ref([])
const sysServices = ref([])
const sysFuel = ref([])
const sysStats = ref({})
async function loadSystems() {
  const r = await apiCall('app-systems', { action: 'summary' })
  if (!r.ok) { err.value = r.error || 'Failed to load systems.'; return }
  const s = r.summary || {}
  sysAssets.value = s.assets || []
  sysServices.value = s.services || []
  sysFuel.value = s.fuel || []
  sysStats.value = s.stats || {}
}

// ── Health Check (app-healthcheck) ──
const plans = ref([])
const planStats = ref({})
async function loadHealth() {
  const r = await apiCall('app-healthcheck', { action: 'summary' })
  if (!r.ok) { err.value = r.error || 'Failed to load health check.'; return }
  plans.value = (r.plans && r.plans.rows) || []
  planStats.value = { by_status: r.plans?.by_status || {}, overdue: r.plans?.overdue || 0, upcoming: r.plans?.upcoming || 0 }
}

async function load() {
  loading.value = true; err.value = ''
  try {
    if (tab.value === 'smart') await loadSmart()
    else if (tab.value === 'systems') await loadSystems()
    else await loadHealth()
  } finally { loading.value = false }
}
onMounted(load)

// ── labels ──
const LOCK_PURPOSE = { showing: '🔑 Showing', broker: '🤝 Broker', maintenance: '🛠️ Maintenance', other: '📋 Other' }
const lockCls = (s) => s === 'active' ? 'b-green' : (s === 'expired' ? 'b-orange' : 'b-red')
const camCls = (s) => s === 'online' ? 'b-green' : 'b-red'
const ASSET_TYPE = { lift: '🛗 Lift', generator: '⚡ Generator', water_pump: '💧 Water pump', sewage_pump: '🚰 Sewage pump', transformer: '🔋 Transformer', solar: '☀️ Solar', booster_pump: '🚿 Booster pump', other: '⚙️ Other' }
const assetType = (t) => ASSET_TYPE[t] || '⚙️ ' + (t || 'Other')
const assetCls = (s) => s === 'operational' ? 'b-green' : (s === 'service_due' ? 'b-orange' : (s === 'faulty' ? 'b-red' : 'b-gray'))
const SEASON = { pre_monsoon: '🌧️ Pre-monsoon', pre_summer: '☀️ Pre-summer', quarterly: '🗓️ Quarterly' }
const SERVICE = { ac_service: '❄️ AC service', roof_waterproof: '🏠 Roof waterproof', drainage_clear: '🌊 Drainage clear', deep_clean: '🧹 Deep clean', pest_control: '🐜 Pest control' }
const planCls = (s) => s === 'Completed' ? 'b-green' : (s === 'Scheduled' || s === 'In_Progress' ? 'b-blue' : (s === 'Skipped' ? 'b-gray' : 'b-orange'))
const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')

// ── Smart Home actions ──
const showLockForm = ref(false)
const lockForm = ref({ lock_name: '', purpose: 'showing', prop: '', unit: '', grant_for: '', notes: '' })
const props = ref([])
function openLockForm() {
  lockForm.value = { lock_name: '', purpose: 'showing', prop: props.value[0]?.id || '', unit: '', grant_for: '', notes: '' }
  showLockForm.value = true
}
async function createLock() {
  const f = lockForm.value
  if (!f.lock_name.trim()) { alert('Lock name is required.'); return }
  const r = await apiCall('app-smarthome', { action: 'lock-create', lock_name: f.lock_name.trim(), purpose: f.purpose, prop: f.prop, unit: f.unit.trim(), grant_for: f.grant_for.trim(), notes: f.notes.trim() })
  if (!r.ok) { alert(r.error || 'Create failed'); return }
  showLockForm.value = false
  toast.value = `✅ Lock ${r.id} created · code ${r.code}`
  setTimeout(() => toast.value = '', 4000)
  await loadSmart()
}
async function revokeLock(l) {
  if (!confirm(`Revoke lock ${l.id}? It can no longer be used.`)) return
  const r = await apiCall('app-smarthome', { action: 'lock-revoke', id: l.id })
  if (!r.ok) { alert(r.error || 'Revoke failed'); return }
  await loadSmart()
}
async function useLock(l) {
  const r = await apiCall('app-smarthome', { action: 'lock-use', id: l.id })
  if (!r.ok) { alert(r.error || 'Use failed'); return }
  toast.value = `✅ ${l.id} used (${r.used_count}×)`
  setTimeout(() => toast.value = '', 4000)
  await loadSmart()
}
async function toggleCam(c) {
  const next = c.status === 'online' ? 'offline' : 'online'
  const r = await apiCall('app-smarthome', { action: 'camera-status', id: c.id, status: next })
  if (!r.ok) { alert(r.error || 'Toggle failed'); return }
  await loadSmart()
}

// ── Systems actions ──
const assetStatusFor = ref(null)
const showAssetStatus = ref(false)
const assetStatusVal = ref('operational')
function openAssetStatus(a) { assetStatusFor.value = a; assetStatusVal.value = a.status; showAssetStatus.value = true }
async function setAssetStatus() {
  const r = await apiCall('app-systems', { action: 'asset-status', id: assetStatusFor.value.id, status: assetStatusVal.value })
  if (!r.ok) { alert(r.error || 'Status update failed'); return }
  showAssetStatus.value = false
  await loadSystems()
}
const serviceFor = ref(null)
const showServiceForm = ref(false)
const svcForm = ref({ service_type: 'routine', technician: '', vendor: '', cost: '', notes: '' })
function openServiceForm(a) { serviceFor.value = a; svcForm.value = { service_type: 'routine', technician: '', vendor: '', cost: '', notes: '' }; showServiceForm.value = true }
async function recordService() {
  const f = svcForm.value
  if (!f.technician.trim()) { alert('Technician is required.'); return }
  const r = await apiCall('app-systems', { action: 'asset-service', id: serviceFor.value.id, service_type: f.service_type, technician: f.technician.trim(), vendor: f.vendor.trim(), cost: +f.cost || 0, notes: f.notes.trim() })
  if (!r.ok) { alert(r.error || 'Record failed'); return }
  showServiceForm.value = false
  toast.value = '✅ Service recorded — next service bumped'
  setTimeout(() => toast.value = '', 4000)
  await loadSystems()
}
const fuelFor = ref(null)
const showFuelForm = ref(false)
const fuelForm = ref({ liters: '', rate_per_litre: '', vendor: '', notes: '' })
function openFuelForm(a) { fuelFor.value = a; fuelForm.value = { liters: '', rate_per_litre: '', vendor: '', notes: '' }; showFuelForm.value = true }
async function recordFuel() {
  const f = fuelForm.value
  if (!(+f.liters > 0)) { alert('Liters must be positive.'); return }
  const r = await apiCall('app-systems', { action: 'asset-fuel', id: fuelFor.value.id, liters: +f.liters, rate_per_litre: +f.rate_per_litre || 0, vendor: f.vendor.trim(), notes: f.notes.trim() })
  if (!r.ok) { alert(r.error || 'Refuel failed'); return }
  showFuelForm.value = false
  toast.value = '✅ Fuel refill recorded'
  setTimeout(() => toast.value = '', 4000)
  await loadSystems()
}

// ── Health actions ──
const PLAN_STATUSES = ['Planned', 'Scheduled', 'In_Progress', 'Completed', 'Skipped']
async function advancePlan(p) {
  const idx = PLAN_STATUSES.indexOf(p.status)
  if (idx < 0 || idx >= PLAN_STATUSES.length - 2) return
  const next = PLAN_STATUSES[idx + 1]
  const r = await apiCall('app-healthcheck', { action: 'plan-status', id: p.id, status: next })
  if (!r.ok) { alert(r.error || 'Advance failed'); return }
  toast.value = `✅ ${p.id} → ${next}`
  setTimeout(() => toast.value = '', 4000)
  await loadHealth()
}
const showPlanForm = ref(false)
const planForm = ref({ season: 'quarterly', service: 'deep_clean', prop: '', scheduled_for: '', assigned_to: '', cost: '' })
function openPlanForm() { planForm.value = { season: 'quarterly', service: 'deep_clean', prop: '', scheduled_for: '', assigned_to: '', cost: '' }; showPlanForm.value = true }
async function createPlan() {
  const f = planForm.value
  const r = await apiCall('app-healthcheck', { action: 'plan-create', season: f.season, service: f.service, prop: f.prop, scheduled_for: f.scheduled_for, assigned_to: f.assigned_to.trim(), cost: +f.cost || 0 })
  if (!r.ok) { alert(r.error || 'Create failed'); return }
  showPlanForm.value = false
  toast.value = `✅ Plan ${r.id} created`
  setTimeout(() => toast.value = '', 4000)
  await loadHealth()
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('🏠 Building Systems') }}</h1>
        <div class="sub">Smart home · systems watch · health check — merged workspace, live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-ghost" @click="load">🔄 Refresh</button>
      </div>
    </div>

    <div v-if="toast" style="padding:10px 14px;border-radius:10px;background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.35);margin-bottom:14px;font-weight:700;font-size:13.5px">{{ toast }}</div>
    <div v-if="err" style="padding:10px 14px;border-radius:10px;background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.35);margin-bottom:14px;font-weight:600;font-size:13.5px">⚠️ {{ err }}</div>

    <!-- Tabs -->
    <ScrollTabs>
      <button @click="tab = 'smart'; load()" :style="tab === 'smart' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'" style="padding:9px 16px;border:none;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer">🔐 Smart Home</button>
      <button @click="tab = 'systems'; load()" :style="tab === 'systems' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'" style="padding:9px 16px;border:none;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer">⚙️ Systems Watch</button>
      <button @click="tab = 'health'; load()" :style="tab === 'health' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'" style="padding:9px 16px;border:none;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer">🌦️ Health Check</button>
    </ScrollTabs>

    <div v-if="loading" class="panel" style="padding:36px;text-align:center;color:var(--text-mute)">Loading…</div>

    <!-- ══ SMART HOME ══ -->
    <template v-if="tab === 'smart' && !loading">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px">
        <div class="panel chip" style="padding:15px"><div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Locks</div><div style="font-size:23px;font-weight:900;margin-top:4px">{{ locks.length }}</div></div>
        <div class="panel chip" style="padding:15px"><div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Active</div><div style="font-size:23px;font-weight:900;margin-top:4px;color:var(--ok,#2ecc71)">{{ locks.filter(l => l.status === 'active').length }}</div></div>
        <div class="panel chip" style="padding:15px"><div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Cameras</div><div style="font-size:23px;font-weight:900;margin-top:4px">{{ cams.length }}</div></div>
        <div class="panel chip" style="padding:15px"><div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Online</div><div style="font-size:23px;font-weight:900;margin-top:4px;color:var(--ok,#2ecc71)">{{ cams.filter(c => c.status === 'online').length }}</div></div>
      </div>

      <div style="display:flex;gap:8px;margin-bottom:14px">
        <button v-if="canManage" @click="openLockForm" style="padding:10px 16px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:13px;cursor:pointer">＋ Grant lock</button>
      </div>

      <div v-if="locks.length" style="margin-bottom:18px">
        <div style="font-weight:800;font-size:14px;margin-bottom:10px">🔑 Smart locks</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px">
          <div v-for="l in locks" :key="l.id" class="panel chip" style="padding:14px 16px">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
              <span style="font-weight:800;font-size:13.5px">{{ l.id }} · {{ l.lock_name || 'Smart lock' }}</span>
              <span class="badge" :class="lockCls(l.status)">{{ l.status }}</span>
            </div>
            <div class="c-sub" style="font-size:12px;margin-top:5px">{{ LOCK_PURPOSE[l.purpose] || l.purpose }}<template v-if="l.grant_for"> · {{ l.grant_for }}</template></div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
              <span v-if="l.code" class="badge b-blue" style="font-family:monospace">{{ l.code }}</span>
              <span v-if="l.model" class="badge b-gray">{{ l.model }}</span>
              <span class="badge b-gray">used {{ l.used_count || 0 }}×</span>
            </div>
            <div class="c-sub" style="font-size:11.5px;margin-top:7px">🕓 {{ l.valid_from || '—' }} → {{ l.valid_until || '—' }}</div>
            <div v-if="canManage" style="display:flex;gap:6px;margin-top:10px">
              <button v-if="l.status === 'active'" @click="useLock(l)" class="btn-ghost" style="padding:5px 10px;font-size:11.5px">🔓 Use</button>
              <button v-if="l.status !== 'revoked'" @click="revokeLock(l)" class="btn-ghost" style="padding:5px 10px;font-size:11.5px;color:var(--danger,#e74c3c)">⛔ Revoke</button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="cams.length">
        <div style="font-weight:800;font-size:14px;margin-bottom:10px">📹 CCTV cameras</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">
          <div v-for="c in cams" :key="c.id" class="panel chip" style="padding:14px 16px">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
              <span style="font-weight:800;font-size:13.5px">{{ c.id }} · {{ c.name || 'Camera' }}</span>
              <span class="badge" :class="camCls(c.status)">{{ c.status }}</span>
            </div>
            <div class="c-sub" style="font-size:12px;margin-top:5px">{{ c.location || '—' }}<template v-if="c.prop_name"> · {{ c.prop_name }}</template></div>
            <div class="c-sub" style="font-size:11.5px;margin-top:6px">Last seen {{ c.last_seen || '—' }}</div>
            <div v-if="canManage" style="margin-top:10px">
              <button @click="toggleCam(c)" class="btn-ghost" style="padding:5px 10px;font-size:11.5px">{{ c.status === 'online' ? '⏸ Mark offline' : '▶️ Mark online' }}</button>
            </div>
          </div>
        </div>
      </div>
      <div v-if="!locks.length && !cams.length" class="panel" style="padding:36px;text-align:center;color:var(--text-mute)">No smart home devices.</div>
    </template>

    <!-- ══ SYSTEMS WATCH ══ -->
    <template v-if="tab === 'systems' && !loading">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px">
        <div class="panel chip" style="padding:15px"><div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Assets</div><div style="font-size:23px;font-weight:900;margin-top:4px">{{ sysStats.assets_total || 0 }}</div></div>
        <div class="panel chip" style="padding:15px"><div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Operational</div><div style="font-size:23px;font-weight:900;margin-top:4px;color:var(--ok,#2ecc71)">{{ sysStats.operational || 0 }}</div></div>
        <div class="panel chip" style="padding:15px"><div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Service due</div><div style="font-size:23px;font-weight:900;margin-top:4px;color:var(--danger,#e74c3c)">{{ (sysStats.service_overdue || 0) + (sysStats.service_due_soon || 0) }}</div></div>
        <div class="panel chip" style="padding:15px"><div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Fuel 30d</div><div style="font-size:18px;font-weight:900;margin-top:6px">{{ money(sysStats.fuel_cost_30d || 0) }}</div></div>
      </div>

      <div v-if="sysAssets.length" style="margin-bottom:18px">
        <div style="font-weight:800;font-size:14px;margin-bottom:10px">⚙️ Assets</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px">
          <div v-for="a in sysAssets" :key="a.id" class="panel chip" style="padding:14px 16px">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
              <span style="font-weight:800;font-size:13.5px">{{ assetType(a.asset_type) }} · {{ a.id }}</span>
              <span class="badge" :class="assetCls(a.status)">{{ a.status }}</span>
            </div>
            <div class="c-sub" style="font-size:12px;margin-top:5px">{{ a.location || '—' }}<template v-if="a.model"> · {{ a.model }}</template></div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
              <span v-if="a.next_service" class="badge b-orange">🔧 {{ a.next_service }}</span>
              <span v-if="a.cert_expiry" class="badge b-blue">📜 {{ a.cert_expiry }}</span>
              <span v-if="a.fuel_capacity" class="badge b-gray">⛽ {{ a.fuel_level || 0 }}/{{ a.fuel_capacity }}L</span>
            </div>
            <div v-if="a.notes" class="c-sub" style="font-size:11.5px;margin-top:7px">{{ a.notes }}</div>
            <div v-if="canManage" style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
              <button @click="openAssetStatus(a)" class="btn-ghost" style="padding:5px 10px;font-size:11.5px">🔧 Status</button>
              <button @click="openServiceForm(a)" class="btn-ghost" style="padding:5px 10px;font-size:11.5px">🛠️ Service</button>
              <button v-if="a.fuel_capacity" @click="openFuelForm(a)" class="btn-ghost" style="padding:5px 10px;font-size:11.5px">⛽ Refuel</button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="sysServices.length" style="margin-bottom:18px">
        <div style="font-weight:800;font-size:14px;margin-bottom:10px">🛠️ Recent services</div>
        <div class="panel" style="overflow:hidden">
          <div class="tbl-wrap">
            <table class="kr" style="width:100%">
              <thead><tr><th>ID</th><th>Asset</th><th>Date</th><th>Type</th><th>Technician</th><th style="text-align:right">Cost</th></tr></thead>
              <tbody>
                <tr v-for="s in sysServices.slice(0, 8)" :key="s.id">
                  <td style="font-weight:700;white-space:nowrap">{{ s.id }}</td>
                  <td>{{ s.asset }}</td>
                  <td class="c-sub">{{ s.service_date }}</td>
                  <td>{{ s.service_type }}</td>
                  <td class="c-sub">{{ s.technician || '—' }}</td>
                  <td style="text-align:right;font-weight:700">{{ money(s.cost) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div v-if="sysFuel.length">
        <div style="font-weight:800;font-size:14px;margin-bottom:10px">⛽ Fuel refills</div>
        <div class="panel" style="overflow:hidden">
          <div class="tbl-wrap">
            <table class="kr" style="width:100%">
              <thead><tr><th>ID</th><th>Asset</th><th>Date</th><th style="text-align:right">Liters</th><th style="text-align:right">Rate/L</th><th style="text-align:right">Amount</th></tr></thead>
              <tbody>
                <tr v-for="f in sysFuel.slice(0, 8)" :key="f.id">
                  <td style="font-weight:700;white-space:nowrap">{{ f.id }}</td>
                  <td>{{ f.asset }}</td>
                  <td class="c-sub">{{ f.refill_date }}</td>
                  <td style="text-align:right">{{ f.liters }}</td>
                  <td style="text-align:right">{{ money(f.rate_per_litre) }}</td>
                  <td style="text-align:right;font-weight:700">{{ money(f.amount) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div v-if="!sysAssets.length" class="panel" style="padding:36px;text-align:center;color:var(--text-mute)">No building assets.</div>
    </template>

    <!-- ══ HEALTH CHECK ══ -->
    <template v-if="tab === 'health' && !loading">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px">
        <div class="panel chip" style="padding:15px"><div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Plans</div><div style="font-size:23px;font-weight:900;margin-top:4px">{{ plans.length }}</div></div>
        <div class="panel chip" style="padding:15px"><div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Overdue</div><div style="font-size:23px;font-weight:900;margin-top:4px;color:var(--danger,#e74c3c)">{{ planStats.overdue || 0 }}</div></div>
        <div class="panel chip" style="padding:15px"><div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Upcoming</div><div style="font-size:23px;font-weight:900;margin-top:4px;color:var(--ok,#2ecc71)">{{ planStats.upcoming || 0 }}</div></div>
        <div class="panel chip" style="padding:15px"><div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Completed</div><div style="font-size:23px;font-weight:900;margin-top:4px">{{ planStats.by_status?.Completed || 0 }}</div></div>
      </div>

      <div style="display:flex;gap:8px;margin-bottom:14px">
        <button v-if="canManage" @click="openPlanForm" style="padding:10px 16px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:13px;cursor:pointer">＋ New plan</button>
      </div>

      <div v-if="plans.length">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:12px">
          <div v-for="p in plans" :key="p.id" class="panel chip" style="padding:14px 16px">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
              <span style="font-weight:800;font-size:13.5px">{{ SERVICE[p.service] || p.service }} · {{ p.id }}</span>
              <span class="badge" :class="planCls(p.status)">{{ p.status }}</span>
            </div>
            <div class="c-sub" style="font-size:12px;margin-top:5px">{{ SEASON[p.season] || p.season }}<template v-if="p.scheduled_for"> · {{ p.scheduled_for }}</template></div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
              <span v-if="p.assigned_to" class="badge b-blue">👤 {{ p.assigned_to }}</span>
              <span v-if="p.cost" class="badge b-gray">{{ money(p.cost) }}</span>
              <span v-if="p.checklist && p.checklist.length" class="badge b-gray">{{ p.checklist.filter(c => c.done).length }}/{{ p.checklist.length }} tasks</span>
            </div>
            <div v-if="canManage && !['Completed', 'Skipped'].includes(p.status)" style="margin-top:10px">
              <button @click="advancePlan(p)" class="btn-ghost" style="padding:5px 10px;font-size:11.5px">⏩ Advance → {{ PLAN_STATUSES[Math.min(PLAN_STATUSES.indexOf(p.status) + 1, PLAN_STATUSES.length - 1)] }}</button>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="panel" style="padding:36px;text-align:center;color:var(--text-mute)">No health plans.</div>
    </template>

    <!-- ══ MODALS ══ -->
    <template v-if="showLockForm">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="showLockForm = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(480px,94vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:80px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">＋ Grant smart lock</div>
          <button @click="showLockForm = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:12px">
          <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Lock name *</label><input v-model="lockForm.lock_name" placeholder="e.g. Showing code — U-006" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px"></div>
          <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Purpose</label>
            <select v-model="lockForm.purpose" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
              <option v-for="(v, k) in LOCK_PURPOSE" :key="k" :value="k">{{ v }}</option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Property</label><input v-model="lockForm.prop" placeholder="P-001" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px"></div>
            <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Unit</label><input v-model="lockForm.unit" placeholder="U-006" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px"></div>
          </div>
          <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Granted to</label><input v-model="lockForm.grant_for" placeholder="e.g. Tanvir Ahmed" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px"></div>
          <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Notes</label><textarea v-model="lockForm.notes" rows="2" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px;resize:vertical"></textarea></div>
          <button @click="createLock" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:13.5px;cursor:pointer">🔑 Create lock</button>
        </div>
      </div>
    </template>

    <template v-if="showAssetStatus">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="showAssetStatus = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(420px,94vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:80px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">⚙️ {{ assetStatusFor?.id }} status</div>
          <button @click="showAssetStatus = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;display:flex;flex-direction:column;gap:14px">
          <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Status</label>
            <select v-model="assetStatusVal" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
              <option value="operational">operational</option><option value="service_due">service_due</option><option value="faulty">faulty</option><option value="decommissioned">decommissioned</option>
            </select>
          </div>
          <button @click="setAssetStatus" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:13.5px;cursor:pointer">💾 Save status</button>
        </div>
      </div>
    </template>

    <template v-if="showServiceForm">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="showServiceForm = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(460px,94vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:80px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">🛠️ Record service — {{ serviceFor?.id }}</div>
          <button @click="showServiceForm = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:12px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Type</label>
              <select v-model="svcForm.service_type" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
                <option value="routine">routine</option><option value="repair">repair</option><option value="major">major</option><option value="amc">amc</option>
              </select>
            </div>
            <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Cost ৳</label><input v-model="svcForm.cost" type="number" placeholder="0" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px"></div>
          </div>
          <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Technician *</label><input v-model="svcForm.technician" placeholder="e.g. Jahidul Islam" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px"></div>
          <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Vendor</label><input v-model="svcForm.vendor" placeholder="e.g. DESCO Sub" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px"></div>
          <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Notes</label><textarea v-model="svcForm.notes" rows="2" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px;resize:vertical"></textarea></div>
          <button @click="recordService" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:13.5px;cursor:pointer">🛠️ Record service</button>
        </div>
      </div>
    </template>

    <template v-if="showFuelForm">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="showFuelForm = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(440px,94vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:80px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">⛽ Refuel — {{ fuelFor?.id }}</div>
          <button @click="showFuelForm = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:12px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Liters *</label><input v-model="fuelForm.liters" type="number" placeholder="100" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px"></div>
            <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Rate/L ৳</label><input v-model="fuelForm.rate_per_litre" type="number" placeholder="114" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px"></div>
          </div>
          <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Vendor</label><input v-model="fuelForm.vendor" placeholder="e.g. Padma Oil" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px"></div>
          <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Notes</label><textarea v-model="fuelForm.notes" rows="2" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px;resize:vertical"></textarea></div>
          <button @click="recordFuel" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:13.5px;cursor:pointer">⛽ Record refill</button>
        </div>
      </div>
    </template>

    <template v-if="showPlanForm">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="showPlanForm = false"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(480px,94vw);background:var(--card);z-index:71;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column">
        <div class="d-cover" style="height:80px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="position:absolute;left:18px;bottom:14px;font-size:17px;font-weight:800;color:#fff">＋ New health plan</div>
          <button @click="showPlanForm = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:12px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Season</label>
              <select v-model="planForm.season" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
                <option value="pre_monsoon">Pre-monsoon</option><option value="pre_summer">Pre-summer</option><option value="quarterly">Quarterly</option>
              </select>
            </div>
            <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Service</label>
              <select v-model="planForm.service" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px">
                <option value="ac_service">AC service</option><option value="roof_waterproof">Roof waterproof</option><option value="drainage_clear">Drainage clear</option><option value="deep_clean">Deep clean</option><option value="pest_control">Pest control</option>
              </select>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Scheduled (YYYY-MM)</label><input v-model="planForm.scheduled_for" type="month" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px"></div>
            <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Property</label><input v-model="planForm.prop" placeholder="P-001" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px"></div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Assigned to</label><input v-model="planForm.assigned_to" placeholder="Vendor" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px"></div>
            <div><label style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase">Cost ৳</label><input v-model="planForm.cost" type="number" placeholder="0" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;margin-top:5px"></div>
          </div>
          <button @click="createPlan" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:13.5px;cursor:pointer">🌦️ Create plan</button>
        </div>
      </div>
    </template>
  </div>
</template>
