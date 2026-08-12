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
  loadTwofa()
  loadSessions()
  loadLoginHistory()
  if (auth.user?.role === 'superadmin') loadAudit()
})

// ── Two-factor authentication (superadmin) — V2.16 ──
const isSuperAdmin = computed(() => auth.user?.role === 'superadmin')
const twofaState = ref({ enabled: false, method: 'totp', email_hint: '' })
const twofaBusy = ref(false)
const twofaCode = ref('')
const twofaPw = ref('')
const twofaSetup = ref(null)   // { secret, uri } from app-2fa-setup
const twofaStep = ref('')      // '' | 'email-code' | 'totp-code' | 'disable'
const toast = (m, t) => { try { window.__krToast?.(m, t) } catch (e) {} }

async function loadTwofa() {
  if (!isSuperAdmin.value) return
  try {
    const r = await apiCall('app-2fa-status', {})
    if (r.ok) twofaState.value = { enabled: !!r.enabled, method: r.method || 'totp', email_hint: r.email_hint || '' }
  } catch (e) { /* silent */ }
}
async function sendTwofaCode() {
  twofaBusy.value = true
  try {
    const r = await apiCall('app-2fa-send', {})
    if (r.ok) { twofaState.value.email_hint = r.email_hint || twofaState.value.email_hint; toast('Code sent to ' + twofaState.value.email_hint, 'ok') }
    else toast(r.error || 'Failed to send code.', 'error')
  } finally { twofaBusy.value = false }
}
async function enableEmail2fa() {
  if (!twofaCode.value) { toast('Enter the code from your email.', 'error'); return }
  twofaBusy.value = true
  try {
    const r = await apiCall('app-2fa-enable', { method: 'email', code: twofaCode.value })
    if (r.ok) { toast('2FA enabled with email codes ✅', 'ok'); twofaStep.value = ''; twofaCode.value = ''; await loadTwofa() }
    else toast(r.error || 'Enable failed.', 'error')
  } finally { twofaBusy.value = false }
}
async function setupTotp() {
  twofaBusy.value = true
  try {
    const r = await apiCall('app-2fa-setup', {})
    if (r.ok) { twofaSetup.value = { secret: r.secret, uri: r.uri }; twofaStep.value = 'totp-code' }
    else toast(r.error || 'Setup failed.', 'error')
  } finally { twofaBusy.value = false }
}
async function enableTotp2fa() {
  if (!twofaCode.value) { toast('Enter the 6-digit code from your authenticator app.', 'error'); return }
  twofaBusy.value = true
  try {
    const r = await apiCall('app-2fa-enable', { method: 'totp', code: twofaCode.value })
    if (r.ok) { toast('2FA enabled with authenticator ✅', 'ok'); twofaStep.value = ''; twofaSetup.value = null; twofaCode.value = ''; await loadTwofa() }
    else toast(r.error || 'Enable failed.', 'error')
  } finally { twofaBusy.value = false }
}
async function disable2fa() {
  if (!twofaCode.value || !twofaPw.value) { toast('Enter the verification code and your password.', 'error'); return }
  twofaBusy.value = true
  try {
    const r = await apiCall('app-2fa-disable', { code: twofaCode.value, password: twofaPw.value })
    if (r.ok) { toast('2FA disabled', 'ok'); twofaStep.value = ''; twofaCode.value = ''; twofaPw.value = ''; await loadTwofa() }
    else toast(r.error || 'Disable failed.', 'error')
  } finally { twofaBusy.value = false }
}

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
const secForm = ref({ recaptcha_site_key: '', recaptcha_secret: '', turnstile_site_key: '', turnstile_secret: '', bot_guard: true, bot_pow_bits: 12, session_ttl_hours: 168, password_min: 8 })
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
        session_ttl_hours: r.session_ttl_hours || 168,
        password_min: r.password_min || 8,
      }
      secAlerts.value = r.sec_login_alerts !== false
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

// ── Sign-in & sessions (V2.17) ──
const sessions = ref([])
const sessionsLoading = ref(false)
const sessionsBusy = ref(false)
const loginHistory = ref([])
const confirmRevoke = ref('')          // '' | 'others' | 'all' (two-step confirm, no native confirm())
const secAlerts = ref(true)

function fmtAgo(d) {
  if (!d) return '—'
  const t = new Date(String(d).replace(' ', 'T') + 'Z').getTime()
  if (isNaN(t)) return String(d).slice(0, 16)
  const s = Math.max(0, (Date.now() - t) / 1000)
  if (s < 60) return 'just now'
  if (s < 3600) return Math.floor(s / 60) + 'm ago'
  if (s < 86400) return Math.floor(s / 3600) + 'h ago'
  if (s < 604800) return Math.floor(s / 86400) + 'd ago'
  return new Date(t).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' })
}
function fmtDate(d) {
  if (!d) return '—'
  const t = new Date(String(d).replace(' ', 'T') + 'Z')
  if (isNaN(t)) return String(d).slice(0, 16)
  return t.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

async function loadSessions() {
  sessionsLoading.value = true
  try {
    const r = await apiCall('app-sessions', {})
    if (r.ok) sessions.value = r.sessions || []
  } catch (e) { sessions.value = [] }
  finally { sessionsLoading.value = false }
}
async function loadLoginHistory() {
  try {
    const r = await apiCall('app-login-history', {})
    if (r.ok) loginHistory.value = r.history || []
  } catch (e) { loginHistory.value = [] }
}
async function revokeSession(id) {
  sessionsBusy.value = true; err.value = ''; saved.value = ''
  try {
    const r = await apiCall('app-sessions', { action: 'revoke', id })
    if (r.ok) {
      if (r.current_ended) {
        try { localStorage.removeItem('krtaker_dash_token') } catch (e) {}
        auth.token = ''; auth.user = null
        location.reload()
        return
      }
      saved.value = 'Device signed out.'
      await loadSessions()
    } else err.value = r.error || 'Failed to revoke session.'
  } finally { sessionsBusy.value = false }
}
async function revokeOthers() {
  sessionsBusy.value = true; err.value = ''; saved.value = ''
  try {
    const r = await apiCall('app-sessions', { action: 'revoke_others' })
    if (r.ok) { saved.value = `Signed out ${r.revoked || 0} other device${r.revoked === 1 ? '' : 's'}.`; confirmRevoke.value = ''; await loadSessions() }
    else err.value = r.error || 'Failed to sign out other devices.'
  } finally { sessionsBusy.value = false }
}
async function revokeAll() {
  sessionsBusy.value = true; err.value = ''; saved.value = ''
  try {
    const r = await apiCall('app-sessions', { action: 'revoke_all' })
    if (r.ok) {
      try { localStorage.removeItem('krtaker_dash_token') } catch (e) {}
      auth.token = ''; auth.user = null
      location.reload()
    } else err.value = r.error || 'Failed to sign out everywhere.'
  } finally { sessionsBusy.value = false }
}
async function saveSecAlerts() {
  sessionsBusy.value = true; err.value = ''; saved.value = ''
  try {
    const r = await apiCall('app-security', { action: 'config-save', sec_login_alerts: secAlerts.value })
    if (r.ok) saved.value = 'New sign-in alerts ' + (secAlerts.value ? 'enabled for this workspace.' : 'turned off for this workspace.')
    else err.value = r.error || 'Failed to save.'
  } finally { sessionsBusy.value = false }
}

// ── Audit log viewer (superadmin) ──
const auditEntries = ref([])
const auditLoading = ref(false)
async function loadAudit() {
  auditLoading.value = true; err.value = ''
  try {
    const r = await apiCall('app-audit', { limit: 50, offset: 0 })
    if (r.ok) auditEntries.value = r.entries || []
    else err.value = r.error || 'Failed to load audit log.'
  } catch (e) { err.value = e.message }
  finally { auditLoading.value = false }
}
const fmtAuditTs = (ts) => { if (!ts) return '—'; return String(ts).replace('T', ' ').slice(0, 16) }

// ── Backup console (superadmin) ──
const backupBusy = ref('')
function saveBlob(blob, fname) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a'); a.href = url; a.download = fname
  document.body.appendChild(a); a.click(); a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 2000)
}
async function downloadBackup() {
  backupBusy.value = 'db'; err.value = ''; saved.value = ''
  try {
    const res = await fetch('https://krtaker.com/api/app-backup', { headers: { Authorization: 'Bearer ' + (auth.token || '') } })
    if (!res.ok) { err.value = 'Backup failed (HTTP ' + res.status + ')'; return }
    const blob = await res.blob()
    saveBlob(blob, 'krtaker_' + new Date().toISOString().slice(0, 10) + '.db')
    saved.value = 'DB snapshot downloaded.'
  } catch (e) { err.value = 'Backup failed: ' + e.message }
  finally { backupBusy.value = '' }
}
async function downloadExport() {
  backupBusy.value = 'json'; err.value = ''; saved.value = ''
  try {
    const res = await fetch('https://krtaker.com/api/app-export', { headers: { Authorization: 'Bearer ' + (auth.token || '') } })
    if (!res.ok) { err.value = 'Export failed (HTTP ' + res.status + ')'; return }
    const blob = await res.blob()
    saveBlob(blob, 'krtaker_export_' + new Date().toISOString().slice(0, 10) + '.json')
    saved.value = 'Full JSON export downloaded.'
  } catch (e) { err.value = 'Export failed: ' + e.message }
  finally { backupBusy.value = '' }
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
            <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">New password (min {{ secForm.password_min || 8 }})</label>
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
        <div style="display:flex;gap:24px;align-items:center;margin:14px 0;flex-wrap:wrap">
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700">
            Session expiry
            <select v-model.number="secForm.session_ttl_hours" style="padding:7px 9px;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:13px;background:var(--bg);color:var(--text)">
              <option :value="12">12 hours</option>
              <option :value="24">1 day</option>
              <option :value="72">3 days</option>
              <option :value="168">7 days (default)</option>
              <option :value="336">14 days</option>
              <option :value="720">30 days</option>
            </select>
          </label>
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700">
            Min password length
            <input type="number" v-model.number="secForm.password_min" min="6" max="32" style="width:70px;padding:7px 9px;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:13px;background:var(--bg);color:var(--text)">
          </label>
        </div>
        <div class="c-sub" style="font-size:12px;margin-bottom:12px">Get keys from Google Cloud console (reCAPTCHA → v3) or the Cloudflare dashboard (Turnstile). Secrets are masked — an unchanged value is kept as-is; blank clears. Session expiry applies to all non-superadmin roles; superadmin stays at 12h.</div>
        <button class="btn-primary" :disabled="secSaving" @click="saveSecurity">{{ secSaving ? 'Saving…' : '💾 Save security settings' }}</button>
      </div>

      <!-- Two-factor authentication (superadmin) -->
      <div v-if="isSuperAdmin" class="panel" style="margin-top:18px">
        <div class="panel-h"><div class="t"><span class="pi">🔐</span>Two-factor authentication <span v-if="twofaState.enabled" class="badge b-green" style="margin-left:8px">On · {{ twofaState.method === 'email' ? 'Email codes' : 'Authenticator' }}</span><span v-else class="badge b-gray" style="margin-left:8px">Off</span></div></div>
        <div class="panel-b">
          <div class="c-sub" style="font-size:12.5px;margin-bottom:14px">Require a second verification step at login. Choose <b>email codes</b> (6-digit code sent to {{ twofaState.email_hint || 'your account email' }}) or an <b>authenticator app</b> (TOTP).</div>

          <template v-if="!twofaState.enabled">
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
              <button class="btn-primary" :disabled="twofaBusy" @click="twofaStep = 'email-code'">📧 Enable with email codes</button>
              <button class="btn-ghost" :disabled="twofaBusy" @click="setupTotp">📱 Enable with authenticator</button>
            </div>

            <div v-if="twofaStep === 'email-code'" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px">
              <div style="font-size:13px;margin-bottom:10px">1. Tap <b>Send code</b> — we'll email a 6-digit code to {{ twofaState.email_hint || 'your account email' }}. 2. Enter it below and confirm.</div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <button class="btn-ghost" :disabled="twofaBusy" @click="sendTwofaCode">Send code</button>
                <input v-model="twofaCode" inputmode="numeric" maxlength="6" placeholder="6-digit code" style="width:130px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;font-family:inherit;font-size:13.5px;background:var(--bg);color:var(--text)">
                <button class="btn-primary" :disabled="twofaBusy" @click="enableEmail2fa">Enable 2FA</button>
              </div>
            </div>

            <div v-if="twofaStep === 'totp-code' && twofaSetup" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px">
              <div style="font-size:13px;margin-bottom:8px">1. Add the key to your authenticator app (Google Authenticator, Authy, 1Password…):</div>
              <div style="font-size:12.5px;font-weight:800;font-family:monospace;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:6px;word-break:break-all">{{ twofaSetup.secret }}</div>
              <div style="font-size:11.5px;color:var(--text-mute);margin-bottom:10px;word-break:break-all">or scan-free manual entry with the URI:<br><span style="font-family:monospace">{{ twofaSetup.uri }}</span></div>
              <div style="font-size:13px;margin-bottom:10px">2. Enter the 6-digit code the app shows and confirm:</div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <input v-model="twofaCode" inputmode="numeric" maxlength="6" placeholder="6-digit code" style="width:130px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;font-family:inherit;font-size:13.5px;background:var(--bg);color:var(--text)">
                <button class="btn-primary" :disabled="twofaBusy" @click="enableTotp2fa">Enable 2FA</button>
              </div>
            </div>
          </template>

          <template v-else>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
              <button class="btn-ghost" :disabled="twofaBusy" @click="sendTwofaCode">📧 Send test code</button>
              <button class="btn-ghost" style="color:var(--danger)" :disabled="twofaBusy" @click="twofaStep = 'disable'">🚫 Disable 2FA</button>
            </div>
            <div v-if="twofaStep === 'disable'" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:14px">
              <div style="font-size:13px;margin-bottom:10px">Send a verification code to your email, then enter it with your password to confirm.</div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
                <button class="btn-ghost" :disabled="twofaBusy" @click="sendTwofaCode">Send code</button>
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <input v-model="twofaCode" inputmode="numeric" maxlength="6" placeholder="6-digit code" style="width:130px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;font-family:inherit;font-size:13.5px;background:var(--bg);color:var(--text)">
                <input v-model="twofaPw" type="password" placeholder="Password" style="width:160px;padding:9px 12px;border:1px solid var(--border);border-radius:9px;font-family:inherit;font-size:13.5px;background:var(--bg);color:var(--text)">
                <button class="btn-primary" style="background:var(--danger)" :disabled="twofaBusy" @click="disable2fa">Disable 2FA</button>
              </div>
            </div>
            <div class="c-sub" style="font-size:12px">Lost your authenticator app? On the login screen choose <b>“Use email code instead”</b> — we'll email you a one-time code.</div>
          </template>
        </div>
      </div>

      <!-- Audit log (superadmin) -->
      <div v-if="isSuperAdmin" class="panel" style="margin-top:18px">
        <div class="panel-h"><div class="t"><span class="pi">🧾</span>Audit log <span class="badge b-blue" style="margin-left:8px">Superadmin</span></div>
          <button class="btn-ghost" style="font-size:12px" :disabled="auditLoading" @click="loadAudit">{{ auditLoading ? 'Loading…' : '↻ Refresh' }}</button>
        </div>
        <div class="panel-b">
          <div class="c-sub" style="font-size:12.5px;margin-bottom:12px">Latest 50 platform events — who did what, when (logins, security changes, exports, reminders).</div>
          <div class="tbl-wrap" style="max-height:none">
            <table class="kr" style="width:100%;font-size:12px">
              <thead>
                <tr><th style="text-align:left">When</th><th style="text-align:left">User</th><th style="text-align:left">Action</th><th style="text-align:left">Module</th><th style="text-align:left">Entity</th></tr>
              </thead>
              <tbody>
                <tr v-for="(a, i) in auditEntries" :key="i">
                  <td style="white-space:nowrap">{{ fmtAuditTs(a.ts) }}</td>
                  <td style="font-weight:700">{{ a.user }}</td>
                  <td>{{ a.action }}</td>
                  <td><span class="badge b-gray">{{ a.module }}</span></td>
                  <td class="c-sub" style="font-size:11px">{{ a.entity }}</td>
                </tr>
                <tr v-if="!auditEntries.length"><td colspan="5" style="text-align:center;color:var(--text-mute);padding:22px 0">No audit entries loaded.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Backup & export (superadmin) -->
      <div v-if="isSuperAdmin" class="panel" style="margin-top:18px">
        <div class="panel-h"><div class="t"><span class="pi">💾</span>Backup &amp; export <span class="badge b-blue" style="margin-left:8px">Superadmin</span></div></div>
        <div class="panel-b">
          <div class="c-sub" style="font-size:12.5px;margin-bottom:14px">Download a consistent SQLite snapshot (VACUUM INTO) or a full JSON dump of every table — for archives, migration and disaster recovery.</div>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button class="btn-primary" :disabled="!!backupBusy" @click="downloadBackup" style="font-size:13px">{{ backupBusy === 'db' ? 'Downloading…' : '⬇️ Download DB snapshot' }}</button>
            <button class="btn-ghost" :disabled="!!backupBusy" @click="downloadExport" style="font-size:13px">{{ backupBusy === 'json' ? 'Exporting…' : '⬇️ Full JSON export' }}</button>
          </div>
        </div>
      </div>

      <!-- Sign-in & sessions (V2.17) -->
      <div class="panel" style="margin-top:18px">
        <div class="panel-h"><div class="t"><span class="pi">🖥️</span>Sign-in &amp; sessions</div></div>
        <div class="panel-b">
          <div class="c-sub" style="font-size:12.5px;margin-bottom:12px">Devices currently signed in to your account. Sign out anything you don't recognise — the session is killed instantly, even if that device is offline.</div>

          <div v-if="sessionsLoading" style="color:var(--text-mute);font-size:13px">Loading sessions…</div>
          <template v-else>
            <table class="kr" style="width:100%;font-size:12.5px">
              <thead>
                <tr><th style="text-align:left">Device</th><th style="text-align:left">IP</th><th style="text-align:left">Last active</th><th></th></tr>
              </thead>
              <tbody>
                <tr v-for="s in sessions" :key="s.id">
                  <td>
                    <div style="font-weight:700">{{ s.device }}</div>
                    <div style="color:var(--text-mute);font-size:11px">{{ s.impersonator ? 'Viewing as ' + s.impersonator + ' · ' : '' }}{{ fmtDate(s.created_at) }}</div>
                  </td>
                  <td>{{ s.ip }}</td>
                  <td>
                    <span v-if="s.current" class="badge b-green">This device</span>
                    <template v-else>{{ fmtAgo(s.last_seen) }}</template>
                  </td>
                  <td style="text-align:right">
                    <button v-if="!s.current" class="btn-ghost" style="color:var(--danger);font-size:11.5px;padding:5px 10px" :disabled="sessionsBusy" @click="revokeSession(s.id)">Sign out</button>
                  </td>
                </tr>
                <tr v-if="!sessions.length"><td colspan="4" style="color:var(--text-mute);padding:14px 0">No active sessions found.</td></tr>
              </tbody>
            </table>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
              <button class="btn-ghost" :disabled="sessionsBusy" @click="confirmRevoke = confirmRevoke === 'others' ? '' : 'others'">📴 Sign out all other devices</button>
              <button class="btn-ghost" style="color:var(--danger)" :disabled="sessionsBusy" @click="confirmRevoke = confirmRevoke === 'all' ? '' : 'all'">🚪 Sign out everywhere</button>
            </div>
            <div v-if="confirmRevoke === 'others'" style="display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap">
              <span style="font-size:12.5px;color:var(--text-mute)">You'll stay signed in here.</span>
              <button class="btn-primary" style="background:var(--danger)" :disabled="sessionsBusy" @click="revokeOthers">Yes, sign out other devices</button>
              <button class="btn-ghost" @click="confirmRevoke = ''">Cancel</button>
            </div>
            <div v-if="confirmRevoke === 'all'" style="display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap">
              <span style="font-size:12.5px;color:var(--text-mute)">This signs out every device, including this one.</span>
              <button class="btn-primary" style="background:var(--danger)" :disabled="sessionsBusy" @click="revokeAll">Yes, sign out everywhere</button>
              <button class="btn-ghost" @click="confirmRevoke = ''">Cancel</button>
            </div>

            <div style="border-top:1px solid var(--border);margin-top:16px;padding-top:14px">
              <div style="font-weight:800;font-size:13px;margin-bottom:8px">Recent sign-ins</div>
              <div v-if="!loginHistory.length" style="color:var(--text-mute);font-size:12.5px">No recent sign-ins recorded.</div>
              <div v-for="h in loginHistory" :key="h.ts + h.ip" style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;padding:4px 0;border-bottom:1px dashed var(--border)">
                <span style="color:var(--text-mute)">{{ h.ip }}</span>
                <span>{{ fmtAgo(h.ts) }}</span>
              </div>
            </div>

            <div v-if="isSuperAdmin" style="border-top:1px solid var(--border);margin-top:16px;padding-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
              <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;cursor:pointer">
                <input type="checkbox" v-model="secAlerts" @change="saveSecAlerts"> Email me when someone signs in from a new device
              </label>
              <span class="c-sub" style="font-size:11.5px">Applies to all accounts in this workspace.</span>
            </div>
          </template>
        </div>
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
