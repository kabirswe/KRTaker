function gateway_init($code, $tx, $inv, $due, $u) {
    $g = GATEWAYS()[$code];
    $cb = 'https://krtaker.com/app-v3/#/dashboard?gw=' . $code . '&sid=' . urlencode($tx['id']);
    if ($code === 'sslcommerz') {
        $r = gw_http_post($g['checkout'], [
            'store_id' => $g['store_id'], 'store_passwd' => $g['store_pass'],
            'total_amount' => $due, 'currency' => 'BDT', 'tran_id' => $tx['id'],
            'success_url' => $cb, 'fail_url' => $cb, 'cancel_url' => $cb,
            'cus_name' => $u['name'], 'cus_email' => $u['email'],
            'cus_phone' => $u['phone'] ?? '01700000000',
            'product_category' => 'Rent', 'product_name' => 'Rent ' . $inv,
            'num_of_item' => 1, 'quantity' => 1, 'weight' => 0, 'shipping_method' => 'NO',
        ]);
        if (isset($r['error'])) return ['error' => 'SSLCommerz unreachable: ' . $r['error']];
        $j = json_decode($r['body'], true);
        if (!$j || ($j['status'] ?? '') !== 'SUCCESS') return ['error' => 'SSLCommerz: ' . ($j['failedreason'] ?? 'init failed')];
        return ['ok' => true, 'url' => $j['GatewayPageURL'] ?: $j['redirectGatewayURL'], 'gw_ref' => $tx['id']];
    }
    if ($code === 'bkash') {
        /* tokenized checkout: grant token → create payment */
        $tokUrl = str_replace('/checkout/create', '/checkout/token/grant', $g['checkout']);
        $r = gw_http_post($tokUrl, ['app_key' => $g['merchant'], 'app_secret' => $g['app_secret']]);
        if (isset($r['error'])) return ['error' => 'bKash unreachable: ' . $r['error']];
        $tok = json_decode($r['body'], true);
        if (empty($tok['id_token'])) return ['error' => 'bKash token grant failed: ' . substr($r['body'], 0, 200)];
        $ch = curl_init($g['checkout']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 40,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: ' . $tok['id_token'], 'X-APP-Key: ' . $g['merchant']],
            CURLOPT_POSTFIELDS => json_encode([
                'mode' => '0011', 'callbackURL' => $cb, 'payerReference' => $inv,
                'amount' => (string)$due, 'currency' => 'BDT', 'merchantInvoiceNumber' => $tx['id'],
            ]),
        ]);
        $body = curl_exec($ch); curl_close($ch);
        $j = json_decode((string)$body, true);
        if (empty($j['bkashURL'])) return ['error' => 'bKash create failed: ' . substr((string)$body, 0, 200)];
        return ['ok' => true, 'url' => $j['bkashURL'], 'gw_ref' => $j['paymentID'] ?? $tx['id']];
    }
    if ($code === 'nagad') {
        /* Nagad DFS: initialize → get an order id + URL */
        $r = gw_http_post($g['checkout'] . '/' . $g['merchant'] . '/' . $tx['id'], [
            'accountNumber' => $g['merchant'], 'amount' => (string)$due, 'currency' => '050',
            'merchantCallbackURL' => $cb, 'orderId' => $tx['id'], 'ip' => '127.0.0.1',
        ]);
        if (isset($r['error'])) return ['error' => 'Nagad unreachable: ' . $r['error']];
        $j = json_decode($r['body'], true);
        if (empty($j['callBackUrl'])) return ['error' => 'Nagad init failed: ' . substr($r['body'], 0, 200)];
        return ['ok' => true, 'url' => $j['callBackUrl'], 'gw_ref' => $j['orderId'] ?? $tx['id']];
    }
    return ['error' => 'Unsupported gateway.'];
}
function gateway_verify($code, $gw_ref) {
    /* Returns true when the gateway confirms the payment succeeded. */
    $g = GATEWAYS()[$code];
    if ($code === 'sslcommerz') {
        /* Live validator when gw_config flips sandbox=false; sandbox otherwise. */
        $val = !empty($g['sandbox'])
            ? 'https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php'
            : 'https://validator.sslcommerz.com/validationserverAPI.php';
        $r = gw_http_post($val, [
            'val_id' => $gw_ref, 'store_id' => $g['store_id'], 'store_passwd' => $g['store_pass'], 'format' => 'json',
        ]);
        $j = json_decode($r['body'] ?? '', true);
        /* v4 IPN hardening: status VALID + currency BDT + amount > 0. The exact
           amount-vs-session check happens in gateway_confirm_session where the
           expected amount is known (the validator echoes amount — the handler
           compares it to the local session's amount). */
        return ($j['status'] ?? '') === 'VALID'
            && ($j['currency'] ?? '') === 'BDT'
            && (int)($j['amount'] ?? 0) > 0;
    }
    if ($code === 'bkash') {
        $r = gw_http_post($g['checkout'] . '/execute', ['paymentID' => $gw_ref]);
        $j = json_decode($r['body'] ?? '', true);
        return ($j['transactionStatus'] ?? '') === 'Completed';
    }
    if ($code === 'nagad') {
        $r = gw_http_post($g['checkout'] . '/complete/' . $g['merchant'] . '/' . $gw_ref, []);
        $j = json_decode($r['body'] ?? '', true);
        return ($j['status'] ?? '') === 'Success';
    }
    return false;
}

/* ── Gateway session confirmation (idempotent, IPN + redirect share this) ──
   Atomic status flip: only a tx in pending/redirecting may become 'paid'. If the
   flip affects 0 rows the session was already confirmed → no double payment. */
function gateway_confirm_session($pdo, $sid, $gref, $actor) {
    $st = $pdo->prepare('SELECT * FROM gateway_tx WHERE id=?'); $st->execute([$sid]);
    $tx = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tx) return ['ok' => false, 'error' => 'Checkout session not found.', 'code' => 404];
    if ($tx['status'] === 'paid') {
        /* idempotent replay: return the already-recorded payment info */
        $pay = $pdo->prepare("SELECT * FROM payments WHERE inv=? AND ref=? ORDER BY id DESC LIMIT 1");
        $pay->execute([$tx['invoice_id'], $tx['ref']]);
        $p = $pay->fetch(PDO::FETCH_ASSOC);
        return ['ok' => true, 'idempotent' => true, 'payment' => $p['id'] ?? '', 'receipt' => ''];
    }
    if ($tx['status'] !== 'pending' && $tx['status'] !== 'redirecting') {
        return ['ok' => false, 'error' => 'Session already ' . $tx['status'] . '.', 'code' => 400];
    }
    /* verify with the gateway BEFORE recording (only when a real ref exists) */
    if (!empty($tx['gw_ref'])) {
        $code = strtolower(array_search($tx['method'], array_map(fn($g) => $g['name'], GATEWAYS()), true) ?: '');
        $ok = false;
        if ($gref) $ok = gateway_verify($code, $gref);
        if (!$ok) {
            audit($actor, 'Gateway verification failed', 'payments', $sid, $code . ' ' . $gref);
            return ['ok' => false, 'error' => 'Gateway could not verify this payment (' . $code . '). If you paid, contact support with session ' . $sid . '.', 'code' => 402];
        }
        /* amount check: validator echoes the gateway-recorded amount — it must
           match our session amount (prevents underpayment/overpayment replay) */
        $g = GATEWAYS()[$code];
        if ($code === 'sslcommerz') {
            $val = !empty($g['sandbox'])
                ? 'https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php'
                : 'https://validator.sslcommerz.com/validationserverAPI.php';
            $vr = gw_http_post($val, ['val_id' => $gref, 'store_id' => $g['store_id'], 'store_passwd' => $g['store_pass'], 'format' => 'json']);
            $vj = json_decode($vr['body'] ?? '', true);
            $gwAmt = (int)($vj['amount'] ?? 0);
            if ($gwAmt > 0 && $gwAmt !== (int)$tx['amount']) {
                audit($actor, 'Gateway amount mismatch', 'payments', $sid, 'session=' . $tx['amount'] . ' gw=' . $gwAmt);
                return ['ok' => false, 'error' => 'Amount mismatch with gateway. Contact support with session ' . $sid . '.', 'code' => 402];
            }
        }
    }
    /* atomic flip — the idempotency barrier */
    $up = $pdo->prepare("UPDATE gateway_tx SET status='paid', updated_at=datetime('now'), gw_ref=COALESCE(NULLIF(?,''),gw_ref) WHERE id=? AND status IN ('pending','redirecting')");
    $up->execute([$gref, $sid]);
    if ($up->rowCount() === 0) {
        /* lost the race → already paid by a concurrent request */
        $pay = $pdo->prepare("SELECT * FROM payments WHERE inv=? AND ref=? ORDER BY id DESC LIMIT 1");
        $pay->execute([$tx['invoice_id'], $tx['ref']]);
        $p = $pay->fetch(PDO::FETCH_ASSOC);
        return ['ok' => true, 'idempotent' => true, 'payment' => $p['id'] ?? '', 'receipt' => ''];
    }
    try {
        list($pid, $rid) = record_payment($pdo, $tx['invoice_id'], (int)$tx['amount'], $tx['method'], $tx['ref'], gmdate('Y-m-d'));
    } catch (Exception $e) {
        return ['ok' => false, 'error' => 'Payment failed: ' . $e->getMessage(), 'code' => 500];
    }
    audit($actor, 'Gateway payment confirmed', 'payments', $pid, $tx['invoice_id'] . ' ' . $tx['amount'] . ' via ' . $tx['method']);
    return ['ok' => true, 'payment' => $pid, 'receipt' => $rid, 'gateway' => $tx['method']];
}
