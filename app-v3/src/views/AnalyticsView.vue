<script setup>
import { computed } from 'vue'
import { useDataStore } from '../stores/data'

const data = useDataStore()
const money = n => '৳' + Math.round(n || 0).toLocaleString('en-IN')

const invoices = computed(() => data.list('invoices'))
const receipts = computed(() => data.list('receipts'))
const payments = computed(() => data.list('payments'))

const billed = computed(() => invoices.value.reduce((a, i) => a + (i.net || 0), 0))
const collected = computed(() => payments.value.reduce((a, p) => a + (p.amount || 0), 0))
const outstanding = computed(() => {
  let o = 0
  invoices.value.forEach(i => { if (i.status !== 'Paid') o += (i.net || 0) })
  return o
})
const byStatus = computed(() => {
  const m = {}
  invoices.value.forEach(i => { const k = i.status || '—'; m[k] = (m[k] || 0) + 1 })
  return Object.entries(m)
})
const byMonth = computed(() => {
  const m = {}
  invoices.value.forEach(i => { const k = i.m || '—'; m[k] = (m[k] || 0) + (i.net || 0) })
  return Object.entries(m).sort((a, b) => b[0].localeCompare(a[0])).slice(0, 6)
})
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h1>📈 Analytics</h1>
        <div class="sub">Billing, collections, arrears — live from API</div>
      </div>
    </div>

    <div class="stats">
      <div class="stat"><div class="s-label"><span class="s-ico">🧾</span>Total billed</div><div class="s-value">{{ money(billed) }}</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">💰</span>Collected</div><div class="s-value">{{ money(collected) }}</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">⏳</span>Outstanding</div><div class="s-value" style="color:var(--danger)">{{ money(outstanding) }}</div></div>
      <div class="stat"><div class="s-label"><span class="s-ico">📄</span>Invoices</div><div class="s-value">{{ invoices.length }}</div></div>
    </div>

    <div class="grid grid-2">
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">🧾</span>Invoices by status</div></div>
        <div class="panel-b">
          <div v-for="[k, v] in byStatus" :key="k" class="kv"><span class="k">{{ k }}</span><span class="v">{{ v }}</span></div>
          <div v-if="!byStatus.length" class="c-sub">No data.</div>
        </div>
      </div>
      <div class="panel">
        <div class="panel-h"><div class="t"><span class="pi">📅</span>Billing by month</div></div>
        <div class="panel-b">
          <div v-for="[k, v] in byMonth" :key="k" class="kv"><span class="k">{{ k }}</span><span class="v">{{ money(v) }}</span></div>
          <div v-if="!byMonth.length" class="c-sub">No data.</div>
        </div>
      </div>
    </div>
  </div>
</template>
