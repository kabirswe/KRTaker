#!/usr/bin/env python3
# Mall Manager — Bengali product brochure (browser-rendered PDF, teal/amber design)
import html, base64, os

IMG = '/tmp/wiki_img'
def b64(p):
    with open(p, 'rb') as f:
        return base64.b64encode(f.read()).decode()
def img(p, w, h=None, extra=''):
    d = b64(os.path.join(IMG, p))
    ext = 'png' if p.endswith('png') else 'jpeg'
    st = f'width:{w}mm' + (f';height:{h}mm;object-fit:cover' if h else '')
    return f'<img src="data:image/{ext};base64,{d}" style="{st};border-radius:2mm;{extra}" />'
E = html.escape

TEAL='#0F766E'; TEAL_D='#115E59'; TEAL_L='#CCFBF1'; AMBER='#F59E0B'; AMBER_D='#B45309'
SLATE='#334155'; MUT='#64748B'; BG='#F1F5F9'; BD='#E2E8F0'; PAPER='#FCFDFF'

def page(inner, cls=''):
    return f'<div class="page {cls}" style="width:210mm;height:297mm;background:{PAPER};position:relative;overflow:hidden;page-break-after:always">{inner}</div>'

def footer(txt='Mall Manager — প্রোডাক্ট ব্রোশিওর · v1.0'):
    return f'''<div style="position:absolute;left:14mm;right:14mm;bottom:7mm;display:flex;justify-content:space-between;border-top:0.5mm solid {TEAL};padding-top:2.2mm;font-size:2.8mm;color:{MUT}">
      <div>{E(txt)}</div><div style="color:{TEAL};font-weight:700">প্রস্তুতকারক: কেআরটেকার (বিটিএসসিওএলের একটি প্রতিষ্ঠান) · মার্কেটিং: অ্যাপভ্যালি</div></div>'''

# ── 12 modules (from the wiki) ──
MODULES = [
    ('🏪','দোকান ও মালিক','মালিক/কোম্পানি প্রোফাইল (KRTaker-স্টাইল), মালিক পোর্টাল, যেকোনো নামে ক্লিক → ড্রয়ার'),
    ('🧾','বিলিং','সার্ভিস + ইউটিলিটি বিল, মিটার রিডিং → অটো বিল, বিল-প্যাড প্রিন্ট, A4/A5 টেমপ্লেট ও PDF'),
    ('💳','আদায় ও পেমেন্ট','২-ধাপ আদায় ফর্ম, ক্যাশ/ব্যাংক/বিকাশ/নগদ + নির্দিষ্ট অ্যাকাউন্ট, RCT- রসিদ, পেমেন্ট ইতিহাস'),
    ('⚡','মিটার ও ইউটিলিটি','সাব-মিটার রিডিং, কার্যকর বিদ্যুৎ রেট ক্যালকুলেটর, দোকানের তথ্য প্যানেল (বকেয়া + ৬ মাসের ইতিহাস)'),
    ('📊','হিসাব (Smart Ledger)','মাল্টি-লেভেল চার্ট অব অ্যাকাউন্টস, জার্নাল ভাউচার, পার্টি লেজার, ট্রায়াল ব্যালেন্স, P&L, ক্যাশফ্লো'),
    ('🏦','ব্যাংক স্টেটমেন্ট','CSV ইমপোর্ট, অটো-ম্যাচ, রিকনসিলিয়েশন ভার্ডিক্ট, একাধিক ব্যাংক ও মোবাইল ব্যাংকিং'),
    ('🔔','এলার্ট ও এসএমএস','উচ্চ বকেয়া/সংযোগ বিচ্ছিন্ন ঝুঁকি/এএমসি এলার্ট, রিমাইন্ডার ও নোটিশ ব্লাস্ট, এসএমএস লগ'),
    ('🧑🤝🧑','ভাড়াটিয়া ও চুক্তি','সম্পূর্ণ ভাড়াটিয়া প্রোফাইল, ভাড়া চুক্তি, ভাড়া আদায় ও বকেয়া'),
    ('🛠️','সম্পদ, কমপ্লেইন্ট, নোটিশ','সম্পদ রেজিস্টার + এএমসি, অভিযোগ ট্র্যাকিং, নোটিশ ও এসএমএস ব্রডকাস্ট'),
    ('🔐','ভূমিকা ও নিরাপত্তা','সুপার অ্যাডমিন/মালিক/ম্যানেজার/হিসাবরক্ষক/কালেক্টর, ভয়েড-মওকুফ অনুমোদন ফ্লো, অডিট লগ'),
    ('📴','অফলাইন ও সিঙ্ক','সার্ভিস ওয়ার্কার শেল, লেখা-কিউ, ইন্টারনেট ফিরলেই অটো-সিঙ্ক'),
    ('⚙️','সেটিংস ও ব্যাকআপ','৭টি সেটিংস ট্যাব, লোগো/ব্র্যান্ডিং, এক-ক্লিক ব্যাকআপ ও পুনরুদ্ধার'),
]

P1 = page(f'''
<div style="height:118mm;background:linear-gradient(165deg,{TEAL} 0%,{TEAL_D} 100%);padding:14mm 16mm;position:relative;overflow:hidden">
  <div style="position:absolute;right:-18mm;top:-20mm;width:70mm;height:70mm;border-radius:50%;background:rgba(255,255,255,.08)"></div>
  <div style="position:absolute;right:26mm;top:32mm;width:26mm;height:26mm;border-radius:50%;background:rgba(255,255,255,.07)"></div>
  <div style="position:absolute;left:-12mm;bottom:-16mm;width:50mm;height:50mm;border-radius:50%;background:rgba(255,255,255,.06)"></div>
  <div style="display:flex;flex-direction:column;align-items:center;text-align:center;position:relative">
    <div style="width:18mm;height:18mm;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:9mm">MM</div>
    <div style="color:#fff;font-weight:700;font-size:12mm;margin-top:6mm">মল ম্যানেজার</div>
    <div style="color:#fff;font-size:5mm;margin-top:3mm;font-weight:700">Mall Manager — মল ও কমার্শিয়াল বিল্ডিং ম্যানেজমেন্ট সফটওয়্যার</div>
    <div style="color:rgba(255,255,255,.88);font-size:3.4mm;margin-top:2.5mm">দোকান ও মালিক · বিলিং · আদায় · হিসাব · এসএমএস · অফলাইন</div>
    <div style="width:34mm;height:1.2mm;background:{AMBER};border-radius:1mm;margin-top:5mm"></div>
  </div>
</div>
<div style="padding:9mm 16mm 0;text-align:center">
  <div style="display:flex;gap:5mm;justify-content:center;margin-bottom:7mm">
    {f'<div style="flex:1;max-width:56mm;background:{BG};border:0.4mm solid {BD};border-top:1.2mm solid {TEAL};border-radius:2mm;padding:3mm 4mm"><div style="font-size:2.8mm;color:{MUT};font-weight:700">{E("ব্রোশিওর ভার্সন")}</div><div style="font-size:4.6mm;color:{TEAL};font-weight:700;margin-top:1.5mm">v1.0</div></div>'}
    {f'<div style="flex:1;max-width:56mm;background:{BG};border:0.4mm solid {BD};border-top:1.2mm solid {TEAL};border-radius:2mm;padding:3mm 4mm"><div style="font-size:2.8mm;color:{MUT};font-weight:700">{E("অ্যাপ ভার্সন")}</div><div style="font-size:4.6mm;color:{TEAL};font-weight:700;margin-top:1.5mm">v2.2</div></div>'}
    {f'<div style="flex:1;max-width:56mm;background:{BG};border:0.4mm solid {BD};border-top:1.2mm solid {TEAL};border-radius:2mm;padding:3mm 4mm"><div style="font-size:2.8mm;color:{MUT};font-weight:700">{E("প্ল্যাটফর্ম")}</div><div style="font-size:4.6mm;color:{TEAL};font-weight:700;margin-top:1.5mm">ওয়েব + অফলাইন</div></div>'}
  </div>
  <div style="display:inline-block;padding:2mm;background:#fff;border-radius:2.5mm;box-shadow:0 1mm 3mm rgba(15,118,110,.18);border:0.4mm solid {BD}">{img('05_module_map.png', 128)}</div>
  <div style="font-size:3.2mm;color:{MUT};margin-top:4mm">১৩টি মডিউল — দোকান থেকে হিসাব, এসএমএস ও অফলাইন সিঙ্ক</div>
</div>
{footer('মল ম্যানেজার — প্রোডাক্ট ব্রোশিওর · v1.0')}
''')

P2 = page(f'''
<div style="padding:14mm 16mm 0">
  <div style="font-weight:700;font-size:7mm;color:{TEAL_D}">মল ম্যানেজার কী?</div>
  <div style="width:16mm;height:1mm;background:{AMBER};border-radius:1mm;margin-top:2mm"></div>
  <div style="margin-top:5mm;font-size:3.8mm;color:{SLATE};line-height:1.8">
    মল ম্যানেজার একটি সম্পূর্ণ <b>শপিং মল / কমার্শিয়াল কমপ্লেক্স ম্যানেজমেন্ট সফটওয়্যার</b> — দোকান ও মালিক ব্যবস্থাপনা থেকে মাসিক বিলিং, আদায়, হিসাব, এসএমএস ও অফলাইন সিঙ্ক — সবকিছু এক জায়গায়।
  </div>
  <div style="margin-top:5mm;display:grid;grid-template-columns:1fr 1fr;gap:4mm">
    {' '.join(f'<div style="background:{BG};border:0.4mm solid {BD};border-left:1.6mm solid {AMBER};border-radius:2mm;padding:3.5mm 4mm"><div style="font-weight:700;font-size:3.8mm;color:{TEAL_D}">{E(t)}</div><div style="font-size:3.2mm;color:{MUT};margin-top:1.5mm;line-height:1.6">{E(d)}</div></div>' for t, d in [
      ('সব-ইন-ওয়ান', 'দোকান, বিল, আদায়, হিসাব, সম্পদ — একটি ড্যাশবোর্ডে'),
      ('বাংলা ইন্টারফেস', 'পুরো অ্যাপ বাংলায় — মালিক থেকে কালেক্টর সবার জন্য সহজ'),
      ('রিয়েল-টাইম রিপোর্ট', 'আজকের আদায়, মোট বকেয়া, লাভ-ক্ষতি — এক ক্লিকে'),
      ('অফলাইনেও চলে', 'ইন্টারনেট ছাড়াই আদায় — ফিরলেই অটো-সিঙ্ক'),
      ('বিল-প্যাড প্রিন্ট', 'কাগজের বিল-প্যাডের মতো প্রিন্ট, PDF ডাউনলোড'),
      ('এসএমএস এলার্ট', 'বকেয়া রিমাইন্ডার, নোটিশ — মালিকের ফোনে সরাসরি'),
      ('রোল-ভিত্তিক নিরাপত্তা', '৫টি ভূমিকা, অনুমোদন ফ্লো, অডিট লগ'),
      ('ডেডিকেটেড সার্ভার', 'আপনার সার্ভারে (ক্লাউড/লোকাল) সম্পূর্ণ স্থাপনা'),
    ])}
  </div>
</div>
{footer()}
''')

def module_card(m):
    ico, t, d = m
    return f'<div style="background:{BG};border:0.4mm solid {BD};border-top:1.2mm solid {TEAL};border-radius:2.5mm;padding:4mm;height:38mm"><div style="font-size:6mm">{ico}</div><div style="font-weight:700;font-size:4.2mm;color:{TEAL_D};margin-top:2mm">{E(t)}</div><div style="font-size:3mm;color:{MUT};margin-top:1.5mm;line-height:1.55">{E(d)}</div></div>'

mod_pages = []
for i in range(0, 12, 4):
    cards = ''.join(module_card(m) for m in MODULES[i:i+4])
    mod_pages.append(page(f'''
<div style="padding:14mm 16mm 0">
  <div style="display:flex;align-items:center;justify-content:space-between">
    <div><div style="font-weight:700;font-size:7mm;color:{TEAL_D}">১২টি মডিউল</div>
    <div style="width:16mm;height:1mm;background:{AMBER};border-radius:1mm;margin-top:2mm"></div></div>
    <div style="font-size:3.2mm;color:{MUT};font-weight:700">{i//4 + 1} / ৩</div>
  </div>
  <div style="margin-top:6mm;display:grid;grid-template-columns:1fr 1fr;gap:4mm">{cards}</div>
</div>
{footer()}
'''))

shots_pg = page(f'''
<div style="padding:14mm 16mm 0">
  <div style="font-weight:700;font-size:7mm;color:{TEAL_D}">প্রোডাক্ট ইন অ্যাকশন</div>
  <div style="width:16mm;height:1mm;background:{AMBER};border-radius:1mm;margin-top:2mm"></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:4mm;margin-top:6mm">
    <div style="background:#fff;border:0.4mm solid {BD};border-radius:2.5mm;padding:2.5mm;box-shadow:0 1mm 3mm rgba(15,118,110,.12)">{img('01_dashboard.jpg', 84, 52)}<div style="font-size:2.9mm;color:{MUT};text-align:center;margin-top:2mm">ড্যাশবোর্ড — আদায়, বকেয়া, এলার্ট</div></div>
    <div style="background:#fff;border:0.4mm solid {BD};border-radius:2.5mm;padding:2.5mm;box-shadow:0 1mm 3mm rgba(15,118,110,.12)">{img('02_meters_panel.jpg', 84, 52)}<div style="font-size:2.9mm;color:{MUT};text-align:center;margin-top:2mm">মিটার রিডিং — দোকানের তথ্য প্যানেল</div></div>
    <div style="background:#fff;border:0.4mm solid {BD};border-radius:2.5mm;padding:2.5mm;box-shadow:0 1mm 3mm rgba(15,118,110,.12)">{img('04_collect_pay.jpg', 84, 52)}<div style="font-size:2.9mm;color:{MUT};text-align:center;margin-top:2mm">আদায় — পেমেন্ট ফর্ম ও রসিদ</div></div>
    <div style="background:#fff;border:0.4mm solid {BD};border-radius:2.5mm;padding:2.5mm;box-shadow:0 1mm 3mm rgba(15,118,110,.12)">{img('06_coa.jpg', 84, 52)}<div style="font-size:2.9mm;color:{MUT};text-align:center;margin-top:2mm">চার্ট অব অ্যাকাউন্টস — মাল্টি-লেভেল</div></div>
  </div>
  <div style="margin-top:6mm;display:grid;grid-template-columns:1fr 1fr;gap:4mm">
    <div style="background:#fff;border:0.4mm solid {BD};border-radius:2.5mm;padding:2.5mm;box-shadow:0 1mm 3mm rgba(15,118,110,.12)">{img('05_payment_history.jpg', 84, 52)}<div style="font-size:2.9mm;color:{MUT};text-align:center;margin-top:2mm">পেমেন্ট ইতিহাস — দোকান অনুযায়ী</div></div>
    <div style="background:#fff;border:0.4mm solid {BD};border-radius:2.5mm;padding:2.5mm;box-shadow:0 1mm 3mm rgba(15,118,110,.12)">{img('07_invoice_preview.jpg', 84, 52)}<div style="font-size:2.9mm;color:{MUT};text-align:center;margin-top:2mm">ইনভয়েস — বিল-প্যাড প্রিভিউ</div></div>
  </div>
</div>
{footer()}
''')

PRICING = [
    ('অন-প্রিমাইস (এককালীন)', '৳৫,০০,০০০', 'একবার কিনুন, চিরকাল ব্যবহার করুন', ['পুরো সিস্টেম আপনার সার্ভারে (ক্লাউড/লোকাল)', '৩ মাস ফ্রি মৌলিক বাগ-ফিক্সিং', 'সম্পূর্ণ ডেটা আপনার নিয়ন্ত্রণে'], 'teal'),
    ('বছরভিত্তিক সাবস্ক্রিপশন', '৳১,০০,০০০/বছর', 'অথবা ৳১০,০০০/মাস', ['আমাদের ক্লাউড সার্ভারে সম্পূর্ণ আলাদা সিস্টেম', 'আপডেট ও সাপোর্টসহ', 'চুক্তিতে ৳২০,০০০ ইমপ্লিমেন্টেশন ও সার্ভার সেটআপ ফি'], 'amber'),
    ('SaaS — ব্যবহারকারীভিত্তিক', '৳১০০/ব্যবহারকারী·দোকান/মাস', 'অথবা ৳৫,০০০/মাস (যা বেশি)', ['প্রতি ব্যবহারকারী/দোকান প্রতি মাসে', 'ন্যূনতম ৳৫,০০০/মাস', 'চুক্তিতে ৳২০,০০০ ইমপ্লিমেন্টেশন ও সার্ভার সেটআপ ফি'], 'teal'),
]
pr = ''.join(f'''<div style="flex:1;background:#fff;border:0.5mm solid {'#F59E0B' if k == 'amber' else TEAL};border-top:1.6mm solid {'#F59E0B' if k == 'amber' else TEAL};border-radius:3mm;padding:5mm 4.5mm;box-shadow:0 1mm 3mm rgba(15,118,110,.1)">
  <div style="font-weight:700;font-size:3.6mm;color:{MUT}">{E(t)}</div>
  <div style="font-weight:700;font-size:6.5mm;color:{TEAL_D};margin-top:2mm">{E(p)}</div>
  <div style="font-size:3mm;color:{AMBER_D};font-weight:700;margin-top:1mm">{E(s)}</div>
  <div style="margin-top:3mm;border-top:0.3mm dashed {BD};padding-top:2.5mm">
    {' '.join(f'<div style="font-size:2.9mm;color:{SLATE};margin-top:1.6mm;line-height:1.5">✔ {E(b)}</div>' for b in bs)}
  </div>
</div>''' for t, p, s, bs, k in PRICING)

P_pr = page(f'''
<div style="padding:14mm 16mm 0">
  <div style="font-weight:700;font-size:7mm;color:{TEAL_D}">প্রাইসিং</div>
  <div style="width:16mm;height:1mm;background:{AMBER};border-radius:1mm;margin-top:2mm"></div>
  <div style="display:flex;gap:4mm;margin-top:7mm">{pr}</div>
  <div style="margin-top:7mm;background:{BG};border:0.4mm solid {BD};border-radius:2.5mm;padding:4.5mm;font-size:3.2mm;color:{SLATE};line-height:1.7">
    📌 <b>প্রতিটি প্যাকেজে:</b> বাংলা + ইংরেজি ইন্টারফেস · বিল-প্যাড প্রিন্ট · এসএমএস · অফলাইন সিঙ্ক · ডেডিকেটেড সাপোর্ট<br/>
    🎁 <b>লাইভ ডেমো:</b> appvaley.com/mall · ওয়েবসাইট: mall.krtaker.com
  </div>
</div>
{footer()}
''')

CONTACT = [
    ('📞 যোগাযোগ', 'বিটিএসসিওএল — বেলাল আহমেদ\nbelal000bd@gmail.com · +88017*****769'),
    ('🌐 ওয়েবসাইট', 'mall.krtaker.com — পণ্যের বিস্তারিত, দাম ও ব্রোশিওর\nappvaley.com — মার্কেটিং পার্টনার'),
    ('💬 হোয়াটসঅ্যাপ', 'ডেমো ও কোটেশনের জন্য সরাসরি হোয়াটসঅ্যাপে মেসেজ করুন\nইনবক্সে নাম + মোবাইল পাঠালেই ফিরে কল'),
    ('📚 উইকি ও সাহায্য', 'অ্যাপের ভেতরে সম্পূর্ণ বাংলা + ইংরেজি উইকি\n৪১টি নিবন্ধ — ধাপে ধাপে, স্ক্রিনশটসহ'),
]
P_last = page(f'''
<div style="height:60mm;background:linear-gradient(165deg,{TEAL} 0%,{TEAL_D} 100%);padding:12mm 16mm;position:relative;overflow:hidden">
  <div style="position:absolute;right:-14mm;top:-16mm;width:56mm;height:56mm;border-radius:50%;background:rgba(255,255,255,.08)"></div>
  <div style="position:relative;text-align:center;color:#fff">
    <div style="font-weight:700;font-size:7mm">ডেমো চাইলে আজই শুরু করুন</div>
    <div style="font-size:3.6mm;margin-top:2.5mm;color:rgba(255,255,255,.9)">লাইভ ডেমো: <b>appvaley.com/mall</b> · প্রোডাক্ট পেজ: <b>mall.krtaker.com</b></div>
    <div style="width:30mm;height:1mm;background:{AMBER};border-radius:1mm;margin:5mm auto 0"></div>
  </div>
</div>
<div style="padding:9mm 16mm 0">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:4mm">
    {' '.join(f'<div style="background:{BG};border:0.4mm solid {BD};border-radius:2.5mm;padding:4mm"><div style="font-weight:700;font-size:3.6mm;color:{TEAL_D}">{E(t)}</div><div style="font-size:3.2mm;color:{SLATE};margin-top:2mm;line-height:1.65;white-space:pre-line">{E(d)}</div></div>' for t, d in CONTACT)}
  </div>
  <div style="margin-top:6mm;text-align:center;font-size:3mm;color:{MUT};line-height:1.8">
    মল ম্যানেজার v2.2 · মল ও কমার্শিয়াল এডিশন<br/>
    © ২০২৬ কেআরটেকার (বিটিএসসিওএলের একটি প্রতিষ্ঠান) — মার্কেটিং: অ্যাপভ্যালি
  </div>
</div>
{footer()}
''')

HTML = f'''<!DOCTYPE html><html lang="bn"><head><meta charset="utf-8"/>
<title>মল ম্যানেজার — প্রোডাক্ট ব্রোশিওর</title>
<style>
  @page {{ size: A4; margin: 0 }}
  * {{ box-sizing: border-box; margin: 0; padding: 0 }}
  body {{ font-family: 'Hind Siliguri','Noto Sans Bengali','SolaimanLipi',sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact }}
  .page {{ break-inside: avoid }}
</style></head>
<body>{P1}{P2}{''.join(mod_pages)}{shots_pg}{P_pr}{P_last}</body></html>'''

open('/tmp/mall_brochure_bn.html', 'w').write(HTML)
print('HTML written:', len(HTML)//1024, 'KB, pages:', 1 + 1 + 3 + 1 + 1 + 1)
