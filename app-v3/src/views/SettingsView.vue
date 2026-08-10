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
  </div>
</template>
