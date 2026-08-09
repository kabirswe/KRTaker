function ADMIN_SETTING_DEFAULTS() {
    return [
        'site_name' => 'KRTaker', 'support_email' => 'support@krtaker.com',
        'support_phone' => '', 'maintenance_mode' => '0', 'signups_open' => '1',
        'trial_days' => '14', 'invoice_prefix' => 'INV-', 'currency' => 'BDT',
        'default_plan' => 'starter', 'admin_email' => 'kabir.swe@gmail.com',
        'ai_provider' => 'deepseek', 'ai_model' => 'deepseek-chat',
        // ── Security & access ──
        'sec_password_min_len' => '6', 'sec_password_complex' => '0',
        'sec_login_attempts' => '10', 'sec_lockout_minutes' => '15', 'sec_session_ttl_days' => '7',
        'bot_guard' => '1', 'bot_pow_bits' => '12', 'turnstile_secret' => '',
        // ── Email & notifications (master switches — per-user opt-outs still apply) ──
        'mail_from_name' => 'KRTaker', 'mail_from_email' => '',
        'mail_welcome' => '1', 'mail_rent_reminders' => '1', 'mail_collections' => '1',
        'mail_docs' => '1', 'mail_renewal' => '1', 'mail_digest' => '1',
        // ── Platform & growth ──
        'plat_referral_bonus' => '5000', 'plat_newsletter' => '1', 'plat_wa_links' => '1',
        'plat_auto_approve' => '0', 'plat_demo_mode' => '0',
        // ── Retention & limits ──
        'sys_audit_retention_days' => '90', 'sys_api_rate_limit' => '120', 'sys_backup_keep' => '7',
    ];
}
