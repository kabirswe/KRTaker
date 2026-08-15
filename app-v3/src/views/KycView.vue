<script setup>
// Tenant KYC (V2.10.0) — Know Your Customer verification for payment compliance.
// Staff: review queue (pending first) with document preview + approve/reject.
// Tenant: submit/update own KYC (required for SSLCommerz card payments).
import { computed, ref, onMounted } from 'vue'
import { t } from '../lib/i18n'
import { useAuthStore } from '../stores/auth'
import { apiCall, apiUpload, apiBlob } from '../api/client'
import { fmtTs } from '../lib/ui'
import CompactFilters from '../components/CompactFilters.vue'

const auth = useAuthStore()

const isTenant = computed(() => auth.user?.role === 'tenant')
const canReview = computed(() => !isTenant.value && ['superadmin', 'owner', 'manager', 'svc_mgr', 'accountant'].includes(auth.user?.role || ''))

const STATUS = [
  { v: 'unverified', l: 'Unverified', ico: '🪪', cls: 'b-gray' },
  { v: 'pending', l: 'Pending', ico: '⏳', cls: 'b-orange' },
  { v: 'verified', l: 'Verified', ico: '✅', cls: 'b-green' },
  { v: 'rejected', l: 'Rejected', ico: '❌', cls: 'b-red' },
]
const stMeta = (s) => STATUS.find(x => x.v === s) || { v: s, l: s || '—', ico: '🪪', cls: 'b-gray' }

// ── data ──
const loading = ref(false)
const err = ref('')
const toast = ref('')
const summary = ref(null)          // staff: {unverified,pending,verified,rejected,tenants}
const records = ref([])            // staff: kyc rows joined w/ tenant info
const record = ref(null)           // tenant: own kyc record
const myTid = ref('')
const fStatus = ref('')
const q = ref('')
const sel = ref(null)              // staff review drawer
const reviewNote = ref('')
const reviewBusy = ref(false)

// ── tenant submission form ──
const form = ref({ full_name: '', nid: '', tin: '', dob: '', address: '' })
const frontFile = ref(null)
const backFile = ref(null)
const submitBusy = ref(false)
const docUrl = ref('')             // blob URL cache for tenant's own doc view

const filtered = computed(() => {
  let out = records.value
  if (fStatus.value) out = out.filter(r => r.status === fStatus.value)
  if (q.value.trim()) {
    const s = q.value.trim().toLowerCase()
    out = out.filter(r => (r.tenant_name || '').toLowerCase().includes(s) || (r.nid || '').toLowerCase().includes(s) || (r.tenant_phone || '').toLowerCase().includes(s))
  }
  return out
})

async function load() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-kyc', { action: 'list' })
    if (!r.ok) { err.value = r.error || 'Failed to load KYC.'; return }
    if (isTenant.value) {
      record.value = r.record || null
      myTid.value = r.tenant_id || ''
      if (record.value) {
        form.value = {
          full_name: record.value.full_name || '',
          nid: record.value.nid || '',
          tin: record.value.tin || '',
          dob: (record.value.dob || '').slice(0, 10),
          address: record.value.address || '',
        }
      }
    } else {
      records.value = r.records || []
      summary.value = r.summary || null
    }
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}

function flash(m, ok = true) { toast.value = m; setTimeout(() => toast.value = '', 4000) }

// ── staff: review drawer ──
const selName = computed(() => sel.value?.tenant_name || 'Tenant')
const docBlob = (r, field) => {
  if (!r || !r[field]) return
  apiBlob('app-kyc?action=view&tenant_id=' + encodeURIComponent(r.tenant_id) + '&field=' + field)
    .then(url => { if (url) window.open(url, '_blank'); else flash('⚠ Document not found', false) })
}

async function review(decision) {
  if (!sel.value) return
  reviewBusy.value = true; err.value = ''
  try {
    const r = await apiCall('app-kyc', { action: 'review', tenant_id: sel.value.tenant_id, decision, notes: reviewNote.value })
    if (!r.ok) { err.value = r.error || 'Review failed.'; return }
    flash(decision === 'approve' ? '✅ KYC approved — tenant can now pay by card' : '❌ KYC rejected')
    reviewNote.value = ''
    await load()
  } catch (e) { err.value = e.message }
  finally { reviewBusy.value = false }
}

async function resetKyc(r) {
  if (!r) return
  reviewBusy.value = true; err.value = ''
  try {
    const res = await apiCall('app-kyc', { action: 'remove', tenant_id: r.tenant_id })
    if (!res.ok) { err.value = res.error || 'Reset failed.'; return }
    flash('🪪 KYC reset to unverified')
    await load()
  } catch (e) { err.value = e.message }
  finally { reviewBusy.value = false }
}

// ── tenant: submit / upload ──
async function submitKyc() {
  if (!form.value.full_name.trim() || !form.value.nid.trim()) { flash('⚠ Full name and NID are required', false); return }
  submitBusy.value = true; err.value = ''
  try {
    const r = await apiCall('app-kyc', { action: 'submit', full_name: form.value.full_name, nid: form.value.nid, tin: form.value.tin, dob: form.value.dob, address: form.value.address })
    if (!r.ok) { err.value = r.error || 'Submit failed.'; return }
    flash('⏳ KYC submitted — awaiting review')
    await load()
  } catch (e) { err.value = e.message }
  finally { submitBusy.value = false }
}

async function uploadDoc(field, file) {
  if (!file) return
  const tid = isTenant.value ? myTid.value : (sel.value?.tenant_id || '')
  if (!tid) { flash('⚠ No tenant selected', false); return }
  const fd = new FormData()
  fd.append('action', 'upload')
  fd.append('field', field)
  fd.append('tenant_id', tid)
  fd.append('file', file)
  submitBusy.value = true; err.value = ''
  try {
    const r = await apiUpload('app-kyc', fd)
    if (!r.ok) { err.value = r.error || 'Upload failed.'; return }
    flash('📄 ' + (field === 'doc_front' ? 'Front' : 'Back') + ' document uploaded')
    frontFile.value = null; backFile.value = null
    await load()
  } catch (e) { err.value = e.message }
  finally { submitBusy.value = false }
}

function pickFront(e) { frontFile.value = e.target.files?.[0] || null }
function pickBack(e) { backFile.value = e.target.files?.[0] || null }

const openSel = (r) => { sel.value = r; reviewNote.value = r.notes || '' }
const closeSel = () => { sel.value = null }

onMounted(load)
</script>

<template>
  <div>
    <!-- ══ HEADER ══ -->
    <div class="page-head">
      <div>
        <h1>{{ t('🪪 Tenant KYC') }}</h1>
        <div class="sub" v-if="!isTenant">Know Your Customer — verify tenant identities for payment compliance (required for SSLCommerz card payments)</div>
        <div class="sub" v-else>{{ t('Verify your identity to unlock card payments (NID/TIN + ID document)') }}</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button v-if="canReview" class="btn-ghost" @click="load">{{ t('Refresh') }}</button>
      </div>
    </div>

    <div v-if="err" style="background:var(--danger,#e74c3c)22;border:1px solid var(--danger,#e74c3c)55;color:var(--danger,#e74c3c);padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:14px">⚠ {{ err }}</div>
    <div v-if="toast" style="background:var(--primary)22;border:1px solid var(--primary)55;color:var(--primary);padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:14px">{{ toast }}</div>

    <!-- ══ TENANT MODE: MY KYC ══ -->
    <template v-if="isTenant">
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
        <div class="stat" style="flex:1;min-width:220px">
          <div class="s-label"><span class="s-ico">🪪</span>{{ t('KYC status') }}</div>
          <div class="s-value" style="font-size:20px">
            <span class="badge" :class="stMeta(record?.status || 'unverified').cls" style="font-size:13px;padding:5px 12px">
              {{ stMeta(record?.status || 'unverified').ico }} {{ stMeta(record?.status || 'unverified').l }}
            </span>
          </div>
          <div class="s-trend" v-if="record?.submitted_at">{{ t('Submitted') }} {{ fmtTs(record.submitted_at) }}</div>
          <div class="s-trend" v-else>{{ t('No KYC submitted yet') }}</div>
        </div>
      </div>

      <div v-if="record?.status === 'verified'" style="background:#27ae6022;border:1px solid #27ae6055;color:#27ae60;padding:12px 16px;border-radius:12px;font-size:13.5px;margin-bottom:16px">
        ✅ <b>{{ t('KYC verified') }}</b> — you can now pay invoices by card (SSLCommerz).
      </div>
      <div v-else-if="record?.status === 'pending'" style="background:#f39c1222;border:1px solid #f39c1255;color:#f39c12;padding:12px 16px;border-radius:12px;font-size:13.5px;margin-bottom:16px">
        ⏳ <b>{{ t('Pending review') }}</b> — your KYC is being checked. Card payments unlock once approved. You may still pay by bKash/Nagad/manual methods.
      </div>
      <div v-else-if="record?.status === 'rejected'" style="background:#e74c3c22;border:1px solid #e74c3c55;color:#e74c3c;padding:12px 16px;border-radius:12px;font-size:13.5px;margin-bottom:16px">
        ❌ <b>{{ t('Rejected') }}</b><template v-if="record?.notes"> — {{ record.notes }}</template> {{ t('Please correct the details below and resubmit.') }}
      </div>
      <div v-else style="background:var(--bg-alt);border:1px solid var(--border);padding:12px 16px;border-radius:12px;font-size:13.5px;margin-bottom:16px">
        🪪 Complete your KYC below — <b>required for card (SSLCommerz) payments</b>. Wallets (bKash/Nagad) and manual payments don't need it.
      </div>

      <div class="panel" style="padding:20px;max-width:640px">
        <div style="font-weight:800;font-size:14px;margin-bottom:14px">📋 Identity details</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--text-mute)">{{ t('Full name') }} (as on {{ t('NID') }}) *</label>
            <input v-model="form.full_name" placeholder="e.g. Rahim Uddin" style="width:100%;margin-top:4px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--text-mute)">{{ t('NID') }} number *</label>
            <input v-model="form.nid" placeholder="e.g. 1234567890" style="width:100%;margin-top:4px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--text-mute)">{{ t('TIN (optional)') }}</label>
            <input v-model="form.tin" placeholder="e.g. 987654321" style="width:100%;margin-top:4px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--text-mute)">{{ t('Date of birth') }}</label>
            <input v-model="form.dob" type="date" style="width:100%;margin-top:4px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
          <div style="grid-column:1/-1">
            <label style="font-size:12px;font-weight:700;color:var(--text-mute)">{{ t('Present address') }}</label>
            <input v-model="form.address" :placeholder="t('House, road, area, city')" style="width:100%;margin-top:4px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          </div>
        </div>

        <div style="font-weight:800;font-size:14px;margin:18px 0 10px">🪪 ID documents</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--text-mute)">{{ t('Front side') }}</label>
            <input type="file" accept="image/*,.pdf" @change="pickFront" style="display:block;margin-top:4px;font-size:12px">
            <button class="btn-ghost" :disabled="submitBusy" @click="uploadDoc('doc_front', frontFile)" style="margin-top:6px;font-size:12px">⬆️ Upload front</button>
            <button v-if="record?.doc_front" class="btn-ghost" @click="docBlob(record, 'doc_front')" style="margin-top:6px;font-size:12px">👁 View uploaded</button>
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--text-mute)">{{ t('Back side') }}</label>
            <input type="file" accept="image/*,.pdf" @change="pickBack" style="display:block;margin-top:4px;font-size:12px">
            <button class="btn-ghost" :disabled="submitBusy" @click="uploadDoc('doc_back', backFile)" style="margin-top:6px;font-size:12px">⬆️ Upload back</button>
            <button v-if="record?.doc_back" class="btn-ghost" @click="docBlob(record, 'doc_back')" style="margin-top:6px;font-size:12px">👁 View uploaded</button>
          </div>
        </div>

        <button class="btn-primary" :disabled="submitBusy" @click="submitKyc" style="margin-top:18px;padding:10px 22px">
          {{ submitBusy ? 'Saving…' : (record ? '🔄 Update KYC' : '📤 Submit KYC') }}
        </button>
      </div>
    </template>

    <!-- ══ STAFF MODE: REVIEW QUEUE ══ -->
    <template v-else>
      <!-- KPI chips -->
      <div class="stats" v-if="summary">
        <div class="stat"><div class="s-label"><span class="s-ico">👥</span>{{ t('Tenants') }}</div><div class="s-value">{{ summary.tenants }}</div><div class="s-trend">total</div></div>
        <div class="stat" @click="fStatus=''"><div class="s-label"><span class="s-ico">🪪</span>{{ t('Unverified') }}</div><div class="s-value" style="cursor:pointer">{{ summary.unverified }}</div><div class="s-trend">no KYC yet</div></div>
        <div class="stat" @click="fStatus='pending'"><div class="s-label"><span class="s-ico">⏳</span>{{ t('Pending') }}</div><div class="s-value" style="cursor:pointer;color:var(--warning,#f39c12)">{{ summary.pending }}</div><div class="s-trend">awaiting review</div></div>
        <div class="stat" @click="fStatus='verified'"><div class="s-label"><span class="s-ico">✅</span>{{ t('Verified') }}</div><div class="s-value" style="cursor:pointer;color:#27ae60">{{ summary.verified }}</div><div class="s-trend">card payments OK</div></div>
        <div class="stat" @click="fStatus='rejected'"><div class="s-label"><span class="s-ico">❌</span>{{ t('Rejected') }}</div><div class="s-value" style="cursor:pointer;color:var(--danger,#e74c3c)">{{ summary.rejected }}</div><div class="s-trend">needs resubmit</div></div>
      </div>

      <!-- Filter bar -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:14px 0">
        <CompactFilters>
        <button v-for="[k, l] in [['', 'All'], ['unverified', '🪪 Unverified'], ['pending', '⏳ Pending'], ['verified', '✅ Verified'], ['rejected', '❌ Rejected']]" :key="k" @click="fStatus = k"
          :style="fStatus === k ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'"
          style="padding:7px 13px;border:none;border-radius:9px;font-weight:800;font-size:12.5px;cursor:pointer">{{ l }}</button>
        <input v-model="q" placeholder="🔍 Search tenant / NID / phone…" style="flex:1;min-width:200px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
        </CompactFilters>
      </div>

      <div v-if="loading" style="text-align:center;padding:40px;color:var(--text-mute)">Loading…</div>
      <div v-else-if="!filtered.length" style="text-align:center;padding:40px;color:var(--text-mute)">{{ t('No KYC records match.') }}</div>
      <div v-else class="panel" style="overflow:auto">
        <table class="kr" style="width:100%;border-collapse:collapse;font-size:13px">
          <thead>
            <tr style="text-align:left;color:var(--text-mute);font-size:11.5px;text-transform:uppercase;letter-spacing:.3px">
              <th style="padding:10px 12px">{{ t('Tenant') }}</th>
              <th style="padding:10px 12px">{{ t('NID') }}</th>
              <th style="padding:10px 12px">{{ t('Submitted') }}</th>
              <th style="padding:10px 12px">{{ t('Status') }}</th>
              <th style="padding:10px 12px">{{ t('Reviewed by') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in filtered" :key="r.tenant_id" @click="openSel(r)" style="cursor:pointer;border-top:1px solid var(--border)" :class="r.status === 'pending' ? '' : ''">
              <td style="padding:10px 12px">
                <div style="font-weight:700">{{ r.tenant_name || r.tenant_id }}</div>
                <div class="c-sub" style="font-size:11.5px">{{ r.tenant_phone || '—' }}<template v-if="r.tenant_kind"> · {{ r.tenant_kind }}</template></div>
              </td>
              <td style="padding:10px 12px;font-family:monospace;font-size:12px">{{ r.nid || '—' }}</td>
              <td style="padding:10px 12px;white-space:nowrap">{{ r.submitted_at ? fmtTs(r.submitted_at) : '—' }}</td>
              <td style="padding:10px 12px"><span class="badge" :class="stMeta(r.status).cls">{{ stMeta(r.status).ico }} {{ stMeta(r.status).l }}</span></td>
              <td style="padding:10px 12px">{{ r.reviewed_by || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Review drawer -->
      <div v-if="sel" style="position:fixed;inset:0;background:#000a;z-index:90;display:flex;justify-content:flex-end" @click.self="closeSel">
        <div style="width:min(520px,100%);height:100%;background:var(--bg,#fff);overflow-y:auto;padding:22px" :style="auth.user?.theme ? {} : {}">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <div>
              <div style="font-weight:800;font-size:17px">🪪 {{ selName }}</div>
              <div class="c-sub" style="font-size:12px">{{ sel.tenant_phone || '' }}<template v-if="sel.tenant_email"> · {{ sel.tenant_email }}</template></div>
            </div>
            <button class="btn-ghost" @click="closeSel" style="font-size:12px">✕ Close</button>
          </div>

          <div style="display:flex;gap:8px;align-items:center;margin-bottom:14px">
            <span class="badge" :class="stMeta(sel.status).cls" style="font-size:12.5px">{{ stMeta(sel.status).ico }} {{ stMeta(sel.status).l }}</span>
            <span v-if="sel.reviewed_by" class="c-sub" style="font-size:11.5px">by {{ sel.reviewed_by }} · {{ sel.reviewed_at ? fmtTs(sel.reviewed_at) : '' }}</span>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;font-size:13px;margin-bottom:14px">
            <div><div class="c-sub" style="font-size:11px;text-transform:uppercase;letter-spacing:.3px">{{ t('Full name') }}</div><b>{{ sel.full_name || '—' }}</b></div>
            <div><div class="c-sub" style="font-size:11px;text-transform:uppercase;letter-spacing:.3px">{{ t('NID') }}</div><b style="font-family:monospace">{{ sel.nid || '—' }}</b></div>
            <div><div class="c-sub" style="font-size:11px;text-transform:uppercase;letter-spacing:.3px">{{ t('TIN') }}</div><b style="font-family:monospace">{{ sel.tin || '—' }}</b></div>
            <div><div class="c-sub" style="font-size:11px;text-transform:uppercase;letter-spacing:.3px">{{ t('Date of birth') }}</div><b>{{ sel.dob || '—' }}</b></div>
            <div style="grid-column:1/-1"><div class="c-sub" style="font-size:11px;text-transform:uppercase;letter-spacing:.3px">{{ t('Address') }}</div><b>{{ sel.address || '—' }}</b></div>
          </div>

          <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute);margin-bottom:8px">🪪 ID documents</div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
            <button class="btn-ghost" @click="docBlob(sel, 'doc_front')" style="font-size:12.5px" :disabled="!sel.doc_front">📄 Front {{ sel.doc_front ? '' : '(none)' }}</button>
            <button class="btn-ghost" @click="docBlob(sel, 'doc_back')" style="font-size:12.5px" :disabled="!sel.doc_back">📄 Back {{ sel.doc_back ? '' : '(none)' }}</button>
            <button v-if="sel.doc_front || sel.doc_back" class="btn-ghost" @click="resetKyc(sel)" style="font-size:12.5px;color:var(--danger,#e74c3c)" :disabled="reviewBusy">🗑 Reset to unverified</button>
          </div>

          <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute);margin-bottom:6px">{{ t('Review note (shown to tenant)') }}</div>
          <textarea v-model="reviewNote" rows="3" :placeholder="t('Optional note — e.g. reason for rejection / confirmation details')"
            style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;resize:vertical;margin-bottom:14px"></textarea>

          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button class="btn-primary" style="flex:1;padding:10px 14px" :disabled="reviewBusy" @click="review('approve')">✅ Approve &amp; verify</button>
            <button style="flex:1;padding:10px 14px;border:none;border-radius:10px;background:#e74c3c;color:#fff;font-weight:800;font-size:13px;cursor:pointer" :disabled="reviewBusy" @click="review('reject')">❌ Reject</button>
          </div>
          <div v-if="sel.reviewed_by" style="font-size:11.5px;color:var(--text-mute);margin-top:10px">💡 Re-approving or rejecting replaces the previous decision.</div>
        </div>
      </div>
    </template>
  </div>
</template>
