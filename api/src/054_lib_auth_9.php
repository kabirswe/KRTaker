function password_policy_error($pdo, $pw) {
    $min = (int)admin_cfg($pdo, 'sec_password_min_len', 6); if ($min < 4) $min = 4; if ($min > 64) $min = 64;
    if (strlen((string)$pw) < $min) return 'Password must be at least ' . $min . ' characters.';
    if (admin_cfg($pdo, 'sec_password_complex', '1') === '1' &&
        (!preg_match('/[A-Za-z]/', (string)$pw) || !preg_match('/[0-9]/', (string)$pw)))
        return 'Password must include at least one letter and one number.';
    $weak = ['password', 'password1', '123456', '1234567', '12345678', '123456789', '1234567890',
        'qwerty', 'qwerty123', 'abc123', 'iloveyou', 'admin', 'admin123', 'letmein', 'welcome',
        'monkey', 'dragon', '111111', '000000', '123123', '654321', '666666', '888888',
        'krtaker', 'krtaker123', 'changeme', 'passw0rd'];
    if (in_array(strtolower((string)$pw), $weak, true))
        return 'That password is too common — choose something harder to guess.';
    return '';
}
/* Master switch for a mail class (App Settings — mail_*). Per-user opt-outs (notify_ok) still apply. */
