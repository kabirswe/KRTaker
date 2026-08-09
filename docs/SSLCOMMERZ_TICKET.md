# SSLCommerz support ticket — store credential / provisioning issue

## Context (evidence gathered 2026-08-09)
- Merchant store: `testkrtak46ew` (Sandbox)
- Store ID: `krtak6a77b97d21328`
- Endpoints tested (all via the official v4 API):
  - `sandbox.sslcommerz.com/v4/api.php` → "Store Credential Error Or Store is De-active"
  - `sandbox-gw.sslcommerz.com/gwprocess/v4/api.php` → "Store Password credential mismatch."
- Tested credentials: the email-registered password, the API key
  (`krtak6a77b97d21328@ssl`), a freshly regenerated key, and the no-suffix
  variants — ALL rejected.
- **Control experiments prove the store EXISTS on sandbox-gw but its password
  matches nothing:**
  - Bogus Store ID on `sandbox.sslcommerz.com` → SAME "Store Credential Error
    Or Store is De-active" error (that host is a testbox with no merchant stores)
  - Bogus Store ID on `sandbox-gw.sslcommerz.com` → DIFFERENT error: "Invalid
    Store ID or Store is inactive" (proves the host distinguishes unknown IDs)
  - `testbox` / `qwerty` (public sandbox creds) on `sandbox-gw` → SUCCESS with a
    real GatewayPageURL (proves our integration code is correct)
- Legacy `process.php` endpoint is dead (v3 → v4 migration notice).

## Conclusion
The store `testkrtak46ew` IS provisioned on sandbox-gw (control-test-verified)
but no password variant authenticates → a platform-side provisioning bug
(password never set / mismatch between the merchant panel and gateway DB).

## Ticket text (send via SSLCommerz merchant panel or support@sslcommerz.com)
Subject: Sandbox store password rejected — credential mismatch on sandbox-gw

Body:
```
Hi SSLCommerz Support,

Our sandbox merchant store is rejecting every credential we try, and control
tests indicate a platform-side provisioning issue rather than a typo on our
side. Details:

- Store name: testkrtak46ew
- Store ID: krtak6a77b97d21328
- Integration endpoint: sandbox-gw.sslcommerz.com/gwprocess/v4/api.php

Symptoms:
- Using the password from our registration email → "Store Password credential
  mismatch."
- Using the API key shown in the merchant panel (krtak6a77b97d21328@ssl) →
  same mismatch.
- After regenerating the API key in the panel → still mismatch.
- Control: a bogus Store ID returns "Invalid Store ID or Store is inactive"
  (a different error), while our store returns "Store Password credential
  mismatch." — so our store is recognized but the password matches nothing.

Can you please verify/reset the sandbox store password on the gateway side
(or re-provision the store) so we can complete our integration testing?

Thanks!
KRTaker team (krtaker.com)
```

## After they respond
- Re-test: `POST sandbox-gw.sslcommerz.com/gwprocess/v4/api.php` with
  `store_id=krtak6a77b97d21328` + the fresh password → expect `status=SUCCESS`
  + `GatewayPageURL`.
- Then flip the live gateway config (gw_config 5-min switch) and run the ৳100
  live smoke with test card 4111111111111111.
