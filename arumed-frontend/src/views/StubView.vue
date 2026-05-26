<script setup>
defineProps({ title: { type: String, default: 'Halaman' } })
</script>

<template>
  <div class="stub">
    <div class="stub-box">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <h2>{{ title }}</h2>
      <p>Modul ini sedang dalam pengembangan.</p>
    </div>
  </div>
</template>

<style scoped>
.stub { display: flex; align-items: center; justify-content: center; min-height: 60vh; }
.stub-box { text-align: center; color: var(--tu); }
.stub-box svg { width: 52px; height: 52px; fill: none; stroke: var(--gb); stroke-width: 1.5; stroke-linecap: round; margin-bottom: 1rem; display: block; margin-inline: auto; }
.stub-box h2 { font-family: 'DM Serif Display', serif; font-size: 22px; color: var(--td); margin-bottom: 0.4rem; }
.stub-box p { font-size: 13px; }
</style>
