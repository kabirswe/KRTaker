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

// Theme bootstrap
const savedTheme = (() => { try { return localStorage.getItem('krtaker_dash_theme') } catch (e) { return '' } })() || 'light'
document.documentElement.setAttribute('data-theme', savedTheme)
</script>

<template>
  <div>
    <!-- Auth gate -->
    <div v-if="!auth.isAuthed" class="auth-screen">
      <router-view />
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
