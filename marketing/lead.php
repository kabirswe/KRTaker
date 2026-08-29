<?php
/* Lead-capture proxy for mall.krtaker.com → Mall Manager API (appvaley.com/mall).
   Server-side forward avoids browser CORS; the API validates, stores the lead
   and sends the demo credentials by SMS (bulksmsbd) + best-effort email. */
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}
$name   = trim((string)($_POST['name'] ?? ''));
$mobile = trim((string)($_POST['mobile'] ?? ''));
$email  = trim((string)($_POST['email'] ?? ''));
$source = trim((string)($_POST['source'] ?? 'mall.krtaker.com'));

$payload = json_encode([
    'name'   => $name,
    'mobile' => $mobile,
    'email'  => $email,
    'source' => $source,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://appvaley.com/mall/api/mall-lead');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);
if ($resp === false || $resp === '') {
    echo json_encode(['ok' => false, 'error' => 'Gateway unreachable: ' . substr($err, 0, 120)]);
    exit;
}
echo $resp;
