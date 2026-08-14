function admin_cfg($pdo, $key, $def = '') {
    $st = $pdo->prepare('SELECT v FROM admin_settings WHERE k=?'); $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $def : (string)$v;
}
function admin_cfg_save($pdo, $key, $val) {
    $pdo->prepare("INSERT INTO admin_settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")
        ->execute([$key, (string)$val]);
}
/* ── SMS gateway (bharakhata parity: SMS reminders + phone OTP) ──
   Providers: 'log' (default — records to sms_log, no real send; safe on staging)
              'bulksmsbd' (HTTP POST to sms_api_url with api_key + senderid).
   Config keys live in admin_settings via admin_cfg. */
