<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const now = ref(new Date())
let timer = null

onMounted(() => {
  timer = setInterval(() => (now.value = new Date()), 1000)
})
onUnmounted(() => clearInterval(timer))

const clock = computed(() =>
  now.value.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
)

const title = computed(() => route.meta?.title ?? 'Arumed Apps')
</script>

<template>
  <header class="topbar">
    <h1 class="topbar-title">{{ title }}</h1>
    <div class="topbar-breadcrumb">
      <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      <span>{{ title }}</span>
    </div>
    <div class="topbar-right">
      <div class="ws-indicator">
        <span class="ws-dot"></span>
        <span>Realtime aktif</span>
      </div>
      <div class="topbar-clock">{{ clock }}</div>
    </div>
  </header>
</template>

<style scoped>
.topbar {
  height: 56px;
  background: var(--bc);
  border-bottom: 1px solid var(--gb);
  display: flex;
  align-items: center;
  padding: 0 1.5rem;
  gap: 1rem;
  flex-shrink: 0;
}
.topbar-title {
  font-family: 'DM Serif Display', serif;
  font-size: 18px;
  color: var(--gd);
  font-weight: 400;
}
.topbar-breadcrumb {
  font-size: 12px;
  color: var(--tu);
  display: flex;
  align-items: center;
  gap: 4px;
}
.topbar-breadcrumb svg {
  width: 12px;
  height: 12px;
  stroke: var(--th);
  fill: none;
  stroke-width: 2;
  stroke-linecap: round;
}
.topbar-right {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
.topbar-clock {
  font-size: 13px;
  font-weight: 600;
  color: var(--tm);
  font-variant-numeric: tabular-nums;
}
.ws-indicator {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: var(--st);
  background: var(--sb);
  border: 1px solid var(--sbd);
  padding: 3px 10px;
  border-radius: 20px;
  font-weight: 500;
}
.ws-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--st);
  animation: blink 1.5s infinite;
}
</style>
