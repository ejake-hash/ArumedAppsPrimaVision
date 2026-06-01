<script setup>
/**
 * AnesthesiaMonitorPanel — pencatatan tanda vital anestesi DURANTE operasi.
 *
 * Tabel baris-waktu (real-time): tiap baris = 1 pembacaan vital pada jam tertentu.
 * Add/edit/delete langsung hit backend (pola sama dengan pemakaian alat di
 * BedahView). Grafik SVG (tanpa dependency) menggambar TD-sistol/diastol, Nadi,
 * SpO2 terhadap waktu.
 *
 * Butuh `recordId` (surgery_records.id) — terbentuk setelah "Mulai Operasi".
 */
import { ref, computed, watch, onMounted } from 'vue'
import { bedahApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'

const props = defineProps({
  recordId: { type: String, default: null },
  disabled: { type: Boolean, default: false },
})

// Kunci edit: hanya role dengan anestesi.write (dokter anestesi) boleh isi/edit/
// hapus. Baca tetap boleh semua tim bedah. Gabung dengan prop disabled
// (laporan finalized). `readonly` dipakai untuk sembunyikan form + tombol aksi.
const auth = useAuthStore()
// canRead: tanpa anestesi.read, panel disembunyikan total (UI only — endpoint
// GET tetap terbuka). canWrite: kunci isi/edit/hapus (hanya dokter anestesi).
const canRead = computed(() => auth.can('anestesi.read'))
const canWrite = computed(() => auth.can('anestesi.write'))
const readonly = computed(() => props.disabled || !canWrite.value)

const rows = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')

// Form baris baru (prefill jam = sekarang HH:MM).
function blankDraft() {
  return {
    recorded_at: nowHHMM(),
    td_sistol: '', td_diastol: '', nadi: '',
    spo2: '', rr: '', etco2: '', suhu: '',
    obat_kejadian: '',
  }
}
const draft = ref(blankDraft())
const editingId = ref(null)

function nowHHMM() {
  const d = new Date()
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

// HH:MM → ISO datetime hari ini (backend simpan timestamp penuh).
function hhmmToIso(hhmm) {
  if (!hhmm) return null
  const [h, m] = hhmm.split(':')
  const d = new Date()
  d.setHours(Number(h) || 0, Number(m) || 0, 0, 0)
  return d.toISOString()
}

function isoToHHMM(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

async function load() {
  if (!canRead.value) { rows.value = []; return }
  if (!props.recordId) { rows.value = []; return }
  loading.value = true
  error.value = ''
  try {
    const { data } = await bedahApi.listAnesthesiaVitals(props.recordId)
    rows.value = data.data ?? []
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Gagal memuat data vital.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.recordId, load)

// Bersihkan angka: '' → null, selain itu Number.
function num(v) {
  if (v === '' || v === null || v === undefined) return null
  const n = Number(v)
  return Number.isNaN(n) ? null : n
}

function buildPayload(d) {
  return {
    recorded_at: hhmmToIso(d.recorded_at),
    td_sistol: num(d.td_sistol),
    td_diastol: num(d.td_diastol),
    nadi: num(d.nadi),
    spo2: num(d.spo2),
    rr: num(d.rr),
    etco2: num(d.etco2),
    suhu: num(d.suhu),
    obat_kejadian: d.obat_kejadian?.trim() || null,
  }
}

async function saveDraft() {
  if (readonly.value || !props.recordId) return
  saving.value = true
  error.value = ''
  try {
    if (editingId.value) {
      await bedahApi.updateAnesthesiaVital(editingId.value, buildPayload(draft.value))
    } else {
      await bedahApi.recordAnesthesiaVital({ surgery_record_id: props.recordId, ...buildPayload(draft.value) })
    }
    draft.value = blankDraft()
    editingId.value = null
    await load()
  } catch (e) {
    const errs = e.response?.data?.errors
    error.value = errs ? Object.values(errs).flat().join(', ') : (e.response?.data?.message ?? 'Gagal menyimpan.')
  } finally {
    saving.value = false
  }
}

function editRow(r) {
  if (readonly.value) return
  editingId.value = r.id
  draft.value = {
    recorded_at: isoToHHMM(r.recorded_at),
    td_sistol: r.td_sistol ?? '', td_diastol: r.td_diastol ?? '', nadi: r.nadi ?? '',
    spo2: r.spo2 ?? '', rr: r.rr ?? '', etco2: r.etco2 ?? '', suhu: r.suhu ?? '',
    obat_kejadian: r.obat_kejadian ?? '',
  }
}

function cancelEdit() {
  editingId.value = null
  draft.value = blankDraft()
}

async function deleteRow(r) {
  if (readonly.value) return
  if (!confirm(`Hapus pembacaan jam ${isoToHHMM(r.recorded_at)}?`)) return
  try {
    await bedahApi.deleteAnesthesiaVital(r.id)
    await load()
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Gagal menghapus.'
  }
}

// ── Grafik SVG ────────────────────────────────────────────────────────────
const CHART_W = 640
const CHART_H = 220
const PAD = { l: 36, r: 12, t: 12, b: 24 }

// Series: key → warna + label.
const SERIES = [
  { key: 'td_sistol',  color: '#d83b3b', label: 'TD Sistol' },
  { key: 'td_diastol', color: '#e08a2b', label: 'TD Diastol' },
  { key: 'nadi',       color: '#1763d4', label: 'Nadi' },
  { key: 'spo2',       color: '#1e8a3a', label: 'SpO₂' },
]

const sortedRows = computed(() =>
  [...rows.value].sort((a, b) => new Date(a.recorded_at) - new Date(b.recorded_at)),
)

// Domain X = index baris (0..n-1) supaya spasi rata; label = jam.
// Domain Y = min/max semua nilai numerik (auto-scale) dengan sedikit margin.
const yDomain = computed(() => {
  let min = Infinity, max = -Infinity
  for (const r of sortedRows.value) {
    for (const s of SERIES) {
      const v = r[s.key]
      if (v === null || v === undefined || v === '') continue
      const n = Number(v)
      if (Number.isNaN(n)) continue
      if (n < min) min = n
      if (n > max) max = n
    }
  }
  if (min === Infinity) return [0, 100]
  if (min === max) { min -= 5; max += 5 }
  const margin = (max - min) * 0.1
  return [Math.max(0, Math.floor(min - margin)), Math.ceil(max + margin)]
})

function xAt(i) {
  const n = sortedRows.value.length
  if (n <= 1) return PAD.l + (CHART_W - PAD.l - PAD.r) / 2
  return PAD.l + (i / (n - 1)) * (CHART_W - PAD.l - PAD.r)
}

function yAt(val) {
  const [lo, hi] = yDomain.value
  const t = (val - lo) / (hi - lo || 1)
  return CHART_H - PAD.b - t * (CHART_H - PAD.t - PAD.b)
}

// Polyline points untuk satu series (lewati nilai kosong).
function linePoints(key) {
  const pts = []
  sortedRows.value.forEach((r, i) => {
    const v = r[key]
    if (v === null || v === undefined || v === '') return
    const n = Number(v)
    if (Number.isNaN(n)) return
    pts.push(`${xAt(i).toFixed(1)},${yAt(n).toFixed(1)}`)
  })
  return pts.join(' ')
}

// Tick Y (4 garis).
const yTicks = computed(() => {
  const [lo, hi] = yDomain.value
  const out = []
  for (let i = 0; i <= 4; i++) {
    const val = lo + (i / 4) * (hi - lo)
    out.push({ val: Math.round(val), y: yAt(val) })
  }
  return out
})

const hasChartData = computed(() =>
  sortedRows.value.some(r => SERIES.some(s => r[s.key] !== null && r[s.key] !== undefined && r[s.key] !== '')),
)
</script>

<template>
  <div v-if="canRead" class="amp-wrap">
    <div class="amp-head">
      <div class="amp-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h4l2 6 4-14 2 8h6"/></svg>
        Monitoring Anestesi (Durante Operasi)
      </div>
      <span v-if="rows.length" class="amp-count">{{ rows.length }} pembacaan</span>
    </div>

    <!-- Guard: butuh surgery_record (operasi dimulai). -->
    <div v-if="!recordId" class="amp-empty">
      Mulai operasi terlebih dahulu untuk mencatat tanda vital anestesi.
    </div>

    <template v-else>
      <!-- Grafik -->
      <div class="amp-chart-card">
        <div v-if="!hasChartData" class="amp-chart-empty">Grafik akan muncul setelah ada pembacaan vital.</div>
        <svg v-else class="amp-chart" :viewBox="`0 0 ${CHART_W} ${CHART_H}`" preserveAspectRatio="xMidYMid meet">
          <!-- Grid + Y ticks -->
          <g v-for="t in yTicks" :key="'y'+t.val">
            <line :x1="PAD.l" :y1="t.y" :x2="CHART_W - PAD.r" :y2="t.y" stroke="#eceff3" stroke-width="1" />
            <text :x="PAD.l - 6" :y="t.y + 3" text-anchor="end" font-size="9" fill="#8a93a3">{{ t.val }}</text>
          </g>
          <!-- X labels (jam) -->
          <text
            v-for="(r, i) in sortedRows" :key="'x'+i"
            :x="xAt(i)" :y="CHART_H - 6" text-anchor="middle" font-size="8.5" fill="#8a93a3"
          >{{ isoToHHMM(r.recorded_at) }}</text>
          <!-- Series lines + dots -->
          <g v-for="s in SERIES" :key="s.key">
            <polyline :points="linePoints(s.key)" fill="none" :stroke="s.color" stroke-width="1.8"
              stroke-linejoin="round" stroke-linecap="round" />
            <template v-for="(r, i) in sortedRows" :key="s.key + i">
              <circle
                v-if="r[s.key] !== null && r[s.key] !== undefined && r[s.key] !== ''"
                :cx="xAt(i)" :cy="yAt(Number(r[s.key]))" r="2.5" :fill="s.color"
              />
            </template>
          </g>
        </svg>
        <div class="amp-legend">
          <span v-for="s in SERIES" :key="s.key" class="amp-leg-item">
            <span class="amp-leg-dot" :style="{ background: s.color }"></span>{{ s.label }}
          </span>
        </div>
      </div>

      <div v-if="error" class="amp-err">{{ error }}</div>

      <!-- Tabel baris-waktu -->
      <div class="amp-table-wrap">
        <table class="amp-table">
          <thead>
            <tr>
              <th>Jam</th><th>TD</th><th>Nadi</th><th>SpO₂</th><th>RR</th><th>EtCO₂</th><th>Suhu</th>
              <th>Obat / Kejadian</th><th v-if="!readonly"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading"><td :colspan="readonly ? 8 : 9" class="amp-td-state">Memuat…</td></tr>
            <tr v-else-if="rows.length === 0"><td :colspan="readonly ? 8 : 9" class="amp-td-state">Belum ada pembacaan.<span v-if="!readonly"> Tambah di bawah.</span></td></tr>
            <tr v-for="r in sortedRows" :key="r.id" :class="{ 'amp-row-editing': editingId === r.id }">
              <td>{{ isoToHHMM(r.recorded_at) }}</td>
              <td>{{ r.td_sistol ?? '–' }}/{{ r.td_diastol ?? '–' }}</td>
              <td>{{ r.nadi ?? '–' }}</td>
              <td>{{ r.spo2 ?? '–' }}</td>
              <td>{{ r.rr ?? '–' }}</td>
              <td>{{ r.etco2 ?? '–' }}</td>
              <td>{{ r.suhu ?? '–' }}</td>
              <td class="amp-td-note">{{ r.obat_kejadian ?? '' }}</td>
              <td v-if="!readonly" class="amp-td-act">
                <button class="amp-btn-icon" @click="editRow(r)" title="Edit">✎</button>
                <button class="amp-btn-icon amp-btn-del" @click="deleteRow(r)" title="Hapus">×</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Form input baris (real-time) — hanya untuk yang punya anestesi.write -->
      <div v-if="!readonly" class="amp-form">
        <div class="amp-form-grid">
          <label>Jam<input v-model="draft.recorded_at" type="time" /></label>
          <label>Sistol<input v-model="draft.td_sistol" type="number" placeholder="120" /></label>
          <label>Diastol<input v-model="draft.td_diastol" type="number" placeholder="80" /></label>
          <label>Nadi<input v-model="draft.nadi" type="number" placeholder="80" /></label>
          <label>SpO₂<input v-model="draft.spo2" type="number" placeholder="99" /></label>
          <label>RR<input v-model="draft.rr" type="number" placeholder="16" /></label>
          <label>EtCO₂<input v-model="draft.etco2" type="number" placeholder="35" /></label>
          <label>Suhu<input v-model="draft.suhu" type="number" step="0.1" placeholder="36.5" /></label>
        </div>
        <label class="amp-form-note">Obat / Kejadian
          <input v-model="draft.obat_kejadian" type="text" placeholder="mis. Propofol 30mg IV / desaturasi" />
        </label>
        <div class="amp-form-actions">
          <button v-if="editingId" class="amp-btn-ghost" @click="cancelEdit" :disabled="saving">Batal</button>
          <button class="amp-btn-primary" @click="saveDraft" :disabled="saving">
            {{ saving ? 'Menyimpan…' : (editingId ? 'Update Baris' : '+ Catat') }}
          </button>
        </div>
      </div>
      <div v-else class="amp-locked">
        <template v-if="props.disabled">Laporan sudah difinalisasi — pencatatan vital terkunci.</template>
        <template v-else>Hanya dokter anestesi yang dapat mengisi / mengubah data vital. Anda dapat melihat saja.</template>
      </div>
    </template>
  </div>
</template>

<style scoped>
.amp-wrap { background: #fff; border: 1px solid var(--gb, #e2e6ec); border-radius: 12px; overflow: hidden; color: #000; }
.amp-wrap * { box-sizing: border-box; }

.amp-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 0.7rem 1rem; border-bottom: 1px solid var(--gb, #e2e6ec);
  background: linear-gradient(180deg, #f7f9fc 0%, #fff 100%);
}
.amp-title { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: #14253d; }
.amp-title svg { width: 18px; height: 18px; color: #1763d4; }
.amp-count { font-size: 11px; font-weight: 700; color: #1763d4; background: #eef6ff; padding: 2px 9px; border-radius: 999px; }

.amp-empty, .amp-chart-empty, .amp-locked {
  padding: 1.25rem; text-align: center; color: #6b7585; font-size: 13px;
}
.amp-locked { border-top: 1px dashed var(--gb, #e2e6ec); }

.amp-chart-card { padding: 0.75rem 1rem 0.5rem; }
.amp-chart { width: 100%; height: auto; display: block; }
.amp-legend { display: flex; gap: 14px; flex-wrap: wrap; padding: 4px 2px 0; }
.amp-leg-item { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; color: #46505f; }
.amp-leg-dot { width: 10px; height: 3px; border-radius: 2px; }

.amp-err { margin: 0 1rem 0.5rem; padding: 0.5rem 0.75rem; background: #fff0f0; border: 1px solid #f3b6b6; border-radius: 6px; color: #b42323; font-size: 12px; }

.amp-table-wrap { padding: 0 1rem; overflow-x: auto; }
.amp-table { width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 560px; }
.amp-table th, .amp-table td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #eef1f4; white-space: nowrap; }
.amp-table th { background: #f5f7fa; font-weight: 700; color: #46505f; font-size: 11.5px; }
.amp-td-state { text-align: center; color: #8a93a3; font-style: italic; }
.amp-td-note { white-space: normal; max-width: 200px; color: #46505f; }
.amp-td-act { text-align: right; }
.amp-row-editing { background: #fff8e8; }

.amp-btn-icon { border: 1px solid #d6dbe2; background: #fff; border-radius: 5px; width: 24px; height: 24px; cursor: pointer; font-size: 13px; margin-left: 3px; }
.amp-btn-icon:hover { background: #f0f3f7; }
.amp-btn-del { color: #c83b3b; border-color: #e6b3b3; }
.amp-btn-del:hover { background: #ffe9e9; }

.amp-form { padding: 0.75rem 1rem 1rem; border-top: 1px solid var(--gb, #e2e6ec); background: #fafbfd; }
.amp-form-grid { display: grid; grid-template-columns: repeat(8, 1fr); gap: 8px; }
.amp-form-grid label, .amp-form-note { display: flex; flex-direction: column; gap: 3px; font-size: 11px; font-weight: 600; color: #46505f; }
.amp-form-grid input, .amp-form-note input {
  font: inherit; font-weight: normal; padding: 5px 7px; border: 1px solid #cfd6df; border-radius: 5px; background: #fff; width: 100%;
}
.amp-form-note { margin-top: 8px; }
.amp-form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; }

.amp-btn-primary {
  padding: 7px 16px; border: 1px solid #1763d4; border-radius: 6px;
  background: #1763d4; color: #fff !important; font-weight: 700; font-size: 13px; cursor: pointer;
}
.amp-btn-primary:hover { background: #134fa8; }
.amp-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.amp-btn-ghost { padding: 7px 14px; border: 1px solid #cfd6df; border-radius: 6px; background: #fff; font-size: 13px; cursor: pointer; }

@media (max-width: 720px) {
  .amp-form-grid { grid-template-columns: repeat(4, 1fr); }
}
</style>
