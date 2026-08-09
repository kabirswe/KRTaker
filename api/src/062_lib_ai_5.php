function kr_alert_scan($pdo) {
    /* SLA breaches + at-risk from maintenance */
    $sla = sla_summary($pdo);
    $created = 0;
    /* fan out to all active owners (subscribers) so each gets their own alert queue */
    $owners = [];
    $ost = $pdo->query("SELECT id FROM subscribers WHERE status='active' ORDER BY id");
    foreach ($ost->fetchAll(PDO::FETCH_ASSOC) as $o) $owners[] = 'sub:' . $o['id'];
    if (!$owners) $owners[] = 'sub:1';
    foreach ($sla['items'] as $it) {
        if ($it['status'] !== 'breached' && $it['status'] !== 'at_risk') continue;
        $sev = $it['status'] === 'breached' ? 'critical' : 'warning';
        $label = $it['status'] === 'breached' ? 'SLA breached' : 'SLA at risk';
        $body = $it['id'] . ' · ' . $it['title'] . ' · ' . $it['priority'] . ' priority · elapsed ' . $it['elapsed_hours'] . 'h';
        foreach ($owners as $ukOwner) if (kr_alert_upsert($pdo, $ukOwner, 'sla', $sev, $label, $body, $it['id']) !== null) $created++;
    }
    /* compliance expiries within 45 days */
    $today = date('Y-m-d');
    foreach ($pdo->query('SELECT * FROM compliance_items WHERE expiry_date>=\'\' ORDER BY expiry_date')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (!$c['expiry_date'] || $c['expiry_date'] > date('Y-m-d', strtotime('+45 days'))) continue;
        $sev = $c['expiry_date'] < $today ? 'critical' : 'warning';
        $label = $c['expiry_date'] < $today ? 'Compliance expired' : 'Compliance expiring';
        foreach ($owners as $ukOwner) if (kr_alert_upsert($pdo, $ukOwner, 'compliance', $sev, $label, $c['label'] . ' · ' . $c['entity_id'] . ' · ' . $c['expiry_date'], $c['id']) !== null) $created++;
    }
    /* arrears — unpaid invoices older than 7 days */
    $st = $pdo->query("SELECT i.id, i.l, i.m, i.net, l.t FROM invoices i JOIN leases l ON l.id=i.l WHERE i.status!='Paid' AND i.m < date('now','-7 days')");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $tst = $pdo->prepare('SELECT name FROM tenants WHERE id=?'); $tst->execute([$i['t']]);
        $tname = (string)$tst->fetchColumn();
        foreach ($owners as $ukOwner) if (kr_alert_upsert($pdo, $ukOwner, 'arrears', 'critical', 'Rent arrears', $i['id'] . ' · ' . $tname . ' · ' . $i['m'] . ' · ৳' . number_format($i['net']), $i['id']) !== null) $created++;
    }
    /* renewals within 60 days */
    $st = $pdo->query("SELECT l.id, l.end, l.rent, t.name AS tname, u.name AS uname FROM leases l JOIN tenants t ON t.id=l.t JOIN units u ON u.id=l.u WHERE l.status='Active' AND l.end <= date('now','+60 days') AND l.end >= date('now')");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        foreach ($owners as $ukOwner) if (kr_alert_upsert($pdo, $ukOwner, 'renewal', 'warning', 'Lease renewal due', $l['id'] . ' · ' . $l['tname'] . ' · ' . $l['uname'] . ' · ends ' . $l['end'], $l['id']) !== null) $created++;
    }
    /* land guard — encroached parcels, overdue/due-soon visits (NRB owner parcels only) */
    $landRows = $pdo->query('SELECT * FROM land_parcels ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($landRows as $lpRow) {
        if (trim($lpRow['owner_email'] ?? '') === '') continue;
        $ost = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $ost->execute([$lpRow['owner_email']]);
        $subId = $ost->fetchColumn();
        if (!$subId) continue;
        $e = land_enrich($pdo, [$lpRow])[0];
        if ($e['status'] === 'Encroached') {
            foreach (['sub:' . $subId] as $ukOwner) if (kr_alert_upsert($pdo, $ukOwner, 'land', 'critical', 'Encroachment detected', $e['name'] . ' (' . $e['id'] . ') — verify and take action', $e['id']) !== null) $created++;
        } elseif ($e['due_days'] !== null && $e['due_days'] < 0) {
            foreach (['sub:' . $subId] as $ukOwner) if (kr_alert_upsert($pdo, $ukOwner, 'land', 'warning', 'Land visit overdue', $e['name'] . ' (' . $e['id'] . ') — monitoring visit ' . abs($e['due_days']) . 'd overdue', $e['id']) !== null) $created++;
        } elseif ($e['due_days'] !== null && $e['due_days'] <= 14) {
            foreach (['sub:' . $subId] as $ukOwner) if (kr_alert_upsert($pdo, $ukOwner, 'land', 'info', 'Land visit due soon', $e['name'] . ' (' . $e['id'] . ') — next visit in ' . $e['due_days'] . 'd', $e['id']) !== null) $created++;
        }
    }

    /* nrb hub — vacancy awaiting approval >2d, vacant 30d+, disputes open 14d+ (NRB owners) */
    $vacRows = $pdo->query('SELECT * FROM nrb_vacancies ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($vacRows as $vcRow) {
        if (trim($vcRow['owner_email'] ?? '') === '') continue;
        $ost = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $ost->execute([$vcRow['owner_email']]);
        $subId = $ost->fetchColumn();
        if (!$subId) continue;
        $ukOwner = 'sub:' . $subId;
        if ($vcRow['status'] === 'Awaiting_Approval' && $vcRow['updated_at'] && strtotime($vcRow['updated_at']) < strtotime('-2 days')) {
            if (kr_alert_upsert($pdo, $ukOwner, 'nrb', 'warning', 'Vacancy awaiting your approval', $vcRow['id'] . ' — ' . $vcRow['unit'] . ' · approve to sign the lease', $vcRow['id']) !== null) $created++;
        } elseif (in_array($vcRow['status'], ['Vacant', 'Marketing', 'Showing', 'Vetting'], true) && $vcRow['ts'] && strtotime($vcRow['ts']) < strtotime('-30 days')) {
            if (kr_alert_upsert($pdo, $ukOwner, 'nrb', 'info', 'Vacancy needs attention', $vcRow['id'] . ' — empty 30+ days · start marketing', $vcRow['id']) !== null) $created++;
        }
    }
    $dRows = $pdo->query('SELECT * FROM nrb_disputes ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dRows as $dRow) {
        if (trim($dRow['owner_email'] ?? '') === '') continue;
        $ost = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $ost->execute([$dRow['owner_email']]);
        $subId = $ost->fetchColumn();
        if (!$subId) continue;
        if (!in_array($dRow['status'], ['Resolved', 'Closed'], true) && $dRow['ts'] && strtotime($dRow['ts']) < strtotime('-14 days')) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'nrb', 'warning', 'Dispute open 14+ days', $dRow['id'] . ' — ' . $dRow['dtype'] . ' · ' . $dRow['tenant'], $dRow['id']) !== null) $created++;
        }
    }
    /* legal concierge — requests stuck / fees pending / holding taxes due (owners) */
    $crAll = $pdo->query('SELECT * FROM concierge_requests ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    $ccfg = concierge_config_rows($pdo);
    foreach ($crAll as $crRow) {
        if (trim($crRow['owner_email'] ?? '') === '') continue;
        $ost = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $ost->execute([$crRow['owner_email']]);
        $subId = $ost->fetchColumn();
        if (!$subId) continue;
        $ukOwner = 'sub:' . $subId;
        if ($crRow['status'] === 'Awaiting_Fee' && $crRow['fee_status'] !== 'paid' && $crRow['awaiting_fee_at'] && strtotime($crRow['awaiting_fee_at']) < strtotime('-7 days')) {
            if (kr_alert_upsert($pdo, $ukOwner, 'concierge', 'warning', 'Concierge fee pending', $crRow['id'] . ' — ' . str_replace('_', ' ', $crRow['service']) . ' · ৳' . number_format($crRow['fee']) . ' · mark fee paid to continue', $crRow['id']) !== null) $created++;
        } elseif (in_array($crRow['status'], ['Under_Review', 'Docs_Requested', 'In_Progress'], true) && $crRow['updated_at'] && strtotime($crRow['updated_at']) < strtotime('-' . (int)$ccfg['docs_stuck_days'] . ' days')) {
            if (kr_alert_upsert($pdo, $ukOwner, 'concierge', 'info', 'Concierge request in progress', $crRow['id'] . ' — ' . str_replace('_', ' ', $crRow['service']) . ' · no update in ' . (int)$ccfg['docs_stuck_days'] . 'd', $crRow['id']) !== null) $created++;
        }
    }
    $htAll = $pdo->query('SELECT * FROM holding_taxes ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($htAll as $htRow) {
        if (trim($htRow['owner_email'] ?? '') === '') continue;
        if ($htRow['status'] === 'Paid') continue;
        $ost = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $ost->execute([$htRow['owner_email']]);
        $subId = $ost->fetchColumn();
        if (!$subId) continue;
        $ukOwner = 'sub:' . $subId;
        if ($htRow['due_date'] && $htRow['due_date'] < date('Y-m-d')) {
            if (kr_alert_upsert($pdo, $ukOwner, 'concierge', 'warning', 'Holding tax overdue', $htRow['id'] . ' — ' . ($htRow['city_corp'] ?: 'City Corp') . ' · ' . $htRow['holding_no'] . ' · ৳' . number_format($htRow['tax_amount']), $htRow['id']) !== null) $created++;
        } elseif ($htRow['due_date'] && $htRow['due_date'] <= date('Y-m-d', strtotime('+' . (int)$ccfg['holding_alert_days'] . ' days'))) {
            if (kr_alert_upsert($pdo, $ukOwner, 'concierge', 'info', 'Holding tax due soon', $htRow['id'] . ' — ' . ($htRow['city_corp'] ?: 'City Corp') . ' · ' . $htRow['holding_no'] . ' · due ' . $htRow['due_date'], $htRow['id']) !== null) $created++;
        }
    }
    /* smart home — cameras offline 24h+, active lock codes expiring within 48h */
    $cvAll = $pdo->query('SELECT * FROM cctv_cameras ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cvAll as $cvRow) {
        if (trim($cvRow['owner_email'] ?? '') === '') continue;
        if ($cvRow['status'] !== 'offline') continue;
        $last = $cvRow['last_seen'] ?: $cvRow['ts'];
        if (!$last || strtotime($last) >= strtotime('-24 hours')) continue;
        $ost = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $ost->execute([$cvRow['owner_email']]);
        $subId = $ost->fetchColumn();
        if (!$subId) continue;
        if (kr_alert_upsert($pdo, 'sub:' . $subId, 'smarthome', 'warning', 'Camera offline', $cvRow['id'] . ' — ' . ($cvRow['name'] ?: 'camera') . ' · ' . ($cvRow['location'] ?: '') . ' · no signal 24h+', $cvRow['id']) !== null) $created++;
    }
    $slAll = $pdo->query("SELECT * FROM smart_locks WHERE status='active' ORDER BY ts DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($slAll as $slRow) {
        if (trim($slRow['owner_email'] ?? '') === '') continue;
        if (!$slRow['valid_until'] || strtotime($slRow['valid_until']) > strtotime('+48 hours')) continue;
        $ost = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $ost->execute([$slRow['owner_email']]);
        $subId = $ost->fetchColumn();
        if (!$subId) continue;
        if (kr_alert_upsert($pdo, 'sub:' . $subId, 'smarthome', 'info', 'Lock code expiring soon', $slRow['id'] . ' — ' . ($slRow['lock_name'] ?: 'lock') . ' · expires ' . $slRow['valid_until'], $slRow['id']) !== null) $created++;
    }
    /* health check — plans overdue / due within the alert window */
    $hpAll = $pdo->query('SELECT * FROM health_plans ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    $thisMonth = date('Y-m');
    $nextMonth = date('Y-m', strtotime('+1 month'));
    foreach ($hpAll as $hpRow) {
        if (trim($hpRow['owner_email'] ?? '') === '') continue;
        if (in_array($hpRow['status'], ['Completed', 'Skipped'], true)) continue;
        $ost = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $ost->execute([$hpRow['owner_email']]);
        $subId = $ost->fetchColumn();
        if (!$subId) continue;
        $label = $hpRow['service'] . ' · ' . ($hpRow['prop'] ?: '—') . ($hpRow['unit'] ? ' · ' . $hpRow['unit'] : '') . ' · ' . $hpRow['scheduled_for'];
        if ($hpRow['scheduled_for'] && $hpRow['scheduled_for'] < $thisMonth) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'health', 'warning', 'Health check overdue', $hpRow['id'] . ' — ' . str_replace('_', ' ', $label), $hpRow['id']) !== null) $created++;
        } elseif ($hpRow['scheduled_for'] && $hpRow['scheduled_for'] <= $nextMonth) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'health', 'info', 'Health check due', $hpRow['id'] . ' — ' . str_replace('_', ' ', $label) . ' · ' . $hpRow['scheduled_for'], $hpRow['id']) !== null) $created++;
        }
    }
    /* build watch — milestone overdue / budget overrun (email-owned projects) */
    $bpAll = $pdo->query('SELECT * FROM build_projects ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach (bp_enrich($pdo, $bpAll) as $bpRow) {
        if (trim($bpRow['owner_email'] ?? '') === '') continue;
        $ost = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $ost->execute([$bpRow['owner_email']]);
        $subId = $ost->fetchColumn();
        if (!$subId) continue;
        if ((int)$bpRow['milestones_overdue'] > 0) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'build', 'warning', 'Build milestone overdue', $bpRow['id'] . ' — ' . $bpRow['title'] . ' · ' . $bpRow['milestones_overdue'] . ' past due', $bpRow['id'] . '-od') !== null) $created++;
        }
        if (!empty($bpRow['over_budget'])) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'build', 'warning', 'Build budget overrun', $bpRow['id'] . ' — ' . $bpRow['title'] . ' · spent ৳' . number_format($bpRow['spent']) . ' vs ৳' . number_format((int)$bpRow['budget_total']) . ' budget', $bpRow['id'] . '-bg') !== null) $created++;
        }
    }
    /* gate watch — flagged watchlist visitor on site (critical) + long-stay (warning) */
    $gvRows = $pdo->query("SELECT * FROM gate_visits WHERE status='Inside' ORDER BY ts DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($gvRows as $gv) {
        $oemail = (string)($gv['owner_email'] ?? '');
        if (trim($oemail) === '') continue;
        $ost = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $ost->execute([$oemail]);
        $subId = $ost->fetchColumn();
        if (!$subId) continue;
        $pname = '';
        if ($gv['prop']) { $pt = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $pt->execute([$gv['prop']]); $pn = $pt->fetchColumn(); $pname = $pn ?: $gv['prop']; }
        $who = $gv['name'] !== '' ? $gv['name'] : ($gv['vehicle_no'] !== '' ? $gv['vehicle_no'] : $gv['id']);
        if ((int)$gv['flagged'] === 1) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'gate', 'critical', 'Watchlist visitor on site', $gv['id'] . ' — ' . $who . ' · ' . $pname . ' · FLAGGED', $gv['id'] . '-wl') !== null) $created++;
        }
        if ($gv['check_in'] && (time() - strtotime($gv['check_in'])) / 3600 >= (int)(gate_cfg($pdo, 'auto_flag_hours', 4))) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'gate', 'warning', 'Long-stay visitor', $gv['id'] . ' — ' . $who . ' still inside after ' . (int)((time() - strtotime($gv['check_in'])) / 3600) . 'h · ' . $pname, $gv['id'] . '-ls') !== null) $created++;
        }
    }
    /* fire safety — expired/expiring/faulty assets, overdue inspections, open high incidents, drills (owners) */
    $faAll = fire_asset_enrich($pdo, $pdo->query('SELECT * FROM fire_assets ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC));
    foreach ($faAll as $fa) {
        if (trim($fa['owner_email'] ?? '') === '') continue;
        $ost = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $ost->execute([$fa['owner_email']]);
        $subId = $ost->fetchColumn();
        if (!$subId) continue;
        $albl = $fa['asset_type_label'] . ' · ' . ($fa['location'] ?: '—') . ' · ' . ($fa['property_name'] ?: $fa['prop']);
        if ((int)$fa['expired'] === 1) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'firesafety', 'critical', 'Fire asset expired', $fa['id'] . ' — ' . $albl . ' · expired ' . $fa['expiry_date'] . ' · replace or recharge', $fa['id'] . '-ex') !== null) $created++;
        } elseif ((int)$fa['expiring'] === 1) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'firesafety', 'warning', 'Fire asset expiring soon', $fa['id'] . ' — ' . $albl . ' · expires in ' . $fa['days_to_expiry'] . 'd', $fa['id'] . '-ew') !== null) $created++;
        }
        if ($fa['status'] === 'faulty') {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'firesafety', 'warning', 'Fire asset faulty', $fa['id'] . ' — ' . $albl . ' · needs service', $fa['id'] . '-fy') !== null) $created++;
        }
        if ((int)$fa['inspection_overdue'] === 1) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'firesafety', 'info', 'Fire inspection overdue', $fa['id'] . ' — ' . $albl . ' · due ' . $fa['next_inspection'], $fa['id'] . '-in') !== null) $created++;
        }
    }
    $fiAll = fire_incident_enrich($pdo, $pdo->query('SELECT * FROM fire_incidents ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC));
    foreach ($fiAll as $fi) {
        if (trim($fi['owner_email'] ?? '') === '') continue;
        if (in_array($fi['status'], ['Resolved', 'Closed'], true)) continue;
        if (!in_array($fi['severity'], ['high', 'critical'], true)) continue;
        $ost = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $ost->execute([$fi['owner_email']]);
        $subId = $ost->fetchColumn();
        if (!$subId) continue;
        if (kr_alert_upsert($pdo, 'sub:' . $subId, 'firesafety', 'warning', 'Open fire incident', $fi['id'] . ' — ' . ucfirst($fi['incident_type']) . ' · ' . $fi['severity'] . ' · ' . ($fi['location'] ?: '') . ' · ' . $fi['status'], $fi['id']) !== null) $created++;
    }
    $epAll = fire_plan_enrich($pdo, $pdo->query("SELECT * FROM evacuation_plans WHERE status='active' ORDER BY ts DESC")->fetchAll(PDO::FETCH_ASSOC));
    foreach ($epAll as $ep) {
        if (trim($ep['owner_email'] ?? '') === '') continue;
        if (!$ep['next_drill'] || $ep['next_drill'] >= date('Y-m-d')) continue;
        $ost = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $ost->execute([$ep['owner_email']]);
        $subId = $ost->fetchColumn();
        if (!$subId) continue;
        if (kr_alert_upsert($pdo, 'sub:' . $subId, 'firesafety', 'info', 'Evacuation drill overdue', $ep['id'] . ' — ' . ($ep['name'] ?: 'plan') . ' · ' . ($ep['property_name'] ?: $ep['prop']) . ' · due ' . $ep['next_drill'], $ep['id'] . '-dr') !== null) $created++;
    }
    /* systems watch — cert/service/fuel alerts (owners) */
    $saRows = $pdo->query('SELECT * FROM sys_assets ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    $saEnr = sys_asset_enrich($pdo, $saRows);
    $subCache = [];
    foreach ($saEnr as $sa) {
        if ($sa['owner_email'] === '') continue;
        if (!isset($subCache[$sa['owner_email']])) {
            $st = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $st->execute([$sa['owner_email']]);
            $subCache[$sa['owner_email']] = $st->fetchColumn();
        }
        $subId = $subCache[$sa['owner_email']];
        if (!$subId) continue;
        $albl = $sa['asset_type_label'];
        if ((int)$sa['cert_overdue']) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'systems', 'critical', 'Certificate expired', $sa['id'] . ' — ' . $albl . ' · ' . ($sa['cert_ref'] ?: '') . ' · expired ' . $sa['cert_expiry'] . ' · renew', $sa['id'] . '-ce') !== null) $created++;
        } elseif ((int)$sa['cert_expiring']) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'systems', 'warning', 'Certificate expiring soon', $sa['id'] . ' — ' . $albl . ' · ' . ($sa['cert_ref'] ?: '') . ' · expires ' . $sa['cert_expiry'], $sa['id'] . '-cw') !== null) $created++;
        }
        if ((int)$sa['service_overdue']) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'systems', 'warning', 'Service overdue', $sa['id'] . ' — ' . $albl . ' · due ' . $sa['next_service'], $sa['id'] . '-so') !== null) $created++;
        } elseif ((int)$sa['service_due_soon']) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'systems', 'info', 'Service due soon', $sa['id'] . ' — ' . $albl . ' · by ' . $sa['next_service'], $sa['id'] . '-sd') !== null) $created++;
        }
        if ($sa['status'] === 'faulty') {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'systems', 'warning', 'System faulty', $sa['id'] . ' — ' . $albl . ' · needs service', $sa['id'] . '-fy') !== null) $created++;
        }
        if ((int)$sa['fuel_low']) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'systems', 'warning', 'Generator fuel low', $sa['id'] . ' — ' . $albl . ' · ' . $sa['fuel_pct'] . '% (' . $sa['fuel_level'] . '/' . $sa['fuel_capacity'] . 'L) · refill before next load shed', $sa['id'] . '-fl') !== null) $created++;
        }
    }
    /* staff watch — attendance/payroll alerts (owners) */
    $bsRows = $pdo->query("SELECT * FROM building_staff WHERE status IN ('active','on_leave') ORDER BY ts DESC")->fetchAll(PDO::FETCH_ASSOC);
    $bsEnr = staff_enrich($pdo, $bsRows);
    $subCache3 = [];
    $bsAbsentPct = (int)(staff_cfg($pdo, 'absent_pct', 30));
    $bsAlertDays = (int)(staff_cfg($pdo, 'payroll_alert_days', 7));
    $bsDayOfMonth = (int)date('j');
    foreach ($bsEnr as $bs) {
        if ($bs['owner_email'] === '') continue;
        if (!isset($subCache3[$bs['owner_email']])) {
            $st = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $st->execute([$bs['owner_email']]);
            $subCache3[$bs['owner_email']] = $st->fetchColumn();
        }
        $subId = $subCache3[$bs['owner_email']];
        if (!$subId) continue;
        if ($bs['status'] === 'active' && $bs['today_status'] === '') {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'staffwatch', 'info', 'Attendance not marked', $bs['id'] . ' — ' . $bs['name'] . ' · ' . date('Y-m-d'), $bs['id'] . '-at') !== null) $created++;
        }
        if ($bs['status'] === 'active' && $bs['absent_pct'] >= $bsAbsentPct) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'staffwatch', 'warning', 'High absenteeism', $bs['id'] . ' — ' . $bs['name'] . ' · ' . $bs['absent_pct'] . '% absent this month', $bs['id'] . '-ab') !== null) $created++;
        }
        if ((int)$bs['payroll_unpaid_prev'] && $bsDayOfMonth > $bsAlertDays) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'staffwatch', 'warning', 'Salary due', $bs['id'] . ' — ' . $bs['name'] . ' · ' . date('Y-m', strtotime('-1 month')) . ' payroll unpaid', $bs['id'] . '-py') !== null) $created++;
        }
    }
    /* kalyan samity — service-charge alerts (owners) */
    $smBills = $pdo->query('SELECT * FROM samity_bills ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
    $smEnr = array_map(fn($b) => samity_bill_enrich($pdo, $b), $smBills);
    $smAlertDays = (int)(samity_cfg($pdo, 'alert_days', 7));
    $subCache4 = [];
    foreach ($smEnr as $sb) {
        if ($sb['owner_email'] === '') continue;
        if (!isset($subCache4[$sb['owner_email']])) {
            $st = $pdo->prepare("SELECT id FROM subscribers WHERE email=? AND status='active'"); $st->execute([$sb['owner_email']]);
            $subCache4[$sb['owner_email']] = $st->fetchColumn();
        }
        $subId = $subCache4[$sb['owner_email']];
        if (!$subId) continue;
        if ($sb['overdue']) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'samity', 'warning', 'Service charge overdue', $sb['unit_label'] . ' · ' . $sb['month'] . ' — ৳' . number_format($sb['outstanding']) . ' due', $sb['id'] . '-ov') !== null) $created++;
        } elseif ($sb['computed_status'] !== 'Waived' && $sb['due_date'] !== '' && $sb['due_date'] <= date('Y-m-d', strtotime('+' . $smAlertDays . ' days'))) {
            if (kr_alert_upsert($pdo, 'sub:' . $subId, 'samity', 'info', 'Service charge due soon', $sb['unit_label'] . ' · ' . $sb['month'] . ' — due ' . $sb['due_date'], $sb['id'] . '-sd') !== null) $created++;
        }
    }
    return $created;
}

