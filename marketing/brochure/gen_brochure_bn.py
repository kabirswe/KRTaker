#!/usr/bin/env python3
# Mall Manager — BILINGUAL (Bengali + English) product brochure, browser-rendered PDF
import html, base64, os

IMG = '/tmp/wiki_img'
def b64(p):
    with open(p, 'rb') as f:
        return base64.b64encode(f.read()).decode()
def img(p, w, h=None):
    d = b64(os.path.join(IMG, p))
    ext = 'png' if p.endswith('png') else 'jpeg'
    st = f'width:{w}mm' + (f';height:{h}mm;object-fit:cover' if h else '')
    return f'<img src="data:image/{ext};base64,{d}" style="{st};border-radius:1.8mm;display:block" />'
E = html.escape

TEAL='#0F766E'; TEAL_D='#115E59'; TEAL_L='#CCFBF1'; AMBER='#F59E0B'; AMBER_D='#B45309'
SLATE='#334155'; MUT='#64748B'; BG='#F1F5F9'; BD='#E2E8F0'; PAPER='#FCFDFF'

def page(inner):
    return f'<div class="page" style="width:210mm;height:297mm;background:{PAPER};position:relative;overflow:hidden;page-break-after:always">{inner}</div>'

def footer():
    return f'''<div style="position:absolute;left:14mm;right:14mm;bottom:7mm;display:flex;justify-content:space-between;border-top:0.5mm solid {TEAL};padding-top:2.2mm;font-size:2.8mm;color:{MUT}">
      <div>Mall Manager — Brochure · ব্রোশিওর · v1.0</div><div style="color:{TEAL};font-weight:700">প্রস্তুতকারক: KRTaker (BITSCOL এর একটি প্রতিষ্ঠান) · মার্কেটিং: Appvaley</div></div>'''

def h(n, bn, en, sub=None):
    s = f'''<div style="display:flex;align-items:center;gap:3mm"><span style="width:7mm;height:7mm;border-radius:3.5mm;background:{AMBER};color:#fff;font-weight:700;font-size:3.6mm;display:flex;align-items:center;justify-content:center">{n}</span>
      <div><div style="font-weight:700;font-size:6mm;color:{TEAL_D}">{E(bn)}</div><div style="font-size:3mm;color:{MUT};font-weight:700">{E(en)}</div></div></div>
      <div style="width:20mm;height:1mm;background:{AMBER};border-radius:1mm;margin-top:2mm"></div>'''
    if sub: s += f'<div style="font-size:3.2mm;color:{MUT};margin-top:2mm">{E(sub)}</div>'
    return s

def bl(bn, en):
    return f'''<div style="display:flex;gap:1.8mm;margin-top:1.8mm"><span style="color:{TEAL};font-weight:700">•</span>
      <div><div style="font-size:3.2mm;color:{SLATE};line-height:1.5"><b>{E(bn)}</b></div>
      <div style="font-size:2.8mm;color:{MUT};line-height:1.45">{E(en)}</div></div></div>'''

# ── 13 modules (bilingual) ──
MODULES = [
 ('দোকান ও মালিকানা ব্যবস্থাপনা', 'Space & Ownership Management', [
  ('দোকান: ফ্লোর, আয়তন (বর্গফুট), বিলিং মডেল ও সার্ভিস রেট', 'Spaces with floor, size (sqft), billing model and service rate'),
  ('নমনীয় মালিকানা — ব্যক্তি/কোম্পানি/ব্যাংক/ট্রাস্ট; এক মালিকের একাধিক দোকান', 'Flexible ownership — persons, companies, banks, trusts; multi-space portfolio'),
  ('অকুপেন্সি: Active / Vacant / Closed + KPI', 'Occupancy tracking: Active / Vacant / Closed with KPIs'),
  ('ভাড়াটিয়া প্রোফাইল + ভাড়া চুক্তি (ঐচ্ছিক ভাড়া আদায়)', 'Tenant profiles + rental agreements (optional rent collection)'),
  ('দোকান নং / মালিক / মোবাইল দিয়ে সার্চ', 'Search by shop no / owner / mobile'),
 ]),
 ('সার্ভিস-চার্জ বিলিং ইঞ্জিন', 'Service-Charge Billing Engine', [
  ('৪টি বিলিং মডেল: Fixed, Per sqft, + ইউটিলিটি', 'Four billing models: Fixed (flat), Per sqft, Fixed + utilities, Per sqft + utilities'),
  ('এক-ক্লিকে মাসিক বিল + নির্ধারিত তারিখ + সিকোয়েন্স নিয়ন্ত্রণ', 'One-click monthly bill generation with due dates; manual sequence control'),
  ('দেরি-ফি ইঞ্জিন: %, গ্রেস দিন, সর্বনিম্ন ফি, ক্যাপ', 'Late-fee engine: %, grace days, minimum fine, cap — configurable'),
  ('বিল-প্যাড প্রিন্ট — বিদ্যুৎ/সার্ভিস চার্জ এবং অন্যান্য বিল — ২টি স্বাক্ষরসহ', 'Printed bills replicate the traditional Bengali bill pad with two committee signatures'),
  ('টেমপ্লেট: A4, A5, ½+½ A4 (পোর্ট্রেট/ল্যান্ডস্কেপ) + PDF', 'Print templates: A4, A5, ½+½ A4 two-per-sheet + PDF download'),
 ]),
 ('সাব-মিটার বিদ্যুৎ ও পানি', 'Sub-Meter Electricity & Water Billing', [
  ('মিটার ফটোসহ মাসিক রিডিং (ফটো বাধ্যতামূলক)', 'Monthly sub-meter readings with photo capture (mandatory — spec)'),
  ('অটো ইউনিট হিসাব + কনফিগারযোগ্য ৳/ইউনিট রেটে বিল', 'Automatic units calculation and bills at configurable ৳/unit rates'),
  ('২০০% অস্বাভাবিকতা ফ্ল্যাগ — মিটার ত্রুটি/চুরি শনাক্ত', '200% usage anomaly flag for meter error / theft detection'),
  ('কার্যকর রেট ক্যালকুলেটর: DESCO বিল ÷ মোট ইউনিট (+২/৫/১০%)', 'Effective rate calculator: DESCO bill ÷ total units → suggested rates'),
  ('ইউটিলিটি = নিজস্ব আয়: আদায় − DESCO/WASA বিল = লাভ/ক্ষতি', 'Utility own-income model: collections − bills = monthly profit/loss'),
 ]),
 ('আদায়, রসিদ ও পেমেন্ট', 'Collections, Receipts & Payments', [
  ('বিল অনুযায়ী আদায় (সম্পূর্ণ/আংশিক) + অটো রসিদ RCT-YYYYMM-####', 'Collect against bills (full/partial) with instant auto receipts'),
  ('ক্যাশ/ব্যাংক/বিকাশ/নগদ + একাধিক ব্যাংক (ব্র্যাক, ইবিএল, বিকাশ বিজনেস, নগদ)', 'Cash, bank, bKash, Nagad with MULTIPLE bank accounts'),
  ('রসিদ প্রিন্ট: ব্যাংক/এ/সি, চার্জ/ফাইন লাইন, কমিটির স্বাক্ষর', 'Money receipt print with bank details, charge/fine lines, signatures'),
  ('দুই-স্তরের মওকুফ/ভয়েড অনুমোদন — রসিদ লক', 'Two-level waiver & void approval workflow, receipt locked'),
  ('ভাড়া (RNT-) ও সরবরাহকারী (VNP-) পেমেন্ট', 'Rent (RNT-) and vendor (VNP-) payments with method/account precision'),
 ]),
 ('হিসাব — সম্পূর্ণ ডাবল-এন্ট্রি', 'Accounting — Complete Double-Entry Books', [
  ('মাল্টি-লেভেল চার্ট অব অ্যাকাউন্টস (গ্রুপ + সাব-লেজার)', 'Multi-level Chart of Accounts (groups + sub-ledgers)'),
  ('জার্নাল ভাউচার — শুধু লিফ অ্যাকাউন্টে পোস্টিং', 'Journal vouchers with double-entry posting; leaf accounts only'),
  ('ট্রায়াল ব্যালেন্স, P&L, পার্টি লেজার, ক্যাশফ্লো', 'Trial Balance, P&L, Party Ledger, Cashflow per account'),
  ('ব্যাংক স্টেটমেন্ট ইমপোর্ট (CSV) + অটো-ম্যাচ → রিকনসিলিয়েশন', 'Bank statement import (CSV) with automatic matching → verdicts'),
  ('সব রিপোর্ট প্রিন্ট/CSV', 'All reports printable / CSV'),
 ]),
 ('কমিটির ফাইন্যান্সিয়াল ভিউ', 'Financial Views for the Committee', [
  ('ড্যাশবোর্ড: আদায় vs বকেয়া, ব্যয়, শীর্ষ খেলাপি, ব্যালেন্স', 'Dashboard: collected vs outstanding, expenses, defaulters, balances'),
  ('ইনভয়েস ভিউ: প্রতি দোকানের কম্বাইন্ড ইনভয়েস (আইটেমাইজড)', 'Invoices view: combined per-space invoices, itemized, print & PDF'),
  ('পেমেন্ট ভিউ: রসিদ, পদ্ধতি, নির্দিষ্ট অ্যাকাউন্ট, স্ট্যাটাস', 'Payments view: every receipt with payer, method, account, status'),
  ('অ্যানালিটিক্স + যেকোনো পার্টির পিরিয়ড স্টেটমেন্ট', 'Analytics: billed vs collected, expense mix, occupancy, defaulters'),
 ]),
 ('এলার্ট, এএমসি ও ঝুঁকি', 'Alerts, AMC & Risk Management', [
  ('উচ্চ বকেয়া অটো এলার্ট (কনফিগারযোগ্য মাস, যেমন ২)', 'High-dues auto alerts after configurable months'),
  ('সংযোগ বিচ্ছিন্ন ঝুঁকি (৩+ মাস) + এক-ট্যাপ এসএমএস সতর্কতা', 'Disconnection-risk detection with one-tap SMS warning'),
  ('লিফট/জেনারেটর/এসকেলেটর/ফায়ার — এএমসি মেয়াদ ৩০ দিনের রিমাইন্ডার', 'AMC expiry tracking for lifts, generators, escalators, fire equipment'),
  ('সব খেলাপিকে রিমাইন্ড এসএমএস ব্লাস্ট', 'Per-space due overview and Remind-all-defaulters SMS blast'),
 ]),
 ('এসএমএস ও যোগাযোগ', 'SMS & Communication', [
  ('প্রতিটি আদায়ে ইনস্ট্যান্ট এসএমএস রসিদ (বাংলায়)', 'Instant SMS receipt on every collection (Bangla)'),
  ('বকেয়া রিমাইন্ডার, বিচ্ছিন্ন সতর্কতা, নোটিশ ব্রডকাস্ট', 'Dues reminders, disconnection warnings, notice broadcasts'),
  ('রিসিপিয়েন্ট নিয়ন্ত্রণ: মালিক / ভাড়াটিয়া / উভয়', 'Recipient control: owner, tenant, or both'),
  ('bulksmsbd গেটওয়ে রেডি (টেস্ট মোডসহ)', 'Provider-ready: bulksmsbd gateway (test mode available)'),
 ]),
 ('সরবরাহকারী, ব্যয় ও সম্পদ', 'Vendors, Expenses & Assets', [
  ('সরবরাহকারী ডিরেক্টরি + ক্যাটাগরি + পেমেন্ট ইতিহাস (VNP-)', 'Vendor directory with categories, contracts and payment history'),
  ('ভাউচার ফটোসহ ব্যয় — অটো লেজারে পোস্ট', 'Expense entries with voucher photo upload, auto-posted'),
  ('সম্পদ রেজিস্টার: ইনস্টল তারিখ, মূল্য, ওয়ারেন্টি/এএমসি', 'Assets register: install date, cost, warranty/AMC until'),
  ('স্টাফ/সিকিউরিটি গার্ড বেতন + পেঅ্যরোল ভিউ', 'Salary payments with payroll view'),
 ]),
 ('কমপ্লেইন্ট ও রক্ষণাবেক্ষণ', 'Complaints & Maintenance', [
  ('লিফট/এসি/লাইট/পানি… — প্রায়োরিটি টিকেট ফ্লো', 'Log complaints (lift / AC / light / water…) with priority'),
  ('স্ট্যাটাস: Open → In Progress → Resolved (সব লগড)', 'Status workflow with every change logged'),
  ('দোকানের ইতিহাস: বিল, রিডিং, পেমেন্ট, কমপ্লেইন্ট', 'Space history: bills, readings, payments, complaints per shop'),
 ]),
 ('গভর্নেন্স ও কমিটি', 'Governance & Committee', [
  ('কমিটি সদস্য ও পদধারী (সভাপতি · সম্পাদক · কোষাধ্যক্ষ)', 'Committee members & office bearers'),
  ('মিটিং রেজিস্টার — এজেন্ডা, সিদ্ধান্ত, মিনিটস', 'Meeting register with agenda, decisions and minutes'),
  ('রেজোলিউশন আর্কাইভ + নোটিশ (পিন-টু-টপ)', 'Resolutions archived; notices pinned for announcements'),
 ]),
 ('নিরাপত্তা, ভূমিকা ও অডিট', 'Security, Roles & Audit', [
  ('৫টি ভূমিকা: সুপার অ্যাডমিন · মালিক · ম্যানেজার · হিসাবরক্ষক · কালেক্টর', 'Roles: Super Admin · Owner · Manager · Accountant · Collector'),
  ('মওকুফ/ভয়েডে দুই-স্তরের অনুমোদন', 'Two-level approval for waivers and payment voids'),
  ('সম্পূর্ণ অডিট লগ + লাইসেন্স কী সিস্টেম', 'Full audit log; vendor-controlled license keys'),
 ]),
 ('মালিক পোর্টাল ও অফলাইন', 'Owner Portal & Offline', [
  ('মালিক পোর্টাল: নিজের বিল, বকেয়া, পেমেন্ট, নোটিশ; কমপ্লেইন্ট', 'Owner portal: view own bills, dues, payments, notices; file complaints'),
  ('অফলাইন + অটো-সিঙ্ক: কিউড রাইট রিপ্লে, অ্যাপ-শেল ক্যাশ', 'Offline + auto-sync: queued writes replay; app-shell cache'),
  ('মোবাইল, ট্যাবলেট ও ডেস্কটপ — রেসপন্সিভ UI', 'Works on mobile, tablet and desktop — responsive'),
 ]),
]

WHY = [
 ('আরও আদায়', 'Collect MORE', 'অটো বিলিং + বকেয়া এলার্ট + এসএমএস রিমাইন্ডার + বিচ্ছিন্ন সতর্কতা — সময়মতো আদায় নাটকীয়ভাবে বাড়ে', 'automatic billing + overdue alerts + SMS reminders + disconnection warnings raise on-time collection dramatically'),
 ('লেজার ঝামেলা শেষ', 'Stop the ledger mess', 'প্রতি ৳ অটো ডাবল-এন্ট্রি চার্ট অব অ্যাকাউন্টসে পোস্ট — ট্রায়াল ব্যালেন্স সবসময় ব্যালেন্সড', 'every ৳ auto-posts to a real double-entry chart of accounts — trial balance always balanced'),
 ('মালিকরা বিশ্বাস করে', 'Owners trust it', 'প্রিন্টেড বিল/রসিদ ঐতিহ্যবাহী কমিটি বিল-প্যাডের মতো — পরিচিত, আনুষ্ঠানিক, স্বাক্ষরসহ', 'printed bills and receipts look like the traditional committee pad — familiar, formal, signed'),
 ('বাংলা-প্রথম', 'Bangla-first', 'পুরো স্টাফ বাংলায় ব্যবহার করতে পারে; ইংরেজি মোডও আছে', 'the entire staff can use it in বাংলা; English mode for anyone else'),
 ('সস্তায় চলে', 'Cheap to run', 'সিঙ্গেল-ফাইল PHP + SQLite — সাধারণ শেয়ার্ড হোস্টিংয়ে (cPanel), কোনো ডেডিকেটেড সার্ভার লাগে না', 'single-file PHP + SQLite on ordinary shared hosting — no VPS, no database license'),
 ('বাংলাদেশের জন্য তৈরি', 'Built for Bangladesh', 'বিকাশ/নগদ/ব্যাংক/ক্যাশ, DESCO/WASA, BDT ৳, মার্কেট-সোসাইটি গভর্নেন্স', 'bKash/Nagad/bank/cash, DESCO/WASA, BDT ৳, market-society governance'),
 ('অফলাইন-নিরাপদ', 'Offline-safe', 'ইন্টারনেট বিচ্ছিন্ন হলেও মিটার রিডিং ও আদায় হারায় না', 'meter readings and collections never get lost during internet outages'),
]

TECH = [
 ('স্থাপত্য', 'Architecture', 'Vue 3 SPA + সিঙ্গেল-ফাইল PHP 8 + SQLite API', 'Vue 3 single-page app + single-file PHP 8 + SQLite API'),
 ('হোস্টিং', 'Hosting', 'স্ট্যান্ডার্ড শেয়ার্ড হোস্টিং (cPanel/Apache/LiteSpeed) — VPS লাগে না', 'Standard shared hosting (cPanel) — no VPS required'),
 ('ডিভাইস', 'Devices', 'মোবাইল, ট্যাবলেট, ডেস্কটপ — রেসপন্সিভ UI', 'Mobile, tablet, desktop — responsive UI'),
 ('প্রিন্টিং', 'Printing', 'ব্রাউজার প্রিন্ট: A4/A5/½+½ A4 + PDF এক্সপোর্ট', 'Browser print with A4/A5/½+½ A4 templates + PDF export'),
 ('এসএমএস', 'SMS', 'bulksmsbd গেটওয়ে (বাংলা টেমপ্লেট)', 'bulksmsbd gateway integration (Bangla templates)'),
 ('অফলাইন', 'Offline', 'সার্ভিস-ওয়ার্কার শেল + লোকালস্টোরেজ রাইট কিউ + অটো-সিঙ্ক', 'Service-worker app shell + localStorage write queue with auto-sync'),
 ('ভাষা', 'Language', 'বাংলা (ডিফল্ট) / English', 'বাংলা (default) / English'),
 ('নিরাপত্তা', 'Security', 'Bearer টোকেন, রোল-ভিত্তিক অ্যাক্সেস, অনুমোদন ফ্লো, অডিট লগ, লাইসেন্স কী', 'Bearer tokens, role-based access, approval workflows, audit log, license keys'),
]

PRICING = [
 ('অন-প্রিমাইস (এককালীন)', 'On-premise (one-time)', '৳৫,০০,০০০', '৳5,00,000', 'একবার কিনুন — আপনার সার্ভারে সম্পূর্ণ স্থাপনা', 'Full deployment on your server (cloud/local)', ['৩ মাস ফ্রি মৌলিক বাগ-ফিক্সিং', '3 months free basic bug-fixing', 'সম্পূর্ণ ডেটা আপনার নিয়ন্ত্রণে', 'Full data control'], 'teal'),
 ('বছরভিত্তিক সাবস্ক্রিপশন', 'Yearly subscription', '৳১,০০,০০০/বছর', '৳1,00,000/yr', 'অথবা ৳১০,০০০/মাস', 'or ৳10,000/mo', ['আমাদের ক্লাউডে সম্পূর্ণ আলাদা সিস্টেম', 'Fully standalone on our cloud', 'আপডেট ও সাপোর্টসহ', 'Updates & support included', 'চুক্তিতে ৳২০,০০০ সেটআপ ফি', '৳20,000 setup at agreement'], 'amber'),
 ('SaaS — ব্যবহারকারীভিত্তিক', 'SaaS / user-based', '৳১০০/ব্যবহারকারী·দোকান/মাস', '৳100/user/shop/mo', 'অথবা ৳৫,০০০/মাস (যা বেশি)', 'or ৳5,000/mo (whichever is higher)', ['প্রতি ব্যবহারকারী/দোকান প্রতি মাসে', 'Per user per shop per month', 'ন্যূনতম ৳৫,০০০/মাস', 'Minimum ৳5,000/mo', 'চুক্তিতে ৳২০,০০০ সেটআপ ফি', '৳20,000 setup at agreement'], 'amber'),
]

LICENSE = [
 ('নমনীয় লাইসেন্সিং', 'Flexible licensing', 'এককালীন ক্রয়, বার্ষিক সাবস্ক্রিপশন, বা প্রতি-ব্যবহারকারী মাসিক', 'one-off purchase, yearly subscription, or per-user monthly'),
 ('ভেন্ডর-নিয়ন্ত্রিত সুপার অ্যাডমিন', 'Vendor-controlled Super Admin', 'ডেডিকেটেড কন্ট্রোল প্যানেল থেকে মল ডেটা, ব্যবহারকারী ও লাইসেন্স', 'manage mall data, users and license from a dedicated control panel'),
 ('গাইডেড অনবোর্ডিং', 'Onboarding', 'মল প্রোফাইল, বিলিং রুল, অ্যাকাউন্ট ম্যাপিং, রোল — তারপর দোকান ইমপোর্ট', 'guided setup — profile, billing rules, mapping, roles — then import shops'),
 ('ডেমো ডেটা', 'Demo data', 'লাইভ যাওয়ার আগে প্রতিটি ফিচার ট্রায়ালের জন্য ডেমো ডেটা', 'demo data included so the committee can try every feature first'),
]

SHOTS = [
 ('01_dashboard.jpg', 'ড্যাশবোর্ড — আদায়, বকেয়া, এলার্ট ও ব্যালেন্স', 'Dashboard — collections, dues, alerts & balances'),
 ('02_meters_panel.jpg', 'মিটার রিডিং — দোকানের তথ্য প্যানেল', 'Meters — space info panel (dues + history)'),
 ('04_collect_pay.jpg', 'আদায় — পেমেন্ট ফর্ম', 'Collect — advanced payment form'),
 ('05_payment_history.jpg', 'পেমেন্ট ইতিহাস — দোকান অনুযায়ী', 'Payment history — per space'),
 ('06_coa.jpg', 'চার্ট অব অ্যাকাউন্টস — মাল্টি-লেভেল', 'Chart of Accounts — multi-level tree'),
 ('07_invoice_preview.jpg', 'ইনভয়েস — বিল-প্যাড প্রিভিউ', 'Invoice — bill-pad preview'),
 ('08_journal.jpg', 'জার্নাল ভাউচার — ডাবল এন্ট্রি', 'Journal — double entry'),
 ('04_bill_form.jpg', 'বিল ফর্ম — সার্ভিস, বিদ্যুৎ ও পানি', 'Bill form — service, electricity & water'),
]

FONT_B64 = b64('/tmp/hind.ttf')
FONT_BOLD_B64 = b64('/tmp/hind-bold.ttf')

def module_card(m, num):
    bn, en, bullets = m
    return f'''<div style="background:#fff;border:0.4mm solid {BD};border-top:1.2mm solid {TEAL};border-radius:2.5mm;padding:3.6mm 4.2mm;height:57mm;overflow:hidden">
      <div style="display:flex;align-items:center;gap:2mm"><span style="width:5.5mm;height:5.5mm;border-radius:2.75mm;background:{AMBER};color:#fff;font-weight:700;font-size:3mm;display:flex;align-items:center;justify-content:center;flex-shrink:0">{num}</span>
      <div><div style="font-weight:700;font-size:3.9mm;color:{TEAL_D}">{E(bn)}</div><div style="font-size:2.7mm;color:{MUT};font-weight:700">{E(en)}</div></div></div>
      <div style="margin-top:1.6mm">{''.join(bl(b, e) for b, e in bullets)}</div></div>'''

PAGES = []
# ── 1 cover ──
PAGES.append(page(f'''
<div style="height:122mm;background:linear-gradient(165deg,{TEAL} 0%,{TEAL_D} 100%);padding:14mm 16mm;position:relative;overflow:hidden">
  <div style="position:absolute;right:-18mm;top:-20mm;width:70mm;height:70mm;border-radius:50%;background:rgba(255,255,255,.08)"></div>
  <div style="position:absolute;right:26mm;top:32mm;width:26mm;height:26mm;border-radius:50%;background:rgba(255,255,255,.07)"></div>
  <div style="position:absolute;left:-12mm;bottom:-16mm;width:50mm;height:50mm;border-radius:50%;background:rgba(255,255,255,.06)"></div>
  <div style="display:flex;flex-direction:column;align-items:center;text-align:center;position:relative">
    <div style="width:18mm;height:18mm;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:9mm">MM</div>
    <div style="color:#fff;font-weight:700;font-size:12.5mm;margin-top:6mm">মল ম্যানেজার</div>
    <div style="color:#fff;font-size:4.4mm;margin-top:2mm;font-weight:700">Mall Manager — Commercial Building Management System (BMS)</div>
    <div style="color:rgba(255,255,255,.88);font-size:3.4mm;margin-top:2.5mm">Shopping Mall · Commercial Complex · Market Society</div>
    <div style="width:34mm;height:1.2mm;background:{AMBER};border-radius:1mm;margin-top:5mm"></div>
  </div>
</div>
<div style="padding:8mm 16mm 0">
  <div style="display:flex;gap:5mm;justify-content:center">
    {''.join(f'<div style="flex:1;max-width:52mm;background:{BG};border:0.4mm solid {BD};border-top:1.2mm solid {TEAL};border-radius:2mm;padding:3mm 4mm"><div style="font-size:2.7mm;color:{MUT};font-weight:700">{E(l1)}</div><div style="font-size:4.4mm;color:{TEAL};font-weight:700;margin-top:1.5mm">{E(v)}</div></div>' for l1, l2, v in [('ব্রোশিওর ভার্সন','Version','v1.0'), ('অ্যাপ ভার্সন','App','v2.2'), ('ভাষা','Language','বাংলা + English')])}
  </div>
  <div style="text-align:center;margin-top:7mm">
    <div style="display:inline-block;padding:2mm;background:#fff;border-radius:2.5mm;box-shadow:0 1mm 3mm rgba(15,118,110,.18);border:0.4mm solid {BD}">{img('05_module_map.png', 118)}</div>
    <div style="font-size:3mm;color:{MUT};margin-top:3mm">১৩টি মডিউল · Thirteen modules — মিটার রিডিং থেকে বার্ষিক সভা পর্যন্ত, একটি সিস্টেমে</div>
  </div>
  <div style="margin-top:6mm;text-align:center;font-size:3mm;color:{MUT}">মল ও মার্কেট মালিক সমিতি / কমিটি · মল ডেভেলপার ও ম্যানেজিং এজেন্ট · কমার্শিয়াল কমপ্লেক্স প্রপার্টি ম্যানেজারদের জন্য</div>
</div>
{footer()}
'''))

# ── 2 overview ──
PAGES.append(page(f'''
<div style="padding:14mm 16mm 0">{h(1, 'প্রোডাক্ট ওভারভিউ', 'Product Overview')}
  <div style="margin-top:5mm;background:#fff;border:0.4mm solid {BD};border-left:1.6mm solid {AMBER};border-radius:2.5mm;padding:4.5mm;font-size:3.4mm;color:{SLATE};line-height:1.8">
    <b>{E('মল ম্যানেজার একটি সম্পূর্ণ কমার্শিয়াল বিল্ডিং ম্যানেজমেন্ট সিস্টেম (BMS)')}</b> — {E('শপিং মল, কমার্শিয়াল কমপ্লেক্স ও মার্কেট সোসাইটির জন্য তৈরি। হাতের লেখা বিল-প্যাড, কাগজের লেজার ও স্প্রেডশিটের বদলে একটি সমন্বিত সিস্টেম — মিটার রিডিং থেকে বার্ষিক সাধারণ সভা পর্যন্ত সবকিছু — এবং প্রিন্টেড বিল দেখতে ঐতিহ্যবাহী কমিটি বিল-প্যাডের মতোই।')}
    <div style="margin-top:2.5mm;font-size:3.1mm;color:{MUT}">{E('Mall Manager is a complete Commercial Building Management System (BMS) for shopping malls, commercial complexes and market societies — replacing handwritten bill pads, paper ledgers and spreadsheets with one integrated system, while printing bills that look exactly like the traditional committee pad.')}</div>
  </div>
  <div style="margin-top:4mm;display:grid;grid-template-columns:1fr 1fr 1fr;gap:3.5mm">
    {''.join(f'<div style="background:{BG};border:0.4mm solid {BD};border-radius:2.5mm;padding:3.5mm"><div style="font-weight:700;font-size:3.3mm;color:{TEAL_D}">{E(t)}</div><div style="font-size:3mm;color:{MUT};font-weight:700;margin-top:.5mm">{E(t2)}</div><div style="font-size:2.9mm;color:{SLATE};margin-top:1.5mm;line-height:1.55">{E(b)}<div style="font-size:2.6mm;color:{MUT};margin-top:.8mm">{E(e)}</div></div></div>' for t, t2, b, e in [
      ('অটো মাসিক বিলিং', 'Automatic billing', 'উচ্চ আদায় হার, শূন্য ম্যানুয়াল লেজার', 'higher collection rate, zero manual ledger'),
      ('মাল্টি-স্পেস পোর্টফোলিও', 'Multi-space portfolio', 'নমনীয় মালিকানা, সম্পূর্ণ হিসাব স্বচ্ছতা', 'flexible ownership, full accounting visibility'),
      ('সাব-মিটার ইউটিলিটি', 'Sub-meter utilities', 'ভেন্ডর/এএমসি নিয়ন্ত্রণ, অডিট ট্রেইল', 'vendor/AMC control, audit trail'),
    ])}
  </div>
  <div style="margin-top:5mm">
    <div style="font-weight:700;font-size:4.2mm;color:{TEAL_D}">যাদের জন্য / For</div>
    <div style="margin-top:2mm;display:flex;gap:2.5mm;flex-wrap:wrap">
      {''.join(f'<span style="background:{TEAL_L};color:{TEAL_D};border-radius:5mm;padding:1.8mm 4mm;font-size:3mm;font-weight:700">{E(x)}</span>' for x in ['মল ও মার্কেট মালিক সমিতি / কমিটি', 'Mall & market owners’ societies', 'মল ডেভেলপার ও ম্যানেজিং এজেন্ট', 'Developers & managing agents', 'কমার্শিয়াল কমপ্লেক্স প্রপার্টি ম্যানেজার', 'Property managers'])}
    </div>
  </div>
  <div style="margin-top:5mm;display:grid;grid-template-columns:1fr 1fr;gap:4mm">
    <div style="background:#fff;border:0.4mm solid {BD};border-radius:2.5mm;padding:2.5mm">{img('01_dashboard.jpg', 84, 52)}<div style="font-size:2.9mm;color:{MUT};text-align:center;margin-top:1.5mm">ড্যাশবোর্ড / Dashboard</div></div>
    <div style="background:#fff;border:0.4mm solid {BD};border-radius:2.5mm;padding:2.5mm">{img('02_meters_panel.jpg', 84, 52)}<div style="font-size:2.9mm;color:{MUT};text-align:center;margin-top:1.5mm">মিটার ও দোকানের তথ্য / Meters & space info</div></div>
  </div>
</div>
{footer()}
'''))

# ── 3-6 modules (4 per page) ──
for i in range(0, 13, 4):
    chunk = MODULES[i:i+4]
    cards = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:3.5mm;margin-top:5mm">' + ''.join(module_card(m, i+j+1) for j, m in enumerate(chunk)) + '</div>'
    PAGES.append(page(f'''<div style="padding:14mm 16mm 0">{h(2 + i//4, 'ফিচার মডিউল', 'Feature Modules')}{cards}</div>{footer()}'''))

# ── 7 feature summary ──
rows = ''.join(f'<tr><td style="padding:1.8mm 2.5mm;font-weight:700;font-size:3mm;color:{TEAL_D};border-bottom:0.3mm solid {BD}">{E(m[0])}<div style="font-size:2.5mm;color:{MUT};font-weight:400">{E(m[1])}</div></td><td style="padding:1.8mm 2.5mm;font-size:2.8mm;color:{SLATE};border-bottom:0.3mm solid {BD}">{E(" · ".join(b for b, e in m[2][:3]))}</td></tr>' for m in MODULES)
PAGES.append(page(f'''
<div style="padding:14mm 16mm 0">{h(6, 'ফিচার সামারি', 'Feature Summary')}
  <div style="margin-top:5mm;background:#fff;border:0.4mm solid {BD};border-radius:2.5mm;overflow:hidden">
    <table style="width:100%;border-collapse:collapse">
      <tr style="background:{TEAL};color:#fff"><th style="padding:2.5mm 3mm;font-size:3mm;text-align:left">মডিউল / Module</th><th style="padding:2.5mm 3mm;font-size:3mm;text-align:left">হাইলাইট / Highlights</th></tr>
      {rows}
    </table>
  </div>
</div>
{footer()}
'''))

# ── 8 why ──
PAGES.append(page(f'''
<div style="padding:14mm 16mm 0">{h(7, 'কেন কমিটি মল ম্যানেজার বেছে নেয়', 'Why Committees Choose Mall Manager')}
  <div style="margin-top:5mm;display:grid;grid-template-columns:1fr 1fr;gap:3.5mm">
    {''.join(f'<div style="background:#fff;border:0.4mm solid {BD};border-left:1.6mm solid {AMBER};border-radius:2.5mm;padding:3.8mm"><div style="font-weight:700;font-size:3.7mm;color:{TEAL_D}">{E(bn)}</div><div style="font-size:2.7mm;color:{MUT};font-weight:700">{E(en)}</div><div style="font-size:3mm;color:{SLATE};margin-top:1.8mm;line-height:1.6">{E(b)}</div><div style="font-size:2.7mm;color:{MUT};margin-top:1.2mm;line-height:1.5">{E(e)}</div></div>' for bn, en, b, e in WHY)}
  </div>
</div>
{footer()}
'''))

# ── 9-10 product in action ──
for i in range(0, 8, 4):
    sh = SHOTS[i:i+4]
    grid = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4mm;margin-top:5mm">' + ''.join(
        f'<div style="background:#fff;border:0.4mm solid {BD};border-radius:2.5mm;padding:2.5mm;box-shadow:0 0.8mm 2.5mm rgba(15,118,110,.12)">{img(f, 84, 52)}<div style="font-size:2.8mm;color:{MUT};text-align:center;margin-top:1.5mm">{E(c1)}<br/><span style="font-size:2.4mm">{E(c2)}</span></div></div>' for f, c1, c2 in sh) + '</div>'
    PAGES.append(page(f'''<div style="padding:14mm 16mm 0">{h(8 + i//4, 'প্রোডাক্ট ইন অ্যাকশন', 'Product in Action', 'লাইভ ডেমোর আসল স্ক্রিন (রাজ্জাক প্লাজা) · Real screens from the live demo')}{grid}</div>{footer()}</div>'''))

# ── 11 technical ──
trows = ''.join(f'<tr><td style="padding:2mm 3mm;font-weight:700;font-size:3mm;color:{TEAL_D};border-bottom:0.3mm solid {BD}">{E(bn)}<div style="font-size:2.5mm;color:{MUT};font-weight:400">{E(en)}</div></td><td style="padding:2mm 3mm;font-size:2.9mm;color:{SLATE};border-bottom:0.3mm solid {BD}">{E(b)}<div style="font-size:2.6mm;color:{MUT};margin-top:.8mm">{E(e)}</div></td></tr>' for bn, en, b, e in TECH)
PAGES.append(page(f'''
<div style="padding:14mm 16mm 0">{h(9, 'টেকনিক্যাল প্রোফাইল', 'Technical Profile')}
  <div style="margin-top:5mm;background:#fff;border:0.4mm solid {BD};border-radius:2.5mm;overflow:hidden">
    <table style="width:100%;border-collapse:collapse"><tr style="background:{TEAL};color:#fff"><th style="padding:2.5mm 3mm;font-size:3mm;text-align:left">বিষয় / Aspect</th><th style="padding:2.5mm 3mm;font-size:3mm;text-align:left">বিস্তারিত / Detail</th></tr>{trows}</table>
  </div>
</div>
{footer()}
'''))

# ── 12 pricing ──
pr = ''.join(f'''<div style="flex:1;background:#fff;border:0.5mm solid {'#F59E0B' if k == 'amber' else TEAL};border-top:1.6mm solid {'#F59E0B' if k == 'amber' else TEAL};border-radius:3mm;padding:4.5mm 4mm;box-shadow:0 1mm 3mm rgba(15,118,110,.1)">
  <div style="font-weight:700;font-size:3.4mm;color:{MUT}">{E(t1)}<br/><span style="font-size:2.5mm">{E(t2)}</span></div>
  <div style="font-weight:700;font-size:5.6mm;color:{TEAL_D};margin-top:2mm">{E(p1)}</div>
  <div style="font-size:2.8mm;color:{AMBER_D};font-weight:700;margin-top:.8mm">{E(s1)}<br/><span style="font-size:2.4mm;color:{MUT}">{E(s2)}</span></div>
  <div style="margin-top:2.5mm;border-top:0.3mm dashed {BD};padding-top:2mm">
    {''.join(f'<div style="font-size:2.7mm;color:{SLATE};margin-top:1.4mm;line-height:1.45">✔ {E(x)}</div>' for x in feats)}
  </div></div>''' for t1, t2, p1, p2, s1, s2, feats, k in PRICING)
PAGES.append(page(f'''
<div style="padding:14mm 16mm 0">{h(10, 'প্রাইসিং', 'Pricing')}
  <div style="display:flex;gap:3.5mm;margin-top:6mm">{pr}</div>
  <div style="margin-top:6mm;background:{BG};border:0.4mm solid {BD};border-radius:2.5mm;padding:4mm;font-size:3mm;color:{SLATE};line-height:1.7">
    📌 <b>প্রতিটি প্যাকেজে / Every package:</b> বাংলা + ইংরেজি ইন্টারফেস · বিল-প্যাড প্রিন্ট · এসএমএস · অফলাইন সিঙ্ক · ডেডিকেটেড সাপোর্ট<br/>
    🎁 <b>লাইভ ডেমো / Live demo:</b> appvaley.com/mall &nbsp;·&nbsp; <b>ওয়েবসাইট / Website:</b> mall.krtaker.com
  </div>
</div>
{footer()}
'''))

# ── 13 licensing ──
PAGES.append(page(f'''
<div style="padding:14mm 16mm 0">{h(11, 'লাইসেন্সিং ও অনবোর্ডিং', 'Licensing & Onboarding')}
  <div style="margin-top:5mm;display:grid;grid-template-columns:1fr 1fr;gap:3.5mm">
    {''.join(f'<div style="background:#fff;border:0.4mm solid {BD};border-top:1.2mm solid {TEAL};border-radius:2.5mm;padding:4mm"><div style="font-weight:700;font-size:3.7mm;color:{TEAL_D}">{E(bn)}</div><div style="font-size:2.7mm;color:{MUT};font-weight:700">{E(en)}</div><div style="font-size:3mm;color:{SLATE};margin-top:2mm;line-height:1.6">{E(b)}</div><div style="font-size:2.7mm;color:{MUT};margin-top:1.2mm;line-height:1.5">{E(e)}</div></div>' for bn, en, b, e in LICENSE)}
  </div>
  <div style="margin-top:6mm;display:grid;grid-template-columns:1fr 1fr;gap:4mm">
    <div style="background:#fff;border:0.4mm solid {BD};border-radius:2.5mm;padding:2.5mm">{img('03_collect_step1.jpg', 84, 52)}<div style="font-size:2.9mm;color:{MUT};text-align:center;margin-top:1.5mm">আদায় — ধাপ ১: দোকান ও বকেয়া / Collect — step 1</div></div>
    <div style="background:#fff;border:0.4mm solid {BD};border-radius:2.5mm;padding:2.5mm">{img('06_coa.jpg', 84, 52)}<div style="font-size:2.9mm;color:{MUT};text-align:center;margin-top:1.5mm">হিসাব — COA / Accounting</div></div>
  </div>
</div>
{footer()}
'''))

# ── 14 contact ──
CONTACT_BOXES = [
    ('📞 যোগাযোগ / Contact', 'BITSCOL — বেলাল আহমেদ\nbelal000bd@gmail.com · +88017*****769'),
    ('🌐 ওয়েবসাইট / Website', 'mall.krtaker.com — পণ্য, দাম ও ব্রোশিওর\nappvaley.com — মার্কেটিং পার্টনার'),
    ('💬 হোয়াটসঅ্যাপ / WhatsApp', 'ডেমো ও কোটেশনের জন্য সরাসরি মেসেজ করুন\nইনবক্সে নাম + মোবাইল পাঠালেই ফিরে কল'),
    ('📚 উইকি ও সাহায্য / Wiki & Help', 'অ্যাপের ভেতরে বাংলা + ইংরেজি উইকি\n৪১টি নিবন্ধ — ধাপে ধাপে, স্ক্রিনশটসহ'),
]
PAGES.append(page(f'''
<div style="height:62mm;background:linear-gradient(165deg,{TEAL} 0%,{TEAL_D} 100%);padding:12mm 16mm;position:relative;overflow:hidden">
  <div style="position:absolute;right:-14mm;top:-16mm;width:56mm;height:56mm;border-radius:50%;background:rgba(255,255,255,.08)"></div>
  <div style="position:relative;text-align:center;color:#fff">
    <div style="font-weight:700;font-size:7mm">ডেমো চাইলে আজই শুরু করুন / Start with a live demo</div>
    <div style="font-size:3.6mm;margin-top:2.5mm;color:rgba(255,255,255,.9)">লাইভ ডেমো: <b>appvaley.com/mall</b> · প্রোডাক্ট পেজ: <b>mall.krtaker.com</b></div>
    <div style="width:30mm;height:1mm;background:{AMBER};border-radius:1mm;margin:5mm auto 0"></div>
  </div>
</div>
<div style="padding:9mm 16mm 0">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:3.5mm">
    {''.join(f'<div style="background:{BG};border:0.4mm solid {BD};border-radius:2.5mm;padding:4mm"><div style="font-weight:700;font-size:3.4mm;color:{TEAL_D}">{E(t)}</div><div style="font-size:2.9mm;color:{SLATE};margin-top:1.8mm;line-height:1.6;white-space:pre-line">{E(d)}</div></div>' for t, d in CONTACT_BOXES)}
  </div>
  <div style="margin-top:6mm;text-align:center;font-size:3mm;color:{MUT};line-height:1.8">
    মল ম্যানেজার v2.2 · মল ও কমার্শিয়াল এডিশন / Mall & Commercial Edition<br/>
    © ২০২৬ KRTaker (BITSCOL এর একটি প্রতিষ্ঠান) — মার্কেটিং: Appvaley · Prepared by KRTaker (A concern of BITSCOL) · Marketed by Appvaley
  </div>
</div>
{footer()}
'''))

HTML = f'''<!DOCTYPE html><html lang="bn"><head><meta charset="utf-8"/>
<title>মল ম্যানেজার — ব্রোশিওর (বাংলা + English)</title>
<style>
  @page {{ size: A4; margin: 0 }}
  * {{ box-sizing: border-box; margin: 0; padding: 0 }}
  @font-face {{ font-family: 'HS'; src: url(data:font/ttf;base64,{FONT_B64}) format('truetype'); font-weight: 400 }}
  @font-face {{ font-family: 'HS'; src: url(data:font/ttf;base64,{FONT_BOLD_B64}) format('truetype'); font-weight: 700 }}
  body {{ font-family: 'HS','Hind Siliguri','Noto Sans Bengali',sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact }}
  .page {{ break-inside: avoid }}
</style></head>
<body>{''.join(PAGES)}</body></html>'''

open('/tmp/mall_brochure_bn.html', 'w').write(HTML)
print('HTML written:', len(HTML)//1024, 'KB, pages:', len(PAGES))
