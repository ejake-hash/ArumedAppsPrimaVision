<script setup>
/**
 * KategoriTagihanView — master CRUD untuk kategori grouping rincian tagihan Kasir.
 *
 * Pola: pakai CrudResourceView (sama dengan JenisPenunjangView). Urutan ditentukan
 * via `sort_order` di tiap row — admin set langsung saat tambah/edit. Item invoice
 * yang category-nya tidak terdaftar di master masuk grup terakhir ("Lainnya")
 * — sehingga master ini bukan constraint, hanya panduan urutan tampil.
 */
import CrudResourceView from '@/views/master-data/_CrudResourceView.vue'

const config = {
  resourceKey: 'kategoriTagihan',
  title: 'Kategori Tagihan',
  description: 'Daftar kategori grouping di Rincian Tagihan Kasir. Urutan tampil di kasir mengikuti kolom Urutan (kecil → besar). Nama kategori harus persis sama dengan yang tersimpan di item invoice (mis. "Konsultasi", "Pemeriksaan").',
  searchPlaceholder: 'Cari nama kategori…',
  extraSearchParam: null,
  csvShowTemplate: false,
  defaults: { name: '', sort_order: 100, is_active: true },
  columns: [
    { key: '_no',        label: 'No.',     width: '52px',  align: 'center' },
    { key: 'name',       label: 'Nama Kategori' },
    { key: 'sort_order', label: 'Urutan',  width: '100px', align: 'center' },
    { key: 'is_active',  label: 'Aktif?',  width: '80px',  align: 'center' },
  ],
  fields: [
    { key: 'name',       label: 'Nama Kategori', type: 'text',     required: true, cols: 2, placeholder: 'mis. Konsultasi / Pemeriksaan / Tindakan' },
    { key: 'sort_order', label: 'Urutan',        type: 'number',   required: true, cols: 1, min: 0, max: 9999, placeholder: '10, 20, 30, …' },
    { key: 'is_active',  label: 'Aktif',         type: 'checkbox', cols: 1 },
  ],
  editFields: null,
  deleteLabel: (r) => r?.name ?? '',
}
config.editFields = config.fields
</script>

<template>
  <CrudResourceView :config="config">
    <template #cell-_no="{ number }">{{ number }}</template>
  </CrudResourceView>
</template>
