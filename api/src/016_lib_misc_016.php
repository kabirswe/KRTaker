function MODULE_REGISTRY() {
    return [
        ['id'=>'dashboard','built'=>true],['id'=>'properties','built'=>true],['id'=>'units','built'=>true],
        ['id'=>'tenants','built'=>true],['id'=>'leases','built'=>true],['id'=>'renewals','built'=>true],
        ['id'=>'documents','built'=>true],['id'=>'templates','built'=>true],['id'=>'notices','built'=>true],
        ['id'=>'referrals','built'=>true],['id'=>'invoices','built'=>true],['id'=>'receipts','built'=>true],
        ['id'=>'payments','built'=>true],['id'=>'taxes','built'=>true],['id'=>'statements','built'=>true],
        ['id'=>'remit','built'=>true],['id'=>'nrb','built'=>true],['id'=>'recon','built'=>true],
        ['id'=>'analytics','built'=>true],['id'=>'subscriptions','built'=>true],['id'=>'caretaker','built'=>true],
        ['id'=>'utilities','built'=>true],['id'=>'maintenance','built'=>true],['id'=>'vendors','built'=>true],
        ['id'=>'onboarding','built'=>true],['id'=>'leads','built'=>true],['id'=>'compliance','built'=>true],
        ['id'=>'legal','built'=>true],['id'=>'trust','built'=>true],['id'=>'land','built'=>true],
        ['id'=>'concierge','built'=>true],['id'=>'smarthome','built'=>true],['id'=>'health','built'=>true],
        ['id'=>'build','built'=>true],['id'=>'gate','built'=>true],['id'=>'firesafety','built'=>true],
        ['id'=>'systems','built'=>true],['id'=>'staffwatch','built'=>true],['id'=>'samity','built'=>true],
        ['id'=>'ai','built'=>true],['id'=>'packages','built'=>true],['id'=>'portal','built'=>true],
        ['id'=>'parking','built'=>false],['id'=>'bookings','built'=>false],['id'=>'voting','built'=>false],
        ['id'=>'forums','built'=>false],['id'=>'events','built'=>false],['id'=>'insurance','built'=>false],
    ];
}
function package_row($code) {
    $st = db()->prepare('SELECT * FROM plan_catalog WHERE lower(code)=?');
    $st->execute([strtolower($code)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function package_modules_map($code) {
    $r = package_row($code);
    $m = $r ? json_decode($r['modules'] ?? '[]', true) : [];
    return is_array($m) ? $m : [];
}
function package_limits_map($code) {
    $r = package_row($code);
    $m = $r ? json_decode($r['limits'] ?? '{}', true) : [];
    return is_array($m) ? $m : [];
}
