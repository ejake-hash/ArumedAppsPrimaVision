<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()
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

// ─── Identitas dokter (hanya akun dokter) ───────────────────────────────────
// Data nama + SIP dari akun login (Data Pengguna → employee). Ditampilkan inline
// di topbar (tanpa card), tidak hanya di RME Dokter.
const isDoctorAccount = computed(() => {
  const prof = (auth.user?.employee?.profession ?? '').toLowerCase()
  const role = (auth.user?.role?.name ?? '').toLowerCase()
  const rdisp = (auth.user?.role?.display_name ?? '').toLowerCase()
  return prof.includes('dokter') || role.includes('dokter') || rdisp.includes('dokter')
})
const doctorName = computed(() => {
  const n = auth.user?.employee?.name ?? auth.user?.name ?? ''
  if (!n) return 'Dokter'
  return /^dr\.?\s/i.test(n) ? n : `dr. ${n}`
})
const doctorSip = computed(() => auth.user?.employee?.sip ?? '')
</script>

<template>
  <header class="topbar">
    <h1 class="topbar-title">{{ title }}</h1>
    <div class="topbar-breadcrumb">
      <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      <span>{{ title }}</span>
    </div>
    <div class="topbar-right">
      <div v-if="isDoctorAccount" class="topbar-doctor">
        <span class="topbar-doctor-name">{{ doctorName }}</span>
        <span class="topbar-doctor-sip">SIP: {{ doctorSip || '—' }}</span>
      </div>
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
.topbar-doctor {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  line-height: 1.2;
  padding-right: 0.75rem;
  border-right: 1px solid var(--gb);
}
.topbar-doctor-name { font-size: 13px; font-weight: 700; color: #000; }
.topbar-doctor-sip { font-size: 11px; font-weight: 500; color: #000; }
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
