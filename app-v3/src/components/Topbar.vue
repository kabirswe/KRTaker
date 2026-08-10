<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'

const router = useRouter()
const auth = useAuthStore()
const data = useDataStore()

const emit = defineEmits(['toggle-sidebar'])

const menuOpen = ref(false)
const theme = ref((() => { try { return localStorage.getItem('krtaker_dash_theme') } catch (e) { return '' } })() || 'light')

function toggleTheme() {
  theme.value = theme.value === 'dark' ? 'light' : 'dark'
  document.documentElement.setAttribute('data-theme', theme.value)
  try { localStorage.setItem('krtaker_dash_theme', theme.value) } catch (e) {}
}
function toggleLang() {
  // Lang switching: simple toggle for now (en/bn) — full i18n port in Phase 3
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
function setRole(r) {
  data.setPreviewRole(r.id)
  closeMenu()
  window.__krToast?.('Switched to ' + r.role)
}

const ROLES = [
  { id: 'owner', role: 'Property Owner', ico: '🏠' },
  { id: 'manager', role: 'Property Manager', ico: '🗝️' },
  { id: 'tenant', role: 'Tenant', ico: '🔑' },
  { id: 'partner', role: 'Service Partner', ico: '🛠️' },
  { id: 'svc_mgr', role: 'Service Manager', ico: '✅' },
  { id: 'legal', role: 'Legal Counsel', ico: '⚖️' },
  { id: 'crm', role: 'CRM & Help Desk', ico: '🎧' },
  { id: 'accountant', role: 'Accountant', ico: '💰' },
  { id: 'hr', role: 'HR & Admin', ico: '👥' },
]

const subs = computed(() => ROLES.filter(r => auth.canSwitchTo(r.id)))
const me = computed(() => {
  const u = data.user || auth.user || {}
  const roleId = u.role && ROLES.some(r => r.id === u.role) ? u.role : data.previewRole
  return ROLES.find(r => r.id === roleId) || ROLES[0]
})
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
            <div class="u-role">{{ me.role }}</div>
          </div>
        </div>
        <!-- tb-user dropdown: subordinate switch + profile settings -->
        <div v-if="menuOpen" class="user-menu" id="userMenu">
          <div class="um-head">
            <div class="role-ava" style="width:38px;height:38px;font-size:13px">{{ initials }}</div>
            <div>
              <div class="um-name">{{ (data.user || auth.user)?.name }}</div>
              <div class="um-role">{{ me.role }}</div>
            </div>
          </div>
          <template v-if="subs.length">
            <div class="um-label">🔀 Switch to subordinate user</div>
            <div v-for="r in subs" :key="r.id" class="um-item" :class="{ active: r.id === data.previewRole }" @click="setRole(r)">
              <span class="um-ic">{{ r.ico }}</span>
              <span class="um-t">{{ r.role }}</span>
              <span v-if="r.id === data.previewRole" class="um-cur">✓</span>
            </div>
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
</template>
