# KRTaker Status Report — for Kabir

**Date:** 2026-08-17 · **Branch:** superadmin-panel · **HEAD:** 26e9497 (3/3 remotes converged)

---

## 1. Overall status: LAUNCH-READY on the code side

All code-able launch items are **done and verified live**:

- **Launch audit 36/36 PASS** (tests/launch_audit.py) — health, SSL expiry (Oct 12), security headers, 8 landing pages + GA4 presence, sitemap 37/37, robots, 6 blog slugs + unknown-404, IndexNow, API auth gates, backup freshness, disk 43%
- **Regression 51/51 PASS** (tests/live_regression.py)
- **V2.51 shipped + deployed live** (2026-08-17):
  - **Superadmin SMS Tracker dashboard** — `sms_log.kind` classification (reminder/otp/test/other), 14-day trend, purpose+provider breakdown, config panel (`log` or `bulksmsbd`), test-send
  - **Refund / return system** (payment gateway) — refunds ledger (`RF-XXX` IDs, full audit trail), auto-detect bKash/SSLCommerz/Nagad, duplicate guards, amount cap, only `Success` payments refundable; **simulated until merchant creds**, zero-code-change switch to real provider refund APIs
- **Finance > Accounts tab crash fixed** — `v-for="t"` shadowed the i18n `t()` → `t is not a function` → blank tab for owners with transactions. Renamed loop vars in AccountsView + 2 latent crashes (Tenants grid, UtilityBills tariffs). Rebuilt + redeployed app-v3 (114/114), verified live, 0 console errors.
- **Offsite backup leg restored** — Google Drive token re-authed (was dead since Aug 15, sync silently skipped), watchdog script path fixed, backups verified on Drive (krtaker + cms dumps, daily 02:00 + weekly snapshots)

## 2. Wiki bug — FIXED (V2.41.0, shipped Aug 15)

- **Root cause:** `WikiView.vue` wrapped 60+ article bodies in the i18n `t()` function in V2.40.6 but **never imported `t`** (`import { lang, t as tr }`) — the Wiki page was **broken since V2.40.6**. Same latent bug in `CompactFilters.vue` (`l.t is not a function`) which broke the Support toolbar's New Ticket button on any view using it.
- **Fix:** proper `t` import in WikiView + CompactFilters; SECTIONS converted to `computed()` so articles re-translate on the EN/বাংলা toggle; full বাংলা localization (i18n dict 2,313 → 2,386 keys, 0 missing); SupportView fully localized too.
- **Verified live** as probe org in বাংলা: wiki renders fully (10 sections, expand/collapse works), support + compose modal in Bengali, 0 console errors.

## 3. Mail OTP latency — ADDRESSED (queued-mail redesign)

- **Context:** sending mail synchronously on the request path made auth + transactional flows slow (SMTP round-trip on every email).
- **Fix (shipped earlier, V2.22-era mail queue):** off-request-path queue — `mail_queue` table, `queue_mail()`/`send_mail(queued)`, `app-mail-worker` endpoint drained by a 15-min cron. Collections/reminders/welcome/compliance emails are queued.
- **OTP is deliberately kept INLINE (not queued)** — login codes must arrive immediately, so OTP sends synchronously and is never held in the queue; a mail failure is caught so it never breaks the auth flow. 15-min worker cron healthy, queue drains clean.

## 4. careers@inceptia.io relay — RUNNING

- CV pipeline (mail.inceptia.io:993 IMAP → erp.appvaley.com) processes careers@inceptia.io emails one-by-one: fetch + download CV → parse (pdfplumber) → duplicate check → create/update candidate in the ERP recruitment module (TME-XXX codes). Reports every 10; resumable via processed-email tracking.

---

## 5. Still blocked on decisions (need inputs)

| # | Item | Blocker | Impact |
|---|---|---|---|
| 1 | **Merchant legal entity + BIN/address** (BITSCOL vs KRTaker) | decision | #1 — SSLCommerz KYC (weeks lead time) |
| 2 | SSLCommerz **sandbox creds** (free, no KYC) | creds | one real-sandbox payment test (GO-LIVE item 7) |
| 3 | Real **SMS provider** creds (SSLCommerz SMS / DopeSMS) | creds | currently `log` (simulated) |
| 4 | **DeepSeek** prod API key | creds | AI console |
| 5 | **Search Console** access | access | sitemap submission/verification |
| 6 | Google Drive OAuth app **publish** (2 clicks) | action | token dies every ~7d otherwise (re-auth done, but recurring) |

**Live real numbers (truthful copy):** 10 subscribers (8 active, 0 paid), 5 partners, ৳75.55 Cr property portfolio.

## 6. Ask

1. Legal entity decision (BITSCOL vs new entity) so we can start SSLCommerz KYC this week.
2. Send SSLCommerz sandbox store credentials → I'll run the real-sandbox payment + refund test.
3. Publish the Drive OAuth app (or authorize a weekly re-auth).
