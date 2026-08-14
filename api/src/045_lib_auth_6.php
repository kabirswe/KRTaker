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
