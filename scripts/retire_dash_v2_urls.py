#!/usr/bin/env python3
"""Retire dashboard-v2.html: repoint API URL literals to app-v3."""
import pathlib, re

SRC = pathlib.Path('/root/KRTaker/api/src')
V3 = 'https://krtaker.com/app-v3/'

edits = [
    ('021_lib_finance_2.php',
     "https://krtaker.com/dashboard-v2.html?gw=",
     "https://krtaker.com/app-v3/#/dashboard?gw=", 1),
    ('030_lib_finance_4.php',
     "https://krtaker.com/dashboard-v2.html?inv=",
     "https://krtaker.com/app-v3/#/invoices?open=", 1),
    ('040_lib_misc_040.php',
     "'workspace_url' => 'https://krtaker.com/dashboard-v2.html'",
     "'workspace_url' => 'https://krtaker.com/app-v3/'", 1),
    ('064_router.php',
     "'workspace_url' => 'https://krtaker.com/dashboard-v2.html'",
     "'workspace_url' => 'https://krtaker.com/app-v3/'", 2),
    ('064_router.php',
     "'/dashboard-v2.html#maintenance'",
     "'/app-v3/#/maintenance'", 1),
    ('064_router.php',
     "'/dashboard-v2.html#invoices'",
     "'/app-v3/#/invoices'", 1),
    ('064_router.php',
     "https://krtaker.com/dashboard-v2.html?inv=",
     "https://krtaker.com/app-v3/#/invoices?open=", 1),
    ('064_router.php',
     "'pay_url' => 'https://krtaker.com/dashboard-v2.html'",
     "'pay_url' => 'https://krtaker.com/app-v3/'", 1),
]

for fname, old, new, n in edits:
    p = SRC / fname
    t = p.read_text()
    cnt = t.count(old)
    status = 'OK' if cnt == n else f'MISMATCH (found {cnt}, expected {n})'
    if cnt:
        t = t.replace(old, new)
        p.write_text(t)
    print(f'{fname}: {status} — replaced {cnt}')

# Remaining bare '/dashboard-v2.html' literals in 064_router.php (test push, default url, rent-due push)
p = SRC / '064_router.php'
t = p.read_text()
left = t.count("'/dashboard-v2.html'")
print(f'\n064_router.php remaining bare literals: {left}')
t = t.replace("'/dashboard-v2.html'", "'/app-v3/'")
p.write_text(t)
print('replaced all bare literals')

# push_to_user default param — find definition
for f in sorted(SRC.iterdir()):
    t = f.read_text()
    if 'function push_to_user' in t:
        print(f'\npush_to_user defined in {f.name}')
        if "url = '/dashboard-v2.html'" in t:
            f.write_text(t.replace("url = '/dashboard-v2.html'", "url = '/app-v3/'"))
            print('   -> default param updated to /app-v3/')

# Final audit: any remaining dashboard-v2.html references in src?
print('\n--- FINAL AUDIT (src) ---')
found = False
for f in sorted(SRC.iterdir()):
    t = f.read_text()
    for i, line in enumerate(t.splitlines(), 1):
        if 'dashboard-v2.html' in line and 'docs/' not in line:
            print(f'{f.name}:{i}: {line.strip()[:130]}')
            found = True
if not found:
    print('CLEAN — no dashboard-v2.html references remain in api/src')
