function sub_info($email) {
    $pdo = db();
    $st = $pdo->prepare('SELECT id, name, org, email, role, plan, status, trial_end FROM subscribers WHERE email=?');
    $st->execute([$email]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* V2.34: actual switchable subordinate users for the tb-user 🔀 dropdown.
   Staff accounts → other active app_users strictly below (three-group rules).
   Subscriber accounts (owner OR team member) → their own team_members
   strictly below (same rules). Empty when no such users exist — the UI then
   shows "No subordinate users" instead of offering roles that would fail. */
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

function user_payload($u) {
    $mods = effective_modules($u);
    $rmap = [];
    foreach (array_keys(ROLE_MODULES()) as $r) $rmap[$r] = effective_modules($u, $r);
    $p = [
        'id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'],
        'role' => $u['role'], 'kind' => $u['kind'], 'modules' => $mods,
        'role_modules' => $rmap, 'limits' => effective_limits($u),
        'avatar' => substr(preg_replace('/[^A-Za-z]/', '', $u['name']), 0, 2),
        'photo' => $u['photo'] ?? '',
    ];
    if ($u['kind'] === 'sub') {
        $p['org'] = $u['org'] ?? '';
        $p['plan'] = $u['plan'] ?? 'Trial';
        $p['trial_end'] = $u['trial_end'] ?? '';
        $p['is_staff'] = false;
        $p['setup_at'] = $u['setup_at'] ?? '';   /* V2.27: guided-setup completion marker */
    } else {
        $p['dept'] = $u['dept'] ?? '';
        $p['is_staff'] = true;
        $p['org'] = 'KRTaker Platform';
        $sub = $pdo ?? db();
        $p['plan'] = 'Enterprise';
        $p['trial_end'] = '';
    }
    if (!empty($u['impersonator'])) {
        $p['impersonator'] = $u['impersonator'];
        $p['imp_expires'] = $u['token_expires'] ?? '';
    }
    if (!empty($u['team_member'])) {
        $p['team_member'] = true;   /* SA1 v20: sub-account of the subscriber workspace */
    }
    $p['subordinates'] = view_as_targets($u);   /* V2.34: real switchable users for the 🔀 dropdown */
    return $p;
}

/* ---------- Phase 18: user settings + profile helpers ---------- */
