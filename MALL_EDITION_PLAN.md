# Mall / Commercial Property Management Edition — Plan

Branch: `feat/mall-management`
Source: `superadmin-panel` @ `5818bce` (V2.40.0)

## Purpose

Re-purpose KRTaker (property management + AI caretaker) into an **on-premise / online
dedicated software** for:

1. **Shopping Mall / Commercial Complex** ← FIRST (spec attached: `mall_management_software_spec-1.docx`)
2. Apartment Buildings
3. Residential Buildings

The spec is a Bangla requirement document from a **Mall Owners' Committee (মার্কেট মালিক সমিতি)**.
Rent collection is **NOT** in scope (shop owners collect their own rent); the software handles
**service charge, electricity & water collection (sub-meter), expenses, assets/AMC, complaints,
ledger/reports, users & notifications** for the committee.

## Spec highlights (translated summary)

| Module | Requirement |
|---|---|
| 3.1 Shop master | shop no, floor, size sqft, owner name/mobile/NID, status (Active/Closed/Vacant), opening balance |
| 3.2 Service charge | monthly rate per shop (sqft or flat), **auto monthly invoice**, collections (cash/bank/bKash/Nagad + ref), dues + overdue, late-fee auto calc, receipt PDF |
| 3.3 Elec & water | sub-meter reading entry, unit rate → auto bill, **DESCO/WASA reconciliation**, dues tracking, **custodial fund = separate account head** |
| 3.4 Expenses | categories (lift, escalator, common elec, AC servicing, generator/fuel, cleaning, security), vendor info + payment tracking, staff salary, voucher image/PDF upload |
| 3.5 Asset & AMC | lift/escalator/generator/fire extinguisher contracts + warranty, auto reminder before expiry, linked to expenses |
| 3.6 Complaints | shop owner reports issue (lift/AC/light), Open → In Progress → Resolved |
| 3.7 Accounting | central ledger (collection vs expense), monthly income-vs-expense, cash/bank balance dashboard, per-shop ledger, annual budget vs actual, bank statement import/recon, Excel/PDF export, **audit trail** |
| 3.8 Users | Admin (committee/office), Accountant, Collection staff, **shop owner view-only login** (own bills/dues/history) |
| 3.9 Notifications | SMS/WhatsApp/email bill reminders, auto alert on high dues, notice board broadcast |
| 3.10 Dashboard | collection vs dues graph, current-month expense pie, top defaulters |
| 3.11 Governance (opt) | meeting/AGM resolutions stored as documents |

## Phasing (per spec §4)

- **Phase 1 (must-have):** shop master, service-charge collection, elec/water collection, basic
  expenses, central ledger/reports
- **Phase 2:** complaint/ticket module, asset & AMC tracking, custodial-vs-own separate ledger,
  audit trail, user roles
- **Phase 3:** shop-owner view-only login, SMS/notifications, notice board, budget planning,
  bank reconciliation, meeting logs

## Implementation mapping onto KRTaker (reuse)

| New Mall module | KRTaker analog to reuse |
|---|---|
| Shops master | `units` table + UnitsView (add floor/sqft/owner/NID/status/opening_balance) |
| Service-charge billing | `invoices` / generate_invoice (add monthly auto-gen, late-fee) |
| Collections | `collections` / receipt (add bKash/Nagad methods) |
| Sub-meter elec/water | `meter_readings` / `utility_bills` (add unit-rate auto-bill + DESCO/WASA recon) |
| Expenses + vendors | `expenses`/`vendors` + vendor payouts (add voucher upload) |
| Asset & AMC | new `assets`/`amc` tables + reminders |
| Complaints | `maintenance` tickets (status flow Open→In Progress→Resolved) |
| Ledger/reports | `finance` hub + analytics (add per-shop ledger, budget vs actual, audit trail) |
| Users/roles | `app_users` + role_modules (add shop-owner role) |
| Notifications | `notices` + wa_send / notice_email |
| Dashboard | `dashboard` + analytics |

## Schema (new tables)

```
shops (id, mall_id?, no, floor, sqft, owner_name, owner_mobile, owner_nid, status, opening_balance)
shop_rates (shop_id, service_charge_type [sqft|flat], rate, elec_unit_rate, water_unit_rate)
shop_bills (id, shop_id, month, kind [service|elec|water], amount, fine, due_date, status)
shop_payments (id, shop_id, bill_id, amount, method [cash|bank|bkash|nagad], ref, date, receipt_no)
meter_readings (id, shop_id, type [elec|water], reading, prev_reading, units, month, date)
expenses (id, category, amount, vendor, note, voucher_path, date)
assets (id, name, type, install_date, warranty_until, contract_until, vendor, cost)
complaints (id, shop_id, subject, desc, status, opened_at, resolved_at)
```

## Phase-1 build order (this branch)

1. ✅ Branch created
2. [ ] `shops` master CRUD (API actions + Vue view)
3. [ ] service-charge rates + auto monthly bill generation
4. [ ] collection entry (cash/bank/bKash/Nagad) + receipt
5. [ ] meter readings + elec/water auto-bill (custodial ledger head)
6. [ ] expenses + vendors + voucher upload
7. [ ] central ledger + per-shop ledger + dashboard KPIs
8. [ ] Phase 2: complaints, assets/AMC, audit trail, roles
9. [ ] Phase 3: owner login, notifications, notice board, budget, bank recon

Deploy: same cPanel FTP rig; app served under the existing app-v3 SPA with a new
route group `/mall/*` or a feature flag for the mall edition.
