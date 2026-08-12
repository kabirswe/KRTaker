<script setup>
// GuideScreen — stylized, theme-adaptive mockups of the real KRTaker screens
// used by the Wiki's step-by-step visual guides. Each variant renders a mini
// representation of the actual app UI (CSS variables so it adapts to light
// and dark themes); `highlights` draws numbered pins + a step legend.
const PALETTE = ['#f87171', '#60a5fa', '#fbbf24', '#34d399', '#a78bfa', '#f472b6', '#2dd4bf', '#fb923c']
defineProps({
  variant: { type: String, required: true },
  url: { type: String, default: '' },
  highlights: { type: Array, default: () => [] },
})
const NAV = ['Overview', 'Portfolio', 'Finance', 'BMS', 'Community', 'Legal', 'Safety', 'Admin']
</script>

<template>
  <div class="gs">
    <div class="gs-frame">
      <div class="gs-bar">
        <span class="gs-dot" style="background:#ff5f57"></span>
        <span class="gs-dot" style="background:#febc2e"></span>
        <span class="gs-dot" style="background:#28c840"></span>
        <span class="gs-url">{{ url || 'app.krtaker.com' }}</span>
      </div>
      <div class="gs-body">

        <!-- ── LOGIN / 2FA ── -->
        <div v-if="variant === 'login'" class="gs-login">
          <div class="gs-login-card">
            <div class="gs-logo">◆ KRTaker</div>
            <div class="gs-f"></div>
            <div class="gs-f" style="margin-top:6px"></div>
            <div class="gs-f" style="margin-top:6px"></div>
            <div class="gs-btn" style="margin-top:8px"></div>
            <div class="gs-link" style="margin-top:6px">Forgot password?</div>
          </div>
        </div>

        <!-- ── OVERVIEW DASHBOARD ── -->
        <div v-else-if="variant === 'dash'" class="gs-dash">
          <div class="gs-side">
            <div class="gs-side-logo">◆ KRTaker</div>
            <div v-for="(n, i) in NAV" :key="n" class="gs-nav" :class="{ on: i === 0 }">{{ n }}</div>
          </div>
          <div class="gs-main">
            <div class="gs-head">
              <span class="gs-search"></span>
              <span class="gs-bell">🔔</span>
            </div>
            <div class="gs-kpis">
              <div class="gs-kpi"><div class="gs-kpi-t">Revenue</div><div class="gs-kpi-v">৳8.3L</div></div>
              <div class="gs-kpi"><div class="gs-kpi-t">Occupancy</div><div class="gs-kpi-v">94%</div></div>
              <div class="gs-kpi"><div class="gs-kpi-t">Alerts</div><div class="gs-kpi-v" style="color:var(--warn)">13</div></div>
            </div>
            <div class="gs-cards">
              <div class="gs-ai">
                <div class="gs-ai-t">🤖 KR Caretaker</div>
                <div class="gs-ai-q">"who is behind on rent?"</div>
              </div>
              <div class="gs-al">
                <div class="gs-al-r"><span class="gs-chip c-r"></span><span>SLA job overdue</span></div>
                <div class="gs-al-r"><span class="gs-chip c-y"></span><span>Compliance expires</span></div>
                <div class="gs-al-r"><span class="gs-chip c-b"></span><span>Lease renewal</span></div>
              </div>
            </div>
          </div>
        </div>

        <!-- ── NOTIFICATIONS ── -->
        <div v-else-if="variant === 'notify'" class="gs-notify">
          <div class="gs-n-head"><b>Notifications</b><span class="gs-n-mark">Mark all read</span></div>
          <div class="gs-n-pills"><span class="gs-pill on">All</span><span class="gs-pill">SLA</span><span class="gs-pill">Compliance</span><span class="gs-pill">Arrears</span><span class="gs-pill">Renewals</span></div>
          <div class="gs-n-list">
            <div class="gs-n-row"><span class="gs-chip c-r"></span><div><b>Maintenance SLA breached</b><div class="gs-n-sub">Unit 4B · 2h ago · critical</div></div></div>
            <div class="gs-n-row"><span class="gs-chip c-y"></span><div><b>Fire certificate expires in 14d</b><div class="gs-n-sub">Compliance · 5h ago · warning</div></div></div>
            <div class="gs-n-row"><span class="gs-chip c-b"></span><div><b>Tenant B missed rent payment</b><div class="gs-n-sub">Arrears · 1d ago · info</div></div></div>
          </div>
        </div>

        <!-- ── FINANCE / INVOICES ── -->
        <div v-else-if="variant === 'finance'" class="gs-fin">
          <div class="gs-fh"><b>Invoices</b><span class="gs-btn-sm">➕ New invoice</span></div>
          <div class="gs-table">
            <div class="gs-tr gs-th"><span>Invoice</span><span>Tenant</span><span>Amount</span><span>Status</span></div>
            <div class="gs-tr"><span>INV-1042</span><span>Tanvir A.</span><span>৳45,000</span><span class="gs-st st-sent">Sent</span></div>
            <div class="gs-tr"><span>INV-1043</span><span>Rakib H.</span><span>৳32,000</span><span class="gs-st st-paid">Paid</span></div>
            <div class="gs-tr"><span>INV-1044</span><span>Nadia S.</span><span>৳28,500</span><span class="gs-st st-draft">Draft</span></div>
          </div>
        </div>

        <!-- ── NOTICE BOARD ── -->
        <div v-else-if="variant === 'notice'" class="gs-notice">
          <div class="gs-fh"><b>Notice board</b><span class="gs-btn-sm">➕ New notice</span></div>
          <div class="gs-ncard">
            <div class="gs-nc-t">Elevator maintenance</div>
            <div class="gs-nc-b">Scheduled maintenance on 14 Aug, 10:00–13:00. Lift #2 will be out of service…</div>
            <div class="gs-email"><span class="gs-chk on"></span><span>Also email to tenants</span><span class="gs-cnt">9 recipients</span></div>
            <div class="gs-post">Post notice</div>
          </div>
        </div>

        <!-- ── SUPPORT / TICKETS ── -->
        <div v-else-if="variant === 'support'" class="gs-supp">
          <div class="gs-fh"><b>Support</b><span class="gs-btn-sm">📚 Wiki</span><span class="gs-btn-sm prim">➕ New ticket</span></div>
          <div class="gs-kpis2">
            <div class="gs-kpi2">Open <b>3</b></div>
            <div class="gs-kpi2">In progress <b>2</b></div>
            <div class="gs-kpi2">Resolved <b>14</b></div>
          </div>
          <div class="gs-supp-body">
            <div class="gs-tlist">
              <div class="gs-trow">
                <span class="gs-pill2 p-bill">Billing</span>
                <div class="gs-t-mid"><b>SUP-014</b><div class="gs-t-sub">Refund for overpaid rent</div></div>
                <span class="gs-st st-urg">Urgent</span><span class="gs-st st-open">Open</span>
              </div>
              <div class="gs-trow">
                <span class="gs-pill2 p-tech">Technical</span>
                <div class="gs-t-mid"><b>SUP-015</b><div class="gs-t-sub">Push notif not arriving</div></div>
                <span class="gs-st st-med">Medium</span><span class="gs-st st-prog">In Progress</span>
              </div>
            </div>
            <div class="gs-drawer">
              <div class="gs-bub me">Hi — I need help with a refund…</div>
              <div class="gs-bub you">Sure — checking the payment now.</div>
              <div class="gs-reply">Type a reply… (Enter to send)</div>
              <div class="gs-stats">
                <span class="gs-st st-open">Open</span><span class="gs-st st-prog">In Progress</span><span class="gs-st st-res">Resolved</span><span class="gs-st st-clo">Closed</span>
              </div>
            </div>
          </div>
        </div>

        <!-- ── SETTINGS ── -->
        <div v-else-if="variant === 'settings'" class="gs-settings">
          <div class="gs-srow"><span>Rent reminders</span><span class="gs-tgl on"></span></div>
          <div class="gs-srow"><span>Collections digest</span><span class="gs-tgl on"></span></div>
          <div class="gs-srow"><span>Web push (browser)</span><span class="gs-tgl on"></span></div>
          <div class="gs-srow"><span>Weekly email digest</span><span class="gs-tgl"></span></div>
          <div class="gs-srow"><span>Two-factor auth (2FA)</span><span class="gs-tgl on"></span></div>
          <div class="gs-srow"><span>Change password</span><span class="gs-link2">›</span></div>
        </div>

        <!-- ── TENANT PORTAL ── -->
        <div v-else-if="variant === 'tenant'" class="gs-tenant">
          <div class="gs-tcard">
            <div class="gs-tc-t">My lease</div>
            <div class="gs-tc-m">Unit 4B · ৳45,000/mo</div>
            <div class="gs-tc-d">Next due: 05 Aug</div>
          </div>
          <div class="gs-tcard">
            <div class="gs-tc-t">My invoices</div>
            <div class="gs-trow2"><span>INV-1042 · ৳45,000</span><span class="gs-st st-draft">Unpaid</span><span class="gs-btn-sm prim" style="padding:2px 8px">Pay</span></div>
          </div>
          <div class="gs-tcard">
            <div class="gs-tc-t">Notice board</div>
            <div class="gs-tc-d">📢 Elevator maintenance 14 Aug</div>
          </div>
        </div>

        <!-- ── PORTFOLIO HUB ── -->
        <div v-else-if="variant === 'portfolio'" class="gs-port">
          <div class="gs-rail">
            <div v-for="(n, i) in ['Properties','Units','Tenants','Leases','Onboarding','Leads','Documents','Insurance']" :key="n" class="gs-rail-i" :class="{ on: i === 0 }">{{ n }}</div>
          </div>
          <div class="gs-port-main">
            <div class="gs-fh"><b>Portfolio</b><span class="gs-btn-sm prim">➕ New property</span></div>
            <div class="gs-port-grid">
              <div class="gs-pcard"><div class="gs-pc-t">🏢 Mirpur Heights</div><div class="gs-pc-s">12 units · 94% occupied</div><div class="gs-pc-s">৳8.3L/mo · 1 lease expiring</div></div>
              <div class="gs-pcard"><div class="gs-pc-t">🏢 Gulshan View</div><div class="gs-pc-s">8 units · 88% occupied</div><div class="gs-pc-s">৳5.1L/mo</div></div>
              <div class="gs-pcard"><div class="gs-pc-t">🏢 Dhanmondi Res</div><div class="gs-pc-s">6 units · 100% occupied</div><div class="gs-pc-s">৳3.9L/mo</div></div>
            </div>
            <div class="gs-port-hier"><span class="gs-hier">Properties → Units → Tenants → Leases</span></div>
          </div>
        </div>

        <!-- ── BMS / MAINTENANCE ── -->
        <div v-else-if="variant === 'bms'" class="gs-bms">
          <div class="gs-rail">
            <div v-for="(n, i) in ['Maintenance','Vendors','Staff','Meter readings','Utility bills','Gate visits','Samity']" :key="n" class="gs-rail-i" :class="{ on: i === 0 }">{{ n }}</div>
          </div>
          <div class="gs-bms-main">
            <div class="gs-fh"><b>Maintenance</b><span class="gs-btn-sm prim">➕ Raise ticket</span></div>
            <div class="gs-bms-list">
              <div class="gs-brow"><span>💧 Leak in Unit 4B</span><span class="gs-st st-urg">SLA overdue</span><span class="gs-pill2 p-lt">Landlord</span></div>
              <div class="gs-brow"><span>⚡ Switchboard 2A</span><span class="gs-st st-prog">2h left</span><span class="gs-pill2 p-tt">Tenant</span></div>
              <div class="gs-brow"><span>🔧 AC service 3C</span><span class="gs-st st-sent">On track</span><span class="gs-pill2 p-sh">Shared</span></div>
            </div>
          </div>
        </div>

        <!-- ── COMMUNITY HUB ── -->
        <div v-else-if="variant === 'community'" class="gs-comm">
          <div class="gs-rail">
            <div v-for="(n, i) in ['Notices','Referrals','Trust (NID)','Support']" :key="n" class="gs-rail-i" :class="{ on: i === 0 }">{{ n }}</div>
          </div>
          <div class="gs-comm-main">
            <div class="gs-fh"><b>Community</b><span class="gs-btn-sm prim">➕ New notice</span></div>
            <div class="gs-ncard" style="background:var(--card)">
              <div class="gs-nc-t">📢 Elevator maintenance</div>
              <div class="gs-nc-b">14 Aug, 10:00–13:00 · Lift #2 out of service</div>
              <div class="gs-email"><span class="gs-chk on"></span><span>Also email to tenants</span><span class="gs-cnt">9 recipients</span></div>
            </div>
            <div class="gs-ref"><span>🤝 Invite & earn ৳5,000</span><span class="gs-ref-code">KR-7F2X</span></div>
            <div class="gs-trust">🪪 NID trust records · <b>3 verified</b> · 1 pending</div>
          </div>
        </div>

        <!-- ── LEGAL HUB ── -->
        <div v-else-if="variant === 'legal'" class="gs-legal">
          <div class="gs-rail">
            <div v-for="(n, i) in ['Compliance','Cases','Concierge']" :key="n" class="gs-rail-i" :class="{ on: i === 0 }">{{ n }}</div>
          </div>
          <div class="gs-legal-main">
            <div class="gs-kpis2">
              <div class="gs-kpi2">⚖️ Compliance <b>42</b><div class="gs-k2-s">3 expiring ≤30d</div></div>
              <div class="gs-kpi2">👨‍⚖️ Cases <b>7</b><div class="gs-k2-s">2 open</div></div>
              <div class="gs-kpi2">📜 Legal notices <b>12</b><div class="gs-k2-s">generated</div></div>
            </div>
            <div class="gs-table">
              <div class="gs-tr gs-th"><span>Item</span><span>Expires</span><span>Status</span></div>
              <div class="gs-tr"><span>Fire certificate</span><span>2026-09-12</span><span class="gs-st st-sent">Valid</span></div>
              <div class="gs-tr"><span>Trade license</span><span>2026-07-30</span><span class="gs-st st-urg">Expiring</span></div>
              <div class="gs-tr"><span>Building safety</span><span>2027-01-15</span><span class="gs-st st-sent">Valid</span></div>
            </div>
            <div class="gs-case">👨‍⚖️ Dispute · Unit 4B · <span class="gs-st st-open">Open</span></div>
          </div>
        </div>

        <!-- ── SAFETY & SECURITY HUB ── -->
        <div v-else-if="variant === 'safety'" class="gs-safety">
          <div class="gs-rail">
            <div v-for="(n, i) in ['Building systems','Smart home','Land','Build','Fire safety','KYC','Inspections']" :key="n" class="gs-rail-i" :class="{ on: i === 0 }">{{ n }}</div>
          </div>
          <div class="gs-safety-main">
            <div class="gs-fh"><b>Building systems</b><span class="gs-btn-sm prim">➕ Add device</span></div>
            <div class="gs-dev-grid">
              <div class="gs-dev"><span>🌡️ Smart thermostat</span><span class="gs-st st-sent">Online</span></div>
              <div class="gs-dev"><span>🚪 Access control</span><span class="gs-st st-sent">Online</span></div>
              <div class="gs-dev"><span>💡 Lighting panel</span><span class="gs-st st-urg">Offline</span></div>
            </div>
            <div class="gs-fire">🔥 Fire certificate · valid till <b>2026-12-01</b> · <span class="gs-st st-sent">Valid</span></div>
            <div class="gs-insp">📋 Inspections · 5 scheduled · 2 due this week</div>
          </div>
        </div>

        <!-- ── FINANCE HUB (full) ── -->
        <div v-else-if="variant === 'finhub'" class="gs-finhub">
          <div class="gs-rail">
            <div v-for="(n, i) in ['Invoices','Receipts','Collections','Payments','Taxes','Remittances','Statements','Accounts']" :key="n" class="gs-rail-i" :class="{ on: i === 0 }">{{ n }}</div>
          </div>
          <div class="gs-finhub-main">
            <div class="gs-kpis2">
              <div class="gs-kpi2">Gross <b>৳8.3L</b></div>
              <div class="gs-kpi2">Collected <b>৳5.2L</b></div>
              <div class="gs-kpi2">Outstanding <b>৳3.1L</b></div>
            </div>
            <div class="gs-table">
              <div class="gs-tr gs-th"><span>Invoice</span><span>Tenant</span><span>Amount</span><span>Status</span></div>
              <div class="gs-tr"><span>INV-1042</span><span>Tanvir A.</span><span>৳45,000</span><span class="gs-st st-sent">Sent</span></div>
              <div class="gs-tr"><span>INV-1043</span><span>Rakib H.</span><span>৳32,000</span><span class="gs-st st-paid">Paid</span></div>
              <div class="gs-tr"><span>INV-1044</span><span>Nadia S.</span><span>৳28,500</span><span class="gs-st st-draft">Draft</span></div>
            </div>
          </div>
        </div>

        <!-- ── RECORD RECEIPT WORKFLOW ── -->
        <div v-else-if="variant === 'receipt'" class="gs-receipt">
          <div class="gs-receipt-l">
            <div class="gs-fh"><b>Invoice INV-1042</b><span class="gs-st st-sent">Sent</span></div>
            <div class="gs-rc-line">Tenant: <b>Tanvir A.</b> · Unit 4B</div>
            <div class="gs-rc-line">Amount: <b>৳45,000</b> · Due 05 Aug</div>
            <div class="gs-rc-line">Method: <span class="gs-st st-paid">bKash</span></div>
            <div class="gs-btn-sm prim" style="margin-top:8px;display:inline-block">💳 Record payment</div>
          </div>
          <div class="gs-receipt-r">
            <div class="gs-rc-t">🧾 Receipt issued</div>
            <div class="gs-rc-line">RCPT-2208 · ৳45,000</div>
            <div class="gs-rc-line">Status: <span class="gs-st st-paid">Paid</span></div>
            <div class="gs-rc-note">Collections updated automatically</div>
          </div>
        </div>

        <!-- ── MAINTENANCE TICKET WORKFLOW ── -->
        <div v-else-if="variant === 'maint'" class="gs-maint">
          <div class="gs-maint-l">
            <div class="gs-fh"><b>Raise ticket</b></div>
            <div class="gs-f" style="margin-bottom:5px"></div>
            <div class="gs-f" style="margin-bottom:5px"></div>
            <div class="gs-f" style="margin-bottom:5px"></div>
            <div class="gs-btn" style="margin-top:4px"></div>
            <div class="gs-link" style="margin-top:5px">Unit · Category · Priority · Title</div>
          </div>
          <div class="gs-maint-r">
            <div class="gs-rc-t">Ticket created → MT-118</div>
            <div class="gs-rc-line">SLA: <span class="gs-st st-prog">4h left</span> · <span class="gs-pill2 p-sh">Shared</span></div>
            <div class="gs-rc-line">Timeline: Created → Assigned → Done</div>
            <div class="gs-rc-note">Staff see it instantly; SLA breach alerts fire</div>
          </div>
        </div>

        <!-- highlight pins -->
        <template v-for="(h, i) in highlights" :key="'h' + i">
          <div class="gs-hl" :style="{ left: h.x + '%', top: h.y + '%', width: h.w + '%', height: h.h + '%', borderColor: PALETTE[i % PALETTE.length] }">
            <span class="gs-hl-n" :style="{ background: PALETTE[i % PALETTE.length] }">{{ i + 1 }}</span>
          </div>
        </template>
      </div>
    </div>

    <div class="gs-legend">
      <div v-for="(h, i) in highlights" :key="'s' + i" class="gs-step">
        <span class="gs-step-n" :style="{ background: PALETTE[i % PALETTE.length] }">{{ i + 1 }}</span>
        <div>
          <div class="gs-step-t">{{ h.t }}</div>
          <div class="gs-step-d">{{ h.d }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.gs { margin: 6px 0 14px; }
.gs-frame { border: 1px solid var(--border); border-radius: 12px; overflow: hidden; background: var(--card); box-shadow: var(--shadow); }
.gs-bar { display: flex; align-items: center; gap: 6px; padding: 7px 10px; background: var(--bg-alt); border-bottom: 1px solid var(--border); }
.gs-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.gs-url { flex: 1; margin-left: 8px; font-size: 10px; color: var(--text-mute); background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 2px 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gs-body { position: relative; }
.gs-hl { position: absolute; border: 2px dashed; border-radius: 8px; pointer-events: none; box-sizing: border-box; z-index: 5; }
.gs-hl-n { position: absolute; top: -11px; left: -8px; min-width: 18px; height: 18px; border-radius: 9px; color: #fff; font-size: 10px; font-weight: 800; display: flex; align-items: center; justify-content: center; padding: 0 3px; box-shadow: 0 2px 6px rgba(0,0,0,.35); }
.gs-legend { margin-top: 10px; display: flex; flex-direction: column; gap: 8px; }
.gs-step { display: flex; gap: 10px; align-items: flex-start; }
.gs-step-n { min-width: 20px; height: 20px; border-radius: 10px; color: #fff; font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; margin-top: 1px; flex-shrink: 0; }
.gs-step-t { font-weight: 700; font-size: 12.5px; }
.gs-step-d { font-size: 11.5px; color: var(--text-soft); line-height: 1.5; margin-top: 1px; }

/* shared atoms */
.gs-btn-sm { font-size: 9.5px; font-weight: 700; color: var(--primary); border: 1px solid var(--primary); border-radius: 6px; padding: 3px 8px; white-space: nowrap; }
.gs-btn-sm.prim { background: var(--grad); color: #fff; border: none; }
.gs-chip { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.c-r { background: var(--danger); } .c-y { background: var(--warn); } .c-b { background: var(--primary); }
.gs-st { font-size: 8px; font-weight: 800; padding: 2px 6px; border-radius: 8px; white-space: nowrap; text-transform: uppercase; letter-spacing: .3px; }
.st-sent { background: var(--primary-light); color: var(--primary); }
.st-paid { background: rgba(39,174,96,.16); color: var(--ok); }
.st-draft { background: var(--bg-alt); color: var(--text-mute); }
.st-urg { background: rgba(231,76,60,.16); color: var(--danger); }
.st-open { background: rgba(47,128,237,.14); color: var(--primary); }
.st-prog { background: rgba(230,126,34,.16); color: var(--warn); }
.st-med { background: var(--bg-alt); color: var(--text-soft); }
.st-res { background: rgba(39,174,96,.16); color: var(--ok); }
.st-clo { background: var(--bg-alt); color: var(--text-mute); }

/* login */
.gs-login { height: 210px; display: flex; align-items: center; justify-content: center; background: var(--bg-alt); }
.gs-login-card { width: 240px; padding: 14px 16px; border: 1px solid var(--border); border-radius: 12px; background: var(--card); }
.gs-logo { font-weight: 800; font-size: 13px; color: var(--text); margin-bottom: 10px; }
.gs-f { height: 18px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg); }
.gs-btn { height: 24px; border-radius: 6px; background: var(--grad); }
.gs-link { font-size: 9px; color: var(--primary); text-align: center; }

/* dashboard */
.gs-dash { height: 230px; display: flex; background: var(--bg); }
.gs-side { width: 128px; flex-shrink: 0; border-right: 1px solid var(--border); padding: 8px 6px; background: var(--bg-alt); }
.gs-side-logo { font-size: 10px; font-weight: 800; color: var(--text); margin: 2px 4px 8px; }
.gs-nav { font-size: 8.5px; color: var(--text-soft); padding: 4px 6px; border-radius: 5px; margin-bottom: 2px; }
.gs-nav.on { background: var(--primary-light); color: var(--primary); font-weight: 700; }
.gs-main { flex: 1; padding: 6px 10px; min-width: 0; }
.gs-head { display: flex; justify-content: flex-end; gap: 8px; margin-bottom: 6px; }
.gs-search { width: 90px; height: 12px; border: 1px solid var(--border); border-radius: 6px; background: var(--bg-alt); }
.gs-bell { font-size: 12px; }
.gs-kpis { display: flex; gap: 8px; margin-bottom: 8px; }
.gs-kpi { flex: 1; border: 1px solid var(--border); border-radius: 8px; padding: 6px 8px; background: var(--card); }
.gs-kpi-t { font-size: 8px; color: var(--text-mute); text-transform: uppercase; letter-spacing: .4px; }
.gs-kpi-v { font-size: 13px; font-weight: 800; color: var(--text); }
.gs-cards { display: flex; gap: 8px; }
.gs-ai { flex: 1.2; border: 1px solid var(--border); border-radius: 8px; padding: 8px; background: var(--card); }
.gs-ai-t { font-size: 9.5px; font-weight: 800; color: var(--text); margin-bottom: 5px; }
.gs-ai-q { font-size: 8.5px; color: var(--text-mute); background: var(--bg-alt); border: 1px solid var(--border); border-radius: 6px; padding: 4px 6px; }
.gs-al { flex: 1; border: 1px solid var(--border); border-radius: 8px; padding: 6px 8px; background: var(--card); }
.gs-al-r { display: flex; align-items: center; gap: 5px; font-size: 8px; color: var(--text-soft); padding: 3px 0; border-bottom: 1px dashed var(--border); }
.gs-al-r:last-child { border-bottom: none; }

/* notifications */
.gs-notify { height: 190px; padding: 10px 14px; background: var(--card); }
.gs-n-head { display: flex; justify-content: space-between; align-items: center; font-size: 11px; color: var(--text); padding-bottom: 6px; }
.gs-n-mark { font-size: 9px; color: var(--primary); }
.gs-n-pills { display: flex; gap: 5px; padding: 6px 0; }
.gs-pill { font-size: 8.5px; color: var(--text-soft); border: 1px solid var(--border); border-radius: 10px; padding: 3px 8px; }
.gs-pill.on { background: var(--primary-light); color: var(--primary); border-color: transparent; font-weight: 700; }
.gs-n-row { display: flex; gap: 8px; align-items: flex-start; padding: 7px 4px; border-bottom: 1px solid var(--border); font-size: 9px; color: var(--text); }
.gs-n-row:last-child { border-bottom: none; }
.gs-n-sub { font-size: 8px; color: var(--text-mute); margin-top: 2px; }

/* finance */
.gs-fin { padding: 10px 14px; background: var(--card); }
.gs-fh { display: flex; justify-content: space-between; align-items: center; font-size: 11px; color: var(--text); padding-bottom: 8px; }
.gs-table { border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.gs-tr { display: grid; grid-template-columns: 1.1fr 1.2fr 1fr .8fr; gap: 6px; padding: 7px 10px; font-size: 9px; color: var(--text-soft); border-bottom: 1px solid var(--border); align-items: center; }
.gs-th { font-size: 8px; font-weight: 800; text-transform: uppercase; letter-spacing: .4px; color: var(--text-mute); background: var(--bg-alt); }
.gs-tr:last-child { border-bottom: none; }

/* notice board */
.gs-notice { padding: 10px 14px; background: var(--card); }
.gs-ncard { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; background: var(--bg-alt); }
.gs-nc-t { font-size: 10.5px; font-weight: 800; color: var(--text); margin-bottom: 4px; }
.gs-nc-b { font-size: 8.5px; color: var(--text-soft); margin-bottom: 8px; line-height: 1.5; }
.gs-email { display: flex; align-items: center; gap: 6px; font-size: 9px; color: var(--text-soft); }
.gs-chk { width: 12px; height: 12px; border-radius: 4px; border: 1.5px solid var(--border); display: inline-block; }
.gs-chk.on { background: var(--ok); border-color: var(--ok); position: relative; }
.gs-chk.on::after { content: '✓'; position: absolute; top: -2px; left: 2px; color: #fff; font-size: 9px; font-weight: 800; }
.gs-cnt { margin-left: auto; font-size: 8.5px; color: var(--primary); font-weight: 700; }
.gs-post { margin-top: 10px; font-size: 9px; font-weight: 800; color: #fff; background: var(--grad); border-radius: 6px; padding: 5px; text-align: center; }

/* support */
.gs-supp { padding: 10px 14px; background: var(--card); }
.gs-kpis2 { display: flex; gap: 8px; padding: 4px 0 8px; }
.gs-kpi2 { flex: 1; border: 1px solid var(--border); border-radius: 8px; padding: 5px 8px; font-size: 8.5px; color: var(--text-mute); }
.gs-kpi2 b { font-size: 13px; color: var(--text); margin-left: 3px; }
.gs-supp-body { display: flex; gap: 8px; }
.gs-tlist { flex: 1; display: flex; flex-direction: column; gap: 6px; }
.gs-trow { border: 1px solid var(--border); border-radius: 8px; padding: 7px 8px; display: flex; align-items: center; gap: 6px; background: var(--bg-alt); }
.gs-pill2 { font-size: 7.5px; font-weight: 800; padding: 2px 6px; border-radius: 8px; flex-shrink: 0; }
.p-bill { background: rgba(230,126,34,.16); color: var(--warn); }
.p-tech { background: var(--primary-light); color: var(--primary); }
.gs-t-mid { flex: 1; min-width: 0; }
.gs-t-mid b { font-size: 9.5px; color: var(--text); }
.gs-t-sub { font-size: 8px; color: var(--text-mute); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gs-drawer { flex: 1.3; border: 1px solid var(--border); border-radius: 8px; padding: 8px; background: var(--bg-alt); display: flex; flex-direction: column; gap: 6px; }
.gs-bub { font-size: 8px; padding: 4px 7px; border-radius: 8px; max-width: 85%; }
.gs-bub.me { background: var(--primary-light); color: var(--text); align-self: flex-start; }
.gs-bub.you { background: var(--card); border: 1px solid var(--border); color: var(--text); align-self: flex-end; }
.gs-reply { font-size: 8px; color: var(--text-mute); border: 1px dashed var(--border); border-radius: 6px; padding: 4px 7px; margin-top: auto; }
.gs-stats { display: flex; gap: 4px; flex-wrap: wrap; }

/* settings */
.gs-settings { padding: 8px 14px; background: var(--card); }
.gs-srow { display: flex; justify-content: space-between; align-items: center; padding: 10px 4px; border-bottom: 1px solid var(--border); font-size: 10px; color: var(--text); }
.gs-srow:last-child { border-bottom: none; }
.gs-tgl { width: 26px; height: 14px; border-radius: 8px; background: var(--border); position: relative; flex-shrink: 0; }
.gs-tgl.on { background: var(--ok); }
.gs-tgl::after { content: ''; position: absolute; top: 2px; left: 2px; width: 10px; height: 10px; border-radius: 50%; background: #fff; }
.gs-tgl.on::after { left: 14px; }
.gs-link2 { font-size: 13px; color: var(--text-mute); }

/* tenant portal */
.gs-tenant { padding: 10px 14px; background: var(--card); display: flex; flex-direction: column; gap: 8px; }
.gs-tcard { border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; background: var(--bg-alt); }
.gs-tc-t { font-size: 8px; font-weight: 800; text-transform: uppercase; letter-spacing: .4px; color: var(--text-mute); margin-bottom: 3px; }
.gs-tc-m { font-size: 10px; font-weight: 700; color: var(--text); }
.gs-tc-d { font-size: 8.5px; color: var(--text-soft); margin-top: 2px; }
.gs-trow2 { display: flex; align-items: center; gap: 8px; font-size: 9.5px; color: var(--text); }
.gs-trow2 .gs-st { margin-left: auto; }

/* module hubs: shared left rail */
.gs-rail { width: 96px; flex-shrink: 0; border-right: 1px solid var(--border); padding: 8px 6px; background: var(--bg-alt); display: flex; flex-direction: column; gap: 2px; }
.gs-rail-i { font-size: 8px; color: var(--text-soft); padding: 4px 6px; border-radius: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gs-rail-i.on { background: var(--primary-light); color: var(--primary); font-weight: 700; }
.gs-k2-s { font-size: 7.5px; color: var(--text-mute); margin-top: 1px; }

/* portfolio */
.gs-port { height: 240px; display: flex; background: var(--bg); }
.gs-port-main { flex: 1; padding: 8px 12px; min-width: 0; }
.gs-port-grid { display: flex; flex-direction: column; gap: 6px; }
.gs-pcard { border: 1px solid var(--border); border-radius: 8px; padding: 6px 9px; background: var(--card); }
.gs-pc-t { font-size: 9.5px; font-weight: 800; color: var(--text); margin-bottom: 2px; }
.gs-pc-s { font-size: 8px; color: var(--text-soft); }
.gs-port-hier { margin-top: 8px; text-align: center; }
.gs-hier { font-size: 8px; color: var(--primary); background: var(--primary-light); border-radius: 10px; padding: 3px 10px; font-weight: 700; }

/* bms */
.gs-bms { height: 230px; display: flex; background: var(--bg); }
.gs-bms-main { flex: 1; padding: 8px 12px; min-width: 0; }
.gs-bms-list { display: flex; flex-direction: column; gap: 6px; }
.gs-brow { display: flex; align-items: center; gap: 6px; border: 1px solid var(--border); border-radius: 8px; padding: 7px 9px; background: var(--card); font-size: 9px; color: var(--text); }
.gs-brow .gs-st { margin-left: auto; }
.p-lt { background: rgba(47,128,237,.14); color: var(--primary); }
.p-tt { background: rgba(231,76,60,.14); color: var(--danger); }
.p-sh { background: rgba(230,126,34,.14); color: var(--warn); }

/* community */
.gs-comm { height: 225px; display: flex; background: var(--bg); }
.gs-comm-main { flex: 1; padding: 8px 12px; min-width: 0; display: flex; flex-direction: column; gap: 7px; }
.gs-ref { display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; background: var(--card); font-size: 9px; color: var(--text); }
.gs-ref-code { font-size: 8.5px; font-weight: 800; color: var(--primary); background: var(--primary-light); border-radius: 6px; padding: 2px 8px; }
.gs-trust { font-size: 8.5px; color: var(--text-soft); background: var(--bg-alt); border: 1px dashed var(--border); border-radius: 8px; padding: 6px 10px; }

/* legal */
.gs-legal { height: 240px; display: flex; background: var(--bg); }
.gs-legal-main { flex: 1; padding: 8px 12px; min-width: 0; }
.gs-case { font-size: 8.5px; color: var(--text-soft); border: 1px dashed var(--border); border-radius: 8px; padding: 6px 10px; margin-top: 7px; display: flex; align-items: center; gap: 6px; }

/* safety */
.gs-safety { height: 240px; display: flex; background: var(--bg); }
.gs-safety-main { flex: 1; padding: 8px 12px; min-width: 0; display: flex; flex-direction: column; gap: 7px; }
.gs-dev-grid { display: flex; flex-direction: column; gap: 5px; }
.gs-dev { display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--border); border-radius: 7px; padding: 6px 9px; background: var(--card); font-size: 9px; color: var(--text); }
.gs-fire { font-size: 8.5px; color: var(--text); background: var(--bg-alt); border: 1px dashed var(--border); border-radius: 8px; padding: 6px 10px; }
.gs-insp { font-size: 8.5px; color: var(--text-soft); }

/* finance hub */
.gs-finhub { height: 235px; display: flex; background: var(--bg); }
.gs-finhub-main { flex: 1; padding: 8px 12px; min-width: 0; }

/* receipt workflow */
.gs-receipt { height: 200px; padding: 10px 14px; background: var(--card); display: flex; gap: 10px; }
.gs-receipt-l { flex: 1; border: 1px solid var(--border); border-radius: 10px; padding: 9px 11px; background: var(--bg-alt); display: flex; flex-direction: column; gap: 6px; }
.gs-receipt-r { flex: 1; border: 1px solid var(--border); border-radius: 10px; padding: 9px 11px; background: var(--bg); display: flex; flex-direction: column; gap: 6px; }
.gs-rc-t { font-size: 10px; font-weight: 800; color: var(--text); margin-bottom: 2px; }
.gs-rc-line { font-size: 9px; color: var(--text-soft); }
.gs-rc-note { font-size: 8px; color: var(--ok); margin-top: auto; font-weight: 700; }

/* maintenance ticket workflow */
.gs-maint { height: 200px; padding: 10px 14px; background: var(--card); display: flex; gap: 10px; }
.gs-maint-l { flex: 1; border: 1px solid var(--border); border-radius: 10px; padding: 9px 11px; background: var(--bg-alt); display: flex; flex-direction: column; }
.gs-maint-r { flex: 1; border: 1px solid var(--border); border-radius: 10px; padding: 9px 11px; background: var(--bg); display: flex; flex-direction: column; gap: 6px; }
</style>
