<script setup>
// ─────────────────────────────────────────────────────────────
// Community Hub (V2.0.7) — one dashboard for resident community.
// Merged from the old 4-item Community group into a single
// /community page with tabs (like Finance):
//   Overview · Notices · Referrals · NID & Trust · Support
// Overview = community command center (KPIs from bootstrap, instant).
// Other tabs embed the existing views via lazy components
// (KeepAlive + :key preserves state between switches).
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
  ['notices', '📢', 'Notice Board'],
  ['referrals', '🤝', 'Referrals'],
  ['trust', '🪪', 'NID & Trust'],
  ['support', '🎧', 'Support'],
]

const VIEWS = {
  notices: defineAsyncComponent(() => import('./NoticesView.vue')),
  referrals: defineAsyncComponent(() => import('./ReferralsView.vue')),
  trust: defineAsyncComponent(() => import('./NidView.vue')),
  support: defineAsyncComponent(() => import('./SupportView.vue')),
}

const tab = ref('overview')
watch(() => route.query.tab, (t) => {
  if (t && TAB_ORDER.some(([k]) => k === t)) tab.value = t
}, { immediate: true })

// ── Overview: instant KPIs from the bootstrap store ──
const notesAll = computed(() => data.list('notices'))
const refsAll = computed(() => data.list('referrals'))
const nidAll = computed(() => data.list('nid_verifications'))
const supAll = computed(() => data.list('support'))

const kpis = computed(() => ({
  notices: notesAll.value.length,
  pinned: notesAll.value.filter(n => n.pinned).length,
  referrals: refsAll.value.length,
  refSigned: refsAll.value.filter(r => r.status === 'Signed up').length,
  nid: nidAll.value.length,
  nidOk: nidAll.value.filter(v => String(v.status).toLowerCase() === 'verified').length,
  support: supAll.value.length,
  supportOpen: supAll.value.filter(s => !['Closed', 'Resolved'].includes(s.status)).length,
}))

const recentNotices = computed(() => [...notesAll.value].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || ''))).slice(0, 5))
const recentRefs = computed(() => [...refsAll.value].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || ''))).slice(0, 5))

const goTab = (t) => { tab.value = t }
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('📢 Community') }}</h1>
        <div class="sub">Resident community — notices, referrals, NID &amp; trust verification and support · one dashboard</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-ghost" @click="goTab('notices')">➕ New notice</button>
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
        <div class="stat"><div class="s-label"><span class="s-ico">📢</span>Notices</div><div class="s-value">{{ kpis.notices }}</div><div class="s-trend">{{ kpis.pinned }} pinned</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🤝</span>Referrals</div><div class="s-value">{{ kpis.referrals }}</div><div class="s-trend">{{ kpis.refSigned }} signed up</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🪪</span>NID verifications</div><div class="s-value">{{ kpis.nid }}</div><div class="s-trend">{{ kpis.nidOk }} verified</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🎧</span>Support tickets</div><div class="s-value" :style="kpis.supportOpen > 0 ? 'color:#f39c12' : ''">{{ kpis.support }}</div><div class="s-trend">{{ kpis.supportOpen }} open</div></div>
      </div>

      <!-- Quick actions -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0">
        <button @click="goTab('notices')" style="padding:9px 15px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:12.5px;cursor:pointer">📢 Notice Board</button>
        <button @click="goTab('referrals')" class="btn-ghost">🤝 Referrals</button>
        <button @click="goTab('trust')" class="btn-ghost">🪪 NID &amp; Trust</button>
        <button @click="goTab('support')" class="btn-ghost">🎧 Support</button>
      </div>

      <!-- lists -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;margin-bottom:14px">
        <div class="panel" style="padding:16px 18px">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">📢 Recent notices</div>
          <div v-for="n in recentNotices" :key="n.id" style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed var(--border);font-size:12.5px">
            <div style="overflow:hidden">
              <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ n.title || n.id }}</div>
              <div class="c-sub" style="font-size:11px">{{ n.author || '' }} · {{ n.ts || '' }}</div>
            </div>
            <div style="text-align:right;white-space:nowrap">
              <span v-if="n.pinned" class="badge badge-red">📌</span>
            </div>
          </div>
          <div v-if="!recentNotices.length" style="padding:14px 0;text-align:center;color:var(--text-mute);font-size:12.5px">No notices yet.</div>
        </div>
        <div class="panel" style="padding:16px 18px">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">🤝 Recent referrals</div>
          <div v-for="r in recentRefs" :key="r.id" style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed var(--border);font-size:12.5px">
            <div style="overflow:hidden">
              <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ r.name || r.referee || r.id }}</div>
              <div class="c-sub" style="font-size:11px">{{ r.ts || '' }}</div>
            </div>
            <div style="text-align:right;white-space:nowrap">
              <span class="badge" :class="r.status === 'Paid' ? 'badge-green' : 'badge-blue'">{{ r.status || '—' }}</span>
            </div>
          </div>
          <div v-if="!recentRefs.length" style="padding:14px 0;text-align:center;color:var(--text-mute);font-size:12.5px">No referrals yet.</div>
        </div>
      </div>
    </template>

    <!-- ══ EMBEDDED MODULES (KeepAlive + :key preserves each tab's state) ══ -->
    <KeepAlive>
      <component :is="VIEWS[tab]" :key="tab" v-if="tab !== 'overview' && VIEWS[tab]" />
    </KeepAlive>
  </div>
</template>
