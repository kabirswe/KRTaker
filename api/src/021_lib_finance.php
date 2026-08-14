function gateway_label($code) {
    $g = GATEWAYS()[$code] ?? null;
    return $g ? $g['name'] : $code;
}
/* Real sandbox/live gateway adapters. Credentials live in GATEWAYS(); while any
   credential is the placeholder, the flow falls back to the simulated checkout
   (pure demo). Set the credentials (and sandbox=false to go live) and these
   adapters take over with ZERO code changes. */
function gateway_ready($code) {
    $g = GATEWAYS()[$code] ?? null;
    if (!$g) return false;
    if ($code === 'sslcommerz') return !in_array($g['store_id'], ['', 'REPLACE_ME', 'krtakerTEST'], true) && $g['store_pass'] !== 'REPLACE_ME';
    if ($code === 'bkash') return $g['merchant'] !== '013000000000' && $g['app_secret'] !== 'REPLACE_ME';
    if ($code === 'nagad') return $g['merchant'] !== 'NAGAD_MERCHANT_ID';
    return false;
}
