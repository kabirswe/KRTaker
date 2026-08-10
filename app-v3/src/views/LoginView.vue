<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'

const router = useRouter()
const auth = useAuthStore()
const data = useDataStore()

const email = ref('')
const password = ref('')
const twofa = ref('')
const show2fa = ref(false)
const err = ref('')

// Anchor page-load time for bot-guard ft (like krBG.attach does in v2)
onMounted(() => { window.__krtFt = Date.now() })

async function doLogin() {
  err.value = ''
  if (!email.value || !password.value) { err.value = 'Email and password are required.'; return }
  const r = await auth.login(email.value, password.value, twofa.value)
  if (r.ok) {
    const ok = await data.bootstrap()
    if (ok || data.offline) router.push('/dashboard')
    else { err.value = data.error || 'Login failed.'; auth.clear() }
  } else if (r.need2fa) {
    show2fa.value = true
    err.value = ''
  } else {
    err.value = r.error || 'Invalid email or password.'
  }
}
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
      <label>Authenticator code</label>
      <input type="text" v-model="twofa" inputmode="numeric" maxlength="6" placeholder="6-digit code" autocomplete="one-time-code" @keyup.enter="doLogin">
    </div>
    <button class="auth-btn" :disabled="auth.loading" @click="doLogin">{{ auth.loading ? 'Signing in…' : 'Log in' }}</button>
    <div class="auth-creds">
      New here? <a href="https://krtaker.com/register.html" style="color:var(--primary);font-weight:700">Create an account →</a>
    </div>
  </div>
</template>
