<script setup>
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const data = useDataStore()

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
      <div class="logo-mark">KR</div>
      <div>
        <div style="font-weight:800">KRTaker<small style="display:block;font-size:10px;color:var(--text-mute)">Key Responsibility Taker</small></div>
      </div>
    </div>
    <h1>Welcome back</h1>
    <p class="sub">Log in to your workspace — owner, tenant, partner or staff.</p>
    <div class="auth-err" :class="{ show: err }">{{ err }}</div>
    <div class="auth-field">
      <label>Email address</label>
      <input type="email" v-model="email" placeholder="you@example.com" autocomplete="email" @keyup.enter="doLogin">
    </div>
    <div class="auth-field">
      <label>Password</label>
      <input type="password" v-model="password" placeholder="••••••••" autocomplete="current-password" @keyup.enter="doLogin">
    </div>
    <div v-if="show2fa" class="auth-field">
      <label v-if="twofaMethod === 'email'">Enter the 6-digit code we emailed to {{ emailHint }}</label>
      <label v-else>Enter the 6-digit code from your authenticator app</label>
      <input type="text" v-model="twofa" inputmode="numeric" maxlength="6" placeholder="6-digit code" autocomplete="one-time-code" @keyup.enter="doLogin">
      <div style="margin-top:7px;font-size:12.5px">
        <a v-if="twofaMethod === 'email'" href="#" @click.prevent="resendOtp" style="color:var(--primary);font-weight:700">Resend code</a>
        <a v-else href="#" @click.prevent="useEmailOtp" style="color:var(--primary);font-weight:700">Use email code instead</a>
      </div>
    </div>
    <button class="auth-btn" :disabled="auth.loading" @click="doLogin">{{ auth.loading ? 'Signing in…' : 'Log in' }}</button>
    <div v-if="turnstile" ref="tsEl" class="auth-ts" style="margin-top:12px;display:flex;justify-content:center"></div>
    <div class="auth-creds">
      New here? <a href="https://krtaker.com/register.html" style="color:var(--primary);font-weight:700">Create an account →</a>
    </div>
  </div>
</template>
