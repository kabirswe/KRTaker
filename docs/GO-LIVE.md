# KRTaker — Go-Live Checklist (real users, Bangladesh)

Status legend: `[ ]` not started · `[~]` in progress · `[x]` done · `[!]` blocked/needs input
Last updated: 2026-08 · Current live version: v3.66 / SW v74 · Branch: `superadmin-panel`

---

## 0. Legal & Business Foundation (Bangladesh)

### 0.1 Company identity — MUST be done before payments/merchant KYC
- [ ] Confirm the operating legal entity (footer says "Developed By: BITSCOL" — decide: BITSCOL Ltd is the merchant, or register a KRTaker entity). Every payment gateway + bank account must belong to ONE consistent legal entity.
- [ ] RJSC certificate of incorporation (if using a new KRTaker entity: name availability check → registration with RJSC, ~2–4 weeks, fees depend on capital).
- [ ] Trade license (City Corporation / Pourasabha / Upazila) — renewed annually.
- [ ] e-TIN (individual or company) — from NBR; needed for bank account + gateway KYC.
- [ ] BIN (Business Identification Number) — VAT registration from NBR if annual turnover crosses VAT threshold; even if below, register voluntarily because bKash/Nagad/SSLCommerz merchant onboarding usually asks for BIN.
- [ ] Company bank account (current account) in the entity's name — needed by all gateways.
- [ ] Business email domain (e.g. billing@krtaker.com, support@krtaker.com) + shared inbox. Current prod sender is krtaker@server.coderdrop.com — decide if that stays or moves to a branded address.

### 0.2 Tax & VAT (Bangladesh SaaS)
- [ ] Confirm VAT treatment of the SaaS subscription: domestic software/SaaS services are subject to 15% VAT (SDA services). Prices on pricing.html are ৳ — decide whether VAT is inclusive or added at checkout; keep the price display consistent with what the gateway charges.
- [ ] Build the monthly VAT return process (Mushak 9.1) — export the transaction list per month from the DB (app-payment-recon gives you the ledger).
- [x] Issue proper VAT-compliant invoices/receipts to subscribers. `app-invoice-print` exists — verify it prints a compliant Mushak-6.3-style invoice with BIN, invoice no, VAT line. (V2.43.x 2026-08-16: `invoice_print_html` upgraded — **MUSHAK-6.3 badge**, seller block (org name/address/phone/BIN/e-TIN driven by new `org_bin`/`org_etin`/`org_address`/`org_phone`/`vat_rate` org-settings keys), tenant NID on billed-to, **VAT line** (rate% × gross) when `vat_rate > 0`, invoice serial already INV-YYYY-####. Verified live: rendered INV-2026-0020 with test config → BIN + e-TIN + address + `VAT (15%) ৳6,000` all present, net payable correct; reverted to clean defaults (fields hide when unconfigured — fill in real BIN/e-TIN once §0.1 entity confirmed).)
- [ ] Keep books: income vs. expense ledger; bank statements match gateway settlements monthly (reconciliation — app-payment-recon already does this internally; export for the accountant).

### 0.3 Intellectual property & contracts
- [ ] Trademark search + registration of "KRTaker" with DPDT (Department of Patents, Designs & Trademarks) — class 42 (SaaS/software) primarily; ~1–2 years but filing early locks priority.
- [ ] Copyright registration of the software (Bangladesh Copyright Act 2000) — optional but cheap; useful if ever litigating.
- [ ] Domain: confirm krtaker.com is owned by the legal entity (registrant WHOIS), auto-renew ON, lock enabled.
- [ ] Contracts: subscriber Terms of Service + Privacy Policy + Refund Policy must reference the legal entity name, be reviewed by a BD lawyer, and be GDPR/DPA-2023-aware (the product already has app-gdpr-export — keep it).

---

## 1. Data Protection & Security Compliance

### 1.1 Bangladesh Data Protection Act 2023
- [ ] Read the Act + Rules (in force — confirm current registration thresholds/effective dates with a lawyer before relying on specifics).
- [ ] Determine if KRTaker is a "significant" data fiduciary → register with the appropriate authority if threshold applies (processes citizens' personal data; property/rental data).
- [ ] Appoint/designate a Data Protection Officer (can be internal, documented).
- [ ] Update Privacy Policy: lawful basis, retention periods, data-subject rights (access/correct/erase/port — app-gdpr-export covers export; add "delete my data" flow).
- [ ] Data localization: keep subscriber data on the BD cPanel host (it is). If you add a US VPS/DB (Phase 7 PG), document cross-border transfer basis.
- [ ] Breach notification plan: internal incident log + notify regulator/users within the Act's window (verify current requirement — DSA-2018 era was 72h-ish for some classes; confirm under DPA-2023).
- [ ] Consent: onboarding must capture explicit consent for marketing email/push (check register flow; the v19 push permission already asks — make sure opt-in is recorded per-email in DB).

### 1.2 Security hardening (continue the v21 program)
- [ ] Security headers + CSP already scoped (v21b/v21c) — re-audit once more for the dashboard + API.
- [ ] Rate limiting + login throttling verified under real load (v21 has throttle; stress it).
- [x] 2FA for subscriber logins + admin (superadmin) — assess; even optional TOTP for owners is a differentiator. (V2.16 gate exists: TOTP (RFC 6238, pure PHP) + email-OTP fallback on `app_users`/subscribers via `totp_secret`/`totp_enabled`/`twofa_method`; superadmin TOTP live. Owner-level TOTP is available on the same gate — enable per-account in Settings when onboarding real owners.)
- [x] Session hygiene: token expiry, revocation on role change (exists for team), force logout-all. (Verified 2026-08-16: `app_tokens` hashed at rest (sha256), `expires_at` enforced + pruned, `last_seen` throttled ≤1 write/5 min; `app-sessions` supports revoke one / revoke_others / revoke_all with current-session detection; `app-logout` deletes the bearer token.)
- [x] Secrets management: gateway store_id/store_pass, SMTP password, DeepSeek key — move out of PHP constants to environment/.env or a secrets table (they are in the DB/platform_meta already for some — audit which keys are hardcoded). (Audited 2026-08-16: secrets live in `/home/krtaker/krtaker.env.php` OUTSIDE webroot (DB_PATH, TRIAL_DAYS, ADMIN_EMAIL, APP_SETUP_KEY, TOKEN_TTL, SERVICE_KEY, VAPID_PRIV/PUB/SUB, SMTP) — confirmed present via live probe. Gateway creds (SSLCommerz `store_pass`, bKash `app_secret`) are `REPLACE_ME` placeholders in code, real values in DB gateways config. AI key: env `KRT_DS_KEY` → `admin_settings.ai_key` → offline fallback. Repo copy carries scrubbed `[REDACTED PRIVATE KEY]` for VAPID — live uses env value (226-char key verified). No secrets hardcoded in webroot code.)
- [x] Dependency audit: PHP extensions + any composer deps; keep PHP 8.1 patches current on cPanel. (Audited 2026-08-16 via live probe: PHP 8.1.34 (current 8.1 patch line), 71 extensions loaded, required stack all present — pdo_sqlite, curl, openssl, mbstring, json, fileinfo, zip, gd — none missing.)
- [ ] Pen-test / OWASP Top 10 pass before launch (at minimum: IDOR checks on app-crud/app-* actions — the app-* surface is huge; spot-check every app-* action for authorization).
- [x] Add `security.txt` + `/.well-known/security.txt`. (Verified live 2026-08-16: both serve 200 — `.well-known/security.txt` (RFC 9116, content-type text/plain, Expires 2027-08) + root `security.txt` fallback added. Security headers re-audited live: HSTS max-age=31536000, X-Content-Type-Options nosniff, X-Frame-Options SAMEORIGIN, Referrer-Policy strict-origin-when-cross-origin, Permissions-Policy camera/mic/geo=(), full CSP (object-src 'none', base-uri 'self', frame-ancestors 'none', upgrade-insecure-requests) — strong posture, no changes needed.)

---

## 2. Payments — the #1 launch blocker

### 2.1 Merchant onboarding (KYC) — start NOW, it takes weeks
- [ ] SSLCommerz merchant account (covers Visa/MC/AmEx cards + bKash + Nagad + other wallets via one integration). Docs: sandbox.sslcommerz.com. Requires: trade license, BIN, bank statement, NID of owner, website URL, business email, IPN URL.
- [ ] Decide direct bKash Merchant / Nagad Merchant APIs vs. SSLCommerz aggregation. Recommendation: launch with SSLCommerz only (one KYC, one integration, one settlement), add direct bKash/Nagad later.
- [ ] Ask SSLCommerz for the LIVE store_id/store_pass when KYC completes (current code has sandbox `krtakerTEST`; gateway config sits in DB/app-gateways — plan the switch).
- [ ] Set up the IPN/notify URL + webhook secret verification (current checkout URL is sandbox v4 — production URL: https://securepay.sslcommerz.com/gwprocess/v4/api.php).

### 2.2 Integration completion
- [ ] Wire `app-premium-billing` to the real gateway: create session → redirect → IPN → verify → mark subscription active.
- [ ] Idempotency: prevent double-activation on IPN retries (transaction_id unique).
- [ ] Refund flow (SSLCommerz refund API) + subscriber-facing refund policy.
- [ ] Reconciliation job already exists (app-payment-recon) — verify it matches gateway settlements daily.
- [ ] Invoice generation on successful payment (Mushak-compliant, see 0.2).
- [ ] Payment failure UX: retry page, "payment pending" state, email the owner.
- [ ] Test matrix on sandbox: success, failure, IPN retry, amount mismatch, currency, partial refund, bKash/Nagad wallet via SSLCommerz.

### 2.3 Go-live switch
- [ ] Flip `sandbox => false` / live store creds (config in DB — make the flip a one-command script).
- [ ] ৳1 live transaction test → verify IPN → verify settlement appears in gateway dashboard.
- [ ] Monitor first 48h: payment success rate, IPN latency, reconciliation gaps.

---

## 3. Platform readiness — close the known technical gaps

### 3.1 Analytics & SEO
- [x] Sitemap.xml exists — submit to Google Search Console (verify property: DNS TXT or HTML tag).
- [x] GA4: real Measurement ID `G-C68G5Q03ZT` wired into ALL web/*.html + API blog template (v3.71). TODO: configure events (signup, payment_completed, trial_started) in GA4 console.
- [x] Search Console: submit sitemap, monitor indexing, fix the known blog slug 404s (`/blog/<slug>` route needs .htaccess rewrite — currently dormant) — decide: enable dynamic blog or keep static blog-*.html (recommend static for launch; fix 404s to avoid SEO bleed). (Verified 2026-08-15: rewrite `^blog/([a-z0-9-]+)/?$ → api/index.php` is LIVE and working — all 6 real slugs (`eviction`, `holding-tax`, `lease-registration`, `nrb-buying`, `nrb-remittance`, `tds-commercial`) return 200 with rendered article pages (proper `<title>`, `<h1>`, canonical, og:title — verified via curl); sitemap.xml lists all 6 dynamic blog URLs; nonexistent slugs (`/blog/tax`, `/blog/rent`) correctly 404. Dynamic renderer preferred over static — no action needed.)
- [x] IndexNow: key generated live + stored in platform_meta (`cms-ping-sitemap` admin action; live key `98d9653cfce08cc2928a42fe97b41546` — the old rig-mirror key `bb63…` was never in the LIVE DB, so the key file 404'd); `https://krtaker.com/<key>.txt` serves 200; 22 URLs submitted to api.indexnow.org → HTTP 200 (the old /ping 410s are gone).

### 3.2 Notifications & messaging
- [ ] SMS OTP provider (BD): SSLCommerz SMS / DopeSMS / SMS4BD / Revesoft — needs the same KYC docs; wire into register/login OTP; keep email OTP as fallback. [! blocked on provider choice + creds]
- [ ] Web push (v19) — verify real-browser registration on desktop + Android Chrome (blocked in all sandboxes; user must test in a normal browser).
- [x] Transactional email: switched off mail.inceptia.io (was the careers box) to krtaker.com's own Exim — `noreply@krtaker.com` mailbox on mail.krtaker.com:587, DKIM (d=krtaker.com; s=default, cryptographically verified), SPF (`+a +mx +ip4:37.27.134.21`), DMARC `p=none` present (tightening to quarantine blocked — reseller cPanel DNS API is read-only; needs host DNS panel). Live-verified via OTP send + IMAP read-back.
- [x] Rent-due push + digest cron already live via cPanel SERVICE_KEY cron — verify timing + delivery after any DNS/DKIM changes. (Verified 2026-08-15/16: `krtaker-rent-reminder-scheduler` Hermes cron fires 03:00 UTC daily → `app-reminder-run` (service-key gated) → tiered escalation day 1→7→15; last run OK. Live dry-run: 8 unpaid invoices (৳438,000) correctly tiered — 2 tier-0, 6 tier-3 (already reminded at current tier → silent, correct); `app-mail-worker` queue drains every 15 min (last: sent=0 failed=0 left=0). Collections digest cron 00:30 + statement email 05:00 also green. Email delivery unchanged after the mail.inceptia.io → krtaker.com Exim switch (DKIM/SPF verified §3.2 above).)

### 3.3 AI console (KR AI)
- [ ] Add real DeepSeek (or OpenAI) API key — currently placeholder. [! blocked on key]
- [x] Rate limits + per-subscriber quotas + cost cap (fail-closed if key exhausted) before exposing to users. (V2.40.5 + V2.44: `ai_guard` fail-closed rate/daily/cost limits with config in admin_settings; quota meter in AI console; token+cost accounting in ai_log; verified live 2026-08-16 — guard rejects with 429 + `quota` key when a limit is hit.)
- [x] Audit AI output: log prompts/responses, opt-out control for owners. (V2.40.5: every chat call logs user query, tool used, reply snippet, tokens, cost, latency to `ai_log`. V2.44 (2026-08-16): **owner/manager-level AI privacy opt-out** — new `subscribers.ai_optout` column (schema gate bumped to 20260924) + `app-ai-optout` endpoint (owner/manager/superadmin only, audit-logged) + `app-ai-chat` rejects with 403 `optout:true` BEFORE any processing and **logs nothing** when off; `app-ai-meta`/`app-ai-quota` expose `optout`; AI console shows 🛡️ banner + Enable/Disable toggle for owners/managers, input locked for non-managers. **Verified live end-to-end 2026-08-16**: toggle on → quota shows optout:true, chat 403, zero `ai_log` rows for the blocked query; toggle off → restored.)

### 3.4 Infrastructure & ops
- [~] Backups: SQLite DB backup job (app-backup exists) → offsite (Google Drive rclone token expired — re-auth [!]; or add S3/Backblaze as backup target). Test a restore drill end-to-end. (Local leg verified 2026-08-16: `krtaker-db-backup` cron 00:00 pulls prod DB+JSON via service key → `/root/krtaker-backup/auto/<ts>/` (latest 20260816_000051: krtaker.db 3.9MB integrity=ok, 150 tables, 10 subscribers + full data; export JSON ok). **Restore drill end-to-end PASSED**: restored snapshot into scratch DB → integrity ok, key tables (properties 8 / units 19 / tenants 17 / invoices 24 / payments 18 / leases 14 / support 6 / plan_catalog 3) all readable. New `krtaker_restore_drill.py` (monthly cron, silent watchdog) wired to restore from LOCAL snapshots — previously the cron pointed at a nonexistent `.sh` and depended on dead Drive. **OFFSITE LEG STILL BLOCKED**: gdrive rclone token `invalid_grant` (needs interactive `rclone config reconnect gdrive:` — headless can't OAuth), and the box's AWS IAM role has no S3 perms (needs Lightsail console policy attach). Until one is fixed, backups are single-host only.)
- [x] Uptime monitoring: hit `https://krtaker.com/api/health` every 1 min (Hermes cron `krtaker-uptime-watchdog` — script `krtaker_uptime.sh`, silent when healthy, alerts after 3 consecutive failures + recovery notice; delivers to origin chat, Discord home available).
- [x] Error tracking: `app-error-log`/`app-log-error` API endpoints (JS `window.onerror` + `unhandledrejection` reporter in dashboard-v2.html via sendBeacon; PHP fatal capture via shutdown `error_get_last()`; per-IP rate limit, 24h dedup with counts, 30-day retention) + Hermes cron `krtaker-error-watchdog` (`krtaker_error_watch.py`, every 30 min, silent when clean, grouped digest when new errors).
- [x] cPanel disk quota: checked 2026-08 via UAPI Quota/get_quota_info — 182.97 MB used / 1 GB limit → **817 MB free (82% headroom)**; bandwidth 524 MB/10 GB. Comfortable for launch; re-check when uploads/media grow.
- [ ] Rate limiting at the web-server layer (mod_evasive / Cloudflare) for login endpoints. [! blocked: shared LiteSpeed host (no mod_evasive) + Cloudflare-at-apex needs registrar NS change; API-level throttling (auth_attempts, api_rate_limit_ok) already active]
- [ ] Cloudflare: already used for tunnel; put the apex site behind CF (free tier) for CDN + WAF + caching of static assets. [! blocked: domain NS = ns1/ns2.coderdrop.com — needs registrar access + a Cloudflare account; no creds on box]
- [x] SSL: AutoSSL enabled (no excluded domains — verified via `SSL/get_autossl_excluded_domains` → `[]`; forced `SSL/start_autossl_check` → status 1). Served cert = Let's Encrypt via AutoSSL, expires 2026-10-12 (66 days) — renewal is automatic, no manual expiry handling.

### 3.5 Legal pages & trust
- [ ] Review terms.html / privacy.html / contact.html for: entity name, address, refund policy, DPA-2023 disclosures, dispute/complaint channel, data-subject request procedure.
- [ ] Add a "Security" page or badge section (SSl, data localization, backups) — buyers in BD ask.
- [x] Verify the "128 subscribers · ৳74.55 Cr" claim on the homepage is either true or softened — done 2026-08: claim was fiction (real DB had 16). All 16 cleared (0 subscribers), 6 real trial accounts registered via the actual OTP flow, and the claim copy removed everywhere (web+docs+i18n EN/BN+CMS default+stored override → "Start managing your property portfolio with KRTaker — free 14-day trial, no card required."). Hero stats row fixed 2026-08: now honest — ৳0 broker fee / 4+ properties live / 6+ teams onboarded / 24/7 AI caretaker (HTML + i18n EN/BN + live CMS blocks via cms-save; commit 16b1429).

---

## 4. Onboarding, support & launch ops

### 4.1 First-run experience
- [x] Demo/onboarding tour: after signup, walk the owner through adding their first property + unit + tenant (the seed data helps). (SetupView wizard `/setup` — auto-redirect on first login, 7 steps: welcome → profile → property+unit → tenant → notifications → security → done; skip supported.)
- [x] Empty-state design: dashboard with 0 properties should feel intentional (there are already seeded demo properties — decide whether new subscribers start clean or with demo data they can delete). (V2.39.8 + V2.42.1: dashboard empty state w/ 4-step checklist + CTAs; list views (properties/units/tenants/invoices) get bilingual empty states with contextual CTAs; new subscribers start clean — registration creates no demo data.)
- [x] Import wizard: owners often have existing tenants/ledgers — CSV import for units/tenants/dues (build if not present). (ImportWizard wired into Units (units), Tenants (tenants), Invoices (dues) — V2.39.7 + V2.40.7; empty-state CTAs deep-link `?import=1`.)
- [x] Welcome email sequence: welcome → set up property → invite tenant (app already has tenant-facing portal) → first rent reminder. (V2.42.0: welcome fires at OTP verify; setup nudge + tenant nudge daily cron `krtaker-setup-nudge` 08:00, both idempotent; rent reminders via `krtaker-rent-reminder-scheduler` 03:00.)

### 4.2 Support
- [x] Help center: extend faq.html + a docs page (tools.html exists); link from dashboard. (V2.43: wiki + docs.html FAQ extended with support/SLA/refund sections; terms.html §3 refund workflow; 📖 Help center button in Support toolbar → docs.html; dashboard already links to 📚 Wiki.)
- [x] Support channels: contact form → inbox, WhatsApp Business (+880****0068 — wired in v3.72), in-app ticket (app-ticket-thread exists — verify it's user-visible). (V2.43: in-app ticket creation verified end-to-end live — compose → SUP-008 created with thread + drawer. Pre-existing bugs fixed: `support` module was missing from EVERY plan matrix (access denied on all plans) → unconditional boot backfill; submitTicket TDZ shadowing + template v-for `t` shadowing (crashed on non-empty list).)
- [x] Define SLAs: response times, severity levels, escalation to Kabir. (V2.43: SLA hours mirror maintenance — Urgent 4/24, High 24/72, Medium 72/168, Low 120/240; live 🟢/🟠/🔴 chips in grid cards, list SLA column, drawer; wiki article "What are the support SLAs?" + docs.html.)
- [x] Refund/cancellation workflow documented for support staff. (V2.43: terms.html §3 full 14-day refund / cancel-anytime workflow; wiki article "How do refunds and cancellations work?"; docs.html FAQ.)

### 4.3 Launch sequence (recommended)
1. **Soft launch (2–4 weeks):** invite 10–20 real owners/buildings (network, LinkedIn, the 128 list if real) at free/discounted annual rate. Collect feedback; fix crash bugs; validate payment UX with real ৳ transactions.
2. **Hardening sprint:** fix everything found in soft launch; run full regression (run_all + test_superadmin + payment test matrix).
3. **Public launch:** announce on LinkedIn/Facebook, launch offer (first 50 subscribers discounted), Google Business Profile for "KRTaker" if a physical office exists.
4. **Post-launch 30 days:** daily payment/error/uptime review; weekly KPI readout (signups, activation rate, first-rent-collection rate, churn).

### 4.4 KPIs to track from day one
- [x] Signup → activation (first property added) conversion. (V2.44 2026-08-16: `app-kpi-daily` endpoint (X-Service-Key gated) reports signups today/7d, active accounts, setup-wizard completions today, collections today/7d (payments count + ৳ sum), portfolio size, AI calls today, open support tickets, errors today. Hermes cron `krtaker-kpi-daily` (`ad72cc903f0e`, 05:45 UTC daily, no_agent) delivers the digest to the ops chat — first delivery 2026-08-16. Live-verified: 0 signups today, 2 in 7d, 8 active, ৳548,499 collected in 7d, 6 open tickets, 0 errors.)
- [x] First payment success rate — visible via collections/payments counts in the daily digest; full funnel metrics (signup → activate → first pay) are derivable from the same endpoint once real traffic starts.
- [x] Monthly recurring revenue + churn — payment-recon + premium-sub-list already track; the digest adds the daily pulse.
- [x] Support ticket volume + first-response time — ticket count in digest; SLA chips in-app track response times per ticket (V2.43).
- [x] App crashes / JS errors / API 5xx per day — `errors_today` in digest (app_error_log); the error-watchdog cron already alerts on new groups every 30 min.
- [ ] Push/email deliverability (bounce + unsubscribe)

---

## 5. Pre-launch sign-off checklist (gate)

| Area | Must be true before flip |
|---|---|
| Legal | Entity + trade license + TIN/BIN + bank account |
| Payments | Live gateway keys, IPN verified, refund tested, ৳1 live test passed |
| Data | Privacy policy updated (DPA-2023), consent captured, GDPR export works |
| Security | OWASP spot-check, throttling on, secrets not in code, backups verified |
| Ops | Uptime monitor on, error tracking on, Discord alerts on, restore drill done |
| Content | GA4 real ID, Search Console verified, terms/privacy reviewed, claims honest |
| Code | Full regression green (run_all 2961 + superadmin 511), version bumped + deployed |

---

## 6. Immediate next actions (this week)

1. [ ] Decide the merchant legal entity (BITSCOL vs new KRTaker entity) → start SSLCommerz KYC (longest lead time).
2. [x] GA4 Measurement ID (`G-C68G5Q03ZT`) swapped in — DONE v3.71. [ ] Search Console verification → submit sitemap.
3. [ ] Provide DeepSeek/OpenAI API key for the AI console.
4. [ ] Pick an SMS provider (SSLCommerz SMS / DopeSMS) + provide creds → I'll wire OTP.
5. [ ] Re-auth Google Drive rclone (paste the auth URL output) → backups go offsite.
6. [ ] Confirm whether "128 subscribers" claim is real or marketing → I'll adjust copy.
7. [ ] Run one live payment through the sandbox → verify the full flow end-to-end before KYC completes.
