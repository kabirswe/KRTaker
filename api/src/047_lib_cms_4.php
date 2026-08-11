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
function WEBHOOK_EVENTS() {
    return [
        ['id' => 'payment.success',       'label' => 'Payment success',             'ic' => '💳'],
        ['id' => 'ticket.created',        'label' => 'Maintenance ticket created',  'ic' => '🎫'],
        ['id' => 'lease.signed',          'label' => 'Lease signed (move-in)',      'ic' => '📄'],
        ['id' => 'subscriber.registered', 'label' => 'New subscriber registered',   'ic' => '👥'],
        ['id' => 'test.ping',             'label' => 'Test ping (manual)',          'ic' => '🧪'],
    ];
}
/* Deliver $event to every active webhook subscribed to it. Body = {event, ts, data};
   signed with HMAC-SHA256 (secret) → X-KRTaker-Signature: sha256=…; every attempt is
   logged to webhook_logs and the hook's ok/fail counters updated. Never throws. */
function webhook_dispatch($pdo, $event, $payload) {
    $hooks = [];
    try {
        $st = $pdo->prepare('SELECT * FROM webhooks WHERE active=1');
        $st->execute();
        $hooks = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return; }
    foreach ($hooks as $h) {
        $evs = json_decode((string)$h['events'], true);
        $evs = is_array($evs) ? $evs : [];
        if (!in_array($event, $evs, true)) continue;
        $body = json_encode(['event' => $event, 'ts' => gmdate('c'), 'data' => $payload], JSON_UNESCAPED_UNICODE);
        $sig = hash_hmac('sha256', $body, (string)$h['secret']);
        $t0 = microtime(true);
        $ch = curl_init((string)$h['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-KRTaker-Event: ' . $event,
                'X-KRTaker-Signature: sha256=' . $sig,
                'X-KRTaker-Delivery: wh_' . bin2hex(random_bytes(8)),
                'X-KRTaker-Hook: ' . (int)$h['id'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $ms = (int)((microtime(true) - $t0) * 1000);
        $ok = ($err === '' && $status >= 200 && $status < 300);
        $respTxt = $err !== '' ? ('ERR: ' . $err) : (string)$resp;
        if (strlen($respTxt) > 400) $respTxt = substr($respTxt, 0, 400);
        try {
            $pdo->prepare('INSERT INTO webhook_logs (hook, event, attempt, ok, status, ms, payload, response) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([(int)$h['id'], $event, 1, $ok ? 1 : 0, $status, $ms, $body, $respTxt]);
            $pdo->prepare($ok
                ? "UPDATE webhooks SET last_status=?, last_at=datetime('now'), ok_count=ok_count+1 WHERE id=?"
                : "UPDATE webhooks SET last_status=?, last_at=datetime('now'), fail_count=fail_count+1 WHERE id=?")
                ->execute([$status, (int)$h['id']]);
        } catch (Exception $e) {}
    }
}
/* Password policy (configurable via App Settings — sec_password_min_len / sec_password_complex).
   Returns '' when OK, or an error message. Clamped so an admin can't set a policy that rejects everything. */
