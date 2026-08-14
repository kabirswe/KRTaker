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

function send_mail($to, $subject, $html, $text = null, $queued = false) {
    if ($queued) return queue_mail($to, $subject, $html, $text);
    if (smtp_send($to, $subject, $html, $text)) return true;
    return mail_fallback($to, $subject, $html, $text);
}

/* ── Mail queue (off-request-path sending, 2026-08-09) ──
   Heavy/aggregate mail (collections digest, rent reminders, welcome sequence)
   is enqueued and drained by the app-mail-worker endpoint (service-key gated,
   called by a cron). OTP/verify emails stay INLINE (time-sensitive, 5-min code
   expiry). Create the table idempotently so it exists on both fresh and old DBs. */
