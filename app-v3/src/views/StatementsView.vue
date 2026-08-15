<script setup>
// 💰 Statements + ⚙️ Rent Config (V2.11.0 redesign)
// Finance hub tab — monthly owner statements (per-property P&L, line items,
// payouts) + per-property rent configuration. Pure frontend redesign; the
// API contract (app-statements / app-rent-config-*) is unchanged.
import { ref, computed, onMounted } from 'vue'
import { t } from '../lib/i18n'
import { apiCall, getBranding, brandUrl, brandSlotSize, brandTitleOn } from '../api/client'
import { useDataStore } from '../stores/data'
import { useAuthStore } from '../stores/auth'
import ScrollTabs from '../components/ScrollTabs.vue'
import CompactFilters from '../components/CompactFilters.vue'

const data = useDataStore()
const auth = useAuthStore()
const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')

const tab = ref('statements')

// ── month navigator ──
const now = new Date()
const month = ref(now.toISOString().slice(0, 7))
const monthLabel = computed(() => {
  const [y, mo] = month.value.split('-')
  return (['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][parseInt(mo, 10) - 1] || mo) + ' ' + y
})
function shiftMonth(d) {
  const [y, mo] = month.value.split('-').map(Number)
  const dt = new Date(y, mo - 1 + d, 1)
  month.value = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0')
  jumped.value = false
  loadList()
}
const isCurrentMonth = computed(() => month.value === now.toISOString().slice(0, 7))

const loading = ref(false)
const err = ref('')
const toast = ref('')
const jumped = ref(false)   // true when we auto-landed on the latest month with data
const MAX_BACK = 12
const netColor = (n) => (n || 0) >= 0 ? '#12a150' : 'var(--danger)'
const netStyle = (n) => 'color:' + netColor(n)

// ── statements ──
const list = ref([])          // [{prop,name,type,gross,collected,tds,service,expenses,net,payout}]
async function loadList() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-statements', { action: 'list', month: month.value })
    if (!r.ok) { err.value = r.error || 'Failed to load statements.'; return }
    list.value = r.statements || []
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}
const totals = computed(() => {
  const t = { gross: 0, collected: 0, tds: 0, service: 0, expenses: 0, net: 0 }
  list.value.forEach(s => { t.gross += s.gross || 0; t.collected += s.collected || 0; t.tds += s.tds || 0; t.service += s.service || 0; t.expenses += s.expenses || 0; t.net += s.net || 0 })
  return t
})
const collectRate = computed(() => totals.value.gross > 0 ? Math.round((totals.value.collected / totals.value.gross) * 100) : 0)
const barPct = (s) => (s.gross > 0 ? Math.min(100, Math.round(((s.collected || 0) / s.gross) * 100)) : 0)
// Walk to the nearest month that has statements — back first (typical case:
// current month empty → latest past month), then forward (old empty month).
async function openLatestWithData() {
  const [y, mo] = month.value.split('-').map(Number)
  const probe = async (off) => {
    const dt = new Date(y, mo - 1 + off, 1)
    const m = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0')
    const r = await apiCall('app-statements', { action: 'list', month: m })
    return r.ok && (r.statements || []).length ? { m, s: r.statements } : null
  }
  for (let i = 1; i <= MAX_BACK; i++) {
    const hit = await probe(-i)
    if (hit) { month.value = hit.m; list.value = hit.s; jumped.value = true; return }
  }
  for (let i = 1; i <= MAX_BACK; i++) {
    const hit = await probe(i)
    if (hit) { month.value = hit.m; list.value = hit.s; jumped.value = true; return }
  }
}
const shortName = (s) => (s.name || s.prop || 'P').split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase()
const propTint = (s) => {
  const tints = ['#3b82f6', '#8b5cf6', '#0ea5e9', '#f59e0b', '#10b981', '#ef4444', '#ec4899', '#14b8a6']
  let h = 0; for (const c of (s.prop || 'P')) h = (h * 31 + c.charCodeAt(0)) >>> 0
  return tints[h % tints.length]
}

// ── detail drawer ──
const sel = ref(null)         // statement detail
const detail = ref(null)      // { lines[], expense_items[], payout, ... }
const busy = ref(false)
async function openDetail(s) {
  sel.value = s; detail.value = null; err.value = ''
  busy.value = true
  try {
    const r = await apiCall('app-statements', { action: 'detail', prop: s.prop, month: month.value })
    if (!r.ok) { err.value = r.error || 'Failed to load detail.'; return }
    detail.value = r
  } catch (e) { err.value = e.message }
  finally { busy.value = false }
}
const dTot = (f) => (detail.value?.lines || []).reduce((a, l) => a + (l[f] || 0), 0)

// ── payout modal ──
const pay = ref(null)
const payOpen = ref(false)
function openPayout(s) {
  pay.value = {
    prop: s.prop, name: s.name, month: month.value,
    amount: s.net || 0, status: s.payout?.status || 'Scheduled',
    method: s.payout?.method || 'Bank', ref: s.payout?.ref || '',
  }
  payOpen.value = true
}
async function savePayout() {
  if (!pay.value || pay.value.amount == null) return
  if (!confirm(`Record ${pay.value.status} payout of ${money(pay.value.amount)} for ${pay.value.name} (${pay.value.month})?`)) return
  err.value = ''; busy.value = true
  try {
    const r = await apiCall('app-statements', {
      action: 'payout', prop: pay.value.prop, month: pay.value.month,
      amount: Math.round(+pay.value.amount || 0), status: pay.value.status,
      method: pay.value.method, ref: pay.value.ref,
    })
    if (!r.ok) { err.value = r.error || 'Payout failed.'; return }
    payOpen.value = false
    toast.value = `✅ Payout recorded for ${pay.value.name}`
    setTimeout(() => toast.value = '', 4000)
    await loadList()
    await data.bootstrap()
    if (sel.value) await openDetail(sel.value)
  } catch (e) { err.value = e.message }
  finally { busy.value = false }
}

// local payout ledger (from bootstrap)
const payouts = computed(() => (data.list('statement_payouts') || [])
  .filter(p => !month.value || (p.month || '').startsWith(month.value.slice(0, 7)))
  .sort((a, b) => String(b.month).localeCompare(String(a.month))))
const paidCount = computed(() => payouts.value.filter(p => p.status === 'Paid').length)
const payoutTotal = computed(() => payouts.value.reduce((a, p) => a + (p.amount || 0), 0))

// ── print document ──
const printOpen = ref(false)
const printIncludePayouts = ref(true)
const printSignatures = ref(true)
const printBrand = ref({})
const ph = computed(() => {
  const b = printBrand.value
  return {
    img: b.logo_print ? brandUrl(b.logo_print) : '',
    h: brandSlotSize(b, 'print', 40),
    showTitle: brandTitleOn(b, 'print'),
    name: b.site_name || 'KRTaker',
  }
})
const genDate = computed(() => new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }))
function openPrint() { printOpen.value = true }
function closePrint() { printOpen.value = false }
function doPrint() {
  document.body.classList.add('print-statements')
  window.print()
  document.body.classList.remove('print-statements')
}

// ── rent config ──
const rentCfg = ref([])        // [{prop, property, config, mix, units}]
const rentLoading = ref(false)
const rentSaving = ref(false)
const editProp = ref(null)
const editForm = ref({})
const canEditRent = computed(() => ['superadmin', 'owner', 'manager'].includes(auth.user?.role || ''))

async function loadRentConfig() {
  rentLoading.value = true; err.value = ''
  try {
    const r = await apiCall('app-rent-config-get')
    if (!r.ok) { err.value = r.error || 'Failed to load rent config.'; return }
    rentCfg.value = r.rent_configs || []
  } catch (e) { err.value = e.message }
  finally { rentLoading.value = false }
}
function openEdit(p) {
  editProp.value = p.prop
  editForm.value = {
    service_charge_pct: p.config.service_charge_pct || 0,
    utility_advance: p.config.utility_advance || 0,
    parking_fee: p.config.parking_fee || 0,
    escalation_pct: p.config.escalation_pct || 0,
    advance_months: p.config.advance_months || 0,
    due_day: p.config.due_day || 5,
    late_fee_pct: p.config.late_fee_pct || 0,
    rent_per_sqft: p.config.rent_per_sqft || 0,
    notes: p.config.notes || '',
  }
}
async function saveRentConfig() {
  if (!editProp.value) return
  if (!confirm(`Save rent configuration for this property?`)) return
  rentSaving.value = true; err.value = ''
  try {
    const r = await apiCall('app-rent-config-save', { prop: editProp.value, config: editForm.value })
    if (!r.ok) { err.value = r.error || 'Failed to save rent config.'; return }
    editProp.value = null
    toast.value = '✅ Rent config saved'
    setTimeout(() => toast.value = '', 4000)
    await loadRentConfig()
  } catch (e) { err.value = e.message }
  finally { rentSaving.value = false }
}
const mixBar = (p) => {
  const base = p.mix?.base || 0, svc = p.mix?.service_charge || 0, pk = p.mix?.parking || 0
  const tot = base + svc + pk || 1
  return {
    base: Math.round((base / tot) * 100),
    svc: Math.round((svc / tot) * 100),
    pk: Math.max(0, 100 - Math.round((base / tot) * 100) - Math.round((svc / tot) * 100)),
  }
}
const rcTotals = computed(() => ({
  base: rentCfg.value.reduce((a, p) => a + (p.mix?.base || 0), 0),
  service: rentCfg.value.reduce((a, p) => a + (p.mix?.service_charge || 0), 0),
  parking: rentCfg.value.reduce((a, p) => a + (p.mix?.parking || 0), 0),
  total: rentCfg.value.reduce((a, p) => a + (p.mix?.total || 0), 0),
}))
const avgEsc = computed(() => {
  const n = rentCfg.value.filter(p => p.config?.escalation_pct).length
  return n ? Math.round((rentCfg.value.reduce((a, p) => a + (+(p.config?.escalation_pct) || 0), 0) / n) * 10) / 10 : 0
})

// ── 📧 Statement Emails (V2.21) ──
// Monthly owner statement emails — config + dry-run preview + send + history.
// API: app-statement-email (config|save|preview|run). Same two-step send
// pattern as rent reminders; no native confirm().
const emailLoading = ref(false)
const emailErr = ref('')
const emailCfg = ref({ enabled: false, day: 5, owner_name: '', bcc: '' })
const emailLastRun = ref('')
const emailHistory = ref([])
const emailPlan = ref([])
const emailTotals = ref({ gross: 0, collected: 0, net: 0, emailable: 0, no_email: 0, already: 0 })
const emailSaving = ref(false)
const emailRunning = ref(false)
const confirmSendEmails = ref(false)
const canEmail = computed(() => ['superadmin', 'owner', 'manager', 'accountant'].includes(auth.user?.role || ''))
async function loadEmailCfg() {
  emailLoading.value = true; emailErr.value = ''
  try {
    const r = await apiCall('app-statement-email', { action: 'config' })
    if (!r.ok) { emailErr.value = r.error || 'Failed to load statement email config.'; return }
    emailCfg.value = { enabled: !!r.config?.enabled, day: r.config?.day || 5, owner_name: r.config?.owner_name || '', bcc: r.config?.bcc || '' }
    emailLastRun.value = r.last_run || ''
    emailHistory.value = r.history || []
  } catch (e) { emailErr.value = e.message }
  finally { emailLoading.value = false }
}
async function saveEmailCfg() {
  emailSaving.value = true; emailErr.value = ''
  try {
    const r = await apiCall('app-statement-email', { action: 'save', config: emailCfg.value })
    if (!r.ok) { emailErr.value = r.error || 'Failed to save.'; return }
    emailCfg.value = { enabled: !!r.config?.enabled, day: r.config?.day || 5, owner_name: r.config?.owner_name || '', bcc: r.config?.bcc || '' }
    toast.value = '✅ Statement email config saved'
    setTimeout(() => toast.value = '', 4000)
    confirmSendEmails.value = false
  } catch (e) { emailErr.value = e.message }
  finally { emailSaving.value = false }
}
async function previewEmails() {
  emailRunning.value = true; emailErr.value = ''
  try {
    const r = await apiCall('app-statement-email', { action: 'preview', month: month.value })
    if (!r.ok) { emailErr.value = r.error || 'Preview failed.'; return }
    emailPlan.value = r.plan || []
    emailTotals.value = r.totals || {}
  } catch (e) { emailErr.value = e.message }
  finally { emailRunning.value = false }
}
async function sendEmails() {
  emailRunning.value = true; emailErr.value = ''
  try {
    const r = await apiCall('app-statement-email', { action: 'run', month: month.value, send: 1 })
    if (!r.ok) { emailErr.value = r.error || 'Send failed.'; return }
    confirmSendEmails.value = false
    emailPlan.value = r.plan || []
    emailTotals.value = r.totals || {}
    emailLastRun.value = r.last_run || ''
    toast.value = `📧 Statement emails queued: ${r.queued ?? 0} sent · ${r.no_email ?? 0} no email · ${r.suppressed ?? 0} suppressed · ${r.skipped ?? 0} already sent`
    setTimeout(() => toast.value = '', 6000)
    await loadEmailCfg()
  } catch (e) { emailErr.value = e.message }
  finally { emailRunning.value = false }
}
const emailPlanRows = computed(() => emailPlan.value.filter(p => !p.already))
const emailAlreadyRows = computed(() => emailPlan.value.filter(p => p.already))

onMounted(async () => {
  await loadList()
  // Open on a month with no data? Auto-jump to the latest one that has statements.
  if (!list.value.length) await openLatestWithData()
  printBrand.value = await getBranding()
})
</script>

<template>
  <div>
    <!-- ══ HEADER ══ -->
    <div class="page-head">
      <div>
        <h1>{{ t('💰 Statements & Rent Config') }}</h1>
        <div class="sub">{{ t('Monthly owner statements — per-property P&L, line items, payouts & rent settings') }}</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <CompactFilters>
        <div style="display:flex;align-items:center;gap:6px;background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;padding:4px">
          <button class="btn-ghost" style="padding:5px 10px;font-size:12.5px" @click="shiftMonth(-1)">◀</button>
          <input v-model="month" type="month" style="padding:7px 10px;border:none;background:transparent;font-family:inherit;font-size:13px;font-weight:700;color:var(--text);outline:none" @change="loadList">
          <button class="btn-ghost" style="padding:5px 10px;font-size:12.5px" @click="shiftMonth(1)">▶</button>
        </div>
        <button v-if="!isCurrentMonth" class="btn-ghost" style="font-size:12.5px" @click="month = now.toISOString().slice(0, 7); loadList()">{{ t('Today') }}</button>
        <button class="btn-ghost" @click="loadList">{{ t('Refresh') }}</button>
        <button v-if="tab === 'statements'" class="btn-ghost" style="font-weight:700" @click="openPrint">🖨️ Print</button>
      </CompactFilters>
      </div>
    </div>

    <div v-if="toast" style="padding:10px 14px;border-radius:10px;background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.35);margin-bottom:14px;font-weight:700;font-size:13.5px">{{ toast }}</div>
    <div v-if="err" style="padding:10px 14px;border-radius:10px;background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.35);margin-bottom:14px;font-weight:600;font-size:13.5px">⚠️ {{ err }}</div>
    <div v-if="jumped" style="display:flex;align-items:center;gap:10px;padding:9px 14px;border-radius:10px;background:rgba(47,128,237,.08);border:1px solid rgba(47,128,237,.28);margin-bottom:14px;font-size:12.5px;font-weight:600;color:var(--text)">
      <span>↩ {{ monthLabel }} has no statements yet — showing the latest month with data instead.</span>
      <button class="btn-ghost" style="margin-left:auto;padding:4px 10px;font-size:11.5px;flex-shrink:0" @click="month = now.toISOString().slice(0, 7); jumped = false; loadList()">{{ t('Go to current month') }}</button>
    </div>

    <!-- Tabs -->
    <ScrollTabs>
      <button @click="tab = 'statements'" :style="tab === 'statements' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'">💰 Statements</button>
      <button @click="tab = 'rentconfig'; loadRentConfig()" :style="tab === 'rentconfig' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'">⚙️ Rent Config</button>
      <button @click="tab = 'email'; loadEmailCfg()" :style="tab === 'email' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'">📧 Statement Emails</button>
    </ScrollTabs>

    <!-- ══ STATEMENTS TAB ══ -->
    <template v-if="tab === 'statements'">
      <!-- Hero summary -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:14px">
        <div class="stat" style="border-left:4px solid #3b82f6">
          <div class="s-label"><span class="s-ico">🧾</span>{{ t('Gross rent') }}</div>
          <div class="s-value">{{ money(totals.gross) }}</div>
          <div class="s-trend">{{ monthLabel }}</div>
        </div>
        <div class="stat" style="border-left:4px solid #10b981">
          <div class="s-label"><span class="s-ico">💰</span>{{ t('Collected') }}</div>
          <div class="s-value">{{ money(totals.collected) }}</div>
          <div class="s-trend" style="display:flex;align-items:center;gap:8px;margin-top:4px">
            <div style="flex:1;height:6px;background:var(--bg-alt);border-radius:99px;overflow:hidden">
              <div :style="{ width: collectRate + '%', height: '100%', background: 'linear-gradient(90deg,#10b981,#34d399)', borderRadius: '99px' }"></div>
            </div>
            <b>{{ collectRate }}%</b>
          </div>
        </div>
        <div class="stat" style="border-left:4px solid #f59e0b">
          <div class="s-label"><span class="s-ico">📉</span>{{ t('TDS + Service') }}</div>
          <div class="s-value">{{ money(totals.tds + totals.service) }}</div>
          <div class="s-trend">deductions</div>
        </div>
        <div class="stat" style="border-left:4px solid #ef4444">
          <div class="s-label"><span class="s-ico">🛠️</span>{{ t('Expenses') }}</div>
          <div class="s-value">{{ money(totals.expenses) }}</div>
          <div class="s-trend">owner-charged</div>
        </div>
        <div class="stat" style="border-left:4px solid #8b5cf6">
          <div class="s-label"><span class="s-ico">🎯</span>{{ t('Net payout') }}</div>
          <div class="s-value" :style="netStyle(totals.net)">{{ money(totals.net) }}</div>
          <div class="s-trend">{{ list.length }} propert{{ list.length === 1 ? 'y' : 'ies' }}</div>
        </div>
      </div>

      <!-- Statements table -->
      <div class="panel">
        <div class="panel-h" style="flex-wrap:wrap;row-gap:6px">
          <div class="t"><span class="pi">🏢</span>{{ t('Statements') }} <span style="display:inline-flex;align-items:center;gap:5px;background:var(--bg-alt);border:1px solid var(--border);border-radius:99px;padding:3px 11px;font-size:11.5px;font-weight:800;color:var(--text);letter-spacing:.2px;white-space:nowrap;flex-shrink:0">📅 {{ monthLabel }}</span></div>
          <span class="badge" :class="list.length ? 'b-blue' : 'b-gray'" style="font-size:11px;flex-shrink:0;white-space:nowrap;margin-left:auto">{{ list.length ? list.length + ' propert' + (list.length === 1 ? 'y' : 'ies') + ' · ' + money(totals.gross) + ' gross' : 'No statements' }}</span>
        </div>
        <div v-if="loading" style="padding:36px;text-align:center;color:var(--text-mute)">Loading…</div>
        <div v-else-if="!list.length" style="padding:42px 20px;text-align:center">
          <div style="font-size:34px;margin-bottom:10px">🗓️</div>
          <div style="font-weight:800;font-size:14.5px;margin-bottom:4px">No statements for {{ monthLabel }}</div>
          <div style="color:var(--text-mute);font-size:12.5px;margin-bottom:16px">{{ t('No rent invoices were generated for this period.') }}</div>
          <button class="btn-ghost" style="font-size:12px" @click="openLatestWithData()">↩ Jump to nearest month with data</button>
        </div>
        <div v-else class="tbl-wrap">
          <table class="kr">
            <thead><tr><th>{{ t('Property') }}</th><th>{{ t('Gross') }}</th><th>{{ t('Collected') }}</th><th>{{ t('TDS') }}</th><th>{{ t('Service') }}</th><th>{{ t('Expenses') }}</th><th>{{ t('Net') }}</th><th>{{ t('Payout') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="s in list" :key="s.prop">
                <td>
                  <div style="display:flex;align-items:center;gap:10px">
                    <div style="width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff;flex-shrink:0" :style="{ background: propTint(s) }">{{ shortName(s) }}</div>
                    <div>
                      <div class="c-name">{{ s.name }}</div>
                      <div class="c-sub" style="font-size:11px">{{ s.type }}</div>
                    </div>
                  </div>
                </td>
                <td style="font-weight:700">{{ money(s.gross) }}</td>
                <td>
                  <div style="font-weight:700">{{ money(s.collected) }}</div>
                  <div style="width:72px;height:5px;background:var(--bg-alt);border-radius:99px;overflow:hidden;margin-top:3px">
                    <div :style="{ width: barPct(s) + '%', height: '100%', background: '#10b981', borderRadius: '99px' }"></div>
                  </div>
                </td>
                <td>{{ money(s.tds) }}</td>
                <td>{{ money(s.service) }}</td>
                <td>{{ money(s.expenses) }}</td>
                <td style="font-weight:800" :style="netStyle(s.net)">{{ money(s.net) }}</td>
                <td>
                  <span v-if="s.payout" class="badge" :class="s.payout.status === 'Paid' ? 'b-green' : 'b-orange'" style="font-size:11px">{{ s.payout.status }} {{ money(s.payout.amount) }}</span>
                  <span v-else class="badge b-gray" style="font-size:11px">—</span>
                </td>
                <td style="white-space:nowrap">
                  <div style="display:flex;gap:6px;align-items:center">
                    <button class="btn-ghost" style="padding:4px 10px;font-size:11.5px" @click="openDetail(s)">👁 Detail</button>
                    <button v-if="['superadmin','owner','accountant'].includes(auth.user?.role || '')" class="btn-ghost" style="padding:4px 10px;font-size:11.5px" @click="openPayout(s)">💸 Payout</button>
                  </div>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr style="background:var(--bg-alt);font-weight:800;border-top:2px solid var(--border)">
                <td style="padding:10px 13px">{{ t('Total') }}</td>
                <td>{{ money(totals.gross) }}</td>
                <td>{{ money(totals.collected) }}</td>
                <td>{{ money(totals.tds) }}</td>
                <td>{{ money(totals.service) }}</td>
                <td>{{ money(totals.expenses) }}</td>
                <td :style="netStyle(totals.net)">{{ money(totals.net) }}</td>
                <td colspan="2"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Payout ledger -->
      <div class="panel" style="margin-top:14px">
        <div class="panel-h" style="flex-wrap:wrap;row-gap:6px">
          <div class="t"><span class="pi">💸</span>{{ t('Payout ledger') }} <span style="display:inline-flex;align-items:center;gap:5px;background:var(--bg-alt);border:1px solid var(--border);border-radius:99px;padding:3px 11px;font-size:11.5px;font-weight:800;color:var(--text);letter-spacing:.2px;white-space:nowrap;flex-shrink:0">📅 {{ monthLabel }}</span></div>
          <span class="badge" :class="payouts.length ? (paidCount === payouts.length ? 'b-green' : 'b-orange') : 'b-gray'" style="font-size:11px;flex-shrink:0;white-space:nowrap;margin-left:auto">{{ payouts.length ? paidCount + '/' + payouts.length + ' paid · ' + money(payoutTotal) : 'No payouts yet' }}</span>
        </div>
        <div class="tbl-wrap">
          <table class="kr">
            <thead><tr><th>{{ t('Property') }}</th><th>{{ t('Month') }}</th><th>{{ t('Amount') }}</th><th>{{ t('Status') }}</th><th>{{ t('Method') }}</th><th>{{ t('Ref') }}</th></tr></thead>
            <tbody>
              <tr v-for="p in payouts" :key="p.prop + p.month">
                <td><span class="c-name">{{ p.prop }}</span></td>
                <td>{{ p.month }}</td>
                <td style="font-weight:700">{{ money(p.amount) }}</td>
                <td><span class="badge" :class="p.status === 'Paid' ? 'b-green' : 'b-orange'" style="font-size:11px">{{ p.status }}</span></td>
                <td>{{ p.method || '—' }}</td>
                <td style="font-family:monospace;font-size:12px">{{ p.ref || '—' }}</td>
              </tr>
              <tr v-if="!payouts.length"><td colspan="6" style="padding:26px 12px;text-align:center;color:var(--text-mute);font-size:12.5px">💸 No payouts recorded for {{ monthLabel }} yet.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- detail drawer -->
      <div v-if="sel" class="overlay" @click.self="sel = null">
        <div class="drawer">
          <div class="modal-h">
            <span class="t" style="display:flex;align-items:center;gap:10px">
              <span style="width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;color:#fff" :style="{ background: propTint(sel) }">{{ shortName(sel) }}</span>
              {{ detail?.name || sel.name }} · {{ monthLabel }}
            </span>
            <button class="close" @click="sel = null">✕</button>
          </div>
          <div v-if="busy && !detail" style="padding:30px;text-align:center;color:var(--text-mute)">Loading…</div>
          <template v-else-if="detail">
            <div style="padding:18px 20px 0;overflow-y:auto;flex:1">
              <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
                <div style="background:var(--bg-alt);border-radius:12px;padding:12px 14px">
                  <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute)">{{ t('Gross') }}</div>
                  <div style="font-weight:800;font-size:16px;margin-top:2px">{{ money(detail.gross) }}</div>
                </div>
                <div style="background:var(--bg-alt);border-radius:12px;padding:12px 14px">
                  <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute)">{{ t('Collected') }}</div>
                  <div style="font-weight:800;font-size:16px;margin-top:2px;color:#10b981">{{ money(detail.collected) }}</div>
                </div>
                <div style="background:var(--bg-alt);border-radius:12px;padding:12px 14px">
                  <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute)">{{ t('Net') }}</div>
                  <div style="font-weight:800;font-size:16px;margin-top:2px" :style="netStyle(detail.net)">{{ money(detail.net) }}</div>
                </div>
                <div style="background:var(--bg-alt);border-radius:12px;padding:12px 14px">
                  <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute)">{{ t('TDS') }}</div>
                  <div style="font-weight:700;font-size:14px;margin-top:2px">{{ money(detail.tds) }}</div>
                </div>
                <div style="background:var(--bg-alt);border-radius:12px;padding:12px 14px">
                  <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute)">{{ t('Service') }}</div>
                  <div style="font-weight:700;font-size:14px;margin-top:2px">{{ money(detail.service) }}</div>
                </div>
                <div style="background:var(--bg-alt);border-radius:12px;padding:12px 14px">
                  <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute)">{{ t('Expenses') }}</div>
                  <div style="font-weight:700;font-size:14px;margin-top:2px;color:var(--danger)">{{ money(detail.expenses) }}</div>
                </div>
              </div>

              <div style="display:flex;align-items:center;gap:8px;margin:18px 0 8px">
                <span style="font-weight:800;font-size:13px">📄 Line items</span>
                <span class="badge b-gray" style="font-size:10.5px">{{ detail.lines?.length || 0 }}</span>
              </div>
              <div class="tbl-wrap">
                <table class="kr">
                  <thead><tr><th>{{ t('Invoice') }}</th><th>{{ t('Unit') }}</th><th>{{ t('Tenant') }}</th><th>{{ t('Gross') }}</th><th>{{ t('Collected') }}</th><th>{{ t('TDS') }}</th><th>{{ t('Service') }}</th><th>{{ t('Net') }}</th><th>{{ t('Status') }}</th></tr></thead>
                  <tbody>
                    <tr v-for="l in detail.lines || []" :key="l.inv">
                      <td><span class="c-name">{{ l.inv }}</span></td>
                      <td>{{ l.unit_name }}</td>
                      <td>{{ l.tenant_name }}</td>
                      <td>{{ money(l.gross) }}</td>
                      <td>{{ money(l.collected) }}</td>
                      <td>{{ money(l.tds) }}</td>
                      <td>{{ money(l.service) }}</td>
                      <td style="font-weight:700">{{ money(l.net) }}</td>
                      <td><span class="badge" :class="l.inv_status === 'Paid' ? 'b-green' : 'b-orange'" style="font-size:11px">{{ l.inv_status }}</span></td>
                    </tr>
                    <tr v-if="!(detail.lines || []).length"><td colspan="9" class="m">{{ t('No invoices this month.') }}</td></tr>
                  </tbody>
                  <tfoot v-if="(detail.lines || []).length">
                    <tr style="background:var(--bg-alt);font-weight:700;border-top:2px solid var(--border)">
                      <td colspan="3" style="padding:8px 13px">{{ t('Total') }}</td>
                      <td>{{ money(dTot('gross')) }}</td>
                      <td>{{ money(dTot('collected')) }}</td>
                      <td>{{ money(dTot('tds')) }}</td>
                      <td>{{ money(dTot('service')) }}</td>
                      <td>{{ money(dTot('net')) }}</td>
                      <td></td>
                    </tr>
                  </tfoot>
                </table>
              </div>

              <div style="display:flex;align-items:center;gap:8px;margin:18px 0 8px">
                <span style="font-weight:800;font-size:13px">🛠️ Expenses</span>
                <span class="badge b-gray" style="font-size:10.5px">{{ detail.expense_items?.length || 0 }}</span>
              </div>
              <div class="tbl-wrap">
                <table class="kr">
                  <thead><tr><th>{{ t('Ticket') }}</th><th>{{ t('Title') }}</th><th>{{ t('Category') }}</th><th>{{ t('Amount') }}</th></tr></thead>
                  <tbody>
                    <tr v-for="e in detail.expense_items || []" :key="e.id">
                      <td><span class="c-name">{{ e.id }}</span></td>
                      <td>{{ e.title }}</td>
                      <td>{{ e.category || '—' }}</td>
                      <td style="font-weight:700">{{ money(e.actual_cost) }}</td>
                    </tr>
                    <tr v-if="!(detail.expense_items || []).length"><td colspan="4" class="m">{{ t('No owner-charged expenses this month.') }}</td></tr>
                  </tbody>
                </table>
              </div>

              <div style="display:flex;align-items:center;gap:8px;margin:18px 0 8px">
                <span style="font-weight:800;font-size:13px">💸 Payout</span>
              </div>
              <div v-if="detail.payout" style="background:var(--bg-alt);border-radius:12px;padding:12px 14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <span class="badge" :class="detail.payout.status === 'Paid' ? 'b-green' : 'b-orange'">{{ detail.payout.status }}</span>
                <span style="font-weight:800;font-size:15px">{{ money(detail.payout.amount) }}</span>
                <span v-if="detail.payout.method" class="c-sub">via {{ detail.payout.method }}</span>
                <span v-if="detail.payout.ref" class="c-sub" style="font-family:monospace">{{ detail.payout.ref }}</span>
              </div>
              <div v-else class="c-sub">{{ t('No payout recorded for this month.') }}</div>
            </div>
            <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
              <button v-if="['superadmin','owner','accountant'].includes(auth.user?.role || '')" class="btn-primary" style="padding:9px 16px;font-size:13px" @click="openPayout({ ...sel, net: detail.net, payout: detail.payout, name: detail.name })">💸 Record payout</button>
              <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="sel = null">{{ t('Close') }}</button>
            </div>
          </template>
        </div>
      </div>

      <!-- payout modal -->
      <div v-if="payOpen" class="overlay" @click.self="payOpen = false">
        <div class="modal">
          <div class="modal-h"><span class="t">💸 Payout · {{ pay.name }} · {{ pay.month }}</span><button class="close" @click="payOpen = false">✕</button></div>
          <div style="padding:18px 20px;display:flex;flex-direction:column;gap:12px">
            <div class="form-field"><label>Amount (৳)</label><input v-model="pay.amount" type="number" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
            <div class="form-field"><label>{{ t('Status') }}</label>
              <select v-model="pay.status" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
                <option>{{ t('Scheduled') }}</option><option>{{ t('Paid') }}</option>
              </select>
            </div>
            <div class="form-field"><label>{{ t('Method') }}</label>
              <select v-model="pay.method" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
                <option>{{ t('Bank') }}</option><option>bKash</option><option>{{ t('Nagad') }}</option><option>{{ t('Cheque') }}</option><option>{{ t('Cash') }}</option>
              </select>
            </div>
            <div class="form-field"><label>{{ t('Reference') }}</label><input v-model="pay.ref" placeholder="trx ID, cheque no…" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
          </div>
          <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
            <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="payOpen = false">{{ t('Cancel') }}</button>
            <button class="btn-primary" style="padding:9px 16px;font-size:13px" :disabled="busy" @click="savePayout">{{ t('Save payout') }}</button>
          </div>
        </div>
      </div>

      <!-- print modal -->
      <div v-if="printOpen" class="overlay" @click.self="printOpen = false">
        <div class="modal" style="max-width:840px;padding:0;display:flex;flex-direction:column;overflow:hidden">
          <div class="modal-h" style="flex-shrink:0">
            <span class="t">🖨️ Print statement</span>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <label style="font-size:12px;display:flex;align-items:center;gap:5px;cursor:pointer"><input type="checkbox" v-model="printIncludePayouts" style="accent-color:var(--primary)"> {{ t('Payouts') }}</label>
              <label style="font-size:12px;display:flex;align-items:center;gap:5px;cursor:pointer"><input type="checkbox" v-model="printSignatures" style="accent-color:var(--primary)"> {{ t('Signatures') }}</label>
              <button class="btn-primary" style="padding:7px 14px;font-size:12.5px" @click="doPrint">🖨️ Print / PDF</button>
              <button class="close" @click="printOpen = false">✕</button>
            </div>
          </div>
          <div id="print-scroll" style="overflow:auto;padding:22px 24px;background:var(--bg-alt)">
            <div id="print-doc" style="background:#fff;color:#111;max-width:760px;margin:0 auto;padding:36px 40px;border-radius:12px;font-size:12.5px;line-height:1.5">
              <!-- letterhead -->
              <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1E5EB8;padding-bottom:14px;margin-bottom:18px">
                <div>
                  <img v-if="ph.img" :src="ph.img" :alt="ph.name" :style="{ height: ph.h + 'px', display: 'block', width: 'auto', maxWidth: '240px', objectFit: 'contain' }">
                  <span v-if="!ph.img || ph.showTitle" style="display:block;font-size:19px;font-weight:800;color:#1E5EB8;letter-spacing:-.3px">{{ ph.name }}</span>
                  <div style="font-size:9.5px;color:#8a94a6;letter-spacing:2px;text-transform:uppercase;margin-top:2px">{{ t('Owner statement') }}</div>
                </div>
                <div style="text-align:right;font-size:11px;color:#444">
                  <div style="font-weight:800;font-size:15px;color:#111">{{ monthLabel }}</div>
                  <div style="margin-top:2px">Generated {{ genDate }}</div>
                </div>
              </div>

              <!-- summary -->
              <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px">
                <div style="border:1px solid #dde3ec;border-radius:8px;padding:9px 10px">
                  <div style="font-size:8.5px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#8a94a6">{{ t('Gross rent') }}</div>
                  <div style="font-size:13.5px;font-weight:800;margin-top:2px">{{ money(totals.gross) }}</div>
                </div>
                <div style="border:1px solid #dde3ec;border-radius:8px;padding:9px 10px">
                  <div style="font-size:8.5px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#8a94a6">{{ t('Collected') }}</div>
                  <div style="font-size:13.5px;font-weight:800;margin-top:2px;color:#12924f">{{ money(totals.collected) }}</div>
                  <div style="font-size:9.5px;color:#666;margin-top:1px">{{ collectRate }}%</div>
                </div>
                <div style="border:1px solid #dde3ec;border-radius:8px;padding:9px 10px">
                  <div style="font-size:8.5px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#8a94a6">{{ t('TDS + service') }}</div>
                  <div style="font-size:13.5px;font-weight:800;margin-top:2px">{{ money(totals.tds + totals.service) }}</div>
                </div>
                <div style="border:1px solid #dde3ec;border-radius:8px;padding:9px 10px">
                  <div style="font-size:8.5px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#8a94a6">{{ t('Expenses') }}</div>
                  <div style="font-size:13.5px;font-weight:800;margin-top:2px;color:#c0392b">{{ money(totals.expenses) }}</div>
                </div>
                <div style="border:1px solid #dde3ec;border-radius:8px;padding:9px 10px">
                  <div style="font-size:8.5px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#8a94a6">{{ t('Net payout') }}</div>
                  <div style="font-size:13.5px;font-weight:800;margin-top:2px" :style="netStyle(totals.net)">{{ money(totals.net) }}</div>
                </div>
              </div>

              <!-- statements table -->
              <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#1E5EB8;margin:20px 0 6px">{{ t('Property statements') }}</div>
              <table style="width:100%;border-collapse:collapse;font-size:11px">
                <thead>
                  <tr>
                    <th style="text-align:left;padding:6px 8px;border-bottom:2px solid #1E5EB8;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#8a94a6">{{ t('Property') }}</th>
                    <th style="text-align:right;padding:6px 8px;border-bottom:2px solid #1E5EB8;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#8a94a6">{{ t('Gross') }}</th>
                    <th style="text-align:right;padding:6px 8px;border-bottom:2px solid #1E5EB8;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#8a94a6">{{ t('Collected') }}</th>
                    <th style="text-align:right;padding:6px 8px;border-bottom:2px solid #1E5EB8;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#8a94a6">{{ t('TDS') }}</th>
                    <th style="text-align:right;padding:6px 8px;border-bottom:2px solid #1E5EB8;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#8a94a6">{{ t('Service') }}</th>
                    <th style="text-align:right;padding:6px 8px;border-bottom:2px solid #1E5EB8;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#8a94a6">{{ t('Expenses') }}</th>
                    <th style="text-align:right;padding:6px 8px;border-bottom:2px solid #1E5EB8;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#8a94a6">{{ t('Net') }}</th>
                    <th style="text-align:right;padding:6px 8px;border-bottom:2px solid #1E5EB8;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#8a94a6">{{ t('Payout') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="s in list" :key="s.prop">
                    <td style="padding:6px 8px;border-bottom:1px solid #e7ebf2;font-weight:700">{{ s.name }}</td>
                    <td style="padding:6px 8px;border-bottom:1px solid #e7ebf2;text-align:right">{{ money(s.gross) }}</td>
                    <td style="padding:6px 8px;border-bottom:1px solid #e7ebf2;text-align:right">{{ money(s.collected) }}</td>
                    <td style="padding:6px 8px;border-bottom:1px solid #e7ebf2;text-align:right">{{ money(s.tds) }}</td>
                    <td style="padding:6px 8px;border-bottom:1px solid #e7ebf2;text-align:right">{{ money(s.service) }}</td>
                    <td style="padding:6px 8px;border-bottom:1px solid #e7ebf2;text-align:right">{{ money(s.expenses) }}</td>
                    <td style="padding:6px 8px;border-bottom:1px solid #e7ebf2;text-align:right;font-weight:800" :style="netStyle(s.net)">{{ money(s.net) }}</td>
                    <td style="padding:6px 8px;border-bottom:1px solid #e7ebf2;text-align:right;font-size:10px">{{ s.payout ? s.payout.status + ' ' + money(s.payout.amount) : '—' }}</td>
                  </tr>
                  <tr v-if="!list.length"><td colspan="8" style="padding:16px;text-align:center;color:#8a94a6">No statements for {{ monthLabel }}.</td></tr>
                </tbody>
                <tfoot>
                  <tr>
                    <td style="padding:7px 8px;border-top:2px solid #1E5EB8;font-weight:800">{{ t('Total') }}</td>
                    <td style="padding:7px 8px;border-top:2px solid #1E5EB8;text-align:right;font-weight:800">{{ money(totals.gross) }}</td>
                    <td style="padding:7px 8px;border-top:2px solid #1E5EB8;text-align:right;font-weight:800">{{ money(totals.collected) }}</td>
                    <td style="padding:7px 8px;border-top:2px solid #1E5EB8;text-align:right;font-weight:800">{{ money(totals.tds) }}</td>
                    <td style="padding:7px 8px;border-top:2px solid #1E5EB8;text-align:right;font-weight:800">{{ money(totals.service) }}</td>
                    <td style="padding:7px 8px;border-top:2px solid #1E5EB8;text-align:right;font-weight:800">{{ money(totals.expenses) }}</td>
                    <td style="padding:7px 8px;border-top:2px solid #1E5EB8;text-align:right;font-weight:800" :style="netStyle(totals.net)">{{ money(totals.net) }}</td>
                    <td style="padding:7px 8px;border-top:2px solid #1E5EB8"></td>
                  </tr>
                </tfoot>
              </table>

              <!-- payout ledger -->
              <template v-if="printIncludePayouts">
                <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#1E5EB8;margin:20px 0 6px">{{ t('Payout ledger') }}</div>
                <table style="width:100%;border-collapse:collapse;font-size:11px">
                  <thead>
                    <tr>
                      <th style="text-align:left;padding:6px 8px;border-bottom:2px solid #1E5EB8;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#8a94a6">{{ t('Property') }}</th>
                      <th style="text-align:left;padding:6px 8px;border-bottom:2px solid #1E5EB8;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#8a94a6">{{ t('Month') }}</th>
                      <th style="text-align:right;padding:6px 8px;border-bottom:2px solid #1E5EB8;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#8a94a6">{{ t('Amount') }}</th>
                      <th style="text-align:left;padding:6px 8px;border-bottom:2px solid #1E5EB8;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#8a94a6">{{ t('Status') }}</th>
                      <th style="text-align:left;padding:6px 8px;border-bottom:2px solid #1E5EB8;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#8a94a6">{{ t('Method') }}</th>
                      <th style="text-align:left;padding:6px 8px;border-bottom:2px solid #1E5EB8;font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#8a94a6">{{ t('Ref') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="p in payouts" :key="p.prop + p.month">
                      <td style="padding:6px 8px;border-bottom:1px solid #e7ebf2;font-weight:700">{{ p.prop }}</td>
                      <td style="padding:6px 8px;border-bottom:1px solid #e7ebf2">{{ p.month }}</td>
                      <td style="padding:6px 8px;border-bottom:1px solid #e7ebf2;text-align:right;font-weight:700">{{ money(p.amount) }}</td>
                      <td style="padding:6px 8px;border-bottom:1px solid #e7ebf2">{{ p.status }}</td>
                      <td style="padding:6px 8px;border-bottom:1px solid #e7ebf2">{{ p.method || '—' }}</td>
                      <td style="padding:6px 8px;border-bottom:1px solid #e7ebf2;font-family:monospace;font-size:10px">{{ p.ref || '—' }}</td>
                    </tr>
                    <tr v-if="!payouts.length"><td colspan="6" style="padding:14px;text-align:center;color:#8a94a6">No payouts recorded for {{ monthLabel }}.</td></tr>
                  </tbody>
                </table>
              </template>

              <!-- signatures -->
              <div v-if="printSignatures" style="display:flex;gap:40px;margin-top:42px;font-size:11px;color:#444">
                <div style="flex:1;text-align:center"><div style="border-top:1px solid #999;padding-top:5px">{{ t('Owner signature') }}</div></div>
                <div style="flex:1;text-align:center"><div style="border-top:1px solid #999;padding-top:5px">{{ t('Date') }}</div></div>
                <div style="flex:1;text-align:center"><div style="border-top:1px solid #999;padding-top:5px">{{ t('KRTaker seal') }}</div></div>
              </div>

              <div style="margin-top:26px;padding-top:10px;border-top:1px dashed #dde3ec;font-size:9.5px;color:#8a94a6;text-align:center">Generated by KRTaker · computer-generated owner statement · {{ genDate }}</div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- ══ RENT CONFIG TAB ══ -->
    <template v-if="tab === 'rentconfig'">
      <div v-if="rentLoading" class="panel" style="padding:36px;text-align:center;color:var(--text-mute)">Loading…</div>
      <template v-else>
        <!-- KPI row -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:14px">
          <div class="stat" style="border-left:4px solid #3b82f6">
            <div class="s-label"><span class="s-ico">🏢</span>{{ t('Properties') }}</div>
            <div class="s-value">{{ rentCfg.length }}</div>
            <div class="s-trend">configured</div>
          </div>
          <div class="stat" style="border-left:4px solid #8b5cf6">
            <div class="s-label"><span class="s-ico">🧮</span>{{ t('Base rent') }}</div>
            <div class="s-value">{{ money(rcTotals.base) }}</div>
            <div class="s-trend">monthly</div>
          </div>
          <div class="stat" style="border-left:4px solid #f59e0b">
            <div class="s-label"><span class="s-ico">🧹</span>{{ t('Service charges') }}</div>
            <div class="s-value">{{ money(rcTotals.service) }}</div>
            <div class="s-trend">+ parking {{ money(rcTotals.parking) }}</div>
          </div>
          <div class="stat" style="border-left:4px solid #10b981">
            <div class="s-label"><span class="s-ico">🎯</span>{{ t('Rent mix total') }}</div>
            <div class="s-value">{{ money(rcTotals.total) }}</div>
            <div class="s-trend">avg escalation {{ avgEsc }}%</div>
          </div>
        </div>

        <!-- property config cards -->
        <div v-if="!rentCfg.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">{{ t('No properties found.') }}</div>
        <div v-for="p in rentCfg" :key="p.prop" class="panel" style="padding:18px 20px;margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:12px">
              <div style="width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;color:#fff;flex-shrink:0" :style="{ background: propTint(p) }">{{ shortName(p) }}</div>
              <div>
                <div style="font-weight:800;font-size:15px">{{ p.property }}</div>
                <div class="c-sub" style="font-size:12px;margin-top:2px">{{ p.prop }} · {{ p.units }} units</div>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
              <div style="text-align:right">
                <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:var(--text-mute)">{{ t('Monthly mix') }}</div>
                <div style="font-weight:800;font-size:15px;margin-top:1px">{{ money(p.mix?.total) }}</div>
              </div>
              <button v-if="canEditRent" class="btn-ghost" style="padding:8px 14px;font-size:12.5px" @click="openEdit(p)">✏️ Edit</button>
            </div>
          </div>

          <!-- rent mix stacked bar -->
          <div style="display:flex;height:10px;border-radius:99px;overflow:hidden;margin:14px 0 4px;background:var(--bg-alt)">
            <div :style="{ width: mixBar(p).base + '%', background: '#3b82f6' }" :title="t('Base rent')"></div>
            <div :style="{ width: mixBar(p).svc + '%', background: '#f59e0b' }" :title="t('Service charge')"></div>
            <div :style="{ width: mixBar(p).pk + '%', background: '#10b981' }" :title="t('Parking')"></div>
          </div>
          <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:11px;color:var(--text-mute);margin-bottom:12px">
            <span><span style="display:inline-block;width:8px;height:8px;border-radius:99px;background:#3b82f6;margin-right:4px"></span>Base {{ money(p.mix?.base) }}</span>
            <span><span style="display:inline-block;width:8px;height:8px;border-radius:99px;background:#f59e0b;margin-right:4px"></span>Service {{ money(p.mix?.service_charge) }}</span>
            <span><span style="display:inline-block;width:8px;height:8px;border-radius:99px;background:#10b981;margin-right:4px"></span>Parking {{ money(p.mix?.parking) }}</span>
          </div>

          <!-- config chips -->
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px">
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Service charge') }}</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ p.config.service_charge_pct || 0 }}%</div>
            </div>
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Utility advance') }}</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ money(p.config.utility_advance) }}</div>
            </div>
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Parking fee') }}</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ money(p.config.parking_fee) }}</div>
            </div>
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Escalation') }}</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ p.config.escalation_pct || 0 }}%</div>
            </div>
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Advance months') }}</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ p.config.advance_months || 0 }}</div>
            </div>
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Due day') }}</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ p.config.due_day || 5 }}</div>
            </div>
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Late fee') }}</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ p.config.late_fee_pct || 0 }}%</div>
            </div>
            <div style="background:var(--bg-alt);border-radius:10px;padding:8px 11px">
              <div style="color:var(--text-mute);font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px">{{ t('Rent / sqft') }}</div>
              <div style="font-weight:800;font-size:13px;margin-top:1px">{{ money(p.config.rent_per_sqft) }}</div>
            </div>
          </div>
          <div v-if="p.config.notes" style="background:#f59e0b14;border:1px solid #f59e0b33;border-radius:10px;padding:8px 12px;font-size:12px;margin-top:10px">📝 {{ p.config.notes }}</div>
        </div>

        <!-- edit modal -->
        <div v-if="editProp" class="overlay" @click.self="editProp = null">
          <div class="modal" style="max-width:560px">
            <div class="modal-h"><span class="t">⚙️ Rent config · {{ rentCfg.find(p => p.prop === editProp)?.property }}</span><button class="close" @click="editProp = null">✕</button></div>
            <div style="padding:18px 20px;display:grid;grid-template-columns:1fr 1fr;gap:12px;overflow-y:auto;max-height:60vh">
              <div class="form-field"><label>{{ t('Service charge (%)') }}</label><input v-model="editForm.service_charge_pct" type="number" step="0.1" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field"><label>Utility advance (৳)</label><input v-model="editForm.utility_advance" type="number" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field"><label>Parking fee (৳)</label><input v-model="editForm.parking_fee" type="number" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field"><label>{{ t('Escalation (%)') }}</label><input v-model="editForm.escalation_pct" type="number" step="0.1" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field"><label>{{ t('Advance months') }}</label><input v-model="editForm.advance_months" type="number" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field"><label>{{ t('Due day') }}</label><input v-model="editForm.due_day" type="number" min="1" max="31" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field"><label>{{ t('Late fee (%)') }}</label><input v-model="editForm.late_fee_pct" type="number" step="0.1" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field"><label>Rent / sqft (৳)</label><input v-model="editForm.rent_per_sqft" type="number" min="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
              <div class="form-field" style="grid-column:1/-1"><label>{{ t('Notes') }}</label><textarea v-model="editForm.notes" rows="2" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;resize:vertical"></textarea></div>
            </div>
            <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
              <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="editProp = null">{{ t('Cancel') }}</button>
              <button class="btn-primary" style="padding:9px 16px;font-size:13px" :disabled="rentSaving" @click="saveRentConfig">💾 Save config {{ rentSaving ? '…' : '' }}</button>
            </div>
          </div>
        </div>
      </template>
    </template>

    <!-- ══ STATEMENT EMAILS TAB (V2.21) ══ -->
    <template v-if="tab === 'email'">
      <div v-if="emailLoading" class="panel" style="padding:36px;text-align:center;color:var(--text-mute)">Loading…</div>
      <template v-else>
        <!-- config + send card -->
        <div class="panel" style="padding:18px 20px;margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
            <div>
              <div style="font-weight:800;font-size:15px">📧 Monthly owner statement emails</div>
              <div class="c-sub" style="font-size:12px;margin-top:3px">One summary email per property to the owner (properties.sub_email) · idempotent per month</div>
            </div>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;cursor:pointer">
              <input type="checkbox" v-model="emailCfg.enabled" :disabled="!canEmail" style="width:16px;height:16px">
              {{ t('Auto-send enabled') }}
            </label>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:14px">
            <div class="form-field">
              <label>Send day of month (1–28)</label>
              <input v-model.number="emailCfg.day" type="number" min="1" max="28" :disabled="!canEmail" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            </div>
            <div class="form-field">
              <label>{{ t('Owner salutation name') }}</label>
              <input v-model="emailCfg.owner_name" placeholder="e.g. Alamgir Kabir" :disabled="!canEmail" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            </div>
            <div class="form-field">
              <label>{{ t('BCC (optional)') }}</label>
              <input v-model="emailCfg.bcc" placeholder="accounts@…" :disabled="!canEmail" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            </div>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px">
            <button v-if="canEmail" class="btn-primary" :disabled="emailSaving" @click="saveEmailCfg" style="font-size:12.5px">💾 Save config {{ emailSaving ? '…' : '' }}</button>
            <button class="btn-ghost" :disabled="emailRunning" @click="previewEmails" style="font-size:12.5px">👁️ Preview for {{ monthLabel }}</button>
            <button v-if="canEmail" class="btn-primary" :disabled="emailRunning || !emailCfg.enabled" @click="confirmSendEmails = !confirmSendEmails" style="font-size:12.5px">📨 Send statements {{ emailRunning ? '…' : '' }}</button>
            <div v-if="emailLastRun" class="c-sub" style="font-size:12px">Last run: {{ emailLastRun }}</div>
          </div>
          <div v-if="confirmSendEmails" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:rgba(231,76,60,.08);padding:10px 14px;border-radius:10px;margin-top:12px">
            <span style="font-size:13px;color:var(--text)">{{ t('Send owner statement emails for') }} <b>{{ monthLabel }}</b> now? One email per property, queued through the mail worker. Already-sent properties are skipped.</span>
            <button class="btn-primary" style="font-size:12.5px" :disabled="emailRunning" @click="sendEmails">Yes, send {{ emailRunning ? '…' : '' }}</button>
            <button class="btn-ghost" style="font-size:12.5px" @click="confirmSendEmails = false">{{ t('Cancel') }}</button>
          </div>
        </div>

        <!-- totals -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:14px">
          <div class="stat"><div class="s-label"><span class="s-ico">🏢</span>{{ t('Properties') }}</div><div class="s-value">{{ emailPlan.length }}</div><div class="s-trend">{{ emailTotals.emailable }} emailable · {{ emailTotals.no_email }} no email · {{ emailTotals.already }} done</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">💰</span>{{ t('Gross billed') }}</div><div class="s-value">{{ money(emailTotals.gross) }}</div><div class="s-trend">for {{ monthLabel }}</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">✅</span>{{ t('Collected') }}</div><div class="s-value" style="color:var(--ok)">{{ money(emailTotals.collected) }}</div><div class="s-trend">to owners</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">📤</span>{{ t('Net payable') }}</div><div class="s-value" style="color:var(--primary)">{{ money(emailTotals.net) }}</div><div class="s-trend">across properties</div></div>
        </div>

        <!-- plan table -->
        <div class="panel" style="overflow:hidden;margin-bottom:14px">
          <div style="padding:14px 18px;border-bottom:1px solid var(--border);font-weight:800;font-size:13.5px">📋 Send plan — {{ monthLabel }}</div>
          <div class="tbl-wrap">
            <table class="kr" style="width:100%">
              <thead><tr><th>{{ t('Property') }}</th><th>{{ t('Gross') }}</th><th>{{ t('Collected') }}</th><th>{{ t('Net') }}</th><th>{{ t('Owner email') }}</th><th>{{ t('Status') }}</th></tr></thead>
              <tbody>
                <tr v-for="p in emailPlanRows" :key="p.prop">
                  <td style="white-space:nowrap"><b>{{ p.name }}</b></td>
                  <td class="c-sub">{{ money(p.gross) }}</td>
                  <td class="c-sub" style="color:var(--ok)">{{ money(p.collected) }}</td>
                  <td style="font-weight:700">{{ money(p.net) }}</td>
                  <td class="c-sub">{{ p.to || '—' }}</td>
                  <td><span class="badge" :style="p.to ? 'background:#E8F5E9;color:#1E8449' : 'background:#FDECEA;color:#B91C1C'">{{ p.to ? 'Will email' : 'No email' }}</span></td>
                </tr>
                <tr v-for="p in emailAlreadyRows" :key="'a' + p.prop" style="opacity:.55">
                  <td style="white-space:nowrap"><b>{{ p.name }}</b></td>
                  <td class="c-sub">{{ money(p.gross) }}</td>
                  <td class="c-sub" style="color:var(--ok)">{{ money(p.collected) }}</td>
                  <td style="font-weight:700">{{ money(p.net) }}</td>
                  <td class="c-sub">{{ p.to || '—' }}</td>
                  <td><span class="badge b-blue" style="background:#E8F0FE;color:#1E5EB8">✅ Already sent</span></td>
                </tr>
                <tr v-if="!emailPlan.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:22px">No properties found. Click 👁️ Preview to load the plan.</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- history -->
        <div class="panel" style="overflow:hidden">
          <div style="padding:14px 18px;border-bottom:1px solid var(--border);font-weight:800;font-size:13.5px">🕓 Send history (last 20)</div>
          <div class="tbl-wrap">
            <table class="kr" style="width:100%">
              <thead><tr><th>{{ t('When') }}</th><th>{{ t('Property') }}</th><th>{{ t('Month') }}</th><th>{{ t('Net') }}</th><th>To</th></tr></thead>
              <tbody>
                <tr v-for="h in emailHistory" :key="h.id">
                  <td style="white-space:nowrap" class="c-sub">{{ h.ts }}</td>
                  <td style="white-space:nowrap"><b>{{ h.prop }}</b></td>
                  <td style="white-space:nowrap">{{ h.month }}</td>
                  <td>{{ money(h.net) }}</td>
                  <td class="c-sub">{{ h.to_addr }}</td>
                </tr>
                <tr v-if="!emailHistory.length"><td colspan="5" style="text-align:center;color:var(--text-mute);padding:22px">{{ t('No statement emails sent yet.') }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>
