#!/usr/bin/env python3
"""Build api/index.php from src/ and upload to live via cPanel UAPI
Fileman::save_file_content. Reads CPANEL_USER/CPANEL_PASS from env (CI)
or the local secrets_loader. Returns the built file's sha256 + size so
the caller can verify against app-deploy-status.
"""
import hashlib, json, os, sys, time, urllib.parse, urllib.request, http.cookiejar, ssl

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__))))
from secrets_env import get_secret

HOST = 'krtaker.com'
DST_DIR = '/home/krtaker/public_html/api'
DST_FILE = 'index.php'

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


def build():
    build_script = os.path.join(REPO, 'api', 'build_api.py')
    if os.path.exists(build_script):
        import subprocess
        subprocess.run([sys.executable, build_script], cwd=os.path.join(REPO, 'api'), check=True)
    out = os.path.join(REPO, 'api', 'index.php')
    data = open(out, 'rb').read()
    return out, data, hashlib.sha256(data).hexdigest(), len(data)


def upload(sess, content):
    data = urllib.parse.urlencode({'dir': DST_DIR, 'file': DST_FILE, 'content': content}).encode()
    req = urllib.request.Request(f'https://{HOST}:2083/cpsess{sess}/execute/Fileman/save_file_content', data=data)
    with opener.open(req, timeout=180) as r:
        d = json.loads(r.read())
    if d.get('status') != 1 or d.get('errors'):
        raise RuntimeError('save_file_content failed: ' + json.dumps(d)[:300])
    return d


def main():
    out, data, sha, size = build()
    print(f'built {out}: {size} bytes sha256={sha[:16]}…')
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
    print(f'API_DEPLOYED sha256={sha} size={size}')
    return sha, size


if __name__ == '__main__':
    main()
