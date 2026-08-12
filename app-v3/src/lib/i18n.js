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
