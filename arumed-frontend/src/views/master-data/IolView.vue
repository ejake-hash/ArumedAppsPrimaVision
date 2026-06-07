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
  description: 'Master lensa intraokular per-TIPE (brand + model + power). Stok & serial dikelola via Penerimaan/Operasi.',
  searchPlaceholder: 'Cari brand/model/pabrik/GTIN…',
  extraSearchParam: 'search',
  csvAllowExcel: true,
  csvShowTemplate: true,
  defaults: {
    brand: '', manufacturer: '', model: '',
    iol_type: 'MONOFOCAL', material: '',
    power: 0, a_constant: '', cylinder: '', axis: '',
    gtin: '', gs1_barcode: '',
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
    { key: 'gtin',          label: 'GTIN',   width: '130px' },
    { key: 'on_hand',       label: 'Stok',   width: '70px', align: 'right', formatter: (v) => v != null ? Number(v) : 0 },
    { key: 'is_active',     label: 'Status', width: '90px', align: 'center' },
  ],
  fields: [
    { key: 'brand',         label: 'Brand',         type: 'text',   required: true, cols: 1 },
    { key: 'manufacturer',  label: 'Pabrik',        type: 'text',   cols: 1 },
    { key: 'model',         label: 'Model',         type: 'text',   required: true, cols: 1 },
    { key: 'iol_type',      label: 'Tipe IOL',      type: 'select', required: true, cols: 1, options: IOL_TYPES },
    { key: 'material',      label: 'Material',      type: 'select', cols: 1, options: [{ value: '', label: '—' }, ...MATERIALS] },
    { key: 'power',         label: 'Power (D)',     type: 'number', required: true, min: -20, max: 40, step: 0.25, cols: 1, hint: 'Bisa minus untuk myopia/aphakia' },
    { key: 'a_constant',    label: 'A-constant (SRK/T)', type: 'number', min: 90, max: 130, step: 0.01, cols: 1, hint: 'Untuk memetakan tabel hitung biometri Quantel ke lensa ini' },
    { key: 'cylinder',      label: 'Cylinder (D) — TORIC', type: 'number', min: 0, max: 10, step: 0.25, cols: 1, hint: 'Wajib jika tipe = TORIC' },
    { key: 'axis',          label: 'Axis (°) — TORIC',     type: 'number', min: 0, max: 180, step: 1, cols: 1, hint: 'Wajib jika tipe = TORIC (0–180)' },
    { key: 'gtin',          label: 'GTIN (UDI)',    type: 'text',   cols: 1, hint: 'Untuk pencocokan saat scan barcode (14 digit)' },
    { key: 'gs1_barcode',   label: 'GS1 Barcode (opsional)', type: 'text', cols: 2, hint: 'String UDI lengkap bila ada' },
    { key: 'expiry_date',   label: 'Kadaluwarsa',   type: 'date',   cols: 1 },
    { key: 'is_active',     label: 'Aktif',         type: 'checkbox', cols: 1 },
  ],
  // Per-tipe: identitas = brand+model+power. Stok dikelola via Penerimaan/Inventori
  // (inventory_stocks), bukan di form master. Serial/lot dicatat saat operasi.
  editFields: null,
  deleteLabel: (r) => `${r?.brand} ${r?.model} (${r?.power}D)`,
}

const filters = ref({ iol_type: '', material: '', active: '', available_only: false })

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

      <button class="crv-chip" :class="{ active: filters.available_only }" style="margin-left:.6rem" @click="toggleAvailable(updateFilter)" title="Stok tersedia (on-hand > 0) & aktif">
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
    <template #cell-on_hand="{ value }">
      <span class="cell-stock" :class="Number(value) > 0 ? 'ok' : 'zero'">{{ Number(value || 0) }}</span>
    </template>
  </CrudResourceView>
</template>

<style scoped>
.cell-tag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.cell-tag[data-iol="TORIC"]      { background: var(--pb); color: var(--pt); border-color: var(--pbd); }
.cell-tag[data-iol="MULTIFOCAL"] { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.cell-tag[data-iol="TRIFOCAL"]   { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.cell-tag[data-iol="EDOF"]       { background: var(--wb); color: var(--wt); border-color: var(--wbd); }

.cell-stock { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; }
.cell-stock.ok   { background: var(--sb); color: var(--st); }
.cell-stock.zero { background: var(--eb); color: var(--et); }
</style>
