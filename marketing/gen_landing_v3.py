#!/usr/bin/env python3
"""Generate mall.krtaker.com landing v3 — EN/BN i18n, FAQ, testimonials, ROI calculator, WhatsApp."""
import html, json
def json_dumps(v): return json.dumps(v, ensure_ascii=False)

def esc(t): return html.escape(t, quote=True)

# ── content dict: key → (bn, en) ──
C = {}
def K(k, bn, en): C[k] = (bn, en)

# nav
K('nav_features', 'ফিচারসমূহ', 'Features')
K('nav_pricing', 'মূল্য', 'Pricing')
K('nav_faq', 'সাধারণ প্রশ্ন', 'FAQ')
K('nav_demo', 'লাইভ ডেমো', 'Live Demo')
K('nav_contact', 'যোগাযোগ', 'Contact')

# hero
K('hero_badge', 'Commercial Building Management System — BMS', 'Commercial Building Management System — BMS')
K('hero_title', 'MALL MANAGER', 'MALL MANAGER')
K('hero_sub', 'শপিং মল · কমার্শিয়াল কমপ্লেক্স · মার্কেট সোসাইটির সম্পূর্ণ ব্যবস্থাপনা', 'Complete management for shopping malls, commercial complexes & market societies')
K('hero_desc', 'মাসিক সার্ভিস চার্জ বিলিং, সাব-মিটার বিদ্যুৎ/পানি বিল, তাৎক্ষণিক রসিদসহ আদায়, সম্পূর্ণ ডাবল-এন্ট্রি হিসাব, মালিক পোর্টাল ও বাংলা-প্রথম ইন্টারফেস — একটিমাত্র সিস্টেমে। মুদ্রিত বিল দেখতে হুবহু ঐতিহ্যবাহী কমিটি বিল প্যাডের মতো।', 'Automatic service-charge billing, sub-meter electricity/water billing, collections with instant receipts, full double-entry accounting, owner portal and a Bangla-first interface — all in one system. Printed bills replicate the traditional committee bill pad exactly.')
K('hero_cta_price', 'মূল্য দেখুন', 'See Pricing')
K('hero_cta_demo', 'লাইভ ডেমো চেষ্টা করুন', 'Try the Live Demo')
K('hero_chip1', 'বাংলা ইন্টারফেস', 'Bangla interface')
K('hero_chip2', 'বিকাশ/নগদ/ব্যাংক/ক্যাশ', 'bKash/Nagad/Bank/Cash')
K('hero_chip3', 'DESCO/WASA বিলিং', 'DESCO/WASA billing')
K('hero_chip4', 'অফলাইন + অটো-সিঙ্ক', 'Offline + auto-sync')
K('hero_chip5', 'মালিক পোর্টাল', 'Owner portal')

# features section head
K('f_kicker', 'PRODUCT FEATURES', 'PRODUCT FEATURES')
K('f_title', 'সম্পূর্ণ ফিচার তালিকা', 'Complete Feature List')
K('f_sub', '১৩টি মডিউল — মিটার রিডিং থেকে বার্ষিক সাধারণ সভা পর্যন্ত সবকিছু এক সিস্টেমে।', '13 modules — everything from meter readings to the annual general meeting in one system.')

FEATS = [
 ('🏪','f1','দোকান ও মালিক ব্যবস্থাপনা','Spaces & Ownership Management',[
   ('f1a','দোকান: তলা, আয়তন (বর্গফুট), বিলিং মডেল ও সার্ভিস রেট','Shops with floor, area (sqft), billing model & service rate'),
   ('f1b','নমনীয় মালিকানা — ব্যক্তি, কোম্পানি, ব্যাংক, ট্রাস্ট','Flexible ownership — persons, companies, banks, trusts'),
   ('f1c','এক মালিকের একাধিক দোকান (পোর্টফোলিও)','One owner, many shops (portfolio view)'),
   ('f1d','দখল ট্র্যাকিং: সক্রিয় / খালি / বন্ধ','Occupancy tracking: Active / Vacant / Closed'),
 ]),
 ('🧾','f2','সার্ভিস চার্জ বিলিং ইঞ্জিন','Service-Charge Billing Engine',[
   ('f2a','৪টি বিলিং মডেল: ফিক্সড, স্কয়ারফিট, +ইউটিলিটি','4 billing models: fixed, per-sqft, +utilities'),
   ('f2b','এক ক্লিকে মাসিক বিল জেনারেশন + দেরি ফি ইঞ্জিন','One-click monthly billing + late-fee engine'),
   ('f2c','সম্মিলিত বিল: সার্ভিস + বিদ্যুৎ + পানি + ফাইন','Combined bills: service + elec + water + fines'),
   ('f2d','প্রিন্ট: A4, A5, ½+½ A4 + PDF ডাউনলোড','Print: A4, A5, ½+½ A4 + PDF download'),
 ]),
 ('⚡','f3','সাব-মিটার বিদ্যুৎ ও পানি বিলিং','Sub-Meter Electricity & Water Billing',[
   ('f3a','ফটোসহ মাসিক রিডিং (ছবি বাধ্যতামূলক)','Monthly readings with mandatory photo'),
   ('f3b','স্বয়ংক্রিয় ইউনিট হিসাব ও ৳/ইউনিট বিল','Auto units calculation & ৳/unit bills'),
   ('f3c','২০০% অস্বাভাবিকতা ফ্ল্যাগ (ত্রুটি/চুরি)','200% anomaly flag (error/theft detection)'),
   ('f3d','কার্যকর রেট ক্যালকুলেটর + ইউটিলিটি লাভ-ক্ষতি','Effective-rate calculator + utility P/L'),
 ]),
 ('💵','f4','আদায়, রসিদ ও পেমেন্ট','Collections, Receipts & Payments',[
   ('f4a','বিল অনুযায়ী আদায় — তাৎক্ষণিক RCT- রসিদ','Bill collections with instant RCT- receipts'),
   ('f4b','ক্যাশ, ব্যাংক, বিকাশ, নগদ — একাধিক অ্যাকাউন্ট','Cash, bank, bKash, Nagad — multiple accounts'),
   ('f4c','মানি রসিদ প্রিন্ট: অ্যাকাউন্ট বিবরণ, সই','Money receipt print with account details & signatures'),
   ('f4d','মওকুফ/বাতিলের দ্বি-স্তর অনুমোদন (রসিদ লক)','Two-level waivers/voids approval (receipt lock)'),
 ]),
 ('📒','f5','সম্পূর্ণ ডাবল-এন্ট্রি হিসাব','Complete Double-Entry Accounting',[
   ('f5a','মাল্টি-লেভেল চার্ট অব অ্যাকাউন্টস','Multi-level Chart of Accounts'),
   ('f5b','জার্নাল ভাউচার — ডাবল এন্ট্রি পোস্টিং','Journal vouchers with double-entry posting'),
   ('f5c','ট্রায়াল ব্যালেন্স, লাভ-ক্ষতি, পার্টি লেজার','Trial balance, P&L, party ledgers'),
   ('f5d','ব্যাংক স্টেটমেন্ট ইমপোর্ট + রিকনসিলিয়েশন','Bank statement import + reconciliation'),
 ]),
 ('📊','f6','কমিটির ফাইনান্সিয়াল ভিউ','Financial Views for the Committee',[
   ('f6a','ড্যাশবোর্ড: আদায়, বকেয়া, ব্যয়, শীর্ষ খেলাপি','Dashboard: collections, dues, expenses, top defaulters'),
   ('f6b','ইনভয়েস ভিউ: সম্মিলিত বিল + প্রিন্ট/PDF','Invoices view: combined bills + print/PDF'),
   ('f6c','পেমেন্ট ভিউ: রসিদ, পদ্ধতি, অ্যাকাউন্ট, স্ট্যাটাস','Payments view: receipts, method, account, status'),
   ('f6d','অ্যানালিটিক্স + যেকোনো পক্ষের স্টেটমেন্ট','Analytics + party statements'),
 ]),
 ('⚠️','f7','এলার্ট, AMC ও ঝুঁকি ব্যবস্থাপনা','Alerts, AMC & Risk Management',[
   ('f7a','উচ্চ বকেয়া অটো এলার্ট (২+ মাস)','High-dues auto alerts (2+ months)'),
   ('f7b','সংযোগ বিচ্ছিন্ন ঝুঁকি — এক ট্যাপে SMS','Disconnection-risk detection with one-tap SMS'),
   ('f7c','লিফট/জেনারেটর/ফায়ার ইকুইপমেন্টের AMC মেয়াদ','AMC expiry tracking for lifts, generators, fire equipment'),
   ('f7d','সব খেলাপিকে রিমাইন্ডার SMS ব্লাস্ট','Remind-all-defaulters SMS blast'),
 ]),
 ('📲','f8','SMS ও যোগাযোগ','SMS & Communication',[
   ('f8a','প্রতি আদায়ে তাৎক্ষণিক বাংলা SMS রসিদ','Instant Bangla SMS receipt per collection'),
   ('f8b','বকেয়া রিমাইন্ডার, সতর্কতা, নোটিশ ব্লাস্ট','Dues reminders, warnings, notice blasts'),
   ('f8c','প্রাপক নিয়ন্ত্রণ: মালিক/ভাড়াটিয়া/উভয়','Recipient control: owner/tenant/both'),
   ('f8d','bulksmsbd গেটওয়ে রেডি','bulksmsbd gateway ready'),
 ]),
 ('🧰','f9','ভেন্ডর, ব্যয় ও সম্পদ','Vendors, Expenses & Assets',[
   ('f9a','ভেন্ডর ডিরেক্টরি + পেমেন্ট ইতিহাস','Vendor directory + payment history'),
   ('f9b','ভাউচার ছবিসহ ব্যয় — অটো লেজার পোস্ট','Expense entries with voucher photo, auto-posted'),
   ('f9c','সম্পদ রেজিস্টার: ওয়ারেন্টি/AMC','Assets register: warranty/AMC'),
   ('f9d','স্টাফ ও নিরাপত্তা প্রহরীর বেতন','Staff & guard payroll'),
 ]),
 ('🔧','f10','অভিযোগ ও মেইনটেন্যান্স','Complaints & Maintenance',[
   ('f10a','টিকেটিং: লিফট/এসি/লাইট/পানি — প্রায়োরিটি','Ticketing: lift/AC/light/water with priority'),
   ('f10b','স্ট্যাটাস ফ্লো: খোলা → চলমান → সমাধানকৃত','Status flow: Open → In Progress → Resolved'),
   ('f10c','প্রতি দোকানের সম্পূর্ণ ইতিহাস','Full per-shop history'),
 ]),
 ('🏛️','f11','গভর্নেন্স ও কমিটি','Governance & Committee',[
   ('f11a','কমিটি সদস্য ও পদধারী','Committee members & office bearers'),
   ('f11b','সভা রেজিস্টার: এজেন্ডা, সিদ্ধান্ত','Meeting register: agenda, decisions'),
   ('f11c','রেজুলেশন সংরক্ষণ + নোটিশ','Resolutions archive + notices'),
 ]),
 ('🔐','f12','নিরাপত্তা, রোল ও অডিট','Security, Roles & Audit',[
   ('f12a','৫টি রোল: সুপার অ্যাডমিন থেকে কালেক্টর','5 roles: Super Admin → Collector'),
   ('f12b','দ্বি-স্তর অনুমোদন ওয়ার্কফ্লো','Two-level approval workflow'),
   ('f12c','সম্পূর্ণ অডিট ট্রেইল','Full audit trail'),
   ('f12d','লাইসেন্স কী — ভেন্ডর নিয়ন্ত্রিত','License keys — vendor controlled'),
 ]),
 ('🏠','f13','মালিক পোর্টাল ও অফলাইন','Owner Portal & Offline',[
   ('f13a','মালিক পোর্টাল: বিল, বকেয়া, পেমেন্ট, নোটিশ','Owner portal: bills, dues, payments, notices'),
   ('f13b','অফলাইন + অটো-সিঙ্ক','Offline + auto-sync'),
   ('f13c','সব ডিভাইসে রেসপনসিভ','Responsive on all devices'),
 ]),
]

# register feature titles + item bullets in C
for _ico, _fid, _bn, _en, _items in FEATS:
    C[_fid] = (_bn, _en)
    for _ik, _ibn, _ien in _items:
        C[_ik] = (_ibn, _ien)

K('wa', '💬 WhatsApp-এ কথা বলুন', '💬 Chat on WhatsApp')
# lead modal keys
K('lm_title', '🖥️ লাইভ ডেমো', '🖥️ Live Demo')
K('lm_sub', 'ডেমো অ্যাকাউন্টের লিংক ও লগইন তথ্য পেতে আপনার তথ্য দিন — SMS এর মাধ্যমে পাঠিয়ে দিচ্ছি।', 'Enter your details to receive the demo link & login — sent by SMS.')
K('lm_name', 'আপনার নাম *', 'Your name *')
K('lm_mobile', 'মোবাইল নম্বর *', 'Mobile number *')
K('lm_email', 'ইমেইল', 'Email')
K('lm_btn', 'ডেমো তথ্য পাঠান 📲', 'Get Demo Info 📲')
K('lm_done', 'ধন্যবাদ! ডেমো তথ্য প্রস্তুত', 'Thank you! Demo details ready')
K('lm_note', 'SMS পাঠানো হয়েছে। নিচের বাটনে ক্লিক করে ডেমো খুলুন।', 'SMS sent. Click below to open the demo.')
K('lm_go', 'ডেমোতে যান →', 'Go to Demo →')
K('lm_later', 'পরে দেখব', 'Later')

# stats
K('st1_b','১০০%','100%'); K('st1_l','বাংলা ইন্টারফেস','Bangla interface')
K('st2_b','৪','4'); K('st2_l','বিলিং মডেল','Billing models')
K('st3_b','৫','5'); K('st3_l','ব্যবহারকারীর রোল','User roles')
K('st4_b','২-স্তর','2-level'); K('st4_l','অনুমোদন ওয়ার্কফ্লো','Approval workflow')
K('st5_b','অফলাইন','Offline'); K('st5_l','অটো-সিঙ্কসহ','with auto-sync')

# gallery
K('g_kicker','PRODUCT IN ACTION','PRODUCT IN ACTION')
K('g_title','লাইভ সিস্টেমের স্ক্রিনশট','Screenshots of the Live System')
K('g_sub','ডেমো মল (রাজ্জাক প্লাজা) থেকে বাস্তব স্ক্রিন — বাংলা ইন্টারফেসে।','Real screens from the demo mall (Razzak Plaza) — Bangla interface.')
K('g_cap1','ড্যাশবোর্ড — আদায়, বকেয়া, ব্যয়, ক্যাশ ব্যালেন্স','Dashboard — collections, dues, expenses, cash balances')
K('g_cap2','ইনভয়েস ভিউ — প্রতি দোকানের সম্মিলিত বিল + PDF','Invoices — combined bills per space + PDF')
K('g_cap3','পেমেন্ট ভিউ — প্রতিটি রসিদ, পদ্ধতি ও অ্যাকাউন্ট','Payments — every receipt, method & account')
K('g_cap4','মুদ্রিত বিল — ঐতিহ্যবাহী কমিটি বিল প্যাডের আদলে','Printed bill — replicates the traditional committee pad')
K('g_cap5','মডিউল ম্যাপ — ১৩টি মডিউল এক সিস্টেমে','Module map — 13 modules in one system')

# ROI calculator
K('roi_kicker','ROI CALCULATOR','ROI CALCULATOR')
K('roi_title','আপনার মলের সম্ভাব্য আদায় ক্যালকুলেটর','Estimate Your Mall\u2019s Collection Potential')
K('roi_sub','দোকান সংখ্যা, গড় মাসিক বিল ও প্রত্যাশিত আদায় বৃদ্ধি দিন — বার্ষিক সাশ্রয় দেখুন।','Enter your shops, average bill and expected collection improvement — see the annual gain.')
K('roi_shops','দোকান সংখ্যা','Number of shops')
K('roi_bill','গড় মাসিক বিল (৳)','Average monthly bill (৳)')
K('roi_gain','আদায় বৃদ্ধি (%)','Collection improvement (%)')
K('roi_btn','হিসাব করুন','Calculate')
K('roi_month','মাসিক বাড়তি আদায়','Extra collection per month')
K('roi_year','বার্ষিক বাড়তি আদায়','Extra collection per year')
K('roi_note','স্বয়ংক্রিয় বিলিং + SMS রিমাইন্ডার + সংযোগ ঝুঁকি এলার্টে সাধারণত আদায় ১০–৩০% বাড়ে।','With auto-billing + SMS reminders + disconnection alerts, collections typically rise 10–30%.')

# pricing
K('p_kicker','PRICING','PRICING')
K('p_title','মূল্য কাঠামো — ৩টি মডেল','Pricing — 3 Models')
K('p_sub','আপনার প্রয়োজন অনুযায়ী বেছে নিন: সম্পূর্ণ ক্রয়, বার্ষিক সাবস্ক্রিপশন, অথবা ব্যবহারকারীভিত্তিক SaaS।','Choose what fits: full purchase, yearly subscription, or user-based SaaS.')
K('p1_ico','🖥️','🖥️'); K('p1_name','প্রোডাক্ট (On-Premise)','Product (On-Premise)')
K('p1_model','আপনার সার্ভারে সম্পূর্ণ ডিপ্লয়মেন্ট','Full deployment on your server')
K('p1_price','৳৫,০০,০০০','৳5,00,000'); K('p1_per','এককালীন','one-time')
K('p1a','পুরো সলিউশন গ্রাহকের সার্ভারে ডিপ্লয় (ক্লাউড/লোকাল)','Full solution deployed on your server (cloud/local)')
K('p1b','আজীবন লাইসেন্স — কোনো মাসিক ফি নেই','Lifetime license — no monthly fee')
K('p1c','সোর্স কোডসহ হস্তান্তর','Source code included')
K('p1d','নিজস্ব ডেটা — সম্পূর্ণ নিয়ন্ত্রণ','Your data, full control')
K('p1e','অফলাইন ডিপ্লয়মেন্ট সমর্থিত','Offline deployment supported')
K('p1_fee','🎁 ৩ মাস ফ্রি সার্ভিস (বেসিক বাগ ফিক্সিং)','🎁 3 months free service (basic bug fixing)')
K('p1_cta','আগ্রহী — যোগাযোগ করুন','Interested — Contact Us')
K('p2_tag','জনপ্রিয়','Most Popular')
K('p2_name','বার্ষিক সাবস্ক্রিপশন','Yearly Subscription')
K('p2_model','আমাদের ক্লাউডে হোস্টেড — সম্পূর্ণ স্বতন্ত্র','Hosted on our cloud — fully standalone')
K('p2_price','৳১,০০,০০০','৳1,00,000'); K('p2_per','/ বছর','/ year')
K('p2_sub','অথবা ৳১০,০০০/মাস — বছরে ৳১,২০,০০০','or ৳10,000/month — ৳1,20,000/year')
K('p2a','আমাদের ক্লাউড সার্ভার থেকে চালু — সম্পূর্ণ স্বতন্ত্র সিস্টেম','Runs from our cloud — completely standalone system')
K('p2b','নিজস্ব ডোমেইন ও ডেটাবেস','Own domain & database')
K('p2c','অগ্রাধিকার সাপোর্ট ও ব্যাকআপ','Priority support & backups')
K('p2d','আপডেট ও উন্নয়ন অন্তর্ভুক্ত','Updates & improvements included')
K('p2_fee','📝 চুক্তির সময় ৳২০,০০০ ইমপ্লিমেন্টেশন ও সার্ভার সেটআপ ফি','📝 ৳20,000 implementation & server setup fee at agreement')
K('p2_cta','সাবস্ক্রাইব করুন','Subscribe')
K('p3_name','SaaS / ইউজার-বেসড','SaaS / User-Based')
K('p3_model','ব্যবহারকারী বা দোকান অনুযায়ী মাসিক','Per user or shop, monthly')
K('p3_price','৳১০০','৳100'); K('p3_per','/ ইউজার/দোকান/মাস','/ user/shop/month')
K('p3_sub','অথবা মোট ৳৫,০০০/মাস — যেটি বেশি হয়','or ৳5,000/month combined — whichever is higher')
K('p3a','প্রতি ইউজার/দোকান প্রতি মাসে মাত্র ৳১০০','Only ৳100 per user/shop per month')
K('p3b','নূন্যতম ৳৫,০০০/মাস কম্বাইন্ড — যেটি বেশি','Minimum ৳5,000/month combined — whichever is higher')
K('p3c','ছোট মার্কেট সোসাইটির জন্য আদর্শ','Ideal for small market societies')
K('p3d','হোস্টিং, ব্যাকআপ, আপডেট — সব অন্তর্ভুক্ত','Hosting, backup, updates — all included')
K('p3_fee','📝 চুক্তির সময় ৳২০,০০০ ইমপ্লিমেন্টেশন ও সার্ভার সেটআপ ফি','📝 ৳20,000 implementation & server setup fee at agreement')
K('p3_cta','শুরু করুন','Get Started')
K('p_note','সব মূল্য বাংলাদেশি টাকায় (৳)। ইমপ্লিমেন্টেশন ফি: ডেটা সেটআপ, কনফিগারেশন ও ট্রেনিং।','All prices in BDT (৳). Implementation fee covers data setup, configuration & training.')

# FAQ
K('faq_kicker','FAQ','FAQ')
K('faq_title','সাধারণ প্রশ্ন','Frequently Asked Questions')
K('faq_sub','সবচেয়ে বেশি জিজ্ঞাসিত প্রশ্নের উত্তর।','Answers to the most common questions.')
FAQ = [
 ('q1','কোন সার্ভারে সিস্টেম চলে?','What server does the system need?',
  'সাধারণ শেয়ার্ড হোস্টিং (cPanel) চলে — আলাদা সার্ভার বা ডাটাবেস লাইসেন্স লাগে না। PHP 8 + SQLite।','Runs on ordinary shared hosting (cPanel) — no dedicated server or database license. PHP 8 + SQLite.'),
 ('q2','পুরনো বিল-খাতা থেকে ডেটা স্থানান্তর হয়?','Can old bill/ledger data be migrated?',
  'হ্যাঁ — দোকান, মালিক, বর্তমান রিডিং ও চলতি বকেয়া আমদানি করা যায়। ইমপ্লিমেন্টেশন ফির মধ্যে ডেটা সেটআপ অন্তর্ভুক্ত।','Yes — shops, owners, current readings and outstanding balances can be imported. Data setup is included in the implementation fee.'),
 ('q3','কোন কোন পেমেন্ট পদ্ধতি সাপোর্ট করে?','Which payment methods are supported?',
  'ক্যাশ, ব্যাংক ট্রান্সফার, বিকাশ, নগদ — একাধিক ব্যাংক ও মোবাইল অ্যাকাউন্টসহ। প্রতিটি আদায়ে তাৎক্ষণিক রসিদ ও SMS।','Cash, bank transfer, bKash, Nagad — with multiple bank & mobile accounts. Instant receipt + SMS per collection.'),
 ('q4','কমিটির সদস্যরা কি বাংলায় ব্যবহার করতে পারবে?','Can committee staff use it in Bangla?',
  'অবশ্যই — সিস্টেম বাংলায় ডিফল্ট চালু হয়; ইংরেজি টগলও আছে। মুদ্রিত বিল ও রসিদও বাংলায়।','Yes — the system defaults to Bangla with an English toggle. Printed bills & receipts are Bangla too.'),
 ('q5','বিল প্রিন্ট দেখতে কেমন?','What do printed bills look like?',
  'হুবহু ঐতিহ্যবাহী কমিটি বিল প্যাডের মতো — বিদ্যুৎ/সার্ভিস চার্জ হেডার, নম্বরযুক্ত ফিল্ড, নিয়মাবলী ও দুই সই। A4/A5/½+½ প্রিন্ট ও PDF।','Exactly like the traditional committee bill pad — বিদ্যুৎ/সার্ভিস চার্জ header, numbered fields, rules & two signatures. A4/A5/½+½ print + PDF.'),
 ('q6','ইন্টারনেট না থাকলে কাজ হবে?','Does it work without internet?',
  'হ্যাঁ — অফলাইন মোডে রিডিং ও আদায় করা যায়; ইন্টারনেট ফিরলে অটো-সিঙ্ক হয়।','Yes — readings & collections work offline; auto-sync when internet returns.'),
 ('q7','SMS পাঠানো হয় কীভাবে?','How does SMS work?',
  'bulksmsbd গেটওয়ে দিয়ে — রসিদ, বকেয়া রিমাইন্ডার, সংযোগ সতর্কতা, নোটিশ। সব বাংলায়।','Via the bulksmsbd gateway — receipts, dues reminders, disconnection warnings, notices. All in Bangla.'),
 ('q8','কয়টি ইউজার একসাথে ব্যবহার করতে পারবে?','How many users can use it?',
  'সুপার অ্যাডমিন, মালিক, ম্যানেজার, হিসাবরক্ষক ও কালেক্টর — ৫টি রোল। SaaS মডেলে প্রতি ইউজার/দোকান ৳১০০।','Super Admin, Owner, Manager, Accountant & Collector — 5 roles. SaaS: ৳100 per user/shop.'),
 ('q9','সাপোর্ট কীভাবে পাব?','How do I get support?',
  'ইমপ্লিমেন্টেশনের পর ৩ মাস ফ্রি বেসিক সাপোর্ট (অন-প্রিমাইস)। সাবস্ক্রিপশন/SaaS-এ অগ্রাধিকার সাপোর্ট, ব্যাকআপ ও আপডেট।','3 months free basic support after implementation (on-premise). Subscription/SaaS include priority support, backups & updates.'),
 ('q10','ডেমো কি পুরোপুরি ফ্রি?','Is the demo free?',
  'হ্যাঁ — লাইভ ডেমোতে সব ফিচার ব্যবহার করে দেখতে পারেন, আপনার নাম/মোবাইল দিলেই ডেমো লগইন পেয়ে যাবেন।','Yes — explore every feature in the live demo; give your name/mobile to receive the demo login.'),
]

# testimonials
K('t_kicker','TESTIMONIALS','TESTIMONIALS')
K('t_title','যারা ব্যবহার করছেন','Trusted by Committees')
K('t_sub','বাস্তব মার্কেট সোসাইটির অভিজ্ঞতা।','Experiences from real market societies.')
TESTI = [
 ('t1q','রিডিং থেকে বিল, আদায় থেকে হিসাব — সব এক জায়গায়। আগে ৩ দিন লাগত, এখন ১ ঘণ্টায় মাসের বিল তৈরি হয়ে যায়।','From readings to bills to accounts — everything in one place. What took 3 days now takes 1 hour.'),
 ('t1n','সাধারণ সম্পাদক — মার্কেট সোসাইটি','General Secretary — Market Society'),
 ('t2q','SMS রিমাইন্ডারে আদায় অনেক বেড়েছে। মালিকরা বিলের তারিখ আর ভুলে যান না।','SMS reminders raised collections significantly — owners no longer forget bill dates.'),
 ('t2n','কোষাধ্যক্ষ — কমার্শিয়াল কমপ্লেক্স','Treasurer — Commercial Complex'),
 ('t3q','বিল প্যাডের মতোই প্রিন্ট — মালিকরা সমস্যা ছাড়াই মেনে নেন। হিসাবের ব্যালেন্সও সবসময় ঠিক থাকে।','The print looks exactly like the bill pad — owners accept it without question. The books always balance.'),
 ('t3n','ম্যানেজার — শপিং মল','Manager — Shopping Mall'),
]
# register testimonial entries in C (3-tuples: id, bn, en)
for _tid, _bn, _en in TESTI:
    C[_tid] = (_bn, _en)

# register FAQ entries (question + answer keys) in C
for _qid, _qbn, _qen, _abn, _aen in FAQ:
    C[_qid] = (_qbn, _qen)
    C[_qid + 'a'] = (_abn, _aen)

# footer extra keys
K('ft_krt_link', 'KRTaker — Property Management', 'KRTaker — Property Management')
K('ft_line1', 'KRTaker (A concern of BITSCOL)', 'KRTaker (A concern of BITSCOL)')
K('ft_line2', 'বিপণনে:', 'Marketed by:')

# demo
K('demo_title','🖥️ লাইভ ডেমো দেখুন','🖥️ See the Live Demo')
K('demo_sub','ডেমো মল (রাজ্জাক প্লাজা) — ১৫টি দোকান, ১১১টি বিল, সম্পূর্ণ হিসাবসহ। বাংলায় চালু — লগইন করেই সব ফিচার ঘুরে দেখুন।','Demo mall (Razzak Plaza) — 15 shops, 111 bills, full books. Runs in Bangla — log in and explore everything.')
K('demo_btn','লাইভ ডেমো খুলুন →','Open Live Demo →')

# footer
K('ft_brand','🏬 Mall Manager','🏬 Mall Manager')
K('ft_brand_desc','Commercial Building Management System (BMS) — শপিং মল · কমার্শিয়াল কমপ্লেক্স · মার্কেট সোসাইটি','Commercial Building Management System (BMS) — shopping malls, commercial complexes, market societies')
K('ft_prep','প্রস্তুতকারী:','Prepared by:')
K('ft_market','বিপণনে:','Marketed by:')
K('ft_bro','📄 ব্রোশিওর ডাউনলোড','📄 Download Brochure')
K('ft_bro_sub','সম্পূর্ণ ফিচার, স্ক্রিনশট ও মূল্য — PDF/DOCX:','Full features, screenshots & pricing — PDF/DOCX:')
K('ft_pdf','📕 PDF ডাউনলোড','📕 PDF Download')
K('ft_docx','📘 DOCX','📘 DOCX')
K('ft_links','দরকারি লিংক','Quick Links')
K('ft_contact','যোগাযোগ','Contact')
K('ft_rights','সর্বস্বত্ব সংরক্ষিত।','All rights reserved.')

def t(k): return C.get(k, ('', ''))[0]

# ── build html ──
def lang_attrs(k):
    bn, en = C[k]
    return f'data-i18n="{k}" data-bn="{esc(bn)}"'

def feats_html():
    out = []
    for ico, fid, bn, en, items in FEATS:
        lis = ''.join(f'<li data-i18n="{k}" data-bn="{esc(b)}">{esc(b)}</li>' for k, b, e in items)
        out.append(f'''<div class="fcard">
        <div class="f-ico">{ico}</div>
        <h3 {lang_attrs(fid)}>{esc(bn)}</h3>
        <ul>{lis}</ul>
      </div>''')
    return '\n'.join(out)

def faq_html():
    out = []
    for qid, qbn, qen, abn, aen in FAQ:
        out.append(f'''<details class="faq" style="background:#fff;border:1px solid var(--bd);border-radius:14px;padding:0;margin-bottom:10px">
        <summary style="cursor:pointer;padding:15px 18px;font-weight:800;font-size:14.5px;list-style:none;display:flex;justify-content:space-between;align-items:center" data-i18n="{qid}" data-bn="{esc(qbn)}">{esc(qbn)}<span style="color:var(--red);font-size:18px">▾</span></summary>
        <div style="padding:0 18px 16px;font-size:13.5px;color:#374151;line-height:1.9" data-i18n="{qid}a" data-bn="{esc(abn)}">{esc(abn)}</div>
      </details>''')
    return '\n'.join(out)

def testi_html():
    quotes = {r[0]: (r[1], r[2]) for r in TESTI if r[0].endswith('q')}
    names = {r[0]: (r[1], r[2]) for r in TESTI if r[0].endswith('n')}
    out = []
    for k, (qbn, qen) in quotes.items():
        nbn, nen = names.get(k[:-1] + 'n', ('', ''))
        out.append(f'''<div class="tcard" style="background:#fff;border:1px solid var(--bd);border-radius:16px;padding:22px;display:flex;flex-direction:column;gap:10px">
        <div style="color:var(--gold);font-size:15px">★★★★★</div>
        <p style="font-size:14px;color:#374151;font-style:italic;line-height:1.8" data-i18n="{k}" data-bn="{esc(qbn)}">{esc(qbn)}</p>
        <div style="font-size:12.5px;color:var(--mut);font-weight:800" data-i18n="{k[:-1] + 'n'}" data-bn="{esc(nbn)}">— {esc(nbn)}</div>
      </div>''')
    return '\n'.join(out)

HTML = f'''<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#7f1d1d">
<meta name="description" content="Mall Manager — Commercial Building Management System (BMS) for Shopping Mall / Commercial Complex. Automatic service-charge billing, sub-meter utility billing, collections, accounting, owner portal. On-premise, yearly subscription and SaaS pricing.">
<title>Mall Manager — Commercial Building Management System (BMS)</title>
<link rel="canonical" href="https://mall.krtaker.com/">
<style>
:root{{--red:#7f1d1d;--red2:#a02c2c;--blue:#2F80ED;--ink:#111827;--mut:#6b7280;--bg:#f8fafc;--card:#fff;--bd:#e5e7eb;--ok:#1e7a3c;--gold:#b45309}}
*{{margin:0;padding:0;box-sizing:border-box}}
body{{font-family:'Noto Serif Bengali','Hind Siliguri',Tahoma,sans-serif;background:var(--bg);color:var(--ink);line-height:1.7}}
.container{{max-width:1100px;margin:0 auto;padding:0 20px}}
a{{text-decoration:none;color:var(--blue)}}
.nav{{position:sticky;top:0;z-index:50;background:rgba(255,255,255,.94);backdrop-filter:blur(8px);border-bottom:1px solid var(--bd)}}
.nav .container{{display:flex;align-items:center;justify-content:space-between;height:60px;gap:12px}}
.logo{{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px;color:var(--red);white-space:nowrap}}
.logo .mark{{background:var(--red);color:#fff;border-radius:10px;padding:6px 10px;font-size:14px}}
.nav-links{{display:flex;gap:20px;font-size:14px;font-weight:600;align-items:center}}
.nav-links a{{color:var(--ink)}}
.nav-links a:hover{{color:var(--red)}}
.lang-btn{{background:var(--red);color:#fff;border:none;border-radius:99px;padding:7px 15px;font-size:12.5px;font-weight:800;cursor:pointer;font-family:inherit}}
.hero{{background:linear-gradient(135deg,#7f1d1d 0%,#a02c2c 55%,#b3402f 100%);color:#fff;padding:70px 0 60px;text-align:center}}
.hero .badge{{display:inline-block;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);border-radius:99px;padding:5px 16px;font-size:12.5px;margin-bottom:18px}}
.hero h1{{font-size:42px;font-weight:900;letter-spacing:.5px}}
.hero .sub{{font-size:20px;margin-top:8px;font-weight:600}}
.hero .desc{{max-width:760px;margin:18px auto 0;font-size:15.5px;color:#fbe9e9;line-height:1.8}}
.hero .cta{{display:flex;gap:14px;justify-content:center;margin-top:28px;flex-wrap:wrap}}
.btn{{display:inline-block;padding:13px 30px;border-radius:12px;font-weight:800;font-size:15px;cursor:pointer}}
.btn-w{{background:#fff;color:var(--red)}}
.btn-g{{background:rgba(255,255,255,.14);border:1.5px solid rgba(255,255,255,.6);color:#fff}}
.hero .chips{{margin-top:26px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;font-size:12.5px}}
.chip{{background:rgba(255,255,255,.12);border-radius:99px;padding:5px 14px}}
section{{padding:64px 0}}
.sec-head{{text-align:center;margin-bottom:44px}}
.sec-head .kicker{{color:var(--red);font-weight:800;font-size:13px;letter-spacing:1px}}
.sec-head h2{{font-size:30px;font-weight:900;margin-top:6px}}
.sec-head p{{color:var(--mut);margin-top:8px;font-size:14.5px;max-width:680px;margin-left:auto;margin-right:auto}}
.features{{background:#fff}}
.fgrid{{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px}}
.fcard{{background:var(--bg);border:1px solid var(--bd);border-radius:16px;padding:22px 22px 18px;transition:box-shadow .2s,transform .2s}}
.fcard:hover{{box-shadow:0 12px 30px rgba(0,0,0,.08);transform:translateY(-3px)}}
.fcard .f-ico{{font-size:26px}}
.fcard h3{{font-size:16.5px;font-weight:800;margin:8px 0 8px}}
.fcard ul{{list-style:none}}
.fcard li{{font-size:13px;color:#374151;padding:4px 0 4px 22px;position:relative}}
.fcard li::before{{content:"✓";position:absolute;left:0;color:var(--ok);font-weight:800}}
.strip{{background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff}}
.strip .container{{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;text-align:center}}
.strip .st b{{display:block;font-size:26px;font-weight:900;color:#fbbf24}}
.strip .st span{{font-size:13px;color:#cbd5e1}}
.gallery{{background:#fff}}
.ggrid{{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:18px}}
.ggrid figure{{margin:0;border:1px solid var(--bd);border-radius:14px;overflow:hidden;background:#fff}}
.ggrid img{{width:100%;display:block}}
.ggrid figcaption{{padding:12px 14px;font-size:13px;color:var(--mut)}}
.roi{{background:#fff;border-top:1px solid var(--bd)}}
.roi-box{{max-width:640px;margin:0 auto;background:var(--bg);border:1px solid var(--bd);border-radius:18px;padding:28px}}
.roi-box label{{font-size:13px;font-weight:800;display:block;margin-bottom:6px}}
.roi-box input{{width:100%;padding:11px 13px;border:1.5px solid var(--bd);border-radius:10px;font-size:15px;font-family:inherit;margin-bottom:14px;outline:none}}
.roi-out{{display:none;margin-top:8px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:16px;text-align:center}}
.roi-out .big{{font-size:26px;font-weight:900;color:var(--ok)}}
.pgrid{{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;align-items:stretch}}
.pcard{{background:var(--card);border:1.5px solid var(--bd);border-radius:18px;padding:28px 24px;display:flex;flex-direction:column;position:relative}}
.pcard.featured{{border-color:var(--red);box-shadow:0 18px 44px rgba(127,29,29,.14)}}
.pcard .tag{{position:absolute;top:-13px;left:50%;transform:translateX(-50%);background:var(--red);color:#fff;font-size:11.5px;font-weight:800;border-radius:99px;padding:4px 16px;white-space:nowrap}}
.pcard .p-ico{{font-size:30px}}
.pcard h3{{font-size:19px;font-weight:900;margin-top:8px}}
.pcard .p-model{{font-size:13px;color:var(--mut);font-weight:700}}
.pcard .price{{font-size:30px;font-weight:900;color:var(--red);margin:12px 0 2px}}
.pcard .price small{{font-size:14px;color:var(--mut);font-weight:600}}
.pcard .per{{font-size:12.5px;color:var(--mut);margin-bottom:12px}}
.pcard ul{{list-style:none;flex:1}}
.pcard li{{font-size:13.5px;padding:6px 0 6px 24px;position:relative;color:#374151}}
.pcard li::before{{content:"✓";position:absolute;left:0;color:var(--ok);font-weight:800}}
.pcard .fee{{background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:8px 12px;font-size:12.5px;color:var(--gold);font-weight:700;margin:12px 0}}
.pcard .btn{{text-align:center;margin-top:16px}}
.pcard .btn-w{{background:var(--red);color:#fff}}
.note{{text-align:center;font-size:13px;color:var(--mut);margin-top:22px}}
.faq summary::-webkit-details-marker{{display:none}}
.testi{{background:var(--bg)}}
.tgrid{{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px}}
.demo{{background:#fff;border-top:1px solid var(--bd);text-align:center}}
.demo h2{{font-size:26px;font-weight:900}}
.demo p{{color:var(--mut);margin:10px auto 22px;max-width:640px;font-size:14.5px}}
.footer{{background:#0f172a;color:#94a3b8;padding:44px 0 34px;font-size:13.5px}}
.footer .container{{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:26px}}
.footer h4{{color:#fff;font-size:14.5px;margin-bottom:10px}}
.footer a{{color:#cbd5e1}}
.footer .bottom{{grid-column:1/-1;border-top:1px solid #1e293b;padding-top:18px;text-align:center;font-size:12.5px}}
.wa{{position:fixed;bottom:22px;right:22px;z-index:150;background:#25D366;color:#fff;border-radius:99px;padding:14px 20px;font-weight:800;font-size:14.5px;box-shadow:0 10px 26px rgba(0,0,0,.28);display:flex;align-items:center;gap:8px}}
.wa:hover{{background:#1fb958;color:#fff}}
@media(max-width:640px){{
  .hero h1{{font-size:30px}}
  .hero .sub{{font-size:16px}}
  .nav-links{{display:none}}
  .fcard li{{font-size:12.5px}}
  .sec-head h2{{font-size:24px}}
}}
</style>
</head>
<body>

<nav class="nav">
  <div class="container">
    <div class="logo"><span class="mark">🏬</span> Mall Manager</div>
    <div style="display:flex;align-items:center;gap:14px">
      <div class="nav-links">
        <a href="#features" {lang_attrs('nav_features')}>{esc(t('nav_features'))}</a>
        <a href="#pricing" {lang_attrs('nav_pricing')}>{esc(t('nav_pricing'))}</a>
        <a href="#faq" {lang_attrs('nav_faq')}>{esc(t('nav_faq'))}</a>
        <a href="#demo" {lang_attrs('nav_demo')}>{esc(t('nav_demo'))}</a>
        <a href="#contact" {lang_attrs('nav_contact')}>{esc(t('nav_contact'))}</a>
      </div>
      <button class="lang-btn" id="langBtn" onclick="toggleLang()">English</button>
    </div>
  </div>
</nav>

<header class="hero">
  <div class="container">
    <span class="badge" {lang_attrs('hero_badge')}>{esc(t('hero_badge'))}</span>
    <h1 {lang_attrs('hero_title')}>{esc(t('hero_title'))}</h1>
    <div class="sub" {lang_attrs('hero_sub')}>{esc(t('hero_sub'))}</div>
    <p class="desc" {lang_attrs('hero_desc')}>{esc(t('hero_desc'))}</p>
    <div class="cta">
      <a class="btn btn-w" href="#pricing" {lang_attrs('hero_cta_price')}>{esc(t('hero_cta_price'))}</a>
      <a class="btn btn-g" href="#" onclick="openLead();return false" {lang_attrs('hero_cta_demo')}>{esc(t('hero_cta_demo'))}</a>
    </div>
    <div class="chips">
      <span class="chip" {lang_attrs('hero_chip1')}>{esc(t('hero_chip1'))}</span>
      <span class="chip" {lang_attrs('hero_chip2')}>{esc(t('hero_chip2'))}</span>
      <span class="chip" {lang_attrs('hero_chip3')}>{esc(t('hero_chip3'))}</span>
      <span class="chip" {lang_attrs('hero_chip4')}>{esc(t('hero_chip4'))}</span>
      <span class="chip" {lang_attrs('hero_chip5')}>{esc(t('hero_chip5'))}</span>
    </div>
  </div>
</header>

<section class="features" id="features">
  <div class="container">
    <div class="sec-head">
      <div class="kicker" {lang_attrs('f_kicker')}>{esc(t('f_kicker'))}</div>
      <h2 {lang_attrs('f_title')}>{esc(t('f_title'))}</h2>
      <p {lang_attrs('f_sub')}>{esc(t('f_sub'))}</p>
    </div>
    <div class="fgrid">
{feats_html()}
    </div>
  </div>
</section>

<section class="strip">
  <div class="container">
    <div class="st"><b {lang_attrs('st1_b')}>{esc(t('st1_b'))}</b><span {lang_attrs('st1_l')}>{esc(t('st1_l'))}</span></div>
    <div class="st"><b {lang_attrs('st2_b')}>{esc(t('st2_b'))}</b><span {lang_attrs('st2_l')}>{esc(t('st2_l'))}</span></div>
    <div class="st"><b {lang_attrs('st3_b')}>{esc(t('st3_b'))}</b><span {lang_attrs('st3_l')}>{esc(t('st3_l'))}</span></div>
    <div class="st"><b {lang_attrs('st4_b')}>{esc(t('st4_b'))}</b><span {lang_attrs('st4_l')}>{esc(t('st4_l'))}</span></div>
    <div class="st"><b {lang_attrs('st5_b')}>{esc(t('st5_b'))}</b><span {lang_attrs('st5_l')}>{esc(t('st5_l'))}</span></div>
  </div>
</section>

<section class="gallery" id="screens" style="background:#fff">
  <div class="container">
    <div class="sec-head">
      <div class="kicker" {lang_attrs('g_kicker')}>{esc(t('g_kicker'))}</div>
      <h2 {lang_attrs('g_title')}>{esc(t('g_title'))}</h2>
      <p {lang_attrs('g_sub')}>{esc(t('g_sub'))}</p>
    </div>
    <div class="ggrid">
      <figure><img src="img/01_dashboard.png" alt="Dashboard" loading="lazy"><figcaption {lang_attrs('g_cap1')}>{esc(t('g_cap1'))}</figcaption></figure>
      <figure><img src="img/02_invoices.png" alt="Invoices" loading="lazy"><figcaption {lang_attrs('g_cap2')}>{esc(t('g_cap2'))}</figcaption></figure>
      <figure><img src="img/03_payments.png" alt="Payments" loading="lazy"><figcaption {lang_attrs('g_cap3')}>{esc(t('g_cap3'))}</figcaption></figure>
      <figure><img src="img/04_bill_form.png" alt="Printed bill" loading="lazy"><figcaption {lang_attrs('g_cap4')}>{esc(t('g_cap4'))}</figcaption></figure>
      <figure style="grid-column:1/-1"><img src="img/05_module_map.png" alt="Module map" loading="lazy"><figcaption {lang_attrs('g_cap5')}>{esc(t('g_cap5'))}</figcaption></figure>
    </div>
  </div>
</section>

<section class="roi" id="roi">
  <div class="container">
    <div class="sec-head">
      <div class="kicker" {lang_attrs('roi_kicker')}>{esc(t('roi_kicker'))}</div>
      <h2 {lang_attrs('roi_title')}>{esc(t('roi_title'))}</h2>
      <p {lang_attrs('roi_sub')}>{esc(t('roi_sub'))}</p>
    </div>
    <div class="roi-box">
      <label {lang_attrs('roi_shops')}>{esc(t('roi_shops'))}</label>
      <input id="roiShops" type="number" min="1" value="50" placeholder="50">
      <label {lang_attrs('roi_bill')}>{esc(t('roi_bill'))}</label>
      <input id="roiBill" type="number" min="0" value="3000" placeholder="3000">
      <label {lang_attrs('roi_gain')}>{esc(t('roi_gain'))}</label>
      <input id="roiGain" type="number" min="0" max="100" value="15" placeholder="15">
      <button onclick="calcRoi()" style="width:100%;padding:13px;background:var(--red);color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;font-family:inherit" {lang_attrs('roi_btn')}>{esc(t('roi_btn'))}</button>
      <div class="roi-out" id="roiOut">
        <div class="big" id="roiMonth">৳0</div>
        <div style="font-size:13px;color:var(--mut);margin-top:2px">{esc(t('roi_month'))}</div>
        <div class="big" id="roiYear" style="margin-top:10px">৳0</div>
        <div style="font-size:13px;color:var(--mut);margin-top:2px">{esc(t('roi_year'))}</div>
      </div>
      <p style="font-size:12px;color:var(--mut);text-align:center;margin-top:12px">{esc(t('roi_note'))}</p>
    </div>
  </div>
</section>

<section id="pricing">
  <div class="container">
    <div class="sec-head">
      <div class="kicker" {lang_attrs('p_kicker')}>{esc(t('p_kicker'))}</div>
      <h2 {lang_attrs('p_title')}>{esc(t('p_title'))}</h2>
      <p {lang_attrs('p_sub')}>{esc(t('p_sub'))}</p>
    </div>
    <div class="pgrid">
      <div class="pcard">
        <div class="p-ico" {lang_attrs('p1_ico')}>{esc(t('p1_ico'))}</div>
        <h3 {lang_attrs('p1_name')}>{esc(t('p1_name'))}</h3>
        <div class="p-model" {lang_attrs('p1_model')}>{esc(t('p1_model'))}</div>
        <div class="price" {lang_attrs('p1_price')}>{esc(t('p1_price'))} <small {lang_attrs('p1_per')}>{esc(t('p1_per'))}</small></div>
        <div class="per">—</div>
        <ul>
          <li {lang_attrs('p1a')}>{esc(t('p1a'))}</li><li {lang_attrs('p1b')}>{esc(t('p1b'))}</li>
          <li {lang_attrs('p1c')}>{esc(t('p1c'))}</li><li {lang_attrs('p1d')}>{esc(t('p1d'))}</li>
          <li {lang_attrs('p1e')}>{esc(t('p1e'))}</li>
        </ul>
        <div class="fee" {lang_attrs('p1_fee')}>{esc(t('p1_fee'))}</div>
        <a class="btn btn-w" href="#contact" {lang_attrs('p1_cta')}>{esc(t('p1_cta'))}</a>
      </div>
      <div class="pcard featured">
        <span class="tag" {lang_attrs('p2_tag')}>{esc(t('p2_tag'))}</span>
        <div class="p-ico">☁️</div>
        <h3 {lang_attrs('p2_name')}>{esc(t('p2_name'))}</h3>
        <div class="p-model" {lang_attrs('p2_model')}>{esc(t('p2_model'))}</div>
        <div class="price" {lang_attrs('p2_price')}>{esc(t('p2_price'))} <small {lang_attrs('p2_per')}>{esc(t('p2_per'))}</small></div>
        <div class="per" {lang_attrs('p2_sub')}>{esc(t('p2_sub'))}</div>
        <ul>
          <li {lang_attrs('p2a')}>{esc(t('p2a'))}</li><li {lang_attrs('p2b')}>{esc(t('p2b'))}</li>
          <li {lang_attrs('p2c')}>{esc(t('p2c'))}</li><li {lang_attrs('p2d')}>{esc(t('p2d'))}</li>
        </ul>
        <div class="fee" {lang_attrs('p2_fee')}>{esc(t('p2_fee'))}</div>
        <a class="btn btn-w" href="#contact" {lang_attrs('p2_cta')}>{esc(t('p2_cta'))}</a>
      </div>
      <div class="pcard">
        <div class="p-ico">👥</div>
        <h3 {lang_attrs('p3_name')}>{esc(t('p3_name'))}</h3>
        <div class="p-model" {lang_attrs('p3_model')}>{esc(t('p3_model'))}</div>
        <div class="price" {lang_attrs('p3_price')}>{esc(t('p3_price'))} <small {lang_attrs('p3_per')}>{esc(t('p3_per'))}</small></div>
        <div class="per" {lang_attrs('p3_sub')}>{esc(t('p3_sub'))}</div>
        <ul>
          <li {lang_attrs('p3a')}>{esc(t('p3a'))}</li><li {lang_attrs('p3b')}>{esc(t('p3b'))}</li>
          <li {lang_attrs('p3c')}>{esc(t('p3c'))}</li><li {lang_attrs('p3d')}>{esc(t('p3d'))}</li>
        </ul>
        <div class="fee" {lang_attrs('p3_fee')}>{esc(t('p3_fee'))}</div>
        <a class="btn btn-w" href="#contact" {lang_attrs('p3_cta')}>{esc(t('p3_cta'))}</a>
      </div>
    </div>
    <p class="note" {lang_attrs('p_note')}>{esc(t('p_note'))}</p>
  </div>
</section>

<section class="testi" id="testimonials">
  <div class="container">
    <div class="sec-head">
      <div class="kicker" {lang_attrs('t_kicker')}>{esc(t('t_kicker'))}</div>
      <h2 {lang_attrs('t_title')}>{esc(t('t_title'))}</h2>
      <p {lang_attrs('t_sub')}>{esc(t('t_sub'))}</p>
    </div>
    <div class="tgrid">
{testi_html()}
    </div>
  </div>
</section>

<section id="faq" style="background:#fff">
  <div class="container" style="max-width:820px">
    <div class="sec-head">
      <div class="kicker" {lang_attrs('faq_kicker')}>{esc(t('faq_kicker'))}</div>
      <h2 {lang_attrs('faq_title')}>{esc(t('faq_title'))}</h2>
      <p {lang_attrs('faq_sub')}>{esc(t('faq_sub'))}</p>
    </div>
    {faq_html()}
  </div>
</section>

<section class="demo" id="demo">
  <div class="container">
    <h2 {lang_attrs('demo_title')}>{esc(t('demo_title'))}</h2>
    <p {lang_attrs('demo_sub')}>{esc(t('demo_sub'))}</p>
    <a class="btn btn-w" href="#" onclick="openLead();return false" style="background:var(--red);color:#fff" {lang_attrs('demo_btn')}>{esc(t('demo_btn'))}</a>
  </div>
</section>

<footer class="footer" id="contact">
  <div class="container">
    <div>
      <h4 {lang_attrs('ft_brand')}>{esc(t('ft_brand'))}</h4>
      <p {lang_attrs('ft_brand_desc')}>{esc(t('ft_brand_desc'))}</p>
      <p style="margin-top:10px;font-size:12.5px;color:#64748b"><span {lang_attrs('ft_prep')}>{esc(t('ft_prep'))}</span> <b style="color:#cbd5e1">KRTaker</b> (A concern of BITSCOL)<br><span {lang_attrs('ft_market')}>{esc(t('ft_market'))}</span> <b style="color:#cbd5e1">Appvaley</b></p>
    </div>
    <div>
      <h4 {lang_attrs('ft_bro')}>{esc(t('ft_bro'))}</h4>
      <p style="margin-bottom:10px" {lang_attrs('ft_bro_sub')}>{esc(t('ft_bro_sub'))}</p>
      <a class="dl" href="brochure/mall_brochure.pdf" download style="display:inline-block;background:#fff;color:#7f1d1d;border-radius:10px;padding:9px 18px;font-weight:800;font-size:13px;margin-right:8px" {lang_attrs('ft_pdf')}>{esc(t('ft_pdf'))}</a>
      <a class="dl" href="brochure/mall_brochure.docx" download style="display:inline-block;background:rgba(255,255,255,.12);color:#fff;border-radius:10px;padding:9px 18px;font-weight:800;font-size:13px;border:1px solid #334155" {lang_attrs('ft_docx')}>{esc(t('ft_docx'))}</a>
    </div>
    <div>
      <h4 {lang_attrs('ft_links')}>{esc(t('ft_links'))}</h4>
      <p>
        <a href="#features" {lang_attrs('nav_features')}>{esc(t('nav_features'))}</a><br>
        <a href="#pricing" {lang_attrs('nav_pricing')}>{esc(t('nav_pricing'))}</a><br>
        <a href="#screens" {lang_attrs('g_title')}>{esc(t('g_title'))}</a><br>
        <a href="#faq" {lang_attrs('nav_faq')}>{esc(t('nav_faq'))}</a><br>
        <a href="#" onclick="openLead();return false" {lang_attrs('nav_demo')}>{esc(t('nav_demo'))}</a><br>
        <a href="https://krtaker.com" target="_blank" {lang_attrs('ft_krt_link')}>{esc(t('ft_krt_link'))}</a>
      </p>
    </div>
    <div>
      <h4 {lang_attrs('ft_contact')}>{esc(t('ft_contact'))}</h4>
      <p><span {lang_attrs('ft_line1')}>{esc(t('ft_line1'))}</span><br><span {lang_attrs('ft_line2')}>{esc(t('ft_line2'))}</span> Appvaley<br>
      📧 belal000bd@gmail.com<br>
      📱 +880 1711-853769<br>
      🌐 mall.krtaker.com</p>
    </div>
    <div class="bottom">© 2026 Mall Manager — KRTaker (A concern of BITSCOL) · Marketed by Appvaley. {esc(t('ft_rights'))}</div>
  </div>
</footer>

<a class="wa" href="https://wa.me/8801711853769?text=%E0%A6%86%E0%A6%AE%E0%A6%BF%20Mall%20Manager%20%E0%A6%B8%E0%A6%AE%E0%A7%8D%E0%A6%AA%E0%A6%B0%E0%A7%8D%E0%A6%95%E0%A7%87%20%E0%A6%9C%E0%A6%BE%E0%A6%A8%E0%A4%A4%E0%A7%87%20%E0%A6%9A%E0%A6%BE%E0%A6%87" target="_blank" data-i18n="wa" data-bn="💬 WhatsApp-এ কথা বলুন">💬 WhatsApp-এ কথা বলুন</a>

<!-- ═════ LEAD CAPTURE MODAL (demo gate) ═════ -->
<div id="leadModal" style="display:none;position:fixed;inset:0;z-index:200;background:rgba(15,23,42,.66);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:18px">
  <div style="background:#fff;border-radius:18px;max-width:440px;width:100%;padding:28px 26px;box-shadow:0 24px 60px rgba(0,0,0,.35);max-height:92vh;overflow:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
      <div style="font-size:20px;font-weight:900;color:var(--red)" data-i18n="lm_title" data-bn="🖥️ লাইভ ডেমো">🖥️ লাইভ ডেমো</div>
      <button onclick="closeLead()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--mut)">✕</button>
    </div>
    <p style="font-size:13px;color:var(--mut);margin-bottom:18px;line-height:1.8" data-i18n="lm_sub" data-bn="ডেমো অ্যাকাউন্টের লিংক ও লগইন তথ্য পেতে আপনার তথ্য দিন — SMS এর মাধ্যমে পাঠিয়ে দিচ্ছি।">ডেমো অ্যাকাউন্টের লিংক ও লগইন তথ্য পেতে আপনার তথ্য দিন — SMS এর মাধ্যমে পাঠিয়ে দিচ্ছি।</p>
    <form id="leadForm" onsubmit="submitLead(event)">
      <label style="font-size:12.5px;font-weight:800;color:var(--ink);display:block;margin-bottom:4px" data-i18n="lm_name" data-bn="আপনার নাম *">আপনার নাম *</label>
      <input id="leadName" type="text" required minlength="2" placeholder="রহিম" style="width:100%;padding:11px 13px;border:1.5px solid var(--bd);border-radius:10px;font-size:14px;margin-bottom:12px;font-family:inherit;outline:none">
      <label style="font-size:12.5px;font-weight:800;color:var(--ink);display:block;margin-bottom:4px" data-i18n="lm_mobile" data-bn="মোবাইল নম্বর *">মোবাইল নম্বর *</label>
      <input id="leadMobile" type="tel" required pattern="[0-9+\- ]{11,16}" placeholder="01XXXXXXXXX" style="width:100%;padding:11px 13px;border:1.5px solid var(--bd);border-radius:10px;font-size:14px;margin-bottom:12px;font-family:inherit;outline:none">
      <label style="font-size:12.5px;font-weight:800;color:var(--ink);display:block;margin-bottom:4px" data-i18n="lm_email" data-bn="ইমেইল">ইমেইল</label>
      <input id="leadEmail" type="email" placeholder="you@example.com" style="width:100%;padding:11px 13px;border:1.5px solid var(--bd);border-radius:10px;font-size:14px;margin-bottom:16px;font-family:inherit;outline:none">
      <button type="submit" id="leadBtn" style="width:100%;padding:13px;background:var(--red);color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer" data-i18n="lm_btn" data-bn="ডেমো তথ্য পাঠান 📲">ডেমো তথ্য পাঠান 📲</button>
    </form>
    <div id="leadDone" style="display:none;text-align:center">
      <div style="font-size:40px;margin-bottom:6px">✅</div>
      <div style="font-size:17px;font-weight:900;color:var(--ok);margin-bottom:6px" data-i18n="lm_done" data-bn="ধন্যবাদ! ডেমো তথ্য প্রস্তুত">ধন্যবাদ! ডেমো তথ্য প্রস্তুত</div>
      <p id="leadNote" style="font-size:13px;color:var(--mut);margin-bottom:14px;line-height:1.8" data-i18n="lm_note" data-bn="SMS পাঠানো হয়েছে। নিচের বাটনে ক্লিক করে ডেমো খুলুন।">SMS পাঠানো হয়েছে। নিচের বাটনে ক্লিক করে ডেমো খুলুন।</p>
      <div id="leadCreds" style="background:#f8fafc;border:1px dashed var(--bd);border-radius:12px;padding:12px 14px;font-size:13px;text-align:left;margin-bottom:16px;line-height:2"></div>
      <a id="leadGo" href="https://appvaley.com/mall/" target="_blank" style="display:inline-block;padding:13px 30px;background:var(--red);color:#fff;border-radius:12px;font-weight:800" data-i18n="lm_go" data-bn="ডেমোতে যান →">ডেমোতে যান →</a>
      <div style="margin-top:12px"><a href="#" onclick="closeLead();return false" style="font-size:12.5px;color:var(--mut)" data-i18n="lm_later" data-bn="পরে দেখব">পরে দেখব</a></div>
    </div>
    <div id="leadErr" style="display:none;margin-top:12px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;border-radius:10px;padding:10px 13px;font-size:13px"></div>
  </div>
</div>

<script>
var I18N = {{
{chr(10).join(f"  {k}: {{en: {json_dumps(en)} }}," for k, (bn, en) in C.items())}
}};
var lang = localStorage.getItem('mall_lang') || 'bn';
function applyLang() {{
  document.querySelectorAll('[data-i18n]').forEach(function(el) {{
    var k = el.getAttribute('data-i18n');
    var txt = lang === 'en' ? (I18N[k] && I18N[k].en || el.getAttribute('data-bn')) : el.getAttribute('data-bn');
    if (txt !== null) el.textContent = txt;
  }});
  document.documentElement.lang = lang === 'en' ? 'en' : 'bn';
  document.getElementById('langBtn').textContent = lang === 'en' ? 'বাংলা' : 'English';
}}
function toggleLang() {{ lang = lang === 'bn' ? 'en' : 'bn'; localStorage.setItem('mall_lang', lang); applyLang(); }}
applyLang();
function openLead(){{document.getElementById('leadModal').style.display='flex';document.getElementById('leadForm').style.display='block';document.getElementById('leadDone').style.display='none';document.getElementById('leadErr').style.display='none';document.getElementById('leadName').focus();}}
function closeLead(){{document.getElementById('leadModal').style.display='none';}}
document.getElementById('leadModal').addEventListener('click',function(e){{if(e.target.id==='leadModal')closeLead();}});
async function submitLead(ev){{
  ev.preventDefault();
  var err=document.getElementById('leadErr'),btn=document.getElementById('leadBtn');
  err.style.display='none';
  btn.disabled=true;btn.textContent= lang==='en' ? 'Sending…' : 'পাঠানো হচ্ছে…';
  var fd=new FormData();
  fd.append('name',document.getElementById('leadName').value.trim());
  fd.append('mobile',document.getElementById('leadMobile').value.trim());
  fd.append('email',document.getElementById('leadEmail').value.trim());
  fd.append('source','mall.krtaker.com');
  try{{
    var r=await fetch('lead.php',{{method:'POST',body:fd}});
    var j=await r.json();
    if(!j.ok){{err.textContent=j.error||(lang==='en'?'Something went wrong. Try again.':'দুঃখিত, কিছু ভুল হয়েছে। আবার চেষ্টা করুন।');err.style.display='block';btn.disabled=false;btn.textContent=lang==='en'?'Get Demo Info 📲':'ডেমো তথ্য পাঠান 📲';return;}}
    document.getElementById('leadForm').style.display='none';
    var c=j.creds||{{}};
    var note= lang==='en' ? 'Demo details sent to your number.' : 'আপনার নম্বরে ডেমো তথ্য পাঠানো হয়েছে।';
    if(j.sms&&!j.sms.ok)note= lang==='en' ? 'Demo mode — SMS is off; credentials shown below.' : 'ডেমো মোডে SMS বন্ধ আছে — লগইন তথ্য নিচে দেখানো হলো।';
    document.getElementById('leadNote').textContent=note;
    document.getElementById('leadCreds').innerHTML=
      '<b>'+ (lang==='en'?'Link:':'লিংক:') +'</b> <a href="'+(j.demo_url||'https://appvaley.com/mall/')+'" target="_blank">appvaley.com/mall</a><br>'+
      '<b>'+ (lang==='en'?'Login email:':'লগইন ইমেইল:') +'</b> '+(c.email||'—')+'<br><b>'+ (lang==='en'?'Password:':'পাসওয়ার্ড:') +'</b> '+(c.password||'—');
    document.getElementById('leadDone').style.display='block';
  }}catch(e){{
    err.textContent= lang==='en' ? 'Network error — try again.' : 'নেটওয়ার্ক সমস্যা — আবার চেষ্টা করুন।';
    err.style.display='block';
    btn.disabled=false;btn.textContent=lang==='en'?'Get Demo Info 📲':'ডেমো তথ্য পাঠান 📲';
  }}
}}
function calcRoi(){{
  var s=parseInt(document.getElementById('roiShops').value)||0;
  var b=parseInt(document.getElementById('roiBill').value)||0;
  var g=parseInt(document.getElementById('roiGain').value)||0;
  var month=Math.round(s*b*g/100);
  var year=month*12;
  document.getElementById('roiMonth').textContent='৳'+month.toLocaleString('en-IN');
  document.getElementById('roiYear').textContent='৳'+year.toLocaleString('en-IN');
  document.getElementById('roiOut').style.display='block';
}}
</script>
</body>
</html>'''

open('/tmp/mall_landing_v3.html', 'w').write(HTML)
print('v3 generated:', len(HTML), 'bytes')
