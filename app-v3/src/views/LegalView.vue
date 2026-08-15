<script setup>
import { computed, ref, watch } from 'vue'
import { t } from '../lib/i18n'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { badge, useViewMode, usePager, fmtTs } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import RichEditor from '../components/RichEditor.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('legal')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'legal'].includes(auth.user?.role || ''))
const canVoid = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))

const cases = computed(() => data.list('cases'))
const legalNotices = computed(() => data.list('legal_notices'))
const tenantsAll = computed(() => data.list('tenants'))
const unitsAll = computed(() => data.list('units'))
const leasesAll = computed(() => data.list('leases'))

const NTYPE_META = {
  eviction: { ico: '🚪', label: 'Notice to quit', cls: 'b-red' },
  rent_hike: { ico: '📈', label: 'Rent increase', cls: 'b-orange' },
  termination: { ico: '🛑', label: 'Termination', cls: 'b-gray' },
  tds_alert: { ico: '💰', label: 'TDS advisory', cls: 'b-orange' },
  default: { ico: '📢', label: t('Notice'), cls: 'b-gray' },
}
const ntypeMeta = (t) => NTYPE_META[t] || NTYPE_META.default
const stCls = (s) => ({ Draft: 'b-gray', Served: 'b-blue', Void: 'b-red' }[s] || 'b-gray')
const tenantName = (tid) => tenantsAll.value.find(t => t.id === tid)?.name || tid || '—'
const unitName = (uid) => unitsAll.value.find(u => u.id === uid)?.name || uid || '—'
const leaseRent = (lid) => leasesAll.value.find(l => l.id === lid)?.rent || 0
function fmtDate(d) { if (!d) return '—'; const t = new Date(String(d).slice(0, 10) + 'T00:00:00'); return isNaN(t) ? String(d).slice(0, 10) : t.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) }
const daysUntil = (d) => { if (!d) return null; const t = new Date(String(d).slice(0, 10) + 'T00:00:00'); if (isNaN(t)) return null; return Math.round((t - Date.now()) / 86400000) }
const effNote = (n) => {
  const d = daysUntil(n.effective_date)
  if (d === null) return ''
  if (n.status === 'Void') return ''
  if (d < 0) return 'effective ' + (-d) + t('d ago')
  if (d === 0) return t('effective today')
  return t('effective in ') + d + 'd'
}

// ── KPIs ──
const kpis = computed(() => {
  const ns = legalNotices.value
  const openCases = cases.value.filter(c => !['Closed', 'Resolved', 'Won', 'Lost'].includes(c.status || '')).length
  const draft = ns.filter(n => n.status === 'Draft').length
  const served = ns.filter(n => n.status === 'Served').length
  const voided = ns.filter(n => n.status === 'Void').length
  return [
    { label: t('Cases'), ico: '⚖️', value: cases.value.length, trend: openCases + ' open' },
    { label: t('Notices'), ico: '📢', value: ns.length, trend: 'legal notices issued' },
    { label: t('Draft'), ico: '📝', value: draft, trend: draft ? 'ready to serve' : t('none in draft') },
    { label: t('Served'), ico: '🚚', value: served, trend: 'delivered to tenant' },
    { label: t('Void'), ico: '⛔', value: voided, trend: 'cancelled' },
    { label: t('Effective'), ico: '📅', value: ns.filter(n => n.status === 'Served' && n.effective_date).length, trend: t('with effective dates') },
  ]
})

// ── filters ──
const query = ref('')
const statusFilter = ref('')
const typeFilter = ref('')
const statusOptions = [t('Draft'), t('Served'), t('Void')]
const typeOptions = computed(() => [...new Set(legalNotices.value.map(n => n.ntype).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = legalNotices.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(n => JSON.stringify(n).toLowerCase().includes(q) || tenantName(n.tenant).toLowerCase().includes(q) || (n.reason || '').toLowerCase().includes(q))
  if (statusFilter.value) out = out.filter(n => n.status === statusFilter.value)
  if (typeFilter.value) out = out.filter(n => n.ntype === typeFilter.value)
  return [...out].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

// ── issue notice modal ──
const nModal = ref(false)
const nForm = ref({ ntype: 'eviction', lease: '', reason: '' })
function openNotice() {
  nForm.value = { ntype: 'eviction', lease: leasesAll.value[0]?.id || '', reason: '' }
  nModal.value = true
}
async function submitNotice() {
  const f = nForm.value
  if (!f.lease) { window.__krToast?.('❌ Select a lease'); return }
  const r = await apiCall('app-legal', { action: 'notice-create', ntype: f.ntype, lease: f.lease, reason: f.reason.trim() })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || t('Failed'))); return }
  nModal.value = false
  window.__krToast?.('✅ ' + (r.id || t('Notice')) + t(' issued · effective ') + (r.effective_date || '—'), 'ok')
  await data.bootstrap()
}

// ── actions ──
const sel = ref(null)
function openDetail(n) { sel.value = n }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const n = legalNotices.value.find(x => x.id === id); if (n) openDetail(n) }
}, { immediate: true })
function leaseRef(n) { return n.lease ? { path: '/leases', query: { open: n.lease } } : null }
async function serveNotice(n) {
  if (n.status !== 'Draft') { window.__krToast?.(t('Only drafts can be served')); return }
  const r = await apiCall('app-legal', { action: 'notice-serve', id: n.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || t('Failed'))); return }
  window.__krToast?.('🚚 ' + n.id + ' served', 'ok')
  await data.bootstrap()
  refreshSel()
}
async function voidNotice(n) {
  if (n.status === 'Void') { window.__krToast?.(t('Already voided')); return }
  if (!window.confirm(t('Void legal notice ') + n.id + '?')) return
  const r = await apiCall('app-legal', { action: 'notice-void', id: n.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || t('Failed'))); return }
  window.__krToast?.('⛔ ' + n.id + ' voided', 'ok')
  await data.bootstrap()
  refreshSel()
}
function refreshSel() {
  if (!sel.value) return
  const fresh = legalNotices.value.find(x => x.id === sel.value.id)
  if (fresh) sel.value = fresh
}
function detailFields(row) {
  const skip = new Set(['id', 'ntype', 'lease', 'tenant', 'unit', 'reason', 'body', 'notice_days', 'effective_date', 'status'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('📜 Legal Engine') }}</h1>
        <div class="sub">{{ legalNotices.length }} notices · {{ kpis[0]?.value || 0 }} cases · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" :placeholder="t('Search notice, tenant, reason…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:210px">
        <select v-model="statusFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All statuses') }}</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
        </select>
        <select v-model="typeFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">{{ t('All types') }}</option>
          <option v-for="t in typeOptions" :key="t" :value="t">{{ ntypeMeta(t).label }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
      </CompactFilters>
        <button v-if="canManage" @click="openNotice" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Issue notice</button>
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
      <div v-for="n in paged" :key="n.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(n)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">{{ ntypeMeta(n.ntype).ico }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="stCls(n.status)" style="background:#ffffff">{{ n.status }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ n.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div>
            <div style="font-weight:800;font-size:14.5px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ ntypeMeta(n.ntype).label }}</div>
            <div class="c-sub" style="margin-top:2px;font-size:12px">👤 {{ tenantName(n.tenant) }} · 🚪 {{ unitName(n.unit) }}</div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="ntypeMeta(n.ntype).cls">{{ ntypeMeta(n.ntype).ico }} {{ ntypeMeta(n.ntype).label }}</span>
            <span v-if="n.lease" class="badge b-gray">{{ n.lease }}</span>
            <span v-if="n.notice_days" class="badge b-blue">{{ n.notice_days }}d notice</span>
          </div>
          <div style="display:flex;gap:13px;font-size:11.5px;margin-top:auto" class="c-sub">
            <span>📅 {{ fmtDate(n.effective_date) }}</span>
            <span v-if="effNote(n)">· {{ effNote(n) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>{{ t('ID') }}</th><th>{{ t('Type') }}</th><th>{{ t('Tenant') }}</th><th>{{ t('Lease') }}</th><th>{{ t('Notice days') }}</th><th>{{ t('Effective') }}</th><th>{{ t('Status') }}</th><th v-if="canManage">{{ t('Action') }}</th></tr></thead>
          <tbody>
            <tr v-for="n in paged" :key="n.id" style="cursor:pointer" @click="openDetail(n)">
              <td style="font-weight:700;white-space:nowrap">{{ n.id }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="ntypeMeta(n.ntype).cls">{{ ntypeMeta(n.ntype).ico }} {{ ntypeMeta(n.ntype).label }}</span></td>
              <td style="white-space:nowrap">{{ tenantName(n.tenant) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ n.lease || '—' }} <template v-if="leaseRent(n.lease)">· ৳{{ leaseRent(n.lease) }}</template></td>
              <td style="white-space:nowrap" class="c-sub">{{ n.notice_days || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ fmtDate(n.effective_date) }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="stCls(n.status)">{{ n.status }}</span></td>
              <td v-if="canManage" style="white-space:nowrap">
                <button v-if="n.status === 'Draft'" @click.stop="serveNotice(n)" :title="t('Serve')" style="background:none;border:none;font-size:14px;cursor:pointer">🚚</button>
                <button v-if="canVoid && n.status !== 'Void'" @click.stop="voidNotice(n)" :title="t('Void')" style="background:none;border:none;font-size:14px;cursor:pointer">⛔</button>
              </td>
            </tr>
            <tr v-if="!filtered.length"><td :colspan="canManage ? 8 : 7" style="text-align:center;color:var(--text-mute);padding:30px">{{ t('No notices found.') }}</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No legal notices found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- cases strip -->
    <div v-if="cases.length" style="margin-top:16px">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px">
        <div style="font-weight:800;font-size:14px">⚖️ Cases <span class="c-sub" style="font-weight:500">· {{ cases.filter(c => !['Closed','Resolved','Won','Lost'].includes(c.status || '')).length }} open</span></div>
        <button class="btn-ghost" style="padding:6px 12px;font-size:12px" @click="go('/cases')">All cases →</button>
      </div>
      <div class="panel" style="overflow:hidden">
        <div class="tbl-wrap" style="max-height:280px">
          <table class="kr" style="width:100%">
            <thead><tr><th>ID</th><th>{{ t('Case') }}</th><th>{{ t('Type') }}</th><th>{{ t('Status') }}</th><th>{{ t('Next hearing') }}</th></tr></thead>
            <tbody>
              <tr v-for="c in cases.slice(0, 8)" :key="c.id" style="cursor:pointer" @click="go('/cases', { open: c.id })">
                <td style="font-weight:700;white-space:nowrap">{{ c.id }}</td>
                <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ c.title }}</td>
                <td style="white-space:nowrap" class="c-sub">{{ c.type || '—' }}</td>
                <td style="white-space:nowrap"><span class="badge" :class="badge(c.status)">{{ c.status || '—' }}</span></td>
                <td style="white-space:nowrap" class="c-sub">{{ fmtDate(c.next_hearing) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- issue notice modal -->
    <template v-if="nModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="nModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(480px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">📢 Issue legal notice</div>
          <button @click="nModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 22px;display:flex;flex-direction:column;gap:12px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Notice type') }}</div>
              <select v-model="nForm.ntype" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option value="eviction">🚪 Notice to quit</option>
                <option value="rent_hike">📈 Rent increase</option>
                <option value="termination">🛑 Termination</option>
                <option value="tds_alert">💰 TDS advisory</option>
              </select>
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Lease *</div>
              <select v-model="nForm.lease" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option v-for="l in leasesAll" :key="l.id" :value="l.id">{{ l.id }} · rent {{ l.rent }}</option>
              </select>
            </div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">{{ t('Reason') }}</div>
            <RichEditor v-model="nForm.reason" placeholder="e.g. Rent unpaid for 3 consecutive months" :min-height="'100px'" style="margin-top:5px" />
          </div>
          <div class="c-sub" style="font-size:11.5px;line-height:1.6">The body is auto-generated from the legal config (statutory notice period for the selected type) with tenant/unit/rent details. Served separately.</div>
          <button @click="submitNotice" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">📢 Issue notice (Draft)</button>
        </div>
      </div>
    </template>

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(600px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">{{ ntypeMeta(sel.ntype).ico }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" :class="stCls(sel.status)" style="background:#ffffff">{{ sel.status }}</span>
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ ntypeMeta(sel.ntype).label }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:20px;font-weight:800;letter-spacing:-.3px">{{ ntypeMeta(sel.ntype).label }}</h2>
          <div class="c-sub" style="margin-top:3px">👤 {{ tenantName(sel.tenant) }} · 🚪 {{ unitName(sel.unit) }} · issued {{ fmtTs(sel.ts) }} by {{ sel.created_by || '—' }}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin:12px 0">
            <button v-if="leaseRef(sel)" class="btn-ghost" style="padding:4px 10px;font-size:12px" @click="go(leaseRef(sel).path, leaseRef(sel).query)">↗ Lease {{ sel.lease }}</button>
            <span v-if="sel.notice_days" class="badge b-blue">{{ sel.notice_days }}d notice</span>
            <span v-if="effNote(sel)" class="badge" :class="sel.status === 'Void' ? 'b-gray' : 'b-orange'">{{ effNote(sel) }}</span>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px 18px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Effective date') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtDate(sel.effective_date) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Served') }}</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.served_on ? fmtTs(sel.served_on) : '—' }}<template v-if="sel.served_by"> · {{ sel.served_by }}</template></div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Rent') }}</div>
              <div style="font-weight:700;margin-top:1px">৳{{ (leaseRent(sel.lease) || 0).toLocaleString('en-IN') }}/mo</div>
            </div>
            <div v-if="sel.reason" style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Reason') }}</div>
              <div style="font-weight:700;margin-top:1px" v-html="sel.reason"></div>
            </div>
          </div>
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0">
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:7px">📝 Notice body</div>
            <div style="font-size:13px;line-height:1.65;white-space:pre-wrap">{{ sel.body }}</div>
          </div>
          <div v-for="[k, v] in detailFields(sel)" :key="k" style="font-size:13px;margin-bottom:8px">
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t(k.replace(/_/g, ' ')) }}</div>
            <div style="font-weight:600;word-break:break-word;margin-top:1px">{{ String(v) }}</div>
          </div>
          <div v-if="canManage" style="display:flex;gap:8px;margin-top:16px">
            <button v-if="sel.status === 'Draft'" @click="serveNotice(sel)" style="flex:1;padding:10px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">🚚 Serve notice</button>
            <button v-if="canVoid && sel.status !== 'Void'" @click="voidNotice(sel)" style="flex:1;padding:10px;border:none;border-radius:10px;background:var(--danger);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">⛔ Void notice</button>
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
