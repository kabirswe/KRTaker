function record_attempt($email, $ip, $kind, $ok) {
    try {
        db()->prepare('INSERT INTO auth_attempts (email, ip, kind, ok) VALUES (?,?,?,?)')
            ->execute([strtolower(trim($email)), $ip, $kind, $ok ? 1 : 0]);
    } catch (Exception $e) { /* never let throttling break auth */ }
    /* opportunistic prune — keep last 7 days */
    if (random_int(1, 50) === 1) {
        try {
            db()->prepare("DELETE FROM auth_attempts WHERE ts < datetime('now','-7 days')")->execute();
        } catch (Exception $e) {}
    }
}
function recent_fails($email, $ip, $mins, $email_max, $ip_max) {
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE ok=0 AND ts >= datetime('now', ?) AND email=?");
        $st->execute(['-' . (int)$mins . ' minutes', strtolower(trim($email))]);
        $byEmail = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE ok=0 AND ts >= datetime('now', ?) AND ip=?");
        $st->execute(['-' . (int)$mins . ' minutes', $ip]);
        $byIp = (int)$st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
    return $byEmail >= $email_max || $byIp >= $ip_max;
}
function auth_blocked($email, $ip) {
    /* login lockout configurable via App Settings (sec_login_attempts / sec_lockout_minutes) */
    $pdo = db();
    $max = (int)admin_cfg($pdo, 'sec_login_attempts', 10); if ($max < 3) $max = 3; if ($max > 100) $max = 100;
    $mins = (int)admin_cfg($pdo, 'sec_lockout_minutes', 15); if ($mins < 1) $mins = 1; if ($mins > 1440) $mins = 1440;
    return recent_fails($email, $ip, $mins, $max, $max * 4);
}
function otp_blocked($email, $ip) {
    /* OTP: 5 fails/email or 30/IP in 15 min → lock */
    return recent_fails($email, $ip, 15, 5, 30);
}

/* ---------- SMTP (raw socket, STARTTLS, AUTH LOGIN) ---------- */
