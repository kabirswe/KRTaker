#!/usr/bin/env python3
"""Deploy api/index.php + app-v3/dist/* to live via cPanel UAPI Fileman.

Uploads:
  api/index.php        → /api/index.php
  app-v3/dist/**       → /app-v3/** (recursive, per-file retry)
Uses the same login/upload pattern as deploy_site.py (cPanel UAPI).
"""
import json, os, sys, time, uuid, urllib.parse, urllib.request, http.cookiejar, ssl, glob

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from secrets_env import get_secret

HOST = 'krtaker.com'
ROOT = '/home/krtaker/public_html'
DIST = os.path.join(REPO, 'app-v3', 'dist')
RETRIES = 3

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


def upload_batch(sess, server_dir, files):
    boundary = '----kr' + uuid.uuid4().hex
    parts = []
    def field(name, val):
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{val}\r\n'.encode())
    field('dir', server_dir)
    field('overwrite', '1')
    for i, (fname, content) in enumerate(files):
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file-{i}"; filename="{fname}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode())
        parts.append(content)
        parts.append(b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    body = b''.join(parts)
    req = urllib.request.Request(f'https://{HOST}:2083/cpsess{sess}/execute/Fileman/upload_files',
                                 data=body, headers={'Content-Type': f'multipart/form-data; boundary={boundary}'})
    with opener.open(req, timeout=180) as r:
        return json.loads(r.read())


def collect_dist():
    """Return [(rel_path, abs_path)] for every file under dist/."""
    out = []
    for root, _dirs, files in os.walk(DIST):
        for f in files:
            full = os.path.join(root, f)
            rel = os.path.relpath(full, DIST)
            out.append((rel, full))
    return sorted(out)


def upload_file(sess, server_dir, fname, content):
    for attempt in range(1, RETRIES + 1):
        try:
            d = upload_batch(sess, server_dir, [(fname, content)])
            if d.get('status') == 1 and not d.get('errors'):
                return True
            raise RuntimeError(json.dumps(d)[:200])
        except Exception as e:
            if attempt == RETRIES:
                print(f'FAIL {server_dir}/{fname}: {e}')
                return False
            print(f'retry {attempt} {fname}: {e}')
            time.sleep(4)
    return False


def main():
    sess = login()
    print('logged in, sess ok')
    ok = failed = 0

    # 1. API
    api_path = os.path.join(REPO, 'api', 'index.php')
    api_content = open(api_path, 'rb').read()
    print(f'API: {len(api_content)} bytes → /api/index.php')
    if upload_file(sess, ROOT + '/api/', 'index.php', api_content):
        ok += 1
    else:
        failed += 1

    # 2. SPA dist (recursive)
    files = collect_dist()
    print(f'SPA: {len(files)} files → /app-v3/')
    for rel, full in files:
        content = open(full, 'rb').read()
        sdir = ROOT + '/app-v3/' + os.path.dirname(rel)
        if upload_file(sess, sdir, os.path.basename(rel), content):
            ok += 1
        else:
            failed += 1

    print(f'uploaded {ok}/{ok + failed} files ({failed} failed)')
    return failed


if __name__ == '__main__':
    sys.exit(1 if main() else 0)
