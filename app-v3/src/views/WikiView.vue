<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const q = ref('')
const open = ref({})   // section id -> set of open item ids (or 'all')

// ── Wiki content (V2.24: subscriber wiki — accurate to the live system) ──
// Alert types, per-user prefs, admin master switches and email classes are
// all documented from the actual implementation (SETTINGS_DEFAULTS,
// ADMIN_SETTING_DEFAULTS, notify_ok flags, app-kr-alert targets, mail_switch).
const SECTIONS = [
  {
    id: 'start', ico: '🚀', title: 'Getting started',
    items: [
      {
        t: 'What is KRTaker?',
        b: 'KRTaker (Key Responsibility Taker) is a property & facility management platform for owners, managers and staff. It covers the full rental lifecycle — units, tenants, leases, invoices, receipts, payments, maintenance, compliance, legal, safety and analytics — all in one dashboard.\n\nEvery screen is organized into hubs: Overview, Portfolio, Finance, BMS, Community, Legal, Operations, Safety & Security and Admin. The sidebar shows only the modules your role can use.',
      },
      {
        t: 'Who can use the app?',
        b: 'Accounts are role-based: Owner, Manager, Accountant, Service Manager, Operations, and staff sub-accounts. Owners see everything; staff see only their modules. A senior user can "Switch role" from the sidebar bottom card to temporarily view the app as a subordinate user (read-only for higher-rank data).',
      },
      {
        t: 'How do I get help or support?',
        b: 'Open the Support module (Community hub) for the built-in support desk. For urgent issues, contact support@krtaker.com. The AI Caretaker (KR) in the Overview hub can also answer operational questions.',
      },
    ],
  },
  {
    id: 'alerts', ico: '🔔', title: 'Alerts & notifications',
    items: [
      {
        t: 'What alert types exist?',
        b: 'The system watches your portfolio and raises alerts in these categories:\n\n• 🔧 SLA — maintenance/service-level jobs that breached their promise time\n• 📋 Compliance — certificates, licenses or inspections expired (or expiring soon)\n• 💰 Arrears — tenants behind on rent\n• 📅 Renewals — leases that are about to end or need renewal\n• 🔔 System — security and account events\n\nEach alert has a severity (info / warning / critical) and links straight to the record that needs attention.',
      },
      {
        t: 'Who receives alerts?',
        b: 'Since V2.23, alerts fan out to both the account owner and all active staff (Manager, Service Manager, Operations). Previously staff saw nothing — now every active team member receives SLA, compliance, arrears and renewal alerts so nothing slips through. Parcel-owner-specific alerts (land parcels, NRB vacancies) stay targeted to the parcel owner only.',
      },
      {
        t: 'Where do I see alerts?',
        b: 'Open the 🔔 Notifications screen (from the bell in the header, or directly). You get: a count of unread items, filter pills by type, one-tap "Mark all read", and "Clear all" to dismiss. Clicking an alert opens the exact record (invoice, lease, maintenance job…) in its module.',
      },
      {
        t: 'How do I turn alert categories on/off?',
        b: 'Settings → Notifications: each category (SLA, Compliance, Arrears, Renewals) has its own toggle. Turn off the ones you do not want in your inbox — your staff members control their own toggles independently.',
      },
    ],
  },
  {
    id: 'prefs', ico: '🎛️', title: 'Notification preferences',
    items: [
      {
        t: 'Per-user email toggles',
        b: 'Your own Settings screen controls which emails you personally receive (all default ON):\n\n• Rent reminders (notify_rent)\n• Collections / payment digests (notify_collections)\n• Lease renewals (notify_renewal)\n• Document emails — invoices, receipts (notify_docs)\n• WhatsApp reminders (wa_reminders, if your phone is set)\n• Weekly email digest (email_digest)\n• Product/premium announcements (notify_premium)\n\nThese are per-account: switching them off only silences YOUR inbox, never other users.',
      },
      {
        t: 'Browser (web push) notifications',
        b: 'Settings → Browser notifications lets you subscribe your device to push alerts. Enable the browser permission when prompted, and the app registers a VAPID push subscription. You can see how many devices are subscribed and unsubscribe any time. Pushes arrive even when the app tab is closed, but only while the browser itself is running.',
      },
      {
        t: 'Master switches (admin controlled)',
        b: 'The account admin can disable an entire mail class for the whole workspace (Settings → App → Email & notifications): welcome mail, rent reminders, collections, documents, renewals, and the digest. Per-user toggles still apply on top — an email is only sent when BOTH the master switch AND the user toggle allow it.',
      },
      {
        t: 'Opt-out for tenants',
        b: 'Tenants and partners can opt out of document emails (notify_docs). The system respects those opt-outs everywhere: invoice emails, receipts, lease documents, and notice broadcasts.',
      },
    ],
  },
  {
    id: 'email', ico: '📧', title: 'Email you receive',
    items: [
      {
        t: 'Welcome & onboarding',
        b: 'New accounts get a welcome email with login details. Owners can also invite staff sub-accounts, who get their own credentials.',
      },
      {
        t: 'Rent reminders',
        b: 'Automatic reminders go out before/at rent due dates to tenants (and owners) based on the rent-reminder schedule. A daily cron (00:30 UTC) scans active leases and sends only to users who have not opted out.',
      },
      {
        t: 'Collections digest',
        b: 'Owners receive a periodic collections digest summarizing money collected across properties — gross, collected, outstanding — plus the automated owner statement emails (monthly statement run from the Statements screen).',
      },
      {
        t: 'Notices broadcast (email)',
        b: 'When you post a notice on the board, you can optionally "Also email to tenants". The recipient count is shown live — it respects tenant opt-outs and the document master switch. Each notice records how many emails were sent (📨 badge + emailed count).',
      },
      {
        t: 'Rate limits & delivery',
        b: 'To protect deliverability, email sends are throttled per IP: 10 sends per 10 minutes for invoice emails, notice broadcasts, receipt emails and tenant reminders. If you hit the limit, wait a few minutes and retry. All outbound mail goes through the configured SMTP queue.',
      },
    ],
  },
  {
    id: 'security', ico: '🛡️', title: 'Security & privacy',
    items: [
      {
        t: 'Two-factor authentication (2FA)',
        b: 'Enable 2FA in Settings → Security. Two methods are supported: email OTP codes (a one-time code sent to your inbox at login) and authenticator TOTP apps. Once enabled, login requires your password plus the code — protecting you even if the password leaks.',
      },
      {
        t: 'Session & password policy',
        b: 'Sessions expire automatically after the configured TTL (Settings → App → Security; default 7 days, owners can pick 12h–30d, the super-admin session is locked to 12h). New passwords must be at least 8 characters (enforced server-side). After several failed logins the account locks briefly to stop brute-force.',
      },
      {
        t: 'Your data & GDPR export',
        b: 'You own your data. From a tenant record → 📦 Data export, you can download a full JSON export of that tenant (leases, invoices, payments). The audit log (super-admin panel) records who did what, and backups are kept on a rotation.',
      },
      {
        t: 'Alerts about security events',
        b: 'New logins from unfamiliar devices and other security events raise a system alert in your Notifications so you can react immediately.',
      },
    ],
  },
  {
    id: 'faq', ico: '❓', title: 'FAQ & troubleshooting',
    items: [
      {
        t: 'I stopped getting emails — why?',
        b: 'Check in order: (1) your own toggles in Settings → Notifications; (2) the admin master switches in Settings → App; (3) your spam folder — add KRTaker to your contacts; (4) the mail queue — a heavy batch (notices, statements) can hit the 10/10-min rate limit; wait and re-run.',
      },
      {
        t: 'Push notifications stopped arriving',
        b: 'The browser must be running for web push. Re-open the app and check Settings → Browser notifications still shows your device. If the browser permission was revoked, re-enable it and subscribe again. Old dead subscriptions are cleaned automatically.',
      },
      {
        t: 'My role was switched / I can\'t see a module',
        b: 'Roles show only permitted modules. If you were switched to a subordinate role (👁 icon in the sidebar bottom), press "↩ Back to my account". If a module is genuinely missing, ask the account admin to adjust your role modules.',
      },
      {
        t: 'I hit "rate limit exceeded"',
        b: 'Broadcast emails are throttled to 10 per 10 minutes per IP. Wait a few minutes — the counter resets on a rolling window — then retry. This protects your domain reputation so your emails do not land in spam.',
      },
    ],
  },
]

// search filter (title + body)
const filtered = computed(() => {
  const s = q.value.trim().toLowerCase()
  if (!s) return SECTIONS
  return SECTIONS.map(sec => ({
    ...sec,
    items: sec.items.filter(it => (it.t + ' ' + it.b).toLowerCase().includes(s)),
  })).filter(sec => sec.items.length)
})

function toggle(sid, iid) {
  const k = sid + ':' + iid
  open.value = { ...open.value, [k]: !open.value[k] }
}
function isOpen(sid, iid) { return !!open.value[sid + ':' + iid] }
function toggleAll(sec, force) {
  const k = sec.id + ':all'
  const on = force !== undefined ? force : !open.value[k]
  open.value = { ...open.value, [k]: on }
}
function allOpen(sec) { return !!open.value[sec.id + ':all'] }
function go(path) { router.push(path) }
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>📚 Wiki & Help</h1>
        <div class="sub">Subscriber guide — alerts, notifications, email &amp; security</div>
      </div>
    </div>

    <!-- search -->
    <div class="panel" style="padding:14px 16px;margin-bottom:14px">
      <input v-model="q" placeholder="🔍  Search the wiki…  (e.g. push, digest, 2FA, opt-out)"
        style="width:100%;padding:11px 14px;border-radius:12px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-size:14px;outline:none">
    </div>

    <div v-if="!filtered.length" class="panel" style="padding:40px 20px;text-align:center;color:var(--text-mute)">
      No articles match “{{ q }}”.
    </div>

    <div v-for="sec in filtered" :key="sec.id" class="panel" style="padding:6px 16px 14px;margin-bottom:14px">
      <div style="display:flex;align-items:center;gap:10px;padding:12px 0 10px;border-bottom:1px solid var(--border)">
        <span style="font-size:20px">{{ sec.ico }}</span>
        <div style="flex:1">
          <div style="font-weight:800;font-size:15px">{{ sec.title }}</div>
          <div class="c-sub" style="font-size:11.5px">{{ sec.items.length }} article{{ sec.items.length === 1 ? '' : 's' }}</div>
        </div>
        <button class="btn-ghost" style="padding:6px 12px;font-size:12px" @click="toggleAll(sec)">{{ allOpen(sec) ? 'Collapse' : 'Expand all' }}</button>
      </div>

      <div v-for="it in sec.items" :key="it.t" style="border-bottom:1px solid var(--border)">
        <div style="display:flex;align-items:center;gap:8px;padding:12px 4px;cursor:pointer;user-select:none" @click="toggle(sec.id, it.t)">
          <span style="font-size:12px;color:var(--text-mute);width:16px;flex-shrink:0">{{ isOpen(sec.id, it.t) ? '▾' : '▸' }}</span>
          <span style="font-weight:700;font-size:13.5px;flex:1">{{ it.t }}</span>
          <span v-if="it.tag" class="badge b-blue" style="font-size:10px">{{ it.tag }}</span>
        </div>
        <div v-if="isOpen(sec.id, it.t)" style="padding:2px 4px 14px 28px;white-space:pre-wrap;line-height:1.65;font-size:13px;color:var(--text)">{{ it.b }}</div>
      </div>
    </div>

    <div class="panel" style="padding:16px;margin-bottom:20px">
      <div style="font-weight:800;font-size:13.5px;margin-bottom:10px">Still stuck?</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn-ghost" style="padding:9px 16px;font-size:12.5px" @click="go('/support')">🎫 Open a support ticket</button>
        <button class="btn-ghost" style="padding:9px 16px;font-size:12.5px" @click="go('/settings')">⚙️ Review my settings</button>
      </div>
    </div>
  </div>
</template>
