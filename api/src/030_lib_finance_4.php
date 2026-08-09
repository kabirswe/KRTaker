function reminder_config_get($pdo) {
    $st = $pdo->prepare("SELECT v FROM platform_meta WHERE k='reminder_config'"); $st->execute();
    $v = $st->fetchColumn();
    $cfg = $v ? json_decode($v, true) : [];
    if (!is_array($cfg)) $cfg = [];
    $d = REMINDER_DEFAULT_CONFIG();
    foreach ($d as $k => $dv) {
        if (!array_key_exists($k, $cfg)) $cfg[$k] = $dv;
        elseif ($k === 'tiers') {
            foreach ($dv as $tk => $tv) if (!isset($cfg['tiers'][$tk])) $cfg['tiers'][$tk] = $tv;
        }
    }
    return $cfg;
}
function reminder_config_save($pdo, $cfg) {
    $d = REMINDER_DEFAULT_CONFIG();
    $clean = ['enabled' => !empty($cfg['enabled']), 'late_fee' => (string)($cfg['late_fee'] ?? $d['late_fee'])];
    $clean['tiers'] = [];
    foreach (['1', '2', '3'] as $tk) {
        $t = $cfg['tiers'][$tk] ?? [];
        $clean['tiers'][$tk] = [
            'label' => (string)($t['label'] ?? $d['tiers'][$tk]['label']),
            'min_days' => (int)($t['min_days'] ?? $d['tiers'][$tk]['min_days']),
            'max_days' => (int)($t['max_days'] ?? $d['tiers'][$tk]['max_days']),
            'note' => (string)($t['note'] ?? $d['tiers'][$tk]['note']),
        ];
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT OR REPLACE INTO platform_meta (k, v) VALUES ('reminder_config', ?)")->execute([$json]);
    return $clean;
}
function reminder_due_date($m) {
    if (!preg_match('/^(\d{4})-(\d{2})$/', (string)$m, $mm)) return null;
    return $mm[1] . '-' . $mm[2] . '-01';
}
function reminder_days_overdue($m) {
    $due = reminder_due_date($m);
    if (!$due) return 0;
    return (int)floor((strtotime(date('Y-m-d')) - strtotime($due)) / 86400);
}
function reminder_tier_for($cfg, $days) {
    if ($days < 0) return 0;
    foreach (['3', '2', '1'] as $tk) {
        $t = $cfg['tiers'][$tk];
        if ($days >= (int)$t['min_days'] && $days <= (int)$t['max_days']) return (int)$tk;
    }
    return 0;
}
function reminder_plan($pdo) {
    $cfg = reminder_config_get($pdo);
    $rows = collections_data($pdo);
    $last = [];
    $st = $pdo->query('SELECT invoice_id, tier FROM invoice_reminders');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $last[$r['invoice_id']] = (int)$r['tier'];
    $plan = [];
    foreach ($rows as $r) {
        $days = reminder_days_overdue($r['m']);
        $tier = reminder_tier_for($cfg, $days);
        $plan[] = $r + ['days_overdue' => $days, 'tier' => $tier, 'last_tier' => $last[$r['inv']] ?? 0];
    }
    return ['cfg' => $cfg, 'plan' => $plan];
}
function reminder_send_one($pdo, $r, $cfg) {
    $tier = $r['tier'];
    $t = $cfg['tiers'][(string)$tier];
    if (empty($r['email'])) return ['inv' => $r['inv'], 'ok' => false, 'reason' => 'no-email'];
    $tier_note = render_merge($t['note'], ['month' => $r['m']]);
    list($subj, $html) = email_render('rent_reminder', [
        'tenant_name' => $r['tenant'], 'invoice_id' => $r['inv'], 'month' => $r['m'],
        'amount' => number_format($r['due']), 'property' => $r['property'], 'unit' => $r['unit'],
        'tier' => $tier, 'days_overdue' => $r['days_overdue'], 'tier_note' => $tier_note, 'late_fee' => $cfg['late_fee'],
    ]);
    $ok = send_mail($r['email'], $subj, $html, null, true);
    if ($ok) {
        $pdo->prepare("INSERT OR REPLACE INTO invoice_reminders (invoice_id, tier, sent_at, via) VALUES (?,?,datetime('now'),'email')")
            ->execute([$r['inv'], $tier]);
    }
    return ['inv' => $r['inv'], 'to' => $r['email'], 'tier' => $tier, 'days' => $r['days_overdue'], 'ok' => $ok];
}
