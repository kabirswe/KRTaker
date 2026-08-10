#!/usr/bin/env python3
"""Generate solid gradient-blue PWA icons (192/512). Real brand icons later."""
import struct, zlib, os

def make_png(path, size):
    w = h = size
    rows = []
    for y in range(h):
        t = y / h
        r = int(47 + (30 - 47) * t); g = int(128 + (94 - 128) * t); b = int(237 + (184 - 237) * t)
        row = b'\x00' + bytes([r, g, b]) * w
        rows.append(row)
    raw = b''.join(rows)
    def chunk(typ, data):
        c = typ + data
        return struct.pack('>I', len(data)) + c + struct.pack('>I', zlib.crc32(c) & 0xffffffff)
    png = b'\x89PNG\r\n\x1a\n'
    png += chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0))
    png += chunk(b'IDAT', zlib.compress(raw, 9))
    png += chunk(b'IEND', b'')
    open(path, 'wb').write(png)

os.makedirs('public/icons', exist_ok=True)
make_png('public/icons/icon-192.png', 192)
make_png('public/icons/icon-512.png', 512)
print('icons written:', os.listdir('public/icons'))
