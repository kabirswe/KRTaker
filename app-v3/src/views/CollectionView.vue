<script setup>
import { computed, ref, onMounted } from 'vue'
import { apiCall } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { badge } from '../lib/ui'

const auth = useAuthStore()
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'accountant'].includes(auth.user?.role || ''))

const tab = ref('collections')
const loading = ref(false)
const err = ref('')
const toast = ref('')

// ── Collections (app-collections-summary / app-collections-run) ──
const col = ref(null)          // { unpaid, total_due, by_tenant, invoices[], last_run, last_push }
const dry = ref(null)          // last dry-run result
const running = ref(false)

async function loadCollections() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-collections-summary')
    if (!r.ok) { err.value = r.error || 'Failed to load collections.'; return }
    col.value = r
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}

const unpaid = computed(() => col.value?.invoices || [])
const totalDue = computed(() => col.value?.total_due || 0)
const byTenant = computed(() => {
  const m = col.value?.by_tenant || {}
  return Object.entries(m).sort((a, b) => b[1] - a[1])
})

async function dryRun() {
  if (!confirm('Preview the collections run? No emails will be sent.')) return
  running.value = true; err.value = ''
  try {
    const r = await apiCall('app-collections-run', { send: false })
    if (!r.ok) { err.value = r.error || 'Dry-run failed.'; return }
    dry.value = r
  } catch (e) { err.value = e.message }
  finally { running.value = false }
}

async function runCampaign() {
  if (!confirm(`Send rent-reminder emails to ${unpaid.value.length} unpaid invoice(s)? This will email tenants.`)) return
  running.value = true; err.value = ''
  try {
    const r = await apiCall('app-collections-run', { send: true })
    if (!r.ok) { err.value = r.error || 'Collections run failed.'; return }
    dry.value = r
    toast.value = `✅ Sent ${r.sent} · failed ${r.failed} · suppressed ${r.suppressed}`
    setTimeout(() => toast.value = '', 5000)
    await loadCollections()
  } catch (e) { err.value = e.message }
  finally { running.value = false }
}

// ── Recon (app-payment-recon) ──
const rec = ref(null)          // { payments{}, receipts, gateway_tx{}, orphan_payments[], overpaid[], stale_sessions[] }
async function loadRecon() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-payment-recon')
    if (!r.ok) { err.value = r.error || 'Failed to load reconciliation.'; return }
    rec.value = r
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}

const gtxEntries = computed(() => Object.entries(rec.value?.gateway_tx || {}).sort((a, b) => b[1] - a[1]))

// Refund a payment
const refundFor = ref(null)
const refundReason = ref('')
async function doRefund() {
  if (!refundFor.value) return
  if (!confirm(`Refund payment ${refundFor.value}? This marks it Refunded (no money moves).`)) return
  err.value = ''
  const r = await apiCall('app-refund', { payment_id: refundFor.value, reason: refundReason.value.trim() })
  if (!r.ok) { err.value = r.error || 'Refund failed.'; return }
  refundFor.value = null; refundReason.value = ''
  toast.value = '✅ Payment refunded'
  setTimeout(() => toast.value = '', 4000)
  await loadRecon()
}

async function cleanupStale() {
  if (!confirm(`Expire ${rec.value?.stale_sessions?.length || 0} stale pending gateway session(s)?`)) return
  err.value = ''
  const r = await apiCall('app-gateway-cleanup')
  if (!r.ok) { err.value = r.error || 'Cleanup failed.'; return }
  toast.value = `✅ ${r.expired} stale session(s) expired`
  setTimeout(() => toast.value = '', 4000)
  await loadRecon()
}

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const wa = (phone) => phone ? `https://wa.me/${String(phone).replace(/[^0-9]/g, '')}` : '#'

onMounted(() => { loadCollections(); loadRecon() })
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>📮 Collections &amp; Recon</h1>
        <div class="sub">Unpaid-rent campaign + payment reconciliation · live from API</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-ghost" @click="tab === 'collections' ? loadCollections() : loadRecon()">🔄 Refresh</button>
      </div>
    </div>

    <div v-if="toast" style="padding:10px 14px;border-radius:10px;background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.35);margin-bottom:14px;font-weight:700;font-size:13.5px">{{ toast }}</div>
    <div v-if="err" style="padding:10px 14px;border-radius:10px;background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.35);margin-bottom:14px;font-weight:600;font-size:13.5px">⚠️ {{ err }}</div>

    <!-- Tabs -->
    <div style="display:flex;gap:8px;margin-bottom:16px">
      <button @click="tab = 'collections'" :style="tab === 'collections' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'" style="padding:9px 16px;border:none;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer">📨 Collections</button>
      <button @click="tab = 'recon'" :style="tab === 'recon' ? 'background:var(--primary);color:#fff' : 'background:var(--bg-alt);color:var(--text)'" style="padding:9px 16px;border:none;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer">🧾 Recon</button>
    </div>

    <!-- ══ COLLECTIONS TAB ══ -->
    <template v-if="tab === 'collections'">
      <div v-if="loading" class="panel" style="padding:36px;text-align:center;color:var(--text-mute)">Loading…</div>
      <template v-else>
        <!-- KPIs -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px">
          <div class="panel chip" style="padding:16px">
            <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Unpaid invoices</div>
            <div style="font-size:24px;font-weight:900;margin-top:4px">{{ unpaid.length }}</div>
          </div>
          <div class="panel chip" style="padding:16px">
            <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Total due</div>
            <div style="font-size:24px;font-weight:900;margin-top:4px;color:var(--danger,#e74c3c)">{{ money(totalDue) }}</div>
          </div>
          <div class="panel chip" style="padding:16px">
            <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Last run</div>
            <div style="font-size:13px;font-weight:700;margin-top:6px;word-break:break-word">{{ col?.last_run || '—' }}</div>
          </div>
          <div class="panel chip" style="padding:16px">
            <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Last push</div>
            <div style="font-size:13px;font-weight:700;margin-top:6px;word-break:break-word">{{ col?.last_push || '—' }}</div>
          </div>
        </div>

        <!-- Actions -->
        <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
          <button @click="dryRun" :disabled="running || !unpaid.length" style="padding:10px 16px;border:none;border-radius:10px;background:var(--bg-alt);color:var(--text);font-weight:800;font-size:13px;cursor:pointer;display:inline-flex;gap:6px;align-items:center">👁️ Dry run</button>
          <button v-if="canManage" @click="runCampaign" :disabled="running || !unpaid.length" style="padding:10px 16px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-weight:800;font-size:13px;cursor:pointer;display:inline-flex;gap:6px;align-items:center">📨 Send reminders {{ running ? '…' : '' }}</button>
        </div>

        <!-- Dry-run result -->
        <div v-if="dry" class="panel" style="padding:14px 18px;margin-bottom:16px;font-size:13px;border-left:3px solid var(--primary)">
          <b>Run result:</b> {{ dry.unpaid }} unpaid · {{ money(dry.total_due) }} due · sent <b>{{ dry.sent }}</b> · failed {{ dry.failed }} · suppressed {{ dry.suppressed }} · {{ dry.dry_run ? 'DRY RUN — no emails sent' : 'emails sent' }}
        </div>

        <!-- Unpaid invoice table -->
        <div class="panel" style="overflow:hidden">
          <div class="tbl-wrap">
            <table class="kr">
              <thead><tr><th>Invoice</th><th>Month</th><th>Tenant</th><th>Unit</th><th>Property</th><th style="text-align:right">Net</th><th style="text-align:right">Due</th><th>Contact</th><th>Status</th></tr></thead>
              <tbody>
                <tr v-for="r in unpaid" :key="r.inv">
                  <td style="font-weight:700">{{ r.inv }}</td>
                  <td>{{ r.m }}</td>
                  <td>{{ r.tenant }}</td>
                  <td class="c-sub">{{ r.unit }}</td>
                  <td class="c-sub">{{ r.property }}</td>
                  <td style="text-align:right">{{ money(r.net) }}</td>
                  <td style="text-align:right;font-weight:800;color:var(--danger,#e74c3c)">{{ money(r.due) }}</td>
                  <td>
                    <a v-if="r.phone" :href="wa(r.phone)" target="_blank" rel="noopener" style="color:var(--primary);text-decoration:none;font-weight:700">💬 {{ r.phone }}</a>
                    <span v-else class="c-sub">—</span>
                  </td>
                  <td><span class="badge" :class="badge(r.status)">{{ r.status }}</span></td>
                </tr>
                <tr v-if="!unpaid.length"><td colspan="9" style="text-align:center;color:var(--text-mute);padding:30px">No unpaid invoices 🎉</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- By tenant -->
        <div class="panel" style="padding:18px;margin-top:16px">
          <div style="font-weight:800;font-size:14px;margin-bottom:12px">By tenant</div>
          <div style="display:flex;flex-direction:column;gap:8px">
            <div v-for="[t, due] in byTenant" :key="t" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
              <span style="font-weight:600;font-size:13px">{{ t }}</span>
              <div style="flex:1;height:6px;border-radius:4px;background:var(--bg-alt);overflow:hidden">
                <div :style="{ width: Math.max(4, Math.round(due / Math.max(1, byTenant[0][1]) * 100)) + '%', height: '100%', background: 'var(--primary)' }"></div>
              </div>
              <span style="font-weight:800;font-size:13px;white-space:nowrap">{{ money(due) }}</span>
            </div>
          </div>
        </div>
      </template>
    </template>

    <!-- ══ RECON TAB ══ -->
    <template v-if="tab === 'recon'">
      <div v-if="loading" class="panel" style="padding:36px;text-align:center;color:var(--text-mute)">Loading…</div>
      <template v-else>
        <!-- KPIs -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px">
          <div class="panel chip" style="padding:16px">
            <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Payments</div>
            <div style="font-size:24px;font-weight:900;margin-top:4px">{{ rec?.payments?.count || 0 }}</div>
            <div style="font-size:12px;color:var(--text-mute)">{{ money(rec?.payments?.total) }} collected</div>
          </div>
          <div class="panel chip" style="padding:16px">
            <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Receipts</div>
            <div style="font-size:24px;font-weight:900;margin-top:4px">{{ rec?.receipts || 0 }}</div>
          </div>
          <div class="panel chip" style="padding:16px">
            <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Refunds</div>
            <div style="font-size:24px;font-weight:900;margin-top:4px">{{ rec?.payments?.refunds || 0 }}</div>
          </div>
          <div class="panel chip" style="padding:16px">
            <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-mute)">Orphan / Overpaid</div>
            <div style="font-size:24px;font-weight:900;margin-top:4px">{{ (rec?.orphan_payments || []).length }} / {{ (rec?.overpaid || []).length }}</div>
          </div>
        </div>

        <!-- Gateway breakdown -->
        <div class="panel" style="padding:18px;margin-bottom:16px">
          <div style="font-weight:800;font-size:14px;margin-bottom:12px">Gateway transactions by status</div>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <span v-for="[s, n] in gtxEntries" :key="s" class="badge" :class="badge(s)" style="font-size:12.5px;padding:6px 12px">{{ s }}: {{ n }}</span>
            <span v-if="!gtxEntries.length" class="c-sub">No gateway transactions</span>
          </div>
        </div>

        <!-- Stale sessions -->
        <div v-if="rec?.stale_sessions?.length" class="panel" style="padding:16px 18px;margin-bottom:16px;border-left:3px solid var(--danger,#e74c3c)">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
            <div>
              <div style="font-weight:800;font-size:14px">⚠️ {{ rec.stale_sessions.length }} stale pending session(s)</div>
              <div class="c-sub" style="font-size:12px;margin-top:3px">Pending &gt;24h — will never complete; safe to expire.</div>
            </div>
            <button v-if="canManage" @click="cleanupStale" style="padding:9px 14px;border:none;border-radius:9px;background:var(--danger,#e74c3c);color:#fff;font-weight:800;font-size:12.5px;cursor:pointer">🧹 Expire all</button>
          </div>
        </div>

        <!-- Overpaid flags -->
        <div v-if="rec?.overpaid?.length" class="panel" style="padding:16px 18px;margin-bottom:16px;border-left:3px solid #f39c12">
          <div style="font-weight:800;font-size:14px;margin-bottom:8px">Overpaid invoices</div>
          <div v-for="o in rec.overpaid" :key="o.inv" style="font-size:13px;display:flex;justify-content:space-between;gap:10px;padding:4px 0">
            <span style="font-weight:700">{{ o.inv }}</span>
            <span class="c-sub">paid {{ money(o.paid) }} · net {{ money(o.net) }} · <b style="color:#f39c12">excess {{ money(o.excess) }}</b></span>
          </div>
        </div>

        <!-- Refund tool -->
        <div class="panel" style="padding:18px">
          <div style="font-weight:800;font-size:14px;margin-bottom:4px">Refund a payment</div>
          <div class="c-sub" style="font-size:12px;margin-bottom:12px">Marks a payment as Refunded (reconciliation flag — money movement is manual).</div>
          <div v-if="!refundFor" style="display:flex;gap:8px;flex-wrap:wrap">
            <button @click="refundFor = 'P-' + Math.random().toString(36).slice(2, 7).toUpperCase()" style="padding:9px 14px;border:none;border-radius:9px;background:var(--bg-alt);color:var(--text);font-weight:800;font-size:12.5px;cursor:pointer">＋ New refund</button>
          </div>
          <div v-else style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <input v-model="refundFor" placeholder="Payment ID (e.g. P-XXXXX)" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;width:150px">
            <input v-model="refundReason" placeholder="Reason (optional)" style="padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none;flex:1;min-width:180px">
            <button @click="doRefund" style="padding:9px 14px;border:none;border-radius:9px;background:var(--primary);color:#fff;font-weight:800;font-size:12.5px;cursor:pointer">💸 Refund</button>
            <button @click="refundFor = null" style="padding:9px 12px;border:none;border-radius:9px;background:transparent;color:var(--text-mute);font-weight:700;font-size:12.5px;cursor:pointer">Cancel</button>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>
