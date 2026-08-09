function password_policy_error($pdo, $pw) {
    $min = (int)admin_cfg($pdo, 'sec_password_min_len', 6); if ($min < 4) $min = 4; if ($min > 64) $min = 64;
    if (strlen((string)$pw) < $min) return 'Password must be at least ' . $min . ' characters.';
    if (admin_cfg($pdo, 'sec_password_complex', '0') === '1' &&
        (!preg_match('/[A-Za-z]/', (string)$pw) || !preg_match('/[0-9]/', (string)$pw)))
        return 'Password must include at least one letter and one number.';
    return '';
}
/* Master switch for a mail class (App Settings — mail_*). Per-user opt-outs (notify_ok) still apply. */
