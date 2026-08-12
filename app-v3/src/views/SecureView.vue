<script setup>
// ─────────────────────────────────────────────────────────────
// Safety & Security Hub (V2.0.7) — one dashboard for physical security.
// Merged from the old 4-item Safety & Security group into a single
// /secure page with tabs (like Finance):
//   Overview · Building Systems · Land Guard · Build Watch · Fire Safety
// Overview = security command center (KPIs from bootstrap, instant).
// Other tabs embed the existing views via lazy components
// (KeepAlive + :key preserves state between switches).
// ─────────────────────────────────────────────────────────────
import { ref, computed, defineAsyncComponent, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import ScrollTabs from '../components/ScrollTabs.vue'

const route = useRoute()
const data = useDataStore()

const TAB_ORDER = [
  ['overview', '📊', 'Overview'],
  ['smarthome', '🏠', 'Building Systems'],
  ['land', '🛰️', 'Land Guard'],
  ['build', '🏗️', 'Build Watch'],
  ['firesafety', '🧯', 'Fire Safety'],
  ['inspections', '🔍', 'Inspections'],
  ['kyc', '🪪', 'Tenant KYC'],
]

const VIEWS = {
  smarthome: defineAsyncComponent(() => import('./BuildingSystemsView.vue')),
  land: defineAsyncComponent(() => import('./LandView.vue')),
  build: defineAsyncComponent(() => import('./BuildView.vue')),
  firesafety: defineAsyncComponent(() => import('./FireSafetyView.vue')),
  inspections: defineAsyncComponent(() => import('./InspectionsView.vue')),
  kyc: defineAsyncComponent(() => import('./KycView.vue')),
}

const tab = ref('overview')
watch(() => route.query.tab, (t) => {
  if (t && TAB_ORDER.some(([k]) => k === t)) tab.value = t
}, { immediate: true })

// ── Overview: instant KPIs from the bootstrap store ──
const sysAll = computed(() => data.list('sys_assets'))
const locksAll = computed(() => data.list('smart_locks'))
const camAll = computed(() => data.list('cctv_cameras'))
const landAll = computed(() => data.list('land_parcels'))
const landVisitsAll = computed(() => data.list('land_visits'))
const buildAll = computed(() => data.list('build_projects'))
const fireAll = computed(() => data.list('fire_assets'))
const fireIncAll = computed(() => data.list('fire_incidents'))

const today = () => new Date().toISOString().slice(0, 10)

const landSecure = computed(() => landAll.value.filter(p => p.status === 'Secure').length)
const landReview = computed(() => landAll.value.filter(p => p.status === 'Needs Review' || p.status === 'Encroached').length)
const buildActive = computed(() => buildAll.value.filter(p => !['Completed', 'Cancelled'].includes(p.status)).length)
const fireOpen = computed(() => fireIncAll.value.filter(i => !['Resolved', 'Closed'].includes(i.status)).length)

const kpis = computed(() => ({
  sys: sysAll.value.length, locks: locksAll.value.length, cams: camAll.value.length,
  land: landAll.value.length, landSecure: landSecure.value, landReview: landReview.value,
  build: buildAll.value.length, buildActive: buildActive.value, fire: fireAll.value.length, fireOpen: fireOpen.value,
}))

const upcomingVisits = computed(() => [...landVisitsAll.value]
  .filter(v => (v.scheduled_for || '') >= today())
  .sort((a, b) => String(a.scheduled_for).localeCompare(String(b.scheduled_for)))
  .slice(0, 5))
const fireRecent = computed(() => [...fireIncAll.value].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || ''))).slice(0, 5))

const goTab = (t) => { tab.value = t }
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🏠 Safety &amp; Security</h1>
        <div class="sub">Physical security — building systems, land guard, build watch &amp; fire safety · one dashboard</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-ghost" @click="goTab('smarthome')">➕ Add system</button>
      </div>
    </div>

    <!-- Tabs -->
    <ScrollTabs>
      <button v-for="[k, ico, l] in TAB_ORDER" :key="k" @click="goTab(k)"
        :style="tab === k ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'">
        {{ ico }} {{ l }}
      </button>
    </ScrollTabs>

    <!-- ══ OVERVIEW ══ -->
    <template v-if="tab === 'overview'">
      <!-- KPI cards -->
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">🏠</span>Building systems</div><div class="s-value">{{ kpis.sys }}</div><div class="s-trend">assets tracked</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🔒</span>Smart locks</div><div class="s-value">{{ kpis.locks }}</div><div class="s-trend">{{ kpis.cams }} CCTV cameras</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🛰️</span>Land parcels</div><div class="s-value">{{ kpis.land }}</div><div class="s-trend">{{ kpis.landSecure }} secure · {{ kpis.landReview }} review</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🏗️</span>Build projects</div><div class="s-value">{{ kpis.build }}</div><div class="s-trend">{{ kpis.buildActive }} active</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🧯</span>Fire assets</div><div class="s-value">{{ kpis.fire }}</div><div class="s-trend">registered</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🚨</span>Fire incidents</div><div class="s-value" :style="kpis.fireOpen > 0 ? 'color:var(--danger,#e74c3c)' : ''">{{ kpis.fireOpen }}</div><div class="s-trend">open</div></div>
      </div>

      <!-- Quick actions -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0">
        <button @click="goTab('smarthome')" style="padding:9px 15px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:12.5px;cursor:pointer">🏠 Building Systems</button>
        <button @click="goTab('land')" class="btn-ghost">🛰️ Land Guard</button>
        <button @click="goTab('build')" class="btn-ghost">🏗️ Build Watch</button>
        <button @click="goTab('firesafety')" class="btn-ghost">🧯 Fire Safety</button>
        <button @click="goTab('inspections')" class="btn-ghost">🔍 Inspections</button>
        <button @click="goTab('kyc')" class="btn-ghost">🪪 Tenant KYC</button>
      </div>

      <!-- lists -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;margin-bottom:14px">
        <div class="panel" style="padding:16px 18px">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">🛰️ Upcoming land visits</div>
          <div v-for="v in upcomingVisits" :key="v.id" style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed var(--border);font-size:12.5px">
            <div style="overflow:hidden">
              <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ v.parcel || v.purpose || v.id }}</div>
              <div class="c-sub" style="font-size:11px">{{ v.notes || v.purpose || '' }}</div>
            </div>
            <div style="text-align:right;white-space:nowrap">
              <div style="font-weight:800;font-size:11.5px;color:var(--primary)">{{ v.scheduled_for || '—' }}</div>
            </div>
          </div>
          <div v-if="!upcomingVisits.length" style="padding:14px 0;text-align:center;color:var(--text-mute);font-size:12.5px">No upcoming visits.</div>
        </div>
        <div class="panel" style="padding:16px 18px">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">🚨 Recent fire incidents</div>
          <div v-for="f in fireRecent" :key="f.id" style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed var(--border);font-size:12.5px">
            <div style="overflow:hidden">
              <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ f.title || f.location || f.id }}</div>
              <div class="c-sub" style="font-size:11px">{{ f.ts || '' }}</div>
            </div>
            <div style="text-align:right;white-space:nowrap">
              <span class="badge" :class="['Resolved','Closed'].includes(f.status) ? 'badge-green' : 'badge-red'">{{ f.status || '—' }}</span>
            </div>
          </div>
          <div v-if="!fireRecent.length" style="padding:14px 0;text-align:center;color:var(--text-mute);font-size:12.5px">No fire incidents recorded.</div>
        </div>
      </div>
    </template>

    <!-- ══ EMBEDDED MODULES (KeepAlive + :key preserves each tab's state) ══ -->
    <KeepAlive>
      <component :is="VIEWS[tab]" :key="tab" v-if="tab !== 'overview' && VIEWS[tab]" />
    </KeepAlive>
  </div>
</template>
