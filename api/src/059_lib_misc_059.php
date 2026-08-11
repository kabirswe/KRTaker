function lease_audit_all($pdo) {
    $leases = $pdo->query("SELECT id FROM leases WHERE status IN ('Active','Pending Registration')")->fetchAll(PDO::FETCH_ASSOC);
    $total = 0; $high = 0; $done = [];
    foreach ($leases as $l) {
        $a = lease_audit($pdo, $l['id']);
        if (!$a) continue;
        $total += $a['score'];
        $high += count(array_filter($a['findings'], fn($f) => $f['sev'] === 'high'));
        $done[] = $a;
    }
    return ['count' => count($done), 'total_score' => $total, 'avg' => $done ? (int)round($total / count($done)) : 0, 'high' => $high, 'items' => $done];
}
function tif_print_html($row) {
    $p = $row['payload'];
    $e = function ($v) { return htmlspecialchars((string)$v); };
    $thana = $row['thana'] ?: ($p['thana'] ?? '');
    $district = $row['district'] ?: ($p['district'] ?? '');
    $house = trim(($p['present_flat'] ?? '') . ' ' . ($p['present_road'] ?? ''));
    $area = trim($p['present_area'] ?? '');
    $presentAddr = trim(implode(', ', array_filter([$p['present_flat'] ?? '', $p['present_road'] ?? '', $p['present_area'] ?? ''])));
    $permAddr = trim($p['permanent_address'] ?? '');
    $family = (int)($p['family_count'] ?? 0);
    $famCount = $family > 0 ? $family : 4;

    /* one fill row */
    $line = function ($label, $val = '', $labelStyle = '') use ($e) {
        return '<tr><td class="lbl" style="' . $labelStyle . '">' . $e($label) . '</td><td class="fill">'
            . ($val !== '' ? '<span class="v">' . $e($val) . '</span>' : '') . '</td></tr>';
    };
    /* two fill columns in one row */
    $two = function ($l1, $v1, $l2, $v2) use ($e) {
        return '<tr><td class="lbl" style="width:32%">' . $e($l1) . '</td><td class="fill" style="width:18%">'
            . ($v1 !== '' ? '<span class="v">' . $e($v1) . '</span>' : '')
            . '</td><td class="lbl" style="width:32%">' . $e($l2) . '</td><td class="fill" style="width:18%">'
            . ($v2 !== '' ? '<span class="v">' . $e($v2) . '</span>' : '') . '</td></tr>';
    };

    $rows = '';
    $rows .= $line('১. ভাড়াটিয়া/বাড়ীওয়ালার নাম', $p['name'] ?? '');
    $rows .= $line('২. পিতার নাম', $p['father'] ?? '');
    $rows .= $two('৩. জন্ম তারিখ', $p['dob'] ?? '', 'বৈবাহিক অবস্থা', '');
    $rows .= $line('৪. স্থায়ী ঠিকানা', $permAddr);
    $rows .= $line('৫. পেশা ও প্রতিষ্ঠান/কর্মস্থলের ঠিকানা', trim(implode(' — ', array_filter([$p['profession'] ?? '', $p['employer'] ?? '']))));
    $rows .= $two('৬. ধর্ম', '', 'শিক্ষাগত যোগ্যতা', '');
    $rows .= $two('৭. মোবাইল নম্বর', $p['phone'] ?? '', 'ই-মেইল আইডি', '');
    $rows .= $line('৮. জাতীয় পরিচয়পত্র নম্বর', $p['nid'] ?? '');
    $rows .= $line('৯. পাসপোর্ট নম্বর (যদি থাকে)', $p['passport'] ?? '');
    $rows .= $line('১০. জরুরী যোগাযোগ', '');
    $rows .= $two('(ক) নাম', $p['spouse'] ?? '', '(খ) সম্পর্ক', '');
    $rows .= $two('(গ) ঠিকানা', $permAddr, '(ঘ) মোবাইল নম্বর', $p['spouse_phone'] ?? '');

    /* ১১ — family table */
    $famRows = '';
    for ($i = 1; $i <= $famCount; $i++) {
        $famRows .= '<tr><td class="fill" style="text-align:center">' . $i . '</td><td class="fill"></td><td class="fill"></td><td class="fill"></td><td class="fill"></td></tr>';
    }
    $rows .= '<tr><td class="lbl" style="vertical-align:top">১১. পরিবার / মেসের সঙ্গীয় সদস্যদের বিবরণ</td><td class="fill" style="padding:0">'
        . '<table class="sub"><tr><th style="width:8%">ক্রঃনং</th><th style="width:32%">নাম</th><th style="width:14%">বয়স</th><th style="width:22%">পেশা</th><th style="width:24%">মোবাইল নম্বর</th></tr>'
        . $famRows . '</table></td></tr>';

    $rows .= $two('১২. গৃহকর্মীর নাম', '', 'জাতীয় পরিচয়পত্র নং', '');
    $rows .= $two('১৩. ড্রাইভারের নাম', $p['driver_name'] ?? '', 'জাতীয় পরিচয়পত্র নং', '');
    $rows .= $two('মোবাইল নম্বর', '', 'স্থায়ী ঠিকানা', '');
    $rows .= $two('১৪. পূর্ববর্তী বাড়িওয়ালার নাম', $p['prev_landlord'] ?? '', 'মোবাইল নম্বর', '');
    $rows .= $line('ঠিকানা', '');
    $rows .= $line('১৫. পূর্ববর্তী বাসা ছাড়ার কারণ', $p['prev_reason'] ?? '');
    $rows .= $two('১৬. বর্তমান বাড়িওয়ালার নাম', $p['landlord_name'] ?? '', 'মোবাইল নম্বর', $p['landlord_phone'] ?? '');
    $rows .= $line('১৭. বর্তমান বাড়ীতে কোন তারিখ থেকে বসবাস', $p['move_in'] ?? '');

    /* supplementary data the app holds that the official form omits */
    $extra = [];
    if (trim($p['mother'] ?? '') !== '') $extra[] = '<b>মাতার নাম:</b> ' . $e($p['mother']);
    if ($presentAddr !== '') $extra[] = '<b>বর্তমান ঠিকানা:</b> ' . $e($presentAddr);
    if (trim($p['landlord_nid'] ?? '') !== '') $extra[] = '<b>মালিকের এনআইডি:</b> ' . $e($p['landlord_nid']);
    if (trim($p['lease_term'] ?? '') !== '') $extra[] = '<b>লিজের মেয়াদ:</b> ' . $e($p['lease_term']) . ' মাস';
    if (trim($p['vehicle'] ?? '') !== '') $extra[] = '<b>গাড়ি:</b> ' . $e($p['vehicle']);
    if (trim($p['ref1_name'] ?? '') !== '') $extra[] = '<b>রেফারেন্স ১:</b> ' . $e($p['ref1_name']) . ($p['ref1_phone'] ? ' — ' . $e($p['ref1_phone']) : '') . ($p['ref1_address'] ? ', ' . $e($p['ref1_address']) : '');
    if (trim($p['ref2_name'] ?? '') !== '') $extra[] = '<b>রেফারেন্স ২:</b> ' . $e($p['ref2_name']) . ($p['ref2_phone'] ? ' — ' . $e($p['ref2_phone']) : '') . ($p['ref2_address'] ? ', ' . $e($p['ref2_address']) : '');
    if (trim($p['remarks'] ?? '') !== '') $extra[] = '<b>মন্তব্য:</b> ' . $e($p['remarks']);
    $extraHtml = $extra ? '<div class="extra"><div class="extra-t">অতিরিক্ত তথ্য / Additional information</div><div style="padding:4px 6px;font-size:11px;line-height:1.7">' . implode('<br>', $extra) . '</div></div>' : '';

    return '<style>
        @page { size: A4 portrait; margin: 10mm 12mm; }
        body { font-family: "Kalpurush","SolaimanLipi","Nikosh","Noto Sans Bengali","Arial",sans-serif; color:#111; font-size:12px; line-height:1.45; margin:0; padding:0; background:#fff; }
        .wrap { width: 188mm; margin:0 auto; }
        table.grid { border-collapse: collapse; width:100%; }
        table.grid td, table.grid th { border:1px solid #000; padding:4px 7px; vertical-align:middle; }
        td.lbl { background:#F5F5F5; font-weight:700; width:42%; }
        td.fill { min-height:26px; height:26px; }
        td.fill .v { font-weight:600; border-bottom:1px dotted #444; padding:0 2px; }
        td.fill:empty::after { content:"\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0\00a0"; text-decoration:underline; text-decoration-style:dotted; color:transparent; }
        table.sub { border-collapse: collapse; width:100%; }
        table.sub th, table.sub td { border:1px solid #000; padding:3px 5px; font-size:11px; }
        table.sub th { background:#EDEDED; }
        .head { border:2px solid #000; border-collapse: collapse; width:100%; margin-bottom:0; }
        .head td { border:1px solid #000; padding:5px 8px; }
        .police { text-align:center; font-size:19px; font-weight:700; letter-spacing:1px; }
        .police-sub { text-align:center; font-size:9px; letter-spacing:2.5px; color:#333; }
        .copyline { font-size:10px; font-weight:700; text-align:center; }
        .photobox { width:96px; height:112px; border:1.5px dashed #000; text-align:center; font-size:10px; color:#333; display:flex; align-items:center; justify-content:center; margin:0 auto; }
        .title { text-align:center; font-size:21px; font-weight:800; letter-spacing:1px; padding:8px 0 6px; border-bottom:2px solid #000; }
        .title-sub { text-align:center; font-size:9px; letter-spacing:2px; color:#333; }
        .sigrow { display:flex; justify-content:space-between; margin-top:14px; font-size:12px; font-weight:700; }
        .sigbox { border-bottom:1px solid #000; width:60mm; height:24px; }
        .hdline { display:inline-block; min-width:70px; border-bottom:1px dotted #444; font-weight:600; }
        .note { margin-top:12px; font-size:10.5px; border:1px solid #000; padding:4px 8px; }
        .footer { margin-top:8px; font-size:9px; color:#555; text-align:center; }
        .extra { margin-top:12px; border:1px solid #000; }
        .extra-t { background:#EDEDED; font-weight:700; font-size:11px; padding:3px 6px; border-bottom:1px solid #000; }
    </style>
    <div class="wrap">
        <table class="head">
            <tr>
                <td style="width:34%;text-align:center;border-right:1px solid #000">
                    <div class="copyline">ভাড়াটিয়ার এক কপি</div>
                    <div style="font-size:9px;color:#333">(Tenant’s copy)</div>
                    <div style="margin-top:6px;text-align:left;font-size:11px">
                        বিভাগ: <span class="hdline">' . $e($district) . '</span><br>
                        থানা: <span class="hdline">' . $e($thana) . '</span><br>
                        বাড়ী/হোল্ডিং: <span class="hdline">' . $e($house) . '</span><br>
                        এলাকা: <span class="hdline">' . $e($area) . '</span>
                    </div>
                </td>
                <td style="width:32%;text-align:center">
                    <div class="police">ঢাকা মেট্রোপলিটন পুলিশ</div>
                    <div class="police-sub">DHAKA METROPOLITAN POLICE</div>
                    <div style="margin-top:4px;font-size:10px;color:#333">গণপ্রজাতন্ত্রী বাংলাদেশ সরকার</div>
                    <div style="font-size:9px;color:#333">People’s Republic of Bangladesh</div>
                </td>
                <td style="width:34%;text-align:center">
                    <div class="photobox">পাসপোর্ট সাইজ ছবি<br><span style="font-size:8px">(Passport photo)</span></div>
                </td>
            </tr>
        </table>
        <div class="title">ভাড়াটিয়া নিবন্ধন ফরম<div class="title-sub">TENANT REGISTRATION FORM</div></div>
        <table class="grid">
            ' . $rows . '
        </table>
        <div class="sigrow">
            <div>তারিখ: <span class="sigbox"></span></div>
            <div>ভাড়াটিয়ার স্বাক্ষর: <span class="sigbox"></span></div>
        </div>
        <div class="note">বিঃদ্রঃ এই ফরমের একটি কপি বাড়ির মালিক অবশ্যই সংরক্ষণ করবেন।<br><span style="font-weight:400">Note: The landlord must preserve a copy of this form.</span></div>
        ' . $extraHtml . '
        <div class="footer">' . $e($row['id']) . ' · ' . $e($row['status']) . ' · KRTaker Trust Engine · Generated ' . date('Y-m-d H:i') . '</div>
    </div>';
}
function trust_summary($pdo) {
    $verified = (int)$pdo->query("SELECT COUNT(*) FROM nid_verifications WHERE status='verified'")->fetchColumn();
    $totalNv = (int)$pdo->query('SELECT COUNT(*) FROM nid_verifications')->fetchColumn();
    $draft = (int)$pdo->query("SELECT COUNT(*) FROM thana_forms WHERE status='Draft'")->fetchColumn();
    $submitted = (int)$pdo->query("SELECT COUNT(*) FROM thana_forms WHERE status='Submitted'")->fetchColumn();
    $verifiedTf = (int)$pdo->query("SELECT COUNT(*) FROM thana_forms WHERE status='Verified'")->fetchColumn();
    $tenantsWithLease = (int)$pdo->query("SELECT COUNT(DISTINCT t) FROM leases WHERE status IN ('Active','Pending Registration')")->fetchColumn();
    $forms = (int)$pdo->query('SELECT COUNT(*) FROM thana_forms')->fetchColumn();
    $needsForm = max(0, $tenantsWithLease - $forms);
    return [
        'nid_verified' => $verified, 'nid_total' => $totalNv,
        'tf_draft' => $draft, 'tf_submitted' => $submitted, 'tf_verified' => $verifiedTf,
        'tenants_with_lease' => $tenantsWithLease, 'forms_total' => $forms, 'needs_form' => $needsForm,
    ];
}
/* ---------- Phase 40: AI caretaker v2 — WhatsApp + proactive alert engine ---------- *//* ---------- Phase 45: Land/Plot Guard — anti-encroachment monitoring helpers ---------- */
function lp_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'LP-','') AS INTEGER)) FROM land_parcels")->fetchColumn();
    return 'LP-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function lv_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'LV-','') AS INTEGER)) FROM land_visits")->fetchColumn();
    return 'LV-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function lm_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'LM-','') AS INTEGER)) FROM land_media")->fetchColumn();
    return 'LM-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function le_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'LE-','') AS INTEGER)) FROM land_events")->fetchColumn();
    return 'LE-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function land_monitor_months($monitor) {
    return ['quarterly' => 3, 'semi_annual' => 6, 'annual' => 12][$monitor] ?? 0;
}
function land_next_due($parcel, $lastCompletedAt) {
    $months = land_monitor_months($parcel['monitor'] ?? 'quarterly');
    if ($months <= 0) return '';
    $base = $lastCompletedAt ?: (($parcel['ts'] ?? '') ?: date('Y-m-d'));
    return date('Y-m-d', strtotime(substr($base, 0, 10) . " +$months months"));
}
function land_enrich($pdo, $rows) {
    $out = [];
    foreach ($rows as $k => $r) {
        $st = $pdo->prepare("SELECT id, status, completed_at, outcome FROM land_visits WHERE parcel=? AND status='Completed' ORDER BY completed_at DESC LIMIT 1");
        $st->execute([$r['id']]);
        $last = $st->fetch(PDO::FETCH_ASSOC);
        $r['last_visit'] = $last ? $last['id'] : '';
        $r['last_visit_at'] = $last ? substr($last['completed_at'], 0, 10) : '';
        $r['last_outcome'] = $last ? $last['outcome'] : '';
        /* pending scheduled visit wins (missed visits => overdue) */
        $st = $pdo->prepare("SELECT id, scheduled_for, status FROM land_visits WHERE parcel=? AND status IN ('Scheduled','In Progress') ORDER BY scheduled_for ASC LIMIT 1");
        $st->execute([$r['id']]);
        $pend = $st->fetch(PDO::FETCH_ASSOC);
        $today = date('Y-m-d');
        if ($pend) {
            $r['next_visit'] = $pend['id'];
            $r['visit_status'] = $pend['status'];
            $r['next_due'] = substr($pend['scheduled_for'], 0, 10);
            $r['due_days'] = (int)floor((strtotime($r['next_due']) - strtotime($today)) / 86400);
        } else {
            $r['next_visit'] = '';
            $r['visit_status'] = '';
            $r['next_due'] = land_next_due($r, $r['last_visit_at']);
            $r['due_days'] = $r['next_due'] ? (int)floor((strtotime($r['next_due']) - strtotime($today)) / 86400) : null;
        }
        $r['coverage'] = ($r['monitor'] ?? 'quarterly') !== 'none';
        $st = $pdo->prepare('SELECT COUNT(*) FROM land_media m JOIN land_visits v ON v.id=m.visit WHERE v.parcel=?'); $st->execute([$r['id']]);
        $r['media_count'] = (int)$st->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM land_events WHERE parcel=?'); $st->execute([$r['id']]);
        $r['events_count'] = (int)$st->fetchColumn();
        $out[] = $r;
    }
    return $out;
}
function land_all_ids($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT id FROM land_parcels WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
    } else {
        $st = $pdo->query('SELECT id FROM land_parcels ORDER BY ts DESC');
    }
    return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id');
}
function land_rows($pdo, $ids) {
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM land_parcels WHERE id IN ($in) ORDER BY ts DESC");
    $st->execute($ids);
    return land_enrich($pdo, $st->fetchAll(PDO::FETCH_ASSOC));
}
function land_event($pdo, $parcelId, $evType, $body, $actor) {
    $id = le_next_id($pdo);
    $pdo->prepare('INSERT INTO land_events (id, parcel, ev_type, body, actor) VALUES (?,?,?,?,?)')->execute([$id, $parcelId, $evType, $body, $actor]);
    return $id;
}
function land_alerts($rows) {
    $out = [];
    foreach ($rows as $p) {
        if ($p['status'] === 'Encroached') $out[] = ['kind' => 'encroached', 'parcel' => $p['id'], 'name' => $p['name'], 'days' => null];
        elseif ($p['due_days'] !== null && $p['due_days'] < 0) $out[] = ['kind' => 'overdue', 'parcel' => $p['id'], 'name' => $p['name'], 'days' => $p['due_days']];
        elseif ($p['due_days'] !== null && $p['due_days'] <= 30) $out[] = ['kind' => 'due_soon', 'parcel' => $p['id'], 'name' => $p['name'], 'days' => $p['due_days']];
    }
    return $out;
}
function land_report_html($parcel, $visits, $media) {
    $statusBadge = ['Secure' => '#27AE60', 'Needs Review' => '#F0B429', 'Encroached' => '#EB5757', 'Under Monitoring' => '#2F80ED'][$parcel['status']] ?? '#8A94A6';
    $rowItem = function ($label, $val) {
        return '<tr><td style="padding:8px 12px;border:1px solid #DDE3EC;background:#F7F9FC;font-size:12px;font-weight:700;color:#445;width:230px">' . htmlspecialchars($label) . '</td>'
            . '<td style="padding:8px 12px;border:1px solid #DDE3EC;font-size:13px">' . htmlspecialchars((string)$val) . '</td></tr>';
    };
    $rows = '';
    foreach ([
        'Parcel / প্লট' => $parcel['name'],
        'District / জেলা' => $parcel['district'],
        'Upazila / উপজেলা' => $parcel['upazila'],
        'Mouza / মৌজা' => $parcel['mouza'],
        'Khatian / খতিয়ান' => $parcel['khatian'],
        'DAG / দাগ' => $parcel['dag'],
        'Area / আয়তন' => $parcel['area'],
        'Geo (lat,lng) / স্থানাঙ্ক' => $parcel['lat'] ? ($parcel['lat'] . ', ' . $parcel['lng']) : '',
        'Monitoring plan / মনিটরিং পরিকল্পনা' => $parcel['monitor'],
        'Next visit due / পরবর্তী পরিদর্শন' => $parcel['next_due'] ?: '—',
    ] as $k => $v) $rows .= $rowItem($k, $v);
    $vt = '';
    foreach ($visits as $v) {
        $vt .= '<tr>'
            . '<td style="padding:8px;border:1px solid #DDE3EC;font-size:12px">' . htmlspecialchars($v['id']) . '</td>'
            . '<td style="padding:8px;border:1px solid #DDE3EC;font-size:12px">' . htmlspecialchars($v['visit_type']) . '</td>'
            . '<td style="padding:8px;border:1px solid #DDE3EC;font-size:12px">' . htmlspecialchars($v['agent']) . '</td>'
            . '<td style="padding:8px;border:1px solid #DDE3EC;font-size:12px">' . htmlspecialchars(substr($v['scheduled_for'], 0, 10)) . '</td>'
            . '<td style="padding:8px;border:1px solid #DDE3EC;font-size:12px">' . htmlspecialchars($v['status']) . '</td>'
            . '<td style="padding:8px;border:1px solid #DDE3EC;font-size:12px">' . htmlspecialchars($v['outcome']) . '</td></tr>';
    }
    $mt = '';
    foreach ($media as $m) {
        $mt .= '<tr>'
            . '<td style="padding:8px;border:1px solid #DDE3EC;font-size:12px">' . htmlspecialchars($m['id']) . '</td>'
            . '<td style="padding:8px;border:1px solid #DDE3EC;font-size:12px">' . htmlspecialchars($m['kind']) . '</td>'
            . '<td style="padding:8px;border:1px solid #DDE3EC;font-size:12px">' . htmlspecialchars($m['name']) . '</td>'
            . '<td style="padding:8px;border:1px solid #DDE3EC;font-size:12px">' . htmlspecialchars($m['geo']) . '</td>'
            . '<td style="padding:8px;border:1px solid #DDE3EC;font-size:12px">' . htmlspecialchars($m['captured_at']) . '</td></tr>';
    }
    return '<div style="font-family:Arial,Helvetica,sans-serif;color:#223;max-width:860px;margin:0 auto;padding:20px">'
        . '<div style="display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid #2F80ED;padding-bottom:12px;margin-bottom:16px">'
        . '<div><div style="font-size:20px;font-weight:800">Land Guard Report / ভূমি গার্ড রিপোর্ট</div>'
        . '<div style="font-size:12px;color:#667">' . htmlspecialchars($parcel['name']) . ' — ' . htmlspecialchars($parcel['id']) . '</div></div>'
        . '<div style="text-align:right"><div style="display:inline-block;padding:4px 12px;border-radius:999px;color:#fff;background:' . $statusBadge . ';font-size:11px;font-weight:700">' . htmlspecialchars($parcel['status']) . '</div></div></div>'
        . '<table style="border-collapse:collapse;width:100%">' . $rows . '</table>'
        . '<h3 style="font-size:14px;margin:18px 0 8px">Visit history / পরিদর্শন ইতিহাস</h3>'
        . '<table style="border-collapse:collapse;width:100%"><tr style="background:#F7F9FC">'
        . '<th style="padding:8px;border:1px solid #DDE3EC;font-size:11px;text-align:left">ID</th>'
        . '<th style="padding:8px;border:1px solid #DDE3EC;font-size:11px;text-align:left">Type</th>'
        . '<th style="padding:8px;border:1px solid #DDE3EC;font-size:11px;text-align:left">Agent</th>'
        . '<th style="padding:8px;border:1px solid #DDE3EC;font-size:11px;text-align:left">Scheduled</th>'
        . '<th style="padding:8px;border:1px solid #DDE3EC;font-size:11px;text-align:left">Status</th>'
        . '<th style="padding:8px;border:1px solid #DDE3EC;font-size:11px;text-align:left">Outcome</th></tr>'
        . ($vt ?: '<tr><td colspan="6" style="padding:8px;border:1px solid #DDE3EC;font-size:12px;color:#99A">No visits yet</td></tr>') . '</table>'
        . '<h3 style="font-size:14px;margin:18px 0 8px">Proof media / প্রমাণ মিডিয়া</h3>'
        . '<table style="border-collapse:collapse;width:100%"><tr style="background:#F7F9FC">'
        . '<th style="padding:8px;border:1px solid #DDE3EC;font-size:11px;text-align:left">ID</th>'
        . '<th style="padding:8px;border:1px solid #DDE3EC;font-size:11px;text-align:left">Kind</th>'
        . '<th style="padding:8px;border:1px solid #DDE3EC;font-size:11px;text-align:left">File</th>'
        . '<th style="padding:8px;border:1px solid #DDE3EC;font-size:11px;text-align:left">Geo</th>'
        . '<th style="padding:8px;border:1px solid #DDE3EC;font-size:11px;text-align:left">Captured</th></tr>'
        . ($mt ?: '<tr><td colspan="5" style="padding:8px;border:1px solid #DDE3EC;font-size:12px;color:#99A">No media yet</td></tr>') . '</table>'
        . '<div style="margin-top:18px;display:flex;justify-content:space-between;gap:30px">'
        . '<div style="font-size:12px;color:#667">Field agent / ড্রোন অপারেটর</div>'
        . '<div style="font-size:12px;color:#667">Owner / মালিক</div></div>'
        . '<div style="margin-top:26px;border-top:1px solid #DDE3EC;padding-top:8px;font-size:10.5px;color:#99A">KRTaker Land Guard · Generated ' . date('Y-m-d H:i') . ' · ' . htmlspecialchars($parcel['id']) . '</div></div>';
}


/* ---------- Phase 46: NRB Hub helpers ---------- */
function nrb_tx_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'TX-','') AS INTEGER)) FROM nrb_tax_returns")->fetchColumn();
    return 'TX-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function nrb_rp_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'RP-','') AS INTEGER)) FROM nrb_repatriations")->fetchColumn();
    return 'RP-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function nrb_vc_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'VC-','') AS INTEGER)) FROM nrb_vacancies")->fetchColumn();
    return 'VC-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function nrb_sh_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'SH-','') AS INTEGER)) FROM nrb_showings")->fetchColumn();
    return 'SH-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function nrb_ds_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'DS-','') AS INTEGER)) FROM nrb_disputes")->fetchColumn();
    return 'DS-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function nrb_tax_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM nrb_tax_returns WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM nrb_tax_returns ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function nrb_rp_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM nrb_repatriations WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM nrb_repatriations ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function nrb_vc_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM nrb_vacancies WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM nrb_vacancies ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function nrb_sh_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT s.* FROM nrb_showings s JOIN nrb_vacancies v ON v.id=s.vacancy WHERE v.owner_email=? OR v.owner_email='' ORDER BY s.scheduled_at DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM nrb_showings ORDER BY scheduled_at DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function nrb_ds_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM nrb_disputes WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM nrb_disputes ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function nrb_vc_enrich($pdo, $rows) {
    $units = []; $props = [];
    foreach ($pdo->query('SELECT id,name,p FROM units')->fetchAll(PDO::FETCH_ASSOC) as $u2) $units[$u2['id']] = $u2;
    foreach ($pdo->query('SELECT id,name FROM properties')->fetchAll(PDO::FETCH_ASSOC) as $pr) $props[$pr['id']] = $pr;
    $sc = $pdo->prepare('SELECT COUNT(*) FROM nrb_showings WHERE vacancy=?');
    foreach ($rows as $k => $v) {
        $sc->execute([$v['id']]);
        $rows[$k]['unit_name'] = $units[$v['unit']]['name'] ?? '';
        $rows[$k]['prop_name'] = $props[$v['prop']]['name'] ?? '';
        $rows[$k]['showings'] = (int)$sc->fetchColumn();
        $rows[$k]['channels'] = json_decode($v['channels'] ?: '[]', true) ?: [];
        $rows[$k]['candidate'] = json_decode($v['candidate'] ?: '{}', true) ?: [];
        $rows[$k]['days_in_status'] = $v['updated_at'] ? (int)floor((time() - strtotime($v['updated_at'])) / 86400) : 0;
    }
    return $rows;
}
function nrb_ds_enrich($pdo, $rows) {
    foreach ($rows as $k => $v) {
        $rows[$k]['timeline'] = json_decode($v['timeline'] ?: '[]', true) ?: [];
        $rows[$k]['days_open'] = $v['ts'] ? (int)floor((time() - strtotime($v['ts'])) / 86400) : 0;
    }
    return $rows;
}
function nrb_ds_event($pdo, $id, $action, $by) {
    $st = $pdo->prepare('SELECT timeline FROM nrb_disputes WHERE id=?'); $st->execute([$id]);
    $tl = json_decode((string)$st->fetchColumn(), true) ?: [];
    $tl[] = ['ts' => date('Y-m-d H:i:s'), 'action' => $action, 'by' => $by];
    $pdo->prepare("UPDATE nrb_disputes SET timeline=?, updated_at=datetime('now') WHERE id=?")
        ->execute([json_encode($tl, JSON_UNESCAPED_UNICODE), $id]);
}
function nrb_config_rows($pdo) {
    $def = ['default_ca_firm' => 'Ahmed & Co. Chartered Accountants', 'default_advocate' => 'Adv. Salma Khatun', 'vacancy_alert_days' => 30, 'dispute_alert_days' => 14];
    foreach (array_keys($def) as $k) {
        $st = $pdo->prepare('SELECT v FROM org_settings WHERE k=?'); $st->execute(['nrb_' . $k]);
        $v = $st->fetchColumn();
        if ($v !== false) $def[$k] = ($k === 'vacancy_alert_days' || $k === 'dispute_alert_days') ? (int)$v : $v;
    }
    return $def;
}
function nrb_config_save($pdo, $cfg) {
    $def = ['default_ca_firm' => '', 'default_advocate' => '', 'vacancy_alert_days' => 30, 'dispute_alert_days' => 14];
    $out = [];
    foreach (array_keys($def) as $k) {
        if (!array_key_exists($k, $cfg)) continue;
        $v = $cfg[$k];
        if ($k === 'vacancy_alert_days' || $k === 'dispute_alert_days') $v = max(1, min(120, (int)$v));
        else $v = trim((string)$v);
        $pdo->prepare("INSERT INTO org_settings (k, v, updated_at) VALUES (?,?,datetime('now')) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')")->execute(['nrb_' . $k, $v]);
        $out[$k] = $v;
    }
    return $out;
}
function nrb_vacancy_approve($pdo, $u, $vc) {
    $cand = json_decode($vc['candidate'] ?: '{}', true) ?: [];
    if (empty($cand['name']) || ($vc['vet_status'] ?? '') !== 'passed')
        return ['ok' => false, 'error' => 'Candidate + passed vetting required before approval.'];
    $st = $pdo->prepare('SELECT * FROM units WHERE id=?'); $st->execute([$vc['unit']]);
    $urow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$urow) return ['ok' => false, 'error' => 'Unit not found.'];
    if ($urow['status'] === 'Leased') return ['ok' => false, 'error' => 'Unit already leased.'];
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'T-','') AS INTEGER)) FROM tenants")->fetchColumn();
    $tid = 'T-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
    $temail = trim($cand['email'] ?? '');
    $pdo->prepare('INSERT INTO tenants (id, name, phone, email, nid, nrb, kind, sub_email) VALUES (?,?,?,?,?,0,?,?)')
        ->execute([$tid, $cand['name'], trim($cand['phone'] ?? ''), $temail, trim($cand['nid'] ?? ''), 'Individual', $temail ?: null]);
    $start = trim($cand['start'] ?? '') ?: date('Y-m-d');
    $d = new DateTime($start);
    $d->modify('+' . max(1, min(36, (int)($cand['months'] ?? 12))) . ' months');
    $end = $d->format('Y-m-d');
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'L-','') AS INTEGER)) FROM leases")->fetchColumn();
    $lid = 'L-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO leases (id, u, t, start, end, rent, adv, res, reg_office, reg_deed, status) VALUES (?,?,?,?,?,?,?,1,?,?,?)')
        ->execute([$lid, $vc['unit'], $tid, $start, $end, (int)($cand['rent'] ?? $vc['target_rent']), (int)($cand['advance'] ?? 0), '', '', 'Active']);
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'HOV-','') AS INTEGER)) FROM handover_checklists")->fetchColumn();
    $hid = 'HOV-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO handover_checklists (id, lease, kind, items, status, created_by) VALUES (?,?,?,?,?,?)')
        ->execute([$hid, $lid, 'move_in', json_encode(HOVO_ITEMS('move_in'), JSON_UNESCAPED_UNICODE), 'In Progress', $u['name']]);
    $iid = invoice_next_id($pdo);
    $rent = (int)($cand['rent'] ?? $vc['target_rent']);
    $pdo->prepare('INSERT INTO invoices (id, l, m, gross, tds, net, status) VALUES (?,?,?,?,0,?,?)')
        ->execute([$iid, $lid, substr($start, 0, 7), $rent, $rent, 'Unpaid']);
    $pdo->prepare("UPDATE units SET status='Leased' WHERE id=?")->execute([$vc['unit']]);
    $pdo->prepare("UPDATE nrb_vacancies SET status='Filled', approved_at=datetime('now'), updated_at=datetime('now') WHERE id=?")->execute([$vc['id']]);
    return ['ok' => true, 'tenant' => $tid, 'lease' => $lid, 'handover' => $hid, 'invoice' => $iid, 'text' => 'Lease signed 🎉 — ' . $cand['name'] . ' (' . $tid . ') · ' . $lid . ' · first invoice ' . $iid . ' (৳' . number_format($rent) . ')'];
}
/* ---------- Phase 47: Legal Concierge helpers ---------- */
function concierge_service_list() { return ['namjari', 'e_porcha', 'khatian', 'holding_tax', 'registration']; }
function concierge_seq() { return ['Submitted', 'Under_Review', 'Docs_Requested', 'In_Progress', 'Awaiting_Fee', 'Completed']; }
function cr_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'CR-','') AS INTEGER)) FROM concierge_requests")->fetchColumn();
    return 'CR-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function cd_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'CD-','') AS INTEGER)) FROM concierge_docs")->fetchColumn();
    return 'CD-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function ht_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'HT-','') AS INTEGER)) FROM holding_taxes")->fetchColumn();
    return 'HT-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function cr_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM concierge_requests WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM concierge_requests ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function cr_enrich($pdo, $rows) {
    $parcels = []; $props = [];
    foreach ($pdo->query('SELECT id,name FROM land_parcels')->fetchAll(PDO::FETCH_ASSOC) as $p2) $parcels[$p2['id']] = $p2['name'];
    foreach ($pdo->query('SELECT id,name FROM properties')->fetchAll(PDO::FETCH_ASSOC) as $pr) $props[$pr['id']] = $pr['name'];
    $dc = $pdo->prepare('SELECT COUNT(*) FROM concierge_docs WHERE request=?');
    foreach ($rows as $k => $v) {
        $dc->execute([$v['id']]);
        $rows[$k]['parcel_name'] = $parcels[$v['parcel']] ?? '';
        $rows[$k]['prop_name'] = $props[$v['prop']] ?? '';
        $rows[$k]['docs_count'] = (int)$dc->fetchColumn();
        $rows[$k]['timeline'] = json_decode($v['timeline'] ?: '[]', true) ?: [];
        $rows[$k]['days_in_status'] = $v['updated_at'] ? (int)floor((time() - strtotime($v['updated_at'])) / 86400) : 0;
        $rows[$k]['service_label'] = ucwords(str_replace('_', ' ', $v['service']));
    }
    return $rows;
}
function cr_event($pdo, $id, $action, $by) {
    $st = $pdo->prepare('SELECT timeline FROM concierge_requests WHERE id=?'); $st->execute([$id]);
    $tl = json_decode((string)$st->fetchColumn(), true) ?: [];
    $tl[] = ['ts' => date('Y-m-d H:i:s'), 'action' => $action, 'by' => $by];
    $pdo->prepare("UPDATE concierge_requests SET timeline=?, updated_at=datetime('now') WHERE id=?")
        ->execute([json_encode($tl, JSON_UNESCAPED_UNICODE), $id]);
}
function cd_rows($pdo, $requestId, $u) {
    $st = $pdo->prepare('SELECT * FROM concierge_docs WHERE request=? ORDER BY ts DESC');
    $st->execute([$requestId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($u['role'] === 'owner') {
        $q = $pdo->prepare('SELECT * FROM concierge_requests WHERE id=?'); $q->execute([$requestId]);
        $cr = $q->fetch(PDO::FETCH_ASSOC);
        if (!$cr || ($cr['owner_email'] !== $u['email'] && $cr['owner_email'] !== '')) return [];
    }
    return $rows;
}
function holding_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM holding_taxes WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM holding_taxes ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function holding_enrich($pdo, $rows) {
    $parcels = []; $props = [];
    foreach ($pdo->query('SELECT id,name FROM land_parcels')->fetchAll(PDO::FETCH_ASSOC) as $p2) $parcels[$p2['id']] = $p2['name'];
    foreach ($pdo->query('SELECT id,name FROM properties')->fetchAll(PDO::FETCH_ASSOC) as $pr) $props[$pr['id']] = $pr['name'];
    $today = date('Y-m-d');
    foreach ($rows as $k => $v) {
        $rows[$k]['parcel_name'] = $parcels[$v['parcel']] ?? '';
        $rows[$k]['prop_name'] = $props[$v['prop']] ?? '';
        $eff = $v['status'];
        if ($eff === 'Due' && $v['due_date'] && $v['due_date'] < $today) $eff = 'Overdue';
        $rows[$k]['status'] = $eff;
        $rows[$k]['due_in'] = $v['due_date'] ? (int)floor((strtotime($v['due_date']) - time()) / 86400) : null;
    }
    return $rows;
}
function concierge_config_rows($pdo) {
    $def = ['concierge_agent' => 'Mithila Rahman', 'namjari_fee' => 5000, 'e_porcha_fee' => 2500, 'khatian_fee' => 3000, 'holding_tax_fee' => 1500, 'registration_fee' => 10000, 'namjari_days' => 45, 'e_porcha_days' => 15, 'khatian_days' => 20, 'holding_tax_days' => 10, 'registration_days' => 30, 'docs_stuck_days' => 7, 'holding_alert_days' => 30];
    $intKeys = ['namjari_fee','e_porcha_fee','khatian_fee','holding_tax_fee','registration_fee','namjari_days','e_porcha_days','khatian_days','holding_tax_days','registration_days','docs_stuck_days','holding_alert_days'];
    foreach (array_keys($def) as $k) {
        $st = $pdo->prepare('SELECT v FROM org_settings WHERE k=?'); $st->execute(['concierge_' . $k]);
        $v = $st->fetchColumn();
        if ($v !== false) $def[$k] = in_array($k, $intKeys, true) ? (int)$v : $v;
    }
    return $def;
}
function concierge_config_save($pdo, $cfg) {
    $def = concierge_config_rows($pdo);
    $feeKeys = ['namjari_fee', 'e_porcha_fee', 'khatian_fee', 'holding_tax_fee', 'registration_fee'];
    $dayKeys = ['namjari_days', 'e_porcha_days', 'khatian_days', 'holding_tax_days', 'registration_days'];
    $alertKeys = ['docs_stuck_days', 'holding_alert_days'];
    $out = [];
    foreach (array_keys($def) as $k) {
        if (!array_key_exists($k, $cfg)) continue;
        $v = $cfg[$k];
        if (in_array($k, $feeKeys, true)) $v = max(0, min(100000, (int)$v));
        elseif (in_array($k, $dayKeys, true)) $v = max(1, min(365, (int)$v));
        elseif (in_array($k, $alertKeys, true)) $v = max(1, min(120, (int)$v));
        else $v = trim((string)$v);
        $pdo->prepare("INSERT INTO org_settings (k, v, updated_at) VALUES (?,?,datetime('now')) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')")->execute(['concierge_' . $k, $v]);
        $out[$k] = $v;
    }
    return $out;
}
/* ---------- Phase 48: Smart Home + Health Check helpers ---------- */
function sl_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'SL-','') AS INTEGER)) FROM smart_locks")->fetchColumn();
    return 'SL-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function cv_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'CV-','') AS INTEGER)) FROM cctv_cameras")->fetchColumn();
    return 'CV-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function hp_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'HP-','') AS INTEGER)) FROM health_plans")->fetchColumn();
    return 'HP-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function sl_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM smart_locks WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM smart_locks ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function cv_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM cctv_cameras WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM cctv_cameras ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function hp_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM health_plans WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM health_plans ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function sl_enrich($pdo, $rows) {
    $units = []; $props = [];
    foreach ($pdo->query('SELECT id,name,p FROM units')->fetchAll(PDO::FETCH_ASSOC) as $u2) $units[$u2['id']] = $u2['name'];
    foreach ($pdo->query('SELECT id,name FROM properties')->fetchAll(PDO::FETCH_ASSOC) as $pr) $props[$pr['id']] = $pr['name'];
    foreach ($rows as $k => $v) {
        $rows[$k]['unit_name'] = $units[$v['unit']] ?? '';
        $rows[$k]['prop_name'] = $props[$v['prop']] ?? '';
        $rows[$k]['days_left'] = $v['valid_until'] ? (int)floor((strtotime($v['valid_until']) - time()) / 86400) : null;
        $rows[$k]['expired'] = $v['status'] === 'active' && $v['valid_until'] && $v['valid_until'] < date('Y-m-d H:i:s');
    }
    return $rows;
}
function cv_enrich($pdo, $rows) {
    $props = [];
    foreach ($pdo->query('SELECT id,name FROM properties')->fetchAll(PDO::FETCH_ASSOC) as $pr) $props[$pr['id']] = $pr['name'];
    foreach ($rows as $k => $v) $rows[$k]['prop_name'] = $props[$v['prop']] ?? '';
    return $rows;
}
function health_checklists($svc) {
    $map = [
        'ac_service' => ['Clean filters', 'Check gas pressure', 'Inspect outdoor unit', 'Test cooling'],
        'roof_waterproof' => ['Inspect roof membrane', 'Clear drainage outlets', 'Patch cracks', 'Water test'],
        'drainage_clear' => ['Clear roof drains', 'Flush downpipes', 'Check waterlogging', 'Anti-mosquito treatment'],
        'deep_clean' => ['Vacuum & mop floors', 'Clean kitchen/bathroom', 'Wipe windows', 'Check for mold', 'Pest inspection'],
        'pest_control' => ['Interior spray', 'Perimeter treatment', 'Seal entry points', 'Place traps'],
    ];
    return $map[$svc] ?? [];
}
function hp_enrich($pdo, $rows) {
    $units = []; $props = [];
    foreach ($pdo->query('SELECT id,name,p FROM units')->fetchAll(PDO::FETCH_ASSOC) as $u2) $units[$u2['id']] = $u2['name'];
    foreach ($pdo->query('SELECT id,name FROM properties')->fetchAll(PDO::FETCH_ASSOC) as $pr) $props[$pr['id']] = $pr['name'];
    foreach ($rows as $k => $v) {
        $rows[$k]['unit_name'] = $units[$v['unit']] ?? '';
        $rows[$k]['prop_name'] = $props[$v['prop']] ?? '';
        $chk = json_decode($v['checklist'] ?: '[]', true) ?: [];
        $done = count(array_filter($chk, fn($c) => !empty($c['done'])));
        $rows[$k]['checklist'] = $chk;
        $rows[$k]['tasks_done'] = $done;
        $rows[$k]['tasks_total'] = count($chk);
        $rows[$k]['pct'] = count($chk) ? (int)round($done * 100 / count($chk)) : 0;
        $rows[$k]['season_label'] = ucwords(str_replace('_', ' ', $v['season']));
        $rows[$k]['service_label'] = ucwords(str_replace('_', ' ', $v['service']));
    }
    return $rows;
}
function smart_config_rows($pdo) {
    $def = ['lock_valid_hours' => 48, 'health_alert_days' => 30, 'health_default_vendor' => 'EcoClean Services'];
    foreach (array_keys($def) as $k) {
        $prefix = ($k === 'lock_valid_hours') ? 'smart_' : 'health_';
        $st = $pdo->prepare('SELECT v FROM org_settings WHERE k=?'); $st->execute([$prefix . $k]);
        $v = $st->fetchColumn();
        if ($v !== false) $def[$k] = in_array($k, ['lock_valid_hours', 'health_alert_days'], true) ? (int)$v : $v;
    }
    return $def;
}
function smart_config_save($pdo, $cfg) {
    $def = smart_config_rows($pdo);
    $out = [];
    foreach (array_keys($def) as $k) {
        if (!array_key_exists($k, $cfg)) continue;
        $prefix = ($k === 'lock_valid_hours') ? 'smart_' : 'health_';
        $v = $cfg[$k];
        if (in_array($k, ['lock_valid_hours', 'health_alert_days'], true)) $v = max(1, min(720, (int)$v));
        else $v = trim((string)$v);
        $pdo->prepare("INSERT INTO org_settings (k, v, updated_at) VALUES (?,?,datetime('now')) ON CONFLICT(k) DO UPDATE SET v=excluded.v, updated_at=datetime('now')")->execute([$prefix . $k, $v]);
        $out[$k] = $v;
    }
    return $out;
}
/* ---------- Phase 49: Build Watch helpers ---------- */
function bp_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'BP-','') AS INTEGER)) FROM build_projects")->fetchColumn();
    return 'BP-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function bm_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'BM-','') AS INTEGER)) FROM build_milestones")->fetchColumn();
    return 'BM-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function bx_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'BX-','') AS INTEGER)) FROM build_expenses")->fetchColumn();
    return 'BX-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function bd_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'BD-','') AS INTEGER)) FROM build_media")->fetchColumn();
    return 'BD-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function build_cfg($pdo, $key, $def = '') {
    $st = $pdo->prepare('SELECT v FROM org_settings WHERE k=?'); $st->execute(['build_' . $key]);
    $v = $st->fetchColumn();
    return $v === false ? $def : $v;
}
function bp_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM build_projects WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM build_projects ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function bm_rows($pdo, $project) {
    $st = $pdo->prepare('SELECT * FROM build_milestones WHERE project=? ORDER BY target_date, id');
    $st->execute([$project]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function bx_rows($pdo, $project) {
    $st = $pdo->prepare('SELECT * FROM build_expenses WHERE project=? ORDER BY spent_on, id');
    $st->execute([$project]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function bd_rows($pdo, $project) {
    $st = $pdo->prepare('SELECT * FROM build_media WHERE project=? ORDER BY captured_at DESC, id');
    $st->execute([$project]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function bp_enrich($pdo, $rows) {
    $today = date('Y-m-d');
    foreach ($rows as $k => $row) {
        $sp = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM build_expenses WHERE project=?');
        $sp->execute([$row['id']]);
        $spent = (int)$sp->fetchColumn();
        $mst = $pdo->prepare('SELECT COUNT(*) FROM build_milestones WHERE project=?');
        $mst->execute([$row['id']]);
        $totalM = (int)$mst->fetchColumn();
        $mst2 = $pdo->prepare("SELECT COUNT(*) FROM build_milestones WHERE project=? AND status IN ('Completed','Skipped')");
        $mst2->execute([$row['id']]);
        $doneM = (int)$mst2->fetchColumn();
        $odt = $pdo->prepare("SELECT COUNT(*) FROM build_milestones WHERE project=? AND status NOT IN ('Completed','Skipped') AND target_date<>'' AND target_date < ?");
        $odt->execute([$row['id'], $today]);
        $overdue = (int)$odt->fetchColumn();
        $bdt = $pdo->prepare('SELECT COUNT(*) FROM build_milestones WHERE project=? AND status NOT IN (?,?) AND target_date<>? AND target_date <= ?');
        $bdt->execute([$row['id'], 'Completed', 'Skipped', '', date('Y-m-d', strtotime('+' . (int)(build_cfg($pdo, 'milestone_alert_days', 14)) . ' days'))]);
        $dueSoon = (int)$bdt->fetchColumn();
        $budget = max(0, (int)$row['budget_total']);
        $pct = $budget > 0 ? round($spent / $budget * 100, 1) : 0;
        $daysLeft = $row['target_end'] ? (int)floor((strtotime($row['target_end']) - strtotime($today)) / 86400) : null;
        $rows[$k]['spent'] = $spent;
        $rows[$k]['milestones_total'] = $totalM;
        $rows[$k]['milestones_done'] = $doneM;
        $rows[$k]['milestones_overdue'] = $overdue;
        $rows[$k]['milestones_due_soon'] = $dueSoon;
        $rows[$k]['progress'] = $totalM > 0 ? (int)round($doneM / $totalM * 100) : 0;
        $rows[$k]['budget_used_pct'] = $pct;
        $rows[$k]['budget_variance'] = $budget - $spent;
        $rows[$k]['days_left'] = $daysLeft;
        $rows[$k]['over_budget'] = $budget > 0 && $spent > $budget * (1 + (int)(build_cfg($pdo, 'budget_overrun_pct', 10)) / 100);
        $mc = $pdo->prepare('SELECT COUNT(*) FROM build_media WHERE project=?'); $mc->execute([$row['id']]);
        $rows[$k]['media_count'] = (int)$mc->fetchColumn();
        $rows[$k]['property_name'] = '';
        if ($row['prop']) {
            $pt = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $pt->execute([$row['prop']]);
            $pn = $pt->fetchColumn();
            $rows[$k]['property_name'] = $pn ?: $row['prop'];
        }
    }
    return $rows;
}
function build_alerts($pdo, $rows) {
    $alerts = [];
    foreach ($rows as $p) {
        if ($p['milestones_overdue'] > 0)
            $alerts[] = ['severity' => 'warning', 'ref' => $p['id'], 'title' => 'Milestone overdue', 'body' => $p['id'] . ' — ' . $p['title'] . ' · ' . $p['milestones_overdue'] . ' milestone(s) past due'];
        if (!empty($p['over_budget']))
            $alerts[] = ['severity' => 'warning', 'ref' => $p['id'], 'title' => 'Budget overrun', 'body' => $p['id'] . ' — ' . $p['title'] . ' · spent ৳' . number_format($p['spent']) . ' of ৳' . number_format((int)$p['budget_total']) . ' budget'];
        if (in_array($p['status'], ['Planning', 'In_Progress', 'On_Hold'], true) && $p['days_left'] !== null && $p['days_left'] < 0)
            $alerts[] = ['severity' => 'info', 'ref' => $p['id'], 'title' => 'Past target date', 'body' => $p['id'] . ' — ' . $p['title'] . ' · target end ' . $p['target_end'] . ' passed'];
    }
    return $alerts;
}
function build_report_html($pdo, $row, $milestones, $expenses, $media) {
    $budget = max(0, (int)$row['budget_total']);
    $spent = (int)($row['spent'] ?? 0);
    $pct = $budget > 0 ? round($spent / $budget * 100, 1) : 0;
    $kinds = ['construction' => 'Construction', 'renovation' => 'Renovation', 'repair' => 'Repair'];
    $kindBn = ['construction' => 'নির্মাণ', 'renovation' => 'সংস্কার', 'repair' => 'মেরামত'];
    $statusBn = ['Planning' => 'পরিকল্পনা', 'In_Progress' => 'চলমান', 'On_Hold' => 'স্থগিত', 'Completed' => 'সম্পন্ন', 'Cancelled' => 'বাতিল'];
    $rows = '';
    foreach ($milestones as $m) {
        $mstat = ['Pending' => 'Pending', 'In_Progress' => 'In Progress', 'Completed' => 'Completed', 'Skipped' => 'Skipped'][$m['status']] ?? $m['status'];
        $rows .= '<tr><td>' . htmlspecialchars($m['id']) . '</td><td>' . htmlspecialchars($m['title']) . '</td><td>' . htmlspecialchars(ucfirst($m['phase'])) . '</td><td>' . htmlspecialchars($m['target_date']) . '</td><td><b>' . $mstat . '</b></td><td style="text-align:right">৳' . number_format((int)$m['cost']) . '</td></tr>';
    }
    $erows = '';
    foreach ($expenses as $x) {
        $erows .= '<tr><td>' . htmlspecialchars($x['id']) . '</td><td>' . htmlspecialchars($x['label']) . '</td><td>' . htmlspecialchars(ucfirst($x['category'])) . '</td><td>' . htmlspecialchars($x['spent_on']) . '</td><td style="text-align:right">৳' . number_format((int)$x['amount']) . '</td></tr>';
    }
    $bars = '';
    foreach ($milestones as $m) {
        $done = in_array($m['status'], ['Completed', 'Skipped'], true);
        $bars .= '<div style="display:flex;align-items:center;gap:8px;margin:4px 0"><span style="width:220px">' . htmlspecialchars($m['title']) . '</span><div style="flex:1;height:8px;background:#eef1f5;border-radius:4px;overflow:hidden"><div style="width:' . ($done ? '100%' : '0%') . ';height:100%;background:' . ($done ? '#16a34a' : '#f59e0b') . '"></div></div><span>' . $m['status'] . '</span></div>';
    }
    $imgs = '';
    foreach ($media as $md) {
        $imgs .= '<div style="display:inline-block;margin:8px;padding:10px;border:1px solid #e2e8f0;border-radius:8px"><div style="font-size:28px">📷</div><div>' . htmlspecialchars($md['name']) . '</div><div style="color:#64748b;font-size:11px">' . htmlspecialchars($md['captured_at']) . ($md['geo'] ? ' · ' . htmlspecialchars($md['geo']) : '') . '</div></div>';
    }
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . htmlspecialchars($row['title']) . ' — Build Watch Report</title><style>body{font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#0f172a;max-width:820px;margin:32px auto;padding:0 16px}h1{font-size:22px;margin:0}h2{font-size:15px;text-transform:uppercase;letter-spacing:.05em;color:#334155;border-bottom:2px solid #e2e8f0;padding-bottom:6px;margin-top:28px}table{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px}th,td{border:1px solid #e2e8f0;padding:7px 9px;text-align:left}th{background:#f8fafc}.chip{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600}.s-inprogress{background:#dbeafe;color:#1d4ed8}.s-completed{background:#dcfce7;color:#166534}.s-planning{background:#f1f5f9;color:#475569}.s-onhold{background:#fef3c7;color:#b45309}.s-cancelled{background:#fee2e2;color:#b91c1c}.stat{display:inline-block;min-width:130px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 14px;margin:6px 8px 6px 0}.stat b{display:block;font-size:18px}.stat span{font-size:11px;color:#64748b}.sig{display:inline-block;width:200px;margin:40px 30px 0 0;text-align:center}.sig div{border-top:1px solid #94a3b8;padding-top:6px;font-size:12px;color:#334155}.stamp{display:inline-block;padding:4px 14px;border:2px solid #16a34a;color:#166534;border-radius:8px;font-weight:700;font-size:13px;letter-spacing:.08em}</style></head><body>
    <div style="display:flex;justify-content:space-between;align-items:flex-start"><div><h1>🏗️ Build Watch — Progress Report</h1><div style="color:#64748b;font-size:13px;margin-top:4px">' . htmlspecialchars($row['id']) . ' · ' . htmlspecialchars($row['title']) . ' · Generated ' . date('d M Y H:i') . '</div></div><div><span class="stamp">UNDER MONITORING</span></div></div>
    <div style="margin-top:14px"><span class="chip s-' . strtolower($row['status']) . '">' . $row['status'] . '</span> <span class="chip" style="background:#f1f5f9;color:#334155">' . ($kinds[$row['kind']] ?? $row['kind']) . ' / ' . ($kindBn[$row['kind']] ?? '') . '</span> <span class="chip" style="background:#f1f5f9;color:#334155">' . htmlspecialchars($row['prop'] ?: '—') . ($row['property_name'] ? ' · ' . htmlspecialchars($row['property_name']) : '') . '</span></div>
    <div style="margin-top:16px"><span class="stat"><b>৳' . number_format($budget) . '</b><span>Budget</span></span><span class="stat"><b>৳' . number_format($spent) . '</b><span>Spent (' . $pct . '%)</span></span><span class="stat"><b>' . $row['progress'] . '%</b><span>Milestones done</span></span><span class="stat"><b>' . ($row['days_left'] !== null ? $row['days_left'] . 'd' : '—') . '</b><span>Target: ' . htmlspecialchars($row['target_end'] ?: '—') . '</span></span></div>
    <h2>Progress</h2>' . $bars . '
    <h2>Milestones</h2><table><thead><tr><th>ID</th><th>Title</th><th>Phase</th><th>Target</th><th>Status</th><th style="text-align:right">Cost</th></tr></thead><tbody>' . $rows . '</tbody></table>
    <h2>Expenses</h2><table><thead><tr><th>ID</th><th>Label</th><th>Category</th><th>Date</th><th style="text-align:right">Amount</th></tr></thead><tbody>' . $erows . '</tbody></table>
    <h2>Site Media (' . count($media) . ')</h2><div>' . ($imgs ?: '<div style="color:#94a3b8;font-size:13px">No site photos uploaded yet.</div>') . '</div>
    <h2>Sign-off</h2><div style="margin-top:18px"><div class="sig"><div>Owner / Owner Representative</div></div><div class="sig"><div>Project Contractor</div></div><div class="sig"><div>KRTaker Site Monitor</div></div></div>
    </body></html>';
}
/* ---------- Phase 50: Gate Watch helpers ---------- */
function gv_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'GV-','') AS INTEGER)) FROM gate_visits")->fetchColumn();
    return 'GV-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function rv_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'RV-','') AS INTEGER)) FROM resident_vehicles")->fetchColumn();
    return 'RV-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function gw_next_id($pdo) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'GW-','') AS INTEGER)) FROM gate_watchlist")->fetchColumn();
    return 'GW-' . str_pad((string)max(100, $mx + 1), 3, '0', STR_PAD_LEFT);
}
function gate_cfg($pdo, $key, $def = '') {
    $st = $pdo->prepare('SELECT v FROM org_settings WHERE k=?'); $st->execute(['gate_' . $key]);
    $v = $st->fetchColumn();
    return $v === false ? $def : $v;
}
function gate_visit_rows($pdo, $u) {
    if ($u['role'] === 'owner') {
        $st = $pdo->prepare("SELECT * FROM gate_visits WHERE owner_email=? OR owner_email='' ORDER BY ts DESC");
        $st->execute([$u['email']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query('SELECT * FROM gate_visits ORDER BY ts DESC')->fetchAll(PDO::FETCH_ASSOC);
}
function gate_enrich($pdo, $rows) {
    foreach ($rows as $k => $r) {
        $rows[$k]['property_name'] = '';
        if ($r['prop']) {
            $pt = $pdo->prepare('SELECT name FROM properties WHERE id=?'); $pt->execute([$r['prop']]);
            $pn = $pt->fetchColumn();
            $rows[$k]['property_name'] = $pn ?: $r['prop'];
        }
        $rows[$k]['inside_min'] = 0;
        if ($r['status'] === 'Inside' && $r['check_in']) {
            $rows[$k]['inside_min'] = max(0, (int)((time() - strtotime($r['check_in'])) / 60));
        }
        $rows[$k]['resident'] = 0;
        if (trim($r['vehicle_no']) !== '') {
            $vs = $pdo->prepare("SELECT id FROM resident_vehicles WHERE vehicle_no=? AND status='active'");
            $vs->execute([$r['vehicle_no']]);
            if ($vs->fetchColumn()) $rows[$k]['resident'] = 1;
        }
    }
    return $rows;
}
function gate_flag_for($pdo, $vehicle_no, $name) {
    $v = strtoupper(trim($vehicle_no));
    $n = trim($name);
    if ($v === '' && $n === '') return null;
    if ($v !== '') {
        $st = $pdo->prepare("SELECT * FROM gate_watchlist WHERE active=1 AND vehicle_no<>'' AND UPPER(vehicle_no)=?");
        $st->execute([$v]);
        $w = $st->fetch(PDO::FETCH_ASSOC);
        if ($w) return $w;
    }
    if ($n !== '') {
        $st = $pdo->prepare("SELECT * FROM gate_watchlist WHERE active=1 AND name<>'' AND name=?");
        $st->execute([$n]);
        $w = $st->fetch(PDO::FETCH_ASSOC);
        if ($w) return $w;
    }
    return null;
}
function gate_report_html($pdo, $rows, $propName, $title) {
    $d = date('d M Y');
    $out = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
        . '<style>body{font-family:Arial,"Hind Siliguri",sans-serif;margin:32px;color:#111}'
        . 'h1{font-size:22px;margin:0 0 4px}h2{font-size:14px;font-weight:600;margin:18px 0 6px;color:#2F80ED}'
        . '.muted{color:#666;font-size:12px}table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px}'
        . 'th,td{border:1px solid #d8dde5;padding:6px 8px;text-align:left}th{background:#f2f5fa}'
        . '.sig{display:flex;gap:60px;margin-top:48px;font-size:12px}.sig div{flex:1}.sig .line{border-top:1px solid #333;margin-top:34px;padding-top:4px}'
        . '.flag{color:#b91c1c;font-weight:700}</style></head><body>'
        . '<h1>' . htmlspecialchars($title) . ' — Gate Watch (গেট রেজিস্টার)</h1>'
        . '<div class="muted">' . htmlspecialchars($propName) . ' · ' . $d . ' · ' . count($rows) . ' visits (মোট আগমন)</div>'
        . '<table><thead><tr><th>#</th><th>ID</th><th>Time in</th><th>Time out</th><th>Type</th><th>Name</th><th>Phone</th><th>Vehicle</th><th>Unit</th><th>Purpose</th><th>Host</th><th>Status</th></tr></thead><tbody>';
    $i = 1;
    foreach ($rows as $r) {
        $vt = ['visitor' => 'Visitor', 'delivery' => 'Delivery', 'worker' => 'Worker', 'vendor' => 'Vendor', 'vehicle' => 'Vehicle'][$r['vtype']] ?? ucfirst($r['vtype']);
        $flag = (int)$r['flagged'] ? ' <span class="flag">★ FLAGGED</span>' : '';
        $out .= '<tr><td>' . $i++ . '</td><td>' . htmlspecialchars($r['id']) . '</td><td>' . htmlspecialchars($r['check_in']) . '</td><td>' . htmlspecialchars($r['check_out'] ?: '—') . '</td>'
            . '<td>' . $vt . '</td><td>' . htmlspecialchars($r['name']) . $flag . '</td><td>' . htmlspecialchars($r['phone']) . '</td>'
            . '<td>' . htmlspecialchars($r['vehicle_no']) . '</td><td>' . htmlspecialchars($r['unit']) . '</td><td>' . htmlspecialchars($r['purpose']) . '</td>'
            . '<td>' . htmlspecialchars($r['host_name']) . '</td><td><b>' . $r['status'] . '</b></td></tr>';
    }
    $out .= '</tbody></table>'
        . '<h2>Summary — সারসংক্ষেপ</h2>'
        . '<div class="muted">' . count($rows) . ' total · Inside now: ' . count(array_filter($rows, fn($r) => $r['status'] === 'Inside')) . ' · Flagged: ' . count(array_filter($rows, fn($r) => (int)$r['flagged'] === 1)) . '</div>'
        . '<div class="sig"><div class="line">Guard on duty (গার্ড)</div><div class="line">Property Manager (ম্যানেজার)</div><div class="line">Owner (মালিক)</div></div>'
        . '</body></html>';
    return $out;
}
