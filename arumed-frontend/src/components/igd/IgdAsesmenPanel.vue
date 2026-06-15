<script setup>
/**
 * RM 3.7 — Pengkajian Gawat Darurat (asesmen medis terstruktur).
 * Tahap 2-4 (anamnesa, psikososial, pemeriksaan fisik + mata OD/OS, penunjang,
 * assessment, planning, kondisi pulang). Tahap 1 (triase ATS + vital + nyeri)
 * dikelola di modal Triase IgdView (ringkasan read-only tampil di header sini).
 * Finalisasi → terbitkan dokumen ber-TTD (PIN) lewat pipeline Form Registry.
 */
import { ref, reactive, computed, onMounted } from 'vue'
import { igdApi, masterApi, formTemplateApi } from '@/services/api'

const props = defineProps({
  visitId: { type: String, required: true },
  patientName: { type: String, default: '' },
  patientNoRm: { type: String, default: '' },
})
const emit = defineEmits(['close', 'saved', 'edit-triase'])

const busy = ref(false)
const toast = ref(null)
const activeTab = ref('anamnesa')
const triase = ref(null)
const finalized = ref(false)
const documentId = ref(null)

const TABS = [
  { key: 'anamnesa', label: 'Anamnese' },
  { key: 'psikososial', label: 'Psikososial' },
  { key: 'fisik', label: 'Pemeriksaan Fisik' },
  { key: 'planning', label: 'Assessment & Planning' },
]

const PSIK_OPTS = ['Tenang', 'Cemas', 'Takut', 'Marah', 'Sedih']
const ALLO_OPTS = ['Keluhan', 'Riwayat kesehatan', 'Keputusan asuhan', 'Lainnya']
const REGIONS = [
  ['kepala', 'Kepala'], ['leher', 'Leher'], ['jantung', 'Jantung'], ['paru', 'Paru'],
  ['abdomen', 'Abdomen'], ['punggung', 'Punggung'], ['genitalia', 'Genitalia'], ['ekstremitas', 'Ekstremitas'],
]
const EYE_ROWS = [
  ['visus', 'Visus'], ['pergerakan', 'Pergerakan Bola Mata'], ['palpebra_sup', 'Palpebra Superior'],
  ['palpebra_inf', 'Palpebra Inferior'], ['kornea', 'Kornea'], ['iris', 'Iris'],
  ['konjungtiva', 'Konjungtiva Bulbi'], ['sekret', 'Sekret'], ['tio', 'Tekanan Bola Mata (TIO)'],
  ['pupil_reflek', 'Pupil — Refleks'], ['pupil_ukuran', 'Pupil — Ukuran'], ['isokor', 'Isokor'],
]

function emptyRegions() {
  const o = {}
  for (const [k] of REGIONS) o[k] = { normal: true, catatan: '' }
  return o
}
function emptyEye() {
  const o = {}
  for (const [k] of EYE_ROWS) o[k] = { od: '', os: '' }
  return o
}

const form = reactive({
  anamnesa: { type: '', allo_source: [], keluhan_utama: '', rpd: '', alergi: '', anamnesa_narasi: '', rpo: '' },
  psikososial: { psikologis: [], bunuh_diri: { ada: false, laporan: '' }, pernikahan: '', tempat_tinggal: '', tempat_tinggal_lainnya: '', keluarga: '', agama: '', spiritual_perlu: false, pekerjaan: '' },
  perilaku: { status: '', bahaya: '' },
  fisik: emptyRegions(),
  mata_od_os: emptyEye(),
  penunjang: { ekg: '', radiologi: '', lab: '' },
  planning: { therapi: '', anjuran: '', pengobatan: '', dpjp: '', instruksi_keluarga: '' },
  diagnosa_kerja: '', diagnosa_kerja_name: '', diagnosa_banding: '',
  keadaan_pulang: '', perawatan_lanjutan: '', waktu_keluar: '',
})

// ICD-10 picker (diagnosa kerja) — pakai with_sub (sub-diagnosa mata) seperti DokterView.
const icdSearch = ref('')
const icdResults = ref([])
let icdTimer = null
function searchIcd() {
  clearTimeout(icdTimer)
  icdTimer = setTimeout(runSearchIcd, 250)
}
async function runSearchIcd() {
  const s = (icdSearch.value || '').trim()
  if (s.length < 2) { icdResults.value = []; return }
  try {
    const { data } = await masterApi.icd10.list({ search: s, per_page: 25, with_sub: 1 })
    icdResults.value = data.data?.data ?? data.data ?? []
  } catch { icdResults.value = [] }
}
function pickIcd(icd) {
  form.diagnosa_kerja = icd.code
  form.diagnosa_kerja_name = icd.name || icd.description || ''
  icdResults.value = []; icdSearch.value = ''
}

function notify(msg, ok = true) { toast.value = { msg, ok }; setTimeout(() => (toast.value = null), 3200) }
function errMsg(e, fb) { return e?.response?.data?.message || fb }
function toggleArr(arr, val) {
  const i = arr.indexOf(val)
  if (i >= 0) arr.splice(i, 1); else arr.push(val)
}

const triaseSummary = computed(() => {
  const t = triase.value
  if (!t) return 'Belum ditriase'
  const parts = []
  if (t.triage_level) parts.push(`ATS ${t.triage_level}`)
  if (t.pain_score !== null && t.pain_score !== undefined) parts.push(`Nyeri ${t.pain_score}/10`)
  if (t.td_sistol) parts.push(`TD ${t.td_sistol}/${t.td_diastol || '-'}`)
  if (t.spo2) parts.push(`SpO₂ ${t.spo2}%`)
  return parts.length ? parts.join(' · ') : 'Triase tercatat'
})

async function load() {
  busy.value = true
  try {
    const { data } = await igdApi.getAssessment(props.visitId)
    triase.value = data.data?.triase || null
    documentId.value = data.data?.document?.id || null
    // Terkunci hanya jika dokumen benar-benar sudah FINALIZED (ditandatangani).
    finalized.value = data.data?.document?.status === 'FINALIZED'
    const a = data.data?.assessment
    if (a) {
      mergeBlock(form.anamnesa, a.anamnesa)
      mergeBlock(form.psikososial, a.psikososial)
      mergeBlock(form.perilaku, a.perilaku)
      if (a.fisik) for (const [k] of REGIONS) if (a.fisik[k]) Object.assign(form.fisik[k], a.fisik[k])
      if (a.mata_od_os) for (const [k] of EYE_ROWS) if (a.mata_od_os[k]) Object.assign(form.mata_od_os[k], a.mata_od_os[k])
      mergeBlock(form.penunjang, a.penunjang)
      mergeBlock(form.planning, a.planning)
      form.diagnosa_kerja = a.diagnosa_kerja || ''
      form.diagnosa_kerja_name = a.diagnosa_kerja_name || ''
      form.diagnosa_banding = a.diagnosa_banding || ''
      form.keadaan_pulang = a.keadaan_pulang || ''
      form.perawatan_lanjutan = a.perawatan_lanjutan || ''
      form.waktu_keluar = a.waktu_keluar ? String(a.waktu_keluar).slice(0, 16) : ''
    }
  } catch (e) {
    notify(errMsg(e, 'Gagal memuat asesmen'), false)
  } finally {
    busy.value = false
  }
}
function mergeBlock(target, src) {
  if (!src || typeof src !== 'object') return
  for (const k of Object.keys(target)) if (src[k] !== undefined && src[k] !== null) target[k] = src[k]
}

function payload() {
  return {
    anamnesa: form.anamnesa, psikososial: form.psikososial, perilaku: form.perilaku,
    fisik: form.fisik, mata_od_os: form.mata_od_os, penunjang: form.penunjang, planning: form.planning,
    diagnosa_kerja: form.diagnosa_kerja || null, diagnosa_kerja_name: form.diagnosa_kerja_name || null,
    diagnosa_banding: form.diagnosa_banding || null,
    keadaan_pulang: form.keadaan_pulang || null, perawatan_lanjutan: form.perawatan_lanjutan || null,
    waktu_keluar: form.waktu_keluar || null,
  }
}

async function saveDraft() {
  busy.value = true
  try {
    await igdApi.saveAssessment(props.visitId, payload())
    notify('Asesmen tersimpan (draft)')
    emit('saved')
  } catch (e) {
    notify(errMsg(e, 'Gagal menyimpan'), false)
  } finally {
    busy.value = false
  }
}

// ── Finalisasi + TTD ──────────────────────────────────────────────────────
const showTtd = ref(false)
const pin = ref('')
async function startFinalize() {
  if (!form.diagnosa_kerja && !form.diagnosa_kerja_name) {
    activeTab.value = 'planning'
    return notify('Diagnosa kerja wajib diisi sebelum finalisasi', false)
  }
  busy.value = true
  try {
    const { data } = await igdApi.finalizeAssessment(props.visitId, payload())
    documentId.value = data.data?.document_id
    pin.value = ''
    showTtd.value = true
  } catch (e) {
    notify(errMsg(e, 'Gagal finalisasi'), false)
  } finally {
    busy.value = false
  }
}
async function submitTtd() {
  if (!pin.value || pin.value.length < 4) return notify('Masukkan PIN tanda tangan', false)
  if (!documentId.value) return notify('Dokumen belum siap', false)
  busy.value = true
  try {
    await formTemplateApi.sign(documentId.value, { signer_type: 'doctor', signature_pin: pin.value })
    await formTemplateApi.finalize(documentId.value)
    finalized.value = true
    showTtd.value = false
    notify('Pengkajian ditandatangani & final')
    emit('saved')
  } catch (e) {
    notify(errMsg(e, 'Tanda tangan gagal (cek PIN)'), false)
  } finally {
    busy.value = false
  }
}

async function cetak() {
  if (!documentId.value) return
  try {
    const { data } = await formTemplateApi.snapshot(documentId.value)
    const html = data.data?.rendered_html
    if (!html) return notify('Dokumen belum ter-render', false)
    const w = window.open('', '_blank')
    w.document.write(html)
    w.document.close()
    w.focus()
    setTimeout(() => w.print(), 400)
  } catch (e) {
    notify(errMsg(e, 'Gagal membuka dokumen'), false)
  }
}

onMounted(load)
</script>

<template>
  <div class="modal-bg" @click.self="emit('close')">
    <div class="asesmen-modal">
      <!-- HEADER -->
      <div class="am-head">
        <div>
          <div class="am-title">Pengkajian Gawat Darurat (RM 3.7)</div>
          <div class="am-sub">{{ patientName }} · {{ patientNoRm }}</div>
        </div>
        <div class="am-head-right">
          <span class="am-triase">Triase: {{ triaseSummary }}</span>
          <button class="btn-link" @click="emit('edit-triase')">Edit Triase</button>
          <button class="am-x" @click="emit('close')">×</button>
        </div>
      </div>

      <div v-if="finalized" class="am-final-banner">
        ✓ Sudah difinalisasi & ditandatangani. Koreksi lewat addendum di Rekam Medis.
        <button class="btn btn-secondary btn-sm" @click="cetak">Cetak Dokumen</button>
      </div>

      <!-- TAB NAV -->
      <div class="am-tabs">
        <button v-for="t in TABS" :key="t.key" class="am-tab" :class="{ sel: activeTab === t.key }" @click="activeTab = t.key">{{ t.label }}</button>
      </div>

      <div class="am-body" :class="{ ro: finalized }">
        <!-- TAHAP 2: ANAMNESE -->
        <section v-show="activeTab === 'anamnesa'">
          <div class="row">
            <div class="fld">
              <label>Jenis Anamnese</label>
              <div class="chips">
                <button class="chip" :class="{ sel: form.anamnesa.type === 'AUTO' }" @click="form.anamnesa.type = 'AUTO'">Autoanamnesa</button>
                <button class="chip" :class="{ sel: form.anamnesa.type === 'ALLO' }" @click="form.anamnesa.type = 'ALLO'">Alloanamnesa</button>
              </div>
            </div>
            <div class="fld" v-if="form.anamnesa.type === 'ALLO'">
              <label>Sumber Allo</label>
              <div class="chips">
                <button v-for="o in ALLO_OPTS" :key="o" class="chip sm" :class="{ sel: form.anamnesa.allo_source.includes(o) }" @click="toggleArr(form.anamnesa.allo_source, o)">{{ o }}</button>
              </div>
            </div>
          </div>
          <div class="fld"><label>Keluhan Utama</label><textarea v-model="form.anamnesa.keluhan_utama" rows="2"></textarea></div>
          <div class="row">
            <div class="fld"><label>Riwayat Penyakit Terdahulu</label><textarea v-model="form.anamnesa.rpd" rows="2"></textarea></div>
            <div class="fld"><label>Riwayat Alergi</label><textarea v-model="form.anamnesa.alergi" rows="2"></textarea></div>
          </div>
          <div class="fld"><label>Anamnesa / Heteroanamnesa</label><textarea v-model="form.anamnesa.anamnesa_narasi" rows="3"></textarea></div>
          <div class="fld"><label>Riwayat Pemakaian Obat (RPO)</label><textarea v-model="form.anamnesa.rpo" rows="2"></textarea></div>
        </section>

        <!-- TAHAP 2b: PSIKOSOSIAL -->
        <section v-show="activeTab === 'psikososial'">
          <div class="fld">
            <label>Status Psikologis</label>
            <div class="chips">
              <button v-for="o in PSIK_OPTS" :key="o" class="chip sm" :class="{ sel: form.psikososial.psikologis.includes(o) }" @click="toggleArr(form.psikososial.psikologis, o)">{{ o }}</button>
            </div>
          </div>
          <div class="row">
            <div class="fld">
              <label>Kecenderungan Bunuh Diri</label>
              <label class="cbx"><input type="checkbox" v-model="form.psikososial.bunuh_diri.ada" /> Ada</label>
            </div>
            <div class="fld" v-if="form.psikososial.bunuh_diri.ada"><label>Dilaporkan ke</label><input v-model="form.psikososial.bunuh_diri.laporan" /></div>
          </div>
          <div class="row">
            <div class="fld">
              <label>Status Pernikahan</label>
              <select v-model="form.psikososial.pernikahan"><option value="">—</option><option>Belum Menikah</option><option>Menikah</option><option>Cerai</option></select>
            </div>
            <div class="fld">
              <label>Tempat Tinggal</label>
              <select v-model="form.psikososial.tempat_tinggal"><option value="">—</option><option>Rumah Sendiri</option><option>Rumah Keluarga</option><option>Lainnya</option></select>
            </div>
            <div class="fld" v-if="form.psikososial.tempat_tinggal === 'Lainnya'"><label>Tempat tinggal (lainnya)</label><input v-model="form.psikososial.tempat_tinggal_lainnya" /></div>
          </div>
          <div class="row">
            <div class="fld">
              <label>Hubungan dgn Keluarga</label>
              <select v-model="form.psikososial.keluarga"><option value="">—</option><option>Baik</option><option>Tidak Baik</option></select>
            </div>
            <div class="fld"><label>Agama</label><input v-model="form.psikososial.agama" /></div>
            <div class="fld">
              <label>Pelayanan Spiritual</label>
              <label class="cbx"><input type="checkbox" v-model="form.psikososial.spiritual_perlu" /> Perlu</label>
            </div>
          </div>
          <div class="fld"><label>Pekerjaan</label><input v-model="form.psikososial.pekerjaan" /></div>
          <div class="row">
            <div class="fld">
              <label>Gangguan Perilaku</label>
              <select v-model="form.perilaku.status"><option value="">—</option><option>Tidak terganggu</option><option>Ada gangguan</option></select>
            </div>
            <div class="fld" v-if="form.perilaku.status === 'Ada gangguan'">
              <label>Tingkat Bahaya</label>
              <select v-model="form.perilaku.bahaya"><option value="">—</option><option>Tidak Membahayakan</option><option>Membahayakan diri/orang lain</option></select>
            </div>
          </div>
          <p v-if="form.perilaku.bahaya === 'Membahayakan diri/orang lain'" class="warn-note">⚠ Pasien membahayakan — lakukan pengkajian Restrain sesuai prosedur.</p>
        </section>

        <!-- TAHAP 3: PEMERIKSAAN FISIK + MATA + PENUNJANG -->
        <section v-show="activeTab === 'fisik'">
          <div class="sec-h">Pemeriksaan Per-Region</div>
          <table class="region-tbl">
            <thead><tr><th>Region</th><th style="width:90px;">Hasil</th><th>Catatan (bila abnormal)</th></tr></thead>
            <tbody>
              <tr v-for="[k, lbl] in REGIONS" :key="k">
                <td>{{ lbl }}</td>
                <td>
                  <label class="cbx sm"><input type="checkbox" v-model="form.fisik[k].normal" /> Normal</label>
                </td>
                <td><input v-model="form.fisik[k].catatan" :disabled="form.fisik[k].normal" placeholder="—" /></td>
              </tr>
            </tbody>
          </table>

          <div class="sec-h">Pemeriksaan Mata (OD / OS)</div>
          <table class="eye-tbl">
            <thead><tr><th>Pemeriksaan</th><th>OD (Kanan)</th><th>OS (Kiri)</th></tr></thead>
            <tbody>
              <tr v-for="[k, lbl] in EYE_ROWS" :key="k">
                <td>{{ lbl }}</td>
                <td><input v-model="form.mata_od_os[k].od" /></td>
                <td><input v-model="form.mata_od_os[k].os" /></td>
              </tr>
            </tbody>
          </table>

          <div class="sec-h">Pemeriksaan Penunjang</div>
          <div class="fld"><label>EKG</label><input v-model="form.penunjang.ekg" /></div>
          <div class="fld"><label>Radiologi</label><input v-model="form.penunjang.radiologi" /></div>
          <div class="fld"><label>Laboratorium</label><input v-model="form.penunjang.lab" /></div>
        </section>

        <!-- TAHAP 4: ASSESSMENT & PLANNING -->
        <section v-show="activeTab === 'planning'">
          <div class="sec-h">Assessment</div>
          <div class="fld">
            <label>Diagnosa Kerja (ICD-10) <span class="req">*</span></label>
            <div v-if="form.diagnosa_kerja_name" class="picked">{{ form.diagnosa_kerja }} — {{ form.diagnosa_kerja_name }}
              <button class="btn-link" @click="form.diagnosa_kerja=''; form.diagnosa_kerja_name=''">ganti</button>
            </div>
            <template v-else>
              <input v-model="icdSearch" placeholder="cari diagnosa / kode ICD-10…" @input="searchIcd" />
              <div v-if="icdResults.length" class="icd-res">
                <button v-for="(d, i) in icdResults" :key="d.code + '|' + (d.name || i)" class="icd-item" @click="pickIcd(d)"><b>{{ d.code }}</b> · {{ d.name || d.description }}</button>
              </div>
            </template>
          </div>
          <div class="fld"><label>Diagnosa Banding</label><textarea v-model="form.diagnosa_banding" rows="2"></textarea></div>

          <div class="sec-h">Planning</div>
          <div class="fld"><label>Therapi</label><textarea v-model="form.planning.therapi" rows="2"></textarea></div>
          <div class="row">
            <div class="fld"><label>Anjuran</label><textarea v-model="form.planning.anjuran" rows="2"></textarea></div>
            <div class="fld"><label>Pengobatan</label><textarea v-model="form.planning.pengobatan" rows="2"></textarea></div>
          </div>
          <div class="row">
            <div class="fld"><label>Diteruskan ke DPJP</label><input v-model="form.planning.dpjp" /></div>
            <div class="fld"><label>Instruksi ke Penderita/Keluarga</label><input v-model="form.planning.instruksi_keluarga" /></div>
          </div>

          <div class="sec-h">Keadaan Saat Pulang / Pindah / Rujuk</div>
          <div class="row">
            <div class="fld">
              <label>Keadaan Pasien</label>
              <select v-model="form.keadaan_pulang"><option value="">—</option><option value="BAIK">Baik</option><option value="SEDANG">Sedang</option><option value="BURUK">Buruk</option><option value="PERDARAHAN">Perdarahan</option><option value="KOMA">Koma</option><option value="MENINGGAL">Meninggal</option></select>
            </div>
            <div class="fld">
              <label>Perawatan Lanjutan</label>
              <select v-model="form.perawatan_lanjutan"><option value="">—</option><option value="RAWAT_JALAN">Rawat Jalan</option><option value="RAWAT_INAP">Rawat Inap</option><option value="RAWAT_INTENSIF">Rawat Intensif</option><option value="DIRUJUK">Dirujuk</option></select>
            </div>
            <div class="fld"><label>Tgl/Jam Keluar</label><input type="datetime-local" v-model="form.waktu_keluar" /></div>
          </div>
        </section>
      </div>

      <!-- ACTIONS -->
      <div class="am-actions">
        <button class="btn btn-ghost" @click="emit('close')">Tutup</button>
        <div style="flex:1"></div>
        <template v-if="!finalized">
          <button class="btn btn-secondary" :disabled="busy" @click="saveDraft">Simpan Draft</button>
          <button class="btn btn-primary" :disabled="busy" @click="startFinalize">Finalisasi &amp; TTD</button>
        </template>
        <button v-else class="btn btn-primary" @click="cetak">Cetak Dokumen</button>
      </div>

      <!-- TTD PIN -->
      <div v-if="showTtd" class="ttd-bg" @click.self="showTtd = false">
        <div class="ttd-box">
          <div class="ttd-title">Tanda Tangan Dokter Jaga IGD</div>
          <p class="muted">Masukkan PIN tanda tangan elektronik Anda untuk mengesahkan dokumen RM 3.7.</p>
          <input type="password" v-model="pin" inputmode="numeric" placeholder="PIN" @keyup.enter="submitTtd" />
          <div class="ttd-actions">
            <button class="btn btn-ghost btn-sm" @click="showTtd = false">Batal</button>
            <button class="btn btn-primary btn-sm" :disabled="busy" @click="submitTtd">Tanda Tangani</button>
          </div>
        </div>
      </div>

      <div v-if="toast" class="am-toast" :class="{ err: !toast.ok }">{{ toast.msg }}</div>
    </div>
  </div>
</template>

<style scoped>
.modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,.5); display: flex; align-items: center; justify-content: center; z-index: 60; padding: 1rem; }
.asesmen-modal { background: var(--bc); border-radius: 14px; width: 880px; max-width: 96vw; max-height: 92vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 24px 60px rgba(0,0,0,.3); position: relative; }
.am-head { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; border-bottom: 1px solid var(--gb); }
.am-title { font-weight: 700; font-size: 14px; color: var(--td); }
.am-sub { font-size: 11.5px; color: var(--tu); margin-top: 2px; }
.am-head-right { display: flex; align-items: center; gap: 10px; }
.am-triase { font-size: 11px; color: var(--td); background: var(--gl); padding: 3px 9px; border-radius: 20px; }
.am-x { background: none; border: none; font-size: 22px; line-height: 1; cursor: pointer; color: var(--tu); }
.btn-link { background: none; border: none; color: var(--ga); cursor: pointer; font-size: 11px; text-decoration: underline; padding: 0; }
.am-final-banner { display: flex; align-items: center; gap: 10px; background: #dcfce7; color: #15803d; font-size: 12px; padding: 7px 1rem; }
.am-final-banner .btn { margin-left: auto; }
.am-tabs { display: flex; gap: 4px; padding: 0.5rem 1rem 0; border-bottom: 1px solid var(--gb); }
.am-tab { padding: 7px 13px; border: none; background: none; border-bottom: 2px solid transparent; cursor: pointer; font-size: 12px; font-weight: 600; color: var(--tu); }
.am-tab.sel { color: var(--ga); border-bottom-color: var(--ga); }
.am-body { padding: 0.9rem 1rem; overflow-y: auto; flex: 1; }
.am-body.ro { pointer-events: none; opacity: .75; }
.am-body.ro .am-actions { pointer-events: auto; }
.row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.6rem; }
.fld { margin-bottom: 0.6rem; display: flex; flex-direction: column; }
.fld > label { font-size: 11px; color: var(--tu); margin-bottom: 3px; font-weight: 600; }
.req { color: #dc2626; }
input, select, textarea { width: 100%; padding: 6px 9px; border: 1px solid var(--gb); border-radius: 7px; font-family: inherit; font-size: 12px; background: var(--bc); color: var(--td); box-sizing: border-box; }
input:focus, select:focus, textarea:focus { outline: none; border-color: var(--ga); }
.chips { display: flex; gap: 5px; flex-wrap: wrap; }
.chip { padding: 5px 11px; border: 1.5px solid var(--gb); border-radius: 20px; background: var(--bc); cursor: pointer; font-size: 11px; font-weight: 600; color: var(--tm); }
.chip.sm { padding: 4px 9px; font-size: 10.5px; }
.chip.sel { border-color: var(--ga); background: var(--ga); color: #fff; }
.cbx { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--td); }
.cbx input { width: auto; }
.cbx.sm { font-size: 11px; }
.sec-h { font-size: 11.5px; font-weight: 700; color: var(--td); text-transform: uppercase; letter-spacing: .3px; margin: 12px 0 6px; padding-bottom: 3px; border-bottom: 1px solid var(--gb); }
.region-tbl, .eye-tbl { width: 100%; border-collapse: collapse; font-size: 11.5px; margin-bottom: 6px; }
.region-tbl th, .eye-tbl th { text-align: left; color: var(--tu); font-weight: 600; padding: 4px 6px; border-bottom: 1px solid var(--gb); font-size: 10.5px; }
.region-tbl td, .eye-tbl td { padding: 3px 6px; border-bottom: 1px solid var(--gl); vertical-align: middle; }
.region-tbl td:first-child, .eye-tbl td:first-child { color: var(--td); font-weight: 500; }
.warn-note { font-size: 11px; color: #b45309; background: #fef3c7; padding: 6px 9px; border-radius: 7px; margin: 4px 0 0; }
.picked { font-size: 12px; color: var(--td); background: var(--gl); padding: 6px 9px; border-radius: 7px; display: flex; gap: 8px; align-items: center; }
.icd-res { border: 1px solid var(--gb); border-radius: 7px; margin-top: 4px; max-height: 180px; overflow-y: auto; }
.icd-item { display: block; width: 100%; text-align: left; padding: 6px 9px; border: none; border-bottom: 1px solid var(--gl); background: var(--bc); cursor: pointer; font-size: 11.5px; color: var(--td); }
.icd-item:hover { background: var(--gl); }
.am-actions { display: flex; gap: 8px; align-items: center; padding: 0.7rem 1rem; border-top: 1px solid var(--gb); background: var(--bc); }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 0 14px; height: 34px; border-radius: 8px; font-family: inherit; font-size: 12px; font-weight: 600; cursor: pointer; border: 1.5px solid transparent; }
.btn-sm { height: 28px; font-size: 11.5px; padding: 0 11px; }
.btn-primary { background: var(--ga); color: #fff; }
.btn-secondary { background: var(--bc); border-color: var(--gb); color: var(--td); }
.btn-ghost { background: none; color: var(--tm); }
.btn:disabled { opacity: .55; cursor: not-allowed; }
.ttd-bg { position: absolute; inset: 0; background: rgba(0,0,0,.4); display: flex; align-items: center; justify-content: center; }
.ttd-box { background: var(--bc); border-radius: 12px; padding: 1.1rem; width: 320px; max-width: 90%; }
.ttd-title { font-weight: 700; font-size: 13px; margin-bottom: 6px; color: var(--td); }
.ttd-box .muted { font-size: 11px; color: var(--tu); margin: 0 0 10px; }
.ttd-actions { display: flex; justify-content: flex-end; gap: 6px; margin-top: 10px; }
.am-toast { position: absolute; bottom: 70px; left: 50%; transform: translateX(-50%); background: #16a34a; color: #fff; padding: 8px 16px; border-radius: 8px; font-size: 12px; box-shadow: 0 6px 18px rgba(0,0,0,.2); }
.am-toast.err { background: #dc2626; }
.muted { color: var(--tu); font-size: 11px; }
</style>
