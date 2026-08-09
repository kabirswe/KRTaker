function current_user() {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$auth) {
        $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($h) $auth = $h;
    }
    if (!$auth) $auth = $_SERVER['Authorization'] ?? '';
    if (!preg_match('/Bearer\s+(\S+)/i', $auth, $m)) return null;
    $pdo = db();
    $st = $pdo->prepare('SELECT t.user_id, t.kind, t.expires_at, t.impersonator FROM app_tokens t WHERE t.token=?');
    $st->execute([hash('sha256', $m[1])]);   /* Phase 6: tokens hashed at rest */
    $tok = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tok) return null;
    if ($tok['expires_at'] && strtotime($tok['expires_at']) < time()) return null;
    $row = null;
    if ($tok['kind'] === 'sub') {
        $st = $pdo->prepare('SELECT * FROM subscribers WHERE id=? AND status="active"');
        $st->execute([$tok['user_id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } elseif ($tok['kind'] === 'team') {
        /* SA1 v20: team member → overlay the parent subscriber (plan/limits/scoping) + member role/name */
        $st = $pdo->prepare('SELECT * FROM team_members WHERE id=? AND status="active"');
        $st->execute([$tok['user_id']]);
        $tm = $st->fetch(PDO::FETCH_ASSOC);
        if ($tm) {
            $st = $pdo->prepare('SELECT * FROM subscribers WHERE email=? AND status="active"');
            $st->execute([$tm['sub_email']]);
            $par = $st->fetch(PDO::FETCH_ASSOC);
            if ($par) {
                $row = $par;
                $row['kind'] = 'sub';           /* inherit subscriber semantics */
                $row['team_member'] = true;
                $row['team_id'] = $tm['id'];
                $row['name'] = $tm['name'];     /* member identity for audit/display */
                $row['role'] = $tm['role'];
            }
        }
    } else {
        $st = $pdo->prepare('SELECT * FROM app_users WHERE id=? AND active=1');
        $st->execute([$tok['user_id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return null;
    if ($tok['kind'] !== 'team') $row['kind'] = $tok['kind'];   /* team rows already carry 'sub' (inherit plan/scoping) */
    $row['impersonator'] = $tok['impersonator'] ?? '';
    $row['token_expires'] = $tok['expires_at'] ?? '';
    return $row;
}

function require_user() {
    $u = current_user();
    if (!$u) json_out(['ok' => false, 'error' => 'Unauthorized — login required.'], 401);
    return $u;
}

function require_module($u, $mod) {
    $allowed = effective_modules($u);
    if (!in_array($mod, $allowed, true)) {
        $plan = plan_for_user($u);
        json_out(['ok' => false, 'error' => "Access denied — $mod not available for {$u['role']} on $plan."], 403);
    }
}

/* ═══ SA1 v28: TOTP two-factor auth (RFC 6238, pure PHP — no deps) ═══ */
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

