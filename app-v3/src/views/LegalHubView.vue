<script setup>
// ─────────────────────────────────────────────────────────────
// Legal Hub (V2.0.7) — one dashboard for everything legal.
// Merged from the old 4-item Legal group into a single
// /legal-hub page with tabs (like Finance):
//   Overview · Compliance · Legal Engine · Cases · Legal Concierge
// Overview = legal command center (KPIs from bootstrap, instant).
// Other tabs embed the existing views via lazy components
// (KeepAlive + :key preserves state between switches).
// NOTE: route is /legal-hub (plain /legal is the Legal Engine tab).
// ─────────────────────────────────────────────────────────────
import { ref, computed, defineAsyncComponent, watch } from 'vue'
import { t } from '../lib/i18n'
import { useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import ScrollTabs from '../components/ScrollTabs.vue'

const route = useRoute()
const data = useDataStore()

const TAB_ORDER = [
  ['overview', '📊', 'Overview'],
  ['compliance', '⚖️', 'Compliance'],
  ['legal', '📜', t('Legal Engine')],
  ['cases', '👨‍⚖️', 'Cases'],
  ['concierge', '🗂️', t('Legal Concierge')],
]

const VIEWS = {
  compliance: defineAsyncComponent(() => import('./ComplianceView.vue')),
  legal: defineAsyncComponent(() => import('./LegalView.vue')),
  cases: defineAsyncComponent(() => import('./CasesView.vue')),
  concierge: defineAsyncComponent(() => import('./ConciergeView.vue')),
}

const tab = ref('overview')
watch(() => route.query.tab, (t) => {
  if (t && TAB_ORDER.some(([k]) => k === t)) tab.value = t
}, { immediate: true })

// ── Overview: instant KPIs from the bootstrap store ──
const compAll = computed(() => data.list('compliance_items'))
const casesAll = computed(() => data.list('cases'))
const legalNotesAll = computed(() => data.list('legal_notices'))
const concAll = computed(() => data.list('concierge_requests'))

const today = () => new Date().toISOString().slice(0, 10)
const daysTo = (d) => d ? Math.ceil((new Date(d) - new Date()) / 86400000) : null

const compSoon = computed(() => compAll.value.filter(c => {
  const dd = daysTo(c.expiry_date)
  return dd !== null && dd >= 0 && dd <= 30 && !['Expired', 'Compliant', 'Renewed'].includes(c.status)
}).length)
const compOverdue = computed(() => compAll.value.filter(c => {
  const dd = daysTo(c.expiry_date)
  return dd !== null && dd < 0 && !['Expired', 'Compliant', 'Renewed'].includes(c.status)
}).length)
const openCases = computed(() => casesAll.value.filter(c => String(c.status).toLowerCase() === 'open').length)
const concActive = computed(() => concAll.value.filter(r => ['In_Progress', 'Under_Review', 'Docs_Requested'].includes(r.status)).length)
const concFee = computed(() => concAll.value.filter(r => r.status === 'Awaiting_Fee' || (r.fee_status === 'unpaid' && r.fee > 0)).length)

const kpis = computed(() => ({
  comp: compAll.value.length, compSoon: compSoon.value, compOverdue: compOverdue.value,
  cases: casesAll.value.length, openCases: openCases.value,
  notes: legalNotesAll.value.length, conc: concAll.value.length, concActive: concActive.value, concFee: concFee.value,
}))

const soonComp = computed(() => [...compAll.value]
  .filter(c => c.expiry_date)
  .sort((a, b) => String(a.expiry_date).localeCompare(String(b.expiry_date)))
  .slice(0, 5))
const recentCases = computed(() => [...casesAll.value].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || ''))).slice(0, 5))

const goTab = (t) => { tab.value = t }
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('⚖️ Legal') }}</h1>
        <div class="sub">{{ t('Everything legal — compliance, legal engine, cases & concierge · one dashboard') }}</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-ghost" @click="goTab('cases')">➕ New case</button>
      </div>
    </div>

    <!-- Tabs -->
    <ScrollTabs>
      <button v-for="[k, ico, l] in TAB_ORDER" :key="k" @click="goTab(k)"
        :style="tab === k ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'">
        {{ ico }} {{ t(l) }}
      </button>
    </ScrollTabs>

    <!-- ══ OVERVIEW ══ -->
    <template v-if="tab === 'overview'">
      <!-- KPI cards -->
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">⚖️</span>{{ t('Compliance items') }}</div><div class="s-value">{{ kpis.comp }}</div><div class="s-trend">{{ kpis.compSoon }} expiring ≤30d</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⏰</span>{{ t('Overdue') }}</div><div class="s-value" :style="kpis.compOverdue > 0 ? 'color:var(--danger,#e74c3c)' : ''">{{ kpis.compOverdue }}</div><div class="s-trend">expired items</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">👨‍⚖️</span>{{ t('Cases') }}</div><div class="s-value">{{ kpis.cases }}</div><div class="s-trend">{{ kpis.openCases }} open</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">📜</span>{{ t('Legal notices') }}</div><div class="s-value">{{ kpis.notes }}</div><div class="s-trend">generated</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🗂️</span>{{ t('Concierge requests') }}</div><div class="s-value">{{ kpis.conc }}</div><div class="s-trend">{{ kpis.concActive }} in progress</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">💳</span>{{ t('Awaiting fee') }}</div><div class="s-value" :style="kpis.concFee > 0 ? 'color:#f39c12' : ''">{{ kpis.concFee }}</div><div class="s-trend">unpaid concierge</div></div>
      </div>

      <!-- Quick actions -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0">
        <button @click="goTab('compliance')" style="padding:9px 15px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:12.5px;cursor:pointer">⚖️ Compliance</button>
        <button @click="goTab('legal')" class="btn-ghost">📜 Legal Engine</button>
        <button @click="goTab('cases')" class="btn-ghost">👨‍⚖️ Cases</button>
        <button @click="goTab('concierge')" class="btn-ghost">🗂️ Legal Concierge</button>
      </div>

      <!-- lists -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;margin-bottom:14px">
        <div class="panel" style="padding:16px 18px">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">⚖️ Compliance <span class="c-sub">— by expiry</span></div>
          <div v-for="c in soonComp" :key="c.id" style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed var(--border);font-size:12.5px">
            <div style="overflow:hidden">
              <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ c.label || c.id }}</div>
              <div class="c-sub" style="font-size:11px">{{ c.entity_type || '' }} {{ c.entity_id || '' }} · {{ c.ref_no || '' }}</div>
            </div>
            <div style="text-align:right;white-space:nowrap">
              <div style="font-weight:800;font-size:11.5px" :style="daysTo(c.expiry_date) !== null && daysTo(c.expiry_date) < 0 ? 'color:var(--danger,#e74c3c)' : 'color:#f39c12'">{{ c.expiry_date || '—' }}</div>
            </div>
          </div>
          <div v-if="!soonComp.length" style="padding:14px 0;text-align:center;color:var(--text-mute);font-size:12.5px">{{ t('No compliance items.') }}</div>
        </div>
        <div class="panel" style="padding:16px 18px">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">👨‍⚖️ Recent cases</div>
          <div v-for="c in recentCases" :key="c.id" style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed var(--border);font-size:12.5px">
            <div style="overflow:hidden">
              <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ c.title || c.id }}</div>
              <div class="c-sub" style="font-size:11px">{{ c.type || '' }} · {{ c.stage || '' }}</div>
            </div>
            <div style="text-align:right;white-space:nowrap">
              <span class="badge" :class="String(c.status).toLowerCase() === 'open' ? 'badge-red' : 'badge-green'">{{ c.status || '—' }}</span>
            </div>
          </div>
          <div v-if="!recentCases.length" style="padding:14px 0;text-align:center;color:var(--text-mute);font-size:12.5px">{{ t('No cases yet.') }}</div>
        </div>
      </div>
    </template>

    <!-- ══ EMBEDDED MODULES (KeepAlive + :key preserves each tab's state) ══ -->
    <KeepAlive>
      <component :is="VIEWS[tab]" :key="tab" v-if="tab !== 'overview' && VIEWS[tab]" />
    </KeepAlive>
  </div>
</template>
