<script setup>
// SetupView — V2.27 guided setup for new subscribers (first-time login).
// A 6-step full-screen wizard that creates real data through the existing API:
//   profile (app-profile) → first property (app-crud) → notification prefs
//   (app-settings-save) → optional 2FA (app-2fa-*) + web push (app-push).
// Completion sets localStorage krtaker_onboard_done; skipping sets
// krtaker_onboard_skip (both respected by the router guard /setup redirect).
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
  { id: 'profile', ico: '🧑‍💼', label: 'Profile' },
  { id: 'property', ico: '🏢', label: 'Property' },
  { id: 'tenant', ico: '👤', label: 'Tenant' },
  { id: 'notify', ico: '🔔', label: 'Notifications' },
  { id: 'security', ico: '🛡️', label: 'Security' },
  { id: 'done', ico: '🎉', label: 'Done' },
]
const STEPS_BN = { welcome: 'স্বাগতম', profile: 'প্রোফাইল', property: 'প্রপার্টি', tenant: 'ভাড়াটিয়া', notify: 'নোটিফিকেশন', security: 'নিরাপত্তা', done: 'সম্পন্ন' }
const step = ref(0)
const busy = ref(false)
const err = ref('')
const saved = ref('')

// ── Step 1: profile ──
const name = ref(auth.user?.name || '')
const org = ref(auth.user?.org || '')

// ── Step 2: first property + optional first unit ──
const PROP_TYPES = ['Flat', 'Apartment', 'Building', 'Villa', 'Commercial', 'Office', 'Shop', 'Warehouse', 'Land', 'Other']
const JURS = ['Dhaka North', 'Dhaka South', 'Chittagong', 'Gazipur', 'Narayanganj', 'Other']
const prop = ref({ name: '', type: 'Flat', jur: 'Dhaka North', address: '' })
const createdProp = ref(null)
const unit = ref({ name: '', floor: '', rent: '' })   // V2.34: optional first unit
const createdUnit = ref(null)

// ── Step 3: optional first tenant (GO-LIVE 4.1 — walk the owner through tenant creation) ──
const tenant = ref({ name: '', phone: '', email: '', nid: '', rent: '' })
const createdTenant = ref(null)

// ── Step 4: notifications (server-side SETTINGS_DEFAULTS keys) ──
const prefs = ref({
  notify_rent: true,
  notify_collections: true,
  notify_renewal: true,
  notify_docs: true,
  wa_reminders: true,
  email_digest: true,
  notify_premium: true,
})
const NOTIFY_ROWS = [
  { k: 'notify_rent', ico: '💸', t: 'Rent reminders', d: 'Emails before/at rent due dates', tbn: 'ভাড়া রিমাইন্ডার', dbn: 'ভাড়া নির্ধারিত তারিখের আগে/সময়ে ইমেইল' },
  { k: 'notify_collections', ico: '🧾', t: 'Collections digest', d: 'Money-collected summaries', tbn: 'আদায় ডাইজেস্ট', dbn: 'আদায়কৃত অর্থের সারসংক্ষেপ' },
  { k: 'notify_renewal', ico: '📅', t: 'Lease renewals', d: 'Leases about to end or renew', tbn: 'লিজ নবায়ন', dbn: 'যেসব লিজ শেষ হতে চলেছে বা নবায়ন হবে' },
  { k: 'notify_docs', ico: '📎', t: 'Document emails', d: 'Invoices, receipts, lease docs', tbn: 'ডকুমেন্ট ইমেইল', dbn: 'ইনভয়েস, রসিদ, লিজ ডকুমেন্ট' },
  { k: 'wa_reminders', ico: '💬', t: 'WhatsApp reminders', d: 'Rent reminders via WhatsApp (if your phone is set)', tbn: 'হোয়াটসঅ্যাপ রিমাইন্ডার', dbn: 'হোয়াটসঅ্যাপে ভাড়া রিমাইন্ডার (ফোন নম্বর সেট থাকলে)' },
  { k: 'email_digest', ico: '📬', t: 'Weekly email digest', d: 'One summary email per week', tbn: 'সাপ্তাহিক ইমেইল ডাইজেস্ট', dbn: 'সপ্তাহে একটি সারসংক্ষেপ ইমেইল' },
  { k: 'notify_premium', ico: '✨', t: 'Product announcements', d: 'New features & premium offers', tbn: 'প্রোডাক্ট ঘোষণা', dbn: 'নতুন ফিচার ও প্রিমিয়াম অফার' },
]

// ── Step 4: security (2FA + web push) ──
const want2fa = ref(false)
const twofaState = ref({ sent: false, enabled: false })
const twofaCode = ref('')
const wantPush = ref(true)
const pushState = ref({ enabled: false, vapid: '', notifPermission: 'unsupported' })

const summary = computed(() => {
  const items = []
  if (name.value.trim()) items.push(`Profile saved as “${name.value.trim()}”`)
  if (createdProp.value) items.push(`Property “${createdProp.value.name}” added`)
  if (createdUnit.value) items.push(`First unit “${createdUnit.value.name}” added`)
  if (createdTenant.value) items.push(`Tenant “${createdTenant.value.name}” added`)
  const on = NOTIFY_ROWS.filter(r => prefs.value[r.k]).length
  if (on) items.push(`${on} notification channel${on > 1 ? 's' : ''} on`)
  if (twofaState.value.enabled) items.push('Two-factor authentication enabled')
  if (pushState.value.enabled) items.push('Browser push notifications on')
  return items
})

async function saveProfile() {
  const body = { name: name.value.trim() || (auth.user?.name || '') }
  body.org = org.value || auth.user?.org || ''
  const r = await apiCall('app-profile', body)
  if (r.ok) { auth.user = r.user || auth.user; return true }
  err.value = r.error || 'Failed to save profile.'
  return false
}

async function createProperty() {
  if (!prop.value.name.trim()) { err.value = 'Give your first property a name.'; return false }
  const payload = {
    name: prop.value.name.trim(),
    type: prop.value.type,
    jur: prop.value.jur,
    address: prop.value.address.trim(),
    status: 'Active',
    published: 1,
    featured: '0',
    sqft: 0,
    value: 0,
    description: '',
  }
  const r = await apiCall('app-crud', { action: 'create', collection: 'properties', data: payload })
  if (!r.ok) { err.value = r.error || 'Failed to create property.'; return false }
  createdProp.value = { name: payload.name, id: r.id || '' }
  await data.bootstrap()
  track('setup_property_created', {})
  // V2.34: optional FIRST UNIT under the new property
  if (unit.value.name.trim()) {
    const up = {
      p: createdProp.value.id || r.id || '',
      name: unit.value.name.trim(),
      floor: unit.value.floor.trim(),
      sqft: 0,
      rent: parseInt(unit.value.rent, 10) || 0,
      beds: 0, baths: 0, furnished: '0',
      status: 'Vacant',
    }
    const ur = await apiCall('app-crud', { action: 'create', collection: 'units', data: up })
    if (ur.ok) {
      createdUnit.value = { name: up.name, id: ur.id || '' }
      await data.bootstrap()
      track('setup_unit_created', {})
    } else {
      err.value = ur.error || 'Property added, but the unit could not be created — you can add it from Units later.'
    }
  }
  return true
}

async function createTenant() {
  // Optional step — skip when the owner left every field empty.
  if (!tenant.value.name.trim() && !tenant.value.phone.trim() && !tenant.value.email.trim()) return true
  if (!tenant.value.name.trim()) { err.value = 'Give your tenant a name (or clear the fields to skip this step).'; return false }
  const payload = {
    name: tenant.value.name.trim(),
    phone: tenant.value.phone.trim(),
    email: tenant.value.email.trim(),
    nid: tenant.value.nid.trim(),
    nrb: 0,
    kind: 'Individual',
    sub_email: (auth.user?.email || '').trim(),
  }
  const r = await apiCall('app-crud', { action: 'create', collection: 'tenants', data: payload })
  if (!r.ok) { err.value = r.error || 'Failed to create the tenant — you can add them later from Tenants.'; return false }
  createdTenant.value = { name: payload.name, id: r.id || '' }
  await data.bootstrap()
  track('setup_tenant_created', {})
  return true
}

async function savePrefs() {
  const r = await apiCall('app-settings-save', { settings: prefs.value })
  if (r.ok) { track('setup_prefs_saved', {}); return true }
  err.value = r.error || 'Failed to save preferences.'
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
  if (step.value === 2) { busy.value = true; try { if (!await createProperty()) return } finally { busy.value = false } }
  if (step.value === 3) { busy.value = true; try { if (!await createTenant()) return } finally { busy.value = false } }
  if (step.value === 4) { busy.value = true; try { if (!await savePrefs()) return } finally { busy.value = false } }
  step.value++
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function back() { err.value = ''; saved.value = ''; if (step.value > 0) step.value-- }

function skip() {
  try {
    const k = ((auth.user?.email) || '').toLowerCase()
    localStorage.setItem('krtaker_onboard_skip_' + k, '1')
    localStorage.removeItem('krtaker_onboard_skip')   // V2.34: old global keys are obsolete
    localStorage.removeItem('krtaker_onboard_done')
  } catch (e) {}
  track('setup_skipped', { at: STEPS[step.value].id })
  router.push('/dashboard')
}

async function finish() {
  busy.value = true; err.value = ''
  try {
    if (want2fa.value && !twofaState.value.enabled) want2fa.value = false // not completed — do it in Settings later
    try {
      const k = ((auth.user?.email) || '').toLowerCase()
      localStorage.setItem('krtaker_onboard_done_' + k, '1')
      localStorage.removeItem('krtaker_onboard_done')   // V2.34: old global keys are obsolete
      localStorage.removeItem('krtaker_onboard_skip')
    } catch (e) {}
    // Persist the server-side marker so the wizard never reappears on any device.
    const r = await apiCall('app-setup-done', {})
    if (r.ok && auth.user) auth.user.setup_at = new Date().toISOString().slice(0, 19).replace('T', ' ')
    track('setup_completed', {})
    router.push('/dashboard')
  } finally { busy.value = false }
}

// ── tiny UI helpers ──
function tgl(k) { prefs.value[k] = !prefs.value[k] }
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
          <div class="ob-hero">◆</div>
          <h1 class="ob-title">{{ lang === 'bn' ? 'KRTaker-এ স্বাগতম 👋' : 'Welcome to KRTaker 👋' }}</h1>
          <p class="ob-sub" v-html="lang === 'bn'
              ? 'আপনার প্রপার্টি ও ফ্যাসিলিটি ম্যানেজমেন্ট কমান্ড সেন্টার প্রস্তুত। আপনার ওয়ার্কস্পেস সেটআপ করি — <b>প্রায় ২ মিনিট, ৬টি দ্রুত ধাপ।</b> যেকোনো ধাপ স্কিপ করে পরে শেষ করতে পারবেন।'
              : &quot;Your property &amp; facility management command center is ready. Let's set up your workspace — <b>about 2 minutes, 6 quick steps.</b> You can skip any step and finish later.&quot;"></p>
          <div class="ob-actions">
            <button class="btn-primary" style="padding:12px 26px;font-size:14px" @click="next" title="Begin the guided workspace setup — about 2 minutes, 6 quick steps">{{ lang === 'bn' ? 'শুরু করুন →' : 'Get started →' }}</button>
            <button class="btn-ghost" style="padding:12px 22px;font-size:13.5px" @click="skip" title="Skip the wizard — finish setting up later from Settings"> {{ lang === 'bn' ? 'এখনই নয়' : 'Skip for now' }}</button>
          </div>
          <div class="ob-hint">{{ lang === 'bn' ? 'সবকিছু পরে ⚙️ সেটিংস থেকে পরিবর্তন করতে পারবেন।' : 'You can change everything later in ⚙️ Settings.' }}</div>
        </div>

        <!-- 1 · PROFILE -->
        <div v-else-if="step === 1" class="ob-step-body">
          <h2 class="ob-h">{{ lang === 'bn' ? '🧑‍💼 আপনার প্রোফাইল' : '🧑‍💼 Your profile' }}</h2>
          <p class="ob-sub l">{{ lang === 'bn' ? 'আপনার নাম মালিক স্টেটমেন্ট, ডকুমেন্ট ও সাপোর্ট টিকেটে দেখা যাবে।' : 'Your name appears on owner statements, documents and support tickets.' }}</p>
          <label class="ob-lab2">{{ lang === 'bn' ? 'পুরো নাম' : 'Full name' }}</label>
          <input v-model="name" :placeholder="t('e.g. Alamgir Kabir Roni')" class="ob-inp" @keyup.enter="next" title="Your full name — shown on owner statements, documents and support tickets" />
          <label class="ob-lab2" style="margin-top:14px">{{ lang === 'bn' ? 'প্রতিষ্ঠান / কোম্পানি' : 'Organisation / company' }} <span class="ob-opt">({{ lang === 'bn' ? 'ঐচ্ছিক' : 'optional' }})</span></label>
          <input v-model="org" :placeholder="t('e.g. Kabir Holdings')" class="ob-inp" @keyup.enter="next" title="Your company or organisation name (optional)" />
          <div class="ob-foot">
            <button class="btn-ghost" @click="back">← {{ t('Back') }}</button>
            <button class="btn-primary" :disabled="busy" @click="next">{{ busy ? (lang === 'bn' ? 'সেভ হচ্ছে…' : 'Saving…') : (lang === 'bn' ? 'চালিয়ে যান →' : 'Continue →') }}</button>
          </div>
        </div>

        <!-- 2 · PROPERTY -->
        <div v-else-if="step === 2" class="ob-step-body">
          <h2 class="ob-h">{{ lang === 'bn' ? '🏢 আপনার প্রথম প্রপার্টি যোগ করুন' : '🏢 Add your first property' }}</h2>
          <p class="ob-sub l">{{ lang === 'bn' ? 'এটি সেই বিল্ডিং বা জমি যা আপনি ম্যানেজ করেন। ইউনিট, ভাড়াটিয়া ও লিজ এর সাথে যুক্ত থাকে — যেকোনো সময় আরও প্রপার্টি যোগ করতে পারবেন।' : 'This is the building or land you manage. Units, tenants and leases hang off it — you can add more properties any time.' }}</p>
          <label class="ob-lab2">{{ lang === 'bn' ? 'প্রপার্টির নাম *' : 'Property name *' }}</label>
          <input v-model="prop.name" :placeholder="t('e.g. Green View Residency')" class="ob-inp" @keyup.enter="next" title="Required: name of the building or land you manage" />
          <div class="ob-grid2">
            <div>
              <label class="ob-lab2">{{ t('Type') }}</label>
              <select v-model="prop.type" class="ob-inp" title="Property type: apartment, commercial, land, etc.">
                <option v-for="t in PROP_TYPES" :key="t" :value="t">{{ t }}</option>
              </select>
            </div>
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'এলাকা (জুরিসডিকশন)' : 'Jurisdiction' }}</label>
              <select v-model="prop.jur" class="ob-inp" title="Legal jurisdiction / area for this property">
                <option v-for="j in JURS" :key="j" :value="j">{{ j }}</option>
              </select>
            </div>
          </div>
          <label class="ob-lab2" style="margin-top:14px">{{ lang === 'bn' ? 'ঠিকানা' : 'Address' }} <span class="ob-opt">({{ lang === 'bn' ? 'ঐচ্ছিক' : 'optional' }})</span></label>
          <input v-model="prop.address" :placeholder="t('e.g. House 12, Road 5, Dhanmondi, Dhaka')" class="ob-inp" @keyup.enter="next" title="Street address of the property (optional)" />
          <div class="ob-unit">
            <div class="ob-unit-h">{{ lang === 'bn' ? '🏠 আপনার প্রথম ইউনিট (ঐচ্ছিক)' : '🏠 Your first unit (optional)' }}</div>
            <p class="ob-unit-d">{{ lang === 'bn' ? 'একটি ফ্ল্যাট/অ্যাপার্টমেন্ট যোগ করলে পরে ভাড়া ও ভাড়াটিয়া সংযুক্ত করা সহজ হবে। এখনই না দিলে পরে 📦 ইউনিট মেনু থেকে যোগ করতে পারবেন।' : 'Adding a flat/apartment now makes it easy to attach rent and a tenant later. You can skip this and add units from the Units menu any time.' }}</p>
            <div class="ob-grid2">
              <div>
                <label class="ob-lab2">{{ lang === 'bn' ? 'ইউনিটের নাম' : 'Unit name' }} <span class="ob-opt">({{ lang === 'bn' ? 'যেমন: ফ্ল্যাট A' : 'e.g. Flat A' }})</span></label>
                <input v-model="unit.name" :placeholder="t('e.g. Flat A')" class="ob-inp" title="Unit name, e.g. Flat A / Shop 2" />
              </div>
              <div>
                <label class="ob-lab2">{{ t('Floor') }}</label>
                <input v-model="unit.floor" :placeholder="t('e.g. 3rd')" class="ob-inp" title="Floor of the unit" />
              </div>
            </div>
            <label class="ob-lab2" style="margin-top:12px">{{ lang === 'bn' ? 'মাসিক ভাড়া (৳)' : 'Monthly rent (৳)' }} <span class="ob-opt">({{ lang === 'bn' ? 'ঐচ্ছিক' : 'optional' }})</span></label>
            <input v-model="unit.rent" type="number" min="0" :placeholder="t('e.g. 25000')" class="ob-inp" title="Expected monthly rent for this unit (optional)" />
          </div>
          <div class="ob-foot">
            <button class="btn-ghost" @click="back">← {{ t('Back') }}</button>
            <button class="btn-primary" :disabled="busy" @click="next">{{ busy ? (lang === 'bn' ? 'তৈরি হচ্ছে…' : 'Creating…') : (lang === 'bn' ? 'তৈরি করুন ও চালিয়ে যান →' : 'Create & continue →') }}</button>
          </div>
        </div>

        <!-- 3 · TENANT (GO-LIVE 4.1: walk the owner through the first tenant) -->
        <div v-else-if="step === 3" class="ob-step-body">
          <h2 class="ob-h">{{ lang === 'bn' ? '👤 আপনার প্রথম ভাড়াটিয়া (ঐচ্ছিক)' : '👤 Add your first tenant (optional)' }}</h2>
          <p class="ob-sub l">{{ lang === 'bn' ? 'ভাড়াটিয়ার নাম, ফোন ও NID দিলে তাদের পোর্টাল অ্যাকাউন্ট স্বয়ংক্রিয় তৈরি হয় — ইনভয়েস, রসিদ ও রিমাইন্ডার তাদের কাছে সরাসরি যায়। এখনই না চাইলে ধাপটি খালি রেখে Skip করতে পারেন।' : 'Add a tenant’s name, phone & NID and their portal account is created automatically — invoices, receipts and reminders go straight to them. Skip the step by leaving the fields empty.' }}</p>
          <label class="ob-lab2">{{ lang === 'bn' ? 'নাম *' : 'Name *' }} <span class="ob-opt">({{ lang === 'bn' ? 'যেমন: মোঃ রফিকুল ইসলাম' : 'e.g. Md. Rofiqul Islam' }})</span></label>
          <input v-model="tenant.name" :placeholder="t('e.g. Md. Rofiqul Islam')" class="ob-inp" @keyup.enter="next" title="Tenant full name — required if you add a tenant" />
          <div class="ob-grid2">
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'ফোন নম্বর' : 'Phone' }}</label>
              <input v-model="tenant.phone" :placeholder="t('e.g. 01712-345678')" class="ob-inp" title="Tenant phone number (used for rent reminders)" />
            </div>
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'ইমেইল' : 'Email' }}</label>
              <input v-model="tenant.email" :placeholder="t('e.g. tenant@email.com')" class="ob-inp" title="Tenant email — creates their portal login" />
            </div>
          </div>
          <div class="ob-grid2" style="margin-top:12px">
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'NID নম্বর' : 'NID number' }}</label>
              <input v-model="tenant.nid" :placeholder="t('e.g. 1990123456789')" class="ob-inp" title="National ID number (optional but recommended)" />
            </div>
            <div>
              <label class="ob-lab2">{{ lang === 'bn' ? 'মাসিক ভাড়া (৳)' : 'Monthly rent (৳)' }}</label>
              <input v-model="tenant.rent" type="number" min="0" :placeholder="t('e.g. 25000')" class="ob-inp" title="Agreed monthly rent — used when you create the first invoice" />
            </div>
          </div>
          <div class="ob-unit" style="margin-top:14px">
            <div class="ob-unit-h">{{ lang === 'bn' ? 'ℹ️ এটা কী করে?' : 'ℹ️ What this does' }}</div>
            <p class="ob-unit-d" style="margin:6px 0 0">{{ lang === 'bn' ? 'ভাড়াটিয়া তৈরি হলে তাদের ইমেইলে একটি পোর্টাল অ্যাকাউন্ট যায়। পরে ইউনিট ও লিজ যুক্ত করে ইনভয়েস চালু করবেন — সবকিছু সেটিংস থেকে পরিবর্তনযোগ্য।' : 'Creating the tenant sends a portal account to their email. You attach a unit & lease later and start invoicing — everything stays editable in Settings.' }}</p>
          </div>
          <div class="ob-foot">
            <button class="btn-ghost" @click="back">← {{ t('Back') }}</button>
            <div style="display:flex;gap:8px">
              <button class="btn-ghost" @click="tenant.name = ''; tenant.phone = ''; tenant.email = ''; tenant.nid = ''; tenant.rent = ''; next()">{{ lang === 'bn' ? 'এড়িয়ে যান' : 'Skip' }}</button>
              <button class="btn-primary" :disabled="busy" @click="next">{{ busy ? (lang === 'bn' ? 'সেভ হচ্ছে…' : 'Saving…') : (lang === 'bn' ? 'যোগ করুন ও চালিয়ে যান →' : 'Add & continue →') }}</button>
            </div>
          </div>
        </div>

        <!-- 4 · NOTIFICATIONS -->
        <div v-else-if="step === 4" class="ob-step-body">
          <h2 class="ob-h">{{ lang === 'bn' ? '🔔 আপনার নোটিফিকেশন বেছে নিন' : '🔔 Choose your notifications' }}</h2>
          <p class="ob-sub l" v-html="lang === 'bn' ? '<b>আপনার</b> ইনবক্সে কী আসবে তা বেছে নিন। প্রতিটি টগল পরে সেটিংস থেকে বদলানো যাবে।' : 'Pick what lands in <b>your</b> inbox. Every toggle can be changed later in Settings.'"></p>
          <div class="ob-rows">
            <div v-for="r in NOTIFY_ROWS" :key="r.k" class="ob-row" @click="tgl(r.k)">
              <span class="ob-row-ico">{{ r.ico }}</span>
              <div class="ob-row-txt">
                <div class="ob-row-t">{{ lang === 'bn' ? (r.tbn || r.t) : r.t }}</div>
                <div class="ob-row-d">{{ lang === 'bn' ? (r.dbn || r.d) : r.d }}</div>
              </div>
              <span class="ob-tgl" :class="{ on: prefs[r.k] }"></span>
            </div>
          </div>
          <div class="ob-foot">
            <button class="btn-ghost" @click="back">← {{ t('Back') }}</button>
            <button class="btn-primary" :disabled="busy" @click="next">{{ busy ? (lang === 'bn' ? 'সেভ হচ্ছে…' : 'Saving…') : (lang === 'bn' ? 'সেভ করুন ও চালিয়ে যান →' : 'Save & continue →') }}</button>
          </div>
        </div>

        <!-- 5 · SECURITY -->
        <div v-else-if="step === 5" class="ob-step-body">
          <h2 class="ob-h">{{ lang === 'bn' ? '🛡️ নিরাপত্তা ও ডিভাইস অ্যালার্ট' : '🛡️ Security &amp; on-device alerts' }}</h2>
          <p class="ob-sub l">{{ lang === 'bn' ? 'দুটি ঐচ্ছিক ধাপ যা আপনার অ্যাকাউন্ট অনেক বেশি নিরাপদ করবে। দুটোই পরে সেটিংস থেকে করা যাবে।' : 'Two optional steps that make your account much safer. Both can be done later from Settings.' }}</p>

          <div class="ob-sec-card" :class="{ active: want2fa || twofaState.enabled }">
            <div class="ob-sec-head" @click="want2fa = !want2fa; twofaState.sent = false; twofaCode = ''">
              <div>
                <div class="ob-sec-t">{{ lang === 'bn' ? '🔐 টু-ফ্যাক্টর প্রমাণীকরণ' : '🔐 Two-factor authentication' }}</div>
                <div class="ob-sec-d">{{ lang === 'bn' ? 'লগ ইন করতে আপনার ইমেইলে পাঠানো নতুন ৬-ডিজিটের কোড লাগবে — পাসওয়ার্ড লিক হলেও সুরক্ষিত থাকবেন।' : 'Logins need a fresh 6-digit code from your email — protects you even if the password leaks.' }}</div>
              </div>
              <span class="ob-tgl" :class="{ on: want2fa || twofaState.enabled }"></span>
            </div>
            <div v-if="twofaState.enabled" class="ob-sec-done">{{ lang === 'bn' ? '✅ আপনার অ্যাকাউন্টে 2FA এখন চালু।' : '✅ 2FA is now enabled on your account.' }}</div>
            <div v-else-if="want2fa" class="ob-sec-flow">
              <template v-if="!twofaState.sent">
                <button class="btn-primary" :disabled="busy" @click="sendTwofa">{{ busy ? (lang === 'bn' ? 'পাঠানো হচ্ছে…' : 'Sending…') : (lang === 'bn' ? '📧 আমাকে একটি কোড পাঠান' : '📧 Send me a code') }}</button>
              </template>
              <template v-else>
                <div class="ob-sec-d">{{ lang === 'bn' ? 'আমরা যে ৬-ডিজিটের কোড পাঠিয়েছি তা লিখুন:' : 'Enter the 6-digit code we emailed you:' }}</div>
                <div style="display:flex;gap:8px;margin-top:8px">
                  <input v-model="twofaCode" placeholder="000000" maxlength="6" class="ob-inp" style="width:150px;text-align:center;letter-spacing:3px;font-weight:800" @keyup.enter="enableTwofa" />
                  <button class="btn-primary" :disabled="busy" @click="enableTwofa">{{ t('Verify') }}</button>
                </div>
              </template>
            </div>
          </div>

          <div class="ob-sec-card" :class="{ active: wantPush || pushState.enabled }">
            <div class="ob-sec-head" @click="wantPush = !wantPush">
              <div>
                <div class="ob-sec-t">{{ lang === 'bn' ? '🔔 ব্রাউজার পুশ নোটিফিকেশন' : '🔔 Browser push notifications' }}</div>
                <div class="ob-sec-d">{{ lang === 'bn' ? 'অ্যাপ ট্যাব বন্ধ থাকলেও এই ডিভাইসে অ্যালার্ট আসবে (ডেস্কটপ ও মোবাইলে কাজ করে)।' : 'Alerts arrive on this device even when the app tab is closed (works on desktop &amp; mobile).' }}</div>
              </div>
              <span class="ob-tgl" :class="{ on: wantPush || pushState.enabled }"></span>
            </div>
            <div v-if="pushState.enabled" class="ob-sec-done">{{ lang === 'bn' ? '✅ এই ডিভাইসে পুশ চালু আছে।' : '✅ Push is on for this device.' }}</div>
            <div v-else-if="wantPush" class="ob-sec-flow">
              <button class="btn-primary" :disabled="busy" @click="enablePush">{{ busy ? (lang === 'bn' ? 'চালু হচ্ছে…' : 'Working…') : (lang === 'bn' ? '🔔 এই ডিভাইসে চালু করুন' : '🔔 Enable on this device') }}</button>
            </div>
          </div>

          <div class="ob-foot">
            <button class="btn-ghost" @click="back">← {{ t('Back') }}</button>
            <button class="btn-primary" :disabled="busy" @click="next">{{ lang === 'bn' ? 'চালিয়ে যান →' : 'Continue →' }}</button>
          </div>
        </div>

        <!-- 5 · DONE -->
        <div v-else class="ob-center">
          <div class="ob-hero ok">✓</div>
          <h1 class="ob-title">{{ lang === 'bn' ? 'সবকিছু প্রস্তুত! 🎉' : "You're all set! 🎉" }}</h1>
          <p class="ob-sub">{{ lang === 'bn' ? 'আপনার ওয়ার্কস্পেস এখন এ রকম দেখাচ্ছে:' : "Here's what your workspace looks like now:" }}</p>
          <div class="ob-sum">
            <div v-for="(it, i) in summary" :key="i" class="ob-sum-row">✅ {{ it }}</div>
            <div v-if="!summary.length" class="ob-sum-row">{{ lang === 'bn' ? '✅ আপনার ওয়ার্কস্পেস প্রস্তুত — শুরু করতে সাইডবার ঘুরে দেখুন।' : '✅ Your workspace is ready — explore the sidebar to start.' }}</div>
          </div>
          <div class="ob-actions">
            <button class="btn-primary" style="padding:12px 26px;font-size:14px" :disabled="busy" @click="finish">{{ lang === 'bn' ? 'আমার ড্যাশবোর্ড খুলুন →' : 'Open my dashboard →' }}</button>
          </div>
          <div class="ob-hint" v-html="lang === 'bn' ? '📚 টিপ: <b>উইকি ও সাহায্য</b> পেজে প্রতিটি স্ক্রিনের ভিজ্যুয়াল গাইড আছে।' : '📚 Tip: the <b>Wiki &amp; Help</b> page has visual guides for every screen.'"></div>
        </div>

        <!-- errors / success -->
        <div v-if="err" class="ob-err">{{ err }}</div>
        <div v-if="saved" class="ob-ok">{{ saved }}</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ob { min-height: calc(100vh - 60px); display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px 60px; }
.ob-card { width: 100%; max-width: 640px; }
.ob-steps { display: flex; justify-content: space-between; margin-bottom: 26px; padding: 0 4px; }
.ob-step { display: flex; flex-direction: column; align-items: center; gap: 6px; flex: 1; position: relative; }
.ob-step::before { content: ''; position: absolute; top: 15px; left: -50%; width: 100%; height: 2px; background: var(--border); z-index: 0; }
.ob-step:first-child::before { display: none; }
.ob-step.on::before { background: var(--primary); }
.ob-dot { width: 32px; height: 32px; border-radius: 50%; background: var(--card); border: 2px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 13px; z-index: 1; color: var(--text-mute); }
.ob-step.on .ob-dot { border-color: var(--primary); background: var(--primary-light); color: var(--primary); }
.ob-step.cur .ob-dot { box-shadow: 0 0 0 4px var(--primary-light); }
.ob-lab { font-size: 10px; color: var(--text-mute); font-weight: 600; }
.ob-step.on .ob-lab { color: var(--primary); }
.ob-body { background: var(--card); border: 1px solid var(--border); border-radius: 18px; padding: 30px 32px; box-shadow: var(--shadow); }
.ob-center { text-align: center; padding: 18px 0 8px; }
.ob-hero { width: 74px; height: 74px; margin: 0 auto 18px; border-radius: 22px; background: var(--grad); color: #fff; font-size: 34px; font-weight: 800; display: flex; align-items: center; justify-content: center; box-shadow: 0 14px 34px rgba(47,128,237,.35); }
.ob-hero.ok { background: linear-gradient(135deg, #27AE60, #1E8E4E); box-shadow: 0 14px 34px rgba(39,174,96,.30); }
.ob-title { font-size: 24px; font-weight: 800; color: var(--text); margin: 0 0 10px; }
.ob-sub { color: var(--text-soft); font-size: 14px; line-height: 1.65; margin: 0 0 22px; }
.ob-sub.l { text-align: left; }
.ob-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 8px; }
.ob-hint { font-size: 12px; color: var(--text-mute); margin-top: 18px; }
.ob-step-body { padding: 4px 0 2px; }
.ob-h { font-size: 20px; font-weight: 800; color: var(--text); margin: 0 0 6px; }
.ob-lab2 { display: block; font-size: 12.5px; font-weight: 700; color: var(--text-soft); margin-bottom: 6px; }
.ob-opt { font-weight: 500; color: var(--text-mute); }
.ob-inp { width: 100%; padding: 11px 13px; border: 1px solid var(--border); border-radius: 10px; background: var(--bg); color: var(--text); font-size: 14px; font-family: inherit; outline: none; box-sizing: border-box; }
.ob-inp:focus { border-color: var(--primary); }
.ob-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 14px; }
.ob-unit { margin-top: 16px; border: 1px dashed var(--border); border-radius: 12px; padding: 14px 16px 16px; background: var(--bg); }
.ob-unit-h { font-size: 13.5px; font-weight: 800; color: var(--text); }
.ob-unit-d { font-size: 12px; color: var(--text-mute); line-height: 1.5; margin: 4px 0 10px; }
@media (max-width: 520px) { .ob-grid2 { grid-template-columns: 1fr; } }
.ob-foot { display: flex; justify-content: space-between; align-items: center; margin-top: 26px; gap: 10px; }
.ob-rows { display: flex; flex-direction: column; gap: 2px; }
.ob-row { display: flex; align-items: center; gap: 12px; padding: 10px 6px; border-bottom: 1px solid var(--border); cursor: pointer; }
.ob-row:last-child { border-bottom: none; }
.ob-row-ico { font-size: 16px; width: 24px; text-align: center; }
.ob-row-txt { flex: 1; min-width: 0; }
.ob-row-t { font-size: 13.5px; font-weight: 700; color: var(--text); }
.ob-row-d { font-size: 11.5px; color: var(--text-mute); margin-top: 1px; }
.ob-tgl { width: 40px; height: 22px; border-radius: 12px; background: var(--border); position: relative; flex-shrink: 0; transition: background .15s; }
.ob-tgl.on { background: var(--ok); }
.ob-tgl::after { content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: left .15s; box-shadow: 0 1px 3px rgba(0,0,0,.25); }
.ob-tgl.on::after { left: 21px; }
.ob-sec-card { border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; background: var(--bg-alt); transition: border-color .15s; }
.ob-sec-card.active { border-color: var(--primary); }
.ob-sec-head { display: flex; align-items: flex-start; gap: 12px; cursor: pointer; }
.ob-sec-t { font-size: 13.5px; font-weight: 800; color: var(--text); }
.ob-sec-d { font-size: 11.5px; color: var(--text-mute); margin-top: 3px; line-height: 1.5; }
.ob-sec-flow { margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--border); }
.ob-sec-done { margin-top: 10px; font-size: 12.5px; font-weight: 700; color: var(--ok); }
.ob-sum { text-align: left; background: var(--bg-alt); border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; margin: 4px 0 20px; }
.ob-sum-row { font-size: 13.5px; color: var(--text); padding: 6px 0; border-bottom: 1px dashed var(--border); }
.ob-sum-row:last-child { border-bottom: none; }
.ob-err { margin-top: 14px; background: rgba(231,76,60,.12); color: var(--danger); border: 1px solid rgba(231,76,60,.35); border-radius: 10px; padding: 10px 14px; font-size: 12.5px; }
.ob-ok { margin-top: 14px; background: rgba(39,174,96,.12); color: var(--ok); border: 1px solid rgba(39,174,96,.35); border-radius: 10px; padding: 10px 14px; font-size: 12.5px; }
@media (max-width: 640px) {
  .ob { padding: 20px 12px 50px; }
  .ob-body { padding: 22px 18px; }
  .ob-grid2 { grid-template-columns: 1fr; }
  .ob-lab { font-size: 9px; }
}
</style>
