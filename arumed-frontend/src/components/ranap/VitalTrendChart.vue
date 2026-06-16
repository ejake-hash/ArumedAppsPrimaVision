<script setup>
import { ref, computed } from 'vue'
import LineChart from '@/components/common/charts/LineChart.vue'

// entries: daftar CPPT (terbaru dulu, format dari ranapApi.cpptList) — memuat
// TTV + ews_score/ews_level (calc-on-read dari backend).
const props = defineProps({ entries: { type: Array, default: () => [] } })

// Parameter yang bisa diplot (1 seri aktif agar skala terbaca).
const SERIES = [
  { key: 'ews_score',  label: 'EWS',       color: '#dc2626', unit: '' },
  { key: 'td_sistol',  label: 'TD Sistol', color: '#1763d4', unit: 'mmHg' },
  { key: 'nadi',       label: 'Nadi',      color: '#db2777', unit: '×/mnt' },
  { key: 'suhu',       label: 'Suhu',      color: '#d97706', unit: '°C' },
  { key: 'spo2',       label: 'SpO₂',      color: '#0891b2', unit: '%' },
  { key: 'respirasi',  label: 'RR',        color: '#15803d', unit: '×/mnt' },
  { key: 'pain_scale', label: 'Nyeri',     color: '#7c3aed', unit: '/10' },
]

const active = ref('ews_score')
const activeSeries = computed(() => SERIES.find((s) => s.key === active.value) || SERIES[0])

// Kronologis (lama → baru) untuk sumbu X.
const chrono = computed(() => [...props.entries].reverse())

function fmtTime(at) {
  if (!at) return '—'
  return new Date(at).toLocaleString('id-ID', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

// Hanya tampil bila ada minimal 1 titik untuk seri aktif.
const hasData = computed(() => chrono.value.some((e) => e[active.value] != null && e[active.value] !== ''))

const chartData = computed(() => {
  const s = activeSeries.value
  return {
    labels: chrono.value.map((e) => fmtTime(e.at)),
    datasets: [{
      label: s.label,
      data: chrono.value.map((e) => (e[s.key] != null && e[s.key] !== '' ? Number(e[s.key]) : null)),
      borderColor: s.color,
      backgroundColor: s.color + '22',
      pointBackgroundColor: s.color,
      borderWidth: 2,
      tension: 0.25,
      spanGaps: true,
      fill: true,
    }],
  }
})

const chartOptions = computed(() => ({
  scales: { y: { beginAtZero: active.value === 'ews_score' || active.value === 'pain_scale' } },
  plugins: { tooltip: { callbacks: { label: (c) => `${c.parsed.y ?? '—'} ${activeSeries.value.unit}` } } },
}))

// EWS terkini (entri terbaru yang punya ews_level).
const latestEws = computed(() => props.entries.find((e) => e.ews_level))
const EWS_CLASS = { HIJAU: 'ews-green', KUNING: 'ews-yellow', MERAH: 'ews-red' }
</script>

<template>
  <div class="vtc">
    <div class="vtc-head">
      <div class="vtc-series">
        <button
          v-for="s in SERIES" :key="s.key"
          type="button" class="vtc-pill" :class="{ on: active === s.key }"
          :style="active === s.key ? { background: s.color, borderColor: s.color, color: '#fff' } : {}"
          @click="active = s.key"
        >{{ s.label }}</button>
      </div>
      <div v-if="latestEws" class="vtc-ews" :class="EWS_CLASS[latestEws.ews_level]">
        EWS terkini: <strong>{{ latestEws.ews_score }}</strong> · {{ latestEws.ews_label }}
        <span v-if="latestEws.ews_level === 'MERAH'" class="vtc-escalate">⚠ perlu eskalasi</span>
      </div>
    </div>
    <LineChart v-if="hasData" :data="chartData" :options="chartOptions" height="220px" />
    <p v-else class="vtc-empty">Belum ada data {{ activeSeries.label }} untuk digrafikkan. Isi tanda vital di CPPT.</p>
  </div>
</template>

<style scoped>
.vtc { border: 1px solid var(--gb); border-radius: 10px; padding: .7rem; background: var(--bc); margin-bottom: 1rem; }
.vtc-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .6rem; margin-bottom: .6rem; }
.vtc-series { display: flex; flex-wrap: wrap; gap: 5px; }
.vtc-pill { height: 26px; padding: 0 11px; border: 1px solid var(--gb); border-radius: 999px; background: var(--bc); color: var(--tm); font-family: inherit; font-size: 11.5px; font-weight: 600; cursor: pointer; transition: all .14s; }
.vtc-pill:hover { border-color: var(--ga); color: var(--td); }
.vtc-ews { font-size: 12px; padding: .35rem .7rem; border-radius: 999px; font-weight: 600; }
.vtc-ews strong { font-size: 14px; }
.ews-green { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.ews-yellow { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }
.ews-red { background: var(--eb); color: var(--et); border: 1px solid var(--ebd); }
.vtc-escalate { margin-left: 6px; font-weight: 700; }
.vtc-empty { font-size: 12px; color: var(--tu); text-align: center; padding: 1.5rem 0; margin: 0; }
</style>
