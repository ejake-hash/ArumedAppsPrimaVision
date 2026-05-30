<script setup>
defineProps({
  size: { type: [Number, String], default: 36 },
})
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 90 90" fill="none" class="brand-mark">
    <circle cx="45" cy="45" r="43" stroke="var(--gb)" stroke-width="1" />
    <circle cx="45" cy="45" r="34" stroke="rgba(63,181,232,0.45)" stroke-width="1" />
    <circle cx="45" cy="45" r="24" stroke="rgba(63,181,232,0.7)" stroke-width="1.5" />
    <circle cx="45" cy="45" r="14" stroke="var(--ld)" stroke-width="1.5" />
    <circle cx="45" cy="45" r="6" fill="var(--gd)" />
    <line x1="45" y1="39" x2="45" y2="51" stroke="#fff" stroke-width="1.5" stroke-linecap="round" />
    <line x1="39" y1="45" x2="51" y2="45" stroke="#fff" stroke-width="1.5" stroke-linecap="round" />
  </svg>
</template>
