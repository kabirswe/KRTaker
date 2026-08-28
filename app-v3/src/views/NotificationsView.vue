<script setup>
import { computed, ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { apiCall } from '../api/client'
import { lang } from '../lib/i18n'
import { track } from '../lib/analytics'
import { notifMeta, notifTarget } from '../lib/ui'

const router = useRouter()

const alerts = ref([])
const unread = ref(0)
const busy = ref(false)
const filterType = ref('all')

const timeAgo = (ts) => {
  if (!ts) return '—'
  const d = new Date(String(ts).replace(' ', 'T'))
  if (isNaN(d)) return String(ts).slice(0, 10)
  const s = (Date.now() - d.getTime()) / 1000
  if (s < 60) return 'just now'
  if (s < 3600) return Math.max(1, Math.floor(s / 60)) + 'm ago'
  if (s < 86400) return Math.floor(s / 3600) + 'h ago'
  if (s < 604800) return Math.floor(s / 86400) + 'd ago'
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

async function load() {
  try {
    const r = await apiCall('app-kr-alert', { action: 'list' })
    if (r.ok) { alerts.value = r.alerts || []; unread.value = r.unread || 0 }
  } catch (e) { /* silent */ }
}

const typeCounts = computed(() => {
  const m = {}
  alerts.value.forEach(a => { m[a.type] = (m[a.type] || 0) + 1 })
  return m
})
const typePills = computed(() => {
  const pills = [{ type: 'all', label: lang.value === 'bn' ? 'সব' : 'All', count: alerts.value.length }]
  Object.keys(typeCounts.value).forEach(t => {
    const meta = notifMeta(t)
    pills.push({ type: t, label: lang.value === 'bn' ? (meta.bn || meta.label) : meta.label, count: typeCounts.value[t] })
  })
  return pills
})
const filtered = computed(() => {
  if (filterType.value === 'all') return alerts.value
  return alerts.value.filter(a => a.type === filterType.value)
})

const sevIco = (s) => s === 'critical' ? '🚨' : (s === 'warning' ? '⚠️' : (s === 'success' ? '✅' : '🔔'))

async function markRead(a) {
  if (a.read_at) return
  a.read_at = new Date().toISOString() // optimistic
  unread.value = Math.max(0, unread.value - 1)
  try { await apiCall('app-kr-alert', { action: 'read', id: a.id }) } catch (e) { load() }
}
async function markAllRead() {
  if (!unread.value || busy.value) return
  busy.value = true
  try {
    await apiCall('app-kr-alert', { action: 'read-all' })
    alerts.value.forEach(a => { if (!a.read_at) a.read_at = new Date().toISOString() })
    unread.value = 0
  } finally { busy.value = false }
}
async function dismiss(a) {
  busy.value = true
  try {
    await apiCall('app-kr-alert', { action: 'dismiss', id: a.id })
    alerts.value = alerts.value.filter(x => x.id !== a.id)
  } finally { busy.value = false }
}
async function dismissAll() {
  if (!alerts.value.length || busy.value) return
  busy.value = true
  try {
    await apiCall('app-kr-alert', { action: 'dismiss-all' })
    alerts.value = []
    unread.value = 0
  } finally { busy.value = false }
}
function openAlert(a) {
  markRead(a)
  const t = notifTarget(a)
  track('notification_opened', { type: a.type || '', severity: a.severity || '' })
  router.push({ path: t.path, query: t.query })
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🔔 {{ lang === 'bn' ? 'নোটিফিকেশন' : 'Notifications' }}</h1>
        <div class="sub">
          <template v-if="alerts.length">{{ alerts.length }} notification{{ alerts.length === 1 ? '' : 's' }} · <span :style="unread ? 'color:var(--danger);font-weight:800' : ''">{{ unread }} unread</span></template>
          <template v-else>Your inbox is quiet</template>
        </div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn-ghost" :disabled="busy || !unread" @click="markAllRead" style="padding:9px 14px;font-size:12.5px">✓ {{ lang === 'bn' ? 'সব পঠিত করুন' : 'Mark all read' }}</button>
        <button class="btn-ghost" :disabled="busy || !alerts.length" @click="dismissAll" style="padding:9px 14px;font-size:12.5px">🗑 {{ lang === 'bn' ? 'সব মুছুন' : 'Clear all' }}</button>
      </div>
    </div>

    <!-- type filter pills -->
    <div v-if="typePills.length > 1" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
      <button v-for="p in typePills" :key="p.type" @click="filterType = p.type"
        class="badge" :class="'b-gray'"
        :style="filterType === p.type ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : 'background:var(--card);color:var(--text);border:1px solid var(--border)'"
        style="padding:7px 13px;font-size:12.5px;font-weight:800;cursor:pointer">
        {{ notifMeta(p.type).ico }} {{ p.label }} <span :style="filterType === p.type ? 'opacity:.8' : ''" style="font-size:11px">{{ p.count }}</span>
      </button>
    </div>

    <div v-if="!alerts.length" style="padding:60px 20px;text-align:center;color:var(--text-mute);font-size:14px">
      <div style="font-size:44px;margin-bottom:12px">🎉</div>
      {{ lang === 'bn' ? 'সব শান্ত — কোনো খোলা নোটিফিকেশন নেই।' : 'All caught up — no open notifications.' }}
    </div>

    <div v-else class="panel" style="padding:0;overflow:hidden">
      <div v-for="a in filtered" :key="a.id"
        @click="openAlert(a)"
        :style="{
          display: 'flex', gap: '12px', padding: '14px 16px', cursor: 'pointer',
          borderBottom: '1px solid var(--border)',
          background: a.read_at ? 'transparent' : 'var(--bg-alt)',
          opacity: a.read_at ? 0.72 : 1,
        }">
        <div style="font-size:20px;flex-shrink:0;width:34px;height:34px;border-radius:10px;background:var(--bg-alt);display:flex;align-items:center;justify-content:center">{{ notifMeta(a.type).ico }}</div>
        <div style="flex:1;min-width:0">
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <span v-if="!a.read_at" style="width:8px;height:8px;border-radius:999px;background:var(--danger);flex-shrink:0"></span>
            <span style="font-weight:800;font-size:13.5px">{{ a.title }}</span>
            <span class="badge" :class="notifMeta(a.type).cls" style="font-size:10.5px">{{ notifMeta(a.type).label }}</span>
            <span class="badge b-gray" style="font-size:10.5px">{{ sevIco(a.severity) }} {{ a.severity }}</span>
          </div>
          <div v-if="a.body" class="c-sub" style="font-size:12.5px;margin-top:4px;line-height:1.55;white-space:pre-wrap">{{ a.body }}</div>
          <div style="display:flex;gap:8px;align-items:center;margin-top:6px;flex-wrap:wrap">
            <span v-if="a.ref" class="badge b-gray" style="font-size:10.5px">{{ a.ref }}</span>
            <span class="c-sub" style="font-size:11px">{{ timeAgo(a.ts) }}</span>
            <span class="c-sub" style="font-size:11px">→ open {{ notifMeta(a.type).label }}</span>
          </div>
        </div>
        <button @click.stop="dismiss(a)" :disabled="busy" title="Dismiss" class="close"
          style="color:var(--text-mute);font-size:14px;font-weight:800">✕</button>
      </div>
    </div>
  </div>
</template>
