<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { lang, t } from '../lib/i18n'
import { apiCall } from '../api/client'
import { track } from '../lib/analytics'
import { badge, useViewMode, usePager } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('support')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const storeSupport = computed(() => data.list('support'))

// Local overlay: rows created/updated in this session that the bootstrap snapshot
// has not caught up with yet (keyed by id).
const overlay = ref({})
const supportAll = computed(() => {
  const out = storeSupport.value.map(t => overlay.value[t.id] || t)
  const extra = Object.values(overlay.value).filter(o => !storeSupport.value.some(t => t.id === o.id))
  return [...out, ...extra]
})

const PRIO_CLS = { High: 'b-red', Medium: 'b-orange', Low: 'b-gray', Urgent: 'b-red', default: 'b-gray' }
const prioCls = (p) => PRIO_CLS[p] || PRIO_CLS.default
const STATUS_FLOW = ['Open', 'In Progress', 'Resolved', 'Closed']
const CATEGORIES = ['General', 'Billing', 'Technical', 'Feature request', 'Account', 'Other']
const CAT_ICO = { General: '💬', Billing: '💰', Technical: '🔧', 'Feature request': '✨', Account: '👤', Other: '📦' }
const catIco = (c) => CAT_ICO[c] || '💬'

// ── KPIs ──
const kpis = computed(() => {
  const ss = supportAll.value
  const open = ss.filter(t => t.status === 'Open').length
  const prog = ss.filter(t => t.status === 'In Progress').length
  const res = ss.filter(t => t.status === 'Resolved' || t.status === 'Closed').length
  const high = ss.filter(t => t.prio === 'High' || t.prio === 'Urgent').length
  const senders = new Set(ss.map(t => (t.from_t || '').replace(/\s*\((Owner|Tenant|Partner)\)\s*$/, '')).filter(Boolean)).size
  return [
    { label: 'Tickets', ico: '🎧', value: ss.length, trend: lang.value === 'bn' ? 'সাপোর্ট রিকোয়েস্ট' : 'support requests', bn: 'টিকেট' },
    { label: 'Open', ico: '🟥', value: open, trend: lang.value === 'bn' ? 'মনোযোগ দরকার' : 'need attention', ok: open <= 2, bn: 'খোলা' },
    { label: 'In progress', ico: '🔵', value: prog, trend: lang.value === 'bn' ? 'চলছে' : 'being worked', bn: 'চলমান' },
    { label: 'Resolved', ico: '✅', value: res, trend: lang.value === 'bn' ? 'সমাধানকৃত' : 'closed', bn: 'সমাধানকৃত' },
    { label: 'High prio', ico: '🚨', value: high, trend: lang.value === 'bn' ? 'এগুলো আগে সমাধান করুন' : 'escalate these first', ok: high === 0, bn: 'উচ্চ অগ্রাধিকার' },
    { label: 'Senders', ico: '👥', value: senders, trend: lang.value === 'bn' ? 'স্বতন্ত্র ব্যবহারকারী' : 'distinct users', bn: 'ব্যবহারকারী' },
  ]
})

// ── filters ──
const query = ref('')
const statusFilter = ref('')
const prioFilter = ref('')
const catFilter = ref('')
const statusOptions = computed(() => [...new Set(supportAll.value.map(t => t.status).filter(Boolean))].sort())
const prioOptions = computed(() => [...new Set(supportAll.value.map(t => t.prio).filter(Boolean))].sort())
const catOptions = computed(() => [...new Set(supportAll.value.map(t => t.cat || 'General').filter(Boolean))].sort())
const filtered = computed(() => {
  let out = supportAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(t => JSON.stringify(t).toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(t => (t.status || '') === statusFilter.value)
  if (prioFilter.value) out = out.filter(t => (t.prio || '') === prioFilter.value)
  if (catFilter.value) out = out.filter(t => (t.cat || 'General') === catFilter.value)
  const rank = (t) => ({ Open: 0, 'In Progress': 1, Resolved: 2, Closed: 3 }[t.status] ?? 4)
  return [...out].sort((a, b) => rank(a) - rank(b))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[\",\n]/.test(s) ? '\"' + s.replace(/\"/g, '\"\"') + '\"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'support.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── compose ticket ──
const showCompose = ref(false)
const busy = ref(false)
const form = ref({ subject: '', cat: 'General', prio: 'Medium', body: '' })
const composeErr = ref('')
async function submitTicket() {
  composeErr.value = ''
  if (!form.value.subject.trim()) { composeErr.value = 'Subject is required.'; return }
  busy.value = true
  try {
    const r = await apiCall('app-support-ticket', { action: 'create', subject: form.value.subject, cat: form.value.cat, prio: form.value.prio, body: form.value.body })
    if (!r.ok) { composeErr.value = r.error || 'Failed to create ticket.'; return }
    track('support_ticket_created', { cat: form.value.cat, prio: form.value.prio })
    window.__krToast?.('✅ Ticket ' + r.id + ' opened')
    // put into overlay so it appears instantly, then open it
    const t = { id: r.id, from_t: (data.user?.name || 'You') + ' (' + ((data.user?.role || 'owner').charAt(0).toUpperCase() + (data.user?.role || 'owner').slice(1)) + ')', subject: form.value.subject, status: 'Open', prio: form.value.prio, cat: form.value.cat, age: 'just now', created_at: new Date().toISOString().replace('T', ' ').slice(0, 19) }
    overlay.value = { ...overlay.value, [t.id]: t }
    showCompose.value = false
    form.value = { subject: '', cat: 'General', prio: 'Medium', body: '' }
    openDetail(t)
  } finally { busy.value = false }
}

// ── drawer ──
const sel = ref(null)
const thread = ref([])
const reply = ref('')
const threadBusy = ref(false)
async function loadThread(id) {
  threadBusy.value = true
  try {
    const r = await apiCall('app-support-ticket', { action: 'thread', id })
    if (r.ok) { thread.value = r.thread || []; return }
  } catch (e) { /* fall through to store */ }
  // offline fallback: bootstrap snapshot
  thread.value = data.list('ticket_thread').filter(x => x.ticket === id)
}
function openDetail(t) {
  sel.value = t
  reply.value = ''
  thread.value = []
  loadThread(t.id)
}
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const t = supportAll.value.find(x => x.id === id); if (t) openDetail(t) }
}, { immediate: true })

const timeAgo = (ts) => {
  if (!ts) return ''
  const d = new Date(String(ts).replace(' ', 'T'))
  if (isNaN(d)) return String(ts).slice(0, 10)
  const s = (Date.now() - d.getTime()) / 1000
  if (s < 60) return 'just now'
  if (s < 3600) return Math.max(1, Math.floor(s / 60)) + 'm ago'
  if (s < 86400) return Math.floor(s / 3600) + 'h ago'
  if (s < 604800) return Math.floor(s / 86400) + 'd ago'
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

async function sendReply() {
  const text = reply.value.trim()
  if (!text || !sel.value || threadBusy.value) return
  threadBusy.value = true
  try {
    const r = await apiCall('app-support-ticket', { action: 'comment', id: sel.value.id, body: text })
    if (!r.ok) { window.__krToast?.('❌ ' + (r.error || 'Reply failed')); return }
    thread.value.push({ author: data.user?.name || 'You', body: text, ts: new Date().toISOString().replace('T', ' ').slice(0, 19) })
    reply.value = ''
    patchOverlay(sel.value.id, { updated_at: new Date().toISOString().replace('T', ' ').slice(0, 19) })
  } finally { threadBusy.value = false }
}
async function setStatus(s) {
  if (!sel.value || threadBusy.value) return
  threadBusy.value = true
  try {
    const r = await apiCall('app-support-ticket', { action: 'status', id: sel.value.id, status: s })
    if (!r.ok) { window.__krToast?.('❌ ' + (r.error || 'Update failed')); return }
    patchOverlay(sel.value.id, { status: s })
    window.__krToast?.('Status → ' + s)
  } finally { threadBusy.value = false }
}
async function setPrio(p) {
  if (!sel.value || threadBusy.value) return
  threadBusy.value = true
  try {
    const r = await apiCall('app-support-ticket', { action: 'prio', id: sel.value.id, prio: p })
    if (!r.ok) { window.__krToast?.('❌ ' + (r.error || 'Update failed')); return }
    patchOverlay(sel.value.id, { prio: p })
    window.__krToast?.('Priority → ' + p)
  } finally { threadBusy.value = false }
}
function patchOverlay(id, patchObj) {
  const cur = overlay.value[id] || storeSupport.value.find(t => t.id === id)
  if (cur) overlay.value = { ...overlay.value, [id]: { ...cur, ...patchObj } }
  if (sel.value?.id === id) sel.value = { ...sel.value, ...patchObj }
}
function detailFields(row) {
  const skip = new Set(['id', 'subject', 'from_t', 'cat'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🎧 {{ lang === 'bn' ? 'সাপোর্ট' : 'Support' }}</h1>
        <div class="sub">{{ supportAll.length }} tickets · {{ kpis[1]?.value || 0 }} open · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <button class="btn-ghost" style="padding:9px 14px;font-size:12.5px;font-weight:800;border-color:var(--primary);color:var(--primary)" @click="go('/wiki')">📚 Wiki</button>
        <input v-model="query" :placeholder="t('Search subject, sender…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All statuses') }}</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="prioFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All priorities') }}</option>
          <option v-for="p in prioOptions" :key="p" :value="p">{{ p }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" :title="t('Download CSV')">⬇ CSV</button>
        <button @click="showCompose = true" style="padding:9px 16px;font-size:12.5px;font-weight:800;border:none;border-radius:10px;background:var(--primary);color:#fff;cursor:pointer">➕ {{ lang === 'bn' ? 'নতুন টিকেট' : 'New ticket' }}</button>
      </CompactFilters>
      </div>
    </div>

    <!-- category pills -->
    <div v-if="catOptions.length > 1" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
      <button @click="catFilter = ''" class="badge b-gray"
        :style="catFilter === '' ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : ''"
        style="padding:7px 13px;font-size:12.5px;font-weight:800;cursor:pointer">🗂 All</button>
      <button v-for="c in catOptions" :key="c" @click="catFilter = (catFilter === c ? '' : c)" class="badge b-gray"
        :style="catFilter === c ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : ''"
        style="padding:7px 13px;font-size:12.5px;font-weight:800;cursor:pointer">
        {{ catIco(c) }} {{ c }} <span style="font-size:11px;opacity:.75">{{ supportAll.filter(t => (t.cat || 'General') === c).length }}</span>
      </button>
    </div>

    <div class="stats">
      <div v-for="k in kpis" :key="k.label" class="stat">
        <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ lang === 'bn' ? (k.bn || k.label) : k.label }}</div>
        <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
        <div class="s-trend">{{ k.trend }}</div>
      </div>
    </div>

    <!-- GRID -->
    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px">
      <div v-for="t in paged" :key="t.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(t)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">🎧</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="prioCls(t.prio)" style="background:#ffffff">{{ t.prio || '—' }}</span>
            <span class="badge" style="background:#ffffff">{{ catIco(t.cat) }} {{ t.cat || 'General' }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ t.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="font-weight:800;font-size:14px;letter-spacing:-.2px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ t.subject }}</div>
          <div class="c-sub" style="font-size:12px">{{ t.from_t || '—' }}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
            <span class="badge" :class="badge(t.status)">{{ t.status || '—' }}</span>
            <span v-if="t.age" class="badge b-gray">🕒 {{ t.age }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>{{ t('ID') }}</th><th>{{ t('Subject') }}</th><th>{{ t('From') }}</th><th>{{ t('Category') }}</th><th>{{ t('Status') }}</th><th>{{ t('Prio') }}</th><th>{{ t('Age') }}</th></tr></thead>
          <tbody>
            <tr v-for="t in paged" :key="t.id" style="cursor:pointer" @click="openDetail(t)">
              <td style="font-weight:700;white-space:nowrap">{{ t.id }}</td>
              <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ t.subject }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ t.from_t || '—' }}</td>
              <td style="white-space:nowrap"><span class="badge b-gray">{{ catIco(t.cat) }} {{ t.cat || 'General' }}</span></td>
              <td style="white-space:nowrap"><span class="badge" :class="badge(t.status)">{{ t.status || '—' }}</span></td>
              <td style="white-space:nowrap"><span class="badge" :class="prioCls(t.prio)">{{ t.prio || '—' }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ t.age || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No tickets found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- compose modal -->
    <template v-if="showCompose">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="showCompose = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(520px,94vw);background:var(--card);border-radius:18px;z-index:61;box-shadow:0 24px 80px rgba(0,0,0,.35);overflow:hidden">
        <div class="d-cover" style="height:90px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:36px">🎧</div>
          <button @click="showCompose = false" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:10px;color:#fff;font-weight:800;font-size:16px;text-shadow:0 1px 3px rgba(0,0,0,.4)">{{ t('Open a support ticket') }}</div>
        </div>
        <div style="padding:18px 20px 20px">
          <div style="font-size:12px;font-weight:800;color:var(--text-mute);margin-bottom:6px">SUBJECT *</div>
          <input v-model="form.subject" :placeholder="t('What do you need help with?')" style="width:100%;padding:11px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-size:14px;outline:none;margin-bottom:14px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
            <div>
              <div style="font-size:12px;font-weight:800;color:var(--text-mute);margin-bottom:6px">{{ t('CATEGORY') }}</div>
              <select v-model="form.cat" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-size:13.5px;outline:none">
                <option v-for="c in CATEGORIES" :key="c" :value="c">{{ catIco(c) }} {{ c }}</option>
              </select>
            </div>
            <div>
              <div style="font-size:12px;font-weight:800;color:var(--text-mute);margin-bottom:6px">{{ t('PRIORITY') }}</div>
              <select v-model="form.prio" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-size:13.5px;outline:none">
                <option value="Low">🟢 Low</option>
                <option value="Medium">🟠 Medium</option>
                <option value="High">🔴 High</option>
                <option value="Urgent">🚨 Urgent</option>
              </select>
            </div>
          </div>
          <div style="font-size:12px;font-weight:800;color:var(--text-mute);margin-bottom:6px">{{ t('DETAILS') }}</div>
          <textarea v-model="form.body" rows="4" placeholder="Describe the issue — what happened, when, and any error you saw…" style="width:100%;padding:11px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-size:13.5px;outline:none;resize:vertical;font-family:inherit"></textarea>
          <div v-if="composeErr" style="color:var(--danger);font-size:12.5px;font-weight:700;margin-top:8px">{{ composeErr }}</div>
          <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
            <button class="btn-ghost" @click="showCompose = false" :disabled="busy" style="padding:10px 16px;font-size:13px">{{ t('Cancel') }}</button>
            <button @click="submitTicket" :disabled="busy" style="padding:10px 20px;font-size:13px;font-weight:800;border:none;border-radius:10px;background:var(--primary);color:#fff;cursor:pointer">{{ busy ? 'Opening…' : 'Open ticket' }}</button>
          </div>
        </div>
      </div>
    </template>

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(560px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">🎧</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ catIco(sel.cat) }} {{ sel.cat || 'General' }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.prio || '—' }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:19px;font-weight:800;letter-spacing:-.3px;line-height:1.4">{{ sel.subject }}</h2>
          <div class="c-sub" style="margin-top:5px;font-size:12.5px">👤 {{ sel.from_t || '—' }} <template v-if="sel.age">· 🕒 {{ sel.age }}</template></div>

          <!-- workflow -->
          <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:14px 0">
            <button v-for="s in STATUS_FLOW" :key="s" @click="setStatus(s)" :disabled="threadBusy"
              :style="sel.status === s ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : ''"
              class="badge b-gray" style="padding:7px 12px;font-size:12px;font-weight:800;cursor:pointer;border:1px solid var(--border)">
              {{ sel.status === s ? '✓ ' : '' }}{{ s }}
            </button>
            <span style="flex:1"></span>
            <select :value="sel.prio" @change="setPrio($event.target.value)" :disabled="threadBusy" style="padding:7px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none">
              <option value="Low">🟢 Low</option>
              <option value="Medium">🟠 Medium</option>
              <option value="High">🔴 High</option>
              <option value="Urgent">🚨 Urgent</option>
            </select>
          </div>

          <!-- thread -->
          <div style="font-size:12px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.4px;margin:4px 0 10px">{{ t('Conversation') }}</div>
          <div v-if="!thread.length" class="c-sub" style="font-size:12.5px;padding:8px 0 14px">{{ t('No replies yet — start the conversation below.') }}</div>
          <div v-for="m in thread" :key="m.id || (m.author + m.ts)" style="margin-bottom:12px">
            <div style="display:flex;gap:10px">
              <div style="width:30px;height:30px;border-radius:50%;background:var(--bg-alt);display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0">{{ (m.author || '?').charAt(0).toUpperCase() }}</div>
              <div style="flex:1;min-width:0">
                <div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap">
                  <span style="font-weight:800;font-size:12.5px">{{ m.author }}</span>
                  <span class="c-sub" style="font-size:11px">{{ timeAgo(m.ts) }}</span>
                </div>
                <div style="background:var(--bg-alt);border-radius:10px;padding:10px 13px;font-size:13px;line-height:1.55;white-space:pre-wrap;margin-top:4px">{{ m.body }}</div>
              </div>
            </div>
          </div>

          <!-- reply -->
          <div style="display:flex;gap:8px;margin:14px 0 8px">
            <input v-model="reply" @keyup.enter="sendReply" placeholder="Write a reply…" :disabled="threadBusy"
              style="flex:1;padding:10px 13px;border-radius:10px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-size:13.5px;outline:none">
            <button @click="sendReply" :disabled="threadBusy || !reply.trim()" style="padding:10px 16px;font-size:13px;font-weight:800;border:none;border-radius:10px;background:var(--primary);color:#fff;cursor:pointer">{{ t('Send') }}</button>
          </div>

          <div v-for="[k, v] in detailFields(sel)" :key="k" style="font-size:13px;margin-bottom:8px">
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ k.replace(/_/g, ' ') }}</div>
            <div style="font-weight:600;word-break:break-word;margin-top:1px">{{ String(v) }}</div>
          </div>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.d-cover .badge { background: #ffffff; }
</style>
