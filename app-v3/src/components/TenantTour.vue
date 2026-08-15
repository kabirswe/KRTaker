<script setup>
/* V2.39: first-login guided tour for tenant portal accounts (role=tenant).
   Dismissed once per browser via krtaker_tenant_tour_<email> (same pattern as the
   subscriber setup wizard). Covers: set password, NID copy + photo, family, move-in ack. */
import { ref } from 'vue'
import { t } from '../lib/i18n'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const auth = useAuthStore()
const email = auth.user?.email || ''

const steps = [
  { ico: '👋', title: 'Welcome to your tenant portal', body: 'This is your private portal for the unit you rent — your lease, rent invoices, payments, receipts, documents and maintenance tickets, all in one place.' },
  { ico: '🔑', title: 'Set your own password', body: 'Your temporary password was emailed to you. Change it now: open Settings (top-right) → Security → enter your current and new password.' },
  { ico: '🪪', title: 'Complete your profile', body: 'Upload your NID copy and a profile photo from My Portal → My Profile, so your landlord can verify your identity.' },
  { ico: '👨‍👩‍👧', title: 'Add family members', body: 'List who lives with you (name, relation, phone). Your landlord uses this for the thana registration form.' },
  { ico: '📋', title: 'Acknowledge the move-in checklist', body: 'Review the condition checklist of your flat on My Portal and tap “Acknowledge” when you agree with it.' },
]
const step = ref(0)
const done = ref(false)
const show = ref(true)

function finish() {
  done.value = true
  try { localStorage.setItem('krtaker_tenant_tour_' + email, '1') } catch (e) {}
}
function next() { if (step.value < steps.length - 1) step.value++; else finish() }
function skip() { finish() }
function goPortal() { finish(); router.push('/portal') }
</script>

<template>
  <div v-if="show && !done" class="tenant-tour-overlay">
    <div class="tenant-tour-card">
      <div class="tt-ico">{{ steps[step].ico }}</div>
      <h2>{{ steps[step].title }}</h2>
      <p>{{ steps[step].body }}</p>
      <div class="tt-dots">
        <span v-for="(s, i) in steps" :key="i" :class="{ on: i === step }"></span>
      </div>
      <div class="tt-actions">
        <button class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="skip">{{ t('Skip tour') }}</button>
        <div style="flex:1"></div>
        <button v-if="step >= 1" class="btn-ghost" style="padding:9px 16px;font-size:13px" @click="goPortal">{{ t('Open My Portal') }}</button>
        <button class="btn-primary" style="padding:9px 18px;font-size:13px" @click="next">{{ step < steps.length - 1 ? 'Next →' : 'Finish' }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.tenant-tour-overlay {
  position: fixed; inset: 0; background: rgba(10,20,40,.55); z-index: 220;
  display: flex; align-items: center; justify-content: center; padding: 20px;
}
.tenant-tour-card {
  background: var(--card, #fff); border-radius: 18px; max-width: 470px; width: 100%;
  padding: 28px 30px; box-shadow: 0 24px 70px rgba(0,0,0,.32);
}
.tt-ico { font-size: 44px; margin-bottom: 10px; }
h2 { margin: 0 0 10px; font-size: 21px; font-weight: 800; letter-spacing: -.3px; color: var(--text, #111); }
p { margin: 0 0 18px; font-size: 14px; line-height: 1.7; color: var(--text-mute, #556); }
.tt-dots { display: flex; gap: 7px; margin-bottom: 18px; }
.tt-dots span { width: 8px; height: 8px; border-radius: 50%; background: var(--border, #ddd); }
.tt-dots span.on { background: var(--primary, #2f80ed); width: 22px; border-radius: 5px; }
.tt-actions { display: flex; align-items: center; gap: 8px; }
</style>
