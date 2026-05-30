<script setup>
/**
 * _StubView — placeholder sementara untuk view master data yang belum dibangun.
 * Akan diganti per resource di poin 7-8.
 */
import { useRoute } from 'vue-router'
import { computed } from 'vue'

const route = useRoute()
const title = computed(() => route.meta?.title ?? 'Halaman')
</script>

<template>
  <div class="stub">
    <div class="stub-icon">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
    </div>
    <h2>{{ title }}</h2>
    <p>Halaman ini sedang dalam pengembangan. Akan diisi pada tahap berikutnya.</p>
  </div>
</template>

<style scoped>
.stub { padding: 4rem 2rem; text-align: center; color: var(--tm); display: flex; flex-direction: column; align-items: center; gap: 0.75rem; }
.stub-icon { width: 64px; height: 64px; border-radius: 16px; background: var(--bs); border: 1px solid var(--gb); display: flex; align-items: center; justify-content: center; }
.stub-icon svg { width: 28px; height: 28px; fill: none; stroke: var(--tu); stroke-width: 1.5; stroke-linecap: round; }
.stub h2 { font-family: 'Space Grotesk', serif; font-size: 22px; color: var(--td); margin: 0.5rem 0 0; }
.stub p { font-size: 13px; color: var(--tu); margin: 0; max-width: 360px; }
</style>
