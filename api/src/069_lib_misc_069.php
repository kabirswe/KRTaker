function wa_channel_for($pdo, $userKey) {
    $st = $pdo->prepare("SELECT * FROM wa_channels WHERE user_key=? AND status='active' AND LENGTH(phone)>=9 ORDER BY ts DESC LIMIT 1");
    $st->execute([$userKey]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
/* ---------- Phase 41: portfolio analytics & forecasting helpers ---------- */
function analytics_pnl($pdo, $month, $scope = '') {
    $props = $pdo->query('SELECT id, name, type FROM properties' . ($scope ? ' WHERE sub_email=' . $pdo->quote($scope) : '') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $rows = []; $tot = ['gross' => 0, 'collected' => 0, 'tds' => 0, 'service' => 0, 'expenses' => 0, 'net' => 0];
    foreach ($props as $p) {
        $c = statement_calc($pdo, $p['id'], $month);
        $row = ['prop' => $p['id'], 'name' => $p['name'], 'type' => $p['type'],
            'gross' => $c['gross'], 'collected' => $c['collected'], 'tds' => $c['tds'],
            'service' => $c['service'], 'expenses' => $c['expenses'], 'net' => $c['net']];
        $rows[] = $row;
        foreach ($tot as $k => $v) $tot[$k] += $row[$k];
    }
    return ['month' => $month, 'properties' => $rows, 'totals' => $tot];
}
function analytics_trends($pdo, $months = 12, $scope = '') {
    $out = []; $cur = new DateTime(date('Y-m-01'));
    $sc = $scope ? ' AND l IN (SELECT l.id FROM leases l JOIN units u ON u.id=l.u WHERE u.sub_email=' . $pdo->quote($scope) . ')' : '';
    for ($i = $months - 1; $i >= 0; $i--) {
        $m = (clone $cur)->modify("-{$i} months")->format('Y-m');
        $is = $pdo->prepare('SELECT COALESCE(SUM(net),0) FROM invoices WHERE m=?' . $sc); $is->execute([$m]);
        $cs = $pdo->prepare('SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN invoices i ON i.id=p.inv WHERE i.m=?' . $sc . ' AND p.status=\'Success\''); $cs->execute([$m]);
        $out[] = ['month' => $m, 'issued' => (int)$is->fetchColumn(), 'collected' => (int)$cs->fetchColumn()];
    }
    $units = (int)$pdo->query('SELECT COUNT(*) FROM units' . ($scope ? ' WHERE sub_email=' . $pdo->quote($scope) : ''))->fetchColumn();
    $leased = (int)$pdo->query('SELECT COUNT(*) FROM units' . ($scope ? ' WHERE sub_email=' . $pdo->quote($scope) . ' AND status=\'Leased\'' : " WHERE status='Leased'"))->fetchColumn();
    return ['months' => $out, 'units' => $units, 'leased' => $leased, 'occupancy' => $units ? round($leased / $units * 100) : 0];
}
function analytics_aging($pdo, $scope = '') {
    $cur = date('Y-m');
    $curY = (int)substr($cur, 0, 4); $curM = (int)substr($cur, 5, 2);
    $buckets = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0];
    $sc = $scope ? ' AND l IN (SELECT l.id FROM leases l JOIN units u ON u.id=l.u WHERE u.sub_email=' . $pdo->quote($scope) . ')' : '';
    $rows = $pdo->query("SELECT m, net FROM invoices WHERE status!='Paid'" . $sc)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $y = (int)substr($r['m'], 0, 4); $mo = (int)substr($r['m'], 5, 2);
        $age = ($curY - $y) * 12 + ($curM - $mo);
        if ($age <= 0) $buckets['current'] += (int)$r['net'];
        elseif ($age === 1) $buckets['d30'] += (int)$r['net'];
        elseif ($age === 2) $buckets['d60'] += (int)$r['net'];
        else $buckets['d90'] += (int)$r['net'];
    }
    $buckets['total'] = array_sum($buckets);
    return $buckets;
}
function analytics_vacancy($pdo, $scope = '') {
    $sc = $scope ? ' WHERE u.sub_email=' . $pdo->quote($scope) . ' AND u.status=\'Vacant\'' : " WHERE u.status='Vacant'";
    $rows = $pdo->query("SELECT u.id, u.name, u.rent, p.name AS prop FROM units u LEFT JOIN properties p ON p.id=u.p" . $sc)->fetchAll(PDO::FETCH_ASSOC);
    $loss = 0;
    foreach ($rows as $r) $loss += (int)$r['rent'];
    return ['units' => $rows, 'count' => count($rows), 'monthly_loss' => $loss, 'annual_loss' => $loss * 12];
}
function analytics_forecast($pdo, $scope = '') {
    $t = analytics_trends($pdo, 6, $scope);
    $issued = array_sum(array_column($t['months'], 'issued'));
    $coll = array_sum(array_column($t['months'], 'collected'));
    $rate = $issued ? (int)round($coll / $issued * 100) : 0;
    $avgIssued = $issued ? (int)round($issued / count($t['months'])) : 0;
    $avgColl = (int)round($avgIssued * $rate / 100);
    $v = analytics_vacancy($pdo, $scope);
    $sc = $scope ? " AND u IN (SELECT id FROM units WHERE sub_email=" . $pdo->quote($scope) . ")" : '';
    $renewals = (int)$pdo->query("SELECT COUNT(*) FROM leases WHERE status='Active' AND end <= date('now','+60 days') AND end >= date('now')" . $sc)->fetchColumn();
    $months = [];
    $base = new DateTime(date('Y-m-01'));
    for ($i = 1; $i <= 12; $i++) {
        $m = (clone $base)->modify("+{$i} months")->format('Y-m');
        $months[] = ['month' => $m, 'expected' => $avgIssued, 'collected' => $avgColl];
    }
    $topRisk = $v['count'] ? $v['count'] . ' vacant unit(s) → ৳' . number_format($v['monthly_loss']) . '/mo lost' : ($renewals ? $renewals . ' lease renewal(s) due' : 'None — healthy');
    return [
        'collection_rate' => $rate, 'avg_issued' => $avgIssued, 'avg_collected' => $avgColl,
        'months' => $months, 'total_forecast' => $avgColl * 12, 'avg_month' => $avgColl,
        'occupancy' => $t['occupancy'], 'leased' => $t['leased'], 'units' => $t['units'],
        'vacancy_loss' => $v['monthly_loss'], 'vacant_units' => $v['count'],
        'renewals_due' => $renewals, 'top_risk' => $topRisk,
    ];
}
/* ---------- V2.0.2: analytics expansion — cashflow, collections, expenses, scores, occupancy, maintenance ---------- */
function analytics_cashflow($pdo, $months = 12, $scope = '') {
    $sc = $scope ? ' AND inv IN (SELECT i.id FROM invoices i JOIN leases l ON l.id=i.l JOIN units u ON u.id=l.u WHERE u.sub_email=' . $pdo->quote($scope) . ')' : '';
    $incSt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='Success' AND substr(date,1,7)=?" . $sc);
    $mntSc = $scope ? " AND (unit IN (SELECT id FROM units WHERE sub_email=" . $pdo->quote($scope) . ") OR prop IN (SELECT id FROM properties WHERE sub_email=" . $pdo->quote($scope) . "))" : '';
    $mntSt = $pdo->prepare("SELECT COALESCE(SUM(actual_cost),0) FROM maintenance_requests WHERE pay_paid=1 AND substr(pay_paid_at,1,7)=?" . $mntSc);
    $vpSc = $scope ? " AND partner IN (SELECT id FROM partners WHERE sub_email=" . $pdo->quote($scope) . ")" : '';
    $vpSt  = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM vendor_payouts WHERE status='Paid' AND month=?" . $vpSc);
    $spSc = $scope ? " AND prop IN (SELECT id FROM properties WHERE sub_email=" . $pdo->quote($scope) . ")" : '';
    $spSt  = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM statement_payouts WHERE status='Paid' AND month=?" . $spSc);
    $out = []; $cum = 0; $cur = new DateTime(date('Y-m-01'));
    for ($i = $months - 1; $i >= 0; $i--) {
        $m = (clone $cur)->modify("-{$i} months")->format('Y-m');
        $incSt->execute([$m]); $income = (int)$incSt->fetchColumn();
        $mntSt->execute([$m]); $exp = (int)$mntSt->fetchColumn();
        $vpSt->execute([$m]);  $exp += (int)$vpSt->fetchColumn();
        $spSt->execute([$m]);  $exp += (int)$spSt->fetchColumn();
        $cum += $income - $exp;
        $out[] = ['month' => $m, 'income' => $income, 'expenses' => $exp, 'net' => $income - $exp, 'cumulative' => $cum];
    }
    $totI = array_sum(array_column($out, 'income')); $totE = array_sum(array_column($out, 'expenses'));
    return ['months' => $out, 'total_income' => $totI, 'total_expenses' => $totE, 'total_net' => $totI - $totE, 'expense_ratio' => $totI ? (int)round($totE / $totI * 100) : 0];
}
function analytics_collections($pdo, $months = 12, $scope = '') {
    $sc = $scope ? ' AND inv IN (SELECT i.id FROM invoices i JOIN leases l ON l.id=i.l JOIN units u ON u.id=l.u WHERE u.sub_email=' . $pdo->quote($scope) . ')' : '';
    $byMethod = $pdo->query("SELECT method, COUNT(*) n, SUM(amount) amount FROM payments WHERE status='Success'" . $sc . " GROUP BY method ORDER BY amount DESC")->fetchAll(PDO::FETCH_ASSOC);
    $onTime = 0; $late = 0; $lateDays = 0; $lateAmt = 0;
    foreach ($pdo->query("SELECT p.date pd, p.amount, i.m mth FROM payments p JOIN invoices i ON i.id=p.inv WHERE p.status='Success'" . $sc)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!$r['mth'] || !$r['pd']) continue;
        $due = strtotime($r['mth'] . '-01') + 3 * 86400;  // 1st + 3-day grace
        $pd = strtotime($r['pd']);
        if ($pd <= $due) { $onTime++; }
        else { $late++; $lateDays += max(0, (int)floor(($pd - $due) / 86400)); $lateAmt += (int)$r['amount']; }
    }
    $issSc = $scope ? ' AND l IN (SELECT l.id FROM leases l JOIN units u ON u.id=l.u WHERE u.sub_email=' . $pdo->quote($scope) . ')' : '';
    $issSt = $pdo->prepare('SELECT COALESCE(SUM(net),0) FROM invoices WHERE m=?' . $issSc);
    $colSt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments p JOIN invoices i ON i.id=p.inv WHERE i.m=?" . $sc . " AND p.status='Success'");
    $byMonth = []; $cur = new DateTime(date('Y-m-01'));
    for ($i = $months - 1; $i >= 0; $i--) {
        $m = (clone $cur)->modify("-{$i} months")->format('Y-m');
        $issSt->execute([$m]); $iss = (int)$issSt->fetchColumn();
        $colSt->execute([$m]); $col = (int)$colSt->fetchColumn();
        $byMonth[] = ['month' => $m, 'issued' => $iss, 'collected' => $col, 'rate' => $iss ? (int)round($col / $iss * 100) : 0];
    }
    $tot = $onTime + $late;
    return ['by_method' => $byMethod, 'by_month' => $byMonth, 'payments' => $tot,
        'on_time' => $onTime, 'late' => $late, 'on_time_rate' => $tot ? (int)round($onTime / $tot * 100) : 0,
        'avg_days_late' => $late ? (int)round($lateDays / $late) : 0, 'late_amount' => $lateAmt];
}
function analytics_expenses($pdo, $months = 12, $scope = '') {
    $sc = $scope ? " AND (unit IN (SELECT id FROM units WHERE sub_email=" . $pdo->quote($scope) . ") OR prop IN (SELECT id FROM properties WHERE sub_email=" . $pdo->quote($scope) . "))" : '';
    $where = $sc ? ' WHERE 1=1' : '';
    $totalPaid = (int)$pdo->query("SELECT COALESCE(SUM(actual_cost),0) FROM maintenance_requests" . $where . " AND pay_paid=1" . $sc)->fetchColumn();
    $totalAll = (int)$pdo->query('SELECT COALESCE(SUM(actual_cost),0) FROM maintenance_requests' . $where . $sc)->fetchColumn();
    $estOpen = (int)$pdo->query("SELECT COALESCE(SUM(cost_estimate),0) FROM maintenance_requests" . $where . " AND status NOT IN ('Closed','Cancelled','Done')" . $sc)->fetchColumn();
    $openCount = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_requests" . $where . " AND status NOT IN ('Closed','Cancelled','Done')" . $sc)->fetchColumn();
    $byCategory = $pdo->query("SELECT COALESCE(NULLIF(category,''),'other') category, COUNT(*) n, COALESCE(SUM(actual_cost),0) cost FROM maintenance_requests" . $where . $sc . " GROUP BY category ORDER BY cost DESC")->fetchAll(PDO::FETCH_ASSOC);
    $byVendor = $pdo->query("SELECT COALESCE(NULLIF(vendor,''),'Unassigned') vendor, COUNT(*) n, COALESCE(SUM(actual_cost),0) cost FROM maintenance_requests" . $where . " AND vendor<>''" . $sc . " GROUP BY vendor ORDER BY cost DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    $byProperty = $pdo->query("SELECT COALESCE(NULLIF(m.prop,''),'—') prop, COALESCE(p.name, COALESCE(NULLIF(m.prop,''),'—')) name, COUNT(*) n, COALESCE(SUM(m.actual_cost),0) cost, SUM(CASE WHEN m.status NOT IN ('Closed','Cancelled','Done') THEN 1 ELSE 0 END) open FROM maintenance_requests m LEFT JOIN properties p ON p.id=m.prop" . $where . $sc . " GROUP BY m.prop ORDER BY cost DESC")->fetchAll(PDO::FETCH_ASSOC);
    $trSt = $pdo->prepare("SELECT COALESCE(SUM(actual_cost),0) FROM maintenance_requests WHERE 1=1 AND pay_paid=1 AND substr(pay_paid_at,1,7)=?" . $sc);
    $trend = []; $cur = new DateTime(date('Y-m-01'));
    for ($i = $months - 1; $i >= 0; $i--) {
        $m = (clone $cur)->modify("-{$i} months")->format('Y-m');
        $trSt->execute([$m]); $trend[] = ['month' => $m, 'cost' => (int)$trSt->fetchColumn()];
    }
    $avgJob = 0;
    $nn = (int)$pdo->query('SELECT COUNT(*) FROM maintenance_requests' . $where . " AND actual_cost>0" . $sc)->fetchColumn();
    if ($nn) $avgJob = (int)round($totalAll / $nn);
    return ['total_paid' => $totalPaid, 'total_all' => $totalAll, 'estimated_open' => $estOpen, 'open_count' => $openCount,
        'avg_job_cost' => $avgJob, 'by_category' => $byCategory, 'by_vendor' => $byVendor, 'by_property' => $byProperty, 'trend' => $trend];
}
function analytics_scores($pdo, $scope = '') {
    $tenants = $pdo->query('SELECT DISTINCT t.id, t.name, t.kind FROM tenants t JOIN leases l ON l.t=t.id' . ($scope ? ' WHERE t.sub_email=' . $pdo->quote($scope) : ''))->fetchAll(PDO::FETCH_ASSOC);
    $bands = ['Excellent' => 0, 'Good' => 0, 'Fair' => 0, 'Risky' => 0];
    $atRisk = []; $scoreSum = 0; $scored = 0;
    foreach ($tenants as $t) {
        $sc = tenant_scorecard($pdo, $t['id']);
        if (!$sc) continue;
        $bands[$sc['band']] = ($bands[$sc['band']] ?? 0) + 1;
        $scoreSum += $sc['score']; $scored++;
        if ($sc['band'] === 'Risky' || $sc['band'] === 'Fair') {
            $atRisk[] = ['id' => $t['id'], 'name' => $t['name'], 'kind' => $t['kind'] ?? '', 'score' => $sc['score'],
                'band' => $sc['band'], 'band_color' => $sc['band_color'] ?? '', 'overdue' => $sc['stats']['overdue'] ?? 0,
                'on_time' => $sc['stats']['on_time_rate'] ?? 0, 'tenure' => $sc['stats']['tenure_months'] ?? 0,
                'tickets_open' => $sc['stats']['tickets_open'] ?? 0];
        }
    }
    usort($atRisk, fn($a, $b) => $a['score'] <=> $b['score']);
    return ['bands' => $bands, 'total' => $scored, 'avg_score' => $scored ? (int)round($scoreSum / $scored) : 0, 'at_risk' => $atRisk];
}
function analytics_occupancy($pdo, $scope = '') {
    $props = $pdo->query('SELECT id, name, type FROM properties' . ($scope ? ' WHERE sub_email=' . $pdo->quote($scope) : '') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $rows = []; $totU = 0; $totL = 0; $roll = 0; $vLoss = 0;
    foreach ($props as $p) {
        $uSc = $scope ? ' AND sub_email=' . $pdo->quote($scope) : '';
        $uSt = $pdo->prepare('SELECT COUNT(*) FROM units WHERE p=?' . $uSc); $uSt->execute([$p['id']]); $units = (int)$uSt->fetchColumn();
        $lSt = $pdo->prepare("SELECT COUNT(*) FROM units WHERE p=?" . $uSc . " AND status='Leased'"); $lSt->execute([$p['id']]); $leased = (int)$lSt->fetchColumn();
        $rSt = $pdo->prepare("SELECT COALESCE(SUM(rent),0) FROM units WHERE p=?" . $uSc . " AND status='Leased'"); $rSt->execute([$p['id']]); $rentRoll = (int)$rSt->fetchColumn();
        $vSt = $pdo->prepare("SELECT COALESCE(SUM(rent),0) FROM units WHERE p=?" . $uSc . " AND status='Vacant'"); $vSt->execute([$p['id']]); $vacLoss = (int)$vSt->fetchColumn();
        $rows[] = ['prop' => $p['id'], 'name' => $p['name'], 'type' => $p['type'], 'units' => $units, 'leased' => $leased,
            'vacant' => $units - $leased, 'occupancy' => $units ? (int)round($leased / $units * 100) : 0,
            'rent_roll' => $rentRoll, 'vacancy_loss' => $vacLoss];
        $totU += $units; $totL += $leased; $roll += $rentRoll; $vLoss += $vacLoss;
    }
    $sc = $scope ? " AND l.u IN (SELECT id FROM units WHERE sub_email=" . $pdo->quote($scope) . ")" : '';
    $exp = $pdo->query("SELECT l.id, l.end, l.rent, t.name tenant, u.name unit, p.name prop FROM leases l JOIN tenants t ON t.id=l.t JOIN units u ON u.id=l.u LEFT JOIN properties p ON p.id=u.p WHERE l.status='Active' AND l.end <= date('now','+90 days') AND l.end >= date('now')" . $sc . " ORDER BY l.end")->fetchAll(PDO::FETCH_ASSOC);
    return ['properties' => $rows, 'units' => $totU, 'leased' => $totL, 'vacant' => $totU - $totL,
        'occupancy' => $totU ? (int)round($totL / $totU * 100) : 0, 'rent_roll' => $roll, 'vacancy_loss' => $vLoss, 'expiries' => $exp];
}
function analytics_maintenance($pdo, $scope = '') {
    $sc = $scope ? " WHERE (unit IN (SELECT id FROM units WHERE sub_email=" . $pdo->quote($scope) . ") OR prop IN (SELECT id FROM properties WHERE sub_email=" . $pdo->quote($scope) . "))" : '';
    $byStatus = $pdo->query('SELECT status, COUNT(*) n FROM maintenance_requests' . $sc . ' GROUP BY status ORDER BY n DESC')->fetchAll(PDO::FETCH_ASSOC);
    $byPriority = $pdo->query('SELECT priority, COUNT(*) n FROM maintenance_requests' . $sc . ' GROUP BY priority ORDER BY n DESC')->fetchAll(PDO::FETCH_ASSOC);
    $byCharge = $pdo->query("SELECT charge_to, COUNT(*) n, COALESCE(SUM(actual_cost),0) cost FROM maintenance_requests" . $sc . " GROUP BY charge_to ORDER BY cost DESC")->fetchAll(PDO::FETCH_ASSOC);
    $byProperty = $pdo->query("SELECT COALESCE(NULLIF(m.prop,''),'—') prop, COALESCE(p.name, COALESCE(NULLIF(m.prop,''),'—')) name, COUNT(*) n, COALESCE(SUM(m.actual_cost),0) cost, SUM(CASE WHEN m.status NOT IN ('Closed','Cancelled','Done') THEN 1 ELSE 0 END) open FROM maintenance_requests m LEFT JOIN properties p ON p.id=m.prop" . $sc . " GROUP BY m.prop ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
    $totalCost = (int)$pdo->query('SELECT COALESCE(SUM(actual_cost),0) FROM maintenance_requests' . $sc)->fetchColumn();
    $estOpen = (int)$pdo->query("SELECT COALESCE(SUM(cost_estimate),0) FROM maintenance_requests" . $sc . ($sc ? " AND status NOT IN ('Closed','Cancelled','Done')" : " WHERE status NOT IN ('Closed','Cancelled','Done')"))->fetchColumn();
    $openCount = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_requests" . $sc . ($sc ? " AND status NOT IN ('Closed','Cancelled','Done')" : " WHERE status NOT IN ('Closed','Cancelled','Done')"))->fetchColumn();
    $doneCount = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_requests" . $sc . ($sc ? " AND status IN ('Closed','Done')" : " WHERE status IN ('Closed','Done')"))->fetchColumn();
    $avgResolve = (float)$pdo->query("SELECT COALESCE(AVG(julianday(updated_at)-julianday(ts)),0) FROM maintenance_requests" . $sc . ($sc ? " AND status IN ('Closed','Done') AND updated_at<>ts AND updated_at<>''" : " WHERE status IN ('Closed','Done') AND updated_at<>ts AND updated_at<>''"))->fetchColumn();
    $aging = $pdo->query("SELECT id, title, status, priority, CAST(julianday('now')-julianday(ts) AS INTEGER) days FROM maintenance_requests" . $sc . ($sc ? " AND status NOT IN ('Closed','Cancelled','Done')" : " WHERE status NOT IN ('Closed','Cancelled','Done')") . " ORDER BY days DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    return ['by_status' => $byStatus, 'by_priority' => $byPriority, 'by_charge' => $byCharge, 'by_property' => $byProperty,
        'total_cost' => $totalCost, 'estimated_open' => $estOpen, 'open_count' => $openCount, 'done_count' => $doneCount,
        'avg_resolve_days' => round($avgResolve, 1), 'aging' => $aging];
}
function board_report_md($pdo, $month, $scope = '') {
    $pnl = analytics_pnl($pdo, $month, $scope);
    $tr = analytics_trends($pdo, 6, $scope);
    $ag = analytics_aging($pdo, $scope);
    $vc = analytics_vacancy($pdo, $scope);
    $fc = analytics_forecast($pdo, $scope);
    $cf = analytics_cashflow($pdo, 12, $scope);
    $co = analytics_collections($pdo, 12, $scope);
    $oc = analytics_occupancy($pdo, $scope);
    $mt = analytics_maintenance($pdo, $scope);
    $sc = analytics_scores($pdo, $scope);
    $t = $pnl['totals'];
    $l = [];
    $l[] = '# KRTaker Portfolio Board Report — ' . $month;
    $l[] = '';
    $l[] = '## Executive summary';
    $l[] = '';
    $l[] = '- Gross rent: ৳' . number_format($t['gross']) . ' · Collected: ৳' . number_format($t['collected']) . ' · Net: ৳' . number_format($t['net']);
    $l[] = '- Collection rate: ' . ($fc['collection_rate'] ?? $co['on_time_rate']) . '% · On-time payments: ' . $co['on_time_rate'] . '% (' . $co['on_time'] . '/' . ($co['payments'] ?: 0) . ')';
    $l[] = '- Occupancy: ' . $oc['occupancy'] . '% (' . $oc['leased'] . '/' . $oc['units'] . ' units) · Arrears: ৳' . number_format($ag['total']);
    $l[] = '- Vacancy loss: ৳' . number_format($vc['monthly_loss']) . '/mo · 12-mo net flow: ৳' . number_format($cf['total_net']);
    $l[] = '- Top risk: ' . $fc['top_risk'];
    $l[] = '';
    $l[] = '## P&L (' . $month . ')';
    $l[] = '';
    $l[] = '| Property | Gross | Collected | TDS | Service | Expenses | Net |';
    $l[] = '|---|---|---|---|---|---|---|';
    foreach ($pnl['properties'] as $r) $l[] = '| ' . $r['name'] . ' | ৳' . number_format($r['gross']) . ' | ৳' . number_format($r['collected']) . ' | ৳' . number_format($r['tds']) . ' | ৳' . number_format($r['service']) . ' | ৳' . number_format($r['expenses']) . ' | ৳' . number_format($r['net']) . ' |';
    $l[] = '| **Total** | **৳' . number_format($t['gross']) . '** | **৳' . number_format($t['collected']) . '** | **৳' . number_format($t['tds']) . '** | **৳' . number_format($t['service']) . '** | **৳' . number_format($t['expenses']) . '** | **৳' . number_format($t['net']) . '** |';
    $l[] = '';
    $l[] = '## Cashflow · 12 months';
    $l[] = '';
    $l[] = '- Income: ৳' . number_format($cf['total_income']) . ' · Expenses: ৳' . number_format($cf['total_expenses']) . ' · Net: ৳' . number_format($cf['total_net']) . ' · Expense ratio: ' . $cf['expense_ratio'] . '%';
    $l[] = '- Best month: ' . array_reduce($cf['months'], fn($a, $m) => ($m['net'] > ($a['net'] ?? -INF)) ? $m : $a)['month'] . ' (৳' . number_format(array_reduce($cf['months'], fn($a, $m) => ($m['net'] > ($a['net'] ?? -INF)) ? $m : $a)['net']) . ')';
    $l[] = '- Worst month: ' . array_reduce($cf['months'], fn($a, $m) => ($m['net'] < ($a['net'] ?? INF)) ? $m : $a)['month'] . ' (৳' . number_format(array_reduce($cf['months'], fn($a, $m) => ($m['net'] < ($a['net'] ?? INF)) ? $m : $a)['net']) . ')';
    $l[] = '';
    $l[] = '## Collections';
    $l[] = '';
    $l[] = '- Payments: ' . $co['payments'] . ' · On-time: ' . $co['on_time'] . ' (' . $co['on_time_rate'] . '%) · Late: ' . $co['late'] . ' (avg ' . $co['avg_days_late'] . 'd) · Late amount: ৳' . number_format($co['late_amount']);
    if (!empty($co['by_method'])) {
        $l[] = '- Top method: ' . $co['by_method'][0]['method'] . ' ৳' . number_format($co['by_method'][0]['amount']) . ' (' . $co['by_method'][0]['n'] . ' payments)';
    }
    $l[] = '';
    $l[] = '## Occupancy & renewals';
    $l[] = '';
    $l[] = '| Property | Leased | Vacant | Occupancy | Rent roll | Vacancy loss |';
    $l[] = '|---|---|---|---|---|---|';
    foreach ($oc['properties'] as $p) $l[] = '| ' . $p['name'] . ' | ' . $p['leased'] . '/' . $p['units'] . ' | ' . $p['vacant'] . ' | ' . $p['occupancy'] . '% | ৳' . number_format($p['rent_roll']) . ' | ৳' . number_format($p['vacancy_loss']) . ' |';
    if (!empty($oc['expiries'])) {
        $l[] = '';
        $l[] = '**Leases expiring within 90 days (' . count($oc['expiries']) . '):**';
        foreach ($oc['expiries'] as $e) $l[] = '- ' . $e['prop'] . ' · ' . $e['unit'] . ' · ' . $e['tenant'] . ' · ends ' . $e['end'];
    }
    $l[] = '';
    $l[] = '## Maintenance';
    $l[] = '';
    $l[] = '- Open tickets: ' . $mt['open_count'] . ' · Resolved: ' . $mt['done_count'] . ' · Avg resolve: ' . $mt['avg_resolve_days'] . 'd · Total cost: ৳' . number_format($mt['total_cost']);
    if (!empty($mt['aging'])) {
        $l[] = '- Oldest open: ' . implode(' · ', array_slice(array_map(fn($a) => $a['id'] . ' (' . $a['days'] . 'd)', $mt['aging']), 0, 3));
    }
    $l[] = '';
    $l[] = '## At-risk tenants (' . count($sc['at_risk']) . ')';
    $l[] = '';
    if (!empty($sc['at_risk'])) {
        $l[] = '| Tenant | Band | Score | Overdue | On-time | Tickets |';
        $l[] = '|---|---|---|---|---|---|';
        foreach ($sc['at_risk'] as $rt) $l[] = '| ' . $rt['name'] . ' | ' . $rt['band'] . ' | ' . $rt['score'] . ' | ৳' . number_format($rt['overdue']) . ' | ' . $rt['on_time'] . '% | ' . $rt['tickets_open'] . ' |';
    } else {
        $l[] = '- No tenants at risk 🎉';
    }
    $l[] = '';
    $l[] = '## Aging — outstanding ৳' . number_format($ag['total']);
    $l[] = '- Current: ৳' . number_format($ag['current']) . ' · 30d: ৳' . number_format($ag['d30']) . ' · 60d: ৳' . number_format($ag['d60']) . ' · 90d+: ৳' . number_format($ag['d90']);
    $l[] = '';
    $l[] = '## Vacancy loss — ৳' . number_format($vc['monthly_loss']) . '/mo (annual ৳' . number_format($vc['annual_loss']) . ')';
    foreach ($vc['units'] as $u) $l[] = '- ' . $u['prop'] . ' · ' . $u['name'] . ' · market ৳' . number_format($u['rent']);
    $l[] = '';
    $l[] = '## 12-month forecast';
    $l[] = '- Collection rate: ' . $fc['collection_rate'] . '% · avg monthly collected ৳' . number_format($fc['avg_collected']);
    $l[] = '- 12-month total: ৳' . number_format($fc['total_forecast']);
    $l[] = '- Top risk: ' . $fc['top_risk'];
    $l[] = '';
    $l[] = '---';
    $l[] = 'Generated ' . date('d M Y H:i') . ' · KRTaker Portfolio Intelligence';
    $l[] = '';
    return implode("\n", $l);
}
/* ---------- Phase 32: owner statements & P&L helpers (top-level) ---------- */
function statement_line_items($pdo, $prop, $month) {
    $st = $pdo->prepare("SELECT i.id AS inv, i.gross, i.tds, i.net, i.status AS inv_status,
        l.id AS lease, u.name AS unit_name, u.id AS unit, t.name AS tenant_name, t.id AS tenant
        FROM invoices i JOIN leases l ON l.id = i.l
        JOIN units u ON u.id = l.u JOIN tenants t ON t.id = l.t
        WHERE u.p=? AND i.m=? ORDER BY u.name");
    $st->execute([$prop, $month]);
    $pct = 0.0;
    $pr = $pdo->prepare('SELECT service_charge_pct FROM property_rent WHERE prop=?');
    $pr->execute([$prop]);
    $pct = (float)$pr->fetchColumn();
    $colSt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE inv=? AND status='Success'");
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $colSt->execute([$r['inv']]);
        $collected = (int)$colSt->fetchColumn();
        $service = (int)round((int)$r['gross'] * $pct / 100);
        $lines[] = [
            'inv' => $r['inv'], 'lease' => $r['lease'], 'unit' => $r['unit'],
            'unit_name' => $r['unit_name'], 'tenant' => $r['tenant'],
            'tenant_name' => $r['tenant_name'], 'gross' => (int)$r['gross'],
            'tds' => (int)$r['tds'], 'collected' => $collected,
            'service' => $service, 'net' => $collected - $service - (int)$r['tds'],
            'inv_status' => $r['inv_status'],
        ];
    }
    return $lines;
}
function statement_expenses($pdo, $prop, $month) {
    $st = $pdo->prepare("SELECT id, title, actual_cost, charge_to, updated_at, category
        FROM maintenance_requests
        WHERE prop=? AND actual_cost>0 AND status IN ('Resolved','Closed')
        AND charge_to IN ('owner','service') AND substr(updated_at,1,7)=? ORDER BY updated_at");
    $st->execute([$prop, $month]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    $total = 0;
    foreach ($items as &$e) { $e['actual_cost'] = (int)$e['actual_cost']; $total += $e['actual_cost']; }
    return ['items' => $items, 'total' => $total];
}
function statement_calc($pdo, $prop, $month) {
    $lines = statement_line_items($pdo, $prop, $month);
    $exp = statement_expenses($pdo, $prop, $month);
    $gross = 0; $collected = 0; $tds = 0; $service = 0;
    foreach ($lines as $l) {
        $gross += $l['gross']; $collected += $l['collected'];
        $tds += $l['tds']; $service += $l['service'];
    }
    $po = null;
    $pst = $pdo->prepare('SELECT * FROM statement_payouts WHERE prop=? AND month=?');
    $pst->execute([$prop, $month]);
    $po = $pst->fetch(PDO::FETCH_ASSOC);
    if ($po) $po['amount'] = (int)$po['amount'];
    return [
        'prop' => $prop, 'month' => $month,
        'gross' => $gross, 'collected' => $collected, 'tds' => $tds,
        'service' => $service, 'expenses' => $exp['total'],
        'net' => $collected - $tds - $service - $exp['total'],
        'payout' => $po, 'lines' => $lines, 'expense_items' => $exp['items'],
    ];
}
/* ---------- Phase 33: compliance & expiry tracker helpers (top-level) ---------- */
function compliance_sync($pdo) {
    $seen = [];
    foreach ($pdo->query('SELECT entity_type, entity_id, item FROM compliance_items')->fetchAll(PDO::FETCH_ASSOC) as $r)
        $seen[$r['entity_type'] . '|' . $r['entity_id'] . '|' . $r['item']] = 1;
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'CPL-','') AS INTEGER)) FROM compliance_items")->fetchColumn();
    $mk = function () use (&$mx) { $mx++; return 'CPL-' . str_pad((string)$mx, 3, '0', STR_PAD_LEFT); };
    $ins = $pdo->prepare('INSERT OR IGNORE INTO compliance_items (id, entity_type, entity_id, item, label, ref_no, issue_date, expiry_date, notes, status) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $need = function ($et, $eid, $item, $label, $ref, $issue, $expiry, $notes) use (&$seen, $ins, $mk) {
        $k = $et . '|' . $eid . '|' . $item;
        if (isset($seen[$k])) return;
        $seen[$k] = 1;
        $ins->execute([$mk(), $et, $eid, $item, $label, $ref, $issue, $expiry, $notes, 'active']);
    };
    foreach ($pdo->query("SELECT l.id, l.end, u.name AS unit_name, t.name AS tenant_name FROM leases l JOIN tenants t ON t.id=l.t JOIN units u ON u.id=l.u WHERE l.status IN ('Active','Pending Registration')")->fetchAll(PDO::FETCH_ASSOC) as $l)
        $need('lease', $l['id'], 'lease_expiry', 'Lease expiry — ' . $l['tenant_name'] . ' · ' . $l['unit_name'], '', '', $l['end'], 'Renew or vacate before this date');
    foreach ($pdo->query("SELECT ip.id, ip.end, ip.plan, t.name AS tenant_name FROM insurance_policies ip JOIN tenants t ON t.id=ip.tenant WHERE ip.status IN ('active','applied','approved')")->fetchAll(PDO::FETCH_ASSOC) as $ip)
        $need('policy', $ip['id'], 'insurance_expiry', 'Insurance — ' . $ip['plan'] . ' · ' . $ip['tenant_name'], $ip['id'], '', $ip['end'], 'Policy coverage window');
    foreach ($pdo->query('SELECT id, name, jur FROM properties')->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $need('property', $p['id'], 'trade_license', 'Trade license — ' . $p['name'], $p['jur'], '', '', 'Renew annually at City Corporation');
        $need('property', $p['id'], 'fire_safety', 'Fire safety certificate — ' . $p['name'], 'BFSCD', '', '', 'Inspection by Fire Service & Civil Defence');
    }
    foreach ($pdo->query("SELECT id, name, nid FROM tenants WHERE nid!=''")->fetchAll(PDO::FETCH_ASSOC) as $t)
        $need('tenant', $t['id'], 'nid', 'NID on file — ' . $t['name'], $t['nid'], '', '', 'Verify identity document');
}
function compliance_rows($pdo) {
    compliance_sync($pdo);
    $today = date('Y-m-d');
    $rows = $pdo->query('SELECT * FROM compliance_items ORDER BY expiry_date, entity_type, id')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $status = 'valid';
        if ($r['expiry_date'] && $r['expiry_date'] < $today) $status = 'expired';
        elseif ($r['expiry_date'] && $r['expiry_date'] <= date('Y-m-d', strtotime('+45 days'))) $status = 'expiring_soon';
        $entity_label = '';
        if ($r['entity_type'] === 'property') { $s = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $s->execute([$r['entity_id']]); $entity_label = (string)$s->fetchColumn(); }
        elseif ($r['entity_type'] === 'lease') { $s = $pdo->prepare('SELECT u FROM leases WHERE id=?'); $s->execute([$r['entity_id']]); $u = (string)$s->fetchColumn(); if ($u) { $s = $pdo->prepare('SELECT name FROM units WHERE id=?'); $s->execute([$u]); $entity_label = (string)$s->fetchColumn(); } }
        elseif ($r['entity_type'] === 'tenant') { $s = $pdo->prepare('SELECT name FROM tenants WHERE id=?'); $s->execute([$r['entity_id']]); $entity_label = (string)$s->fetchColumn(); }
        elseif ($r['entity_type'] === 'policy') { $s = $pdo->prepare('SELECT tenant FROM insurance_policies WHERE id=?'); $s->execute([$r['entity_id']]); $tid = (string)$s->fetchColumn(); if ($tid) { $s = $pdo->prepare('SELECT name FROM tenants WHERE id=?'); $s->execute([$tid]); $entity_label = 'Policy · ' . (string)$s->fetchColumn(); } }
        $r['status'] = $status;
        $r['entity_label'] = $entity_label;
        $r['days_left'] = 0;
        if ($r['expiry_date']) $r['days_left'] = (int)floor((strtotime($r['expiry_date']) - strtotime($today)) / 86400);
        $out[] = $r;
    }
    return $out;
}

/* ---------- Phase 2: seed ---------- */
function seed_app() {
    $pdo = db();
    /* optional demo reset: wipe P16-era utility/renewal rows so canonical P34/P35 seeds land */
    if (($_GET['reset'] ?? '') === '1') {
        $pdo->exec('DELETE FROM meter_readings');
        $pdo->exec('DELETE FROM utility_bills');
        $pdo->exec('DELETE FROM renewal_requests');
        $pdo->exec("UPDATE leases SET end='2026-12-14', rent=40000, status='Active' WHERE id='L-007'");
        $pdo->exec("UPDATE leases SET rent=32000, status='Active' WHERE id='L-002'");
        $pdo->exec("UPDATE leases SET rent=25000, status='Active' WHERE id='L-001'");
        $pdo->exec('DELETE FROM partner_invoices');
        $pdo->exec('DELETE FROM vendor_payouts');
        $pdo->exec('DELETE FROM remittances');
        $pdo->exec('DELETE FROM onboarding_apps');
        $pdo->exec('DELETE FROM vendor_ratings');
        $pdo->exec('DELETE FROM sla_config');
        $pdo->exec('DELETE FROM kr_alerts');
        $pdo->exec('DELETE FROM wa_channels');
        $pdo->exec('DELETE FROM kr_wa_msgs');
        $pdo->exec('DELETE FROM board_reports');
        $pdo->exec('DELETE FROM job_media');
        $pdo->exec('DELETE FROM nid_verifications');
        $pdo->exec('DELETE FROM thana_forms');
        $pdo->exec('DELETE FROM legal_notices');
        $pdo->exec('DELETE FROM case_events');
        $pdo->exec('DELETE FROM cases');
        $pdo->exec('DELETE FROM land_media');
        $pdo->exec('DELETE FROM land_visits');
        $pdo->exec('DELETE FROM land_events');
        $pdo->exec('DELETE FROM land_parcels');
        $pdo->exec('DELETE FROM nrb_tax_returns');
        $pdo->exec('DELETE FROM nrb_repatriations');
        $pdo->exec('DELETE FROM nrb_showings');
        $pdo->exec('DELETE FROM nrb_vacancies');
        $pdo->exec('DELETE FROM nrb_disputes');
        $pdo->exec('DELETE FROM concierge_requests');
        $pdo->exec('DELETE FROM concierge_docs');
        $pdo->exec('DELETE FROM holding_taxes');
        $pdo->exec('DELETE FROM smart_locks');
        $pdo->exec('DELETE FROM cctv_cameras');
        $pdo->exec('DELETE FROM health_plans');
        $pdo->exec('DELETE FROM build_media');
        $pdo->exec('DELETE FROM build_expenses');
        $pdo->exec('DELETE FROM build_milestones');
        $pdo->exec('DELETE FROM build_projects');
        $pdo->exec('DELETE FROM gate_visits');
        $pdo->exec('DELETE FROM resident_vehicles');
        $pdo->exec('DELETE FROM gate_watchlist');
        $pdo->exec('DELETE FROM fire_assets');
        $pdo->exec('DELETE FROM fire_incidents');
        $pdo->exec('DELETE FROM evacuation_plans');
        $pdo->exec('DELETE FROM emergency_contacts');
        $pdo->exec("DELETE FROM org_settings WHERE k LIKE 'fire_%'");
        $pdo->exec('DELETE FROM sys_assets');
        $pdo->exec('DELETE FROM sys_services');
        $pdo->exec('DELETE FROM sys_fuel');
        $pdo->exec("DELETE FROM org_settings WHERE k LIKE 'sys_%'");
        $pdo->exec('DELETE FROM building_staff');
        $pdo->exec('DELETE FROM staff_attendance');
        $pdo->exec('DELETE FROM samity_collections');
        $pdo->exec('DELETE FROM samity_bills');
        $pdo->exec('DELETE FROM samity_members');
        $pdo->exec("DELETE FROM org_settings WHERE k LIKE 'samity_%'");        $pdo->exec('DELETE FROM staff_payroll');
        $pdo->exec("DELETE FROM org_settings WHERE k LIKE 'staff_%'");
        $pdo->exec("DELETE FROM org_settings WHERE k LIKE 'gate_%'");
        $pdo->exec("DELETE FROM org_settings WHERE k LIKE 'build_%'");
        $pdo->exec("DELETE FROM org_settings WHERE k LIKE 'nrb_%'");

        $pdo->exec("UPDATE maintenance_requests SET memo='', pay_release=0, pay_release_at='', pay_paid=0, pay_paid_at=''");
        $pdo->exec("UPDATE partner_invoices SET status='Submitted' WHERE status='Payable'");

        $pdo->exec("DELETE FROM leases WHERE u IN ('U-003','U-007')");
        $pdo->exec("DELETE FROM invoices WHERE l NOT IN (SELECT id FROM leases)");
        $pdo->exec("DELETE FROM handover_checklists WHERE lease NOT IN (SELECT id FROM leases)");
        $pdo->exec("UPDATE units SET status='Vacant' WHERE id IN ('U-003','U-007')");
        $pdo->exec('DELETE FROM remittances');
        $pdo->exec("UPDATE maintenance_requests SET qc_by='', qc_at=''");
        $pdo->exec("UPDATE maintenance_requests SET status='Open', assigned_to='', vendor='Rahim Steel Works', cost_estimate=120000, actual_cost=0, charge_to='owner', ts=datetime('now','-20 days'), updated_at=datetime('now','-20 days') WHERE id='MR-001'");
        $pdo->exec("UPDATE maintenance_requests SET status='Assigned', assigned_to='Arif Chowdhury', vendor='Kazi Plumbing', cost_estimate=3500, actual_cost=0, charge_to='tenant', ts=datetime('now','-4 days'), updated_at=datetime('now','-4 days') WHERE id='MR-002'");
        $pdo->exec("UPDATE maintenance_requests SET status='In Progress', assigned_to='Arif Chowdhury', vendor='', cost_estimate=8000, actual_cost=8500, charge_to='owner', ts=datetime('now','-6 days'), updated_at=datetime('now','-6 days') WHERE id='MR-003'");
        $pdo->exec("UPDATE maintenance_requests SET status='Resolved', assigned_to='', vendor='ElectroCool BD', cost_estimate=4500, actual_cost=4500, charge_to='tenant', ts=datetime('now','-2 days'), updated_at=datetime('now','-2 days') WHERE id='MR-004'");
        $pdo->exec("UPDATE maintenance_requests SET status='Closed', assigned_to='', vendor='', cost_estimate=1500, actual_cost=1500, charge_to='owner', ts=datetime('now','-30 days'), updated_at=datetime('now','-30 days') WHERE id='MR-005'");
        $pdo->exec("UPDATE leases SET rent=25000, status='Active' WHERE id='L-001'");
    }
    $starter_mods = json_encode(['owner' => ['dashboard','properties','units','tenants','leases','renewals','invoices','receipts','payments','taxes','statements','notices','documents','subscriptions','analytics'], 'tenant' => ['dashboard','invoices','receipts','payments','maintenance','notices','documents']]);
    $business_mods = json_encode(['owner' => ['dashboard','properties','units','tenants','leases','renewals','invoices','receipts','payments','taxes','statements','remit','maintenance','vendors','utilities','compliance','legal','trust','onboarding','leads','notices','documents','templates','referrals','subscriptions','analytics','ai'], 'manager' => ['dashboard','properties','units','tenants','leases','renewals','invoices','receipts','payments','taxes','statements','remit','maintenance','vendors','utilities','compliance','legal','trust','onboarding','leads','notices','documents','templates','analytics','ai'], 'tenant' => ['dashboard','invoices','receipts','payments','maintenance','notices','documents','ai']]);
    $enterprise_mods = json_encode(['owner' => ROLE_MODULES()['owner'], 'manager' => ROLE_MODULES()['manager'], 'svc_mgr' => ROLE_MODULES()['svc_mgr'], 'tenant' => ROLE_MODULES()['tenant'], 'partner' => ROLE_MODULES()['partner'], 'legal' => ROLE_MODULES()['legal'], 'crm' => ROLE_MODULES()['crm'], 'accountant' => ROLE_MODULES()['accountant'], 'hr' => ROLE_MODULES()['hr']]);
    $has = (int)$pdo->query('SELECT COUNT(*) FROM plan_catalog')->fetchColumn();
    if ($has === 0) {
        $plans = [
            ['starter', 'Starter', 4999, 1, 'Most popular', json_encode(['1 property', '1 seat', 'Smart invoicing & signed receipts', 'Tenant portal', 'Payment links (bKash / Nagad)']), $starter_mods, json_encode(['property_limit'=>1,'unit_limit'=>5,'seats'=>1,'kr_ai'=>false,'api_access'=>false,'reports'=>true])],
            ['business', 'Business', 14999, 3, 'Best value', json_encode(['Up to 10 properties', '3 seats', 'Everything in Starter', 'Holding tax & TDS engines', 'Maintenance + service partners', 'KR AI caretaker']), $business_mods, json_encode(['property_limit'=>10,'unit_limit'=>50,'seats'=>3,'kr_ai'=>true,'api_access'=>false,'reports'=>true])],
            ['enterprise', 'Enterprise', 39999, 10, 'For portfolios', json_encode(['Unlimited properties', '10 seats', 'Everything in Business', 'Legal & compliance docket', 'Dedicated success manager', 'API access']), $enterprise_mods, json_encode(['property_limit'=>9999,'unit_limit'=>99999,'seats'=>10,'kr_ai'=>true,'api_access'=>true,'reports'=>true])],
        ];
        $st = $pdo->prepare('INSERT INTO plan_catalog (code, name, price, seats, tag, features, modules, limits) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($plans as $p) $st->execute($p);
    }
    /* ── P55: backfill modules/limits for plans seeded before the columns existed ── */
    $bmods = [
        'starter' => $starter_mods, 'business' => $business_mods, 'enterprise' => $enterprise_mods,
    ];
    $blims = [
        'starter' => json_encode(['property_limit'=>1,'unit_limit'=>5,'seats'=>1,'kr_ai'=>false,'api_access'=>false,'reports'=>true]),
        'business' => json_encode(['property_limit'=>10,'unit_limit'=>50,'seats'=>3,'kr_ai'=>true,'api_access'=>false,'reports'=>true]),
        'enterprise' => json_encode(['property_limit'=>9999,'unit_limit'=>99999,'seats'=>10,'kr_ai'=>true,'api_access'=>true,'reports'=>true]),
    ];
    foreach ($bmods as $bcode => $bmod) {
        $chk = $pdo->prepare('SELECT modules, limits FROM plan_catalog WHERE lower(code)=?');
        $chk->execute([$bcode]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if ($row && (empty($row['modules']) || $row['modules'] === '[]')) {
            $pdo->prepare('UPDATE plan_catalog SET modules=? WHERE lower(code)=?')->execute([$bmod, $bcode]);
        }
        if ($row && (empty($row['limits']) || $row['limits'] === '{}' || $row['limits'] === '[]')) {
            /* SA1 v18: '[]' (empty JSON array) is also stale — live DB catalog was written
               with array limits, so per-plan caps never applied there. Backfill the object. */
            $pdo->prepare('UPDATE plan_catalog SET limits=? WHERE lower(code)=?')->execute([$blims[$bcode], $bcode]);
        }
    }

    $cnt = (int)$pdo->query('SELECT COUNT(*) FROM properties')->fetchColumn();
    if ($cnt === 0) {
        $props = [
            ['P-001','Green View Residency','Flat','Dhaka North','12/5, Mirpur-10',18000,82000000,'Active'],
            ['P-002','Banani Commercial Tower','Commercial','Dhaka North','32/K, Banani C/A',45000,320000000,'Active'],
            ['P-003','Gulshan Lakeside Plot','Plot','Dhaka North','Plot 7, Block SE(D)',3600,150000000,'Active'],
            ['P-004','Chattogram Industrial Shed','Industrial','Chattogram','KEPZ Plot 18, Patenga',30000,175000000,'Active'],
            ['P-005','Dhanmondi Apartment','Flat','Dhaka South','Road 9/A, Dhanmondi',2450,18500000,'Active'],
        ];
        $st = $pdo->prepare('INSERT INTO properties (id, name, type, jur, holding, sqft, value, status, published) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($props as $p) $st->execute(array_merge($p, [1]));

        $units = [
            ['U-001','P-001','Flat 3B','3rd',1450,'Leased',25000], ['U-002','P-001','Flat 4A','4th',1650,'Leased',32000],
            ['U-003','P-001','Flat 5C','5th',1200,'Vacant',28000], ['U-004','P-002','Floor 1 (Retail)','1st',6000,'Leased',450000],
            ['U-005','P-002','Floor 2 (Office)','2nd',5200,'Leased',380000], ['U-006','P-002','Floor 3 (Office)','3rd',5200,'Leased',350000],
            ['U-007','P-003','Plot 7 (SE(D))','—',3600,'Vacant',35000], ['U-008','P-004','Shed A','Ground',18000,'Leased',210000],
            ['U-009','P-004','Shed B','Ground',12000,'Maintenance',180000], ['U-010','P-005','Apartment 7B','7th',2450,'Leased',40000],
            ['U-011','P-005','Flat 1A','1st',1250,'Vacant',0],   /* SA1-v17: was orphaned (p=P-006, no such property); repointed to P-005 */
        ];
        $st = $pdo->prepare('INSERT INTO units (id,p,name,floor,sqft,status,rent) VALUES (?,?,?,?,?,?,?)');
        foreach ($units as $u) $st->execute($u);

        $rents = [
            ['P-001',5,25000,4000,8,3,5,2,1500,'Community generator + lift maintenance shared 5%'],
            ['P-002',8,50000,15000,6,3,3,2,7200,'Commercial service charge 8%; 2 covered car parks'],
            ['P-003',2,20000,0,10,0,10,1,9000,'Plot only — no building utilities'],
            ['P-004',6,80000,6000,5,2,3,2,6500,'Factory common area + security 6%'],
            ['P-005',4,15000,2500,7,3,5,2,7000,'Apartment: lift, generator, caretaker 4%'],
        ];
        $st = $pdo->prepare('INSERT INTO property_rent (prop, service_charge_pct, utility_advance, parking_fee, escalation_pct, advance_months, due_day, late_fee_pct, rent_per_sqft, notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($rents as $r) $st->execute($r);

        $amens = [
            ['AM-001','P-001','','Lift','🛗','Building'], ['AM-002','P-001','','Standby generator','⚡','Building'],
            ['AM-003','P-001','','CCTV security','📹','Building'], ['AM-004','P-001','U-001','1 car parking','🚗','Unit'],
            ['AM-005','P-001','','Rooftop terrace','🌇','Building'], ['AM-006','P-002','','Central AC','❄️','Building'],
            ['AM-007','P-002','','Fire safety system','🧯','Building'], ['AM-008','P-002','U-004','2 car parks (retail)','🚗','Unit'],
            ['AM-009','P-002','','24/7 security','🛡️','Building'], ['AM-010','P-003','','Lake view','🏞️','Plot'],
            ['AM-011','P-005','','Lift','🛗','Building'], ['AM-012','P-005','U-010','1 car parking','🚗','Unit'],
            ['AM-013','P-005','','Caretaker','🧑‍🔧','Building'], ['AM-014','P-005','','Generator backup','⚡','Building'],
            ['AM-015','P-004','','Cargo ramp','🚚','Building'], ['AM-016','P-004','','Loading bay','📦','Building'],
        ];
        $st = $pdo->prepare('INSERT INTO amenities (id, prop, unit, name, icon, category, status) VALUES (?,?,?,?,?,?,?)');
        foreach ($amens as $a) $st->execute($a);

        $tenants = [
            ['T-001','Rafiqul Islam','01711-223344','rafiqul@gmail.com','19900123456789012',0,'Individual',''],
            ['T-002','Sultana Rahman','01712-445566','sultana.r@yahoo.com','19850112233445021',0,'Individual','sultana@krtaker.com'],
            ['T-003','Orbit Textiles Ltd','01711-778899','accounts@orbittextiles.com','BIN-004512367',0,'Corporate',''],
            ['T-004','Dr. Nadia Karim','01713-556677','nadia.karim@outlook.com','19880108990011031',1,'Individual',''],
            ['T-005','Bengal Agro Foods','01713-889900','finance@bengalagro.com','BIN-006734512',0,'Corporate',''],
            ['T-006','Tanvir Hossain','01714-223344','tanvir.h@gmail.com','1993223445566',0,'Individual',''],
            ['T-007','Maria Chowdhury (NRB)','+1 646-555-0182','maria.chowdhury@nyc.com','1975123456780',1,'Individual',''],
            ['T-008','Ahmed & Sons Traders','01715-990011','ahmedsons@outlook.com','BIN-009812345',0,'Corporate',''],
        ];
        $st = $pdo->prepare('INSERT INTO tenants (id,name,phone,email,nid,nrb,kind,sub_email) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($tenants as $t) $st->execute($t);

        $leases = [
            ['L-001','U-001','T-001','2026-01-01','2026-12-31',25000,25000,1,'','','Active'],
            ['L-002','U-002','T-004','2025-11-01','2027-10-31',32000,32000,1,'Sub-Registry Mirpur','DL-4521/2025','Active'],
            ['L-003','U-004','T-003','2025-06-01','2027-05-31',450000,450000,0,'Sub-Registry Gulshan','DL-3877/2025','Active'],
            ['L-004','U-005','T-005','2026-03-01','2027-02-28',380000,380000,0,'','','Pending Registration'],
            ['L-005','U-006','T-008','2024-07-01','2026-06-30',350000,700000,0,'Sub-Registry Gulshan','DL-2110/2024','Active'],
            ['L-006','U-008','T-006','2026-02-01','2026-10-31',210000,210000,0,'','','Active'],
            ['L-007','U-010','T-002','2026-01-15','2026-12-14',40000,40000,1,'','','Active'],
            ['L-008','U-002','T-007','2023-03-01','2024-02-29',28000,56000,1,'Sub-Registry Dhanmondi','DL-1108/2023','Expired'],
            ['L-009','U-009','T-005','2025-09-01','2026-08-31',180000,180000,0,'','','Terminated'],
            ['L-010','U-001','T-006','2026-06-01','2028-05-31',28000,56000,1,'','','Pending Registration'],
        ];
        $st = $pdo->prepare('INSERT INTO leases VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($leases as $l) $st->execute($l);

        $invoices = [
            ['INV-2026-001','L-001','2026-06',25000,0,25000,'Paid'], ['INV-2026-002','L-002','2026-06',32000,0,32000,'Paid'],
            ['INV-2026-003','L-003','2026-06',450000,45000,405000,'Paid'], ['INV-2026-004','L-004','2026-06',380000,38000,342000,'Unpaid'],
            ['INV-2026-005','L-005','2026-06',350000,35000,315000,'Unpaid'], ['INV-2026-006','L-006','2026-06',210000,0,210000,'Overdue'],
            ['INV-2026-007','L-007','2026-06',40000,0,40000,'Paid'], ['INV-2026-008','L-004','2026-07',380000,38000,342000,'Overdue'],
        ];
        $st = $pdo->prepare('INSERT INTO invoices VALUES (?,?,?,?,?,?,?)');
        foreach ($invoices as $i) $st->execute($i);

        $receipts = [
            ['RCP-0001','INV-2026-001',25000,'2026-06-05','bKash','SIG-9f3a21c8'],
            ['RCP-0002','INV-2026-002',32000,'2026-06-08','Bank','SIG-4b71d90e'],
            ['RCP-0003','INV-2026-003',405000,'2026-06-10','bKash','SIG-c2e58a77'],
            ['RCP-0004','INV-2026-007',40000,'2026-06-12','Nagad','SIG-77d21f0a'],
        ];
        $st = $pdo->prepare('INSERT INTO receipts VALUES (?,?,?,?,?,?)');
        foreach ($receipts as $r) $st->execute($r);

        $payments = [
            ['PAY-001','INV-2026-001',25000,'bKash','BK-7f2a','2026-06-05','Success'],
            ['PAY-002','INV-2026-003',405000,'bKash','BK-91cd','2026-06-10','Success'],
            ['PAY-003','INV-2026-007',40000,'Nagad','NG-33ab','2026-06-12','Success'],
        ];
        $st = $pdo->prepare('INSERT INTO payments VALUES (?,?,?,?,?,?,?)');
        foreach ($payments as $p) $st->execute($p);

        $tickets = [
            ['MT-001','U-008','Roof structural crack over Shed A','2026-06-18','Landlord','Open','Rahim Steel Works',0],
            ['MT-002','U-001','Kitchen sink leakage','2026-06-21','Tenant','Open','',0],
            ['MT-003','U-010','Water pump failure (common line)','2026-06-24','Landlord','In Progress','Kazi Plumbing',8500],
            ['MT-004','U-009','Flooring damage from tenant forklift','2026-06-27','Tenant','Awaiting Payment','Meghna Builders',42000],
        ];
        $st = $pdo->prepare('INSERT INTO tickets VALUES (?,?,?,?,?,?,?,?)');
        foreach ($tickets as $t) $st->execute($t);

        $partners = [
            ['SP-01','Rahim Steel Works','Structural & Steel',4.8,24,'Active','rahim@krtaker.com'],
            ['SP-02','Kazi Plumbing','Plumbing & Sanitary',4.5,41,'Active',''],
            ['SP-03','Meghna Builders','Interior & Renovation',4.2,17,'Active',''],
            ['SP-04','SecureLine Security','Security Services',4.9,33,'Active',''],
            ['SP-05','CleanPro BD','Cleaning & Facility',4.0,52,'Onboarding',''],
        ];
        $st = $pdo->prepare('INSERT INTO partners (id,name,trade,rating,jobs,status,sub_email) VALUES (?,?,?,?,?,?,?)');
        foreach ($partners as $p) $st->execute($p);

        $staff = [
            ['ST-01','Arif Chowdhury','Service Manager','Operations','Active'], ['ST-02','Mithila Rahman','CRM & Help Desk','Support','Active'],
            ['ST-03','Sohel Rana','Accountant','Finance','Active'], ['ST-04','Nusrat Jahan','HR & Admin','Admin','Active'],
            ['ST-05','Barrister Naima Karim','Legal Counsel','Legal','Active'], ['ST-06','Tanvir Hasan','Service Manager (Jr)','Operations','Active'],
            ['ST-07','Farzana Akter','CRM Executive','Support','Probation'], ['ST-08','Mahmudul Islam','Finance Officer','Finance','Active'],
        ];
        $st = $pdo->prepare('INSERT INTO staff VALUES (?,?,?,?,?)');
        foreach ($staff as $s) $st->execute($s);

        $support = [
            ['SUP-001','Rofiqul Islam (Owner)','Cannot upload registration deed for L-004','Open','High','2h'],
            ['SUP-002','Orbit Textiles (Tenant)','TDS certificate not received for June','Open','Medium','5h'],
            ['SUP-003','Sultana Rahman (Tenant)','How to submit repair invoice for deduction?','In Progress','Low','1d'],
            ['SUP-004','Rahim Steel Works (Partner)','QC feedback on MT-001 — photos uploaded','In Progress','Medium','1d'],
            ['SUP-005','Dr. Nadia Karim (Tenant)','Payment failed — bKash timeout','Resolved','High','2d'],
            ['SUP-006','Bengal Agro Foods (Tenant)','Need invoice re-issue with VAT','Open','Medium','3h'],
        ];
        $st = $pdo->prepare('INSERT INTO support VALUES (?,?,?,?,?,?)');
        foreach ($support as $s) $st->execute($s);

        $meta = [
            ['subscribers','128'], ['mrr','2480000'], ['arr','29760000'], ['partners','45'],
            ['staff','8'], ['csat','4.6'], ['subs_trend','+12 this month'],
        ];
        $st = $pdo->prepare('INSERT INTO platform_meta (k,v) VALUES (?,?)');
        foreach ($meta as $m) $st->execute($m);
    }

    /* staff app users — V3.88: real role-based users, NO demo accounts */
    $uc = (int)$pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn();
    if ($uc === 0) {
        $staff_users = [
            ['Platform Admin','kabir@krtaker.com','36VRzfNC3b43D6','superadmin','Management','KB'],
            ['Rofiqul Islam','owner@krtaker.com','danhQn5oBNPC','owner','Property Owner','RI'],
            ['Shakil Ahmed','manager@krtaker.com','tC8xOXd6SaBy','manager','Operations','SA'],
            ['Nusrat Jahan','hr@krtaker.com','zXNu2cVpbpJF','hr','HR & Admin','NJ'],
            ['Sohel Rana','accountant@krtaker.com','ZOobUZDVKIsT','accountant','Finance','SR'],
            ['Mithila Rahman','crm@krtaker.com','RZAstOvnGA77','crm','CRM & Support','MR'],
            ['Barrister Naima','legal@krtaker.com','62GS5u4zzSRY','legal','Legal Counsel','BN'],
            ['Arif Chowdhury','svc_mgr@krtaker.com','UhxCUVvqSD91','svc_mgr','Service Manager','AC'],
            ['Sultana Rahman','tenant@krtaker.com','yMgRyJ9emqXN','tenant','Tenant','SR'],
            ['Rahim Steel Works','partner@krtaker.com','Bt9VPiUyOiBT','partner','Service Partner','RS'],
        ];
        $st = $pdo->prepare('INSERT INTO app_users (name, email, password_hash, role, dept, avatar) VALUES (?,?,?,?,?,?)');
        foreach ($staff_users as $s) $st->execute([$s[0], $s[1], password_hash($s[2], PASSWORD_DEFAULT), $s[3], $s[4], $s[5]]);
    }
    /* V3.88: no demo subscriber accounts (owner@/tenant@/partner@ are app_users now) */

    /* Phase 8–10: notice board, ticket threads, referral demo data (idempotent) */
    notice_seed();
    $tc = (int)$pdo->query('SELECT COUNT(*) FROM ticket_thread')->fetchColumn();
    if ($tc === 0) {
        $th = [
            ['MT-001', 'Rahim Steel Works', 'Inspection done — crack is load-bearing; steel jacketing proposed. Cost estimate ৳1,85,000.', '2026-06-19 10:12:00'],
            ['MT-001', 'Rofiqul Islam', 'Approved. Start next week; coordinate with tenant for access.', '2026-06-20 09:40:00'],
            ['MT-003', 'Kazi Plumbing', 'Pump replaced; testing pressure now.', '2026-06-25 16:05:00'],
            ['MT-004', 'Meghna Builders', 'Quote sent: ৳42,000 incl. materials. Awaiting tenant confirmation.', '2026-06-28 11:22:00'],
        ];
        $st = $pdo->prepare('INSERT OR IGNORE INTO ticket_thread (ticket, author, body, ts) VALUES (?,?,?,?)');
        foreach ($th as $r) $st->execute($r);
    }
    $rc = (int)$pdo->query('SELECT COUNT(*) FROM referrals')->fetchColumn();
    if ($rc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO referrals (id, code, user_email, role, referred_name, referred_email, status, reward) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute(['REF-A1B2C3', 'KR-DEMO1', 'owner@krtaker.com', 'owner', 'Tanvir Hossain', 'tanvir.h@gmail.com', 'Signed up', 5000]);
        $st->execute(['REF-D4E5F6', 'KR-DEMO2', 'owner@krtaker.com', 'owner', 'Orbit Textiles Ltd', 'accounts@orbittextiles.com', 'Paid', 15000]);
    }
    $pub = (int)$pdo->query('SELECT COUNT(*) FROM properties WHERE published=1')->fetchColumn();
    if ($pub === 0) $pdo->exec("UPDATE properties SET published=1 WHERE id IN ('P-001','P-002','P-003','P-005')");

    /* Phase 25: idempotent fills for rent config + amenities (DBs seeded before P25) */
    $prc = (int)$pdo->query('SELECT COUNT(*) FROM property_rent')->fetchColumn();
    if ($prc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO property_rent (prop, service_charge_pct, utility_advance, parking_fee, escalation_pct, advance_months, due_day, late_fee_pct, rent_per_sqft, notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ([
            ['P-001',5,25000,4000,8,3,5,2,1500,'Community generator + lift maintenance shared 5%'],
            ['P-002',8,50000,15000,6,3,3,2,7200,'Commercial service charge 8%; 2 covered car parks'],
            ['P-003',2,20000,0,10,0,10,1,9000,'Plot only — no building utilities'],
            ['P-004',6,80000,6000,5,2,3,2,6500,'Factory common area + security 6%'],
            ['P-005',4,15000,2500,7,3,5,2,7000,'Apartment: lift, generator, caretaker 4%'],
        ] as $r) $st->execute($r);
    }
    $amc = (int)$pdo->query('SELECT COUNT(*) FROM amenities')->fetchColumn();
    if ($amc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO amenities (id, prop, unit, name, icon, category, status) VALUES (?,?,?,?,?,?,?)');
        foreach ([
            ['AM-001','P-001','','Lift','🛗','Building'], ['AM-002','P-001','','Standby generator','⚡','Building'],
            ['AM-003','P-001','','CCTV security','📹','Building'], ['AM-004','P-001','U-001','1 car parking','🚗','Unit'],
            ['AM-005','P-001','','Rooftop terrace','🌇','Building'], ['AM-006','P-002','','Central AC','❄️','Building'],
            ['AM-007','P-002','','Fire safety system','🧯','Building'], ['AM-008','P-002','U-004','2 car parks (retail)','🚗','Unit'],
            ['AM-009','P-002','','24/7 security','🛡️','Building'], ['AM-010','P-003','','Lake view','🏞️','Plot'],
            ['AM-011','P-005','','Lift','🛗','Building'], ['AM-012','P-005','U-010','1 car parking','🚗','Unit'],
            ['AM-013','P-005','','Caretaker','🧑‍🔧','Building'], ['AM-014','P-005','','Generator backup','⚡','Building'],
            ['AM-015','P-004','','Cargo ramp','🚚','Building'], ['AM-016','P-004','','Loading bay','📦','Building'],
        ] as $a) $st->execute($a);
    }
    /* Phase 28: idempotent caretaker-invoice demo fill for CS-001 (live sub from P22) */
    $cic = (int)$pdo->query('SELECT COUNT(*) FROM caretaker_invoices')->fetchColumn();
    if ($cic === 0) {
        $st = $pdo->prepare("SELECT id, user_email, price, next_invoice FROM caretaker_subs WHERE status='active' ORDER BY ts LIMIT 1");
        $st->execute();
        $demo = $st->fetch(PDO::FETCH_ASSOC);
        if ($demo && $demo['next_invoice'] !== '') {
            $pdo->prepare('INSERT OR IGNORE INTO caretaker_invoices (id, sub, user_email, month, amount, status) VALUES (?,?,?,?,?,?)')
                ->execute(['CI-001', $demo['id'], $demo['user_email'], $demo['next_invoice'], (int)$demo['price'], 'Unpaid']);
        }
    }
    /* Phase 29: insurance plan catalog (idempotent) */
    $ipc = (int)$pdo->query('SELECT COUNT(*) FROM insurance_plans')->fetchColumn();
    if ($ipc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO insurance_plans (id, name, type, premium, coverage, coverage_label, features) VALUES (?,?,?,?,?,?,?)');
        $plans = [
            ['IP-001', 'Rent Guarantee', 'rent_guarantee', 499, 120000, 'Up to 3 months rent', json_encode(['Covers rent if you lose your income', 'Landlord paid directly (no advance needed)', 'Up to 3 months rent covered', 'Claim in 48 hours with job-loss proof'], JSON_UNESCAPED_UNICODE)],
            ['IP-002', 'Deposit Shield', 'deposit_shield', 299, 40000, 'Full advance deposit', json_encode(['Protects your advance deposit', 'Covers unfair withholding', 'Free legal notice on dispute', 'Refund gap paid within 7 days'], JSON_UNESCAPED_UNICODE)],
            ['IP-003', 'Damage Cover', 'damage_cover', 199, 50000, 'Up to ৳50,000 per incident', json_encode(['Accidental damage to the unit', 'Covers furniture & fittings', 'Claims settled in 5 working days', 'No-fault claims do not raise premium'], JSON_UNESCAPED_UNICODE)],
        ];
        foreach ($plans as $p) $st->execute($p);
    }

    /* Phase 30: maintenance demo requests (idempotent) */
    $mrc = (int)$pdo->query('SELECT COUNT(*) FROM maintenance_requests')->fetchColumn();
    if ($mrc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO maintenance_requests (id, tenant, unit, prop, category, priority, title, desc, status, assigned_to, vendor, cost_estimate, actual_cost, charge_to, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $mrs = [
            ['MR-001','T-006','U-008','P-004','structural','high','Roof structural crack over Shed A','Crack line widening after last storm; needs steel reinforcement assessment.','Open','','Rahim Steel Works',120000,0,'owner','shakil@krtaker.com'],
            ['MR-002','T-002','U-010','P-005','plumbing','medium','Kitchen sink leakage','Water seeping under sink cabinet; tap joint leaking.','Assigned','Arif Chowdhury','Kazi Plumbing',3500,0,'tenant','sultana@krtaker.com'],
            ['MR-003','T-001','U-001','P-001','electrical','medium','Hall socket sparking','Main hall wall socket sparks when used; suspect wiring.','In Progress','Arif Chowdhury','',8000,8500,'owner','rafiqul@gmail.com'],
            ['MR-004','T-005','U-005','P-002','appliance','low','AC cooling weak (Floor 2)','Split AC not cooling below 26°C; gas refill likely.','Resolved','','ElectroCool BD',4500,4500,'tenant','nadia@orbit.com'],
            ['MR-005','T-003','U-004','P-002','other','low','Common lobby light flickering','Lobby tube lights flickering; replace ballast.','Closed','','',1500,1500,'owner','shakil@krtaker.com'],
        ];
        foreach ($mrs as $m) $st->execute($m);
    }
    /* Phase 31: demo leasing leads (idempotent) */
    $ldc = (int)$pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn();
    if ($ldc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO leads (id, name, phone, email, prop, source, message, status, assigned_to, notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $lds = [
            ['LD-001','Tanvir Hossain','01712-998877','tanvir.h@gmail.com','P-001','listing','Interested in Flat 3B (1450 sqft). When can I view?','New','','Came via listings page inquiry'],
            ['LD-002','Farhana Yasmin','01811-223344','farhana.y@outlook.com','P-005','referral','Referred by Sultana. Looking for 2-bed apartment under ৳45k.','Contacted','Mithila Rahman','Called — viewing Saturday'],
            ['LD-003','Nabil Ahmed','01922-334455','nabil@bizmail.com','P-002','listing','Need 2 floors for office expansion, 5k sqft each.','Viewing','Shakil Islam','Viewing Floor 2 & 3 Tuesday'],
            ['LD-004','Rima Sultana','01611-556677','rima.s@gmail.com','P-003','other','Plot inquiry — is it for lease or sale?','Applied','','Sent application + NID'],
            ['LD-005','Kamal Uddin','01555-667788','kamal.u@yahoo.com','P-001','referral','Referred by Dr. Nadia. Flat 4A availability?','Lost','','Wanted immediate move-in — unit not ready'],
        ];
        foreach ($lds as $l) $st->execute($l);
    }
    /* Phase 32: demo owner-statement payouts (idempotent) */
    $spc = (int)$pdo->query('SELECT COUNT(*) FROM statement_payouts')->fetchColumn();
    if ($spc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO statement_payouts (prop, month, amount, status, method, ref) VALUES (?,?,?,?,?,?)');
        foreach ([
            ['P-001','2026-06',186400,'Paid','Bank','NPSB-88412'],
            ['P-002','2026-06',883000,'Paid','Bank','NPSB-88415'],
            ['P-005','2026-06',34500,'Scheduled','',''],
            ['P-001','2026-05',181200,'Paid','Bank','NPSB-87840'],
            ['P-002','2026-05',876500,'Paid','Bank','NPSB-87843'],
        ] as $sp) $st->execute($sp);
    }
    /* Phase 33: demo compliance items (idempotent) */
    $cpc = (int)$pdo->query('SELECT COUNT(*) FROM compliance_items')->fetchColumn();
    if ($cpc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO compliance_items (id, entity_type, entity_id, item, label, ref_no, issue_date, expiry_date, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $cpls = [
            ['CPL-001','property','P-001','fire_safety','Fire safety certificate — Dhanmondi Apartment','BFSCD-2041','2025-08-01','2026-07-15','active','Annual inspection — certificate expired'],
            ['CPL-002','property','P-005','trade_license','Trade license — Green View Residency','DNCC-TL-8821','2025-09-01','2026-09-05','active','Renew before expiry at DNCC'],
            ['CPL-003','property','P-002','fire_safety','Fire safety certificate — Banani Commercial Tower','BFSCD-2042','2025-08-01','2026-08-01','active','Annual inspection due now'],
            ['CPL-004','tenant','T-002','nid','NID on file — Sultana Rahman','1992123456789','','2036-12-31','active','Identity document on file'],
            ['CPL-005','lease','L-007','lease_expiry','Lease expiry — Sultana Rahman · Apartment 7B','L-007','','2026-12-14','active','Renew or vacate before this date'],
        ];
        foreach ($cpls as $c) $st->execute($c);
    }

    /* Phase 36: demo partner invoices + vendor payouts (idempotent) */
    $pic = (int)$pdo->query('SELECT COUNT(*) FROM partner_invoices')->fetchColumn();
    if ($pic === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO partner_invoices (id, partner, job, amount, desc, status, submitted_by, decided_by, decided_at) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ([
            ['PI-001','SP-01','MR-001',185000,'Steel jacketing + reinforcement for roof crack — materials & labour','Approved','rahim@krtaker.com','Arif Chowdhury','2026-06-30 11:20:00'],
            ['PI-002','SP-02','MR-002',3500,'Kitchen sink tap joint replacement + pipe sealant','Submitted','kazi.plumbing@gmail.com','',''],
            ['PI-003','SP-03','MR-003',8500,'Rewiring hall socket + new MCB (hall socket sparking)','Paid','meghna.builders@gmail.com','Sohel Rana','2026-07-05 15:40:00'],
        ] as $i) $st->execute($i);
    }
    $vpc = (int)$pdo->query('SELECT COUNT(*) FROM vendor_payouts')->fetchColumn();
    if ($vpc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO vendor_payouts (id, partner, month, amount, status, method, ref) VALUES (?,?,?,?,?,?,?)');
        foreach ([
            ['VP-001','SP-01','2026-06',185000,'Paid','Bank','NPSB-33001'],
            ['VP-002','SP-02','2026-06',3500,'Scheduled','',''],
            ['VP-003','SP-03','2026-05',8500,'Paid','bKash','BK-88231'],
        ] as $v) $st->execute($v);
    }
    /* Phase 37: demo NRB remittances (idempotent) */
    $rmc = (int)$pdo->query('SELECT COUNT(*) FROM remittances')->fetchColumn();
    if ($rmc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO remittances (id, owner_email, owner_name, prop, month, amount, rate, amount_fx, currency, method, ref, form_c, status, confirmed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ([
            ['RM-001','owner@krtaker.com','Rofiqul Islam','P-001','2026-06',186400,118.50,1573,'USD','SWIFT','SW-BD-88412','FC-88412','Confirmed','2026-07-03 09:15:00'],
            ['RM-002','owner@krtaker.com','Rofiqul Islam','P-002','2026-06',883000,118.50,7451,'USD','SWIFT','SW-BD-88415','FC-88415','Sent',''],
            ['RM-003','owner@krtaker.com','Rofiqul Islam','P-001','2026-05',181200,118.00,1536,'USD','SWIFT','SW-BD-87840','FC-87840','Confirmed','2026-06-02 10:05:00'],
        ] as $r) $st->execute($r);
    }

    /* Phase 38: demo onboarding apps (idempotent) */
    $obc = (int)$pdo->query('SELECT COUNT(*) FROM onboarding_apps')->fetchColumn();
    if ($obc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO onboarding_apps (id, lead, name, email, phone, nid, unit, prop, rent, adv, months, start, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ([
            ['OB-001','LD-002','Farhana Yasmin','farhana.y@outlook.com','01811-223344','198706224411','U-003','P-001',28000,56000,12,'2026-09-01','Started','Referred by Sultana. Awaiting application docs.'],
            ['OB-002','LD-001','Tanvir Hossain','tanvir.h@gmail.com','01712-998877','199205144523','U-003','P-001',28000,56000,12,'2026-09-01','Submitted','NID + bank statement uploaded — KYC review pending'],
            ['OB-003','LD-004','Rima Sultana','rima.s@gmail.com','01611-556677','199011305566','U-007','P-003',35000,70000,12,'2026-08-15','Verified','KYC approved — ready to complete move-in'],
        ] as $o) $st->execute($o);
    }
    /* Phase 39: demo SLA config + vendor ratings (idempotent) */
    $slac = (int)$pdo->query('SELECT COUNT(*) FROM sla_config')->fetchColumn();
    if ($slac === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO sla_config (priority, response_hours, resolve_hours, enabled) VALUES (?,?,?,1)');
        foreach ([['urgent',4,24], ['high',24,72], ['medium',72,168], ['low',120,240]] as $s) $st->execute($s);
    }
    $vrc = (int)$pdo->query('SELECT COUNT(*) FROM vendor_ratings')->fetchColumn();
    if ($vrc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO vendor_ratings (id, partner, job, rating, comment, rated_by) VALUES (?,?,?,?,?,?)');
        foreach ([
            ['VR-001','SP-01','MR-001',5,'Fast response, quality steelwork.','Arif Chowdhury'],
            ['VR-002','SP-02','MR-002',4,'Good work, slight delay on parts.','Shakil Ahmed'],
        ] as $v) $st->execute($v);
    }
    /* Phase 43: demo NID verifications + thana forms (idempotent) */
    $nvc = (int)$pdo->query('SELECT COUNT(*) FROM nid_verifications')->fetchColumn();
    if ($nvc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO nid_verifications (id, tenant, nid, dob, status, method, checksum_ok, age_ok, notes, verified_by, verified_at, ts) VALUES (?,?,?,?,?,?,?,?,?,?,datetime(\'now\'),datetime(\'now\'))');
        foreach ([
            ['NV-001','T-001','19900123456789012','1990-01-23','verified','checksum',1,1,'Check digit valid.','Kabir Ahmed',],
            ['NV-002','T-002','19850112233445021','1985-11-22','verified','checksum',1,1,'Check digit valid.','Kabir Ahmed',],
            ['NV-003','T-004','19880108990011031','1988-08-08','unverified','checksum',0,1,'Check digit mismatch — verify manually.','',],
        ] as $n) $st->execute($n);
    }
    $tfc = (int)$pdo->query('SELECT COUNT(*) FROM thana_forms')->fetchColumn();
    if ($tfc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO thana_forms (id, tenant, unit, prop, thana, district, status, payload, submitted_at, verified_by, verified_at, created_by, ts) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,datetime(\'now\'))');
        $p1 = json_encode(['unit'=>'U-001','prop'=>'P-001','name'=>'Rafiqul Islam','nid'=>'19900123456789012','dob'=>'1990-01-23','phone'=>'01711-223344','father'=>'Abdul Karim','mother'=>'Rahima Khatun','profession'=>'Business','employer'=>'Rafi Traders','present_flat'=>'Flat 3B, Green View Residency','present_road'=>'12/5, Mirpur-10','present_area'=>'Mirpur','permanent_address'=>'Village: Charpara, P.O. Sirajganj','spouse'=>'Nasima Begum','spouse_phone'=>'01711-334455','family_count'=>'4','ref1_name'=>'Md. Hanif','ref1_phone'=>'01811-112233','ref1_address'=>'Mirpur-12','ref2_name'=>'Shahidul Islam','ref2_phone'=>'01911-556677','ref2_address'=>'Pallabi','landlord_name'=>'Rofiqul Islam (Owner)','landlord_nid'=>'','landlord_phone'=>'01700-000000','move_in'=>'2026-01-01','lease_term'=>'12','vehicle'=>'None','remarks'=>'Corporate lease reference NA'], JSON_UNESCAPED_UNICODE);
        $p2 = json_encode(['unit'=>'U-010','prop'=>'P-001','name'=>'Sultana Rahman','nid'=>'19850112233445021','dob'=>'1985-11-22','phone'=>'01712-445566','father'=>'Late A. Rahman','mother'=>'Jahanara Begum','profession'=>'Banker','employer'=>'Dutch-Bangla Bank','present_flat'=>'Flat 10A, Green View Residency','present_road'=>'12/5, Mirpur-10','present_area'=>'Mirpur','permanent_address'=>'House 22, Road 4, Dhanmondi','spouse'=>'Kamal Hossain','spouse_phone'=>'01712-778899','family_count'=>'3','ref1_name'=>'Rafiqul Islam','ref1_phone'=>'01711-223344','ref1_address'=>'Green View Residency','ref2_name'=>'Nusrat Jahan','ref2_phone'=>'01611-223344','ref2_address'=>'Mirpur DOHS','landlord_name'=>'Rofiqul Islam (Owner)','landlord_nid'=>'','landlord_phone'=>'01700-000000','move_in'=>'2026-01-15','lease_term'=>'11','vehicle'=>'Toyota Axio (Dhaka Metro-GHA-12-3456)','remarks'=>''], JSON_UNESCAPED_UNICODE);
        foreach ([
            ['TF-001','T-001','U-001','P-001','Mirpur','Dhaka','Verified',$p1,'2026-02-01 10:00:00','Kabir Ahmed','2026-02-02 12:00:00','Kabir Ahmed',],
            ['TF-002','T-002','U-010','P-001','Mirpur','Dhaka','Submitted',$p2,'2026-07-20 09:30:00','','','Kabir Ahmed',],
        ] as $f) $st->execute($f);
    }
    /* Phase 44: demo legal notices + dispute cases + audit config (idempotent) */
    $lnc = (int)$pdo->query('SELECT COUNT(*) FROM legal_notices')->fetchColumn();
    if ($lnc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO legal_notices (id, ntype, lease, tenant, unit, reason, body, notice_days, effective_date, served_on, served_by, status, created_by, ts) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,datetime(\'now\'))');
        $st->execute(['LN-001','eviction','L-004','T-005','U-005','Recurring rent default — 2 months outstanding','NOTICE TO QUIT / উচ্ছেদ নোটিশ\nTo: Bengal Agro Foods (L-004)\nPremises: Floor 1 (Retail) — Banani Commercial Tower (rent ৳3,80,000/month)\nYou are hereby given 90 days notice to vacate the premises. Effective date: ' . date('Y-m-d', strtotime('+90 days')) . '.\nআপনাকে ৯০ দিনের নোটিশ দেওয়া হল — নির্ধারিত তারিখের মধ্যে বাসস্থান খালি করুন।',90,date('Y-m-d', strtotime('+90 days')),'2026-07-01 10:00:00','Kabir Ahmed','Served','Kabir Ahmed']);
        $st->execute(['LN-002','rent_hike','L-006','T-006','U-008','Market adjustment — 10% proposed','NOTICE OF RENT INCREASE / ভাড়া বৃদ্ধি নোটিশ\nTo: Tanvir Hossain (L-006)\nPremises: Shed A — Chattogram Industrial Shed (rent ৳2,10,000/month)\nYour monthly rent will increase effective ' . date('Y-m-d', strtotime('+60 days')) . ' (notice period 60 days).\nআপনার মাসিক ভাড়া ৬০ দিন পর থেকে বৃদ্ধি পাবে।',60,date('Y-m-d', strtotime('+60 days')),'','','Draft','Naima Rahman']);
    }
    $cac = (int)$pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn();
    if ($cac === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO cases (id, title, ref_lease, type, status, opened, notes, stage, lawyer, next_hearing, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute(['CASE-001','Eviction proceedings — Bengal Agro Foods','L-004','eviction','Open','2026-07-01','90-day notice served 01 Jul; tenant seeking time','Legal Notice','Barrister Naima','2026-08-15','2026-07-15']);
        $st->execute(['CASE-002','Rent arrears — Tanvir Hossain','L-006','arrears','Open','2026-07-05','Two months arrears; payment plan proposed','Negotiation','','2026-07-30','2026-07-05']);
        $ce = $pdo->prepare('INSERT OR IGNORE INTO case_events (id, case_id, ev_type, body, actor, ts) VALUES (?,?,?,?,?,datetime(\'now\'))');
        $ce->execute(['EV-001','CASE-001','stage','Case opened — stage: Legal Notice','Kabir Ahmed']);
        $ce->execute(['EV-002','CASE-001','hearing','Next hearing listed for 15 Aug at Dhaka Chief Metropolitan Magistrate Court','Barrister Naima']);
        $ce->execute(['EV-003','CASE-002','stage','Case opened — stage: Negotiation','Kabir Ahmed']);
    }
    /* Phase 45: demo land parcels + visits + proof + events (idempotent) */
    $lpc = (int)$pdo->query('SELECT COUNT(*) FROM land_parcels')->fetchColumn();
    if ($lpc === 0) {
        $lp = $pdo->prepare("INSERT OR IGNORE INTO land_parcels (id, owner_email, name, district, upazila, mouza, khatian, dag, area, lat, lng, monitor, status, risk, notes, created_by, ts, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))");
        $lp->execute(['LP-001','owner@krtaker.com','Sirajganj Ancestral Land','Sirajganj','Ullapara','Charpara','KH-1482','DAG-110','8.5 decimal','24.2837','89.5872','quarterly','Secure','low','Family khas land beside the Bongshi river — watch river erosion','Kabir Ahmed']);
        $lp->execute(['LP-002','owner@krtaker.com','Savar Dhamrai Plot','Dhaka','Dhamrai','Kushura','KH-2105','DAG-47','12 decimal','23.9050','90.1965','quarterly','Needs Review','medium','Boundary wall partially damaged — verify adjacent construction','Kabir Ahmed']);
        $lp->execute(['LP-003','owner@krtaker.com','Rangpur Bagan Bari Land','Rangpur','Badarganj','Baganbari','KH-3310','DAG-22','20 decimal','25.6807','89.0660','semi_annual','Encroached','high','Illegal tin-shed structure reported by field agent','Kabir Ahmed']);
        $lp->execute(['LP-004','','Mymensingh Khas Plot','Mymensingh','Trishal','Kanthal','KH-0451','DAG-9','5 decimal','24.6100','90.4050','annual','Secure','low','Org-held parcel — annual drone sweep','Kabir Ahmed']);
        $lv = $pdo->prepare("INSERT OR IGNORE INTO land_visits (id, parcel, agent, visit_type, scheduled_for, completed_at, status, outcome, findings, checklist, created_by, completed_by, ts) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'))");
        $lv->execute(['LV-001','LP-001','Drone — DJI Mavic 3E','drone','2026-06-10','2026-06-10 11:20:00','Completed','Secure','Parcel clear. No structure or encroachment. Riverbank stable.','["Aerial sweep","Boundary check","Encroachment scan"]','Kabir Ahmed','Rahim Steel Works (agent)']);
        $lv->execute(['LV-002','LP-002','Karim Uddin (field agent)','field','2026-06-15','2026-06-15 15:40:00','Completed','Needs Review','Boundary wall damage on east side. Adjacent new construction starting.','["Perimeter walk","Boundary wall","Photos"]','Kabir Ahmed','Karim Uddin']);
        $lv->execute(['LV-003','LP-003','Karim Uddin (field agent)','field','2026-05-20','2026-05-20 09:10:00','Completed','Encroached','Illegal tin-shed + fencing found on SW corner. Owner notified; legal action advised.','["Perimeter walk","Occupancy check","Video"]','Kabir Ahmed','Karim Uddin']);
        $lv->execute(['LV-004','LP-001','Drone — DJI Mavic 3E','drone',date('Y-m-d', strtotime('+85 days')),'','Scheduled','','Quarterly aerial sweep','["Aerial sweep","Boundary check"]','Kabir Ahmed','']);
        $lv->execute(['LV-005','LP-002','Karim Uddin (field agent)','field',date('Y-m-d', strtotime('-5 days')),'','Scheduled','','Follow-up on boundary wall — MISSED, must reschedule','["Boundary wall","Photos"]','Kabir Ahmed','']);
        $lm = $pdo->prepare("INSERT OR IGNORE INTO land_media (id, visit, kind, name, fname, size, mime, geo, captured_at, uploaded_by, ts) VALUES (?,?,?,?,?,?,?,?,?,?,datetime('now'))");
        $lm->execute(['LM-001','LV-001','drone','Aerial overview — LP-001','lp001_aerial_01.jpg',2840132,'image/jpeg','24.2837,89.5872','2026-06-10 11:20:00','Rahim Steel Works (agent)']);
        $lm->execute(['LM-002','LV-002','photo','Boundary wall damage east side','lp002_wall_01.jpg',1983321,'image/jpeg','23.9050,90.1965','2026-06-15 15:40:00','Karim Uddin']);
        $lm->execute(['LM-003','LV-003','photo','Illegal tin-shed SW corner','lp003_shed_01.jpg',2415580,'image/jpeg','25.6807,89.0660','2026-05-20 09:10:00','Karim Uddin']);
        $lm->execute(['LM-004','LV-003','video','360 walkthrough of encroachment','lp003_walk_01.mp4',15842912,'video/mp4','25.6807,89.0660','2026-05-20 09:15:00','Karim Uddin']);
        $le = $pdo->prepare("INSERT OR IGNORE INTO land_events (id, parcel, ev_type, body, actor, ts) VALUES (?,?,?,?,?,datetime('now'))");
        $le->execute(['LE-001','LP-001','created','Parcel registered — quarterly monitoring plan','Kabir Ahmed']);
        $le->execute(['LE-002','LP-001','visit','Visit LV-001 completed — Secure','Rahim Steel Works (agent)']);
        $le->execute(['LE-003','LP-002','created','Parcel registered — quarterly monitoring plan','Kabir Ahmed']);
        $le->execute(['LE-004','LP-002','visit','Visit LV-002 completed — Needs Review (boundary wall)','Karim Uddin']);
        $le->execute(['LE-005','LP-002','note','Boundary wall repair estimate requested','Kabir Ahmed']);
        $le->execute(['LE-006','LP-003','created','Parcel registered — semi-annual monitoring plan','Kabir Ahmed']);
        $le->execute(['LE-007','LP-003','encroachment','Visit LV-003 found illegal tin-shed + fencing — status Encroached','Karim Uddin']);
        $le->execute(['LE-008','LP-004','created','Parcel registered — annual drone sweep','Kabir Ahmed']);
    }
    /* Phase 46: demo NRB Hub rows (idempotent) */
    $txc = (int)$pdo->query('SELECT COUNT(*) FROM nrb_tax_returns')->fetchColumn();
    if ($txc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO nrb_tax_returns (id, owner_email, prop, fy, rental_income, tds_deducted, tax_estimate, status, ca_firm, ca_ref, filing_date, paid_date, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute(['TX-001','owner@krtaker.com','P-001','2025-26',480000,48000,14400,'Filed','Ahmed & Co. Chartered Accountants','CA-8821','2026-05-30','','Filed via CA — TDS certificates attached']);
        $st->execute(['TX-002','owner@krtaker.com','P-002','2026-27',360000,36000,10800,'Draft','Ahmed & Co. Chartered Accountants','','','','Estimate auto-computed at 13% less TDS']);
    }
    $rpc = (int)$pdo->query('SELECT COUNT(*) FROM nrb_repatriations')->fetchColumn();
    if ($rpc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO nrb_repatriations (id, owner_email, amount_bdt, amount_fx, rate, currency, method, bank_channel, documents, status, ref, submitted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute(['RP-001','owner@krtaker.com',420000,3456.79,121.5,'USD','SWIFT','NRP_Account','["BB form C","Tax clearance certificate"]','Submitted','BB/NRP/2026/1147','2026-07-20']);
        $st->execute(['RP-002','owner@krtaker.com',250000,2049.18,122.0,'USD','bKash','BB_Approval','["Form C","Property ownership deed"]','Docs_Submitted','','']);
    }
    $vcc = (int)$pdo->query('SELECT COUNT(*) FROM nrb_vacancies')->fetchColumn();
    if ($vcc === 0) {
        $st = $pdo->prepare("INSERT OR IGNORE INTO nrb_vacancies (id, owner_email, prop, unit, status, started_at, tenant_left, target_rent, channels, listing_url, candidate, vet_status, notes, ts, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))");
        $st->execute(['VC-001','owner@krtaker.com','P-002','U-006','Marketing','2026-07-25','T-003 (moved out)','350000','["Bikroy","PropertyBarta"]','','{}','none','High-end commercial floor — premium NRB segment']);
        $st->execute(['VC-002','owner@krtaker.com','P-001','U-002','Awaiting_Approval','2026-07-10','','32000','["Bikroy"]','','{"name":"Farhana Akter","phone":"+8801712345678","email":"farhana@example.com","nid":"","rent":32000,"start":"2026-09-01","months":12,"advance":64000}','passed','NID check done via Trust Engine — candidate passed']);
        $st->execute(['VC-003','owner@krtaker.com','P-003','U-008','Vetting','2026-08-01','','210000','["PropertyBarta"]','','{"name":"Minhaj Hossain","phone":"+8801811122233","email":"","nid":"","rent":210000,"start":"2026-10-01","months":24,"advance":0}','pending','Background check in progress']);
    }
    $shc = (int)$pdo->query('SELECT COUNT(*) FROM nrb_showings')->fetchColumn();
    if ($shc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO nrb_showings (id, vacancy, prospect, phone, scheduled_at, status, feedback, rating) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute(['SH-001','VC-002','Farhana Akter','+8801712345678','2026-08-05','Done','Liked the flat — ready to sign on 1 Sep','5']);
        $st->execute(['SH-002','VC-001','Tanvir Ahmed','+8801911223344','2026-08-03','Done','Wants dedicated parking space','4']);
        $st->execute(['SH-003','VC-003','Minhaj Hossain','+8801811122233','2026-08-08','Scheduled','','0']);
    }
    $dsc = (int)$pdo->query('SELECT COUNT(*) FROM nrb_disputes')->fetchColumn();
    if ($dsc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO nrb_disputes (id, owner_email, prop, unit, tenant, dtype, advocate, fee, status, notice_ref, last_update, next_action, timeline, ts) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,datetime(\'now\'))');
        $tl1 = json_encode([['ts'=>'2026-07-01 10:00:00','action'=>'Dispute opened — tenant refusing to vacate after lease end','by'=>'Kabir Ahmed'],['ts'=>'2026-07-05 14:30:00','action'=>'Advocate assigned — Adv. Salma Khatun','by'=>'Kabir Ahmed'],['ts'=>'2026-07-12 09:00:00','action'=>'Eviction notice LN-592 served — 30-day notice period running','by'=>'Adv. Salma Khatun']], JSON_UNESCAPED_UNICODE);
        $st->execute(['DS-001','owner@krtaker.com','P-004','U-004','T-004 (refusing to vacate)','eviction','Adv. Salma Khatun — Bar Council 4451',50000,'Notice_Served','LN-592','Eviction notice served — 30-day period running','File suit if tenant does not vacate by 2026-08-11',$tl1]);
        $tl2 = json_encode([['ts'=>'2026-08-02 11:00:00','action'=>'Dispute opened — wall damage claim ৳18,000','by'=>'Kabir Ahmed']], JSON_UNESCAPED_UNICODE);
        $st->execute(['DS-002','owner@krtaker.com','P-001','U-001','T-001','damages','Adv. Rafiq Islam — Bar Council 2233',15000,'Open','','Claim filed — photos collected','Inspect unit and finalize claim amount',$tl2]);
    }
    foreach (['default_ca_firm'=>'Ahmed & Co. Chartered Accountants','default_advocate'=>'Adv. Salma Khatun','vacancy_alert_days'=>'30','dispute_alert_days'=>'14'] as $ck => $cv) {
        $pdo->prepare('INSERT OR IGNORE INTO org_settings (k, v) VALUES (?,?)')->execute(['nrb_' . $ck, $cv]);
    }


    /* Phase 47: demo Legal Concierge rows (idempotent) */
    $crc = (int)$pdo->query('SELECT COUNT(*) FROM concierge_requests')->fetchColumn();
    if ($crc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO concierge_requests (id, owner_email, parcel, prop, service, district, upazila, mouza, khatian, dag, status, assigned_to, est_days, fee, fee_status, fee_paid_at, service_ref, notes, timeline) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $tl1 = json_encode([['ts' => '2026-07-18 10:00:00', 'action' => 'Request submitted — Namjari for LP-002', 'by' => 'Kabir Ahmed'], ['ts' => '2026-07-19 12:00:00', 'action' => 'Under review — documents collected', 'by' => 'Mithila Rahman'], ['ts' => '2026-07-25 15:30:00', 'action' => 'Filed at AC Land office — case no AC/NA/MUT/2026/881', 'by' => 'Mithila Rahman']], JSON_UNESCAPED_UNICODE);
        $st->execute(['CR-001', 'owner@krtaker.com', 'LP-002', '', 'namjari', 'Tangail', 'Tangail Sadar', 'Kurigram', 'KN-114', 'DAG-221', 'In_Progress', 'Mithila Rahman', 45, 5000, 'paid', '2026-07-22 11:00:00', 'AC/NA/MUT/2026/881', 'Mutation application filed — awaiting AC Land approval', $tl1]);
        $tl2 = json_encode([['ts' => '2026-07-05 09:00:00', 'action' => 'Request submitted — e-Porcha for LP-004', 'by' => 'Kabir Ahmed'], ['ts' => '2026-07-10 14:00:00', 'action' => 'e-Porcha extracted and verified — CS portal', 'by' => 'Mithila Rahman'], ['ts' => '2026-07-28 10:00:00', 'action' => 'Delivered to owner — CD-002 attached', 'by' => 'Mithila Rahman']], JSON_UNESCAPED_UNICODE);
        $st->execute(['CR-002', 'owner@krtaker.com', 'LP-004', '', 'e_porcha', 'Rangpur', 'Kaunia', 'Haragach', 'KN-031', 'DAG-887', 'Completed', 'Mithila Rahman', 15, 2500, 'paid', '2026-07-11 10:00:00', 'E-POR/2026/114', 'Verified e-Porcha delivered with CS digital seal', $tl2]);
        $tl3 = json_encode([['ts' => '2026-08-01 09:30:00', 'action' => 'Request submitted — holding tax payment for P-001', 'by' => 'Kabir Ahmed'], ['ts' => '2026-08-02 11:00:00', 'action' => 'Bill collected — DSCC holding 1842, FY 2025-26', 'by' => 'Mithila Rahman']], JSON_UNESCAPED_UNICODE);
        $st->execute(['CR-003', 'owner@krtaker.com', '', 'P-001', 'holding_tax', 'Dhaka', '', '', '', '', 'Awaiting_Fee', 'Mithila Rahman', 10, 1500, 'unpaid', '', '', 'Holding tax payment ready — pay service fee ৳1,500 to complete', $tl3]);
    }
    $cdc = (int)$pdo->query('SELECT COUNT(*) FROM concierge_docs')->fetchColumn();
    if ($cdc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO concierge_docs (id, request, kind, name, fname, size, mime, uploaded_by) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute(['CD-001', 'CR-001', 'application', 'Namjari application form', 'cd_app_form.pdf', 482103, 'application/pdf', 'Mithila Rahman']);
        $st->execute(['CD-002', 'CR-002', 'porcha', 'Verified e-Porcha (CS seal)', 'cd_porcha_lp004.pdf', 1204488, 'application/pdf', 'Mithila Rahman']);
    }
    $htc = (int)$pdo->query('SELECT COUNT(*) FROM holding_taxes')->fetchColumn();
    if ($htc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO holding_taxes (id, owner_email, parcel, prop, city_corp, ward, holding_no, fy, annual_value, rate_pct, tax_amount, paid_amount, status, due_date, paid_date, receipt_no, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute(['HT-001', 'owner@krtaker.com', '', 'P-001', 'DSCC', '27', '1842', '2025-26', 480000, 7.0, 33600, 0, 'Due', '2026-08-31', '', '', 'City corporation holding tax — FY 2025-26']);
        $st->execute(['HT-002', 'owner@krtaker.com', '', 'P-002', 'DNCC', '12', '558', '2025-26', 360000, 7.0, 25200, 25200, 'Paid', '2026-07-31', '2026-07-15', 'DSCC-RCP-2026-7741', 'Paid via concierge — receipt attached']);
        $st->execute(['HT-003', 'owner@krtaker.com', 'LP-003', '', 'CCC', '4', '901', '2026-27', 240000, 7.0, 16800, 0, 'Due', '2026-07-15', '', '', 'Holding tax for LP-003 — overdue demo']);
    }
    foreach (['concierge_agent' => 'Mithila Rahman', 'namjari_fee' => '5000', 'e_porcha_fee' => '2500', 'khatian_fee' => '3000', 'holding_tax_fee' => '1500', 'registration_fee' => '10000', 'namjari_days' => '45', 'e_porcha_days' => '15', 'khatian_days' => '20', 'holding_tax_days' => '10', 'registration_days' => '30', 'docs_stuck_days' => '7', 'holding_alert_days' => '30'] as $ck => $cv) {
        $pdo->prepare('INSERT OR IGNORE INTO org_settings (k, v) VALUES (?,?)')->execute(['concierge_' . $ck, $cv]);
    }

    /* Phase 48: demo Smart Home + Health Check rows (idempotent) */
    $slc = (int)$pdo->query('SELECT COUNT(*) FROM smart_locks')->fetchColumn();
    if ($slc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO smart_locks (id, owner_email, prop, unit, lock_name, model, code, status, purpose, grant_for, showing, valid_from, valid_until, used_count, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute(['SL-001', 'owner@krtaker.com', 'P-001', 'U-002', 'Unit 2 — front door', 'Yale YRD226', '483920', 'active', 'broker', 'Bikroy Broker', '', '2026-07-20 09:00:00', '2026-08-04 20:00:00', 0, 'Broker walkthrough code — expires 04 Aug']);
        $st->execute(['SL-002', 'owner@krtaker.com', 'P-001', 'U-002', 'Unit 2 — front door', 'Yale YRD226', '118374', 'revoked', 'showing', 'Farhana Akter', 'SH-001', '2026-08-05 08:00:00', '2026-08-05 20:00:00', 1, 'Showing code for SH-001 — used and revoked']);
    }
    $cvc = (int)$pdo->query('SELECT COUNT(*) FROM cctv_cameras')->fetchColumn();
    if ($cvc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO cctv_cameras (id, owner_email, prop, name, location, status, feed_url, last_seen, notes) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute(['CV-001', 'owner@krtaker.com', 'P-001', 'Lobby — main entrance', 'Front lobby entrance', 'online', 'https://stream.krtaker.com/cam/cv001', '2026-08-03 13:00:00', '24/7 recording — NVR on site']);
        $st->execute(['CV-002', 'owner@krtaker.com', 'P-002', 'Roof terrace', 'Banani tower roof — west side', 'offline', 'https://stream.krtaker.com/cam/cv002', '2026-08-01 09:00:00', 'Check NVR port 3 — no signal since 01 Aug']);
    }
    $hpc = (int)$pdo->query('SELECT COUNT(*) FROM health_plans')->fetchColumn();
    if ($hpc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO health_plans (id, owner_email, prop, unit, season, service, scheduled_for, status, assigned_to, cost, paid, checklist, findings, completed_at, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $chk1 = json_encode([['task' => 'Inspect roof membrane', 'done' => 0], ['task' => 'Clear drainage outlets', 'done' => 0], ['task' => 'Patch cracks', 'done' => 0], ['task' => 'Water test', 'done' => 0]], JSON_UNESCAPED_UNICODE);
        $st->execute(['HP-001', 'owner@krtaker.com', 'P-001', '', 'pre_monsoon', 'roof_waterproof', '2026-08', 'Scheduled', 'EcoClean Services', 12000, 0, $chk1, '', '', 'Waterproofing before monsoon peak']);
        $chk2 = json_encode([['task' => 'Clean filters', 'done' => 1], ['task' => 'Check gas pressure', 'done' => 1], ['task' => 'Inspect outdoor unit', 'done' => 1], ['task' => 'Test cooling', 'done' => 1]], JSON_UNESCAPED_UNICODE);
        $st->execute(['HP-002', 'owner@krtaker.com', 'P-002', 'U-010', 'pre_summer', 'ac_service', '2026-05', 'Completed', 'EcoClean Services', 4000, 4000, $chk2, 'All 3 units serviced — cooling OK', '2026-05-20 16:00:00', 'Pre-summer AC servicing']);
        $st->execute(['HP-003', 'owner@krtaker.com', 'P-003', 'U-008', 'quarterly', 'deep_clean', '2026-09', 'Planned', '', 0, 0, '[]', '', '', 'Empty flat — deep clean before renting']);
    }
    foreach (['smart_lock_valid_hours' => '48', 'health_alert_days' => '30', 'health_default_vendor' => 'EcoClean Services'] as $ck => $cv) {
        $pdo->prepare('INSERT OR IGNORE INTO org_settings (k, v) VALUES (?,?)')->execute([$ck, $cv]);
    }

    /* Phase 50: demo Gate Watch rows (idempotent) */
    $gvc = (int)$pdo->query('SELECT COUNT(*) FROM gate_visits')->fetchColumn();
    if ($gvc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO gate_visits (id, owner_email, prop, vtype, name, phone, vehicle_no, unit, purpose, host_name, check_in, check_out, status, flagged, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute(['GV-001', 'owner@krtaker.com', 'P-002', 'visitor', 'Mahmudul Karim', '01712345678', '', 'U-004', 'Meeting with tenant', 'Sultana Rahman', date('Y-m-d H:i:s', strtotime('-6 hours')), '', 'Inside', 0, 'Brother of tenant — long visit', 'Arif Chowdhury']);
        $st->execute(['GV-002', 'owner@krtaker.com', 'P-002', 'delivery', 'Pathao Food', '01711112222', 'DHAKA-LA-1234', '', 'Food delivery', '', date('Y-m-d H:i:s', strtotime('-2 hours')), date('Y-m-d H:i:s', strtotime('-90 minutes')), 'Out', 0, '', 'Arif Chowdhury']);
        $st->execute(['GV-003', 'owner@krtaker.com', 'P-001', 'worker', 'Electrical repair crew', '', 'DHAKA-METRO-7712', 'U-001', 'MR-003 electrical fix', 'Rahim Uddin', date('Y-m-d H:i:s', strtotime('-3 hours')), date('Y-m-d H:i:s', strtotime('-2 hours')), 'Out', 0, '', 'Arif Chowdhury']);
        $st->execute(['GV-004', 'owner@krtaker.com', 'P-003', 'vehicle', 'Unknown pickup', '', 'DHAKA-METRO-9999', '', 'Suspicious vehicle — watchlist demo', '', date('Y-m-d H:i:s', strtotime('-30 minutes')), '', 'Inside', 1, 'Auto-flagged by watchlist on check-in', 'Arif Chowdhury']);
    }
    $rvc = (int)$pdo->query('SELECT COUNT(*) FROM resident_vehicles')->fetchColumn();
    if ($rvc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO resident_vehicles (id, prop, unit, vehicle_no, vtype, label, owner_name, sticker_no, status) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute(['RV-001', 'P-002', 'U-003', 'DHAKA-METRO-1122', 'car', 'Honda City', 'Sultana Rahman', 'ST-101', 'active']);
        $st->execute(['RV-002', 'P-001', 'U-001', 'DHAKA-METRO-3344', 'bike', 'Yamaha FZS', 'Rahim Uddin', 'ST-102', 'active']);
    }
    $gwc = (int)$pdo->query('SELECT COUNT(*) FROM gate_watchlist')->fetchColumn();
    if ($gwc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO gate_watchlist (id, vehicle_no, name, reason, active) VALUES (?,?,?,?,1)');
        $st->execute(['GW-001', 'DHAKA-METRO-9999', 'Unknown pickup', 'Suspicious activity — reported by neighbour, avoid entry']);
    }
    foreach (['gate_auto_flag_hours' => '4', 'gate_report_title' => 'Daily Gate Register'] as $ck => $cv) {
        $pdo->prepare('INSERT OR IGNORE INTO org_settings (k, v) VALUES (?,?)')->execute([$ck, $cv]);
    }

    /* Phase 51: demo Fire Safety rows (idempotent) */
    $fac = (int)$pdo->query('SELECT COUNT(*) FROM fire_assets')->fetchColumn();
    if ($fac === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO fire_assets (id, owner_email, prop, asset_type, location, model, serial_no, install_date, expiry_date, status, last_inspected, next_inspection, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute(['FA-001', 'owner@krtaker.com', 'P-001', 'extinguisher', 'Lobby — ground floor', 'ABC-4kg Dry Powder', 'SN-101', '2025-08-01', date('Y-m-d', strtotime('+45 days')), 'active', date('Y-m-d', strtotime('-15 days')), date('Y-m-d', strtotime('+15 days')), 'Expiring soon — recharge after expiry']);
        $st->execute(['FA-002', 'owner@krtaker.com', 'P-002', 'extinguisher', 'Kitchen — 3rd floor', 'CO2-5kg', 'SN-102', '2024-06-10', date('Y-m-d', strtotime('-20 days')), 'active', date('Y-m-d', strtotime('-80 days')), date('Y-m-d', strtotime('-50 days')), 'EXPIRED — replace immediately']);
        $st->execute(['FA-003', 'owner@krtaker.com', 'P-001', 'detector', 'Hallway — 2nd floor', 'SD-2000', 'SN-103', '2026-01-15', date('Y-m-d', strtotime('+300 days')), 'active', date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('+30 days')), 'Smoke detector — annual battery check']);
        $st->execute(['FA-004', 'owner@krtaker.com', 'P-003', 'alarm', 'Fire alarm panel — lobby', 'FP-9000', 'SN-104', '2025-03-01', date('Y-m-d', strtotime('+200 days')), 'faulty', date('Y-m-d', strtotime('-10 days')), date('Y-m-d', strtotime('+20 days')), 'Zone 2 sensor fault — technician needed']);
    }
    $fic = (int)$pdo->query('SELECT COUNT(*) FROM fire_incidents')->fetchColumn();
    if ($fic === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO fire_incidents (id, owner_email, prop, incident_type, severity, status, reported_by, occurred_at, location, description, timeline, resolved_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $tl1 = json_encode([['t' => date('Y-m-d H:i:s', strtotime('-2 hours')), 'by' => 'Arif Chowdhury', 'note' => 'Incident reported — kitchen smoke from 3rd floor'], ['t' => date('Y-m-d H:i:s', strtotime('-100 minutes')), 'by' => 'Fire Service', 'note' => 'Responding — crew dispatched']], JSON_UNESCAPED_UNICODE);
        $st->execute(['FI-001', 'owner@krtaker.com', 'P-002', 'fire', 'high', 'Responding', 'Arif Chowdhury', date('Y-m-d H:i:s', strtotime('-2 hours')), 'Kitchen — 3rd floor flat U-004', 'Smoke reported from kitchen; gas stove left on', $tl1, '']);
        $st->execute(['FI-002', 'owner@krtaker.com', 'P-001', 'electrical', 'low', 'Resolved', 'Rahim Uddin', date('Y-m-d', strtotime('-20 days')) . ' 14:30:00', 'Parking — basement', 'Circuit breaker tripped, no fire', '[]', date('Y-m-d', strtotime('-20 days')) . ' 15:00:00']);
    }
    $epc = (int)$pdo->query('SELECT COUNT(*) FROM evacuation_plans')->fetchColumn();
    if ($epc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO evacuation_plans (id, owner_email, prop, name, assembly_point, route, last_drill, next_drill, status) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute(['EP-001', 'owner@krtaker.com', 'P-001', 'Tower A Evacuation Plan', 'South gate car park', 'Stairwell A → Ground → South gate car park', date('Y-m-d', strtotime('-190 days')), date('Y-m-d', strtotime('-10 days')), 'active']);
        $st->execute(['EP-002', 'owner@krtaker.com', 'P-002', 'Banani Tower Evacuation Plan', 'Road 11 corner', 'Stairwell B → Lobby → Road 11 corner', '', '', 'draft']);
    }
    $ecc = (int)$pdo->query('SELECT COUNT(*) FROM emergency_contacts')->fetchColumn();
    if ($ecc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO emergency_contacts (id, owner_email, prop, contact_type, name, phone, notes) VALUES (?,?,?,?,?,?,?)');
        $st->execute(['EC-001', 'owner@krtaker.com', '', 'fire', 'Fire Service & Civil Defence', '999', 'National fire emergency']);
        $st->execute(['EC-002', 'owner@krtaker.com', '', 'police', 'Police Emergency', '999', 'National police emergency']);
        $st->execute(['EC-003', 'owner@krtaker.com', '', 'ambulance', 'Ambulance — National Helpline', '199', 'National ambulance service']);
        $st->execute(['EC-004', 'owner@krtaker.com', 'P-001', 'building_manager', 'Arif Chowdhury', '01712345678', 'Building manager — on site 8am-8pm']);
        $st->execute(['EC-005', 'owner@krtaker.com', 'P-001', 'electric', 'DESCO Emergency', '16106', 'Electricity — line fault']);
    }

    /* Phase 52: demo Systems Watch rows (idempotent) */
    $sac = (int)$pdo->query('SELECT COUNT(*) FROM sys_assets')->fetchColumn();
    if ($sac === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO sys_assets (id, owner_email, prop, asset_type, location, model, serial_no, install_date, next_service, service_interval_days, cert_expiry, cert_ref, status, fuel_capacity, fuel_level, fuel_burn_rate, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute(['SA-001', 'owner@krtaker.com', 'P-002', 'generator', 'Basement — generator room', 'Perkins 100kVA', 'SN-G1', '2024-02-10', date('Y-m-d', strtotime('-5 days')), 90, '2026-09-20', 'RAJUK-LC-2026', 'operational', 500, 110, 6, 'Low fuel — refill before next load shed']);
        $st->execute(['SA-002', 'owner@krtaker.com', 'P-001', 'lift', 'Lift shaft — Tower A', 'Otis Gen2', 'SN-L1', '2023-06-01', date('Y-m-d', strtotime('+10 days')), 90, date('Y-m-d', strtotime('-15 days')), 'RAJUK-LIFT-2026', 'operational', 0, 0, 0, 'RAJUK annual certificate EXPIRED']);
        $st->execute(['SA-003', 'owner@krtaker.com', 'P-001', 'water_pump', 'Sump — basement', 'Kirloskar 5HP', 'SN-W1', '2025-01-20', date('Y-m-d', strtotime('+20 days')), 60, '', '', 'operational', 0, 0, 0, '']);
        $st->execute(['SA-004', 'owner@krtaker.com', 'P-003', 'transformer', 'Substation — ground floor', 'ABB 500kVA', 'SN-T1', '2022-11-05', date('Y-m-d', strtotime('+8 days')), 180, '2026-11-01', 'DESCO-TR-2026', 'faulty', 0, 0, 0, 'Oil leak — technician scheduled']);
        $st2 = $pdo->prepare('INSERT OR IGNORE INTO sys_services (id, owner_email, asset, service_date, service_type, technician, vendor, cost, hours_run, notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st2->execute(['SS-001', 'owner@krtaker.com', 'SA-001', date('Y-m-d', strtotime('-95 days')), 'major', 'Mehedi Hasan', 'PowerGen Services', 8500, 1750, 'Full overhaul + filter change']);
        $st2->execute(['SS-002', 'owner@krtaker.com', 'SA-002', date('Y-m-d', strtotime('-80 days')), 'amc', 'Rafiq Ahmed', 'LiftCare BD', 12000, 0, 'Monthly AMC visit']);
        $st2->execute(['SS-003', 'owner@krtaker.com', 'SA-004', date('Y-m-d', strtotime('-30 days')), 'routine', 'Jahidul Islam', 'DESCO Sub', 3000, 0, 'Oil top-up — leak reported']);
        $st3 = $pdo->prepare('INSERT OR IGNORE INTO sys_fuel (id, owner_email, asset, refill_date, liters, rate_per_litre, amount, vendor, hours_run, notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st3->execute(['SF-001', 'owner@krtaker.com', 'SA-001', date('Y-m-d', strtotime('-25 days')), 180, 112, 20160, 'Meghna Petroleum', 1800, '']);
        $st3->execute(['SF-002', 'owner@krtaker.com', 'SA-001', date('Y-m-d', strtotime('-3 days')), 210, 114, 23940, 'Padma Oil', 1840, '']);
    }

    /* Phase 53: demo Staff Watch rows (idempotent) */
    $bsc = (int)$pdo->query('SELECT COUNT(*) FROM building_staff')->fetchColumn();
    if ($bsc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO building_staff (id, owner_email, name, role, phone, join_date, monthly_salary, shift, prop, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute(['BS-001', 'owner@krtaker.com', 'Abdul Karim', 'guard', '01711000001', date('Y-m-d', strtotime('-400 days')), 12000, 'day', 'P-001', 'active', 'Main gate — day shift']);
        $st->execute(['BS-002', 'owner@krtaker.com', 'Rashida Begum', 'cleaner', '01711000002', date('Y-m-d', strtotime('-300 days')), 8000, 'day', 'P-002', 'active', 'Common areas + stairwells']);
        $st->execute(['BS-003', 'owner@krtaker.com', 'Mizanur Rahman', 'caretaker', '01711000003', date('Y-m-d', strtotime('-800 days')), 18000, 'rotating', 'P-002', 'active', 'Building caretaker — all floors']);
        $st->execute(['BS-004', 'owner@krtaker.com', 'Shafiqul Islam', 'driver', '01711000004', date('Y-m-d', strtotime('-200 days')), 15000, 'night', 'P-003', 'on_leave', 'Owner car — night duty']);
        $st2 = $pdo->prepare('INSERT OR IGNORE INTO staff_attendance (id, owner_email, staff, work_date, check_in, check_out, status, notes) VALUES (?,?,?,?,?,?,?,?)');
        $st2->execute(['AT-001', 'owner@krtaker.com', 'BS-001', date('Y-m-d', strtotime('-5 days')), '07:55', '20:05', 'present', '']);
        $st2->execute(['AT-002', 'owner@krtaker.com', 'BS-001', date('Y-m-d', strtotime('-4 days')), '07:58', '20:02', 'present', '']);
        $st2->execute(['AT-003', 'owner@krtaker.com', 'BS-001', date('Y-m-d', strtotime('-3 days')), '', '', 'absent', 'No show — no notice']);
        $st2->execute(['AT-004', 'owner@krtaker.com', 'BS-001', date('Y-m-d', strtotime('-2 days')), '', '', 'absent', 'No show']);
        $st2->execute(['AT-005', 'owner@krtaker.com', 'BS-001', date('Y-m-d', strtotime('-1 days')), '', '', 'absent', 'No show']);
        $st2->execute(['AT-006', 'owner@krtaker.com', 'BS-002', date('Y-m-d'), '08:10', '', 'present', 'On duty']);
        $st2->execute(['AT-007', 'owner@krtaker.com', 'BS-003', date('Y-m-d'), '09:40', '', 'late', 'Came 40 min late']);
        $st2->execute(['AT-008', 'owner@krtaker.com', 'BS-004', date('Y-m-d', strtotime('-1 days')), '', '', 'leave', 'Approved leave']);
        $st3 = $pdo->prepare('INSERT OR IGNORE INTO staff_payroll (id, owner_email, staff, month, salary, overtime, bonus, advance_deduction, absent_days, daily_rate, net, status, paid_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st3->execute(['PY-001', 'owner@krtaker.com', 'BS-001', date('Y-m', strtotime('-1 month')), 12000, 0, 0, 0, 0, 400, 12000, 'Draft', '']);
        $st3->execute(['PY-002', 'owner@krtaker.com', 'BS-002', date('Y-m', strtotime('-1 month')), 8000, 0, 500, 0, 0, 267, 8500, 'Paid', date('Y-m', strtotime('-1 month')) . '-28']);
        $st3->execute(['PY-003', 'owner@krtaker.com', 'BS-003', date('Y-m', strtotime('-1 month')), 18000, 0, 0, 2000, 0, 600, 16000, 'Draft', '']);
    }
    foreach (['staff_payroll_alert_days' => '7', 'staff_absent_pct' => '30'] as $ck => $cv) {
        $pdo->prepare('INSERT OR IGNORE INTO org_settings (k, v) VALUES (?,?)')->execute([$ck, $cv]);
    }




    /* Phase 54: demo Kalyan Samity rows (idempotent) */
    $smc = (int)$pdo->query('SELECT COUNT(*) FROM samity_members')->fetchColumn();
    if ($smc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO samity_members (id, owner_email, name, role, phone, since_date, status, notes) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute(['SM-001', 'owner@krtaker.com', 'Rofiqul Islam', 'Chairman', '01711000011', date('Y-m-d', strtotime('-700 days')), 'active', 'Flat 4B — owner']);
        $st->execute(['SM-002', 'owner@krtaker.com', 'Abdul Karim', 'Secretary', '01711000012', date('Y-m-d', strtotime('-700 days')), 'active', 'Flat 3A — owner']);
        $st->execute(['SM-003', 'owner@krtaker.com', 'Fatema Begum', 'Treasurer', '01711000013', date('Y-m-d', strtotime('-500 days')), 'active', 'Flat 6C — owner']);
        $st->execute(['SM-004', 'owner@krtaker.com', 'Shahin Alam', 'Member', '01711000014', date('Y-m-d', strtotime('-300 days')), 'active', 'Flat 2B — owner']);
        $st2 = $pdo->prepare('INSERT OR IGNORE INTO samity_bills (id, owner_email, unit, month, amount, due_date, status, note) VALUES (?,?,?,?,?,?,?,?)');
        $st2->execute(['SB-001', 'owner@krtaker.com', 'U-001', date('Y-m', strtotime('-1 month')), 3000, date('Y-m-05', strtotime('-1 month')), 'Paid', 'Monthly service charge']);
        $st2->execute(['SB-002', 'owner@krtaker.com', 'U-002', date('Y-m', strtotime('-1 month')), 3000, date('Y-m-05', strtotime('-1 month')), 'Partial', 'Monthly service charge']);
        $st2->execute(['SB-003', 'owner@krtaker.com', 'U-003', date('Y-m', strtotime('-1 month')), 3000, date('Y-m-05', strtotime('-1 month')), 'Pending', 'Monthly service charge — unpaid']);
        $st2->execute(['SB-004', 'owner@krtaker.com', 'U-004', date('Y-m'), 3000, date('Y-m-05'), 'Pending', 'Monthly service charge']);
        $st2->execute(['SB-005', 'owner@krtaker.com', 'U-005', date('Y-m'), 3000, date('Y-m-05'), 'Pending', 'Monthly service charge']);
        $st3 = $pdo->prepare('INSERT OR IGNORE INTO samity_collections (id, owner_email, bill, amount, method, collected_at, receipt_no, note) VALUES (?,?,?,?,?,?,?,?)');
        $st3->execute(['SC-001', 'owner@krtaker.com', 'SB-001', 3000, 'bKash', date('Y-m-03', strtotime('-1 month')), 'RCPT-SC-001', 'Full payment']);
        $st3->execute(['SC-002', 'owner@krtaker.com', 'SB-002', 1500, 'Cash', date('Y-m-08', strtotime('-1 month')), 'RCPT-SC-002', 'Partial — rest next week']);
    }
    foreach (['samity_alert_days' => '7', 'samity_default_charge' => '3000'] as $ck => $cv) {
        $pdo->prepare('INSERT OR IGNORE INTO org_settings (k, v) VALUES (?,?)')->execute([$ck, $cv]);
    }
    /* Phase 49: demo Build Watch rows (idempotent) */
    $bpc = (int)$pdo->query('SELECT COUNT(*) FROM build_projects')->fetchColumn();
    if ($bpc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO build_projects (id, owner_email, prop, title, kind, status, budget_total, start_date, target_end, contractor, architect, site_address, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute(['BP-001', 'owner@krtaker.com', 'P-002', 'Banani Duplex Renovation', 'renovation', 'In_Progress', 2000000, '2026-04-01', '2026-09-30', 'EcoBuild Engineering', 'Ar. Tanvir Islam', 'House 12, Road 11, Banani, Dhaka', 'Full interior renovation — 2 floors, owner abroad']);
        $st->execute(['BP-002', 'owner@krtaker.com', 'P-003', 'Gulshan Tower — 6th Floor Construction', 'construction', 'In_Progress', 8000000, '2026-01-15', '2026-12-20', 'EcoBuild Engineering', '', 'Gulshan Avenue, Dhaka', 'New floor slab + finishing for rental unit']);
        $st->execute(['BP-003', 'owner@krtaker.com', 'P-001', 'Bashundhara Flat Repair', 'repair', 'Completed', 150000, '2026-05-01', '2026-06-15', 'QuickFix Interiors', '', 'Block C, Bashundhara R/A', 'Bathroom + kitchen repair after tenant move-out']);
    }
    $bmc = (int)$pdo->query('SELECT COUNT(*) FROM build_milestones')->fetchColumn();
    if ($bmc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO build_milestones (id, project, title, phase, target_date, status, cost, paid, notes) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute(['BM-001', 'BP-001', 'Structure & partition walls', 'structure', '2026-05-15', 'Completed', 700000, 700000, 'Load-bearing walls + partition done']);
        $st->execute(['BM-002', 'BP-001', 'Electrical & plumbing rough-in', 'electrical', '2026-08-10', 'In_Progress', 350000, 150000, 'Wiring 70% — awaiting inspection']);
        $st->execute(['BM-003', 'BP-001', 'Flooring & finishing works', 'finishing', '2026-07-20', 'Pending', 500000, 0, 'Tile order placed — overdue']);
        $st->execute(['BM-004', 'BP-002', 'Foundation & column casting', 'foundation', '2026-03-30', 'Completed', 2500000, 2500000, 'Columns 1-6 cast & cured']);
        $st->execute(['BM-005', 'BP-002', '6th floor slab', 'structure', '2026-08-25', 'In_Progress', 3000000, 1200000, 'Steel work in progress']);
        $st->execute(['BM-006', 'BP-003', 'Bathroom & kitchen repair', 'finishing', '2026-06-10', 'Completed', 150000, 150000, 'Handed back clean']);
    }
    $bxc = (int)$pdo->query('SELECT COUNT(*) FROM build_expenses')->fetchColumn();
    if ($bxc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO build_expenses (id, project, label, amount, category, spent_on, paid, notes) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute(['BX-001', 'BP-001', 'Cement & brick', 800000, 'material', '2026-05-10', 1, 'Shah Cement — 400 bags']);
        $st->execute(['BX-002', 'BP-001', 'Mason & helper wages', 650000, 'labour', '2026-07-25', 1, '4 crews × 12 weeks']);
        $st->execute(['BX-003', 'BP-001', 'Tiles & fittings', 400000, 'material', '2026-07-30', 0, 'Imported porcelain — partial payment']);
        $st->execute(['BX-004', 'BP-002', 'Steel & concrete', 5000000, 'material', '2026-04-20', 1, 'BSRM rod + ready-mix']);
        $st->execute(['BX-005', 'BP-002', 'Site labour', 4000000, 'labour', '2026-08-01', 0, 'Daily-wage site crew — over budget']);
        $st->execute(['BX-006', 'BP-003', 'Repair materials + labour', 148000, 'material', '2026-06-12', 1, 'Sanitary + fittings + 1 crew']);
    }
    $bdc = (int)$pdo->query('SELECT COUNT(*) FROM build_media')->fetchColumn();
    if ($bdc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO build_media (id, project, kind, name, fname, size, mime, geo, captured_at, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute(['BD-001', 'BP-001', 'photo', 'Site photo — electrical rough-in', 'bmd_bp001_01.jpg', 182345, 'image/jpeg', '23.7925,90.4078', '2026-07-22 10:15:00', 'Arif Chowdhury']);
        $st->execute(['BD-002', 'BP-002', 'photo', 'Slab casting day', 'bmd_bp002_01.jpg', 241902, 'image/jpeg', '23.8103,90.4125', '2026-04-18 14:30:00', 'Arif Chowdhury']);
    }
    foreach (['build_milestone_alert_days' => '14', 'build_budget_overrun_pct' => '10', 'build_default_contractor' => 'EcoBuild Engineering'] as $ck => $cv) {
        $pdo->prepare('INSERT OR IGNORE INTO org_settings (k, v) VALUES (?,?)')->execute([$ck, $cv]);
    }

    /* SLA demo timestamps so breaches/at-risk show on live */

    $pdo->exec("UPDATE maintenance_requests SET ts=datetime('now','-20 days'), updated_at=datetime('now','-20 days') WHERE id='MR-001'");
    $pdo->exec("UPDATE maintenance_requests SET ts=datetime('now','-4 days'), updated_at=datetime('now','-4 days') WHERE id='MR-002'");
    $pdo->exec("UPDATE maintenance_requests SET ts=datetime('now','-6 days'), updated_at=datetime('now','-6 days') WHERE id='MR-003'");
    /* Phase 34: demo meter readings + utility bills (idempotent) */
    $mrc2 = (int)$pdo->query('SELECT COUNT(*) FROM meter_readings')->fetchColumn();
    if ($mrc2 === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO meter_readings (id, unit, tenant, type, reading, month, note) VALUES (?,?,?,?,?,?,?)');
        foreach ([
            ['MR-100','U-001','T-001','electric',4125,'2026-07',''],
            ['MR-101','U-001','T-001','water',185,'2026-07',''],
            ['MR-102','U-001','T-001','electric',3990,'2026-06',''],
            ['MR-103','U-004','T-003','electric',88410,'2026-07',''],
            ['MR-104','U-004','T-003','water',1210,'2026-07',''],
            ['MR-105','U-010','T-002','electric',4125,'2026-07',''],
            ['MR-106','U-010','T-002','water',185,'2026-07',''],
            ['MR-107','U-010','T-002','electric',3990,'2026-06',''],
        ] as $m) $st->execute($m);
    }
    $ubc = (int)$pdo->query('SELECT COUNT(*) FROM utility_bills')->fetchColumn();
    if ($ubc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO utility_bills (id, unit, tenant, type, month, prev_reading, curr_reading, usage, rate, standing, amount, status, paid_at, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ([
            ['UB-100','U-001','T-001','electric','2026-07',3990,4125,135,10,0,1350,'Unpaid','',''],
            ['UB-101','U-001','T-001','water','2026-07',150,185,35,20,0,700,'Paid','2026-08-01 10:00:00',''],
            ['UB-102','U-004','T-003','electric','2026-07',87950,88410,460,10,0,4600,'Unpaid','',''],
            ['UB-103','U-010','T-002','electric','2026-07',3990,4125,135,10,0,1350,'Unpaid','',''],
            ['UB-104','U-010','T-002','water','2026-07',150,185,35,20,0,700,'Paid','2026-08-01 10:00:00',''],
        ] as $b) $st->execute($b);
    }
    /* Phase 35: demo renewal requests (idempotent) */
    $rrc = (int)$pdo->query('SELECT COUNT(*) FROM renewal_requests')->fetchColumn();
    if ($rrc === 0) {
        $st = $pdo->prepare('INSERT OR IGNORE INTO renewal_requests (id, lease, tenant, months, new_rent, note, status, decided_by, decided_at) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ([
            ['RR-100','L-007','T-002',12,44000,'Please renew','Pending','',''],
            ['RR-101','L-003','T-003',24,460000,'','Approved','Shakil Ahmed','2026-07-28 11:00:00'],
            ['RR-102','L-007','T-002',12,42800,'7% escalation per Dhanmondi policy','Offered','Rofiqul Islam','2026-08-01 09:00:00'],
        ] as $r) $st->execute($r);
    }

    audit('system', 'setup', 'system', 'seed', 'Phase 2 app tables + demo data seeded');
    return ['plans' => (int)$pdo->query('SELECT COUNT(*) FROM plan_catalog')->fetchColumn(),
            'properties' => (int)$pdo->query('SELECT COUNT(*) FROM properties')->fetchColumn(),
            'app_users' => (int)$pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn()];
}

