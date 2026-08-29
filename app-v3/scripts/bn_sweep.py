#!/usr/bin/env python3
"""Mall Manager Bangla sweep: wrap static button/label/th/placeholder/option
text in t() and add dict entries. Idempotent (skips already-wrapped)."""
import re, json

MV = '/root/mall-management/app-v3/src/views/MallView.vue'
I18N = '/root/mall-management/app-v3/src/lib/i18n.js'
mv = open(MV).read()
tpl = mv[mv.find('</script>') + 9:]

# ── translations (EN → BN) ──────────────────────────────────────────
T = {
 # buttons
 'Cancel': 'বাতিল', 'Close': 'বন্ধ করুন', 'Search': 'সার্চ',
 '⚙️ Generate service-charge bills': '⚙️ সার্ভিস চার্জ বিল তৈরি করুন',
 '💸 Compute late fees': '💸 দেরি ফি হিসাব করুন', '📲 Remind all defaulters': '📲 সব খেলাপিকে রিমাইন্ডার দিন',
 '🧹 Clear fines': '🧹 ফাইন মুছুন', '⬇ CSV': '⬇ সিএসভি',
 '💵 Collect': '💵 আদায় করুন', '💸 Waiver': '💸 মওকুফ', '🖨️ Receipt': '🖨️ রসিদ', '📄 Combined': '📄 সম্মিলিত',
 '🔒 Void': '🔒 বাতিল', '＋ Add account': '＋ অ্যাকাউন্ট যোগ করুন',
 '＋ New voucher (double entry)': '＋ নতুন ভাউচার (ডাবল এন্ট্রি)', '🔍 Preview': '🔍 প্রিভিউ', '🔄 Refresh': '🔄 রিফ্রেশ',
 '💾 Record expense': '💾 ব্যয় রেকর্ড করুন', '💾 Record income': '💾 আয় রেকর্ড করুন',
 '＋ Log complaint': '＋ অভিযোগ লগ করুন', '▶ Start': '▶ শুরু করুন', '✓ Resolve': '✓ সমাধান করুন',
 '＋ Add asset': '＋ সম্পদ যোগ করুন', '📢 Post notice': '📢 নোটিশ পোস্ট করুন', '📲 SMS broadcast': '📲 এসএমএস ব্রডকাস্ট',
 '＋ Add staff': '＋ স্টাফ যোগ করুন', '💸 Pay salary': '💸 বেতন দিন', '＋ Add system user': '＋ সিস্টেম ব্যবহারকারী যোগ করুন',
 '＋ Add member': '＋ সদস্য যোগ করুন', '📅 Log meeting': '📅 সভা লগ করুন', '📜 Add resolution': '📜 রেজুলেশন যোগ করুন',
 '＋ Add owner': '＋ মালিক যোগ করুন', '＋ Add tenant': '＋ ভাড়াটিয়া যোগ করুন', '📄 New agreement': '📄 নতুন চুক্তি',
 '💵 Collect rent': '💵 ভাড়া আদায় করুন', '🚪 Exit request': '🚪 এক্সিট রিকোয়েস্ট', '✅ Approve exit + NOC': '✅ এক্সিট + এনওসি অনুমোদন',
 '🖨️ NOC': '🖨️ এনওসি', '＋ Add vendor': '＋ সরবরাহকারী যোগ করুন', '💸 Pay': '💸 পরিশোধ করুন',
 '💾 Save changes': '💾 পরিবর্তন সংরক্ষণ করুন', 'Calculate': 'হিসাব করুন', 'Use': 'ব্যবহার করুন',
 '💾 Save': '💾 সংরক্ষণ করুন', '📤 Send test': '📤 টেস্ট পাঠান', '💾 Save mapping': '💾 ম্যাপিং সংরক্ষণ করুন',
 '♻️ Reset all to defaults': '♻️ সব ডিফল্টে রিসেট করুন', '✏️ Manage roles': '✏️ রোল পরিচালনা করুন', '💾 Save roles': '💾 রোল সংরক্ষণ করুন',
 '🔑 Save license': '🔑 লাইসেন্স সংরক্ষণ করুন', '💾 Save budget': '💾 বাজেট সংরক্ষণ করুন',
 '💾 Save mall settings': '💾 মলের সেটিংস সংরক্ষণ করুন', '📨 Submit request': '📨 রিকোয়েস্ট জমা দিন',
 '💾 Save collection': '💾 আদায় সংরক্ষণ করুন', '🔧 Log complaint': '🔧 অভিযোগ লগ করুন', '💾 Save asset': '💾 সম্পদ সংরক্ষণ করুন',
 '📢 Post': '📢 পোস্ট করুন', '💾 Save staff': '💾 স্টাফ সংরক্ষণ করুন', '💸 Confirm payment': '💸 পেমেন্ট নিশ্চিত করুন',
 '💾 Save user': '💾 ব্যবহারকারী সংরক্ষণ করুন', '🔑 Reset password': '🔑 পাসওয়ার্ড রিসেট করুন',
 '💾 Save member': '💾 সদস্য সংরক্ষণ করুন', '💾 Save meeting': '💾 সভা সংরক্ষণ করুন', '💾 Save resolution': '💾 রেজুলেশন সংরক্ষণ করুন',
 '💾 Save owner': '💾 মালিক সংরক্ষণ করুন', '💾 Save tenant': '💾 ভাড়াটিয়া সংরক্ষণ করুন', '💾 Save agreement': '💾 চুক্তি সংরক্ষণ করুন',
 '✅ Record rent': '✅ ভাড়া রেকর্ড করুন', '💾 Save vendor': '💾 সরবরাহকারী সংরক্ষণ করুন', '✅ Record payment': '✅ পেমেন্ট রেকর্ড করুন',
 '💾 Save account': '💾 অ্যাকাউন্ট সংরক্ষণ করুন', '＋ Add line': '＋ লাইন যোগ করুন', '📤 Submit for approval': '📤 অনুমোদনের জন্য জমা দিন',
 '🖨️ Print receipt': '🖨️ রসিদ প্রিন্ট করুন', '✅ Approve': '✅ অনুমোদন করুন', '⛔ Reject': '⛔ প্রত্যাখ্যান করুন',
 '＋ Add': '＋ যোগ করুন', '🗑 Remove': '🗑 মুছুন', '☰ List': '☰ তালিকা', '⊞ Grid': '⊞ গ্রিড',
 '✕ Close': '✕ বন্ধ করুন', '＋ Add Space': '＋ দোকান যোগ করুন', '🖨️ Print': '🖨️ প্রিন্ট',
 # table headers
 'Status': 'স্ট্যাটাস', 'Type': 'ধরন', 'Amount': 'পরিমাণ', 'Method': 'পদ্ধতি', 'Date': 'তারিখ',
 'Space': 'দোকান', 'Note': 'নোট', 'Balance': 'ব্যালেন্স', 'Debit': 'ডেবিট', 'Credit': 'ক্রেডিট',
 'Phone': 'মোবাইল', 'Email': 'ইমেইল', 'Month': 'মাস', 'Account': 'অ্যাকাউন্ট', 'Due': 'বকেয়া',
 'Floor': 'তলা', 'Kind': 'ধরন', 'Address': 'ঠিকানা', 'Owner': 'মালিক', '#': '#', 'Ref': 'রেফারেন্স',
 'Code': 'কোড', 'Category': 'ক্যাটাগরি', 'Priority': 'অগ্রাধিকার', 'Details': 'বিস্তারিত',
 'Designation': 'পদবি', 'Paid': 'পরিশোধিত', 'Role': 'রোল', 'Term': 'মেয়াদ',
 'Mobile': 'মোবাইল', 'Receipt': 'রসিদ', 'Opening': 'প্রারম্ভিক', 'Particulars': 'বিবরণ',
 'Description': 'বর্ণনা', 'Reading': 'রিডিং', 'Units': 'ইউনিট', 'Billed': 'বিল হয়েছে',
 'Vendor': 'সরবরাহকারী', 'Subject': 'বিষয়', 'Opened': 'খোলা হয়েছে', 'Location': 'অবস্থান',
 'Warranty until': 'ওয়ারেন্টি পর্যন্ত', 'User': 'ব্যবহারকারী', 'Tenant': 'ভাড়াটিয়া',
 'Rent/mo': 'মাসিক ভাড়া', 'Advance': 'অগ্রিম', 'Occupancy': 'দখল', 'Sqft': 'স্কয়ার ফিট',
 'Rate/mo': 'মাসিক রেট', 'Debits': 'ডেবিট', 'Credits': 'ক্রেডিট', 'Match': 'মিল',
 'Photo': 'ছবি', 'Flag': 'ফ্ল্যাগ', 'Head': 'হেড', 'Voucher': 'ভাউচার', 'Asset': 'সম্পদ',
 'Installed': 'স্থাপিত', 'AMC until': 'এএমসি পর্যন্ত', 'When': 'কখন', 'Action': 'অ্যাকশন',
 'Module': 'মডিউল', 'Entity': 'এনটিটি', 'Name': 'নাম', 'Joined': 'যোগদান', 'Salary/mo': 'মাসিক বেতন',
 'Staff': 'স্টাফ', 'Last login': 'সর্বশেষ লগইন', 'Capability': 'সক্ষমতা', 'Service': 'সার্ভিস',
 'Elec': 'বিদ্যুৎ', 'Water': 'পানি', 'Total due': 'মোট বকেয়া', 'Elec collected': 'বিদ্যুৎ আদায়',
 'Water collected': 'পানি আদায়', 'DESCO bill paid': 'ডেসকো বিল পরিশোধ', 'WASA bill paid': 'ওয়াসা বিল পরিশোধ',
 'Subsidiary': 'সাবসিডিয়ারি', 'Collection': 'আদায়', 'Bill': 'বিল', 'Fine': 'ফাইন', 'Label': 'লেবেল',
 'Paid on': 'পরিশোধের তারিখ', 'When': 'কখন',
 # labels
 'Amount (৳)': 'পরিমাণ (৳)', 'Full name *': 'পুরো নাম *', 'Title *': 'শিরোনাম *',
 'Paid via': 'পরিশোধের মাধ্যমে', '⬆ Upload': '⬆ আপলোড', 'New password': 'নতুন পাসওয়ার্ড',
 'Opening balance (৳)': 'প্রারম্ভিক ব্যালেন্স (৳)', 'Space *': 'দোকান *', 'NID': 'এনআইডি',
 'Notes': 'নোট', 'Contact person': 'যোগাযোগের ব্যক্তি', 'Meter reading': 'মিটার রিডিং',
 'Vendor / supplier': 'সরবরাহকারী', 'Note (voucher / invoice)': 'নোট (ভাউচার / ইনভয়েস)',
 'Income head': 'আয়ের খাত', 'Received via': 'প্রাপ্তির মাধ্যমে', 'Mall name': 'মলের নাম',
 'Chairman': 'সভাপতি', 'Secretary': 'সাধারণ সম্পাদক', 'Elec rate (৳/unit)': 'বিদ্যুৎ রেট (৳/ইউনিট)',
 'Water rate (৳/unit)': 'পানি রেট (৳/ইউনিট)', 'Due day of month': 'মাসের নির্ধারিত তারিখ',
 '🚨 High-dues alert after (months)': '🚨 উচ্চ বকেয়া এলার্ট (মাস)', '⛔ Disconnection risk after (months)': '⛔ সংযোগ বিচ্ছিন্ন ঝুঁকি (মাস)',
 'Advance months (default)': 'অগ্রিম মাস (ডিফল্ট)', 'Rent due day of month': 'ভাড়ার নির্ধারিত তারিখ',
 'Statement footer note': 'স্টেটমেন্ট ফুটার নোট', 'Default billing model': 'ডিফল্ট বিলিং মডেল',
 'Default flat rate (৳/mo)': 'ডিফল্ট ফ্ল্যাট রেট (৳/মাস)', 'Default per-sqft rate (৳/sqft/mo)': 'ডিফল্ট স্কয়ার ফিট রেট (৳/বর্গফুট/মাস)',
 'Fine rate (% of bill)': 'ফাইন রেট (বিলের %)', 'Grace days (after due date)': 'গ্রেস দিন (তারিখের পরে)',
 'Minimum fine (৳)': 'সর্বনিম্ন ফাইন (৳)', 'Max cap (% of bill)': 'সর্বোচ্চ সীমা (বিলের %)',
 'Provider': 'প্রোভাইডার', 'Sender ID': 'সেন্ডার আইডি', 'API URL': 'এপিআই ইউআরএল',
 '📨 Recipients': '📨 প্রাপক', 'API key': 'এপিআই কী', 'Receipt / invoice prefix': 'রসিদ / ইনভয়েস প্রিফিক্স',
 'Plan': 'প্ল্যান', 'Expiry': 'মেয়াদ', 'User seats': 'ব্যবহারকারী আসন', 'License holder': 'লাইসেন্সধারী',
 'Bank name': 'ব্যাংকের নাম', 'Account title': 'অ্যাকাউন্ট টাইটেল', 'Account number': 'অ্যাকাউন্ট নম্বর',
 'Footer line on printed receipts': 'প্রিন্টেড রসিদের ফুটার লাইন', 'Display name': 'প্রদর্শনের নাম',
 'Current password': 'বর্তমান পাসওয়ার্ড', 'Space no *': 'দোকান নম্বর *', 'Size (sqft)': 'সাইজ (বর্গফুট)',
 'Service rate (৳/mo)': 'সার্ভিস রেট (৳/মাস)', 'Billing model': 'বিলিং মডেল',
 'Rate per sqft (৳/sqft/mo)': 'স্কয়ার ফিট রেট (৳/বর্গফুট/মাস)', 'Owner name *': 'মালিকের নাম *',
 'Owner mobile': 'মালিকের মোবাইল', 'Owner NID': 'মালিকের এনআইডি', 'Owner (directory)': 'মালিক (ডিরেক্টরি)',
 'Space type': 'দোকানের ধরন', 'Waiver amount (৳)': 'মওকুফের পরিমাণ (৳)', 'Reason *': 'কারণ *',
 'Reference (trx no / note)': 'রেফারেন্স (ট্রানজেকশন নং / নোট)', 'Subject *': 'বিষয় *',
 'Asset name *': 'সম্পদের নাম *', 'Vendor / service provider': 'সরবরাহকারী / সার্ভিস প্রোভাইডার',
 'Install date': 'স্থাপনের তারিখ', 'AMC / contract until': 'এএমসি / চুক্তি পর্যন্ত', 'Cost (৳)': 'মূল্য (৳)',
 'Join date': 'যোগদানের তারিখ', 'Monthly salary (৳)': 'মাসিক বেতন (৳)', 'Email *': 'ইমেইল *',
 'Temporary password *': 'অস্থায়ী পাসওয়ার্ড *', 'Space (owner of)': 'দোকান (যার মালিক)',
 'Agenda': 'এজেন্ডা', 'Decisions': 'সিদ্ধান্ত', 'Minutes / notes': 'কার্যবিবরণী / নোট',
 'Number': 'নম্বর', 'Resolution text': 'রেজুলেশন টেক্সট', 'Linked meeting (optional)': 'সংযুক্ত সভা (ঐচ্ছিক)',
 'Name / entity name *': 'নাম / প্রতিষ্ঠানের নাম *', 'NID (person) / TIN': 'এনআইডি (ব্যক্তি) / টিআইএন',
 'Trade license (company)': 'ট্রেড লাইসেন্স (কোম্পানি)', 'Employer / business': 'নিয়োগকর্তা / ব্যবসা',
 'Monthly rent (৳)': 'মাসিক ভাড়া (৳)', 'Advance months': 'অগ্রিম মাস', 'Start date': 'শুরুর তারিখ',
 'End date (optional)': 'শেষ তারিখ (ঐচ্ছিক)', 'Rent due day': 'ভাড়ার নির্ধারিত তারিখ', 'Reference': 'রেফারেন্স',
 'Vendor name *': 'সরবরাহকারীর নাম *', 'Reference / cheque no': 'রেফারেন্স / চেক নং',
 'Note (what is this for?)': 'নোট (কিসের জন্য?)', 'Account name *': 'অ্যাকাউন্টের নাম *',
 'Parent group': 'প্যারেন্ট গ্রুপ', '🧾 Party type': '🧾 পক্ষের ধরন', 'Reference (auto JV-####)': 'রেফারেন্স (অটো জেভি-####)',
 'Voucher lines': 'ভাউচার লাইন', 'Note / description': 'নোট / বর্ণনা', '⬆ Upload image': '⬆ ছবি আপলোড',
 # placeholders
 'optional': 'ঐচ্ছিক', 'Select space…': 'দোকান নির্বাচন…', 'Add head…': 'হেড যোগ করুন…',
 '— choose category —': '— ক্যাটাগরি নির্বাচন —', '— choose vendor —': '— সরবরাহকারী নির্বাচন —',
 'e.g. Monthly lift AMC — invoice #88412': 'যেমন মাসিক লিফট এএমসি — ইনভয়েস #৮৮৪১২',
 '— choose income head —': '— আয়ের খাত নির্বাচন —', 'e.g. Razzak Plaza': 'যেমন রাজ্জাক প্লাজা',
 'e.g. 42 Motijheel C/A, Dhaka 1000': 'যেমন ৪২ মতিঝিল সি/এ, ঢাকা ১০০০', 'office@razzakplaza.com': 'office@razzakplaza.com',
 'DESCO main bill ৳…': 'ডেসকো মূল বিল ৳…', 'https://api.bulksmsbd.com/smsapi': 'https://api.bulksmsbd.com/smsapi',
 'Test phone (01XXXXXXXXX)…': 'টেস্ট ফোন (01XXXXXXXXX)…', 'Account…': 'অ্যাকাউন্ট…',
 'New role, e.g. Auditor': 'নতুন রোল, যেমন অডিটর', 'RCT': 'RCT', 'e.g. Islami Bank Bangladesh PLC': 'যেমন ইসলামী ব্যাংক বাংলাদেশ পিএলসি',
 'e.g. 205-123-4567': 'যেমন 205-123-4567', 'required to change password': 'পাসওয়ার্ড পরিবর্তন আবশ্যক',
 'e.g. A-101': 'যেমন A-101', 'e.g. Ground': 'যেমন গ্রাউন্ড',
 '— standalone (name above) —': '— স্বতন্ত্র (উপরের নাম) —',
 'e.g. shop closed 10 days for renovation — committee case #12': 'যেমন সংস্কারের জন্য ১০ দিন দোকান বন্ধ — কমিটি কেস #১২',
 'e.g. Lift not working on 2nd floor': 'যেমন ২য় তলায় লিফট কাজ করছে না', 'Describe the issue…': 'সমস্যা বর্ণনা করুন…',
 'e.g. Passenger Lift 1': 'যেমন প্যাসেঞ্জার লিফট ১', 'e.g. Block A, near main entrance': 'যেমন ব্লক এ, প্রধান প্রবেশপথের কাছে',
 'e.g. Otis Elevator': 'যেমন ওটিস এলিভেটর', 'Any notes…': 'যেকোনো নোট…',
 'e.g. Generator maintenance on Sunday 10am–2pm': 'যেমন রবিবার সকাল ১০টা–২টায় জেনারেটর মেইনটেন্যান্স',
 'Full announcement…': 'সম্পূর্ণ ঘোষণা…', 'e.g. Md. Karim': 'যেমন মো. করিম', 'Shift, remarks…': 'শিফট, মন্তব্য…',
 'Optional — voucher / remark': 'ঐচ্ছিক — ভাউচার / মন্তব্য', 'e.g. secretary@razzakplaza.com': 'যেমন secretary@razzakplaza.com',
 'min 8 characters — user changes it on first login': 'সর্বনিম্ন ৮ অক্ষর — প্রথম লগইনে ব্যবহারকারী বদলাবে',
 'Independent / no shop': 'স্বাধীন / কোনো দোকান নেই', 'e.g. 2024–2026': 'যেমন ২০২৪–২০২৬', 'e.g. Annual General Meeting 2026': 'যেমন বার্ষিক সাধারণ সভা ২০২৬',
 'Agenda items…': 'এজেন্ডা আইটেম…', 'What was decided…': 'কী সিদ্ধান্ত হলো…',
 'Full minutes or notes (stored as the governance record)…': 'সম্পূর্ণ কার্যবিবরণী বা নোট (গভর্নেন্স রেকর্ড হিসেবে সংরক্ষিত)…',
 'RES-2026-01': 'RES-2026-01', 'e.g. 5% service charge increase from October': 'যেমন অক্টোবর থেকে ৫% সার্ভিস চার্জ বৃদ্ধি',
 'The full resolution text — archived as the governance record…': 'সম্পূর্ণ রেজুলেশন টেক্সট — গভর্নেন্স রেকর্ড হিসেবে সংরক্ষিত…',
 '— none —': '— নেই —', 'e.g. Rahim Uddin or Rahim Traders Ltd': 'যেমন রহিম উদ্দিন বা রহিম ট্রেডার্স লিমিটেড',
 'for companies': 'কোম্পানির জন্য', 'e.g. Abdul Kader': 'যেমন আব্দুল কাদের', 'e.g. 01800-000000': 'যেমন 01800-000000',
 'e.g. Mobile accessories shop': 'যেমন মোবাইল এক্সেসরিজের দোকান', '— choose space —': '— দোকান নির্বাচন —',
 '— choose tenant —': '— ভাড়াটিয়া নির্বাচন —', 'e.g. 25000': 'যেমন 25000', 'e.g. 3': 'যেমন ৩',
 'e.g. Otis Elevator Co.': 'যেমন ওটিস এলিভেটর কো.', 'e.g. 8500': 'যেমন 8500', 'e.g. Lift AMC — August': 'যেমন লিফট এএমসি — আগস্ট',
 'e.g. 5080': 'যেমন 5080', 'e.g. Generator Fuel': 'যেমন জেনারেটর ফুয়েল', '— top level —': '— শীর্ষ স্তর —',
 'JV-00001': 'JV-00001', 'Account… (search by code or name)': 'অ্যাকাউন্ট… (কোড বা নাম দিয়ে সার্চ)',
 '৳': '৳', '🧾 Party…': '🧾 পক্ষ…', 'e.g. Generator fuel for August': 'যেমন আগস্টের জেনারেটর ফুয়েল',
 '🔍 Search shop no / owner / mobile…': '🔍 দোকান নং / মালিক / মোবাইল সার্চ…',
 '🔍 Search account / code / type…': '🔍 অ্যাকাউন্ট / কোড / ধরন সার্চ…',
 '🔍 Search user / action / module…': '🔍 ব্যবহারকারী / অ্যাকশন / মডিউল সার্চ…',
 'min 8 characters': 'সর্বনিম্ন ৮ অক্ষর', 'e.g. 01711-000000': 'যেমন 01711-000000', 'e.g. 02-9551234': 'যেমন 02-9551234',
 'e.g. Alhaj Md. Abdul Razzak': 'যেমন আলহাজ মো. আব্দুল রাজ্জাক', 'e.g. Md. Shahidullah': 'যেমন মো. শাহিদুল্লাহ',
 # options
 'All statuses': 'সব স্ট্যাটাস', 'All kinds': 'সব ধরন', 'Unpaid': 'বকেয়া',
 'Bank account…': 'ব্যাংক অ্যাকাউন্ট…', '⚡ Electricity': '⚡ বিদ্যুৎ', '💧 Water': '💧 পানি',
 'Open': 'খোলা', 'In Progress': 'চলমান', 'Resolved': 'সমাধানকৃত',
 'Fixed + utilities': 'ফিক্সড + ইউটিলিটি', 'Per sqft + utilities': 'স্কয়ার ফিট + ইউটিলিটি',
 'Log (testing — no real send)': 'লগ (পরীক্ষা — আসল পাঠানো হয় না)', 'bulksmsbd (real gateway)': 'bulksmsbd (আসল গেটওয়ে)',
 '👤 Owner only': '👤 শুধু মালিক', '🧑‍💼 Tenant only': '🧑‍💼 শুধু ভাড়াটিয়া',
 'Fixed + utilities (metered)': 'ফিক্সড + ইউটিলিটি (মিটারযুক্ত)', 'Per sqft + utilities (metered)': 'স্কয়ার ফিট + ইউটিলিটি (মিটারযুক্ত)',
 'Any party': 'যেকোনো পক্ষ', '🧰 Vendors': '🧰 সরবরাহকারী', '🏢 Owners': '🏢 মালিক',
 '🧑🤝🧑 Tenants': '🧑🤝🧑 ভাড়াটিয়া', '🧑‍💼 Staff': '🧑‍💼 স্টাফ', 'Fixed (flat monthly)': 'ফিক্সড (ফ্ল্যাট মাসিক)',
 'Per sqft (rate × size)': 'স্কয়ার ফিট (রেট × সাইজ)',
}

# strings to SKIP wrapping (icons / technical)
SKIP = {'✕', '🗑️', '✏️', '👁', '🔑', '📎', '◀', '▶', 'Dr', 'Cr', '৳'}

def do_replace(src, patterns):
    for old, new in patterns:
        src = src.replace(old, new)
    return src

def wrap_labels(src, T, SKIP):
    # label text followed by whitespace + child tag or end: >TEXT  <input...
    for s in T:
        if s in SKIP: continue
        pat = re.compile(r'>' + re.escape(s) + r'(?=\s*<|\s*$)', re.M)
        src = pat.sub('>{{ t(\'' + s + '\') }}', src)
    return src

patterns = []
for s, bn in T.items():
    if s in SKIP: continue
    # buttons
    patterns.append((f'>{s}</button>', f">{{{{ t('{s}') }}}}</button>"))
    # options
    patterns.append((f'>{s}</option>', f">{{{{ t('{s}') }}}}</option>"))
    # th (with closing)
    patterns.append((f'>{s}</th>', f">{{{{ t('{s}') }}}}</th>"))
    # placeholder (not :placeholder)
for s, bn in T.items():
    if s in SKIP: continue
    patterns.append((f'placeholder="{s}"', f":placeholder=\"t('{s}')\""))

new_tpl = do_replace(tpl, patterns)
new_tpl = wrap_labels(new_tpl, T, SKIP)
# reassemble
mv_new = mv[:mv.find('</script>') + 9] + new_tpl
open(MV, 'w').write(mv_new)

# ── dict injection ─────────────────────────────────────────────────
i18n = open(I18N).read()
bn_start = i18n.find('const BN =')
brace = i18n.find('{', bn_start)
tail = i18n[brace:]
depth = 0; end = None
for i, ch in enumerate(tail):
    if ch == '{': depth += 1
    elif ch == '}':
        depth -= 1
        if depth == 0: end = brace + i; break
block = '\n'.join(f"  {json.dumps(k, ensure_ascii=False)}: {json.dumps(v, ensure_ascii=False)}," for k, v in T.items())
i18n = i18n[:end] + '\n  /* ── Mall buttons & forms (sweep) ── */\n' + block + '\n' + i18n[end:]
open(I18N, 'w').write(i18n)
print('wrapped', len(T), 'strings; dict injected', len(T))
