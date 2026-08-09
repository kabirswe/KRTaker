function inv_context($pdo, $invId) {
    $st = $pdo->prepare('SELECT i.*, l.u AS uid, l.t AS tid, l.rent,
                         u.name AS uname, u.p AS pid, p.name AS pname, t.name AS tname, t.email AS temail
                         FROM invoices i JOIN leases l ON l.id=i.l
                         JOIN units u ON u.id=l.u JOIN properties p ON p.id=u.p
                         JOIN tenants t ON t.id=l.t WHERE i.id=?');
    $st->execute([$invId]);
    return $st->fetch(PDO::FETCH_ASSOC);
}
