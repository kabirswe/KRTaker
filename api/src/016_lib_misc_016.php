function totp_b32_decode($s) {
    $s = strtoupper(preg_replace('/[^A-Z2-7]/', '', (string)$s));
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++) $bits .= str_pad(decbin(strpos($map, $s[$i])), 5, '0', STR_PAD_LEFT);
    $out = '';
    for ($i = 0, $n = strlen($bits); $i + 8 <= $n; $i += 8) $out .= chr(bindec(substr($bits, $i, 8)));
    return $out;
}
function totp_b32_encode($bin) {
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $n = strlen($bin); $i < $n; $i++) $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
    $out = '';
    for ($i = 0, $n = strlen($bits); $i + 5 <= $n; $i += 5) $out .= $map[bindec(substr($bits, $i, 5))];
    return $out;
}
function totp_secret_new($bytes = 20) {
    return totp_b32_encode(random_bytes($bytes));
}
function totp_code($secret, $t = null, $window = 0) {
    if ($t === null) $t = time();
    $counter = pack('N2', 0, (int)floor($t / 30) + $window);
    $hash = hash_hmac('sha1', $counter, totp_b32_decode($secret), true);
    $off = ord($hash[strlen($hash) - 1]) & 0x0F;
    $bin = ((ord($hash[$off]) & 0x7F) << 24) | ((ord($hash[$off + 1]) & 0xFF) << 16) | ((ord($hash[$off + 2]) & 0xFF) << 8) | (ord($hash[$off + 3]) & 0xFF);
    return str_pad((string)($bin % 1000000), 6, '0', STR_PAD_LEFT);
}
function totp_verify($secret, $code) {
    $code = preg_replace('/\D/', '', (string)$code);
    if (strlen($code) !== 6) return false;
    for ($w = -1; $w <= 1; $w++) {
        if (hash_equals(totp_code($secret, null, $w), $code)) return true;
    }
    return false;
}
function totp_uri($email, $secret) {
    $iss = rawurlencode('KRTaker');
    $acc = rawurlencode($email);
    return "otpauth://totp/$iss:$acc?secret=$secret&issuer=$iss&period=30&digits=6";
}

/* ── V2.16: email-OTP 2FA helpers ── */
function mask_email($e) {
    $e = (string)$e;
    $p = strpos($e, '@');
    if ($p === false || $p < 2) return 'your account email';
    return substr($e, 0, 2) . '••••' . substr($e, $p - 1);
}
function otp_code_new() {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}
/* Send a 6-digit login code to the user's email; stores sha256 + 5-min expiry. */
function otp_send($pdo, $u) {
    $code = otp_code_new();
    $pdo->prepare("UPDATE app_users SET otp_hash=?, otp_expires=datetime('now','+5 minutes'), otp_fails=0 WHERE id=?")
        ->execute([hash('sha256', $code), $u['id']]);
    try { send_mail($u['email'], 'Your KRTaker login code', otp_email_html($code, $u['name'])); } catch (Throwable $e) { /* mail failure must never break the auth flow */ }
    audit($u['name'], 'OTP emailed', 'auth', (string)$u['id']);
    return true;
}
function otp_verify($pdo, $u, $code) {
    $code = trim((string)$code);
    if ($code === '' || strlen($code) !== 6 || !ctype_digit($code)) return false;
    if (empty($u['otp_hash']) || empty($u['otp_expires'])) return false;
    if (strtotime($u['otp_expires']) < time()) return false;
    if ((int)($u['otp_fails'] ?? 0) >= 5) return false;
    if (!hash_equals($u['otp_hash'], hash('sha256', $code))) {
        $pdo->prepare('UPDATE app_users SET otp_fails=otp_fails+1 WHERE id=?')->execute([$u['id']]);
        return false;
    }
    $pdo->prepare("UPDATE app_users SET otp_hash='', otp_expires='', otp_fails=0 WHERE id=?")->execute([$u['id']]);
    return true;
}

/* ── V2.17: session registry + new-sign-in intelligence ── */
/* Minimal UA → "Chrome on Windows" style label for session lists + login alerts. */
function ua_device_label($ua) {
    $ua = (string)$ua;
    if ($ua === '') return 'Unknown device';
    $os = 'Unknown OS';
    foreach ([['Windows', 'Windows'], ['Mac OS X|Macintosh', 'macOS'], ['iPhone', 'iPhone'], ['iPad', 'iPad'], ['Android', 'Android'], ['Linux', 'Linux']] as $p) {
        if (preg_match('/' . $p[0] . '/i', $ua)) { $os = $p[1]; break; }
    }
    $browser = 'Browser';
    foreach ([['Edg/', 'Edge'], ['OPR/', 'Opera'], ['Firefox/', 'Firefox'], ['Chrome/', 'Chrome'], ['Safari/', 'Safari']] as $p) {
        if (stripos($ua, $p[0]) !== false) { $browser = $p[1]; break; }
    }
    if (stripos($ua, 'Headless') !== false) $browser .= ' (headless)';
    return "$browser on $os";
}
/* True when a successful login comes from an IP this email hasn't succeeded from
   in the last 14 days — i.e. a likely new device/network. Flood-guarded at
   3 alerts/day/email. Toggle via admin_cfg sec_login_alerts (default on). */
function new_login_alert_needed($pdo, $email, $ip) {
    if ($email === '') return false;
    try {
        if ((int)admin_cfg($pdo, 'sec_login_alerts', 1) !== 1) return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE ok=1 AND kind='login' AND email=? AND ip=? AND ts >= datetime('now','-14 days')");
        $st->execute([strtolower(trim($email)), $ip]);
        if ((int)$st->fetchColumn() > 0) return false;
        $st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE kind='login-alert' AND email=? AND ts >= datetime('now','-24 hours')");
        $st->execute([strtolower(trim($email))]);
        if ((int)$st->fetchColumn() >= 3) return false;
        return true;
    } catch (Exception $e) { return false; }
}
function send_login_alert($pdo, $email, $name, $ip) {
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $dev = ua_device_label($ua);
    $when = gmdate('Y-m-d H:i:s') . ' UTC';
    $n = htmlspecialchars((string)$name, ENT_QUOTES);
    $html = "<div style=\"font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto\">"
        . "<h2 style=\"color:#b23b3b;margin:0 0 14px\">🔐 New sign-in to your KRTaker account</h2>"
        . "<p style=\"font-size:14px;line-height:1.6;color:#333\">Hi $n,</p>"
        . "<p style=\"font-size:14px;line-height:1.6;color:#333\">Your account was just signed in to from a device we haven't seen recently:</p>"
        . "<table style=\"border-collapse:collapse;margin:16px 0;font-size:13.5px\">"
        . "<tr><td style=\"padding:6px 14px 6px 0;color:#777;font-weight:700\">Device</td><td style=\"padding:6px 0\">" . htmlspecialchars($dev, ENT_QUOTES) . "</td></tr>"
        . "<tr><td style=\"padding:6px 14px 6px 0;color:#777;font-weight:700\">IP address</td><td style=\"padding:6px 0\">" . htmlspecialchars($ip, ENT_QUOTES) . "</td></tr>"
        . "<tr><td style=\"padding:6px 14px 6px 0;color:#777;font-weight:700\">Time</td><td style=\"padding:6px 0\">" . htmlspecialchars($when, ENT_QUOTES) . "</td></tr>"
        . "</table>"
        . "<p style=\"font-size:14px;line-height:1.6;color:#333\">If this was you, no action is needed.</p>"
        . "<p style=\"font-size:14px;line-height:1.6;color:#333\"><b>If it wasn't you</b>, change your password immediately from <b>Settings → Profile → Change password</b>, and review your active sessions under <b>Settings → Sign-in &amp; sessions</b> to sign out any device you don't recognise.</p>"
        . "<p style=\"font-size:12.5px;color:#999\">— KRTaker security team</p></div>";
    try { send_mail($email, 'New sign-in to your KRTaker account', $html); } catch (Throwable $e) { /* alert mail is best-effort */ }
    try {
        $pdo->prepare("INSERT INTO auth_attempts (email, ip, kind, ok) VALUES (?,?,'login-alert',1)")
            ->execute([strtolower(trim($email)), $ip]);
    } catch (Exception $e) {}
    try { audit($name, 'New sign-in alert emailed', 'auth', (string)$ip); } catch (Throwable $e) {}
}

function make_token($uid, $kind, $impersonator = '', $ttl = TOKEN_TTL) {
    $tok = bin2hex(random_bytes(24));
    $pdo = db();
    /* V2.17: session registry — record where/from-what this session was created */
    $ip = substr((string)client_ip(), 0, 45);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $pdo->prepare('INSERT INTO app_tokens (token, user_id, kind, expires_at, impersonator, ip, ua) VALUES (?,?,?,?,?,?,?)')
        ->execute([hash('sha256', $tok), $uid, $kind, gmdate('Y-m-d H:i:s', time() + $ttl), $impersonator, $ip, $ua]);
    /* SA1 v21: opportunistic prune of expired tokens so app_tokens can't grow unbounded */
    if (random_int(1, 64) === 1) {
        try { $pdo->prepare("DELETE FROM app_tokens WHERE expires_at < datetime('now')")->execute(); } catch (Exception $e) {}
    }
    return $tok;
}

