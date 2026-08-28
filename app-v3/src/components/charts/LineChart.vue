<script setup>
// Multi-series SVG line/area chart — zero deps, theme-aware (CSS vars), title tooltips.
import { computed } from 'vue'

const props = defineProps({
  series: { type: Array, default: () => [] },   // [{ name, color, points: [num...] }]
  labels: { type: Array, default: () => [] },   // x-axis labels (aligned to points)
  height: { type: Number, default: 230 },
  fmt: { type: Function, default: (v) => String(Math.round(v || 0)) },
  area: { type: Boolean, default: true },       // soft fill under the first series
})

const W = 720, PAD_L = 10, PAD_R = 10, PAD_T = 16, PAD_B = 24
const H = 240
const n = computed(() => Math.max(2, props.labels.length))
const maxV = computed(() => {
  let m = 0
  for (const s of props.series) for (const p of s.points || []) m = Math.max(m, p)
  return m <= 0 ? 1 : Math.ceil(m * 1.08 / 10) * 10
})
const x = (i) => PAD_L + (n.value === 1 ? 0 : i * (W - PAD_L - PAD_R) / (n.value - 1))
const y = (v) => PAD_T + (1 - (v || 0) / maxV.value) * (H - PAD_T - PAD_B)

const grid = computed(() => {
  const rows = []
  for (let g = 0; g <= 4; g++) rows.push({ y: PAD_T + g * (H - PAD_T - PAD_B) / 4, val: Math.round(maxV.value * (1 - g / 4)) })
  return rows
})
const linePath = (points) => (points || []).map((p, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(p).toFixed(1)).join(' ')
const areaPath = (points) => (points || []).length ? linePath(points) + ` L${x((points || []).length - 1).toFixed(1)} ${(H - PAD_B).toFixed(1)} L${x(0).toFixed(1)} ${(H - PAD_B).toFixed(1)} Z` : ''
const labelEvery = computed(() => Math.max(1, Math.ceil(n.value / 9)))
</script>

<template>
  <div>
    <div v-if="series.length > 1" style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:8px">
      <span v-for="s in series" :key="s.name" style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--text-mute)">
        <i :style="{ width: 10, height: 3, borderRadius: 2, background: s.color, display: 'inline-block' }"></i>{{ s.name }}
      </span>
    </div>
    <svg :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none" :style="{ height: height + 'px', width: '100%', display: 'block' }">
      <line v-for="g in grid" :key="'g' + g.y" :x1="0" :x2="W" :y1="g.y" :y2="g.y" stroke="var(--border)" stroke-width="1" stroke-dasharray="3 5" opacity=".6" />
      <path v-if="area && series[0]" :d="areaPath(series[0].points)" :fill="series[0].color" fill-opacity=".07" stroke="none" />
      <path v-for="s in series" :key="s.name" :d="linePath(s.points)" fill="none" :stroke="s.color" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round" opacity=".95" />
      <g v-for="(s, si) in series" :key="'d' + s.name">
        <circle v-for="(p, i) in s.points" :key="s.name + i" :cx="x(i).toFixed(1)" :cy="y(p).toFixed(1)" r="3" :fill="s.color" stroke="#fff" stroke-width="1.2">
          <title>{{ props.labels[i] }} · {{ s.name }}: {{ fmt(p) }}</title>
        </circle>
      </g>
      <g>
        <text v-for="(lb, i) in labels" :key="'x' + i" v-show="i % labelEvery === 0 || i === labels.length - 1" :x="x(i).toFixed(1)" :y="H - 6" text-anchor="middle" font-size="10.5" fill="var(--text-mute)" font-weight="600">{{ lb }}</text>
      </g>
    </svg>
  </div>
</template>
