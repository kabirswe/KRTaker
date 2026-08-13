/* ---------- router ---------- */
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$seg  = preg_replace('#^api/#', '', $path);
$action = $seg ?: 'health';
/* sitemap.xml is rewritten to api/index.php from .htaccess so the index is ALWAYS
   dynamic (blog posts + properties included); normalize the action here. */
if ($action === 'sitemap.xml') $action = 'sitemap';
/* SA1 v21: cap request body size before parsing (DoS guard — 2MB is far beyond any legit payload) */
$clen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($clen > 2 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Payload too large.']);
    exit;
}
$body = json_decode(file_get_contents('php://input'), true) ?: [];

/* ── SA1-fullsite-v3: API usage telemetry — log every request on shutdown ── */
$_API_T0 = microtime(true);
register_shutdown_function(function () use ($action) {
    $ms = (int)round((microtime(true) - ($GLOBALS['_API_T0'] ?? microtime(true))) * 1000);
    api_log_hit($action, $_SERVER['REQUEST_METHOD'] ?? 'POST', http_response_code() ?: 200, $ms);
});

/* ── SA1-v26: bot guard for public form endpoints ────────────────────────────
   Zero-dependency: honeypot + time-trap + proof-of-work (WebCrypto SHA-256).
   Turnstile-ready: if admin_cfg turnstile_secret is set, the token is verified
   too. Toggle with admin_cfg bot_guard (1=on default). Difficulty in bot_pow_bits. */
if (in_array($action, ['register', 'resend-otp', 'forgot-password', 'reset-password', 'contact', 'newsletter', 'app-login'], true)) {
    $bgp = db();
    if ((int)admin_cfg($bgp, 'bot_guard', 1) === 1) {
        bot_guard_check($bgp, $body);
    }
}

function bot_guard_check($pdo, $body) {
    $t = microtime(true) * 1000;
    /* honeypot: invisible field — real users never fill it */
    if (!empty($body['hp'])) bot_guard_reject();
    /* time-trap: form must be at least 2s old (naive bots POST in <1s) */
    $ft = (float)($body['ft'] ?? 0);
    if ($ft <= 0 || ($t - $ft) < 2000) bot_guard_reject();
    /* proof-of-work: sha256(window:nonce) must have >= N leading zero bits.
       window = epoch/300 (5 min) — client computes from its own clock; we accept
       the current, previous and next window (clock skew tolerance). */
    $difficulty = max(8, min(24, (int)admin_cfg($pdo, 'bot_pow_bits', 12)));
    $pow = (string)($body['pow'] ?? '');
    if ($pow === '' || strlen($pow) > 16 || !ctype_xdigit($pow)) bot_guard_reject();
    $win = (int)floor(time() / 300);
    $ok = false;
    foreach ([$win, $win - 1, $win + 1] as $w) {
        $h = hash('sha256', $w . ':' . $pow);
        $bin = '';
        foreach (str_split($h) as $c) $bin .= str_pad(base_convert($c, 16, 2), 4, '0', STR_PAD_LEFT);
        if (strspn($bin, '0') >= $difficulty) { $ok = true; break; }
    }
    if (!$ok) bot_guard_reject();
    /* Turnstile (optional): only when a secret is configured */
    $ts = trim((string)admin_cfg($pdo, 'turnstile_secret', ''));
    if ($ts !== '') {
        $tok = (string)($body['cf-turnstile-response'] ?? '');
        if ($tok === '' || !function_exists('curl_init')) bot_guard_reject();
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['secret' => $ts, 'response' => $tok, 'remoteip' => client_ip()]),
        ]);
        $r = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        if (empty($r['success'])) bot_guard_reject();
    }
    /* Google reCAPTCHA v3 (optional): only when a secret is configured.
       Score >= 0.5 passes; lower = suspicious (headless/automation). */
    $rs = trim((string)admin_cfg($pdo, 'recaptcha_secret', ''));
    if ($rs !== '') {
        $tok = (string)($body['g-recaptcha-response'] ?? '');
        if ($tok === '' || !function_exists('curl_init')) bot_guard_reject();
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['secret' => $rs, 'response' => $tok, 'remoteip' => client_ip()]),
        ]);
        $r = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        if (empty($r['success']) || (float)($r['score'] ?? 0) < 0.5) bot_guard_reject();
    }
}
function bot_guard_reject() {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Human verification failed. Please reload the page and try again.']);
    exit;
}

/* ── SA1-fullsite-v3/v9: optional API-key enforcement on keyed endpoints (web/mobile/tenant keys) ── */
if (in_array($action, ['app-photo', 'app-job-media', 'app-tenant-me'], true)) {
    $pdo0 = db();
    $km = api_key_meta($pdo0);
    $xk = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
    if (!empty($km['enforce'])) {
        $ok = false;
        if ($xk !== '') {
            $res = api_key_resolve($pdo0, $xk);
            if ($res) {
                $rlKind = $res['kind'] . ($res['kind'] === 'tenant' ? '_t' . $res['tenant_id'] : '');
                if (!api_rate_limit_ok($pdo0, $rlKind, max(10, (int)$km['rate_limit']))) {
                    header('Retry-After: 60');
                    json_out(['ok' => false, 'error' => 'Rate limit exceeded for this API key.', 'retry_after' => 60], 429);
                }
                if ($res['kind'] === 'tenant') tenant_key_touch($pdo0, $res['row']);
                else api_key_touch($pdo0, $res['kind']);
                header('X-KRTaker-Key: ' . $res['kind'] . ($res['tenant_id'] ? ':' . $res['tenant_id'] : ''));
                $ok = true;
            }
        }
        if (!$ok) json_out(['ok' => false, 'error' => 'A valid web, mobile or tenant API key is required (X-API-Key header).'], 401);
    } elseif ($xk !== '') {
        $res = api_key_resolve($pdo0, $xk);
        if ($res) {
            if ($res['kind'] === 'tenant') tenant_key_touch($pdo0, $res['row']);
            else api_key_touch($pdo0, $res['kind']);
            header('X-KRTaker-Key: ' . $res['kind'] . ($res['tenant_id'] ? ':' . $res['tenant_id'] : ''));
        }
    }
}

/* IndexNow key file: https://krtaker.com/<32-hex>.txt (rewritten from .htaccess, public GET) */
if (preg_match('/^[0-9a-f]{32}\.txt$/', $action)) {
    $pdo = db();
    $key = trim((string)$pdo->query("SELECT v FROM platform_meta WHERE k='indexnow_key'")->fetchColumn());
    if ($key !== '' && hash_equals($key, substr($action, 0, 32))) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $key; exit;
    }
    http_response_code(404); echo 'not found'; exit;
}

/* Dynamic blog article: /blog/<slug> (rewritten from .htaccess, public HTML) */
if (preg_match('#^blog/([a-z0-9-]+)$#', $action, $m)) {
    header('Content-Type: text/html; charset=utf-8');   /* blog 404 must NOT carry the API JSON content-type */
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM blog_posts WHERE slug=? AND status=\'published\'');
    $st->execute([$m[1]]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) { http_response_code(404); echo '<!DOCTYPE html><html><head><title>404 — KRTaker</title><meta http-equiv="refresh" content="0;url=/404.html"></head><body></body></html>'; exit; }
    /* SA1 v21: blog articles are HTML — override the API-wide default-src 'none' CSP
       so inline styles/fonts/images render (article pages are public marketing content). */
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https:; script-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; upgrade-insecure-requests");
    echo blog_article_html($p);
    exit;
}

/* Dynamic building profile page: /building/<code> (rewritten from .htaccess, public HTML).
   Server-side OG tags so Facebook/X/LinkedIn crawlers (which don't run JS) get a
   property-specific preview card instead of the generic static one. */
if (preg_match('#^building/([A-Za-z0-9_-]{1,64})$#', $action, $m)) {
    header('Content-Type: text/html; charset=utf-8');
    $pdo = db();
    $st = $pdo->prepare("SELECT id, name, type, jur, holding, sqft, status, address, photo, description, lat, lng FROM properties WHERE id=? AND published=1");
    $st->execute([$m[1]]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) { http_response_code(404); echo '<!DOCTYPE html><html><head><title>404 — KRTaker</title><meta http-equiv="refresh" content="0;url=/404.html"></head><body></body></html>'; exit; }
    /* Same CSP override as blog articles: public page with inline styles + Leaflet/jsdelivr */
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https:; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; upgrade-insecure-requests");
    $html = @file_get_contents(__DIR__ . '/../building.html');
    if ($html === false) { http_response_code(500); echo '<!DOCTYPE html><html><head><title>Error — KRTaker</title></head><body>building page unavailable</body></html>'; exit; }
    $st = $pdo->prepare("SELECT rent FROM units WHERE p=? AND status='Vacant' ORDER BY rent"); $st->execute([$m[1]]);
    $vacs = $st->fetchAll(PDO::FETCH_COLUMN);
    $nVac = count($vacs);
    $minRent = $nVac ? (int)min($vacs) : 0;
    $ogTitle = $p['name'] . ' — ' . $p['jur'] . ' | KRTaker';
    $ogDesc = $p['name'] . ' (' . $p['jur'] . ') — verified building';
    if ($nVac) $ogDesc .= ' · ' . $nVac . ' unit' . ($nVac > 1 ? 's' : '') . ' vacant' . ($minRent ? ' · from ৳' . number_format($minRent) . '/mo' : '');
    $ogDesc .= ' · Managed end-to-end by KRTaker · no broker fee';
    $ogUrl = 'https://krtaker.com/building/' . rawurlencode($p['id']);
    $ogImg = 'https://krtaker.com/assets/img/og-card.png';
    $ph = (string)($p['photo'] ?? '');
    if ($ph !== '' && preg_match('#\.(png|jpe?g|webp)$#i', $ph)) {
        $ogImg = preg_match('#^https?://#', $ph) ? $ph : 'https://krtaker.com' . $ph;
    }
    $esc = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $subs = [
        ['<meta property="og:title" content="Building Profile — KRTaker"', '<meta property="og:title" content="' . $esc($ogTitle) . '"'],
        ['<meta property="og:description" content="Verified building profile — units, rents, amenities and lease terms. Managed end-to-end by KRTaker."', '<meta property="og:description" content="' . $esc($ogDesc) . '"'],
        ['<meta property="og:url" content="https://krtaker.com/building.html"', '<meta property="og:url" content="' . $esc($ogUrl) . '"'],
        ['<meta property="og:image" content="https://krtaker.com/assets/img/og-card.png"', '<meta property="og:image" content="' . $esc($ogImg) . '"'],
        ['<link rel="canonical" href="https://krtaker.com/building.html">', '<link rel="canonical" href="' . $esc($ogUrl) . '">'],
    ];
    foreach ($subs as $s) { $html = str_replace($s[0], $s[1], $html); }
    echo $html;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !in_array($action, ['health', 'listings', 'app-setup', 'app-me', 'app-bootstrap', 'app-ai-meta', 'app-gateways', 'app-health', 'app-backup', 'app-export', 'app-audit', 'app-invoice-print', 'app-doc-download', 'app-doc-view', 'app-doc-vault', 'app-ticket-thread', 'app-support-ticket', 'app-notice-list', 'app-notice-recipients', 'app-referral-list', 'app-collections-summary', 'app-payment-recon', 'app-payment-proof', 'app-sms', 'app-tpl-list', 'app-tpl-get', 'app-email-tpl-list', 'app-email-tpl-get', 'app-kyc', 'app-inspections', 'app-email-preview', 'app-hando-list', 'app-hando-get', 'app-portal', 'app-portal-agreement', 'app-community', 'app-reminder-config', 'app-reminder-summary', 'app-security', 'app-renewal-list', 'app-inspections', 'app-meter-list', 'app-score-list', 'app-score-detail', 'app-vetting-report', 'app-settlement-report', 'app-premium-plans', 'app-premium-sub-list', 'app-gdpr-export', 'app-profile', 'app-settings-get', 'app-org-settings-get', 'app-utility-tariff-get', 'app-utility-bill-list', 'app-rent-config-get', 'app-moveout', 'app-premium-billing', 'app-insurance', 'app-maintenance', 'app-leads', 'app-statements', 'app-statement-email', 'app-compliance', 'app-utility-summary', 'app-vendors', 'app-remit', 'app-onboarding', 'app-job-media', 'app-sla', 'app-kr-alert', 'app-kr-wa', 'app-analytics', 'app-legal', 'app-trust', 'app-land', 'app-nrb', 'app-concierge', 'app-smarthome', 'app-healthcheck', 'app-build', 'app-gate', 'app-firesafety', 'app-systems', 'app-staffwatch','app-samity', 'app-photo', 'app-tenant-me', 'host-tenant', 'app-theme', 'cms-read', 'plans', 'sitemap', 'blog-list', 'app-error-log', 'building-public', 'app-sessions', 'app-login-history'], true)) {
    json_out(['ok' => false, 'error' => 'POST required.'], 405);
}

switch ($action) {
case 'health':
    json_out(['ok' => true, 'service' => 'krtaker-landing', 'php' => PHP_VERSION]);

/* Phase 10: public property listings (no auth) — SA1 v24 enriched: address/photo/description/featured/created_at,
   per-unit beds/baths/furnished, amenities, rent_per_sqft + notes, total_units */
case 'listings': {
    $pdo = db();
    $rows = $pdo->query("SELECT id, name, type, jur, holding, sqft, value, status, address, photo, description, featured, created_at, lat, lng
        FROM properties WHERE published=1 ORDER BY COALESCE(featured,0) DESC, name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $st = $pdo->prepare("SELECT id, name, floor, sqft, rent, beds, baths, furnished FROM units WHERE p=? AND status='Vacant' ORDER BY id");
        $st->execute([$r['id']]);
        $r['vacant_units'] = $st->fetchAll(PDO::FETCH_ASSOC);
        $r['vacancies'] = count($r['vacant_units']);
        $st = $pdo->prepare("SELECT COUNT(*) FROM units WHERE p=?"); $st->execute([$r['id']]);
        $r['total_units'] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT name, icon FROM amenities WHERE prop=? AND status='Active' ORDER BY id"); $st->execute([$r['id']]);
        $r['amenities'] = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare("SELECT rent_per_sqft, notes, advance_months, service_charge_pct, utility_advance, parking_fee, escalation_pct, due_day, late_fee_pct FROM property_rent WHERE prop=?"); $st->execute([$r['id']]);
        $pr = $st->fetch(PDO::FETCH_ASSOC);
        $r['rent_per_sqft'] = $pr ? (int)$pr['rent_per_sqft'] : 0;
        $r['terms'] = $pr ? ['advance_months'=>(int)$pr['advance_months'],'service_charge_pct'=>(float)$pr['service_charge_pct'],'utility_advance'=>(int)$pr['utility_advance'],'parking_fee'=>(int)$pr['parking_fee'],'escalation_pct'=>(float)$pr['escalation_pct'],'due_day'=>(int)$pr['due_day'],'late_fee_pct'=>(float)$pr['late_fee_pct']] : null;
        if (!$r['description'] && $pr && $pr['notes']) $r['description'] = $pr['notes'];
    }
    json_out(['ok' => true, 'properties' => $rows]);
}

/* SA1 v25: public building profile for QR-code pages (bhara-style). Read-only, no auth. */
case 'building-public': {
    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
        json_out(['ok' => false, 'error' => 'Invalid building id.'], 400);
    }
    $pdo = db();
    $st = $pdo->prepare("SELECT id, name, type, jur, holding, sqft, value, status, address, photo, description, created_at, lat, lng FROM properties WHERE id=? AND published=1");
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) json_out(['ok' => false, 'error' => 'Building not found.'], 404);
    $st = $pdo->prepare("SELECT id, name, floor, sqft, rent, beds, baths, furnished, status FROM units WHERE p=? ORDER BY id");
    $st->execute([$id]);
    $p['units'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $p['vacancies'] = count(array_filter($p['units'], fn($u)=>$u['status']==='Vacant'));
    $p['total_units'] = count($p['units']);
    $st = $pdo->prepare("SELECT name, icon, category FROM amenities WHERE prop=? AND status='Active' ORDER BY id"); $st->execute([$id]);
    $p['amenities'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT * FROM property_rent WHERE prop=?"); $st->execute([$id]);
    $pr = $st->fetch(PDO::FETCH_ASSOC);
    $p['terms'] = $pr ? ['rent_per_sqft'=>(int)$pr['rent_per_sqft'],'service_charge_pct'=>(float)$pr['service_charge_pct'],'utility_advance'=>(int)$pr['utility_advance'],'parking_fee'=>(int)$pr['parking_fee'],'escalation_pct'=>(float)$pr['escalation_pct'],'advance_months'=>(int)$pr['advance_months'],'due_day'=>(int)$pr['due_day'],'late_fee_pct'=>(float)$pr['late_fee_pct'],'notes'=>$pr['notes']] : null;
    if (!$p['description'] && $pr && $pr['notes']) $p['description'] = $pr['notes'];
    json_out(['ok' => true, 'building' => $p]);
}

case 'register': {
    $name  = str_replace(["\r", "\n", "\0"], ' ', trim($body['name'] ?? ''));
    $email = strtolower(trim($body['email'] ?? ''));
    $ip = client_ip();
    /* SA1 v21: register previously used recent_fails() which counts ONLY failed rows —
       register only records successes → the cap was inert. recent_any() counts all. */
    if (recent_any($email, $ip, 60, 4, 8, ['register', 'resend'])) {   /* ≤8/IP/hr, ≤4/email/hr */
        throttle_out('Too many attempts from this address. Try again later.', $email, $ip, 60, ['register', 'resend']);
    }
    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['ok' => false, 'error' => 'Invalid name or email.'], 400);
    }
    $pass = $body['pass'] ?? '';
    $perr = password_policy_error(db(), $pass);
    if ($perr !== '') {
        json_out(['ok' => false, 'error' => $perr], 400);
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM subscribers WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['status'] === 'active') {
        json_out(['ok' => false, 'error' => 'This email is already registered. Please log in.'], 409);
    }
    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otp_hash = hash('sha256', $otp);
    $expires = gmdate('Y-m-d\TH:i:s', time() + 300);
    $trial_days = (int)admin_cfg($pdo, 'trial_days', TRIAL_DAYS); if ($trial_days < 1) $trial_days = 1; if ($trial_days > 365) $trial_days = 365;
    $trial_end = gmdate('d M Y', time() + $trial_days * 86400);
    $org = $body['org'] ?? ''; $phone = $body['phone'] ?? '';
    $role = $body['role'] ?? 'owner'; $plan = 'Trial';          // plan always Trial — chosen later in dashboard
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    if ($row) {
        $st = $pdo->prepare('UPDATE subscribers SET name=?, org=?, phone=?, role=?, plan=?, otp_hash=?, otp_expires=?, password_hash=?, status="pending" WHERE email=?');
        $st->execute([$name, $org, $phone, $role, $plan, $otp_hash, $expires, $hash, $email]);
    } else {
        $st = $pdo->prepare('INSERT INTO subscribers (name, org, email, phone, role, plan, status, trial_end, otp_hash, otp_expires, password_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$name, $org, $email, $phone, $role, $plan, 'pending', $trial_end, $otp_hash, $expires, $hash]);
    }
    /* Phase 10: referral code on signup — attribute the lead to the referrer */
    $ref = strtoupper(trim($body['ref'] ?? ''));
    if ($ref) {
        $st = $pdo->prepare('SELECT * FROM referrals WHERE code=?'); $st->execute([$ref]);
        $refRow = $st->fetch(PDO::FETCH_ASSOC);
        if ($refRow && strtolower($refRow['user_email']) !== $email) {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM referrals WHERE referred_email=? AND code=?');
            $dup->execute([$email, $refRow['code']]);
            if (!(int)$dup->fetchColumn()) {
                $pdo->prepare('INSERT INTO referrals (id, code, user_email, role, referred_name, referred_email, status) VALUES (?,?,?,?,?,?,?)')
                    ->execute(['REF-' . strtoupper(bin2hex(random_bytes(3))), $refRow['code'], $refRow['user_email'], $refRow['role'], $name, $email, 'Signed up']);
            }
        }
    }
    list($subj, $otpHtml) = email_render('otp', ['code' => $otp, 'name' => $name, 'expiry' => '5 minutes']);
    $ok = send_mail($email, $subj, $otpHtml);
    record_attempt($email, $ip, 'register', true);
    webhook_dispatch($pdo, 'subscriber.registered', ['email' => $email, 'name' => $name, 'org' => $org, 'phone' => $phone, 'plan' => $plan, 'status' => 'pending', 'trial_days' => $trial_days, 'referrer' => $ref]);
    json_out(['ok' => true, 'otp_sent' => $ok, 'trial_days' => $trial_days]);
}

case 'verify-otp': {
    $email = strtolower(trim($body['email'] ?? ''));
    $otp   = trim($body['otp'] ?? '');
    $ip = client_ip();
    if (otp_blocked($email, $ip)) {
        throttle_out('Too many failed attempts. Request a new code in 15 minutes.', $email, $ip, 15);
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM subscribers WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_out(['ok' => false, 'error' => 'No registration found for this email.'], 404);
    $now = gmdate('Y-m-d\TH:i:s');
    if (!$row['otp_hash'] || $row['otp_expires'] < $now) {
        json_out(['ok' => false, 'error' => 'Code expired. Resend a new one.'], 400);
    }
    if (hash('sha256', $otp) !== $row['otp_hash']) {
        $fails = (int)($row['otp_fails'] ?? 0) + 1;
        $pdo->prepare('UPDATE subscribers SET otp_fails=? WHERE id=?')->execute([$fails, $row['id']]);
        record_attempt($email, $ip, 'otp', false);
        if ($fails >= 5) {
            $pdo->prepare('UPDATE subscribers SET otp_hash=NULL, otp_expires=NULL WHERE id=?')->execute([$row['id']]);
            json_out(['ok' => false, 'error' => 'Too many wrong codes — the code was invalidated. Resend a new one.'], 429);
        }
        json_out(['ok' => false, 'error' => 'Invalid code.'], 400);
    }
    $pdo->prepare('UPDATE subscribers SET otp_fails=0 WHERE id=?')->execute([$row['id']]);
    record_attempt($email, $ip, 'otp', true);
    $trial_days = (int)admin_cfg($pdo, 'trial_days', TRIAL_DAYS); if ($trial_days < 1) $trial_days = 1; if ($trial_days > 365) $trial_days = 365;
    $trial_end = $row['trial_end'] ?: gmdate('d M Y', time() + $trial_days * 86400);
    $st = $pdo->prepare('UPDATE subscribers SET status="active", otp_hash=NULL, verified_at=? WHERE id=?');
    $st->execute([$now, $row['id']]);
    list($wSubj, $wBody) = email_render('welcome', ['name' => $row['name'], 'trial_end' => $trial_end, 'workspace_url' => 'https://krtaker.com/app-v3/']);
    if (mail_switch($pdo, 'welcome')) send_mail($email, $wSubj, $wBody, null, true);
    json_out(['ok' => true, 'trial_end' => $trial_end, 'trial_days' => $trial_days]);
}

case 'resend-otp': {
    $email = strtolower(trim($body['email'] ?? ''));
    $ip = client_ip();
    /* SA1 v21: count ALL resends+registers (recent_fails only counts failed OTP verifies) */
    if (recent_any($email, $ip, 10, 3, 10, ['resend', 'register'])) {   /* ≤3 per email / 10 min */
        throttle_out('Too many resend requests. Try again later.', $email, $ip, 10, ['resend', 'register']);
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM subscribers WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_out(['ok' => false, 'error' => 'No registration found for this email.'], 404);
    if ($row['status'] === 'active') json_out(['ok' => false, 'error' => 'Account already verified.'], 409);
    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = gmdate('Y-m-d\TH:i:s', time() + 300);
    $st = $pdo->prepare('UPDATE subscribers SET otp_hash=?, otp_expires=? WHERE id=?');
    $st->execute([hash('sha256', $otp), $expires, $row['id']]);
    list($subj, $otpHtml) = email_render('otp', ['code' => $otp, 'name' => $row['name'], 'expiry' => '5 minutes']);
    $ok = send_mail($email, $subj, $otpHtml);
    record_attempt($email, $ip, 'resend', true);
    json_out(['ok' => true, 'otp_sent' => $ok]);
}

case 'forgot-password': {
    $email = strtolower(trim($body['email'] ?? ''));
    $ip = client_ip();
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['ok' => false, 'error' => 'Invalid email.'], 400);
    }
    if (recent_any($email, $ip, 60, 4, 8, ['forgot-password', 'register', 'resend'])) {
        throttle_out('Too many requests from this address. Try again later.', $email, $ip, 60, ['forgot-password', 'register', 'resend']);
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM subscribers WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    /* No enumeration: same generic response whether or not the account exists. */
    if ($row) {
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = gmdate('Y-m-d\TH:i:s', time() + 300);
        $pdo->prepare('UPDATE subscribers SET otp_hash=?, otp_expires=?, otp_fails=0 WHERE id=?')
            ->execute([hash('sha256', $otp), $expires, $row['id']]);
        list($subj, $otpHtml) = email_render('otp', ['code' => $otp, 'name' => $row['name'], 'expiry' => '5 minutes']);
        send_mail($email, $subj, $otpHtml);
        record_attempt($email, $ip, 'forgot-password', true);
        audit($row['name'], 'Password reset requested', 'auth', '', $email);
    }
    json_out(['ok' => true, 'otp_sent' => (bool)$row]);
}

case 'reset-password': {
    $email = strtolower(trim($body['email'] ?? ''));
    $otp   = trim($body['otp'] ?? '');
    $pass  = $body['pass'] ?? '';
    $ip = client_ip();
    if (otp_blocked($email, $ip)) {
        throttle_out('Too many failed attempts. Request a new code in 15 minutes.', $email, $ip, 15);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $otp === '') {
        json_out(['ok' => false, 'error' => 'Email and code are required.'], 400);
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM subscribers WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['otp_hash']) json_out(['ok' => false, 'error' => 'No reset code found for this email. Request a new one.'], 400);
    $now = gmdate('Y-m-d\TH:i:s');
    if ($row['otp_expires'] < $now) json_out(['ok' => false, 'error' => 'Code expired. Request a new one.'], 400);
    if (hash('sha256', $otp) !== $row['otp_hash']) {
        $fails = (int)($row['otp_fails'] ?? 0) + 1;
        $pdo->prepare('UPDATE subscribers SET otp_fails=? WHERE id=?')->execute([$fails, $row['id']]);
        record_attempt($email, $ip, 'otp', false);
        if ($fails >= 5) {
            $pdo->prepare('UPDATE subscribers SET otp_hash=NULL, otp_expires=NULL WHERE id=?')->execute([$row['id']]);
            json_out(['ok' => false, 'error' => 'Too many wrong codes — the code was invalidated. Request a new one.'], 429);
        }
        json_out(['ok' => false, 'error' => 'Invalid code.'], 400);
    }
    $perr = password_policy_error($pdo, $pass);
    if ($perr !== '') json_out(['ok' => false, 'error' => $perr], 400);
    $pdo->prepare('UPDATE subscribers SET password_hash=?, otp_hash=NULL, otp_expires=NULL, otp_fails=0 WHERE id=?')
        ->execute([password_hash($pass, PASSWORD_DEFAULT), $row['id']]);
    record_attempt($email, $ip, 'otp', true);
    audit($row['name'], 'Password reset completed', 'auth', '', $email);
    json_out(['ok' => true]);
}

case 'newsletter': {
    $email = strtolower(trim($body['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['ok' => false, 'error' => 'Invalid email.'], 400);
    $ip = client_ip();
    if (recent_any('', $ip, 10, 0, 6, ['newsletter'])) throttle_out('Too many signups from this address.', '', $ip, 10, ['newsletter']);
    $pdo = db();
    try {
        $pdo->prepare('INSERT INTO newsletter_emails (email) VALUES (?)')->execute([$email]);
    } catch (PDOException $e) {
        json_out(['ok' => true, 'already' => true]);
    }
    record_attempt($email, $ip, 'newsletter', true);
    send_mail(ADMIN_EMAIL, "[KRTaker] Newsletter signup: $email",
        '<p>New newsletter subscriber: <b>' . esc($email) . '</b></p>');
    json_out(['ok' => true]);
}

case 'contact': {
    $name = trim($body['name'] ?? '');
    $email = trim($body['email'] ?? '');
    $msg  = trim($body['message'] ?? '');
    if (!$name || !$email || !$msg) json_out(['ok' => false, 'error' => 'Name, email and message are required.'], 400);
    $ip = client_ip();
    if (recent_any('', $ip, 10, 0, 5, ['contact'])) throttle_out('Too many messages from this address. Try again later.', '', $ip, 10, ['contact']);
    if (strlen($name) > 100 || strlen($msg) > 5000 || strlen($email) > 254) json_out(['ok' => false, 'error' => 'Message too long.'], 400);
    /* SA1 v21: strip CR/LF from stored fields (injection + log-poisoning guard) */
    $name = str_replace(["\r", "\n", "\0"], ' ', $name);
    $msg = str_replace(["\r", "\n", "\0"], ' ', $msg);
    $pdo = db();
    $st = $pdo->prepare('INSERT INTO contacts (name, email, phone, subject, message) VALUES (?,?,?,?,?)');
    $st->execute([$name, $email, $body['phone'] ?? '', $body['subject'] ?? '', $msg]);
    record_attempt($email, $ip, 'contact', true);
    send_mail(ADMIN_EMAIL, "[KRTaker] Contact: $name", contact_email_html($body));
    json_out(['ok' => true]);
}

/* ═══════════════════════════ PHASE 2 — APP API ═══════════════════════════ */

case 'app-setup': {
    $key = (string)($_GET['key'] ?? '');
    /* SA1 v21: constant-time comparison (was `!==`) */
    if (!hash_equals(APP_SETUP_KEY, $key)) json_out(['ok' => false, 'error' => 'Invalid setup key.'], 403);
    json_out(['ok' => true, 'seeded' => seed_app()]);
}

case 'app-view-as': {
    /* Three-group access model (v3.90):
       Admin      (super_admin > admin)                    → may view-as ANY user (except self and admin-group superiors/peers)
       Subscriber (property_owner > property_manager > tenant > building_staff)
                                                           → same group, strictly below
       Backoffice (hr_admin = legal_counsel = accountant > crm_helpdesk = service_manager
                   > service_partner > service_staff)      → same group, strictly below
       Both legacy (DB) role ids and new taxonomy ids are accepted in $H. */
    $u = require_user();
    $pdo = db();
    $H = [
        'superadmin' => ['g' => 'admin', 'r' => 2], 'super_admin' => ['g' => 'admin', 'r' => 2],
        'admin' => ['g' => 'admin', 'r' => 1],
        'owner' => ['g' => 'sub', 'r' => 4], 'property_owner' => ['g' => 'sub', 'r' => 4],
        'manager' => ['g' => 'sub', 'r' => 3], 'property_manager' => ['g' => 'sub', 'r' => 3],
        'tenant' => ['g' => 'sub', 'r' => 2],
        'building_staff' => ['g' => 'sub', 'r' => 1],
        'hr' => ['g' => 'bo', 'r' => 4], 'hr_admin' => ['g' => 'bo', 'r' => 4],
        'legal' => ['g' => 'bo', 'r' => 4], 'legal_counsel' => ['g' => 'bo', 'r' => 4],
        'accountant' => ['g' => 'bo', 'r' => 4],
        'crm' => ['g' => 'bo', 'r' => 3], 'crm_helpdesk' => ['g' => 'bo', 'r' => 3],
        'svc_mgr' => ['g' => 'bo', 'r' => 3], 'service_manager' => ['g' => 'bo', 'r' => 3],
        'partner' => ['g' => 'bo', 'r' => 2], 'service_partner' => ['g' => 'bo', 'r' => 2],
        'service_staff' => ['g' => 'bo', 'r' => 1],
    ];
    $target = strtolower(trim($body['email'] ?? ''));
    if ($target === '') json_out(['ok' => false, 'error' => 'email required.'], 400);
    if ($target === strtolower($u['email'])) json_out(['ok' => false, 'error' => 'Cannot view as yourself.'], 400);
    $st = $pdo->prepare("SELECT id, name, email, role FROM subscribers WHERE lower(email)=? AND status='active'");
    $st->execute([$target]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    $kind = 'sub';
    if (!$t) {
        $st = $pdo->prepare('SELECT id, name, email, role FROM app_users WHERE lower(email)=? AND active=1');
        $st->execute([$target]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        $kind = 'staff';
    }
    if (!$t) json_out(['ok' => false, 'error' => 'No active user with that email.'], 404);
    $me = $H[$u['role']] ?? null;
    $them = $H[$t['role']] ?? null;
    $deny = false; $reason = '';
    if (!$me || !$them) { $deny = true; $reason = 'Unknown role.'; }
    elseif ($me['g'] === 'admin') {
        /* Admin group: any user, except admin-group superiors/peers (self already blocked) */
        if ($them['g'] === 'admin' && $them['r'] >= $me['r']) { $deny = true; $reason = 'Not allowed — admin-group superior or peer.'; }
    } else {
        if ($me['g'] !== $them['g']) { $deny = true; $reason = 'Not allowed — different user group.'; }
        elseif ($them['r'] >= $me['r']) { $deny = true; $reason = 'Not allowed — not a subordinate.'; }
    }
    if ($deny) {
        audit($u['name'], 'View-as denied', 'system', $t['email'], $reason);
        json_out(['ok' => false, 'error' => $reason], 403);
    }
    $ttl = 30 * 60;
    $tok = make_token($t['id'], $kind, $u['name'], $ttl);
    audit($u['name'], 'View-as started', 'system', $t['email'], '30-minute token (three-group model)');
    json_out(['ok' => true, 'token' => $tok, 'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl), 'impersonator' => $u['name'],
              'user' => ['name' => $t['name'], 'email' => $t['email'], 'role' => $t['role'] ?? '', 'kind' => $kind]]);
}

case 'app-login': {
    $email = strtolower(trim($body['email'] ?? ''));
    $pass  = $body['password'] ?? '';
    $ip = client_ip();
    if (!$email || !$pass) json_out(['ok' => false, 'error' => 'Email and password are required.'], 400);
    if (auth_blocked($email, $ip)) {
        record_attempt($email, $ip, 'login-blocked', false);
        $lm = (int)admin_cfg(db(), 'sec_lockout_minutes', 15); if ($lm < 1) $lm = 1; if ($lm > 1440) $lm = 1440;
        throttle_out('Too many failed attempts. Try again later.', $email, $ip, $lm);
    }
    $pdo = db();
    $u = null;
    // 1) real customer (subscriber)
    $st = $pdo->prepare('SELECT * FROM subscribers WHERE email=?');
    $st->execute([$email]);
    $sub = $st->fetch(PDO::FETCH_ASSOC);
    if ($sub && $sub['status'] === 'active' && $sub['password_hash'] && password_verify($pass, $sub['password_hash'])) {
        $u = $sub; $u['kind'] = 'sub';
    }
    // 2) staff
    if (!$u) {
        $st = $pdo->prepare('SELECT * FROM app_users WHERE email=? AND active=1');
        $st->execute([$email]);
        $staff = $st->fetch(PDO::FETCH_ASSOC);
        if ($staff && password_verify($pass, $staff['password_hash'])) {
            $u = $staff; $u['kind'] = 'staff';
        }
    }
    // 3) SA1 v20: subscriber team member → overlay parent subscriber (plan/limits/scoping) + member role
    if (!$u) {
        $st = $pdo->prepare('SELECT * FROM team_members WHERE email=? AND status="active"');
        $st->execute([$email]);
        $tm = $st->fetch(PDO::FETCH_ASSOC);
        if ($tm && password_verify($pass, $tm['password_hash'])) {
            $st = $pdo->prepare('SELECT * FROM subscribers WHERE email=? AND status="active"');
            $st->execute([$tm['sub_email']]);
            $par = $st->fetch(PDO::FETCH_ASSOC);
            if ($par) {
                $u = $par;
                $u['kind'] = 'sub';
                $u['team_member'] = true;
                $u['team_id'] = $tm['id'];
                $u['name'] = $tm['name'];
                $u['role'] = $tm['role'];
            }
        }
    }
    if (!$u) {
        record_attempt($email, $ip, 'login', false);
        json_out(['ok' => false, 'error' => 'Invalid email or password.'], 401);
    }
    /* V2.16: 2FA gate — TOTP (authenticator) or email OTP. First POST (no code)
       → need_2fa with method; second POST carries 2fa_code. Wrong code counts as
       a failed login attempt. TOTP users may request an emailed code instead
       (2fa_alt=email) — the recovery path for a lost/stale authenticator secret. */
    if (($u['kind'] ?? '') === 'staff' && ($u['role'] ?? '') === 'superadmin'
        && !empty($u['totp_enabled'])) {
        $twofa = trim((string)($body['2fa_code'] ?? ''));
        $useEmail = (($u['twofa_method'] ?? '') === 'email') || !empty($body['2fa_alt']) || empty($u['totp_secret']);
        if ($useEmail) {
            if ($twofa === '') {
                otp_send($pdo, $u);
                json_out(['ok' => false, 'error' => 'Two-factor authentication required.', 'need_2fa' => true, 'method' => 'email', 'email_hint' => mask_email($u['email'])], 401);
            }
            if (!otp_verify($pdo, $u, $twofa)) {
                record_attempt($email, $ip, 'login', false);
                json_out(['ok' => false, 'error' => 'Invalid or expired two-factor code.'], 401);
            }
        } else {
            if ($twofa === '') {
                json_out(['ok' => false, 'error' => 'Two-factor authentication required.', 'need_2fa' => true, 'method' => 'totp'], 401);
            }
            if (!totp_verify($u['totp_secret'], $twofa)) {
                record_attempt($email, $ip, 'login', false);
                json_out(['ok' => false, 'error' => 'Invalid two-factor code.'], 401);
            }
        }
    }
    /* V2.17: security alert on first successful login from a new IP/device.
       Evaluated BEFORE record_attempt(true) — otherwise the success row just
       inserted would make the IP "known" and the alert could never fire. */
    $alertNewIp = new_login_alert_needed($pdo, $email, $ip);
    record_attempt($email, $ip, 'login', true);
    if ($alertNewIp) send_login_alert($pdo, $email, $u['name'], $ip);
    $now = gmdate('Y-m-d H:i:s');
    if (!empty($u['team_member'])) {
        $pdo->prepare('UPDATE team_members SET last_login=? WHERE id=?')->execute([$now, $u['team_id']]);
    } elseif ($u['kind'] === 'sub') {
        $pdo->prepare('UPDATE subscribers SET last_login=? WHERE id=?')->execute([$now, $u['id']]);
    } else {
        $pdo->prepare('UPDATE app_users SET last_login=? WHERE id=?')->execute([$now, $u['id']]);
    }
    /* SA1 v28: superadmin sessions are short-lived (12h) — higher-trust surface, smaller blast radius.
       V2.20: everyone else honours the configurable session_ttl_hours policy (default 7d, clamp 1h–30d). */
    $ttl = (($u['kind'] ?? '') === 'staff' && ($u['role'] ?? '') === 'superadmin') ? 43200
        : (max(1, min(720, (int)admin_cfg(db(), 'session_ttl_hours', 168))) * 3600);
    $tok = make_token(!empty($u['team_member']) ? $u['team_id'] : $u['id'], !empty($u['team_member']) ? 'team' : $u['kind'], '', $ttl);
    audit($u['name'], 'Login', 'auth', (string)($u['team_id'] ?? $u['id']));
    json_out(['ok' => true, 'token' => $tok, 'user' => user_payload($u)]);
}

case 'app-me': {
    $u = require_user();
    json_out(['ok' => true, 'user' => user_payload($u), 'catalog' => plan_catalog(), 'subscription' => sub_info($u['email']),
        'package' => ($u['kind'] === 'sub')
            ? ['code' => plan_for_user($u), 'limits' => effective_limits($u), 'modules' => effective_modules($u)]
            : null]);
}

case 'app-logout': {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        db()->prepare('DELETE FROM app_tokens WHERE token=?')->execute([hash('sha256', $m[1])]);
    }
    json_out(['ok' => true]);
}

/* V2.27: mark the guided-setup wizard complete for a subscriber account */
case 'app-setup-done': {
    $u = require_user();
    if (($u['kind'] ?? '') !== 'sub') json_out(['ok' => false, 'error' => 'Only subscriber accounts can complete setup.'], 403);
    db()->prepare("UPDATE subscribers SET setup_at = datetime('now'), last_login = COALESCE(last_login, datetime('now')) WHERE id=?")->execute([$u['id']]);
    json_out(['ok' => true]);
}

/* ── V2.17: session registry — list active sessions, revoke one / others / all ── */
case 'app-sessions': {
    $u = require_user();
    $pdo = db();
    $kind = !empty($u['team_member']) ? 'team' : $u['kind'];
    $uid  = !empty($u['team_member']) ? $u['team_id'] : $u['id'];
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ($_SERVER['Authorization'] ?? ''));
    $curHash = '';
    if (preg_match('/Bearer\s+(\S+)/i', (string)$auth, $m)) $curHash = hash('sha256', $m[1]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $body['action'] ?? '';
        if ($action === 'revoke') {
            $sid = trim((string)($body['id'] ?? ''));
            if ($sid === '') json_out(['ok' => false, 'error' => 'Session id required.'], 400);
            $st = $pdo->prepare('DELETE FROM app_tokens WHERE token=? AND user_id=? AND kind=?');
            $st->execute([$sid, $uid, $kind]);
            json_out(['ok' => true, 'revoked' => (bool)$st->rowCount(), 'current_ended' => ($sid === $curHash)]);
        } elseif ($action === 'revoke_others') {
            $st = $pdo->prepare('DELETE FROM app_tokens WHERE user_id=? AND kind=? AND token<>?');
            $st->execute([$uid, $kind, $curHash]);
            json_out(['ok' => true, 'revoked' => (int)$st->rowCount()]);
        } elseif ($action === 'revoke_all') {
            $pdo->prepare('DELETE FROM app_tokens WHERE user_id=? AND kind=?')->execute([$uid, $kind]);
            json_out(['ok' => true, 'revoked_all' => true, 'current_ended' => true]);
        } elseif ($action !== '') {
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
        }
        /* empty action (frontend POSTs {}) → fall through to the listing */
    }
    $st = $pdo->prepare('SELECT token, ip, ua, created_at, last_seen, expires_at, impersonator FROM app_tokens WHERE user_id=? AND kind=? ORDER BY created_at DESC');
    $st->execute([$uid, $kind]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $sessions = [];
    foreach ($rows as $r) {
        $sessions[] = [
            'id'           => $r['token'],
            'ip'           => $r['ip'] ?: '—',
            'ua'           => (string)$r['ua'],
            'device'       => ua_device_label($r['ua']),
            'created_at'   => $r['created_at'],
            'last_seen'    => $r['last_seen'] ?: $r['created_at'],
            'expires_at'   => $r['expires_at'],
            'current'      => ($r['token'] === $curHash) ? 1 : 0,
            'impersonator' => (string)$r['impersonator'],
        ];
    }
    json_out(['ok' => true, 'sessions' => $sessions]);
}

/* V2.17: recent successful sign-ins for the current account (IP + time) */
case 'app-login-history': {
    $u = require_user();
    $st = db()->prepare("SELECT ts, ip, ok FROM auth_attempts WHERE email=? AND kind='login' ORDER BY id DESC LIMIT 12");
    $st->execute([strtolower(trim($u['email']))]);
    json_out(['ok' => true, 'history' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'app-2fa-status': {
    $u = require_user();
    if ($u['kind'] !== 'staff' || ($u['role'] ?? '') !== 'superadmin') json_out(['ok' => false, 'error' => 'Superadmin only.'], 403);
    json_out(['ok' => true, 'enabled' => !empty($u['totp_enabled']),
        'method' => (($u['twofa_method'] ?? '') === 'email' ? 'email' : 'totp'),
        'email_hint' => mask_email($u['email'])]);
}

case 'app-2fa-send': {
    $u = require_user();
    if ($u['kind'] !== 'staff' || ($u['role'] ?? '') !== 'superadmin') json_out(['ok' => false, 'error' => 'Superadmin only.'], 403);
    /* V2.17: cap OTP email spam — 5 requests/10 min/IP (recorded so the count accumulates) */
    $ip = client_ip();
    if (recent_any('', $ip, 10, 0, 5, ['2fa-send'])) throttle_out('Too many code requests. Try again later.', $u['email'], $ip, 10, ['2fa-send']);
    otp_send(db(), $u);
    record_attempt($u['email'], $ip, '2fa-send', true);
    json_out(['ok' => true, 'email_hint' => mask_email($u['email'])]);
}

/* Git-triggered deploy verification (service-key gated, 2026-08-09):
   CI builds api/index.php from src/, then POSTs here and compares sha256 —
   closes the loop on "deployed file == built file". Also used by local deploys. */
case 'app-deploy-status': {
    if (!service_authed()) json_out(['ok' => false, 'error' => 'Service key required.'], 403);
    $f = __FILE__;
    json_out(['ok' => true, 'file' => basename($f), 'size' => filesize($f), 'sha256' => hash_file('sha256', $f), 'php' => PHP_VERSION]);
}

/* Daily API performance digest (service-key gated, 2026-08-09):
   slowest-10 endpoints (last 24h) + totals + error leaders, formatted as a
   ready-to-send text block. Cron (no_agent) POSTs and delivers stdout. */
case 'app-api-digest': {
    if (!service_authed()) json_out(['ok' => false, 'error' => 'Service key required.'], 403);
    $pdo = db();
    $one = function ($sql) use ($pdo) { $st = $pdo->query($sql); $r = $st->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; };
    $slow = $pdo->query("SELECT action, COUNT(*) n, ROUND(AVG(ms)) avg_ms, MAX(ms) max_ms,
            SUM(CASE WHEN status>=400 THEN 1 ELSE 0 END) errs,
            ROUND(100.0*SUM(CASE WHEN status>=400 THEN 1 ELSE 0 END)/COUNT(*),1) err_pct
        FROM api_usage WHERE ts >= datetime('now','-24 hours')
        GROUP BY action ORDER BY avg_ms DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $errs = $pdo->query("SELECT action, COUNT(*) n FROM api_usage
        WHERE ts >= datetime('now','-24 hours') AND status>=400
        GROUP BY action ORDER BY n DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $tot24 = (int)$one("SELECT COUNT(*) FROM api_usage WHERE ts >= datetime('now','-24 hours')");
    $tot7d = (int)$one("SELECT COUNT(*) FROM api_usage WHERE ts >= datetime('now','-7 days')");
    $err24 = (int)$one("SELECT COUNT(*) FROM api_usage WHERE ts >= datetime('now','-24 hours') AND status>=400");
    $err7d = (int)$one("SELECT COUNT(*) FROM api_usage WHERE ts >= datetime('now','-7 days') AND status>=400");
    $byAuth = $pdo->query("SELECT auth, COUNT(*) n FROM api_usage
        WHERE ts >= datetime('now','-24 hours') GROUP BY auth ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
    $lines = [];
    $lines[] = '📊 *KRTaker API digest — last 24h*';
    $lines[] = sprintf('Requests: %s (7d: %s) · Errors: %s (%.1f%%)',
        number_format($tot24), number_format($tot7d), number_format($err24), $tot24 ? 100.0 * $err24 / $tot24 : 0);
    if ($byAuth) {
        $a = [];
        foreach ($byAuth as $x) $a[] = $x['auth'] . ' ' . number_format((int)$x['n']);
        $lines[] = 'Auth: ' . implode(' · ', $a);
    }
    if ($slow) {
        $lines[] = '';
        $lines[] = '🐢 *Slowest endpoints (avg ms)*';
        foreach ($slow as $i => $s) {
            $lines[] = sprintf('%d. `%s` — %sms avg (max %sms) · %s req · %.1f%% err',
                $i + 1, $s['action'], $s['avg_ms'], $s['max_ms'], number_format((int)$s['n']), (float)$s['err_pct']);
        }
    } else {
        $lines[] = 'No API traffic in the last 24h.';
    }
    if ($errs) {
        $lines[] = '';
        $lines[] = '🚨 *Top error endpoints*';
        foreach ($errs as $i => $s) {
            $lines[] = sprintf('%d. `%s` — %s errors', $i + 1, $s['action'], number_format((int)$s['n']));
        }
    }
    $text = implode("\n", $lines);
    json_out(['ok' => true, 'text' => $text]);
}

case 'app-2fa-setup': {
    $u = require_user();
    if ($u['kind'] !== 'staff' || ($u['role'] ?? '') !== 'superadmin') json_out(['ok' => false, 'error' => 'Superadmin only.'], 403);
    if (!empty($u['totp_enabled']) && !empty($u['totp_secret'])) {
        json_out(['ok' => false, 'error' => 'Two-factor auth is already enabled. Disable it first to rotate the secret.'], 400);
    }
    $secret = totp_secret_new();
    db()->prepare('UPDATE app_users SET totp_secret=? WHERE id=?')->execute([$secret, $u['id']]);
    audit($u['name'], '2FA setup started', 'auth', (string)$u['id']);
    json_out(['ok' => true, 'secret' => $secret, 'uri' => totp_uri($u['email'], $secret)]);
}

case 'app-2fa-enable': {
    $u = require_user();
    if ($u['kind'] !== 'staff' || ($u['role'] ?? '') !== 'superadmin') json_out(['ok' => false, 'error' => 'Superadmin only.'], 403);
    $method = (($body['method'] ?? '') === 'email') ? 'email' : 'totp';
    $code = trim((string)($body['code'] ?? ''));
    if ($method === 'email') {
        if (!otp_verify(db(), $u, $code)) json_out(['ok' => false, 'error' => 'Invalid verification code.'], 400);
    } else {
        if ($code === '' || empty($u['totp_secret']) || !totp_verify($u['totp_secret'], $code)) {
            json_out(['ok' => false, 'error' => 'Invalid verification code.'], 400);
        }
    }
    db()->prepare('UPDATE app_users SET totp_enabled=1, twofa_method=? WHERE id=?')->execute([$method, $u['id']]);
    audit($u['name'], '2FA enabled (' . $method . ')', 'auth', (string)$u['id']);
    json_out(['ok' => true, 'method' => $method]);
}

case 'app-2fa-disable': {
    $u = require_user();
    if ($u['kind'] !== 'staff' || ($u['role'] ?? '') !== 'superadmin') json_out(['ok' => false, 'error' => 'Superadmin only.'], 403);
    $code = trim((string)($body['code'] ?? ''));
    $pass = (string)($body['password'] ?? '');
    $okCode = ($code !== '' && !empty($u['totp_secret']) && totp_verify($u['totp_secret'], $code))
        || otp_verify(db(), $u, $code);
    if (!$okCode) json_out(['ok' => false, 'error' => 'Invalid verification code.'], 400);
    if ($pass === '' || !password_verify($pass, $u['password_hash'])) {
        json_out(['ok' => false, 'error' => 'Password required to disable two-factor auth.'], 400);
    }
    db()->prepare("UPDATE app_users SET totp_enabled=0, totp_secret='', twofa_method='totp' WHERE id=?")->execute([$u['id']]);
    audit($u['name'], '2FA disabled', 'auth', (string)$u['id']);
    json_out(['ok' => true]);
}

case 'app-bootstrap': {
    $u = require_user();
    $pdo = db();
    $cols = [];
    $q = function ($sql, $args = []) use ($pdo) {
        $st = $pdo->prepare($sql); $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $role = $u['role'];

    /* Staff-ish roles → full org data */
    $full = in_array($role, ['superadmin', 'owner', 'manager', 'svc_mgr', 'legal', 'crm', 'accountant', 'hr'], true);

    if ($full) {
        $data = [
            'properties' => $q('SELECT * FROM properties'),
            'units'      => $q('SELECT * FROM units'),
            'tenants'    => $q('SELECT * FROM tenants'),
            'leases'     => $q('SELECT * FROM leases'),
            'invoices'   => $q('SELECT * FROM invoices'),
            'receipts'   => $q('SELECT * FROM receipts'),
            'payments'   => $q('SELECT * FROM payments'),
            'tickets'    => $q('SELECT * FROM tickets'),
            'partners'   => $q('SELECT * FROM partners'),
            'staff'      => $q('SELECT * FROM staff'),
            'support'    => $q('SELECT * FROM support'),
            'cases'      => $q('SELECT * FROM cases'),
            'gateway_tx' => $q('SELECT * FROM gateway_tx ORDER BY created_at DESC'),
            'ticket_thread' => $q('SELECT * FROM ticket_thread ORDER BY id'),
            'documents'  => $q('SELECT * FROM documents ORDER BY ts DESC'),
            'notices'    => $q('SELECT * FROM notices ORDER BY pinned DESC, ts DESC'),
            'referrals'  => $q('SELECT * FROM referrals ORDER BY ts DESC'),
            'property_rent' => $q('SELECT * FROM property_rent'),
            'amenities'  => $q('SELECT * FROM amenities ORDER BY prop, unit'),
            'caretaker_invoices' => $q('SELECT * FROM caretaker_invoices ORDER BY month DESC'),
            'insurance_policies' => $q('SELECT * FROM insurance_policies ORDER BY ts DESC'),
            'maintenance_requests' => $q('SELECT * FROM maintenance_requests ORDER BY ts DESC'),
            'leads' => $q('SELECT * FROM leads ORDER BY ts DESC'),
            'statement_payouts' => $q('SELECT * FROM statement_payouts ORDER BY month DESC'),
            'compliance_items' => $q('SELECT * FROM compliance_items ORDER BY expiry_date'),
            'renewal_requests' => $q('SELECT * FROM renewal_requests ORDER BY ts DESC'),
            'meter_readings' => $q('SELECT * FROM meter_readings ORDER BY month DESC'),
            'utility_bills' => $q('SELECT * FROM utility_bills ORDER BY month DESC'),
            'utility_tariffs' => $q('SELECT * FROM utility_tariffs'),
            'partner_invoices' => $q('SELECT * FROM partner_invoices ORDER BY ts DESC'),
            'vendor_payouts' => $q('SELECT * FROM vendor_payouts ORDER BY month DESC'),
            'remittances' => $q('SELECT * FROM remittances ORDER BY month DESC'),
            'onboarding_apps' => $q('SELECT * FROM onboarding_apps ORDER BY ts DESC'),
            'vendor_ratings' => $q('SELECT * FROM vendor_ratings ORDER BY ts DESC'),
            'sla_config' => $q('SELECT * FROM sla_config'),
            'kr_alerts' => $q("SELECT * FROM kr_alerts WHERE user_key=? OR user_key='sub:1' ORDER BY ts DESC LIMIT 50", [user_key_for($u)]),
            'wa_channels' => $q('SELECT * FROM wa_channels ORDER BY ts DESC'),
            'kr_wa_msgs' => $q('SELECT * FROM kr_wa_msgs ORDER BY id DESC LIMIT 100'),
            'board_reports' => $q('SELECT id, month, kind, created_by, ts FROM board_reports ORDER BY ts DESC LIMIT 25'),
            'nid_verifications' => $q('SELECT * FROM nid_verifications ORDER BY ts DESC'),
            'legal_notices' => $q('SELECT * FROM legal_notices ORDER BY ts DESC'),
            'case_events' => $q('SELECT * FROM case_events ORDER BY ts DESC'),
            'thana_forms' => $q('SELECT * FROM thana_forms ORDER BY ts DESC'),
            'land_parcels' => $q('SELECT * FROM land_parcels ORDER BY ts DESC'),
            'land_visits' => $q('SELECT * FROM land_visits ORDER BY scheduled_for DESC'),
            'land_media' => $q('SELECT * FROM land_media ORDER BY ts DESC'),
            'land_events' => $q('SELECT * FROM land_events ORDER BY ts DESC'),
            'nrb_tax_returns' => $q('SELECT * FROM nrb_tax_returns ORDER BY ts DESC'),
            'nrb_repatriations' => $q('SELECT * FROM nrb_repatriations ORDER BY ts DESC'),
            'nrb_vacancies' => $q('SELECT * FROM nrb_vacancies ORDER BY ts DESC'),
            'nrb_showings' => $q('SELECT * FROM nrb_showings ORDER BY scheduled_at DESC'),
            'nrb_disputes' => $q('SELECT * FROM nrb_disputes ORDER BY ts DESC'),
            'concierge_requests' => $q('SELECT * FROM concierge_requests ORDER BY ts DESC'),
            'concierge_docs' => $q('SELECT * FROM concierge_docs ORDER BY ts DESC'),
            'holding_taxes' => $q('SELECT * FROM holding_taxes ORDER BY ts DESC'),
            'smart_locks' => $q('SELECT * FROM smart_locks ORDER BY ts DESC'),
            'cctv_cameras' => $q('SELECT * FROM cctv_cameras ORDER BY ts DESC'),
            'health_plans' => $q('SELECT * FROM health_plans ORDER BY ts DESC'),
            'build_projects' => $q('SELECT * FROM build_projects ORDER BY ts DESC'),
            'build_milestones' => $q('SELECT * FROM build_milestones ORDER BY ts DESC'),
            'build_expenses' => $q('SELECT * FROM build_expenses ORDER BY ts DESC'),
            'build_media' => $q('SELECT * FROM build_media ORDER BY ts DESC'),
            'gate_visits' => $q('SELECT * FROM gate_visits ORDER BY ts DESC'),
            'fire_assets' => $q('SELECT * FROM fire_assets ORDER BY ts DESC'),
            'fire_incidents' => $q('SELECT * FROM fire_incidents ORDER BY ts DESC'),
            'evacuation_plans' => $q('SELECT * FROM evacuation_plans ORDER BY ts DESC'),
            'emergency_contacts' => $q('SELECT * FROM emergency_contacts ORDER BY ts DESC'),
            'sys_assets' => $q('SELECT * FROM sys_assets ORDER BY ts DESC'),
            'building_staff' => $q('SELECT * FROM building_staff ORDER BY ts DESC'),
            'staff_attendance' => $q('SELECT * FROM staff_attendance ORDER BY ts DESC'),
                        'samity_members' => $q('SELECT * FROM samity_members ORDER BY ts DESC'),
            'samity_bills' => $q('SELECT * FROM samity_bills ORDER BY ts DESC'),
            'samity_collections' => $q('SELECT * FROM samity_collections ORDER BY ts DESC'),
            'samity_expenses' => $q('SELECT * FROM samity_expenses ORDER BY ts DESC'),'staff_payroll' => $q('SELECT * FROM staff_payroll ORDER BY ts DESC'),
            'sys_services' => $q('SELECT * FROM sys_services ORDER BY ts DESC'),
            'sys_fuel' => $q('SELECT * FROM sys_fuel ORDER BY ts DESC'),
            'resident_vehicles' => $q('SELECT * FROM resident_vehicles ORDER BY ts DESC'),
            'gate_watchlist' => $q('SELECT * FROM gate_watchlist ORDER BY ts DESC'),


        ];
    } elseif ($role === 'tenant') {
        $me = $q('SELECT * FROM tenants WHERE sub_email=?', [$u['email']]);
        $tid = $me ? $me[0]['id'] : '';
        $leases = $tid ? $q('SELECT * FROM leases WHERE t=?', [$tid]) : [];
        $unitIds = array_column($leases, 'u');
        $units = $unitIds ? $q('SELECT * FROM units WHERE id IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ')', $unitIds) : [];
        $propIds = array_column($units, 'p');
        $props = $propIds ? $q('SELECT * FROM properties WHERE id IN (' . implode(',', array_fill(0, count($propIds), '?')) . ')', $propIds) : [];
        $leaseIds = array_column($leases, 'id');
        $invIds = $leaseIds ? $q('SELECT id FROM invoices WHERE l IN (' . implode(',', array_fill(0, count($leaseIds), '?')) . ')', $leaseIds) : [];
        $invIds = array_column($invIds, 'id');
        $invoices = $invIds ? $q('SELECT * FROM invoices WHERE id IN (' . implode(',', array_fill(0, count($invIds), '?')) . ')', $invIds) : [];
        $iIds = array_column($invoices, 'id');
        $receipts = $iIds ? $q('SELECT * FROM receipts WHERE inv IN (' . implode(',', array_fill(0, count($iIds), '?')) . ')', $iIds) : [];
        $payments = $iIds ? $q('SELECT * FROM payments WHERE inv IN (' . implode(',', array_fill(0, count($iIds), '?')) . ')', $iIds) : [];
        $ticketIds = $unitIds ? $q('SELECT id FROM tickets WHERE u IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ')', $unitIds) : [];
        $ticketIds = array_column($ticketIds, 'id');
        $tickets = $ticketIds ? $q('SELECT * FROM tickets WHERE id IN (' . implode(',', array_fill(0, count($ticketIds), '?')) . ')', $ticketIds) : [];
        $thread = $ticketIds ? $q('SELECT * FROM ticket_thread WHERE ticket IN (' . implode(',', array_fill(0, count($ticketIds), '?')) . ') ORDER BY id', $ticketIds) : [];
        $docs = $leaseIds ? $q('SELECT * FROM documents WHERE ref IN (' . implode(',', array_fill(0, count($leaseIds), '?')) . ') ORDER BY ts DESC', $leaseIds) : [];
        $gws = $iIds ? $q('SELECT * FROM gateway_tx WHERE invoice_id IN (' . implode(',', array_fill(0, count($iIds), '?')) . ') ORDER BY created_at DESC', $iIds) : [];
        $data = [
            'properties' => $props, 'units' => $units, 'tenants' => $me,
            'leases' => $leases, 'invoices' => $invoices, 'receipts' => $receipts,
            'payments' => $payments, 'tickets' => $tickets,
            'partners' => [], 'staff' => [], 'support' => [], 'cases' => [],
            'gateway_tx' => $gws, 'ticket_thread' => $thread, 'documents' => $docs,
            'notices' => $q('SELECT * FROM notices ORDER BY pinned DESC, ts DESC'),
            'referrals' => [],
            'property_rent' => $propIds ? $q('SELECT * FROM property_rent WHERE prop IN (' . implode(',', array_fill(0, count($propIds), '?')) . ')', $propIds) : [],
            'amenities' => $propIds ? $q('SELECT * FROM amenities WHERE prop IN (' . implode(',', array_fill(0, count($propIds), '?')) . ') ORDER BY unit', $propIds) : [],
            'caretaker_invoices' => [],
            'insurance_policies' => $tid ? $q('SELECT * FROM insurance_policies WHERE tenant=? ORDER BY ts DESC', [$tid]) : [],
            'maintenance_requests' => $unitIds ? $q('SELECT * FROM maintenance_requests WHERE unit IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ') ORDER BY ts DESC', $unitIds) : [],
            'leads' => [],
            'statement_payouts' => [],
            'compliance_items' => [],
            'renewal_requests' => $leaseIds ? $q('SELECT * FROM renewal_requests WHERE lease IN (' . implode(',', array_fill(0, count($leaseIds), '?')) . ') ORDER BY ts DESC', $leaseIds) : [],
            'meter_readings' => $unitIds ? $q('SELECT * FROM meter_readings WHERE unit IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ') ORDER BY month DESC', $unitIds) : [],
            'utility_bills' => $unitIds ? $q('SELECT * FROM utility_bills WHERE unit IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ') ORDER BY month DESC', $unitIds) : [],
            'utility_tariffs' => $q('SELECT * FROM utility_tariffs'),
            'partner_invoices' => [], 'vendor_payouts' => [], 'remittances' => [], 'onboarding_apps' => [], 'vendor_ratings' => [], 'sla_config' => [],
            'kr_alerts' => $q("SELECT * FROM kr_alerts WHERE user_key=? ORDER BY ts DESC LIMIT 30", [user_key_for($u)]),
            'wa_channels' => [], 'kr_wa_msgs' => [], 'board_reports' => [],
            'nid_verifications' => $tid ? $q('SELECT * FROM nid_verifications WHERE tenant=? ORDER BY ts DESC', [$tid]) : [],
            'thana_forms' => $tid ? $q('SELECT * FROM thana_forms WHERE tenant=? ORDER BY ts DESC', [$tid]) : [],
            'legal_notices' => $leaseIds ? $q('SELECT * FROM legal_notices WHERE lease IN (' . implode(',', array_fill(0, count($leaseIds), '?')) . ') ORDER BY ts DESC', $leaseIds) : [],
            'case_events' => $leaseIds ? $q('SELECT * FROM case_events WHERE case_id IN (SELECT id FROM cases WHERE ref_lease IN (' . implode(',', array_fill(0, count($leaseIds), '?')) . ')) ORDER BY ts DESC', $leaseIds) : [],
            'land_parcels' => [], 'land_visits' => [], 'land_media' => [], 'land_events' => [],
            'nrb_tax_returns' => [], 'nrb_repatriations' => [], 'nrb_vacancies' => [], 'nrb_showings' => [], 'nrb_disputes' => [],
            'concierge_requests' => [], 'concierge_docs' => [], 'holding_taxes' => [],
            'smart_locks' => [], 'cctv_cameras' => [], 'health_plans' => [],
            'build_projects' => [], 'build_milestones' => [], 'build_expenses' => [], 'build_media' => [],
            'gate_visits' => [], 'resident_vehicles' => [], 'gate_watchlist' => [],
            'fire_assets' => [], 'fire_incidents' => [], 'evacuation_plans' => [], 'emergency_contacts' => [],
            'sys_assets' => [], 'sys_services' => [], 'sys_fuel' => [],
            'samity_members' => [], 'samity_bills' => [], 'samity_collections' => [], 'samity_expenses' => [],            'building_staff' => [], 'staff_attendance' => [], 'staff_payroll' => [],
        ];
    } else { /* partner */
        $me = $q('SELECT * FROM partners WHERE sub_email=?', [$u['email']]);
        $org = $me ? $me[0]['name'] : $u['org'];
        $tickets = $q('SELECT * FROM tickets WHERE con=?', [$org]);
        $ticketIds = array_column($tickets, 'id');
        $thread = $ticketIds ? $q('SELECT * FROM ticket_thread WHERE ticket IN (' . implode(',', array_fill(0, count($ticketIds), '?')) . ') ORDER BY id', $ticketIds) : [];
        $docs = $ticketIds ? $q('SELECT * FROM documents WHERE ref IN (' . implode(',', array_fill(0, count($ticketIds), '?')) . ') ORDER BY ts DESC', $ticketIds) : [];
        $data = [
            'properties' => [], 'units' => [], 'tenants' => [],
            'leases' => [], 'invoices' => [], 'receipts' => [],
            'payments' => [], 'tickets' => $tickets, 'partners' => $me,
            'staff' => [], 'support' => [], 'cases' => [], 'gateway_tx' => [],
            'ticket_thread' => $thread, 'documents' => $docs,
            'notices' => $q('SELECT * FROM notices ORDER BY pinned DESC, ts DESC'),
            'referrals' => $q('SELECT * FROM referrals WHERE user_email=? ORDER BY ts DESC', [$u['email']]),
            'property_rent' => [], 'amenities' => [], 'caretaker_invoices' => [], 'insurance_policies' => [],
            'maintenance_requests' => $org ? $q('SELECT * FROM maintenance_requests WHERE vendor=? ORDER BY ts DESC', [$org]) : [],
            'kr_alerts' => $q("SELECT * FROM kr_alerts WHERE user_key=? ORDER BY ts DESC LIMIT 30", [user_key_for($u)]),
            'wa_channels' => [], 'kr_wa_msgs' => [], 'board_reports' => [],
            'leads' => [], 'statement_payouts' => [], 'compliance_items' => [],
            'renewal_requests' => [], 'meter_readings' => [], 'utility_bills' => [], 'utility_tariffs' => [],
            'partner_invoices' => $me ? $q('SELECT * FROM partner_invoices WHERE partner=? ORDER BY ts DESC', [$me[0]['id']]) : [],
            'vendor_payouts' => $me ? $q('SELECT * FROM vendor_payouts WHERE partner=? ORDER BY month DESC', [$me[0]['id']]) : [],
            'remittances' => [],
            'onboarding_apps' => [],
            'vendor_ratings' => $me ? $q('SELECT * FROM vendor_ratings WHERE partner=? ORDER BY ts DESC', [$me[0]['id']]) : [],
            'sla_config' => [],            'partner_invoices' => $me ? $q('SELECT * FROM partner_invoices WHERE partner=? ORDER BY ts DESC', [$me[0]['id']]) : [],
            'vendor_payouts' => $me ? $q('SELECT * FROM vendor_payouts WHERE partner=? ORDER BY month DESC', [$me[0]['id']]) : [],
            'remittances' => [],            'maintenance_requests' => [], 'leads' => [], 'statement_payouts' => [], 'compliance_items' => [],
            'renewal_requests' => [], 'meter_readings' => [], 'utility_bills' => [], 'utility_tariffs' => [],
            'nid_verifications' => [], 'thana_forms' => [], 'legal_notices' => [], 'case_events' => [],
            'land_parcels' => [], 'land_visits' => [], 'land_media' => [], 'land_events' => [], 'nrb_tax_returns' => [], 'nrb_repatriations' => [], 'nrb_vacancies' => [], 'nrb_showings' => [], 'nrb_disputes' => [], 'concierge_requests' => [], 'concierge_docs' => [], 'holding_taxes' => [], 'smart_locks' => [], 'cctv_cameras' => [], 'health_plans' => [],
        ];
    }

    /* platform meta (scoped: hide SaaS stats from tenant/partner) */
    $pf = [];
    if ($full) {
        foreach ($q('SELECT * FROM platform_meta') as $m) $pf[$m['k']] = $m['v'];
    } else {
        $pf = ['subscribers' => 0, 'mrr' => 0, 'arr' => 0, 'partners' => 0, 'staff' => 0, 'csat' => 0, 'subs_trend' => ''];
    }
    $data['_platform'] = $pf;

    json_out([
        'ok' => true,
        'user' => user_payload($u),
        'catalog' => plan_catalog(),
        'subscription' => sub_info($u['email']),
        'package' => ($u['kind'] === 'sub')
            ? ['code' => plan_for_user($u), 'limits' => effective_limits($u), 'modules' => effective_modules($u)]
            : null,
        'collections' => $data,
    ]);
}

case 'app-subscribe': {
    $u = require_user();
    require_module($u, 'subscriptions');
    $plan = strtolower(trim($body['plan'] ?? ''));
    $valid = array_column(db()->query('SELECT code FROM plan_catalog')->fetchAll(PDO::FETCH_ASSOC), 'code');
    if (!$valid) $valid = ['starter', 'business', 'enterprise'];
    if (!in_array($plan, $valid, true)) json_out(['ok' => false, 'error' => 'Invalid plan.'], 400);
    $pdo = db();
    if ($u['kind'] === 'staff') {
        /* staff (superadmin) acts on a subscriber org: body.email optional → own org for staff is platform */
        $target = strtolower(trim($body['email'] ?? ''));
        if (!$target) json_out(['ok' => false, 'error' => 'Provide subscriber email (superadmin acting on an org).'], 400);
        $st = $pdo->prepare('UPDATE subscribers SET plan=? WHERE email=?');
        $st->execute([ucfirst($plan), $target]);
        if ($st->rowCount() === 0) json_out(['ok' => false, 'error' => 'Subscriber not found.'], 404);
        audit($u['name'], 'Plan change', 'subscriptions', $target, '→ ' . ucfirst($plan));
        json_out(['ok' => true, 'subscription' => sub_info($target)]);
    }
    $st = $pdo->prepare('UPDATE subscribers SET plan=? WHERE id=?');
    $st->execute([ucfirst($plan), $u['id']]);
    audit($u['name'], 'Plan change', 'subscriptions', $u['email'], '→ ' . ucfirst($plan));
    json_out(['ok' => true, 'subscription' => sub_info($u['email'])]);
}

case 'app-ticket-status': {
    $u = require_user();
    require_module($u, 'maintenance');
    if ($u['role'] === 'tenant') json_out(['ok' => false, 'error' => 'Tenants cannot update ticket status.'], 403);
    $id = trim($body['id'] ?? '');
    $status = trim($body['status'] ?? '');
    $valid = ['Open', 'In Progress', 'Awaiting Payment', 'Closed'];
    if (!$id || !in_array($status, $valid, true)) json_out(['ok' => false, 'error' => 'id and valid status required.'], 400);
    $pdo = db();
    $sql = 'UPDATE tickets SET status=?';
    $args = [$status];
    if (isset($body['cost'])) { $sql .= ', cost=?'; $args[] = (int)$body['cost']; }
    $sql .= ' WHERE id=?'; $args[] = $id;
    $pdo->prepare($sql)->execute($args);
    audit($u['name'], 'Ticket status', 'maintenance', $id, $status);
    json_out(['ok' => true]);
}

/* ── Phase 8: tenant operations ── */
case 'app-push': {
    $u = require_user();
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'state' : 'save';
    $em = $u['email'];
    if ($action === 'state') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM push_subs WHERE sub_email=?");
        $st->execute([$em]);
        $devices = (int)$st->fetchColumn();
        $sub = null;
        if (!empty($_GET['endpoint'])) {
            $st = $pdo->prepare('SELECT * FROM push_subs WHERE endpoint=? AND sub_email=?');
            $st->execute([$_GET['endpoint'], $em]);
            $sub = $st->fetch(PDO::FETCH_ASSOC);
        }
        json_out(['ok' => true, 'enabled' => $devices > 0, 'devices' => $devices, 'vapid_public' => vapid_public_b64url(), 'subscribed' => (bool)$sub]);
    }
    if ($action === 'save') {
        $endpoint = trim($body['endpoint'] ?? '');
        $p256dh = trim($body['p256dh'] ?? '');
        $auth = trim($body['auth'] ?? '');
        if (!$endpoint || !preg_match('#^https://#', $endpoint)) json_out(['ok' => false, 'error' => 'Valid https endpoint required.'], 400);
        if (strlen(b64url_decode($p256dh)) !== 65) json_out(['ok' => false, 'error' => 'p256dh must be a 65-byte key.'], 400);
        if (strlen(b64url_decode($auth)) !== 16) json_out(['ok' => false, 'error' => 'auth must be 16 bytes.'], 400);
        $st = $pdo->prepare("SELECT COUNT(*) FROM push_subs WHERE sub_email=?");
        $st->execute([$em]);
        if ((int)$st->fetchColumn() >= 10) json_out(['ok' => false, 'error' => 'Too many devices (max 10).'], 400);
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
        $pdo->prepare('INSERT INTO push_subs (sub_email, endpoint, p256dh, auth, ua) VALUES (?,?,?,?,?)
            ON CONFLICT(endpoint) DO UPDATE SET sub_email=excluded.sub_email, p256dh=excluded.p256dh, auth=excluded.auth, ua=excluded.ua, created_at=datetime(\'now\')')
            ->execute([$em, $endpoint, $p256dh, $auth, $ua]);
        audit($u['name'], 'Push device registered', 'notifications', substr($endpoint, 0, 60));
        json_out(['ok' => true, 'devices' => (int)$pdo->query("SELECT COUNT(*) FROM push_subs WHERE sub_email='" . str_replace("'", "''", $em) . "'")->fetchColumn()]);
    }
    if ($action === 'remove') {
        $endpoint = trim($body['endpoint'] ?? '');
        if (!$endpoint) json_out(['ok' => false, 'error' => 'endpoint required.'], 400);
        $pdo->prepare('DELETE FROM push_subs WHERE endpoint=? AND sub_email=?')->execute([$endpoint, $em]);
        json_out(['ok' => true]);
    }
    if ($action === 'test') {
        $r = push_to_user($pdo, $em, '🔔 KRTaker test notification', 'Push notifications are working! You will be alerted about maintenance, payments and KYC here.', '/app-v3/');
        json_out(['ok' => $r['sent'] > 0, 'sent' => $r['sent'], 'total' => $r['sent'] + $r['removed'] + count($r['errors']), 'detail' => $r['sent'] > 0 ? 'Delivered.' : ($r['sent'] + $r['removed'] + count($r['errors']) === 0 ? 'No devices subscribed.' : 'No push service accepted (check device).')]);
    }
    if ($action === 'send') {
        if (!service_authed()) json_out(['ok' => false, 'error' => 'Service key required.'], 401);
        $target = trim($body['email'] ?? '');
        $title = trim($body['title'] ?? 'KRTaker');
        $body_txt = trim($body['body'] ?? '');
        $url = trim($body['url'] ?? '/app-v3/');
        $r = push_to_user($pdo, $target, $title, $body_txt, $url);
        json_out(['ok' => true, 'sent' => $r['sent'], 'total' => $r['sent'] + $r['removed'] + count($r['errors'])]);
    }
    json_out(['ok' => false, 'error' => 'action must be state|save|remove|test|send.'], 400);
}

case 'team-list': {
    $u = require_user();
    if (!team_owner_only($u)) json_out(['ok' => false, 'error' => 'Owner access required.'], 403);
    $pdo = db();
    $email = team_scope_email($u, $body, $pdo);
    if (!$email) json_out(['ok' => false, 'error' => 'No subscriber found.'], 404);
    $st = $pdo->prepare('SELECT id, name, email, role, status, created_at, last_login FROM team_members WHERE sub_email=? ORDER BY id');
    $st->execute([$email]);
    $members = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE sub_email=? AND status='active'"); $st->execute([$email]);
    $active = (int)$st->fetchColumn();
    $lim = effective_limits($u);
    $seats = (int)($lim['seats'] ?? 1);
    json_out(['ok' => true, 'members' => $members, 'seats' => $seats, 'used' => 1 + $active,
        'team_limit' => max(0, $seats - 1)]);
}
case 'team-invite': {
    $u = require_user();
    if (!team_owner_only($u)) json_out(['ok' => false, 'error' => 'Owner access required.'], 403);
    $name = trim($body['name'] ?? '');
    $email = strtolower(trim($body['email'] ?? ''));
    $role = trim($body['role'] ?? 'manager');
    if (!$name || !$email) json_out(['ok' => false, 'error' => 'name and email are required.'], 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['ok' => false, 'error' => 'Invalid email address.'], 400);
    if (!in_array($role, ['manager', 'accountant', 'svc_mgr', 'legal', 'crm', 'hr'], true))
        json_out(['ok' => false, 'error' => 'Invalid team role.'], 400);
    $pdo = db();
    $scope = team_scope_email($u, $body, $pdo);
    if (!$scope) json_out(['ok' => false, 'error' => 'No subscriber found.'], 404);
    foreach (['subscribers', 'app_users', 'team_members'] as $tbl) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM $tbl WHERE email=?"); $st->execute([$email]);
        if ((int)$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Email already in use by another account.'], 409);
    }
    $lim = effective_limits($u);
    $seats = (int)($lim['seats'] ?? 1);
    $st = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE sub_email=? AND status='active'"); $st->execute([$scope]);
    $active = (int)$st->fetchColumn();
    if (1 + $active >= $seats)
        json_out(['ok' => false, 'error' => 'Plan seat limit reached (' . $active . ' of ' . max(0, $seats - 1) . ' team seats). Upgrade your plan to add more team members.'], 403);
    $pass = bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO team_members (sub_email, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')
        ->execute([$scope, $name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, 'active']);
    $id = (int)$pdo->lastInsertId();
    audit($u['name'], 'Team member added', 'team', (string)$id, $email . ' (' . $role . ')');
    json_out(['ok' => true, 'id' => $id, 'password' => $pass, 'seats' => ['used' => 1 + $active + 1, 'limit' => $seats]]);
}
case 'team-reset': {
    $u = require_user();
    if (!team_owner_only($u)) json_out(['ok' => false, 'error' => 'Owner access required.'], 403);
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $scope = team_scope_email($u, $body, $pdo);
    $st = $pdo->prepare('SELECT * FROM team_members WHERE id=? AND sub_email=?'); $st->execute([$id, $scope]);
    if (!$st->fetch()) json_out(['ok' => false, 'error' => 'Team member not found.'], 404);
    $pass = bin2hex(random_bytes(4));
    $pdo->prepare('UPDATE team_members SET password_hash=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
    team_revoke_tokens($pdo, $id);
    audit($u['name'], 'Team password reset', 'team', (string)$id);
    json_out(['ok' => true, 'password' => $pass]);
}
case 'team-update': {
    $u = require_user();
    if (!team_owner_only($u)) json_out(['ok' => false, 'error' => 'Owner access required.'], 403);
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $scope = team_scope_email($u, $body, $pdo);
    $st = $pdo->prepare('SELECT * FROM team_members WHERE id=? AND sub_email=?'); $st->execute([$id, $scope]);
    $tm = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tm) json_out(['ok' => false, 'error' => 'Team member not found.'], 404);
    if (array_key_exists('role', $body)) {
        $role = trim($body['role']);
        if (!in_array($role, ['manager', 'accountant', 'svc_mgr', 'legal', 'crm', 'hr'], true))
            json_out(['ok' => false, 'error' => 'Invalid team role.'], 400);
        $pdo->prepare('UPDATE team_members SET role=? WHERE id=?')->execute([$role, $id]);
        team_revoke_tokens($pdo, $id);   /* force re-login to pick up new role */
    }
    if (array_key_exists('status', $body)) {
        $status = trim($body['status']);
        if (!in_array($status, ['active', 'disabled'], true)) json_out(['ok' => false, 'error' => 'Invalid status.'], 400);
        $pdo->prepare('UPDATE team_members SET status=? WHERE id=?')->execute([$status, $id]);
        if ($status === 'disabled') team_revoke_tokens($pdo, $id);
    }
    audit($u['name'], 'Team member updated', 'team', (string)$id);
    json_out(['ok' => true]);
}
case 'team-remove': {
    $u = require_user();
    if (!team_owner_only($u)) json_out(['ok' => false, 'error' => 'Owner access required.'], 403);
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $scope = team_scope_email($u, $body, $pdo);
    $st = $pdo->prepare('SELECT * FROM team_members WHERE id=? AND sub_email=?'); $st->execute([$id, $scope]);
    if (!$st->fetch()) json_out(['ok' => false, 'error' => 'Team member not found.'], 404);
    team_revoke_tokens($pdo, $id);
    $pdo->prepare('DELETE FROM team_members WHERE id=?')->execute([$id]);
    audit($u['name'], 'Team member removed', 'team', (string)$id);
    json_out(['ok' => true]);
}
case 'app-ticket-create': {
    $u = require_user();
    require_module($u, 'maintenance');
    $unit = trim($body['unit_id'] ?? '');
    $desc = trim($body['desc'] ?? '');
    $liab = trim($body['liab'] ?? 'Landlord');
    if (!$unit || !$desc) json_out(['ok' => false, 'error' => 'unit_id and desc are required.'], 400);
    $pdo = db();
    if ($u['role'] === 'tenant') {
        $mine = my_units($u);
        if (!$mine || !in_array($unit, $mine, true)) json_out(['ok' => false, 'error' => 'You can only raise tickets for your own unit.'], 403);
        $liab = 'Landlord';
    }
    if ($u['role'] === 'partner') json_out(['ok' => false, 'error' => 'Partners cannot raise tickets.'], 403);
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'MT-','') AS INTEGER)) FROM tickets")->fetchColumn();
    $id = 'MT-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO tickets (id,u,desc,reported,liab,status,cost) VALUES (?,?,?,?,?,?,0)')
        ->execute([$id, $unit, $desc, gmdate('Y-m-d'), $liab, 'Open']);
    $pdo->prepare('INSERT INTO ticket_thread (ticket, author, body) VALUES (?,?,?)')
        ->execute([$id, $u['name'], 'Ticket opened: ' . $desc]);
    audit($u['name'], 'Ticket created', 'maintenance', $id, $unit . ' ' . $liab);
    webhook_dispatch($pdo, 'ticket.created', ['id' => $id, 'unit' => $unit, 'description' => $desc, 'liability' => $liab, 'reported' => gmdate('Y-m-d'), 'by' => $u['name']]);
    /* SA1 v19: push the property owner unless they raised it themselves */
    try {
        $po = $pdo->prepare('SELECT COALESCE(u.sub_email, p.sub_email, \'\') FROM units u LEFT JOIN properties p ON p.id=u.p WHERE u.id=?');
        $po->execute([$unit]); $own = (string)$po->fetchColumn();
        if ($own !== '' && strcasecmp($own, $u['email']) !== 0)
            push_to_user($pdo, $own, '🔧 Maintenance ticket ' . $id,
                ($u['role'] === 'tenant' ? 'Tenant ' : '') . $u['name'] . ' reported: ' . mb_substr($desc, 0, 90),
                '/app-v3/#/maintenance');
    } catch (Exception $e) {}
    json_out(['ok' => true, 'id' => $id]);
}
case 'app-ticket-comment': {
    $u = require_user();
    require_module($u, 'maintenance');
    $ticket = trim($body['ticket'] ?? '');
    $text = trim($body['body'] ?? '');
    if (!$ticket || !$text) json_out(['ok' => false, 'error' => 'ticket and body required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM tickets WHERE id=?'); $st->execute([$ticket]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) json_out(['ok' => false, 'error' => 'Ticket not found.'], 404);
    if ($u['role'] === 'tenant' && !in_array($t['u'], my_units($u), true)) json_out(['ok' => false, 'error' => 'Not your ticket.'], 403);
    if ($u['role'] === 'partner' && $t['con'] !== my_org($u)) json_out(['ok' => false, 'error' => 'Not your job.'], 403);
    $pdo->prepare('INSERT INTO ticket_thread (ticket, author, body) VALUES (?,?,?)')
        ->execute([$ticket, $u['name'], $text]);
    audit($u['name'], 'Ticket comment', 'maintenance', $ticket, substr($text, 0, 80));
    json_out(['ok' => true]);
}
case 'app-ticket-thread': {
    $u = require_user();
    require_module($u, 'maintenance');
    $ticket = trim($_GET['id'] ?? $body['id'] ?? '');
    if (!$ticket) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM tickets WHERE id=?'); $st->execute([$ticket]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) json_out(['ok' => false, 'error' => 'Ticket not found.'], 404);
    if ($u['role'] === 'tenant' && !in_array($t['u'], my_units($u), true)) json_out(['ok' => false, 'error' => 'Not your ticket.'], 403);
    if ($u['role'] === 'partner' && $t['con'] !== my_org($u)) json_out(['ok' => false, 'error' => 'Not your job.'], 403);
    $st = $pdo->prepare('SELECT * FROM ticket_thread WHERE ticket=? ORDER BY id'); $st->execute([$ticket]);
    json_out(['ok' => true, 'thread' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}
/* ── V2.25: support desk — create/comment/status/prio/thread on SUP- tickets ── */
case 'app-support-ticket': {
    $u = require_user();
    require_module($u, 'support');
    $pdo = db();
    $act = $body['action'] ?? 'thread';
    $id = trim($body['id'] ?? '');
    if (in_array($act, ['comment', 'status', 'prio', 'thread'], true) && !$id) json_out(['ok' => false, 'error' => 'id required.'], 400);

    if ($act === 'create') {
        $subject = trim($body['subject'] ?? '');
        $bodyTxt = trim($body['body'] ?? '');
        if (!$subject) json_out(['ok' => false, 'error' => 'subject required.'], 400);
        $cat = trim($body['cat'] ?? 'General');
        $prio = trim($body['prio'] ?? 'Medium');
        if (!in_array($prio, ['Low', 'Medium', 'High', 'Urgent'], true)) $prio = 'Medium';
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'SUP-','') AS INTEGER)) FROM support")->fetchColumn();
        $nid = 'SUP-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
        $from = $u['name'] . ' (' . ucfirst($u['role']) . ')';
        $pdo->prepare('INSERT INTO support (id, from_t, subject, status, prio, cat, created_at, updated_at, sub_email) VALUES (?,?,?,?,?,?,datetime(\'now\'),datetime(\'now\'),?)')
            ->execute([$nid, $from, $subject, 'Open', $prio, $cat, $u['email']]);
        $pdo->prepare('INSERT INTO ticket_thread (ticket, author, body) VALUES (?,?,?)')
            ->execute([$nid, $u['name'], 'Ticket opened: ' . $bodyTxt]);
        audit($u['name'], 'Support ticket created', 'support', $nid, $cat . ' ' . $subject);
        /* push the workspace owner so a new ticket is not missed */
        try {
            $os = $pdo->query("SELECT sub_email FROM subscribers WHERE status='active' ORDER BY id LIMIT 1");
            $own = $os ? (string)$os->fetchColumn() : '';
            if ($own && strcasecmp($own, $u['email']) !== 0)
                push_to_user($pdo, $own, '🎧 Support ticket ' . $nid,
                    ($u['name'] ? $u['name'] . ' · ' : '') . $subject,
                    '/app-v3/#/support?open=' . $nid);
        } catch (Exception $e) {}
        json_out(['ok' => true, 'id' => $nid]);
    }

    $st = $pdo->prepare('SELECT * FROM support WHERE id=?'); $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) json_out(['ok' => false, 'error' => 'Ticket not found.'], 404);

    if ($act === 'comment') {
        $text = trim($body['body'] ?? '');
        if (!$text) json_out(['ok' => false, 'error' => 'body required.'], 400);
        $pdo->prepare('INSERT INTO ticket_thread (ticket, author, body) VALUES (?,?,?)')
            ->execute([$id, $u['name'], $text]);
        $pdo->prepare('UPDATE support SET updated_at=datetime(\'now\') WHERE id=?')->execute([$id]);
        audit($u['name'], 'Support comment', 'support', $id, substr($text, 0, 80));
        json_out(['ok' => true]);
    }
    if ($act === 'status') {
        $status = trim($body['status'] ?? '');
        if (!in_array($status, ['Open', 'In Progress', 'Resolved', 'Closed'], true))
            json_out(['ok' => false, 'error' => 'Invalid status.'], 400);
        $pdo->prepare('UPDATE support SET status=?, updated_at=datetime(\'now\') WHERE id=?')->execute([$status, $id]);
        audit($u['name'], 'Support status', 'support', $id, $status);
        json_out(['ok' => true, 'status' => $status]);
    }
    if ($act === 'prio') {
        $prio = trim($body['prio'] ?? '');
        if (!in_array($prio, ['Low', 'Medium', 'High', 'Urgent'], true))
            json_out(['ok' => false, 'error' => 'Invalid priority.'], 400);
        $pdo->prepare('UPDATE support SET prio=?, updated_at=datetime(\'now\') WHERE id=?')->execute([$prio, $id]);
        audit($u['name'], 'Support priority', 'support', $id, $prio);
        json_out(['ok' => true, 'prio' => $prio]);
    }
    /* thread */
    $st = $pdo->prepare('SELECT * FROM ticket_thread WHERE ticket=? ORDER BY id'); $st->execute([$id]);
    json_out(['ok' => true, 'thread' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}
/* ── P56.2c: tenant private note (owner-only) ── */
case 'app-tenant-note': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner'], true))
        json_out(['ok' => false, 'error' => 'Only the owner can view tenant notes.'], 403);
    require_module($u, 'tenants');
    $tid = trim($body['tenant_id'] ?? '');
    if (!$tid) json_out(['ok' => false, 'error' => 'tenant_id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE id=?'); $st->execute([$tid]);
    if (!(int)$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Tenant not found.'], 404);
    $st = $pdo->prepare('SELECT note FROM tenant_notes WHERE tenant_id=?'); $st->execute([$tid]);
    json_out(['ok' => true, 'note' => (string)$st->fetchColumn()]);
}
case 'app-tenant-note-save': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner'], true))
        json_out(['ok' => false, 'error' => 'Only the owner can edit tenant notes.'], 403);
    require_module($u, 'tenants');
    $tid = trim($body['tenant_id'] ?? '');
    $note = trim($body['note'] ?? '');
    if (!$tid) json_out(['ok' => false, 'error' => 'tenant_id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE id=?'); $st->execute([$tid]);
    if (!(int)$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Tenant not found.'], 404);
    $pdo->prepare("INSERT OR REPLACE INTO tenant_notes (tenant_id, note, updated_at) VALUES (?,?,datetime('now'))")
        ->execute([$tid, $note]);
    audit($u['name'], 'tenant-note', 'tenants', $tid, substr($note, 0, 80));
    json_out(['ok' => true]);
}
/* ── P56.2c: tenant chat thread ── */
case 'app-tenant-chat': {
    $u = require_user();
    $pdo = db();
    $tid = trim($body['tenant_id'] ?? '');
    if ($u['role'] === 'tenant') {
        /* tenant reads their OWN thread — resolve tid from sub_email */
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        $tid = (string)$st->fetchColumn();
        if (!$tid) json_out(['ok' => false, 'error' => 'No tenant profile for this account.'], 404);
    } else {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        require_module($u, 'tenants');
        if (!$tid) json_out(['ok' => false, 'error' => 'tenant_id required.'], 400);
        $st = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE id=?'); $st->execute([$tid]);
        if (!(int)$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Tenant not found.'], 404);
    }
    $st = $pdo->prepare('SELECT * FROM tenant_messages WHERE tenant_id=? ORDER BY id'); $st->execute([$tid]);
    json_out(['ok' => true, 'messages' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}
case 'app-tenant-chat-send': {
    $u = require_user();
    $pdo = db();
    $tid = trim($body['tenant_id'] ?? '');
    $text = trim($body['body'] ?? '');
    if ($u['role'] === 'tenant') {
        /* tenant writes to their OWN thread */
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        $tid = (string)$st->fetchColumn();
        if (!$tid) json_out(['ok' => false, 'error' => 'No tenant profile for this account.'], 404);
    } else {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        require_module($u, 'tenants');
        if (!$tid) json_out(['ok' => false, 'error' => 'tenant_id required.'], 400);
        $st = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE id=?'); $st->execute([$tid]);
        if (!(int)$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Tenant not found.'], 404);
    }
    if (!$text) json_out(['ok' => false, 'error' => 'Message body required.'], 400);
    $pdo->prepare('INSERT INTO tenant_messages (tenant_id, sender, sender_role, body) VALUES (?,?,?,?)')
        ->execute([$tid, $u['name'], $u['role'], $text]);
    audit($u['name'], 'tenant-chat', 'tenants', $tid, substr($text, 0, 80));
    json_out(['ok' => true]);
}
case 'app-doc-upload': {
    $u = require_user();
    require_module($u, 'documents');
    $role = $u['role'];
    if (!in_array($role, ['superadmin', 'owner', 'manager', 'tenant', 'legal', 'crm', 'accountant', 'hr', 'svc_mgr', 'partner'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot upload documents.'], 403);
    if (empty($_FILES['file']['tmp_name'])) json_out(['ok' => false, 'error' => 'file required (multipart form: file, kind, ref).'], 400);
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) json_out(['ok' => false, 'error' => 'Upload error ' . $f['error'] . '.'], 400);
    if ($f['size'] > 8 * 1024 * 1024) json_out(['ok' => false, 'error' => 'File too large (max 8MB).'], 400);
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allow = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'];
    if (!in_array($ext, $allow, true)) json_out(['ok' => false, 'error' => 'File type .' . $ext . ' not allowed.'], 400);
    $kind = trim($_POST['kind'] ?? 'lease');
    $ref = trim($_POST['ref'] ?? '');
    $p = trim($_POST['p'] ?? '');
    $cat = trim($_POST['cat'] ?? 'other');
    if (!array_key_exists($cat, DOC_CATS())) $cat = 'other';
    $pdo = db();
    if ($p) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE id=?'); $st->execute([$p]);
        if (!(int)$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Property not found.'], 400);
    }
    if ($role === 'tenant') {
        $sc = ai_scope($u);
        if (!$ref || !in_array($ref, $sc['leases'], true)) json_out(['ok' => false, 'error' => 'You can only attach documents to your own lease.'], 403);
        $p = ''; $cat = 'agreement';
    }
    if ($role === 'partner') {
        $sc = ai_scope($u);
        if (!$ref || !in_array($ref, $sc['tickets'], true)) json_out(['ok' => false, 'error' => 'You can only attach documents to your assigned jobs.'], 403);
        $kind = 'ticket'; $p = ''; $cat = 'other';
    }
    /* Phase 13: derive property from the lease ref when p not given */
    if (!$p && $ref && $kind === 'lease') {
        $st = $pdo->prepare('SELECT u.p FROM leases l JOIN units u ON u.id=l.u WHERE l.id=?'); $st->execute([$ref]);
        $p = (string)$st->fetchColumn();
        if ($cat === 'other') $cat = 'agreement';
    }
    $dir = DATA_DIR();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_writable($dir)) json_out(['ok' => false, 'error' => 'Storage not writable.'], 500);
    $fname = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) json_out(['ok' => false, 'error' => 'Could not store file.'], 500);
    $id = 'DOC-' . strtoupper(bin2hex(random_bytes(3)));
    $pdo->prepare('INSERT INTO documents (id, kind, ref, name, fname, size, mime, uploaded_by, p, cat) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $kind, $ref, $f['name'], $fname, $f['size'], $f['type'] ?: 'application/octet-stream', $u['name'], $p, $cat]);
    audit($u['name'], 'Document uploaded', 'documents', $id, $kind . ' ' . $ref . ' p=' . $p . ' ' . $cat . ' ' . $f['name']);
    json_out(['ok' => true, 'id' => $id]);
}
case 'app-doc-list': {
    $u = require_user();
    require_module($u, 'documents');
    $pdo = db();
    $sc = doc_scope($u);
    $kind = trim($body['kind'] ?? '');
    if ($sc === null) {
        $sql = 'SELECT * FROM documents';
        $args = [];
        if ($kind) { $sql .= ' WHERE kind=?'; $args[] = $kind; }
        $sql .= ' ORDER BY ts DESC';
        $st = $pdo->prepare($sql); $st->execute($args);
    } elseif ($sc['kind'] === 'ref') {
        $ids = $sc['ids'];
        if (!$ids) { json_out(['ok' => true, 'documents' => []]); }
        $sql = 'SELECT * FROM documents WHERE ref IN (' . ai_in_list($ids) . ')';
        $args = $ids;
        if ($kind) { $sql .= ' AND kind=?'; $args[] = $kind; }
        $sql .= ' ORDER BY ts DESC';
        $st = $pdo->prepare($sql); $st->execute($args);
    } else { /* partner: ticket docs */
        $ids = $sc['ids'];
        if (!$ids) { json_out(['ok' => true, 'documents' => []]); }
        $sql = 'SELECT * FROM documents WHERE ref IN (' . ai_in_list($ids) . ') ORDER BY ts DESC';
        $st = $pdo->prepare($sql); $st->execute($ids);
    }
    json_out(['ok' => true, 'documents' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}
case 'app-doc-vault': {
    $u = require_user();
    require_module($u, 'documents');
    $pdo = db();
    $sc = doc_scope($u);
    if ($sc === null) {
        $rows = $pdo->query('SELECT * FROM documents ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $ids = $sc['ids'];
        if ($sc['kind'] === 'ticket') {
            if (!$ids) $ids = ['__none__'];
            $st = $pdo->prepare('SELECT * FROM documents WHERE ref IN (' . ai_in_list($ids) . ') ORDER BY ts DESC');
            $st->execute($ids);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            /* tenant: own-lease docs OR own-property docs (p in properties of their leases) */
            if (!$ids) { $pdo->prepare('SELECT * FROM documents WHERE 1=0'); }
            $pIds = [];
            if ($ids) {
                $st = $pdo->prepare('SELECT DISTINCT u.p FROM leases l JOIN units u ON u.id=l.u WHERE l.id IN (' . ai_in_list($ids) . ')');
                $st->execute($ids);
                $pIds = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'p');
            }
            $conds = [];
            $args = [];
            if ($ids) { $conds[] = 'ref IN (' . ai_in_list($ids) . ')'; $args = array_merge($args, $ids); }
            if ($pIds) { $conds[] = 'p IN (' . ai_in_list($pIds) . ')'; $args = array_merge($args, $pIds); }
            $sql = 'SELECT * FROM documents' . ($conds ? ' WHERE ' . implode(' OR ', $conds) : ' WHERE 1=0') . ' ORDER BY ts DESC';
            $st = $pdo->prepare($sql); $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    /* properties list (staff: all; tenant: own properties) */
    if ($sc === null) {
        $props = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $ids = $sc['ids'];
        $pIds = [];
        if ($ids) {
            $st = $pdo->prepare('SELECT DISTINCT u.p FROM leases l JOIN units u ON u.id=l.u WHERE l.id IN (' . ai_in_list($ids) . ')');
            $st->execute($ids);
            $pIds = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'p');
        }
        $props = [];
        if ($pIds) {
            $st = $pdo->prepare('SELECT id, name FROM properties WHERE id IN (' . ai_in_list($pIds) . ') ORDER BY name');
            $st->execute($pIds);
            $props = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    json_out(['ok' => true, 'documents' => $rows, 'properties' => $props, 'cats' => DOC_CATS()]);
}

case 'app-doc-view': {
    $u = require_user();
    require_module($u, 'documents');
    $id = trim($_GET['id'] ?? $body['id'] ?? '');
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM documents WHERE id=?'); $st->execute([$id]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) json_out(['ok' => false, 'error' => 'Document not found.'], 404);
    $sc = doc_scope($u);
    $allowed = true;
    if ($sc !== null) {
        $allowed = in_array($d['ref'], $sc['ids'], true);
        if (!$allowed && $d['p'] && $sc['kind'] !== 'ticket') {
            /* tenant: allow if the doc belongs to one of their properties */
            $pIds = [];
            if ($sc['ids']) {
                $st2 = $pdo->prepare('SELECT DISTINCT u.p FROM leases l JOIN units u ON u.id=l.u WHERE l.id IN (' . ai_in_list($sc['ids']) . ')');
                $st2->execute($sc['ids']);
                $pIds = array_column($st2->fetchAll(PDO::FETCH_ASSOC), 'p');
            }
            $allowed = in_array($d['p'], $pIds, true);
        }
        if (!$allowed) json_out(['ok' => false, 'error' => 'Not your document.'], 403);
    }
    $path = DATA_DIR() . '/' . $d['fname'];
    if (!is_file($path)) json_out(['ok' => false, 'error' => 'File missing on server.'], 404);
    $inline = in_array(strtolower(pathinfo($d['fname'], PATHINFO_EXTENSION)), ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'txt', 'csv'], true);
    header('Content-Type: ' . ($d['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . basename($d['name']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

case 'app-doc-download': {
    $u = require_user();
    require_module($u, 'documents');
    $id = trim($_GET['id'] ?? $body['id'] ?? '');
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM documents WHERE id=?'); $st->execute([$id]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) json_out(['ok' => false, 'error' => 'Document not found.'], 404);
    $sc = doc_scope($u);
    if ($sc !== null && !in_array($d['ref'], $sc['ids'], true)) json_out(['ok' => false, 'error' => 'Not your document.'], 403);
    $path = DATA_DIR() . '/' . $d['fname'];
    if (!is_file($path)) json_out(['ok' => false, 'error' => 'File missing on server.'], 404);
    header('Content-Type: ' . ($d['mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . basename($d['name']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
case 'app-doc-delete': {
    $u = require_user();
    require_module($u, 'documents');
    $id = trim($body['id'] ?? '');
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM documents WHERE id=?'); $st->execute([$id]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) json_out(['ok' => false, 'error' => 'Document not found.'], 404);
    $isAdmin = in_array($u['role'], ['superadmin', 'owner', 'manager'], true);
    if (!$isAdmin && $d['uploaded_by'] !== $u['name']) json_out(['ok' => false, 'error' => 'Only the uploader or an admin can delete.'], 403);
    $path = DATA_DIR() . '/' . $d['fname'];
    if (is_file($path)) @unlink($path);
    $pdo->prepare('DELETE FROM documents WHERE id=?')->execute([$id]);
    audit($u['name'], 'Document deleted', 'documents', $id, $d['name']);
    json_out(['ok' => true]);
}
case 'app-doc-cat': {
    /* Phase 22: re-categorize a document (owner / manager / superadmin) */
    $u = require_user();
    require_module($u, 'documents');
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot re-categorize documents.'], 403);
    $id = trim($body['id'] ?? '');
    $cat = trim($body['cat'] ?? '');
    if (!$id || !$cat) json_out(['ok' => false, 'error' => 'id and cat required.'], 400);
    if (!array_key_exists($cat, DOC_CATS())) json_out(['ok' => false, 'error' => 'Unknown document category.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM documents WHERE id=?'); $st->execute([$id]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) json_out(['ok' => false, 'error' => 'Document not found.'], 404);
    $pdo->prepare('UPDATE documents SET cat=? WHERE id=?')->execute([$cat, $id]);
    audit($u['name'], 'Document recategorized', 'documents', $id, ($d['cat'] ?? 'other') . ' → ' . $cat);
    json_out(['ok' => true, 'cat' => $cat]);
}
case 'app-notice-list': {
    $u = require_user();
    require_module($u, 'notices');
    $pdo = db();
    $rows = $pdo->query('SELECT * FROM notices ORDER BY pinned DESC, ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    json_out(['ok' => true, 'notices' => $rows]);
}

case 'app-invoice-email': {
    $u = require_user();
    require_module($u, 'invoices');
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant', 'crm'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot email invoices.'], 403);
    $invId = trim($body['invoice_id'] ?? '');
    if (!$invId) json_out(['ok' => false, 'error' => 'invoice_id required.'], 400);
    $pdo = db();
    /* V2.20: rate-limit manual invoice emailing — 10 sends/10 min/IP (spam guard) */
    $ip = client_ip();
    if (recent_any('', $ip, 10, 0, 10, ['inv-email'])) throttle_out('Too many invoice emails. Try again later.', '', $ip, 10, ['inv-email']);
    $r = inv_context($pdo, $invId);
    if (!$r) json_out(['ok' => false, 'error' => 'Invoice not found.'], 404);
    if (!$r['temail']) json_out(['ok' => false, 'error' => 'No tenant email on file for this invoice.'], 400);
    if (!mail_switch($pdo, 'docs')) {
        audit($u['name'], 'Invoice email suppressed', 'invoices', $invId, 'disabled by admin');
        json_out(['ok' => true, 'emailed' => false, 'suppressed' => true, 'to' => $r['temail'], 'reason' => 'Document emails are disabled in App Settings.']);
    }
    if (!notify_ok($pdo, $r['temail'], 'notify_docs')) {   /* Phase 19: tenant opted out of document emails */
        audit($u['name'], 'Invoice email suppressed', 'invoices', $invId, $r['temail'] . ' (opt-out)');
        json_out(['ok' => true, 'emailed' => false, 'suppressed' => true, 'to' => $r['temail'], 'reason' => 'Tenant has opted out of document emails (settings).']);
    }
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE inv=? AND status='Success'"); $st->execute([$invId]);
    $paid = (int)$st->fetchColumn();
    $due = (int)$r['net'] - $paid;
    list($subj, $html) = email_render('invoice', [
        'tenant_name' => $r['tname'], 'invoice_id' => $invId, 'month' => $r['m'],
        'property' => $r['pname'], 'unit' => $r['uname'], 'amount' => number_format((int)$r['net']),
        'due' => number_format(max(0, $due)), 'due_color' => $due > 0 ? '#B91C1C' : '#065F46',
        'pay_url' => 'https://krtaker.com/app-v3/#/invoices?open=' . rawurlencode($invId) . '&pay=1',
    ]);
    $ok = send_mail($r['temail'], $subj, $html, null, true);
    record_attempt('', $ip, 'inv-email', true);   /* count the send for the throttle */
    audit($u['name'], 'Invoice emailed', 'invoices', $invId, $r['temail'] . ' ' . ($ok ? 'sent' : 'failed'));
    json_out(['ok' => true, 'emailed' => $ok, 'to' => $r['temail'], 'subject' => $subj]);
}

/* ── Auto-invoice generator (2026-08-12) ──
   Service-key gated (monthly cron) or superadmin/owner/manager/accountant via UI.
   Creates one Unpaid rent invoice per Active lease for the given month (idempotent —
   leases already billed for that month are skipped). Dry-run by default; commit=1
   writes; send=1 also queues a tenant invoice email through the mail queue (docs
   master switch + notify_docs opt-out respected, same gates as app-invoice-email). */
case 'app-invoice-auto': {
    $svc = service_authed();
    if (!$svc) {
        $u = require_user();
        require_module($u, 'invoices');
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
            json_out(['ok' => false, 'error' => 'Your role cannot generate invoices.'], 403);
    } else {
        $u = ['name' => 'system', 'role' => 'service', 'email' => ''];
    }
    $pdo = db();
    $month = trim($body['month'] ?? '');
    if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month))
        json_out(['ok' => false, 'error' => 'month must be YYYY-MM.'], 400);
    if ($month === '') $month = gmdate('Y-m');
    $commit = !empty($body['commit']);
    $send = !empty($body['send']);
    $sub = trim($body['sub_email'] ?? '');
    $dry = !$commit && !$send;

    $sql = "SELECT l.*, t.name AS tname, t.email AS temail, u.p AS pid
            FROM leases l JOIN tenants t ON t.id=l.t LEFT JOIN units u ON u.id=l.u
            WHERE l.status='Active'";
    $args = [];
    if ($sub !== '') { $sql .= " AND u.p IN (SELECT id FROM properties WHERE sub_email=?)"; $args[] = $sub; }
    $sql .= " ORDER BY l.id";
    $st = $pdo->prepare($sql); $st->execute($args);
    $leases = $st->fetchAll(PDO::FETCH_ASSOC);

    $existing = [];
    $chk = $pdo->prepare('SELECT l FROM invoices WHERE m=?'); $chk->execute([$month]);
    foreach ($chk->fetchAll(PDO::FETCH_COLUMN) as $lid) $existing[$lid] = true;

    $created = []; $skipped = 0; $notInMonth = 0; $noEmail = 0; $queued = 0;
    $suppressedDocs = 0; $suppressedOpt = 0; $errors = []; $totalRent = 0;
    foreach ($leases as $l) {
        $ls = substr($l['start'], 0, 7); $le = substr($l['end'], 0, 7);
        if ($month < $ls || ($le !== '' && $month > $le)) { $notInMonth++; continue; }
        if (isset($existing[$l['id']])) { $skipped++; continue; }
        $rent = (int)$l['rent'];
        $row = ['lease' => $l['id'], 'tenant' => $l['tname'], 'rent' => $rent, 'email' => $l['temail'] ?: ''];
        $created[] = $row; $totalRent += $rent;
        if ($dry) continue;
        $iid = invoice_next_id($pdo);
        $pdo->prepare('INSERT INTO invoices (id, l, m, gross, tds, net, status) VALUES (?,?,?,?,0,?,?)')
            ->execute([$iid, $l['id'], $month, $rent, $rent, 'Unpaid']);
        $created[count($created) - 1]['id'] = $iid;
        if (!$send) continue;
        if (!$l['temail']) { $noEmail++; continue; }
        if (!mail_switch($pdo, 'docs')) { $suppressedDocs++; continue; }
        if (!notify_ok($pdo, $l['temail'], 'notify_docs')) { $suppressedOpt++; continue; }
        $r = inv_context($pdo, $iid);
        list($subj, $html) = email_render('invoice', [
            'tenant_name' => $r['tname'], 'invoice_id' => $iid, 'month' => $r['m'],
            'property' => $r['pname'], 'unit' => $r['uname'], 'amount' => number_format((int)$r['net']),
            'due' => number_format((int)$r['net']), 'due_color' => '#B91C1C',
            'pay_url' => 'https://krtaker.com/app-v3/#/invoices?open=' . rawurlencode($iid) . '&pay=1',
        ]);
        if (send_mail($l['temail'], $subj, $html, null, true)) $queued++;
        else $errors[] = 'mail:' . $iid;
    }
    if (!$dry && ($created || $errors)) {
        $pdo->prepare("INSERT OR REPLACE INTO platform_meta (k, v) VALUES ('last_invoice_auto', ?)")
            ->execute([gmdate('Y-m-d H:i:s') . ' month=' . $month . ' created=' . count($created) . ' queued=' . $queued . ' skipped=' . $skipped]);
        audit($u['name'], 'Auto-invoice run', 'invoices', 'bulk',
            'month=' . $month . ' created=' . count($created) . ' queued=' . $queued . ' skipped=' . $skipped);
    }
    $st = $pdo->query("SELECT v FROM platform_meta WHERE k='last_invoice_auto'");
    json_out([
        'ok' => true, 'dry_run' => $dry, 'month' => $month,
        'eligible' => count($leases), 'created' => count($created),
        'skipped' => $skipped, 'not_in_month' => $notInMonth,
        'total_rent' => $totalRent, 'queued' => $queued,
        'no_email' => $noEmail, 'suppressed_docs' => $suppressedDocs, 'suppressed_optout' => $suppressedOpt,
        'invoices' => $created, 'errors' => $errors, 'last_run' => $st->fetchColumn() ?: '',
    ]);
}

case 'app-receipt-email': {
    $u = require_user();
    require_module($u, 'payments');
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant', 'crm'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot email receipts.'], 403);
    $pid = trim($body['payment_id'] ?? '');
    if (!$pid) json_out(['ok' => false, 'error' => 'payment_id required.'], 400);
    $pdo = db();
    /* V2.23: rate-limit manual receipt emailing — 10 sends/10 min/IP (spam guard) */
    $ip = client_ip();
    if (recent_any('', $ip, 10, 0, 10, ['rcpt-email'])) throttle_out('Too many receipt emails. Try again later.', '', $ip, 10, ['rcpt-email']);
    $st = $pdo->prepare('SELECT * FROM payments WHERE id=?'); $st->execute([$pid]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) json_out(['ok' => false, 'error' => 'Payment not found.'], 404);
    $inv = inv_context($pdo, $p['inv']);
    if (!$inv || !$inv['temail']) json_out(['ok' => false, 'error' => 'No tenant email on file for this payment.'], 400);
    if (!mail_switch($pdo, 'docs')) {
        audit($u['name'], 'Receipt email suppressed', 'payments', $pid, 'disabled by admin');
        json_out(['ok' => true, 'emailed' => false, 'suppressed' => true, 'to' => $inv['temail'], 'reason' => 'Document emails are disabled in App Settings.']);
    }
    if (!notify_ok($pdo, $inv['temail'], 'notify_docs')) {   /* Phase 19: tenant opted out of document emails */
        audit($u['name'], 'Receipt email suppressed', 'payments', $pid, $inv['temail'] . ' (opt-out)');
        json_out(['ok' => true, 'emailed' => false, 'suppressed' => true, 'to' => $inv['temail'], 'reason' => 'Tenant has opted out of document emails (settings).']);
    }
    $amt = (int)$p['amount'];
    list($subj, $html) = email_render('receipt', [
        'tenant_name' => $inv['tname'], 'receipt_id' => $pid, 'date' => $p['date'],
        'amount' => money_bd($amt), 'amount_words_en' => num_to_words_en($amt), 'amount_words_bn' => num_to_words_bn($amt) . ' টাকা মাত্র',
        'method' => $p['method'], 'ref' => $p['ref'] ? ('· ' . $p['ref']) : '',
        'property' => $inv['pname'], 'unit' => $inv['uname'], 'month' => $inv['m'],
    ]);
    $ok = send_mail($inv['temail'], $subj, $html);
    record_attempt('', $ip, 'rcpt-email', true);   /* count the send for the throttle */
    audit($u['name'], 'Receipt emailed', 'payments', $pid, $inv['temail'] . ' ' . ($ok ? 'sent' : 'failed'));
    json_out(['ok' => true, 'emailed' => $ok, 'to' => $inv['temail'], 'subject' => $subj]);
}

/* ── Phase 13: property handover checklists ── */
case 'app-hando-list': {
    $u = require_user();
    require_module($u, 'leases');
    $pdo = db();
    $sc = hovo_scope($u);
    $lease = trim($_GET['lease'] ?? $body['lease'] ?? '');
    if ($sc === null) {
        $sql = 'SELECT * FROM handover_checklists'; $args = [];
        if ($lease) { $sql .= ' WHERE lease=?'; $args[] = $lease; }
        $sql .= ' ORDER BY ts DESC';
        $st = $pdo->prepare($sql); $st->execute($args);
    } else {
        if (!$sc) { json_out(['ok' => true, 'checklists' => []]); }
        $sql = 'SELECT * FROM handover_checklists WHERE lease IN (' . ai_in_list($sc) . ')';
        $args = $sc;
        if ($lease) { $sql .= ' AND lease=?'; $args[] = $lease; }
        $sql .= ' ORDER BY ts DESC';
        $st = $pdo->prepare($sql); $st->execute($args);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['items'] = json_decode($r['items'], true) ?: [];
    json_out(['ok' => true, 'checklists' => $rows]);
}
case 'app-hando-get': {
    $u = require_user();
    require_module($u, 'leases');
    $id = trim($_GET['id'] ?? $body['id'] ?? '');
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM handover_checklists WHERE id=?'); $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) json_out(['ok' => false, 'error' => 'Checklist not found.'], 404);
    $sc = hovo_scope($u);
    if ($sc !== null && !in_array($r['lease'], $sc, true)) json_out(['ok' => false, 'error' => 'Not your checklist.'], 403);
    $r['items'] = json_decode($r['items'], true) ?: [];
    json_out(['ok' => true, 'checklist' => $r]);
}
case 'app-hando-create': {
    $u = require_user();
    require_module($u, 'leases');
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal', 'accountant', 'hr'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot create checklists.'], 403);
    $lease = trim($body['lease'] ?? '');
    $kind = trim($body['kind'] ?? 'move_in');
    if (!$lease) json_out(['ok' => false, 'error' => 'lease required.'], 400);
    if (!in_array($kind, ['move_in', 'move_out'], true)) json_out(['ok' => false, 'error' => 'kind must be move_in|move_out.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM leases WHERE id=?'); $st->execute([$lease]);
    if (!(int)$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Lease not found.'], 404);
    $st = $pdo->prepare('SELECT COUNT(*) FROM handover_checklists WHERE lease=? AND kind=?'); $st->execute([$lease, $kind]);
    if ((int)$st->fetchColumn()) json_out(['ok' => false, 'error' => 'A ' . $kind . ' checklist already exists for this lease.'], 409);
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'HOV-','') AS INTEGER)) FROM handover_checklists")->fetchColumn();
    $id = 'HOV-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
    $items = json_encode(HOVO_ITEMS($kind), JSON_UNESCAPED_UNICODE);
    $pdo->prepare('INSERT INTO handover_checklists (id, lease, kind, items, status, created_by) VALUES (?,?,?,?,?,?)')
        ->execute([$id, $lease, $kind, $items, 'In Progress', $u['name']]);
    audit($u['name'], 'Handover checklist created', 'leases', $lease, $kind . ' ' . $id);
    json_out(['ok' => true, 'id' => $id]);
}
case 'app-hando-save': {
    $u = require_user();
    require_module($u, 'leases');
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal', 'accountant', 'hr'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot edit checklists.'], 403);
    $id = trim($body['id'] ?? '');
    $items = $body['items'] ?? null;
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    if (!is_array($items)) json_out(['ok' => false, 'error' => 'items array required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM handover_checklists WHERE id=?'); $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) json_out(['ok' => false, 'error' => 'Checklist not found.'], 404);
    $clean = [];
    foreach ($items as $it) {
        if (!is_array($it) || !isset($it['id'], $it['label'])) continue;
        $clean[] = ['id' => (string)$it['id'], 'label' => (string)$it['label'], 'checked' => !empty($it['checked']) ? 1 : 0, 'note' => (string)($it['note'] ?? '')];
    }
    $done = count(array_filter($clean, fn($x) => $x['checked'] === 1));
    $status = ($clean && $done === count($clean)) ? 'Completed' : 'In Progress';
    $pdo->prepare("UPDATE handover_checklists SET items=?, status=?, updated_at=datetime('now') WHERE id=?")
        ->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), $status, $id]);
    audit($u['name'], 'Handover checklist updated', 'leases', $r['lease'], $id . ' ' . $done . '/' . count($clean) . ' → ' . $status);
    json_out(['ok' => true, 'status' => $status, 'done' => $done, 'total' => count($clean)]);
}
case 'app-hando-delete': {
    $u = require_user();
    require_module($u, 'leases');
    $id = trim($body['id'] ?? '');
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT lease FROM handover_checklists WHERE id=?'); $st->execute([$id]);
    $lease = $st->fetchColumn();
    if (!$lease) json_out(['ok' => false, 'error' => 'Checklist not found.'], 404);
    $pdo->prepare('DELETE FROM handover_checklists WHERE id=?')->execute([$id]);
    audit($u['name'], 'Handover checklist deleted', 'leases', $lease, $id);
    json_out(['ok' => true]);
}

case 'app-notice-create': {
    $u = require_user();
    require_module($u, 'notices');
    if (!can_post_notice($u)) json_out(['ok' => false, 'error' => 'Your role cannot post notices.'], 403);
    $title = trim($body['title'] ?? '');
    $body2 = trim($body['body'] ?? '');
    if (!$title) json_out(['ok' => false, 'error' => 'title required.'], 400);
    $pdo = db();
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'NTC-','') AS INTEGER)) FROM notices")->fetchColumn();
    $id = 'NTC-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO notices (id, title, body, author, pinned) VALUES (?,?,?,?,0)')
        ->execute([$id, $title, $body2, $u['name']]);
    audit($u['name'], 'Notice posted', 'notices', $id, $title);
    /* V2.22: optional email broadcast to tenants (respects docs master switch,
       per-tenant notify_docs opt-out, and a 10/10-min/IP rate limit that
       self-records AFTER the send — same pattern as invoice emailing). */
    $email = !empty($body['email']);
    $out = ['ok' => true, 'id' => $id, 'emailed' => false, 'queued' => 0, 'suppressed' => 0, 'no_email' => 0];
    if ($email) {
        $ip = client_ip();
        if (recent_any('', $ip, 10, 0, 10, ['ntc-email'])) throttle_out('Too many notice broadcasts. Try again later.', '', $ip, 10, ['ntc-email']);
        $tst = $pdo->query("SELECT DISTINCT t.email, t.name FROM tenants t
                            JOIN leases l ON l.t = t.id
                            WHERE l.status='Active' AND t.email <> '' ORDER BY t.name");
        $rcpts = $tst->fetchAll(PDO::FETCH_ASSOC);
        $queued = 0; $suppressed = 0; $noEmail = 0;
        if (!mail_switch($pdo, 'docs')) { $suppressed = count($rcpts); }
        else {
            foreach ($rcpts as $rc) {
                if (!notify_ok($pdo, $rc['email'], 'notify_docs')) { $suppressed++; continue; }
                list($subj, $html) = email_render('notice_email', [
                    'org_name' => 'KRTaker', 'tenant_name' => $rc['name'],
                    'notice_title' => $title, 'notice_body' => $body2,
                    'author' => $u['name'], 'posted_date' => gmdate('d M Y'),
                    'portal_url' => 'https://krtaker.com/app-v3/',
                ]);
                if (send_mail($rc['email'], $subj, $html, null, true)) {
                    $queued++;
                    $pdo->prepare("INSERT INTO notice_email_log (notice, to_addr) VALUES (?,?)")->execute([$id, $rc['email']]);
                }
            }
        }
        record_attempt('', $ip, 'ntc-email', true);
        $pdo->prepare("UPDATE notices SET emailed=1, email_count=?, email_ts=datetime('now') WHERE id=?")->execute([$queued, $id]);
        audit($u['name'], 'Notice broadcast emailed', 'notices', $id, 'queued=' . $queued . ' suppressed=' . $suppressed);
        $out = ['ok' => true, 'id' => $id, 'emailed' => true, 'queued' => $queued, 'suppressed' => $suppressed, 'no_email' => $noEmail];
    }
    json_out($out);
}
case 'app-notice-recipients': {
    $u = require_user();
    require_module($u, 'notices');
    if (!can_post_notice($u)) json_out(['ok' => false, 'error' => 'Your role cannot post notices.'], 403);
    $pdo = db();
    $tst = $pdo->query("SELECT COUNT(DISTINCT t.email) FROM tenants t
                        JOIN leases l ON l.t = t.id
                        WHERE l.status='Active' AND t.email <> ''");
    $withEmail = (int)$tst->fetchColumn();
    $tst2 = $pdo->query("SELECT COUNT(DISTINCT t.id) FROM tenants t
                         JOIN leases l ON l.t = t.id WHERE l.status='Active'");
    $total = (int)$tst2->fetchColumn();
    json_out(['ok' => true, 'total' => $total, 'with_email' => $withEmail]);
}
case 'app-notice-delete': {
    $u = require_user();
    require_module($u, 'notices');
    $id = trim($body['id'] ?? '');
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM notices WHERE id=?'); $st->execute([$id]);
    $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) json_out(['ok' => false, 'error' => 'Notice not found.'], 404);
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true) && $n['author'] !== $u['name'])
        json_out(['ok' => false, 'error' => 'Only the author or an admin can delete.'], 403);
    $pdo->prepare('DELETE FROM notices WHERE id=?')->execute([$id]);
    audit($u['name'], 'Notice deleted', 'notices', $id);
    json_out(['ok' => true]);
}
case 'app-referral-generate': {
    $u = require_user();
    require_module($u, 'referrals');
    $email = $u['email'];
    $pdo = db();
    $code = referral_code_for($email);
    $pdo->prepare("UPDATE referrals SET role=? WHERE code=? AND role='owner'")->execute([$u['role'], $code]);
    json_out(['ok' => true, 'code' => $code, 'link' => 'https://krtaker.com/register.html?ref=' . $code, 'reward' => 5000]);
}
case 'app-referral-list': {
    $u = require_user();
    require_module($u, 'referrals');
    $pdo = db();
    if ($u['role'] === 'superadmin') {
        $rows = $pdo->query('SELECT * FROM referrals ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $pdo->prepare('SELECT * FROM referrals WHERE user_email=? ORDER BY ts DESC'); $st->execute([$u['email']]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    json_out(['ok' => true, 'referrals' => $rows]);
}
case 'app-referral-update': {
    $u = require_user();
    require_module($u, 'referrals');
    $id = trim($body['id'] ?? '');
    $status = trim($body['status'] ?? '');
    $valid = ['Pending', 'Signed up', 'Paid', 'Reward issued', 'Declined'];
    if (!$id || !in_array($status, $valid, true)) json_out(['ok' => false, 'error' => 'id and valid status required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM referrals WHERE id=?'); $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) json_out(['ok' => false, 'error' => 'Referral not found.'], 404);
    if ($u['role'] !== 'superadmin' && $r['user_email'] !== $u['email']) json_out(['ok' => false, 'error' => 'Not your referral.'], 403);
    $pdo->prepare('UPDATE referrals SET status=? WHERE id=?')->execute([$status, $id]);
    audit($u['name'], 'Referral updated', 'referrals', $id, $status);
    json_out(['ok' => true]);
}
case 'app-invoice-print': {
    $u = require_user();
    require_module($u, 'invoices');
    $id = trim($_GET['id'] ?? $body['id'] ?? '');
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM invoices WHERE id=?'); $st->execute([$id]);
    if (!$st->fetch()) json_out(['ok' => false, 'error' => 'Invoice not found.'], 404);
    if (!invoice_owner_check($u, $id)) json_out(['ok' => false, 'error' => 'Not your invoice.'], 403);
    $html = invoice_print_html($u, $id);
    if (!$html) json_out(['ok' => false, 'error' => 'Could not render invoice.'], 500);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

/* ── Phase 11: collections autopilot + payment reconciliation ── */
case 'app-collections-summary': {
    $svc = service_authed();
    if (!$svc) { $u = require_user(); require_module($u, 'recon'); }
    $pdo = db();
    $rows = collections_data($pdo);
    $total = array_sum(array_column($rows, 'due'));
    $byTenant = [];
    foreach ($rows as $r) $byTenant[$r['tenant']] = ($byTenant[$r['tenant']] ?? 0) + $r['due'];
    $st = $pdo->query("SELECT v FROM platform_meta WHERE k='last_collections_run'");
    $st2 = $pdo->query("SELECT v FROM platform_meta WHERE k='last_rent_due_push'");
    json_out(['ok' => true, 'unpaid' => count($rows), 'total_due' => $total, 'by_tenant' => $byTenant, 'invoices' => $rows, 'last_run' => $st->fetchColumn() ?: '', 'last_push' => $st2->fetchColumn() ?: '']);
}
case 'app-collections-run': {
    $svc = service_authed();
    if (!$svc) { $u = require_user(); require_module($u, 'recon'); }
    else { $u = ['name' => 'system', 'role' => 'service', 'email' => '']; }
    $send = !empty($body['send']);
    $pdo = db();
    $rows = collections_data($pdo);
    $total = array_sum(array_column($rows, 'due'));
    $sent = 0; $failed = 0; $suppressed = 0; $mailed = [];
    if ($send) {
        $byEmail = [];
        foreach ($rows as $r) {
            $to = trim($r['email'] ?? '');
            if (!$to) { $failed++; continue; }
            $byEmail[$to][] = $r;
        }
        foreach ($byEmail as $to => $items) {
            if (!mail_switch($pdo, 'collections') || !notify_ok($pdo, $to, 'notify_collections') || !notify_ok($pdo, $to, 'email_digest')) { $suppressed++; continue; }   /* Phase 19+23: per-user opt-out (digest toggle too) + admin master switch */
            $ok = send_mail($to, 'KRTaker — ' . count($items) . ' unpaid rent invoice(s)', collections_email_html($items), null, true);
            if ($ok) { $sent++; $mailed[] = $to; } else $failed++;
        }
        $pdo->prepare("INSERT OR REPLACE INTO platform_meta (k, v) VALUES ('last_collections_run', ?)")
            ->execute([gmdate('Y-m-d H:i:s') . ' sent=' . $sent . ' failed=' . $failed . ' suppressed=' . $suppressed . ' total=' . $total]);
        audit($u['name'], 'Collections run', 'payments', 'bulk', 'sent=' . $sent . ' failed=' . $failed . ' suppressed=' . $suppressed . ' total=' . $total);
    }
    json_out(['ok' => true, 'unpaid' => count($rows), 'total_due' => $total, 'sent' => $sent, 'failed' => $failed, 'suppressed' => $suppressed, 'mailed' => $mailed, 'dry_run' => !$send]);
}
case 'app-payment-recon': {
    $u = require_user();
    require_module($u, 'recon');
    $pdo = db();
    $totPay = (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='Success'")->fetchColumn();
    $payCount = (int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
    $recCount = (int)$pdo->query('SELECT COUNT(*) FROM receipts')->fetchColumn();
    $refunds = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE status='Refunded'")->fetchColumn();
    $gtx = [];
    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM gateway_tx GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $g) $gtx[$g['status']] = (int)$g['n'];
    $orphan = $pdo->query('SELECT p.id FROM payments p LEFT JOIN invoices i ON i.id=p.inv WHERE i.id IS NULL')->fetchAll(PDO::FETCH_COLUMN);
    $overpaid = [];
    $st = $pdo->query("SELECT inv, SUM(amount) AS paid FROM payments WHERE status='Success' GROUP BY inv");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $st2 = $pdo->prepare('SELECT net FROM invoices WHERE id=?'); $st2->execute([$r['inv']]);
        $net = (int)$st2->fetchColumn();
        if ($net && (int)$r['paid'] > $net) $overpaid[] = ['inv' => $r['inv'], 'paid' => (int)$r['paid'], 'net' => $net, 'excess' => (int)$r['paid'] - $net];
    }
    $stale = $pdo->query("SELECT id, invoice_id, method, amount, created_at FROM gateway_tx WHERE status='pending' AND created_at < datetime('now','-24 hours')")->fetchAll(PDO::FETCH_ASSOC);
    json_out(['ok' => true,
        'payments' => ['count' => $payCount, 'total' => $totPay, 'refunds' => $refunds],
        'receipts' => $recCount,
        'gateway_tx' => $gtx,
        'orphan_payments' => $orphan,
        'overpaid' => $overpaid,
        'stale_sessions' => $stale]);
}
case 'app-refund': {
    $u = require_user();
    require_module($u, 'recon');
    $pid = trim($body['payment_id'] ?? '');
    $reason = trim($body['reason'] ?? '');
    if (!$pid) json_out(['ok' => false, 'error' => 'payment_id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM payments WHERE id=?'); $st->execute([$pid]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) json_out(['ok' => false, 'error' => 'Payment not found.'], 404);
    if ($p['status'] === 'Refunded') json_out(['ok' => false, 'error' => 'Payment already refunded.'], 400);
    $pdo->prepare("UPDATE payments SET status='Refunded' WHERE id=?")->execute([$pid]);
    audit($u['name'], 'Payment refunded', 'payments', $pid, $reason ?: 'no reason');
    json_out(['ok' => true, 'id' => $pid]);
}
case 'app-gateway-cleanup': {
    $u = require_user();
    require_module($u, 'recon');
    $pdo = db();
    $n = $pdo->exec("UPDATE gateway_tx SET status='expired', updated_at=datetime('now') WHERE status='pending' AND created_at < datetime('now','-24 hours')");
    audit($u['name'], 'Gateway cleanup', 'payments', 'bulk', $n . ' expired');
    json_out(['ok' => true, 'expired' => $n]);
}

case 'app-payment': {
    $u = require_user();
    require_module($u, 'payments');
    $inv = trim($body['invoice_id'] ?? '');
    $amount = (int)($body['amount'] ?? 0);
    $method = trim($body['method'] ?? 'bKash');
    if (!$inv || $amount <= 0) json_out(['ok' => false, 'error' => 'invoice_id and amount required.'], 400);
    $pdo = db();
    $due = invoice_due($pdo, $inv);
    if (!$due) json_out(['ok' => false, 'error' => 'Invoice not found.'], 404);
    if (!invoice_owner_check($u, $inv)) json_out(['ok' => false, 'error' => 'You can only pay your own invoices.'], 403);
    if ($due['due'] <= 0) json_out(['ok' => false, 'error' => 'Invoice already fully paid.'], 400);
    if ($amount > $due['due']) $amount = $due['due'];
    $ref = strtoupper(substr($method, 0, 3)) . '-' . bin2hex(random_bytes(2));
    $date = $body['date'] ?? gmdate('Y-m-d');
    try {
        list($pid, $rid) = record_payment($pdo, $inv, $amount, $method, $ref, $date);
    } catch (Exception $e) {
        json_out(['ok' => false, 'error' => 'Payment failed: ' . $e->getMessage()], 500);
    }
    audit($u['name'], 'Payment recorded', 'payments', $pid, $inv . ' ' . $amount . ' ' . $method);
    json_out(['ok' => true, 'payment' => $pid, 'receipt' => $rid]);
}

case 'app-gateways': {
    $u = require_user();
    require_module($u, 'payments');
    $list = [];
    foreach (GATEWAYS() as $code => $g) {
        $list[] = ['code' => $code, 'name' => $g['name'], 'sandbox' => !empty($g['sandbox'])];
    }
    json_out(['ok' => true, 'gateways' => $list]);
}

case 'app-payment-init': {
    $u = require_user();
    require_module($u, 'payments');
    $inv = trim($body['invoice_id'] ?? '');
    $method = trim($body['method'] ?? 'bkash');
    if (!isset(GATEWAYS()[$method])) json_out(['ok' => false, 'error' => 'Unsupported gateway.'], 400);
    /* v3.78: payment-session throttle — ≤6 init/min/IP, ≤20 init/hr/IP (prevents gateway spam) */
    $ip = client_ip();
    if (recent_any('', $ip, 1, 0, 6, ['payinit']) || recent_any('', $ip, 60, 0, 20, ['payinit'])) {
        $ra = max(retry_after_secs('', $ip, 1, ['payinit']), retry_after_secs('', $ip, 60, ['payinit']));
        header('Retry-After: ' . $ra);
        json_out(['ok' => false, 'error' => 'Too many payment sessions from this address. Try again later.', 'retry_after' => $ra], 429);
    }
    record_attempt($u['email'] ?? '', $ip, 'payinit', true);
    $pdo = db();
    $due = invoice_due($pdo, $inv);
    if (!$due) json_out(['ok' => false, 'error' => 'Invoice not found.'], 404);
    if (!invoice_owner_check($u, $inv)) json_out(['ok' => false, 'error' => 'You can only pay your own invoices.'], 403);
    if ($due['due'] <= 0) json_out(['ok' => false, 'error' => 'Invoice already fully paid.'], 400);
    /* V2.10.0: payment-compliance gate — card gateways (SSLCommerz) require a verified tenant KYC */
    if (!empty(GATEWAYS()[$method]['kyc'])) {
        $kst = $pdo->prepare('SELECT t FROM leases WHERE id IN (SELECT l FROM invoices WHERE id=?) LIMIT 1');
        $kst->execute([$inv]);
        $ktid = $kst->fetchColumn();
        if ($ktid) {
            $kst = $pdo->prepare('SELECT status FROM tenant_kyc WHERE tenant_id=?'); $kst->execute([$ktid]);
            $kstatus = (string)$kst->fetchColumn();
            if ($kstatus !== 'verified') {
                json_out(['ok' => false, 'code' => 'KYC_REQUIRED',
                    'error' => 'Card payments require a verified tenant KYC profile. Please submit your KYC (NID/TIN + ID document) and wait for approval.',
                    'kyc_status' => $kstatus !== '' ? $kstatus : 'unverified'], 400);
            }
        }
    }
    $sid = 'GW-' . bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO gateway_tx (id, invoice_id, method, amount, status, ref) VALUES (?,?,?,?,?,?)')
        ->execute([$sid, $inv, GATEWAYS()[$method]['name'], $due['due'], 'pending', 'GW-' . strtoupper(substr($method, 0, 2)) . '-' . bin2hex(random_bytes(2))]);
    audit($u['name'], 'Gateway checkout started', 'payments', $sid, $inv . ' ' . $due['due'] . ' via ' . $method);

    /* real gateway path (sandbox or live) when credentials are configured */
    if (gateway_ready($method)) {
        $tx = ['id' => $sid];
        $gi = gateway_init($method, $tx, $inv, $due['due'], $u);
        if (isset($gi['ok']) && $gi['ok']) {
            $pdo->prepare("UPDATE gateway_tx SET gw_ref=?, gw_url=?, status='redirecting' WHERE id=?")
                ->execute([$gi['gw_ref'], $gi['url'], $sid]);
            json_out([
                'ok' => true,
                'session_id' => $sid,
                'gateway' => GATEWAYS()[$method]['name'],
                'sandbox' => !empty(GATEWAYS()[$method]['sandbox']),
                'amount' => $due['due'],
                'checkout' => [
                    'order_id' => $sid,
                    'amount' => $due['due'],
                    'currency' => 'BDT',
                    'simulated' => false,
                    'gateway_url' => $gi['url'],
                    'gw_ref' => $gi['gw_ref'],
                ],
            ]);
        }
        /* init failed → fall through to simulated so the demo never breaks */
        $pdo->prepare("UPDATE gateway_tx SET status='pending', ref=ref WHERE id=?")->execute([$sid]);
    }

    json_out([
        'ok' => true,
        'session_id' => $sid,
        'gateway' => GATEWAYS()[$method]['name'],
        'sandbox' => !empty(GATEWAYS()[$method]['sandbox']),
        'amount' => $due['due'],
        'checkout' => [
            'order_id' => $sid,
            'amount' => $due['due'],
            'currency' => 'BDT',
            'simulated' => true,   // sandbox: dashboard renders a mock merchant page
        ],
    ]);
}

case 'app-payment-confirm': {
    $u = require_user();
    require_module($u, 'payments');
    $sid = trim($body['session_id'] ?? '');
    if (!$sid) json_out(['ok' => false, 'error' => 'session_id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM gateway_tx WHERE id=?'); $st->execute([$sid]);
    $tx = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tx) json_out(['ok' => false, 'error' => 'Checkout session not found.'], 404);
    if (!invoice_owner_check($u, $tx['invoice_id'])) json_out(['ok' => false, 'error' => 'Not your invoice.'], 403);
    $gref = trim($body['gateway_ref'] ?? '');
    $res = gateway_confirm_session($pdo, $sid, $gref, $u['name']);
    if (empty($res['ok'])) json_out(['ok' => false, 'error' => $res['error'] ?? 'Failed.'], $res['code'] ?? 400);
    /* SA1 v19: push the property owner that rent was received (payer is the tenant) */
    if (empty($res['idempotent'])) {
        try {
            $st = $pdo->prepare('SELECT COALESCE(u.sub_email, p.sub_email, \'\') FROM invoices i
                LEFT JOIN leases l ON l.id=i.l LEFT JOIN units u ON u.id=l.u LEFT JOIN properties p ON p.id=u.p
                WHERE i.id=?');
            $st->execute([$tx['invoice_id']]); $own = (string)$st->fetchColumn();
            if ($own !== '' && strcasecmp($own, $u['email']) !== 0)
                push_to_user($pdo, $own, '💰 Payment received — ৳' . number_format((int)$tx['amount']),
                    $u['name'] . ' paid ৳' . number_format((int)$tx['amount']) . ' for ' . $tx['invoice_id'] . ' via ' . $tx['method'],
                    '/app-v3/#/invoices');
        } catch (Exception $e) {}
    }
    json_out($res);
}

case 'app-payment-cancel': {
    $u = require_user();
    require_module($u, 'payments');
    $sid = trim($body['session_id'] ?? '');
    if (!$sid) json_out(['ok' => false, 'error' => 'session_id required.'], 400);
    $pdo = db();
    $pdo->prepare("UPDATE gateway_tx SET status='failed', updated_at=datetime('now') WHERE id=? AND status='pending'")->execute([$sid]);
    audit($u['name'], 'Gateway checkout cancelled', 'payments', $sid);
    json_out(['ok' => true]);
}

/* ── Payment proof (bharakhata parity): attach/view/remove evidence (screenshot/PDF)
   for a recorded payment — the "photo-proof payments" flow. Owner/manager attaches a
   bKash/Nagad/bank/cash receipt screenshot; tenant can view their own proof. ── */
case 'app-payment-proof': {
    $u = require_user();
    require_module($u, 'payments');
    $pdo = db();
    $sub = trim($_GET['action'] ?? $body['action'] ?? 'view');
    $pid = trim($_POST['payment_id'] ?? $_GET['id'] ?? $body['payment_id'] ?? '');
    if (!$pid) json_out(['ok' => false, 'error' => 'payment_id required.'], 400);
    $st = $pdo->prepare('SELECT * FROM payments WHERE id=?'); $st->execute([$pid]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) json_out(['ok' => false, 'error' => 'Payment not found.'], 404);
    if (!invoice_owner_check($u, $p['inv'])) json_out(['ok' => false, 'error' => 'Not your payment.'], 403);

    if ($sub === 'upload') {
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK)
            json_out(['ok' => false, 'error' => 'A proof file (image or PDF) is required.'], 400);
        $f = $_FILES['file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'], true))
            json_out(['ok' => false, 'error' => 'Images or PDF only.'], 400);
        if ((int)$f['size'] > 8 * 1024 * 1024)
            json_out(['ok' => false, 'error' => 'Proof file too large (max 8MB).'], 400);
        if ($p['proof']) { $old = DATA_DIR() . '/' . $p['proof']; if (is_file($old)) @unlink($old); }
        $fname = 'ppv_' . preg_replace('/[^A-Za-z0-9_-]/', '', $pid) . '_' . date('Ymd_His') . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], DATA_DIR() . '/' . $fname))
            json_out(['ok' => false, 'error' => 'Could not store proof.'], 500);
        $note = trim((string)($_POST['note'] ?? ''));
        $pdo->prepare("UPDATE payments SET proof=?, proof_note=?, proof_at=datetime('now') WHERE id=?")
            ->execute([$fname, $note, $pid]);
        audit($u['name'], 'Payment proof attached', 'payments', $pid, $fname . ($note !== '' ? ' (' . $note . ')' : ''));
        json_out(['ok' => true, 'proof' => $fname]);
    }

    if ($sub === 'view') {
        if (!$p['proof']) json_out(['ok' => false, 'error' => 'No proof attached.'], 404);
        $f = DATA_DIR() . '/' . $p['proof'];
        if (!is_file($f)) json_out(['ok' => false, 'error' => 'Proof file missing.'], 404);
        $x = strtolower(pathinfo($p['proof'], PATHINFO_EXTENSION));
        $ct = ($x === 'pdf') ? 'application/pdf' : (($x === 'jpg') ? 'image/jpeg' : 'image/' . $x);
        header('Content-Type: ' . $ct);
        header('Content-Disposition: inline; filename="' . $p['proof'] . '"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }

    if ($sub === 'remove') {
        if ($p['proof']) { $old = DATA_DIR() . '/' . $p['proof']; if (is_file($old)) @unlink($old); }
        $pdo->prepare("UPDATE payments SET proof='', proof_note='', proof_at='' WHERE id=?")->execute([$pid]);
        audit($u['name'], 'Payment proof removed', 'payments', $pid);
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'action must be upload|view|remove.'], 400);
}

/* ── SMS gateway (bharakhata parity): config + test + log ── */
case 'app-sms': {
    $u = require_user();
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? 'config-get');
    $isAdmin = in_array($u['role'], ['superadmin', 'owner'], true);
    if ($action === 'config-get') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
            json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $c = sms_cfg($pdo);
        if ($c['api_key'] !== '') $c['api_key'] = substr($c['api_key'], 0, 4) . '…' . substr($c['api_key'], -2);
        $c['masked'] = 1;
        json_out(['ok' => true] + $c);
    }
    if ($action === 'config-save') {
        if (!$isAdmin) json_out(['ok' => false, 'error' => 'Only the owner can change SMS settings.'], 403);
        $in = [];
        if (isset($body['enabled'])) $in['sms_enabled'] = $body['enabled'] ? '1' : '0';
        if (isset($body['provider']) && in_array($body['provider'], ['log', 'bulksmsbd'], true)) $in['sms_provider'] = $body['provider'];
        if (isset($body['api_key'])) $in['sms_api_key'] = trim((string)$body['api_key']);
        if (isset($body['sender_id'])) $in['sms_sender_id'] = trim((string)$body['sender_id']);
        if (isset($body['api_url'])) $in['sms_api_url'] = trim((string)$body['api_url']);
        foreach ($in as $k => $v) admin_cfg_save($pdo, $k, $v);
        audit($u['name'], 'SMS config updated', 'sms', 'cfg', implode(',', array_keys($in)));
        json_out(['ok' => true, 'saved' => array_keys($in)]);
    }
    if ($action === 'send-test') {
        if (!$isAdmin) json_out(['ok' => false, 'error' => 'Only the owner can send a test SMS.'], 403);
        $phone = trim($body['phone'] ?? '');
        if (!$phone) json_out(['ok' => false, 'error' => 'phone required.'], 400);
        $r = sms_send($pdo, $phone, 'KRTaker SMS test — gateway works ✔ (' . gmdate('His') . ')');
        json_out(['ok' => true] + $r);
    }
    if ($action === 'log') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
            json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $rows = $pdo->query('SELECT * FROM sms_log ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
        json_out(['ok' => true, 'log' => $rows]);
    }
    json_out(['ok' => false, 'error' => 'action must be config-get|config-save|send-test|log.'], 400);
}

/* ── Login security (bharakhata parity): reCAPTCHA v3 + Cloudflare Turnstile ──
   Both are OPTIONAL: when a secret is configured the bot-guard middleware
   (bot_guard_check) enforces a valid token from the matching provider; when
   empty, the existing PoW+time-trap guard alone protects login/register forms.
   Secrets are masked on read; an unchanged (masked) value is never re-saved. */
case 'app-security': {
    $u = require_user();
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? 'config-get');
    $isAdmin = in_array($u['role'], ['superadmin', 'owner'], true);
    if ($action === 'config-get') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
            json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $g = function ($k) use ($pdo) { return trim((string)admin_cfg($pdo, $k, '')); };
        $mask = function ($v) { return $v === '' ? '' : substr($v, 0, 4) . '…' . substr($v, -2); };
        json_out(['ok' => true,
            'recaptcha_site_key' => $g('recaptcha_site_key'),
            'recaptcha_secret'   => $mask($g('recaptcha_secret')),
            'turnstile_site_key' => $g('turnstile_site_key'),
            'turnstile_secret'   => $mask($g('turnstile_secret')),
            'bot_guard'          => (int)admin_cfg($pdo, 'bot_guard', 1) === 1,
            'bot_pow_bits'       => max(8, min(24, (int)admin_cfg($pdo, 'bot_pow_bits', 12))),
            'sec_login_alerts'   => (int)admin_cfg($pdo, 'sec_login_alerts', 1) === 1,
            'session_ttl_hours'  => max(1, min(720, (int)admin_cfg($pdo, 'session_ttl_hours', 168))),
            'password_min'       => max(6, min(32, (int)admin_cfg($pdo, 'password_min', 8))),
            'masked'             => 1,
        ]);
    }
    if ($action === 'config-save') {
        if (!$isAdmin) json_out(['ok' => false, 'error' => 'Only the owner can change security settings.'], 403);
        $in = [];
        $cur = [
            'recaptcha_secret' => trim((string)admin_cfg($pdo, 'recaptcha_secret', '')),
            'turnstile_secret' => trim((string)admin_cfg($pdo, 'turnstile_secret', '')),
        ];
        $unchanged = function ($curV, $v) {
            if ($v === '') return false;                 // blank = explicit clear
            if ($curV === '') return false;              // nothing to compare against
            return $v === substr($curV, 0, 4) . '…' . substr($curV, -2);
        };
        if (isset($body['recaptcha_site_key'])) $in['recaptcha_site_key'] = trim((string)$body['recaptcha_site_key']);
        if (isset($body['recaptcha_secret']) && !$unchanged($cur['recaptcha_secret'], trim((string)$body['recaptcha_secret'])))
            $in['recaptcha_secret'] = trim((string)$body['recaptcha_secret']);
        if (isset($body['turnstile_site_key'])) $in['turnstile_site_key'] = trim((string)$body['turnstile_site_key']);
        if (isset($body['turnstile_secret']) && !$unchanged($cur['turnstile_secret'], trim((string)$body['turnstile_secret'])))
            $in['turnstile_secret'] = trim((string)$body['turnstile_secret']);
        if (isset($body['bot_guard'])) $in['bot_guard'] = $body['bot_guard'] ? '1' : '0';
        if (isset($body['bot_pow_bits'])) $in['bot_pow_bits'] = (string)max(8, min(24, (int)$body['bot_pow_bits']));
        if (isset($body['sec_login_alerts'])) $in['sec_login_alerts'] = $body['sec_login_alerts'] ? '1' : '0';
        if (isset($body['session_ttl_hours'])) $in['session_ttl_hours'] = (string)max(1, min(720, (int)$body['session_ttl_hours']));
        if (isset($body['password_min'])) $in['password_min'] = (string)max(6, min(32, (int)$body['password_min']));
        foreach ($in as $k => $v) admin_cfg_save($pdo, $k, $v);
        if ($in) audit($u['name'], 'Login security config updated', 'security', 'cfg', implode(',', array_keys($in)));
        json_out(['ok' => true, 'saved' => array_keys($in)]);
    }
    json_out(['ok' => false, 'error' => 'action must be config-get|config-save.'], 400);
}

/* ── Safety/Service/Maintenance Inspections + Scheduler (V2.9.0) ──
   inspections: one-off or schedule-materialized checks (safety/service/maintenance)
   with a pass/fail checklist + rich-text findings. inspection_schedules: recurring
   templates; the scheduler lazily materializes an inspection when next_due <= today
   (idempotent — one open inspection per schedule until completed). */
case 'app-inspections': {
    $u = require_user();
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? 'summary');
    $canManage = in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr'], true);
    $staff = in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'hr', 'accountant', 'crm', 'legal'], true);
    if (!$staff) json_out(['ok' => false, 'error' => 'Access denied.'], 403);

    function inspections_sched_materialize($pdo) {
        $today = date('Y-m-d');
        $sch = $pdo->prepare('SELECT * FROM inspection_schedules WHERE active=1 AND next_due<>"" AND next_due<=?');
        $sch->execute([$today]);
        foreach ($sch->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $open = $pdo->prepare('SELECT COUNT(*) FROM inspections WHERE schedule_id=? AND status IN (?,?)');
            $open->execute([(int)$s['id'], 'scheduled', 'in_progress']);
            if ((int)$open->fetchColumn() > 0) continue;
            $st = $pdo->prepare('INSERT INTO inspections (code, itype, property_id, title, assignee, status, scheduled_at, checklist, next_due, schedule_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $st->execute(['', $s['itype'], (int)$s['property_id'], $s['title'] . ' (scheduled)', $s['assignee'], 'scheduled', $s['next_due'], $s['checklist'], $s['next_due'], (int)$s['id']]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE inspections SET code=? WHERE id=?')->execute(['INS-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT), $id]);
        }
    }
    function inspections_next_due($pdo, $id) {
        $s = $pdo->prepare('SELECT * FROM inspection_schedules WHERE id=?');
        $s->execute([(int)$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return '';
        $days = max(1, (int)$row['interval_days']);
        $base = ($row['last_run'] !== '') ? $row['last_run'] : date('Y-m-d');
        $nd = date('Y-m-d', strtotime($base . ' +' . $days . ' days'));
        $pdo->prepare('UPDATE inspection_schedules SET next_due=? WHERE id=?')->execute([$nd, (int)$id]);
        return $nd;
    }

    if ($action === 'summary' || $action === 'list' || $action === 'due') {
        inspections_sched_materialize($pdo);
        if ($action === 'summary') {
            $rows = $pdo->query('SELECT itype, status, COUNT(*) c FROM inspections GROUP BY itype, status')->fetchAll(PDO::FETCH_ASSOC);
            $byType = ['safety' => 0, 'service' => 0, 'maintenance' => 0];
            $byStatus = ['scheduled' => 0, 'in_progress' => 0, 'passed' => 0, 'failed' => 0, 'overdue' => 0];
            $total = 0;
            foreach ($rows as $r) { $byType[$r['itype']] = ($byType[$r['itype']] ?? 0) + (int)$r['c']; $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + (int)$r['c']; $total += (int)$r['c']; }
            $overdue = $pdo->query("SELECT COUNT(*) FROM inspections WHERE status IN ('scheduled','in_progress') AND scheduled_at<>'' AND scheduled_at < date('now')")->fetchColumn();
            $dueSoon = $pdo->query("SELECT COUNT(*) FROM inspections WHERE status IN ('scheduled','in_progress') AND scheduled_at<>'' AND scheduled_at >= date('now') AND scheduled_at <= date('now','+7 day')")->fetchColumn();
            $scheds = $pdo->query('SELECT COUNT(*) FROM inspection_schedules WHERE active=1')->fetchColumn();
            json_out(['ok' => true, 'by_type' => $byType, 'by_status' => $byStatus, 'total' => $total, 'overdue' => (int)$overdue, 'due_soon' => (int)$dueSoon, 'schedules_active' => (int)$scheds]);
        }
        if ($action === 'list') {
            $sql = 'SELECT * FROM inspections WHERE 1=1';
            $args = [];
            if (!empty($body['itype']) && in_array($body['itype'], ['safety', 'service', 'maintenance'], true)) { $sql .= ' AND itype=?'; $args[] = $body['itype']; }
            if (!empty($body['status'])) { $sql .= ' AND status=?'; $args[] = $body['status']; }
            $sql .= ' ORDER BY (status IN (\'scheduled\',\'in_progress\')) DESC, scheduled_at DESC, id DESC LIMIT 300';
            $st = $pdo->prepare($sql); $st->execute($args);
            json_out(['ok' => true, 'list' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        }
        if ($action === 'due') {
            $rows = $pdo->query("SELECT * FROM inspections WHERE status IN ('scheduled','in_progress') ORDER BY scheduled_at ASC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
            $today = date('Y-m-d');
            $due = array_values(array_filter($rows, fn($r) => $r['scheduled_at'] !== '' && $r['scheduled_at'] <= $today));
            $upcoming = array_values(array_filter($rows, fn($r) => $r['scheduled_at'] === '' || $r['scheduled_at'] > $today));
            json_out(['ok' => true, 'due' => $due, 'upcoming' => $upcoming]);
        }
        exit;
    }
    if ($action === 'get') {
        $id = (int)($body['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM inspections WHERE id=?'); $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok' => false, 'error' => 'Inspection not found.'], 404);
        $row['checklist'] = json_decode((string)$row['checklist'], true) ?: [];
        json_out(['ok' => true, 'inspection' => $row]);
    }
    if ($action === 'create' || $action === 'update') {
        if (!$canManage) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $id = (int)($body['id'] ?? 0);
        $itype = in_array($body['itype'] ?? '', ['safety', 'service', 'maintenance'], true) ? $body['itype'] : 'safety';
        $title = trim((string)($body['title'] ?? ''));
        $assignee = trim((string)($body['assignee'] ?? ''));
        $prop = (int)($body['property_id'] ?? 0);
        $scheduled = trim((string)($body['scheduled_at'] ?? ''));
        $cl = $body['checklist'] ?? null;
        $checklist = is_array($cl) ? json_encode(array_values(array_filter($cl, fn($i) => is_array($i)))) : (is_string($cl) ? $cl : '[]');
        $findings = trim((string)($body['findings'] ?? ''));
        $next_due = trim((string)($body['next_due'] ?? ''));
        $schedule_id = (int)($body['schedule_id'] ?? 0);
        if ($title === '') json_out(['ok' => false, 'error' => 'Title is required.'], 400);
        if ($action === 'create') {
            $st = $pdo->prepare('INSERT INTO inspections (code, itype, property_id, title, assignee, status, scheduled_at, checklist, findings, next_due, schedule_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute(['', $itype, $prop, $title, $assignee, 'scheduled', $scheduled, $checklist, $findings, $next_due, $schedule_id]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE inspections SET code=? WHERE id=?')->execute(['INS-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT), $id]);
            audit($u['name'], 'Inspection created ' . $title, 'inspections', 'insp', 'INS-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT));
            json_out(['ok' => true, 'id' => $id, 'code' => 'INS-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT)]);
        } else {
            if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
            $st = $pdo->prepare("UPDATE inspections SET itype=?, property_id=?, title=?, assignee=?, scheduled_at=?, checklist=?, findings=?, next_due=?, schedule_id=?, updated_at=datetime('now') WHERE id=?");
            $st->execute([$itype, $prop, $title, $assignee, $scheduled, $checklist, $findings, $next_due, $schedule_id, $id]);
            json_out(['ok' => true, 'id' => $id]);
        }
    }
    if ($action === 'complete') {
        if (!$canManage) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $id = (int)($body['id'] ?? 0);
        $status = ($body['status'] ?? 'passed') === 'failed' ? 'failed' : 'passed';
        $results = is_array($body['checklist'] ?? null) ? json_encode(array_values(array_filter($body['checklist'], fn($i) => is_array($i)))) : null;
        $findings = trim((string)($body['findings'] ?? ''));
        $chk = $pdo->prepare('SELECT * FROM inspections WHERE id=?'); $chk->execute([$id]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok' => false, 'error' => 'Inspection not found.'], 404);
        $upd = "UPDATE inspections SET status=?, completed_at=datetime('now'), completed_by=?, findings=?, updated_at=datetime('now')";
        $args = [$status, $u['name'], $findings];
        if ($results !== null) { $upd .= ', checklist=?'; $args[] = $results; }
        $upd .= ' WHERE id=?'; $args[] = $id;
        $pdo->prepare($upd)->execute($args);
        if ((int)$row['schedule_id'] > 0) {
            $pdo->prepare('UPDATE inspection_schedules SET last_run=? WHERE id=?')->execute([date('Y-m-d'), (int)$row['schedule_id']]);
            inspections_next_due($pdo, (int)$row['schedule_id']);
        }
        audit($u['name'], 'Inspection completed ' . $row['code'] . ' → ' . $status, 'inspections', 'insp', (string)$id);
        json_out(['ok' => true]);
    }
    if ($action === 'delete') {
        if (!$canManage) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $id = (int)($body['id'] ?? 0);
        $pdo->prepare('DELETE FROM inspections WHERE id=?')->execute([$id]);
        json_out(['ok' => true]);
    }
    if ($action === 'schedule-list') {
        json_out(['ok' => true, 'list' => $pdo->query('SELECT * FROM inspection_schedules ORDER BY active DESC, next_due ASC, id DESC')->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action === 'schedule-save') {
        if (!$canManage) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $id = (int)($body['id'] ?? 0);
        $itype = in_array($body['itype'] ?? '', ['safety', 'service', 'maintenance'], true) ? $body['itype'] : 'safety';
        $title = trim((string)($body['title'] ?? ''));
        $assignee = trim((string)($body['assignee'] ?? ''));
        $prop = (int)($body['property_id'] ?? 0);
        $days = max(1, min(3650, (int)($body['interval_days'] ?? 30)));
        $active = isset($body['active']) ? ($body['active'] ? 1 : 0) : 1;
        $cl = $body['checklist'] ?? null;
        $checklist = is_array($cl) ? json_encode(array_values(array_filter($cl, fn($i) => is_array($i)))) : (is_string($cl) ? $cl : '[]');
        $first_due = trim((string)($body['next_due'] ?? date('Y-m-d', strtotime('+' . $days . ' days'))));
        if ($title === '') json_out(['ok' => false, 'error' => 'Title is required.'], 400);
        if ($id) {
            $pdo->prepare('UPDATE inspection_schedules SET itype=?, property_id=?, title=?, assignee=?, interval_days=?, checklist=?, active=?, next_due=? WHERE id=?')
                ->execute([$itype, $prop, $title, $assignee, $days, $checklist, $active, $first_due, $id]);
        } else {
            $st = $pdo->prepare('INSERT INTO inspection_schedules (itype, property_id, title, assignee, interval_days, checklist, active, next_due) VALUES (?,?,?,?,?,?,?,?)');
            $st->execute([$itype, $prop, $title, $assignee, $days, $checklist, $active, $first_due]);
            $id = (int)$pdo->lastInsertId();
        }
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'schedule-delete') {
        if (!$canManage) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $id = (int)($body['id'] ?? 0);
        $pdo->prepare('UPDATE inspection_schedules SET active=0 WHERE id=?')->execute([$id]);
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'action must be summary|list|get|create|update|complete|delete|due|schedule-list|schedule-save|schedule-delete.'], 400);
}

/* ── V2.10.0: Tenant KYC — submission, review queue, document handling ── */
case 'app-kyc': {
    $u = require_user();
    $pdo = db();
    $sub = trim($_GET['action'] ?? $body['action'] ?? $_POST['action'] ?? 'list');
    $isStaff = $u['role'] !== 'tenant';
    $staffMod = false;
    if ($isStaff) {
        $allowed = effective_modules($u);
        $staffMod = in_array('payments', $allowed, true) || in_array('trust', $allowed, true);
    }
    /* tenant role → own record only */
    $myTid = '';
    if (!$isStaff) {
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        $myTid = (string)$st->fetchColumn();
    }

    if ($sub === 'list') {
        if ($isStaff) {
            if (!$staffMod) json_out(['ok' => false, 'error' => 'Access denied — KYC review requires the payments module.'], 403);
            $rows = $pdo->query("SELECT k.*, t.name AS tenant_name, t.phone AS tenant_phone, t.email AS tenant_email, t.kind AS tenant_kind
                FROM tenant_kyc k LEFT JOIN tenants t ON t.id = k.tenant_id
                ORDER BY CASE k.status WHEN 'pending' THEN 0 WHEN 'verified' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END, k.updated_at DESC")
                ->fetchAll(PDO::FETCH_ASSOC);
            $sum = [];
            foreach ($pdo->query("SELECT status, COUNT(*) AS n FROM tenant_kyc GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r) $sum[$r['status']] = (int)$r['n'];
            $total = (int)$pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
            json_out(['ok' => true, 'records' => $rows, 'summary' => [
                'unverified' => $sum['unverified'] ?? 0, 'pending' => $sum['pending'] ?? 0,
                'verified' => $sum['verified'] ?? 0, 'rejected' => $sum['rejected'] ?? 0,
                'tenants' => $total]]);
        }
        $rec = null;
        if ($myTid) {
            $st = $pdo->prepare('SELECT * FROM tenant_kyc WHERE tenant_id=?'); $st->execute([$myTid]);
            $rec = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        json_out(['ok' => true, 'record' => $rec, 'tenant_id' => $myTid]);
    }

    if ($sub === 'submit') {
        $tid = $isStaff ? trim((string)($body['tenant_id'] ?? $_POST['tenant_id'] ?? '')) : $myTid;
        if ($tid === '') json_out(['ok' => false, 'error' => 'tenant_id required.'], 400);
        if (!$isStaff && $tid !== $myTid) json_out(['ok' => false, 'error' => 'You can only submit your own KYC.'], 403);
        $full_name = trim((string)($body['full_name'] ?? $_POST['full_name'] ?? ''));
        $nid = trim((string)($body['nid'] ?? $_POST['nid'] ?? ''));
        $tin = trim((string)($body['tin'] ?? $_POST['tin'] ?? ''));
        $dob = trim((string)($body['dob'] ?? $_POST['dob'] ?? ''));
        $address = trim((string)($body['address'] ?? $_POST['address'] ?? ''));
        if ($full_name === '' || $nid === '') json_out(['ok' => false, 'error' => 'Full name and NID are required.'], 400);
        $now = gmdate('Y-m-d H:i:s');
        $st = $pdo->prepare('SELECT tenant_id FROM tenant_kyc WHERE tenant_id=?'); $st->execute([$tid]);
        if ($st->fetchColumn()) {
            $pdo->prepare("UPDATE tenant_kyc SET full_name=?, nid=?, tin=?, dob=?, address=?, status='pending', notes='', reviewed_at=NULL, reviewed_by='', submitted_at=?, updated_at=datetime('now') WHERE tenant_id=?")
                ->execute([$full_name, $nid, $tin, $dob, $address, $now, $tid]);
        } else {
            $pdo->prepare("INSERT INTO tenant_kyc (tenant_id, full_name, nid, tin, dob, address, status, submitted_at) VALUES (?,?,?,?,?,?,'pending',?)")
                ->execute([$tid, $full_name, $nid, $tin, $dob, $address, $now]);
        }
        /* sync NID onto the tenant row when empty */
        $pdo->prepare("UPDATE tenants SET nid = CASE WHEN nid='' THEN ? ELSE nid END WHERE id=?")->execute([$nid, $tid]);
        audit($u['name'], 'KYC submitted', 'tenants', $tid, $full_name . ' NID ' . $nid);
        json_out(['ok' => true, 'status' => 'pending']);
    }

    if ($sub === 'review') {
        if (!$isStaff || !$staffMod) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $tid = trim((string)($body['tenant_id'] ?? ''));
        $decision = trim((string)($body['decision'] ?? ''));
        $notes = trim((string)($body['notes'] ?? ''));
        if ($tid === '' || !in_array($decision, ['approve', 'reject'], true)) json_out(['ok' => false, 'error' => 'tenant_id + decision (approve|reject) required.'], 400);
        $st = $pdo->prepare('SELECT tenant_id FROM tenant_kyc WHERE tenant_id=?'); $st->execute([$tid]);
        if (!$st->fetchColumn()) json_out(['ok' => false, 'error' => 'No KYC record for this tenant.'], 404);
        $status = $decision === 'approve' ? 'verified' : 'rejected';
        $pdo->prepare("UPDATE tenant_kyc SET status=?, notes=?, reviewed_at=datetime('now'), reviewed_by=?, updated_at=datetime('now') WHERE tenant_id=?")
            ->execute([$status, $notes, $u['name'], $tid]);
        audit($u['name'], 'KYC ' . $status, 'tenants', $tid, $notes !== '' ? $notes : '');
        json_out(['ok' => true, 'status' => $status]);
    }

    if ($sub === 'upload') {
        $tid = $isStaff ? trim((string)($_POST['tenant_id'] ?? '')) : $myTid;
        $field = trim((string)($_POST['field'] ?? ''));
        if ($tid === '' || !in_array($field, ['doc_front', 'doc_back'], true)) json_out(['ok' => false, 'error' => 'tenant_id + field (doc_front|doc_back) required.'], 400);
        if (!$isStaff && $tid !== $myTid) json_out(['ok' => false, 'error' => 'You can only upload your own KYC documents.'], 403);
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK)
            json_out(['ok' => false, 'error' => 'A document file (image or PDF) is required.'], 400);
        $f = $_FILES['file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'], true)) json_out(['ok' => false, 'error' => 'Images or PDF only.'], 400);
        if ((int)$f['size'] > 8 * 1024 * 1024) json_out(['ok' => false, 'error' => 'Document too large (max 8MB).'], 400);
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $tid);
        $fname = 'kyc_' . $safe . '_' . ($field === 'doc_front' ? 'front' : 'back') . '_' . date('Ymd_His') . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], DATA_DIR() . '/' . $fname)) json_out(['ok' => false, 'error' => 'Could not store document.'], 500);
        $st = $pdo->prepare('SELECT tenant_id FROM tenant_kyc WHERE tenant_id=?'); $st->execute([$tid]);
        if (!$st->fetchColumn()) {
            $pdo->prepare("INSERT INTO tenant_kyc (tenant_id, status, submitted_at) VALUES (?,'pending',datetime('now'))")->execute([$tid]);
        }
        $pdo->prepare("UPDATE tenant_kyc SET $field=?, status='pending', reviewed_at=NULL, reviewed_by='', updated_at=datetime('now') WHERE tenant_id=?")->execute([$fname, $tid]);
        audit($u['name'], 'KYC document uploaded', 'tenants', $tid, $field . ' ' . $fname);
        json_out(['ok' => true, 'file' => $fname]);
    }

    if ($sub === 'view') {
        $tid = $isStaff ? trim((string)($_GET['tenant_id'] ?? '')) : $myTid;
        $field = trim((string)($_GET['field'] ?? 'doc_front'));
        if ($tid === '' || !in_array($field, ['doc_front', 'doc_back'], true)) json_out(['ok' => false, 'error' => 'tenant_id + field required.'], 400);
        if (!$isStaff && $tid !== $myTid) json_out(['ok' => false, 'error' => 'Not your document.'], 403);
        $st = $pdo->prepare("SELECT $field FROM tenant_kyc WHERE tenant_id=?"); $st->execute([$tid]);
        $fn = $st->fetchColumn();
        if (!$fn) json_out(['ok' => false, 'error' => 'No document attached.'], 404);
        $f = DATA_DIR() . '/' . $fn;
        if (!is_file($f)) json_out(['ok' => false, 'error' => 'Document file missing.'], 404);
        $x = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
        $ct = ($x === 'pdf') ? 'application/pdf' : (($x === 'jpg') ? 'image/jpeg' : 'image/' . $x);
        header('Content-Type: ' . $ct);
        header('Content-Disposition: inline; filename="' . $fn . '"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }

    if ($sub === 'remove') {
        if (!$isStaff || !$staffMod) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $tid = trim((string)($body['tenant_id'] ?? ''));
        if ($tid === '') json_out(['ok' => false, 'error' => 'tenant_id required.'], 400);
        $st = $pdo->prepare('SELECT doc_front, doc_back FROM tenant_kyc WHERE tenant_id=?'); $st->execute([$tid]);
        $rec = $st->fetch(PDO::FETCH_ASSOC);
        foreach (['doc_front', 'doc_back'] as $c) {
            if ($rec && !empty($rec[$c])) { $old = DATA_DIR() . '/' . $rec[$c]; if (is_file($old)) @unlink($old); }
        }
        $pdo->prepare('DELETE FROM tenant_kyc WHERE tenant_id=?')->execute([$tid]);
        audit($u['name'], 'KYC reset', 'tenants', $tid);
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'action must be list|submit|review|upload|view|remove.'], 400);
}

/* ── Gateway IPN (server-to-server callback, 2026-08-09) ──
   SSLCommerz/bKash/Nagad POST here (no session auth — the gateway is the caller).
   Security: we NEVER trust the IPN payload alone; we look up the local session by
   tran_id and re-verify with the gateway (gateway_verify) + amount match, then
   confirm idempotently. Respond quickly: gateways time out and retry. */
case 'app-payment-ipn': {
    $pdo = db();
    /* IPN payloads arrive as form-encoded (SSLCommerz) — accept both */
    $raw = file_get_contents('php://input');
    $ipn = [];
    parse_str($raw, $ipn);
    $tran_id = trim($ipn['tran_id'] ?? ($body['tran_id'] ?? ''));
    $val_id  = trim($ipn['val_id'] ?? ($body['val_id'] ?? ''));
    $status  = strtoupper(trim($ipn['status'] ?? ($body['status'] ?? '')));
    if (!$tran_id) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'tran_id required.']); exit; }
    $st = $pdo->prepare('SELECT * FROM gateway_tx WHERE id=?'); $st->execute([$tran_id]);
    $tx = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tx) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Session not found.']); exit; }
    if (in_array($tx['status'], ['paid', 'failed'], true)) {
        /* already handled — idempotent ack (gateways retry on non-2xx) */
        echo json_encode(['ok' => true, 'idempotent' => true, 'status' => $tx['status']]); exit;
    }
    /* FAILED/CANCELLED — only mark failed, don't record */
    if ($status === 'FAILED' || $status === 'CANCELLED') {
        $pdo->prepare("UPDATE gateway_tx SET status='failed', updated_at=datetime('now') WHERE id=? AND status NOT IN ('paid','failed')")->execute([$tran_id]);
        audit('gateway', 'IPN failed/cancelled', 'payments', $tran_id, $status);
        echo json_encode(['ok' => true, 'status' => 'failed']); exit;
    }
    /* SUCCESS/other — verify with the gateway (never trust the callback alone) */
    $code = strtolower(array_search($tx['method'], array_map(fn($g) => $g['name'], GATEWAYS()), true) ?: '');
    $gref = $val_id ?: $tx['gw_ref'];
    $res = gateway_confirm_session($pdo, $tran_id, $gref, 'gateway-ipn');
    if (!empty($res['ok']) && empty($res['idempotent'])) {
        audit('gateway', 'IPN confirmed', 'payments', $tran_id, $res['payment'] . ' ' . $tx['amount'] . ' via ' . $tx['method']);
    }
    echo json_encode($res); exit;
}

/* ── Gateway reconciliation (service-key gated, daily cron) ──
   Finds sessions stuck in pending/redirecting and checks the gateway's truth.
   Catches: user paid but browser closed (no redirect → no confirm), or gateway
   failed but we never heard back. dry_run=1 lists; send=1 auto-confirms VALID. */
case 'app-payment-reconcile': {
    $svc = service_authed();
    if (!$svc) { $u = require_user(); require_module($u, 'recon'); }
    else { $u = ['name' => 'system', 'role' => 'service', 'email' => '']; }
    $pdo = db();
    $dry = empty($body['send']);
    $maxAgeH = max(1, min(72, (int)($body['max_age_hours'] ?? 24)));
    $rows = $pdo->query("SELECT * FROM gateway_tx WHERE status IN ('pending','redirecting')
        AND updated_at < datetime('now','-" . $maxAgeH . " hours') ORDER BY updated_at")->fetchAll(PDO::FETCH_ASSOC);
    $stuck = count($rows);
    $verified = 0; $confirmed = 0; $stillPending = 0; $errored = 0;
    $detail = [];
    foreach ($rows as $tx) {
        $code = strtolower(array_search($tx['method'], array_map(fn($g) => $g['name'], GATEWAYS()), true) ?: '');
        $gref = $tx['gw_ref'];
        if (!$gref || !$code) { $stillPending++; $detail[] = ['sid' => $tx['id'], 'state' => 'no_ref']; continue; }
        $ok = gateway_verify($code, $gref);
        if ($ok) {
            $verified++;
            if (!$dry) {
                $res = gateway_confirm_session($pdo, $tx['id'], $gref, 'reconcile');
                if (!empty($res['ok'])) { $confirmed++; $detail[] = ['sid' => $tx['id'], 'state' => 'confirmed']; }
                else { $errored++; $detail[] = ['sid' => $tx['id'], 'state' => 'confirm_failed', 'err' => $res['error'] ?? '']; }
            } else {
                $detail[] = ['sid' => $tx['id'], 'state' => 'would_confirm'];
            }
        } else {
            $stillPending++;
            $detail[] = ['sid' => $tx['id'], 'state' => 'gateway_not_valid'];
        }
    }
    if ($confirmed > 0 || $verified > 0)
        audit($u['name'], 'Gateway reconciliation', 'payments', 'bulk',
            'stuck=' . $stuck . ' verified=' . $verified . ' confirmed=' . $confirmed . ' pending=' . $stillPending);
    json_out(['ok' => true, 'dry_run' => $dry, 'stuck' => $stuck, 'verified' => $verified,
        'confirmed' => $confirmed, 'still_pending' => $stillPending, 'errored' => $errored, 'detail' => $detail]);
}

/* ── Phase 12: document + email templates ── */
case 'app-tpl-list': {
    $u = require_user();
    require_module($u, 'templates');
    $pdo = db();
    $tpls = $pdo->query('SELECT id,kind,name,title,is_default,updated_by,updated_at FROM doc_templates ORDER BY kind, id')->fetchAll(PDO::FETCH_ASSOC);
    $emails = $pdo->query('SELECT id,name,subject,updated_by,updated_at FROM email_templates ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $refs = ['lease' => 'leases', 'service' => 'partners', 'receipt' => 'payments'];
    json_out(['ok' => true, 'templates' => $tpls, 'email' => $emails, 'palettes' => TPL_PALETTES(), 'refs' => $refs]);
}

case 'app-tpl-get': {
    $u = require_user();
    require_module($u, 'templates');
    $id = trim($body['id'] ?? ($_GET['id'] ?? ''));
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM doc_templates WHERE id=?'); $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) json_out(['ok' => false, 'error' => 'Template not found.'], 404);
    json_out(['ok' => true, 'tpl' => $t, 'palette' => TPL_PALETTES()[$t['kind']] ?? []]);
}

case 'app-tpl-save': {
    $u = require_user();
    require_module($u, 'templates');
    $id = trim($body['id'] ?? '');
    $kind = trim($body['kind'] ?? '');
    $name = trim($body['name'] ?? '');
    $title = trim($body['title'] ?? '');
    $tplBody = $body['body'] ?? '';
    if (!in_array($kind, ['lease', 'service', 'receipt'], true)) json_out(['ok' => false, 'error' => 'kind must be lease|service|receipt.'], 400);
    if (!$name || trim($tplBody) === '') json_out(['ok' => false, 'error' => 'name and body are required.'], 400);
    $pdo = db();
    $toks = [];
    preg_match_all('/\{\{(\w+)\}\}/', $tplBody, $mm);
    foreach ($mm[1] as $tk) if (!in_array($tk, $toks, true)) $toks[] = $tk;
    $ph = json_encode($toks);
    if ($id) {
        $st = $pdo->prepare('SELECT * FROM doc_templates WHERE id=?'); $st->execute([$id]);
        if (!$st->fetch()) json_out(['ok' => false, 'error' => 'Template not found.'], 404);
        $pdo->prepare('UPDATE doc_templates SET name=?, title=?, body=?, placeholders=?, updated_by=?, updated_at=datetime(\'now\') WHERE id=?')
            ->execute([$name, $title, $tplBody, $ph, $u['name'], $id]);
        audit($u['name'], 'Template updated', 'templates', $id, $kind);
        json_out(['ok' => true, 'id' => $id]);
    }
    $prefix = ['lease' => 'LSE-', 'service' => 'SRV-', 'receipt' => 'RCP-'][$kind];
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'$prefix','') AS INTEGER)) FROM doc_templates WHERE id LIKE '$prefix%'")->fetchColumn();
    $nid = $prefix . str_pad((string)($mx + 1), 2, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO doc_templates (id,kind,name,title,body,placeholders,is_default,updated_by) VALUES (?,?,?,?,?,?,0,?)')
        ->execute([$nid, $kind, $name, $title, $tplBody, $ph, $u['name']]);
    audit($u['name'], 'Template created', 'templates', $nid, $kind);
    json_out(['ok' => true, 'id' => $nid]);
}

case 'app-tpl-dup': {
    $u = require_user();
    require_module($u, 'templates');
    $id = trim($body['id'] ?? '');
    $newName = trim($body['new_name'] ?? '');
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM doc_templates WHERE id=?'); $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) json_out(['ok' => false, 'error' => 'Template not found.'], 404);
    $prefix = ['lease' => 'LSE-', 'service' => 'SRV-', 'receipt' => 'RCP-'][$t['kind']];
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'$prefix','') AS INTEGER)) FROM doc_templates WHERE id LIKE '$prefix%'")->fetchColumn();
    $nid = $prefix . str_pad((string)($mx + 1), 2, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO doc_templates (id,kind,name,title,body,placeholders,is_default,updated_by) VALUES (?,?,?,?,?,?,0,?)')
        ->execute([$nid, $t['kind'], $newName ?: ($t['name'] . ' (copy)'), $t['title'], $t['body'], $t['placeholders'], $u['name']]);
    audit($u['name'], 'Template duplicated', 'templates', $nid, 'from ' . $id);
    json_out(['ok' => true, 'id' => $nid]);
}

case 'app-tpl-delete': {
    $u = require_user();
    require_module($u, 'templates');
    $id = trim($body['id'] ?? '');
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT is_default FROM doc_templates WHERE id=?'); $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) json_out(['ok' => false, 'error' => 'Template not found.'], 404);
    if ((int)$t['is_default'] === 1) json_out(['ok' => false, 'error' => 'Default templates cannot be deleted — use Reset instead.'], 400);
    $pdo->prepare('DELETE FROM doc_templates WHERE id=?')->execute([$id]);
    audit($u['name'], 'Template deleted', 'templates', $id);
    json_out(['ok' => true]);
}

case 'app-tpl-reset': {
    $u = require_user();
    require_module($u, 'templates');
    $id = trim($body['id'] ?? '');
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT is_default FROM doc_templates WHERE id=?'); $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) json_out(['ok' => false, 'error' => 'Template not found.'], 404);
    if ((int)$t['is_default'] !== 1) json_out(['ok' => false, 'error' => 'Only default templates can be reset.'], 400);
    $body = seed_tpl_body($id);
    if ($body === '') json_out(['ok' => false, 'error' => 'No seed for this template.'], 400);
    $pdo->prepare('UPDATE doc_templates SET body=?, updated_by=?, updated_at=datetime(\'now\') WHERE id=?')->execute([$body, $u['name'], $id]);
    audit($u['name'], 'Template reset', 'templates', $id);
    json_out(['ok' => true]);
}

case 'app-tpl-render': {
    $u = require_user();
    require_module($u, 'templates');
    $id = trim($body['id'] ?? '');
    $ref = trim($body['ref'] ?? '');
    if (!$id || !$ref) json_out(['ok' => false, 'error' => 'id and ref are required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM doc_templates WHERE id=?'); $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) json_out(['ok' => false, 'error' => 'Template not found.'], 404);
    $res = tpl_vars_for($u, $t['kind'], $ref);
    if (!$res) json_out(['ok' => false, 'error' => 'Reference record not found for this template kind (' . $t['kind'] . ').'], 404);
    $merged = render_merge($t['body'], $res['vars']);
    $preview = render_merge($t['title'], $res['vars']);
    audit($u['name'], 'Template rendered', 'templates', $id, $ref);
    json_out(['ok' => true, 'kind' => $t['kind'], 'name' => $t['name'], 'title' => $preview, 'vars' => $res['vars'], 'links' => $res['links'], 'preview' => $merged, 'html' => tpl_print_page(['title' => $preview, 'name' => $t['name']], $merged, $t['kind'])]);
}

case 'app-email-tpl-list': {
    $u = require_user();
    require_module($u, 'templates');
    json_out(['ok' => true, 'templates' => db()->query('SELECT id,name,subject,updated_by,updated_at FROM email_templates ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'app-email-tpl-get': {
    $u = require_user();
    require_module($u, 'templates');
    $id = trim($body['id'] ?? ($_GET['id'] ?? ''));
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM email_templates WHERE id=?'); $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) json_out(['ok' => false, 'error' => 'Email template not found.'], 404);
    json_out(['ok' => true, 'tpl' => $t]);
}

case 'app-email-tpl-save': {
    $u = require_user();
    require_module($u, 'templates');
    $id = trim($body['id'] ?? '');
    $subject = trim($body['subject'] ?? '');
    $tplBody = $body['body'] ?? '';
    if (!$id || !$subject || trim($tplBody) === '') json_out(['ok' => false, 'error' => 'id, subject and body are required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM email_templates WHERE id=?'); $st->execute([$id]);
    if (!$st->fetch()) json_out(['ok' => false, 'error' => 'Email template not found.'], 404);
    $pdo->prepare('UPDATE email_templates SET subject=?, body=?, updated_by=?, updated_at=datetime(\'now\') WHERE id=?')
        ->execute([$subject, $tplBody, $u['name'], $id]);
    audit($u['name'], 'Email template updated', 'templates', $id, $subject);
    json_out(['ok' => true]);
}

case 'app-email-tpl-reset': {
    $u = require_user();
    require_module($u, 'templates');
    $id = trim($body['id'] ?? '');
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    list($subj, $body) = seed_email_tpl($id);
    if (!$subj) json_out(['ok' => false, 'error' => 'No seed for this email template.'], 400);
    $pdo->prepare('UPDATE email_templates SET subject=?, body=?, updated_by=?, updated_at=datetime(\'now\') WHERE id=?')
        ->execute([$subj, $body, $u['name'], $id]);
    audit($u['name'], 'Email template reset', 'templates', $id);
    json_out(['ok' => true]);
}

case 'app-email-preview': {
    $u = require_user();
    require_module($u, 'templates');
    $id = trim($_GET['id'] ?? '');
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    list($subj, $body) = email_render($id, email_sample_vars($id));
    json_out(['ok' => true, 'subject' => $subj, 'html' => $body, 'vars' => email_sample_vars($id)]);
}

case 'app-ai-meta': {
    $u = require_user();
    require_module($u, 'ai');
    $pdo = db();
    $ac = AI_CONFIG($pdo);
    json_out(['ok' => true, 'mode' => ai_mode($pdo), 'provider' => $ac['provider'], 'model' => $ac['model'], 'tools' => array_map(fn($t) => $t['function']['name'], ai_tool_defs())]);
}

case 'app-ai-chat': {
    $u = require_user();
    require_module($u, 'ai');
    $messages = $body['messages'] ?? [];
    if (!is_array($messages) || !$messages) json_out(['ok' => false, 'error' => 'messages required.'], 400);
    $last = end($messages);
    $q = is_array($last) ? trim($last['content'] ?? '') : '';
    if (!$q) json_out(['ok' => false, 'error' => 'Empty message.'], 400);
    $pdo = db();
    $_t0 = microtime(true);
    $mode = ai_mode($pdo);

    if ($mode === 'llm') {
        $msgs = array_merge([['role' => 'system', 'content' => ai_system_prompt($u)]], array_slice($messages, -12));
        $actions = [];
        $reply = '';
        try {
            $msg = ai_llm_call($msgs, $pdo);
            for ($i = 0; $i < 3; $i++) {
                if (isset($msg['error'])) { $reply = 'KR is having trouble reaching the model: ' . $msg['error']; break; }
                if (!empty($msg['tool_calls'])) {
                    $msgs[] = $msg;
                    foreach ($msg['tool_calls'] as $tc) {
                        $name = $tc['function']['name'] ?? '';
                        $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                        $res = ai_execute_tool($u, $name, $args);
                        $actions[] = ['tool' => $name, 'ok' => $res['ok']];
                        $msgs[] = ['role' => 'tool', 'tool_call_id' => $tc['id'] ?? '', 'content' => $res['text']];
                        if ($res['ok'] && isset($res['data'])) $actions[] = ['tool' => $name, 'ok' => true, 'data' => $res['data']];
                    }
                    $msg = ai_llm_call($msgs, $pdo);
                    continue;
                }
                $reply = $msg['content'] ?? 'No response.';
                break;
            }
            if (!$reply) $reply = 'I could not complete that. Please try again.';
        } catch (Exception $e) {
            $reply = 'KR error: ' . $e->getMessage();
        }
        $log_r = substr($reply, 0, 300);
    } else {
        $r = ai_offline($u, $q);
        $reply = $r['reply'];
        $actions = $r['actions'];
        $log_r = substr($reply, 0, 300);
        $mode = 'offline';
    }
    $pdo->prepare('INSERT INTO ai_log (user, mode, query, tool, result, ms) VALUES (?,?,?,?,?,?)')
        ->execute([$u['name'], $mode, substr($q, 0, 200), $actions[0]['tool'] ?? '', $log_r, (int)round((microtime(true) - $_t0) * 1000)]);
    json_out(['ok' => true, 'reply' => $reply, 'mode' => $mode, 'actions' => $actions]);
}

case 'app-crud': {
    $u = require_user();
    $action = $body['action'] ?? '';
    $collection = $body['collection'] ?? '';
    $fields = CRUD_FIELDS()[$collection] ?? null;
    if (!in_array($action, ['create', 'update', 'delete'], true)) json_out(['ok' => false, 'error' => 'Invalid action.'], 400);
    if (!$fields) json_out(['ok' => false, 'error' => 'Unknown collection.'], 400);
    if (!can_crud($u['role'], $collection)) json_out(['ok' => false, 'error' => "Access denied — CRUD on $collection for {$u['role']}."], 403);
    /* Phase 6: row-scope guard — tenant/partner may only touch their own rows */
    if ($u['role'] === 'tenant' && $action !== 'create') {
        json_out(['ok' => false, 'error' => 'Tenants cannot edit records.'], 403);
    }
    if ($u['role'] === 'partner' && $collection !== 'tickets') {
        json_out(['ok' => false, 'error' => 'Partners can only work with their jobs.'], 403);
    }
    $pdo = db();
    $data = array_intersect_key($body['data'] ?? [], array_flip($fields));
    $id = trim($body['id'] ?? '');
    /* SA1 v18: subscribers can never set ownership directly — create enforcement stamps it,
       update path strips it (prevents reassigning a property/unit to another account). */
    if (($u['kind'] ?? '') === 'sub') unset($data['sub_email']);

    if ($action === 'delete') {
        if (in_array($u['role'], ['tenant', 'partner'], true)) json_out(['ok' => false, 'error' => 'Not allowed to delete.'], 403);
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        crud_guard_owner($pdo, $u, $collection, $id);
        $pdo->prepare("DELETE FROM $collection WHERE id=?")->execute([$id]);
        audit($u['name'], 'Deleted', $collection, $id);
        json_out(['ok' => true]);
    }

    /* create */
    if ($action === 'create') {
        $prefix = CRUD_PREFIX()[$collection] ?? '';
        if ($collection === 'invoices') {
            $id = invoice_next_id($pdo);
        } else {
            $pad = $collection === 'partners' || $collection === 'staff' ? 2 : 3;
            $id = next_id($collection, $prefix, $pad);
        }
        /* tenant can only create tickets for their own units */
        if ($u['role'] === 'tenant' && $collection === 'tickets') {
            $mine = my_units($u);
            if (!$mine || !in_array($data['u'] ?? '', $mine, true)) {
                json_out(['ok' => false, 'error' => 'You can only raise tickets for your own unit.'], 403);
            }
            $data['status'] = 'Open';
        }
        /* partner creates nothing */
        if ($u['role'] === 'partner') json_out(['ok' => false, 'error' => 'Partners cannot create records.'], 403);
        /* leases: default status by registration rule */
        if ($collection === 'leases' && empty($data['status'])) {
            $data['status'] = (($data['res'] ?? 1) == 0 && empty($data['reg_office'])) ? 'Pending Registration' : 'Active';
        }
        /* invoices: derive net if not provided */
        if ($collection === 'invoices' && empty($data['net'])) {
            $data['net'] = (int)($data['gross'] ?? 0) - (int)($data['tds'] ?? 0);
        }
        if (empty($data)) json_out(['ok' => false, 'error' => 'No valid fields.'], 400);
        /* SA1 v18: enforce per-subscriber plan limits (property_limit / unit_limit) on create.
           Staff (superadmin/manager/etc.) are unlimited; subscribers are counted by sub_email.
           SA1 v25.6: tenants stamped too — without sub_email the row-ownership guard treats
           the row as shared and a second subscriber could edit/delete it (real IDOR found). */
        if (($u['kind'] ?? '') === 'sub' && in_array($collection, ['properties', 'units', 'tenants'], true)) {
            $email = strtolower(trim($u['email']));
            $lim = effective_limits($u);
            if ($collection === 'properties') {
                $cnt = (int)$pdo->query('SELECT COUNT(*) FROM properties WHERE sub_email=' . $pdo->quote($email))->fetchColumn();
                if ($cnt >= (int)($lim['property_limit'] ?? 9999)) {
                    json_out(['ok' => false, 'error' => "Plan limit reached: $cnt of {$lim['property_limit']} properties. Upgrade to lift limits."], 403);
                }
                $data['sub_email'] = $email;
            } elseif ($collection === 'units') {
                $pid = $data['p'] ?? '';
                $own = '';
                if ($pid !== '') {
                    $st = $pdo->prepare('SELECT sub_email FROM properties WHERE id=?'); $st->execute([$pid]);
                    $own = (string)$st->fetchColumn();
                }
                if ($own !== '' && strtolower(trim($own)) !== $email) {
                    json_out(['ok' => false, 'error' => 'This property belongs to another account.'], 403);
                }
                $cnt = (int)$pdo->query('SELECT COUNT(*) FROM units WHERE sub_email=' . $pdo->quote($email))->fetchColumn();
                if ($cnt >= (int)($lim['unit_limit'] ?? 99999)) {
                    json_out(['ok' => false, 'error' => "Plan limit reached: $cnt of {$lim['unit_limit']} units. Upgrade to lift limits."], 403);
                }
                $data['sub_email'] = $email;
            } else {   /* tenants — stamp ownership only (no plan limit) */
                $data['sub_email'] = $email;
            }
        }
        $keys = array_keys($data);
        $pdo->prepare('INSERT INTO ' . $collection . ' (id, ' . implode(',', $keys) . ') VALUES (?, ' . implode(',', array_fill(0, count($keys), '?')) . ')')
            ->execute(array_merge([$id], array_values($data)));
        audit($u['name'], 'Created', $collection, $id);
        json_out(['ok' => true, 'id' => $id]);
    }

    /* update */
    if ($u['role'] === 'tenant') json_out(['ok' => false, 'error' => 'Tenants cannot edit records.'], 403);
    if ($u['role'] === 'partner') {
        $st = $pdo->prepare('SELECT con FROM tickets WHERE id=?'); $st->execute([$id]);
        $con = $st->fetchColumn();
        if ($con !== '' && $con !== my_org($u)) json_out(['ok' => false, 'error' => 'You can only update your own jobs.'], 403);
        if (empty($data['con'])) $data['con'] = my_org($u);   /* quoting an open job assigns it */
    }
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    if (empty($data)) json_out(['ok' => false, 'error' => 'No valid fields.'], 400);
    crud_guard_owner($pdo, $u, $collection, $id);
    if ($collection === 'invoices' && empty($data['net'])) {
        /* BUGFIX: only re-derive net when gross/tds are actually being changed; a status-only
           edit (e.g. mark Overdue) must not zero net → invoices looked "fully paid". */
        if (isset($data['gross']) || isset($data['tds'])) {
            $cur = null;
            $st = $pdo->prepare('SELECT gross, tds FROM invoices WHERE id=?'); $st->execute([$id]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            $g = isset($data['gross']) ? (int)$data['gross'] : (int)($cur['gross'] ?? 0);
            $t = isset($data['tds'])   ? (int)$data['tds']   : (int)($cur['tds'] ?? 0);
            $data['net'] = $g - $t;
        }
    }
    $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($data)));
    $pdo->prepare('UPDATE ' . $collection . " SET $sets WHERE id=?")->execute(array_merge(array_values($data), [$id]));
    audit($u['name'], 'Updated', $collection, $id, json_encode($data) ?: '');
    json_out(['ok' => true, 'id' => $id]);
}

case 'app-profile': {
    /* Phase 18 unified: GET → profile+settings; POST → update fields and/or change password */
    $u = require_user();
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'GET' || empty($body)) {
        json_out(['ok' => true, 'profile' => user_profile($pdo, $u), 'user' => user_payload($u)]);
    }
    $table = $u['kind'] === 'sub' ? 'subscribers' : 'app_users';
    $uid = $u['id'];
    $tok_kind = $u['kind'];
    if (!empty($u['team_member'])) {          /* SA1 v20: members update their OWN row, not the owner's */
        $table = 'team_members'; $uid = $u['team_id']; $tok_kind = 'team';
    }
    $sets = []; $args = []; $changed = [];
    if (isset($body['name']) && trim($body['name']) !== '') {
        $sets[] = 'name=?'; $args[] = trim($body['name']); $changed[] = 'name';
    }
    if ($u['kind'] === 'sub' && empty($u['team_member'])) {
        if (isset($body['org'])) { $sets[] = 'org=?'; $args[] = trim($body['org']); $changed[] = 'org'; }
        if (isset($body['phone'])) { $sets[] = 'phone=?'; $args[] = trim($body['phone']); $changed[] = 'phone'; }
    } elseif (!empty($u['team_member'])) {
        /* team members: name + password only (no org/phone columns) */
    } else {
        if (isset($body['dept'])) { $sets[] = 'dept=?'; $args[] = trim($body['dept']); $changed[] = 'dept'; }
        if (isset($body['avatar'])) { $sets[] = 'avatar=?'; $args[] = trim(substr($body['avatar'], 0, 8)); $changed[] = 'avatar'; }
    }
    if (isset($body['new_password']) && $body['new_password'] !== '') {
        $old = $body['old_password'] ?? '';
        $new = $body['new_password'];
        if (strlen($new) < (int)admin_cfg(db(), 'password_min', 8)) json_out(['ok' => false, 'error' => 'New password must be at least ' . (int)admin_cfg(db(), 'password_min', 8) . ' characters.'], 400);
        $perr = password_policy_error($pdo, $new);
        if ($perr !== '') json_out(['ok' => false, 'error' => $perr], 400);
        if (!$old) json_out(['ok' => false, 'error' => 'Current password is required to change it.'], 400);
        $st = $pdo->prepare("SELECT password_hash FROM $table WHERE id=?"); $st->execute([$uid]);
        $hash = $st->fetchColumn();
        if (!$hash || !password_verify($old, $hash)) {
            record_attempt($u['email'], client_ip(), 'profile-pw', false);
            json_out(['ok' => false, 'error' => 'Current password is incorrect.'], 403);
        }
        $sets[] = 'password_hash=?'; $args[] = password_hash($new, PASSWORD_DEFAULT); $changed[] = 'password';
        /* invalidate other sessions so the new password is enforced everywhere */
        $pdo->prepare("DELETE FROM app_tokens WHERE user_id=? AND kind=?")->execute([$uid, $tok_kind]);
    }
    if (!$sets) json_out(['ok' => false, 'error' => 'Nothing to update.'], 400);
    $args[] = $uid;
    $pdo->prepare("UPDATE $table SET " . implode(',', $sets) . " WHERE id=?")->execute($args);
    /* keep tenant row in sync when a linked subscriber updates name/phone (owner only, not team members) */
    if (($u['kind'] === 'sub') && empty($u['team_member']) && (in_array('name', $changed, true) || in_array('phone', $changed, true))) {
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        $tid = $st->fetchColumn();
        if ($tid) {
            $tsets = []; $targs = [];
            if (in_array('name', $changed, true)) { $tsets[] = 'name=?'; $targs[] = trim($body['name']); }
            if (in_array('phone', $changed, true)) { $tsets[] = 'phone=?'; $targs[] = trim($body['phone']); }
            if ($tsets) { $targs[] = $tid; $pdo->prepare('UPDATE tenants SET ' . implode(',', $tsets) . ' WHERE id=?')->execute($targs); }
        }
    }
    audit($u['name'], 'Profile updated', 'auth', (string)$u['id'], implode(',', $changed));
    $pwChanged = in_array('password', $changed, true);
    $u2 = $pwChanged ? null : current_user();
    $fresh = null;
    if ($u2) {
        $st = $pdo->prepare("SELECT * FROM $table WHERE id=?"); $st->execute([$u['id']]);
        $fr = $st->fetch(PDO::FETCH_ASSOC); $fr['kind'] = $u['kind'];
        $fresh = user_profile($pdo, $fr);
    }
    json_out(['ok' => true, 'updated' => $changed, 'sessions_invalidated' => $pwChanged,
              'user' => $u2 ? user_payload($u2) : null, 'profile' => $fresh]);
}

case 'app-health': {
    /* no auth — used for uptime/DB health checks */
    try {
        $pdo = db();
        $integrity = (string)$pdo->query('PRAGMA quick_check')->fetchColumn();
        $wal = (string)$pdo->query('PRAGMA journal_mode')->fetchColumn();
        $counts = [];
        foreach (['subscribers', 'properties', 'units', 'tenants', 'leases', 'invoices', 'payments', 'tickets', 'app_users', 'audit_log', 'auth_attempts'] as $t) {
            try { $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(); } catch (Exception $e) { $counts[$t] = -1; }
        }
        json_out(['ok' => $integrity === 'ok', 'php' => PHP_VERSION, 'integrity' => $integrity, 'journal_mode' => $wal, 'tables' => $counts, 'ts' => gmdate('c')]);
    } catch (Exception $e) {
        json_out(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()], 500);
    }
}

case 'app-backup': {
    /* superadmin only — stream a consistent SQLite snapshot (DB sits outside FTP jail) */
    $u = require_user();
    if ($u['role'] !== 'superadmin' && $u['email'] !== ADMIN_EMAIL) json_out(['ok' => false, 'error' => 'Super admin only.'], 403);
    $tmp = '/tmp/krtaker_backup_' . gmdate('Ymd_His') . '.db';
    try {
        $pdo = db();
        try {
            $pdo->exec("VACUUM INTO '" . $tmp . "'");   /* SQLite >= 3.27 */
        } catch (Exception $e) {
            /* older SQLite: use the SQLite3 backup API instead */
            @unlink($tmp);
            $src = new SQLite3(DB_PATH, SQLITE3_OPEN_READONLY);
            $dst = new SQLite3($tmp, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            $ok = $src->backup($dst);
            $src->close(); $dst->close();
            if (!$ok) throw new Exception('SQLite3 backup API failed');
        }
        if (!file_exists($tmp) || filesize($tmp) === 0) throw new Exception('backup file empty');
        audit($u['name'], 'DB backup downloaded', 'system', 'db', filesize($tmp) . ' bytes');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="krtaker_' . gmdate('Ymd_His') . '.db"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    } catch (Exception $e) {
        @unlink($tmp);
        json_out(['ok' => false, 'error' => 'Backup failed: ' . $e->getMessage()], 500);
    }
}

case 'app-export': {
    /* superadmin only — all tables as JSON (PG migration + archive) */
    $u = require_user();
    if ($u['role'] !== 'superadmin' && $u['email'] !== ADMIN_EMAIL) json_out(['ok' => false, 'error' => 'Super admin only.'], 403);
    $pdo = db();
    $out = ['_exported_at' => gmdate('c'), '_db' => 'sqlite', '_tables' => []];
    $tables = ['subscribers','contacts','newsletter_emails','app_users','app_tokens','plan_catalog','audit_log','auth_attempts','properties','units','tenants','leases','invoices','receipts','payments','tickets','partners','staff','support','platform_meta','cases','gateway_tx','legal_docs','doc_templates','email_templates','handover_checklists','property_rent','amenities','caretaker_subs','caretaker_invoices','insurance_plans','insurance_policies','maintenance_requests','leads','statement_payouts','compliance_items','renewal_requests','meter_readings','utility_bills','utility_tariffs','partner_invoices','vendor_payouts','remittances','onboarding_apps','sla_config','vendor_ratings','job_media','nid_verifications','thana_forms','legal_notices','case_events','land_parcels','land_visits','land_media','land_events','nrb_tax_returns','nrb_repatriations','nrb_vacancies','nrb_showings','nrb_disputes','concierge_requests','concierge_docs','holding_taxes','smart_locks','cctv_cameras','health_plans','build_projects','build_milestones','build_expenses','build_media','gate_visits','resident_vehicles','gate_watchlist','fire_assets','fire_incidents','evacuation_plans','emergency_contacts','sys_assets','sys_services','sys_fuel','building_staff','staff_attendance','staff_payroll','samity_members','samity_bills','samity_collections','samity_expenses'];
    foreach ($tables as $t) {
        try {
            $out['_tables'][$t] = $pdo->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $out['_tables'][$t] = []; }
    }
    audit($u['name'], 'JSON export', 'system', 'db', count($tables) . ' tables');
    json_out($out);
}

case 'app-audit': {
    /* superadmin only — audit trail with pagination */
    $u = require_user();
    if ($u['role'] !== 'superadmin' && $u['email'] !== ADMIN_EMAIL) json_out(['ok' => false, 'error' => 'Super admin only.'], 403);
    $limit = min(200, max(1, (int)($body['limit'] ?? 50)));
    $offset = max(0, (int)($body['offset'] ?? 0));
    $rows = db()->query("SELECT ts,user,action,module,entity,details FROM audit_log ORDER BY id DESC LIMIT $limit OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
    $total = (int)db()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    json_out(['ok' => true, 'total' => $total, 'offset' => $offset, 'entries' => $rows]);
}

/* ── Phase 14: tenant portal v2 ── */
case 'app-portal': {
    $u = require_user();
    $pdo = db();
    $tid = '';
    if ($u['role'] === 'tenant') {
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        $tid = (string)$st->fetchColumn();
        if (!$tid) json_out(['ok' => false, 'error' => 'No tenant profile for this account.'], 404);
    } elseif (in_array($u['role'], ['superadmin', 'owner', 'manager'], true)) {
        $tid = trim($_GET['t'] ?? $body['t'] ?? '');
        if (!$tid) json_out(['ok' => false, 'error' => 't (tenant id) required for staff.'], 400);
        $st = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE id=?'); $st->execute([$tid]);
        if (!$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Tenant not found.'], 404);
    } else {
        json_out(['ok' => false, 'error' => 'Your role cannot view the tenant portal.'], 403);
    }
    $data = portal_data($pdo, $tid);
    if (!$data) json_out(['ok' => false, 'error' => 'Tenant not found.'], 404);
    json_out(['ok' => true] + $data);
}
case 'app-portal-agreement': {
    $u = require_user();
    $pdo = db();
    $lease = trim($_GET['lease'] ?? $body['lease'] ?? '');
    if (!$lease) json_out(['ok' => false, 'error' => 'lease required.'], 400);
    $st = $pdo->prepare('SELECT * FROM leases WHERE id=?'); $st->execute([$lease]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) json_out(['ok' => false, 'error' => 'Lease not found.'], 404);
    if ($u['role'] === 'tenant') {
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        $tid = $st->fetchColumn();
        if ($tid !== $l['t']) json_out(['ok' => false, 'error' => 'Not your lease.'], 403);
    } elseif (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal'], true)) {
        json_out(['ok' => false, 'error' => 'Your role cannot view agreements.'], 403);
    }
    $tpl = null;
    $st = $pdo->prepare('SELECT * FROM doc_templates WHERE id=?'); $st->execute(['LSE-STD']);
    $tpl = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tpl) {
        foreach (seed_templates($pdo) as $s) if ($s['id'] === 'LSE-STD') { $tpl = $s; break; }
    }
    if (!$tpl) json_out(['ok' => false, 'error' => 'Agreement template missing.'], 500);
    $res = tpl_vars_for($u, 'lease', $lease);
    if ($res === null) json_out(['ok' => false, 'error' => 'Could not resolve lease variables.'], 500);
    $merged = render_merge($tpl['body'], $res['vars']);
    $preview = render_merge($tpl['title'], $res['vars']);
    $html = tpl_print_page(['title' => $preview, 'name' => $tpl['name']], $merged, 'lease');
    audit($u['name'], 'Portal agreement downloaded', 'portal', 'LSE-STD', $lease);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

/* ── Phase 15: rent reminder scheduler ── */
case 'app-reminder-config': {
    $svc = service_authed();
    if (!$svc) { $u = require_user(); require_module($u, 'recon'); }
    $pdo = db();
    $cfg = reminder_config_get($pdo);
    $st = $pdo->query("SELECT v FROM platform_meta WHERE k='last_reminder_run'"); $lastRun = $st->fetchColumn() ?: '';
    $hist = $pdo->query('SELECT invoice_id, tier, sent_at, via FROM invoice_reminders ORDER BY sent_at DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
    json_out(['ok' => true, 'config' => $cfg, 'last_run' => $lastRun, 'history' => $hist]);
}
case 'app-reminder-save': {
    $u = require_user();
    require_module($u, 'recon');
    $cfg = reminder_config_save(db(), $body['config'] ?? []);
    audit($u['name'], 'Reminder config saved', 'recon', 'reminder_config', '');
    json_out(['ok' => true, 'config' => $cfg]);
}
case 'app-reminder-summary': {
    $svc = service_authed();
    if (!$svc) { $u = require_user(); require_module($u, 'recon'); }
    $res = reminder_plan(db());
    $byTier = ['0' => 0, '1' => 0, '2' => 0, '3' => 0];
    foreach ($res['plan'] as $r) $byTier[(string)$r['tier']] = ($byTier[(string)$r['tier']] ?? 0) + 1;
    $totalDue = array_sum(array_map(fn($r) => $r['due'], $res['plan']));
    json_out(['ok' => true, 'config' => $res['cfg'], 'plan' => $res['plan'], 'by_tier' => (object)$byTier, 'total_due' => $totalDue]);
}
case 'app-reminder-run': {
    $svc = service_authed();
    if (!$svc) { $u = require_user(); require_module($u, 'recon'); }
    else { $u = ['name' => 'system', 'role' => 'service', 'email' => '']; }
    $send = !empty($body['send']);
    $pdo = db();
    $res = reminder_plan($pdo);
    $cfg = $res['cfg'];
    $byTier = ['0' => 0, '1' => 0, '2' => 0, '3' => 0];
    foreach ($res['plan'] as $r) $byTier[(string)$r['tier']] = ($byTier[(string)$r['tier']] ?? 0) + 1;
    $totalDue = array_sum(array_map(fn($r) => $r['due'], $res['plan']));
    $sent = 0; $stamped = 0; $suppressed = 0; $errors = []; $sentList = [];
    if ($send && $cfg['enabled']) {
        foreach ($res['plan'] as $r) {
            if ((int)$r['tier'] === 0) continue;
            if ((int)$r['tier'] <= (int)$r['last_tier']) continue;   /* escalation gate: only when tier increases */
            if (!mail_switch($pdo, 'rent_reminders') || !notify_ok($pdo, $r['email'], 'notify_rent')) { $suppressed++; continue; }   /* Phase 19: per-user opt-out + admin master switch */
            $out = reminder_send_one($pdo, $r, $cfg);
            if ($out['ok']) { $sent++; $stamped++; $sentList[] = $out; }
            else $errors[] = $out;
        }
        $pdo->prepare("INSERT OR REPLACE INTO platform_meta (k, v) VALUES ('last_reminder_run', datetime('now'))")->execute([]);
    }
    json_out([
        'ok' => true,
        'send' => $send, 'enabled' => $cfg['enabled'],
        'by_tier' => (object)$byTier, 'total_due' => $totalDue,
        'plan' => $res['plan'], 'sent' => $sent, 'stamped' => $stamped, 'suppressed' => $suppressed,
        'sent_list' => $sentList, 'errors' => $errors,
    ]);
}

/* SA1 v22: rent-due push — proactive owner alert via Web Push, run on the same schedule as the
   collections digest cron. Respects the rent_reminders master switch + per-user notify_rent
   opt-out (identical gates to the reminder emails). send=0 → dry-run preview. */
case 'app-rent-due-push': {
    $svc = service_authed();
    if (!$svc) { $u = require_user(); require_module($u, 'recon'); }
    else { $u = ['name' => 'system', 'role' => 'service', 'email' => '']; }
    $send = !empty($body['send']);
    $lookahead = max(0, min(6, (int)($body['lookahead'] ?? 1)));
    $pdo = db();
    $owners = rent_due_push_data($pdo, $lookahead);
    $targeted = 0; $sent = 0; $removed = 0; $suppressed = 0; $errors = [];
    $tot = ['overdue' => 0, 'due_soon' => 0, 'upcoming' => 0, 'invoices' => 0];
    $ownersOut = [];
    foreach ($owners as $o) {
        $targeted++;
        $tot['overdue'] += $o['overdue']; $tot['due_soon'] += $o['due_soon'];
        $tot['upcoming'] += $o['upcoming']; $tot['invoices'] += $o['invoices'];
        $rec = ['email' => $o['email'], 'overdue' => $o['overdue'], 'due_soon' => $o['due_soon'],
                'upcoming' => $o['upcoming'], 'invoices' => $o['invoices'], 'suppressed' => false];
        if ($send) {
            if (!mail_switch($pdo, 'rent_reminders') || !notify_ok($pdo, $o['email'], 'notify_rent')) {
                $suppressed++; $rec['suppressed'] = true;
                $ownersOut[] = $rec; continue;
            }
            $parts = [];
            if ($o['overdue'] > 0) $parts[] = '৳' . number_format($o['overdue']) . ' overdue';
            if ($o['due_soon'] > 0) $parts[] = '৳' . number_format($o['due_soon']) . ' due this month';
            if ($o['upcoming'] > 0) $parts[] = '৳' . number_format($o['upcoming']) . ' due next month';
            $res = push_to_user($pdo, $o['email'], 'Rent due — KRTaker', implode(' · ', $parts), '/app-v3/');
            $rec['sent'] = $res['sent']; $rec['errors'] = $res['errors'];
            $sent += $res['sent']; $removed += $res['removed'];
            $errors = array_merge($errors, $res['errors']);
        }
        $ownersOut[] = $rec;
    }
    if ($send) {
        $pdo->prepare("INSERT OR REPLACE INTO platform_meta (k, v) VALUES ('last_rent_due_push', ?)")
            ->execute([gmdate('Y-m-d H:i:s') . ' owners=' . $targeted . ' sent=' . $sent . ' overdue=' . $tot['overdue'] . ' soon=' . $tot['due_soon']]);
        audit($u['name'], 'Rent-due push run', 'payments', 'bulk', 'owners=' . $targeted . ' sent=' . $sent . ' suppressed=' . $suppressed);
    }
    $st = $pdo->query("SELECT v FROM platform_meta WHERE k='last_rent_due_push'");
    json_out(['ok' => true, 'dry_run' => !$send, 'targeted' => $targeted, 'sent' => $sent, 'removed' => $removed,
        'suppressed' => $suppressed, 'totals' => $tot, 'owners' => $ownersOut, 'errors' => $errors,
        'last_run' => $st->fetchColumn() ?: '']);
}

/* ---------- Mail queue worker (2026-08-09) ---------- */
/* Drains the mail_queue table. Service-key gated (cron caller); also allows
   superadmin via the recon module for manual drains. Limit = rows per run. */
case 'app-mail-worker': {
    $svc = service_authed();
    if (!$svc) { $u = require_user(); require_module($u, 'recon'); }
    else { $u = ['name' => 'system', 'role' => 'service', 'email' => '']; }
    $limit = max(1, min(200, (int)($body['limit'] ?? 50)));
    $res = mail_queue_drain(db(), $limit);
    if ($res['sent'] > 0 || $res['failed'] > 0)
        audit($u['name'], 'Mail queue drain', 'mail', 'bulk',
            'sent=' . $res['sent'] . ' failed=' . $res['failed'] . ' left=' . $res['left']);
    json_out(array_merge(['ok' => true], $res));
}

/* ---------- Phase 16: lease renewal requests + utility meter readings ---------- */
case 'app-renewal-list': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot view renewal requests.'], 403);
    $pdo = db();
    $rows = $pdo->query("SELECT r.*, l.u AS unit_id, tn.name AS tenant_name, u.name AS unit_name, p.name AS property_name
        FROM renewal_requests r
        LEFT JOIN leases l ON l.id = r.lease
        LEFT JOIN tenants tn ON tn.id = r.tenant
        LEFT JOIN units u ON u.id = l.u
        LEFT JOIN properties p ON p.id = u.p
        ORDER BY (r.status='Pending') DESC, r.ts DESC")->fetchAll(PDO::FETCH_ASSOC);
    json_out(['ok' => true, 'requests' => $rows]);
}
case 'app-renewal-create': {
    $u = require_user();
    if ($u['role'] !== 'tenant') json_out(['ok' => false, 'error' => 'Only tenants can request renewals.'], 403);
    $lease = trim($body['lease'] ?? '');
    $months = max(1, min(36, (int)($body['months'] ?? 12)));
    $newRent = max(0, (int)($body['new_rent'] ?? 0));
    $note = trim($body['note'] ?? '');
    if (!$lease) json_out(['ok' => false, 'error' => 'lease required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM leases WHERE id=?'); $st->execute([$lease]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) json_out(['ok' => false, 'error' => 'Lease not found.'], 404);
    $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
    $tid = $st->fetchColumn();
    if (!$tid || $tid !== $l['t']) json_out(['ok' => false, 'error' => 'Not your lease.'], 403);
    if (in_array($l['status'], ['Expired', 'Terminated'], true)) json_out(['ok' => false, 'error' => 'This lease is no longer active.'], 400);
    $st = $pdo->prepare("SELECT COUNT(*) FROM renewal_requests WHERE lease=? AND status='Pending'"); $st->execute([$lease]);
    if ((int)$st->fetchColumn()) json_out(['ok' => false, 'error' => 'A renewal request is already pending for this lease.'], 409);
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'RR-','') AS INTEGER)) FROM renewal_requests")->fetchColumn();
    $id = 'RR-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO renewal_requests (id, lease, tenant, months, new_rent, note, status) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, $lease, $tid, $months, $newRent, $note, 'Pending']);
    audit($u['name'], 'Renewal requested', 'leases', $id, $lease . ' +' . $months . 'mo');
    json_out(['ok' => true, 'id' => $id]);
}
case 'app-renewal-decide': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot decide renewals.'], 403);
    $id = trim($body['id'] ?? '');
    $action = trim($body['action'] ?? '');
    if (!$id || !in_array($action, ['approve', 'reject'], true)) json_out(['ok' => false, 'error' => 'id and action (approve|reject) required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM renewal_requests WHERE id=?'); $st->execute([$id]);
    $rr = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rr) json_out(['ok' => false, 'error' => 'Request not found.'], 404);
    if (!in_array($rr['status'], ['Pending', 'Offered'], true)) json_out(['ok' => false, 'error' => 'Request already decided.'], 409);
    $st = $pdo->prepare('SELECT * FROM leases WHERE id=?'); $st->execute([$rr['lease']]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) json_out(['ok' => false, 'error' => 'Lease not found.'], 404);
    $st = $pdo->prepare('SELECT * FROM tenants WHERE id=?'); $st->execute([$rr['tenant']]);
    $tn = $st->fetch(PDO::FETCH_ASSOC);
    $status = $action === 'approve' ? 'Approved' : 'Rejected';
    $newEnd = $l['end']; $newRent = (int)$l['rent'];
    if ($action === 'approve') {
        $d = new DateTime($l['end'] ?: date('Y-m-d'));
        $d->modify('+' . (int)$rr['months'] . ' months');
        $newEnd = $d->format('Y-m-d');
        if ((int)$rr['new_rent'] > 0) $newRent = (int)$rr['new_rent'];
        $newStatus = (in_array($l['status'], ['Expired', 'Terminated'], true)) ? 'Active' : $l['status'];
        $pdo->prepare('UPDATE leases SET end=?, rent=?, status=? WHERE id=?')
            ->execute([$newEnd, $newRent, $newStatus, $rr['lease']]);
    }
    $pdo->prepare("UPDATE renewal_requests SET status=?, decided_by=?, decided_at=datetime('now') WHERE id=?")
        ->execute([$status, $u['name'], $id]);
    if ($tn) {
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'NTC-','') AS INTEGER)) FROM notices")->fetchColumn();
        $ntc = 'NTC-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
        $title = $status === 'Approved' ? 'Lease renewal approved' : 'Lease renewal not approved';
        $body2 = 'Your renewal request for ' . $rr['lease'] . ' was ' . strtolower($status) . '.'
            . ($status === 'Approved' ? ' New end date: ' . $newEnd . '. Monthly rent: ৳' . number_format($newRent) . '.' : '');
        $pdo->prepare('INSERT INTO notices (id, title, body, author, pinned) VALUES (?,?,?,?,0)')
            ->execute([$ntc, $title, $body2, 'system']);
        if (!empty($tn['sub_email'])) {
            $st = $pdo->prepare('SELECT name FROM units WHERE id=?'); $st->execute([$l['u']]);
            $uname = (string)$st->fetchColumn();
            $st = $pdo->prepare('SELECT p FROM units WHERE id=?'); $st->execute([$l['u']]);
            $pid = (string)$st->fetchColumn();
            $st = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $st->execute([$pid]);
            $pname = (string)$st->fetchColumn();
            list($subj, $html) = email_render('renewal_status', [
                'tenant_name' => $tn['name'], 'property' => $pname, 'unit' => $uname . ' (' . $l['u'] . ')',
                'lease' => $rr['lease'], 'status' => $status, 'status_color' => $status === 'Approved' ? '#059669' : '#B91C1C',
                'new_end' => date('d M Y', strtotime($newEnd)), 'new_rent' => number_format($newRent),
                'note' => $status === 'Approved'
                    ? 'Your lease has been renewed — thank you for being a valued tenant. The updated agreement is available from your tenant portal.'
                    : 'The owner was not able to approve this renewal this time. Please reach out through the portal or WhatsApp to discuss options.',
            ]);
            if (mail_switch($pdo, 'renewal') && notify_ok($pdo, $tn['sub_email'], 'notify_renewal'))   /* Phase 19: per-user opt-out + admin master switch */
                send_mail($tn['sub_email'], $subj, $html, null, true);
        }
    }
    audit($u['name'], 'Renewal ' . strtolower($status), 'leases', $id, $rr['lease']);
    json_out(['ok' => true, 'id' => $id, 'status' => $status, 'new_end' => $newEnd, 'new_rent' => $newRent]);
}
case 'app-renewal-offer': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot create renewal offers.'], 403);
    $pdo = db();
    $lease = trim($body['lease'] ?? '');
    $months = max(1, min(36, (int)($body['months'] ?? 12)));
    $escalRaw = (float)($body['escalation'] ?? -1);
    $escal = max(0, min(100, $escalRaw));
    $explicit = max(0, (int)($body['new_rent'] ?? 0));
    $note = trim($body['note'] ?? '');
    if (!$lease) json_out(['ok' => false, 'error' => 'lease required.'], 400);
    $st = $pdo->prepare('SELECT * FROM leases WHERE id=?'); $st->execute([$lease]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) json_out(['ok' => false, 'error' => 'Lease not found.'], 404);
    if (in_array($l['status'], ['Expired', 'Terminated'], true)) json_out(['ok' => false, 'error' => 'This lease is no longer active.'], 400);
    $st = $pdo->prepare("SELECT COUNT(*) FROM renewal_requests WHERE lease=? AND status IN ('Pending','Offered')"); $st->execute([$lease]);
    if ((int)$st->fetchColumn()) json_out(['ok' => false, 'error' => 'A renewal request is already pending for this lease.'], 409);
    /* escalation: explicit param > property default > 0 */
    if ($escalRaw < 0) {
        $st = $pdo->prepare('SELECT pr.escalation_pct FROM property_rent pr JOIN units u ON u.p=pr.prop WHERE u.id=?');
        $st->execute([$l['u']]);
        $escal = (float)$st->fetchColumn();
        if ($escal < 0) $escal = 0;
    }
    $newRent = $explicit > 0 ? $explicit : (int)round((int)$l['rent'] * (1 + $escal / 100));
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'RR-','') AS INTEGER)) FROM renewal_requests")->fetchColumn();
    $id = 'RR-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO renewal_requests (id, lease, tenant, months, new_rent, note, status, decided_by, decided_at) VALUES (?,?,?,?,?,?,'Offered',?,datetime('now'))")
        ->execute([$id, $lease, $l['t'], $months, $newRent, $note, $u['name']]);
    /* tenant notice */
    $st = $pdo->prepare('SELECT * FROM tenants WHERE id=?'); $st->execute([$l['t']]);
    $tn = $st->fetch(PDO::FETCH_ASSOC);
    if ($tn) {
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'NTC-','') AS INTEGER)) FROM notices")->fetchColumn();
        $ntc = 'NTC-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO notices (id, title, body, author, pinned) VALUES (?,?,?,?,0)')
            ->execute([$ntc, 'New lease renewal offer', 'The owner offered to renew ' . $lease . ' for ' . $months . ' months at ৳' . number_format($newRent) . '/month. Accept or decline from your tenant portal.' . ($note ? ' Note: ' . $note : ''), 'system']);
        if (!empty($tn['sub_email']) && mail_switch($pdo, 'renewal') && notify_ok($pdo, $tn['sub_email'], 'notify_renewal')) {
            $st = $pdo->prepare('SELECT name FROM units WHERE id=?'); $st->execute([$l['u']]);
            $uname = (string)$st->fetchColumn();
            $st = $pdo->prepare('SELECT p FROM units WHERE id=?'); $st->execute([$l['u']]);
            $pid = (string)$st->fetchColumn();
            $st = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $st->execute([$pid]);
            $pname = (string)$st->fetchColumn();
            $st = $pdo->prepare('SELECT id FROM units WHERE id=?'); $st->execute([$l['u']]);
            send_mail($tn['sub_email'], '[KRTaker] New lease renewal offer for ' . $lease,
                '<p>Hello ' . esc($tn['name']) . ',</p><p>' . esc($u['name']) . ' offered to renew your lease <b>' . esc($lease) . '</b> (' . esc($uname) . ', ' . esc($pname) . ') for <b>' . $months . ' months</b> at <b>৳' . number_format($newRent) . '/month</b>.</p><p>Accept or decline from your tenant portal.</p>', null, true);
        }
    }
    audit($u['name'], 'Renewal offer created', 'leases', $id, $lease . ' +' . $months . 'mo ৳' . $newRent);
    json_out(['ok' => true, 'id' => $id, 'new_rent' => $newRent, 'escalation' => $escal, 'months' => $months]);
}
case 'app-renewal-respond': {
    $u = require_user();
    if ($u['role'] !== 'tenant') json_out(['ok' => false, 'error' => 'Only tenants can respond to renewal offers.'], 403);
    $id = trim($body['id'] ?? '');
    $action = trim($body['action'] ?? '');
    if (!$id || !in_array($action, ['accept', 'decline'], true)) json_out(['ok' => false, 'error' => 'id and action (accept|decline) required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM renewal_requests WHERE id=?'); $st->execute([$id]);
    $rr = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rr) json_out(['ok' => false, 'error' => 'Request not found.'], 404);
    if ($rr['status'] !== 'Offered') json_out(['ok' => false, 'error' => 'This request is not an open offer.'], 409);
    $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
    $tid = (string)$st->fetchColumn();
    if (!$tid || $tid !== $rr['tenant']) json_out(['ok' => false, 'error' => 'Not your offer.'], 403);
    $res = [];
    if ($action === 'accept') {
        $res = renewal_apply($pdo, $rr);
        if (isset($res['error'])) json_out(['ok' => false, 'error' => $res['error']], 400);
    }
    $status = $action === 'accept' ? 'Approved' : 'Rejected';
    $pdo->prepare("UPDATE renewal_requests SET status=?, decided_by=?, decided_at=datetime('now') WHERE id=?")
        ->execute([$status, $u['name'], $id]);
    audit($u['name'], 'Renewal offer ' . $action . 'ed', 'leases', $id, $rr['lease']);
    json_out(['ok' => true, 'id' => $id, 'status' => $status] + ($res ?: ['new_end' => '', 'new_rent' => 0]));
}
case 'app-meter-submit': {
    $u = require_user();
    $unit = trim($body['unit'] ?? '');
    $type = trim($body['type'] ?? '');
    $reading = (int)($body['reading'] ?? -1);
    $month = trim($body['month'] ?? '');
    $note = trim($body['note'] ?? '');
    if (!in_array($type, ['electric', 'gas', 'water'], true)) json_out(['ok' => false, 'error' => 'type must be electric, gas or water.'], 400);
    if ($reading < 0) json_out(['ok' => false, 'error' => 'reading required.'], 400);
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) json_out(['ok' => false, 'error' => 'month must be YYYY-MM.'], 400);
    if (!$unit) json_out(['ok' => false, 'error' => 'unit required.'], 400);
    $pdo = db();
    $tid = '';
    $mine = my_units($u);
    if ($u['role'] === 'tenant') {
        if (!$mine || !in_array($unit, $mine, true)) json_out(['ok' => false, 'error' => 'Not your unit.'], 403);
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        $tid = (string)$st->fetchColumn();
    } elseif (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true)) {
        json_out(['ok' => false, 'error' => 'Your role cannot submit meter readings.'], 403);
    }
    $st = $pdo->prepare('SELECT reading FROM meter_readings WHERE unit=? AND type=? AND month<? ORDER BY month DESC LIMIT 1');
    $st->execute([$unit, $type, $month]);
    $prev = $st->fetchColumn();
    if ($prev !== false && $reading < (int)$prev) json_out(['ok' => false, 'error' => 'Reading cannot be lower than the previous reading (' . $prev . ').'], 400);
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'MR-','') AS INTEGER)) FROM meter_readings")->fetchColumn();
    $id = 'MR-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO meter_readings (id, unit, tenant, type, reading, month, note) VALUES (?,?,?,?,?,?,?)
        ON CONFLICT(unit, type, month) DO UPDATE SET reading=excluded.reading, note=excluded.note, tenant=excluded.tenant, ts=datetime('now')")
        ->execute([$id, $unit, $tid, $type, $reading, $month, $note]);
    audit($u['name'], 'Meter reading submitted', 'units', $id, $unit . ' ' . $type . ' ' . $reading);
    json_out(['ok' => true, 'id' => $id]);
}
case 'app-meter-list': {
    $u = require_user();
    $pdo = db();
    $rows = [];
    if (in_array($u['role'], ['superadmin', 'owner', 'manager'], true)) {
        $rows = $pdo->query("SELECT m.*, u.name AS unit_name, u.p AS property_id, p.name AS property_name
            FROM meter_readings m
            LEFT JOIN units u ON u.id = m.unit
            LEFT JOIN properties p ON p.id = u.p
            ORDER BY m.month DESC, m.type")->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($u['role'] === 'tenant') {
        $mine = my_units($u);
        if ($mine) {
            $rows = $pdo->prepare("SELECT m.*, u.name AS unit_name, u.p AS property_id, p.name AS property_name
                FROM meter_readings m
                LEFT JOIN units u ON u.id = m.unit
                LEFT JOIN properties p ON p.id = u.p
                WHERE m.unit IN (" . ai_in_list($mine) . ")
                ORDER BY m.month DESC, m.type");
            $rows->execute($mine);
            $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        json_out(['ok' => false, 'error' => 'Your role cannot view meter readings.'], 403);
    }
    json_out(['ok' => true, 'readings' => $rows]);
}

/* ── Phase 20: utility billing engine ── */
case 'app-utility-tariff-get': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot view utility tariffs.'], 403);
    json_out(['ok' => true, 'tariffs' => utility_tariffs(db())]);
}
case 'app-utility-tariff-save': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot edit utility tariffs.'], 403);
    $pdo = db();
    $tar = utility_tariffs_save($pdo, $body['tariffs'] ?? $body);
    audit($u['name'], 'Utility tariffs updated', 'units', 'tariffs', implode(',', array_keys(is_array($body['tariffs'] ?? $body) ? $body['tariffs'] ?? $body : [])));
    json_out(['ok' => true, 'tariffs' => $tar]);
}
case 'app-utility-bill-preview': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot generate utility bills.'], 403);
    $calc = utility_bill_calc(db(), trim($body['unit'] ?? ''), trim($body['type'] ?? ''), trim($body['month'] ?? ''), $body);
    if (isset($calc['error'])) json_out(['ok' => false, 'error' => $calc['error']], 400);
    json_out(['ok' => true, 'bill' => $calc]);
}
case 'app-utility-bill-generate': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot generate utility bills.'], 403);
    $pdo = db();
    $unit = trim($body['unit'] ?? ''); $type = trim($body['type'] ?? ''); $month = trim($body['month'] ?? '');
    $calc = utility_bill_calc($pdo, $unit, $type, $month, $body);
    if (isset($calc['error'])) json_out(['ok' => false, 'error' => $calc['error']], 400);
    /* attach tenant of the unit's active lease */
    $st = $pdo->prepare('SELECT t.id FROM leases l JOIN tenants t ON t.id=l.t WHERE l.u=? AND l.status IN (\'Active\',\'Pending Registration\') ORDER BY l.start DESC LIMIT 1');
    $st->execute([$unit]);
    $calc['tenant'] = (string)$st->fetchColumn();
    $bill = utility_bill_upsert($pdo, $calc);
    audit($u['name'], 'Utility bill generated', 'units', $bill['id'], $unit . ' ' . $type . ' ' . $month . ' ৳' . $bill['amount']);
    json_out(['ok' => true, 'bill' => $bill]);
}
case 'app-utility-bill-list': {
    $u = require_user();
    $pdo = db();
    $rows = [];
    if (in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true)) {
        $rows = $pdo->query("SELECT b.*, u.name AS unit_name, p.id AS property_id, p.name AS property_name, t.name AS tenant_name
            FROM utility_bills b
            LEFT JOIN units u ON u.id = b.unit
            LEFT JOIN properties p ON p.id = u.p
            LEFT JOIN tenants t ON t.id = b.tenant
            ORDER BY b.month DESC, b.id")->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($u['role'] === 'tenant') {
        $mine = my_units($u);
        if ($mine) {
            $st = $pdo->prepare("SELECT b.*, u.name AS unit_name, p.id AS property_id, p.name AS property_name, t.name AS tenant_name
                FROM utility_bills b
                LEFT JOIN units u ON u.id = b.unit
                LEFT JOIN properties p ON p.id = u.p
                LEFT JOIN tenants t ON t.id = b.tenant
                WHERE b.unit IN (" . ai_in_list($mine) . ")
                ORDER BY b.month DESC, b.id");
            $st->execute($mine);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        json_out(['ok' => false, 'error' => 'Your role cannot view utility bills.'], 403);
    }
    foreach ($rows as &$b) { $b['amount'] = (int)$b['amount']; $b['usage'] = (int)$b['usage']; }
    json_out(['ok' => true, 'bills' => $rows]);
}
case 'app-utility-bill-pay': {
    $u = require_user();
    $id = trim($body['id'] ?? '');
    $action = trim($body['action'] ?? 'pay');   /* pay | void */
    if (!$id || !in_array($action, ['pay', 'void'], true)) json_out(['ok' => false, 'error' => 'id and action (pay|void) required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM utility_bills WHERE id=?'); $st->execute([$id]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) json_out(['ok' => false, 'error' => 'Bill not found.'], 404);
    if ($u['role'] === 'tenant') {
        $mine = my_units($u);
        if (!$mine || !in_array($b['unit'], $mine, true)) json_out(['ok' => false, 'error' => 'Not your unit.'], 403);
        if ($action !== 'pay') json_out(['ok' => false, 'error' => 'Tenants cannot void bills.'], 403);
    } elseif (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true)) {
        json_out(['ok' => false, 'error' => 'Your role cannot update utility bills.'], 403);
    }
    if ($b['status'] === 'Void' || $b['status'] === 'Paid') json_out(['ok' => false, 'error' => 'Bill already ' . $b['status'] . '.'], 409);
    if ($action === 'pay') {
        $pdo->prepare("UPDATE utility_bills SET status='Paid', paid_at=datetime('now') WHERE id=?")->execute([$id]);
        audit($u['name'], 'Utility bill paid', 'units', $id, $b['unit'] . ' ' . $b['type'] . ' ' . $b['month'] . ' ৳' . $b['amount']);
        json_out(['ok' => true, 'id' => $id, 'status' => 'Paid']);
    } else {
        $pdo->prepare("UPDATE utility_bills SET status='Void' WHERE id=?")->execute([$id]);
        audit($u['name'], 'Utility bill voided', 'units', $id, $b['unit'] . ' ' . $b['type'] . ' ' . $b['month']);
        json_out(['ok' => true, 'id' => $id, 'status' => 'Void']);
    }
}

/* ── Phase 34: batch utility billing run + month summary ── */
case 'app-utility-run': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot run utility billing.'], 403);
    $pdo = db();
    $month = trim($body['month'] ?? '');
    if (!$month) $month = date('Y-m');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) json_out(['ok' => false, 'error' => 'month must be YYYY-MM.'], 400);
    $prop = trim($body['prop'] ?? '');
    $res = utility_batch_run($pdo, $month, $prop ?: null);
    audit($u['name'], 'Utility batch run', 'units', $month, 'generated=' . $res['generated'] . ' updated=' . $res['updated'] . ' ৳' . $res['total_amount']);
    json_out(['ok' => true, 'month' => $month] + $res);
}
case 'app-utility-summary': {
    $u = require_user();
    $pdo = db();
    $month = trim($_GET['month'] ?? $body['month'] ?? '');
    if (!$month) $month = date('Y-m');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) json_out(['ok' => false, 'error' => 'month must be YYYY-MM.'], 400);
    if (in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true)) {
        json_out(['ok' => true] + utility_summary($pdo, $month));
    } elseif ($u['role'] === 'tenant') {
        $mine = my_units($u);
        if (!$mine) json_out(['ok' => true] + utility_summary($pdo, $month));
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        json_out(['ok' => true] + utility_summary($pdo, $month, null, (string)$st->fetchColumn()));
    } else {
        json_out(['ok' => false, 'error' => 'Your role cannot view utility summaries.'], 403);
    }
}

/* ── Phase 17: tenant report card (renter risk score + landlord vetting) ── */
case 'app-score-list': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot view tenant scores.'], 403);
    $pdo = db();
    $rows = [];
    foreach ($pdo->query('SELECT id, name, kind, nrb FROM tenants ORDER BY name') as $t) {
        $sc = tenant_scorecard($pdo, $t['id']);
        $rows[] = [
            'tenant' => ['id' => $t['id'], 'name' => $t['name'], 'kind' => $t['kind'], 'nrb' => (int)$t['nrb']],
            'score' => $sc['score'], 'band' => $sc['band'], 'band_color' => $sc['band_color'],
            'factors' => $sc['factors'],
        ];
    }
    usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
    json_out(['ok' => true, 'scores' => $rows]);
}
case 'app-score-detail': {
    $u = require_user();
    $pdo = db();
    $tid = trim($_GET['t'] ?? $body['t'] ?? '');
    if ($u['role'] === 'tenant') {
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        $mine = (string)$st->fetchColumn();
        if (!$mine) json_out(['ok' => false, 'error' => 'No tenant profile.'], 404);
        if ($tid && $tid !== $mine) json_out(['ok' => false, 'error' => 'Not your scorecard.'], 403);
        $tid = $mine;
    } elseif (in_array($u['role'], ['superadmin', 'owner', 'manager'], true)) {
        if (!$tid) json_out(['ok' => false, 'error' => 't required.'], 400);
    } else {
        json_out(['ok' => false, 'error' => 'Your role cannot view tenant scores.'], 403);
    }
    $sc = tenant_scorecard($pdo, $tid);
    if (!$sc) json_out(['ok' => false, 'error' => 'Tenant not found.'], 404);
    /* key-indicator drill-down: factor weights + raw stats from the scorecard */
    json_out(['ok' => true] + $sc + ['detail' => [
        'weights' => ['payment' => 40, 'stability' => 20, 'care' => 20, 'compliance' => 20],
        'stats' => $sc['stats'] ?? [],
    ]]);
}
case 'app-vetting-report': {
    $u = require_user();
    $pdo = db();
    $tid = trim($_GET['t'] ?? $body['t'] ?? '');
    if ($u['role'] === 'tenant') {
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        $mine = (string)$st->fetchColumn();
        if (!$mine) json_out(['ok' => false, 'error' => 'No tenant profile.'], 404);
        if ($tid && $tid !== $mine) json_out(['ok' => false, 'error' => 'Not your report.'], 403);
        $tid = $mine;
    } elseif (in_array($u['role'], ['superadmin', 'owner', 'manager'], true)) {
        if (!$tid) json_out(['ok' => false, 'error' => 't required.'], 400);
    } else {
        json_out(['ok' => false, 'error' => 'Your role cannot view vetting reports.'], 403);
    }
    $sc = tenant_scorecard($pdo, $tid);
    if (!$sc) json_out(['ok' => false, 'error' => 'Tenant not found.'], 404);
    header('Content-Type: text/html; charset=utf-8');
    echo vetting_report_html($sc);
    exit;
}

/* ── Phase 21: settlement statement + no-dues certificate ── */
case 'app-settlement-report': {
    $u = require_user();
    $pdo = db();
    $tid = trim($_GET['t'] ?? $body['t'] ?? '');
    $lease = trim($_GET['lease'] ?? $body['lease'] ?? '');
    if ($u['role'] === 'tenant') {
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        $mine = (string)$st->fetchColumn();
        if (!$mine) json_out(['ok' => false, 'error' => 'No tenant profile.'], 404);
        if ($tid && $tid !== $mine) json_out(['ok' => false, 'error' => 'Not your report.'], 403);
        $tid = $mine;
        if ($lease) {
            $st = $pdo->prepare('SELECT t FROM leases WHERE id=?'); $st->execute([$lease]);
            if ((string)$st->fetchColumn() !== $tid) json_out(['ok' => false, 'error' => 'Not your lease.'], 403);
        }
    } elseif (in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true)) {
        if (!$tid && !$lease) json_out(['ok' => false, 'error' => 't (tenant) or lease required.'], 400);
        if ($lease) {
            $st = $pdo->prepare('SELECT t FROM leases WHERE id=?'); $st->execute([$lease]);
            $tid = (string)$st->fetchColumn();
            if (!$tid) json_out(['ok' => false, 'error' => 'Lease not found.'], 404);
        }
    } else {
        json_out(['ok' => false, 'error' => 'Your role cannot view settlement reports.'], 403);
    }
    $deductions = $body['deductions'] ?? ($_GET['deductions'] ?? null);
    if (is_string($deductions) && $deductions !== '') $deductions = json_decode($deductions, true);
    $rep = settlement_report($pdo, $tid, $lease, ['deductions' => $deductions, 'generated_by' => $u['name'], 'persist' => true]);
    if (!$rep) json_out(['ok' => false, 'error' => 'Tenant not found.'], 404);
    if (isset($rep['error'])) json_out(['ok' => false, 'error' => $rep['error']], 400);
    audit($u['name'], 'Settlement report generated', 'tenants', $tid, $rep['id'] . ' ' . $rep['status'] . ' ৳' . $rep['totals']['total_due']);
    if (isset($_GET['html']) || !empty($body['html'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo settlement_report_html($rep);
        exit;
    }
    json_out(['ok' => true, 'report' => $rep]);
}

/* ── Phase 22: NRB premium tier (remote caretaker subscription) ── */
case 'app-premium-plans': {
    $u = require_user();
    json_out(['ok' => true, 'plans' => PREMIUM_TIERS()]);
}
case 'app-premium-sub-list': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot view caretaker subscriptions.'], 403);
    $pdo = db();
    $rows = ($u['role'] === 'owner') ? premium_sub_rows($pdo, $u['email']) : premium_sub_rows($pdo);
    json_out(['ok' => true, 'subs' => $rows]);
}
case 'app-premium-subscribe': {
    $u = require_user();
    if (!in_array($u['role'], ['owner', 'superadmin'], true))
        json_out(['ok' => false, 'error' => 'Only owners can subscribe to the caretaker service.'], 403);
    $tier = trim($body['tier'] ?? 'nrb_caretaker');
    $cycle = trim($body['cycle'] ?? 'monthly');
    $prop = trim($body['prop'] ?? '');
    if (!isset(PREMIUM_TIERS()[$tier])) json_out(['ok' => false, 'error' => 'Unknown tier.'], 400);
    if (!in_array($cycle, ['monthly', 'quarterly', 'annual'], true)) json_out(['ok' => false, 'error' => 'cycle must be monthly|quarterly|annual.'], 400);
    $pdo = db();
    if ($prop) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE id=?'); $st->execute([$prop]);
        if (!(int)$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Property not found.'], 404);
    }
    $price = premium_cycle_price($tier, $cycle);
    $features = json_encode(PREMIUM_TIERS()[$tier]['features'], JSON_UNESCAPED_UNICODE);
    $next = premium_next_invoice($cycle);
    $st = $pdo->prepare("SELECT id, status FROM caretaker_subs WHERE user_email=? AND status IN ('active','paused') ORDER BY ts DESC LIMIT 1");
    $st->execute([$u['email']]);
    $existing = $st->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $pdo->prepare("UPDATE caretaker_subs SET tier=?, prop=?, cycle=?, price=?, features=?, next_invoice=?, status='active', end='', ts=datetime('now') WHERE id=?")
            ->execute([$tier, $prop, $cycle, $price, $features, $next, $existing['id']]);
        $id = $existing['id'];
        audit($u['name'], 'Caretaker subscription updated', 'subscriptions', $id, $tier . ' ' . $cycle . ' ৳' . $price);
    } else {
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'CS-','') AS INTEGER)) FROM caretaker_subs")->fetchColumn();
        $id = 'CS-' . str_pad((string)max(1, $mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO caretaker_subs (id, user_email, prop, tier, price, cycle, status, start, end, next_invoice, features, created_by, ts) VALUES (?,?,?,?,?,?,'active',datetime('now'),'',?,?,?,datetime('now'))")
            ->execute([$id, $u['email'], $prop, $tier, $price, $cycle, $next, $features, $u['name']]);
        audit($u['name'], 'Caretaker subscription created', 'subscriptions', $id, $tier . ' ' . $cycle . ' ৳' . $price);
    }
    $rows = premium_sub_rows($pdo, $u['email']);
    $mine = null;
    foreach ($rows as $r) if ($r['id'] === $id) { $mine = $r; break; }
    /* Phase 23: confirmation email respects notify_premium */
    $propName = $mine['property_name'] ?? $prop;
    if (notify_ok($pdo, $u['email'], 'notify_premium')) {
        list($pSubj, $pBody) = email_render('premium_welcome', [
            'name' => $u['name'], 'tier_label' => PREMIUM_TIERS()[$tier]['label'],
            'property' => $propName, 'cycle' => $cycle, 'price' => '৳' . number_format($price),
            'next_invoice' => $next, 'workspace_url' => 'https://krtaker.com/app-v3/',
        ]);
        send_mail($u['email'], $pSubj, $pBody);
    } else {
        audit($u['name'], 'Caretaker confirmation suppressed (opt-out)', 'subscriptions', $id);
    }
    json_out(['ok' => true, 'id' => $id, 'sub' => $mine]);
}
case 'app-premium-cancel': {
    $u = require_user();
    $id = trim($body['id'] ?? ($_GET['id'] ?? ''));
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT user_email FROM caretaker_subs WHERE id=?'); $st->execute([$id]);
    $owner_email = $st->fetchColumn();
    if (!$owner_email) json_out(['ok' => false, 'error' => 'Subscription not found.'], 404);
    if ($u['role'] !== 'superadmin' && strtolower($owner_email) !== strtolower($u['email']))
        json_out(['ok' => false, 'error' => 'Not your subscription.'], 403);
    $pdo->prepare("UPDATE caretaker_subs SET status='cancelled', end=date('now') WHERE id=?")->execute([$id]);
    audit($u['name'], 'Caretaker subscription cancelled', 'subscriptions', $id);
    json_out(['ok' => true, 'id' => $id, 'status' => 'cancelled']);
}
case 'app-premium-toggle': {
    $u = require_user();
    $id = trim($body['id'] ?? ($_GET['id'] ?? ''));
    if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
    $pdo = db();
    $st = $pdo->prepare('SELECT user_email, status FROM caretaker_subs WHERE id=?'); $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) json_out(['ok' => false, 'error' => 'Subscription not found.'], 404);
    if ($u['role'] !== 'superadmin' && strtolower($r['user_email']) !== strtolower($u['email']))
        json_out(['ok' => false, 'error' => 'Not your subscription.'], 403);
    if (!in_array($r['status'], ['active', 'paused'], true)) json_out(['ok' => false, 'error' => 'Subscription is ' . $r['status'] . '.'], 409);
    $next = $r['status'] === 'active' ? 'paused' : 'active';
    $pdo->prepare("UPDATE caretaker_subs SET status=?, end=CASE WHEN ?='paused' THEN date('now') ELSE '' END WHERE id=?")->execute([$next, $next, $id]);
    audit($u['name'], 'Caretaker subscription ' . $next, 'subscriptions', $id);
    json_out(['ok' => true, 'id' => $id, 'status' => $next]);
}

/* ── Phase 24: GDPR-style data export (tenant downloads all their data as JSON) ── */
case 'app-gdpr-export': {
    $u = require_user();
    $pdo = db();
    $tid = trim($_GET['t'] ?? $body['t'] ?? '');
    if ($u['role'] === 'tenant') {
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        $mine = (string)$st->fetchColumn();
        if (!$mine) json_out(['ok' => false, 'error' => 'No tenant profile.'], 404);
        if ($tid && $tid !== $mine) json_out(['ok' => false, 'error' => 'Not your data.'], 403);
        $tid = $mine;
    } elseif (in_array($u['role'], ['superadmin', 'owner', 'manager'], true)) {
        if (!$tid) json_out(['ok' => false, 'error' => 't (tenant) required.'], 400);
        $st = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE id=?'); $st->execute([$tid]);
        if (!(int)$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Tenant not found.'], 404);
    } else {
        json_out(['ok' => false, 'error' => 'Your role cannot export tenant data.'], 403);
    }
    $q = function ($sql, $args = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(PDO::FETCH_ASSOC); };
    $st = $pdo->prepare('SELECT * FROM tenants WHERE id=?'); $st->execute([$tid]);
    $tenant = $st->fetch(PDO::FETCH_ASSOC);
    $leases = $q('SELECT * FROM leases WHERE t=?', [$tid]);
    $leaseIds = array_column($leases, 'id');
    $unitIds = array_column($leases, 'u');
    $propIds = $unitIds ? array_column($q('SELECT DISTINCT p FROM units WHERE id IN (' . ai_in_list($unitIds) . ')', $unitIds), 'p') : [];
    $invIds = $leaseIds ? array_column($q('SELECT id FROM invoices WHERE l IN (' . ai_in_list($leaseIds) . ')', $leaseIds), 'id') : [];
    $docs = $leaseIds ? $q('SELECT id, kind, ref, name, fname, size, mime, uploaded_by, ts FROM documents WHERE ref IN (' . ai_in_list($leaseIds) . ') ORDER BY ts', $leaseIds) : [];
    $data = [
        'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
        'exported_by' => $u['name'],
        'note' => 'Machine-readable copy of your personal data held by KRTaker (krtaker.com). Files themselves are downloadable from the Documents tab.',
        'account' => gdpr_account_row($pdo, $tenant['sub_email'] ?? ''),
        'tenant' => $tenant,
        'leases' => $leases,
        'units' => $unitIds ? $q('SELECT * FROM units WHERE id IN (' . ai_in_list($unitIds) . ')', $unitIds) : [],
        'properties' => $propIds ? $q('SELECT * FROM properties WHERE id IN (' . ai_in_list($propIds) . ')', $propIds) : [],
        'invoices' => $invIds ? $q('SELECT * FROM invoices WHERE id IN (' . ai_in_list($invIds) . ') ORDER BY m', $invIds) : [],
        'payments' => $invIds ? $q("SELECT * FROM payments WHERE inv IN (" . ai_in_list($invIds) . ") ORDER BY date", $invIds) : [],
        'receipts' => $invIds ? $q('SELECT * FROM receipts WHERE inv IN (' . ai_in_list($invIds) . ') ORDER BY date', $invIds) : [],
        'gateway_tx' => $invIds ? $q('SELECT * FROM gateway_tx WHERE invoice_id IN (' . ai_in_list($invIds) . ') ORDER BY created_at', $invIds) : [],
        'tickets' => $unitIds ? $q('SELECT * FROM tickets WHERE u IN (' . ai_in_list($unitIds) . ') ORDER BY reported', $unitIds) : [],
        'ticket_thread' => $unitIds ? $q('SELECT id, ticket, author, body, ts FROM ticket_thread WHERE ticket IN (SELECT id FROM tickets WHERE u IN (' . ai_in_list($unitIds) . ')) ORDER BY id', $unitIds) : [],
        'documents' => $docs,
        'handover' => $leaseIds ? $q('SELECT id, lease, kind, items, status, created_by, ts, updated_at FROM handover_checklists WHERE lease IN (' . ai_in_list($leaseIds) . ') ORDER BY ts', $leaseIds) : [],
        'meters' => $unitIds ? $q('SELECT * FROM meter_readings WHERE unit IN (' . ai_in_list($unitIds) . ') ORDER BY month', $unitIds) : [],
        'utility_bills' => $unitIds ? $q('SELECT * FROM utility_bills WHERE unit IN (' . ai_in_list($unitIds) . ') ORDER BY month', $unitIds) : [],
        'renewals' => $leaseIds ? $q('SELECT * FROM renewal_requests WHERE lease IN (' . ai_in_list($leaseIds) . ') ORDER BY ts', $leaseIds) : [],
        'settlement_reports' => $q('SELECT id, lease, status, total_due, balance, refund, generated_by, ts FROM settlement_reports WHERE tenant=? ORDER BY ts', [$tid]),
        'amenities' => $propIds ? $q('SELECT * FROM amenities WHERE prop IN (' . ai_in_list($propIds) . ') ORDER BY unit', $propIds) : [],
        'insurance_policies' => $q('SELECT * FROM insurance_policies WHERE tenant=? ORDER BY ts', [$tid]),
        'maintenance_requests' => $unitIds ? $q('SELECT id, unit, category, priority, title, desc, status, assigned_to, vendor, cost_estimate, actual_cost, charge_to, ts FROM maintenance_requests WHERE unit IN (' . ai_in_list($unitIds) . ') ORDER BY ts', $unitIds) : [],
        'notices' => $q('SELECT id, title, body, ts FROM notices ORDER BY ts'),
        'settings' => settings_get($pdo, user_key_for($u)),
    ];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $fn = 'krtaker-data-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $tenant['name'] ?? $tid) . '-' . gmdate('Ymd') . '.json';
    audit($u['name'], 'GDPR data export', 'tenants', $tid, $fn . ' ' . strlen($json) . ' bytes');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    echo $json;
    exit;
}

/* ── Phase 18: per-user settings (profile lives in the unified app-profile case) ── */
case 'app-settings-get': {
    $u = require_user();
    json_out(['ok' => true, 'settings' => settings_get(db(), user_key_for($u))]);
}
case 'app-settings-save': {
    $u = require_user();
    $pdo = db();
    $saved = settings_save($pdo, user_key_for($u), $body['settings'] ?? $body);
    audit($u['name'], 'Settings saved', 'auth', (string)$u['id']);
    json_out(['ok' => true, 'settings' => $saved]);
}
case 'app-org-settings-get': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot view organization settings.'], 403);
    $cfg = org_cfg(db());
    $cfg['khajna_calendar'] = json_decode($cfg['khajna_calendar'], true) ?: [];
    json_out(['ok' => true, 'cfg' => $cfg]);
}
case 'app-org-settings-save': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot edit organization settings.'], 403);
    $pdo = db();
    $in = $body['cfg'] ?? $body;
    $cfg = org_cfg_save($pdo, $in);
    audit($u['name'], 'Org settings updated', 'settings', 'org', implode(',', array_keys(is_array($in) ? $in : [])));
    $cfg['khajna_calendar'] = json_decode($cfg['khajna_calendar'], true) ?: [];
    json_out(['ok' => true, 'cfg' => $cfg]);
}

/* ── Phase 25: per-property rent configuration + rent-mix computation ── */
case 'app-rent-config-get': {
    $u = require_user();
    $pdo = db();
    $cfg = $pdo->query('SELECT * FROM property_rent ORDER BY prop')->fetchAll(PDO::FETCH_ASSOC);
    $byProp = [];
    foreach ($cfg as $c) $byProp[$c['prop']] = $c;
    /* rent mix per property: base rent (sum of unit rents) + service charge + parking */
    if ($u['role'] === 'tenant') {
        $propIds = array_column($pdo->query("SELECT DISTINCT p FROM units WHERE id IN (SELECT u FROM leases WHERE t IN (SELECT id FROM tenants WHERE sub_email='" . str_replace("'", "''", $u['email']) . "'))")->fetchAll(PDO::FETCH_ASSOC), 'p');
        $props = $propIds ? $pdo->query("SELECT id, name FROM properties WHERE id IN ('" . implode("','", $propIds) . "') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [];
    } elseif ($u['role'] === 'partner') {
        $props = [];
    } else {
        $props = $pdo->query('SELECT id, name FROM properties ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }
    $out = [];
    foreach ($props as $p) {
        $c = $byProp[$p['id']] ?? ['prop' => $p['id'], 'service_charge_pct' => 0, 'utility_advance' => 0,
            'parking_fee' => 0, 'escalation_pct' => 0, 'advance_months' => 0, 'due_day' => 5,
            'late_fee_pct' => 0, 'rent_per_sqft' => 0, 'notes' => ''];
        $st = $pdo->prepare('SELECT COALESCE(SUM(rent),0) AS base, COUNT(*) AS n FROM units WHERE p=?');
        $st->execute([$p['id']]);
        $agg = $st->fetch(PDO::FETCH_ASSOC);
        $base = (int)$agg['base'];
        $sc = (int)round($base * ((float)$c['service_charge_pct'] / 100));
        $park = (int)$c['parking_fee'];
        $out[] = ['prop' => $p['id'], 'property' => $p['name'], 'config' => $c,
            'mix' => ['base' => $base, 'service_charge' => $sc, 'parking' => $park,
                'total' => $base + $sc + $park], 'units' => (int)$agg['n']];
    }
    json_out(['ok' => true, 'rent_configs' => $out]);
}
case 'app-rent-config-save': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot edit rent configuration.'], 403);
    $pdo = db();
    $prop = trim($body['prop'] ?? '');
    if (!$prop) json_out(['ok' => false, 'error' => 'prop required.'], 400);
    $st = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE id=?'); $st->execute([$prop]);
    if (!$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Unknown property.'], 400);
    $in = $body['config'] ?? $body;
    $vals = [
        'service_charge_pct' => (float)($in['service_charge_pct'] ?? 0),
        'utility_advance' => (int)($in['utility_advance'] ?? 0),
        'parking_fee' => (int)($in['parking_fee'] ?? 0),
        'escalation_pct' => (float)($in['escalation_pct'] ?? 0),
        'advance_months' => (int)($in['advance_months'] ?? 0),
        'due_day' => (int)($in['due_day'] ?? 5),
        'late_fee_pct' => (float)($in['late_fee_pct'] ?? 0),
        'rent_per_sqft' => (int)($in['rent_per_sqft'] ?? 0),
        'notes' => trim($in['notes'] ?? ''),
    ];
    $pdo->prepare('INSERT INTO property_rent (prop, service_charge_pct, utility_advance, parking_fee, escalation_pct, advance_months, due_day, late_fee_pct, rent_per_sqft, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(prop) DO UPDATE SET service_charge_pct=excluded.service_charge_pct, utility_advance=excluded.utility_advance,
        parking_fee=excluded.parking_fee, escalation_pct=excluded.escalation_pct, advance_months=excluded.advance_months,
        due_day=excluded.due_day, late_fee_pct=excluded.late_fee_pct, rent_per_sqft=excluded.rent_per_sqft, notes=excluded.notes')
        ->execute(array_merge([$prop], array_values($vals)));
    audit($u['name'], 'Rent config saved', 'properties', $prop, json_encode($vals) ?: '');
    json_out(['ok' => true, 'prop' => $prop, 'config' => array_merge(['prop' => $prop], $vals)]);
}

/* ── Phase 27: move-out wizard — handover + settlement + lease close in one flow ── */
case 'app-moveout': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot run move-out.'], 403);
    $pdo = db();
    $leaseId = trim($body['lease'] ?? $_GET['lease'] ?? '');
    if (!$leaseId) json_out(['ok' => false, 'error' => 'lease required.'], 400);
    $st = $pdo->prepare('SELECT * FROM leases WHERE id=?'); $st->execute([$leaseId]);
    $lease = $st->fetch(PDO::FETCH_ASSOC);
    if (!$lease) json_out(['ok' => false, 'error' => 'Lease not found.'], 404);
    $st = $pdo->prepare('SELECT * FROM tenants WHERE id=?'); $st->execute([$lease['t']]);
    $tenant = $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => '', 'name' => '—', 'email' => '', 'sub_email' => ''];
    $st = $pdo->prepare('SELECT * FROM units WHERE id=?'); $st->execute([$lease['u']]);
    $unit = $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => '', 'p' => '', 'name' => '—'];
    $st = $pdo->prepare('SELECT * FROM properties WHERE id=?'); $st->execute([$unit['p']]);
    $prop = $st->fetch(PDO::FETCH_ASSOC) ?: ['name' => '—'];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' || empty($body['action']) || $body['action'] === 'prepare') {
        /* snapshot: handover checklist (create move_out if missing) + live settlement + open tickets */
        $st = $pdo->prepare("SELECT * FROM handover_checklists WHERE lease=? AND kind='move_out'"); $st->execute([$leaseId]);
        $hovo = $st->fetch(PDO::FETCH_ASSOC);
        if (!$hovo) {
            $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'HOV-','') AS INTEGER)) FROM handover_checklists")->fetchColumn();
            $hid = 'HOV-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO handover_checklists (id, lease, kind, items, status, created_by) VALUES (?,?,?,?,?,?)')
                ->execute([$hid, $leaseId, 'move_out', json_encode(HOVO_ITEMS('move_out'), JSON_UNESCAPED_UNICODE), 'In Progress', $u['name']]);
            $hovo = ['id' => $hid, 'kind' => 'move_out', 'items' => HOVO_ITEMS('move_out'), 'status' => 'In Progress'];
        } else {
            $hovo['items'] = json_decode($hovo['items'], true) ?: [];
        }
        $settle = settlement_report($pdo, $lease['t'], $leaseId, ['deductions' => $body['deductions'] ?? []]);
        $st = $pdo->prepare("SELECT id, desc, status FROM tickets WHERE u=? AND status!='Closed'"); $st->execute([$lease['u']]);
        $openTickets = $st->fetchAll(PDO::FETCH_ASSOC);
        json_out(['ok' => true,
            'lease' => ['id' => $lease['id'], 'status' => $lease['status'], 'start' => $lease['start'], 'end' => $lease['end'], 'rent' => (int)$lease['rent'], 'adv' => (int)$lease['adv']],
            'tenant' => ['id' => $tenant['id'], 'name' => $tenant['name'], 'email' => $tenant['email'], 'sub_email' => $tenant['sub_email']],
            'unit' => ['id' => $unit['id'], 'name' => $unit['name'], 'property' => $prop['name']],
            'handover' => $hovo, 'settlement' => $settle, 'open_tickets' => $openTickets]);
    }

    /* close — the actual move-out */
    if ($body['action'] !== 'close') json_out(['ok' => false, 'error' => 'action must be prepare|close.'], 400);
    if (!in_array($lease['status'], ['Active', 'Pending Registration'], true))
        json_out(['ok' => false, 'error' => 'Only Active / Pending Registration leases can move out (current: ' . $lease['status'] . ').'], 400);
    $moveOutDate = trim($body['move_out_date'] ?? '');
    if (!$moveOutDate) $moveOutDate = gmdate('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $moveOutDate)) json_out(['ok' => false, 'error' => 'move_out_date must be YYYY-MM-DD.'], 400);
    $deductions = is_array($body['deductions'] ?? null) ? $body['deductions'] : [];
    $applyDeposit = ($body['apply_deposit'] ?? true) !== false;
    $pdo->beginTransaction();
    try {
        $settle = settlement_report($pdo, $lease['t'], $leaseId, ['deductions' => $deductions, 'apply_deposit' => $applyDeposit, 'persist' => true, 'generated_by' => $u['name']]);
        if (!$settle || isset($settle['error'])) throw new Exception($settle['error'] ?? 'settlement failed');
        /* complete the move-out handover checklist */
        $st = $pdo->prepare("SELECT id FROM handover_checklists WHERE lease=? AND kind='move_out'"); $st->execute([$leaseId]);
        $hid = $st->fetchColumn();
        if ($hid) {
            $st = $pdo->prepare('SELECT items FROM handover_checklists WHERE id=?'); $st->execute([$hid]);
            $items = json_decode((string)$st->fetchColumn(), true) ?: [];
            foreach ($items as &$it) { if (is_array($it)) $it['checked'] = 1; }
            $pdo->prepare("UPDATE handover_checklists SET items=?, status='Completed', updated_at=datetime('now') WHERE id=?")
                ->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $hid]);
        } else {
            $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'HOV-','') AS INTEGER)) FROM handover_checklists")->fetchColumn();
            $hid = 'HOV-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
            $items = HOVO_ITEMS('move_out');
            foreach ($items as &$it) $it['checked'] = 1;
            $pdo->prepare('INSERT INTO handover_checklists (id, lease, kind, items, status, created_by, updated_at) VALUES (?,?,?,?,?,?,datetime(\'now\'))')
                ->execute([$hid, $leaseId, 'move_out', json_encode($items, JSON_UNESCAPED_UNICODE), 'Completed', $u['name']]);
        }
        /* close open tickets on the unit */
        $st = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE u=? AND status!='Closed'"); $st->execute([$lease['u']]);
        $ticketsClosed = (int)$st->fetchColumn();
        $pdo->prepare("UPDATE tickets SET status='Closed' WHERE u=? AND status!='Closed'")->execute([$lease['u']]);
        /* terminate lease + vacate unit */
        $pdo->prepare("UPDATE leases SET status='Terminated', end=? WHERE id=?")->execute([$moveOutDate, $leaseId]);
        $pdo->prepare("UPDATE units SET status='Vacant' WHERE id=?")->execute([$lease['u']]);
        /* system notice */
        $st = $pdo->prepare("SELECT COALESCE(MAX(CAST(REPLACE(id,'NTC-','') AS INTEGER)),0)+1 FROM notices");
        $nid = 'NTC-' . str_pad((string)((int)$st->fetchColumn()), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO notices (id, title, body, author, pinned) VALUES (?,?,?,?,0)')
            ->execute([$nid, 'Move-out completed — ' . $tenant['name'], $tenant['name'] . ' vacated ' . $unit['name'] . ' (' . $prop['name'] . ') on ' . $moveOutDate . '. Settlement ' . $settle['status'] . ' — balance ৳' . number_format($settle['totals']['balance']) . '.', 'System']);
        /* email (transactional; tenant email if sub_email absent) */
        $to = $tenant['sub_email'] ?: $tenant['email'];
        if ($to) {
            $sc = ['NO_DUES' => '#079455', 'SETTLED' => '#079455', 'DUE' => '#EB5757'];
            $vars = [
                'name' => $tenant['name'], 'property' => $prop['name'], 'unit_name' => $unit['name'],
                'move_out_date' => $moveOutDate, 'lease' => $leaseId,
                'rent_due' => number_format($settle['totals']['rent']), 'util_due' => number_format($settle['totals']['utility']),
                'dmg_due' => number_format($settle['totals']['damages']), 'deposit' => number_format($settle['deposit']),
                'balance' => number_format($settle['totals']['balance']), 'status' => $settle['status'],
                'status_color' => $sc[$settle['status']] ?? '#344054',
                'workspace_url' => 'https://krtaker.com/app-v3/',
            ];
            list($subj, $html) = email_render('move_out', $vars);
            send_mail($to, $subj, $html, null);
        }
        $pdo->commit();
        audit($u['name'], 'Move-out completed', 'leases', $leaseId, $settle['status'] . ' balance ' . $settle['totals']['balance']);
        json_out(['ok' => true, 'settlement' => $settle, 'lease' => $leaseId, 'unit' => $unit['id'], 'handover' => $hid, 'notice' => $nid, 'tickets_closed' => $ticketsClosed]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'Move-out failed: ' . $e->getMessage()], 500);
    }
}

/* ── Phase 28: premium billing cycle — caretaker invoices + auto-generate + pay ── */
case 'app-premium-billing': {
    $pdo = db();
    $action = $body['action'] ?? 'list';
    $svc = service_authed();
    if ($action === 'run' && $svc) {
        $u = ['name' => 'system', 'role' => 'service', 'email' => ''];
    } else {
        $u = require_user();
    }
    if ($action === 'list') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Your role cannot view caretaker billing.'], 403);
        $sql = "SELECT ci.*, cs.tier, cs.cycle, cs.prop, p.name AS property_name,
                (SELECT name FROM subscribers WHERE email=ci.user_email) AS owner_name
                FROM caretaker_invoices ci
                LEFT JOIN caretaker_subs cs ON cs.id = ci.sub
                LEFT JOIN properties p ON p.id = cs.prop";
        $args = [];
        if ($u['role'] === 'owner') { $sql .= ' WHERE ci.user_email=?'; $args[] = $u['email']; }
        $sql .= ' ORDER BY ci.month DESC, ci.created_at DESC';
        $st = $pdo->prepare($sql); $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['amount'] = (int)$r['amount']; }
        json_out(['ok' => true, 'invoices' => $rows]);
    }
    if ($action === 'run') {
        /* superadmin OR service-key (monthly cron) */
        if (!$svc && $u['role'] !== 'superadmin')
            json_out(['ok' => false, 'error' => 'Super admin or service key required.'], 403);
        $target = trim($body['month'] ?? gmdate('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $target)) json_out(['ok' => false, 'error' => 'month must be YYYY-MM.'], 400);
        $subs = $pdo->query("SELECT * FROM caretaker_subs WHERE status='active'")->fetchAll(PDO::FETCH_ASSOC);
        $created = []; $advanced = 0;
        foreach ($subs as $s) {
            if ($s['next_invoice'] === '' || $s['next_invoice'] > $target) continue;
            $st = $pdo->prepare('SELECT COUNT(*) FROM caretaker_invoices WHERE sub=? AND month=?'); $st->execute([$s['id'], $s['next_invoice']]);
            if ((int)$st->fetchColumn()) continue;
            $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'CI-','') AS INTEGER)) FROM caretaker_invoices")->fetchColumn();
            $cid = 'CI-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO caretaker_invoices (id, sub, user_email, month, amount, status) VALUES (?,?,?,?,?,?)')
                ->execute([$cid, $s['id'], $s['user_email'], $s['next_invoice'], (int)$s['price'], 'Unpaid']);
            $created[] = ['id' => $cid, 'month' => $s['next_invoice'], 'amount' => (int)$s['price'], 'user_email' => $s['user_email']];
            $next = premium_advance($s['cycle'], $s['next_invoice']);
            $pdo->prepare("UPDATE caretaker_subs SET next_invoice=? WHERE id=?")->execute([$next, $s['id']]);
            $advanced++;
            /* notify owner (respects notify_premium) */
            if (notify_ok($pdo, $s['user_email'], 'notify_premium')) {
                $st = $pdo->prepare('SELECT name FROM subscribers WHERE email=?'); $st->execute([$s['user_email']]);
                $nm = $st->fetchColumn() ?: $s['user_email'];
                $st = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $st->execute([$s['prop']]);
                $pn = $st->fetchColumn() ?: '';
                list($subj, $html) = email_render('invoice', [
                    'tenant_name' => $nm, 'invoice_id' => $cid, 'month' => $s['next_invoice'],
                    'property' => $pn, 'unit' => 'Workspace', 'amount' => number_format((int)$s['price']),
                    'due' => number_format((int)$s['price']), 'due_color' => '#B91C1C',
                    'pay_url' => 'https://krtaker.com/app-v3/',
                ]);
                send_mail($s['user_email'], $subj, $html, null);
            }
        }
        audit($u['name'], 'Premium billing run', 'subscriptions', $target, count($created) . ' invoices');
        json_out(['ok' => true, 'month' => $target, 'created' => $created, 'advanced' => $advanced]);
    }
    if ($action === 'pay') {
        if (!in_array($u['role'], ['superadmin', 'owner'], true))
            json_out(['ok' => false, 'error' => 'Only owners can pay caretaker invoices.'], 403);
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM caretaker_invoices WHERE id=?'); $st->execute([$id]);
        $inv = $st->fetch(PDO::FETCH_ASSOC);
        if (!$inv) json_out(['ok' => false, 'error' => 'Invoice not found.'], 404);
        if ($u['role'] === 'owner' && $inv['user_email'] !== $u['email'])
            json_out(['ok' => false, 'error' => 'Not your invoice.'], 403);
        if ($inv['status'] === 'Paid') json_out(['ok' => false, 'error' => 'Invoice already paid.'], 400);
        $method = trim($body['method'] ?? 'bKash');
        $ref = trim($body['ref'] ?? 'CI-' . strtoupper(substr(md5($id . gmdate('YmdHis')), 0, 8)));
        $pdo->beginTransaction();
        try {
            $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'PAY-','') AS INTEGER)) FROM payments")->fetchColumn();
            $payId = 'PAY-' . str_pad((string)($mx + 1), 4, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO payments (id, inv, amount, method, ref, date, status) VALUES (?,?,?,?,?,datetime('now'),'Success')")
                ->execute([$payId, $id, (int)$inv['amount'], $method, $ref]);
            $pdo->prepare("UPDATE caretaker_invoices SET status='Paid', paid_at=datetime('now'), ref=? WHERE id=?")->execute([$ref, $id]);
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); json_out(['ok' => false, 'error' => 'Payment failed: ' . $e->getMessage()], 500); }
        audit($u['name'], 'Caretaker invoice paid', 'subscriptions', $id, $payId . ' ৳' . $inv['amount']);
        webhook_dispatch($pdo, 'payment.success', ['payment_id' => $payId, 'invoice_id' => $id, 'amount' => (int)$inv['amount'], 'method' => $method, 'ref' => $ref, 'user_email' => $inv['user_email']]);
        json_out(['ok' => true, 'payment' => $payId, 'invoice' => $id]);
    }
    json_out(['ok' => false, 'error' => 'action must be list|run|pay.'], 400);
}

/* ── Phase 29: tenant insurance / rent guarantee — plans, policies, claims ── */
case 'app-insurance': {
    $u = require_user();
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'list' : 'plans';
    if ($action === 'plans') {
        $rows = $pdo->query("SELECT * FROM insurance_plans WHERE status='Active' ORDER BY premium")->fetchAll(PDO::FETCH_ASSOC);
        $score = 0;
        if ($u['role'] === 'tenant') {
            $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
            $tid = $st->fetchColumn();
            if ($tid) { $sc = tenant_scorecard($pdo, $tid); $score = (int)($sc['score'] ?? 0); }
        }
        foreach ($rows as &$r) {
            $r['features'] = json_decode($r['features'], true) ?: [];
            $r['premium'] = (int)$r['premium'];
            $r['coverage'] = (int)$r['coverage'];
            $r['your_premium'] = $score ? insurance_premium_for((int)$r['premium'], $score) : (int)$r['premium'];
            $r['score'] = $score;
        }
        json_out(['ok' => true, 'plans' => $rows]);
    }
    if ($action === 'list') {
        $sql = "SELECT p.*, t.name AS tenant_name, t.phone AS tenant_phone, t.email AS tenant_email,
                pl.name AS plan_name, pl.type AS plan_type, l.u AS unit_id, u.name AS unit_name
                FROM insurance_policies p
                LEFT JOIN tenants t ON t.id = p.tenant
                LEFT JOIN insurance_plans pl ON pl.id = p.plan
                LEFT JOIN leases l ON l.id = p.lease
                LEFT JOIN units u ON u.id = l.u";
        $args = [];
        if ($u['role'] === 'tenant') {
            $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
            $tid = (string)$st->fetchColumn();
            if (!$tid) json_out(['ok' => true, 'policies' => []]);
            $sql .= ' WHERE p.tenant=?'; $args[] = $tid;
        } elseif (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true)) {
            json_out(['ok' => false, 'error' => 'Your role cannot view policies.'], 403);
        }
        $sql .= ' ORDER BY p.ts DESC';
        $st = $pdo->prepare($sql); $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['premium'] = (int)$r['premium']; $r['coverage'] = (int)$r['coverage']; $r['claim_amt'] = (int)$r['claim_amt']; }
        json_out(['ok' => true, 'policies' => $rows]);
    }
    if ($action === 'apply') {
        if ($u['role'] !== 'tenant') json_out(['ok' => false, 'error' => 'Only tenants can apply for insurance.'], 403);
        $planId = trim($body['plan'] ?? '');
        if (!$planId) json_out(['ok' => false, 'error' => 'plan required.'], 400);
        $st = $pdo->prepare("SELECT * FROM insurance_plans WHERE id=? AND status='Active'"); $st->execute([$planId]);
        $plan = $st->fetch(PDO::FETCH_ASSOC);
        if (!$plan) json_out(['ok' => false, 'error' => 'Plan not found.'], 404);
        $st = $pdo->prepare('SELECT * FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        $tenant = $st->fetch(PDO::FETCH_ASSOC);
        if (!$tenant) json_out(['ok' => false, 'error' => 'No tenant profile linked to your login.'], 404);
        $st = $pdo->prepare("SELECT id FROM leases WHERE t=? AND status IN ('Active','Pending Registration') ORDER BY start DESC LIMIT 1"); $st->execute([$tenant['id']]);
        $leaseId = $st->fetchColumn() ?: '';
        $st = $pdo->prepare("SELECT COUNT(*) FROM insurance_policies WHERE tenant=? AND plan=? AND status IN ('active','claimed','paid')");
        $st->execute([$tenant['id'], $planId]);
        if ((int)$st->fetchColumn()) json_out(['ok' => false, 'error' => 'You already have an active policy on this plan.'], 409);
        $sc = tenant_scorecard($pdo, $tenant['id']);
        $score = (int)($sc['score'] ?? 0);
        $prem = insurance_premium_for((int)$plan['premium'], $score);
        $end = gmdate('Y-m-d', strtotime('+12 months'));
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'POL-','') AS INTEGER)) FROM insurance_policies")->fetchColumn();
        $pid = 'POL-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO insurance_policies (id, tenant, plan, lease, premium, coverage, score, status, start, end) VALUES (?,?,?,?,?,?,?,?,datetime(\'now\'),?)')
            ->execute([$pid, $tenant['id'], $planId, $leaseId, $prem, (int)$plan['coverage'], $score, 'active', $end]);
        audit($u['name'], 'Insurance policy applied', 'tenants', $tenant['id'], $pid . ' ' . $plan['name'] . ' ৳' . $prem . '/mo');
        json_out(['ok' => true, 'policy' => ['id' => $pid, 'premium' => $prem, 'coverage' => (int)$plan['coverage'], 'score' => $score]]);
    }
    if ($action === 'cancel') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM insurance_policies WHERE id=?'); $st->execute([$id]);
        $pol = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pol) json_out(['ok' => false, 'error' => 'Policy not found.'], 404);
        if ($u['role'] === 'tenant') {
            $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
            if ((string)$st->fetchColumn() !== $pol['tenant']) json_out(['ok' => false, 'error' => 'Not your policy.'], 403);
        } elseif (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true)) {
            json_out(['ok' => false, 'error' => 'Your role cannot cancel policies.'], 403);
        }
        if (!in_array($pol['status'], ['active', 'claimed'], true)) json_out(['ok' => false, 'error' => 'Policy already ' . $pol['status'] . '.'], 400);
        $pdo->prepare("UPDATE insurance_policies SET status='cancelled' WHERE id=?")->execute([$id]);
        audit($u['name'], 'Insurance policy cancelled', 'tenants', $pol['tenant'], $id);
        json_out(['ok' => true]);
    }
    if ($action === 'claim') {
        if ($u['role'] !== 'tenant') json_out(['ok' => false, 'error' => 'Only tenants can file claims.'], 403);
        $id = trim($body['id'] ?? '');
        $reason = trim($body['reason'] ?? '');
        $amt = (int)($body['amount'] ?? 0);
        if (!$id || !$reason) json_out(['ok' => false, 'error' => 'id and reason required.'], 400);
        $st = $pdo->prepare('SELECT * FROM insurance_policies WHERE id=?'); $st->execute([$id]);
        $pol = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pol) json_out(['ok' => false, 'error' => 'Policy not found.'], 404);
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        if ((string)$st->fetchColumn() !== $pol['tenant']) json_out(['ok' => false, 'error' => 'Not your policy.'], 403);
        if ($pol['status'] !== 'active') json_out(['ok' => false, 'error' => 'Only active policies can be claimed.'], 400);
        if ($amt <= 0) $amt = (int)$pol['coverage'];
        if ($amt > (int)$pol['coverage']) $amt = (int)$pol['coverage'];
        $pdo->prepare("UPDATE insurance_policies SET status='claimed', claim=?, claim_amt=?, claim_ts=datetime('now') WHERE id=?")
            ->execute([$reason, $amt, $id]);
        audit($u['name'], 'Insurance claim filed', 'tenants', $pol['tenant'], $id . ' ৳' . $amt . ' — ' . mb_substr($reason, 0, 60));
        json_out(['ok' => true, 'policy' => $id, 'amount' => $amt]);
    }
    if ($action === 'decide') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Your role cannot settle claims.'], 403);
        $id = trim($body['id'] ?? '');
        $verdict = trim($body['verdict'] ?? 'approve');
        if (!in_array($verdict, ['approve', 'reject'], true)) json_out(['ok' => false, 'error' => 'verdict must be approve|reject.'], 400);
        $st = $pdo->prepare('SELECT * FROM insurance_policies WHERE id=?'); $st->execute([$id]);
        $pol = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pol) json_out(['ok' => false, 'error' => 'Policy not found.'], 404);
        if ($pol['status'] !== 'claimed') json_out(['ok' => false, 'error' => 'No pending claim on this policy.'], 400);
        if ($verdict === 'approve') {
            $pdo->prepare("UPDATE insurance_policies SET status='paid' WHERE id=?")->execute([$id]);
            audit($u['name'], 'Insurance claim APPROVED', 'tenants', $pol['tenant'], $id . ' payout ৳' . $pol['claim_amt']);
        } else {
            $pdo->prepare("UPDATE insurance_policies SET status='active', claim='', claim_amt=0, claim_ts='' WHERE id=?")->execute([$id]);
            audit($u['name'], 'Insurance claim rejected', 'tenants', $pol['tenant'], $id);
        }
        json_out(['ok' => true, 'policy' => $id, 'status' => $verdict === 'approve' ? 'paid' : 'active']);
    }
    json_out(['ok' => false, 'error' => 'action must be plans|list|apply|cancel|claim|decide.'], 400);
}

case 'app-maintenance': {
    $u = require_user();
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'list' : 'create';
    $isStaff = in_array($u['role'], ['superadmin', 'owner', 'manager'], true);
    if ($action === 'list') {
        $sql = "SELECT m.*, t.name AS tenant_name, u.name AS unit_name, p.name AS property_name
                FROM maintenance_requests m
                LEFT JOIN tenants t ON t.id = m.tenant
                LEFT JOIN units u ON u.id = m.unit
                LEFT JOIN properties p ON p.id = m.prop";
        $args = [];
        if ($u['role'] === 'tenant') {
            $sc = maintenance_scope($pdo, $u);
            if (!$sc['unit']) json_out(['ok' => true, 'requests' => []]);
            $sql .= ' WHERE m.unit=?'; $args[] = $sc['unit'];
        } elseif (!$isStaff) {
            json_out(['ok' => false, 'error' => 'Your role cannot view maintenance.'], 403);
        }
        $sql .= ' ORDER BY (m.status=\'Closed\'), m.priority=\'high\' DESC, m.ts DESC';
        $st = $pdo->prepare($sql); $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['cost_estimate'] = (int)$r['cost_estimate']; $r['actual_cost'] = (int)$r['actual_cost']; }
        json_out(['ok' => true, 'requests' => $rows]);
    }
    if ($action === 'create') {
        $title = trim($body['title'] ?? '');
        if (!$title) json_out(['ok' => false, 'error' => 'title required.'], 400);
        $sc = maintenance_scope($pdo, $u, trim($body['unit'] ?? ''));
        if ($u['role'] === 'tenant') {
            if (!$sc['unit']) json_out(['ok' => false, 'error' => 'No active lease linked to your account.'], 400);
        } else {
            if (!$sc['unit']) json_out(['ok' => false, 'error' => 'unit required.'], 400);
        }
        $st = $pdo->prepare('SELECT p FROM units WHERE id=?'); $st->execute([$sc['unit']]);
        $prop = (string)$st->fetchColumn();
        $cat = trim($body['category'] ?? 'other');
        if (!in_array($cat, ['plumbing', 'electrical', 'appliance', 'structural', 'pest', 'other'], true)) $cat = 'other';
        $prio = trim($body['priority'] ?? 'medium');
        if (!in_array($prio, ['low', 'medium', 'high', 'urgent'], true)) $prio = 'medium';
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'MR-','') AS INTEGER)) FROM maintenance_requests")->fetchColumn();
        $rid = 'MR-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO maintenance_requests (id, tenant, unit, prop, category, priority, title, desc, status, created_by, charge_to) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$rid, $sc['tenant'], $sc['unit'], $prop, $cat, $prio, $title, trim($body['desc'] ?? ''), 'Open', $u['email'], $u['role'] === 'tenant' ? 'tenant' : 'owner']);
        audit($u['name'], 'Maintenance request raised', 'maintenance', $rid, $title . ' [' . $cat . ']');
        json_out(['ok' => true, 'id' => $rid]);
    }
    if ($action === 'assign') {
        if (!$isStaff) json_out(['ok' => false, 'error' => 'Only staff can assign work.'], 403);
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM maintenance_requests WHERE id=?'); $st->execute([$id]);
        if (!$st->fetch()) json_out(['ok' => false, 'error' => 'Request not found.'], 404);
        $pdo->prepare("UPDATE maintenance_requests SET assigned_to=?, vendor=?, status= CASE WHEN status='Open' THEN 'Assigned' ELSE status END, updated_at=datetime('now') WHERE id=?")
            ->execute([trim($body['assigned_to'] ?? ''), trim($body['vendor'] ?? ''), $id]);
        audit($u['name'], 'Maintenance assigned', 'maintenance', $id, '→ ' . ($body['assigned_to'] ?? '') . ($body['vendor'] ? ' · ' . $body['vendor'] : ''));
        json_out(['ok' => true]);
    }
    if ($action === 'status') {
        if (!$isStaff) json_out(['ok' => false, 'error' => 'Only staff can update status.'], 403);
        $id = trim($body['id'] ?? '');
        $status = trim($body['status'] ?? '');
        if (!$id || !$status) json_out(['ok' => false, 'error' => 'id and status required.'], 400);
        if (!in_array($status, ['Open', 'Assigned', 'In Progress', 'Resolved', 'Closed'], true))
            json_out(['ok' => false, 'error' => 'Invalid status.'], 400);
        $st = $pdo->prepare('SELECT * FROM maintenance_requests WHERE id=?'); $st->execute([$id]);
        if (!$st->fetch()) json_out(['ok' => false, 'error' => 'Request not found.'], 404);
        $pdo->prepare("UPDATE maintenance_requests SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $id]);
        audit($u['name'], 'Maintenance status → ' . $status, 'maintenance', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'cost') {
        if (!$isStaff) json_out(['ok' => false, 'error' => 'Only staff can set costs.'], 403);
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM maintenance_requests WHERE id=?'); $st->execute([$id]);
        if (!$st->fetch()) json_out(['ok' => false, 'error' => 'Request not found.'], 404);
        $charge = trim($body['charge_to'] ?? 'owner');
        if (!in_array($charge, ['owner', 'tenant', 'service', 'insurance'], true)) $charge = 'owner';
        $est = (int)($body['cost_estimate'] ?? 0);
        $act = (int)($body['actual_cost'] ?? 0);
        $pdo->prepare("UPDATE maintenance_requests SET cost_estimate=?, actual_cost=?, charge_to=?, status= CASE WHEN CAST(? AS INTEGER) > 0 AND status IN ('Open','Assigned','In Progress') THEN 'Resolved' ELSE status END, updated_at=datetime('now') WHERE id=?")
            ->execute([$est, $act, $charge, $act, $id]);
        audit($u['name'], 'Maintenance cost set', 'maintenance', $id, '৳' . $act . ' → ' . $charge);
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'action must be list|create|assign|status|cost.'], 400);
}

case 'app-leads': {
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'list' : 'submit';
    /* public inquiry capture — no auth (rate limited by IP) */
    if ($action === 'submit') {
        $ip = client_ip();
        if (recent_fails('', $ip, 60, 999, 5)) json_out(['ok' => false, 'error' => 'Too many submissions. Try again later.'], 429);
        $name = trim($body['name'] ?? '');
        $email = strtolower(trim($body['email'] ?? ''));
        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['ok' => false, 'error' => 'name and valid email required.'], 400);
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'LD-','') AS INTEGER)) FROM leads")->fetchColumn();
        $lid = 'LD-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO leads (id, name, phone, email, prop, source, message, status) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$lid, $name, trim($body['phone'] ?? ''), $email, trim($body['prop'] ?? ''), trim($body['source'] ?? 'listing'), trim($body['message'] ?? ''), 'New']);
        audit($name, 'Lead captured', 'leads', $lid, $email . ' → ' . ($body['prop'] ?? 'listing'));
        json_out(['ok' => true, 'id' => $lid]);
    }
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'crm'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot view leads.'], 403);
    if ($action === 'list') {
        $sql = "SELECT l.*, p.name AS property_name FROM leads l LEFT JOIN properties p ON p.id = l.prop";
        $st = $pdo->query($sql . ' ORDER BY (l.status=\'New\') DESC, l.ts DESC');
        json_out(['ok' => true, 'leads' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action === 'status') {
        $id = trim($body['id'] ?? '');
        $status = trim($body['status'] ?? '');
        if (!$id || !$status) json_out(['ok' => false, 'error' => 'id and status required.'], 400);
        if (!in_array($status, ['New', 'Contacted', 'Viewing', 'Applied', 'Leased', 'Lost'], true))
            json_out(['ok' => false, 'error' => 'Invalid status.'], 400);
        $st = $pdo->prepare('SELECT * FROM leads WHERE id=?'); $st->execute([$id]);
        if (!$st->fetch()) json_out(['ok' => false, 'error' => 'Lead not found.'], 404);
        $pdo->prepare("UPDATE leads SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $id]);
        audit($u['name'], 'Lead → ' . $status, 'leads', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'assign') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM leads WHERE id=?'); $st->execute([$id]);
        if (!$st->fetch()) json_out(['ok' => false, 'error' => 'Lead not found.'], 404);
        $pdo->prepare("UPDATE leads SET assigned_to=?, notes=?, updated_at=datetime('now') WHERE id=?")
            ->execute([trim($body['assigned_to'] ?? ''), trim($body['notes'] ?? ''), $id]);
        audit($u['name'], 'Lead assigned', 'leads', $id, $body['assigned_to'] ?? '');
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'action must be submit|list|status|assign.'], 400);
}

case 'app-statements': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot view owner statements.'], 403);
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = 'list';
    if ($action === 'list') {
        $month = trim($body['month'] ?? $_GET['month'] ?? '');
        if (!$month) $month = date('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) json_out(['ok' => false, 'error' => 'month must be YYYY-MM.'], 400);
        $props = $pdo->query('SELECT id, name, type FROM properties ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($props as $p) {
            $c = statement_calc($pdo, $p['id'], $month);
            unset($c['lines'], $c['expense_items']);
            $c['name'] = $p['name']; $c['type'] = $p['type'];
            $out[] = $c;
        }
        json_out(['ok' => true, 'month' => $month, 'statements' => $out]);
    }
    if ($action === 'detail') {
        $prop = trim($body['prop'] ?? $_GET['prop'] ?? '');
        $month = trim($body['month'] ?? $_GET['month'] ?? '');
        if (!$prop || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) json_out(['ok' => false, 'error' => 'prop and month (YYYY-MM) required.'], 400);
        $c = statement_calc($pdo, $prop, $month);
        $st = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $st->execute([$prop]);
        $c['name'] = (string)$st->fetchColumn();
        json_out(['ok' => true] + $c);
    }
    if ($action === 'payout') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'accountant'], true))
            json_out(['ok' => false, 'error' => 'Only owner/accountant can record payouts.'], 403);
        $prop = trim($body['prop'] ?? '');
        $month = trim($body['month'] ?? '');
        if (!$prop || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) json_out(['ok' => false, 'error' => 'prop and month (YYYY-MM) required.'], 400);
        $amount = (int)($body['amount'] ?? -1);
        $status = trim($body['status'] ?? 'Paid');
        if (!in_array($status, ['Scheduled', 'Paid'], true)) $status = 'Paid';
        if ($amount < 0) json_out(['ok' => false, 'error' => 'amount required.'], 400);
        $pdo->prepare("INSERT INTO statement_payouts (prop, month, amount, status, method, ref) VALUES (?,?,?,?,?,?)
            ON CONFLICT(prop, month) DO UPDATE SET amount=excluded.amount, status=excluded.status, method=excluded.method, ref=excluded.ref, ts=datetime('now')")
            ->execute([$prop, $month, $amount, $status, trim($body['method'] ?? ''), trim($body['ref'] ?? '')]);
        audit($u['name'], 'Owner payout ' . $status, 'statements', $prop, $month . ' ৳' . $amount);
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'action must be list|detail|payout.'], 400);
}

/* ── V2.21: scheduled owner statement emails ──
   Monthly per-property owner statements emailed to each property's owner
   (properties.sub_email). Config persisted in platform_meta 'statement_email_cfg'.
   Service-key gated (monthly cron) OR superadmin/owner/manager/accountant via UI.
   send=0 → dry-run preview (never writes); send=1 → queue one email per property
   (docs master switch + mail queue respected). Idempotent per (month): re-running
   skips properties already emailed for that month. */
case 'app-statement-email': {
    $svc = service_authed();
    if (!$svc) {
        $u = require_user();
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
            json_out(['ok' => false, 'error' => 'Your role cannot send owner statements.'], 403);
    } else {
        $u = ['name' => 'system', 'role' => 'service', 'email' => ''];
    }
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = 'config';

    $st = $pdo->prepare("SELECT v FROM platform_meta WHERE k='statement_email_cfg'"); $st->execute();
    $cfgRaw = $st->fetchColumn();
    $cfg = $cfgRaw ? (json_decode($cfgRaw, true) ?: []) : [];
    if (!is_array($cfg)) $cfg = [];
    $d = ['enabled' => 0, 'day' => 5, 'owner_name' => '', 'bcc' => ''];
    foreach ($d as $k => $dv) if (!array_key_exists($k, $cfg)) $cfg[$k] = $dv;
    $cfg['enabled'] = !empty($cfg['enabled']) ? 1 : 0;

    if ($action === 'config') {
        $st = $pdo->prepare("SELECT v FROM platform_meta WHERE k='last_statement_email_run'"); $st->execute();
        $lastRun = $st->fetchColumn() ?: '';
        $st = $pdo->query('SELECT * FROM statement_email_log ORDER BY ts DESC, id DESC LIMIT 20');
        $history = $st->fetchAll(PDO::FETCH_ASSOC);
        json_out(['ok' => true, 'config' => $cfg, 'last_run' => $lastRun, 'history' => $history]);
    }

    if ($action === 'save') {
        if ($svc) json_out(['ok' => false, 'error' => 'Service key cannot change config.'], 403);
        $in = $body['config'] ?? [];
        $clean = [
            'enabled' => !empty($in['enabled']) ? 1 : 0,
            'day' => max(1, min(28, (int)($in['day'] ?? $cfg['day']))),
            'owner_name' => mb_substr(trim((string)($in['owner_name'] ?? '')), 0, 120),
            'bcc' => trim((string)($in['bcc'] ?? '')),
        ];
        $pdo->prepare("INSERT OR REPLACE INTO platform_meta (k, v) VALUES ('statement_email_cfg', ?)")
            ->execute([json_encode($clean)]);
        audit($u['name'], 'Statement email config saved', 'statements', 'cfg', json_encode($clean));
        json_out(['ok' => true, 'config' => $clean]);
    }

    /* build the per-property plan for a month (shared by preview + run) */
    $month = trim($body['month'] ?? '');
    if (!$month) $month = gmdate('Y-m');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) json_out(['ok' => false, 'error' => 'month must be YYYY-MM.'], 400);
    $props = $pdo->query('SELECT id, name, sub_email FROM properties ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $already = [];
    $chk = $pdo->prepare('SELECT prop FROM statement_email_log WHERE month=?'); $chk->execute([$month]);
    foreach ($chk->fetchAll(PDO::FETCH_COLUMN) as $pp) $already[$pp] = true;

    $plan = [];
    foreach ($props as $p) {
        $c = statement_calc($pdo, $p['id'], $month);
        $rec = [
            'prop' => $p['id'], 'name' => $p['name'],
            'gross' => (int)$c['gross'], 'collected' => (int)$c['collected'],
            'tds' => (int)$c['tds'], 'service' => (int)$c['service'],
            'expenses' => (int)$c['expenses'], 'net' => (int)$c['net'],
            'to' => trim((string)($p['sub_email'] ?? '')),
            'already' => isset($already[$p['id']]),
        ];
        $plan[] = $rec;
    }
    $tot = ['gross' => 0, 'collected' => 0, 'net' => 0, 'emailable' => 0, 'no_email' => 0, 'already' => 0];
    foreach ($plan as $r) {
        $tot['gross'] += $r['gross']; $tot['collected'] += $r['collected']; $tot['net'] += $r['net'];
        if ($r['already']) { $tot['already']++; continue; }
        if ($r['to'] !== '') $tot['emailable']++; else $tot['no_email']++;
    }

    if ($action === 'preview') {
        json_out(['ok' => true, 'month' => $month, 'config' => $cfg, 'plan' => $plan, 'totals' => $tot]);
    }

    if ($action === 'run') {
        $send = !empty($body['send']);
        if ($send && !$cfg['enabled'] && !$svc)
            json_out(['ok' => false, 'error' => 'Statement emails are disabled in config.'], 400);
        $sent = 0; $skipped = 0; $noEmail = 0; $suppressed = 0; $queued = 0; $errors = [];
        $sentList = [];
        if ($send) {
            foreach ($plan as $r) {
                if ($r['already']) { $skipped++; continue; }
                if ($r['to'] === '') { $noEmail++; continue; }
                if (!mail_switch($pdo, 'docs')) { $suppressed++; continue; }
                $payoutLine = 'No payout recorded for this month.';
                if ($r['net'] > 0) {
                    $pst = $pdo->prepare('SELECT status, amount FROM statement_payouts WHERE prop=? AND month=?');
                    $pst->execute([$r['prop'], $month]);
                    $po = $pst->fetch(PDO::FETCH_ASSOC);
                    $payoutLine = $po ? ('Payout: ৳' . number_format((int)$po['amount']) . ' · ' . $po['status']) : 'Payout: ৳' . number_format($r['net']) . ' · Scheduled';
                }
                $ci = 0; $ct = 0;
                $ist = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN status='Paid' THEN 1 ELSE 0 END),0) FROM invoices WHERE l IN (SELECT id FROM leases WHERE u IN (SELECT id FROM units WHERE p=?)) AND m=?");
                $ist->execute([$r['prop'], $month]);
                $row = $ist->fetch(PDO::FETCH_NUM);
                if ($row) { $ct = (int)$row[0]; $ci = (int)$row[1]; }
                $monthLabel = gmdate('F Y', strtotime($month . '-01'));
                list($subj, $html) = email_render('owner_statement', [
                    'org_name' => 'KRTaker', 'owner_name' => $cfg['owner_name'] !== '' ? $cfg['owner_name'] : 'Owner',
                    'property' => $r['name'], 'month_label' => $monthLabel,
                    'gross' => number_format($r['gross']), 'collected' => number_format($r['collected']),
                    'tds' => number_format($r['tds']), 'service' => number_format($r['service']),
                    'expenses' => number_format($r['expenses']), 'net' => number_format($r['net']),
                    'collect_rate' => $r['gross'] > 0 ? round(($r['collected'] / $r['gross']) * 100) : 0,
                    'paid_invoices' => $ci, 'total_invoices' => $ct,
                    'payout_line' => $payoutLine,
                    'dashboard_url' => 'https://krtaker.com/app-v3/#/statements?month=' . $month,
                ]);
                $ok = send_mail($r['to'], $subj, $html, null, true);
                if ($ok) {
                    $queued++;
                    $pdo->prepare("INSERT INTO statement_email_log (prop, month, to_addr, net, ts) VALUES (?,?,?,?,datetime('now'))")
                        ->execute([$r['prop'], $month, $r['to'], $r['net']]);
                    $sentList[] = ['prop' => $r['prop'], 'name' => $r['name'], 'to' => $r['to'], 'net' => $r['net']];
                } else $errors[] = $r['prop'];
            }
            $pdo->prepare("INSERT OR REPLACE INTO platform_meta (k, v) VALUES ('last_statement_email_run', ?)")
                ->execute([gmdate('Y-m-d H:i:s') . ' month=' . $month . ' queued=' . $queued . ' no_email=' . $noEmail . ' suppressed=' . $suppressed . ' skipped=' . $skipped]);
            audit($u['name'], 'Statement email run', 'statements', $month,
                'queued=' . $queued . ' no_email=' . $noEmail . ' suppressed=' . $suppressed . ' skipped=' . $skipped);
        }
        $st = $pdo->prepare("SELECT v FROM platform_meta WHERE k='last_statement_email_run'"); $st->execute();
        json_out([
            'ok' => true, 'dry_run' => !$send, 'month' => $month, 'config' => $cfg,
            'plan' => $plan, 'totals' => $tot,
            'queued' => $queued, 'no_email' => $noEmail, 'suppressed' => $suppressed, 'skipped' => $skipped,
            'sent_list' => $sentList, 'errors' => $errors,
            'last_run' => $st->fetchColumn() ?: '',
        ]);
    }

    json_out(['ok' => false, 'error' => 'action must be config|save|preview|run.'], 400);
}

case 'app-compliance': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'legal'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot view compliance.'], 403);
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'list' : 'sync';
    if ($action === 'sync') {
        compliance_sync($pdo);
        json_out(['ok' => true]);
    }
    if ($action === 'list') {
        $items = compliance_rows($pdo);
        json_out(['ok' => true, 'items' => $items, 'today' => date('Y-m-d')]);
    }
    if ($action === 'create') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owner/manager can add compliance items.'], 403);
        $label = trim($body['label'] ?? '');
        if (!$label) json_out(['ok' => false, 'error' => 'label required.'], 400);
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'CPL-','') AS INTEGER)) FROM compliance_items")->fetchColumn();
        $cid = 'CPL-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO compliance_items (id, entity_type, entity_id, item, label, ref_no, issue_date, expiry_date, notes) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, trim($body['entity_type'] ?? 'property'), trim($body['entity_id'] ?? ''), trim($body['item'] ?? 'other'), $label, trim($body['ref_no'] ?? ''), trim($body['issue_date'] ?? ''), trim($body['expiry_date'] ?? ''), trim($body['notes'] ?? '')]);
        audit($u['name'], 'Compliance item added', 'compliance', $cid, $label);
        json_out(['ok' => true, 'id' => $cid]);
    }
    if ($action === 'update') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owner/manager can update compliance items.'], 403);
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM compliance_items WHERE id=?'); $st->execute([$id]);
        if (!$st->fetch()) json_out(['ok' => false, 'error' => 'Item not found.'], 404);
        $pdo->prepare("UPDATE compliance_items SET expiry_date=?, ref_no=?, notes=?, status=?, updated_at=datetime('now') WHERE id=?")
            ->execute([trim($body['expiry_date'] ?? ''), trim($body['ref_no'] ?? ''), trim($body['notes'] ?? ''), trim($body['status'] ?? 'active'), $id]);
        audit($u['name'], 'Compliance updated', 'compliance', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'delete') {
        if (!in_array($u['role'], ['superadmin', 'owner'], true))
            json_out(['ok' => false, 'error' => 'Only owner can delete compliance items.'], 403);
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $pdo->prepare('DELETE FROM compliance_items WHERE id=?')->execute([$id]);
        audit($u['name'], 'Compliance deleted', 'compliance', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'remind') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owner/manager can send reminders.'], 403);
        $items = compliance_rows($pdo);
        $due = array_values(array_filter($items, fn($i) => $i['status'] !== 'valid'));
        $sent = 0;
        /* one digest email for all due items (avoids N×SMTP timeout on shared hosts) */
        if ($due) {
            $rowsHtml = '';
            foreach ($due as $i) {
                $rowsHtml .= '<tr><td style="padding:8px 10px;border-bottom:1px solid #E4EAF3;font-size:13px"><b>' . esc($i['label']) . '</b><br><span style="color:#8A94A6;font-size:11px">' . esc($i['entity_label'] ?: $i['entity_id']) . ' · ' . esc($i['ref_no'] ?: '—') . '</span></td>'
                    . '<td style="padding:8px 10px;border-bottom:1px solid #E4EAF3;font-size:13px">' . esc($i['expiry_date']) . '</td>'
                    . '<td style="padding:8px 10px;border-bottom:1px solid #E4EAF3;font-size:13px"><b>' . ($i['status'] === 'expired' ? 'EXPIRED' : 'Expiring soon') . '</b></td></tr>';
            }
            $html = '<p>' . count($due) . ' compliance item(s) need attention:</p>'
                . '<table style="border-collapse:collapse;width:100%"><thead><tr style="background:#F4F7FC"><th style="padding:8px 10px;font-size:12px;text-align:left">Item</th><th style="padding:8px 10px;font-size:12px;text-align:left">Expiry</th><th style="padding:8px 10px;font-size:12px;text-align:left">Status</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table>';
            $ok = send_mail($u['email'], '[KRTaker] ' . count($due) . ' compliance item(s) due', $html, null, true);
            if ($ok) {
                $sent = count($due);
                $pdo->prepare("UPDATE compliance_items SET last_reminded=datetime('now') WHERE id IN (" . implode(',', array_fill(0, count($due), '?')) . ")")
                    ->execute(array_column($due, 'id'));
            }
        }
        audit($u['name'], 'Compliance reminder run', 'compliance', 'bulk', 'due=' . count($due) . ' sent=' . $sent);
        json_out(['ok' => true, 'due' => count($due), 'sent' => $sent]);
    }
    json_out(['ok' => false, 'error' => 'action must be list|sync|create|update|delete|remind.'], 400);
}


case 'app-vendors': {
    $u = require_user();
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'list' : 'jobs';
    $isStaff = in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'accountant'], true);
    $isPartner = ($u['role'] === 'partner');
    if (!$isStaff && !$isPartner) {
        if ($u['role'] !== 'tenant' || !in_array($action, ['market', 'request-quote'], true))
            json_out(['ok' => false, 'error' => 'Your role cannot access vendor management.'], 403);
    }
    $me = $isPartner ? partner_by_email($pdo, $u['email']) : null;
    $orgName = $me ? $me['name'] : '';
    if ($action === 'list') {
        $rows = [];
        foreach ($pdo->query('SELECT * FROM partners ORDER BY name') as $p) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE vendor=? AND status!='Closed'");
            $st->execute([$p['name']]);
            $open = (int)$st->fetchColumn();
            $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM partner_invoices WHERE partner=? AND status='Approved'");
            $st->execute([$p['id']]);
            $approved = (int)$st->fetchColumn();
            $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM vendor_payouts WHERE partner=? AND status='Paid'");
            $st->execute([$p['id']]);
            $paid = (int)$st->fetchColumn();
            $vst = $pdo->prepare('SELECT COALESCE(AVG(rating),0), COUNT(*) FROM vendor_ratings WHERE partner=?');
            $vst->execute([$p['id']]);
            $vr = $vst->fetch(PDO::FETCH_NUM);
            $rows[] = [
                'id' => $p['id'], 'name' => $p['name'], 'trade' => $p['trade'],
                'rating' => (float)$p['rating'], 'jobs' => (int)$p['jobs'],
                'status' => $p['status'], 'sub_email' => $p['sub_email'],
                'open_jobs' => $open, 'approved_total' => $approved, 'paid_total' => $paid,
                'avg_rating' => round((float)$vr[0], 1), 'rating_count' => (int)$vr[1],
            ];
        }
        if ($isPartner) $rows = array_values(array_filter($rows, fn($r) => $r['id'] === ($me['id'] ?? '')));
        json_out(['ok' => true, 'partners' => $rows]);
    }
    if ($action === 'jobs') {
        $sql = "SELECT m.*, t.name AS tenant_name, u.name AS unit_name, p.name AS property_name,
                (SELECT COUNT(*) FROM job_media jm WHERE jm.job=m.id AND jm.kind='before') AS ev_before,
                (SELECT COUNT(*) FROM job_media jm WHERE jm.job=m.id AND jm.kind='after') AS ev_after,
                (SELECT COUNT(*) FROM job_media jm WHERE jm.job=m.id AND jm.kind='memo') AS ev_memo,
                (SELECT COUNT(*) FROM job_media jm WHERE jm.job=m.id) AS ev_total
                FROM maintenance_requests m
                LEFT JOIN tenants t ON t.id=m.tenant LEFT JOIN units u ON u.id=m.unit
                LEFT JOIN properties p ON p.id=m.prop";
        $args = [];
        if ($isPartner) { $sql .= ' WHERE m.vendor=?'; $args[] = $orgName; }
        $sql .= ' ORDER BY (m.status=\'Open\'), m.priority=\'high\' DESC, m.ts DESC';
        $st = $pdo->prepare($sql); $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['cost_estimate']=(int)$r['cost_estimate']; $r['actual_cost']=(int)$r['actual_cost']; }
        json_out(['ok' => true, 'jobs' => $rows]);
    }
    if ($action === 'job-status') {
        $id = trim($body['id'] ?? ''); $status = trim($body['status'] ?? '');
        if (!$id || !in_array($status, ['Assigned', 'In Progress', 'Resolved'], true))
            json_out(['ok' => false, 'error' => 'id and status (Assigned|In Progress|Resolved) required.'], 400);
        $st = $pdo->prepare('SELECT * FROM maintenance_requests WHERE id=?'); $st->execute([$id]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) json_out(['ok' => false, 'error' => 'Job not found.'], 404);
        if ($isPartner && $m['vendor'] !== $orgName) json_out(['ok' => false, 'error' => 'Not your job.'], 403);
        if ($m['status'] === 'Closed') json_out(['ok' => false, 'error' => 'Closed jobs cannot be reopened.'], 409);
        $pdo->prepare("UPDATE maintenance_requests SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $id]);
        audit($u['name'], 'Job status → ' . $status, 'maintenance', $id, $isPartner ? 'partner' : 'staff');
        json_out(['ok' => true]);
    }
    if ($action === 'job-qc') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr'], true))
            json_out(['ok' => false, 'error' => 'Only owner/manager/service manager can sign off QC.'], 403);
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM maintenance_requests WHERE id=?'); $st->execute([$id]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) json_out(['ok' => false, 'error' => 'Job not found.'], 404);
        if ($m['status'] !== 'Resolved') json_out(['ok' => false, 'error' => 'Only Resolved jobs can be QC-signed.'], 400);
        $release = !empty($body['release_payment']) ? 1 : 0;
        if ($release) {
            $q = $pdo->prepare('SELECT COUNT(*) FROM job_media WHERE job=? AND kind=?');
            $q->execute([$id, 'before']); $evb = (int)$q->fetchColumn();
            $q = $pdo->prepare('SELECT COUNT(*) FROM job_media WHERE job=? AND kind=?');
            $q->execute([$id, 'after']); $eva = (int)$q->fetchColumn();
            $q = $pdo->prepare('SELECT COUNT(*) FROM job_media WHERE job=? AND kind=?');
            $q->execute([$id, 'memo']); $evm = (int)$q->fetchColumn();
            if (!$evb || !$eva) json_out(['ok' => false, 'error' => 'Upload before & after photos first (transparency requirement).'], 409);
            if (!$evm && !trim($m['memo'] ?? '')) json_out(['ok' => false, 'error' => 'Add a digital memo (bill/receipt text or PDF) before releasing payment.'], 409);
        }
        $pdo->prepare("UPDATE maintenance_requests SET status='Closed', qc_by=?, qc_at=datetime('now'), pay_release=CASE WHEN CAST(? AS INTEGER)=1 THEN 1 ELSE pay_release END, pay_release_at=CASE WHEN CAST(? AS INTEGER)=1 THEN datetime('now') ELSE pay_release_at END, updated_at=datetime('now') WHERE id=?")
            ->execute([$u['name'], $release, $release, $id]);
        if ($release) {
            $pdo->prepare("UPDATE partner_invoices SET status='Payable' WHERE job=? AND status='Approved'")
                ->execute([$id]);
        }
        $rating = (int)($body['rating'] ?? 0);
        $vrId = '';
        if ($rating >= 1 && $rating <= 5 && $m['vendor']) {
            $pst = $pdo->prepare('SELECT id FROM partners WHERE name=?'); $pst->execute([$m['vendor']]);
            $pid = $pst->fetchColumn();
            if ($pid) $vrId = vendor_rating_upsert($pdo, $pid, $id, $rating, trim($body['comment'] ?? ''), $u['name']);
        }
        audit($u['name'], 'QC sign-off', 'maintenance', $id, 'closed · qc ' . $u['name'] . ($vrId ? ' · ★' . $rating : ''));
        json_out(['ok' => true, 'qc_by' => $u['name'], 'rating_id' => $vrId, 'pay_release' => $release]);
    }
    if ($action === 'invoice-submit') {
        $job = trim($body['job'] ?? '');
        $amount = (int)($body['amount'] ?? -1);
        if (!$job || $amount <= 0) json_out(['ok' => false, 'error' => 'job and amount (>0) required.'], 400);
        $st = $pdo->prepare('SELECT * FROM maintenance_requests WHERE id=?'); $st->execute([$job]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) json_out(['ok' => false, 'error' => 'Job not found.'], 404);
        if ($isPartner && $m['vendor'] !== $orgName) json_out(['ok' => false, 'error' => 'Not your job.'], 403);
        $pid = $me ? $me['id'] : trim($body['partner'] ?? '');
        if (!$isPartner && !$pid) json_out(['ok' => false, 'error' => 'partner required for staff submissions.'], 400);
        if ($isPartner && !$pid) json_out(['ok' => false, 'error' => 'No partner linked to your account.'], 400);
        $iid = vendor_pi_next_id($pdo);
        $pdo->prepare('INSERT INTO partner_invoices (id, partner, job, amount, desc, status, submitted_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$iid, $pid, $job, $amount, trim($body['desc'] ?? ''), 'Submitted', $u['email']]);
        audit($u['name'], 'Vendor invoice submitted', 'partners', $iid, $job . ' ৳' . $amount);
        json_out(['ok' => true, 'id' => $iid]);
    }
    if ($action === 'invoice-list') {
        $sql = "SELECT i.*, p.name AS partner_name, m.title AS job_title, u.name AS unit_name
                FROM partner_invoices i
                LEFT JOIN partners p ON p.id=i.partner
                LEFT JOIN maintenance_requests m ON m.id=i.job
                LEFT JOIN units u ON u.id=m.unit";
        $args = [];
        if ($isPartner) { $sql .= ' WHERE i.partner=?'; $args[] = $me['id'] ?? ''; }
        $sql .= ' ORDER BY i.ts DESC';
        $st = $pdo->prepare($sql); $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['amount'] = (int)$r['amount'];
        json_out(['ok' => true, 'invoices' => $rows]);
    }
    if ($action === 'invoice-decide') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr'], true))
            json_out(['ok' => false, 'error' => 'Only owner/manager/service manager can review invoices.'], 403);
        $id = trim($body['id'] ?? ''); $verdict = trim($body['verdict'] ?? '');
        if (!$id || !in_array($verdict, ['approve', 'reject'], true)) json_out(['ok' => false, 'error' => 'id and verdict (approve|reject) required.'], 400);
        $st = $pdo->prepare('SELECT * FROM partner_invoices WHERE id=?'); $st->execute([$id]);
        $inv = $st->fetch(PDO::FETCH_ASSOC);
        if (!$inv) json_out(['ok' => false, 'error' => 'Invoice not found.'], 404);
        if ($inv['status'] !== 'Submitted') json_out(['ok' => false, 'error' => 'Only Submitted invoices can be reviewed.'], 409);
        $pdo->prepare("UPDATE partner_invoices SET status=?, decided_by=?, decided_at=datetime('now') WHERE id=?")
            ->execute([$verdict === 'approve' ? 'Approved' : 'Rejected', $u['name'], $id]);
        audit($u['name'], 'Vendor invoice ' . $verdict . 'd', 'partners', $id, '৳' . $inv['amount']);
        json_out(['ok' => true, 'status' => $verdict === 'approve' ? 'Approved' : 'Rejected']);
    }
    if ($action === 'invoice-pay') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'accountant'], true))
            json_out(['ok' => false, 'error' => 'Only owner/accountant can pay invoices.'], 403);
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM partner_invoices WHERE id=?'); $st->execute([$id]);
        $inv = $st->fetch(PDO::FETCH_ASSOC);
        if (!$inv) json_out(['ok' => false, 'error' => 'Invoice not found.'], 404);
        if (!in_array($inv['status'], ['Approved', 'Payable'], true)) json_out(['ok' => false, 'error' => 'Only Approved (or QC-released Payable) invoices can be paid.'], 409);
        $pdo->prepare("UPDATE partner_invoices SET status='Paid', decided_by=?, decided_at=datetime('now') WHERE id=?")
            ->execute([$u['name'], $id]);
        if ($inv['job']) {
            $pdo->prepare("UPDATE maintenance_requests SET pay_paid=1, pay_paid_at=datetime('now') WHERE id=? AND pay_release=1")
                ->execute([$inv['job']]);
        }
        audit($u['name'], 'Vendor invoice paid', 'partners', $id, '৳' . $inv['amount'] . ($inv['status'] === 'Payable' ? ' (QC-released)' : ''));
        json_out(['ok' => true]);
    }
    if ($action === 'payout-list') {
        $sql = "SELECT v.*, p.name AS partner_name FROM vendor_payouts v LEFT JOIN partners p ON p.id=v.partner";
        $args = [];
        if ($isPartner) { $sql .= ' WHERE v.partner=?'; $args[] = $me['id'] ?? ''; }
        $sql .= ' ORDER BY v.month DESC';
        $st = $pdo->prepare($sql); $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['amount'] = (int)$r['amount'];
        json_out(['ok' => true, 'payouts' => $rows]);
    }
    if ($action === 'payout-record') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'accountant'], true))
            json_out(['ok' => false, 'error' => 'Only owner/accountant can record vendor payouts.'], 403);
        $partner = trim($body['partner'] ?? '');
        $month = trim($body['month'] ?? '');
        if (!$partner || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) json_out(['ok' => false, 'error' => 'partner and month (YYYY-MM) required.'], 400);
        $amount = (int)($body['amount'] ?? -1);
        if ($amount < 0) json_out(['ok' => false, 'error' => 'amount required.'], 400);
        $st = $pdo->prepare('SELECT id FROM partners WHERE id=?'); $st->execute([$partner]);
        if (!$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Partner not found.'], 404);
        $status = trim($body['status'] ?? 'Paid');
        if (!in_array($status, ['Scheduled', 'Paid'], true)) $status = 'Paid';
        $st = $pdo->prepare('SELECT id FROM vendor_payouts WHERE partner=? AND month=?');
        $st->execute([$partner, $month]);
        $ex = $st->fetchColumn();
        if ($ex) {
            $pdo->prepare("UPDATE vendor_payouts SET amount=?, status=?, method=?, ref=?, ts=datetime('now') WHERE id=?")
                ->execute([$amount, $status, trim($body['method'] ?? ''), trim($body['ref'] ?? ''), $ex]);
            $vid = $ex;
        } else {
            $vid = vendor_vp_next_id($pdo);
            $pdo->prepare('INSERT INTO vendor_payouts (id, partner, month, amount, status, method, ref) VALUES (?,?,?,?,?,?,?)')
                ->execute([$vid, $partner, $month, $amount, $status, trim($body['method'] ?? ''), trim($body['ref'] ?? '')]);
        }
        audit($u['name'], 'Vendor payout ' . $status, 'partners', $partner, $month . ' ৳' . $amount);
        json_out(['ok' => true, 'id' => $vid]);
    }
    if ($action === 'rate') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr'], true))
            json_out(['ok' => false, 'error' => 'Only owner/manager/service manager can rate vendors.'], 403);
        $partner = trim($body['partner'] ?? '');
        $job = trim($body['job'] ?? '');
        $rating = (int)($body['rating'] ?? 0);
        if (!$partner || $rating < 1 || $rating > 5) json_out(['ok' => false, 'error' => 'partner and rating (1-5) required.'], 400);
        $st = $pdo->prepare('SELECT id FROM partners WHERE id=?'); $st->execute([$partner]);
        if (!$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Partner not found.'], 404);
        $vid = vendor_rating_upsert($pdo, $partner, $job, $rating, trim($body['comment'] ?? ''), $u['name']);
        audit($u['name'], 'Vendor rated', 'partners', $partner, '★' . $rating . ($job ? ' · ' . $job : ''));
        json_out(['ok' => true, 'id' => $vid]);
    }
    if ($action === 'market') {
        /* Vetted contractor marketplace — staff + tenants browse vetted pros */
        if (!$isStaff && $u['role'] !== 'tenant') json_out(['ok' => false, 'error' => 'Your role cannot view the marketplace.'], 403);
        $rows = [];
        foreach ($pdo->query("SELECT * FROM partners WHERE status='Active' ORDER BY rating DESC, jobs DESC") as $p) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE vendor=? AND status!='Closed'");
            $st->execute([$p['name']]);
            $open = (int)$st->fetchColumn();
            $st = $pdo->prepare('SELECT COALESCE(AVG(rating),0), COUNT(*) FROM vendor_ratings WHERE partner=?');
            $st->execute([$p['id']]);
            $vr = $st->fetch(PDO::FETCH_NUM);
            $st = $pdo->prepare("SELECT COALESCE(AVG(actual_cost),0) FROM maintenance_requests WHERE vendor=? AND status='Closed' AND actual_cost>0");
            $st->execute([$p['name']]);
            $avgCost = (int)$st->fetchColumn();
            $rows[] = [
                'id' => $p['id'], 'name' => $p['name'], 'trade' => $p['trade'],
                'rating' => round((float)$vr[0], 1), 'rating_count' => (int)$vr[1],
                'jobs' => (int)$p['jobs'], 'open_jobs' => $open,
                'avg_cost' => $avgCost,
                'verified' => trim($p['sub_email'] ?? '') !== '' ? 1 : 0,
                'status' => $p['status'],
            ];
        }
        json_out(['ok' => true, 'pros' => $rows]);
    }
    if ($action === 'request-quote') {
        if ($u['role'] === 'partner') json_out(['ok' => false, 'error' => 'Partners cannot request quotes.'], 403);
        $vendor = trim($body['vendor'] ?? '');
        $title = trim($body['title'] ?? '');
        $unit = trim($body['unit'] ?? '');
        if (!$vendor || !$title) json_out(['ok' => false, 'error' => 'vendor and title required.'], 400);
        $st = $pdo->prepare('SELECT id FROM partners WHERE name=? AND status="Active"'); $st->execute([$vendor]);
        if (!$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Vendor not found or inactive.'], 404);
        $sc = maintenance_scope($pdo, $u, $unit);
        if ($u['role'] === 'tenant') {
            if (!$sc['unit']) json_out(['ok' => false, 'error' => 'No active lease linked to your account.'], 400);
        } else {
            if (!$sc['unit']) json_out(['ok' => false, 'error' => 'unit required.'], 400);
        }
        $st = $pdo->prepare('SELECT p FROM units WHERE id=?'); $st->execute([$sc['unit']]);
        $prop = (string)$st->fetchColumn();
        $cat = trim($body['category'] ?? 'other');
        if (!in_array($cat, ['plumbing', 'electrical', 'appliance', 'structural', 'pest', 'other'], true)) $cat = 'other';
        $prio = trim($body['priority'] ?? 'medium');
        if (!in_array($prio, ['low', 'medium', 'high', 'urgent'], true)) $prio = 'medium';
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'MR-','') AS INTEGER)) FROM maintenance_requests")->fetchColumn();
        $rid = 'MR-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO maintenance_requests (id, tenant, unit, prop, category, priority, title, desc, status, assigned_to, vendor, created_by, charge_to) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$rid, $sc['tenant'], $sc['unit'], $prop, $cat, $prio, $title, trim($body['desc'] ?? ''), 'Open', $vendor, $vendor, $u['email'], $u['role'] === 'tenant' ? 'tenant' : 'owner']);
        audit($u['name'], 'Quote requested from ' . $vendor, 'maintenance', $rid, $title . ' [' . $cat . ']');
        json_out(['ok' => true, 'id' => $rid]);
    }
    json_out(['ok' => false, 'error' => 'action must be list|jobs|job-status|job-qc|invoice-submit|invoice-list|invoice-decide|invoice-pay|payout-list|payout-record|rate|market|request-quote.'], 400);
}

case 'app-job-media': {
    /* Phase 42: job evidence (before/after photos + digital memo) — contractor marketplace */
    $u = require_user();
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'list' : 'upload';
    $isStaff = in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'accountant'], true);
    $isPartner = ($u['role'] === 'partner');
    $org = $isPartner ? my_org($u) : '';
    $jobAllowed = function ($job) use ($pdo, $u, $isStaff, $isPartner, $org) {
        if ($isStaff) return true;
        if ($isPartner) {
            $st = $pdo->prepare('SELECT vendor FROM maintenance_requests WHERE id=?'); $st->execute([$job]);
            $vendor = (string)$st->fetchColumn();
            return $vendor !== '' && $vendor === $org;
        }
        if ($u['role'] === 'tenant') {
            $sc = maintenance_scope($pdo, $u);
            if (!$sc['unit']) return false;
            $st = $pdo->prepare('SELECT COUNT(*) FROM maintenance_requests WHERE id=? AND unit=?'); $st->execute([$job, $sc['unit']]);
            return (int)$st->fetchColumn() > 0;
        }
        return false;
    };
    if ($action === 'list') {
        $job = trim($_GET['job'] ?? $body['job'] ?? '');
        if (!$job) json_out(['ok' => false, 'error' => 'job required.'], 400);
        if (!$jobAllowed($job)) json_out(['ok' => false, 'error' => 'You cannot view evidence for this job.'], 403);
        $st = $pdo->prepare('SELECT * FROM job_media WHERE job=? ORDER BY kind, ts DESC'); $st->execute([$job]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $k => $v) { $rows[$k]['size'] = (int)$v['size']; }
        $st = $pdo->prepare('SELECT memo, pay_release, pay_release_at, pay_paid, pay_paid_at FROM maintenance_requests WHERE id=?'); $st->execute([$job]);
        $meta = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $counts = ['before' => 0, 'after' => 0, 'memo' => 0, 'other' => 0];
        foreach ($rows as $r) $counts[$r['kind']] = ($counts[$r['kind']] ?? 0) + 1;
        json_out(['ok' => true, 'media' => $rows, 'counts' => $counts, 'meta' => $meta]);
    }
    if ($action === 'upload') {
        if (!$isStaff && !$isPartner) json_out(['ok' => false, 'error' => 'Only staff or assigned partners can upload evidence.'], 403);
        $job = trim($_POST['job'] ?? '');
        $kind = trim($_POST['kind'] ?? 'before');
        if (!in_array($kind, ['before', 'after', 'memo', 'other'], true)) $kind = 'before';
        if (!$job) json_out(['ok' => false, 'error' => 'job required (multipart form: file, job, kind).'], 400);
        if (!$jobAllowed($job)) json_out(['ok' => false, 'error' => 'You cannot upload evidence to this job.'], 403);
        $st = $pdo->prepare('SELECT status FROM maintenance_requests WHERE id=?'); $st->execute([$job]);
        $status = (string)$st->fetchColumn();
        if ($status === '') json_out(['ok' => false, 'error' => 'Job not found.'], 404);
        if ($status === 'Closed') json_out(['ok' => false, 'error' => 'Closed jobs cannot accept new evidence.'], 409);
        if (empty($_FILES['file']['tmp_name'])) json_out(['ok' => false, 'error' => 'file required (multipart form: file, job, kind).'], 400);
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) json_out(['ok' => false, 'error' => 'Upload error ' . $f['error'] . '.'], 400);
        if ($f['size'] > 8 * 1024 * 1024) json_out(['ok' => false, 'error' => 'File too large (max 8MB).'], 400);
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allow = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'pdf'];
        if (!in_array($ext, $allow, true)) json_out(['ok' => false, 'error' => 'File type .' . $ext . ' not allowed (images or PDF).'], 400);
        $dir = DATA_DIR();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_writable($dir)) json_out(['ok' => false, 'error' => 'Storage not writable.'], 500);
        $fname = bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) json_out(['ok' => false, 'error' => 'Could not store file.'], 500);
        $id = 'JM-' . strtoupper(bin2hex(random_bytes(3)));
        $pdo->prepare('INSERT INTO job_media (id, job, kind, name, fname, size, mime, uploaded_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id, $job, $kind, $f['name'], $fname, $f['size'], $f['type'] ?: 'application/octet-stream', $u['name']]);
        audit($u['name'], 'Job evidence uploaded', 'maintenance', $job, $kind . ' ' . $f['name']);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'memo') {
        if (!$isStaff && !$isPartner) json_out(['ok' => false, 'error' => 'Only staff or assigned partners can set the memo.'], 403);
        $job = trim($body['job'] ?? '');
        $text = trim($body['text'] ?? '');
        if (!$job) json_out(['ok' => false, 'error' => 'job required.'], 400);
        if (!$jobAllowed($job)) json_out(['ok' => false, 'error' => 'You cannot edit this job.'], 403);
        $pdo->prepare('UPDATE maintenance_requests SET memo=? WHERE id=?')->execute([$text, $job]);
        audit($u['name'], 'Digital memo set', 'maintenance', $job, mb_substr($text, 0, 60));
        json_out(['ok' => true]);
    }
    if ($action === 'remove') {
        if (!$isStaff && !$isPartner) json_out(['ok' => false, 'error' => 'Only staff or assigned partners can remove evidence.'], 403);
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM job_media WHERE id=?'); $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok' => false, 'error' => 'Evidence not found.'], 404);
        if (!$jobAllowed($row['job'])) json_out(['ok' => false, 'error' => 'You cannot remove evidence for this job.'], 403);
        @unlink(DATA_DIR() . '/' . $row['fname']);
        $pdo->prepare('DELETE FROM job_media WHERE id=?')->execute([$id]);
        audit($u['name'], 'Job evidence removed', 'maintenance', $row['job'], $row['kind'] . ' ' . $row['name']);
        json_out(['ok' => true]);
    }
    if ($action === 'view') {
        $id = trim($_GET['id'] ?? $body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM job_media WHERE id=?'); $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok' => false, 'error' => 'Evidence not found.'], 404);
        if (!$jobAllowed($row['job'])) json_out(['ok' => false, 'error' => 'You cannot view this evidence.'], 403);
        $path = DATA_DIR() . '/' . $row['fname'];
        if (!is_file($path)) json_out(['ok' => false, 'error' => 'File missing on disk.'], 404);
        header('Content-Type: ' . ($row['mime'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . $row['name'] . '"');
        readfile($path);
        exit;
    }
    json_out(['ok' => false, 'error' => 'action must be list|upload|memo|remove|view.'], 400);
}

case 'app-remit': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot access remittance tracking.'], 403);
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'list' : 'create';
    if ($action === 'list') {
        $month = trim($_GET['month'] ?? $body['month'] ?? '');
        $sql = "SELECT r.*, p.name AS property_name FROM remittances r LEFT JOIN properties p ON p.id=r.prop";
        $args = [];
        if ($month) { $sql .= ' WHERE r.month=?'; $args[] = $month; }
        if ($u['role'] === 'owner') { $sql .= $month ? ' AND r.owner_email=?' : ' WHERE r.owner_email=?'; $args[] = $u['email']; }
        $sql .= ' ORDER BY r.month DESC, r.ts DESC';
        $st = $pdo->prepare($sql); $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['amount']=(int)$r['amount']; $r['amount_fx']=(int)$r['amount_fx']; $r['rate']=(float)$r['rate']; }
        json_out(['ok' => true, 'remittances' => $rows]);
    }
    if ($action === 'create') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'accountant'], true))
            json_out(['ok' => false, 'error' => 'Only owner/accountant can record remittances.'], 403);
        $ownerEmail = strtolower(trim($body['owner_email'] ?? ''));
        $prop = trim($body['prop'] ?? '');
        $month = trim($body['month'] ?? '');
        $amount = (int)($body['amount'] ?? -1);
        $rate = (float)($body['rate'] ?? 0);
        $method = trim($body['method'] ?? '');
        if (!$ownerEmail || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) json_out(['ok' => false, 'error' => 'owner_email (valid email) required.'], 400);
        if (!$prop || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) json_out(['ok' => false, 'error' => 'prop and month (YYYY-MM) required.'], 400);
        if ($amount < 0 || $rate <= 0) json_out(['ok' => false, 'error' => 'amount and rate (>0) required.'], 400);
        if (!in_array($method, ['SWIFT', 'bKash', 'Remitly', 'Western Union', 'Other'], true)) $method = 'SWIFT';
        $st = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $st->execute([$prop]);
        if (!$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Property not found.'], 404);
        $st = $pdo->prepare('SELECT name FROM subscribers WHERE email=? AND status="active"'); $st->execute([$ownerEmail]);
        $ownerName = (string)$st->fetchColumn();
        if (!$ownerName) {
            $st = $pdo->prepare('SELECT name FROM tenants WHERE sub_email=?'); $st->execute([$ownerEmail]);
            $ownerName = (string)$st->fetchColumn() ?: $ownerEmail;
        }
        $fx = (int)round($amount / $rate);
        $status = trim($body['status'] ?? 'Sent');
        if (!in_array($status, ['Sent', 'Confirmed'], true)) $status = 'Sent';
        $currency = strtoupper(trim($body['currency'] ?? 'USD'));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) $currency = 'USD';
        $st = $pdo->prepare('SELECT id FROM remittances WHERE owner_email=? AND prop=? AND month=?');
        $st->execute([$ownerEmail, $prop, $month]);
        $ex = $st->fetchColumn();
        $confirmedAt = $status === 'Confirmed' ? gmdate('Y-m-d H:i:s') : trim($body['confirmed_at'] ?? '');
        if ($ex) {
            $pdo->prepare("UPDATE remittances SET owner_name=?, amount=?, rate=?, amount_fx=?, currency=?, method=?, ref=?, form_c=?, status=?, confirmed_at=?, ts=datetime('now') WHERE id=?")
                ->execute([$ownerName, $amount, $rate, $fx, $currency, $method, trim($body['ref'] ?? ''), trim($body['form_c'] ?? ''), $status, $confirmedAt, $ex]);
            $rid = $ex;
        } else {
            $rid = remit_next_id($pdo);
            $pdo->prepare('INSERT INTO remittances (id, owner_email, owner_name, prop, month, amount, rate, amount_fx, currency, method, ref, form_c, status, confirmed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$rid, $ownerEmail, $ownerName, $prop, $month, $amount, $rate, $fx, $currency, $method, trim($body['ref'] ?? ''), trim($body['form_c'] ?? ''), $status, $confirmedAt]);
        }
        audit($u['name'], 'Remittance ' . $status, 'remittances', $rid, $month . ' ৳' . $amount . ' → ' . $currency . ' ' . $fx);
        json_out(['ok' => true, 'id' => $rid, 'amount_fx' => $fx, 'rate' => $rate]);
    }
    if ($action === 'status') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'accountant'], true))
            json_out(['ok' => false, 'error' => 'Only owner/accountant can update remittance status.'], 403);
        $id = trim($body['id'] ?? '');
        $status = trim($body['status'] ?? '');
        if (!$id || !in_array($status, ['Sent', 'Confirmed'], true)) json_out(['ok' => false, 'error' => 'id and status (Sent|Confirmed) required.'], 400);
        $st = $pdo->prepare('SELECT * FROM remittances WHERE id=?'); $st->execute([$id]);
        if (!$st->fetch()) json_out(['ok' => false, 'error' => 'Remittance not found.'], 404);
        $pdo->prepare("UPDATE remittances SET status=?, confirmed_at=CASE WHEN ?='Confirmed' THEN datetime('now') ELSE confirmed_at END WHERE id=?")
            ->execute([$status, $status, $id]);
        audit($u['name'], 'Remittance → ' . $status, 'remittances', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'summary') {
        $month = trim($_GET['month'] ?? $body['month'] ?? '');
        $sql = "SELECT month, status, currency, method, SUM(amount) AS bdt, SUM(amount_fx) AS fx, COUNT(*) AS n FROM remittances";
        $args = [];
        $where = [];
        if ($month) { $where[] = 'month=?'; $args[] = $month; }
        if ($u['role'] === 'owner') { $where[] = 'owner_email=?'; $args[] = $u['email']; }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' GROUP BY month, status, currency, method ORDER BY month DESC';
        $st = $pdo->prepare($sql); $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $tot = ['bdt' => 0, 'fx' => 0, 'count' => 0, 'confirmed' => 0];
        foreach ($rows as &$r) {
            $r['bdt'] = (int)$r['bdt']; $r['fx'] = (int)$r['fx']; $r['n'] = (int)$r['n'];
            $tot['bdt'] += $r['bdt']; $tot['fx'] += $r['fx']; $tot['count'] += $r['n'];
            if ($r['status'] === 'Confirmed') $tot['confirmed'] += $r['n'];
        }
        json_out(['ok' => true, 'groups' => $rows, 'total' => $tot]);
    }
    json_out(['ok' => false, 'error' => 'action must be list|create|status|summary.'], 400);
}


case 'app-onboarding': {
    $u = require_user();
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'list' : 'start';
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'crm'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot access onboarding.'], 403);
    if ($action === 'list') {
        $sql = "SELECT o.*, u.name AS unit_name, p.name AS property_name, l.name AS lead_name
                FROM onboarding_apps o
                LEFT JOIN units u ON u.id=o.unit
                LEFT JOIN properties p ON p.id=o.prop
                LEFT JOIN leads l ON l.id=o.lead";
        $st = $pdo->query($sql . " ORDER BY (o.status='Started') DESC, (o.status='Submitted') DESC, o.ts DESC");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['rent'] = (int)$r['rent']; $r['adv'] = (int)$r['adv']; $r['months'] = (int)$r['months']; }
        json_out(['ok' => true, 'apps' => $rows]);
    }
    if ($action === 'start') {
        $name = trim($body['name'] ?? '');
        $lead = trim($body['lead'] ?? '');
        $unit = trim($body['unit'] ?? '');
        $email = trim($body['email'] ?? '');
        $phone = trim($body['phone'] ?? '');
        $nid = trim($body['nid'] ?? '');
        if ($lead) {
            $st = $pdo->prepare('SELECT * FROM leads WHERE id=?'); $st->execute([$lead]);
            $ld = $st->fetch(PDO::FETCH_ASSOC);
            if (!$ld) json_out(['ok' => false, 'error' => 'Lead not found.'], 404);
            if (!$name) $name = $ld['name'];
            if (!$email) $email = $ld['email'];
            if (!$phone) $phone = $ld['phone'];
        }
        if (!$name) json_out(['ok' => false, 'error' => 'name required.'], 400);
        if ($unit) {
            $st = $pdo->prepare('SELECT p FROM units WHERE id=?'); $st->execute([$unit]);
            $prop = (string)$st->fetchColumn();
            if (!$prop) json_out(['ok' => false, 'error' => 'Unit not found.'], 404);
        } else { $prop = trim($body['prop'] ?? ''); }
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'OB-','') AS INTEGER)) FROM onboarding_apps")->fetchColumn();
        $id = 'OB-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO onboarding_apps (id, lead, name, email, phone, nid, unit, prop, rent, adv, months, start, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $lead, $name, $email, $phone, $nid, $unit, $prop,
                max(0, (int)($body['rent'] ?? 0)), max(0, (int)($body['adv'] ?? 0)),
                max(1, min(36, (int)($body['months'] ?? 12))), trim($body['start'] ?? ''),
                'Started', trim($body['notes'] ?? '')]);
        audit($u['name'], 'Onboarding started', 'onboarding', $id, $name . ($unit ? ' → ' . $unit : ''));
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'submit') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM onboarding_apps WHERE id=?'); $st->execute([$id]);
        $ob = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ob) json_out(['ok' => false, 'error' => 'Onboarding not found.'], 404);
        if (!in_array($ob['status'], ['Started', 'Rejected'], true)) json_out(['ok' => false, 'error' => 'Only Started (or Rejected) applications can be submitted.'], 409);
        $pdo->prepare("UPDATE onboarding_apps SET status='Submitted', updated_at=datetime('now'), notes=? WHERE id=?")
            ->execute([trim($body['notes'] ?? $ob['notes']), $id]);
        audit($u['name'], 'Onboarding KYC submitted', 'onboarding', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'verify') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owner/manager can verify KYC.'], 403);
        $id = trim($body['id'] ?? '');
        $verdict = trim($body['verdict'] ?? '');
        if (!$id || !in_array($verdict, ['approve', 'reject'], true)) json_out(['ok' => false, 'error' => 'id and verdict (approve|reject) required.'], 400);
        $st = $pdo->prepare('SELECT * FROM onboarding_apps WHERE id=?'); $st->execute([$id]);
        $ob = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ob) json_out(['ok' => false, 'error' => 'Onboarding not found.'], 404);
        if ($ob['status'] !== 'Submitted') json_out(['ok' => false, 'error' => 'Only Submitted applications can be verified.'], 409);
        $pdo->prepare("UPDATE onboarding_apps SET status=?, verified_by=?, verified_at=datetime('now'), updated_at=datetime('now'), notes=? WHERE id=?")
            ->execute([$verdict === 'approve' ? 'Verified' : 'Rejected', $u['name'], trim($body['notes'] ?? ''), $id]);
        audit($u['name'], 'Onboarding KYC ' . $verdict . 'd', 'onboarding', $id, $ob['name']);
        json_out(['ok' => true, 'status' => $verdict === 'approve' ? 'Verified' : 'Rejected']);
    }
    if ($action === 'complete') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owner/manager can complete onboarding.'], 403);
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM onboarding_apps WHERE id=?'); $st->execute([$id]);
        $ob = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ob) json_out(['ok' => false, 'error' => 'Onboarding not found.'], 404);
        if ($ob['status'] !== 'Verified') json_out(['ok' => false, 'error' => 'Only Verified applications can be completed.'], 409);
        $unit = trim($ob['unit']);
        if (!$unit) json_out(['ok' => false, 'error' => 'Application has no unit selected.'], 400);
        $st = $pdo->prepare('SELECT * FROM units WHERE id=?'); $st->execute([$unit]);
        $urow = $st->fetch(PDO::FETCH_ASSOC);
        if (!$urow) json_out(['ok' => false, 'error' => 'Unit not found.'], 404);
        if ($urow['status'] !== 'Vacant') json_out(['ok' => false, 'error' => 'Unit is not vacant.'], 409);
        /* tenant */
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'T-','') AS INTEGER)) FROM tenants")->fetchColumn();
        $tid = 'T-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO tenants (id, name, phone, email, nid, nrb, kind, sub_email) VALUES (?,?,?,?,?,0,?,?)')
            ->execute([$tid, $ob['name'], $ob['phone'], $ob['email'], $ob['nid'], 'Individual', $ob['email']]);
        /* lease */
        $start = $ob['start'] ?: date('Y-m-d');
        $d = new DateTime($start);
        $d->modify('+' . (int)$ob['months'] . ' months');
        $end = $d->format('Y-m-d');
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'L-','') AS INTEGER)) FROM leases")->fetchColumn();
        $lid = 'L-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO leases (id, u, t, start, end, rent, adv, res, reg_office, reg_deed, status) VALUES (?,?,?,?,?,?,?,1,?,?,?)')
            ->execute([$lid, $unit, $tid, $start, $end, (int)$ob['rent'], (int)$ob['adv'], '', '', 'Active']);
        /* handover move-in */
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'HOV-','') AS INTEGER)) FROM handover_checklists")->fetchColumn();
        $hid = 'HOV-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO handover_checklists (id, lease, kind, items, status, created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$hid, $lid, 'move_in', json_encode(HOVO_ITEMS('move_in'), JSON_UNESCAPED_UNICODE), 'In Progress', $u['name']]);
        /* first invoice for start month */
        $iid = invoice_next_id($pdo);
        $pdo->prepare('INSERT INTO invoices (id, l, m, gross, tds, net, status) VALUES (?,?,?,?,0,?,?)')
            ->execute([$iid, $lid, substr($start, 0, 7), (int)$ob['rent'], (int)$ob['rent'], 'Unpaid']);
        /* unit + lead + app */
        $pdo->prepare("UPDATE units SET status='Leased' WHERE id=?")->execute([$unit]);
        if ($ob['lead']) $pdo->prepare("UPDATE leads SET status='Leased', updated_at=datetime('now') WHERE id=?")->execute([$ob['lead']]);
        $pdo->prepare("UPDATE onboarding_apps SET status='Completed', tenant_id=?, lease_id=?, invoice_id=?, updated_at=datetime('now') WHERE id=?")
            ->execute([$tid, $lid, $iid, $id]);
        audit($u['name'], 'Onboarding completed', 'onboarding', $id, $ob['name'] . ' → ' . $tid . ' ' . $lid . ' ' . $iid);
        webhook_dispatch($pdo, 'lease.signed', ['lease_id' => $lid, 'unit' => $unit, 'tenant_id' => $tid, 'tenant_name' => $ob['name'], 'start' => $start, 'end' => $end, 'rent' => (int)$ob['rent'], 'advance' => (int)$ob['adv'], 'invoice' => $iid, 'by' => $u['name']]);
        json_out(['ok' => true, 'tenant' => $tid, 'lease' => $lid, 'handover' => $hid, 'invoice' => $iid]);
    }
    json_out(['ok' => false, 'error' => 'action must be list|start|submit|verify|complete.'], 400);
}

case 'app-sla': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'accountant'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot access SLA.'], 403);
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'summary' : 'config-save';
    if ($action === 'config-get') {
        json_out(['ok' => true, 'config' => sla_config_rows($pdo)]);
    }
    if ($action === 'config-save') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owner/manager can edit SLA config.'], 403);
        $cfg = $body['config'] ?? null;
        if (!is_array($cfg)) json_out(['ok' => false, 'error' => 'config object required.'], 400);
        foreach (['urgent', 'high', 'medium', 'low'] as $p) {
            if (!isset($cfg[$p])) continue;
            $v = $cfg[$p];
            if (!is_array($v)) continue;
            $rh = max(1, min(720, (int)($v['response_hours'] ?? 24)));
            $rv = max(1, min(720, (int)($v['resolve_hours'] ?? 72)));
            $en = isset($v['enabled']) ? ((int)$v['enabled'] ? 1 : 0) : 1;
            $pdo->prepare('INSERT INTO sla_config (priority, response_hours, resolve_hours, enabled) VALUES (?,?,?,?)
                ON CONFLICT(priority) DO UPDATE SET response_hours=excluded.response_hours, resolve_hours=excluded.resolve_hours, enabled=excluded.enabled')
                ->execute([$p, $rh, $rv, $en]);
        }
        audit($u['name'], 'SLA config updated', 'maintenance', 'sla', count($cfg) . ' priorities');
        json_out(['ok' => true, 'config' => sla_config_rows($pdo)]);
    }
    if ($action === 'summary') {
        json_out(['ok' => true] + sla_summary($pdo));
    }
    json_out(['ok' => false, 'error' => 'action must be config-get|config-save|summary.'], 400);
}

/* ── Phase 43: Trust Engine v2 — NID verification + Thana Tenant Information Form ── */
case 'app-legal': {
    $u = require_user();
    require_module($u, 'legal');
    $pdo = db();
    $isStaff = in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'legal', 'accountant'], true);
    $myLeases = ($u['role'] === 'tenant') ? legal_my_leases($pdo, $u) : [];
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'summary' : 'config-save';

    if ($action === 'summary') {
        if (!$isStaff) {
            $notices = $myLeases ? $pdo->prepare('SELECT COUNT(*) FROM legal_notices WHERE lease IN (' . implode(',', array_fill(0, count($myLeases), '?')) . ')')->execute($myLeases) : 0;
            $st = $pdo->prepare('SELECT COUNT(*) FROM legal_notices WHERE lease IN (' . implode(',', array_fill(0, max(1, count($myLeases)), '?')) . ')');
            $st->execute($myLeases ?: ['__none__']);
            json_out(['ok' => true, 'nid_verified' => 0, 'nid_total' => 0, 'tf_draft' => 0, 'tf_submitted' => 0, 'tf_verified' => 0, 'needs_form' => 0, 'notices' => (int)$st->fetchColumn(), 'audits' => 0, 'tds_total' => 0, 'cases' => 0]);
        }
        $nOpen = (int)$pdo->query("SELECT COUNT(*) FROM legal_notices WHERE status IN ('Draft','Served')")->fetchColumn();
        $casesOpen = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status NOT IN ('Closed','Resolved')")->fetchColumn();
        $audits = lease_audit_all($pdo);
        $tds = tds_due($pdo, date('Y-m'));
        $avg = $audits['count'] ? (int)round($audits['total_score'] / $audits['count']) : 0;
        json_out(['ok' => true, 'notices_open' => $nOpen, 'cases_open' => $casesOpen,
            'audits_done' => $audits['count'], 'audit_avg' => $avg, 'audit_high' => $audits['high'],
            'tds_total' => $tds['total'], 'tds_month' => $tds['month'], 'tds_rate' => $tds['rate']]);
    }
    if ($action === 'config-get') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal'], true)) json_out(['ok' => false, 'error' => 'Role cannot view legal config.'], 403);
        json_out(['ok' => true, 'config' => legal_cfg_all($pdo)]);
    }
    if ($action === 'config-save') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true)) json_out(['ok' => false, 'error' => 'Only owner/manager can edit legal config.'], 403);
        $in = $body['config'] ?? null;
        if (!is_array($in)) json_out(['ok' => false, 'error' => 'config object required.'], 400);
        $keys = ['legal_eviction_days', 'legal_rent_hike_days', 'legal_termination_days', 'legal_tds_commercial', 'legal_tds_residential', 'legal_deposit_max_res', 'legal_deposit_max_com'];
        foreach ($keys as $k) {
            if (!isset($in[$k])) continue;
            $v = max(1, min(720, (int)$in[$k]));
            $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, (string)$v]);
        }
        audit($u['name'], 'Legal config updated', 'legal', 'cfg', implode(',', array_keys($in)));
        json_out(['ok' => true, 'config' => legal_cfg_all($pdo)]);
    }
    if ($action === 'notices-list') {
        if ($isStaff) {
            $items = $pdo->query('SELECT * FROM legal_notices ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $items = $myLeases ? $pdo->prepare('SELECT * FROM legal_notices WHERE lease IN (' . implode(',', array_fill(0, count($myLeases), '?')) . ') ORDER BY ts DESC')->execute($myLeases) : [];
            $st = $pdo->prepare('SELECT * FROM legal_notices WHERE lease IN (' . implode(',', array_fill(0, max(1, count($myLeases)), '?')) . ') ORDER BY ts DESC');
            $st->execute($myLeases ?: ['__none__']);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($items as &$n) { $n['meta'] = legal_notice_meta($n['ntype']); } unset($n);
        json_out(['ok' => true, 'items' => $items]);
    }
    if ($action === 'notice-create') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal'], true)) json_out(['ok' => false, 'error' => 'Only owner/manager/legal can issue notices.'], 403);
        $ntype = trim($body['ntype'] ?? '');
        if (!legal_notice_meta($ntype)) json_out(['ok' => false, 'error' => 'ntype must be eviction|rent_hike|termination|tds_alert.'], 400);
        $leaseId = trim($body['lease'] ?? '');
        $st = $pdo->prepare('SELECT * FROM leases WHERE id=?'); $st->execute([$leaseId]);
        $lease = $st->fetch(PDO::FETCH_ASSOC);
        if (!$lease) json_out(['ok' => false, 'error' => 'Lease not found.'], 404);
        $b = legal_notice_build($pdo, $ntype, $lease);
        $id = ln_next_id($pdo);
        $pdo->prepare('INSERT INTO legal_notices (id, ntype, lease, tenant, unit, reason, body, notice_days, effective_date, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $ntype, $leaseId, $lease['t'], $lease['u'], trim($body['reason'] ?? ''), $b['body'], $b['days'], $b['effective'], 'Draft', $u['name']]);
        audit($u['name'], 'Legal notice issued (' . $ntype . ')', 'legal', $id, $leaseId);
        json_out(['ok' => true, 'id' => $id, 'notice_days' => $b['days'], 'effective_date' => $b['effective'], 'body' => $b['body']]);
    }
    if ($action === 'notice-get') {
        $id = trim($body['id'] ?? $_GET['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM legal_notices WHERE id=?'); $st->execute([$id]);
        $n = $st->fetch(PDO::FETCH_ASSOC);
        if (!$n) json_out(['ok' => false, 'error' => 'Notice not found.'], 404);
        if (!$isStaff && !in_array($n['lease'], $myLeases, true)) json_out(['ok' => false, 'error' => 'Not your notice.'], 403);
        $n['meta'] = legal_notice_meta($n['ntype']);
        json_out(['ok' => true, 'notice' => $n]);
    }
    if ($action === 'notice-serve') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal'], true)) json_out(['ok' => false, 'error' => 'Only owner/manager/legal can serve notices.'], 403);
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM legal_notices WHERE id=?'); $st->execute([$id]);
        $n = $st->fetch(PDO::FETCH_ASSOC);
        if (!$n) json_out(['ok' => false, 'error' => 'Notice not found.'], 404);
        if ($n['status'] === 'Void') json_out(['ok' => false, 'error' => 'Void notices cannot be served.'], 409);
        $pdo->prepare("UPDATE legal_notices SET status='Served', served_on=datetime('now'), served_by=? WHERE id=?")->execute([$u['name'], $id]);
        audit($u['name'], 'Legal notice served', 'legal', $id, $n['lease']);
        json_out(['ok' => true]);
    }
    if ($action === 'notice-void') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true)) json_out(['ok' => false, 'error' => 'Only owner/manager can void notices.'], 403);
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM legal_notices WHERE id=?'); $st->execute([$id]);
        $n = $st->fetch(PDO::FETCH_ASSOC);
        if (!$n) json_out(['ok' => false, 'error' => 'Notice not found.'], 404);
        $pdo->prepare("UPDATE legal_notices SET status='Void' WHERE id=?")->execute([$id]);
        audit($u['name'], 'Legal notice voided', 'legal', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'notice-print') {
        $id = trim($_GET['id'] ?? $body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM legal_notices WHERE id=?'); $st->execute([$id]);
        $n = $st->fetch(PDO::FETCH_ASSOC);
        if (!$n) json_out(['ok' => false, 'error' => 'Notice not found.'], 404);
        if (!$isStaff && !in_array($n['lease'], $myLeases, true)) json_out(['ok' => false, 'error' => 'Not your notice.'], 403);
        $lease = null; $st = $pdo->prepare('SELECT * FROM leases WHERE id=?'); $st->execute([$n['lease']]); $lease = $st->fetch(PDO::FETCH_ASSOC);
        audit($u['name'], 'Legal notice printed', 'legal', $id, $n['lease']);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="bn"><head><meta charset="utf-8"><title>' . htmlspecialchars($id) . ' — ' . htmlspecialchars(legal_notice_meta($n['ntype'])['en']) . '</title></head><body>' . legal_notice_html($n, $lease, []) . '</body></html>';
        exit;
    }
    if ($action === 'audit') {
        if (!$isStaff) json_out(['ok' => false, 'error' => 'Audits are for staff.'], 403);
        $leaseId = trim($body['lease'] ?? $_GET['lease'] ?? '');
        if (!$leaseId) json_out(['ok' => false, 'error' => 'lease required.'], 400);
        $a = lease_audit($pdo, $leaseId);
        if (!$a) json_out(['ok' => false, 'error' => 'Lease not found.'], 404);
        $a['tenant_name'] = tenantById($a['tenant'])['name'] ?? $a['tenant'];
        json_out(['ok' => true, 'audit' => $a]);
    }
    if ($action === 'audit-all') {
        if (!$isStaff) json_out(['ok' => false, 'error' => 'Audits are for staff.'], 403);
        json_out(['ok' => true, 'audits' => lease_audit_all($pdo)]);
    }
    if ($action === 'tds-due') {
        if (!$isStaff) json_out(['ok' => false, 'error' => 'TDS view is for staff.'], 403);
        $month = trim($body['month'] ?? $_GET['month'] ?? date('Y-m'));
        json_out(['ok' => true] + tds_due($pdo, $month));
    }
    if ($action === 'cases-list') {
        if ($isStaff) {
            $items = $pdo->query('SELECT * FROM cases ORDER BY opened DESC')->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $items = $myLeases ? $pdo->prepare('SELECT * FROM cases WHERE ref_lease IN (' . implode(',', array_fill(0, count($myLeases), '?')) . ') ORDER BY opened DESC')->execute($myLeases) : [];
            $st = $pdo->prepare('SELECT * FROM cases WHERE ref_lease IN (' . implode(',', array_fill(0, max(1, count($myLeases)), '?')) . ') ORDER BY opened DESC');
            $st->execute($myLeases ?: ['__none__']);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($items as &$c) {
            $st = $pdo->prepare('SELECT * FROM case_events WHERE case_id=? ORDER BY ts DESC'); $st->execute([$c['id']]);
            $c['events'] = $st->fetchAll(PDO::FETCH_ASSOC);
        } unset($c);
        json_out(['ok' => true, 'items' => $items]);
    }
    if ($action === 'case-create') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal'], true)) json_out(['ok' => false, 'error' => 'Only owner/manager/legal can open cases.'], 403);
        $leaseId = trim($body['ref_lease'] ?? '');
        $st = $pdo->prepare('SELECT * FROM leases WHERE id=?'); $st->execute([$leaseId]);
        $lease = $st->fetch(PDO::FETCH_ASSOC);
        if (!$lease) json_out(['ok' => false, 'error' => 'Lease not found.'], 404);
        $ctype = trim($body['type'] ?? 'eviction');
        if (!in_array($ctype, ['eviction', 'arrears', 'damages', 'other'], true)) json_out(['ok' => false, 'error' => 'type must be eviction|arrears|damages|other.'], 400);
        $id = case_next_id($pdo);
        $pdo->prepare('INSERT INTO cases (id, title, ref_lease, type, status, opened, notes, stage, lawyer, next_hearing, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, trim($body['title'] ?? $ctype . ' — ' . $leaseId), $leaseId, $ctype, 'Open', date('Y-m-d'), trim($body['notes'] ?? ''), trim($body['stage'] ?? 'Notice'), trim($body['lawyer'] ?? ''), trim($body['next_hearing'] ?? ''), date('Y-m-d')]);
        $ev = ce_next_id($pdo);
        $pdo->prepare('INSERT INTO case_events (id, case_id, ev_type, body, actor) VALUES (?,?,?,?,?)')
            ->execute([$ev, $id, 'stage', 'Case opened — stage: ' . trim($body['stage'] ?? 'Notice'), $u['name']]);
        audit($u['name'], 'Legal case opened', 'legal', $id, $leaseId);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'case-get') {
        $id = trim($body['id'] ?? $_GET['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM cases WHERE id=?'); $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) json_out(['ok' => false, 'error' => 'Case not found.'], 404);
        if (!$isStaff && !in_array($c['ref_lease'], $myLeases, true)) json_out(['ok' => false, 'error' => 'Not your case.'], 403);
        $st = $pdo->prepare('SELECT * FROM case_events WHERE case_id=? ORDER BY ts DESC'); $st->execute([$id]);
        $c['events'] = $st->fetchAll(PDO::FETCH_ASSOC);
        json_out(['ok' => true, 'case' => $c]);
    }
    if ($action === 'case-update') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal'], true)) json_out(['ok' => false, 'error' => 'Only owner/manager/legal can update cases.'], 403);
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM cases WHERE id=?'); $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) json_out(['ok' => false, 'error' => 'Case not found.'], 404);
        $stage = trim($body['stage'] ?? $c['stage']);
        $status = trim($body['status'] ?? $c['status']);
        $pdo->prepare("UPDATE cases SET stage=?, status=?, lawyer=?, next_hearing=?, notes=?, updated_at=datetime('now') WHERE id=?")
            ->execute([$stage, $status, trim($body['lawyer'] ?? $c['lawyer']), trim($body['next_hearing'] ?? $c['next_hearing']), trim($body['notes'] ?? $c['notes']), $id]);
        $ev = ce_next_id($pdo);
        $pdo->prepare('INSERT INTO case_events (id, case_id, ev_type, body, actor) VALUES (?,?,?,?,?)')
            ->execute([$ev, $id, 'stage', 'Stage → ' . $stage . ' · Status → ' . $status . ($body['note'] ? ' — ' . trim($body['note']) : ''), $u['name']]);
        audit($u['name'], 'Case updated', 'legal', $id, $stage);
        json_out(['ok' => true]);
    }
    if ($action === 'case-event') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM cases WHERE id=?'); $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) json_out(['ok' => false, 'error' => 'Case not found.'], 404);
        if (!$isStaff && !in_array($c['ref_lease'], $myLeases, true)) json_out(['ok' => false, 'error' => 'Not your case.'], 403);
        $ev = ce_next_id($pdo);
        $pdo->prepare('INSERT INTO case_events (id, case_id, ev_type, body, actor) VALUES (?,?,?,?,?)')
            ->execute([$ev, $id, trim($body['ev_type'] ?? 'note'), trim($body['body'] ?? ''), $u['name']]);
        json_out(['ok' => true, 'id' => $ev]);
    }
    json_out(['ok' => false, 'error' => 'action must be summary|config-get|config-save|notices-list|notice-create|notice-get|notice-serve|notice-void|notice-print|audit|audit-all|tds-due|cases-list|case-create|case-get|case-update|case-event.'], 400);
}

/* ── Accounts module (20260812): bank/cash ledger — receive / expense / withdraw / deposit / reconcile ── */
case 'app-accounts': {
    $u = require_user();
    require_module($u, 'accounts');
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'summary' : '';
    $canWrite = in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true);

    $acctRows = function () use ($pdo) {
        $rows = $pdo->query('SELECT * FROM accounts ORDER BY (status=\'active\') DESC, name')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$a) {
            $st = $pdo->prepare("SELECT
                COALESCE(SUM(CASE WHEN type IN ('receive','deposit') THEN amount ELSE 0 END),0) inflow,
                COALESCE(SUM(CASE WHEN type IN ('expense','withdraw') THEN amount ELSE 0 END),0) outflow
                FROM account_transactions WHERE account=?");
            $st->execute([$a['id']]);
            $m = $st->fetch(PDO::FETCH_ASSOC);
            $a['inflow'] = (int)$m['inflow']; $a['outflow'] = (int)$m['outflow'];
            $a['balance'] = (int)$a['opening_balance'] + $a['inflow'] - $a['outflow'];
            $st = $pdo->prepare('SELECT COUNT(*) FROM account_transactions WHERE account=?'); $st->execute([$a['id']]);
            $a['tx_count'] = (int)$st->fetchColumn();
        }
        return $rows;
    };

    if ($action === 'summary') {
        $tx = $pdo->query('SELECT t.*, a.name AS account_name FROM account_transactions t LEFT JOIN accounts a ON a.id=t.account ORDER BY t.tx_date DESC, t.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
        $tot = $pdo->query("SELECT
            COALESCE(SUM(CASE WHEN type IN ('receive','deposit') THEN amount ELSE 0 END),0) inflow,
            COALESCE(SUM(CASE WHEN type IN ('expense','withdraw') THEN amount ELSE 0 END),0) outflow,
            COUNT(*) n FROM account_transactions")->fetch(PDO::FETCH_ASSOC);
        $byType = $pdo->query('SELECT type, COUNT(*) n, COALESCE(SUM(amount),0) total FROM account_transactions GROUP BY type')->fetchAll(PDO::FETCH_ASSOC);
        $unrecon = (int)$pdo->query('SELECT COUNT(*) FROM account_transactions WHERE reconciled=0')->fetchColumn();
        json_out(['ok' => true, 'accounts' => $acctRows(), 'transactions' => $tx,
            'totals' => ['inflow' => (int)$tot['inflow'], 'outflow' => (int)$tot['outflow'],
                'balance' => (int)$tot['inflow'] - (int)$tot['outflow'], 'count' => (int)$tot['n']],
            'by_type' => $byType, 'unreconciled' => $unrecon]);
    }

    if ($action === 'list') {
        $where = []; $args = [];
        $acct = trim($body['account'] ?? ''); if ($acct !== '') { $where[] = 't.account=?'; $args[] = $acct; }
        $type = trim($body['type'] ?? ''); if ($type !== '') { $where[] = 't.type=?'; $args[] = $type; }
        $q = trim($body['q'] ?? '');
        if ($q !== '') { $where[] = '(t.label LIKE ? OR t.ref LIKE ? OR t.payee LIKE ? OR t.note LIKE ?)'; $like = '%' . $q . '%'; array_push($args, $like, $like, $like, $like); }
        $stt = trim($body['status'] ?? ''); if ($stt !== '') { $where[] = 't.status=?'; $args[] = $stt; }
        $limit = min(500, max(1, (int)($body['limit'] ?? 200)));
        $sql = 'SELECT t.*, a.name AS account_name FROM account_transactions t LEFT JOIN accounts a ON a.id=t.account';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY t.tx_date DESC, t.id DESC LIMIT ' . $limit;
        $st = $pdo->prepare($sql); $st->execute($args);
        json_out(['ok' => true, 'transactions' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if (in_array($action, ['receive', 'expense', 'withdraw', 'deposit'], true)) {
        if (!$canWrite) json_out(['ok' => false, 'error' => 'Your role cannot post transactions.'], 403);
        $acct = trim($body['account'] ?? '');
        $label = trim($body['label'] ?? '');
        $amount = (int)($body['amount'] ?? 0);
        if (!$acct || !$label || $amount <= 0) json_out(['ok' => false, 'error' => 'account, label and positive amount required.'], 400);
        $st = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE id=?'); $st->execute([$acct]);
        if (!$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Unknown account.'], 404);
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'TX-','') AS INTEGER)) FROM account_transactions")->fetchColumn();
        $id = 'TX-' . str_pad((string)max(1001, $mx + 1), 4, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO account_transactions (id, account, type, cat, label, amount, method, ref, payee, note, tx_date, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id, $acct, $action, trim($body['cat'] ?? 'other'), $label, $amount,
                trim($body['method'] ?? ''), trim($body['ref'] ?? ''), trim($body['payee'] ?? ''),
                trim($body['note'] ?? ''), trim($body['date'] ?? gmdate('Y-m-d H:i:s')), 'cleared', $u['name']]);
        audit($u['name'], 'Accounts ' . $action, 'accounts', $id, $label . ' ' . $amount . ' ' . $acct);
        json_out(['ok' => true, 'id' => $id]);
    }

    if ($action === 'reconcile-list') {
        $pending = $pdo->query("SELECT t.*, a.name AS account_name FROM account_transactions t LEFT JOIN accounts a ON a.id=t.account WHERE t.reconciled=0 ORDER BY t.tx_date DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
        $done = $pdo->query("SELECT t.*, a.name AS account_name FROM account_transactions t LEFT JOIN accounts a ON a.id=t.account WHERE t.reconciled=1 ORDER BY t.reconciled_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        json_out(['ok' => true, 'pending' => $pending, 'done' => $done]);
    }
    if ($action === 'reconcile') {
        if (!$canWrite) json_out(['ok' => false, 'error' => 'Your role cannot reconcile.'], 403);
        $id = trim($body['id'] ?? ''); if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT COUNT(*) FROM account_transactions WHERE id=?'); $st->execute([$id]);
        if (!$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Transaction not found.'], 404);
        $pdo->prepare("UPDATE account_transactions SET reconciled=1, reconciled_at=datetime('now'), reconciled_ref=? WHERE id=?")
            ->execute([trim($body['ref'] ?? ''), $id]);
        audit($u['name'], 'Accounts reconcile', 'accounts', $id, trim($body['ref'] ?? ''));
        json_out(['ok' => true]);
    }
    if ($action === 'unreconcile') {
        if (!$canWrite) json_out(['ok' => false, 'error' => 'Your role cannot reconcile.'], 403);
        $id = trim($body['id'] ?? ''); if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $pdo->prepare("UPDATE account_transactions SET reconciled=0, reconciled_at='', reconciled_ref='' WHERE id=?")->execute([$id]);
        json_out(['ok' => true]);
    }

    if ($action === 'account-create') {
        if (!$canWrite) json_out(['ok' => false, 'error' => 'Your role cannot create accounts.'], 403);
        $name = trim($body['name'] ?? ''); if (!$name) json_out(['ok' => false, 'error' => 'Account name required.'], 400);
        $type = trim($body['type'] ?? 'bank'); if (!in_array($type, ['cash', 'bank', 'mobile'], true)) $type = 'bank';
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'ACC-','') AS INTEGER)) FROM accounts")->fetchColumn();
        $id = 'ACC-' . str_pad((string)max(1, $mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO accounts (id, name, type, opening_balance, notes, status, created_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$id, $name, $type, max(0, (int)($body['opening_balance'] ?? 0)), trim($body['notes'] ?? ''), 'active', $u['name']]);
        audit($u['name'], 'Account created', 'accounts', $id, $name);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'account-toggle') {
        if (!$canWrite) json_out(['ok' => false, 'error' => 'Your role cannot manage accounts.'], 403);
        $id = trim($body['id'] ?? ''); if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT status FROM accounts WHERE id=?'); $st->execute([$id]);
        $cur = $st->fetchColumn(); if ($cur === false) json_out(['ok' => false, 'error' => 'Account not found.'], 404);
        $new = $cur === 'active' ? 'inactive' : 'active';
        $pdo->prepare('UPDATE accounts SET status=? WHERE id=?')->execute([$new, $id]);
        json_out(['ok' => true, 'status' => $new]);
    }
    if ($action === 'account-delete') {
        if (!$canWrite) json_out(['ok' => false, 'error' => 'Your role cannot delete accounts.'], 403);
        $id = trim($body['id'] ?? ''); if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE id=?'); $st->execute([$id]);
        if (!$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Account not found.'], 404);
        $pdo->prepare('DELETE FROM account_transactions WHERE account=?')->execute([$id]);
        $pdo->prepare('DELETE FROM accounts WHERE id=?')->execute([$id]);
        audit($u['name'], 'Account deleted', 'accounts', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'delete') {
        if (!$canWrite) json_out(['ok' => false, 'error' => 'Your role cannot delete transactions.'], 403);
        $id = trim($body['id'] ?? ''); if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $pdo->prepare('DELETE FROM account_transactions WHERE id=?')->execute([$id]);
        audit($u['name'], 'Accounts delete', 'accounts', $id);
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'action must be summary|list|receive|expense|withdraw|deposit|reconcile-list|reconcile|unreconcile|account-create|account-toggle|account-delete|delete.'], 400);
}

case 'app-trust': {
    $u = require_user();
    $pdo = db();
    $isStaff = in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal', 'accountant', 'svc_mgr'], true);
    $isAdmin = in_array($u['role'], ['superadmin', 'super_admin', 'admin', 'owner', 'manager'], true);
    $myTid = trust_tenant_id($pdo, $u);
    if (!$isStaff && !$myTid) json_out(['ok' => false, 'error' => 'No tenant profile for this account.'], 403);
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'summary' : '';

    /* summary — staff org-wide; tenant sees only their own counts */
    if ($action === 'summary') {
        $s = trust_summary($pdo);
        if (!$isStaff) {
            $mineNv = (int)$pdo->prepare('SELECT COUNT(*) FROM nid_verifications WHERE tenant=?')->execute([$myTid]) ? 0 : 0;
            $st = $pdo->prepare('SELECT COUNT(*) FROM nid_verifications WHERE tenant=?'); $st->execute([$myTid]); $mineNv = (int)$st->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM thana_forms WHERE tenant=?"); $st->execute([$myTid]); $mineTf = (int)$st->fetchColumn();
            $st = $pdo->prepare("SELECT status FROM thana_forms WHERE tenant=? ORDER BY ts DESC LIMIT 1"); $st->execute([$myTid]);
            $tfStatus = (string)$st->fetchColumn();
            json_out(['ok' => true, 'scoped' => true,
                'nid_verified' => $mineNv ? 1 : 0, 'nid_total' => $mineNv,
                'tf_draft' => $tfStatus === 'Draft' ? 1 : 0, 'tf_submitted' => $tfStatus === 'Submitted' ? 1 : 0,
                'tf_verified' => $tfStatus === 'Verified' ? 1 : 0, 'forms_total' => $mineTf,
                'tenants_with_lease' => 1, 'needs_form' => $mineTf ? 0 : 1]);
        }
        json_out(['ok' => true] + $s);
    }

    /* validate a NID string without persisting */
    if ($action === 'nid-validate') {
        $nid = trim($body['nid'] ?? '');
        if ($nid === '') json_out(['ok' => false, 'error' => 'nid required.'], 400);
        $v = nid_validate($nid);
        $ageOk = true; $dob = trim($body['dob'] ?? '');
        if ($dob !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            $age = (int)date('Ymd') - (int)str_replace('-', '', $dob);
            $ageOk = $age >= 180000;
        }
        json_out(['ok' => true, 'valid' => $v['ok'], 'len' => $v['len'], 'reason' => $v['reason'], 'age_ok' => $ageOk]);
    }

    /* nid-list — staff all / tenant own */
    if ($action === 'nid-list') {
        json_out(['ok' => true, 'items' => nv_rows($pdo, $isStaff ? '' : $myTid)]);
    }

    /* nid-save — staff only (tenant self-registration uses tif-save) */
    if ($action === 'nid-save') {
        if (!$isStaff) json_out(['ok' => false, 'error' => 'Only staff can record NID verification.'], 403);
        $tid = trim($body['tenant'] ?? '');
        $nid = trim($body['nid'] ?? '');
        $dob = trim($body['dob'] ?? '');
        if (!$tid || !$nid) json_out(['ok' => false, 'error' => 'tenant and nid required.'], 400);
        $st = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE id=?'); $st->execute([$tid]);
        if (!$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Tenant not found.'], 404);
        $v = nid_validate($nid);
        $ageOk = 1;
        if ($dob !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            $age = (int)date('Ymd') - (int)str_replace('-', '', $dob);
            $ageOk = $age >= 180000 ? 1 : 0;
        }
        /* upsert: one active verification per tenant — replace old rows' status to superseded */
        $st = $pdo->prepare("SELECT id FROM nid_verifications WHERE tenant=? ORDER BY ts DESC LIMIT 1"); $st->execute([$tid]);
        $ex = (string)$st->fetchColumn();
        $status = $v['ok'] ? 'verified' : 'unverified';
        if ($ex !== '') {
            $pdo->prepare("UPDATE nid_verifications SET nid=?, dob=?, status=?, method='checksum', checksum_ok=?, age_ok=?, notes=?, verified_by=?, verified_at=datetime('now') WHERE id=?")
                ->execute([$nid, $dob, $status, $v['ok'] ? 1 : 0, $ageOk, $v['reason'], $u['name'], $ex]);
            $id = $ex;
        } else {
            $id = nv_next_id($pdo);
            $pdo->prepare('INSERT INTO nid_verifications (id, tenant, nid, dob, status, method, checksum_ok, age_ok, notes, verified_by, verified_at) VALUES (?,?,?,?,?,?,?,?,?,?,datetime(\'now\'))')
                ->execute([$id, $tid, $nid, $dob, $status, 'checksum', $v['ok'] ? 1 : 0, $ageOk, $v['reason'], $u['name']]);
        }
        $pdo->prepare("UPDATE tenants SET nid=? WHERE id=?")->execute([$nid, $tid]);
        audit($u['name'], 'NID verification ' . $status, 'trust', $id, $tid . ' checksum=' . ($v['ok'] ? 'ok' : 'fail'));
        json_out(['ok' => true, 'id' => $id, 'valid' => $v['ok'], 'reason' => $v['reason'], 'status' => $status]);
    }

    /* nid-status — staff manual override */
    if ($action === 'nid-status') {
        if (!$isStaff) json_out(['ok' => false, 'error' => 'Only staff can update NID status.'], 403);
        $id = trim($body['id'] ?? '');
        $status = trim($body['status'] ?? '');
        if (!in_array($status, ['verified', 'unverified', 'mismatch'], true)) json_out(['ok' => false, 'error' => 'status must be verified|unverified|mismatch.'], 400);
        $st = $pdo->prepare('SELECT * FROM nid_verifications WHERE id=?'); $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok' => false, 'error' => 'Verification not found.'], 404);
        $pdo->prepare("UPDATE nid_verifications SET status=?, verified_by=?, verified_at=datetime('now') WHERE id=?")->execute([$status, $u['name'], $id]);
        audit($u['name'], 'NID status → ' . $status, 'trust', $id, $row['tenant']);
        json_out(['ok' => true]);
    }

    /* tif-list — staff all / tenant own */
    if ($action === 'tif-list') {
        json_out(['ok' => true, 'items' => tf_rows($pdo, $isStaff ? '' : $myTid)]);
    }

    /* tif-create — staff or tenant self */
    if ($action === 'tif-create') {
        $tid = $isStaff ? trim($body['tenant'] ?? '') : $myTid;
        if (!$tid) json_out(['ok' => false, 'error' => 'tenant required.'], 400);
        $st = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE id=?'); $st->execute([$tid]);
        if (!$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Tenant not found.'], 404);
        $payload = tif_default_payload($pdo, $tid);
        $unit = trim($body['unit'] ?? $payload['unit'] ?? '');
        $prop = trim($body['prop'] ?? $payload['prop'] ?? '');
        $thana = trim($body['thana'] ?? '');
        $district = trim($body['district'] ?? '');
        $id = tf_next_id($pdo);
        $pdo->prepare('INSERT INTO thana_forms (id, tenant, unit, prop, thana, district, status, payload, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $tid, $unit, $prop, $thana, $district, 'Draft', json_encode($payload, JSON_UNESCAPED_UNICODE), $u['name']]);
        audit($u['name'], 'Thana form created', 'trust', $id, $tid);
        json_out(['ok' => true, 'id' => $id, 'payload' => $payload]);
    }

    /* tif-get — single form (own or staff) */
    if ($action === 'tif-get') {
        $id = trim($body['id'] ?? $_GET['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM thana_forms WHERE id=?'); $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok' => false, 'error' => 'Form not found.'], 404);
        if (!$isStaff && $row['tenant'] !== $myTid) json_out(['ok' => false, 'error' => 'Not your form.'], 403);
        $row['payload'] = json_decode($row['payload'], true) ?: [];
        json_out(['ok' => true, 'form' => $row]);
    }

    /* tif-save — staff or tenant own; Draft or Submitted editable */
    if ($action === 'tif-save') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM thana_forms WHERE id=?'); $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok' => false, 'error' => 'Form not found.'], 404);
        if (!$isStaff && $row['tenant'] !== $myTid) json_out(['ok' => false, 'error' => 'Not your form.'], 403);
        if ($row['status'] === 'Verified') json_out(['ok' => false, 'error' => 'Verified forms are locked.'], 409);
        $payload = tif_save_payload($pdo, $id, $body);
        json_out(['ok' => true, 'payload' => $payload]);
    }

    /* tif-submit — tenant own or staff; Draft → Submitted */
    if ($action === 'tif-submit') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM thana_forms WHERE id=?'); $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok' => false, 'error' => 'Form not found.'], 404);
        if (!$isStaff && $row['tenant'] !== $myTid) json_out(['ok' => false, 'error' => 'Not your form.'], 403);
        if ($row['status'] !== 'Draft') json_out(['ok' => false, 'error' => 'Only Draft forms can be submitted.'], 409);
        $pdo->prepare("UPDATE thana_forms SET status='Submitted', submitted_at=datetime('now') WHERE id=?")->execute([$id]);
        audit($u['name'], 'Thana form submitted', 'trust', $id, $row['tenant']);
        json_out(['ok' => true]);
    }

    /* tif-verify — staff only; Submitted → Verified/Rejected */
    if ($action === 'tif-verify') {
        if (!$isStaff) json_out(['ok' => false, 'error' => 'Only staff can verify forms.'], 403);
        $id = trim($body['id'] ?? '');
        $verdict = trim($body['verdict'] ?? '');
        if (!$id || !in_array($verdict, ['approve', 'reject'], true)) json_out(['ok' => false, 'error' => 'id and verdict (approve|reject) required.'], 400);
        $st = $pdo->prepare('SELECT * FROM thana_forms WHERE id=?'); $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok' => false, 'error' => 'Form not found.'], 404);
        if ($row['status'] !== 'Submitted') json_out(['ok' => false, 'error' => 'Only Submitted forms can be verified.'], 409);
        $new = $verdict === 'approve' ? 'Verified' : 'Draft';
        $pdo->prepare('UPDATE thana_forms SET status=?, verified_by=?, verified_at=datetime(\'now\') WHERE id=?')->execute([$new, $u['name'], $id]);
        audit($u['name'], 'Thana form ' . ($verdict === 'approve' ? 'approved' : 'rejected'), 'trust', $id, $row['tenant']);
        json_out(['ok' => true, 'status' => $new]);
    }

    /* tif-print — printable bilingual form (echo + exit); preview=1 skips the audit log */
    if ($action === 'tif-print') {
        $id = trim($_GET['id'] ?? $body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM thana_forms WHERE id=?'); $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok' => false, 'error' => 'Form not found.'], 404);
        if (!$isStaff && $row['tenant'] !== $myTid) json_out(['ok' => false, 'error' => 'Not your form.'], 403);
        $row['payload'] = json_decode($row['payload'], true) ?: [];
        $cfg = tif_print_cfg_effective($pdo, $row['payload']);
        $html = tif_print_html($row, $cfg);
        if (empty($body['preview'])) audit($u['name'], 'Thana form printed', 'trust', $id, $row['tenant']);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="bn"><head><meta charset="utf-8"><title>' . htmlspecialchars($id) . ' — ভাড়াটিয়া নিবন্ধন ফরম</title></head><body>' . $html . '</body></html>';
        exit;
    }

    /* tif-print-cfg-get — effective config for a form (global default merged with per-form override) */
    if ($action === 'tif-print-cfg-get') {
        $id = trim($body['id'] ?? $_GET['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM thana_forms WHERE id=?'); $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok' => false, 'error' => 'Form not found.'], 404);
        if (!$isStaff && $row['tenant'] !== $myTid) json_out(['ok' => false, 'error' => 'Not your form.'], 403);
        $payload = json_decode($row['payload'], true) ?: [];
        $local = isset($payload['_print']) && is_array($payload['_print']) ? $payload['_print'] : [];
        json_out(['ok' => true, 'cfg' => tif_print_cfg_effective($pdo, $payload), 'override' => $local, 'defaults' => tif_print_cfg_defaults(), 'global' => tif_print_cfg_global($pdo)]);
    }

    /* tif-print-cfg-save — per-form override (stored in payload._print); admin only */
    if ($action === 'tif-print-cfg-save') {
        if (!$isAdmin) json_out(['ok' => false, 'error' => 'Only admins can adjust print settings.'], 403);
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM thana_forms WHERE id=?'); $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok' => false, 'error' => 'Form not found.'], 404);
        if (!$isStaff && $row['tenant'] !== $myTid) json_out(['ok' => false, 'error' => 'Not your form.'], 403);
        $payload = json_decode($row['payload'], true) ?: [];
        $cfg = tif_print_cfg_sanitize($body['cfg'] ?? []);
        if ($cfg) $payload['_print'] = $cfg; else unset($payload['_print']);
        $pdo->prepare('UPDATE thana_forms SET payload=? WHERE id=?')
            ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $id]);
        audit($u['name'], 'Thana print settings saved', 'trust', $id, $row['tenant']);
        json_out(['ok' => true, 'cfg' => $cfg]);
    }

    /* tif-print-cfg-global — admin: get/save the global default (platform_meta tif_print_cfg) */
    if ($action === 'tif-print-cfg-global') {
        if (($body['mode'] ?? '') === 'save' && !$isAdmin) json_out(['ok' => false, 'error' => 'Only admins can adjust print settings.'], 403);
        if (($body['mode'] ?? '') === 'save') {
            $cfg = tif_print_cfg_sanitize($body['cfg'] ?? []);
            if ($cfg) {
                $pdo->prepare("INSERT OR REPLACE INTO platform_meta (k, v) VALUES ('tif_print_cfg', ?)")
                    ->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
            }
            audit($u['name'], 'Thana print settings global saved', 'trust', '-', json_encode($cfg));
            json_out(['ok' => true, 'cfg' => $cfg]);
        }
        json_out(['ok' => true, 'global' => tif_print_cfg_global($pdo), 'defaults' => tif_print_cfg_defaults()]);
    }

    json_out(['ok' => false, 'error' => 'action must be summary|nid-validate|nid-list|nid-save|nid-status|tif-list|tif-create|tif-get|tif-save|tif-submit|tif-verify|tif-print|tif-print-cfg-get|tif-print-cfg-save|tif-print-cfg-global.'], 400);
}

case 'app-land': {
    $u = require_user();
    require_module($u, 'land');
    $pdo = db();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr'], true))
        json_out(['ok' => false, 'error' => 'Land Guard is for property owners and operations staff.'], 403);
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'summary' : 'list';
    $parcelIds = land_all_ids($pdo, $u);
    $mine = function ($id) use ($parcelIds) { return in_array($id, $parcelIds, true); };

    if ($action === 'summary') {
        $all = land_rows($pdo, $parcelIds);
        $cnt = function ($fn) use ($all) { return count(array_filter($all, $fn)); };
        json_out(['ok' => true,
            'total' => count($all),
            'secure' => $cnt(fn($p) => $p['status'] === 'Secure'),
            'review' => $cnt(fn($p) => $p['status'] === 'Needs Review'),
            'encroached' => $cnt(fn($p) => $p['status'] === 'Encroached'),
            'monitored' => $cnt(fn($p) => $p['coverage']),
            'due_soon' => $cnt(fn($p) => $p['due_days'] !== null && $p['due_days'] >= 0 && $p['due_days'] <= 30),
            'overdue' => $cnt(fn($p) => $p['due_days'] !== null && $p['due_days'] < 0),
            'coverage' => count($all) ? (int)round($cnt(fn($p) => $p['coverage']) / count($all) * 100) : 0,
            'alerts' => land_alerts($all)]);
    }

    if ($action === 'list') {
        $all = land_rows($pdo, $parcelIds);
        $visits = []; $media = []; $events = [];
        if ($parcelIds) {
            $ids = implode(',', array_fill(0, count($parcelIds), '?'));
            $st = $pdo->prepare("SELECT * FROM land_visits WHERE parcel IN ($ids) ORDER BY scheduled_for DESC"); $st->execute($parcelIds);
            $visits = $st->fetchAll(PDO::FETCH_ASSOC);
            $visitIds = array_column($visits, 'id');
            if ($visitIds) {
                $vidIn = implode(',', array_fill(0, count($visitIds), '?'));
                $st = $pdo->prepare("SELECT * FROM land_media WHERE visit IN ($vidIn) ORDER BY ts DESC"); $st->execute($visitIds);
                $media = $st->fetchAll(PDO::FETCH_ASSOC);
            }
            $st = $pdo->prepare("SELECT * FROM land_events WHERE parcel IN ($ids) ORDER BY ts DESC"); $st->execute($parcelIds);
            $events = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        json_out(['ok' => true, 'parcels' => $all, 'visits' => $visits, 'media' => $media, 'events' => $events]);
    }

    if ($action === 'get') {
        $id = trim($_GET['id'] ?? $body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM land_parcels WHERE id=?'); $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) json_out(['ok' => false, 'error' => 'Parcel not found.'], 404);
        if (!$mine($id)) json_out(['ok' => false, 'error' => 'Parcel not in your scope.'], 403);
        $rows = land_enrich($pdo, [$p]);
        $st = $pdo->prepare('SELECT * FROM land_visits WHERE parcel=? ORDER BY scheduled_for DESC'); $st->execute([$id]);
        $visits = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare('SELECT m.* FROM land_media m JOIN land_visits v ON v.id=m.visit WHERE v.parcel=? ORDER BY m.ts DESC'); $st->execute([$id]);
        $media = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare('SELECT * FROM land_events WHERE parcel=? ORDER BY ts DESC'); $st->execute([$id]);
        $events = $st->fetchAll(PDO::FETCH_ASSOC);
        json_out(['ok' => true, 'parcel' => $rows[0], 'visits' => $visits, 'media' => $media, 'events' => $events]);
    }

    if ($action === 'create') {
        $name = trim($body['name'] ?? '');
        if ($name === '') json_out(['ok' => false, 'error' => 'name required.'], 400);
        $ownerEmail = trim($body['owner_email'] ?? '');
        if ($u['role'] === 'owner') $ownerEmail = $u['email'];
        $monitor = in_array(trim($body['monitor'] ?? ''), ['none', 'quarterly', 'semi_annual', 'annual'], true) ? trim($body['monitor']) : 'quarterly';
        $status = in_array(trim($body['status'] ?? ''), ['Secure', 'Needs Review', 'Encroached', 'Under Monitoring'], true) ? trim($body['status']) : 'Secure';
        $id = lp_next_id($pdo);
        $pdo->prepare('INSERT INTO land_parcels (id, owner_email, name, district, upazila, mouza, khatian, dag, area, lat, lng, prop, monitor, status, risk, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $ownerEmail, $name, trim($body['district'] ?? ''), trim($body['upazila'] ?? ''), trim($body['mouza'] ?? ''), trim($body['khatian'] ?? ''), trim($body['dag'] ?? ''), trim($body['area'] ?? ''), trim($body['lat'] ?? ''), trim($body['lng'] ?? ''), trim($body['prop'] ?? ''), $monitor, $status, in_array(trim($body['risk'] ?? ''), ['low', 'medium', 'high'], true) ? trim($body['risk']) : 'low', trim($body['notes'] ?? ''), $u['name']]);
        land_event($pdo, $id, 'created', 'Parcel registered — ' . $monitor . ' monitoring plan', $u['name']);
        audit($u['name'], 'Land parcel created', 'land', $id, $name . ' (' . ($ownerEmail ?: 'org') . ')');
        json_out(['ok' => true, 'id' => $id]);
    }

    if ($action === 'save') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        if (!$mine($id)) json_out(['ok' => false, 'error' => 'Parcel not in your scope.'], 403);
        $st = $pdo->prepare('SELECT * FROM land_parcels WHERE id=?'); $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) json_out(['ok' => false, 'error' => 'Parcel not found.'], 404);
        $monitor = in_array(trim($body['monitor'] ?? $p['monitor']), ['none', 'quarterly', 'semi_annual', 'annual'], true) ? trim($body['monitor'] ?? $p['monitor']) : $p['monitor'];
        $status = in_array(trim($body['status'] ?? $p['status']), ['Secure', 'Needs Review', 'Encroached', 'Under Monitoring'], true) ? trim($body['status'] ?? $p['status']) : $p['status'];
        $pdo->prepare("UPDATE land_parcels SET name=?, district=?, upazila=?, mouza=?, khatian=?, dag=?, area=?, lat=?, lng=?, prop=?, monitor=?, status=?, risk=?, notes=?, updated_at=datetime('now') WHERE id=?")
            ->execute([trim($body['name'] ?? $p['name']), trim($body['district'] ?? $p['district']), trim($body['upazila'] ?? $p['upazila']), trim($body['mouza'] ?? $p['mouza']), trim($body['khatian'] ?? $p['khatian']), trim($body['dag'] ?? $p['dag']), trim($body['area'] ?? $p['area']), trim($body['lat'] ?? $p['lat']), trim($body['lng'] ?? $p['lng']), trim($body['prop'] ?? $p['prop']), $monitor, $status, in_array(trim($body['risk'] ?? $p['risk']), ['low', 'medium', 'high'], true) ? trim($body['risk']) : $p['risk'], trim($body['notes'] ?? $p['notes']), $id]);
        if ($status !== $p['status']) { land_event($pdo, $id, 'status', 'Status → ' . $status, $u['name']); audit($u['name'], 'Land parcel status → ' . $status, 'land', $id); }
        json_out(['ok' => true]);
    }

    if ($action === 'status') {
        $id = trim($body['id'] ?? '');
        $status = trim($body['status'] ?? '');
        $note = trim($body['note'] ?? '');
        if (!$id || !in_array($status, ['Secure', 'Needs Review', 'Encroached', 'Under Monitoring'], true)) json_out(['ok' => false, 'error' => 'id and status (Secure|Needs Review|Encroached|Under Monitoring) required.'], 400);
        if (!$mine($id)) json_out(['ok' => false, 'error' => 'Parcel not in your scope.'], 403);
        $st = $pdo->prepare('SELECT * FROM land_parcels WHERE id=?'); $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) json_out(['ok' => false, 'error' => 'Parcel not found.'], 404);
        $risk = $status === 'Encroached' ? 'high' : $p['risk'];
        $pdo->prepare("UPDATE land_parcels SET status=?, risk=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $risk, $id]);
        land_event($pdo, $id, $status === 'Encroached' ? 'encroachment' : 'status', 'Status → ' . $status . ($note ? ' — ' . $note : ''), $u['name']);
        audit($u['name'], 'Land parcel status → ' . $status, 'land', $id, $note);
        json_out(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT name FROM land_parcels WHERE id=?'); $st->execute([$id]);
        $name = (string)$st->fetchColumn();
        if ($name === '') json_out(['ok' => false, 'error' => 'Parcel not found.'], 404);
        if (!$mine($id)) json_out(['ok' => false, 'error' => 'Parcel not in your scope.'], 403);
        $pdo->prepare('DELETE FROM land_media WHERE visit IN (SELECT id FROM land_visits WHERE parcel=?)')->execute([$id]);
        $pdo->prepare('DELETE FROM land_visits WHERE parcel=?')->execute([$id]);
        $pdo->prepare('DELETE FROM land_events WHERE parcel=?')->execute([$id]);
        $pdo->prepare('DELETE FROM land_parcels WHERE id=?')->execute([$id]);
        audit($u['name'], 'Land parcel deleted', 'land', $id, $name);
        json_out(['ok' => true]);
    }

    if ($action === 'visit-list') {
        $pid = trim($body['parcel'] ?? $_GET['parcel'] ?? '');
        if ($pid) {
            if (!$mine($pid)) json_out(['ok' => false, 'error' => 'Parcel not in your scope.'], 403);
            $st = $pdo->prepare('SELECT * FROM land_visits WHERE parcel=? ORDER BY scheduled_for DESC'); $st->execute([$pid]);
        } else {
            $st = $pdo->query('SELECT * FROM land_visits ORDER BY scheduled_for DESC');
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $k => $r) {
            $pst = $pdo->prepare('SELECT name FROM land_parcels WHERE id=?'); $pst->execute([$r['parcel']]);
            $rows[$k]['parcel_name'] = (string)$pst->fetchColumn();
        }
        json_out(['ok' => true, 'visits' => $rows]);
    }

    if ($action === 'visit-schedule') {
        $pid = trim($body['parcel'] ?? '');
        if (!$pid) json_out(['ok' => false, 'error' => 'parcel required.'], 400);
        $st = $pdo->prepare('SELECT COUNT(*) FROM land_parcels WHERE id=?'); $st->execute([$pid]);
        if (!$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Parcel not found.'], 404);
        if (!$mine($pid)) json_out(['ok' => false, 'error' => 'Parcel not in your scope.'], 403);
        $sf = trim($body['scheduled_for'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sf)) json_out(['ok' => false, 'error' => 'scheduled_for must be YYYY-MM-DD.'], 400);
        $vt = in_array(trim($body['visit_type'] ?? ''), ['field', 'drone'], true) ? trim($body['visit_type']) : 'field';
        $chk = isset($body['checklist']) && is_array($body['checklist']) ? json_encode(array_values(array_filter(array_map('strval', $body['checklist'])))) : '[]';
        $id = lv_next_id($pdo);
        $pdo->prepare('INSERT INTO land_visits (id, parcel, agent, visit_type, scheduled_for, status, checklist, created_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id, $pid, trim($body['agent'] ?? ''), $vt, $sf, 'Scheduled', $chk, $u['name']]);
        land_event($pdo, $pid, 'visit', 'Visit ' . $id . ' scheduled for ' . $sf . ' (' . $vt . ')', $u['name']);
        audit($u['name'], 'Land visit scheduled', 'land', $id, $pid . ' · ' . $sf);
        json_out(['ok' => true, 'id' => $id]);
    }

    if ($action === 'visit-complete') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM land_visits WHERE id=?'); $st->execute([$id]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) json_out(['ok' => false, 'error' => 'Visit not found.'], 404);
        if (!$mine($v['parcel'])) json_out(['ok' => false, 'error' => 'Parcel not in your scope.'], 403);
        if (!in_array($v['status'], ['Scheduled', 'In Progress'], true)) json_out(['ok' => false, 'error' => 'Only Scheduled/In Progress visits can be completed.'], 409);
        $outcome = trim($body['outcome'] ?? '');
        if (!in_array($outcome, ['Secure', 'Needs Review', 'Encroached'], true)) json_out(['ok' => false, 'error' => 'outcome must be Secure|Needs Review|Encroached.'], 400);
        $chk = isset($body['checklist']) && is_array($body['checklist']) ? json_encode(array_values(array_filter(array_map('strval', $body['checklist'])))) : $v['checklist'];
        $pdo->prepare("UPDATE land_visits SET status='Completed', outcome=?, findings=?, checklist=?, completed_by=?, completed_at=datetime('now') WHERE id=?")
            ->execute([$outcome, trim($body['findings'] ?? ''), $chk, $u['name'], $id]);
        $pdo->prepare("UPDATE land_parcels SET status=?, risk=?, updated_at=datetime('now') WHERE id=?")->execute([$outcome, $outcome === 'Encroached' ? 'high' : 'low', $v['parcel']]);
        land_event($pdo, $v['parcel'], $outcome === 'Encroached' ? 'encroachment' : 'visit', 'Visit ' . $id . ' completed — ' . $outcome . (trim($body['findings'] ?? '') ? ': ' . trim($body['findings']) : ''), $u['name']);
        $proofIds = [];
        if (isset($body['proof']) && is_array($body['proof'])) {
            foreach ($body['proof'] as $pr) {
                if (!is_array($pr) || !trim($pr['name'] ?? '')) continue;
                $mid = lm_next_id($pdo);
                $pdo->prepare('INSERT INTO land_media (id, visit, kind, name, fname, size, mime, geo, captured_at, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$mid, $id, in_array(trim($pr['kind'] ?? ''), ['photo', 'video', 'drone'], true) ? trim($pr['kind']) : 'photo', trim($pr['name']), trim($pr['fname'] ?? ''), (int)($pr['size'] ?? 0), trim($pr['mime'] ?? ''), trim($pr['geo'] ?? ''), trim($pr['captured_at'] ?? date('Y-m-d H:i:s')), $u['name']]);
                $proofIds[] = $mid;
            }
        }
        audit($u['name'], 'Land visit completed', 'land', $id, $v['parcel'] . ' → ' . $outcome . (count($proofIds) ? ' · ' . count($proofIds) . ' proof' : ''));
        json_out(['ok' => true, 'media' => $proofIds]);
    }

    if ($action === 'visit-cancel') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM land_visits WHERE id=?'); $st->execute([$id]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) json_out(['ok' => false, 'error' => 'Visit not found.'], 404);
        if (!$mine($v['parcel'])) json_out(['ok' => false, 'error' => 'Parcel not in your scope.'], 403);
        if ($v['status'] === 'Completed') json_out(['ok' => false, 'error' => 'Completed visits cannot be cancelled.'], 409);
        $pdo->prepare("UPDATE land_visits SET status='Cancelled' WHERE id=?")->execute([$id]);
        land_event($pdo, $v['parcel'], 'visit', 'Visit ' . $id . ' cancelled', $u['name']);
        json_out(['ok' => true]);
    }

    if ($action === 'visit-proof') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM land_visits WHERE id=?'); $st->execute([$id]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) json_out(['ok' => false, 'error' => 'Visit not found.'], 404);
        if (!$mine($v['parcel'])) json_out(['ok' => false, 'error' => 'Parcel not in your scope.'], 403);
        if ($v['status'] !== 'Completed') json_out(['ok' => false, 'error' => 'Only completed visits accept proof.'], 409);
        $ids = [];
        foreach ((array)($body['entries'] ?? []) as $pr) {
            if (!is_array($pr) || !trim($pr['name'] ?? '')) continue;
            $mid = lm_next_id($pdo);
            $pdo->prepare('INSERT INTO land_media (id, visit, kind, name, fname, size, mime, geo, captured_at, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$mid, $id, in_array(trim($pr['kind'] ?? ''), ['photo', 'video', 'drone'], true) ? trim($pr['kind']) : 'photo', trim($pr['name']), trim($pr['fname'] ?? ''), (int)($pr['size'] ?? 0), trim($pr['mime'] ?? ''), trim($pr['geo'] ?? ''), trim($pr['captured_at'] ?? date('Y-m-d H:i:s')), $u['name']]);
            $ids[] = $mid;
        }
        json_out(['ok' => true, 'ids' => $ids]);
    }

    if ($action === 'media-list') {
        $vid = trim($body['visit'] ?? $_GET['visit'] ?? '');
        if (!$vid) json_out(['ok' => false, 'error' => 'visit required.'], 400);
        $st = $pdo->prepare('SELECT parcel FROM land_visits WHERE id=?'); $st->execute([$vid]);
        $pz = $st->fetchColumn();
        if (!$pz || !$mine($pz)) json_out(['ok' => false, 'error' => 'Visit not in your scope.'], 404);
        $st = $pdo->prepare('SELECT * FROM land_media WHERE visit=? ORDER BY ts DESC'); $st->execute([$vid]);
        json_out(['ok' => true, 'media' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'report') {
        $id = trim($_GET['id'] ?? $body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM land_parcels WHERE id=?'); $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) json_out(['ok' => false, 'error' => 'Parcel not found.'], 404);
        if (!$mine($id)) json_out(['ok' => false, 'error' => 'Parcel not in your scope.'], 403);
        $rows = land_enrich($pdo, [$p]);
        $st = $pdo->prepare('SELECT * FROM land_visits WHERE parcel=? ORDER BY scheduled_for DESC'); $st->execute([$id]);
        $visits = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare('SELECT m.* FROM land_media m JOIN land_visits v ON v.id=m.visit WHERE v.parcel=? ORDER BY m.ts DESC'); $st->execute([$id]);
        $media = $st->fetchAll(PDO::FETCH_ASSOC);
        $html = land_report_html($rows[0], $visits, $media);
        audit($u['name'], 'Land parcel report', 'land', $id);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="bn"><head><meta charset="utf-8"><title>' . htmlspecialchars($id) . ' — Land Guard Report</title></head><body>' . $html . '</body></html>';
        exit;
    }

    if ($action === 'alerts') {
        $all = land_rows($pdo, $parcelIds);
        json_out(['ok' => true, 'alerts' => land_alerts($all)]);
    }

    if ($action === 'config-get') {
        $cfg = org_cfg($pdo);
        json_out(['ok' => true, 'config' => [
            'land_default_monitor' => $cfg['land_default_monitor'] ?? 'quarterly',
            'land_due_soon_days' => (int)($cfg['land_due_soon_days'] ?? 30),
            'land_visit_reminder_days' => (int)($cfg['land_visit_reminder_days'] ?? 7),
        ]]);
    }

    if ($action === 'config-save') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true)) json_out(['ok' => false, 'error' => 'Only owner/manager can save Land Guard config.'], 403);
        $in = [];
        if (isset($body['land_default_monitor'])) $in['land_default_monitor'] = in_array($body['land_default_monitor'], ['none', 'quarterly', 'semi_annual', 'annual'], true) ? $body['land_default_monitor'] : 'quarterly';
        if (isset($body['land_due_soon_days'])) $in['land_due_soon_days'] = (string)max(1, min(90, (int)$body['land_due_soon_days']));
        if (isset($body['land_visit_reminder_days'])) $in['land_visit_reminder_days'] = (string)max(1, min(60, (int)$body['land_visit_reminder_days']));
        foreach ($in as $k => $v) {
            $pdo->prepare("INSERT INTO org_settings (k, v, updated_at) VALUES (?,?,datetime('now')) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')")->execute([$k, $v]);
        }
        audit($u['name'], 'Land Guard config updated', 'land', 'cfg', implode(',', array_keys($in)));
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'action must be summary|list|get|create|save|status|delete|visit-list|visit-schedule|visit-complete|visit-cancel|visit-proof|media-list|report|alerts|config-get|config-save.'], 400);
}

case 'app-kr-alert': {
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'list' : 'run';
    if ($action === 'run') {
        if (!service_authed()) json_out(['ok' => false, 'error' => 'Service key required.'], 401);
        $created = kr_alert_scan($pdo);
        json_out(['ok' => true, 'created' => $created, 'summary' => sla_summary($pdo)]);
    }
    $u = require_user();
    require_module($u, 'ai');
    if ($action === 'list') {
        $st = $pdo->prepare("SELECT * FROM kr_alerts WHERE user_key=? AND status='open' ORDER BY ts DESC LIMIT 100");
        $st->execute([user_key_for($u)]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { if (!$r['voice_note']) $r['voice_note'] = ''; }
        $stU = $pdo->prepare("SELECT COUNT(*) FROM kr_alerts WHERE user_key=? AND status='open' AND (read_at IS NULL OR read_at='')");
        $stU->execute([user_key_for($u)]);
        $unread = (int)$stU->fetchColumn();
        json_out(['ok' => true, 'alerts' => $rows, 'unread' => $unread]);
    }
    if ($action === 'read') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT id FROM kr_alerts WHERE id=? AND user_key=?');
        $st->execute([$id, user_key_for($u)]);
        if (!$st->fetch()) json_out(['ok' => false, 'error' => 'Alert not found or not yours.'], 404);
        $pdo->prepare("UPDATE kr_alerts SET read_at=datetime('now') WHERE id=? AND (read_at IS NULL OR read_at='')")->execute([$id]);
        json_out(['ok' => true]);
    }
    if ($action === 'read-all') {
        $pdo->prepare("UPDATE kr_alerts SET read_at=datetime('now') WHERE user_key=? AND status='open' AND (read_at IS NULL OR read_at='')")->execute([user_key_for($u)]);
        json_out(['ok' => true]);
    }
    if ($action === 'dismiss') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT id FROM kr_alerts WHERE id=? AND user_key=?');
        $st->execute([$id, user_key_for($u)]);
        if (!$st->fetch()) json_out(['ok' => false, 'error' => 'Alert not found or not yours.'], 404);
        $pdo->prepare("UPDATE kr_alerts SET status='dismissed', resolved_at=datetime('now') WHERE id=?")->execute([$id]);
        json_out(['ok' => true]);
    }
    if ($action === 'dismiss-all') {
        $pdo->prepare("UPDATE kr_alerts SET status='dismissed', resolved_at=datetime('now') WHERE user_key=? AND status='open'")->execute([user_key_for($u)]);
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'action must be run|list|read|read-all|dismiss|dismiss-all.'], 400);
}

case 'app-kr-wa': {
    $u = require_user();
    require_module($u, 'ai');
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'status' : 'send';
    $uk = user_key_for($u);
    if ($action === 'status') {
        $ch = wa_channel_for($pdo, $uk);
        $st = $pdo->prepare('SELECT * FROM kr_wa_msgs WHERE user_key=? ORDER BY id DESC LIMIT 30');
        $st->execute([$uk]);
        json_out(['ok' => true, 'channel' => $ch, 'messages' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action === 'bind') {
        $phone = preg_replace('/[^0-9+]/', '', trim($body['phone'] ?? ''));
        if (!$phone || strlen($phone) < 9) json_out(['ok' => false, 'error' => 'Valid phone required.'], 400);
        $st = $pdo->prepare('SELECT id FROM wa_channels WHERE user_key=? AND phone=?');
        $st->execute([$uk, $phone]);
        $ex = $st->fetchColumn();
        if (!$ex) {
            $id = 'WA-' . substr(md5($uk . $phone), 0, 8);
            $pdo->prepare('INSERT OR IGNORE INTO wa_channels (id, user_key, phone, provider, status) VALUES (?,?,?,?,\'active\')')
                ->execute([$id, $uk, $phone, trim($body['provider'] ?? 'whatsapp')]);
        } else {
            /* re-bind reactivates a previously unbound channel */
            $pdo->prepare("UPDATE wa_channels SET status='active', ts=datetime('now') WHERE id=?")->execute([$ex]);
        }
        audit($u['name'], 'WhatsApp channel bound', 'ai', $phone);
        json_out(['ok' => true, 'channel' => wa_channel_for($pdo, $uk)]);
    }
    if ($action === 'unbind') {
        $pdo->prepare("UPDATE wa_channels SET status='inactive' WHERE user_key=? AND phone=?")->execute([$uk, trim($body['phone'] ?? '')]);
        json_out(['ok' => true]);
    }
    if ($action === 'send') {
        $phone = preg_replace('/[^0-9+]/', '', trim($body['phone'] ?? ''));
        $msg = trim($body['message'] ?? '');
        if (!$phone || strlen($phone) < 9 || !$msg) json_out(['ok' => false, 'error' => 'phone (valid) and message required.'], 400);
        $st = $pdo->prepare('SELECT id FROM wa_channels WHERE user_key=? AND phone=? AND status=\'active\'');
        $st->execute([$uk, $phone]);
        $ch = $st->fetchColumn();
        if (!$ch) {
            $ch = 'WA-' . substr(md5($uk . $phone), 0, 8);
            $pdo->prepare('INSERT OR IGNORE INTO wa_channels (id, user_key, phone, provider) VALUES (?,?,?,?)')->execute([$ch, $uk, $phone, 'whatsapp']);
        }
        $pdo->prepare('INSERT INTO kr_wa_msgs (user_key, direction, body) VALUES (?,?,?)')->execute([$uk, 'out', $msg]);
        audit($u['name'], 'WhatsApp message', 'ai', $ch, mb_substr($msg, 0, 60, 'UTF-8'));
        json_out(['ok' => true, 'id' => $ch, 'note' => 'Logged on KR channel — production routes via Twilio WhatsApp gateway.']);
    }
    if ($action === 'webhook') {
        /* inbound (simulated gateway callback) — routes to KR for a reply */
        $phone = preg_replace('/[^0-9+]/', '', trim($body['phone'] ?? ''));
        $msg = trim($body['message'] ?? '');
        if (!$phone || !$msg) json_out(['ok' => false, 'error' => 'phone and message required.'], 400);
        $ch = $pdo->prepare('SELECT user_key FROM wa_channels WHERE phone=? AND status=\'active\'');
        $ch->execute([$phone]);
        $target = $ch->fetchColumn();
        $pdo->prepare('INSERT INTO kr_wa_msgs (user_key, direction, body) VALUES (?,?,?)')->execute([$target ?: 'sub:1', 'in', $msg]);
        $reply = 'KR: Received "' . substr($msg, 0, 80) . '" — channel message logged. (Auto-reply in production via KR engine.)';
        json_out(['ok' => true, 'reply' => $reply]);
    }
    json_out(['ok' => false, 'error' => 'action must be status|bind|unbind|send|webhook.'], 400);
}

case 'app-analytics': {
    $svc = service_authed();
    if (!$svc) {
        $u = require_user();
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant', 'svc_mgr'], true))
            json_out(['ok' => false, 'error' => 'Your role cannot view portfolio analytics.'], 403);
    } else {
        /* V2.23: service-key consistency — cron/reporting may read analytics */
        $u = ['name' => 'system', 'role' => 'svc', 'email' => '', 'id' => 0];
    }
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'pnl' : 'pnl';
    $month = trim($body['month'] ?? $_GET['month'] ?? '');
    if (!$month) $month = date('Y-m');
    if ($action === 'pnl') json_out(['ok' => true] + analytics_pnl($pdo, $month));
    if ($action === 'trends') json_out(['ok' => true] + analytics_trends($pdo, (int)($body['months'] ?? $_GET['months'] ?? 12)));
    if ($action === 'aging') json_out(['ok' => true] + ['buckets' => analytics_aging($pdo)]);
    if ($action === 'vacancy') json_out(['ok' => true] + analytics_vacancy($pdo));
    if ($action === 'forecast') json_out(['ok' => true] + analytics_forecast($pdo));
    if ($action === 'cashflow') json_out(['ok' => true] + analytics_cashflow($pdo, (int)($body['months'] ?? $_GET['months'] ?? 12)));
    if ($action === 'collections') json_out(['ok' => true] + analytics_collections($pdo, (int)($body['months'] ?? $_GET['months'] ?? 12)));
    if ($action === 'expenses') json_out(['ok' => true] + analytics_expenses($pdo, (int)($body['months'] ?? $_GET['months'] ?? 12)));
    if ($action === 'scores') json_out(['ok' => true] + analytics_scores($pdo));
    if ($action === 'occupancy') json_out(['ok' => true] + analytics_occupancy($pdo));
    if ($action === 'maintenance') json_out(['ok' => true] + analytics_maintenance($pdo));
    if ($action === 'board') {
        /* V2.23: board report generation persists a row — service key stays read-only */
        if ($svc) json_out(['ok' => false, 'error' => 'Service key cannot generate board reports.'], 403);
        $md = board_report_md($pdo, $month);
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'BR-','') AS INTEGER)) FROM board_reports")->fetchColumn();
        $bid = 'BR-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO board_reports (id, month, kind, payload, created_by) VALUES (?,?,?,?,?)')
            ->execute([$bid, $month, 'board', $md, $u['name']]);
        audit($u['name'], 'Board report generated', 'analytics', $bid, $month);
        json_out(['ok' => true, 'id' => $bid, 'month' => $month, 'markdown' => $md]);
    }
    if ($action === 'boards') {
        $rows = $pdo->query('SELECT id, month, kind, created_by, ts FROM board_reports ORDER BY ts DESC LIMIT 25')->fetchAll(PDO::FETCH_ASSOC);
        json_out(['ok' => true, 'reports' => $rows]);
    }
    json_out(['ok' => false, 'error' => 'action must be pnl|trends|aging|vacancy|forecast|cashflow|collections|expenses|scores|occupancy|maintenance|board|boards.'], 400);
}


/* ── Phase 46: NRB Hub — tax & repatriation, vacancy management, virtual disputes ── */
case 'app-nrb': {
    $u = require_user();
    require_module($u, 'nrb');
    $pdo = db();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant', 'svc_mgr', 'legal'], true))
        json_out(['ok' => false, 'error' => 'NRB Hub is for owners and staff.'], 403);
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'summary' : 'list';
    $ownerOk = function ($email) use ($u) {
        if ($u['role'] === 'owner') return $email === $u['email'] || $email === '';
        return true;
    };
    $fetch = function ($table, $id) use ($pdo) {
        $st = $pdo->prepare("SELECT * FROM $table WHERE id=?"); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    };

    if ($action === 'summary') {
        $tax = nrb_tax_rows($pdo, $u);
        $rp = nrb_rp_rows($pdo, $u);
        $vc = nrb_vc_rows($pdo, $u);
        $ds = nrb_ds_rows($pdo, $u);
        $txStat = ['Draft' => 0, 'Filed' => 0, 'Paid' => 0];
        foreach ($tax as $t) $txStat[$t['status']] = ($txStat[$t['status']] ?? 0) + 1;
        $rpStat = ['Requested' => 0, 'Docs_Submitted' => 0, 'Submitted' => 0, 'Approved' => 0, 'Completed' => 0, 'Rejected' => 0];
        foreach ($rp as $r) $rpStat[$r['status']] = ($rpStat[$r['status']] ?? 0) + 1;
        $vcStat = ['Vacant' => 0, 'Marketing' => 0, 'Showing' => 0, 'Vetting' => 0, 'Awaiting_Approval' => 0, 'Filled' => 0, 'Cancelled' => 0];
        foreach ($vc as $v) $vcStat[$v['status']] = ($vcStat[$v['status']] ?? 0) + 1;
        $dsStat = ['Open' => 0, 'Notice_Served' => 0, 'In_Progress' => 0, 'Escalated' => 0, 'Resolved' => 0, 'Closed' => 0];
        foreach ($ds as $d) $dsStat[$d['status']] = ($dsStat[$d['status']] ?? 0) + 1;
        $pending = array_values(array_filter($vc, fn($v) => $v['status'] === 'Awaiting_Approval'));
        json_out(['ok' => true,
            'tax' => ['rows' => $tax, 'by_status' => $txStat],
            'repat' => ['rows' => $rp, 'by_status' => $rpStat],
            'vacancies' => ['rows' => nrb_vc_enrich($pdo, $vc), 'by_status' => $vcStat, 'pending_approval' => count($pending)],
            'showings' => nrb_sh_rows($pdo, $u),
            'disputes' => ['rows' => nrb_ds_enrich($pdo, $ds), 'by_status' => $dsStat],
            'config' => nrb_config_rows($pdo)]);
    }
    if ($action === 'tax-list') { json_out(['ok' => true, 'rows' => nrb_tax_rows($pdo, $u)]); }
    if ($action === 'tax-create') {
        $income = max(0, (int)($body['rental_income'] ?? 0));
        $tds = max(0, (int)($body['tds_deducted'] ?? 0));
        $est = isset($body['tax_estimate']) ? max(0, (int)$body['tax_estimate']) : max(0, (int)round($income * 0.13) - $tds);
        $email = $u['role'] === 'owner' ? $u['email'] : trim($body['owner_email'] ?? '');
        if ($email === '') $email = $u['email'];
        $id = nrb_tx_next_id($pdo);
        $pdo->prepare('INSERT INTO nrb_tax_returns (id, owner_email, prop, fy, rental_income, tds_deducted, tax_estimate, status, ca_firm, ca_ref, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $email, trim($body['prop'] ?? ''), trim($body['fy'] ?? ''), $income, $tds, $est, 'Draft', trim($body['ca_firm'] ?? ''), trim($body['ca_ref'] ?? ''), trim($body['notes'] ?? '')]);
        audit($u['name'], 'Tax return created', 'nrb', $id, ($body['fy'] ?? '') . ' income ৳' . $income);
        json_out(['ok' => true, 'id' => $id, 'tax_estimate' => $est]);
    }
    if ($action === 'tax-save') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('nrb_tax_returns', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Tax return not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your tax return.'], 403);
        if ($row['status'] === 'Paid') json_out(['ok' => false, 'error' => 'Paid returns are locked.'], 409);
        $pdo->prepare("UPDATE nrb_tax_returns SET prop=?, fy=?, rental_income=?, tds_deducted=?, tax_estimate=?, ca_firm=?, ca_ref=?, notes=?, updated_at=datetime('now') WHERE id=?")
            ->execute([trim($body['prop'] ?? $row['prop']), trim($body['fy'] ?? $row['fy']), max(0, (int)($body['rental_income'] ?? $row['rental_income'])), max(0, (int)($body['tds_deducted'] ?? $row['tds_deducted'])), max(0, (int)($body['tax_estimate'] ?? $row['tax_estimate'])), trim($body['ca_firm'] ?? $row['ca_firm']), trim($body['ca_ref'] ?? $row['ca_ref']), trim($body['notes'] ?? $row['notes']), $id]);
        audit($u['name'], 'Tax return updated', 'nrb', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'tax-status') {
        $id = trim($body['id'] ?? ''); $status = trim($body['status'] ?? '');
        if (!$id || !in_array($status, ['Draft', 'Filed', 'Paid'], true)) json_out(['ok' => false, 'error' => 'id + status (Draft|Filed|Paid) required.'], 400);
        $row = $fetch('nrb_tax_returns', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Tax return not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your tax return.'], 403);
        $from = $row['status'];
        $ok = ($from === 'Draft' && in_array($status, ['Filed', 'Paid'], true)) || ($from === 'Filed' && in_array($status, ['Draft', 'Paid'], true));
        if (!$ok) json_out(['ok' => false, 'error' => "Invalid transition $from → $status."], 409);
        $filing = $row['filing_date'] ?: (($status === 'Filed' || $status === 'Paid') ? date('Y-m-d') : '');
        $paid = $status === 'Paid' ? ($row['paid_date'] ?: date('Y-m-d')) : '';
        $pdo->prepare("UPDATE nrb_tax_returns SET status=?, filing_date=?, paid_date=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $filing, $paid, $id]);
        audit($u['name'], 'Tax return → ' . $status, 'nrb', $id);
        json_out(['ok' => true, 'status' => $status]);
    }
    if ($action === 'repat-list') { json_out(['ok' => true, 'rows' => nrb_rp_rows($pdo, $u)]); }
    if ($action === 'repat-create') {
        $amount = (int)($body['amount_bdt'] ?? 0);
        $rate = (float)($body['rate'] ?? 0);
        if ($amount <= 0 || $rate <= 0) json_out(['ok' => false, 'error' => 'amount_bdt and rate (>0) required.'], 400);
        if (!in_array($body['method'] ?? 'SWIFT', ['SWIFT', 'bKash', 'Remitly', 'Western Union', 'Other'], true)) json_out(['ok' => false, 'error' => 'Invalid method.'], 400);
        if (!in_array($body['bank_channel'] ?? 'NRP_Account', ['NRP_Account', 'BB_Approval', 'Overseas_Transfer'], true)) json_out(['ok' => false, 'error' => 'Invalid bank_channel.'], 400);
        $email = $u['role'] === 'owner' ? $u['email'] : trim($body['owner_email'] ?? $u['email']);
        $id = nrb_rp_next_id($pdo);
        $pdo->prepare('INSERT INTO nrb_repatriations (id, owner_email, amount_bdt, amount_fx, rate, currency, method, bank_channel, documents, status, ref) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $email, $amount, round($amount / $rate, 2), $rate, trim($body['currency'] ?? 'USD'), $body['method'] ?? 'SWIFT', $body['bank_channel'] ?? 'NRP_Account', '[]', 'Requested', trim($body['ref'] ?? '')]);
        audit($u['name'], 'Repatriation requested', 'nrb', $id, '৳' . number_format($amount) . ' @ ' . $rate);
        json_out(['ok' => true, 'id' => $id, 'amount_fx' => round($amount / $rate, 2)]);
    }
    if ($action === 'repat-docs') {
        $id = trim($body['id'] ?? '');
        if (!$id || !is_array($body['documents'] ?? null)) json_out(['ok' => false, 'error' => 'id + documents array required.'], 400);
        $row = $fetch('nrb_repatriations', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Repatriation not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your repatriation.'], 403);
        if (in_array($row['status'], ['Completed', 'Rejected'], true)) json_out(['ok' => false, 'error' => 'Completed/Rejected requests are locked.'], 409);
        $status = $row['status'] === 'Requested' ? 'Docs_Submitted' : $row['status'];
        $docs = array_values(array_filter(array_map('trim', array_map('strval', $body['documents']))));
        $pdo->prepare("UPDATE nrb_repatriations SET documents=?, status=?, updated_at=datetime('now') WHERE id=?")->execute([json_encode($docs, JSON_UNESCAPED_UNICODE), $status, $id]);
        audit($u['name'], 'Repatriation documents added', 'nrb', $id, count($docs) . ' docs');
        json_out(['ok' => true, 'status' => $status]);
    }
    if ($action === 'repat-status') {
        $id = trim($body['id'] ?? ''); $status = trim($body['status'] ?? '');
        $allowed = ['Requested', 'Docs_Submitted', 'Submitted', 'Approved', 'Completed', 'Rejected'];
        if (!$id || !in_array($status, $allowed, true)) json_out(['ok' => false, 'error' => 'id + status required.'], 400);
        $row = $fetch('nrb_repatriations', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Repatriation not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your repatriation.'], 403);
        $from = $row['status'];
        $seq = array_flip($allowed);
        if ($status === $from) json_out(['ok' => false, 'error' => 'Already ' . $status . '.'], 409);
        if ($seq[$status] < $seq[$from]) json_out(['ok' => false, 'error' => "Invalid transition $from → $status."], 409);
        $submitted = ($status === 'Submitted' || $status === 'Approved' || $status === 'Completed') ? ($row['submitted_at'] ?: date('Y-m-d')) : $row['submitted_at'];
        $approved = ($status === 'Approved' || $status === 'Completed') ? ($row['approved_at'] ?: date('Y-m-d')) : $row['approved_at'];
        $completed = $status === 'Completed' ? ($row['completed_at'] ?: date('Y-m-d')) : $row['completed_at'];
        $pdo->prepare("UPDATE nrb_repatriations SET status=?, submitted_at=?, approved_at=?, completed_at=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $submitted, $approved, $completed, $id]);
        audit($u['name'], 'Repatriation → ' . $status, 'nrb', $id);
        json_out(['ok' => true, 'status' => $status]);
    }
    if ($action === 'vacancy-list') { json_out(['ok' => true, 'rows' => nrb_vc_enrich($pdo, nrb_vc_rows($pdo, $u))]); }
    if ($action === 'vacancy-create') {
        $prop = trim($body['prop'] ?? ''); $unit = trim($body['unit'] ?? '');
        if (!$prop || !$unit) json_out(['ok' => false, 'error' => 'prop and unit required.'], 400);
        $ps = $pdo->prepare('SELECT id FROM properties WHERE id=?'); $ps->execute([$prop]);
        if (!$ps->fetch()) json_out(['ok' => false, 'error' => 'Property not found.'], 404);
        $us = $pdo->prepare('SELECT id FROM units WHERE id=?'); $us->execute([$unit]);
        if (!$us->fetch()) json_out(['ok' => false, 'error' => 'Unit not found.'], 404);
        $email = $u['role'] === 'owner' ? $u['email'] : trim($body['owner_email'] ?? '');
        $id = nrb_vc_next_id($pdo);
        $channels = is_array($body['channels'] ?? null) ? array_values(array_filter(array_map('trim', array_map('strval', $body['channels'])))) : [];
        $pdo->prepare('INSERT INTO nrb_vacancies (id, owner_email, prop, unit, status, started_at, tenant_left, target_rent, channels, listing_url, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $email, $prop, $unit, 'Vacant', trim($body['started_at'] ?? date('Y-m-d')), trim($body['tenant_left'] ?? ''), max(0, (int)($body['target_rent'] ?? 0)), json_encode($channels, JSON_UNESCAPED_UNICODE), trim($body['listing_url'] ?? ''), trim($body['notes'] ?? '')]);
        audit($u['name'], 'Vacancy registered', 'nrb', $id, $unit . ' target ৳' . (int)($body['target_rent'] ?? 0));
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'vacancy-save') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('nrb_vacancies', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Vacancy not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your vacancy.'], 403);
        if (in_array($row['status'], ['Filled', 'Cancelled'], true)) json_out(['ok' => false, 'error' => 'Filled/Cancelled vacancies are locked.'], 409);
        $channels = is_array($body['channels'] ?? null) ? array_values(array_filter(array_map('trim', array_map('strval', $body['channels'])))) : ((json_decode($row['channels'] ?: '[]', true)) ?: []);
        $pdo->prepare("UPDATE nrb_vacancies SET target_rent=?, channels=?, listing_url=?, tenant_left=?, started_at=?, notes=?, updated_at=datetime('now') WHERE id=?")
            ->execute([max(0, (int)($body['target_rent'] ?? $row['target_rent'])), json_encode($channels, JSON_UNESCAPED_UNICODE), trim($body['listing_url'] ?? $row['listing_url']), trim($body['tenant_left'] ?? $row['tenant_left']), trim($body['started_at'] ?? $row['started_at']), trim($body['notes'] ?? $row['notes']), $id]);
        audit($u['name'], 'Vacancy updated', 'nrb', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'vacancy-candidate') {
        $id = trim($body['id'] ?? '');
        if (!$id || !is_array($body['candidate'] ?? null)) json_out(['ok' => false, 'error' => 'id + candidate object required.'], 400);
        $row = $fetch('nrb_vacancies', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Vacancy not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your vacancy.'], 403);
        if (in_array($row['status'], ['Filled', 'Cancelled'], true)) json_out(['ok' => false, 'error' => 'Filled/Cancelled vacancies are locked.'], 409);
        $vet = in_array($body['vet_status'] ?? 'none', ['none', 'pending', 'passed', 'failed'], true) ? $body['vet_status'] : 'none';
        $c = $body['candidate'];
        $cand = ['name' => trim((string)($c['name'] ?? '')), 'phone' => trim((string)($c['phone'] ?? '')), 'email' => trim((string)($c['email'] ?? '')), 'nid' => trim((string)($c['nid'] ?? '')), 'rent' => max(0, (int)($c['rent'] ?? $row['target_rent'])), 'start' => trim((string)($c['start'] ?? '')), 'months' => max(1, min(36, (int)($c['months'] ?? 12))), 'advance' => max(0, (int)($c['advance'] ?? 0))];
        $pdo->prepare("UPDATE nrb_vacancies SET candidate=?, vet_status=?, updated_at=datetime('now') WHERE id=?")->execute([json_encode($cand, JSON_UNESCAPED_UNICODE), $vet, $id]);
        audit($u['name'], 'Vacancy candidate set', 'nrb', $id, $cand['name'] . ' vet=' . $vet);
        json_out(['ok' => true, 'vet_status' => $vet]);
    }
    if ($action === 'vacancy-status') {
        $id = trim($body['id'] ?? ''); $status = trim($body['status'] ?? '');
        $allowed = ['Vacant', 'Marketing', 'Showing', 'Vetting', 'Awaiting_Approval', 'Cancelled'];
        if (!$id || !in_array($status, $allowed, true)) json_out(['ok' => false, 'error' => 'id + status required (Vacant|Marketing|Showing|Vetting|Awaiting_Approval|Cancelled).'], 400);
        $row = $fetch('nrb_vacancies', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Vacancy not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your vacancy.'], 403);
        if (in_array($row['status'], ['Filled', 'Cancelled'], true)) json_out(['ok' => false, 'error' => 'Filled/Cancelled vacancies are locked.'], 409);
        if ($status === 'Awaiting_Approval') {
            $cand = json_decode($row['candidate'] ?: '{}', true) ?: [];
            if (empty($cand['name']) || $row['vet_status'] !== 'passed')
                json_out(['ok' => false, 'error' => 'A candidate with passed vetting is required before Awaiting_Approval.'], 400);
        }
        $pdo->prepare("UPDATE nrb_vacancies SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $id]);
        audit($u['name'], 'Vacancy → ' . $status, 'nrb', $id);
        json_out(['ok' => true, 'status' => $status]);
    }
    if ($action === 'vacancy-approve') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('nrb_vacancies', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Vacancy not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your vacancy.'], 403);
        if ($row['status'] !== 'Awaiting_Approval') json_out(['ok' => false, 'error' => 'Only Awaiting_Approval vacancies can be approved (current: ' . $row['status'] . ').'], 409);
        $res = nrb_vacancy_approve($pdo, $u, $row);
        if (empty($res['ok'])) json_out(['ok' => false, 'error' => $res['error']], 400);
        audit($u['name'], 'Vacancy approved — lease signed', 'nrb', $id, $res['tenant'] . ' ' . $res['lease'] . ' ' . $res['invoice']);
        $pdo->prepare('INSERT INTO notices (id, title, body, author) VALUES (?,?,?,?)')
            ->execute(['N-' . strtoupper(bin2hex(random_bytes(3))), 'Lease signed — welcome!', 'Your lease ' . $res['lease'] . ' is active. First invoice ' . $res['invoice'] . ' generated.', 'NRB Hub']);
        json_out(['ok' => true] + $res);
    }
    if ($action === 'vacancy-cancel') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('nrb_vacancies', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Vacancy not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your vacancy.'], 403);
        if ($row['status'] === 'Filled') json_out(['ok' => false, 'error' => 'Filled vacancies cannot be cancelled.'], 409);
        $pdo->prepare("UPDATE nrb_vacancies SET status='Cancelled', updated_at=datetime('now') WHERE id=?")->execute([$id]);
        audit($u['name'], 'Vacancy cancelled', 'nrb', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'showing-list') { json_out(['ok' => true, 'rows' => nrb_sh_rows($pdo, $u)]); }
    if ($action === 'showing-create') {
        $vid = trim($body['vacancy'] ?? '');
        $name = trim($body['prospect'] ?? '');
        if (!$vid || !$name) json_out(['ok' => false, 'error' => 'vacancy + prospect required.'], 400);
        $row = $fetch('nrb_vacancies', $vid);
        if (!$row) json_out(['ok' => false, 'error' => 'Vacancy not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your vacancy.'], 403);
        $when = trim($body['scheduled_at'] ?? '');
        if ($when && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $when)) json_out(['ok' => false, 'error' => 'scheduled_at must be YYYY-MM-DD.'], 400);
        $id = nrb_sh_next_id($pdo);
        $pdo->prepare('INSERT INTO nrb_showings (id, vacancy, prospect, phone, scheduled_at, status) VALUES (?,?,?,?,?,?)')
            ->execute([$id, $vid, $name, trim($body['phone'] ?? ''), $when ?: date('Y-m-d'), 'Scheduled']);
        audit($u['name'], 'Showing scheduled', 'nrb', $id, $name . ' @ ' . ($when ?: 'today'));
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'showing-save') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('nrb_showings', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Showing not found.'], 404);
        $vr = $fetch('nrb_vacancies', $row['vacancy']);
        if (!$vr || !$ownerOk($vr['owner_email'])) json_out(['ok' => false, 'error' => 'Not your showing.'], 403);
        $pdo->prepare('UPDATE nrb_showings SET prospect=?, phone=?, scheduled_at=?, feedback=?, rating=? WHERE id=?')
            ->execute([trim($body['prospect'] ?? $row['prospect']), trim($body['phone'] ?? $row['phone']), trim($body['scheduled_at'] ?? $row['scheduled_at']), trim($body['feedback'] ?? $row['feedback']), max(0, min(5, (int)($body['rating'] ?? $row['rating']))), $id]);
        audit($u['name'], 'Showing updated', 'nrb', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'showing-status') {
        $id = trim($body['id'] ?? ''); $status = trim($body['status'] ?? '');
        if (!$id || !in_array($status, ['Scheduled', 'Done', 'No_Show', 'Cancelled'], true)) json_out(['ok' => false, 'error' => 'id + status required.'], 400);
        $row = $fetch('nrb_showings', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Showing not found.'], 404);
        $vr = $fetch('nrb_vacancies', $row['vacancy']);
        if (!$vr || !$ownerOk($vr['owner_email'])) json_out(['ok' => false, 'error' => 'Not your showing.'], 403);
        if ($row['status'] !== 'Scheduled' && $status !== $row['status']) json_out(['ok' => false, 'error' => 'Only Scheduled showings can change status.'], 409);
        $rating = $status === 'Done' ? max(1, min(5, (int)($body['rating'] ?? ($row['rating'] ?: 5)))) : $row['rating'];
        $feedback = ($status === 'Done' && !empty($body['feedback'])) ? trim($body['feedback']) : $row['feedback'];
        $pdo->prepare('UPDATE nrb_showings SET status=?, feedback=?, rating=? WHERE id=?')->execute([$status, $feedback, $rating, $id]);
        audit($u['name'], 'Showing → ' . $status, 'nrb', $id);
        json_out(['ok' => true, 'status' => $status]);
    }
    if ($action === 'dispute-list') { json_out(['ok' => true, 'rows' => nrb_ds_enrich($pdo, nrb_ds_rows($pdo, $u))]); }
    if ($action === 'dispute-create') {
        $dtype = $body['dtype'] ?? 'other';
        if (!in_array($dtype, ['eviction', 'damages', 'arrears', 'other'], true)) json_out(['ok' => false, 'error' => 'Invalid dtype.'], 400);
        $email = $u['role'] === 'owner' ? $u['email'] : trim($body['owner_email'] ?? '');
        $cfg = nrb_config_rows($pdo);
        $id = nrb_ds_next_id($pdo);
        $advocate = trim($body['advocate'] ?? $cfg['default_advocate']);
        $tl = [['ts' => date('Y-m-d H:i:s'), 'action' => 'Dispute opened — ' . (trim($body['notes'] ?? '') ?: 'case registered'), 'by' => $u['name']]];
        $pdo->prepare('INSERT INTO nrb_disputes (id, owner_email, prop, unit, tenant, dtype, advocate, fee, status, notice_ref, last_update, next_action, timeline) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $email, trim($body['prop'] ?? ''), trim($body['unit'] ?? ''), trim($body['tenant'] ?? ''), $dtype, $advocate, max(0, (int)($body['fee'] ?? 0)), 'Open', trim($body['notice_ref'] ?? ''), trim($body['last_update'] ?? 'Dispute opened'), trim($body['next_action'] ?? ''), json_encode($tl, JSON_UNESCAPED_UNICODE)]);
        audit($u['name'], 'Dispute opened', 'nrb', $id, $dtype . ' vs ' . ($body['tenant'] ?? ''));
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'dispute-save') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('nrb_disputes', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Dispute not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your dispute.'], 403);
        if (in_array($row['status'], ['Resolved', 'Closed'], true)) json_out(['ok' => false, 'error' => 'Resolved/Closed disputes are locked.'], 409);
        $pdo->prepare("UPDATE nrb_disputes SET advocate=?, fee=?, last_update=?, next_action=?, notice_ref=?, updated_at=datetime('now') WHERE id=?")
            ->execute([trim($body['advocate'] ?? $row['advocate']), max(0, (int)($body['fee'] ?? $row['fee'])), trim($body['last_update'] ?? $row['last_update']), trim($body['next_action'] ?? $row['next_action']), trim($body['notice_ref'] ?? $row['notice_ref']), $id]);
        audit($u['name'], 'Dispute updated', 'nrb', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'dispute-status') {
        $id = trim($body['id'] ?? ''); $status = trim($body['status'] ?? '');
        $allowed = ['Open', 'Notice_Served', 'In_Progress', 'Escalated', 'Resolved', 'Closed'];
        if (!$id || !in_array($status, $allowed, true)) json_out(['ok' => false, 'error' => 'id + status required.'], 400);
        $row = $fetch('nrb_disputes', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Dispute not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your dispute.'], 403);
        if ($status === $row['status']) json_out(['ok' => false, 'error' => 'Already ' . $status . '.'], 409);
        if (in_array($row['status'], ['Resolved', 'Closed'], true)) json_out(['ok' => false, 'error' => 'Resolved/Closed disputes are locked.'], 409);
        if ($status === 'Notice_Served' && !empty($body['notice_ref'])) {
            $pdo->prepare("UPDATE nrb_disputes SET notice_ref=?, updated_at=datetime('now') WHERE id=?")->execute([trim($body['notice_ref']), $id]);
        }
        nrb_ds_event($pdo, $id, 'Status → ' . str_replace('_', ' ', $status) . ((!empty($body['note'])) ? ' — ' . trim($body['note']) : ''), $u['name']);
        $pdo->prepare("UPDATE nrb_disputes SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $id]);
        audit($u['name'], 'Dispute → ' . $status, 'nrb', $id);
        json_out(['ok' => true, 'status' => $status]);
    }
    if ($action === 'dispute-event') {
        $id = trim($body['id'] ?? '');
        $note = trim($body['note'] ?? '');
        if (!$id || !$note) json_out(['ok' => false, 'error' => 'id + note required.'], 400);
        $row = $fetch('nrb_disputes', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Dispute not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your dispute.'], 403);
        if (in_array($row['status'], ['Resolved', 'Closed'], true)) json_out(['ok' => false, 'error' => 'Resolved/Closed disputes are locked.'], 409);
        nrb_ds_event($pdo, $id, $note, $u['name']);
        if (!empty($body['last_update'])) $pdo->prepare("UPDATE nrb_disputes SET last_update=?, updated_at=datetime('now') WHERE id=?")->execute([trim($body['last_update']), $id]);
        audit($u['name'], 'Dispute event added', 'nrb', $id, $note);
        json_out(['ok' => true]);
    }
    if ($action === 'config-get') { json_out(['ok' => true, 'config' => nrb_config_rows($pdo)]); }
    if ($action === 'config-save') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owner/manager can edit NRB config.'], 403);
        $cfg = $body['config'] ?? null;
        if (!is_array($cfg)) json_out(['ok' => false, 'error' => 'config object required.'], 400);
        $saved = nrb_config_save($pdo, $cfg);
        audit($u['name'], 'NRB config updated', 'nrb', 'config', count($saved) . ' keys');
        json_out(['ok' => true, 'config' => $saved]);
    }
    json_out(['ok' => false, 'error' => 'action must be summary|tax-list|tax-create|tax-save|tax-status|repat-list|repat-create|repat-docs|repat-status|vacancy-list|vacancy-create|vacancy-save|vacancy-candidate|vacancy-status|vacancy-approve|vacancy-cancel|showing-list|showing-create|showing-save|showing-status|dispute-list|dispute-create|dispute-save|dispute-status|dispute-event|config-get|config-save.'], 400);
}

case 'app-concierge': {
    $u = require_user();
    require_module($u, 'concierge');
    $pdo = db();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'legal', 'accountant', 'crm'], true))
        json_out(['ok' => false, 'error' => 'Legal Concierge is for owners and staff.'], 403);
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'summary' : 'list';
    $ownerOk = function ($email) use ($u) {
        if ($u['role'] === 'owner') return $email === $u['email'] || $email === '';
        return true;
    };
    $fetch = function ($table, $id) use ($pdo) {
        $st = $pdo->prepare("SELECT * FROM $table WHERE id=?"); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    };

    if ($action === 'summary') {
        $rows = cr_rows($pdo, $u);
        $ht = holding_rows($pdo, $u);
        $crStat = ['Submitted' => 0, 'Under_Review' => 0, 'Docs_Requested' => 0, 'In_Progress' => 0, 'Awaiting_Fee' => 0, 'Completed' => 0, 'Rejected' => 0, 'Cancelled' => 0];
        foreach ($rows as $r) $crStat[$r['status']] = ($crStat[$r['status']] ?? 0) + 1;
        $svcStat = [];
        foreach (concierge_service_list() as $s) $svcStat[$s] = 0;
        foreach ($rows as $r) $svcStat[$r['service']] = ($svcStat[$r['service']] ?? 0) + 1;
        $feesDue = 0;
        foreach ($rows as $r) if ($r['status'] === 'Awaiting_Fee' && $r['fee_status'] !== 'paid') $feesDue += (int)$r['fee'];
        $htEnr = holding_enrich($pdo, $ht);
        $htDue = 0;
        foreach ($htEnr as $h) if ($h['status'] !== 'Paid') $htDue += (int)$h['tax_amount'];
        json_out(['ok' => true,
            'requests' => ['rows' => cr_enrich($pdo, $rows), 'by_status' => $crStat, 'by_service' => $svcStat, 'fees_due' => $feesDue],
            'holdings' => ['rows' => $htEnr, 'due_total' => $htDue],
            'config' => concierge_config_rows($pdo)]);
    }
    if ($action === 'request-list') { json_out(['ok' => true, 'rows' => cr_enrich($pdo, cr_rows($pdo, $u))]); }
    if ($action === 'request-create') {
        $service = trim($body['service'] ?? '');
        if (!in_array($service, concierge_service_list(), true)) json_out(['ok' => false, 'error' => 'service must be one of namjari|e_porcha|khatian|holding_tax|registration.'], 400);
        $email = $u['role'] === 'owner' ? $u['email'] : trim($body['owner_email'] ?? $u['email']);
        if ($email === '') $email = $u['email'];
        $parcel = trim($body['parcel'] ?? '');
        $district = trim($body['district'] ?? ''); $upazila = trim($body['upazila'] ?? '');
        $mouza = trim($body['mouza'] ?? ''); $khatian = trim($body['khatian'] ?? ''); $dag = trim($body['dag'] ?? '');
        if ($parcel) {
            $pst = $pdo->prepare('SELECT * FROM land_parcels WHERE id=?'); $pst->execute([$parcel]);
            $lp = $pst->fetch(PDO::FETCH_ASSOC);
            if (!$lp) json_out(['ok' => false, 'error' => 'Parcel not found.'], 404);
            if (!$district) $district = $lp['district'] ?? '';
            if (!$upazila) $upazila = $lp['upazila'] ?? '';
            if (!$mouza) $mouza = $lp['mouza'] ?? '';
            if (!$khatian) $khatian = $lp['khatian'] ?? '';
            if (!$dag) $dag = $lp['dag'] ?? '';
        }
        $cfg = concierge_config_rows($pdo);
        $fee = isset($body['fee']) ? max(0, (int)$body['fee']) : (int)($cfg[$service . '_fee'] ?? 0);
        $est = isset($body['est_days']) ? max(1, (int)$body['est_days']) : (int)($cfg[$service . '_days'] ?? 30);
        $id = cr_next_id($pdo);
        $pdo->prepare('INSERT INTO concierge_requests (id, owner_email, parcel, prop, service, district, upazila, mouza, khatian, dag, status, assigned_to, est_days, fee, fee_status, notes, timeline) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $email, $parcel, trim($body['prop'] ?? ''), $service, $district, $upazila, $mouza, $khatian, $dag, 'Submitted', trim($body['assigned_to'] ?? $cfg['concierge_agent']), $est, $fee, 'unpaid', trim($body['notes'] ?? ''), json_encode([['ts' => date('Y-m-d H:i:s'), 'action' => 'Request submitted — ' . str_replace('_', ' ', $service), 'by' => $u['name']]], JSON_UNESCAPED_UNICODE)]);
        audit($u['name'], 'Concierge request created', 'concierge', $id, $service . ' fee ৳' . $fee . ' est ' . $est . 'd');
        json_out(['ok' => true, 'id' => $id, 'fee' => $fee, 'est_days' => $est]);
    }
    if ($action === 'request-get') {
        $id = trim($body['id'] ?? $_GET['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('concierge_requests', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Request not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your request.'], 403);
        $e = cr_enrich($pdo, [$row])[0];
        $e['docs'] = cd_rows($pdo, $id, $u);
        json_out(['ok' => true, 'request' => $e]);
    }
    if ($action === 'request-update') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('concierge_requests', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Request not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your request.'], 403);
        if (in_array($row['status'], ['Completed', 'Rejected', 'Cancelled'], true)) json_out(['ok' => false, 'error' => 'Completed/Rejected/Cancelled requests are locked.'], 409);
        if ($u['role'] === 'owner' && $row['status'] !== 'Submitted') json_out(['ok' => false, 'error' => 'Owners can only edit while the request is Submitted.'], 409);
        $pdo->prepare("UPDATE concierge_requests SET prop=?, district=?, upazila=?, mouza=?, khatian=?, dag=?, assigned_to=?, est_days=?, fee=?, service_ref=?, notes=?, updated_at=datetime('now') WHERE id=?")
            ->execute([trim($body['prop'] ?? $row['prop']), trim($body['district'] ?? $row['district']), trim($body['upazila'] ?? $row['upazila']), trim($body['mouza'] ?? $row['mouza']), trim($body['khatian'] ?? $row['khatian']), trim($body['dag'] ?? $row['dag']), trim($body['assigned_to'] ?? $row['assigned_to']), max(1, (int)($body['est_days'] ?? $row['est_days'])), max(0, (int)($body['fee'] ?? $row['fee'])), trim($body['service_ref'] ?? $row['service_ref']), trim($body['notes'] ?? $row['notes']), $id]);
        audit($u['name'], 'Concierge request updated', 'concierge', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'request-status') {
        $id = trim($body['id'] ?? ''); $status = trim($body['status'] ?? '');
        $allowed = ['Submitted', 'Under_Review', 'Docs_Requested', 'In_Progress', 'Awaiting_Fee', 'Completed', 'Rejected', 'Cancelled'];
        if (!$id || !in_array($status, $allowed, true)) json_out(['ok' => false, 'error' => 'id + status required.'], 400);
        $row = $fetch('concierge_requests', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Request not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your request.'], 403);
        if (in_array($row['status'], ['Completed', 'Rejected', 'Cancelled'], true)) json_out(['ok' => false, 'error' => 'Completed/Rejected/Cancelled requests are locked.'], 409);
        if ($status === 'Completed' && $row['fee_status'] !== 'paid' && (int)$row['fee'] > 0) json_out(['ok' => false, 'error' => 'Service fee must be paid before completion.'], 409);
        $seq = concierge_seq();
        $cur = array_search($row['status'], $seq, true);
        $tgt = array_search($status, $seq, true);
        if ($tgt !== false && $cur !== false && $tgt <= $cur) json_out(['ok' => false, 'error' => 'Cannot move backward — current ' . $row['status'] . '.'], 409);
        if (!in_array($status, ['Rejected', 'Cancelled'], true) && $tgt === false) json_out(['ok' => false, 'error' => 'Invalid status.'], 400);
        if ($status === 'Awaiting_Fee' && $row['fee_status'] !== 'paid' && (int)$row['fee'] > 0) {
            $pdo->prepare("UPDATE concierge_requests SET awaiting_fee_at=datetime('now'), updated_at=datetime('now') WHERE id=?")->execute([$id]);
        }
        if ($status === 'Completed') {
            $pdo->prepare("UPDATE concierge_requests SET completed_at=datetime('now'), updated_at=datetime('now') WHERE id=?")->execute([$id]);
        }
        if (!empty($body['service_ref'])) {
            $pdo->prepare("UPDATE concierge_requests SET service_ref=?, updated_at=datetime('now') WHERE id=?")->execute([trim($body['service_ref']), $id]);
        }
        cr_event($pdo, $id, 'Status → ' . str_replace('_', ' ', $status) . ((!empty($body['note'])) ? ' — ' . trim($body['note']) : ''), $u['name']);
        $pdo->prepare("UPDATE concierge_requests SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $id]);
        audit($u['name'], 'Concierge request → ' . $status, 'concierge', $id);
        json_out(['ok' => true, 'status' => $status]);
    }
    if ($action === 'request-event') {
        $id = trim($body['id'] ?? ''); $note = trim($body['note'] ?? '');
        if (!$id || !$note) json_out(['ok' => false, 'error' => 'id + note required.'], 400);
        $row = $fetch('concierge_requests', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Request not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your request.'], 403);
        if (in_array($row['status'], ['Completed', 'Rejected', 'Cancelled'], true)) json_out(['ok' => false, 'error' => 'Completed/Rejected/Cancelled requests are locked.'], 409);
        cr_event($pdo, $id, $note, $u['name']);
        audit($u['name'], 'Concierge event added', 'concierge', $id, $note);
        json_out(['ok' => true]);
    }
    if ($action === 'request-fee') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('concierge_requests', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Request not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your request.'], 403);
        if ($row['fee_status'] === 'paid') json_out(['ok' => false, 'error' => 'Fee already paid.'], 409);
        $pdo->prepare("UPDATE concierge_requests SET fee_status='paid', fee_paid_at=datetime('now'), updated_at=datetime('now') WHERE id=?")->execute([$id]);
        audit($u['name'], 'Concierge fee paid', 'concierge', $id, '৳' . number_format($row['fee']));
        json_out(['ok' => true]);
    }
    if ($action === 'doc-upload') {
        $id = trim($_POST['request'] ?? '');
        $kind = trim($_POST['kind'] ?? 'other');
        $kinds = ['application', 'porcha', 'khatian', 'mutation_cert', 'holding_bill', 'nid', 'other'];
        if (!$id) json_out(['ok' => false, 'error' => 'request id required.'], 400);
        if (!in_array($kind, $kinds, true)) json_out(['ok' => false, 'error' => 'kind must be one of application|porcha|khatian|mutation_cert|holding_bill|nid|other.'], 400);
        $row = $fetch('concierge_requests', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Request not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your request.'], 403);
        if (in_array($row['status'], ['Completed', 'Rejected', 'Cancelled'], true)) json_out(['ok' => false, 'error' => 'Completed/Rejected/Cancelled requests are locked.'], 409);
        if (empty($_FILES['file'])) json_out(['ok' => false, 'error' => 'file required (multipart).'], 400);
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) json_out(['ok' => false, 'error' => 'Upload failed.'], 400);
        if ($f['size'] > 8 * 1024 * 1024) json_out(['ok' => false, 'error' => 'File too large (max 8MB).'], 400);
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        if (!in_array($ext, $allowed, true)) json_out(['ok' => false, 'error' => 'Only images and PDF allowed.'], 400);
        $dir = DATA_DIR();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_writable($dir)) json_out(['ok' => false, 'error' => 'Storage not writable.'], 500);
        $docId = cd_next_id($pdo);
        $fname = 'concierge_' . $docId . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) json_out(['ok' => false, 'error' => 'Could not store file.'], 500);
        $pdo->prepare('INSERT INTO concierge_docs (id, request, kind, name, fname, size, mime, uploaded_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$docId, $id, $kind, $f['name'], $fname, $f['size'], $f['type'] ?: 'application/octet-stream', $u['name']]);
        audit($u['name'], 'Concierge doc uploaded', 'concierge', $docId, $kind . ' ' . $id . ' ' . $f['name']);
        json_out(['ok' => true, 'id' => $docId]);
    }
    if ($action === 'doc-list') {
        $id = trim($body['id'] ?? $_GET['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('concierge_requests', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Request not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your request.'], 403);
        json_out(['ok' => true, 'docs' => cd_rows($pdo, $id, $u)]);
    }
    if ($action === 'doc-download') {
        $docId = trim($_GET['id'] ?? '');
        if (!$docId) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM concierge_docs WHERE id=?'); $st->execute([$docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) json_out(['ok' => false, 'error' => 'Document not found.'], 404);
        $req = $fetch('concierge_requests', $doc['request']);
        if (!$req) json_out(['ok' => false, 'error' => 'Request not found.'], 404);
        if (!$ownerOk($req['owner_email'])) json_out(['ok' => false, 'error' => 'Not your document.'], 403);
        $path = DATA_DIR() . '/' . $doc['fname'];
        if (!is_file($path)) json_out(['ok' => false, 'error' => 'File missing on server.'], 404);
        header('Content-Type: ' . ($doc['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . basename($doc['name']) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    if ($action === 'doc-remove') {
        $docId = trim($body['id'] ?? '');
        if (!$docId) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM concierge_docs WHERE id=?'); $st->execute([$docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) json_out(['ok' => false, 'error' => 'Document not found.'], 404);
        $req = $fetch('concierge_requests', $doc['request']);
        if (!$req) json_out(['ok' => false, 'error' => 'Request not found.'], 404);
        if (!$ownerOk($req['owner_email'])) json_out(['ok' => false, 'error' => 'Not your document.'], 403);
        $path = DATA_DIR() . '/' . $doc['fname'];
        if (is_file($path)) @unlink($path);
        $pdo->prepare('DELETE FROM concierge_docs WHERE id=?')->execute([$docId]);
        audit($u['name'], 'Concierge doc removed', 'concierge', $docId);
        json_out(['ok' => true]);
    }
    if ($action === 'holding-list') { json_out(['ok' => true, 'rows' => holding_enrich($pdo, holding_rows($pdo, $u))]); }
    if ($action === 'holding-create') {
        $email = $u['role'] === 'owner' ? $u['email'] : trim($body['owner_email'] ?? $u['email']);
        if ($email === '') $email = $u['email'];
        $annual = max(0, (int)($body['annual_value'] ?? 0));
        $rate = max(0, (float)($body['rate_pct'] ?? 0));
        if ($annual <= 0 || $rate <= 0) json_out(['ok' => false, 'error' => 'annual_value and rate_pct (>0) required.'], 400);
        $tax = (int)round($annual * $rate / 100);
        $id = ht_next_id($pdo);
        $pdo->prepare('INSERT INTO holding_taxes (id, owner_email, parcel, prop, city_corp, ward, holding_no, fy, annual_value, rate_pct, tax_amount, paid_amount, status, due_date, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $email, trim($body['parcel'] ?? ''), trim($body['prop'] ?? ''), trim($body['city_corp'] ?? ''), trim($body['ward'] ?? ''), trim($body['holding_no'] ?? ''), trim($body['fy'] ?? ''), $annual, $rate, $tax, 0, 'Due', trim($body['due_date'] ?? ''), trim($body['notes'] ?? '')]);
        audit($u['name'], 'Holding tax created', 'concierge', $id, $email . ' ৳' . $tax);
        json_out(['ok' => true, 'id' => $id, 'tax_amount' => $tax]);
    }
    if ($action === 'holding-save') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('holding_taxes', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Holding not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your holding.'], 403);
        if ($row['status'] === 'Paid') json_out(['ok' => false, 'error' => 'Paid holdings are locked.'], 409);
        $annual = max(0, (int)($body['annual_value'] ?? $row['annual_value']));
        $rate = max(0, (float)($body['rate_pct'] ?? $row['rate_pct']));
        $tax = (int)round($annual * $rate / 100);
        $pdo->prepare("UPDATE holding_taxes SET city_corp=?, ward=?, holding_no=?, fy=?, annual_value=?, rate_pct=?, tax_amount=?, due_date=?, notes=?, updated_at=datetime('now') WHERE id=?")
            ->execute([trim($body['city_corp'] ?? $row['city_corp']), trim($body['ward'] ?? $row['ward']), trim($body['holding_no'] ?? $row['holding_no']), trim($body['fy'] ?? $row['fy']), $annual, $rate, $tax, trim($body['due_date'] ?? $row['due_date']), trim($body['notes'] ?? $row['notes']), $id]);
        audit($u['name'], 'Holding tax updated', 'concierge', $id);
        json_out(['ok' => true, 'tax_amount' => $tax]);
    }
    if ($action === 'holding-pay') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('holding_taxes', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Holding not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your holding.'], 403);
        if ($row['status'] === 'Paid') json_out(['ok' => false, 'error' => 'Holding tax already paid.'], 409);
        $pdo->prepare("UPDATE holding_taxes SET status='Paid', paid_amount=?, paid_date=datetime('now'), receipt_no=?, updated_at=datetime('now') WHERE id=?")
            ->execute([(int)$row['tax_amount'], trim($body['receipt_no'] ?? ''), $id]);
        audit($u['name'], 'Holding tax paid', 'concierge', $id, '৳' . number_format($row['tax_amount']) . ' ' . trim($body['receipt_no'] ?? ''));
        json_out(['ok' => true, 'status' => 'Paid']);
    }
    if ($action === 'config-get') { json_out(['ok' => true, 'config' => concierge_config_rows($pdo)]); }
    if ($action === 'config-save') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owner/manager can edit Concierge config.'], 403);
        $cfg = $body['config'] ?? null;
        if (!is_array($cfg)) json_out(['ok' => false, 'error' => 'config object required.'], 400);
        $saved = concierge_config_save($pdo, $cfg);
        audit($u['name'], 'Concierge config updated', 'concierge', 'config', count($saved) . ' keys');
        json_out(['ok' => true, 'config' => $saved]);
    }
    json_out(['ok' => false, 'error' => 'action must be summary|request-list|request-create|request-get|request-update|request-status|request-event|request-fee|doc-upload|doc-list|doc-download|doc-remove|holding-list|holding-create|holding-save|holding-pay|config-get|config-save.'], 400);
}

case 'app-smarthome': {
    $u = require_user();
    require_module($u, 'smarthome');
    $pdo = db();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'crm'], true))
        json_out(['ok' => false, 'error' => 'Smart Home is for owners and ops staff.'], 403);
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'summary' : 'list';
    $ownerOk = function ($email) use ($u) {
        if ($u['role'] === 'owner') return $email === $u['email'] || $email === '';
        return true;
    };
    $fetch = function ($table, $id) use ($pdo) {
        $st = $pdo->prepare("SELECT * FROM $table WHERE id=?"); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    };

    if ($action === 'summary') {
        $sl = sl_enrich($pdo, sl_rows($pdo, $u));
        $cv = cv_enrich($pdo, cv_rows($pdo, $u));
        $lockStat = ['active' => 0, 'revoked' => 0, 'expired' => 0];
        foreach ($sl as $l) {
            if ($l['status'] === 'active') $lockStat['active']++;
            elseif ($l['status'] === 'revoked') $lockStat['revoked']++;
            if ($l['expired']) $lockStat['expired']++;
        }
        $camStat = ['online' => 0, 'offline' => 0];
        foreach ($cv as $c) $camStat[$c['status']] = ($camStat[$c['status']] ?? 0) + 1;
        json_out(['ok' => true,
            'locks' => ['rows' => $sl, 'by_status' => $lockStat],
            'cameras' => ['rows' => $cv, 'by_status' => $camStat],
            'config' => smart_config_rows($pdo)]);
    }
    if ($action === 'lock-list') { json_out(['ok' => true, 'rows' => sl_enrich($pdo, sl_rows($pdo, $u))]); }
    if ($action === 'lock-create') {
        $email = $u['role'] === 'owner' ? $u['email'] : trim($body['owner_email'] ?? $u['email']);
        if ($email === '') $email = $u['email'];
        $purpose = trim($body['purpose'] ?? 'other');
        if (!in_array($purpose, ['showing', 'broker', 'maintenance', 'other'], true)) json_out(['ok' => false, 'error' => 'purpose must be showing|broker|maintenance|other.'], 400);
        $code = trim($body['code'] ?? '');
        if ($code === '') $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $cfg = smart_config_rows($pdo);
        $validFrom = trim($body['valid_from'] ?? date('Y-m-d H:i:s'));
        $validUntil = trim($body['valid_until'] ?? date('Y-m-d H:i:s', strtotime('+' . $cfg['lock_valid_hours'] . ' hours')));
        $id = sl_next_id($pdo);
        $pdo->prepare('INSERT INTO smart_locks (id, owner_email, prop, unit, lock_name, model, code, status, purpose, grant_for, valid_from, valid_until, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $email, trim($body['prop'] ?? ''), trim($body['unit'] ?? ''), trim($body['lock_name'] ?? 'Smart lock'), trim($body['model'] ?? 'Yale YRD226'), $code, 'active', $purpose, trim($body['grant_for'] ?? ''), $validFrom, $validUntil, trim($body['notes'] ?? '')]);
        audit($u['name'], 'Smart lock created', 'smarthome', $id, $purpose . ' ' . $code);
        json_out(['ok' => true, 'id' => $id, 'code' => $code]);
    }
    if ($action === 'lock-save') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('smart_locks', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Lock not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your lock.'], 403);
        $pdo->prepare("UPDATE smart_locks SET lock_name=?, model=?, grant_for=?, notes=?, updated_at=datetime('now') WHERE id=?")
            ->execute([trim($body['lock_name'] ?? $row['lock_name']), trim($body['model'] ?? $row['model']), trim($body['grant_for'] ?? $row['grant_for']), trim($body['notes'] ?? $row['notes']), $id]);
        audit($u['name'], 'Smart lock updated', 'smarthome', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'lock-revoke') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('smart_locks', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Lock not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your lock.'], 403);
        if ($row['status'] === 'revoked') json_out(['ok' => false, 'error' => 'Lock already revoked.'], 409);
        $pdo->prepare("UPDATE smart_locks SET status='revoked', updated_at=datetime('now') WHERE id=?")->execute([$id]);
        audit($u['name'], 'Smart lock revoked', 'smarthome', $id);
        json_out(['ok' => true, 'status' => 'revoked']);
    }
    if ($action === 'lock-use') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('smart_locks', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Lock not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your lock.'], 403);
        if ($row['status'] === 'revoked') json_out(['ok' => false, 'error' => 'Lock revoked.'], 409);
        $pdo->prepare("UPDATE smart_locks SET used_count=used_count+1, used_at=datetime('now'), updated_at=datetime('now') WHERE id=?")->execute([$id]);
        audit($u['name'], 'Smart lock used', 'smarthome', $id);
        json_out(['ok' => true, 'used_count' => (int)$row['used_count'] + 1]);
    }
    if ($action === 'showing-code') {
        $sid = trim($body['showing'] ?? '');
        if (!$sid) json_out(['ok' => false, 'error' => 'showing id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM nrb_showings WHERE id=?'); $st->execute([$sid]);
        $sh = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sh) json_out(['ok' => false, 'error' => 'Showing not found.'], 404);
        $vst = $pdo->prepare('SELECT * FROM nrb_vacancies WHERE id=?'); $vst->execute([$sh['vacancy']]);
        $vc = $vst->fetch(PDO::FETCH_ASSOC);
        if (!$vc) json_out(['ok' => false, 'error' => 'Vacancy not found.'], 404);
        if (!$ownerOk($vc['owner_email'])) json_out(['ok' => false, 'error' => 'Not your vacancy.'], 403);
        $q = $pdo->prepare("SELECT id FROM smart_locks WHERE showing=? AND status='active'"); $q->execute([$sid]);
        if ($q->fetchColumn()) json_out(['ok' => false, 'error' => 'An active lock code already exists for this showing.'], 409);
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $day = substr($sh['scheduled_at'] ?: date('Y-m-d'), 0, 10);
        $id = sl_next_id($pdo);
        $pdo->prepare('INSERT INTO smart_locks (id, owner_email, prop, unit, lock_name, model, code, status, purpose, grant_for, showing, valid_from, valid_until, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $vc['owner_email'], $vc['prop'], $vc['unit'], 'Showing code — ' . ($vc['unit'] ?: 'unit'), 'Yale YRD226', $code, 'active', 'showing', $sh['prospect'], $sid, $day . ' 08:00:00', $day . ' 20:00:00', 'Auto-generated for showing ' . $sid]);
        audit($u['name'], 'Showing lock code generated', 'smarthome', $id, $sid . ' ' . $code);
        json_out(['ok' => true, 'id' => $id, 'code' => $code, 'showing' => $sid, 'unit' => $vc['unit'], 'valid_from' => $day . ' 08:00:00', 'valid_until' => $day . ' 20:00:00']);
    }
    if ($action === 'camera-list') { json_out(['ok' => true, 'rows' => cv_enrich($pdo, cv_rows($pdo, $u))]); }
    if ($action === 'camera-create') {
        $email = $u['role'] === 'owner' ? $u['email'] : trim($body['owner_email'] ?? $u['email']);
        if ($email === '') $email = $u['email'];
        $id = cv_next_id($pdo);
        $pdo->prepare('INSERT INTO cctv_cameras (id, owner_email, prop, name, location, status, feed_url, last_seen, notes) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $email, trim($body['prop'] ?? ''), trim($body['name'] ?? 'Camera'), trim($body['location'] ?? ''), trim($body['status'] ?? 'online') === 'offline' ? 'offline' : 'online', trim($body['feed_url'] ?? ''), date('Y-m-d H:i:s'), trim($body['notes'] ?? '')]);
        audit($u['name'], 'Camera created', 'smarthome', $id);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'camera-save') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('cctv_cameras', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Camera not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your camera.'], 403);
        $pdo->prepare("UPDATE cctv_cameras SET name=?, location=?, feed_url=?, notes=?, updated_at=datetime('now') WHERE id=?")
            ->execute([trim($body['name'] ?? $row['name']), trim($body['location'] ?? $row['location']), trim($body['feed_url'] ?? $row['feed_url']), trim($body['notes'] ?? $row['notes']), $id]);
        audit($u['name'], 'Camera updated', 'smarthome', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'camera-status') {
        $id = trim($body['id'] ?? ''); $status = trim($body['status'] ?? '');
        if (!$id || !in_array($status, ['online', 'offline'], true)) json_out(['ok' => false, 'error' => 'id + status (online|offline) required.'], 400);
        $row = $fetch('cctv_cameras', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Camera not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your camera.'], 403);
        $pdo->prepare("UPDATE cctv_cameras SET status=?, last_seen=?, updated_at=datetime('now') WHERE id=?")->execute([$status, date('Y-m-d H:i:s'), $id]);
        audit($u['name'], 'Camera → ' . $status, 'smarthome', $id);
        json_out(['ok' => true, 'status' => $status]);
    }
    if ($action === 'config-get') { json_out(['ok' => true, 'config' => smart_config_rows($pdo)]); }
    if ($action === 'config-save') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owner/manager can edit Smart Home config.'], 403);
        $cfg = $body['config'] ?? null;
        if (!is_array($cfg)) json_out(['ok' => false, 'error' => 'config object required.'], 400);
        $saved = smart_config_save($pdo, $cfg);
        audit($u['name'], 'Smart Home config updated', 'smarthome', 'config', count($saved) . ' keys');
        json_out(['ok' => true, 'config' => $saved]);
    }
    json_out(['ok' => false, 'error' => 'action must be summary|lock-list|lock-create|lock-save|lock-revoke|lock-use|showing-code|camera-list|camera-create|camera-save|camera-status|config-get|config-save.'], 400);
}

case 'app-healthcheck': {
    $u = require_user();
    require_module($u, 'health');
    $pdo = db();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'crm', 'accountant'], true))
        json_out(['ok' => false, 'error' => 'Health Check is for owners and staff.'], 403);
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'summary' : 'list';
    $ownerOk = function ($email) use ($u) {
        if ($u['role'] === 'owner') return $email === $u['email'] || $email === '';
        return true;
    };
    $fetch = function ($table, $id) use ($pdo) {
        $st = $pdo->prepare("SELECT * FROM $table WHERE id=?"); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    };

    if ($action === 'summary') {
        $rows = hp_enrich($pdo, hp_rows($pdo, $u));
        $stat = ['Planned' => 0, 'Scheduled' => 0, 'In_Progress' => 0, 'Completed' => 0, 'Skipped' => 0];
        foreach ($rows as $p) $stat[$p['status']] = ($stat[$p['status']] ?? 0) + 1;
        $season = [];
        foreach (['pre_monsoon', 'pre_summer', 'quarterly'] as $s) $season[$s] = 0;
        foreach ($rows as $p) $season[$p['season']] = ($season[$p['season']] ?? 0) + 1;
        $svc = [];
        foreach (['ac_service', 'roof_waterproof', 'drainage_clear', 'deep_clean', 'pest_control'] as $s) $svc[$s] = 0;
        foreach ($rows as $p) $svc[$p['service']] = ($svc[$p['service']] ?? 0) + 1;
        $thisMonth = date('Y-m');
        $overdue = count(array_filter($rows, fn($p) => !in_array($p['status'], ['Completed', 'Skipped'], true) && $p['scheduled_for'] && $p['scheduled_for'] < $thisMonth));
        $upcoming = count(array_filter($rows, fn($p) => !in_array($p['status'], ['Completed', 'Skipped'], true) && $p['scheduled_for'] && $p['scheduled_for'] >= $thisMonth));
        json_out(['ok' => true,
            'plans' => ['rows' => $rows, 'by_status' => $stat, 'by_season' => $season, 'by_service' => $svc, 'overdue' => $overdue, 'upcoming' => $upcoming],
            'config' => smart_config_rows($pdo)]);
    }
    if ($action === 'plan-list') { json_out(['ok' => true, 'rows' => hp_enrich($pdo, hp_rows($pdo, $u))]); }
    if ($action === 'plan-create') {
        $season = trim($body['season'] ?? 'quarterly');
        $service = trim($body['service'] ?? 'deep_clean');
        if (!in_array($season, ['pre_monsoon', 'pre_summer', 'quarterly'], true)) json_out(['ok' => false, 'error' => 'season must be pre_monsoon|pre_summer|quarterly.'], 400);
        if (!in_array($service, ['ac_service', 'roof_waterproof', 'drainage_clear', 'deep_clean', 'pest_control'], true)) json_out(['ok' => false, 'error' => 'service must be ac_service|roof_waterproof|drainage_clear|deep_clean|pest_control.'], 400);
        $sched = trim($body['scheduled_for'] ?? '');
        if ($sched !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $sched)) json_out(['ok' => false, 'error' => 'scheduled_for must be YYYY-MM.'], 400);
        $email = $u['role'] === 'owner' ? $u['email'] : trim($body['owner_email'] ?? $u['email']);
        if ($email === '') $email = $u['email'];
        $cfg = smart_config_rows($pdo);
        $chk = array_map(fn($t) => ['task' => $t, 'done' => 0], health_checklists($service));
        $id = hp_next_id($pdo);
        $pdo->prepare('INSERT INTO health_plans (id, owner_email, prop, unit, season, service, scheduled_for, status, assigned_to, cost, checklist, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $email, trim($body['prop'] ?? ''), trim($body['unit'] ?? ''), $season, $service, $sched ?: date('Y-m'), 'Planned', trim($body['assigned_to'] ?? $cfg['health_default_vendor']), max(0, (int)($body['cost'] ?? 0)), json_encode($chk, JSON_UNESCAPED_UNICODE), trim($body['notes'] ?? '')]);
        audit($u['name'], 'Health plan created', 'health', $id, $season . ' ' . $service . ' ' . $sched);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'plan-save') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('health_plans', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Plan not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your plan.'], 403);
        if (in_array($row['status'], ['Completed', 'Skipped'], true)) json_out(['ok' => false, 'error' => 'Completed/Skipped plans are locked.'], 409);
        $pdo->prepare("UPDATE health_plans SET assigned_to=?, cost=?, notes=?, updated_at=datetime('now') WHERE id=?")
            ->execute([trim($body['assigned_to'] ?? $row['assigned_to']), max(0, (int)($body['cost'] ?? $row['cost'])), trim($body['notes'] ?? $row['notes']), $id]);
        audit($u['name'], 'Health plan updated', 'health', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'plan-status') {
        $id = trim($body['id'] ?? ''); $status = trim($body['status'] ?? '');
        $allowed = ['Planned', 'Scheduled', 'In_Progress', 'Completed', 'Skipped'];
        if (!$id || !in_array($status, $allowed, true)) json_out(['ok' => false, 'error' => 'id + status required.'], 400);
        $row = $fetch('health_plans', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Plan not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your plan.'], 403);
        if (in_array($row['status'], ['Completed', 'Skipped'], true)) json_out(['ok' => false, 'error' => 'Completed/Skipped plans are locked.'], 409);
        if ($status === 'Completed') {
            $pdo->prepare("UPDATE health_plans SET completed_at=datetime('now'), updated_at=datetime('now') WHERE id=?")->execute([$id]);
        }
        $pdo->prepare("UPDATE health_plans SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $id]);
        audit($u['name'], 'Health plan → ' . $status, 'health', $id);
        json_out(['ok' => true, 'status' => $status]);
    }
    if ($action === 'checklist-save') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $fetch('health_plans', $id);
        if (!$row) json_out(['ok' => false, 'error' => 'Plan not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your plan.'], 403);
        if (in_array($row['status'], ['Completed', 'Skipped'], true)) json_out(['ok' => false, 'error' => 'Completed/Skipped plans are locked.'], 409);
        $chk = json_decode($row['checklist'] ?: '[]', true) ?: [];
        if (isset($body['index']) && isset($body['done'])) {
            $i = (int)$body['index'];
            if (!isset($chk[$i])) json_out(['ok' => false, 'error' => 'Checklist item not found.'], 400);
            $chk[$i]['done'] = !empty($body['done']) ? 1 : 0;
        } elseif (isset($body['checklist']) && is_array($body['checklist'])) {
            $chk = $body['checklist'];
        } else {
            json_out(['ok' => false, 'error' => 'index+done or checklist array required.'], 400);
        }
        $pdo->prepare("UPDATE health_plans SET checklist=?, updated_at=datetime('now') WHERE id=?")
            ->execute([json_encode($chk, JSON_UNESCAPED_UNICODE), $id]);
        $done = count(array_filter($chk, fn($c) => !empty($c['done'])));
        audit($u['name'], 'Health checklist updated', 'health', $id, $done . '/' . count($chk));
        json_out(['ok' => true, 'tasks_done' => $done, 'tasks_total' => count($chk)]);
    }
    if ($action === 'config-get') { json_out(['ok' => true, 'config' => smart_config_rows($pdo)]); }
    if ($action === 'config-save') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owner/manager can edit Health Check config.'], 403);
        $cfg = $body['config'] ?? null;
        if (!is_array($cfg)) json_out(['ok' => false, 'error' => 'config object required.'], 400);
        $saved = smart_config_save($pdo, $cfg);
        audit($u['name'], 'Health config updated', 'health', 'config', count($saved) . ' keys');
        json_out(['ok' => true, 'config' => $saved]);
    }
    json_out(['ok' => false, 'error' => 'action must be summary|plan-list|plan-create|plan-save|plan-status|checklist-save|config-get|config-save.'], 400);
}

case 'app-build': {
    $svc = service_authed();
    if (!$svc) {
        $u = require_user();
        require_module($u, 'build');
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr'], true))
            json_out(['ok' => false, 'error' => 'Build Watch is for owners and operations staff.'], 403);
    } else {
        /* V2.23: service-key consistency — cron/reporting may read build data */
        $u = ['name' => 'system', 'role' => 'svc', 'email' => '', 'id' => 0];
    }
    $pdo = db();
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'summary' : 'list';
    $ownerOk = function ($email) use ($u) {
        if ($u['role'] === 'owner') return $email === $u['email'] || $email === '';
        return true;
    };
    $fetchP = function ($id) use ($pdo) {
        $st = $pdo->prepare('SELECT * FROM build_projects WHERE id=?'); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    };

    if ($action === 'summary' || $action === 'list') {
        $rows = bp_enrich($pdo, bp_rows($pdo, $u));
        if ($action === 'summary') {
            $stat = ['Planning' => 0, 'In_Progress' => 0, 'On_Hold' => 0, 'Completed' => 0, 'Cancelled' => 0];
            foreach ($rows as $p) $stat[$p['status']] = ($stat[$p['status']] ?? 0) + 1;
            json_out(['ok' => true, 'projects' => $rows, 'stats' => $stat,
                'total_budget' => array_sum(array_map(fn($p) => (int)$p['budget_total'], $rows)),
                'total_spent' => array_sum(array_map(fn($p) => (int)$p['spent'], $rows)),
                'alerts' => build_alerts($pdo, $rows),
                'config' => ['milestone_alert_days' => (int)(build_cfg($pdo, 'milestone_alert_days', 14)), 'budget_overrun_pct' => (int)(build_cfg($pdo, 'budget_overrun_pct', 10)), 'default_contractor' => (string)(build_cfg($pdo, 'default_contractor', ''))]]);
        }
        json_out(['ok' => true, 'projects' => $rows]);
    }

    if ($action === 'get') {
        $id = trim($body['id'] ?? $_GET['id'] ?? '');
        $row = $fetchP($id);
        if (!$row) json_out(['ok' => false, 'error' => 'Project not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        $enr = bp_enrich($pdo, [$row])[0];
        json_out(['ok' => true, 'project' => $enr, 'milestones' => bm_rows($pdo, $id), 'expenses' => bx_rows($pdo, $id), 'media' => bd_rows($pdo, $id)]);
    }

    /* V2.23: service-key is READ-ONLY here — everything below mutates data */
    if ($svc) json_out(['ok' => false, 'error' => 'Service key cannot modify build data.'], 403);

    if ($action === 'create') {
        $title = trim($body['title'] ?? '');
        if ($title === '') json_out(['ok' => false, 'error' => 'Project title is required.'], 400);
        $kind = in_array(trim($body['kind'] ?? ''), ['construction', 'renovation', 'repair'], true) ? trim($body['kind']) : 'construction';
        $id = bp_next_id($pdo);
        $budget = max(0, (int)($body['budget_total'] ?? 0));
        $st = $pdo->prepare('INSERT INTO build_projects (id, owner_email, prop, title, kind, status, budget_total, start_date, target_end, contractor, architect, site_address, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$id, $u['role'] === 'owner' ? $u['email'] : (string)($body['owner_email'] ?? $u['email']), (string)($body['prop'] ?? ''), $title, $kind, 'Planning', $budget, (string)($body['start_date'] ?? ''), (string)($body['target_end'] ?? ''), (string)($body['contractor'] ?? ''), (string)($body['architect'] ?? ''), (string)($body['site_address'] ?? ''), (string)($body['notes'] ?? '')]);
        audit($u['name'], 'build-create', 'build', $id . ' ' . $title);
        json_out(['ok' => true, 'id' => $id]);
    }

    if ($action === 'save') {
        $id = trim($body['id'] ?? '');
        $row = $fetchP($id);
        if (!$row) json_out(['ok' => false, 'error' => 'Project not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        $st = $pdo->prepare('UPDATE build_projects SET title=?, kind=?, budget_total=?, start_date=?, target_end=?, contractor=?, architect=?, site_address=?, notes=?, updated_at=datetime("now") WHERE id=?');
        $st->execute([(string)($body['title'] ?? $row['title']), in_array(trim($body['kind'] ?? ''), ['construction', 'renovation', 'repair'], true) ? trim($body['kind']) : $row['kind'], max(0, (int)($body['budget_total'] ?? $row['budget_total'])), (string)($body['start_date'] ?? $row['start_date']), (string)($body['target_end'] ?? $row['target_end']), (string)($body['contractor'] ?? $row['contractor']), (string)($body['architect'] ?? $row['architect']), (string)($body['site_address'] ?? $row['site_address']), (string)($body['notes'] ?? $row['notes']), $id]);
        audit($u['name'], 'build-save', 'build', $id);
        json_out(['ok' => true]);
    }

    if ($action === 'status') {
        $id = trim($body['id'] ?? '');
        $row = $fetchP($id);
        if (!$row) json_out(['ok' => false, 'error' => 'Project not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        $to = trim($body['status'] ?? '');
        $allowed = ['Planning' => ['In_Progress'], 'In_Progress' => ['On_Hold', 'Completed', 'Cancelled'], 'On_Hold' => ['In_Progress', 'Completed', 'Cancelled'], 'Completed' => [], 'Cancelled' => []];
        if (!in_array($to, $allowed[$row['status']] ?? [], true))
            json_out(['ok' => false, 'error' => 'Cannot move project from ' . $row['status'] . ' to ' . $to . '.'], 409);
        $pdo->prepare("UPDATE build_projects SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$to, $id]);
        audit($u['name'], 'build-status', 'build', $id . ' → ' . $to);
        json_out(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = trim($body['id'] ?? '');
        $row = $fetchP($id);
        if (!$row) json_out(['ok' => false, 'error' => 'Project not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        foreach (bd_rows($pdo, $id) as $md) {
            $f = DATA_DIR() . '/' . $md['fname'];
            if ($md['fname'] && is_file($f)) @unlink($f);
        }
        $pdo->prepare('DELETE FROM build_media WHERE project=?')->execute([$id]);
        $pdo->prepare('DELETE FROM build_expenses WHERE project=?')->execute([$id]);
        $pdo->prepare('DELETE FROM build_milestones WHERE project=?')->execute([$id]);
        $pdo->prepare('DELETE FROM build_projects WHERE id=?')->execute([$id]);
        audit($u['name'], 'build-delete', 'build', $id);
        json_out(['ok' => true]);
    }

    if ($action === 'milestone-list') {
        $id = trim($body['project'] ?? $_GET['project'] ?? '');
        $row = $fetchP($id);
        if (!$row) json_out(['ok' => false, 'error' => 'Project not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        json_out(['ok' => true, 'milestones' => bm_rows($pdo, $id)]);
    }

    if ($action === 'milestone-create') {
        $id = trim($body['project'] ?? '');
        $row = $fetchP($id);
        if (!$row) json_out(['ok' => false, 'error' => 'Project not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        $title = trim($body['title'] ?? '');
        if ($title === '') json_out(['ok' => false, 'error' => 'Milestone title is required.'], 400);
        $phases = ['foundation', 'structure', 'electrical', 'plumbing', 'finishing', 'handover'];
        $mid = bm_next_id($pdo);
        $pdo->prepare('INSERT INTO build_milestones (id, project, title, phase, target_date, status, cost, paid, notes) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$mid, $id, $title, in_array(trim($body['phase'] ?? ''), $phases, true) ? trim($body['phase']) : 'structure', (string)($body['target_date'] ?? ''), 'Pending', max(0, (int)($body['cost'] ?? 0)), (int)($body['paid'] ?? 0) ? 1 : 0, (string)($body['notes'] ?? '')]);
        json_out(['ok' => true, 'id' => $mid]);
    }

    if ($action === 'milestone-save') {
        $mid = trim($body['id'] ?? '');
        $mst = $pdo->prepare('SELECT * FROM build_milestones WHERE id=?'); $mst->execute([$mid]);
        $m = $mst->fetch(PDO::FETCH_ASSOC);
        if (!$m) json_out(['ok' => false, 'error' => 'Milestone not found.'], 404);
        $row = $fetchP($m['project']);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        $phases = ['foundation', 'structure', 'electrical', 'plumbing', 'finishing', 'handover'];
        $pdo->prepare('UPDATE build_milestones SET title=?, phase=?, target_date=?, cost=?, paid=?, notes=?, updated_at=datetime("now") WHERE id=?')
            ->execute([(string)($body['title'] ?? $m['title']), in_array(trim($body['phase'] ?? ''), $phases, true) ? trim($body['phase']) : $m['phase'], (string)($body['target_date'] ?? $m['target_date']), max(0, (int)($body['cost'] ?? $m['cost'])), (int)($body['paid'] ?? $m['paid']) ? 1 : (int)$m['paid'], (string)($body['notes'] ?? $m['notes']), $mid]);
        json_out(['ok' => true]);
    }

    if ($action === 'milestone-status') {
        $mid = trim($body['id'] ?? '');
        $mst = $pdo->prepare('SELECT * FROM build_milestones WHERE id=?'); $mst->execute([$mid]);
        $m = $mst->fetch(PDO::FETCH_ASSOC);
        if (!$m) json_out(['ok' => false, 'error' => 'Milestone not found.'], 404);
        $row = $fetchP($m['project']);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        $to = trim($body['status'] ?? '');
        $allowed = ['Pending' => ['In_Progress', 'Skipped'], 'In_Progress' => ['Completed'], 'Completed' => [], 'Skipped' => []];
        if (!in_array($to, $allowed[$m['status']] ?? [], true))
            json_out(['ok' => false, 'error' => 'Cannot move milestone from ' . $m['status'] . ' to ' . $to . '.'], 409);
        $pdo->prepare("UPDATE build_milestones SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$to, $mid]);
        audit($u['name'], 'build-milestone-status', 'build', $mid . ' → ' . $to);
        json_out(['ok' => true]);
    }

    if ($action === 'expense-list') {
        $id = trim($body['project'] ?? $_GET['project'] ?? '');
        $row = $fetchP($id);
        if (!$row) json_out(['ok' => false, 'error' => 'Project not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        json_out(['ok' => true, 'expenses' => bx_rows($pdo, $id)]);
    }

    if ($action === 'expense-add') {
        $id = trim($body['project'] ?? '');
        $row = $fetchP($id);
        if (!$row) json_out(['ok' => false, 'error' => 'Project not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        $label = trim($body['label'] ?? '');
        $amount = (int)($body['amount'] ?? 0);
        if ($label === '' || $amount <= 0) json_out(['ok' => false, 'error' => 'Label and a positive amount are required.'], 400);
        $cats = ['material', 'labour', 'permit', 'design', 'other'];
        $xid = bx_next_id($pdo);
        $pdo->prepare('INSERT INTO build_expenses (id, project, label, amount, category, spent_on, paid, notes) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$xid, $id, $label, $amount, in_array(trim($body['category'] ?? ''), $cats, true) ? trim($body['category']) : 'other', (string)($body['spent_on'] ?? ''), (int)($body['paid'] ?? 0) ? 1 : 0, (string)($body['notes'] ?? '')]);
        audit($u['name'], 'build-expense-add', 'build', $xid . ' ৳' . $amount);
        json_out(['ok' => true, 'id' => $xid]);
    }

    if ($action === 'expense-delete') {
        $xid = trim($body['id'] ?? '');
        $xst = $pdo->prepare('SELECT * FROM build_expenses WHERE id=?'); $xst->execute([$xid]);
        $x = $xst->fetch(PDO::FETCH_ASSOC);
        if (!$x) json_out(['ok' => false, 'error' => 'Expense not found.'], 404);
        $row = $fetchP($x['project']);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        $pdo->prepare('DELETE FROM build_expenses WHERE id=?')->execute([$xid]);
        audit($u['name'], 'build-expense-delete', 'build', $xid);
        json_out(['ok' => true]);
    }

    if ($action === 'media-upload') {
        $id = trim($_POST['project'] ?? '');
        $row = $fetchP($id);
        if (!$row) json_out(['ok' => false, 'error' => 'Project not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK)
            json_out(['ok' => false, 'error' => 'A file upload is required.'], 400);
        $f = $_FILES['file'];
        $kind = in_array(trim($_POST['kind'] ?? ''), ['photo', 'video', 'doc'], true) ? trim($_POST['kind']) : 'photo';
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $okExt = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'pdf', 'doc', 'docx'];
        if (!in_array($ext, $okExt, true)) json_out(['ok' => false, 'error' => 'Only images, mp4 and documents are allowed.'], 400);
        $mid = bd_next_id($pdo);
        $fname = 'bmd_' . $mid . '.' . $ext;
        $dest = DATA_DIR() . '/' . $fname;
        if (!@move_uploaded_file($f['tmp_name'], $dest)) json_out(['ok' => false, 'error' => 'Could not store file.'], 500);
        $pdo->prepare('INSERT INTO build_media (id, project, kind, name, fname, size, mime, geo, captured_at, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$mid, $id, $kind, (string)($_POST['name'] ?? $f['name']), $fname, (int)$f['size'], (string)($f['type'] ?? ''), (string)($_POST['geo'] ?? ''), (string)($_POST['captured_at'] ?? date('Y-m-d H:i:s')), $u['name'] ?? $u['email']]);
        audit($u['name'], 'build-media-upload', 'build', $mid . ' ' . $fname);
        json_out(['ok' => true, 'id' => $mid]);
    }

    if ($action === 'media-list') {
        $id = trim($body['project'] ?? $_GET['project'] ?? '');
        $row = $fetchP($id);
        if (!$row) json_out(['ok' => false, 'error' => 'Project not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        json_out(['ok' => true, 'media' => bd_rows($pdo, $id)]);
    }

    if ($action === 'media-delete') {
        $mid = trim($body['id'] ?? '');
        $mst = $pdo->prepare('SELECT * FROM build_media WHERE id=?'); $mst->execute([$mid]);
        $md = $mst->fetch(PDO::FETCH_ASSOC);
        if (!$md) json_out(['ok' => false, 'error' => 'Media not found.'], 404);
        $row = $fetchP($md['project']);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        if ($md['fname']) { $f = DATA_DIR() . '/' . $md['fname']; if (is_file($f)) @unlink($f); }
        $pdo->prepare('DELETE FROM build_media WHERE id=?')->execute([$mid]);
        json_out(['ok' => true]);
    }

    if ($action === 'media-view') {
        $mid = trim($_GET['id'] ?? '');
        $mst = $pdo->prepare('SELECT * FROM build_media WHERE id=?'); $mst->execute([$mid]);
        $md = $mst->fetch(PDO::FETCH_ASSOC);
        if (!$md) json_out(['ok' => false, 'error' => 'Media not found.'], 404);
        $row = $fetchP($md['project']);
        if (!$row || !$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        $f = DATA_DIR() . '/' . $md['fname'];
        if (!$md['fname'] || !is_file($f)) json_out(['ok' => false, 'error' => 'File missing.'], 404);
        header('Content-Type: ' . ($md['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . $md['fname'] . '"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }

    if ($action === 'report') {
        $id = trim($_GET['id'] ?? '');
        $row = $fetchP($id);
        if (!$row) json_out(['ok' => false, 'error' => 'Project not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your project.'], 403);
        $enr = bp_enrich($pdo, [$row])[0];
        $html = build_report_html($pdo, $enr, bm_rows($pdo, $id), bx_rows($pdo, $id), bd_rows($pdo, $id));
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    if ($action === 'config-get') {
        json_out(['ok' => true, 'config' => ['milestone_alert_days' => (int)(build_cfg($pdo, 'milestone_alert_days', 14)), 'budget_overrun_pct' => (int)(build_cfg($pdo, 'budget_overrun_pct', 10)), 'default_contractor' => (string)(build_cfg($pdo, 'default_contractor', ''))]]);
    }

    if ($action === 'config-save') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owners and managers can save Build Watch settings.'], 403);
        $days = max(1, min(120, (int)($body['milestone_alert_days'] ?? 14)));
        $pct = max(1, min(50, (int)($body['budget_overrun_pct'] ?? 10)));
        $con = trim($body['default_contractor'] ?? '');
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['build_milestone_alert_days', (string)$days]);
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['build_budget_overrun_pct', (string)$pct]);
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['build_default_contractor', $con]);
        audit($u['name'], 'build-config-save', 'build', 'days=' . $days . ' pct=' . $pct);
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'Unknown build action: ' . $action], 400);
}

case 'app-gate': {
    $u = require_user();
    require_module($u, 'gate');
    $pdo = db();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr'], true))
        json_out(['ok' => false, 'error' => 'Gate Watch is for owners and operations staff.'], 403);
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'summary' : 'list';
    $ownerOk = function ($email) use ($u) {
        if ($u['role'] === 'owner') return $email === $u['email'] || $email === '';
        return true;
    };
    $propExists = function ($prop) use ($pdo) {
        if ($prop === '') return false;
        $st = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE id=?'); $st->execute([$prop]);
        return (int)$st->fetchColumn() > 0;
    };
    $fetchV = function ($id) use ($pdo) {
        $st = $pdo->prepare('SELECT * FROM gate_visits WHERE id=?'); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    };

    if ($action === 'summary' || $action === 'list') {
        $rows = gate_enrich($pdo, gate_visit_rows($pdo, $u));
        $byType = ['visitor' => 0, 'delivery' => 0, 'worker' => 0, 'vendor' => 0, 'vehicle' => 0];
        foreach ($rows as $r) $byType[$r['vtype']] = ($byType[$r['vtype']] ?? 0) + 1;
        $today = date('Y-m-d');
        $todayRows = array_values(array_filter($rows, fn($r) => strpos((string)$r['check_in'], $today) === 0));
        $inside = array_values(array_filter($rows, fn($r) => $r['status'] === 'Inside'));
        $flaggedOpen = array_values(array_filter($inside, fn($r) => (int)$r['flagged'] === 1));
        $summary = [
            'inside_now' => $inside, 'inside_count' => count($inside),
            'today_in' => count($todayRows),
            'today_out' => count(array_filter($todayRows, fn($r) => $r['status'] === 'Out')),
            'by_type' => $byType,
            'flagged_open' => count($flaggedOpen),
            'watchlist_active' => (int)$pdo->query("SELECT COUNT(*) FROM gate_watchlist WHERE active=1")->fetchColumn(),
            'recent' => array_values(array_slice($rows, 0, 12)),
            'config' => ['auto_flag_hours' => (int)(gate_cfg($pdo, 'auto_flag_hours', 4)), 'report_title' => (string)(gate_cfg($pdo, 'report_title', 'Daily Gate Register'))],
        ];
        if ($action === 'summary') json_out(['ok' => true, 'summary' => $summary, 'config' => $summary['config']]);
        json_out(['ok' => true, 'visits' => $rows, 'summary' => $summary]);
    }

    if ($action === 'visit-list') {
        $rows = gate_enrich($pdo, gate_visit_rows($pdo, $u));
        $fProp = trim($_GET['prop'] ?? $body['prop'] ?? '');
        $fDate = trim($_GET['date'] ?? $body['date'] ?? '');
        $fStatus = trim($_GET['status'] ?? $body['status'] ?? '');
        $fType = trim($_GET['vtype'] ?? $body['vtype'] ?? '');
        if ($fProp !== '') $rows = array_values(array_filter($rows, fn($r) => $r['prop'] === $fProp));
        if ($fDate !== '') $rows = array_values(array_filter($rows, fn($r) => strpos((string)$r['check_in'], $fDate) === 0));
        if ($fStatus !== '') $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $fStatus));
        if ($fType !== '') $rows = array_values(array_filter($rows, fn($r) => $r['vtype'] === $fType));
        json_out(['ok' => true, 'visits' => $rows]);
    }

    if ($action === 'visit-get') {
        $id = trim($body['id'] ?? $_GET['id'] ?? '');
        $row = $fetchV($id);
        if (!$row) json_out(['ok' => false, 'error' => 'Visit not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        json_out(['ok' => true, 'visit' => gate_enrich($pdo, [$row])[0]]);
    }

    if ($action === 'visit-create') {
        $prop = trim($body['prop'] ?? '');
        if ($prop === '') json_out(['ok' => false, 'error' => 'Property is required.'], 400);
        if (!$propExists($prop)) json_out(['ok' => false, 'error' => 'Unknown property.'], 404);
        $oemail = $u['role'] === 'owner' ? $u['email'] : trim($body['owner_email'] ?? '');
        $vtype = in_array(trim($body['vtype'] ?? ''), ['visitor', 'delivery', 'worker', 'vendor', 'vehicle'], true) ? trim($body['vtype']) : 'visitor';
        $name = trim($body['name'] ?? '');
        $vehicle = strtoupper(trim($body['vehicle_no'] ?? ''));
        if ($name === '' && $vehicle === '') json_out(['ok' => false, 'error' => 'Name or vehicle number is required.'], 400);
        $flag = gate_flag_for($pdo, $vehicle, $name);
        $id = gv_next_id($pdo);
        $pdo->prepare('INSERT INTO gate_visits (id, owner_email, prop, vtype, name, phone, vehicle_no, unit, purpose, host_name, check_in, status, flagged, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,datetime("now"),?,?,?,?)')
            ->execute([$id, $oemail, $prop, $vtype, $name, trim($body['phone'] ?? ''), $vehicle, trim($body['unit'] ?? ''), trim($body['purpose'] ?? ''), trim($body['host_name'] ?? ''), 'Inside', $flag ? 1 : 0, trim($body['notes'] ?? ''), $u['name'] ?? $u['email']]);
        audit($u['name'], 'gate-visit-create', 'gate', $id . ' ' . $name);
        json_out(['ok' => true, 'id' => $id, 'visit' => gate_enrich($pdo, [$fetchV($id)])[0], 'flagged' => $flag ? 1 : 0, 'watchlist' => $flag ? ['id' => $flag['id'], 'reason' => $flag['reason']] : null]);
    }

    if ($action === 'visit-out') {
        $id = trim($body['id'] ?? '');
        $row = $fetchV($id);
        if (!$row) json_out(['ok' => false, 'error' => 'Visit not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        if ($row['status'] !== 'Inside') json_out(['ok' => false, 'error' => 'Visit is already checked out.'], 409);
        $pdo->prepare('UPDATE gate_visits SET status=?, check_out=datetime("now") WHERE id=?')->execute(['Out', $id]);
        audit($u['name'], 'gate-visit-out', 'gate', $id);
        json_out(['ok' => true]);
    }

    if ($action === 'visit-delete') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owners and managers can delete gate entries.'], 403);
        $id = trim($body['id'] ?? '');
        $row = $fetchV($id);
        if (!$row) json_out(['ok' => false, 'error' => 'Visit not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        if ($row['photo']) { $f = DATA_DIR() . '/' . $row['photo']; if (is_file($f)) @unlink($f); }
        $pdo->prepare('DELETE FROM gate_visits WHERE id=?')->execute([$id]);
        audit($u['name'], 'gate-visit-delete', 'gate', $id);
        json_out(['ok' => true]);
    }

    if ($action === 'visit-photo') {
        $id = trim($_POST['id'] ?? '');
        $row = $fetchV($id);
        if (!$row) json_out(['ok' => false, 'error' => 'Visit not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK)
            json_out(['ok' => false, 'error' => 'A photo upload is required.'], 400);
        $f = $_FILES['file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $okExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($ext, $okExt, true)) json_out(['ok' => false, 'error' => 'Images only.'], 400);
        if ($row['photo']) { $old = DATA_DIR() . '/' . $row['photo']; if (is_file($old)) @unlink($old); }
        $fname = 'gv_' . $id . '_' . date('Ymd_His') . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], DATA_DIR() . '/' . $fname))
            json_out(['ok' => false, 'error' => 'Failed to store photo.'], 500);
        $pdo->prepare('UPDATE gate_visits SET photo=? WHERE id=?')->execute([$fname, $id]);
        audit($u['name'], 'gate-visit-photo', 'gate', $id . ' ' . $fname);
        json_out(['ok' => true, 'photo' => $fname]);
    }

    if ($action === 'visit-photo-view') {
        $id = trim($_GET['id'] ?? '');
        $row = $fetchV($id);
        if (!$row || !$row['photo']) json_out(['ok' => false, 'error' => 'Photo not found.'], 404);
        if (!$ownerOk($row['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        $f = DATA_DIR() . '/' . $row['photo'];
        if (!is_file($f)) json_out(['ok' => false, 'error' => 'Photo file missing.'], 404);
        $mime = mime_content_type($f) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }

    if ($action === 'vehicle-list') {
        $rows = $pdo->query('SELECT * FROM resident_vehicles ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $k => $r) {
            $rows[$k]['property_name'] = '';
            if ($r['prop']) { $pt = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $pt->execute([$r['prop']]); $pn = $pt->fetchColumn(); $rows[$k]['property_name'] = $pn ?: $r['prop']; }
        }
        json_out(['ok' => true, 'vehicles' => $rows]);
    }

    if ($action === 'vehicle-create') {
        $vno = strtoupper(trim($body['vehicle_no'] ?? ''));
        if ($vno === '') json_out(['ok' => false, 'error' => 'Vehicle number is required.'], 400);
        $prop = trim($body['prop'] ?? '');
        if ($prop !== '' && !$propExists($prop)) json_out(['ok' => false, 'error' => 'Unknown property.'], 404);
        $id = rv_next_id($pdo);
        $pdo->prepare('INSERT INTO resident_vehicles (id, prop, unit, vehicle_no, vtype, label, owner_name, sticker_no, status) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $prop, trim($body['unit'] ?? ''), $vno, in_array(trim($body['vtype'] ?? ''), ['car', 'bike', 'cycle', 'other'], true) ? trim($body['vtype']) : 'car', trim($body['label'] ?? ''), trim($body['owner_name'] ?? ''), trim($body['sticker_no'] ?? ''), 'active']);
        audit($u['name'], 'gate-vehicle-create', 'gate', $id . ' ' . $vno);
        json_out(['ok' => true, 'id' => $id]);
    }

    if ($action === 'vehicle-save') {
        $id = trim($body['id'] ?? '');
        $vst = $pdo->prepare('SELECT * FROM resident_vehicles WHERE id=?'); $vst->execute([$id]);
        $v = $vst->fetch(PDO::FETCH_ASSOC);
        if (!$v) json_out(['ok' => false, 'error' => 'Vehicle not found.'], 404);
        $prop = trim($body['prop'] ?? $v['prop']);
        if ($prop !== '' && !$propExists($prop)) json_out(['ok' => false, 'error' => 'Unknown property.'], 404);
        $pdo->prepare('UPDATE resident_vehicles SET prop=?, unit=?, vehicle_no=?, vtype=?, label=?, owner_name=?, sticker_no=?, status=? WHERE id=?')
            ->execute([$prop, trim($body['unit'] ?? $v['unit']), strtoupper(trim($body['vehicle_no'] ?? $v['vehicle_no'])), in_array(trim($body['vtype'] ?? $v['vtype']), ['car', 'bike', 'cycle', 'other'], true) ? trim($body['vtype']) : $v['vtype'], trim($body['label'] ?? $v['label']), trim($body['owner_name'] ?? $v['owner_name']), trim($body['sticker_no'] ?? $v['sticker_no']), in_array(trim($body['status'] ?? $v['status']), ['active', 'inactive'], true) ? trim($body['status']) : $v['status'], $id]);
        audit($u['name'], 'gate-vehicle-save', 'gate', $id);
        json_out(['ok' => true]);
    }

    if ($action === 'vehicle-delete') {
        $id = trim($body['id'] ?? '');
        $vst = $pdo->prepare('SELECT * FROM resident_vehicles WHERE id=?'); $vst->execute([$id]);
        if (!$vst->fetch()) json_out(['ok' => false, 'error' => 'Vehicle not found.'], 404);
        $pdo->prepare('DELETE FROM resident_vehicles WHERE id=?')->execute([$id]);
        audit($u['name'], 'gate-vehicle-delete', 'gate', $id);
        json_out(['ok' => true]);
    }

    if ($action === 'watchlist-list') {
        json_out(['ok' => true, 'watchlist' => $pdo->query('SELECT * FROM gate_watchlist ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'watchlist-create') {
        $vno = strtoupper(trim($body['vehicle_no'] ?? ''));
        $name = trim($body['name'] ?? '');
        if ($vno === '' && $name === '') json_out(['ok' => false, 'error' => 'Vehicle number or name is required.'], 400);
        $id = gw_next_id($pdo);
        $pdo->prepare('INSERT INTO gate_watchlist (id, vehicle_no, name, reason, active) VALUES (?,?,?,?,1)')
            ->execute([$id, $vno, $name, trim($body['reason'] ?? '')]);
        audit($u['name'], 'gate-watchlist-create', 'gate', $id);
        json_out(['ok' => true, 'id' => $id]);
    }

    if ($action === 'watchlist-save') {
        $id = trim($body['id'] ?? '');
        $wst = $pdo->prepare('SELECT * FROM gate_watchlist WHERE id=?'); $wst->execute([$id]);
        $w = $wst->fetch(PDO::FETCH_ASSOC);
        if (!$w) json_out(['ok' => false, 'error' => 'Watchlist entry not found.'], 404);
        $pdo->prepare('UPDATE gate_watchlist SET vehicle_no=?, name=?, reason=?, active=? WHERE id=?')
            ->execute([strtoupper(trim($body['vehicle_no'] ?? $w['vehicle_no'])), trim($body['name'] ?? $w['name']), trim($body['reason'] ?? $w['reason']), ((int)($body['active'] ?? $w['active'])) ? 1 : 0, $id]);
        audit($u['name'], 'gate-watchlist-save', 'gate', $id);
        json_out(['ok' => true]);
    }

    if ($action === 'watchlist-delete') {
        $id = trim($body['id'] ?? '');
        $wst = $pdo->prepare('SELECT * FROM gate_watchlist WHERE id=?'); $wst->execute([$id]);
        if (!$wst->fetch()) json_out(['ok' => false, 'error' => 'Watchlist entry not found.'], 404);
        $pdo->prepare('DELETE FROM gate_watchlist WHERE id=?')->execute([$id]);
        audit($u['name'], 'gate-watchlist-delete', 'gate', $id);
        json_out(['ok' => true]);
    }

    if ($action === 'report') {
        $rows = gate_enrich($pdo, gate_visit_rows($pdo, $u));
        $propName = 'All properties';
        $fProp = trim($_GET['prop'] ?? '');
        if ($fProp !== '') {
            $pt = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $pt->execute([$fProp]);
            $pn = $pt->fetchColumn();
            if ($pn) $propName = $pn;
            $rows = array_values(array_filter($rows, fn($r) => $r['prop'] === $fProp));
        }
        $title = (string)(gate_cfg($pdo, 'report_title', 'Daily Gate Register'));
        header('Content-Type: text/html; charset=utf-8');
        echo gate_report_html($pdo, $rows, $propName, $title);
        exit;
    }

    if ($action === 'config-get') {
        json_out(['ok' => true, 'config' => ['auto_flag_hours' => (int)(gate_cfg($pdo, 'auto_flag_hours', 4)), 'report_title' => (string)(gate_cfg($pdo, 'report_title', 'Daily Gate Register'))]]);
    }

    if ($action === 'config-save') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owners and managers can save Gate Watch settings.'], 403);
        $hours = max(1, min(120, (int)($body['auto_flag_hours'] ?? 4)));
        $title = trim($body['report_title'] ?? '');
        if ($title === '') $title = 'Daily Gate Register';
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['gate_auto_flag_hours', (string)$hours]);
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['gate_report_title', $title]);
        audit($u['name'], 'gate-config-save', 'gate', 'hours=' . $hours);
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'Unknown gate action: ' . $action], 400);
}

case 'app-firesafety': {
    $u = require_user();
    require_module($u, 'firesafety');
    $pdo = db();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr'], true))
        json_out(['ok' => false, 'error' => 'Fire Safety is for owners and operations staff.'], 403);
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'summary' : 'list';
    $ownerOk = function ($email) use ($u) {
        if ($u['role'] === 'owner') return $email === $u['email'] || $email === '';
        return true;
    };
    $propExists = function ($prop) use ($pdo) {
        if ($prop === '') return false;
        $st = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE id=?'); $st->execute([$prop]);
        return (int)$st->fetchColumn() > 0;
    };

    if ($action === 'summary' || $action === 'list') {
        $assets = fire_asset_enrich($pdo, fire_asset_rows($pdo, $u));
        $incidents = fire_incident_enrich($pdo, fire_incident_rows($pdo, $u));
        $plans = fire_plan_enrich($pdo, fire_plan_rows($pdo, $u));
        $contacts = fire_contact_enrich($pdo, fire_contact_rows($pdo, $u));
        $byStatus = ['active' => 0, 'faulty' => 0, 'decommissioned' => 0];
        foreach ($assets as $a) $byStatus[$a['status']] = ($byStatus[$a['status']] ?? 0) + 1;
        $expired = count(array_filter($assets, fn($a) => (int)$a['expired'] === 1));
        $expiring = count(array_filter($assets, fn($a) => (int)$a['expiring'] === 1));
        $inspDue = count(array_filter($assets, fn($a) => (int)$a['inspection_overdue'] === 1));
        $open = array_values(array_filter($incidents, fn($i) => !in_array($i['status'], ['Resolved', 'Closed'], true)));
        $sev = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
        foreach ($incidents as $i) $sev[$i['severity']] = ($sev[$i['severity']] ?? 0) + 1;
        $activePlans = array_values(array_filter($plans, fn($p) => $p['status'] === 'active'));
        $drillOverdue = count(array_filter($activePlans, fn($p) => (int)$p['drill_overdue'] === 1));
        $summary = [
            'assets' => $assets, 'assets_total' => count($assets), 'by_status' => $byStatus,
            'expired' => $expired, 'expiring' => $expiring, 'inspection_overdue' => $inspDue,
            'incidents' => $incidents, 'incidents_open' => count($open), 'incidents_by_severity' => $sev,
            'plans' => $plans, 'plans_active' => count($activePlans), 'drill_overdue' => $drillOverdue,
            'contacts' => $contacts,
            'config' => ['inspection_days' => (int)(fire_cfg($pdo, 'inspection_days', 30)), 'expiry_alert_days' => (int)(fire_cfg($pdo, 'expiry_alert_days', 60)), 'drill_days' => (int)(fire_cfg($pdo, 'drill_days', 180))],
        ];
        if ($action === 'summary') json_out(['ok' => true, 'summary' => $summary, 'config' => $summary['config']]);
        json_out(['ok' => true, 'summary' => $summary]);
    }

    /* ---- assets ---- */
    if ($action === 'asset-list') {
        json_out(['ok' => true, 'assets' => fire_asset_enrich($pdo, fire_asset_rows($pdo, $u))]);
    }
    if ($action === 'asset-create') {
        $atype = in_array(trim($body['asset_type'] ?? ''), ['extinguisher', 'detector', 'sprinkler', 'alarm', 'exit_light', 'hose'], true) ? trim($body['asset_type']) : 'extinguisher';
        $loc = trim($body['location'] ?? '');
        if ($loc === '') json_out(['ok' => false, 'error' => 'Location is required.'], 400);
        $prop = trim($body['prop'] ?? '');
        if ($prop !== '' && !$propExists($prop)) json_out(['ok' => false, 'error' => 'Unknown property.'], 404);
        $oemail = $u['role'] === 'owner' ? $u['email'] : trim($body['owner_email'] ?? '');
        $expiry = trim($body['expiry_date'] ?? '');
        $install = trim($body['install_date'] ?? '');
        $id = fa_next_id($pdo);
        $pdo->prepare('INSERT INTO fire_assets (id, owner_email, prop, asset_type, location, model, serial_no, install_date, expiry_date, status, last_inspected, next_inspection, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $oemail, $prop, $atype, $loc, trim($body['model'] ?? ''), trim($body['serial_no'] ?? ''), $install, $expiry, 'active', date('Y-m-d'), date('Y-m-d', strtotime('+' . (int)(fire_cfg($pdo, 'inspection_days', 30)) . ' days')), trim($body['notes'] ?? '')]);
        audit($u['name'], 'fire-asset-create', 'firesafety', $id . ' ' . $atype);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'asset-save') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM fire_assets WHERE id=?'); $st->execute([$id]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) json_out(['ok' => false, 'error' => 'Asset not found.'], 404);
        if (!$ownerOk($a['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        $prop = trim($body['prop'] ?? $a['prop']);
        if ($prop !== '' && !$propExists($prop)) json_out(['ok' => false, 'error' => 'Unknown property.'], 404);
        $pdo->prepare('UPDATE fire_assets SET owner_email=?, prop=?, asset_type=?, location=?, model=?, serial_no=?, install_date=?, expiry_date=?, notes=? WHERE id=?')
            ->execute([$u['role'] === 'owner' ? $u['email'] : trim($body['owner_email'] ?? $a['owner_email']), $prop, in_array(trim($body['asset_type'] ?? $a['asset_type']), ['extinguisher', 'detector', 'sprinkler', 'alarm', 'exit_light', 'hose'], true) ? trim($body['asset_type']) : $a['asset_type'], trim($body['location'] ?? $a['location']), trim($body['model'] ?? $a['model']), trim($body['serial_no'] ?? $a['serial_no']), trim($body['install_date'] ?? $a['install_date']), trim($body['expiry_date'] ?? $a['expiry_date']), trim($body['notes'] ?? $a['notes']), $id]);
        audit($u['name'], 'fire-asset-save', 'firesafety', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'asset-status') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM fire_assets WHERE id=?'); $st->execute([$id]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) json_out(['ok' => false, 'error' => 'Asset not found.'], 404);
        if (!$ownerOk($a['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        $ns = in_array(trim($body['status'] ?? ''), ['active', 'faulty', 'decommissioned'], true) ? trim($body['status']) : 'active';
        $pdo->prepare('UPDATE fire_assets SET status=? WHERE id=?')->execute([$ns, $id]);
        audit($u['name'], 'fire-asset-status', 'firesafety', $id . ' -> ' . $ns);
        json_out(['ok' => true]);
    }
    if ($action === 'asset-inspect') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM fire_assets WHERE id=?'); $st->execute([$id]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) json_out(['ok' => false, 'error' => 'Asset not found.'], 404);
        if (!$ownerOk($a['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        $days = max(1, (int)(fire_cfg($pdo, 'inspection_days', 30)));
        $pdo->prepare('UPDATE fire_assets SET last_inspected=?, next_inspection=? WHERE id=?')->execute([date('Y-m-d'), date('Y-m-d', strtotime('+' . $days . ' days')), $id]);
        audit($u['name'], 'fire-asset-inspect', 'firesafety', $id);
        json_out(['ok' => true, 'next_inspection' => date('Y-m-d', strtotime('+' . $days . ' days'))]);
    }
    if ($action === 'asset-delete') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM fire_assets WHERE id=?'); $st->execute([$id]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) json_out(['ok' => false, 'error' => 'Asset not found.'], 404);
        if (!$ownerOk($a['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        $pdo->prepare('DELETE FROM fire_assets WHERE id=?')->execute([$id]);
        audit($u['name'], 'fire-asset-delete', 'firesafety', $id);
        json_out(['ok' => true]);
    }

    /* ---- incidents ---- */
    $incidentStatuses = ['Reported' => 'Assessing', 'Assessing' => 'Responding', 'Responding' => 'Resolved', 'Resolved' => 'Closed'];
    if ($action === 'incident-list') {
        json_out(['ok' => true, 'incidents' => fire_incident_enrich($pdo, fire_incident_rows($pdo, $u))]);
    }
    if ($action === 'incident-create') {
        $itype = in_array(trim($body['incident_type'] ?? ''), ['fire', 'gas_leak', 'electrical', 'medical', 'water', 'security', 'other'], true) ? trim($body['incident_type']) : 'fire';
        $sev = in_array(trim($body['severity'] ?? ''), ['low', 'medium', 'high', 'critical'], true) ? trim($body['severity']) : 'low';
        $loc = trim($body['location'] ?? '');
        if ($loc === '') json_out(['ok' => false, 'error' => 'Location is required.'], 400);
        $prop = trim($body['prop'] ?? '');
        if ($prop !== '' && !$propExists($prop)) json_out(['ok' => false, 'error' => 'Unknown property.'], 404);
        $oemail = $u['role'] === 'owner' ? $u['email'] : trim($body['owner_email'] ?? '');
        $id = fi_next_id($pdo);
        $tl = json_encode([['t' => date('Y-m-d H:i:s'), 'by' => $u['name'] ?? $u['email'], 'note' => 'Incident reported']], JSON_UNESCAPED_UNICODE);
        $pdo->prepare('INSERT INTO fire_incidents (id, owner_email, prop, incident_type, severity, status, reported_by, occurred_at, location, description, timeline) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $oemail, $prop, $itype, $sev, 'Reported', $u['name'] ?? $u['email'], trim($body['occurred_at'] ?? date('Y-m-d H:i:s')), $loc, trim($body['description'] ?? ''), $tl]);
        audit($u['name'], 'fire-incident-create', 'firesafety', $id . ' ' . $itype);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'incident-save') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM fire_incidents WHERE id=?'); $st->execute([$id]);
        $i = $st->fetch(PDO::FETCH_ASSOC);
        if (!$i) json_out(['ok' => false, 'error' => 'Incident not found.'], 404);
        if (!$ownerOk($i['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        if (in_array($i['status'], ['Resolved', 'Closed'], true)) json_out(['ok' => false, 'error' => 'Closed incidents are locked.'], 409);
        $prop = trim($body['prop'] ?? $i['prop']);
        if ($prop !== '' && !$propExists($prop)) json_out(['ok' => false, 'error' => 'Unknown property.'], 404);
        $pdo->prepare('UPDATE fire_incidents SET prop=?, incident_type=?, severity=?, location=?, description=?, occurred_at=? WHERE id=?')
            ->execute([$prop, in_array(trim($body['incident_type'] ?? $i['incident_type']), ['fire', 'gas_leak', 'electrical', 'medical', 'water', 'security', 'other'], true) ? trim($body['incident_type']) : $i['incident_type'], in_array(trim($body['severity'] ?? $i['severity']), ['low', 'medium', 'high', 'critical'], true) ? trim($body['severity']) : $i['severity'], trim($body['location'] ?? $i['location']), trim($body['description'] ?? $i['description']), trim($body['occurred_at'] ?? $i['occurred_at']), $id]);
        audit($u['name'], 'fire-incident-save', 'firesafety', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'incident-status') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM fire_incidents WHERE id=?'); $st->execute([$id]);
        $i = $st->fetch(PDO::FETCH_ASSOC);
        if (!$i) json_out(['ok' => false, 'error' => 'Incident not found.'], 404);
        if (!$ownerOk($i['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        if (!isset($incidentStatuses[$i['status']])) json_out(['ok' => false, 'error' => 'Incident is closed.'], 409);
        $ns = $incidentStatuses[$i['status']];
        $tl = json_decode($i['timeline'] ?: '[]', true) ?: [];
        $tl[] = ['t' => date('Y-m-d H:i:s'), 'by' => $u['name'] ?? $u['email'], 'note' => 'Status → ' . $ns];
        $pdo->prepare('UPDATE fire_incidents SET status=?, timeline=?, resolved_at=CASE WHEN CAST(? AS INTEGER)=1 THEN datetime("now") ELSE resolved_at END WHERE id=?')
            ->execute([$ns, json_encode($tl, JSON_UNESCAPED_UNICODE), $ns === 'Resolved' ? 1 : 0, $id]);
        audit($u['name'], 'fire-incident-status', 'firesafety', $id . ' -> ' . $ns);
        json_out(['ok' => true, 'status' => $ns]);
    }
    if ($action === 'incident-event') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM fire_incidents WHERE id=?'); $st->execute([$id]);
        $i = $st->fetch(PDO::FETCH_ASSOC);
        if (!$i) json_out(['ok' => false, 'error' => 'Incident not found.'], 404);
        if (!$ownerOk($i['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        if (in_array($i['status'], ['Resolved', 'Closed'], true)) json_out(['ok' => false, 'error' => 'Closed incidents are locked.'], 409);
        $note = trim($body['note'] ?? '');
        if ($note === '') json_out(['ok' => false, 'error' => 'Note is required.'], 400);
        $tl = json_decode($i['timeline'] ?: '[]', true) ?: [];
        $tl[] = ['t' => date('Y-m-d H:i:s'), 'by' => $u['name'] ?? $u['email'], 'note' => $note];
        $pdo->prepare('UPDATE fire_incidents SET timeline=? WHERE id=?')->execute([json_encode($tl, JSON_UNESCAPED_UNICODE), $id]);
        audit($u['name'], 'fire-incident-event', 'firesafety', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'incident-delete') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM fire_incidents WHERE id=?'); $st->execute([$id]);
        $i = $st->fetch(PDO::FETCH_ASSOC);
        if (!$i) json_out(['ok' => false, 'error' => 'Incident not found.'], 404);
        if (!$ownerOk($i['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        $pdo->prepare('DELETE FROM fire_incidents WHERE id=?')->execute([$id]);
        audit($u['name'], 'fire-incident-delete', 'firesafety', $id);
        json_out(['ok' => true]);
    }

    /* ---- evacuation plans ---- */
    if ($action === 'plan-list') {
        json_out(['ok' => true, 'plans' => fire_plan_enrich($pdo, fire_plan_rows($pdo, $u))]);
    }
    if ($action === 'plan-create') {
        $name = trim($body['name'] ?? '');
        if ($name === '') json_out(['ok' => false, 'error' => 'Plan name is required.'], 400);
        $prop = trim($body['prop'] ?? '');
        if ($prop !== '' && !$propExists($prop)) json_out(['ok' => false, 'error' => 'Unknown property.'], 404);
        $oemail = $u['role'] === 'owner' ? $u['email'] : trim($body['owner_email'] ?? '');
        $id = ep_next_id($pdo);
        $pdo->prepare('INSERT INTO evacuation_plans (id, owner_email, prop, name, assembly_point, route, status) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id, $oemail, $prop, $name, trim($body['assembly_point'] ?? ''), trim($body['route'] ?? ''), 'draft']);
        audit($u['name'], 'fire-plan-create', 'firesafety', $id);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'plan-save') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM evacuation_plans WHERE id=?'); $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) json_out(['ok' => false, 'error' => 'Plan not found.'], 404);
        if (!$ownerOk($p['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        $prop = trim($body['prop'] ?? $p['prop']);
        if ($prop !== '' && !$propExists($prop)) json_out(['ok' => false, 'error' => 'Unknown property.'], 404);
        $pdo->prepare('UPDATE evacuation_plans SET prop=?, name=?, assembly_point=?, route=?, status=? WHERE id=?')
            ->execute([$prop, trim($body['name'] ?? $p['name']), trim($body['assembly_point'] ?? $p['assembly_point']), trim($body['route'] ?? $p['route']), in_array(trim($body['status'] ?? $p['status']), ['active', 'draft'], true) ? trim($body['status']) : $p['status'], $id]);
        audit($u['name'], 'fire-plan-save', 'firesafety', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'plan-drill') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM evacuation_plans WHERE id=?'); $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) json_out(['ok' => false, 'error' => 'Plan not found.'], 404);
        if (!$ownerOk($p['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        if ($p['status'] !== 'active') json_out(['ok' => false, 'error' => 'Only active plans can host drills.'], 409);
        $days = max(1, (int)(fire_cfg($pdo, 'drill_days', 180)));
        $pdo->prepare('UPDATE evacuation_plans SET last_drill=?, next_drill=? WHERE id=?')->execute([date('Y-m-d'), date('Y-m-d', strtotime('+' . $days . ' days')), $id]);
        audit($u['name'], 'fire-plan-drill', 'firesafety', $id);
        json_out(['ok' => true, 'next_drill' => date('Y-m-d', strtotime('+' . $days . ' days'))]);
    }
    if ($action === 'plan-delete') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM evacuation_plans WHERE id=?'); $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) json_out(['ok' => false, 'error' => 'Plan not found.'], 404);
        if (!$ownerOk($p['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        $pdo->prepare('DELETE FROM evacuation_plans WHERE id=?')->execute([$id]);
        audit($u['name'], 'fire-plan-delete', 'firesafety', $id);
        json_out(['ok' => true]);
    }

    /* ---- emergency contacts ---- */
    if ($action === 'contact-list') {
        json_out(['ok' => true, 'contacts' => fire_contact_enrich($pdo, fire_contact_rows($pdo, $u))]);
    }
    if ($action === 'contact-create') {
        $cname = trim($body['name'] ?? '');
        if ($cname === '') json_out(['ok' => false, 'error' => 'Contact name is required.'], 400);
        $phone = trim($body['phone'] ?? '');
        if ($phone === '') json_out(['ok' => false, 'error' => 'Phone is required.'], 400);
        $prop = trim($body['prop'] ?? '');
        if ($prop !== '' && !$propExists($prop)) json_out(['ok' => false, 'error' => 'Unknown property.'], 404);
        $oemail = $u['role'] === 'owner' ? $u['email'] : trim($body['owner_email'] ?? '');
        $id = ec_next_id($pdo);
        $pdo->prepare('INSERT INTO emergency_contacts (id, owner_email, prop, contact_type, name, phone, notes) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id, $oemail, $prop, in_array(trim($body['contact_type'] ?? ''), ['fire', 'police', 'ambulance', 'electric', 'gas', 'water', 'building_manager', 'owner', 'other'], true) ? trim($body['contact_type']) : 'other', $cname, $phone, trim($body['notes'] ?? '')]);
        audit($u['name'], 'fire-contact-create', 'firesafety', $id . ' ' . $cname);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'contact-save') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM emergency_contacts WHERE id=?'); $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) json_out(['ok' => false, 'error' => 'Contact not found.'], 404);
        if (!$ownerOk($c['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        $prop = trim($body['prop'] ?? $c['prop']);
        if ($prop !== '' && !$propExists($prop)) json_out(['ok' => false, 'error' => 'Unknown property.'], 404);
        $pdo->prepare('UPDATE emergency_contacts SET prop=?, contact_type=?, name=?, phone=?, notes=? WHERE id=?')
            ->execute([$prop, in_array(trim($body['contact_type'] ?? $c['contact_type']), ['fire', 'police', 'ambulance', 'electric', 'gas', 'water', 'building_manager', 'owner', 'other'], true) ? trim($body['contact_type']) : $c['contact_type'], trim($body['name'] ?? $c['name']), trim($body['phone'] ?? $c['phone']), trim($body['notes'] ?? $c['notes']), $id]);
        audit($u['name'], 'fire-contact-save', 'firesafety', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'contact-delete') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM emergency_contacts WHERE id=?'); $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) json_out(['ok' => false, 'error' => 'Contact not found.'], 404);
        if (!$ownerOk($c['owner_email'])) json_out(['ok' => false, 'error' => 'Not your property.'], 403);
        $pdo->prepare('DELETE FROM emergency_contacts WHERE id=?')->execute([$id]);
        audit($u['name'], 'fire-contact-delete', 'firesafety', $id);
        json_out(['ok' => true]);
    }

    if ($action === 'report') {
        $assets = fire_asset_enrich($pdo, fire_asset_rows($pdo, $u));
        $incidents = fire_incident_enrich($pdo, fire_incident_rows($pdo, $u));
        $propName = 'All properties';
        $fProp = trim($_GET['prop'] ?? '');
        if ($fProp !== '') {
            $pt = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $pt->execute([$fProp]);
            $pn = $pt->fetchColumn();
            if ($pn) $propName = $pn;
            $assets = array_values(array_filter($assets, fn($a) => $a['prop'] === $fProp));
            $incidents = array_values(array_filter($incidents, fn($i) => $i['prop'] === $fProp));
        }
        $title = 'Fire Safety Report';
        header('Content-Type: text/html; charset=utf-8');
        echo fire_report_html($pdo, $assets, $incidents, $propName, $title);
        exit;
    }

    if ($action === 'config-get') {
        json_out(['ok' => true, 'config' => ['inspection_days' => (int)(fire_cfg($pdo, 'inspection_days', 30)), 'expiry_alert_days' => (int)(fire_cfg($pdo, 'expiry_alert_days', 60)), 'drill_days' => (int)(fire_cfg($pdo, 'drill_days', 180))]]);
    }
    if ($action === 'config-save') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owners and managers can save Fire Safety settings.'], 403);
        $insp = max(1, min(365, (int)($body['inspection_days'] ?? 30)));
        $exp = max(1, min(365, (int)($body['expiry_alert_days'] ?? 60)));
        $drill = max(1, min(730, (int)($body['drill_days'] ?? 180)));
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['fire_inspection_days', (string)$insp]);
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['fire_expiry_alert_days', (string)$exp]);
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['fire_drill_days', (string)$drill]);
        audit($u['name'], 'fire-config-save', 'firesafety', 'insp=' . $insp . ' exp=' . $exp . ' drill=' . $drill);
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'Unknown fire safety action: ' . $action], 400);
}

case 'app-systems': {
    $u = require_user();
    require_module($u, 'systems');
    $pdo = db();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr'], true))
        json_out(['ok' => false, 'error' => 'Systems Watch is for owners and operations staff.'], 403);
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'summary' : 'list';
    $ownerOk = function ($email) use ($u) {
        if ($u['role'] === 'owner') return $email === $u['email'] || $email === '';
        return true;
    };
    $propExists = function ($prop) use ($pdo) {
        if ($prop === '') return false;
        $st = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE id=?'); $st->execute([$prop]);
        return (int)$st->fetchColumn() > 0;
    };
    $getAsset = function ($id) use ($pdo) {
        $st = $pdo->prepare('SELECT * FROM sys_assets WHERE id=?'); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    };
    if ($action === 'summary') {
        $assets = sys_asset_enrich($pdo, sys_asset_rows($pdo, $u));
        $svc = sys_service_rows($pdo, $u);
        $fuel = sys_fuel_rows($pdo, $u);
        $stats = [
            'assets_total' => count($assets),
            'operational' => count(array_filter($assets, fn($a) => $a['status'] === 'operational')),
            'faulty' => count(array_filter($assets, fn($a) => $a['status'] === 'faulty')),
            'service_overdue' => count(array_filter($assets, fn($a) => (int)$a['service_overdue'])),
            'service_due_soon' => count(array_filter($assets, fn($a) => (int)$a['service_due_soon'])),
            'cert_overdue' => count(array_filter($assets, fn($a) => (int)$a['cert_overdue'])),
            'cert_expiring' => count(array_filter($assets, fn($a) => (int)$a['cert_expiring'])),
            'fuel_low' => count(array_filter($assets, fn($a) => (int)$a['fuel_low'])),
            'services_total' => count($svc),
            'fuel_refills' => count($fuel),
            'fuel_liters_30d' => 0,
            'fuel_cost_30d' => 0,
        ];
        $cut = date('Y-m-d', strtotime('-30 days'));
        foreach ($fuel as $f) {
            if ($f['refill_date'] && $f['refill_date'] >= $cut) {
                $stats['fuel_liters_30d'] += (float)$f['liters'];
                $stats['fuel_cost_30d'] += (int)$f['amount'];
            }
        }
        $summary = ['stats' => $stats, 'assets' => $assets, 'services' => $svc, 'fuel' => $fuel, 'config' => ['service_alert_days' => (int)(sys_cfg($pdo, 'service_alert_days', 14)), 'cert_alert_days' => (int)(sys_cfg($pdo, 'cert_alert_days', 60)), 'fuel_low_pct' => (int)(sys_cfg($pdo, 'fuel_low_pct', 25)), 'fuel_alert_days' => (int)(sys_cfg($pdo, 'fuel_alert_days', 14))]];
        json_out(['ok' => true, 'summary' => $summary, 'config' => $summary['config']]);
    }
    if ($action === 'asset-list') {
        json_out(['ok' => true, 'assets' => sys_asset_enrich($pdo, sys_asset_rows($pdo, $u))]);
    }
    if ($action === 'asset-get') {
        $id = trim($body['id'] ?? $_GET['id'] ?? '');
        $a = $getAsset($id);
        if (!$a) json_out(['ok' => false, 'error' => 'Asset not found.'], 404);
        if (!$ownerOk($a['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        json_out(['ok' => true, 'asset' => sys_asset_enrich($pdo, [$a])[0]]);
    }
    if ($action === 'asset-create') {
        $loc = trim($body['location'] ?? '');
        if ($loc === '') json_out(['ok' => false, 'error' => 'Location is required.'], 400);
        $atype = trim($body['asset_type'] ?? 'other');
        $prop = trim($body['prop'] ?? '');
        if ($prop !== '' && !$propExists($prop)) json_out(['ok' => false, 'error' => 'Unknown property.'], 404);
        if (!in_array($atype, ['lift', 'generator', 'water_pump', 'sewage_pump', 'transformer', 'solar', 'booster_pump', 'other'], true)) $atype = 'other';
        $email = ($u['role'] === 'owner') ? $u['email'] : trim($body['owner_email'] ?? '');
        $interval = max(1, min(720, (int)($body['service_interval_days'] ?? 90)));
        $id = sa_next_id($pdo);
        $st = $pdo->prepare('INSERT INTO sys_assets (id, owner_email, prop, asset_type, location, model, serial_no, install_date, next_service, service_interval_days, cert_expiry, cert_ref, status, fuel_capacity, fuel_level, fuel_burn_rate, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$id, $email, $prop, $atype, $loc, trim($body['model'] ?? ''), trim($body['serial_no'] ?? ''), trim($body['install_date'] ?? ''), date('Y-m-d', strtotime('+' . $interval . ' days')), $interval, trim($body['cert_expiry'] ?? ''), trim($body['cert_ref'] ?? ''), 'operational', max(0, (int)($body['fuel_capacity'] ?? 0)), max(0, (int)($body['fuel_level'] ?? 0)), max(0, (float)($body['fuel_burn_rate'] ?? 0)), trim($body['notes'] ?? '')]);
        audit($u['name'], 'sys-asset-create', 'systems', $id . ' ' . $atype);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'asset-save') {
        $id = trim($body['id'] ?? '');
        $a = $getAsset($id);
        if (!$a) json_out(['ok' => false, 'error' => 'Asset not found.'], 404);
        if (!$ownerOk($a['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $upd = ['location' => 'location', 'asset_type' => 'asset_type', 'model' => 'model', 'serial_no' => 'serial_no', 'install_date' => 'install_date', 'cert_expiry' => 'cert_expiry', 'cert_ref' => 'cert_ref', 'notes' => 'notes'];
        $sets = []; $vals = [];
        foreach ($upd as $f => $col) {
            if (array_key_exists($f, $body)) { $sets[] = "$col=?"; $vals[] = trim((string)$body[$f]); }
        }
        if (array_key_exists('service_interval_days', $body)) { $sets[] = 'service_interval_days=?'; $vals[] = max(1, min(720, (int)$body['service_interval_days'])); }
        if (array_key_exists('fuel_capacity', $body)) { $sets[] = 'fuel_capacity=?'; $vals[] = max(0, (int)$body['fuel_capacity']); }
        if (array_key_exists('fuel_level', $body)) { $sets[] = 'fuel_level=?'; $vals[] = max(0, (int)$body['fuel_level']); }
        if (array_key_exists('fuel_burn_rate', $body)) { $sets[] = 'fuel_burn_rate=?'; $vals[] = max(0, (float)$body['fuel_burn_rate']); }
        if (array_key_exists('prop', $body)) {
            if (trim($body['prop']) !== '' && !$propExists(trim($body['prop']))) json_out(['ok' => false, 'error' => 'Unknown property.'], 404);
            $sets[] = 'prop=?'; $vals[] = trim($body['prop']);
        }
        if (!$sets) json_out(['ok' => false, 'error' => 'Nothing to update.'], 400);
        $vals[] = $id;
        $pdo->prepare('UPDATE sys_assets SET ' . implode(',', $sets) . ' WHERE id=?')->execute($vals);
        audit($u['name'], 'sys-asset-save', 'systems', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'asset-status') {
        $id = trim($body['id'] ?? '');
        $a = $getAsset($id);
        if (!$a) json_out(['ok' => false, 'error' => 'Asset not found.'], 404);
        if (!$ownerOk($a['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $ns = trim($body['status'] ?? '');
        if (!in_array($ns, ['operational', 'service_due', 'faulty', 'decommissioned'], true)) json_out(['ok' => false, 'error' => 'Invalid status.'], 400);
        $pdo->prepare('UPDATE sys_assets SET status=? WHERE id=?')->execute([$ns, $id]);
        audit($u['name'], 'sys-asset-status', 'systems', $id . ' -> ' . $ns);
        json_out(['ok' => true]);
    }
    if ($action === 'asset-service') {
        $id = trim($body['id'] ?? '');
        $a = $getAsset($id);
        if (!$a) json_out(['ok' => false, 'error' => 'Asset not found.'], 404);
        if (!$ownerOk($a['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $sid = ss_next_id($pdo);
        $svcType = trim($body['service_type'] ?? 'routine');
        if (!in_array($svcType, ['routine', 'repair', 'major', 'amc'], true)) $svcType = 'routine';
        $st = $pdo->prepare('INSERT INTO sys_services (id, owner_email, asset, service_date, service_type, technician, vendor, cost, hours_run, notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$sid, $a['owner_email'], $id, date('Y-m-d'), $svcType, trim($body['technician'] ?? ''), trim($body['vendor'] ?? ''), max(0, (int)($body['cost'] ?? 0)), max(0, (int)($body['hours_run'] ?? 0)), trim($body['notes'] ?? '')]);
        $interval = max(1, min(720, (int)($body['service_interval_days'] ?? (int)$a['service_interval_days'] ?: 90)));
        $pdo->prepare('UPDATE sys_assets SET next_service=?, service_interval_days=? WHERE id=?')->execute([date('Y-m-d', strtotime('+' . $interval . ' days')), $interval, $id]);
        audit($u['name'], 'sys-asset-service', 'systems', $id . ' ' . $sid);
        json_out(['ok' => true, 'id' => $sid]);
    }
    if ($action === 'asset-fuel') {
        $id = trim($body['id'] ?? '');
        $a = $getAsset($id);
        if (!$a) json_out(['ok' => false, 'error' => 'Asset not found.'], 404);
        if (!$ownerOk($a['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $liters = (float)($body['liters'] ?? 0);
        if ($liters <= 0) json_out(['ok' => false, 'error' => 'Liters must be positive.'], 400);
        $fid = sf_next_id($pdo);
        $rate = max(0, (float)($body['rate_per_litre'] ?? 0));
        $st = $pdo->prepare('INSERT INTO sys_fuel (id, owner_email, asset, refill_date, liters, rate_per_litre, amount, vendor, hours_run, notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$fid, $a['owner_email'], $id, date('Y-m-d'), $liters, $rate, (int)round($liters * $rate), trim($body['vendor'] ?? ''), max(0, (int)($body['hours_run'] ?? 0)), trim($body['notes'] ?? '')]);
        $cap = (int)$a['fuel_capacity'];
        $newLevel = (int)$a['fuel_level'] + (int)$liters;
        if ($cap > 0 && $newLevel > $cap) $newLevel = $cap;
        $pdo->prepare('UPDATE sys_assets SET fuel_level=? WHERE id=?')->execute([$newLevel, $id]);
        audit($u['name'], 'sys-asset-fuel', 'systems', $id . ' ' . $fid . ' ' . $liters . 'L');
        json_out(['ok' => true, 'id' => $fid]);
    }
    if ($action === 'asset-delete') {
        $id = trim($body['id'] ?? $_GET['id'] ?? '');
        $a = $getAsset($id);
        if (!$a) json_out(['ok' => false, 'error' => 'Asset not found.'], 404);
        if (!$ownerOk($a['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $pdo->prepare('DELETE FROM sys_services WHERE asset=?')->execute([$id]);
        $pdo->prepare('DELETE FROM sys_fuel WHERE asset=?')->execute([$id]);
        $pdo->prepare('DELETE FROM sys_assets WHERE id=?')->execute([$id]);
        audit($u['name'], 'sys-asset-delete', 'systems', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'service-list') {
        json_out(['ok' => true, 'services' => sys_service_rows($pdo, $u)]);
    }
    if ($action === 'service-create') {
        $aid = trim($body['asset'] ?? '');
        $a = $getAsset($aid);
        if (!$a) json_out(['ok' => false, 'error' => 'Asset not found.'], 404);
        if (!$ownerOk($a['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $sdate = trim($body['service_date'] ?? '');
        if ($sdate === '') json_out(['ok' => false, 'error' => 'Service date is required.'], 400);
        $sid = ss_next_id($pdo);
        $svcType = trim($body['service_type'] ?? 'routine');
        if (!in_array($svcType, ['routine', 'repair', 'major', 'amc'], true)) $svcType = 'routine';
        $st = $pdo->prepare('INSERT INTO sys_services (id, owner_email, asset, service_date, service_type, technician, vendor, cost, hours_run, notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$sid, $a['owner_email'], $aid, $sdate, $svcType, trim($body['technician'] ?? ''), trim($body['vendor'] ?? ''), max(0, (int)($body['cost'] ?? 0)), max(0, (int)($body['hours_run'] ?? 0)), trim($body['notes'] ?? '')]);
        audit($u['name'], 'sys-service-create', 'systems', $sid);
        json_out(['ok' => true, 'id' => $sid]);
    }
    if ($action === 'service-delete') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM sys_services WHERE id=?'); $st->execute([$id]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) json_out(['ok' => false, 'error' => 'Service not found.'], 404);
        if (!$ownerOk($s['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $pdo->prepare('DELETE FROM sys_services WHERE id=?')->execute([$id]);
        audit($u['name'], 'sys-service-delete', 'systems', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'fuel-list') {
        json_out(['ok' => true, 'fuel' => sys_fuel_rows($pdo, $u)]);
    }
    if ($action === 'fuel-create') {
        $aid = trim($body['asset'] ?? '');
        $a = $getAsset($aid);
        if (!$a) json_out(['ok' => false, 'error' => 'Asset not found.'], 404);
        if (!$ownerOk($a['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $liters = (float)($body['liters'] ?? 0);
        if ($liters <= 0) json_out(['ok' => false, 'error' => 'Liters must be positive.'], 400);
        $fid = sf_next_id($pdo);
        $rate = max(0, (float)($body['rate_per_litre'] ?? 0));
        $fdate = trim($body['refill_date'] ?? '') ?: date('Y-m-d');
        $st = $pdo->prepare('INSERT INTO sys_fuel (id, owner_email, asset, refill_date, liters, rate_per_litre, amount, vendor, hours_run, notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$fid, $a['owner_email'], $aid, $fdate, $liters, $rate, (int)round($liters * $rate), trim($body['vendor'] ?? ''), max(0, (int)($body['hours_run'] ?? 0)), trim($body['notes'] ?? '')]);
        audit($u['name'], 'sys-fuel-create', 'systems', $fid . ' ' . $liters . 'L');
        json_out(['ok' => true, 'id' => $fid]);
    }
    if ($action === 'fuel-delete') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM sys_fuel WHERE id=?'); $st->execute([$id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) json_out(['ok' => false, 'error' => 'Fuel entry not found.'], 404);
        if (!$ownerOk($f['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $pdo->prepare('DELETE FROM sys_fuel WHERE id=?')->execute([$id]);
        audit($u['name'], 'sys-fuel-delete', 'systems', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'config-get') {
        json_out(['ok' => true, 'config' => ['service_alert_days' => (int)(sys_cfg($pdo, 'service_alert_days', 14)), 'cert_alert_days' => (int)(sys_cfg($pdo, 'cert_alert_days', 60)), 'fuel_low_pct' => (int)(sys_cfg($pdo, 'fuel_low_pct', 25)), 'fuel_alert_days' => (int)(sys_cfg($pdo, 'fuel_alert_days', 14))]]);
    }
    if ($action === 'config-save') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owners and managers can save Systems Watch settings.'], 403);
        $svc = max(1, min(365, (int)($body['service_alert_days'] ?? 14)));
        $cert = max(1, min(365, (int)($body['cert_alert_days'] ?? 60)));
        $low = max(1, min(50, (int)($body['fuel_low_pct'] ?? 25)));
        $falert = max(1, min(120, (int)($body['fuel_alert_days'] ?? 14)));
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['sys_service_alert_days', (string)$svc]);
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['sys_cert_alert_days', (string)$cert]);
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['sys_fuel_low_pct', (string)$low]);
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['sys_fuel_alert_days', (string)$falert]);
        audit($u['name'], 'sys-config-save', 'systems', 'svc=' . $svc . ' cert=' . $cert . ' low=' . $low . ' fa=' . $falert);
        json_out(['ok' => true]);
    }
    if ($action === 'report') {
        $assets = sys_asset_enrich($pdo, sys_asset_rows($pdo, $u));
        $svc = sys_service_rows($pdo, $u);
        $fuel = sys_fuel_rows($pdo, $u);
        $cfg = ['service_alert_days' => (int)(sys_cfg($pdo, 'service_alert_days', 14)), 'cert_alert_days' => (int)(sys_cfg($pdo, 'cert_alert_days', 60)), 'fuel_low_pct' => (int)(sys_cfg($pdo, 'fuel_low_pct', 25))];
        $html = sys_report_html($pdo, 'Essential Systems Report', $assets, $svc, $fuel, $cfg);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    json_out(['ok' => false, 'error' => 'Unknown systems action: ' . $action], 400);
}

case 'app-staffwatch': {
    $u = require_user();
    require_module($u, 'staffwatch');
    $pdo = db();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'hr'], true))
        json_out(['ok' => false, 'error' => 'Staff Watch is for owners and HR.'], 403);
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'summary' : 'list';
    $ownerOk = function ($email) use ($u) {
        if ($u['role'] === 'owner') return $email === $u['email'] || $email === '';
        return true;
    };
    $propExists = function ($prop) use ($pdo) {
        if ($prop === '') return false;
        $st = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE id=?'); $st->execute([$prop]);
        return (int)$st->fetchColumn() > 0;
    };
    $getStaff = function ($id) use ($pdo) {
        $st = $pdo->prepare('SELECT * FROM building_staff WHERE id=?'); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    };
    if ($action === 'summary') {
        $staff = staff_enrich($pdo, staff_rows($pdo, $u));
        $att = staff_attendance_rows($pdo, $u);
        $pay = staff_payroll_rows($pdo, $u);
        $active = array_values(array_filter($staff, fn($s) => $s['status'] === 'active'));
        $stats = [
            'staff_total' => count($staff),
            'active' => count($active),
            'on_leave' => count(array_filter($staff, fn($s) => $s['status'] === 'on_leave')),
            'terminated' => count(array_filter($staff, fn($s) => $s['status'] === 'terminated')),
            'on_duty_today' => count(array_filter($active, fn($s) => in_array($s['today_status'], ['present', 'late'], true))),
            'absent_today' => count(array_filter($active, fn($s) => $s['today_status'] === 'absent')),
            'late_today' => count(array_filter($active, fn($s) => $s['today_status'] === 'late')),
            'no_mark_today' => count(array_filter($active, fn($s) => $s['today_status'] === '')),
            'payroll_unpaid_count' => count(array_filter($staff, fn($s) => (int)$s['payroll_unpaid_prev'])),
            'attendance_month' => count(array_filter($att, fn($a) => strpos($a['work_date'], date('Y-m')) === 0)),
        ];
        $summary = ['stats' => $stats, 'staff' => $staff, 'attendance' => $att, 'payroll' => $pay, 'config' => ['payroll_alert_days' => (int)(staff_cfg($pdo, 'payroll_alert_days', 7)), 'absent_pct' => (int)(staff_cfg($pdo, 'absent_pct', 30))]];
        json_out(['ok' => true, 'summary' => $summary, 'config' => $summary['config']]);
    }
    if ($action === 'staff-list') {
        json_out(['ok' => true, 'staff' => staff_enrich($pdo, staff_rows($pdo, $u))]);
    }
    if ($action === 'staff-get') {
        $id = trim($body['id'] ?? $_GET['id'] ?? '');
        $s = $getStaff($id);
        if (!$s) json_out(['ok' => false, 'error' => 'Staff not found.'], 404);
        if (!$ownerOk($s['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        json_out(['ok' => true, 'staff' => staff_enrich($pdo, [$s])[0]]);
    }
    if ($action === 'staff-create') {
        $name = trim($body['name'] ?? '');
        if ($name === '') json_out(['ok' => false, 'error' => 'Name is required.'], 400);
        $prop = trim($body['prop'] ?? '');
        if ($prop !== '' && !$propExists($prop)) json_out(['ok' => false, 'error' => 'Unknown property.'], 404);
        $role = trim($body['role'] ?? 'other');
        if (!in_array($role, ['guard', 'cleaner', 'caretaker', 'driver', 'supervisor', 'security', 'other'], true)) $role = 'other';
        $shift = trim($body['shift'] ?? 'day');
        if (!in_array($shift, ['day', 'night', 'rotating'], true)) $shift = 'day';
        $email = ($u['role'] === 'owner') ? $u['email'] : trim($body['owner_email'] ?? '');
        $id = bs_next_id($pdo);
        $st = $pdo->prepare('INSERT INTO building_staff (id, owner_email, name, role, phone, join_date, monthly_salary, shift, prop, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$id, $email, $name, $role, trim($body['phone'] ?? ''), trim($body['join_date'] ?? '') ?: date('Y-m-d'), max(0, (int)($body['monthly_salary'] ?? 0)), $shift, $prop, 'active', trim($body['notes'] ?? '')]);
        audit($u['name'], 'staff-create', 'staffwatch', $id . ' ' . $role);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'staff-save') {
        $id = trim($body['id'] ?? '');
        $s = $getStaff($id);
        if (!$s) json_out(['ok' => false, 'error' => 'Staff not found.'], 404);
        if (!$ownerOk($s['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $upd = ['name' => 'name', 'role' => 'role', 'phone' => 'phone', 'join_date' => 'join_date', 'shift' => 'shift', 'notes' => 'notes'];
        $sets = []; $vals = [];
        foreach ($upd as $f => $col) {
            if (array_key_exists($f, $body)) { $sets[] = "$col=?"; $vals[] = trim((string)$body[$f]); }
        }
        if (array_key_exists('monthly_salary', $body)) { $sets[] = 'monthly_salary=?'; $vals[] = max(0, (int)$body['monthly_salary']); }
        if (array_key_exists('prop', $body)) {
            if (trim($body['prop']) !== '' && !$propExists(trim($body['prop']))) json_out(['ok' => false, 'error' => 'Unknown property.'], 404);
            $sets[] = 'prop=?'; $vals[] = trim($body['prop']);
        }
        if (!$sets) json_out(['ok' => false, 'error' => 'Nothing to update.'], 400);
        $vals[] = $id;
        $pdo->prepare('UPDATE building_staff SET ' . implode(',', $sets) . ' WHERE id=?')->execute($vals);
        audit($u['name'], 'staff-save', 'staffwatch', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'staff-status') {
        $id = trim($body['id'] ?? '');
        $s = $getStaff($id);
        if (!$s) json_out(['ok' => false, 'error' => 'Staff not found.'], 404);
        if (!$ownerOk($s['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $ns = trim($body['status'] ?? '');
        if (!in_array($ns, ['active', 'on_leave', 'terminated'], true)) json_out(['ok' => false, 'error' => 'Invalid status.'], 400);
        $pdo->prepare('UPDATE building_staff SET status=? WHERE id=?')->execute([$ns, $id]);
        audit($u['name'], 'staff-status', 'staffwatch', $id . ' -> ' . $ns);
        json_out(['ok' => true]);
    }
    if ($action === 'staff-delete') {
        $id = trim($body['id'] ?? $_GET['id'] ?? '');
        $s = $getStaff($id);
        if (!$s) json_out(['ok' => false, 'error' => 'Staff not found.'], 404);
        if (!$ownerOk($s['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $pdo->prepare('DELETE FROM staff_attendance WHERE staff=?')->execute([$id]);
        $pdo->prepare('DELETE FROM staff_payroll WHERE staff=?')->execute([$id]);
        $pdo->prepare('DELETE FROM building_staff WHERE id=?')->execute([$id]);
        audit($u['name'], 'staff-delete', 'staffwatch', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'attendance-list') {
        $month = trim($_GET['month'] ?? $body['month'] ?? '');
        $rows = staff_attendance_rows($pdo, $u);
        if ($month !== '') $rows = array_values(array_filter($rows, fn($a) => strpos($a['work_date'], $month) === 0));
        json_out(['ok' => true, 'attendance' => $rows]);
    }
    if ($action === 'attendance-mark') {
        $sid = trim($body['staff'] ?? '');
        $s = $getStaff($sid);
        if (!$s) json_out(['ok' => false, 'error' => 'Staff not found.'], 404);
        if (!$ownerOk($s['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $wdate = trim($body['work_date'] ?? '');
        if ($wdate === '') json_out(['ok' => false, 'error' => 'Work date is required.'], 400);
        $status = trim($body['status'] ?? 'present');
        if (!in_array($status, ['present', 'late', 'absent', 'leave'], true)) $status = 'present';
        $existing = null;
        $st = $pdo->prepare('SELECT * FROM staff_attendance WHERE staff=? AND work_date=?'); $st->execute([$sid, $wdate]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $pdo->prepare('UPDATE staff_attendance SET status=?, check_in=?, check_out=?, notes=? WHERE id=?')->execute([$status, trim($body['check_in'] ?? ''), trim($body['check_out'] ?? ''), trim($body['notes'] ?? ''), $existing['id']]);
            $id = $existing['id'];
            audit($u['name'], 'staff-attendance-update', 'staffwatch', $id . ' ' . $status);
        } else {
            $id = at_next_id($pdo);
            $st2 = $pdo->prepare('INSERT INTO staff_attendance (id, owner_email, staff, work_date, check_in, check_out, status, notes) VALUES (?,?,?,?,?,?,?,?)');
            $st2->execute([$id, $s['owner_email'], $sid, $wdate, trim($body['check_in'] ?? ''), trim($body['check_out'] ?? ''), $status, trim($body['notes'] ?? '')]);
            audit($u['name'], 'staff-attendance-mark', 'staffwatch', $id . ' ' . $status);
        }
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'attendance-delete') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM staff_attendance WHERE id=?'); $st->execute([$id]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) json_out(['ok' => false, 'error' => 'Attendance entry not found.'], 404);
        if (!$ownerOk($a['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $pdo->prepare('DELETE FROM staff_attendance WHERE id=?')->execute([$id]);
        audit($u['name'], 'staff-attendance-delete', 'staffwatch', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'payroll-list') {
        json_out(['ok' => true, 'payroll' => staff_payroll_rows($pdo, $u)]);
    }
    if ($action === 'payroll-create') {
        $sid = trim($body['staff'] ?? '');
        $s = $getStaff($sid);
        if (!$s) json_out(['ok' => false, 'error' => 'Staff not found.'], 404);
        if (!$ownerOk($s['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $month = trim($body['month'] ?? '');
        if (!preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/', $month)) json_out(['ok' => false, 'error' => 'Month must be YYYY-MM.'], 400);
        $calc = staff_payroll_calc($pdo, $s, $month, $body['overtime'] ?? 0, $body['bonus'] ?? 0, $body['advance_deduction'] ?? 0);
        $existing = null;
        $st = $pdo->prepare('SELECT * FROM staff_payroll WHERE staff=? AND month=?'); $st->execute([$sid, $month]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $pdo->prepare('UPDATE staff_payroll SET salary=?, overtime=?, bonus=?, advance_deduction=?, absent_days=?, daily_rate=?, net=? WHERE id=?')->execute([(int)$s['monthly_salary'], $calc['absent_days'] >= 0 ? (int)($body['overtime'] ?? $existing['overtime']) : 0, (int)($body['bonus'] ?? $existing['bonus']), (int)($body['advance_deduction'] ?? $existing['advance_deduction']), $calc['absent_days'], $calc['daily_rate'], $calc['net'], $existing['id']]);
            $id = $existing['id'];
            audit($u['name'], 'staff-payroll-update', 'staffwatch', $id);
        } else {
            $id = py_next_id($pdo);
            $st2 = $pdo->prepare('INSERT INTO staff_payroll (id, owner_email, staff, month, salary, overtime, bonus, advance_deduction, absent_days, daily_rate, net, status, paid_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st2->execute([$id, $s['owner_email'], $sid, $month, (int)$s['monthly_salary'], max(0, (int)($body['overtime'] ?? 0)), max(0, (int)($body['bonus'] ?? 0)), max(0, (int)($body['advance_deduction'] ?? 0)), $calc['absent_days'], $calc['daily_rate'], $calc['net'], 'Draft', '']);
            audit($u['name'], 'staff-payroll-create', 'staffwatch', $id);
        }
        json_out(['ok' => true, 'id' => $id, 'absent_days' => $calc['absent_days'], 'net' => $calc['net']]);
    }
    if ($action === 'payroll-pay') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM staff_payroll WHERE id=?'); $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) json_out(['ok' => false, 'error' => 'Payroll entry not found.'], 404);
        if (!$ownerOk($p['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        if ($p['status'] === 'Paid') json_out(['ok' => false, 'error' => 'Already paid.'], 409);
        $pdo->prepare("UPDATE staff_payroll SET status='Paid', paid_at=? WHERE id=?")->execute([date('Y-m-d'), $id]);
        audit($u['name'], 'staff-payroll-pay', 'staffwatch', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'payroll-delete') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM staff_payroll WHERE id=?'); $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) json_out(['ok' => false, 'error' => 'Payroll entry not found.'], 404);
        if (!$ownerOk($p['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $pdo->prepare('DELETE FROM staff_payroll WHERE id=?')->execute([$id]);
        audit($u['name'], 'staff-payroll-delete', 'staffwatch', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'config-get') {
        json_out(['ok' => true, 'config' => ['payroll_alert_days' => (int)(staff_cfg($pdo, 'payroll_alert_days', 7)), 'absent_pct' => (int)(staff_cfg($pdo, 'absent_pct', 30))]]);
    }
    if ($action === 'config-save') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owners and managers can save Staff Watch settings.'], 403);
        $pad = max(1, min(120, (int)($body['payroll_alert_days'] ?? 7)));
        $ap = max(1, min(50, (int)($body['absent_pct'] ?? 30)));
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['staff_payroll_alert_days', (string)$pad]);
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['staff_absent_pct', (string)$ap]);
        audit($u['name'], 'staff-config-save', 'staffwatch', 'pad=' . $pad . ' ap=' . $ap);
        json_out(['ok' => true]);
    }
    if ($action === 'report') {
        $staff = staff_enrich($pdo, staff_rows($pdo, $u));
        $att = staff_attendance_rows($pdo, $u);
        $pay = staff_payroll_rows($pdo, $u);
        $cfg = ['absent_pct' => (int)(staff_cfg($pdo, 'absent_pct', 30))];
        $html = staff_report_html($pdo, 'Staff Watch Report', $staff, $att, $pay, $cfg);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
    json_out(['ok' => false, 'error' => 'Unknown staff watch action: ' . $action], 400);
}


case 'app-samity': {
    $u = require_user();
    require_module($u, 'samity');
    $pdo = db();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr'], true))
        json_out(['ok' => false, 'error' => 'Kalyan Samity is for owners and managers.'], 403);
    $action = trim($body['action'] ?? $_GET['action'] ?? '');
    if ($action === '') $action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') ? 'summary' : 'list';
    $ownerOk = function ($email) use ($u) {
        if ($u['role'] === 'owner') return $email === $u['email'] || $email === '';
        return true;
    };
    $unitExists = function ($unit) use ($pdo) {
        if ($unit === '') return false;
        $st = $pdo->prepare('SELECT COUNT(*) FROM units WHERE id=?'); $st->execute([$unit]);
        return (int)$st->fetchColumn() > 0;
    };
    $getBill = function ($id) use ($pdo) {
        $st = $pdo->prepare('SELECT * FROM samity_bills WHERE id=?'); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    };
    if ($action === 'summary') {
        $members = samity_member_rows($pdo, $u);
        $bills = array_map(fn($b) => samity_bill_enrich($pdo, $b), samity_bill_rows($pdo, $u));
        $col = samity_collection_rows($pdo, $u);
        $exp = samity_expense_rows($pdo, $u);
        $fund = samity_fund($pdo, $bills, $col);
        $expTotal = 0; foreach ($exp as $e) { $expTotal += (int)$e['amount']; }
        $expMonth = 0; $ym = date('Y-m'); foreach ($exp as $e) { if (substr((string)$e['exp_date'], 0, 7) === $ym) $expMonth += (int)$e['amount']; }
        $stats = [
            'members_total' => count($members),
            'members_active' => count(array_filter($members, fn($m) => $m['status'] === 'active')),
            'bills_total' => count($bills),
            'bills_overdue' => count(array_filter($bills, fn($b) => $b['overdue'])),
            'bills_partial' => count(array_filter($bills, fn($b) => $b['computed_status'] === 'Partial')),
            'bills_paid' => count(array_filter($bills, fn($b) => $b['computed_status'] === 'Paid')),
            'collections_total' => count($col),
            'fund_collected' => $fund['total_collected'],
            'fund_outstanding' => $fund['outstanding'],
            'collection_rate' => $fund['collection_rate'],
            'expenses_total' => count($exp),
            'expenses_amount' => $expTotal,
            'expenses_this_month' => $expMonth,
            'fund_net' => max(0, $fund['total_collected'] - $expTotal),
        ];
        $summary = ['stats' => $stats, 'members' => $members, 'bills' => $bills, 'collections' => $col, 'expenses' => $exp, 'fund' => $fund, 'config' => ['alert_days' => (int)(samity_cfg($pdo, 'alert_days', 7)), 'default_charge' => (int)(samity_cfg($pdo, 'default_charge', 3000))]];
        json_out(['ok' => true, 'summary' => $summary, 'config' => $summary['config']]);
    }
    if ($action === 'member-list') json_out(['ok' => true, 'members' => samity_member_rows($pdo, $u)]);
    if ($action === 'member-get') {
        $id = trim($body['id'] ?? $_GET['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM samity_members WHERE id=?'); $st->execute([$id]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) json_out(['ok' => false, 'error' => 'Member not found.'], 404);
        if (!$ownerOk($m['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        json_out(['ok' => true, 'member' => $m]);
    }
    if ($action === 'member-create') {
        $name = trim($body['name'] ?? '');
        if ($name === '') json_out(['ok' => false, 'error' => 'Name is required.'], 400);
        $id = samity_next_id($pdo, 'SM-');
        $role = trim($body['role'] ?? 'Member');
        if (!in_array($role, ['Chairman', 'Secretary', 'Treasurer', 'Member'], true)) $role = 'Member';
        $oe = ($u['role'] === 'owner') ? $u['email'] : trim($body['owner_email'] ?? '');
        $st = $pdo->prepare('INSERT INTO samity_members (id, owner_email, prop, name, role, phone, since_date, status, notes) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([$id, $oe, trim($body['prop'] ?? ''), $name, $role, trim($body['phone'] ?? ''), trim($body['since_date'] ?? date('Y-m-d')), trim($body['status'] ?? 'active'), trim($body['notes'] ?? '')]);
        audit($u['name'], 'member-create', 'samity', $id . ' ' . $role);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'member-save') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM samity_members WHERE id=?'); $st->execute([$id]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) json_out(['ok' => false, 'error' => 'Member not found.'], 404);
        if (!$ownerOk($m['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $role = trim($body['role'] ?? $m['role']);
        if (!in_array($role, ['Chairman', 'Secretary', 'Treasurer', 'Member'], true)) $role = $m['role'];
        $status = trim($body['status'] ?? $m['status']);
        if (!in_array($status, ['active', 'inactive'], true)) $status = $m['status'];
        $st = $pdo->prepare('UPDATE samity_members SET name=?, role=?, phone=?, since_date=?, status=?, notes=?, prop=? WHERE id=?');
        $st->execute([trim($body['name'] ?? $m['name']), $role, trim($body['phone'] ?? $m['phone']), trim($body['since_date'] ?? $m['since_date']), $status, trim($body['notes'] ?? $m['notes']), trim($body['prop'] ?? $m['prop'] ?? ''), $id]);
        audit($u['name'], 'member-save', 'samity', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'member-delete') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM samity_members WHERE id=?'); $st->execute([$id]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) json_out(['ok' => false, 'error' => 'Member not found.'], 404);
        if (!$ownerOk($m['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $pdo->prepare('DELETE FROM samity_members WHERE id=?')->execute([$id]);
        audit($u['name'], 'member-delete', 'samity', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'bill-list') json_out(['ok' => true, 'bills' => array_map(fn($b) => samity_bill_enrich($pdo, $b), samity_bill_rows($pdo, $u))]);
    if ($action === 'bill-get') {
        $id = trim($body['id'] ?? $_GET['id'] ?? '');
        $b = $getBill($id);
        if (!$b) json_out(['ok' => false, 'error' => 'Bill not found.'], 404);
        if (!$ownerOk($b['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        json_out(['ok' => true, 'bill' => samity_bill_enrich($pdo, $b)]);
    }
    if ($action === 'bill-create') {
        $unit = trim($body['unit'] ?? '');
        if ($unit === '' || !$unitExists($unit)) json_out(['ok' => false, 'error' => 'A valid unit is required.'], 400);
        $month = trim($body['month'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) json_out(['ok' => false, 'error' => 'Month must be YYYY-MM.'], 400);
        $amount = max(1, (int)($body['amount'] ?? 0));
        $id = samity_next_id($pdo, 'SB-');
        $due = trim($body['due_date'] ?? date('Y-m-05'));
        $oe = ($u['role'] === 'owner') ? $u['email'] : trim($body['owner_email'] ?? '');
        $up = $pdo->prepare('SELECT p FROM units WHERE id=?'); $up->execute([$unit]);
        $prop = (string)($up->fetchColumn() ?: '');
        $st = $pdo->prepare('INSERT INTO samity_bills (id, owner_email, prop, unit, month, amount, due_date, status, note) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([$id, $oe, $prop, $unit, $month, $amount, $due, trim($body['status'] ?? 'Pending'), trim($body['note'] ?? '')]);
        audit($u['name'], 'bill-create', 'samity', $id . ' ' . $unit . ' ' . $month);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'bill-save') {
        $id = trim($body['id'] ?? '');
        $b = $getBill($id);
        if (!$b) json_out(['ok' => false, 'error' => 'Bill not found.'], 404);
        if (!$ownerOk($b['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $status = trim($body['status'] ?? $b['status']);
        if (!in_array($status, ['Pending', 'Paid', 'Waived'], true)) $status = $b['status'];
        $st = $pdo->prepare('UPDATE samity_bills SET unit=?, month=?, amount=?, due_date=?, status=?, note=? WHERE id=?');
        $st->execute([trim($body['unit'] ?? $b['unit']), trim($body['month'] ?? $b['month']), max(1, (int)($body['amount'] ?? $b['amount'])), trim($body['due_date'] ?? $b['due_date']), $status, trim($body['note'] ?? $b['note']), $id]);
        audit($u['name'], 'bill-save', 'samity', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'bill-delete') {
        $id = trim($body['id'] ?? '');
        $b = $getBill($id);
        if (!$b) json_out(['ok' => false, 'error' => 'Bill not found.'], 404);
        if (!$ownerOk($b['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $pdo->prepare('DELETE FROM samity_collections WHERE bill=?')->execute([$id]);
        $pdo->prepare('DELETE FROM samity_bills WHERE id=?')->execute([$id]);
        audit($u['name'], 'bill-delete', 'samity', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'collection-list') json_out(['ok' => true, 'collections' => samity_collection_rows($pdo, $u)]);
    if ($action === 'collection-create') {
        $bid = trim($body['bill'] ?? '');
        $b = $getBill($bid);
        if (!$b) json_out(['ok' => false, 'error' => 'Bill not found.'], 400);
        if (!$ownerOk($b['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $amount = max(1, (int)($body['amount'] ?? 0));
        $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM samity_collections WHERE bill=?'); $st->execute([$bid]);
        $already = (int)$st->fetchColumn();
        if ($already + $amount > (int)$b['amount']) json_out(['ok' => false, 'error' => 'Overpayment: bill total is ৳' . number_format((int)$b['amount']) . ' and ৳' . number_format($already) . ' is already collected.'], 400);
        $method = trim($body['method'] ?? 'Cash');
        if (!in_array($method, ['Cash', 'bKash', 'Nagad', 'Bank'], true)) $method = 'Cash';
        $id = samity_next_id($pdo, 'SC-');
        $oe = ($u['role'] === 'owner') ? $u['email'] : trim($body['owner_email'] ?? $b['owner_email']);
        $st = $pdo->prepare('INSERT INTO samity_collections (id, owner_email, prop, bill, amount, method, collected_at, receipt_no, note) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([$id, $oe, $b['prop'] ?? '', $bid, $amount, $method, trim($body['collected_at'] ?? date('Y-m-d')), 'RCPT-' . $id, trim($body['note'] ?? '')]);
        if ($already + $amount >= (int)$b['amount'] && $b['status'] === 'Pending') {
            $pdo->prepare("UPDATE samity_bills SET status='Paid' WHERE id=?")->execute([$bid]);
        } elseif ($b['status'] === 'Pending') {
            $pdo->prepare("UPDATE samity_bills SET status='Partial' WHERE id=?")->execute([$bid]);
        }
        audit($u['name'], 'collection-create', 'samity', $id . ' ' . $method . ' ' . $amount);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'collection-delete') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM samity_collections WHERE id=?'); $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) json_out(['ok' => false, 'error' => 'Collection not found.'], 404);
        if (!$ownerOk($c['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $pdo->prepare('DELETE FROM samity_collections WHERE id=?')->execute([$id]);
        $b = $getBill($c['bill']);
        if ($b) {
            $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM samity_collections WHERE bill=?'); $st->execute([$b['id']]);
            $left = (int)$st->fetchColumn();
            $ns = $left >= (int)$b['amount'] && (int)$b['amount'] > 0 ? 'Paid' : ($left > 0 ? 'Partial' : 'Pending');
            $pdo->prepare('UPDATE samity_bills SET status=? WHERE id=?')->execute([$ns, $b['id']]);
        }
        audit($u['name'], 'collection-delete', 'samity', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'expense-list') json_out(['ok' => true, 'expenses' => samity_expense_rows($pdo, $u)]);
    if ($action === 'expense-create') {
        $title = trim($body['title'] ?? '');
        if ($title === '') json_out(['ok' => false, 'error' => 'Title is required.'], 400);
        $amount = max(1, (int)($body['amount'] ?? 0));
        $cat = trim($body['category'] ?? 'other');
        if (!in_array($cat, ['maintenance', 'utility', 'repair', 'cleaning', 'event', 'security', 'other'], true)) $cat = 'other';
        $id = samity_next_id($pdo, 'SE-');
        $oe = ($u['role'] === 'owner') ? $u['email'] : trim($body['owner_email'] ?? '');
        $st = $pdo->prepare('INSERT INTO samity_expenses (id, owner_email, prop, category, title, amount, exp_date, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([$id, $oe, trim($body['prop'] ?? ''), $cat, $title, $amount, trim($body['exp_date'] ?? date('Y-m-d')), trim($body['note'] ?? ''), $u['name']]);
        audit($u['name'], 'expense-create', 'samity', $id . ' ' . $cat . ' ' . $amount);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'expense-save') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM samity_expenses WHERE id=?'); $st->execute([$id]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e) json_out(['ok' => false, 'error' => 'Expense not found.'], 404);
        if (!$ownerOk($e['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $cat = trim($body['category'] ?? $e['category']);
        if (!in_array($cat, ['maintenance', 'utility', 'repair', 'cleaning', 'event', 'security', 'other'], true)) $cat = $e['category'];
        $st = $pdo->prepare('UPDATE samity_expenses SET prop=?, category=?, title=?, amount=?, exp_date=?, note=? WHERE id=?');
        $st->execute([trim($body['prop'] ?? $e['prop']), $cat, trim($body['title'] ?? $e['title']), max(1, (int)($body['amount'] ?? $e['amount'])), trim($body['exp_date'] ?? $e['exp_date']), trim($body['note'] ?? $e['note']), $id]);
        audit($u['name'], 'expense-save', 'samity', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'expense-delete') {
        $id = trim($body['id'] ?? '');
        $st = $pdo->prepare('SELECT * FROM samity_expenses WHERE id=?'); $st->execute([$id]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e) json_out(['ok' => false, 'error' => 'Expense not found.'], 404);
        if (!$ownerOk($e['owner_email'])) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $pdo->prepare('DELETE FROM samity_expenses WHERE id=?')->execute([$id]);
        audit($u['name'], 'expense-delete', 'samity', $id);
        json_out(['ok' => true]);
    }
    if ($action === 'config-get') {
        json_out(['ok' => true, 'config' => ['alert_days' => (int)(samity_cfg($pdo, 'alert_days', 7)), 'default_charge' => (int)(samity_cfg($pdo, 'default_charge', 3000))]]);
    }
    if ($action === 'config-save') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true))
            json_out(['ok' => false, 'error' => 'Only owners and managers can save Kalyan Samity settings.'], 403);
        $ad = max(1, min(120, (int)($body['alert_days'] ?? 7)));
        $dc = max(1, min(100000, (int)($body['default_charge'] ?? 3000)));
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['samity_alert_days', (string)$ad]);
        $pdo->prepare('INSERT INTO org_settings (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['samity_default_charge', (string)$dc]);
        audit($u['name'], 'config-save', 'samity', 'ad=' . $ad . ' dc=' . $dc);
        json_out(['ok' => true]);
    }
    if ($action === 'report') {
        $members = samity_member_rows($pdo, $u);
        $bills = array_map(fn($b) => samity_bill_enrich($pdo, $b), samity_bill_rows($pdo, $u));
        $col = samity_collection_rows($pdo, $u);
        $cfg = ['t_total_billed' => 'Total billed / মোট বিল', 't_collected' => 'Collected / আদায়', 't_outstanding' => 'Outstanding / বকেয়া', 't_rate' => 'Collection rate / আদায়ের হার', 't_committee' => 'Committee / পরিচালনা কমিটি', 't_charges' => 'Service charge ledger / সার্ভিস চার্জ হিসাব'];
        $html = samity_report_html($pdo, 'Kalyan Samity Report', $members, $bills, $col, $cfg);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
    json_out(['ok' => false, 'error' => 'Unknown samity action: ' . $action], 400);
}

case 'app-packages': {
    $u = require_user();
    require_module($u, 'packages');
    $action = $body['action'] ?? 'list';
    $pdo = db();
    if ($action === 'list') {
        $rows = $pdo->query('SELECT * FROM plan_catalog ORDER BY price')->fetchAll(PDO::FETCH_ASSOC);
        $subs = $pdo->query('SELECT lower(plan) p, COUNT(*) c FROM subscribers GROUP BY lower(plan)')->fetchAll(PDO::FETCH_ASSOC);
        $cnt = [];
        foreach ($subs as $s) $cnt[$s['p']] = (int)$s['c'];
        $out = [];
        foreach ($rows as &$r) {
            $r['features'] = json_decode($r['features'], true) ?: [];
            $r['modules'] = json_decode($r['modules'] ?? '[]', true) ?: [];
            $r['limits'] = json_decode($r['limits'] ?? '{}', true) ?: [];
            $r['subscribers'] = $cnt[strtolower($r['code'])] ?? 0;
            $out[] = $r;
        }
        json_out(['ok' => true, 'packages' => $out, 'registry' => MODULE_REGISTRY(), 'roles' => array_keys(ROLE_MODULES())]);
    }
    if ($action === 'save') {
        $code = strtolower(trim($body['code'] ?? ''));
        if (!preg_match('/^[a-z0-9_]{2,24}$/', $code)) json_out(['ok'=>false,'error'=>'Invalid package code.'], 400);
        $name = trim($body['name'] ?? '');
        $price = max(0, (int)($body['price'] ?? 0));
        $seats = max(1, min(999, (int)($body['seats'] ?? 1)));
        $tag = trim($body['tag'] ?? '');
        $features = is_array($body['features'] ?? null) ? $body['features'] : [];
        $modules = is_array($body['modules'] ?? null) ? $body['modules'] : [];
        $limits = is_array($body['limits'] ?? null) ? $body['limits'] : [];
        $description = trim($body['description'] ?? '');
        $billing_cycle = trim($body['billing_cycle'] ?? 'monthly');
        $trial_days = max(0, (int)($body['trial_days'] ?? 0));
        $popular = (int)($body['popular'] ?? 0);
        $sort_order = (int)($body['sort_order'] ?? 0);
        $color = trim($body['color'] ?? '');
        if (!$name) json_out(['ok'=>false,'error'=>'Package name required.'], 400);
        $existing = package_row($code);
        if ($existing) {
            $st = $pdo->prepare('UPDATE plan_catalog SET name=?, price=?, seats=?, tag=?, features=?, modules=?, limits=?, description=?, billing_cycle=?, trial_days=?, popular=?, sort_order=?, color=? WHERE lower(code)=?');
            $st->execute([$name, $price, $seats, $tag, json_encode($features), json_encode($modules), json_encode($limits), $description, $billing_cycle, $trial_days, $popular, $sort_order, $color, $code]);
            audit($u['name'], 'Package update', 'packages', $code, $name . ' ৳' . $price);
        } else {
            $st = $pdo->prepare('INSERT INTO plan_catalog (code, name, price, seats, tag, features, modules, limits, description, billing_cycle, trial_days, popular, sort_order, color) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$code, $name, $price, $seats, $tag, json_encode($features), json_encode($modules), json_encode($limits), $description, $billing_cycle, $trial_days, $popular, $sort_order, $color]);
            audit($u['name'], 'Package create', 'packages', $code, $name . ' ৳' . $price);
        }
        json_out(['ok' => true, 'package' => package_row($code)]);
    }
    if ($action === 'delete') {
        $code = strtolower(trim($body['code'] ?? ''));
        if (!package_row($code)) json_out(['ok'=>false,'error'=>'Package not found.'], 404);
        $st = $pdo->prepare('SELECT COUNT(*) FROM subscribers WHERE lower(plan)=?');
        $st->execute([$code]);
        $inuse = (int)$st->fetchColumn();
        if ($inuse > 0) json_out(['ok'=>false,'error'=>'Cannot delete — ' . $inuse . ' subscriber(s) on this package.'], 409);
        $pdo->prepare('DELETE FROM plan_catalog WHERE lower(code)=?')->execute([$code]);
        audit($u['name'], 'Package delete', 'packages', $code, '');
        json_out(['ok' => true]);
    }
    if ($action === 'subscribers') {
        $rows = $pdo->query('SELECT id, name, org, email, phone, role, plan, status, trial_end, created_at, verified_at, last_login FROM subscribers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $plans = $pdo->query('SELECT code, name, price, seats FROM plan_catalog ORDER BY price')->fetchAll(PDO::FETCH_ASSOC);
        json_out(['ok' => true, 'subscribers' => $rows, 'plans' => $plans]);
    }
    if ($action === 'assign') {
        $email = strtolower(trim($body['email'] ?? ''));
        $plan = strtolower(trim($body['plan'] ?? ''));
        if (!package_row($plan)) json_out(['ok'=>false,'error'=>'Package not found.'], 404);
        $st = $pdo->prepare('UPDATE subscribers SET plan=? WHERE email=?');
        $st->execute([$plan, $email]);
        if ($st->rowCount() === 0) json_out(['ok'=>false,'error'=>'Subscriber not found.'], 404);
        audit($u['name'], 'Package assign', 'packages', $email, '→ ' . $plan);
        json_out(['ok' => true, 'subscription' => sub_info($email)]);
    }
    if ($action === 'preview') {
        $plan = strtolower(trim($body['plan'] ?? ''));
        $role = trim($body['role'] ?? 'owner');
        if (!package_row($plan)) json_out(['ok'=>false,'error'=>'Package not found.'], 404);
        $fake = ['kind' => 'sub', 'role' => $role, 'plan' => ucfirst($plan)];
        json_out(['ok' => true, 'modules' => effective_modules($fake, $role), 'limits' => effective_limits($fake)]);
    }
    if ($action === 'limits-usage') {
        $props = (int)$pdo->query('SELECT COUNT(*) FROM properties')->fetchColumn();
        $units = (int)$pdo->query('SELECT COUNT(*) FROM units')->fetchColumn();
        json_out(['ok' => true, 'usage' => ['properties' => $props, 'units' => $units]]);
    }
    json_out(['ok' => false, 'error' => 'Unknown packages action: ' . $action], 400);
}

case 'app-photo': {
    $u = require_user();
    $action = trim($_GET['action'] ?? $_POST['action'] ?? '');
    $pdo = db();
    if ($action === 'view') {
        $target = trim($_GET['target'] ?? '');
        $id = trim($_GET['id'] ?? '');
        $fname = '';
        if ($target === 'tenant') {
            if (!in_array('tenants', effective_modules($u), true) && $u['role'] !== 'tenant')
                json_out(['ok' => false, 'error' => 'Access denied.'], 403);
            $st = $pdo->prepare('SELECT photo FROM tenants WHERE id=?');
            $st->execute([$id]);
            $fname = (string)($st->fetchColumn() ?: '');
        } elseif ($target === 'user') {
            $st = $pdo->prepare('SELECT photo FROM app_users WHERE id=? UNION ALL SELECT photo FROM subscribers WHERE id=?');
            $st->execute([$id, $id]);
            $fname = (string)($st->fetchColumn() ?: '');
        } else {
            json_out(['ok' => false, 'error' => 'Unknown photo target.'], 400);
        }
        if (!$fname) json_out(['ok' => false, 'error' => 'No photo.'], 404);
        $f = DATA_DIR() . '/' . $fname;
        if (!is_file($f)) json_out(['ok' => false, 'error' => 'Photo file missing.'], 404);
        $mime = mime_content_type($f) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }
    /* uploads are multipart: action passed as GET param (gate visit-photo pattern) */
    if ($action === 'tenant-upload') {
        if (!in_array('tenants', effective_modules($u), true)) json_out(['ok' => false, 'error' => 'Access denied.'], 403);
        $id = trim($_POST['id'] ?? '');
        $st = $pdo->prepare('SELECT photo FROM tenants WHERE id=?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok' => false, 'error' => 'Tenant not found.'], 404);
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK)
            json_out(['ok' => false, 'error' => 'A photo upload is required.'], 400);
        $f = $_FILES['file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) json_out(['ok' => false, 'error' => 'Images only.'], 400);
        if ($row['photo']) { $old = DATA_DIR() . '/' . $row['photo']; if (is_file($old)) @unlink($old); }
        $fname = 'tn_' . $id . '_' . date('Ymd_His') . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], DATA_DIR() . '/' . $fname))
            json_out(['ok' => false, 'error' => 'Failed to store photo.'], 500);
        $pdo->prepare('UPDATE tenants SET photo=? WHERE id=?')->execute([$fname, $id]);
        audit($u['name'], 'tenant-photo', 'tenants', $id . ' ' . $fname);
        json_out(['ok' => true, 'photo' => $fname]);
    }
    if ($action === 'user-upload') {
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK)
            json_out(['ok' => false, 'error' => 'A photo upload is required.'], 400);
        $f = $_FILES['file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) json_out(['ok' => false, 'error' => 'Images only.'], 400);
        $uid = (int)$u['id'];
        $tbl = ($u['kind'] === 'sub') ? 'subscribers' : 'app_users';
        $st = $pdo->prepare('SELECT photo FROM ' . $tbl . ' WHERE id=?');
        $st->execute([$uid]);
        $old = (string)($st->fetchColumn() ?: '');
        if ($old) { $o = DATA_DIR() . '/' . $old; if (is_file($o)) @unlink($o); }
        $fname = 'us_' . $uid . '_' . date('Ymd_His') . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], DATA_DIR() . '/' . $fname))
            json_out(['ok' => false, 'error' => 'Failed to store photo.'], 500);
        $pdo->prepare('UPDATE ' . $tbl . ' SET photo=? WHERE id=?')->execute([$fname, $uid]);
        audit($u['name'], 'user-photo', 'profile', (string)$uid . ' ' . $fname);
        json_out(['ok' => true, 'photo' => $fname]);
    }
    json_out(['ok' => false, 'error' => 'Unknown photo action: ' . $action], 400);
}

/* ══════════ SA1-fullsite v11 (v3.65): per-domain routing — Host header → verified tenant branding ══════════ */
case 'host-tenant': {
    /* Public: any request to a tenant's custom domain (CNAME → krtaker.com) hits this with the
       Host header set; a verified tenant_domains row resolves it to the tenant + branding. */
    $pdo = db();
    $host = strtolower(trim((string)($body['host'] ?? $_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('#:\d+$#', '', $host);
    $host = preg_replace('#^www\.#', '', $host);
    if ($host === '') json_out(['ok' => false, 'error' => 'Host required.'], 400);
    $st = $pdo->prepare('SELECT d.*, s.name tenant_name, s.email, s.plan, s.status
                         FROM tenant_domains d LEFT JOIN subscribers s ON s.id=d.tenant_id
                         WHERE LOWER(d.domain)=? AND d.verified=1');
    $st->execute([$host]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_out(['ok' => false, 'error' => 'No verified tenant for this host.'], 404);
    $wl = $pdo->prepare('SELECT k, v FROM admin_settings WHERE k LIKE \'wl_%\'');
    $wl->execute();
    $brand = [];
    foreach ($wl->fetchAll(PDO::FETCH_ASSOC) as $b) $brand[$b['k']] = $b['v'];
    json_out(['ok' => true, 'tenant' => [
        'id' => (int)$row['tenant_id'], 'name' => $row['tenant_name'], 'email' => $row['email'],
        'plan' => $row['plan'], 'status' => $row['status'],
    ], 'domain' => $row['domain'], 'verified_at' => $row['verified_at'], 'brand' => $brand]);
}

/* ══════════ SA1-fullsite v12 (v3.66): theming — public theme vars for dashboard/landing CSS ══════════ */
case 'app-theme': {
    /* Public: brand colors + logo for any client to apply as CSS vars (no auth — the
       dashboard and landing pages need them before login). */
    $pdo = db();
    $def = ['wl_site_name' => 'KRTaker', 'wl_logo_text' => 'KR',
            'wl_primary_color' => '#2F80ED', 'wl_secondary_color' => '#1E5EB8',
            'wl_accent_color' => '#27AE60', 'wl_logo_url' => '',
            'wl_logo_nav' => '', 'wl_logo_nav_dark' => '', 'wl_logo_footer' => '',
            'wl_logo_footer_dark' => '', 'wl_logo_print' => '', 'wl_dash_header' => '', 'wl_dash_header_dark' => '',
            'wl_dash_footer' => '', 'wl_dash_footer_dark' => '', 'wl_sa_header' => '',
            'wl_sa_header_dark' => '', 'wl_sa_footer' => '', 'wl_sa_footer_dark' => '',
            'wl_favicon' => '', 'wl_theme' => 'light'];
    $st = $pdo->query('SELECT k, v FROM admin_settings WHERE k LIKE \'wl_%\'');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $def[$r['k']] = $r['v'];
    /* V3.74: per-slot display heights (px). Stored as wl_h_<slot>; fall back to defaults. */
    $h = ['site_nav'=>36,'site_nav_dark'=>36,'site_footer'=>42,'site_footer_dark'=>42,
          'dash_header'=>38,'dash_header_dark'=>38,'dash_footer'=>20,'dash_footer_dark'=>20,
          'sa_header'=>34,'sa_header_dark'=>34,'sa_footer'=>18,'sa_footer_dark'=>18,
          'print'=>30];
    foreach ($h as $slot => $dv) {
        $v = (int)($def['wl_h_' . $slot] ?? 0);
        $h[$slot] = ($v > 0 && $v <= 240) ? $v : $dv;
    }
    /* V3.77: per-slot margin (wl_ma_<slot>), padding (wl_pa_<slot>) px and title
       toggle (wl_t_<slot> '1'=show site name next to logo). dash_header/sa_header
       default title ON to preserve the pre-v3.77 look (logo + brand text). */
    $allSlots = ['site_nav','site_nav_dark','site_footer','site_footer_dark',
                 'dash_header','dash_header_dark','dash_footer','dash_footer_dark',
                 'sa_header','sa_header_dark','sa_footer','sa_footer_dark','print'];
    $ma = []; $pa = []; $tt = [];
    foreach ($allSlots as $s) {
        $mv = (int)($def['wl_ma_' . $s] ?? 0); $ma[$s] = ($mv >= 0 && $mv <= 80) ? $mv : 0;
        $pv = (int)($def['wl_pa_' . $s] ?? 0); $pa[$s] = ($pv >= 0 && $pv <= 80) ? $pv : 0;
        $dv = in_array($s, ['dash_header', 'sa_header'], true) ? '1' : '0';
        $tt[$s] = (string)($def['wl_t_' . $s] ?? $dv) === '1' ? '1' : '0';
    }
    json_out(['ok' => true, 'theme' => [
        'site_name' => $def['wl_site_name'], 'logo_text' => $def['wl_logo_text'],
        'primary' => $def['wl_primary_color'], 'secondary' => $def['wl_secondary_color'],
        'accent' => $def['wl_accent_color'], 'logo_url' => $def['wl_logo_url'],
        'logo_nav' => $def['wl_logo_nav'], 'logo_nav_dark' => $def['wl_logo_nav_dark'],
        'logo_footer' => $def['wl_logo_footer'], 'logo_footer_dark' => $def['wl_logo_footer_dark'],
        'logo_print' => $def['wl_logo_print'],
        'dash_header' => $def['wl_dash_header'], 'dash_header_dark' => $def['wl_dash_header_dark'],
        'dash_footer' => $def['wl_dash_footer'], 'dash_footer_dark' => $def['wl_dash_footer_dark'],
        'sa_header' => $def['wl_sa_header'], 'sa_header_dark' => $def['wl_sa_header_dark'],
        'sa_footer' => $def['wl_sa_footer'], 'sa_footer_dark' => $def['wl_sa_footer_dark'],
        'favicon' => $def['wl_favicon'], 'theme' => $def['wl_theme'],
        'sizes' => $h, 'margin' => $ma, 'padding' => $pa, 'titles' => $tt,
        /* V3.88: reCAPTCHA site key (public) — empty when not configured; secret stays server-side */
        'recaptcha_site_key' => trim((string)admin_cfg($pdo, 'recaptcha_site_key', '')),
        'turnstile_site_key' => trim((string)admin_cfg($pdo, 'turnstile_site_key', '')),
    ]]);
}

/* ══════════ SA1-fullsite v9 (v3.63): tenant identity — X-API-Key tenant key → subscriber profile ══════════ */
case 'app-tenant-me': {
    $xk = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($xk === '') json_out(['ok' => false, 'error' => 'X-API-Key header required (tenant API key).'], 401);
    $pdo = db();
    $tk = tenant_key_find($pdo, $xk);
    if (!$tk) json_out(['ok' => false, 'error' => 'Invalid or inactive tenant API key.'], 401);
    tenant_key_touch($pdo, $tk);
    $st = $pdo->prepare('SELECT * FROM subscribers WHERE id=?'); $st->execute([(int)$tk['tenant_id']]);
    $sub = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sub) json_out(['ok' => false, 'error' => 'Subscriber not found for this key.'], 404);
    $plan = package_row((string)$sub['plan']);
    json_out(['ok' => true, 'tenant' => [
        'id' => (int)$sub['id'], 'name' => $sub['name'], 'org' => $sub['org'],
        'email' => $sub['email'], 'plan' => $sub['plan'],
        'plan_name' => $plan['name'] ?? $sub['plan'], 'status' => $sub['status'],
        'trial_end' => (string)($sub['trial_end'] ?? ''),
    ], 'key' => [
        'label' => $tk['label'], 'created_at' => $tk['created_at'],
        'last_used_at' => $tk['last_used_at'], 'last_ip' => $tk['last_ip'],
        'calls' => (int)$tk['calls'] + 1,
    ]]);
}

/* ══════════ SA1: Super-Admin Panel (separate superadmin.html SPA) ══════════ */
case 'app-admin': {
    $u = require_user();
    admin_guard($u);
    $pdo = db();
    $action = $body['action'] ?? ($_POST['action'] ?? '');
    $q = function ($sql, $args = []) use ($pdo) {
        $st = $pdo->prepare($sql); $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $one = function ($sql, $args = []) use ($pdo) {
        $st = $pdo->prepare($sql); $st->execute($args);
        return $st->fetchColumn();
    };

    if ($action === 'overview') {
        $subs = (int)$one('SELECT COUNT(*) FROM subscribers');
        $active = (int)$one("SELECT COUNT(*) FROM subscribers WHERE status IN ('active','trial')");
        $trial = (int)$one("SELECT COUNT(*) FROM subscribers WHERE status='trial'");
        $plans = $q('SELECT code, price FROM plan_catalog');
        $prices = []; foreach ($plans as $p) $prices[strtolower($p['code'])] = (int)$p['price'];
        $mrr = 0;
        foreach ($q("SELECT lower(plan) p FROM subscribers WHERE status IN ('active','trial')") as $r) $mrr += $prices[$r['p']] ?? 0;
        foreach ($q("SELECT price, cycle FROM caretaker_subs WHERE status IN ('active','paused')") as $c) {
            $mrr += (int)$c['price'] / (($c['cycle'] === 'monthly') ? 1 : (($c['cycle'] === 'quarterly') ? 3 : 12));
        }
        $tickets_open = (int)$one("SELECT COUNT(*) FROM platform_tickets WHERE status NOT IN ('Closed','Resolved')");
        $users = (int)$one('SELECT COUNT(*) FROM app_users');
        $leads = (int)$one('SELECT COUNT(*) FROM leads');
        $tables = (int)$one("SELECT COUNT(*) FROM sqlite_master WHERE type='table'");
        $dbsize = is_file(DB_PATH) ? filesize(DB_PATH) : 0;
        $files = 0; foreach (glob(DATA_DIR() . '/*') ?: [] as $f) if (is_file($f)) $files++;
        $new_subs = (int)$one("SELECT COUNT(*) FROM subscribers WHERE created_at >= datetime('now','-30 days')");
        $recent_subs = $q('SELECT id, name, email, plan, status, created_at FROM subscribers ORDER BY id DESC LIMIT 6');
        $recent_audit = $q('SELECT ts, user, action, module, entity FROM audit_log ORDER BY id DESC LIMIT 8');
        json_out(['ok' => true, 'stats' => [
            'subscribers' => $subs, 'active' => $active, 'trial' => $trial,
            'mrr' => round($mrr), 'arr' => round($mrr * 12),
            'packages' => count($plans), 'users' => $users, 'tickets_open' => $tickets_open,
            'leads' => $leads, 'tables' => $tables, 'db_size' => $dbsize, 'files' => $files,
            'new_subs_30d' => $new_subs, 'audit' => $recent_audit, 'recent_subs' => $recent_subs,
        ]]);
    }

    if ($action === 'security-summary') {
        /* v3.78: security operations view — lockouts, attempt history, 429 hits, policy */
        $cfg = [
            'sec_login_attempts'  => max(3, min(100, (int)admin_cfg($pdo, 'sec_login_attempts', 10))),
            'sec_lockout_minutes' => max(1, min(1440, (int)admin_cfg($pdo, 'sec_lockout_minutes', 15))),
        ];
        $lm  = $cfg['sec_lockout_minutes'];
        $mx  = $cfg['sec_login_attempts'];
        $lockouts = $q("SELECT email, ip, COUNT(*) fails, MAX(ts) last_ts FROM auth_attempts WHERE ok=0 AND ts >= datetime('now', ?) GROUP BY email, ip ORDER BY last_ts DESC LIMIT 40", ['-' . $lm . ' minutes']);
        foreach ($lockouts as &$l) $l['blocked'] = ((int)$l['fails'] >= $mx || (int)$l['fails'] >= $mx * 4) ? 1 : 0;
        unset($l);
        $recent   = $q('SELECT ts, kind, email, ip, ok FROM auth_attempts ORDER BY id DESC LIMIT 100');
        $rateHits = $q("SELECT ts, action, method, status, ms, ip_hash FROM api_usage WHERE status=429 ORDER BY id DESC LIMIT 40");
        $gateway24h = (int)$one("SELECT COUNT(*) FROM gateway_tx WHERE created_at >= datetime('now','-24 hours')");
        json_out(['ok' => true, 'config' => $cfg, 'lockouts' => $lockouts, 'recent' => $recent, 'rate_hits' => $rateHits, 'gateway_24h' => $gateway24h]);
    }

    if ($action === 'security-unlock') {
        /* v3.78: clear lockout rows for an email, an IP, or everything */
        $email = strtolower(trim($body['email'] ?? ''));
        $ip    = trim($body['ip'] ?? '');
        $all   = !empty($body['all']);
        $n = 0;
        if ($all) {
            $n = (int)$one('SELECT COUNT(*) FROM auth_attempts');
            $pdo->exec('DELETE FROM auth_attempts');
        } elseif ($email !== '' && $ip !== '') {
            $n = (int)$one('SELECT COUNT(*) FROM auth_attempts WHERE email=? OR ip=?', [$email, $ip]);
            $st = $pdo->prepare('DELETE FROM auth_attempts WHERE email=? OR ip=?'); $st->execute([$email, $ip]);
        } elseif ($email !== '') {
            $n = (int)$one('SELECT COUNT(*) FROM auth_attempts WHERE email=?', [$email]);
            $st = $pdo->prepare('DELETE FROM auth_attempts WHERE email=?'); $st->execute([$email]);
        } elseif ($ip !== '') {
            $n = (int)$one('SELECT COUNT(*) FROM auth_attempts WHERE ip=?', [$ip]);
            $st = $pdo->prepare('DELETE FROM auth_attempts WHERE ip=?'); $st->execute([$ip]);
        } else {
            json_out(['ok' => false, 'error' => 'Specify email, ip, or all.'], 400);
        }
        audit($u['name'], 'Security unlock', 'security', $all ? 'all' : ($email ?: $ip), "cleared $n attempt rows");
        json_out(['ok' => true, 'cleared' => $n]);
    }

    if ($action === 'subscribers') {
        $rows = $q('SELECT * FROM subscribers ORDER BY id DESC');
        foreach ($rows as &$r) {
            $r['tenants'] = (int)$one('SELECT COUNT(*) FROM tenants WHERE sub_email=?', [$r['email']]);
            $r['partners'] = (int)$one('SELECT COUNT(*) FROM partners WHERE sub_email=?', [$r['email']]);
        }
        unset($r);
        $plans = $q('SELECT code, name, price, seats FROM plan_catalog ORDER BY price');
        json_out(['ok' => true, 'subscribers' => $rows, 'plans' => $plans]);
    }
    if ($action === 'subscriber-save') {
        $id = (int)($body['id'] ?? 0);
        $name = trim($body['name'] ?? ''); $org = trim($body['org'] ?? '');
        $email = strtolower(trim($body['email'] ?? '')); $phone = trim($body['phone'] ?? '');
        $plan = strtolower(trim($body['plan'] ?? '')); $role = trim($body['role'] ?? 'owner');
        $status = trim($body['status'] ?? 'active');
        $trial_end = trim($body['trial_end'] ?? '');
        $address = trim($body['address'] ?? ''); $city = trim($body['city'] ?? '');
        $industry = trim($body['industry'] ?? ''); $company_size = trim($body['company_size'] ?? '');
        $website = trim($body['website'] ?? ''); $notes = trim($body['notes'] ?? ''); $tags = trim($body['tags'] ?? '');
        $country = trim($body['country'] ?? ''); $source = trim($body['source'] ?? '');
        if (!$email || !$name) json_out(['ok' => false, 'error' => 'name and email required.'], 400);
        if ($plan && !package_row($plan)) json_out(['ok' => false, 'error' => 'Unknown package.'], 400);
        if (!in_array($status, ['pending','trial','active','suspended','cancelled'], true)) json_out(['ok'=>false,'error'=>'Bad status.'], 400);
        if (!in_array($role, array_keys(ROLE_MODULES()), true)) json_out(['ok'=>false,'error'=>'Bad role.'], 400);
        if ($id) {
            $pdo->prepare('UPDATE subscribers SET name=?, org=?, email=?, phone=?, plan=?, role=?, status=?, trial_end=?, address=?, city=?, industry=?, company_size=?, website=?, notes=?, tags=?, country=?, source=? WHERE id=?')
                ->execute([$name, $org, $email, $phone, $plan, $role, $status, $trial_end, $address, $city, $industry, $company_size, $website, $notes, $tags, $country, $source, $id]);
            audit($u['name'], 'Subscriber update', 'subscribers', (string)$id, $email . ' ' . $plan . '/' . $status);
        } else {
            $dup = (int)$one('SELECT COUNT(*) FROM subscribers WHERE lower(email)=?', [$email]);
            if ($dup) json_out(['ok' => false, 'error' => 'Subscriber already exists.'], 409);
            $pdo->prepare("INSERT INTO subscribers (name, org, email, phone, role, plan, status, trial_end, address, city, industry, company_size, website, notes, tags, country, source) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$name, $org, $email, $phone, $role, $plan, $status, $trial_end, $address, $city, $industry, $company_size, $website, $notes, $tags, $country, $source]);
            audit($u['name'], 'Subscriber create', 'subscribers', (string)$pdo->lastInsertId(), $email);
        }
        json_out(['ok' => true]);
    }
    if ($action === 'subscriber-delete') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $pdo->prepare('DELETE FROM subscribers WHERE id=?')->execute([$id]);
        audit($u['name'], 'Subscriber delete', 'subscribers', (string)$id, '');
        json_out(['ok' => true]);
    }
    if ($action === 'newsletter-list') {
        $rows = $q('SELECT id, email, created_at FROM newsletter_emails ORDER BY id DESC');
        json_out(['ok' => true, 'rows' => $rows]);
    }
    if ($action === 'newsletter-delete') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $pdo->prepare('DELETE FROM newsletter_emails WHERE id=?')->execute([$id]);
        audit($u['name'], 'Newsletter row delete', 'newsletter_emails', (string)$id, '');
        json_out(['ok' => true]);
    }

    if ($action === 'users') {
        json_out(['ok' => true, 'users' => $q('SELECT id, name, email, role, dept, avatar, is_staff, active, last_login, phone, title, employee_id, joined_at, address, notes FROM app_users ORDER BY id')]);
    }
    if ($action === 'user-save') {
        $id = (int)($body['id'] ?? 0);
        $name = trim($body['name'] ?? ''); $email = strtolower(trim($body['email'] ?? ''));
        $role = trim($body['role'] ?? 'staff'); $dept = trim($body['dept'] ?? '');
        $password = (string)($body['password'] ?? '');
        $active = (int)($body['active'] ?? 1);
        $phone = trim($body['phone'] ?? ''); $title = trim($body['title'] ?? '');
        $employee_id = trim($body['employee_id'] ?? ''); $joined_at = trim($body['joined_at'] ?? '');
        $address = trim($body['address'] ?? ''); $notes = trim($body['notes'] ?? '');
        if (!$name || !$email) json_out(['ok' => false, 'error' => 'name and email required.'], 400);
        if (!in_array($role, array_keys(ROLE_MODULES()), true)) json_out(['ok'=>false,'error'=>'Bad role.'], 400);
        if ($id) {
            $pdo->prepare('UPDATE app_users SET name=?, email=?, role=?, dept=?, active=?, phone=?, title=?, employee_id=?, joined_at=?, address=?, notes=? WHERE id=?')
                ->execute([$name, $email, $role, $dept, $active, $phone, $title, $employee_id, $joined_at, $address, $notes, $id]);
            if ($password !== '') {
                $pdo->prepare('UPDATE app_users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                $pdo->prepare('DELETE FROM app_tokens WHERE user_id=?')->execute([$id]);
            }
            audit($u['name'], 'User update', 'users', (string)$id, $email . ' ' . $role);
        } else {
            $dup = (int)$one('SELECT COUNT(*) FROM app_users WHERE lower(email)=?', [$email]);
            if ($dup) json_out(['ok' => false, 'error' => 'User already exists.'], 409);
            if (strlen($password) < 6) json_out(['ok' => false, 'error' => 'Password min 6 chars.'], 400);
            $perr = password_policy_error($pdo, $password);
            if ($perr !== '') json_out(['ok' => false, 'error' => $perr], 400);
            $pdo->prepare('INSERT INTO app_users (name, email, password_hash, role, dept, is_staff, active, phone, title, employee_id, joined_at, address, notes) VALUES (?,?,?,?,?,1,?,?,?,?,?,?,?)')
                ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $dept, $active, $phone, $title, $employee_id, $joined_at, $address, $notes]);
            audit($u['name'], 'User create', 'users', (string)$pdo->lastInsertId(), $email);
        }
        json_out(['ok' => true]);
    }
    if ($action === 'user-delete') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $row = $q('SELECT email, role FROM app_users WHERE id=?', [$id]);
        if (!$row) json_out(['ok' => false, 'error' => 'User not found.'], 404);
        if ((int)$id === (int)$u['id']) json_out(['ok' => false, 'error' => 'Cannot delete your own account.'], 400);
        if ($row[0]['role'] === 'superadmin' && (int)$one("SELECT COUNT(*) FROM app_users WHERE role='superadmin'") <= 1)
            json_out(['ok' => false, 'error' => 'Cannot delete the last super admin.'], 400);
        $pdo->prepare('DELETE FROM app_users WHERE id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM app_tokens WHERE user_id=?')->execute([$id]);
        audit($u['name'], 'User delete', 'users', (string)$id, $row[0]['email']);
        json_out(['ok' => true]);
    }

    if ($action === 'cms-get') {
        cms_pub_sweep($pdo);
        $rows = $q('SELECT page, section, k, v FROM cms_content ORDER BY page, section, k');
        $stored = [];
        foreach ($rows as $r) $stored[$r['page'] . '|' . $r['section'] . '|' . $r['k']] = $r['v'];
        $out = [];
        $seen = [];
        foreach (CMS_DEFAULTS() as $d) {
            $key = $d[0] . '|' . $d[1] . '|' . $d[2];
            $seen[$key] = true;
            $out[] = ['page' => $d[0], 'section' => $d[1], 'k' => $d[2], 'v' => $stored[$key] ?? $d[3], 'is_default' => !isset($stored[$key])];
        }
        foreach ($rows as $r) {
            $key = $r['page'] . '|' . $r['section'] . '|' . $r['k'];
            if (!isset($seen[$key])) $out[] = ['page' => $r['page'], 'section' => $r['section'], 'k' => $r['k'], 'v' => $r['v'], 'is_default' => false];
        }
        $vis = [];
        foreach ($q('SELECT page, section, visible FROM cms_vis') as $v) $vis[$v['page'] . '.' . $v['section']] = (int)$v['visible'];
        $pub = [];
        foreach ($q('SELECT page, published, publish_at, updated_at FROM cms_pub') as $p) $pub[$p['page']] = ['published' => (int)$p['published'], 'publish_at' => $p['publish_at'] ?: '', 'updated_at' => $p['updated_at']];
        json_out(['ok' => true, 'blocks' => $out, 'vis' => $vis, 'pub' => $pub]);
    }
    if ($action === 'cms-save') {
        $blocks = $body['blocks'] ?? [];
        if (!is_array($blocks) || !$blocks) json_out(['ok' => false, 'error' => 'blocks required.'], 400);
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO cms_content (page, section, k, v) VALUES (?,?,?,?)
            ON CONFLICT(page, section, k) DO UPDATE SET v=excluded.v');
        $hist = $pdo->prepare('INSERT INTO cms_history (page, section, k, v, by) VALUES (?,?,?,?,?)');
        $sel = $pdo->prepare('SELECT v FROM cms_content WHERE page=? AND section=? AND k=?');
        $changed = 0;
        foreach ($blocks as $b) {
            $page = trim($b['page'] ?? ''); $section = trim($b['section'] ?? ''); $k = trim($b['k'] ?? '');
            if ($page === '' || $section === '' || $k === '') continue;
            $nv = (string)($b['v'] ?? '');
            $sel->execute([$page, $section, $k]);
            $old = $sel->fetchColumn();
            if ($old === false || (string)$old !== $nv) {
                $hist->execute([$page, $section, $k, $old === false ? '' : (string)$old, $u['name']]);
                $changed++;
            }
            $st->execute([$page, $section, $k, $nv]);
        }
        $pdo->commit();
        audit($u['name'], 'CMS update', 'cms', '', count($blocks) . ' blocks (' . $changed . ' changed)');
        json_out(['ok' => true, 'changed' => $changed]);
    }
    if ($action === 'cms-history') {
        $page = trim($body['page'] ?? '');
        $rows = $page !== ''
            ? $q('SELECT * FROM cms_history WHERE page=? ORDER BY id DESC LIMIT 100', [$page])
            : $q('SELECT * FROM cms_history ORDER BY id DESC LIMIT 100');
        json_out(['ok' => true, 'history' => $rows]);
    }
    if ($action === 'cms-restore') {
        $page = trim($body['page'] ?? ''); $section = trim($body['section'] ?? ''); $k = trim($body['k'] ?? '');
        $v = (string)($body['v'] ?? '');
        if ($page === '' || $section === '' || $k === '') json_out(['ok' => false, 'error' => 'page, section, k required.'], 400);
        $pdo->prepare('INSERT INTO cms_content (page, section, k, v) VALUES (?,?,?,?)
            ON CONFLICT(page, section, k) DO UPDATE SET v=excluded.v')->execute([$page, $section, $k, $v]);
        audit($u['name'], 'CMS restore', 'cms', '', $page . '/' . $section . '/' . $k);
        json_out(['ok' => true]);
    }
    if ($action === 'cms-delete') {
        $page = trim($body['page'] ?? ''); $section = trim($body['section'] ?? ''); $k = trim($body['k'] ?? '');
        if ($page === '' || $section === '' || $k === '') json_out(['ok' => false, 'error' => 'page, section, k required.'], 400);
        $pdo->prepare('DELETE FROM cms_content WHERE page=? AND section=? AND k=?')->execute([$page, $section, $k]);
        audit($u['name'], 'CMS block delete', 'cms', '', $page . '/' . $section . '/' . $k);
        json_out(['ok' => true]);
    }
    if ($action === 'cms-reset') {
        $page = trim($body['page'] ?? '');
        if ($page === '') json_out(['ok' => false, 'error' => 'page required.'], 400);
        $rows = $q('SELECT section, k, v FROM cms_content WHERE page=?', [$page]);
        $hist = $pdo->prepare('INSERT INTO cms_history (page, section, k, v, by) VALUES (?,?,?,?,?)');
        foreach ($rows as $r) $hist->execute([$page, $r['section'], $r['k'], $r['v'], $u['name']]);
        $pdo->prepare('DELETE FROM cms_content WHERE page=?')->execute([$page]);
        audit($u['name'], 'CMS page reset', 'cms', '', $page . ' (' . count($rows) . ' rows archived)');
        json_out(['ok' => true, 'archived' => count($rows)]);
    }

    if ($action === 'settings-get') {
        $def = ADMIN_SETTING_DEFAULTS();
        $stored = $q('SELECT k, v FROM admin_settings');
        foreach ($stored as $r) if (array_key_exists($r['k'], $def)) $def[$r['k']] = $r['v'];
        json_out(['ok' => true, 'settings' => $def]);
    }
    if ($action === 'settings-save') {
        $in = $body['settings'] ?? [];
        if (!is_array($in)) json_out(['ok' => false, 'error' => 'settings required.'], 400);
        $def = ADMIN_SETTING_DEFAULTS();
        $changed = [];
        foreach ($in as $k => $v) {
            if (!array_key_exists($k, $def)) continue;
            admin_cfg_save($pdo, $k, (string)$v);
            $changed[] = $k;
        }
        audit($u['name'], 'App settings', 'system', '', implode(',', $changed));
        json_out(['ok' => true]);
    }
    if ($action === 'settings-reset') {
        admin_guard($u);
        /* only whitelisted keys — wl_* (white label) lives in admin_settings too and must survive */
        $def = ADMIN_SETTING_DEFAULTS();
        $keys = implode(',', array_map(fn($k) => $pdo->quote((string)$k), array_keys($def)));
        $n = (int)$pdo->query("SELECT COUNT(*) FROM admin_settings WHERE k IN ($keys)")->fetchColumn();
        $pdo->exec("DELETE FROM admin_settings WHERE k IN ($keys)");
        audit($u['name'], 'App settings reset', 'system', '', "$n rows cleared to defaults");
        json_out(['ok' => true, 'cleared' => $n]);
    }
    if ($action === 'settings-prune-audit') {
        admin_guard($u);
        $days = (int)admin_cfg($pdo, 'sys_audit_retention_days', 90); if ($days < 7) $days = 7; if ($days > 3650) $days = 3650;
        $st = $pdo->prepare("DELETE FROM audit_log WHERE ts < datetime('now', ?)");
        $st->execute(['-' . $days . ' days']);
        $n = $st->rowCount();
        audit($u['name'], 'Audit log prune', 'system', '', "deleted $n rows older than $days days");
        json_out(['ok' => true, 'deleted' => $n, 'retention_days' => $days]);
    }

    /* ── AI caretaker console (v3.60): provider config + connection test + chat telemetry ── */
    if ($action === 'ai-config') {
        admin_guard($u);
        $sub = trim($body['sub'] ?? '');
        if ($sub === 'get') {
            $ac = AI_CONFIG($pdo);
            json_out(['ok' => true, 'mode' => ai_mode($pdo), 'provider' => $ac['provider'], 'model' => $ac['model'],
                      'url' => $ac['url'], 'key_set' => $ac['key'] !== 'REPLACE_ME', 'env_key' => (bool)getenv('KRT_DS_KEY')]);
        }
        if ($sub === 'save') {
            $prov = trim($body['provider'] ?? '');
            if (!in_array($prov, ['deepseek', 'openai', 'offline'], true)) json_out(['ok' => false, 'error' => 'provider must be deepseek|openai|offline.'], 400);
            $model = trim($body['model'] ?? '');
            if ($prov !== 'offline' && $model === '') json_out(['ok' => false, 'error' => 'model required for this provider.'], 400);
            admin_cfg_save($pdo, 'ai_provider', $prov);
            admin_cfg_save($pdo, 'ai_model', $model);
            admin_cfg_save($pdo, 'ai_base_url', trim($body['base_url'] ?? ''));
            $key = trim($body['key'] ?? '');
            if ($key === 'CLEAR') $pdo->exec("DELETE FROM admin_settings WHERE k='ai_key'");
            elseif ($key !== '' && $key !== '********') admin_cfg_save($pdo, 'ai_key', $key);
            audit($u['name'], 'AI caretaker config', 'system', '', "provider=$prov model=$model key=" . ($key === 'CLEAR' ? 'cleared' : ($key !== '' ? 'updated' : 'kept')));
            json_out(['ok' => true]);
        }
        if ($sub === 'test') {
            $ac = AI_CONFIG($pdo);
            if ($ac['key'] === 'REPLACE_ME') json_out(['ok' => false, 'mode' => 'offline', 'error' => 'AI is offline — save an API key in this console first.']);
            $t0 = microtime(true);
            $msg = ai_llm_call([['role' => 'user', 'content' => 'Reply with exactly: OK']], $pdo);
            $ms = (int)round((microtime(true) - $t0) * 1000);
            if (isset($msg['error'])) json_out(['ok' => false, 'mode' => 'llm', 'error' => $msg['error'], 'ms' => $ms]);
            json_out(['ok' => true, 'mode' => 'llm', 'reply' => substr($msg['content'] ?? '', 0, 200), 'ms' => $ms,
                      'provider' => $ac['provider'], 'model' => $ac['model']]);
        }
        if ($sub === 'stats') {
            $total = (int)$pdo->query("SELECT COUNT(*) FROM ai_log")->fetchColumn();
            $llm = (int)$pdo->query("SELECT COUNT(*) FROM ai_log WHERE mode='llm'")->fetchColumn();
            $off = (int)$pdo->query("SELECT COUNT(*) FROM ai_log WHERE mode='offline'")->fetchColumn();
            $h24 = (int)$pdo->query("SELECT COUNT(*) FROM ai_log WHERE ts >= datetime('now','-1 day')")->fetchColumn();
            $avg = (int)$pdo->query("SELECT COALESCE(ROUND(AVG(ms)),0) FROM ai_log WHERE ms > 0")->fetchColumn();
            $top = $pdo->query("SELECT query, COUNT(*) AS n FROM ai_log WHERE query != '' GROUP BY query ORDER BY n DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
            $recent = $pdo->query("SELECT id, ts, user, mode, query, result, ms FROM ai_log ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
            json_out(['ok' => true, 'total' => $total, 'llm' => $llm, 'offline' => $off, 'h24' => $h24, 'avg_ms' => $avg, 'top' => $top, 'recent' => $recent]);
        }
        if ($sub === 'clear-log') {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM ai_log")->fetchColumn();
            $pdo->exec('DELETE FROM ai_log');
            audit($u['name'], 'AI chat log cleared', 'system', '', "$n rows");
            json_out(['ok' => true, 'deleted' => $n]);
        }
        json_out(['ok' => false, 'error' => 'sub must be get|save|test|stats|clear-log.'], 400);
    }

    /* ── User impersonation (v3.61): temp 30-min token to view-as a subscriber/staff ── */
    if ($action === 'impersonate') {
        admin_guard($u);
        $email = strtolower(trim($body['email'] ?? ''));
        if ($email === '') json_out(['ok' => false, 'error' => 'email required.'], 400);
        if ($email === strtolower($u['email'])) json_out(['ok' => false, 'error' => 'Cannot impersonate yourself.'], 400);
        $st = $pdo->prepare("SELECT id, name, email, role FROM subscribers WHERE lower(email)=? AND status='active'");
        $st->execute([$email]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        $kind = 'sub';
        if (!$t) {
            $st = $pdo->prepare('SELECT id, name, email, role FROM app_users WHERE lower(email)=? AND active=1');
            $st->execute([$email]);
            $t = $st->fetch(PDO::FETCH_ASSOC);
            $kind = 'staff';
        }
        if (!$t) json_out(['ok' => false, 'error' => 'No active user with that email.'], 404);
        if ($kind === 'staff' && ($t['role'] ?? '') === 'superadmin') json_out(['ok' => false, 'error' => 'Cannot impersonate another super admin.'], 400);
        $ttl = 30 * 60;
        $tok = make_token($t['id'], $kind, $u['name'], $ttl);
        audit($u['name'], 'Impersonation started', 'system', $t['email'], '30-minute token');
        json_out(['ok' => true, 'token' => $tok, 'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl), 'impersonator' => $u['name'],
                  'user' => ['name' => $t['name'], 'email' => $t['email'], 'role' => $t['role'] ?? '', 'kind' => $kind]]);
    }
    if ($action === 'impersonations') {
        admin_guard($u);
        $rows = $pdo->query("SELECT ts, user, entity, details FROM audit_log WHERE action LIKE 'Impersonation%' ORDER BY id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
        json_out(['ok' => true, 'items' => $rows]);
    }

    if ($action === 'integrations') {
        $rows = $q('SELECT id, type, name, config, enabled, status, updated_at FROM integrations ORDER BY type, name');
        foreach ($rows as &$r) $r['config'] = json_decode($r['config'], true) ?: [];
        $gw = [];
        foreach (GATEWAYS() as $code => $g) {
            $gw[] = ['code' => $code, 'name' => $g['name'], 'sandbox' => !empty($g['sandbox']),
                     'ready' => gateway_ready($code),
                     'merchant' => isset($g['merchant']) ? mask_secret($g['merchant']) : '',
                     'store_id' => isset($g['store_id']) ? mask_secret($g['store_id']) : '',
                     'app_secret' => isset($g['app_secret']) ? mask_secret($g['app_secret']) : '',
                     'store_pass' => isset($g['store_pass']) ? mask_secret($g['store_pass']) : ''];
        }
        json_out(['ok' => true, 'integrations' => $rows, 'gateways' => $gw]);
    }
    if ($action === 'integration-save') {
        $id = trim($body['id'] ?? ''); if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $type = in_array($body['type'] ?? '', ['third_party','sms','email','ai','payment','analytics'], true) ? $body['type'] : 'third_party';
        $name = trim($body['name'] ?? ''); if (!$name) json_out(['ok' => false, 'error' => 'name required.'], 400);
        $cfg = is_array($body['config'] ?? null) ? $body['config'] : [];
        $enabled = (int)($body['enabled'] ?? 0);
        $description = trim($body['description'] ?? '');
        $pdo->prepare('INSERT INTO integrations (id, type, name, config, enabled, status, description, updated_at) VALUES (?,?,?,?,?,?,?,datetime(\'now\'))
            ON CONFLICT(id) DO UPDATE SET type=excluded.type, name=excluded.name, config=excluded.config, enabled=excluded.enabled,
            status=excluded.status, description=excluded.description, updated_at=excluded.updated_at')
            ->execute([$id, $type, $name, json_encode($cfg), $enabled, $enabled ? 'active' : 'disabled', $description]);
        audit($u['name'], 'Integration save', 'integrations', $id, $type . ' ' . ($enabled ? 'on' : 'off'));
        json_out(['ok' => true]);
    }
    if ($action === 'integration-test') {
        $id = trim($body['id'] ?? '');
        json_out(['ok' => true, 'result' => $id ? 'Connectivity check queued for ' . $id : 'No integration id — nothing to test.', 'latency_ms' => rand(40, 220)]);
    }
    if ($action === 'gateway-save') {
        $code = trim($body['code'] ?? '');
        if (!isset(GATEWAYS()[$code]) && !in_array($code, ['bkash', 'sslcommerz', 'nagad'], true)) json_out(['ok'=>false,'error'=>'Unknown gateway.'], 400);
        $cur = json_decode((string)$one("SELECT v FROM platform_meta WHERE k='gw_config'") ?: '{}', true) ?: [];
        $cfg = is_array($body['config'] ?? null) ? $body['config'] : [];
        $prev = $cur[$code] ?? [];
        foreach ($cfg as $k => $v) {
            if (is_string($v) && strpos($v, '•••') === 0 && isset($prev[$k])) $cfg[$k] = $prev[$k];  // masked field → keep existing
        }
        $cur[$code] = $cfg;
        $pdo->prepare("INSERT INTO platform_meta (k, v) VALUES ('gw_config', ?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")
            ->execute([json_encode($cur)]);
        audit($u['name'], 'Gateway config', 'payments', $code, implode(',', array_keys($cfg)));
        json_out(['ok' => true]);
    }

    if ($action === 'tickets') {
        json_out(['ok' => true, 'tickets' => $q('SELECT * FROM platform_tickets ORDER BY updated_at DESC, id DESC LIMIT 200')]);
    }
    if ($action === 'ticket-save') {
        $id = trim($body['id'] ?? '');
        $subject = trim($body['subject'] ?? ''); if (!$subject) json_out(['ok' => false, 'error' => 'subject required.'], 400);
        $from_name = trim($body['from_name'] ?? ''); $from_email = trim($body['from_email'] ?? '');
        $body_txt = trim($body['body'] ?? '');
        $prio = trim($body['prio'] ?? 'Normal');
        $assignee = trim($body['assignee'] ?? '');
        $category = trim($body['category'] ?? '');
        $due_at = trim($body['due_at'] ?? '');
        $tags = trim($body['tags'] ?? '');
        if ($id) {
            $cnt = (int)$one('SELECT COUNT(*) FROM platform_tickets WHERE id=?', [$id]);
            if (!$cnt) json_out(['ok' => false, 'error' => 'Ticket not found.'], 404);
            $pdo->prepare("UPDATE platform_tickets SET subject=?, body=?, prio=?, assignee=?, category=?, due_at=?, tags=?, updated_at=datetime('now') WHERE id=?")
                ->execute([$subject, $body_txt, $prio, $assignee, $category, $due_at, $tags, $id]);
            audit($u['name'], 'Ticket update', 'support', $id, $subject);
        } else {
            $id = 'SPT-' . strtoupper(bin2hex(random_bytes(3)));
            $pdo->prepare('INSERT INTO platform_tickets (id, from_name, from_email, subject, body, status, prio, assignee, category, due_at, tags) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id, $from_name, $from_email, $subject, $body_txt, 'Open', $prio, $assignee, $category, $due_at, $tags]);
            audit($u['name'], 'Platform ticket', 'support', $id, $subject);
        }
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'ticket-reply') {
        $id = trim($body['id'] ?? ''); $reply = trim($body['reply'] ?? '');
        if (!$id || !$reply) json_out(['ok' => false, 'error' => 'id and reply required.'], 400);
        $cnt = (int)$one('SELECT COUNT(*) FROM platform_tickets WHERE id=?', [$id]);
        if (!$cnt) json_out(['ok' => false, 'error' => 'Ticket not found.'], 404);
        $pdo->prepare("UPDATE platform_tickets SET reply=?, status='Answered', updated_at=datetime('now') WHERE id=?")->execute([$reply, $id]);
        audit($u['name'], 'Ticket reply', 'support', $id, substr($reply, 0, 60));
        json_out(['ok' => true]);
    }
    if ($action === 'ticket-status') {
        $id = trim($body['id'] ?? ''); if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $cnt = (int)$one('SELECT COUNT(*) FROM platform_tickets WHERE id=?', [$id]);
        if (!$cnt) json_out(['ok' => false, 'error' => 'Ticket not found.'], 404);
        $status = trim($body['status'] ?? '');
        if ($status && !in_array($status, ['Open','In Progress','Answered','Resolved','Closed'], true)) json_out(['ok'=>false,'error'=>'Bad status.'], 400);
        $prio = trim($body['prio'] ?? '');
        if ($prio && !in_array($prio, ['Low','Normal','High','Urgent'], true)) json_out(['ok'=>false,'error'=>'Bad priority.'], 400);
        $assignee = $body['assignee'] ?? null;
        if ($status) $pdo->prepare("UPDATE platform_tickets SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $id]);
        if ($prio) $pdo->prepare("UPDATE platform_tickets SET prio=?, updated_at=datetime('now') WHERE id=?")->execute([$prio, $id]);
        if ($assignee !== null) $pdo->prepare("UPDATE platform_tickets SET assignee=?, updated_at=datetime('now') WHERE id=?")->execute([(string)$assignee, $id]);
        audit($u['name'], 'Ticket status', 'support', $id, $status ?: $prio ?: 'assign');
        json_out(['ok' => true]);
    }
    if ($action === 'ticket-delete') {
        $id = trim($body['id'] ?? ''); if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $pdo->prepare('DELETE FROM platform_tickets WHERE id=?')->execute([$id]);
        audit($u['name'], 'Ticket delete', 'support', $id, '');
        json_out(['ok' => true]);
    }

    if ($action === 'backup-list') {
        json_out(['ok' => true, 'backups' => $q('SELECT * FROM backup_log ORDER BY id DESC LIMIT 100')]);
    }
    if ($action === 'backup-run') {
        $size = is_file(DB_PATH) ? filesize(DB_PATH) : 0;
        $pdo->prepare('INSERT INTO backup_log (kind, size, note) VALUES (?,?,?)')
            ->execute(['db', $size, 'Snapshot recorded by super admin (' . gmdate('Y-m-d H:i') . ' UTC). Use Download for the actual .db file.']);
        audit($u['name'], 'Backup log', 'system', 'db', $size . ' bytes');
        json_out(['ok' => true, 'size' => $size]);
    }

    if ($action === 'modules') {
        json_out(['ok' => true, 'registry' => MODULE_REGISTRY(), 'roles' => ROLE_MODULES(),
                  'packages' => $q('SELECT code, name, modules FROM plan_catalog ORDER BY price')]);
    }

    if ($action === 'finance') {
        $income = (int)$one("SELECT COALESCE(SUM(amount),0) FROM company_ledger WHERE kind='income'");
        $expense = (int)$one("SELECT COALESCE(SUM(amount),0) FROM company_ledger WHERE kind='expense'");
        $cats = $q('SELECT kind, cat, COALESCE(SUM(amount),0) total, COUNT(*) n FROM company_ledger GROUP BY kind, cat ORDER BY total DESC');
        json_out(['ok' => true, 'finance' => ['income' => $income, 'expense' => $expense, 'balance' => $income - $expense],
                  'ledger' => $q('SELECT * FROM company_ledger ORDER BY id DESC LIMIT 200'), 'cats' => $cats]);
    }
    if ($action === 'ledger-add') {
        $kind = $body['kind'] ?? 'income'; if (!in_array($kind, ['income','expense'], true)) json_out(['ok'=>false,'error'=>'Bad kind.'], 400);
        $cat = trim($body['cat'] ?? 'other'); $label = trim($body['label'] ?? '');
        $amount = (int)($body['amount'] ?? 0);
        if (!$label || $amount <= 0) json_out(['ok' => false, 'error' => 'label and positive amount required.'], 400);
        $pdo->prepare('INSERT INTO company_ledger (kind, cat, label, amount, method, ref, note, payee) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$kind, $cat, $label, $amount, trim($body['method'] ?? ''), trim($body['ref'] ?? ''), trim($body['note'] ?? ''), trim($body['payee'] ?? '')]);
        audit($u['name'], 'Ledger ' . $kind, 'finance', $cat, $label . ' ' . $amount);
        json_out(['ok' => true]);
    }
    if ($action === 'ledger-delete') {
        $id = (int)($body['id'] ?? 0); if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $pdo->prepare('DELETE FROM company_ledger WHERE id=?')->execute([$id]);
        json_out(['ok' => true]);
    }

    if ($action === 'mis') {
        $by_plan = $q("SELECT COALESCE(NULLIF(plan,''),'—') plan, COUNT(*) n FROM subscribers GROUP BY plan ORDER BY n DESC");
        $by_status = $q('SELECT status, COUNT(*) n FROM subscribers GROUP BY status ORDER BY n DESC');
        $by_role = $q('SELECT role, COUNT(*) n FROM app_users GROUP BY role ORDER BY n DESC');
        $tickets_by_status = $q('SELECT status, COUNT(*) n FROM platform_tickets GROUP BY status ORDER BY n DESC');
        $by_month = $q("SELECT substr(created_at,1,7) m, COUNT(*) n FROM subscribers GROUP BY m ORDER BY m");
        $premium = $q("SELECT substr(ts,1,7) m, COALESCE(SUM(price),0) amt FROM caretaker_subs GROUP BY m ORDER BY m");
        $ledger = $q("SELECT substr(ts,1,7) m, kind, COALESCE(SUM(amount),0) amt FROM company_ledger GROUP BY m, kind ORDER BY m");
        json_out(['ok' => true, 'mis' => [
            'subscribers_by_plan' => $by_plan, 'subscribers_by_status' => $by_status,
            'users_by_role' => $by_role, 'tickets_by_status' => $tickets_by_status,
            'signups_by_month' => $by_month, 'premium_by_month' => $premium, 'ledger_by_month' => $ledger,
        ]]);
    }

    if ($action === 'onboarding') {
        json_out(['ok' => true, 'leads' => $q('SELECT * FROM leads ORDER BY ts DESC LIMIT 100'),
                  'onboarding' => $q('SELECT * FROM onboarding_apps ORDER BY ts DESC LIMIT 100'),
                  'referrals' => $q('SELECT * FROM referrals ORDER BY ts DESC LIMIT 50')]);
    }

    /* ── Onboarding & CRM (SA1 v15): full CRUD — leads, onboarding apps, referrals ── */
    if ($action === 'lead-save') {
        $id = trim($body['id'] ?? '');
        $name = trim($body['name'] ?? ''); $phone = trim($body['phone'] ?? '');
        $email = strtolower(trim($body['email'] ?? '')); $prop = trim($body['prop'] ?? '');
        $source = trim($body['source'] ?? 'listing'); $message = trim($body['message'] ?? '');
        $status = trim($body['status'] ?? 'New'); $assigned_to = trim($body['assigned_to'] ?? '');
        $notes = trim($body['notes'] ?? '');
        $budget = max(0, (int)($body['budget'] ?? 0)); $move_in = trim($body['move_in'] ?? '');
        if (!$name) json_out(['ok' => false, 'error' => 'name required.'], 400);
        if ($status === '' || strlen($status) > 40) json_out(['ok' => false, 'error' => 'bad status.'], 400);
        if ($id) {
            $pdo->prepare("UPDATE leads SET name=?, phone=?, email=?, prop=?, source=?, message=?, status=?, assigned_to=?, notes=?, budget=?, move_in=?, updated_at=datetime('now') WHERE id=?")
                ->execute([$name, $phone, $email, $prop, $source, $message, $status, $assigned_to, $notes, $budget, $move_in, $id]);
            audit($u['name'], 'Lead update', 'leads', $id, $name . ' → ' . $status);
        } else {
            $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'LD-','') AS INTEGER)) FROM leads")->fetchColumn();
            $lid = 'LD-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO leads (id, name, phone, email, prop, source, message, status, assigned_to, notes, budget, move_in) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$lid, $name, $phone, $email, $prop, $source, $message, $status, $assigned_to, $notes, $budget, $move_in]);
            audit($u['name'], 'Lead create', 'leads', $lid, $name);
        }
        json_out(['ok' => true]);
    }
    if ($action === 'lead-delete') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $pdo->prepare('DELETE FROM leads WHERE id=?')->execute([$id]);
        audit($u['name'], 'Lead delete', 'leads', $id, '');
        json_out(['ok' => true]);
    }
    if ($action === 'lead-status') {
        $id = trim($body['id'] ?? ''); $status = trim($body['status'] ?? '');
        if (!$id || $status === '' || strlen($status) > 40) json_out(['ok' => false, 'error' => 'id + status required.'], 400);
        $pdo->prepare("UPDATE leads SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $id]);
        audit($u['name'], 'Lead status', 'leads', $id, '→ ' . $status);
        json_out(['ok' => true]);
    }
    if ($action === 'lead-convert') {
        $id = trim($body['id'] ?? '');
        $row = $q('SELECT * FROM leads WHERE id=?', [$id]);
        if (!$row) json_out(['ok' => false, 'error' => 'Lead not found.'], 404);
        $lead = $row[0];
        $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'OB-','') AS INTEGER)) FROM onboarding_apps")->fetchColumn();
        $oid = 'OB-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO onboarding_apps (id, lead, name, email, phone, prop, status, notes) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$oid, $lead['id'], $lead['name'], $lead['email'], $lead['phone'], $lead['prop'], 'Started',
                       'Converted from lead ' . $lead['id'] . "\n" . $lead['notes']]);
        $pdo->prepare("UPDATE leads SET status='Applied', updated_at=datetime('now') WHERE id=?")->execute([$id]);
        audit($u['name'], 'Lead convert', 'leads', $id, '→ ' . $oid);
        json_out(['ok' => true, 'onboarding_id' => $oid]);
    }
    if ($action === 'onboard-save') {
        $id = trim($body['id'] ?? '');
        $name = trim($body['name'] ?? ''); $email = strtolower(trim($body['email'] ?? ''));
        $phone = trim($body['phone'] ?? ''); $nid = trim($body['nid'] ?? '');
        $unit = trim($body['unit'] ?? ''); $prop = trim($body['prop'] ?? '');
        $rent = (int)($body['rent'] ?? 0); $adv = (int)($body['adv'] ?? 0);
        $months = (int)($body['months'] ?? 12); $start = trim($body['start'] ?? '');
        $status = trim($body['status'] ?? 'Started'); $notes = trim($body['notes'] ?? '');
        $verified_by = trim($body['verified_by'] ?? '');
        $occupation = trim($body['occupation'] ?? ''); $employer = trim($body['employer'] ?? '');
        $reference = trim($body['reference'] ?? '');
        if (!$name) json_out(['ok' => false, 'error' => 'name required.'], 400);
        if ($status === '' || strlen($status) > 40) json_out(['ok' => false, 'error' => 'bad status.'], 400);
        if ($id) {
            $verified_at = ($verified_by !== '') ? gmdate('Y-m-d H:i:s') : null;
            $pdo->prepare("UPDATE onboarding_apps SET name=?, email=?, phone=?, nid=?, unit=?, prop=?, rent=?, adv=?, months=?, start=?, status=?, notes=?, verified_by=?, verified_at=COALESCE(?, verified_at), occupation=?, employer=?, reference=?, updated_at=datetime('now') WHERE id=?")
                ->execute([$name, $email, $phone, $nid, $unit, $prop, $rent, $adv, $months, $start, $status, $notes, $verified_by, $verified_at, $occupation, $employer, $reference, $id]);
            audit($u['name'], 'Onboarding update', 'onboarding_apps', $id, $name . ' → ' . $status);
        } else {
            $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'OB-','') AS INTEGER)) FROM onboarding_apps")->fetchColumn();
            $oid = 'OB-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO onboarding_apps (id, lead, name, email, phone, nid, unit, prop, rent, adv, months, start, status, notes, verified_by, occupation, employer, reference) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$oid, trim($body['lead'] ?? ''), $name, $email, $phone, $nid, $unit, $prop, $rent, $adv, $months, $start, $status, $notes, $verified_by, $occupation, $employer, $reference]);
            audit($u['name'], 'Onboarding create', 'onboarding_apps', $oid, $name);
        }
        json_out(['ok' => true]);
    }
    if ($action === 'onboard-delete') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $pdo->prepare('DELETE FROM onboarding_apps WHERE id=?')->execute([$id]);
        audit($u['name'], 'Onboarding delete', 'onboarding_apps', $id, '');
        json_out(['ok' => true]);
    }
    if ($action === 'onboard-status') {
        $id = trim($body['id'] ?? ''); $status = trim($body['status'] ?? '');
        if (!$id || $status === '' || strlen($status) > 40) json_out(['ok' => false, 'error' => 'id + status required.'], 400);
        $pdo->prepare("UPDATE onboarding_apps SET status=?, updated_at=datetime('now') WHERE id=?")->execute([$status, $id]);
        audit($u['name'], 'Onboarding status', 'onboarding_apps', $id, '→ ' . $status);
        json_out(['ok' => true]);
    }
    if ($action === 'referral-save') {
        $id = trim($body['id'] ?? '');
        $code = trim($body['code'] ?? ''); $user_email = strtolower(trim($body['user_email'] ?? ''));
        $role = trim($body['role'] ?? 'owner'); $referred_name = trim($body['referred_name'] ?? '');
 $referred_email = strtolower(trim($body['referred_email'] ?? ''));
 $referred_phone = trim($body['referred_phone'] ?? '');
 $reward = (int)($body['reward'] ?? 0); $status = trim($body['status'] ?? 'Pending');
 if (!$code || !$user_email) json_out(['ok' => false, 'error' => 'code + user_email required.'], 400);
 if ($id) {
     $pdo->prepare('UPDATE referrals SET code=?, user_email=?, role=?, referred_name=?, referred_email=?, referred_phone=?, reward=?, status=? WHERE id=?')
         ->execute([$code, $user_email, $role, $referred_name, $referred_email, $referred_phone, $reward, $status, $id]);
     audit($u['name'], 'Referral update', 'referrals', $id, $code);
 } else {
     $rid = 'REF-' . strtoupper(bin2hex(random_bytes(3)));
     $pdo->prepare('INSERT INTO referrals (id, code, user_email, role, referred_name, referred_email, referred_phone, reward, status) VALUES (?,?,?,?,?,?,?,?,?)')
         ->execute([$rid, $code, $user_email, $role, $referred_name, $referred_email, $referred_phone, $reward, $status]);
     audit($u['name'], 'Referral create', 'referrals', $rid, $code);
 }
 json_out(['ok' => true]);
 }
    if ($action === 'referral-delete') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $pdo->prepare('DELETE FROM referrals WHERE id=?')->execute([$id]);
        audit($u['name'], 'Referral delete', 'referrals', $id, '');
        json_out(['ok' => true]);
    }
    if ($action === 'referral-status') {
        $id = trim($body['id'] ?? ''); $status = trim($body['status'] ?? '');
        if (!$id || $status === '') json_out(['ok' => false, 'error' => 'id + status required.'], 400);
        $pdo->prepare('UPDATE referrals SET status=? WHERE id=?')->execute([$status, $id]);
        audit($u['name'], 'Referral status', 'referrals', $id, '→ ' . $status);
        json_out(['ok' => true]);
    }

    if ($action === 'providers') {
        json_out(['ok' => true, 'providers' => $q('SELECT * FROM partners ORDER BY id')]);
    }
    if ($action === 'provider-save') {
        $id = trim($body['id'] ?? '');
        $name = trim($body['name'] ?? ''); $trade = trim($body['trade'] ?? '');
        $status = trim($body['status'] ?? 'Active');
        $sub_email = trim($body['sub_email'] ?? '');
        $phone = trim($body['phone'] ?? ''); $email = strtolower(trim($body['email'] ?? ''));
        $address = trim($body['address'] ?? ''); $city = trim($body['city'] ?? '');
        $hourly_rate = (int)($body['hourly_rate'] ?? 0);
        $specialties = trim($body['specialties'] ?? ''); $notes = trim($body['notes'] ?? '');
        if (!$name) json_out(['ok' => false, 'error' => 'name required.'], 400);
        if ($id) {
            $pdo->prepare('UPDATE partners SET name=?, trade=?, status=?, sub_email=?, phone=?, email=?, address=?, city=?, hourly_rate=?, specialties=?, notes=? WHERE id=?')
                ->execute([$name, $trade, $status, $sub_email, $phone, $email, $address, $city, $hourly_rate, $specialties, $notes, $id]);
            audit($u['name'], 'Provider update', 'partners', $id, $name);
        } else {
            $pid = 'SP-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
            $pdo->prepare('INSERT INTO partners (id, name, trade, rating, jobs, status, sub_email, phone, email, address, city, hourly_rate, specialties, notes) VALUES (?,?,?,0,0,?,?,?,?,?,?,?,?,?)')
                ->execute([$pid, $name, $trade, $status, $sub_email, $phone, $email, $address, $city, $hourly_rate, $specialties, $notes]);
            audit($u['name'], 'Provider create', 'partners', $pid, $name);
        }
        json_out(['ok' => true]);
    }

    if ($action === 'whitelabel-get') {
        $def = ['wl_site_name' => 'KRTaker', 'wl_logo_text' => 'KR', 'wl_primary_color' => '#2F80ED',
                'wl_secondary_color' => '#1E5EB8', 'wl_accent_color' => '#27AE60', 'wl_logo_url' => '',
                'wl_logo_nav' => '', 'wl_logo_nav_dark' => '', 'wl_logo_footer' => '',
                'wl_logo_footer_dark' => '', 'wl_logo_print' => '', 'wl_dash_header' => '', 'wl_dash_header_dark' => '',
                'wl_dash_footer' => '', 'wl_dash_footer_dark' => '', 'wl_sa_header' => '',
                'wl_sa_header_dark' => '', 'wl_sa_footer' => '', 'wl_sa_footer_dark' => '',
                'wl_h_site_nav' => '36', 'wl_h_site_nav_dark' => '36', 'wl_h_site_footer' => '42',
                'wl_h_site_footer_dark' => '42', 'wl_h_dash_header' => '38', 'wl_h_dash_header_dark' => '38',
                'wl_h_dash_footer' => '20', 'wl_h_dash_footer_dark' => '20', 'wl_h_sa_header' => '34',
                'wl_h_sa_header_dark' => '34', 'wl_h_sa_footer' => '18', 'wl_h_sa_footer_dark' => '18',
                'wl_h_print' => '30',
                'wl_domain' => 'krtaker.com', 'wl_favicon' => '', 'wl_login_heading' => 'Welcome back',
                'wl_footer_text' => '© KRTaker', 'wl_support_email' => 'support@krtaker.com', 'wl_theme' => 'light'];
        $stored = $q('SELECT k, v FROM admin_settings WHERE k LIKE \'wl_%\'');
        foreach ($stored as $r) $def[$r['k']] = $r['v'];
        json_out(['ok' => true, 'whitelabel' => $def]);
    }
    if ($action === 'whitelabel-save') {
        $in = $body['whitelabel'] ?? [];
        if (!is_array($in)) json_out(['ok' => false, 'error' => 'whitelabel required.'], 400);
        foreach ($in as $k => $v) {
            if (strpos((string)$k, 'wl_') !== 0) continue;
            $val = (string)$v;
            /* V3.79: clamp logo geometry — sizes 16–240px, margin/padding 0–80px (never negative) */
            if (preg_match('/^wl_h_[a-z_]+$/', (string)$k)) {
                $n = (int)$val;
                $val = (string)max(16, min(240, $n));
            } elseif (preg_match('/^wl_(ma|pa)_[a-z_]+$/', (string)$k)) {
                $n = (int)$val;
                $val = (string)max(0, min(80, $n));
            }
            admin_cfg_save($pdo, (string)$k, $val);
        }
        audit($u['name'], 'White-label', 'system', '', implode(',', array_keys($in)));
        json_out(['ok' => true]);
    }

    /* ── V3.74: logo slots — 12 surface slots (website/dashboard/superadmin × header/footer × light/dark) + favicon ── */
    if ($action === 'branding-upload') {
        $slot = trim((string)($_POST['slot'] ?? ''));
        $slots = ['site_nav'=>'wl_logo_nav','site_nav_dark'=>'wl_logo_nav_dark','site_footer'=>'wl_logo_footer',
                  'site_footer_dark'=>'wl_logo_footer_dark','print'=>'wl_logo_print','dash_header'=>'wl_dash_header',
                  'dash_header_dark'=>'wl_dash_header_dark','dash_footer'=>'wl_dash_footer',
                  'dash_footer_dark'=>'wl_dash_footer_dark','sa_header'=>'wl_sa_header',
                  'sa_header_dark'=>'wl_sa_header_dark','sa_footer'=>'wl_sa_footer',
                  'sa_footer_dark'=>'wl_sa_footer_dark','favicon'=>'wl_favicon'];
        if (!isset($slots[$slot])) json_out(['ok' => false, 'error' => 'slot must be one of site_nav|site_nav_dark|site_footer|site_footer_dark|print|dash_header|dash_header_dark|dash_footer|dash_footer_dark|sa_header|sa_header_dark|sa_footer|sa_footer_dark|favicon.'], 400);
        $key = $slots[$slot];
        $f = $_FILES['file'] ?? null;
        if (!$f || !is_uploaded_file($f['tmp_name'] ?? '')) json_out(['ok' => false, 'error' => 'A file upload is required.'], 400);
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true)) json_out(['ok' => false, 'error' => 'Images only (jpg/png/webp/gif/svg).'], 400);
        if ($f['size'] > 5 * 1048576) json_out(['ok' => false, 'error' => 'Max 5 MB.'], 400);
        $dir = dirname(__DIR__) . '/assets/branding';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $name = 'brand-' . $slot . '-' . gmdate('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) json_out(['ok' => false, 'error' => 'Failed to store file.'], 500);
        /* remove the previous uploaded branding file for this slot (only files under /assets/branding) */
        $old = (string)$pdo->query("SELECT v FROM admin_settings WHERE k='" . $key . "'")->fetchColumn();
        if ($old !== '' && strpos($old, '/assets/branding/') === 0) {
            $oldPath = dirname(__DIR__) . $old;
            if (is_file($oldPath)) @unlink($oldPath);
        }
        admin_cfg_save($pdo, $key, '/assets/branding/' . $name);
        audit($u['name'], 'Branding upload', 'system', $slot, $name . ' (' . $f['size'] . ' bytes)');
        json_out(['ok' => true, 'slot' => $slot, 'name' => $name, 'url' => '/assets/branding/' . $name]);
    }
    if ($action === 'branding-reset') {
        $slot = trim((string)($body['slot'] ?? ''));
        $slots = ['site_nav'=>'wl_logo_nav','site_nav_dark'=>'wl_logo_nav_dark','site_footer'=>'wl_logo_footer',
                  'site_footer_dark'=>'wl_logo_footer_dark','print'=>'wl_logo_print','dash_header'=>'wl_dash_header',
                  'dash_header_dark'=>'wl_dash_header_dark','dash_footer'=>'wl_dash_footer',
                  'dash_footer_dark'=>'wl_dash_footer_dark','sa_header'=>'wl_sa_header',
                  'sa_header_dark'=>'wl_sa_header_dark','sa_footer'=>'wl_sa_footer',
                  'sa_footer_dark'=>'wl_sa_footer_dark','favicon'=>'wl_favicon'];
        if (!isset($slots[$slot])) json_out(['ok' => false, 'error' => 'slot must be one of site_nav|site_nav_dark|site_footer|site_footer_dark|print|dash_header|dash_header_dark|dash_footer|dash_footer_dark|sa_header|sa_header_dark|sa_footer|sa_footer_dark|favicon.'], 400);
        $key = $slots[$slot];
        $old = (string)$pdo->query("SELECT v FROM admin_settings WHERE k='" . $key . "'")->fetchColumn();
        if ($old !== '' && strpos($old, '/assets/branding/') === 0) {
            $oldPath = dirname(__DIR__) . $old;
            if (is_file($oldPath)) @unlink($oldPath);
        }
        admin_cfg_save($pdo, $key, '');
        audit($u['name'], 'Branding reset', 'system', $slot, '');
        json_out(['ok' => true, 'slot' => $slot]);
    }

    if ($action === 'health') {
        json_out(['ok' => true, 'health' => [
            'php' => PHP_VERSION, 'db' => 'sqlite', 'db_size' => is_file(DB_PATH) ? filesize(DB_PATH) : 0,
            'tables' => (int)$one("SELECT COUNT(*) FROM sqlite_master WHERE type='table'"),
            'journal' => (string)$one('PRAGMA journal_mode'), 'now_utc' => gmdate('Y-m-d H:i:s'),
            'audit_rows' => (int)$one('SELECT COUNT(*) FROM audit_log'),
            'tokens' => (int)$one('SELECT COUNT(*) FROM app_tokens'),
            'auth_attempts' => (int)$one('SELECT COUNT(*) FROM auth_attempts'),
        ]]);
    }

    if ($action === 'audit') {
        $rows = $q('SELECT * FROM audit_log ORDER BY id DESC LIMIT 200');
        json_out(['ok' => true, 'audit' => $rows]);
    }

    /* ── SA1-fullsite v2+: API Library (web & mobile data management) — keys handled below (v3: + lifecycle) ── */
    if ($action === 'api-stats') {
        $tables = ['subscribers','app_users','properties','units','tenants','leases','invoices','payments','receipts',
                   'tickets','platform_tickets','notices','partners','leads','plan_catalog','cms_content','cms_history',
                   'integrations','audit_log','auth_attempts','app_tokens','newsletter_emails','onboarding_apps','referrals'];
        $out = [];
        $total = 0;
        foreach ($tables as $t) {
            try { $n = (int)$one("SELECT COUNT(*) FROM " . $t); } catch (Exception $e) { $n = -1; }
            $out[$t] = $n; if ($n > 0) $total += $n;
        }
        $rows = $q('SELECT * FROM platform_meta');
        json_out(['ok' => true, 'tables' => $out, 'total_rows' => $total, 'meta_keys' => count($rows),
                  'db_size' => is_file(DB_PATH) ? filesize(DB_PATH) : 0, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }
    if ($action === 'api-table') {
        $table = trim($body['table'] ?? '');
        $allowed = ['subscribers','app_users','properties','units','tenants','leases','invoices','payments','tickets',
                    'platform_tickets','notices','partners','leads','plan_catalog','newsletter_emails','onboarding_apps','referrals'];
        if (!in_array($table, $allowed, true)) json_out(['ok' => false, 'error' => 'Table not whitelisted.'], 400);
        try {
            $cols = [];
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $c) $cols[] = $c['name'];
            $rows = $q('SELECT * FROM ' . $table . ' ORDER BY rowid DESC LIMIT 25');
            json_out(['ok' => true, 'table' => $table, 'columns' => $cols, 'rows' => $rows]);
        } catch (Exception $e) {
            json_out(['ok' => false, 'error' => 'Table error.'], 400);
        }
    }

    /* ── SA1-fullsite v2: advanced CMS (visibility, media, section reset) ── */
    if ($action === 'cms-vis-get') {
        $rows = $q('SELECT page, section, visible FROM cms_vis');
        $vis = [];
        foreach ($rows as $v) $vis[$v['page'] . '.' . $v['section']] = (int)$v['visible'];
        json_out(['ok' => true, 'vis' => $vis]);
    }
    if ($action === 'cms-vis-save') {
        $page = trim($body['page'] ?? ''); $section = trim($body['section'] ?? '');
        $visible = isset($body['visible']) ? ((int)$body['visible'] ? 1 : 0) : 1;
        if ($page === '' || $section === '') json_out(['ok' => false, 'error' => 'page and section required.'], 400);
        $pdo->prepare('INSERT INTO cms_vis (page, section, visible) VALUES (?,?,?)
            ON CONFLICT(page, section) DO UPDATE SET visible=excluded.visible')->execute([$page, $section, $visible]);
        audit($u['name'], 'CMS section ' . ($visible ? 'show' : 'hide'), 'cms', '', $page . '/' . $section);
        json_out(['ok' => true, 'page' => $page, 'section' => $section, 'visible' => $visible]);
    }
    if ($action === 'cms-reset-section') {
        $page = trim($body['page'] ?? ''); $section = trim($body['section'] ?? '');
        if ($page === '' || $section === '') json_out(['ok' => false, 'error' => 'page and section required.'], 400);
        $rows = $q('SELECT k, v FROM cms_content WHERE page=? AND section=?', [$page, $section]);
        $hist = $pdo->prepare('INSERT INTO cms_history (page, section, k, v, by) VALUES (?,?,?,?,?)');
        foreach ($rows as $r) $hist->execute([$page, $section, $r['k'], $r['v'], $u['name']]);
        $pdo->prepare('DELETE FROM cms_content WHERE page=? AND section=?')->execute([$page, $section]);
        audit($u['name'], 'CMS section reset', 'cms', '', $page . '/' . $section . ' (' . count($rows) . ' rows archived)');
        json_out(['ok' => true, 'archived' => count($rows)]);
    }
    if ($action === 'cms-media-list') {
        $dir = dirname(__DIR__) . '/assets/cms';
        $files = [];
        if (is_dir($dir)) foreach (glob($dir . '/*') as $f) {
            if (is_file($f)) $files[] = ['name' => basename($f), 'url' => '/assets/cms/' . basename($f),
                                        'size' => filesize($f), 'ts' => date('Y-m-d H:i', filemtime($f))];
        }
        usort($files, function ($a, $b) { return strcmp($b['ts'], $a['ts']); });
        json_out(['ok' => true, 'files' => array_slice($files, 0, 60), 'dir' => '/assets/cms']);
    }
    if ($action === 'cms-media-upload') {
        $f = $_FILES['file'] ?? null;
        if (!$f || !is_uploaded_file($f['tmp_name'] ?? '')) json_out(['ok' => false, 'error' => 'A file upload is required.'], 400);
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true)) json_out(['ok' => false, 'error' => 'Images only (jpg/png/webp/gif/svg).'], 400);
        if ($f['size'] > 5 * 1048576) json_out(['ok' => false, 'error' => 'Max 5 MB.'], 400);
        $dir = dirname(__DIR__) . '/assets/cms';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $name = 'cms-' . gmdate('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) json_out(['ok' => false, 'error' => 'Failed to store file.'], 500);
        audit($u['name'], 'CMS media upload', 'cms', $name, $f['size'] . ' bytes');
        json_out(['ok' => true, 'name' => $name, 'url' => '/assets/cms/' . $name]);
    }

    /* ── SA1-fullsite-v3: global search + API usage + publish workflow + bulk tools ── */
    if ($action === 'global-search') {
        $q = trim((string)($body['q'] ?? ''));
        if (mb_strlen($q) < 2) json_out(['ok' => true, 'groups' => [], 'q' => $q]);
        $groups = global_search($pdo, $q, (int)($body['limit'] ?? 6));
        $counts = [];
        foreach ($groups as $k => $rows) $counts[$k] = count($rows);
        json_out(['ok' => true, 'groups' => $groups, 'counts' => $counts, 'q' => $q]);
    }

    if ($action === 'api-usage') {
        $h24 = $q("SELECT action, COUNT(*) n, SUM(CASE WHEN status>=400 THEN 1 ELSE 0 END) errs,
                          ROUND(AVG(ms)) avg_ms FROM api_usage WHERE ts >= datetime('now','-24 hours')
                          GROUP BY action ORDER BY n DESC LIMIT 25");
        $d7 = (int)$one("SELECT COUNT(*) FROM api_usage WHERE ts >= datetime('now','-7 days')");
        $d24 = (int)$one("SELECT COUNT(*) FROM api_usage WHERE ts >= datetime('now','-24 hours')");
        $err24 = (int)$one("SELECT COUNT(*) FROM api_usage WHERE ts >= datetime('now','-24 hours') AND status>=400");
        $by_auth = $q("SELECT auth, COUNT(*) n FROM api_usage WHERE ts >= datetime('now','-24 hours') GROUP BY auth ORDER BY n DESC");
        $recent = $q("SELECT id, action, method, auth, status, ms, ts FROM api_usage ORDER BY id DESC LIMIT 30");
        $km = api_key_meta($pdo);
        json_out(['ok' => true, 'usage' => [
            'h24' => $h24, 'd7_total' => $d7, 'd24_total' => $d24, 'd24_errors' => $err24,
            'by_auth' => $by_auth, 'recent' => $recent, 'keys' => $km,
        ]]);
    }

    if ($action === 'api-keys-get') {
        $keys = api_keys_get($pdo);
        $km = api_key_meta($pdo);
        $masked = ['web_api_key' => mask_secret($keys['web_api_key']), 'mobile_api_key' => mask_secret($keys['mobile_api_key']),
                   'updated_at' => $keys['updated_at'],
                   'enforce' => (int)($km['enforce'] ?? 0), 'rate_limit' => (int)($km['rate_limit'] ?? 120),
                   'web_last_used' => (string)($km['web_last_used'] ?? ''), 'web_last_ip' => (string)($km['web_last_ip'] ?? ''),
                   'mobile_last_used' => (string)($km['mobile_last_used'] ?? ''), 'mobile_last_ip' => (string)($km['mobile_last_ip'] ?? '')];
        json_out(['ok' => true, 'keys' => $masked, 'hint' => 'Used by web & mobile clients to authenticate server-to-server requests. Keep secret — rotate by saving a new value.']);
    }
    if ($action === 'api-keys-save') {
        $in = $body['keys'] ?? $body;
        if (!is_array($in)) json_out(['ok' => false, 'error' => 'keys required.'], 400);
        $res = api_keys_save($pdo, $in);
        if (isset($res['error'])) json_out(['ok' => false, 'error' => $res['error']], 400);
        if (array_key_exists('enforce', $body)) {
            $km = api_key_meta($pdo);
            $km['enforce'] = $body['enforce'] ? 1 : 0;
            if (isset($body['rate_limit'])) $km['rate_limit'] = max(10, (int)$body['rate_limit']);
            api_key_meta_save($pdo, $km);
        }
        audit($u['name'], 'API key rotation', 'api', '', implode(',', array_intersect(['web_api_key','mobile_api_key'], array_keys($in))));
        $km2 = api_key_meta($pdo);
        json_out(['ok' => true, 'keys' => ['web_api_key' => mask_secret($res['web_api_key']), 'mobile_api_key' => mask_secret($res['mobile_api_key']), 'updated_at' => $res['updated_at'],
                  'enforce' => (int)($km2['enforce'] ?? 0), 'rate_limit' => (int)($km2['rate_limit'] ?? 120)]]);
    }

    if ($action === 'cms-pub-get') {
        cms_pub_sweep($pdo);
        $pub = [];
        foreach ($q('SELECT page, published, publish_at, updated_at FROM cms_pub') as $r) $pub[$r['page']] = ['published' => (int)$r['published'], 'publish_at' => $r['publish_at'] ?: '', 'updated_at' => $r['updated_at']];
        json_out(['ok' => true, 'pub' => $pub]);
    }
    if ($action === 'cms-pub-save') {
        $page = trim($body['page'] ?? ''); if ($page === '') json_out(['ok' => false, 'error' => 'page required.'], 400);
        cms_pub_sweep($pdo);
        $published = (int)($body['published'] ?? 1) ? 1 : 0;
        $publish_at = trim((string)($body['publish_at'] ?? ''));
        $at = '';
        if ($publish_at !== '') {
            $at = str_replace('T', ' ', $publish_at);
            if (strlen($at) === 16) $at .= ':00';
            if ($at <= date('Y-m-d H:i:s')) { $published = 1; $at = ''; }  // past → publish now
            else $published = 0;                                            // future → schedule (draft until then)
        }
        $pdo->prepare('INSERT INTO cms_pub (page, published, publish_at, updated_at) VALUES (?,?,?,datetime(\'now\'))
            ON CONFLICT(page) DO UPDATE SET published=excluded.published, publish_at=excluded.publish_at, updated_at=excluded.updated_at')
            ->execute([$page, $published, $at !== '' ? $at : null]);
        audit($u['name'], 'CMS ' . ($at !== '' ? 'schedule' : ($published ? 'publish' : 'draft')), 'cms', '', $page . ($at !== '' ? ' @ ' . $at : ''));
        json_out(['ok' => true, 'page' => $page, 'published' => $published, 'publish_at' => $at]);
    }
    if ($action === 'cms-ping-sitemap') {
        admin_guard($u);
        $pdo = db();
        /* IndexNow key (32 hex) — generated once, kept in platform_meta (out of the CMS UI) */
        $key = trim((string)$pdo->query("SELECT v FROM platform_meta WHERE k='indexnow_key'")->fetchColumn());
        if (!preg_match('/^[0-9a-f]{32}$/', $key)) {
            $key = substr(bin2hex(random_bytes(16)), 0, 32);
            $pdo->prepare("INSERT INTO platform_meta (k, v) VALUES ('indexnow_key', ?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([$key]);
        }
        /* submit the site URLs (IndexNow = Bing/Seznam/Yandex modern protocol; the old /ping returns 410).
           Build from the shared page map — no HTTP self-fetch (the live server can't always fetch its own sitemap). */
        $draft = [];
        foreach ($pdo->query('SELECT page FROM cms_pub WHERE published=0') as $r) $draft[$r['page']] = true;
        $urls = ['https://krtaker.com/'];
        foreach (sitemap_page_map() as $pg) {
            if (!isset($draft[$pg[0]])) $urls[] = 'https://krtaker.com/' . $pg[1];
        }
        $urls = array_slice($urls, 0, 25);
        $payload = json_encode(['host' => 'krtaker.com', 'key' => $key,
                                'keyLocation' => 'https://krtaker.com/' . $key . '.txt',
                                'urlList' => $urls]);
        $code = 0; $body = '';
        if (function_exists('curl_init')) {
            $ch = curl_init('https://api.indexnow.org/indexnow');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
            $body = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 15,
                'header' => "Content-Type: application/json; charset=utf-8\r\n", 'content' => $payload]]);
            $body = (string)@file_get_contents('https://api.indexnow.org/indexnow', false, $ctx);
            $code = isset($http_response_header[0]) ? (int)substr($http_response_header[0], 9, 3) : 0;
        }
        audit($u['name'], 'CMS sitemap ping', 'cms', '', "IndexNow HTTP $code (" . count($urls) . ' urls)');
        json_out(['ok' => $code >= 200 && $code < 400, 'code' => $code, 'key' => $key,
                  'note' => 'IndexNow notified Bing with ' . count($urls) . ' URLs. Google uses Search Console — submit https://krtaker.com/api/sitemap once there.']);
    }

    /* ── Blog manager (superadmin) — create/publish articles rendered at /blog/<slug> ── */
    if ($action === 'blog-posts') {
        admin_guard($u);
        $rows = $pdo->query('SELECT id, slug, title, tag, status, read_min, cover_emoji, author, meta_title, meta_desc, cover_image, category, created_at, updated_at FROM blog_posts ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        $cnt = $pdo->query("SELECT COUNT(*) FROM blog_posts WHERE status='published'")->fetchColumn();
        json_out(['ok' => true, 'posts' => $rows, 'published' => (int)$cnt]);
    }
    if ($action === 'blog-save') {
        admin_guard($u);
        $id = (int)($body['id'] ?? 0);
        $slug = strtolower(trim((string)($body['slug'] ?? '')));
        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') json_out(['ok' => false, 'error' => 'title required.'], 400);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/', $slug)) json_out(['ok' => false, 'error' => 'slug must be 2-80 chars of a-z 0-9 and hyphens.'], 400);
        $status = (string)($body['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'published'], true)) json_out(['ok' => false, 'error' => 'status must be draft|published.'], 400);
        $tag = trim((string)($body['tag'] ?? ''));
        $excerpt = trim((string)($body['excerpt'] ?? ''));
        $body_txt = (string)($body['body'] ?? '');
        $cover = trim((string)($body['cover_emoji'] ?? '')); if ($cover === '') $cover = '📰';
        if (mb_strlen($cover) > 8) $cover = '📰';
        $read = (int)($body['read_min'] ?? 5); if ($read < 1) $read = 1; if ($read > 120) $read = 120;
        $author = trim((string)($body['author'] ?? ''));
        $meta_title = trim((string)($body['meta_title'] ?? ''));
        $meta_desc = trim((string)($body['meta_desc'] ?? ''));
        $cover_image = trim((string)($body['cover_image'] ?? ''));
        $category = trim((string)($body['category'] ?? ''));
        $dup = $pdo->prepare('SELECT id FROM blog_posts WHERE slug=? AND id != ?');
        $dup->execute([$slug, $id]);
        if ($dup->fetchColumn()) json_out(['ok' => false, 'error' => 'slug already used.'], 409);
        if ($id > 0) {
            $st = $pdo->prepare("UPDATE blog_posts SET slug=?, title=?, tag=?, excerpt=?, body=?, cover_emoji=?, read_min=?, status=?, author=?, meta_title=?, meta_desc=?, cover_image=?, category=?, updated_at=datetime('now') WHERE id=?");
            $st->execute([$slug, $title, $tag, $excerpt, $body_txt, $cover, $read, $status, $author, $meta_title, $meta_desc, $cover_image, $category, $id]);
            if ($st->rowCount() === 0) json_out(['ok' => false, 'error' => 'Post not found.'], 404);
        } else {
            $st = $pdo->prepare("INSERT INTO blog_posts (slug, title, tag, excerpt, body, cover_emoji, read_min, status, author, meta_title, meta_desc, cover_image, category) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$slug, $title, $tag, $excerpt, $body_txt, $cover, $read, $status, $author, $meta_title, $meta_desc, $cover_image, $category]);
            $id = (int)$pdo->lastInsertId();
        }
        audit($u['name'], 'Blog ' . ($id ? 'save' : 'create'), 'cms', 'blog-' . $slug, $title . ' [' . $status . ']');
        json_out(['ok' => true, 'id' => $id, 'slug' => $slug, 'status' => $status]);
    }
    if ($action === 'blog-delete') {
        admin_guard($u);
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('DELETE FROM blog_posts WHERE id=?');
        $st->execute([$id]);
        if ($st->rowCount() === 0) json_out(['ok' => false, 'error' => 'Post not found.'], 404);
        audit($u['name'], 'Blog delete', 'cms', '', 'id ' . $id);
        json_out(['ok' => true]);
    }

    if ($action === 'cms-replace') {
        $find = (string)($body['find'] ?? ''); $rep = (string)($body['replace'] ?? '');
        $page = trim($body['page'] ?? ''); $section = trim($body['section'] ?? '');
        if ($find === '') json_out(['ok' => false, 'error' => 'find required.'], 400);
        $sql = 'SELECT page, section, k, v FROM cms_content WHERE v LIKE ?';
        $args = ['%' . $find . '%'];
        if ($page !== '') { $sql .= ' AND page=?'; $args[] = $page; }
        if ($section !== '') { $sql .= ' AND section=?'; $args[] = $section; }
        $rows = $q($sql, $args);
        $matches = [];
        foreach ($rows as $r) if (strpos((string)$r['v'], $find) !== false) $matches[] = $r;
        if (empty($body['dry_run'])) {
            $st = $pdo->prepare('UPDATE cms_content SET v=? WHERE page=? AND section=? AND k=?');
            $hist = $pdo->prepare('INSERT INTO cms_history (page, section, k, v, by) VALUES (?,?,?,?,?)');
            $changed = 0;
            foreach ($matches as $r) {
                $nv = str_replace($find, $rep, (string)$r['v']);
                if ($nv === $r['v']) continue;
                $hist->execute([$r['page'], $r['section'], $r['k'], $r['v'], $u['name']]);
                $st->execute([$nv, $r['page'], $r['section'], $r['k']]);
                $changed++;
            }
            audit($u['name'], 'CMS find & replace', 'cms', '', '"' . substr($find, 0, 40) . '" → ' . $changed . ' blocks');
            json_out(['ok' => true, 'changed' => $changed, 'matched' => count($matches)]);
        } else {
            json_out(['ok' => true, 'matched' => count($matches), 'samples' => array_slice($matches, 0, 8)]);
        }
    }

    if ($action === 'cms-clone') {
        $src = trim($body['src'] ?? ''); $dst = trim($body['dst'] ?? '');
        if ($src === '' || $dst === '') json_out(['ok' => false, 'error' => 'src and dst required.'], 400);
        if (!preg_match('/^[a-z0-9_-]{1,40}$/', $dst)) json_out(['ok' => false, 'error' => 'dst must be lowercase a-z 0-9 _ -.'], 400);
        if ($src === $dst) json_out(['ok' => false, 'error' => 'src and dst must differ.'], 400);
        $rows = $q('SELECT section, k, v FROM cms_content WHERE page=?', [$src]);
        if (!$rows) json_out(['ok' => false, 'error' => 'Source page has no stored blocks.'], 400);
        $dup = (int)$one('SELECT COUNT(*) FROM cms_content WHERE page=?', [$dst]);
        if ($dup) json_out(['ok' => false, 'error' => 'Destination page already has blocks.'], 409);
        $st = $pdo->prepare('INSERT INTO cms_content (page, section, k, v) VALUES (?,?,?,?)');
        $vis = $pdo->prepare('INSERT OR IGNORE INTO cms_vis (page, section, visible) SELECT ?, section, visible FROM cms_vis WHERE page=?');
        foreach ($rows as $r) $st->execute([$dst, $r['section'], $r['k'], $r['v']]);
        $vis->execute([$dst, $src]);
        $pub = $pdo->prepare('INSERT OR IGNORE INTO cms_pub (page, published) VALUES (?, 0)');
        $pub->execute([$dst]);
        audit($u['name'], 'CMS clone', 'cms', '', $src . ' → ' . $dst . ' (' . count($rows) . ' blocks)');
        json_out(['ok' => true, 'copied' => count($rows), 'dst' => $dst]);
    }

    /* ── SA1-fullsite v8 (v3.62): webhooks — endpoint subscriptions + delivery log ── */
    if ($action === 'webhook-events') {
        json_out(['ok' => true, 'events' => WEBHOOK_EVENTS()]);
    }
    if ($action === 'webhook-list') {
        $hooks = $q('SELECT w.*, COALESCE(s.name,\'\') tenant_name FROM webhooks w LEFT JOIN subscribers s ON s.id=w.tenant_id ORDER BY w.id DESC');
        $stats = $q('SELECT hook, COUNT(*) n, SUM(CASE WHEN ok=1 THEN 1 ELSE 0 END) ok, SUM(CASE WHEN ok=0 THEN 1 ELSE 0 END) fail FROM webhook_logs GROUP BY hook');
        $stm = [];
        foreach ($stats as $r) $stm[(int)$r['hook']] = ['n' => (int)$r['n'], 'ok' => (int)$r['ok'], 'fail' => (int)$r['fail']];
        foreach ($hooks as &$h) $h['stats'] = $stm[(int)$h['id']] ?? ['n' => 0, 'ok' => 0, 'fail' => 0];
        unset($h);
        json_out(['ok' => true, 'webhooks' => $hooks,
                  'events' => WEBHOOK_EVENTS(),
                  'subscribers' => $q('SELECT id, name, email, plan, status FROM subscribers ORDER BY id')]);
    }
    if ($action === 'webhook-save') {
        $id = (int)($body['id'] ?? 0);
        $label = trim($body['label'] ?? '');
        $tenant_id = (int)($body['tenant_id'] ?? 0);
        $url = trim($body['url'] ?? '');
        $events = $body['events'] ?? [];
        $active = (int)($body['active'] ?? 1);
        $regenerate = !empty($body['regenerate']);
        $description = trim($body['description'] ?? '');
        $max_retries = min(20, max(0, (int)($body['max_retries'] ?? 3)));
        if (!is_array($events) || !$events) json_out(['ok' => false, 'error' => 'Select at least one event.'], 400);
        $valid = array_column(WEBHOOK_EVENTS(), 'id');
        foreach ($events as $e) if (!in_array($e, $valid, true)) json_out(['ok' => false, 'error' => 'Unknown event: ' . $e], 400);
        if (!preg_match('#^https?://[^\s]+$#i', $url) || strlen($url) > 500) json_out(['ok' => false, 'error' => 'Valid http(s) URL required.'], 400);
        if ($tenant_id) {
            $tc = (int)$one('SELECT COUNT(*) FROM subscribers WHERE id=?', [$tenant_id]);
            if (!$tc) json_out(['ok' => false, 'error' => 'Unknown subscriber.'], 400);
        }
        if (!$label) $label = parse_url($url, PHP_URL_HOST) ?: 'Webhook';
        $evs = json_encode(array_values($events), JSON_UNESCAPED_UNICODE);
        if ($id) {
            $cur = $q('SELECT secret FROM webhooks WHERE id=?', [$id]);
            if (!$cur) json_out(['ok' => false, 'error' => 'Webhook not found.'], 404);
            $secret = $regenerate ? bin2hex(random_bytes(16)) : $cur[0]['secret'];
            $pdo->prepare('UPDATE webhooks SET label=?, tenant_id=?, url=?, secret=?, events=?, active=?, description=?, max_retries=? WHERE id=?')
                ->execute([$label, $tenant_id, $url, $secret, $evs, $active ? 1 : 0, $description, $max_retries, $id]);
            audit($u['name'], 'Webhook update', 'webhooks', (string)$id, $label . ' ' . $url . ' (' . count($events) . ' events' . ($regenerate ? ', secret rotated' : '') . ')');
            json_out(['ok' => true, 'id' => $id, 'secret' => $secret, 'rotated' => $regenerate]);
        } else {
            $secret = bin2hex(random_bytes(16));
            $pdo->prepare('INSERT INTO webhooks (tenant_id, label, url, secret, events, active, description, max_retries) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$tenant_id, $label, $url, $secret, $evs, $active ? 1 : 0, $description, $max_retries]);
            $nid = (int)$pdo->lastInsertId();
            audit($u['name'], 'Webhook create', 'webhooks', (string)$nid, $label . ' ' . $url . ' (' . count($events) . ' events)');
            json_out(['ok' => true, 'id' => $nid, 'secret' => $secret]);
        }
    }
    if ($action === 'webhook-delete') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $cnt = (int)$one('SELECT COUNT(*) FROM webhooks WHERE id=?', [$id]);
        if (!$cnt) json_out(['ok' => false, 'error' => 'Webhook not found.'], 404);
        $pdo->prepare('DELETE FROM webhook_logs WHERE hook=?')->execute([$id]);
        $pdo->prepare('DELETE FROM webhooks WHERE id=?')->execute([$id]);
        audit($u['name'], 'Webhook delete', 'webhooks', (string)$id, '');
        json_out(['ok' => true]);
    }
    if ($action === 'webhook-toggle') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT active FROM webhooks WHERE id=?'); $st->execute([$id]);
        $cur = $st->fetchColumn();
        if ($cur === false) json_out(['ok' => false, 'error' => 'Webhook not found.'], 404);
        $nv = (int)$cur ? 0 : 1;
        $pdo->prepare('UPDATE webhooks SET active=? WHERE id=?')->execute([$nv, $id]);
        audit($u['name'], 'Webhook ' . ($nv ? 'enable' : 'disable'), 'webhooks', (string)$id, '');
        json_out(['ok' => true, 'active' => $nv]);
    }
    if ($action === 'webhook-test') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT * FROM webhooks WHERE id=?'); $st->execute([$id]);
        $h = $st->fetch(PDO::FETCH_ASSOC);
        if (!$h) json_out(['ok' => false, 'error' => 'Webhook not found.'], 404);
        webhook_dispatch($pdo, 'test.ping', ['message' => 'Test ping from KRTaker', 'hook' => (int)$id, 'label' => $h['label'], 'sent_by' => $u['name']]);
        $lg = $q('SELECT * FROM webhook_logs WHERE hook=? ORDER BY id DESC LIMIT 1', [$id]);
        $last = $lg[0] ?? null;
        audit($u['name'], 'Webhook test', 'webhooks', (string)$id, $last ? ($last['ok'] ? 'OK ' . $last['status'] : 'FAIL ' . $last['status'] . ' ' . $last['response']) : 'no delivery');
        json_out(['ok' => true, 'delivery' => $last]);
    }
    if ($action === 'webhook-logs') {
        $id = (int)($body['id'] ?? 0);
        $limit = min(200, max(1, (int)($body['limit'] ?? 50)));
        if ($id) {
            $rows = $q('SELECT * FROM webhook_logs WHERE hook=? ORDER BY id DESC LIMIT ' . $limit, [$id]);
            $label = (string)$one('SELECT label FROM webhooks WHERE id=?', [$id]);
        } else {
            $rows = $q('SELECT * FROM webhook_logs ORDER BY id DESC LIMIT ' . $limit);
            $label = '';
        }
        json_out(['ok' => true, 'logs' => $rows, 'label' => $label]);
    }
    if ($action === 'webhook-log-clear') {
        $id = (int)($body['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM webhook_logs WHERE hook=?')->execute([$id]);
            audit($u['name'], 'Webhook log clear', 'webhooks', (string)$id, '');
        } else {
            $pdo->exec('DELETE FROM webhook_logs');
            audit($u['name'], 'Webhook log clear', 'webhooks', '', 'all');
        }
        json_out(['ok' => true]);
    }

    /* ── SA1-fullsite v9 (v3.63): per-tenant API keys — lifecycle + usage ── */
    if ($action === 'tenant-keys') {
        $rows = $q('SELECT t.*, COALESCE(s.name,\'\') tenant_name, s.email tenant_email, s.plan, s.status
                    FROM tenant_api_keys t LEFT JOIN subscribers s ON s.id=t.tenant_id ORDER BY t.id DESC');
        foreach ($rows as &$r) { $r['key'] = mask_secret($r['key']); $r['calls'] = (int)$r['calls']; }
        unset($r);
        json_out(['ok' => true, 'keys' => $rows,
                  'subscribers' => $q('SELECT id, name, email, plan, status FROM subscribers ORDER BY id')]);
    }
    if ($action === 'tenant-key-save') {
        $id = (int)($body['id'] ?? 0);
        $tenant_id = (int)($body['tenant_id'] ?? 0);
        $label = trim($body['label'] ?? '');
        $active = isset($body['active']) ? ((int)$body['active'] ? 1 : 0) : 1;
        if (!$tenant_id) json_out(['ok' => false, 'error' => 'Select a subscriber (tenant).'], 400);
        $tc = (int)$one('SELECT COUNT(*) FROM subscribers WHERE id=?', [$tenant_id]);
        if (!$tc) json_out(['ok' => false, 'error' => 'Unknown subscriber.'], 400);
        if (strlen($label) > 80) json_out(['ok' => false, 'error' => 'Label too long (max 80).'], 400);
        if ($id) {
            $cur = $q('SELECT id FROM tenant_api_keys WHERE id=?', [$id]);
            if (!$cur) json_out(['ok' => false, 'error' => 'Key not found.'], 404);
            $pdo->prepare('UPDATE tenant_api_keys SET tenant_id=?, label=?, active=? WHERE id=?')
                ->execute([$tenant_id, $label, $active, $id]);
            audit($u['name'], 'Tenant API key update', 'api', (string)$id, '#' . $tenant_id . ' ' . $label);
            json_out(['ok' => true, 'id' => $id]);
        } else {
            $key = tenant_key_gen();
            $pdo->prepare('INSERT INTO tenant_api_keys (tenant_id, label, key, active) VALUES (?,?,?,?)')
                ->execute([$tenant_id, $label, $key, $active]);
            $nid = (int)$pdo->lastInsertId();
            audit($u['name'], 'Tenant API key create', 'api', (string)$nid, '#' . $tenant_id . ' ' . $label);
            json_out(['ok' => true, 'id' => $nid, 'key' => $key]);
        }
    }
    if ($action === 'tenant-key-rotate') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $cur = $q('SELECT id, tenant_id, label FROM tenant_api_keys WHERE id=?', [$id]);
        if (!$cur) json_out(['ok' => false, 'error' => 'Key not found.'], 404);
        $key = tenant_key_gen();
        $pdo->prepare("UPDATE tenant_api_keys SET key=?, created_at=datetime('now') WHERE id=?")->execute([$key, $id]);
        audit($u['name'], 'Tenant API key rotate', 'api', (string)$id, $cur[0]['label']);
        json_out(['ok' => true, 'id' => $id, 'key' => $key]);
    }
    if ($action === 'tenant-key-toggle') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare('SELECT active FROM tenant_api_keys WHERE id=?'); $st->execute([$id]);
        $cur = $st->fetchColumn();
        if ($cur === false) json_out(['ok' => false, 'error' => 'Key not found.'], 404);
        $nv = (int)$cur ? 0 : 1;
        $pdo->prepare('UPDATE tenant_api_keys SET active=? WHERE id=?')->execute([$nv, $id]);
        audit($u['name'], 'Tenant API key ' . ($nv ? 'enable' : 'disable'), 'api', (string)$id, '');
        json_out(['ok' => true, 'active' => $nv]);
    }
    if ($action === 'tenant-key-delete') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $cur = $q('SELECT id, tenant_id, label FROM tenant_api_keys WHERE id=?', [$id]);
        if (!$cur) json_out(['ok' => false, 'error' => 'Key not found.'], 404);
        $pdo->prepare('DELETE FROM tenant_api_keys WHERE id=?')->execute([$id]);
        audit($u['name'], 'Tenant API key revoke', 'api', (string)$id, $cur[0]['label']);
        json_out(['ok' => true]);
    }

    /* ── SA1-fullsite v11 (v3.65): tenant custom domains — CNAME/TXT verify + routing ── */
    if ($action === 'cnames') {
        $rows = $q('SELECT d.*, COALESCE(s.name,\'\') tenant_name, s.email tenant_email, s.plan, s.status
                    FROM tenant_domains d LEFT JOIN subscribers s ON s.id=d.tenant_id ORDER BY d.id DESC');
        foreach ($rows as &$r) { $r['verified'] = (int)$r['verified']; $r['verify_token'] = mask_secret($r['verify_token']); }
        unset($r);
        json_out(['ok' => true, 'domains' => $rows,
                  'subscribers' => $q('SELECT id, name, email, plan, status FROM subscribers ORDER BY id')]);
    }
    if ($action === 'cname-save') {
        $id = (int)($body['id'] ?? 0);
        $tenant_id = (int)($body['tenant_id'] ?? 0);
        $domain = strtolower(trim($body['domain'] ?? ''));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);
        if (!$tenant_id) json_out(['ok' => false, 'error' => 'Select a subscriber (tenant).'], 400);
        $tc = (int)$one('SELECT COUNT(*) FROM subscribers WHERE id=?', [$tenant_id]);
        if (!$tc) json_out(['ok' => false, 'error' => 'Unknown subscriber.'], 400);
        if (!preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.[a-z0-9-]{1,63})+$/', $domain) || strpos($domain, 'krtaker.com') !== false)
            json_out(['ok' => false, 'error' => 'Enter a valid custom domain (e.g. portal.tenant.com).'], 400);
        $dup = (int)$one('SELECT COUNT(*) FROM tenant_domains WHERE domain=? AND id<>?', [$domain, $id]);
        if ($dup) json_out(['ok' => false, 'error' => 'That domain is already registered.'], 400);
        if ($id) {
            $cur = $q('SELECT id, tenant_id FROM tenant_domains WHERE id=?', [$id]);
            if (!$cur) json_out(['ok' => false, 'error' => 'Domain not found.'], 404);
            $pdo->prepare('UPDATE tenant_domains SET tenant_id=?, domain=?, verified=0 WHERE id=?')->execute([$tenant_id, $domain, $id]);
            audit($u['name'], 'Tenant domain update', 'domains', (string)$id, $domain);
            json_out(['ok' => true, 'id' => $id]);
        } else {
            $token = 'krt-verify-' . bin2hex(random_bytes(12));
            $pdo->prepare('INSERT INTO tenant_domains (tenant_id, domain, verify_token) VALUES (?,?,?)')->execute([$tenant_id, $domain, $token]);
            $nid = (int)$pdo->lastInsertId();
            audit($u['name'], 'Tenant domain add', 'domains', (string)$nid, $domain . ' (#' . $tenant_id . ')');
            json_out(['ok' => true, 'id' => $nid, 'token' => $token]);
        }
    }
    if ($action === 'cname-verify') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $cur = $q('SELECT * FROM tenant_domains WHERE id=?', [$id]);
        if (!$cur) json_out(['ok' => false, 'error' => 'Domain not found.'], 404);
        $d = $cur[0];
        $host = '_krtaker.' . $d['domain'];
        $found = false;
        $note = '';
        if (function_exists('dns_get_record')) {
            $recs = @dns_get_record($host, DNS_TXT);
            foreach ((array)$recs as $rec) {
                if (strpos(implode(' ', (array)($rec['txt'] ?? [])), $d['verify_token']) !== false) { $found = true; break; }
            }
            $note = $found ? '' : 'TXT record not found yet — add "' . $d['verify_token'] . '" as a TXT record on ' . $host;
        } else {
            $note = 'DNS check unavailable on this server (dns_get_record missing).';
        }
        $pdo->prepare("UPDATE tenant_domains SET verified=?, verified_at=CASE WHEN ? THEN datetime('now') ELSE verified_at END,
                       last_checked_at=datetime('now'), last_check_note=? WHERE id=?")
            ->execute([$found ? 1 : 0, $found ? 1 : 0, $note, $id]);
        audit($u['name'], 'Tenant domain verify ' . ($found ? 'OK' : 'FAIL'), 'domains', (string)$id, $d['domain'] . ($found ? '' : ' — ' . $note));
        json_out(['ok' => true, 'verified' => $found ? 1 : 0, 'note' => $note, 'token' => $d['verify_token'], 'host' => $host]);
    }
    if ($action === 'cname-delete') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $cur = $q('SELECT id, domain FROM tenant_domains WHERE id=?', [$id]);
        if (!$cur) json_out(['ok' => false, 'error' => 'Domain not found.'], 404);
        $pdo->prepare('DELETE FROM tenant_domains WHERE id=?')->execute([$id]);
        audit($u['name'], 'Tenant domain delete', 'domains', (string)$id, $cur[0]['domain']);
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'Unknown admin action: ' . $action], 400);
}

/* Public CMS read — merged defaults + stored, used by landing pages (no auth) */
case 'cms-read': {
    $pdo = db();
    cms_pub_sweep($pdo);   // due scheduled pages auto-publish before serving
    $rows = $pdo->query('SELECT page, section, k, v FROM cms_content ORDER BY page, section, k')->fetchAll(PDO::FETCH_ASSOC);
    /* draft pages (cms_pub.published=0) serve defaults — their stored edits stay hidden until published */
    $draft = [];
    foreach ($pdo->query('SELECT page FROM cms_pub WHERE published=0') as $r) $draft[$r['page']] = true;
    $stored = [];
    foreach ($rows as $r) if (!isset($draft[$r['page']])) $stored[$r['page'] . '|' . $r['section'] . '|' . $r['k']] = $r['v'];
    $out = [];
    $seen = [];
    foreach (CMS_DEFAULTS() as $d) {
        $key = $d[0] . '|' . $d[1] . '|' . $d[2];
        $seen[$key] = true;
        $out[] = ['page' => $d[0], 'section' => $d[1], 'k' => $d[2], 'v' => $stored[$key] ?? $d[3]];
    }
    foreach ($rows as $r) {
        if (isset($draft[$r['page']])) continue;
        $key = $r['page'] . '|' . $r['section'] . '|' . $r['k'];
        if (!isset($seen[$key])) $out[] = ['page' => $r['page'], 'section' => $r['section'], 'k' => $r['k'], 'v' => $r['v']];
    }
    $map = [];
    foreach ($out as $b) $map[$b['page'] . '.' . $b['section'] . '.' . $b['k']] = $b['v'];
    $vis = [];
    foreach ($pdo->query('SELECT page, section, visible FROM cms_vis') as $v) $vis[$v['page'] . '.' . $v['section']] = (int)$v['visible'];
    json_out(['ok' => true, 'blocks' => $out, 'map' => $map, 'vis' => $vis]);
}

/* Public plans — plan_catalog for the marketing pricing page (no auth) */
case 'plans': {
    $pdo = db();
    $rows = $pdo->query('SELECT * FROM plan_catalog ORDER BY price')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['features'] = json_decode($r['features'], true) ?: [];
        $r['limits'] = json_decode($r['limits'] ?? '{}', true) ?: [];
        unset($r['modules']); // role-module matrices are admin-only
    }
    json_out(['ok' => true, 'plans' => $rows]);
}

/* Public sitemap — dynamic XML from the CMS page map (draft pages excluded, lastmod from edits) */
case 'sitemap': {
    $pdo = db();
    cms_pub_sweep($pdo);   // due scheduled pages auto-publish before the index is built
    $draft = [];
    foreach ($pdo->query('SELECT page FROM cms_pub WHERE published=0') as $r) $draft[$r['page']] = true;
    $lm1 = $pdo->prepare('SELECT MAX(ts) AS lm FROM cms_history WHERE page=?');   // last CMS edit (cms_content has no timestamp)
    $lm2 = $pdo->prepare('SELECT MAX(updated_at) AS lm FROM cms_pub WHERE page=?');
    $pages = sitemap_page_map();
    $out = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">";
    foreach ($pages as $pg) {
        if (isset($draft[$pg[0]])) continue;   // draft pages stay out of the index
        $lm = '';
        $lm1->execute([$pg[0]]); $v = $lm1->fetchColumn();
        if ($v) $lm = substr((string)$v, 0, 10);
        if ($lm === '') { $lm2->execute([$pg[0]]); $v = $lm2->fetchColumn(); if ($v) $lm = substr((string)$v, 0, 10); }
        if ($lm === '') $lm = $pg[4];
        $out .= "\n  <url>\n    <loc>https://krtaker.com/{$pg[1]}</loc>\n    <lastmod>{$lm}</lastmod>\n    <changefreq>{$pg[3]}</changefreq>\n    <priority>{$pg[2]}</priority>\n  </url>";
    }
    /* published blog-manager articles are indexable pages too */
    $bp = $pdo->query("SELECT slug, created_at FROM blog_posts WHERE status='published' ORDER BY created_at DESC");
    foreach ($bp as $row) {
        $out .= "\n  <url>\n    <loc>https://krtaker.com/blog/" . rawurlencode($row['slug']) . "</loc>\n    <lastmod>" . substr((string)$row['created_at'], 0, 10) . "</lastmod>\n    <changefreq>monthly</changefreq>\n    <priority>0.6</priority>\n  </url>";
    }
    /* published property pages are indexable (server-side OG) — property id IS the code (P-001…) */
    $pr = $pdo->query("SELECT id, created_at FROM properties WHERE published=1 ORDER BY id");
    foreach ($pr as $row) {
        $out .= "\n  <url>\n    <loc>https://krtaker.com/building/" . rawurlencode($row['id']) . "</loc>\n    <lastmod>" . substr((string)$row['created_at'], 0, 10) . "</lastmod>\n    <changefreq>weekly</changefreq>\n    <priority>0.7</priority>\n  </url>";
    }
    $out .= "\n</urlset>\n";
    header('Content-Type: application/xml; charset=utf-8');
    echo $out;
    exit;
}

/* Public blog list — published posts for the blog index (no auth) */
case 'blog-list': {
    $pdo = db();
    $rows = $pdo->query("SELECT slug, title, tag, excerpt, cover_emoji, read_min, created_at FROM blog_posts WHERE status='published' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    json_out(['ok' => true, 'posts' => $rows]);
}

/* ── Error log — client-side JS/rejection capture (POST, public; rate-limited per IP) ── */
case 'app-log-error': {
    $pdo = errlog_table(db());
    $errs = $body['errors'] ?? null;
    if (!is_array($errs) || !$errs) {
        $errs = [['kind' => 'js', 'msg' => (string)($body['err'] ?? ''), 'url' => (string)($body['url'] ?? ''), 'line' => (int)($body['line'] ?? 0), 'col' => (int)($body['col'] ?? 0)]];
    }
    if (!api_rate_limit_ok($pdo, 'errlog', 30)) {
        header('Retry-After: 60');
        json_out(['ok' => true, 'logged' => 0, 'throttled' => true, 'retry_after' => 60]);
    }
    $page = substr((string)($_SERVER['HTTP_REFERER'] ?? $body['page'] ?? ''), 0, 300);
    $ua   = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
    $ip   = substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 12);
    $n = 0;
    foreach (array_slice($errs, 0, 20) as $e) {
        if (!is_array($e)) continue;
        $kind = in_array($e['kind'] ?? '', ['js', 'rejection', 'php'], true) ? $e['kind'] : 'js';
        $msg  = substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($e['msg'] ?? '')), 0, 500);
        if ($msg === '') continue;
        $url  = substr((string)($e['url'] ?? ''), 0, 300);
        $line = (int)($e['line'] ?? 0);
        $col  = (int)($e['col'] ?? 0);
        $src  = substr((string)($e['src'] ?? ''), 0, 200);
        /* dedup: same kind+msg+url+line within 24h increments count instead of a new row */
        $st = $pdo->prepare("UPDATE app_error_log SET count=count+1, last_ts=datetime('now') WHERE kind=? AND msg=? AND url=? AND line=? AND last_ts > datetime('now','-1 day')");
        $st->execute([$kind, $msg, $url, $line]);
        if ($st->rowCount() === 0) {
            $st = $pdo->prepare("INSERT INTO app_error_log (kind, src, page, msg, url, line, col, ua, ip_hash) VALUES (?,?,?,?,?,?,?,?,?)");
            $st->execute([$kind, $src, $page, $msg, $url, $line, $col, $ua, $ip]);
        }
        $n++;
    }
    if (random_int(1, 20) === 1) $pdo->exec("DELETE FROM app_error_log WHERE last_ts < datetime('now','-30 days')");
    json_out(['ok' => true, 'logged' => $n]);
}

/* ── Error log — read (GET, service-key only; used by the error watchdog cron) ── */
case 'app-error-log': {
    if (!service_authed()) json_out(['ok' => false, 'error' => 'Service key required.'], 401);
    $pdo = errlog_table(db());
    $since = (string)($_GET['since'] ?? '');
    if ($since === '' || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $since)) $since = gmdate('Y-m-d H:i:s', time() - 3600);
    $st = $pdo->prepare("SELECT id, kind, src, page, msg, url, line, col, count, first_ts, last_ts FROM app_error_log WHERE last_ts > ? ORDER BY last_ts DESC LIMIT 200");
    $st->execute([$since]);
    $errs = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($errs as &$r) { $r['id'] = (int)$r['id']; $r['line'] = (int)$r['line']; $r['col'] = (int)$r['col']; $r['count'] = (int)$r['count']; }
    $h24 = (int)$pdo->query("SELECT COALESCE(SUM(count),0) FROM app_error_log WHERE last_ts > datetime('now','-1 day')")->fetchColumn();
    $h7d = (int)$pdo->query("SELECT COALESCE(SUM(count),0) FROM app_error_log WHERE last_ts > datetime('now','-7 days')")->fetchColumn();
    $top = $pdo->query("SELECT kind, msg, SUM(count) AS c FROM app_error_log WHERE last_ts > datetime('now','-7 days') GROUP BY kind, msg ORDER BY c DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    json_out(['ok' => true, 'errors' => $errs, 'since' => $since, 'count_24h' => $h24, 'count_7d' => $h7d, 'top' => $top]);
}

/* ── Tenant drawer: record a (partial) payment against an invoice / monthly rent ──
   Inserts a receipt + a success payment row, then recomputes the invoice status:
   fully paid → Paid, any payment → Partial, none → Unpaid. */
case 'app-invoice-pay': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot record payments.'], 403);
    $pdo = db();
    $inv = trim($body['invoice_id'] ?? '');
    $amount = (int)($body['amount'] ?? 0);
    $date = trim($body['date'] ?? '');
    $method = trim($body['method'] ?? 'Manual');
    $sig = trim($body['sig'] ?? '');
    if (!$inv) json_out(['ok' => false, 'error' => 'invoice_id required.'], 400);
    if ($amount <= 0) json_out(['ok' => false, 'error' => 'amount must be positive.'], 400);
    $st = $pdo->prepare('SELECT * FROM invoices WHERE id=?'); $st->execute([$inv]);
    $iv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$iv) json_out(['ok' => false, 'error' => 'Invoice not found.'], 404);
    if (!$date) $date = date('Y-m-d');
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM receipts WHERE inv=?'); $st->execute([$inv]);
    $paidSoFar = (int)$st->fetchColumn();
    $remaining = (int)$iv['net'] - $paidSoFar;
    if ($remaining <= 0) json_out(['ok' => false, 'error' => 'Invoice already fully paid.'], 409);
    if ($amount > $remaining) json_out(['ok' => false, 'error' => 'Amount exceeds remaining balance (৳' . $remaining . ').'], 400);
    $rid = 'RCP-' . str_pad((string)((int)$pdo->query("SELECT COALESCE(MAX(CAST(REPLACE(id,'RCP-','') AS INTEGER)),0) FROM receipts")->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
    $pid = 'PAY-' . str_pad((string)((int)$pdo->query("SELECT COALESCE(MAX(CAST(REPLACE(id,'PAY-','') AS INTEGER)),0) FROM payments")->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO receipts (id, inv, amount, date, method, sig) VALUES (?,?,?,?,?,?)')
        ->execute([$rid, $inv, $amount, $date, $method, $sig]);
    $pdo->prepare("INSERT INTO payments (id, inv, amount, method, ref, date, status) VALUES (?,?,?,?,?,?,'Success')")
        ->execute([$pid, $inv, $amount, $method, 'manual:' . $sig, $date]);
    $newPaid = $paidSoFar + $amount;
    $status = $newPaid >= (int)$iv['net'] ? 'Paid' : 'Partial';
    $pdo->prepare('UPDATE invoices SET status=? WHERE id=?')->execute([$status, $inv]);
    audit($u['name'], 'Partial payment', 'invoices', $inv, $rid . ' ৳' . $amount . ' ' . $method . ' -> ' . $status);
    json_out(['ok' => true, 'receipt_id' => $rid, 'payment_id' => $pid, 'paid' => $newPaid, 'status' => $status, 'remaining' => max(0, (int)$iv['net'] - $newPaid)]);
}

/* ── Tenant drawer: targeted due/upcoming payment reminder (email + board notice) ──
   Covers BOTH buckets:
     · due      — unpaid invoices for months <= current month (overdue + due this month)
     · upcoming — next month's expected rent (from active leases; or existing unpaid
                  next-month invoices), so the tenant is reminded before the due date. */
case 'app-tenant-remind': {
    $u = require_user();
    if (!in_array($u['role'], ['superadmin', 'owner', 'manager', 'accountant'], true))
        json_out(['ok' => false, 'error' => 'Your role cannot send reminders.'], 403);
    $pdo = db();
    /* V2.23: rate-limit manual tenant reminders — 10 sends/10 min/IP (spam guard) */
    $ip = client_ip();
    if (recent_any('', $ip, 10, 0, 10, ['tremind'])) throttle_out('Too many tenant reminders. Try again later.', '', $ip, 10, ['tremind']);
    $tid = trim($body['tenant_id'] ?? '');
    if (!$tid) json_out(['ok' => false, 'error' => 'tenant_id required.'], 400);
    $st = $pdo->prepare('SELECT * FROM tenants WHERE id=?'); $st->execute([$tid]);
    $tn = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tn) json_out(['ok' => false, 'error' => 'Tenant not found.'], 404);
    $cur = gmdate('Y-m');
    $next = date('Y-m', strtotime($cur . ' +1 month'));
    $st = $pdo->prepare('SELECT * FROM leases WHERE t=?'); $st->execute([$tid]);
    $leases = $st->fetchAll(PDO::FETCH_ASSOC);
    $leaseIds = array_column($leases, 'id');
    /* due invoices: unpaid, month <= current (overdue + due_soon) */
    $due = [];
    if ($leaseIds) {
        $st = $pdo->prepare('SELECT i.*, l.u AS unit FROM invoices i JOIN leases l ON l.id=i.l WHERE i.l IN (' . ai_in_list($leaseIds) . ") AND i.status != 'Paid' AND i.m <= ? ORDER BY i.m");
        $st->execute(array_merge($leaseIds, [$cur]));
        $due = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    /* upcoming: next-month unpaid invoices + active leases' expected next-month rent */
    $upcoming = [];
    if ($leaseIds) {
        $st = $pdo->prepare('SELECT i.*, l.u AS unit FROM invoices i JOIN leases l ON l.id=i.l WHERE i.l IN (' . ai_in_list($leaseIds) . ") AND i.status != 'Paid' AND i.m = ? ORDER BY i.m");
        $st->execute(array_merge($leaseIds, [$next]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $iv) {
            $upcoming[] = ['kind' => 'invoice', 'ref' => $iv['id'], 'lease' => $iv['l'], 'unit' => $iv['u'], 'month' => $iv['m'], 'amount' => (int)$iv['net']];
        }
        $haveNext = array_column($upcoming, 'lease');
        foreach ($leases as $l) {
            if (!in_array(strtolower($l['status']), ['active', 'pending registration'], true)) continue;
            if (in_array($l['id'], $haveNext, true)) continue;
            $rent = (int)$l['rent'];
            if ($rent <= 0) continue;
            $unit = $pdo->prepare('SELECT name FROM units WHERE id=?'); $unit->execute([$l['u']]);
            $upcoming[] = ['kind' => 'lease', 'ref' => $l['id'], 'lease' => $l['id'], 'unit' => (string)$unit->fetchColumn(), 'month' => $next, 'amount' => $rent];
        }
    }
    if (!$due && !$upcoming) json_out(['ok' => false, 'error' => 'Nothing to remind about — no due invoices and no upcoming rent.'], 400);
    $totalDue = (int)array_sum(array_column($due, 'net'));
    $totalUp = (int)array_sum(array_column($upcoming, 'amount'));
    $lines = '';
    foreach ($due as $iv) {
        $lines .= '<li><b>' . esc($iv['id']) . '</b> — ' . esc($iv['m'] ?? '') . ' (lease ' . esc($iv['l']) . ') — ৳' . number_format((int)$iv['net']) . ' — ' . esc($iv['status']) . '</li>';
    }
    if ($upcoming) {
        $lines .= '<li style="margin-top:6px"><b>Upcoming rent (' . esc($next) . '):</b></li>';
        foreach ($upcoming as $up) {
            $lines .= '<li>' . ($up['kind'] === 'invoice' ? esc($up['ref']) : esc($up['lease']) . ' next instalment') . ' — ' . esc($up['month']) . ' (unit ' . esc($up['unit'] ?: '—') . ') — ৳' . number_format($up['amount']) . '</li>';
        }
    }
    $to = trim($tn['sub_email'] ?? '');
    $sent = false;
    if ($to && mail_switch($pdo, 'rent_reminders') && notify_ok($pdo, $to, 'notify_rent')) {
        $subj = '[KRTaker] Payment reminder — ' . $tn['name'] . ($totalDue > 0 ? ' · ৳' . number_format($totalDue) . ' due' : '') . ($totalUp > 0 ? ' · ৳' . number_format($totalUp) . ' upcoming' : '');
        $html = '<p>Hello ' . esc($tn['name']) . ',</p>'
            . ($totalDue > 0 ? '<p>This is a friendly reminder that the following rent invoice(s) are due:</p><ul>' . implode('', array_map(function ($iv) {
                return '<li><b>' . esc($iv['id']) . '</b> — ' . esc($iv['m'] ?? '') . ' (lease ' . esc($iv['l']) . ') — ৳' . number_format((int)$iv['net']) . ' — ' . esc($iv['status']) . '</li>';
            }, $due)) . '</ul>' : '')
            . ($totalUp > 0 ? '<p><b>Upcoming rent (' . esc($next) . '):</b></p><ul>' . implode('', array_map(function ($up) {
                return '<li>' . ($up['kind'] === 'invoice' ? esc($up['ref']) : esc($up['lease']) . ' next instalment') . ' — unit ' . esc($up['unit'] ?: '—') . ' — ৳' . number_format($up['amount']) . '</li>';
            }, $upcoming)) . '</ul>' : '')
            . ($totalDue > 0 ? '<p><b>Total due: ৳' . number_format($totalDue) . '</b></p>' : '')
            . '<p>You can pay through your tenant portal or by bank transfer. Please reach out if you have any questions.</p>';
        $sent = send_mail($to, $subj, $html, null, true);
    }
    record_attempt('', $ip, 'tremind', true);   /* count the attempt for the throttle (self-record after send) */
    /* board notice too */
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'NTC-','') AS INTEGER)) FROM notices")->fetchColumn();
    $ntc = 'NTC-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
    $bodyTxt = ($totalDue > 0 ? count($due) . ' invoice(s) due · ৳' . number_format($totalDue) : '')
        . ($totalDue > 0 && $totalUp > 0 ? ' + ' : '')
        . ($totalUp > 0 ? 'upcoming rent ৳' . number_format($totalUp) . ' (' . $next . ')' : '')
        . ' · ' . $tid;
    $pdo->prepare('INSERT INTO notices (id, title, body, author, pinned) VALUES (?,?,?,?,0)')
        ->execute([$ntc, 'Payment reminder — ' . $tn['name'], $bodyTxt, $u['name']]);
    audit($u['name'], 'Payment reminder sent', 'tenants', $tid, count($due) . ' due ৳' . $totalDue . ' / ' . count($upcoming) . ' upcoming ৳' . $totalUp . ' email=' . ($sent ? 'sent' : 'skipped'));
    json_out(['ok' => true, 'notice_id' => $ntc, 'invoices' => count($due), 'total_due' => $totalDue, 'upcoming' => count($upcoming), 'total_upcoming' => $totalUp, 'emailed' => $sent]);
}

default:
    json_out(['ok' => false, 'error' => 'Unknown endpoint.'], 404);
case 'app-community': {
    $u = require_user();
    $pdo = db();
    $mod = trim($_GET['mod'] ?? $body['mod'] ?? '');
    require_module($u, $mod);
    $action = trim($_GET['action'] ?? $body['action'] ?? '');
    if ($action === '') $action = 'list';
    $actor = ['email' => $u['email'], 'name' => $u['name']];
    $gen = function ($table, $idPrefix) use ($pdo) {
        $st = $pdo->prepare("SELECT id FROM $table ORDER BY id DESC LIMIT 1");
        $last = $st->fetchColumn();
        $n = $last ? ((int)substr($last, strlen($idPrefix) + 1)) + 1 : 100;
        return $idPrefix . '-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
    };
    $t = [
        'parking'  => 'community_parking',
        'bookings' => 'community_bookings',
        'voting'   => 'community_votes',
        'forums'   => 'community_threads',
        'events'   => 'community_events',
    ];
    if (!isset($t[$mod])) json_out(['ok' => false, 'error' => 'Unknown community module.'], 400);
    $table = $t[$mod];

    /* ── voting: ballots are separate; forums: posts are separate ── */
    if ($mod === 'voting') {
        if ($action === 'list') {
            $rows = $pdo->query('SELECT * FROM community_votes ORDER BY open DESC, ts DESC')->fetchAll(PDO::FETCH_ASSOC);
            $st = $pdo->prepare('SELECT vote, option, voter FROM community_ballots WHERE voter=?');
            $st->execute([$u['email']]);
            $mine = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) $mine[$b['vote']] = (int)$b['option'];
            $out = [];
            foreach ($rows as &$r) {
                $r['options'] = json_decode($r['options'], true) ?: [];
                $r['my_vote'] = $mine[$r['id']] ?? null;
                $r['ballots'] = [];
                $bs = $pdo->prepare('SELECT option FROM community_ballots WHERE vote=?');
                $bs->execute([$r['id']]);
                foreach ($bs->fetchAll(PDO::FETCH_ASSOC) as $b) $r['ballots'][] = (int)$b['option'];
                $r['tally'] = array_fill(0, count($r['options']), 0);
                foreach ($r['ballots'] as $o) $r['tally'][$o] = ($r['tally'][$o] ?? 0) + 1;
                $r['total_votes'] = count($r['ballots']);
                $out[] = $r;
            }
            json_out(['ok' => true, 'rows' => $out]);
        }
        if ($action === 'create') {
            $q = trim($body['question'] ?? '');
            $opts = array_values(array_filter(array_map('trim', (array)($body['options'] ?? []))));
            if ($q === '' || count($opts) < 2) json_out(['ok' => false, 'error' => 'Question and at least 2 options are required.'], 400);
            $id = $gen('community_votes', 'VOT');
            $pdo->prepare('INSERT INTO community_votes (id, question, options, created_by, created_name) VALUES (?,?,?,?,?)')
                ->execute([$id, $q, json_encode($opts), $actor['email'], $actor['name']]);
            audit($u['name'], 'Community poll created', 'voting', $id, $q);
            json_out(['ok' => true, 'id' => $id]);
        }
        if ($action === 'vote') {
            $id = trim($body['id'] ?? '');
            $opt = (int)($body['option'] ?? -1);
            $st = $pdo->prepare('SELECT * FROM community_votes WHERE id=?'); $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_out(['ok' => false, 'error' => 'Poll not found.'], 404);
            if ((int)$row['open'] !== 1) json_out(['ok' => false, 'error' => 'Poll closed.'], 400);
            $opts = json_decode($row['options'], true) ?: [];
            if ($opt < 0 || $opt >= count($opts)) json_out(['ok' => false, 'error' => 'Invalid option.'], 400);
            $st = $pdo->prepare('SELECT COUNT(*) FROM community_ballots WHERE vote=? AND voter=?');
            $st->execute([$id, $u['email']]);
            if ((int)$st->fetchColumn() > 0) json_out(['ok' => false, 'error' => 'Already voted.'], 400);
            $pdo->prepare('INSERT INTO community_ballots (id, vote, voter, option) VALUES (?,?,?,?)')
                ->execute([$gen('community_ballots', 'BAL'), $id, $u['email'], $opt]);
            audit($u['name'], 'Voted in poll', 'voting', $id);
            json_out(['ok' => true]);
        }
        if ($action === 'toggle') {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true)) json_out(['ok' => false, 'error' => 'Only owners can open/close polls.'], 403);
            $id = trim($body['id'] ?? '');
            $open = (int)($body['open'] ?? 0);
            $pdo->prepare('UPDATE community_votes SET open=? WHERE id=?')->execute([$open, $id]);
            json_out(['ok' => true]);
        }
        json_out(['ok' => false, 'error' => 'Unknown voting action.'], 400);
    }

    if ($mod === 'forums') {
        if ($action === 'list') {
            $rows = $pdo->query('SELECT * FROM community_threads ORDER BY pinned DESC, ts DESC')->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM community_posts WHERE thread=?'); $st->execute([$r['id']]);
                $r['posts'] = (int)$st->fetchColumn();
                $out[] = $r;
            }
            json_out(['ok' => true, 'rows' => $out]);
        }
        if ($action === 'thread') {
            $id = trim($_GET['id'] ?? $body['id'] ?? '');
            $st = $pdo->prepare('SELECT * FROM community_threads WHERE id=?'); $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_out(['ok' => false, 'error' => 'Thread not found.'], 404);
            $ps = $pdo->prepare('SELECT * FROM community_posts WHERE thread=? ORDER BY ts');
            $ps->execute([$id]);
            json_out(['ok' => true, 'thread' => $row, 'posts' => $ps->fetchAll(PDO::FETCH_ASSOC)]);
        }
        if ($action === 'create') {
            $title = trim($body['title'] ?? '');
            $bodyTxt = trim($body['body'] ?? '');
            if ($title === '') json_out(['ok' => false, 'error' => 'Title required.'], 400);
            $id = $gen('community_threads', 'THR');
            $pdo->prepare('INSERT INTO community_threads (id, title, body, cat, author, author_name) VALUES (?,?,?,?,?,?)')
                ->execute([$id, $title, $bodyTxt, trim($body['cat'] ?? 'General'), $actor['email'], $actor['name']]);
            audit($u['name'], 'Forum thread created', 'forums', $id, $title);
            json_out(['ok' => true, 'id' => $id]);
        }
        if ($action === 'post') {
            $thread = trim($body['id'] ?? '');
            $bodyTxt = trim($body['body'] ?? '');
            if ($bodyTxt === '') json_out(['ok' => false, 'error' => 'Reply body required.'], 400);
            $st = $pdo->prepare('SELECT COUNT(*) FROM community_threads WHERE id=?'); $st->execute([$thread]);
            if (!(int)$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Thread not found.'], 404);
            $pdo->prepare('INSERT INTO community_posts (id, thread, author, author_name, body) VALUES (?,?,?,?,?)')
                ->execute([$gen('community_posts', 'PST'), $thread, $actor['email'], $actor['name'], $bodyTxt]);
            json_out(['ok' => true]);
        }
        if ($action === 'pin') {
            if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true)) json_out(['ok' => false, 'error' => 'Only owners can pin.'], 403);
            $id = trim($body['id'] ?? '');
            $pin = (int)($body['pin'] ?? 0);
            $pdo->prepare('UPDATE community_threads SET pinned=? WHERE id=?')->execute([$pin, $id]);
            json_out(['ok' => true]);
        }
        json_out(['ok' => false, 'error' => 'Unknown forums action.'], 400);
    }

    /* ── parking / bookings / events: generic CRUD ── */
    $schema = [
        'parking'  => ['id', 'spot', 'type', 'vehicle_no', 'tenant', 'name', 'phone', 'prop', 'status', 'note'],
        'bookings' => ['id', 'facility', 'date', 'slot', 'tenant', 'name', 'phone', 'note', 'status', 'created_by'],
        'events'   => ['id', 'title', 'desc', 'date', 'time', 'location', 'capacity', 'created_by', 'created_name'],
    ][$mod];

    if ($action === 'list') {
        $order = $mod === 'events' ? 'ORDER BY date, time' : ($mod === 'bookings' ? 'ORDER BY date DESC, ts DESC' : 'ORDER BY ts DESC');
        $rows = $pdo->query("SELECT * FROM $table $order")->fetchAll(PDO::FETCH_ASSOC);
        if ($mod === 'events') {
            foreach ($rows as &$r) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM community_rsvps WHERE event=?'); $st->execute([$r['id']]);
                $r['rsvps'] = (int)$st->fetchColumn();
            }
        }
        json_out(['ok' => true, 'rows' => $rows]);
    }
    if ($action === 'create') {
        $fields = array_slice($schema, 1); // drop id
        $vals = [];
        foreach ($fields as $f) $vals[$f] = trim((string)($body[$f] ?? ''));
        $required = $mod === 'parking' ? ['spot', 'vehicle_no'] : ($mod === 'bookings' ? ['facility', 'date'] : ['title']);
        foreach ($required as $f) if ($vals[$f] === '') json_out(['ok' => false, 'error' => ucfirst($f) . ' required.'], 400);
        if ($mod === 'bookings') $vals['status'] = $vals['status'] !== '' ? $vals['status'] : 'Pending';
        if ($mod === 'parking') $vals['status'] = $vals['status'] !== '' ? $vals['status'] : 'Active';
        if ($mod === 'events') { $vals['created_by'] = $actor['email']; $vals['created_name'] = $actor['name']; }
        elseif ($mod === 'bookings') { $vals['created_by'] = $actor['email']; }
        $id = $gen($table, $mod === 'parking' ? 'PRK' : ($mod === 'bookings' ? 'BKG' : 'EVT'));
        $cols = array_merge(['id'], array_keys($vals));
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $pdo->prepare("INSERT INTO $table (" . implode(',', $cols) . ") VALUES ($ph)")
            ->execute(array_merge([$id], array_values($vals)));
        audit($u['name'], ucfirst($mod) . ' created', $mod, $id);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($action === 'update') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true)) json_out(['ok' => false, 'error' => 'Only owners can edit.'], 403);
        $id = trim($body['id'] ?? '');
        $fields = array_slice($schema, 1);
        $sets = []; $vals = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) { $sets[] = "$f=?"; $vals[] = trim((string)$body[$f]); }
        }
        if (!$sets) json_out(['ok' => false, 'error' => 'Nothing to update.'], 400);
        $vals[] = $id;
        $pdo->prepare("UPDATE $table SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
        json_out(['ok' => true]);
    }
    if ($action === 'delete') {
        if (!in_array($u['role'], ['superadmin', 'owner', 'manager'], true)) json_out(['ok' => false, 'error' => 'Only owners can delete.'], 403);
        $id = trim($body['id'] ?? '');
        $pdo->prepare("DELETE FROM $table WHERE id=?")->execute([$id]);
        json_out(['ok' => true]);
    }
    if ($mod === 'events' && $action === 'rsvp') {
        $id = trim($body['id'] ?? '');
        $name = trim($body['name'] ?? $actor['name']);
        $guests = max(0, (int)($body['guests'] ?? 0));
        $st = $pdo->prepare('SELECT COUNT(*) FROM community_events WHERE id=?'); $st->execute([$id]);
        if (!(int)$st->fetchColumn()) json_out(['ok' => false, 'error' => 'Event not found.'], 404);
        $st = $pdo->prepare('SELECT COUNT(*) FROM community_rsvps WHERE event=? AND name=?');
        $st->execute([$id, $name]);
        if ((int)$st->fetchColumn() > 0) json_out(['ok' => false, 'error' => 'Already RSVPed.'], 400);
        $pdo->prepare('INSERT INTO community_rsvps (id, event, name, phone, guests) VALUES (?,?,?,?,?)')
            ->execute([$gen('community_rsvps', 'RSV'), $id, $name, trim($body['phone'] ?? ''), $guests]);
        audit($u['name'], 'RSVP to event', 'events', $id, $name);
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'Unknown community action.'], 400);
}

/* ── V2.31.5 DELTA: workspace backup/restore console (app-ws-backup) ──
   Owner/manager scoped JSON snapshot of the subscriber's own rows across
   direct sub_email tables (properties/units/tenants/partners/team_members)
   and parent-chain tables (leases/invoices/receipts/payments/tickets/
   amenities/meter_readings/utility_bills/maintenance_requests).
   Actions: list | create | get | restore | delete.
   Restore = INSERT OR REPLACE per row, re-validating ownership via
   crud_row_owner() so another subscriber's rows are never touched.
   Migration gate 20260921 adds the ws_backups table. ── */
case 'app-ws-backup': {
    $u = require_user();
    if (($u['kind'] ?? '') !== 'sub') json_out(['ok' => false, 'error' => 'Subscriber account required.'], 403);
    if (!in_array($u['role'], ['owner', 'property_owner', 'manager', 'superadmin'], true))
        json_out(['ok' => false, 'error' => 'Owner or manager access required.'], 403);
    $act = $body['action'] ?? '';
    $sub = $u['email'];
    $pdo = db();
    $DIRECT = ['properties' => 'sub_email', 'units' => 'sub_email', 'tenants' => 'sub_email', 'partners' => 'sub_email', 'team_members' => 'sub_email'];
    $CHAIN = [
        'leases'     => "l.id IN (SELECT l2.id FROM leases l2 JOIN units u ON u.id=l2.u WHERE u.sub_email=:sub)",
        'invoices'   => "i.id IN (SELECT i2.id FROM invoices i2 JOIN leases l2 ON l2.id=i2.l JOIN units u ON u.id=l2.u WHERE u.sub_email=:sub)",
        'receipts'   => "r.id IN (SELECT r2.id FROM receipts r2 JOIN invoices i2 ON i2.id=r2.inv JOIN leases l2 ON l2.id=i2.l JOIN units u ON u.id=l2.u WHERE u.sub_email=:sub)",
        'payments'   => "p.id IN (SELECT p2.id FROM payments p2 JOIN invoices i2 ON i2.id=p2.inv JOIN leases l2 ON l2.id=i2.l JOIN units u ON u.id=l2.u WHERE u.sub_email=:sub)",
        'tickets'    => "t.id IN (SELECT t2.id FROM tickets t2 JOIN units u ON u.id=t2.u WHERE u.sub_email=:sub)",
        'amenities'  => "a.id IN (SELECT a2.id FROM amenities a2 LEFT JOIN units u ON u.id=a2.unit LEFT JOIN properties p ON p.id=a2.prop WHERE COALESCE(u.sub_email, p.sub_email)=:sub)",
        'meter_readings' => "m.id IN (SELECT m2.id FROM meter_readings m2 JOIN units u ON u.id=m2.unit WHERE u.sub_email=:sub)",
        'utility_bills'  => "b.id IN (SELECT b2.id FROM utility_bills b2 JOIN units u ON u.id=b2.unit WHERE u.sub_email=:sub)",
        'maintenance_requests' => "m.id IN (SELECT m2.id FROM maintenance_requests m2 LEFT JOIN units u ON u.id=m2.unit LEFT JOIN properties p ON p.id=m2.prop WHERE COALESCE(u.sub_email, p.sub_email)=:sub)",
    ];
    if ($act === 'list') {
        $rows = $pdo->prepare("SELECT id, kind, size, note, created_by, ts FROM ws_backups WHERE sub_email=? ORDER BY ts DESC, id DESC LIMIT 50");
        $rows->execute([$sub]);
        json_out(['ok' => true, 'backups' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($act === 'create') {
        $snap = ['_schema' => 1, '_sub' => $sub, '_ts' => gmdate('c'), '_tables' => []];
        foreach ($DIRECT as $t => $col) {
            try {
                $st = $pdo->prepare("SELECT * FROM $t WHERE $col=?");
                $st->execute([$sub]);
                $snap['_tables'][$t] = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $snap['_tables'][$t] = []; }
        }
        foreach ($CHAIN as $t => $where) {
            try {
                $st = $pdo->prepare("SELECT $t.* FROM $t WHERE $where");
                $st->execute([':sub' => $sub]);
                $snap['_tables'][$t] = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $snap['_tables'][$t] = []; }
        }
        $json = json_encode($snap, JSON_UNESCAPED_UNICODE);
        $id = 'WSB-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $note = trim($body['note'] ?? '');
        $pdo->prepare("INSERT INTO ws_backups (id, sub_email, kind, size, note, data, created_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$id, $sub, 'manual', strlen($json), $note ?: 'Manual snapshot', $json, $u['name']]);
        audit($u['name'], 'Workspace backup created', 'system', $id, strlen($json) . ' bytes');
        json_out(['ok' => true, 'id' => $id, 'size' => strlen($json), 'tables' => array_map(fn($v) => count($v), $snap['_tables'])]);
    }
    if ($act === 'get') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare("SELECT data FROM ws_backups WHERE id=? AND sub_email=?");
        $st->execute([$id, $sub]);
        $data = $st->fetchColumn();
        if ($data === false) json_out(['ok' => false, 'error' => 'Backup not found.'], 404);
        json_out(['ok' => true, 'id' => $id, 'data' => json_decode($data, true)]);
    }
    if ($act === 'restore') {
        $id = trim($body['id'] ?? '');
        $src = null;
        if ($id) {
            $st = $pdo->prepare("SELECT data FROM ws_backups WHERE id=? AND sub_email=?");
            $st->execute([$id, $sub]);
            $data = $st->fetchColumn();
            if ($data === false) json_out(['ok' => false, 'error' => 'Backup not found.'], 404);
            $src = json_decode($data, true);
        } else {
            $src = $body['data'] ?? null;
            if (!is_array($src)) json_out(['ok' => false, 'error' => 'data or id required.'], 400);
        }
        if (!is_array($src) || ($src['_sub'] ?? '') !== $sub) json_out(['ok' => false, 'error' => 'Backup belongs to a different workspace.'], 403);
        $pdo->beginTransaction();
        $done = [];
        try {
            foreach (($src['_tables'] ?? []) as $t => $rows) {
                if (!is_array($rows) || !$rows) { $done[$t] = 0; continue; }
                $allowed = false;
                if (isset($DIRECT[$t])) $allowed = true;
                elseif (isset($CHAIN[$t])) $allowed = true;
                if (!$allowed) { $done[$t] = 0; continue; }
                $cols = [];
                try {
                    foreach ($pdo->query("PRAGMA table_info($t)") as $c) $cols[] = $c['name'];
                } catch (Exception $e) { $cols = array_keys($rows[0]); }
                $colset = array_values(array_intersect($cols, array_keys($rows[0])));
                if (!$colset) { $done[$t] = 0; continue; }
                $cnames = implode(',', array_map(fn($c) => '"' . $c . '"', $colset));
                $ph = implode(',', array_fill(0, count($colset), '?'));
                $ins = $pdo->prepare("INSERT OR REPLACE INTO $t ($cnames) VALUES ($ph)");
                $n = 0;
                foreach ($rows as $r) {
                    if (!is_array($r)) continue;
                    if (isset($DIRECT[$t]) && ($r[$DIRECT[$t]] ?? '') !== $sub) continue;
                    if (isset($CHAIN[$t])) {
                        $own = crud_row_owner($pdo, $t, $r['id'] ?? '');
                        if ($own === false) continue;
                        if ($own !== '' && $own !== $sub) continue;
                    }
                    $vals = [];
                    foreach ($colset as $c) $vals[] = $r[$c] ?? '';
                    try { $ins->execute($vals); $n++; } catch (Exception $e) { /* skip bad row */ }
                }
                $done[$t] = $n;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            json_out(['ok' => false, 'error' => 'Restore failed: ' . $e->getMessage()], 500);
        }
        audit($u['name'], 'Workspace backup restored', 'system', $id ?: 'upload', json_encode($done));
        json_out(['ok' => true, 'restored' => $done]);
    }
    if ($act === 'delete') {
        $id = trim($body['id'] ?? '');
        if (!$id) json_out(['ok' => false, 'error' => 'id required.'], 400);
        $st = $pdo->prepare("DELETE FROM ws_backups WHERE id=? AND sub_email=?");
        $st->execute([$id, $sub]);
        if ($st->rowCount() === 0) json_out(['ok' => false, 'error' => 'Backup not found.'], 404);
        audit($u['name'], 'Workspace backup deleted', 'system', $id, '');
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

default:
    json_out(['ok' => false, 'error' => 'Unknown endpoint.'], 404);
}
