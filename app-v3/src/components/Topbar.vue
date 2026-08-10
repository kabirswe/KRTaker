<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import { ROLES, roleLabel, GROUP_LABEL } from '../lib/roles'

const router = useRouter()
const auth = useAuthStore()
const data = useDataStore()

const emit = defineEmits(['toggle-sidebar'])

const menuOpen = ref(false)
const switching = ref(false)
const theme = ref((() => { try { return localStorage.getItem('krtaker_dash_theme') } catch (e) { return '' } })() || 'light')

function toggleTheme() {
  theme.value = theme.value === 'dark' ? 'light' : 'dark'
  document.documentElement.setAttribute('data-theme', theme.value)
  try { localStorage.setItem('krtaker_dash_theme', theme.value) } catch (e) {}
}
function toggleLang() {
  const cur = (() => { try { return localStorage.getItem('krtaker_dash_lang') } catch (e) { return 'en' } })() || 'en'
  const next = cur === 'en' ? 'bn' : 'en'
  try { localStorage.setItem('krtaker_dash_lang', next) } catch (e) {}
  window.__krToast?.(next === 'bn' ? 'বাংলা ভাষা নির্বাচিত' : 'Language: English')
}

function toggleMenu() { menuOpen.value = !menuOpen.value }
function closeMenu() { menuOpen.value = false }
function goSettings() { closeMenu(); router.push('/settings') }
async function doLogout() {
  closeMenu()
  await auth.logout()
  data.$reset()
  router.push('/login')
}
function goProfile() {
  closeMenu()
  router.push('/settings')
}

// ── Real subordinate switch: server issues a temp token, then refetch scoped data ──
async function setRole(r) {
  if (switching.value || r.id === currentRole.value) { closeMenu(); return }
  switching.value = true
  try {
    const res = await auth.viewAs(r.email)
    if (!res.ok) {
      window.__krToast?.('❌ ' + (res.error || 'Switch failed'))
      closeMenu()
      return
    }
    await data.bootstrap()
    data.setPreviewRole(r.id)
    window.__krToast?.(auth.isImpersonating ? `👁 Viewing as ${r.role}` : 'Switched to ' + r.role)
  } catch (e) {
    window.__krToast?.('❌ Switch failed — try again')
  } finally {
    switching.value = false
    closeMenu()
  }
}

async function backToMe() {
  switching.value = true
  try {
    await auth.backToMe()
    await data.bootstrap()
    data.setPreviewRole(auth.user?.role || 'owner')
    window.__krToast?.('Back to ' + roleLabel(auth.user?.role))
  } finally {
    switching.value = false
  }
}

// Subordinates strictly below the CURRENT effective rank (updates after a switch)
const subs = computed(() => ROLES.filter(r => auth.canSwitchTo(r.id)))
// Group the subordinate list (admin sees multiple groups; others see one)
const subGroups = computed(() => {
  const map = {}
  subs.value.forEach(r => { (map[r.group] = map[r.group] || []).push(r) })
  return Object.entries(map)
})
const currentRole = computed(() => {
  const u = data.user || auth.user || {}
  const roleId = u.role
  return ROLES.some(r => r.id === roleId) ? roleId : (data.previewRole || 'owner')
})
const me = computed(() => ROLES.find(r => r.id === currentRole.value) || ROLES.find(r => r.id === 'owner'))
const initials = computed(() => {
  const n = (data.user || auth.user)?.name || ''
  return n.replace(/[^A-Za-z]/g, '').slice(0, 2).toUpperCase()
})

function onDocClick(e) {
  if (!e.target.closest('.tb-user') && !e.target.closest('.user-menu')) menuOpen.value = false
}
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>

<template>
  <div>
    <!-- Impersonation banner: viewing-as a subordinate -->
    <div v-if="auth.isImpersonating" class="imp-banner">
      <span>👁 Viewing as <b>{{ (data.user || auth.user)?.name }}</b> (<b>{{ roleLabel(auth.user?.role) }}</b>) — started by {{ auth.impersonator }} · expires {{ auth.impExpires?.replace('T', ' ').slice(0, 16) }}</span>
      <button class="imp-back" :disabled="switching" @click="backToMe()">↩ Back to my account</button>
    </div>

    <header class="topbar">
      <div class="topbar-in">
        <button class="menu-toggle" @click="emit('toggle-sidebar')">☰</button>
        <div class="tb-actions">
          <button class="icon-btn" @click="toggleLang()">বাংলা</button>
          <button class="icon-btn" @click="toggleTheme()">{{ theme === 'dark' ? '☀️ Light' : '🌙 Dark' }}</button>
          <button class="icon-btn" @click="goSettings()" title="Settings">⚙️</button>
          <div class="tb-user" id="tbUserChip" @click.stop="toggleMenu()">
            <div class="role-ava" style="width:34px;height:34px;font-size:12px">{{ initials }}</div>
            <div>
              <div class="u-name">{{ (data.user || auth.user)?.name }}</div>
              <div class="u-role">{{ me.role }}<span v-if="auth.isImpersonating" style="color:var(--warn)"> · 👁</span></div>
            </div>
          </div>
          <!-- tb-user dropdown: subordinate switch + profile settings -->
          <div v-if="menuOpen" class="user-menu" id="userMenu">
            <div class="um-head">
              <div class="role-ava" style="width:38px;height:38px;font-size:13px">{{ initials }}</div>
              <div>
                <div class="um-name">{{ (data.user || auth.user)?.name }}</div>
                <div class="um-role">{{ me.role }}<span v-if="auth.isImpersonating" style="color:var(--warn)"> · 👁</span></div>
              </div>
            </div>
            <template v-if="auth.isImpersonating">
              <div class="um-item" style="font-weight:700;color:var(--primary)" @click="backToMe()">
                <span class="um-ic">↩</span>
                <span class="um-t">Back to my account</span>
              </div>
              <div class="um-div"></div>
            </template>
            <template v-if="subs.length">
              <div class="um-label">🔀 Switch to subordinate user</div>
              <template v-for="([g, items], gi) in subGroups" :key="g">
                <div v-if="subGroups.length > 1" class="um-label" style="text-transform:uppercase;font-size:10px;letter-spacing:.6px;color:var(--text-mute);padding-top:2px">{{ GROUP_LABEL[g] || g }}</div>
                <div v-for="r in items" :key="r.id" class="um-item" :class="{ active: r.id === currentRole }" @click="setRole(r)">
                  <span class="um-ic">{{ r.ico }}</span>
                  <span class="um-t">{{ r.role }}</span>
                  <span v-if="r.id === currentRole" class="um-cur">✓</span>
                </div>
              </template>
            </template>
            <div v-else class="um-label" style="color:var(--text-mute);text-transform:none;letter-spacing:0;font-weight:600">No subordinate users</div>
            <div class="um-div"></div>
            <div class="um-item" @click="goProfile()">
              <span class="um-ic">⚙️</span>
              <div>
                <div class="um-t">Profile &amp; settings</div>
                <div class="um-s">Profile, preferences, security, billing</div>
              </div>
            </div>
            <div class="um-item" @click="doLogout()">
              <span class="um-ic">⎋</span>
              <div>
                <div class="um-t">Log out</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </header>
  </div>
</template>
