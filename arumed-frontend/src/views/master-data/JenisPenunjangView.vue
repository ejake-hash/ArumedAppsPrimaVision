<script setup>
import CrudResourceView from './_CrudResourceView.vue'

const config = {
  resourceKey: 'diagnosticTestType',
  title: 'Jenis Pemeriksaan Penunjang',
  description: 'Daftar jenis pemeriksaan penunjang yang tersedia di klinik. Dokter memilih dari daftar ini saat order. Kode dipakai untuk relasi tarif penunjang. Urutan mengikuti nomor (urutan penambahan).',
  searchPlaceholder: 'Cari kode / nama / kategori…',
  extraSearchParam: 'search',
  csvShowTemplate: false,
  defaults: { code: '', name: '', category: '', is_active: true },
  columns: [
    { key: '_no',       label: 'No.',              width: '52px',  align: 'center' },
    { key: 'code',      label: 'Kode',             width: '90px' },
    { key: 'name',      label: 'Nama Pemeriksaan' },
    { key: 'category',  label: 'Kategori',         width: '150px' },
    { key: 'is_active', label: 'Aktif?',           width: '80px',  align: 'center' },
  ],
  fields: [
    { key: 'code',      label: 'Kode',             type: 'text',     required: true, cols: 1, placeholder: 'OCT' },
    { key: 'category',  label: 'Kategori',         type: 'text',     cols: 1, placeholder: 'Imaging / Fungsional / …' },
    { key: 'name',      label: 'Nama Pemeriksaan', type: 'text',     required: true, cols: 2, placeholder: 'OCT Macula / Saraf' },
    { key: 'is_active', label: 'Aktif',            type: 'checkbox', cols: 1 },
  ],
  editFields: null,
  deleteLabel: (r) => `${r?.code} · ${r?.name}`,
}

// Kode dipakai relasi tarif → kunci di-disable saat edit (hapus + buat baru bila salah).
config.editFields = config.fields.map((f) => f.key === 'code' ? { ...f, disabled: true } : f)
</script>

<template>
  <CrudResourceView :config="config">
    <!-- Kolom No. = nomor baris (urutan), bukan field data -->
    <template #cell-_no="{ number }">{{ number }}</template>
  </CrudResourceView>
</template>
