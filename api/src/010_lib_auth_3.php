function team_owner_only($u) {
    if ($u['role'] === 'superadmin') return true;
    if (($u['kind'] ?? '') !== 'sub' || $u['role'] !== 'owner' || !empty($u['team_member']))
        return false;
    return true;
}
function team_scope_email($u, $body, $pdo) {
    /* owner → own email; superadmin → optional target email */
    if ($u['role'] === 'superadmin') {
        $em = strtolower(trim($body['email'] ?? ''));
        if (!$em) {
            $first = $pdo->query("SELECT email FROM subscribers WHERE status='active' ORDER BY id LIMIT 1")->fetchColumn();
            return $first ?: '';
        }
        return $em;
    }
    return $u['email'];
}
function team_revoke_tokens($pdo, $team_id) {
    $pdo->prepare("DELETE FROM app_tokens WHERE kind='team' AND user_id=?")->execute([$team_id]);
}

