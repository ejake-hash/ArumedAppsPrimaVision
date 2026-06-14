<script setup>
import CrudResourceView from './_CrudResourceView.vue'

// Modalitas DICOM per jenis — jembatan ke integrasi alat (OCT/USG/Biometri).
const MODALITY_OPTIONS = [
  { value: '',    label: '— (bukan alat DICOM)' },
  { value: 'OPT', label: 'OCT (OPT)' },
  { value: 'US',  label: 'USG / Biometri (US)' },
  { value: 'OT',  label: 'Lainnya (OT)' },
]
const MODALITY_LABEL = { OPT: 'OCT (OPT)', US: 'USG/Biometri', OT: 'Lainnya' }

const config = {
  resourceKey: 'diagnosticTestType',
  title: 'Jenis Pemeriksaan Penunjang',
  description: 'Master jenis pemeriksaan penunjang. Kode dibuat otomatis (PNJ-xxx). Setiap jenis tersinkron sebagai tindakan kategori "Penunjang" — dokter memilihnya saat order. Tarif TIDAK diatur di sini; semua harga berasal dari Buku Tarif (Tarif & Paket → Tarif Tindakan, kategori Penunjang).',
  searchPlaceholder: 'Cari kode / nama…',
  extraSearchParam: 'search',
  csvShowTemplate: false,
  defaults: { name: '', modality: '', is_active: true },
  columns: [
    { key: '_no',        label: 'No.',              width: '52px',  align: 'center' },
    { key: 'code',       label: 'Kode',             width: '110px' },
    { key: 'name',       label: 'Nama Pemeriksaan' },
    { key: 'modality',   label: 'Modalitas Alat',   width: '130px', formatter: (v) => MODALITY_LABEL[v] ?? '—' },
    { key: 'is_active',  label: 'Aktif?',           width: '80px',  align: 'center' },
  ],
  // Kode tidak diinput admin (auto PNJ-xxx) & immutable saat edit. Tarif via Buku Tarif.
  // Modalitas = jembatan ke alat DICOM: OCT→OPT (masuk worklist OCT), USG/Biometri→US.
  fields: [
    { key: 'name',       label: 'Nama Pemeriksaan', type: 'text',     required: true, cols: 2, placeholder: 'mis. OCT Fundus' },
    { key: 'modality',   label: 'Modalitas Alat',   type: 'select',   cols: 2, options: MODALITY_OPTIONS, hint: 'OCT (OPT) agar masuk worklist OCT; USG/Biometri (US); kosongkan bila bukan alat DICOM' },
    { key: 'is_active',  label: 'Aktif',            type: 'checkbox', cols: 2 },
  ],
  editFields: [
    { key: 'name',       label: 'Nama Pemeriksaan', type: 'text',     required: true, cols: 2 },
    { key: 'modality',   label: 'Modalitas Alat',   type: 'select',   cols: 2, options: MODALITY_OPTIONS, hint: 'OCT (OPT) agar masuk worklist OCT; USG/Biometri (US); kosongkan bila bukan alat DICOM' },
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
