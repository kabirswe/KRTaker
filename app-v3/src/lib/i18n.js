// KRTaker i18n — V2.30 full Bangla (বাংলা) localization layer.
// Usage: import { lang, t, useT } from '../lib/i18n'
//   t('key')         → translated string (falls back to key if missing)
//   useT() in <script setup> → reactive: const { t } = useT()
// Language is persisted in localStorage 'krtaker_dash_lang' ('en' | 'bn').
// The topbar toggle (Topbar.vue) calls setLang('bn'|'en') which flips a
// document-level event so every view re-renders reactively.
import { ref, computed } from 'vue'

export const LANGS = ['en', 'bn']
const KEY = 'krtaker_dash_lang'

export const lang = ref('en')
try { lang.value = localStorage.getItem(KEY) || 'en' } catch (e) { /* ssr/headless */ }
if (!LANGS.includes(lang.value)) lang.value = 'en'

export function setLang(l) {
  if (!LANGS.includes(l)) l = 'en'
  lang.value = l
  try { localStorage.setItem(KEY, l) } catch (e) { /* ignore */ }
  document.dispatchEvent(new CustomEvent('krt-lang', { detail: l }))
  document.documentElement.setAttribute('lang', l)
}
export function toggleLang() { setLang(lang.value === 'bn' ? 'en' : 'bn') }
export const isBn = computed(() => lang.value === 'bn')

// ── Dictionary (en key → bn value). Missing keys fall back to the English key. ──
const BN = {
  // ── App shell / nav ──
  Overview: 'ওভারভিউ', Portfolio: 'পোর্টফোলিও', Finance: 'ফাইনান্স', BMS: 'বিএমএস',
  Community: 'কমিউনিটি', Legal: 'লিগ্যাল', Operations: 'অপারেশনস', 'Safety & Security': 'নিরাপত্তা ও সুরক্ষা', Admin: 'অ্যাডমিন',
  Analytics: 'অ্যানালিটিক্স', 'AI Caretaker (KR)': 'এআই কেয়ারটেকার (KR)', 'Wiki & Help': 'উইকি ও সাহায্য',
  Vendors: 'ভেন্ডর', Caretaker: 'কেয়ারটেকার', Properties: 'প্রপার্টি', Units: 'ইউনিট', Tenants: 'ভাড়াটিয়া',
  Leases: 'লিজ', Insurance: 'বীমা', Onboarding: 'অনবোর্ডিং', Leads: 'লিড', Documents: 'ডকুমেন্ট', Templates: 'টেমপ্লেট',
  Invoices: 'ইনভয়েস', Receipts: 'রসিদ', Payments: 'পেমেন্ট', 'Holding taxes': 'হোল্ডিং ট্যাক্স', Remittances: 'রেমিট্যান্স',
  Collections: 'আদায়', Statements: 'স্টেটমেন্ট', Accounts: 'অ্যাকাউন্ট', Notices: 'নোটিশ', Referrals: 'রেফারেল',
  'Trust (NID)': 'ট্রাস্ট (NID)', Support: 'সাপোর্ট', Maintenance: 'মেইনটেন্যান্স', 'Utility bills': 'ইউটিলিটি বিল',
  Staff: 'স্টাফ', Attendance: 'উপস্থিতি', Payroll: 'পে-রোল', 'Meter readings': 'মিটার রিডিং', Compliance: 'কমপ্লায়েন্স',
  Cases: 'কেস', Concierge: 'কনসিয়ার্জ', 'Building systems': 'বিল্ডিং সিস্টেম', Land: 'জমি', Build: 'নির্মাণ',
  'Gate visits': 'গেট ভিজিট', 'Fire safety': 'ফায়ার সেফটি', KYC: 'কেওয়াইসি', Inspections: 'পরিদর্শন', Samity: 'সমিতি',
  'Switch role': 'ভূমিকা পরিবর্তন', 'Back to my account': 'আমার অ্যাকাউন্টে ফিরুন', Logout: 'লগআউট', 'Sign out': 'সাইন আউট',
  Search: 'খুঁজুন', 'Global search': 'গ্লোবাল সার্চ', 'Dark': 'ডার্ক', 'Light': 'লাইট',
  'Welcome back': 'আবারও স্বাগতম', 'Log in': 'লগ ইন', 'Create an account': 'অ্যাকাউন্ট তৈরি করুন',
  'Email address': 'ইমেইল ঠিকানা', Password: 'পাসওয়ার্ড', 'Forgot password?': 'পাসওয়ার্ড ভুলে গেছেন?',
  'Remember me': 'মনে রাখুন', 'Sign in': 'সাইন ইন', 'Two-factor code': 'টু-ফ্যাক্টর কোড',
  'Send code': 'কোড পাঠান', 'Verify': 'যাচাই করুন', 'Invalid email or password': 'ভুল ইমেইল বা পাসওয়ার্ড',

  // ── Common actions ──
  New: 'নতুন', Add: 'যোগ করুন', Save: 'সেভ করুন', Cancel: 'বাতিল', Edit: 'সম্পাদনা', Delete: 'মুছুন',
  Close: 'বন্ধ', Open: 'খোলা', Search: 'খুঁজুন', Filter: 'ফিল্টার', Export: 'এক্সপোর্ট', Import: 'ইমপোর্ট',
  Print: 'প্রিন্ট', Email: 'ইমেইল', Send: 'পাঠান', Update: 'আপডেট', View: 'দেখুন', All: 'সব',
  Yes: 'হ্যাঁ', No: 'না', OK: 'ঠিক আছে', Back: 'ফিরুন', Next: 'পরবর্তী', Done: 'সম্পন্ন', Skip: 'স্কিপ',
  Loading: 'লোড হচ্ছে…', 'No data': 'কোনো ডেটা নেই', 'No results': 'কোনো ফলাফল নেই', Retry: 'আবার চেষ্টা করুন',
  'Total': 'মোট', Status: 'স্ট্যাটাস', Actions: 'অ্যাকশন', Date: 'তারিখ', Amount: 'পরিমাণ', Name: 'নাম',
  Title: 'শিরোনাম', Description: 'বিবরণ', Category: 'বিভাগ', Priority: 'অগ্রাধিকার', Type: 'ধরন',
  Details: 'বিস্তারিত', Notes: 'নোট', 'Due date': 'নির্ধারিত তারিখ', 'Created at': 'তৈরির সময়', Updated: 'আপডেটেড',

  // ── Dashboard / KPIs ──
  Revenue: 'রাজস্ব', Occupancy: 'অকুপেন্সি', Alerts: 'সতর্কতা', 'Total rent': 'মোট ভাড়া',
  Collected: 'আদায়কৃত', Outstanding: 'বকেয়া', 'Gross income': 'মোট আয়', 'Net income': 'নিট আয়',
  'Monthly rent': 'মাসিক ভাড়া', 'Units occupied': 'অধিকৃত ইউনিট', 'Open alerts': 'খোলা সতর্কতা',
  'Welcome, ': 'স্বাগতম, ', 'Good morning': 'শুভ সকাল', 'Good afternoon': 'শুভ অপরাহ্ন', 'Good evening': 'শুভ সন্ধ্যা',
  'AI Caretaker': 'এআই কেয়ারটেকার', 'Ask anything…': 'যেকোনো প্রশ্ন করুন…', 'Send question': 'প্রশ্ন পাঠান',

  // ── Notifications ──
  Notifications: 'নোটিফিকেশন', 'Mark all read': 'সব পঠিত করুন', 'Clear all': 'সব মুছুন', Unread: 'অপঠিত',
  'SLA breach': 'এসএলএ লঙ্ঘন', 'Compliance expiry': 'কমপ্লায়েন্স মেয়াদ', Arrears: 'বকেয়া', Renewals: 'নবায়ন',
  System: 'সিস্টেম', critical: 'জরুরি', warning: 'সতর্কতা', info: 'তথ্য', 'severity': 'গুরুত্ব',

  // ── Settings ──
  Settings: 'সেটিংস', Profile: 'প্রোফাইল', Notifications: 'নোটিফিকেশন', Security: 'নিরাপত্তা',
  'Two-factor auth (2FA)': 'টু-ফ্যাক্টর প্রমাণীকরণ (2FA)', 'Change password': 'পাসওয়ার্ড পরিবর্তন',
  'Email OTP': 'ইমেইল ওটিপি', 'Authenticator app': 'অথেন্টিকেটর অ্যাপ', 'Enable 2FA': '2FA চালু করুন',
  'Disable 2FA': '2FA বন্ধ করুন', 'Current password': 'বর্তমান পাসওয়ার্ড', 'New password': 'নতুন পাসওয়ার্ড',
  'Confirm password': 'পাসওয়ার্ড নিশ্চিত করুন', 'Browser notifications': 'ব্রাউজার নোটিফিকেশন',
  'Subscribe push': 'পুশ সাবস্ক্রাইব', 'Rent reminders': 'ভাড়া রিমাইন্ডার', 'Collections digest': 'আদায় ডাইজেস্ট',
  'Weekly digest': 'সাপ্তাহিক ডাইজেস্ট', 'Language': 'ভাষা', 'বাংলা': 'বাংলা', 'English': 'English',

  // ── Setup wizard ──
  'Get started': 'শুরু করুন', 'Skip for now': 'এখনই নয়', 'Welcome to KRTaker': 'KRTaker-এ স্বাগতম',
  'Your first property': 'আপনার প্রথম প্রপার্টি', 'Property name': 'প্রপার্টির নাম', 'Property type': 'প্রপার্টির ধরন',
  'Notification preferences': 'নোটিফিকেশন পছন্দ', 'Secure your account': 'অ্যাকাউন্ট সুরক্ষিত করুন',
  'Everything is set up': 'সবকিছু সেটআপ হয়েছে', 'Open dashboard': 'ড্যাশবোর্ড খুলুন',
  'Step': 'ধাপ', 'of': 'এর মধ্যে', 'Profile details': 'প্রোফাইলের বিবরণ', 'Full name': 'পুরো নাম', Company: 'কোম্পানি',

  // ── Finance ──
  'New invoice': 'নতুন ইনভয়েস', 'Record payment': 'পেমেন্ট রেকর্ড করুন', 'Auto-generate': 'অটো-জেনারেট',
  Paid: 'পরিশোধিত', 'Partially paid': 'আংশিক পরিশোধিত', Unpaid: 'অপরিশোধিত', Sent: 'পাঠানো', Draft: 'খসড়া',
  Void: 'বাতিল', 'Invoice ID': 'ইনভয়েস আইডি', 'Issue receipt': 'রসিদ ইস্যু করুন', Method: 'পদ্ধতি',
  bKash: 'বিকাশ', Nagad: 'নগদ', Bank: 'ব্যাংক', Cash: 'নগদ', 'Due': 'বকেয়া', Overdue: 'অতিরিক্ত বকেয়া',

  // ── Tenants / Properties ──
  'New property': 'নতুন প্রপার্টি', 'New tenant': 'নতুন ভাড়াটিয়া', 'New unit': 'নতুন ইউনিট', 'New lease': 'নতুন লিজ',
  'Add unit': 'ইউনিট যোগ করুন', 'Assign tenant': 'ভাড়াটিয়া নিয়োগ', 'Move in': 'মুভ ইন', 'Move out': 'মুভ আউট',
  Occupied: 'অধিকৃত', Vacant: 'খালি', 'Rent amount': 'ভাড়ার পরিমাণ', 'Security deposit': 'সিকিউরিটি ডিপোজিট',
  'Lease start': 'লিজ শুরু', 'Lease end': 'লিজ শেষ', 'Phone': 'ফোন', 'NID': 'এনআইডি', Address: 'ঠিকানা',
  'Emergency contact': 'জরুরি যোগাযোগ', 'Owner name': 'মালিকের নাম', 'Property address': 'প্রপার্টির ঠিকানা',

  // ── Wiki / Support ──
  'Wiki & Help': 'উইকি ও সাহায্য', 'Search the wiki': 'উইকি খুঁজুন', 'Expand all': 'সব খুলুন', Collapse: 'সব বন্ধ করুন',
  articles: 'নিবন্ধ', 'Still stuck?': 'আরও সাহায্য দরকার?', 'Open a support ticket': 'সাপোর্ট টিকেট খুলুন',
  'Review my settings': 'আমার সেটিংস দেখুন', 'New ticket': 'নতুন টিকেট', 'Ticket ID': 'টিকেট আইডি',
  'In progress': 'চলমান', Resolved: 'সমাধানকৃত', Closed: 'বন্ধ', 'Reply…': 'জবাব লিখুন…',
  'Send reply': 'জবাব পাঠান', 'General': 'সাধারণ', Billing: 'বিলিং', Technical: 'প্রযুক্তিগত',
  'Feature request': 'ফিচার রিকোয়েস্ট', Other: 'অন্যান্য', Low: 'নিম্ন', Medium: 'মাঝারি', High: 'উচ্চ', Urgent: 'জরুরি',

  // ── Notices / Community ──
  'New notice': 'নতুন নোটিশ', 'Notice board': 'নোটিশ বোর্ড', 'Also email to tenants': 'ভাড়াটিয়াদের ইমেইলও পাঠান',
  'Post notice': 'নোটিশ পোস্ট করুন', 'Recipients': 'গ্রহীতারা', 'Referral code': 'রেফারেল কোড', 'Earn': 'আয় করুন',
  'Invite friends': 'বন্ধুদের আমন্ত্রণ জানান', 'Earn ৳5,000 per referral': 'প্রতি রেফারেলে ৳৫,০০০ আয় করুন',

  // ── Maintenance ──
  'Raise ticket': 'টিকেট তৈরি করুন', 'SLA': 'এসএলএ', 'On track': 'ঠিক পথে', 'Time left': 'অবশিষ্ট সময়',
  Landlord: 'বাড়িওয়ালা', Tenant: 'ভাড়াটিয়া', Shared: 'ভাগাভাগি', 'Work order': 'ওয়ার্ক অর্ডার',
  Plumbing: 'প্লাম্বিং', Electrical: 'ইলেকট্রিক্যাল', HVAC: 'এইচভিএসি', 'In progress': 'চলমান',

  // ── Misc ──
  'Something went wrong': 'কিছু একটা সমস্যা হয়েছে', 'Network error': 'নেটওয়ার্ক সমস্যা', 'Try again': 'আবার চেষ্টা করুন',
  'No permission': 'অনুমতি নেই', 'Access denied': 'অ্যাক্সেস অস্বীকার করা হয়েছে', 'Session expired': 'সেশন মেয়াদোত্তীর্ণ',
  'Please log in again': 'আবার লগ ইন করুন', 'Logging in…': 'লগ ইন হচ্ছে…', 'Saving…': 'সেভ হচ্ছে…',
  'Loading…': 'লোড হচ্ছে…', 'Saved': 'সেভ হয়েছে', 'Copied': 'কপি হয়েছে', 'Copied to clipboard': 'ক্লিপবোর্ডে কপি হয়েছে',
  'Confirm': 'নিশ্চিত করুন', 'Are you sure?': 'আপনি কি নিশ্চিত?', 'This action cannot be undone': 'এই কাজটি ফেরানো যাবে না',

  // ── Batch pass 2 — all module views (V2.30.1) ──
  Account: 'অ্যাকাউন্ট', Action: 'অ্যাকশন', Applicant: 'আবেদনকারী',
  'Ask about rent, tickets, leases…': 'ভাড়া, টিকেট, লিজ সম্পর্কে জানুন…',
  Asset: 'সম্পদ', 'Assign to (e.g. Arif Chowdhury)': 'নিয়োগ করুন (যেমন: আরিফ চৌধুরী)',
  Assigned: 'নিয়োগকৃত', Bonus: 'বোনাস', Cat: 'বিভাগ', 'Check-in': 'চেক-ইন', 'Check-out': 'চেক-আউট',
  Checks: 'চেক', Code: 'কোড', Contact: 'যোগাযোগ', DOB: 'জন্ম তারিখ', Days: 'দিন',
  'Days left': 'অবশিষ্ট দিন', Decided: 'নির্ধারিত', Deduction: 'কর্তন', Desc: 'বিবরণ',
  'Due soon': 'শীঘ্রই বকেয়া', Effective: 'কার্যকর', Expenses: 'ব্যয়', Expiry: 'মেয়াদ',
  File: 'ফাইল', 'Go to current month': 'বর্তমান মাসে যান', Gross: 'মোট', Hours: 'ঘণ্টা',
  Inspection: 'পরিদর্শন', Invoice: 'ইনভয়েস', Job: 'জব', Kind: 'ধরন', Label: 'লেবেল',
  Last: 'শেষ', Lead: 'লিড', Lease: 'লিজ', Linked: 'লিংককৃত', Location: 'অবস্থান',
  Month: 'মাস', Net: 'নিট', Note: 'নোট', 'Notes…': 'নোট…', 'Notice days': 'নোটিশ দিন',
  'Optional — e.g. owner reading': 'ঐচ্ছিক — যেমন: মালিকের রিডিং', Owner: 'মালিক',
  Partner: 'পার্টনার', Payee: 'প্রাপক', Payment: 'পেমেন্ট', Payout: 'পেআউট',
  Proof: 'প্রমাণ', Property: 'প্রপার্টি', Reading: 'রিডিং', Receipt: 'রসিদ', Recon: 'রিকন',
  Ref: 'রেফ', Referred: 'রেফারড', Registration: 'রেজিস্ট্রেশন', Rent: 'ভাড়া',
  'Rent / mo': 'ভাড়া/মাস', Reward: 'রেওয়ার্ড', Role: 'ভূমিকা', Salary: 'বেতন',
  'Search assets…': 'সম্পদ খুঁজুন…', 'Search bill, unit, tenant…': 'বিল, ইউনিট, ভাড়াটিয়া খুঁজুন…',
  'Search code / title / assignee…': 'কোড / শিরোনাম / দায়িত্বপ্রাপ্ত খুঁজুন…',
  'Search desc, job, submitter…': 'বিবরণ, জব, প্রেরক খুঁজুন…',
  'Search holding no, property…': 'হোল্ডিং নং, প্রপার্টি খুঁজুন…',
  'Search label, ref, notes…': 'লেবেল, রেফ, নোট খুঁজুন…',
  'Search label, ref, payee…': 'লেবেল, রেফ, প্রাপক খুঁজুন…',
  'Search lease, tenant, unit…': 'লিজ, ভাড়াটিয়া, ইউনিট খুঁজুন…',
  'Search name, email, code…': 'নাম, ইমেইল, কোড খুঁজুন…',
  'Search name, email, message…': 'নাম, ইমেইল, বার্তা খুঁজুন…',
  'Search name, email, unit…': 'নাম, ইমেইল, ইউনিট খুঁজুন…',
  'Search name, khatian, dag…': 'নাম, খতিয়ান, দাগ খুঁজুন…',
  'Search name, phone, flat…': 'নাম, ফোন, ফ্ল্যাট খুঁজুন…',
  'Search name, phone…': 'নাম, ফোন খুঁজুন…',
  'Search name, ref, uploader…': 'নাম, রেফ, আপলোডার খুঁজুন…',
  'Search name, vehicle, property…': 'নাম, গাড়ি, প্রপার্টি খুঁজুন…',
  'Search notice, tenant, reason…': 'নোটিশ, ভাড়াটিয়া, কারণ খুঁজুন…',
  'Search parcel, property, ref…': 'পার্সেল, প্রপার্টি, রেফ খুঁজুন…',
  'Search partner, ref…': 'পার্টনার, রেফ খুঁজুন…',
  'Search partner, trade, category…': 'পার্টনার, ট্রেড, বিভাগ খুঁজুন…',
  'Search payment, invoice, tenant…': 'পেমেন্ট, ইনভয়েস, ভাড়াটিয়া খুঁজুন…',
  'Search reading, unit, tenant…': 'রিডিং, ইউনিট, ভাড়াটিয়া খুঁজুন…',
  'Search receipt, invoice, tenant…': 'রসিদ, ইনভয়েস, ভাড়াটিয়া খুঁজুন…',
  'Search ref, form C, owner…': 'রেফ, ফর্ম সি, মালিক খুঁজুন…',
  'Search staff, notes…': 'স্টাফ, নোট খুঁজুন…', 'Search staff…': 'স্টাফ খুঁজুন…',
  'Search tenant, NID…': 'ভাড়াটিয়া, এনআইডি খুঁজুন…',
  'Search tenant, plan, claim…': 'ভাড়াটিয়া, প্ল্যান, ক্লেইম খুঁজুন…',
  'Search title, contractor, site…': 'শিরোনাম, ঠিকাদার, সাইট খুঁজুন…',
  'Search title, notes, lawyer…': 'শিরোনাম, নোট, আইনজীবী খুঁজুন…',
  'Search unit, property, tenant…': 'ইউনিট, প্রপার্টি, ভাড়াটিয়া খুঁজুন…',
  Service: 'সার্ভিস', Sig: 'স্বাক্ষর', Size: 'আকার', Source: 'উৎস', Start: 'শুরু',
  TDS: 'টিডিএস', Term: 'মেয়াদ', Ticket: 'টিকেট', Tier: 'টিয়ার', Today: 'আজ',
  Unit: 'ইউনিট', 'Unit / Property': 'ইউনিট / প্রপার্টি', 'Unit · Property': 'ইউনিট · প্রপার্টি',
  Upcoming: 'আসন্ন', Uploaded: 'আপলোডকৃত', Value: 'মান', Vehicle: 'গাড়ি', Via: 'মাধ্যমে',
  Visitor: 'দর্শনার্থী', 'Yes, send emails': 'হ্যাঁ, ইমেইল পাঠান', 'Yes, send push': 'হ্যাঁ, পুশ পাঠান',
  'e.g. 5120': 'যেমন: 5120',
  // Hub tab labels (V2.30.1)
  Reminders: 'রিমাইন্ডার', 'Holding Tax': 'হোল্ডিং ট্যাক্স', Subscriptions: 'সাবস্ক্রিপশন',
  Units: 'ইউনিট', 'Notice Board': 'নোটিশ বোর্ড', 'NID & Trust': 'এনআইডি ও ট্রাস্ট',
  'Legal Engine': 'লিগ্যাল ইঞ্জিন', 'Legal Concierge': 'লিগ্যাল কনসিয়ার্জ',
  'Land Guard': 'ল্যান্ড গার্ড', 'Build Watch': 'নির্মাণ মনিটর',
  // Emoji section headings (V2.30.1)
  '⏰ Rent Reminder Automation': '⏰ ভাড়া রিমাইন্ডার অটোমেশন', '⚖️ Cases': '⚖️ কেস',
  '⚖️ Compliance': '⚖️ কমপ্লায়েন্স', '⚖️ Legal': '⚖️ লিগ্যাল', '🌍 Remittances': '🌍 রেমিট্যান্স',
  '🏗️ Build Watch': '🏗️ নির্মাণ মনিটর', '🏘️ Samity': '🏘️ সমিতি',
  '🏛️ Holding Taxes': '🏛️ হোল্ডিং ট্যাক্স', '🏠 Building Systems': '🏠 বিল্ডিং সিস্টেম',
  '🏢 Portfolio': '🏢 পোর্টফোলিও', '👷 Staff': '👷 স্টাফ', '👷 Staff Attendance': '👷 স্টাফ উপস্থিতি',
  '💰 Finance': '💰 ফাইনান্স', '💰 Staff Payroll': '💰 স্টাফ পে-রোল',
  '💰 Vendor Payouts': '💰 ভেন্ডর পেআউট', '💳 Payments': '💳 পেমেন্ট',
  '💼 Accounts': '💼 অ্যাকাউন্ট', '📁 Documents': '📁 ডকুমেন্ট', '📄 Leases': '📄 লিজ',
  '📈 Analytics': '📈 অ্যানালিটিক্স', '📋 Onboarding': '📋 অনবোর্ডিং', '📎 Receipts': '📎 রসিদ',
  '📏 Meter readings': '📏 মিটার রিডিং', '📜 Legal Engine': '📜 লিগ্যাল ইঞ্জিন',
  '📢 Community': '📢 কমিউনিটি', '📥 Leads': '📥 লিড', '🔍 Inspections': '🔍 পরিদর্শন',
  '🔧 BMS': '🔧 বিএমএস', '🗂️ Legal Concierge': '🗂️ লিগ্যাল কনসিয়ার্জ',
  '🗂️ Templates': '🗂️ টেমপ্লেট', '🚪 Gate Visits': '🚪 গেট ভিজিট', '🚪 Units': '🚪 ইউনিট',
  '🛠️ Vendors': '🛠️ ভেন্ডর', '🛡️ Insurance': '🛡️ বীমা', '🛰️ Land Guard': '🛰️ ল্যান্ড গার্ড',
  '🤖 AI Caretaker (KR)': '🤖 এআই কেয়ারটেকার (KR)', '🤝 Referrals': '🤝 রেফারেল',
  '🧯 Fire Safety': '🧯 ফায়ার সেফটি', '🧾 Partner Invoices': '🧾 পার্টনার ইনভয়েস',
  '🧾 Utility bills': '🧾 ইউটিলিটি বিল', '🪪 NID Verification': '🪪 এনআইডি ভেরিফিকেশন',
  '🪪 Tenant KYC': '🪪 ভাড়াটিয়া কেওয়াইসি',
  // Pass-2 leftovers (previously-done views)
  'Save preferences': 'পছন্দ সেভ করুন', 'Save profile': 'প্রোফাইল সেভ করুন',
  'Search invoice, tenant, unit…': 'ইনভয়েস, ভাড়াটিয়া, ইউনিট খুঁজুন…',
  'Search name, address, holding…': 'নাম, ঠিকানা, হোল্ডিং খুঁজুন…',
  'Search subject, sender…': 'বিষয়, প্রেরক খুঁজুন…',
  'Search title, body, author…': 'শিরোনাম, বডি, লেখক খুঁজুন…',
  'Search title, unit, property…': 'শিরোনাম, ইউনিট, প্রপার্টি খুঁজুন…',
  'What do you need help with?': 'আপনি কী বিষয়ে সাহায্য চান?', "What's the issue?": 'সমস্যাটি কী?',
  '6-digit code': '৬ সংখ্যার কোড',
  'e.g. Alamgir Kabir Roni': 'যেমন: আলমগীর কবির রনি',
  'e.g. Green View Residency': 'যেমন: গ্রিন ভিউ রেসিডেন্সি',
  'e.g. House 12, Road 5, Dhanmondi, Dhaka': 'যেমন: বাড়ি ১২, রোড ৫, ধানমন্ডি, ঢাকা',
  'e.g. Kabir Holdings': 'যেমন: কবির হোল্ডিংস',
  'e.g. Kitchen sink leakage': 'যেমন: রান্নাঘরের সিঙ্ক লিক',
  Age: 'বয়স', Author: 'লেখক', From: 'থেকে', Notice: 'নোটিশ', OT: 'ওটি',
  Posted: 'পোস্টকৃত', Prio: 'অগ্রাধিকার', Subject: 'বিষয়', Tickets: 'টিকেট',
  '📮 Collections & Recon': '📮 আদায় ও রিকন',
  '🏠 Safety & Security': '🏠 নিরাপত্তা ও সুরক্ষা',
  '💰 Statements & Rent Config': '💰 স্টেটমেন্ট ও ভাড়া কনফিগ',
  '📋 DMP Thana Forms': '📋 ডিএমপি থানা ফরম',
  '💎 Premium & Subscriptions': '💎 প্রিমিয়াম ও সাবস্ক্রিপশন',
  Prev: 'পূর্ববর্তী',
  // ── Role switcher (V2.30.3) ──
  'Property Owner': 'প্রপার্টি মালিক', 'Property Manager': 'প্রপার্টি ম্যানেজার', Tenant: 'ভাড়াটিয়া',
  'HR & Admin': 'এইচআর ও অ্যাডমিন', 'Legal Counsel': 'লিগ্যাল কাউন্সেল', Accountant: 'অ্যাকাউন্ট্যান্ট',
  'CRM & Help Desk': 'সিআরএম ও হেল্প ডেস্ক', 'Service Manager': 'সার্ভিস ম্যানেজার', 'Service Partner': 'সার্ভিস পার্টনার',
  'Portfolio-wide view across every building': 'সব বিল্ডিং জুড়ে পোর্টফোলিও-ব্যাপী ভিউ',
  'Day-to-day ops on assigned properties': 'নির্ধারিত প্রপার্টিগুলোর দৈনন্দিন কার্যক্রম',
  'Invoices, receipts, repairs — your side': 'ইনভয়েস, রসিদ, মেরামত — আপনার পক্ষ থেকে',
  'Staff, onboarding, org admin': 'স্টাফ, অনবোর্ডিং, প্রতিষ্ঠান অ্যাডমিন',
  'Registrations, PRCA cases, compliance docket': 'রেজিস্ট্রেশন, পিআরসিএ কেস, কমপ্লায়েন্স ডকেট',
  'Cash flow, TDS, invoices, aging': 'ক্যাশ ফ্লো, টিডিএস, ইনভয়েস, এজিং',
  'Tickets, CSAT, tenant onboarding, leads': 'টিকেট, সিএসএটি, ভাড়াটিয়া অনবোর্ডিং, লিড',
  'Quality control & SLA across partners': 'পার্টনারদের মান নিয়ন্ত্রণ ও এসএলএ',
  'Jobs, QC feedback, payouts': 'জব, কিউসি ফিডব্যাক, পেআউট',
  Admin: 'অ্যাডমিন', Subscriber: 'সাবস্ক্রাইবার', Backoffice: 'ব্যাকঅফিস',
  'Viewing as': 'ভিউ করছেন', 'Switched to': 'সুইচ করা হয়েছে', 'Switch failed': 'সুইচ ব্যর্থ হয়েছে',
  // ── Tenant portal (V2.31) ──
  'My Portal': 'আমার পোর্টাল', 'Select tenant': 'ভাড়াটিয়া নির্বাচন',
  'Total paid': 'মোট পরিশোধিত', 'Next due': 'পরবর্তী বিল', 'Open tickets': 'খোলা টিকেট',
  'Lease days left': 'লিজে দিন বাকি', 'My lease': 'আমার লিজ', 'Lease progress': 'লিজ অগ্রগতি',
  'Download agreement': 'লিজ চুক্তি ডাউনলোড', 'Registration pending': 'রেজিস্ট্রেশন বাকি আছে',
  'Renter score': 'রেন্টার স্কোর', 'Renter risk assessment — landlord vetting': 'রেন্টার ঝুঁকি মূল্যায়ন — বাড়িওয়ালা ভেটিং',
  'No active lease': 'কোনো সক্রিয় লিজ নেই', 'Due invoices': 'বকেয়া ইনভয়েস',
  'Recent payments': 'সাম্প্রতিক পেমেন্ট', 'Maintenance tickets': 'মেইনটেন্যান্স টিকেট',
  'Utility bills': 'ইউটিলিটি বিল', 'Meter readings': 'মিটার রিডিং', 'Renewal requests': 'নবায়ন অনুরোধ',
  Settlement: 'সেটেলমেন্ট', Deposit: 'ডিপোজিট', Damages: 'ক্ষতি', 'Total due': 'মোট বকেয়া',
  Balance: 'ব্যালান্স', Refund: 'রিফান্ড', 'No-dues certificate': 'নো-ডিউজ সার্টিফিকেট',
  Usage: 'ব্যবহার', Reading: 'রিডিং', 'Pay rent': 'ভাড়া পরিশোধ',
  'everything about your tenancy in one place': 'আপনার ভাড়া, লিজ ও টিকেট এক জায়গায়',
}

// t(key) — dictionary lookup with English fallback.
export function t(key) {
  if (lang.value !== 'bn') return key
  return BN[key] || key
}

// Reactive composable for <script setup> use.
export function useT() {
  return { t, lang, isBn }
}
