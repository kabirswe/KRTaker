#!/usr/bin/env python3
"""Generate KRTaker brand PWA icons (192/512) + apple-touch-icon.

Design: rounded-square diagonal gradient (#2F80ED -> #1E5EB8) with a white
key glyph (Key Responsibility Taker). Supersampled 4x for antialiasing.
"""
import os
from PIL import Image, ImageDraw

C1 = (47, 128, 237)    # #2F80ED
C2 = (30, 94, 184)     # #1E5EB8


def gradient_square(size, c1, c2):
    """Diagonal gradient image."""
    g = Image.linear_gradient('L').resize((size, size))
    g = g.rotate(45, expand=True)
    # center-crop back to square
    w, h = g.size
    s = min(w, h)
    g = g.crop(((w - s) // 2, (h - s) // 2, (w + s) // 2, (h + s) // 2)).resize((size, size))
    # map L -> color ramp
    px = g.load()
    out = Image.new('RGB', (size, size))
    opx = out.load()
    for y in range(size):
        for x in range(size):
            t = px[x, y] / 255.0
            opx[x, y] = tuple(int(a + (b - a) * t) for a, b in zip(c1, c2))
    return out


def rounded_mask(size, radius_ratio=0.22):
    m = Image.new('L', (size, size), 0)
    d = ImageDraw.Draw(m)
    d.rounded_rectangle([0, 0, size - 1, size - 1], radius=int(size * radius_ratio), fill=255)
    return m


def key_layer(size, color=(255, 255, 255)):
    """White key glyph centered, occupying ~55% of canvas (maskable-safe)."""
    img = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    cx, cy = size * 0.30, size * 0.50
    r1, r2 = size * 0.165, size * 0.105          # bow outer/inner radius
    # bow ring
    d.ellipse([cx - r1, cy - r1, cx + r1, cy + r1], fill=color)
    d.ellipse([cx - r2, cy - r2, cx + r2, cy + r2], fill=(0, 0, 0, 0))
    # shaft (rounded, from bow to right)
    sx0, sx1 = size * 0.30, size * 0.80
    sy0, sy1 = cy - size * 0.048, cy + size * 0.048
    d.rounded_rectangle([sx0, sy0, sx1, sy1], radius=int(size * 0.048), fill=color)
    # teeth (two bumps below shaft near right end)
    t_w, t_h = size * 0.058, size * 0.15
    for tx in (size * 0.615, size * 0.705):
        d.rounded_rectangle([tx, cy + size * 0.048 - 2, tx + t_w, cy + size * 0.048 + t_h - 2],
                            radius=int(size * 0.03), fill=color)
    return img


def make_icon(path, size, ss=4):
    C = size * ss
    base = gradient_square(C, C1, C2).convert('RGBA')
    base.putalpha(rounded_mask(C))
    key = key_layer(C).resize((C, C), Image.LANCZOS)
    out = Image.alpha_composite(base, key)
    out = out.resize((size, size), Image.LANCZOS)
    out.save(path, 'PNG')
    print(f'  {path}: {os.path.getsize(path)} bytes')


if __name__ == '__main__':
    os.makedirs('public/icons', exist_ok=True)
    make_icon('public/icons/icon-192.png', 192)
    make_icon('public/icons/icon-512.png', 512)
    make_icon('public/icons/apple-touch-icon.png', 180)
    print('done')
