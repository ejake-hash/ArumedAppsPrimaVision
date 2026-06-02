<script setup>
import { ref } from 'vue'
import CrudResourceView from './_CrudResourceView.vue'

const IOL_TYPES = [
  { value: 'MONOFOCAL',  label: 'Monofocal' },
  { value: 'MULTIFOCAL', label: 'Multifocal' },
  { value: 'TORIC',      label: 'Toric' },
  { value: 'TRIFOCAL',   label: 'Trifocal' },
  { value: 'EDOF',       label: 'EDOF' },
  { value: 'PHAKIC',     label: 'Phakic' },
]

const MATERIALS = [
  { value: 'Acrylic',  label: 'Acrylic' },
  { value: 'Silicone', label: 'Silicone' },
  { value: 'PMMA',     label: 'PMMA' },
]

const config = {
  resourceKey: 'iol',
  title: 'IOL',
  description: 'Master lensa intraokular (1 baris = 1 unit fisik, serial unik).',
  searchPlaceholder: 'Cari brand/model/pabrik/serial/lot…',
  extraSearchParam: 'search',
  csvAllowExcel: true,
  csvShowTemplate: true,
  defaults: {
    brand: '', manufacturer: '', model: '',
    iol_type: 'MONOFOCAL', material: '',
    power: 0, cylinder: '', axis: '',
    lot_number: '', serial_number: '', gs1_barcode: '',
    expiry_date: '', is_active: true,
  },
  columns: [
    { key: 'brand',         label: 'Brand',  width: '130px' },
    { key: 'model',         label: 'Model',  width: '120px' },
    { key: 'iol_type',      label: 'Tipe',   width: '110px' },
    { key: 'material',      label: 'Material', width: '90px' },
    { key: 'power',         label: 'Power',  width: '80px', align: 'right', formatter: (v) => v != null ? `${v}D` : '—' },
    { key: 'cylinder',      label: 'Cyl',    width: '70px', align: 'right', formatter: (v) => v != null && v !== '' ? `${v}D` : '—' },
    { key: 'axis',          label: 'Axis',   width: '60px', align: 'right', formatter: (v) => v != null && v !== '' ? `${v}°` : '—' },
    { key: 'serial_number', label: 'Serial', width: '130px' },
    { key: 'is_used',       label: 'Used?',  width: '70px', align: 'center' },
    { key: 'is_active',     label: 'Status', width: '90px', align: 'center' },
  ],
  fields: [
    { key: 'brand',         label: 'Brand',         type: 'text',   required: true, cols: 1 },
    { key: 'manufacturer',  label: 'Pabrik',        type: 'text',   cols: 1 },
    { key: 'model',         label: 'Model',         type: 'text',   required: true, cols: 1 },
    { key: 'iol_type',      label: 'Tipe IOL',      type: 'select', required: true, cols: 1, options: IOL_TYPES },
    { key: 'material',      label: 'Material',      type: 'select', cols: 1, options: [{ value: '', label: '—' }, ...MATERIALS] },
    { key: 'power',         label: 'Power (D)',     type: 'number', required: true, min: -20, max: 40, step: 0.25, cols: 1, hint: 'Bisa minus untuk myopia/aphakia' },
    { key: 'cylinder',      label: 'Cylinder (D) — TORIC', type: 'number', min: 0, max: 10, step: 0.25, cols: 1, hint: 'Wajib jika tipe = TORIC' },
    { key: 'axis',          label: 'Axis (°) — TORIC',     type: 'number', min: 0, max: 180, step: 1, cols: 1, hint: 'Wajib jika tipe = TORIC (0–180)' },
    { key: 'lot_number',    label: 'Lot Number',    type: 'text',   cols: 1 },
    { key: 'serial_number', label: 'Serial Number', type: 'text',   cols: 1, hint: 'Unique — kunci CSV upsert' },
    { key: 'gs1_barcode',   label: 'GS1 Barcode',   type: 'text',   cols: 2 },
    { key: 'expiry_date',   label: 'Kadaluwarsa',   type: 'date',   cols: 1 },
    { key: 'is_active',     label: 'Aktif',         type: 'checkbox', cols: 1 },
  ],
  editFields: null,
  deleteLabel: (r) => `${r?.brand} ${r?.model} (${r?.power}D)`,
}

// Serial number adalah unique key — disable di edit. Field is_used hanya muncul di edit.
config.editFields = [
  ...config.fields.map((f) => f.key === 'serial_number' ? { ...f, disabled: true } : f),
  { key: 'is_used', label: 'Sudah Terpakai (used)', type: 'checkbox', cols: 1 },
]

const filters = ref({ iol_type: '', material: '', active: '', is_used: '', available_only: false })

function applyFilter(updateFn, key, value) {
  filters.value[key] = value
  updateFn(key, value === '' || value === false ? null : value)
}

function toggleAvailable(updateFn) {
  filters.value.available_only = !filters.value.available_only
  updateFn('available_only', filters.value.available_only ? 1 : null)
}
</script>

<template>
  <CrudResourceView :config="config">
    <template #filters="{ updateFilter }">
      <span class="crv-filter-label">Tipe:</span>
      <select class="crv-filter-select" :value="filters.iol_type" @change="(e) => applyFilter(updateFilter, 'iol_type', e.target.value)">
        <option value="">Semua</option>
        <option v-for="t in IOL_TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
      </select>

      <span class="crv-filter-label" style="margin-left:.6rem">Material:</span>
      <select class="crv-filter-select" :value="filters.material" @change="(e) => applyFilter(updateFilter, 'material', e.target.value)">
        <option value="">Semua</option>
        <option v-for="m in MATERIALS" :key="m.value" :value="m.value">{{ m.label }}</option>
      </select>

      <button class="crv-chip" :class="{ active: filters.available_only }" style="margin-left:.6rem" @click="toggleAvailable(updateFilter)" title="Belum dipakai & stok > 0 & aktif">
        Tersedia
      </button>

      <span class="crv-filter-label" style="margin-left:.6rem">Status:</span>
      <button class="crv-chip" :class="{ active: filters.active === '' }" @click="applyFilter(updateFilter, 'active', '')">Semua</button>
      <button class="crv-chip" :class="{ active: filters.active === 1 }" @click="applyFilter(updateFilter, 'active', 1)">Aktif</button>
      <button class="crv-chip" :class="{ active: filters.active === 0 }" @click="applyFilter(updateFilter, 'active', 0)">Nonaktif</button>
    </template>

    <template #cell-iol_type="{ value }">
      <span class="cell-tag" :data-iol="value">{{ value }}</span>
    </template>
    <template #cell-is_used="{ value }">
      <span class="cell-used" :class="value ? 'used' : 'avail'">{{ value ? 'USED' : 'Avail' }}</span>
    </template>
  </CrudResourceView>
</template>

<style scoped>
.cell-tag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.cell-tag[data-iol="TORIC"]      { background: var(--pb); color: var(--pt); border-color: var(--pbd); }
.cell-tag[data-iol="MULTIFOCAL"] { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.cell-tag[data-iol="TRIFOCAL"]   { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.cell-tag[data-iol="EDOF"]       { background: var(--wb); color: var(--wt); border-color: var(--wbd); }

.cell-used { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 600; }
.cell-used.used  { background: var(--eb); color: var(--et); }
.cell-used.avail { background: var(--sb); color: var(--st); }
</style>
