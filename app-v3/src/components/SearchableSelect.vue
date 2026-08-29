<template>
  <div ref="root" style="position:relative;width:100%">
    <button type="button" class="ssel-btn" @click.stop="open = !open" style="width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px;border-radius:10px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:13px;cursor:pointer;text-align:left">
      <span :style="!modelValue ? 'color:var(--text-mute)' : ''" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ selectedLabel || placeholder }}</span>
      <span style="color:var(--text-mute);font-size:10px;flex-shrink:0">▾</span>
    </button>
    <div v-if="open" class="ssel-panel" style="position:absolute;top:calc(100% + 6px);left:0;right:0;z-index:120;background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:0 18px 50px rgba(0,0,0,.18);overflow:hidden">
      <div style="padding:8px;border-bottom:1px solid var(--border)">
        <input v-model="q" class="ssel-q" :placeholder="t('Search') + ' ' + (placeholder || '').toLowerCase() + '…'" @keydown.esc="open = false" style="width:100%;padding:8px 10px;border-radius:8px;border:1px solid var(--border);background:var(--bg-alt);color:var(--text);font-family:inherit;font-size:12.5px;outline:none" />
      </div>
      <div style="max-height:220px;overflow-y:auto;padding:4px">
        <div v-for="o in filtered" :key="String(o.value)" class="ssel-item" :class="{ sel: o.value === modelValue }" @click="pick(o)" style="padding:8px 10px;border-radius:8px;font-size:12.5px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ o.label }}</div>
        <div v-if="!filtered.length" style="padding:10px;font-size:12px;color:var(--text-mute);text-align:center">{{ t('No matches') }}</div>
      </div>
      <div v-if="allowAdd" class="ssel-add" @click.stop="onAdd" style="padding:10px;border-top:1px dashed var(--border);font-size:12.5px;font-weight:800;color:var(--primary);cursor:pointer;text-align:center;background:var(--bg-alt)">＋ {{ t(addLabel || 'Add new') }}</div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { t } from '../lib/i18n'

const props = defineProps({
  modelValue: { default: '' },
  options: { type: Array, default: () => [] },   // [{ value, label }]
  placeholder: { type: String, default: 'Select…' },
  allowAdd: { type: Boolean, default: false },
  addLabel: { type: String, default: 'Add new' },
})
const emit = defineEmits(['update:modelValue', 'add'])

const open = ref(false)
const q = ref('')
const root = ref(null)

const selectedLabel = computed(() => {
  if (props.modelValue === '' || props.modelValue === null || props.modelValue === undefined) return ''
  const o = props.options.find(x => x.value === props.modelValue)
  return o ? o.label : ''
})
const filtered = computed(() => {
  const s = q.value.trim().toLowerCase()
  if (!s) return props.options
  return props.options.filter(o => o.label.toLowerCase().includes(s))
})

function pick(o) {
  emit('update:modelValue', o.value)
  open.value = false
  q.value = ''
}
function onAdd() {
  open.value = false
  q.value = ''
  emit('add')
}
function onDocClick(e) {
  if (root.value && !root.value.contains(e.target)) open.value = false
}
watch(open, v => { if (v) q.value = '' })
document.addEventListener('mousedown', onDocClick)
onBeforeUnmount(() => document.removeEventListener('mousedown', onDocClick))
</script>

<style scoped>
.ssel-item:hover { background: var(--bg-alt) }
.ssel-item.sel { background: rgba(47, 128, 237, .12); color: var(--primary); font-weight: 700 }
</style>
