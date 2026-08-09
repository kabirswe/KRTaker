# PTR / EHLO ticket for coderdrop — CORRECTED (2026-08-09)

## Why this version differs from the old draft
The earlier draft said "Our cPanel hostname has been set to match." That is
FALSE: krtaker.com is on a SHARED box (37.27.134.21 also hosts bhubanghar.bd,
bssf.com.bd, dhakai.com.bd, mth.com.bd, odekha.bd, adcromjan.com, ...). The
server hostname is `server.coderdrop.com` and must NOT be changed globally.
Sending the old draft as-is would change PTR to mail.krtaker.com while EHLO
stays server.coderdrop.com → PTR/EHLO mismatch → breaks FCrDNS → deliverability
gets WORSE. Golden rule: PTR and EHLO must change TOGETHER.

## Current state (verified 2026-08-09)
- PTR  37.27.134.21  → server.coderdrop.com
- EHLO (Exim)        → server.coderdrop.com
- mail.krtaker.com   → CNAME krtaker.com → 37.27.134.21  (forward-confirm ready)
- FCrDNS currently PASSES; SPF -all + DKIM + DMARC double-aligned; mail lands INBOX.

## Goal
PTR + per-domain EHLO = mail.krtaker.com, so outgoing mail from krtaker.com
presents mail.krtaker.com end-to-end (branded, matches SPF/DKIM/DMARC).

## Ticket text (send to coderdrop support)
Subject: Reverse DNS + per-domain Exim helo for krtaker.com

Body:
```
Hi,

Could you please make two changes on our shared server (37.27.134.21, cPanel
account krtaker):

1) Set the reverse DNS (PTR) for 37.27.134.21 to:
   mail.krtaker.com

2) Configure the per-domain Exim HELO (helo_data) override for the krtaker.com
   domain so outgoing mail advertises:
   mail.krtaker.com

Please do BOTH changes together. mail.krtaker.com already resolves forward to
37.27.134.21 (CNAME → krtaker.com), so forward-confirmed reverse DNS will be
consistent.

We understand the server hostname itself must remain server.coderdrop.com
(shared box) — we only need the per-domain helo override, not a global change.

Thanks!
```

## Why per-domain helo_data (not hostname)
- Shared box: changing the global hostname would affect every customer.
- cPanel supports per-domain `helo_data` in Exim Configuration Manager — a
  server-level setting that only affects the named domain.
- We do NOT have WHM root access (port 2087 → 401), so only coderdrop staff
  can apply it. That's why this is a support ticket, not a self-service fix.

## Fallback (do nothing)
Current state passes all checks and email delivers fine. PTR branding is a
trust/nice-to-have, not a hard failure. If coderdrop can't/won't do the helo
override, we accept current state rather than risk a mismatch.

## After they confirm (verification)
```bash
dig -x 37.27.134.21                      # expect mail.krtaker.com.
openssl s_client -starttls smtp -connect 37.27.134.21:587 -quiet <<< $'EHLO probe\r\nQUIT\r\n' | head -2
                                         # expect 250-mail.krtaker.com
# then send a real test mail and check headers (Return-Path + DKIM + SPF + DMARC)
```
