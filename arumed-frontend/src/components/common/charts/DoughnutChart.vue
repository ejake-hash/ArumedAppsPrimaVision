<script setup>
/** DoughnutChart — wrapper tipis vue-chartjs. Props: data (chart.js data) + options. */
import { computed } from 'vue'
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, Title, Tooltip, Legend, ArcElement } from 'chart.js'

ChartJS.register(Title, Tooltip, Legend, ArcElement)

const props = defineProps({
  data:    { type: Object, required: true },
  options: { type: Object, default: () => ({}) },
  height:  { type: String, default: '260px' },
})

const mergedOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  cutout: '62%',
  plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
  ...props.options,
}))
</script>

<template>
  <div class="chart-box" :style="{ height }">
    <Doughnut :data="data" :options="mergedOptions" />
  </div>
</template>

<style scoped>
.chart-box { position: relative; width: 100%; }
</style>
