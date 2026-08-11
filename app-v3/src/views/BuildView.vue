<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useViewMode, usePager, money, fmtTs } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('build')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const projAll = computed(() => data.list('build_projects'))
const milesAll = computed(() => data.list('build_milestones'))
const expsAll = computed(() => data.list('build_expenses'))
const propName = (pid) => data.list('properties').find(p => p.id === pid)?.name || pid || ''

const KIND_META = { renovation: { ico: '🛠️', label: 'Renovation', cls: 'b-blue' }, construction: { ico: '🏗️', label: 'Construction', cls: 'b-orange' }, repair: { ico: '🔧', label: 'Repair', cls: 'b-green' }, default: { ico: '🏗️', label: 'Project', cls: 'b-gray' } }
const kindMeta = (k) => KIND_META[k] || KIND_META.default
const stCls = (s) => s === 'In_Progress' ? 'b-blue' : (s === 'Completed' ? 'b-green' : (s === 'Pending' ? 'b-orange' : (s === 'On_Hold' ? 'b-gray' : 'b-gray')))
const stLabel = (s) => String(s || '—').replace(/_/g, ' ')
const daysTo = (d) => { if (!d) return null; const t = new Date(String(d).slice(0, 10) + 'T00:00:00'); if (isNaN(t)) return null; return Math.round((t - Date.now()) / 86400000) }
const fmtDate = (d) => { if (!d) return '—'; const t = new Date(String(d).slice(0, 10) + 'T00:00:00'); return isNaN(t) ? String(d).slice(0, 10) : t.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) }
const endNote = (p) => {
  const n = daysTo(p.target_end)
  if (n === null) return ''
  if (p.status === 'Completed') return 'completed'
  if (n < 0) return 'overdue by ' + (-n) + 'd'
  if (n === 0) return 'due today'
  return n + 'd left'
}

// ── KPIs ──
const kpis = computed(() => {
  const ps = projAll.value
  const prog = ps.filter(p => p.status === 'In_Progress').length
  const done = ps.filter(p => p.status === 'Completed').length
  const budget = ps.reduce((s, p) => s + (p.budget_total || 0), 0)
  const contractors = new Set(ps.map(p => p.contractor).filter(Boolean)).size
  const overdue = ps.filter(p => p.status === 'In_Progress' && daysTo(p.target_end) !== null && daysTo(p.target_end) < 0).length
  return [
    { label: 'Projects', ico: '🏗️', value: ps.length, trend: 'build works tracked' },
    { label: 'In progress', ico: '🔵', value: prog, trend: prog ? 'active sites' : 'none active' },
    { label: 'Completed', ico: '✅', value: done, trend: done ? 'finished' : 'none yet' },
    { label: 'Budget', ico: '💰', value: money(budget), trend: 'total planned spend' },
    { label: 'Contractors', ico: '🧰', value: contractors, trend: 'firms engaged' },
    { label: 'Overdue', ico: '⏰', value: overdue, trend: overdue ? 'past target end' : 'on schedule', ok: overdue === 0 },
  ]
})

// ── filters ──
const query = ref('')
const kindFilter = ref('')
const statusFilter = ref('')
const kindOptions = computed(() => [...new Set(projAll.value.map(p => p.kind).filter(Boolean))].sort())
const statusOptions = computed(() => [...new Set(projAll.value.map(p => p.status).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = projAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(p => JSON.stringify(p).toLowerCase().includes(q) || (propName(p.prop) || '').toLowerCase().includes(q))
  if (kindFilter.value) out = out.filter(p => (p.kind || '') === kindFilter.value)
  if (statusFilter.value) out = out.filter(p => (p.status || '') === statusFilter.value)
  return [...out].sort((a, b) => String(b.updated_at || '').localeCompare(String(a.updated_at || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 10)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[\",\n]/.test(s) ? '\"' + s.replace(/\"/g, '\"\"') + '\"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'build.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(p) { sel.value = p }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const p = projAll.value.find(x => x.id === id); if (p) openDetail(p) }
}, { immediate: true })
const milestones = computed(() => milesAll.value.filter(m => m.project === sel.value?.id).sort((a, b) => String(a.target_date || '').localeCompare(String(b.target_date || ''))))
const expenses = computed(() => expsAll.value.filter(e => e.project === sel.value?.id).sort((a, b) => String(b.spent_on || '').localeCompare(String(a.spent_on || ''))))
const expTotal = computed(() => expenses.value.reduce((s, e) => s + (e.amount || 0), 0))
const expPaid = computed(() => expenses.value.filter(e => e.paid).reduce((s, e) => s + (e.amount || 0), 0))
function propRef(p) { return p.prop ? { path: '/properties', query: { open: p.prop } } : null }
function detailFields(row) {
  const skip = new Set(['id', 'title', 'kind', 'status', 'budget_total', 'notes'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🏗️ Build Watch</h1>
        <div class="sub">{{ projAll.length }} projects · {{ kpis[3]?.value || 0 }} budget · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search title, contractor, site…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="kindFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All kinds</option>
          <option v-for="k in kindOptions" :key="k" :value="k">{{ kindMeta(k).label }}</option>
        </select>
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ stLabel(s) }}</option>
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
      <div v-for="p in paged" :key="p.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(p)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">{{ kindMeta(p.kind).ico }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="kindMeta(p.kind).cls" style="background:#ffffff">{{ kindMeta(p.kind).label }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ p.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ p.title }}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="stCls(p.status)">{{ stLabel(p.status) }}</span>
            <span v-if="p.prop" class="badge b-blue">{{ propName(p.prop) }}</span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge b-gray">💰 {{ money(p.budget_total) }}</span>
            <span v-if="p.contractor" class="badge b-orange">🧰 {{ p.contractor }}</span>
          </div>
          <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
            <span>📅 {{ fmtDate(p.target_end) }}</span>
            <template v-if="endNote(p)"><span :style="endNote(p).includes('overdue') ? 'color:var(--danger)' : ''">{{ endNote(p) }}</span></template>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Project</th><th>Kind</th><th>Property</th><th>Budget</th><th>Contractor</th><th>Target end</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="p in paged" :key="p.id" style="cursor:pointer" @click="openDetail(p)">
              <td style="font-weight:700;white-space:nowrap">{{ p.id }}</td>
              <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ p.title }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="kindMeta(p.kind).cls">{{ kindMeta(p.kind).label }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ p.prop ? propName(p.prop) : '—' }}</td>
              <td style="white-space:nowrap;font-weight:700">{{ money(p.budget_total) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ p.contractor || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ fmtDate(p.target_end) }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="stCls(p.status)">{{ stLabel(p.status) }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No projects found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(680px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:44px">{{ kindMeta(sel.kind).ico }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ kindMeta(sel.kind).label }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:19px;font-weight:800;letter-spacing:-.3px;line-height:1.35">{{ sel.title }}</h2>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin:12px 0">
            <span class="badge" :class="stCls(sel.status)">{{ stLabel(sel.status) }}</span>
            <span class="badge b-gray">💰 {{ money(sel.budget_total) }}</span>
            <button v-if="propRef(sel)" class="btn-ghost" style="padding:4px 10px;font-size:12px" @click="go(propRef(sel).path, propRef(sel).query)">↗ {{ propName(sel.prop) }}</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px 18px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Start</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtDate(sel.start_date) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Target end</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtDate(sel.target_end) }} <template v-if="endNote(sel)"><span :style="endNote(sel).includes('overdue') ? 'color:var(--danger)' : ''">({{ endNote(sel) }})</span></template></div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Contractor</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.contractor || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Architect</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.architect || '—' }}</div>
            </div>
          </div>
          <div v-if="sel.site_address" class="c-sub" style="font-size:12.5px;margin-top:10px">📍 {{ sel.site_address }}</div>
          <div v-if="sel.notes" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;font-size:13px;line-height:1.65">{{ sel.notes }}</div>

          <!-- milestones -->
          <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:9px">🏁 Milestones · {{ milestones.length }}</div>
            <div v-if="!milestones.length" class="c-sub" style="font-size:12.5px">No milestones.</div>
            <div v-else class="drawer-tbl-wrap" style="overflow:auto;max-height:52vh">
              <table class="kr" style="width:100%">
                <thead><tr><th>Milestone</th><th>Phase</th><th>Target</th><th>Cost</th><th>Paid</th><th>Status</th></tr></thead>
                <tbody>
                  <tr v-for="m in milestones" :key="m.id">
                    <td style="white-space:nowrap"><b>{{ m.title }}</b></td>
                    <td style="white-space:nowrap" class="c-sub">{{ m.phase || '—' }}</td>
                    <td style="white-space:nowrap" class="c-sub">{{ fmtDate(m.target_date) }}</td>
                    <td style="white-space:nowrap">{{ money(m.cost) }}</td>
                    <td style="white-space:nowrap" class="c-sub">{{ m.paid ? money(m.paid) : '—' }}</td>
                    <td style="white-space:nowrap"><span class="badge" :class="stCls(m.status)">{{ stLabel(m.status) }}</span></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- expenses -->
          <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:9px">🧾 Expenses · {{ expenses.length }} · {{ money(expTotal) }} total · {{ money(expPaid) }} paid</div>
            <div v-if="!expenses.length" class="c-sub" style="font-size:12.5px">No expenses recorded.</div>
            <div v-else class="drawer-tbl-wrap" style="overflow:auto;max-height:52vh">
              <table class="kr" style="width:100%">
                <thead><tr><th>Label</th><th>Category</th><th>Spent on</th><th>Amount</th><th>Paid</th></tr></thead>
                <tbody>
                  <tr v-for="e in expenses" :key="e.id">
                    <td style="white-space:nowrap"><b>{{ e.label }}</b></td>
                    <td style="white-space:nowrap"><span class="badge" :class="e.category === 'labour' ? 'b-orange' : 'b-blue'">{{ e.category || '—' }}</span></td>
                    <td style="white-space:nowrap" class="c-sub">{{ fmtDate(e.spent_on) }}</td>
                    <td style="white-space:nowrap;font-weight:700">{{ money(e.amount) }}</td>
                    <td style="white-space:nowrap"><span class="badge" :class="e.paid ? 'b-green' : 'b-red'">{{ e.paid ? 'Paid' : 'Unpaid' }}</span></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div v-for="[k, v] in detailFields(sel)" :key="k" style="font-size:13px;margin-top:9px">
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
