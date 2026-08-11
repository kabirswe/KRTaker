function json_fail_safe($err) {
    /* never let error handling itself blow up */
    if (ob_get_level()) { while (ob_get_level()) { ob_end_clean(); } }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode(['ok' => false, 'error' => 'Server error — please retry.', 'detail' => (string)$err], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
set_exception_handler(function ($e) { json_fail_safe($e->getMessage()); });


/* ── Error tracking (GO-LIVE 3.4): capture uncaught PHP fatals + JS-reported errors into
   app_error_log. Every capture path is defensive — logging must never break the API. ── */
function errlog_table($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_error_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT DEFAULT 'js', src TEXT DEFAULT '', page TEXT DEFAULT '',
        msg TEXT DEFAULT '', url TEXT DEFAULT '', line INTEGER DEFAULT 0, col INTEGER DEFAULT 0,
        ua TEXT DEFAULT '', ip_hash TEXT DEFAULT '', count INTEGER DEFAULT 1,
        first_ts TEXT DEFAULT (datetime('now')), last_ts TEXT DEFAULT (datetime('now')))");
    return $pdo;
}
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) return;
    try {
        $pdo = errlog_table(db());
        $st = $pdo->prepare("INSERT INTO app_error_log (kind, src, page, msg, url, line, col, ua, ip_hash)
            VALUES ('php', ?, ?, ?, ?, ?, 0, '', ?)");
        $st->execute([
            substr((string)($e['file'] ?? ''), 0, 200),
            substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 300),
            substr((string)$e['message'], 0, 500),
            substr((string)($e['file'] ?? '') . ':' . (int)($e['line'] ?? 0), 0, 300),
            substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 12),
        ]);
        if (random_int(1, 20) === 1) $pdo->exec("DELETE FROM app_error_log WHERE last_ts < datetime('now','-30 days')");
    } catch (Exception $ex) { /* never break shutdown */ }
    /* SA1 v26: a fatal mid-request must still return JSON, not an empty body.
       If headers are already sent there is nothing we can do — the buffer is flushed
       by PHP itself — but while they are not, give the client a parseable error. */
    if (!headers_sent()) {
        if (ob_get_level()) { while (ob_get_level()) { ob_end_clean(); } }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Server error — please retry.', 'detail' => substr((string)($e['message'] ?? ''), 0, 200)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
});

/* ── SA1 v25.6: secrets moved OUT of webroot into /home/krtaker/krtaker.env.php (return array).
   getenv('KRT_*') is the secondary source (cPanel "PHP Environment Variables") so ops can
   override without editing the file. No secret values live in this source file. ── */
$__env = [];
if (is_file('/home/krtaker/krtaker.env.php')) { $__env = (array)@include '/home/krtaker/krtaker.env.php'; }
function krenv($k, $def = '') {
    global $__env;
    if (isset($__env[$k]) && $__env[$k] !== '' && $__env[$k] !== null) return $__env[$k];
    $g = getenv('KRT_' . $k);
    if ($g !== false && $g !== '') return $g;
    return $def;
}
define('DB_PATH', krenv('DB_PATH', '/home/krtaker/krtaker_landing.db'));
define('TRIAL_DAYS', (int)krenv('TRIAL_DAYS', '14'));
define('ADMIN_EMAIL', krenv('ADMIN_EMAIL', ''));
define('APP_SETUP_KEY', krenv('APP_SETUP_KEY', ''));
define('TOKEN_TTL', 7 * 86400);
define('SERVICE_KEY', krenv('SERVICE_KEY', ''));
$SMTP = is_array($__env['SMTP'] ?? null) ? $__env['SMTP'] : ['host' => '', 'port' => 587, 'user' => '', 'pass' => '', 'from' => ''];

function json_out($data, $code = 200) {
    /* discard any stray bytes (PHP notices, accidental echoes) that would corrupt JSON */
    if (ob_get_level()) { while (ob_get_level()) { ob_end_clean(); } }
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        /* ── Phase 6: connection hardening ── */
        try { $pdo->exec('PRAGMA journal_mode=WAL'); } catch (Exception $e) { /* fall back to default */ }
        try { $pdo->exec('PRAGMA synchronous=NORMAL'); } catch (Exception $e) {}
        try { $pdo->exec('PRAGMA foreign_keys=ON'); } catch (Exception $e) {}
        try { $pdo->exec('PRAGMA busy_timeout=15000'); } catch (Exception $e) {}
        try { $pdo->exec('PRAGMA temp_store=MEMORY'); } catch (Exception $e) {}
        /* ── Schema bootstrap gate (2026-08-07): the idempotent migration block below
           used to run on EVERY request, taking SQLite write locks each time. Under
           concurrent dashboard/API calls the DDL write-write contention exceeded
           busy_timeout → uncaught "database is locked" 500s (82 in 24h, mostly
           /api/app-admin + /api/listings + /api/building-public bursts).
           PRAGMA user_version now gates it: migrations run once, then skip.
           ⚠ BUMP 20260809 to a higher number whenever adding new CREATE/ALTER
           statements to the block below, or they will never run on migrated DBs. ── */
        $__sv = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($__sv < 20260822) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT DEFAULT '', ip TEXT DEFAULT '',
            kind TEXT DEFAULT '', ok INTEGER DEFAULT 0, ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_auth_email_ts ON auth_attempts(email, ts)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_auth_ip_ts ON auth_attempts(ip, ts)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscribers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL, org TEXT DEFAULT '', email TEXT UNIQUE NOT NULL,
            phone TEXT DEFAULT '', role TEXT DEFAULT 'owner', plan TEXT DEFAULT 'Starter',
            status TEXT DEFAULT 'pending', trial_end TEXT, otp_hash TEXT, otp_expires TEXT,
            created_at TEXT DEFAULT (datetime('now')), verified_at TEXT)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, phone TEXT,
            subject TEXT, message TEXT, created_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS newsletter_emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL,
            created_at TEXT DEFAULT (datetime('now')))");

        /* ── Phase 2: app tables ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL, role TEXT NOT NULL, dept TEXT DEFAULT '',
            avatar TEXT DEFAULT '', is_staff INTEGER DEFAULT 1, active INTEGER DEFAULT 1,
            last_login TEXT)");
        $au_cols = array_column($pdo->query('PRAGMA table_info(app_users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('totp_secret', $au_cols, true)) $pdo->exec("ALTER TABLE app_users ADD COLUMN totp_secret TEXT DEFAULT ''");
        if (!in_array('totp_enabled', $au_cols, true)) $pdo->exec("ALTER TABLE app_users ADD COLUMN totp_enabled INTEGER DEFAULT 0");
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_tokens (
            token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, kind TEXT DEFAULT 'sub',
            created_at TEXT DEFAULT (datetime('now')), expires_at TEXT)");
        $at_cols = array_column($pdo->query('PRAGMA table_info(app_tokens)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('impersonator', $at_cols, true)) $pdo->exec("ALTER TABLE app_tokens ADD COLUMN impersonator TEXT DEFAULT ''");
        /* ── SA1-fullsite v8 (v3.62): outbound webhooks — per-tenant endpoint subscriptions + delivery log ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS webhooks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER DEFAULT 0,
            label TEXT DEFAULT '',
            url TEXT NOT NULL,
            secret TEXT DEFAULT '',
            events TEXT DEFAULT '[]',
            active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now')),
            last_status INTEGER DEFAULT 0,
            last_at TEXT,
            ok_count INTEGER DEFAULT 0,
            fail_count INTEGER DEFAULT 0)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS webhook_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hook INTEGER NOT NULL,
            event TEXT DEFAULT '',
            attempt INTEGER DEFAULT 1,
            ok INTEGER DEFAULT 0,
            status INTEGER DEFAULT 0,
            ms INTEGER DEFAULT 0,
            payload TEXT DEFAULT '',
            response TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_whlog_hook ON webhook_logs(hook, id)");
        /* ── SA1-fullsite v9 (v3.63): per-tenant API keys — subscribers get their own key (X-API-Key) ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_api_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            label TEXT DEFAULT '',
            key TEXT UNIQUE NOT NULL,
            active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now')),
            last_used_at TEXT DEFAULT '',
            last_ip TEXT DEFAULT '',
            calls INTEGER DEFAULT 0)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tak_tenant ON tenant_api_keys(tenant_id)");
        /* ── SA1-fullsite v11 (v3.65): tenant custom domains — CNAME/TXT verify + per-domain routing ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            domain TEXT UNIQUE NOT NULL,
            verify_token TEXT NOT NULL,
            verified INTEGER DEFAULT 0,
            verified_at TEXT DEFAULT '',
            last_checked_at TEXT DEFAULT '',
            last_check_note TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_td_tenant ON tenant_domains(tenant_id)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS plan_catalog (
            code TEXT PRIMARY KEY, name TEXT NOT NULL, price INTEGER NOT NULL,
            seats INTEGER DEFAULT 1, tag TEXT DEFAULT '', features TEXT NOT NULL)");
        /* ── P55: package columns (role-module matrix + limits) ── */
        $pc_cols = array_column($pdo->query('PRAGMA table_info(plan_catalog)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('modules', $pc_cols, true)) $pdo->exec("ALTER TABLE plan_catalog ADD COLUMN modules TEXT DEFAULT '[]'");
        if (!in_array('limits', $pc_cols, true)) $pdo->exec("ALTER TABLE plan_catalog ADD COLUMN limits TEXT DEFAULT '{}'");
        /* ── SA1 v18: self-heal plan_catalog limits/modules at boot. Live DB rows were written
             with '[]'/'{}' placeholders (seed endpoint never ran), so per-plan caps never
             applied there. Canonical values mirror seed_app() definitions. ── */
        $cmods = [
            'starter' => json_encode(['owner' => ['dashboard','properties','units','tenants','leases','renewals','invoices','receipts','payments','taxes','statements','notices','documents','subscriptions','analytics'], 'tenant' => ['dashboard','invoices','receipts','payments','maintenance','notices','documents']]),
            'business' => json_encode(['owner' => ['dashboard','properties','units','tenants','leases','renewals','invoices','receipts','payments','taxes','statements','remit','maintenance','vendors','utilities','compliance','legal','trust','onboarding','leads','notices','documents','templates','referrals','subscriptions','analytics','ai'], 'manager' => ['dashboard','properties','units','tenants','leases','renewals','invoices','receipts','payments','taxes','statements','remit','maintenance','vendors','utilities','compliance','legal','trust','onboarding','leads','notices','documents','templates','analytics','ai'], 'tenant' => ['dashboard','invoices','receipts','payments','maintenance','notices','documents','ai']]),
            'enterprise' => json_encode(['owner' => ROLE_MODULES()['owner'], 'manager' => ROLE_MODULES()['manager'], 'svc_mgr' => ROLE_MODULES()['svc_mgr'], 'tenant' => ROLE_MODULES()['tenant'], 'partner' => ROLE_MODULES()['partner'], 'legal' => ROLE_MODULES()['legal'], 'crm' => ROLE_MODULES()['crm'], 'accountant' => ROLE_MODULES()['accountant'], 'hr' => ROLE_MODULES()['hr']]),
        ];
        $clims = [
            'starter' => json_encode(['property_limit'=>1,'unit_limit'=>5,'seats'=>1,'kr_ai'=>false,'api_access'=>false,'reports'=>true]),
            'business' => json_encode(['property_limit'=>10,'unit_limit'=>50,'seats'=>3,'kr_ai'=>true,'api_access'=>false,'reports'=>true]),
            'enterprise' => json_encode(['property_limit'=>9999,'unit_limit'=>99999,'seats'=>10,'kr_ai'=>true,'api_access'=>true,'reports'=>true]),
        ];
        foreach ($cmods as $cc => $cm) {
            $cr = $pdo->prepare('SELECT modules, limits FROM plan_catalog WHERE lower(code)=?'); $cr->execute([$cc]);
            $crow = $cr->fetch(PDO::FETCH_ASSOC);
            if ($crow) {
                if (empty($crow['modules']) || $crow['modules'] === '[]' || $crow['modules'] === '{}')
                    $pdo->prepare('UPDATE plan_catalog SET modules=? WHERE lower(code)=?')->execute([$cm, $cc]);
                if (empty($crow['limits']) || $crow['limits'] === '{}' || $crow['limits'] === '[]')
                    $pdo->prepare('UPDATE plan_catalog SET limits=? WHERE lower(code)=?')->execute([$clims[$cc], $cc]);
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT DEFAULT (datetime('now')),
            user TEXT, action TEXT, module TEXT, entity TEXT, details TEXT)");
        /* tenants must exist before the photo-column migration below (fresh-DB fix) */
        $pdo->exec("CREATE TABLE IF NOT EXISTS tenants (
            id TEXT PRIMARY KEY, name TEXT, phone TEXT, email TEXT, nid TEXT,
            nrb INTEGER DEFAULT 0, kind TEXT DEFAULT 'Individual', sub_email TEXT DEFAULT '')");
        $pc2 = array_column($pdo->query('PRAGMA table_info(tenants)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('photo', $pc2, true)) $pdo->exec("ALTER TABLE tenants ADD COLUMN photo TEXT DEFAULT ''");
        if (!in_array('family', $pc2, true)) $pdo->exec("ALTER TABLE tenants ADD COLUMN family TEXT DEFAULT ''");    /* JSON array: family members (individual tenants) */
        if (!in_array('company', $pc2, true)) $pdo->exec("ALTER TABLE tenants ADD COLUMN company TEXT DEFAULT ''");  /* JSON object: company profile (corporate tenants) */
        $pc3 = array_column($pdo->query('PRAGMA table_info(app_users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('photo', $pc3, true)) $pdo->exec("ALTER TABLE app_users ADD COLUMN photo TEXT DEFAULT ''");
        $pc4 = array_column($pdo->query('PRAGMA table_info(subscribers)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach (['photo','address','city','industry','company_size','website','notes','tags','country','source'] as $scc) {
            if (!in_array($scc, $pc4, true)) $pdo->exec("ALTER TABLE subscribers ADD COLUMN $scc TEXT DEFAULT ''");
        }
        /* ── SA1 v16: advanced profile fields across admin entities (idempotent) ── */
        $mig = [
            'app_users'         => ['phone','title','employee_id','joined_at','address','notes'],
            'partners'          => ['phone','email','address','city','hourly_rate','specialties','notes'],
            'plan_catalog'      => ['description','billing_cycle','trial_days','popular','sort_order','color'],
            'blog_posts'        => ['author','meta_title','meta_desc','cover_image','category'],
            'platform_tickets'  => ['category','due_at','tags'],
            'webhooks'          => ['description','max_retries'],
            'integrations'      => ['description'],
            'company_ledger'    => ['note','payee'],
            'leads'             => ['budget','move_in'],
            'onboarding_apps'   => ['occupation','employer','reference'],
            'referrals'         => ['referred_phone'],
            'properties'        => ['sub_email','address','photo','description','featured','created_at'],
            'units'             => ['sub_email','beds','baths','furnished'],
        ];
        foreach ($mig as $tbl => $cols) {
            $have = array_column($pdo->query("PRAGMA table_info($tbl)")->fetchAll(PDO::FETCH_ASSOC), 'name');
            foreach ($cols as $c) {
                if (!in_array($c, $have, true)) {
                    try { $pdo->exec("ALTER TABLE $tbl ADD COLUMN $c TEXT DEFAULT ''"); } catch (Exception $e) {}
                }
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS properties (
            id TEXT PRIMARY KEY, name TEXT, type TEXT, jur TEXT, holding TEXT,
            sqft INTEGER DEFAULT 0, value INTEGER DEFAULT 0, status TEXT DEFAULT 'Active')");
        /* SA1 v25: map coordinates for public listings/building pages */
        $p_cols = array_map(fn($r) => $r['name'], $pdo->query('PRAGMA table_info(properties)')->fetchAll(PDO::FETCH_ASSOC));
        if (!in_array('lat', $p_cols, true)) { try { $pdo->exec("ALTER TABLE properties ADD COLUMN lat REAL DEFAULT 0"); } catch (Exception $e) {} }
        if (!in_array('lng', $p_cols, true)) { try { $pdo->exec("ALTER TABLE properties ADD COLUMN lng REAL DEFAULT 0"); } catch (Exception $e) {} }
        $pdo->exec("CREATE TABLE IF NOT EXISTS units (
            id TEXT PRIMARY KEY, p TEXT, name TEXT, floor TEXT, sqft INTEGER DEFAULT 0, status TEXT DEFAULT 'Vacant', rent INTEGER DEFAULT 0)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS property_rent (
            prop TEXT PRIMARY KEY, service_charge_pct REAL DEFAULT 0, utility_advance INTEGER DEFAULT 0,
            parking_fee INTEGER DEFAULT 0, escalation_pct REAL DEFAULT 0, advance_months INTEGER DEFAULT 0,
            due_day INTEGER DEFAULT 5, late_fee_pct REAL DEFAULT 0, rent_per_sqft INTEGER DEFAULT 0,
            notes TEXT DEFAULT '')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS amenities (
            id TEXT PRIMARY KEY, prop TEXT, unit TEXT DEFAULT '', name TEXT, icon TEXT DEFAULT '✨',
            category TEXT DEFAULT 'Building', status TEXT DEFAULT 'Active')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tenants (
            id TEXT PRIMARY KEY, name TEXT, phone TEXT, email TEXT, nid TEXT,
            nrb INTEGER DEFAULT 0, kind TEXT DEFAULT 'Individual', sub_email TEXT DEFAULT '')");
        /* ── P56.2c: tenant private note (owner-only) + tenant chat thread ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_notes (
            tenant_id TEXT PRIMARY KEY, note TEXT DEFAULT '', updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id TEXT NOT NULL, sender TEXT DEFAULT '',
            sender_role TEXT DEFAULT '', body TEXT NOT NULL, ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tenant_msgs ON tenant_messages(tenant_id, id)");
        /* ── SA1: super-admin panel tables ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_settings (
            k TEXT PRIMARY KEY, v TEXT DEFAULT '')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cms_content (
            page TEXT NOT NULL, section TEXT NOT NULL, k TEXT NOT NULL, v TEXT DEFAULT '',
            PRIMARY KEY (page, section, k))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cms_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT, page TEXT NOT NULL, section TEXT NOT NULL,
            k TEXT NOT NULL, v TEXT DEFAULT '', by TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cms_vis (
            page TEXT NOT NULL, section TEXT NOT NULL, visible INTEGER DEFAULT 1,
            PRIMARY KEY (page, section))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cms_pub (
            page TEXT PRIMARY KEY, published INTEGER DEFAULT 1, updated_at TEXT DEFAULT (datetime('now')))");
        $cms_pub_cols = array_column($pdo->query('PRAGMA table_info(cms_pub)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('publish_at', $cms_pub_cols, true)) $pdo->exec("ALTER TABLE cms_pub ADD COLUMN publish_at TEXT DEFAULT NULL");
        $pdo->exec("CREATE TABLE IF NOT EXISTS blog_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE, title TEXT NOT NULL, tag TEXT DEFAULT '',
            excerpt TEXT DEFAULT '', body TEXT DEFAULT '', cover_emoji TEXT DEFAULT '📰',
            read_min INTEGER DEFAULT 5, status TEXT DEFAULT 'draft',
            author TEXT DEFAULT '', meta_title TEXT DEFAULT '', meta_desc TEXT DEFAULT '',
            cover_image TEXT DEFAULT '', category TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cms_hist_page ON cms_history(page)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_usage (
            id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT NOT NULL, method TEXT DEFAULT 'POST',
            auth TEXT DEFAULT 'public', status INTEGER DEFAULT 200, ms INTEGER DEFAULT 0,
            ip_hash TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_api_usage_ts ON api_usage(ts)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_api_usage_action ON api_usage(action)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS integrations (
            id TEXT PRIMARY KEY, type TEXT DEFAULT 'third_party', name TEXT DEFAULT '',
            config TEXT DEFAULT '{}', enabled INTEGER DEFAULT 0, status TEXT DEFAULT 'unconfigured',
            updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS platform_tickets (
            id TEXT PRIMARY KEY, from_name TEXT DEFAULT '', from_email TEXT DEFAULT '',
            subject TEXT NOT NULL, body TEXT DEFAULT '', status TEXT DEFAULT 'Open',
            prio TEXT DEFAULT 'Normal', assignee TEXT DEFAULT '', reply TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS company_ledger (
            id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT DEFAULT 'income', cat TEXT DEFAULT 'subscription',
            label TEXT NOT NULL, amount INTEGER NOT NULL DEFAULT 0, method TEXT DEFAULT '',
            ref TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS backup_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT DEFAULT 'db', size INTEGER DEFAULT 0,
            note TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS leases (
            id TEXT PRIMARY KEY, u TEXT, t TEXT, start TEXT, end TEXT,
            rent INTEGER DEFAULT 0, adv INTEGER DEFAULT 0, res INTEGER DEFAULT 1,
            reg_office TEXT DEFAULT '', reg_deed TEXT DEFAULT '', status TEXT DEFAULT 'Active')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
            id TEXT PRIMARY KEY, l TEXT, m TEXT, gross INTEGER DEFAULT 0,
            tds INTEGER DEFAULT 0, net INTEGER DEFAULT 0, status TEXT DEFAULT 'Unpaid')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS receipts (
            id TEXT PRIMARY KEY, inv TEXT, amount INTEGER DEFAULT 0,
            date TEXT, method TEXT, sig TEXT DEFAULT '')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
            id TEXT PRIMARY KEY, inv TEXT, amount INTEGER DEFAULT 0, method TEXT,
            ref TEXT DEFAULT '', date TEXT, status TEXT DEFAULT 'Success')");
        $pp_cols = []; $st0 = $pdo->query("PRAGMA table_info(payments)"); foreach ($st0->fetchAll(PDO::FETCH_ASSOC) as $c) $pp_cols[] = $c['name'];
        if (!in_array('proof', $pp_cols, true)) $pdo->exec("ALTER TABLE payments ADD COLUMN proof TEXT DEFAULT ''");
        if (!in_array('proof_note', $pp_cols, true)) $pdo->exec("ALTER TABLE payments ADD COLUMN proof_note TEXT DEFAULT ''");
        if (!in_array('proof_at', $pp_cols, true)) $pdo->exec("ALTER TABLE payments ADD COLUMN proof_at TEXT DEFAULT ''");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
            id TEXT PRIMARY KEY, u TEXT, desc TEXT, reported TEXT, liab TEXT,
            status TEXT DEFAULT 'Open', con TEXT DEFAULT '', cost INTEGER DEFAULT 0)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS partners (
            id TEXT PRIMARY KEY, name TEXT, trade TEXT, rating REAL DEFAULT 0,
            jobs INTEGER DEFAULT 0, status TEXT DEFAULT 'Active', sub_email TEXT DEFAULT '')");
        /* SA1 v19: push notification subscriptions (one row per device) */
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_subs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sub_email TEXT NOT NULL,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            ua TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now')))");
        /* SA1 v20: subscriber team members (sub-accounts sharing the parent workspace) */
        $pdo->exec("CREATE TABLE IF NOT EXISTS team_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sub_email TEXT NOT NULL,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'manager',
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT DEFAULT (datetime('now')),
            last_login TEXT DEFAULT '')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff (
            id TEXT PRIMARY KEY, name TEXT, role TEXT, dept TEXT, status TEXT DEFAULT 'Active')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS support (
            id TEXT PRIMARY KEY, from_t TEXT, subject TEXT, status TEXT,
            prio TEXT, age TEXT)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS platform_meta (\n            k TEXT PRIMARY KEY, v TEXT)");
        /* ── SA1 v18: per-subscriber plan limits — scope properties/units to an owning subscriber.
             One-time backfill: seeded demo portfolio belongs to the Enterprise owner; future
             subscriber-created rows are stamped at create time by app-crud enforcement. ── */
        $pb = (int)$pdo->query("SELECT COUNT(*) FROM platform_meta WHERE k='sub_email_backfill_v1'")->fetchColumn();
        if (!$pb) {
            try { $pdo->exec("UPDATE properties SET sub_email='owner@krtaker.com' WHERE sub_email=''"); } catch (Exception $e) {}
            try { $pdo->exec("UPDATE units SET sub_email=(SELECT sub_email FROM properties WHERE properties.id=units.p) WHERE sub_email=''"); } catch (Exception $e) {}
            try { $pdo->prepare('INSERT OR REPLACE INTO platform_meta (k,v) VALUES (?,?)')->execute(['sub_email_backfill_v1','1']); } catch (Exception $e) {}
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS cases (
            id TEXT PRIMARY KEY, title TEXT, ref_lease TEXT DEFAULT '', type TEXT,
            status TEXT DEFAULT 'Open', opened TEXT, notes TEXT DEFAULT '')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS gateway_tx (
            id TEXT PRIMARY KEY, invoice_id TEXT, method TEXT, amount INTEGER DEFAULT 0,
            status TEXT DEFAULT 'pending', ref TEXT DEFAULT '', created_at TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')))");
        $gtx = [];
        foreach ($pdo->query('PRAGMA table_info(gateway_tx)') as $c) $gtx[] = $c['name'];
        if (!in_array('gw_ref', $gtx)) $pdo->exec("ALTER TABLE gateway_tx ADD COLUMN gw_ref TEXT DEFAULT ''");
        if (!in_array('gw_url', $gtx)) $pdo->exec("ALTER TABLE gateway_tx ADD COLUMN gw_url TEXT DEFAULT ''");
        /* ── Phase 8: tenant operations — tickets thread, documents, notices ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_thread (
            id INTEGER PRIMARY KEY AUTOINCREMENT, ticket TEXT NOT NULL,
            author TEXT DEFAULT '', body TEXT NOT NULL,
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_thread_ticket ON ticket_thread(ticket, id)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS documents (
            id TEXT PRIMARY KEY, kind TEXT DEFAULT 'lease', ref TEXT DEFAULT '',
            name TEXT NOT NULL, fname TEXT NOT NULL, size INTEGER DEFAULT 0,
            mime TEXT DEFAULT 'application/octet-stream', uploaded_by TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')))");
        $dcols = [];
        foreach ($pdo->query('PRAGMA table_info(documents)') as $c) $dcols[] = $c['name'];
        if (!in_array('p', $dcols)) $pdo->exec("ALTER TABLE documents ADD COLUMN p TEXT DEFAULT ''");
        if (!in_array('cat', $dcols)) $pdo->exec("ALTER TABLE documents ADD COLUMN cat TEXT DEFAULT 'other'");
        /* ── Phase 13: property handover checklists (move-in / move-out) ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS handover_checklists (
            id TEXT PRIMARY KEY, lease TEXT NOT NULL, kind TEXT DEFAULT 'move_in',
            items TEXT NOT NULL, status TEXT DEFAULT 'In Progress',
            created_by TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hovo_lease ON handover_checklists(lease, kind)");
        /* ── Phase 15: rent reminder escalation log (tier 1/7/15) ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_reminders (
            invoice_id TEXT PRIMARY KEY, tier INTEGER DEFAULT 0,
            sent_at TEXT DEFAULT (datetime('now')), via TEXT DEFAULT 'email')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sms_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT, to_phone TEXT DEFAULT '', message TEXT DEFAULT '',
            provider TEXT DEFAULT '', ref TEXT DEFAULT '', status TEXT DEFAULT 'sent',
            ts TEXT DEFAULT (datetime('now')))");
        /* ── Phase 16: lease renewal requests + utility meter readings ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS renewal_requests (
            id TEXT PRIMARY KEY, lease TEXT NOT NULL, tenant TEXT NOT NULL,
            months INTEGER DEFAULT 12, new_rent INTEGER DEFAULT 0, note TEXT DEFAULT '',
            status TEXT DEFAULT 'Pending', decided_by TEXT DEFAULT '', decided_at TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rr_lease ON renewal_requests(lease, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS meter_readings (
            id TEXT PRIMARY KEY, unit TEXT NOT NULL, tenant TEXT DEFAULT '',
            type TEXT NOT NULL, reading INTEGER NOT NULL, month TEXT NOT NULL,
            note TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_mr_unit_type_month ON meter_readings(unit, type, month)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mr_unit ON meter_readings(unit, type, month)");
        /* Phase 20: utility billing — tariffs + generated bills (one per unit/type/month) */
        $pdo->exec("CREATE TABLE IF NOT EXISTS utility_tariffs (
            type TEXT PRIMARY KEY, rate REAL NOT NULL DEFAULT 0, standing INTEGER NOT NULL DEFAULT 0,
            unit_label TEXT DEFAULT '', enabled INTEGER DEFAULT 1, updated_at TEXT)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS utility_bills (
            id TEXT PRIMARY KEY, unit TEXT NOT NULL, tenant TEXT DEFAULT '', type TEXT NOT NULL,
            month TEXT NOT NULL, prev_reading INTEGER NOT NULL DEFAULT 0, curr_reading INTEGER NOT NULL DEFAULT 0,
            usage INTEGER NOT NULL DEFAULT 0, rate REAL NOT NULL DEFAULT 0, standing INTEGER NOT NULL DEFAULT 0,
            amount INTEGER NOT NULL DEFAULT 0, status TEXT DEFAULT 'Unpaid', paid_at TEXT, note TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ub_unit_type_month ON utility_bills(unit, type, month)");
        /* Phase 21: settlement statements + no-dues certificates */
        $pdo->exec("CREATE TABLE IF NOT EXISTS settlement_reports (
            id TEXT PRIMARY KEY, tenant TEXT NOT NULL, lease TEXT NOT NULL,
            status TEXT NOT NULL, total_due INTEGER DEFAULT 0, balance INTEGER DEFAULT 0, refund INTEGER DEFAULT 0,
            payload TEXT NOT NULL, generated_by TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_settle_tenant ON settlement_reports(tenant, ts)");
        /* Phase 22: NRB premium tier — remote caretaker subscriptions */
        $pdo->exec("CREATE TABLE IF NOT EXISTS caretaker_subs (
            id TEXT PRIMARY KEY, user_email TEXT NOT NULL, prop TEXT DEFAULT '',
            tier TEXT NOT NULL DEFAULT 'nrb_caretaker', price INTEGER NOT NULL DEFAULT 0,
            cycle TEXT DEFAULT 'monthly', status TEXT DEFAULT 'active',
            start TEXT DEFAULT (datetime('now')), end TEXT DEFAULT '', next_invoice TEXT DEFAULT '',
            features TEXT NOT NULL DEFAULT '[]', created_by TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cs_email ON caretaker_subs(user_email, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS caretaker_invoices (
            id TEXT PRIMARY KEY, sub TEXT NOT NULL, user_email TEXT NOT NULL,
            month TEXT NOT NULL, amount INTEGER NOT NULL DEFAULT 0,
            status TEXT DEFAULT 'Unpaid', paid_at TEXT DEFAULT '', ref TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now')), UNIQUE(sub, month))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ci_email ON caretaker_invoices(user_email, month)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS insurance_plans (
            id TEXT PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL DEFAULT 'rent_guarantee',
            premium INTEGER NOT NULL DEFAULT 0, coverage INTEGER NOT NULL DEFAULT 0,
            coverage_label TEXT DEFAULT '', features TEXT NOT NULL DEFAULT '[]',
            status TEXT DEFAULT 'Active')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS insurance_policies (
            id TEXT PRIMARY KEY, tenant TEXT NOT NULL, plan TEXT NOT NULL, lease TEXT DEFAULT '',
            premium INTEGER NOT NULL DEFAULT 0, coverage INTEGER NOT NULL DEFAULT 0,
            score INTEGER DEFAULT 0, status TEXT DEFAULT 'active',
            start TEXT DEFAULT (datetime('now')), end TEXT DEFAULT '',
            claim TEXT DEFAULT '', claim_amt INTEGER DEFAULT 0, claim_ts TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pol_tenant ON insurance_policies(tenant, status)");
        /* ── Phase 30: maintenance & repairs workflow ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_requests (
            id TEXT PRIMARY KEY, tenant TEXT DEFAULT '', unit TEXT DEFAULT '', prop TEXT DEFAULT '',
            category TEXT DEFAULT 'other', priority TEXT DEFAULT 'medium',
            title TEXT NOT NULL, desc TEXT DEFAULT '',
            status TEXT DEFAULT 'Open', assigned_to TEXT DEFAULT '', vendor TEXT DEFAULT '',
            cost_estimate INTEGER DEFAULT 0, actual_cost INTEGER DEFAULT 0,
            charge_to TEXT DEFAULT 'owner', created_by TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mr_unit ON maintenance_requests(unit, status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mr_prop ON maintenance_requests(prop, status)");
        /* ── Phase 31: leasing pipeline (leads) ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS leads (
            id TEXT PRIMARY KEY, name TEXT NOT NULL, phone TEXT DEFAULT '', email TEXT DEFAULT '',
            prop TEXT DEFAULT '', source TEXT DEFAULT 'listing', message TEXT DEFAULT '',
            status TEXT DEFAULT 'New', assigned_to TEXT DEFAULT '', notes TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lead_status ON leads(status, ts)");
        /* ── Phase 32: owner statements & P&L (payout ledger) ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS statement_payouts (
            prop TEXT NOT NULL, month TEXT NOT NULL, amount INTEGER DEFAULT 0,
            status TEXT DEFAULT 'Scheduled', method TEXT DEFAULT '', ref TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), PRIMARY KEY (prop, month))");
        /* ── Phase 33: compliance & expiry tracker ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS compliance_items (
            id TEXT PRIMARY KEY, entity_type TEXT DEFAULT 'property', entity_id TEXT DEFAULT '',
            item TEXT DEFAULT 'other', label TEXT NOT NULL, ref_no TEXT DEFAULT '',
            issue_date TEXT DEFAULT '', expiry_date TEXT DEFAULT '', status TEXT DEFAULT 'active',
            notes TEXT DEFAULT '', last_reminded TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')),
            updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cpl_exp ON compliance_items(expiry_date)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cpl_entity ON compliance_items(entity_type, entity_id)");
        
        /* ── Phase 36: vendor management — partner invoices + payout ledger ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS partner_invoices (
            id TEXT PRIMARY KEY, partner TEXT NOT NULL, job TEXT DEFAULT '',
            amount INTEGER NOT NULL DEFAULT 0, desc TEXT DEFAULT '',
            status TEXT DEFAULT 'Submitted', submitted_by TEXT DEFAULT '',
            decided_by TEXT DEFAULT '', decided_at TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pi_partner ON partner_invoices(partner, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS vendor_payouts (
            id TEXT PRIMARY KEY, partner TEXT NOT NULL, month TEXT NOT NULL,
            amount INTEGER NOT NULL DEFAULT 0, status TEXT DEFAULT 'Scheduled',
            method TEXT DEFAULT '', ref TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), UNIQUE(partner, month))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vp_partner ON vendor_payouts(partner, month)");
        $mcols = [];
        foreach ($pdo->query('PRAGMA table_info(maintenance_requests)') as $c) $mcols[] = $c['name'];
        if (!in_array('qc_by', $mcols)) $pdo->exec("ALTER TABLE maintenance_requests ADD COLUMN qc_by TEXT DEFAULT ''");
        if (!in_array('qc_at', $mcols)) $pdo->exec("ALTER TABLE maintenance_requests ADD COLUMN qc_at TEXT DEFAULT ''");
        if (!in_array('memo', $mcols)) $pdo->exec("ALTER TABLE maintenance_requests ADD COLUMN memo TEXT DEFAULT ''");
        if (!in_array('pay_release', $mcols)) $pdo->exec("ALTER TABLE maintenance_requests ADD COLUMN pay_release INTEGER DEFAULT 0");
        if (!in_array('pay_release_at', $mcols)) $pdo->exec("ALTER TABLE maintenance_requests ADD COLUMN pay_release_at TEXT DEFAULT ''");
        if (!in_array('pay_paid', $mcols)) $pdo->exec("ALTER TABLE maintenance_requests ADD COLUMN pay_paid INTEGER DEFAULT 0");
        if (!in_array('pay_paid_at', $mcols)) $pdo->exec("ALTER TABLE maintenance_requests ADD COLUMN pay_paid_at TEXT DEFAULT ''");
        /* ── Phase 42: contractor marketplace v2 — job evidence + pay-on-signoff ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS job_media (
            id TEXT PRIMARY KEY, job TEXT NOT NULL, kind TEXT DEFAULT 'before',
            name TEXT NOT NULL, fname TEXT NOT NULL, size INTEGER DEFAULT 0,
            mime TEXT DEFAULT 'application/octet-stream', uploaded_by TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_jm_job ON job_media(job, kind)");

        /* ── Phase 43: Trust Engine v2 — NID verification + Thana Tenant Information Form ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS nid_verifications (
            id TEXT PRIMARY KEY, tenant TEXT NOT NULL, nid TEXT DEFAULT '', dob TEXT DEFAULT '',
            status TEXT DEFAULT 'unverified', method TEXT DEFAULT 'checksum', checksum_ok INTEGER DEFAULT 0,
            age_ok INTEGER DEFAULT 0, notes TEXT DEFAULT '', verified_by TEXT DEFAULT '',
            verified_at TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nv_tenant ON nid_verifications(tenant, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS thana_forms (
            id TEXT PRIMARY KEY, tenant TEXT NOT NULL, unit TEXT DEFAULT '', prop TEXT DEFAULT '',
            thana TEXT DEFAULT '', district TEXT DEFAULT '', status TEXT DEFAULT 'Draft',
            payload TEXT DEFAULT '{}', submitted_at TEXT DEFAULT '', verified_by TEXT DEFAULT '',
            verified_at TEXT DEFAULT '', created_by TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tf_tenant ON thana_forms(tenant, status)");

        /* ── Phase 44: Legal Engine v2 — statutory notices, disputes, audit ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS legal_notices (
            id TEXT PRIMARY KEY, ntype TEXT NOT NULL, lease TEXT DEFAULT '', tenant TEXT DEFAULT '',
            unit TEXT DEFAULT '', reason TEXT DEFAULT '', body TEXT DEFAULT '',
            notice_days INTEGER NOT NULL DEFAULT 0, effective_date TEXT DEFAULT '',
            served_on TEXT DEFAULT '', served_by TEXT DEFAULT '', status TEXT DEFAULT 'Draft',
            created_by TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ln_tenant ON legal_notices(tenant, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS case_events (
            id TEXT PRIMARY KEY, case_id TEXT NOT NULL, ev_type TEXT DEFAULT 'note',
            body TEXT DEFAULT '', actor TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ce_case ON case_events(case_id)");
        $cols = array_column($pdo->query("PRAGMA table_info(cases)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach (['stage' => "TEXT DEFAULT 'Notice'", 'lawyer' => "TEXT DEFAULT ''", 'next_hearing' => "TEXT DEFAULT ''", 'updated_at' => "TEXT DEFAULT ''"] as $c => $ddl) {
            if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE cases ADD COLUMN $c $ddl");
        }

        /* ── Phase 37: NRB remittance & payout tracking ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS remittances (
            id TEXT PRIMARY KEY, owner_email TEXT NOT NULL, owner_name TEXT DEFAULT '',
            prop TEXT DEFAULT '', month TEXT NOT NULL,
            amount INTEGER NOT NULL DEFAULT 0, rate REAL NOT NULL DEFAULT 0,
            amount_fx INTEGER NOT NULL DEFAULT 0, currency TEXT DEFAULT 'USD',
            method TEXT DEFAULT '', ref TEXT DEFAULT '', form_c TEXT DEFAULT '',
            status TEXT DEFAULT 'Sent', confirmed_at TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), UNIQUE(owner_email, prop, month))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rem_month ON remittances(month, status)");

        /* ── Phase 38: tenant onboarding & e-KYC ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS onboarding_apps (
            id TEXT PRIMARY KEY, lead TEXT DEFAULT '', name TEXT NOT NULL, email TEXT DEFAULT '',
            phone TEXT DEFAULT '', nid TEXT DEFAULT '', unit TEXT DEFAULT '', prop TEXT DEFAULT '',
            rent INTEGER DEFAULT 0, adv INTEGER DEFAULT 0, months INTEGER DEFAULT 12, start TEXT DEFAULT '',
            status TEXT DEFAULT 'Started', notes TEXT DEFAULT '',
            verified_by TEXT DEFAULT '', verified_at TEXT DEFAULT '',
            tenant_id TEXT DEFAULT '', lease_id TEXT DEFAULT '', invoice_id TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ob_status ON onboarding_apps(status, ts)");
        /* ── Phase 39: maintenance SLA + vendor ratings ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS sla_config (
            priority TEXT PRIMARY KEY, response_hours INTEGER NOT NULL DEFAULT 24,
            resolve_hours INTEGER NOT NULL DEFAULT 72, enabled INTEGER DEFAULT 1)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS vendor_ratings (
            id TEXT PRIMARY KEY, partner TEXT NOT NULL, job TEXT DEFAULT '',
            rating INTEGER NOT NULL DEFAULT 0, comment TEXT DEFAULT '',
            rated_by TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')),
            UNIQUE(partner, job))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vr_partner ON vendor_ratings(partner)");
        /* ── Phase 40: AI caretaker v2 — WhatsApp channel + proactive alert engine ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_channels (
            id TEXT PRIMARY KEY, user_key TEXT NOT NULL, phone TEXT NOT NULL,
            provider TEXT DEFAULT 'whatsapp', status TEXT DEFAULT 'active',
            ts TEXT DEFAULT (datetime('now')), UNIQUE(user_key, phone))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_wa_user ON wa_channels(user_key)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS kr_alerts (
            id TEXT PRIMARY KEY, user_key TEXT NOT NULL, type TEXT NOT NULL,
            severity TEXT DEFAULT 'info', title TEXT NOT NULL, body TEXT DEFAULT '',
            ref TEXT DEFAULT '', status TEXT DEFAULT 'open', voice_note TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), resolved_at TEXT DEFAULT '')");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_kra_user ON kr_alerts(user_key, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS kr_wa_msgs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_key TEXT NOT NULL,
            direction TEXT DEFAULT 'out', body TEXT NOT NULL, voice_note INTEGER DEFAULT 0,
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_krw_user ON kr_wa_msgs(user_key, id)");
        /* ── Phase 41: portfolio analytics — saved board reports ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS board_reports (
            id TEXT PRIMARY KEY, month TEXT NOT NULL, kind TEXT DEFAULT 'board',
            payload TEXT NOT NULL, created_by TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_br_month ON board_reports(month, kind)");
        /* ── Phase 45: Land/Plot Guard — anti-encroachment monitoring (NRB owners) ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS land_parcels (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', name TEXT NOT NULL,
            district TEXT DEFAULT '', upazila TEXT DEFAULT '', mouza TEXT DEFAULT '',
            khatian TEXT DEFAULT '', dag TEXT DEFAULT '', area TEXT DEFAULT '',
            lat TEXT DEFAULT '', lng TEXT DEFAULT '', prop TEXT DEFAULT '',
            monitor TEXT DEFAULT 'quarterly', status TEXT DEFAULT 'Secure',
            risk TEXT DEFAULT 'low', notes TEXT DEFAULT '', created_by TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lp_owner ON land_parcels(owner_email, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS land_visits (
            id TEXT PRIMARY KEY, parcel TEXT NOT NULL, agent TEXT DEFAULT '',
            visit_type TEXT DEFAULT 'field', scheduled_for TEXT DEFAULT '',
            completed_at TEXT DEFAULT '', status TEXT DEFAULT 'Scheduled',
            outcome TEXT DEFAULT '', findings TEXT DEFAULT '', checklist TEXT DEFAULT '[]',
            created_by TEXT DEFAULT '', completed_by TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lv_parcel ON land_visits(parcel, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS land_media (
            id TEXT PRIMARY KEY, visit TEXT NOT NULL, kind TEXT DEFAULT 'photo',
            name TEXT NOT NULL, fname TEXT DEFAULT '', size INTEGER DEFAULT 0,
            mime TEXT DEFAULT '', geo TEXT DEFAULT '', captured_at TEXT DEFAULT '',
            uploaded_by TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lm_visit ON land_media(visit, kind)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS land_events (
            id TEXT PRIMARY KEY, parcel TEXT NOT NULL, ev_type TEXT DEFAULT 'note',
            body TEXT DEFAULT '', actor TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_le_parcel ON land_events(parcel, ts)");
        /* ── Phase 46: NRB Hub — tax & repatriation, vacancy management, virtual dispute resolution ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS nrb_tax_returns (
            id TEXT PRIMARY KEY, owner_email TEXT NOT NULL, prop TEXT DEFAULT '',
            fy TEXT DEFAULT '', rental_income INTEGER DEFAULT 0, tds_deducted INTEGER DEFAULT 0,
            tax_estimate INTEGER DEFAULT 0, status TEXT DEFAULT 'Draft',
            ca_firm TEXT DEFAULT '', ca_ref TEXT DEFAULT '',
            filing_date TEXT DEFAULT '', paid_date TEXT DEFAULT '', notes TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nrbtx_owner ON nrb_tax_returns(owner_email, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS nrb_repatriations (
            id TEXT PRIMARY KEY, owner_email TEXT NOT NULL, amount_bdt INTEGER DEFAULT 0,
            amount_fx REAL DEFAULT 0, rate REAL DEFAULT 0, currency TEXT DEFAULT 'USD',
            method TEXT DEFAULT 'SWIFT', bank_channel TEXT DEFAULT 'NRP_Account',
            documents TEXT DEFAULT '[]', status TEXT DEFAULT 'Requested', ref TEXT DEFAULT '',
            submitted_at TEXT DEFAULT '', approved_at TEXT DEFAULT '', completed_at TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nrbrp_owner ON nrb_repatriations(owner_email, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS nrb_vacancies (
            id TEXT PRIMARY KEY, owner_email TEXT NOT NULL, prop TEXT DEFAULT '', unit TEXT DEFAULT '',
            status TEXT DEFAULT 'Vacant', started_at TEXT DEFAULT '', tenant_left TEXT DEFAULT '',
            target_rent INTEGER DEFAULT 0, channels TEXT DEFAULT '[]', listing_url TEXT DEFAULT '',
            candidate TEXT DEFAULT '{}', vet_status TEXT DEFAULT 'none',
            approved_at TEXT DEFAULT '', notes TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nrbvc_owner ON nrb_vacancies(owner_email, status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nrbvc_unit ON nrb_vacancies(unit)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS nrb_showings (
            id TEXT PRIMARY KEY, vacancy TEXT NOT NULL, prospect TEXT NOT NULL, phone TEXT DEFAULT '',
            scheduled_at TEXT DEFAULT '', status TEXT DEFAULT 'Scheduled',
            feedback TEXT DEFAULT '', rating INTEGER DEFAULT 0,
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nrbsh_vac ON nrb_showings(vacancy, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS nrb_disputes (
            id TEXT PRIMARY KEY, owner_email TEXT NOT NULL, prop TEXT DEFAULT '', unit TEXT DEFAULT '',
            tenant TEXT DEFAULT '', dtype TEXT DEFAULT 'other', advocate TEXT DEFAULT '',
            fee INTEGER DEFAULT 0, status TEXT DEFAULT 'Open', notice_ref TEXT DEFAULT '',
            last_update TEXT DEFAULT '', next_action TEXT DEFAULT '', timeline TEXT DEFAULT '[]',
            ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nrbds_owner ON nrb_disputes(owner_email, status)");
        /* ── Phase 47: Legal Concierge — namjari/mutation, e-Porcha/Khatian, holding-tax tracking ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS concierge_requests (
            id TEXT PRIMARY KEY, owner_email TEXT NOT NULL, parcel TEXT DEFAULT '', prop TEXT DEFAULT '',
            service TEXT NOT NULL, district TEXT DEFAULT '', upazila TEXT DEFAULT '', mouza TEXT DEFAULT '',
            khatian TEXT DEFAULT '', dag TEXT DEFAULT '', status TEXT DEFAULT 'Submitted',
            assigned_to TEXT DEFAULT '', est_days INTEGER DEFAULT 0, fee INTEGER DEFAULT 0,
            fee_status TEXT DEFAULT 'unpaid', fee_paid_at TEXT DEFAULT '', service_ref TEXT DEFAULT '',
            awaiting_fee_at TEXT DEFAULT '', completed_at TEXT DEFAULT '', notes TEXT DEFAULT '',
            timeline TEXT DEFAULT '[]', ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cr_owner ON concierge_requests(owner_email, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS concierge_docs (
            id TEXT PRIMARY KEY, request TEXT NOT NULL, kind TEXT DEFAULT 'other', name TEXT DEFAULT '',
            fname TEXT DEFAULT '', size INTEGER DEFAULT 0, mime TEXT DEFAULT '', uploaded_by TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cd_req ON concierge_docs(request)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS holding_taxes (
            id TEXT PRIMARY KEY, owner_email TEXT NOT NULL, parcel TEXT DEFAULT '', prop TEXT DEFAULT '',
            city_corp TEXT DEFAULT '', ward TEXT DEFAULT '', holding_no TEXT DEFAULT '', fy TEXT DEFAULT '',
            annual_value INTEGER DEFAULT 0, rate_pct REAL DEFAULT 0, tax_amount INTEGER DEFAULT 0,
            paid_amount INTEGER DEFAULT 0, status TEXT DEFAULT 'Due', due_date TEXT DEFAULT '',
            paid_date TEXT DEFAULT '', receipt_no TEXT DEFAULT '', notes TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ht_owner ON holding_taxes(owner_email, status)");
        /* ── Phase 48: Smart Home & Security (VAS-D) + Property Health Check (VAS-C) ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS smart_locks (
            id TEXT PRIMARY KEY, owner_email TEXT NOT NULL, prop TEXT DEFAULT '', unit TEXT DEFAULT '',
            lock_name TEXT DEFAULT '', model TEXT DEFAULT '', code TEXT DEFAULT '', status TEXT DEFAULT 'active',
            purpose TEXT DEFAULT 'other', grant_for TEXT DEFAULT '', showing TEXT DEFAULT '',
            valid_from TEXT DEFAULT '', valid_until TEXT DEFAULT '', used_at TEXT DEFAULT '',
            used_count INTEGER DEFAULT 0, notes TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sl_owner ON smart_locks(owner_email, status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sl_showing ON smart_locks(showing)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cctv_cameras (
            id TEXT PRIMARY KEY, owner_email TEXT NOT NULL, prop TEXT DEFAULT '', name TEXT DEFAULT '',
            location TEXT DEFAULT '', status TEXT DEFAULT 'online', feed_url TEXT DEFAULT '',
            last_seen TEXT DEFAULT '', notes TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cv_owner ON cctv_cameras(owner_email, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS health_plans (
            id TEXT PRIMARY KEY, owner_email TEXT NOT NULL, prop TEXT DEFAULT '', unit TEXT DEFAULT '',
            season TEXT DEFAULT 'quarterly', service TEXT DEFAULT 'deep_clean', scheduled_for TEXT DEFAULT '',
            status TEXT DEFAULT 'Planned', assigned_to TEXT DEFAULT '', cost INTEGER DEFAULT 0,
            paid INTEGER DEFAULT 0, checklist TEXT DEFAULT '[]', findings TEXT DEFAULT '',
            completed_at TEXT DEFAULT '', notes TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hp_owner ON health_plans(owner_email, status)");
        /* ── Phase 49: Build Watch (construction & renovation monitoring) ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS build_projects (
            id TEXT PRIMARY KEY, owner_email TEXT NOT NULL, prop TEXT DEFAULT '', title TEXT DEFAULT '',
            kind TEXT DEFAULT 'construction', status TEXT DEFAULT 'Planning', budget_total INTEGER DEFAULT 0,
            start_date TEXT DEFAULT '', target_end TEXT DEFAULT '', contractor TEXT DEFAULT '',
            architect TEXT DEFAULT '', site_address TEXT DEFAULT '', notes TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bp_owner ON build_projects(owner_email, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS build_milestones (
            id TEXT PRIMARY KEY, project TEXT NOT NULL, title TEXT DEFAULT '', phase TEXT DEFAULT 'structure',
            target_date TEXT DEFAULT '', status TEXT DEFAULT 'Pending', cost INTEGER DEFAULT 0,
            paid INTEGER DEFAULT 0, notes TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bm_project ON build_milestones(project, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS build_expenses (
            id TEXT PRIMARY KEY, project TEXT NOT NULL, label TEXT DEFAULT '', amount INTEGER DEFAULT 0,
            category TEXT DEFAULT 'material', spent_on TEXT DEFAULT '', paid INTEGER DEFAULT 0,
            notes TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bx_project ON build_expenses(project)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS build_media (
            id TEXT PRIMARY KEY, project TEXT NOT NULL, kind TEXT DEFAULT 'photo', name TEXT DEFAULT '',
            fname TEXT DEFAULT '', size INTEGER DEFAULT 0, mime TEXT DEFAULT '', geo TEXT DEFAULT '',
            captured_at TEXT DEFAULT '', uploaded_by TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bd_project ON build_media(project)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS gate_visits (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', prop TEXT DEFAULT '', vtype TEXT DEFAULT 'visitor',
            name TEXT DEFAULT '', phone TEXT DEFAULT '', vehicle_no TEXT DEFAULT '',
            unit TEXT DEFAULT '', purpose TEXT DEFAULT '', host_name TEXT DEFAULT '',
            check_in TEXT DEFAULT '', check_out TEXT DEFAULT '', status TEXT DEFAULT 'Inside',
            flagged INTEGER DEFAULT 0, notes TEXT DEFAULT '', photo TEXT DEFAULT '',
            created_by TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gv_prop ON gate_visits(prop, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS resident_vehicles (
            id TEXT PRIMARY KEY, prop TEXT DEFAULT '', unit TEXT DEFAULT '', vehicle_no TEXT NOT NULL,
            vtype TEXT DEFAULT 'car', label TEXT DEFAULT '', owner_name TEXT DEFAULT '',
            sticker_no TEXT DEFAULT '', status TEXT DEFAULT 'active', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rv_prop ON resident_vehicles(prop)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS gate_watchlist (
            id TEXT PRIMARY KEY, vehicle_no TEXT DEFAULT '', name TEXT DEFAULT '',
            reason TEXT DEFAULT '', active INTEGER DEFAULT 1, ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fire_assets (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', prop TEXT DEFAULT '', asset_type TEXT DEFAULT 'extinguisher',
            location TEXT DEFAULT '', model TEXT DEFAULT '', serial_no TEXT DEFAULT '',
            install_date TEXT DEFAULT '', expiry_date TEXT DEFAULT '', status TEXT DEFAULT 'active',
            last_inspected TEXT DEFAULT '', next_inspection TEXT DEFAULT '', notes TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fa_prop ON fire_assets(prop, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fire_incidents (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', prop TEXT DEFAULT '', incident_type TEXT DEFAULT 'fire',
            severity TEXT DEFAULT 'low', status TEXT DEFAULT 'Reported', reported_by TEXT DEFAULT '',
            occurred_at TEXT DEFAULT '', location TEXT DEFAULT '', description TEXT DEFAULT '',
            timeline TEXT DEFAULT '[]', resolved_at TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fi_prop ON fire_incidents(prop, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS evacuation_plans (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', prop TEXT DEFAULT '', name TEXT DEFAULT '',
            assembly_point TEXT DEFAULT '', route TEXT DEFAULT '', last_drill TEXT DEFAULT '',
            next_drill TEXT DEFAULT '', status TEXT DEFAULT 'draft', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS emergency_contacts (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', prop TEXT DEFAULT '', contact_type TEXT DEFAULT 'other',
            name TEXT DEFAULT '', phone TEXT DEFAULT '', notes TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_assets (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', prop TEXT DEFAULT '', asset_type TEXT DEFAULT 'other',
            location TEXT DEFAULT '', model TEXT DEFAULT '', serial_no TEXT DEFAULT '',
            install_date TEXT DEFAULT '', next_service TEXT DEFAULT '', service_interval_days INTEGER DEFAULT 90,
            cert_expiry TEXT DEFAULT '', cert_ref TEXT DEFAULT '', status TEXT DEFAULT 'operational',
            fuel_capacity INTEGER DEFAULT 0, fuel_level INTEGER DEFAULT 0, fuel_burn_rate REAL DEFAULT 0,
            notes TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sa_prop ON sys_assets(prop, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_services (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', asset TEXT DEFAULT '', service_date TEXT DEFAULT '',
            service_type TEXT DEFAULT 'routine', technician TEXT DEFAULT '', vendor TEXT DEFAULT '',
            cost INTEGER DEFAULT 0, hours_run INTEGER DEFAULT 0, notes TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ss_asset ON sys_services(asset, service_date)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_fuel (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', asset TEXT DEFAULT '', refill_date TEXT DEFAULT '',
            liters REAL DEFAULT 0, rate_per_litre REAL DEFAULT 0, amount INTEGER DEFAULT 0,
            vendor TEXT DEFAULT '', hours_run INTEGER DEFAULT 0, notes TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sf_asset ON sys_fuel(asset, refill_date)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS building_staff (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', name TEXT DEFAULT '', role TEXT DEFAULT 'other',
            phone TEXT DEFAULT '', join_date TEXT DEFAULT '', monthly_salary INTEGER DEFAULT 0,
            shift TEXT DEFAULT 'day', prop TEXT DEFAULT '', status TEXT DEFAULT 'active',
            notes TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bs_prop ON building_staff(prop, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_attendance (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', staff TEXT DEFAULT '', work_date TEXT DEFAULT '',
            check_in TEXT DEFAULT '', check_out TEXT DEFAULT '', status TEXT DEFAULT 'present',
            notes TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sa_unique ON staff_attendance(staff, work_date)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_payroll (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', staff TEXT DEFAULT '', month TEXT DEFAULT '',
            salary INTEGER DEFAULT 0, overtime INTEGER DEFAULT 0, bonus INTEGER DEFAULT 0,
            advance_deduction INTEGER DEFAULT 0, absent_days INTEGER DEFAULT 0, daily_rate INTEGER DEFAULT 0,
            net INTEGER DEFAULT 0, status TEXT DEFAULT 'Draft', paid_at TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')))");
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS samity_members (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', name TEXT DEFAULT '',
            role TEXT DEFAULT 'Member', phone TEXT DEFAULT '', since_date TEXT DEFAULT '',
            status TEXT DEFAULT 'active', notes TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sm_owner ON samity_members(owner_email, status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS samity_bills (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', unit TEXT DEFAULT '',
            month TEXT DEFAULT '', amount INTEGER DEFAULT 0, due_date TEXT DEFAULT '',
            status TEXT DEFAULT 'Pending', note TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sb_unit ON samity_bills(unit, month)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS samity_collections (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', bill TEXT DEFAULT '',
            amount INTEGER DEFAULT 0, method TEXT DEFAULT 'Cash', collected_at TEXT DEFAULT '',
            receipt_no TEXT DEFAULT '', note TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sc_bill ON samity_collections(bill)");$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sp_unique ON staff_payroll(staff, month)");
        /* VAS-S1 (20260811): samity expenses + per-property scoping */
        $pdo->exec("CREATE TABLE IF NOT EXISTS samity_expenses (
            id TEXT PRIMARY KEY, owner_email TEXT DEFAULT '', prop TEXT DEFAULT '',
            category TEXT DEFAULT 'other', title TEXT DEFAULT '', amount INTEGER DEFAULT 0,
            exp_date TEXT DEFAULT '', note TEXT DEFAULT '', created_by TEXT DEFAULT '',
            ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_se_prop ON samity_expenses(prop, exp_date)");
        if (!in_array('prop', $pdo->query('PRAGMA table_info(samity_members)')->fetchAll(PDO::FETCH_COLUMN, 1), true)) {
            $pdo->exec("ALTER TABLE samity_members ADD COLUMN prop TEXT DEFAULT ''");
            $pdo->exec("UPDATE samity_members SET prop='P-001' WHERE prop=''");  /* society flats live at Green View Residency */
        }
        if (!in_array('prop', $pdo->query('PRAGMA table_info(samity_bills)')->fetchAll(PDO::FETCH_COLUMN, 1), true)) {
            $pdo->exec("ALTER TABLE samity_bills ADD COLUMN prop TEXT DEFAULT ''");
            $pdo->exec("UPDATE samity_bills SET prop=(SELECT p FROM units WHERE units.id=samity_bills.unit) WHERE unit IN (SELECT id FROM units)");
        }
        if (!in_array('prop', $pdo->query('PRAGMA table_info(samity_collections)')->fetchAll(PDO::FETCH_COLUMN, 1), true)) {
            $pdo->exec("ALTER TABLE samity_collections ADD COLUMN prop TEXT DEFAULT ''");
            $pdo->exec("UPDATE samity_collections SET prop=(SELECT prop FROM samity_bills WHERE samity_bills.id=samity_collections.bill)");
        }




$defTariff = $pdo->prepare('INSERT OR IGNORE INTO utility_tariffs (type, rate, standing, unit_label, enabled) VALUES (?,?,?,?,1)');
        $defTariff->execute(['electric', 10.0, 0, 'kWh']);
        $defTariff->execute(['gas', 15.0, 0, 'm³']);
        $defTariff->execute(['water', 20.0, 0, 'm³']);
        $pdo->exec("CREATE TABLE IF NOT EXISTS notices (
            id TEXT PRIMARY KEY, title TEXT NOT NULL, body TEXT DEFAULT '',
            author TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')), pinned INTEGER DEFAULT 0)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS referrals (
            id TEXT PRIMARY KEY, code TEXT NOT NULL, user_email TEXT NOT NULL,
            role TEXT DEFAULT 'owner', referred_name TEXT DEFAULT '', referred_email TEXT DEFAULT '',
            status TEXT DEFAULT 'Pending', reward INTEGER DEFAULT 0, ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ref_code ON referrals(code)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ref_email ON referrals(user_email)");
        /* ── Phase 18: per-user settings (profile + preferences) ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_settings (
            user_key TEXT PRIMARY KEY, prefs TEXT NOT NULL DEFAULT '{}',
            updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS org_settings (
            k TEXT PRIMARY KEY, v TEXT DEFAULT '', updated_at TEXT DEFAULT (datetime('now')))");
        $pcols = [];
        foreach ($pdo->query('PRAGMA table_info(properties)') as $c) $pcols[] = $c['name'];
        if (!in_array('published', $pcols)) $pdo->exec("ALTER TABLE properties ADD COLUMN published INTEGER DEFAULT 0");
        /* Phase 16: asking rent per unit (shown on public listings) — idempotent */
        $ucols = [];
        foreach ($pdo->query('PRAGMA table_info(units)') as $c) $ucols[] = $c['name'];
        if (!in_array('rent', $ucols)) $pdo->exec("ALTER TABLE units ADD COLUMN rent INTEGER DEFAULT 0");
        $ask = ['U-001'=>25000,'U-002'=>32000,'U-003'=>28000,'U-004'=>450000,'U-005'=>380000,'U-006'=>350000,'U-007'=>35000,'U-008'=>210000,'U-009'=>180000,'U-010'=>40000];
        $ur = $pdo->prepare('UPDATE units SET rent=? WHERE id=? AND rent=0');
        foreach ($ask as $uid => $rv) $ur->execute([$rv, $uid]);
        /* ── Phase 12: document + email templates ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS doc_templates (
            id TEXT PRIMARY KEY, kind TEXT NOT NULL, name TEXT NOT NULL, title TEXT DEFAULT '',
            body TEXT NOT NULL, placeholders TEXT DEFAULT '', is_default INTEGER DEFAULT 0,
            updated_by TEXT DEFAULT '', updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_templates (
            id TEXT PRIMARY KEY, name TEXT NOT NULL, subject TEXT NOT NULL, body TEXT NOT NULL,
            updated_by TEXT DEFAULT '', updated_at TEXT DEFAULT (datetime('now')))");
        seed_templates($pdo);
        /* ── Phase 5: KR AI — legal knowledge base + FTS ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS legal_docs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, cat TEXT, title TEXT, body TEXT, kw TEXT)");
        try {
            $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS legal_docs_fts USING fts5(cat, title, body, kw)");
        } catch (Exception $e) {
            /* FTS5 unavailable — legal_search falls back to LIKE */
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT DEFAULT (datetime('now')),
            user TEXT, mode TEXT, query TEXT, tool TEXT, result TEXT)");
        $aiCols = $pdo->query('PRAGMA table_info(ai_log)')->fetchAll(PDO::FETCH_ASSOC);
        if (!in_array('ms', array_column($aiCols, 'name'), true)) $pdo->exec('ALTER TABLE ai_log ADD COLUMN ms INTEGER DEFAULT 0');

        /* Add password_hash / last_login to subscribers if missing (idempotent) */
        $cols = [];
        foreach ($pdo->query("PRAGMA table_info(subscribers)") as $c) $cols[] = $c['name'];
        if (!in_array('password_hash', $cols)) {
            $pdo->exec("ALTER TABLE subscribers ADD COLUMN password_hash TEXT DEFAULT ''");
        }
        if (!in_array('last_login', $cols)) {
            $pdo->exec("ALTER TABLE subscribers ADD COLUMN last_login TEXT DEFAULT ''");
        }
        if (!in_array('otp_fails', $cols)) {
            $pdo->exec("ALTER TABLE subscribers ADD COLUMN otp_fails INTEGER DEFAULT 0");
        }
        /* ── Accounts module (20260812): bank/cash accounts + transaction ledger ── */
        $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (
            id TEXT PRIMARY KEY, name TEXT NOT NULL, type TEXT DEFAULT 'bank',
            opening_balance INTEGER DEFAULT 0, notes TEXT DEFAULT '', status TEXT DEFAULT 'active',
            created_by TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS account_transactions (
            id TEXT PRIMARY KEY, account TEXT DEFAULT '', type TEXT NOT NULL,
            cat TEXT DEFAULT 'other', label TEXT NOT NULL, amount INTEGER NOT NULL DEFAULT 0,
            method TEXT DEFAULT '', ref TEXT DEFAULT '', payee TEXT DEFAULT '',
            note TEXT DEFAULT '', tx_date TEXT DEFAULT (datetime('now')),
            status TEXT DEFAULT 'cleared', reconciled INTEGER DEFAULT 0,
            reconciled_at TEXT DEFAULT '', reconciled_ref TEXT DEFAULT '',
            created_by TEXT DEFAULT '', ts TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_atx_account ON account_transactions(account, tx_date)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_atx_type ON account_transactions(type, tx_date)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_atx_recon ON account_transactions(reconciled, tx_date)");
        try { $pdo->exec('PRAGMA user_version=20260822'); } catch (Exception $e) {}
        }   /* end schema bootstrap gate */
    }
    return $pdo;
}

function audit($user, $action, $module, $entity, $details = '') {
    $pdo = db();
    $pdo->prepare('INSERT INTO audit_log (user, action, module, entity, details) VALUES (?,?,?,?,?)')
        ->execute([$user, $action, $module, $entity, $details]);
}

/* ---------- Phase 6: auth throttling ---------- */
function client_ip() {
    /* Trust REMOTE_ADDR only — X-Forwarded-For is user-suppliable on shared hosting. */
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
