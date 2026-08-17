#!/usr/bin/env python3
"""Upload one file to live via cPanel UAPI Fileman::upload_files (multipart)."""
import json, os, sys, uuid, urllib.parse, urllib.request, http.cookiejar, ssl

sys.path.insert(0, '/root/KRTaker/deploy')
from secrets_env import get_secret

HOST = 'krtaker.com'
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

local, remote_dir = sys.argv[1], sys.argv[2]
data = open(local, 'rb').read()
boundary = '----kr' + uuid.uuid4().hex
parts = []
parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="dir"\r\n\r\n{remote_dir}\r\n'.encode())
parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="overwrite"\r\n\r\n1\r\n'.encode())
parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file-0"; filename="{os.path.basename(local)}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode())
parts.append(data)
parts.append(b'\r\n')
parts.append(f'--{boundary}--\r\n'.encode())
body = b''.join(parts)
sess = login()
req = urllib.request.Request(f'https://{HOST}:2083/cpsess{sess}/execute/Fileman/upload_files',
                             data=body, headers={'Content-Type': f'multipart/form-data; boundary={boundary}'})
with opener.open(req, timeout=180) as r:
    d = json.loads(r.read())
print('upload:', 'OK' if d.get('status') == 1 else json.dumps(d)[:300], f'({len(data)} bytes)')
