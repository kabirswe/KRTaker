<script setup>
// ScrollTabs (V2.13.0) — wraps a .kr-tabs strip with ◀ ▶ arrow buttons so
// phone users can page through the scrollable tab strip. Arrows appear only
// when the strip actually overflows (mobile) and auto-disable at the ends.
// Desktop: strip never overflows → arrows never render → zero change.
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'

defineOptions({ inheritAttrs: false })

const strip = ref(null)
const showPrev = ref(false)
const showNext = ref(false)
let ticking = false

function update() {
  const el = strip.value
  if (!el) return
  const max = el.scrollWidth - el.clientWidth
  showPrev.value = el.scrollLeft > 4
  showNext.value = max > 4 && el.scrollLeft < max - 4
}
function onScroll() {
  if (ticking) return
  ticking = true
  requestAnimationFrame(() => { update(); ticking = false })
}
function scroll(dir) {
  const el = strip.value
  if (!el) return
  el.scrollBy({ left: dir * Math.max(140, Math.round(el.clientWidth * 0.6)), behavior: 'smooth' })
}
let timers = []
onMounted(() => {
  timers.push(setTimeout(update, 80), setTimeout(update, 400))
  const el = strip.value
  if (el) el.addEventListener('scroll', onScroll, { passive: true })
  window.addEventListener('resize', onScroll, { passive: true })
})
onBeforeUnmount(() => {
  timers.forEach(t => clearTimeout(t))
  const el = strip.value
  if (el) el.removeEventListener('scroll', onScroll)
  window.removeEventListener('resize', onScroll)
})
</script>

<template>
  <div class="kr-tabs-scroll">
    <button v-if="showPrev" class="kr-scroll-btn kr-scroll-prev" type="button" aria-label="Scroll tabs left" @click="scroll(-1)">◀</button>
    <div ref="strip" class="kr-tabs" v-bind="$attrs"><slot /></div>
    <button v-if="showNext" class="kr-scroll-btn kr-scroll-next" type="button" aria-label="Scroll tabs right" @click="scroll(1)">▶</button>
  </div>
</template>
