function mask_secret($v) {
    $s = (string)$v;
    if ($s === '' || $s === 'REPLACE_ME' || $s === 'krtakerTEST' || $s === '013000000000' || $s === 'NAGAD_MERCHANT_ID') return $s;
    if (strlen($s) <= 4) return '•••';
    return '•••' . substr($s, -4);
}

/* ── SA1-fullsite-v4.3: dynamic blog article renderer (chrome mirrors the static article pages; v2 = unified v=3.66 chrome + share/related/CTA outside CMS element + dynamic related cards) ── */
function blog_article_html($p) {
    $title = htmlspecialchars((string)$p['title'], ENT_QUOTES);
    $titleQ = rawurlencode((string)$p['title']);
    $excerpt = htmlspecialchars((string)$p['excerpt'], ENT_QUOTES);
    $tag = htmlspecialchars((string)$p['tag'], ENT_QUOTES);
    $slug = rawurlencode((string)$p['slug']);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'krtaker.com';
    $base = $scheme . '://' . $host;
    $url = $base . '/blog/' . $slug;
    $date = date('M j, Y', strtotime((string)$p['created_at']));
    $iso = substr((string)$p['created_at'], 0, 10);
    $read = (int)($p['read_min'] ?? 5); if ($read < 1) $read = 1;
    $body = (string)$p['body'];
    $tagHtml = $tag !== '' ? '<span>🏷 ' . $tag . '</span>' : '';
    /* related: latest 3 published, excluding self */
    $rel = '';
    try {
        $pdo = db();
        $rst = $pdo->prepare("SELECT slug, title FROM blog_posts WHERE status='published' AND slug <> ? ORDER BY created_at DESC LIMIT 3");
        $rst->execute([(string)$p['slug']]);
        while ($r = $rst->fetch(PDO::FETCH_ASSOC)) {
            $rel .= '<a class="related-card" href="/blog/' . rawurlencode((string)$r['slug']) . '"><b>' . htmlspecialchars((string)$r['title'], ENT_QUOTES) . '</b><span>Read →</span></a>';
        }
    } catch (Exception $e) { $rel = ''; }
    $h = <<<HDR
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#2F80ED">
<meta name="description" content="__EXCERPT__">
<title>__TITLE__ — KRTaker</title>
<base href="$base/">
<link rel="canonical" href="__URL__">
<meta property="og:type" content="article">
<meta property="og:site_name" content="KRTaker">
<meta property="og:title" content="__TITLE__ — KRTaker">
<meta property="og:description" content="__EXCERPT__">
<meta property="og:url" content="__URL__">
<meta property="og:image" content="$base/assets/og-default.png">
<meta property="og:locale" content="en_US">
<meta property="og:locale:alternate" content="bn_BD">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="__TITLE__ — KRTaker">
<meta name="twitter:description" content="__EXCERPT__">
<meta name="twitter:image" content="$base/assets/og-default.png">
<script type="application/ld+json">{"@context": "https://schema.org", "@type": "BlogPosting", "headline": "__TITLE__ — KRTaker", "description": "__EXCERPT__", "datePublished": "__ISO__", "dateModified": "__ISO__", "author": {"@type": "Organization", "name": "KRTaker"}, "publisher": {"@type": "Organization", "name": "KRTaker", "logo": {"@type": "ImageObject", "url": "$base/pwa/icon-192.png"}}, "mainEntityOfPage": "__URL__"}</script>
<link rel="icon" href="/pwa/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/pwa/icon-192.png">
<link rel="manifest" href="/manifest.json">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Hind+Siliguri:wght@300;400;500;600;700&display=swap" as="style">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Hind+Siliguri:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/style.css?v=3.73">
<!-- KRTaker analytics: Google Analytics 4 (G-C68G5Q03ZT) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-C68G5Q03ZT"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', 'G-C68G5Q03ZT');
</script>
</head>
<body data-page="blog-__PAGE__">

HDR;
    $h = str_replace('__EXCERPT__', $excerpt, $h);
    $h = str_replace('__TITLE__', $title, $h);
    $h = str_replace('__URL__', $url, $h);
    $h = str_replace('__ISO__', $iso, $h);
    $h = str_replace('__PAGE__', htmlspecialchars((string)$p['slug'], ENT_QUOTES), $h);
    $h .= <<<NVB
<!-- ===== NAVBAR ===== -->
<nav class="navbar">
  <div class="container">
    <a class="nav-logo" href="/index.html"><img src="/assets/img/krtaker-logo.png" alt="KRTaker" class="nav-logo-img"></a>
    <button class="nav-toggle" aria-label="Menu">☰</button>
    <ul class="nav-links">
      <li class="mega-li">
        <a href="/features.html" class="mega-trigger " data-i18n="nav.platform">Platform <span class="chev">▾</span></a>
        <div class="mega-dropdown mega-wide mega-center">
          <div class="mega-featured">
            <h4 data-i18n="nav.mfPlatform">Everything a building needs</h4>
            <p data-i18n="nav.mfPlatformSub">Nine modules working as one digital caretaker.</p>
          </div>
          <a href="/features.html"><span class="mega-ico">🧾</span><span data-i18n="nav.features">Features</span></a>
          <a href="/how-it-works.html"><span class="mega-ico">🗺️</span><span data-i18n="nav.how">How it works</span></a>
          <a href="/legal-compliance.html"><span class="mega-ico">⚖️</span><span data-i18n="footer.legal">Legal &amp; Compliance</span></a>
          <a href="/features.html"><span class="mega-ico">🏦</span><span data-i18n="home.feat2t">Holding tax engine</span></a>
          <a href="/features.html"><span class="mega-ico">💳</span><span data-i18n="home.feat4t">bKash &amp; SSLCommerz</span></a>
          <a href="/features.html"><span class="mega-ico">📊</span><span data-i18n="home.feat9t">Reports that tell the truth</span></a>
        </div>
      </li>
      <li class="mega-li">
        <a href="/for-owners.html" class="mega-trigger " data-i18n="nav.forYou">For you <span class="chev">▾</span></a>
        <div class="mega-dropdown mega-wide mega-center">
          <div class="mega-featured">
            <h4 data-i18n="nav.mfForYou">One platform, nine roles</h4>
            <p data-i18n="nav.mfForYouSub">From owner to field crew — everyone in the same system.</p>
          </div>
          <a href="/for-owners.html"><span class="mega-ico">🏠</span><span data-i18n="footer.owners">Property Owners</span></a>
          <a href="/for-tenants.html"><span class="mega-ico">👤</span><span data-i18n="footer.tenants">Tenants</span></a>
          <a href="/for-partners.html"><span class="mega-ico">🛠️</span><span data-i18n="footer.partners">Service Partners</span></a>
          <a href="/for-nrb.html"><span class="mega-ico">🌍</span><span data-i18n="footer.nrb">NRB Investors</span></a>
          <a href="/register.html"><span class="mega-ico">📝</span><span data-i18n="footer.register">Register</span></a>
          <a href="/login.html"><span class="mega-ico">🔐</span><span data-i18n="footer.login">Log in</span></a>
        </div>
      </li>
      <li class="mega-li">
        <a href="/blog.html" class="mega-trigger active" data-i18n="nav.resources">Resources <span class="chev">▾</span></a>
        <div class="mega-dropdown mega-center">
          <div class="mega-featured">
            <h4 data-i18n="nav.mfResources">Insights &amp; Blog</h4>
            <p data-i18n="nav.mfResourcesSub">Guides on BD property law, taxes &amp; portfolio management.</p>
          </div>
          <a href="/tools.html"><span class="mega-ico">🧮</span><span data-i18n="misc.calculators">Calculators</span></a>
          <a href="/blog.html"><span class="mega-ico">📰</span><span data-i18n="footer.blog">Insights &amp; Blog</span></a>
          <a href="/case-studies.html"><span class="mega-ico">📈</span><span data-i18n="footer.caseStudies">Case studies</span></a>
          <a href="/faq.html"><span class="mega-ico">❓</span><span data-i18n="footer.faq">FAQ</span></a>
          <a href="/about.html"><span class="mega-ico">ℹ️</span><span data-i18n="footer.about">About us</span></a>
          <a href="/contact.html"><span class="mega-ico">📞</span><span data-i18n="footer.contact">Contact</span></a>
        </div>
      </li>
      <li><a href="/pricing.html" class="" data-i18n="nav.pricing">Pricing</a></li>
      <li><a href="/ai-caretaker.html" class="" data-i18n="nav.ai">AI Caretaker</a></li>
      <li><a href="/listings.html" class="" data-i18n="nav.listings">Listings</a></li>
    </ul>
    <div class="nav-cta">
      <button class="icon-btn" data-lang-toggle aria-label="Language">বাং</button>
      <button class="icon-btn" data-theme-toggle aria-label="Toggle dark mode">🌙</button>
      <a href="/login.html" class="btn btn-ghost nav-ghost" data-i18n="nav.login">Log in</a>
      <a href="/register.html" class="btn btn-primary nav-primary" data-i18n="nav.getStarted">Get started</a>
    </div>
  </div>
</nav>
NVB;
    $h .= str_replace(array('__TITLE__','__TITLE_Q__','__EXCERPT__','__DATE__','__READ__','__TAG_HTML__','__BODY__','__RELATED__','__URL__'),
        array($title, $titleQ, $excerpt, $date, (string)$read, $tagHtml, $body, $rel, $url), <<<HRO

<div class="page-hero">
  <div class="container">
    <h1>__TITLE__</h1>
    <p>__EXCERPT__</p>
  </div>
</div>
<div class="page-body">
  <div class="container" style="max-width:780px">
    <div class="blog-meta" style="margin-bottom:24px"><span>📅 __DATE__</span><span>⏱ __READ__ min read</span>__TAG_HTML__</div>
    __BODY__
  </div>
  <div class="container" style="max-width:780px">
    <div class="share-strip"><span class="share-label">Share this guide</span><a class="share-btn" href="https://twitter.com/intent/tweet?text=__TITLE_Q__&amp;url=__URL__" target="_blank" rel="noopener" aria-label="Share on X">𝕏</a><a class="share-btn" href="https://www.facebook.com/sharer/sharer.php?u=__URL__" target="_blank" rel="noopener" aria-label="Share on Facebook">f</a><a class="share-btn" href="https://www.linkedin.com/sharing/share-offsite/?url=__URL__" target="_blank" rel="noopener" aria-label="Share on LinkedIn">in</a><a class="share-btn wa" href="https://wa.me/?text=__TITLE_Q__%20__URL__" target="_blank" rel="noopener" aria-label="Share on WhatsApp">✆</a></div>
    <div class="related-grid"><h3>Keep reading</h3><div class="related-cards">__RELATED__</div></div>
    <div class="post-cta"><div><h3>Put this into action</h3><p>KRTaker computes holding tax, TDS, NAV and compliance dates automatically for every property.</p></div><a href="/register.html" class="btn btn-white">Start free trial →</a></div>
  </div>
</div>

HRO);
    $h .= <<<FTR
<!-- ===== FOOTER ===== -->
<footer class="footer">
  <div class="container">
    <div class="footer-top">
      <div class="footer-brand">
        <div class="nav-logo"><img src="/assets/img/krtaker-logo-full-white.png" alt="KRTaker" class="nav-logo-img nav-logo-footer"></div>
        <p data-i18n="footer.blurb" data-cms="footer.section.tagline">Key Responsibility Taker — the AI-driven autonomous property management platform for Bangladesh. One caretaker for your entire portfolio, from anywhere in the world.</p>
        <div class="footer-socials">
          <a href="https://www.facebook.com/people/KRTaker/61592897676181" class="soc" aria-label="Facebook" target="_blank" rel="noopener">f</a>
          <a href="https://www.linkedin.com/company/krtaker" class="soc" aria-label="LinkedIn" target="_blank" rel="noopener">in</a>
          <a href="https://x.com/krtaker" class="soc" aria-label="X (Twitter)" target="_blank" rel="noopener">𝕏</a>
          <a href="https://www.youtube.com/@krtaker" class="soc" aria-label="YouTube" target="_blank" rel="noopener">▶</a>
          <a href="https://wa.me/8801844680068" class="soc" aria-label="WhatsApp" target="_blank" rel="noopener">✆</a>
        </div>
      </div>
      <div class="footer-news">
        <h4 data-i18n="footer.newsTitle" data-cms="footer.news.title">Property insights, in Bengali &amp; English</h4>
        <p data-i18n="footer.newsSub" data-cms="footer.news.sub">Holding tax, TDS, PRCA updates and product news — once a month, no spam.</p>
        <form class="news-form" id="newsletterForm" novalidate>
          <input type="email" id="newsEmail" data-i18n-ph="footer.newsPh" placeholder="you@example.com" required data-cms-ph="footer.news.ph">
          <button type="submit" data-i18n="footer.newsBtn" data-cms="footer.news.btn">Subscribe</button>
        </form>
        <div class="footer-pay">
          <span data-cms="footer.pay.label">Pays with</span>
          <span class="pay-chip">bKash</span>
          <span class="pay-chip">Nagad</span>
          <span class="pay-chip">SSLCommerz</span>
        </div>
      </div>
    </div>
    <div class="footer-grid">
      <div>
        <h4 data-i18n="footer.p1" data-cms="footer.cols.c1">Platform</h4>
        <ul>
          <li><a href="/features.html" data-i18n="footer.features">Features</a></li>
          <li><a href="/how-it-works.html" data-i18n="footer.how">How it works</a></li>
          <li><a href="/pricing.html" data-i18n="footer.pricing">Pricing</a></li>
          <li><a href="/register.html" data-i18n="footer.register">Register</a></li>
          <li><a href="/login.html" data-i18n="footer.login">Log in</a></li>
        </ul>
      </div>
      <div>
        <h4 data-i18n="footer.p2" data-cms="footer.cols.c2">For you</h4>
        <ul>
          <li><a href="/for-owners.html" data-i18n="footer.owners">Property Owners</a></li>
          <li><a href="/for-tenants.html" data-i18n="footer.tenants">Tenants</a></li>
          <li><a href="/for-partners.html" data-i18n="footer.partners">Service Partners</a></li>
          <li><a href="/for-nrb.html" data-i18n="footer.nrb">NRB Investors</a></li>
          <li><a href="/legal-compliance.html" data-i18n="footer.legal">Legal &amp; Compliance</a></li>
        </ul>
      </div>
      <div>
        <h4 data-i18n="footer.p3" data-cms="footer.cols.c3">Company</h4>
        <ul>
          <li><a href="/about.html" data-i18n="footer.about">About us</a></li>
          <li><a href="/blog.html" data-i18n="footer.blog">Insights &amp; Blog</a></li>
          <li><a href="/case-studies.html" data-i18n="footer.caseStudies">Case studies</a></li>
          <li><a href="/faq.html" data-i18n="footer.faq">FAQ</a></li>
          <li><a href="/contact.html" data-i18n="footer.contact">Contact</a></li>
          <li><a href="/ai-caretaker.html" data-i18n="footer.ai">AI Caretaker</a></li>
        </ul>
      </div>
      <div>
        <h4 data-i18n="footer.p4" data-cms="footer.cols.c4">Get in touch</h4>
        <ul class="footer-contact">
          <li data-i18n="footer.c1" data-cms="footer.contact.c1">📧 hello@krtaker.com</li>
          <li data-i18n="footer.c2" data-cms="footer.contact.c2">📞 +880 1712-000000</li>
          <li data-i18n="footer.c3" data-cms="footer.contact.c3">📍 Dhaka, Bangladesh</li>
          <li data-i18n="footer.c4" data-cms="footer.contact.c4">🕐 24/7 AI caretaker online</li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span data-i18n="footer.rights">© 2026 KRTaker. All rights reserved.</span>
      <span class="footer-made" data-i18n="footer.madeIn" data-cms="footer.bottom.made">Made in Bangladesh 🇧🇩</span>
      <span><a href="terms.html" data-i18n="footer.terms">Terms</a> · <a href="privacy.html" data-i18n="footer.privacy">Privacy</a> · <a href="contact.html" data-i18n="footer.support">Support</a></span>
    </div>
  </div>
</footer>
FTR;
    $h .= <<<TAL
<!-- Mobile sticky CTA -->
<div class="sticky-cta">
  <a href="register.html" data-i18n="shared.stickyCta">Start free trial — 14 days, no card</a>
</div>
<!-- Back to top -->
<button class="btt-btn" aria-label="Back to top">↑</button>
<script src="i18n/i18n-dict.js?v=3.66"></script>
<script src="js/i18n.js?v=3.66"></script>
<script src="js/cms-hydrate.js?v=3.66"></script>
<script src="js/main.js?v=3.66"></script>
<script src="js/chat.js?v=3.66"></script>
<script>
if ('serviceWorker' in navigator) { window.addEventListener('load', function(){ navigator.serviceWorker.register('sw.js').catch(function(){}); }); }
</script>
</body>
</html>
TAL;
    return $h;
}

/* ── SA1-fullsite: API Library — web & mobile API key store (platform_meta api_keys JSON) ── */
function api_keys_get($pdo) {
    $cur = json_decode((string)$pdo->query("SELECT v FROM platform_meta WHERE k='api_keys'")->fetchColumn(), true) ?: [];
    $def = ['web_api_key' => 'krweb-' . substr(hash('sha256', 'krtaker-web'), 0, 16),
            'mobile_api_key' => 'krmob-' . substr(hash('sha256', 'krtaker-mobile'), 0, 16)];
    foreach ($def as $k => $v) if (!isset($cur[$k]) || !is_string($cur[$k]) || $cur[$k] === '') $cur[$k] = $v;
    $cur['updated_at'] = (string)($cur['updated_at'] ?? '');
    return $cur;
}
function api_keys_save($pdo, $in) {
    $cur = api_keys_get($pdo);
    foreach (['web_api_key', 'mobile_api_key'] as $k) {
        if (isset($in[$k]) && is_string($in[$k])) {
            $v = trim($in[$k]);
            if ($v === '' || strpos($v, '•••') === 0) continue;      // masked → keep existing
            if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $v)) return ['error' => $k . ' must be 16-64 chars (A-Z a-z 0-9 _ -).'];
            $cur[$k] = $v;
        }
    }
    $cur['updated_at'] = gmdate('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO platform_meta (k, v) VALUES ('api_keys', ?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")
        ->execute([json_encode($cur)]);
    return $cur;
}
function api_key_ok($pdo, $kind, $key) {
    if (!is_string($key) || $key === '') return false;
    $cur = api_keys_get($pdo);
    return hash_equals((string)($cur[$kind] ?? ''), $key);
}
/* ── SA1-fullsite v9 (v3.63): per-tenant API keys — subscribers authenticate with their own key ── */
function tenant_key_gen() { return 'krt_' . bin2hex(random_bytes(16)); }
function tenant_key_find($pdo, $key) {
    if (!is_string($key) || $key === '') return false;
    $st = $pdo->prepare('SELECT * FROM tenant_api_keys WHERE key=? AND active=1');
    $st->execute([$key]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: false;
}
function tenant_key_touch($pdo, $row) {
    try {
        $pdo->prepare("UPDATE tenant_api_keys SET last_used_at=datetime('now'), last_ip=?, calls=calls+1 WHERE id=?")
            ->execute([client_ip(), (int)$row['id']]);
    } catch (Exception $e) { /* telemetry must never break the request */ }
}
/* Resolve any X-API-Key: platform web/mobile OR a tenant key. Returns
   ['kind'=>'web'|'mobile'|'tenant', 'tenant_id'=>int, 'row'=>…] or null. */
function api_key_resolve($pdo, $key) {
    if (!is_string($key) || $key === '') return null;
    $cur = api_keys_get($pdo);
    foreach (['web_api_key', 'mobile_api_key'] as $kind)
        if (hash_equals((string)($cur[$kind] ?? ''), $key)) return ['kind' => $kind, 'tenant_id' => 0, 'row' => null];
    $tk = tenant_key_find($pdo, $key);
    if ($tk) return ['kind' => 'tenant', 'tenant_id' => (int)$tk['tenant_id'], 'row' => $tk];
    return null;
}
/* ── SA1-fullsite-v3: API usage logging + key lifecycle (last-used, enforcement, rate limit) ── */
function api_key_meta($pdo) {
    $m = json_decode((string)$pdo->query("SELECT v FROM platform_meta WHERE k='api_key_meta'")->fetchColumn(), true) ?: [];
    $def = ['enforce' => 0, 'rate_limit' => 120, 'web_last_used' => '', 'web_last_ip' => '',
            'mobile_last_used' => '', 'mobile_last_ip' => ''];
    foreach ($def as $k => $v) if (!isset($m[$k])) $m[$k] = $v;
    return $m;
}
function api_key_meta_save($pdo, $m) {
    $pdo->prepare("INSERT INTO platform_meta (k, v) VALUES ('api_key_meta', ?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")
        ->execute([json_encode($m)]);
}
function api_key_touch($pdo, $kind) {
    try {
        $m = api_key_meta($pdo);
        $m[$kind . '_last_used'] = gmdate('Y-m-d H:i:s');
        $m[$kind . '_last_ip'] = client_ip();
        api_key_meta_save($pdo, $m);
    } catch (Exception $e) { /* never break the request for telemetry */ }
}
function api_auth_kind() {
    if (isset($_SERVER['HTTP_X_API_KEY']) && $_SERVER['HTTP_X_API_KEY'] !== '') return 'key';
    if (isset($_SERVER['HTTP_X_SERVICE_KEY']) && $_SERVER['HTTP_X_SERVICE_KEY'] !== '') return 'service';
    if (preg_match('/^Bearer\s+/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''))) return 'session';
    return 'public';
}
function api_log_hit($action, $method, $status, $ms) {
    try {
        $pdo = db();
        $ip = client_ip();
        $pdo->prepare('INSERT INTO api_usage (action, method, auth, status, ms, ip_hash) VALUES (?,?,?,?,?,?)')
            ->execute([substr((string)$action, 0, 60), $method, api_auth_kind(), (int)$status, (int)$ms, substr(hash('sha256', $ip), 0, 12)]);
        /* opportunistic prune — keep ~10k rows / 21 days */
        if (random_int(1, 60) === 1) {
            $n = (int)$pdo->query('SELECT COUNT(*) FROM api_usage')->fetchColumn();
            if ($n > 10000) $pdo->exec("DELETE FROM api_usage WHERE id NOT IN (SELECT id FROM api_usage ORDER BY id DESC LIMIT 10000)");
            $pdo->exec("DELETE FROM api_usage WHERE ts < datetime('now','-21 days')");
        }
    } catch (Exception $e) { /* logging must never break the API */ }
}
function api_rate_limit_ok($pdo, $kind, $limit) {
    /* per-key per-minute counter in platform_meta (rl_<kind>_<YYYYmmddHHi>) */
    $min = gmdate('YmdHi');
    try {
        $key = 'rl_' . $kind . '_' . $min;
        $cur = (int)$pdo->query("SELECT v FROM platform_meta WHERE k='" . $key . "'")->fetchColumn();
        $cur++;
        $pdo->prepare("INSERT INTO platform_meta (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")
            ->execute([$key, $cur]);
        if (random_int(1, 30) === 1) $pdo->exec("DELETE FROM platform_meta WHERE k LIKE 'rl\\_%' ESCAPE '\\' AND substr(k, -12) < '" . gmdate('YmdHi', time() - 600) . "'");
        return $cur <= $limit;
    } catch (Exception $e) { return true; }
}
/* ── SA1-fullsite-v3: global search across platform data (super-admin) ── */
function gs_row($view, $title, $sub, $payload = [], $ic = '🔍') {
    return ['view' => $view, 'title' => $title, 'sub' => $sub, 'payload' => $payload, 'ic' => $ic];
}
function global_search($pdo, $q, $limit = 6) {
    $q = trim($q); if ($q === '') return [];
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $out = [];
    /* subscribers */
    $st = $pdo->prepare("SELECT id, name, org, email, phone, plan, status FROM subscribers WHERE name LIKE ? ESCAPE '\\' OR email LIKE ? ESCAPE '\\' OR org LIKE ? ESCAPE '\\' OR phone LIKE ? ESCAPE '\\' ORDER BY id DESC LIMIT " . (int)$limit);
    $st->execute([$like, $like, $like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out['subscribers'][] = gs_row('subscribers', $r['name'], ($r['email'] . ' · ' . $r['plan'] . ' · ' . $r['status']), ['id' => (int)$r['id']], '👥');
    /* app users */
    $st = $pdo->prepare("SELECT id, name, email, role, dept FROM app_users WHERE name LIKE ? ESCAPE '\\' OR email LIKE ? ESCAPE '\\' ORDER BY id LIMIT " . (int)$limit);
    $st->execute([$like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out['users'][] = gs_row('users', $r['name'], ($r['email'] . ' · ' . $r['role'] . ($r['dept'] ? ' · ' . $r['dept'] : '')), ['id' => (int)$r['id']], '👤');
    /* packages */
    $st = $pdo->prepare("SELECT code, name, price FROM plan_catalog WHERE code LIKE ? ESCAPE '\\' OR name LIKE ? ESCAPE '\\' ORDER BY price LIMIT " . (int)$limit);
    $st->execute([$like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out['packages'][] = gs_row('packages', $r['name'], ($r['code'] . ' · ৳' . number_format((int)$r['price'])), ['code' => $r['code']], '📦');
    /* providers/partners */
    $st = $pdo->prepare("SELECT id, name, trade, status FROM partners WHERE name LIKE ? ESCAPE '\\' OR trade LIKE ? ESCAPE '\\' ORDER BY id LIMIT " . (int)$limit);
    $st->execute([$like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out['providers'][] = gs_row('providers', $r['name'], ($r['trade'] . ' · ' . $r['status']), ['id' => $r['id']], '🛠️');
    /* platform tickets */
    $st = $pdo->prepare("SELECT id, from_name, from_email, subject, status FROM platform_tickets WHERE id LIKE ? ESCAPE '\\' OR subject LIKE ? ESCAPE '\\' OR from_name LIKE ? ESCAPE '\\' OR from_email LIKE ? ESCAPE '\\' ORDER BY id DESC LIMIT " . (int)$limit);
    $st->execute([$like, $like, $like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out['tickets'][] = gs_row('tickets', ('#' . $r['id'] . ' — ' . $r['subject']), ($r['from_name'] . ' · ' . $r['status']), ['id' => $r['id']], '🎫');
    /* leads */
    $st = $pdo->prepare("SELECT id, name, email, phone, source, status FROM leads WHERE name LIKE ? ESCAPE '\\' OR email LIKE ? ESCAPE '\\' OR phone LIKE ? ESCAPE '\\' ORDER BY id DESC LIMIT " . (int)$limit);
    $st->execute([$like, $like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out['leads'][] = gs_row('onboarding', ('Lead — ' . $r['name']), ($r['email'] . ' · ' . ($r['status'] ?? '')), ['tab' => 'leads', 'id' => $r['id']], '🎧');
    /* CMS blocks */
    $st = $pdo->prepare("SELECT page, section, k, v FROM cms_content WHERE v LIKE ? ESCAPE '\\' OR k LIKE ? ESCAPE '\\' ORDER BY page, section LIMIT " . (int)$limit);
    $st->execute([$like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out['cms'][] = gs_row('cms', ($r['page'] . ' / ' . $r['section'] . ' / ' . $r['k']), substr((string)$r['v'], 0, 90), ['page' => $r['page'], 'section' => $r['section'], 'k' => $r['k']], '🌐');
    /* ledger */
    $st = $pdo->prepare("SELECT id, kind, cat, label, amount FROM company_ledger WHERE label LIKE ? ESCAPE '\\' OR ref LIKE ? ESCAPE '\\' ORDER BY id DESC LIMIT " . (int)$limit);
    $st->execute([$like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out['finance'][] = gs_row('finance', ($r['label'] . ' — ৳' . number_format((int)$r['amount'])), ($r['kind'] . ' · ' . $r['cat']), ['id' => (int)$r['id']], '💰');
    /* audit */
    $st = $pdo->prepare("SELECT id, user, action, module, entity, ts FROM audit_log WHERE user LIKE ? ESCAPE '\\' OR action LIKE ? ESCAPE '\\' OR entity LIKE ? ESCAPE '\\' ORDER BY id DESC LIMIT " . (int)$limit);
    $st->execute([$like, $like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out['audit'][] = gs_row('audit', ($r['user'] . ' — ' . $r['action']), ($r['module'] . ' · ' . substr((string)$r['entity'], 0, 60) . ' · ' . $r['ts']), ['id' => (int)$r['id']], '📜');
    return $out;
}
/* ---------- Phase 19: org-level settings + notification-preference enforcement ---------- */
function ORG_DEFAULTS() {
    return [
        'inv_prefix' => 'INV-', 'org_name' => 'KRTaker', 'org_tagline' => 'AI CARETAKER',
        'default_lease_months' => 12, 'khajna_calendar' => '[]',
        'invoice_footer' => 'This is a system-generated invoice from KRTaker — your AI caretaker. Verify at krtaker.com · For questions: support@krtaker.com',
    ];
}
function org_cfg($pdo) {
    $out = ORG_DEFAULTS();
    $st = $pdo->prepare('SELECT k, v FROM org_settings');
    $st->execute();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['k']] = $r['v'];
    return $out;
}
function org_cfg_save($pdo, $in) {
    if (!is_array($in)) $in = [];
    $cfg = org_cfg($pdo);
    foreach (array_keys(ORG_DEFAULTS()) as $k) {
        if (!array_key_exists($k, $in)) continue;
        $v = $in[$k];
        if ($k === 'inv_prefix') {
            $v = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$v);
            if ($v !== '' && substr($v, -1) !== '-') $v .= '-';
        }
        elseif ($k === 'default_lease_months') $v = (string)max(1, min(120, (int)$v));
        elseif ($k === 'khajna_calendar') $v = json_encode(is_array($v) ? $v : (json_decode((string)$v, true) ?: []));
        else $v = trim((string)$v);
        $cfg[$k] = $v;
    }
    foreach ($cfg as $k => $v) {
        $pdo->prepare("INSERT INTO org_settings (k, v, updated_at) VALUES (?,?,datetime('now'))
            ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')")->execute([$k, (string)$v]);
    }
    return $cfg;
}
function invoice_next_id($pdo) {
    $cfg = org_cfg($pdo);
    $prefix = $cfg['inv_prefix'] !== '' ? $cfg['inv_prefix'] : 'INV-';
    $year = gmdate('Y');
    $pfx = $prefix . $year . '-';
    $like = $pdo->quote($pfx . '%');
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id," . $pdo->quote($pfx) . ",'') AS INTEGER)) FROM invoices WHERE id LIKE " . $like)->fetchColumn();
    return $pfx . str_pad((string)($mx + 1), 4, '0', STR_PAD_LEFT);
}
/* Respect a user's notify_* preference for a recipient email. Unknown recipients → send (default). */
function notify_ok($pdo, $email, $flag) {
    if (!$email) return true;
    $email = trim($email);
    $st = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'");
    $st->execute([$email]);
    $uid = $st->fetchColumn();
    if ($uid) return (bool)(settings_get($pdo, 'sub:' . $uid)[$flag] ?? true);
    $st = $pdo->prepare('SELECT id FROM app_users WHERE email=? AND active=1');
    $st->execute([$email]);
    $uid = $st->fetchColumn();
    if ($uid) return (bool)(settings_get($pdo, 'staff:' . $uid)[$flag] ?? true);
    return true;
}
/* ---------- Phase 20: utility billing (readings → usage → bill → pay) ---------- */
function UTILITY_TYPES() {
    return ['electric' => ['rate' => 10.0, 'standing' => 0, 'unit_label' => 'kWh', 'enabled' => 1],
            'gas'      => ['rate' => 15.0, 'standing' => 0, 'unit_label' => 'm³',  'enabled' => 1],
            'water'    => ['rate' => 20.0, 'standing' => 0, 'unit_label' => 'm³',  'enabled' => 1]];
}
function utility_tariffs($pdo) {
    $def = UTILITY_TYPES();
    $st = $pdo->query('SELECT * FROM utility_tariffs');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($def[$r['type']])) continue;
        $def[$r['type']] = ['rate' => (float)$r['rate'], 'standing' => (int)$r['standing'],
                            'unit_label' => $r['unit_label'] ?: $def[$r['type']]['unit_label'],
                            'enabled' => (int)$r['enabled']];
    }
    return $def;
}
function utility_tariffs_save($pdo, $in) {
    if (!is_array($in)) $in = [];
    $upd = $pdo->prepare('INSERT INTO utility_tariffs (type, rate, standing, unit_label, enabled, updated_at) VALUES (?,?,?,?,?,datetime(\'now\'))
        ON CONFLICT(type) DO UPDATE SET rate=excluded.rate, standing=excluded.standing, unit_label=excluded.unit_label, enabled=excluded.enabled, updated_at=datetime(\'now\')');
    foreach (UTILITY_TYPES() as $type => $def) {
        if (!isset($in[$type])) continue;
        $v = $in[$type];
        $rate = isset($v['rate']) ? max(0, (float)$v['rate']) : $def['rate'];
        $standing = isset($v['standing']) ? max(0, (int)$v['standing']) : $def['standing'];
        $label = isset($v['unit_label']) ? trim((string)$v['unit_label']) : $def['unit_label'];
        $enabled = isset($v['enabled']) ? ((int)$v['enabled'] ? 1 : 0) : 1;
        $upd->execute([$type, $rate, $standing, $label, $enabled]);
    }
    return utility_tariffs($pdo);
}
function utility_bill_calc($pdo, $unit, $type, $month, $overrides = []) {
    if (!in_array($type, array_keys(UTILITY_TYPES()), true)) return ['error' => 'type must be electric, gas or water.'];
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) return ['error' => 'month must be YYYY-MM.'];
    if (!$unit) return ['error' => 'unit required.'];
    $tar = utility_tariffs($pdo)[$type];
    if (!$tar['enabled']) return ['error' => 'Tariff for ' . $type . ' is disabled.'];
    /* current reading: exact month row from meter_readings, or explicit override */
    $curr = null;
    if (isset($overrides['curr_reading'])) $curr = (int)$overrides['curr_reading'];
    else {
        $st = $pdo->prepare('SELECT reading FROM meter_readings WHERE unit=? AND type=? AND month=?');
        $st->execute([$unit, $type, $month]);
        $curr = $st->fetchColumn();
        if ($curr === false) return ['error' => 'No meter reading for ' . $unit . ' / ' . $type . ' / ' . $month . '. Submit the reading first.'];
    }
    /* previous reading: latest month < current */
    $prev = null;
    if (isset($overrides['prev_reading'])) $prev = (int)$overrides['prev_reading'];
    else {
        $st = $pdo->prepare('SELECT reading FROM meter_readings WHERE unit=? AND type=? AND month<? ORDER BY month DESC LIMIT 1');
        $st->execute([$unit, $type, $month]);
        $pv = $st->fetchColumn();
        if ($pv !== false) $prev = (int)$pv;
    }
    $usage = $prev === null ? $curr : max(0, $curr - $prev);
    $amount = (int)round($usage * $tar['rate'] + $tar['standing']);
    return ['unit' => $unit, 'type' => $type, 'month' => $month,
            'prev_reading' => $prev === null ? 0 : $prev, 'curr_reading' => (int)$curr, 'usage' => $usage,
            'rate' => $tar['rate'], 'standing' => $tar['standing'], 'amount' => $amount,
            'unit_label' => $tar['unit_label']];
}
function utility_bill_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'UB-','') AS INTEGER)) FROM utility_bills")->fetchColumn();
    return 'UB-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function utility_bill_upsert($pdo, $calc) {
    if (isset($calc['error'])) return $calc;
    $st = $pdo->prepare('SELECT id FROM utility_bills WHERE unit=? AND type=? AND month=?');
    $st->execute([$calc['unit'], $calc['type'], $calc['month']]);
    $id = $st->fetchColumn();
    if (!$id) $id = utility_bill_next_id($pdo);
    $pdo->prepare('INSERT INTO utility_bills (id, unit, tenant, type, month, prev_reading, curr_reading, usage, rate, standing, amount, status, note, ts) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,datetime(\'now\'))
        ON CONFLICT(unit, type, month) DO UPDATE SET prev_reading=excluded.prev_reading, curr_reading=excluded.curr_reading,
        usage=excluded.usage, rate=excluded.rate, standing=excluded.standing, amount=excluded.amount, ts=datetime(\'now\')')
        ->execute([$id, $calc['unit'], $calc['tenant'] ?? '', $calc['type'], $calc['month'], $calc['prev_reading'], $calc['curr_reading'],
                   $calc['usage'], $calc['rate'], $calc['standing'], $calc['amount'], 'Unpaid', $calc['note'] ?? '']);
    $calc['id'] = $id;
    $calc['status'] = 'Unpaid';
    return $calc;
}
/* Phase 34: batch utility run — generate bills for every leased unit in a month */
function utility_batch_run($pdo, $month, $prop = null) {
    $sql = "SELECT l.u AS unit, (SELECT l2.t FROM leases l2 WHERE l2.u=l.u AND l2.status IN ('Active','Pending Registration') ORDER BY l2.start DESC LIMIT 1) AS tenant
            FROM leases l JOIN units u ON u.id=l.u
            WHERE l.status IN ('Active','Pending Registration')";
    $args = [];
    if ($prop) { $sql .= ' AND u.p=?'; $args[] = $prop; }
    $sql .= ' GROUP BY l.u';
    $st = $pdo->prepare($sql); $st->execute($args);
    $units = $st->fetchAll(PDO::FETCH_ASSOC);
    $tars = utility_tariffs($pdo);
    $out = ['generated' => 0, 'updated' => 0, 'skipped' => 0, 'total_amount' => 0, 'errors' => [], 'bills' => []];
    foreach ($units as $u) {
        foreach ($tars as $type => $tar) {
            if (!$tar['enabled']) continue;
            $calc = utility_bill_calc($pdo, $u['unit'], $type, $month);
            if (isset($calc['error'])) { $out['skipped']++; continue; }   /* no reading / tariff off */
            $calc['tenant'] = $u['tenant'];
            $prev = $pdo->prepare('SELECT id FROM utility_bills WHERE unit=? AND type=? AND month=?');
            $prev->execute([$u['unit'], $type, $month]);
            $exists = (bool)$prev->fetchColumn();
            $bill = utility_bill_upsert($pdo, $calc);
            if ($exists) $out['updated']++; else $out['generated']++;
            $out['total_amount'] += (int)$bill['amount'];
            $out['bills'][] = $bill;
        }
    }
    return $out;
}
/* Phase 34: per-tenant utility summary for a month (billed / paid / unpaid) */
function utility_summary($pdo, $month, $unit = null, $tenant = null) {
    $sql = "SELECT b.unit, b.type, b.month, b.amount, b.status, u.name AS unit_name, t.name AS tenant_name
            FROM utility_bills b
            LEFT JOIN units u ON u.id=b.unit LEFT JOIN tenants t ON t.id=b.tenant
            WHERE b.month=? AND b.status!='Void'";
    $args = [$month];
    if ($unit) { $sql .= ' AND b.unit=?'; $args[] = $unit; }
    if ($tenant) { $sql .= ' AND b.tenant=?'; $args[] = $tenant; }
    $st = $pdo->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $billed = 0; $paid = 0; $unpaid = 0;
    foreach ($rows as &$r) {
        $r['amount'] = (int)$r['amount'];
        $billed += $r['amount'];
        if ($r['status'] === 'Paid') $paid += $r['amount']; else $unpaid += $r['amount'];
    }
    return ['month' => $month, 'bills' => $rows, 'billed' => $billed, 'paid' => $paid, 'unpaid' => $unpaid, 'count' => count($rows)];
}
/* Phase 35: apply an approved renewal to the lease (shared by staff decide + tenant accept) */
function renewal_apply($pdo, $rr) {
    $st = $pdo->prepare('SELECT * FROM leases WHERE id=?'); $st->execute([$rr['lease']]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) return ['error' => 'Lease not found.'];
    $st = $pdo->prepare('SELECT * FROM tenants WHERE id=?'); $st->execute([$rr['tenant']]);
    $tn = $st->fetch(PDO::FETCH_ASSOC);
    $d = new DateTime($l['end'] ?: date('Y-m-d'));
    $d->modify('+' . (int)$rr['months'] . ' months');
    $newEnd = $d->format('Y-m-d');
    $newRent = (int)$rr['new_rent'] > 0 ? (int)$rr['new_rent'] : (int)$l['rent'];
    $newStatus = (in_array($l['status'], ['Expired', 'Terminated'], true)) ? 'Active' : $l['status'];
    $pdo->prepare('UPDATE leases SET end=?, rent=?, status=? WHERE id=?')
        ->execute([$newEnd, $newRent, $newStatus, $rr['lease']]);
    /* tenant notice + email */
    if ($tn) {
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'NTC-','') AS INTEGER)) FROM notices")->fetchColumn();
        $ntc = 'NTC-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO notices (id, title, body, author, pinned) VALUES (?,?,?,?,0)')
            ->execute([$ntc, 'Lease renewal approved', 'Your renewal for ' . $rr['lease'] . ' was approved. New end date: ' . $newEnd . '. Monthly rent: ৳' . number_format($newRent) . '.', 'system']);
        if (!empty($tn['sub_email']) && mail_switch($pdo, 'renewal') && notify_ok($pdo, $tn['sub_email'], 'notify_renewal')) {
            $st = $pdo->prepare('SELECT name FROM units WHERE id=?'); $st->execute([$l['u']]);
            $uname = (string)$st->fetchColumn();
            $st = $pdo->prepare('SELECT p FROM units WHERE id=?'); $st->execute([$l['u']]);
            $pid = (string)$st->fetchColumn();
            $st = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $st->execute([$pid]);
            $pname = (string)$st->fetchColumn();
            list($subj, $html) = email_render('renewal_status', [
                'tenant_name' => $tn['name'], 'property' => $pname, 'unit' => $uname . ' (' . $l['u'] . ')',
                'lease' => $rr['lease'], 'status' => 'Approved', 'status_color' => '#059669',
                'new_end' => date('d M Y', strtotime($newEnd)), 'new_rent' => number_format($newRent),
                'note' => 'Your lease has been renewed — thank you for being a valued tenant. The updated agreement is available from your tenant portal.',
            ]);
            send_mail($tn['sub_email'], $subj, $html, null, true);
        }
    }
    return ['new_end' => $newEnd, 'new_rent' => $newRent];
}
/* ── Phase 21: settlement statement + no-dues certificate ── */
function settlement_report($pdo, $tid, $lease_id = null, $opts = []) {
    $st = $pdo->prepare('SELECT * FROM tenants WHERE id=?'); $st->execute([$tid]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return null;
    $st = $pdo->prepare('SELECT * FROM leases WHERE t=? ORDER BY start'); $st->execute([$tid]);
    $leases = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$leases) return ['error' => 'No leases for this tenant.'];
    /* primary lease: requested, else active, else latest */
    $primary = null;
    if ($lease_id) foreach ($leases as $l) if ($l['id'] === $lease_id) { $primary = $l; break; }
    if (!$primary) foreach ($leases as $l) if (in_array($l['status'], ['Active', 'Pending Registration'], true)) { $primary = $l; break; }
    if (!$primary) $primary = $leases[count($leases) - 1];
    $leaseIds = array_column($leases, 'id');
    $unitIds = array_column($leases, 'u');
    $invoices = []; $invIds = [];
    if ($leaseIds) {
        $st = $pdo->prepare('SELECT * FROM invoices WHERE l IN (' . ai_in_list($leaseIds) . ') ORDER BY m');
        $st->execute($leaseIds);
        $invoices = $st->fetchAll(PDO::FETCH_ASSOC);
        $invIds = array_column($invoices, 'id');
    }
    $paidByInv = [];
    if ($invIds) {
        $st = $pdo->prepare('SELECT inv, amount FROM payments WHERE inv IN (' . ai_in_list($invIds) . ") AND status='Success'");
        $st->execute($invIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $paidByInv[$p['inv']] = ($paidByInv[$p['inv']] ?? 0) + (int)$p['amount'];
    }
    $arrears = [];
    foreach ($invoices as $iv) {
        $paid = $paidByInv[$iv['id']] ?? 0;
        $due = max(0, (int)$iv['net'] - $paid);
        if ($due > 0) $arrears[] = ['invoice' => $iv['id'], 'month' => $iv['m'], 'net' => (int)$iv['net'], 'paid' => (int)$paid, 'due' => $due];
    }
    $rentDue = array_sum(array_column($arrears, 'due'));
    $utils = [];
    if ($unitIds) {
        $st = $pdo->prepare('SELECT * FROM utility_bills WHERE unit IN (' . ai_in_list($unitIds) . ") AND status='Unpaid' ORDER BY month, type");
        $st->execute($unitIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) $utils[] = ['id' => $b['id'], 'type' => $b['type'], 'month' => $b['month'], 'usage' => (int)$b['usage'], 'amount' => (int)$b['amount']];
    }
    $utilDue = array_sum(array_column($utils, 'amount'));
    $dmg = [];
    $raw = $opts['deductions'] ?? [];
    if (is_array($raw)) foreach ($raw as $d) {
        if (!is_array($d) || !isset($d['label'])) continue;
        $amt = max(0, (int)($d['amount'] ?? 0));
        if ($amt > 0) $dmg[] = ['label' => trim((string)$d['label']), 'amount' => $amt];
    }
    $dmgDue = array_sum(array_column($dmg, 'amount'));
    $totalDue = $rentDue + $utilDue + $dmgDue;
    $deposit = (int)$primary['adv'];
    $depApplied = ($opts['apply_deposit'] ?? true) ? min($deposit, $totalDue) : 0;
    $balance = $totalDue - $depApplied;
    if ($totalDue === 0) { $status = 'NO_DUES'; $refund = $deposit; }
    elseif ($balance <= 0) { $status = 'SETTLED'; $refund = -$balance; }
    else { $status = 'DUE'; $refund = 0; }
    $hovo = [];
    $st = $pdo->prepare('SELECT id, kind, status, ts FROM handover_checklists WHERE lease=? ORDER BY kind'); $st->execute([$primary['id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) $hovo[] = $h;
    $urow = null; $prow = null;
    if ($primary['u']) { $st = $pdo->prepare('SELECT * FROM units WHERE id=?'); $st->execute([$primary['u']]); $urow = $st->fetch(PDO::FETCH_ASSOC); }
    if ($urow && $urow['p']) { $st = $pdo->prepare('SELECT * FROM properties WHERE id=?'); $st->execute([$urow['p']]); $prow = $st->fetch(PDO::FETCH_ASSOC); }
    $cfg = org_cfg($pdo);
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'SET-','') AS INTEGER)) FROM settlement_reports")->fetchColumn();
    $rid = 'SET-' . str_pad((string)max(1, $mx + 1), 3, '0', STR_PAD_LEFT);
    $rep = [
        'id' => $rid, 'generated_at' => gmdate('Y-m-d H:i:s'),
        'tenant' => ['id' => $t['id'], 'name' => $t['name'], 'phone' => $t['phone'], 'nid' => $t['nid'], 'kind' => $t['kind']],
        'lease' => ['id' => $primary['id'], 'unit' => $urow['id'] ?? '', 'unit_name' => $urow['name'] ?? '', 'property' => $prow['name'] ?? '', 'holding' => $prow['holding'] ?? '', 'jur' => $prow['jur'] ?? '', 'start' => $primary['start'], 'end' => $primary['end'], 'rent' => (int)$primary['rent'], 'status' => $primary['status']],
        'org' => ['name' => (string)($cfg['org_name'] ?? 'KRTaker'), 'address' => (string)($cfg['org_address'] ?? '')],
        'sections' => ['rent_arrears' => $arrears, 'utility_dues' => $utils, 'damages' => $dmg],
        'deposit' => $deposit, 'deposit_applied' => $depApplied,
        'totals' => ['rent' => $rentDue, 'utility' => $utilDue, 'damages' => $dmgDue, 'total_due' => $totalDue, 'balance' => $balance, 'refund' => $refund],
        'status' => $status, 'certificate_eligible' => ($status === 'NO_DUES'),
        'move_out' => $hovo,
    ];
    if (!empty($opts['persist'])) {
        $pdo->prepare('INSERT INTO settlement_reports (id, tenant, lease, status, total_due, balance, refund, payload, generated_by, ts) VALUES (?,?,?,?,?,?,?,?,?,datetime(\'now\'))')
            ->execute([$rid, $tid, $primary['id'], $status, $totalDue, $balance, $refund, json_encode($rep, JSON_UNESCAPED_UNICODE), (string)($opts['generated_by'] ?? '')]);
    }
    return $rep;
}
function settlement_report_html($rep) {
    $t = $rep['tenant']; $l = $rep['lease']; $o = $rep['org']; $tot = $rep['totals'];
    $meta = ['NO_DUES' => ['No Dues', 'কোনো পাওনা নেই', '#059669'], 'SETTLED' => ['Settled', 'নিষ্পত্তিকৃত', '#2F80ED'], 'DUE' => ['Amount Due', 'বকেয়া আছে', '#B91C1C']];
    $st = $meta[$rep['status']] ?? $meta['DUE'];
    $rows = '';
    $sum = function ($label, $amt) { return '<tr><td style="padding:8px 12px;border-bottom:1px solid #E4EAF3;color:#475467;font-size:13.5px">' . $label . '</td><td style="padding:8px 12px;border-bottom:1px solid #E4EAF3;text-align:right;font-weight:700">৳' . number_format($amt) . '</td></tr>'; };
    foreach ($rep['sections']['rent_arrears'] as $a) $rows .= $sum('Rent arrears — ' . esc($a['invoice']) . ' (' . esc($a['month']) . ')', $a['due']);
    foreach ($rep['sections']['utility_dues'] as $u) $rows .= $sum('Utility due — ' . esc(ucfirst($u['type'])) . ' · ' . esc($u['id']) . ' (' . esc($u['month']) . ')', $u['amount']);
    foreach ($rep['sections']['damages'] as $d) $rows .= $sum('Deduction — ' . esc($d['label']), $d['amount']);
    if (!$rows) $rows .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #E4EAF3;color:#059669;font-size:13.5px">All rent & utility dues settled ✔</td><td style="padding:8px 12px;border-bottom:1px solid #E4EAF3;text-align:right;font-weight:700;color:#059669">৳0</td></tr>';
    $rows .= $sum('Advance / security deposit held', $rep['deposit']);
    if ($rep['deposit_applied'] > 0) $rows .= $sum('Deposit applied to dues', -$rep['deposit_applied']);
    $rows .= '<tr><td style="padding:10px 12px;border-top:2px solid #101828;font-weight:800;font-size:14px">' . ($rep['status'] === 'NO_DUES' ? 'Net position' : ($rep['status'] === 'DUE' ? 'Payable by tenant' : 'Refundable to tenant')) . '</td><td style="padding:10px 12px;border-top:2px solid #101828;text-align:right;font-weight:800;font-size:14px;color:' . $st[2] . '">৳' . number_format($rep['status'] === 'DUE' ? $tot['balance'] : $tot['refund']) . '</td></tr>';
    $amt = $rep['status'] === 'DUE' ? $tot['balance'] : $tot['refund'];
    $sig = function ($label, $sub) { return '<div style="flex:1;text-align:center"><div style="height:52px"></div><div style="border-top:1.5px solid #101828;font-weight:700;font-size:13px">' . $label . '</div><div style="color:#8A94A6;font-size:11.5px;margin-top:3px">' . $sub . '</div></div>'; };
    $hovoNote = '';
    foreach ($rep['move_out'] as $h) $hovoNote .= '<span style="display:inline-block;margin:3px 8px 3px 0;padding:3px 10px;border-radius:999px;background:#F0F4FA;color:#475467;font-size:12px">' . esc(ucfirst(str_replace('_', ' ', $h['kind']))) . ': ' . esc($h['status']) . '</span>';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Settlement Statement — ' . esc($t['name']) . '</title><style>body{font-family:Inter,"Hind Siliguri",Arial,sans-serif;background:#F4F6FA;margin:0;padding:32px;color:#101828}.page{max-width:780px;margin:0 auto;background:#fff;border:1px solid #E4EAF3;border-radius:16px;padding:42px}h1{font-size:21px;margin:0 0 4px}.muted{color:#8A94A6;font-size:13px}.head{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #101828;padding-bottom:14px;margin-bottom:20px}.stamp{display:inline-block;padding:6px 16px;border-radius:999px;color:#fff;background:' . $st[2] . ';font-size:15px;font-weight:800;letter-spacing:.5px}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:2px 30px;margin:14px 0}@media(max-width:640px){.grid2{grid-template-columns:1fr}}table{width:100%;border-collapse:collapse;margin-top:10px}.foot{margin-top:28px;padding-top:14px;border-top:1px solid #E4EAF3;color:#98A2B3;font-size:12px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px}.words{background:#F7F9FC;border:1px dashed #CBD5E1;border-radius:10px;padding:10px 14px;margin:12px 0;font-size:13px;color:#475467;line-height:1.7}.sigs{display:flex;gap:34px;margin-top:34px;flex-wrap:wrap}</style></head><body><div class="page">'
        . '<div class="head"><div><h1>🏠 ' . esc($o['name'] ?: 'KRTaker') . ' — Settlement Statement</h1><div class="muted">' . esc($o['address']) . '</div><div class="muted">' . esc($rep['id']) . ' · Generated ' . date('d M Y H:i', strtotime($rep['generated_at'])) . ' · by ' . esc($rep['generated_by'] ?? 'KRTaker') . '</div></div><span class="stamp">' . $st[0] . '</span></div>'
        . '<div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:10px"><div><div style="font-size:16px;font-weight:800">' . esc($t['name']) . '</div><div class="muted">' . esc($t['kind']) . ($t['nid'] ? ' · NID ' . esc($t['nid']) : '') . ' · ' . esc($t['phone']) . '</div></div><div style="text-align:right"><div style="font-size:14px;font-weight:700">' . esc($l['property']) . ' — ' . esc($l['unit_name']) . '</div><div class="muted">' . esc($l['holding']) . ', ' . esc($l['jur']) . ' · Lease ' . esc($l['id']) . ' · ৳' . number_format($l['rent']) . '/mo</div></div></div>'
        . '<div class="grid2"><div class="muted">Lease term</div><div style="font-size:13.5px;font-weight:600;text-align:right">' . esc($l['start']) . ' → ' . esc($l['end']) . ' (' . esc($l['status']) . ')</div></div>'
        . '<div style="font-size:14px;font-weight:800;margin:16px 0 2px">Statement of account</div>'
        . '<table>' . $rows . '</table>'
        . ($rep['status'] === 'NO_DUES'
            ? '<div style="margin-top:18px;padding:16px 18px;background:#ECFDF5;border:1px solid #A7F3D0;border-radius:12px"><div style="font-size:15px;font-weight:800;color:#059669">✅ No dues certificate — কোনো পাওনা নেই</div><div style="font-size:13px;color:#065F46;margin-top:6px;line-height:1.7">This certifies that ' . esc($t['name']) . ' has no outstanding rent, utility, or other charges against Lease ' . esc($l['id']) . ' as of ' . date('d M Y') . '. The advance / security deposit of ৳' . number_format($rep['deposit']) . ' is held by the landlord and is refundable on move-out per the tenancy agreement.</div></div>'
            : ($rep['status'] === 'DUE'
                ? '<div class="words"><b>' . $st[1] . ' —</b> ' . esc($t['name']) . ' must settle ৳' . number_format($tot['balance']) . ' (' . num_to_words_en($tot['balance']) . ' Taka / ' . num_to_words_bn($tot['balance']) . ' টাকা মাত্র) before move-out clearance is issued.</div>'
                : '<div class="words"><b>' . $st[1] . ' —</b> All dues were covered by the advance deposit. Refund of ৳' . number_format($tot['refund']) . ' (' . num_to_words_en($tot['refund']) . ' Taka / ' . num_to_words_bn($tot['refund']) . ' টাকা মাত্র) is due to ' . esc($t['name']) . '.</div>'))
        . ($hovoNote ? '<div style="margin-top:14px">' . $hovoNote . '</div>' : '')
        . '<div class="sigs">' . $sig('Landlord / Owner', 'Name & signature') . $sig('Tenant', esc($t['name'])) . $sig('KRTaker', 'System-generated · krtaker.com') . '</div>'
        . '<div class="foot"><span>KRTaker · krtaker.com · ' . esc($rep['id']) . '</span><span>This statement is a system-generated account of dues; the signed agreement governs.</span></div>'
        . '</div></body></html>';
}
/* ── Phase 22: NRB premium tier — remote caretaker subscriptions ── */
function PREMIUM_TIERS() {
    return [
        'care_plus' => [
            'label' => 'Caretaker Plus', 'tag' => 'Local premium',
            'blurb' => 'Your AI caretaker for one property — rent chase, utility settlement, monthly report.',
            'price' => ['monthly' => 2500, 'quarterly' => 7000, 'annual' => 25000],
            'features' => ['Rent autopilot & reminders', 'Utility bill auto-settlement', 'Monthly caretaker report', 'Repair coordination', 'KR AI chat priority'],
        ],
        'nrb_caretaker' => [
            'label' => 'NRB Remote Caretaker', 'tag' => 'For overseas owners', 'popular' => true,
            'blurb' => 'Full remote management for NRB landlords — video walkthroughs, escrow, compliance desk, repatriated rent.',
            'price' => ['monthly' => 4500, 'quarterly' => 12000, 'annual' => 45000],
            'features' => ['Everything in Caretaker Plus', 'Monthly video walkthrough', 'NRTA/NITA remittance dossier', 'TPA registration desk', 'Repair escrow with partner QC', 'Quarterly property health report', 'WhatsApp concierge line'],
        ],
    ];
}
function premium_cycle_price($tier, $cycle) {
    $t = PREMIUM_TIERS()[$tier] ?? null;
    if (!$t) return 0;
    return (int)($t['price'][$cycle] ?? $t['price']['monthly']);
}
function premium_next_invoice($cycle) {
    $add = ['monthly' => '+1 month', 'quarterly' => '+3 months', 'annual' => '+12 months'][$cycle] ?? '+1 month';
    return gmdate('Y-m', strtotime($add));
}
function premium_advance($cycle, $fromMonth) {
    $add = ['monthly' => '+1 month', 'quarterly' => '+3 months', 'annual' => '+12 months'][$cycle] ?? '+1 month';
    return gmdate('Y-m', strtotime($fromMonth . '-01 ' . $add));
}
function insurance_premium_for($base, $score) {
    $disc = 0;
    if ($score >= 80) $disc = 15;
    elseif ($score >= 70) $disc = 10;
    elseif ($score >= 60) $disc = 5;
    return max(49, (int)round($base * (100 - $disc) / 100));
}
/* Phase 30: maintenance & repairs — scope unit for a user (tenant → own lease unit; staff → any) */
function maintenance_scope($pdo, $u, $unit = '') {
    if ($u['role'] === 'tenant') {
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        $tid = (string)$st->fetchColumn();
        $st = $pdo->prepare("SELECT l.u FROM leases l WHERE l.t=? AND l.status IN ('Active','Pending Registration') ORDER BY l.start DESC LIMIT 1");
        $st->execute([$tid]);
        return ['tenant' => $tid, 'unit' => (string)$st->fetchColumn()];
    }
    return ['tenant' => '', 'unit' => $unit];
}
function maintenance_row($pdo, $id) {
    $st = $pdo->prepare("SELECT m.*, t.name AS tenant_name, u.name AS unit_name, p.name AS property_name
        FROM maintenance_requests m
        LEFT JOIN tenants t ON t.id = m.tenant
        LEFT JOIN units u ON u.id = m.unit
        LEFT JOIN properties p ON p.id = m.prop
        WHERE m.id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) { $r['cost_estimate'] = (int)$r['cost_estimate']; $r['actual_cost'] = (int)$r['actual_cost']; }
    return $r;
}
function premium_sub_rows($pdo, $email = null) {
    $sql = "SELECT s.*, p.name AS property_name FROM caretaker_subs s LEFT JOIN properties p ON p.id = s.prop";
    $args = [];
    if ($email !== null) { $sql .= ' WHERE s.user_email=?'; $args[] = $email; }
    $sql .= ' ORDER BY s.ts DESC';
    $st = $pdo->prepare($sql); $st->execute($args);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tier = PREMIUM_TIERS()[$r['tier']] ?? ['label' => $r['tier'], 'price' => ['monthly' => 0, 'quarterly' => 0, 'annual' => 0], 'features' => []];
        $rows[] = [
            'id' => $r['id'], 'user_email' => $r['user_email'], 'prop' => $r['prop'],
            'property_name' => $r['property_name'] ?? '', 'tier' => $r['tier'],
            'tier_label' => $tier['label'] ?? $r['tier'], 'price' => (int)$r['price'],
            'cycle' => $r['cycle'], 'status' => $r['status'],
            'start' => $r['start'], 'end' => $r['end'], 'next_invoice' => $r['next_invoice'],
            'features' => json_decode($r['features'], true) ?: [],
        ];
    }
    return $rows;
}
/* Phase 24: redacted account row for GDPR export (top-level — never between switch cases) */
function gdpr_account_row($pdo, $email) {
    if (!$email) return null;
    $st = $pdo->prepare('SELECT name, email, phone, role, plan, status, trial_end, last_login, created_at FROM subscribers WHERE email=?');
    $st->execute([$email]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['password_hash'] = '[redacted]'; $r['otp_hash'] = '[redacted]';
    return $r;
}
function user_profile($pdo, $u) {
    $profile = [
        'id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'],
        'role' => $u['role'], 'kind' => $u['kind'] ?? 'staff',
        'avatar' => $u['avatar'] ?? substr(preg_replace('/[^A-Za-z]/', '', $u['name'] ?? 'KR'), 0, 2),
    ];
    if (($u['kind'] ?? 'staff') === 'sub') {
        $profile['phone'] = $u['phone'] ?? '';
        $profile['org'] = $u['org'] ?? '';
        $profile['plan'] = $u['plan'] ?? 'Trial';
        $profile['trial_end'] = $u['trial_end'] ?? '';
        $profile['is_staff'] = false;
        /* link tenant row if this subscriber is a tenant */
        $st = $pdo->prepare('SELECT id, name, phone FROM tenants WHERE sub_email=?');
        $st->execute([$u['email']]);
        $tn = $st->fetch(PDO::FETCH_ASSOC);
        $profile['tenant_id'] = $tn ? $tn['id'] : '';
        $profile['linked_tenant'] = $tn ? $tn['name'] : '';
    } else {
        $profile['dept'] = $u['dept'] ?? '';
        $profile['org'] = 'KRTaker Platform';
        $profile['plan'] = 'Enterprise';
        $profile['is_staff'] = true;
    }
    $profile['settings'] = settings_get($pdo, user_key_for($u));
    return $profile;
}


/* ── Phase 36/37: vendor + remittance helpers (top-level) ── */
function partner_by_email($pdo, $email) {
    $st = $pdo->prepare('SELECT * FROM partners WHERE sub_email=?'); $st->execute([$email]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function vendor_pi_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'PI-','') AS INTEGER)) FROM partner_invoices")->fetchColumn();
    return 'PI-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function vendor_vp_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'VP-','') AS INTEGER)) FROM vendor_payouts")->fetchColumn();
    return 'VP-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}

/* ── Phase 39: maintenance SLA helpers (top-level) ── */
function sla_config_rows($pdo) {
    $defs = ['urgent' => [4, 24], 'high' => [24, 72], 'medium' => [72, 168], 'low' => [120, 240]];
    $rows = [];
    foreach ($pdo->query('SELECT * FROM sla_config') as $r) $rows[$r['priority']] = $r;
    foreach ($defs as $p => $d) {
        if (!isset($rows[$p])) $rows[$p] = ['priority' => $p, 'response_hours' => $d[0], 'resolve_hours' => $d[1], 'enabled' => 1];
    }
    return $rows;
}
function sla_state($pdo, $row) {
    $cfg = sla_config_rows($pdo);
    $c = $cfg[$row['priority']] ?? ['response_hours' => 24, 'resolve_hours' => 72, 'enabled' => 1];
    $created = strtotime($row['ts'] ?: date('Y-m-d H:i:s'));
    $now = time();
    $respDue = $created + ((int)$c['response_hours'] * 3600);
    $resvDue = $created + ((int)$c['resolve_hours'] * 3600);
    $elapsed = max(0, ($now - $created) / 3600);
    $done = in_array($row['status'], ['Resolved', 'Closed'], true);
    $status = 'on_track';
    if ($done) $status = 'done';
    elseif ($now > $resvDue) $status = 'breached';
    elseif ($now > $respDue && in_array($row['status'], ['Open', 'Assigned'], true)) $status = 'breached';
    elseif ($now > ($resvDue - (int)$c['resolve_hours'] * 900)) $status = 'at_risk';
    return ['status' => $status,
            'response_due' => gmdate('Y-m-d H:i:s', $respDue), 'resolve_due' => gmdate('Y-m-d H:i:s', $resvDue),
            'elapsed_hours' => round($elapsed, 1),
            'response_hours' => (int)$c['response_hours'], 'resolve_hours' => (int)$c['resolve_hours']];
}
function sla_summary($pdo) {
    $rows = $pdo->query('SELECT * FROM maintenance_requests ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    $out = ['on_track' => 0, 'at_risk' => 0, 'breached' => 0, 'done' => 0, 'items' => []];
    foreach ($rows as $r) {
        $s = sla_state($pdo, $r);
        $out[$s['status']]++;
        $out['items'][] = array_merge(['id' => $r['id'], 'title' => $r['title'], 'status' => $r['status'],
            'priority' => $r['priority'], 'vendor' => $r['vendor'], 'ts' => $r['ts'], 'unit' => $r['unit']], $s);
    }
    return $out;
}
function vendor_rating_upsert($pdo, $partner, $job, $rating, $comment, $by) {
    $st = $pdo->prepare('SELECT id FROM vendor_ratings WHERE partner=? AND job=?');
    $st->execute([$partner, $job]);
    $ex = $st->fetchColumn();
    if ($ex) {
        $pdo->prepare("UPDATE vendor_ratings SET rating=?, comment=?, rated_by=?, ts=datetime('now') WHERE id=?")
            ->execute([$rating, $comment, $by, $ex]);
        $id = $ex;
    } else {
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'VR-','') AS INTEGER)) FROM vendor_ratings")->fetchColumn();
        $id = 'VR-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO vendor_ratings (id, partner, job, rating, comment, rated_by) VALUES (?,?,?,?,?,?)')
            ->execute([$id, $partner, $job, $rating, $comment, $by]);
    }
    /* rolling average onto partners.rating */
    $st = $pdo->prepare('SELECT COALESCE(AVG(rating),0), COUNT(*) FROM vendor_ratings WHERE partner=?');
    $st->execute([$partner]);
    $avg = $st->fetch(PDO::FETCH_NUM);
    $pdo->prepare('UPDATE partners SET rating=? WHERE id=?')->execute([round((float)$avg[0], 1), $partner]);
    return $id;
}
function remit_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'RM-','') AS INTEGER)) FROM remittances")->fetchColumn();
    return 'RM-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
/* ---------- Phase 43: Trust Engine v2 — NID verification + Thana Tenant Information Form ---------- */
/* Bangladesh NID is 17 digits; the 17th is a check digit computed with weights 2..9 over the first 16.
   (Legacy 10-digit NIDs are numeric-only, no checksum.) Returns ['ok'=>bool,'len'=>int,'reason'=>str]. */
function nid_validate($nid) {
    $n = preg_replace('/[^0-9]/', '', (string)$nid);
    $len = strlen($n);
    if ($len !== 17 && $len !== 10) return ['ok' => false, 'len' => $len, 'reason' => 'NID must be 10 or 17 digits.'];
    if ($len === 10) return ['ok' => true, 'len' => 10, 'reason' => 'Legacy 10-digit NID (no check digit).'];
    $w = [2, 3, 4, 5, 6, 7, 8, 9, 2, 3, 4, 5, 6, 7, 8, 9];
    $sum = 0;
    for ($i = 0; $i < 16; $i++) $sum += (int)$n[$i] * $w[$i];
    $check = (11 - ($sum % 11)) % 11;
    if ($check === 10) $check = 0;
    $ok = $check === (int)$n[16];
    return ['ok' => $ok, 'len' => 17, 'reason' => $ok ? 'Check digit valid.' : 'Check digit mismatch (bad NID).'];
}
function nv_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'NV-','') AS INTEGER)) FROM nid_verifications")->fetchColumn();
    return 'NV-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function tf_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'TF-','') AS INTEGER)) FROM thana_forms")->fetchColumn();
    return 'TF-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function trust_tenant_id($pdo, $u) {
    if ($u['role'] === 'tenant') {
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        return (string)$st->fetchColumn();
    }
    return '';
}
function nv_rows($pdo, $tenant = '') {
    $sql = "SELECT v.*, t.name AS tenant_name, t.phone AS tenant_phone, t.kind AS tenant_kind
        FROM nid_verifications v LEFT JOIN tenants t ON t.id = v.tenant";
    $args = [];
    if ($tenant !== '') { $sql .= ' WHERE v.tenant=?'; $args[] = $tenant; }
    $sql .= ' ORDER BY v.ts DESC';
    $st = $pdo->prepare($sql); $st->execute($args);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['checksum_ok'] = (int)$r['checksum_ok']; $r['age_ok'] = (int)$r['age_ok'];
        $rows[] = $r;
    }
    return $rows;
}
function tf_rows($pdo, $tenant = '') {
    $sql = "SELECT f.*, t.name AS tenant_name, t.phone AS tenant_phone,
        u.name AS unit_name, p.name AS property_name
        FROM thana_forms f
        LEFT JOIN tenants t ON t.id = f.tenant
        LEFT JOIN units u ON u.id = f.unit
        LEFT JOIN properties p ON p.id = f.prop";
    $args = [];
    if ($tenant !== '') { $sql .= ' WHERE f.tenant=?'; $args[] = $tenant; }
    $sql .= ' ORDER BY f.ts DESC';
    $st = $pdo->prepare($sql); $st->execute($args);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['payload'] = json_decode($r['payload'], true) ?: [];
        $rows[] = $r;
    }
    return $rows;
}
function tif_default_payload($pdo, $tenant) {
    $t = null;
    $st = $pdo->prepare('SELECT * FROM tenants WHERE id=?'); $st->execute([$tenant]); $t = $st->fetch(PDO::FETCH_ASSOC);
    $lease = null;
    if ($t) {
        $st = $pdo->prepare("SELECT l.*, u.name AS unit_name, p.name AS property_name FROM leases l
            LEFT JOIN units u ON u.id = l.u LEFT JOIN properties p ON p.id = u.p
            WHERE l.t=? AND l.status IN ('Active','Pending Registration') ORDER BY l.start DESC LIMIT 1");
        $st->execute([$tenant]); $lease = $st->fetch(PDO::FETCH_ASSOC);
    }
    $unit = $lease['u'] ?? ''; $prop = $lease['p'] ?? '';
    $nv = null;
    $st = $pdo->prepare("SELECT * FROM nid_verifications WHERE tenant=? ORDER BY ts DESC LIMIT 1"); $st->execute([$tenant]); $nv = $st->fetch(PDO::FETCH_ASSOC);
    return [
        'unit' => $unit, 'prop' => $prop,
        'name' => $t['name'] ?? '', 'nid' => $nv['nid'] ?? $t['nid'] ?? '',
        'dob' => $nv['dob'] ?? '', 'phone' => $t['phone'] ?? '',
        'father' => '', 'mother' => '', 'profession' => '', 'employer' => '',
        'present_flat' => '', 'present_road' => '', 'present_area' => '',
        'permanent_address' => '', 'spouse' => '', 'spouse_phone' => '',
        'family_count' => '1', 'ref1_name' => '', 'ref1_phone' => '', 'ref1_address' => '',
        'ref2_name' => '', 'ref2_phone' => '', 'ref2_address' => '',
        'landlord_name' => '', 'landlord_nid' => '', 'landlord_phone' => '',
        'move_in' => $lease ? substr($lease['start'], 0, 10) : '', 'lease_term' => '',
        'vehicle' => '', 'remarks' => '',
    ];
}
function tif_save_payload($pdo, $id, $in) {
    $keys = ['unit','prop','name','nid','dob','phone','father','mother','profession','employer',
        'present_flat','present_road','present_area','permanent_address','spouse','spouse_phone',
        'family_count','ref1_name','ref1_phone','ref1_address','ref2_name','ref2_phone','ref2_address',
        'landlord_name','landlord_nid','landlord_phone','move_in','lease_term','vehicle','remarks'];
    $cur = [];
    $st = $pdo->prepare('SELECT * FROM thana_forms WHERE id=?'); $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) $cur = json_decode($row['payload'], true) ?: [];
    foreach ($keys as $k) {
        if (isset($in[$k])) $cur[$k] = trim((string)$in[$k]);
    }
    if (isset($in['thana'])) $cur['thana'] = trim((string)$in['thana']);
    if (isset($in['district'])) $cur['district'] = trim((string)$in['district']);
    $pdo->prepare('UPDATE thana_forms SET payload=?, thana=?, district=? WHERE id=?')
        ->execute([json_encode($cur, JSON_UNESCAPED_UNICODE), $cur['thana'] ?? '', $cur['district'] ?? '', $id]);
    return $cur;
}

/* ═══ Phase 44: Legal Engine v2 — notices, audit, TDS, disputes ═══ */
function tenantById($id) {
    $st = db()->prepare('SELECT * FROM tenants WHERE id=?'); $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function unitById($id) {
    $st = db()->prepare('SELECT * FROM units WHERE id=?'); $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
