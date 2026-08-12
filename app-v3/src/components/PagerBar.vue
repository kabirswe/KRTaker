<script setup>
// Shared pagination bar. Usage:
//   <PagerBar :page="page" :page-count="pageCount" :range="rangeLabel"
//             @set="setPage" />
defineProps({
  page: { type: Number, default: 1 },
  pageCount: { type: Number, default: 1 },
  range: { type: String, default: '' },
})
const emit = defineEmits(['set'])
import { t } from '../lib/i18n'
</script>

<template>
  <div v-if="pageCount > 1" class="pager">
    <span class="c-sub pager-range">{{ range }}</span>
    <div class="pager-btns">
      <button class="btn-ghost pager-btn" :disabled="page <= 1" @click="emit('set', page - 1)">← {{ t('Prev') }}</button>
      <span class="pager-page">{{ page }} / {{ pageCount }}</span>
      <button class="btn-ghost pager-btn" :disabled="page >= pageCount" @click="emit('set', page + 1)">{{ t('Next') }} →</button>
    </div>
  </div>
  <div v-else-if="range" class="pager pager-single">
    <span class="c-sub">{{ range }}</span>
  </div>
</template>

<style scoped>
.pager{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 16px;border-top:1px solid var(--border);font-size:12.5px}
.pager-btns{display:flex;align-items:center;gap:6px}
.pager-btn{padding:6px 12px;font-size:12px}
.pager-btn:disabled{opacity:.45;cursor:default}
.pager-page{display:inline-flex;align-items:center;padding:0 8px;font-weight:700;font-size:12.5px}
.pager-single{padding:10px 16px}
</style>
