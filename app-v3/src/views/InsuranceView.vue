<script setup>
import { computed, ref, watch } from 'vue'
import { t } from '../lib/i18n'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { useViewMode, usePager, money, fmtTs } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('insurance')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))
const polAll = computed(() => data.list('insurance_policies'))
const tenantName = (tid) => data.list('tenants').find(t => t.id === tid)?.name || tid || ''
const stCls = (s) => s === 'paid' ? 'b-green' : (s === 'active' ? 'b-green' : (s === 'expired' ? 'b-gray' : (s === 'cancelled' ? 'b-red' : 'b-gray')))
const stLabel = (s) => s === 'paid' ? 'Active' : (s || '—')
const daysLeft = (p) => {
  if (!p.end) return null
  const t = new Date(String(p.end).slice(0, 10) + 'T00:00:00')
  if (isNaN(t)) return null
  return Math.round((t - Date.now()) / 86400000)
}
const fmtDate = (d) => { if (!d) return '—'; const t = new Date(String(d).slice(0, 10) + 'T00:00:00'); return isNaN(t) ? String(d).slice(0, 10) : t.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) }

// ── KPIs ──
const kpis = computed(() => {
  const ps = polAll.value
  const coverage = ps.reduce((s, p) => s + (p.coverage || 0), 0)
  const premium = ps.reduce((s, p) => s + (p.premium || 0), 0)
  const active = ps.filter(p => stCls(p.status) === 'b-green').length
  const claims = ps.filter(p => p.claim).length
  const claimAmt = ps.filter(p => p.claim).reduce((s, p) => s + (p.claim_amt || 0), 0)
  const tenants = new Set(ps.map(p => p.tenant).filter(Boolean)).size
  return [
    { label: 'Policies', ico: '🛡️', value: ps.length, trend: 'insurance policies' },
    { label: 'Coverage', ico: '🏦', value: money(coverage), trend: 'total insured value' },
    { label: 'Premium', ico: '💳', value: money(premium), trend: 'total premium paid' },
    { label: 'Active', ico: '✅', value: active, trend: active + ' in force', ok: active === ps.length },
    { label: 'Claims', ico: '📋', value: claims, trend: claims ? money(claimAmt) + ' claimed' : 'no claims', ok: claims <= 1 },
    { label: 'Tenants', ico: '👥', value: tenants, trend: 'policy holders' },
  ]
})

// ── filters ──
const query = ref('')
const statusFilter = ref('')
const tenantFilter = ref('')
const statusOptions = computed(() => [...new Set(polAll.value.map(p => p.status).filter(Boolean))].sort())
const tenantOptions = computed(() => [...new Set(polAll.value.map(p => p.tenant).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = polAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(p => JSON.stringify(p).toLowerCase().includes(q) || (tenantName(p.tenant) || '').toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(p => (p.status || '') === statusFilter.value)
  if (tenantFilter.value) out = out.filter(p => (p.tenant || '') === tenantFilter.value)
  return [...out].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[\",\n]/.test(s) ? '\"' + s.replace(/\"/g, '\"\"') + '\"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'insurance.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(p) { sel.value = p }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const p = polAll.value.find(x => x.id === id); if (p) openDetail(p) }
}, { immediate: true })
function tenantRef(p) { return p.tenant ? { path: '/tenants', query: { open: p.tenant } } : null }
function leaseRef(p) { return p.lease ? { path: '/leases', query: { open: p.lease } } : null }
function detailFields(row) {
  const skip = new Set(['id', 'tenant', 'lease', 'plan', 'premium', 'coverage', 'score', 'status', 'start', 'end', 'claim', 'claim_amt', 'claim_ts'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}

// ── owner write actions ──
const busy = ref('')
async function cancelPolicy(p) {
  if (!confirm(`Cancel policy ${p.id} for ${tenantName(p.tenant)}?`)) return
  busy.value = p.id
  const r = await apiCall('app-insurance', { action: 'cancel', id: p.id })
  busy.value = ''
  if (!r.ok) { alert(r.error || 'Cancel failed'); return }
  await data.bootstrap()
  closeDetail()
}
async function decideClaim(p, verdict) {
  busy.value = p.id
  const r = await apiCall('app-insurance', { action: 'decide', id: p.id, verdict })
  busy.value = ''
  if (!r.ok) { alert(r.error || 'Decision failed'); return }
  await data.bootstrap()
  closeDetail()
}
const cancellable = (p) => canManage.value && !['cancelled', 'expired'].includes(String(p.status || '')) && !p.claim
const pendingClaim = (p) => canManage.value && p.claim && !p.claim_amt
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('🛡️ Insurance') }}</h1>
        <div class="sub">{{ polAll.length }} policies · {{ kpis[1]?.value || 0 }} coverage · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" :placeholder="t('Search tenant, plan, claim…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ stLabel(s) }}</option>
        </select>
        <select v-model="tenantFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All tenants</option>
          <option v-for="t in tenantOptions" :key="t" :value="t">{{ tenantName(t) }}</option>
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
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">🛡️</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="stCls(p.status)" style="background:#ffffff">{{ stLabel(p.status) }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ p.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ tenantName(p.tenant) }}</div>
          <div class="c-sub" style="font-size:12px">{{ p.plan || '—' }} · lease {{ p.lease || '—' }}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge b-blue">Cover {{ money(p.coverage) }}</span>
            <span class="badge b-gray">৳{{ p.premium }}/mo</span>
            <span v-if="p.score" class="badge b-orange">★ {{ p.score }}</span>
          </div>
          <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
            <span>📅 {{ fmtDate(p.end) }}</span>
            <template v-if="daysLeft(p) !== null"><span v-if="daysLeft(p) < 0" style="color:var(--danger)">expired {{ -daysLeft(p) }}d ago</span><span v-else>{{ daysLeft(p) }}d left</span></template>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Tenant</th><th>Plan</th><th>Premium</th><th>Coverage</th><th>Score</th><th>Status</th><th>Expires</th></tr></thead>
          <tbody>
            <tr v-for="p in paged" :key="p.id" style="cursor:pointer" @click="openDetail(p)">
              <td style="font-weight:700;white-space:nowrap">{{ p.id }}</td>
              <td style="white-space:nowrap">{{ tenantName(p.tenant) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ p.plan || '—' }}</td>
              <td style="white-space:nowrap">{{ money(p.premium) }}/mo</td>
              <td style="white-space:nowrap;font-weight:700">{{ money(p.coverage) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ p.score ?? '—' }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="stCls(p.status)">{{ stLabel(p.status) }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ fmtDate(p.end) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No policies found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(580px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">🛡️</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ stLabel(sel.status) }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ tenantName(sel.tenant) }}</h2>
          <div class="c-sub" style="margin-top:4px;font-size:12.5px">{{ sel.plan || '—' }} · started {{ fmtDate(sel.start) }}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin:12px 0">
            <span class="badge b-blue">Cover {{ money(sel.coverage) }}</span>
            <span class="badge b-gray">৳{{ sel.premium }}/mo</span>
            <span v-if="sel.score" class="badge b-orange">★ {{ sel.score }}</span>
            <button v-if="tenantRef(sel)" class="btn-ghost" style="padding:4px 10px;font-size:12px" @click="go(tenantRef(sel).path, tenantRef(sel).query)">↗ {{ tenantName(sel.tenant) }}</button>
            <button v-if="leaseRef(sel)" class="btn-ghost" style="padding:4px 10px;font-size:12px" @click="go(leaseRef(sel).path, leaseRef(sel).query)">↗ Lease {{ sel.lease }}</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px 18px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Start</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtDate(sel.start) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Expires</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtDate(sel.end) }} <template v-if="daysLeft(sel) !== null"><span v-if="daysLeft(sel) < 0" style="color:var(--danger)">(expired {{ -daysLeft(sel) }}d ago)</span><span v-else>({{ daysLeft(sel) }}d left)</span></template></div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Lease</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.lease || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Plan</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.plan || '—' }}</div>
            </div>
          </div>
          <div v-if="sel.claim" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:7px">📋 Claim filed</div>
            <div style="font-size:13.5px;line-height:1.6">{{ sel.claim }}</div>
            <div style="display:flex;gap:16px;font-size:12px;margin-top:8px">
              <span class="badge b-red">Claimed {{ money(sel.claim_amt) }}</span>
              <span class="c-sub">🕒 {{ fmtTs(sel.claim_ts) }}</span>
            </div>
            <div v-if="pendingClaim(sel)" style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
              <button :disabled="busy === sel.id" @click="decideClaim(sel, 'approve')" class="btn-primary" style="padding:8px 16px;font-size:12.5px">✓ Approve claim</button>
              <button :disabled="busy === sel.id" @click="decideClaim(sel, 'reject')" class="btn-ghost" style="padding:8px 16px;font-size:12.5px;color:var(--danger)">✕ Reject</button>
            </div>
            <div v-else-if="sel.claim_amt" style="margin-top:8px;font-size:12px;color:var(--ok)">✅ Claim settled — {{ money(sel.claim_amt) }} paid out</div>
          </div>
          <div v-if="cancellable(sel)" style="display:flex;gap:8px;margin:14px 0;flex-wrap:wrap">
            <button :disabled="busy === sel.id" @click="cancelPolicy(sel)" class="btn-ghost" style="padding:8px 14px;font-size:12.5px;color:var(--danger)">⛔ Cancel policy</button>
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
