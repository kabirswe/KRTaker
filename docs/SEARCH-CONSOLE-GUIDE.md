# Search Console Verification — KRTaker (5-minute guide)

**Date:** 2026-08-17 · **Status:** Not verified yet · **Sitemap:** ready (37 URLs, live)

---

## What's already done

- [x] GA4 installed (`G-C68G5Q03ZT`) on all landing pages + dashboard (V2.50 re-deploy)
- [x] `sitemap.xml` live — 37 URLs (all landing pages, blog slugs, security/privacy)
- [x] `robots.txt` live — points to `sitemap.xml` + dynamic `/api/sitemap`
- [x] IndexNow key file live (audit 36/36)
- [ ] **Search Console property verified** ← this step
- [ ] Sitemap submitted in Search Console
- [ ] (optional) IndexNow key submitted to Bing too

---

## Method A — DNS TXT (recommended, no re-deploy needed) ⭐

1. Go to **https://search.google.com/search-console** → sign in with the Google
   account that owns GA4 (`G-C68G5Q03ZT`)
2. Add property → **Domain** → type `krtaker.com`
3. Google gives a **TXT record** like `google-site-verification=XXXXXXXX`
4. Add it as a TXT record in the **cPanel DNS Zone Editor** (krtaker.com)
   - Wait — GO-LIVE §3.1 notes the reseller cPanel DNS API is **read-only**;
     if the zone can't be edited from cPanel, use the registrar's DNS panel
     (wherever krtaker.com's nameservers point) — usually Namecheap/GoDaddy/Cloudflare
5. Click **Verify** — instant once DNS propagates (usually < 1 min, up to a few hours)

> Why this method: it verifies the whole domain (all subdomains incl. app-v3),
> never needs to touch the site files, and doesn't depend on hosting access.

## Method B — HTML file (if DNS editing is blocked)

1. Add property → **URL prefix** → `https://krtaker.com`
2. Google gives a file like `google12345.html` to upload to the webroot
3. Upload via the deploy path (repo `web/` → `deploy_repo_web.py`) so it
   doesn't get clobbered by the next deploy
4. Verify

## Method C — HTML meta tag

1. Add property → URL prefix → copy the `<meta name="google-site-verification" content="...">`
2. Add to the `<head>` of `web/template.html` (the shared shell) + `web/index.html`
3. Rebuild/deploy (repo → live via `deploy_repo_web.py`)
4. Verify

---

## After verification

1. **Submit the sitemap:** Search Console → Sitemaps → `https://krtaker.com/sitemap.xml` → Submit
2. **Request indexing** (optional): URL Inspection → paste `https://krtaker.com/` → Request Indexing
3. **Bing Webmaster Tools** (bonus): same sitemap + IndexNow key — cheap extra search traffic
4. Mark GO-LIVE §6 item 2 complete

## What I need from you

- **Which Google account** owns the GA4 property? (I can't see it from here — the
  measurement ID is in the code, but the console login is yours)
- **Where do krtaker.com's nameservers point?** (cPanel host = likely the reseller's
  DNS; if read-only, we need the registrar login)

Once you tell me those two, pick **Method A** (best) and paste the TXT value here —
or if you give me cPanel/registrar access I'll do the whole flow.

---

*Prepared 2026-08-17. Companion: docs/GO-LIVE.md §3.1 / §6 item 2.*
