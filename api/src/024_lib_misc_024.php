function AI_CONFIG($pdo = null) {
    /* Key resolution order: env KRT_DS_KEY → admin_settings ai_key → REPLACE_ME (offline).
       Provider/model/base_url come from App Settings (ai_provider/ai_model) and the AI
       caretaker console (ai_key/ai_base_url). provider=offline always forces offline mode. */
    $key = getenv('KRT_DS_KEY') ?: '';
    $provider = 'deepseek'; $model = 'deepseek-chat';
    $url = 'https://api.deepseek.com/chat/completions';
    if ($pdo) {
        $provider = admin_cfg($pdo, 'ai_provider', 'deepseek');
        $model = admin_cfg($pdo, 'ai_model', 'deepseek-chat');
        if ($key === '') { $k2 = admin_cfg($pdo, 'ai_key', ''); if ($k2 !== '' && $k2 !== 'REPLACE_ME') $key = $k2; }
        $bu = trim(admin_cfg($pdo, 'ai_base_url', ''));
        if ($provider === 'openai')        $url = $bu !== '' ? $bu : 'https://api.openai.com/v1/chat/completions';
        elseif ($provider === 'deepseek')  $url = $bu !== '' ? $bu : 'https://api.deepseek.com/chat/completions';
        elseif ($provider === 'offline')   $key = 'REPLACE_ME';
        else { $provider = 'deepseek'; $model = 'deepseek-chat'; $url = 'https://api.deepseek.com/chat/completions'; }
    } elseif ($key === '') { $key = 'REPLACE_ME'; }
    return ['key' => $key ?: 'REPLACE_ME', 'model' => $model, 'url' => $url, 'provider' => $provider];
}
