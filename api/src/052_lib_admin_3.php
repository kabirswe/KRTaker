function admin_guard($u) {
    if (($u['role'] ?? '') !== 'superadmin' && ($u['email'] ?? '') !== ADMIN_EMAIL)
        json_out(['ok' => false, 'error' => 'Super admin only.'], 403);
}
function admin_files_count($pdo) {
    try { return (int)$pdo->query('SELECT COUNT(*) FROM sqlite_master WHERE type=\'table\'')->fetchColumn(); } catch (Exception $e) { return 0; }
}
