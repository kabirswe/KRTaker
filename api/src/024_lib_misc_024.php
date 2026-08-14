function invoice_owner_check($u, $inv) {
    /* tenant may only pay invoices on their own leases; staff may pay any */
    if ($u['role'] !== 'tenant') return true;
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?'); $st->execute([$u['email']]);
    $tid = $st->fetchColumn();
    if (!$tid) return false;
    $st = $pdo->prepare('SELECT COUNT(*) FROM leases WHERE t=? AND id IN (SELECT l FROM invoices WHERE id=?)');
    $st->execute([$tid, $inv]);
    return (int)$st->fetchColumn() > 0;
}
function invoice_due($pdo, $inv) {
    $st = $pdo->prepare('SELECT * FROM invoices WHERE id=?'); $st->execute([$inv]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE inv=?'); $st->execute([$inv]);
    $paid = (int)$st->fetchColumn();
    $due = (int)$row['net'] - $paid;
    return ['row' => $row, 'paid' => $paid, 'due' => $due];
}
/* Atomic payment + signed receipt + invoice ripple (shared by manual & gateway paths) */
