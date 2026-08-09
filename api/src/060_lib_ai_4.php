function kr_alert_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'AL-','') AS INTEGER)) FROM kr_alerts")->fetchColumn();
    return 'AL-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function kr_alert_upsert($pdo, $userKey, $type, $severity, $title, $body, $ref = '', $voice = '') {
    /* dedupe: open alert exists, OR one dismissed within the last 24h (cooldown) */
    $st = $pdo->prepare('SELECT id FROM kr_alerts WHERE user_key=? AND type=? AND ref=? AND (status=\'open\' OR (status=\'dismissed\' AND resolved_at >= datetime(\'now\',\'-1 day\')))');
    $st->execute([$userKey, $type, $ref]);
    $ex = $st->fetchColumn();
    if ($ex) return $ex;
    $id = kr_alert_next_id($pdo);
    $pdo->prepare('INSERT INTO kr_alerts (id, user_key, type, severity, title, body, ref, voice_note) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id, $userKey, $type, $severity, $title, $body, $ref, $voice]);
    return $id;
}
/* ---------- Phase 51: Fire Safety helpers ---------- */
