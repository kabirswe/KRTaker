#!/usr/bin/env python3
"""Git-triggered deploy orchestrator.

1. build api/index.php from src/ (sha256 captured)
2. upload API via UAPI save_file_content
3. upload site files via UAPI upload_files (per-file retry)
4. VERIFY: POST /api/app-deploy-status (X-Service-Key) and compare the live
   file's sha256 to the built hash — fail the run on mismatch.

Secrets: CPANEL_USER, CPANEL_PASS, SERVICE_KEY from env (GitHub Actions) or
the local secrets_loader (Hermes box).
"""
import json, os, sys, urllib.request, ssl

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from secrets_env import get_secret
import deploy_api
import deploy_site

HOST = 'krtaker.com'
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE


def verify(local_sha, local_size):
    body = json.dumps({}).encode()
    req = urllib.request.Request(f'https://{HOST}/api/app-deploy-status', data=body, method='POST')
    req.add_header('Content-Type', 'application/json')
    req.add_header('X-Service-Key', get_secret('SERVICE_KEY'))
    with urllib.request.urlopen(req, timeout=30, context=ctx) as r:
        d = json.loads(r.read())
    if not d.get('ok'):
        raise RuntimeError('app-deploy-status failed: ' + json.dumps(d)[:200])
    live_sha, live_size = d['sha256'], d['size']
    print(f'verify: live sha256={live_sha[:16]}… size={live_size} | built sha256={local_sha[:16]}… size={local_size}')
    if live_sha != local_sha or live_size != local_size:
        raise RuntimeError(f'DEPLOY MISMATCH — live != built ({live_sha[:16]} vs {local_sha[:16]})')
    print('✅ verify: live API matches built file')


def main():
    sha, size = deploy_api.main()
    failed = deploy_site.main()
    verify(sha, size)
    if failed:
        print(f'⚠ {failed} site files failed after retries — check above')
        sys.exit(1)
    print('✅ DEPLOY COMPLETE — API hash-verified, site uploaded')


if __name__ == '__main__':
    main()
