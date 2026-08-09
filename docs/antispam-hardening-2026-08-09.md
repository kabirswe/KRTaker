# Anti-Spam / Phishing Hardening — 2026-08-09

Trigger: user-reported spamming/phishing attack on KRTaker infrastructure.
Full audit executed from the deploy box; no active compromise found, attack
surface closed.

## Audit results (all clean)

- **No open relay**: Exim rejects unauthenticated foreign MAIL FROM (550);
  anti-spam banner present. Auth required for sending.
- **No injected files** in public_html (52 files, all known KRTaker pages).
- **No rogue mailboxes**: billing, dkimtest, info, noreply, support, krtaker — all legit.
- **No rogue DNS**: full zone reviewed — only test/mail/www/ftp, all → 37.27.134.21.
- **No spam registrations** in DB; all public mail-sending endpoints rate-limited
  (register ≤8/IP/hr ≤4/email/hr, resend same, forgot-password throttled,
  contact ≤5/IP/10min, newsletter ≤6/IP/10min).
- **Error logs**: only legacy `merittradeinter` CMS noise + known SQLite lock errors.

## Changes applied (live)

| Item | Before | After |
|---|---|---|
| DMARC | p=quarantine | **p=reject; sp=reject** (rua/ruf kept, pct=100) |
| test.krtaker.com SPF | ~all | **-all** |
| test.krtaker.com access | public | **Basic Auth** (creds: krtaker-deploy/staging_creds.txt, 0600) |
| Probe files | 7 present | **removed** (kr_probe_f.php, kr_mail_probe.php, kr_smtp_probe.php, og_uploader.php, _t.txt, probe_wl.php, pv.php) |

## Verification

- `dig +short TXT _dmarc.krtaker.com @ns1.coderdrop.com` → p=reject (public resolver lags up to TTL 4h)
- `dig +short TXT test.krtaker.com @ns1.coderdrop.com` → -all
- `curl https://test.krtaker.com/` → 401 unauth / 200 with creds
- krtaker.com + /api/health → 200

## Tooling notes (for future sessions)

- UAPI `Fileman::fileop` / `Fileman::chmod` do not exist → use API2 `Fileman::fileop`.
- API2 fileop **ignores the `dir` param** → pass full path in `sourcefiles-0`.
- `Fileman::list_files` is blind to dotfiles → verify .htaccess/.htpasswd via get/save_file_content.
- Staging creds live in `krtaker-deploy/staging_creds.txt` (0600) — not in memory/repo.

## Still open (carried)

- krtaker.env.php is 644 (outside docroot, not web-accessible; chmod unavailable via API — acceptable).
- PTR/EHLO branding fix still requires coderdrop action (ticket drafted).
