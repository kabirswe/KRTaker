<script setup>
import { computed, ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useDataStore } from '../stores/data'
import { lang, setLang } from '../lib/i18n'
import { apiCall } from '../api/client'
import { track } from '../lib/analytics'
import { ROLES, roleLabel, GROUP_LABEL } from '../lib/roles'
import { globalSearch, searchTarget, SEARCH_HINT } from '../lib/globalsearch'
import { notifTarget } from '../lib/ui'

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
  const next = lang.value === 'bn' ? 'en' : 'bn'
  setLang(next)
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
  if (!e.target.closest('.tb-bell') && !e.target.closest('.bell-menu')) bellOpen.value = false
  if (!e.target.closest('.tb-search') && !e.target.closest('.search-menu')) searchOpen.value = false
}

// ── Notification bell (app-kr-alert) ──
const bellOpen = ref(false)
const alerts = ref([])
const unread = ref(0)
const bellBusy = ref(false)
async function loadAlerts() {
  try {
    const r = await apiCall('app-kr-alert', { action: 'list' })
    if (r.ok) { alerts.value = r.alerts || []; unread.value = r.unread || 0 }
  } catch (e) { /* silent */ }
}
function toggleBell() {
  bellOpen.value = !bellOpen.value
  if (bellOpen.value) loadAlerts()
}
async function dismissAlert(id) {
  bellBusy.value = true
  try {
    await apiCall('app-kr-alert', { action: 'dismiss', id })
    await loadAlerts()
  } finally { bellBusy.value = false }
}
async function dismissAllAlerts() {
  bellBusy.value = true
  try {
    await apiCall('app-kr-alert', { action: 'dismiss-all' })
    await loadAlerts()
  } finally { bellBusy.value = false }
}
async function readAllAlerts() {
  if (!unread.value || bellBusy.value) return
  bellBusy.value = true
  try {
    await apiCall('app-kr-alert', { action: 'read-all' })
    await loadAlerts()
  } finally { bellBusy.value = false }
}
function openAlert(a) {
  if (!a.read_at) apiCall('app-kr-alert', { action: 'read', id: a.id }).catch(() => {})
  bellOpen.value = false
  const t = notifTarget(a)
  track('notification_opened', { type: a.type || '', severity: a.severity || '' })
  router.push({ path: t.path, query: t.query })
}
const sevIco = (s) => s === 'critical' ? '🚨' : (s === 'warning' ? '⚠️' : (s === 'success' ? '✅' : '🔔'))
const timeAgoBell = (ts) => {
  if (!ts) return ''
  const d = new Date(String(ts).replace(' ', 'T'))
  if (isNaN(d)) return String(ts).slice(0, 10)
  const s = (Date.now() - d.getTime()) / 1000
  if (s < 3600) return Math.max(1, Math.floor(s / 60)) + 'm'
  if (s < 86400) return Math.floor(s / 3600) + 'h'
  if (s < 604800) return Math.floor(s / 86400) + 'd'
  return String(ts).slice(0, 10)
}
// keep the badge fresh when navigating (alerts may change on other pages)
watch(() => router.currentRoute.value.fullPath, () => { loadAlerts() })

// ── Global search (client-side index over data.db) ──
const searchQ = ref('')
const searchOpen = ref(false)
const searchBusy = ref(false)
const results = ref([])
const searchFocus = ref(0)   // keyboard navigation index
let searchTimer = null

function runSearch() {
  clearTimeout(searchTimer)
  const q = searchQ.value
  if (q.trim().length < 2) { results.value = []; searchFocus.value = 0; return }
  searchBusy.value = true
  searchTimer = setTimeout(() => {
    results.value = globalSearch(data.db, q)
    searchFocus.value = 0
    searchBusy.value = false
  }, 180)
}
function openSearch() { searchOpen.value = true; searchFocus.value = 0 }
function closeSearch() { searchOpen.value = false; searchQ.value = ''; results.value = [] }
function flatItems() { return results.value.flatMap((g, gi) => g.items.map((it, ii) => ({ gi, ii }))) }
function goSearch(item) {
  const grp = results.value[item.gi]
  const target = searchTarget(grp, grp.items[item.ii])
  closeSearch()
  router.push(target)
}
function onSearchKey(e) {
  const items = flatItems()
  if (e.key === 'ArrowDown' && items.length) { e.preventDefault(); searchFocus.value = (searchFocus.value + 1) % items.length }
  else if (e.key === 'ArrowUp' && items.length) { e.preventDefault(); searchFocus.value = (searchFocus.value - 1 + items.length) % items.length }
  else if (e.key === 'Enter' && items.length) { e.preventDefault(); goSearch(items[searchFocus.value]) }
  else if (e.key === 'Escape') closeSearch()
}
watch(searchOpen, (o) => { if (!o) { clearTimeout(searchTimer); searchQ.value = ''; results.value = [] } })

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
        <!-- Global search -->
        <div class="tb-search" style="position:relative;flex:1;max-width:420px;min-width:140px">
          <div class="gs-box" @click="openSearch">
            <span class="gs-ic">🔍</span>
            <input v-model="searchQ" @input="runSearch" @focus="openSearch" @keydown="onSearchKey"
                   :placeholder="SEARCH_HINT" class="gs-input" aria-label="Global search" />
            <button v-if="searchQ" class="gs-clear" @click.stop="searchQ = ''; runSearch()">✕</button>
          </div>
          <!-- dropdown -->
          <div v-if="searchOpen" class="search-menu" style="position:absolute;top:calc(100% + 8px);left:0;right:0;background:var(--card,#fff);border:1px solid var(--border,#e5e7eb);border-radius:14px;box-shadow:0 18px 50px rgba(0,0,0,.16);z-index:95;overflow:hidden">
            <template v-if="searchQ.trim().length >= 2">
              <div v-if="searchBusy" style="padding:20px;text-align:center;color:var(--text-mute);font-size:13px">Searching…</div>
              <div v-else-if="!results.length" style="padding:26px 16px;text-align:center;color:var(--text-mute);font-size:13px">No matches for <b>{{ searchQ }}</b></div>
              <div v-else style="max-height:min(480px,62vh);overflow-y:auto;padding:6px 0">
                <template v-for="(g, gi) in results" :key="g.group">
                  <div class="gs-group" style="padding:8px 14px 4px;font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--text-mute)">{{ g.ic }} {{ g.group }} <span style="font-weight:600;opacity:.7">· {{ g.items.length }}</span></div>
                  <div v-for="(it, ii) in g.items" :key="g.group + '-' + it.id"
                       class="gs-item" :class="{ active: searchFocus === flatItems().findIndex(x => x.gi === gi && x.ii === ii) }"
                       @mousedown.prevent="goSearch({ gi, ii })"
                       @mouseenter="searchFocus = flatItems().findIndex(x => x.gi === gi && x.ii === ii)"
                       style="display:flex;gap:10px;align-items:center;padding:8px 14px;cursor:pointer">
                    <div style="font-size:15px;flex-shrink:0;width:22px;text-align:center">{{ g.ic }}</div>
                    <div style="flex:1;min-width:0">
                      <div style="font-weight:700;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ it.title }}</div>
                      <div class="c-sub" style="font-size:11.5px;color:var(--text-mute);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ it.sub }}</div>
                    </div>
                    <span class="gs-go" style="color:var(--text-mute);font-size:11px;flex-shrink:0">↗</span>
                  </div>
                </template>
              </div>
            </template>
            <div v-else style="padding:20px 16px;text-align:center;color:var(--text-mute);font-size:12.5px">Type at least 2 characters to search across tenants, units, invoices, maintenance, notices and more.</div>
          </div>
        </div>
        <div class="tb-actions">
          <button class="icon-btn" @click="toggleLang()">বাংলা</button>
          <button class="icon-btn" @click="toggleTheme()">{{ theme === 'dark' ? '☀️' : '🌙' }}<span class="tb-theme-txt">{{ theme === 'dark' ? ' Light' : ' Dark' }}</span></button>
          <div style="position:relative;display:inline-block" class="tb-bell">
            <button class="icon-btn" @click.stop="toggleBell()" title="Notifications" style="position:relative">🔔<span v-if="unread" style="position:absolute;top:-4px;right:-4px;min-width:17px;height:17px;border-radius:999px;background:var(--danger,#e74c3c);color:#fff;font-size:10.5px;font-weight:800;display:flex;align-items:center;justify-content:center;padding:0 4px">{{ unread > 99 ? '99+' : unread }}</span></button>
            <!-- bell dropdown -->
            <div v-if="bellOpen" class="bell-menu" style="position:absolute;top:calc(100% + 10px);right:0;width:min(360px,86vw);background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 18px 50px rgba(0,0,0,.16);z-index:90;overflow:hidden">
              <div style="display:flex;justify-content:space-between;align-items:center;padding:13px 16px;border-bottom:1px solid var(--border)">
                <div style="font-weight:800;font-size:14px">🔔 Notifications <span v-if="unread" style="color:var(--danger);font-size:12px">· {{ unread }} new</span></div>
                <div style="display:flex;gap:8px;align-items:center">
                  <button v-if="unread" @click="readAllAlerts" :disabled="bellBusy" style="border:none;background:transparent;color:var(--primary);font-weight:800;font-size:11.5px;cursor:pointer">✓ Read all</button>
                  <button v-if="alerts.length" @click="dismissAllAlerts" :disabled="bellBusy" style="border:none;background:transparent;color:var(--text-mute);font-weight:800;font-size:11.5px;cursor:pointer">Clear all</button>
                </div>
              </div>
              <div style="max-height:min(420px,60vh);overflow-y:auto">
                <div v-if="!alerts.length" style="padding:34px 16px;text-align:center;color:var(--text-mute);font-size:13px">No open notifications 🎉</div>
                <div v-for="a in alerts" :key="a.id" @click="openAlert(a)" style="display:flex;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border);cursor:pointer;background:transparent" :style="!a.read_at ? 'background:var(--bg-alt)' : 'opacity:.6'">
                  <div style="font-size:17px;flex-shrink:0">{{ sevIco(a.severity) }}</div>
                  <div style="flex:1;min-width:0">
                    <div style="font-weight:800;font-size:13px">{{ a.title }}</div>
                    <div v-if="a.body" class="c-sub" style="font-size:12px;margin-top:2px;line-height:1.5;white-space:pre-wrap">{{ a.body }}</div>
                    <div style="display:flex;gap:8px;align-items:center;margin-top:5px">
                      <span v-if="a.ref" class="badge b-gray" style="font-size:10.5px">{{ a.ref }}</span>
                      <span class="c-sub" style="font-size:11px">{{ timeAgoBell(a.ts) }}</span>
                    </div>
                  </div>
                  <button @click.stop="dismissAlert(a.id)" :disabled="bellBusy" title="Dismiss" style="border:none;background:transparent;color:var(--text-mute);font-size:14px;font-weight:800;cursor:pointer;flex-shrink:0">✕</button>
                </div>
              </div>
              <div style="padding:10px 16px;border-top:1px solid var(--border);text-align:center">
                <button @click="bellOpen = false; router.push('/notifications')" style="border:none;background:transparent;color:var(--primary);font-weight:800;font-size:12.5px;cursor:pointer">View all notifications →</button>
              </div>
            </div>
          </div>
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
