function user_key_for($u) {
    return ($u['kind'] ?? 'staff') . ':' . $u['id'];
}
/* ---------- SA1: super-admin panel helpers ---------- */
