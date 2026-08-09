function admin_cfg($pdo, $key, $def = '') {
    $st = $pdo->prepare('SELECT v FROM admin_settings WHERE k=?'); $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $def : (string)$v;
}
function admin_cfg_save($pdo, $key, $val) {
    $pdo->prepare("INSERT INTO admin_settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")
        ->execute([$key, (string)$val]);
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
