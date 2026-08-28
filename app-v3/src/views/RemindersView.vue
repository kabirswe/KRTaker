<script setup>
// ⏰ Rent Reminder Automation Center (V2.19)
// Tenant escalation emails (3-tier, day 1/7/15) + owner web-push digests.
// Backend: app-reminder-config / save / summary / run + app-rent-due-push.
import { ref, computed, onMounted } from 'vue'
import { t } from '../lib/i18n'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { track } from '../lib/analytics'

const auth = useAuthStore()

const money = (n) => '৳' + Math.round(n || 0).toLocaleString('en-IN')
const fmtAgo = (ts) => {
  if (!ts) return '—'
  const d = new Date(String(ts).replace(' ', 'T'))
  if (isNaN(d)) return String(ts).slice(0, 10)
  const s = Math.floor((Date.now() - d.getTime()) / 1000)
  if (s < 60) return 'just now'
  if (s < 3600) return Math.floor(s / 60) + 'm ago'
  if (s < 86400) return Math.floor(s / 3600) + 'h ago'
  if (s < 604800) return Math.floor(s / 86400) + 'd ago'
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
}
const tierBadge = (t) => t === 3 ? 'b-red' : (t === 2 ? 'b-orange' : (t === 1 ? 'b-blue' : 'b-gray'))
const canManage = computed(() => ['superadmin', 'owner', 'manager', 'accountant'].includes(auth.user?.role || ''))

// ── state ──
const loading = ref(false)
const err = ref('')
const toast = ref('')
const flash = (m) => { toast.value = m; setTimeout(() => toast.value = '', 5000) }

const rem = ref(null)        // { config, last_run, history }
const remPlan = ref(null)    // { config, plan, by_tier, total_due }
const remRun = ref(null)     // last reminder run / dry-run
const remRunning = ref(false)
const remSaving = ref(false)
const confirmSend = ref(false)

const emptyTiers = () => ({
  '1': { label: 'Day 1 · gentle', min_days: 0, max_days: 6, note: '' },
  '2': { label: 'Day 7 · follow-up', min_days: 7, max_days: 13, note: '' },
  '3': { label: 'Day 15 · final', min_days: 14, max_days: 999, note: '' },
})
const remCfg = ref({ enabled: true, late_fee: '', tiers: emptyTiers() })

// owner push channel
const pushLookahead = ref(2)
const pushRun = ref(null)    // { dry_run, targeted, sent, suppressed, totals, owners, last_run }
const pushRunning = ref(false)
const confirmPush = ref(false)

// ── load ──
async function loadAll() {
  loading.value = true; err.value = ''
  try {
    const [c, s] = await Promise.all([apiCall('app-reminder-config'), apiCall('app-reminder-summary')])
    if (!c.ok) { err.value = c.error || 'Failed to load config.'; return }
    if (!s.ok) { err.value = s.error || 'Failed to load plan.'; return }
    rem.value = c
    remPlan.value = s
    const cfg = c.config || {}
    const tiers = cfg.tiers && typeof cfg.tiers === 'object' ? cfg.tiers : {}
    const base = emptyTiers()
    remCfg.value = {
      enabled: cfg.enabled !== false,
      late_fee: cfg.late_fee || '',
      tiers: { '1': tiers['1'] || base['1'], '2': tiers['2'] || base['2'], '3': tiers['3'] || base['3'] },
    }
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}

async function saveCfg() {
  remSaving.value = true; err.value = ''
  try {
    const r = await apiCall('app-reminder-save', { config: remCfg.value })
    if (!r.ok) { err.value = r.error || 'Save failed.'; return }
    flash('✅ Reminder config saved')
    track('reminder_config_saved', { enabled: r.config?.enabled ? 1 : 0 })
    await loadAll()
  } catch (e) { err.value = e.message }
  finally { remSaving.value = false }
}

async function runReminders(send) {
  remRunning.value = true; err.value = ''
  try {
    const r = await apiCall('app-reminder-run', { send })
    if (!r.ok) { err.value = r.error || 'Run failed.'; return }
    remRun.value = r
    confirmSend.value = false
    if (send) {
      flash(`📨 Sent ${r.sent} · stamped ${r.stamped} · suppressed ${r.suppressed} · failed ${r.errors?.length || 0}`)
      track('reminder_run', { sent: r.sent || 0, suppressed: r.suppressed || 0 })
    } else {
      flash(`👁️ Dry run — ${r.plan?.length || 0} invoices in plan (no emails sent)`)
    }
    await loadAll()
  } catch (e) { err.value = e.message }
  finally { remRunning.value = false }
}

async function runPush(send) {
  pushRunning.value = true; err.value = ''
  try {
    const r = await apiCall('app-rent-due-push', { send, lookahead: pushLookahead.value })
    if (!r.ok) { err.value = r.error || 'Push run failed.'; return }
    pushRun.value = r
    confirmPush.value = false
    if (send) {
      flash(`🔔 Push sent — targeted ${r.targeted} owner(s) · sent ${r.sent} · suppressed ${r.suppressed}`)
      track('owner_push_sent', { targeted: r.targeted || 0, sent: r.sent || 0 })
    } else {
      flash(`👁️ Push dry run — ${r.targeted || 0} owner(s) would be notified (lookahead ${pushLookahead.value} mo)`)
    }
  } catch (e) { err.value = e.message }
  finally { pushRunning.value = false }
}

const planRows = computed(() => remPlan.value?.plan || [])
const byTierEntries = computed(() => Object.entries(remPlan.value?.by_tier || {}).sort((a, b) => a[0] - b[0]))
const history = computed(() => rem.value?.history || [])
const pushOwners = computed(() => (pushRun.value?.owners) || [])
const pushTotals = computed(() => pushRun.value?.totals || {})

onMounted(loadAll)
</script>

<template>
  <div>
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:14px">
      <div>
        <h1 style="margin:0;font-size:22px">{{ t('⏰ Rent Reminder Automation') }}</h1>
        <div class="c-sub" style="font-size:12.5px;margin-top:4px">3-tier tenant escalation emails (day 1 → 7 → 15) + owner web-push digests · scheduler runs daily 00:30 UTC</div>
      </div>
      <span v-if="remCfg.enabled" class="badge b-green" style="font-size:12px;padding:5px 12px">● Automation on</span>
      <span v-else class="badge b-gray" style="font-size:12px;padding:5px 12px">○ Automation off</span>
    </div>

    <div v-if="err" style="background:rgba(231,76,60,.12);color:var(--danger);padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:14px">⚠️ {{ err }}</div>
    <div v-if="toast" style="background:rgba(18,161,80,.12);color:var(--ok);padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:14px">{{ toast }}</div>

    <div v-if="loading" style="color:var(--text-mute);padding:30px;text-align:center">Loading…</div>
    <template v-else>
      <!-- ── KPI cards ── -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px">
        <div class="panel" style="padding:14px 16px">
          <div class="c-sub" style="font-size:11px">UNPAID INVOICES</div>
          <div style="font-size:20px;font-weight:800">{{ planRows.length }}</div>
        </div>
        <div class="panel" style="padding:14px 16px">
          <div class="c-sub" style="font-size:11px">TOTAL DUE</div>
          <div style="font-size:20px;font-weight:800">{{ money(remPlan?.total_due) }}</div>
        </div>
        <div class="panel" style="padding:14px 16px">
          <div class="c-sub" style="font-size:11px">PLAN BY TIER</div>
          <div style="font-size:16px;font-weight:700;display:flex;gap:8px;flex-wrap:wrap">
            <span v-for="[t, n] in byTierEntries" :key="t"><span class="badge" :class="tierBadge(+t)">T{{ t }}:{{ n }}</span></span>
            <span v-if="!byTierEntries.length" class="c-sub" style="font-size:12px">—</span>
          </div>
        </div>
        <div class="panel" style="padding:14px 16px">
          <div class="c-sub" style="font-size:11px">LAST EMAIL RUN</div>
          <div style="font-size:15px;font-weight:700">{{ fmtAgo(rem?.last_run) }}</div>
        </div>
        <div class="panel" style="padding:14px 16px">
          <div class="c-sub" style="font-size:11px">LAST OWNER PUSH</div>
          <div style="font-size:15px;font-weight:700">{{ pushRun?.last_run ? fmtAgo(pushRun.last_run) : '—' }}</div>
        </div>
      </div>

      <!-- ── Escalation plan + run ── -->
      <div class="panel" style="margin-bottom:16px">
        <div class="panel-h"><div class="t"><span class="pi">📅</span>Escalation plan · {{ planRows.length }} unpaid</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn-ghost" :disabled="remRunning" @click="runReminders(false)" style="font-size:12.5px">👁️ Dry run {{ remRunning ? '…' : '' }}</button>
            <button v-if="canManage" class="btn-primary" :disabled="remRunning || !remCfg.enabled" @click="confirmSend = !confirmSend" style="font-size:12.5px">📨 Send reminders {{ remRunning ? '…' : '' }}</button>
          </div>
        </div>
        <div class="panel-b">
          <div v-if="confirmSend" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:rgba(231,76,60,.08);padding:10px 14px;border-radius:10px;margin-bottom:12px">
            <span style="font-size:13px;color:var(--text)">Send rent-reminder emails now? Only invoices whose escalation tier increased are emailed.</span>
            <button class="btn-primary" style="background:var(--danger);font-size:12.5px" :disabled="remRunning" @click="runReminders(true)">{{ t('Yes, send emails') }}</button>
            <button class="btn-ghost" style="font-size:12.5px" @click="confirmSend = false">{{ t('Cancel') }}</button>
          </div>

          <div v-if="remRun" style="font-size:12.5px;color:var(--text-mute);margin-bottom:12px;line-height:1.6">
            <b>Last run:</b> plan {{ remRun.plan?.length || 0 }} invoices · T1:{{ remRun.by_tier?.['1'] || 0 }} T2:{{ remRun.by_tier?.['2'] || 0 }} T3:{{ remRun.by_tier?.['3'] || 0 }} · due {{ money(remRun.total_due) }} · sent <b>{{ remRun.sent }}</b> · stamped {{ remRun.stamped }} · suppressed {{ remRun.suppressed }} · failed {{ remRun.errors?.length || 0 }} · {{ remRun.send ? '📨 EMAILS SENT' : '👁️ dry run — nothing sent' }}
          </div>

          <div class="tbl-wrap" style="max-height:none">
            <table class="kr" style="width:100%;font-size:12.5px">
              <thead>
                <tr><th style="text-align:left">{{ t('Invoice') }}</th><th style="text-align:left">{{ t('Tenant') }}</th><th style="text-align:left">{{ t('Unit · Property') }}</th><th style="text-align:left">{{ t('Month') }}</th><th style="text-align:right">{{ t('Due') }}</th><th style="text-align:right">{{ t('Days') }}</th><th style="text-align:center">{{ t('Tier') }}</th><th style="text-align:center">{{ t('Last') }}</th><th style="text-align:left">{{ t('Email') }}</th></tr>
              </thead>
              <tbody>
                <tr v-for="r in planRows" :key="r.inv">
                  <td style="font-weight:700">{{ r.inv }}</td>
                  <td>{{ r.tenant }}</td>
                  <td class="c-sub">{{ r.unit }}{{ r.property ? ' · ' + r.property : '' }}</td>
                  <td>{{ r.m }}</td>
                  <td style="text-align:right;font-weight:700">{{ money(r.due) }}</td>
                  <td style="text-align:right">{{ r.days_overdue }}d</td>
                  <td style="text-align:center"><span v-if="r.tier" class="badge" :class="tierBadge(r.tier)">T{{ r.tier }}</span><span v-else class="badge b-gray">—</span></td>
                  <td style="text-align:center"><span v-if="r.last_tier" class="badge" :class="tierBadge(r.last_tier)">T{{ r.last_tier }}</span><span v-else class="c-sub">—</span></td>
                  <td class="c-sub" style="font-size:11.5px">{{ r.email || '—' }}</td>
                </tr>
                <tr v-if="!planRows.length"><td colspan="9" style="text-align:center;color:var(--text-mute);padding:28px 0">No unpaid invoices 🎉</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ── Config editor ── -->
      <div class="panel" style="margin-bottom:16px">
        <div class="panel-h"><div class="t"><span class="pi">⚙️</span>Escalation config</div></div>
        <div class="panel-b">
          <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;font-weight:700;cursor:pointer;margin-bottom:12px">
            <input type="checkbox" v-model="remCfg.enabled"> Automation enabled (scheduler may send tiered reminder emails)
          </label>

          <div style="font-weight:800;font-size:13px;margin-bottom:6px">Late-fee note <span class="c-sub" style="font-weight:400">(appended to tier 3)</span></div>
          <textarea v-model="remCfg.late_fee" rows="2" style="width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:10px;padding:10px;font-size:12.5px;background:var(--bg);color:var(--text);margin-bottom:14px" placeholder="A late fee may apply per your tenancy agreement — please settle to avoid it."></textarea>

          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px">
            <div v-for="tk in ['1', '2', '3']" :key="tk" style="border:1px solid var(--border);border-radius:12px;padding:12px 14px">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                <span class="badge" :class="tierBadge(+tk)">T{{ tk }}</span>
                <input v-model="remCfg.tiers[tk].label" style="flex:1;border:1px solid var(--border);border-radius:8px;padding:6px 10px;font-size:12.5px;background:var(--bg);color:var(--text)" />
              </div>
              <div style="display:flex;gap:8px;margin-bottom:8px">
                <label class="c-sub" style="font-size:11px;flex:1">Min days <input type="number" v-model.number="remCfg.tiers[tk].min_days" min="0" style="width:100%;border:1px solid var(--border);border-radius:8px;padding:5px 8px;font-size:12px;background:var(--bg);color:var(--text)" /></label>
                <label class="c-sub" style="font-size:11px;flex:1">Max days <input type="number" v-model.number="remCfg.tiers[tk].max_days" min="0" style="width:100%;border:1px solid var(--border);border-radius:8px;padding:5px 8px;font-size:12px;background:var(--bg);color:var(--text)" /></label>
              </div>
              <textarea v-model="remCfg.tiers[tk].note" rows="3" style="width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:12px;background:var(--bg);color:var(--text)" placeholder="Message — use {{ '{{month}}' }} for the invoice month"></textarea>
            </div>
          </div>
          <div style="margin-top:12px">
            <button class="btn-primary" :disabled="remSaving" @click="saveCfg" style="font-size:13px">💾 Save config {{ remSaving ? '…' : '' }}</button>
          </div>
        </div>
      </div>

      <!-- ── Owner web-push channel ── -->
      <div class="panel" style="margin-bottom:16px">
        <div class="panel-h"><div class="t"><span class="pi">🔔</span>Owner web-push digest <span class="badge b-green" style="margin-left:8px">push</span></div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <label class="c-sub" style="font-size:12px">Lookahead
              <select v-model.number="pushLookahead" style="border:1px solid var(--border);border-radius:8px;padding:6px 8px;font-size:12px;background:var(--bg);color:var(--text);margin-left:6px">
                <option v-for="n in 6" :key="n" :value="n">{{ n }} mo</option>
              </select>
            </label>
            <button class="btn-ghost" :disabled="pushRunning" @click="runPush(false)" style="font-size:12.5px">👁️ Dry run {{ pushRunning ? '…' : '' }}</button>
            <button v-if="canManage" class="btn-primary" :disabled="pushRunning" @click="confirmPush = !confirmPush" style="font-size:12.5px">🔔 Send push {{ pushRunning ? '…' : '' }}</button>
          </div>
        </div>
        <div class="panel-b">
          <div class="c-sub" style="font-size:12.5px;margin-bottom:10px">Proactive browser-notification digest to owners with a subscribed device — overdue / due-this-month / due-next-month per property. Respects the rent_reminders switch and per-user opt-out.</div>
          <div v-if="confirmPush" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:rgba(231,76,60,.08);padding:10px 14px;border-radius:10px;margin-bottom:12px">
            <span style="font-size:13px">Send web-push digests to <b>{{ pushRun?.targeted || '?' }}</b> owner(s)?</span>
            <button class="btn-primary" style="background:var(--danger);font-size:12.5px" :disabled="pushRunning" @click="runPush(true)">{{ t('Yes, send push') }}</button>
            <button class="btn-ghost" style="font-size:12.5px" @click="confirmPush = false">{{ t('Cancel') }}</button>
          </div>
          <div v-if="pushRun" style="font-size:12.5px;color:var(--text-mute);margin-bottom:12px">
            <b>Last run:</b> targeted {{ pushRun.targeted }} · sent {{ pushRun.sent }} · suppressed {{ pushRun.suppressed }} · overdue {{ money(pushTotals.overdue) }} · due soon {{ money(pushTotals.due_soon) }} · upcoming {{ money(pushTotals.upcoming) }} · {{ pushRun.dry_run ? '👁️ dry run' : '🔔 SENT' }}
          </div>
          <div class="tbl-wrap" style="max-height:none">
            <table class="kr" style="width:100%;font-size:12.5px">
              <thead>
                <tr><th style="text-align:left">{{ t('Owner') }}</th><th style="text-align:right">{{ t('Overdue') }}</th><th style="text-align:right">{{ t('Due soon') }}</th><th style="text-align:right">{{ t('Upcoming') }}</th><th style="text-align:center">{{ t('Invoices') }}</th><th style="text-align:center">{{ t('Status') }}</th></tr>
              </thead>
              <tbody>
                <tr v-for="(o, i) in pushOwners" :key="i">
                  <td style="font-weight:700">{{ o.email }}</td>
                  <td style="text-align:right;color:var(--danger)">{{ money(o.overdue) }}</td>
                  <td style="text-align:right">{{ money(o.due_soon) }}</td>
                  <td style="text-align:right" class="c-sub">{{ money(o.upcoming) }}</td>
                  <td style="text-align:center">{{ o.invoices }}</td>
                  <td style="text-align:center"><span v-if="o.suppressed" class="badge b-gray">suppressed</span><span v-else class="badge b-green">will notify</span></td>
                </tr>
                <tr v-if="!pushOwners.length"><td colspan="6" style="text-align:center;color:var(--text-mute);padding:24px 0">Run a dry-run to preview owners ({{ pushRun ? 'no subscribed owners with dues' : 'no data yet' }})</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ── History ── -->
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">📜</span>Send history · last {{ history.length }}</div></div>
        <div class="panel-b">
          <div class="tbl-wrap" style="max-height:none">
            <table class="kr" style="width:100%;font-size:12.5px">
              <thead>
                <tr><th style="text-align:left">{{ t('Invoice') }}</th><th style="text-align:center">{{ t('Tier') }}</th><th style="text-align:center">{{ t('Via') }}</th><th style="text-align:left">{{ t('Sent') }}</th></tr>
              </thead>
              <tbody>
                <tr v-for="(h, i) in history" :key="i">
                  <td style="font-weight:700">{{ h.invoice_id }}</td>
                  <td style="text-align:center"><span class="badge" :class="tierBadge(h.tier)">T{{ h.tier }}</span></td>
                  <td style="text-align:center"><span class="badge" :class="h.via === 'email' ? 'b-blue' : (h.via === 'email+sms' ? 'b-green' : 'b-gray')">{{ h.via || '—' }}</span></td>
                  <td>{{ h.sent_at }}</td>
                </tr>
                <tr v-if="!history.length"><td colspan="4" style="text-align:center;color:var(--text-mute);padding:24px 0">No reminders sent yet</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
