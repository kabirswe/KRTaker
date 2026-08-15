<script setup>
/* CSV Import Wizard (GO-LIVE 4.1) — shared by UnitsView + TenantsView.
   Three steps: template → paste/preview → commit. Mirrors app-import API. */
import { ref, computed } from 'vue'
import { t } from '../lib/i18n'
import { apiCall } from '../api/client'

const props = defineProps({
  collection: { type: String, required: true },   // 'units' | 'tenants'
  onDone: { type: Function, default: null },
})
const emit = defineEmits(['close', 'done'])

const step = ref('template')          // template → preview → done
const csv = ref('')
const tpl = ref('')
const columns = ref({})
const preview = ref(null)
const busy = ref(false)
const err = ref('')
const result = ref(null)

const title = computed(() => '⬆ ' + t('Import ' + props.collection + ' (CSV)'))
const noun = computed(() => ({ units: t('units imported'), tenants: t('tenants imported'), dues: t('dues imported') }[props.collection] || props.collection + ' imported'))

async function loadTemplate() {
  busy.value = true; err.value = ''
  try {
    const r = await apiCall('app-import', { action: 'template', collection: props.collection })
    if (!r.ok) { err.value = r.error || t('Failed to load template.'); return }
    tpl.value = r.csv || ''
    csv.value = r.csv || ''
    columns.value = r.columns || {}
    step.value = 'template'
  } catch (e) { err.value = e.message }
  finally { busy.value = false }
}
loadTemplate()

function onFile(e) {
  const f = e.target.files?.[0]
  if (!f) return
  const rd = new FileReader()
  rd.onload = () => { csv.value = String(rd.result || '') }
  rd.readAsText(f)
}

async function doPreview() {
  busy.value = true; err.value = ''
  try {
    const r = await apiCall('app-import', { action: 'preview', collection: props.collection, csv: csv.value })
    if (!r.ok) { err.value = r.error || t('Preview failed.'); return }
    preview.value = r
    step.value = 'preview'
  } catch (e) { err.value = e.message }
  finally { busy.value = false }
}

async function doCommit() {
  busy.value = true; err.value = ''
  try {
    const r = await apiCall('app-import', { action: 'commit', collection: props.collection, csv: csv.value })
    if (!r.ok) { err.value = r.error || t('Commit failed.'); return }
    result.value = r
    step.value = 'done'
    if (props.onDone) props.onDone()
    emit('done')
  } catch (e) { err.value = e.message }
  finally { busy.value = false }
}

function close() { emit('close') }
</script>

<template>
  <div style="position:fixed;inset:0;background:rgba(16,24,40,.55);z-index:1200;display:flex;align-items:center;justify-content:center;padding:20px" @click.self="close">
    <div style="background:var(--bg,#fff);border-radius:18px;max-width:760px;width:100%;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.28)">
      <div style="padding:18px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
        <h3 style="font-size:16px;font-weight:800;margin:0">{{ title }}</h3>
        <button @click="close" style="background:none;border:none;font-size:18px;cursor:pointer;color:var(--text-mute)" :title="t('Close')">✕</button>
      </div>

      <div style="padding:20px 22px;overflow:auto;flex:1">
        <!-- STEP: template -->
        <div v-if="step === 'template'">
          <p style="font-size:13px;color:var(--text-mute);margin:0 0 12px">{{ t('Paste your CSV below (or pick a file). Headers must match the template — invalid rows are skipped safely.') }}</p>
          <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:12px 14px;margin-bottom:14px;font-size:12px;color:var(--text-mute);line-height:1.7">
            <b style="color:var(--text)">{{ t('Columns:') }}</b>
            <span v-for="(d,k) in columns" :key="k" style="display:inline-block;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:3px 8px;margin:3px 4px 3px 0"><code style="font-weight:700">{{ k }}</code> · {{ d }}</span>
          </div>
          <textarea v-model="csv" rows="10" spellcheck="false" style="width:100%;box-sizing:border-box;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;padding:12px;border:1px solid var(--border);border-radius:12px;background:var(--bg);color:var(--text);outline:none;resize:vertical"></textarea>
          <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
            <button class="btn-ghost" style="font-size:12.5px" @click="csv = tpl">↺ Reset to template</button>
            <label class="btn-ghost" style="font-size:12.5px;cursor:pointer">📄 Choose CSV file<input type="file" accept=".csv,text/csv,text/plain" style="display:none" @change="onFile"></label>
          </div>
        </div>

        <!-- STEP: preview -->
        <div v-if="step === 'preview'">
          <div style="display:flex;gap:14px;margin-bottom:14px;flex-wrap:wrap">
            <div style="background:rgba(46,204,113,.1);border:1px solid rgba(46,204,113,.3);border-radius:12px;padding:10px 16px"><div style="font-size:11px;color:var(--text-mute)">{{ t('VALID') }}</div><div style="font-size:20px;font-weight:800;color:var(--ok)">{{ preview.valid }}</div></div>
            <div style="background:rgba(231,76,60,.08);border:1px solid rgba(231,76,60,.25);border-radius:12px;padding:10px 16px"><div style="font-size:11px;color:var(--text-mute)">{{ t('SKIPPED') }}</div><div style="font-size:20px;font-weight:800;color:var(--danger)">{{ preview.invalid }}</div></div>
            <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:12px;padding:10px 16px"><div style="font-size:11px;color:var(--text-mute)">{{ t('TOTAL ROWS') }}</div><div style="font-size:20px;font-weight:800;color:var(--text)">{{ preview.total }}</div></div>
          </div>
          <div v-if="preview.limit_error" style="background:rgba(231,76,60,.08);border:1px solid rgba(231,76,60,.3);border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:12.5px;color:var(--danger);font-weight:600">⚠️ {{ preview.limit_error }}</div>
          <div style="max-height:280px;overflow:auto;border:1px solid var(--border);border-radius:12px">
            <table style="width:100%;border-collapse:collapse;font-size:12px">
              <thead><tr style="background:var(--bg-alt)"><th style="padding:8px 10px;text-align:left;position:sticky;top:0;background:var(--bg-alt)">{{ t('Line') }}</th><th style="padding:8px 10px;text-align:left;position:sticky;top:0;background:var(--bg-alt)">{{ t('Data') }}</th><th style="padding:8px 10px;text-align:left;position:sticky;top:0;background:var(--bg-alt)">{{ t('Status') }}</th></tr></thead>
              <tbody>
                <tr v-for="row in preview.rows" :key="row.line" :style="row.ok ? '' : 'background:rgba(231,76,60,.05)'">
                  <td style="padding:7px 10px;color:var(--text-mute)">{{ row.line }}</td>
                  <td style="padding:7px 10px;font-family:ui-monospace,Menlo,monospace;font-size:11.5px">{{ Object.values(row.data).join(' · ') }}</td>
                  <td style="padding:7px 10px"><span v-if="row.ok" style="color:var(--ok);font-weight:700">✓</span><span v-else style="color:var(--danger);font-size:11px">{{ row.errors.join('; ') }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- STEP: done -->
        <div v-if="step === 'done'">
          <div style="text-align:center;padding:26px 10px">
            <div style="font-size:42px;margin-bottom:10px">✅</div>
            <div style="font-size:18px;font-weight:800;margin-bottom:6px">{{ result.created }} {{ noun }}</div>
            <div v-if="result.provisioned" style="font-size:13px;color:var(--text-mute);margin-bottom:4px">🔑 {{ result.provisioned }} tenant portal account(s) created + welcome email sent</div>
            <div v-if="result.invalid" style="font-size:13px;color:var(--danger);margin-top:6px">{{ result.invalid }} row(s) skipped (invalid)</div>
            <div v-if="result.created_ids?.length" style="font-size:11.5px;color:var(--text-mute);margin-top:8px">IDs: {{ result.created_ids.join(', ') }}</div>
          </div>
        </div>
      </div>

      <div v-if="err" style="padding:10px 22px;background:rgba(231,76,60,.08);border-top:1px solid rgba(231,76,60,.25);font-size:12.5px;color:var(--danger);font-weight:600">⚠️ {{ err }}</div>

      <div style="padding:14px 22px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:10px">
        <div style="display:flex;gap:8px">
          <button v-if="step === 'preview'" class="btn-ghost" style="font-size:12.5px" @click="step = 'template'">← Back</button>
        </div>
        <div style="display:flex;gap:8px">
          <button class="btn-ghost" style="font-size:12.5px" @click="close">{{ t('Cancel') }}</button>
          <button v-if="step === 'template'" class="btn-primary" style="padding:9px 18px;font-size:13px" :disabled="busy || !csv.trim()" @click="doPreview">{{ busy ? 'Checking…' : 'Preview →' }}</button>
          <button v-if="step === 'preview'" class="btn-primary" style="padding:9px 18px;font-size:13px" :disabled="busy || preview.valid === 0 || preview.limit_error" @click="doCommit">{{ busy ? 'Importing…' : 'Import ' + preview.valid + ' row' + (preview.valid === 1 ? '' : 's') + ' →' }}</button>
          <button v-if="step === 'done'" class="btn-primary" style="padding:9px 18px;font-size:13px" @click="close">{{ t('Done') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
