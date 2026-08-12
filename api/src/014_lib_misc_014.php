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

