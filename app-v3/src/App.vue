<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useAuthStore } from './stores/auth'
import { useDataStore } from './stores/data'
import Sidebar from './components/Sidebar.vue'
import Topbar from './components/Topbar.vue'

const auth = useAuthStore()
const data = useDataStore()

const sidebarOpen = ref(false)
const toasts = ref([])
let toastId = 0

function toast(msg, type = 'info') {
  const id = ++toastId
  toasts.value.push({ id, msg, type })
  setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id) }, 2600)
}

// Expose toast globally (components use it via inject/emit; simple window hook for now)
window.__krToast = toast

function toggleSidebar() { sidebarOpen.value = !sidebarOpen.value }
function closeSidebar() { sidebarOpen.value = false }

onMounted(() => { document.addEventListener('click', closeSidebarOnOutside) })
onBeforeUnmount(() => { document.removeEventListener('click', closeSidebarOnOutside) })
function closeSidebarOnOutside(e) {
  if (!e.target.closest('.sidebar') && !e.target.closest('.menu-toggle')) closeSidebar()
}

// Splash safety net: if a persisted token is still unvalidated after 6s, give up
// on it and show the login form — a hung /app-me must never trap the user on a
// spinner (or worse, the old empty-shell bug where the form never appeared).
let splashTimer = null
onMounted(() => {
  if (auth.isAuthed && !auth.validated) {
    splashTimer = setTimeout(() => {
      if (auth.isAuthed && !auth.validated) {
        auth.clear()
        if (location.hash && location.hash !== '#/login') location.hash = '#/login'
      }
    }, 6000)
  }
})
onBeforeUnmount(() => { if (splashTimer) clearTimeout(splashTimer) })

// Theme bootstrap
const savedTheme = (() => { try { return localStorage.getItem('krtaker_dash_theme') } catch (e) { return '' } })() || 'light'
document.documentElement.setAttribute('data-theme', savedTheme)
</script>

<template>
  <div>
    <!-- Auth gate: show login UNLESS a token exists AND has been validated.
         A stale/expired token must never render the shell — that left users
         staring at an empty sidebar with no login form (fixed: validated flag). -->
    <div v-if="!auth.isAuthed" class="auth-screen">
      <router-view />
    </div>

    <!-- Token exists but not yet validated against /app-me → brief splash
         (normally sub-second; only visible when the API is slow or the token dies) -->
    <div v-else-if="!auth.validated" class="auth-screen">
      <div style="display:flex;flex-direction:column;align-items:center;gap:14px;color:var(--text-mute)">
        <div class="spinner" style="width:34px;height:34px;border-width:4px"></div>
        <div style="font-size:13.5px;font-weight:600">Checking session…</div>
      </div>
    </div>

    <!-- App shell -->
    <div v-else class="app">
      <div class="sb-backdrop" :class="{ show: sidebarOpen }" @click="closeSidebar"></div>
      <Sidebar :open="sidebarOpen" @close="closeSidebar" />
      <div class="main">
        <Topbar @toggle-sidebar="toggleSidebar" />
        <div v-if="data.offline" class="offline-banner">📡 You are offline — showing last-loaded data (read-only)</div>
        <main class="content">
          <router-view v-slot="{ Component }">
            <component :is="Component" />
          </router-view>
        </main>
      </div>
    </div>

    <div class="toast-wrap">
      <div v-for="t in toasts" :key="t.id" class="toast" :class="t.type">{{ t.msg }}</div>
    </div>
  </div>
</template>
