function collections_email_html($rows) {
    $items = '';
    foreach ($rows as $r) {
        $items .= '<tr><td style="padding:8px 10px;border-bottom:1px solid #E4EAF3;font-size:13px"><b>' . htmlspecialchars($r['inv']) . '</b><br><span style="color:#8A94A6;font-size:11px">' . htmlspecialchars($r['tenant']) . ' · ' . htmlspecialchars($r['property']) . '</span></td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #E4EAF3;font-size:13px">' . htmlspecialchars($r['m']) . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #E4EAF3;font-size:13px;text-align:right">৳' . number_format($r['due']) . '</td></tr>';
    }
    $total = array_sum(array_column($rows, 'due'));
    list($s, $h) = email_render('collections', ['unpaid' => count($rows), 'total' => '৳' . number_format($total), 'items' => $items]);
    return $h;
}

/* SA1 v22: rent-due push — owner-facing proactive alert (complements the tenant digest email).
   Classifies each owner's unpaid invoices: overdue (< current month), due_soon (current month),
   upcoming (next N months). Only owners with ≥1 registered push device are considered. */
