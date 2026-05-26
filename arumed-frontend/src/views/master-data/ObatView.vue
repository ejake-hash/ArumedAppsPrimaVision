<script setup>
import { ref } from 'vue'
import CrudResourceView from './_CrudResourceView.vue'

const FORM_SEDIAAN = [
  { value: 'TABLET',     label: 'Tablet' },
  { value: 'KAPSUL',     label: 'Kapsul' },
  { value: 'SIRUP',      label: 'Sirup' },
  { value: 'TETES_MATA', label: 'Tetes Mata' },
  { value: 'SALEP_MATA', label: 'Salep Mata' },
  { value: 'INJEKSI',    label: 'Injeksi' },
  { value: 'LAIN',       label: 'Lain' },
]

const GOLONGAN = [
  { value: 'BEBAS',          label: 'Bebas' },
  { value: 'BEBAS_TERBATAS', label: 'Bebas Terbatas' },
  { value: 'KERAS',          label: 'Keras' },
  { value: 'NARKOTIKA',      label: 'Narkotika' },
  { value: 'PSIKOTROPIKA',   label: 'Psikotropika' },
]

const config = {
  resourceKey: 'obat',
  title: 'Obat',
  description: 'Master daftar obat — formularium, golongan BPOM, form sediaan.',
  searchPlaceholder: 'Cari kode/nama/generic/komposisi/pabrik…',
  extraSearchParam: 'search',
  csvShowTemplate: true,
  defaults: {
    code: '', name: '', generic_name: '', composition: '', manufacturer: '',
    formularium: 'FORNAS', form_sediaan: '', golongan: '',
    unit_besar: '', unit_kecil: '', konversi: null,
    min_stock: 0,
    expiry_date: '', batch_number: '', description: '',
    is_active: true,
  },
  columns: [
    { key: 'code',         label: 'Kode',         width: '110px' },
    { key: 'name',         label: 'Nama Obat' },
    { key: 'form_sediaan', label: 'Sediaan',      width: '110px' },
    { key: 'golongan',     label: 'Golongan',     width: '110px' },
    { key: 'formularium',  label: 'Formularium',  width: '120px' },
    { key: '_satuan',      label: 'Satuan',       width: '160px' },
    { key: 'is_active',    label: 'Status',       width: '90px',  align: 'center' },
  ],
  fields: [
    { key: 'code',         label: 'Kode',                type: 'text',     required: true, cols: 1, placeholder: 'OB-001' },
    { key: 'name',         label: 'Nama Obat',           type: 'text',     required: true, cols: 1 },
    { key: 'generic_name', label: 'Nama Generik',        type: 'text',     cols: 1 },
    { key: 'manufacturer', label: 'Pabrik',              type: 'text',     cols: 1 },
    { key: 'composition',  label: 'Komposisi / Kandungan', type: 'textarea', cols: 2, rows: 2, placeholder: 'mis. Tobramycin 0.3% + Dexamethasone 0.1%' },
    { key: 'formularium',  label: 'Formularium',         type: 'select',   required: true, cols: 1, options: [
      { value: 'FORNAS',              label: 'FORNAS' },
      { value: 'FORMULARIUM GENERIK', label: 'FORMULARIUM GENERIK' },
      { value: 'BRANDED',             label: 'BRANDED' },
    ]},
    { key: 'form_sediaan', label: 'Form Sediaan',        type: 'select',   cols: 1, options: [{ value: '', label: '—' }, ...FORM_SEDIAAN] },
    { key: 'golongan',     label: 'Golongan BPOM',       type: 'select',   cols: 1, options: [{ value: '', label: '—' }, ...GOLONGAN] },
    { key: 'unit_besar',   label: 'Satuan Besar',        type: 'text',     cols: 1, placeholder: 'box / dus / botol' },
    { key: 'unit_kecil',   label: 'Satuan Kecil',        type: 'text',     cols: 1, placeholder: 'tablet / kapsul / ml' },
    { key: 'konversi',     label: 'Konversi',            type: 'number',   min: 1, cols: 1, placeholder: '100', hint: '1 satuan besar = ? satuan kecil' },
    { key: 'min_stock',    label: 'Stok Minimum',        type: 'number',   min: 0, cols: 1, hint: 'Threshold alert stok rendah' },
    { key: 'expiry_date',  label: 'Tanggal Kadaluwarsa', type: 'date',     cols: 1 },
    { key: 'batch_number', label: 'No. Batch',           type: 'text',     cols: 1 },
    { key: 'description',  label: 'Deskripsi/Catatan',   type: 'textarea', cols: 2, rows: 2 },
    { key: 'is_active',    label: 'Aktif',               type: 'checkbox', cols: 1 },
  ],
  editFields: null,
  deleteLabel: (r) => `${r?.code} · ${r?.name}`,
}

config.editFields = config.fields.map((f) => f.key === 'code' ? { ...f, disabled: true } : f)

const filters = ref({ golongan: '', form_sediaan: '', active: '', low_stock: false })

function applyFilter(updateFn, key, value) {
  filters.value[key] = value
  updateFn(key, value === '' || value === false ? null : value)
}

function toggleLowStock(updateFn) {
  filters.value.low_stock = !filters.value.low_stock
  updateFn('low_stock', filters.value.low_stock ? 1 : null)
}

function labelForm(v) { return FORM_SEDIAAN.find((f) => f.value === v)?.label ?? v }
function labelGolongan(v) { return GOLONGAN.find((g) => g.value === v)?.label ?? v }
</script>

<template>
  <CrudResourceView :config="config">
    <template #filters="slotProps">
      <span class="crv-filter-label">Golongan:</span>
      <select class="crv-filter-select" v-model="filters.golongan" @change="slotProps.updateFilter('golongan', filters.golongan || null)">
        <option value="">Semua</option>
        <option v-for="g in GOLONGAN" :key="g.value" :value="g.value">{{ g.label }}</option>
      </select>

      <span class="crv-filter-label" style="margin-left:.6rem">Sediaan:</span>
      <select class="crv-filter-select" v-model="filters.form_sediaan" @change="slotProps.updateFilter('form_sediaan', filters.form_sediaan || null)">
        <option value="">Semua</option>
        <option v-for="f in FORM_SEDIAAN" :key="f.value" :value="f.value">{{ f.label }}</option>
      </select>

      <span class="crv-filter-label" style="margin-left:.6rem">Status:</span>
      <button class="crv-chip" :class="{ active: filters.active === '' }" @click="applyFilter(slotProps.updateFilter, 'active', '')">Semua</button>
      <button class="crv-chip" :class="{ active: filters.active === 1 }" @click="applyFilter(slotProps.updateFilter, 'active', 1)">Aktif</button>
      <button class="crv-chip" :class="{ active: filters.active === 0 }" @click="applyFilter(slotProps.updateFilter, 'active', 0)">Nonaktif</button>

      <button class="crv-chip" :class="{ active: filters.low_stock }" style="margin-left:.6rem" @click="toggleLowStock(slotProps.updateFilter)" title="Stok minimum">
        Stok Rendah
      </button>
    </template>

    <template #cell-form_sediaan="{ value }">
      <span v-if="value" class="cell-tag">{{ labelForm(value) }}</span>
      <span v-else>—</span>
    </template>
    <template #cell-golongan="{ value }">
      <span v-if="value" class="cell-tag" :data-gol="value">{{ labelGolongan(value) }}</span>
      <span v-else>—</span>
    </template>
    <template #cell-_satuan="{ row }">
      <span v-if="row.unit_kecil || row.unit_besar" class="cell-satuan">
        <strong>{{ row.unit_kecil || '—' }}</strong>
        <span v-if="row.unit_besar" class="cell-satuan-sub">
          / {{ row.unit_besar }}<span v-if="row.konversi"> ({{ row.konversi }})</span>
        </span>
      </span>
      <span v-else>—</span>
    </template>
  </CrudResourceView>
</template>

<style scoped>
.cell-tag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.cell-tag[data-gol="NARKOTIKA"]    { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.cell-tag[data-gol="PSIKOTROPIKA"] { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.cell-tag[data-gol="KERAS"]        { background: var(--pb); color: var(--pt); border-color: var(--pbd); }
.cell-tag[data-gol="BEBAS_TERBATAS"] { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.cell-tag[data-gol="BEBAS"]        { background: var(--sb); color: var(--st); border-color: var(--sbd); }

.cell-satuan { font-size: 12px; color: var(--td); }
.cell-satuan strong { font-weight: 500; }
.cell-satuan-sub { color: var(--tu); font-size: 11px; margin-left: 2px; }
</style>
