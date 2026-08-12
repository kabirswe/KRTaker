<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useViewMode, usePager, money, fmtTs, avatarColor, initials } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('referrals')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const refAll = computed(() => data.list('referrals'))

const stCls = (s) => s === 'Paid' ? 'b-green' : (s === 'Signed up' ? 'b-blue' : (s === 'Pending' ? 'b-orange' : 'b-gray'))

// ── KPIs ──
const kpis = computed(() => {
  const rs = refAll.value
  const reward = rs.reduce((s, r) => s + (r.reward || 0), 0)
  const paid = rs.filter(r => r.status === 'Paid')
  const paidAmt = paid.reduce((s, r) => s + (r.reward || 0), 0)
  const signed = rs.filter(r => r.status === 'Signed up').length
  const months = new Set(rs.map(r => String(r.ts || '').slice(0, 7)).filter(Boolean)).size
  return [
    { label: 'Referrals', ico: '🤝', value: rs.length, trend: 'refer-a-tenant programme' },
    { label: 'Total reward', ico: '💰', value: money(reward), trend: 'committed rewards' },
    { label: 'Paid out', ico: '💵', value: paidAmt ? money(paidAmt) : '—', trend: paid.length + ' paid', ok: paid.length > 0 },
    { label: 'Signed up', ico: '📝', value: signed, trend: signed ? 'converted' : 'none', ok: signed > 0 },
    { label: 'Pending', ico: '⏳', value: rs.length - paid.length - signed, trend: 'awaiting conversion' },
    { label: 'Avg reward', ico: '📊', value: rs.length ? money(Math.round(reward / rs.length)) : '—', trend: months + ' months active' },
  ]
})

// ── filters ──
const query = ref('')
const statusFilter = ref('')
const statusOptions = computed(() => [...new Set(refAll.value.map(r => r.status).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = refAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(r => JSON.stringify(r).toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(r => (r.status || '') === statusFilter.value)
  return [...out].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'referrals.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(r) { sel.value = r }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const r = refAll.value.find(x => x.id === id); if (r) openDetail(r) }
}, { immediate: true })
function detailFields(row) {
  const skip = new Set(['id', 'code', 'referred_name', 'referred_email', 'referred_phone', 'role', 'status', 'reward', 'ts', 'user_email'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🤝 Referrals</h1>
        <div class="sub">{{ refAll.length }} referrals · {{ kpis[2]?.value || '৳0' }} paid out · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" placeholder="Search name, email, code…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
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
      <div v-for="r in paged" :key="r.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(r)">
        <div style="padding:16px 15px 0;display:flex;align-items:center;gap:12px">
          <div style="width:46px;height:46px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;color:#fff" :style="{ background: avatarColor(r.id) }">{{ initials(r.referred_name) }}</div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ r.referred_name || '—' }}</div>
            <div class="c-sub" style="font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ r.referred_email || '—' }}</div>
          </div>
          <span class="badge" :class="stCls(r.status)">{{ r.status || '—' }}</span>
        </div>
        <div style="padding:12px 15px 14px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span v-if="r.role" class="badge b-gray">{{ r.role }}</span>
            <span v-if="r.code" class="badge b-gray">#{{ r.code }}</span>
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-top:auto">
            <span style="font-weight:800;font-size:17px">{{ money(r.reward) }}</span>
            <span class="c-sub" style="font-size:11.5px">🕓 {{ fmtTs(r.ts) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Referred</th><th>Contact</th><th>Role</th><th>Code</th><th>Reward</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
            <tr v-for="r in paged" :key="r.id" style="cursor:pointer" @click="openDetail(r)">
              <td style="font-weight:700;white-space:nowrap">{{ r.id }}</td>
              <td style="white-space:nowrap">{{ r.referred_name || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ r.referred_email || r.referred_phone || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ r.role || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ r.code || '—' }}</td>
              <td style="white-space:nowrap;font-weight:700">{{ money(r.reward) }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="stCls(r.status)">{{ r.status || '—' }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ fmtTs(r.ts) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No referrals found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(600px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">🤝</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" :class="stCls(sel.status)" style="background:#ffffff">{{ sel.status || '—' }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <div style="display:flex;align-items:center;gap:14px">
            <div style="width:58px;height:58px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px;color:#fff" :style="{ background: avatarColor(sel.id) }">{{ initials(sel.referred_name) }}</div>
            <div>
              <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.referred_name || '—' }}</h2>
              <div class="c-sub" style="margin-top:4px;font-size:12.5px">referred {{ fmtTs(sel.ts) }} · by {{ sel.user_email || '—' }}</div>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px 18px;margin-top:16px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Email</div>
              <div style="font-weight:700;margin-top:1px;word-break:break-word">{{ sel.referred_email || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Phone</div>
              <div style="font-weight:700;margin-top:1px"><a v-if="sel.referred_phone" :href="'tel:' + sel.referred_phone" style="color:var(--primary)">{{ sel.referred_phone }}</a><template v-else>—</template></div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Role</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.role || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Referral code</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.code || '—' }}</div>
            </div>
          </div>
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
            <div>
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Reward</div>
              <div style="font-weight:800;font-size:22px;margin-top:2px">{{ money(sel.reward) }}</div>
            </div>
            <span class="badge" :class="stCls(sel.status)">{{ sel.status || '—' }}</span>
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
