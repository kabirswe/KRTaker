<script setup>
import { computed } from 'vue'
import { useDataStore } from '../stores/data'

const data = useDataStore()

const cases = computed(() => data.list('cases'))
const legalDocs = computed(() => data.list('legal_docs'))
const legalNotices = computed(() => data.list('legal_notices'))
const caseEvents = computed(() => data.list('case_events'))

const openCases = computed(() => cases.value.filter(c => c.status === 'Open').length)
const byType = computed(() => {
  const m = {}
  cases.value.forEach(c => { const k = c.type || 'other'; m[k] = (m[k] || 0) + 1 })
  return Object.entries(m)
})
function money(n) { return '৳' + Math.round(n || 0).toLocaleString('en-IN') }
function badge(st) { return ({ Open: 'b-red', Closed: 'b-green', Hearing: 'b-blue', Negotiation: 'b-orange', Won: 'b-green', Lost: 'b-red' })[st] || 'b-gray' }
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>📜 Legal Engine</h1>
        <div class="sub">{{ cases.length }} cases · {{ legalDocs.length }} KB docs · {{ legalNotices.length }} notices</div>
      </div>
    </div>

    <div class="stats">
      <div class="stat"><div class="s-label"><span class="s-ico">⚖️</span>Cases</div><div class="s-value">{{ cases.length }}</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">🔴</span>Open</div><div class="s-value">{{ openCases }}</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">📚</span>KB docs</div><div class="s-value">{{ legalDocs.length }}</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">📢</span>Notices</div><div class="s-value">{{ legalNotices.length }}</div></div>
    </div>

    <div class="grid grid-2">
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">⚖️</span>Cases</div></div>
        <div class="panel-b" style="padding:8px 18px">
          <div v-for="c in cases" :key="c.id" style="padding:11px 0;border-bottom:1px dashed var(--border)">
            <div style="display:flex;justify-content:space-between;gap:10px">
              <div style="font-weight:700;font-size:13.5px">{{ c.title }}</div>
              <span class="badge" :class="badge(c.status)">{{ c.status }}</span>
            </div>
            <div class="c-sub" style="margin-top:3px">{{ c.type }} · opened {{ c.opened }} · lease {{ c.ref_lease || '—' }}</div>
            <div v-if="c.notes" class="c-sub" style="margin-top:2px;white-space:normal">{{ c.notes }}</div>
          </div>
          <div v-if="!cases.length" class="c-sub" style="padding:14px">No cases yet.</div>
        </div>
      </div>
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">🏷️</span>Case types</div></div>
        <div class="panel-b">
          <div v-for="[k, v] in byType" :key="k" class="kv"><span class="k">{{ k }}</span><span class="v">{{ v }}</span></div>
          <div v-if="!byType.length" class="c-sub">No data.</div>
        </div>
      </div>
    </div>
  </div>
</template>
