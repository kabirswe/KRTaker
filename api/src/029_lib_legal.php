function legal_seed() {
    $pdo = db();
    $n = (int)$pdo->query('SELECT COUNT(*) FROM legal_docs')->fetchColumn();
    if ($n) return;
    $st = $pdo->prepare('INSERT OR IGNORE INTO legal_docs (cat,title,body,kw) VALUES (?,?,?,?)');
    foreach (LEGAL_KB() as $r) $st->execute([$r[0], $r[1], $r[2], $r[3]]);
    $st2 = $pdo->prepare('INSERT OR IGNORE INTO legal_docs_fts (cat,title,body,kw) VALUES (?,?,?,?)');
    foreach (LEGAL_KB() as $r) $st2->execute([$r[0], $r[1], $r[2], $r[3]]);
}
function legal_search($q) {
    legal_seed();
    $pdo = db();
    $words = preg_split('/\s+/', trim($q));
    $clean = [];
    foreach ($words as $w) { $w = preg_replace('/[^A-Za-z0-9]/', '', $w); if (mb_strlen($w) > 1) $clean[] = strtolower($w); }
    if (!$clean) return [];
    try {
        $fts = '"' . implode('" OR "', array_slice($clean, 0, 6)) . '"';
        $st = $pdo->prepare('SELECT cat,title,body, bm25(legal_docs_fts) AS score FROM legal_docs_fts WHERE legal_docs_fts MATCH ? ORDER BY score LIMIT 3');
        $st->execute([$fts]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $like = '%' . implode('%', $clean) . '%';
        $st = $pdo->prepare('SELECT cat,title,body, 0 AS score FROM legal_docs WHERE body LIKE ? OR title LIKE ? OR kw LIKE ? LIMIT 3');
        $st->execute([$like, $like, $like]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* ── RBAC-gated tools (permission inheritance: execute AS the calling user) ── */
/* Phase 6: row-level scoping for KR — tenants/partners only see their own records */
