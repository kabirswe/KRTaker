<script setup>
import { ref, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'

const auth = useAuthStore()

const name = ref('')
const dept = ref('')
const avatar = ref('')
const oldPw = ref('')
const newPw = ref('')
const prefs = ref({})
const saved = ref('')
const err = ref('')

onMounted(async () => {
  const u = auth.user || {}
  name.value = u.name || ''
  dept.value = u.dept || ''
  avatar.value = u.avatar || ''
  const r = await apiCall('app-settings-get')
  if (r.ok) prefs.value = r.settings || {}
})

async function saveProfile() {
  err.value = ''; saved.value = ''
  const body = { name: name.value }
  if (auth.user?.is_staff) { body.dept = dept.value; body.avatar = avatar.value }
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
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">👤</span>Profile</div></div>
        <div class="panel-b">
          <div class="form-field" style="margin-bottom:12px">
            <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">Name</label>
            <input v-model="name" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-family:inherit;font-size:13.5px;background:var(--bg);color:var(--text)">
          </div>
          <div v-if="auth.user?.is_staff" class="form-field" style="margin-bottom:12px">
            <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">Department</label>
            <input v-model="dept" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-family:inherit;font-size:13.5px;background:var(--bg);color:var(--text)">
          </div>
          <button class="btn-primary" style="width:100%" @click="saveProfile">Save profile</button>
        </div>
      </div>

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
