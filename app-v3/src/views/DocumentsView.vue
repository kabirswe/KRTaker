<script setup>
import { computed, ref, watch } from 'vue'
import { t } from '../lib/i18n'
import { useRouter, useRoute } from 'vue-router'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import { apiBase, apiUpload, apiBlob } from '../api/client'
import { badge, useViewMode, usePager, fmtSize } from '../lib/ui'
import PagerBar from '../components/PagerBar.vue'
import CompactFilters from '../components/CompactFilters.vue'

const router = useRouter()
const route = useRoute()
const viewMode = useViewMode('documents')
const go = (path, q) => router.push({ path, query: q })

const data = useDataStore()
const auth = useAuthStore()
// Server-side upload roles: superadmin, owner, manager, tenant, legal, crm, accountant, hr, svc_mgr, partner
const canUpload = computed(() => ['superadmin', 'owner', 'manager', 'tenant', 'legal', 'crm', 'accountant', 'hr', 'svc_mgr', 'partner'].includes(auth.user?.role || ''))
const docsAll = computed(() => data.list('documents'))

const CATS = [
  { v: 'agreement', l: 'Agreement & lease' },
  { v: 'utility', l: 'Utility papers' },
  { v: 'legal', l: 'Legal documents' },
  { v: 'tax', l: 'Tax & khajna' },
  { v: 'community', l: 'Community / society' },
  { v: 'other', l: 'Other' },
]
const KINDS = ['lease', 'tenant', 'property', 'ticket', 'other']
const kindLabel = (k) => ({ lease: 'Lease', tenant: 'Tenant', property: 'Property', ticket: 'Ticket', other: 'Other' }[k] || k || '—')

function fileIco(d) {
  const m = (d.mime || '').toLowerCase()
  if (m.includes('pdf')) return '📕'
  if (m.includes('image')) return '🖼️'
  if (m.includes('sheet') || m.includes('excel') || m.includes('csv')) return '📊'
  if (m.includes('word') || m.includes('document') || m.includes('txt')) return '📝'
  return '📦'
}

// ── KPIs ──
const kpis = computed(() => {
  const ds = docsAll.value
  const totalB = ds.reduce((s, d) => s + (d.size || 0), 0)
  const kinds = new Set(ds.map(d => d.kind).filter(Boolean)).size
  const thisM = new Date().toISOString().slice(0, 7)
  const mCount = ds.filter(d => (d.ts || '').startsWith(thisM)).length
  return [
    { label: 'Documents', ico: '📁', value: ds.length, trend: 'files in the vault' },
    { label: 'Storage', ico: '💾', value: fmtSize(totalB), trend: 'total vault size' },
    { label: 'Kinds', ico: '🗂️', value: kinds, trend: 'lease · tenant · property …' },
    { label: 'This month', ico: '📅', value: mCount, trend: 'uploaded in ' + thisM.slice(0, 7) },
    { label: 'Largest', ico: '🐘', value: ds.length ? fmtSize(Math.max(...ds.map(d => d.size || 0))) : '—', trend: ds.length ? (ds.find(d => d.size === Math.max(...ds.map(x => x.size || 0)))?.name || '') : '' },
    { label: 'Vault', ico: '🔒', value: ds.length ? 'Live' : '—', trend: 'synced with server' },
  ]
})

// ── filters / sort ──
const query = ref('')
const kindFilter = ref('')
const catFilter = ref('')
const filtered = computed(() => {
  let out = docsAll.value
  const q = query.value.trim().toLowerCase()
  if (q) out = out.filter(d => JSON.stringify(d).toLowerCase().includes(q))
  if (kindFilter.value) out = out.filter(d => (d.kind || '') === kindFilter.value)
  if (catFilter.value) out = out.filter(d => (d.cat || '') === catFilter.value)
  return [...out].sort((a, b) => String(b.ts || '').localeCompare(String(a.ts || '')))
})
const { paged, page, pageCount, rangeLabel, setPage } = usePager(filtered, 12)

function exportCsv() {
  const rows = filtered.value; if (!rows.length) return
  const esc = (v) => { const s = v === null || v === undefined ? '' : String(v); return /[\",\n]/.test(s) ? '\"' + s.replace(/\"/g, '\"\"') + '\"' : s }
  const lines = [['id', 'name', 'kind', 'ref', 'cat', 'size', 'mime', 'uploaded_by', 'ts', 'p'].map(esc).join(',')]
  rows.forEach(d => lines.push([d.id, d.name, d.kind, d.ref, d.cat, d.size, d.mime, d.uploaded_by, d.ts, d.p].map(esc).join(',')))
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = 'documents.csv'; a.click(); URL.revokeObjectURL(a.href)
}

// ── drawer ──
const sel = ref(null)
function openDetail(d) { sel.value = d }
function closeDetail() { sel.value = null }
watch(() => route.query.open, (id) => {
  if (id) { const d = docsAll.value.find(x => x.id === id); if (d) openDetail(d) }
}, { immediate: true })

// ── view / download ──
const busyDoc = ref('')
async function viewDoc(d) {
  busyDoc.value = d.id
  try {
    const url = await apiBlob('app-doc-view?id=' + encodeURIComponent(d.id))
    if (url) window.open(url, '_blank')
    else window.__krToast?.('Preview unavailable', 'error')
  } finally { busyDoc.value = '' }
}
async function downloadDoc(d) {
  busyDoc.value = d.id
  try {
    const res = await fetch(apiBase() + 'app-doc-download?id=' + encodeURIComponent(d.id), { headers: { Authorization: 'Bearer ' + (auth.token || '') } })
    if (!res.ok) { window.__krToast?.('Download failed (' + res.status + ')', 'error'); return }
    const blob = await res.blob()
    const ext = (d.name || '').includes('.') ? '' : (d.mime ? '.' + d.mime.split('/')[1] : '.bin')
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = d.name || (d.id + ext)
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(a.href)
  } catch (e) { window.__krToast?.('Network error', 'error') } finally { busyDoc.value = '' }
}

// ── ref deep link ──
function refTarget(d) {
  if (!d.ref) return null
  const k = d.kind
  if (k === 'tenant') return { path: '/tenants', query: { open: d.ref } }
  if (k === 'lease') return { path: '/leases', query: { open: d.ref } }
  if (k === 'property') return { path: '/properties', query: { open: d.ref } }
  if (k === 'ticket') return { path: '/maintenance', query: { open: d.ref } }
  return null
}

// ── upload ──
const upModal = ref(false)
const uploading = ref(false)
const upFile = ref(null)
const upKind = ref('lease')
const upRef = ref('')
const upCat = ref('agreement')
function pickFile(e) { upFile.value = e.target.files[0] || null }
async function submitUpload() {
  if (!upFile.value) { window.__krToast?.('Choose a file first', 'error'); return }
  if (!upRef.value.trim()) { window.__krToast?.('Reference is required (e.g. L-007)', 'error'); return }
  uploading.value = true
  try {
    const fd = new FormData()
    fd.append('file', upFile.value)
    fd.append('kind', upKind.value)
    fd.append('ref', upRef.value.trim())
    fd.append('cat', upCat.value)
    const r = await apiUpload('app-doc-upload', fd)
    if (r.ok) { window.__krToast?.(`📁 ${r.id} uploaded`, 'ok'); upModal.value = false; upFile.value = null; upRef.value = ''; await data.bootstrap() }
    else window.__krToast?.(r.error || 'Upload failed', 'error')
  } finally { uploading.value = false }
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('📁 Documents') }}</h1>
        <div class="sub">{{ docsAll.length }} files · {{ kpis[1]?.value || 0 }} · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <input v-model="query" :placeholder="t('Search name, ref, uploader…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:220px">
        <select v-model="kindFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All kinds</option>
          <option v-for="k in KINDS" :key="k" :value="k">{{ kindLabel(k) }}</option>
        </select>
        <select v-model="catFilter" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All categories</option>
          <option v-for="c in CATS" :key="c.v" :value="c.v">{{ c.l }}</option>
        </select>
        <div style="display:flex;border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <button @click="viewMode = 'grid'" :style="viewMode === 'grid' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">▦ Grid</button>
          <button @click="viewMode = 'list'" :style="viewMode === 'list' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text-mute)'" style="padding:8px 12px;border:none;font-size:12.5px;font-weight:800;cursor:pointer">☰ List</button>
        </div>
        <button v-if="filtered.length" @click="exportCsv" class="btn-ghost" title="Download CSV">⬇ CSV</button>
      </CompactFilters>
        <button v-if="canUpload" class="btn-primary" style="padding:9px 14px;font-size:12.5px" @click="upModal = true">＋ Upload</button>
      </div>
    </div>

    <div class="stats">
      <div v-for="k in kpis" :key="k.label" class="stat">
        <div class="s-label"><span class="s-ico">{{ k.ico }}</span>{{ k.label }}</div>
        <div class="s-value">{{ k.value }}</div>
        <div class="s-trend" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ k.trend }}</div>
      </div>
    </div>

    <!-- GRID -->
    <div v-if="filtered.length && viewMode === 'grid'" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px">
      <div v-for="d in paged" :key="d.id" class="panel chip" style="cursor:pointer;overflow:hidden;display:flex;flex-direction:column" @click="openDetail(d)">
        <div style="height:84px;position:relative;background:var(--grad)">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:34px">{{ fileIco(d) }}</div>
          <div style="position:absolute;top:10px;left:12px;display:flex;gap:6px">
            <span class="badge" style="background:#ffffff">{{ kindLabel(d.kind) }}</span>
          </div>
          <div style="position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5)">{{ fmtSize(d.size) }}</div>
        </div>
        <div style="padding:13px 15px;flex:1;display:flex;flex-direction:column;gap:8px">
          <div style="font-weight:800;font-size:14px;letter-spacing:-.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ d.name }}</div>
          <div class="c-sub" style="font-size:12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ d.ref || '—' }} · {{ kindLabel(d.kind) }} · {{ d.mime || '—' }}</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:auto">
            <span v-if="d.cat" class="badge b-blue">{{ d.cat }}</span>
          </div>
          <div style="display:flex;gap:13px;font-size:11.5px" class="c-sub">
            <span>👤 {{ d.uploaded_by || '—' }}</span>
            <span>🕒 {{ (d.ts || '').slice(0, 10) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="filtered.length && viewMode === 'list'" class="panel" style="overflow:hidden">
      <div class="tbl-wrap">
        <table class="kr" style="width:100%">
          <thead><tr><th>{{ t('ID') }}</th><th>{{ t('File') }}</th><th>{{ t('Kind') }}</th><th>{{ t('Ref') }}</th><th>{{ t('Cat') }}</th><th>{{ t('Size') }}</th><th>{{ t('Uploaded') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="d in paged" :key="d.id" style="cursor:pointer" @click="openDetail(d)">
              <td style="font-weight:700;white-space:nowrap">{{ d.id }}</td>
              <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ fileIco(d) }} {{ d.name }}</td>
              <td style="white-space:nowrap"><span class="badge" :class="badge(kindLabel(d.kind))">{{ kindLabel(d.kind) }}</span></td>
              <td style="white-space:nowrap" class="c-sub">{{ d.ref || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ d.cat || '—' }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ fmtSize(d.size) }}</td>
              <td style="white-space:nowrap" class="c-sub">{{ (d.ts || '').slice(0, 10) }}</td>
              <td style="white-space:nowrap">
                <button class="btn-ghost" style="padding:4px 9px;font-size:11px" :disabled="busyDoc === d.id" @click.stop="downloadDoc(d)">⬇</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No documents found{{ query ? ' for “' + query + '”' : '' }}.</div>

    <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel" @set="setPage" />

    <!-- drawer -->
    <template v-if="sel">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:60" @click="closeDetail"></div>
      <div style="position:fixed;top:0;right:0;bottom:0;width:min(580px,94vw);background:var(--card);z-index:61;box-shadow:-18px 0 50px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden">
        <div class="d-cover" style="height:120px;background:var(--grad);position:relative;flex-shrink:0">
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:52px">{{ fileIco(sel) }}</div>
          <button @click="closeDetail" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border-radius:50%;border:none;background:rgba(255,255,255,.25);color:#fff;font-size:15px;font-weight:800;cursor:pointer">✕</button>
          <div style="position:absolute;left:16px;bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
            <span class="badge" style="background:#ffffff">{{ sel.id }}</span>
            <span class="badge" style="background:#ffffff">{{ kindLabel(sel.kind) }}</span>
          </div>
        </div>
        <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
          <h2 style="font-size:19px;font-weight:800;letter-spacing:-.3px;line-height:1.35;word-break:break-word">{{ sel.name }}</h2>
          <div class="c-sub" style="margin-top:5px;font-size:12.5px">👤 {{ sel.uploaded_by || '—' }} · 🕒 {{ (sel.ts || '').slice(0, 16) }}</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin:14px 0">
            <button class="btn-primary" style="padding:9px 16px;font-size:12.5px" :disabled="busyDoc === sel.id" @click="downloadDoc(sel)">⬇ Download</button>
            <button class="btn-ghost" style="padding:9px 16px;font-size:12.5px" :disabled="busyDoc === sel.id" @click="viewDoc(sel)">👁 View</button>
            <button v-if="refTarget(sel)" class="btn-ghost" style="padding:9px 16px;font-size:12.5px" @click="go(refTarget(sel).path, refTarget(sel).query)">↗ {{ kindLabel(sel.kind) }} {{ sel.ref }}</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px 18px;border-top:1px solid var(--border);padding-top:14px">
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Size</div>
              <div style="font-weight:700;margin-top:1px">{{ fmtSize(sel.size) }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Type</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.mime || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Reference</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.ref || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Category</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.cat || '—' }}</div>
            </div>
            <div style="font-size:13px">
              <div style="color:var(--text-mute);font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">Property</div>
              <div style="font-weight:700;margin-top:1px">{{ sel.p || '—' }}</div>
            </div>
          </div>
          <div style="height:24px"></div>
        </div>
      </div>
    </template>

    <!-- upload modal -->
    <template v-if="upModal">
      <div style="position:fixed;inset:0;background:rgba(10,20,40,.45);z-index:70" @click="upModal = false"></div>
      <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:min(500px,94vw);background:var(--card);border-radius:16px;z-index:71;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden">
        <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <h3 style="font-size:15px;font-weight:800">📁 Upload document</h3>
          <button @click="upModal = false" style="border:none;background:none;font-size:16px;cursor:pointer;color:var(--text-mute)">✕</button>
        </div>
        <div style="padding:18px 22px;display:flex;flex-direction:column;gap:13px">
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">File * (max 8 MB)</label>
            <input type="file" @change="pickFile" style="margin-top:6px;font-size:13px;color:var(--text)">
            <div v-if="upFile" class="c-sub" style="font-size:12px;margin-top:5px">{{ upFile.name }} · {{ fmtSize(upFile.size) }}</div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Kind</label>
              <select v-model="upKind" style="width:100%;margin-top:5px;padding:9px 10px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
                <option v-for="k in KINDS" :key="k" :value="k">{{ kindLabel(k) }}</option>
              </select>
            </div>
            <div>
              <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Category</label>
              <select v-model="upCat" style="width:100%;margin-top:5px;padding:9px 10px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
                <option v-for="c in CATS" :key="c.v" :value="c.v">{{ c.l }}</option>
              </select>
            </div>
          </div>
          <div>
            <label style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.3px">Reference *</label>
            <input v-model="upRef" placeholder="e.g. L-007 · T-105 · MT-001 · P-002" style="width:100%;margin-top:5px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <div class="c-sub" style="font-size:11.5px;margin-top:5px">Lease (L-), tenant (T-), maintenance job (MT-), or property (P-) id.</div>
          </div>
        </div>
        <div style="padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
          <button class="btn-ghost" @click="upModal = false">Cancel</button>
          <button class="btn-primary" :disabled="uploading" @click="submitUpload" style="padding:9px 18px">{{ uploading ? 'Uploading…' : '📁 Upload' }}</button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.d-cover .badge { background: #ffffff; }
</style>
