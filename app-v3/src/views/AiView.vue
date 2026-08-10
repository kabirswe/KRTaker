<script setup>
import { ref } from 'vue'
import { useDataStore } from '../stores/data'
import { apiCall } from '../api/client'

const data = useDataStore()
const q = ref('')
const busy = ref(false)
const result = ref('')

async function ask() {
  if (!q.value.trim() || busy.value) return
  busy.value = true; result.value = ''
  try {
    const r = await apiCall('app-ai-chat', { q: q.value })
    if (r.ok) result.value = r.reply || r.answer || JSON.stringify(r).slice(0, 500)
    else result.value = 'AI error: ' + (r.error || 'no reply')
  } catch (e) {
    result.value = 'Network error.'
  } finally { busy.value = false }
}
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>🤖 AI Caretaker (KR)</h1>
        <div class="sub">Ask about your portfolio — rent, arrears, compliance, anything</div>
      </div>
    </div>

    <div class="panel" style="max-width:760px">
      <div class="panel-b">
        <div style="display:flex;gap:10px">
          <input v-model="q" placeholder="e.g. Which tenants are in arrears this month?" @keyup.enter="ask" style="flex:1;padding:12px 14px;border:1px solid var(--border);border-radius:10px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13.5px;outline:none">
          <button class="btn-primary" :disabled="busy" @click="ask">{{ busy ? 'Thinking…' : 'Ask KR' }}</button>
        </div>
        <div v-if="result" style="margin-top:16px;padding:16px;background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;font-size:13.5px;white-space:pre-wrap;line-height:1.7">{{ result }}</div>
        <div v-if="data.offline" class="c-sub" style="margin-top:10px">📡 Offline — AI requires a connection.</div>
      </div>
    </div>
  </div>
</template>
