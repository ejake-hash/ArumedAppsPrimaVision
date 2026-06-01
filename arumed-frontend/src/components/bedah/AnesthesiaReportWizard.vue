<script setup>
/**
 * AnesthesiaReportWizard — Laporan Anestesi lengkap (RM 5.2), wizard 3 halaman.
 *
 *   Hal 1: Pra-anestesi (identitas tim, teknik, alat khusus, monitoring, ASA,
 *          checklist persiapan, penilaian pra-induksi)
 *   Hal 2: Teknis tindakan (infus/posisi/premedikasi/induksi/jalan nafas/
 *          intubasi/ventilasi/regional blok)
 *   Hal 3: Monitoring durante (reuse AnesthesiaMonitorPanel — grafik vital)
 *
 * Field hal 1-2 disimpan ke surgery_anesthesia_reports.form_data (1 baris/operasi)
 * via bedahApi.saveAnesthesiaReport. Hal 3 punya storage sendiri (vitals).
 *
 * RBAC: anestesi.read → tampil; anestesi.write → boleh isi/edit (selain itu
 * read-only). Pola sama AnesthesiaMonitorPanel.
 *
 * Catatan: pakai v-if antar halaman (BUKAN v-show) — hindari freeze child
 * (memory feedback vshow unmount).
 */
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { bedahApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'
import { useMasterDataStore } from '@/stores/masterDataStore'
import AnesthesiaMonitorPanel from './AnesthesiaMonitorPanel.vue'

const props = defineProps({
  recordId: { type: String, default: null },
  visitId:  { type: String, default: null },
  disabled: { type: Boolean, default: false },   // laporan finalized
})

const auth = useAuthStore()
const masterStore = useMasterDataStore()
const canRead  = computed(() => auth.can('anestesi.read'))
const canWrite = computed(() => auth.can('anestesi.write'))
const readonly = computed(() => props.disabled || !canWrite.value)

// Daftar dokter anestesi (role dokter_anestesi) untuk dropdown DPJP Anestesi.
const anesthesiologists = ref([])
// Apakah user yang login adalah dokter anestesi (untuk auto-isi default).
const loggedInIsAnes = computed(() => auth.roleName === 'dokter_anestesi')

// Kop klinik untuk cetak.
const clinicLogoUrl = computed(() => {
  const p = masterStore.profilKlinik?.logo_path
  if (!p) return null
  if (p.startsWith('http')) return p
  const apiBase = import.meta.env.VITE_API_URL ?? '/api/v1'
  return `${apiBase.replace(/\/api\/v\d+\/?$/, '')}/storage/${p}`
})
const printVitals = ref([])

// ── State ───────────────────────────────────────────────────────────────────
const page = ref(1)
const PAGES = [
  { n: 1, label: 'Pra-Anestesi' },
  { n: 2, label: 'Teknis Tindakan' },
  { n: 3, label: 'Monitoring Durante' },
]

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const savedAt = ref(null)

// form = seluruh field hal 1-2 (objek besar). Default value supaya v-model aman.
function blankForm() {
  return {
    // — Identitas tim / diagnosis —
    dpjp_anestesi: '', asisten_anestesi: '', dpjp_bedah: '',
    diagnosis_pra: '', jenis_pembedahan: '', diagnosis_pasca: '',
    // — Teknik anestesi —
    teknik_anestesi: [],            // multi: Sedasi/Umum/Spinal/Epidural/Kaudal/Blok Perifer
    teknik_umum_detail: '', teknik_lain: '', blok_perifer_detail: '',
    // — Teknik & alat khusus —
    alat_khusus: [],               // Hipotensi/TCI/CPB/Ventilasi 1 paru/Bronkoskopi/Glidescope/USG/Stimulator saraf
    alat_khusus_lain: '',
    // — Monitoring alat —
    monitoring: [],                // EKG/Arteri line/EtCO2/Stetoskop/NIBP/NGT/BIS/CVP/SpO2/Kateter urine/Temp
    monitoring_ekg_lead: '', monitoring_arteri: '', monitoring_cvp: '', monitoring_lain: '',
    // — Status fisik —
    asa_class: '', asa_emergency: false,
    alergi: '', alergi_detail: '',
    penyulit_pra: '',
    // — Checklist persiapan —
    checklist: [],                 // Informed consent/Obat anestesi/Jalan nafas/Mesin anestesi/Monitoring/Obat emergensi/Suction
    // — Penilaian pra-induksi —
    praind_jam: '', praind_kesadaran: '', praind_td: '', praind_nadi: '',
    praind_rr: '', praind_suhu: '', praind_spo2: '', praind_lain: '',

    // ── Hal 2: Teknis Tindakan ──
    // Infus / CVC
    infus_1: '', infus_2: '', cvc: '',
    // Posisi + perlindungan mata
    posisi: '', posisi_lain: '',
    perlindungan_mata: [],         // Ka / Ki
    // Premedikasi
    premed_oral: '', premed_im: '', premed_iv: '',
    // Induksi
    induksi_iv: '', induksi_inhalasi: '',
    // Tata laksana jalan nafas
    jalan_nafas: [],               // Face mask/ETT/LMA/Trakeostomi/Bronkoskopi fiberoptik/Glidescope
    facemask_no: '', oronaso_no: '',
    ett_no: '', ett_jenis: '', ett_fiksasi_cm: '',
    lma_no: '', lma_jenis: '',
    jalan_nafas_lain: '',
    // Intubasi
    intubasi_kondisi: [],          // Sesudah tidur/Blind/Oral/Nasal/Trakheostomi
    intubasi_sisi: '',             // Ka/Ki
    sulit_ventilasi: '', sulit_intubasi: '',
    intubasi_opsi: [],             // Dengan stilet/Cuff/Pack
    ett_level: '',
    // Ventilasi
    ventilasi_mode: '',            // Spontan/Kendali/Ventilator
    vent_tv: '', vent_rr: '', vent_peep: '', ventilasi_lain: '',
    // Teknik regional / blok perifer
    regional_jenis: '', regional_lokasi: '', regional_jarum: '',
    regional_kateter: '', regional_fiksasi_cm: '',
    regional_obat: '', regional_komplikasi: '', regional_hasil: '',
  }
}
const form = ref(blankForm())

// Opsi-opsi (sesuai form asli RM 5.2)
const OPT_TEKNIK = ['Sedasi', 'Anestesi Umum', 'Spinal', 'Epidural', 'Kaudal', 'Blok Perifer', 'Lain-lain']
const OPT_ALAT_KHUSUS = ['Hipotensi', 'TCI', 'CPB', 'Ventilasi satu paru', 'Bronkoskopi', 'Glidescope', 'USG', 'Stimulator Saraf']
const OPT_MONITORING = ['EKG', 'Arteri line', 'EtCO2', 'Stetoskop', 'NIBP', 'NGT', 'BIS', 'CVP', 'Cath A Pulmo', 'SpO2', 'Kateter urine', 'Temp']
const OPT_CHECKLIST = ['Informed consent', 'Obat-obatan Anestesi', 'Tatalaksana jalan nafas', 'Mesin Anestesi', 'Monitoring', 'Obat-obatan Emergensi', 'Suction Apparatus']
const OPT_ASA = ['1', '2', '3', '4', '5']
// Hal 2
const OPT_POSISI = ['Terlentang', 'Lithotomi', 'Prone', 'Lateral']
const OPT_MATA = ['Ka', 'Ki']
const OPT_JALAN_NAFAS = ['Face mask', 'ETT', 'LMA', 'Trakeostomi', 'Bronkoskopi fiberoptik', 'Glidescope']
const OPT_INTUBASI_KONDISI = ['Sesudah tidur', 'Blind', 'Oral', 'Nasal', 'Trakheostomi']
const OPT_INTUBASI_OPSI = ['Dengan stilet', 'Cuff', 'Pack']
const OPT_VENTILASI = ['Spontan', 'Kendali', 'Ventilator']
const OPT_HASIL_BLOK = ['Total Blok', 'Partial', 'Gagal']

// ── Load / Save ─────────────────────────────────────────────────────────────
async function loadAnesthesiologists() {
  try {
    const { data } = await bedahApi.anesthesiologists()
    anesthesiologists.value = data.data ?? []
  } catch (_) { anesthesiologists.value = [] }
}

async function load() {
  if (!canRead.value || !props.recordId) return
  loading.value = true
  error.value = ''
  try {
    await loadAnesthesiologists()
    const { data } = await bedahApi.getAnesthesiaReport(props.recordId)
    const fd = data.data?.form_data
    if (fd && typeof fd === 'object') {
      form.value = { ...blankForm(), ...fd }
    }
    // Default DPJP Anestesi = user login (kalau dia dokter_anestesi) & belum terisi.
    if (!form.value.dpjp_anestesi && loggedInIsAnes.value && auth.employeeName) {
      form.value.dpjp_anestesi = auth.employeeName
    }
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Gagal memuat laporan anestesi.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.recordId, load)

async function save() {
  if (readonly.value || !props.recordId) return
  saving.value = true
  error.value = ''
  try {
    await bedahApi.saveAnesthesiaReport(props.recordId, { form_data: { ...form.value } })
    savedAt.value = new Date()
  } catch (e) {
    const errs = e.response?.data?.errors
    error.value = errs ? Object.values(errs).flat().join(', ') : (e.response?.data?.message ?? 'Gagal menyimpan.')
  } finally {
    saving.value = false
  }
}

// Pindah halaman — auto-save dulu kalau boleh tulis (state tetap utuh apa pun).
async function goTo(n) {
  if (n < 1 || n > 3) return
  if (!readonly.value && (page.value === 1 || page.value === 2)) {
    await save()
  }
  page.value = n
}
function next() { goTo(page.value + 1) }
function prev() { goTo(page.value - 1) }

// Helper toggle item di array (checkbox group).
function toggle(arrKey, val) {
  if (readonly.value) return
  const arr = form.value[arrKey]
  const i = arr.indexOf(val)
  if (i === -1) arr.push(val); else arr.splice(i, 1)
}
function has(arrKey, val) {
  return form.value[arrKey].includes(val)
}

// ── Cetak A4 3 halaman ──────────────────────────────────────────────────────
function arr(v) { return Array.isArray(v) && v.length ? v.join(', ') : '—' }
function val(v) { return (v === null || v === undefined || v === '') ? '—' : v }
function jam(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

async function cetak() {
  if (!masterStore.profilKlinik) {
    try { await masterStore.fetchProfilKlinik?.() } catch (_) { /* non-fatal */ }
  }
  printVitals.value = []
  if (props.recordId) {
    try {
      const { data } = await bedahApi.listAnesthesiaVitals(props.recordId)
      printVitals.value = (data.data ?? []).slice().sort((a, b) => new Date(a.recorded_at) - new Date(b.recorded_at))
    } catch (_) { /* non-fatal */ }
  }
  await nextTick()
  setTimeout(() => window.print(), 80)
}
</script>

<template>
  <div v-if="canRead" class="arw-wrap">
    <!-- Header + stepper -->
    <div class="arw-head">
      <div class="arw-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Laporan Anestesi (RM 5.2)
      </div>
      <div class="arw-head-right">
        <span v-if="savedAt" class="arw-saved">Tersimpan</span>
        <span v-else-if="readonly" class="arw-ro">Mode lihat</span>
        <button class="arw-btn-print" @click="cetak" title="Cetak Laporan Anestesi A4">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
          Cetak
        </button>
      </div>
    </div>

    <ol class="arw-steps">
      <li v-for="p in PAGES" :key="p.n"
        :class="{ active: page === p.n, done: page > p.n }"
        @click="goTo(p.n)">
        <span class="arw-step-num">{{ p.n }}</span>
        <span class="arw-step-label">{{ p.label }}</span>
      </li>
    </ol>

    <div v-if="error" class="arw-err">{{ error }}</div>
    <div v-if="loading" class="arw-loading">Memuat…</div>

    <!-- ══ HALAMAN 1 — Pra-Anestesi ══ -->
    <section v-else-if="page === 1" class="arw-page">
      <div class="arw-grid2">
        <label class="arw-f">DPJP Anestesi
          <select v-model="form.dpjp_anestesi" :disabled="readonly">
            <option value="">— Pilih dokter anestesi —</option>
            <option v-for="a in anesthesiologists" :key="a.id" :value="a.name">{{ a.name }}</option>
            <!-- Nilai lama yang bukan dari daftar (mis. teks bebas) tetap tampil -->
            <option v-if="form.dpjp_anestesi && !anesthesiologists.some(a => a.name === form.dpjp_anestesi)" :value="form.dpjp_anestesi">{{ form.dpjp_anestesi }}</option>
          </select>
        </label>
        <label class="arw-f">Asisten Anestesi<input v-model="form.asisten_anestesi" :disabled="readonly" /></label>
        <label class="arw-f">DPJP Bedah<input v-model="form.dpjp_bedah" :disabled="readonly" /></label>
      </div>
      <div class="arw-grid3">
        <label class="arw-f">Diagnosis Pra-Bedah<input v-model="form.diagnosis_pra" :disabled="readonly" /></label>
        <label class="arw-f">Jenis Pembedahan<input v-model="form.jenis_pembedahan" :disabled="readonly" /></label>
        <label class="arw-f">Diagnosis Pasca-Bedah<input v-model="form.diagnosis_pasca" :disabled="readonly" /></label>
      </div>

      <fieldset class="arw-fs">
        <legend>Teknik Anestesi</legend>
        <div class="arw-chips">
          <label v-for="o in OPT_TEKNIK" :key="o" class="arw-chk">
            <input type="checkbox" :checked="has('teknik_anestesi', o)" :disabled="readonly" @change="toggle('teknik_anestesi', o)" />{{ o }}
          </label>
        </div>
        <div class="arw-grid3" style="margin-top:8px">
          <label class="arw-f">Anestesi Umum (detail)<input v-model="form.teknik_umum_detail" :disabled="readonly" /></label>
          <label class="arw-f">Blok Perifer (detail)<input v-model="form.blok_perifer_detail" :disabled="readonly" /></label>
          <label class="arw-f">Lain-lain<input v-model="form.teknik_lain" :disabled="readonly" /></label>
        </div>
      </fieldset>

      <fieldset class="arw-fs">
        <legend>Teknik &amp; Alat Khusus</legend>
        <div class="arw-chips">
          <label v-for="o in OPT_ALAT_KHUSUS" :key="o" class="arw-chk">
            <input type="checkbox" :checked="has('alat_khusus', o)" :disabled="readonly" @change="toggle('alat_khusus', o)" />{{ o }}
          </label>
        </div>
        <label class="arw-f" style="margin-top:8px">Lain-lain<input v-model="form.alat_khusus_lain" :disabled="readonly" /></label>
      </fieldset>

      <fieldset class="arw-fs">
        <legend>Monitoring</legend>
        <div class="arw-chips">
          <label v-for="o in OPT_MONITORING" :key="o" class="arw-chk">
            <input type="checkbox" :checked="has('monitoring', o)" :disabled="readonly" @change="toggle('monitoring', o)" />{{ o }}
          </label>
        </div>
        <div class="arw-grid3" style="margin-top:8px">
          <label class="arw-f">EKG Lead<input v-model="form.monitoring_ekg_lead" :disabled="readonly" /></label>
          <label class="arw-f">Arteri line<input v-model="form.monitoring_arteri" :disabled="readonly" /></label>
          <label class="arw-f">CVP<input v-model="form.monitoring_cvp" :disabled="readonly" /></label>
          <label class="arw-f">Lain-lain<input v-model="form.monitoring_lain" :disabled="readonly" /></label>
        </div>
      </fieldset>

      <div class="arw-grid2">
        <fieldset class="arw-fs">
          <legend>Status Fisik (ASA)</legend>
          <div class="arw-chips">
            <label v-for="o in OPT_ASA" :key="o" class="arw-radio">
              <input type="radio" name="asa" :value="o" v-model="form.asa_class" :disabled="readonly" />ASA {{ o }}
            </label>
            <label class="arw-chk"><input type="checkbox" v-model="form.asa_emergency" :disabled="readonly" />E (Emergency)</label>
          </div>
        </fieldset>
        <fieldset class="arw-fs">
          <legend>Alergi</legend>
          <div class="arw-chips">
            <label class="arw-radio"><input type="radio" value="Tidak" v-model="form.alergi" :disabled="readonly" />Tidak</label>
            <label class="arw-radio"><input type="radio" value="Ya" v-model="form.alergi" :disabled="readonly" />Ya</label>
          </div>
          <label class="arw-f" style="margin-top:6px">Detail (bila ada)<input v-model="form.alergi_detail" :disabled="readonly" /></label>
        </fieldset>
      </div>

      <label class="arw-f">Penyulit Pra-Anestesi<textarea v-model="form.penyulit_pra" rows="2" :disabled="readonly"></textarea></label>

      <fieldset class="arw-fs">
        <legend>Checklist Persiapan Anestesi</legend>
        <div class="arw-chips">
          <label v-for="o in OPT_CHECKLIST" :key="o" class="arw-chk">
            <input type="checkbox" :checked="has('checklist', o)" :disabled="readonly" @change="toggle('checklist', o)" />{{ o }}
          </label>
        </div>
      </fieldset>

      <fieldset class="arw-fs">
        <legend>Penilaian Pra-Induksi</legend>
        <div class="arw-grid4">
          <label class="arw-f">Jam<input v-model="form.praind_jam" type="time" :disabled="readonly" /></label>
          <label class="arw-f">Kesadaran<input v-model="form.praind_kesadaran" :disabled="readonly" /></label>
          <label class="arw-f">Tekanan Darah<input v-model="form.praind_td" placeholder="120/80" :disabled="readonly" /></label>
          <label class="arw-f">Nadi<input v-model="form.praind_nadi" type="number" :disabled="readonly" /></label>
          <label class="arw-f">RR<input v-model="form.praind_rr" type="number" :disabled="readonly" /></label>
          <label class="arw-f">Suhu<input v-model="form.praind_suhu" type="number" step="0.1" :disabled="readonly" /></label>
          <label class="arw-f">Saturasi O2<input v-model="form.praind_spo2" type="number" :disabled="readonly" /></label>
          <label class="arw-f">Lain-lain<input v-model="form.praind_lain" :disabled="readonly" /></label>
        </div>
      </fieldset>
    </section>

    <!-- ══ HALAMAN 2 — Teknis Tindakan ══ -->
    <section v-else-if="page === 2" class="arw-page">
      <fieldset class="arw-fs">
        <legend>Akses Vaskular &amp; Posisi</legend>
        <div class="arw-grid3">
          <label class="arw-f">Infus perifer 1 (tempat &amp; ukuran)<input v-model="form.infus_1" :disabled="readonly" /></label>
          <label class="arw-f">Infus perifer 2<input v-model="form.infus_2" :disabled="readonly" /></label>
          <label class="arw-f">CVC<input v-model="form.cvc" :disabled="readonly" /></label>
        </div>
        <div class="arw-grid2" style="margin-top:8px">
          <div>
            <span class="arw-sublbl">Posisi</span>
            <div class="arw-chips">
              <label v-for="o in OPT_POSISI" :key="o" class="arw-radio"><input type="radio" name="posisi" :value="o" v-model="form.posisi" :disabled="readonly" />{{ o }}</label>
            </div>
            <label class="arw-f" style="margin-top:6px">Lain-lain<input v-model="form.posisi_lain" :disabled="readonly" /></label>
          </div>
          <div>
            <span class="arw-sublbl">Perlindungan Mata</span>
            <div class="arw-chips">
              <label v-for="o in OPT_MATA" :key="o" class="arw-chk"><input type="checkbox" :checked="has('perlindungan_mata', o)" :disabled="readonly" @change="toggle('perlindungan_mata', o)" />{{ o }}</label>
            </div>
          </div>
        </div>
      </fieldset>

      <fieldset class="arw-fs">
        <legend>Premedikasi</legend>
        <div class="arw-grid3">
          <label class="arw-f">Oral<input v-model="form.premed_oral" :disabled="readonly" /></label>
          <label class="arw-f">I.M<input v-model="form.premed_im" :disabled="readonly" /></label>
          <label class="arw-f">I.V<input v-model="form.premed_iv" :disabled="readonly" /></label>
        </div>
      </fieldset>

      <fieldset class="arw-fs">
        <legend>Induksi</legend>
        <div class="arw-grid2">
          <label class="arw-f">Intravena<input v-model="form.induksi_iv" :disabled="readonly" /></label>
          <label class="arw-f">Inhalasi<input v-model="form.induksi_inhalasi" :disabled="readonly" /></label>
        </div>
      </fieldset>

      <fieldset class="arw-fs">
        <legend>Tata Laksana Jalan Nafas</legend>
        <div class="arw-chips">
          <label v-for="o in OPT_JALAN_NAFAS" :key="o" class="arw-chk"><input type="checkbox" :checked="has('jalan_nafas', o)" :disabled="readonly" @change="toggle('jalan_nafas', o)" />{{ o }}</label>
        </div>
        <div class="arw-grid4" style="margin-top:8px">
          <label class="arw-f">Face mask No<input v-model="form.facemask_no" :disabled="readonly" /></label>
          <label class="arw-f">Oro/Nasopharing No<input v-model="form.oronaso_no" :disabled="readonly" /></label>
          <label class="arw-f">ETT No<input v-model="form.ett_no" :disabled="readonly" /></label>
          <label class="arw-f">ETT Jenis<input v-model="form.ett_jenis" :disabled="readonly" /></label>
          <label class="arw-f">ETT Fiksasi (cm)<input v-model="form.ett_fiksasi_cm" type="number" :disabled="readonly" /></label>
          <label class="arw-f">LMA No<input v-model="form.lma_no" :disabled="readonly" /></label>
          <label class="arw-f">LMA Jenis<input v-model="form.lma_jenis" :disabled="readonly" /></label>
          <label class="arw-f">Lain-lain<input v-model="form.jalan_nafas_lain" :disabled="readonly" /></label>
        </div>
      </fieldset>

      <fieldset class="arw-fs">
        <legend>Intubasi</legend>
        <div class="arw-chips">
          <label v-for="o in OPT_INTUBASI_KONDISI" :key="o" class="arw-chk"><input type="checkbox" :checked="has('intubasi_kondisi', o)" :disabled="readonly" @change="toggle('intubasi_kondisi', o)" />{{ o }}</label>
        </div>
        <div class="arw-chips" style="margin-top:6px">
          <span class="arw-sublbl" style="margin-right:6px">Sisi:</span>
          <label class="arw-radio"><input type="radio" name="intsisi" value="Ka" v-model="form.intubasi_sisi" :disabled="readonly" />Ka</label>
          <label class="arw-radio"><input type="radio" name="intsisi" value="Ki" v-model="form.intubasi_sisi" :disabled="readonly" />Ki</label>
        </div>
        <div class="arw-chips" style="margin-top:6px">
          <label v-for="o in OPT_INTUBASI_OPSI" :key="o" class="arw-chk"><input type="checkbox" :checked="has('intubasi_opsi', o)" :disabled="readonly" @change="toggle('intubasi_opsi', o)" />{{ o }}</label>
        </div>
        <div class="arw-grid3" style="margin-top:8px">
          <label class="arw-f">Sulit ventilasi<input v-model="form.sulit_ventilasi" :disabled="readonly" /></label>
          <label class="arw-f">Sulit intubasi<input v-model="form.sulit_intubasi" :disabled="readonly" /></label>
          <label class="arw-f">Level ETT<input v-model="form.ett_level" :disabled="readonly" /></label>
        </div>
      </fieldset>

      <fieldset class="arw-fs">
        <legend>Ventilasi</legend>
        <div class="arw-chips">
          <label v-for="o in OPT_VENTILASI" :key="o" class="arw-radio"><input type="radio" name="ventmode" :value="o" v-model="form.ventilasi_mode" :disabled="readonly" />{{ o }}</label>
        </div>
        <div class="arw-grid4" style="margin-top:8px">
          <label class="arw-f">TV<input v-model="form.vent_tv" :disabled="readonly" /></label>
          <label class="arw-f">RR<input v-model="form.vent_rr" :disabled="readonly" /></label>
          <label class="arw-f">PEEP<input v-model="form.vent_peep" :disabled="readonly" /></label>
          <label class="arw-f">Lain-lain<input v-model="form.ventilasi_lain" :disabled="readonly" /></label>
        </div>
      </fieldset>

      <fieldset class="arw-fs">
        <legend>Teknik Regional / Blok Perifer</legend>
        <div class="arw-grid3">
          <label class="arw-f">Jenis<input v-model="form.regional_jenis" :disabled="readonly" /></label>
          <label class="arw-f">Lokasi<input v-model="form.regional_lokasi" :disabled="readonly" /></label>
          <label class="arw-f">Jenis Jarum / No<input v-model="form.regional_jarum" :disabled="readonly" /></label>
          <label class="arw-f">Kateter<input v-model="form.regional_kateter" :disabled="readonly" /></label>
          <label class="arw-f">Fiksasi (cm)<input v-model="form.regional_fiksasi_cm" type="number" :disabled="readonly" /></label>
          <label class="arw-f">Obat-obat<input v-model="form.regional_obat" :disabled="readonly" /></label>
        </div>
        <div class="arw-grid2" style="margin-top:8px">
          <label class="arw-f">Komplikasi<input v-model="form.regional_komplikasi" :disabled="readonly" /></label>
          <div>
            <span class="arw-sublbl">Hasil</span>
            <div class="arw-chips">
              <label v-for="o in OPT_HASIL_BLOK" :key="o" class="arw-radio"><input type="radio" name="blokhasil" :value="o" v-model="form.regional_hasil" :disabled="readonly" />{{ o }}</label>
            </div>
          </div>
        </div>
      </fieldset>
    </section>

    <!-- ══ HALAMAN 3 — Monitoring Durante (reuse panel) ══ -->
    <section v-else-if="page === 3" class="arw-page arw-page-nopad">
      <AnesthesiaMonitorPanel :record-id="recordId" :disabled="disabled" />
    </section>

    <!-- Navigasi -->
    <div v-if="!loading" class="arw-nav">
      <button class="arw-btn-ghost" :disabled="page === 1" @click="prev">‹ Sebelumnya</button>
      <span class="arw-nav-mid">Halaman {{ page }} / 3</span>
      <button v-if="page < 3" class="arw-btn-primary" @click="next">Berikutnya ›</button>
      <button v-else-if="!readonly" class="arw-btn-primary" :disabled="saving" @click="save">{{ saving ? 'Menyimpan…' : 'Simpan' }}</button>
      <span v-else class="arw-nav-spacer"></span>
    </div>

    <!-- ═══ CETAK A4 — LAPORAN ANESTESI 3 HALAMAN (Teleport, @media print global) ═══ -->
    <Teleport to="body">
      <div class="anes-print">
        <!-- Kop -->
        <div class="ap-kop">
          <img v-if="clinicLogoUrl" :src="clinicLogoUrl" alt="Logo" class="ap-logo" />
          <div>
            <div class="ap-clinic">{{ masterStore.profilKlinik?.clinic_name ?? 'Klinik' }}</div>
            <div v-if="masterStore.profilKlinik?.address" class="ap-line">{{ masterStore.profilKlinik.address }}</div>
          </div>
          <div class="ap-formno">RM 5.2</div>
        </div>
        <div class="ap-title">LAPORAN ANESTESI</div>

        <!-- HALAMAN 1 -->
        <div class="ap-page">
          <div class="ap-sec">A. Pra-Anestesi</div>
          <table class="ap-meta">
            <tr><td class="k">DPJP Anestesi</td><td>: {{ val(form.dpjp_anestesi) }}</td><td class="k">DPJP Bedah</td><td>: {{ val(form.dpjp_bedah) }}</td></tr>
            <tr><td class="k">Diagnosis Pra</td><td>: {{ val(form.diagnosis_pra) }}</td><td class="k">Jenis Pembedahan</td><td>: {{ val(form.jenis_pembedahan) }}</td></tr>
            <tr><td class="k">Diagnosis Pasca</td><td colspan="3">: {{ val(form.diagnosis_pasca) }}</td></tr>
            <tr><td class="k">Teknik Anestesi</td><td colspan="3">: {{ arr(form.teknik_anestesi) }}<span v-if="form.blok_perifer_detail"> · Blok: {{ form.blok_perifer_detail }}</span></td></tr>
            <tr><td class="k">Alat Khusus</td><td colspan="3">: {{ arr(form.alat_khusus) }}</td></tr>
            <tr><td class="k">Monitoring</td><td colspan="3">: {{ arr(form.monitoring) }}</td></tr>
            <tr><td class="k">Status Fisik</td><td>: ASA {{ val(form.asa_class) }}<span v-if="form.asa_emergency"> E</span></td><td class="k">Alergi</td><td>: {{ val(form.alergi) }} {{ form.alergi_detail }}</td></tr>
            <tr><td class="k">Checklist</td><td colspan="3">: {{ arr(form.checklist) }}</td></tr>
            <tr><td class="k">Penyulit Pra</td><td colspan="3">: {{ val(form.penyulit_pra) }}</td></tr>
            <tr><td class="k">Pra-Induksi</td><td colspan="3">: Jam {{ val(form.praind_jam) }} · Kes {{ val(form.praind_kesadaran) }} · TD {{ val(form.praind_td) }} · N {{ val(form.praind_nadi) }} · RR {{ val(form.praind_rr) }} · S {{ val(form.praind_suhu) }} · SpO2 {{ val(form.praind_spo2) }}</td></tr>
          </table>
        </div>

        <!-- HALAMAN 2 -->
        <div class="ap-page ap-pagebreak">
          <div class="ap-sec">B. Teknis Tindakan</div>
          <table class="ap-meta">
            <tr><td class="k">Infus / CVC</td><td colspan="3">: {{ val(form.infus_1) }}<span v-if="form.infus_2">; {{ form.infus_2 }}</span><span v-if="form.cvc"> · CVC: {{ form.cvc }}</span></td></tr>
            <tr><td class="k">Posisi</td><td>: {{ val(form.posisi) }}</td><td class="k">Perlindungan Mata</td><td>: {{ arr(form.perlindungan_mata) }}</td></tr>
            <tr><td class="k">Premedikasi</td><td colspan="3">: Oral {{ val(form.premed_oral) }} · IM {{ val(form.premed_im) }} · IV {{ val(form.premed_iv) }}</td></tr>
            <tr><td class="k">Induksi</td><td colspan="3">: IV {{ val(form.induksi_iv) }} · Inhalasi {{ val(form.induksi_inhalasi) }}</td></tr>
            <tr><td class="k">Jalan Nafas</td><td colspan="3">: {{ arr(form.jalan_nafas) }}<span v-if="form.ett_no"> · ETT No {{ form.ett_no }} {{ form.ett_jenis }} fiksasi {{ form.ett_fiksasi_cm }}cm</span><span v-if="form.lma_no"> · LMA No {{ form.lma_no }}</span></td></tr>
            <tr><td class="k">Intubasi</td><td colspan="3">: {{ arr(form.intubasi_kondisi) }}<span v-if="form.intubasi_sisi"> ({{ form.intubasi_sisi }})</span> · {{ arr(form.intubasi_opsi) }}<span v-if="form.ett_level"> · Level {{ form.ett_level }}</span></td></tr>
            <tr><td class="k">Ventilasi</td><td colspan="3">: {{ val(form.ventilasi_mode) }}<span v-if="form.vent_tv"> · TV {{ form.vent_tv }} RR {{ form.vent_rr }} PEEP {{ form.vent_peep }}</span></td></tr>
            <tr><td class="k">Regional/Blok</td><td colspan="3">: {{ val(form.regional_jenis) }}<span v-if="form.regional_lokasi"> @ {{ form.regional_lokasi }}</span><span v-if="form.regional_obat"> · {{ form.regional_obat }}</span><span v-if="form.regional_hasil"> · Hasil: {{ form.regional_hasil }}</span></td></tr>
          </table>
        </div>

        <!-- HALAMAN 3 -->
        <div class="ap-page ap-pagebreak">
          <div class="ap-sec">C. Monitoring Durante</div>
          <table v-if="printVitals.length" class="ap-vital">
            <thead><tr><th>Jam</th><th>TD</th><th>Nadi</th><th>SpO₂</th><th>RR</th><th>EtCO₂</th><th>Suhu</th><th>Obat/Kejadian</th></tr></thead>
            <tbody>
              <tr v-for="r in printVitals" :key="r.id">
                <td>{{ jam(r.recorded_at) }}</td><td>{{ r.td_sistol ?? '–' }}/{{ r.td_diastol ?? '–' }}</td>
                <td>{{ r.nadi ?? '–' }}</td><td>{{ r.spo2 ?? '–' }}</td><td>{{ r.rr ?? '–' }}</td>
                <td>{{ r.etco2 ?? '–' }}</td><td>{{ r.suhu ?? '–' }}</td><td>{{ r.obat_kejadian ?? '' }}</td>
              </tr>
            </tbody>
          </table>
          <div v-else class="ap-muted">Tidak ada pencatatan vital durante.</div>
        </div>

        <!-- TTD -->
        <div class="ap-sign">
          <div>
            <div>Dokter Anestesi,</div>
            <div class="ap-sign-space"></div>
            <div class="ap-sign-name">( {{ val(form.dpjp_anestesi) }} )</div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.arw-wrap { background: #fff; border: 1px solid var(--gb, #e2e6ec); border-radius: 12px; overflow: hidden; color: #000; }
.arw-wrap * { box-sizing: border-box; }

.arw-head { display: flex; align-items: center; justify-content: space-between; padding: 0.7rem 1rem; border-bottom: 1px solid var(--gb, #e2e6ec); background: linear-gradient(180deg, #f7f9fc 0%, #fff 100%); }
.arw-title { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: #14253d; }
.arw-title svg { width: 18px; height: 18px; color: #1763d4; }
.arw-saved { font-size: 11px; font-weight: 700; color: #1e8a3a; background: #ecf9ee; padding: 2px 9px; border-radius: 999px; }
.arw-ro { font-size: 11px; font-weight: 700; color: #8a5a00; background: #fff7ec; padding: 2px 9px; border-radius: 999px; }
.arw-head-right { display: flex; align-items: center; gap: 8px; }
.arw-btn-print { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border: 1px solid #cfd6df; border-radius: 6px; background: #fff; color: #46505f; font-size: 12px; font-weight: 600; cursor: pointer; }
.arw-btn-print:hover { background: #f0f3f7; }
.arw-btn-print svg { width: 14px; height: 14px; }

.arw-steps { display: flex; gap: 0; list-style: none; padding: 0; margin: 0; border-bottom: 1px solid var(--gb, #e2e6ec); }
.arw-steps li { flex: 1; display: flex; align-items: center; justify-content: center; gap: 7px; padding: 0.7rem; font-size: 12.5px; color: #6b7585; cursor: pointer; border-right: 1px solid var(--gb, #e2e6ec); transition: all 120ms; }
.arw-steps li:last-child { border-right: 0; }
.arw-steps li.active { background: #1763d4; color: #fff; font-weight: 700; }
.arw-steps li.done { background: #ecf9ee; color: #1e6a35; }
.arw-step-num { display: inline-flex; width: 20px; height: 20px; border-radius: 50%; background: #fff; color: #1763d4; align-items: center; justify-content: center; font-weight: 700; font-size: 11px; }
.arw-steps li.active .arw-step-num { background: #fff; color: #1763d4; }
.arw-steps li.done .arw-step-num { background: #1e8a3a; color: #fff; }
@media (max-width: 640px) { .arw-step-label { display: none; } }

.arw-err { margin: 0.6rem 1rem 0; padding: 0.5rem 0.75rem; background: #fff0f0; border: 1px solid #f3b6b6; border-radius: 6px; color: #b42323; font-size: 12px; }
.arw-loading { padding: 1.5rem; text-align: center; color: #6b7585; font-size: 13px; }

.arw-page { padding: 1rem; display: flex; flex-direction: column; gap: 0.9rem; }
.arw-page-nopad { padding: 0.5rem; }
.arw-placeholder { padding: 2rem; text-align: center; color: #8a93a3; font-style: italic; border: 1px dashed var(--gb, #e2e6ec); border-radius: 8px; }

.arw-grid2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.7rem; }
.arw-grid3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.7rem; }
.arw-grid4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.7rem; }
@media (max-width: 720px) { .arw-grid2, .arw-grid3, .arw-grid4 { grid-template-columns: repeat(2, 1fr); } }

.arw-f { display: flex; flex-direction: column; gap: 3px; font-size: 11.5px; font-weight: 600; color: #46505f; }
.arw-f input, .arw-f textarea, .arw-f select { font: inherit; font-weight: normal; padding: 5px 8px; border: 1px solid #cfd6df; border-radius: 5px; background: #fff; width: 100%; }
.arw-f input:disabled, .arw-f textarea:disabled, .arw-f select:disabled { background: #f4f6f8; color: #555; }

.arw-fs { border: 1px solid #e2e6ec; border-radius: 8px; padding: 0.6rem 0.8rem; margin: 0; }
.arw-fs legend { font-size: 12px; font-weight: 700; color: #14253d; padding: 0 6px; }
.arw-sublbl { display: block; font-size: 11px; font-weight: 700; color: #6b7585; margin-bottom: 4px; }
.arw-chips { display: flex; flex-wrap: wrap; gap: 6px 14px; }
.arw-chk, .arw-radio { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: #46505f; cursor: pointer; }
.arw-chk input, .arw-radio input { width: 14px; height: 14px; }

.arw-nav { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 0.75rem 1rem; border-top: 1px solid var(--gb, #e2e6ec); background: #fafbfd; }
.arw-nav-mid { font-size: 12px; color: #6b7585; }
.arw-nav-spacer { width: 110px; }
.arw-btn-primary { padding: 7px 18px; border: 1px solid #1763d4; border-radius: 6px; background: #1763d4; color: #fff !important; font-weight: 700; font-size: 13px; cursor: pointer; }
.arw-btn-primary:hover { background: #134fa8; }
.arw-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.arw-btn-ghost { padding: 7px 16px; border: 1px solid #cfd6df; border-radius: 6px; background: #fff; font-size: 13px; cursor: pointer; }
.arw-btn-ghost:disabled { opacity: 0.5; cursor: not-allowed; }
</style>

<!-- ═══ CETAK A4 — LAPORAN ANESTESI (gaya GLOBAL, tidak scoped) ═══ -->
<style>
.anes-print { display: none; }

@media print {
  @page { size: A4 portrait; margin: 14mm 15mm; }
  html, body { width: auto !important; min-width: 0 !important; height: auto !important; overflow: visible !important; background: #fff !important; }
  #app { display: none !important; }

  .anes-print { display: block !important; color: #000; font-family: 'Inter', Arial, sans-serif; font-size: 11px; line-height: 1.45; }

  .anes-print .ap-kop { display: flex; align-items: center; gap: 12px; border-bottom: 3px double #000; padding-bottom: 8px; }
  .anes-print .ap-logo { height: 54px; width: auto; object-fit: contain; }
  .anes-print .ap-clinic { font-size: 17px; font-weight: 800; }
  .anes-print .ap-line { font-size: 10px; }
  .anes-print .ap-formno { margin-left: auto; font-size: 10px; font-weight: 700; }

  .anes-print .ap-title { text-align: center; font-size: 14px; font-weight: 800; letter-spacing: .06em; text-decoration: underline; margin: 10px 0; }

  .anes-print .ap-page { page-break-inside: avoid; }
  .anes-print .ap-pagebreak { page-break-before: always; }
  .anes-print .ap-sec { font-weight: 800; font-size: 12px; margin: 10px 0 4px; border-bottom: 1px solid #999; padding-bottom: 2px; }

  .anes-print .ap-meta { width: 100%; border-collapse: collapse; }
  .anes-print .ap-meta td { padding: 2px 4px; vertical-align: top; font-size: 10.5px; }
  .anes-print .ap-meta .k { width: 18%; color: #333; }

  .anes-print .ap-vital { width: 100%; border-collapse: collapse; font-size: 9.5px; margin-top: 4px; }
  .anes-print .ap-vital th, .anes-print .ap-vital td { border: 1px solid #999; padding: 2px 4px; text-align: left; }
  .anes-print .ap-vital th { background: #eee; font-weight: 700; }
  .anes-print .ap-muted { color: #666; font-style: italic; }

  .anes-print .ap-sign { display: flex; justify-content: flex-end; margin-top: 28px; }
  .anes-print .ap-sign > div { text-align: center; }
  .anes-print .ap-sign-space { height: 56px; }
  .anes-print .ap-sign-name { font-size: 10.5px; border-top: 1px solid #000; padding-top: 2px; min-width: 170px; }
}
</style>
