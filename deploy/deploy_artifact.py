#!/usr/bin/env python3
"""Upload the patched api/index.php artifact to live via cPanel UAPI — NO rebuild."""
import hashlib, json, sys, time, urllib.parse, urllib.request, http.cookiejar, ssl

sys.path.insert(0, '/root/KRTaker/deploy')
from secrets_env import get_secret

HOST = 'krtaker.com'
DST_DIR = '/home/krtaker/public_html/api'
DST_FILE = 'index.php'
SRC = sys.argv[1] if len(sys.argv) > 1 else '/root/krtaker-deploy/api/index.php'

ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
cj = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj), urllib.request.HTTPSHandler(context=ctx))

def login():
    body = urllib.parse.urlencode({'user': get_secret('CPANEL_USER'), 'pass': get_secret('CPANEL_PASS')}).encode()
    req = urllib.request.Request(f'https://{HOST}:2083/login/?login_only=1', data=body,
                                 headers={'Content-Type': 'application/x-www-form-urlencoded'})
    with opener.open(req, timeout=30) as r:
        resp = json.loads(r.read())
    if resp.get('status') != 1:
        raise RuntimeError('login failed: ' + str(resp))
    return resp['security_token'].split('cpsess')[-1]

def upload(sess, content):
    data = urllib.parse.urlencode({'dir': DST_DIR, 'file': DST_FILE, 'content': content}).encode()
    req = urllib.request.Request(f'https://{HOST}:2083/cpsess{sess}/execute/Fileman/save_file_content', data=data)
    with opener.open(req, timeout=180) as r:
        d = json.loads(r.read())
    if d.get('status') != 1 or d.get('errors'):
        raise RuntimeError('save_file_content failed: ' + json.dumps(d)[:300])
    return d

data = open(SRC, 'rb').read()
sha = hashlib.sha256(data).hexdigest()
print(f'uploading {SRC}: {len(data)} bytes sha256={sha[:16]}…')
sess = login()
print('logged in, sess ok')
for attempt in range(1, 4):
    try:
        upload(sess, data.decode('utf-8', 'replace'))
        print(f'upload OK (attempt {attempt})')
        break
    except Exception as e:
        print(f'attempt {attempt} failed: {e}')
        if attempt == 3:
            raise
        time.sleep(5)
print(f'API_DEPLOYED sha256={sha} size={len(data)}')
