<script setup>
import { ref } from 'vue'
import CrudResourceView from './_CrudResourceView.vue'

const config = {
  resourceKey: 'bhp',
  title: 'BHP',
  description: 'Master Bahan Habis Pakai medis & non-medis.',
  searchPlaceholder: 'Cari nama/kategori/pabrik…',
  extraSearchParam: 'search',
  csvShowTemplate: true,
  defaults: {
    name: '', category: '', unit: '', manufacturer: '',
    min_stock: 0,
    expiry_date: '', batch_number: '', description: '',
    is_active: true,
  },
  columns: [
    { key: 'name',         label: 'Nama BHP' },
    { key: 'category',     label: 'Kategori', width: '130px' },
    { key: 'unit',         label: 'Satuan',   width: '80px' },
    { key: 'manufacturer', label: 'Pabrik',   width: '150px' },
    { key: 'is_active',    label: 'Status',   width: '90px', align: 'center' },
  ],
  fields: [
    { key: 'name',         label: 'Nama BHP',            type: 'text',     required: true, cols: 1 },
    { key: 'category',     label: 'Kategori',            type: 'text',     cols: 1, placeholder: 'mis. Operasi / Poli / Steril' },
    { key: 'unit',         label: 'Satuan',              type: 'text',     cols: 1, placeholder: 'pcs / pack / roll' },
    { key: 'manufacturer', label: 'Pabrik',              type: 'text',     cols: 1 },
    { key: 'min_stock',    label: 'Stok Minimum',        type: 'number',   min: 0, cols: 1, hint: 'Threshold alert stok rendah' },
    { key: 'expiry_date',  label: 'Tanggal Kadaluwarsa', type: 'date',     cols: 1 },
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
</script>

<template>
  <CrudResourceView :config="config">
    <template #filters="{ updateFilter }">
      <span class="crv-filter-label">Kategori:</span>
      <input
        type="text"
        class="crv-filter-select"
        :value="filters.category"
        placeholder="filter kategori…"
        style="min-width:140px"
        @keydown.enter="(e) => applyFilter(updateFilter, 'category', e.target.value.trim())"
        @blur="(e) => applyFilter(updateFilter, 'category', e.target.value.trim())"
      />

      <span class="crv-filter-label" style="margin-left:.6rem">Status:</span>
      <button class="crv-chip" :class="{ active: filters.active === '' }" @click="applyFilter(updateFilter, 'active', '')">Semua</button>
      <button class="crv-chip" :class="{ active: filters.active === 1 }" @click="applyFilter(updateFilter, 'active', 1)">Aktif</button>
      <button class="crv-chip" :class="{ active: filters.active === 0 }" @click="applyFilter(updateFilter, 'active', 0)">Nonaktif</button>

      <button class="crv-chip" :class="{ active: filters.low_stock }" style="margin-left:.6rem" @click="toggleLowStock(updateFilter)" title="Stok ≤ minimum">
        Stok Rendah
      </button>
    </template>

    <template #cell-category="{ value }">
      <span v-if="value" class="cell-tag">{{ value }}</span>
      <span v-else>—</span>
    </template>
  </CrudResourceView>
</template>

<style scoped>
.cell-tag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
</style>
