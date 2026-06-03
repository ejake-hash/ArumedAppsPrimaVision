<script setup>
import { ref } from 'vue'
import CrudResourceView from './_CrudResourceView.vue'

const BHP_CATEGORIES = [
  { value: 'MEDICAL_BHP',      label: 'BHP Medis' },
  { value: 'CSSD',             label: 'CSSD (Sterilisasi)' },
  { value: 'INSTRUMENT_SET',   label: 'Set Instrumen Bedah' },
]

// Kategori reusable (disteril/dipakai ulang) — tidak butuh stok minimum & kadaluwarsa.
const REUSABLE_CATEGORIES = ['CSSD', 'INSTRUMENT_SET']
const isConsumable = (form) => !REUSABLE_CATEGORIES.includes(form.category)

const config = {
  resourceKey: 'bhp',
  title: 'BHP',
  description: 'Master Bahan Habis Pakai medis & non-medis.',
  searchPlaceholder: 'Cari nama/kategori/pabrik…',
  extraSearchParam: 'search',
  csvAllowExcel: true,
  csvShowTemplate: true,
  defaults: {
    name: '', category: 'MEDICAL_BHP', unit: '', manufacturer: '',
    min_stock: 0,
    expiry_date: '', batch_number: '', description: '',
    is_active: true,
  },
  columns: [
    { key: 'name',         label: 'Nama BHP' },
    { key: 'category',     label: 'Kategori', width: '160px' },
    { key: 'unit',         label: 'Satuan',   width: '80px' },
    { key: 'manufacturer', label: 'Pabrik',   width: '150px' },
    { key: 'is_active',    label: 'Status',   width: '90px', align: 'center' },
  ],
  fields: [
    { key: 'name',         label: 'Nama BHP',            type: 'text',     required: true, cols: 1 },
    { key: 'category',     label: 'Kategori',            type: 'select',   required: true, cols: 1, options: BHP_CATEGORIES },
    { key: 'unit',         label: 'Satuan',              type: 'text',     cols: 1, placeholder: 'pcs / pack / roll' },
    { key: 'manufacturer', label: 'Pabrik',              type: 'text',     cols: 1 },
    { key: 'min_stock',    label: 'Stok Minimum',        type: 'number',   min: 0, cols: 1, hint: 'Threshold alert stok rendah', showIf: isConsumable },
    { key: 'expiry_date',  label: 'Tanggal Kadaluwarsa', type: 'date',     cols: 1, showIf: isConsumable },
    { key: 'batch_number', label: 'No. Batch',           type: 'text',     cols: 1 },
    { key: 'description',  label: 'Deskripsi/Catatan',   type: 'textarea', cols: 2, rows: 2 },
    { key: 'is_active',    label: 'Aktif',               type: 'checkbox', cols: 1 },
  ],
  editFields: null,
  deleteLabel: (r) => r?.name,
}

config.editFields = config.fields

const filters = ref({ active: '', low_stock: false, category: '' })

function applyFilter(updateFn, key, value) {
  filters.value[key] = value
  updateFn(key, value === '' || value === false ? null : value)
}

function toggleLowStock(updateFn) {
  filters.value.low_stock = !filters.value.low_stock
  updateFn('low_stock', filters.value.low_stock ? 1 : null)
}

function labelCategory(v) {
  return BHP_CATEGORIES.find((c) => c.value === v)?.label ?? v
}
</script>

<template>
  <CrudResourceView :config="config">
    <template #filters="{ updateFilter }">
      <span class="crv-filter-label">Kategori:</span>
      <select class="crv-filter-select" v-model="filters.category" @change="applyFilter(updateFilter, 'category', filters.category || null)">
        <option value="">Semua</option>
        <option v-for="c in BHP_CATEGORIES" :key="c.value" :value="c.value">{{ c.label }}</option>
      </select>

      <span class="crv-filter-label" style="margin-left:.6rem">Status:</span>
      <button class="crv-chip" :class="{ active: filters.active === '' }" @click="applyFilter(updateFilter, 'active', '')">Semua</button>
      <button class="crv-chip" :class="{ active: filters.active === 1 }" @click="applyFilter(updateFilter, 'active', 1)">Aktif</button>
      <button class="crv-chip" :class="{ active: filters.active === 0 }" @click="applyFilter(updateFilter, 'active', 0)">Nonaktif</button>

      <button class="crv-chip" :class="{ active: filters.low_stock }" style="margin-left:.6rem" @click="toggleLowStock(updateFilter)" title="Stok ≤ minimum">
        Stok Rendah
      </button>
    </template>

    <template #cell-category="{ value }">
      <span v-if="value" class="cell-tag" :data-cat="value">{{ labelCategory(value) }}</span>
      <span v-else>—</span>
    </template>
  </CrudResourceView>
</template>

<style scoped>
.cell-tag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.cell-tag[data-cat="CSSD"]             { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
.cell-tag[data-cat="INSTRUMENT_SET"]   { background: #ede9fe; color: #5b21b6; border-color: #c4b5fd; }
.cell-tag[data-cat="MEDICAL_BHP"]      { background: #d1fae5; color: #065f46; border-color: #6ee7b7; }
</style>
