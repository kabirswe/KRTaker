<script setup>
// Horizontal labeled bars (ranking style). Zero deps.
import { computed } from 'vue'

const props = defineProps({
  rows: { type: Array, default: () => [] },     // [{ label, value, sub?, color? }]
  color: { type: String, default: 'var(--primary)' },
  fmt: { type: Function, default: (v) => String(Math.round(v || 0)) },
  empty: { type: String, default: 'No data.' },
})

const max = computed(() => Math.max(1, ...props.rows.map((r) => r.value || 0)))
</script>

<template>
  <div>
    <div v-for="(r, i) in rows" :key="r.label + i" style="margin-bottom:11px">
      <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-bottom:4px">
        <span style="font-size:12.5px;font-weight:700;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ r.label }}</span>
        <span style="font-size:12px;font-weight:800;color:var(--text);white-space:nowrap">{{ fmt(r.value) }}<span v-if="r.sub" style="color:var(--text-mute);font-weight:600;font-size:11px"> · {{ r.sub }}</span></span>
      </div>
      <div style="height:9px;background:var(--bg-alt);border-radius:6px;overflow:hidden">
        <div :style="{ width: Math.max(1.5, (r.value || 0) / max * 100) + '%', height: '100%', background: r.color || color, borderRadius: 6, opacity: .9 }"></div>
      </div>
    </div>
    <div v-if="!rows.length" class="c-sub">{{ empty }}</div>
  </div>
</template>
