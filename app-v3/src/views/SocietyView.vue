<script setup>
// ─────────────────────────────────────────────────────────────
// Society Hub (V2.31.0) — community suite for buildings & societies.
// Tabs: Parking · Amenity Bookings · Voting · Forums · Events · Samity (V2.31.6)
// Backed by the app-community API (POST actions; GET list/thread).
// ─────────────────────────────────────────────────────────────
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { lang, t } from '../lib/i18n'
import { fmtTs, badge } from '../lib/ui'
import ScrollTabs from '../components/ScrollTabs.vue'
import { defineAsyncComponent } from 'vue'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

// V2.31.6: Samity moved into Society hub (was a standalone /samity page and a
// BMS tab). SamityView is self-contained (its own data + filters), so it is
// embedded as an async component — the same pattern BmsView already used.
const SamityView = defineAsyncComponent(() => import('./SamityView.vue'))

const TAB_ORDER = [
  ['parking', '🅿️', 'Parking'],
  ['bookings', '📅', 'Bookings'],
  ['voting', '🗳️', 'Voting'],
  ['forums', '💬', 'Forums'],
  ['events', '🎉', 'Events'],
  ['samity', '🏘️', 'Samity'],
]
const tab = ref('parking')
if (route.query.tab && TAB_ORDER.some(([k]) => k === route.query.tab)) tab.value = String(route.query.tab)
const goTab = (k) => { tab.value = k; router.replace({ query: { ...route.query, tab: k } }) }

const isStaff = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role))
const me = computed(() => auth.user?.name || '')

const toast = (m, ty) => { try { window.__krToast?.(m, ty) } catch (e) {} }

// ── generic loader ──
const rows = ref([])
const loading = ref(false)
const err = ref('')
async function load(mod) {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-community?mod=' + mod + '&action=list')
    if (r.ok) rows.value = r.rows || []
    else err.value = r.error || 'Failed to load.'
  } catch (e) { err.value = e.message || 'Network error.' }
  finally { loading.value = false }
}
async function post(mod, payload) {
  const r = await apiCall('app-community', { mod, ...payload })
  return r
}
function onTab(k) {
  tab.value = k
  router.replace({ query: { ...route.query, tab: k } })
  // Samity is self-contained (its own data loading + filters) — don't double-load.
  if (k === 'samity') return
  load(k)
}

// ── Parking ──
const parkForm = ref({ show: false, spot: '', type: 'car', vehicle_no: '', name: '', phone: '', prop: '', note: '' })
const parkBusy = ref(false)
async function saveParking() {
  parkBusy.value = true
  try {
    const r = await post('parking', { action: 'create', ...parkForm.value })
    if (r.ok) { toast(t('Saved'), 'ok'); parkForm.value.show = false; parkForm.value.spot = parkForm.value.vehicle_no = parkForm.value.name = parkForm.value.phone = parkForm.value.prop = parkForm.value.note = ''; await load('parking') }
    else toast(r.error || t('Failed'), 'error')
  } finally { parkBusy.value = false }
}
async function releaseParking(id) {
  const r = await post('parking', { action: 'update', id, status: 'Released' })
  if (r.ok) { toast(t('Updated'), 'ok'); await load('parking') } else toast(r.error || t('Failed'), 'error')
}
const parkTypeIco = (x) => x === 'bike' ? '🛵' : x === 'car' ? '🚗' : '🚙'

// ── Bookings ──
const bkgForm = ref({ show: false, facility: '', date: '', slot: '', name: '', phone: '', note: '', status: 'Pending' })
const bkgBusy = ref(false)
const FACILITIES = ['Community Hall', 'Rooftop', 'Gym', 'Party Room', 'Guest Room', 'Lawn']
async function saveBooking() {
  bkgBusy.value = true
  try {
    const r = await post('bookings', { action: 'create', ...bkgForm.value })
    if (r.ok) { toast(t('Saved'), 'ok'); bkgForm.value.show = false; bkgForm.value.facility = bkgForm.value.date = bkgForm.value.slot = bkgForm.value.name = bkgForm.value.phone = bkgForm.value.note = ''; bkgForm.value.status = 'Pending'; await load('bookings') }
    else toast(r.error || t('Failed'), 'error')
  } finally { bkgBusy.value = false }
}
async function bkgStatus(id, status) {
  const r = await post('bookings', { action: 'update', id, status })
  if (r.ok) { toast(t('Updated'), 'ok'); await load('bookings') } else toast(r.error || t('Failed'), 'error')
}

// ── Voting ──
const pollForm = ref({ show: false, question: '', options: ['', ''] })
const pollBusy = ref(false)
async function savePoll() {
  pollBusy.value = true
  try {
    const opts = pollForm.value.options.map(o => o.trim()).filter(Boolean)
    const r = await post('voting', { action: 'create', question: pollForm.value.question, options: opts })
    if (r.ok) { toast(t('Poll created'), 'ok'); pollForm.value.show = false; pollForm.value.question = ''; pollForm.value.options = ['', '']; await load('voting') }
    else toast(r.error || t('Failed'), 'error')
  } finally { pollBusy.value = false }
}
async function castVote(id, opt) {
  const r = await post('voting', { action: 'vote', id, option: opt })
  if (r.ok) { toast(t('Vote recorded'), 'ok'); await load('voting') } else toast(r.error || t('Failed'), 'error')
}
async function togglePoll(id, open) {
  const r = await post('voting', { action: 'toggle', id, open: open ? 1 : 0 })
  if (r.ok) { await load('voting') } else toast(r.error || t('Failed'), 'error')
}
function addOpt() { pollForm.value.options.push('') }
function rmOpt(i) { if (pollForm.value.options.length > 2) pollForm.value.options.splice(i, 1) }

// ── Forums ──
const threadForm = ref({ show: false, title: '', body: '', cat: 'General' })
const threadBusy = ref(false)
const openThread = ref(null)
const openPosts = ref([])
const replyBody = ref('')
const replyBusy = ref(false)
const CATS = ['General', 'Announcements', 'Help & Advice', 'Buy & Sell', 'Lost & Found', 'Social']
async function saveThread() {
  threadBusy.value = true
  try {
    const r = await post('forums', { action: 'create', title: threadForm.value.title, body: threadForm.value.body, cat: threadForm.value.cat })
    if (r.ok) { toast(t('Thread created'), 'ok'); threadForm.value.show = false; threadForm.value.title = threadForm.value.body = ''; threadForm.value.cat = 'General'; await load('forums') }
    else toast(r.error || t('Failed'), 'error')
  } finally { threadBusy.value = false }
}
async function openThreadView(id) {
  try {
    const r = await apiCall('app-community?mod=forums&action=thread&id=' + encodeURIComponent(id))
    if (r.ok) { openThread.value = r.thread; openPosts.value = r.posts || [] }
    else toast(r.error || t('Failed'), 'error')
  } catch (e) { toast(e.message || 'Network error.', 'error') }
}
async function sendReply() {
  if (!replyBody.value.trim()) return
  replyBusy.value = true
  try {
    const r = await post('forums', { action: 'post', id: openThread.value.id, body: replyBody.value })
    if (r.ok) { replyBody.value = ''; await openThreadView(openThread.value.id); await load('forums') }
    else toast(r.error || t('Failed'), 'error')
  } finally { replyBusy.value = false }
}
async function pinThread(id, pin) {
  const r = await post('forums', { action: 'pin', id, pin: pin ? 1 : 0 })
  if (r.ok) { await load('forums') } else toast(r.error || t('Failed'), 'error')
}

// ── Events ──
const evtForm = ref({ show: false, title: '', desc: '', date: '', time: '', location: '', capacity: '' })
const evtBusy = ref(false)
async function saveEvent() {
  evtBusy.value = true
  try {
    const r = await post('events', { action: 'create', ...evtForm.value })
    if (r.ok) { toast(t('Event created'), 'ok'); evtForm.value.show = false; evtForm.value.title = evtForm.value.desc = evtForm.value.date = evtForm.value.time = evtForm.value.location = ''; evtForm.value.capacity = ''; await load('events') }
    else toast(r.error || t('Failed'), 'error')
  } finally { evtBusy.value = false }
}
async function rsvp(id) {
  const r = await post('events', { action: 'rsvp', id, name: me.value || 'Resident' })
  if (r.ok) { toast(t('RSVP confirmed'), 'ok'); await load('events') } else toast(r.error || t('Failed'), 'error')
}
// V2.31.8: un-RSVP (remove my attendance) + staff delete for community rows
async function unRsvp(id) {
  const r = await post('events', { action: 'rsvp-remove', id, name: me.value || 'Resident' })
  if (r.ok) { toast(t('RSVP cancelled'), 'ok'); await load('events') } else toast(r.error || t('Failed'), 'error')
}
async function delRow(mod, row) {
  if (!window.confirm('Delete this ' + mod.slice(0, -1) + ' (' + (row.title || row.spot || row.facility || row.id) + ')?')) return
  const r = await post(mod, { action: 'delete', id: row.id })
  if (r.ok) { toast(t('Deleted'), 'ok'); await load(mod); loadStats() } else toast(r.error || t('Failed'), 'error')
}
const evtDate = (d) => d ? String(d).slice(0, 10) : '—'
const evtTime = (x) => x || '—'

// ── V2.31.6: Society KPI strip — live counts per module (incl. Samity) ──
const stats = ref({ parking: 0, bookings: 0, voting: 0, forums: 0, events: 0, samity: 0 })
async function loadStats() {
  const out = { parking: 0, bookings: 0, voting: 0, forums: 0, events: 0, samity: 0 }
  const mods = ['parking', 'bookings', 'voting', 'forums', 'events']
  await Promise.all(mods.map(async (m) => {
    try {
      const r = await apiCall('app-community?mod=' + m + '&action=list')
      if (r.ok) out[m] = (r.rows || []).length
    } catch (e) {}
  }))
  try {
    const r = await apiCall('app-samity', { action: 'list' })
    if (r.ok) out.samity = (r.members || r.rows || []).length
  } catch (e) {}
  stats.value = out
}

onMounted(() => { load(tab.value); loadStats() })

const loadingRow = () => loading.value
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ lang === 'bn' ? '🏘️ সোসাইটি' : '🏘️ Society' }}</h1>
        <div class="sub">{{ lang === 'bn' ? 'পার্কিং, বুকিং, ভোট, ফোরাম, ইভেন্ট ও সমিতি' : 'Parking, bookings, voting, forums, events & samity' }}</div>
      </div>
      <div class="head-actions">
        <button class="btn-ghost" @click="load(tab); loadStats()" title="Refresh">🔄</button>
      </div>
    </div>

    <!-- V2.31.6: live KPI strip -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:14px 0">
      <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">🅿️ {{ t('Parking') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ stats.parking }}</div></div>
      <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">📅 {{ t('Bookings') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ stats.bookings }}</div></div>
      <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">🗳️ {{ t('Voting') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ stats.voting }}</div></div>
      <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">💬 {{ t('Forums') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ stats.forums }}</div></div>
      <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">🎉 {{ t('Events') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ stats.events }}</div></div>
      <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">🏘️ {{ t('Samity') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ stats.samity }}</div></div>
    </div>

    <div v-if="err" class="auth-err show" style="margin-bottom:12px">{{ err }}</div>

    <ScrollTabs class="kr-tabs">
      <button v-for="[k, ico, l] in TAB_ORDER" :key="k" :class="{ active: tab === k }" @click="onTab(k)">{{ ico }} {{ t(l) }}</button>
    </ScrollTabs>

    <!-- ══════════════ PARKING ══════════════ -->
    <template v-if="tab === 'parking'">
      <div style="display:flex;justify-content:flex-end;margin:14px 0">
        <button class="btn-primary" @click="parkForm.show = !parkForm.show">➕ {{ t('Register vehicle') }}</button>
      </div>
      <div v-if="parkForm.show" class="panel" style="margin-bottom:14px">
        <div class="panel-h"><div class="t"><span class="pi">🅿️</span>{{ t('Register vehicle') }}</div><button style="border:none;background:none;font-size:15px;cursor:pointer;color:var(--text-mute)" @click="parkForm.show = false">✕</button></div>
        <div class="panel-b">
          <div class="grid grid-2" style="gap:10px">
            <input v-model="parkForm.spot" :placeholder="t('Parking spot') + ' (e.g. A-01)'" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
            <select v-model="parkForm.type" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
              <option value="car">{{ t('Car') }}</option><option value="bike">{{ t('Bike') }}</option><option value="van">{{ t('Van') }}</option>
            </select>
            <input v-model="parkForm.vehicle_no" :placeholder="t('Vehicle number')" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
            <input v-model="parkForm.name" :placeholder="t('Owner name')" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
            <input v-model="parkForm.phone" :placeholder="t('Phone')" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
            <input v-model="parkForm.prop" :placeholder="t('Property / unit')" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
          </div>
          <button class="btn-primary" style="margin-top:12px" :disabled="parkBusy" @click="saveParking">{{ t('Save') }}</button>
        </div>
      </div>
      <div class="panel">
        <div class="panel-b" style="padding:0">
          <div class="tbl-wrap">
            <table class="tbl">
              <thead><tr><th>{{ t('Spot') }}</th><th>{{ t('Type') }}</th><th>{{ t('Vehicle') }}</th><th>{{ t('Owner') }}</th><th>{{ t('Phone') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
              <tbody>
                <tr v-for="r in rows" :key="r.id">
                  <td><b>{{ r.spot }}</b></td>
                  <td>{{ parkTypeIco(r.type) }} {{ r.type }}</td>
                  <td class="mono">{{ r.vehicle_no }}</td>
                  <td>{{ r.name || '—' }}</td>
                  <td>{{ r.phone || '—' }}</td>
                  <td><span class="badge" :class="badge(r.status)">{{ r.status }}</span></td>
                  <td style="text-align:right">
                    <button v-if="r.status === 'Active' && isStaff" class="btn-ghost" style="padding:4px 10px;font-size:11.5px" @click="releaseParking(r.id)">{{ t('Release') }}</button>
                    <button v-if="isStaff" class="btn-ghost" style="padding:4px 8px;font-size:11px;color:var(--danger)" title="Delete" @click="delRow('parking', r)">✕</button>
                  </td>
                </tr>
                <tr v-if="!loading && !rows.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No vehicles registered') }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>

    <!-- ══════════════ BOOKINGS ══════════════ -->
    <template v-if="tab === 'bookings'">
      <div style="display:flex;justify-content:flex-end;margin:14px 0">
        <button class="btn-primary" @click="bkgForm.show = !bkgForm.show">➕ {{ t('New booking') }}</button>
      </div>
      <div v-if="bkgForm.show" class="panel" style="margin-bottom:14px">
        <div class="panel-h"><div class="t"><span class="pi">📅</span>{{ t('New booking') }}</div><button style="border:none;background:none;font-size:15px;cursor:pointer;color:var(--text-mute)" @click="bkgForm.show = false">✕</button></div>
        <div class="panel-b">
          <div class="grid grid-2" style="gap:10px">
            <select v-model="bkgForm.facility" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
              <option value="" disabled>{{ t('Facility') }}</option>
              <option v-for="f in FACILITIES" :key="f" :value="f">{{ t(f) }}</option>
            </select>
            <input v-model="bkgForm.date" type="date" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
            <input v-model="bkgForm.slot" :placeholder="t('Time slot') + ' (e.g. 18:00–21:00)'" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
            <input v-model="bkgForm.name" :placeholder="t('Name')" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
            <input v-model="bkgForm.phone" :placeholder="t('Phone')" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
            <input v-model="bkgForm.note" :placeholder="t('Note')" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
          </div>
          <button class="btn-primary" style="margin-top:12px" :disabled="bkgBusy" @click="saveBooking">{{ t('Save') }}</button>
        </div>
      </div>
      <div class="panel">
        <div class="panel-b" style="padding:0">
          <div class="tbl-wrap">
            <table class="tbl">
              <thead><tr><th>{{ t('Facility') }}</th><th>{{ t('Date') }}</th><th>{{ t('Slot') }}</th><th>{{ t('Name') }}</th><th>{{ t('Status') }}</th><th v-if="isStaff"></th></tr></thead>
              <tbody>
                <tr v-for="r in rows" :key="r.id">
                  <td><b>{{ r.facility }}</b></td>
                  <td>{{ r.date }}</td>
                  <td>{{ r.slot || '—' }}</td>
                  <td>{{ r.name || '—' }}</td>
                  <td><span class="badge" :class="badge(r.status)">{{ r.status }}</span></td>
                  <td v-if="isStaff" style="text-align:right">
                    <template v-if="r.status === 'Pending'"><button class="btn-ghost" style="padding:3px 9px;font-size:11px" @click="bkgStatus(r.id, 'Confirmed')">✓</button></template>
                    <template v-if="r.status !== 'Cancelled'"><button class="btn-ghost" style="padding:3px 9px;font-size:11px" @click="bkgStatus(r.id, 'Cancelled')">✕</button></template>
                    <button class="btn-ghost" style="padding:3px 8px;font-size:11px;color:var(--danger)" title="Delete" @click="delRow('bookings', r)">🗑</button>
                  </td>
                </tr>
                <tr v-if="!loading && !rows.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No bookings yet') }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>

    <!-- ══════════════ VOTING ══════════════ -->
    <template v-if="tab === 'voting'">
      <div style="display:flex;justify-content:flex-end;margin:14px 0">
        <button class="btn-primary" @click="pollForm.show = !pollForm.show">➕ {{ t('New poll') }}</button>
      </div>
      <div v-if="pollForm.show" class="panel" style="margin-bottom:14px">
        <div class="panel-h"><div class="t"><span class="pi">🗳️</span>{{ t('New poll') }}</div><button style="border:none;background:none;font-size:15px;cursor:pointer;color:var(--text-mute)" @click="pollForm.show = false">✕</button></div>
        <div class="panel-b">
          <input v-model="pollForm.question" :placeholder="t('Poll question')" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px;margin-bottom:8px">
          <div v-for="(o, i) in pollForm.options" :key="i" style="display:flex;gap:8px;margin-bottom:6px">
            <input v-model="pollForm.options[i]" :placeholder="t('Option') + ' ' + (i + 1)" style="flex:1;padding:8px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
            <button v-if="pollForm.options.length > 2" style="border:none;background:none;color:var(--text-mute);cursor:pointer" @click="rmOpt(i)">✕</button>
          </div>
          <button class="btn-ghost" style="padding:5px 12px;font-size:12px" @click="addOpt">➕ {{ t('Add option') }}</button>
          <button class="btn-primary" style="margin-left:8px" :disabled="pollBusy" @click="savePoll">{{ t('Create poll') }}</button>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
        <div v-for="p in rows" :key="p.id" class="panel">
          <div class="panel-b">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px">
              <div style="font-weight:800;font-size:14px">{{ p.question }}</div>
              <span class="badge" :class="p.open ? 'b-green' : 'b-gray'">{{ p.open ? t('Open') : t('Closed') }}</span>
            </div>
            <div class="c-sub" style="font-size:11.5px;margin-bottom:10px">{{ t('Created by') }} {{ p.created_name || '—' }} · {{ fmtTs(p.ts) }}</div>
            <div v-for="(o, i) in p.options" :key="i" style="margin-bottom:7px">
              <button :disabled="p.my_vote !== null || !p.open" @click="castVote(p.id, i)"
                style="width:100%;display:flex;align-items:center;gap:8px;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);cursor:pointer;font-family:inherit;font-size:12.5px;color:var(--text);text-align:left">
                <span style="flex-shrink:0">{{ p.my_vote === i ? '✅' : '○' }}</span>
                <span style="flex:1">{{ o }}</span>
                <span v-if="p.total_votes" class="c-sub" style="font-size:11px">{{ p.tally[i] || 0 }} · {{ Math.round((p.tally[i] || 0) / p.total_votes * 100) }}%</span>
              </button>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px">
              <span class="c-sub" style="font-size:11.5px">{{ p.total_votes || 0 }} {{ t('votes') }}</span>
              <button v-if="isStaff" class="btn-ghost" style="padding:4px 10px;font-size:11px" @click="togglePoll(p.id, !p.open)">{{ p.open ? t('Close poll') : t('Reopen') }}</button>
            </div>
          </div>
        </div>
        <div v-if="!loading && !rows.length" class="panel" style="grid-column:1/-1;padding:30px;text-align:center;color:var(--text-mute)">{{ t('No polls yet') }}</div>
      </div>
    </template>

    <!-- ══════════════ FORUMS ══════════════ -->
    <template v-if="tab === 'forums'">
      <div style="display:flex;justify-content:flex-end;margin:14px 0">
        <button class="btn-primary" @click="threadForm.show = !threadForm.show">➕ {{ t('New thread') }}</button>
      </div>
      <div v-if="threadForm.show" class="panel" style="margin-bottom:14px">
        <div class="panel-h"><div class="t"><span class="pi">💬</span>{{ t('New thread') }}</div><button style="border:none;background:none;font-size:15px;cursor:pointer;color:var(--text-mute)" @click="threadForm.show = false">✕</button></div>
        <div class="panel-b">
          <input v-model="threadForm.title" :placeholder="t('Title')" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px;margin-bottom:8px">
          <select v-model="threadForm.cat" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px;margin-bottom:8px">
            <option v-for="c in CATS" :key="c" :value="c">{{ t(c) }}</option>
          </select>
          <textarea v-model="threadForm.body" rows="3" :placeholder="t('Description') + '…'" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px;resize:vertical;margin-bottom:8px"></textarea>
          <button class="btn-primary" :disabled="threadBusy" @click="saveThread">{{ t('Post thread') }}</button>
        </div>
      </div>

      <!-- thread view -->
      <div v-if="openThread" class="panel" style="margin-bottom:14px">
        <div class="panel-h">
          <div class="t"><span class="pi">💬</span>{{ openThread.title }} <span class="badge b-gray" style="margin-left:6px">{{ openThread.cat }}</span></div>
          <button style="border:none;background:none;font-size:15px;cursor:pointer;color:var(--text-mute)" @click="openThread = null">✕</button>
        </div>
        <div class="panel-b">
          <div class="c-sub" style="font-size:12px;margin-bottom:8px">{{ openThread.author_name || openThread.author || '—' }} · {{ fmtTs(openThread.ts) }}</div>
          <div style="font-size:13.5px;white-space:pre-wrap;line-height:1.6">{{ openThread.body || '—' }}</div>
          <div style="margin-top:14px;border-top:1px solid var(--border);padding-top:10px">
            <div v-for="p in openPosts" :key="p.id" style="padding:8px 0;border-bottom:1px dashed var(--border)">
              <div class="c-sub" style="font-size:11px">{{ p.author_name || p.author || '—' }} · {{ fmtTs(p.ts) }}</div>
              <div style="font-size:13px;white-space:pre-wrap;margin-top:2px">{{ p.body }}</div>
            </div>
            <div style="display:flex;gap:8px;margin-top:10px">
              <input v-model="replyBody" :placeholder="t('Reply…')" style="flex:1;padding:8px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
              <button class="btn-primary" :disabled="replyBusy" @click="sendReply">{{ t('Send') }}</button>
            </div>
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px">
        <div v-for="r in rows" :key="r.id" class="panel" style="cursor:pointer" @click="openThreadView(r.id)">
          <div class="panel-b">
            <div style="display:flex;justify-content:space-between;gap:8px;align-items:center">
              <div style="font-weight:800;font-size:13.5px;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                {{ r.pinned ? '📌 ' : '' }}{{ r.title }}
              </div>
              <span class="badge b-gray">{{ r.cat }}</span>
            </div>
            <div class="c-sub" style="font-size:11.5px;margin-top:4px">{{ r.author_name || r.author || '—' }} · {{ r.posts || 0 }} {{ t('replies') }} · {{ fmtTs(r.ts) }}</div>
          </div>
        </div>
        <div v-if="!loading && !rows.length" class="panel" style="grid-column:1/-1;padding:30px;text-align:center;color:var(--text-mute)">{{ t('No threads yet') }}</div>
      </div>
    </template>

    <!-- ══════════════ EVENTS ══════════════ -->
    <template v-if="tab === 'events'">
      <div style="display:flex;justify-content:flex-end;margin:14px 0">
        <button class="btn-primary" @click="evtForm.show = !evtForm.show">➕ {{ t('New event') }}</button>
      </div>
      <div v-if="evtForm.show" class="panel" style="margin-bottom:14px">
        <div class="panel-h"><div class="t"><span class="pi">🎉</span>{{ t('New event') }}</div><button style="border:none;background:none;font-size:15px;cursor:pointer;color:var(--text-mute)" @click="evtForm.show = false">✕</button></div>
        <div class="panel-b">
          <div class="grid grid-2" style="gap:10px">
            <input v-model="evtForm.title" :placeholder="t('Event title')" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
            <input v-model="evtForm.date" type="date" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
            <input v-model="evtForm.time" :placeholder="t('Time')" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
            <input v-model="evtForm.location" :placeholder="t('Location')" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
            <input v-model="evtForm.capacity" type="number" min="0" :placeholder="t('Capacity')" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px">
          </div>
          <textarea v-model="evtForm.desc" rows="2" :placeholder="t('Description') + '…'" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13px;resize:vertical;margin-top:10px"></textarea>
          <button class="btn-primary" style="margin-top:12px" :disabled="evtBusy" @click="saveEvent">{{ t('Create event') }}</button>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
        <div v-for="r in rows" :key="r.id" class="panel">
          <div class="panel-b">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
              <div style="font-weight:800;font-size:14px">{{ r.title }}</div>
              <div style="display:flex;align-items:center;gap:6px">
                <span class="badge" :class="r.full ? 'b-red' : 'b-blue'">{{ r.rsvps || 0 }}/{{ r.capacity || '∞' }} {{ t('going') }}</span>
                <button v-if="isStaff" class="btn-ghost" style="padding:3px 8px;font-size:11px;color:var(--danger)" title="Delete" @click="delRow('events', r)">🗑</button>
              </div>
            </div>
            <div class="c-sub" style="font-size:12px;margin:6px 0 10px">
              📅 {{ evtDate(r.date) }} <span v-if="evtTime(r.time) !== '—'">· 🕐 {{ evtTime(r.time) }}</span>
              <span v-if="r.location"> · 📍 {{ r.location }}</span>
              <span v-if="r.capacity"> · 👥 {{ r.capacity }}</span>
            </div>
            <div v-if="r.desc" style="font-size:13px;color:var(--text-mute);line-height:1.55;margin-bottom:10px">{{ r.desc }}</div>
            <div style="display:flex;justify-content:space-between;align-items:center">
              <span class="c-sub" style="font-size:11px">{{ r.created_name || '' }}</span>
              <div style="display:flex;gap:8px">
                <button v-if="r.my_rsvp" class="btn-ghost" style="padding:7px 14px;font-size:12.5px;color:var(--ok)" @click="unRsvp(r.id)">✅ {{ t('Going') }}</button>
                <button v-else class="btn-primary" style="padding:7px 14px;font-size:12.5px" :disabled="r.full" @click="rsvp(r.id)">{{ r.full ? '🈵 ' + t('Full') : '✅ ' + t('RSVP') }}</button>
              </div>
            </div>
          </div>
        </div>
        <div v-if="!loading && !rows.length" class="panel" style="grid-column:1/-1;padding:30px;text-align:center;color:var(--text-mute)">{{ t('No events yet') }}</div>
      </div>
    </template>

    <!-- ══════════════ SAMITY (V2.31.6 — moved from standalone /samity + BMS tab) ══════════════ -->
    <template v-if="tab === 'samity'">
      <SamityView embedded />
    </template>
  </div>
</template>
