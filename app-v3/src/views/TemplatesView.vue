<script setup>
import { ref, computed, onMounted } from 'vue'
import { apiCall } from '../api/client'
import RichEditor from '../components/RichEditor.vue'
import CompactFilters from '../components/CompactFilters.vue'

const tab = ref('docs')
const loading = ref(false)
const err = ref('')
const toast = ref('')

// ── doc templates (app-tpl-list / get / save / dup / delete / reset) ──
const tpls = ref([])          // [{id,kind,name,title,lang,is_default,updated_by,updated_at}]
const emails = ref([])        // [{id,name,subject,lang,updated_by,updated_at}]
const palettes = ref({})
const kindFilter = ref('')
const langFilter = ref('')    // '' | 'en' | 'bn'

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
const KIND_GRAD = { lease: 'linear-gradient(135deg,#2F80ED,#1E5EB8)', service: 'linear-gradient(135deg,#E67E22,#D35400)', receipt: 'linear-gradient(135deg,#27AE60,#1E8449)' }
const LANG = { en: { label: 'English', short: 'EN', flag: '🇬🇧' }, bn: { label: 'বাংলা', short: 'BN', flag: '🇧🇩' } }

const stats = computed(() => {
  const t = tpls.value, e = emails.value
  return {
    docs: t.length, emails: e.length,
    en: t.filter(x => (x.lang || 'en') === 'en').length + e.filter(x => (x.lang || 'en') === 'en').length,
    bn: t.filter(x => (x.lang || 'en') === 'bn').length + e.filter(x => (x.lang || 'en') === 'bn').length,
  }
})

const filtered = computed(() => {
  let out = tpls.value
  if (kindFilter.value) out = out.filter(t => t.kind === kindFilter.value)
  if (langFilter.value) out = out.filter(t => (t.lang || 'en') === langFilter.value)
  return out
})
const filteredEmails = computed(() =>
  langFilter.value ? emails.value.filter(e => (e.lang || 'en') === langFilter.value) : emails.value)

const previewOf = (body) => (body || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 150)
// rich editor refs (token insertion targets the focused editor)
const docEditor = ref(null)
const emailEditor = ref(null)
// ── live preview (frontend token substitution) ──
const SAMPLE = {
  tenant_name: 'Tahmina Akter', tenant_phone: '01712-345678', tenant_nid: '1234567890', tenant_email: 'tahmina@example.com',
  property_name: 'Green View Residency', property_address: 'House 12, Road 5, Dhanmondi, Dhaka',
  unit_name: 'Flat 3B', unit_id: 'U-001', floor: '3rd',
  lease_id: 'L-007', lease_start: '15 Jun 2026', lease_end: '14 Jun 2027',
  rent: '৳32,000', rent_words: 'Thirty-Two Thousand', rent_words_bn: 'বত্রিশ হাজার টাকা',
  advance: '৳90,000', advance_words: 'Ninety Thousand', advance_words_bn: 'নব্বই হাজার টাকা',
  reg_office: 'Sub-Registrar, Dhanmondi', reg_deed: 'Deed No. 4123', reg_note: 'Registration recommended for enforceability.',
  receipt_id: 'PAY-010', date: '02 Aug 2026', amount: '৳25,000', amount_words_en: 'Twenty-Five Thousand', amount_words_bn: 'পঁচিশ হাজার টাকা মাত্র',
  method: 'bKash', ref: '8T5XK2QZ', invoice_id: 'INV-2026-0011', month: '2026-06',
  partner_name: 'Rahim Steel Works', partner_trade: 'Fabrication & Repair', partner_rating: '4.8', partner_jobs: '23', partner_status: 'Active', partner_email: 'rahim@example.com',
  org_name: 'KRTaker Ltd', org_phone: '+880 1722-759646', org_email: 'support@krtaker.com', org_address: 'Dhaka, Bangladesh', today: '12 Jun 2026',
  name: 'Tahmina Akter', code: '483920', expiry: '5 minutes', trial_end: '26 Jun 2026', workspace_url: 'https://krtaker.com/app-v3/',
  total_due: '৳96,000', days_overdue: '21', pay_url: 'https://krtaker.com/app-v3/',
}
const sampleRender = (body) => (body || '').replace(/\{\{(\w+)\}\}/g, (m, k) => SAMPLE[k] !== undefined ? SAMPLE[k] : m)

const previewOpen = ref(false)
const previewBody = ref('')
const previewRaw = ref('')
const previewTitle = ref('')
const previewKind = ref('doc')        // 'doc' | 'email'
const previewSubject = ref('')
function showPreview(title, body, kind = 'doc', subject = '') {
  previewTitle.value = title
  previewRaw.value = body || ''
  previewBody.value = sampleRender(body)
  previewKind.value = kind
  previewSubject.value = sampleRender(subject || '')
  previewOpen.value = true
}
const previewTokens = computed(() => {
  const m = previewRaw.value.match(/\{\{(\w+)\}\}/g)
  return m ? [...new Set(m.map(t => t.replace(/[{}]/g, '')))] : []
})
const looksHtml = (s) => /<[a-z][\s\S]*>/i.test(s || '')
const previewHtml = computed(() => looksHtml(previewBody.value) ? previewBody.value : null)
const tokTag = (t) => '{{' + t + '}}'
async function previewTpl(t) {
  let body = t.body
  if (!body) {
    try {
      const r = await apiCall('app-tpl-get', { id: t.id })
      if (r.ok) body = r.tpl?.body || ''
    } catch (e) {}
  }
  showPreview(t.title || t.name, body, 'doc')
}
async function previewEmail(e) {
  let body = e.body, subj = e.subject
  if (!body) {
    try {
      const r = await apiCall('app-email-tpl-get', { id: e.id })
      if (r.ok) { body = r.tpl?.body || ''; subj = r.tpl?.subject || subj }
    } catch (e) {}
  }
  showPreview(e.name, body, 'email', subj)
}
async function copyPreview() {
  const txt = previewBody.value
  let ok = false
  try {
    await navigator.clipboard.writeText(txt)
    ok = true
  } catch (e) {
    try {
      const ta = document.createElement('textarea')
      ta.value = txt
      ta.style.position = 'fixed'; ta.style.opacity = '0'
      document.body.appendChild(ta); ta.select()
      ok = document.execCommand('copy')
      ta.remove()
    } catch (e2) { ok = false }
  }
  toast.value = ok ? '✅ Preview copied to clipboard' : '⚠️ Copy failed'
  setTimeout(() => toast.value = '', 3000)
}

// ── editor modal (doc) ──
const edit = ref(null)        // { id?, kind, name, title, body, lang }
const editOpen = ref(false)
function newTpl(kind) {
  edit.value = { id: '', kind, name: '', title: '', body: '', lang: langFilter.value || 'en' }
  editOpen.value = true
}
async function openTpl(t) {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-tpl-get', { id: t.id })
    if (!r.ok) { err.value = r.error || 'Failed to load template.'; return }
    edit.value = { id: r.tpl.id, kind: r.tpl.kind, name: r.tpl.name, title: r.tpl.title || '', body: r.tpl.body || '', lang: r.tpl.lang || 'en' }
    editOpen.value = true
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}
const paletteFor = computed(() => palettes.value[edit.value?.kind] || [])
const tplTag = (tok) => '{{' + tok + '}}'
function insertTok(tok) {
  if (!edit.value) return
  if (docEditor.value) docEditor.value.insertAtCaret(tplTag(tok))
  else edit.value.body += tplTag(tok)
}
async function saveTpl() {
  if (!edit.value) return
  if (!edit.value.name || !edit.value.body.trim()) { err.value = 'Name and body are required.'; return }
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-tpl-save', { id: edit.value.id || undefined, kind: edit.value.kind, name: edit.value.name, title: edit.value.title, body: edit.value.body, lang: edit.value.lang })
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
const emEdit = ref(null)      // { id, name, subject, body, lang }
const emOpen = ref(false)
async function openEmail(e) {
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-email-tpl-get', { id: e.id })
    if (!r.ok) { err.value = r.error || 'Failed to load email template.'; return }
    emEdit.value = { id: r.tpl.id, name: r.tpl.name, subject: r.tpl.subject || '', body: r.tpl.body || '', lang: r.tpl.lang || 'en' }
    emOpen.value = true
  } catch (e) { err.value = e.message }
  finally { loading.value = false }
}
async function saveEmail() {
  if (!emEdit.value) return
  if (!emEdit.value.subject || !emEdit.value.body.trim()) { err.value = 'Subject and body are required.'; return }
  loading.value = true; err.value = ''
  try {
    const r = await apiCall('app-email-tpl-save', { id: emEdit.value.id, subject: emEdit.value.subject, body: emEdit.value.body, lang: emEdit.value.lang })
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

onMounted(() => {
  // respect the topbar language toggle (localStorage krtaker_dash_lang)
  try { if (localStorage.getItem('krtaker_dash_lang') === 'bn') langFilter.value = 'bn' } catch (e) {}
  loadAll()
})
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🗂️ Templates</h1>
        <div class="sub">Documents &amp; email templates in English and বাংলা — placeholders auto-detected</div>
      </div>
      <div class="head-actions" style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn-ghost" @click="loadAll">🔄 Refresh</button>
      </div>
    </div>

    <!-- stats strip -->
    <div class="tpl-stats">
      <div class="stat-chip"><span class="stat-ico">📄</span><b>{{ stats.docs }}</b> documents</div>
      <div class="stat-chip"><span class="stat-ico">✉️</span><b>{{ stats.emails }}</b> emails</div>
      <div class="stat-chip en"><span class="stat-ico">🇬🇧</span><b>{{ stats.en }}</b> English</div>
      <div class="stat-chip bn"><span class="stat-ico">🇧🇩</span><b>{{ stats.bn }}</b> বাংলা</div>
    </div>

    <div v-if="toast" class="tpl-toast ok">{{ toast }}</div>
    <div v-if="err" class="tpl-toast bad">⚠️ {{ err }}</div>

    <!-- tabs -->
    <div class="seg" style="margin-bottom:16px">
      <button class="seg-btn" :class="{ on: tab === 'docs' }" @click="tab = 'docs'">📄 Documents</button>
      <button class="seg-btn" :class="{ on: tab === 'emails' }" @click="tab = 'emails'">✉️ Emails <span class="seg-count">{{ emails.length }}</span></button>
    </div>

    <!-- Docs -->
    <template v-if="tab === 'docs'">
      <div class="tpl-toolbar">
        <CompactFilters>
        <select v-model="kindFilter" class="tpl-select">
          <option value="">All kinds</option>
          <option v-for="(l, k) in KIND" :key="k" :value="k">{{ l }}</option>
        </select>
        <div class="lang-seg">
          <button class="lang-pill" :class="{ on: langFilter === '' }" @click="langFilter = ''">All</button>
          <button class="lang-pill en" :class="{ on: langFilter === 'en' }" @click="langFilter = 'en'">🇬🇧 EN</button>
          <button class="lang-pill bn" :class="{ on: langFilter === 'bn' }" @click="langFilter = 'bn'">🇧🇩 বাংলা</button>
        </div>
        <div style="flex:1"></div>
        </CompactFilters>
        <button v-for="(l, k) in KIND" :key="k" class="btn-ghost tpl-new" @click="newTpl(k)">＋ {{ l }}</button>
      </div>

      <div class="tpl-grid">
        <div v-for="t in filtered" :key="t.id" class="panel tpl-card" style="margin:0">
          <div class="tpl-band" :style="{ background: KIND_GRAD[t.kind] || '#2F80ED' }"></div>
          <div class="tpl-card-body">
            <div class="tpl-card-top">
              <span class="badge" :class="t.kind === 'lease' ? 'b-blue' : t.kind === 'service' ? 'b-orange' : 'b-green'">{{ KIND[t.kind] || t.kind }}</span>
              <div style="flex:1"></div>
              <span v-if="t.is_default" class="badge b-gray">default</span>
              <span class="lang-pill mini" :class="(t.lang || 'en') === 'en' ? 'en' : 'bn'">{{ (t.lang || 'en') === 'en' ? '🇬🇧 EN' : '🇧🇩 বাংলা' }}</span>
            </div>
            <div class="tpl-name">{{ t.name }}</div>
            <div v-if="t.title" class="c-sub" style="font-size:11.5px">{{ t.title }}</div>
            <div class="tpl-preview">{{ previewOf(t.snippet || t.body) }}</div>
            <div class="c-sub tpl-meta">{{ t.id }} · {{ t.updated_by || '—' }} · {{ t.updated_at }}</div>
          </div>
          <div class="tpl-card-actions">
            <button class="btn-ghost" style="padding:5px 11px;font-size:11.5px" @click="openTpl(t)">✏️ Edit</button>
            <button class="btn-ghost" style="padding:5px 11px;font-size:11.5px" @click="previewTpl(t)">👁 Preview</button>
            <button class="btn-ghost" style="padding:5px 11px;font-size:11.5px" @click="dupTpl(t)">⧉ Duplicate</button>
            <button v-if="t.is_default" class="btn-ghost" style="padding:5px 11px;font-size:11.5px" @click="resetTpl(t)">↩ Reset</button>
            <div style="flex:1"></div>
            <button v-if="!t.is_default" class="btn-ghost" style="padding:5px 11px;font-size:11.5px;color:var(--danger)" @click="delTpl(t)">🗑</button>
          </div>
        </div>
        <div v-if="!filtered.length" class="panel tpl-empty">No templates{{ kindFilter ? ' of this kind' : '' }}{{ langFilter === 'bn' ? ' in বাংলা' : langFilter === 'en' ? ' in English' : '' }} yet.</div>
      </div>
    </template>

    <!-- Emails -->
    <template v-if="tab === 'emails'">
      <div class="tpl-toolbar">
        <div class="lang-seg">
          <button class="lang-pill" :class="{ on: langFilter === '' }" @click="langFilter = ''">All</button>
          <button class="lang-pill en" :class="{ on: langFilter === 'en' }" @click="langFilter = 'en'">🇬🇧 EN</button>
          <button class="lang-pill bn" :class="{ on: langFilter === 'bn' }" @click="langFilter = 'bn'">🇧🇩 বাংলা</button>
        </div>
        <div style="flex:1"></div>
      </div>
      <div class="tpl-grid">
        <div v-for="e in filteredEmails" :key="e.id" class="panel tpl-card" style="margin:0">
          <div class="tpl-band" style="background:linear-gradient(135deg,#6C5CE7,#5A4BD1)"></div>
          <div class="tpl-card-body">
            <div class="tpl-card-top">
              <span class="badge b-purple">✉️ Email</span>
              <div style="flex:1"></div>
              <span class="lang-pill mini" :class="(e.lang || 'en') === 'en' ? 'en' : 'bn'">{{ (e.lang || 'en') === 'en' ? '🇬🇧 EN' : '🇧🇩 বাংলা' }}</span>
            </div>
            <div class="tpl-name">{{ e.name }}</div>
            <div class="tpl-subject">📨 {{ e.subject }}</div>
            <div class="c-sub tpl-meta">{{ e.id }} · {{ e.updated_by || '—' }} · {{ e.updated_at }}</div>
          </div>
          <div class="tpl-card-actions">
            <button class="btn-ghost" style="padding:5px 11px;font-size:11.5px" @click="openEmail(e)">✏️ Edit</button>
            <button class="btn-ghost" style="padding:5px 11px;font-size:11.5px" @click="previewEmail(e)">👁 Preview</button>
            <div style="flex:1"></div>
            <button class="btn-ghost" style="padding:5px 11px;font-size:11.5px" @click="resetEmail(e)">↩ Reset</button>
          </div>
        </div>
        <div v-if="!filteredEmails.length" class="panel tpl-empty">No email templates{{ langFilter === 'bn' ? ' in বাংলা' : langFilter === 'en' ? ' in English' : '' }}.</div>
      </div>
    </template>

    <!-- doc template editor -->
    <div v-if="editOpen" class="overlay" @click.self="editOpen = false">
      <div class="modal" style="width:680px;max-width:94vw">
        <div class="modal-h"><span class="t">{{ edit.id ? '✏️ ' + edit.id : '＋ New ' + (KIND[edit.kind] || edit.kind) }}</span><button class="close" @click="editOpen = false">✕</button></div>
        <div style="padding:18px 20px;display:flex;flex-direction:column;gap:12px">
          <div class="form-field"><label>Language</label>
            <div class="lang-seg" style="margin-top:4px">
              <button type="button" class="lang-pill en" :class="{ on: edit.lang === 'en' }" @click="edit.lang = 'en'">🇬🇧 English</button>
              <button type="button" class="lang-pill bn" :class="{ on: edit.lang === 'bn' }" @click="edit.lang = 'bn'">🇧🇩 বাংলা</button>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div class="form-field"><label>Name</label><input v-model="edit.name" placeholder="e.g. Standard lease agreement" class="tpl-input"></div>
            <div class="form-field"><label>Title (optional)</label><input v-model="edit.title" placeholder="Document title line" class="tpl-input"></div>
          </div>
          <div class="form-field"><label>Placeholders — click to insert</label>
            <div style="display:flex;flex-wrap:wrap;gap:5px">
              <button v-for="p in paletteFor" :key="p[0]" type="button" class="btn-ghost tpl-tok" @click="insertTok(p[0])" :title="p[1]">{{ tplTag(p[0]) }}</button>
              <span v-if="!paletteFor.length" class="c-sub">Choose a kind to see placeholders.</span>
            </div>
          </div>
          <div class="form-field"><label>Body <span class="c-sub" style="font-weight:400">— {{ edit.body.replace(/<[^>]*>/g, ' ').length }} chars · rich text</span></label>
            <RichEditor ref="docEditor" v-model="edit.body" :min-height="'280px'" placeholder="Write your document — use {{placeholders}}…" />
          </div>
        </div>
        <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
          <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="editOpen = false">Cancel</button>
          <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="showPreview(edit.title || edit.name, edit.body, 'doc')">👁 Preview</button>
          <button class="btn-primary" style="padding:9px 16px;font-size:13px" :disabled="loading" @click="saveTpl">💾 Save</button>
        </div>
      </div>
    </div>

    <!-- email template editor -->
    <div v-if="emOpen" class="overlay" @click.self="emOpen = false">
      <div class="modal" style="width:680px;max-width:94vw">
        <div class="modal-h"><span class="t">✏️ {{ emEdit.id }} · {{ emEdit.name }}</span><button class="close" @click="emOpen = false">✕</button></div>
        <div style="padding:18px 20px;display:flex;flex-direction:column;gap:12px">
          <div class="form-field"><label>Language</label>
            <div class="lang-seg" style="margin-top:4px">
              <button type="button" class="lang-pill en" :class="{ on: emEdit.lang === 'en' }" @click="emEdit.lang = 'en'">🇬🇧 English</button>
              <button type="button" class="lang-pill bn" :class="{ on: emEdit.lang === 'bn' }" @click="emEdit.lang = 'bn'">🇧🇩 বাংলা</button>
            </div>
          </div>
          <div class="form-field"><label>Subject <span class="c-sub" style="font-weight:400">— {{ emEdit.subject.length }} chars</span></label><input v-model="emEdit.subject" class="tpl-input"></div>
          <div class="form-field"><label>Body <span class="c-sub" style="font-weight:400">— {{ emEdit.body.replace(/<[^>]*>/g, ' ').length }} chars · rich text</span></label>
            <RichEditor ref="emailEditor" v-model="emEdit.body" :min-height="'280px'" placeholder="Email body — use {{placeholders}}…" />
          </div>
        </div>
        <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
          <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="emOpen = false">Cancel</button>
          <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="showPreview(emEdit.name, emEdit.body, 'email', emEdit.subject)">👁 Preview</button>
          <button class="btn-primary" style="padding:9px 16px;font-size:13px" :disabled="loading" @click="saveEmail">💾 Save</button>
        </div>
      </div>
    </div>

    <!-- live preview modal -->
    <div v-if="previewOpen" class="overlay" @click.self="previewOpen = false">
      <div class="modal" style="width:720px;max-width:94vw">
        <div class="modal-h"><span class="t">👁 Preview · {{ previewTitle }}</span><button class="close" @click="previewOpen = false">✕</button></div>
        <div style="padding:16px 20px">
          <!-- email chrome -->
          <div v-if="previewKind === 'email'" class="prev-mail">
            <div class="pm-row"><span>From</span><b>{{ SAMPLE.org_name }} &lt;{{ SAMPLE.org_email }}&gt;</b></div>
            <div class="pm-row"><span>To</span><b>{{ SAMPLE.tenant_email }}</b></div>
            <div class="pm-row"><span>Subject</span><b class="pm-subj">{{ previewSubject }}</b></div>
          </div>
          <!-- rendered body -->
          <div class="prev-paper" :class="previewKind">
            <template v-if="previewKind === 'doc'">
              <div class="prev-org">{{ SAMPLE.org_name }}<template v-if="SAMPLE.org_address"> · {{ SAMPLE.org_address }}</template><template v-if="SAMPLE.org_phone"> · {{ SAMPLE.org_phone }}</template></div>
              <div v-if="previewTitle" class="prev-doc-title">{{ previewTitle }}</div>
              <div v-if="previewHtml" class="prev-body" v-html="previewHtml"></div>
              <div v-else class="prev-body">{{ previewBody }}</div>
            </template>
            <div v-if="previewHtml" class="prev-body" v-html="previewHtml"></div>
            <div v-else class="prev-body">{{ previewBody }}</div>
          </div>
          <!-- placeholder legend -->
          <div v-if="previewTokens.length" class="prev-tokens">
            <span class="c-sub" style="font-size:11px;font-weight:700">Placeholders:</span>
            <span v-for="t in previewTokens" :key="t" class="prev-tok">{{ tokTag(t) }}</span>
          </div>
        </div>
        <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
          <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="copyPreview">📋 Copy</button>
          <button class="btn-primary" style="padding:9px 16px;font-size:13px" @click="previewOpen = false">Close</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.tpl-stats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px }
.stat-chip { display:flex; align-items:center; gap:7px; background:var(--bg-alt); border:1px solid var(--border); border-radius:12px; padding:8px 14px; font-size:13px; color:var(--text-mute) }
.stat-chip b { font-size:15px; color:var(--text) }
.stat-chip .stat-ico { font-size:15px }
.stat-chip.en { border-color:rgba(47,128,237,.35); background:rgba(47,128,237,.07) }
.stat-chip.bn { border-color:rgba(38,166,91,.35); background:rgba(38,166,91,.07) }
.stat-chip.bn b { color:#1E8449 }

.tpl-toast { padding:10px 14px; border-radius:10px; margin-bottom:14px; font-weight:700; font-size:13.5px }
.tpl-toast.ok { background:rgba(46,204,113,.12); border:1px solid rgba(46,204,113,.35) }
.tpl-toast.bad { background:rgba(231,76,60,.12); border:1px solid rgba(231,76,60,.35); font-weight:600 }

.seg { display:inline-flex; background:var(--bg-alt); border:1px solid var(--border); border-radius:12px; padding:4px; gap:4px }
.seg-btn { border:0; background:transparent; color:var(--text-mute); padding:8px 18px; border-radius:9px; font-size:13px; font-weight:600; cursor:pointer; transition:all .15s }
.seg-btn.on { background:var(--primary); color:#fff }
.seg-count { background:rgba(255,255,255,.25); border-radius:99px; padding:0 7px; font-size:11px; margin-left:3px }

.tpl-toolbar { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:14px }
.tpl-select { padding:9px 12px; border:1px solid var(--border); border-radius:10px; background:var(--bg-alt); font-family:inherit; font-size:13px; color:var(--text); outline:none }

.lang-seg { display:inline-flex; gap:6px; flex-wrap:wrap }
.lang-pill { border:1px solid var(--border); background:var(--bg-alt); color:var(--text-mute); border-radius:99px; padding:7px 14px; font-size:12.5px; font-weight:600; cursor:pointer; transition:all .15s }
.lang-pill.on { border-color:var(--primary); color:var(--primary); background:rgba(47,128,237,.08) }
.lang-pill.en.on { border-color:#2F80ED; color:#2F80ED; background:rgba(47,128,237,.08) }
.lang-pill.bn.on { border-color:#1E8449; color:#1E8449; background:rgba(38,166,91,.08) }
.lang-pill.mini { padding:3px 9px; font-size:11px; pointer-events:none }
.lang-pill.mini.en { border-color:rgba(47,128,237,.4); color:#2F80ED; background:rgba(47,128,237,.08) }
.lang-pill.mini.bn { border-color:rgba(38,166,91,.4); color:#1E8449; background:rgba(38,166,91,.08) }

.tpl-new { padding:7px 12px; font-size:12px }

.tpl-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:14px }
.tpl-card { overflow:hidden; display:flex; flex-direction:column; transition:transform .15s, box-shadow .15s }
.tpl-card:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(16,24,40,.08) }
.tpl-band { height:4px; flex:none }
.tpl-card-body { padding:14px 16px; flex:1 }
.tpl-card-top { display:flex; align-items:center; gap:8px; margin-bottom:7px }
.tpl-name { font-weight:800; font-size:14px; color:var(--text) }
.tpl-subject { font-size:12px; color:var(--text-mute); margin-top:4px; line-height:1.5; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden }
.tpl-preview { margin-top:9px; font-size:11.5px; color:var(--text-mute); line-height:1.55; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; background:var(--bg-alt); border:1px dashed var(--border); border-radius:8px; padding:8px 10px }
.tpl-meta { font-size:11px; margin-top:9px }
.tpl-card-actions { padding:10px 16px; border-top:1px solid var(--border); display:flex; gap:6px; flex-wrap:wrap; align-items:center }
.tpl-empty { padding:40px; text-align:center; color:var(--text-mute) }

.tpl-input { width:100%; padding:9px 12px; border:1px solid var(--border); border-radius:9px; background:var(--bg-alt); font-family:inherit; font-size:13px; color:var(--text); outline:none }
.tpl-ta { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:9px; background:var(--bg-alt); font-family:ui-monospace,monospace; font-size:12px; color:var(--text); outline:none; resize:vertical; line-height:1.55 }
.tpl-tok { padding:3px 9px; font-size:10.5px; font-family:monospace }
.tpl-prev-box { margin:0; white-space:pre-wrap; word-break:break-word; background:var(--bg-alt); border:1px solid var(--border); border-radius:12px; padding:16px; font-family:ui-monospace,monospace; font-size:12.5px; line-height:1.7; color:var(--text); max-height:52vh; overflow:auto }

/* preview modal — email chrome + paper doc */
.prev-mail { border:1px solid var(--border); border-radius:12px; padding:11px 14px; background:var(--bg-alt); margin-bottom:12px; display:flex; flex-direction:column; gap:6px; font-size:12.5px }
.pm-row { display:flex; gap:10px; align-items:baseline }
.pm-row span { width:62px; flex:none; color:var(--text-mute); font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:.3px }
.pm-subj { color:var(--primary) }
.prev-paper { background:var(--bg-alt); border:1px solid var(--border); border-radius:12px; padding:22px 24px; max-height:50vh; overflow:auto }
.prev-paper.doc { font-family:Georgia,'Times New Roman',serif }
.prev-org { text-align:center; font-size:11px; color:var(--text-mute); letter-spacing:.3px; text-transform:uppercase; padding-bottom:10px; border-bottom:1px dashed var(--border); margin-bottom:14px }
.prev-doc-title { text-align:center; font-weight:800; font-size:17px; color:var(--text); margin-bottom:14px }
.prev-body { white-space:pre-wrap; word-break:break-word; font-size:13.5px; line-height:1.8; color:var(--text) }
.prev-paper.email .prev-body { font-family:ui-monospace,monospace; font-size:12.5px }
.prev-tokens { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-top:12px }
.prev-tok { border:1px solid var(--border); background:var(--bg-alt); color:var(--primary); border-radius:6px; padding:3px 9px; font-size:11px; font-family:monospace }
</style>
