<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { RouterLink } from 'vue-router'
import { useDokterStore } from '@/stores/dokterStore'
import { useJadwalDokterStore } from '@/stores/jadwalDokterStore'
import { useAuthStore } from '@/stores/auth'
import { masterApi, dokterApi, integrasiApi } from '@/services/api'
import PatientAvatar from '@/components/common/PatientAvatar.vue'
import FormDocsBrowser from '@/components/forms/FormDocsBrowser.vue'

const store      = useDokterStore()
const jadwalStore = useJadwalDokterStore()
const auth       = useAuthStore()

// ─── Status Saya Hari Ini (Jadwal Dokter quick panel) ───────────────────────
const myEmployeeId = computed(() => auth.user?.employee?.id ?? null)

// Identitas dokter (nama + SIP) dipindah ke topbar global (AppTopbar.vue).
const todayIso     = () => {
  const d = new Date().getDay()        // 0=Sun..6=Sat
  return d === 0 ? 7 : d               // 1=Mon..7=Sun
}
const mySchedulesToday = computed(() => {
  if (!myEmployeeId.value) return []
  const dow = todayIso()
  const me  = jadwalStore.daftarDokter.find((e) => e.employee_id === myEmployeeId.value)
  return (me?.jadwal ?? []).filter((j) => j.day_of_week === dow)
})
const myRoomEdit = ref({})  // { [scheduleId]: roomString }
function startEditRoom(j) {
  myRoomEdit.value = { ...myRoomEdit.value, [j.id]: j.room ?? '' }
}
function cancelEditRoom(id) {
  const next = { ...myRoomEdit.value }
  delete next[id]
  myRoomEdit.value = next
}
async function saveRoom(j) {
  const newRoom = (myRoomEdit.value[j.id] ?? '').trim()
  if (!newRoom) { toast('w', 'Ruangan tidak boleh kosong'); return }
  try {
    await jadwalStore.updateJadwal(j.id, { room: newRoom })
    cancelEditRoom(j.id)
    toast('s', `Ruangan diperbarui → D${newRoom}`)
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal memperbarui ruangan')
  }
}
async function toggleMyAktif(j) {
  try {
    await jadwalStore.toggleAktif(j.id)
    toast('s', j.is_active ? 'Status dimatikan' : 'Status diaktifkan')
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal mengubah status')
  }
}
const DAY_LABELS = { 1:'Senin', 2:'Selasa', 3:'Rabu', 4:'Kamis', 5:'Jumat', 6:'Sabtu', 7:'Minggu' }

// ─── UI filter state ────────────────────────────────────────────────────────
const qFilter        = ref('waiting')
const ptypeFilter    = ref('Semua')
const qSearch        = ref('')
const pendingCallIds = ref([])
const pendingSkipIds = ref([])

// ─── Adapter helpers ────────────────────────────────────────────────────────
function ptypeOf(visit) {
  const g = visit?.guarantor_type
  if (g === 'BPJS') return 'bpjs'
  if (g === 'ASURANSI' || g === 'PERUSAHAAN' || g === 'SOSIAL') return 'asn'
  return 'umum'
}
function uiStatus(s) {
  if (s === 'WAITING') return 'waiting'
  if (s === 'CALLED' || s === 'IN_PROGRESS') return 'progress'
  if (s === 'DI_PENUNJANG') return 'penunjang'
  if (s === 'SELESAI_PENUNJANG') return 'penunjang_done'
  if (s === 'COMPLETED') return 'done'
  return 'skip'
}
const STATUS_LABEL = {
  waiting: 'Menunggu', progress: 'Proses', done: 'Selesai', skip: 'Dilewati',
  penunjang: 'Pemeriksaan Penunjang', penunjang_done: 'Selesai Penunjang',
}
const STATUS_PILL = {
  waiting: 'pill-waiting', progress: 'pill-in_progress', done: 'pill-completed', skip: 'pill-completed',
  penunjang: 'pill-penunjang', penunjang_done: 'pill-penunjang-done',
}
function statusLabel(s) { return STATUS_LABEL[s] ?? s }
function statusPillClass(s) { return STATUS_PILL[s] ?? 'pill-waiting' }
function calcAge(dob) {
  if (!dob) return null
  const d = new Date(dob), n = new Date()
  return n.getFullYear() - d.getFullYear() -
    (n < new Date(n.getFullYear(), d.getMonth(), d.getDate()) ? 1 : 0)
}
function fmtTime(d) {
  if (!d) return '—'
  return new Date(d).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
}
function fmtRx(sph, cyl, ax) {
  if (sph == null && cyl == null && ax == null) return '—'
  const s = sph != null ? `S${sph >= 0 ? '+' : ''}${sph}` : ''
  const c = cyl != null ? ` C${cyl >= 0 ? '+' : ''}${cyl}` : ''
  const a = ax  != null ? ` ×${ax}°` : ''
  return (s + c + a).trim() || '—'
}
// Bulatkan ke bilangan bulat; biarkan nilai non-numerik ('—', visus '6/6') apa adanya.
function toInt(v) {
  if (v == null || v === '—' || v === '') return v
  const n = Number(v)
  return Number.isFinite(n) ? Math.round(n) : v
}
// Keratometri: "43.50 / 44.00 @ 180°"
function fmtK(k1, k2, axis) {
  if (k1 == null && k2 == null) return '—'
  const ax = axis != null ? ` @ ${axis}°` : ''
  return `${k1 ?? '—'} / ${k2 ?? '—'}${ax}`
}
// ADD power: "+2.00" atau "—"
function fmtAdd(v) { return v != null ? `+${v}` : '—' }
// Kacamata lama: Rx (+ ADD bila ada)
function fmtGlasses(sph, cyl, ax, add) {
  const base = fmtRx(sph, cyl, ax)
  const a = add != null ? `Add +${add}` : ''
  if (base === '—' && !a) return '—'
  if (base === '—') return a
  return a ? `${base} · ${a}` : base
}

// Map satu baris queue dari API → bentuk yang dipakai template.
// Default safe values supaya template tidak crash kalau nurse/refraksi belum ada.
function mapPatient(q) {
  if (!q) return null
  const v     = q.visit ?? {}
  const p     = v.patient ?? {}
  const nurse = v.nurse_assessment ?? null
  const refr  = v.refraction_record ?? null
  const ptype = ptypeOf(v)

  return {
    id:      q.id,
    visitId: v.id ?? null,
    patientId: p.id ?? null,
    qNum:    q.queue_number,
    name:    p.name ?? '—',
    rm:      p.no_rm ?? '—',
    photo:   p.photo_url ?? null,
    nik:     p.nik ?? '—',
    age:     p.age ?? calcAge(p.date_of_birth) ?? '—',
    gender:  p.gender ?? '—',
    address: p.address ?? '',
    classification: v.classification ?? '',
    internalRefFrom: v.internal_referral_from_schedule?.poliklinik ?? null,
    poli:    ptype === 'bpjs' ? 'Poli BPJS'
           : ptype === 'asn'  ? 'Poli Asuransi'
           : 'Poli Umum',
    ptype,
    bpjsNo:  p.bpjs_number ?? '',
    sepNo:   v.no_sep ?? '—',
    status:  uiStatus(q.status),
    rawStatus: q.status,
    time:    fmtTime(q.created_at),
    hasNurse: !!(nurse?.is_finalized || v.assessment_finalized),
    hasRO:    !!refr?.is_finalized,
    allergies: nurse?.allergy_detail
      ? nurse.allergy_detail.split(',').map((s) => s.trim()).filter(Boolean)
      : (p.allergy_notes ? [p.allergy_notes] : []),
    nd: {
      td_s:    nurse?.td_sistol  ?? '—',
      td_d:    nurse?.td_diastol ?? '—',
      nadi:    nurse?.nadi       ?? '—',
      spo2:    nurse?.spo2       ?? '—',
      suhu:    nurse?.suhu       ?? '—',
      pain:    nurse?.pain_scale ?? 0,
      kgd:     nurse?.kgd        ?? '—',
      keluhan: nurse?.chief_complaint ?? '—',
    },
    rd: {
      ucva_od: refr?.visus_awal_od  ?? '—',
      ucva_os: refr?.visus_awal_os  ?? '—',
      pinhole_od: refr?.pinhole_od ?? '—',
      pinhole_os: refr?.pinhole_os ?? '—',
      bcva_od: refr?.visus_akhir_od ?? '—',
      bcva_os: refr?.visus_akhir_os ?? '—',
      autoref_od: fmtRx(refr?.autoref_od_sph, refr?.autoref_od_cyl, refr?.autoref_od_axis),
      autoref_os: fmtRx(refr?.autoref_os_sph, refr?.autoref_os_cyl, refr?.autoref_os_axis),
      kerato_od: fmtK(refr?.keratometri1_od, refr?.keratometri2_od, refr?.keratometri_axis_od),
      kerato_os: fmtK(refr?.keratometri1_os, refr?.keratometri2_os, refr?.keratometri_axis_os),
      rx_od:   fmtRx(refr?.refraksi_subjektif_od_sph, refr?.refraksi_subjektif_od_cyl, refr?.refraksi_subjektif_od_axis),
      rx_os:   fmtRx(refr?.refraksi_subjektif_os_sph, refr?.refraksi_subjektif_os_cyl, refr?.refraksi_subjektif_os_axis),
      add_od:  fmtAdd(refr?.add_power_od),
      add_os:  fmtAdd(refr?.add_power_os),
      old_od:  fmtGlasses(refr?.old_glasses_od_sph, refr?.old_glasses_od_cyl, refr?.old_glasses_od_axis, refr?.old_glasses_add_od),
      old_os:  fmtGlasses(refr?.old_glasses_os_sph, refr?.old_glasses_os_cyl, refr?.old_glasses_os_axis, refr?.old_glasses_add_os),
      iop_od:  refr?.iop_od ?? '—',
      iop_os:  refr?.iop_os ?? '—',
      iop_method: refr?.iop_method ?? '',
      pd:      refr?.pd_distance ?? '',
      perception: refr?.perception_type ?? '',
      note:    refr?.clinical_notes ?? '',
    },
    // List berikut belum di-fetch dari API saat antrian list (perlu separate call ke
    // /dokter/kunjungan/{visitId}). Default kosong supaya template tidak crash.
    // (soapHistory tidak lagi di sini — di-fetch terpisah ke soapHistoryData
    //  lewat RME aggregator saat pasien dipilih.)
    history:     [],
    penunjang:   [],
    _raw: q,
  }
}

const patients = computed(() => store.antrian.map(mapPatient))
const selP     = computed(() => store.selectedQueue ? mapPatient(store.selectedQueue) : null)

// Baris tabel RO untuk Tab 1. Baris non-inti disembunyikan bila kedua mata kosong
// agar kartu tetap ringkas tapi tetap memuat seluruh data yang ada.
const roRows = computed(() => {
  const r = selP.value?.rd
  if (!r) return []
  const rows = [
    { label: 'Autoref',            od: r.autoref_od, os: r.autoref_os, always: true },
    { label: 'Keratometri',        od: r.kerato_od,  os: r.kerato_os,  always: true },
    { label: 'IOP', unit: 'mmHg', note: r.iop_method || null, cls: 'strong',
      od: toInt(r.iop_od), os: toInt(r.iop_os),
      odWarn: r.iop_od >= 22, osWarn: r.iop_os >= 22, always: true },
    { label: 'Visus Awal (UCVA)',  od: r.ucva_od,    os: r.ucva_os,    cls: 'strong', always: true },
    { label: 'Visus Akhir (BCVA)', od: r.bcva_od,    os: r.bcva_os,    cls: 'strong success', always: true },
    { label: 'Pinhole',            od: r.pinhole_od, os: r.pinhole_os },
    { label: 'Refraksi Subjektif', od: r.rx_od,      os: r.rx_os,      cls: 'strong', always: true },
    { label: 'Adisi (ADD)',        od: r.add_od,     os: r.add_os },
    { label: 'Kacamata Lama',      od: r.old_od,     os: r.old_os },
  ]
  return rows.filter((row) => row.always || row.od !== '—' || row.os !== '—')
})

// ─── SOAP / CPPT: paginasi per tanggal kunjungan ─────────────────────────────
// 1 halaman = 1 tanggal. Entri perawat + dokter di hari yang sama tetap 1 halaman.
// Urutan descending (kunjungan terbaru = halaman pertama).
// Sumber data = RME aggregator lintas-kunjungan pasien (di-fetch saat pasien dipilih),
// BUKAN selP.soapHistory yang selalu kosong di mapPatient.
const soapHistoryData = ref([])
const soapHistoryLoading = ref(false)
const soapPageIdx = ref(0)

// Map respons RME (/rekam-medis/pasien/{id}/kunjungan) → entri kartu SOAP/CPPT.
// Tiap visit yang punya SOAP terisi → 1 entri Dokter. Vitals perawat tetap di kartu
// "Riwayat Kunjungan" (PerawatView), di sini fokus narasi SOAP.
function _mapSoapHistory(rows) {
  const out = []
  for (const v of rows ?? []) {
    const date = v.visit_date ?? '—'
    const s = v.detail?.soap ?? null
    if (s && (s.s || s.o || s.a || s.p)) {
      out.push({ date, role: 'Dokter', S: s.s ?? '', O: s.o ?? '', A: s.a ?? '', P: s.p ?? '' })
    }
  }
  return out
}

async function loadSoapHistory() {
  const patientId = selP.value?.patientId
  soapPageIdx.value = 0
  if (!patientId) { soapHistoryData.value = []; return }
  soapHistoryLoading.value = true
  try {
    const { data } = await dokterApi.riwayatKunjungan(patientId)
    soapHistoryData.value = _mapSoapHistory(data.data ?? [])
  } catch {
    soapHistoryData.value = []
  } finally {
    soapHistoryLoading.value = false
  }
}

const soapPages = computed(() => {
  const list = soapHistoryData.value ?? []
  const groups = new Map()
  for (const e of list) {
    const key = e.date ?? '—'
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push(e)
  }
  return [...groups.entries()]
    .sort((a, b) => {
      const ta = Date.parse(a[0]), tb = Date.parse(b[0])
      if (!Number.isNaN(ta) && !Number.isNaN(tb)) return tb - ta            // tanggal terbaru dulu
      return a[0] < b[0] ? 1 : a[0] > b[0] ? -1 : 0                          // fallback string desc
    })
    .map(([date, entries]) => ({ date, entries }))
})
const currentSoapPage = computed(() => soapPages.value[soapPageIdx.value] ?? soapPages.value[0] ?? null)
// Label di bawah tanggal: idx 0 (descending) = kunjungan terakhir, sisanya = sebelumnya.
const soapPageLabel = computed(() => (soapPageIdx.value === 0 ? 'Kunjungan terakhir' : 'Kunjungan sebelumnya'))

// Muat riwayat SOAP/CPPT tiap kali pasien (bukan sekadar kunjungan) berganti.
watch(() => selP.value?.patientId, loadSoapHistory, { immediate: true })

const tab = ref('data') // 'data' | 'pemeriksaan' | 'tindakan' | 'soap'
const dw = ref(null)
const finalized = ref(false)
const finalizing = ref(false)

const filtQ = computed(() => {
  let list = patients.value
  if (qFilter.value === 'done') {
    list = list.filter((p) => p.status === 'done' || p.status === 'skip')
  } else {
    list = list.filter((p) => p.status !== 'done' && p.status !== 'skip')
  }
  if (ptypeFilter.value === 'BPJS')          list = list.filter((p) => p.ptype === 'bpjs')
  else if (ptypeFilter.value === 'UmumAsn')  list = list.filter((p) => p.ptype === 'umum' || p.ptype === 'asn')
  if (qSearch.value) {
    const s = qSearch.value.toLowerCase()
    list = list.filter((p) => p.name.toLowerCase().includes(s) || p.qNum.toLowerCase().includes(s))
  }
  return list
})

const cWait = computed(() => patients.value.filter((p) => ['waiting', 'progress', 'penunjang', 'penunjang_done'].includes(p.status)).length)
const cDone = computed(() => patients.value.filter((p) => p.status === 'done').length)
const bpjsCount = computed(() => patients.value.filter((p) => p.ptype === 'bpjs' && p.status !== 'done' && p.status !== 'skip').length)
const umumAsnCount = computed(() => patients.value.filter((p) => (p.ptype === 'umum' || p.ptype === 'asn') && p.status !== 'done' && p.status !== 'skip').length)

const classColor = { Baru: 'cls-baru', 'Pre-Op': 'cls-preop', 'Post-Op': 'cls-postop', Kontrol: 'cls-kontrol' }
function clsCls(c) { return classColor[c] ?? 'cls-baru' }

const toasts = ref([])
let toastId = 0
function toast(type, msg) {
  const id = ++toastId
  toasts.value.push({ id, type, msg })
  setTimeout(() => (toasts.value = toasts.value.filter((t) => t.id !== id)), 3500)
}

function resetFormState() {
  tab.value = 'data'
  soapPageIdx.value = 0
  dw.value = null
  finalized.value = false
  resetExam()
  tab2Exists.value = false
  resetSoap()
  tindakanList.value = []
  tindakanSearch.value = ''
  showTarifList.value = false
  rxList.value = []
  obatSearch.value = ''
  newRx.value = makeRx()
  kasirNote.value = ''
  pharmacyNote.value = ''
  tab3Sent.value = false
  showSendKasirModal.value = false
  sendingToKasir.value = false
  diagnosisUtama.value = null
  diagnosisSekunder.value = []
  diagnosisText.value = ''
  icd9List.value = []
  planning.value = ''
  tanggalKontrol.value = ''
  surgeryLocation.value = 'RUANG_BEDAH'
  surgeryCategory.value = ''
  surgeryPkg.value = ''
  surgeryDate.value = ''
  surgeryTime.value = ''
  requiresInpatient.value = false
  bedahSlotInfo.value = null
  rujukFaskes.value = ''
  rujukAlasan.value = ''
  rujukDone.value = false
  rujukMode.value = 'EXTERNAL'
  internalTargets.value = []
  internalTargetId.value = ''
  internalReason.value = ''
  Object.assign(rk, { faskesKode: '', faskesNama: '', poliKode: '', poliNama: '', tipeRujukan: '1', jnsPelayanan: '2', diagKode: '', diagNama: '', catatan: '' })
  rkFaskesQ.value = ''; rkFaskesRes.value = []; rkPoliQ.value = ''; rkPoliRes.value = []
  suratKontrol.value = null
  dxSearch.value = ''
  dxSearchSek.value = ''
  icd9Search.value = ''
  signed.value = false
  signTimestamp.value = null
  pinInput.value = ''
  pinError.value = ''
  showSignModal.value = false
  penunjangOrders.value = []
  showPenunjangModal.value = false
  showCustomPenunjang.value = false
  customPenunjang.value = { name: '', category: '' }
  selectedHasil.value = null
  showHasilModal.value = false
  // Batalkan autosave tertunda dari pasien sebelumnya agar tidak fire dengan
  // visitId pasien baru (saveTindakan/saveResep membaca selP saat timer jalan).
  clearTimeout(_saveTindakanTimer)
  clearTimeout(_saveResepTimer)
}

function pickPt(p) {
  if (store.selectedQueue?.id === p.id) return
  store.pickPatient(p._raw)
  resetFormState()
  toast('i', `Membuka RME ${p.name}`)
}

async function callPt(p) {
  if (pendingCallIds.value.includes(p.id)) return
  const isRecall = p.rawStatus !== 'WAITING'
  pendingCallIds.value.push(p.id)
  try {
    await store.panggilAntrian(p.id)
    toast('i', `${isRecall ? 'Memanggil ulang' : 'Memanggil'} ${p.qNum} — ${p.name}`)
  } catch (err) {
    toast('w', err.message ?? 'Gagal memanggil pasien')
  } finally {
    pendingCallIds.value = pendingCallIds.value.filter((id) => id !== p.id)
  }
}

async function skipPt(p) {
  if (p.status === 'done' || p.status === 'skip') {
    toast('w', 'Pasien sudah selesai, tidak bisa dilewati'); return
  }
  if (pendingSkipIds.value.includes(p.id)) return
  pendingSkipIds.value.push(p.id)
  try {
    // Otoritatif di server (tukar queue_sequence + broadcast TV) — bukan reorder
    // lokal yang dibuang polling 8s. Pola sama: Kasir/Penunjang/Refraksionis.
    await store.lewatiAntrian(p.id)
    if (store.selectedQueue?.id === p.id) store.clearSelected()
    toast('w', `${p.qNum} diturunkan 1 antrean`)
  } catch (err) {
    toast('w', err.message ?? 'Gagal melewati pasien')
  } finally {
    pendingSkipIds.value = pendingSkipIds.value.filter((id) => id !== p.id)
  }
}

// ─── Lifecycle ──────────────────────────────────────────────────────────────
onMounted(async () => {
  await store.fetchAntrian()
  store.startPolling()
  // Load jadwal dokter agar "Status Saya Hari Ini" terisi
  jadwalStore.fetchAll()
  // Load master jenis penunjang untuk modal order
  loadPenunjangTypes()
  // Load daftar obat ber-harga (inventori farmasi → penentuan harga)
  loadObat()
  // Load master paket bedah (untuk planning → Jadwalkan Bedah)
  loadSurgeryPackages()
  // Load master paket pemeriksaan (untuk Tab Tindakan)
  loadExamPackages()
  // Tutup dropdown pencarian tindakan saat klik di luar
  document.addEventListener('mousedown', _handleTindakanClickOutside)
})

onUnmounted(() => {
  store.stopPolling()
  store.clearSelected()
  document.removeEventListener('mousedown', _handleTindakanClickOutside)
})

// ── TAB 2: PEMERIKSAAN MATA ──────────────────────────────────────────────────

const segmenOpts = ['Normal', 'Tidak Normal', 'Tidak Dapat Dinilai']

const saFields = [
  { key: 'kornea', label: 'Kornea' },
  { key: 'coa', label: 'COA' },
  { key: 'iris', label: 'Iris' },
  { key: 'pupil', label: 'Pupil' },
  { key: 'lensa', label: 'Lensa' },
]
const spFields = [
  { key: 'papil', label: 'Papil' },
  { key: 'macula', label: 'Macula' },
  { key: 'retina', label: 'Retina' },
  { key: 'vitreous', label: 'Vitreous' },
]

function makeExam() {
  return {
    anamnese: '',
    sa: { kornea: { od: '', os: '' }, coa: { od: '', os: '' }, iris: { od: '', os: '' }, pupil: { od: '', os: '' }, lensa: { od: '', os: '' } },
    sp: { papil: { od: '', os: '' }, macula: { od: '', os: '' }, retina: { od: '', os: '' }, vitreous: { od: '', os: '' } },
    slitlamp_notes: '',
  }
}

const exam = ref(makeExam())
function resetExam() { exam.value = makeExam() }

// Apakah doctor_examination untuk kunjungan ini sudah ada di backend?
// Menentukan POST (create) vs PUT (update) saat menyimpan Tab 2.
const tab2Exists = ref(false)

// Flatten state `exam` → kolom backend (sa_kornea_od, sp_papil_os, ...).
// Segmen kosong dikirim `null` (BUKAN ''), karena rule `in:` menolak string kosong.
function buildTab2Payload() {
  const out = { anamnese: exam.value.anamnese || null, slitlamp_notes: exam.value.slitlamp_notes || null }
  for (const f of saFields) {
    out[`sa_${f.key}_od`] = exam.value.sa[f.key].od || null
    out[`sa_${f.key}_os`] = exam.value.sa[f.key].os || null
  }
  for (const f of spFields) {
    out[`sp_${f.key}_od`] = exam.value.sp[f.key].od || null
    out[`sp_${f.key}_os`] = exam.value.sp[f.key].os || null
  }
  return out
}

// Prefill `exam` dari record backend saat kunjungan dipilih (read-back).
async function loadTab2() {
  const visitId = selP.value?.visitId
  if (!visitId) { tab2Exists.value = false; return }
  try {
    const { data } = await dokterApi.showTab2(visitId)
    const e = data.data
    if (!e) { tab2Exists.value = false; return }
    tab2Exists.value = true
    exam.value.anamnese = e.anamnese ?? ''
    exam.value.slitlamp_notes = e.slitlamp_notes ?? ''
    // Diagnosa naratif (Tab 4) — pulihkan agar tampil saat buka ulang / read-only.
    if (e.diagnosis_text != null) diagnosisText.value = e.diagnosis_text
    for (const f of saFields) {
      exam.value.sa[f.key].od = e[`sa_${f.key}_od`] ?? ''
      exam.value.sa[f.key].os = e[`sa_${f.key}_os`] ?? ''
    }
    for (const f of spFields) {
      exam.value.sp[f.key].od = e[`sp_${f.key}_od`] ?? ''
      exam.value.sp[f.key].os = e[`sp_${f.key}_os`] ?? ''
    }
  } catch { tab2Exists.value = false }
}

// Simpan Tab 2: PUT bila record sudah ada, POST bila belum. Bila POST gagal
// karena record sempat dibuat (mis. autosave Tab 3 lebih dulu), fallback ke PUT.
async function saveTab2() {
  const visitId = selP.value?.visitId
  if (!visitId) return
  const payload = buildTab2Payload()
  if (tab2Exists.value) {
    await dokterApi.updateTab2(visitId, payload)
    return
  }
  try {
    await dokterApi.storeTab2(visitId, payload)
    tab2Exists.value = true
  } catch (e) {
    if (e.response?.status === 422) {
      await dokterApi.updateTab2(visitId, payload)
      tab2Exists.value = true
    } else { throw e }
  }
}

// Muat Tab 2 tiap kali kunjungan yang dipilih berganti.
watch(() => selP.value?.visitId, loadTab2, { immediate: true })

// ── PENUNJANG ────────────────────────────────────────────────────────────────
// Daftar jenis penunjang diambil dari master (modul Penunjang → tab "Jenis Penunjang").
const penunjangTypes = ref([])
async function loadPenunjangTypes() {
  try {
    const { data } = await masterApi.diagnosticTestType.list({ active: 1, per_page: 200 })
    const rows = data.data?.data ?? data.data ?? []
    penunjangTypes.value = rows.map((r) => ({ id: r.code, code: r.code, name: r.name, category: r.category ?? '' }))
  } catch {
    penunjangTypes.value = []
  }
}
const penunjangOrders = ref([])   // order REQUESTED (pending) dari DB — KUNJUNGAN AKTIF saja
const showPenunjangModal = ref(false)
const selectedHasil = ref(null)
const showHasilModal = ref(false)

// Riwayat HASIL penunjang LINTAS-kunjungan (RME aggregator) — dipaginasi per tanggal
// kunjungan seperti kartu SOAP/CPPT (kunjungan terakhir dulu, descending).
const penunjangHistory = ref([])
const penunjangHistoryLoading = ref(false)
const penunjangPageIdx = ref(0)

async function loadPenunjangHistory() {
  const patientId = selP.value?.patientId
  penunjangPageIdx.value = 0
  if (!patientId) { penunjangHistory.value = []; return }
  penunjangHistoryLoading.value = true
  try {
    const { data } = await dokterApi.riwayatPenunjang(patientId)
    penunjangHistory.value = (data.data ?? []).map((r) => ({
      id:            r.order_id,
      date:          r.visit_date ?? '—',
      name:          r.test_name ?? r.test_type,
      eyeSide:       r.eye_side ?? '',
      status:        r.status ?? '',
      result:        r.summary || (r.status === 'IN_PROGRESS' ? 'Sedang diproses' : '(lihat detail hasil)'),
      kesimpulan:    r.detail?.expertise_data?.kesimpulan ?? '',
      ringkasan:     r.detail?.expertise_data?.ringkasan ?? '',
      notes:         r.detail?.notes ?? '',
      biometri:      (r.test_type === 'Biometri' && (r.detail?.expertise_data?.od || r.detail?.expertise_data?.os))
        ? { od: r.detail.expertise_data.od ?? null, os: r.detail.expertise_data.os ?? null } : null,
      attachmentUrl:  r.attachment_url ?? null,
      attachmentPath: r.attachment_url ?? '',   // regex ekstensi gambar di modal pakai URL
    }))
  } catch {
    penunjangHistory.value = []
  } finally {
    penunjangHistoryLoading.value = false
  }
}

// Group per tanggal kunjungan (descending — terbaru di halaman pertama).
const penunjangPages = computed(() => {
  const groups = new Map()
  for (const h of penunjangHistory.value) {
    const key = h.date ?? '—'
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push(h)
  }
  return [...groups.entries()]
    .sort((a, b) => {
      const ta = Date.parse(a[0]), tb = Date.parse(b[0])
      if (!Number.isNaN(ta) && !Number.isNaN(tb)) return tb - ta
      return a[0] < b[0] ? 1 : a[0] > b[0] ? -1 : 0
    })
    .map(([date, items]) => ({ date, items }))
})
const currentPenunjangPage = computed(() => penunjangPages.value[penunjangPageIdx.value] ?? penunjangPages.value[0] ?? null)
const penunjangPageLabel = computed(() => (penunjangPageIdx.value === 0 ? 'Kunjungan terakhir' : 'Kunjungan sebelumnya'))

// Modal "Dokumen Rekam Medis Pasien" (Form Registry) — dibuka dari kartu launcher
// di Tab SOAP supaya form-form tidak memanjang ke bawah / butuh scroll panjang.
const showFormDocsModal = ref(false)

// test_type dari backend = KODE master (mis. "OCT"). Resolve display name + kategori
// dari master; bila kode tak ada di master (order "Lainnya" teks bebas) → pakai apa adanya.
function _typeByCode(code) {
  return penunjangTypes.value.find((t) => t.code === code) ?? null
}
function _mapOrder(o) {
  const t = _typeByCode(o.test_type)
  return {
    id:         o.id,
    code:       o.test_type,
    name:       t?.name ?? o.test_type,
    category:   t?.category ?? '',
    status:     o.status,
    _persisted: true,
  }
}

// Muat order penunjang PENDING milik kunjungan AKTIF (workflow order).
// HASIL (riwayat lintas-kunjungan) di-load terpisah via loadPenunjangHistory.
async function loadPenunjangData() {
  const visitId = selP.value?.visitId
  if (!visitId) { penunjangOrders.value = []; return }
  try {
    const { data } = await dokterApi.indexOrderPenunjang(visitId)
    penunjangOrders.value = (data.data ?? [])
      .filter((o) => o.status === 'REQUESTED')
      .map(_mapOrder)
  } catch { /* abaikan, biarkan list apa adanya */ }
  // Catatan: chip "Dipesan" cocok by `name` (bukan id), supaya entry persisted
  // dari backend (UUID) dan staging (tmp-id) sama-sama match dengan master `t.id`.
}

// Toggle staging lokal — order baru benar-benar dikirim ke backend saat klik
// tombol "Konfirmasi N Pemeriksaan" di footer modal (confirmPenunjang).
function orderPenunjang(t) {
  if (!selP.value?.visitId) { toast('e', 'Pilih pasien terlebih dahulu'); return }
  const existing = penunjangOrders.value.find((x) => x.name === t.name)
  if (existing) {
    // Klik chip yang sudah dipilih = batal pilih (kalau masih staging)
    if (!existing._persisted) {
      penunjangOrders.value = penunjangOrders.value.filter((x) => x.name !== t.name)
    } else {
      toast('w', `${t.name} sudah dipesan sebelumnya — hapus via tombol ×`)
    }
    return
  }
  penunjangOrders.value.push({
    id: `tmp-${t.id}`,
    code: t.code,
    name: t.name,
    category: t.category,
    status: 'REQUESTED',
    _persisted: false,
  })
}

async function removePenunjang(id) {
  const o = penunjangOrders.value.find((x) => x.id === id)
  if (!o) return
  // Staging lokal — cukup hapus dari list, belum pernah masuk DB.
  if (!o._persisted) {
    penunjangOrders.value = penunjangOrders.value.filter((x) => x.id !== id)
    return
  }
  try {
    await dokterApi.cancelOrderPenunjang(id)
    penunjangOrders.value = penunjangOrders.value.filter((x) => x.id !== id)
    toast('i', 'Order penunjang dibatalkan')
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal membatalkan order')
  }
}

// "Lainnya" — pemeriksaan di luar master (teks bebas). Staging lokal, ikut dikirim saat Konfirmasi.
const showCustomPenunjang = ref(false)
const customPenunjang = ref({ name: '', category: '' })
function addCustomPenunjang() {
  if (!selP.value?.visitId) { toast('e', 'Pilih pasien terlebih dahulu'); return }
  const name = customPenunjang.value.name.trim()
  if (!name) { toast('w', 'Nama pemeriksaan wajib diisi'); return }
  if (penunjangOrders.value.find((x) => x.name === name)) { toast('w', `${name} sudah dipilih`); return }
  penunjangOrders.value.push({
    id: `tmp-custom-${Date.now()}`,
    code: null,   // di luar master → tidak ditarifkan otomatis; dikirim sbg teks bebas
    name,
    category: customPenunjang.value.category.trim(),
    status: 'REQUESTED',
    _persisted: false,
  })
  customPenunjang.value = { name: '', category: '' }
  showCustomPenunjang.value = false
}
function viewHasil(h) { selectedHasil.value = h; showHasilModal.value = true }
function openAttachment(url) { if (url) window.open(url, '_blank', 'noopener') }

// Konfirmasi: persist semua staging order ke backend → baris DOKTER pause + turun ke bawah.
// Order baru benar-benar ter-record sebagai DiagnosticOrder REQUESTED di sini, bukan saat klik chip.
async function confirmPenunjang() {
  if (!penunjangOrders.value.length) { showPenunjangModal.value = false; return }
  if (!store.selectedQueue) return
  const visitId = selP.value?.visitId
  if (!visitId) { toast('e', 'Pilih pasien terlebih dahulu'); return }

  const staging = penunjangOrders.value.filter((o) => !o._persisted)
  try {
    for (const o of staging) {
      // Master → kirim KODE (agar tertarif di kasir). Custom "Lainnya" → teks bebas.
      const { data } = await dokterApi.storeOrderPenunjang({ visit_id: visitId, test_type: o.code || o.name })
      // Ganti entry staging dengan hasil dari backend (id real + _persisted=true)
      const idx = penunjangOrders.value.findIndex((x) => x.id === o.id)
      if (idx !== -1) penunjangOrders.value[idx] = _mapOrder(data.data)
    }
    await store.kirimKePenunjang(store.selectedQueue.id)
    toast('s', `Pasien dikirim ke penunjang dengan ${penunjangOrders.value.length} pemeriksaan`)
    showPenunjangModal.value = false
    store.clearSelected()
    await store.fetchAntrian()
  } catch (e) {
    toast('e', e.response?.data?.message ?? e.message ?? 'Gagal mengirim ke penunjang')
  }
}

// Order pending (kunjungan aktif) di-load per visit; riwayat HASIL per pasien.
watch(() => selP.value?.visitId, loadPenunjangData, { immediate: true })
watch(() => selP.value?.patientId, loadPenunjangHistory, { immediate: true })

// Watch utk muat tarif/tindakan/resep dipindah ke bawah (setelah deklarasi
// `tindakanList` & `rxList`) agar tidak kena temporal dead zone saat
// `immediate: true` dieksekusi pada setup.

function segClass(val) {
  if (val === 'Normal') return 'seg-ok'
  if (val === 'Tidak Normal') return 'seg-warn'
  if (val === 'Tidak Dapat Dinilai') return 'seg-muted'
  return ''
}

// ── TAB 3: TINDAKAN (Master Tarif Tindakan) ─────────────────────────────────

const tarifTindakanList = ref([])  // {id, code, name, category, price} dari backend
const tindakanSearch = ref('')
const tindakanList = ref([])       // {id, code, name, category, price, qty}
const showTarifList = ref(false)
const tindakanSearchFocus = ref(false)
const tindakanSearchRef = ref(null)
function _handleTindakanClickOutside(e) {
  if (!tindakanSearchFocus.value) return
  const el = tindakanSearchRef.value
  if (el && !el.contains(e.target)) tindakanSearchFocus.value = false
}

async function loadTarifTindakan(visitId) {
  if (!visitId) { tarifTindakanList.value = []; return }
  try {
    const { data } = await dokterApi.tarifTindakan(visitId)
    tarifTindakanList.value = data.data ?? []
  } catch { tarifTindakanList.value = [] }
}

const filteredTarif = computed(() => {
  const s = tindakanSearch.value.trim().toLowerCase()
  if (!s) return []
  return tarifTindakanList.value
    .filter((t) => t.name.toLowerCase().includes(s) || (t.code ?? '').toLowerCase().includes(s))
    .slice(0, 50)
})

const tindakanSubtotal = computed(() =>
  tindakanList.value.reduce((sum, t) => sum + (Number(t.price) || 0) * t.qty, 0)
)

function fmtRp(v) { return 'Rp ' + Number(v).toLocaleString('id-ID') }

function addTindakan(t) {
  const existing = tindakanList.value.find((x) => x.id === t.id)
  if (existing) { existing.qty++; scheduleSaveTindakan(); toast('s', `Qty ${t.name} +1`); return }
  tindakanList.value.push({ id: t.id, code: t.code, name: t.name, category: t.category, price: Number(t.price) || 0, qty: 1 })
  scheduleSaveTindakan()
  toast('s', `${t.name} ditambahkan`)
}
function removeTindakan(id) {
  tindakanList.value = tindakanList.value.filter((t) => t.id !== id)
  scheduleSaveTindakan()
}
function incTindakan(t) { t.qty++; scheduleSaveTindakan() }
function decTindakan(t) { if (t.qty > 1) { t.qty--; scheduleSaveTindakan() } }

// ── TAB 3: E-RESEP ──────────────────────────────────────────────────────────

const rxList = ref([])
const obatList = ref([])           // {id, code, name, form, golongan, unit, stock, hja}
const obatSearch = ref('')
const kasirNote = ref('')          // catatan dokter untuk kasir (dipersist ke prescriptions.notes)
const pharmacyNote = ref('')       // catatan dokter untuk farmasi (dipersist ke prescriptions.pharmacy_note)
const tab3Sent = ref(false)        // sudah klik "Simpan & Kirim ke Kasir" → Tab 3 read-only
const showSendKasirModal = ref(false)
const sendingToKasir = ref(false)

async function loadObat() {
  try {
    const { data } = await dokterApi.daftarObat()
    obatList.value = data.data ?? []
  } catch { obatList.value = [] }
}

const filteredObat = computed(() => {
  const s = obatSearch.value.trim().toLowerCase()
  if (!s) return []
  return obatList.value
    .filter((d) => d.name.toLowerCase().includes(s) || (d.code ?? '').toLowerCase().includes(s))
    .slice(0, 50)
})

function pickObat(d) {
  newRx.value.medication_id = d.id
  newRx.value.name = d.name
  newRx.value.form = d.form
  newRx.value.hja  = d.hja
  obatSearch.value = ''
}
// Opsi durasi pemakaian (satuan hari — selaras dengan _parseDur → duration_days).
const DURASI_OPTS = ['3 hari', '5 hari', '7 hari', '10 hari', '14 hari', '21 hari', '28 hari', '30 hari', '60 hari', '90 hari']
// Opsi Signa (frekuensi pakai). Memuat default makeRx '2×/hari'.
const SIGNA_OPTS = ['1×/hari', '2×/hari', '3×/hari', '4×/hari', '6×/hari', 'tiap 4 jam', 'tiap 6 jam', 'tiap 8 jam', 'tiap jam', 'bila perlu (prn)', 'sebelum tidur', '1 tetes tiap 1 jam']
function makeRx() {
  return { medication_id: null, name: '', form: '', hja: 0, qty: 1, jumlah: '1 tetes', signa: '2×/hari', dur: '7 hari', posisi: 'ODS' }
}
function normalizePosisi(v) {
  const s = String(v ?? '').trim().toUpperCase()
  if (s.includes('ODS')) return 'ODS'
  if (s === 'OD' || s.endsWith(' OD')) return 'OD'
  if (s === 'OS' || s.endsWith(' OS')) return 'OS'
  return ''
}
const newRx = ref(makeRx())

function addRx() {
  if (!newRx.value.medication_id) { toast('w', 'Pilih obat dari daftar dulu'); return }
  rxList.value.push({ ...newRx.value })
  newRx.value = makeRx()
  scheduleSaveResep()
  toast('s', 'Obat ditambahkan ke resep')
}
function removeRx(idx) { rxList.value.splice(idx, 1); scheduleSaveResep() }

// ── Tab 3: muat data tersimpan + autosave (replace) ke DB ───────────────────
let _loadingResep = false   // true saat loadTindakanResep menulis kasirNote (suppress autosave)
async function loadTindakanResep() {
  const visitId = selP.value?.visitId
  // Reset state paket pemeriksaan agar tak bocor antar pasien.
  examPackageSel.value = ''
  examPackageInfo.value = null
  if (!visitId) { tindakanList.value = []; rxList.value = []; return }
  try {
    const { data } = await dokterApi.indexTindakan(visitId)
    tindakanList.value = (data.data ?? []).map((vs) => ({
      id: vs.procedure_id,
      code: vs.procedure?.code ?? '',
      name: vs.procedure?.name ?? '—',
      category: vs.procedure?.category ?? '',
      price: Number(vs.price) || 0,
      qty: vs.quantity ?? 1,
    }))
  } catch { /* biarkan */ }
  try {
    const { data } = await dokterApi.indexResep(visitId)
    const list = data.data ?? []
    const presc = list.find((p) => p.status === 'DRAFT') ?? list[0] ?? null
    // Set kasirNote/pharmacyNote dari hasil load TANPA memicu autosave (watcher di-suppress).
    _loadingResep = true
    kasirNote.value = presc?.notes ?? ''
    pharmacyNote.value = presc?.pharmacy_note ?? ''
    nextTick(() => { _loadingResep = false })
    rxList.value = (presc?.items ?? []).map((it) => ({
      medication_id: it.medication_id,
      name: it.medication?.name ?? '—',
      form: it.medication?.form_sediaan ?? '',
      hja: 0,
      qty: it.quantity ?? 1,
      jumlah: it.dose ?? '',
      signa: it.frequency ?? '',
      dur: it.duration_days ? `${it.duration_days} hari` : '',
      posisi: normalizePosisi(it.route),
    }))
  } catch { /* biarkan */ }
}

// Muat tarif tindakan + tindakan/resep tersimpan tiap kali kunjungan berganti.
// Ditaruh di sini (bukan di atas dekat watch penunjang) agar `tindakanList` &
// `rxList` sudah ter-inisialisasi saat `immediate: true` dieksekusi.
watch(() => selP.value?.visitId, (vid) => { loadTarifTindakan(vid); loadTindakanResep() }, { immediate: true })

let _saveTindakanTimer = null
function scheduleSaveTindakan() { clearTimeout(_saveTindakanTimer); _saveTindakanTimer = setTimeout(saveTindakan, 600) }
async function saveTindakan() {
  const visitId = selP.value?.visitId
  if (!visitId) return
  try {
    await dokterApi.storeTindakan(visitId, {
      services: tindakanList.value.map((t) => ({ procedure_id: t.id, quantity: t.qty, price: t.price })),
    })
  } catch (e) { toast('e', e.response?.data?.message ?? 'Gagal menyimpan tindakan') }
}

function _parseDur(s) { const m = String(s ?? '').match(/\d+/); return m ? parseInt(m[0], 10) : null }
let _saveResepTimer = null
function scheduleSaveResep() { clearTimeout(_saveResepTimer); _saveResepTimer = setTimeout(saveResep, 600) }
async function saveResep() {
  const visitId = selP.value?.visitId
  if (!visitId) return
  try {
    await dokterApi.storeResep(visitId, {
      notes: kasirNote.value?.trim() || null,
      pharmacy_note: pharmacyNote.value?.trim() || null,
      items: rxList.value.map((r) => ({
        medication_id: r.medication_id,
        quantity: Number(r.qty) || 1,
        dose: r.jumlah || null,
        frequency: r.signa || null,
        route: r.posisi || null,
        duration_days: _parseDur(r.dur),
      })),
    })
  } catch (e) { toast('e', e.response?.data?.message ?? 'Gagal menyimpan resep') }
}

// Autosave kasirNote/pharmacyNote ke prescriptions (ikut payload saveResep).
// Di-skip saat perubahan berasal dari load (prefill), bukan ketikan dokter.
watch([kasirNote, pharmacyNote], () => { if (!_loadingResep) scheduleSaveResep() })

// Tombol "Simpan & Kirim ke Kasir": flush autosave kedua endpoint lalu lock Tab 3.
// Data fisik baru masuk ke station Kasir saat doFinalize() menjalankan
// store.selesaiAntrian (advance queue) — di sini hanya komitmen UI/UX.
async function konfirmKirimKasir() {
  if (sendingToKasir.value) return
  sendingToKasir.value = true
  try {
    clearTimeout(_saveTindakanTimer)
    clearTimeout(_saveResepTimer)
    await saveTindakan()
    await saveResep()
    tab3Sent.value = true
    showSendKasirModal.value = false
    toast('s', 'Tindakan & resep tersimpan. Lanjut ke SOAP & finalisasi untuk mengirim ke kasir.')
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal menyimpan data')
  } finally {
    sendingToKasir.value = false
  }
}

// ── TAB 4: SOAP ─────────────────────────────────────────────────────────────

const soap = ref({ S: '', O: '', A: '', P: '' })
function resetSoap() {
  soap.value = { S: '', O: '', A: '', P: '' }
  // Kembalikan S/O/P ke mode auto untuk pasien berikutnya.
  soapDirty.S = false; soapDirty.O = false; soapDirty.P = false
}

// Hanya tampilkan item yang punya nilai (bukan null / '—' / string kosong).
function hasVal(v) { return v != null && v !== '—' && v !== '' }

// Objektif (O) disusun otomatis dari triase/RO + segmen abnormal + catatan slitlamp.
// Item tanpa nilai tidak dimunculkan (mis. SpO₂/T yang kosong tidak ditampilkan).
const objectiveText = computed(() => {
  if (!selP.value) return ''
  const { nd, rd } = selP.value
  const lines = []

  // Tanda vital — hanya yang terisi.
  const vitals = []
  if (hasVal(nd.td_s) && hasVal(nd.td_d)) vitals.push(`TD: ${toInt(nd.td_s)}/${toInt(nd.td_d)} mmHg`)
  if (hasVal(nd.nadi)) vitals.push(`N: ${nd.nadi} bpm`)
  if (hasVal(nd.spo2)) vitals.push(`SpO₂: ${nd.spo2}%`)
  if (hasVal(nd.suhu)) vitals.push(`T: ${nd.suhu}°C`)
  if (hasVal(nd.kgd))  vitals.push(`KGD: ${toInt(nd.kgd)} mg/dL`)
  if (vitals.length) lines.push(vitals.join(', '))

  // Visus — UCVA/BCVA, hanya baris yang punya nilai pada salah satu mata.
  const visus = []
  if (hasVal(rd.ucva_od) || hasVal(rd.ucva_os)) visus.push(`UCVA OD ${rd.ucva_od} / OS ${rd.ucva_os}`)
  if (hasVal(rd.bcva_od) || hasVal(rd.bcva_os)) visus.push(`BCVA OD ${rd.bcva_od} / OS ${rd.bcva_os}`)
  if (visus.length) lines.push(`Visus: ${visus.join(' | ')}`)

  // IOP & Rx — tampil hanya bila ada nilai.
  if (hasVal(rd.iop_od) || hasVal(rd.iop_os)) lines.push(`IOP: OD ${toInt(rd.iop_od)} / OS ${toInt(rd.iop_os)} mmHg`)
  if (hasVal(rd.rx_od) || hasVal(rd.rx_os)) lines.push(`Rx: OD ${rd.rx_od} | OS ${rd.rx_os}`)

  const segAbn = []
  const collect = (fields, group) => {
    for (const f of fields) {
      const od = group[f.key].od, os = group[f.key].os
      if ((od && od !== 'Normal') || (os && os !== 'Normal')) {
        segAbn.push(`${f.label} OD ${od || '-'}/OS ${os || '-'}`)
      }
    }
  }
  collect(saFields, exam.value.sa)
  collect(spFields, exam.value.sp)
  if (segAbn.length) lines.push(`Segmen abnormal: ${segAbn.join('; ')}`)
  const sl = exam.value.slitlamp_notes?.trim()
  if (sl) lines.push(`Slitlamp: ${sl}`)
  return lines.join('\n')
})

// S/O/P terisi & ikut auto-update dari sumbernya (anamnese / pemeriksaan / e-resep)
// SELAMA dokter belum mengetik manual di field tsb. Begitu disentuh manual, field
// jadi "milik dokter" (dirty) dan auto-sync berhenti agar editannya tidak hilang.
// Tombol "sync ulang" per field mengembalikan ke mode otomatis.
const soapDirty = reactive({ S: false, O: false, P: false })
function markSoapDirty(field) { soapDirty[field] = true }

// Planning (P) disusun otomatis dari e-resep: satu baris per obat → "Nama, Signa, Posisi".
const planningText = computed(() =>
  rxList.value
    .filter((r) => r.name)
    .map((r) => [r.name, r.signa, r.posisi].filter(Boolean).join(', '))
    .join('\n')
)

// Sinkron live: hanya menimpa bila field belum diedit manual.
watch(objectiveText, (v) => { if (!soapDirty.O) soap.value.O = v })
watch(() => exam.value.anamnese, (v) => { if (!soapDirty.S) soap.value.S = v })
watch(planningText, (v) => { if (!soapDirty.P) soap.value.P = v })

// Sync ulang per field: tarik nilai terbaru dari sumber & kembalikan ke mode auto.
function resyncSoapS() { soap.value.S = exam.value.anamnese ?? ''; soapDirty.S = false }
function resyncSoapO() { soap.value.O = objectiveText.value; soapDirty.O = false }
function resyncSoapP() { soap.value.P = planningText.value; soapDirty.P = false }

// ── TAB 4: DIAGNOSIS ICD-10 ─────────────────────────────────────────────────

const diagnosisUtama = ref(null)
const diagnosisSekunder = ref([])
const diagnosisText = ref('')   // diagnosa naratif (teks bebas) saat ragu kode ICD
const dxSearch = ref('')
const dxSearchSek = ref('')
const icd10DB = [
  { code: 'H52.1', name: 'Miopia' }, { code: 'H52.0', name: 'Hipermetropia' },
  { code: 'H52.2', name: 'Astigmatisme' }, { code: 'H52.4', name: 'Presbiopia' },
  { code: 'H25.9', name: 'Katarak senilis' }, { code: 'H26.0', name: 'Katarak juvenil' },
  { code: 'H40.1', name: 'Glaukoma sudut terbuka primer' }, { code: 'H40.2', name: 'Glaukoma sudut tertutup' },
  { code: 'H11.0', name: 'Pterigium' }, { code: 'H04.1', name: 'Dry eye syndrome' },
  { code: 'H10.0', name: 'Konjungtivitis akut' }, { code: 'H10.1', name: 'Konjungtivitis atopik' },
  { code: 'H53.0', name: 'Ambliopia ex anopsia' }, { code: 'H50.0', name: 'Esotropia konkomitan' },
  { code: 'H35.3', name: 'Degenerasi makula' }, { code: 'H33.0', name: 'Ablasio retina' },
  { code: 'H16.0', name: 'Ulkus kornea' }, { code: 'H15.0', name: 'Skleritis' },
  { code: 'H20.0', name: 'Iridosiklitis akut' }, { code: 'H27.0', name: 'Afakia' },
]
const filteredIcd10 = computed(() => {
  const s = dxSearch.value.toLowerCase()
  if (!s) return []
  return icd10DB.filter((d) => d.name.toLowerCase().includes(s) || d.code.toLowerCase().includes(s))
})
const filteredIcd10Sek = computed(() => {
  const s = dxSearchSek.value.toLowerCase()
  if (!s) return []
  return icd10DB.filter((d) => d.name.toLowerCase().includes(s) || d.code.toLowerCase().includes(s))
})
function setDxUtama(d) { diagnosisUtama.value = { ...d }; dxSearch.value = ''; toast('s', `Dx utama: ${d.code}`) }
function addDxSekunder(d) {
  if (diagnosisUtama.value?.code === d.code) { toast('w', 'Sudah menjadi dx utama'); return }
  if (diagnosisSekunder.value.find((x) => x.code === d.code)) return
  diagnosisSekunder.value.push({ ...d }); dxSearchSek.value = ''
  toast('s', `Dx sekunder: ${d.code}`)
}
function removeDxSekunder(code) {
  diagnosisSekunder.value = diagnosisSekunder.value.filter((d) => d.code !== code)
}

// ── TAB 4: ICD-9 CM ─────────────────────────────────────────────────────────

const icd9List = ref([])
const icd9Search = ref('')
const icd9DB = [
  { code: '11.73', name: 'Eksisi pterigium' }, { code: '13.41', name: 'Fakoemulsifikasi + IOL' },
  { code: '12.65', name: 'Trabekulektomi' }, { code: '10.31', name: 'Konjungtivoplasti' },
  { code: '08.86', name: 'Koreksi entropion' }, { code: '14.49', name: 'Vitrektomi' },
  { code: '16.21', name: 'Enukleasi bola mata' }, { code: '13.19', name: 'Aspirasi katarak' },
  { code: '11.53', name: 'Keratoplasti penetrating' }, { code: '14.41', name: 'Kriopeksi retina' },
]
const filteredIcd9 = computed(() => {
  const s = icd9Search.value.toLowerCase()
  if (!s) return []
  return icd9DB.filter((t) => t.name.toLowerCase().includes(s) || t.code.toLowerCase().includes(s))
})
function addIcd9(t) {
  if (icd9List.value.find((x) => x.code === t.code)) return
  icd9List.value.push({ ...t }); icd9Search.value = ''
  toast('s', `ICD-9 ${t.code} ditambahkan`)
}
function removeIcd9(code) { icd9List.value = icd9List.value.filter((t) => t.code !== code) }

// ── TAB 4: PLANNING ─────────────────────────────────────────────────────────

const planning = ref('')
const tanggalKontrol = ref('')
const surgeryLocation = ref('RUANG_BEDAH')  // lokasi pelaksanaan: RUANG_BEDAH (operasi) | RUANG_TINDAKAN (laser YAG/PRP)
const surgeryCategory = ref('')   // kategori paket bedah terpilih
const surgeryPkg = ref('')        // id paket bedah terpilih
const surgeryDate = ref('')
const surgeryTime = ref('')       // jam rencana bedah (HH:MM) — opsional
const requiresInpatient = ref(false)  // Fase 8: bedah perlu rawat inap pre-op (pasien datang H-1)
const rujukFaskes = ref('')
const rujukAlasan = ref('')
// Penanda rujukan SUDAH dibuat (lewat tombol terpisah submitRujukInternal/Keluar).
// Dipakai doFinalize untuk memperingatkan bila planning=RUJUK tapi belum ada rujukan.
// (isRujukMade computed dideklarasikan setelah isBpjsPatient & rujukMode di bawah.)
const rujukDone = ref(false)

// ── Rujuk: dua mode — INTERNAL (antar-poli) vs EXTERNAL (faskes lain) ─────────
const rujukMode = ref('EXTERNAL')          // 'INTERNAL' | 'EXTERNAL'
const internalTargets = ref([])            // [{schedule_id, doctor_name, poliklinik, is_today, day_label, start_time, ...}]
const internalTargetsLoading = ref(false)
const internalTargetId = ref('')           // schedule_id tujuan terpilih
const internalReason = ref('')
const internalSubmitting = ref(false)

async function loadInternalTargets() {
  if (!store.selectedVisitId) return
  internalTargetsLoading.value = true
  try {
    const { data } = await dokterApi.rujukInternalTargets(store.selectedVisitId)
    internalTargets.value = data.data ?? []
  } catch {
    internalTargets.value = []
  } finally {
    internalTargetsLoading.value = false
  }
}

// Tujuan dikelompokkan per poliklinik untuk <optgroup> (rapi saat banyak jadwal).
const internalTargetsByPoli = computed(() => {
  const groups = {}
  for (const t of internalTargets.value) {
    const key = t.poliklinik || 'Tanpa Poli'
    ;(groups[key] ||= []).push(t)
  }
  return Object.entries(groups).map(([poli, items]) => ({ poli, items }))
})

const internalTargetSel = computed(() =>
  internalTargets.value.find((t) => t.schedule_id === internalTargetId.value) || null
)

// Saat masuk mode internal pertama kali, muat daftar tujuan.
watch(rujukMode, (m) => { if (m === 'INTERNAL' && !internalTargets.value.length) loadInternalTargets() })

async function submitRujukInternal() {
  if (!store.selectedVisitId) { toast('e', 'Pasien belum dipilih'); return }
  if (!internalTargetId.value) { toast('e', 'Pilih poli/dokter tujuan dulu'); return }
  internalSubmitting.value = true
  try {
    const { data } = await dokterApi.rujukInternal(store.selectedVisitId, {
      target_schedule_id: internalTargetId.value,
      reason: internalReason.value || null,
    })
    const r = data.data ?? {}
    const t = r.target ?? {}
    toast('s', r.enqueued
      ? `Dirujuk ke ${t.poliklinik} (${t.doctor_name || 'dokter'}) — masuk antrean hari ini.`
      : `Rujukan ke ${t.poliklinik} dibuat. Pasien daftar ulang hari ${t.day_label} (${t.start_time}).`)
    rujukDone.value = true   // tandai rujukan sudah dibuat (cek di doFinalize)
    // Reset sub-form internal; biarkan daftar tujuan agar bisa rujuk lagi bila perlu.
    internalTargetId.value = ''
    internalReason.value = ''
  } catch (e) {
    toast('e', e.response?.data?.message || 'Gagal membuat rujukan internal')
  } finally {
    internalSubmitting.value = false
  }
}

// ── Rujuk EXTERNAL (faskes lain). Pasien BPJS → terbit ke VClaim ─────────────
const isBpjsPatient = computed(() => selP.value?.ptype === 'bpjs')

// Rujukan dianggap "dibuat" bila: tombol rujuk sukses (rujukDone), ATAU rujukan
// eksternal non-BPJS (tak punya tombol terpisah — ikut Simpan Planning) dgn faskes terisi.
const isRujukMade = computed(() =>
  rujukDone.value ||
  (rujukMode.value === 'EXTERNAL' && !isBpjsPatient.value && !!rujukFaskes.value.trim())
)
const rk = reactive({
  faskesKode: '', faskesNama: '',
  poliKode: '', poliNama: '',
  tipeRujukan: '1',   // 0 penuh / 1 partial / 2 rujuk balik
  jnsPelayanan: '2',  // 1 R.Inap / 2 R.Jalan
  diagKode: '', diagNama: '',
  catatan: '',
})
const rkSubmitting = ref(false)

// Referensi BPJS pickers (faskes & poli) — cari via integrasiApi.referensi.
const rkFaskesQ = ref(''); const rkFaskesRes = ref([]); const rkFaskesLoading = ref(false)
const rkPoliQ = ref('');   const rkPoliRes = ref([]);   const rkPoliLoading = ref(false)

async function searchFaskes() {
  const q = rkFaskesQ.value.trim()
  if (q.length < 3) { toast('e', 'Ketik minimal 3 huruf nama faskes'); return }
  rkFaskesLoading.value = true; rkFaskesRes.value = []
  try {
    const { data } = await integrasiApi.referensi('faskes', { q, jns: '2' })
    const b = data.data ?? {}
    rkFaskesRes.value = b.response?.faskes ?? b.response?.list ?? []
  } catch (e) {
    toast('e', (e.response?.status === 503 ? '⚠ Integrasi belum aktif. ' : '') + 'Gagal cari faskes')
  } finally { rkFaskesLoading.value = false }
}
function pickFaskes(f) { rk.faskesKode = f.kode; rk.faskesNama = f.nama; rkFaskesRes.value = []; rkFaskesQ.value = f.nama }

async function searchPoliRujuk() {
  const q = rkPoliQ.value.trim()
  if (q.length < 2) { toast('e', 'Ketik minimal 2 huruf nama poli'); return }
  rkPoliLoading.value = true; rkPoliRes.value = []
  try {
    const { data } = await integrasiApi.referensi('poli', { q })
    const b = data.data ?? {}
    rkPoliRes.value = b.response?.poli ?? b.response?.list ?? []
  } catch (e) {
    toast('e', (e.response?.status === 503 ? '⚠ Integrasi belum aktif. ' : '') + 'Gagal cari poli')
  } finally { rkPoliLoading.value = false }
}
function pickPoliRujuk(p) { rk.poliKode = p.kode; rk.poliNama = p.nama; rkPoliRes.value = []; rkPoliQ.value = p.nama }

// Prefill diagnosa rujukan dari diagnosis utama dokter (bisa diubah manual).
watch(diagnosisUtama, (d) => {
  if (d && !rk.diagKode) { rk.diagKode = d.code ?? ''; rk.diagNama = d.name ?? '' }
}, { immediate: true })

async function submitRujukKeluar() {
  const visitId = selP.value?.visitId
  if (!visitId) { toast('e', 'Pilih pasien dulu'); return }
  if (!rk.faskesKode) { toast('e', 'Pilih faskes tujuan dulu'); return }
  if (!rk.diagKode)   { toast('e', 'Diagnosa rujukan wajib (isi diagnosis di Tab 4)'); return }
  // BPJS: SEP wajib ada sebelum rujukan bisa terbit ke VClaim.
  if (isBpjsPatient.value && (!selP.value?.sepNo || selP.value.sepNo === '—')) {
    toast('e', 'Pasien BPJS belum punya SEP. Terbitkan SEP di Admisi dulu.'); return
  }
  rkSubmitting.value = true
  try {
    const { data } = await dokterApi.rujukanKeluar({
      visit_id: visitId,
      faskes_tujuan_kode: rk.faskesKode,
      faskes_tujuan_nama: rk.faskesNama || null,
      poli_rujukan: rk.poliKode || null,
      poli_rujukan_nama: rk.poliNama || null,
      tipe_rujukan: rk.tipeRujukan,
      jns_pelayanan: rk.jnsPelayanan,
      diagnosa_rujukan: rk.diagKode,
      diagnosa_nama: rk.diagNama || null,
      catatan_rujukan: rk.catatan || null,
    })
    const r = data.data ?? {}
    toast('s', r.status === 'SUCCESS'
      ? `Rujukan BPJS terbit. No: ${r.no_rujukan}`
      : 'Surat rujukan tersimpan.')
    rujukDone.value = true   // tandai rujukan sudah dibuat (cek di doFinalize)
  } catch (e) {
    toast('e', e.response?.data?.message || 'Gagal membuat rujukan')
  } finally { rkSubmitting.value = false }
}

// ── Surat Kontrol BPJS (planning Pulang) ────────────────────────────────────
// DRAFT dibuat backend saat finalisasi (Pulang + tgl kontrol + pasien BPJS).
// Diterbitkan OTOMATIS ke VClaim di akhir doFinalize (non-blocking). Panel di
// Tab 4 menampilkan HASIL (No. Surat Kontrol / FAILED) — info saja, read-only.
const suratKontrol = ref(null)   // { status, no_surat_kontrol, tanggal_rencana_kontrol } | null

// Dipanggil otomatis setelah finalisasi sukses. Non-blocking: kegagalan VClaim
// tidak membatalkan finalisasi (pasien sudah diteruskan), hanya diberi tahu.
async function autoSubmitSuratKontrol(visitId) {
  if (!visitId || !isBpjsPatient.value) return
  if (planning.value !== 'PULANG' || !tanggalKontrol.value) return
  try {
    const { data } = await dokterApi.submitSuratKontrol(visitId)
    suratKontrol.value = data.data ?? null
    toast('s', `Surat Kontrol BPJS terbit. No: ${suratKontrol.value?.no_surat_kontrol ?? '—'}`)
  } catch (e) {
    toast('w', 'Surat Kontrol BPJS belum terbit: ' + (e.response?.data?.message || 'gagal hubungi VClaim') + '. Bisa diterbitkan ulang dari Bridging.')
    // Tampilkan status terbaru (mungkin FAILED) untuk transparansi.
    try {
      const { data } = await dokterApi.getSuratKontrol(visitId)
      suratKontrol.value = data.data ?? null
    } catch { /* abaikan */ }
  }
}

// Slot jam bedah 07:00–17:00 per 30 menit (untuk dropdown jam).
const SURGERY_TIME_SLOTS = (() => {
  const out = []
  for (let h = 7; h <= 17; h++) {
    out.push(`${String(h).padStart(2, '0')}:00`)
    if (h !== 17) out.push(`${String(h).padStart(2, '0')}:30`)
  }
  return out
})()

// Preview jadwal bedah pada tanggal terpilih: { total, slots: [{time, room, package_name}] }.
const bedahSlotInfo = ref(null)
const bedahSlotLoading = ref(false)
// Set jam yang sudah terisi pada tanggal itu — untuk menandai slot bentrok di dropdown.
const bookedSurgeryTimes = computed(() =>
  new Set((bedahSlotInfo.value?.slots ?? []).map((s) => s.time).filter(Boolean))
)
async function loadBedahSlot(tanggal) {
  if (!tanggal) { bedahSlotInfo.value = null; return }
  bedahSlotLoading.value = true
  try {
    // Filter preview slot sesuai lokasi terpilih (Ruang Bedah vs Ruang Tindakan)
    // agar jumlah & jam terisi tidak tercampur antar-stasiun.
    const { data } = await dokterApi.bedahSlot(tanggal, surgeryLocation.value)
    bedahSlotInfo.value = data.data ?? null
  } catch {
    bedahSlotInfo.value = null
  } finally {
    bedahSlotLoading.value = false
  }
}
// Tanggal berganti → muat preview & reset jam (hindari jam nyangkut dari tanggal lain).
watch(surgeryDate, (d) => { surgeryTime.value = ''; loadBedahSlot(d) })
// Ganti lokasi → muat ulang preview (slot Ruang Bedah ≠ slot Ruang Tindakan).
watch(surgeryLocation, () => { if (surgeryDate.value) loadBedahSlot(surgeryDate.value) })

// Master paket BEDAH (surgery_packages package_type=BEDAH) — untuk Tab 4 Jadwalkan Bedah.
const surgeryPackages = ref([])   // {id, code, name, category, price}
async function loadSurgeryPackages() {
  try {
    const { data } = await masterApi.paketBedah.list({ active: 1, per_page: 200, package_type: 'BEDAH' })
    const rows = data.data?.data ?? data.data ?? []
    surgeryPackages.value = rows.map((p) => ({
      id: p.id, code: p.code ?? '', name: p.name,
      category: p.category || 'Tanpa Kategori',
      price: Number(p.price ?? p.total_base_price) || 0,
    }))
  } catch { surgeryPackages.value = [] }
}

// ── PAKET PEMERIKSAAN (poliklinik) — Tab Tindakan ──────────────────────────
// Memilih paket → tindakan paket masuk daftar (ditagih) + diskon paket di kasir.
const examPackages = ref([])      // {id, code, name}
const examPackageSel = ref('')    // id paket pemeriksaan terpilih utk kunjungan ini
const examPackageInfo = ref(null) // {package_name, sell_price, total_base_price, discount_amount}
const applyingExamPkg = ref(false)
async function loadExamPackages() {
  try {
    const { data } = await masterApi.paketBedah.list({ active: 1, per_page: 200, package_type: 'PEMERIKSAAN' })
    const rows = data.data?.data ?? data.data ?? []
    examPackages.value = rows.map((p) => ({ id: p.id, code: p.code ?? '', name: p.name }))
  } catch { examPackages.value = [] }
}
async function applyExamPackage() {
  if (!examPackageSel.value || !visitId.value || applyingExamPkg.value) return
  applyingExamPkg.value = true
  try {
    const { data } = await dokterApi.applyExaminationPackage(visitId.value, examPackageSel.value)
    const res = data.data ?? {}
    // Refresh daftar tindakan dari response (komponen paket sudah masuk).
    const svc = res.visit_services ?? []
    tindakanList.value = svc.map((vs) => ({
      id: vs.procedure_id, code: vs.procedure?.code ?? '', name: vs.procedure?.name ?? 'Tindakan',
      category: vs.procedure?.category ?? '', price: Number(vs.price) || 0, qty: vs.quantity ?? 1,
    }))
    examPackageInfo.value = res.snapshot ?? null
    toast('s', 'Paket pemeriksaan diterapkan — tindakan masuk daftar')
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal terapkan paket')
  } finally {
    applyingExamPkg.value = false
  }
}
async function removeExamPackage() {
  if (!visitId.value) return
  try {
    await dokterApi.removeExaminationPackage(visitId.value)
    examPackageSel.value = ''
    examPackageInfo.value = null
    toast('s', 'Paket pemeriksaan dilepas (tindakan tetap, hapus manual bila perlu)')
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal lepas paket')
  }
}

// Daftar kategori unik (untuk dropdown kategori), urut alfabet.
const surgeryCategories = computed(() =>
  [...new Set(surgeryPackages.value.map((p) => p.category))].sort((a, b) => a.localeCompare(b))
)
// Paket bedah yang difilter sesuai kategori terpilih.
const filteredSurgeryPackages = computed(() =>
  surgeryCategory.value
    ? surgeryPackages.value.filter((p) => p.category === surgeryCategory.value)
    : []
)
// Ganti kategori → reset pilihan paket agar tidak nyangkut paket dari kategori lain.
watch(surgeryCategory, () => { surgeryPkg.value = '' })

// ── TANDA TANGAN DIGITAL ────────────────────────────────────────────────────

// Identitas penandatangan = akun dokter yang sedang login (bukan teks statis).
const signerName = computed(() => {
  const n = auth.user?.employee?.name ?? auth.user?.name ?? ''
  if (!n) return 'Dokter'
  return /^dr\.?\s/i.test(n) ? n : `dr. ${n}`
})
const signerRole = computed(() => {
  const prof = auth.user?.employee?.profession ?? auth.user?.role?.display_name ?? auth.user?.role?.name ?? 'Dokter'
  const sip  = auth.user?.employee?.sip
  return sip ? `${prof} — SIP: ${sip}` : prof
})

const signed = ref(false)
const signTimestamp = ref(null)
const pinInput = ref('')
const pinError = ref('')
const showSignModal = ref(false)     // popup PIN tanda tangan
const pinVerifying = ref(false)

// Buka popup PIN (tombol kecil di sudut kanan).
function openSignModal() {
  pinInput.value = ''
  pinError.value = ''
  showSignModal.value = true
}
async function doSign() {
  if (pinInput.value.length < 4) { pinError.value = 'PIN minimal 4 digit'; return }
  pinVerifying.value = true
  pinError.value = ''
  try {
    await dokterApi.verifyPin(pinInput.value)
    pinInput.value = ''
    showSignModal.value = false
    signed.value = true
    signTimestamp.value = new Date().toLocaleString('id-ID')
    toast('s', 'Dokumen ditandatangani secara digital')
  } catch (e) {
    pinError.value = e?.response?.data?.message ?? 'PIN salah, coba lagi'
    pinInput.value = ''
  } finally {
    pinVerifying.value = false
  }
}

const isLocked = computed(() => signed.value || selP.value?.status === 'done')
function undoSign() {
  signed.value = false
  signTimestamp.value = null
  pinInput.value = ''
  pinError.value = ''
  showSignModal.value = false
  toast('w', 'Tanda tangan dihapus — halaman kembali terbuka')
}

// ── FINALISASI ───────────────────────────────────────────────────────────────

// Map planning UI (PULANG/BEDAH/RUJUK) → enum backend Tab 4.
const PLANNING_ENUM = { PULANG: 'PULANG_BEROBAT_JALAN', BEDAH: 'BEDAH', RAWAT_INAP: 'RAWAT_INAP', RUJUK: 'RUJUK' }

async function doFinalize() {
  if (!diagnosisUtama.value) { toast('e', 'Wajib isi diagnosa utama ICD-10'); return }
  if (!soap.value.A) { toast('e', 'Wajib isi Assessment pada SOAP'); return }
  if (!planning.value) { toast('e', 'Wajib pilih planning (Pulang / Bedah / Rujuk)'); return }
  if (planning.value === 'BEDAH') {
    // Paket WAJIB hanya untuk operasi (Ruang Bedah). Tindakan laser (Ruang Tindakan)
    // boleh tanpa paket — ditagih per-tindakan di stasiun Ruang Tindakan.
    if (surgeryLocation.value === 'RUANG_BEDAH' && !surgeryPkg.value) {
      toast('e', 'Pilih paket bedah terlebih dahulu'); return
    }
    if (!surgeryDate.value) {
      toast('e', surgeryLocation.value === 'RUANG_TINDAKAN' ? 'Isi tanggal rencana tindakan' : 'Isi tanggal rencana bedah'); return
    }
  }
  if (!signed.value) { toast('e', 'Wajib tandatangani dokumen terlebih dahulu'); return }
  if (!store.selectedQueue) { toast('e', 'Tidak ada antrian aktif'); return }
  const visitId = selP.value?.visitId
  if (!visitId) { toast('e', 'Kunjungan tidak ditemukan'); return }

  // Planning RUJUK tapi rujukan belum dibuat: rujukan dibuat lewat tombol terpisah
  // ("Buat Rujukan ke Poli Ini" / "Terbitkan Rujukan BPJS"). Jika dilewati, pasien
  // langsung di-advance ke KASIR tanpa surat rujukan. Peringatkan — boleh lanjut.
  if (planning.value === 'RUJUK' && !isRujukMade.value) {
    const lanjut = window.confirm(
      'Planning RUJUK tapi rujukan belum dibuat.\n\n' +
      'Rujukan internal/BPJS dibuat lewat tombol terpisah di panel Rujuk. ' +
      'Bila dilanjutkan, pasien diteruskan ke kasir TANPA surat rujukan.\n\n' +
      'Tetap finalisasi?'
    )
    if (!lanjut) return
  }

  finalizing.value = true
  try {
    // 0) Flush autosave Tab 3 (tindakan & resep) yang masih dalam debounce 600ms.
    //    storeVisitServices = replace (hapus lalu re-create); bila POST telat fire
    //    SETELAH finalize/advance, backend menolak (assertNotFinalized 422) dan
    //    tindakan/resep terakhir hilang dari tagihan kasir (under-billing).
    clearTimeout(_saveTindakanTimer)
    clearTimeout(_saveResepTimer)
    await saveTindakan()
    await saveResep()

    // 1) Simpan Tab 2 (anamnese + segmen anterior/posterior + slitlamp) lebih dulu,
    //    karena POST tab2 menolak bila record sudah ada (storeTab4 pakai firstOrCreate).
    await saveTab2()

    // 2) Simpan Tab 4 (SOAP + diagnosis + planning + paket/tanggal bedah).
    //    Backend membuat/memperbarui SurgerySchedule saat planning BEDAH, sehingga
    //    QueueService dapat merutekan ke BEDAH bila tanggal operasi = hari ini.
    await dokterApi.storeTab4(visitId, {
      soap_subjective:    soap.value.S || null,
      soap_objective:     soap.value.O || null,
      soap_assessment:    soap.value.A || null,
      soap_plan:          soap.value.P || null,
      diagnosis_utama:    diagnosisUtama.value.code,
      diagnosis_sekunder: diagnosisSekunder.value.map((d) => d.code),
      diagnosis_text:     diagnosisText.value?.trim() || null,
      tindakan_codes:     icd9List.value.map((t) => t.code),
      planning:           PLANNING_ENUM[planning.value] ?? planning.value,
      surgery_package_id: planning.value === 'BEDAH' ? (surgeryPkg.value || null) : null,
      // Lokasi pelaksanaan: RUANG_BEDAH (operasi) | RUANG_TINDAKAN (laser YAG/PRP).
      location_type:      planning.value === 'BEDAH' ? surgeryLocation.value : null,
      surgery_date:       planning.value === 'BEDAH' ? surgeryDate.value : null,
      surgery_time:       planning.value === 'BEDAH' ? (surgeryTime.value || null) : null,
      // Fase 8: bedah yang butuh inap (pre-op H-1). Hanya relevan saat planning BEDAH di RUANG_BEDAH.
      requires_inpatient: planning.value === 'BEDAH' && surgeryLocation.value === 'RUANG_BEDAH' ? requiresInpatient.value : false,
      // Rujukan eksternal non-BPJS (faskes lain). BPJS dirujuk lewat VClaim (submitRujukKeluar),
      // jadi field ini hanya diisi untuk pasien non-BPJS saat planning RUJUK.
      external_referral_facility: planning.value === 'RUJUK' && !isBpjsPatient.value ? (rujukFaskes.value || null) : null,
      external_referral_reason:   planning.value === 'RUJUK' && !isBpjsPatient.value ? (rujukAlasan.value || null) : null,
      follow_up_date:     planning.value === 'PULANG' && tanggalKontrol.value ? tanggalKontrol.value : null,
    })

    // 3) Kunci pemeriksaan (is_finalized = true).
    await dokterApi.finalize(visitId)

    // 4) Advance queue ke station berikutnya (PENUNJANG / BEDAH / KASIR — backend).
    await store.selesaiAntrian(store.selectedQueue.id)

    finalized.value = true
    qFilter.value = 'done'
    toast('s',
      planning.value === 'BEDAH'
        ? (requiresInpatient.value
            ? 'RME difinalisasi — jadwal bedah dibuat (pre-op rawat inap, pasien datang H-1)'
            : 'RME difinalisasi — jadwal bedah dibuat & pasien diteruskan')
        : planning.value === 'RAWAT_INAP'
          ? 'RME difinalisasi — pasien masuk papan Menunggu Kamar (rawat inap)'
          : 'RME difinalisasi — pasien dikirim ke station berikutnya')

    // Surat Kontrol BPJS: terbit otomatis bila Pulang + tgl kontrol + pasien BPJS.
    // Non-blocking — finalisasi di atas sudah final, ini hanya menyusulkan ke VClaim.
    await autoSubmitSuratKontrol(visitId)

    // Resume Medis: auto-generate dari data kunjungan yang baru saja dikunci, lalu
    // tampilkan modal preview agar dokter bisa tinjau/edit & setujui (terbit). Non-
    // blocking — finalisasi RME sudah final; kegagalan generate hanya diberi tahu.
    await openResumePreview(visitId)
    // Pasien sengaja TIDAK di-clear: agar dokter masih bisa melihat data tindakan &
    // resep (read-only) setelah finalisasi. `isLocked` sudah menutup edit (signed=true
    // + status='done' membuat seluruh panel pane-locked).
  } catch (err) {
    toast('e', err.response?.data?.message ?? err.message ?? 'Gagal menyelesaikan RME')
  } finally {
    finalizing.value = false
  }
}

// ── Resume Medis Rawat Jalan (RM 1.7/RMRJ/22): preview → (edit) → Setuju → terbit ─
// Dipanggil otomatis di akhir doFinalize (RME sudah terkunci). Resume di-generate
// (field formulir auto-isi dari data kunjungan), ditinjau/diedit dokter di modal,
// lalu "Setuju & Terbitkan" menyimpan editan + finalize (is_finalized=true).
const showResumeModal = ref(false)
const resumeData = ref(null)         // objek MedicalResume mentah (termasuk rmrj_data)
const resumeGenerating = ref(false)  // sedang generate (buka modal)
const resumeApproving = ref(false)   // sedang simpan+finalize (klik Setuju)

// Field naratif yang DIEDIT dokter di modal (header/footer cetak tetap di rmrj_data).
const RESUME_FIELDS = [
  { key: 'anamnese',             label: 'Anamnese',                                      rows: 2 },
  { key: 'pemeriksaan_fisik',    label: 'Pemeriksaan Fisik',                             rows: 3 },
  { key: 'alergi_obat',          label: 'Alergi Obat',                                   rows: 1 },
  { key: 'hasil_penunjang',      label: 'Hasil Penunjang Medis (Lab/Radiologi/dll)',     rows: 2 },
  { key: 'diagnosa',             label: 'Diagnosa',                                      rows: 2 },
  { key: 'tindakan',             label: 'Tindakan',                                      rows: 2 },
  { key: 'terapi',               label: 'Terapi',                                        rows: 3 },
  { key: 'riwayat_inap_operasi', label: 'Riwayat/Rawat Inap/Operasi/Tindakan',           rows: 2 },
  { key: 'instruksi_edukasi',    label: 'Instruksi/Anjuran dan Edukasi Lanjutan',        rows: 2 },
]
const resumeForm = reactive(Object.fromEntries(RESUME_FIELDS.map((f) => [f.key, ''])))
const resumeKontrol = reactive({ kontrol_tanggal: '', kontrol_tempat: '' })

function hydrateResumeForm(r) {
  const d = r?.rmrj_data ?? {}
  for (const f of RESUME_FIELDS) resumeForm[f.key] = d[f.key] ?? ''
  resumeKontrol.kontrol_tanggal = d.kontrol_tanggal ?? ''
  resumeKontrol.kontrol_tempat  = d.kontrol_tempat ?? ''
}

// Header/footer cetak (read-only di modal — auto dari kunjungan).
const resumeHeader = computed(() => resumeData.value?.rmrj_data ?? {})

async function openResumePreview(visitId) {
  resumeGenerating.value = true
  try {
    // Auto-generate (updateOrCreate di backend) lalu tampilkan untuk ditinjau.
    const { data } = await dokterApi.generateResumeMedis(visitId)
    const r = data.data ?? data
    if (!r?.id) { toast('e', 'Gagal menyiapkan resume medis'); return }
    // Resume bisa saja sudah difinalisasi (kunjungan diproses ulang) — skip modal.
    if (r.is_finalized) return
    resumeData.value = r
    hydrateResumeForm(r)
    showResumeModal.value = true
  } catch (e) {
    // Non-blocking: finalisasi RME sudah final. Dokter tetap bisa terbitkan resume
    // lewat tombol Resume di antrean (FormDocsBrowser) bila modal gagal muncul.
    toast('e', e.response?.data?.message ?? 'Resume medis belum bisa disiapkan (bisa diterbitkan manual lewat tombol Resume).')
  } finally {
    resumeGenerating.value = false
  }
}

async function approveResume() {
  const r = resumeData.value
  if (!r?.id) { showResumeModal.value = false; return }
  resumeApproving.value = true
  try {
    // Selalu kirim field yang diedit dokter (merge dgn header/footer di backend).
    const rmrj = {}
    for (const f of RESUME_FIELDS) rmrj[f.key] = resumeForm[f.key] || ''
    rmrj.kontrol_tanggal = resumeKontrol.kontrol_tanggal || ''
    rmrj.kontrol_tempat  = resumeKontrol.kontrol_tempat || ''
    await dokterApi.updateResumeMedis(r.id, { rmrj_data: rmrj })

    // Finalisasi resume → is_finalized=true, is_editable=false (terbit/dikunci).
    await dokterApi.finalizeResumeMedis(r.id)
    showResumeModal.value = false
    resumeData.value = null
    toast('s', 'Resume medis pasien diterbitkan.')
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal menerbitkan resume medis')
  } finally {
    resumeApproving.value = false
  }
}

// Lewati persetujuan: resume tetap tersimpan sebagai DRAFT (is_finalized=false),
// dokter bisa terbitkan nanti lewat tombol Resume. Tidak menghapus apa pun.
function skipResume() {
  showResumeModal.value = false
  resumeData.value = null
  toast('i', 'Resume disimpan sebagai draf — bisa diterbitkan nanti lewat tombol Resume.')
}
</script>

<template>
  <div class="dokter">
    <!-- LEFT: QUEUE PANEL -->
    <aside class="qp">

      <!-- ═══ Status Saya Hari Ini ═══ -->
      <div v-if="myEmployeeId" class="card status-card">
        <div class="status-head">
          <div class="status-head-title">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Status Saya Hari Ini
          </div>
          <RouterLink to="/jadwal-dokter" class="status-link" title="Kelola jadwal mingguan">Atur Jadwal</RouterLink>
        </div>

        <div v-if="!mySchedulesToday.length" class="status-empty">
          Belum ada jadwal untuk hari {{ DAY_LABELS[todayIso()] }}.
        </div>

        <div v-else class="status-list">
          <div
            v-for="j in mySchedulesToday" :key="j.id"
            :class="['status-row', j.is_active ? 'on' : 'off']"
          >
            <div class="sr-main">
              <div class="sr-line1">
                <span class="sr-prefix">D{{ j.room || '?' }}</span>
                <span class="sr-jam">{{ j.start_time?.slice(0,5) }}–{{ j.end_time?.slice(0,5) }}</span>
                <span :class="['sr-dot', j.is_active ? 'on' : 'off']" :title="j.is_active ? 'Aktif' : 'Nonaktif'"></span>
              </div>
              <div class="sr-line2">
                <span v-if="j.poliklinik">{{ j.poliklinik }}</span>
                <span v-if="j.poliklinik && j.room"> · </span>
                <span v-if="j.room">Ruang {{ j.room }}</span>
              </div>
            </div>

            <div class="sr-actions">
              <!-- Edit ruangan inline -->
              <template v-if="myRoomEdit[j.id] !== undefined">
                <input
                  v-model="myRoomEdit[j.id]"
                  class="sr-room-input"
                  type="text"
                  maxlength="3"
                  placeholder="Ruang"
                  @keydown.enter="saveRoom(j)"
                  @keydown.escape="cancelEditRoom(j.id)"
                />
                <button class="sr-btn save" @click="saveRoom(j)" title="Simpan">
                  <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                </button>
                <button class="sr-btn cancel" @click="cancelEditRoom(j.id)" title="Batal">
                  <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </template>
              <template v-else>
                <button class="sr-btn edit" @click="startEditRoom(j)" title="Ubah ruangan">
                  <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <label class="sr-switch" :title="j.is_active ? 'Klik untuk nonaktifkan' : 'Klik untuk aktifkan'">
                  <input type="checkbox" :checked="j.is_active" @change="toggleMyAktif(j)" />
                  <span class="sr-slider"></span>
                </label>
              </template>
            </div>
          </div>
        </div>
      </div>

      <div class="card qp-card">

      <!-- FIXED: header -->
      <div class="card-head">
        <div>
          <div class="card-head-title">
            <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Antrean Dokter
          </div>
          <div class="card-head-sub">{{ patients.length }} pasien hari ini</div>
        </div>
        <span class="pill-live">LIVE</span>
      </div>

      <div class="card-body queue-scroll" role="region" aria-label="Daftar antrean dokter">

        <!-- Stats bar -->
        <div class="stats-bar">
          <div class="stat-item">
            <span class="stat-label">Menunggu</span>
            <b class="stat-num stat-waiting">{{ cWait }}</b>
          </div>
          <div class="stat-divider"></div>
          <div class="stat-item">
            <span class="stat-label">Selesai</span>
            <b class="stat-num stat-done">{{ cDone }}</b>
          </div>
          <div class="stat-divider"></div>
          <div class="stat-item">
            <span class="stat-label">Total</span>
            <b class="stat-num">{{ patients.length }}</b>
          </div>
        </div>

        <!-- Primary filter -->
        <div class="primary-filter">
          <button :class="['pf-btn', qFilter !== 'done' ? 'a' : '']" @click="qFilter = 'waiting'">
            Belum Selesai
            <span v-if="cWait" class="pf-ct">{{ cWait }}</span>
          </button>
          <button :class="['pf-btn', qFilter === 'done' ? 'a' : '']" @click="qFilter = 'done'">
            Selesai
            <span v-if="cDone" class="pf-ct">{{ cDone }}</span>
          </button>
        </div>

        <!-- Sub-tab jenis penjamin -->
        <div class="ptype-tabs" role="group" aria-label="Filter jenis penjamin">
          <button :class="['ptype-tab', ptypeFilter === 'Semua' ? 'a' : '']" @click="ptypeFilter = 'Semua'">Semua</button>
          <button :class="['ptype-tab ptype-bpjs', ptypeFilter === 'BPJS' ? 'a' : '']" @click="ptypeFilter = 'BPJS'">
            BPJS<span v-if="bpjsCount" class="ptype-ct">{{ bpjsCount }}</span>
          </button>
          <button :class="['ptype-tab ptype-umum', ptypeFilter === 'UmumAsn' ? 'a' : '']" @click="ptypeFilter = 'UmumAsn'">
            Umum &amp; Asuransi<span v-if="umumAsnCount" class="ptype-ct">{{ umumAsnCount }}</span>
          </button>
        </div>

        <!-- Search -->
        <div class="q-search-wrap">
          <input v-model="qSearch" class="q-search" placeholder="Cari nama / no. antrean…" />
        </div>

        <div v-if="!filtQ.length" class="empty-section" aria-live="polite">Tidak ada pasien dalam filter ini</div>

        <div v-else role="list" aria-label="Daftar antrean dokter">
          <div
            v-for="p in filtQ" :key="p.id"
            role="listitem"
            :class="['q-item', selP?.id === p.id ? 'active' : '', (p.status === 'done' || p.status === 'skip') ? 'done' : '']"
            tabindex="0"
            @click="pickPt(p)"
            @keydown.enter="pickPt(p)"
          >
            <div class="qi-left">
              <div class="q-num">{{ p.qNum }}</div>
              <span :class="['pill', statusPillClass(p.status)]">
                {{ statusLabel(p.status) }}
              </span>
            </div>
            <div class="q-info">
              <div class="q-name">{{ p.name }}</div>
              <div class="q-meta">{{ p.age }} th · {{ p.gender }} · {{ p.poli }}</div>
              <div class="q-tags">
                <span :class="['pill', p.ptype === 'bpjs' ? 'pill-bpjs' : p.ptype === 'asn' ? 'pill-asn' : 'pill-umum']">
                  {{ p.ptype === 'bpjs' ? 'BPJS' : p.ptype === 'asn' ? 'Asuransi' : 'Umum' }}
                </span>
                <span v-if="p.hasNurse" class="pill pill-done">
                  <svg viewBox="0 0 24 24" class="pill-icon"><polyline points="20 6 9 17 4 12"/></svg>
                  Triase
                </span>
                <span v-if="p.hasRO" class="pill pill-ro">
                  <svg viewBox="0 0 24 24" class="pill-icon"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/></svg>
                  RO
                </span>
                <span v-if="p.allergies?.length" class="pill pill-allergy">⚠ Alergi</span>
              </div>
              <!-- Pasien sedang di penunjang — tidak ada aksi panggil -->
              <div v-if="p.status === 'penunjang'" class="q-actions" @click.stop>
                <span class="q-at-penunjang">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
                  Sedang di Penunjang
                </span>
              </div>
              <div v-else-if="p.status !== 'done' && p.status !== 'skip'" class="q-actions" @click.stop>
                <button
                  :class="['q-act-btn',
                    p.status === 'penunjang_done' ? 'resume'
                      : p.rawStatus !== 'WAITING' ? 'call recall'
                      : 'call']"
                  :disabled="pendingCallIds.includes(p.id)"
                  @click.stop="callPt(p)"
                >
                  <svg v-if="p.rawStatus === 'WAITING' || p.status === 'penunjang_done'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 014.69 12a19.79 19.79 0 01-3.07-8.67A2 2 0 013.6 1.27h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L7.91 8.91a16 16 0 006.18 6.18l.96-.96a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                  <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
                  {{ p.status === 'penunjang_done' ? 'Lanjutkan'
                     : p.rawStatus !== 'WAITING' ? 'Panggil Ulang'
                     : 'Panggil' }}
                </button>
                <button class="q-act-btn skip" :disabled="pendingSkipIds.includes(p.id)" @click.stop="skipPt(p)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="7 13 12 18 17 13"/><polyline points="7 6 12 11 17 6"/></svg>
                  {{ pendingSkipIds.includes(p.id) ? 'Melewati…' : 'Lewati' }}
                </button>
              </div>
            </div>

            <div class="qi-time">{{ p.time }}</div>
          </div>
        </div>

      </div>
      </div><!-- end qp-card -->
    </aside>

    <!-- RIGHT: RME AREA -->
    <section class="rme">

      <!-- Identitas dokter dipindah ke topbar global (AppTopbar) — tanpa card -->

      <div class="rme-card">

      <div v-if="!selP" class="empty">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        <p>Pilih atau panggil pasien dari antrean<br />untuk membuka Rekam Medis Elektronik</p>
      </div>

      <template v-if="selP">
        <!-- PATIENT BAR -->
        <div class="ptb">
          <PatientAvatar :name="selP.name" :src="selP.photo" :size="42" radius="50%" />
          <div class="pti">
            <div class="ptn">{{ selP.name }}</div>
            <div class="ptm">RM: {{ selP.rm }} · NIK: {{ selP.nik }} · {{ selP.age }} th · {{ selP.gender === 'L' ? 'Laki-laki' : 'Perempuan' }}</div>
            <div v-if="selP.address" class="pt-address">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
              {{ selP.address }}
            </div>
            <div class="ptags">
              <span
                v-if="selP.classification === 'Rujukan Internal'"
                class="ptg ptg-rujuk"
                :title="selP.internalRefFrom ? 'Dirujuk dari ' + selP.internalRefFrom : 'Rujukan internal antar-poli'"
              >↪ Rujukan{{ selP.internalRefFrom ? ' dari ' + selP.internalRefFrom : '' }}</span>
              <span v-else-if="selP.classification" :class="['ptg', clsCls(selP.classification)]">{{ selP.classification }}</span>
              <span v-if="selP.ptype === 'bpjs'" class="ptg ptg-b">BPJS · SEP {{ selP.sepNo }}</span>
              <span v-if="selP.allergies.length" class="ptg ptg-a">⚠ Alergi: {{ selP.allergies.join(', ') }}</span>
            </div>
          </div>
          <div class="vit">
            <div class="vdv"></div>
            <div class="vi">
              <div :class="['viv', Number(selP.nd.td_s) >= 140 ? 'w' : '']">{{ toInt(selP.nd.td_s) }}/{{ toInt(selP.nd.td_d) }}</div>
              <div class="vil">TD</div>
            </div>
            <div class="vi">
              <div :class="['viv', selP.nd.kgd > 200 ? 'w' : selP.nd.kgd < 70 ? 'lo' : '']">{{ toInt(selP.nd.kgd) }}</div>
              <div class="vil">KGD</div>
            </div>
            <div class="vdv"></div>
            <div class="vi">
              <div class="vi-eyes"><span>OD</span><span>OS</span></div>
              <div :class="['viv', selP.rd.iop_od >= 22 || selP.rd.iop_os >= 22 ? 'w' : '']">{{ toInt(selP.rd.iop_od) }}/{{ toInt(selP.rd.iop_os) }}</div>
              <div class="vil">IOP</div>
            </div>
            <div class="vi">
              <div class="vi-eyes"><span>OD</span><span>OS</span></div>
              <div class="viv small viv-pair"><span>{{ selP.rd.ucva_od }}</span><span>{{ selP.rd.ucva_os }}</span></div>
              <div class="vil">UCVA</div>
            </div>
            <div class="vi">
              <div class="vi-eyes"><span>OD</span><span>OS</span></div>
              <div class="viv small viv-pair"><span>{{ selP.rd.bcva_od }}</span><span>{{ selP.rd.bcva_os }}</span></div>
              <div class="vil">BCVA</div>
            </div>
            <div class="vdv"></div>
          </div>
          <div class="dbtns">
            <button :class="['db', 'db-n', dw === 'nurse' ? 'act' : '']" @click="dw = dw === 'nurse' ? null : 'nurse'">▼ Triase</button>
            <button :class="['db', 'db-r', dw === 'ro' ? 'act' : '']" @click="dw = dw === 'ro' ? null : 'ro'">▼ RO</button>
            <button :class="['db', 'db-h', dw === 'hist' ? 'act' : '']" @click="dw = dw === 'hist' ? null : 'hist'">▼ Riwayat</button>
            <button
              v-if="store.selectedVisitId"
              class="db db-doc"
              title="Resume, surat & consent — isi, cetak, atau tanda tangani"
              @click="showFormDocsModal = true"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
              Dokumen RM
            </button>
          </div>
        </div>

        <!-- DRAWER -->
        <transition name="dwr">
          <div v-if="dw" class="dwr">
            <div v-if="dw === 'nurse'" class="dwc">
              <div class="dwt n">
                <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/></svg>
                Triase Perawat
              </div>
              <div class="dw-grid">
                <div class="dwr2"><span class="dwl">TD</span><span :class="['dwv', selP.nd.td_s >= 140 ? 'w' : 'ok']">{{ toInt(selP.nd.td_s) }}/{{ toInt(selP.nd.td_d) }} mmHg</span></div>
                <div class="dwr2"><span class="dwl">Nadi</span><span class="dwv">{{ toInt(selP.nd.nadi) }} bpm</span></div>
                <div class="dwr2"><span class="dwl">SpO₂</span><span :class="['dwv', selP.nd.spo2 < 95 ? 'hi' : 'ok']">{{ toInt(selP.nd.spo2) }}%</span></div>
                <div class="dwr2"><span class="dwl">Suhu</span><span class="dwv">{{ toInt(selP.nd.suhu) }}°C</span></div>
                <div class="dwr2"><span class="dwl">Nyeri</span><span class="dwv">{{ toInt(selP.nd.pain) }}/10</span></div>
                <div class="dwr2"><span class="dwl">KGD</span><span :class="['dwv', selP.nd.kgd > 200 ? 'hi' : selP.nd.kgd > 140 ? 'w' : 'ok']">{{ toInt(selP.nd.kgd) }} mg/dL</span></div>
              </div>
              <div class="dw-keluhan"><b>Keluhan:</b> {{ selP.nd.keluhan }}</div>
            </div>
            <div v-if="dw === 'ro'" class="dwc">
              <div class="dwt r">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/></svg>
                Data Refraksionis
              </div>
              <div class="rog">
                <div class="roi"><div class="roi-l">UCVA OD</div><div class="roi-v">{{ selP.rd.ucva_od }}</div></div>
                <div class="roi"><div class="roi-l">UCVA OS</div><div class="roi-v">{{ selP.rd.ucva_os }}</div></div>
                <div class="roi"><div class="roi-l">BCVA OD</div><div class="roi-v">{{ selP.rd.bcva_od }}</div></div>
                <div class="roi"><div class="roi-l">BCVA OS</div><div class="roi-v">{{ selP.rd.bcva_os }}</div></div>
                <div class="roi"><div class="roi-l">IOP OD</div><div class="roi-v" :class="{ warn: selP.rd.iop_od >= 22 }">{{ toInt(selP.rd.iop_od) }} mmHg</div></div>
                <div class="roi"><div class="roi-l">IOP OS</div><div class="roi-v" :class="{ warn: selP.rd.iop_os >= 22 }">{{ toInt(selP.rd.iop_os) }} mmHg</div></div>
              </div>
              <div class="rx-line"><b>Rx OD:</b> {{ selP.rd.rx_od }}</div>
              <div class="rx-line"><b>Rx OS:</b> {{ selP.rd.rx_os }}</div>
              <div v-if="selP.rd.note" class="ro-note">{{ selP.rd.note }}</div>
            </div>
            <div v-if="dw === 'hist'" class="dwc">
              <div class="dwt">
                <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>
                Riwayat Kunjungan
              </div>
              <div v-for="h in selP.history" :key="h.date" class="histi">
                <div class="hd">{{ h.date }} · {{ h.doctor }}</div>
                <div class="hdx">{{ h.dx }}</div>
                <div class="hdet">{{ h.detail }}</div>
              </div>
              <div v-if="!selP.history.length" class="hist-empty">Pasien baru — belum ada riwayat</div>
            </div>
          </div>
        </transition>

        <!-- TABS -->
        <div class="rmtabs">
          <button :class="['rmt', tab === 'data' ? 'a' : '']" @click="tab = 'data'">
            <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Data Pasien
          </button>
          <button :class="['rmt', tab === 'pemeriksaan' ? 'a' : '']" @click="tab = 'pemeriksaan'">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
            Pemeriksaan Mata
          </button>
          <button :class="['rmt', tab === 'tindakan' ? 'a' : '']" @click="tab = 'tindakan'">
            <svg viewBox="0 0 24 24"><path d="M3 3h18v18H3zM3 9h18M9 21V9"/></svg>
            Tindakan &amp; Resep
            <span v-if="tindakanList.length || rxList.length" class="rmt-count">{{ tindakanList.length + rxList.length }}</span>
          </button>
          <button :class="['rmt', tab === 'soap' ? 'a' : '']" @click="tab = 'soap'">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            SOAP &amp; Diagnosis
            <span v-if="!diagnosisUtama" class="rmtd"></span>
          </button>
        </div>

        <!-- CONTENT -->
        <div class="rmc">

          <!-- ═══ TAB 1: DATA PASIEN (Read-only) ═══════════════════════════ -->
          <div v-if="tab === 'data'" class="af">
            <div class="readonly-banner">
              <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
              Data hanya-baca — diisi oleh Perawat &amp; Refraksionis
            </div>

            <div class="card">
              <div class="ch">
                <div class="cht">
                  <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/></svg>
                  Data Triase Perawat
                </div>
                <span class="ro-badge">Read-only</span>
              </div>
              <div class="cb">
                <table class="tr-table">
                  <thead>
                    <tr>
                      <th>TD <small>mmHg</small></th>
                      <th>Nadi <small>bpm</small></th>
                      <th>SpO₂ <small>%</small></th>
                      <th>Suhu <small>°C</small></th>
                      <th>KGD <small>mg/dL</small></th>
                      <th>Nyeri</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td :class="{ warn: selP.nd.td_s >= 140 }">{{ selP.nd.td_s }}/{{ selP.nd.td_d }}</td>
                      <td>{{ selP.nd.nadi }}</td>
                      <td :class="{ warn: selP.nd.spo2 < 95 }">{{ selP.nd.spo2 }}</td>
                      <td>{{ selP.nd.suhu }}</td>
                      <td :class="{ warn: selP.nd.kgd > 200 }">{{ toInt(selP.nd.kgd) }}</td>
                      <td>{{ selP.nd.pain }}<small>/10</small></td>
                    </tr>
                  </tbody>
                </table>
                <div class="tr-keluhan"><b>Keluhan Utama:</b> {{ selP.nd.keluhan }}</div>
              </div>
            </div>

            <div class="card">
              <div class="ch">
                <div class="cht">
                  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/></svg>
                  Data Refraksionis
                </div>
                <span class="ro-badge">Read-only</span>
              </div>
              <div class="cb">
                <table class="ro-table">
                  <thead>
                    <tr>
                      <th class="rt-param">Parameter</th>
                      <th><span class="rt-eye el-od">OD</span> <span class="rt-side">Kanan</span></th>
                      <th><span class="rt-eye el-os">OS</span> <span class="rt-side">Kiri</span></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="row in roRows" :key="row.label">
                      <td class="rt-param">{{ row.label }}<small v-if="row.note"> · {{ row.note }}</small></td>
                      <td :class="[row.cls, row.odWarn && 'warn']">{{ row.od }}<small v-if="row.unit"> {{ row.unit }}</small></td>
                      <td :class="[row.cls, row.osWarn && 'warn']">{{ row.os }}<small v-if="row.unit"> {{ row.unit }}</small></td>
                    </tr>
                  </tbody>
                </table>

                <div v-if="selP.rd.pd || selP.rd.perception" class="ro-foot">
                  <span v-if="selP.rd.pd">PD <b>{{ toInt(selP.rd.pd) }} mm</b></span>
                  <span v-if="selP.rd.perception">Persepsi <b>{{ selP.rd.perception }}</b></span>
                </div>
                <div v-if="selP.rd.note" class="ro-note"><b>Catatan RO:</b> {{ selP.rd.note }}</div>
              </div>
            </div>

            <button class="btn btn-primary" @click="tab = 'pemeriksaan'">
              Lanjut ke Pemeriksaan Mata
              <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
          </div>

          <!-- ═══ TAB 2: PEMERIKSAAN MATA ═══════════════════════════════════ -->
          <div v-if="tab === 'pemeriksaan'" :class="['pem-grid', isLocked ? 'pane-locked' : '']">

            <!-- ── KIRI: FORM ── -->
            <div class="pem-main">

              <!-- Anamnese -->
              <div class="card">
                <div class="ch">
                  <div class="cht">
                    <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                    Anamnese
                  </div>
                </div>
                <div class="cb">
                  <div class="fg">
                    <label class="fl">Anamnese Pasien</label>
                    <textarea v-model="exam.anamnese" class="form-textarea" rows="4"
                      placeholder="Keluhan utama, lama keluhan, riwayat penyakit dahulu, riwayat keluarga, riwayat pengobatan..."></textarea>
                  </div>
                </div>
              </div>

              <!-- Segmen Anterior & Posterior — 2 kolom agar ringkas -->
              <div class="seg-2col">
              <!-- Segmen Anterior -->
              <div class="card">
                <div class="ch">
                  <div class="cht">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                    Segmen Anterior
                  </div>
                  <span class="card-hint">Normal / Tidak Normal / Tidak Dapat Dinilai</span>
                </div>
                <div class="cb">
                  <div class="seg-table">
                    <div class="seg-head">
                      <div></div>
                      <div class="seg-eye-lbl od"><span class="elbl el-od">OD</span> Mata Kanan</div>
                      <div class="seg-eye-lbl os"><span class="elbl el-os">OS</span> Mata Kiri</div>
                    </div>
                    <div v-for="f in saFields" :key="f.key" class="seg-row">
                      <div class="seg-label">{{ f.label }}</div>
                      <select v-model="exam.sa[f.key].od" :class="['form-select', segClass(exam.sa[f.key].od)]">
                        <option value="">— Pilih —</option>
                        <option v-for="o in segmenOpts" :key="o" :value="o">{{ o }}</option>
                      </select>
                      <select v-model="exam.sa[f.key].os" :class="['form-select', segClass(exam.sa[f.key].os)]">
                        <option value="">— Pilih —</option>
                        <option v-for="o in segmenOpts" :key="o" :value="o">{{ o }}</option>
                      </select>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Segmen Posterior -->
              <div class="card">
                <div class="ch">
                  <div class="cht">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 12s1-3 4-3 4 3 4 3-1 3-4 3-4-3-4-3z"/></svg>
                    Segmen Posterior
                  </div>
                  <span class="card-hint">Normal / Tidak Normal / Tidak Dapat Dinilai</span>
                </div>
                <div class="cb">
                  <div class="seg-table">
                    <div class="seg-head">
                      <div></div>
                      <div class="seg-eye-lbl od"><span class="elbl el-od">OD</span> Mata Kanan</div>
                      <div class="seg-eye-lbl os"><span class="elbl el-os">OS</span> Mata Kiri</div>
                    </div>
                    <div v-for="f in spFields" :key="f.key" class="seg-row">
                      <div class="seg-label">{{ f.label }}</div>
                      <select v-model="exam.sp[f.key].od" :class="['form-select', segClass(exam.sp[f.key].od)]">
                        <option value="">— Pilih —</option>
                        <option v-for="o in segmenOpts" :key="o" :value="o">{{ o }}</option>
                      </select>
                      <select v-model="exam.sp[f.key].os" :class="['form-select', segClass(exam.sp[f.key].os)]">
                        <option value="">— Pilih —</option>
                        <option v-for="o in segmenOpts" :key="o" :value="o">{{ o }}</option>
                      </select>
                    </div>
                  </div>
                </div>
              </div>
              </div><!-- /seg-2col -->

              <!-- Catatan Slitlamp -->
              <div class="card">
                <div class="ch">
                  <div class="cht">
                    <svg viewBox="0 0 24 24"><path d="M12 2v6M12 16v6M4.93 4.93l4.24 4.24M14.83 14.83l4.24 4.24M2 12h6M16 12h6M4.93 19.07l4.24-4.24M14.83 9.17l4.24-4.24"/></svg>
                    Catatan Slitlamp
                  </div>
                </div>
                <div class="cb">
                  <div class="fg">
                    <label class="fl">Temuan Slitlamp</label>
                    <textarea v-model="exam.slitlamp_notes" class="form-textarea" rows="3"
                      placeholder="Temuan pemeriksaan slitlamp OD/OS..."></textarea>
                  </div>
                </div>
              </div>

              <button class="btn btn-primary" @click="tab = 'tindakan'">
                Lanjut ke Tindakan &amp; Resep
                <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
              </button>
            </div>

            <!-- ── KANAN: SIDEBAR ── -->
            <div class="pem-sidebar">

              <!-- SOAP History card -->
              <div class="card">
                <div class="ch">
                  <div class="cht">
                    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    SOAP / CPPT
                  </div>
                  <span class="card-counter">{{ soapPages.length }} kunjungan</span>
                </div>

                <div v-if="soapHistoryLoading" class="penunjang-empty">
                  Memuat riwayat SOAP…
                </div>

                <div v-else-if="!soapPages.length" class="penunjang-empty">
                  Belum ada catatan SOAP/CPPT
                </div>

                <template v-else-if="currentSoapPage">
                  <!-- Navigasi per tanggal kunjungan: default kunjungan terakhir (idx 0,
                       descending). Panah KANAN → kunjungan lebih lama, KIRI → lebih baru. -->
                  <div class="soap-pager">
                    <button
                      class="soap-pager-btn" title="Kunjungan lebih baru"
                      :disabled="soapPageIdx <= 0"
                      @click="soapPageIdx--"
                    >‹</button>
                    <div class="soap-pager-info">
                      <div class="soap-pager-date">{{ currentSoapPage.date }}</div>
                      <div class="soap-pager-count">{{ soapPageLabel }}</div>
                    </div>
                    <button
                      class="soap-pager-btn" title="Kunjungan lebih lama"
                      :disabled="soapPageIdx >= soapPages.length - 1"
                      @click="soapPageIdx++"
                    >›</button>
                  </div>

                  <div class="soap-history-list">
                    <div v-for="(h, i) in currentSoapPage.entries" :key="i" class="soap-history-item">
                      <div v-if="h.role" class="soap-entry-role" :class="h.role === 'Dokter' ? 'r-dok' : 'r-prw'">{{ h.role }}</div>
                      <div v-if="h.S" class="soap-history-row"><span class="soap-mini-key s">S</span><span class="soap-history-val">{{ h.S }}</span></div>
                      <div v-if="h.O" class="soap-history-row"><span class="soap-mini-key o">O</span><span class="soap-history-val">{{ h.O }}</span></div>
                      <div v-if="h.A" class="soap-history-row"><span class="soap-mini-key a">A</span><span class="soap-history-val soap-a">{{ h.A }}</span></div>
                      <div v-if="h.P" class="soap-history-row"><span class="soap-mini-key p">P</span><span class="soap-history-val">{{ h.P }}</span></div>
                    </div>
                  </div>
                </template>
                <div class="soap-mini-footer">
                  <button class="btn btn-sm btn-secondary" style="width:100%;justify-content:center" @click="tab = 'soap'">
                    Tulis SOAP Kunjungan Ini →
                  </button>
                </div>
              </div>

              <!-- Pemeriksaan Penunjang card (order + dipesan + hasil) -->
              <div class="card">
                <div class="ch">
                  <div class="cht">
                    <svg viewBox="0 0 24 24"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2v-4M9 21H5a2 2 0 01-2-2v-4m0 0h18"/></svg>
                    Pemeriksaan Penunjang
                  </div>
                  <button class="btn btn-sm btn-primary" @click="showPenunjangModal = true">
                    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Order
                  </button>
                </div>

                <!-- Dipesan (pending) -->
                <template v-if="penunjangOrders.length">
                  <div class="hasil-pending-title">Dipesan</div>
                  <div class="hasil-list">
                    <div v-for="o in penunjangOrders" :key="o.id" class="hasil-item pending">
                      <div class="hasil-meta">
                        <span class="hasil-name">{{ o.name }}</span>
                        <div class="hasil-meta-right">
                          <span class="hasil-pending-badge">Menunggu hasil</span>
                          <button class="penunjang-item-del" @click="removePenunjang(o.id)" title="Batalkan order">×</button>
                        </div>
                      </div>
                    </div>
                  </div>
                </template>

                <!-- Hasil — riwayat per kunjungan (terbaru dulu) -->
                <template v-if="penunjangPages.length">
                  <!-- Pager kunjungan: ‹ lebih baru · › lebih lama -->
                  <div class="soap-pager pj-pager">
                    <button class="soap-pager-btn" title="Kunjungan lebih baru"
                      :disabled="penunjangPageIdx <= 0" @click="penunjangPageIdx--">‹</button>
                    <div class="soap-pager-info">
                      <div class="soap-pager-date">{{ currentPenunjangPage.date }}</div>
                      <div class="soap-pager-count">{{ penunjangPageLabel }}</div>
                    </div>
                    <button class="soap-pager-btn" title="Kunjungan lebih lama"
                      :disabled="penunjangPageIdx >= penunjangPages.length - 1" @click="penunjangPageIdx++">›</button>
                  </div>

                  <div v-if="currentPenunjangPage" class="hasil-list">
                    <div v-for="h in currentPenunjangPage.items" :key="h.id" class="hasil-item">
                      <div class="hasil-meta">
                        <span class="hasil-name">
                          {{ h.name }}
                          <svg v-if="h.attachmentUrl" class="hasil-attach-ico" viewBox="0 0 24 24" title="Ada lampiran"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>
                          <span v-if="h.status === 'IN_PROGRESS'" class="hasil-prg-badge">Diproses</span>
                        </span>
                        <div class="hasil-meta-right">
                          <button class="btn-lihat" @click="viewHasil(h)">
                            <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            Lihat
                          </button>
                        </div>
                      </div>
                      <div class="hasil-result">{{ h.result }}</div>
                    </div>
                  </div>
                </template>

                <div v-if="penunjangHistoryLoading" class="penunjang-empty">Memuat riwayat penunjang…</div>
                <div v-else-if="!penunjangPages.length && !penunjangOrders.length" class="penunjang-empty">
                  Belum ada pemeriksaan penunjang. Klik <b>Order</b> untuk memesan.
                </div>
              </div>

            </div>
          </div>

          <!-- ═══ TAB 3: TINDAKAN & RESEP ════════════════════════════════════ -->
          <div v-if="tab === 'tindakan'" class="af">

            <!-- Notice kalau sudah dikirim ke kasir -->
            <div v-if="tab3Sent && !isLocked" class="lock-notice">
              <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
              Tindakan &amp; resep sudah dikunci untuk dikirim ke kasir. Lanjutkan ke SOAP &amp; finalisasi.
              <button class="lock-notice-undo" @click="tab3Sent = false">Buka Kembali</button>
            </div>

            <!-- Konten Tindakan + Resep + Catatan Kasir (lockable) -->
            <div :class="['tab3-stack', (isLocked || tab3Sent) ? 'pane-locked' : '']">

            <!-- Tindakan dari Master Tarif -->
            <div class="card card-dropdown-host">
              <div class="ch">
                <div class="cht">
                  <svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                  Tindakan Medis
                </div>
                <div style="display:flex;align-items:center;gap:0.5rem">
                  <span :class="['tarif-type-badge', selP?.ptype === 'bpjs' ? 'bpjs' : 'umum']">
                    Tarif {{ selP?.ptype === 'bpjs' ? 'BPJS' : selP?.ptype === 'asn' ? 'Asuransi' : 'Umum' }}
                  </span>
                  <span class="card-counter">{{ tindakanList.length }} item</span>
                </div>
              </div>
              <div class="cb">
                <!-- Paket Pemeriksaan (poliklinik): pilih → tindakan paket masuk daftar + diskon di kasir -->
                <div v-if="examPackages.length" class="exam-pkg-bar">
                  <div class="exam-pkg-row">
                    <svg class="exam-pkg-icon" viewBox="0 0 24 24"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    <select v-model="examPackageSel" class="form-input exam-pkg-select" :disabled="applyingExamPkg">
                      <option value="">— Pilih paket pemeriksaan (opsional) —</option>
                      <option v-for="p in examPackages" :key="p.id" :value="p.id">{{ p.code ? `[${p.code}] ` : '' }}{{ p.name }}</option>
                    </select>
                    <button class="exam-pkg-apply" :disabled="!examPackageSel || applyingExamPkg" @click="applyExamPackage">
                      {{ applyingExamPkg ? 'Menerapkan…' : 'Terapkan' }}
                    </button>
                    <button v-if="examPackageInfo" class="exam-pkg-remove" @click="removeExamPackage" title="Lepas paket (tindakan tetap)">Lepas</button>
                  </div>
                  <div v-if="examPackageInfo" class="exam-pkg-info">
                    <strong>{{ examPackageInfo.package_name }}</strong> ·
                    Harga paket {{ fmtRp(examPackageInfo.sell_price) }} ·
                    Total komponen {{ fmtRp(examPackageInfo.total_base_price) }} ·
                    <span class="exam-pkg-disc">Diskon {{ fmtRp(examPackageInfo.discount_amount) }}</span>
                    <span class="exam-pkg-note">— tindakan paket dapat diskon di kasir; tambahan di luar paket ditagih penuh.</span>
                  </div>
                </div>

                <!-- Search-driven add tindakan: hanya muncul saat focus + ada query -->
                <div class="tindakan-search-wrap" ref="tindakanSearchRef">
                  <div class="tindakan-search-field">
                    <svg class="tindakan-search-icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input
                      v-model="tindakanSearch"
                      class="form-input tindakan-search-input"
                      placeholder="Ketik untuk cari tindakan (nama / kode)…"
                      @focus="tindakanSearchFocus = true"
                    />
                    <button v-if="tindakanSearch" class="tindakan-search-clear" @click="tindakanSearch = ''" title="Hapus pencarian">
                      <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                    <span v-if="tindakanList.length" class="tarif-sel-count">{{ tindakanList.length }} dipilih</span>
                  </div>
                  <!-- Dropdown hasil: hanya saat input fokus & ada query -->
                  <div v-if="tindakanSearchFocus && tindakanSearch.trim()" class="tindakan-search-drop">
                    <div
                      v-for="t in filteredTarif" :key="t.id"
                      :class="['tarif-list-item', tindakanList.find(x=>x.id===t.id) ? 'in-list' : '']"
                      @mousedown.prevent="addTindakan(t)"
                    >
                      <span class="tarif-kode">{{ t.code }}</span>
                      <span v-if="t.category" :class="['tarif-kat', t.category === 'Jasa Medis' ? 'jm' : 'td']">{{ t.category }}</span>
                      <span class="tarif-list-name">{{ t.name }}</span>
                      <span class="tarif-list-price">{{ fmtRp(t.price) }}</span>
                      <svg v-if="tindakanList.find(x=>x.id===t.id)" class="tarif-list-icon check" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                      <svg v-else class="tarif-list-icon add" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </div>
                    <div v-if="!filteredTarif.length" class="tarif-empty">
                      {{ tarifTindakanList.length ? 'Tidak ditemukan' : 'Belum ada master tarif tindakan' }}
                    </div>
                    <div v-else-if="filteredTarif.length >= 50" class="tindakan-search-hint">
                      Menampilkan 50 teratas — sempitkan pencarian untuk hasil lebih spesifik
                    </div>
                  </div>
                </div>

                <!-- Tindakan terpilih -->
                <div v-if="tindakanList.length" class="tindakan-table">
                  <div class="tindakan-thead">
                    <div>Tindakan</div>
                    <div>Tarif</div>
                    <div>Qty</div>
                    <div>Subtotal</div>
                    <div></div>
                  </div>
                  <div v-for="t in tindakanList" :key="t.id" class="tindakan-row">
                    <div class="tindakan-info">
                      <span class="tarif-kode">{{ t.code }}</span>
                      <span class="tindakan-nama">{{ t.name }}</span>
                    </div>
                    <div class="tindakan-rate">
                      {{ fmtRp(t.price) }}
                    </div>
                    <div class="tindakan-qty">
                      <button class="qty-btn" @click="decTindakan(t)">−</button>
                      <span class="qty-val">{{ t.qty }}</span>
                      <button class="qty-btn" @click="incTindakan(t)">+</button>
                    </div>
                    <div class="tindakan-subtotal">
                      {{ fmtRp(t.price * t.qty) }}
                    </div>
                    <button class="dx-remove" @click="removeTindakan(t.id)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                  </div>
                  <div class="tindakan-total">
                    <span>Total Tindakan</span>
                    <span class="tindakan-total-val">{{ fmtRp(tindakanSubtotal) }}</span>
                  </div>
                </div>
                <div v-else class="dx-empty" style="margin-top:0.5rem">Pilih tindakan dari daftar di atas</div>
              </div>
            </div>

            <!-- E-Resep -->
            <div class="card">
              <div class="ch">
                <div class="cht">
                  <svg viewBox="0 0 24 24"><path d="M3 3h18v18H3zM3 9h18M9 21V9"/></svg>
                  E-Resep
                </div>
                <span class="card-counter">{{ rxList.length }} obat</span>
              </div>
              <div class="cb">
                <!-- Cari & pilih obat dari inventori (harga = HJA penentuan harga) -->
                <div class="rx-picker">
                  <input v-model="obatSearch" class="form-input" placeholder="Cari obat dari inventori (nama / kode)…" />
                  <div v-if="obatSearch && filteredObat.length" class="rx-drop">
                    <div v-for="d in filteredObat" :key="d.id" class="rx-drop-item" @click="pickObat(d)">
                      <span class="rx-drop-name">{{ d.name }}</span>
                      <span class="rx-drop-meta">
                        {{ d.form }} ·
                        <span :class="['rx-stok', Number(d.stock) > 0 ? 'ok' : 'zero']">Farmasi: {{ d.stock }}</span>
                      </span>
                      <span class="rx-drop-price">{{ fmtRp(d.hja) }}</span>
                    </div>
                  </div>
                  <div v-else-if="obatSearch" class="tarif-empty">Obat tidak ditemukan di inventori</div>
                </div>

                <!-- Form aturan pakai: muncul otomatis saat obat dipilih -->
                <div v-if="newRx.medication_id" class="rx-form-grid">
                  <div class="rx-fg rx-fg-name">
                    <label class="rx-fl">Nama Obat/Alkes</label>
                    <div class="rx-picked">
                      <span class="rx-picked-name">{{ newRx.name }}</span>
                      <span class="rx-picked-meta">{{ newRx.form }} · {{ fmtRp(newRx.hja) }}</span>
                    </div>
                  </div>
                  <div class="rx-fg rx-fg-qty">
                    <label class="rx-fl">Quantity</label>
                    <input v-model.number="newRx.qty" type="number" min="1" class="form-input" placeholder="Qty" />
                  </div>
                  <div class="rx-fg rx-fg-jum">
                    <label class="rx-fl">Jumlah</label>
                    <input v-model="newRx.jumlah" class="form-input" placeholder="cth: 1 tetes" />
                  </div>
                  <div class="rx-fg rx-fg-sig">
                    <label class="rx-fl">Signa</label>
                    <select v-model="newRx.signa" class="form-input" title="Aturan pakai (frekuensi)">
                      <option value="">— pilih —</option>
                      <option v-for="s in SIGNA_OPTS" :key="s" :value="s">{{ s }}</option>
                    </select>
                  </div>
                  <div class="rx-fg rx-fg-dur">
                    <label class="rx-fl">Durasi</label>
                    <select v-model="newRx.dur" class="form-input" title="Lama pemakaian obat">
                      <option value="">— pilih —</option>
                      <option v-for="d in DURASI_OPTS" :key="d" :value="d">{{ d }}</option>
                    </select>
                  </div>
                  <div class="rx-fg rx-fg-pos">
                    <label class="rx-fl">Posisi Mata</label>
                    <select v-model="newRx.posisi" class="form-input" title="Kosongkan jika bukan obat tetes">
                      <option value="">— (bukan tetes)</option>
                      <option value="OD">OD (Kanan)</option>
                      <option value="OS">OS (Kiri)</option>
                      <option value="ODS">ODS (Kedua)</option>
                    </select>
                  </div>
                  <div class="rx-fg rx-fg-btn">
                    <label class="rx-fl">&nbsp;</label>
                    <button class="btn btn-success rx-add-btn" @click="addRx" title="Tambahkan ke daftar resep">
                      <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </button>
                  </div>
                </div>
                <!-- Obat yang sudah diresepkan — tabel ringkas -->
                <div v-if="rxList.length" class="rx-table">
                  <div class="rx-thead">
                    <div>Obat / Alkes</div>
                    <div>Qty</div>
                    <div>Jumlah</div>
                    <div>Signa</div>
                    <div>Durasi</div>
                    <div>Mata</div>
                    <div></div>
                  </div>
                  <div v-for="(r, i) in rxList" :key="`${r.medication_id}-${i}`" class="rx-trow">
                    <div class="rx-cell-name" :title="r.name">{{ r.name }}</div>
                    <div class="rx-cell">{{ r.qty }}</div>
                    <div class="rx-cell">{{ r.jumlah || '—' }}</div>
                    <div class="rx-cell">{{ r.signa || '—' }}</div>
                    <div class="rx-cell">{{ r.dur || '—' }}</div>
                    <div class="rx-cell">{{ r.posisi || '—' }}</div>
                    <button class="dx-remove" @click="removeRx(i)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                  </div>
                </div>
                <div v-else class="dx-empty">Belum ada obat dalam resep</div>

                <!-- Catatan dokter KHUSUS untuk petugas farmasi (substitusi, racikan,
                     instruksi penyerahan). Terpisah dari Catatan Kasir (penagihan). -->
                <div class="farmasi-note">
                  <label class="farmasi-note-label" for="farmasi-note">
                    <svg viewBox="0 0 24 24"><path d="M10 2v6.5L4 13v7h16v-7l-6-4.5V2z"/><line x1="9" y1="2" x2="15" y2="2"/></svg>
                    Catatan untuk Farmasi
                    <span class="farmasi-note-opsional">opsional</span>
                  </label>
                  <textarea
                    id="farmasi-note"
                    v-model="pharmacyNote"
                    class="form-textarea farmasi-note-field"
                    rows="2"
                    maxlength="500"
                    placeholder="Instruksi untuk farmasi (substitusi merek, racikan, cara penyerahan, dsb)…"
                  ></textarea>
                  <div class="farmasi-note-counter">{{ (pharmacyNote || '').length }}/500</div>
                </div>
              </div>
            </div>

            </div><!-- /pane-locked wrapper -->

            <!-- Footer card Tab 3 — full-width, rata dengan card di atas.
                 Kiri: Catatan Kasir (kompak). Kanan: aksi (Kirim ke Kasir + Lanjut ke SOAP). -->
            <div class="tab3-footer">
              <!-- Catatan untuk Kasir — kompak, label di atas field -->
              <div :class="['kasir-note-inline', (isLocked || tab3Sent) ? 'pane-locked' : '']">
                <label class="kasir-note-label" for="kasir-note">
                  <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/></svg>
                  Catatan Kasir
                  <span class="kasir-note-opsional">opsional</span>
                </label>
                <textarea
                  id="kasir-note"
                  v-model="kasirNote"
                  class="form-textarea kasir-note-field"
                  rows="2"
                  maxlength="500"
                  placeholder="Catatan untuk kasir (diskon, instruksi penagihan, dsb)…"
                ></textarea>
                <div class="kasir-note-counter">{{ (kasirNote || '').length }}/500</div>
              </div>

              <!-- Grup aksi: Kirim ke Kasir + Lanjut ke SOAP, sejajar di kanan -->
              <div class="tab3-action-group">
                <button
                  v-if="!isLocked && !tab3Sent"
                  class="btn btn-success tab3-btn"
                  :disabled="!tindakanList.length && !rxList.length"
                  @click="showSendKasirModal = true"
                  title="Kunci tindakan & resep — siap dikirim ke kasir setelah finalisasi"
                >
                  <svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                  Kirim ke Kasir
                </button>
                <button class="btn btn-primary tab3-btn" @click="tab = 'soap'">
                  Lanjut ke SOAP &amp; Diagnosis
                  <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
              </div>
            </div>
          </div>

          <!-- ═══ TAB 4: SOAP & DIAGNOSIS ════════════════════════════════════ -->
          <div v-if="tab === 'soap'" class="af">

            <!-- Lock notice -->
            <div v-if="isLocked" class="lock-notice">
              <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
              Halaman dikunci oleh tanda tangan digital.
              <button class="lock-notice-undo" @click="undoSign">Hapus Tanda Tangan</button>
            </div>

            <!-- SOAP + ICD + Planning — locked when signed -->
            <div :class="['tab3-stack', isLocked ? 'pane-locked' : '']">

            <!-- 2 kolom: KIRI = SOAP (vertikal), KANAN = ICD-10 + ICD-9 -->
            <div class="soap-dx-grid">

            <!-- SOAP (kolom kiri) -->
            <div class="card">
              <div class="ch">
                <div class="cht">
                  <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>
                  SOAP
                </div>
              </div>
              <div class="cb">
                <div class="soap-stack">
                  <div class="soap-cell">
                    <label class="fl soap-fl">
                      <span class="soap-letter s">S</span> Subjektif — keluhan pasien
                      <span v-if="!soapDirty.S" class="auto-tag">otomatis dari anamnese</span>
                      <span v-else class="manual-tag">diedit manual
                        <button type="button" class="resync-btn" @click="resyncSoapS"
                          title="Susun ulang dari anamnese (Tab Pemeriksaan)">↺ sync</button>
                      </span>
                    </label>
                    <textarea v-model="soap.S" @input="markSoapDirty('S')" class="form-textarea" rows="6"
                      placeholder="Terisi otomatis dari anamnese (Tab Pemeriksaan)…"></textarea>
                  </div>
                  <div class="soap-cell">
                    <label class="fl soap-fl">
                      <span class="soap-letter o">O</span> Objektif
                      <span v-if="!soapDirty.O" class="auto-tag">otomatis dari triase, RO, segmen &amp; slitlamp</span>
                      <span v-else class="manual-tag">diedit manual
                        <button type="button" class="resync-btn" @click="resyncSoapO"
                          title="Susun ulang dari data pemeriksaan terbaru">↺ sync</button>
                      </span>
                    </label>
                    <textarea v-model="soap.O" @input="markSoapDirty('O')" class="form-textarea" rows="8"
                      placeholder="Terisi otomatis dari pemeriksaan…"></textarea>
                  </div>
                  <div class="soap-cell">
                    <label class="fl soap-fl">
                      <span class="soap-letter a">A</span> Assessment — kesimpulan klinis
                      <span class="req">*</span>
                    </label>
                    <textarea v-model="soap.A" class="form-textarea" rows="6"
                      placeholder="Diferensial diagnosis, kesimpulan..."></textarea>
                  </div>
                  <div class="soap-cell">
                    <label class="fl soap-fl">
                      <span class="soap-letter p">P</span> Planning — rencana tindakan
                      <span v-if="!soapDirty.P" class="auto-tag">otomatis dari e-resep</span>
                      <span v-else class="manual-tag">diedit manual
                        <button type="button" class="resync-btn" @click="resyncSoapP"
                          title="Susun ulang dari e-resep">↺ sync</button>
                      </span>
                    </label>
                    <textarea v-model="soap.P" @input="markSoapDirty('P')" class="form-textarea" rows="6"
                      placeholder="Terisi otomatis dari e-resep (Nama Obat, Signa, Posisi)…"></textarea>
                  </div>
                </div>
              </div>
            </div>

            <!-- ICD-10 + ICD-9 (kolom kanan, bertumpuk) -->
            <div class="dx-grid">

              <!-- ICD-10 -->
              <div class="card">
                <div class="ch">
                  <div class="cht">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9 12l2 2 4-4"/></svg>
                    Diagnosis ICD-10
                  </div>
                </div>
                <div class="cb">
                  <!-- Utama -->
                  <div class="dx-section">
                    <div class="dx-section-title">
                      <span class="dx-type primary">Utama</span> <span class="req">*</span>
                    </div>
                    <input v-model="dxSearch" class="form-input dx-search" placeholder="Cari kode / nama penyakit..." />
                    <div class="dx-results">
                      <div
                        v-for="d in filteredIcd10" :key="d.code"
                        :class="['dx-result-item', diagnosisUtama?.code === d.code ? 'sel' : '']"
                        @click="setDxUtama(d)"
                      >
                        <span class="dx-code">{{ d.code }}</span>
                        <span class="dx-result-name">{{ d.name }}</span>
                        <svg v-if="diagnosisUtama?.code === d.code" class="dx-result-check" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                      </div>
                    </div>
                    <div v-if="diagnosisUtama" class="dx-row dx-utama" style="margin-top:0.5rem">
                      <span class="dx-type primary">Primer</span>
                      <span class="dx-code">{{ diagnosisUtama.code }}</span>
                      <span class="dx-name">{{ diagnosisUtama.name }}</span>
                      <button class="dx-remove" @click="diagnosisUtama = null; dxSearch = ''">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                      </button>
                    </div>
                    <div v-else class="dx-empty-sm">Belum dipilih — wajib finalisasi</div>
                  </div>

                  <div class="dx-divider"></div>

                  <!-- Sekunder -->
                  <div class="dx-section">
                    <div class="dx-section-title">
                      <span class="dx-type secondary">Sekunder</span> <span style="font-size:10px;color:var(--tu)">opsional</span>
                    </div>
                    <input v-model="dxSearchSek" class="form-input dx-search" placeholder="Cari kode / nama penyakit..." />
                    <div class="dx-results">
                      <div
                        v-for="d in filteredIcd10Sek" :key="d.code"
                        :class="['dx-result-item', diagnosisSekunder.find(x=>x.code===d.code) ? 'sel' : '']"
                        @click="addDxSekunder(d)"
                      >
                        <span class="dx-code">{{ d.code }}</span>
                        <span class="dx-result-name">{{ d.name }}</span>
                        <svg v-if="diagnosisSekunder.find(x=>x.code===d.code)" class="dx-result-check" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                      </div>
                    </div>
                    <div v-if="diagnosisSekunder.length" class="dx-list" style="margin-top:0.5rem">
                      <div v-for="d in diagnosisSekunder" :key="d.code" class="dx-row">
                        <span class="dx-type secondary">Sekunder</span>
                        <span class="dx-code">{{ d.code }}</span>
                        <span class="dx-name">{{ d.name }}</span>
                        <button class="dx-remove" @click="removeDxSekunder(d.code)">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                      </div>
                    </div>
                  </div>

                  <div class="dx-divider"></div>

                  <!-- Tulis Diagnosa (teks bebas) — dipakai saat dokter ragu kode ICD.
                       Terbaca verifikator di Klaim. -->
                  <div class="dx-section">
                    <div class="dx-section-title">
                      <span class="dx-type freetext">Tulis Diagnosa</span>
                      <span style="font-size:10px;color:var(--tu)">bila ragu kode</span>
                    </div>
                    <textarea
                      v-model="diagnosisText"
                      class="form-textarea"
                      rows="2"
                      maxlength="1000"
                      placeholder="Tulis diagnosa dalam bentuk teks bila belum yakin kode ICD-10 yang sesuai…"
                    ></textarea>
                  </div>
                </div>
              </div>

              <!-- ICD-9 CM -->
              <div class="card">
                <div class="ch">
                  <div class="cht">
                    <svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                    ICD-9 CM — Kode Prosedur
                  </div>
                  <span class="card-counter">{{ icd9List.length }} kode</span>
                </div>
                <div class="cb">
                  <input v-model="icd9Search" class="form-input dx-search" placeholder="Cari kode / nama prosedur..." />
                  <div class="dx-results">
                    <div
                      v-for="t in filteredIcd9" :key="t.code"
                      :class="['dx-result-item', icd9List.find(x=>x.code===t.code) ? 'sel' : '']"
                      @click="addIcd9(t)"
                    >
                      <span class="dx-code">{{ t.code }}</span>
                      <span class="dx-result-name">{{ t.name }}</span>
                      <svg v-if="icd9List.find(x=>x.code===t.code)" class="dx-result-check" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                  </div>
                  <div v-if="icd9List.length" class="dx-list" style="margin-top:0.5rem">
                    <div v-for="t in icd9List" :key="t.code" class="dx-row">
                      <span class="icd9-badge">ICD-9</span>
                      <span class="dx-code">{{ t.code }}</span>
                      <span class="dx-name">{{ t.name }}</span>
                      <button class="dx-remove" @click="removeIcd9(t.code)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                      </button>
                    </div>
                  </div>
                  <div v-else class="dx-empty-sm">Opsional — untuk coding klaim</div>
                </div>
              </div>

            <!-- Planning (kolom kanan, di bawah ICD — mengisi area kosong) -->
            <div class="card">
              <div class="ch">
                <div class="cht">
                  <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                  Planning <span class="req" style="font-weight:400">*</span>
                </div>
              </div>
              <div class="cb">
                <div class="plan-opts">
                  <!-- Pulang Berobat Jalan -->
                  <div :class="['plan-opt', planning === 'PULANG' ? 'selected' : '']" @click="planning = 'PULANG'">
                    <div class="plan-icon pulang">
                      <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <div class="plan-body">
                      <div class="plan-title">Pulang</div>
                      <div class="plan-sub">Resep & jadwal kontrol</div>
                    </div>
                    <div class="plan-check">
                      <svg v-if="planning === 'PULANG'" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                    </div>
                  </div>
                  <!-- Bedah -->
                  <div :class="['plan-opt', planning === 'BEDAH' ? 'selected' : '']" @click="planning = 'BEDAH'">
                    <div class="plan-icon bedah">
                      <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 100 20A10 10 0 0012 2z"/><path d="M12 8v4l3 3"/></svg>
                    </div>
                    <div class="plan-body">
                      <div class="plan-title">Bedah</div>
                      <div class="plan-sub">Antrean kamar operasi</div>
                    </div>
                    <div class="plan-check">
                      <svg v-if="planning === 'BEDAH'" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                    </div>
                  </div>
                  <!-- Rawat Inap (observasi) -->
                  <div :class="['plan-opt', planning === 'RAWAT_INAP' ? 'selected' : '']" @click="planning = 'RAWAT_INAP'">
                    <div class="plan-icon ranap">
                      <svg viewBox="0 0 24 24"><path d="M3 18v-6a2 2 0 012-2h14a2 2 0 012 2v6"/><path d="M3 18h18"/><path d="M7 10V7a1 1 0 011-1h3a1 1 0 011 1v3"/></svg>
                    </div>
                    <div class="plan-body">
                      <div class="plan-title">Rawat Inap</div>
                      <div class="plan-sub">Observasi · menunggu kamar</div>
                    </div>
                    <div class="plan-check">
                      <svg v-if="planning === 'RAWAT_INAP'" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                    </div>
                  </div>
                  <!-- Rujuk -->
                  <div :class="['plan-opt', planning === 'RUJUK' ? 'selected' : '']" @click="planning = 'RUJUK'">
                    <div class="plan-icon rujuk">
                      <svg viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </div>
                    <div class="plan-body">
                      <div class="plan-title">Rujuk</div>
                      <div class="plan-sub">Antar-poli / faskes lain</div>
                    </div>
                    <div class="plan-check">
                      <svg v-if="planning === 'RUJUK'" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                    </div>
                  </div>
                </div>

                <!-- Pulang: Tanggal Kontrol -->
                <div v-if="planning === 'PULANG'" class="plan-fields">
                  <div class="fg">
                    <label class="fl">Tanggal Kontrol Berikutnya</label>
                    <input type="date" v-model="tanggalKontrol" class="form-input" style="max-width:220px" />
                  </div>

                  <!-- Surat Kontrol BPJS: info (sebelum finalisasi) + hasil (sesudah) -->
                  <template v-if="isBpjsPatient">
                    <div v-if="suratKontrol?.status === 'SUCCESS'" class="sk-panel sk-ok">
                      <span class="sk-badge sk-badge-ok">Surat Kontrol BPJS terbit</span>
                      No: <b>{{ suratKontrol.no_surat_kontrol }}</b> · kontrol {{ suratKontrol.tanggal_rencana_kontrol }}
                    </div>
                    <div v-else-if="suratKontrol?.status === 'FAILED'" class="sk-panel sk-fail">
                      <span class="sk-badge sk-badge-fail">Surat Kontrol gagal terbit</span>
                      Bisa diterbitkan ulang dari menu Bridging.
                    </div>
                    <div v-else-if="tanggalKontrol" class="sk-panel sk-info">
                      <span class="sk-badge sk-badge-info">BPJS</span>
                      Surat Kontrol akan diterbitkan otomatis ke VClaim saat finalisasi.
                      <span class="sk-note">(butuh SEP aktif)</span>
                    </div>
                  </template>
                </div>

                <!-- Bedah: Lokasi → Kategori → Paket → Tanggal -->
                <div v-if="planning === 'BEDAH'" class="surgery-fields">
                  <!-- 0) Lokasi pelaksanaan: Ruang Bedah (operasi) vs Ruang Tindakan (laser) -->
                  <div class="fg" style="margin-bottom:0.6rem">
                    <label class="fl">Lokasi Pelaksanaan</label>
                    <div class="loc-toggle">
                      <label class="loc-opt" :class="{ active: surgeryLocation === 'RUANG_BEDAH' }">
                        <input type="radio" value="RUANG_BEDAH" v-model="surgeryLocation" />
                        <span><b>Ruang Bedah</b><small>Operasi (Phaco, SICS, Vitrektomi, dll)</small></span>
                      </label>
                      <label class="loc-opt" :class="{ active: surgeryLocation === 'RUANG_TINDAKAN' }">
                        <input type="radio" value="RUANG_TINDAKAN" v-model="surgeryLocation" />
                        <span><b>Ruang Tindakan</b><small>Tindakan laser (YAG, Retina/PRP)</small></span>
                      </label>
                    </div>
                  </div>
                  <div class="g2">
                    <!-- 1) Pilih kategori dulu -->
                    <div class="fg">
                      <label class="fl">
                        Kategori Paket {{ surgeryLocation === 'RUANG_TINDAKAN' ? 'Tindakan' : 'Bedah' }}
                        <span v-if="surgeryLocation === 'RUANG_TINDAKAN'" class="fl-opt">(opsional)</span>
                      </label>
                      <select v-model="surgeryCategory" class="form-select">
                        <option value="">— Pilih kategori —</option>
                        <option v-for="c in surgeryCategories" :key="c" :value="c">{{ c }}</option>
                      </select>
                      <div v-if="!surgeryPackages.length" class="surgery-hint warn">
                        Belum ada master paket aktif. Atur di Tarif &amp; Paket Bedah.
                      </div>
                    </div>
                    <!-- 2) Paket sesuai kategori -->
                    <div class="fg">
                      <label class="fl">
                        Paket {{ surgeryLocation === 'RUANG_TINDAKAN' ? 'Tindakan' : 'Bedah' }}
                        <span v-if="surgeryLocation === 'RUANG_TINDAKAN'" class="fl-opt">(opsional)</span>
                      </label>
                      <select v-model="surgeryPkg" class="form-select" :disabled="!surgeryCategory">
                        <option value="">{{ surgeryCategory ? '— Pilih paket —' : 'Pilih kategori dulu' }}</option>
                        <option v-for="p in filteredSurgeryPackages" :key="p.id" :value="p.id">
                          {{ p.code ? p.code + ' · ' : '' }}{{ p.name }}
                        </option>
                      </select>
                      <div v-if="surgeryLocation === 'RUANG_TINDAKAN'" class="surgery-hint">
                        Tindakan laser ditagih per-tindakan di Ruang Tindakan — paket boleh dikosongkan.
                      </div>
                      <div v-else-if="surgeryCategory && !filteredSurgeryPackages.length" class="surgery-hint">
                        Tidak ada paket pada kategori ini
                      </div>
                    </div>
                  </div>
                  <!-- 3) Tanggal + jam rencana bedah -->
                  <div class="g2" style="margin-top:0.6rem">
                    <div class="fg">
                      <label class="fl">Tanggal Bedah</label>
                      <input type="date" v-model="surgeryDate" class="form-input" />
                    </div>
                    <div class="fg">
                      <label class="fl">Jam Bedah <span class="fl-opt">(opsional)</span></label>
                      <select v-model="surgeryTime" class="form-select" :disabled="!surgeryDate">
                        <option value="">{{ surgeryDate ? '— Pilih jam —' : 'Pilih tanggal dulu' }}</option>
                        <option
                          v-for="t in SURGERY_TIME_SLOTS" :key="t" :value="t"
                          :disabled="bookedSurgeryTimes.has(t)"
                        >
                          {{ t }}{{ bookedSurgeryTimes.has(t) ? ' · terisi' : '' }}
                        </option>
                      </select>
                    </div>
                  </div>

                  <!-- Preview jumlah pasien bedah pada tanggal terpilih -->
                  <div v-if="surgeryDate" class="bedah-preview">
                    <div v-if="bedahSlotLoading" class="bedah-preview-loading">Memeriksa jadwal…</div>
                    <template v-else-if="bedahSlotInfo">
                      <div class="bedah-preview-head">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span v-if="bedahSlotInfo.total">
                          <b>{{ bedahSlotInfo.total }}</b> pasien bedah terjadwal pada tanggal ini
                        </span>
                        <span v-else>Belum ada pasien bedah terjadwal pada tanggal ini</span>
                      </div>
                      <div v-if="bedahSlotInfo.slots?.length" class="bedah-preview-slots">
                        <span
                          v-for="(s, i) in bedahSlotInfo.slots" :key="i"
                          class="bedah-slot-chip"
                          :title="s.package_name || ''"
                        >
                          {{ s.time || '--:--' }}<template v-if="s.room"> · {{ s.room }}</template>
                        </span>
                      </div>
                    </template>
                  </div>

                  <!-- Fase 8: pre-op rawat inap (pasien datang H-1) — hanya untuk operasi di Ruang Bedah -->
                  <label v-if="surgeryLocation === 'RUANG_BEDAH'" class="ranap-check">
                    <input type="checkbox" v-model="requiresInpatient" />
                    <span class="ranap-check-tx">
                      <b>Perlu rawat inap (pre-op H-1)</b>
                      <small>Pasien datang H-1 untuk persiapan pre-op lalu diopname sampai operasi.</small>
                    </span>
                  </label>
                </div>

                <!-- Rujuk: pilih mode Internal (antar-poli) vs Eksternal (faskes lain) -->
                <div v-if="planning === 'RUJUK'" class="plan-fields">
                  <div class="rujuk-mode-tabs">
                    <button type="button" :class="['rujuk-mode-tab', rujukMode === 'INTERNAL' ? 'active' : '']" @click="rujukMode = 'INTERNAL'">
                      Rujuk Internal <span class="rmt-sub">(antar-poli)</span>
                    </button>
                    <button type="button" :class="['rujuk-mode-tab', rujukMode === 'EXTERNAL' ? 'active' : '']" @click="rujukMode = 'EXTERNAL'">
                      Rujuk Eksternal <span class="rmt-sub">(faskes lain)</span>
                    </button>
                  </div>

                  <!-- Mode INTERNAL: pilih poli/dokter tujuan + jadwal-aware -->
                  <div v-if="rujukMode === 'INTERNAL'" class="rujuk-internal-box">
                    <div class="g2">
                      <div class="fg">
                        <label class="fl">Poli / Dokter Tujuan</label>
                        <select v-model="internalTargetId" class="form-select" :disabled="internalTargetsLoading">
                          <option value="">{{ internalTargetsLoading ? 'Memuat…' : '— Pilih poli/dokter tujuan —' }}</option>
                          <optgroup v-for="grp in internalTargetsByPoli" :key="grp.poli" :label="grp.poli">
                            <option v-for="t in grp.items" :key="t.schedule_id" :value="t.schedule_id">
                              {{ t.doctor_name || 'Dokter' }} · {{ t.day_label }} {{ t.start_time }}{{ t.is_today ? ' · HARI INI' : '' }}
                            </option>
                          </optgroup>
                        </select>
                        <div v-if="!internalTargetsLoading && !internalTargets.length" class="surgery-hint warn">
                          Belum ada jadwal dokter lain minggu ini. Atur di Jadwal Dokter.
                        </div>
                      </div>
                      <div class="fg">
                        <label class="fl">Alasan Rujukan <span class="fl-opt">(opsional)</span></label>
                        <input v-model="internalReason" class="form-input" placeholder="mis. Evaluasi retina / glaukoma..." />
                      </div>
                    </div>

                    <!-- Preview jadwal dokter tujuan (jadwal-aware) -->
                    <div v-if="internalTargetSel" class="rujuk-internal-preview">
                      <template v-if="internalTargetSel.is_today">
                        <span class="rip-badge rip-today">Praktik hari ini</span>
                        Pasien langsung masuk antrean <b>{{ internalTargetSel.poliklinik }}</b>
                        ({{ internalTargetSel.doctor_name }}, mulai {{ internalTargetSel.start_time }}).
                      </template>
                      <template v-else>
                        <span class="rip-badge rip-later">Jadwal {{ internalTargetSel.day_label }}</span>
                        Dokter tujuan tidak praktik hari ini — pasien <b>daftar ulang</b> hari
                        {{ internalTargetSel.day_label }} ({{ internalTargetSel.start_time }}).
                      </template>
                    </div>

                    <div class="rujuk-internal-actions">
                      <button
                        type="button" class="btn-rujuk-internal"
                        :disabled="!internalTargetId || internalSubmitting"
                        @click="submitRujukInternal"
                      >
                        {{ internalSubmitting ? 'Memproses…' : 'Buat Rujukan ke Poli Ini' }}
                      </button>
                      <span class="ri-hint">Membuat kunjungan baru untuk dokter tujuan. Tidak menutup pemeriksaan ini.</span>
                    </div>
                  </div>

                  <!-- Mode EXTERNAL -->
                  <div v-else>
                    <!-- Pasien BPJS: form lengkap → terbit ke VClaim -->
                    <div v-if="isBpjsPatient" class="rujuk-internal-box">
                      <!-- Cari faskes tujuan (referensi BPJS) -->
                      <div class="fg">
                        <label class="fl">Faskes Tujuan (RS) <span class="req">*</span></label>
                        <div class="rk-search">
                          <input v-model="rkFaskesQ" class="form-input" placeholder="Ketik nama RS lalu Cari…" @keyup.enter="searchFaskes" />
                          <button type="button" class="rk-search-btn" :disabled="rkFaskesLoading" @click="searchFaskes">{{ rkFaskesLoading ? '…' : 'Cari' }}</button>
                        </div>
                        <div v-if="rkFaskesRes.length" class="rk-result-list">
                          <div v-for="f in rkFaskesRes" :key="f.kode" class="rk-result-item" @click="pickFaskes(f)">
                            <b>{{ f.nama }}</b> <span class="rk-code">{{ f.kode }}</span>
                          </div>
                        </div>
                        <div v-if="rk.faskesKode" class="rk-picked">✓ {{ rk.faskesNama }} ({{ rk.faskesKode }})</div>
                      </div>

                      <div class="g2">
                        <!-- Poli rujukan (referensi BPJS) -->
                        <div class="fg">
                          <label class="fl">Poli Rujukan</label>
                          <div class="rk-search">
                            <input v-model="rkPoliQ" class="form-input" placeholder="mis. Mata…" @keyup.enter="searchPoliRujuk" />
                            <button type="button" class="rk-search-btn" :disabled="rkPoliLoading" @click="searchPoliRujuk">{{ rkPoliLoading ? '…' : 'Cari' }}</button>
                          </div>
                          <div v-if="rkPoliRes.length" class="rk-result-list">
                            <div v-for="p in rkPoliRes" :key="p.kode" class="rk-result-item" @click="pickPoliRujuk(p)">
                              <b>{{ p.nama }}</b> <span class="rk-code">{{ p.kode }}</span>
                            </div>
                          </div>
                          <div v-if="rk.poliKode" class="rk-picked">✓ {{ rk.poliNama }} ({{ rk.poliKode }})</div>
                        </div>
                        <!-- Diagnosa rujukan (prefill dari Tab 4) -->
                        <div class="fg">
                          <label class="fl">Diagnosa Rujukan <span class="req">*</span></label>
                          <input v-model="rk.diagKode" class="form-input" placeholder="Kode ICD-10 (mis. H25.9)" />
                          <div v-if="rk.diagNama" class="surgery-hint">{{ rk.diagNama }}</div>
                        </div>
                      </div>

                      <div class="g2">
                        <div class="fg">
                          <label class="fl">Tipe Rujukan</label>
                          <select v-model="rk.tipeRujukan" class="form-select">
                            <option value="0">Penuh</option>
                            <option value="1">Partial</option>
                            <option value="2">Rujuk Balik</option>
                          </select>
                        </div>
                        <div class="fg">
                          <label class="fl">Jenis Pelayanan</label>
                          <select v-model="rk.jnsPelayanan" class="form-select">
                            <option value="2">Rawat Jalan</option>
                            <option value="1">Rawat Inap</option>
                          </select>
                        </div>
                      </div>

                      <div class="fg">
                        <label class="fl">Catatan</label>
                        <input v-model="rk.catatan" class="form-input" placeholder="Catatan rujukan…" />
                      </div>

                      <div v-if="!selP?.sepNo || selP.sepNo === '—'" class="surgery-hint warn">
                        Pasien BPJS belum punya SEP — terbitkan SEP di Admisi sebelum membuat rujukan.
                      </div>

                      <div class="rujuk-internal-actions">
                        <button
                          type="button" class="btn-rujuk-internal"
                          :disabled="rkSubmitting || !rk.faskesKode || !rk.diagKode"
                          @click="submitRujukKeluar"
                        >
                          {{ rkSubmitting ? 'Menerbitkan…' : 'Terbitkan Rujukan BPJS' }}
                        </button>
                        <span class="ri-hint">Dikirim ke VClaim BPJS. Nomor rujukan muncul setelah berhasil.</span>
                      </div>
                    </div>

                    <!-- Pasien non-BPJS: surat rujukan lokal (ikut Simpan Planning) -->
                    <div v-else class="g2">
                      <div class="fg">
                        <label class="fl">Faskes Tujuan</label>
                        <input v-model="rujukFaskes" class="form-input" placeholder="Nama RS / Klinik / Puskesmas..." />
                      </div>
                      <div class="fg">
                        <label class="fl">Alasan Rujukan</label>
                        <input v-model="rujukAlasan" class="form-input" placeholder="Diperlukan tindakan bedah..." />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div><!-- /Planning card -->

            </div><!-- /dx-grid (kolom kanan: ICD-10 + ICD-9 + Planning) -->
            </div><!-- /soap-dx-grid -->

            </div><!-- end pane-locked -->

            <!-- Penyelesaian RME — Tanda Tangan + Finalisasi dalam SATU card,
                 dua tombol sejajar & seukuran. -->
            <div class="card finalize-card">
              <div v-if="!finalized" class="finalize-row">
                <div class="finalize-info">
                  <svg class="finalize-status-ic" viewBox="0 0 24 24">
                    <template v-if="signed">
                      <path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>
                    </template>
                    <template v-else>
                      <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </template>
                  </svg>
                  <div>
                    <div class="fin-title" :class="{ 'fin-ready': signed }">
                      {{ signed ? 'Siap Difinalisasi' : 'Belum Dapat Difinalisasi' }}
                    </div>
                    <div class="fin-sub">
                      <template v-if="signed">Ditandatangani · {{ signTimestamp }} — klik Finalisasi untuk kirim FHIR R4</template>
                      <template v-else>Lengkapi Dx · Assessment · Planning, lalu tanda tangan</template>
                    </div>
                  </div>
                </div>

                <div class="finalize-actions">
                  <!-- Tanda Tangan -->
                  <button v-if="!signed" class="btn btn-secondary finalize-btn" @click="openSignModal">
                    <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    Tanda Tangan
                  </button>
                  <button v-else class="btn btn-secondary finalize-btn is-signed" @click="undoSign" title="Hapus tanda tangan">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Ditandatangani
                  </button>
                  <!-- Finalisasi -->
                  <button class="btn btn-primary finalize-btn" :disabled="finalizing || !signed" @click="doFinalize">
                    <div v-if="finalizing" class="sp"></div>
                    <svg v-else viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                    {{ finalizing ? 'Memproses…' : 'Finalisasi' }}
                  </button>
                </div>
              </div>
              <div v-else class="fin-success">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                RME telah difinalisasi &amp; terkirim ke Satu Sehat
              </div>
            </div>

            <!-- ═══ FORM REGISTRY — Dokumen Rekam Medis Pasien (Fase 2) ═══
                 Launcher dipindah ke patient bar (.dbtns) supaya tampil di semua
                 tab; form lengkap dibuka di modal showFormDocsModal. -->

          </div>
        </div>
      </template>

      </div><!-- end rme-card -->
    </section>

    <!-- ═══ MODAL: TANDA TANGAN DIGITAL (PIN) ═══ -->
    <Teleport to="body">
      <div v-if="showSignModal" class="modal-overlay" @click.self="showSignModal = false">
        <div class="modal-box sig-modal-box">
          <div class="modal-box-head">
            <div class="modal-box-title">
              <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
              Tanda Tangan Digital
            </div>
            <button class="modal-box-close" @click="showSignModal = false">×</button>
          </div>
          <div class="modal-box-body sig-modal-body">
            <div class="sig-doctor-info">
              <div class="sig-avatar">
                <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </div>
              <div>
                <div class="sig-name">{{ signerName }}</div>
                <div class="sig-role">{{ signerRole }}</div>
              </div>
            </div>

            <label class="fl" for="sig-pin">PIN Tanda Tangan</label>
            <input
              id="sig-pin"
              v-model="pinInput"
              type="password"
              :class="['form-input sig-pin-input', { 'pin-error': pinError }]"
              placeholder="Masukkan PIN"
              maxlength="6"
              autofocus
              @keydown.enter="doSign"
            />
            <div class="sig-pin-feedback">
              <span v-if="pinError" class="sig-pin-error">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                {{ pinError }}
              </span>
              <span v-else class="sig-pin-hint">Diatur admin di Data Pengguna</span>
            </div>
          </div>
          <div class="sig-modal-actions">
            <button class="btn btn-secondary" @click="showSignModal = false">Batal</button>
            <button class="btn btn-primary" :disabled="pinVerifying" @click="doSign">
              <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
              {{ pinVerifying ? 'Memverifikasi…' : 'Tanda Tangan' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ═══ MODAL: DOKUMEN REKAM MEDIS (FORM REGISTRY) ═══ -->
    <Teleport to="body">
      <div v-if="showFormDocsModal && store.selectedVisitId" class="modal-overlay" @click.self="showFormDocsModal = false">
        <div class="modal-box modal-box-forms">
          <div class="modal-box-head">
            <div class="modal-box-title">
              <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
              Dokumen Rekam Medis Pasien
            </div>
            <button class="modal-box-close" @click="showFormDocsModal = false">×</button>
          </div>
          <div class="modal-box-body">
            <FormDocsBrowser
              :visit-id="store.selectedVisitId"
              :patient-id="store.selectedPatientId"
            />
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ═══ MODAL: ORDER PENUNJANG ═══ -->
    <Teleport to="body">
      <div v-if="showPenunjangModal" class="modal-overlay" @click.self="showPenunjangModal = false">
        <div class="modal-box">
          <div class="modal-box-head">
            <div class="modal-box-title">
              <svg viewBox="0 0 24 24"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2v-4M9 21H5a2 2 0 01-2-2v-4m0 0h18"/></svg>
              Order Pemeriksaan Penunjang
            </div>
            <button class="modal-box-close" @click="showPenunjangModal = false">×</button>
          </div>
          <div class="modal-box-body">
            <div class="penunjang-modal-grid">
              <div
                v-for="t in penunjangTypes" :key="t.id"
                :class="['penunjang-modal-chip', penunjangOrders.find(x => x.name === t.name) ? 'ordered' : '']"
                @click="orderPenunjang(t)"
              >
                <div class="penunjang-modal-chip-name">{{ t.name }}</div>
                <div class="penunjang-modal-chip-cat">{{ t.category }}</div>
                <div v-if="penunjangOrders.find(x => x.name === t.name)" class="penunjang-modal-chip-check">
                  <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                  Dipilih
                </div>
              </div>
              <!-- Pemeriksaan di luar master -->
              <div
                class="penunjang-modal-chip chip-lainnya"
                :class="{ ordered: showCustomPenunjang }"
                @click="showCustomPenunjang = !showCustomPenunjang"
              >
                <div class="penunjang-modal-chip-name">+ Lainnya</div>
                <div class="penunjang-modal-chip-cat">Di luar daftar master</div>
              </div>
            </div>

            <div v-if="!penunjangTypes.length" class="penunjang-empty">
              Master jenis penunjang masih kosong — atur di modul <b>Penunjang → tab "Jenis Penunjang"</b>.
            </div>

            <!-- Input pemeriksaan custom (Lainnya) -->
            <div v-if="showCustomPenunjang" class="custom-penunjang">
              <input
                v-model="customPenunjang.name" class="cp-input"
                placeholder="Nama pemeriksaan (mis. Pachymetry)"
                @keydown.enter="addCustomPenunjang"
              />
              <input
                v-model="customPenunjang.category" class="cp-input cp-cat"
                placeholder="Kategori (opsional)"
                @keydown.enter="addCustomPenunjang"
              />
              <button class="btn btn-sm btn-primary" @click="addCustomPenunjang">Tambah</button>
            </div>

            <div v-if="penunjangOrders.length" class="penunjang-modal-ordered">
              <div class="penunjang-modal-ordered-title">Dipesan ({{ penunjangOrders.length }})</div>
              <div class="penunjang-modal-tags">
                <span v-for="o in penunjangOrders" :key="o.id" class="penunjang-modal-tag">
                  {{ o.name }}
                  <button @click.stop="removePenunjang(o.id)">×</button>
                </span>
              </div>
            </div>
          </div>
          <div class="modal-box-foot">
            <button class="btn btn-secondary" @click="showPenunjangModal = false">Tutup</button>
            <button class="btn btn-primary" :disabled="!penunjangOrders.length" @click="confirmPenunjang">
              Konfirmasi {{ penunjangOrders.length }} Pemeriksaan
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ═══ MODAL: KONFIRMASI KIRIM KE KASIR ═══ -->
    <Teleport to="body">
      <div v-if="showSendKasirModal" class="modal-overlay" @click.self="showSendKasirModal = false">
        <div class="modal-box modal-box-sm">
          <div class="modal-box-head">
            <div class="modal-box-title">
              <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
              Kirim ke Kasir?
            </div>
            <button class="modal-box-close" @click="showSendKasirModal = false">×</button>
          </div>
          <div class="modal-box-body">
            <p class="kasir-confirm-msg">
              Tindakan dan resep akan dikirim ke <b>kasir</b> dan data tidak bisa diubah lagi.
            </p>
            <div class="kasir-confirm-summary">
              <div><b>{{ tindakanList.length }}</b> tindakan medis</div>
              <div><b>{{ rxList.length }}</b> obat dalam resep</div>
              <div v-if="kasirNote && kasirNote.trim()" class="kasir-confirm-note">
                Catatan: <i>"{{ kasirNote.trim() }}"</i>
              </div>
            </div>
          </div>
          <div class="modal-box-foot">
            <button class="btn btn-secondary" :disabled="sendingToKasir" @click="showSendKasirModal = false">Batal</button>
            <button class="btn btn-success" :disabled="sendingToKasir" @click="konfirmKirimKasir">
              <span v-if="sendingToKasir" class="sp"></span>
              {{ sendingToKasir ? 'Menyimpan…' : 'YA, Kirim' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ═══ MODAL: LIHAT HASIL PENUNJANG ═══ -->
    <Teleport to="body">
      <div v-if="showHasilModal && selectedHasil" class="modal-overlay" @click.self="showHasilModal = false">
        <div class="modal-box modal-box-sm">
          <div class="modal-box-head">
            <div class="modal-box-title">
              <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
              {{ selectedHasil.name }}
            </div>
            <button class="modal-box-close" @click="showHasilModal = false">×</button>
          </div>
          <div class="modal-box-body">
            <div class="hasil-modal-date">
              <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              Tanggal: {{ selectedHasil.date }}
              <span v-if="selectedHasil.status === 'IN_PROGRESS'" class="hasil-modal-badge prg">Masih diproses</span>
            </div>

            <!-- Kesimpulan -->
            <template v-if="selectedHasil.kesimpulan">
              <div class="hasil-modal-label">Kesimpulan</div>
              <div class="hasil-modal-result">{{ selectedHasil.kesimpulan }}</div>
            </template>

            <!-- Ringkasan -->
            <template v-if="selectedHasil.ringkasan">
              <div class="hasil-modal-label">Ringkasan Pemeriksaan</div>
              <div class="hasil-modal-result">{{ selectedHasil.ringkasan }}</div>
            </template>

            <!-- Catatan tambahan -->
            <template v-if="selectedHasil.notes">
              <div class="hasil-modal-label">Catatan Tambahan</div>
              <div class="hasil-modal-result">{{ selectedHasil.notes }}</div>
            </template>

            <!-- Biometri OD/OS -->
            <template v-if="selectedHasil.biometri">
              <div class="hasil-modal-label">Hasil Biometri</div>
              <table class="hasil-bio-table">
                <thead>
                  <tr><th>Parameter</th><th>OD</th><th>OS</th></tr>
                </thead>
                <tbody>
                  <tr><td>Axial Length (mm)</td><td>{{ selectedHasil.biometri.od?.axial_length || '—' }}</td><td>{{ selectedHasil.biometri.os?.axial_length || '—' }}</td></tr>
                  <tr><td>K1 (D)</td><td>{{ selectedHasil.biometri.od?.k1 || '—' }}</td><td>{{ selectedHasil.biometri.os?.k1 || '—' }}</td></tr>
                  <tr><td>K2 (D)</td><td>{{ selectedHasil.biometri.od?.k2 || '—' }}</td><td>{{ selectedHasil.biometri.os?.k2 || '—' }}</td></tr>
                  <tr><td>ACD (mm)</td><td>{{ selectedHasil.biometri.od?.acd || '—' }}</td><td>{{ selectedHasil.biometri.os?.acd || '—' }}</td></tr>
                  <tr><td>Rec. IOL (D)</td><td>{{ selectedHasil.biometri.od?.recommended_iol_power || '—' }}</td><td>{{ selectedHasil.biometri.os?.recommended_iol_power || '—' }}</td></tr>
                  <tr><td>Tipe IOL</td><td>{{ selectedHasil.biometri.od?.iol_type || '—' }}</td><td>{{ selectedHasil.biometri.os?.iol_type || '—' }}</td></tr>
                  <tr><td>Brand IOL</td><td>{{ selectedHasil.biometri.od?.brand || '—' }}</td><td>{{ selectedHasil.biometri.os?.brand || '—' }}</td></tr>
                </tbody>
              </table>
            </template>

            <!-- Lampiran (gambar / PDF) -->
            <template v-if="selectedHasil.attachmentUrl">
              <div class="hasil-modal-label">Lampiran Hasil</div>
              <div class="hasil-modal-attach">
                <img
                  v-if="/\.(jpe?g|png|webp|gif)$/i.test(selectedHasil.attachmentPath)"
                  :src="selectedHasil.attachmentUrl"
                  class="hasil-modal-img"
                  alt="Lampiran hasil penunjang"
                  @click="openAttachment(selectedHasil.attachmentUrl)"
                />
                <a v-else :href="selectedHasil.attachmentUrl" target="_blank" rel="noopener" class="hasil-modal-file">
                  <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  Buka lampiran (PDF / file)
                </a>
                <a :href="selectedHasil.attachmentUrl" target="_blank" rel="noopener" class="hasil-modal-openlink">
                  Buka di tab baru ↗
                </a>
              </div>
            </template>

            <!-- Fallback bila belum ada hasil terstruktur sama sekali -->
            <template v-if="!selectedHasil.kesimpulan && !selectedHasil.ringkasan && !selectedHasil.notes && !selectedHasil.biometri && !selectedHasil.attachmentUrl">
              <div class="hasil-modal-label">Hasil Pemeriksaan</div>
              <div class="hasil-modal-result">{{ selectedHasil.result }}</div>
            </template>
          </div>
          <div class="modal-box-foot">
            <button class="btn btn-secondary" @click="showHasilModal = false">Tutup</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ═══ MODAL: PREVIEW & TERBITKAN RESUME MEDIS (pasca-finalisasi) ═══ -->
    <Teleport to="body">
      <div v-if="showResumeModal && resumeData" class="modal-overlay">
        <div class="modal-box">
          <div class="modal-box-head">
            <div class="modal-box-title">
              <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
              Resume Medis Rawat Jalan
            </div>
            <button class="modal-box-close" :disabled="resumeApproving" @click="skipResume">×</button>
          </div>
          <div class="modal-box-body">
            <p class="resume-modal-hint">
              Tinjau resume medis hasil pemeriksaan. Lengkapi <b>Tindakan</b>, <b>Riwayat</b>,
              dan <b>Instruksi/Edukasi</b> bila perlu, lalu klik <b>Setuju &amp; Terbitkan</b>.
            </p>

            <!-- Header (read-only, auto dari kunjungan) -->
            <div class="resume-head-grid">
              <div><span class="rh-label">Tanggal Berobat</span><span class="rh-val">{{ resumeHeader.tanggal_berobat || '—' }}</span></div>
              <div><span class="rh-label">Dokter yang Merawat</span><span class="rh-val">{{ resumeHeader.dokter_merawat || '—' }}</span></div>
              <div><span class="rh-label">Ruang Poli</span><span class="rh-val">{{ resumeHeader.ruang_poli || '—' }}</span></div>
              <div><span class="rh-label">Penanggung Pembayaran</span><span class="rh-val">{{ resumeHeader.penanggung_bayar || '—' }}</span></div>
            </div>

            <!-- Field naratif (editable) -->
            <div v-for="f in RESUME_FIELDS" :key="f.key" class="resume-field">
              <label>{{ f.label }}</label>
              <textarea
                v-model="resumeForm[f.key]"
                class="form-input resume-ta"
                :rows="f.rows"
                :disabled="resumeApproving"
                :placeholder="f.key === 'tindakan' ? 'Isi tindakan yang dilakukan…' : ''"
              ></textarea>
            </div>

            <!-- Kontrol -->
            <div class="resume-kontrol">
              <div class="resume-field">
                <label>Kontrol Tanggal</label>
                <input v-model="resumeKontrol.kontrol_tanggal" type="date" class="form-input" :disabled="resumeApproving" />
              </div>
              <div class="resume-field">
                <label>Kontrol di</label>
                <input v-model="resumeKontrol.kontrol_tempat" type="text" class="form-input" :disabled="resumeApproving" placeholder="Tempat kontrol" />
              </div>
            </div>
          </div>
          <div class="modal-box-foot">
            <button class="btn btn-secondary" :disabled="resumeApproving" @click="skipResume">Nanti Saja</button>
            <button class="btn btn-success" :disabled="resumeApproving" @click="approveResume">
              <span v-if="resumeApproving" class="sp"></span>
              {{ resumeApproving ? 'Menerbitkan…' : 'Setuju & Terbitkan' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- TOASTS -->
    <div class="toast-wrap">
      <div v-for="t in toasts" :key="t.id" :class="['toast', 'toast-' + t.type]">
        <span>{{ t.msg }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.dokter {
  display: flex;
  height: calc(100vh - 56px - 3rem);
  margin: -1.5rem;
  background: var(--bg);
  gap: 0;
}

/* ── QUEUE PANEL ─────────────────────────────────────────────────────────── */
.qp { width: 316px; flex-shrink: 0; display: flex; flex-direction: column; padding: 0.75rem 0 0.75rem 0.75rem; overflow: hidden; gap: 0.6rem; }
.qp-card { display: flex; flex-direction: column; flex: 1; overflow: hidden; }

/* ── STATUS SAYA HARI INI ────────────────────────────────────────────────── */
.status-card { padding: 0.75rem 0.85rem; flex-shrink: 0; }
.status-head { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 0.55rem; }
.status-head-title { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: var(--td); }
.status-head-title svg { width: 13px; height: 13px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.status-link { font-size: 11px; color: var(--ga); text-decoration: none; padding: 2px 6px; border-radius: 6px; border: 1px solid var(--gb); transition: background .15s, color .15s; }
.status-link:hover { background: var(--gb); color: var(--td); }
.status-empty { font-size: 11.5px; color: var(--tm); padding: 0.4rem 0.1rem; }
.status-list { display: flex; flex-direction: column; gap: 6px; }
.status-row { display: flex; align-items: center; gap: 8px; padding: 7px 9px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bg); transition: border-color .15s; }
.status-row.on { border-color: rgba(34,197,94,0.35); background: rgba(34,197,94,0.05); }
.status-row.off { opacity: 0.78; }
.sr-main { flex: 1; min-width: 0; }
.sr-line1 { display: flex; align-items: center; gap: 6px; }
.sr-prefix { font-size: 12px; font-weight: 700; color: var(--td); background: var(--gb); padding: 1px 6px; border-radius: 4px; }
.sr-jam { font-size: 11px; color: var(--tm); }
.sr-dot { width: 6px; height: 6px; border-radius: 50%; margin-left: auto; }
.sr-dot.on { background: #22c55e; box-shadow: 0 0 0 2px rgba(34,197,94,0.18); }
.sr-dot.off { background: #cbd5e1; }
.sr-line2 { font-size: 10.5px; color: var(--tm); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sr-actions { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
.sr-room-input { width: 46px; padding: 4px 6px; font-size: 11.5px; border: 1px solid var(--gb); border-radius: 5px; outline: none; }
.sr-room-input:focus { border-color: var(--ga); }
.sr-btn { width: 22px; height: 22px; border: 1px solid var(--gb); border-radius: 5px; background: var(--bg); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background .15s, border-color .15s; }
.sr-btn svg { width: 11px; height: 11px; fill: none; stroke: var(--td); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.sr-btn.edit:hover { background: var(--gb); }
.sr-btn.save { border-color: rgba(34,197,94,0.4); }
.sr-btn.save svg { stroke: #22c55e; }
.sr-btn.cancel:hover { background: rgba(239,68,68,0.08); border-color: rgba(239,68,68,0.3); }
.sr-btn.cancel:hover svg { stroke: #ef4444; }

/* toggle switch */
.sr-switch { position: relative; display: inline-block; width: 30px; height: 16px; cursor: pointer; }
.sr-switch input { opacity: 0; width: 0; height: 0; }
.sr-slider { position: absolute; inset: 0; background: #cbd5e1; border-radius: 16px; transition: background .2s; }
.sr-slider::before { content: ''; position: absolute; left: 2px; top: 2px; width: 12px; height: 12px; background: #fff; border-radius: 50%; transition: transform .2s; }
.sr-switch input:checked + .sr-slider { background: #22c55e; }
.sr-switch input:checked + .sr-slider::before { transform: translateX(14px); }
.card-head { padding: 0.85rem 1rem; border-bottom: 1px solid var(--gb); display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; flex-shrink: 0; }
.card-head-title { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--td); }
.card-head-title svg { width: 14px; height: 14px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.card-head-sub { font-size: 11px; color: var(--tu); margin-top: 3px; }
.pill-live { font-size: 9.5px; font-weight: 700; padding: 2px 8px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 20px; letter-spacing: 0.05em; }
.card-body { padding: 0.6rem; }
.queue-scroll { flex: 1; overflow-y: auto; min-height: 0; display: flex; flex-direction: column; gap: 0.55rem; }
.queue-scroll::-webkit-scrollbar { width: 3px; }
.queue-scroll::-webkit-scrollbar-thumb { background: var(--gb); border-radius: 2px; }
.ptype-ct { font-size: 9px; font-weight: 700; padding: 0 4px; border-radius: 8px; background: rgba(255,255,255,.3); display: inline-block; margin-left: 2px; }

.stats-bar { display: flex; align-items: center; background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 8px 12px; margin-bottom: 0.65rem; }
.stat-item { flex: 1; text-align: center; }
.stat-divider { width: 1px; height: 28px; background: var(--gb); flex-shrink: 0; }
.stat-label { display: block; font-size: 9.5px; color: var(--tu); letter-spacing: 0.03em; margin-bottom: 2px; }
.stat-num { display: block; font-size: 17px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; }
.stat-waiting { color: #d97706; }
.stat-done { color: var(--st); }

.stats-bar { display: flex; align-items: center; background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 8px 12px; }
.stat-item { flex: 1; text-align: center; }
.stat-divider { width: 1px; height: 28px; background: var(--gb); flex-shrink: 0; }
.stat-label { display: block; font-size: 9.5px; color: var(--tu); letter-spacing: 0.03em; margin-bottom: 2px; }
.stat-num { display: block; font-size: 17px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; }
.stat-waiting { color: #d97706; }
.stat-done { color: var(--st); }

.primary-filter { display: flex; gap: 4px; }
.pf-btn { flex: 1; height: 32px; font-size: 11.5px; font-weight: 500; border: 1.5px solid var(--gb); border-radius: 8px; background: var(--bs); color: var(--tm); cursor: pointer; font-family: 'Inter', sans-serif; transition: all .13s; display: flex; align-items: center; justify-content: center; gap: 5px; }
.pf-btn:hover { border-color: var(--ga); color: var(--ga); }
.pf-btn.a { background: var(--gd); color: #fff; border-color: var(--gd); }
.pf-ct { font-size: 9px; font-weight: 700; padding: 0 5px; border-radius: 10px; background: rgba(255,255,255,.25); }

.ptype-tabs { display: flex; gap: 3px; }
.ptype-tab { flex: 1; padding: 5px 3px; font-size: 9.5px; font-weight: 600; border: 1.5px solid var(--gb); border-radius: 7px; background: var(--bs); color: var(--tu); cursor: pointer; font-family: 'Inter', sans-serif; text-align: center; transition: all .13s; white-space: nowrap; }
.ptype-tab:hover { border-color: var(--ga); color: var(--ga); }
.ptype-tab.a { color: #fff; font-weight: 700; }
.ptype-bpjs.a { background: #1d4ed8; border-color: #1d4ed8; }
.ptype-umum.a { background: var(--ga); border-color: var(--ga); }
.ptype-asur.a { background: var(--pt); border-color: var(--pt); }

.q-search-wrap { }
.q-search { width: 100%; height: 30px; font-size: 11.5px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 10px; background: var(--bs); font-family: 'Inter', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.q-search:focus { border-color: var(--ga); background: #fff; }

.empty-section { text-align: center; padding: 0.75rem 1rem; font-size: 11px; color: var(--th); background: var(--bi); border-radius: 7px; border: 1px dashed var(--gb); }

.q-item { display: flex; gap: 8px; padding: 8px 10px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; margin-bottom: 5px; cursor: pointer; transition: all 0.14s; font-family: 'Inter', sans-serif; }
.q-item:hover { border-color: var(--lm); background: var(--gl); }
.q-item.active { border-color: var(--ga); background: var(--gl); }
.q-item.done { opacity: .55; }
.q-item:focus-visible { outline: 2px solid var(--ga); outline-offset: 2px; }
.qi-left { display: flex; flex-direction: column; gap: 4px; min-width: 52px; }
.q-num { font-weight: 700; font-size: 13px; color: var(--ga); letter-spacing: 0.03em; }
.qi-time { font-size: 9px; color: var(--tu); font-variant-numeric: tabular-nums; align-self: flex-start; margin-left: auto; }
.q-info { flex: 1; min-width: 0; }
.q-name { font-size: 12px; font-weight: 500; color: var(--td); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.q-meta { font-size: 9.5px; color: var(--tu); margin-top: 2px; }
.q-tags { display: flex; gap: 3px; margin-top: 3px; flex-wrap: wrap; }

.pill { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px; }
.pill-icon { width: 8px; height: 8px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; flex-shrink: 0; }
.pill-waiting     { background: #fef3c7; color: #92400e; }
.pill-in_progress { background: #dbeafe; color: #1e40af; }
.pill-completed   { background: var(--sb); color: var(--st); }
.pill-penunjang      { background: #fef9c3; color: #854d0e; }
.pill-penunjang-done { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.pill-bpjs   { background: #dbeafe; color: #1e40af; }
.pill-umum   { background: var(--gl); color: var(--ga); }
.pill-asn    { background: var(--pb); color: var(--pt); }
.pill-done   { background: var(--sb); color: var(--st); }
.pill-ro     { background: var(--ib); color: var(--it); }
.pill-allergy { background: var(--eb); color: var(--et); }

.q-actions { display: flex; gap: 4px; margin-top: 5px; padding-top: 5px; border-top: 1px dashed var(--gb); width: 100%; }
.q-act-btn { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; font-size: 10px; font-weight: 600; border-radius: 5px; border: 1px solid; cursor: pointer; font-family: 'Inter', sans-serif; transition: background .12s, color .12s, border-color .12s, transform .07s, box-shadow .07s; background: none; user-select: none; }
.q-act-btn svg { width: 10px; height: 10px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.q-act-btn.call { color: var(--ga); border-color: var(--ga); background: var(--gl); }
.q-act-btn.call:hover { background: var(--ga); color: #fff; }
.q-act-btn.call.recall { color: #b45309; border-color: #fbbf24; background: #fef3c7; }
.q-act-btn.call.recall:hover { background: #f59e0b; color: #fff; border-color: #f59e0b; }
.q-act-btn.skip { color: var(--tu); border-color: var(--gb); }
.q-act-btn.skip:hover { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.q-act-btn.resume { color: #fff; border-color: var(--ga); background: var(--ga); }
.q-act-btn.resume:hover { filter: brightness(1.07); }
.q-act-btn:active:not(:disabled) { transform: scale(0.93); box-shadow: inset 0 1px 3px rgba(0,0,0,.12); }
.q-act-btn.call:active:not(:disabled) { background: var(--gd); color: #fff; border-color: var(--gd); }
.q-act-btn.call.recall:active:not(:disabled) { background: #b45309; color: #fff; border-color: #b45309; }
.q-act-btn.resume:active:not(:disabled) { background: var(--gd); border-color: var(--gd); }
.q-act-btn.skip:active:not(:disabled) { background: var(--wt); color: #fff; border-color: var(--wt); }
.q-act-btn:disabled { opacity: .55; cursor: wait; }
.q-at-penunjang { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 600; color: #854d0e; }
.q-at-penunjang svg { width: 11px; height: 11px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

/* RME */
.rme { flex: 1; display: flex; flex-direction: column; min-width: 0; margin: 0.75rem 0.75rem 0.75rem 0; }

/* TOPBAR — identitas dokter (atas kanan) */
.rme-card {
  flex: 1; display: flex; flex-direction: column; overflow: hidden; min-height: 0;
  background: var(--bc); border: 1px solid var(--gb); border-radius: 12px;
  position: relative;
}
.empty {
  flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
  color: var(--th); text-align: center; gap: 1rem;
}
.empty svg { width: 64px; height: 64px; fill: none; stroke: var(--gb); stroke-width: 1.5; stroke-linecap: round; }
.empty p { font-size: 13.5px; line-height: 1.7; color: var(--th); }

/* Patient bar */
.ptb {
  display: flex; align-items: flex-start; gap: 0.85rem; padding: 0.75rem 1rem;
  background: var(--bc); border-bottom: 1px solid var(--gb); flex-shrink: 0;
}
.ptav {
  width: 42px; height: 42px; border-radius: 50%; background: var(--gl); color: var(--ga);
  font-weight: 700; font-size: 17px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px;
}
.pti { min-width: 0; flex: 1; }
.ptn { font-family: 'Space Grotesk', serif; font-size: 18px; color: var(--td); line-height: 1.1; font-weight: 400; }
.ptm { font-size: 11px; color: var(--tu); margin-top: 3px; }
.pt-address { font-size: 10.5px; color: var(--tu); margin-top: 3px; display: flex; align-items: center; gap: 4px; }
.pt-address svg { width: 10px; height: 10px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.ptags { display: flex; gap: 4px; margin-top: 5px; flex-wrap: wrap; }
.ptg { font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 4px; letter-spacing: 0.02em; }
.ptg-b { background: #dbeafe; color: #1e40af; }
.ptg-a { background: var(--eb); color: var(--et); }
.ptg-rujuk { background: #cffafe; color: #0e7490; }
.cls-baru    { background: #dbeafe; color: #1e40af; }
.cls-preop   { background: #fef3c7; color: #92400e; }
.cls-postop  { background: var(--sb); color: var(--st); }
.cls-kontrol { background: #f3e8ff; color: #7e22ce; }
.vit { display: flex; align-items: flex-end; gap: 0.6rem; padding: 0 0.8rem; align-self: center; }
.vdv { width: 1px; height: 38px; background: var(--gb); }
.vi { display: flex; flex-direction: column; align-items: center; gap: 2px; min-width: 50px; }
.viv { font-size: 14.5px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; line-height: 1; }
.viv.small { font-size: 11.5px; }
.viv.w { color: var(--wt); }
.viv.lo { color: var(--it); }
.vil { font-size: 9px; color: var(--tu); letter-spacing: 0.05em; text-transform: uppercase; }
.vi-eyes { display: flex; justify-content: space-between; width: 100%; max-width: 48px; padding: 0 2px; margin: 0 auto; font-size: 7.5px; font-weight: 700; color: var(--tu); letter-spacing: 0.04em; line-height: 1; }
.viv-pair { display: flex; justify-content: space-between; width: 100%; max-width: 48px; padding: 0 2px; margin: 0 auto; }
.dbtns { display: flex; gap: 4px; flex-shrink: 0; }
.db {
  padding: 5px 10px; font-size: 10.5px; font-weight: 600; border-radius: 7px;
  border: 1.5px solid var(--gb); background: var(--bc); cursor: pointer; color: var(--tu);
  transition: all 0.15s; font-family: 'Inter', sans-serif;
}
.db:hover { color: var(--td); }
.db.act { background: var(--gd); color: #fff; border-color: var(--gd); }
/* Tombol launcher Dokumen RM — aksen biru + ikon supaya menonjol dari drawer toggle */
.db-doc {
  display: inline-flex; align-items: center; gap: 5px;
  color: #1763d4; border-color: #c6dcfb; background: #f3f8ff;
}
.db-doc svg {
  width: 13px; height: 13px; fill: none; stroke: currentColor;
  stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
}
.db-doc:hover { color: #fff; background: #1763d4; border-color: #1763d4; }

/* DRAWER */
.dwr { background: var(--bs); border-bottom: 1px solid var(--gb); flex-shrink: 0; padding: 0.75rem 1rem; }
.dwr-enter-from, .dwr-leave-to { opacity: 0; max-height: 0; padding-top: 0; padding-bottom: 0; }
.dwr-enter-active, .dwr-leave-active { transition: all 0.2s ease; max-height: 300px; overflow: hidden; }
.dwc { background: var(--bc); border: 1px solid var(--gb); border-radius: 9px; padding: 0.7rem 0.85rem; }
.dwt {
  font-size: 11px; font-weight: 600; color: var(--td);
  display: flex; align-items: center; gap: 5px; margin-bottom: 0.5rem;
  letter-spacing: 0.04em; text-transform: uppercase;
}
.dwt svg { width: 13px; height: 13px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.dwt.n svg { stroke: #5b21b6; }
.dwt.r svg { stroke: var(--ga); }
.dw-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 0.4rem 0.85rem; }
.dwr2 { display: flex; align-items: baseline; gap: 6px; font-size: 11px; }
.dwl { color: var(--tu); }
.dwv { font-weight: 600; font-variant-numeric: tabular-nums; }
.dwv.ok { color: var(--st); }
.dwv.w { color: var(--wt); }
.dwv.hi { color: var(--et); }
.dw-keluhan { margin-top: 0.5rem; font-size: 11px; color: var(--tm); }
.dw-keluhan b { color: var(--td); }
.rog { display: grid; grid-template-columns: repeat(2,1fr); gap: 0.35rem 1rem; margin-bottom: 0.5rem; }
.roi { display: flex; align-items: baseline; gap: 6px; }
.roi-l { font-size: 10px; color: var(--tu); text-transform: uppercase; letter-spacing: 0.05em; }
.roi-v { font-size: 12px; font-weight: 600; color: var(--td); }
.roi-v.warn { color: var(--wt); }
.rx-line { font-size: 11px; color: var(--td); margin-top: 3px; }
.rx-line b { color: var(--tm); font-weight: 600; }
.ro-note {
  background: var(--ib); border: 1px solid var(--ibd); border-radius: 7px;
  padding: 0.45rem 0.65rem; font-size: 11px; color: var(--it); margin-top: 0.5rem;
}
.ro-table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
.ro-table th, .ro-table td { padding: 5px 8px; text-align: center; border-bottom: 1px solid var(--gb); }
.ro-table thead th { font-size: 10px; color: var(--tu); font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
.ro-table tbody tr:last-child td { border-bottom: none; }
.ro-table tbody tr:hover td { background: var(--bs); }
.ro-table td { color: var(--tm); font-variant-numeric: tabular-nums; }
.ro-table td.strong { font-weight: 700; color: var(--td); }
.ro-table td.success { color: var(--ga); }
.ro-table td.warn { color: var(--wt); font-weight: 700; }
.ro-table small { font-size: 9px; color: var(--tu); font-weight: 500; }
.rt-param { text-align: left !important; color: var(--tm); font-weight: 600; white-space: nowrap; }
.rt-eye { display: inline-flex; align-items: center; justify-content: center; padding: 1px 6px; border-radius: 5px; font-size: 10px; font-weight: 700; }
.rt-side { font-size: 9px; color: var(--tu); font-weight: 500; }
.ro-foot { display: flex; flex-wrap: wrap; gap: 0.4rem 1.1rem; margin-top: 0.55rem; font-size: 11px; color: var(--tm); }
.ro-foot b { color: var(--td); font-weight: 600; }
.tr-table { width: 100%; border-collapse: collapse; }
.tr-table th { font-size: 9.5px; color: var(--tu); font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; padding: 4px 6px; text-align: center; border-bottom: 1px solid var(--gb); }
.tr-table th small { font-weight: 500; opacity: 0.8; }
.tr-table td { padding: 8px 6px; text-align: center; font-size: 14px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; }
.tr-table td small { font-size: 9px; color: var(--tu); font-weight: 500; }
.tr-table td.warn { color: var(--wt); }
.tr-keluhan { margin-top: 0.55rem; font-size: 11.5px; color: var(--tm); border-top: 1px solid var(--gb); padding-top: 0.5rem; }
.tr-keluhan b { color: var(--td); font-weight: 600; }
.histi { padding: 0.45rem 0.55rem; border: 1px solid var(--gb); border-radius: 7px; background: var(--bs); margin-bottom: 4px; }
.hd { font-size: 10.5px; font-weight: 600; color: var(--td); }
.hdx { font-size: 11px; color: var(--td); margin-top: 2px; }
.hdet { font-size: 10px; color: var(--tu); margin-top: 1px; }
.hist-empty { font-size: 11px; color: var(--th); text-align: center; padding: 0.5rem; }

/* TABS */
.rmtabs {
  display: flex; background: var(--bc); border-bottom: 1px solid var(--gb);
  padding: 0 1rem; gap: 4px; flex-shrink: 0;
}
.rmt {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 0.7rem 0.85rem; font-size: 12px; font-weight: 500; color: var(--tu);
  background: none; border: none; cursor: pointer;
  border-bottom: 2px solid transparent; margin-bottom: -1px;
  font-family: 'Inter', sans-serif; position: relative;
}
.rmt:hover { color: var(--td); }
.rmt.a { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }
.rmt svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.rmtd { width: 6px; height: 6px; border-radius: 50%; background: var(--wt); margin-left: 3px; }
.rmt-count {
  font-size: 9px; font-weight: 700; padding: 1px 5px; border-radius: 20px;
  background: var(--ga); color: #fff; margin-left: 2px;
}

/* ── Pemeriksaan 2-col layout ────────────────────────────────────────────── */
.pem-grid { display: grid; grid-template-columns: 1fr 270px; gap: 1rem; align-items: start; }
.pem-main { display: flex; flex-direction: column; gap: 1rem; }
.pem-sidebar { display: flex; flex-direction: column; gap: 0.75rem; position: sticky; top: 0; }

/* SOAP History sidebar */
.soap-history-list { max-height: 340px; overflow-y: auto; }
.soap-history-item { padding: 0.65rem 0.9rem; border-bottom: 1px solid var(--gb); }
.soap-history-item:last-child { border-bottom: none; }
.soap-history-date { font-size: 10px; font-weight: 700; color: var(--ga); letter-spacing: 0.04em; margin-bottom: 0.45rem; }

/* Pager SOAP/CPPT per tanggal kunjungan */
.soap-pager { display: flex; align-items: center; gap: 8px; padding: 0.5rem 0.65rem; border-bottom: 1px solid var(--gb); background: var(--bs); }
/* Pager penunjang di sidebar card: beri border atas + radius supaya menyatu */
.pj-pager { border-top: 1px solid var(--gb); }
.soap-pager-btn { width: 28px; height: 28px; flex-shrink: 0; border: 1px solid var(--gb); border-radius: 7px; background: var(--bc); color: var(--td); font-size: 16px; line-height: 1; cursor: pointer; transition: all .13s; }
.soap-pager-btn:hover:not(:disabled) { border-color: var(--ga); color: var(--ga); background: var(--gl); }
.soap-pager-btn:disabled { opacity: .35; cursor: not-allowed; }
.soap-pager-info { flex: 1; text-align: center; }
.soap-pager-date { font-size: 11.5px; font-weight: 700; color: var(--ga); letter-spacing: 0.03em; }
.soap-pager-count { font-size: 9.5px; color: var(--tu); margin-top: 1px; }
.soap-entry-role { display: inline-block; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 1px 7px; border-radius: 4px; margin-bottom: 5px; }
.soap-entry-role.r-dok { background: var(--gl); color: var(--ga); }
.soap-entry-role.r-prw { background: var(--ib); color: var(--it); }
.soap-history-row { display: flex; gap: 7px; margin-bottom: 3px; }
.soap-history-val { font-size: 10.5px; color: var(--tm); line-height: 1.45; word-break: break-word; }
.soap-history-val.soap-a { color: #7e22ce; font-weight: 600; }

/* Penunjang modal */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 300; display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
.modal-box { background: var(--bc); border-radius: 14px; width: 100%; max-width: 640px; max-height: 86vh; display: flex; flex-direction: column; box-shadow: 0 24px 64px rgba(0,0,0,.18); overflow: hidden; }
.modal-box-sm { max-width: 480px; }
.modal-box-head { display: flex; align-items: center; justify-content: space-between; padding: 0.9rem 1.2rem; border-bottom: 1px solid var(--gb); flex-shrink: 0; }
.modal-box-title { display: flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 700; color: var(--td); }
.modal-box-title svg { width: 15px; height: 15px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.modal-box-close { width: 28px; height: 28px; border-radius: 6px; border: 1px solid var(--gb); background: var(--bs); font-size: 16px; cursor: pointer; color: var(--tu); display: flex; align-items: center; justify-content: center; }
.modal-box-close:hover { background: var(--eb); color: var(--et); }
.modal-box-body { padding: 1rem 1.2rem; overflow-y: auto; flex: 1; }
.modal-box-foot { padding: 0.75rem 1.2rem; border-top: 1px solid var(--gb); display: flex; justify-content: flex-end; gap: 0.5rem; flex-shrink: 0; }

/* Modal Resume Medis (preview pasca-finalisasi) */
.resume-modal-hint { font-size: 12px; color: var(--tm); line-height: 1.5; margin: 0 0 0.9rem; }
.resume-field { margin-bottom: 0.85rem; }
.resume-field label { display: block; font-size: 11px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.3rem; }
.resume-ta { width: 100%; resize: vertical; font-family: 'Inter', sans-serif; font-size: 12.5px; line-height: 1.5; }
.resume-penunjang { margin: 0; padding-left: 1.1rem; font-size: 12px; color: var(--tm); }
.resume-penunjang li { margin-bottom: 2px; }
.resume-head-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem 1rem; padding: 0.7rem 0.85rem; margin-bottom: 1rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; }
.resume-head-grid > div { display: flex; flex-direction: column; gap: 1px; }
.rh-label { font-size: 9.5px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: 0.04em; }
.rh-val { font-size: 12.5px; color: var(--td); font-weight: 600; }
.resume-kontrol { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }

.penunjang-modal-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 1rem; }
.chip-lainnya { border-style: dashed; }
.chip-lainnya.ordered { border-color: var(--ga); background: var(--gl); }
.custom-penunjang { display: flex; gap: 8px; align-items: center; margin-bottom: 1rem; }
.cp-input { flex: 1; min-width: 0; height: 34px; border: 1.5px solid var(--gb); border-radius: 8px; padding: 0 11px; font-size: 12.5px; font-family: 'Inter', sans-serif; color: var(--td); background: var(--bs); outline: none; }
.cp-input:focus { border-color: var(--ga); background: #fff; }
.cp-cat { max-width: 150px; }
.penunjang-modal-chip { padding: 0.65rem 0.8rem; border: 1.5px solid var(--gb); border-radius: 10px; cursor: pointer; background: var(--bs); transition: all .14s; position: relative; }
.penunjang-modal-chip:hover { border-color: var(--ga); background: var(--gl); }
.penunjang-modal-chip.ordered { border-color: var(--ga); background: var(--gl); cursor: default; }
.penunjang-modal-chip-name { font-size: 12px; font-weight: 500; color: var(--td); line-height: 1.35; margin-bottom: 3px; }
.penunjang-modal-chip-cat { font-size: 9.5px; color: var(--tu); }
.penunjang-modal-chip-check { display: flex; align-items: center; gap: 4px; margin-top: 6px; font-size: 9.5px; font-weight: 700; color: var(--ga); }
.penunjang-modal-chip-check svg { width: 11px; height: 11px; fill: none; stroke: var(--ga); stroke-width: 2.5; stroke-linecap: round; }
.penunjang-modal-ordered { padding: 0.75rem; background: var(--gl); border: 1px solid rgba(31,125,74,.2); border-radius: 9px; }
.penunjang-modal-ordered-title { font-size: 10px; font-weight: 700; color: var(--ga); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.45rem; }
.penunjang-modal-tags { display: flex; flex-wrap: wrap; gap: 5px; }
.penunjang-modal-tag { display: inline-flex; align-items: center; gap: 5px; background: var(--bc); border: 1px solid var(--sbd); color: var(--st); font-size: 11px; font-weight: 500; padding: 3px 10px; border-radius: 20px; }
.penunjang-modal-tag button { background: none; border: none; cursor: pointer; color: var(--ga); font-size: 14px; line-height: 1; padding: 0; opacity: .7; }
.penunjang-modal-tag button:hover { opacity: 1; }

/* Penunjang ordered list */
.penunjang-list { display: flex; flex-direction: column; gap: 4px; padding: 0.65rem 0.85rem; }
.penunjang-item { display: flex; align-items: center; gap: 8px; padding: 6px 10px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 8px; }
.penunjang-item-icon { width: 14px; height: 14px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.penunjang-item-info { flex: 1; min-width: 0; }
.penunjang-item-name { font-size: 11.5px; font-weight: 500; color: var(--td); }
.penunjang-item-cat { font-size: 9.5px; color: var(--tu); margin-top: 1px; }
.penunjang-item-status { font-size: 9px; font-weight: 700; padding: 2px 7px; border-radius: 4px; flex-shrink: 0; }
.penunjang-item-status.ordered { background: var(--ib); color: var(--it); }
.penunjang-item-status.done { background: var(--sb); color: var(--st); }
.penunjang-item-del { width: 20px; height: 20px; border-radius: 4px; border: 1px solid var(--gb); background: none; cursor: pointer; color: var(--tu); font-size: 14px; display: flex; align-items: center; justify-content: center; line-height: 1; flex-shrink: 0; }
.penunjang-item-del:hover { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.penunjang-empty { font-size: 11px; color: var(--th); text-align: center; padding: 0.75rem; font-style: italic; }

/* SOAP mini sidebar */
.soap-mini { padding: 0.65rem 0.9rem; display: flex; flex-direction: column; gap: 0.5rem; }
.soap-mini-row { display: flex; gap: 8px; }
.soap-mini-key { font-size: 11.5px; font-weight: 700; width: 16px; flex-shrink: 0; padding-top: 1px; }
.soap-mini-key.s { color: #1d4ed8; }
.soap-mini-key.o { color: var(--ga); }
.soap-mini-key.a { color: #7e22ce; }
.soap-mini-key.p { color: #b45309; }
.soap-mini-val { font-size: 11px; color: var(--td); line-height: 1.5; max-height: 56px; overflow: hidden; word-break: break-word; }
.soap-mini-val.empty { color: var(--th); font-style: italic; }
.soap-mini-footer { padding: 0.55rem 0.65rem; border-top: 1px solid var(--gb); }

/* Hasil Penunjang sidebar card */
.hasil-list { display: flex; flex-direction: column; }
.hasil-item { padding: 0.6rem 0.85rem; border-bottom: 1px solid var(--gb); }
.hasil-item:last-child { border-bottom: none; }
.hasil-item.pending { opacity: .7; }
.hasil-meta { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 4px; }
.hasil-name { font-size: 11.5px; font-weight: 600; color: var(--td); display: inline-flex; align-items: center; gap: 5px; }
.hasil-attach-ico { width: 11px; height: 11px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.hasil-prg-badge { font-size: 8.5px; font-weight: 700; padding: 1px 5px; border-radius: 4px; background: #dbeafe; color: #1e40af; }
.hasil-date { font-size: 9.5px; color: var(--tu); flex-shrink: 0; }
.hasil-result { font-size: 11px; color: var(--tm); line-height: 1.45; }
.hasil-pending-badge { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 4px; background: var(--ib); color: var(--it); flex-shrink: 0; }
.hasil-pending-title { font-size: 9.5px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: 0.06em; padding: 0.4rem 0.85rem 0; border-top: 1px dashed var(--gb); }

/* Lihat button */
.btn-lihat { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; font-size: 10px; font-weight: 600; border-radius: 5px; border: 1px solid var(--ga); background: var(--gl); color: var(--ga); cursor: pointer; font-family: 'Inter',sans-serif; transition: all .12s; flex-shrink: 0; }
.btn-lihat:hover { background: var(--ga); color: #fff; }
.btn-lihat svg { width: 9px; height: 9px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.hasil-meta-right { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }

/* Modal hasil detail */
.hasil-modal-date { display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: var(--tu); margin-bottom: 0.9rem; }
.hasil-modal-date svg { width: 13px; height: 13px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.hasil-modal-label { font-size: 10px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.45rem; }
.hasil-modal-result { background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 0.85rem 1rem; font-size: 13px; color: var(--td); line-height: 1.65; white-space: pre-wrap; margin-bottom: 0.85rem; }
.hasil-modal-label:not(:first-of-type) { margin-top: 0.2rem; }
.hasil-modal-badge { font-size: 9.5px; font-weight: 700; padding: 1px 7px; border-radius: 4px; margin-left: auto; }
.hasil-modal-badge.prg { background: #dbeafe; color: #1e40af; }
.hasil-bio-table { width: 100%; border-collapse: collapse; margin-bottom: 0.85rem; font-size: 12px; }
.hasil-bio-table th, .hasil-bio-table td { border: 1px solid var(--gb); padding: 5px 9px; text-align: left; }
.hasil-bio-table th { background: var(--bs); font-size: 10.5px; font-weight: 700; color: var(--tm); }
.hasil-bio-table td:first-child { color: var(--tu); font-weight: 600; }
.hasil-bio-table td { color: var(--td); font-variant-numeric: tabular-nums; }
.hasil-modal-attach { display: flex; flex-direction: column; gap: 8px; margin-bottom: 0.4rem; }
.hasil-modal-img { width: 100%; max-height: 360px; object-fit: contain; border: 1px solid var(--gb); border-radius: 9px; background: #000; cursor: zoom-in; }
.hasil-modal-file { display: inline-flex; align-items: center; gap: 7px; padding: 9px 12px; border: 1.5px solid var(--ga); border-radius: 9px; background: var(--gl); color: var(--ga); font-size: 12.5px; font-weight: 600; text-decoration: none; }
.hasil-modal-file svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.hasil-modal-openlink { font-size: 11px; color: var(--ga); text-decoration: underline; align-self: flex-start; }

/* CONTENT */
.rmc { flex: 1; overflow-y: auto; padding: 1rem 1.25rem; background: var(--bg); }
.rmc::-webkit-scrollbar { width: 4px; }
.rmc::-webkit-scrollbar-thumb { background: var(--gb); border-radius: 2px; }
.af { display: flex; flex-direction: column; gap: 1rem; }
/* Wrapper card lockable (Tab Tindakan & SOAP): beri jarak antar-card seperti .af,
   karena div pane-locked memutus gap dari .af. */
.tab3-stack { display: flex; flex-direction: column; gap: 1rem; }

/* Read-only banner */
.readonly-banner {
  display: flex; align-items: center; gap: 8px;
  background: var(--ib); border: 1px solid var(--ibd); border-radius: 9px;
  padding: 0.55rem 0.85rem; font-size: 11.5px; color: var(--it); font-weight: 500;
}
.readonly-banner svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.ro-badge {
  font-size: 9px; font-weight: 700; padding: 2px 7px; border-radius: 4px;
  background: var(--ib); color: var(--it); border: 1px solid var(--ibd); letter-spacing: 0.04em;
}

/* CARDS */
.card { background: var(--bc); border-radius: 12px; border: 1px solid var(--gb); overflow: hidden; }
/* Card yang menampung dropdown absolute (search tindakan): jangan clip & angkat
   ke atas card berikutnya supaya dropdown mengambang, bukan terpotong/tertutup. */
.card-dropdown-host { overflow: visible; position: relative; z-index: 5; }
.ch {
  padding: 0.7rem 1rem; border-bottom: 1px solid var(--gb);
  display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
}
.cht {
  font-size: 12.5px; font-weight: 600; color: var(--td);
  display: flex; align-items: center; gap: 6px;
}
.cht svg { width: 14px; height: 14px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.cb { padding: 0.9rem 1rem; }
.cb.stack { display: flex; flex-direction: column; gap: 0.65rem; }
.card-counter { font-size: 11px; font-weight: 500; color: var(--tu); }
.card-hint { font-size: 10px; color: var(--th); font-style: italic; }

.fg { display: flex; flex-direction: column; gap: 4px; }
.fl { font-size: 10px; font-weight: 600; color: var(--tm); letter-spacing: 0.06em; text-transform: uppercase; }
.req { color: var(--et); }
.g4 { display: grid; grid-template-columns: repeat(4,1fr); gap: 0.5rem; }
.g3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 0.5rem; }
.g2 { display: grid; grid-template-columns: repeat(2,1fr); gap: 0.5rem; }

.ro-field {
  background: var(--bs); border: 1.5px solid var(--gb); border-radius: 7px;
  padding: 7px 10px; font-size: 12.5px; text-align: center;
  font-weight: 600; color: var(--td); min-height: 32px;
}
.ro-field.left { text-align: left; font-weight: 500; }
.ro-field.strong { font-size: 14.5px; font-weight: 700; }
.ro-field.success { color: var(--ga); }
.ro-field.warn { color: var(--wt); border-color: rgba(234,88,12,0.3); background: var(--wb); }

.form-input,
.form-textarea,
.form-select {
  width: 100%;
  background: var(--bs);
  border: 1.5px solid var(--gb);
  border-radius: 8px;
  font-family: 'Inter', sans-serif;
  font-size: 12.5px;
  color: var(--td);
  outline: none;
  padding: 8px 11px;
  transition: border-color 0.15s, background 0.15s;
}
.form-textarea { line-height: 1.5; min-height: 50px; resize: vertical; }
.form-select { cursor: pointer; }
.form-input:focus,
.form-textarea:focus,
.form-select:focus { border-color: var(--ga); background: #fff; box-shadow: 0 0 0 3px rgba(31,125,74,0.09); }

/* Select value colors */
.form-select.seg-ok { border-color: rgba(31,125,74,0.4); color: var(--st); }
.form-select.seg-warn { border-color: rgba(234,88,12,0.4); color: var(--wt); }
.form-select.seg-muted { color: var(--th); }

/* OD/OS eye cards */
.odos { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.eyec { background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 0.65rem 0.75rem; }
.eyeh { display: flex; align-items: center; gap: 6px; margin-bottom: 0.5rem; }
.elbl { width: 28px; height: 22px; border-radius: 5px; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
.el-od { background: var(--ib); color: var(--it); }
.el-os { background: var(--pb); color: var(--pt); }
.esub { font-size: 11px; color: var(--tu); font-weight: 500; }
.ef { margin-bottom: 0.4rem; }
.ef label { font-size: 9.5px; font-weight: 600; color: var(--tm); text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 3px; }
.divider { height: 1px; background: var(--gb); margin: 0.4rem 0; }

/* SEGMEN TABLE */
.seg-table { display: flex; flex-direction: column; gap: 0; }
.seg-head {
  display: grid; grid-template-columns: 100px 1fr 1fr; gap: 0.5rem;
  padding-bottom: 0.5rem; margin-bottom: 0.35rem;
  border-bottom: 1px solid var(--gb);
}
.seg-eye-lbl {
  display: flex; align-items: center; gap: 6px;
  font-size: 11px; font-weight: 600; color: var(--td);
}
.seg-row {
  display: grid; grid-template-columns: 100px 1fr 1fr; gap: 0.5rem;
  align-items: center; padding: 0.3rem 0;
  border-bottom: 1px solid var(--bs);
}
.seg-row:last-child { border-bottom: none; }
.seg-label { font-size: 11.5px; font-weight: 500; color: var(--td); }

/* Segmen 2 kolom + kompak */
.seg-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.seg-2col .seg-head,
.seg-2col .seg-row { grid-template-columns: 64px 1fr 1fr; gap: 0.35rem; }
.seg-2col .seg-row { padding: 0.2rem 0; }
.seg-2col .seg-eye-lbl { font-size: 10px; gap: 4px; }
.seg-2col .seg-eye-lbl .elbl { width: 24px; height: 19px; font-size: 10px; }
.seg-row .form-select { padding: 5px 7px; font-size: 11.5px; }
@media (max-width: 1180px) { .seg-2col { grid-template-columns: 1fr; } }

/* Tag "otomatis" pada label field */
.auto-tag {
  font-size: 9px; font-weight: 600; color: var(--ga); text-transform: none;
  letter-spacing: 0; background: var(--gl); border-radius: 4px; padding: 1px 6px; margin-left: 5px;
}
/* Penanda field SOAP yang sudah diedit manual (auto-sync berhenti) + tombol sync ulang. */
.manual-tag {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 9px; font-weight: 600; color: #b45309; text-transform: none;
  letter-spacing: 0; background: #fef3c7; border-radius: 4px; padding: 1px 6px; margin-left: 5px;
}
.resync-btn {
  font-size: 9px; font-weight: 700; color: #1763d4; background: #fff;
  border: 1px solid #c7d6f0; border-radius: 4px; padding: 0 5px; cursor: pointer; line-height: 1.5;
}
.resync-btn:hover { background: #1763d4; color: #fff !important; border-color: #1763d4; }

/* Layout 2 kolom: KIRI = SOAP (vertikal), KANAN = ICD-10 + ICD-9 + Planning.
   align-items:stretch (default) → kedua kolom sama tinggi, margin bawah sejajar. */
.soap-dx-grid {
  display: grid; grid-template-columns: 1.35fr 1fr; gap: 1rem; align-items: stretch;
}
/* SOAP vertikal (S → O → A → P ke bawah) di kolom kiri */
.soap-stack { display: flex; flex-direction: column; gap: 0.7rem; }
.soap-cell { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.soap-cell .form-textarea { resize: vertical; }
.soap-fl { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.soap-letter {
  width: 20px; height: 20px; border-radius: 5px; flex-shrink: 0;
  font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center;
}
.soap-letter.s { background: #dbeafe; color: #1e40af; }
.soap-letter.o { background: var(--sb); color: var(--st); }
.soap-letter.a { background: var(--wb); color: var(--wt); }
.soap-letter.p { background: var(--pb); color: var(--pt); }
@media (max-width: 1024px) { .soap-dx-grid { grid-template-columns: 1fr; } }

/* ── ICD-10 + ICD-9 (kolom kanan, bertumpuk vertikal) ────────────────────── */
.dx-grid { display: flex; flex-direction: column; gap: 1rem; }

/* DIAGNOSIS */
.dx-section { margin-bottom: 0.75rem; }
.dx-section-title { display: flex; align-items: center; gap: 7px; font-size: 11px; font-weight: 600; color: var(--td); margin-bottom: 0.5rem; }
.dx-divider { height: 1px; background: var(--gb); margin: 0.75rem 0; }
.dx-search { margin-bottom: 0.4rem; font-size: 12px; }
.dx-results { display: flex; flex-direction: column; gap: 2px; max-height: 180px; overflow-y: auto; margin-bottom: 0.25rem; }
.dx-results::-webkit-scrollbar { width: 3px; }
.dx-results::-webkit-scrollbar-thumb { background: var(--gb); }
.dx-result-item { display: flex; align-items: center; gap: 7px; padding: 5px 8px; border-radius: 6px; cursor: pointer; transition: background .12s; }
.dx-result-item:hover { background: var(--gl); }
.dx-result-item.sel { background: var(--gl); }
.dx-result-name { flex: 1; font-size: 11.5px; color: var(--td); min-width: 0; }
.dx-result-check { width: 13px; height: 13px; fill: none; stroke: var(--ga); stroke-width: 2.5; stroke-linecap: round; flex-shrink: 0; }
.dx-code { font-weight: 700; color: var(--ga); font-size: 10px; white-space: nowrap; }
.dx-list { display: flex; flex-direction: column; gap: 4px; }
.dx-row { display: flex; align-items: center; gap: 8px; padding: 7px 11px; background: var(--bs); border: 1px solid var(--gb); border-radius: 7px; }
.dx-utama { border-color: rgba(31,125,74,0.3); background: var(--gl); }
.dx-type { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 4px; letter-spacing: 0.05em; text-transform: uppercase; flex-shrink: 0; }
.dx-type.primary { background: var(--gd); color: #fff; }
.dx-type.secondary { background: var(--gb); color: var(--tm); }
.dx-type.freetext { background: var(--ga); color: #fff; }
.dx-name { flex: 1; font-size: 12px; color: var(--td); }
.dx-remove { background: none; border: none; cursor: pointer; color: var(--tu); padding: 2px; display: flex; }
.dx-remove svg { width: 13px; height: 13px; }
.dx-remove:hover { color: var(--et); }
.dx-empty { text-align: center; padding: 1.4rem 1rem; font-size: 11.5px; color: var(--th); }
.dx-empty-sm { font-size: 11px; color: var(--th); font-style: italic; padding: 0.35rem 0; }
.icd9-badge { font-size: 8px; font-weight: 700; padding: 1px 5px; border-radius: 3px; background: var(--pb); color: var(--pt); letter-spacing: 0.04em; }

/* TINDAKAN / RX */
/* Tabel obat yang sudah diresepkan — selaras dengan .tindakan-table */
.rx-table { border: 1px solid var(--gb); border-radius: 9px; overflow: hidden; }
.rx-thead, .rx-trow {
  display: grid;
  grid-template-columns: 1fr 52px 90px 90px 78px 56px 28px;
  gap: 0.5rem; align-items: center;
}
.rx-thead {
  padding: 0.45rem 0.85rem; background: var(--bs);
  font-size: 9.5px; font-weight: 700; color: var(--tu);
  text-transform: uppercase; letter-spacing: 0.05em;
  border-bottom: 1px solid var(--gb);
}
.rx-trow {
  padding: 0.6rem 0.85rem; border-bottom: 1px solid var(--gb);
  transition: background 0.1s;
}
.rx-trow:last-child { border-bottom: none; }
.rx-trow:hover { background: var(--bs); }
.rx-cell-name {
  font-size: 12px; font-weight: 500; color: var(--td);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0;
}
.rx-cell {
  font-size: 11.5px; color: var(--tu);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

/* PLANNING */
/* Planning di kolom kanan (sempit) → 4 opsi kompak 2×2: Pulang | Rawat Inap | Bedah | Rujuk.
   Tiap opsi vertikal-center (icon di atas, judul di bawah), subtitle disembunyikan
   agar muat dalam grid sempit. */
.plan-opts { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; margin-bottom: 0.75rem; }
.plan-opt {
  display: flex; flex-direction: column; align-items: center; gap: 5px;
  padding: 0.6rem 0.4rem; border: 1.5px solid var(--gb); border-radius: 9px;
  background: var(--bs); cursor: pointer; transition: all 0.15s; text-align: center; position: relative;
}
.plan-opt:hover { border-color: var(--ga); background: var(--gl); }
.plan-opt.selected { border-color: var(--ga); background: var(--gl); }
.plan-icon { width: 28px; height: 28px; border-radius: 7px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
.plan-icon.pulang { background: var(--sb); }
.plan-icon.ranap { background: #ccfbf1; }
.plan-icon.bedah { background: var(--wb); }
.plan-icon.rujuk { background: var(--ib); }
.plan-icon svg { width: 15px; height: 15px; fill: none; stroke-width: 2; stroke-linecap: round; }
.plan-icon.pulang svg { stroke: var(--st); }
.plan-icon.ranap svg { stroke: #0f766e; }
.plan-icon.bedah svg { stroke: var(--wt); }
.plan-icon.rujuk svg { stroke: var(--it); }
.plan-body { min-width: 0; }
.plan-title { font-size: 11px; font-weight: 600; color: var(--td); line-height: 1.2; }
.plan-sub { display: none; }
/* Centang di pojok kanan-atas opsi terpilih */
.plan-check { position: absolute; top: 4px; right: 4px; width: 15px; flex-shrink: 0; }
.plan-check svg { width: 15px; height: 15px; fill: none; stroke: var(--ga); stroke-width: 2.5; stroke-linecap: round; }
.surgery-fields, .plan-fields { background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 0.85rem 1rem; margin-top: 0; }
.surgery-hint { font-size: 10.5px; color: var(--tu); margin-top: 4px; }
.surgery-hint.warn { color: var(--wt); }
/* Toggle lokasi pelaksanaan: Ruang Bedah vs Ruang Tindakan (laser) */
.loc-toggle { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.loc-opt { display: flex; align-items: flex-start; gap: 8px; padding: 9px 11px; border: 1px solid var(--bd, #cbd5e1); border-radius: 9px; cursor: pointer; background: #fff; transition: border-color .15s, background .15s; }
.loc-opt:hover { border-color: #94a3b8; }
.loc-opt.active { border-color: #1FAAE0; background: #f0f9ff; }
.loc-opt input { width: 15px; height: 15px; margin-top: 2px; accent-color: #1FAAE0; flex-shrink: 0; }
.loc-opt span { display: flex; flex-direction: column; gap: 1px; }
.loc-opt b { font-size: 12.5px; color: #0E3A66; }
.loc-opt small { font-size: 10.5px; color: var(--tu, #64748b); }
/* Fase 8 — Rawat Inap (observasi) note + checkbox pre-op pada Bedah */
.ranap-note { display: flex; align-items: flex-start; gap: 8px; color: #0f766e; background: #f0fdfa; border-color: #99f6e4 !important; font-size: 12px; }
.ranap-note svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }
.ranap-check { display: flex; align-items: flex-start; gap: 8px; margin-top: 12px; padding: 10px 12px; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 9px; cursor: pointer; }
.ranap-check input { width: 16px; height: 16px; margin-top: 2px; accent-color: #0d9488; flex-shrink: 0; }
.ranap-check-tx { display: flex; flex-direction: column; gap: 2px; }
.ranap-check-tx b { color: #0f766e; font-size: 12.5px; }
.ranap-check-tx small { color: #475569; font-size: 11px; }
.form-select:disabled { background: var(--bi); cursor: not-allowed; opacity: .7; }
.fl-opt { font-weight: 400; color: var(--tu); font-size: 9.5px; }

/* Preview jadwal bedah per tanggal (Tab 4) */
.bedah-preview { margin-top: 0.65rem; padding: 0.55rem 0.7rem; background: #eef4ff; border: 1px solid #c7dbff; border-radius: 8px; }
.bedah-preview-loading { font-size: 11px; color: #1763d4; }
.bedah-preview-head { display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: #1a1a1a; }
.bedah-preview-head svg { width: 14px; height: 14px; flex-shrink: 0; color: #1763d4; }
.bedah-preview-head b { color: #1763d4; }
.bedah-preview-slots { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 7px; }
.bedah-slot-chip { font-size: 10.5px; font-weight: 600; color: #1763d4; background: #fff; border: 1px solid #c7dbff; border-radius: 6px; padding: 2px 7px; }

/* RUJUK: mode internal / eksternal */
.rujuk-mode-tabs { display: flex; gap: 6px; margin-bottom: 0.75rem; }
.rujuk-mode-tab { flex: 1; padding: 0.5rem 0.6rem; font-size: 12px; font-weight: 600; color: #000; background: var(--bs); border: 2px solid var(--gb); border-radius: 8px; cursor: pointer; transition: all 0.15s; }
.rujuk-mode-tab:hover { border-color: var(--ga); }
.rujuk-mode-tab.active { border-color: #1763d4; background: #eef4ff; color: #1763d4; }
.rujuk-mode-tab .rmt-sub { font-weight: 400; font-size: 10px; opacity: 0.8; }
.rujuk-internal-box { display: flex; flex-direction: column; gap: 0.65rem; }
.rujuk-internal-preview { padding: 0.55rem 0.7rem; background: #eef4ff; border: 1px solid #c7dbff; border-radius: 8px; font-size: 11.5px; color: #1a1a1a; line-height: 1.5; }
.rujuk-internal-preview b { color: #1763d4; }
.rip-badge { display: inline-block; font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 5px; margin-right: 6px; }
.rip-today { background: var(--ga); color: #fff; }
.rip-later { background: #c47f17; color: #fff; }
.rujuk-internal-actions { display: flex; align-items: center; gap: 0.7rem; flex-wrap: wrap; }
.btn-rujuk-internal { padding: 0.5rem 1rem; font-size: 12.5px; font-weight: 700; color: #fff !important; background: #1763d4; border: none; border-radius: 8px; cursor: pointer; transition: opacity 0.15s; }
.btn-rujuk-internal:hover:not(:disabled) { opacity: 0.9; }
.btn-rujuk-internal:disabled { opacity: 0.5; cursor: not-allowed; }
.ri-hint { font-size: 10.5px; color: var(--tu); }
.rk-search { display: flex; gap: 6px; }
.rk-search .form-input { flex: 1; }
.rk-search-btn { flex-shrink: 0; padding: 0 0.9rem; font-size: 12px; font-weight: 700; color: #fff !important; background: #1763d4; border: none; border-radius: 8px; cursor: pointer; }
.rk-search-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.rk-result-list { margin-top: 5px; border: 1px solid var(--gb); border-radius: 8px; max-height: 160px; overflow-y: auto; background: var(--bs); }
.rk-result-item { padding: 0.45rem 0.7rem; font-size: 12px; color: #000; cursor: pointer; border-bottom: 1px solid var(--gl); }
.rk-result-item:last-child { border-bottom: none; }
.rk-result-item:hover { background: #eef4ff; }
.rk-result-item .rk-code { color: var(--tu); font-size: 10.5px; margin-left: 4px; }
.rk-picked { margin-top: 5px; font-size: 11.5px; font-weight: 600; color: var(--ga); }
.sk-panel { margin-top: 0.6rem; padding: 0.5rem 0.7rem; border-radius: 8px; font-size: 11.5px; line-height: 1.5; color: #1a1a1a; }
.sk-panel b { color: #1763d4; }
.sk-info { background: #eef4ff; border: 1px solid #c7dbff; }
.sk-ok { background: #eafaf0; border: 1px solid #b6e6c8; }
.sk-fail { background: #fdeeee; border: 1px solid #f3c2c2; }
.sk-badge { display: inline-block; font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 5px; margin-right: 6px; color: #fff; }
.sk-badge-info { background: #1763d4; }
.sk-badge-ok { background: var(--ga); }
.sk-badge-fail { background: #c0392b; }
.sk-note { color: var(--tu); }

/* DIGITAL SIGNATURE */
.sig-action-wrap { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.sig-pin-inline { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px dashed var(--gb); }
.sig-pin-inline .sig-pin-input { width: 140px; flex-shrink: 0; }
.sig-pin-feedback { display: flex; align-items: center; margin-top: 5px; padding-left: 2px; }
.sig-pin-wrap { display: flex; flex-direction: column; gap: 0.85rem; }
.sig-doctor-info { display: flex; align-items: center; gap: 0.75rem; padding: 0.7rem 0.9rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; }
.sig-pin-form { display: flex; flex-direction: column; gap: 0.45rem; }
.sig-pin-row { display: flex; gap: 0.5rem; align-items: center; }
.sig-pin-input { width: 120px; flex-shrink: 0; font-size: 18px; letter-spacing: 6px; text-align: center; }
.sig-pin-input.pin-error { border-color: var(--et); background: var(--eb); }
.sig-pin-error { display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--et); font-weight: 500; }
.sig-pin-error svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.sig-pin-hint { font-size: 10.5px; color: var(--tu); font-style: italic; }
.sig-role { font-size: 10.5px; color: var(--tu); margin-top: 1px; }
.sig-done {
  display: flex; align-items: center; gap: 0.85rem;
  background: var(--sb); border: 1px solid var(--sbd); border-radius: 9px;
  padding: 0.85rem 1rem;
}
.sig-av-success { background: var(--st) !important; }
.sig-avatar {
  width: 40px; height: 40px; border-radius: 50%; background: var(--st);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.sig-avatar svg { width: 20px; height: 20px; fill: none; stroke: #fff; stroke-width: 2; stroke-linecap: round; }
.sig-name { font-size: 13px; font-weight: 600; color: var(--td); }
.sig-ts { font-size: 10.5px; color: var(--tu); margin-top: 2px; }

/* ── Tarif collapsible list ──────────────────────────────────────────────── */
.tarif-toggle-row { display: flex; align-items: center; gap: 0.65rem; margin-bottom: 0.55rem; }
.tarif-toggle-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border: 1.5px solid var(--gb); border-radius: 8px; background: var(--bs); font-size: 12.5px; font-weight: 500; color: var(--tm); cursor: pointer; font-family: 'Inter', sans-serif; transition: all .13s; }
.tarif-toggle-btn:hover { border-color: var(--ga); color: var(--td); background: var(--gl); }
.tarif-toggle-btn.open { border-color: var(--ga); background: var(--gl); color: var(--td); }
.tarif-toggle-btn svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.chevron-sm { transition: transform .18s; }
.chevron-sm.up { transform: rotate(180deg); }
.tarif-sel-count { font-size: 11px; font-weight: 600; color: var(--ga); }

.tarif-list-panel { background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 0.65rem; margin-bottom: 0.65rem; }
.tarif-list { display: flex; flex-direction: column; gap: 3px; max-height: 260px; overflow-y: auto; }
.tarif-list-item { display: flex; align-items: center; gap: 8px; padding: 7px 10px; border-radius: 7px; cursor: pointer; transition: background .12s; }
.tarif-list-item:hover { background: var(--gl); }
.tarif-list-item.in-list { background: var(--gl); }
.tarif-list-name { flex: 1; font-size: 12px; font-weight: 500; color: var(--td); min-width: 0; }
.tarif-list-price { font-size: 11.5px; font-weight: 600; color: var(--td); white-space: nowrap; font-variant-numeric: tabular-nums; }
.tarif-list-price em { font-style: normal; font-size: 9.5px; color: var(--tu); font-weight: 400; margin-left: 2px; }
.tarif-list-icon { width: 14px; height: 14px; flex-shrink: 0; fill: none; stroke-width: 2; stroke-linecap: round; }
.tarif-list-icon.add { stroke: var(--ga); }
.tarif-list-icon.check { stroke: var(--st); stroke-width: 2.5; }

/* ── Tindakan search-driven picker ───────────────────────────────────────── */
/* Paket Pemeriksaan bar (Tab Tindakan) */
.exam-pkg-bar { background: var(--bs); border: 1px solid var(--gb); border-radius: 10px; padding: 0.7rem 0.85rem; margin-bottom: 0.75rem; }
.exam-pkg-row { display: flex; align-items: center; gap: 0.5rem; }
.exam-pkg-icon { width: 18px; height: 18px; flex-shrink: 0; fill: none; stroke: var(--ga); stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.exam-pkg-select { flex: 1; }
.exam-pkg-apply { padding: 8px 16px; border-radius: 8px; border: 1px solid var(--ga); background: var(--ga); color: #fff; font-size: 13px; font-weight: 500; cursor: pointer; white-space: nowrap; }
.exam-pkg-apply:disabled { opacity: 0.5; cursor: not-allowed; }
.exam-pkg-remove { padding: 8px 12px; border-radius: 8px; border: 1px solid var(--ebd); background: var(--eb); color: var(--et); font-size: 12.5px; cursor: pointer; }
.exam-pkg-info { margin-top: 0.55rem; font-size: 12px; color: var(--tm); line-height: 1.5; }
.exam-pkg-info strong { color: var(--td); }
.exam-pkg-disc { color: var(--ga); font-weight: 600; }
.exam-pkg-note { display: block; color: var(--tu); font-size: 11px; margin-top: 2px; }

.tindakan-search-wrap { position: relative; margin-bottom: 0.75rem; }
.tindakan-search-field {
  position: relative; display: flex; align-items: center; gap: 8px;
  padding: 0 12px 0 36px; height: 38px;
  border: 1.5px solid var(--gb); border-radius: 9px;
  background: var(--bs); transition: border-color .13s, background .13s;
}
.tindakan-search-field:focus-within { border-color: var(--ga); background: var(--bc); }
.tindakan-search-icon {
  position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
  width: 14px; height: 14px; fill: none; stroke: var(--tu); stroke-width: 2; stroke-linecap: round;
}
.tindakan-search-input {
  flex: 1; min-width: 0; border: none !important; background: transparent !important;
  padding: 0 !important; height: auto !important; font-size: 13px; color: var(--td);
  outline: none; box-shadow: none !important;
}
.tindakan-search-input::placeholder { color: var(--th); }
.tindakan-search-clear {
  display: inline-flex; align-items: center; justify-content: center;
  width: 22px; height: 22px; border-radius: 50%; border: none;
  background: var(--gb); color: var(--tu); cursor: pointer; padding: 0;
  flex-shrink: 0; transition: background .12s;
}
.tindakan-search-clear:hover { background: var(--th); color: #fff; }
.tindakan-search-clear svg { width: 11px; height: 11px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; }
.tindakan-search-drop {
  position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 30;
  background: var(--bc); border: 1px solid var(--gb); border-radius: 9px;
  box-shadow: 0 8px 24px rgba(0,0,0,.10);
  max-height: 320px; overflow-y: auto; padding: 4px;
}
.tindakan-search-hint {
  padding: 7px 10px; font-size: 10.5px; color: var(--tu);
  border-top: 1px dashed var(--gb); text-align: center; font-style: italic;
}

/* ── E-Resep form grid (label di atas tiap kolom) ───────────────────────── */
.rx-form-grid {
  display: grid;
  grid-template-columns: minmax(0, 2.4fr) 70px minmax(0, 1fr) minmax(0, 1.1fr) minmax(0, 1fr) minmax(0, 1.3fr) 44px;
  gap: 8px;
  align-items: end;
  margin-bottom: 0.85rem;
  padding: 0.65rem 0.7rem;
  background: var(--bs);
  border: 1px solid var(--gb);
  border-radius: 9px;
}
.rx-fg { display: flex; flex-direction: column; min-width: 0; }
.rx-fl {
  font-size: 9.5px; font-weight: 700; color: var(--tu);
  text-transform: uppercase; letter-spacing: 0.04em;
  margin-bottom: 4px; padding: 0 2px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.rx-fg .form-input { width: 100%; height: 34px; font-size: 12px; }
.rx-fg-qty .form-input { text-align: center; }
.rx-add-btn {
  width: 100%; height: 34px; padding: 0;
  display: inline-flex; align-items: center; justify-content: center;
}
.rx-add-btn svg { width: 16px; height: 16px; }
@media (max-width: 1100px) {
  .rx-form-grid { grid-template-columns: minmax(0,1fr) minmax(0,1fr); }
  .rx-fg-name { grid-column: 1 / -1; }
  .rx-fg-btn { grid-column: 1 / -1; }
}

/* Picker obat dari inventori */
.rx-picker { position: relative; margin-bottom: 0.6rem; }
.rx-drop { margin-top: 4px; border: 1px solid var(--gb); border-radius: 9px; background: var(--bc); max-height: 240px; overflow-y: auto; box-shadow: 0 6px 18px rgba(0,0,0,.07); }
.rx-drop-item { display: flex; align-items: center; gap: 8px; padding: 8px 11px; cursor: pointer; border-bottom: 1px solid var(--gb); transition: background .12s; }
.rx-drop-item:last-child { border-bottom: none; }
.rx-drop-item:hover { background: var(--gl); }
.rx-drop-name { flex: 1; min-width: 0; font-size: 12px; font-weight: 600; color: var(--td); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rx-drop-meta { font-size: 10px; color: var(--tu); flex-shrink: 0; }
.rx-stok { font-weight: 600; }
.rx-stok.ok { color: #15803d; }
.rx-stok.zero { color: #b45309; }
.rx-drop-price { font-size: 11.5px; font-weight: 700; color: var(--ga); flex-shrink: 0; font-variant-numeric: tabular-nums; }
.rx-picked { display: flex; flex-direction: column; justify-content: center; padding: 4px 10px; background: var(--gl); border: 1px solid var(--sbd); border-radius: 8px; }
.rx-picked-name { font-size: 12px; font-weight: 600; color: var(--td); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rx-picked-meta { font-size: 9.5px; color: var(--ga); font-weight: 600; }

/* ── Locked state ───────────────────────────────────────────────────────── */
.pane-locked { pointer-events: none; opacity: 0.65; user-select: none; }

.lock-notice {
  display: flex; align-items: center; gap: 8px; padding: 9px 14px;
  background: #fef3c7; border: 1px solid #f59e0b; border-radius: 9px;
  font-size: 12px; font-weight: 500; color: #92400e;
}
.lock-notice svg { width: 14px; height: 14px; fill: none; stroke: #d97706; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.lock-notice-undo {
  margin-left: auto; padding: 3px 10px; font-size: 11px; font-weight: 600;
  border: 1.5px solid #d97706; border-radius: 6px; background: none;
  color: #92400e; cursor: pointer; font-family: 'Inter', sans-serif;
  white-space: nowrap;
}
.lock-notice-undo:hover { background: #fde68a; }

/* ── Penyelesaian RME: Tanda Tangan + Finalisasi dalam 1 card ────────────── */
.finalize-card { padding: 1rem 1.25rem; }
.finalize-row {
  display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
}
.finalize-info { display: flex; align-items: center; gap: 0.75rem; flex: 1; min-width: 220px; }
.finalize-status-ic { width: 22px; height: 22px; fill: none; stroke: var(--tu); stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.fin-title { font-size: 13px; font-weight: 700; color: var(--td); }
.fin-title.fin-ready { color: var(--td); }
.fin-ready ~ .fin-sub { color: var(--td); }
.finalize-row:has(.fin-ready) .finalize-status-ic { stroke: var(--ga); }
.fin-sub { font-size: 11px; color: var(--tu); margin-top: 2px; }
/* Dua tombol sejajar & seukuran */
.finalize-actions { display: flex; align-items: center; gap: 0.6rem; flex-shrink: 0; }
.finalize-btn { min-width: 150px; justify-content: center; white-space: nowrap; }
.finalize-btn.is-signed {
  color: var(--st); border-color: var(--sbd); background: var(--sb);
}
.finalize-btn.is-signed:hover { background: var(--sb); filter: brightness(0.97); }
.finalize-actions .btn-primary { box-shadow: 0 4px 14px rgba(31,125,74,.25); }
.finalize-actions .btn-primary:disabled { box-shadow: none; }
@media (max-width: 720px) {
  .finalize-actions { width: 100%; }
  .finalize-btn { flex: 1; }
}

/* Modal PIN tanda tangan */
.sig-modal-box { max-width: 380px; }
.sig-modal-body { display: flex; flex-direction: column; gap: 0.6rem; }
.sig-modal-body .sig-pin-input { width: 100%; }
.sig-modal-actions {
  display: flex; justify-content: flex-end; gap: 0.5rem;
  padding: 0.85rem 1.25rem; border-top: 1px solid var(--gb);
}

.fin-success {
  display: flex; align-items: center; gap: 10px;
  padding: 1rem 1.25rem; background: var(--sb);
  border: 1px solid var(--sbd); border-radius: 12px;
  font-size: 13px; font-weight: 600; color: var(--st);
}
.fin-success svg { width: 18px; height: 18px; fill: none; stroke: var(--st); stroke-width: 2.5; stroke-linecap: round; }

/* ── Undo signature button ───────────────────────────────────────────────── */
.undo-sig-btn { flex-shrink: 0; }
.undo-sig-btn svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

/* ── Floating Finalisasi button ──────────────────────────────────────────── */

.savedb {
  display: inline-flex; align-items: center; gap: 6px;
  background: var(--sb); color: var(--st); border: 1px solid var(--sbd);
  font-size: 11px; font-weight: 600; padding: 4px 11px; border-radius: 20px;
}
.savedb svg { width: 13px; height: 13px; }

/* FAB Finalisasi */
.rme { position: relative; }
.fab-container { position: absolute; bottom: 1.25rem; right: 1.25rem; z-index: 20; display: flex; align-items: center; gap: 0.6rem; pointer-events: none; }
.fab-container > * { pointer-events: all; }
.fab-hint { display: inline-flex; align-items: center; gap: 5px; background: var(--bc); border: 1px solid var(--gb); border-radius: 8px; padding: 5px 10px; font-size: 10px; color: var(--tu); box-shadow: 0 2px 8px rgba(0,0,0,.09); white-space: nowrap; }
.fab-hint svg { width: 12px; height: 12px; fill: none; stroke: var(--it); stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.fab-btn { display: inline-flex; align-items: center; gap: 7px; background: var(--gd); color: #fff; border: none; border-radius: 10px; padding: 0 20px; height: 44px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; box-shadow: 0 4px 18px rgba(31,125,74,.38); transition: all .15s; white-space: nowrap; }
.fab-btn:hover:not(:disabled) { background: var(--gm); box-shadow: 0 6px 22px rgba(31,125,74,.48); transform: translateY(-1px); }
.fab-btn:disabled { opacity: .6; cursor: not-allowed; box-shadow: none; transform: none; }
.fab-btn svg { width: 15px; height: 15px; fill: none; stroke: #fff; stroke-width: 2; stroke-linecap: round; }
.fab-success { position: absolute; bottom: 1.25rem; right: 1.25rem; z-index: 20; display: inline-flex; align-items: center; gap: 7px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 10px; padding: 10px 18px; font-size: 12.5px; font-weight: 600; box-shadow: 0 2px 10px rgba(0,0,0,.09); }
.fab-success svg { width: 15px; height: 15px; }
.fab-hint-fade-enter-active, .fab-hint-fade-leave-active { transition: opacity .2s, transform .2s; }
.fab-hint-fade-enter-from, .fab-hint-fade-leave-to { opacity: 0; transform: translateX(8px); }

/* BUTTONS */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  padding: 0 16px; height: 38px; border-radius: 9px;
  font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 500;
  cursor: pointer; transition: all 0.15s; border: 1.5px solid transparent;
}
.btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover:not(:disabled) { background: var(--gm); }
.btn-primary:disabled { background: var(--th); cursor: not-allowed; }
.btn-success { background: var(--ga); color: #fff; border-color: var(--ga); }
.btn-success:hover { background: var(--gm); }
.btn-secondary { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-secondary:hover { border-color: var(--ga); color: var(--td); background: var(--gl); }
.btn-sm { height: 28px; padding: 0 10px; font-size: 11px; }
.btn-lg { height: 46px; padding: 0 22px; font-size: 14px; font-weight: 600; }

.sp {
  width: 14px; height: 14px; border-radius: 50%;
  border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff;
  animation: spin 0.7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* TOAST */
.toast-wrap {
  position: fixed; top: 1rem; right: 1rem; z-index: 999;
  display: flex; flex-direction: column; gap: 6px; pointer-events: none;
}
.toast {
  padding: 9px 13px; border-radius: 10px; font-size: 12px; font-weight: 500;
  border: 1px solid; pointer-events: all; min-width: 230px; max-width: 320px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08); animation: slideInRight 0.3s ease;
}
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }
@keyframes slideInRight { from { opacity:0; transform:translateX(16px); } to { opacity:1; transform:translateX(0); } }

/* TARIF TYPE BADGE */
.tarif-type-badge {
  font-size: 9px; font-weight: 700; padding: 2px 7px; border-radius: 4px; letter-spacing: 0.04em;
}
.tarif-type-badge.bpjs { background: #dbeafe; color: #1e40af; }
.tarif-type-badge.umum { background: var(--gl); color: var(--td); }

/* TARIF GRID */
.tarif-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.45rem;
  margin-bottom: 0.75rem;
  max-height: 220px;
  overflow-y: auto;
  padding-right: 2px;
}
.tarif-grid::-webkit-scrollbar { width: 3px; }
.tarif-grid::-webkit-scrollbar-thumb { background: var(--gb); }
.tarif-chip {
  border: 1.5px solid var(--gb); border-radius: 9px;
  padding: 0.55rem 0.7rem; cursor: pointer;
  background: var(--bs); transition: all 0.14s;
}
.tarif-chip:hover { border-color: var(--ga); background: var(--gl); }
.tarif-chip.in-list { border-color: var(--ga); background: var(--gl); }
.tarif-chip-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 3px; }
.tarif-kode { font-size: 9.5px; font-weight: 700; color: var(--ga); letter-spacing: 0.03em; }
.tarif-kat { font-size: 8px; font-weight: 700; padding: 1px 5px; border-radius: 3px; letter-spacing: 0.03em; }
.tarif-kat.jm { background: var(--ib); color: var(--it); }
.tarif-kat.td { background: var(--gl); color: var(--td); border: 1px solid rgba(31,125,74,0.2); }
.tarif-nama { font-size: 11px; font-weight: 500; color: var(--td); margin-bottom: 5px; line-height: 1.35; }
.tarif-harga { font-size: 11.5px; font-weight: 700; color: var(--td); }
.tarif-sat { font-size: 9px; font-weight: 400; color: var(--tu); }
.tarif-empty { grid-column: span 3; text-align: center; padding: 1rem; font-size: 11px; color: var(--th); }

/* TINDAKAN TABLE */
.tindakan-table {
  border: 1px solid var(--gb); border-radius: 9px; overflow: hidden;
}
.tindakan-thead {
  display: grid;
  grid-template-columns: 1fr 130px 90px 130px 28px;
  gap: 0.5rem;
  padding: 0.45rem 0.85rem;
  background: var(--bs);
  font-size: 9.5px; font-weight: 700; color: var(--tu);
  text-transform: uppercase; letter-spacing: 0.05em;
  border-bottom: 1px solid var(--gb);
}
.tindakan-row {
  display: grid;
  grid-template-columns: 1fr 130px 90px 130px 28px;
  gap: 0.5rem;
  align-items: center;
  padding: 0.6rem 0.85rem;
  border-bottom: 1px solid var(--gb);
  transition: background 0.1s;
}
.tindakan-row:last-child { border-bottom: none; }
.tindakan-row:hover { background: var(--bs); }
.tindakan-info { display: flex; align-items: center; gap: 7px; min-width: 0; }
.tindakan-nama { font-size: 12px; color: var(--td); font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tindakan-rate { font-size: 11.5px; color: var(--tu); font-variant-numeric: tabular-nums; }
.tindakan-qty { display: flex; align-items: center; gap: 6px; }
.qty-btn {
  width: 22px; height: 22px; border-radius: 5px; border: 1.5px solid var(--gb);
  background: var(--bs); cursor: pointer; font-size: 15px; line-height: 1;
  display: flex; align-items: center; justify-content: center;
  color: var(--td); font-family: monospace; transition: all 0.12s;
  padding: 0;
}
.qty-btn:hover { border-color: var(--ga); color: var(--ga); }
.qty-val { font-size: 13px; font-weight: 600; color: var(--td); min-width: 18px; text-align: center; font-variant-numeric: tabular-nums; }
.tindakan-subtotal { font-size: 12px; font-weight: 600; color: var(--td); font-variant-numeric: tabular-nums; }
.tindakan-total {
  display: flex; align-items: center; justify-content: space-between;
  padding: 0.7rem 0.85rem;
  background: var(--gl); border-top: 2px solid rgba(31,125,74,0.2);
  font-size: 12px; font-weight: 600; color: var(--td);
}
.tindakan-total-val { font-size: 15px; font-weight: 700; font-variant-numeric: tabular-nums; }

/* ── Penunjang sudah diperiksa (read-only preview di Tab 3) ─────────────── */
.pj-bill-note {
  display: flex; align-items: flex-start; gap: 7px;
  padding: 8px 10px; margin-bottom: 0.6rem;
  background: var(--ib); color: var(--it); border: 1px solid var(--ibd); border-radius: 8px;
  font-size: 11px; line-height: 1.45;
}
.pj-bill-note svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; margin-top: 1px; }
.pj-bill-list { border: 1px solid var(--gb); border-radius: 9px; overflow: hidden; }
.pj-bill-row { display: flex; align-items: center; gap: 0.6rem; padding: 9px 12px; border-bottom: 1px solid var(--gb); }
.pj-bill-row:last-of-type { border-bottom: none; }
.pj-bill-info { flex: 1; min-width: 0; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pj-bill-code { font-family: 'Geist Mono', monospace; font-size: 11px; color: #000; background: var(--bs); padding: 2px 7px; border-radius: 5px; border: 1px solid var(--gb); }
.pj-bill-name { font-size: 12.5px; font-weight: 500; color: #000; }
.pj-bill-eye { font-size: 9px; font-weight: 700; padding: 1px 6px; background: var(--gl); color: var(--td); border-radius: 4px; margin-left: 4px; }
.pj-bill-cat { font-size: 10px; color: var(--tu); }
.pj-bill-price { font-size: 12.5px; font-weight: 600; color: #000; font-variant-numeric: tabular-nums; flex-shrink: 0; }
.pj-bill-total {
  display: flex; align-items: center; justify-content: space-between;
  padding: 0.7rem 0.85rem;
  background: var(--gl); border-top: 2px solid rgba(31,125,74,0.2);
  font-size: 12px; font-weight: 600; color: var(--td);
}
.pj-bill-total-val { font-size: 14px; font-weight: 700; font-variant-numeric: tabular-nums; }
.pj-bill-estimasi {
  display: flex; align-items: center; justify-content: space-between;
  margin-top: 0.6rem; padding: 0.7rem 0.85rem;
  background: var(--bs); border: 1px dashed var(--gb); border-radius: 8px;
  font-size: 12px; font-weight: 600; color: #000;
}
.pj-bill-estimasi-val { font-size: 15px; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--td); }

/* ── Catatan Kasir + Action row Tab 3 ───────────────────────────────────── */
.kasir-note-counter {
  margin-top: 3px; text-align: right;
  font-size: 10px; color: var(--tu); font-variant-numeric: tabular-nums;
}
/* Footer card Tab 3 — full-width, rata dengan card di atas. Kiri: Catatan,
   Kanan: grup aksi (2 tombol seukuran, stacked). */
.tab3-footer {
  display: flex; align-items: stretch; gap: 1rem;
  background: var(--bc); border: 1px solid var(--gb); border-radius: 12px;
  padding: 0.9rem 1rem;
}
.kasir-note-inline {
  display: flex; flex-direction: column; gap: 4px;
  flex: 1; min-width: 0;
}
.kasir-note-label {
  display: flex; align-items: center; gap: 5px;
  font-size: 10.5px; font-weight: 600; color: var(--tm); letter-spacing: 0.02em;
}
.kasir-note-label svg {
  width: 12px; height: 12px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round;
}
.kasir-note-opsional {
  font-size: 9px; font-weight: 500; color: var(--th); font-style: italic;
  margin-left: 2px;
}
.kasir-note-field {
  resize: vertical; min-height: 56px; font-size: 12px; line-height: 1.4; flex: 1;
}
.kasir-note-counter {
  margin-top: 0; text-align: right;
  font-size: 10px; color: var(--tu); font-variant-numeric: tabular-nums;
}
/* ── Catatan untuk Farmasi (di dalam card E-Resep) ──────────────────────────
   Mengikuti gaya Catatan Kasir; dipisah dari daftar obat dengan garis tipis. */
.farmasi-note {
  display: flex; flex-direction: column; gap: 4px;
  margin-top: 0.75rem; padding-top: 0.75rem;
  border-top: 1px dashed var(--gb);
}
.farmasi-note-label {
  display: flex; align-items: center; gap: 5px;
  font-size: 10.5px; font-weight: 600; color: var(--tm); letter-spacing: 0.02em;
}
.farmasi-note-label svg {
  width: 12px; height: 12px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
}
.farmasi-note-opsional {
  font-size: 9px; font-weight: 500; color: var(--th); font-style: italic;
  margin-left: 2px;
}
.farmasi-note-field {
  resize: vertical; min-height: 52px; font-size: 12px; line-height: 1.4;
}
.farmasi-note-counter {
  margin-top: 0; text-align: right;
  font-size: 10px; color: var(--tu); font-variant-numeric: tabular-nums;
}
/* Grup aksi kanan: dua tombol selebar sama, ditumpuk vertikal & rata bawah */
.tab3-action-group {
  display: flex; flex-direction: column; justify-content: flex-end; gap: 0.5rem;
  flex-shrink: 0; width: 230px;
}
.tab3-btn {
  width: 100%; justify-content: center; white-space: nowrap;
}
.tab3-action-group .btn-success {
  box-shadow: 0 4px 14px rgba(31,125,74,.25);
}
.tab3-action-group .btn-success:hover:not(:disabled) {
  box-shadow: 0 6px 18px rgba(31,125,74,.35);
}
@media (max-width: 820px) {
  .tab3-footer { flex-direction: column; align-items: stretch; }
  .tab3-action-group { width: 100%; }
}

/* Konfirmasi kirim kasir modal */
.kasir-confirm-msg {
  margin: 0 0 0.85rem 0;
  font-size: 13px; line-height: 1.55; color: var(--td);
}
.kasir-confirm-msg b { color: var(--ga); font-weight: 700; }
.kasir-confirm-summary {
  background: var(--bs); border: 1px solid var(--gb); border-radius: 9px;
  padding: 0.75rem 0.9rem; display: flex; flex-direction: column; gap: 6px;
  font-size: 12px; color: var(--tm);
}
.kasir-confirm-summary b { color: var(--td); font-weight: 700; font-size: 13px; }
.kasir-confirm-note { color: var(--tu); font-size: 11.5px; padding-top: 4px; border-top: 1px dashed var(--gb); }
.kasir-confirm-note i { color: var(--td); font-style: italic; }

/* ── FORM REGISTRY: modal Dokumen RM (isi = FormDocsBrowser) ──────────────── */
.modal-box-forms { max-width: 760px; }
</style>
