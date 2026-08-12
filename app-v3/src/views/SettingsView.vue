<script setup>
import { ref, onMounted, computed } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import { apiCall } from '../api/client'

const auth = useAuthStore()
const data = useDataStore()

const name = ref('')
const dept = ref('')
const avatar = ref('')
const oldPw = ref('')
const newPw = ref('')
const prefs = ref({})
const saved = ref('')
const err = ref('')
const account = ref({})
const twofa = ref(null)

const isStaff = computed(() => auth.user?.is_staff || ['superadmin', 'owner', 'manager', 'hr', 'accountant', 'crm', 'legal', 'svc_mgr'].includes(auth.user?.role))

// ── Web push notifications (V2.14) ──
const pushState = ref({ loading: true, enabled: false, devices: 0, vapid: '', subscribed: false, notifPermission: Notification ? Notification.permission : 'unsupported' })
const pushBusy = ref(false)

async function loadPushState() {
  try {
    const r = await apiCall('app-push?action=state', {})
    if (r.ok) pushState.value = { loading: false, enabled: !!r.enabled, devices: r.devices || 0, vapid: r.vapid_public || '', subscribed: !!r.subscribed, notifPermission: Notification ? Notification.permission : 'unsupported' }
  } catch (e) { pushState.value.loading = false }
}

async function enablePush() {
  pushBusy.value = true; err.value = ''; saved.value = ''
  try {
    if (!('Notification' in window) || !('PushManager' in window) || !('serviceWorker' in navigator)) {
      err.value = 'Push notifications are not supported in this browser.'; return
    }
    if (pushState.value.notifPermission !== 'granted') {
      const perm = await Notification.requestPermission()
      pushState.value.notifPermission = perm
      if (perm !== 'granted') { err.value = 'Permission denied — enable notifications in your browser settings to continue.'; return }
    }
    // make sure the SW is ready (it handles push events)
    const reg = await navigator.serviceWorker.ready
    // fetch VAPID key fresh (in case of reload before state loaded)
    if (!pushState.value.vapid) {
      const st = await apiCall('app-push?action=state', {})
      if (st.ok) pushState.value.vapid = st.vapid_public || ''
    }
    if (!pushState.value.vapid) { err.value = 'Push is not configured on the server yet.'; return }
    const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: pushState.value.vapid })
    const json = sub.toJSON()
    const r = await apiCall('app-push', { action: 'save', endpoint: json.endpoint, p256dh: json.keys.p256dh, auth: json.keys.auth, ua: navigator.userAgent.slice(0, 200) })
    if (r.ok) { saved.value = 'Push notifications enabled for this device.'; await loadPushState() }
    else err.value = r.error || 'Failed to save subscription.'
  } catch (e) {
    err.value = 'Could not subscribe: ' + (e && e.message ? e.message : e)
  } finally { pushBusy.value = false }
}

async function testPush() {
  pushBusy.value = true; err.value = ''; saved.value = ''
  try {
    const r = await apiCall('app-push', { action: 'test' })
    if (r.ok) saved.value = 'Test notification ' + (r.sent > 0 ? 'sent to your device(s).' : '— ' + (r.detail || 'no devices.'))
    else err.value = r.error || 'Test failed.'
  } catch (e) { err.value = 'Network error.' }
  finally { pushBusy.value = false }
}

async function disablePush() {
  pushBusy.value = true; err.value = ''
  try {
    let ep = ''
    if ('serviceWorker' in navigator) {
      const reg = await navigator.serviceWorker.ready
      const sub = await reg.pushManager.getSubscription()
      if (sub) { ep = sub.endpoint; await sub.unsubscribe() }
    }
    if (ep) await apiCall('app-push', { action: 'remove', endpoint: ep })
    pushState.value.enabled = false; pushState.value.devices = 0
    saved.value = 'Push notifications disabled.'
  } catch (e) { err.value = 'Could not unsubscribe.' }
  finally { pushBusy.value = false }
}

onMounted(async () => {
  const u = auth.user || {}
  name.value = u.name || ''
  dept.value = u.dept || ''
  avatar.value = u.avatar || ''
  const r = await apiCall('app-settings-get')
  if (r.ok) {
    prefs.value = r.settings || {}
    account.value = r.account || r.user || {}
    twofa.value = r.twofa ?? (r.account?.twofa ?? null)
  }
  loadSecurity()
  loadPushState()
})

function setLang(lang) { prefs.value.lang = lang }
function setTheme(t) { prefs.value.theme = t; document.documentElement.setAttribute('data-theme', t === 'dark' ? 'dark' : '') }

async function saveProfile() {
  err.value = ''; saved.value = ''
  const body = { name: name.value }
  if (isStaff.value) { body.dept = dept.value; body.avatar = avatar.value }
  else { body.org = auth.user?.org || '' }
  const r = await apiCall('app-profile', body)
  if (r.ok) { saved.value = 'Profile saved.'; auth.user = r.user || auth.user }
  else err.value = r.error || 'Failed to save.'
}

async function changePassword() {
  err.value = ''; saved.value = ''
  if (!oldPw.value || !newPw.value) { err.value = 'Current and new password are required.'; return }
  if (newPw.value.length < 6) { err.value = 'New password must be at least 6 characters.'; return }
  const r = await apiCall('app-profile', { old_password: oldPw.value, new_password: newPw.value })
  if (r.ok) { saved.value = 'Password changed.'; oldPw.value = ''; newPw.value = '' }
  else err.value = r.error || 'Failed to change password.'
}

async function saveSettings() {
  err.value = ''; saved.value = ''
  const r = await apiCall('app-settings-save', { settings: prefs.value })
  if (r.ok) saved.value = 'Preferences saved.'
  else err.value = r.error || 'Failed to save.'
}

const roleLabel = computed(() => {
  const m = { superadmin: 'Super Admin', owner: 'Owner', manager: 'Property Manager', staff: 'Staff', tenant: 'Tenant', partner: 'Partner' }
  return m[data.previewRole || auth.user?.role] || auth.user?.role || '—'
})

// ── Login security (reCAPTCHA v3 + Cloudflare Turnstile) — owner/superadmin only ──
const isOwner = computed(() => ['superadmin', 'owner'].includes(auth.user?.role))
const secForm = ref({ recaptcha_site_key: '', recaptcha_secret: '', turnstile_site_key: '', turnstile_secret: '', bot_guard: true, bot_pow_bits: 12 })
const secLoading = ref(false)
const secSaving = ref(false)

async function loadSecurity() {
  if (!isOwner.value) return
  secLoading.value = true
  try {
    const r = await apiCall('app-security', { action: 'config-get' })
    if (r.ok) {
      secForm.value = {
        recaptcha_site_key: r.recaptcha_site_key || '',
        recaptcha_secret: r.recaptcha_secret || '',
        turnstile_site_key: r.turnstile_site_key || '',
        turnstile_secret: r.turnstile_secret || '',
        bot_guard: !!r.bot_guard,
        bot_pow_bits: r.bot_pow_bits || 12,
      }
    }
  } finally { secLoading.value = false }
}

async function saveSecurity() {
  err.value = ''; saved.value = ''
  secSaving.value = true
  try {
    const r = await apiCall('app-security', { action: 'config-save', ...secForm.value })
    if (r.ok) { saved.value = 'Security settings saved.'; await loadSecurity() }
    else err.value = r.error || 'Failed to save security settings.'
  } finally { secSaving.value = false }
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>⚙️ Settings</h1>
        <div class="sub">Profile, preferences, security</div>
      </div>
    </div>

    <div v-if="err" class="auth-err show">{{ err }}</div>
    <div v-if="saved" style="background:rgba(39,174,96,.1);color:#1e8e4d;padding:10px 13px;border-radius:9px;font-size:12.5px;font-weight:700;margin-bottom:14px">{{ saved }}</div>

    <div class="grid grid-2">
      <!-- Account -->
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">🪪</span>Account</div></div>
        <div class="panel-b">
          <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px">
            <div style="width:52px;height:52px;border-radius:50%;background:var(--grad);color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:800;flex-shrink:0">
              {{ (name || auth.user?.name || '?').trim()[0]?.toUpperCase() || '?' }}
            </div>
            <div>
              <div style="font-weight:800;font-size:15px">{{ auth.user?.name || '—' }}</div>
              <div class="c-sub" style="font-size:12.5px">{{ auth.user?.email || '—' }}</div>
              <div style="margin-top:4px"><span class="badge b-blue">{{ roleLabel }}</span></div>
            </div>
          </div>
          <div class="kv"><span class="k">Member since</span><span class="v">{{ account.created || account.member_since || auth.user?.created || '—' }}</span></div>
          <div class="kv"><span class="k">Last login</span><span class="v">{{ account.last_login || '—' }}</span></div>
          <div class="kv">
            <span class="k">Two-factor auth</span>
            <span class="v">
              <span class="badge" :class="twofa ? 'b-green' : 'b-gray'">{{ twofa ? '🔒 Enabled' : 'Disabled' }}</span>
            </span>
          </div>
        </div>
      </div>

      <!-- Profile -->
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">👤</span>Profile</div></div>
        <div class="panel-b">
          <div class="form-field" style="margin-bottom:12px">
            <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">Display name</label>
            <input v-model="name" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-family:inherit;font-size:13.5px;background:var(--bg);color:var(--text)">
          </div>
          <div v-if="isStaff" class="form-field" style="margin-bottom:12px">
            <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">Department</label>
            <input v-model="dept" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-family:inherit;font-size:13.5px;background:var(--bg);color:var(--text)">
          </div>
          <div v-if="isStaff" class="form-field" style="margin-bottom:12px">
            <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">Avatar URL (optional)</label>
            <input v-model="avatar" placeholder="https://…" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-family:inherit;font-size:13.5px;background:var(--bg);color:var(--text)">
          </div>
          <button class="btn-primary" style="width:100%" @click="saveProfile">Save profile</button>
        </div>
      </div>
    </div>

    <div class="grid grid-2" style="margin-top:18px">
      <!-- Preferences -->
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">🎨</span>Preferences</div></div>
        <div class="panel-b">
          <div style="font-size:12px;font-weight:700;margin-bottom:6px">Theme</div>
          <div style="display:flex;gap:8px;margin-bottom:14px">
            <button class="btn-ghost" :style="(prefs.theme || 'light') === 'light' ? 'border-color:var(--primary);background:var(--primary-light)' : ''" @click="setTheme('light')">☀️ Light</button>
            <button class="btn-ghost" :style="prefs.theme === 'dark' ? 'border-color:var(--primary);background:var(--primary-light)' : ''" @click="setTheme('dark')">🌙 Dark</button>
          </div>
          <div style="font-size:12px;font-weight:700;margin-bottom:6px">Language</div>
          <div style="display:flex;gap:8px;margin-bottom:14px">
            <button class="btn-ghost" :style="(prefs.lang || 'en') === 'en' ? 'border-color:var(--primary);background:var(--primary-light)' : ''" @click="setLang('en')">🇬🇧 English</button>
            <button class="btn-ghost" :style="prefs.lang === 'bn' ? 'border-color:var(--primary);background:var(--primary-light)' : ''" @click="setLang('bn')">🇧🇩 বাংলা</button>
          </div>
          <button class="btn-primary" style="width:100%" @click="saveSettings">Save preferences</button>
        </div>
      </div>

      <!-- Security -->
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">🔑</span>Change password</div></div>
        <div class="panel-b">
          <div class="form-field" style="margin-bottom:12px">
            <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">Current password</label>
            <input v-model="oldPw" type="password" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-family:inherit;font-size:13.5px;background:var(--bg);color:var(--text)">
          </div>
          <div class="form-field" style="margin-bottom:12px">
            <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">New password (min 6)</label>
            <input v-model="newPw" type="password" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-family:inherit;font-size:13.5px;background:var(--bg);color:var(--text)">
          </div>
          <button class="btn-primary" style="width:100%" @click="changePassword">Change password</button>
        </div>
      </div>
    </div>

    <!-- Login security (owner) -->
    <div v-if="isOwner" class="panel" style="margin-top:18px">
      <div class="panel-h"><div class="t"><span class="pi">🔐</span>Login security <span class="badge b-blue" style="margin-left:8px">Owner</span></div></div>
      <div class="panel-b">
        <div class="c-sub" style="font-size:12.5px;margin-bottom:14px">Optional human-verification on the login form. When a secret is set, a valid token is required to log in; leave both blank to keep the built-in proof-of-work guard only.</div>
        <div class="grid grid-2" style="gap:14px">
          <div class="form-field">
            <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">Google reCAPTCHA v3 — site key</label>
            <input v-model="secForm.recaptcha_site_key" placeholder="6Lc…" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-family:inherit;font-size:13.5px;background:var(--bg);color:var(--text)">
          </div>
          <div class="form-field">
            <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">Google reCAPTCHA v3 — secret key</label>
            <input v-model="secForm.recaptcha_secret" placeholder="6Lc…" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-family:inherit;font-size:13.5px;background:var(--bg);color:var(--text)">
          </div>
          <div class="form-field">
            <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">Cloudflare Turnstile — site key</label>
            <input v-model="secForm.turnstile_site_key" placeholder="0x4AAAA…" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-family:inherit;font-size:13.5px;background:var(--bg);color:var(--text)">
          </div>
          <div class="form-field">
            <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">Cloudflare Turnstile — secret key</label>
            <input v-model="secForm.turnstile_secret" placeholder="0x4AAAA…" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-family:inherit;font-size:13.5px;background:var(--bg);color:var(--text)">
          </div>
        </div>
        <div style="display:flex;gap:24px;align-items:center;margin:14px 0;flex-wrap:wrap">
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;cursor:pointer">
            <input type="checkbox" v-model="secForm.bot_guard"> Bot guard (PoW + time-trap) enabled
          </label>
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700">
            PoW difficulty
            <input type="number" v-model.number="secForm.bot_pow_bits" min="8" max="24" style="width:70px;padding:7px 9px;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:13px;background:var(--bg);color:var(--text)">
          </label>
        </div>
        <div class="c-sub" style="font-size:12px;margin-bottom:12px">Get keys from Google Cloud console (reCAPTCHA → v3) or the Cloudflare dashboard (Turnstile). Secrets are masked — an unchanged value is kept as-is; blank clears.</div>
        <button class="btn-primary" :disabled="secSaving" @click="saveSecurity">{{ secSaving ? 'Saving…' : '💾 Save security settings' }}</button>
      </div>

      <!-- Notifications -->
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">🔔</span>Push notifications <span v-if="pushState.enabled" class="badge b-green" style="margin-left:8px">On</span><span v-else class="badge b-gray" style="margin-left:8px">Off</span></div></div>
        <div class="panel-b">
          <div class="c-sub" style="font-size:12.5px;margin-bottom:12px">Get instant alerts on this device when a tenant raises maintenance, a payment is recorded, or a KYC application is submitted — even when the app is closed.</div>
          <div v-if="pushState.loading" style="color:var(--text-mute);font-size:13px">Loading…</div>
          <template v-else>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
              <button v-if="!pushState.enabled" class="btn-primary" :disabled="pushBusy" @click="enablePush">{{ pushBusy ? 'Working…' : '🔔 Enable notifications' }}</button>
              <template v-else>
                <button class="btn-ghost" :disabled="pushBusy" @click="testPush">{{ pushBusy ? 'Sending…' : '🧪 Send test' }}</button>
                <button class="btn-ghost" style="color:var(--danger)" :disabled="pushBusy" @click="disablePush">{{ pushBusy ? 'Working…' : '🚫 Disable' }}</button>
              </template>
            </div>
            <div style="font-size:12.5px;color:var(--text-mute)">
              <template v-if="pushState.enabled">✅ Active on <b>{{ pushState.devices }}</b> device{{ pushState.devices === 1 ? '' : 's' }}</template>
              <template v-else-if="pushState.notifPermission === 'denied'">⚠️ Notification permission is blocked in this browser — unblock it from the site settings (padlock icon) to enable.</template>
              <template v-else>No devices registered on this browser yet.</template>
            </div>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
