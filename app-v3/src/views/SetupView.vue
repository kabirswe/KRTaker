<script setup>
// SetupView — Mall Manager guided setup (first-time login, zero spaces).
// A 7-step full-screen wizard that creates real data through the existing API:
//   user profile (app-profile) + mall profile (mall config-set) → first space
//   with owner (app-crud shops) → billing rates (config-set) → SMS
//   (config-set) → optional 2FA (app-2fa-*) + web push (app-push).
// Completion sets localStorage mall_onboard_done_<email>; skipping sets
// mall_onboard_skip_<email> (both respected by the router guard).
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import { apiCall } from '../api/client'
import { track } from '../lib/analytics'
import { t, lang } from '../lib/i18n'

const router = useRouter()
const auth = useAuthStore()
const data = useDataStore()

const STEPS = [
  { id: 'welcome', ico: '👋', label: 'Welcome' },
  { id: 'profile', ico: '🏬', label: 'Mall profile' },
  { id: 'space', ico: '🏪', label: 'Space & owner' },
  { id: 'billing', ico: '🧾', label: 'Billing' },
  { id: 'sms', ico: '📱', label: 'SMS' },
  { id: 'security', ico: '🛡️', label: 'Security' },
  { id: 'done', ico: '🎉', label: 'Done' },
]
const STEPS_BN = { welcome: 'স্বাগতম', profile: 'মল প্রোফাইল', space: 'দোকান ও মালিক', billing: 'বিলিং', sms: 'এসএমএস', security: 'নিরাপত্তা', done: 'সম্পন্ন' }
const step = ref(0)
const busy = ref(false)
const err = ref('')
const saved = ref('')

// ── Step 1: user + mall profile ──
const name = ref(auth.user?.name || '')
const org = ref(auth.user?.org || '')
const mall = ref({ name: '', address: '', phone: '', email: '', chairman: '', secretary: '' })

// ── Step 2: first space + owner ──
const BILL_MODELS = [
  { v: 'fixed', bn: 'ফিক্সড (ফ্ল্যাট মাসিক)', en: 'Fixed (flat monthly)' },
  { v: 'sqft', bn: 'বর্গফুট অনুযায়ী', en: 'Per sqft' },
]
const shop = ref({ no: '', floor: '', sqft: '', owner_name: '', owner_mobile: '', service_rate: '', bill_model: 'fixed', rate_sqft: '' })
const createdShop = ref(null)
const rateModel = computed({
  get: () => shop.value.bill_model === 'sqft' ? shop.value.rate_sqft : shop.value.service_rate,
  set: (v) => { if (shop.value.bill_model === 'sqft') shop.value.rate_sqft = v; else shop.value.service_rate = v },
})

// ── Step 3: billing setup ──
const billing = ref({ elec_unit_rate: 8, water_unit_rate: 30, due_day: 10, late_fee_pct: 5, late_fees_enabled: true })

// ── Step 4: SMS ──
const sms = ref({ enabled: false, recipients: 'owner' })
const SMS_RECIPIENTS = [
  { v: 'owner', bn: 'মালিক', en: 'Owner' },
  { v: 'tenant', bn: 'ভাড়াটিয়া', en: 'Tenant' },
  { v: 'both', bn: 'উভয়', en: 'Both' },
]

// ── Step 5: security (2FA + web push) ──
const want2fa = ref(false)
const twofaState = ref({ sent: false, enabled: false })
const twofaCode = ref('')
const wantPush = ref(true)
const pushState = ref({ enabled: false, vapid: '', notifPermission: 'unsupported' })

const summary = computed(() => {
  const items = []
  if (mall.value.name.trim() || name.value.trim()) items.push(`মল প্রোফাইল: “${mall.value.name.trim() || name.value.trim()}”`)
  if (createdShop.value) items.push(`দোকান “${createdShop.value.no}” + মালিক “${createdShop.value.owner}” যোগ হয়েছে`)
  if (billing.value.elec_unit_rate || billing.value.water_unit_rate) items.push(`বিলিং: ⚡ ৳${billing.value.elec_unit_rate}/ইউনিট · 💧 ৳${billing.value.water_unit_rate}/ইউনিট · তারিখ ${billing.value.due_day}`)
  items.push(sms.value.enabled ? 'এসএমএস চালু (রসিদ + রিমাইন্ডার)' : 'এসএমএস লগ-মোডে (পরে চালু করা যাবে)')
  if (twofaState.value.enabled) items.push('দুই-স্তরের যাচাই (2FA) চালু')
  if (pushState.value.enabled) items.push('ব্রাউজার পুশ নোটিফিকেশন চালু')
  return items
})

async function saveProfile() {
  // user identity
  const body = { name: name.value.trim() || (auth.user?.name || '') }
  body.org = org.value || auth.user?.org || ''
  const r1 = await apiCall('app-profile', body)
  if (!r1.ok) { err.value = r1.error || 'Failed to save profile.'; return false }
  auth.user = r1.user || auth.user
  // mall profile
  const m = {}
  if (mall.value.name.trim()) m.mall_name = mall.value.name.trim()
  if (mall.value.address.trim()) m.mall_address = mall.value.address.trim()
  if (mall.value.phone.trim()) m.mall_phone = mall.value.phone.trim()
  if (mall.value.email.trim()) m.mall_email = mall.value.email.trim()
  if (mall.value.chairman.trim()) m.chairman = mall.value.chairman.trim()
  if (mall.value.secretary.trim()) m.secretary = mall.value.secretary.trim()
  if (Object.keys(m).length) {
    const r2 = await apiCall('mall', { action: 'config-set', ...m })
    if (!r2.ok) { err.value = r2.error || 'Failed to save the mall profile.'; return false }
  }
  track('setup_mall_profile_saved', {})
  return true
}

async function createSpace() {
  if (!shop.value.no.trim()) { err.value = 'দোকান নম্বর দিন (যেমন A-102)।'; return false }
  if (!shop.value.owner_name.trim()) { err.value = 'মালিকের নাম দিন।'; return false }
  const payload = {
    no: shop.value.no.trim(),
    floor: (shop.value.floor || '').trim(),
    sqft: Number(shop.value.sqft) || 0,
    owner_name: (shop.value.owner_name || '').trim(),
    owner_mobile: (shop.value.owner_mobile || '').trim(),
    owner_nid: '',
    status: 'Active',
    service_rate: Number(shop.value.service_rate) || 0,
    opening_balance: 0,
    owner_id: 0,
    space_type: 'Shop',
    occupancy: 'Owner',
    bill_model: shop.value.bill_model || 'fixed',
    rate_sqft: Number(shop.value.rate_sqft) || 0,
    util_included: 1,
  }
  const r = await apiCall('app-crud', { action: 'create', collection: 'shops', data: payload })
  if (!r.ok) { err.value = r.error || 'Failed to create the space.'; return false }
  createdShop.value = { no: payload.no, owner: payload.owner_name, id: r.id || '' }
  await data.bootstrap()
  track('setup_space_created', {})
  return true
}

async function saveBilling() {
  const r = await apiCall('mall', {
    action: 'config-set',
    elec_unit_rate: Number(billing.value.elec_unit_rate) || 0,
    water_unit_rate: Number(billing.value.water_unit_rate) || 0,
    due_day: Number(billing.value.due_day) || 10,
    late_fee_pct: Number(billing.value.late_fee_pct) || 0,
    late_fees_enabled: billing.value.late_fees_enabled ? 1 : 0,
    bill_model_default: shop.value.bill_model || 'fixed',
  })
  if (r.ok) { track('setup_billing_saved', {}); return true }
  err.value = r.error || 'Failed to save billing settings.'
  return false
}

async function saveSms() {
  const r = await apiCall('mall', {
    action: 'config-set',
    sms_enabled: sms.value.enabled ? 1 : 0,
    sms_recipients: sms.value.recipients,
  })
  if (r.ok) { track('setup_sms_saved', {}); return true }
  err.value = r.error || 'Failed to save SMS settings.'
  return false
}

async function sendTwofa() {
  busy.value = true; err.value = ''
  try {
    const r = await apiCall('app-2fa-send', {})
    if (r.ok) twofaState.value.sent = true
    else err.value = r.error || 'Could not send the code.'
  } finally { busy.value = false }
}

async function enableTwofa() {
  if (!twofaCode.value.trim()) { err.value = 'Enter the 6-digit code from your email.'; return }
  busy.value = true; err.value = ''
  try {
    const r = await apiCall('app-2fa-enable', { method: 'email', code: twofaCode.value.trim() })
    if (r.ok) { twofaState.value.enabled = true; track('setup_2fa_enabled', {}); }
    else err.value = r.error || 'Code did not match — try again.'
  } finally { busy.value = false }
}

async function enablePush() {
  busy.value = true; err.value = ''
  try {
    if (!('Notification' in window) || !('PushManager' in window) || !('serviceWorker' in navigator)) {
      err.value = 'Push is not supported in this browser — you can enable it later from Settings.'
      return
    }
    if (pushState.value.notifPermission !== 'granted') {
      const perm = await Notification.requestPermission()
      pushState.value.notifPermission = perm
      if (perm !== 'granted') { err.value = 'Permission denied — you can enable push later from Settings.'; return }
    }
    const reg = await navigator.serviceWorker.ready
    if (!pushState.value.vapid) {
      const st = await apiCall('app-push?action=state', {})
      if (st.ok) pushState.value.vapid = st.vapid_public || ''
    }
    if (!pushState.value.vapid) { err.value = 'Push is not configured on the server yet.'; return }
    const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: pushState.value.vapid })
    const json = sub.toJSON()
    const r = await apiCall('app-push', { action: 'save', endpoint: json.endpoint, p256dh: json.keys.p256dh, auth: json.keys.auth, ua: navigator.userAgent.slice(0, 200) })
    if (r.ok) { pushState.value.enabled = true; track('setup_push_enabled', {}); }
    else err.value = r.error || 'Failed to save push subscription.'
  } catch (e) {
    err.value = 'Could not subscribe: ' + (e && e.message ? e.message : e)
  } finally { busy.value = false }
}

async function next() {
  err.value = ''; saved.value = ''
  // Step actions run when LEAVING the step (except Done, which runs on enter).
  if (step.value === 1) { busy.value = true; try { if (!await saveProfile()) return } finally { busy.value = false } }
  if (step.value === 2) { busy.value = true; try { if (!await createSpace()) return } finally { busy.value = false } }
  if (step.value === 3) { busy.value = true; try { if (!await saveBilling()) return } finally { busy.value = false } }
  if (step.value === 4) { busy.value = true; try { if (!await saveSms()) return } finally { busy.value = false } }
  step.value++
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function back() { err.value = ''; saved.value = ''; if (step.value > 0) step.value-- }

function skip() {
  try {
    const k = ((auth.user?.email) || '').toLowerCase()
    localStorage.setItem('mall_onboard_skip_' + k, '1')
    localStorage.removeItem('mall_onboard_skip')
    localStorage.removeItem('mall_onboard_done')
  } catch (e) {}
  track('setup_skipped', { at: STEPS[step.value].id })
  router.push('/mall')
}

async function finish() {
  busy.value = true; err.value = ''
  try {
    if (want2fa.value && !twofaState.value.enabled) want2fa.value = false // not completed — do it in Settings later
    try {
      const k = ((auth.user?.email) || '').toLowerCase()
      localStorage.setItem('mall_onboard_done_' + k, '1')
      localStorage.removeItem('mall_onboard_done')
      localStorage.removeItem('mall_onboard_skip')
    } catch (e) {}
    track('setup_completed', {})
    router.push('/mall')
  } finally { busy.value = false }
}
</script>

<template>
  <div class="ob">
    <div class="ob-card">
      <!-- progress -->
      <div class="ob-steps">
        <div v-for="(s, i) in STEPS" :key="s.id" class="ob-step" :class="{ on: i <= step, cur: i === step }">
          <div class="ob-dot">{{ i < step ? '✓' : s.ico }}</div>
          <div class="ob-lab">{{ lang === 'bn' ? STEPS_BN[s.id] : s.label }}</div>
        </div>
      </div>

      <!-- step body -->
      <div class="ob-body">

        <!-- 0 · WELCOME -->
        <div v-if="step === 0" class="ob-center">
          <div class="ob-hero">🏬</div>
          <h1 class="ob-title">{{ lang === 'bn' ? 'মল ম্যানেজারে স্বাগতম 👋' : 'Welcome to Mall Manager 👋' }}</h1>
          <p class="ob-sub" v-html="lang === 'bn'
              ? 'আপনার মলের সম্পূর্ণ ম্যানেজমেন্ট সিস্টেম প্রস্তুত। সেটআপ করি — <b>প্রায় ২ মিনিট, ৬টি দ্রুত ধাপ।</b> যেকোনো ধাপ স্কিপ করে পরে শেষ করতে পারবেন।'
              : 'Your complete mall management system is ready. Time to set it up — <b>about 2 minutes, 6 quick steps.</b> You can skip any step and finish later.'"></p>
          <div class="ob-actions">
            <button class="btn-primary" style="padding:12px 26px;font-size:14px" @click="next" title="Begin the guided mall setup — about 2 minutes, 6 quick steps">{{ lang === 'bn' ? 'শুরু করুন →' : 'Get started →' }}</button>
            <button class="btn-ghost" style="padding:12px 22px;font-size:13.5px" @click="skip" title="Skip the wizard — finish setting up later from Settings">{{ lang === 'bn' ? 'এখনই নয়' : 'Skip for now' }}</button>
          </div>
          <div class="ob-hint">{{ lang === 'bn' ? 'সবকিছু পরে ⚙️ সেটিংস থেকে পরিবর্তন করতে পারবেন।' : 'You can change everything later in ⚙️ Settings.' }}</div>
        </div>

        <!-- 1 · MALL PROFILE -->
        <div v-else-if="step === 1" class="ob-step-body">
          <h2 class="ob-h">{{ lang === 'bn' ? '🏬 মল প্রোফাইল' : '🏬 Mall profile' }}</h2>
          <p class="ob-sub l">{{ lang === 'bn' ? 'মলের নাম বিল, রসিদ ও এসএমএসে দেখা যাবে — সেটিংসে পরে লোগো ও ব্যাংক তথ্যও বসাতে পারবেন।' : 'The mall name appears on bills, receipts and SMS — you can add a logo and bank details later in Settings.' }}</p>
          <div class="ob-grid2">
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'আপনার নাম' : 'Your name' }}</label>
              <input v-model="name" :placeholder="t('e.g. Alamgir Kabir Roni')" class="ob-inp" @keyup.enter="next" title="Your full name" />
            </div>
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'প্রতিষ্ঠান (ঐচ্ছিক)' : 'Organisation (optional)' }}</label>
              <input v-model="org" :placeholder="t('e.g. Razzak Plaza Committee')" class="ob-inp" @keyup.enter="next" title="Your organisation / committee (optional)" />
            </div>
          </div>
          <label class="ob-lab2" style="margin-top:14px">{{ lang === 'bn' ? 'মলের নাম *' : 'Mall name *' }}</label>
          <input v-model="mall.name" :placeholder="t('e.g. Razzak Plaza')" class="ob-inp" @keyup.enter="next" title="Required: the name of your mall / market" />
          <div class="ob-grid2">
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'ঠিকানা' : 'Address' }}</label>
              <input v-model="mall.address" :placeholder="t('e.g. 42 Motijheel C/A, Dhaka')" class="ob-inp" @keyup.enter="next" />
            </div>
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'ফোন' : 'Phone' }}</label>
              <input v-model="mall.phone" :placeholder="t('e.g. 02-9551234')" class="ob-inp" @keyup.enter="next" />
            </div>
          </div>
          <div class="ob-grid2">
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'সভাপতি' : 'Chairman' }} <span class="ob-opt">({{ lang === 'bn' ? 'ঐচ্ছিক' : 'optional' }})</span></label>
              <input v-model="mall.chairman" class="ob-inp" @keyup.enter="next" title="Committee chairman — printed on bills" />
            </div>
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'সাধারণ সম্পাদক' : 'Secretary' }} <span class="ob-opt">({{ lang === 'bn' ? 'ঐচ্ছিক' : 'optional' }})</span></label>
              <input v-model="mall.secretary" class="ob-inp" @keyup.enter="next" title="Committee secretary — printed on bills" />
            </div>
          </div>
          <div class="ob-foot">
            <button class="btn-ghost" @click="back">← {{ t('Back') }}</button>
            <button class="btn-primary" :disabled="busy" @click="next">{{ busy ? (lang === 'bn' ? 'সেভ হচ্ছে…' : 'Saving…') : (lang === 'bn' ? 'চালিয়ে যান →' : 'Continue →') }}</button>
          </div>
        </div>

        <!-- 2 · FIRST SPACE + OWNER -->
        <div v-else-if="step === 2" class="ob-step-body">
          <h2 class="ob-h">{{ lang === 'bn' ? '🏪 প্রথম দোকান ও মালিক' : '🏪 First space & owner' }}</h2>
          <p class="ob-sub l">{{ lang === 'bn' ? 'আপনার প্রথম দোকান যোগ করুন — মালিকের নামসহ। পরে যেকোনো সময় আরও দোকান, মালিক ও ভাড়াটিয়া যোগ করতে পারবেন।' : 'Add your first space with its owner — you can add more spaces, owners and tenants any time.' }}</p>
          <div class="ob-grid2">
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'দোকান নং *' : 'Space no *' }}</label>
              <input v-model="shop.no" :placeholder="t('e.g. A-102')" class="ob-inp" @keyup.enter="next" title="Required: e.g. A-102, B-205" />
            </div>
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'ফ্লোর' : 'Floor' }}</label>
              <input v-model="shop.floor" :placeholder="t('e.g. Ground')" class="ob-inp" @keyup.enter="next" />
            </div>
          </div>
          <div class="ob-grid2">
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'মালিকের নাম *' : 'Owner name *' }}</label>
              <input v-model="shop.owner_name" :placeholder="t('e.g. Rahim Uddin')" class="ob-inp" @keyup.enter="next" title="Required: the owner of this space" />
            </div>
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'মালিকের মোবাইল' : 'Owner mobile' }}</label>
              <input v-model="shop.owner_mobile" :placeholder="t('e.g. 01711-010101')" class="ob-inp" @keyup.enter="next" title="For SMS receipts & dues alerts" />
            </div>
          </div>
          <div class="ob-grid3">
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'আয়তন (বর্গফুট)' : 'Size (sqft)' }}</label>
              <input v-model="shop.sqft" type="number" min="0" class="ob-inp" @keyup.enter="next" />
            </div>
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'বিলিং মডেল' : 'Billing model' }}</label>
              <select v-model="shop.bill_model" class="ob-inp" title="Fixed flat monthly charge, or per sqft">
                <option v-for="m in BILL_MODELS" :key="m.v" :value="m.v">{{ lang === 'bn' ? m.bn : m.en }}</option>
              </select>
            </div>
            <div>
              <label class="ob-lab2">{{ shop.bill_model === 'sqft' ? (lang === 'bn' ? 'রেট (৳/বর্গফুট)' : 'Rate (৳/sqft)') : (lang === 'bn' ? 'মাসিক সার্ভিস (৳)' : 'Monthly service (৳)') }}</label>
              <input v-model="rateModel" type="number" min="0" class="ob-inp" @keyup.enter="next" />
            </div>
          </div>
          <div class="ob-foot">
            <button class="btn-ghost" @click="back">← {{ t('Back') }}</button>
            <button class="btn-primary" :disabled="busy" @click="next">{{ busy ? (lang === 'bn' ? 'সেভ হচ্ছে…' : 'Saving…') : (lang === 'bn' ? 'চালিয়ে যান →' : 'Continue →') }}</button>
          </div>
        </div>

        <!-- 3 · BILLING -->
        <div v-else-if="step === 3" class="ob-step-body">
          <h2 class="ob-h">{{ lang === 'bn' ? '🧾 বিলিং সেটআপ' : '🧾 Billing setup' }}</h2>
          <p class="ob-sub l">{{ lang === 'bn' ? 'সাব-মিটার ইউনিট রেট, বিলের নির্ধারিত তারিখ ও দেরি-ফি — সব পরে ⚙️ সেটিংস → বিলিং থেকে বদলানো যায়।' : 'Sub-meter unit rates, bill due day and late fee — all changeable later in ⚙️ Settings → Billing.' }}</p>
          <div class="ob-grid3">
            <div>
              <label class="ob-lab2">⚡ {{ lang === 'bn' ? 'বিদ্যুৎ (৳/ইউনিট)' : 'Electricity (৳/unit)' }}</label>
              <input v-model.number="billing.elec_unit_rate" type="number" min="0" class="ob-inp" @keyup.enter="next" title="Electricity rate per sub-meter unit" />
            </div>
            <div>
              <label class="ob-lab2">💧 {{ lang === 'bn' ? 'পানি (৳/ইউনিট)' : 'Water (৳/unit)' }}</label>
              <input v-model.number="billing.water_unit_rate" type="number" min="0" class="ob-inp" @keyup.enter="next" title="Water rate per sub-meter unit" />
            </div>
            <div>
              <label class="ob-lab2">📅 {{ lang === 'bn' ? 'বিলের নির্ধারিত তারিখ' : 'Bill due day' }}</label>
              <input v-model.number="billing.due_day" type="number" min="1" max="28" class="ob-inp" @keyup.enter="next" title="Day of the month bills fall due (late fee starts after)" />
            </div>
          </div>
          <div class="ob-grid2">
            <div>
              <label class="ob-lab2">⚠️ {{ lang === 'bn' ? 'দেরি-ফি (%)' : 'Late fee (%)' }}</label>
              <input v-model.number="billing.late_fee_pct" type="number" min="0" max="100" class="ob-inp" @keyup.enter="next" title="Late fee percent applied after the due day" />
            </div>
            <div style="display:flex;align-items:center;gap:8px;padding-top:22px">
              <input v-model="billing.late_fees_enabled" type="checkbox" id="ob-lf" style="width:16px;height:16px" />
              <label for="ob-lf" style="font-size:13px;color:var(--text)">{{ lang === 'bn' ? 'দেরি-ফি সক্রিয় করুন' : 'Enable late fees' }}</label>
            </div>
          </div>
          <div class="ob-foot">
            <button class="btn-ghost" @click="back">← {{ t('Back') }}</button>
            <button class="btn-primary" :disabled="busy" @click="next">{{ busy ? (lang === 'bn' ? 'সেভ হচ্ছে…' : 'Saving…') : (lang === 'bn' ? 'চালিয়ে যান →' : 'Continue →') }}</button>
          </div>
        </div>

        <!-- 4 · SMS -->
        <div v-else-if="step === 4" class="ob-step-body">
          <h2 class="ob-h">{{ lang === 'bn' ? '📱 এসএমএস' : '📱 SMS' }}</h2>
          <p class="ob-sub l">{{ lang === 'bn' ? 'প্রতিটি আদায়ে অটো রসিদ এসএমএস, বকেয়া রিমাইন্ডার ও নোটিশ — মালিকের ফোনে বাংলায়।' : 'Auto receipt SMS on every collection, dues reminders and notices — in Bangla to owners.' }}</p>
          <div style="display:flex;align-items:center;gap:10px;margin-top:6px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px">
            <input v-model="sms.enabled" type="checkbox" id="ob-sms" style="width:18px;height:18px" />
            <label for="ob-sms" style="font-size:13.5px;font-weight:700;cursor:pointer">{{ lang === 'bn' ? 'এসএমএস সক্রিয় করুন' : 'Enable SMS' }}</label>
          </div>
          <p class="ob-hint" style="margin-top:8px">{{ lang === 'bn' ? 'বন্ধ থাকলে সব এসএমএস লগ-মোডে যায় (কোনো খরচ নেই) — সেটিংস → এসএমএস থেকে গেটওয়ে কী বসিয়ে পরে চালু করতে পারবেন।' : 'When off, all SMS go to log mode (no cost) — add a gateway key later in Settings → SMS to go live.' }}</p>
          <label class="ob-lab2" style="margin-top:14px">{{ lang === 'bn' ? 'এসএমএস রিসিপিয়েন্ট' : 'SMS recipients' }}</label>
          <select v-model="sms.recipients" class="ob-inp" title="Who receives auto SMS — owner, tenant or both">
            <option v-for="r in SMS_RECIPIENTS" :key="r.v" :value="r.v">{{ lang === 'bn' ? r.bn : r.en }}</option>
          </select>
          <div class="ob-foot">
            <button class="btn-ghost" @click="back">← {{ t('Back') }}</button>
            <button class="btn-primary" :disabled="busy" @click="next">{{ busy ? (lang === 'bn' ? 'সেভ হচ্ছে…' : 'Saving…') : (lang === 'bn' ? 'চালিয়ে যান →' : 'Continue →') }}</button>
          </div>
        </div>

        <!-- 5 · SECURITY -->
        <div v-else-if="step === 5" class="ob-step-body">
          <h2 class="ob-h">{{ lang === 'bn' ? '🛡️ নিরাপত্তা' : '🛡️ Security' }}</h2>
          <p class="ob-sub l">{{ lang === 'bn' ? 'ঐচ্ছিক — পরে ⚙️ সেটিংস থেকে যেকোনো সময় চালু করতে পারবেন।' : 'Optional — enable any time later from ⚙️ Settings.' }}</p>
          <div style="display:flex;align-items:center;gap:10px;margin-top:6px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px">
            <input v-model="want2fa" type="checkbox" id="ob-2fa" style="width:18px;height:18px" />
            <label for="ob-2fa" style="font-size:13.5px;font-weight:700;cursor:pointer">{{ lang === 'bn' ? 'ইমেইল 2FA (দুই-স্তরের যাচাই) চালু করুন' : 'Enable email 2FA (two-factor authentication)' }}</label>
          </div>
          <div v-if="want2fa" style="margin-top:12px">
            <template v-if="!twofaState.enabled">
              <button class="btn-ghost" :disabled="busy || twofaState.sent" @click="sendTwofa" style="padding:9px 16px;font-size:12.5px">{{ twofaState.sent ? (lang === 'bn' ? 'কোড পাঠানো হয়েছে ✓' : 'Code sent ✓') : (lang === 'bn' ? 'কোড পাঠান' : 'Send code') }}</button>
              <div v-if="twofaState.sent" style="margin-top:10px">
                <label class="ob-lab2">{{ lang === 'bn' ? 'ইমেইলে আসা ৬-ডিজিট কোড' : '6-digit code from your email' }}</label>
                <input v-model="twofaCode" class="ob-inp" style="max-width:220px" @keyup.enter="enableTwofa" />
                <button class="btn-primary" :disabled="busy" @click="enableTwofa" style="margin-top:10px;padding:9px 18px;font-size:12.5px">{{ busy ? '…' : (lang === 'bn' ? 'সক্রিয় করুন' : 'Enable') }}</button>
              </div>
            </template>
            <p v-else style="color:var(--ok);font-weight:700;font-size:13px">✅ {{ lang === 'bn' ? '2FA সক্রিয়' : '2FA enabled' }}</p>
          </div>
          <div style="display:flex;align-items:center;gap:10px;margin-top:14px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px 16px">
            <input v-model="wantPush" type="checkbox" id="ob-push" style="width:18px;height:18px" />
            <label for="ob-push" style="font-size:13.5px;font-weight:700;cursor:pointer">{{ lang === 'bn' ? 'ব্রাউজার পুশ নোটিফিকেশন' : 'Browser push notifications' }}</label>
          </div>
          <p v-if="pushState.enabled" style="color:var(--ok);font-weight:700;font-size:13px;margin-top:8px">✅ {{ lang === 'bn' ? 'পুশ সক্রিয়' : 'Push enabled' }}</p>
          <div class="ob-foot">
            <button class="btn-ghost" @click="back">← {{ t('Back') }}</button>
            <button class="btn-primary" :disabled="busy" @click="next">{{ busy ? (lang === 'bn' ? 'সেভ হচ্ছে…' : 'Saving…') : (lang === 'bn' ? 'চালিয়ে যান →' : 'Continue →') }}</button>
          </div>
        </div>

        <!-- 6 · DONE -->
        <div v-else-if="step === 6" class="ob-center">
          <div class="ob-hero">🎉</div>
          <h1 class="ob-title">{{ lang === 'bn' ? 'সেটআপ সম্পন্ন!' : 'Setup complete!' }}</h1>
          <p class="ob-sub l" style="text-align:left;max-width:520px;margin:14px auto 0">
            <span v-for="(it, i) in summary" :key="i" style="display:block;padding:7px 12px;margin-bottom:6px;background:var(--bg-alt);border:1px solid var(--border);border-radius:10px;font-size:13px">✓ {{ it }}</span>
          </p>
          <p class="ob-hint" style="margin-top:14px">{{ lang === 'bn' ? 'এখন ড্যাশবোর্ডে গিয়ে দোকান, বিল ও আদায় শুরু করুন। 📚 উইকি ও সাহায্যে সবকিছুর ধাপে ধাপে গাইড আছে।' : 'Head to the dashboard to start adding spaces, billing and collecting. The 📚 Wiki & Help has step-by-step guides for everything.' }}</p>
          <div class="ob-actions">
            <button class="btn-primary" style="padding:12px 28px;font-size:14px" :disabled="busy" @click="finish">{{ busy ? (lang === 'bn' ? 'যাওয়া হচ্ছে…' : 'Loading…') : (lang === 'bn' ? '🚀 ড্যাশবোর্ডে যান' : '🚀 Go to dashboard') }}</button>
          </div>
        </div>

      </div>

      <div v-if="err" class="ob-err">{{ err }}</div>
      <div v-if="saved" class="ob-ok">{{ saved }}</div>
    </div>
  </div>
</template>
