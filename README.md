# Mall Manager

**Dedicated on-premise / online software for Shopping Mall, Commercial Complex,
Apartment Building and Residential Building management** — a standalone product,
fully separate from KRTaker (own repo, own branding, own database).

## Modules (per `docs/MALL_EDITION_PLAN.md` — spec `mall_management_software_spec-1.docx`)

### Phase 1 — shipped ✅
- **Shops master** — shop no, floor, sqft, owner name/mobile/NID, status
  (Active/Closed/Vacant), opening balance, service rate (flat or per-sqft)
- **Service-charge billing** — one-click monthly bill generation (idempotent),
  dues tracking, overdue detection, **late-fee auto-calculation** (% configurable)
- **Collections** — cash / bank / bKash / Nagad with reference; auto money receipt
  (`RCT-YYYYMM-NNNN`) with **print / PDF**
- **Electricity & water (sub-meter)** — reading entry → units → auto bill
  (unit rates configurable); **custodial fund** tracked separately from
  service charges (forwarded to DESCO/WASA)
- **Ledger** — billed vs collected by kind, per-shop paid/billed, expenses
- **Committee dashboard** — collected/outstanding KPIs, top defaulters,
  expense categories, shop counts
- **Mall settings** — mall name, elec/water unit rates, due day, late-fee %

### Phase 2 — planned
Complaints/tickets (Open → In Progress → Resolved), assets & AMC tracking
(lifts/escalators/generators/fire extinguishers + expiry reminders), audit trail,
collection-staff role

### Phase 3 — planned
Shop-owner view-only login (own bills/dues/history), SMS/WhatsApp/email reminders,
notice board broadcast, budget planning, bank reconciliation, meeting/AGM logs

## Stack
- Backend: PHP + SQLite single-file API (`api/index.php`) — no framework, runs on
  any shared hosting / cPanel / on-premise box (DB path configurable via env file)
- Frontend: Vue 3 + Vite SPA (`app-v3/`) — mobile-responsive, role-based access
- Auth: PoW-protected login, Bearer tokens, RBAC (admin/accountant/collection staff)

## Quick start (dev)
```bash
# backend (creates mall_manager.db on first request)
cd api && php -S 127.0.0.1:8767 index.php
# frontend
cd app-v3 && npm install && npx vite --config vite.preview.config.js
# open http://localhost:5174 — login owner@krtaker.com / owner123 (demo seed)
```

## Deploy (on-premise / dedicated)
1. Upload `api/index.php` + `app-v3/dist` to the server
2. Create an env file next to the API (`krtaker.env.php`) to point
   `DB_PATH` at a dedicated database file — the app never shares KRTaker's DB
3. Configure mall name + rates in ⚙️ Mall settings
