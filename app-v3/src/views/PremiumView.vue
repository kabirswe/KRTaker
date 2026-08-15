<script setup>
import { ref, computed, onMounted } from 'vue'
import { apiCall } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import ScrollTabs from '../components/ScrollTabs.vue'
import { t } from '../lib/i18n'

const auth = useAuthStore()
const data = useDataStore()
const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')

const role = computed(() => auth.user?.role || data.user?.role || '')
const isOwner = computed(() => ['owner', 'superadmin'].includes(role.value))
const canSeeAll = computed(() => ['superadmin', 'owner', 'manager'].includes(role.value))
const canBilling = computed(() => ['superadmin', 'owner', 'manager'].includes(role.value))

const tab = ref('plans')
const loading = ref(false)
const err = ref('')
const toast = ref('')

// ── Plans (app-premium-plans) ──
const plans = ref([])
async function loadPlans() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-premium-plans')
    if (!r.ok) { err.value = r.error || t('Failed to load plans.'); return }
    plans.value = Object.entries(r.plans || {}).map(([code, p]) => ({ code, ...p }))
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}
const CYCLES = [['monthly', 'Monthly'], ['quarterly', t('Quarterly')], ['annual', 'Annual']]

// ── Subscribe (app-premium-subscribe) ──
const subOpen = ref(false)
const subForm = ref({ tier: 'nrb_caretaker', cycle: 'monthly', prop: '' })
const subBusy = ref(false)
function openSubscribe() {
  subForm.value = { tier: plans.value.find(p => p.popular)?.code || plans.value[0]?.code || 'nrb_caretaker', cycle: 'monthly', prop: data.list('properties')[0]?.id || '' }
  subOpen.value = true
}
async function doSubscribe() {
  subBusy.value = true; err.value = ''
  try {
    const r = await apiCall('app-premium-subscribe', subForm.value)
    if (!r.ok) { err.value = r.error || t('Subscribe failed.'); return }
    subOpen.value = false
    toast.value = `✅ ${r.id} subscribed — ${r.sub?.tier_label || ''} ${r.sub?.cycle || ''}`
    setTimeout(() => toast.value = '', 5000)
    await Promise.all([loadSubs(), loadBilling()])
  } catch (e) { err.value = e.message }
  finally { subBusy.value = false }
}

// ── Subs (app-premium-sub-list) ──
const subs = ref([])
async function loadSubs() {
  if (!canSeeAll.value) { subs.value = []; return }
  const r = await apiCall('app-premium-sub-list')
  if (r.ok) subs.value = r.subs || []
  else if (r.error && !err.value) err.value = r.error
}
async function toggleSub(s) {
  if (!confirm(`Pause or resume ${s.id} (${s.tier_label})?`)) return
  err.value = ''
  const r = await apiCall('app-premium-toggle', { id: s.id })
  if (!r.ok) { err.value = r.error || t('Toggle failed.'); return }
  toast.value = `✅ ${s.id} → ${r.status}`
  setTimeout(() => toast.value = '', 4000)
  await loadSubs()
}
async function cancelSub(s) {
  if (!confirm(`Cancel ${s.id} (${s.tier_label})? This ends the subscription and stops billing.`)) return
  err.value = ''
  const r = await apiCall('app-premium-cancel', { id: s.id })
  if (!r.ok) { err.value = r.error || t('Cancel failed.'); return }
  toast.value = `✅ ${s.id} cancelled`
  setTimeout(() => toast.value = '', 4000)
  await loadSubs()
}
const stCls = (s) => s === 'active' ? 'b-green' : (s === 'paused' ? 'b-orange' : 'b-gray')

// ── Billing (app-premium-billing) ──
const bills = ref([])
const billBusy = ref(false)
async function loadBilling() {
  if (!canBilling.value) { bills.value = []; return }
  const r = await apiCall('app-premium-billing', { action: 'list' })
  if (r.ok) bills.value = r.invoices || []
  else if (r.error && !err.value) err.value = r.error
}
async function payBill(b) {
  if (!confirm(`Mark caretaker invoice ${b.id} (${money(b.amount)}) as Paid?`)) return
  billBusy.value = true; err.value = ''
  try {
    const r = await apiCall('app-premium-billing', { action: 'pay', id: b.id, method: 'bKash' })
    if (!r.ok) { err.value = r.error || t('Pay failed.'); return }
    toast.value = `✅ ${b.id} paid`
    setTimeout(() => toast.value = '', 4000)
    await loadBilling()
  } catch (e) { err.value = e.message }
  finally { billBusy.value = false }
}
async function runBilling() {
  if (!confirm(`Run the premium billing cycle for the current month? Generates invoices for active subscriptions.`)) return
  billBusy.value = true; err.value = ''
  try {
    const r = await apiCall('app-premium-billing', { action: 'run' })
    if (!r.ok) { err.value = r.error || t('Billing run failed.'); return }
    toast.value = `✅ Billing run: ${r.created?.length || 0} invoices created`
    setTimeout(() => toast.value = '', 5000)
    await loadBilling()
  } catch (e) { err.value = e.message }
  finally { billBusy.value = false }
}
const billCls = (s) => s === 'Paid' ? 'b-green' : 'b-orange'

onMounted(() => { loadPlans(); loadSubs(); loadBilling() })
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('💎 Premium & Subscriptions') }}</h1>
        <div class="sub">{{ t('Remote caretaker plans — subscribe, manage, bill · live from API') }}</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-ghost" @click="loadPlans(); loadSubs(); loadBilling()">{{ t('Refresh') }}</button>
        <button v-if="isOwner" @click="openSubscribe" style="padding:10px 16px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:13px;cursor:pointer">＋ Subscribe</button>
      </div>
    </div>

    <div v-if="toast" style="padding:10px 14px;border-radius:10px;background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.35);margin-bottom:14px;font-weight:700;font-size:13.5px">{{ toast }}</div>
    <div v-if="err" style="padding:10px 14px;border-radius:10px;background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.35);margin-bottom:14px;font-weight:600;font-size:13.5px">⚠️ {{ err }}</div>

    <!-- Tabs -->
    <ScrollTabs>
      <button @click="tab = 'plans'" :style="tab === 'plans' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'" style="padding:9px 16px;border:none;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer">💎 Plans</button>
      <button v-if="canSeeAll" @click="tab = 'subs'" :style="tab === 'subs' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'" style="padding:9px 16px;border:none;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer">📋 Subscriptions</button>
      <button v-if="canBilling" @click="tab = 'billing'" :style="tab === 'billing' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'" style="padding:9px 16px;border:none;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer">🧾 Billing</button>
    </ScrollTabs>

    <!-- ══ PLANS ══ -->
    <template v-if="tab === 'plans'">
      <div v-if="loading" class="panel" style="padding:36px;text-align:center;color:var(--text-mute)">Loading…</div>
      <div v-else style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
        <div v-for="p in plans" :key="p.code" class="panel chip" style="padding:22px;position:relative;display:flex;flex-direction:column">
          <div v-if="p.popular" style="position:absolute;top:14px;right:14px;background:var(--primary);color:#fff;font-size:10.5px;font-weight:800;padding:4px 10px;border-radius:999px">★ POPULAR</div>
          <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">{{ p.tag }}</div>
          <div style="font-size:19px;font-weight:900;margin-top:3px">{{ p.label }}</div>
          <div class="c-sub" style="font-size:12.5px;margin-top:6px;line-height:1.6">{{ p.blurb }}</div>
          <div style="margin:16px 0 6px">
            <span style="font-size:26px;font-weight:900;color:var(--primary)">{{ money(p.price?.monthly) }}</span>
            <span class="c-sub" style="font-size:12px">/month</span>
          </div>
          <div style="font-size:12px;color:var(--text-mute);margin-bottom:14px">Quarterly {{ money(p.price?.quarterly) }} · Annual {{ money(p.price?.annual) }}</div>
          <div style="display:flex;flex-direction:column;gap:7px;margin-bottom:18px;flex:1">
            <div v-for="f in p.features || []" :key="f" style="font-size:13px;display:flex;gap:8px;align-items:flex-start">
              <span style="color:var(--ok,#12a150);font-weight:900">✓</span><span>{{ f }}</span>
            </div>
          </div>
          <button v-if="isOwner" @click="openSubscribe" style="padding:11px 14px;border:none;border-radius:11px;background:var(--primary);color:#fff;font-weight:800;font-size:13px;cursor:pointer">Choose {{ p.label }}</button>
        </div>
      </div>
    </template>

    <!-- ══ SUBSCRIPTIONS ══ -->
    <template v-if="tab === 'subs'">
      <div v-if="!subs.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No subscriptions yet.{{ isOwner ? ' Choose a plan above and subscribe.' : '' }}</div>
      <div v-else class="panel" style="overflow:hidden">
        <div class="tbl-wrap">
          <table class="kr">
            <thead><tr><th>ID</th><th>{{ t('Owner') }}</th><th>{{ t('Plan') }}</th><th>{{ t('Property') }}</th><th style="text-align:right">{{ t('Price') }}</th><th>{{ t('Cycle') }}</th><th>{{ t('Next invoice') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="s in subs" :key="s.id">
                <td style="font-weight:700">{{ s.id }}</td>
                <td style="white-space:nowrap">{{ s.user_email }}</td>
                <td style="white-space:nowrap">{{ s.tier_label }}</td>
                <td class="c-sub" style="white-space:nowrap">{{ s.property_name || s.prop || '—' }}</td>
                <td style="text-align:right;font-weight:700">{{ money(s.price) }}</td>
                <td>{{ s.cycle }}</td>
                <td class="c-sub">{{ s.next_invoice || '—' }}</td>
                <td><span class="badge" :class="stCls(s.status)">{{ s.status }}</span></td>
                <td style="white-space:nowrap">
                  <button v-if="['active', 'paused'].includes(s.status)" class="btn-ghost" style="padding:4px 9px;font-size:11.5px" @click="toggleSub(s)">{{ s.status === 'active' ? '⏸ Pause' : '▶ Resume' }}</button>
                  <button v-if="['active', 'paused'].includes(s.status)" class="btn-ghost" style="padding:4px 9px;font-size:11.5px;color:var(--danger)" @click="cancelSub(s)">✕ Cancel</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ══ BILLING ══ -->
    <template v-if="tab === 'billing'">
      <div style="display:flex;gap:8px;margin-bottom:16px">
        <button v-if="role === 'superadmin'" @click="runBilling" :disabled="billBusy" style="padding:10px 16px;border:none;border-radius:10px;background:var(--bg-alt);color:var(--text);font-weight:800;font-size:13px;cursor:pointer">🔁 Run billing cycle {{ billBusy ? '…' : '' }}</button>
      </div>
      <div v-if="!bills.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">{{ t('No caretaker invoices yet.') }}</div>
      <div v-else class="panel" style="overflow:hidden">
        <div class="tbl-wrap">
          <table class="kr">
            <thead><tr><th>{{ t('Invoice') }}</th><th>{{ t('Sub') }}</th><th>{{ t('Owner') }}</th><th>{{ t('Property') }}</th><th>{{ t('Month') }}</th><th style="text-align:right">{{ t('Amount') }}</th><th>{{ t('Status') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="b in bills" :key="b.id">
                <td style="font-weight:700">{{ b.id }}</td>
                <td class="c-sub">{{ b.sub }}</td>
                <td style="white-space:nowrap">{{ b.user_email }}</td>
                <td class="c-sub" style="white-space:nowrap">{{ b.property_name || '—' }}</td>
                <td>{{ b.month }}</td>
                <td style="text-align:right;font-weight:700">{{ money(b.amount) }}</td>
                <td><span class="badge" :class="billCls(b.status)">{{ b.status }}</span></td>
                <td>
                  <button v-if="b.status !== 'Paid' && isOwner" class="btn-ghost" style="padding:4px 9px;font-size:11.5px" @click="payBill(b)">💳 {{ t('Mark paid') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- subscribe modal -->
    <div v-if="subOpen" class="overlay" @click.self="subOpen = false">
      <div class="modal">
        <div class="modal-h"><span class="t">💎 Subscribe to caretaker service</span><button class="close" @click="subOpen = false">✕</button></div>
        <div style="padding:18px 20px;display:flex;flex-direction:column;gap:12px">
          <div class="form-field"><label>{{ t('Plan') }}</label>
            <select v-model="subForm.tier" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option v-for="p in plans" :key="p.code" :value="p.code">{{ p.label }} — {{ money(p.price?.monthly) }}/mo</option>
            </select>
          </div>
          <div class="form-field"><label>{{ t('Billing cycle') }}</label>
            <div style="display:flex;gap:8px">
              <button v-for="[c, cl] in CYCLES" :key="c" @click="subForm.cycle = c" :style="subForm.cycle === c ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'" style="padding:9px 14px;border:none;border-radius:9px;font-weight:800;font-size:12.5px;cursor:pointer;flex:1">{{ cl }}<div style="font-size:11px;opacity:.85">{{ money(plans.find(p => p.code === subForm.tier)?.price?.[c]) }}</div></button>
            </div>
          </div>
          <div class="form-field"><label>{{ t('Property') }}</label>
            <select v-model="subForm.prop" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option value="">— No property (workspace only) —</option>
              <option v-for="p in data.list('properties')" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
        </div>
        <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
          <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="subOpen = false">{{ t('Cancel') }}</button>
          <button class="btn-primary" style="padding:9px 16px;font-size:13px" :disabled="subBusy" @click="doSubscribe">Subscribe {{ subBusy ? '…' : '' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
