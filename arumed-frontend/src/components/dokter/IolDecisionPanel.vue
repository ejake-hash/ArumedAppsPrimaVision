<script setup>
/**
 * Panel Keputusan IOL berbasis biometri Quantel.
 * Menampilkan nilai biometri + tabel hitung IOL (per A-constant/formula) lalu
 * dokter memilih lensa master + power + target → tersimpan sbg keputusan final
 * yang dibaca Bedah untuk request IOL/BHP ke gudang.
 */
import { ref, reactive, computed, watch } from 'vue'
import { dokterApi } from '@/services/api'

const props = defineProps({
  visitId: { type: String, default: null },
})

const loading = ref(false)
const loaded  = ref(false)
const data    = ref(null)            // { biometry, iol_masters, decisions, result_id }
const forms   = reactive({})         // per-eye: { iol_item_id, recommended_power, formula, a_constant, target_refraction, predicted_refraction, notes, saving }

const eyes = computed(() => {
  const e = data.value?.biometry?.eyes || {}
  return ['OD', 'OS'].filter((k) => e[k])
})
const masters = computed(() => data.value?.iol_masters || [])

function blankForm() {
  return { iol_item_id: '', recommended_power: null, formula: '', a_constant: null, target_refraction: null, predicted_refraction: null, notes: '', saving: false }
}

async function load() {
  if (!props.visitId) return
  loading.value = true
  try {
    const { data: res } = await dokterApi.biometriIol(props.visitId)
    data.value = res?.data ?? res
    // Seed form per mata dari keputusan tersimpan (bila ada).
    for (const eye of ['OD', 'OS']) {
      const dec = data.value?.decisions?.[eye]
      forms[eye] = blankForm()
      if (dec) {
        forms[eye].iol_item_id          = dec.iol_item_id || ''
        forms[eye].recommended_power     = dec.recommended_power != null ? Number(dec.recommended_power) : null
        forms[eye].formula               = dec.formula || ''
        forms[eye].a_constant            = dec.a_constant != null ? Number(dec.a_constant) : null
        forms[eye].target_refraction     = dec.target_refraction != null ? Number(dec.target_refraction) : null
        forms[eye].predicted_refraction  = dec.predicted_refraction != null ? Number(dec.predicted_refraction) : null
        forms[eye].notes                 = dec.notes || ''
      }
    }
    loaded.value = true
  } catch (e) {
    data.value = null
  } finally {
    loading.value = false
  }
}

watch(() => props.visitId, () => { loaded.value = false; data.value = null })

// Entri tabel hitung yang cocok dengan A-constant lensa terpilih (untuk prediksi).
function matchedCalc(eye) {
  const f = forms[eye]
  const calc = data.value?.biometry?.eyes?.[eye]?.iol_calc || []
  if (f?.a_constant == null) return null
  return calc.find((c) => Math.abs(Number(c.a_constant) - Number(f.a_constant)) < 0.001) || null
}

// Saat dokter pilih lensa master → set A-constant + tipe + tarik formula/power dari tabel.
function onPickMaster(eye) {
  const f = forms[eye]
  const m = masters.value.find((x) => x.id === f.iol_item_id)
  if (!m) return
  f.a_constant = m.a_constant
  const calc = matchedCalc(eye)
  if (calc) {
    f.formula = calc.formula || f.formula
    // default power = baris median bila ada, atau emmetropia.
    const med = (calc.results || []).find((r) => r.median)
    if (med) { f.recommended_power = med.power; f.predicted_refraction = med.predicted_ref }
    else if (calc.emmetropia_power != null) { f.recommended_power = Math.round(calc.emmetropia_power * 2) / 2 }
  }
  // Kalau master punya power tetap, hormati.
  if (m.power != null) f.recommended_power = m.power
  recompute(eye)
}

// Prediksi refraksi dari baris power terdekat pada entri tabel yang cocok.
function recompute(eye) {
  const f = forms[eye]
  const calc = matchedCalc(eye)
  if (!calc || f.recommended_power == null) return
  let best = null, bestD = 1e9
  for (const r of (calc.results || [])) {
    const d = Math.abs(Number(r.power) - Number(f.recommended_power))
    if (d < bestD) { bestD = d; best = r }
  }
  if (best) f.predicted_refraction = best.predicted_ref
}

function masterLabel(m) {
  const parts = [m.label || m.brand]
  if (m.power != null) parts.push(`${m.power}D`)
  if (m.a_constant != null) parts.push(`A=${m.a_constant}`)
  parts.push(m.on_hand > 0 ? `stok ${m.on_hand}` : 'stok 0')
  return parts.filter(Boolean).join(' · ')
}

const emit = defineEmits(['saved'])

async function save(eye) {
  const f = forms[eye]
  if (!f.iol_item_id && f.recommended_power == null) return
  f.saving = true
  try {
    await dokterApi.decideIol(props.visitId, {
      eye_side: eye,
      iol_item_id: f.iol_item_id || null,
      diagnostic_result_id: data.value?.result_id || null,
      recommended_power: f.recommended_power,
      formula: f.formula || null,
      a_constant: f.a_constant,
      target_refraction: f.target_refraction,
      predicted_refraction: f.predicted_refraction,
      notes: f.notes || null,
    })
    emit('saved', eye)
    await load()
  } finally {
    f.saving = false
  }
}

defineExpose({ load })
</script>

<template>
  <div class="iolp">
    <div class="iolp-head">
      <b>Keputusan IOL (dari Biometri)</b>
      <button type="button" class="iolp-btn ghost" :disabled="loading" @click="load">
        {{ loaded ? 'Muat ulang' : 'Muat data biometri' }}
      </button>
    </div>

    <div v-if="loading" class="iolp-muted">Memuat…</div>

    <div v-else-if="loaded && !data?.biometry" class="iolp-muted">
      Belum ada hasil biometri terstruktur untuk kunjungan ini. (Hasil alat Quantel akan muncul otomatis setelah ter-ingest.)
    </div>

    <div v-else-if="loaded" class="iolp-eyes">
      <div v-for="eye in eyes" :key="eye" class="iolp-eye">
        <div class="iolp-eye-head">{{ eye === 'OD' ? 'Mata Kanan (OD)' : 'Mata Kiri (OS)' }}</div>

        <!-- Nilai biometri inti -->
        <div class="iolp-bio">
          <span>AL <b>{{ data.biometry.eyes[eye].biometry.axial_length ?? '—' }}</b> mm</span>
          <span>ACD <b>{{ data.biometry.eyes[eye].biometry.acd ?? '—' }}</b></span>
          <span>K1 <b>{{ data.biometry.eyes[eye].biometry.k1 ?? '—' }}</b></span>
          <span>K2 <b>{{ data.biometry.eyes[eye].biometry.k2 ?? '—' }}</b></span>
        </div>

        <!-- Tabel hitung IOL per implan/A-constant -->
        <div class="iolp-calc">
          <div v-for="(c, ci) in data.biometry.eyes[eye].iol_calc" :key="ci" class="iolp-calc-blk">
            <div class="iolp-calc-h">
              {{ c.implant_designation || 'Lensa' }} · A={{ c.a_constant }} · {{ c.formula }}
              <small v-if="c.emmetropia_power != null">(emetropia {{ Number(c.emmetropia_power).toFixed(2) }}D)</small>
            </div>
            <div class="iolp-calc-rows">
              <span
                v-for="(r, ri) in c.results" :key="ri"
                :class="['iolp-pw', r.median ? 'median' : '']"
                :title="`Prediksi refraksi ${Number(r.predicted_ref).toFixed(2)}`"
              >{{ r.power }}D <em>{{ Number(r.predicted_ref).toFixed(2) }}</em></span>
            </div>
          </div>
        </div>

        <!-- Form keputusan -->
        <div class="iolp-form">
          <label>Lensa (master + stok)
            <select v-model="forms[eye].iol_item_id" class="form-select" @change="onPickMaster(eye)">
              <option value="">— pilih lensa —</option>
              <option v-for="m in masters" :key="m.id" :value="m.id">{{ masterLabel(m) }}</option>
            </select>
          </label>
          <label>Power (D)
            <input type="number" step="0.5" v-model.number="forms[eye].recommended_power" class="form-input" @input="recompute(eye)" />
          </label>
          <label>Formula
            <input type="text" v-model="forms[eye].formula" class="form-input" placeholder="SRK/T" />
          </label>
          <label>Target refraksi
            <input type="number" step="0.25" v-model.number="forms[eye].target_refraction" class="form-input" />
          </label>
          <label>Prediksi refraksi
            <input type="number" step="0.01" v-model.number="forms[eye].predicted_refraction" class="form-input" readonly />
          </label>
          <label class="iolp-notes">Catatan
            <input type="text" v-model="forms[eye].notes" class="form-input" />
          </label>
          <div class="iolp-actions">
            <span v-if="data.decisions?.[eye]?.is_final" class="iolp-saved-badge">✓ tersimpan</span>
            <button type="button" class="iolp-btn" :disabled="forms[eye].saving" @click="save(eye)">
              {{ forms[eye].saving ? 'Menyimpan…' : 'Simpan keputusan ' + eye }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.iolp { border: 1px solid var(--border, #e2e8f0); border-radius: 10px; padding: 12px; margin-top: 12px; background: #fafcff; }
.iolp-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.iolp-muted { color: #64748b; font-size: 13px; padding: 6px 0; }
.iolp-eyes { display: grid; gap: 12px; }
.iolp-eye { border: 1px solid #e6eef7; border-radius: 8px; padding: 10px; background: #fff; }
.iolp-eye-head { font-weight: 700; color: #0E3A66; margin-bottom: 6px; }
.iolp-bio { display: flex; flex-wrap: wrap; gap: 12px; font-size: 13px; color: #334155; margin-bottom: 8px; }
.iolp-bio b { color: #0f172a; }
.iolp-calc { display: grid; gap: 6px; margin-bottom: 10px; }
.iolp-calc-blk { background: #f1f6fc; border-radius: 6px; padding: 6px 8px; }
.iolp-calc-h { font-size: 12px; font-weight: 600; color: #1FAAE0; margin-bottom: 4px; }
.iolp-calc-h small { color: #64748b; font-weight: 400; }
.iolp-calc-rows { display: flex; flex-wrap: wrap; gap: 6px; }
.iolp-pw { font-size: 12px; background: #fff; border: 1px solid #dbe6f2; border-radius: 4px; padding: 2px 6px; }
.iolp-pw em { color: #64748b; font-style: normal; }
.iolp-pw.median { border-color: #1FAAE0; background: #e6f6fd; font-weight: 600; }
.iolp-form { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.iolp-form label { display: flex; flex-direction: column; font-size: 12px; color: #475569; gap: 3px; }
.iolp-notes { grid-column: 1 / -1; }
.iolp-actions { grid-column: 1 / -1; display: flex; align-items: center; justify-content: flex-end; gap: 10px; }
.iolp-saved-badge { color: #16a34a; font-size: 12px; font-weight: 600; }
.iolp-btn { background: #0E3A66; color: #fff; border: none; border-radius: 6px; padding: 7px 12px; font-size: 13px; cursor: pointer; }
.iolp-btn.ghost { background: transparent; color: #0E3A66; border: 1px solid #0E3A66; padding: 5px 10px; }
.iolp-btn:disabled { opacity: .6; cursor: default; }
@media (max-width: 640px) { .iolp-form { grid-template-columns: 1fr; } }
</style>
