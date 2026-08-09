function SETTINGS_DEFAULTS() {
    return [
        'theme' => 'light', 'lang' => 'en',
        'notify_rent' => true, 'notify_collections' => true, 'notify_renewal' => true, 'notify_docs' => true,
        'wa_reminders' => true, 'email_digest' => true, 'notify_premium' => true,
        'wa_phone' => '', 'alert_sla' => true, 'alert_compliance' => true,
        'alert_arrears' => true, 'alert_renewal' => true,
    ];
}
function settings_get($pdo, $user_key) {
    $st = $pdo->prepare('SELECT prefs FROM user_settings WHERE user_key=?');
    $st->execute([$user_key]);
    $raw = $st->fetchColumn();
    $prefs = $raw ? json_decode($raw, true) : [];
    return array_merge(SETTINGS_DEFAULTS(), is_array($prefs) ? $prefs : []);
}
function settings_save($pdo, $user_key, $in) {
    $cur = settings_get($pdo, $user_key);
    $allowed = array_keys(SETTINGS_DEFAULTS());
    foreach ($allowed as $k) {
        if (array_key_exists($k, $in)) {
            $v = $in[$k];
            if ($k === 'theme') $cur[$k] = ($v === 'dark') ? 'dark' : 'light';
            elseif ($k === 'lang') $cur[$k] = ($v === 'bn') ? 'bn' : 'en';
            else $cur[$k] = (bool)$v;
        }
    }
    $pdo->prepare('INSERT INTO user_settings (user_key, prefs, updated_at) VALUES (?,?,datetime(\'now\'))
        ON CONFLICT(user_key) DO UPDATE SET prefs=excluded.prefs, updated_at=datetime(\'now\')')
        ->execute([$user_key, json_encode($cur)]);
    return $cur;
}
