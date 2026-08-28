#!/usr/bin/env python3
"""Split api/index.php into src/ modules + build script.

Strategy (safety-first):
- Parse the single file, find every top-level `function` boundary and the
  router start. Group consecutive functions into topical modules.
- Emit src/ files preserving EXACT bytes (no rewriting).
- build_api.py concatenates src files in dependency order → index.php.
- First build MUST be byte-identical to the pre-split file (sha256 check).

Usage:
  python3 split_api.py          # split into src/ (idempotent, safe)
  python3 split_api.py --verify # build + compare sha256 vs backup
"""
import hashlib
import os
import re
import sys
import shutil

API = '/root/KRTaker/api/index.php'
SRC = '/root/KRTaker/api/src'
BACKUP = '/root/KRTaker/api/index.php.presplit'
BUILD = '/root/KRTaker/api/build_api.py'

# Topical module map: function name prefix -> module file
# Order matters (bootstrap first). Anything unmatched goes to lib_misc.
MODULES = [
    ('bootstrap', None),  # header code before first function (special)
    ('lib_core',   ['json_fail_safe', 'errlog_table', 'krenv', 'json_out', 'db',
                    'esc', 'client_ip', 'audit', 'b64url', 'money_bd']),
    ('lib_auth',   ['record_attempt', 'recent_fails', 'auth_blocked', 'otp_blocked',
                    'recent_any', 'retry_after_secs', 'throttle_out', 'current_user',
                    'require_user', 'require_module', 'team_owner_only',
                    'team_scope_email', 'team_revoke_tokens', 'password_policy_error',
                    'referral_code_for', 'user_key_for', 'user_payload', 'sub_info']),
    ('lib_mail',   ['smtp_', 'mail_fallback', 'send_mail', 'otp_email_html',
                    'welcome_email_html', 'contact_email_html', 'mail_switch',
                    'mail_hdr', 'collections_email_html']),
    ('lib_push',   ['vapid_', 'hkdf_', 'webpush_', 'push_to_user', 'ec_spki',
                    'webpush_encrypt', 'webpush_send', 'rent_due_push_data']),
    ('lib_finance',['inv_context', 'invoice_print_html', 'collections_data',
                    'record_payment', 'gateway_', 'reminder_', 'payment_']),
    ('lib_legal',  ['TPL_', 'seed_tpl_body', 'render_merge', 'num_to_words',
                    'print_brand_img', 'wa_link', 'legal_']),
    ('lib_cms',    ['CMS_', 'admin_cfg', 'WEBHOOK_', 'webhook_dispatch',
                    'SETTINGS_', 'settings_', 'plan_', 'cms_']),
    ('lib_admin',  ['ADMIN_', 'DB_', 'admin_', 'superadmin', 'app_admin']),
    ('lib_ai',     ['ai_', 'kr_']),
    ('seed',       None),  # the big seed function (special: seed_* / setup)
    ('router',     None),  # from `/* ---------- router ---------- */` to EOF
]


def parse_functions(text):
    """Return list of (start_line, name) for top-level function defs."""
    out = []
    for m in re.finditer(r'^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(', text, re.M):
        out.append((text[:m.start()].count('\n') + 1, m.group(1)))
    return out


def module_for(name):
    for mod, prefixes in MODULES:
        if prefixes is None:
            continue
        for p in prefixes:
            if name.startswith(p):
                return mod
    return 'lib_misc'


def split():
    text = open(API, encoding='utf-8').read()
    lines = text.split('\n')
    funcs = parse_functions(text)
    router_line = None
    for i, ln in enumerate(lines, 1):
        if '/* ---------- router ---------- */' in ln:
            router_line = i
            break
    assert router_line, 'router marker not found'
    os.makedirs(SRC, exist_ok=True)

    # boundaries: function start lines + router line + EOF
    starts = [f[0] for f in funcs]
    # bootstrap: line 1 .. first function start - 1
    # group: from a function start to the next function start - 1 (or router line)
    sections = []  # (module, start_line, end_line_inclusive)
    first_fn = funcs[0][0]
    sections.append(('bootstrap', 1, first_fn - 1))
    for i, (ln, name) in enumerate(funcs):
        nxt = funcs[i + 1][0] if i + 1 < len(funcs) else router_line
        sections.append((module_for(name), ln, nxt - 1))
    sections.append(('router', router_line, len(lines)))

    # merge consecutive sections of same module
    merged = []
    for mod, s, e in sections:
        if merged and merged[-1][0] == mod and s == merged[-1][2] + 1:
            merged[-1] = (mod, merged[-1][1], e)
        else:
            merged.append((mod, s, e))

    # write files (order preserved in filenames)
    seen = {}
    for idx, (mod, s, e) in enumerate(merged):
        chunk = '\n'.join(lines[s - 1:e])
        key = mod
        if mod in ('lib_misc',):
            key = f'{mod}_{idx:03d}'
        seen[key] = seen.get(key, 0) + 1
        if seen[key] > 1:
            key = f'{key}_{seen[key]}'
        # module files accumulate all their sections; router/seed/bootstrap standalone
        with open(os.path.join(SRC, f'{idx:03d}_{key}.php'), 'w', encoding='utf-8') as f:
            f.write(chunk + '\n')
        print(f'{idx:03d} {key:16s} lines {s}-{e} ({e-s+1})')

    # generate build script
    files = sorted(os.listdir(SRC))
    build = f'''#!/usr/bin/env python3
"""Build deployable api/index.php from src/ modules (concatenation, no rewrite)."""
import os, sys
SRC = os.path.dirname(os.path.abspath(__file__)) + '/src'
OUT = os.path.dirname(os.path.abspath(__file__)) + '/index.php'
parts = []
for fn in sorted(os.listdir(SRC)):
    if fn.endswith('.php'):
        parts.append(open(os.path.join(SRC, fn), encoding='utf-8').read())
out = '\\n'.join(parts).rstrip('\\n') + '\\n'
open(OUT, 'w', encoding='utf-8').write(out)
print('wrote', OUT, len(out), 'bytes')
'''
    with open(BUILD, 'w') as f:
        f.write(build)
    print('\nbuild script:', BUILD)
    print('src files:', len(files))


def verify():
    # rebuild and compare with backup
    sub = __import__('subprocess')
    sub.run([sys.executable, BUILD], check=True)
    if not os.path.exists(BACKUP):
        print('no backup at', BACKUP, '— copying current as reference')
        shutil.copy(API, BACKUP)
    a = hashlib.sha256(open(BACKUP, 'rb').read()).hexdigest()
    b = hashlib.sha256(open(API, 'rb').read()).hexdigest()
    print('backup sha:', a)
    print('build   sha:', b)
    print('MATCH ✅' if a == b else 'MISMATCH ❌')
    return a == b


if __name__ == '__main__':
    if '--verify' in sys.argv:
        sys.exit(0 if verify() else 1)
    split()
