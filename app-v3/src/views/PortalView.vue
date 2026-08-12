<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import { apiCall, apiBlob } from '../api/client'
import { lang, t } from '../lib/i18n'
import { money, fmtTs, badge, monthLabel } from '../lib/ui'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const data = useDataStore()

// ── State ──
const loading = ref(true)
const err = ref('')
const portal = ref(null)
const tid = ref('')              // selected tenant (staff picker or ?t=)
const staffMode = computed(() => !['tenant', 'partner'].includes(auth.user?.role) && !auth.isImpersonating)
const isTenantView = computed(() => auth.user?.role === 'tenant' || auth.isImpersonating)

// ── Load ──
async function load() {
  loading.value = true; err.value = ''
  try {
    const q = tid.value ? '?t=' + encodeURIComponent(tid.value) : ''
    const r = await apiCall('app-portal' + q)
    if (!r.ok) { err.value = r.error || 'Failed to load portal.'; portal.value = null; return }
    portal.value = r
  } catch (e) { err.value = e.message || 'Network error.' }
  finally { loading.value = false }
}

function pickTenant(id) { tid.value = id; if (id) load() }

onMounted(async () => {
  if (route.query.t) tid.value = String(route.query.t)
  // staff (owner/manager/superadmin) need a tenant id — default to first tenant if none passed
  if (!tid.value && staffMode.value && data.list('tenants').length) tid.value = String(data.list('tenants')[0].id)
  await load()
})

// ── Derived ──
const tenant = computed(() => portal.value?.tenant || null)
const leases = computed(() => portal.value?.leases || [])
const invoices = computed(() => portal.value?.invoices || [])
const payments = computed(() => portal.value?.payments || [])
const receipts = computed(() => portal.value?.receipts || [])
const docs = computed(() => portal.value?.docs || [])
const tickets = computed(() => portal.value?.tickets || [])
const notices = computed(() => portal.value?.notices || [])
const stats = computed(() => portal.value?.stats || {})
const renewals = computed(() => portal.value?.renewals || [])
const meters = computed(() => portal.value?.meters || [])
const utilityBills = computed(() => portal.value?.utility_bills || [])
const settlement = computed(() => portal.value?.settlement || null)
const score = computed(() => portal.value?.score || null)

const activeLease = computed(() => leases.value.find(l => ['Active', 'Pending Registration'].includes(l.status)) || leases.value[0] || null)
const totalOutstanding = computed(() => stats.value.outstanding ?? invoices.value.reduce((a, i) => a + (i.due || 0), 0))
const paidTotal = computed(() => stats.value.paid_total ?? payments.value.reduce((a, p) => a + (p.amount || 0), 0))
const openTickets = computed(() => tickets.value.filter(x => x.status !== 'Closed'))
const dueInvoices = computed(() => invoices.value.filter(i => (i.due || 0) > 0))
const recentPayments = computed(() => [...payments.value].sort((a, b) => String(b.date || '').localeCompare(String(a.date || ''))).slice(0, 8))

function leasePct(l) { return Math.max(0, Math.min(100, l.pct ?? 0)) }
function daysLabel(d) {
  if (d === null || d === undefined) return '—'
  if (d < 0) return lang.value === 'bn' ? 'মেয়াদ শেষ' : 'expired'
  if (d === 0) return lang.value === 'bn' ? 'আজ' : 'today'
  if (d === 1) return lang.value === 'bn' ? '১ দিন বাকি' : '1 day left'
  return lang.value === 'bn' ? `${d} দিন বাকি` : `${d} days left`
}
const invStatusBadge = (st) => badge(st || 'Unpaid')
const ticketStatus = (s) => s === 'Closed' ? { c: 'b-green', l: lang.value === 'bn' ? 'বন্ধ' : 'Closed' } : { c: 'b-orange', l: s || 'Open' }

async function downloadAgreement(leaseId) {
  try {
    const url = await apiBlob('app-portal-agreement?lease=' + encodeURIComponent(leaseId))
    if (!url) { window.__krToast?.('Failed to load agreement.', 'error'); return }
    window.open(url, '_blank')
  } catch (e) { window.__krToast?.('Failed to load agreement.', 'error') }
}
function goPay(inv) {
  router.push({ path: '/invoices', query: { open: inv.id, pay: '1' } })
}
function goTickets() { router.push('/maintenance') }
function goNotices() { router.push('/notices') }
function goDocs() { router.push('/documents') }
function refresh() { load() }

const statCards = computed(() => [
  { label: lang.value === 'bn' ? 'বকেয়া' : 'Outstanding', ico: '💳', val: money(totalOutstanding.value), tone: totalOutstanding.value > 0 ? 'warn' : 'ok' },
  { label: lang.value === 'bn' ? 'মোট পরিশোধিত' : 'Total paid', ico: '✅', val: money(paidTotal.value), tone: 'ok' },
  { label: lang.value === 'bn' ? 'পরবর্তী বিল' : 'Next due', ico: '📅', val: stats.value.next_due ? money(stats.value.next_due.amount) + ' · ' + (stats.value.next_due.m || '') : '—', tone: stats.value.next_due ? 'warn' : 'mute' },
  { label: lang.value === 'bn' ? 'খোলা টিকেট' : 'Open tickets', ico: '🔧', val: String(stats.value.tickets_open ?? openTickets.value.length), tone: openTickets.value.length ? 'warn' : 'ok' },
  { label: lang.value === 'bn' ? 'লিজে দিন বাকি' : 'Lease days left', ico: '⏳', val: stats.value.min_days_left === null || stats.value.min_days_left === undefined ? '—' : String(stats.value.min_days_left), tone: 'mute' },
])

const bandTone = (c) => ({ 'b-green': 'ok', 'b-blue': 'info', 'b-orange': 'warn', 'b-red': 'danger' }[c] || 'mute')
const factorRows = computed(() => {
  const f = score.value?.factors || {}
  const map = [
    ['payment', lang.value === 'bn' ? 'পেমেন্ট' : 'Payment'],
    ['stability', lang.value === 'bn' ? 'স্থিতিশীলতা' : 'Stability'],
    ['care', lang.value === 'bn' ? 'যত্ন' : 'Care'],
    ['compliance', lang.value === 'bn' ? 'কমপ্লায়েন্স' : 'Compliance'],
  ]
  return map.map(([k, label]) => ({ label, v: f[k] ?? 0 }))
})
const settleRows = computed(() => {
  if (!settlement.value) return []
  const s = settlement.value
  return [
    { k: lang.value === 'bn' ? 'ডিপোজিট' : 'Deposit', v: money(s.deposit || 0) },
    { k: lang.value === 'bn' ? 'ভাড়া' : 'Rent', v: money(s.rent || 0) },
    { k: lang.value === 'bn' ? 'ইউটিলিটি' : 'Utility', v: money(s.utility || 0) },
    { k: lang.value === 'bn' ? 'ক্ষতি' : 'Damages', v: money(s.damages || 0) },
    { k: lang.value === 'bn' ? 'মোট বকেয়া' : 'Total due', v: money(s.total_due || 0) },
    { k: lang.value === 'bn' ? 'ব্যালান্স' : 'Balance', v: money(s.balance || 0) },
    { k: lang.value === 'bn' ? 'রিফান্ড' : 'Refund', v: money(s.refund || 0) },
  ]
})
const meterType = (t) => ({ electricity: '⚡', gas: '🔥', water: '💧' }[t] || '📟') + ' ' + (t || '')
const utType = (t) => ({ electricity: '⚡', gas: '🔥', water: '💧', common: '🏢' }[t] || '📟') + ' ' + (t || '')
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ lang === 'bn' ? '🏠 আমার পোর্টাল' : '🏠 My Portal' }}</h1>
        <div class="sub">
          <template v-if="loading">{{ lang === 'bn' ? 'লোড হচ্ছে…' : 'Loading…' }}</template>
          <template v-else-if="err">—</template>
          <template v-else-if="tenant">
            {{ lang === 'bn' ? `${tenant.name}, আপনার ভাড়া, লিজ ও টিকেট এক জায়গায়।` : `${tenant.name}, everything about your tenancy in one place.` }}
          </template>
        </div>
      </div>
      <div class="head-actions">
        <button class="btn-ghost" @click="refresh" title="Refresh">🔄</button>
        <span v-if="score" class="badge" :class="score.band_color">{{ score.band }} · {{ score.score }}/100</span>
      </div>
    </div>

    <div v-if="err" class="auth-err show" style="margin-bottom:14px">{{ err }}</div>

    <!-- Staff tenant picker (owner/manager/superadmin) -->
    <div v-if="staffMode && !auth.isImpersonating" class="panel" style="margin-bottom:16px">
      <div class="panel-h"><div class="t"><span class="pi">🔍</span>{{ lang === 'bn' ? 'ভাড়াটিয়া নির্বাচন' : 'Select tenant' }}</div></div>
      <div class="panel-b" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <select :value="tid" @change="pickTenant($event.target.value)"
          style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13.5px;min-width:260px">
          <option v-for="tn in data.list('tenants')" :key="tn.id" :value="String(tn.id)">{{ tn.name }} · {{ tn.phone || '' }}</option>
        </select>
        <span class="c-sub" style="font-size:12px">{{ lang === 'bn' ? 'পোর্টাল দেখতে ভাড়াটিয়া বাছাই করুন' : 'Pick a tenant to view their portal' }}</span>
      </div>
    </div>

    <template v-if="portal">
      <!-- Stat cards -->
      <div class="grid grid-4" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px">
        <div v-for="(c, i) in statCards" :key="i" class="panel" style="padding:14px 16px">
          <div style="font-size:11.5px;font-weight:800;color:var(--text-mute);text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:6px">
            <span>{{ c.ico }}</span>{{ c.label }}
          </div>
          <div style="font-size:19px;font-weight:800;margin-top:6px" :style="c.tone === 'warn' ? 'color:var(--warn)' : c.tone === 'ok' ? 'color:var(--ok, #1e8e4d)' : ''">{{ c.val }}</div>
        </div>
      </div>

      <div class="grid grid-2">
        <!-- Active lease -->
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">📄</span>{{ lang === 'bn' ? 'আমার লিজ' : 'My lease' }}</div></div>
          <div class="panel-b">
            <template v-if="activeLease">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
                <div>
                  <div style="font-weight:800;font-size:15px">
                    {{ activeLease.property?.name || '—' }}
                    <span v-if="activeLease.unit?.name" style="color:var(--text-mute);font-weight:600"> · {{ activeLease.unit.name }}{{ activeLease.unit.floor ? ' (Floor ' + activeLease.unit.floor + ')' : '' }}</span>
                  </div>
                  <div class="c-sub" style="font-size:12.5px;margin-top:2px">
                    {{ lang === 'bn' ? 'ভাড়া' : 'Rent' }}: <b>{{ money(activeLease.rent) }}</b>/{{ lang === 'bn' ? 'মাস' : 'mo' }}
                    <span v-if="activeLease.adv"> · {{ lang === 'bn' ? 'অগ্রিম' : 'Advance' }} {{ money(activeLease.adv) }}</span>
                  </div>
                  <div class="c-sub" style="font-size:12px;margin-top:2px">
                    {{ activeLease.start || '—' }} → {{ activeLease.end || '—' }}
                  </div>
                </div>
                <span class="badge" :class="badge(activeLease.status)">{{ activeLease.status }}</span>
              </div>
              <!-- progress -->
              <div style="margin-top:14px">
                <div style="display:flex;justify-content:space-between;font-size:11.5px;color:var(--text-mute);margin-bottom:5px">
                  <span>{{ lang === 'bn' ? 'লিজ অগ্রগতি' : 'Lease progress' }}</span>
                  <span>{{ leasePct(activeLease) }}% · {{ daysLabel(activeLease.days_left) }}</span>
                </div>
                <div style="height:7px;border-radius:99px;background:var(--bg-alt);overflow:hidden">
                  <div :style="{ width: leasePct(activeLease) + '%', height: '100%', background: 'var(--grad)', borderRadius: '99px' }"></div>
                </div>
                <div v-if="activeLease.reg_pending" style="margin-top:10px;font-size:12px;color:var(--warn);font-weight:700">
                  ⚠️ {{ lang === 'bn' ? 'রেজিস্ট্রেশন বাকি আছে' : 'Registration pending' }}
                </div>
              </div>
              <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
                <button class="btn-ghost" style="padding:7px 12px;font-size:12.5px" @click="downloadAgreement(activeLease.id)">
                  📄 {{ lang === 'bn' ? 'লিজ চুক্তি ডাউনলোড' : 'Download agreement' }}
                </button>
                <button class="btn-ghost" style="padding:7px 12px;font-size:12.5px" @click="goTickets()">
                  🔧 {{ lang === 'bn' ? 'টিকেট' : 'Tickets' }}
                </button>
              </div>
            </template>
            <div v-else class="c-sub" style="font-size:13px">{{ lang === 'bn' ? 'কোনো সক্রিয় লিজ নেই।' : 'No active lease.' }}</div>
          </div>
        </div>

        <!-- Scorecard -->
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">🏅</span>{{ lang === 'bn' ? 'রেন্টার স্কোর' : 'Renter score' }}</div></div>
          <div class="panel-b">
            <template v-if="score">
              <div style="display:flex;align-items:center;gap:16px">
                <div style="width:76px;height:76px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:900;flex-shrink:0;border:4px solid"
                  :style="'border-color:' + (score.band_color === 'b-green' ? '#27ae60' : score.band_color === 'b-blue' ? '#2f80ed' : score.band_color === 'b-orange' ? '#e67e22' : '#e74c3c')">
                  {{ score.score }}
                </div>
                <div style="flex:1;min-width:0">
                  <div style="font-weight:800;font-size:15px">{{ score.band }}</div>
                  <div class="c-sub" style="font-size:12px;margin-top:2px">
                    {{ lang === 'bn' ? 'রেন্টার ঝুঁকি মূল্যায়ন — বাড়িওয়ালা ভেটিং' : 'Renter risk assessment — landlord vetting' }}
                  </div>
                </div>
              </div>
              <div style="margin-top:12px;display:flex;flex-direction:column;gap:7px">
                <div v-for="fr in factorRows" :key="fr.label" style="display:flex;align-items:center;gap:8px">
                  <span style="font-size:12px;font-weight:700;width:92px;flex-shrink:0">{{ fr.label }}</span>
                  <div style="flex:1;height:6px;border-radius:99px;background:var(--bg-alt);overflow:hidden">
                    <div :style="{ width: fr.v + '%', height: '100%', background: fr.v >= 70 ? '#27ae60' : fr.v >= 45 ? '#e67e22' : '#e74c3c', borderRadius: '99px' }"></div>
                  </div>
                  <span style="font-size:11.5px;color:var(--text-mute);width:30px;text-align:right">{{ fr.v }}</span>
                </div>
              </div>
              <div v-if="score.tips && score.tips.length" style="margin-top:12px;font-size:12px;color:var(--text-mute);line-height:1.55">
                <div v-for="(tip, ti) in score.tips.slice(0, 2)" :key="ti">💡 {{ tip }}</div>
              </div>
            </template>
            <div v-else class="c-sub" style="font-size:13px">{{ lang === 'bn' ? 'স্কোর পাওয়া যায়নি।' : 'Score unavailable.' }}</div>
          </div>
        </div>
      </div>

      <!-- Due invoices -->
      <div class="panel" style="margin-top:16px">
        <div class="panel-h">
          <div class="t"><span class="pi">🧾</span>{{ lang === 'bn' ? 'বকেয়া ইনভয়েস' : 'Due invoices' }}</div>
          <div style="margin-left:auto"><span class="badge" :class="dueInvoices.length ? 'b-orange' : 'b-green'">{{ dueInvoices.length }}</span></div>
        </div>
        <div class="panel-b" style="padding:0">
          <div class="tbl-wrap">
            <table class="tbl">
              <thead>
                <tr>
                  <th>{{ t('Invoice') }}</th>
                  <th>{{ t('Month') }}</th>
                  <th>{{ t('Net') }}</th>
                  <th>{{ t('Paid') }}</th>
                  <th>{{ t('Due') }}</th>
                  <th>{{ t('Status') }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="i in invoices" :key="i.id">
                  <td class="mono">{{ i.id }}</td>
                  <td>{{ monthLabel(i.m) }}</td>
                  <td>{{ money(i.net) }}</td>
                  <td>{{ money(i.paid) }}</td>
                  <td><b :style="(i.due || 0) > 0 ? 'color:var(--warn)' : ''">{{ money(i.due) }}</b></td>
                  <td><span class="badge" :class="invStatusBadge(i.status)">{{ i.status }}</span></td>
                  <td style="text-align:right">
                    <button v-if="(i.due || 0) > 0" class="btn-ghost" style="padding:5px 11px;font-size:11.5px" @click="goPay(i)">
                      💳 {{ lang === 'bn' ? 'পরিশোধ' : 'Pay' }}
                    </button>
                  </td>
                </tr>
                <tr v-if="!invoices.length">
                  <td colspan="7" style="text-align:center;color:var(--text-mute);font-size:13px;padding:22px">{{ lang === 'bn' ? 'কোনো ইনভয়েস নেই।' : 'No invoices.' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="grid grid-2" style="margin-top:16px">
        <!-- Recent payments -->
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">💳</span>{{ lang === 'bn' ? 'সাম্প্রতিক পেমেন্ট' : 'Recent payments' }}</div></div>
          <div class="panel-b" style="padding:0">
            <div v-if="recentPayments.length" style="display:flex;flex-direction:column">
              <div v-for="p in recentPayments" :key="p.id" style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border)">
                <div style="width:30px;height:30px;border-radius:50%;background:var(--bg-alt);display:flex;align-items:center;justify-content:center;flex-shrink:0">✅</div>
                <div style="flex:1;min-width:0">
                  <div style="font-weight:700;font-size:13px">{{ p.inv }} <span class="c-sub" style="font-weight:600">· {{ p.method || '' }}</span></div>
                  <div class="c-sub" style="font-size:11.5px">{{ fmtTs(p.date) }}</div>
                </div>
                <div style="font-weight:800;font-size:13.5px">{{ money(p.amount) }}</div>
              </div>
            </div>
            <div v-else style="padding:22px;text-align:center;color:var(--text-mute);font-size:13px">{{ lang === 'bn' ? 'কোনো পেমেন্ট নেই।' : 'No payments yet.' }}</div>
          </div>
        </div>

        <!-- Open tickets -->
        <div class="panel">
          <div class="panel-h">
            <div class="t"><span class="pi">🔧</span>{{ lang === 'bn' ? 'মেইনটেন্যান্স টিকেট' : 'Maintenance tickets' }}</div>
            <button class="btn-ghost" style="margin-left:auto;padding:5px 11px;font-size:11.5px" @click="goTickets()">{{ lang === 'bn' ? 'সব দেখুন' : 'View all' }} →</button>
          </div>
          <div class="panel-b" style="padding:0">
            <div v-if="tickets.length" style="display:flex;flex-direction:column">
              <div v-for="tk in tickets" :key="tk.id" style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border)">
                <div style="width:30px;height:30px;border-radius:50%;background:var(--bg-alt);display:flex;align-items:center;justify-content:center;flex-shrink:0">{{ tk.status === 'Closed' ? '✅' : '🛠️' }}</div>
                <div style="flex:1;min-width:0">
                  <div style="font-weight:700;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ tk.desc || tk.id }}</div>
                  <div class="c-sub" style="font-size:11.5px">{{ tk.id }} · {{ fmtTs(tk.reported) }}</div>
                </div>
                <span class="badge" :class="ticketStatus(tk.status).c">{{ ticketStatus(tk.status).l }}</span>
              </div>
            </div>
            <div v-else style="padding:22px;text-align:center;color:var(--text-mute);font-size:13px">{{ lang === 'bn' ? 'কোনো টিকেট নেই 🎉' : 'No tickets 🎉' }}</div>
          </div>
        </div>
      </div>

      <div class="grid grid-2" style="margin-top:16px">
        <!-- Notices -->
        <div class="panel">
          <div class="panel-h">
            <div class="t"><span class="pi">📢</span>{{ lang === 'bn' ? 'নোটিশ' : 'Notices' }}</div>
            <button class="btn-ghost" style="margin-left:auto;padding:5px 11px;font-size:11.5px" @click="goNotices()">{{ lang === 'bn' ? 'সব দেখুন' : 'View all' }} →</button>
          </div>
          <div class="panel-b" style="padding:0">
            <div v-if="notices.length" style="display:flex;flex-direction:column">
              <div v-for="n in notices" :key="n.id" style="padding:11px 16px;border-bottom:1px solid var(--border)">
                <div style="font-weight:700;font-size:13px">{{ n.title }}</div>
                <div class="c-sub" style="font-size:11.5px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ n.body }}</div>
                <div class="c-sub" style="font-size:11px;margin-top:3px">{{ fmtTs(n.ts) }}</div>
              </div>
            </div>
            <div v-else style="padding:22px;text-align:center;color:var(--text-mute);font-size:13px">{{ lang === 'bn' ? 'কোনো নোটিশ নেই।' : 'No notices.' }}</div>
          </div>
        </div>

        <!-- Documents -->
        <div class="panel">
          <div class="panel-h">
            <div class="t"><span class="pi">📁</span>{{ lang === 'bn' ? 'ডকুমেন্ট' : 'Documents' }}</div>
            <button class="btn-ghost" style="margin-left:auto;padding:5px 11px;font-size:11.5px" @click="goDocs()">{{ lang === 'bn' ? 'সব দেখুন' : 'View all' }} →</button>
          </div>
          <div class="panel-b" style="padding:0">
            <div v-if="docs.length" style="display:flex;flex-direction:column">
              <div v-for="d in docs.slice(0, 5)" :key="d.id" style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border)">
                <div style="font-size:16px;flex-shrink:0">📄</div>
                <div style="flex:1;min-width:0">
                  <div style="font-weight:700;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ d.name }}</div>
                  <div class="c-sub" style="font-size:11.5px">{{ d.kind || 'doc' }} · {{ fmtTs(d.ts) }}</div>
                </div>
              </div>
            </div>
            <div v-else style="padding:22px;text-align:center;color:var(--text-mute);font-size:13px">{{ lang === 'bn' ? 'কোনো ডকুমেন্ট নেই।' : 'No documents.' }}</div>
          </div>
        </div>
      </div>

      <!-- Utility + meters -->
      <div class="grid grid-2" style="margin-top:16px">
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">🧾</span>{{ lang === 'bn' ? 'ইউটিলিটি বিল' : 'Utility bills' }}</div></div>
          <div class="panel-b" style="padding:0">
            <div class="tbl-wrap">
              <table class="tbl">
                <thead>
                  <tr>
                    <th>{{ t('Type') }}</th>
                    <th>{{ t('Month') }}</th>
                    <th>{{ t('Usage') }}</th>
                    <th>{{ t('Amount') }}</th>
                    <th>{{ t('Status') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="u in utilityBills" :key="u.id">
                    <td>{{ utType(u.type) }}</td>
                    <td>{{ u.month }}</td>
                    <td>{{ u.usage ?? '—' }}</td>
                    <td>{{ money(u.amount) }}</td>
                    <td><span class="badge" :class="badge(u.status)">{{ u.status }}</span></td>
                  </tr>
                  <tr v-if="!utilityBills.length">
                    <td colspan="5" style="text-align:center;color:var(--text-mute);font-size:13px;padding:20px">{{ lang === 'bn' ? 'কোনো ইউটিলিটি বিল নেই।' : 'No utility bills.' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">📏</span>{{ lang === 'bn' ? 'মিটার রিডিং' : 'Meter readings' }}</div></div>
          <div class="panel-b" style="padding:0">
            <div class="tbl-wrap">
              <table class="tbl">
                <thead>
                  <tr>
                    <th>{{ t('Type') }}</th>
                    <th>{{ t('Month') }}</th>
                    <th>{{ t('Reading') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="m in meters" :key="m.id">
                    <td>{{ meterType(m.type) }}</td>
                    <td>{{ m.month }}</td>
                    <td>{{ m.reading }}</td>
                  </tr>
                  <tr v-if="!meters.length">
                    <td colspan="3" style="text-align:center;color:var(--text-mute);font-size:13px;padding:20px">{{ lang === 'bn' ? 'কোনো রিডিং নেই।' : 'No readings.' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Renewals + Settlement -->
      <div class="grid grid-2" style="margin-top:16px">
        <div class="panel">
          <div class="panel-h"><div class="t"><span class="pi">🔄</span>{{ lang === 'bn' ? 'নবায়ন অনুরোধ' : 'Renewal requests' }}</div></div>
          <div class="panel-b" style="padding:0">
            <div v-if="renewals.length" style="display:flex;flex-direction:column">
              <div v-for="r in renewals" :key="r.id" style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border)">
                <div style="flex:1;min-width:0">
                  <div style="font-weight:700;font-size:13px">{{ r.months || 12 }} {{ lang === 'bn' ? 'মাস' : 'months' }} <span v-if="r.new_rent">· {{ money(r.new_rent) }}/{{ lang === 'bn' ? 'মাস' : 'mo' }}</span></div>
                  <div class="c-sub" style="font-size:11.5px">{{ fmtTs(r.ts) }}</div>
                </div>
                <span class="badge" :class="badge(r.status)">{{ r.status }}</span>
              </div>
            </div>
            <div v-else style="padding:22px;text-align:center;color:var(--text-mute);font-size:13px">{{ lang === 'bn' ? 'কোনো নবায়ন অনুরোধ নেই।' : 'No renewal requests.' }}</div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-h">
            <div class="t"><span class="pi">🧾</span>{{ lang === 'bn' ? 'সেটেলমেন্ট' : 'Settlement' }}</div>
            <span v-if="settlement" class="badge" :class="badge(settlement.status)">{{ settlement.status }}</span>
          </div>
          <div class="panel-b">
            <template v-if="settleRows.length">
              <div class="kv" v-for="(sr, si) in settleRows" :key="si">
                <span class="k">{{ sr.k }}</span>
                <span class="v" :style="sr.k.includes('রিফান্ড') || sr.k === 'Refund' ? 'color:var(--ok,#1e8e4d);font-weight:800' : ''">{{ sr.v }}</span>
              </div>
              <div v-if="settlement.certificate_eligible" style="margin-top:10px;font-size:12px;color:var(--ok,#1e8e4d);font-weight:700">
                🎓 {{ lang === 'bn' ? 'নো-ডিউজ সার্টিফিকেট পাওয়ার যোগ্য' : 'Eligible for a no-dues certificate' }}
              </div>
            </template>
            <div v-else class="c-sub" style="font-size:13px">{{ lang === 'bn' ? 'সেটেলমেন্ট তথ্য নেই।' : 'No settlement data.' }}</div>
          </div>
        </div>
      </div>
    </template>

    <!-- Loading -->
    <div v-else-if="loading" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">
      {{ lang === 'bn' ? 'লোড হচ্ছে…' : 'Loading…' }}
    </div>

    <!-- No data -->
    <div v-else-if="!err" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">
      {{ lang === 'bn' ? 'পোর্টাল লোড করা যায়নি।' : 'Portal unavailable.' }}
    </div>
  </div>
</template>
