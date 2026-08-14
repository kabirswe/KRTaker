function rent_due_push_data($pdo, $lookahead = 1) {
    $cur = gmdate('Y-m');
    $monthSet = [];
    for ($i = 1; $i <= max(0, $lookahead); $i++) $monthSet[date('Y-m', strtotime($cur . ' +' . $i . ' months'))] = true;
    $owners = $pdo->query('SELECT DISTINCT sub_email FROM push_subs')->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($owners as $em) {
        if (!$em) continue;
        $st = $pdo->prepare("SELECT i.id AS inv, i.m, i.net,
                COALESCE((SELECT SUM(amount) FROM payments WHERE inv=i.id AND status='Success'),0) AS paid,
                t.name AS tname, u.name AS uname, p.name AS pname
            FROM invoices i
            JOIN leases l ON l.id=i.l
            JOIN units u ON u.id=l.u
            JOIN properties p ON p.id=u.p
            JOIN tenants t ON t.id=l.t
            WHERE p.sub_email=? AND i.status!='Paid'");
        $st->execute([$em]);
        $o = ['email' => $em, 'overdue' => 0, 'due_soon' => 0, 'upcoming' => 0, 'invoices' => 0, 'items' => []];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $due = (int)$r['net'] - (int)$r['paid'];
            if ($due <= 0) continue;
            $it = ['inv' => $r['inv'], 'm' => $r['m'], 'due' => $due, 'tenant' => $r['tname'], 'unit' => $r['uname'], 'property' => $r['pname']];
            if ($r['m'] < $cur) { $o['overdue'] += $due; $it['bucket'] = 'overdue'; }
            elseif ($r['m'] === $cur) { $o['due_soon'] += $due; $it['bucket'] = 'due_soon'; }
            elseif (isset($monthSet[$r['m']])) { $o['upcoming'] += $due; $it['bucket'] = 'upcoming'; }
            else continue; /* outside the window — don't count */
            $o['invoices']++;
            $o['items'][] = $it;
        }
        if ($o['invoices'] > 0) $out[] = $o;
    }
    return $out;
}

/* ════════════════════════════════════════════════════════════════
   Phase 12: document + email templates (customizable, merge fields,
   wireframe field-linking to source records)
   ════════════════════════════════════════════════════════════════ */
