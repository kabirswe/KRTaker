function gateway_init($code, $tx, $inv, $due, $u) {
    $g = GATEWAYS()[$code];
    $cb = 'https://krtaker.com/dashboard-v2.html?gw=' . $code . '&sid=' . urlencode($tx['id']);
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
        $r = gw_http_post('https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php', [
            'val_id' => $gw_ref, 'store_id' => $g['store_id'], 'store_passwd' => $g['store_pass'], 'format' => 'json',
        ]);
        $j = json_decode($r['body'] ?? '', true);
        return ($j['status'] ?? '') === 'VALID' && ($j['currency'] ?? '') === 'BDT';
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
