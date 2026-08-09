#!/usr/bin/env python3
"""Upload site files (web/*, docs/dashboard-v2.html, docs/superadmin.html, css/js/
sw/manifest/sitemap/robots/.htaccess, pwa images) to live via cPanel UAPI
Fileman::upload_files (multipart). Per-file retry (cPanel timeouts are common —
deploy_cpanel.py historically dropped 3/76 files per run). Repo-relative paths.
"""
import json, os, sys, time, uuid, urllib.parse, urllib.request, http.cookiejar, ssl, glob

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from secrets_env import get_secret

HOST = 'krtaker.com'
ROOT = '/home/krtaker/public_html'
WEB = os.path.join(REPO, 'web')
DOCS = os.path.join(REPO, 'docs')
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


def build_files():
    files = []
    for f in sorted(glob.glob(os.path.join(WEB, '*.html'))):
        files.append((os.path.basename(f), ROOT + '/', f))
    files.append(('dashboard-v2.html', ROOT + '/', os.path.join(DOCS, 'dashboard-v2.html')))
    files.append(('superadmin.html', ROOT + '/', os.path.join(DOCS, 'superadmin.html')))
    subs = [
        ('css/style.css', '/css/'), ('css/share.css', '/css/'), ('js/main.js', '/js/'),
        ('js/i18n.js', '/js/'), ('js/cms-hydrate.js', '/js/'), ('js/blog-hydrate.js', '/js/'),
        ('js/plans-hydrate.js', '/js/'), ('js/chat.js', '/js/'), ('js/share.js', '/js/'),
        ('js/tools.js', '/js/'), ('js/register.js', '/js/'), ('i18n/i18n-dict.js', '/i18n/'),
        ('manifest.json', '/'), ('manifest-dash.json', '/'), ('sw.js', '/'),
        ('sitemap.xml', '/'), ('robots.txt', '/'), ('.htaccess', '/'),
    ]
    for name, sub in subs:
        local = os.path.join(WEB, name)
        if name == '.htaccess':
            local = os.path.join(WEB, '.htaccess')
        files.append((name, ROOT + sub, local))
    for name in ['icon.svg', 'icon-192.png', 'icon-512.png']:
        files.append((name, ROOT + '/pwa/', os.path.join(WEB, 'pwa', name)))
    for name in ['ls-p001.svg', 'ls-p002.svg', 'ls-p003.svg', 'ls-p004.svg', 'ls-p005.svg',
                 'krtaker-logo.png', 'krtaker-logo-white.png', 'krtaker-logo-full.png',
                 'krtaker-logo-full-white.png']:
        p = os.path.join(WEB, 'assets', name)
        if not os.path.exists(p):
            p = os.path.join(WEB, 'img', name)
        if os.path.exists(p):
            files.append((name, ROOT + '/assets/', p))
    return files


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


def main():
    files = build_files()
    print(f'{len(files)} files to deploy')
    sess = login()
    print('logged in, sess ok')
    ok = failed = 0
    for fname, sdir, local in files:
        if not os.path.exists(local):
            print(f'SKIP (missing) {fname}')
            continue
        content = open(local, 'rb').read()
        for attempt in range(1, RETRIES + 1):
            try:
                d = upload_batch(sess, sdir, [(fname, content)])
                if d.get('status') == 1 and not d.get('errors'):
                    ok += 1
                    break
                else:
                    raise RuntimeError(json.dumps(d)[:200])
            except Exception as e:
                if attempt == RETRIES:
                    failed += 1
                    print(f'FAIL {fname}: {e}')
                else:
                    print(f'retry {attempt} {fname}: {e}')
                    time.sleep(4)
    print(f'uploaded {ok}/{len(files)} files ({failed} failed)')
    return failed


if __name__ == '__main__':
    sys.exit(1 if main() else 0)
