<script setup>
/**
 * TarifKamarView — section "Tarif Kamar" di menu Tarif (/tarif-paket/tarif-kamar).
 *
 * Tarif kamar rawat inap (per malam, per kelas × penjamin). Sebelumnya diatur di
 * Master Data → Fasilitas & Ruang; dipindah ke sini agar segaris dengan tarif
 * tindakan/obat/BHP/IOL/paket yang juga per-penjamin. Backend tidak berubah.
 */
import RoomTarifPanel from '@/components/tarif-paket/RoomTarifPanel.vue'
</script>

<template>
  <div class="tk-view">
    <header class="tk-head">
      <h2>Tarif Kamar Rawat Inap</h2>
      <p class="tk-sub">Harga sewa kamar per malam, ditentukan per kelas perawatan dan per penjamin.</p>
    </header>
    <RoomTarifPanel />
  </div>
</template>

<style scoped>
.tk-view { display: flex; flex-direction: column; gap: 1rem; }
.tk-head h2 { font-family: var(--font-display); font-size: 18px; color: var(--td); margin: 0; }
.tk-sub { font-size: 13px; color: var(--tm); margin: 4px 0 0; }
</style>
