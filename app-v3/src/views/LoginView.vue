<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import { getBranding, brandUrl, brandSlotSize, brandTitleOn } from '../api/client'
import { t, lang } from '../lib/i18n'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const data = useDataStore()

// V2.20: dynamic branding on the login screen (logo image + site name + colors)
const brand = ref({})
async function loadBrand() { brand.value = await getBranding() }
const loginLogo = computed(() => {
  const b = brand.value
  const img = b.dash_header || b.wl_logo_print || ''
  return {
    img: img ? brandUrl(img) : '',
    h: brandSlotSize(b, 'dash_header', 40),
    showTitle: brandTitleOn(b, 'dash_header'),
    name: b.site_name || 'Mall Manager',
    mark: b.logo_text || 'MM',
    grad: b.primary ? ('linear-gradient(135deg,' + b.primary + ',' + (b.secondary || b.primary) + ')') : '',
  }
})
onMounted(loadBrand)

// Deep-link destination preserved by the router guard (?redirect=/dashboard?gw=…&sid=…)
const redirectTo = (() => { try { return String(route.query.redirect || '/dashboard') } catch (e) { return '/dashboard' } })()

const email = ref('')
const password = ref('')
const twofa = ref('')
const show2fa = ref(false)
const twofaMethod = ref('totp')
const emailHint = ref('')
const twofaAlt = ref(false)
const err = ref('')
const turnstile = ref('')      // widget site key when configured
const tsToken = ref('')        // latest Turnstile token (sent with login)
const tsEl = ref(null)

// Anchor page-load time for bot-guard ft (like krBG.attach does in v2)
onMounted(() => {
  window.__krtFt = Date.now()
  // Optional Cloudflare Turnstile widget — only when a site key is configured
  // (theme exposes it; server verifies only when turnstile_secret is set).
  try {
    fetch('../api/app-theme', { headers: {} }).then((r) => r.json()).then((d) => {
      const key = (d && d.theme && d.theme.turnstile_site_key) || ''
      if (!key) return
      turnstile.value = key
      const s = document.createElement('script')
      s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
      s.onload = () => {
        try {
          window.turnstile.render(tsEl.value, {
            sitekey: key,
            callback: (tok) => { tsToken.value = tok },
            'expired-callback': () => { tsToken.value = '' },
          })
        } catch (e) { /* widget unavailable — PoW still guards */ }
      }
      document.head.appendChild(s)
    }).catch(() => {})
  } catch (e) { /* optional */ }
})

async function doLogin() {
  err.value = ''
  if (!email.value || !password.value) { err.value = 'Email and password are required.'; return }
  // Turnstile token rides along when the widget rendered (server ignores it otherwise)
  const extra = tsToken.value ? { 'cf-turnstile-response': tsToken.value } : {}
  if (twofaAlt.value) { extra['2fa_alt'] = 'email'; twofaAlt.value = false }
  const r = await auth.login(email.value, password.value, twofa.value, extra)
  if (r.ok) {
    const ok = await data.bootstrap()
    if (ok || data.offline) router.push(redirectTo)
    else { err.value = data.error || 'Login failed.'; auth.clear() }
  } else if (r.need2fa) {
    show2fa.value = true
    twofaMethod.value = r.method || 'totp'
    emailHint.value = r.email_hint || ''
    twofa.value = ''
    err.value = ''
  } else {
    err.value = r.error || 'Invalid email or password.'
  }
}
// Email-OTP recovery: switch from authenticator codes to an emailed code.
function useEmailOtp() { twofaAlt.value = true; twofa.value = ''; doLogin() }
// Resend: re-submit without a code → server mints + emails a fresh code.
function resendOtp() { twofa.value = ''; doLogin() }
</script>

<template>
  <div class="auth-card">
    <div class="auth-logo">
      <template v-if="loginLogo.img">
        <img :src="loginLogo.img" :style="{ height: loginLogo.h + 'px', maxWidth: '160px', objectFit: 'contain' }" alt="logo" />
      </template>
      <template v-else>
        <div class="logo-mark" :style="loginLogo.grad ? { background: loginLogo.grad } : {}">{{ loginLogo.mark }}</div>
      </template>
      <div v-if="loginLogo.showTitle">
        <div style="font-weight:800">{{ loginLogo.name }}<small style="display:block;font-size:10px;color:var(--text-mute)">Mall & Commercial Building Management</small></div>
      </div>
    </div>
    <h1>{{ t('Welcome back') }}</h1>
    <p class="sub">{{ lang === 'bn' ? 'আপনার ওয়ার্কস্পেসে লগ ইন করুন — মালিক, ভাড়াটিয়া, পার্টনার বা স্টাফ।' : 'Log in to your workspace — owner, tenant, partner or staff.' }}</p>
    <div class="auth-err" :class="{ show: err }">{{ err }}</div>
    <div class="auth-field">
      <label>{{ t('Email address') }}</label>
      <input type="email" v-model="email" placeholder="you@example.com" autocomplete="email" @keyup.enter="doLogin">
    </div>
    <div class="auth-field">
      <label>{{ t('Password') }}</label>
      <input type="password" v-model="password" placeholder="••••••••" autocomplete="current-password" @keyup.enter="doLogin">
    </div>
    <div v-if="show2fa" class="auth-field">
      <label v-if="twofaMethod === 'email'">{{ lang === 'bn' ? ('আমরা ' + emailHint + ' ঠিকানায় ৬-ডিজিটের কোড পাঠিয়েছি') : ('Enter the 6-digit code we emailed to ' + emailHint) }}</label>
      <label v-else>{{ lang === 'bn' ? 'আপনার অথেন্টিকেটর অ্যাপ থেকে ৬-ডিজিটের কোড লিখুন' : 'Enter the 6-digit code from your authenticator app' }}</label>
      <input type="text" v-model="twofa" inputmode="numeric" maxlength="6" placeholder="6-digit code" autocomplete="one-time-code" @keyup.enter="doLogin">
      <div style="margin-top:7px;font-size:12.5px">
        <a v-if="twofaMethod === 'email'" href="#" @click.prevent="resendOtp" style="color:var(--primary);font-weight:700">{{ lang === 'bn' ? 'কোড আবার পাঠান' : 'Resend code' }}</a>
        <a v-else href="#" @click.prevent="useEmailOtp" style="color:var(--primary);font-weight:700">{{ lang === 'bn' ? 'পরিবর্তে ইমেইল কোড ব্যবহার করুন' : 'Use email code instead' }}</a>
      </div>
    </div>
    <button class="auth-btn" :disabled="auth.loading" @click="doLogin">{{ auth.loading ? (lang === 'bn' ? 'সাইন ইন হচ্ছে…' : 'Signing in…') : t('Log in') }}</button>
    <div v-if="turnstile" ref="tsEl" class="auth-ts" style="margin-top:12px;display:flex;justify-content:center"></div>
    <div class="auth-creds">
      {{ lang === 'bn' ? 'নতুন?' : 'New here?' }} <a href="#" style="color:var(--primary);font-weight:700">{{ lang === 'bn' ? 'অ্যাকাউন্ট তৈরি করুন →' : 'Create an account →' }}</a>
    </div>
    <div style="margin-top:10px;text-align:center;font-size:12px">
      🏬 Space owner? <a href="#/owner" style="color:var(--primary);font-weight:800">{{ t('Open the owner portal →') }}</a>
    </div>
    <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border);display:flex;justify-content:center;gap:16px;font-size:11.5px">
      <a href="#" style="color:var(--text-mute)">{{ t('Privacy') }}</a>
      <a href="#" style="color:var(--text-mute)">{{ t('Terms') }}</a>
      <a href="#" style="color:var(--text-mute)">Mall Manager</a>
    </div>
  </div>
</template>
