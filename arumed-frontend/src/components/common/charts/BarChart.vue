<script setup>
/** BarChart — wrapper tipis vue-chartjs. Props: data (chart.js data) + options. */
import { computed } from 'vue'
import { Bar } from 'vue-chartjs'
import { Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale } from 'chart.js'

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale)

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
    <Bar :data="data" :options="mergedOptions" />
  </div>
</template>

<style scoped>
.chart-box { position: relative; width: 100%; }
</style>
