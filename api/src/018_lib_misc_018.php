function effective_modules($u, $role = null) {
    $role = $role ?: ($u['role'] ?? 'owner');
    $base = ROLE_MODULES()[$role] ?? [];
    if (($u['kind'] ?? '') !== 'sub') return $base;                 /* staff = full base (Enterprise) */
    $code = plan_for_user($u);
    $map = package_modules_map($code);
    if (!$map && $code === 'trial') $map = package_modules_map('starter');  /* SA1-v17: Trial ≈ Starter, not full base */
    if (!$map) return $base;                                        /* legacy package w/o matrix → full base */
    $granted = $map[$role] ?? [];                                   /* role NOT in matrix → zero modules */
    return array_values(array_intersect($base, $granted));          /* package restricts, role is base */
}
function effective_limits($u) {
    $def = ['property_limit'=>9999,'unit_limit'=>99999,'seats'=>1,'kr_ai'=>true,'api_access'=>false,'reports'=>true];
    $code = plan_for_user($u);
    $lim = package_limits_map($code);
    if (!$lim && $code === 'trial') $lim = package_limits_map('starter');  /* SA1-v17: Trial ≈ Starter limits */
    return array_merge($def, is_array($lim) ? $lim : []);
}

/* ---------- Phase 3: generic CRUD ---------- */
function CRUD_FIELDS() {
    return [
        'properties' => ['name','type','jur','holding','sqft','value','status','sub_email','address','photo','description','featured','created_at','lat','lng'],
        'units'      => ['p','name','floor','sqft','status','rent','sub_email','beds','baths','furnished'],
        'tenants'    => ['name','phone','email','nid','nrb','kind','sub_email'],
        'leases'     => ['u','t','start','end','rent','adv','res','reg_office','reg_deed','status'],
        'invoices'   => ['l','m','gross','tds','net','status'],
        'tickets'    => ['u','desc','reported','liab','status','con','cost'],
        'partners'   => ['name','trade','rating','jobs','status','sub_email'],
        'staff'      => ['name','role','dept','status'],
        'support'    => ['from_t','subject','status','prio','age'],
        'cases'      => ['title','ref_lease','type','status','opened','notes'],
        'amenities'  => ['prop','unit','name','icon','category','status'],
    ];
}
function CRUD_PREFIX() {
    return ['properties'=>'P-','units'=>'U-','tenants'=>'T-','leases'=>'L-','tickets'=>'MT-','partners'=>'SP-','staff'=>'ST-','support'=>'SUP-','cases'=>'CASE-','amenities'=>'AM-'];
}
function can_crud($role, $collection) {
    $map = [
        'superadmin' => true,
        'owner'      => ['properties','units','tenants','leases','invoices','tickets','partners','amenities'],
        'manager'    => ['properties','units','tenants','leases','invoices','tickets','amenities'],
        'accountant' => ['invoices','tickets'],
        'svc_mgr'    => ['tickets','partners'],
        'crm'        => ['tickets','support'],
        'hr'         => ['staff'],
        'legal'      => ['leases','cases'],
        'tenant'     => ['tickets'],
        'partner'    => ['tickets'],
    ];
    return ($map[$role] ?? []) === true || in_array($collection, $map[$role] ?? [], true);
}
function next_id($table, $prefix, $pad) {
    $mx = db()->query("SELECT MAX(CAST(REPLACE(id,'" . $prefix . "','') AS INTEGER)) FROM " . $table)->fetchColumn();
    return $prefix . str_pad((string)((int)$mx + 1), $pad, '0', STR_PAD_LEFT);
}
/* ── SA1 v25.6: row-ownership resolver for app-crud — maps any CRUD-able row to its owning
   subscriber email. properties/units/tenants/partners carry sub_email directly; the rest
   resolve through the parent chain (lease→unit→prop, invoice→lease→unit→prop,
   ticket→unit→prop, amenity→unit→prop). Returns '' when the row cannot be attributed
   (shared/global rows) and false when the row does not exist. ── */
function crud_row_owner($pdo, $collection, $id) {
    $tables = ['properties','units','tenants','partners','leases','invoices','tickets','amenities'];
    if (!in_array($collection, $tables, true)) return '';   // not an ownership-scoped table
    try {
        if (in_array($collection, ['properties','units','tenants','partners'], true)) {
            $st = $pdo->prepare("SELECT sub_email FROM $collection WHERE id=?");
            $st->execute([$id]);
            $v = $st->fetchColumn();
            if ($v === false) return false;
            return (string)$v;
        }
        if ($collection === 'leases') {
            $st = $pdo->prepare("SELECT u.sub_email FROM leases l JOIN units u ON u.id=l.u WHERE l.id=?");
            $st->execute([$id]);
            $v = $st->fetchColumn();
            if ($v === false) return false;
            return (string)$v;
        }
        if ($collection === 'invoices') {
            $st = $pdo->prepare("SELECT u.sub_email FROM invoices i JOIN leases l ON l.id=i.l JOIN units u ON u.id=l.u WHERE i.id=?");
            $st->execute([$id]);
            $v = $st->fetchColumn();
            if ($v === false) return false;
            return (string)$v;
        }
        if ($collection === 'tickets') {
            $st = $pdo->prepare("SELECT u.sub_email FROM tickets t JOIN units u ON u.id=t.u WHERE t.id=?");
            $st->execute([$id]);
            $v = $st->fetchColumn();
            if ($v === false) return false;
            return (string)$v;
        }
        if ($collection === 'amenities') {
            /* building-level amenities carry unit='' → resolve owner via prop fallback */
            $st = $pdo->prepare("SELECT a.id, COALESCE(u.sub_email, p.sub_email) FROM amenities a LEFT JOIN units u ON u.id=a.unit LEFT JOIN properties p ON p.id=a.prop WHERE a.id=?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_NUM);
            if ($row === false) return false;   // amenity row missing → 404
            return (string)$row[1];             // '' = shared/unattributable → allowed
        }
    } catch (Exception $e) { return ''; }
    return '';
}
/* subscriber row-scope guard for update/delete — blocks cross-account writes on app-crud */
function crud_guard_owner($pdo, $u, $collection, $id) {
    if (($u['kind'] ?? '') !== 'sub') return;               // staff/team overlay inherit parent scope
    /* partner tickets are scoped by the dedicated `con` (assigned company) check in app-crud —
       the unit→property chain resolves to the building owner's email and would 403 legit jobs */
    if (($u['role'] ?? '') === 'partner' && $collection === 'tickets') return;
    $own = crud_row_owner($pdo, $collection, $id);
    if ($own === false) json_out(['ok' => false, 'error' => 'Record not found.'], 404);
    if ($own !== '' && strtolower(trim($own)) !== strtolower(trim($u['email'])))
        json_out(['ok' => false, 'error' => 'This record belongs to another account.'], 403);
}
/* tenant/partner own-scope helpers */
function my_units($u) {
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM tenants WHERE sub_email=?');
    $st->execute([$u['email']]);
    $tid = $st->fetchColumn();
    if (!$tid) return [];
    $st = $pdo->prepare('SELECT u FROM leases WHERE t=?');
    $st->execute([$tid]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}
function my_org($u) {
    $pdo = db();
    $st = $pdo->prepare('SELECT name FROM partners WHERE sub_email=?');
    $st->execute([$u['email']]);
    $n = $st->fetchColumn();
    return $n ?: $u['org'] ?? '';
}

/* ---------- Phase 4: payment gateways (bKash / SSLCommerz / Nagad) ----------
   Sandbox mode: init returns a simulated checkout; confirm records the payment.
   Go live: paste real merchant credentials below and set sandbox=false. */
function GATEWAYS() {
    $gw = [
        'bkash' => [
            'name' => 'bKash', 'sandbox' => true,
            'merchant' => '013000000000',          // ← live App Key / wallet no.
            'app_secret' => 'REPLACE_ME',          // ← live bKash tokenized-checkout App Secret
            'checkout' => 'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/create',
        ],
        'sslcommerz' => [
            'name' => 'SSLCommerz', 'sandbox' => true,
            'store_id' => 'krtakerTEST',           // ← live Store ID
            'store_pass' => 'REPLACE_ME',          // ← live Store Password
            'checkout' => 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php',
        ],
        'nagad' => [
            'name' => 'Nagad', 'sandbox' => true,
            'merchant' => 'NAGAD_MERCHANT_ID',     // ← live merchant id
            'checkout' => 'https://sandbox.mynagad.com/api/dfs/check-out/initialize',
        ],
    ];
    /* SA1: super-admin payment-integration overrides (platform_meta gw_config JSON) */
    try {
        $ov = json_decode((string)db()->query("SELECT v FROM platform_meta WHERE k='gw_config'")->fetchColumn(), true);
        if (is_array($ov)) foreach ($ov as $code => $cfg) {
            if (!isset($gw[$code]) || !is_array($cfg)) continue;
            foreach ($cfg as $k => $v) {
                if ($k === 'sandbox') { $gw[$code]['sandbox'] = !empty($v); }
                elseif (array_key_exists($k, $gw[$code])) { $gw[$code][$k] = (string)$v; }
            }
        }
    } catch (Exception $e) { /* schema not ready yet — keep defaults */ }
    return $gw;
}
