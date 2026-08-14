function sms_cfg($pdo) {
    return [
        'enabled'   => (int)admin_cfg($pdo, 'sms_enabled', 0),
        'provider'  => admin_cfg($pdo, 'sms_provider', 'log'),
        'api_key'   => admin_cfg($pdo, 'sms_api_key', ''),
        'sender_id' => admin_cfg($pdo, 'sms_sender_id', 'KRTaker'),
        'api_url'   => admin_cfg($pdo, 'sms_api_url', 'https://api.bulksmsbd.com/smsapi'),
    ];
}
function sms_normalize_phone($p) {
    $d = preg_replace('/\D/', '', (string)$p);
    if (strlen($d) === 11 && substr($d, 0, 2) === '01') $d = '88' . $d;
    elseif (strlen($d) === 10 && substr($d, 0, 1) === '1') $d = '880' . $d;
    return $d;
}
function sms_send($pdo, $phone, $message, $provider = null) {
    $cfg = sms_cfg($pdo);
    if (!$cfg['enabled']) return ['ok' => false, 'reason' => 'sms-disabled'];
    $to = sms_normalize_phone($phone);
    if (strlen($to) < 11) return ['ok' => false, 'reason' => 'bad-phone'];
    $prov = $provider ?: $cfg['provider'];
    $ref = 'SMS-' . strtoupper(bin2hex(random_bytes(3)));
    $status = 'sent';
    if ($prov === 'bulksmsbd' && $cfg['api_key'] !== '') {
        $data = http_build_query([
            'api_key' => $cfg['api_key'], 'type' => 'text', 'contacts' => $to,
            'senderid' => $cfg['sender_id'], 'msg' => $message,
        ]);
        $resp = '';
        if (function_exists('curl_init')) {
            $ch = curl_init(rtrim($cfg['api_url'], '/'));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data, CURLOPT_TIMEOUT => 12]);
            $resp = (string)curl_exec($ch); curl_close($ch);
        }
        $status = (strpos($resp, 'SUCCESS') !== false || strpos($resp, '"status":1') !== false) ? 'sent' : 'failed';
    }
    $pdo->prepare('INSERT INTO sms_log (to_phone, message, provider, ref, status) VALUES (?,?,?,?,?)')
        ->execute([$to, mb_substr($message, 0, 480), $prov, $ref, $status]);
    return ['ok' => $status === 'sent', 'to' => $to, 'ref' => $ref, 'provider' => $prov, 'status' => $status];
}
function sms_reminder_text($r) {
    $amt = '৳' . number_format((int)$r['due']);
    return 'KRTaker rent reminder: ' . $r['inv'] . ' ' . $amt . ' due (' . $r['m'] . '). Pay now: https://krtaker.com/app-v3/#/invoices?open=' . rawurlencode($r['inv']) . '&pay=1';
}
/* ── SA1-fullsite v8 (v3.62): outbound webhooks — event catalog + delivery engine ── */
