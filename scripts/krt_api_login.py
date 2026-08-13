#!/usr/bin/env python3
"""KRTaker API login (PoW-aware) — used for live verification.
Solves bot_guard proof-of-work (12 bits = 3 hex zeros), time-trap, then POSTs app-login.
Usage: python3 scripts/krt_api_login.py [email] [password] [--json]
"""
import hashlib, json, sys, time, urllib.request, urllib.error, ssl, os

API = 'https://krtaker.com/api/'

def solve_pow(difficulty=12, max_tries=2000000):
    win = int(time.time() // 300)
    for nonce in range(max_tries):
        h = hashlib.sha256(f"{win}:{nonce:x}".encode()).hexdigest()
        bits = 0
        for c in h:
            b = bin(int(c, 16))[2:].zfill(4)
            if b == '0000':
                bits += 4
            else:
                bits += len(b) - len(b.lstrip('0'))
                break
        if bits >= difficulty:
            return f"{nonce:x}"
    raise RuntimeError('PoW not solved')

def api_login(email, password):
    pow_nonce = solve_pow(12)
    ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
    body = json.dumps({
        'email': email, 'password': password,
        'pow': pow_nonce,
        'ft': (time.time() * 1000) - 2500,
    }).encode()
    req = urllib.request.Request(API + 'app-login', data=body, method='POST',
                                 headers={'Content-Type': 'application/json', 'User-Agent': 'kr-verify/2.31.7'})
    with urllib.request.urlopen(req, timeout=30, context=ctx) as r:
        return json.loads(r.read())

def api(token, action, payload=None):
    ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
    data = payload or {}
    body = json.dumps(data).encode()
    req = urllib.request.Request(API + action, data=body, method='POST',
                                 headers={'Content-Type': 'application/json',
                                          'Authorization': 'Bearer ' + token,
                                          'User-Agent': 'kr-verify/2.31.7'})
    try:
        with urllib.request.urlopen(req, timeout=30, context=ctx) as r:
            return json.loads(r.read())
    except urllib.error.HTTPError as e:
        try:
            return json.loads(e.read().decode() or '{}')
        except Exception:
            return {'ok': False, 'error': f'HTTP {e.code}', 'raw': e.read().decode()[:200]}

if __name__ == '__main__':
    email = sys.argv[1] if len(sys.argv) > 1 else 'owner@krtaker.com'
    password = sys.argv[2] if len(sys.argv) > 2 else os.environ.get('KR_OWNER_PASS', '')
    d = api_login(email, password)
    if not d.get('ok') and not d.get('token'):
        print(json.dumps(d)[:300]); sys.exit(1)
    print(json.dumps(d)[:400])
