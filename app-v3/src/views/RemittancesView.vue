<script setup>
import { computed, ref, watch } from 'vue'
import { t } from '../lib/i18n'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useViewMode, usePager, money, monthLabel, fmtTs } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('remittances')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const rmAll = computed(() => data.list('remittances'))
const propName = (pid) => data.list('properties').find(p => p.id === pid)?.name || pid || ''
const stCls = (s) => s === 'Confirmed' ? 'b-green' : (s === 'Sent' ? 'b-blue' : (s === 'Failed' ? 'b-red' : 'b-gray'))

// ── KPIs ──
const kpis = computed(() => {
  const rs = rmAll.value
  const bdt = rs.reduce((s, r) => s + (r.amount || 0), 0)
  const fx = rs.reduce((s, r) => s + (r.amount_fx || 0), 0)
  const confirmed = rs.filter(r => r.status === 'Confirmed').length
  const confirmedAmt = rs.filter(r => r.status === 'Confirmed').reduce((s, r) => s + (r.amount || 0), 0)
  const sent = rs.filter(r => r.status === 'Sent').length
  const months = new Set(rs.map(r => r.month).filter(Boolean)).size
  const cur = new Set(rs.map(r => r.currency).filter(Boolean)).size
  return [
    { label: 'Remittances', ico: '🌍', value: rs.length, trend: 'NRB repatriations' },
    { label: 'BDT total', ico: '💵', value: money(bdt), trend: 'repatriated' },
    { label: 'FX total', ico: '💱', value: fx ? fx.toLocaleString('en-US') + ' ' + (rs[0]?.currency || '') : '—', trend: 'foreign currency' },
    { label: 'Confirmed', ico: '✅', value: confirmed, trend: confirmed ? money(confirmedAmt) + ' confirmed' : 'none', ok: confirmed > 0 },
    { label: 'In transit', ico: '🚀', value: sent, trend: sent ? 'awaiting bank confirmation' : 'none', ok: sent === 0 },
    { label: 'Months', ico: '🗓️', value: months, trend: cur + ' currency · SWIFT' },
  ]
})

// ── filters ──
const query = ref('')
const statusFilter = ref('')
const monthFilter = ref('')
const statusOptions = computed(() => [...new Set(rmAll.value.map(r => r.status).filter(Boolean))].sort())
const monthOptions = computed(() => [...new Set(rmAll.value.map(r => r.month).filter(Boolean))].sort().reverse())
const filtered = computed(() => {
  let out = rmAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(r => JSON.stringify(r).toLowerCase().includes(q) || (propName(r.prop) || '').toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(r => (r.status || '') === statusFilter.value)
  if (monthFilter.value) out = out.filter(r => (r.month || '') === monthFilter.value)
  return [...out].sort((a, b) => String(b.month || '').localeCompare(String(a.month || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[\",\n]/.test(s) ? '\"' + s.replace(/\"/g, '\"\"') + '\"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'remittances.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(r) { sel.value = r }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const r = rmAll.value.find(x => x.id === id); if (r) openDetail(r) }
}, { immediate: true })
function propRef(r) { return r.prop ? { path: '/properties', query: { open: r.prop } } : null }
function detailFields(row) {
  const skip = new Set(['id', 'prop', 'month', 'amount', 'amount_fx', 'currency', 'method', 'status'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('🌍 Remittances') }}</h1>
        <div class="sub">{{ rmAll.length }} repatriations · {{ kpis[1]?.value || 0 }} · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" :placeholder="t('Search ref, form C, owner…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All statuses') }}</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="monthFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All months') }}</option>
          <option v-for="m in monthOptions" :key="m" :value="m">{{ monthLabel(m) }}</option>
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
      <div v-for="r in paged" :key="r.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(r)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">🌍</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="stCls(r.status)" style="background:#ffffff">{{ r.status || '—' }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:13px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ money(r.amount) }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ r.owner_name || '—' }}</div>
          <div class="c-sub" style="font-size:12px">{{ r.id }} · {{ monthLabel(r.month) }}<template v-if="r.prop"> · {{ propName(r.prop) }}</template></div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge b-blue">💱 {{ r.amount_fx ? r.amount_fx.toLocaleString('en-US') : '—' }} {{ r.currency || '' }}</span>
            <span v-if="r.rate" class="badge b-gray">{{ t('Rate') }} {{ r.rate }}</span>
            <span v-if="r.method" class="badge b-orange">{{ r.method }}</span>
          </div>
          <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
            <span>🧾 {{ r.form_c || '—' }}</span>
            <span v-if="r.ref">#{{ r.ref }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>{{ t('Owner') }}</th><th>{{ t('Month') }}</th><th>{{ t('Amount') }}</th><th>FX</th><th>{{ t('Rate') }}</th><th>{{ t('Status') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in paged" :key="r.id" style="cursor:pointer" @click="openDetail(r)">
              <td style="font-weight:700;white-space:nowrap">{{ r.id }}</td>
              <td style="white-space:nowrap">{{ r.owner_name || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ monthLabel(r.month) }}</td>
              <td style="white-space:nowrap;font-weight:700">{{ money(r.amount) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ r.amount_fx ? r.amount_fx.toLocaleString('en-US') : '—' }} {{ r.currency || '' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ r.rate || '—' }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="stCls(r.status)">{{ r.status || '—' }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No remittances found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(580px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:44px">🌍</div>
          <div style="position:absolute;left:20px;top:36px;right:60px;text-align:center">
            <div style="color:#fff;font-weight:800;font-size:24px;text-shadow:0 2px 6px rgba(0,0,0,.4)">{{ money(sel.amount) }}</div>
          </div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.status || '—' }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.owner_name || '—' }}</h2>
          <div class="c-sub" style="margin-top:4px;font-size:12.5px">{{ monthLabel(sel.month) }} · {{ fmtTs(sel.ts) }}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin:12px 0">
            <span class="badge b-blue">💱 {{ sel.amount_fx ? sel.amount_fx.toLocaleString('en-US') : '—' }} {{ sel.currency || '' }}</span>
            <span v-if="sel.rate" class="badge b-gray">{{ t('Rate') }} {{ sel.rate }}</span>
            <button v-if="propRef(sel)" class="btn-ghost" style="padding:4px 10px;font-size:12px" @click="go(propRef(sel).path, propRef(sel).query)">↗ {{ propName(sel.prop) }}</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px 18px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Amount (BDT)') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ money(sel.amount) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('FX amount') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.amount_fx ? sel.amount_fx.toLocaleString('en-US') : '—' }} {{ sel.currency || '' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Rate') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.rate || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Method') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.method || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Bank ref') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.ref || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Form C') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.form_c || '—' }}</div>
            </div>
            <div v-if="sel.confirmed_at" style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Confirmed') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtTs(sel.confirmed_at) }}</div>
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
