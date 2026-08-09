function legal_my_leases($pdo, $u) {
    $st = $pdo->prepare('SELECT id FROM leases WHERE t=(SELECT id FROM tenants WHERE sub_email=?)');
    $st->execute([$u['email']]);
    return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id');
}
