function invoice_print_html($u, $invId) {
    $pdo = db();
    $st = $pdo->prepare('SELECT i.*, l.u AS uid, l.t AS tid, l.rent, l.adv, l.start AS ls, l.end AS le,
                         u.name AS uname, u.p AS pid, p.name AS pname, p.jur AS pjur, p.holding AS pholding,
                         t.name AS tname, t.phone AS tphone, t.email AS temail, t.nid AS tnid
                         FROM invoices i JOIN leases l ON l.id=i.l
                         JOIN units u ON u.id=l.u JOIN properties p ON p.id=u.p
                         JOIN tenants t ON t.id=l.t WHERE i.id=?');
    $st->execute([$invId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $pay = $pdo->prepare('SELECT * FROM payments WHERE inv=? ORDER BY date');
    $pay->execute([$invId]);
    $pays = $pay->fetchAll(PDO::FETCH_ASSOC);
    $paid = array_sum(array_column($pays, 'amount'));
    $due = (int)$r['net'] - $paid;
    $rows = '';
    foreach ($pays as $p) {
        $rows .= '<tr><td>' . esc($p['date']) . '</td><td>' . esc($p['method']) . '</td><td>' . esc($p['ref']) . '</td><td class="r">৳' . number_format($p['amount']) . '</td></tr>';
    }
    if (!$rows) $rows = '<tr><td colspan="4" class="m">No payments recorded yet</td></tr>';
    $due_cls = $due > 0 ? 'due' : 'ok';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Invoice ' . esc($invId) . '</title>
<style>
*{box-sizing:border-box}body{font-family:Inter,Helvetica,Arial,sans-serif;color:#101828;margin:0;padding:32px;background:#F4F6FA}
.page{max-width:760px;margin:0 auto;background:#fff;border:1px solid #E4EAF3;border-radius:16px;padding:36px}
.top{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #2F80ED;padding-bottom:20px;margin-bottom:24px}
.brand{font-size:22px;font-weight:800;color:#2F80ED;letter-spacing:-.3px}.brand img{display:block;margin-bottom:6px}.brand small{display:block;font-size:11px;font-weight:500;color:#8A94A6;letter-spacing:1.2px;text-transform:uppercase;margin-top:2px}
.meta{text-align:right;font-size:13px;color:#475467;line-height:1.7}.meta b{color:#101828}
h1{font-size:26px;margin:0 0 4px;color:#101828}.st{display:inline-block;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:700}
.st.unpaid{background:#FEF3C7;color:#92400E}.st.paid{background:#D1FAE5;color:#065F46}.st.overdue{background:#FEE2E2;color:#B91C1C}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin:24px 0}.h6{font-size:10px;letter-spacing:1.4px;text-transform:uppercase;color:#98A2B3;margin:0 0 6px}
table{width:100%;border-collapse:collapse;margin:16px 0 8px}th{font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#98A2B3;text-align:left;padding:8px 10px;border-bottom:1px solid #E4EAF3}
td{padding:10px;font-size:13.5px;border-bottom:1px solid #F2F4F7}.r{text-align:right;font-variant-numeric:tabular-nums}.m{color:#98A2B3;text-align:center;padding:18px}
.tot{border-top:2px solid #101828;margin-top:8px}.tot td{padding:10px;font-size:14px}.tot .big{font-size:18px;font-weight:800}
.due{color:#B91C1C;font-weight:700}.ok{color:#065F46;font-weight:700}
.foot{margin-top:28px;padding-top:16px;border-top:1px dashed #E4EAF3;font-size:11.5px;color:#98A2B3;line-height:1.7;text-align:center}
.pay{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}.pay span{background:#F2F4F7;border:1px solid #E4EAF3;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:600;color:#475467}
@media print{body{background:#fff;padding:0}.page{border:0;box-shadow:none}.noprint{display:none}}
.noprint{position:fixed;top:16px;right:16px;display:flex;gap:8px}
.btn{font:600 13px Inter;padding:9px 16px;border-radius:10px;border:1px solid #D0D5DD;background:#fff;cursor:pointer}.btn.p{background:#2F80ED;color:#fff;border-color:#2F80ED}
</style></head><body>
<div class="noprint"><button class="btn" onclick="window.print()">🖨 Print / Save PDF</button></div>
<div class="page">
<div class="top"><div><div class="brand">' . print_brand_img() . '<small>AI CARETAKER · RENT INVOICE</small></div></div>
<div class="meta"><b>' . esc($invId) . '</b><br>Month: ' . esc($r['m']) . '<br>Issued: ' . esc(gmdate('d M Y')) . '</div></div>
<h1>Rent invoice</h1><span class="st ' . ($due > 0 ? (($r['status'] === 'Overdue') ? 'overdue' : 'unpaid') : 'paid') . '">' . esc($r['status']) . ($due > 0 ? ' · due ৳' . number_format($due) : ' · paid in full') . '</span>
<div class="grid"><div><p class="h6">Billed to</p><div style="font-size:14px;line-height:1.7"><b>' . esc($r['tname']) . '</b><br>' . esc($r['tphone']) . '<br>' . esc($r['temail']) . '</div></div>
<div><p class="h6">Property</p><div style="font-size:14px;line-height:1.7"><b>' . esc($r['pname']) . '</b><br>' . esc($r['uname']) . ' (' . esc($r['uid']) . ') · ' . esc($r['pjur']) . '<br>' . esc($r['pholding']) . '</div></div></div>
<table><thead><tr><th>Description</th><th>Period</th><th>Lease</th><th class="r">Amount</th></tr></thead>
<tbody><tr><td>Monthly rent — ' . esc($r['uname']) . '</td><td>' . esc($r['m']) . '</td><td>' . esc($r['l']) . '</td><td class="r">৳' . number_format($r['gross']) . '</td></tr>'
    . ($r['tds'] ? '<tr><td>Advance tax (TDS, IT Act §109)</td><td></td><td></td><td class="r">−৳' . number_format($r['tds']) . '</td></tr>' : '')
    . '</tbody></table>
<table class="tot"><tr><td>Net payable</td><td></td><td></td><td class="r big">৳' . number_format($r['net']) . '</td></tr></table>
<table><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="r">Amount</th></tr></thead><tbody>' . $rows . '</tbody></table>
<div class="pay"><span>📱 bKash</span><span>📱 Nagad</span><span>🏦 SSLCommerz</span><span>💳 Bank</span></div>
<div class="foot">This is a system-generated invoice from KRTaker — your AI caretaker. Verify at krtaker.com · For questions: support@krtaker.com</div>
</div></body></html>';
    return $html;
}

/* Phase 11: collections autopilot — unpaid/overdue rollup + reminder engine */
function collections_data($pdo, $limit = 500) {
    $sql = "SELECT i.id AS inv, i.m, i.net, i.status,
                   l.id AS lease, l.rent, u.id AS uid, u.name AS uname, p.id AS pid, p.name AS pname,
                   t.id AS tid, t.name AS tname, t.email AS temail, t.phone AS tphone,
                   COALESCE((SELECT SUM(amount) FROM payments WHERE inv=i.id AND status='Success'),0) AS paid
            FROM invoices i
            JOIN leases l ON l.id=i.l
            JOIN units u ON u.id=l.u
            JOIN properties p ON p.id=u.p
            JOIN tenants t ON t.id=l.t
            WHERE i.status != 'Paid'
            ORDER BY i.m, i.id LIMIT " . (int)$limit;
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $due = (int)$r['net'] - (int)$r['paid'];
        if ($due <= 0) continue;
        $out[] = [
            'inv' => $r['inv'], 'm' => $r['m'], 'net' => (int)$r['net'], 'due' => $due, 'status' => $r['status'],
            'unit' => $r['uname'] . ' (' . $r['uid'] . ')', 'property' => $r['pname'],
            'tenant' => $r['tname'], 'email' => $r['temail'], 'phone' => $r['tphone'],
        ];
    }
    return $out;
}
