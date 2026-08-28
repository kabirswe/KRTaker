<script setup>
// Donut chart (SVG circles + HTML legend). Zero deps, theme-aware.
import { computed } from 'vue'

const props = defineProps({
  segments: { type: Array, default: () => [] }, // [{ label, value, color }]
  size: { type: Number, default: 170 },
  thickness: { type: Number, default: 22 },
  centerLabel: { type: String, default: '' },
  centerValue: { type: String, default: '' },
  fmt: { type: Function, default: (v) => String(Math.round(v || 0)) },
})

const total = computed(() => props.segments.reduce((s, x) => s + (x.value || 0), 0))
const r = computed(() => (props.size - props.thickness) / 2)
const circ = computed(() => 2 * Math.PI * r.value)
const c = computed(() => props.size / 2)

const arcs = computed(() => {
  let cum = 0
  return props.segments.map((s) => {
    const frac = total.value ? (s.value || 0) / total.value : 0
    const arc = { ...s, frac, dash: (frac * circ.value).toFixed(2), off: (-cum * circ.value).toFixed(2), pct: Math.round(frac * 100) }
    cum += frac
    return arc
  })
})
</script>

<template>
  <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">
    <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`" style="flex-shrink:0">
      <circle :cx="c" :cy="c" :r="r" fill="none" stroke="var(--bg-alt)" :stroke-width="thickness" />
      <circle v-for="a in arcs" :key="a.label" :cx="c" :cy="c" :r="r" fill="none" :stroke="a.color" :stroke-width="thickness"
        :stroke-dasharray="a.dash + ' ' + circ.toFixed(2)" :stroke-dashoffset="a.off" :transform="'rotate(-90 ' + c + ' ' + c + ')'" stroke-linecap="butt">
        <title>{{ a.label }}: {{ fmt(a.value) }} ({{ a.pct }}%)</title>
      </circle>
      <text v-if="centerValue !== ''" :x="c" :y="c - 2" text-anchor="middle" font-size="17" font-weight="800" fill="var(--text)">{{ centerValue }}</text>
      <text v-if="centerLabel" :x="c" :y="c + 16" text-anchor="middle" font-size="10.5" fill="var(--text-mute)" font-weight="600">{{ centerLabel }}</text>
    </svg>
    <div style="flex:1;min-width:150px">
      <div v-for="a in arcs" :key="a.label" style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:3px 0;font-size:12.5px">
        <span style="display:inline-flex;align-items:center;gap:7px;font-weight:600;color:var(--text)">
          <i :style="{ width: 9, height: 9, borderRadius: 3, background: a.color, display: 'inline-block', flexShrink: 0 }"></i>{{ a.label }}
        </span>
        <span style="font-weight:800;color:var(--text)">{{ fmt(a.value) }} <span style="color:var(--text-mute);font-weight:600">· {{ a.pct }}%</span></span>
      </div>
      <div v-if="!segments.length" class="c-sub">No data.</div>
    </div>
  </div>
</template>
