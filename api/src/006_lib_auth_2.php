function recent_any($email, $ip, $mins, $email_max, $ip_max, $kinds = null) {
    try {
        $pdo = db();
        $kindSql = $kinds ? " AND kind IN (" . implode(',', array_fill(0, count($kinds), '?')) . ")" : '';
        $st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE ts >= datetime('now', ?) AND email=?" . $kindSql);
        $st->execute(array_merge(['-' . (int)$mins . ' minutes', strtolower(trim($email))], $kinds ?: []));
        $byEmail = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM auth_attempts WHERE ts >= datetime('now', ?) AND ip=?" . $kindSql);
        $st->execute(array_merge(['-' . (int)$mins . ' minutes', $ip], $kinds ?: []));
        $byIp = (int)$st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
    /* non-positive max = no limit for that dimension */
    return ($email_max > 0 && $byEmail >= $email_max) || ($ip_max > 0 && $byIp >= $ip_max);
}

/* v3.78: seconds until the sliding throttle window clears (oldest failure + window − now).
   $kinds = null → count ALL failures (recent_fails semantics: login/OTP);
   $kinds = [...] → kind-filtered (recent_any semantics: register/resend/forgot/newsletter/contact/payinit). */
function retry_after_secs($email, $ip, $mins, $kinds = null) {
    try {
        $pdo = db();
        $kindSql = $kinds ? " AND kind IN (" . implode(',', array_fill(0, count($kinds), '?')) . ")" : '';
        $args0 = ['-' . (int)$mins . ' minutes'];
        foreach (($kinds ?: []) as $k) $args0[] = (string)$k;
        $best = 0;
        foreach ([['email', strtolower(trim($email))], ['ip', $ip]] as $pair) {
            if ($pair[0] === 'email' && $pair[1] === '') continue;
            $st = $pdo->prepare("SELECT MIN(strftime('%s', ts)) FROM auth_attempts WHERE ok=0 AND ts >= datetime('now', ?) AND " . $pair[0] . "=?" . $kindSql);
            $st->execute(array_merge($args0, [$pair[1]], $kinds ?: []));
            $old = (int)$st->fetchColumn();
            if ($old > 0) $best = max($best, $old + (int)$mins * 60 - time());
        }
        if ($best <= 0) $best = 1;
        return max(1, min((int)$mins * 60, $best));
    } catch (Exception $e) { return (int)$mins * 60; }
}

/* v3.78: emit a 429 with a standards-compliant Retry-After header + JSON retry_after field */
function throttle_out($msg, $email, $ip, $mins, $kinds = null) {
    $ra = retry_after_secs($email, $ip, $mins, $kinds);
    header('Retry-After: ' . $ra);
    json_out(['ok' => false, 'error' => $msg, 'retry_after' => $ra], 429);
}

