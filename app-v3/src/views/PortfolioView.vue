<script setup>
// ─────────────────────────────────────────────────────────────
// Portfolio Hub (V2.0.7) — one dashboard for everything property.
// Merged from the old 9-item Portfolio group into a single
// /portfolio page with tabs (like Finance):
//   Overview · Properties · Units · Tenants · Leases · Insurance ·
//   Onboarding · Leads · Documents · Templates
// Overview = portfolio command center (KPIs from bootstrap, instant).
// Other tabs embed the existing views via lazy components
// (KeepAlive + :key preserves state between switches).
// Tab is synced with ?tab= so each sidebar sub-item can deep-link.
// ─────────────────────────────────────────────────────────────
import { ref, computed, defineAsyncComponent, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'

const route = useRoute()
const data = useDataStore()

const TAB_ORDER = [
  ['overview', '📊', 'Overview'],
  ['properties', '🏢', 'Properties'],
  ['units', '🚪', 'Units'],
  ['tenants', '👤', 'Tenants'],
  ['leases', '📄', 'Leases'],
  ['insurance', '🛡️', 'Insurance'],
  ['onboarding', '📋', 'Onboarding'],
  ['leads', '📥', 'Leads'],
  ['documents', '📁', 'Documents'],
  ['templates', '🗂️', 'Templates'],
]

const VIEWS = {
  properties: defineAsyncComponent(() => import('./PropertiesView.vue')),
  units: defineAsyncComponent(() => import('./UnitsView.vue')),
  tenants: defineAsyncComponent(() => import('./TenantsView.vue')),
  leases: defineAsyncComponent(() => import('./LeasesView.vue')),
  insurance: defineAsyncComponent(() => import('./InsuranceView.vue')),
  onboarding: defineAsyncComponent(() => import('./OnboardingView.vue')),
  leads: defineAsyncComponent(() => import('./LeadsView.vue')),
  documents: defineAsyncComponent(() => import('./DocumentsView.vue')),
  templates: defineAsyncComponent(() => import('./TemplatesView.vue')),
}

const tab = ref('overview')
watch(() => route.query.tab, (t) => {
  if (t && TAB_ORDER.some(([k]) => k === t)) tab.value = t
}, { immediate: true })

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')

// ── Overview: instant KPIs from the bootstrap store ──
const propsAll = computed(() => data.list('properties'))
const unitsAll = computed(() => data.list('units'))
const tenantsAll = computed(() => data.list('tenants'))
const leasesAll = computed(() => data.list('leases'))
const insAll = computed(() => data.list('insurance_policies'))
const obAll = computed(() => data.list('onboarding_apps'))
const leadsAll = computed(() => data.list('leads'))
const docsAll = computed(() => data.list('documents'))

const activeLeases = computed(() => leasesAll.value.filter(l => String(l.status).toLowerCase() === 'active'))
const rentRoll = computed(() => activeLeases.value.reduce((s, l) => s + (Number(l.rent) || 0), 0))
const occupiedUnits = computed(() => new Set(activeLeases.value.map(l => l.u)).size)
const occupancy = computed(() => unitsAll.value.length ? Math.round(occupiedUnits.value / unitsAll.value.length * 100) : 0)

const kpis = computed(() => ({
  props: propsAll.value.length, units: unitsAll.value.length, tenants: tenantsAll.value.length,
  active: activeLeases.value.length, rent: rentRoll.value, occupancy: occupancy.value,
  ins: insAll.value.length, leads: leadsAll.value.length, docs: docsAll.value.length,
}))

const recentOnboarding = computed(() => [...obAll.value]
  .sort((a, b) => String(b.updated_at || b.ts || '').localeCompare(String(a.updated_at || a.ts || '')))
  .slice(0, 5))
const recentLeads = computed(() => [...leadsAll.value]
  .sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || '')))
  .slice(0, 5))

const goTab = (t) => { tab.value = t }
onMounted(() => { /* KPIs are reactive — nothing to fetch */ })
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🏢 Portfolio</h1>
        <div class="sub">Everything property — properties, units, tenants, leases, insurance, onboarding, leads, documents &amp; templates · one dashboard</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-ghost" @click="goTab('properties')">➕ New property</button>
      </div>
    </div>

    <!-- Tabs -->
    <div class="kr-tabs">
      <button v-for="[k, ico, l] in TAB_ORDER" :key="k" @click="goTab(k)"
        :style="tab === k ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'">
        {{ ico }} {{ l }}
      </button>
    </div>

    <!-- ══ OVERVIEW ══ -->
    <template v-if="tab === 'overview'">
      <!-- KPI cards -->
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🏢</span>Properties</div><div class="s-value">{{ kpis.props }}</div><div class="s-trend">{{ kpis.units }} units total</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🚪</span>Units</div><div class="s-value">{{ kpis.units }}</div><div class="s-trend">{{ kpis.occupancy }}% occupied</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">👤</span>Tenants</div><div class="s-value">{{ kpis.tenants }}</div><div class="s-trend">{{ kpis.active }} active leases</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📄</span>Monthly rent roll</div><div class="s-value" style="color:var(--ok,#12a150)">{{ money(kpis.rent) }}</div><div class="s-trend">from active leases</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🛡️</span>Insurance</div><div class="s-value">{{ kpis.ins }}</div><div class="s-trend">policies</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📥</span>Leads</div><div class="s-value">{{ kpis.leads }}</div><div class="s-trend">{{ kpis.docs }} documents</div></div>
      </div>

      <!-- Quick actions -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0">
        <button @click="goTab('properties')" style="padding:9px 15px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:12.5px;cursor:pointer">🏢 Properties</button>
        <button @click="goTab('units')" class="btn-ghost">🚪 Units</button>
        <button @click="goTab('tenants')" class="btn-ghost">👤 Tenants</button>
        <button @click="goTab('leases')" class="btn-ghost">📄 Leases</button>
        <button @click="goTab('onboarding')" class="btn-ghost">📋 Onboarding</button>
        <button @click="goTab('leads')" class="btn-ghost">📥 Leads</button>
        <button @click="goTab('documents')" class="btn-ghost">📁 Documents</button>
      </div>

      <!-- lists -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;margin-bottom:14px">
        <div class="panel" style="padding:16px 18px">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">📋 Onboarding pipeline <span class="c-sub">— recent</span></div>
          <div v-for="a in recentOnboarding" :key="a.id" style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed var(--border);font-size:12.5px">
            <div style="overflow:hidden">
              <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ a.name || a.id }}</div>
              <div class="c-sub" style="font-size:11px">{{ a.updated_at || a.ts || '' }}</div>
            </div>
            <div style="text-align:right;white-space:nowrap">
              <span class="badge" :class="a.status === 'Completed' ? 'badge-green' : 'badge-blue'">{{ a.status || '—' }}</span>
            </div>
          </div>
          <div v-if="!recentOnboarding.length" style="padding:14px 0;text-align:center;color:var(--text-mute);font-size:12.5px">No onboarding applications yet.</div>
        </div>
        <div class="panel" style="padding:16px 18px">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">📥 Recent leads</div>
          <div v-for="l in recentLeads" :key="l.id" style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed var(--border);font-size:12.5px">
            <div style="overflow:hidden">
              <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ l.name || l.id }}</div>
              <div class="c-sub" style="font-size:11px">{{ l.phone || l.email || '' }} · {{ l.prop || '' }}</div>
            </div>
            <div style="text-align:right;white-space:nowrap">
              <span class="badge" :class="l.status === 'Converted' ? 'badge-green' : 'badge-blue'">{{ l.status || '—' }}</span>
            </div>
          </div>
          <div v-if="!recentLeads.length" style="padding:14px 0;text-align:center;color:var(--text-mute);font-size:12.5px">No leads yet.</div>
        </div>
      </div>
    </template>

    <!-- ══ EMBEDDED MODULES (KeepAlive + :key preserves each tab's state) ══ -->
    <KeepAlive>
      <component :is="VIEWS[tab]" :key="tab" v-if="tab !== 'overview' && VIEWS[tab]" />
    </KeepAlive>
  </div>
</template>
