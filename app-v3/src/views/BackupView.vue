<script setup>
// V2.31.5 — Workspace Backup & Restore console.
// Owner/manager scoped: snapshot the subscriber's own rows (sub_email chain),
// list history, restore (upsert, ownership re-validated server-side), delete.
import { ref, onMounted, computed } from 'vue'
import { useAuthStore } from '../stores/auth'
import { apiCall } from '../api/client'
import { lang, t } from '../lib/i18n'

const auth = useAuthStore()

const backups = ref([])
const loading = ref(false)
const err = ref('')
const toast = ref('')
const note = ref('')
const creating = ref(false)
const restoringId = ref('')
const uploading = ref(false)
const fileInput = ref(null)

const canManage = computed(() => ['owner', 'property_owner', 'manager', 'superadmin'].includes(auth.user?.role))

function showToast(msg, ok = true) {
  toast.value = (ok ? '✅ ' : '❌ ') + msg
  setTimeout(() => toast.value = '', 4000)
}

async function load() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-ws-backup', { action: 'list' })
    if (r.ok) backups.value = r.backups || []
    else err.value = r.error || t('Failed to load backups.')
  } catch (e) { err.value = t('Network error.')}
  loading.value = false
}

async function createBackup() {
  creating.value = true; err.value = ''
  try {
    const r = await apiCall('app-ws-backup', { action: 'create', note: note.value })
    if (r.ok) {
      showToast(`Backup ${r.id} created — ${(r.size / 1024).toFixed(1)} KB, ${r.tables ? Object.entries(r.tables).map(([k, v]) => `${k}: ${v}`).join(', ') : ''}`)
      note.value = ''
      await load()
    } else err.value = r.error || 'Backup failed.'
  } catch (e) { err.value = t('Network error.')}
  creating.value = false
}

async function downloadBackup(b) {
  try {
    const r = await apiCall('app-ws-backup', { action: 'get', id: b.id })
    if (!r.ok) { err.value = r.error || t('Download failed.'); return }
    const blob = new Blob([JSON.stringify(r.data, null, 2)], { type: 'application/json' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `krtaker-backup-${b.id}-${(b.ts || '').slice(0, 10)}.json`
    a.click()
    URL.revokeObjectURL(a.href)
    showToast(`Downloaded ${b.id}`)
  } catch (e) { err.value = t('Network error.')}
}

async function restoreBackup(b) {
  if (!confirm(`Restore backup ${b.id}? Existing rows with the same IDs will be overwritten. Rows belonging to other accounts are never touched.`)) return
  restoringId.value = b.id; err.value = ''
  try {
    const r = await apiCall('app-ws-backup', { action: 'restore', id: b.id })
    if (r.ok) {
      const parts = Object.entries(r.restored || {}).map(([k, v]) => `${k}: ${v}`).join(', ')
      showToast(`Restored — ${parts}`)
    } else err.value = r.error || 'Restore failed.'
  } catch (e) { err.value = t('Network error.')}
  restoringId.value = ''
}

async function deleteBackup(b) {
  if (!confirm(`Delete backup ${b.id}? This removes the stored snapshot.`)) return
  try {
    const r = await apiCall('app-ws-backup', { action: 'delete', id: b.id })
    if (r.ok) { showToast(`Deleted ${b.id}`); await load() }
    else err.value = r.error || t('Delete failed.')
  } catch (e) { err.value = t('Network error.')}
}

function onFilePicked(ev) {
  const f = ev.target.files && ev.target.files[0]
  if (!f) return
  const rd = new FileReader()
  rd.onload = async () => {
    uploading.value = true; err.value = ''
    try {
      const data = JSON.parse(rd.result)
      const r = await apiCall('app-ws-backup', { action: 'restore', data })
      if (r.ok) {
        const parts = Object.entries(r.restored || {}).map(([k, v]) => `${k}: ${v}`).join(', ')
        showToast(`Uploaded restore — ${parts}`)
        await load()
      } else err.value = r.error || 'Restore failed.'
    } catch (e) { err.value = t('Invalid backup file: ') + (e.message || e) }
    uploading.value = false
    ev.target.value = ''
  }
  rd.readAsText(f)
}

function fmtSize(b) {
  const s = Number(b.size) || 0
  return s > 1048576 ? (s / 1048576).toFixed(2) + ' MB' : s > 1024 ? (s / 1024).toFixed(1) + ' KB' : s + ' B'
}

function fmtTs(ts) {
  if (!ts) return ''
  const d = new Date(String(ts).replace(' ', 'T'))
  return isNaN(d) ? String(ts).slice(0, 16) : d.toLocaleString()
}

onMounted(load)
</script>

<template>
  <div class="page">
    <h1>💾 {{ lang === 'bn' ? 'ব্যাকআপ ও রিস্টোর' : 'Backup &amp; Restore' }}</h1>
    <p class="c-sub" style="max-width:720px">{{ lang === 'bn'
      ? 'আপনার ওয়ার্কস্পেসের ডেটার JSON স্ন্যাপশট তৈরি, ডাউনলোড ও পুনরুদ্ধার করুন — শুধুমাত্র আপনার নিজের রেকর্ড।'
      : 'Create, download and restore JSON snapshots of your workspace data — your own records only.' }}</p>

    <div v-if="toast" class="toast-inline">{{ toast }}</div>
    <div v-if="err" class="err-box">⚠️ {{ err }}</div>

    <div class="card" style="margin-top:18px">
      <h3>🆕 {{ t('Create a backup') }}</h3>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px">
        <input v-model="note" :placeholder="t('Note (optional)')"
               style="flex:1;min-width:200px" />
        <button class="btn-primary" :disabled="creating" @click="createBackup">
          {{ creating ? '⏳ …' : '📦 ' + (lang === 'bn' ? 'ব্যাকআপ নিন' : 'Create backup') }}
        </button>
        <label class="btn-secondary" style="cursor:pointer">
          ⬆️ {{ lang === 'bn' ? 'ফাইল থেকে রিস্টোর' : 'Restore from file' }}
          <input ref="fileInput" type="file" accept=".json,application/json" style="display:none" @change="onFilePicked" />
        </label>
      </div>
    </div>

    <div class="card" style="margin-top:18px">
      <h3>🗂️ {{ t('Backup history') }}</h3>
      <p v-if="loading" class="c-sub">Loading…</p>
      <div v-else-if="!backups.length" class="empty-state" style="padding:26px 0;text-align:center;color:var(--text-mute)">
        {{ lang === 'bn' ? 'এখনো কোনো ব্যাকআপ নেই।' : 'No backups yet — create your first one above.' }}
      </div>
      <div v-else class="tbl-wrap">
        <table class="kr-table">
          <thead>
            <tr>
              <th>ID</th><th>{{ t('Date') }}</th><th>{{ t('Size') }}</th>
              <th>{{ t('Note') }}</th><th>{{ lang === 'bn' ? 'দ্বারা' : 'By' }}</th><th style="width:210px">{{ t('Actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="b in backups" :key="b.id">
              <td><b>{{ b.id }}</b></td>
              <td>{{ fmtTs(b.ts) }}</td>
              <td>{{ fmtSize(b) }}</td>
              <td>{{ b.note || '—' }}</td>
              <td>{{ b.created_by || '—' }}</td>
              <td>
                <button class="btn-mini" @click="downloadBackup(b)">⬇</button>
                <button class="btn-mini" :disabled="restoringId === b.id" @click="restoreBackup(b)">↩</button>
                <button class="btn-mini danger" @click="deleteBackup(b)">✕</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="c-sub" style="margin-top:10px;font-size:12px">{{ lang === 'bn'
        ? '⬇ ডাউনলোড · ↩ রিস্টোর (একই ID-এর সারি ওভাররাইট হবে) · ✕ মুছুন'
        : '⬇ download · ↩ restore (overwrites rows with the same IDs) · ✕ delete' }}</p>
    </div>
  </div>
</template>

<style scoped>
.card { background: var(--card, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 14px; padding: 16px; }
.card h3 { margin: 0 0 4px; font-size: 15px; }
.c-sub { color: var(--text-mute, #6b7280); font-size: 13px; }
.toast-inline { background: var(--success-bg, #e6f7e6); color: var(--success, #1a7f37); border-radius: 10px; padding: 10px 14px; margin-top: 12px; font-size: 13px; }
.err-box { background: var(--danger-bg, #fdecea); color: var(--danger, #c0392b); border-radius: 10px; padding: 10px 14px; margin-top: 12px; font-size: 13px; }
.btn-primary { background: var(--primary, #2563eb); color: #fff; border: 0; border-radius: 10px; padding: 9px 16px; font-weight: 700; cursor: pointer; }
.btn-primary:disabled { opacity: .5; cursor: default; }
.btn-secondary { background: var(--bg-alt, #f3f4f6); color: var(--text, #111827); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 9px 16px; font-weight: 600; font-size: 13px; }
.btn-mini { background: var(--bg-alt, #f3f4f6); border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 4px 9px; margin-right: 4px; cursor: pointer; font-size: 13px; }
.btn-mini.danger { color: var(--danger, #c0392b); }
.btn-mini:disabled { opacity: .5; cursor: default; }
input { border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 9px 12px; font-size: 13px; background: var(--card, #fff); color: var(--text, #111827); }
.kr-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.kr-table th, .kr-table td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--border, #e5e7eb); }
.kr-table th { font-size: 11.5px; text-transform: uppercase; letter-spacing: .4px; color: var(--text-mute, #6b7280); }
</style>
