function b64url_encode($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64url_decode($s) { return base64_decode(strtr($s, '-_', '+/')); }

/* Build an EC public-key PEM from a raw 65-byte uncompressed point (openssl needs PEM, not raw DER) */
