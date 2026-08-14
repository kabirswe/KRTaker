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
