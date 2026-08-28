<script setup>
import { ref, nextTick } from 'vue'
import { t } from '../lib/i18n'
import { useDataStore } from '../stores/data'
import { apiCall } from '../api/client'

const data = useDataStore()
const q = ref('')
const busy = ref(false)
const msgs = ref([])
const box = ref(null)

const suggestions = [
  'Which tenants are in arrears this month?',
  'What is the total outstanding rent?',
  'Which lease is expiring next?',
  'Summarize open maintenance tickets',
  'How many units are vacant?',
  'What did we collect this month?',
]

async function ask(text) {
  const prompt = (text ?? q.value).trim()
  if (!prompt || busy.value) return
  msgs.value.push({ role: 'user', text: prompt })
  q.value = ''
  busy.value = true
  try {
    // API expects OpenAI-style messages array; send last 12 for context.
    const history = msgs.value.slice(-12).map(m => ({ role: m.role === 'user' ? 'user' : 'assistant', content: m.text }))
    const r = await apiCall('app-ai-chat', { messages: history })
    msgs.value.push({ role: 'ai', text: (r.ok ? (r.reply || r.answer || JSON.stringify(r).slice(0, 600)) : ('AI error: ' + (r.error || 'no reply'))) })
  } catch (e) {
    msgs.value.push({ role: 'ai', text: 'Network error.' })
  } finally {
    busy.value = false
    scroll()
  }
}

function scroll() { nextTick(() => { if (box.value) box.value.scrollTop = box.value.scrollHeight }) }
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>{{ t('🤖 AI Caretaker (KR)') }}</h1>
        <div class="sub">Ask about your portfolio — rent, arrears, compliance, anything</div>
      </div>
    </div>

    <div class="panel" style="max-width:760px;display:flex;flex-direction:column;max-height:72vh">
      <div class="panel-b" style="flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:12px" ref="box">
        <div v-if="!msgs.length" class="c-sub" style="text-align:center;padding:26px 10px 10px">
          Try a question:
        </div>
        <div v-if="!msgs.length" style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center;padding-bottom:14px">
          <button v-for="s in suggestions" :key="s" @click="ask(s)" class="btn-ghost" style="font-size:12px">{{ s }}</button>
        </div>

        <div v-for="(m, i) in msgs" :key="i" :style="{ alignSelf: m.role === 'user' ? 'flex-end' : 'flex-start', maxWidth: '82%' }">
          <div v-if="m.role === 'user'" style="background:var(--primary);color:#fff;padding:10px 14px;border-radius:14px 14px 4px 14px;font-size:13.5px;white-space:pre-wrap">{{ m.text }}</div>
          <div v-else style="background:var(--bg-alt);border:1px solid var(--border);padding:12px 15px;border-radius:14px 14px 14px 4px;font-size:13.5px;white-space:pre-wrap;line-height:1.7">{{ m.text }}</div>
        </div>

        <div v-if="busy" style="align-self:flex-start;background:var(--bg-alt);border:1px solid var(--border);padding:11px 15px;border-radius:14px;font-size:13px;color:var(--text-mute)">🤔 KR is thinking…</div>

        <div v-if="data.offline" class="c-sub" style="text-align:center;font-size:12px">📡 Offline — AI requires a connection.</div>
      </div>

      <div style="display:flex;gap:10px;padding:14px 18px;border-top:1px solid var(--border)">
        <input v-model="q" :placeholder="t('Ask about rent, tickets, leases…')" @keyup.enter="ask()" style="flex:1;padding:12px 14px;border:1px solid var(--border);border-radius:10px;background:var(--bg);color:var(--text);font-family:inherit;font-size:13.5px;outline:none">
        <button class="btn-primary" :disabled="busy || !q.trim()" @click="ask()">{{ busy ? 'Thinking…' : 'Send ➤' }}</button>
      </div>
    </div>
  </div>
</template>
