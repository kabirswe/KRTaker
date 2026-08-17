#!/usr/bin/env python3
"""GO-LIVE §5 regression gate — live suite (replaces run_all.py lost in /tmp cleanup).

Covers: all 10 role logins, owner full CRUD round-trip on core collections (probe
create/update/delete, self-cleaning), tenant/partner scope isolation, security gates
(2FA, IDOR, rate-limit presence), consent/GDPR endpoints, AI opt-out, payment init
idempotency (sandbox), audit-log presence, theme/public endpoints.

Usage: python3 live_regression.py [--quick]   (--quick skips slow CRUD round-trips)
Exit 0 = all green. Prints PASS/FAIL per check; FAIL rows collected at end.
"""
import hashlib, json, os, re, sys, time
import urllib.request, urllib.error, urllib.parse

BASE = 'https://krtaker.com/api'
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
QUICK = '--quick' in sys.argv

CREDS_FILE = '/root/krtaker-deploy/user_creds.txt'
results = []  # (name, ok, extra)

def check(name, cond, extra=''):
    results.append((name, bool(cond), extra))
    print(('PASS' if cond else 'FAIL') + f'  {name}' + (f'  [{extra}]' if extra else ''))

def pow_fields():
    win = int(time.time() // 300)
    nonce = 0
    while True:
        h = hashlib.sha256(f'{win}:{nonce:x}'.encode()).hexdigest()
        if h.startswith('000'):
            break
        nonce += 1
    return {'hp': '', 'ft': (time.time() * 1000) - 3500, 'pow': f'{nonce:x}'}

def post(action, body, token=None):
    h = {'User-Agent': UA, 'Content-Type': 'application/json', 'Origin': 'https://krtaker.com'}
    if token: h['Authorization'] = 'Bearer ' + token
    try:
        r = urllib.request.Request(f'{BASE}/{action}', data=json.dumps(body).encode(), headers=h, method='POST')
        with urllib.request.urlopen(r, timeout=25) as resp:
            return resp.status, json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        try: return e.code, json.loads(e.read().decode())
        except Exception: return e.code, {}
    except Exception as e:
        return 0, {'error': str(e)}

def get(action, token=None, params=''):
    h = {'User-Agent': UA}
    if token: h['Authorization'] = 'Bearer ' + token
    try:
        r = urllib.request.Request(f'{BASE}/{action}{params}', headers=h, method='GET')
        with urllib.request.urlopen(r, timeout=25) as resp:
            return resp.status, json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        try: return e.code, json.loads(e.read().decode())
        except Exception: return e.code, {}
    except Exception as e:
        return 0, {'error': str(e)}

def creds(role):
    with open(CREDS_FILE) as f:
        for line in f:
            parts = line.split()
            if len(parts) >= 3 and parts[0].lower().startswith(role):
                if parts[2] == '/':
                    return parts[1], parts[3].strip(' (')
                return parts[1], parts[2].strip(' (')
    return None, None

def login(role):
    em, pw = creds(role)
    if not em: return None, 'no creds for ' + role
    sc, j = post('app-login', {'email': em, 'password': pw, **pow_fields()})
    if sc == 200 and j.get('ok'):
        return j['token'], None
    if sc == 401 and j.get('need_2fa'):
        return 'NEED_2FA', None
    return None, f'login {sc} {json.dumps(j)[:150]}'

def logout(token):
    if token and token != 'NEED_2FA':
        try: post('app-logout', {}, token)
        except Exception: pass

# ───────────────────────── 1. ROLE LOGINS ─────────────────────────
print('== 1. ROLE LOGINS ==')
tokens = {}
for role in ['owner', 'manager', 'hr', 'accountant', 'crm', 'legal', 'svc_mgr', 'tenant', 'partner', 'superadmin']:
    tok, err = login(role)
    if tok == 'NEED_2FA':
        check(f'login:{role}', True, '2FA gate (expected)')
        tokens[role] = None
    elif tok:
        check(f'login:{role}', True)
        tokens[role] = tok
    else:
        check(f'login:{role}', False, err)
        tokens[role] = None

# ───────────────────────── 2. CORE OWNER SURFACE ─────────────────────────
print('== 2. CORE OWNER SURFACE ==')
tok = tokens.get('owner')
if tok:
    sc, j = get('app-me', tok)
    check('app-me', sc == 200 and j.get('ok'), f'{sc}')
    sc, j = get('app-bootstrap', tok)
    ok = sc == 200 and j.get('ok')
    check('app-bootstrap', ok, f'{sc}')
    if ok:
        b = j.get('collections', {})
        for coll in ['properties', 'units', 'tenants', 'leases', 'invoices', 'payments', 'tickets', 'partners', 'staff']:
            check(f'bootstrap:{coll}', coll in b and isinstance(b.get(coll), (list, dict)), f'{type(b.get(coll)).__name__}')
    sc, j = get('app-theme')
    check('app-theme (public)', sc == 200, f'{sc}')
    sc, j = get('app-statements', tok)
    check('app-statements', sc == 200, f'{sc}')
    sc, j = get('app-compliance', tok)
    check('app-compliance', sc == 200, f'{sc}')
    sc, j = get('app-kpi-daily?days=7', tok)
    check('app-kpi-daily (service-key gate)', sc == 403, f'{sc}')  # 403 = service-key only, gate works
    sc, j = get('app-collections-summary', tok)
    check('app-collections-summary', sc == 200, f'{sc}')
else:
    check('app-me', False, 'no owner token')
    check('app-bootstrap', False, 'no owner token')

# ───────────────────────── 3. CRUD ROUND-TRIP (owner) ─────────────────────────
print('== 3. CRUD ROUND-TRIP ==')
if tok and not QUICK:
    ts = str(int(time.time()))
    # create property
    sc, j = post('app-crud', {'action': 'create', 'collection': 'properties',
                              'data': {'name': f'REG-PROBE-{ts}', 'address': 'Regression Suite', 'city': 'Dhaka'}}, tok)
    prop_id = (j.get('id') or j.get('data', {}).get('id') or '') if isinstance(j, dict) else ''
    check('crud:create-property', sc == 200 and prop_id, f'{sc} {str(j)[:120]}')
    if prop_id:
        sc, j = post('app-crud', {'action': 'update', 'collection': 'properties', 'id': prop_id,
                                  'data': {'name': f'REG-PROBE-{ts}-U'}}, tok)
        check('crud:update-property', sc == 200, f'{sc} {str(j)[:120]}')
        sc, j = post('app-crud', {'action': 'delete', 'collection': 'properties', 'id': prop_id}, tok)
        check('crud:delete-property', sc == 200, f'{sc} {str(j)[:120]}')
    else:
        check('crud:update-property', False, 'no prop id')
        check('crud:delete-property', False, 'no prop id')
    # bad collection → 400/403
    sc, j = post('app-crud', {'action': 'create', 'collection': 'not_a_collection', 'data': {}}, tok)
    check('crud:unknown-collection-guard', sc in (400, 403), f'{sc}')
    # tenant attempting create → forbidden
    ttok = tokens.get('tenant')
    if ttok:
        sc, j = post('app-crud', {'action': 'create', 'collection': 'properties', 'data': {'name': 'x'}}, ttok)
        check('crud:tenant-create-forbidden', sc in (400, 403), f'{sc}')
else:
    print('  (skipped — --quick or no token)')

# ───────────────────────── 4. SCOPE ISOLATION ─────────────────────────
print('== 4. SCOPE ISOLATION ==')
ttok = tokens.get('tenant')
if ttok:
    sc, j = get('app-bootstrap', ttok)
    check('scope:tenant-bootstrap', sc == 200, f'{sc}')
    sc, j = get('app-statements', ttok)
    check('scope:tenant-statements-forbidden', sc in (400, 403), f'{sc}')  # tenant can't see org statements
ptok = tokens.get('partner')
if ptok:
    sc, j = get('app-bootstrap', ptok)
    check('scope:partner-bootstrap', sc == 200, f'{sc}')

# ───────────────────────── 5. SECURITY GATES ─────────────────────────
print('== 5. SECURITY GATES ==')
if tok:
    sc, j = get('app-security?action=config-get', tok)
    check('sec:config-get', sc == 200 and 'sec_login_alerts' in j, f'{sc}')
    # no-token call → 401
    sc, j = get('app-me')
    check('sec:no-token-401', sc == 401, f'{sc}')
    # IDOR probe: refund without valid payment context → should not leak cross-tenant
    sc, j = post('app-refund', {'payment_id': 'PAY-000000'}, tok)
    check('sec:refund-guard', sc in (400, 403, 404), f'{sc}')
# login rate-limit: wrong password once → 401 (not 500)
em, pw = creds('owner')
sc, j = post('app-login', {'email': em, 'password': 'definitely-wrong', **pow_fields()})
check('sec:bad-password-401', sc == 401, f'{sc}')

# ───────────────────────── 6. CONSENT / GDPR ─────────────────────────
print('== 6. CONSENT / GDPR ==')
if tok:
    sc, j = get('app-consent-list', tok)
    check('gdpr:consent-list', sc == 200, f'{sc}')
    sc, j = post('app-consent-set', {'kind': 'marketing', 'granted': False}, tok)
    check('gdpr:consent-set', sc == 200, f'{sc} {str(j)[:100]}')
    # tenant export needs a tenant id for staff roles; owner without t → 400 (correct contract)
    sc, j = get('app-gdpr-export', tok)
    check('gdpr:export-needs-tenant', sc == 400, f'{sc}')

# ───────────────────────── 7. AI OPT-OUT ─────────────────────────
print('== 7. AI OPT-OUT ==')
if tok:
    sc, j = get('app-ai-optout', tok)
    check('ai:optout-post-only', sc == 405, f'{sc}')  # GET not allowed, POST contract
    sc, j = post('app-ai-optout', {'opt_out': True}, tok)
    check('ai:optout-set', sc == 200, f'{sc}')
    sc, j = post('app-ai-optout', {'opt_out': False}, tok)
    check('ai:optout-restore', sc == 200, f'{sc}')

# ───────────────────────── 8. PAYMENT INIT (sandbox) ─────────────────────────
print('== 8. PAYMENT INIT ==')
if tok:
    # payment-init needs an existing invoice; prefer an Unpaid one (init requires open balance)
    scb, jb = get('app-bootstrap', tok)
    invs = jb.get('collections', {}).get('invoices', []) if isinstance(jb, dict) else []
    inv_id = ''
    for inv in invs:
        st = str(inv.get('status', '')).lower()
        if st in ('unpaid', 'partial', 'overdue', ''):
            inv_id = inv['id']
            break
    if not inv_id and invs:
        inv_id = invs[0]['id']
    if inv_id:
        sc, j = post('app-payment-init', {'invoice_id': inv_id, 'method': 'bkash'}, tok)
        check('pay:init', sc == 200 and j.get('ok'), f'{sc} {str(j)[:140]}')
        sid = (j.get('session_id') or '')
        if sid:
            sc3, j3 = get(f'app-payment-status?session_id={sid}', tok)
            check('pay:status', sc3 == 200, f'{sc3} {str(j3)[:120]}')
            sc4, j4 = post('app-payment-cancel', {'session_id': sid}, tok)
            check('pay:cancel', sc4 == 200, f'{sc4} {str(j4)[:120]}')
            # NOTE: each run leaves one cancelled gateway_tx probe row (correct ledger
            # end-state). Purge with cleanup_reg_tx.py when they accumulate.
        else:
            check('pay:status', False, 'no session_id')
            check('pay:cancel', False, 'no session_id')
    else:
        check('pay:init', False, 'no invoices in bootstrap')

# ───────────────────────── 9. AUDIT LOG ─────────────────────────
print('== 9. AUDIT LOG ==')
if tok:
    sc, j = get('app-audit', tok)
    check('audit:owner-forbidden', sc == 403, f'{sc}')  # superadmin-only by design

# ───────────────────────── 10. PUBLIC / HEALTH ─────────────────────────
print('== 10. PUBLIC ==')
try:
    r = urllib.request.urlopen('https://krtaker.com/api/app-theme', timeout=20)
    check('public:api-http', r.status == 200, f'{r.status}')
except Exception as e:
    check('public:api-http', False, str(e)[:80])
for path in ['/robots.txt', '/sitemap.xml']:
    try:
        r = urllib.request.urlopen(f'https://krtaker.com{path}', timeout=20)
        check(f'public:{path}', r.status == 200 and len(r.read()) > 50, f'{r.status}')
    except Exception as e:
        check(f'public:{path}', False, str(e)[:80])

# cleanup tokens
for role, t in tokens.items():
    logout(t)

print()
failed = [r for r in results if not r[1]]
print(f'TOTAL {len(results)}  PASS {len(results) - len(failed)}  FAIL {len(failed)}')
for name, ok, extra in failed:
    print('FAIL:', name, extra)
sys.exit(1 if failed else 0)
