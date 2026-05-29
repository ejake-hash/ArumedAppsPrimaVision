<script setup>
/**
 * MorseFallScale — contoh CUSTOM_COMPONENT.
 *
 * Pakai use case: SCORED_FORM declarative cukup untuk MFS, tapi component
 * ini demonstrasi UI custom yang lebih dense + visual (gauge indikator
 * risiko). Sama-sama compute lokal.
 */
import { computed, ref, watch } from 'vue'

const props = defineProps({
  template: { type: Object, required: true },
  modelValue: { type: Object, default: () => ({}) },
  readonly: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const items = [
  { key: 'riwayat_jatuh',      label: 'Riwayat jatuh (3 bulan)',
    options: [{ label: 'Tidak', score: 0 }, { label: 'Ya', score: 25 }] },
  { key: 'diagnosis_sekunder', label: 'Diagnosis sekunder',
    options: [{ label: 'Tidak', score: 0 }, { label: 'Ya', score: 15 }] },
  { key: 'alat_bantu',         label: 'Alat bantu jalan',
    options: [
      { label: 'Tidak ada / bedrest', score: 0 },
      { label: 'Kruk / walker',       score: 15 },
      { label: 'Pegangan furnitur',   score: 30 },
    ] },
  { key: 'iv_terapi',          label: 'Terpasang infus',
    options: [{ label: 'Tidak', score: 0 }, { label: 'Ya', score: 20 }] },
  { key: 'cara_jalan',         label: 'Cara berjalan',
    options: [
      { label: 'Normal / bedrest',     score: 0 },
      { label: 'Lemah / lemah lutut',  score: 10 },
      { label: 'Terganggu / tak seimbang', score: 20 },
    ] },
  { key: 'status_mental',      label: 'Status mental',
    options: [{ label: 'Sadar', score: 0 }, { label: 'Tidak sadar', score: 15 }] },
]

const local = ref({ ...props.modelValue })

watch(local, (v) => emit('update:modelValue', { ...v }), { deep: true })
watch(() => props.modelValue, (v) => {
  // Sync external changes (mis. dari useScoring inject computed)
  Object.assign(local.value, v)
}, { deep: true })

const totalScore = computed(() => items.reduce((sum, it) => {
  const v = local.value[it.key]
  return typeof v === 'number' ? sum + v : sum
}, 0))

const interpretation = computed(() => {
  const s = totalScore.value
  if (s <= 24)  return { label: 'Rendah',  color: '#1e8a3a', bg: '#ecf9ee' }
  if (s <= 44)  return { label: 'Sedang',  color: '#946600', bg: '#fff7ec' }
  return                { label: 'Tinggi',  color: '#b42323', bg: '#fff0f0' }
})

function pick(key, score) {
  if (props.readonly) return
  local.value[key] = score
}
</script>

<template>
  <div class="mfs-wrap">
    <header class="mfs-head">
      <strong>Morse Fall Scale</strong>
      <span class="mfs-sub">Asesmen risiko jatuh dewasa — 6 item</span>
    </header>

    <div v-for="it in items" :key="it.key" class="mfs-row">
      <div class="mfs-row-label">
        {{ it.label }}
        <span v-if="local[it.key] !== undefined" class="mfs-row-score">+{{ local[it.key] }}</span>
      </div>
      <div class="mfs-row-opts">
        <button
          v-for="opt in it.options"
          :key="opt.label"
          type="button"
          class="mfs-opt"
          :class="{ active: local[it.key] === opt.score }"
          :disabled="readonly"
          @click="pick(it.key, opt.score)"
        >
          {{ opt.label }} <span class="mfs-pts">{{ opt.score }}</span>
        </button>
      </div>
    </div>

    <footer class="mfs-foot" :style="{ background: interpretation.bg, color: interpretation.color }">
      <div class="mfs-total">
        <strong>{{ totalScore }}</strong>
        <span>Total Skor</span>
      </div>
      <div class="mfs-interp">
        <strong>Risiko {{ interpretation.label }}</strong>
        <span>≤24 rendah · 25-44 sedang · ≥45 tinggi</span>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.mfs-wrap { display: flex; flex-direction: column; gap: 0.6rem; }
.mfs-head { display: flex; flex-direction: column; gap: 4px; padding-bottom: 0.5rem; border-bottom: 1px solid var(--gb); }
.mfs-head strong { font-size: 14.5px; color: var(--td); font-family: 'DM Serif Display', serif; }
.mfs-sub { font-size: 11.5px; color: var(--tm); }

.mfs-row { display: grid; grid-template-columns: 200px 1fr; gap: 0.6rem; padding: 0.4rem 0; }
.mfs-row-label { font-size: 13px; color: var(--td); display: flex; align-items: center; gap: 0.5rem; }
.mfs-row-score { padding: 1px 7px; background: var(--pri); color: white; border-radius: 999px; font-size: 10.5px; font-weight: 700; }

.mfs-row-opts { display: flex; gap: 0.3rem; flex-wrap: wrap; }
.mfs-opt {
  padding: 0.35rem 0.7rem; background: white; border: 1px solid var(--gb); border-radius: 5px;
  cursor: pointer; font-size: 12px; transition: all 120ms;
}
.mfs-opt:hover:not(:disabled) { background: var(--bg); border-color: #b8c1cf; }
.mfs-opt.active { background: var(--pri); color: white; border-color: var(--pri); }
.mfs-opt:disabled { opacity: 0.5; cursor: not-allowed; }
.mfs-pts { margin-left: 0.3rem; opacity: 0.7; font-weight: 700; }

.mfs-foot {
  margin-top: 0.5rem;
  padding: 0.85rem 1rem;
  border: 1px solid currentColor;
  border-radius: 8px;
  display: flex; justify-content: space-between; align-items: center;
}
.mfs-total { display: flex; flex-direction: column; align-items: flex-start; }
.mfs-total strong { font-size: 28px; line-height: 1; }
.mfs-total span { font-size: 11px; opacity: 0.85; }
.mfs-interp { text-align: right; }
.mfs-interp strong { font-size: 14px; }
.mfs-interp span { display: block; font-size: 10.5px; opacity: 0.8; }
</style>
