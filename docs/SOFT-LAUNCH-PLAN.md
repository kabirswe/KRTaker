# KRTaker Soft-Launch Plan (GO-LIVE §4.3 step 1)

**Date:** 2026-08-17 · **Status:** Ready to execute — gated on merchant decision (SSLCommerz KYC) for paid flows

---

## 1. Objective

Land **10–20 real owner/building accounts** in 2–4 weeks, collect feedback, fix crash bugs, and validate the payment UX with real ৳ transactions — **before** public launch.

## 2. Who to invite (the real list)

Truthful inventory (V2.47 audit — marketing claims removed):
- **10 subscribers** currently in DB: **8 active** (verified, have accounts), **0 paid**
- **5 partners** (demo/reseller relationships)
- **৳75.55 Cr** property portfolio represented in the system (owner 130 + demo orgs)
- Kabir's network: Dhaka landlord/developer community (LinkedIn, the original "128 list" — verify against real DB before using; most were marketing-fiction)
- Belal's BITSCOL network: building owners, property managers met through the MARS ERP / inceptia work

**Target mix:** 60% small owners (1–3 units, simplest win) · 25% mid landlords (5–20 units, most revenue) · 15% property managers/agents (high leverage).

## 3. Offer (free / discounted — gate paid flows)

Since **payment gateway is not live yet** (KYC pending), the offer structure is:

| Tier | What they get | Cost |
|---|---|---|
| **Founding Owner** (first 10) | Business plan, 12 months, all modules (incl. AI console + WhatsApp) | ৳0 — free annual (value ৳7,200) |
| **Founding Partner** (next 10) | Business plan, 6 months | 50% off (৳300/mo → ৳150/mo) |
| Post-launch | Standard pricing (landing: 14-day trial → Starter/Business) | full |

- **Free period = 12 months** for the first 10 (matches the trial infrastructure; flips to billed at renewal)
- Rationale: 0 paid users today; getting real usage + testimonials + bug reports is worth more than ৳7,200/yr per owner
- **No credit card required** — avoids friction and the no-live-gateway problem

## 4. Activation funnel (what "success" means)

Each invited owner must reach: **signup → email verified → first property added → first tenant added → first rent invoice → first collection recorded**

- Registration flow: `register.html` (multi-step, OTP, 14-day trial) — works today
- Setup nudge cron (`58f177815044`, 08:00 UTC) auto-emails anyone who verified but added no property
- First-tenant nudge (V2.42) emails after property added, before tenant
- Rent-due reminders (V2.22) + push (v22) engage them once invoicing starts

**Weekly KPI readout (from `app-kpi-daily`, cron `ad72cc903f0e` 05:45 UTC):**
- Signups (today / 7d), active accounts, setup completions, collections (count + ৳), AI calls, tickets, errors
- **Track specifically:** signup→activation %, first-invoice %, first-collection %, 7-day retention

## 5. Feedback loop

- **In-app:** SupportView (tickets, SLA chips, V2.43) + Wiki/Help (V2.41, বাংলা) — owners file tickets in-app
- **Weekly sync:** 1:1 or group call with 2–3 most-active owners (Kabir + Belal)
- **Bug triage:** error-watchdog cron (every 30 min) + error-log panel in superadmin; KPI digest flags spikes
- **Log everything to the ticket system** (SUP-XXX), not chat — so nothing is lost

## 6. Payment UX validation (when gateway is live)

Once SSLCommerz KYC lands:
1. Flip `gw_config` via `flip_gateway_live.py` (dry-run default; `--apply` with live creds)
2. Run the **sandbox matrix first** (`sandbox_payment_matrix.py` — 15/15 on simulated path) against real sandbox store
3. Then one **real ৳1 test transaction** through the live store
4. Record in GO-LIVE §5 Payments row + sandbox-test-matrix doc

Until then: founders use the platform free; **no payments processed**. The refund system (V2.51) is ready and simulate-tested — real refund test happens with the same creds.

## 7. Risks & mitigations

| Risk | Mitigation |
|---|---|
| No gateway → can't charge founders | Free 12-mo offer; no CC required; flips at renewal |
| Low activation (owners don't add data) | Setup-nudge + first-tenant nudge crons; Belal/Kabir personally onboard first 5 |
| Bengali users struggle with EN UI | Full বাংলা (V2.41: 2,386 keys, wiki + support localized); default language is বাংলা |
| Data-entry burden (land records) | Excel master-data templates (same as MARS playbook §3); offer bulk-import service |
| Churn after trial | 12-mo founder lock; collect testimonials during soft launch |
| Payment gateway KYC delays | Soft launch can proceed WITHOUT payments; gate only the paid tier |

## 8. Timeline

| Week | Action |
|---|---|
| W1 (Aug 17–23) | Finalize offer, invite list, invite email/WhatsApp template; merchant entity decision + SSLCommerz KYC application filed |
| W2 (Aug 24–30) | Onboard first 5 owners personally (data entry + training); collect feedback; fix bugs as found |
| W3 (Aug 31–Sep 6) | Onboard 5–10 more; first rent invoices in real orgs; payment UX test (if KYC done) |
| W4 (Sep 7–13) | Review feedback, fix crash bugs, run full regression; decide public-launch date |

## 9. What's needed from Belal/Kabir (this week)

1. **Merchant legal entity decision** (BITSCOL vs new KRTaker entity) + BIN/address → SSLCommerz KYC (the gate for paid tier)
2. **Invite list** — 10–20 real owners from network (don't use the fiction "128")
3. Approve the **free 12-month founding offer** (or adjust price/duration)
4. **Search Console verification** (I'll walk you through — 5 min) so organic traffic is tracked from day one

---

*Prepared 2026-08-17. Numbers from live DB (V2.47 truthful audit). Companion docs: GO-LIVE.md §4.3/§4.4, docs/SSLCOMMERZ_TICKET.md, docs/sandbox-test-matrix.md.*
