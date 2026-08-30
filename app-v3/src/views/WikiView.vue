<script setup>
import { ref, computed } from 'vue'
import { lang } from '../lib/i18n'
import hindFont from '../assets/HindSiliguri-Regular.ttf?inline'
import hindBoldFont from '../assets/HindSiliguri-Bold.ttf?inline'

const q = ref('')
const open = ref({})   // section id -> set of open item ids (or 'all')

// ── Mall Management wiki — accurate to the live system (v2.2 · Mall & Commercial Edition) ──
// Every item is bilingual: { bn, en }. Bodies are plain text (pre-wrap).
const SECTIONS = [
  {
    id: 'start', ico: '🚀',
    title: { bn: 'শুরু করা', en: 'Getting started' },
    items: [
      { id: 'overview', img: '05_module_map.png', cap: { bn: 'মল ম্যানেজার — মডিউল মানচিত্র (১৩টি মডিউল)', en: 'Mall Manager — module map (13 modules)' },
        t: { bn: 'সিস্টেম ওভারভিউ — মডিউল মানচিত্র', en: 'System overview — module map' }, tag: { bn: 'ম্যাপ', en: 'map' },
        b: { bn: `মল ম্যানেজারের সব মডিউল এক নজরে — দোকান ও মালিক, বিলিং, মিটার রিডিং, আদায়, হিসাব, এসএমএস, ভাড়াটিয়া, সম্পদ, নোটিশ, অডিট, অফলাইন সিঙ্ক ও সেটিংস।

প্রতিটি মডিউলের বিস্তারিত নিচের সেকশনে ধাপে ধাপে।`, en: `All Mall Manager modules at a glance — spaces & owners, billing, meter readings, collections, accounting, SMS, tenants, assets, notices, audit, offline sync and settings.

Each module is covered step-by-step in the sections below.` } },
      { id: 'login', notes: [{ type: 'info', bn: 'প্রতিটি ভূমিকা আলাদা কী দেখে — কালেক্টর শুধু আদায়/রিডিং, হিসাবরক্ষক হিসাব, সুপার অ্যাডমিন সবকিছু।', en: 'Each role sees different things — collector only collections/readings, accountant accounting, superadmin everything.' }], t: { bn: 'লগইন — কারা কী দেখতে পান', en: 'Logging in — who sees what' }, tag: { bn: 'ভূমিকা', en: 'roles' },
        b: { bn: `মল ম্যানেজারে ৫ ধরনের ব্যবহারকারী থাকে:

• সুপার অ্যাডমিন (superadmin@razzakplaza.com) — সবকিছু: সেটিংস, অনুমোদন, অডিট
• মালিক/কমিটি (owner@krtaker.com) — সব রিপোর্ট + আদায় + বিল
• ম্যানেজার (manager@razzakplaza.com) — দৈনন্দিন পরিচালনা, রিডিং, আদায়
• হিসাবরক্ষক (accountant@razzakplaza.com) — হিসাব, জার্নাল, স্টেটমেন্ট
• কালেক্টর (collector@krtaker.com) — শুধু আদায় ও রিডিং (লিমিটেড পড়ার অনুমতি)

ডেমো পাসওয়ার্ডগুলো ডেমো লগইন স্ক্রিনে দেখানো হয়।`, en: `Mall Manager has 5 user roles:

• Super admin (superadmin@razzakplaza.com) — everything: settings, approvals, audit
• Owner/committee (owner@krtaker.com) — all reports + collections + billing
• Manager (manager@razzakplaza.com) — day-to-day ops, readings, collections
• Accountant (accountant@razzakplaza.com) — accounting, journal, statements
• Collector (collector@krtaker.com) — collections & readings only (limited read access)

Demo passwords are shown on the demo login screen.` } },
      { id: 'ui', t: { bn: 'ইন্টারফেস পরিচিতি', en: 'The interface' }, tag: { bn: 'টিপস', en: 'tips' },
        b: { bn: `বাম দিকে সাইডবার (ডেস্কটপ) — সব ট্যাব সেখানে। মোবাইলে সাইডবার হ্যামবার্গারে যায়।

উপরে:
• মাস পিকার (◀ মাস ▶) — যেকোনো মাসের বিল/আদায় দেখুন
• 🔍 সার্চ — দ্রুত খুঁজুন
• বাংলা/English টগল — পুরো অ্যাপের ভাষা
• 🌙 ডার্ক মোড

যেকোনো দোকান/মালিক/ভাড়াটিয়ার নামে ক্লিক করলে তার ড্রয়ার (বিস্তারিত প্যানেল) খোলে।`, en: `Sidebar on the left (desktop) — every tab lives there. On mobile the sidebar moves behind the hamburger.

Top bar:
• Month picker (◀ month ▶) — jump to any month's bills/collections
• 🔍 search — quick find
• বাংলা/English toggle — whole app language
• 🌙 dark mode

Click any shop/owner/tenant name anywhere — its detail drawer slides open.` } },
      { id: 'dash', see: [{ s: 'payments', i: 'history', l: { bn: '📅 আজকের আদায় ও পেমেন্ট ইতিহাস', en: "📅 Today's collections & payment history" } }, { s: 'alerts', i: 'alert-types', l: { bn: '🔔 এলার্টের ধরন', en: '🔔 Alert types' } }], img: '01_dashboard.jpg', cap: { bn: 'ড্যাশবোর্ড — আদায়, বকেয়া, এলার্ট ও ব্যালেন্স', en: 'Dashboard — collections, dues, alerts & balances' }, t: { bn: 'ড্যাশবোর্ড — এক নজরে মল', en: 'Dashboard — the mall at a glance' }, tag: { bn: 'রিপোর্ট', en: 'reports' },
        b: { bn: `ড্যাশবোর্ডে দেখা যায়:

• 💵 এই মাসের আদায় + % বিলের (of billed)
• ⏳ এই মাসের বকেয়া + অপরিশোধিত বিল সংখ্যা
• 📅 আজকের আদায় — আজ কত টাকা উঠেছে (সার্ভিস + ভাড়া)
• ⚠️ আজ পর্যন্ত মোট বকেয়া — সব মাসের অপরিশোধিত বিলের যোগফল
• ⛔/🚨/🛠️ এলার্ট ব্যানার — সংযোগ বিচ্ছিন্ন ঝুঁকি, উচ্চ বকেয়া, এএমসি মেয়াদ
• 💰 প্রতি অ্যাকাউন্টের ব্যালেন্স (ক্যাশ, ব্যাংক, বিকাশ…)
• 🚨 শীর্ষ খেলাপি, 📉 ব্যয় ক্যাটাগরি, 🕘 সাম্প্রতিক আদায়`, en: `The dashboard shows:

• 💵 this month's collections + % of billed
• ⏳ this month's outstanding + unpaid bill count
• 📅 today's collections — how much came in today (service + rent)
• ⚠️ all dues till today — every unpaid bill across all months
• ⛔/🚨/🛠️ alert banners — disconnection risk, high dues, AMC expiry
• 💰 per-account balances (cash, bank, bKash…)
• 🚨 top defaulters, 📉 expense categories, 🕘 recent collections` } },
    ],
  },
  {
    id: 'spaces', ico: '🏪',
    title: { bn: 'দোকান ও মালিক', en: 'Spaces & owners' },
    items: [
      { id: 'space-add', t: { bn: 'দোকান যোগ / সম্পাদনা', en: 'Add / edit a space' }, tag: { bn: 'সেটআপ', en: 'setup' },
        b: { bn: `দোকান ট্যাবে "＋ দোকান যোগ করুন":

• দোকান নং (যেমন A-102), ফ্লোর, স্ট্যাটাস (Active/Vacant/Closed)
• মালিক — সার্চ করে বাছুন (নতুন হলে আগে মালিক তৈরি করুন)
• সার্ভিস রেট (৳/বর্গফুট) — মাসিক সার্ভিস বিল এর উপর ভিত্তি করে

দোকানের আইডি টেক্সট (SH-001) — সংখ্যা নয়, তাই সতর্ক থাকুন।`, en: `In the Spaces tab: "＋ Add space":

• Space no (e.g. A-102), floor, status (Active/Vacant/Closed)
• Owner — search & pick (create the owner first if new)
• Service rate (৳/sqft) — the monthly service bill is based on this

Space ids are TEXT (SH-001), not numbers.` } },
      { id: 'owner-profile', see: [{ s: 'spaces', i: 'owner-portal', l: { bn: '🔑 মালিক পোর্টাল', en: '🔑 Owner portal' } }], t: { bn: 'মালিক প্রোফাইল (কে.আর.টেকার-স্টাইল)', en: 'Owner profiles (KRTaker-style)' }, tag: { bn: 'প্রোফাইল', en: 'profile' },
        b: { bn: `মালিক/কোম্পানি প্রোফাইলে বিস্তারিত তথ্য থাকে:

• ধরন — ব্যক্তি / কর্পোরেট / কোম্পানি
• বাবা ও মায়ের নাম, এনআইডি, ফোন, ইমেইল, জরুরি যোগাযোগ
• বর্তমান + স্থায়ী ঠিকানা, শহর, যুক্ত হওয়ার তারিখ
• ব্যবসার নাম, পেশা, ট্যাগ
• পরিবারের সদস্য (নাম ও সম্পর্ক), কোম্পানির প্রোফাইল (লাইসেন্স ইত্যাদি)
• নোট

যেকোনো জায়গায় মালিকের নামে ক্লিক → ড্রয়ারে পুরো প্রোফাইল দেখুন, ✏️ এডিট ট্যাবে সম্পাদনা করুন।`, en: `Owner/company profiles carry full detail:

• Type — Individual / Corporate / Company
• Father & mother names, NID, phone, email, emergency contact
• Present + permanent address, city, joined date
• Business name, occupation, tags
• Family members (name & relation), company profile (license etc.)
• Notes

Click any owner name anywhere → full profile drawer, ✏️ edit tab inside.` } },
      { id: 'owner-portal', notes: [{ type: 'link', bn: 'https://appvaley.com/mall/#/portal — মালিক পোর্টাল লিংক', en: 'https://appvaley.com/mall/#/portal — owner portal link', url: 'https://appvaley.com/mall/#/portal' }], t: { bn: 'মালিক পোর্টাল', en: 'Owner portal' }, tag: { bn: 'পোর্টাল', en: 'portal' },
        b: { bn: `মালিকেরা আলাদা পোর্টালে লগইন করে নিজেদের দোকান, বিল ও রসিদ দেখতে পারেন।

লগইন স্ক্রিনে "মালিক পোর্টাল খুলুন →" লিংকে যান (যেমন rahim@razzakplaza.com)।

পোর্টালে: নিজের দোকানের তালিকা, মাসিক বিল, পরিশোধের রসিদ, বকেয়া।`, en: `Owners log into a separate portal to see their own spaces, bills and receipts.

Use the "Owner portal →" link on the login screen (e.g. rahim@razzakplaza.com).

The portal shows: their spaces, monthly bills, payment receipts, dues.` } },
      { id: 'deep-links', notes: [{ type: 'tip', bn: 'নীল রঙের নামই ক্লিকযোগ্য — হোভারে আন্ডারলাইন আসে। টেবিলের সারি ক্লিক থেকে আলাদা করতে নামে ক্লিক করুন।', en: 'Names in blue are clickable — underline appears on hover. Click the name (not the row) to open the drawer.' }], t: { bn: 'যেকোনো নামে ক্লিক → ড্রয়ার', en: 'Click any name → drawer' }, tag: { bn: 'নেভিগেশন', en: 'navigation' },
        b: { bn: `সব জায়গায় এন্টিটির নাম ক্লিকযোগ্য (নীল রঙ, হোভারে আন্ডারলাইন):

• 🏪 দোকান নং → দোকানের ড্রয়ার (ড্যাশবোর্ড, বিল, ইনভয়েস, পেমেন্ট, চুক্তি…)
• 👤 মালিকের নাম → মালিকের ড্রয়ার
• 🧑🤝🧑 ভাড়াটিয়ার নাম → ভাড়াটিয়ার ড্রয়ার

পুরনো ডেটাতে ভাড়াটিয়ার নাম আইডি কলামে থাকতে পারে — সিস্টেম নিজে মিলিয়ে নেয়।`, en: `Entity names are clickable everywhere (blue, underline on hover):

• 🏪 shop no → space drawer (dashboard, bills, invoices, payments, agreements…)
• 👤 owner name → owner drawer
• 🧑🤝🧑 tenant name → tenant drawer

Legacy data may store tenant NAMES in the id column — the system resolves it automatically.` } },
    ],
  },
  {
    id: 'billing', ico: '🧾',
    title: { bn: 'বিলিং', en: 'Billing' },
    items: [
      { id: 'bill-model', img: '04_bill_form.jpg', cap: { bn: 'বিল ফর্ম — সার্ভিস, বিদ্যুৎ ও পানি', en: 'Bill form — service, electricity & water' }, t: { bn: 'বিলের মডেল — সার্ভিস + ইউটিলিটি', en: 'Bill model — service + utilities' }, tag: { bn: 'মডেল', en: 'model' },
        b: { bn: `প্রতি দোকানের মাসিক বিলে ৩টি খাত থাকতে পারে:

• 🧾 সার্ভিস চার্জ — দোকানের আয়তন (বর্গফুট) × সার্ভিস রেট
• ⚡ বিদ্যুৎ — সাব-মিটার ইউনিট × প্রতি ইউনিট রেট
• 💧 পানি — সাব-মিটার ইউনিট × প্রতি ইউনিট রেট
• ⚠️ ফাইন — নির্ধারিত তারিখের পরে দেরি হলে

বিদ্যুৎ/পানি আদায় মলের নিজস্ব আয় (spec 3.3) — DESCO/WASA বিল একই লেজারে ব্যয়।`, en: `Each space's monthly bill can have 3 lines:

• 🧾 Service charge — space size (sqft) × service rate
• ⚡ Electricity — sub-meter units × per-unit rate
• 💧 Water — sub-meter units × per-unit rate
• ⚠️ Fine — when paid after the due date

Electricity/water collections are the mall's OWN income (spec 3.3) — DESCO/WASA bills are expenses in the same ledger.` } },
      { id: 'auto-bill', see: [{ s: 'meters', i: 'shop-info', l: { bn: '💡 দোকানের তথ্য প্যানেল', en: '💡 Space info panel' } }], t: { bn: 'মিটার রিডিং → অটো বিল', en: 'Meter reading → auto bill' }, tag: { bn: '⚡', en: '⚡' },
        b: { bn: `⚡ সাব-মিটার রিডিং ট্যাবে:

1. দোকান বাছুন — সাথে সাথে 💡 এই মাসের বিল, ⚠️ বকেয়া ও 📜 ৬ মাসের ইতিহাস দেখাবে
2. ধরন (বিদ্যুৎ/পানি), মাস, রিডিং সংখ্যা দিন (আগের রিডিং অটো বসে)
3. 💾 Save — বিল অটো তৈরি হয়ে যাবে

ইউনিট = বর্তমান রিডিং − আগের রিডিং। রিডিং ২০০%+ বেড়ে গেলে অস্বাভাবিকতা ফ্ল্যাগ আসে।`, en: `In the ⚡ Sub-meter reading tab:

1. Pick a space — the 💡 this-month bill, ⚠️ dues and 📜 6-month history appear instantly
2. Type (electricity/water), month, reading value (previous reading auto-filled)
3. 💾 Save — the bill is generated automatically

Units = current reading − previous reading. Readings jumping 200%+ are flagged as anomalies.` } },
      { id: 'rate-calc', t: { bn: 'কার্যকর বিদ্যুৎ রেট ক্যালকুলেটর', en: 'Effective electricity rate calculator' }, tag: { bn: 'সেটিংস', en: 'settings' },
        b: { bn: `DESCO মূল বিল (৳) ÷ মোট সাব-মিটার ইউনিট = কার্যকর রেট/ইউনিট।

সেটিংস → ⚡ ইউটিলিটি কস্টিং-এ DESCO বিল ও মোট ইউনিট দিলে ক্যালকুলেটর:
• কার্যকর রেট দেখায়
• +২%/+৫%/+১০% মার্জিনসহ প্রস্তাবিত রেট দেয়
• "Use" বাটনে ক্লিক করলেই রেট সেট হয়ে যায়

উদাহরণ: ৳৪৫,০০০ ÷ ৯,১৭৩ ইউনিট = ৳৪.৯১ → ৳৫/ইউনিট।`, en: `DESCO main bill (৳) ÷ total sub-meter units = effective rate/unit.

In Settings → ⚡ Utility costing, enter the DESCO bill + total units:
• shows the effective rate
• suggests rates with +2%/+5%/+10% margin
• "Use" sets the rate instantly

Example: ৳45,000 ÷ 9,173 units = ৳4.91 → ৳5/unit.` } },
      { id: 'combined-bill', see: [{ s: 'billing', i: 'bill-templates', l: { bn: '🖨️ প্রিন্ট টেমপ্লেট ও PDF', en: '🖨️ Print templates & PDF' } }, { s: 'payments', i: 'receipts', l: { bn: '🧾 রসিদ (RCT-)', en: '🧾 Receipts (RCT-)' } }], img: '07_invoice_preview.jpg', cap: { bn: 'ইনভয়েস প্রিভিউ — বিল-প্যাড লেআউট', en: 'Invoice preview — bill-pad layout' }, t: { bn: 'কম্বাইন্ড বিল — বিল-প্যাড প্রিন্ট', en: 'Combined bill — bill-pad print' }, tag: { bn: '🖨️', en: '🖨️' },
        b: { bn: `ইনভয়েস ট্যাব → 🖨️ — কাগজের বিল-প্যাডের মতো প্রিন্ট:

• লাল ব্যানার: বিদ্যুৎ/সার্ভিস চার্জ এবং অন্যান্য বিল, নং- ও মাস:
• ১০টি ডটেড লাইন: ক্রেতার নাম, দোকান নং, রিডিং+তারিখ, ব্যবহৃত ইউনিট, দোকানের আয়তন, প্রতি ইউনিটের মূল্য, সেবা চার্জ, বিল ইস্যু/পরিশোধ তারিখ, বকেয়া, বিবিধ, মোট টাকা
• নির্দেশনা (২০ তারিখের মধ্যে পরিশোধ, লাইন বিচ্ছিন্ন ইত্যাদি)
• স্বাক্ষর: বিল প্রস্তুতকারী · সাধারণ সম্পাদক

প্রপার্টির নাম ও ঠিকানা সেটিংস থেকে আসে।`, en: `Invoices tab → 🖨️ — prints like the physical bill pad:

• red band: বিদ্যুৎ/সার্ভিস চার্জ এবং অন্যান্য বিল with নং- and মাস:
• 10 dotted fields: buyer name, shop no, readings+dates, units used, space size, per-unit price, service charge, issue/due dates, arrears, miscellaneous, total
• instructions (pay by the 20th, line disconnection…)
• signatures: বিল প্রস্তুতকারী · সাধারণ সম্পাদক

Property name & address come from Settings.` } },
      { id: 'bill-templates', t: { bn: 'প্রিন্ট টেমপ্লেট ও পিডিএফ', en: 'Print templates & PDF' }, tag: { bn: '🖨️', en: '🖨️' },
        b: { bn: `প্রতিটি ইনভয়েসে টেমপ্লেট পিকার আছে:

• A4 · বর্তমান (পোর্ট্রেট)
• A5 (পোর্ট্রেট/ল্যান্ডস্কেপ)
• ½+½ A4 — এক পাতায় দুইটি বিল (পোর্ট্রেট/ল্যান্ডস্কেপ)

⬇ পিডিএফ — এক ক্লিকে PDF ডাউনলোড (সঠিক সাইজে রেন্ডার হয়)।
👁 খাতভিত্তিক টগল — আইটেমাইজড টেবিল দেখুন।
মোবাইলে কমপ্যাক্ট ভিউ অটো।`, en: `Every invoice has a template picker:

• A4 · current (portrait)
• A5 (portrait/landscape)
• ½+½ A4 — two bills on one sheet (portrait/landscape)

⬇ PDF — one-click PDF download (rendered at the right size).
👁 itemized toggle — show the line-item table.
Compact view on mobile automatically.` } },
      { id: 'dues-fines', t: { bn: 'বকেয়া, ফাইন ও ঝুঁকি ব্যাজ', en: 'Dues, fines & risk badges' }, tag: { bn: 'বকেয়া', en: 'dues' },
        b: { bn: `• বিলের নির্ধারিত তারিখের পরে ফাইন যোগ হয় (সেটিংসে নিয়ম)
• বিল ট্যাবে অপরিশোধিত বিলের পাশে ⛔ সংযোগ বিচ্ছিন্ন ঝুঁকি ব্যাজ (নির্ধারিত মাস ধরে বকেয়া)
• ড্যাশবোর্ডে ⚠️ আজ পর্যন্ত মোট বকেয়া — সব মাস মিলিয়ে কত বাকি

সেটিংস → বিলিং-এ থ্রেশহোল্ড (high_dues_months / disconnect_months) বদলানো যায়।`, en: `• Late fee applies after the bill's due date (rule in Settings)
• Unpaid bills carry a ⛔ disconnection-risk badge after the configured months
• Dashboard ⚠️ all-dues-till-today — everything owed across all months

Settings → Billing lets you change the thresholds (high_dues_months / disconnect_months).` } },
    ],
  },
  {
    id: 'payments', ico: '💳',
    title: { bn: 'আদায় ও পেমেন্ট', en: 'Collections & payments' },
    items: [
      { id: 'collect-flow', see: [{ s: 'payments', i: 'history', l: { bn: '📜 পেমেন্ট ইতিহাস', en: '📜 Payment history' } }, { s: 'payments', i: 'void-waiver', l: { bn: '🔒 ভয়েড ও মওকুফ', en: '🔒 Void & waiver' } }], imgs: ['03_collect_step1.jpg', '04_collect_pay.jpg'], cap: { bn: 'ধাপ ১: দোকান ও বকেয়া → ধাপ ২: পেমেন্ট ফর্ম', en: 'Step 1: space & dues → Step 2: payment form' }, t: { bn: 'আদায় প্রক্রিয়া (২ ধাপ)', en: 'The collect flow (2 steps)' }, tag: { bn: '💵', en: '💵' },
        b: { bn: `পেমেন্ট ট্যাব → 💵 আদায় করুন:

ধাপ ১: দোকান বাছুন — সাথে সাথে ⚠️ মোট বকেয়া + কোন মাসের বিল বাকি দেখায়, নিচে অপরিশোধিত বিলের তালিকা
ধাপ ২: বিলে ক্লিক → আধুনিক পেমেন্ট ফর্ম:
• বিল সারাংশ (খাত, স্ট্যাটাস, মাস, বিল নং, নির্ধারিত তারিখ, মালিকের ফোন, ফাইনসহ মোট)
• প্রদানকারী (মালিক) — আগে থেকে বসানো, বদলানো যায়
• পরিমাণ + সম্পূর্ণ/½ কুইক বাটন (আংশিক আদায়)
• পদ্ধতি (ক্যাশ/ব্যাংক/বিকাশ/নগদ) + নির্দিষ্ট অ্যাকাউন্ট
• রেফারেন্স (ট্রানজেকশন নং/নোট)
💾 সংরক্ষণ → রসিদ অটো খোলে (প্রিন্ট/PDF)।`, en: `Payments tab → 💵 Collect:

Step 1: pick a space — ⚠️ total dues + which months are unpaid appear instantly, then the unpaid-bill list
Step 2: click a bill → advanced payment form:
• bill summary (kind, status, month, bill #, due date, owner phone, total incl. fine)
• payer (owner) — pre-filled, editable
• amount + Full/½ quick buttons (partial collection)
• method (cash/bank/bKash/Nagad) + exact account
• reference (trx no / note)
💾 Save → the receipt auto-opens (print/PDF).` } },
      { id: 'methods', t: { bn: 'পেমেন্ট পদ্ধতি ও অ্যাকাউন্ট', en: 'Payment methods & accounts' }, tag: { bn: '🏦', en: '🏦' },
        b: { bn: `প্রতিটি আদায়ে পদ্ধতি + নির্দিষ্ট হিসাব:

• 💵 ক্যাশ → 1010 ক্যাশ ইন হ্যান্ড
• 🏦 ব্যাংক → 1020 ব্যাংক (ডিফল্ট), 1021 ব্র্যাক ব্যাংক, 1022 ইবিএল
• 📱 বিকাশ → 1030 বিকাশ, 1031 বিকাশ (বিজনেস)
• 📱 নগদ → 1032 নগদ

প্রতিটি পেমেন্ট রো-তে method_acct সেভ হয় — রসিদে "ব্যাংক · ব্র্যাক ব্যাংক অ্যাকাউন্ট" দেখায়। ডাবল-এন্ট্রি জার্নালে সঠিক অ্যাকাউন্টে যায়।`, en: `Every collection records method + exact account:

• 💵 Cash → 1010 Cash in Hand
• 🏦 Bank → 1020 Bank (default), 1021 Brac Bank, 1022 EBL
• 📱 bKash → 1030 bKash, 1031 bKash (Business)
• 📱 Nagad → 1032 Nagad

Each payment row stores method_acct — receipts show "bank · Brac Bank Account". The double-entry journal posts to the exact account.` } },
      { id: 'receipts', img: '02_invoices.jpg', cap: { bn: 'ইনভয়েস ও রসিদ তালিকা', en: 'Invoices & receipts list' }, t: { bn: 'রসিদ (RCT-)', en: 'Receipts (RCT-)' }, tag: { bn: '🧾', en: '🧾' },
        b: { bn: `প্রতিটি আদায়ে রসিদ নম্বর RCT-YYYYMM-#### (সেটিংসে প্রিফিক্স/সিকোয়েন্স বদলানো যায়)।

রসিদে: দোকান, পরিশোধকারী, বিলের খাত, পরিমাণ, পদ্ধতি + নির্দিষ্ট অ্যাকাউন্ট, রসিদ নং, তারিখ, মলের নাম/লোগো।

🖨️ প্রিন্ট / ⬇ PDF — ইনভয়েস টেমপ্লেটের মতো (A4/A5/½+½)।

ভাড়া আদায়ের রসিদ RNT-YYYYMM-####।`, en: `Every collection gets receipt no RCT-YYYYMM-#### (prefix/sequence configurable in Settings).

Receipt shows: space, payer, bill line, amount, method + exact account, receipt no, date, mall name/logo.

🖨️ print / ⬇ PDF — same templates as invoices (A4/A5/½+½).

Rent collections use RNT-YYYYMM-####.` } },
      { id: 'history', see: [{ s: 'start', i: 'dash', l: { bn: '📊 ড্যাশবোর্ড — আজকের আদায়', en: "📊 Dashboard — today's collections" } }], img: '05_payment_history.jpg', cap: { bn: 'দোকান অনুযায়ী পেমেন্ট ইতিহাস', en: 'Per-space payment history' }, t: { bn: 'পেমেন্ট ইতিহাস — দোকান অনুযায়ী', en: 'Payment history — per space' }, tag: { bn: '📜', en: '📜' },
        b: { bn: `পেমেন্ট ট্যাবে দোকান ড্রপ-ডাউন থেকে যেকোনো দোকান বাছলে বর্তমান টেবিলের নিচে 📜 পেমেন্টের ইতিহাস দেখায় — সব মাসের (সার্ভিস + ভাড়া) রসিদ, তারিখ, মাস, পরিশোধকারী, পদ্ধতি, পরিমাণ, স্ট্যাটাস + মোট ও নিট।

একই জায়গায় 📅 আজকের আদায় ও ⚠️ আজ পর্যন্ত মোট বকেয়া কার্ডও আছে।`, en: `In the Payments tab, pick a space from the drop-down and a 📜 payment-history panel appears under the current table — all months (service + rent): receipt, date, month, payer, method, amount, status + total & net.

The same strip shows 📅 today's collections and ⚠️ all-dues-till-today cards.` } },
      { id: 'void-waiver', t: { bn: 'ভয়েড ও মওকুফ (অনুমোদন ফ্লো)', en: 'Void & waiver (approval flow)' }, tag: { bn: '🔒', en: '🔒' },
        b: { bn: `ভুল আদায় হলে:

• 🔒 ভয়েড রিকোয়েস্ট — কারণসহ পাঠান → অ্যাডমিন/কমিটি অনুমোদন করলে রসিদ লক হয়ে বিপরীত জার্নাল (VOID-) হয়
• মওকুফ (ওয়েভার) — ফাইন/বিল মওকুফের জন্য দুই-স্তরের অনুমোদন (WAV-)

অনুমোদিত না হওয়া পর্যন্ত পেমেন্ট "পেন্ডিং" থাকে; সব সাম-এ বাতিলকৃত বাদ পড়ে (নিট দেখায়)।`, en: `For mistaken collections:

• 🔒 Void request — send with reason → admin/committee approves → the receipt is locked and a reversal journal (VOID-) posts
• Waiver — two-level approval to waive fines/bills (WAV-)

Payments stay "Pending" until approved; voided ones are excluded from every sum (net shown).` } },
    ],
  },
  {
    id: 'accounting', ico: '📊',
    title: { bn: 'হিসাব', en: 'Accounting' },
    items: [
      { id: 'coa', see: [{ s: 'accounting', i: 'journal', l: { bn: '📖 জার্নাল ভাউচার', en: '📖 Journal voucher' } }, { s: 'accounting', i: 'reports', l: { bn: '📊 রিপোর্ট', en: '📊 Reports' } }], img: '06_coa.jpg', cap: { bn: 'চার্ট অব অ্যাকাউন্টস — মাল্টি-লেভেল ট্রি', en: 'Chart of Accounts — multi-level tree' }, t: { bn: 'চার্ট অব অ্যাকাউন্টস (মাল্টি-লেভেল)', en: 'Chart of Accounts (multi-level)' }, tag: { bn: '📊', en: '📊' },
        b: { bn: `হিসাব → 🏦 চার্ট অব অ্যাকাউন্টস:

• গ্রুপ হেডিং (সম্পদ, দায়, মালিকানা, আয়, ব্যয়) + সাব-গ্রুপ (স্থায়ী সম্পদ ইত্যাদি) — ▸/▾ কোলাপ্স
• গ্রুপ রো-তে রিকার্সিভ সাবটোটাল (ডেবিট/ক্রেডিট/ব্যালেন্স)
• সার্চ + Active-অনলি ফিল্টার
• 🧩 গ্রুপ টগল + প্যারেন্ট পিকার — নতুন অ্যাকাউন্ট/গ্রুপ তৈরি
• শুধু লিফ (পোস্টিং) অ্যাকাউন্টে জার্নাল পোস্ট হয় — গ্রুপে পোস্ট করা নিষেধ, গ্রুপ ডিলিটও guarded

হিসাব কোড: 1000 Assets, 1100 Fixed Assets, 1010 Cash, 1020 Bank…`, en: `Accounting → 🏦 Chart of Accounts:

• Group headings (Assets, Liabilities, Equity, Income, Expenses) + sub-groups (Fixed Assets…) — ▸/▾ collapse
• Group rows roll up recursive subtotals (debit/credit/balance)
• Search + Active-only filter
• 🧩 Group toggle + parent picker — create accounts/groups
• Only leaf (posting) accounts accept journal entries — posting to a group is rejected, groups with children can't be deleted

Codes: 1000 Assets, 1100 Fixed Assets, 1010 Cash, 1020 Bank…` } },
      { id: 'journal', notes: [{ type: 'warn', bn: 'গ্রুপ অ্যাকাউন্টে (যেমন 1000 Assets) পোস্ট করা যাবে না — সবসময় লিফ অ্যাকাউন্ট বাছুন।', en: 'You cannot post to a group account (e.g. 1000 Assets) — always pick a leaf account.' }], img: '08_journal.jpg', cap: { bn: 'জার্নাল ভাউচার — ডাবল এন্ট্রি', en: 'Journal voucher — double entry' }, t: { bn: 'জার্নাল ভাউচার (ডাবল এন্ট্রি)', en: 'Journal voucher (double entry)' }, tag: { bn: '📖', en: '📖' },
        b: { bn: `হিসাব → 📖 জার্নাল:

• ডেবিট/ক্রেডিট লাইন যোগ করুন — অ্যাকাউন্ট পিকার সার্চযোগ্য (লিফ-অনলি, শ্রেণিবিন্যাস পথসহ: "1010 — Cash in Hand · Assets ›")
• পার্টি সাব-লেজার (মালিক/ভাড়াটিয়া/সরবরাহকারী/স্টাফ) — subsidiary অ্যাকাউন্টে
• টোটাল ডেবিট = টোটাল ক্রেডিট (ব্যালেন্সড ভাউচার) — পোস্ট হলে লেজার আপডেট

JV-XXXX নম্বর প্যাটার্ন; OB-001 ওপেনিং ব্যালেন্স।`, en: `Accounting → 📖 Journal:

• add debit/credit lines — the account picker is searchable (leaf-only, with the hierarchy path: "1010 — Cash in Hand · Assets ›")
• party sub-ledgers (owner/tenant/vendor/staff) on subsidiary accounts
• total debit = total credit (balanced voucher) — posts to ledgers on save

JV-XXXX numbering; OB-001 for opening balances.` } },
      { id: 'ledger', t: { bn: 'পার্টি লেজার / স্টেটমেন্ট', en: 'Party ledger / statements' }, tag: { bn: '🧾', en: '🧾' },
        b: { bn: `হিসাব → 🧾 পার্টি লেজার / 💰 স্টেটমেন্ট:

• ধরন বাছুন (মালিক/ভাড়াটিয়া/সরবরাহকারী/স্টাফ) → পার্টি বাছুন
• তারিখের রেঞ্জ দিন
• স্টেটমেন্ট: প্রারম্ভিক ব্যালেন্স → লেনদেন → সমাপনী ব্যালেন্স
• ⬇ CSV / 🖨️ প্রিন্ট

কোনো পার্টির নামে ক্লিক করলেই তার ড্রয়ার খোলে।`, en: `Accounting → 🧾 Party ledger / 💰 Statements:

• pick a type (owner/tenant/vendor/staff) → pick the party
• set a date range
• statement: opening balance → transactions → closing balance
• ⬇ CSV / 🖨️ print

Click any party name and its drawer opens.` } },
      { id: 'reports', t: { bn: 'ট্রায়াল ব্যালেন্স, লাভ-ক্ষতি, ক্যাশফ্লো', en: 'Trial balance, P&L, cashflow' }, tag: { bn: '📊', en: '📊' },
        b: { bn: `হিসাব ট্যাবে:

• ⚖️ ট্রায়াল ব্যালেন্স — সব অ্যাকাউন্টের ডেবিট/ক্রেডিট
• 📊 লাভ-ক্ষতি বিবরণী — আয় (সার্ভিস + ইউটিলিটি) বনাম ব্যয় (DESCO/WASA সহ) — ইউটিলিটি নিজস্ব-আয় মডেলে
• 🔄 ক্যাশফ্লো — প্রতি অ্যাকাউন্টের আয়/ব্যয়/ব্যালেন্স কার্ড + মোট
• 🔁 রিকনসিলিয়েশন — ইউটিলিটি আয়-ব্যয় P/L + ব্যাংক/ক্যাশ ভার্ডিক্ট (মিল ✅ / ঘাটতি ⚠️)`, en: `In the Accounting group:

• ⚖️ Trial balance — all accounts' debits/credits
• 📊 P&L — income (service + utility) vs expenses (incl. DESCO/WASA) — the own-income utility model
• 🔄 Cashflow — per-account in/out/balance cards + total
• 🔁 Reconciliation — utility income-vs-cost P/L + cash/bank verdict (✅ reconciled / ⚠️ short or over)` } },
      { id: 'bank-import', notes: [{ type: 'tip', bn: 'CSV কলাম অটো-ডিটেক্ট হয় — তারিখ, বিবরণ, আউট/ইন, ব্যালেন্স। ৳, কমা ও (123) নেগেটিভ সাপোর্টেড।', en: 'CSV columns are auto-detected — date, description, out/in, balance. ৳, commas and (123) negatives supported.' }], t: { bn: 'ব্যাংক স্টেটমেন্ট ইমপোর্ট', en: 'Bank statement import' }, tag: { bn: '🏦', en: '🏦' },
        b: { bn: `রিকনসিলিয়েশন → 📥 ব্যাংক স্টেটমেন্ট ইমপোর্ট:

1. অ্যাকাউন্ট বাছুন → CSV ফাইল আপলোড (তারিখ, বিবরণ, আউট/ইন, ব্যালেন্স কলাম অটো-ডিটেক্ট; ৳/কমা/ব্র্যাকেট নেগেটিভ হ্যান্ডেল করে)
2. প্রিভিউ — প্রতি লাইনে ম্যাচ ব্যাজ (পরিমাণ + ±৩ দিনের উইন্ডোতে বইয়ের সাথে মিলিয়ে)
3. ইমপোর্ট — ম্যাচ করা লাইনে সিস্টেম রেফারেন্স স্ট্যাম্প
4. ভার্ডিক্ট: স্টেটমেন্ট ব্যালেন্স vs বই — ✅/⚠️ + CSV এক্সপোর্ট/প্রিন্ট

প্রতি ব্যাচ 🗑️ ডিলিট করা যায়।`, en: `Reconciliation → 📥 Bank statement import:

1. pick an account → upload a CSV (date, description, out/in, balance columns auto-detected; ৳/commas/bracket negatives handled)
2. Preview — match badges per line (amount + ±3-day window against the books)
3. Import — matched lines get the system reference stamped
4. Verdict: statement balance vs books — ✅/⚠️ + CSV export/print

Each batch can be deleted.` } },
    ],
  },
  {
    id: 'meters', ico: '⚡',
    title: { bn: 'মিটার ও ইউটিলিটি', en: 'Meters & utilities' },
    items: [
      { id: 'reading-entry', t: { bn: 'রিডিং প্রবেশ', en: 'Entering readings' }, tag: { bn: '⚡', en: '⚡' },
        b: { bn: `⚡ সাব-মিটার রিডিং ট্যাব:

• দোকান (সার্চযোগ্য), ধরন (⚡ বিদ্যুৎ / 💧 পানি), মাস, রিডিং সংখ্যা
• 📸 মিটার ফটো সংযুক্ত করা যায়
• রিডিং ২০০%+ বেড়ে গেলে অস্বাভাবিকতা ফ্ল্যাগ

সেভ করলে ইউনিট অনুযায়ী বিল অটো তৈরি।`, en: `⚡ Sub-meter reading tab:

• space (searchable), type (⚡ electricity / 💧 water), month, reading
• 📸 attach a meter photo
• readings jumping 200%+ are flagged as anomalies

Saving auto-generates the bill from the units.` } },
      { id: 'shop-info', img: '02_meters_panel.jpg', cap: { bn: 'রিডিং ফর্মে দোকানের তথ্য প্যানেল', en: 'Space info panel in the reading form' }, t: { bn: 'দোকানের তথ্য প্যানেল', en: 'The space info panel' }, tag: { bn: '💡', en: '💡' },
        b: { bn: `রিডিং ফর্মে দোকান বাছলেই পূর্ণ তথ্য প্যানেল (পুরো প্রস্থে, ডেস্কটপে এক সারিতে):

• 💡 এই মাস — খাতভিত্তিক বিল (সার্ভিস/বিদ্যুৎ/পানি + ফাইন) প্রতিটির স্ট্যাটাসসহ + মোট
• ⚠️ বকেয়া — মোট বকেয়া + অপরিশোধিত বিল সংখ্যা + মাস চিপ; সম্পূর্ণ পরিশোধিত হলে ✅ সবুজ
• 📜 বিলের ইতিহাস — সর্বশেষ ৬ মাসের টেবিল (🧾⚡💧⚠️ মোট + স্ট্যাটাস)

দোকান/মাস বদলালে অটো রিফ্রেশ।`, en: `Pick a space in the reading form and a full info panel appears (full width, one row on desktop):

• 💡 This month — itemized bills (service/elec/water + fine) each with status + total
• ⚠️ Dues — total due + unpaid bill count + month chips; fully paid shows ✅ green
• 📜 Bill history — last 6 months table (🧾⚡💧⚠️ total + status)

Auto-refreshes on space/month change.` } },
      { id: 'util-income', t: { bn: 'ইউটিলিটি = নিজস্ব আয়', en: 'Utility = own income' }, tag: { bn: 'মডেল', en: 'model' },
        b: { bn: `বিদ্যুৎ/পানির আদায় মলের নিজস্ব আয়, DESCO/WASA বিল ব্যয় (spec 3.3)।

🔁 রিকনসিলিয়েশন প্যানেল মাসিক লাভ/ক্ষতি দেখায়:
• "বিদ্যুৎ ও পানি: আদায় − (DESCO + WASA) = লাভ/ক্ষতি"
• শুধু বিদ্যুৎ: আদায় − DESCO বিল
• সব মিলিয়ে অল-টাইম P/L

ইউটিলিটি কস্টিং-এ কার্যকর রেট ক্যালকুলেটরও আছে।`, en: `Electricity/water collections are the mall's OWN income; DESCO/WASA bills are expenses (spec 3.3).

The 🔁 Reconciliation panel shows monthly P/L:
• "Elec & water: collections − (DESCO + WASA) = profit/loss"
• Elec only: collections − DESCO bill
• All-time P/L

The utility-costing tab also has the effective-rate calculator.` } },
    ],
  },
  {
    id: 'alerts', ico: '🔔',
    title: { bn: 'এলার্ট ও এসএমএস', en: 'Alerts & SMS' },
    items: [
      { id: 'alert-types', t: { bn: 'ড্যাশবোর্ড এলার্ট', en: 'Dashboard alerts' }, tag: { bn: '🔔', en: '🔔' },
        b: { bn: `ড্যাশবোর্ডে ৩ ধরনের অটো এলার্ট:

• ⛔ সংযোগ বিচ্ছিন্ন ঝুঁকি (লাল) — বকেয়া ৩+ মাস (disconnect_months)
• 🚨 উচ্চ বকেয়া (কমলা) — বকেয়া ২+ মাস (high_dues_months)
• 🛠️ এএমসি/সার্ভিসিং (বেগুনি) — সম্পদের এএমসি মেয়াদ শেষ বা ৩০ দিনের মধ্যে

প্রতিটি চিপে 📲 বাটন — এক ক্লিকে মালিককে এসএমএস এলার্ট।`, en: `Three automatic dashboard alerts:

• ⛔ Disconnection risk (red) — 3+ months due (disconnect_months)
• 🚨 High dues (orange) — 2+ months due (high_dues_months)
• 🛠️ AMC/service (purple) — asset AMC expiring within 30 days or expired

Each chip has a 📲 button — one-click SMS alert to the owner.` } },
      { id: 'sms-recipients', t: { bn: 'এসএমএস রিসিপিয়েন্ট', en: 'SMS recipients' }, tag: { bn: '📱', en: '📱' },
        b: { bn: `সেটিংস → 📱 এসএমএস:

• রিসিপিয়েন্ট: মালিক / ভাড়াটিয়া / উভয় (sms_recipients)
• রসিদের অটো এসএমএস ও ডিউস এলার্ট এই সেটিং অনুসরণ করে
• প্রতি-সেন্ড ওভাররাইডও আছে (send-alert-এ to:)

এসএমএস গেটওয়ে bulksmsbd — লগ মোডে (sms_enabled=0) কোনো খরচ ছাড়া টেস্ট; 1 করলে আসল এসএমএস যায়।`, en: `Settings → 📱 SMS:

• recipients: owner / tenant / both (sms_recipients)
• auto receipt SMS and dues alerts follow this setting
• per-send override available (to: on send-alert)

Gateway: bulksmsbd — log mode (sms_enabled=0) tests free of charge; set 1 for real SMS.` } },
      { id: 'blasts', t: { bn: 'রিমাইন্ডার ও নোটিশ ব্লাস্ট', en: 'Reminder & notice blasts' }, tag: { bn: '📲', en: '📲' },
        b: { bn: `বিল ট্যাবে 📲 "সব খেলাপিকে রিমাইন্ড" — প্রতিটি বকেয়া দোকানে বাংলা রিমাইন্ডার এসএমএস।

নোটিশ ট্যাবে 📢 SMS ব্রডকাস্ট — যেকোনো নোটিশ সব মালিক/ভাড়াটিয়াকে।

সব পাঠানো mall_sms_log-এ লগ হয় (কাইন্ড: remind/notice/test)।`, en: `Bills tab → 📲 "Remind all defaulters" — Bangla reminder SMS to every shop with dues.

Notices tab → 📢 SMS broadcast — any notice to all owners/tenants.

Everything is logged in mall_sms_log (kind: remind/notice/test).` } },
    ],
  },
  {
    id: 'tenants', ico: '🧑🤝🧑',
    title: { bn: 'ভাড়াটিয়া ও চুক্তি', en: 'Tenants & agreements' },
    items: [
      { id: 'tenant-profile', t: { bn: 'ভাড়াটিয়া প্রোফাইল', en: 'Tenant profiles' }, tag: { bn: 'প্রোফাইল', en: 'profile' },
        b: { bn: `ভাড়া ও ভাড়াটিয়া ট্যাব → ভাড়াটিয়ার কার্ডে ক্লিক → ড্রয়ার:

• পরিচয়: ধরন, বাবা/মা, এনআইডি, ফোন, ইমেইল, জরুরি যোগাযোগ
• ঠিকানা: বর্তমান + স্থায়ী, শহর, যুক্ত হওয়ার তারিখ
• ব্যবসা: ব্যবসার নাম, পেশা, ট্যাগ
• পরিবার ও কোম্পানি প্রোফাইল
• ✏️ এডিট ট্যাব — ড্রয়ারেই সম্পাদনা + 💾 সংরক্ষণ

+ ভাড়াটিয়া যোগ করুন — সেকশনভিত্তিক ফর্ম।`, en: `Rent & tenants tab → click a tenant card → drawer:

• identity: type, father/mother, NID, phone, email, emergency contact
• addresses: present + permanent, city, joined date
• business: business name, occupation, tags
• family & company profile
• ✏️ edit tab — edit right in the drawer + 💾 save

"＋ Add tenant" — a sectioned form.` } },
      { id: 'agreements', t: { bn: 'ভাড়া চুক্তি', en: 'Rent agreements' }, tag: { bn: '📄', en: '📄' },
        b: { bn: `"📄 নতুন চুক্তি" — ভাড়াটিয়া, দোকান, মাসিক ভাড়া, শুরু/শেষ তারিখ, জামানত।

ভাড়া আদায় একটি ঐচ্ছিক সেবা (মালিকরা নিজেও আদায় করতে পারেন; কমিটি চাইলে পরিচালনা করে)।

চুক্তির কার্ডে ভাড়াটিয়ার নামে ক্লিক → ভাড়াটিয়ার ড্রয়ার।`, en: `"📄 New agreement" — tenant, space, monthly rent, start/end dates, deposit.

Rent collection is an optional service (owners may collect themselves; the committee manages it on request).

Click a tenant name on an agreement card → tenant drawer.` } },
      { id: 'rent-due', t: { bn: 'ভাড়া বকেয়া', en: 'Rent dues' }, tag: { bn: '⏳', en: '⏳' },
        b: { bn: `ভাড়া ট্যাবের KPI: ভাড়াটিয়া সংখ্যা, চুক্তি, ভাড়া আদায় (মাস), বকেয়া ভাড়া (মাস × ভাড়া)।

ভাড়া আদায়ে RNT- রসিদ; ড্যাশবোর্ডের 📅 আজকের আদায়ে ভাড়াও যোগ হয়।`, en: `Rent tab KPIs: tenant count, agreements, rent collected (month), rent due (months × rent).

Rent collections issue RNT- receipts; they're included in the dashboard's 📅 today's collections.` } },
    ],
  },
  {
    id: 'ops', ico: '🛠️',
    title: { bn: 'সম্পদ, কমপ্লেইন্ট, নোটিশ', en: 'Assets, complaints, notices' },
    items: [
      { id: 'assets', t: { bn: 'সম্পদ ও এএমসি', en: 'Assets & AMC' }, tag: { bn: '🛠️', en: '🛠️' },
        b: { bn: `সম্পদ ট্যাব — মলের স্থায়ী সম্পদ (লিফট, জেনারেটর, ফায়ার এক্সটিংগুইশার…) রেজিস্টার + এএমসি মেয়াদ।

এএমসি শেষ হতে ৩০ দিনের মধ্যে ড্যাশবোর্ডে 🛠️ এলার্ট — জেনারেটর, এসকেলেটর, লিফট ইত্যাদি ট্র্যাক করুন।`, en: `Assets tab — register the mall's fixed assets (lifts, generator, fire extinguishers…) with AMC expiry.

Within 30 days of AMC expiry a 🛠️ dashboard alert appears — track generators, escalators, lifts.` } },
      { id: 'complaints', t: { bn: 'কমপ্লেইন্ট', en: 'Complaints' }, tag: { bn: '📮', en: '📮' },
        b: { bn: `কমপ্লেইন্ট ট্যাব — মালিক/ভাড়াটিয়ার অভিযোগ রেকর্ড, স্ট্যাটাস ট্র্যাক (নতুন/প্রক্রিয়াধীন/সমাধান), দোকানের সাথে লিংক।

নতুন কমপ্লেইন্ট: 📮 + বাটন — বিষয়, দোকান, বর্ণনা।`, en: `Complaints tab — record owner/tenant complaints, track status (new/in-progress/resolved), linked to the space.

New complaint: 📮 + button — subject, space, description.` } },
      { id: 'notices', t: { bn: 'নোটিশ ও ব্রডকাস্ট', en: 'Notices & broadcast' }, tag: { bn: '📢', en: '📢' },
        b: { bn: `নোটিশ ট্যাব — কমিটির নোটিশ প্রকাশ (শিরোনাম + বডি), মাস/দোকান-নির্দিষ্ট।

📢 SMS ব্রডকাস্ট — নোটিশ সব মালিক/ভাড়াটিয়ার ফোনে।`, en: `Notices tab — publish committee notices (title + body), month/space-specific.

📢 SMS broadcast — the notice to all owners/tenants.` } },
    ],
  },
  {
    id: 'roles', ico: '🔐',
    title: { bn: 'ভূমিকা ও নিরাপত্তা', en: 'Roles & security' },
    items: [
      { id: 'permissions', t: { bn: 'ভূমিকার অনুমতি', en: 'Role permissions' }, tag: { bn: '🔐', en: '🔐' },
        b: { bn: `• সুপার অ্যাডমিন/মালিক/ম্যানেজার/হিসাবরক্ষক — পূর্ণ অ্যাক্সেস (সেটিংস, এসএমএস, ব্লাস্ট, অনুমোদন)
• কালেক্টর — আদায়, রিডিং, বিল দেখা; এসএমএস/সেটিংস/রিপোর্ট লেখায় সীমাবদ্ধ
• অ্যাকাউন্ট ম্যাপিং, আয় যোগ, এক্সিট অনুমোদন, মওকুফ/ভয়েড — নির্দিষ্ট রোল-গেটেড

মালিক পোর্টাল আলাদা — শুধু নিজের ডেটা।`, en: `• Super admin/owner/manager/accountant — full access (settings, SMS, blasts, approvals)
• Collector — collections, readings, view bills; limited on SMS/settings/report writes
• Account mapping, income add, exit approvals, waiver/void — role-gated

Owner portal is separate — own data only.` } },
      { id: 'audit', t: { bn: 'অডিট লগ', en: 'Audit log' }, tag: { bn: '🕵️', en: '🕵️' },
        b: { bn: `অডিট ট্যাব — কে কী করেছে তার লগ: লগইন, বিল, আদায়, ভয়েড, সেটিংস পরিবর্তন।

কোনো সমস্যা হলেই কারণ খুঁজে বের করুন — সব লেখা অপারেশন লগ হয়।`, en: `Audit tab — who did what: logins, bills, collections, voids, setting changes.

Every write operation is logged — trace any issue back to its source.` } },
    ],
  },
  {
    id: 'offline', ico: '📴',
    title: { bn: 'অফলাইন ও সিঙ্ক', en: 'Offline & sync' },
    items: [
      { id: 'offline-mode', notes: [{ type: 'tip', bn: '📡 Sync (N) ব্যাজ দেখলে N সংখ্যক অপেক্ষমাণ লেখা আছে — ইন্টারনেট ফিরলে অটো চলে যাবে।', en: '📡 Sync (N) badge means N queued writes — they flush automatically when online.' }], t: { bn: 'অফলাইন মোড', en: 'Offline mode' }, tag: { bn: '📴', en: '📴' },
        b: { bn: `ইন্টারনেট চলে গেলেও অ্যাপ চলে:

• সার্ভিস ওয়ার্কার শেল ক্যাশ করে — পাতা খোলে, আগের ডেটা দেখায়
• যে লেখা (আদায় ইত্যাদি) সেভ করা যায়নি সেগুলো কিউতে যায় — 📡 Sync (N) ব্যাজ দেখায়
• ইন্টারনেট ফিরলেই অটো-সিঙ্ক — কিউ রিপ্লে হয়

মাথার উপর 📴 ব্যানার + 📡 Sync বাটন — অবস্থা জানায়।`, en: `The app keeps working without internet:

• a service worker caches the shell — pages open, last-loaded data shows
• writes that failed (collections etc.) go into a queue — 📡 Sync (N) badge appears
• auto-flush on reconnect — the queue replays

A 📴 banner + 📡 Sync button show the state.` } },
    ],
  },
  {
    id: 'settings', ico: '⚙️',
    title: { bn: 'সেটিংস ও ব্যাকআপ', en: 'Settings & backup' },
    items: [
      { id: 'settings-tabs', t: { bn: 'সেটিংসের ট্যাব', en: 'Settings tabs' }, tag: { bn: '⚙️', en: '⚙️' },
        b: { bn: `সেটিংস (⚙️) — ৭টি ট্যাব:

• প্রোফাইল — মলের নাম, ঠিকানা, ফোন, লোগো (রসিদ/বিলে)
• বিলিং — সার্ভিস রেট, ইউনিট রেট, বিল সিকোয়েন্স, high_dues/disconnect থ্রেশহোল্ড, ⚡ ইউটিলিটি কস্টিং (কার্যকর রেট)
• ফাইন — দেরি ফাইন নিয়ম
• অ্যাকাউন্টস — খাত ম্যাপিং, সাব-লেজার কন্ট্রোল
• এসএমএস — গেটওয়ে, রিসিপিয়েন্ট, স্বয়ংক্রিয় রসিদ এসএমএস
• গভর্নেন্স — কমিটি, অনুমোদন
• হিসাব — কোডিং, ডিফল্ট

মালিক পোর্টালের ব্র্যান্ডিংও এখানে।`, en: `Settings (⚙️) — 7 tabs:

• Profile — mall name, address, phone, logo (on receipts/bills)
• Billing — service rate, unit rates, bill sequence, high_dues/disconnect thresholds, ⚡ utility costing (effective rate)
• Fines — late-fee rule
• Accounts — head mapping, sub-ledger control
• SMS — gateway, recipients, auto receipt SMS
• Governance — committee, approvals
• Account — coding, defaults

Owner portal branding lives here too.` } },
      { id: 'backup', t: { bn: 'ব্যাকআপ ও পুনরুদ্ধার', en: 'Backup & restore' }, tag: { bn: '💾', en: '💾' },
        b: { bn: `সাহায্য → 💾 ব্যাকআপ ও পুনরুদ্ধার:

• ⬇ ব্যাকআপ ডাউনলোড — পুরো ডেটাবেস (এক ফাইল)
• ⬆ পুনরুদ্ধার — আপলোড করলে ডেটা ফিরে আসে

নিয়মিত ব্যাকআপ নিন — বিশেষত মাসের বিল/আদায়ের পরে।`, en: `Help → 💾 Backup & restore:

• ⬇ download backup — the whole database (one file)
• ⬆ restore — upload to bring data back

Take regular backups — especially after monthly billing/collections.` } },
    ],
  },
]

const filtered = computed(() => {
  const s = q.value.trim().toLowerCase()
  if (!s) return SECTIONS
  return SECTIONS.map(sec => ({
    ...sec,
    items: sec.items.filter(it => (it.t.bn + ' ' + it.t.en + ' ' + it.b.bn + ' ' + it.b.en).toLowerCase().includes(s)),
  })).filter(sec => sec.items.length)
})

function toggle(sid, iid) {
  const k = sid + ':' + iid
  open.value = { ...open.value, [k]: !open.value[k] }
}
function isOpen(sid, iid) { return !!open.value[sid + ':' + iid] }
function toggleAll(sec, force) {
  const k = sec.id + ':all'
  const on = force !== undefined ? force : !open.value[k]
  open.value = { ...open.value, [k]: on }
}
function allOpen(sec) { return !!open.value[sec.id + ':all'] }
const pdfBusy = ref(false)

/* ── cross-article links ── */
function goTo(sid, iid) {
  open.value = { ...open.value, [sid + ':' + iid]: true }
  const el = document.getElementById('wiki-' + sid + '-' + iid)
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

/* ── jump to the actual app screen for each topic ── */
const GO = {
  login: { tab: 'dashboard' }, ui: { tab: 'dashboard' }, overview: { tab: 'dashboard' }, dash: { tab: 'dashboard' },
  'space-add': { tab: 'space' }, 'owner-profile': { tab: 'owners' }, 'owner-portal': { hash: '#/portal' }, 'deep-links': { tab: 'space' },
  'bill-model': { tab: 'bills' }, 'auto-bill': { tab: 'meters' }, 'rate-calc': { tab: 'settings' }, 'combined-bill': { tab: 'invoices' }, 'bill-templates': { tab: 'invoices' }, 'dues-fines': { tab: 'bills' },
  'collect-flow': { tab: 'payments' }, methods: { tab: 'payments' }, receipts: { tab: 'payments' }, history: { tab: 'payments' }, 'void-waiver': { tab: 'payments' },
  coa: { tab: 'coa' }, journal: { tab: 'journal' }, ledger: { tab: 'statements' }, reports: { tab: 'trial' }, 'bank-import': { tab: 'reconcile' },
  'reading-entry': { tab: 'meters' }, 'shop-info': { tab: 'meters' }, 'util-income': { tab: 'reconcile' },
  'alert-types': { tab: 'dashboard' }, 'sms-recipients': { tab: 'settings' }, blasts: { tab: 'bills' },
  'tenant-profile': { tab: 'rent' }, agreements: { tab: 'rent' }, 'rent-due': { tab: 'rent' },
  assets: { tab: 'assets' }, complaints: { tab: 'complaints' }, notices: { tab: 'notices' },
  permissions: { tab: 'users' }, audit: { tab: 'audit' },
  'offline-mode': { tab: 'dashboard' },
  'settings-tabs': { tab: 'settings' }, backup: { hash: '#/backup' },
}
function goApp(g) {
  if (!g) return
  if (g.hash) location.hash = g.hash
  else location.hash = '#/mall?tab=' + g.tab
}

/* ── notation callout styles ── */
function noteStyle(type) {
  const m = {
    tip:  { bg: '#f0fdf4', bd: '#bbf7d0' },
    warn: { bg: '#fef2f2', bd: '#fecaca' },
    info: { bg: '#eff6ff', bd: '#bfdbfe' },
    link: { bg: '#faf5ff', bd: '#e9d5ff' },
  }[type] || { bg: '#f8fafc', bd: '#e2e8f0' }
  return `background:${m.bg};border:1px solid ${m.bd};border-radius:8px;padding:8px 11px;font-size:12px;line-height:1.55`
}

/* ── expand everything (for print / PDF) ── */
async function expandAll() {
  SECTIONS.forEach(s => toggleAll(s, true))
  await new Promise(r => setTimeout(r, 400))
}

/* ── 🖨️ print the whole wiki ── */
async function printWiki() {
  await expandAll()
  await new Promise(r => setTimeout(r, 800))   // let images settle
  window.print()
}

/* ── ⬇ complete PDF as a DOCUMENT (margins, cover, TOC, footer, version) ── */
const PDF_VER = 'v1.0'
const APP_VER = 'v2.2 · Mall & Commercial Edition'
function b64FromBuffer(buf) {
  let bin = ''
  const chunk = 0x8000
  for (let i = 0; i < buf.length; i += chunk) bin += String.fromCharCode.apply(null, new Uint8Array(buf.subarray(i, i + chunk)))
  return btoa(bin)
}
async function loadImage(url) {
  const r = await fetch(url)
  const blob = await r.blob()
  return new Promise((res, rej) => {
    const fr = new FileReader()
    fr.onload = () => {
      const im = new Image()
      im.onload = () => res({ data: fr.result, w: im.width, h: im.height })
      im.onerror = rej
      im.src = fr.result
    }
    fr.onerror = rej
    fr.readAsDataURL(blob)
  })
}
async function downloadPdf() {
  if (pdfBusy.value) return
  pdfBusy.value = true
  try {
    const { jsPDF } = await import('jspdf')
    const html2canvas = (await import('html2canvas')).default
    const bn = lang.value === 'bn'
    /* ── palette ── */
    const C = {
      teal: [15, 118, 110], tealD: [13, 94, 88], navy: [15, 23, 42],
      ink: [51, 65, 85], mut: [100, 116, 139], amber: [217, 119, 6],
      red: [185, 28, 28], blue: [29, 78, 216], purple: [109, 40, 217], green: [4, 120, 87],
      bg: [241, 245, 249], border: [203, 213, 225], white: [255, 255, 255], paper: [252, 253, 255],
    }
    const L = 16, T = 20, R = 16, B = 18
    const W = 210 - L - R
    const M = { tip: C.green, warn: C.red, info: C.blue, link: C.purple }
    const NOTE_ICON = { tip: '💡', warn: '⚠️', info: '📌', link: '🔗' }
    /* preload every wiki image once */
    const imgCache = {}
    const allImgs = [...new Set(SECTIONS.flatMap(s => s.items.flatMap(it => it.imgs || [it.img]).filter(Boolean)))]
    for (const im of allImgs) { try { imgCache[im] = await loadImage('img/wiki/' + im) } catch (e) { console.warn('img fail', im) } }

    /* ── design tokens (NO black — teal + amber + slate) ── */
    const TEAL = '#0F766E', TEAL_D = '#115E59', TEAL_L = '#CCFBF1', AMBER = '#F59E0B', AMBER_D = '#B45309'
    const SLATE = '#334155', MUTED = '#64748B', BG = '#F1F5F9', BORDER = '#E2E8F0', PAPER = '#FCFDFF'
    const NOTE_C = { tip: ['#DCFCE7', '#166534'], warn: ['#FEF2F2', '#B91C1C'], info: ['#E0F2FE', '#0369A1'], link: ['#F3E8FF', '#7E22CE'] }

    const PAGE_W = 210, PAGE_H = 297, PT = 16, PR = 14, PB = 20, PL = 14
    const MM = 96 / 25.4                                   // mm → px
    const PH = (PAGE_H - PT - PB) * MM                     // usable page height (px)

    /* @font-face with the inlined Hind Siliguri data URIs — guaranteed shaping everywhere */
    const FONTS_CSS = `<style>
      @font-face{font-family:'HS';src:url(${hindFont}) format('truetype');font-weight:400;font-display:block}
      @font-face{font-family:'HS';src:url(${hindBoldFont}) format('truetype');font-weight:700;font-display:block}
      *{box-sizing:border-box;margin:0;padding:0}
    </style>`

    const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

    /* ── cover page ── */
    const shots = ['01_dashboard.jpg', '02_meters_panel.jpg', '04_collect_pay.jpg', '05_module_map.png'].filter(s => imgCache[s])
    const coll = shots.slice(0, 4).map(s => {
      const c = imgCache[s]
      const w = 84, h = Math.min(58, Math.round(c.h * w / c.w))
      return `<div style="width:${w}mm;height:${h}mm;flex-shrink:0;padding:2mm;background:#fff;border-radius:2.5mm;box-shadow:0 1mm 3mm rgba(15,118,110,.18)"><img src="${c.data}" style="width:100%;height:100%;object-fit:cover;border-radius:1.5mm" /></div>`
    }).join('')
    const cover = `<div class="pg" data-t="cover">
      <div style="height:118mm;background:linear-gradient(165deg,${TEAL} 0%,${TEAL_D} 100%);padding:14mm 16mm;position:relative;overflow:hidden">
        <div style="position:absolute;right:-18mm;top:-20mm;width:70mm;height:70mm;border-radius:50%;background:rgba(255,255,255,.08)"></div>
        <div style="position:absolute;right:26mm;top:30mm;width:26mm;height:26mm;border-radius:50%;background:rgba(255,255,255,.07)"></div>
        <div style="position:absolute;left:-12mm;bottom:-16mm;width:50mm;height:50mm;border-radius:50%;background:rgba(255,255,255,.06)"></div>
        <div style="display:flex;flex-direction:column;align-items:center;text-align:center;position:relative">
          <div style="width:18mm;height:18mm;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:9mm;letter-spacing:1px">MM</div>
          <div style="color:#fff;font-weight:700;font-size:11.5mm;margin-top:6mm;line-height:1.2">Mall Manager</div>
          <div style="color:#fff;font-size:5.4mm;margin-top:3mm;font-weight:700">${esc(bn ? 'উইকি ও সাহায্য — সম্পূর্ণ ডকুমেন্টেশন' : 'Wiki & Help — Complete Documentation')}</div>
          <div style="color:rgba(255,255,255,.88);font-size:3.4mm;margin-top:2.5mm">${esc(bn ? 'মল ও কমার্শিয়াল বিল্ডিং ম্যানেজমেন্ট · Shopping Mall & Commercial Building Management' : 'Shopping Mall & Commercial Building Management')}</div>
          <div style="width:34mm;height:1mm;background:${AMBER};border-radius:1mm;margin-top:5mm"></div>
        </div>
      </div>
      <div style="padding:8mm 16mm 0">
        <div style="display:flex;gap:5mm;justify-content:center">
          ${[['ডকুমেন্ট ভার্সন', 'Document version', PDF_VER], ['অ্যাপ ভার্সন', 'App version', 'v2.2'], ['তৈরি', 'Generated', new Date().toLocaleDateString(bn ? 'bn-BD' : 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' })]].map(b => `
            <div style="flex:1;max-width:58mm;background:${BG};border:0.4mm solid ${BORDER};border-top:1.2mm solid ${TEAL};border-radius:2mm;padding:3mm 4mm">
              <div style="font-size:2.8mm;color:${MUTED};font-weight:700;letter-spacing:.4px">${esc(b[bn ? 0 : 1]).toUpperCase()}</div>
              <div style="font-size:4.6mm;color:${TEAL};font-weight:700;margin-top:1.5mm">${esc(b[2])}</div>
            </div>`).join('')}
        </div>
        <div style="display:flex;gap:4mm;justify-content:center;margin-top:7mm">${coll}</div>
        <div style="border-top:0.4mm solid ${BORDER};margin-top:8mm;padding-top:3.5mm;text-align:center;color:${MUTED};font-size:3mm">${esc(bn ? 'প্রস্তুতকারক: KRTaker (BITSCOL এর একটি প্রতিষ্ঠান) · মার্কেটিং: Appvaley' : 'Prepared by: KRTaker (A concern of BITSCOL) · Marketed by Appvaley')}</div>
      </div>
    </div>`

    /* ── holder + pagination ── */
    const holder = document.createElement('div')
    holder.style.cssText = `position:fixed;left:-99999px;top:0;width:${PAGE_W}mm;background:${PAPER};font-family:HS,'Hind Siliguri','Noto Sans Bengali',sans-serif`
    holder.innerHTML = FONTS_CSS
    document.body.appendChild(holder)
    let page = null
    const pages = []
    function newPage() {
      page = document.createElement('div')
      page.className = 'pg'
      page.style.cssText = `width:${PAGE_W}mm;height:${PAGE_H}mm;background:${PAPER};padding:${PT}mm ${PR}mm ${PB}mm ${PL}mm;position:relative;overflow:hidden;page-break-after:always`
      holder.appendChild(page)
      pages.push(page)
      return page
    }
    newPage()
    const used = { px: 0 }
    function addBlock(el) {
      page.appendChild(el)
      used.px += el.offsetHeight + 8
      if (used.px > PH) { newPage(); page.appendChild(el); used.px = el.offsetHeight + 8 }
    }
    function addPageEl(html) { const d = document.createElement('div'); d.innerHTML = html; return d.firstElementChild }

    /* ── TOC ── */
    const tocRows = []
    /* ── sections ── */
    const secStart = {}                                   // section id -> page index
    SECTIONS.forEach((sec, si) => {
      secStart[sec.id] = pages.length
      const secHead = document.createElement('div')
      secHead.innerHTML = `<div style="display:flex;align-items:center;justify-content:space-between;background:linear-gradient(90deg,${TEAL},${TEAL_D});border-radius:2.5mm;padding:3.2mm 5mm;color:#fff">
        <div style="font-weight:700;font-size:5.2mm">${esc(sec.ico)} ${si + 1}. ${esc(sec.title[bn ? 'bn' : 'en'])}</div>
        <div style="font-size:2.9mm;background:rgba(255,255,255,.18);border-radius:4mm;padding:1.2mm 3.5mm;font-weight:700">${sec.items.length} ${bn ? 'টি নিবন্ধ' : 'articles'}</div>
      </div>`
      addBlock(secHead.firstElementChild)
      sec.items.forEach((it, ix) => {
        const itemNo = `${si + 1}.${ix + 1}`
        const imgs = (it.imgs || (it.img ? [it.img] : [])).filter(im => imgCache[im]).map(im => {
          const c = imgCache[im]
          const w = Math.min(150, 182), h = Math.min(92, Math.round(c.h * w / c.w))
          return `<div style="text-align:center;margin-top:3mm">
            <div style="display:inline-block;padding:1.5mm;background:#fff;border-radius:2mm;box-shadow:0 0.8mm 2.5mm rgba(15,118,110,.15);border:0.3mm solid ${BORDER}"><img src="${c.data}" style="width:${w}mm;height:${h}mm;object-fit:cover;border-radius:1.2mm" /></div>
            ${it.cap ? `<div style="font-size:2.9mm;color:${MUTED};margin-top:1.5mm">${esc(it.cap[bn ? 'bn' : 'en'])}</div>` : ''}
          </div>`
        }).join('')
        const notes = (it.notes || []).map(n => {
          const [bg, fg] = NOTE_C[n.type] || NOTE_C.info
          const ico = { tip: '💡', warn: '⚠️', info: '📌', link: '🔗' }[n.type] || '•'
          const body = n.url ? `<a href="${esc(n.url)}" style="color:${fg};font-weight:700;text-decoration:underline">${esc(n[bn ? 'bn' : 'en'])}</a>` : esc(n[bn ? 'bn' : 'en'])
          return `<div style="display:flex;gap:2mm;background:${bg};border-left:1.6mm solid ${fg};border-radius:1.5mm;padding:2.4mm 3.5mm;font-size:3.2mm;color:${fg};line-height:1.55;margin-top:2.2mm"><span>${ico}</span><div>${body}</div></div>`
        }).join('')
        const see = it.see && it.see.length ? `<div style="margin-top:2.4mm;font-size:3.2mm;color:${TEAL};font-weight:700">${bn ? 'আরও দেখুন: ' : 'See also: '}${esc(it.see.map(sg => sg.l[bn ? 'bn' : 'en']).join('  ·  '))}</div>` : ''
        const tag = it.tag ? `<span style="font-size:2.7mm;color:${TEAL};background:${TEAL_L};border-radius:4mm;padding:0.8mm 3mm;font-weight:700;margin-left:2.5mm">${esc(it.tag[bn ? 'bn' : 'en'])}</span>` : ''
        const art = document.createElement('div')
        art.innerHTML = `<div style="margin-top:4mm;background:${BG};border-radius:2mm;padding:3.6mm 4.5mm;border-left:1.2mm solid ${AMBER}">
          <div style="display:flex;align-items:center;font-weight:700;font-size:4.2mm;color:${TEAL_D}"><span style="color:${AMBER_D}">${itemNo}</span><span style="margin-left:2.5mm">${esc(it.t[bn ? 'bn' : 'en'])}</span>${tag}</div>
          <div style="margin-top:2.2mm;font-size:3.5mm;color:${SLATE};line-height:1.7;white-space:pre-wrap">${esc(it.b[bn ? 'bn' : 'en'])}</div>
          ${notes}${see}${imgs}
        </div>`
        addBlock(art.firstElementChild)
      })
      tocRows.push(`<div style="display:flex;align-items:center;gap:2.5mm;margin-top:2.2mm">
        <span style="width:6mm;height:6mm;border-radius:3mm;background:${AMBER};color:#fff;font-weight:700;font-size:3.2mm;display:flex;align-items:center;justify-content:center">${si + 1}</span>
        <span style="font-weight:700;font-size:4mm;color:${SLATE}">${esc(sec.ico)} ${esc(sec.title[bn ? 'bn' : 'en'])}</span>
        <span style="flex:1;border-bottom:0.5mm dotted ${BORDER};transform:translateY(-1mm)"></span>
        <span style="font-weight:700;font-size:4mm;color:${TEAL}">${secStart[sec.id] + 2}</span>
      </div>`)
    })

    /* ── insert TOC page after the cover ── */
    const tocPage = newPage()
    tocPage.style.paddingTop = `${PT + 4}mm`
    tocPage.innerHTML = `<div style="font-weight:700;font-size:6.5mm;color:${TEAL_D}">${bn ? 'সূচিপত্র' : 'Table of Contents'}</div>
      <div style="width:16mm;height:1mm;background:${AMBER};border-radius:1mm;margin-top:2mm"></div>
      <div style="margin-top:5mm">${tocRows.join('')}</div>
      <div style="margin-top:8mm;font-size:2.9mm;color:${MUTED}">${bn ? 'এই ডকুমেন্টে মল ম্যানেজারের ১২টি সেকশন ও ৪১টি নিবন্ধ রয়েছে — স্ক্রিনশট ও গ্রাফিক্সসহ।' : 'This document covers all 12 sections and 41 articles — with screenshots & graphics.'}</div>`
    /* move the toc page right after the cover (cover is inserted before it next) */
    holder.insertBefore(tocPage, pages[0])

    /* ── footers (version + page numbers) on every page (DOM order!) ── */
    const allPg = holder.querySelectorAll('.pg')
    const N = allPg.length
    allPg.forEach((p, i) => {
      const f = document.createElement('div')
      f.style.cssText = `position:absolute;left:${PL}mm;right:${PR}mm;bottom:6mm;display:flex;justify-content:space-between;align-items:center;border-top:0.4mm solid ${TEAL};padding-top:2mm`
      f.innerHTML = `<div style="font-size:2.7mm;color:${MUTED}">Mall Manager — Wiki &amp; Help · App ${APP_VER} · Document ${PDF_VER}</div>
        <div style="font-size:2.7mm;color:${TEAL};font-weight:700">${bn ? 'পৃষ্ঠা' : 'Page'} ${i + 1} ${bn ? 'এর' : 'of'} ${N}</div>`
      p.appendChild(f)
    })

    /* cover first (insert at the very beginning, before the toc page) */
    holder.insertBefore(addPageEl(cover), holder.firstChild.nextSibling)
    await document.fonts.ready
    await new Promise(r => setTimeout(r, 1200))

    /* ── render each page with the browser (perfect Bengali shaping) → PDF ── */
    const pdf = new jsPDF({ orientation: 'p', unit: 'mm', format: 'a4' })
    const all = holder.querySelectorAll('.pg')
    for (let i = 0; i < all.length; i++) {
      const cv = await html2canvas(all[i], { scale: 2, backgroundColor: PAPER, useCORS: true, logging: false })
      const img = cv.toDataURL('image/jpeg', 0.92)
      if (i > 0) pdf.addPage()
      pdf.addImage(img, 'JPEG', 0, 0, PAGE_W, PAGE_H)
    }
    document.body.removeChild(holder)
    pdf.save(`Mall-Manager-Wiki-${PDF_VER}.pdf`)
    window.__krToast?.(bn ? '✅ PDF ডকুমেন্ট ডাউনলোড হয়েছে' : '✅ PDF document downloaded', 'ok')
  } catch (e) {
    console.error('wiki pdf failed:', e)
    window.__krToast?.(lang.value === 'bn' ? 'PDF তৈরি ব্যর্থ — প্রিন্ট → Save as PDF ব্যবহার করুন' : 'PDF render failed — use Print → Save as PDF', 'err')
    window.print()
  } finally {
    pdfBusy.value = false
  }
}
</script>

<template>
  <div class="wiki-pdf-root">
    <div class="page-head">
      <div>
        <h1>📚 {{ lang === 'bn' ? 'উইকি ও সাহায্য' : 'Wiki &amp; Help' }}</h1>
        <div class="sub">{{ lang === 'bn' ? 'মল ম্যানেজমেন্ট — ধাপে ধাপে গাইড, সেটিংস, হিসাব ও এসএমএস' : 'Mall management — step-by-step guides, settings, accounting & SMS' }}</div>
      </div>
      <div class="wiki-actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <button class="btn-ghost" @click="printWiki" style="padding:8px 13px;font-size:12px">🖨️ {{ lang === 'bn' ? 'প্রিন্ট' : 'Print' }}</button>
        <button @click="downloadPdf" :disabled="pdfBusy" style="padding:8px 13px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12px;font-weight:800;cursor:pointer">{{ pdfBusy ? (lang === 'bn' ? '⏳ তৈরি হচ্ছে…' : '⏳ Rendering…') : '⬇ ' + (lang === 'bn' ? 'সম্পূর্ণ PDF ডাউনলোড' : 'Download complete PDF') }}</button>
      </div>
    </div>

    <!-- search -->
    <div class="panel" style="padding:14px 16px;margin-bottom:14px">
      <input v-model="q" :placeholder="lang === 'bn' ? '🔍  উইকি খুঁজুন… (যেমন: বিল, রসিদ, জার্নাল, অফলাইন, এসএমএস)' : '🔍  Search the wiki…  (e.g. bill, receipt, journal, offline, SMS)'"
        style="width:100%;padding:11px 14px;border-radius:12px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-size:14px;outline:none">
    </div>

    <div v-if="!filtered.length" class="panel" style="padding:40px 20px;text-align:center;color:var(--text-mute)">
      {{ lang === 'bn' ? '“' + q + '” এর জন্য কোনো নিবন্ধ পাওয়া যায়নি।' : 'No articles match “' + q + '”.' }}
    </div>

    <div v-for="sec in filtered" :key="sec.id" class="panel" style="padding:6px 16px 14px;margin-bottom:14px">
      <div style="display:flex;align-items:center;gap:10px;padding:12px 0 10px;border-bottom:1px solid var(--border)">
        <span style="font-size:20px">{{ sec.ico }}</span>
        <div style="flex:1">
          <div style="font-weight:800;font-size:15px">{{ sec.title[lang] }}</div>
          <div class="c-sub" style="font-size:11.5px">{{ sec.items.length }} {{ lang === 'bn' ? 'টি নিবন্ধ' : (sec.items.length === 1 ? 'article' : 'articles') }}</div>
        </div>
        <button class="btn-ghost" style="padding:6px 12px;font-size:12px" @click="toggleAll(sec)">{{ allOpen(sec) ? (lang === 'bn' ? 'সঙ্কুচিত' : 'Collapse') : (lang === 'bn' ? 'সব খুলুন' : 'Expand all') }}</button>
      </div>

      <div v-for="it in sec.items" :id="'wiki-' + sec.id + '-' + it.id" :key="it.id" style="border-bottom:1px solid var(--border)">
        <div style="display:flex;align-items:center;gap:8px;padding:12px 4px;cursor:pointer;user-select:none" @click="toggle(sec.id, it.id)">
          <span style="font-size:12px;color:var(--text-mute);width:16px;flex-shrink:0">{{ isOpen(sec.id, it.id) ? '▾' : '▸' }}</span>
          <span style="font-weight:700;font-size:13.5px;flex:1">{{ it.t[lang] }}</span>
          <span v-if="it.tag" class="badge b-blue" style="font-size:10px">{{ it.tag[lang] }}</span>
        </div>
        <div v-if="isOpen(sec.id, it.id)" style="padding:2px 4px 14px 28px">
          <div style="white-space:pre-wrap;line-height:1.65;font-size:13px;color:var(--text)">{{ it.b[lang] }}</div>
          <div v-if="it.img || it.imgs" style="margin-top:10px">
            <template v-for="(im, ix) in (it.imgs || [it.img])" :key="ix">
              <a :href="'img/wiki/' + im" target="_blank" style="display:block;margin-top:6px">
                <img :src="'img/wiki/' + im" :alt="it.cap ? it.cap[lang] : ''" style="max-width:100%;border-radius:12px;border:1px solid var(--border);cursor:zoom-in" loading="lazy" />
              </a>
            </template>
            <div v-if="it.cap" style="font-size:11.5px;color:var(--text-mute);margin-top:6px;text-align:center">{{ it.cap[lang] }}</div>
          </div>
          <div v-if="it.notes && it.notes.length" style="margin-top:10px;display:flex;flex-direction:column;gap:7px">
            <div v-for="(nt, ni) in it.notes" :key="ni" :style="noteStyle(nt.type)">
              <b>{{ nt.type === 'tip' ? '💡 ' + (lang === 'bn' ? 'টিপস' : 'Tip') : nt.type === 'warn' ? '⚠️ ' + (lang === 'bn' ? 'সতর্কতা' : 'Warning') : nt.type === 'link' ? '🔗 ' + (lang === 'bn' ? 'লিংক' : 'Link') : '📌 ' + (lang === 'bn' ? 'জেনে রাখুন' : 'Note') }}</b>
              <template v-if="nt.url"><a :href="nt.url" target="_blank" style="color:var(--primary);font-weight:700;text-decoration:underline">{{ nt[lang] }}</a></template>
              <template v-else> {{ nt[lang] }}</template>
            </div>
          </div>
          <div v-if="it.see && it.see.length" style="margin-top:11px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <span style="font-size:11.5px;color:var(--text-mute);font-weight:800">{{ lang === 'bn' ? 'আরও দেখুন:' : 'See also:' }}</span>
            <a v-for="sg in it.see" :key="sg.s + ':' + sg.i" @click.prevent="goTo(sg.s, sg.i)" style="cursor:pointer;padding:4px 11px;border:1px solid var(--border);border-radius:20px;font-size:11.5px;background:var(--bg-alt);color:var(--primary);font-weight:700">{{ sg.l[lang] }}</a>
          </div>
          <div v-if="GO[it.id]" style="margin-top:12px">
            <button @click="goApp(GO[it.id])" style="padding:8px 15px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:12px;font-weight:800;cursor:pointer">🚀 {{ lang === 'bn' ? 'এই ফিচারে যান' : 'Open this feature' }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style>
@media print {
  .sidebar, .topbar, .app-header, .banner, .wiki-actions { display: none !important; }
  body { background: #fff !important; }
  .wiki-pdf-root { position: absolute; left: 0; top: 0; width: 100%; }
  .wiki-pdf-root .panel { break-inside: avoid; }
  .wiki-pdf-root img { max-height: 260px; object-fit: contain; }
}
</style>
