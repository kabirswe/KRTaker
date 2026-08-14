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
define('VAPID_PRIV', krenv('VAPID_PRIV', PUSH_VAPID_PRIV));
define('VAPID_PUB', krenv('VAPID_PUB', PUSH_VAPID_PUB));
define('VAPID_SUB', krenv('VAPID_SUB', 'mailto:owner@krtaker.com'));

