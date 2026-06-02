<script setup>
import { ref } from 'vue'
import CrudResourceView from './_CrudResourceView.vue'

const config = {
  resourceKey: 'icd10',
  title: 'ICD-10',
  description: 'Klasifikasi diagnosa internasional (ICD-10). Centang "Eye-related" untuk diagnosa mata.',
  searchPlaceholder: 'Cari kode/deskripsi/Bahasa Indonesia…',
  extraSearchParam: 'search',
  csvShowTemplate: true,
  csvAllowExcel: true,
  defaults: {
    code: '', chapter: '', chapter_label: '', category: '',
    description: '', indonesian_description: '',
    is_eye_related: false, is_favorite: false,
  },
  columns: [
    { key: 'code',                   label: 'Kode',     width: '90px' },
    { key: 'category',               label: 'Cat',      width: '70px' },
    { key: 'description',            label: 'Deskripsi (EN)' },
    { key: 'indonesian_description', label: 'Deskripsi (ID)' },
    { key: 'is_eye_related',         label: 'Mata?',    width: '80px',  align: 'center' },
    { key: 'is_favorite',            label: 'Favorit?', width: '90px', align: 'center' },
  ],
  fields: [
    { key: 'code',                   label: 'Kode',         type: 'text',     required: true, cols: 1, placeholder: 'H40.9' },
    { key: 'category',               label: 'Kategori (parent)', type: 'text', cols: 1, placeholder: 'mis. H40' },
    { key: 'chapter',                label: 'Chapter',      type: 'text',     cols: 1, placeholder: 'VII' },
    { key: 'chapter_label',          label: 'Nama Chapter', type: 'text',     cols: 1, placeholder: 'Diseases of the eye and adnexa' },
    { key: 'description',            label: 'Deskripsi (EN)', type: 'textarea', required: true, cols: 2, rows: 2, placeholder: 'mis. Glaucoma, unspecified' },
    { key: 'indonesian_description', label: 'Deskripsi (ID)', type: 'textarea', cols: 2, rows: 2, placeholder: 'mis. Glaukoma, tidak spesifik' },
    { key: 'is_eye_related',         label: 'Eye-related (oftalmologi)', type: 'checkbox', cols: 1 },
    { key: 'is_favorite',            label: 'Favorit (sering dipakai)',  type: 'checkbox', cols: 1 },
  ],
  editFields: null,
  deleteLabel: (r) => `${r?.code} · ${r?.description}`,
}

config.editFields = config.fields.map((f) => f.key === 'code' ? { ...f, disabled: true } : f)

const filters = ref({ eye_related: '', favorite: '', category: '' })

function applyFilter(updateFn, key, value) {
  filters.value[key] = value
  updateFn(key, value === '' || value === false ? null : value)
}
</script>

<template>
  <CrudResourceView :config="config">
    <template #filters="{ updateFilter }">
      <span class="crv-filter-label">Kategori:</span>
      <input
        type="text"
        class="crv-filter-select"
        :value="filters.category"
        placeholder="filter (mis. H25)"
        style="min-width:120px"
        @keydown.enter="(e) => applyFilter(updateFilter, 'category', e.target.value.trim())"
        @blur="(e) => applyFilter(updateFilter, 'category', e.target.value.trim())"
      />

      <span class="crv-filter-label" style="margin-left:.6rem">Eye-related:</span>
      <button class="crv-chip" :class="{ active: filters.eye_related === '' }" @click="applyFilter(updateFilter, 'eye_related', '')">Semua</button>
      <button class="crv-chip" :class="{ active: filters.eye_related === 1 }" @click="applyFilter(updateFilter, 'eye_related', 1)">Mata</button>
      <button class="crv-chip" :class="{ active: filters.eye_related === 0 }" @click="applyFilter(updateFilter, 'eye_related', 0)">Non-mata</button>

      <span class="crv-filter-label" style="margin-left:.6rem">Favorit:</span>
      <button class="crv-chip" :class="{ active: filters.favorite === '' }" @click="applyFilter(updateFilter, 'favorite', '')">Semua</button>
      <button class="crv-chip" :class="{ active: filters.favorite === 1 }" @click="applyFilter(updateFilter, 'favorite', 1)">⭐</button>
    </template>

    <template #cell-category="{ value }">
      <span v-if="value" class="cell-tag">{{ value }}</span>
      <span v-else>—</span>
    </template>
  </CrudResourceView>
</template>

<style scoped>
.cell-tag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; background: var(--bs); color: var(--tm); border: 1px solid var(--gb); font-family: 'Geist Mono', monospace; }
</style>
