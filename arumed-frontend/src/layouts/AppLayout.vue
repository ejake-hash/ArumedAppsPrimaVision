<script setup>
import { watch } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import AppSidebar from '@/components/layout/AppSidebar.vue'
import AppTopbar from '@/components/layout/AppTopbar.vue'
import { useUiShell } from '@/composables/useUiShell'

const route = useRoute()
const { mobileNavOpen, closeMobileNav } = useUiShell()

// Tutup drawer otomatis setiap kali pindah halaman (klik menu di sidebar mobile).
watch(() => route.fullPath, () => closeMobileNav())
</script>

<template>
  <div class="app-shell">
    <AppSidebar />
    <!-- Scrim gelap di belakang drawer; hanya muncul di mode mobile saat terbuka -->
    <div
      class="app-scrim"
      :class="{ show: mobileNavOpen }"
      @click="closeMobileNav"
    ></div>
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

/* Scrim drawer — tak tampil di desktop */
.app-scrim { display: none; }

/* ─── Mode fluid (≤1024px, HANYA rute `app-fluid`: TTD Dokumen) ───────────────
   Sidebar berubah jadi drawer (lihat AppSidebar.vue), jadi app-main mengisi
   seluruh lebar. Scrim aktif untuk menutup drawer. Di rute desktop biasa
   (tanpa kelas `app-fluid`) blok ini tidak berlaku → sidebar tetap kolom tetap. */
@media (max-width: 1024px) {
  html.app-fluid .app-scrim {
    display: block;
    position: fixed;
    inset: 0;
    z-index: 60;
    background: rgba(15, 30, 50, 0.45);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
  }
  html.app-fluid .app-scrim.show {
    opacity: 1;
    pointer-events: auto;
  }
  html.app-fluid .app-content { padding: 1rem; }
}
@media (max-width: 640px) {
  html.app-fluid .app-content { padding: 0.75rem; }
}

/* Saat mencetak: sembunyikan chrome aplikasi, biarkan konten halaman mengalir
   penuh agar dokumen cetak (mis. Rincian Biaya A4) tampil bersih. */
@media print {
  .sidebar, .topbar { display: none !important; }
  .app-shell { display: block !important; height: auto !important; overflow: visible !important; }
  .app-main { display: block !important; overflow: visible !important; }
  .app-content { padding: 0 !important; overflow: visible !important; }
}
</style>
