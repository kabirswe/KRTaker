function otp_email_html($code, $name) {
    list($s, $h) = email_render('otp', ['code' => $code, 'name' => $name, 'expiry' => '5 minutes']);
    return $h;
}

function welcome_email_html($name, $trial_end) {
    list($s, $h) = email_render('welcome', ['name' => $name, 'trial_end' => $trial_end, 'workspace_url' => 'https://krtaker.com/dashboard-v2.html']);
    return $h;
}

function contact_email_html($c) {
    return '<div style="font-family:Inter,Arial,sans-serif;max-width:520px;margin:0 auto;padding:28px;border:1px solid #E4EAF3;border-radius:16px">'
        . '<h2 style="margin:0 0 10px;color:#1A2433">New contact message</h2>'
        . '<p style="color:#5B6B83;font-size:14px"><b>Name:</b> ' . esc($c['name'] ?? '') . '<br>'
        . '<b>Email:</b> ' . esc($c['email'] ?? '') . '<br>'
        . '<b>Phone:</b> ' . esc($c['phone'] ?? '') . '<br>'
        . '<b>Topic:</b> ' . esc($c['subject'] ?? '') . '</p>'
        . '<div style="background:#F6F9FE;border-radius:12px;padding:16px;font-size:14px;color:#1A2433;white-space:pre-wrap">' . esc($c['message'] ?? '') . '</div></div>';
}

/* ---------- Phase 2: RBAC ---------- */
