<script setup>
import { ref } from 'vue'
import CrudResourceView from '../master-data/_CrudResourceView.vue'

const config = {
  resourceKey: 'supplier',
  title: 'Supplier',
  description: 'Master daftar supplier/distributor obat, BHP, & IOL. Kode auto-generate SUP-NNN.',
  searchPlaceholder: 'Cari kode/nama/kontak/telp/email/NPWP…',
  extraSearchParam: 'search',
  csvShowTemplate: false,
  defaults: {
    code: '', name: '', contact_person: '', phone: '', email: '',
    npwp: '', address: '', is_active: true,
  },
  columns: [
    { key: 'code',           label: 'Kode',           width: '110px' },
    { key: 'name',           label: 'Nama Supplier' },
    { key: 'contact_person', label: 'Kontak Person',  width: '180px' },
    { key: 'phone',          label: 'Telepon',        width: '140px' },
    { key: 'email',          label: 'Email',          width: '180px' },
    { key: 'is_active',      label: 'Status',         width: '90px', align: 'center' },
  ],
  fields: [
    { key: 'name',           label: 'Nama Supplier',    type: 'text',     required: true, cols: 2, placeholder: 'PT. Kimia Farma Distributor' },
    { key: 'contact_person', label: 'Kontak Person',    type: 'text',     cols: 1 },
    { key: 'phone',          label: 'Telepon',          type: 'text',     cols: 1, placeholder: '0812-xxxx-xxxx' },
    { key: 'email',          label: 'Email',            type: 'email',    cols: 1 },
    { key: 'npwp',           label: 'NPWP',             type: 'text',     cols: 1, placeholder: '00.000.000.0-000.000' },
    { key: 'address',        label: 'Alamat',           type: 'textarea', cols: 2, rows: 2 },
    { key: 'is_active',      label: 'Aktif',            type: 'checkbox', cols: 1 },
  ],
  editFields: null,
  deleteLabel: (r) => `${r?.code} · ${r?.name}`,
}
config.editFields = config.fields

const filters = ref({ active: '' })

function applyFilter(updateFn, key, value) {
  filters.value[key] = value
  updateFn(key, value === '' ? null : value)
}
</script>

<template>
  <CrudResourceView :config="config">
    <template #filters="slotProps">
      <span class="crv-filter-label">Status:</span>
      <button class="crv-chip" :class="{ active: filters.active === '' }" @click="applyFilter(slotProps.updateFilter, 'active', '')">Semua</button>
      <button class="crv-chip" :class="{ active: filters.active === 1 }" @click="applyFilter(slotProps.updateFilter, 'active', 1)">Aktif</button>
      <button class="crv-chip" :class="{ active: filters.active === 0 }" @click="applyFilter(slotProps.updateFilter, 'active', 0)">Nonaktif</button>
    </template>

    <template #cell-email="{ value }">
      <a v-if="value" :href="`mailto:${value}`" class="cell-email">{{ value }}</a>
      <span v-else>—</span>
    </template>
  </CrudResourceView>
</template>

<style scoped>
.cell-email { color: var(--ga); text-decoration: none; font-size: 12.5px; }
.cell-email:hover { text-decoration: underline; }
</style>
