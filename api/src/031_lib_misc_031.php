function DATA_DIR() {
    return dirname(DB_PATH) . '/files';
}
function notice_seed() {
    $pdo = db();
    $n = (int)$pdo->query('SELECT COUNT(*) FROM notices')->fetchColumn();
    if ($n === 0) {
        $seed = [
            ['NTC-001', 'Utility schedule for July', 'Electricity & gas bills will be shared by the 10th of each month. Please check the Documents tab for your copies.', 'Rofiqul Islam', 1],
            ['NTC-002', 'Annual fire-safety inspection', 'Dhaka Fire Service inspection is scheduled for 18–20 July. Access to common areas required 9am–5pm.', 'Arif Chowdhury', 1],
            ['NTC-003', 'Generator maintenance — 26 July', 'Standby generator service from 10am–2pm on 26 July. Expect brief water-pump interruptions.', 'Shakil Ahmed', 0],
        ];
        $st = $pdo->prepare('INSERT OR IGNORE INTO notices (id, title, body, author, pinned, sub_email) VALUES (?,?,?,?,?,?)');
        foreach ($seed as $r) $st->execute([$r[0], $r[1], $r[2], $r[3], $r[4], 'owner@krtaker.com']);
    }
}
function can_post_notice($u) {
    return in_array($u['role'], ['superadmin', 'owner', 'manager', 'svc_mgr', 'crm', 'legal', 'accountant', 'hr'], true);
}
function doc_scope($u) {
    /* null = all docs; otherwise list of allowed refs (lease ids for tenant, ticket ids for partner) */
    if (in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal', 'crm', 'accountant', 'hr', 'svc_mgr'], true)) return null;
    if ($u['role'] === 'tenant') {
        $sc = ai_scope($u);
        return ['kind' => 'ref', 'ids' => $sc['leases']];
    }
    if ($u['role'] === 'partner') {
        $sc = ai_scope($u);
        return ['kind' => 'ticket', 'ids' => $sc['tickets']];
    }
    return ['kind' => 'ref', 'ids' => []];
}
/* Phase 13: document vault categories + handover checklist defaults */
function DOC_CATS() {
    return [
        'utility'   => 'Utility papers & documents',
        'legal'     => 'Legal documents',
        'tax'       => 'Tax & khajna',
        'community' => 'Community / society documents',
        'agreement' => 'Agreement & lease papers',
        'other'     => 'Other',
    ];
}
function hovo_scope($u) {
    /* null = all; tenant = own lease checklists */
    if (in_array($u['role'], ['superadmin', 'owner', 'manager', 'legal', 'accountant', 'hr'], true)) return null;
    if ($u['role'] === 'tenant') {
        $sc = ai_scope($u);
        return $sc['leases'];
    }
    return [];
}
function HOVO_ITEMS($kind) {
    if ($kind === 'move_out') {
        return [
            ['id' => 'o1', 'label' => 'Keys returned (all sets)', 'checked' => 0, 'note' => ''],
            ['id' => 'o2', 'label' => 'Electric / gas / water meter readings re-taken', 'checked' => 0, 'note' => ''],
            ['id' => 'o3', 'label' => 'All dues cleared (rent, service, utility recovery)', 'checked' => 0, 'note' => ''],
            ['id' => 'o4', 'label' => 'Advance/security deposit refund processed (receipt issued)', 'checked' => 0, 'note' => ''],
            ['id' => 'o5', 'label' => 'Flat condition walkthrough done (photos)', 'checked' => 0, 'note' => ''],
            ['id' => 'o6', 'label' => 'Furniture/inventory verified against move-in list', 'checked' => 0, 'note' => ''],
            ['id' => 'o7', 'label' => 'Utilities transferred out of tenant name', 'checked' => 0, 'note' => ''],
            ['id' => 'o8', 'label' => 'Society/RWA clearance + NOC collected', 'checked' => 0, 'note' => ''],
            ['id' => 'o9', 'label' => 'Parking spot released', 'checked' => 0, 'note' => ''],
            ['id' => 'o10', 'label' => 'Agreement formally terminated (lease record updated)', 'checked' => 0, 'note' => ''],
        ];
    }
    return [
        ['id' => 'i1', 'label' => 'Agreement signed & registration gate checked (TPA §107)', 'checked' => 0, 'note' => ''],
        ['id' => 'i2', 'label' => 'Advance/security deposit received — receipt issued', 'checked' => 0, 'note' => ''],
        ['id' => 'i3', 'label' => 'Keys handed over (all sets)', 'checked' => 0, 'note' => ''],
        ['id' => 'i4', 'label' => 'Electric meter reading recorded', 'checked' => 0, 'note' => ''],
        ['id' => 'i5', 'label' => 'Gas meter reading recorded', 'checked' => 0, 'note' => ''],
        ['id' => 'i6', 'label' => 'Water meter reading recorded', 'checked' => 0, 'note' => ''],
        ['id' => 'i7', 'label' => 'Utility bills transferred to tenant name', 'checked' => 0, 'note' => ''],
        ['id' => 'i8', 'label' => 'Society/RWA NOC + contact shared', 'checked' => 0, 'note' => ''],
        ['id' => 'i9', 'label' => 'Parking spot assigned (if applicable)', 'checked' => 0, 'note' => ''],
        ['id' => 'i10', 'label' => 'Furniture/inventory verified (photos)', 'checked' => 0, 'note' => ''],
        ['id' => 'i11', 'label' => 'e-TIN/NID + emergency contact collected', 'checked' => 0, 'note' => ''],
        ['id' => 'i12', 'label' => 'Flat condition walkthrough done (photos)', 'checked' => 0, 'note' => ''],
    ];
}
/* Phase 14: tenant portal v2 — aggregate (leases + countdown + payment history + docs + handover) */
function portal_data($pdo, $tid) {
    $st = $pdo->prepare('SELECT * FROM tenants WHERE id=?'); $st->execute([$tid]);
    $tenant = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) return null;
    $st = $pdo->prepare('SELECT * FROM leases WHERE t=? ORDER BY start'); $st->execute([$tid]);
    $leases = $st->fetchAll(PDO::FETCH_ASSOC);
    $leaseIds = array_column($leases, 'id');
    $unitIds = array_column($leases, 'u');
    $today = strtotime(date('Y-m-d'));
    $enriched = [];
    foreach ($leases as $l) {
        $urow = null; $prow = null;
        if ($l['u']) { $st = $pdo->prepare('SELECT * FROM units WHERE id=?'); $st->execute([$l['u']]); $urow = $st->fetch(PDO::FETCH_ASSOC); }
        if ($urow && $urow['p']) { $st = $pdo->prepare('SELECT * FROM properties WHERE id=?'); $st->execute([$urow['p']]); $prow = $st->fetch(PDO::FETCH_ASSOC); }
        $total = 0; $left = 0; $pct = 0;
        if ($l['start'] && $l['end']) {
            $total = max(1, (int)round((strtotime($l['end']) - strtotime($l['start'])) / 86400));
            $left = (int)floor((strtotime($l['end']) - $today) / 86400);
            $pct = (int)round((1 - $left / $total) * 100);
            if ($pct < 0) $pct = 0; if ($pct > 100) $pct = 100;
        }
        $res = ((int)($l['res'] ?? 1)) === 1;
        $enriched[] = [
            'id' => $l['id'], 'start' => $l['start'], 'end' => $l['end'],
            'rent' => (int)$l['rent'], 'adv' => (int)$l['adv'], 'status' => $l['status'], 'res' => $res,
            'unit' => $urow ? ['id' => $urow['id'], 'name' => $urow['name'], 'floor' => $urow['floor']] : null,
            'property' => $prow ? ['id' => $prow['id'], 'name' => $prow['name'], 'jur' => $prow['jur'], 'holding' => $prow['holding']] : null,
            'days_left' => $left, 'total_days' => (int)$total, 'pct' => $pct,
            'reg_pending' => (!$res && empty($l['reg_office'])),
        ];
    }
    $invoices = []; $invIds = [];
    if ($leaseIds) {
        $st = $pdo->prepare('SELECT * FROM invoices WHERE l IN (' . ai_in_list($leaseIds) . ') ORDER BY m DESC');
        $st->execute($leaseIds);
        $invoices = $st->fetchAll(PDO::FETCH_ASSOC);
        $invIds = array_column($invoices, 'id');
    }
    $payments = []; $receipts = [];
    if ($invIds) {
        $st = $pdo->prepare('SELECT * FROM payments WHERE inv IN (' . ai_in_list($invIds) . ") AND status='Success' ORDER BY date DESC, id DESC");
        $st->execute($invIds);
        $payments = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare('SELECT * FROM receipts WHERE inv IN (' . ai_in_list($invIds) . ') ORDER BY date DESC, id DESC');
        $st->execute($invIds);
        $receipts = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $paidByInv = [];
    foreach ($payments as $p) $paidByInv[$p['inv']] = ($paidByInv[$p['inv']] ?? 0) + (int)$p['amount'];
    foreach ($invoices as &$iv) {
        $iv['paid'] = $paidByInv[$iv['id']] ?? 0;
        $iv['due'] = max(0, (int)$iv['net'] - $iv['paid']);
    }
    unset($iv);
    $docs = []; $pIds = [];
    if ($unitIds) {
        $st = $pdo->prepare('SELECT DISTINCT p FROM units WHERE id IN (' . ai_in_list($unitIds) . ')');
        $st->execute($unitIds);
        $pIds = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'p');
    }
    $conds = []; $args = [];
    if ($leaseIds) { $conds[] = 'ref IN (' . ai_in_list($leaseIds) . ')'; $args = array_merge($args, $leaseIds); }
    if ($pIds) { $conds[] = 'p IN (' . ai_in_list($pIds) . ')'; $args = array_merge($args, $pIds); }
    if ($conds) {
        $st = $pdo->prepare('SELECT * FROM documents WHERE ' . implode(' OR ', $conds) . ' ORDER BY ts DESC');
        $st->execute($args);
        $docs = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $handover = [];
    if ($leaseIds) {
        $st = $pdo->prepare('SELECT id, lease, kind, status, ts, updated_at FROM handover_checklists WHERE lease IN (' . ai_in_list($leaseIds) . ') ORDER BY updated_at DESC');
        $st->execute($leaseIds);
        $handover = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $tickets = [];
    if ($unitIds) {
        $st = $pdo->prepare('SELECT id, desc, status, reported, liab FROM tickets WHERE u IN (' . ai_in_list($unitIds) . ') ORDER BY reported DESC');
        $st->execute($unitIds);
        $tickets = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $notices = $pdo->query('SELECT id, title, body, ts FROM notices ORDER BY pinned DESC, ts DESC LIMIT 3')->fetchAll(PDO::FETCH_ASSOC);
    /* Phase 16: lease renewal requests + utility meter readings (tenant scoped) */
    $renewals = [];
    if ($leaseIds) {
        $st = $pdo->prepare('SELECT * FROM renewal_requests WHERE lease IN (' . ai_in_list($leaseIds) . ') ORDER BY ts DESC');
        $st->execute($leaseIds);
        $renewals = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $meters = [];
    if ($unitIds) {
        $st = $pdo->prepare('SELECT * FROM meter_readings WHERE unit IN (' . ai_in_list($unitIds) . ') ORDER BY month DESC, type');
        $st->execute($unitIds);
        $meters = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $utilityBills = [];
    if ($unitIds) {
        $st = $pdo->prepare('SELECT * FROM utility_bills WHERE unit IN (' . ai_in_list($unitIds) . ") AND status!='Void' ORDER BY month DESC, type");
        $st->execute($unitIds);
        $utilityBills = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $outstanding = array_sum(array_map(fn($i) => $i['due'], $invoices));
    $paidTotal = array_sum(array_column($payments, 'amount'));
    $next = null;
    foreach ($invoices as $iv) { if ($iv['status'] !== 'Paid' && $iv['due'] > 0) { $next = $iv; break; } }
    $minLeft = null;
    foreach ($enriched as $l) { if ($l['status'] === 'Active' || $l['status'] === 'Pending Registration') { $minLeft = $minLeft === null ? $l['days_left'] : min($minLeft, $l['days_left']); } }
    $stats = [
        'outstanding' => (int)$outstanding,
        'paid_total' => (int)$paidTotal,
        'next_due' => $next ? ['id' => $next['id'], 'm' => $next['m'], 'amount' => (int)$next['due']] : null,
        'tickets_open' => count(array_filter($tickets, fn($t) => $t['status'] !== 'Closed')),
        'min_days_left' => $minLeft,
    ];
    /* Phase 21: settlement summary (non-persisting) */
    $settle = null;
    $sr = settlement_report($pdo, $tid, null, []);
    if ($sr && !isset($sr['error'])) {
        $settle = ['status' => $sr['status'], 'certificate_eligible' => $sr['certificate_eligible'],
                   'total_due' => $sr['totals']['total_due'], 'balance' => $sr['totals']['balance'],
                   'refund' => $sr['totals']['refund'], 'deposit' => $sr['deposit'],
                   'rent' => $sr['totals']['rent'], 'utility' => $sr['totals']['utility'], 'damages' => $sr['totals']['damages']];
    }
    return [
        'tenant' => ['id' => $tenant['id'], 'name' => $tenant['name'], 'email' => $tenant['sub_email'], 'phone' => $tenant['phone'], 'kind' => $tenant['kind'], 'nid' => $tenant['nid']],
        'leases' => $enriched, 'invoices' => $invoices, 'payments' => $payments,
        'receipts' => $receipts, 'docs' => $docs, 'handover' => $handover,
        'tickets' => $tickets, 'notices' => $notices, 'stats' => $stats,
        'renewals' => $renewals, 'meters' => $meters, 'utility_bills' => $utilityBills,
        'settlement' => $settle,
        'score' => tenant_scorecard($pdo, $tid),
    ];
}
/* ── Phase 17: tenant report card (renter risk score + landlord vetting) ── */
function score_band($s) {
    if ($s >= 80) return ['Excellent', 'b-green'];
    if ($s >= 60) return ['Good', 'b-blue'];
    if ($s >= 40) return ['Fair', 'b-orange'];
    return ['Risky', 'b-red'];
}
function tenant_scorecard($pdo, $tid) {
    $st = $pdo->prepare('SELECT * FROM tenants WHERE id=?'); $st->execute([$tid]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) return null;
    $st = $pdo->prepare('SELECT * FROM leases WHERE t=?'); $st->execute([$tid]);
    $leases = $st->fetchAll(PDO::FETCH_ASSOC);
    $leaseIds = array_column($leases, 'id');
    $unitIds = array_column($leases, 'u');

    $invoices = [];
    if ($leaseIds) {
        $st = $pdo->prepare('SELECT * FROM invoices WHERE l IN (' . ai_in_list($leaseIds) . ')');
        $st->execute($leaseIds);
        $invoices = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $invIds = array_column($invoices, 'id');
    $payments = [];
    if ($invIds) {
        $st = $pdo->prepare('SELECT * FROM payments WHERE inv IN (' . ai_in_list($invIds) . ") AND status='Success'");
        $st->execute($invIds);
        $payments = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /* payment factor (40%) — settled invoices + punctuality */
    $paidByInv = [];
    foreach ($payments as $p) $paidByInv[$p['inv']] = ($paidByInv[$p['inv']] ?? 0) + (int)$p['amount'];
    $paidCount = 0; $overdueCount = 0;
    foreach ($invoices as $iv) {
        $paid = $paidByInv[$iv['id']] ?? 0;
        if ($iv['status'] === 'Paid' || $paid >= (int)$iv['net']) $paidCount++;
        elseif ($iv['m'] < date('Y-m')) $overdueCount++;
    }
    $totalInv = count($invoices);
    $paidRatio = $totalInv ? $paidCount / $totalInv : 0.5;
    $onTime = 0; $lateSum = 0; $payN = count($payments);
    foreach ($payments as $p) {
        $inv = null;
        foreach ($invoices as $iv) if ($iv['id'] === $p['inv']) { $inv = $iv; break; }
        if (!$inv) continue;
        $daysLate = (int)floor((strtotime($p['date']) - strtotime($inv['m'] . '-01')) / 86400) - 3; /* 3-day grace */
        if ($daysLate <= 0) $onTime++; else $lateSum += $daysLate;
    }
    $onTimeRate = $payN ? $onTime / $payN : 0.5;
    $avgLate = $payN ? (int)round($lateSum / $payN) : 0;
    $payment = (int)max(0, min(100, round(100 * $paidRatio - min(25, $avgLate) - min(25, $overdueCount * 10))));

    /* stability factor (20%) — tenure + approved renewals */
    $tenure = 0; $hasActive = false;
    foreach ($leases as $l) {
        if (in_array($l['status'], ['Active', 'Pending Registration'], true)) $hasActive = true;
        if ($l['start'] && $l['end']) {
            $mo = (int)floor((strtotime($l['end']) - strtotime($l['start'])) / 86400 / 30.44);
            if ($mo > $tenure) $tenure = min(24, $mo);
        }
    }
    $renewals = 0;
    if ($leaseIds) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM renewal_requests WHERE lease IN (" . ai_in_list($leaseIds) . ") AND status='Approved'");
        $st->execute($leaseIds);
        $renewals = (int)$st->fetchColumn();
    }
    $stability = (int)max(0, min(100, round($tenure * 4 + $renewals * 10 + ($hasActive ? 0 : -20))));

    /* care factor (20%) — tenant-liability tickets resolved + meter discipline */
    $tenantTickets = [];
    if ($unitIds) {
        $st = $pdo->prepare("SELECT * FROM tickets WHERE u IN (" . ai_in_list($unitIds) . ") AND liab='Tenant'");
        $st->execute($unitIds);
        $tenantTickets = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $closedT = count(array_filter($tenantTickets, fn($x) => $x['status'] === 'Closed'));
    $totalT = count($tenantTickets);
    $resolved = $totalT ? $closedT / $totalT : 0.5;
    $meters = 0;
    if ($unitIds) {
        $st = $pdo->prepare('SELECT COUNT(DISTINCT unit || type || month) FROM meter_readings WHERE unit IN (' . ai_in_list($unitIds) . ')');
        $st->execute($unitIds);
        $meters = (int)$st->fetchColumn();
    }
    $care = (int)max(0, min(100, round(50 + 50 * $resolved + min(30, $meters * 2))));

    /* compliance factor (20%) — handover, registration, documents */
    $comp = 50; $handoverDone = false;
    if ($leaseIds) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM handover_checklists WHERE lease IN (" . ai_in_list($leaseIds) . ") AND status='Completed'");
        $st->execute($leaseIds);
        if ((int)$st->fetchColumn() > 0) { $handoverDone = true; $comp += 20; }
    }
    $regOk = true;
    foreach ($leases as $l) if ((int)$l['res'] === 0 && empty($l['reg_office'])) $regOk = false;
    $comp += $regOk ? 20 : -20;
    $propIds = [];
    if ($unitIds) {
        $st = $pdo->prepare('SELECT DISTINCT p FROM units WHERE id IN (' . ai_in_list($unitIds) . ')');
        $st->execute($unitIds);
        $propIds = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'p');
    }
    $docN = 0;
    if ($leaseIds || $propIds) {
        $conds = []; $args = [];
        if ($leaseIds) { $conds[] = 'ref IN (' . ai_in_list($leaseIds) . ')'; $args = array_merge($args, $leaseIds); }
        if ($propIds) { $conds[] = 'p IN (' . ai_in_list($propIds) . ')'; $args = array_merge($args, $propIds); }
        $st = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE ' . implode(' OR ', $conds));
        $st->execute($args);
        $docN = (int)$st->fetchColumn();
    }
    if ($docN > 0) $comp += 10;
    $compliance = (int)max(0, min(100, $comp));

    $score = (int)round(0.40 * $payment + 0.20 * $stability + 0.20 * $care + 0.20 * $compliance);
    list($band, $bandColor) = score_band($score);

    $tips = [];
    if ($payment < 60) $tips[] = 'Pay invoices on or before the 1st — payment history is the heaviest factor (' . (int)round($onTimeRate * 100) . '% on-time).';
    if ($overdueCount > 0) $tips[] = 'Clear ' . $overdueCount . ' overdue invoice(s) to lift your payment factor.';
    if ($stability < 50) $tips[] = 'A longer lease term and an approved renewal improve your stability factor.';
    if ($care < 60 && $totalT > 0) $tips[] = 'Resolve ' . ($totalT - $closedT) . ' open maintenance ticket(s) to raise your care factor.';
    if ($meters === 0) $tips[] = 'Submit monthly meter readings — consistent readings boost your care factor.';
    if (!$regOk) $tips[] = 'Complete registration for your non-residential lease (TPA §107).';
    if ($comp < 60) $tips[] = 'Complete the handover checklist and upload documents to lift compliance.';
    if (!$tips) $tips[] = 'Excellent standing — keep it up!';

    return [
        'tenant' => ['id' => $t['id'], 'name' => $t['name'], 'kind' => $t['kind'], 'nrb' => (int)$t['nrb'], 'email' => $t['sub_email']],
        'score' => $score, 'band' => $band, 'band_color' => $bandColor,
        'factors' => ['payment' => $payment, 'stability' => $stability, 'care' => $care, 'compliance' => $compliance],
        'stats' => [
            'on_time_rate' => (int)round($onTimeRate * 100), 'avg_days_late' => $avgLate,
            'unpaid' => $totalInv - $paidCount, 'overdue' => $overdueCount,
            'tenure_months' => $tenure, 'renewals' => $renewals,
            'tickets_open' => $totalT - $closedT, 'tickets_total' => $totalT,
            'meter_months' => $meters, 'handover_done' => $handoverDone, 'reg_ok' => $regOk, 'docs' => $docN,
        ],
        'tips' => $tips,
    ];
}
function vetting_report_html($sc) {
    $t = $sc['tenant'];
    $cols = ['Excellent' => '#059669', 'Good' => '#2F80ED', 'Fair' => '#D97706', 'Risky' => '#B91C1C'];
    $col = $cols[$sc['band']] ?? '#8A94A6';
    $ring = '<svg width="120" height="120" viewBox="0 0 120 120"><circle cx="60" cy="60" r="50" fill="none" stroke="#E4EAF3" stroke-width="12"/><circle cx="60" cy="60" r="50" fill="none" stroke="' . $col . '" stroke-width="12" stroke-linecap="round" stroke-dasharray="' . round(2 * 3.14159 * 50) . '" stroke-dashoffset="' . round(2 * 3.14159 * 50 * (1 - $sc['score'] / 100)) . '" transform="rotate(-90 60 60)"/><text x="60" y="66" text-anchor="middle" font-size="30" font-weight="800" fill="' . $col . '">' . $sc['score'] . '</text></svg>';
    $bars = '';
    $fac = [['Payment', 'payment', 40], ['Stability', 'stability', 20], ['Care', 'care', 20], ['Compliance', 'compliance', 20]];
    foreach ($fac as $f) {
        $v = $sc['factors'][$f[1]];
        $bars .= '<div style="margin:10px 0"><div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px"><b>' . $f[0] . ' (' . $f[2] . '%)</b><b>' . $v . '</b></div><div style="background:#E4EAF3;border-radius:6px;height:8px"><div style="background:' . $col . ';height:8px;border-radius:6px;width:' . $v . '%"></div></div></div>';
    }
    $stats = $sc['stats'];
    $kv = function ($k, $v) { return '<tr><td style="padding:6px 10px;border-bottom:1px solid #E4EAF3;color:#8A94A6;font-size:13px">' . $k . '</td><td style="padding:6px 10px;border-bottom:1px solid #E4EAF3;text-align:right;font-weight:600">' . $v . '</td></tr>'; };
    $tips = '<ul style="padding-left:18px;margin:8px 0 0;color:#475467;font-size:13.5px;line-height:1.7">';
    foreach ($sc['tips'] as $x) $tips .= '<li>' . $x . '</li>';
    $tips .= '</ul>';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Tenant Vetting Report — ' . esc($t['name']) . '</title><style>body{font-family:Inter,"Hind Siliguri",Arial,sans-serif;background:#F4F6FA;margin:0;padding:32px;color:#101828}.page{max-width:760px;margin:0 auto;background:#fff;border:1px solid #E4EAF3;border-radius:16px;padding:40px}h1{font-size:20px;margin:0 0 4px}.muted{color:#8A94A6;font-size:13px}.head{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #101828;padding-bottom:14px;margin-bottom:22px}.band{display:inline-block;padding:4px 12px;border-radius:999px;color:#fff;background:' . $col . ';font-size:13px;font-weight:700}.flex{display:flex;gap:24px;align-items:center;flex-wrap:wrap;margin:18px 0}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:0 30px}@media(max-width:640px){.grid2{grid-template-columns:1fr}}table{width:100%;border-collapse:collapse;margin-top:8px}.foot{margin-top:26px;padding-top:14px;border-top:1px solid #E4EAF3;color:#98A2B3;font-size:12px;display:flex;justify-content:space-between}</style></head><body><div class="page">'
        . '<div class="head"><div><h1>🔑 KRTaker — Tenant Vetting Report</h1><div class="muted">Prepared ' . date('d M Y') . ' · ' . esc($t['kind']) . ($t['nrb'] ? ' · NRB' : '') . '</div></div><span class="band">' . $sc['band'] . '</span></div>'
        . '<div style="font-size:15px;font-weight:700">' . esc($t['name']) . '</div><div class="muted">' . esc($t['email']) . '</div>'
        . '<div class="flex">' . $ring . '<div style="flex:1;min-width:280px">' . $bars . '</div></div>'
        . '<h2 style="font-size:15px;margin:22px 0 4px">Key indicators</h2>'
        . '<table>' . $kv('On-time payment rate', $stats['on_time_rate'] . '%') . $kv('Avg days late (past grace)', $stats['avg_days_late']) . $kv('Unpaid invoices', $stats['unpaid']) . $kv('Overdue invoices', $stats['overdue']) . $kv('Lease tenure', $stats['tenure_months'] . ' months') . $kv('Renewals approved', $stats['renewals']) . $kv('Open maintenance (tenant)', $stats['tickets_open'] . ' / ' . $stats['tickets_total']) . $kv('Meter readings (months)', $stats['meter_months']) . $kv('Handover completed', $stats['handover_done'] ? 'Yes' : 'No') . $kv('Registration (TPA §107)', $stats['reg_ok'] ? 'OK' : 'Needs attention') . $kv('Documents on file', $stats['docs']) . '</table>'
        . '<h2 style="font-size:15px;margin:22px 0 4px">How to improve</h2>' . $tips
        . '<div class="foot"><span>KRTaker · krtaker.com</span><span>Report is a risk indicator, not a legal record</span></div>'
        . '</div></body></html>';
}
/* Phase 15: rent reminder escalation (day 1 → 7 → 15) */
function REMINDER_DEFAULT_CONFIG() {
    return [
        'enabled' => true,
        'late_fee' => 'A late fee may apply per your tenancy agreement — please settle to avoid it.',
        'tiers' => [
            '1' => ['label' => 'Day 1 · gentle', 'min_days' => 0, 'max_days' => 6, 'note' => 'your rent for {{month}} is now due. A gentle nudge — please pay at your convenience from the tenant portal.'],
            '2' => ['label' => 'Day 7 · follow-up', 'min_days' => 7, 'max_days' => 13, 'note' => 'your rent for {{month}} is overdue. Please settle now to keep your account current.'],
            '3' => ['label' => 'Day 15 · final', 'min_days' => 14, 'max_days' => 999, 'note' => 'final notice for {{month}}. If this stays unpaid it may lead to a formal notice under the PRCA 1991.'],
        ],
    ];
}
