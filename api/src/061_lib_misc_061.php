function fa_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'FA-','') AS INTEGER)) FROM fire_assets")->fetchColumn();
    return 'FA-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function fi_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'FI-','') AS INTEGER)) FROM fire_incidents")->fetchColumn();
    return 'FI-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function ep_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'EP-','') AS INTEGER)) FROM evacuation_plans")->fetchColumn();
    return 'EP-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function ec_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'EC-','') AS INTEGER)) FROM emergency_contacts")->fetchColumn();
    return 'EC-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function fire_cfg($pdo, $key, $def = '') {
    $st = $pdo->prepare('SELECT v FROM org_settings WHERE k=?'); $st->execute(['fire_' . $key]);
    $v = $st->fetchColumn();
    return $v === false ? $def : $v;
}
function fire_asset_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM fire_assets WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM fire_assets ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function fire_asset_enrich($pdo, $rows) {
    $alertDays = (int)(fire_cfg($pdo, 'expiry_alert_days', 60));
    $today = date('Y-m-d');
    foreach ($rows as $k => $r) {
        $rows[$k]['property_name'] = '';
        if ($r['prop']) {
            $pt = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $pt->execute([$r['prop']]);
            $pn = $pt->fetchColumn();
            $rows[$k]['property_name'] = $pn ?: $r['prop'];
        }
        $rows[$k]['expired'] = 0; $rows[$k]['expiring'] = 0; $rows[$k]['days_to_expiry'] = null;
        if ($r['expiry_date']) {
            $diff = (int)((strtotime($r['expiry_date']) - strtotime($today)) / 86400);
            $rows[$k]['days_to_expiry'] = $diff;
            if ($diff < 0) $rows[$k]['expired'] = 1;
            elseif ($diff <= $alertDays) $rows[$k]['expiring'] = 1;
        }
        $rows[$k]['inspection_overdue'] = 0;
        if ($r['next_inspection'] && $r['next_inspection'] < $today && $r['status'] !== 'decommissioned')
            $rows[$k]['inspection_overdue'] = 1;
        $at = ['extinguisher' => 'Fire extinguisher', 'detector' => 'Smoke detector', 'sprinkler' => 'Sprinkler', 'alarm' => 'Alarm', 'exit_light' => 'Exit light', 'hose' => 'Hose reel'];
        $rows[$k]['asset_type_label'] = $at[$r['asset_type']] ?? ucfirst($r['asset_type']);
    }
    return $rows;
}
function fire_incident_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM fire_incidents WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM fire_incidents ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function fire_incident_enrich($pdo, $rows) {
    foreach ($rows as $k => $r) {
        $rows[$k]['property_name'] = '';
        if ($r['prop']) {
            $pt = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $pt->execute([$r['prop']]);
            $pn = $pt->fetchColumn();
            $rows[$k]['property_name'] = $pn ?: $r['prop'];
        }
        $rows[$k]['timeline_arr'] = json_decode($r['timeline'] ?: '[]', true) ?: [];
    }
    return $rows;
}
function fire_plan_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM evacuation_plans WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM evacuation_plans ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function fire_plan_enrich($pdo, $rows) {
    $today = date('Y-m-d');
    foreach ($rows as $k => $r) {
        $rows[$k]['property_name'] = '';
        if ($r['prop']) {
            $pt = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $pt->execute([$r['prop']]);
            $pn = $pt->fetchColumn();
            $rows[$k]['property_name'] = $pn ?: $r['prop'];
        }
        $rows[$k]['drill_overdue'] = 0;
        if ($r['status'] === 'active' && $r['next_drill'] && $r['next_drill'] < $today) $rows[$k]['drill_overdue'] = 1;
    }
    return $rows;
}
function fire_contact_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM emergency_contacts WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM emergency_contacts ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function fire_contact_enrich($pdo, $rows) {
    foreach ($rows as $k => $r) {
        $rows[$k]['property_name'] = '';
        if ($r['prop']) {
            $pt = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $pt->execute([$r['prop']]);
            $pn = $pt->fetchColumn();
            $rows[$k]['property_name'] = $pn ?: $r['prop'];
        }
        $ct = ['fire' => 'Fire service', 'police' => 'Police', 'ambulance' => 'Ambulance', 'electric' => 'Electricity', 'gas' => 'Gas', 'water' => 'Water', 'building_manager' => 'Building manager', 'owner' => 'Owner'];
        $rows[$k]['contact_type_label'] = $ct[$r['contact_type']] ?? ucfirst($r['contact_type']);
    }
    return $rows;
}
function fire_report_html($pdo, $assets, $incidents, $propName, $title) {
    $d = date('d M Y');
    $out = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
        . '<style>body{font-family:Arial,"Hind Siliguri",sans-serif;margin:32px;color:#111}'
        . 'h1{font-size:22px;margin:0 0 4px}h2{font-size:14px;font-weight:600;margin:18px 0 6px;color:#2F80ED}'
        . '.muted{color:#666;font-size:12px}table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px}'
        . 'th,td{border:1px solid #d8dde5;padding:6px 8px;text-align:left}th{background:#f2f5fa}'
        . '.sig{display:flex;gap:60px;margin-top:48px;font-size:12px}.sig div{flex:1}.sig .line{border-top:1px solid #333;margin-top:34px;padding-top:4px}'
        . '.crit{color:#b91c1c;font-weight:700}.warn{color:#b45309;font-weight:700}</style></head><body>'
        . '<h1>' . htmlspecialchars($title) . ' — Fire Safety (অগ্নি নিরাপত্তা)</h1>'
        . '<div class="muted">' . htmlspecialchars($propName) . ' · ' . $d . ' · ' . count($assets) . ' assets (মোট যন্ত্রপাতি) · ' . count($incidents) . ' incidents (ঘটনার রেকর্ড)</div>'
        . '<h2>Fire assets / অগ্নি নিরাপত্তা যন্ত্রপাতি</h2>'
        . '<table><thead><tr><th>ID</th><th>Type</th><th>Location</th><th>Property</th><th>Expiry</th><th>Last inspected</th><th>Status</th></tr></thead><tbody>';
    foreach ($assets as $a) {
        $st = ucfirst($a['status']);
        if ((int)$a['expired']) $st .= ' <span class="crit">★ EXPIRED</span>';
        elseif ((int)$a['expiring']) $st .= ' <span class="warn">★ EXPIRING</span>';
        $out .= '<tr><td>' . htmlspecialchars($a['id']) . '</td><td>' . htmlspecialchars($a['asset_type_label'] ?? $a['asset_type']) . '</td><td>' . htmlspecialchars($a['location']) . '</td><td>' . htmlspecialchars($a['property_name'] ?: '—') . '</td><td>' . htmlspecialchars($a['expiry_date'] ?: '—') . '</td><td>' . htmlspecialchars($a['last_inspected'] ?: '—') . '</td><td>' . $st . '</td></tr>';
    }
    $out .= '</tbody></table>';
    $out .= '<h2>Incidents / ঘটনা</h2><table><thead><tr><th>ID</th><th>Type</th><th>Severity</th><th>Status</th><th>Location</th><th>Reported by</th><th>Occurred</th></tr></thead><tbody>';
    foreach ($incidents as $i) {
        $sev = ucfirst($i['severity']);
        if (in_array($i['severity'], ['high', 'critical'], true)) $sev = '<span class="crit">' . $sev . '</span>';
        $out .= '<tr><td>' . htmlspecialchars($i['id']) . '</td><td>' . htmlspecialchars(ucfirst($i['incident_type'])) . '</td><td>' . $sev . '</td><td>' . htmlspecialchars($i['status']) . '</td><td>' . htmlspecialchars($i['location'] ?: '—') . '</td><td>' . htmlspecialchars($i['reported_by'] ?: '—') . '</td><td>' . htmlspecialchars($i['occurred_at'] ?: '—') . '</td></tr>';
    }
    $out .= '</tbody></table>';
    $out .= '<div class="sig"><div>Prepared by (তৈরি করেছেন)<div class="line"></div></div><div>Fire safety officer (অগ্নি নিরাপত্তা কর্মকর্তা)<div class="line"></div></div><div>Owner (মালিক)<div class="line"></div></div></div>';
    $out .= '</body></html>';
    return $out;
}
/* ---------- Phase 52: Systems Watch helpers ---------- */
function sa_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'SA-','') AS INTEGER)) FROM sys_assets")->fetchColumn();
    return 'SA-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function ss_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'SS-','') AS INTEGER)) FROM sys_services")->fetchColumn();
    return 'SS-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function sf_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'SF-','') AS INTEGER)) FROM sys_fuel")->fetchColumn();
    return 'SF-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function sys_cfg($pdo, $key, $def = '') {
    $st = $pdo->prepare('SELECT v FROM org_settings WHERE k=?'); $st->execute(['sys_' . $key]);
    $v = $st->fetchColumn();
    return $v === false ? $def : $v;
}
function sys_asset_type_label($t) {
    $m = ['lift' => 'Lift', 'generator' => 'Generator', 'water_pump' => 'Water pump', 'sewage_pump' => 'Sewage pump', 'transformer' => 'Transformer', 'solar' => 'Solar', 'booster_pump' => 'Booster pump'];
    return $m[$t] ?? ucfirst($t);
}
function sys_asset_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM sys_assets WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM sys_assets ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function sys_asset_enrich($pdo, $rows) {
    $svcDays = (int)(sys_cfg($pdo, 'service_alert_days', 14));
    $certDays = (int)(sys_cfg($pdo, 'cert_alert_days', 60));
    $fuelLow = (int)(sys_cfg($pdo, 'fuel_low_pct', 25));
    $today = date('Y-m-d');
    foreach ($rows as $k => $r) {
        $rows[$k]['property_name'] = '';
        if ($r['prop']) {
            $pt = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $pt->execute([$r['prop']]);
            $pn = $pt->fetchColumn();
            $rows[$k]['property_name'] = $pn ?: $r['prop'];
        }
        $rows[$k]['asset_type_label'] = sys_asset_type_label($r['asset_type']);
        $rows[$k]['service_overdue'] = 0;
        $rows[$k]['service_due_soon'] = 0;
        if ($r['status'] !== 'decommissioned' && $r['next_service']) {
            if ($r['next_service'] < $today) $rows[$k]['service_overdue'] = 1;
            elseif ($r['next_service'] <= date('Y-m-d', strtotime('+' . $svcDays . ' days'))) $rows[$k]['service_due_soon'] = 1;
        }
        $rows[$k]['cert_overdue'] = 0;
        $rows[$k]['cert_expiring'] = 0;
        if ($r['cert_expiry']) {
            if ($r['cert_expiry'] < $today) $rows[$k]['cert_overdue'] = 1;
            elseif ($r['cert_expiry'] <= date('Y-m-d', strtotime('+' . $certDays . ' days'))) $rows[$k]['cert_expiring'] = 1;
        }
        $rows[$k]['fuel_pct'] = null;
        $rows[$k]['fuel_low'] = 0;
        if ((int)$r['fuel_capacity'] > 0) {
            $rows[$k]['fuel_pct'] = (int)round((int)$r['fuel_level'] / (int)$r['fuel_capacity'] * 100);
            if ($rows[$k]['fuel_pct'] <= $fuelLow) $rows[$k]['fuel_low'] = 1;
        }
        $cnt = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(service_date),\'\') FROM sys_services WHERE asset=?'); $cnt->execute([$r['id']]);
        $cv = $cnt->fetch(PDO::FETCH_NUM);
        $rows[$k]['services_count'] = (int)$cv[0];
        $rows[$k]['last_service'] = $cv[1];
    }
    return $rows;
}
function sys_service_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM sys_services WHERE owner_email=? OR owner_email='' ORDER BY service_date DESC, ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM sys_services ORDER BY service_date DESC, ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function sys_fuel_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM sys_fuel WHERE owner_email=? OR owner_email='' ORDER BY refill_date DESC, ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM sys_fuel ORDER BY refill_date DESC, ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function sys_asset_name($pdo, $id) {
    $st = $pdo->prepare('SELECT asset_type FROM sys_assets WHERE id=?'); $st->execute([$id]);
    $t = $st->fetchColumn();
    return $t ? sys_asset_type_label($t) : '';
}
function sys_report_html($pdo, $title, $assets, $services, $fuel, $cfg) {
    $rows = '';
    foreach ($assets as $a) {
        $flags = [];
        if ((int)$a['cert_overdue']) $flags[] = 'CERT EXPIRED';
        if ((int)$a['cert_expiring']) $flags[] = 'cert expiring';
        if ((int)$a['service_overdue']) $flags[] = 'SERVICE OVERDUE';
        if ((int)$a['service_due_soon']) $flags[] = 'service due soon';
        if ((int)$a['fuel_low']) $flags[] = 'LOW FUEL ' . $a['fuel_pct'] . '%';
        if ($a['status'] === 'faulty') $flags[] = 'FAULTY';
        $rows .= '<tr><td>' . htmlspecialchars($a['id']) . '</td><td>' . htmlspecialchars($a['asset_type_label']) . '</td><td>' . htmlspecialchars($a['location'] ?: '') . '</td><td>' . htmlspecialchars($a['model'] ?: '') . '</td><td>' . htmlspecialchars($a['next_service'] ?: '—') . '</td><td>' . htmlspecialchars($a['cert_expiry'] ?: '—') . '</td><td>' . ($a['fuel_pct'] !== null ? $a['fuel_pct'] . '%' : '—') . '</td><td>' . htmlspecialchars(implode(', ', $flags)) . '</td></tr>';
    }
    $frows = '';
    foreach ($fuel as $f) {
        $frows .= '<tr><td>' . htmlspecialchars($f['id']) . '</td><td>' . htmlspecialchars($f['refill_date']) . '</td><td>' . htmlspecialchars(sys_asset_name($pdo, $f['asset']) ?: $f['asset']) . '</td><td>' . htmlspecialchars((string)$f['liters']) . ' L</td><td>৳' . number_format((float)$f['rate_per_litre']) . '</td><td>৳' . number_format((int)$f['amount']) . '</td><td>' . htmlspecialchars($f['vendor'] ?: '') . '</td><td>' . htmlspecialchars((string)$f['hours_run']) . '</td></tr>';
    }
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title></head><body style="font-family:Arial,sans-serif;color:#1A2433;padding:32px">'
        . '<h1>' . htmlspecialchars($title) . ' — Systems Watch (সিস্টেম ওয়াচ)</h1>'
        . '<p>Generated ' . date('Y-m-d H:i') . ' · Essential systems: lift, generator, pumps, transformer</p>'
        . '<h2>Assets / যন্ত্রপাতি</h2><table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px"><tr style="background:#F6F9FE"><th>ID</th><th>Type</th><th>Location</th><th>Model</th><th>Next service</th><th>Cert expiry</th><th>Fuel</th><th>Flags</th></tr>' . $rows . '</table>'
        . ($frows ? '<h2>Fuel ledger / ডিজেল লেজার</h2><table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px"><tr style="background:#F6F9FE"><th>ID</th><th>Date</th><th>Asset</th><th>Liters</th><th>Rate</th><th>Amount</th><th>Vendor</th><th>Hours</th></tr>' . $frows . '</table>' : '')
        . '<div class="sig" style="margin-top:48px;display:flex;gap:48px"><div>Prepared by (তৈরি করেছেন)<div style="border-bottom:1px solid #999;width:180px;height:24px"></div></div><div>Technician (টেকনিশিয়ান)<div style="border-bottom:1px solid #999;width:180px;height:24px"></div></div><div>Owner (মালিক)<div style="border-bottom:1px solid #999;width:180px;height:24px"></div></div></div>'
        . '</body></html>';
}
/* ---------- Phase 53: Staff Watch helpers ---------- */
function bs_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'BS-','') AS INTEGER)) FROM building_staff")->fetchColumn();
    return 'BS-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function at_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'AT-','') AS INTEGER)) FROM staff_attendance")->fetchColumn();
    return 'AT-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function py_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'PY-','') AS INTEGER)) FROM staff_payroll")->fetchColumn();
    return 'PY-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function staff_cfg($pdo, $key, $def = '') {
    $st = $pdo->prepare('SELECT v FROM org_settings WHERE k=?'); $st->execute(['staff_' . $key]);
    $v = $st->fetchColumn();
    return $v === false ? $def : $v;
}
function staff_role_label($r) {
    $m = ['guard' => 'Guard', 'cleaner' => 'Cleaner', 'caretaker' => 'Caretaker', 'driver' => 'Driver', 'supervisor' => 'Supervisor', 'security' => 'Security'];
    return $m[$r] ?? ucfirst($r);
}
function staff_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM building_staff WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM building_staff ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function staff_enrich($pdo, $rows) {
    $today = date('Y-m-d');
    $month = date('Y-m');
    $prev = date('Y-m', strtotime('-1 month'));
    foreach ($rows as $k => $r) {
        $rows[$k]['property_name'] = '';
        if ($r['prop']) {
            $pt = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $pt->execute([$r['prop']]);
            $pn = $pt->fetchColumn();
            $rows[$k]['property_name'] = $pn ?: $r['prop'];
        }
        $rows[$k]['role_label'] = staff_role_label($r['role']);
        $rows[$k]['today_status'] = '';
        $rows[$k]['present_month'] = 0;
        $rows[$k]['late_month'] = 0;
        $rows[$k]['absent_month'] = 0;
        $rows[$k]['leave_month'] = 0;
        $rows[$k]['absent_pct'] = 0;
        $rows[$k]['payroll_unpaid_prev'] = 0;
        $rows[$k]['days_employed'] = 0;
        if ($r['join_date']) {
            $rows[$k]['days_employed'] = max(0, (int)floor((strtotime($today) - strtotime($r['join_date'])) / 86400));
        }
        $q = $pdo->prepare('SELECT status FROM staff_attendance WHERE staff=? AND work_date=?');
        $q->execute([$r['id'], $today]);
        $ts = $q->fetchColumn();
        if ($ts) $rows[$k]['today_status'] = $ts;
        $cnt = $pdo->prepare("SELECT status, COUNT(*) FROM staff_attendance WHERE staff=? AND work_date LIKE ? GROUP BY status");
        $cnt->execute([$r['id'], $month . '-%']);
        $total = 0;
        foreach ($cnt->fetchAll(PDO::FETCH_NUM) as $cv) {
            $total += (int)$cv[1];
            if ($cv[0] === 'present') $rows[$k]['present_month'] = (int)$cv[1];
            if ($cv[0] === 'late') $rows[$k]['late_month'] = (int)$cv[1];
            if ($cv[0] === 'absent') $rows[$k]['absent_month'] = (int)$cv[1];
            if ($cv[0] === 'leave') $rows[$k]['leave_month'] = (int)$cv[1];
        }
        if ($total > 0) $rows[$k]['absent_pct'] = (int)round($rows[$k]['absent_month'] / $total * 100);
        $py = $pdo->prepare("SELECT COUNT(*) FROM staff_payroll WHERE staff=? AND month=? AND status='Draft'");
        $py->execute([$r['id'], $prev]);
        $rows[$k]['payroll_unpaid_prev'] = (int)$py->fetchColumn();
    }
    return $rows;
}
function staff_attendance_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM staff_attendance WHERE owner_email=? OR owner_email='' ORDER BY work_date DESC, ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM staff_attendance ORDER BY work_date DESC, ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function staff_payroll_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM staff_payroll WHERE owner_email=? OR owner_email='' ORDER BY month DESC, ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM staff_payroll ORDER BY month DESC, ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function staff_payroll_calc($pdo, $staffRow, $month, $overtime = 0, $bonus = 0, $advance = 0) {
    $absent = 0;
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM staff_attendance WHERE staff=? AND work_date LIKE ? AND status='absent'");
    $cnt->execute([$staffRow['id'], $month . '-%']);
    $absent = (int)$cnt->fetchColumn();
    $salary = max(0, (int)$staffRow['monthly_salary']);
    $daily = (int)round($salary / 30);
    $net = $salary + max(0, (int)$overtime) + max(0, (int)$bonus) - max(0, (int)$advance) - ($absent * $daily);
    if ($net < 0) $net = 0;
    return ['absent_days' => $absent, 'daily_rate' => $daily, 'net' => $net];
}
function staff_report_html($pdo, $title, $staff, $attendance, $payroll, $cfg) {
    $today = date('Y-m-d');
    $rows = '';
    foreach ($staff as $s) {
        $flags = [];
        if ($s['status'] === 'active' && $s['today_status'] === '') $flags[] = 'NO MARK TODAY';
        if ($s['today_status'] === 'absent') $flags[] = 'ABSENT TODAY';
        if ($s['today_status'] === 'late') $flags[] = 'LATE TODAY';
        if ($s['absent_pct'] > 0 && $s['absent_pct'] >= (int)$cfg['absent_pct']) $flags[] = 'ABSENT ' . $s['absent_pct'] . '%';
        if ($s['payroll_unpaid_prev']) $flags[] = 'SALARY DUE';
        $rows .= '<tr><td>' . htmlspecialchars($s['id']) . '</td><td>' . htmlspecialchars($s['name'] ?: '') . '</td><td>' . htmlspecialchars($s['role_label']) . '</td><td>' . htmlspecialchars($s['property_name'] ?: '—') . '</td><td>' . htmlspecialchars($s['shift']) . '</td><td>' . htmlspecialchars($s['today_status'] ?: '—') . '</td><td>' . $s['present_month'] . '/' . $s['late_month'] . '/' . $s['absent_month'] . '</td><td>' . htmlspecialchars(implode(', ', $flags)) . '</td></tr>';
    }
    $prows = '';
    foreach ($payroll as $p) {
        $nm = $pdo->prepare('SELECT name FROM building_staff WHERE id=?'); $nm->execute([$p['staff']]);
        $prows .= '<tr><td>' . htmlspecialchars($p['id']) . '</td><td>' . htmlspecialchars($p['month']) . '</td><td>' . htmlspecialchars($nm->fetchColumn() ?: $p['staff']) . '</td><td>৳' . number_format((int)$p['salary']) . '</td><td>' . (int)$p['absent_days'] . '</td><td>৳' . number_format((int)$p['net']) . '</td><td>' . htmlspecialchars($p['status']) . '</td></tr>';
    }
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title></head><body style="font-family:Arial,sans-serif;color:#1A2433;padding:32px">'
        . '<h1>' . htmlspecialchars($title) . ' — Staff Watch (স্টাফ ওয়াচ)</h1>'
        . '<p>Generated ' . date('Y-m-d H:i') . ' · building staff roster, attendance and payroll</p>'
        . '<h2>Roster / কর্মী তালিকা</h2><table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px"><tr style="background:#F6F9FE"><th>ID</th><th>Name</th><th>Role</th><th>Property</th><th>Shift</th><th>Today</th><th>P/L/A</th><th>Flags</th></tr>' . $rows . '</table>'
        . ($prows ? '<h2>Payroll / বেতন</h2><table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px"><tr style="background:#F6F9FE"><th>ID</th><th>Month</th><th>Staff</th><th>Salary</th><th>Absent</th><th>Net</th><th>Status</th></tr>' . $prows . '</table>' : '')
        . '<div class="sig" style="margin-top:48px;display:flex;gap:48px"><div>Prepared by (তৈরি করেছেন)<div style="border-bottom:1px solid #999;width:180px;height:24px"></div></div><div>HR / Manager (এইচআর / ম্যানেজার)<div style="border-bottom:1px solid #999;width:180px;height:24px"></div></div><div>Owner (মালিক)<div style="border-bottom:1px solid #999;width:180px;height:24px"></div></div></div>'
        . '</body></html>';
}

/* ═══════════ Kalyan Samity (Building Society) helpers — VAS-J ═══════════ */
function samity_cfg($pdo, $key, $def = '') {
    $st = $pdo->prepare('SELECT v FROM org_settings WHERE k=?'); $st->execute(['samity_' . $key]);
    $v = $st->fetchColumn(); return $v === false ? $def : $v;
}
function samity_next_id($pdo, $prefix) {
    $tbl = ['SM-' => 'samity_members', 'SB-' => 'samity_bills', 'SC-' => 'samity_collections'][$prefix] ?? 'samity_members';
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'$prefix','') AS INTEGER)) FROM $tbl")->fetchColumn();
    return $prefix . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
}
function samity_member_rows($pdo, $u) {
    if ($u['role'] === 'owner') { $st = $pdo->prepare("SELECT * FROM samity_members WHERE owner_email=? OR owner_email='' ORDER BY ts DESC"); $st->execute([$u['email']]); return $st->fetchAll(PDO::FETCH_ASSOC); }
    return $pdo->query('SELECT * FROM samity_members ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function samity_bill_rows($pdo, $u) {
    if ($u['role'] === 'owner') { $st = $pdo->prepare("SELECT * FROM samity_bills WHERE owner_email=? OR owner_email='' ORDER BY month DESC, ts DESC"); $st->execute([$u['email']]); return $st->fetchAll(PDO::FETCH_ASSOC); }
    return $pdo->query('SELECT * FROM samity_bills ORDER BY month DESC, ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function samity_collection_rows($pdo, $u) {
    if ($u['role'] === 'owner') { $st = $pdo->prepare("SELECT * FROM samity_collections WHERE owner_email=? OR owner_email='' ORDER BY collected_at DESC, ts DESC"); $st->execute([$u['email']]); return $st->fetchAll(PDO::FETCH_ASSOC); }
    return $pdo->query('SELECT * FROM samity_collections ORDER BY collected_at DESC, ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function samity_unit_label($pdo, $unit) {
    $st = $pdo->prepare('SELECT u.name, p.name FROM units u LEFT JOIN properties p ON p.id=u.p WHERE u.id=?'); $st->execute([$unit]);
    $r = $st->fetch(PDO::FETCH_NUM); return $r ? (($r[0] ?? $unit) . ($r[1] ? ' · ' . $r[1] : '')) : $unit;
}
function samity_bill_enrich($pdo, $bill) {
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM samity_collections WHERE bill=?'); $st->execute([$bill['id']]);
    $collected = (int)$st->fetchColumn();
    $due = $bill['due_date'] !== '' && $bill['due_date'] < date('Y-m-d');
    $b = $bill;
    $b['collected'] = $collected;
    $b['outstanding'] = max(0, (int)$bill['amount'] - $collected);
    $b['computed_status'] = ($b['status'] === 'Waived') ? 'Waived' : (($collected >= (int)$bill['amount'] && (int)$bill['amount'] > 0) ? 'Paid' : ($collected > 0 ? 'Partial' : $b['status']));
    $b['overdue'] = $due && !in_array($b['computed_status'], ['Paid', 'Waived'], true);
    $b['unit_label'] = samity_unit_label($pdo, $bill['unit']);
    return $b;
}
function samity_fund($pdo, $bills, $collections) {
    $total_billed = 0; $total_collected = 0;
    foreach ($bills as $bl) { $total_billed += (int)$bl['amount']; }
    foreach ($collections as $c) { $total_collected += (int)$c['amount']; }
    return ['total_billed' => $total_billed, 'total_collected' => $total_collected, 'outstanding' => max(0, $total_billed - $total_collected), 'collection_rate' => $total_billed > 0 ? round($total_collected / $total_billed * 100) : 0];
}
function samity_report_html($pdo, $title, $members, $bills, $collections, $cfg) {
    $fund = samity_fund($pdo, $bills, $collections);
    $today = date('Y-m-d');
    $rows = '';
    foreach ($bills as $bl) {
        $rows .= '<tr><td>' . htmlspecialchars($bl['unit_label']) . '</td><td>' . htmlspecialchars($bl['month']) . '</td><td style="text-align:right">৳' . number_format((int)$bl['amount']) . '</td><td style="text-align:right">৳' . number_format((int)$bl['collected']) . '</td><td style="text-align:right">৳' . number_format((int)$bl['outstanding']) . '</td><td>' . ($bl['overdue'] ? '<b style="color:#C0392B">OVERDUE</b>' : htmlspecialchars($bl['computed_status'])) . '</td></tr>';
    }
    $mrows = '';
    foreach ($members as $m) {
        $mrows .= '<tr><td>' . htmlspecialchars($m['name']) . '</td><td>' . htmlspecialchars($m['role']) . '</td><td>' . htmlspecialchars($m['phone']) . '</td><td>' . htmlspecialchars($m['since_date']) . '</td><td>' . htmlspecialchars($m['status']) . '</td></tr>';
    }
    $html = '<!DOCTYPE html><html lang="bn"><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title><style>body{font-family:"Noto Sans Bengali",sans-serif;max-width:900px;margin:24px auto;padding:0 16px;color:#1A2332}h1{font-size:20px;margin:0 0 4px}h2{font-size:15px;margin:18px 0 8px;border-bottom:2px solid #E8EDF4;padding-bottom:6px}table{width:100%;border-collapse:collapse;margin:8px 0 18px;font-size:13px}th,td{border:1px solid #E2E8F0;padding:7px 9px;text-align:left}th{background:#F4F7FB}.fund{display:flex;gap:24px;flex-wrap:wrap;margin:12px 0 20px}.fc{background:#F4F7FB;border:1px solid #E2E8F0;border-radius:10px;padding:12px 18px;flex:1;min-width:150px}.fc b{display:block;font-size:18px;margin-top:2px}.sig{display:flex;justify-content:space-between;margin-top:48px;font-size:13px}.sig div{text-align:center;width:42%}.sig .ln{border-top:1px solid #999;padding-top:6px;margin-top:34px}.muted{color:#6B7A90;font-size:12px}</style></head><body>';
    $html .= '<h1>' . htmlspecialchars($title) . '</h1><div class="muted">' . $today . ' · KRTaker Kalyan Samity</div>';
    $html .= '<div class="fund"><div class="fc">' . htmlspecialchars($cfg['t_total_billed'] ?? 'Total billed') . '<b>৳' . number_format($fund['total_billed']) . '</b></div><div class="fc">' . htmlspecialchars($cfg['t_collected'] ?? 'Collected') . '<b>৳' . number_format($fund['total_collected']) . '</b></div><div class="fc">' . htmlspecialchars($cfg['t_outstanding'] ?? 'Outstanding') . '<b>৳' . number_format($fund['outstanding']) . '</b></div><div class="fc">' . htmlspecialchars($cfg['t_rate'] ?? 'Collection rate') . '<b>' . $fund['collection_rate'] . '%</b></div></div>';
    $html .= '<h2>' . htmlspecialchars($cfg['t_committee'] ?? 'Committee') . '</h2><table><thead><tr><th>Name</th><th>Role</th><th>Phone</th><th>Since</th><th>Status</th></tr></thead><tbody>' . $mrows . '</tbody></table>';
    $html .= '<h2>' . htmlspecialchars($cfg['t_charges'] ?? 'Service charge ledger') . '</h2><table><thead><tr><th>Unit</th><th>Month</th><th>Billed</th><th>Collected</th><th>Outstanding</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    $html .= '<div class="sig"><div><div class="ln">Chairman / সভাপতি</div></div><div><div class="ln">Treasurer / কোষাধ্যক্ষ</div></div></div>';
    $html .= '</body></html>';
    return $html;
}
