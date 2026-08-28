<script setup>
// KRTaker RichEditor V2 (2026-08-12) — WYSIWYG + HTML source + Plain-text modes.
// Toolbar: inline styles, blocks, lists, alignment, links, images, colors,
// highlight, size, table, hr, sub/sup, indent/outdent, clear, undo/redo.
// Modes: Write (contenteditable) · HTML (raw source) · Text (plain, strips tags).
// Dependency-free (document.execCommand — same as v1; works in all browsers).
import { ref, watch, onMounted } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  minHeight: { type: String, default: '220px' },
})
const emit = defineEmits(['update:modelValue'])

const el = ref(null)
const focused = ref(false)
const mode = ref('write')
const htmlSrc = ref('')
const textSrc = ref('')
let savedRange = null

// ── conversion helpers ──
function toHtml(txt) {
  if (/<[a-z][\s\S]*>/i.test(txt || '')) return txt || ''
  return (txt || '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/\r\n/g, '\n').split('\n').map(l => l.trim() ? '<div>' + l + '</div>' : '<div><br></div>').join('')
}
function stripTags(txt) {
  const d = document.createElement('div')
  d.innerHTML = (txt || '')
    .replace(/<br\s*\/?>/gi, '\n').replace(/<\/(p|div|h[1-6]|li|tr)>/gi, '\n')
  return (d.textContent || '').replace(/\n{3,}/g, '\n\n').trim()
}
function setHtml(h) { if (el.value && el.value.innerHTML !== toHtml(h)) el.value.innerHTML = toHtml(h) }
watch(() => props.modelValue, (v) => {
  if (mode.value === 'write' && !focused.value) setHtml(v)
  else if (mode.value === 'html' && !focused.value) htmlSrc.value = v || ''
  else if (mode.value === 'text' && !focused.value) textSrc.value = stripTags(v)
})
onMounted(() => { setHtml(props.modelValue); htmlSrc.value = props.modelValue || ''; textSrc.value = stripTags(props.modelValue) })

// ── selection & editing ──
function saveSel() {
  const sel = window.getSelection()
  if (sel && sel.rangeCount && el.value && el.value.contains(sel.anchorNode)) savedRange = sel.getRangeAt(0).cloneRange()
}
function restoreSel() {
  if (savedRange && el.value) { const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(savedRange) }
}
function onBlur() { focused.value = false; saveSel() }
function onInput() { emit('update:modelValue', el.value.innerHTML) }
function exec(cmd, val) {
  el.value.focus(); restoreSel()
  document.execCommand(cmd, false, val)
  emit('update:modelValue', el.value.innerHTML)
}
function setBlock(tag) { exec('formatBlock', false, tag) }
function clearFmt() { exec('removeFormat') }
function insertHtml(h) {
  el.value.focus(); restoreSel()
  document.execCommand('insertHTML', false, h)
  emit('update:modelValue', el.value.innerHTML)
}
function setColor(c) { exec('foreColor', c); colorOpen.value = false }
function setHl(c) { exec('hiliteColor', c); hlOpen.value = false }
function inlineCode() {
  el.value.focus(); restoreSel()
  const sel = window.getSelection()
  const txt = sel ? sel.toString() : ''
  document.execCommand('insertHTML', false,
    txt ? '<code>' + txt.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</code>' : '<code>code</code>')
  emit('update:modelValue', el.value.innerHTML)
}
function setSize(s) {
  const map = { S: '<span style="font-size:12px">', M: '<span style="font-size:15px">', L: '<span style="font-size:19px">' }
  el.value.focus(); restoreSel()
  document.execCommand('insertHTML', false, map[s])
  emit('update:modelValue', el.value.innerHTML)
}
function insertTable() { insertHtml('<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;margin:6px 0"><tr><td>Cell</td><td>Cell</td></tr><tr><td>Cell</td><td>Cell</td></tr></table>') }
function insertHr() { insertHtml('<hr style="border:none;border-top:1px solid #ccc;margin:10px 0">') }
function insertAtCaret(text) {
  el.value.focus(); restoreSel()
  document.execCommand('insertText', false, text)
  emit('update:modelValue', el.value.innerHTML)
}

// ── mode switching ──
function switchMode(m) {
  if (m === mode.value) return
  if (m === 'write') {
    if (mode.value === 'html') setHtml(htmlSrc.value)
    else setHtml(textSrc.value)
  } else if (m === 'html') {
    htmlSrc.value = mode.value === 'text' ? textSrc.value : (props.modelValue || '')
  } else {
    textSrc.value = stripTags(mode.value === 'html' ? htmlSrc.value : (props.modelValue || ''))
  }
  mode.value = m
}
function onModeInput() {
  if (mode.value === 'html') emit('update:modelValue', htmlSrc.value)
  else emit('update:modelValue', textSrc.value)
}

// ── link / image popover (no window.prompt — headless-safe) ──
const pop = ref(null)   // { kind: 'link'|'image', url: '' }
const popUrl = ref('')
function openPop(kind) { pop.value = kind; popUrl.value = '' }
function applyPop() {
  const url = popUrl.value.trim()
  if (pop.value === 'link' && url) exec('createLink', url)
  if (pop.value === 'image' && url) insertHtml('<img src="' + url.replace(/"/g, '&quot;') + '" alt="" style="max-width:100%;border-radius:8px;margin:6px 0">')
  pop.value = null
}

// ── color swatches ──
const colorOpen = ref(false)
const hlOpen = ref(false)
const COLORS = ['#1F2937', '#DC2626', '#EA580C', '#D97706', '#16A34A', '#0891B2', '#2563EB', '#7C3AED', '#DB2777', '#6B7280']
const HLS = ['#FEF08A', '#FED7AA', '#FECACA', '#BBF7D0', '#BFDBFE', '#E9D5FF', '#FBCFE8', '#E5E7EB']

defineExpose({ insertAtCaret, focus: () => el.value && el.value.focus(), stripTags })
</script>

<template>
  <div class="rich-wrap">
    <!-- Toolbar -->
    <div class="rich-tb" @mousedown.prevent>
      <button type="button" title="Undo" @click="exec('undo')">↶</button>
      <button type="button" title="Redo" @click="exec('redo')">↷</button>
      <span class="sep"></span>
      <button type="button" title="Bold" @click="exec('bold')"><b>B</b></button>
      <button type="button" title="Italic" @click="exec('italic')"><i>I</i></button>
      <button type="button" title="Underline" @click="exec('underline')"><u>U</u></button>
      <button type="button" title="Strikethrough" @click="exec('strikeThrough')"><s>S</s></button>
      <button type="button" title="Subscript" @click="exec('subscript')">x₂</button>
      <button type="button" title="Superscript" @click="exec('superscript')">x²</button>
      <span class="sep"></span>
      <button type="button" title="Heading 1" @click="setBlock('h2')">H1</button>
      <button type="button" title="Heading 2" @click="setBlock('h3')">H2</button>
      <button type="button" title="Heading 3" @click="setBlock('h4')">H3</button>
      <button type="button" title="Normal paragraph" @click="setBlock('p')">¶</button>
      <button type="button" title="Blockquote" @click="setBlock('blockquote')">❝</button>
      <span class="sep"></span>
      <button type="button" title="Bullet list" @click="exec('insertUnorderedList')">•≡</button>
      <button type="button" title="Numbered list" @click="exec('insertOrderedList')">1≡</button>
      <button type="button" title="Indent" @click="exec('indent')">⤏</button>
      <button type="button" title="Outdent" @click="exec('outdent')">⤎</button>
      <span class="sep"></span>
      <button type="button" title="Align left" @click="exec('justifyLeft')">⇤</button>
      <button type="button" title="Align center" @click="exec('justifyCenter')">⇔</button>
      <button type="button" title="Align right" @click="exec('justifyRight')">⇥</button>
      <button type="button" title="Justify" @click="exec('justifyFull')">≣</button>
      <span class="sep"></span>
      <button type="button" title="Insert link" @click="openPop('link')">🔗</button>
      <button type="button" title="Insert image (URL)" @click="openPop('image')">🖼</button>
      <button type="button" title="Inline code" @click="inlineCode">⟨/⟩</button>
      <button type="button" title="Code block" @click="setBlock('pre')">{ }</button>
      <span class="sep"></span>
      <div class="dd" style="position:relative">
        <button type="button" title="Text color" @click="colorOpen = !colorOpen; hlOpen = false">🎨</button>
        <div v-if="colorOpen" class="dd-pal">
          <button v-for="c in COLORS" :key="c" type="button" :style="{ background: c }" :title="c" @click="setColor(c)"></button>
        </div>
      </div>
      <div class="dd" style="position:relative">
        <button type="button" title="Highlight" @click="hlOpen = !hlOpen; colorOpen = false">🖍</button>
        <div v-if="hlOpen" class="dd-pal">
          <button v-for="c in HLS" :key="c" type="button" :style="{ background: c }" :title="c" @click="setHl(c)"></button>
        </div>
      </div>
      <button type="button" title="Small text" @click="setSize('S')">A⁻</button>
      <button type="button" title="Medium text" @click="setSize('M')">A</button>
      <button type="button" title="Large text" @click="setSize('L')">A⁺</button>
      <span class="sep"></span>
      <button type="button" title="Insert table" @click="insertTable">⊞</button>
      <button type="button" title="Horizontal rule" @click="insertHr">―</button>
      <button type="button" title="Clear formatting" @click="clearFmt">⌫</button>
      <span class="tb-grow"></span>
      <!-- Mode toggle -->
      <div class="rich-mode" role="tablist">
        <button type="button" :class="{ on: mode === 'write' }" @click="switchMode('write')">✍ Write</button>
        <button type="button" :class="{ on: mode === 'html' }" @click="switchMode('html')">&lt;/&gt; HTML</button>
        <button type="button" :class="{ on: mode === 'text' }" @click="switchMode('text')">T Text</button>
      </div>
    </div>

    <!-- Link/image popover -->
    <div v-if="pop" class="rich-pop" @mousedown.prevent>
      <input ref="popInp" v-model="popUrl" :placeholder="pop === 'link' ? 'https://…' : 'Image URL https://…'" @keyup.enter="applyPop" style="flex:1;min-width:180px;padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:12.5px;background:var(--bg);color:var(--text)">
      <button type="button" class="pop-ok" @click="applyPop">Insert</button>
      <button type="button" class="pop-x" @click="pop = null">✕</button>
    </div>

    <!-- Write -->
    <div v-show="mode === 'write'" class="rich-ed" ref="el" contenteditable="true" :data-ph="placeholder"
      :style="{ minHeight }" @input="onInput" @focus="focused = true" @blur="onBlur"></div>
    <!-- HTML source -->
    <textarea v-show="mode === 'html'" v-model="htmlSrc" class="rich-src" spellcheck="false"
      :placeholder="placeholder" :style="{ minHeight }" @input="onModeInput"></textarea>
    <!-- Plain text -->
    <textarea v-show="mode === 'text'" v-model="textSrc" class="rich-src rich-txt" spellcheck="true"
      :placeholder="placeholder" :style="{ minHeight }" @input="onModeInput"></textarea>
  </div>
</template>

<style scoped>
.rich-wrap { border:1px solid var(--border); border-radius:12px; overflow:hidden; background:var(--bg-alt) }
.rich-tb { display:flex; flex-wrap:wrap; gap:2px; padding:6px 8px; border-bottom:1px solid var(--border); background:var(--card); align-items:center; user-select:none }
.rich-tb button { min-width:28px; height:27px; padding:0 7px; border:1px solid transparent; border-radius:6px; background:transparent; color:var(--text-mute); font-size:12px; font-weight:600; cursor:pointer; font-family:inherit; transition:all .12s }
.rich-tb button:hover { background:var(--bg-alt); color:var(--text); border-color:var(--border) }
.rich-tb .sep { width:1px; height:18px; background:var(--border); margin:0 4px; flex:none }
.rich-tb .tb-grow { flex:1 }
.rich-mode { display:flex; background:var(--bg-alt); border:1px solid var(--border); border-radius:8px; overflow:hidden; margin-left:6px }
.rich-mode button { min-width:0; padding:5px 10px; border:none; border-radius:0; font-size:11.5px; font-weight:800 }
.rich-mode button.on { background:var(--primary); color:#fff }
.rich-ed { padding:13px 15px; font-size:13.5px; line-height:1.75; color:var(--text); outline:none; overflow-y:auto; max-height:56vh; word-break:break-word }
.rich-ed:empty::before { content:attr(data-ph); color:var(--text-mute); opacity:.55 }
.rich-ed :deep(p) { margin:0 0 9px }
.rich-ed :deep(h2) { font-size:18px; margin:12px 0 7px; font-weight:800 }
.rich-ed :deep(h3) { font-size:16px; margin:11px 0 6px; font-weight:700 }
.rich-ed :deep(h4) { font-size:14.5px; margin:10px 0 6px; font-weight:700 }
.rich-ed :deep(ul), .rich-ed :deep(ol) { margin:4px 0 9px; padding-left:22px }
.rich-ed :deep(li) { margin:2px 0 }
.rich-ed :deep(a) { color:var(--primary) }
.rich-ed :deep(blockquote) { margin:6px 0 10px; padding:6px 12px; border-left:3px solid var(--primary); background:var(--card); color:var(--text-mute); border-radius:0 8px 8px 0 }
.rich-ed :deep(code) { background:var(--bg); border:1px solid var(--border); padding:1px 5px; border-radius:5px; font-family:ui-monospace,monospace; font-size:12px }
.rich-ed :deep(pre) { background:var(--bg); border:1px solid var(--border); padding:10px 12px; border-radius:8px; overflow-x:auto; font-family:ui-monospace,monospace; font-size:12.5px; margin:6px 0 10px }
.rich-ed :deep(pre code) { border:none; background:none; padding:0 }
.rich-src { width:100%; padding:12px 14px; font-family:ui-monospace,monospace; font-size:12.5px; line-height:1.6; color:var(--text); background:var(--bg); border:none; outline:none; resize:vertical; word-break:break-word }
.rich-txt { font-family:inherit; font-size:13.5px; line-height:1.7 }
.rich-pop { display:flex; gap:6px; padding:8px; border-bottom:1px solid var(--border); background:var(--card); align-items:center }
.pop-ok { background:var(--primary); color:#fff; border:none; border-radius:8px; padding:7px 13px; font-weight:800; font-size:12px; cursor:pointer }
.pop-x { background:transparent; border:none; color:var(--text-mute); font-size:14px; cursor:pointer; padding:4px 7px }
.dd-pal { position:absolute; top:30px; left:0; z-index:20; display:grid; grid-template-columns:repeat(5, 20px); gap:5px; padding:8px; background:var(--card); border:1px solid var(--border); border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,.14) }
.dd-pal button { min-width:20px; width:20px; height:20px; padding:0; border-radius:5px; border:1px solid rgba(0,0,0,.12) }
</style>
