function legal_notice_meta($ntype) {
    return [
        'eviction'    => ['en' => 'NOTICE TO QUIT', 'bn' => 'উচ্ছেদ নোটিশ', 'ref' => 'Premises Rent Control Act 1991 / PRCO 1985 — statutory notice period', 'days_key' => 'legal_eviction_days'],
        'rent_hike'   => ['en' => 'NOTICE OF RENT INCREASE', 'bn' => 'ভাড়া বৃদ্ধি নোটিশ', 'ref' => 'Premises Rent Control Act 1991 — rent increase notice', 'days_key' => 'legal_rent_hike_days'],
        'termination' => ['en' => 'NOTICE OF LEASE TERMINATION', 'bn' => 'লিজ সমাপ্তি নোটিশ', 'ref' => 'Transfer of Property Act 1882 §106 — termination notice', 'days_key' => 'legal_termination_days'],
        'tds_alert'   => ['en' => 'TDS DEDUCTION ADVISORY', 'bn' => 'টিডিএস কর্তন পরামর্শ', 'ref' => 'Income Tax Act 2023 §109 — TDS on rent', 'days_key' => 'legal_eviction_days'],
    ][$ntype] ?? null;
}
function legal_notice_build($pdo, $ntype, $lease) {
    $meta = legal_notice_meta($ntype);
    $cfg = legal_cfg_all($pdo);
    $days = (int)($cfg[$meta['days_key']] ?? 30);
    $t = tenantById($lease['t']); $u = unitById($lease['u']);
    $tname = $t['name'] ?? 'Tenant'; $uname = $u['name'] ?? $lease['u'];
    $rent = (int)$lease['rent'];
    $eff = date('Y-m-d', strtotime('+' . $days . ' days'));
    $addr = ($t['address'] ?? '') ?: ($t['phone'] ?? '');
    $lines = [];
    $lines[] = $meta['en'] . ' / ' . $meta['bn'];
    $lines[] = 'To: ' . $tname . ' (' . $addr . ')';
    $lines[] = 'Premises: ' . $uname . ' — Lease ' . $lease['id'] . ' (rent ৳' . number_format($rent) . '/month)';
    if ($ntype === 'eviction') {
        $lines[] = 'You are hereby given ' . $days . ' days notice to vacate the premises. Effective date: ' . $eff . '.';
        $lines[] = 'আপনাকে ' . $days . ' দিনের নোটিশ দেওয়া হল — নির্ধারিত তারিখের মধ্যে বাসস্থান খালি করুন। কার্যকর তারিখ: ' . $eff . '।';
    } elseif ($ntype === 'rent_hike') {
        $lines[] = 'Your monthly rent will increase effective ' . $eff . ' (notice period ' . $days . ' days).';
        $lines[] = 'আপনার মাসিক ভাড়া ' . $eff . ' থেকে বৃদ্ধি পাবে (নোটিশ সময়কাল ' . $days . ' দিন)।';
    } elseif ($ntype === 'termination') {
        $lines[] = 'Your lease will terminate effective ' . $eff . ' (notice period ' . $days . ' days). Please prepare for handover.';
        $lines[] = 'আপনার লিজ ' . $eff . ' থেকে সমাপ্ত হবে (নোটিশ সময়কাল ' . $days . ' দিন)। হ্যান্ডওভারের জন্য প্রস্তুত থাকুন।';
    } elseif ($ntype === 'tds_alert') {
        $rate = (int)$cfg['legal_tds_commercial'];
        $lines[] = 'This is a TDS advisory: ' . $rate . '% of gross rent (৳' . number_format(round($rent * $rate / 100)) . '/month) must be withheld and deposited under ITA 2023 §109.';
        $lines[] = 'টিডিএস পরামর্শ: মোট ভাড়ার ' . $rate . '% (মাসিক ৳' . number_format(round($rent * $rate / 100)) . ') কর্তন করে জমা দিতে হবে (আইটিএ ২০২৩ §১০৯)।';
    }
    return ['days' => $days, 'effective' => $eff, 'body' => implode("
", $lines), 'meta' => $meta];
}
function legal_notice_html($n, $lease, $cfg) {
    $meta = legal_notice_meta($n['ntype']);
    $t = tenantById($n['tenant']); $u = unitById($n['unit']);
    $rows = '';
    foreach ([
        ['Notice', $meta['en'] . ' / ' . $meta['bn']],
        ['To', ($t['name'] ?? $n['tenant']) . ($t['phone'] ? ' · ' . $t['phone'] : '')],
        ['Premises', ($u['name'] ?? $n['unit']) . ' — Lease ' . $n['lease']],
        ['Notice period', $n['notice_days'] . ' days'],
        ['Effective date', $n['effective_date']],
        ['Status', $n['status']],
        ['Issued by', $n['created_by']],
        ['Statutory reference', $meta['ref']],
    ] as $r) {
        $rows .= '<tr><td style="padding:8px 12px;border:1px solid #DDE3EC;background:#F7F9FC;font-size:12px;font-weight:700;width:200px">' . $r[0] . '</td><td style="padding:8px 12px;border:1px solid #DDE3EC;font-size:13px">' . esc($r[1]) . '</td></tr>';
    }
    $body = nl2br(esc($n['body']));
    return '<div style="font-family:Arial;max-width:820px;margin:0 auto;padding:20px">'
        . '<div style="margin-bottom:10px">' . print_brand_img() . '</div>'
        . '<div style="border-bottom:3px solid #2F80ED;padding-bottom:12px;margin-bottom:16px"><div style="font-size:20px;font-weight:800">' . esc($meta['en']) . '</div><div style="font-size:12px;color:#667">' . esc($meta['bn']) . ' — ' . esc($meta['ref']) . '</div></div>'
        . '<table style="border-collapse:collapse;width:100%">' . $rows . '</table>'
        . '<div style="margin-top:16px;padding:14px;border:1px solid #E4EAF3;border-radius:8px;background:#FAFCFE;font-size:13px;line-height:1.7">' . $body . '</div></div>';
}
