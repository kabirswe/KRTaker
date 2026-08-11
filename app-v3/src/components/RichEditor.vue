<script setup>
import { ref, watch, onMounted } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  minHeight: { type: String, default: '220px' },
})
const emit = defineEmits(['update:modelValue'])

const el = ref(null)
const focused = ref(false)
let savedRange = null

function toHtml(txt) {
  if (/<[a-z][\s\S]*>/i.test(txt || '')) return txt || ''
  return (txt || '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/\r\n/g, '\n').split('\n').map(l => l.trim() ? '<div>' + l + '</div>' : '<div><br></div>').join('')
}
function setHtml(h) { if (el.value && el.value.innerHTML !== toHtml(h)) el.value.innerHTML = toHtml(h) }
watch(() => props.modelValue, (v) => { if (!focused.value) setHtml(v) })
onMounted(() => setHtml(props.modelValue))

function saveSel() {
  const sel = window.getSelection()
  if (sel && sel.rangeCount && el.value && el.value.contains(sel.anchorNode)) {
    savedRange = sel.getRangeAt(0).cloneRange()
  }
}
function restoreSel() {
  if (savedRange && el.value) {
    const sel = window.getSelection()
    sel.removeAllRanges(); sel.addRange(savedRange)
  }
}
function onBlur() { focused.value = false; saveSel() }

function onInput() { emit('update:modelValue', el.value.innerHTML) }
function exec(cmd, val) {
  el.value.focus()
  document.execCommand(cmd, false, val)
  emit('update:modelValue', el.value.innerHTML)
}
function setBlock(tag) { exec('formatBlock', false, tag) }
function insertAtCaret(text) {
  el.value.focus()
  restoreSel()
  document.execCommand('insertText', false, text)
  emit('update:modelValue', el.value.innerHTML)
}
function clearFmt() { exec('removeFormat') }

defineExpose({ insertAtCaret, focus: () => el.value && el.value.focus() })
</script>

<template>
  <div class="rich-wrap">
    <div class="rich-tb" @mousedown.prevent>
      <button type="button" title="Bold" @click="exec('bold')"><b>B</b></button>
      <button type="button" title="Italic" @click="exec('italic')"><i>I</i></button>
      <button type="button" title="Underline" @click="exec('underline')"><u>U</u></button>
      <button type="button" title="Strikethrough" @click="exec('strikeThrough')"><s>S</s></button>
      <span class="sep"></span>
      <button type="button" title="Heading 1" @click="setBlock('h2')">H1</button>
      <button type="button" title="Heading 2" @click="setBlock('h3')">H2</button>
      <button type="button" title="Normal paragraph" @click="setBlock('p')">¶</button>
      <span class="sep"></span>
      <button type="button" title="Bullet list" @click="exec('insertUnorderedList')">• List</button>
      <button type="button" title="Numbered list" @click="exec('insertOrderedList')">1. List</button>
      <span class="sep"></span>
      <button type="button" title="Align left" @click="exec('justifyLeft')">⇤ L</button>
      <button type="button" title="Align center" @click="exec('justifyCenter')">⇔ C</button>
      <button type="button" title="Align right" @click="exec('justifyRight')">⇥ R</button>
      <span class="sep"></span>
      <button type="button" title="Clear formatting" @click="clearFmt">⌫</button>
      <button type="button" title="Undo" @click="exec('undo')">↶</button>
      <button type="button" title="Redo" @click="exec('redo')">↷</button>
    </div>
    <div class="rich-ed" ref="el" contenteditable="true" :data-ph="placeholder"
      :style="{ minHeight }" @input="onInput" @focus="focused = true" @blur="onBlur"></div>
  </div>
</template>

<style scoped>
.rich-wrap { border:1px solid var(--border); border-radius:12px; overflow:hidden; background:var(--bg-alt) }
.rich-tb { display:flex; flex-wrap:wrap; gap:2px; padding:6px 8px; border-bottom:1px solid var(--border); background:var(--card); align-items:center; user-select:none }
.rich-tb button { min-width:30px; height:28px; padding:0 8px; border:1px solid transparent; border-radius:6px; background:transparent; color:var(--text-mute); font-size:12px; font-weight:600; cursor:pointer; font-family:inherit; transition:all .12s }
.rich-tb button:hover { background:var(--bg-alt); color:var(--text); border-color:var(--border) }
.rich-tb .sep { width:1px; height:18px; background:var(--border); margin:0 5px; flex:none }
.rich-ed { padding:13px 15px; font-size:13.5px; line-height:1.75; color:var(--text); outline:none; overflow-y:auto; max-height:56vh; word-break:break-word }
.rich-ed:empty::before { content:attr(data-ph); color:var(--text-mute); opacity:.55 }
.rich-ed :deep(p) { margin:0 0 9px }
.rich-ed :deep(h2) { font-size:17px; margin:12px 0 7px; font-weight:800 }
.rich-ed :deep(h3) { font-size:15px; margin:11px 0 6px; font-weight:700 }
.rich-ed :deep(ul), .rich-ed :deep(ol) { margin:4px 0 9px; padding-left:22px }
.rich-ed :deep(li) { margin:2px 0 }
.rich-ed :deep(a) { color:var(--primary) }
</style>
