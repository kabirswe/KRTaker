<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useViewMode, usePager, money, fmtTs } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('onboarding')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const appsAll = computed(() => data.list('onboarding_apps'))
const propName = (pid) => data.list('properties').find(p => p.id === pid)?.name || pid || ''
const unitName = (uid) => data.list('units').find(u => u.id === uid)?.name || uid || ''
const leadName = (lid) => data.list('leads').find(l => l.id === lid)?.name || lid || ''

const stCls = (s) => s === 'Completed' ? 'b-green' : (s === 'Submitted' ? 'b-orange' : (s === 'Started' ? 'b-gray' : (s === 'Approved' ? 'b-blue' : 'b-gray')))
const stRank = (s) => ({ Completed: 0, Approved: 1, Submitted: 2, Started: 3 }[s] ?? 9)

// ── KPIs ──
const kpis = computed(() => {
  const as = appsAll.value
  const done = as.filter(a => a.status === 'Completed').length
  const submitted = as.filter(a => a.status === 'Submitted').length
  const started = as.filter(a => a.status === 'Started').length
  const pipeline = as.filter(a => a.status !== 'Completed').reduce((s, a) => s + (a.rent || 0), 0)
  const months = as.reduce((s, a) => s + (a.months || 0), 0)
  const units = new Set(as.map(a => a.unit).filter(Boolean)).size
  return [
    { label: 'Applications', ico: '📋', value: as.length, trend: 'tenant onboarding' },
    { label: 'Completed', ico: '✅', value: done, trend: done ? 'tenants moved in' : 'none yet', ok: done > 0 },
    { label: 'Submitted', ico: '📤', value: submitted, trend: submitted ? 'KYC review pending' : 'none', ok: submitted === 0 },
    { label: 'Started', ico: '📝', value: started, trend: started ? 'draft applications' : 'none', ok: started === 0 },
    { label: 'Pipeline rent', ico: '💰', value: money(pipeline), trend: 'monthly rent in progress' },
    { label: 'Units', ico: '🚪', value: units, trend: months + ' lease-months total' },
  ]
})

// ── filters ──
const query = ref('')
const statusFilter = ref('')
const statusOptions = computed(() => [...new Set(appsAll.value.map(a => a.status).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = appsAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(a => JSON.stringify(a).toLowerCase().includes(q) || (propName(a.prop) || '').toLowerCase().includes(q) || (unitName(a.unit) || '').toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(a => (a.status || '') === statusFilter.value)
  return [...out].sort((a, b) => stRank(a.status) - stRank(b.status) || String(b.updated_at || '').localeCompare(String(a.updated_at || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[\",\n]/.test(s) ? '\"' + s.replace(/\"/g, '\"\"') + '\"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'onboarding.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(a) { sel.value = a }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const a = appsAll.value.find(x => x.id === id); if (a) openDetail(a) }
}, { immediate: true })
function links(a) {
  const out = []
  if (a.tenant_id) out.push({ label: '👤 Tenant ' + a.tenant_id, path: '/tenants', q: a.tenant_id })
  if (a.lease_id) out.push({ label: '📄 Lease ' + a.lease_id, path: '/leases', q: a.lease_id })
  if (a.invoice_id) out.push({ label: '🧾 Invoice ' + a.invoice_id, path: '/invoices', q: a.invoice_id })
  if (a.lead) out.push({ label: '📥 Lead ' + a.lead, path: '/leads', q: a.lead })
  return out
}
function detailFields(row) {
  const skip = new Set(['id', 'name', 'status', 'notes'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>📋 Onboarding</h1>
        <div class="sub">{{ appsAll.length }} applications · {{ kpis[1]?.value || 0 }} completed · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" placeholder="Search name, email, unit…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
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
      <div v-for="a in paged" :key="a.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(a)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">📋</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="stCls(a.status)" style="background:#ffffff">{{ a.status || '—' }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ a.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ a.name }}</div>
          <div class="c-sub" style="font-size:12px">{{ a.email || '—' }} · {{ a.phone || '—' }}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span v-if="a.unit" class="badge b-blue">{{ unitName(a.unit) }}</span>
            <span v-if="a.prop" class="badge b-gray">{{ propName(a.prop) }}</span>
            <span v-if="a.lead" class="badge b-gray">📥 {{ leadName(a.lead) }}</span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge b-orange">Rent {{ money(a.rent) }}</span>
            <span class="badge b-gray">Adv {{ money(a.adv) }}</span>
            <span v-if="a.months" class="badge b-gray">{{ a.months }} mo</span>
          </div>
          <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
            <span>📅 {{ (a.start || '').slice(0, 10) }}</span>
            <span v-if="a.verified_by">✅ {{ a.verified_by }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Applicant</th><th>Unit</th><th>Rent</th><th>Start</th><th>Status</th><th>Linked</th></tr></thead>
          <tbody>
            <tr v-for="a in paged" :key="a.id" style="cursor:pointer" @click="openDetail(a)">
              <td style="font-weight:700;white-space:nowrap">{{ a.id }}</td>
              <td style="white-space:nowrap">{{ a.name }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ a.unit ? unitName(a.unit) : '—' }}</td>
              <td style="white-space:nowrap">{{ money(a.rent) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ (a.start || '').slice(0, 10) }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="stCls(a.status)">{{ a.status || '—' }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ a.tenant_id ? 'T✓' : (a.lease_id ? 'L✓' : '—') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No applications found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(600px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">📋</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.status || '—' }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.name }}</h2>
          <div class="c-sub" style="margin-top:4px;font-size:12.5px">{{ sel.email || '—' }} · {{ sel.phone || '—' }} · updated {{ fmtTs(sel.updated_at) }}</div>
          <div v-if="links(sel).length" style="display:flex;gap:6px;flex-wrap:wrap;margin:12px 0">
            <button v-for="l in links(sel)" :key="l.label" class="btn-ghost" style="padding:6px 12px;font-size:12px" @click="go(l.path, { open: l.q })">{{ l.label }}</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px 18px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Unit</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.unit ? unitName(sel.unit) : '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Property</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.prop ? propName(sel.prop) : '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Rent</div>
              <div style="font-weight:700;margin-top:1px">{{ money(sel.rent) }}/mo</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Advance</div>
              <div style="font-weight:700;margin-top:1px">{{ money(sel.adv) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Term</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.months ? sel.months + ' months' : '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Move-in</div>
              <div style="font-weight:700;margin-top:1px">{{ (sel.start || '').slice(0, 10) }}</div>
            </div>
            <div v-if="sel.nid" style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">NID</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.nid }}</div>
            </div>
            <div v-if="sel.verified_by" style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Verified by</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.verified_by }} · {{ fmtTs(sel.verified_at) }}</div>
            </div>
          </div>
          <div v-if="sel.notes" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;font-size:13px;line-height:1.65">{{ sel.notes }}</div>
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
