<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRefraksiStore } from '@/stores/refraksiStore'
import { refraksiApi } from '@/services/api'
import PatientAvatar from '@/components/common/PatientAvatar.vue'
import CpptHistoryCard from '@/components/common/CpptHistoryCard.vue'

const store = useRefraksiStore()

// ─── UI filter state ────────────────────────────────────────────────────────
const qFilter     = ref('waiting')  // 'waiting' | 'done'
const ptypeFilter = ref('Semua')
const qSearch     = ref('')
const pendingCallIds = ref([])
const pendingSkipIds = ref([])

// ─── Adapter: map API queue row → UI shape yg dipakai template ──────────────
function ptypeOf(visit) {
  const g = visit?.guarantor_type
  if (g === 'BPJS') return 'bpjs'
  if (g === 'ASURANSI' || g === 'PERUSAHAAN' || g === 'SOSIAL') return 'asn'
  return 'umum'
}

// Helper: bersihkan nilai numerik (string → number atau null)
// Toleran terhadap input manual combobox (bukan pilih dari datalist): koma desimal
// "1,50", unicode minus/en-dash "−1.50", dan satuan/simbol yang sering diketik
// ("90°", "+1.50 D", "43.5 mm") dirapikan dulu supaya nilainya TETAP tersimpan —
// bukan dibuang diam-diam (yang bikin field kosong di RefractionRecord & DokterView).
function num(v) {
  if (v === '' || v === null || v === undefined) return null
  if (typeof v === 'number') return Number.isFinite(v) ? v : null
  const s = String(v).trim()
    .replace(/[‒–—−]/g, '-') // figure/en/em-dash & minus → hyphen ASCII
    .replace(',', '.')                            // koma desimal lokal → titik
    .replace(/[^0-9.+\-]/g, '')                   // buang huruf & satuan (D, mm, °, spasi)
  if (!/\d/.test(s)) return null                  // tak ada digit tersisa → bukan angka
  const n = Number(s)
  return Number.isFinite(n) ? n : null
}
// Axis & keratometri axis = kolom INTEGER (0–180) → bulatkan; desimal ⇒ 422.
function numInt(v) {
  const n = num(v)
  return n === null ? null : Math.round(n)
}
function str(v) {
  return v === '' || v === null || v === undefined ? null : v
}

function uiStatus(s) {
  if (s === 'WAITING') return 'waiting'
  if (s === 'CALLED' || s === 'IN_PROGRESS') return 'progress'
  if (s === 'COMPLETED') return 'done'
  return 'skip'
}

function calcAge(dob) {
  if (!dob) return null
  const d = new Date(dob), n = new Date()
  return n.getFullYear() - d.getFullYear() -
    (n < new Date(n.getFullYear(), d.getMonth(), d.getDate()) ? 1 : 0)
}

function mapQueueRow(q) {
  const visit   = q.visit ?? {}
  const patient = visit.patient ?? q.patient ?? {}
  const nurse   = visit.nurse_assessment ?? null
  const sched   = visit.doctor_schedule ?? null
  // Stasiun pasangan (TRIASE) sedang memegang pasien? → cegah panggil-ganda paralel.
  const sibQ    = (visit.queues ?? []).find((x) => x.station === 'TRIASE')
  const siblingActive = !!sibQ && ['CALLED', 'IN_PROGRESS'].includes(sibQ.status)
  return {
    dpjp:         sched?.employee?.name ?? null,
    poliklinik:   sched?.poliklinik ?? null,
    room:         sched?.room ?? null,
    id:           q.id,
    createdAt:    q.created_at,
    qNum:         q.queue_number,
    name:         patient.name ?? '—',
    rm:           patient.no_rm ?? '—',
    photo:        patient.photo_url ?? null,
    nik:          patient.nik ?? '',
    age:          patient.age ?? calcAge(patient.date_of_birth),
    gender:       patient.gender ?? '',
    address:      patient.address ?? '',
    classification: visit.classification ?? null,
    poli:         visit.guarantor_type === 'BPJS' ? 'Poli BPJS' : 'Poli Umum',
    ptype:        ptypeOf(visit),
    status:       uiStatus(q.status),
    hasNurse:     !!(nurse?.is_finalized || visit.assessment_finalized),
    allergies:    nurse?.allergy_detail ? nurse.allergy_detail.split(',').map((s) => s.trim()).filter(Boolean) : [],
    nurseData:    nurse ? {
      td:       `${nurse.td_sistol ?? '—'}/${nurse.td_diastol ?? '—'}`,
      nadi:     nurse.nadi ?? '—',
      spo2:     nurse.spo2 ?? '—',
      suhu:     nurse.suhu ?? '—',
      keluhan:  nurse.chief_complaint ?? '—',
    } : null,
    siblingActive,
    siblingLabel: 'Triase',
    history:      [],
    _raw:         q,
  }
}

const mappedQueue = computed(() => store.antrian.map(mapQueueRow))

// Baris antrean dibuat hari ini? (kunjungan lintas-hari yang masih nyangkut → segmen "Masih Aktif")
function isTodayRow(c) {
  if (!c) return true
  const d = new Date(c), n = new Date()
  return d.getFullYear() === n.getFullYear() && d.getMonth() === n.getMonth() && d.getDate() === n.getDate()
}
const isDoneP = (p) => p.status === 'done' || p.status === 'skip'

const filtQ = computed(() => {
  let list = mappedQueue.value
  if (qFilter.value === 'active') {
    list = list.filter((p) => !isTodayRow(p.createdAt))
  } else if (qFilter.value === 'done') {
    list = list.filter((p) => isTodayRow(p.createdAt) && isDoneP(p))
  } else {
    list = list.filter((p) => isTodayRow(p.createdAt) && !isDoneP(p))
  }
  if (ptypeFilter.value === 'BPJS')          list = list.filter((p) => p.ptype === 'bpjs')
  else if (ptypeFilter.value === 'UmumAsn')  list = list.filter((p) => p.ptype === 'umum' || p.ptype === 'asn')
  if (qSearch.value) {
    const s = qSearch.value.toLowerCase()
    list = list.filter((p) =>
      p.name.toLowerCase().includes(s) ||
      p.qNum?.toLowerCase().includes(s) ||
      p.rm?.toLowerCase().includes(s)
    )
  }
  return list
})

const cWait   = computed(() => mappedQueue.value.filter((p) => isTodayRow(p.createdAt) && !isDoneP(p)).length)
const cDone   = computed(() => mappedQueue.value.filter((p) => isTodayRow(p.createdAt) && isDoneP(p)).length)
const cActive = computed(() => mappedQueue.value.filter((p) => !isTodayRow(p.createdAt)).length)

const selP = computed(() => store.selectedQueue ? mapQueueRow(store.selectedQueue) : null)
const activeTab = ref('autoref')
const doneSteps = ref([])
const showRxModal = ref(false)

// ── Layout dinamis: panel collapsible + mode fokus ──────────────────────────
const RQKEY = 'refraksi.queueCollapsed'
const RSKEY = 'refraksi.sideCollapsed'
const queueCollapsed = ref(localStorage.getItem(RQKEY) === '1')
const sideCollapsed  = ref(localStorage.getItem(RSKEY) === '1')
function toggleQueue() { queueCollapsed.value = !queueCollapsed.value; localStorage.setItem(RQKEY, queueCollapsed.value ? '1' : '0') }
function toggleSide()  { sideCollapsed.value = !sideCollapsed.value; localStorage.setItem(RSKEY, sideCollapsed.value ? '1' : '0') }
// Mode fokus: saat pasien dipilih di layar sedang (≤1500px), ciutkan antrean agar form lega.
watch(() => store.selectedQueue, (q, prev) => {
  if (q && !prev && typeof window !== 'undefined' && window.matchMedia('(max-width: 1500px)').matches) {
    queueCollapsed.value = true
    localStorage.setItem(RQKEY, '1')
  }
})

const steps = [
  { tab: 'autoref', label: 'Autoref', sub: 'Objektif + Keratometri' },
  { tab: 'iop', label: 'Tonometri', sub: 'NCT' },
  { tab: 'visus', label: 'Visus & Refraksi', sub: 'UCVA · Subjektif · BCVA' },
]

const oldGlasses = ref({
  od_s: '', od_c: '', od_ax: '', od_add: '', od_visus: '',
  os_s: '', os_c: '', os_ax: '', os_add: '', os_visus: '',
})
const autoref = ref({ od_s: '', od_c: '', od_ax: '', os_s: '', os_c: '', os_ax: '', k_od_1: '', k_od_2: '', k_ax_od: '', k_ax2_od: '', k_os_1: '', k_os_2: '', k_ax_os: '', k_ax2_os: '' })
const iop = ref({ od: '', os: '', method: 'NCT' })
// Tonometri berulang (manual, dinamis) — metode = iop.method bersama. [{od,os},...]
const iopExtra = ref([])
function addIopReading() { iopExtra.value.push({ od: '', os: '' }) }
function removeIopReading(idx) { iopExtra.value.splice(idx, 1) }
// IOP tinggi (≥22) di pengukuran MANA PUN (#1 atau pengukuran ulang) → peringatan glaukoma.
const iopHigh = computed(() => {
  const hi = (v) => v !== '' && v !== null && v !== undefined && Number(v) >= 22
  if (hi(iop.value.od) || hi(iop.value.os)) return true
  return iopExtra.value.some((r) => hi(r.od) || hi(r.os))
})
const visus = ref({ ucva_od: '', ucva_os: '', bcva_od: '', bcva_os: '' })
const pinhole = ref({ od: '', os: '' })
const refine = ref({ od_s: '', od_c: '', od_ax: '', os_s: '', os_c: '', os_ax: '', add_od: '', add_os: '', pd: '64' })
const clinicalNotes = ref('')
const rxFinal = ref({ perception_type: 'JAUH', od_add: '', os_add: '', jenis: 'Bifocal', lensa: 'CR-39', coating: 'Anti-reflection', remarks: '' })

// SOAP refraksionis (PPA). O autofill dari data refraksi tapi editable & tersimpan
// (soap_o). S/A/P diketik manual.
// A & P autofill default (tetap editable; tersimpan menang bila sudah diisi).
const SOAP_A_DEFAULT = 'Gangguan Pemeriksaan'
const SOAP_P_DEFAULT = 'Konsultasi ke Dokter'
const soap = ref({ s: '', o: '', a: SOAP_A_DEFAULT, p: SOAP_P_DEFAULT })
const soapODirty = ref(false)   // O disentuh manual → hentikan autofill dari data refraksi

// Objektif (O) tersusun dari data refraksi — SUMBER TUNGGAL `soap_o`, mirror
// RmeAggregator::refraksiObjektif & DokterService::refraksiObjektifResume.
// Urutan: Visus awal → Refraksi subjektif (S/C/X) → Visus akhir → ADD → IOP → PD.
// Editable: begitu diedit manual, autofill berhenti.
const oDerived = computed(() => {
  const parts = []
  const v = visus.value, r = refine.value, i = iop.value
  const has = (x) => x !== '' && x != null
  const signed = (x) => `${Number(x) >= 0 ? '+' : ''}${x}`
  // 1. Visus awal (UCVA)
  if (has(v.ucva_od) || has(v.ucva_os)) parts.push(`Visus awal OD ${v.ucva_od || '–'} / OS ${v.ucva_os || '–'}`)
  // 2. Refraksi subjektif S/C/X (tanpa ADD — ADD baris tersendiri)
  const scx = (s, c, a) => {
    const p = []
    if (has(s)) p.push(`S${signed(s)}`)
    if (has(c)) p.push(`C${signed(c)}`)
    if (has(a)) p.push(`X${a}°`)
    return p.join(' / ')
  }
  const rxOd = scx(r.od_s, r.od_c, r.od_ax), rxOs = scx(r.os_s, r.os_c, r.os_ax)
  if (rxOd || rxOs) parts.push(`Refraksi subjektif OD ${rxOd || '–'} | OS ${rxOs || '–'}`)
  // 3. Visus akhir (BCVA)
  if (has(v.bcva_od) || has(v.bcva_os)) parts.push(`Visus akhir OD ${v.bcva_od || '–'} / OS ${v.bcva_os || '–'}`)
  // 4. ADD (adisi baca)
  if (has(r.add_od) || has(r.add_os)) parts.push(`Add OD ${has(r.add_od) ? signed(r.add_od) : '–'} / OS ${has(r.add_os) ? signed(r.add_os) : '–'}`)
  // 5. IOP/TIO
  if (has(i.od) || has(i.os)) parts.push(`TIO OD ${i.od || '–'} / OS ${i.os || '–'} mmHg${i.method ? ` (${i.method})` : ''}`)
  // 6. PD (pupillary distance) — paling bawah
  if (has(r.pd)) parts.push(`PD ${r.pd} mm`)
  return parts.join('\n')
})

// Autofill O selama belum diedit manual.
watch(oDerived, (v) => { if (!soapODirty.value) soap.value.o = v })

// Ref ke kartu CPPT (panggil reload sehabis finalisasi agar entri baru tampil).
const cpptCardRef = ref(null)

const classColor = { Baru: 'cls-baru', 'Pre-Op': 'cls-preop', 'Post-Op': 'cls-postop', Kontrol: 'cls-kontrol' }
function clsCls(c) { return classColor[c] ?? 'cls-baru' }

function resetForm() {
  oldGlasses.value = { od_s: '', od_c: '', od_ax: '', od_add: '', od_visus: '', os_s: '', os_c: '', os_ax: '', os_add: '', os_visus: '' }
  autoref.value    = { od_s: '', od_c: '', od_ax: '', os_s: '', os_c: '', os_ax: '', k_od_1: '', k_od_2: '', k_ax_od: '', k_ax2_od: '', k_os_1: '', k_os_2: '', k_ax_os: '', k_ax2_os: '' }
  iop.value        = { od: '', os: '', method: 'NCT' }
  iopExtra.value   = []
  visus.value      = { ucva_od: '', ucva_os: '', bcva_od: '', bcva_os: '' }
  pinhole.value    = { od: '', os: '' }
  refine.value     = { od_s: '', od_c: '', od_ax: '', os_s: '', os_c: '', os_ax: '', add_od: '', add_os: '', pd: '64' }
  clinicalNotes.value = ''
  rxFinal.value    = { perception_type: 'JAUH', od_add: '', os_add: '', jenis: 'Bifocal', lensa: 'CR-39', coating: 'Anti-reflection', remarks: '' }
  soap.value       = { s: '', o: '', a: SOAP_A_DEFAULT, p: SOAP_P_DEFAULT }
  soapODirty.value = false
}

/**
 * Prefill semua form dari RefractionRecord + RefractionPrescription existing.
 * Field bertipe decimal datang dari backend sebagai string ("−1.50"), aman di-assign
 * langsung ke v-model input karena input type=text/number menerima keduanya.
 */
function fillFormFromRecord(rec, presc) {
  if (!rec) { resetForm(); return }

  const v = (x) => x === null || x === undefined ? '' : String(x)
  // Buang nol desimal di belakang utk nilai numerik (mis. "15.00" → "15", "15.50" → "15.5")
  const vnum = (x) => {
    if (x === null || x === undefined || x === '') return ''
    const n = Number(x)
    return Number.isFinite(n) ? String(n) : String(x)
  }

  autoref.value = {
    od_s:     v(rec.autoref_od_sph),
    od_c:     v(rec.autoref_od_cyl),
    od_ax:    v(rec.autoref_od_axis),
    os_s:     v(rec.autoref_os_sph),
    os_c:     v(rec.autoref_os_cyl),
    os_ax:    v(rec.autoref_os_axis),
    k_od_1:  v(rec.keratometri1_od),
    k_od_2:  v(rec.keratometri2_od),
    k_ax_od:  v(rec.keratometri_axis_od),
    k_ax2_od: v(rec.keratometri_axis2_od),
    k_os_1:  v(rec.keratometri1_os),
    k_os_2:  v(rec.keratometri2_os),
    k_ax_os:  v(rec.keratometri_axis_os),
    k_ax2_os: v(rec.keratometri_axis2_os),
  }
  iop.value = {
    od:     vnum(rec.iop_od),
    os:     vnum(rec.iop_os),
    method: rec.iop_method ?? 'NCT',
  }
  iopExtra.value = Array.isArray(rec.iop_extra_readings)
    ? rec.iop_extra_readings.map((r) => ({ od: vnum(r?.od), os: vnum(r?.os) }))
    : []
  visus.value = {
    ucva_od: v(rec.visus_awal_od),
    ucva_os: v(rec.visus_awal_os),
    bcva_od: v(rec.visus_akhir_od),
    bcva_os: v(rec.visus_akhir_os),
  }
  pinhole.value = {
    od: v(rec.pinhole_od),
    os: v(rec.pinhole_os),
  }
  refine.value = {
    od_s:  v(rec.refraksi_subjektif_od_sph),
    od_c:  v(rec.refraksi_subjektif_od_cyl),
    od_ax: v(rec.refraksi_subjektif_od_axis),
    os_s:  v(rec.refraksi_subjektif_os_sph),
    os_c:  v(rec.refraksi_subjektif_os_cyl),
    os_ax: v(rec.refraksi_subjektif_os_axis),
    add_od: v(rec.add_power_od),
    add_os: v(rec.add_power_os),
    pd:    vnum(rec.pd_distance) || '64',
  }
  oldGlasses.value = {
    od_s:   v(rec.old_glasses_od_sph),
    od_c:   v(rec.old_glasses_od_cyl),
    od_ax:  v(rec.old_glasses_od_axis),
    od_add: v(rec.old_glasses_add_od),
    od_visus: v(rec.old_glasses_visus_od),
    os_s:   v(rec.old_glasses_os_sph),
    os_c:   v(rec.old_glasses_os_cyl),
    os_ax:  v(rec.old_glasses_os_axis),
    os_add: v(rec.old_glasses_add_os),
    os_visus: v(rec.old_glasses_visus_os),
  }
  clinicalNotes.value = rec.clinical_notes ?? ''
  // O: pakai nilai tersimpan bila ada (tandai dirty agar tak ditimpa autofill);
  // bila kosong, autofill dari data refraksi yang baru saja diisi (oDerived).
  const hasStoredO = rec.soap_o != null && rec.soap_o !== ''
  soapODirty.value = hasStoredO
  soap.value = {
    s: rec.soap_s ?? '',
    o: hasStoredO ? rec.soap_o : oDerived.value,
    a: rec.soap_a ?? SOAP_A_DEFAULT,
    p: rec.soap_p ?? SOAP_P_DEFAULT,
  }

  rxFinal.value = {
    perception_type: rec.perception_type ?? 'JAUH',
    od_add:  v(presc?.rx_od_add),
    os_add:  v(presc?.rx_os_add),
    jenis:   presc?.glasses_type  ?? 'Bifocal',
    lensa:   presc?.lens_material ?? 'CR-39',
    coating: presc?.coating       ?? 'Anti-reflection',
    remarks: presc?.notes         ?? '',
  }
}

async function pickPt(p) {
  if (store.selectedQueue?.id === p.id) return
  try {
    await store.pickPatient(p._raw)
    activeTab.value = 'autoref'
    doneSteps.value = []
    fillFormFromRecord(store.pemeriksaan, store.prescription)
  } catch (err) {
    toast('w', err.message ?? 'Gagal memilih pasien')
  }
}

async function callPt(p) {
  if (pendingCallIds.value.includes(p.id)) return
  if (p.siblingActive) {
    toast('w', `Pasien sedang ditangani di ${p.siblingLabel} — tidak bisa dipanggil dari sini.`)
    return
  }
  const isRecall = p._raw?.status !== 'WAITING'
  pendingCallIds.value.push(p.id)
  try {
    await store.panggilAntrian(p.id)
    toast('i', `${isRecall ? 'Memanggil ulang' : 'Memanggil'} ${p.qNum} — ${p.name}`)
  } catch (err) {
    toast('w', err.response?.data?.message ?? err.message ?? 'Gagal memanggil pasien')
  } finally {
    pendingCallIds.value = pendingCallIds.value.filter((id) => id !== p.id)
  }
}

async function skipPt(p) {
  if (p.status === 'done' || p.status === 'skip') {
    toast('w', 'Pasien sudah selesai, tidak bisa dilewati')
    return
  }
  if (pendingSkipIds.value.includes(p.id)) return
  pendingSkipIds.value.push(p.id)
  try {
    await store.lewatiAntrian(p.id)
    if (store.selectedQueue?.id === p.id) store.clearSelected()
    toast('w', `${p.qNum} diturunkan 1 antrean`)
  } catch (err) {
    toast('w', err.response?.data?.message ?? err.message ?? 'Gagal melewati pasien')
  } finally {
    pendingSkipIds.value = pendingSkipIds.value.filter((id) => id !== p.id)
  }
}

function goTab(t) {
  activeTab.value = t
}

// Bangun payload untuk RefractionRecord — semua field yang tabel dukung.
function buildPemeriksaanPayload() {
  return {
    perception_type: rxFinal.value.perception_type,
    // Autoref
    autoref_od_sph:  num(autoref.value.od_s),
    autoref_od_cyl:  num(autoref.value.od_c),
    autoref_od_axis: numInt(autoref.value.od_ax),
    autoref_os_sph:  num(autoref.value.os_s),
    autoref_os_cyl:  num(autoref.value.os_c),
    autoref_os_axis: numInt(autoref.value.os_ax),
    // Keratometri K1@axis1 / K2@axis2 (axis integer 0–180)
    keratometri1_od:      num(autoref.value.k_od_1),
    keratometri2_od:      num(autoref.value.k_od_2),
    keratometri_axis_od:  numInt(autoref.value.k_ax_od),
    keratometri_axis2_od: numInt(autoref.value.k_ax2_od),
    keratometri1_os:      num(autoref.value.k_os_1),
    keratometri2_os:      num(autoref.value.k_os_2),
    keratometri_axis_os:  numInt(autoref.value.k_ax_os),
    keratometri_axis2_os: numInt(autoref.value.k_ax2_os),
    // Refraksi Subjektif
    refraksi_subjektif_od_sph:  num(refine.value.od_s),
    refraksi_subjektif_od_cyl:  num(refine.value.od_c),
    refraksi_subjektif_od_axis: numInt(refine.value.od_ax),
    refraksi_subjektif_os_sph:  num(refine.value.os_s),
    refraksi_subjektif_os_cyl:  num(refine.value.os_c),
    refraksi_subjektif_os_axis: numInt(refine.value.os_ax),
    // ADD presbyopia (di tab Refine)
    add_power_od: num(refine.value.add_od),
    add_power_os: num(refine.value.add_os),
    // Kacamata lama (+ visus dengan kacamata lama / presenting VA)
    old_glasses_od_sph:  num(oldGlasses.value.od_s),
    old_glasses_od_cyl:  num(oldGlasses.value.od_c),
    old_glasses_od_axis: numInt(oldGlasses.value.od_ax),
    old_glasses_add_od:  num(oldGlasses.value.od_add),
    old_glasses_visus_od: str(oldGlasses.value.od_visus),
    old_glasses_os_sph:  num(oldGlasses.value.os_s),
    old_glasses_os_cyl:  num(oldGlasses.value.os_c),
    old_glasses_os_axis: numInt(oldGlasses.value.os_ax),
    old_glasses_add_os:  num(oldGlasses.value.os_add),
    old_glasses_visus_os: str(oldGlasses.value.os_visus),
    // IOP (+ pengukuran berulang; metode = iop.method bersama)
    iop_od:     num(iop.value.od),
    iop_os:     num(iop.value.os),
    iop_method: iop.value.method,
    iop_extra_readings: iopExtra.value
      .map((r) => ({ od: num(r.od), os: num(r.os) }))
      .filter((r) => r.od !== null || r.os !== null),
    // Visus
    visus_awal_od:  str(visus.value.ucva_od),
    visus_akhir_od: str(visus.value.bcva_od),
    pinhole_od:     str(pinhole.value.od),
    visus_awal_os:  str(visus.value.ucva_os),
    visus_akhir_os: str(visus.value.bcva_os),
    pinhole_os:     str(pinhole.value.os),
    // Shared
    pd_distance:    num(refine.value.pd),
    clinical_notes: str(clinicalNotes.value),
    // SOAP refraksionis (PPA) — O di-derive backend dari data refraksi
    soap_s:         str(soap.value.s),
    soap_o:         str(soap.value.o),
    soap_a:         str(soap.value.a),
    soap_p:         str(soap.value.p),
  }
}

function buildResepPayload() {
  return {
    rx_od_sph:  num(refine.value.od_s),
    rx_od_cyl:  num(refine.value.od_c),
    rx_od_axis: numInt(refine.value.od_ax),
    rx_od_add:  num(rxFinal.value.od_add),
    rx_os_sph:  num(refine.value.os_s),
    rx_os_cyl:  num(refine.value.os_c),
    rx_os_axis: numInt(refine.value.os_ax),
    rx_os_add:  num(rxFinal.value.os_add),
    glasses_type:  str(rxFinal.value.jenis),
    lens_material: str(rxFinal.value.lensa),
    coating:       str(rxFinal.value.coating),
    notes:         str(rxFinal.value.remarks),
  }
}

/**
 * Simpan draft ke backend lalu pindah ke tab berikut.
 * Catatan: backend "PUT /refraksi/pemeriksaan/{id}" tolak update kalau record
 * sudah finalized, jadi tombol "Simpan & Lanjut" otomatis no-op di state itu.
 */
async function saveStep(curTab, nextTab) {
  if (!store.selectedQueue) {
    toast('w', 'Pilih pasien dulu')
    return
  }
  if (store.isFinalized) {
    // Sudah dikunci → cukup pindah tab, jangan call backend.
    if (!doneSteps.value.includes(curTab)) doneSteps.value.push(curTab)
    if (nextTab) activeTab.value = nextTab
    return
  }
  try {
    await store.savePemeriksaan(buildPemeriksaanPayload())
    if (!doneSteps.value.includes(curTab)) doneSteps.value.push(curTab)
    if (nextTab) activeTab.value = nextTab
    toast('s', `Langkah "${curTab}" tersimpan`)
  } catch (err) {
    toast('w', err.message ?? 'Gagal menyimpan draft')
  }
}

/**
 * Simpan draft tab Refraksi lalu buka modal "Resep Kacamata Final".
 * (Dulu Rx Final adalah tab ke-5; kini jadi modal yang muncul dari tab Refraksi.)
 */
async function openRxModal() {
  await saveStep('visus', null)
  if (!doneSteps.value.includes('visus')) return // saveStep gagal → jangan buka modal
  showRxModal.value = true
}

function fillFromAutoref() {
  refine.value = { ...refine.value, od_s: autoref.value.od_s, od_c: autoref.value.od_c, od_ax: autoref.value.od_ax, os_s: autoref.value.os_s, os_c: autoref.value.os_c, os_ax: autoref.value.os_ax }
  toast('i', 'Refraksi diisi dari hasil autoref')
}

// Acuan autoref (read-only) sbg panduan refraksi subjektif — format "S C ×Axis°".
function fmtAutorefEye(eye) {
  const v = (x) => (x === '' || x === null || x === undefined) ? null : x
  const s = v(autoref.value[`${eye}_s`])
  const c = v(autoref.value[`${eye}_c`])
  const ax = v(autoref.value[`${eye}_ax`])
  if (s === null && c === null && ax === null) return 'belum diisi'
  const body = `${s ?? '—'} ${c ?? '—'}`
  return ax !== null ? `${body} ×${ax}°` : body
}

// Lewati Refraksi: pasien tidak perlu refraksi. Record ditandai "dilewati"
// (tanpa data klinis) & antrean tetap maju (gate paralel ke Dokter / Kirim ke Bedah).
async function onSkipRefraksi() {
  if (!store.selectedQueue?.id) { toast('w', 'Pilih pasien dulu'); return }
  if (!confirm('Lewati refraksi untuk pasien ini? Pemeriksaan ditandai "tidak diperlukan" dan pasien lanjut ke antrean berikutnya.')) return
  try {
    await store.skipRefraksi(store.selectedQueue.id)
    toast('s', 'Refraksi dilewati — pasien lanjut ke antrean berikutnya')
    qFilter.value = 'done'
    store.clearSelected()
  } catch (err) {
    toast('e', err.message || 'Gagal melewati refraksi')
  }
}

// ── Tanda tangan PIN (paraf refraksionis sebagai PPA) ───────────────────────
const pinMode  = ref(false)
const pinValue = ref('')
const pinError = ref('')
const pinBusy  = ref(false)

// Langkah 1: simpan draft (record + resep + SOAP) lalu minta PIN untuk finalisasi.
async function sendToDoctor() {
  // Tidak ada field klinis yang wajib — refraksionis boleh kirim ke dokter dengan
  // data sebagian/kosong (hanya pasien yang harus terpilih sebagai konteks).
  if (!store.selectedQueue) { toast('w', 'Pilih pasien dulu'); return }

  try {
    // 1. Simpan RefractionRecord (semua field + SOAP S/A/P).
    await store.savePemeriksaan(buildPemeriksaanPayload())

    // 2. Simpan RefractionPrescription (create/update sebelum finalize).
    try {
      await store.saveResep(buildResepPayload())
    } catch (err) {
      // Gagal resep ≠ blocker hard — beri tahu, lanjut minta PIN.
      toast('w', `Resep kacamata belum tersimpan: ${err.message}`)
    }
  } catch (err) {
    toast('w', err.message ?? 'Gagal menyimpan draft refraksi')
    return
  }

  // 3. Minta PIN (tanda tangan) sebelum mengunci & advance ke DOKTER.
  pinValue.value = ''
  pinError.value = ''
  pinMode.value  = true
}

// Langkah 2: verifikasi PIN → finalize (gate advance ke DOKTER + paraf refraksionis).
async function confirmFinalizePin() {
  const pin = pinValue.value.trim()
  if (!/^\d{4,6}$/.test(pin)) { pinError.value = 'PIN harus 4–6 digit angka.'; return }
  pinError.value = ''
  pinBusy.value  = true
  try {
    // Backend balikkan doctor_ticket (D-NNN) bila Triase JUGA sudah finalize.
    await store.finalizePemeriksaan(pin)
    pinMode.value = false
    qFilter.value = 'done'
    cpptCardRef.value?.reload()
    if (store.doctorTicket) {
      toast('s', `Pasien lengkap (TR) — antrean dokter ${store.doctorTicket.queue_number} dibuat. Cetak tiket pasien.`)
    } else {
      toast('s', 'Refraksi ditandatangani & dikunci. Menunggu Triase selesai sebelum tiket dokter dicetak.')
    }
    // Pasien tetap terpilih agar tombol "Cetak Tiket Dokter" tampil.
  } catch (err) {
    // PIN salah / belum diatur → tampilkan di modal, jangan tutup.
    pinError.value = err.message ?? 'Gagal mengunci pemeriksaan'
  } finally {
    pinBusy.value = false
  }
}

// Buka kunci (periksa ulang atas permintaan dokter) — form ter-unlock, antrean kembali aktif.
async function onReopen() {
  if (!store.pemeriksaan?.id) return
  if (!confirm('Buka kunci pemeriksaan refraksi untuk diperiksa ulang?\nTanda tangan dihapus dan pemeriksaan harus difinalisasi (PIN) ulang.')) return
  try {
    await store.reopenPemeriksaan()
    activeTab.value = 'autoref'
    doneSteps.value = []
    toast('s', 'Pemeriksaan dibuka kembali — silakan revisi lalu kirim ke dokter lagi.')
  } catch (err) {
    toast('w', err.message || 'Gagal membuka kunci')
  }
}

/* ============================================================
   CETAK TIKET DOKTER (80mm) — tampil setelah TR selesai keduanya.
   Sumber data: store.doctorTicket (D-NNN + poliklinik/ruang/dokter).
   ============================================================ */
function escHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ))
}

function printDoctorTicket() {
  const t = store.doctorTicket
  if (!t?.queue_number) { toast('w', 'Antrean dokter belum dibuat — tunggu Triase selesai.'); return }
  const patientName = selP.value?.name ?? ''
  const dest = [
    t.poliklinik ? `Poli ${escHtml(t.poliklinik)}` : null,
    t.room ? `Ruang ${escHtml(t.room)}` : null,
  ].filter(Boolean).join(' · ') || 'Poliklinik Dokter'
  const ticketHtml = `
    <html><head><title>Antrean ${escHtml(t.queue_number)}</title>
    <style>
      @page { size: 80mm auto; margin: 0; }
      * { margin:0; padding:0; box-sizing:border-box; font-family:'Helvetica Neue',Arial,sans-serif; color:#000; }
      body { width:80mm; padding:4mm 0; }
      .h { text-align:center; padding:2mm 4mm; font-size:13pt; font-weight:700; border-bottom:1px dashed #000; }
      .sub { text-align:center; font-size:8pt; padding:2mm 4mm 1mm; letter-spacing:0.05em; text-transform:uppercase; }
      .num { text-align:center; font-size:56pt; font-weight:700; padding:2mm 0; line-height:1; }
      .sep { border-top:1px dashed #000; margin:2mm 4mm; }
      .b { text-align:center; padding:2mm 4mm; font-size:10pt; line-height:1.5; }
      .b strong { font-size:11pt; }
      .ft { text-align:center; padding:2mm 4mm 0; font-size:8pt; }
    </style></head><body>
      <div class="h">RUMAH SAKIT MATA PRIMA VISION</div>
      <div class="sub">Tiket Antrean Dokter</div>
      <div class="num">${escHtml(t.queue_number)}</div>
      <div class="sep"></div>
      <div class="b">
        ${patientName ? `<div style="margin-bottom:2mm">${escHtml(patientName)}</div>` : ''}
        Menuju <strong>${dest}</strong>
        ${t.doctor_name ? `<div style="margin-top:2mm">${escHtml(t.doctor_name)}</div>` : ''}
      </div>
      <div class="ft">Simpan tiket ini sampai dipanggil</div>
    </body></html>`
  const w = window.open('', '_blank', 'width=320,height=480')
  if (!w) { toast('w', 'Popup diblokir browser — izinkan popup untuk cetak tiket'); return }
  w.document.write(ticketHtml)
  w.document.close()
  w.focus()
  w.onload = () => { try { w.print() } catch {} }
  setTimeout(() => { try { w.print() } catch {} }, 400)
}

// ─── Opsi combobox dari master (kind → daftar nilai) ────────────────────────
// Fallback default kalau master belum termuat / endpoint gagal (degradasi mulus:
// input tetap combobox dengan datalist kosong → petugas tetap bisa ketik manual).
const DEFAULT_VA = ['6/6', '6/7.5', '6/9', '6/12', '6/15', '6/18', '6/24', '6/36', '6/60', '3/60', '2/60', '1/60', 'CF', 'HM', 'LP', 'NLP']
// Visus Akhir (BCVA) — master TERPISAH dari Visus Awal (nilainya berbeda).
const DEFAULT_VA_AKHIR = ['6/6', '6/7.5', '6/9', '6/12', '6/15', '6/18', '6/24', '6/36', '6/60', '3/60', '2/60', '1/60', 'CF', 'HM', 'LP', 'NLP']
// Pinhole TERPISAH dari Visus (tanpa HM/LP/NLP).
const DEFAULT_PH = ['6/6', '6/7.5', '6/9', '6/12', '6/15', '6/18', '6/24', '6/36', '6/60', '3/60', '2/60', '1/60', 'CF']
const refOpts = ref({
  sphere: [], cylinder: [], axis: [], keratometri: [], add: [], visus: DEFAULT_VA, visus_akhir: DEFAULT_VA_AKHIR, pinhole: DEFAULT_PH,
})
// Backward-compat: komponen visus awal pakai `vaOpts`; visus akhir `vaAkhirOpts`; pinhole `phOpts`.
const vaOpts = computed(() => refOpts.value.visus?.length ? refOpts.value.visus : DEFAULT_VA)
const vaAkhirOpts = computed(() => refOpts.value.visus_akhir?.length ? refOpts.value.visus_akhir : DEFAULT_VA_AKHIR)
const phOpts = computed(() => refOpts.value.pinhole?.length ? refOpts.value.pinhole : DEFAULT_PH)

async function loadRefOpts() {
  try {
    const { data } = await refraksiApi.opsi()
    const m = data?.data ?? {}
    refOpts.value = {
      sphere:      m.sphere      ?? [],
      cylinder:    m.cylinder    ?? [],
      axis:        m.axis        ?? [],
      keratometri: m.keratometri ?? [],
      add:         m.add         ?? [],
      visus:       m.visus?.length ? m.visus : DEFAULT_VA,
      visus_akhir: m.visus_akhir?.length ? m.visus_akhir : DEFAULT_VA_AKHIR,
      pinhole:     m.pinhole?.length ? m.pinhole : DEFAULT_PH,
    }
  } catch {
    // diam — fallback default sudah aktif
  }
}

// ─── Notifikasi suara "pasien baru masuk antrean" ───────────────────────────
// Nada "Hospital Ping" di-generate Web Audio API (tanpa file aset). Mengikuti
// pola AntreanTVView::playHospitalPing. Toggle mute/unmute persist di localStorage.
const SNDKEY = 'refraksi.soundEnabled'
const soundEnabled = ref(localStorage.getItem(SNDKEY) !== '0')
let audioCtx = null

function ensureCtx() {
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)()
    if (audioCtx.state === 'suspended') audioCtx.resume()
  } catch { /* AudioContext diblokir → diam */ }
  return audioCtx
}

function playPing() {
  const ctx = ensureCtx()
  if (!ctx) return
  try {
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    const delay = ctx.createDelay()
    const feedback = ctx.createGain()
    delay.delayTime.value = 0.22
    feedback.gain.value = 0.35
    osc.type = 'sine'
    osc.frequency.value = 988   // B5
    osc.connect(gain)
    gain.connect(ctx.destination)
    gain.connect(delay)
    delay.connect(feedback)
    feedback.connect(delay)
    delay.connect(ctx.destination)
    const t0 = ctx.currentTime
    gain.gain.setValueAtTime(0.0001, t0)
    gain.gain.exponentialRampToValueAtTime(0.5, t0 + 0.04)
    gain.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.5)
    osc.start(t0)
    osc.stop(t0 + 0.6)
  } catch { /* abaikan */ }
}

function toggleSound() {
  soundEnabled.value = !soundEnabled.value
  localStorage.setItem(SNDKEY, soundEnabled.value ? '1' : '0')
  if (soundEnabled.value) { ensureCtx(); playPing() }   // konfirmasi audible + unlock
  toast('i', soundEnabled.value ? 'Suara notifikasi dinyalakan' : 'Suara notifikasi dimatikan')
}

// Unlock autoplay: browser blokir audio sebelum ada gesture. Buka pada interaksi pertama.
function unlockAudioOnce() {
  ensureCtx()
  window.removeEventListener('pointerdown', unlockAudioOnce)
  window.removeEventListener('keydown', unlockAudioOnce)
}

// Pasien baru terdeteksi di store → bunyikan (bila tidak di-mute) + toast.
watch(() => store.newPatientPing, () => {
  if (soundEnabled.value) playPing()
  toast('i', 'Pasien baru masuk antrean Refraksi')
})

// ─── Lifecycle ──────────────────────────────────────────────────────────────
onMounted(async () => {
  window.addEventListener('pointerdown', unlockAudioOnce)
  window.addEventListener('keydown', unlockAudioOnce)
  loadRefOpts()
  await store.fetchAntrian()
  store.connectWs()
})

onUnmounted(() => {
  window.removeEventListener('pointerdown', unlockAudioOnce)
  window.removeEventListener('keydown', unlockAudioOnce)
  try { audioCtx?.close() } catch { /* abaikan */ }
  audioCtx = null
  store.disconnectWs()
  store.clearSelected()
})

const toasts = ref([])
let tid = 0
function toast(type, msg) {
  const id = ++tid
  toasts.value.push({ id, type, msg })
  setTimeout(() => (toasts.value = toasts.value.filter((t) => t.id !== id)), 3000)
}
</script>

<template>
  <div class="refraksi">
    <!-- Datalist combobox opsi refraksi (dari master /refraksi/opsi). Input pakai
         list=… → boleh pilih cepat ATAU ketik nilai di luar daftar. -->
    <datalist id="dl-sphere"><option v-for="o in refOpts.sphere" :key="o" :value="o" /></datalist>
    <datalist id="dl-cylinder"><option v-for="o in refOpts.cylinder" :key="o" :value="o" /></datalist>
    <datalist id="dl-axis"><option v-for="o in refOpts.axis" :key="o" :value="o" /></datalist>
    <datalist id="dl-keratometri"><option v-for="o in refOpts.keratometri" :key="o" :value="o" /></datalist>
    <datalist id="dl-add"><option v-for="o in refOpts.add" :key="o" :value="o" /></datalist>
    <datalist id="dl-visus"><option v-for="o in vaOpts" :key="o" :value="o" /></datalist>
    <datalist id="dl-visus-akhir"><option v-for="o in vaAkhirOpts" :key="o" :value="o" /></datalist>
    <datalist id="dl-pinhole"><option v-for="o in phOpts" :key="o" :value="o" /></datalist>

    <div :class="['grid', selP ? 'grid-3' : '', { 'q-collapsed': queueCollapsed, 's-collapsed': sideCollapsed && selP }]">
      <!-- QUEUE -->
      <aside class="col-queue">
        <button class="queue-rail" @click="toggleQueue" title="Buka daftar antrean" aria-label="Buka daftar antrean">
          <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
          <span class="queue-rail-count">{{ mappedQueue.length }}</span>
          <span class="queue-rail-txt">Antrean</span>
        </button>
        <div class="card queue-card">
          <div class="card-head">
            <div>
              <div class="card-head-title">
                <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Antrean Refraksi
              </div>
              <div class="card-head-sub">{{ mappedQueue.length }} pasien hari ini</div>
            </div>
            <div class="head-actions">
              <span class="pill-live">LIVE</span>
              <button
                :class="['panel-collapse', 'snd-toggle', soundEnabled ? '' : 'is-muted']"
                @click="toggleSound"
                :title="soundEnabled ? 'Matikan suara notifikasi' : 'Nyalakan suara notifikasi'"
                :aria-label="soundEnabled ? 'Matikan suara notifikasi' : 'Nyalakan suara notifikasi'"
                :aria-pressed="!soundEnabled"
              >
                <svg v-if="soundEnabled" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/>
                </svg>
                <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="22" y1="9" x2="16" y2="15"/><line x1="16" y1="9" x2="22" y2="15"/>
                </svg>
              </button>
              <button class="panel-collapse" @click="toggleQueue" title="Ciutkan antrean" aria-label="Ciutkan antrean">
                <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
              </button>
            </div>
          </div>

          <div class="card-body queue-scroll" role="region" aria-label="Daftar antrean refraksi">

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
                <b class="stat-num">{{ mappedQueue.length }}</b>
              </div>
            </div>

            <!-- Primary filter -->
            <div class="primary-filter" role="group" aria-label="Filter utama antrean">
              <button :class="['pf-btn', qFilter === 'waiting' ? 'a' : '']" @click="qFilter = 'waiting'">
                Belum Selesai
                <span v-if="cWait" class="pf-ct">{{ cWait }}</span>
              </button>
              <button :class="['pf-btn', qFilter === 'done' ? 'a' : '']" @click="qFilter = 'done'">
                Selesai
                <span v-if="cDone" class="pf-ct">{{ cDone }}</span>
              </button>
              <button :class="['pf-btn', qFilter === 'active' ? 'a' : '']" @click="qFilter = 'active'" title="Kunjungan belum selesai dari hari sebelumnya (lintas-hari)">
                Masih Aktif
                <span v-if="cActive" class="pf-ct">{{ cActive }}</span>
              </button>
            </div>

            <!-- Secondary filter -->
            <div class="ptype-tabs" role="group" aria-label="Filter jenis penjamin">
              <button :class="['ptype-tab', ptypeFilter === 'Semua' ? 'a' : '']" @click="ptypeFilter = 'Semua'">Semua</button>
              <button :class="['ptype-tab ptype-bpjs', ptypeFilter === 'BPJS' ? 'a' : '']" @click="ptypeFilter = 'BPJS'">BPJS</button>
              <button :class="['ptype-tab ptype-umum', ptypeFilter === 'UmumAsn' ? 'a' : '']" @click="ptypeFilter = 'UmumAsn'">Umum &amp; Asuransi</button>
            </div>

            <!-- Search -->
            <div class="q-search-wrap">
              <input v-model="qSearch" class="q-search" placeholder="Cari nama / no. antrean…" />
            </div>

            <!-- Empty -->
            <div v-if="!filtQ.length" class="empty-section" aria-live="polite">
              Tidak ada pasien dalam filter ini
            </div>

            <!-- Queue list -->
            <div v-else role="list" aria-label="Daftar antrean refraksi">
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
                  <span :class="['pill', p.status === 'waiting' ? 'pill-waiting' : p.status === 'progress' ? 'pill-in_progress' : 'pill-completed']">
                    {{ p.status === 'waiting' ? 'Menunggu' : p.status === 'progress' ? 'Proses' : 'Selesai' }}
                  </span>
                </div>

                <div class="q-info">
                  <div class="q-name">{{ p.name }}</div>
                  <div class="q-meta">{{ p.age }} th · {{ p.gender }} · {{ p.poli }}</div>
                  <div v-if="p.dpjp" class="q-dpjp" :title="`DPJP tujuan: ${p.dpjp}`">
                    <svg viewBox="0 0 24 24" class="q-dpjp-ic" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
                    DPJP: {{ p.dpjp }}
                  </div>
                  <div class="q-tags">
                    <span :class="['pill', p.ptype === 'bpjs' ? 'pill-bpjs' : p.ptype === 'asn' ? 'pill-asn' : 'pill-umum']">
                      {{ p.ptype === 'bpjs' ? 'BPJS' : p.ptype === 'asn' ? 'Asuransi' : 'Umum' }}
                    </span>
                    <span v-if="p.hasNurse" class="pill pill-done">
                      <svg viewBox="0 0 24 24" class="pill-icon"><polyline points="20 6 9 17 4 12"/></svg>
                      Triase
                    </span>
                    <span v-if="p.allergies?.length" class="pill pill-allergy">⚠ Alergi</span>
                    <span v-if="p.siblingActive" class="pill pill-sibling" :title="`Pasien sedang ditangani di ${p.siblingLabel}`">⏳ Sedang di {{ p.siblingLabel }}</span>
                  </div>
                  <div v-if="p.status !== 'done' && p.status !== 'skip'" class="q-actions" @click.stop>
                    <button
                      type="button"
                      :class="['q-act-btn', 'call', pendingCallIds.includes(p.id) ? 'is-pressed' : '']"
                      :disabled="pendingCallIds.includes(p.id) || p.siblingActive"
                      :title="p.siblingActive ? `Pasien sedang ditangani di ${p.siblingLabel}` : 'Panggil pasien'"
                      :aria-pressed="pendingCallIds.includes(p.id)"
                      :aria-busy="pendingCallIds.includes(p.id)"
                      @click.stop="callPt(p)"
                    >
                      <span v-if="pendingCallIds.includes(p.id)" class="q-sp" aria-hidden="true"></span>
                      <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>
                      {{ pendingCallIds.includes(p.id) ? 'Memanggil…' : 'Panggil' }}
                    </button>
                    <button
                      type="button"
                      :class="['q-act-btn', 'skip', pendingSkipIds.includes(p.id) ? 'is-pressed' : '']"
                      :disabled="pendingSkipIds.includes(p.id)"
                      :aria-pressed="pendingSkipIds.includes(p.id)"
                      :aria-busy="pendingSkipIds.includes(p.id)"
                      @click.stop="skipPt(p)"
                    >
                      <span v-if="pendingSkipIds.includes(p.id)" class="q-sp" aria-hidden="true"></span>
                      <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg>
                      {{ pendingSkipIds.includes(p.id) ? 'Melewati…' : 'Lewati' }}
                    </button>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
      </aside>

      <!-- FORM -->
      <section class="pf">
        <div v-if="!selP" class="empty">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/></svg>
          <p>Pilih pasien dari antrean untuk<br />memulai pemeriksaan refraksi</p>
        </div>

        <div v-else>
          <!-- BANNER -->
          <div class="banner" role="region" :aria-label="`Pasien aktif: ${selP.name}`">
            <PatientAvatar :name="selP.name" :src="selP.photo" :size="46" radius="50%" style="margin-top:2px" />
            <div class="b-info">
              <div class="b-name">{{ selP.name }}</div>
              <div class="b-meta">RM: {{ selP.rm }} · {{ selP.age }} th · {{ selP.gender === 'L' ? 'Laki-laki' : 'Perempuan' }}</div>
              <div v-if="selP.dpjp" class="b-dpjp" :title="`DPJP tujuan: ${selP.dpjp}`">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
                DPJP: <strong>{{ selP.dpjp }}</strong>
                <span v-if="selP.poliklinik"> · {{ selP.poliklinik }}</span>
                <span v-if="selP.room"> · {{ selP.room }}</span>
              </div>
              <div v-if="selP.address" class="b-address">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                {{ selP.address }}
              </div>
              <div class="b-tags">
                <span v-if="selP.classification" :class="['ptg', clsCls(selP.classification)]">
                  {{ selP.classification }}
                </span>
                <span v-if="selP.ptype === 'bpjs'" class="ptg ptg-b">BPJS</span>
                <span v-if="selP.ptype === 'asn'" class="ptg ptg-asn">Asuransi</span>
                <span v-if="selP.hasNurse" class="ptg ptg-n">Triase ✓</span>
                <span v-if="store.isFinalized" class="ptg ptg-signed" title="Rekam refraksi telah ditandatangani (PIN)">✓ Ditandatangani</span>
                <span v-if="selP.allergies && selP.allergies.length" class="ptg ptg-a"
                      role="alert" :aria-label="`Peringatan alergi: ${selP.allergies.join(', ')}`">
                  ⚠ {{ selP.allergies.join(', ') }}
                </span>
              </div>
            </div>
          </div>

          <!-- NURSE VITALS -->
          <div v-if="selP.nurseData" class="nurse-bar" role="region" aria-label="Data triase perawat">
            <div class="nb-title">Triase Perawat</div>
            <div class="nb-stat"><b>{{ selP.nurseData.td }}</b><span>mmHg</span></div>
            <div class="nb-stat"><b>{{ selP.nurseData.nadi }}</b><span>bpm</span></div>
            <div class="nb-stat"><b>{{ selP.nurseData.spo2 }}%</b><span>SpO₂</span></div>
            <div class="nb-stat"><b>{{ selP.nurseData.suhu }}°C</b><span>Suhu</span></div>
            <div class="nb-keluhan"><strong>Keluhan:</strong> {{ selP.nurseData.keluhan }}</div>
          </div>

          <!-- STEPS -->
          <div class="steps" role="list" aria-label="Langkah-langkah pemeriksaan">
            <div v-for="(s, i) in steps" :key="s.tab"
                 class="step-i"
                 role="listitem"
                 @click="goTab(s.tab)"
                 @keydown.enter.prevent="goTab(s.tab)"
                 @keydown.space.prevent="goTab(s.tab)"
                 tabindex="0"
                 :aria-current="activeTab === s.tab ? 'step' : undefined"
                 :aria-label="`Langkah ${i + 1}: ${s.label}${doneSteps.includes(s.tab) ? ' (selesai)' : activeTab === s.tab ? ' (aktif)' : ''}`">
              <div :class="['step-c', activeTab === s.tab ? 'a' : doneSteps.includes(s.tab) ? 'd' : '']" aria-hidden="true">
                <svg v-if="doneSteps.includes(s.tab)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                <span v-else>{{ i + 1 }}</span>
              </div>
              <div class="step-l">
                <div :class="['step-t', activeTab === s.tab ? 'a' : doneSteps.includes(s.tab) ? 'd' : '']">{{ s.label }}</div>
                <div class="step-s">{{ s.sub }}</div>
              </div>
              <div v-if="i < steps.length - 1" :class="['step-line', doneSteps.includes(s.tab) ? 'd' : '']" aria-hidden="true"></div>
            </div>
          </div>

          <!-- AUTOREF -->
          <div v-if="activeTab === 'autoref'" class="card" role="region" aria-labelledby="card-autoref-title">
            <div class="card-head">
              <div class="card-head-title" id="card-autoref-title">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/></svg>
                Autorefraktometri &amp; Keratometri
              </div>
            </div>
            <div class="card-body">
              <div class="odos">
                <div class="eyec">
                  <div class="eyeh"><span class="elbl el-od" aria-hidden="true">OD</span><span class="esub">Mata Kanan</span></div>
                  <div class="g3">
                    <div class="fg">
                      <label class="fl" for="akr-od-s">Sferis (S)</label>
                      <input id="akr-od-s" v-model="autoref.od_s" list="dl-sphere" class="form-input" placeholder="-1.50" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="akr-od-c">Silindris (C)</label>
                      <input id="akr-od-c" v-model="autoref.od_c" list="dl-cylinder" class="form-input" placeholder="-0.75" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="akr-od-ax">Axis</label>
                      <input id="akr-od-ax" v-model="autoref.od_ax" list="dl-axis" class="form-input" placeholder="180" />
                    </div>
                  </div>
                  <div class="sec" aria-label="Keratometri mata kanan">Keratometri</div>
                  <div class="g4">
                    <div class="fg">
                      <label class="fl" for="akr-k-od-1">K1 <span class="hint">D</span></label>
                      <input id="akr-k-od-1" v-model="autoref.k_od_1" list="dl-keratometri" class="form-input" placeholder="43.50" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="akr-k-od-ax">Axis K1</label>
                      <input id="akr-k-od-ax" v-model="autoref.k_ax_od" list="dl-axis" class="form-input" placeholder="180" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="akr-k-od-2">K2 <span class="hint">D</span></label>
                      <input id="akr-k-od-2" v-model="autoref.k_od_2" list="dl-keratometri" class="form-input" placeholder="44.25" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="akr-k-od-ax2">Axis K2</label>
                      <input id="akr-k-od-ax2" v-model="autoref.k_ax2_od" list="dl-axis" class="form-input" placeholder="90" />
                    </div>
                  </div>
                </div>
                <div class="eyec">
                  <div class="eyeh"><span class="elbl el-os" aria-hidden="true">OS</span><span class="esub">Mata Kiri</span></div>
                  <div class="g3">
                    <div class="fg">
                      <label class="fl" for="akr-os-s">Sferis (S)</label>
                      <input id="akr-os-s" v-model="autoref.os_s" list="dl-sphere" class="form-input" placeholder="-1.75" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="akr-os-c">Silindris (C)</label>
                      <input id="akr-os-c" v-model="autoref.os_c" list="dl-cylinder" class="form-input" placeholder="-0.50" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="akr-os-ax">Axis</label>
                      <input id="akr-os-ax" v-model="autoref.os_ax" list="dl-axis" class="form-input" placeholder="90" />
                    </div>
                  </div>
                  <div class="sec" aria-label="Keratometri mata kiri">Keratometri</div>
                  <div class="g4">
                    <div class="fg">
                      <label class="fl" for="akr-k-os-1">K1 <span class="hint">D</span></label>
                      <input id="akr-k-os-1" v-model="autoref.k_os_1" list="dl-keratometri" class="form-input" placeholder="43.25" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="akr-k-os-ax">Axis K1</label>
                      <input id="akr-k-os-ax" v-model="autoref.k_ax_os" list="dl-axis" class="form-input" placeholder="90" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="akr-k-os-2">K2 <span class="hint">D</span></label>
                      <input id="akr-k-os-2" v-model="autoref.k_os_2" list="dl-keratometri" class="form-input" placeholder="44.00" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="akr-k-os-ax2">Axis K2</label>
                      <input id="akr-k-os-ax2" v-model="autoref.k_ax2_os" list="dl-axis" class="form-input" placeholder="180" />
                    </div>
                  </div>
                </div>
              </div>
              <div class="action-row">
                <button type="button" class="btn btn-primary"
                  :disabled="store.saving || store.isFinalized"
                  @click="saveStep('autoref', 'iop')">Simpan &amp; Lanjut ke IOP →</button>
                <button type="button" class="btn btn-secondary"
                  :disabled="store.saving || store.isFinalized"
                  @click="saveStep('autoref', null)">Simpan Draft</button>
              </div>
            </div>
          </div>

          <!-- IOP -->
          <div v-if="activeTab === 'iop'" class="card" role="region" aria-labelledby="card-iop-title">
            <div class="card-head">
              <div class="card-head-title" id="card-iop-title">Tonometri (NCT)</div>
            </div>
            <div class="card-body">
              <div class="g3">
                <div class="fg">
                  <label class="fl" for="iop-method">Metode</label>
                  <select id="iop-method" v-model="iop.method" class="form-input">
                    <option value="NCT">NCT</option>
                    <option value="Goldmann">Goldmann (GAT)</option>
                    <option value="iCare">iCare (Rebound)</option>
                    <option value="Schiotz">Schiotz</option>
                  </select>
                </div>
                <div class="fg">
                  <label class="fl" for="iop-od">IOP OD <span class="hint">mmHg</span></label>
                  <input id="iop-od" v-model="iop.od" type="number" class="form-input" placeholder="15" />
                </div>
                <div class="fg">
                  <label class="fl" for="iop-os">IOP OS <span class="hint">mmHg</span></label>
                  <input id="iop-os" v-model="iop.os" type="number" class="form-input" placeholder="16" />
                </div>
              </div>

              <!-- Tonometri berulang (opsional, dinamis) — metode mengikuti dropdown di atas -->
              <div v-for="(r, i) in iopExtra" :key="i" class="iop-extra-row">
                <span class="iop-extra-no" aria-hidden="true">#{{ i + 2 }}</span>
                <div class="fg">
                  <label class="fl" :for="`iop2-od-${i}`">IOP OD <span class="hint">mmHg</span></label>
                  <input :id="`iop2-od-${i}`" v-model="r.od" type="number" class="form-input" placeholder="15" />
                </div>
                <div class="fg">
                  <label class="fl" :for="`iop2-os-${i}`">IOP OS <span class="hint">mmHg</span></label>
                  <input :id="`iop2-os-${i}`" v-model="r.os" type="number" class="form-input" placeholder="16" />
                </div>
                <button type="button" class="iop-extra-del" :disabled="store.isFinalized"
                        :title="`Hapus pengukuran #${i + 2}`" :aria-label="`Hapus pengukuran #${i + 2}`" @click="removeIopReading(i)">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
              </div>
              <button type="button" class="btn btn-secondary btn-sm" style="margin-top:0.6rem"
                      :disabled="store.isFinalized" @click="addIopReading"
                      title="Tambah baris pengukuran IOP (metode sama)">
                + Tambah pengukuran
              </button>

              <div v-if="iopHigh"
                   class="alert-warn" role="alert">
                ⚠ IOP meningkat — pertimbangkan rujuk dokter SpM untuk pemeriksaan glaukoma.
              </div>
              <div class="action-row">
                <button type="button" class="btn btn-secondary btn-sm" @click="goTab('autoref')">← Kembali</button>
                <button type="button" class="btn btn-primary"
                  :disabled="store.saving || store.isFinalized"
                  @click="saveStep('iop', 'visus')">Simpan &amp; Lanjut →</button>
              </div>
            </div>
          </div>

          <!-- VISUS & REFRAKSI SUBJEKTIF (gabungan: UCVA → Pinhole → Subjektif → BCVA) -->
          <div v-if="activeTab === 'visus'" class="card" role="region" aria-labelledby="card-visus-title">
            <div class="card-head">
              <div class="card-head-title" id="card-visus-title">Visus &amp; Refraksi Subjektif</div>
            </div>
            <div class="card-body">
              <div class="sec">Visus Awal (UCVA)</div>
              <div class="g2">
                <div class="fg">
                  <label class="fl" for="vis-ucva-od">UCVA OD</label>
                  <input id="vis-ucva-od" v-model="visus.ucva_od" list="dl-visus" class="form-input" placeholder="6/6" />
                </div>
                <div class="fg">
                  <label class="fl" for="vis-ucva-os">UCVA OS</label>
                  <input id="vis-ucva-os" v-model="visus.ucva_os" list="dl-visus" class="form-input" placeholder="6/6" />
                </div>
              </div>
              <div class="sec">Pinhole Test <span class="hint" style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--tu)">— deteksi amblyopia</span></div>
              <div class="g2">
                <div class="fg">
                  <label class="fl" for="ph-od">Pinhole OD</label>
                  <input id="ph-od" v-model="pinhole.od" list="dl-pinhole" class="form-input" placeholder="6/6" />
                </div>
                <div class="fg">
                  <label class="fl" for="ph-os">Pinhole OS</label>
                  <input id="ph-os" v-model="pinhole.os" list="dl-pinhole" class="form-input" placeholder="6/6" />
                </div>
              </div>
              <!-- Refraksi Subjektif (koreksi DULU, sebelum ukur Visus Akhir) -->
              <div class="sec-row">
                <div class="sec" style="margin:0">Refraksi Subjektif</div>
                <button type="button" class="btn btn-sm btn-secondary" @click="fillFromAutoref">Isi dari Autoref</button>
              </div>
              <div class="odos">
                <div class="eyec">
                  <div class="eyeh"><span class="elbl el-od" aria-hidden="true">OD</span></div>
                  <div class="autoref-ref" aria-label="Acuan autoref OD (read-only)">
                    <span class="autoref-ref-lbl">Autoref</span>
                    <span class="autoref-ref-val">{{ fmtAutorefEye('od') }}</span>
                  </div>
                  <div class="g3">
                    <div class="fg">
                      <label class="fl" for="ref-od-s">S</label>
                      <input id="ref-od-s" v-model="refine.od_s" list="dl-sphere" class="form-input" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="ref-od-c">C</label>
                      <input id="ref-od-c" v-model="refine.od_c" list="dl-cylinder" class="form-input" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="ref-od-ax">Axis</label>
                      <input id="ref-od-ax" v-model="refine.od_ax" list="dl-axis" class="form-input" />
                    </div>
                  </div>
                </div>
                <div class="eyec">
                  <div class="eyeh"><span class="elbl el-os" aria-hidden="true">OS</span></div>
                  <div class="autoref-ref" aria-label="Acuan autoref OS (read-only)">
                    <span class="autoref-ref-lbl">Autoref</span>
                    <span class="autoref-ref-val">{{ fmtAutorefEye('os') }}</span>
                  </div>
                  <div class="g3">
                    <div class="fg">
                      <label class="fl" for="ref-os-s">S</label>
                      <input id="ref-os-s" v-model="refine.os_s" list="dl-sphere" class="form-input" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="ref-os-c">C</label>
                      <input id="ref-os-c" v-model="refine.os_c" list="dl-cylinder" class="form-input" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="ref-os-ax">Axis</label>
                      <input id="ref-os-ax" v-model="refine.os_ax" list="dl-axis" class="form-input" />
                    </div>
                  </div>
                </div>
              </div>
              <!-- Visus Akhir (BCVA) — diukur SETELAH koreksi subjektif, SEBELUM ADD/PD -->
              <div class="sec">Visus Akhir (BCVA) <span class="hint" style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--tu)">— ketajaman terbaik setelah koreksi</span></div>
              <div class="g2">
                <div class="fg">
                  <label class="fl" for="vis-bcva-od">BCVA OD</label>
                  <input id="vis-bcva-od" v-model="visus.bcva_od" list="dl-visus-akhir" class="form-input" placeholder="6/6" />
                </div>
                <div class="fg">
                  <label class="fl" for="vis-bcva-os">BCVA OS</label>
                  <input id="vis-bcva-os" v-model="visus.bcva_os" list="dl-visus-akhir" class="form-input" placeholder="6/6" />
                </div>
              </div>

              <!-- ADD presbyopia + PD (untuk resep) — setelah Visus Akhir -->
              <div class="g3" style="margin-top: 0.65rem">
                <div class="fg">
                  <label class="fl" for="ref-add-od">ADD OD <span class="hint">presbyopia</span></label>
                  <input id="ref-add-od" v-model="refine.add_od" list="dl-add" class="form-input" placeholder="+2.00" />
                </div>
                <div class="fg">
                  <label class="fl" for="ref-add-os">ADD OS <span class="hint">presbyopia</span></label>
                  <input id="ref-add-os" v-model="refine.add_os" list="dl-add" class="form-input" placeholder="+2.00" />
                </div>
                <div class="fg">
                  <label class="fl" for="ref-pd">PD <span class="hint">mm</span></label>
                  <input id="ref-pd" v-model="refine.pd" class="form-input" placeholder="64" />
                </div>
              </div>

              <!-- Kacamata Lama -->
              <div class="sec" style="margin-top:1rem">Kacamata Lama <span class="hint" style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--tu)">— kosongkan jika tidak pakai kacamata</span></div>
              <div class="odos">
                <div class="eyec">
                  <div class="eyeh"><span class="elbl el-od" aria-hidden="true">OD</span><span class="esub">Mata Kanan</span></div>
                  <div class="g4">
                    <div class="fg">
                      <label class="fl" for="og-od-s">S</label>
                      <input id="og-od-s" v-model="oldGlasses.od_s" list="dl-sphere" class="form-input" placeholder="-1.00" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="og-od-c">C</label>
                      <input id="og-od-c" v-model="oldGlasses.od_c" list="dl-cylinder" class="form-input" placeholder="-0.50" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="og-od-ax">Axis</label>
                      <input id="og-od-ax" v-model="oldGlasses.od_ax" list="dl-axis" class="form-input" placeholder="180" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="og-od-add">ADD</label>
                      <input id="og-od-add" v-model="oldGlasses.od_add" list="dl-add" class="form-input" placeholder="+1.50" />
                    </div>
                  </div>
                </div>
                <div class="eyec">
                  <div class="eyeh"><span class="elbl el-os" aria-hidden="true">OS</span><span class="esub">Mata Kiri</span></div>
                  <div class="g4">
                    <div class="fg">
                      <label class="fl" for="og-os-s">S</label>
                      <input id="og-os-s" v-model="oldGlasses.os_s" list="dl-sphere" class="form-input" placeholder="-1.25" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="og-os-c">C</label>
                      <input id="og-os-c" v-model="oldGlasses.os_c" list="dl-cylinder" class="form-input" placeholder="-0.50" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="og-os-ax">Axis</label>
                      <input id="og-os-ax" v-model="oldGlasses.os_ax" list="dl-axis" class="form-input" placeholder="90" />
                    </div>
                    <div class="fg">
                      <label class="fl" for="og-os-add">ADD</label>
                      <input id="og-os-add" v-model="oldGlasses.os_add" list="dl-add" class="form-input" placeholder="+1.50" />
                    </div>
                  </div>
                </div>
              </div>

              <!-- Visus dengan kacamata lama (presenting VA) -->
              <div class="sec" style="margin-top:1rem">Visus dengan Kacamata Lama <span class="hint" style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--tu)">— ketajaman saat memakai kacamata lama</span></div>
              <div class="g2">
                <div class="fg">
                  <label class="fl" for="og-visus-od">Visus OD</label>
                  <input id="og-visus-od" v-model="oldGlasses.od_visus" list="dl-visus" class="form-input" placeholder="6/9" />
                </div>
                <div class="fg">
                  <label class="fl" for="og-visus-os">Visus OS</label>
                  <input id="og-visus-os" v-model="oldGlasses.os_visus" list="dl-visus" class="form-input" placeholder="6/9" />
                </div>
              </div>

              <!-- Catatan Refraksionis -->
              <div class="sec" style="margin-top:1rem">Catatan Refraksionis</div>
              <div class="fg">
                <textarea
                  id="refraksionis-notes"
                  v-model="clinicalNotes"
                  class="form-input ta"
                  rows="3"
                  aria-label="Catatan refraksionis"
                  placeholder="Observasi visus, koreksi yang dicoba, kesulitan pasien, rekomendasi pemeriksaan lanjutan…"
                ></textarea>
              </div>

              <div class="action-row">
                <button type="button" class="btn btn-secondary btn-sm" @click="goTab('iop')">← Kembali</button>
                <button type="button" class="btn btn-secondary"
                  :disabled="store.saving || store.isFinalized"
                  @click="saveStep('visus', null)">Simpan Draft</button>
                <button type="button" class="btn btn-primary"
                  :disabled="store.saving"
                  @click="openRxModal">Resep Kacamata →</button>
              </div>
            </div>
          </div>

          <!-- ── Kartu aksi: Kirim ke Dokter (di luar tab, selalu tampil) ── -->
          <div class="card send-card">
            <div class="card-body send-card-body">
              <div class="send-card-info">
                <div class="send-card-title">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                  Kirim ke Dokter untuk Tanda Tangan
                </div>
                <div class="send-card-sub">
                  <template v-if="store.pemeriksaanLoading">Memuat data refraksi…</template>
                  <template v-else-if="store.isFinalized && store.doctorTicket">Pasien selesai Triase &amp; Refraksionis — antrean dokter <strong>{{ store.doctorTicket.queue_number }}</strong> dibuat. Cetak tiket pasien.</template>
                  <template v-else-if="store.isFinalized">Refraksi dikunci. Menunggu <strong>Triase</strong> selesai sebelum tiket dokter bisa dicetak.</template>
                  <template v-else-if="!refine.od_s && !refine.os_s">Isi hasil refraksi (OD/OS) terlebih dahulu sebelum mengirim.</template>
                  <template v-else>Klik untuk mengunci rekam refraksi dan kirim resep ke dokter.</template>
                </div>
              </div>
              <div class="send-actions">
                <button
                  type="button"
                  class="btn btn-success btn-lg send-btn"
                  :disabled="store.finalizing || store.saving || store.pemeriksaanLoading || store.isFinalized"
                  @click="sendToDoctor"
                >
                  <div v-if="store.finalizing || store.saving || store.pemeriksaanLoading" class="sp" role="status" aria-label="Memproses…"></div>
                  <template v-else>
                    Kirim ke Dokter
                    <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                  </template>
                </button>
                <button
                  v-if="!store.isFinalized"
                  type="button"
                  class="btn btn-secondary btn-lg send-btn"
                  :disabled="store.skipping || store.finalizing || store.pemeriksaanLoading"
                  title="Pasien tidak perlu refraksi — antrean tetap lanjut ke dokter"
                  @click="onSkipRefraksi"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg>
                  {{ store.skipping ? 'Melewati…' : 'Tidak Perlu Refraksi' }}
                </button>
                <button
                  v-if="store.isFinalized"
                  type="button"
                  class="btn btn-secondary btn-lg send-btn"
                  :disabled="!store.doctorTicket"
                  :title="store.doctorTicket ? `Cetak tiket antrean ${store.doctorTicket.queue_number}` : 'Tombol aktif setelah Triase juga selesai (antrean dokter dibuat)'"
                  @click="printDoctorTicket"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                  Cetak Tiket Dokter
                </button>
                <button
                  v-if="store.isFinalized"
                  type="button"
                  class="btn btn-secondary btn-lg send-btn"
                  :disabled="store.finalizing"
                  title="Buka kunci untuk pemeriksaan ulang (atas permintaan dokter)"
                  @click="onReopen"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 11V7a5 5 0 0 1 9.9-1"/><rect x="3" y="11" width="18" height="11" rx="2"/></svg>
                  Buka Kunci
                </button>
              </div>
            </div>
          </div>

        </div>
      </section>

      <!-- KOLOM KANAN: Riwayat CPPT/SOAP lintas-episode (semua PPA) -->
      <aside v-if="selP" class="col-cppt">
        <button class="side-rail" @click="toggleSide" title="Buka CPPT / SOAP" aria-label="Buka CPPT / SOAP">
          <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
          <span class="side-rail-txt">CPPT / SOAP</span>
        </button>
        <button class="side-collapse-bar" @click="toggleSide" title="Ciutkan CPPT / SOAP" aria-label="Ciutkan CPPT / SOAP">
          <span>CPPT / SOAP</span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
        <CpptHistoryCard ref="cpptCardRef" :patient-id="store.selectedPatientId" :fetcher="refraksiApi.riwayatCppt" />

        <!-- SOAP Refraksionis (PPA) — kartu entri sendiri, di bawah riwayat CPPT kunjungan.
             O di-derive otomatis dari data refraksi (visus/IOP/Rx) yang diisi di formulir kiri. -->
        <div class="card soap-rfx-card">
          <div class="card-head">
            <div class="card-head-title">SOAP Refraksionis · CPPT Terpadu</div>
          </div>
          <div class="card-body">
            <div class="soap-rfx">
              <div class="fg">
                <label class="fl" for="rfx-soap-s"><span class="soap-tag s">S</span> Subjektif — keluhan visus</label>
                <textarea id="rfx-soap-s" v-model="soap.s" class="form-input ta" rows="2"
                  placeholder="Keluhan penglihatan pasien (mis. kabur melihat jauh, silau, pusing dengan kacamata lama)…"></textarea>
              </div>
              <div class="fg">
                <label class="fl" for="rfx-soap-o">
                  <span class="soap-tag o">O</span> Objektif
                  <em v-if="!soapODirty" class="soap-auto">otomatis dari data refraksi</em>
                  <em v-else class="soap-auto">diedit manual
                    <button type="button" class="soap-o-resync" @click="soapODirty = false; soap.o = oDerived">↺ sync</button>
                  </em>
                </label>
                <textarea id="rfx-soap-o" v-model="soap.o" @input="soapODirty = true" class="form-input ta" rows="3"
                  placeholder="Visus akhir, koreksi subjektif & TIO — terisi otomatis dari formulir, boleh diedit…"></textarea>
              </div>
              <div class="fg">
                <label class="fl" for="rfx-soap-a"><span class="soap-tag a">A</span> Assessment — kesimpulan refraksi</label>
                <textarea id="rfx-soap-a" v-model="soap.a" class="form-input ta" rows="2"
                  placeholder="Mis. Miopia simpleks ODS; koreksi terbaik tercapai; presbiopia awal…"></textarea>
              </div>
              <div class="fg">
                <label class="fl" for="rfx-soap-p"><span class="soap-tag p">P</span> Planning — rencana</label>
                <textarea id="rfx-soap-p" v-model="soap.p" class="form-input ta" rows="2"
                  placeholder="Mis. Resep kacamata baru; rujuk dokter untuk evaluasi katarak; kontrol 6 bulan…"></textarea>
              </div>
            </div>
          </div>
        </div>
      </aside>
    </div>

    <!-- ── MODAL: Resep Kacamata Final (dibuka dari tab Refraksi) ───────────── -->
    <div v-if="showRxModal" class="rx-overlay" role="dialog" aria-modal="true" aria-labelledby="rx-modal-title" @click.self="showRxModal = false">
      <div class="rx-modal">
        <div class="rx-modal-head">
          <div class="card-head-title" id="rx-modal-title">Resep Kacamata Final</div>
          <button type="button" class="rx-close" aria-label="Tutup" @click="showRxModal = false">
            <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
        <div class="rx-modal-body">
          <div class="sec">Jenis Resep</div>
          <div class="seg" role="tablist" aria-label="Tipe persepsi kacamata">
            <button type="button" :class="['seg-b', rxFinal.perception_type === 'JAUH' ? 'a' : '']" role="tab"
                    :aria-selected="rxFinal.perception_type === 'JAUH'" @click="rxFinal.perception_type = 'JAUH'">
              Jauh (Distance)
            </button>
            <button type="button" :class="['seg-b', rxFinal.perception_type === 'DEKAT' ? 'a' : '']" role="tab"
                    :aria-selected="rxFinal.perception_type === 'DEKAT'" @click="rxFinal.perception_type = 'DEKAT'">
              Dekat (Near)
            </button>
          </div>

          <div class="rx-summary" aria-label="Ringkasan resep">
            <div class="rx-row"><b>OD</b><span>{{ refine.od_s || '—' }} {{ refine.od_c }} {{ refine.od_ax ? `×${refine.od_ax}°` : '' }} <em v-if="rxFinal.od_add">ADD {{ rxFinal.od_add }}</em></span></div>
            <div class="rx-row"><b>OS</b><span>{{ refine.os_s || '—' }} {{ refine.os_c }} {{ refine.os_ax ? `×${refine.os_ax}°` : '' }} <em v-if="rxFinal.os_add">ADD {{ rxFinal.os_add }}</em></span></div>
            <div class="rx-row"><b>PD</b><span>{{ refine.pd }} mm</span></div>
          </div>

          <div class="sec">ADD Resep (Bifocal/Progresif)</div>
          <div class="g2">
            <div class="fg">
              <label class="fl" for="rx-add-od">ADD OD</label>
              <input id="rx-add-od" v-model="rxFinal.od_add" list="dl-add" class="form-input" placeholder="+1.50" />
            </div>
            <div class="fg">
              <label class="fl" for="rx-add-os">ADD OS</label>
              <input id="rx-add-os" v-model="rxFinal.os_add" list="dl-add" class="form-input" placeholder="+1.50" />
            </div>
          </div>

          <div class="sec">Spesifikasi Kacamata</div>
          <div class="g3">
            <div class="fg">
              <label class="fl" for="rx-jenis">Jenis Lensa</label>
              <select id="rx-jenis" v-model="rxFinal.jenis" class="form-input">
                <option>Single Vision</option><option>Bifocal</option><option>Progresif</option><option>Office</option>
              </select>
            </div>
            <div class="fg">
              <label class="fl" for="rx-lensa">Material</label>
              <select id="rx-lensa" v-model="rxFinal.lensa" class="form-input">
                <option>CR-39</option><option>Polycarbonate</option><option>Trivex</option><option>Hi-index 1.67</option>
              </select>
            </div>
            <div class="fg">
              <label class="fl" for="rx-coating">Coating</label>
              <select id="rx-coating" v-model="rxFinal.coating" class="form-input">
                <option>Anti-reflection</option><option>UV</option><option>Blue light</option><option>Photochromic</option><option value="">—</option>
              </select>
            </div>
          </div>

          <div class="fg" style="margin-top: 0.65rem">
            <label class="fl" for="rx-remarks">Catatan Resep</label>
            <textarea id="rx-remarks" v-model="rxFinal.remarks" class="form-input ta" rows="2" placeholder="Lensa kontak, frame, instruksi pakai, dll"></textarea>
          </div>

          <div class="alert-info" role="note">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="13"/><line x1="12" y1="16" x2="12" y2="16"/></svg>
            Resep akan dikirim ke dokter untuk ditanda-tangani secara digital sebelum diserahkan ke pasien.
          </div>
        </div>

        <div class="rx-modal-foot">
          <button type="button" class="btn btn-secondary btn-sm" @click="showRxModal = false">← Tutup</button>
          <button type="button" class="btn btn-secondary" @click="toast('i', 'Mencetak resep kacamata')"
                  aria-label="Cetak resep kacamata">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Cetak Rx
          </button>
        </div>
      </div>
    </div>

    <!-- ── MODAL: PIN tanda tangan refraksionis (paraf PPA) ─────────────────── -->
    <div v-if="pinMode" class="pin-overlay" role="dialog" aria-modal="true" @click.self="pinMode = false">
      <div class="pin-modal">
        <h4 class="pin-title">Tanda Tangan Refraksionis</h4>
        <p class="pin-hint">Masukkan PIN untuk menandatangani &amp; mengunci rekam refraksi <b>{{ selP?.name }}</b>, lalu kirim ke dokter.</p>
        <input
          v-model="pinValue"
          type="password"
          inputmode="numeric"
          maxlength="6"
          class="pin-input"
          placeholder="••••••"
          autocomplete="off"
          @keyup.enter="confirmFinalizePin()"
        />
        <div v-if="pinError" class="pin-err">{{ pinError }}</div>
        <div class="pin-actions">
          <button type="button" class="btn btn-secondary btn-sm" :disabled="pinBusy" @click="pinMode = false">Batal</button>
          <button type="button" class="btn btn-success btn-sm" :disabled="pinBusy" @click="confirmFinalizePin()">
            {{ pinBusy ? 'Memproses…' : 'Tanda Tangani & Kunci' }}
          </button>
        </div>
      </div>
    </div>

    <div class="toast-wrap" aria-live="polite" aria-atomic="false">
      <div v-for="t in toasts" :key="t.id" :class="['toast', `toast-${t.type}`]">{{ t.msg }}</div>
    </div>
  </div>
</template>

<style scoped>
.refraksi { padding: 0; }
.grid { display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 1rem; align-items: start; transition: grid-template-columns .2s ease; }
.grid.q-collapsed { grid-template-columns: 52px minmax(0, 1fr); }
/* Saat pasien terpilih: tambah kolom kanan untuk kartu CPPT/SOAP lintas-episode. */
.grid.grid-3 { grid-template-columns: 320px minmax(0, 1fr) 340px; }
.grid.grid-3.q-collapsed { grid-template-columns: 52px minmax(0, 1fr) 340px; }
.grid.grid-3.s-collapsed { grid-template-columns: 320px minmax(0, 1fr) 52px; }
.grid.grid-3.q-collapsed.s-collapsed { grid-template-columns: 52px minmax(0, 1fr) 52px; }
.col-cppt { min-width: 0; position: sticky; top: 0.5rem; }

/* Form lega: batasi lebar & pusatkan saat ruang berlebih */
.pf > * { max-width: 1040px; margin-inline: auto; width: 100%; }

/* Rail (panel diciutkan) + tombol ciutkan */
.col-queue .queue-rail, .col-cppt .side-rail { display: none; }
.grid.q-collapsed .col-queue .queue-card { display: none; }
.grid.q-collapsed .col-queue .queue-rail { display: flex; }
.grid.s-collapsed .col-cppt > :not(.side-rail) { display: none; }
.grid.s-collapsed .col-cppt .side-rail { display: flex; }
.queue-rail, .side-rail { position: sticky; top: 0.5rem; width: 52px; min-height: 128px; flex-direction: column; align-items: center; gap: 9px; padding: 13px 4px; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; cursor: pointer; color: var(--tm); font-family: 'Inter', sans-serif; transition: all .13s; }
.queue-rail:hover, .side-rail:hover { border-color: var(--ga); color: var(--ga); }
.queue-rail svg, .side-rail svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.queue-rail-count { font-size: 14px; font-weight: 700; color: var(--ga); font-variant-numeric: tabular-nums; }
.queue-rail-txt, .side-rail-txt { writing-mode: vertical-rl; text-orientation: mixed; font-size: 11px; font-weight: 600; letter-spacing: 0.05em; }
.head-actions { display: flex; align-items: center; gap: 6px; }
.panel-collapse { width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--gb); border-radius: 7px; background: var(--bs); color: var(--tu); cursor: pointer; transition: all .13s; flex-shrink: 0; }
.panel-collapse:hover { border-color: var(--ga); color: var(--ga); }
.panel-collapse svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.snd-toggle.is-muted { color: var(--wt, #b45309); border-color: var(--wbd, #fcd34d); background: var(--wb, #fffbeb); }
.snd-toggle.is-muted:hover { border-color: var(--wt, #b45309); color: var(--wt, #b45309); }
.side-collapse-bar { display: flex; align-items: center; justify-content: space-between; gap: 6px; width: 100%; padding: 7px 12px; margin-bottom: 0.75rem; background: var(--bc); border: 1px solid var(--gb); border-radius: 10px; font-size: 11px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: var(--tm); cursor: pointer; font-family: 'Inter', sans-serif; }
.side-collapse-bar:hover { border-color: var(--ga); color: var(--ga); }
.side-collapse-bar svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

/* Responsif: stack 1 kolom di layar sempit (tanpa scroll horizontal) */
@media (max-width: 1180px) {
  .grid, .grid.q-collapsed, .grid.grid-3, .grid.grid-3.q-collapsed, .grid.grid-3.s-collapsed, .grid.grid-3.q-collapsed.s-collapsed { grid-template-columns: 1fr; }
  .col-cppt { grid-column: 1 / -1; position: static; }
  .col-queue .queue-rail, .col-cppt .side-rail, .panel-collapse, .side-collapse-bar { display: none !important; }
  .col-queue .queue-card { display: block !important; }
  .grid.s-collapsed .col-cppt > :not(.side-rail):not(.side-collapse-bar) { display: block !important; }
  .pf > * { max-width: none; }
}

/* ── Queue card ──────────────────────────────────────────────────────────── */
.card-head-sub { font-size: 11px; color: var(--tu); margin-top: 3px; }
.pill-live { font-size: 9.5px; font-weight: 700; padding: 2px 8px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 20px; letter-spacing: 0.05em; }
.queue-scroll { padding: 0.6rem; max-height: calc(100vh - 200px); overflow-y: auto; }

/* Stats bar */
.stats-bar { display: flex; align-items: center; background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 8px 12px; margin-bottom: 0.65rem; }
.stat-item { flex: 1; text-align: center; }
.stat-divider { width: 1px; height: 28px; background: var(--gb); flex-shrink: 0; }
.stat-label { display: block; font-size: 9.5px; color: var(--tu); letter-spacing: 0.03em; margin-bottom: 2px; }
.stat-num { display: block; font-size: 17px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; }
.stat-waiting { color: #d97706; }
.stat-done { color: var(--st); }

/* Primary filter */
.primary-filter { display: flex; gap: 4px; margin-bottom: 0.5rem; }
.pf-btn { flex: 1; height: 32px; font-size: 11.5px; font-weight: 500; border: 1.5px solid var(--gb); border-radius: 8px; background: var(--bs); color: var(--tm); cursor: pointer; font-family: 'Inter', sans-serif; transition: all .13s; display: flex; align-items: center; justify-content: center; gap: 5px; }
.pf-btn:hover { border-color: var(--ga); color: var(--ga); }
.pf-btn.a { background: var(--gd); color: #fff; border-color: var(--gd); }
.pf-ct { font-size: 9px; font-weight: 700; padding: 0 5px; border-radius: 10px; background: rgba(255,255,255,.25); }

/* Secondary filter */
.ptype-tabs { display: flex; gap: 3px; margin-bottom: 0.55rem; }
.ptype-tab { flex: 1; padding: 5px 4px; font-size: 10px; font-weight: 600; border: 1.5px solid var(--gb); border-radius: 7px; background: var(--bs); color: var(--tu); cursor: pointer; font-family: 'Inter',sans-serif; text-align: center; transition: all .13s; white-space: nowrap; }
.ptype-tab:hover { border-color: var(--ga); color: var(--ga); }
.ptype-tab.a { color: #fff; font-weight: 700; }
.ptype-bpjs.a { background: #1d4ed8; border-color: #1d4ed8; }
.ptype-umum.a { background: var(--ga); border-color: var(--ga); }
.ptype-asur.a { background: var(--pt); border-color: var(--pt); }

/* Search */
.q-search-wrap { margin-bottom: 0.5rem; }
.q-search { width: 100%; height: 30px; font-size: 11.5px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 10px; background: var(--bs); font-family: 'Inter', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.q-search:focus { border-color: var(--ga); background: #fff; }

/* Empty state */
.empty-section { text-align: center; padding: 0.75rem 1rem; font-size: 11px; color: var(--th); background: var(--bi); border-radius: 7px; margin-bottom: 6px; border: 1px dashed var(--gb); }

/* Queue item */
.q-item { display: flex; gap: 8px; padding: 8px 10px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; margin-bottom: 5px; cursor: pointer; transition: all 0.14s; font-family: 'Inter', sans-serif; }
.q-item:hover { border-color: var(--lm); background: var(--gl); }
.q-item.active { border-color: var(--ga); background: var(--gl); }
.q-item.done { opacity: .55; }
.q-item:focus-visible { outline: 2px solid var(--ga); outline-offset: 2px; }
.qi-left { display: flex; flex-direction: column; gap: 4px; min-width: 56px; }
.q-num { font-weight: 700; font-size: 13.5px; color: var(--ga); letter-spacing: 0.03em; }
.q-info { flex: 1; min-width: 0; }
.q-name { font-size: 12.5px; font-weight: 500; color: var(--td); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.q-meta { font-size: 10px; color: var(--tu); margin-top: 2px; }
.q-dpjp { font-size: 10px; color: #0e3a66; font-weight: 600; margin-top: 2px; display: flex; align-items: center; gap: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.q-dpjp-ic { width: 10px; height: 10px; flex-shrink: 0; }
.q-tags { display: flex; gap: 3px; margin-top: 3px; flex-wrap: wrap; }

/* Pills */
.pill { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px; }
.pill-icon { width: 8px; height: 8px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; flex-shrink: 0; }
.pill-waiting     { background: #fef3c7; color: #92400e; }
.pill-in_progress { background: #dbeafe; color: #1e40af; }
.pill-completed   { background: var(--sb); color: var(--st); }
.pill-bpjs  { background: #dbeafe; color: #1e40af; }
.pill-umum  { background: var(--gl); color: var(--ga); }
.pill-asn   { background: var(--pb); color: var(--pt); }
.pill-done  { background: var(--sb); color: var(--st); }
.pill-allergy { background: var(--eb); color: var(--et); }
.pill-sibling { background: #fef3c7; color: #92400e; }

/* Queue actions */
.q-actions { display: flex; gap: 4px; margin-top: 5px; padding-top: 5px; border-top: 1px dashed var(--gb); width: 100%; }
.q-act-btn { position: relative; display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; font-size: 10px; font-weight: 600; border-radius: 5px; border: 1px solid; cursor: pointer; font-family: 'Inter',sans-serif; transition: transform .08s ease, box-shadow .12s ease, background .12s ease, color .12s ease, border-color .12s ease; background: none; user-select: none; -webkit-tap-highlight-color: transparent; }
.q-act-btn svg { width: 10px; height: 10px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.q-act-btn.call { color: var(--ga); border-color: var(--ga); background: var(--gl); }
.q-act-btn.call:hover:not(:disabled) { background: var(--ga); color: #fff; }
.q-act-btn.skip { color: var(--tu); border-color: var(--gb); }
.q-act-btn.skip:hover:not(:disabled) { background: var(--wb); color: var(--wt); border-color: var(--wbd); }

/* Button-press feedback — efek depress (translate Y + inset shadow) saat ditekan
   atau saat dalam state pending (is-pressed). Lebih kuat dari sekadar bg change
   supaya user dapat instant tactile feedback meski klik singkat. */
.q-act-btn:active:not(:disabled),
.q-act-btn.is-pressed {
  transform: translateY(1px) scale(0.97);
  box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.12);
}
.q-act-btn.call:active:not(:disabled),
.q-act-btn.call.is-pressed {
  background: var(--gd); color: #fff; border-color: var(--gd);
}
.q-act-btn.skip:active:not(:disabled),
.q-act-btn.skip.is-pressed {
  background: var(--wbd); color: var(--wt); border-color: var(--wbd);
}
.q-act-btn:focus-visible { outline: 2px solid var(--ga); outline-offset: 2px; }
.q-act-btn:disabled { cursor: progress; opacity: 0.85; }

/* Mini spinner untuk state pending pada tombol panggil/lewati */
.q-sp { width: 10px; height: 10px; border-radius: 50%; border: 1.5px solid currentColor; border-top-color: transparent; animation: ref-spin 0.7s linear infinite; flex-shrink: 0; }

/* form panel */
.pf { display: flex; flex-direction: column; gap: 0.75rem; }
.empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1rem; padding: 4rem 2rem; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; color: var(--th); text-align: center; }
.empty svg { width: 56px; height: 56px; fill: none; stroke: var(--gb); stroke-width: 1.5; stroke-linecap: round; }
.empty p { font-size: 13.5px; line-height: 1.7; }

.banner { display: flex; align-items: flex-start; gap: 1rem; padding: 0.85rem 1.1rem; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; }
.b-av { width: 46px; height: 46px; border-radius: 50%; background: var(--gl); color: var(--ga); font-size: 19px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; }
.b-info { flex: 1; min-width: 0; }
.b-name { font-family: 'Space Grotesk', serif; font-size: 19px; color: var(--td); line-height: 1.1; }
.b-meta { font-size: 11px; color: var(--tu); margin-top: 3px; }
.b-address { font-size: 10.5px; color: var(--tu); margin-top: 3px; display: flex; align-items: center; gap: 4px; }
.b-address svg { width: 10px; height: 10px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.b-dpjp { font-size: 11px; color: #0e3a66; font-weight: 600; margin-top: 3px; display: flex; align-items: center; gap: 4px; }
.b-dpjp svg { width: 11px; height: 11px; flex-shrink: 0; }
.b-tags { display: flex; gap: 4px; margin-top: 6px; flex-wrap: wrap; }
.ptg { font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 4px; }
.ptg-b { background: #dbeafe; color: #1e40af; }
.ptg-asn { background: var(--pb); color: var(--pt); }
.ptg-n { background: var(--sb); color: var(--st); }
.ptg-a { background: var(--eb); color: var(--et); }
.ptg-signed { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.cls-baru    { background: #dbeafe; color: #1e40af; }
.cls-preop   { background: #fef3c7; color: #92400e; }
.cls-postop  { background: var(--sb); color: var(--st); }
.cls-kontrol { background: #f3e8ff; color: #7e22ce; }

.nurse-bar { display: flex; align-items: center; gap: 0.85rem; padding: 0.55rem 1rem; background: var(--gl); border: 1px solid var(--sbd); border-radius: 10px; }
.nb-title { font-size: 10.5px; font-weight: 700; color: var(--ga); letter-spacing: 0.04em; text-transform: uppercase; }
.nb-stat { display: flex; flex-direction: column; align-items: center; }
.nb-stat b { font-size: 13.5px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; line-height: 1; }
.nb-stat span { font-size: 9px; color: var(--tu); margin-top: 2px; }
.nb-keluhan { font-size: 11.5px; color: var(--tm); flex: 1; }
.nb-keluhan strong { color: var(--tu); font-weight: 600; }

/* Steps */
.steps { display: flex; align-items: center; padding: 0.9rem 1rem; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; }
.step-i { display: flex; align-items: center; flex: 1; cursor: pointer; border-radius: 4px; }
.step-i:last-child { flex: 0; }
.step-i:focus-visible { outline: 2px solid var(--ga); outline-offset: 2px; border-radius: 4px; }
.step-c { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; border: 2px solid var(--gb); background: var(--bc); color: var(--tu); flex-shrink: 0; }
.step-c.a { border-color: var(--ga); background: var(--ga); color: #fff; }
.step-c.d { border-color: var(--st); background: var(--st); color: #fff; }
.step-c svg { width: 13px; height: 13px; fill: none; stroke: currentColor; }
.step-l { margin-left: 8px; }
.step-t { font-size: 11.5px; font-weight: 500; color: var(--tu); }
.step-t.a { color: var(--ga); font-weight: 600; }
.step-t.d { color: var(--st); }
.step-s { font-size: 9.5px; color: var(--tu); margin-top: 1px; }
.step-line { flex: 1; height: 2px; background: var(--gb); margin: 0 10px; }
.step-line.d { background: var(--st); }

.card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }
.card-head { padding: 0.7rem 1rem; border-bottom: 1px solid var(--gb); display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.card-head-title { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--td); }
.card-head-title svg { width: 14px; height: 14px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.card-body { padding: 1rem; }

.odos { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 0.75rem; }
.eyec { background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 0.75rem; }
.eyeh { display: flex; align-items: center; gap: 6px; margin-bottom: 0.5rem; }
.elbl { width: 30px; height: 24px; border-radius: 5px; font-size: 11.5px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
.el-od { background: var(--ib); color: var(--it); }
.el-os { background: var(--pb); color: var(--pt); }
.esub { font-size: 11px; color: var(--tu); font-weight: 500; }
.sec { font-size: 10px; font-weight: 600; color: var(--tm); letter-spacing: 0.06em; text-transform: uppercase; margin: 0.75rem 0 0.4rem; }
.sec-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin: 0.75rem 0 0.4rem; }

/* Acuan autoref read-only di seksi Refraksi Subjektif (panduan, bukan input) */
.autoref-ref { display: flex; align-items: baseline; gap: 7px; margin-bottom: 0.5rem; padding: 4px 9px; background: var(--bi); border: 1px dashed var(--gb); border-radius: 6px; }
.autoref-ref-lbl { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--tu); flex-shrink: 0; }
.autoref-ref-val { font-size: 11.5px; font-weight: 600; color: var(--tm); font-variant-numeric: tabular-nums; }

.g2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; }
.g3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; }
.g4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; }
.fg { display: flex; flex-direction: column; gap: 4px; }
.fl { font-size: 10px; font-weight: 600; color: var(--tm); letter-spacing: 0.05em; text-transform: uppercase; display: flex; gap: 5px; align-items: center; cursor: default; }
.fl .hint { font-size: 9.5px; font-weight: 400; color: var(--tu); text-transform: none; letter-spacing: 0; }
.form-input { background: var(--bs); border: 1.5px solid var(--gb); border-radius: 7px; font-family: 'Inter', sans-serif; font-size: 13px; padding: 8px 11px; height: 36px; outline: none; color: var(--td); width: 100%; }
.form-input.ta { height: auto; resize: vertical; line-height: 1.5; }
.form-input:focus { border-color: var(--ga); background: #fff; box-shadow: 0 0 0 3px rgba(31, 125, 74, 0.09); }
/* Combobox (input + datalist): beri warna kontras agar beda dari input biasa + caret.
   TETAP editable — boleh pilih dari daftar ATAU ketik manual; nilai ketik tetap tersimpan. */
.form-input[list] {
  background-color: #eef6ff;
  border-color: #bcd7f5;
  background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%231d4ed8' stroke-width='2.5' stroke-linecap='round'><polyline points='6 9 12 15 18 9'/></svg>");
  background-repeat: no-repeat;
  background-position: right 8px center;
  background-size: 12px;
  padding-right: 26px;
}
.form-input[list]:focus { background-color: #fff; border-color: var(--ga); }
.form-input:focus-visible { outline: 2px solid var(--ga); outline-offset: 1px; }

.action-row { display: flex; gap: 0.5rem; margin-top: 0.85rem; flex-wrap: wrap; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 0 14px; height: 36px; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 12.5px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; }
.btn-sm { height: 28px; padding: 0 10px; font-size: 11.5px; }
.btn-lg { height: 42px; padding: 0 20px; font-size: 13px; font-weight: 600; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover { background: var(--gm); }
.btn-primary:focus-visible { outline: 2px solid var(--ga); outline-offset: 2px; }
.btn-secondary { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-secondary:hover { border-color: var(--ga); color: var(--td); background: var(--gl); }
.btn-secondary:focus-visible { outline: 2px solid var(--ga); outline-offset: 2px; }
.btn-success { background: var(--ga); color: #fff; border-color: var(--ga); }
.btn-success:hover { background: var(--gm); }
.btn-success:focus-visible { outline: 2px solid var(--gd); outline-offset: 2px; }
.btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

.alert-warn { margin-top: 0.65rem; padding: 9px 13px; background: var(--wb); border: 1px solid var(--wbd); color: var(--wt); border-radius: 8px; font-size: 11.5px; }

/* Tonometri berulang — baris pengukuran tambahan (metode tetap satu) */
.iop-extra-row { display: grid; grid-template-columns: 34px 1fr 1fr 34px; gap: 0.5rem; align-items: end; margin-top: 0.5rem; }
.iop-extra-no { font-size: 11px; font-weight: 700; color: var(--tu); height: 32px; display: flex; align-items: center; justify-content: center; font-variant-numeric: tabular-nums; }
.iop-extra-del { height: 32px; width: 34px; display: flex; align-items: center; justify-content: center; border: 1.5px solid var(--gb); border-radius: 7px; background: var(--bs); color: var(--tu); cursor: pointer; transition: all .13s; }
.iop-extra-del:hover:not(:disabled) { border-color: var(--wbd); color: var(--wt); background: var(--wb); }
.iop-extra-del:disabled { opacity: 0.5; cursor: not-allowed; }
.iop-extra-del svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

.rx-summary { background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 0.75rem; margin-bottom: 0.85rem; }
.rx-row { display: grid; grid-template-columns: 100px 1fr; gap: 0.5rem; padding: 4px 0; font-size: 12.5px; }
.rx-row b { color: var(--tm); font-weight: 600; }
.rx-row span { color: var(--td); font-weight: 500; font-variant-numeric: tabular-nums; }

/* ── Kartu Kirim ke Dokter (action card di bawah tab pemeriksaan) ────────── */
.send-card { border-color: var(--sbd); background: linear-gradient(180deg, var(--sb) 0%, var(--bc) 60%); }
.send-card-body { display: flex; align-items: center; gap: 1rem; padding: 0.9rem 1.1rem; }
.send-card-info { flex: 1; min-width: 0; }
.send-card-title { display: flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 700; color: var(--td); }
.send-card-title svg { width: 15px; height: 15px; fill: none; stroke: var(--ga); stroke-width: 2.5; stroke-linecap: round; }
.send-card-sub { font-size: 11.5px; color: var(--tm); margin-top: 3px; line-height: 1.5; }
.send-btn { flex-shrink: 0; }
.send-actions { display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; }
.send-actions .btn svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.sp { width: 14px; height: 14px; border-radius: 50%; border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; animation: ref-spin 0.7s linear infinite; }
@keyframes ref-spin { to { transform: rotate(360deg); } }

.toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 999; display: flex; flex-direction: column; gap: 6px; }
.toast { padding: 9px 13px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); min-width: 230px; }
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }

/* Perception type segmented toggle (JAUH / DEKAT) */
.seg { display: inline-flex; border: 1.5px solid var(--gb); background: var(--bs); border-radius: 8px; padding: 3px; gap: 2px; margin-bottom: 0.85rem; }
.seg-b { flex: 1; min-width: 130px; height: 32px; padding: 0 14px; background: transparent; border: none; border-radius: 6px; font-family: 'Inter', sans-serif; font-size: 12px; font-weight: 600; color: var(--tu); cursor: pointer; transition: all .14s; }
.seg-b:hover { color: var(--td); }
.seg-b:focus-visible { outline: 2px solid var(--ga); outline-offset: 1px; }
.seg-b.a { background: var(--ga); color: #fff; box-shadow: 0 1px 3px rgba(31,125,74,.25); }

/* Info alert (Rx final notice) */
.alert-info { margin-top: 0.65rem; padding: 9px 13px; background: var(--ib); border: 1px solid var(--ibd); color: var(--it); border-radius: 8px; font-size: 11.5px; display: flex; align-items: center; gap: 7px; }
.alert-info svg { width: 14px; height: 14px; flex-shrink: 0; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

/* Rx summary ADD chip */
.rx-row em { font-style: normal; font-size: 10.5px; font-weight: 700; padding: 1px 6px; border-radius: 4px; background: var(--gl); color: var(--ga); margin-left: 6px; vertical-align: middle; }

/* datetime-local consistent height */
.form-input[type="datetime-local"] { padding-right: 8px; }

/* ── Modal Resep Kacamata (inline overlay, hindari Teleport agar CSS scoped tetap) ── */
.rx-overlay { position: fixed; inset: 0; z-index: 8000; display: flex; align-items: center; justify-content: center; padding: 1.5rem; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(2px); }
.rx-modal { width: 100%; max-width: 620px; max-height: calc(100vh - 3rem); display: flex; flex-direction: column; background: var(--bc); border: 1px solid var(--gb); border-radius: 14px; box-shadow: 0 18px 50px rgba(0, 0, 0, 0.35); overflow: hidden; }
.rx-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 0.9rem 1.1rem; border-bottom: 1px solid var(--gb); flex-shrink: 0; }
.rx-close { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border: 1px solid var(--gb); border-radius: 8px; background: var(--bs); color: var(--tu); cursor: pointer; transition: all .13s; }
.rx-close:hover { border-color: var(--ga); color: var(--ga); }
.rx-close svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; }
.rx-modal-body { padding: 1.1rem; overflow-y: auto; }
.rx-modal-foot { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; padding: 0.85rem 1.1rem; border-top: 1px solid var(--gb); flex-shrink: 0; }

/* ── SOAP Refraksionis (PPA) ─────────────────────────────────────────────── */
.soap-rfx-card { margin-top: 0.75rem; }
.soap-rfx { display: flex; flex-direction: column; gap: 0.6rem; }
.soap-rfx .fl { display: flex; align-items: center; gap: 6px; }
.soap-tag { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 5px; font-size: 11px; font-weight: 800; color: #fff; }
.soap-tag.s { background: #1d4ed8; }
.soap-tag.o { background: #64748b; }
.soap-tag.a { background: #7e22ce; }
.soap-tag.p { background: #b45309; }
.soap-auto { font-size: 9.5px; font-weight: 500; font-style: italic; color: var(--tu); text-transform: none; letter-spacing: 0; }
.soap-o-derived { font-size: 11px; color: var(--tm); background: var(--bs); border: 1px dashed var(--gb); border-radius: 8px; padding: 7px 10px; line-height: 1.5; }

/* ── Modal PIN tanda tangan ──────────────────────────────────────────────── */
.pin-overlay { position: fixed; inset: 0; z-index: 9000; display: flex; align-items: center; justify-content: center; padding: 1.5rem; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(2px); }
.pin-modal { width: min(360px, 94vw); background: #fff; border-radius: 14px; padding: 1.6rem; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
.pin-title { margin: 0 0 8px; font-size: 15px; font-weight: 700; color: var(--td); }
.pin-hint { margin: 0 0 16px; font-size: 12.5px; color: var(--tm); line-height: 1.5; }
.pin-input { width: 100%; padding: 12px; border: 1px solid var(--gb); border-radius: 8px; font-size: 22px; letter-spacing: 8px; text-align: center; box-sizing: border-box; color: var(--td); }
.pin-input:focus { outline: none; border-color: var(--ga); }
.pin-err { margin: 10px 0 0; font-size: 12.5px; color: var(--et); }
.pin-actions { display: flex; gap: 0.8rem; justify-content: center; align-items: center; margin-top: 1.2rem; }
</style>
