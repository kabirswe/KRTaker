# KRTaker API Contract — Frontend Modernization (Phase 0)

Frozen spec for the Vue 3 frontend. Source of truth: `api/src/064_router.php` + auth libs.
The new SPA MUST call exactly what dashboard-v2.html calls today. No backend changes.

## Transport
- Base: `../api/` relative to the page (deployed at `/api/` on live)
- Method: POST with `Content-Type: application/json` (all actions, even reads)
- Auth: `Authorization: Bearer <token>` header (token stored in localStorage `krtaker_dash_token`)
- Errors: HTTP status + `{ok:false, error:"..."}` JSON body
- Retry: transient failures (503/SQLite lock/empty body) → retry 3x with backoff, then graceful error state

## Auth flow
1. **POST /api/app-login** — body: `{email, password, hp, ft, pow, 2fa_code?}`
   - Bot-guard required on this endpoint (see below). `hp`="" (honeypot), `ft`=page-load ms timestamp (>2s before submit), `pow`=PoW nonce.
   - superadmin with 2FA: first POST (no `2fa_code`) → `401 {ok:false, need_2fa:true}`; second POST carries `2fa_code`.
   - Success: `{ok:true, token, user:{id,name,email,role,kind,modules,role_modules,limits,avatar,photo,is_staff,org,plan,...}}`
2. **GET/POST /api/app-bootstrap** — `{ok:true, user, catalog, subscription, package, collections:{...79 collections}}`
   - `collections` = all data tables (properties, units, tenants, leases, invoices, receipts, payments, tickets, partners, staff, support, cases, gateway_tx, ... 79 total)
   - Staff roles (superadmin/owner/manager/svc_mgr/legal/crm/accountant/hr) get FULL org data; tenant gets own-scoped; partner gets jobs/partners/payouts.
3. **POST /api/app-me** — current user refresh. **POST /api/app-logout** — invalidate token.
4. **Token TTL**: 7 days (`TOKEN_TTL`); superadmin 12h. Tokens hashed at rest (sha256).

## Bot guard (login/register/contact/newsletter/forgot/reset)
- Fields: `hp` (honeypot — must be empty), `ft` (page-load ms, must be ≥2s old), `pow` (SHA-256 PoW nonce)
- PoW: `sha256(window:nonce)` must have ≥N leading zero bits (difficulty 8–24, default 12 from `bot_pow_bits`); window = floor(epoch/300), accept ±1 window skew; nonce hex ≤16 chars
- Optional layers (only when keys configured): Turnstile (`cf-turnstile-response`), reCAPTCHA v3 (`g-recaptcha-response`, score ≥0.5)
- Fail → `422 {ok:false, error:"Human verification failed..."}`
- Rate limit: `auth_blocked` after too many failures → 429 with Retry-After; superadmin lockout via `sec_lockout_minutes`

## Generic CRUD — POST /api/app-crud
Body: `{action: "create"|"update"|"delete", collection, data:{...}, id?}`
- Allowed collections (fields exactly): 
  - `properties`: name,type,jur,holding,sqft,value,status,sub_email,address,photo,description,featured,created_at,lat,lng
  - `units`: p,name,floor,sqft,status,rent,sub_email,beds,baths,furnished
  - `tenants`: name,phone,email,nid,nrb,kind,sub_email
  - `leases`: u,t,start,end,rent,adv,res,reg_office,reg_deed,status
  - `invoices`: l,m,gross,tds,net,status (net auto-derived if absent; id via `INV-YYYY-NNNN`)
  - `tickets`: u,desc,reported,liab,status,con,cost
  - `partners`: name,trade,rating,jobs,status,sub_email
  - `staff`: name,role,dept,status
  - `support`: from_t,subject,status,prio,age
  - `cases`: title,ref_lease,type,status,opened,notes
  - `amenities`: prop,unit,name,icon,category,status
- ID prefixes: P-, U-, T-, L-, MT-, SP-, ST-, SUP-, CASE-, AM-
- RBAC (`can_crud`): superadmin=all; owner=props/units/tenants/leases/invoices/tickets/partners/amenities; manager=props/units/tenants/leases/invoices/tickets/amenities; accountant=invoices/tickets; svc_mgr=tickets/partners; crm=tickets/support; hr=staff; legal=leases/cases; tenant=tickets(own unit only, create only); partner=tickets(own jobs, update only)
- Subscribers get plan-limit checks + `sub_email` ownership stamping; tenant/partner row-scope guards → 403

## Module endpoints (all POST, require auth, JSON body)
171 total actions in router. Key families:
- Finance: app-collections-summary, app-collections-run, app-payment-recon, app-refund, app-gateway-cleanup, app-payment, app-gateways, app-payment-init, app-payment-confirm, app-payment-cancel, app-payment-ipn, app-payment-reconcile
- Templates: app-tpl-list/get/save/dup/delete/reset/render, app-email-tpl-*
- AI: app-ai-meta, app-ai-chat
- Admin: app-admin (user mgmt), app-crud, app-profile, app-settings-get/save, app-org-settings-get/save, app-rent-config-*
- Misc: app-backup, app-export, app-audit, app-portal, app-portal-agreement, app-reminder-*, app-rent-due-push, app-mail-worker, app-renewal-*, app-meter-*, app-utility-*, app-score-*, app-vetting-report, app-settlement-report, app-premium-*, app-gdpr-export
- Modules: app-moveout, app-premium-billing, app-insurance, app-maintenance, app-leads, app-statements, app-compliance, app-vendors, app-job-media, app-remit, app-onboarding, app-sla, app-legal, app-trust, app-land, app-kr-alert, app-kr-wa, app-analytics, app-nrb, app-concierge, app-smarthome, app-healthcheck, app-build, app-gate, app-firesafety, app-systems, app-staffwatch, app-samity, app-packages, app-photo, app-theme, app-tenant-me, app-notice-*, app-doc-*, app-hando-*, app-referral-*, app-subscribe, app-ticket-*, app-tenant-note/chat, app-2fa-*
- Public: health, listings, building-public, register, verify-otp, resend-otp, forgot-password, reset-password, newsletter, contact, app-setup, plans, sitemap, blog-list, cms-read, app-log-error, app-error-log
- Tenant: host-tenant, app-tenant-me

## Key payloads
- **app-bootstrap** `collections` keys (79): properties, units, tenants, leases, invoices, receipts, payments, tickets, partners, staff, support, cases, gateway_tx, ticket_thread, documents, notices, referrals, property_rent, amenities, caretaker_invoices, insurance_policies, maintenance_requests, leads, statement_payouts, compliance_items, renewal_requests, meter_readings, utility_bills, utility_tariffs, partner_invoices, vendor_payouts, remittances, onboarding_apps, vendor_ratings, sla_config, kr_alerts, wa_channels, kr_wa_msgs, board_reports, nid_verifications, legal_notices, case_events, thana_forms, land_parcels, land_visits, land_media, land_events, nrb_tax_returns, nrb_repatriations, nrb_vacancies, nrb_showings, nrb_disputes, concierge_requests, concierge_docs, holding_taxes, smart_locks, cctv_cameras, health_plans, build_projects, build_milestones, build_expenses, build_media, gate_visits, fire_assets, fire_incidents, evacuation_plans, emergency_contacts, sys_assets, building_staff, staff_attendance, samity_members, samity_bills, samity_collections, staff_payroll, sys_services, sys_fuel, resident_vehicles, gate_watchlist, _platform
- **user_payload**: id, name, email, role, kind, modules, role_modules (per-role module map), limits, avatar, photo, is_staff, org, plan, trial_end, dept, impersonator?, imp_expires?, team_member?
- **app-profile**: GET → `{ok, profile, user}`; POST accepts name/org/phone/dept/avatar, old_password+new_password
- **app-settings-get** → `{ok, settings}`; **app-settings-save** → POST `{settings:{...}}`
- **app-admin**: superadmin-only dispatch (users list/create/update/delete, 2FA, platform settings)

## RBAC modules per role (ROLE_MODULES)
superadmin: full (incl subscriptions, packages, parking, bookings, voting, forums, events, insurance)
owner: full minus platform extras; manager: full minus subscriptions; tenant: dashboard,portal,invoices,receipts,payments,maintenance,legal,trust,ai,notices,documents,analytics; partner: dashboard,maintenance,vendors,invoices,payments,ai,notices,documents,referrals; svc_mgr/legal/crm/accountant/hr: staff subsets.

## Frontend state shape (from dashboard-v2.html)
- state: {role, view, theme, lang} persisted in localStorage
- TOKEN in localStorage `krtaker_dash_token`; theme `krtaker_dash_theme`; lang `krtaker_dash_lang`
- Role-switch (tb-user): subordinate-only via ROLE_RANK {superadmin:100, owner:90, manager:80, staff:60, tenant/partner:20}
- Views: dashboard, properties, units, tenants, leases, renewals, invoices, receipts, payments, taxes, statements, remit, utilities, maintenance, vendors, onboarding, leads, compliance, legal, trust, land, nrb, concierge, smarthome, health, build, gate, firesafety, systems, staffwatch, samity, subscriptions, ai, analytics, notices, documents, templates, referrals, recon, caretaker, packages, parking, bookings, voting, forums, events, insurance

## Non-negotiable preservation
- Bot-guard PoW on login/register/contact/newsletter
- Admin 2FA prompt flow
- RBAC via role_modules (nav filtering) + can_crud (write gating)
- Token storage/expiry handling; logout clears token
- Impersonation banner (superadmin → view-as) + exit
