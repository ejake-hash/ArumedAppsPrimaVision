<script setup>
import { RouterView } from 'vue-router'
import AppSidebar from '@/components/layout/AppSidebar.vue'
import AppTopbar from '@/components/layout/AppTopbar.vue'
</script>

<template>
  <div class="app-shell">
    <AppSidebar />
    <div class="app-main">
      <AppTopbar />
      <main class="app-content">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.app-shell {
  display: flex;
  height: 100vh;
  overflow: hidden;
}
.app-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.app-content {
  flex: 1;
  overflow-y: auto;
  padding: 1.5rem;
}
.app-content::-webkit-scrollbar { width: 5px; }
.app-content::-webkit-scrollbar-thumb { background: var(--gb); border-radius: 3px; }

/* Saat mencetak: sembunyikan chrome aplikasi, biarkan konten halaman mengalir
   penuh agar dokumen cetak (mis. Rincian Biaya A4) tampil bersih. */
@media print {
  .sidebar, .topbar { display: none !important; }
  .app-shell { display: block !important; height: auto !important; overflow: visible !important; }
  .app-main { display: block !important; overflow: visible !important; }
  .app-content { padding: 0 !important; overflow: visible !important; }
}
</style>
