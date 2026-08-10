<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { badge, useViewMode, usePager } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('support')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const supportAll = computed(() => data.list('support'))

const PRIO_CLS = { High: 'b-red', Medium: 'b-orange', Low: 'b-gray', Urgent: 'b-red', default: 'b-gray' }
const prioCls = (p) => PRIO_CLS[p] || PRIO_CLS.default

// ── KPIs ──
const kpis = computed(() => {
  const ss = supportAll.value
  const open = ss.filter(t => t.status === 'Open').length
  const prog = ss.filter(t => t.status === 'In Progress').length
  const res = ss.filter(t => t.status === 'Resolved').length
  const high = ss.filter(t => t.prio === 'High' || t.prio === 'Urgent').length
  const senders = new Set(ss.map(t => (t.from_t || '').replace(/\s*\((Owner|Tenant|Partner)\)\s*$/, '')).filter(Boolean)).size
  return [
    { label: 'Tickets', ico: '🎧', value: ss.length, trend: 'support requests' },
    { label: 'Open', ico: '🟥', value: open, trend: open ? 'need attention' : 'none open', ok: open <= 2 },
    { label: 'In progress', ico: '🔵', value: prog, trend: 'being worked' },
    { label: 'Resolved', ico: '✅', value: res, trend: res ? 'closed' : 'none yet' },
    { label: 'High prio', ico: '🚨', value: high, trend: high ? 'escalate these first' : 'no urgent items', ok: high === 0 },
    { label: 'Senders', ico: '👥', value: senders, trend: 'distinct users' },
  ]
})

// ── filters ──
const query = ref('')
const statusFilter = ref('')
const prioFilter = ref('')
const statusOptions = computed(() => [...new Set(supportAll.value.map(t => t.status).filter(Boolean))].sort())
const prioOptions = computed(() => [...new Set(supportAll.value.map(t => t.prio).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = supportAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(t => JSON.stringify(t).toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(t => (t.status || '') === statusFilter.value)
  if (prioFilter.value) out = out.filter(t => (t.prio || '') === prioFilter.value)
  const rank = (t) => ({ Open: 0, 'In Progress': 1, Resolved: 2 }[t.status] ?? 3)
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

// ── drawer ──
const sel = ref(null)
function openDetail(t) { sel.value = t }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const t = supportAll.value.find(x => x.id === id); if (t) openDetail(t) }
}, { immediate: true })
function detailFields(row) {
  const skip = new Set(['id', 'subject', 'from_t'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🎧 Support</h1>
        <div class="sub">{{ supportAll.length }} tickets · {{ kpis[1]?.value || 0 }} open · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search subject, sender…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="prioFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All priorities</option>
          <option v-for="p in prioOptions" :key="p" :value="p">{{ p }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
      </div>
    </div>

    <div class="stats">
      <div v-for="k in kpis" :key="k.label" class="stat">
        <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
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
          <thead><tr><th>ID</th><th>Subject</th><th>From</th><th>Status</th><th>Prio</th><th>Age</th></tr></thead>
          <tbody>
            <tr v-for="t in paged" :key="t.id" style="cursor:pointer" @click="openDetail(t)">
              <td style="font-weight:700;white-space:nowrap">{{ t.id }}</td>
              <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ t.subject }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ t.from_t || '—' }}</td>
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

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(560px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">🎧</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.prio || '—' }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:19px;font-weight:800;letter-spacing:-.3px;line-height:1.4">{{ sel.subject }}</h2>
          <div class="c-sub" style="margin-top:5px;font-size:12.5px">👤 {{ sel.from_t || '—' }} <template v-if="sel.age">· 🕒 {{ sel.age }}</template></div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin:14px 0">
            <span class="badge" :class="badge(sel.status)">{{ sel.status || '—' }}</span>
            <span class="badge" :class="prioCls(sel.prio)">{{ sel.prio || '—' }}</span>
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
