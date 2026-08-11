<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { badge, useViewMode, usePager } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import RichEditor from '../components/RichEditor.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('notices')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
// Server-side can_post_notice: superadmin, owner, manager, svc_mgr, crm, legal, accountant, hr
const canPost = computed(() => ['superadmin', 'owner', 'manager', 'svc_mgr', 'crm', 'legal', 'accountant', 'hr'].includes(auth.user?.role || ''))
const noticesAll = computed(() => data.list('notices'))

const fmtTs = (ts) => { if (!ts) return '—'; const d = new Date(String(ts).replace(' ', 'T')); if (isNaN(d)) return String(ts).slice(0, 10); return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) }
const timeAgo = (ts) => { if (!ts) return ''; const d = new Date(String(ts).replace(' ', 'T')); if (isNaN(d)) return ''; const s = (Date.now() - d.getTime()) / 1000; if (s < 3600) return Math.max(1, Math.floor(s / 60)) + 'm ago'; if (s < 86400) return Math.floor(s / 3600) + 'h ago'; if (s < 604800) return Math.floor(s / 86400) + 'd ago'; return fmtTs(ts) }

// ── KPIs ──
const kpis = computed(() => {
  const ns = noticesAll.value
  const pinned = ns.filter(n => n.pinned)
  const thisM = new Date().toISOString().slice(0, 7)
  const mCount = ns.filter(n => (n.ts || '').startsWith(thisM)).length
  const authors = new Set(ns.map(n => n.author).filter(Boolean)).size
  return [
    { label: 'Notices', ico: '📢', value: ns.length, trend: 'total posted' },
    { label: 'Pinned', ico: '📌', value: pinned.length, trend: pinned.length ? 'shown on tenant boards' : 'none pinned', ok: pinned.length > 0 },
    { label: 'This month', ico: '📅', value: mCount, trend: 'posted in ' + thisM.slice(0, 7) },
    { label: 'Authors', ico: '👤', value: authors, trend: 'staff contributors' },
    { label: 'Latest', ico: '🕒', value: ns.length ? timeAgo(ns[0].ts) : '—', trend: ns.length ? fmtTs(ns[0].ts) : '' },
    { label: 'Board reach', ico: '🏢', value: 'All', trend: 'visible to tenants & staff' },
  ]
})

// ── filters / sort ──
const query = ref('')
const pinnedOnly = ref(false)
const sortBy = ref('ts')
const filtered = computed(() => {
  let out = noticesAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(n => n.title.toLowerCase().includes(q) || (n.body || '').toLowerCase().includes(q) || (n.author || '').toLowerCase().includes(q))
  if (pinnedOnly.value) out = out.filter(n => n.pinned)
  const get = (n) => sortBy.value === 'title' ? (n.title || '') : (n.ts || '')
  return [...out].sort((a, b) => typeof get(a) === 'string' ? String(get(a)).localeCompare(String(get(b))) : String(get(b)).localeCompare(String(get(a))))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 10)
function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const lines = [['id', 'title', 'body', 'author', 'ts', 'pinned'].map(esc).join(',')]
  rows.forEach(n => lines.push([n.id, n.title, n.body, n.author, n.ts, n.pinned ? 'yes' : 'no'].map(esc).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'notices.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(n) { sel.value = n }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const n = noticesAll.value.find(x => x.id === id); if (n) openDetail(n) }
}, { immediate: true })

// ── compose / delete ──
const newModal = ref(false)
const saving = ref(false)
const newTitle = ref('')
const newBody = ref('')
async function submitNotice() {
  if (!newTitle.value.trim()) { window.__krToast?.('Notice title required', 'error'); return }
  saving.value = true
  try {
    const r = await apiCall('app-notice-create', { title: newTitle.value.trim(), body: newBody.value.trim() })
    if (r.ok) { window.__krToast?.(`📢 ${r.id} posted`, 'ok'); newModal.value = false; newTitle.value = ''; newBody.value = ''; await data.bootstrap() }
    else window.__krToast?.(r.error || 'Failed to post', 'error')
  } finally { saving.value = false }
}
const delBusy = ref('')
async function deleteNotice(n) {
  if (!confirm(`Delete notice ${n.id}?`)) return
  delBusy.value = n.id
  try {
    const r = await apiCall('app-notice-delete', { id: n.id })
    if (r.ok) { window.__krToast?.(`🗑️ ${n.id} deleted`, 'ok'); if (sel.value?.id === n.id) sel.value = null; await data.bootstrap() }
    else window.__krToast?.(r.error || 'Delete failed', 'error')
  } finally { delBusy.value = '' }
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>📢 Notices</h1>
        <div class="sub">{{ noticesAll.length }} notices · {{ kpis[1]?.value || 0 }} pinned · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search title, body, author…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <button @click="pinnedOnly = !pinnedOnly" class="btn-ghost" :style="pinnedOnly ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : ''" title="Show pinned only">📌 {{ pinnedOnly ? 'Pinned only' : 'All notices' }}</button>
        <select v-model="sortBy" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="ts">Sort: Newest</option>
          <option value="title">Sort: Title</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
        <button v-if="canPost" class="btn-primary" style="padding:9px 14px;font-size:12.5px" @click="newModal = true">＋ New notice</button>
      </div>
    </div>

    <div class="stats">
      <div v-for="k in kpis" :key="k.label" class="stat">
        <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
        <div class="s-value" :style="k.ok !== undefined ? (k.ok ? 'color:var(--ok)' : 'color:var(--danger)') : ''">{{ k.value }}</div>
        <div class="s-trend">{{ k.trend }}</div>
      </div>
    </div>

    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px">
      <div v-for="n in paged" :key="n.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(n)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">{{ n.pinned ? '📌' : '📢' }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span v-if="n.pinned" class="badge b-blue" style="background:#ffffff">📌 Pinned</span>
            <span v-else class="badge" style="background:#ffffff">Notice</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ n.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div>
            <div style="font-weight:800;font-size:15px;letter-spacing:-.2px;line-height:1.35">{{ n.title }}</div>
            <div class="c-sub" style="margin-top:4px;font-size:12px;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden" v-html="n.body || '—'"></div>
          </div>
          <div style="display:flex;gap:13px;font-size:12px;flex-wrap:wrap;margin-top:auto">
            <span class="c-sub">👤 {{ n.author || '—' }}</span>
            <span class="c-sub">🕒 {{ timeAgo(n.ts) }}</span>
          </div>
          <div v-if="canPost" style="display:flex;gap:6px;border-top:1px solid var(--border);padding-top:9px">
            <button class="btn-ghost" style="flex:1;justify-content:center;padding:6px 10px;font-size:12px;color:var(--danger)" :disabled="delBusy === n.id" @click.stop="deleteNotice(n)">🗑️ Delete</button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>Notice</th><th>Title</th><th>Author</th><th>Posted</th><th></th></tr></thead>
          <tbody>
            <tr v-for="n in paged" :key="n.id" style="cursor:pointer" @click="openDetail(n)">
              <td style="white-space:nowrap"><b>{{ n.id }}</b> <template v-if="n.pinned">📌</template></td>
              <td style="max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ n.title }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ n.author || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ fmtTs(n.ts) }}</td>
              <td style="white-space:nowrap"><button v-if="canPost" class="btn-ghost" style="padding:4px 9px;font-size:11px;color:var(--danger)" :disabled="delBusy === n.id" @click.stop="deleteNotice(n)">🗑️</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No notices found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(620px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">{{ sel.pinned ? '📌' : '📢' }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span v-if="sel.pinned" class="badge b-blue" style="background:#ffffff">📌 Pinned</span>
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px;line-height:1.35">{{ sel.title }}</h2>
          <div class="c-sub" style="margin-top:5px">👤 {{ sel.author || '—' }} · 🕒 {{ fmtTs(sel.ts) }} <template v-if="sel.pinned">· 📌 pinned to tenant boards</template></div>
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:16px 18px;margin:16px 0;font-size:13.5px;line-height:1.75" v-html="sel.body || '—'"></div>
          <div v-if="canPost" style="display:flex;gap:8px;margin-bottom:14px">
            <button class="btn-ghost" style="padding:8px 15px;font-size:12.5px;color:var(--danger)" :disabled="delBusy === sel.id" @click="deleteNotice(sel)">🗑️ Delete notice</button>
          </div>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>

    <!-- compose modal -->
    <template v-if="newModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="newModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(480px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">📢 New notice</h3>
          <button @click="newModal = false" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Title *</label>
            <input v-model="newTitle" placeholder="e.g. Utility schedule for August" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13.5px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Body</label>
            <RichEditor v-model="newBody" placeholder="Write the notice… visible to tenants and staff" :min-height="'160px'" style="margin-top:5px" />
          </div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="newModal = false">Cancel</button>
          <button class="btn-primary" :disabled="saving" @click="submitNotice" style="padding:9px 18px">{{ saving ? 'Posting…' : '📢 Post notice' }}</button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* White badges only on cover/header areas (same convention as Properties/Units) */
.d-cover .badge { background: #ffffff; }
</style>
