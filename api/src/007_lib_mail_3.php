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
function mail_queue_table($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        to_addr TEXT, subject TEXT, html TEXT, text_body TEXT,
        status TEXT DEFAULT 'pending', attempts INTEGER DEFAULT 0,
        last_error TEXT DEFAULT '', created_at TEXT DEFAULT (datetime('now')),
        sent_at TEXT)");
}

function queue_mail($to, $subject, $html, $text = null) {
    try {
        $pdo = db();
        mail_queue_table($pdo);
        $st = $pdo->prepare('INSERT INTO mail_queue (to_addr, subject, html, text_body) VALUES (?,?,?,?)');
        return $st->execute([$to, $subject, $html, $text]);
    } catch (Exception $e) {
        /* queue is best-effort — if it fails, fall back to inline so mail is not lost */
        return smtp_send($to, $subject, $html, $text) || mail_fallback($to, $subject, $html, $text);
    }
}

/* Drain up to $limit pending queue rows; returns ['sent'=>n,'failed'=>n,'left'=>n].
   Called by the app-mail-worker endpoint. Max 3 attempts per row, then marked dead. */
function mail_queue_drain($pdo, $limit = 50) {
    mail_queue_table($pdo);
    $sent = 0; $failed = 0;
    $rows = $pdo->prepare('SELECT * FROM mail_queue WHERE status=? ORDER BY id LIMIT ' . (int)$limit);
    $rows->execute(['pending']);
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ok = smtp_send($r['to_addr'], $r['subject'], $r['html'], $r['text_body'])
            || mail_fallback($r['to_addr'], $r['subject'], $r['html'], $r['text_body']);
        if ($ok) {
            $pdo->prepare("UPDATE mail_queue SET status='sent', sent_at=datetime('now') WHERE id=?")->execute([$r['id']]);
            $sent++;
        } else {
            $att = (int)$r['attempts'] + 1;
            $st = $att >= 3 ? 'dead' : 'pending';
            $pdo->prepare("UPDATE mail_queue SET attempts=?, status=?, last_error=? WHERE id=?")
                ->execute([$att, $st, 'send failed x' . $att, $r['id']]);
            $failed++;
        }
    }
    $left = (int)$pdo->query("SELECT COUNT(*) FROM mail_queue WHERE status='pending'")->fetchColumn();
    return ['sent' => $sent, 'failed' => $failed, 'left' => $left];
}

/* ── SA1 v19: Web Push (RFC 8291 content-encryption + RFC 8292 VAPID) — keys from env file ── */
define('VAPID_PRIV', krenv('VAPID_PRIV', ''));
define('VAPID_PUB', krenv('VAPID_PUB', ''));
define('VAPID_SUB', krenv('VAPID_SUB', 'mailto:kabir.swe@gmail.com'));

