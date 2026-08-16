# Sandbox Payment Test Matrix (GO-LIVE §2.2)

Status: **SIMULATED flow live-verified 2026-08-16** (sandbox creds not yet configured).
The simulated checkout is the current production path (gateway_ready() returns false
until real sandbox store creds are set). The harness at
`/root/krtaker-deploy/sandbox_payment_matrix.py` runs the matrix below against the API;
every row is executable. When SSLCommerz sandbox credentials are added to
`platform_meta.gw_config`, the SAME harness exercises the real sandbox path
(`--real-sandbox`), and this document's "real sandbox" column applies.

## Gateways & config
- `GATEWAYS()` in `api/index.php` — code defaults (bKash / SSLCommerz `krtakerTEST` / Nagad), overridable per-gateway via `platform_meta.gw_config` JSON.
- `gateway_ready(code)` = false while store_id is `''`/`REPLACE_ME`/`krtakerTEST` or store_pass is `REPLACE_ME` → init falls through to **simulated** checkout.
- Sandbox endpoints: SSLCommerz `https://sandbox.sslcommerz.com/gwprocess/v4/api.php` (init) + validator `https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php`.
- Live endpoints (after flip): `https://securepay.sslcommerz.com/gwprocess/v4/api.php` + `https://validator.sslcommerz.com/validationserverAPI.php`.
- Flip: `python3 /root/krtaker-deploy/flip_gateway_live.py` (dry-run by default; `--live --store-id ... --store-pass ...` to apply, `--sandbox` to revert).

## Matrix (each row = a test case)

| # | Case | How to trigger | Expected result | Simulated status |
|---|------|----------------|-----------------|------------------|
| 1 | **Init success** | `app-payment-init` on an unpaid invoice (method=bkash/sslcommerz/nagad) | 200 `ok:true`, `session_id=GW-*`, status pending→redirecting, gateway/sandbox/amount echoed | ✅ PASS |
| 2 | **Init rejections** | init on paid invoice / unknown method / without payments module / rate-limit burst (7 init/min) | 400 (already paid / unsupported gateway) or 403 (module) or 429 (throttle, Retry-After header) | ✅ PASS |
| 3 | **Confirm success (simulated)** | `app-payment-confirm {session_id}` on a redirecting session | 200 ok, payment PAY-### created, receipt RCP-###, session → paid, invoice due → 0 | ✅ PASS |
| 4 | **IPN retry / idempotent replay** | `app-payment-confirm` again with the SAME session after paid | 200 `ok:true, idempotent:true`, same PAY id, **no second payment, no double credit** | ✅ PASS |
| 5 | **Double-activation race (premium)** | `app-premium-billing action=pay` twice concurrently on the same caretaker invoice | First → PAY + status Paid; second → 400 "Invoice already paid." (atomic `UPDATE ... WHERE status='Unpaid'` barrier, V2.45) | ✅ PASS |
| 6 | **Amount mismatch** | Real sandbox: pay a different amount at the gateway then confirm with the gateway val_id | 402 "Amount mismatch with gateway", session NOT marked paid, audit entry | ✅ (code path; needs real sandbox val_id) |
| 7 | **Verification failure** | Confirm with a bogus gateway_ref on a redirecting session that has gw_ref | 402 "Gateway could not verify", session → failed, payer notified, invoice unpaid | ✅ (code path; needs real sandbox) |
| 8 | **Cancel** | `app-payment-cancel {session_id}` on pending | 200, session → cancelled, audit, payment-failed email queued; confirm-after-cancel → 400 "Session already cancelled" | ✅ PASS |
| 9 | **Status polling** | `app-payment-status {session_id}` pending / redirecting / paid / cancelled / failed | 200 with status + paid + due + pay_url; 404 unknown; 403 not-your-invoice | ✅ PASS |
| 10 | **Refund** | `app-refund` on a paid payment (recon module, own workspace) | 200, payment → Refunded, double-refund → 400, outstanding recalculated | ✅ PASS |
| 11 | **Failure UX** | Confirm with failing path / cancelled session → check email `payment_failed` queued + push to owner | `notify_payment_failed` queues email (respects mail_switch docs), push to property owner; UI retry state | ✅ PASS |
| 12 | **Currency** | All amounts BDT; gateway echoes BDT; validator amount compared as int | No currency drift; BDT only (BDT hardcoded) | ✅ PASS (static) |
| 13 | **bKash/Nagad via SSLCommerz** | Real sandbox: init sslcommerz, choose bKash/Nagad wallet on the merchant page | Session created via SSLCommerz aggregate; validator returns wallet-paid; confirm credits invoice | ⏳ blocked on sandbox creds |
| 14 | **Partial refund** | Real sandbox: refund API partial amount | Payment status Refunded (partial not modeled — refunds are full; note in docs) | ⏳ blocked |
| 15 | **KYC gate (cards)** | `app-payment-init` method=sslcommerz on a lease whose tenant KYC ≠ verified | 400 code `KYC_REQUIRED` with kyc_status | ✅ PASS |

## How to run the harness (simulated, no creds needed)
```bash
cd /root/krtaker-deploy
python3 sandbox_payment_matrix.py            # creates a probe invoice + owner token, runs rows 1–15 API checks
```
It cleans up all probe data (invoice, payments, receipts, sessions, tokens) afterwards.

## How to run against real sandbox (once creds set)
```bash
python3 flip_gateway_live.py --sandbox-creds --store-id <sandbox_id> --store-pass <sandbox_pass>   # keep sandbox=true
python3 sandbox_payment_matrix.py --real-sandbox --method sslcommerz
# then in a browser complete the SSLCommerz sandbox card flow (test cards from the SSLCommerz dashboard)
# and confirm with the returned val_id; rows 6/7/13/14 cover the gateway-specific paths.
```

## Known notes
- Refunds are full-amount only (no partial-refund model yet) — partial refund is tracked as a gateway-level test for the launch hardening sprint.
- Invoice payments are idempotent via the atomic `UPDATE gateway_tx SET status='paid' WHERE id=? AND status IN ('pending','redirecting')` barrier (rowCount check) + replay detection on `status='paid'`.
- Premium (caretaker) invoice payments gained the same atomic barrier in V2.45.
