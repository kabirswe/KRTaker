<script setup>
import { ref, computed, onMounted } from 'vue'
import { apiCall } from '../api/client'

const tab = ref('docs')
const loading = ref(false)
const err = ref('')
const toast = ref('')

// ── doc templates (app-tpl-list / get / save / dup / delete / reset) ──
const tpls = ref([])          // [{id,kind,name,title,is_default,updated_by,updated_at}]
const emails = ref([])        // [{id,name,subject,updated_by,updated_at}]
const palettes = ref({})
const kindFilter = ref('')

async function loadAll() {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-tpl-list')
    if (!r.ok) { err.value = r.error || 'Failed to load templates.'; return }
    tpls.value = r.templates || []
    emails.value = r.email || []
    palettes.value = r.palettes || {}
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}

const KIND = { lease: '📄 Lease', service: '🧰 Service', receipt: '📎 Receipt' }
const filtered = computed(() => (kindFilter.value ? tpls.value.filter(t => t.kind === kindFilter.value) : tpls.value))

// editor modal (doc)
const edit = ref(null)        // { id?, kind, name, title, body }
const editOpen = ref(false)
function newTpl(kind) {
  edit.value = { id: '', kind, name: '', title: '', body: '' }
  editOpen.value = true
}
async function openTpl(t) {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-tpl-get', { id: t.id })
    if (!r.ok) { err.value = r.error || 'Failed to load template.'; return }
    edit.value = { id: r.tpl.id, kind: r.tpl.kind, name: r.tpl.name, title: r.tpl.title || '', body: r.tpl.body || '' }
    editOpen.value = true
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}
const paletteFor = computed(() => palettes.value[edit.value?.kind] || [])
const tplTag = (tok) => '{{' + tok + '}}'
function insertTok(tok) {
  if (!edit.value) return
  const ta = document.getElementById('tplBody')
  if (ta) {
    const s = ta.selectionStart ?? edit.value.body.length
    const e = ta.selectionEnd ?? edit.value.body.length
    edit.value.body = edit.value.body.slice(0, s) + '{{' + tok + '}}' + edit.value.body.slice(e)
    requestAnimationFrame(() => { ta.focus(); const p = s + tok.length + 4; ta.setSelectionRange(p, p) })
  } else edit.value.body += '{{' + tok + '}}'
}
async function saveTpl() {
  if (!edit.value) return
  if (!edit.value.name || !edit.value.body.trim()) { err.value = 'Name and body are required.'; return }
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-tpl-save', { id: edit.value.id || undefined, kind: edit.value.kind, name: edit.value.name, title: edit.value.title, body: edit.value.body })
    if (!r.ok) { err.value = r.error || 'Save failed.'; return }
    editOpen.value = false
    toast.value = `✅ ${r.id} saved`
    setTimeout(() => toast.value = '', 4000)
    await loadAll()
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}
async function dupTpl(t) {
  if (!confirm(`Duplicate template ${t.id}?`)) return
  err.value = ''
  const r = await apiCall('app-tpl-dup', { id: t.id })
  if (!r.ok) { err.value = r.error || 'Duplicate failed.'; return }
  toast.value = `✅ ${r.id} created`
  setTimeout(() => toast.value = '', 4000)
  await loadAll()
}
async function delTpl(t) {
  if (!confirm(`Delete template ${t.id}?`)) return
  err.value = ''
  const r = await apiCall('app-tpl-delete', { id: t.id })
  if (!r.ok) { err.value = r.error || 'Delete failed.'; return }
  toast.value = `🗑 ${t.id} deleted`
  setTimeout(() => toast.value = '', 4000)
  await loadAll()
}
async function resetTpl(t) {
  if (!confirm(`Reset ${t.id} to its default content?`)) return
  err.value = ''
  const r = await apiCall('app-tpl-reset', { id: t.id })
  if (!r.ok) { err.value = r.error || 'Reset failed.'; return }
  toast.value = `↩ ${t.id} reset to default`
  setTimeout(() => toast.value = '', 4000)
  await loadAll()
}

// ── email templates (app-email-tpl-get / save / reset) ──
const emEdit = ref(null)      // { id, subject, body }
const emOpen = ref(false)
async function openEmail(e) {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-email-tpl-get', { id: e.id })
    if (!r.ok) { err.value = r.error || 'Failed to load email template.'; return }
    emEdit.value = { id: r.tpl.id, name: r.tpl.name, subject: r.tpl.subject || '', body: r.tpl.body || '' }
    emOpen.value = true
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}
async function saveEmail() {
  if (!emEdit.value) return
  if (!emEdit.value.subject || !emEdit.value.body.trim()) { err.value = 'Subject and body are required.'; return }
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-email-tpl-save', { id: emEdit.value.id, subject: emEdit.value.subject, body: emEdit.value.body })
    if (!r.ok) { err.value = r.error || 'Save failed.'; return }
    emOpen.value = false
    toast.value = `✅ ${emEdit.value.id} saved`
    setTimeout(() => toast.value = '', 4000)
    await loadAll()
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}
async function resetEmail(e) {
  if (!confirm(`Reset ${e.id} to its default content?`)) return
  err.value = ''
  const r = await apiCall('app-email-tpl-reset', { id: e.id })
  if (!r.ok) { err.value = r.error || 'Reset failed.'; return }
  toast.value = `↩ ${e.id} reset to default`
  setTimeout(() => toast.value = '', 4000)
  await loadAll()
}

onMounted(loadAll)
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🗂️ Templates</h1>
        <div class="sub">Lease / service / receipt documents &amp; email templates — placeholders auto-detected</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-ghost" @click="loadAll">🔄 Refresh</button>
      </div>
    </div>

    <div v-if="toast" style="padding:10px 14px;border-radius:10px;background:rgba(46,204,113,.12);border:1px solid rgba(46,204,113,.35);margin-bottom:14px;font-weight:700;font-size:13.5px">{{ toast }}</div>
    <div v-if="err" style="padding:10px 14px;border-radius:10px;background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.35);margin-bottom:14px;font-weight:600;font-size:13.5px">⚠️ {{ err }}</div>

    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">
      <button class="btn-ghost" :style="tab === 'docs' ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : ''" @click="tab = 'docs'">📄 Documents</button>
      <button class="btn-ghost" :style="tab === 'emails' ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : ''" @click="tab = 'emails'">✉️ Emails</button>
    </div>

    <!-- Docs -->
    <template v-if="tab === 'docs'">
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <select v-model="kindFilter" style="padding:9px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none">
          <option value="">All kinds</option>
          <option v-for="(l, k) in KIND" :key="k" :value="k">{{ l }}</option>
        </select>
        <div style="flex:1"></div>
        <button v-for="(l, k) in KIND" :key="k" class="btn-ghost" style="padding:7px 12px;font-size:12px" @click="newTpl(k)">＋ {{ l }}</button>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px">
        <div v-for="t in filtered" :key="t.id" class="panel" style="margin:0;display:flex;flex-direction:column">
          <div style="padding:14px 16px;flex:1">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
              <span class="badge" :class="t.kind === 'lease' ? 'b-blue' : t.kind === 'service' ? 'b-orange' : 'b-green'">{{ KIND[t.kind] || t.kind }}</span>
              <span v-if="t.is_default" class="badge b-gray">default</span>
            </div>
            <div style="font-weight:800;font-size:14px">{{ t.name }}</div>
            <div v-if="t.title" class="c-sub" style="font-size:11.5px">{{ t.title }}</div>
            <div class="c-sub" style="font-size:11px;margin-top:8px">{{ t.id }} · {{ t.updated_by || '—' }} · {{ t.updated_at }}</div>
          </div>
          <div style="padding:10px 16px;border-top:1px solid var(--border);display:flex;gap:6px;flex-wrap:wrap">
            <button class="btn-ghost" style="padding:5px 11px;font-size:11.5px" @click="openTpl(t)">✏️ Edit</button>
            <button class="btn-ghost" style="padding:5px 11px;font-size:11.5px" @click="dupTpl(t)">⧉ Duplicate</button>
            <button v-if="t.is_default" class="btn-ghost" style="padding:5px 11px;font-size:11.5px" @click="resetTpl(t)">↩ Reset</button>
            <div style="flex:1"></div>
            <button v-if="!t.is_default" class="btn-ghost" style="padding:5px 11px;font-size:11.5px;color:var(--danger)" @click="delTpl(t)">🗑</button>
          </div>
        </div>
        <div v-if="!filtered.length" class="panel" style="padding:40px;text-align:center;color:var(--text-mute)">No templates{{ kindFilter ? ' of this kind' : '' }} yet.</div>
      </div>
    </template>

    <!-- Emails -->
    <template v-if="tab === 'emails'">
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">✉️</span>Email templates</div></div>
        <div class="tbl-wrap">
          <table>
            <thead><tr><th>ID</th><th>Name</th><th>Subject</th><th>Updated</th><th></th></tr></thead>
            <tbody>
              <tr v-for="e in emails" :key="e.id">
                <td><span class="c-name">{{ e.id }}</span></td>
                <td>{{ e.name }}</td>
                <td>{{ e.subject }}</td>
                <td>{{ e.updated_by || '—' }} · {{ e.updated_at }}</td>
                <td style="white-space:nowrap">
                  <button class="btn-ghost" style="padding:4px 10px;font-size:11.5px" @click="openEmail(e)">✏️ Edit</button>
                  <button class="btn-ghost" style="padding:4px 10px;font-size:11.5px" @click="resetEmail(e)">↩ Reset</button>
                </td>
              </tr>
              <tr v-if="!emails.length"><td colspan="5" class="m">No email templates.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- doc template editor -->
    <div v-if="editOpen" class="overlay" @click.self="editOpen = false">
      <div class="modal" style="width:640px;max-width:94vw">
        <div class="modal-h"><span class="t">{{ edit.id ? '✏️ ' + edit.id : '＋ New ' + (KIND[edit.kind] || edit.kind) }}</span><button class="close" @click="editOpen = false">✕</button></div>
        <div style="padding:18px 20px;display:flex;flex-direction:column;gap:12px">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div class="form-field"><label>Name</label><input v-model="edit.name" placeholder="e.g. Standard lease agreement" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
            <div class="form-field"><label>Title (optional)</label><input v-model="edit.title" placeholder="Document title line" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
          </div>
          <div class="form-field"><label>Placeholders — click to insert</label>
            <div style="display:flex;flex-wrap:wrap;gap:5px">
              <button v-for="p in paletteFor" :key="p[0]" type="button" class="btn-ghost" style="padding:3px 9px;font-size:10.5px;font-family:monospace" @click="insertTok(p[0])">{{ tplTag(p[0]) }}</button>
              <span v-if="!paletteFor.length" class="c-sub">Choose a kind to see placeholders.</span>
            </div>
          </div>
          <div class="form-field"><label>Body</label>
            <textarea id="tplBody" v-model="edit.body" rows="14" placeholder="Use {{placeholders}} — e.g. This lease between {{tenant_name}} and {{org_name}}…" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:ui-monospace,monospace;font-size:12px;color:var(--text);outline:none;resize:vertical;line-height:1.55"></textarea>
          </div>
        </div>
        <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
          <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="editOpen = false">Cancel</button>
          <button class="btn-primary" style="padding:9px 16px;font-size:13px" :disabled="loading" @click="saveTpl">💾 Save</button>
        </div>
      </div>
    </div>

    <!-- email template editor -->
    <div v-if="emOpen" class="overlay" @click.self="emOpen = false">
      <div class="modal" style="width:640px;max-width:94vw">
        <div class="modal-h"><span class="t">✏️ {{ emEdit.id }} · {{ emEdit.name }}</span><button class="close" @click="emOpen = false">✕</button></div>
        <div style="padding:18px 20px;display:flex;flex-direction:column;gap:12px">
          <div class="form-field"><label>Subject</label><input v-model="emEdit.subject" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:inherit;font-size:13px;color:var(--text);outline:none"></div>
          <div class="form-field"><label>Body</label>
            <textarea v-model="emEdit.body" rows="12" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;background:var(--bg-alt);font-family:ui-monospace,monospace;font-size:12px;color:var(--text);outline:none;resize:vertical;line-height:1.55"></textarea>
          </div>
        </div>
        <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
          <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="emOpen = false">Cancel</button>
          <button class="btn-primary" style="padding:9px 16px;font-size:13px" :disabled="loading" @click="saveEmail">💾 Save</button>
        </div>
      </div>
    </div>
  </div>
</template>
