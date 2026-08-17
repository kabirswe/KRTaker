<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { t } from '../lib/i18n'
import { useRoute } from 'vue-router'
import { apiCall } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { badge } from '../lib/ui'
import ScrollTabs from '../components/ScrollTabs.vue'
import CompactFilters from '../components/CompactFilters.vue'

const route = useRoute()
const auth = useAuthStore()
const canWrite = computed(() => ['superadmin', 'owner', 'manager', 'accountant'].includes(auth.user?.role || ''))

const tab = ref('transactions')
watch(() => route.query.tab, (t) => { if (t && ['transactions', 'receive', 'expense', 'withdraw', 'deposit', 'reconcile'].includes(t)) tab.value = t }, { immediate: true })

const loading = ref(false)
const err = ref('')
const toast = ref('')
const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const sign = (t) => ['receive', 'deposit'].includes(t) ? '+' : '−'
const typeColor = (t) => ['receive', 'deposit'].includes(t) ? '#12a150' : 'var(--danger,#e74c3c)'
const typeIco = (t) => ({ receive: '📥', expense: '📤', withdraw: '🏧', deposit: '🏦' }[t] || '💱')
const typeLabel = (ty) => ({ receive: 'Receive', expense: 'Expense', withdraw: 'Withdraw', deposit: 'Deposit' }[ty] || ty)
const typeLabelT = (ty) => t(typeLabel(ty))
const acctTypeLabel = (t) => ({ cash: '💵 Cash', bank: '🏦 Bank', mobile: '📱 Mobile' }[t] || t)

// ── summary (accounts + totals + recent) ──
const accounts = ref([])        // [{id,name,type,opening_balance,inflow,outflow,balance,tx_count,status}]
const totals = ref({ inflow: 0, outflow: 0, balance: 0, count: 0 })
const byType = ref([])
const recent = ref([])          // transactions from summary
const unreconciled = ref(0)

async function loadSummary() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-accounts', { action: 'summary' })
    if (!r.ok) { err.value = r.error || t('Failed to load accounts.'); return }
    accounts.value = r.accounts || []
    totals.value = r.totals || { inflow: 0, outflow: 0, balance: 0, count: 0 }
    byType.value = r.by_type || []
    recent.value = r.transactions || []
    unreconciled.value = r.unreconciled || 0
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}

// ── transactions list (filtered) ──
const txList = ref([])
const fAcct = ref('')
const fType = ref('')
const fQ = ref('')
async function loadTx() {
  const r = await apiCall('app-accounts', { action: 'list', account: fAcct.value, type: fType.value, q: fQ.value.trim() })
  if (r.ok) txList.value = r.transactions || []
  else if (r.error && !err.value) err.value = r.error
}
async function delTx(t) {
  if (!confirm(`Delete transaction ${t.id} (${t.label})?`)) return
  err.value = ''
  const r = await apiCall('app-accounts', { action: 'delete', id: t.id })
  if (!r.ok) { err.value = r.error || t('Delete failed.'); return }
  toast.value = `🗑️ ${t.id} deleted`
  setTimeout(() => toast.value = '', 4000)
  await Promise.all([loadSummary(), loadTx(), loadRecon()])
}
const reconBadge = (t) => t.reconciled == 1 ? 'b-green' : 'b-gray'

// ── post forms (receive/expense/withdraw/deposit) — the active tab IS the type ──
const postForm = ref({ account: '', cat: 'other', label: '', amount: '', method: 'Bank', ref: '', payee: '', note: '', date: '' })
const postBusy = ref(false)

const CATS = {
  receive: [['rent', 'Rent'], ['service_charge', t('Service charge')], ['utility', 'Utility'], ['parking', 'Parking'], ['advance', t('Advance / deposit')], ['refund', t('Refund received')], ['other_income', t('Other income')]],
  expense: [['maintenance', 'Maintenance'], ['utility', t('Utility bill')], ['salary', t('Salary / staff')], ['tax', t('Tax / govt fee')], ['marketing', 'Marketing'], ['travel', 'Travel'], ['transport', 'Transport'], ['office', t('Office / admin')], ['misc', 'Miscellaneous']],
  withdraw: [['cash', t('Cash withdrawal')], ['transfer', t('Transfer out')]],
  deposit: [['cash', t('Cash deposit')], ['transfer', t('Transfer in')]],
}

function openPost(type) {
  tab.value = type
  postForm.value = {
    account: accounts.value.find(a => a.status === 'active')?.id || '',
    cat: (CATS[type] || [])[0]?.[0] || 'other',
    label: '', amount: '', method: 'Bank', ref: '', payee: '', note: '',
    date: new Date().toISOString().slice(0, 16),
  }
}
async function submitPost() {
  const type = tab.value   // the form only renders on its own tab — the active tab IS the type
  if (!postForm.value.label.trim() || !(+postForm.value.amount > 0)) { err.value = t('Label and positive amount are required.'); return }
  postBusy.value = true; err.value = ''
  try {
    const body = {
      action: type,
      account: postForm.value.account,
      cat: postForm.value.cat,
      label: postForm.value.label.trim(),
      amount: Math.round(+postForm.value.amount || 0),
      method: postForm.value.method,
      ref: postForm.value.ref.trim(),
      payee: postForm.value.payee.trim(),
      note: postForm.value.note.trim(),
      date: postForm.value.date,
    }
    const r = await apiCall('app-accounts', body)
    if (!r.ok) { err.value = r.error || t('Post failed.'); return }
    toast.value = `✅ ${typeLabelT(type)} posted — ${r.id}`
    setTimeout(() => toast.value = '', 5000)
    await Promise.all([loadSummary(), loadTx(), loadRecon()])
  } catch (e) { err.value = e.message }
  finally { postBusy.value = false }
}
const METHODS = ['Bank', 'bKash', 'Nagad', 'Cash', 'Cheque', 'Card', 'Other']

// ── accounts management ──
const acctOpen = ref(false)
const acctForm = ref({ name: '', type: 'bank', opening_balance: 0, notes: '' })
const acctBusy = ref(false)
function openAcct() {
  acctForm.value = { name: '', type: 'bank', opening_balance: 0, notes: '' }
  acctOpen.value = true
}
async function createAcct() {
  if (!acctForm.value.name.trim()) { err.value = t('Account name required.'); return }
  acctBusy.value = true; err.value = ''
  try {
    const r = await apiCall('app-accounts', { action: 'account-create', name: acctForm.value.name.trim(), type: acctForm.value.type, opening_balance: +acctForm.value.opening_balance || 0, notes: acctForm.value.notes.trim() })
    if (!r.ok) { err.value = r.error || t('Create failed.'); return }
    acctOpen.value = false
    toast.value = `✅ Account created — ${r.id}`
    setTimeout(() => toast.value = '', 4000)
    await loadSummary()
  } catch (e) { err.value = e.message }
  finally { acctBusy.value = false }
}
async function toggleAcct(a) {
  if (!confirm(`Set account ${a.name} (${a.id}) to ${a.status === 'active' ? 'inactive' : 'active'}?`)) return
  err.value = ''
  const r = await apiCall('app-accounts', { action: 'account-toggle', id: a.id })
  if (!r.ok) { err.value = r.error || t('Toggle failed.'); return }
  await loadSummary()
}
async function delAcct(a) {
  if (!confirm(`Delete account ${a.name} (${a.id})? ALL its transactions will be deleted too.`)) return
  err.value = ''
  const r = await apiCall('app-accounts', { action: 'account-delete', id: a.id })
  if (!r.ok) { err.value = r.error || t('Delete failed.'); return }
  toast.value = `🗑️ ${a.id} deleted`
  setTimeout(() => toast.value = '', 4000)
  await loadSummary()
}

// ── reconcile ──
const reconPending = ref([])
const reconDone = ref([])
const reconRefs = ref({})
const reconBusy = ref(false)
async function loadRecon() {
  const r = await apiCall('app-accounts', { action: 'reconcile-list' })
  if (r.ok) { reconPending.value = r.pending || []; reconDone.value = r.done || [] }
  else if (r.error && !err.value) err.value = r.error
}
async function doReconcile(t) {
  if (!confirm(`Mark ${t.id} (${money(t.amount)}) as reconciled?`)) return
  reconBusy.value = true; err.value = ''
  try {
    const r = await apiCall('app-accounts', { action: 'reconcile', id: t.id, ref: reconRefs.value[t.id] || '' })
    if (!r.ok) { err.value = r.error || t('Reconcile failed.'); return }
    toast.value = `✅ ${t.id} reconciled`
    setTimeout(() => toast.value = '', 4000)
    await Promise.all([loadRecon(), loadSummary()])
  } catch (e) { err.value = e.message }
  finally { reconBusy.value = false }
}
async function undoReconcile(t) {
  if (!confirm(`Un-reconcile ${t.id}?`)) return
  err.value = ''
  const r = await apiCall('app-accounts', { action: 'unreconcile', id: t.id })
  if (!r.ok) { err.value = r.error || t('Failed.'); return }
  await Promise.all([loadRecon(), loadSummary()])
}

onMounted(() => { loadSummary(); loadTx(); loadRecon() })
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('💼 Accounts') }}</h1>
        <div class="sub">{{ t('Bank & cash ledger — receive, expense, withdraw, deposit, reconcile · live from API') }}</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-ghost" @click="loadSummary(); loadTx(); loadRecon()">{{ t('Refresh') }}</button>
        <button v-if="canWrite" @click="openAcct" style="padding:10px 16px;border:none;border-radius:10px;background:var(--bg-alt);color:var(--text);font-weight:800;font-size:13px;cursor:pointer">＋ Account</button>
      </div>
    </div>

    <div v-if="toast" style="padding:10px 14px;border-radius:10px;background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.35);margin-bottom:14px;font-weight:700;font-size:13.5px">{{ toast }}</div>
    <div v-if="err" style="padding:10px 14px;border-radius:10px;background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.35);margin-bottom:14px;font-weight:600;font-size:13.5px">⚠️ {{ err }}</div>

    <!-- Tabs -->
    <ScrollTabs>
      <button v-for="[k, l] in [['transactions','💱 Transactions'],['receive','📥 Receive'],['expense','📤 Expense'],['withdraw','🏧 Withdraw'],['deposit','🏦 Deposit'],['reconcile','🔁 Reconcile']]" :key="k" @click="tab = k" :style="tab === k ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'">{{ t(l) }}</button>
    </ScrollTabs>

    <!-- ══ TRANSACTIONS ══ -->
    <template v-if="tab === 'transactions'">
      <div v-if="loading" class="panel" style="padding:36px;text-align:center;color:var(--text-mute)">Loading…</div>
      <template v-else>
        <div class="stats">
          <div class="stat"><div class="s-label"><span class="s-ico">📥</span>{{ t('Money in') }}</div><div class="s-value" style="color:var(--ok,#12a150)">{{ money(totals.inflow) }}</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">📤</span>{{ t('Money out') }}</div><div class="s-value" style="color:var(--danger,#e74c3c)">{{ money(totals.outflow) }}</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">🎯</span>{{ t('Net balance') }}</div><div class="s-value" :style="(totals.balance||0) >= 0 ? 'color:var(--ok,#12a150)' : 'color:var(--danger,#e74c3c)'">{{ money(totals.balance) }}</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">🔁</span>{{ t('Unreconciled') }}</div><div class="s-value" :style="unreconciled ? 'color:#f39c12' : ''">{{ unreconciled }}</div></div>
          <div class="stat"><div class="s-label"><span class="s-ico">🧾</span>{{ t('Transactions') }}</div><div class="s-value">{{ totals.count }}</div></div>
        </div>

        <!-- account cards -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;margin:16px 0">
          <div v-for="a in accounts" :key="a.id" class="panel chip" style="padding:16px;position:relative">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
              <div>
                <div style="font-weight:800;font-size:14px">{{ a.name }}</div>
                <div class="c-sub" style="font-size:11.5px;margin-top:2px">{{ a.id }} · {{ acctTypeLabel(a.type) }}</div>
              </div>
              <div style="display:flex;gap:6px;align-items:center">
                <span class="badge" :class="a.status === 'active' ? 'b-green' : 'b-gray'">{{ a.status }}</span>
                <button v-if="canWrite" @click="toggleAcct(a)" :title="t('Toggle active')" style="border:none;background:transparent;cursor:pointer;font-size:13px">🔃</button>
                <button v-if="canWrite && a.status === 'inactive'" @click="delAcct(a)" :title="t('Delete account + transactions')" style="border:none;background:transparent;cursor:pointer;font-size:13px">🗑️</button>
              </div>
            </div>
            <div style="font-size:22px;font-weight:900;margin-top:10px" :style="(a.balance||0) >= 0 ? '' : 'color:var(--danger)'">{{ money(a.balance) }}</div>
            <div class="c-sub" style="font-size:11.5px;margin-top:4px">opening {{ money(a.opening_balance) }} · in {{ money(a.inflow) }} · out {{ money(a.outflow) }} · {{ a.tx_count }} tx</div>
          </div>
          <div v-if="!accounts.length" class="panel" style="padding:24px;text-align:center;color:var(--text-mute);font-size:13px">No accounts yet — click ＋ Account to create one.</div>
        </div>

        <!-- filters + table -->
        <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center">
          <CompactFilters>
          <select v-model="fAcct" @change="loadTx" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">{{ t('All accounts') }}</option>
            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
          </select>
          <select v-model="fType" @change="loadTx" style="padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
            <option value="">{{ t('All types') }}</option>
            <option value="receive">{{ t('Receive') }}</option><option value="expense">{{ t('Expense') }}</option>
            <option value="withdraw">{{ t('Withdraw') }}</option><option value="deposit">{{ t('Deposit') }}</option>
          </select>
          <input v-model="fQ" @input="loadTx" :placeholder="t('Search label, ref, payee…')" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;flex:1;min-width:200px">
          </CompactFilters>
          <button v-if="canWrite" @click="openPost('receive')" style="padding:9px 14px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:12.5px;cursor:pointer">＋ Post</button>
        </div>

        <div class="panel" style="overflow:hidden">
          <div class="tbl-wrap">
            <table class="kr">
              <thead><tr><th>{{ t('ID') }}</th><th>{{ t('Date') }}</th><th>{{ t('Account') }}</th><th>{{ t('Type') }}</th><th>{{ t('Category') }}</th><th>{{ t('Label') }}</th><th>{{ t('Payee') }}</th><th>{{ t('Method') }}</th><th>{{ t('Ref') }}</th><th style="text-align:right">{{ t('Amount') }}</th><th>{{ t('Recon') }}</th><th></th></tr></thead>
              <tbody>
                <tr v-for="x in txList" :key="x.id">
                  <td style="font-weight:700;white-space:nowrap">{{ x.id }}</td>
                  <td class="c-sub" style="white-space:nowrap">{{ x.tx_date }}</td>
                  <td class="c-sub" style="white-space:nowrap">{{ x.account_name || x.account || '—' }}</td>
                  <td><span class="badge" :class="badge(x.type)">{{ typeIco(x.type) }} {{ typeLabelT(x.type) }}</span></td>
                  <td class="c-sub">{{ x.cat }}</td>
                  <td style="font-weight:600">{{ x.label }}</td>
                  <td class="c-sub">{{ x.payee || '—' }}</td>
                  <td class="c-sub">{{ x.method || '—' }}</td>
                  <td class="c-sub">{{ x.ref || '—' }}</td>
                  <td style="text-align:right;font-weight:800;white-space:nowrap" :style="{ color: typeColor(x.type) }">{{ sign(x.type) }} {{ money(x.amount) }}</td>
                  <td><span class="badge" :class="reconBadge(x)">{{ x.reconciled == 1 ? '✓' : '—' }}</span></td>
                  <td style="white-space:nowrap">
                    <button v-if="canWrite" @click="delTx(x)" style="border:none;background:transparent;color:var(--text-mute);cursor:pointer;font-size:13px" :title="t('Delete')">🗑️</button>
                  </td>
                </tr>
                <tr v-if="!txList.length"><td colspan="12" style="text-align:center;color:var(--text-mute);padding:30px">{{ t('No transactions found.') }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- by type -->
        <div class="panel" style="padding:16px 18px;margin-top:14px">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">{{ t('By type') }}</div>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <span v-for="b in byType" :key="b.type" class="badge" :class="badge(b.type)" style="font-size:12.5px;padding:6px 12px">{{ typeLabelT(b.type) }}: {{ b.n }} · {{ money(b.total) }}</span>
          </div>
        </div>
      </template>
    </template>

    <!-- ══ POST FORMS (receive / expense / withdraw / deposit) ══ -->
    <template v-if="['receive', 'expense', 'withdraw', 'deposit'].includes(tab)">
      <div class="panel" style="padding:20px;max-width:720px;margin-bottom:16px">
        <div style="font-weight:800;font-size:15px;margin-bottom:4px">{{ typeIco(tab) }} {{ typeLabelT(tab) }} money</div>
        <div class="c-sub" style="font-size:12.5px;margin-bottom:16px">{{ tab === 'receive' ? 'Money in — rent, service charge, deposits, other income' : (tab === 'expense' ? 'Money out — maintenance, salaries, taxes, bills' : (tab === 'withdraw' ? 'Withdraw cash from a bank / mobile account' : 'Deposit cash into a bank / mobile account')) }}</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-field"><label>Account *</label>
            <select v-model="postForm.account" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option v-for="a in accounts.filter(x => x.status === 'active')" :key="a.id" :value="a.id">{{ a.name }} ({{ a.id }})</option>
            </select>
          </div>
          <div class="form-field"><label>{{ t('Category') }}</label>
            <select v-model="postForm.cat" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option v-for="[v, l] in CATS[tab] || []" :key="v" :value="v">{{ t(l) }}</option>
            </select>
          </div>
          <div class="form-field" style="grid-column:1/-1"><label>Label *</label><input v-model="postForm.label" placeholder="e.g. June rent — Unit 7B" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
          <div class="form-field"><label>Amount (৳) *</label><input v-model="postForm.amount" type="number" min="1" placeholder="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
          <div class="form-field"><label>{{ t('Method') }}</label>
            <select v-model="postForm.method" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option v-for="m in METHODS" :key="m" :value="m">{{ t(m) }}</option>
            </select>
          </div>
          <div class="form-field"><label>{{ t('Reference') }}</label><input v-model="postForm.ref" placeholder="trx ID, cheque no…" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
          <div class="form-field"><label v-if="tab === 'receive' || tab === 'expense'">{{ t('Payee / Payer') }}</label><label v-else>{{ t('Note') }}</label><input v-model="postForm.payee" :placeholder="tab === 'receive' ? 'Who paid (tenant, client…)' : (tab === 'expense' ? 'Paid to (vendor, staff…)' : 'optional note')" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
          <div class="form-field" style="grid-column:1/-1"><label>{{ t('Date / time') }}</label><input v-model="postForm.date" type="datetime-local" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
        </div>
        <div style="display:flex;gap:8px;margin-top:16px">
          <button @click="submitPost" :disabled="postBusy" style="padding:11px 18px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:13px;cursor:pointer">{{ typeIco(tab) }} Post {{ typeLabelT(tab) }} {{ postBusy ? '…' : '' }}</button>
        </div>
      </div>

      <div class="panel" style="overflow:hidden">
        <div class="panel-h" style="padding:14px 18px"><div class="t"><span class="pi">🕓</span>Recent {{ typeLabelT(tab) }}s</div></div>
        <div class="tbl-wrap">
          <table class="kr">
            <thead><tr><th>ID</th><th>{{ t('Date') }}</th><th>{{ t('Account') }}</th><th>{{ t('Label') }}</th><th>{{ t('Payee') }}</th><th style="text-align:right">{{ t('Amount') }}</th><th>{{ t('Recon') }}</th></tr></thead>
            <tbody>
              <tr v-for="x in recent.filter(x => x.type === tab)" :key="x.id">
                <td style="font-weight:700">{{ x.id }}</td>
                <td class="c-sub">{{ x.tx_date }}</td>
                <td class="c-sub">{{ x.account_name || '—' }}</td>
                <td style="font-weight:600">{{ x.label }}</td>
                <td class="c-sub">{{ x.payee || '—' }}</td>
                <td style="text-align:right;font-weight:800" :style="{ color: typeColor(x.type) }">{{ sign(x.type) }} {{ money(x.amount) }}</td>
                <td><span class="badge" :class="reconBadge(x)">{{ x.reconciled == 1 ? '✓' : '—' }}</span></td>
              </tr>
              <tr v-if="!recent.filter(x => x.type === tab).length"><td colspan="7" style="text-align:center;color:var(--text-mute);padding:26px">No {{ typeLabelT(tab) }}s yet.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ══ RECONCILE ══ -->
    <template v-if="tab === 'reconcile'">
      <div class="stats">
        <div class="stat"><div class="s-label"><span class="s-ico">⏳</span>{{ t('Pending') }}</div><div class="s-value" :style="reconPending.length ? 'color:#f39c12' : ''">{{ reconPending.length }}</div></div>
        <div class="stat"><div class="s-label"><span class="s-ico">✅</span>{{ t('Reconciled') }}</div><div class="s-value" style="color:var(--ok,#12a150)">{{ reconDone.length }}</div></div>
      </div>

      <div class="panel" style="overflow:hidden;margin-top:14px">
        <div class="panel-h" style="padding:14px 18px"><div class="t"><span class="pi">⏳</span>{{ t('Pending reconciliation') }}</div></div>
        <div class="tbl-wrap">
          <table class="kr">
            <thead><tr><th>ID</th><th>{{ t('Date') }}</th><th>{{ t('Account') }}</th><th>{{ t('Type') }}</th><th>{{ t('Label') }}</th><th style="text-align:right">{{ t('Amount') }}</th><th>{{ t('Statement ref') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="x in reconPending" :key="x.id">
                <td style="font-weight:700">{{ x.id }}</td>
                <td class="c-sub">{{ x.tx_date }}</td>
                <td class="c-sub">{{ x.account_name || '—' }}</td>
                <td><span class="badge" :class="badge(x.type)">{{ typeLabelT(x.type) }}</span></td>
                <td style="font-weight:600">{{ x.label }}</td>
                <td style="text-align:right;font-weight:800" :style="{ color: typeColor(x.type) }">{{ sign(x.type) }} {{ money(x.amount) }}</td>
                <td style="width:200px"><input v-model="reconRefs[x.id]" placeholder="e.g. statement Jul 2026" style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg-alt);font-family:inherit;font-size:12.5px;color:var(--text);outline:none"></td>
                <td><button v-if="canWrite" @click="doReconcile(x)" :disabled="reconBusy" style="padding:7px 12px;border:none;border-radius:8px;background:var(--primary);color:#fff;font-weight:800;font-size:12px;cursor:pointer">✓ Reconcile</button></td>
              </tr>
              <tr v-if="!reconPending.length"><td colspan="8" style="text-align:center;color:var(--text-mute);padding:26px">Everything reconciled 🎉</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="panel" style="overflow:hidden;margin-top:14px">
        <div class="panel-h" style="padding:14px 18px"><div class="t"><span class="pi">✅</span>{{ t('Reconciled history') }}</div></div>
        <div class="tbl-wrap">
          <table class="kr">
            <thead><tr><th>ID</th><th>{{ t('Date') }}</th><th>{{ t('Account') }}</th><th>{{ t('Label') }}</th><th style="text-align:right">{{ t('Amount') }}</th><th>{{ t('Ref') }}</th><th>{{ t('Reconciled at') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="x in reconDone" :key="x.id">
                <td style="font-weight:700">{{ x.id }}</td>
                <td class="c-sub">{{ x.tx_date }}</td>
                <td class="c-sub">{{ x.account_name || '—' }}</td>
                <td style="font-weight:600">{{ x.label }}</td>
                <td style="text-align:right;font-weight:800" :style="{ color: typeColor(x.type) }">{{ sign(x.type) }} {{ money(x.amount) }}</td>
                <td class="c-sub">{{ x.reconciled_ref || '—' }}</td>
                <td class="c-sub">{{ x.reconciled_at }}</td>
                <td><button v-if="canWrite" @click="undoReconcile(x)" style="border:none;background:transparent;color:var(--text-mute);cursor:pointer;font-size:12.5px;font-weight:700">↩ Undo</button></td>
              </tr>
              <tr v-if="!reconDone.length"><td colspan="8" style="text-align:center;color:var(--text-mute);padding:26px">{{ t('Nothing reconciled yet.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- account create modal -->
    <div v-if="acctOpen" class="overlay" @click.self="acctOpen = false">
      <div class="modal">
        <div class="modal-h"><span class="t">＋ New account</span><button class="close" @click="acctOpen = false">✕</button></div>
        <div style="padding:18px 20px;display:flex;flex-direction:column;gap:12px">
          <div class="form-field"><label>Name *</label><input v-model="acctForm.name" placeholder="e.g. Bank Asia — Gulshan" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
          <div class="form-field"><label>{{ t('Type') }}</label>
            <select v-model="acctForm.type" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
              <option value="bank">🏦 Bank</option><option value="cash">💵 Cash</option><option value="mobile">📱 Mobile wallet</option>
            </select>
          </div>
          <div class="form-field"><label>Opening balance (৳)</label><input v-model="acctForm.opening_balance" type="number" min="0" placeholder="0" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
          <div class="form-field"><label>{{ t('Notes') }}</label><input v-model="acctForm.notes" placeholder="optional" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
        </div>
        <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
          <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="acctOpen = false">{{ t('Cancel') }}</button>
          <button class="btn-primary" style="padding:9px 16px;font-size:13px" :disabled="acctBusy" @click="createAcct">💾 Create {{ acctBusy ? '…' : '' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
