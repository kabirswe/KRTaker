<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useViewMode, usePager, fmtTs, money } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('partner-invoices')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const piAll = computed(() => data.list('partner_invoices'))
const partnerName = (pid) => data.list('partners').find(p => p.id === pid)?.name || pid || ''

const STATUS_CLS = {
  Submitted: 'b-orange', Payable: 'b-orange', Approved: 'b-blue', Paid: 'b-green', Rejected: 'b-red', Cancelled: 'b-gray',
}
const stCls = (s) => STATUS_CLS[s] || 'b-gray'
const statusRank = (s) => ({ Submitted: 0, Payable: 1, Approved: 2, Paid: 3, Rejected: 4 }[s] ?? 9)

// ── KPIs ──
const kpis = computed(() => {
  const ps = piAll.value
  const total = ps.reduce((s, p) => s + (p.amount || 0), 0)
  const pending = ps.filter(p => ['Submitted', 'Payable'].includes(p.status)).length
  const pendingAmt = ps.filter(p => ['Submitted', 'Payable'].includes(p.status)).reduce((s, p) => s + (p.amount || 0), 0)
  const approved = ps.filter(p => p.status === 'Approved').length
  const paid = ps.filter(p => p.status === 'Paid').length
  const partners = new Set(ps.map(p => p.partner).filter(Boolean)).size
  const avg = ps.length ? Math.round(total / ps.length) : 0
  return [
    { label: 'Invoices', ico: '🧾', value: ps.length, trend: 'vendor invoices' },
    { label: 'Total', ico: '💰', value: money(total), trend: 'across ' + partners + ' partner(s)' },
    { label: 'Pending', ico: '⏳', value: pending, trend: pending ? money(pendingAmt) + ' awaiting decision' : 'none pending', ok: pending === 0 },
    { label: 'Approved', ico: '✅', value: approved, trend: approved ? 'ready for payout' : 'none approved' },
    { label: 'Paid', ico: '💸', value: paid, trend: paid ? 'settled' : 'no payments yet' },
    { label: 'Avg', ico: '📊', value: money(avg), trend: 'per invoice' },
  ]
})

// ── filters ──
const query = ref('')
const statusFilter = ref('')
const partnerFilter = ref('')
const statusOptions = computed(() => [...new Set(piAll.value.map(p => p.status).filter(Boolean))].sort())
const partnerOptions = computed(() => [...new Set(piAll.value.map(p => p.partner).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = piAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(p => JSON.stringify(p).toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(p => (p.status || '') === statusFilter.value)
  if (partnerFilter.value) out = out.filter(p => (p.partner || '') === partnerFilter.value)
  return [...out].sort((a, b) => statusRank(a.status) - statusRank(b.status) || String(b.ts || '').localeCompare(String(a.ts || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[\",\n]/.test(s) ? '\"' + s.replace(/\"/g, '\"\"') + '\"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'partner-invoices.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(p) { sel.value = p }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const p = piAll.value.find(x => x.id === id); if (p) openDetail(p) }
}, { immediate: true })

function partnerRef(p) { return p.partner ? { path: '/vendors', query: { open: p.partner } } : null }
function jobRef(p) { return p.job ? { path: '/maintenance', query: { open: p.job } } : null }
function detailFields(row) {
  const skip = new Set(['id', 'partner', 'job', 'amount', 'status', 'desc'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🧾 Partner Invoices</h1>
        <div class="sub">{{ piAll.length }} vendor invoices · {{ kpis[2]?.value || 0 }} pending · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" placeholder="Search desc, job, submitter…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="partnerFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All partners</option>
          <option v-for="p in partnerOptions" :key="p" :value="p">{{ partnerName(p) }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
      </CompactFilters>
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
    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px">
      <div v-for="p in paged" :key="p.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(p)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">🧾</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="stCls(p.status)" style="background:#ffffff">{{ p.status || '—' }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:13px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ money(p.amount) }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="display:flex;align-items:center;gap:8px">
            <div style="min-width:0">
              <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ partnerName(p.partner) }}</div>
              <div class="c-sub" style="font-size:12px;margin-top:1px">{{ p.id }} · job {{ p.job || '—' }}</div>
            </div>
          </div>
          <div class="c-sub" style="font-size:12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.5">{{ p.desc || '—' }}</div>
          <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
            <span>📅 {{ (p.ts || '').slice(0, 10) }}</span>
            <span v-if="p.decided_by">✅ {{ p.decided_by }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Partner</th><th>Job</th><th>Desc</th><th>Amount</th><th>Status</th><th>Decided</th></tr></thead>
          <tbody>
            <tr v-for="p in paged" :key="p.id" style="cursor:pointer" @click="openDetail(p)">
              <td style="font-weight:700;white-space:nowrap">{{ p.id }}</td>
              <td style="white-space:nowrap"><span class="badge b-blue">{{ partnerName(p.partner) }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ p.job || '—' }}</td>
              <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" class="c-sub">{{ p.desc || '—' }}</td>
              <td style="white-space:nowrap;font-weight:700">{{ money(p.amount) }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="stCls(p.status)">{{ p.status || '—' }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ p.decided_by || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No invoices found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(580px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:44px">🧾</div>
          <div style="position:absolute;left:20px;top:36px;right:60px;text-align:center">
            <div style="color:#fff;font-weight:800;font-size:26px;text-shadow:0 2px 6px rgba(0,0,0,.4)">{{ money(sel.amount) }}</div>
          </div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.status || '—' }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:19px;font-weight:800;letter-spacing:-.3px">{{ partnerName(sel.partner) }}</h2>
          <div class="c-sub" style="margin-top:4px;font-size:12.5px">{{ sel.id }} · submitted {{ fmtTs(sel.ts) }}</div>
          <div v-if="sel.desc" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;font-size:13px;line-height:1.65">{{ sel.desc }}</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button v-if="partnerRef(sel)" class="btn-ghost" style="padding:8px 13px;font-size:12.5px" @click="go(partnerRef(sel).path, partnerRef(sel).query)">↗ {{ partnerName(sel.partner) }}</button>
            <button v-if="jobRef(sel)" class="btn-ghost" style="padding:8px 13px;font-size:12.5px" @click="go(jobRef(sel).path, jobRef(sel).query)">🔧 Job {{ sel.job }}</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px 18px;border-top:1px solid var(--border);padding-top:14px;margin-top:14px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Amount</div>
              <div style="font-weight:700;margin-top:1px">{{ money(sel.amount) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Job</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.job || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Submitted by</div>
              <div style="font-weight:700;margin-top:1px;word-break:break-word">{{ sel.submitted_by || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Decided by</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.decided_by || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Decided at</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtTs(sel.decided_at) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Status</div>
              <div style="font-weight:700;margin-top:1px"><span class="badge" :class="stCls(sel.status)">{{ sel.status || '—' }}</span></div>
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
