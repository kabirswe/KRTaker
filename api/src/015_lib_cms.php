function plan_catalog() {
    $pdo = db();
    $rows = $pdo->query('SELECT * FROM plan_catalog ORDER BY price')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['features'] = json_decode($r['features'], true) ?: [];
        $r['modules'] = json_decode($r['modules'] ?? '[]', true) ?: [];
        $r['limits'] = json_decode($r['limits'] ?? '{}', true) ?: [];
    }
    return $rows;
}

/* ── P55: package-driven access (module matrix + limits) ── */
