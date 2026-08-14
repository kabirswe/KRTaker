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
    /* V2.17: refresh last_seen (throttled to ≤1 write/5 min per session) */
    try {
        $pdo->prepare("UPDATE app_tokens SET last_seen=datetime('now') WHERE token=? AND (last_seen IS NULL OR last_seen < datetime('now','-5 minutes'))")
            ->execute([hash('sha256', $m[1])]);
    } catch (Exception $e) { /* never let session bookkeeping break requests */ }
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
