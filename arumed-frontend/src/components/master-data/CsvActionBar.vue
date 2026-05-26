<script setup>
/**
 * CsvActionBar — toolbar 3 tombol: Template / Import / Export.
 *
 * Props:
 *   resourceKey  : string  — key di REGISTRY masterDataStore
 *   showTemplate : boolean (default true)  — tarif tidak punya template-csv di backend, set false
 *
 * Internal:
 *   File input hidden — open via klik Import.
 *   Hasil import (inserted/updated/skipped/errors) di-render dalam result panel.
 *
 * Emits:
 *   imported (result) — { inserted, updated, skipped, errors[] }
 *   error    (msg)
 */
import { ref } from 'vue'
import { useMasterDataStore } from '@/stores/masterDataStore'

const props = defineProps({
  resourceKey:  { type: String, required: true },
  showTemplate: { type: Boolean, default: true },
})

const emit = defineEmits(['imported', 'error'])

const store = useMasterDataStore()

const fileInputRef = ref(null)
const busy = ref({ template: false, export: false, import: false })
const lastResult = ref(null)        // { inserted, updated, skipped, errors[] }
const lastErrMsg = ref(null)

function reset() {
  lastResult.value = null
  lastErrMsg.value = null
}

async function onTemplate() {
  reset()
  busy.value.template = true
  try {
    await store.downloadTemplate(props.resourceKey)
  } catch (e) {
    lastErrMsg.value = e.message ?? 'Gagal mengunduh template'
    emit('error', lastErrMsg.value)
  } finally {
    busy.value.template = false
  }
}

async function onExport() {
  reset()
  busy.value.export = true
  try {
    await store.exportCsv(props.resourceKey)
  } catch (e) {
    lastErrMsg.value = e.message ?? 'Gagal mengekspor CSV'
    emit('error', lastErrMsg.value)
  } finally {
    busy.value.export = false
  }
}

function pickFile() {
  reset()
  fileInputRef.value?.click()
}

async function onFilePicked(e) {
  const file = e.target.files?.[0]
  e.target.value = ''            // reset, supaya pilih file sama lagi tetap trigger
  if (!file) return

  busy.value.import = true
  try {
    const result = await store.importCsv(props.resourceKey, file)
    lastResult.value = result
    emit('imported', result)
  } catch (err) {
    lastErrMsg.value = err.response?.data?.message ?? err.message ?? 'Gagal mengimpor CSV'
    emit('error', lastErrMsg.value)
  } finally {
    busy.value.import = false
  }
}
</script>

<template>
  <div class="csv-bar">
    <div class="csv-actions">
      <button
        v-if="showTemplate"
        class="csv-btn csv-btn-template"
        :disabled="busy.template"
        @click="onTemplate"
      >
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 11v6M9 14h6"/></svg>
        {{ busy.template ? 'Mengunduh…' : 'Template CSV' }}
      </button>

      <button
        class="csv-btn csv-btn-import"
        :disabled="busy.import"
        @click="pickFile"
      >
        <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
        {{ busy.import ? 'Mengimpor…' : 'Import CSV' }}
      </button>

      <button
        class="csv-btn csv-btn-export"
        :disabled="busy.export"
        @click="onExport"
      >
        <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
        {{ busy.export ? 'Mengekspor…' : 'Export CSV' }}
      </button>

      <input
        ref="fileInputRef"
        type="file"
        accept=".csv,text/csv"
        @change="onFilePicked"
        style="display:none"
      />
    </div>

    <!-- Result panel: shown after import -->
    <div v-if="lastResult" class="csv-result" :class="{ 'csv-result-warn': lastResult.errors?.length }">
      <div class="csv-result-summary">
        <strong>Import selesai:</strong>
        <span class="csv-pill csv-pill-ok">{{ lastResult.inserted ?? 0 }} baru</span>
        <span class="csv-pill csv-pill-info">{{ lastResult.updated ?? 0 }} diperbarui</span>
        <span v-if="(lastResult.skipped ?? 0) > 0" class="csv-pill csv-pill-warn">{{ lastResult.skipped }} dilewati</span>
      </div>
      <ul v-if="lastResult.errors?.length" class="csv-errors">
        <li v-for="(err, i) in lastResult.errors" :key="i">{{ err }}</li>
      </ul>
    </div>

    <div v-if="lastErrMsg" class="csv-error-banner">{{ lastErrMsg }}</div>
  </div>
</template>

<style scoped>
.csv-bar { display: flex; flex-direction: column; gap: 0.7rem; }

.csv-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.csv-btn { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 9px; border: 1px solid var(--gb); background: var(--bc); color: var(--td); font-size: 12.5px; font-weight: 500; cursor: pointer; transition: background 0.15s, color 0.15s, border-color 0.15s; }
.csv-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.csv-btn:disabled { opacity: 0.55; cursor: not-allowed; }
.csv-btn-template:hover:not(:disabled) { background: var(--gl); border-color: var(--ga); color: var(--gd); }
.csv-btn-import:hover:not(:disabled) { background: var(--ib); border-color: var(--ibd); color: var(--it); }
.csv-btn-export:hover:not(:disabled) { background: var(--sb); border-color: var(--sbd); color: var(--st); }

.csv-result { padding: 0.8rem 1rem; background: var(--sb); border: 1px solid var(--sbd); border-radius: 10px; display: flex; flex-direction: column; gap: 0.6rem; }
.csv-result-warn { background: var(--wb); border-color: var(--wbd); }
.csv-result-summary { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; font-size: 13px; color: var(--td); }

.csv-pill { padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.csv-pill-ok { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.csv-pill-info { background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.csv-pill-warn { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }

.csv-errors { margin: 0; padding-left: 1.2rem; font-size: 12px; color: var(--wt); max-height: 200px; overflow-y: auto; }
.csv-errors li { margin: 2px 0; }

.csv-error-banner { padding: 0.7rem 1rem; background: var(--eb); border: 1px solid var(--ebd); border-radius: 10px; color: var(--et); font-size: 13px; }
</style>
