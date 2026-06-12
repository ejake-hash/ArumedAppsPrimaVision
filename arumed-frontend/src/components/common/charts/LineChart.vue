<script setup>
/** LineChart — wrapper tipis vue-chartjs. Props: data (chart.js data) + options. */
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import { Chart as ChartJS, Title, Tooltip, Legend, PointElement, LineElement, CategoryScale, LinearScale, Filler } from 'chart.js'

ChartJS.register(Title, Tooltip, Legend, PointElement, LineElement, CategoryScale, LinearScale, Filler)

const props = defineProps({
  data:    { type: Object, required: true },
  options: { type: Object, default: () => ({}) },
  height:  { type: String, default: '260px' },
})

const mergedOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
  ...props.options,
}))
</script>

<template>
  <div class="chart-box" :style="{ height }">
    <Line :data="data" :options="mergedOptions" />
  </div>
</template>

<style scoped>
.chart-box { position: relative; width: 100%; }
</style>
