#!/usr/bin/env python3
"""Mall Manager module-map poster (1600x1000) — PIL + Noto Bengali."""
from PIL import Image, ImageDraw, ImageFont

W, H = 1600, 1000
BG = (15, 23, 42)          # dark navy
RED = (127, 29, 29)
RED2 = (160, 44, 44)
CARD = (30, 41, 59)
BORDER = (51, 65, 85)
TXT = (241, 245, 249)
MUT = (148, 163, 184)
GOLD = (251, 191, 36)
OK = (74, 222, 128)

def font(path, size):
    return ImageFont.truetype(path, size)

def fonts():
    try:
        return {
            'serif_b': font('/usr/share/fonts/truetype/noto/NotoSerifBengali-Bold.ttf', 0),
            'sans_b': font('/usr/share/fonts/truetype/noto/NotoSansBengali-Bold.ttf', 0),
            'sans': font('/usr/share/fonts/truetype/noto/NotoSansBengali-Regular.ttf', 0),
        }
    except Exception:
        return None

# fallback: find any noto bengali files
import glob
cands = glob.glob('/usr/share/fonts/truetype/noto/*Bengali*.ttf')
print('bengali fonts:', cands[:4])

img = Image.new('RGB', (W, H), BG)
d = ImageDraw.Draw(img)

def load(name, size):
    path = {'serif_b': None, 'sans_b': None, 'sans': None}
    for p in cands:
        pl = p.lower()
        if name == 'sans_b' and 'bold' in pl and 'uib' in pl:
            path[name] = p; break
    if not path[name]:
        for p in cands:
            pl = p.lower()
            if name == 'sans' and 'regular' in pl and 'ui' in pl:
                path[name] = p; break
    if not path[name]:
        path[name] = cands[0]
    return ImageFont.truetype(path[name], size)

F_BIG = load('sans_b', 54)
F_TITLE = load('sans_b', 30)
F_SUB = load('sans', 20)
F_CARD = load('sans', 21)
F_CARD_B = load('sans_b', 22)
F_SMALL = load('sans', 16)

# header band
d.rectangle([0, 0, W, 150], fill=RED)
d.rectangle([0, 146, W, 150], fill=RED2)
# center title
t = 'MALL MANAGER'
tw = d.textlength(t, font=F_BIG)
d.text(((W - tw) / 2, 26), t, font=F_BIG, fill=(255, 255, 255))
t2 = 'Commercial Building Management System — ১৩টি মডিউল'
tw2 = d.textlength(t2, font=F_SUB)
d.text(((W - tw2) / 2, 98), t2, font=F_SUB, fill=(254, 226, 226))

# module cards (icon + bn + en)
MODULES = [
    ('🏪', 'দোকান ও মালিক', 'Spaces & Ownership'),
    ('🧾', 'সার্ভিস চার্জ বিলিং', 'Service-Charge Billing'),
    ('⚡', 'সাব-মিটার বিদ্যুৎ/পানি', 'Sub-Meter Utilities'),
    ('💵', 'আদায় ও রসিদ', 'Collections & Receipts'),
    ('📒', 'ডাবল-এন্ট্রি হিসাব', 'Double-Entry Accounting'),
    ('📊', 'রিপোর্ট ও অ্যানালিটিক্স', 'Reports & Analytics'),
    ('⚠️', 'এলার্ট ও AMC', 'Alerts & AMC'),
    ('📲', 'বাংলা SMS', 'SMS & Communication'),
    ('🧰', 'ভেন্ডর, ব্যয়, সম্পদ', 'Vendors & Expenses'),
    ('🔧', 'অভিযোগ টিকেট', 'Complaints'),
    ('🏛️', 'গভর্নেন্স ও কমিটি', 'Governance'),
    ('🔐', 'রোল, অনুমোদন, অডিট', 'Security & Audit'),
    ('🏠', 'মালিক পোর্টাল + অফলাইন', 'Owner Portal & Offline'),
]

cols, rows = 4, 4
cw, ch = 360, 180
gx, gy = 26, 26
x0, y0 = 36, 196

for i, (ico, bn, en) in enumerate(MODULES):
    r, c = divmod(i, cols)
    x = x0 + c * (cw + gx)
    y = y0 + r * (ch + gy)
    d.rounded_rectangle([x, y, x + cw, y + ch], radius=16, fill=CARD, outline=BORDER, width=2)
    # icon
    d.text((x + 18, y + 16), ico, font=load('sans', 30))
    # bn name
    d.text((x + 18, y + 78), bn, font=F_CARD_B, fill=TXT)
    # en sub
    d.text((x + 18, y + 122), en, font=F_SMALL, fill=MUT)

# pricing strip at bottom
y = y0 + 4 * (ch + gy) + 6
d.rounded_rectangle([36, y, W - 36, y + 108], radius=16, fill=(51, 30, 30), outline=RED2, width=2)
d.text((56, y + 14), 'মূল্য:', font=F_CARD_B, fill=GOLD)
prices = [
    ('🖥️ On-Premise', '৳৫,০০,০০০ এককালীন'),
    ('☁️ বার্ষিক', '৳১,০০,০০০/বছর'),
    ('👥 SaaS', '৳১০০/ইউজার/মাস'),
]
px = 160
for name, pr in prices:
    d.text((px, y + 14), name, font=F_CARD_B, fill=TXT)
    d.text((px, y + 58), pr, font=F_CARD, fill=(254, 215, 170))
    px += 440

img.save('/tmp/mall_shots/05_module_map.png')
print('diagram saved')
