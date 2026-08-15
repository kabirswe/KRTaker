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
  ['analytics', '📊', 'Analytics'],
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
  // Analytics pulls every module's rows — separate loader.
  if (k === 'analytics') { loadAnalytics(); return }
  load(k)
}

// ── Parking ──
const parkForm = ref({ show: false, spot: '', type: 'car', vehicle_no: '', name: '', phone: '', prop: '', note: '' })
const parkBusy = ref(false)
const parkFilter = ref('All')
const parkQ = ref('')
const parkFiltered = computed(() => {
  let out = rows.value.filter(r => parkFilter.value === 'All' || r.status === parkFilter.value)
  const q = parkQ.value.trim().toLowerCase()
  if (q) out = out.filter(r => [r.spot, r.vehicle_no, r.name, r.phone, r.prop].some(v => String(v || '').toLowerCase().includes(q)))
  return out
})
const parkStats = computed(() => {
  const all = rows.value
  return { total: all.length, active: all.filter(r => r.status === 'Active').length, released: all.filter(r => r.status === 'Released').length }
})
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
const bkgFilter = ref('All')
const bkgQ = ref('')
const bkgFiltered = computed(() => {
  let out = rows.value.filter(r => bkgFilter.value === 'All' || r.status === bkgFilter.value)
  const q = bkgQ.value.trim().toLowerCase()
  if (q) out = out.filter(r => [r.facility, r.date, r.slot, r.name, r.phone].some(v => String(v || '').includes(q)))
  return out
})
const bkgStats = computed(() => {
  const all = rows.value
  return { total: all.length, pending: all.filter(r => r.status === 'Pending').length, confirmed: all.filter(r => r.status === 'Confirmed').length, cancelled: all.filter(r => r.status === 'Cancelled').length }
})
const FACILITIES = ['Community Hall', 'Rooftop', 'Gym', 'Party Room', 'Guest Room', 'Lawn']
const FAC_ICONS = { 'Community Hall': '🏛️', Rooftop: '🌇', Gym: '🏋️', 'Party Room': '🎉', 'Guest Room': '🛏️', Lawn: '🌳' }
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

// ── Voting (V2.32.1: stats + Open/Closed filter + search + result bars) ──
const voteFilter = ref('All')
const voteQ = ref('')
const voteFiltered = computed(() => {
  let out = rows.value.filter(r => voteFilter.value === 'All' || (voteFilter.value === 'Open' ? r.open : !r.open))
  const q = voteQ.value.trim().toLowerCase()
  if (q) out = out.filter(r => String(r.question || '').toLowerCase().includes(q))
  return out
})
const voteStats = computed(() => {
  const all = rows.value
  return { total: all.length, open: all.filter(r => r.open).length, closed: all.filter(r => !r.open).length, votes: all.reduce((s, r) => s + (r.total_votes || 0), 0) }
})
const pct = (p, i) => p.total_votes ? Math.round((p.tally[i] || 0) / p.total_votes * 100) : 0

// ── V2.32.3: Voting result donut charts ──
const PAL = ['#4f8ef7', '#f0a35a', '#34c07a', '#e0567a', '#a48cf0', '#2ea8c8', '#e8842e', '#7b5ee8']
// conic-gradient style string for a poll's vote distribution
function donutStyle(p) {
  const t = p.total_votes || 0
  if (!t) return 'background:#eef0f6'
  let acc = 0
  const segs = []
  ;(p.options || []).forEach((o, i) => {
    const v = ((p.tally[i] || 0) / t) * 100
    segs.push(PAL[i % PAL.length] + ' ' + acc.toFixed(1) + '% ' + (acc + v).toFixed(1) + '%')
    acc += v
  })
  return 'background:conic-gradient(' + segs.join(',') + ')'
}
// conic-gradient style for the parking occupancy donut (V2.32.4)
const parkDonutStyle = () => 'background:conic-gradient(var(--ok) 0% ' + anPark.value.pct + '%, var(--border) ' + anPark.value.pct + '% 100%)'
// index of the leading option (👑), -1 when nothing has votes
function winIdx(p) {
  let wi = -1, mx = -1
  ;(p.options || []).forEach((o, i) => {
    const v = p.tally[i] || 0
    if (v > mx) { mx = v; wi = i }
  })
  return wi
}
const fillStyle = (p, i) => {
  const c = PAL[i % PAL.length]
  return { width: pct(p, i) + '%', background: p.my_vote === i ? c + '55' : c + '33' }
}

// ── Forums (V2.32.1: category chips + search + stats) ──
const forumCat = ref('All')
const forumQ = ref('')
const forumFiltered = computed(() => {
  let out = rows.value.filter(r => forumCat.value === 'All' || r.cat === forumCat.value)
  const q = forumQ.value.trim().toLowerCase()
  if (q) out = out.filter(r => String(r.title || '').toLowerCase().includes(q))
  return out
})
const forumStats = computed(() => {
  const all = rows.value
  return { total: all.length, pinned: all.filter(r => r.pinned).length, posts: all.reduce((s, r) => s + (r.posts || 0), 0), cats: new Set(all.map(r => r.cat).filter(Boolean)).size }
})

// ── Events (V2.32.1: Upcoming/Past filter + search + stats) ──
const evtFilter = ref('All')
const evtQ = ref('')
const todayStr = () => new Date().toISOString().slice(0, 10)
const evtFiltered = computed(() => {
  let out = rows.value.filter(r => {
    if (evtFilter.value === 'All') return true
    const d = evtDate(r.date)
    return d === '—' ? true : (evtFilter.value === 'Upcoming' ? d >= todayStr() : d < todayStr())
  })
  const q = evtQ.value.trim().toLowerCase()
  if (q) out = out.filter(r => [r.title, r.location, r.desc].some(v => String(v || '').toLowerCase().includes(q)))
  return out
})
const evtStats = computed(() => {
  const all = rows.value
  const t = todayStr()
  return { total: all.length, upcoming: all.filter(r => { const d = evtDate(r.date); return d !== '—' && d >= t }).length, past: all.filter(r => { const d = evtDate(r.date); return d !== '—' && d < t }).length, rsvps: all.reduce((s, r) => s + (r.rsvps || 0), 0) }
})

// ── V2.32.3: Events calendar view ──
const evtView = ref('list')            // 'list' | 'calendar'
const calYM = ref({ y: new Date().getFullYear(), m: new Date().getMonth() })
const calSel = ref(null)               // selected day 'YYYY-MM-DD' (events shown below grid)
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const MONTHS_BN = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর']
const WDS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const WDS_BN = ['রবি', 'সোম', 'মঙ্গল', 'বুধ', 'বৃহঃ', 'শুক্র', 'শনি']
const calTitle = computed(() => (lang.value === 'bn' ? MONTHS_BN[calYM.value.m] : MONTHS[calYM.value.m]) + ' ' + calYM.value.y)
// date → events map (only rows passing the current filter + search)
const evtByDate = computed(() => {
  const m = {}
  evtFiltered.value.forEach(e => {
    const d = evtDate(e.date)
    if (d !== '—') (m[d] = m[d] || []).push(e)
  })
  return m
})
// 42-cell grid (6 weeks × 7 days, Sun-first)
const calGrid = computed(() => {
  const { y, m } = calYM.value
  const first = new Date(y, m, 1)
  const off = first.getDay()
  const cells = []
  for (let i = 0; i < 42; i++) {
    const d = new Date(y, m, 1 - off + i)
    const ds = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
    cells.push({ ds, day: d.getDate(), inMonth: d.getMonth() === m, events: evtByDate.value[ds] || [] })
  }
  return cells
})
const calPrev = () => { calYM.value = { y: calYM.value.m === 0 ? calYM.value.y - 1 : calYM.value.y, m: calYM.value.m === 0 ? 11 : calYM.value.m - 1 } }
const calNext = () => { calYM.value = { y: calYM.value.m === 11 ? calYM.value.y + 1 : calYM.value.y, m: calYM.value.m === 11 ? 0 : calYM.value.m + 1 } }
const calToday = () => { const n = new Date(); calYM.value = { y: n.getFullYear(), m: n.getMonth() }; calSel.value = todayStr() }

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

// ── V2.32.4: Analytics dashboard — full cross-module rows ──
const an = ref({ parking: [], bookings: [], voting: [], forums: [], events: [], samity: [] })
const anLoading = ref(false)
async function loadAnalytics() {
  anLoading.value = true
  try {
    const mods = ['parking', 'bookings', 'voting', 'forums', 'events']
    const results = await Promise.all(mods.map(async (m) => {
      try {
        const r = await apiCall('app-community?mod=' + m + '&action=list')
        return [m, r.ok ? (r.rows || []) : []]
      } catch (e) { return [m, []] }
    }))
    const out = { parking: [], bookings: [], voting: [], forums: [], events: [], samity: [] }
    results.forEach(([m, rows]) => { out[m] = rows })
    try {
      const r = await apiCall('app-samity', { action: 'list' })
      out.samity = (r.members || r.rows || [])
    } catch (e) {}
    an.value = out
  } finally { anLoading.value = false }
}
// per-module analytics computeds
const anPark = computed(() => {
  const all = an.value.parking
  const active = all.filter(r => r.status === 'Active').length
  const released = all.filter(r => r.status === 'Released').length
  const pct = all.length ? Math.round(active / all.length * 100) : 0
  return { total: all.length, active, released, pct }
})
const anBkg = computed(() => {
  const all = an.value.bookings
  const by = {}
  all.forEach(r => {
    const f = r.facility || 'Other'
    by[f] = by[f] || { facility: f, total: 0, pending: 0, confirmed: 0, cancelled: 0 }
    by[f].total++
    by[f][String(r.status || 'pending').toLowerCase()] = (by[f][String(r.status || 'pending').toLowerCase()] || 0) + 1
  })
  const pending = all.filter(r => String(r.status || '').toLowerCase() === 'pending').length
  const confirmed = all.filter(r => String(r.status || '').toLowerCase() === 'confirmed').length
  const cancelled = all.filter(r => String(r.status || '').toLowerCase() === 'cancelled').length
  return { total: all.length, pending, confirmed, cancelled, byFacility: Object.values(by).sort((a, b) => b.total - a.total) }
})
const anVote = computed(() => {
  const all = an.value.voting
  const totalVotes = all.reduce((s, r) => s + (r.total_votes || 0), 0)
  const top = [...all].sort((a, b) => (b.total_votes || 0) - (a.total_votes || 0)).slice(0, 5)
  return { total: all.length, votes: totalVotes, open: all.filter(r => r.open).length, top }
})
const anForum = computed(() => {
  const all = an.value.forums
  const cats = {}
  all.forEach(r => {
    const c = r.cat || 'General'
    cats[c] = (cats[c] || 0) + 1
  })
  const pinned = all.filter(r => r.pinned).length
  const posts = all.reduce((s, r) => s + (r.posts || 0), 0)
  return { total: all.length, pinned, posts, cats: Object.entries(cats).sort((a, b) => b[1] - a[1]) }
})
const anEvt = computed(() => {
  const all = an.value.events
  const t = todayStr()
  const upcoming = all.filter(r => { const d = evtDate(r.date); return d !== '—' && d >= t })
  const past = all.filter(r => { const d = evtDate(r.date); return d !== '—' && d < t })
  const rsvps = all.reduce((s, r) => s + (r.rsvps || 0), 0)
  return { total: all.length, upcoming: upcoming.length, past: past.length, rsvps, next: upcoming.sort((a, b) => evtDate(a.date).localeCompare(evtDate(b.date))).slice(0, 4) }
})
const anSamity = computed(() => {
  const all = an.value.samity
  const roles = {}
  all.forEach(r => {
    const role = r.role || 'Member'
    roles[role] = (roles[role] || 0) + 1
  })
  const office = ['Chairman', 'Vice Chairman', 'Secretary', 'Treasurer'].filter(x => roles[x]).map(x => ({ role: x, name: (all.find(r => r.role === x) || {}).name || '—' }))
  return { total: all.length, roles: Object.entries(roles).sort((a, b) => b[1] - a[1]), office }
})

onMounted(() => {
  if (tab.value === 'analytics') loadAnalytics()
  else if (tab.value !== 'samity') load(tab.value)
  loadStats()
})

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
        <button class="btn-ghost" @click="load(tab); loadStats()" :title="t('Refresh')">🔄</button>
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
      <!-- V2.32.0: stat cards -->
      <div class="stats" style="margin-bottom:14px">
        <div class="stat"><div class="s-label"><span class="s-ico">🅿️</span>{{ t('Parking') }}</div><div class="s-value">{{ parkStats.total }}</div><div class="s-trend">{{ t('Total spots') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🚗</span>{{ t('Active') }}</div><div class="s-value" style="color:var(--ok,#12a150)">{{ parkStats.active }}</div><div class="s-trend">{{ t('Occupied now') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🆓</span>{{ t('Released') }}</div><div class="s-value" style="color:var(--text-mute)">{{ parkStats.released }}</div><div class="s-trend">{{ t('Free spots') }}</div></div>
      </div>

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

      <!-- V2.32.0: filter chips + search -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px">
        <button v-for="f in ['All', 'Active', 'Released']" :key="f" class="chip" :class="{ on: parkFilter === f }" style="padding:6px 13px;border:1px solid var(--border);border-radius:20px;background:var(--card);font-size:12px;font-weight:700;cursor:pointer" @click="parkFilter = f">{{ f === 'All' ? t('All') : t(f) }}</button>
        <div style="flex:1;min-width:160px;max-width:260px;margin-left:auto;position:relative">
          <input v-model="parkQ" :placeholder="t('Search') + '…'" style="width:100%;padding:7px 12px 7px 30px;border:1px solid var(--border);border-radius:20px;background:var(--bg);color:var(--text);font-family:inherit;font-size:12.5px">
          <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:12px;opacity:.6">🔍</span>
        </div>
      </div>

      <div class="panel">
        <div class="panel-b" style="padding:0">
          <div class="tbl-wrap">
            <table class="kr">
              <thead><tr><th>{{ t('Spot') }}</th><th>{{ t('Type') }}</th><th>{{ t('Vehicle') }}</th><th>{{ t('Owner') }}</th><th>{{ t('Phone') }}</th><th>{{ t('Status') }}</th><th style="text-align:right">{{ t('Actions') }}</th></tr></thead>
              <tbody>
                <tr v-for="r in parkFiltered" :key="r.id">
                  <td><b style="font-size:13px">{{ r.spot }}</b></td>
                  <td>{{ parkTypeIco(r.type) }} {{ r.type }}</td>
                  <td class="mono">{{ r.vehicle_no }}</td>
                  <td>{{ r.name || '—' }}</td>
                  <td>{{ r.phone || '—' }}</td>
                  <td><span class="badge" :class="badge(r.status)">{{ r.status }}</span></td>
                  <td style="text-align:right;white-space:nowrap">
                    <button v-if="r.status === 'Active' && isStaff" class="btn-ghost" style="padding:4px 10px;font-size:11.5px" @click="releaseParking(r.id)">🆓 {{ t('Release') }}</button>
                    <button v-if="isStaff" class="btn-ghost" style="padding:4px 8px;font-size:11px;color:var(--danger)" :title="t('Delete')" @click="delRow('parking', r)">✕</button>
                  </td>
                </tr>
                <tr v-if="!loading && !parkFiltered.length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:26px">{{ rows.length ? t('No match') : t('No vehicles registered') }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>

    <!-- ══════════════ BOOKINGS ══════════════ -->
    <template v-if="tab === 'bookings'">
      <!-- V2.32.0: stat cards -->
      <div class="stats" style="margin-bottom:14px">
        <div class="stat"><div class="s-label"><span class="s-ico">📅</span>{{ t('Bookings') }}</div><div class="s-value">{{ bkgStats.total }}</div><div class="s-trend">{{ t('Total') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">⏳</span>{{ t('Pending') }}</div><div class="s-value" style="color:#e67e22">{{ bkgStats.pending }}</div><div class="s-trend">{{ t('Awaiting approval') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">✅</span>{{ t('Confirmed') }}</div><div class="s-value" style="color:var(--ok,#12a150)">{{ bkgStats.confirmed }}</div><div class="s-trend">{{ t('Approved') }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">🚫</span>{{ t('Cancelled') }}</div><div class="s-value" style="color:var(--text-mute)">{{ bkgStats.cancelled }}</div><div class="s-trend">{{ t('Declined') }}</div></div>
      </div>

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

      <!-- V2.32.0: filter chips + search -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px">
        <button v-for="f in ['All', 'Pending', 'Confirmed', 'Cancelled']" :key="f" class="chip" :class="{ on: bkgFilter === f }" style="padding:6px 13px;border:1px solid var(--border);border-radius:20px;background:var(--card);font-size:12px;font-weight:700;cursor:pointer" @click="bkgFilter = f">{{ f === 'All' ? t('All') : t(f) }}</button>
        <div style="flex:1;min-width:160px;max-width:260px;margin-left:auto;position:relative">
          <input v-model="bkgQ" :placeholder="t('Search') + '…'" style="width:100%;padding:7px 12px 7px 30px;border:1px solid var(--border);border-radius:20px;background:var(--bg);color:var(--text);font-family:inherit;font-size:12.5px">
          <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:12px;opacity:.6">🔍</span>
        </div>
      </div>

      <div class="panel">
        <div class="panel-b" style="padding:0">
          <div class="tbl-wrap">
            <table class="kr">
              <thead><tr><th>{{ t('Facility') }}</th><th>{{ t('Date') }}</th><th>{{ t('Slot') }}</th><th>{{ t('Name') }}</th><th>{{ t('Status') }}</th><th v-if="isStaff" style="text-align:right">{{ t('Actions') }}</th></tr></thead>
              <tbody>
                <tr v-for="r in bkgFiltered" :key="r.id">
                  <td><b style="font-size:13px">{{ FAC_ICONS[r.facility] || '📅' }} {{ r.facility }}</b></td>
                  <td>{{ r.date }}</td>
                  <td>{{ r.slot || '—' }}</td>
                  <td>{{ r.name || '—' }}</td>
                  <td><span class="badge" :class="badge(r.status)">{{ r.status }}</span></td>
                  <td v-if="isStaff" style="text-align:right;white-space:nowrap">
                    <template v-if="r.status === 'Pending'"><button class="btn-ghost" style="padding:3px 9px;font-size:11px" @click="bkgStatus(r.id, 'Confirmed')" :title="t('Confirm')">✅</button></template>
                    <template v-if="r.status !== 'Cancelled'"><button class="btn-ghost" style="padding:3px 9px;font-size:11px" @click="bkgStatus(r.id, 'Cancelled')" :title="t('Cancel')">✕</button></template>
                    <button class="btn-ghost" style="padding:3px 8px;font-size:11px;color:var(--danger)" :title="t('Delete')" @click="delRow('bookings', r)">🗑</button>
                  </td>
                </tr>
                <tr v-if="!loading && !bkgFiltered.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:26px">{{ rows.length ? t('No match') : t('No bookings yet') }}</td></tr>
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
      <!-- V2.32.1: stats row -->
      <div class="stats" style="margin-bottom:14px">
        <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">{{ t('Total polls') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ voteStats.total }}</div></div>
        <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">{{ t('Open') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px;color:var(--ok)">{{ voteStats.open }}</div></div>
        <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">{{ t('Closed') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px;color:var(--text-mute)">{{ voteStats.closed }}</div></div>
        <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">🗳️ {{ t('Votes cast') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ voteStats.votes }}</div></div>
      </div>
      <!-- V2.32.1: filter chips + search -->
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
        <button v-for="f in ['All', 'Open', 'Closed']" :key="f" class="chip" :class="{ on: voteFilter === f }" style="padding:6px 13px;border:1px solid var(--border);border-radius:20px;background:var(--card);font-size:12px;font-weight:700;cursor:pointer" @click="voteFilter = f">{{ f === 'All' ? t('All') : t(f) }}</button>
        <input v-model="voteQ" :placeholder="t('Search') + '…'" style="flex:1;min-width:160px;max-width:280px;margin-left:auto;padding:7px 12px;border:1px solid var(--border);border-radius:20px;background:var(--bg);color:var(--text);font-family:inherit;font-size:12px">
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px">
        <div v-for="p in voteFiltered" :key="p.id" class="panel">
          <div class="panel-b">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:6px">
              <div style="font-weight:800;font-size:14px;min-width:0">{{ p.question }}</div>
              <span class="badge" :class="p.open ? 'b-green' : 'b-gray'" style="flex-shrink:0">{{ p.open ? t('Open') : t('Closed') }}</span>
            </div>
            <div class="c-sub" style="font-size:11.5px;margin-bottom:10px">{{ t('Created by') }} {{ p.created_name || '—' }} · {{ fmtTs(p.ts) }}</div>
            <!-- V2.32.3: donut chart + legend -->
            <div style="display:flex;align-items:center;gap:16px;margin-bottom:12px">
              <div style="position:relative;width:74px;height:74px;border-radius:50%;flex-shrink:0" :style="donutStyle(p)">
                <div style="position:absolute;inset:11px;border-radius:50%;background:var(--card);display:flex;flex-direction:column;align-items:center;justify-content:center">
                  <span style="font-weight:900;font-size:15px;line-height:1">{{ p.total_votes || 0 }}</span>
                  <span class="c-sub" style="font-size:8.5px;font-weight:700">{{ t('votes') }}</span>
                </div>
              </div>
              <div style="flex:1;display:flex;flex-direction:column;gap:5px;min-width:0">
                <div v-for="(o, i) in p.options" :key="i" style="display:flex;align-items:center;gap:7px;font-size:11.5px">
                  <span :style="{ background: PAL[i % PAL.length] }" style="width:9px;height:9px;border-radius:50%;flex-shrink:0"></span>
                  <span style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text-mute);font-weight:600">{{ o }}</span>
                  <span style="font-weight:800">{{ p.tally[i] || 0 }} <span class="c-sub" style="font-weight:600">({{ pct(p, i) }}%)</span></span>
                  <span v-if="winIdx(p) === i && p.total_votes" style="font-size:11px">👑</span>
                </div>
              </div>
            </div>
            <!-- V2.32.1 result bars → V2.32.3 per-option color -->
            <div v-for="(o, i) in p.options" :key="'b' + i" style="margin-bottom:7px">
              <button :disabled="p.my_vote !== null || !p.open" @click="castVote(p.id, i)"
                style="position:relative;width:100%;display:flex;align-items:center;gap:8px;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);cursor:pointer;font-family:inherit;font-size:12.5px;color:var(--text);text-align:left;overflow:hidden">
                <span v-if="p.total_votes" class="vote-fill" :style="fillStyle(p, i)"></span>
                <span style="position:relative;flex-shrink:0">{{ p.my_vote === i ? '✅' : '○' }}</span>
                <span style="position:relative;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ o }}</span>
                <span v-if="p.total_votes" class="c-sub" style="position:relative;font-size:11px">{{ p.tally[i] || 0 }} · {{ pct(p, i) }}%</span>
              </button>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px">
              <span class="c-sub" style="font-size:11.5px">{{ p.total_votes || 0 }} {{ t('votes') }}</span>
              <button v-if="isStaff" class="btn-ghost" style="padding:4px 10px;font-size:11px" @click="togglePoll(p.id, !p.open)">{{ p.open ? t('Close poll') : t('Reopen') }}</button>
            </div>
          </div>
        </div>
        <div v-if="!loading && !voteFiltered.length" class="panel" style="grid-column:1/-1;padding:30px;text-align:center;color:var(--text-mute)">{{ voteQ || voteFilter !== 'All' ? t('No match') : t('No polls yet') }}</div>
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

      <!-- V2.32.1: stats row -->
      <div class="stats" style="margin-bottom:14px">
        <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">💬 {{ t('Threads') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ forumStats.total }}</div></div>
        <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">📌 {{ t('Pinned') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ forumStats.pinned }}</div></div>
        <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">{{ t('replies') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ forumStats.posts }}</div></div>
        <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">{{ t('Categories') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ forumStats.cats }}</div></div>
      </div>
      <!-- V2.32.1: category chips + search -->
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
        <button v-for="c in ['All', ...CATS]" :key="c" class="chip" :class="{ on: forumCat === c }" style="padding:6px 13px;border:1px solid var(--border);border-radius:20px;background:var(--card);font-size:12px;font-weight:700;cursor:pointer" @click="forumCat = c">{{ c === 'All' ? t('All') : t(c) }}</button>
        <input v-model="forumQ" :placeholder="t('Search') + '…'" style="flex:1;min-width:160px;max-width:280px;margin-left:auto;padding:7px 12px;border:1px solid var(--border);border-radius:20px;background:var(--bg);color:var(--text);font-family:inherit;font-size:12px">
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px">
        <div v-for="r in forumFiltered" :key="r.id" class="panel" style="cursor:pointer" @click="openThreadView(r.id)">
          <div class="panel-b">
            <div style="display:flex;justify-content:space-between;gap:8px;align-items:center">
              <div style="font-weight:800;font-size:13.5px;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                {{ r.pinned ? '📌 ' : '' }}{{ r.title }}
              </div>
              <span class="badge" :class="r.pinned ? 'b-orange' : 'b-gray'">{{ r.cat }}</span>
            </div>
            <div class="c-sub" style="font-size:11.5px;margin-top:4px">{{ r.author_name || r.author || '—' }} · {{ r.posts || 0 }} {{ t('replies') }} · {{ fmtTs(r.ts) }}</div>
          </div>
        </div>
        <div v-if="!loading && !forumFiltered.length" class="panel" style="grid-column:1/-1;padding:30px;text-align:center;color:var(--text-mute)">{{ forumQ || forumCat !== 'All' ? t('No match') : t('No threads yet') }}</div>
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
      <!-- V2.32.1: stats row -->
      <div class="stats" style="margin-bottom:14px">
        <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">🎉 {{ t('Total events') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ evtStats.total }}</div></div>
        <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">{{ t('Upcoming events') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px;color:var(--primary)">{{ evtStats.upcoming }}</div></div>
        <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">{{ t('Past events') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px;color:var(--text-mute)">{{ evtStats.past }}</div></div>
        <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">✅ {{ t('Total RSVPs') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ evtStats.rsvps }}</div></div>
      </div>
      <!-- V2.32.3: view toggle (List / Calendar) -->
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
        <button v-for="v in ['list', 'calendar']" :key="v" class="chip" :class="{ on: evtView === v }" style="padding:6px 13px;border:1px solid var(--border);border-radius:20px;background:var(--card);font-size:12px;font-weight:700;cursor:pointer" @click="evtView = v">{{ v === 'list' ? '📋 ' + t('List view') : '📅 ' + t('Calendar') }}</button>
        <button v-for="f in ['All', 'Upcoming', 'Past']" :key="f" class="chip" :class="{ on: evtFilter === f }" style="padding:6px 13px;border:1px solid var(--border);border-radius:20px;background:var(--card);font-size:12px;font-weight:700;cursor:pointer" @click="evtFilter = f">{{ f === 'All' ? t('All') : t(f) }}</button>
        <input v-if="evtView === 'list'" v-model="evtQ" :placeholder="t('Search') + '…'" style="flex:1;min-width:160px;max-width:280px;margin-left:auto;padding:7px 12px;border:1px solid var(--border);border-radius:20px;background:var(--bg);color:var(--text);font-family:inherit;font-size:12px">
      </div>

      <!-- V2.32.3: calendar view -->
      <div v-if="evtView === 'calendar'" class="panel" style="margin-bottom:14px;padding:18px">
        <!-- month nav -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
          <button class="btn-ghost" style="padding:6px 12px;font-size:13px" @click="calPrev">◀</button>
          <div style="font-weight:900;font-size:16px;text-align:center;flex:1">{{ calTitle }}</div>
          <button class="btn-ghost" style="padding:6px 12px;font-size:13px" @click="calNext">▶</button>
          <button class="btn-ghost" style="padding:6px 12px;font-size:12px" @click="calToday">{{ t('Today') }}</button>
        </div>
        <!-- weekday header -->
        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-bottom:6px">
          <div v-for="(w, i) in WDS" :key="w" style="text-align:center;font-size:11px;font-weight:800;color:var(--text-mute);padding:6px 0">{{ lang === 'bn' ? WDS_BN[i] : w }}</div>
        </div>
        <!-- 6x7 grid -->
        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px">
          <div v-for="(c, i) in calGrid" :key="i"
            @click="calSel = c.ds"
            style="min-height:58px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);padding:6px 7px;cursor:pointer;box-sizing:border-box"
            :style="{
              background: calSel === c.ds ? 'rgba(47,128,237,.12)' : (c.inMonth ? 'var(--bg-alt)' : 'rgba(0,0,0,.02)'),
              borderColor: calSel === c.ds ? 'var(--primary)' : (c.ds === todayStr() ? 'var(--primary)' : 'var(--border)'),
              opacity: c.inMonth ? 1 : .45
            }">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
              <span style="font-size:11.5px;font-weight:800" :style="{ color: c.ds === todayStr() ? 'var(--primary)' : 'var(--text)' }">{{ c.day }}</span>
              <span v-if="c.events.length" style="font-size:9.5px;font-weight:900;color:#fff;background:var(--primary);border-radius:99px;padding:1px 6px">{{ c.events.length }}</span>
            </div>
            <div style="display:flex;flex-direction:column;gap:2px">
              <div v-for="(e, j) in c.events.slice(0, 2)" :key="e.id" style="font-size:8.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text-mute)">{{ e.title }}</div>
              <div v-if="c.events.length > 2" style="font-size:8.5px;font-weight:800;color:var(--text-mute)">+{{ c.events.length - 2 }} {{ t('more') }}</div>
            </div>
          </div>
        </div>
        <!-- selected-day events -->
        <div v-if="calSel" style="margin-top:16px;border-top:1px dashed var(--border);padding-top:12px">
          <div style="font-weight:800;font-size:13px;margin-bottom:8px">📅 {{ t('Events on') }} {{ calSel }}</div>
          <div v-if="!(evtByDate[calSel] || []).length" class="c-sub" style="font-size:12px">{{ t('No events this day') }}</div>
          <div v-for="e in (evtByDate[calSel] || [])" :key="e.id" style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px dashed var(--border)">
            <div style="width:32px;height:32px;border-radius:9px;background:var(--bg-alt);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0">🎉</div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:800;font-size:12.5px">{{ e.title }}</div>
              <div class="c-sub" style="font-size:10.5px">{{ evtTime(e.time) }} <span v-if="e.location">· {{ e.location }}</span> · {{ e.rsvps || 0 }}/{{ e.capacity || '∞' }} {{ t('going') }}</div>
            </div>
            <button v-if="e.my_rsvp" class="btn-ghost" style="padding:5px 11px;font-size:11px;color:var(--ok)" @click="unRsvp(e.id)">✅ {{ t('Going') }}</button>
            <button v-else class="btn-primary" style="padding:5px 11px;font-size:11px" :disabled="e.full" @click="rsvp(e.id)">{{ e.full ? '🈵' : '✅ ' + t('RSVP') }}</button>
          </div>
        </div>
      </div>

      <!-- list view grid -->
      <div v-if="evtView === 'list'" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
        <div v-for="r in evtFiltered" :key="r.id" class="panel">
          <div class="panel-b">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
              <div style="font-weight:800;font-size:14px;min-width:0">{{ r.title }}</div>
              <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
                <span class="badge" :class="evtDate(r.date) !== '—' && evtDate(r.date) < todayStr() ? 'b-gray' : 'b-blue'">{{ evtDate(r.date) !== '—' && evtDate(r.date) < todayStr() ? t('Past') : t('Upcoming') }}</span>
                <span class="badge" :class="r.full ? 'b-red' : 'b-blue'">{{ r.rsvps || 0 }}/{{ r.capacity || '∞' }} {{ t('going') }}</span>
                <button v-if="isStaff" class="btn-ghost" style="padding:3px 8px;font-size:11px;color:var(--danger)" :title="t('Delete')" @click="delRow('events', r)">🗑</button>
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
        <div v-if="!loading && !evtFiltered.length" class="panel" style="grid-column:1/-1;padding:30px;text-align:center;color:var(--text-mute)">{{ evtQ || evtFilter !== 'All' ? t('No match') : t('No events yet') }}</div>
      </div>
    </template>

    <!-- ══════════════ ANALYTICS (V2.32.4) ══════════════ -->
    <template v-if="tab === 'analytics'">
      <div v-if="anLoading" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">⏳ {{ t('Loading') }}…</div>
      <template v-else>
        <!-- top KPI strip -->
        <div class="stats" style="margin-bottom:14px">
          <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">🅿️ {{ t('Parking spots') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ anPark.total }}</div><div class="s-trend">{{ anPark.active }} {{ t('Active') }} · {{ anPark.released }} {{ t('Released') }}</div></div>
          <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">📅 {{ t('Bookings') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ anBkg.total }}</div><div class="s-trend">{{ anBkg.pending }} {{ t('Pending') }} · {{ anBkg.confirmed }} {{ t('Confirmed') }}</div></div>
          <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">🗳️ {{ t('Polls') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ anVote.total }}</div><div class="s-trend">{{ anVote.votes }} {{ t('votes') }} · {{ anVote.open }} {{ t('Open') }}</div></div>
          <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">💬 {{ t('Threads') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ anForum.total }}</div><div class="s-trend">{{ anForum.posts }} {{ t('replies') }} · {{ anForum.pinned }} {{ t('Pinned') }}</div></div>
          <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">🎉 {{ t('Events') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ anEvt.total }}</div><div class="s-trend">{{ anEvt.upcoming }} {{ t('Upcoming') }} · {{ anEvt.rsvps }} {{ t('going') }}</div></div>
          <div class="panel" style="padding:12px 14px"><div class="s-label" style="font-size:11px;color:var(--text-mute);font-weight:700;letter-spacing:.3px">🏘️ {{ t('Samity') }}</div><div style="font-size:20px;font-weight:800;margin-top:2px">{{ anSamity.total }}</div><div class="s-trend">{{ anSamity.office.length }} {{ t('office bearers') }}</div></div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">

          <!-- Parking occupancy donut -->
          <div class="panel">
            <div class="panel-b">
              <div style="font-weight:800;font-size:14px;margin-bottom:14px">🅿️ {{ t('Parking occupancy') }}</div>
              <div style="display:flex;align-items:center;gap:16px">
                <div :style="parkDonutStyle()" style="position:relative;width:96px;height:96px;border-radius:50%;flex-shrink:0">
                  <div style="position:absolute;inset:14px;border-radius:50%;background:var(--card);display:flex;flex-direction:column;align-items:center;justify-content:center">
                    <span style="font-weight:900;font-size:18px;line-height:1">{{ anPark.pct }}%</span>
                    <span class="c-sub" style="font-size:9px;font-weight:700">{{ t('Active') }}</span>
                  </div>
                </div>
                <div style="flex:1;display:flex;flex-direction:column;gap:8px">
                  <div style="display:flex;align-items:center;gap:8px;font-size:12px"><span style="width:10px;height:10px;border-radius:3px;background:var(--ok);flex-shrink:0"></span><span style="flex:1">{{ t('Active') }}</span><b>{{ anPark.active }}</b></div>
                  <div style="display:flex;align-items:center;gap:8px;font-size:12px"><span style="width:10px;height:10px;border-radius:3px;background:var(--border);flex-shrink:0"></span><span style="flex:1">{{ t('Released') }}</span><b>{{ anPark.released }}</b></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Bookings by facility -->
          <div class="panel">
            <div class="panel-b">
              <div style="font-weight:800;font-size:14px;margin-bottom:14px">📅 {{ t('Bookings by facility') }}</div>
              <div v-if="!anBkg.byFacility.length" class="c-sub" style="font-size:12px">{{ t('No bookings yet') }}</div>
              <div v-for="b in anBkg.byFacility" :key="b.facility" style="margin-bottom:10px">
                <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:700;margin-bottom:4px"><span>{{ b.facility }}</span><span>{{ b.total }}</span></div>
                <div style="height:8px;background:var(--bg-alt);border-radius:99px;overflow:hidden">
                  <div style="height:100%;border-radius:99px;background:var(--primary)" :style="{ width: Math.round(b.total / Math.max(anBkg.byFacility[0].total, 1) * 100) + '%' }"></div>
                </div>
                <div class="c-sub" style="font-size:10px;margin-top:2px">{{ b.pending }} {{ t('Pending') }} · {{ b.confirmed }} {{ t('Confirmed') }} · {{ b.cancelled }} {{ t('Cancelled') }}</div>
              </div>
            </div>
          </div>

          <!-- Top polls -->
          <div class="panel">
            <div class="panel-b">
              <div style="font-weight:800;font-size:14px;margin-bottom:14px">🗳️ {{ t('Top polls') }}</div>
              <div v-if="!anVote.top.length" class="c-sub" style="font-size:12px">{{ t('No polls yet') }}</div>
              <div v-for="(p, pi) in anVote.top" :key="p.id" style="margin-bottom:12px">
                <div style="display:flex;justify-content:space-between;gap:8px;font-size:12.5px;font-weight:800;margin-bottom:5px">
                  <span style="min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ p.question }}</span>
                  <span class="badge" :class="p.open ? 'b-green' : 'b-gray'" style="flex-shrink:0">{{ p.open ? t('Open') : t('Closed') }}</span>
                </div>
                <div v-for="(o, i) in (p.options || []).slice(0, 3)" :key="i" style="display:flex;align-items:center;gap:7px;font-size:11px;margin-top:3px">
                  <span :style="{ background: PAL[i % PAL.length] }" style="width:8px;height:8px;border-radius:50%;flex-shrink:0"></span>
                  <span style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text-mute)">{{ o }}</span>
                  <span style="font-weight:800">{{ p.tally[i] || 0 }} <span class="c-sub" style="font-weight:600">({{ pct(p, i) }}%)</span></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Forum categories -->
          <div class="panel">
            <div class="panel-b">
              <div style="font-weight:800;font-size:14px;margin-bottom:14px">💬 {{ t('Forum categories') }}</div>
              <div v-if="!anForum.cats.length" class="c-sub" style="font-size:12px">{{ t('No threads yet') }}</div>
              <div v-for="([c, n], i) in anForum.cats" :key="c" style="margin-bottom:10px">
                <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:700;margin-bottom:4px"><span>{{ c }}</span><span>{{ n }}</span></div>
                <div style="height:8px;background:var(--bg-alt);border-radius:99px;overflow:hidden">
                  <div style="height:100%;border-radius:99px" :style="{ width: Math.round(n / Math.max(anForum.cats[0][1], 1) * 100) + '%', background: PAL[i % PAL.length] }"></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Upcoming events -->
          <div class="panel">
            <div class="panel-b">
              <div style="font-weight:800;font-size:14px;margin-bottom:14px">🎉 {{ t('Upcoming events') }}</div>
              <div v-if="!anEvt.next.length" class="c-sub" style="font-size:12px">{{ t('No events yet') }}</div>
              <div v-for="e in anEvt.next" :key="e.id" style="display:flex;align-items:center;gap:11px;padding:7px 0;border-bottom:1px dashed var(--border)">
                <div style="width:34px;min-width:34px;text-align:center;background:var(--bg-alt);border-radius:9px;padding:5px 0">
                  <div style="font-size:14px;font-weight:900;line-height:1">{{ String(evtDate(e.date)).slice(8, 10) }}</div>
                  <div style="font-size:8.5px;font-weight:700;color:var(--text-mute);text-transform:uppercase">{{ MONTHS[Number(String(evtDate(e.date)).slice(5, 7)) - 1]?.slice(0, 3) }}</div>
                </div>
                <div style="flex:1;min-width:0">
                  <div style="font-size:12.5px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ e.title }}</div>
                  <div class="c-sub" style="font-size:10.5px">{{ evtTime(e.time) }} <span v-if="e.location">· {{ e.location }}</span></div>
                </div>
                <span class="c-sub" style="font-size:10.5px;flex-shrink:0">{{ e.rsvps || 0 }}/{{ e.capacity || '∞' }}</span>
              </div>
            </div>
          </div>

          <!-- Samity committee -->
          <div class="panel">
            <div class="panel-b">
              <div style="font-weight:800;font-size:14px;margin-bottom:14px">🏘️ {{ t('Samity committee') }}</div>
              <div v-if="anSamity.office.length">
                <div v-for="o in anSamity.office" :key="o.role" style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px dashed var(--border)">
                  <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#7b7bf0,#5a5ae6);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:11px;flex-shrink:0">{{ String(o.name || '?').slice(0, 1).toUpperCase() }}</div>
                  <div style="flex:1;min-width:0">
                    <div style="font-size:12.5px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ o.name }}</div>
                    <div class="c-sub" style="font-size:10.5px">{{ o.role }}</div>
                  </div>
                </div>
              </div>
              <div v-else class="c-sub" style="font-size:12px">{{ t('No samity members') }}</div>
            </div>
          </div>

        </div>
      </template>
    </template>

    <!-- ══════════════ SAMITY (V2.31.6 — moved from standalone /samity + BMS tab) ══════════════ -->
    <template v-if="tab === 'samity'">
      <SamityView embedded />
    </template>
  </div>
</template>

<style scoped>
/* V2.32.0: filter-chip active state + stat card polish (mirrors VendorsView) */
.chip.on { border-color: var(--primary); color: var(--primary); background: rgba(47,128,237,.08) }
/* V2.32.1 poll result bar → V2.32.3: fill color set inline via fillStyle() */
.vote-fill { position: absolute; left: 0; top: 0; bottom: 0; background: rgba(47,128,237,.13); border-radius: 8px; transition: width .3s }
.s-value { font-size: 24px; font-weight: 800; margin-top: 2px; line-height: 1.1 }
.s-trend { font-size: 11px; color: var(--text-mute); margin-top: 3px }
</style>
