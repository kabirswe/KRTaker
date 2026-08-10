<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { badge, useViewMode, usePager } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('vendors')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const partnersAll = computed(() => data.list('partners'))
const payoutsAll = computed(() => data.list('vendor_payouts'))

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const avatarColor = (s) => { let h = 0; for (const c of String(s || '')) h = (h * 31 + c.charCodeAt(0)) % 360; return `hsl(${h},62%,45%)` }
const initials = (n) => String(n || '?').split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase()
const payoutsOf = (p) => payoutsAll.value.filter(x => x.partner === p.id).sort((a, b) => String(b.month).localeCompare(String(a.month)))
function monthLabel(m) { if (!m) return '—'; const [y, mo] = m.split('-'); const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']; return (names[parseInt(mo, 10) - 1] || mo) + ' ' + y }

// ── KPIs ──
const kpis = computed(() => {
  const ps = partnersAll.value
  const active = ps.filter(p => String(p.status).toLowerCase() === 'active')
  const totJobs = ps.reduce((s, p) => s + (p.jobs || 0), 0)
  const avgRate = ps.length ? (ps.reduce((s, p) => s + (p.rating || 0), 0) / ps.length).toFixed(1) : '—'
  const totPaid = payoutsAll.value.reduce((s, x) => s + (x.amount || 0), 0)
  const withEmail = ps.filter(p => p.sub_email || p.email).length
  return [
    { label: 'Vendors', ico: '🛠️', value: ps.length, trend: active.length + ' active' },
    { label: 'Avg rating', ico: '⭐', value: avgRate, trend: 'of 5.0' },
    { label: 'Total jobs', ico: '🔧', value: totJobs, trend: 'completed work orders' },
    { label: 'Payouts', ico: '💰', value: money(totPaid), trend: 'paid to vendors' },
    { label: 'Portal access', ico: '📧', value: withEmail, trend: 'with sub_email' },
    { label: 'Active', ico: '✅', value: active.length, trend: active.length === ps.length ? 'all active' : 'some inactive', ok: active.length === ps.length },
  ]
})

// ── filters / sort ──
const query = ref('')
const tradeFilter = ref('')
const statusFilter = ref('')
const sortBy = ref('jobs')
const tradeOptions = computed(() => [...new Set(partnersAll.value.map(p => p.trade).filter(Boolean))].sort())
const statusOptions = computed(() => [...new Set(partnersAll.value.map(p => p.status).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = partnersAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(p => p.id.toLowerCase().includes(q) || p.name.toLowerCase().includes(q) || (p.trade || '').toLowerCase().includes(q) || (p.city || '').toLowerCase().includes(q) || (p.specialties || '').toLowerCase().includes(q))
  if (tradeFilter.value) out = out.filter(p => p.trade === tradeFilter.value)
  if (statusFilter.value) out = out.filter(p => p.status === statusFilter.value)
  const get = (p) => sortBy.value === 'rating' ? (p.rating || 0) : sortBy.value === 'name' ? (p.name || '') : (p.jobs || 0)
  return [...out].sort((a, b) => typeof get(a) === 'string' ? String(get(a)).localeCompare(String(get(b))) : get(b) - get(a))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)
function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s }
  const lines = [['id', 'name', 'trade', 'rating', 'jobs', 'status', 'phone', 'email', 'city', 'hourly_rate'].map(esc).join(',')]
  rows.forEach(p => lines.push([p.id, p.name, p.trade, p.rating, p.jobs, p.status, p.phone, p.sub_email || p.email, p.city, p.hourly_rate].map(esc).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'vendors.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(p) { sel.value = p }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const p = partnersAll.value.find(x => x.id === id); if (p) openDetail(p) }
}, { immediate: true })
const selPayouts = computed(() => sel.value ? payoutsOf(sel.value) : [])
const selPaid = computed(() => selPayouts.value.reduce((s, x) => s + (x.amount || 0), 0))
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🛠️ Vendors</h1>
        <div class="sub">{{ partnersAll.length }} vendors · {{ money(kpis[3]?.value || 0) }} paid out · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search vendor, trade, city…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="tradeFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All trades</option>
          <option v-for="t in tradeOptions" :key="t" :value="t">{{ t }}</option>
        </select>
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All statuses</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="sortBy" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="jobs">Sort: Jobs</option>
          <option value="rating">Sort: Rating</option>
          <option value="name">Sort: Name</option>
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

    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px">
      <div v-for="p in paged" :key="p.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(p)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:800;color:#fff;text-shadow:0 2px 6px rgba(0,0,0,.35)">{{ initials(p.name) }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="badge(p.status)">{{ p.status }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ p.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div>
            <div style="font-weight:800;font-size:15px;letter-spacing:-.2px">{{ p.name }}</div>
            <div class="c-sub" style="margin-top:2px">🔧 {{ p.trade || '—' }}<template v-if="p.city"> · 📍 {{ p.city }}</template></div>
          </div>
          <div style="display:flex;gap:13px;font-size:12px;flex-wrap:wrap">
            <span class="c-sub" title="Rating">⭐ {{ p.rating || 0 }}/5</span>
            <span class="c-sub" title="Jobs">🔧 {{ p.jobs || 0 }} jobs</span>
            <span class="c-sub" title="Hourly rate">{{ p.hourly_rate ? money(Number(p.hourly_rate)) + '/hr' : '' }}</span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
            <span class="badge b-blue">💰 {{ money(payoutsOf(p).reduce((s, x) => s + (x.amount || 0), 0)) }}</span>
            <span v-if="p.sub_email || p.email" class="badge b-green">📧 portal</span>
          </div>
        </div>
      </div>
    </div>
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>Vendor</th><th>Trade</th><th>Rating</th><th>Jobs</th><th>Hourly</th><th>Paid out</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="p in paged" :key="p.id" style="cursor:pointer" @click="openDetail(p)">
              <td style="white-space:nowrap"><b>{{ p.id }}</b> · {{ p.name }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ p.trade || '—' }}</td>
              <td style="white-space:nowrap">⭐ {{ p.rating || 0 }}</td>
              <td style="white-space:nowrap">{{ p.jobs || 0 }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ p.hourly_rate ? money(Number(p.hourly_rate)) + '/hr' : '—' }}</td>
              <td style="white-space:nowrap">{{ money(payoutsOf(p).reduce((s, x) => s + (x.amount || 0), 0)) }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="badge(p.status)">{{ p.status }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No vendors found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(620px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px;font-weight:800;color:#fff;text-shadow:0 2px 8px rgba(0,0,0,.35)">{{ initials(sel.name) }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="badge(sel.status)" style="background:#ffffff">{{ sel.status }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ sel.name }}</h2>
          <div class="c-sub" style="margin-top:3px">🔧 {{ sel.trade || '—' }}<template v-if="sel.city"> · 📍 {{ sel.city }}</template><template v-if="sel.address"> · {{ sel.address }}</template></div>

          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:10px;margin:16px 0">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Rating</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">⭐ {{ sel.rating || 0 }}/5</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Jobs</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">🔧 {{ sel.jobs || 0 }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Hourly rate</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px">{{ sel.hourly_rate ? money(Number(sel.hourly_rate)) + '/hr' : '—' }}</div>
            </div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:11px;padding:10px 12px">
              <div style="font-size:10.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Paid out</div>
              <div style="font-size:14.5px;font-weight:800;margin-top:2px;color:var(--ok)">{{ money(selPaid) }}</div>
            </div>
          </div>

          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">📞 Contact</div>
            <div style="font-size:12.5px;line-height:1.7">
              <div v-if="sel.phone">📱 {{ sel.phone }}</div>
              <div v-if="sel.sub_email || sel.email">📧 {{ sel.sub_email || sel.email }}<span v-if="sel.sub_email" class="c-sub"> (portal login)</span></div>
              <div v-if="!sel.phone && !sel.sub_email && !sel.email" class="c-sub">No contact details on file.</div>
            </div>
          </div>

          <div v-if="sel.specialties" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">🧰 Specialties</div>
            <div style="font-size:12.5px">{{ sel.specialties }}</div>
          </div>

          <div v-if="sel.notes" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">📝 Notes</div>
            <div style="font-size:12.5px">{{ sel.notes }}</div>
          </div>

          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">💰 Payout history</div>
            <div class="drawer-tbl-wrap">
              <table class="kr" style="width:100%">
                <thead><tr><th>ID</th><th>Month</th><th>Method</th><th>Ref</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                  <tr v-for="x in selPayouts" :key="x.id">
                    <td style="font-weight:700">{{ x.id }}</td>
                    <td>{{ monthLabel(x.month) }}</td>
                    <td>{{ x.method || '—' }}</td>
                    <td>{{ x.ref || '—' }}</td>
                    <td>{{ money(x.amount) }}</td>
                    <td><span class="badge" :class="badge(x.status)">{{ x.status }}</span></td>
                  </tr>
                  <tr v-if="!selPayouts.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:16px">No payouts recorded for this vendor.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* White badges only on cover/header areas (same convention as Properties/Units) */
.d-cover .badge { background: #ffffff; }
</style>
