function record_payment($pdo, $inv, $amount, $method, $ref, $date) {
    $mx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'PAY-','') AS INTEGER)) FROM payments")->fetchColumn();
    $pid = 'PAY-' . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
    $rx = (int)$pdo->query("SELECT MAX(CAST(REPLACE(id,'RCP-','') AS INTEGER)) FROM receipts")->fetchColumn();
    $rid = 'RCP-' . str_pad((string)($rx + 1), 4, '0', STR_PAD_LEFT);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO payments (id, inv, amount, method, ref, date, status) VALUES (?,?,?,?,?,?,?)')
            ->execute([$pid, $inv, $amount, $method, $ref, $date, 'Success']);
        $pdo->prepare('INSERT INTO receipts (id, inv, amount, date, method, sig) VALUES (?,?,?,?,?,?)')
            ->execute([$rid, $inv, $amount, $date, $method, 'SIG-' . bin2hex(random_bytes(4))]);
        /* ripple: mark invoice Paid when balance is cleared */
        $due = invoice_due($pdo, $inv);
        if ($due && $due['due'] <= 0) {
            $pdo->prepare('UPDATE invoices SET status="Paid" WHERE id=?')->execute([$inv]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$pid, $rid];
}

/* ═══════════════════════════ PHASE 5 — KR AI (DeepSeek + offline fallback) ═══════════════════════════ */
