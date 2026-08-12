#!/usr/bin/env python3
"""KRTaker i18n batch pass 2 — translate chrome of all remaining views.
Restricted to <template> section only. Dry run: --apply to write."""
import re, sys, os, glob

VIEWS = '/root/KRTaker/app-v3/src/views'
DONE = {'DashboardView','InvoicesView','LoginView','MaintenanceView','NoticesView',
        'NotificationsView','PropertiesView','SettingsView','SetupView','SupportView',
        'TenantsView','WikiView'}
HUB = {'FinanceView','PortfolioView','BmsView','CommunityView','LegalHubView','SecureView'}
JUNK = {'01XXXXXXXXX','ABC-4kg','3rd','DAG-…','DHAKA-METRO-1234','KH-…','U-006','••••••',
        'accounts@…',"' + inline(line.slice(4)) + '"}
DIGITISH = re.compile(r'^[\dXx…\s.,\-]+$')

H_RE = re.compile(r'<h([1-4])([^>]*)>([^<{\n][^<{]*)</h\1>')
BTN_RE = re.compile(r'<button([^>]*)>\s*([A-Za-z][^<{]{1,45}?)\s*</button>')
TH_RE = re.compile(r'<th([^>]*)>\s*([A-Za-z][^<{]{1,30}?)\s*</th>')
A_RE = re.compile(r'<a([^>]*)>\s*([A-Za-z][^<{]{1,45}?)\s*</a>')
PH_RE = re.compile(r'\splaceholder="([^"]{2,60})"')
TABL_RE = re.compile(r'\{\{ l \}\}')

apply = '--apply' in sys.argv
strings = set()
changed = []

def good(txt):
    if not txt or len(txt) > 60: return False
    if '{' in txt or '}' in txt: return False
    if '&' in txt: return False   # HTML entities render literally inside mustache
    if txt in JUNK: return False
    if DIGITISH.match(txt): return False
    if len(txt) <= 2: return False
    return True

def esc(txt):
    return txt.replace("\\", "\\\\").replace("'", "\\'")

for path in sorted(glob.glob(VIEWS + '/*.vue')):
    name = os.path.basename(path)[:-4]
    if name in DONE: continue
    src = open(path, encoding='utf-8').read()
    if 'lib/i18n' in src: continue
    tpl_start = src.find('<template>')
    tpl_end = src.find('</template>')
    if tpl_start < 0 or tpl_end < 0:
        print(f'SKIP no template: {name}')
        continue
    template = src[tpl_start:tpl_end]
    orig_tpl = template
    found = False

    def hsub(m):
        global found
        txt = m.group(3).strip()
        if not good(txt): return m.group(0)
        found = True; strings.add(txt)
        return f'<h{m.group(1)}{m.group(2)}>{{{{ t(\'{esc(txt)}\') }}}}</h{m.group(1)}>'
    template = H_RE.sub(hsub, template)

    def bsub(m):
        global found
        txt = m.group(2).strip()
        if not good(txt): return m.group(0)
        found = True; strings.add(txt)
        return f'<button{m.group(1)}>{{{{ t(\'{esc(txt)}\') }}}}</button>'
    template = BTN_RE.sub(bsub, template)

    def tsub(m):
        global found
        txt = m.group(2).strip()
        if not good(txt): return m.group(0)
        found = True; strings.add(txt)
        return f'<th{m.group(1)}>{{{{ t(\'{esc(txt)}\') }}}}</th>'
    template = TH_RE.sub(tsub, template)

    def asub(m):
        global found
        txt = m.group(2).strip()
        if not good(txt): return m.group(0)
        found = True; strings.add(txt)
        return f'<a{m.group(1)}>{{{{ t(\'{esc(txt)}\') }}}}</a>'
    template = A_RE.sub(asub, template)

    def psub(m):
        global found
        txt = m.group(1)
        if not good(txt): return m.group(0)
        found = True; strings.add(txt)
        return f' :placeholder="t(\'{esc(txt)}\')"'
    template = PH_RE.sub(psub, template)

    if name in HUB and TABL_RE.search(template):
        found = True
        strings.add('[hub-tab-labels]')
        template = TABL_RE.sub(lambda m: '{{ t(l) }}', template)

    if found:
        src = src[:tpl_start] + template + src[tpl_end:]
        lines = src.split('\n')
        idx = 0
        for i, ln in enumerate(lines):
            if ln.startswith('import ') or ln.startswith('import{'):
                idx = i + 1
                break
        else:
            for i, ln in enumerate(lines):
                if '<script' in ln:
                    idx = i + 1
                    break
        lines.insert(idx, "import { t } from '../lib/i18n'")
        src = '\n'.join(lines)
        changed.append(name)
        if apply:
            open(path, 'w', encoding='utf-8').write(src)

print('APPLY' if apply else 'DRYRUN')
print(f'files changed: {len(changed)}')
print(' '.join(sorted(changed)))
print(f'unique strings extracted: {len(strings)}')
for s in sorted(strings):
    print(repr(s))
