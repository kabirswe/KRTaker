<script setup>
import { computed, ref, watch } from 'vue'
import { t } from '../lib/i18n'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useViewMode, usePager, money, fmtTs, today } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('holding-taxes')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const htAll = computed(() => data.list('holding_taxes'))
const parcelName = (pid) => data.list('land_parcels').find(p => p.id === pid)?.district || pid || ''
const propName = (pid) => data.list('properties').find(p => p.id === pid)?.name || pid || ''

const stCls = (s) => s === 'Paid' ? 'b-green' : (s === 'Partial' ? 'b-orange' : (s === 'Due' ? 'b-red' : 'b-gray'))
const dueOf = (h) => Math.max(0, (h.tax_amount || 0) - (h.paid_amount || 0))
const overdue = (h) => h.status !== 'Paid' && h.due_date && h.due_date < today()

// ── KPIs ──
const kpis = computed(() => {
  const hs = htAll.value
  const tax = hs.reduce((s, h) => s + (h.tax_amount || 0), 0)
  const paid = hs.reduce((s, h) => s + (h.paid_amount || 0), 0)
  const outstanding = hs.reduce((s, h) => s + dueOf(h), 0)
  const over = hs.filter(overdue).length
  const corps = new Set(hs.map(h => h.city_corp).filter(Boolean)).size
  return [
    { label: t('Holding taxes'), ico: '🏛️', value: hs.length, trend: 'city corp assessments' },
    { label: t('Assessed'), ico: '📋', value: money(tax), trend: 'total tax billed' },
    { label: t('Collected'), ico: '💵', value: money(paid), trend: 'paid so far' },
    { label: t('Outstanding'), ico: '⚠️', value: money(outstanding), trend: outstanding ? 'unpaid balance' : t('fully settled'), ok: outstanding === 0 },
    { label: t('Overdue'), ico: '⏰', value: over, trend: over ? 'past due date' : 'none', ok: over === 0 },
    { label: 'Corporations', ico: '🏙️', value: corps, trend: 'city corporations' },
  ]
})

// ── filters ──
const query = ref('')
const statusFilter = ref('')
const statusOptions = computed(() => [...new Set(htAll.value.map(h => h.status).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = htAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(h => JSON.stringify(h).toLowerCase().includes(q) || (parcelName(h.parcel) || '').toLowerCase().includes(q) || (propName(h.prop) || '').toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(h => (h.status || '') === statusFilter.value)
  return [...out].sort((a, b) => String(b.fy || '').localeCompare(String(a.fy || '')) || String(a.id || '').localeCompare(String(b.id || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'holding-taxes.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(h) { sel.value = h }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const h = htAll.value.find(x => x.id === id); if (h) openDetail(h) }
}, { immediate: true })
function refLinks(h) {
  const out = []
  if (h.parcel) out.push({ label: '🗺️ Land ' + h.parcel, path: '/land', q: h.parcel })
  if (h.prop) out.push({ label: '🏢 ' + propName(h.prop), path: '/properties', q: h.prop })
  return out
}
function detailFields(row) {
  const skip = new Set(['id', 'city_corp', 'ward', 'holding_no', 'fy', 'annual_value', 'rate_pct', 'tax_amount', 'paid_amount', 'status', 'due_date', 'paid_date', 'receipt_no', 'notes', 'parcel', 'prop'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('🏛️ Holding Taxes') }}</h1>
        <div class="sub">{{ htAll.length }} assessments · {{ kpis[3]?.value || '৳0' }} outstanding · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" :placeholder="t('Search holding no, property…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All statuses') }}</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" :title="t('Download CSV')">⬇ CSV</button>
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
      <div v-for="h in paged" :key="h.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(h)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">🏛️</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="stCls(h.status)" style="background:#ffffff">{{ h.status || '—' }}</span>
            <span v-if="overdue(h)" class="badge b-red" style="background:#ffffff">⏰ Overdue</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ h.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
            <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px">{{ h.city_corp || '—' }}<template v-if="h.holding_no"> · {{ h.holding_no }}</template></div>
            <span v-if="h.fy" class="badge b-gray">{{ h.fy }}</span>
          </div>
          <div class="c-sub" style="font-size:12px">Ward {{ h.ward || '—' }}<template v-if="h.parcel"> · 🗺️ {{ parcelName(h.parcel) }}</template><template v-if="h.prop"> · {{ propName(h.prop) }}</template></div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge b-orange">Tax {{ money(h.tax_amount) }}</span>
            <span v-if="h.paid_amount" class="badge b-green">Paid {{ money(h.paid_amount) }}</span>
            <span v-if="dueOf(h)" class="badge b-red">Due {{ money(dueOf(h)) }}</span>
          </div>
          <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
            <span v-if="h.due_date">📅 due {{ h.due_date }}</span>
            <span v-if="h.paid_date">✅ paid {{ fmtTs(h.paid_date) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>{{ t('Corporation') }}</th><th>{{ t('Holding') }}</th><th>FY</th><th>{{ t('Assessed') }}</th><th>{{ t('Paid') }}</th><th>{{ t('Due') }}</th><th>{{ t('Due date') }}</th><th>{{ t('Status') }}</th></tr></thead>
          <tbody>
            <tr v-for="h in paged" :key="h.id" style="cursor:pointer" @click="openDetail(h)">
              <td style="font-weight:700;white-space:nowrap">{{ h.id }}</td>
              <td style="white-space:nowrap">{{ h.city_corp || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ h.holding_no || '—' }} <span class="c-sub" style="font-size:10.5px">W{{ h.ward || '—' }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ h.fy || '—' }}</td>
              <td style="white-space:nowrap">{{ money(h.tax_amount) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ money(h.paid_amount) }}</td>
              <td style="white-space:nowrap;font-weight:700" :style="dueOf(h) ? 'color:var(--danger)' : ''">{{ dueOf(h) ? money(dueOf(h)) : '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ h.due_date || '—' }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="stCls(h.status)">{{ h.status || '—' }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No holding taxes found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(600px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">🏛️</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" :class="stCls(sel.status)" style="background:#ffffff">{{ sel.status || '—' }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.city_corp || 'Holding tax' }} · {{ sel.fy || '—' }}</h2>
          <div class="c-sub" style="margin-top:4px;font-size:12.5px">Ward {{ sel.ward || '—' }} · Holding no {{ sel.holding_no || '—' }}</div>
          <div v-if="refLinks(sel).length" style="display:flex;gap:6px;flex-wrap:wrap;margin:12px 0">
            <button v-for="l in refLinks(sel)" :key="l.label" class="btn-ghost" style="padding:6px 12px;font-size:12px" @click="go(l.path, { open: l.q })">{{ l.label }}</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px 18px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Annual value') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ money(sel.annual_value) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Rate') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.rate_pct ? sel.rate_pct + '%' : '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Due date') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.due_date || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Paid date') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.paid_date ? fmtTs(sel.paid_date) : '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Receipt no') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.receipt_no || '—' }}</div>
            </div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin:14px 0">
            <div style="flex:1;min-width:130px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Assessed tax') }}</div>
              <div style="font-weight:800;font-size:19px;margin-top:2px">{{ money(sel.tax_amount) }}</div>
            </div>
            <div style="flex:1;min-width:130px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Paid') }}</div>
              <div style="font-weight:800;font-size:19px;margin-top:2px;color:var(--ok)">{{ money(sel.paid_amount) }}</div>
            </div>
            <div style="flex:1;min-width:130px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Balance') }}</div>
              <div style="font-weight:800;font-size:19px;margin-top:2px" :style="dueOf(sel) ? 'color:var(--danger)' : 'color:var(--ok)'">{{ dueOf(sel) ? money(dueOf(sel)) : '—' }}</div>
            </div>
          </div>
          <div v-if="sel.rate_pct && sel.annual_value" class="c-sub" style="font-size:12px;margin:-6px 0 10px">{{ sel.rate_pct }}% of {{ money(sel.annual_value) }} annual value</div>
          <div v-if="sel.notes" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;font-size:13px;line-height:1.65">{{ sel.notes }}</div>
          <div v-for="[k, v] in detailFields(sel)" :key="k" style="font-size:13px;margin-bottom:8px">
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t(k.replace(/_/g, ' ')) }}</div>
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
