function smtp_read_reply($sock) {
    $last = '';
    while (($l = fgets($sock, 512)) !== false) {
        $last = trim($l);
        if (strlen($l) > 3 && $l[3] === ' ') break;
    }
    return $last;
}
function smtp_drain_greeting($sock) {
    $last = '';
    while (($l = fgets($sock, 512)) !== false) {
        $last = trim($l);
        if (strlen($l) > 3 && $l[3] === ' ') break;
    }
    return $last;
}
function smtp_send($to, $subject, $html, $text = null) {
    global $SMTP;
    $text = $text ?? strip_tags(str_replace(['<br>', '<br/>', '</p>', '</div>', '</li>'], "\n", $html));
    /* SA1 v21: sanitize every header value at the send boundary (CRLF-injection guard) */
    $to = mail_hdr($to, 320);
    $subject = mail_hdr($subject, 200);
    $fromAddr = mail_hdr($SMTP['from'], 320);
    $host = $SMTP['host']; $port = $SMTP['port'];
    /* non-verifying SSL context so STARTTLS survives self-signed / broken-chain hosts */
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $sock = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) return false;
    stream_set_timeout($sock, 20);
    smtp_drain_greeting($sock);
    fwrite($sock, "EHLO krtaker.com\r\n");
    smtp_read_reply($sock);
    fwrite($sock, "STARTTLS\r\n");
    $r = smtp_read_reply($sock);
    if (strpos($r, '220') !== false) {
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        fwrite($sock, "EHLO krtaker.com\r\n");
        smtp_read_reply($sock);
    }
    fwrite($sock, "AUTH LOGIN\r\n"); $r = smtp_read_reply($sock);
    fwrite($sock, base64_encode($SMTP['user']) . "\r\n"); $r = smtp_read_reply($sock);
    fwrite($sock, base64_encode($SMTP['pass']) . "\r\n"); $r = smtp_read_reply($sock);
    if (strpos($r, '235') === false) {
        /* AUTH PLAIN fallback (some servers only accept it, or session desynced after TLS) */
        fwrite($sock, "AUTH PLAIN " . base64_encode("\0{$SMTP['user']}\0{$SMTP['pass']}") . "\r\n"); $r = smtp_read_reply($sock);
        if (strpos($r, '235') === false) { fclose($sock); return false; }
    }
    fwrite($sock, "MAIL FROM:<$fromAddr>\r\n"); smtp_read_reply($sock);
    fwrite($sock, "RCPT TO:<$to>\r\n"); $r = smtp_read_reply($sock);
    if (strpos($r, '250') === false) { fwrite($sock, "QUIT\r\n"); fclose($sock); return false; }
    fwrite($sock, "DATA\r\n"); smtp_read_reply($sock);
    $boundary = 'kr_' . md5(uniqid('', true));
    $fromName = mail_hdr((string)admin_cfg(db(), 'mail_from_name', 'KRTaker'), 60);
    if ($fromName === '') $fromName = 'KRTaker';
    $msg  = "From: $fromName <$fromAddr>\r\n";
    $msg .= "To: <$to>\r\n";
    $msg .= "Subject: $subject\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n";
    $msg .= "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n\r\n";
    $msg .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n\r\n";
    $msg .= "--$boundary--\r\n.\r\n";
    fwrite($sock, $msg);
    $r = smtp_read_reply($sock);
    fwrite($sock, "QUIT\r\n");
    fclose($sock);
    return strpos($r, '250') !== false;
}

