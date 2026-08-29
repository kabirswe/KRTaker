<script setup>
// Space Owner Portal (spec 3.8) — view-only login for space owners.
// Owners see ONLY their own shop: bills, dues, payment history, notices,
// and can file complaints. No access to any committee functions.
import { t, bnd } from '../lib/i18n'
import { ref, computed, onMounted } from 'vue'
import { money, monthLabel, badge } from '../lib/ui'

const API = './api/'

const tok = ref(localStorage.getItem('mm_owner_token') || '')
const loggedIn = computed(() => !!tok.value)
const me = ref(null)
const tab = ref('bills')
const month = ref(new Date().toISOString().slice(0, 7))
const err = ref('')
const busy = ref(false)

const TABS = [
  ['bills', '🧾', 'My Bills'], ['payments', '🕘', 'Payments'], ['complaints', '🔧', 'Report Issue'], ['notices', '📢', 'Notices'],
]

async function api(action, body = {}) {
  const res = await fetch(API + action, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...(tok.value ? { Authorization: 'Bearer ' + tok.value } : {}) },
    body: JSON.stringify(body),
  })
  return res.json()
}

const loginForm = ref({ email: '', password: '' })
async function doLogin() {
  err.value = ''; busy.value = true
  try {
    const r = await api('mall-owner-login', loginForm.value)
    if (r.ok && r.token) {
      tok.value = r.token
      localStorage.setItem('mm_owner_token', r.token)
      await loadMe()
    } else err.value = r.error || 'Login failed.'
  } catch (e) { err.value = 'Network error — try again.' }
  finally { busy.value = false }
}
function logout() { tok.value = ''; localStorage.removeItem('mm_owner_token'); me.value = null; loginForm.value = { email: '', password: '' } }

async function loadMe() {
  const r = await api('mall-owner', { action: 'me' })
  if (r.ok) me.value = r
  else if (r.error && String(r.error).includes('Session')) logout()
}

const bills = ref([])
const billTotals = ref({})
async function loadBills() { const r = await api('mall-owner', { action: 'bills', month: month.value }); if (r.ok) { bills.value = r.bills; billTotals.value = r.totals } }
const payments = ref([])
async function loadPayments() { const r = await api('mall-owner', { action: 'payments' }); if (r.ok) payments.value = r.payments }
const complaints = ref([])
async function loadComplaints() { const r = await api('mall-owner', { action: 'complaints' }); if (r.ok) complaints.value = r.complaints }
const notices = ref([])
async function loadNotices() { const r = await api('mall-owner', { action: 'notices' }); if (r.ok) notices.value = r.notices }

const compForm = ref({ subject: '', descr: '', priority: 'Normal' })
async function submitComplaint() {
  if (!compForm.value.subject.trim()) return
  const r = await api('mall-owner', { action: 'complaint-add', subject: compForm.value.subject, descr: compForm.value.descr, priority: compForm.value.priority })
  if (r.ok) { compForm.value = { subject: '', descr: '', priority: 'Normal' }; await loadComplaints() }
}

function switchTab(t) {
  tab.value = t
  if (t === 'bills') loadBills()
  if (t === 'payments') loadPayments()
  if (t === 'complaints') loadComplaints()
  if (t === 'notices') loadNotices()
}

onMounted(() => { if (tok.value) loadMe() })
</script>

<template>
  <!-- ═══════ LOGIN ═══════ -->
  <div v-if="!loggedIn" style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:linear-gradient(150deg,#0f172a 0%,#1e3a5f 55%,#14532d 130%)">
    <div style="width:100%;max-width:400px;background:var(--card,#fff);border-radius:20px;padding:30px 28px;box-shadow:0 30px 80px rgba(0,0,0,.35)">
      <div style="text-align:center;margin-bottom:22px">
        <div style="width:58px;height:58px;border-radius:16px;background:linear-gradient(135deg,#2F80ED,#27AE60);color:#fff;font-size:26px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px">🏬</div>
        <div style="font-size:19px;font-weight:800">{{ t('Space Owner Portal') }}</div>
        <div style="font-size:12.5px;color:var(--text-mute,#5b6b83);margin-top:4px">{{ t('Razzak Plaza — view your bills, dues &amp; payments') }}</div>
      </div>
      <div v-if="err" style="background:rgba(235,87,87,.1);border:1px solid rgba(235,87,87,.35);color:#c0392b;font-size:12.5px;padding:10px 14px;border-radius:10px;margin-bottom:14px">⚠️ {{ err }}</div>
      <label style="font-size:12px;color:var(--text-mute,#5b6b83);display:block">{{ t('Email (as registered with the committee)') }}
        <input v-model="loginForm.email" type="email" placeholder="you@example.com" style="width:100%;margin-top:5px;padding:12px;border-radius:12px;border:1px solid var(--border,#e5e7eb);background:var(--bg-alt,#f6f9fe);font-family:inherit;font-size:14px" />
      </label>
      <label style="font-size:12px;color:var(--text-mute,#5b6b83);display:block;margin-top:12px">{{ t('Password') }}
        <input v-model="loginForm.password" type="password" placeholder="••••••••" @keydown.enter="doLogin" style="width:100%;margin-top:5px;padding:12px;border-radius:12px;border:1px solid var(--border,#e5e7eb);background:var(--bg-alt,#f6f9fe);font-family:inherit;font-size:14px" />
      </label>
      <button @click="doLogin" :disabled="busy" style="width:100%;margin-top:18px;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#2F80ED,#27AE60);color:#fff;font-size:14.5px;font-weight:800;cursor:pointer">{{ busy ? t('Signing in…') : t('🔐 Sign in') }}</button>
      <div style="text-align:center;font-size:11.5px;color:var(--text-mute,#5b6b83);margin-top:16px">{{ t('Demo:') }} <b>rahim@razzakplaza.com</b> / owner1234<br />{{ t('Committee staff?') }} <a href="#/" style="color:#2F80ED;font-weight:700">{{ t('System login →') }}</a></div>
    </div>
  </div>

  <!-- ═══════ PORTAL HOME ═══════ -->
  <div v-else style="min-height:100vh;background:var(--bg,#f4f7fb)">
    <header style="position:sticky;top:0;z-index:40;background:var(--card,#fff);border-bottom:1px solid var(--border,#e5e7eb);padding:14px 18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <div style="width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#2F80ED,#27AE60);color:#fff;font-size:18px;display:flex;align-items:center;justify-content:center">🏬</div>
      <div style="flex:1;min-width:140px">
        <div style="font-weight:800;font-size:14.5px">{{ me?.shop?.no || '' }} <span style="color:var(--text-mute)">· {{ me?.shop?.floor || '' }} floor</span></div>
        <div style="font-size:12px;color:var(--text-mute,#5b6b83)">{{ me?.shop?.owner_name || '' }} · {{ me?.owner?.email || '' }}</div>
      </div>
      <button @click="logout" style="padding:8px 14px;border:1px solid var(--border,#e5e7eb);border-radius:10px;background:var(--bg-alt,#f6f9fe);font-weight:700;font-size:12.5px;cursor:pointer;color:var(--text)">{{ t('🚪 Log out') }}</button>
    </header>

    <main style="max-width:760px;margin:0 auto;padding:18px 16px 40px">
      <!-- dues summary -->
      <div v-if="me" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px">
        <div v-for="(k, key) in { service: '🧾 Service', elec: '⚡ Electricity', water: '💧 Water' }" :key="key" style="background:var(--card,#fff);border:1px solid var(--border,#e5e7eb);border-radius:14px;padding:13px 12px">
          <div style="font-size:11px;color:var(--text-mute,#5b6b83);font-weight:700">{{ k }}</div>
          <div style="font-size:16.5px;font-weight:800;margin-top:4px" :style="Number(me.current[key].outstanding) > 0 ? 'color:#c0392b' : 'color:#1e8449'">{{ money(me.current[key].outstanding) }}</div>
          <div style="font-size:10.5px;color:var(--text-mute,#5b6b83);margin-top:2px">due of {{ money(me.current[key].billed) }}</div>
        </div>
      </div>
      <div v-if="me && Number(me.current.service.outstanding) + Number(me.current.elec.outstanding) + Number(me.current.water.outstanding) > 0" style="background:rgba(235,87,87,.08);border:1px solid rgba(235,87,87,.3);border-radius:12px;padding:11px 14px;font-size:12.5px;color:#922b21;margin-bottom:16px">
        ⏳ You have outstanding dues for {{ monthLabel(month) }}. Pay at the committee office (cash / bank / bKash / Nagad) — receipt will be issued instantly.
      </div>

      <!-- tabs -->
      <div style="display:flex;gap:8px;margin-bottom:14px;overflow-x:auto;padding-bottom:4px">
        <button v-for="[id, ico, label] in TABS" :key="id" @click="switchTab(id)"
          :style="tab === id ? 'background:#2F80ED;color:#fff' : 'background:var(--card,#fff);color:var(--text-mute,#5b6b83)'"
          style="padding:9px 15px;border:1px solid var(--border,#e5e7eb);border-radius:999px;font-size:12.5px;font-weight:800;cursor:pointer;white-space:nowrap">{{ ico }} {{ t(label) }}</button>
      </div>

      <!-- ═══ MY BILLS ═══ -->
      <template v-if="tab === 'bills'">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
          <button @click="month = new Date(new Date(month + '-01').setMonth(new Date(month + '-01').getMonth() - 1)).toISOString().slice(0,7); loadBills()" style="border:1px solid var(--border);background:var(--card);border-radius:10px;padding:7px 11px;cursor:pointer">◀</button>
          <b style="font-size:13.5px">{{ monthLabel(month) }}</b>
          <button @click="month = new Date(new Date(month + '-01').setMonth(new Date(month + '-01').getMonth() + 1)).toISOString().slice(0,7); loadBills()" style="border:1px solid var(--border);background:var(--card);border-radius:10px;padding:7px 11px;cursor:pointer">▶</button>
          <span style="margin-left:auto;font-size:12px;color:var(--text-mute)">Due {{ money(billTotals.outstanding) }} · Paid {{ money(billTotals.paid) }}</span>
        </div>
        <div v-for="b in bills" :key="b.id" style="background:var(--card,#fff);border:1px solid var(--border,#e5e7eb);border-radius:14px;padding:14px 16px;margin-bottom:10px;display:flex;align-items:center;gap:12px">
          <div style="font-size:20px">{{ { service: '🧾', elec: '⚡', water: '💧' }[b.kind] || '💰' }}</div>
          <div style="flex:1">
            <div style="font-weight:800;font-size:13.5px">{{ { service: 'Service charge', elec: 'Electricity', water: 'Water' }[b.kind] }}</div>
            <div style="font-size:11.5px;color:var(--text-mute)">Bill #{{ b.id }} · due {{ b.due_date }}<span v-if="b.fine" style="color:#c0392b"> · late fee {{ money(b.fine) }}</span></div>
          </div>
          <div style="text-align:right">
            <div style="font-weight:800;font-size:14px">{{ money(b.amount) }}</div>
            <span class="badge" :class="badge(b.status)" style="margin-top:3px">{{ bnd(b.status) }}</span>
          </div>
        </div>
        <div v-if="!bills.length" style="text-align:center;color:var(--text-mute);padding:30px">No bills for {{ monthLabel(month) }}.</div>
      </template>

      <!-- ═══ PAYMENTS ═══ -->
      <template v-if="tab === 'payments'">
        <div v-for="p in payments" :key="p.id" style="background:var(--card,#fff);border:1px solid var(--border,#e5e7eb);border-radius:14px;padding:13px 16px;margin-bottom:10px;display:flex;align-items:center;gap:12px">
          <div style="font-size:18px">📎</div>
          <div style="flex:1">
            <div style="font-weight:800;font-size:13.5px">{{ p.receipt }}</div>
            <div style="font-size:11.5px;color:var(--text-mute)">{{ p.month }} · {{ { service: 'Service', elec: 'Electricity', water: 'Water' }[p.kind] }} · {{ p.method }}<span v-if="p.ref"> ({{ p.ref }})</span></div>
          </div>
          <div style="font-weight:800;font-size:14px;color:#1e8449">{{ money(p.amount) }}</div>
        </div>
        <div v-if="!payments.length" style="text-align:center;color:var(--text-mute);padding:30px">{{ t('No payments yet.') }}</div>
      </template>

      <!-- ═══ COMPLAINTS ═══ -->
      <template v-if="tab === 'complaints'">
        <div style="background:var(--card,#fff);border:1px solid var(--border,#e5e7eb);border-radius:14px;padding:16px;margin-bottom:14px">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">{{ t('🔧 Report an issue (lift / AC / light / water…)') }}</div>
          <input v-model="compForm.subject" :placeholder="t('Subject — e.g. AC not cooling')" style="width:100%;padding:11px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);font-family:inherit;font-size:13px;margin-bottom:8px" />
          <textarea v-model="compForm.descr" rows="2" placeholder="Details…" style="width:100%;padding:11px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);font-family:inherit;font-size:13px;resize:vertical;margin-bottom:8px"></textarea>
          <div style="display:flex;gap:10px;align-items:center">
            <select v-model="compForm.priority" style="padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);font-family:inherit;font-size:13px">
              <option v-for="p in ['Low', 'Normal', 'High', 'Urgent']" :key="p" :value="p">{{ p }}</option>
            </select>
            <button @click="submitComplaint" style="padding:10px 18px;border:none;border-radius:10px;background:#2F80ED;color:#fff;font-size:13px;font-weight:800;cursor:pointer">{{ t('📨 Submit') }}</button>
          </div>
        </div>
        <div v-for="c in complaints" :key="c.id" style="background:var(--card,#fff);border:1px solid var(--border,#e5e7eb);border-radius:14px;padding:13px 16px;margin-bottom:10px">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <b style="font-size:13.5px">{{ c.subject }}</b>
            <span class="badge" :class="badge(c.status)">{{ bnd(c.status) }}</span>
            <span class="badge" :class="{ Low: 'b-gray', Normal: 'b-blue', High: 'b-orange', Urgent: 'b-red' }[c.priority]">{{ c.priority }}</span>
          </div>
          <div v-if="c.descr" style="font-size:12.5px;color:var(--text-mute);margin-top:6px">{{ c.descr }}</div>
          <div style="font-size:11px;color:var(--text-mute);margin-top:8px">#{{ c.id }} · opened {{ (c.opened_at || '').slice(0, 10) }}<span v-if="c.resolved_at"> · resolved {{ (c.resolved_at || '').slice(0, 10) }}</span></div>
        </div>
        <div v-if="!complaints.length" style="text-align:center;color:var(--text-mute);padding:24px">{{ t('No complaints filed.') }}</div>
      </template>

      <!-- ═══ NOTICES ═══ -->
      <template v-if="tab === 'notices'">
        <div v-for="n in notices" :key="n.id" style="background:var(--card,#fff);border:1px solid var(--border,#e5e7eb);border-radius:14px;padding:15px 16px;margin-bottom:10px;border-left:3px solid" :style="n.pinned ? 'border-left-color:#2F80ED' : 'border-left-color:#e5e7eb'">
          <div style="font-weight:800;font-size:13.5px">{{ n.pinned ? '📌' : '📢' }} {{ n.title }}</div>
          <div style="font-size:12.5px;color:var(--text);margin-top:6px;white-space:pre-wrap">{{ n.body }}</div>
          <div style="font-size:11px;color:var(--text-mute);margin-top:8px">{{ n.date }} · by {{ n.author }}</div>
        </div>
        <div v-if="!notices.length" style="text-align:center;color:var(--text-mute);padding:24px">{{ t('No notices.') }}</div>
      </template>

      <div style="text-align:center;font-size:11px;color:var(--text-mute);margin-top:26px">Mall Manager — Space Owner Portal · payments are collected at the committee office · receipt issued instantly</div>
    </main>
  </div>
</template>
