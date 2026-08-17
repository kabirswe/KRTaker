#!/usr/bin/env python3
"""V2.51 local E2E — superadmin SMS dashboard + gateway refund system.
Runs the built api/index.php on a local PHP server against a fresh SQLite DB,
seeds a superadmin, then exercises: sms-summary/config/test, refunds,
refund-initiate (simulated), refund-status, app-refund ledger, dup guards.
Usage: python3 tests/test_v251.py   (needs php-cli + curl)
"""
import hashlib, json, os, sqlite3, subprocess, sys, time, urllib.request, urllib.error

BASE = 'http://127.0.0.1:8091/api'
DB = '/tmp/krt_v251_test.db'
SUPER = 'sa@krtaker.test'
SUPER_PASS = 'S@test12345!'
php = None

def sh(cmd):
    return subprocess.run(cmd, shell=True, capture_output=True, text=True)

def start_server():
    global php
    if os.path.exists(DB): os.remove(DB)
    env = dict(os.environ)
    env.update({'KRT_DB_PATH': DB, 'KRT_SERVICE_KEY': 'test-service-key',
                'KRT_ADMIN_EMAIL': SUPER, 'KRT_APP_SETUP_KEY': 'test-setup-key'})
    php = subprocess.Popen(['php', '-S', '127.0.0.1:8091', '-t', '/root/KRTaker/api',
                            '/root/KRTaker/api/index.php'], env=env,
                           stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    # wait for the server + schema bootstrap
    for _ in range(40):
        try:
            sc, _ = get('health')
            if sc == 200:
                get('plans')  # force db() → schema bootstrap gate
                return True
        except Exception:
            time.sleep(0.25)
    return False

def stop_server():
    if php: php.terminate()

def get(path):
    try:
        r = urllib.request.urlopen(BASE + '/' + path, timeout=10)
        return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        try: return e.code, json.loads(e.read().decode())
        except Exception: return e.code, {}

def post(action, body, token=None):
    h = {'Content-Type': 'application/json'}
    if token: h['Authorization'] = 'Bearer ' + token
    try:
        req = urllib.request.Request(f'{BASE}/{action}', data=json.dumps(body).encode(), headers=h, method='POST')
        r = urllib.request.urlopen(req, timeout=15)
        return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        try: return e.code, json.loads(e.read().decode())
        except Exception: return e.code, {}

def seed_superadmin():
    con = sqlite3.connect(DB)
    con.execute("INSERT INTO app_users (name, email, password_hash, role, dept, is_staff, active) VALUES (?,?,?,?,?,1,1)",
                ('Local SA', SUPER, 'x', 'superadmin', 'admin'))
    con.commit(); con.close()

def login_superadmin():
    con = sqlite3.connect(DB)
    uid = con.execute("SELECT id FROM app_users WHERE email=?", (SUPER,)).fetchone()[0]
    tok = 'test-sa-token-' + hashlib.sha256(os.urandom(8)).hexdigest()[:24]
    con.execute("INSERT INTO app_tokens (token, user_id, kind, created_at, expires_at) VALUES (?,?,?,datetime('now'),datetime('now','+1 day'))",
                (hashlib.sha256(tok.encode()).hexdigest(), uid, 'staff'))
    con.commit(); con.close()
    return tok

results = []
def check(name, cond, extra=''):
    results.append((name, bool(cond), extra))
    print(('PASS' if cond else 'FAIL') + f'  {name}' + (f'  [{extra}]' if extra else ''))

def run_checks(tok):
    # ── SMS dashboard ──
    sc, d = post('app-admin', {'action': 'sms-summary'}, tok)
    check('sms-summary 200', sc == 200 and d.get('ok'), f'{sc}')
    check('sms-summary has stats/config', 'stats' in d and 'config' in d and 'recent' in d)
    check('sms-summary trend 14 days', len(d.get('stats', {}).get('trend', [])) == 14, str(len(d.get('stats', {}).get('trend', []))))

    sc, d = post('app-admin', {'action': 'sms-config-save', 'enabled': True, 'provider': 'log', 'sender_id': 'KRTaker'}, tok)
    check('sms-config-save 200', sc == 200 and d.get('ok'), f'{sc} {d.get("error","")}')

    sc, d = post('app-admin', {'action': 'sms-test-send', 'phone': '01712345678'}, tok)
    check('sms-test-send 200 + kind=test', sc == 200 and d.get('ok') and d.get('kind') == 'test', f'{sc} {d.get("error","")}')

    sc, d = post('app-admin', {'action': 'sms-summary'}, tok)
    check('sms-summary reflects test row', d.get('stats', {}).get('total', 0) >= 1 and d.get('stats', {}).get('by_kind', {}).get('test', 0) >= 1,
          f"total={d.get('stats',{}).get('total')}")

    # non-superadmin gate
    sc, d = post('app-admin', {'action': 'sms-summary'})
    check('sms-summary auth gate 401', sc == 401, f'{sc}')

    # ── workspace app-sms stats (same code path via app-sms) ──
    sc, d = post('app-sms', {'action': 'stats'}, tok)
    check('app-sms stats 200', sc == 200 and d.get('ok'), f'{sc}')

    # ── Refund system ──
    # seed an invoice + payment + gateway_tx (Success)
    con = sqlite3.connect(DB)
    con.execute("INSERT INTO properties (id, name, type, jur, holding) VALUES ('P-T1','Test Prop','residential','Dhaka','12')")
    con.execute("INSERT INTO units (id, p, name, floor, sqft, status, rent) VALUES ('U-T1','P-T1','Flat 1','1',900,'Active',20000)")
    con.execute("INSERT INTO tenants (id, name, phone, email) VALUES ('T-T1','Test Tenant','01711111111','tenant@test.dev')")
    con.execute("INSERT INTO leases (id, u, t, start, end, rent, adv, res, status) VALUES ('L-T1','U-T1','T-T1','2026-01-01','2026-12-31',20000,0,0,'Active')")
    con.execute("INSERT INTO invoices (id, l, m, gross, tds, net, status) VALUES ('INV-T1','L-T1','2026-08',20000,0,20000,'Unpaid')")
    con.execute("INSERT INTO payments (id, inv, amount, method, ref, date, status) VALUES ('PAY-T1','INV-T1',20000,'SSLCommerz','GW-SSL-TEST1','2026-08-01','Success')")
    con.execute("INSERT INTO gateway_tx (id, invoice_id, method, amount, status, ref, gw_ref) VALUES ('GW-T1','INV-T1','SSLCommerz',20000,'paid','GW-SSL-TEST1','VAL-123456')")
    con.commit(); con.close()

    sc, d = post('app-admin', {'action': 'refunds'}, tok)
    check('refunds list 200', sc == 200 and d.get('ok'), f'{sc}')
    check('refunds stats shape', 'stats' in d and 'by_status' in d.get('stats', {}))

    sc, d = post('app-admin', {'action': 'refund-initiate', 'payment_id': 'PAY-T1', 'reason': 'Duplicate charge'}, tok)
    check('refund-initiate 200', sc == 200 and d.get('ok'), f'{sc} {d.get("error","")}')
    check('refund-initiate simulated refunded', d.get('simulated') is True and d.get('status') == 'refunded', f"{d.get('status')} sim={d.get('simulated')}")
    rfid = d.get('refund_id', '')
    check('refund id RF-', rfid.startswith('RF-'), rfid)

    # dup guard — payment is already Refunded after the first refund, so a second
    # attempt is rejected (400 "already refunded"; the 409 open-refund guard
    # covers payments still Success with an in-flight request)
    sc, d = post('app-admin', {'action': 'refund-initiate', 'payment_id': 'PAY-T1', 'reason': 'again'}, tok)
    check('refund-initiate dup rejected 400/409', sc in (400, 409), f'{sc}')

    # payment now Refunded
    con = sqlite3.connect(DB)
    st = con.execute("SELECT status FROM payments WHERE id='PAY-T1'").fetchone()[0]
    con.close()
    check('payment marked Refunded', st == 'Refunded', st)

    sc, d = post('app-admin', {'action': 'refund-status', 'refund_id': rfid}, tok)
    check('refund-status 200', sc == 200 and d.get('ok'), f'{sc} {d.get("error","")}')
    check('refund-status simulated stays refunded', d.get('status') == 'refunded', f"{d.get('status')}")

    sc, d = post('app-admin', {'action': 'refund-status', 'refund_id': 'RF-NOPE'}, tok)
    check('refund-status unknown 404', sc == 404, f'{sc}')

    # refunds list reflects the row
    sc, d = post('app-admin', {'action': 'refunds'}, tok)
    found = any(r.get('id') == rfid for r in d.get('refunds', []))
    check('refunds list contains row', found, rfid)

    # gateway column auto-detected from gateway_tx (sslcommerz)
    sc, d = post('app-admin', {'action': 'refunds'}, tok)
    row = next((r for r in d.get('refunds', []) if r.get('id') == rfid), {})
    check('refund gateway detected sslcommerz', row.get('gateway') == 'sslcommerz', row.get('gateway', ''))

    # ── app-refund (workspace) records into ledger ──
    con = sqlite3.connect(DB)
    con.execute("INSERT INTO payments (id, inv, amount, method, ref, date, status) VALUES ('PAY-T2','INV-T1',5000,'bKash','BK-T2','2026-08-02','Success')")
    con.commit(); con.close()
    sc, d = post('app-refund', {'payment_id': 'PAY-T2', 'reason': 'offline refund'}, tok)
    check('app-refund 200', sc == 200 and d.get('ok'), f'{sc} {d.get("error","")}')
    sc, d = post('app-admin', {'action': 'refunds'}, tok)
    manual = next((r for r in d.get('refunds', []) if r.get('payment_id') == 'PAY-T2'), {})
    check('app-refund ledger row manual', manual.get('gateway') == 'manual' and manual.get('status') == 'refunded', str(manual.get('gateway')))

    # ── invalid cases ──
    sc, d = post('app-admin', {'action': 'refund-initiate', 'payment_id': 'PAY-MISSING'}, tok)
    check('refund-initiate unknown payment 404', sc == 404, f'{sc}')
    con = sqlite3.connect(DB)
    con.execute("INSERT INTO payments (id, inv, amount, method, ref, date, status) VALUES ('PAY-T3','INV-T1',3000,'Cash','C-T3','2026-08-03','Pending')")
    con.commit(); con.close()
    sc, d = post('app-admin', {'action': 'refund-initiate', 'payment_id': 'PAY-T3'}, tok)
    check('refund-initiate non-success 400', sc == 400, f'{sc}')

    # ledger email template exists
    con = sqlite3.connect(DB)
    n = con.execute("SELECT COUNT(*) FROM email_templates WHERE id='refund'").fetchone()[0]
    con.close()
    check('refund email template seeded', n >= 1, str(n))

def main():
    env_file = '/home/krtaker/krtaker.env.php'
    env_bak = env_file + '.v251bak'
    env_moved = False
    try:
        # isolate: this dev VM has a live env file that overrides KRT_DB_PATH
        if os.path.exists(env_file):
            os.rename(env_file, env_bak)
            env_moved = True
        if not start_server():
            print('FAIL server start'); sys.exit(1)
        print('server up, schema bootstrapped')
        seed_superadmin()
        tok = login_superadmin()
        print('superadmin token minted')
        run_checks(tok)
    finally:
        stop_server()
        if env_moved and os.path.exists(env_bak):
            os.rename(env_bak, env_file)
        fails = [r for r in results if not r[1]]
        print(f'\n{len(results) - len(fails)}/{len(results)} PASS')
        for f in fails: print('  FAIL:', f[0], f[2])
        sys.exit(1 if fails else 0)

if __name__ == '__main__':
    main()
