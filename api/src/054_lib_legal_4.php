function legal_cfg_all($pdo) {
    $defs = ['legal_eviction_days' => '90', 'legal_rent_hike_days' => '60', 'legal_termination_days' => '30',
        'legal_tds_commercial' => '10', 'legal_tds_residential' => '2',
        'legal_deposit_max_res' => '2', 'legal_deposit_max_com' => '3'];
    $out = [];
    $st = $pdo->query("SELECT k, v FROM org_settings WHERE k LIKE 'legal_%'");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['k']] = $r['v'];
    foreach ($defs as $k => $d) if (!isset($out[$k])) $out[$k] = $d;
    return $out;
}
function legal_cfg($pdo, $k, $def) {
    $st = $pdo->prepare('SELECT v FROM org_settings WHERE k=?'); $st->execute([$k]);
    $v = (string)$st->fetchColumn();
    return $v === '' ? $def : $v;
}
