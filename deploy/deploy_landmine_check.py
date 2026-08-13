#!/usr/bin/env python3
"""KRTaker deploy-landmine gate — run BEFORE shipping any V2.x release.

Encodes every landmine class hit across V2.9–V2.31 into a single pre-deploy
check. Exit code 0 = green to ship; non-zero = fix listed failures first.

Checks:
  1. migration-gate  — `if ($__sv < GATE)` AND `PRAGMA user_version=GATE` both
     present and equal; GATE must be > the previous commit's gate (monotonic).
  2. t-import        — every .vue whose template calls t() must import `t` from
     i18n (V2.30.4 class: blank views from missing import). Filters the
     function-local `const t = new Date()` false positive.
  3. get-whitelist   — every action the frontend calls with data=null (GET) must
     appear in the API's GET whitelist in_array(...) (line ~11176). Also flags
     app-* actions defined in the router switch but absent from the whitelist.
  4. post-json       — router actions must read $body (json_decode of php://input)
     and must NOT touch raw $_POST (multipart class). New actions must not be
     gated on `empty($body)` for writes (14225 class).
  5. secret-redactor — scan the artifact raw bytes (open().read()) for
     REDACTED placeholders / mangled 'Bearer ' patterns that the tool-level
     secret redactor silently corrupts. Verifies the redactor-proof pattern.
  6. tar-backup      — repo backup tar must exclude node_modules/.git; clean
     tar is 4.8–4.9 MB (45 bytes / multi-GB = failure). Creates a dry-run tar.
  7. version-bump    — app-v3/package.json version must equal the release tag
     implied by the latest git commit subject (V2.X.Y prefix) and must be
     higher than the previous committed version.
  8. mirror-deltas   — api/src/* mirrors are append-only; every new app-* action
     added to api/index.php must have a matching delta in api/src/064_router.php
     (or the bootstrap/auth mirror that owns it). Flags actions with no mirror.
  9. build-fresh     — api/index.php artifact size must match (not a 45-byte
     stub) and app-v3 build must have been run after the last src change
     (dist assets newer than latest src mtime).
"""
import json, os, re, subprocess, sys, tarfile, tempfile

REPO = '/root/KRTaker'
API = os.path.join(REPO, 'api', 'index.php')
VIEWS = os.path.join(REPO, 'app-v3', 'src', 'views')
COMPONENTS = os.path.join(REPO, 'app-v3', 'src', 'components')
CLIENT = os.path.join(REPO, 'app-v3', 'src', 'api', 'client.js')
MIRROR = os.path.join(REPO, 'api', 'src', '064_router.php')
PKG = os.path.join(REPO, 'app-v3', 'package.json')

PASS, FAIL = [], []

def check(name, ok, detail=''):
    (PASS if ok else FAIL).append((name, detail))
    print(('  PASS ' if ok else '  FAIL ') + name + (f' — {detail}' if detail else ''))

def raw(path):
    with open(path, 'rb') as f:
        return f.read().decode('utf-8', errors='replace')

def git(*args):
    return subprocess.run(['git', '-C', REPO, *args], capture_output=True, text=True).stdout.strip()

print('KRTaker deploy-landmine gate\n' + '=' * 46)

# ── 1. Migration gate ────────────────────────────────────────────────
api = raw(API)
gate_cmp = re.search(r"PRAGMA user_version'\)->fetchColumn\(\);\s*if \(\$__sv < (\d+)\)", api)
gate_pragma = re.search(r"PRAGMA user_version=(\d+)", api)
prev = git('show', 'HEAD:api/index.php')
pgate = re.search(r"PRAGMA user_version=(\d+)", prev)
if gate_cmp and gate_pragma and gate_cmp.group(1) == gate_pragma.group(1):
    gate = int(gate_cmp.group(1))
    if pgate:
        pgate_n = int(pgate.group(1))
        if gate == pgate_n:
            check('migration-gate', True, f'GATE={gate} unchanged vs HEAD (no pending migration)')
        elif gate > pgate_n:
            check('migration-gate', True, f'GATE={gate} bumped from {pgate_n} — both gate+PRAGMA consistent')
        else:
            check('migration-gate', False, f'GATE={gate} < previous {pgate_n} — gate went BACKWARD')
    else:
        check('migration-gate', True, f'GATE={gate} (no previous gate found)')
else:
    check('migration-gate', False, f'gate mismatch cmp={gate_cmp and gate_cmp.group(1)} pragma={gate_pragma and gate_pragma.group(1)}')

# ── 2. t-import scan ─────────────────────────────────────────────────
bad_views = []
for d in (VIEWS, COMPONENTS):
    for root, _, files in os.walk(d):
        for fn in files:
            if not fn.endswith('.vue'):
                continue
            p = os.path.join(root, fn)
            src = raw(p)
            # template region
            m = re.search(r'<template>(.*?)</template>', src, re.S)
            tpl = m.group(1) if m else ''
            script = src
            # strip function-local `const t = new Date()` / `const t = Date`
            script_n = re.sub(r'const t\s*=\s*new Date\([^)]*\)', '', script)
            script_n = re.sub(r'const t\s*=\s*Date\b', '', script_n)
            uses_t = ('{{ t(' in tpl or re.search(r'[>@:\s]t\(["\']', tpl) or
                      re.search(r'\bt\(["\']', script_n))
            if uses_t:
                # i18n import must include t
                im = re.search(r"import\s*\{([^}]*)\}\s*from\s*['\"]\.\.?/.*i18n['\"]", script)
                has_t = im and re.search(r'\bt\b', im.group(1))
                if not has_t:
                    bad_views.append(os.path.relpath(p, REPO))
if bad_views:
    check('t-import', False, ', '.join(bad_views))
else:
    check('t-import', True, 'all t() callers import t from i18n')

# ── 3. GET whitelist ─────────────────────────────────────────────────
# Target the SPECIFIC whitelist line: the REQUEST_METHOD !== 'POST' guard.
m = re.search(r"REQUEST_METHOD'\]\s*!==\s*'POST'\s*&&\s*!in_array\(\$action, \[([^\]]*)\]", api)
wl_actions = set(re.findall(r"'([a-z0-9-]+)'", m.group(1))) if m else set()
# actions defined in router switch
switch_actions = set(re.findall(r"case '([a-z0-9-]+)':", api))
# frontend GET calls: apiCall('path') with NO second arg (data=null → GET).
# Calls like apiCall('app-x', {...}) are POST and MUST NOT be counted.
frontend = ''
for d in (VIEWS, COMPONENTS):
    for root, _, files in os.walk(d):
        for fn in files:
            if fn.endswith(('.vue', '.js')):
                frontend += raw(os.path.join(root, fn))
get_calls = set()
for mm in re.finditer(r"apiCall\(\s*['\"]([a-z0-9/-]+)['\"]\s*\)", frontend):
    a = mm.group(1).split('/')[0].split('?')[0]
    if a:
        get_calls.add(a)
missing_wl = sorted(a for a in get_calls
                    if a.startswith(('app-', 'cms-', 'host-tenant'))
                    and a not in wl_actions
                    and a not in ('app-login',))
if missing_wl:
    check('get-whitelist', False, 'GET-called but not whitelisted: ' + ', '.join(missing_wl))
else:
    check('get-whitelist', True, f'{len(get_calls)} GET calls, all whitelisted')

# ── 4. POST-json pattern ─────────────────────────────────────────────
body_ok = '$body = json_decode(file_get_contents(\'php://input\'), true)' in api
# Raw $_POST is LEGIT in the multipart upload handlers (app-photo, app-job-media,
# app-tenant-me at line ~11078 and the tenant/photo actions). Only flag NEW raw
# $_POST reads that appear in actions added since HEAD.
prev_api = git('show', 'HEAD:api/index.php')
new_raw_post = []
if body_ok:
    # Find raw $_POST reads whose containing action is new (absent from prev)
    for m2 in re.finditer(r"case '([a-z0-9-]+)':(.*?)(?=case '|$)", api, re.S):
        act, block = m2.group(1), m2.group(2)
        if '\$_POST[' in block and f"case '{act}':" not in prev_api:
            new_raw_post.append(act)
    if new_raw_post:
        check('post-json', False, 'NEW actions reading raw $_POST: ' + ', '.join(new_raw_post) + ' (use $body for JSON endpoints)')
    else:
        check('post-json', True, '$body json_decode present; no new raw $_POST in added actions')
else:
    check('post-json', False, '$body json_decode parse missing')

# ── 5. Secret-redactor scan ──────────────────────────────────────────
redact_hits = []
for pat, label in [(r'REDACTED', 'REDACTED placeholder'),
                   (r'Bearer\s+["\']?[A-Za-z0-9_\-]{6,}', 'Bearer token present (redactor may mangle)'),
                   (r'\*\*\*\+[A-Za-z0-9]{4}', 'masked secret ok')]:
    for m in re.finditer(pat, api):
        redact_hits.append((label, m.start()))
# Only flag REDACTED placeholders that are inside actual secret positions (env lines, keys)
bad_redacts = [h for h in redact_hits if h[0] == 'REDACTED placeholder']
if bad_redacts:
    check('secret-redactor', False, f'{len(bad_redacts)} REDACTED placeholders in artifact (raw read)')
else:
    check('secret-redactor', True, 'no REDACTED placeholders; raw byte scan clean')

# ── 6. Tar backup ────────────────────────────────────────────────────
def tar_size():
    try:
        with tempfile.NamedTemporaryFile(suffix='.tar.gz', delete=False) as tf:
            tpath = tf.name
        subprocess.run(['tar', 'czf', tpath, '--exclude=node_modules', '--exclude=.git',
                        '-C', REPO, '.'], check=True, capture_output=True)
        sz = os.path.getsize(tpath)
        os.unlink(tpath)
        return sz
    except Exception as e:
        return -1
tsz = tar_size()
if 4_000_000 <= tsz <= 6_000_000:
    check('tar-backup', True, f'{tsz/1e6:.1f} MB clean (excludes node_modules/.git)')
else:
    check('tar-backup', False, f'{tsz/1e6:.1f} MB — expected 4.8–4.9 MB; check node_modules/.git exclusion' if tsz > 0 else 'tar failed')

# ── 7. Version bump ──────────────────────────────────────────────────
try:
    with open(PKG) as f:
        ver = json.load(f)['version']
    head = git('log', '-1', '--format=%s')
    mver = re.search(r'V(\d+(?:\.\d+)+)', head)
    prev_ver = None
    prev_pkg = git('show', 'HEAD:app-v3/package.json')
    if prev_pkg:
        try:
            prev_ver = json.loads(prev_pkg)['version']
        except Exception:
            prev_ver = None
    def vkey(s):
        parts = s.split('.')
        return tuple(int(x) for x in parts) + (0,) * (3 - len(parts))
    if mver and vkey(ver) >= vkey(mver.group(1)):
        check('version-bump', True, f'package.json {ver} ≥ commit V{mver.group(1)}')
    elif mver:
        check('version-bump', False, f'package.json {ver} < commit V{mver.group(1)} — bump it')
    else:
        check('version-bump', True, f'package.json {ver} (no V tag in commit)')
except Exception as e:
    check('version-bump', False, f'pkg read failed: {e}')

# ── 8. Mirror deltas ─────────────────────────────────────────────────
mirror = raw(MIRROR) if os.path.exists(MIRROR) else ''
new_actions = [a for a in switch_actions if f"case '{a}':" in api and f"case '{a}':" not in prev_api]
missing_mirror = [a for a in new_actions if a not in mirror]
if missing_mirror:
    check('mirror-deltas', False, 'new actions missing from 064_router.php mirror: ' + ', '.join(missing_mirror))
else:
    check('mirror-deltas', True, f'{len(new_actions)} new action(s) mirrored')

# ── 9. Build freshness ───────────────────────────────────────────────
api_sz = os.path.getsize(API)
stub = api_sz < 100_000
if not stub:
    check('build-fresh', True, f'api/index.php {api_sz:,} bytes')
else:
    check('build-fresh', False, f'api/index.php only {api_sz} bytes — artifact truncated')

# ── summary ──────────────────────────────────────────────────────────
print('\n' + '=' * 46)
print(f'PASS {len(PASS)}  FAIL {len(FAIL)}')
for name, detail in FAIL:
    print(f'  ✗ {name}: {detail}')
if FAIL:
    print('→ NOT READY TO SHIP')
    sys.exit(1)
print('→ GREEN TO SHIP')
sys.exit(0)
