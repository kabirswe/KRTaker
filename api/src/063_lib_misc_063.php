function lease_audit($pdo, $leaseId) {
    $st = $pdo->prepare('SELECT * FROM leases WHERE id=?'); $st->execute([$leaseId]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) return null;
    $cfg = legal_cfg_all($pdo);
    $rent = (int)$l['rent']; $adv = (int)$l['adv'];
    $isRes = (int)$l['res'] === 1;
    $cap = $isRes ? (int)$cfg['legal_deposit_max_res'] : (int)$cfg['legal_deposit_max_com'];
    $termMonths = 0;
    if ($l['start'] && $l['end']) $termMonths = (int)round((strtotime($l['end']) - strtotime($l['start'])) / 2592000);
    $f = [];
    if ($rent > 0 && $adv > $cap * $rent)
        $f[] = ['sev' => 'high', 'code' => 'DEPOSIT', 'label' => 'Advance exceeds ' . $cap . '× rent', 'detail' => 'Advance ৳' . number_format($adv) . ' > cap ৳' . number_format($cap * $rent) . ' (' . $cap . ' × ৳' . number_format($rent) . ')'];
    if ($termMonths > 12 && empty($l['reg']))
        $f[] = ['sev' => 'high', 'code' => 'REG', 'label' => 'Lease > 12 months must be registered', 'detail' => 'TPA §107 — deed + Sub-Registry stamp duty required'];
    if (!$isRes && empty($l['reg_office']))
        $f[] = ['sev' => 'med', 'code' => 'REGOFF', 'label' => 'Commercial lease missing registration office', 'detail' => 'Set reg_office on the lease record'];
    if (!$isRes)
        $f[] = ['sev' => 'info', 'code' => 'TDS', 'label' => 'TDS obligation on commercial rent', 'detail' => 'Withhold ' . $cfg['legal_tds_commercial'] . '% of gross rent under ITA 2023 §109'];
    if ($l['end'] && strtotime($l['end']) - time() < 60 * 86400 && in_array($l['status'], ['Active', 'Pending Registration'], true))
        $f[] = ['sev' => 'med', 'code' => 'EXP', 'label' => 'Lease ends within 60 days', 'detail' => 'Plan renewal or vacation notice before ' . $l['end']];
    $hi = count(array_filter($f, fn($x) => $x['sev'] === 'high'));
    $me = count(array_filter($f, fn($x) => $x['sev'] === 'med'));
    $score = max(0, 100 - 25 * $hi - 10 * $me);
    return ['lease' => $leaseId, 'tenant' => $l['t'], 'res' => $isRes, 'rent' => $rent, 'adv' => $adv,
        'term_months' => $termMonths, 'status' => $l['status'], 'score' => $score, 'findings' => $f];
}
function tds_due($pdo, $month) {
    $cfg = legal_cfg_all($pdo);
    $rate = (int)$cfg['legal_tds_commercial'];
    $items = []; $total = 0;
    $st = $pdo->query("SELECT l.id AS lease, l.t AS tenant, l.u AS unit, l.rent, t.name AS tenant_name, u.name AS unit_name, p.name AS prop_name
        FROM leases l LEFT JOIN tenants t ON t.id=l.t LEFT JOIN units u ON u.id=l.u LEFT JOIN properties p ON p.id=u.p
        WHERE l.status IN ('Active','Pending Registration') AND l.res=0 ORDER BY l.id");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $gross = (int)$r['rent'];
        $tds = round($gross * $rate / 100);
        $total += $tds;
        $items[] = ['lease' => $r['lease'], 'tenant' => $r['tenant_name'] ?: $r['tenant'], 'unit' => $r['unit_name'] ?: $r['unit'],
            'property' => $r['prop_name'] ?: $r['lease'], 'gross' => $gross, 'rate' => $rate, 'tds' => $tds];
    }
    return ['month' => $month, 'rate' => $rate, 'items' => $items, 'total' => $total];
}
