# SSLCommerz KYC — Application Guide (GO-LIVE §2.1 · the #1 launch blocker)

**Date:** 2026-08-17 · **Status:** Needs merchant entity decision → then submit

---

## Why this is the critical path

SSLCommerz merchant KYC is the **longest-lead-time item** in the launch checklist
(typically **1–4 weeks** after submission, sometimes longer during peak seasons).
Everything else (real payments, refunds, premium billing, sandbox→live flip) sits
behind it. **Decision needed first: which legal entity is the merchant?**

| Option | Pros | Cons |
|---|---|---|
| **BITSCOL** (existing company) | Already has trade license, TIN/BIN, bank account → fastest KYC | KRTaker bills under BITSCOL name; entity mismatch with brand (fine legally, but invoices say "BITSCOL") |
| **New KRTaker entity** | Clean brand alignment | Requires company registration first (2–4 wks) → KYC delayed by that much |

**Recommendation:** use **BITSCOL** to start KYC now (fastest), rename/rebrand the
merchant display name to "KRTaker" in the SSLCommerz panel (supported). A separate
entity can be added as a second merchant later without rework.

---

## Documents to prepare (all scanned, valid)

1. **Trade License** (current, renewed) — BITSCOL's trade license, Uttara
2. **TIN Certificate** (National Board of Revenue)
3. **BIN / VAT Registration Certificate** (if applicable for the business)
4. **Bank account details** — account name, number, bank + branch, **routing number**; a cancelled cheque leaf (front scan)
5. **NID** of the authorized signatory / owner (Belal Ahmed as CEO)
6. **Company letterhead** — for the authorization letter (some panels require a signed letter)
7. **Website URL** — krtaker.com (already live, HTTPS, GA4 present)
8. **Business email** — use a real inbox (noreply@krtaker.com is a sender; get a human inbox like info@krtaker.com or use Belal's)

> ⚠️ The merchant panel fields may also ask for: company category (Software/SaaS),
> expected monthly transaction volume, business description. Keep it simple and
> truthful: "Property-management SaaS (rent collection, owner statements)".

---

## Step-by-step

### Step 0 — Decide entity (Belal/Kabir decision)
- [ ] Pick **BITSCOL** (recommended) or new KRTaker entity
- [ ] Confirm the authorized signatory NID + bank account to register

### Step 1 — Create the merchant account
1. Go to **https://merchant.sslcommerz.com** → *Sign Up*
2. Fill company details per the decision above
3. Use a **real, monitored email** (all KYC correspondence goes there)

### Step 2 — Upload KYC documents
1. In the dashboard: **Merchant Profile → KYC / Document Upload**
2. Upload: trade license, TIN, BIN (if any), NID, cancelled cheque, authorization letter (if asked)
3. Set the **store display name** to "KRTaker" and website to `https://krtaker.com`
4. Select the store type: **Live** (not sandbox — sandbox comes free with the account)

### Step 3 — Submit & track
1. Submit → note the **application/reference number**
2. Check status daily in the panel; SSLCommerz typically emails on approval
3. Follow up after 5 working days if no response (merchant support live chat)

### Step 4 — After approval
1. Grab **Store ID + Store Password** (live) from the merchant panel
2. Give me the creds → I will:
   - Write `gw_config` via `flip_gateway_live.py --live --store-id X --store-pass Y --apply`
   - Run the sandbox matrix against the **live** store (payment + refund)
   - Run one real ৳1 test transaction
   - Update GO-LIVE §5 Payments row
3. Keep the **sandbox store** credentials handy — sandbox is created automatically; if the
   existing sandbox store (`testkrtak46ew`) password issue persists, the live account
   usually fixes it (or re-provision fresh sandbox creds from the panel)

---

## Parallel tracks (not blocked on KYC)

- **Sandbox store password bug** — support ticket drafted in `docs/SSLCOMMERZ_TICKET.md`
  (store `testkrtak46ew` provisioned but password rejected). Send via the merchant
  panel once the account is created, or follow up `support@sslcommerz.com` with the
  ticket text already written.
- **Soft launch** — can proceed WITHOUT payments (free 12-month founding offer), see
  `docs/SOFT-LAUNCH-PLAN.md`. KYC only gates the paid tier.

## Checklist summary

- [ ] Merchant entity decision (BITSCOL vs new entity) — **this week**
- [ ] Gather 7 documents (trade license, TIN, BIN, bank + cheque, NID, letterhead, business email)
- [ ] Create merchant account at merchant.sslcommerz.com
- [ ] Upload KYC docs, display name "KRTaker", site krtaker.com
- [ ] Submit + track daily; follow up at 5 working days
- [ ] On approval: send creds to AJ → flip gateway + run live payment/refund test

---

*Prepared 2026-08-17. Companion: docs/SSLCOMMERZ_TICKET.md (sandbox bug), docs/SOFT-LAUNCH-PLAN.md, docs/GO-LIVE.md §2.1.*
