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
const viewMode = useViewMode('compliance')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))
const canDelete = computed(() => ['superadmin', 'owner'].includes(auth.user?.role || ''))
const cplAll = computed(() => data.list('compliance_items'))

const ITEM_META = {
  fire_safety: { ico: '🧯', label: 'Fire safety', cls: 'b-red' },
  trade_license: { ico: '📜', label: 'Trade license', cls: 'b-blue' },
  lease_expiry: { ico: '📅', label: 'Lease expiry', cls: 'b-green' },
  nid: { ico: '🪪', label: 'NID on file', cls: 'b-gray' },
  boiler: { ico: '⚙️', label: 'Boiler / cert', cls: 'b-orange' },
  default: { ico: '📋', label: 'Compliance', cls: 'b-gray' },
}
const itemMeta = (t) => ITEM_META[t] || ITEM_META.default
const daysUntil = (d) => { if (!d) return null; const t = new Date(String(d).slice(0, 10) + 'T00:00:00'); if (isNaN(t)) return null; return Math.round((t - Date.now()) / 86400000) }
const fmtDate = (d) => { if (!d) return '—'; const t = new Date(String(d).slice(0, 10) + 'T00:00:00'); return isNaN(t) ? String(d).slice(0, 10) : t.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) }

// expiry bucket: expired | soon (<=90d) | ok (later or none)
function bucket(c) {
  const n = daysUntil(c.expiry_date)
  if (n === null) return 'none'
  if (n < 0) return 'expired'
  if (n <= 90) return 'soon'
  return 'ok'
}
const expiryBadge = (c) => {
  const b = bucket(c)
  if (b === 'none') return { cls: 'b-gray', text: 'No expiry' }
  const n = daysUntil(c.expiry_date)
  if (b === 'expired') return { cls: 'b-red', text: '⏰ Expired ' + fmtDate(c.expiry_date) }
  if (b === 'soon') return { cls: 'b-orange', text: '⚠️ ' + n + 'd left · ' + fmtDate(c.expiry_date) }
  return { cls: 'b-green', text: '✅ ' + fmtDate(c.expiry_date) }
}

// entity name + deep link
function entityRef(c) {
  const e = c.entity_type, id = c.entity_id
  if (e === 'property') return { name: data.list('properties').find(p => p.id === id)?.name || id, path: '/properties' }
  if (e === 'lease') return { name: data.list('leases').find(l => l.id === id)?.id || id, path: '/leases' }
  if (e === 'tenant') return { name: data.list('tenants').find(t => t.id === id)?.name || id, path: '/tenants' }
  return { name: id || '', path: '' }
}

// ── KPIs ──
const kpis = computed(() => {
  const cs = cplAll.value
  const expired = cs.filter(c => bucket(c) === 'expired').length
  const soon = cs.filter(c => bucket(c) === 'soon').length
  const withExp = cs.filter(c => daysUntil(c.expiry_date) !== null).length
  const types = new Set(cs.map(c => c.item).filter(Boolean)).size
  const ok = cs.length - expired - soon
  return [
    { label: 'Items', ico: '📋', value: cs.length, trend: 'compliance records' },
    { label: 'Expired', ico: '⏰', value: expired, trend: expired ? 'needs immediate action' : 'none expired', ok: expired === 0 },
    { label: 'Expiring ≤90d', ico: '⚠️', value: soon, trend: soon ? 'renewals due soon' : 'nothing due', ok: soon === 0 },
    { label: 'Healthy', ico: '✅', value: ok, trend: 'ok or no expiry' },
    { label: 'With expiry', ico: '📅', value: withExp, trend: withExp + ' tracked against the calendar' },
    { label: 'Types', ico: '🗂️', value: types, trend: 'licenses · safety · leases · NID' },
  ]
})

// ── filters / sort ──
const query = ref('')
const typeFilter = ref('')
const bucketFilter = ref('')
const typeOptions = computed(() => [...new Set(cplAll.value.map(c => c.item).filter(Boolean))].sort())
const filtered = computed(() => {
  let out = cplAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(c => JSON.stringify(c).toLowerCase().includes(q))
  if (typeFilter.value) out = out.filter(c => (c.item || '') === typeFilter.value)
  if (bucketFilter.value) out = out.filter(c => bucket(c) === bucketFilter.value)
  return [...out].sort((a, b) => {
    const an = daysUntil(a.expiry_date), bn = daysUntil(b.expiry_date)
    return (an === null ? 1e9 : an) - (bn === null ? 1e9 : bn)
  })
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[\",\n]/.test(s) ? '\"' + s.replace(/\"/g, '\"\"') + '\"' : s }
  const cols = [...new Set(rows.flatMap(r => Object.keys(r)))]
  const lines = [cols.map(esc).join(',')]
  rows.forEach(r => lines.push(cols.map(c => esc(r[c])).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'compliance.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(c) { sel.value = c }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const c = cplAll.value.find(x => x.id === id); if (c) openDetail(c) }
}, { immediate: true })
function detailFields(row) {
  const skip = new Set(['id', 'label', 'item', 'entity_type', 'entity_id', 'status', 'expiry_date'])
  return Object.entries(row).filter(([k, v]) => !skip.has(k) && v !== null && v !== undefined && v !== '')
}

// ── writes ──
const addModal = ref(false)
const addForm = ref({ label: '', entity_type: 'property', entity_id: '', item: 'trade_license', ref_no: '', issue_date: '', expiry_date: '', notes: '' })
function openAdd() {
  addForm.value = { label: '', entity_type: 'property', entity_id: data.list('properties')[0]?.id || '', item: 'trade_license', ref_no: '', issue_date: new Date().toISOString().slice(0, 10), expiry_date: '', notes: '' }
  addModal.value = true
}
function entityOptions() {
  const t = addForm.value.entity_type
  if (t === 'property') return data.list('properties').map(p => ({ id: p.id, label: p.name }))
  if (t === 'lease') return data.list('leases').map(l => ({ id: l.id, label: l.id + ' · ' + l.rent }))
  if (t === 'tenant') return data.list('tenants').map(x => ({ id: x.id, label: x.name }))
  return []
}
async function submitAdd() {
  const f = addForm.value
  if (!f.label.trim()) { window.__krToast?.('❌ Label is required'); return }
  if (f.entity_type !== 'property' && !f.entity_id) { window.__krToast?.('❌ Select an entity'); return }
  const r = await apiCall('app-compliance', { action: 'create', label: f.label.trim(), entity_type: f.entity_type, entity_id: f.entity_id, item: f.item, ref_no: f.ref_no.trim(), issue_date: f.issue_date, expiry_date: f.expiry_date, notes: f.notes.trim() })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  addModal.value = false
  window.__krToast?.('✅ ' + (r.id || 'Item') + ' added', 'ok')
  await data.bootstrap()
}
const editForm = ref({ expiry_date: '', ref_no: '', notes: '', status: 'active' })
function openEdit(c) {
  editForm.value = { expiry_date: c.expiry_date || '', ref_no: c.ref_no || '', notes: c.notes || '', status: c.status || 'active' }
}
async function saveEdit() {
  const f = editForm.value
  const r = await apiCall('app-compliance', { action: 'update', id: sel.value.id, expiry_date: f.expiry_date, ref_no: f.ref_no.trim(), notes: f.notes.trim(), status: f.status })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('✅ ' + sel.value.id + ' updated', 'ok')
  await data.bootstrap()
  refreshSel()
}
async function delItem(c) {
  if (!window.confirm('Delete compliance item ' + c.id + ' (' + c.label + ')?')) return
  const r = await apiCall('app-compliance', { action: 'delete', id: c.id })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('🗑 Deleted')
  closeDetail()
  await data.bootstrap()
}
async function runRemind() {
  if (!window.confirm('Email a compliance digest for all due/expired items to your account?')) return
  const r = await apiCall('app-compliance', { action: 'remind' })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('📧 ' + (r.sent || 0) + ' of ' + (r.due || 0) + ' due items emailed', 'ok')
  await data.bootstrap()
}
async function runSync() {
  const r = await apiCall('app-compliance', { action: 'sync' })
  if (r && r.ok === false) { window.__krToast?.('❌ ' + (r.error || 'Failed')); return }
  window.__krToast?.('🔄 Compliance resynced (lease expiries recomputed)', 'ok')
  await data.bootstrap()
}
function refreshSel() {
  if (!sel.value) return
  const fresh = cplAll.value.find(x => x.id === sel.value.id)
  if (fresh) sel.value = fresh
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>⚖️ Compliance</h1>
        <div class="sub">{{ cplAll.length }} items · {{ kpis[1]?.value || 0 }} expired · {{ kpis[2]?.value || 0 }} expiring soon</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input v-model="query" placeholder="Search label, ref, notes…" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="typeFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All types</option>
          <option v-for="t in typeOptions" :key="t" :value="t">{{ itemMeta(t).label }}</option>
        </select>
        <select v-model="bucketFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All expiry states</option>
          <option value="expired">⏰ Expired</option>
          <option value="soon">⚠️ Expiring ≤ 90 days</option>
          <option value="ok">✅ Ok / later</option>
          <option value="none">⚪ No expiry date</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
        <template v-if="canManage">
          <button @click="runRemind" class="btn-ghost" title="Email due/expired digest">📧 Remind</button>
          <button @click="runSync" class="btn-ghost" title="Recompute from leases">🔄 Sync</button>
          <button @click="openAdd" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">＋ Add item</button>
        </template>
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
      <div v-for="c in paged" :key="c.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(c)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:32px">{{ itemMeta(c.item).ico }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" :class="itemMeta(c.item).cls" style="background:#ffffff">{{ itemMeta(c.item).label }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ c.id }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:9px">
          <div style="font-weight:800;font-size:14px;letter-spacing:-.2px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ c.label }}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <span v-if="entityRef(c).name" class="badge b-blue">{{ entityRef(c).name }}</span>
            <span v-if="c.ref_no" class="badge b-gray">{{ c.ref_no }}</span>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
            <span class="badge" :class="expiryBadge(c).cls">{{ expiryBadge(c).text }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>ID</th><th>Item</th><th>Entity</th><th>Ref</th><th>Status</th><th>Expiry</th></tr></thead>
          <tbody>
            <tr v-for="c in paged" :key="c.id" style="cursor:pointer" @click="openDetail(c)">
              <td style="font-weight:700;white-space:nowrap">{{ c.id }}</td>
              <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ itemMeta(c.item).ico }} {{ c.label }}</td>
              <td style="white-space:nowrap"><span class="badge b-blue">{{ entityRef(c).name || '—' }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ c.ref_no || '—' }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="badge(c.status)">{{ c.status || '—' }}</span></td>
              <td style="white-space:nowrap"><span class="badge" :class="expiryBadge(c).cls">{{ expiryBadge(c).text }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No compliance items found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- add modal -->
    <template v-if="addModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="addModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(500px,94vw);background:var(--card);z-index:71;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden">
        <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div style="font-weight:800;font-size:15.5px">📋 Add compliance item</div>
          <button @click="addModal = false" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--bg-alt);color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer">✕</button>
        </div>
        <div style="padding:18px 20px 22px;display:flex;flex-direction:column;gap:12px">
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Label *</div>
            <input v-model="addForm.label" placeholder="e.g. Trade license 2026-27" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Type</div>
              <select v-model="addForm.item" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option v-for="(m, k) in ITEM_META" :key="k" :value="k">{{ m.ico }} {{ m.label }}</option>
              </select>
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Entity type</div>
              <select v-model="addForm.entity_type" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
                <option value="property">🏢 Property</option>
                <option value="lease">📄 Lease</option>
                <option value="tenant">👤 Tenant</option>
              </select>
            </div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Entity</div>
            <select v-model="addForm.entity_id" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-size:13px;font-family:inherit;outline:none">
              <option v-if="addForm.entity_type === 'property'" v-for="p in data.list('properties')" :key="p.id" :value="p.id">{{ p.id }} · {{ p.name }}</option>
              <option v-if="addForm.entity_type === 'lease'" v-for="l in data.list('leases')" :key="l.id" :value="l.id">{{ l.id }} · rent {{ l.rent }}</option>
              <option v-if="addForm.entity_type === 'tenant'" v-for="t in data.list('tenants')" :key="t.id" :value="t.id">{{ t.name }}</option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Ref no.</div>
              <input v-model="addForm.ref_no" placeholder="e.g. TL-2026-114" style="width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
            <div>
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Issue date</div>
              <input v-model="addForm.issue_date" type="date" style="width:100%;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            </div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Expiry date</div>
            <input v-model="addForm.expiry_date" type="date" style="width:100%;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;outline:none">
            <div class="c-sub" style="font-size:11px;margin-top:4px">Leave blank if it never expires.</div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px">Notes</div>
            <RichEditor v-model="addForm.notes" placeholder="Optional" :min-height="'100px'" style="margin-top:5px" />
          </div>
          <button @click="submitAdd" style="padding:11px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:13.5px;font-weight:800;cursor:pointer">＋ Add item</button>
        </div>
      </div>
    </template>

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(560px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:46px">{{ itemMeta(sel.item).ico }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ itemMeta(sel.item).label }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:19px;font-weight:800;letter-spacing:-.3px;line-height:1.35">{{ sel.label }}</h2>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin:12px 0">
            <span class="badge" :class="expiryBadge(sel).cls">{{ expiryBadge(sel).text }}</span>
            <span class="badge" :class="badge(sel.status)">{{ sel.status || '—' }}</span>
            <button v-if="entityRef(sel).path" class="btn-ghost" style="padding:4px 10px;font-size:12px" @click="go(entityRef(sel).path, { open: sel.entity_id })">↗ {{ sel.entity_type }} {{ sel.entity_id }}</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px 18px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Issue date</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtDate(sel.issue_date) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Expiry date</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtDate(sel.expiry_date) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Ref no.</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.ref_no || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Last reminded</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.last_reminded || '—' }}</div>
            </div>
          </div>
          <div v-if="sel.notes" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin:14px 0;font-size:13px;line-height:1.65" v-html="sel.notes"></div>
          <div v-for="[k, v] in detailFields(sel)" :key="k" style="font-size:13px;margin-bottom:8px">
            <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ k.replace(/_/g, ' ') }}</div>
            <div style="font-weight:600;word-break:break-word;margin-top:1px">{{ String(v) }}</div>
          </div>
          <template v-if="canManage">
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:13px 16px;margin-bottom:14px">
              <div style="font-size:11px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px">✏️ Update item</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                <div>
                  <div class="c-sub" style="font-size:10.5px;margin-bottom:3px">Expiry date</div>
                  <input v-model="editForm.expiry_date" type="date" style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none">
                </div>
                <div>
                  <div class="c-sub" style="font-size:10.5px;margin-bottom:3px">Status</div>
                  <select v-model="editForm.status" style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-size:12.5px;font-family:inherit;outline:none">
                    <option value="active">Active</option>
                    <option value="expired">Expired</option>
                    <option value="completed">Completed</option>
                  </select>
                </div>
              </div>
              <input v-model="editForm.ref_no" placeholder="Ref no." style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font-family:inherit;font-size:12.5px;outline:none;margin-top:8px">
              <RichEditor v-model="editForm.notes" placeholder="Notes" :min-height="'100px'" style="margin-top:8px" />
              <div style="display:flex;gap:8px;margin-top:10px">
                <button @click="saveEdit" style="flex:1;padding:9px;border:none;border-radius:9px;background:var(--primary);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">💾 Save changes</button>
                <button v-if="canDelete" @click="delItem(sel)" style="padding:9px 14px;border:none;border-radius:9px;background:var(--danger);color:#fff;font-size:12.5px;font-weight:800;cursor:pointer">🗑 Delete</button>
              </div>
            </div>
          </template>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.d-cover .badge { background: #ffffff; }
</style>
