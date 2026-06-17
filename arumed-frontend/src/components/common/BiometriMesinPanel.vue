<script setup>
import { computed } from 'vue'

// Tampilan READ-ONLY data biometri mentah dari alat (Quantel Compact Touch),
// disimpan di expertise_data.biometry oleh PenunjangIngestService. Dipakai bersama
// di PenunjangView (operator) & DokterView (modal hasil). Tidak mengubah data —
// hanya menyajikan AL/ACD/K1/K2/KCor + tabel hitung IOL per formula/implan.
const props = defineProps({
  biometry: { type: Object, default: null },   // { exam_date, physician, exam_kind, eyes: { OD, OS } }
})

const EYES = ['OD', 'OS']

const eyesPresent = computed(() =>
  EYES.filter((e) => props.biometry?.eyes?.[e]),
)

function n(v, d = 2) {
  if (v === null || v === undefined || v === '') return '—'
  const num = Number(v)
  return Number.isNaN(num) ? '—' : num.toFixed(d)
}

function fmtDate(d) {
  if (!d) return '—'
  const dt = new Date(d)
  return Number.isNaN(dt.getTime()) ? '—' : dt.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
}
</script>

<template>
  <div v-if="eyesPresent.length" class="bm-panel">
    <div class="bm-head">
      <span class="bm-badge">Data alat Quantel</span>
      <span v-if="biometry.exam_date">· {{ fmtDate(biometry.exam_date) }}</span>
      <span v-if="biometry.physician">· dr. {{ biometry.physician }}</span>
    </div>

    <div class="bm-eyes">
      <div v-for="eye in eyesPresent" :key="eye" class="bm-eye">
        <div class="bm-eye-title">{{ eye === 'OD' ? 'OD (Mata Kanan)' : 'OS (Mata Kiri)' }}</div>

        <!-- Biometri inti -->
        <table class="bm-table">
          <tbody>
            <tr><td>Axial Length (mm)</td><td>{{ n(biometry.eyes[eye].biometry?.axial_length) }}</td></tr>
            <tr><td>ACD (mm)</td><td>{{ n(biometry.eyes[eye].biometry?.acd) }}</td></tr>
            <tr><td>Lens Thickness (mm)</td><td>{{ n(biometry.eyes[eye].biometry?.lens_thickness) }}</td></tr>
            <tr><td>Vitreous (mm)</td><td>{{ n(biometry.eyes[eye].biometry?.vitreous) }}</td></tr>
            <tr><td>K1 (D)</td><td>{{ n(biometry.eyes[eye].biometry?.k1) }}</td></tr>
            <tr><td>K2 (D)</td><td>{{ n(biometry.eyes[eye].biometry?.k2) }}</td></tr>
            <tr><td>K rata-rata (D)</td><td>{{ n(biometry.eyes[eye].biometry?.kcor) }}</td></tr>
            <tr v-if="biometry.eyes[eye].biometry?.technique"><td>Teknik</td><td>{{ biometry.eyes[eye].biometry.technique }}</td></tr>
          </tbody>
        </table>

        <!-- Hitung IOL per formula / implan -->
        <template v-if="biometry.eyes[eye].iol_calc?.length">
          <div class="bm-calc-title">Perhitungan IOL</div>
          <div v-for="(calc, ci) in biometry.eyes[eye].iol_calc" :key="ci" class="bm-calc">
            <div class="bm-calc-head">
              <b>{{ calc.formula || 'Formula —' }}</b>
              <span v-if="calc.implant_designation">· {{ calc.implant_designation }}</span>
              <span v-if="calc.a_constant != null" class="bm-a">A {{ n(calc.a_constant, 1) }}</span>
              <span class="bm-emme">Emetropia: <b>{{ n(calc.emmetropia_power, 1) }} D</b></span>
            </div>
            <table v-if="calc.results?.length" class="bm-table bm-results">
              <thead><tr><th>Power (D)</th><th>Prediksi Refraksi</th></tr></thead>
              <tbody>
                <tr v-for="(r, ri) in calc.results" :key="ri" :class="{ 'bm-median': r.median }">
                  <td>{{ n(r.power, 1) }}</td>
                  <td>{{ n(r.predicted_ref, 2) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bm-panel { border: 1px solid var(--gb, #e2e8f0); border-radius: 10px; padding: 12px; background: var(--bs, #f8fafc); margin-top: 10px; }
.bm-head { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 11px; color: var(--tu, #64748b); margin-bottom: 10px; }
.bm-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #ecfeff; color: #0e7490; font-weight: 700; font-size: 10px; }
.bm-eyes { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.bm-eye { border: 1px solid var(--gb, #e2e8f0); border-radius: 8px; padding: 10px; background: #fff; }
.bm-eye-title { font-size: 11.5px; font-weight: 700; color: var(--td, #0f172a); margin-bottom: 6px; }
.bm-table { width: 100%; border-collapse: collapse; font-size: 11px; }
.bm-table td, .bm-table th { padding: 3px 6px; border-bottom: 1px solid var(--gb, #eef2f7); text-align: left; }
.bm-table td:last-child, .bm-table th:last-child { text-align: right; font-variant-numeric: tabular-nums; }
.bm-table th { color: var(--tu, #64748b); font-weight: 600; }
.bm-calc-title { font-size: 11px; font-weight: 700; color: var(--td, #0f172a); margin: 10px 0 4px; }
.bm-calc { border: 1px solid var(--gb, #eef2f7); border-radius: 6px; padding: 6px 8px; margin-bottom: 6px; }
.bm-calc-head { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 10.5px; color: var(--tm, #475569); margin-bottom: 4px; }
.bm-a { padding: 1px 6px; border-radius: 4px; background: #f1f5f9; font-weight: 700; }
.bm-emme { margin-left: auto; color: #0e7490; }
.bm-results { margin-top: 2px; }
.bm-results thead th { font-size: 10px; }
.bm-median td { background: #ecfdf5; font-weight: 700; color: #047857; }

@media (max-width: 640px) {
  .bm-eyes { grid-template-columns: 1fr; }
}
</style>
