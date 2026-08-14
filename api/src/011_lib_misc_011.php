function push_owners($pdo, $title, $body, $url = '/app-v3/', $actor = null, $atype = 'system', $aref = '') {
    try {
        if ($actor && in_array($actor['role'] ?? '', ['superadmin', 'owner'], true)) return;
        $rows = $pdo->query("SELECT id, email FROM subscribers WHERE status='active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) $rows = [];
        $sev = $atype === 'payment' ? 'success' : ($atype === 'maintenance' || $atype === 'kyc' ? 'warning' : 'info');
        foreach ($rows as $row) {
            if (!empty($row['email'])) push_to_user($pdo, $row['email'], $title, $body, $url);
            if (!empty($row['id'])) kr_alert_upsert($pdo, 'sub:' . $row['id'], $atype, $sev, $title, $body, $aref);
        }
    } catch (Throwable $e) { /* push must never break the primary action */ }
}

/* ── SA1 v20: subscriber team (sub-accounts) + seat enforcement ── */
