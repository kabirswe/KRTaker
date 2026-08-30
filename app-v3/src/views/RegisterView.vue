<script setup>
// RegisterView — V2.35 self-service registration for new mall owners.
// Creates an isolated workspace (subscriber owner) via the app-register API,
// auto-logs in, then the router guard funnels the account into the guided
// setup wizard (/setup) since a fresh workspace has zero spaces.
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import { apiCall, getBranding, brandUrl, brandTitleOn } from '../api/client'
import { t, lang } from '../lib/i18n'

const router = useRouter()
const auth = useAuthStore()
const data = useDataStore()

const brand = ref({})
async function loadBrand() { brand.value = await getBranding() }
const loginLogo = (() => {
  const b = brand.value
  const img = b.dash_header || ''
  return { img: img ? brandUrl(img) : '', name: b.site_name || 'Mall Manager', mark: b.logo_text || 'MM' }
})
onMounted(loadBrand)

const form = ref({ name: '', email: '', phone: '', password: '', agree: false })
const err = ref('')
const busy = ref(false)
const okMsg = ref('')

async function doRegister() {
  err.value = ''; okMsg.value = ''
  if (!form.value.name.trim()) { err.value = lang.value === 'bn' ? 'আপনার নাম লিখুন।' : 'Please enter your name.'; return }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email.trim())) { err.value = lang.value === 'bn' ? 'সঠিক ইমেইল দিন।' : 'Please enter a valid email.'; return }
  if (form.value.password.length < 6) { err.value = lang.value === 'bn' ? 'পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে।' : 'Password must be at least 6 characters.'; return }
  if (!form.value.agree) { err.value = lang.value === 'bn' ? 'রেজিস্ট্রেশনের শর্তাবলি মেনে নিতে হবে।' : 'You must accept the terms to register.'; return }
  busy.value = true
  try {
    const r = await apiCall('app-register', {
      name: form.value.name.trim(),
      email: form.value.email.trim(),
      phone: form.value.phone.trim(),
      password: form.value.password,
      agree: form.value.agree ? '1' : '0',
    })
    if (!r.ok) { err.value = r.error || 'Registration failed.'; return }
    // auto-login with the returned token
    auth.token = r.token
    auth.user = r.user
    auth.validated = true
    try { localStorage.setItem('krtaker_dash_token', r.token) } catch (e) {}
    const ok = await data.bootstrap()
    if (ok || data.offline) router.push('/dashboard')
    else { err.value = data.error || 'Account created — please log in.'; auth.clear(); router.push('/login') }
  } finally { busy.value = false }
}
</script>

<template>
  <div class="auth-card">
    <div class="auth-logo">
      <template v-if="loginLogo().img">
        <img :src="loginLogo().img" style="height:40px;max-width:160px;object-fit:contain" alt="logo" />
      </template>
      <template v-else>
        <div class="logo-mark">{{ loginLogo().mark }}</div>
      </template>
      <div v-if="loginLogo().name" style="font-weight:800">{{ loginLogo().name }}<small style="display:block;font-size:10px;color:var(--text-mute)">Mall & Commercial Building Management</small></div>
    </div>
    <h1>{{ lang === 'bn' ? 'নতুন মল অ্যাকাউন্ট খুলুন' : 'Create a new mall account' }}</h1>
    <p class="sub">{{ lang === 'bn' ? 'নিবন্ধন করলেই আপনার নিজস্ব আইসোলেটেড ওয়ার্কস্পেস তৈরি হবে — তারপর গাইডেড সেটআপে মলের প্রোফাইল, প্রথম দোকান ও বিলিং সেটআপ করবেন (প্রায় ২ মিনিট)।' : 'Registering creates your own isolated workspace — the guided setup then walks you through the mall profile, first space and billing (~2 minutes).' }}</p>
    <div class="auth-err" :class="{ show: err }">{{ err }}</div>
    <div class="auth-field">
      <label>{{ t('Full name') }}</label>
      <input type="text" v-model="form.name" :placeholder="t('e.g. Alamgir Kabir Roni')" autocomplete="name" @keyup.enter="doRegister">
    </div>
    <div class="auth-field">
      <label>{{ t('Email address') }}</label>
      <input type="email" v-model="form.email" placeholder="you@example.com" autocomplete="email" @keyup.enter="doRegister">
    </div>
    <div class="auth-field">
      <label>{{ lang === 'bn' ? 'মোবাইল (ঐচ্ছিক)' : 'Mobile (optional)' }}</label>
      <input type="tel" v-model="form.phone" placeholder="+8801XXXXXXXXX" autocomplete="tel" @keyup.enter="doRegister">
    </div>
    <div class="auth-field">
      <label>{{ t('Password') }} <small style="color:var(--text-mute)">({{ lang === 'bn' ? 'কমপক্ষে ৬ অক্ষর' : 'min 6 chars' }})</small></label>
      <input type="password" v-model="form.password" placeholder="••••••••" autocomplete="new-password" @keyup.enter="doRegister">
    </div>
    <label style="display:flex;align-items:flex-start;gap:8px;font-size:12.5px;color:var(--text-soft);margin:4px 0 14px;cursor:pointer">
      <input type="checkbox" v-model="form.agree" style="margin-top:1px">
      <span>{{ lang === 'bn' ? 'আমি শর্তাবলি ও প্রাইভেসি পলিসিতে সম্মত — আমার ডেটা কেবল আমার ওয়ার্কস্পেসে থাকবে।' : 'I agree to the Terms & Privacy Policy — my data stays in my own workspace.' }}</span>
    </label>
    <button class="auth-btn" :disabled="busy" @click="doRegister">{{ busy ? (lang === 'bn' ? 'অ্যাকাউন্ট খোলা হচ্ছে…' : 'Creating…') : (lang === 'bn' ? 'অ্যাকাউন্ট খুলুন' : 'Create account') }}</button>
    <div class="auth-creds">
      {{ lang === 'bn' ? 'ইতিমধ্যে অ্যাকাউন্ট আছে?' : 'Already have an account?' }} <a href="#/login" style="color:var(--primary);font-weight:700">{{ t('Log in →') }}</a>
    </div>
  </div>
</template>
