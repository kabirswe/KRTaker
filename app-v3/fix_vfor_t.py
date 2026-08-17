#!/usr/bin/env python3
"""Fix the v-for=\"t in ...\" shadowing bug class across app-v3 views.
The i18n translate function is named `t`; a v-for loop variable named `t`
shadows it inside the loop body, so t('Delete') throws "t is not a function"
and the whole view fails to render. Rename loop var t -> x per loop, keeping
the parent-scope t() translate calls intact.
"""
import re, os

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'src')

# (file, [(vfor_line_1based, end_line_1based_inclusive), ...])
SITES = {
    'views/AccountsView.vue': [(264, 279), (334, 342), (363, 372), (385, 394)],
    'views/TenantsView.vue': [(826, 859)],
    'views/UtilityBillsView.vue': [(397, 404)],
    'views/InspectionsView.vue': [(217, 220)],
    'views/LeasesView.vue': [(386, 389)],
}

WORD_T = re.compile(r'\bt\b(?!\()')  # standalone t not followed by '('

for path, ranges in SITES.items():
    full = os.path.join(ROOT, path)
    lines = open(full, encoding='utf-8').read().splitlines()
    changed = 0
    for (start, end) in ranges:
        for i in range(start - 1, end):
            new = WORD_T.sub('x', lines[i])
            if new != lines[i]:
                lines[i] = new
                changed += 1
    open(full, 'w', encoding='utf-8').write('\n'.join(lines) + '\n')
    print(f'{path}: {changed} lines updated')
