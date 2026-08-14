function referral_code_for($email) {
    $pdo = db();
    $st = $pdo->prepare('SELECT code FROM referrals WHERE user_email=? ORDER BY ts DESC LIMIT 1');
    $st->execute([$email]);
    $code = $st->fetchColumn();
    if ($code) return $code;
    do {
        $code = 'KR-' . strtoupper(bin2hex(random_bytes(3)));
        $st = $pdo->prepare('SELECT COUNT(*) FROM referrals WHERE code=?'); $st->execute([$code]);
    } while ((int)$st->fetchColumn() > 0);
    $pdo->prepare('INSERT INTO referrals (id, code, user_email, role) VALUES (?,?,?,?)')
        ->execute(['REF-' . strtoupper(bin2hex(random_bytes(3))), $code, $email, 'owner']);
    return $code;
}
/* Phase 13: invoice → tenant/property context for email delivery */
