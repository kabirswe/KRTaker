function view_as_targets($u) {
    $pdo = db();
    $H = [
        'superadmin' => ['g' => 'admin', 'r' => 2], 'super_admin' => ['g' => 'admin', 'r' => 2], 'admin' => ['g' => 'admin', 'r' => 1],
        'owner' => ['g' => 'sub', 'r' => 4], 'property_owner' => ['g' => 'sub', 'r' => 4],
        'manager' => ['g' => 'sub', 'r' => 3], 'property_manager' => ['g' => 'sub', 'r' => 3],
        'tenant' => ['g' => 'sub', 'r' => 2], 'building_staff' => ['g' => 'sub', 'r' => 1],
        'hr' => ['g' => 'bo', 'r' => 4], 'hr_admin' => ['g' => 'bo', 'r' => 4],
        'legal' => ['g' => 'bo', 'r' => 4], 'legal_counsel' => ['g' => 'bo', 'r' => 4],
        'accountant' => ['g' => 'bo', 'r' => 4],
        'crm' => ['g' => 'bo', 'r' => 3], 'crm_helpdesk' => ['g' => 'bo', 'r' => 3],
        'svc_mgr' => ['g' => 'bo', 'r' => 3], 'service_manager' => ['g' => 'bo', 'r' => 3],
        'partner' => ['g' => 'bo', 'r' => 2], 'service_partner' => ['g' => 'bo', 'r' => 2],
        'service_staff' => ['g' => 'bo', 'r' => 1],
    ];
    $me = $H[$u['role'] ?? ''] ?? null;
    if (!$me) return [];
    $out = [];
    if (($u['kind'] ?? '') === 'staff') {
        foreach ($pdo->query("SELECT name,email,role FROM app_users WHERE active=1") as $r) {
            if (strtolower((string)$r['email']) === strtolower((string)$u['email'])) continue;
            $them = $H[$r['role']] ?? null;
            if (!$them) continue;
            $ok = $me['g'] === 'admin'
                ? !($them['g'] === 'admin' && $them['r'] >= $me['r'])
                : ($me['g'] === $them['g'] && $them['r'] < $me['r']);
            if ($ok) $out[] = ['name' => $r['name'], 'email' => $r['email'], 'role' => $r['role'], 'kind' => 'staff'];
        }
    } else {
        /* subscriber (owner or team member): team members under the SAME subscriber, strictly below */
        $subEmail = strtolower(trim((string)$u['email']));
        $st = $pdo->prepare("SELECT name,email,role FROM team_members WHERE lower(sub_email)=? AND status='active'");
        $st->execute([$subEmail]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (strtolower((string)$r['email']) === $subEmail) continue;
            $them = $H[$r['role']] ?? null;
            if (!$them) continue;
            if ($me['g'] === $them['g'] && $them['r'] < $me['r'])
                $out[] = ['name' => $r['name'], 'email' => $r['email'], 'role' => $r['role'], 'kind' => 'team'];
        }
    }
    return $out;
}

