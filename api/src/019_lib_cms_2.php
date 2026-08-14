function plan_for_user($u) {
    if (($u['kind'] ?? '') === 'sub') return strtolower($u['plan'] ?? 'starter');
    return 'enterprise';
}
