#!/usr/bin/env python3
"""Build deployable api/index.php from src/ modules (concatenation, no rewrite)."""
import os, sys
SRC = os.path.dirname(os.path.abspath(__file__)) + '/src'
OUT = os.path.dirname(os.path.abspath(__file__)) + '/index.php'
parts = []
for fn in sorted(os.listdir(SRC)):
    if fn.endswith('.php'):
        parts.append(open(os.path.join(SRC, fn), encoding='utf-8').read())
out = ''.join(parts).rstrip('\n') + '\n'
open(OUT, 'w', encoding='utf-8').write(out)
print('wrote', OUT, len(out), 'bytes')
