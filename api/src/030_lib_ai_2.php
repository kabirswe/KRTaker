function ai_scope($u) {
    if ($u['role'] === 'tenant') {
        $pdo = db();
        $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
        $tid = $st->fetchColumn();
        if (!$tid) return ['leases' => [], 'units' => [], 'invoices' => []];
        $st = $pdo->prepare('SELECT id, u FROM leases WHERE t=?'); $st->execute([$tid]);
        $leases = $st->fetchAll(PDO::FETCH_ASSOC);
        $leaseIds = array_column($leases, 'id');
        $unitIds = array_column($leases, 'u');
        $invIds = [];
        if ($leaseIds) {
            $q = 'SELECT id FROM invoices WHERE l IN (' . implode(',', array_fill(0, count($leaseIds), '?')) . ')';
            $st = $pdo->prepare($q); $st->execute($leaseIds);
            $invIds = $st->fetchAll(PDO::FETCH_COLUMN);
        }
        return ['leases' => $leaseIds, 'units' => $unitIds, 'invoices' => $invIds];
    }
    if ($u['role'] === 'partner') {
        $org = my_org($u);
        $st = db()->prepare('SELECT id FROM tickets WHERE con=?'); $st->execute([$org]);
        return ['leases' => [], 'units' => [], 'invoices' => [], 'tickets' => $st->fetchAll(PDO::FETCH_COLUMN), 'org' => $org];
    }
    return null;   /* staff/owner: org-wide */
}
function ai_in_list($ids) {
    return $ids ? implode(',', array_fill(0, count($ids), '?')) : "''";
}
function ai_q($sql, $args = []) {
    try {
        $st = db()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}
/* Parse a month out of a query: YYYY-MM, English or Bengali month name → current year */
function ai_month($q) {
    if (preg_match('/(\d{4})-(\d{2})/', $q, $m)) return $m[1] . '-' . $m[2];
    $bn = ['জানুয়ারি' => 1, 'ফেব্রুয়ারি' => 2, 'মার্চ' => 3, 'এপ্রিল' => 4, 'মে' => 5, 'জুন' => 6, 'জুলাই' => 7, 'আগস্ট' => 8, 'সেপ্টেম্বর' => 9, 'অক্টোবর' => 10, 'নভেম্বর' => 11, 'ডিসেম্বর' => 12];
    $en = ['january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12];
    $s = mb_strtolower($q, 'UTF-8');
    foreach ($bn as $name => $n) { if (mb_strpos($s, $name) !== false) return gmdate('Y') . '-' . str_pad((string)$n, 2, '0', STR_PAD_LEFT); }
    foreach ($en as $name => $n) { if (strpos($s, $name) !== false) return gmdate('Y') . '-' . str_pad((string)$n, 2, '0', STR_PAD_LEFT); }
    return gmdate('Y-m');
}
/* ═══ Phase 8–10 helpers: documents, notices, referrals, print ═══ */
