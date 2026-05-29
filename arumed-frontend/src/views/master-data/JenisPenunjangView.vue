<script setup>
import CrudResourceView from './_CrudResourceView.vue'

const config = {
  resourceKey: 'diagnosticTestType',
  title: 'Jenis Pemeriksaan Penunjang',
  description: 'Daftar jenis pemeriksaan penunjang (turunan dari tindakan kategori "Penunjang"). Hanya tampilan — untuk menambah/ubah/atur harga, kelola di Tarif & Paket → Tarif Tindakan (kategori Penunjang). Dokter memilih dari daftar ini saat order.',
  searchPlaceholder: 'Cari kode / nama / kategori…',
  extraSearchParam: 'search',
  csvShowTemplate: false,
  readOnly: true,
  defaults: { code: '', name: '', category: '', is_active: true },
  columns: [
    { key: '_no',        label: 'No.',              width: '52px',  align: 'center' },
    { key: 'code',       label: 'Kode',             width: '110px' },
    { key: 'name',       label: 'Nama Pemeriksaan' },
    { key: 'category',   label: 'Kategori',         width: '150px' },
    { key: 'is_active',  label: 'Aktif?',           width: '80px',  align: 'center' },
  ],
  // read-only → fields/editFields tidak dipakai (tidak ada modal create/edit)
  fields: [],
  editFields: [],
  deleteLabel: (r) => `${r?.code} · ${r?.name}`,
}
</script>

<template>
  <CrudResourceView :config="config">
    <!-- Kolom No. = nomor baris (urutan), bukan field data -->
    <template #cell-_no="{ number }">{{ number }}</template>
  </CrudResourceView>
</template>
