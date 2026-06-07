<script setup>
import CrudResourceView from './_CrudResourceView.vue'

const config = {
  resourceKey: 'diagnosticTestType',
  title: 'Jenis Pemeriksaan Penunjang',
  description: 'Master jenis pemeriksaan penunjang. Kode dibuat otomatis (PNJ-xxx). Setiap jenis tersinkron sebagai tindakan kategori "Penunjang" — dokter memilihnya saat order. Tarif TIDAK diatur di sini; semua harga berasal dari Buku Tarif (Tarif & Paket → Tarif Tindakan, kategori Penunjang).',
  searchPlaceholder: 'Cari kode / nama…',
  extraSearchParam: 'search',
  csvShowTemplate: false,
  defaults: { name: '', is_active: true },
  columns: [
    { key: '_no',        label: 'No.',              width: '52px',  align: 'center' },
    { key: 'code',       label: 'Kode',             width: '110px' },
    { key: 'name',       label: 'Nama Pemeriksaan' },
    { key: 'is_active',  label: 'Aktif?',           width: '80px',  align: 'center' },
  ],
  // Kode tidak diinput admin (auto PNJ-xxx) & immutable saat edit. Tarif via Buku Tarif.
  fields: [
    { key: 'name',       label: 'Nama Pemeriksaan', type: 'text',     required: true, cols: 2, placeholder: 'mis. OCT Macula' },
    { key: 'is_active',  label: 'Aktif',            type: 'checkbox', cols: 2 },
  ],
  editFields: [
    { key: 'name',       label: 'Nama Pemeriksaan', type: 'text',     required: true, cols: 2 },
    { key: 'is_active',  label: 'Aktif',            type: 'checkbox', cols: 2 },
  ],
  deleteLabel: (r) => `${r?.code} · ${r?.name}`,
}
</script>

<template>
  <CrudResourceView :config="config">
    <!-- Kolom No. = nomor baris (urutan), bukan field data -->
    <template #cell-_no="{ number }">{{ number }}</template>
  </CrudResourceView>
</template>
