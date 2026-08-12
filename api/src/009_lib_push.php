function ec_spki($raw) {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function vapid_public_b64url() {
    $d = openssl_pkey_get_details(openssl_pkey_get_public(VAPID_PUB));
    return b64url_encode("\x04" . $d['ec']['x'] . $d['ec']['y']);
}

function vapid_jwt($aud) {
    $h = b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = b64url_encode(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => VAPID_SUB]));
    $input = $h . '.' . $c;
    openssl_sign($input, $sig, VAPID_PRIV, OPENSSL_ALGO_SHA256);
    /* DER-encoded ECDSA signature → raw r||s (64 bytes) */
    $i = 2; $r = $s = '';
    if (isset($sig[2]) && $sig[2] === "\x02") { $l = ord($sig[3]); $r = substr($sig, 4, $l); $i = 4 + $l; }
    if (isset($sig[$i]) && $sig[$i] === "\x02") { $l = ord($sig[$i + 1]); $s = substr($sig, $i + 2, $l); }
    if (strlen($r) === 33 && $r[0] === "\x00") $r = substr($r, 1);
    if (strlen($s) === 33 && $s[0] === "\x00") $s = substr($s, 1);
    $raw = str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    return $input . '.' . b64url_encode($raw);
}

/* RFC 5869 HKDF-Expand (no re-extract — hash_hkdf() re-extracts and is NOT RFC 8291 compatible) */
function hkdf_expand($prk, $info, $len) {
    $out = ''; $t = ''; $i = 1;
    while (strlen($out) < $len) {
        $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
        $out .= $t; $i++;
    }
    return substr($out, 0, $len);
}

/* Encrypt a JSON payload for a PushSubscription (RFC 8291 aes128gcm) */
function webpush_encrypt($p256dh, $auth, $payload) {
    $ua_pub = b64url_decode($p256dh);
    $ua_auth = b64url_decode($auth);
    /* openssl_pkey_derive() only accepts a PEM string — convert the raw point via SPKI → PEM */
    $ua_pem = openssl_pkey_get_details(openssl_pkey_get_public(ec_spki($ua_pub)))['key'];
    $srv_res = openssl_pkey_get_private(VAPID_PRIV);
    $sd = openssl_pkey_get_details(openssl_pkey_get_public(VAPID_PUB));
    $server_pub = "\x04" . $sd['ec']['x'] . $sd['ec']['y'];
    $salt = random_bytes(16);
    $shared = openssl_pkey_derive($ua_pem, $srv_res);
    $key_info = 'Web Push: info' . $ua_pub . $server_pub;
    $prk = hash_hmac('sha256', $shared, $salt, true);
    $ikm = hkdf_expand($prk, $key_info, 32);
    $prk2 = hash_hmac('sha256', $ikm, $ua_auth, true);
    $cek = hkdf_expand($prk2, "Content-Encoding: aes128gcm\0", 16);
    $nonce = hkdf_expand($prk2, "Content-Encoding: nonce\0", 12);
    $header = $salt . pack('N', 4096) . chr(65) . $server_pub;
    $tag = '';
    $cipher = openssl_encrypt("\x02" . $payload, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, $header);
    return $header . $cipher . $tag;
}

/* POST the encrypted payload to the push service; returns HTTP result */
function webpush_send($endpoint, $p256dh, $auth, $payload, $ttl = 86400) {
    $body = webpush_encrypt($p256dh, $auth, $payload);
    $u = parse_url($endpoint);
    $origin = $u['scheme'] . '://' . $u['host'];
    $jwt = vapid_jwt($origin);
    $headers = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'TTL: ' . $ttl,
        'Urgency: normal',
        'Authorization: vapid t=' . $jwt . ', k=' . vapid_public_b64url(),
        'Content-Length: ' . strlen($body),
    ];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'err' => $err, 'resp' => substr((string)$resp, 0, 200)];
}

/* Send a notification to every device registered for an email */
function push_to_user($pdo, $email, $title, $body, $url = '/app-v3/') {
    if (!$email) return ['sent' => 0, 'removed' => 0, 'errors' => []];
    $st = $pdo->prepare('SELECT endpoint, p256dh, auth FROM push_subs WHERE sub_email=?');
    $st->execute([$email]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['sent' => 0, 'removed' => 0, 'errors' => []];
    $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url], JSON_UNESCAPED_UNICODE);
    $sent = 0; $removed = 0; $errors = [];
    foreach ($rows as $r) {
        $res = webpush_send($r['endpoint'], $r['p256dh'], $r['auth'], $payload);
        if ($res['code'] === 201) { $sent++; }
        elseif ($res['code'] === 404 || $res['code'] === 410) {
            try { $pdo->prepare('DELETE FROM push_subs WHERE endpoint=?')->execute([$r['endpoint']]); $removed++; } catch (Exception $e) {}
        } else { $errors[] = ['code' => $res['code'], 'err' => $res['err']]; }
    }
    return ['sent' => $sent, 'removed' => $removed, 'errors' => $errors];
}

/* Push all active owners (V2.14 — trigger helper; never breaks the primary action). */
function push_owners($pdo, $title, $body, $url = '/app-v3/', $actor = null, $atype = 'system', $aref = '') {
    try {
        if ($actor && in_array($actor['role'] ?? '', ['superadmin', 'owner'], true)) return;
        $rows = $pdo->query("SELECT id, email FROM subscribers WHERE status='active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) $rows = [];
        $sev = $atype === 'payment' ? 'success' : ($atype === 'maintenance' || $atype === 'kyc' ? 'warning' : 'info');
        foreach ($rows as $row) {
            if (!empty($row['email'])) push_to_user($pdo, $row['email'], $title, $body, $url);
            if (!empty($row['id'])) kr_alert_upsert($pdo, 'sub:' . $row['id'], $atype, $sev, $title, $body, $aref);
        }
    } catch (Throwable $e) { /* push must never break the primary action */ }
}

/* ── SA1 v20: subscriber team (sub-accounts) + seat enforcement ── */
