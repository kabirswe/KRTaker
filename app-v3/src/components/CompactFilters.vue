<script setup>
// CompactFilters (V2.13.0) — collapses a toolbar/filter row into a compact
// dropdown on phones (≤640px); renders its slot INLINE on desktop so nothing
// changes on wide screens. Wrap every filter/search/select/secondary button;
// keep the primary action (＋ New) OUTSIDE so it stays visible on mobile.
import { ref } from 'vue'
const open = ref(false)
defineProps({
  label: { type: String, default: '⚙️ Filters' },
})
</script>

<template>
  <div class="cf">
    <button class="cf-btn" type="button" :aria-expanded="open" @click="open = !open">
      <span>{{ label }}</span><span class="cf-caret">{{ open ? '▴' : '▾' }}</span>
    </button>
    <div class="cf-panel" :class="{ open }">
      <slot />
      <button class="cf-close" type="button" @click="open = false">✓ Done</button>
    </div>
  </div>
</template>
