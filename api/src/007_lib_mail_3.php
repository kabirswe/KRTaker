function mail_fallback($to, $subject, $html, $text = null) {
    $text = $text ?? strip_tags(str_replace(['<br>', '<br/>', '</p>', '</div>', '</li>'], "\n", $html));
    /* SA1 v21: sanitize at the boundary (CRLF-injection guard) */
    $to = mail_hdr($to, 320);
    $subject = mail_hdr($subject, 200);
    $boundary = 'kr_' . md5(uniqid('', true));
    $from = mail_hdr('KRTaker <krtaker@krtaker.com>', 320);
    /* CRITICAL: MIME headers go in the 4th param (additional headers), ONLY the MIME
       parts go in the body. Passing the whole RFC822 message as the body makes the
       MTA deliver it WITHOUT a Content-Type → clients render raw MIME text. */
    $headers = "From: $from\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$boundary\"";
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n\r\n"
          . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n\r\n"
          . "--$boundary--";
    /* v3.85: pass -f so the ENVELOPE sender is krtaker@krtaker.com (defaults to
       krtaker@server.coderdrop.com otherwise) — SPF+DKIM double alignment for
       Gmail/Outlook. Verified 2026-08-09 via live probe: Return-Path was
       server.coderdrop.com before, krtaker@krtaker.com after. */
    return @mail($to, $subject, $body, $headers, '-fkrtaker@krtaker.com');
}

function send_mail($to, $subject, $html, $text = null) {
    if (smtp_send($to, $subject, $html, $text)) return true;
    return mail_fallback($to, $subject, $html, $text);
}

/* ── SA1 v19: Web Push (RFC 8291 content-encryption + RFC 8292 VAPID) — keys from env file ── */
define('VAPID_PRIV', krenv('VAPID_PRIV', ''));
define('VAPID_PUB', krenv('VAPID_PUB', ''));
define('VAPID_SUB', krenv('VAPID_SUB', 'mailto:kabir.swe@gmail.com'));

