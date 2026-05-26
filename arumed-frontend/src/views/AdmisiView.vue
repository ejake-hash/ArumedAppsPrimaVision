<script setup>
import { ref, computed, reactive, onMounted, onUnmounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useAdmisiStore } from '@/stores/admisiStore'
import { useJadwalDokterStore } from '@/stores/jadwalDokterStore'
import WilayahPicker from '@/components/master-data/WilayahPicker.vue'

const admisiStore   = useAdmisiStore()
const jadwalStore   = useJadwalDokterStore()
const router        = useRouter()

/* ============================================================
   HELPERS
   ============================================================ */
function calcAge(birth) {
  if (!birth) return ''
  const b = new Date(birth)
  if (isNaN(b.getTime())) return ''
  const now = new Date()
  let age = now.getFullYear() - b.getFullYear()
  const m = now.getMonth() - b.getMonth()
  if (m < 0 || (m === 0 && now.getDate() < b.getDate())) age--
  return age < 0 ? '' : age
}

function fmtTime(dt) {
  if (!dt) return '—'
  try { return new Date(dt).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) }
  catch { return dt }
}

function fmtDate(dt) {
  if (!dt) return '—'
  try {
    const d = new Date(dt)
    if (isNaN(d.getTime())) return '—'
    return `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')}/${d.getFullYear()}`
  } catch { return '—' }
}

function escHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]))
}

/* Map a raw queue item from API to the shape expected by the template */
function mapQueueItem(q) {
  const p = q.visit?.patient ?? {}
  const walkIn = p.name === 'Belum Terdaftar'
  return {
    id:         q.id,
    queueNo:    q.queue_number,
    noRm:       walkIn ? '—'           : (p.no_rm ?? '—'),
    name:       walkIn ? 'Belum Terdaftar' : (p.name ?? '—'),
    nik:        p.nik                  ?? '—',
    address:    p.address              ?? '—',
    phone:      p.phone                ?? '—',
    birthDate:  p.date_of_birth        ?? null,
    age:        walkIn ? null          : calcAge(p.date_of_birth),
    sex:        p.gender               ?? '—',
    guarantor:  q.visit?.guarantor_type ?? '—',
    station:    q.visit?.current_station ?? '—',
    classification: q.visit?.classification ?? '—',
    status:     q.status,
    doctor:     null,
    arrivedAt:  fmtTime(q.created_at),
    arrivedDate: fmtDate(q.visit?.visit_date ?? q.created_at),
    visitId:    q.visit_id,
    patientId:  p.id                   ?? null,
    gcSigned:   !!q.visit?.general_consent_signed_at,
    walkIn,
  }
}

/* ============================================================
   VISIT COUNTS (used by dashboard stats row)
   ============================================================ */
const vpAll      = computed(() => admisiStore.visits)
const vpBpjs     = computed(() => vpAll.value.filter(v => v.guarantor_type === 'BPJS').length)
const vpAsuransi = computed(() => vpAll.value.filter(v => !['BPJS','UMUM'].includes(v.guarantor_type)).length)
const vpBedah    = computed(() => vpAll.value.filter(v => v.current_station === 'BEDAH').length)
const vpTotal    = computed(() => admisiStore.visitsMeta.total || vpAll.value.length)

/* ============================================================
   DATE / CLOCK
   ============================================================ */
const dateStr  = ref('')
const clockStr = ref('')
const days   = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu']
const months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des']
let dateTimer = null

function updateDate() {
  const n = new Date()
  dateStr.value  = `${days[n.getDay()]}, ${n.getDate()} ${months[n.getMonth()]} ${n.getFullYear()}`
  clockStr.value = n.toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit', second:'2-digit' })
}

/* ============================================================
   STATUS MAPPING
   ============================================================ */
function uiStatus(p) {
  if (p.status === 'CANCELLED') return 'cancel'
  // Queue ADMISI sudah ditutup → cek visit.current_station untuk tampilkan station lanjutan
  if (p.status === 'COMPLETED') {
    if (['TRIASE','REFRAKSIONIS'].includes(p.station)) return 'triage'
    if (p.station === 'DOKTER') return 'doctor'
    return 'done'
  }
  if (p.status === 'CALLED') return 'called'
  if (p.status === 'IN_PROGRESS') return p.station === 'DOKTER' ? 'doctor' : 'triage'
  if (p.station === 'REFRAKSIONIS' || p.station === 'TRIASE') return 'triage'
  if (p.station === 'DOKTER') return 'doctor'
  return 'waiting'
}

const statusLabel = (s) => ({ waiting:'Menunggu', called:'Dipanggil', triage:'Triase', doctor:'Dokter', done:'Selesai', cancel:'Batal' }[s] ?? s)

/* ============================================================
   STATS (from dashboard API)
   ============================================================ */
const stats = computed(() => ({
  total:    admisiStore.stats.total,
  waiting:  admisiStore.stats.waiting  ?? 0,
  triage:   admisiStore.stats.triage   ?? 0,
  doctor:   admisiStore.stats.doctor   ?? 0,
  done:     admisiStore.stats.done     ?? 0,
  sepCount: admisiStore.stats.sep      ?? 0,
  cancel:   admisiStore.stats.cancel   ?? 0,
}))

/* ============================================================
   BPJS STATUS (from dashboard API)
   ============================================================ */
const bpjsStatusList = computed(() => {
  const nameMap = {
    VCLAIM:    'VClaim — Penerbitan SEP',
    ANTREAN:   'Antrean Online BPJS',
    ICARE:     'LUPIS — Lap. Utilisasi',
    SATUSEHAT: 'Satu Sehat (FHIR R4)',
  }
  const raw = admisiStore.bpjsStatus ?? []
  const items = raw.map(s => ({
    name: nameMap[s.system] ?? s.system,
    ok:   s.is_enabled && s.last_test_status === 'SUCCESS',
  }))
  // Fallback kalau SATUSEHAT tidak ada di response
  if (!raw.some(s => s.system === 'SATUSEHAT')) {
    items.push({ name: nameMap.SATUSEHAT, ok: false })
  }
  return items
})

/* ============================================================
   ANTRIAN (from store)
   ============================================================ */
// Skip zombie row — queue yg visit-nya sudah soft-deleted (visit null)
const mappedAntrian = computed(() =>
  admisiStore.antrian.filter(q => q.visit != null).map(mapQueueItem),
)

// Antrean yg masih perlu aksi admin: WAITING (panggil) + CALLED (daftarkan/selesai)
const callableQueue = computed(() => mappedAntrian.value.filter(p => p.status === 'WAITING' || p.status === 'CALLED'))

/* ============================================================
   VISITS (semua kunjungan hari ini — apapun stasiun-nya)
   Source untuk tabel "Seluruh Kunjungan Hari Ini".
   Bersifat union dengan walk-in kiosk yang belum didaftarkan.
   ============================================================ */
function mapVisitRow(v) {
  const p      = v.patient ?? {}
  const walkIn = p.name === 'Belum Terdaftar'
  // status untuk pewarnaan pill — derive dari current_station saja
  // (queue ADMISI mungkin sudah completed, tidak relevan di sini)
  return {
    id:         v.id,
    visitId:    v.id,
    queueNo:    v.no_antrian ?? '—',
    noRm:       walkIn ? '—' : (p.no_rm ?? '—'),
    name:       walkIn ? 'Belum Terdaftar' : (p.name ?? '—'),
    nik:        p.nik ?? '—',
    address:    p.address ?? '—',
    phone:      p.phone ?? '—',
    birthDate:  p.date_of_birth ?? null,
    age:        walkIn ? null : calcAge(p.date_of_birth),
    sex:        p.gender ?? '—',
    guarantor:  v.guarantor_type ?? '—',
    station:    v.current_station ?? '—',
    classification: v.classification ?? '—',
    doctor:     v.doctor_schedule?.employee?.name ?? null,
    status:     'WAITING',  // sentinel — uiStatus akan derive dari station
    arrivedAt:  fmtTime(v.created_at),
    arrivedDate: fmtDate(v.visit_date ?? v.created_at),
    patientId:  p.id ?? null,
    gcSigned:   !!v.general_consent_signed,
    walkIn,
  }
}

const mappedVisits = computed(() =>
  (admisiStore.visits ?? []).map(mapVisitRow),
)

function ptypeClass(g) {
  return { BPJS:'pt-bpjs', ASURANSI:'pt-asuransi', PERUSAHAAN:'pt-perusahaan', SOSIAL:'pt-sosial' }[g] ?? 'pt-umum'
}

/* ============================================================
   FILTERS + TABLE
   ============================================================ */
const filterStation   = ref('all')
const filterGuarantor = ref('all')
const tableSearch     = ref('')
const tableExpanded   = ref(false)

const stationOptions = [
  { key: 'all',          label: 'Semua Stasiun' },
  { key: 'ADMISI',       label: 'Admisi' },
  { key: 'TRIASE',       label: 'Triase / Refraksionis' },
  { key: 'DOKTER',       label: 'Dokter' },
  { key: 'FARMASI',      label: 'Farmasi' },
  { key: 'KASIR',        label: 'Kasir' },
  { key: 'BEDAH',        label: 'Bedah' },
]

function matchesStation(p, key) {
  if (key === 'all')    return true
  if (key === 'TRIASE') return ['TRIASE','REFRAKSIONIS'].includes(p.station)
  return p.station === key
}

const filteredQueue = computed(() => {
  const search = tableSearch.value.trim().toLowerCase()

  // Union: semua visits hari ini + walk-in kiosk yang belum punya visit terdaftar
  // (mappedAntrian masih dipakai supaya pasien dari kiosk yang status WAITING/CALLED
  // tetap kelihatan di tabel kunjungan walaupun visit-nya belum lengkap)
  const visitIds = new Set(mappedVisits.value.map(v => v.visitId))
  const walkInOnly = mappedAntrian.value.filter(
    p => p.walkIn && p.visitId && ! visitIds.has(p.visitId),
  )

  return [...mappedVisits.value, ...walkInOnly]
    .filter(p => p.status !== 'CANCELLED')
    .map(p => ({ ...p, ui: uiStatus(p) }))
    .filter(p => matchesStation(p, filterStation.value))
    .filter(p => filterGuarantor.value === 'all' || p.guarantor === filterGuarantor.value)
    .filter(p => !search || p.name.toLowerCase().includes(search) || p.noRm.toLowerCase().includes(search))
})

/* ============================================================
   TOAST SYSTEM
   ============================================================ */
const toasts = ref([])
let toastId = 0
function toast(type, msg) {
  const id = ++toastId
  const icons = {
    s: '<path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>',
    e: '<circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><circle cx="12" cy="16" r=".6" fill="currentColor"/>',
    w: '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><circle cx="12" cy="17" r=".6" fill="currentColor"/>',
    i: '<circle cx="12" cy="12" r="9"/><line x1="12" y1="16" x2="12" y2="12"/><circle cx="12" cy="8" r=".6" fill="currentColor"/>',
  }
  toasts.value.push({ id, type, msg, icon: icons[type] ?? icons.i })
  setTimeout(() => removeToast(id), 3500)
}
function removeToast(id) { toasts.value = toasts.value.filter(t => t.id !== id) }

/* ============================================================
   QUEUE ACTIONS
   ============================================================ */
async function callPatient(p) {
  const isRecall = p.status === 'CALLED'
  try {
    await admisiStore.panggilAntrian(p.id)
    const label = p.walkIn ? `Pasien ${p.queueNo}` : `${p.name} (${p.queueNo})`
    toast('s', `${label} ${isRecall ? 'dipanggil ulang' : 'dipanggil'} ke loket admisi`)
  } catch (e) {
    toast('e', e.message)
  }
}

/* Batalkan kunjungan (hapus visit + antrean terkait).
   Boleh dibatalkan kapan saja sebelum status SELESAI. */
async function confirmCancelKunjungan(p) {
  const label = p.walkIn ? `antrean ${p.queueNo}` : `${p.name} (${p.queueNo})`
  const stationWarn = p.station !== 'ADMISI'
    ? `\n\n⚠ Pasien sudah berada di stasiun ${p.station}. Membatalkan akan menghapus seluruh antrean & kunjungan.`
    : ''
  if (!window.confirm(`Batalkan ${label}?\nKunjungan dan nomor antrean akan dihapus dan tidak bisa dikembalikan.${stationWarn}`)) return
  try {
    await admisiStore.cancelKunjungan(p.visitId)
    toast('s', `Kunjungan ${label} dibatalkan`)
    // Refresh untuk sinkron dengan server state
    await Promise.allSettled([admisiStore.fetchAntrian(), admisiStore.fetchDashboard()])
    admisiStore.fetchVisits({ tanggal: new Date().toISOString().split('T')[0] })
  } catch (e) {
    toast('e', e.message)
  }
}

/* ============================================================
   EDIT PASIEN — modal kecil di card "Siap Dipanggil"
   ============================================================ */
const editOpen      = ref(false)
const editSaving    = ref(false)
const editPatientId = ref(null)
const editForm      = reactive({
  name: '', nik: '', gender: 'L', date_of_birth: '', phone: '', address: '',
})

function openEditPasien(p) {
  if (!p.patientId) { toast('w', 'Pasien tidak memiliki ID — coba reload data'); return }
  if (p.walkIn) { toast('w', 'Pasien walk-in belum terdaftar — gunakan tombol Daftarkan'); return }
  editPatientId.value = p.patientId
  Object.assign(editForm, {
    name:          p.name        ?? '',
    nik:           p.nik         ?? '',
    gender:        p.sex         ?? 'L',
    date_of_birth: p.birthDate   ?? '',
    phone:         p.phone === '—' ? '' : (p.phone ?? ''),
    address:       p.address === '—' ? '' : (p.address ?? ''),
  })
  editOpen.value = true
}

function closeEditPasien() { editOpen.value = false }

async function submitEditPasien() {
  if (!editPatientId.value) return
  editSaving.value = true
  try {
    const payload = {
      name:          editForm.name,
      gender:        editForm.gender,
      date_of_birth: editForm.date_of_birth || null,
      phone:         editForm.phone || null,
      address:       editForm.address || null,
    }
    await admisiStore.updatePasien(editPatientId.value, payload)
    toast('s', `Data pasien ${editForm.name} diperbarui`)
    editOpen.value = false
    await Promise.allSettled([admisiStore.fetchAntrian(), admisiStore.fetchVisits({ tanggal: new Date().toISOString().split('T')[0] })])
  } catch (e) {
    const firstErr = e.errors ? Object.values(e.errors)[0]?.[0] : null
    toast('e', firstErr ?? e.message ?? 'Gagal memperbarui data')
  } finally {
    editSaving.value = false
  }
}

/* Walk-in (dari kiosk Anjungan) — buka wizard dalam mode "merge ke Visit existing".
   submitRegistration akan call admisiApi.daftarkanWalkIn(visitId) alih-alih daftar baru. */
const walkInVisitId = ref(null)
const walkInQueueNo = ref('')

function daftarkanWalkIn(p) {
  walkInVisitId.value = p.visitId
  walkInQueueNo.value = p.queueNo
  Object.assign(form, blankForm())
  insuranceSearch.value = ''
  searchResults.value   = []
  wizardStep.value = 1
  wizardOpen.value = true
}

/* Wilayah cascading — pakai komponen WilayahPicker (emsifa external API) */

/* ============================================================
   INSURER DROPDOWN (from API — for ASURANSI / PERUSAHAAN / SOSIAL)
   ============================================================ */
const insuranceSearch       = ref('')
const insuranceDropdownOpen = ref(false)

// Filter insurer per guarantor type (kolom insurers.type:
// ASURANSI / PERUSAHAAN / SOSIAL) + filter pencarian nama.
const filteredInsurers = computed(() => {
  const q = insuranceSearch.value.trim().toLowerCase()
  const wantType = ['ASURANSI', 'PERUSAHAAN', 'SOSIAL'].includes(form.guarantor)
    ? form.guarantor
    : null
  return (admisiStore.insurers ?? [])
    .filter(i => !wantType || i.type === wantType)
    .filter(i => !q || i.name.toLowerCase().includes(q))
})

function selectInsurer(ins) {
  form.insurer_id    = ins.id
  form.insuranceName = ins.name
  insuranceSearch.value       = ins.name
  insuranceDropdownOpen.value = false
}
function onInsuranceBlur() { setTimeout(() => { insuranceDropdownOpen.value = false }, 150) }

// Reset pilihan insurer saat user ganti tipe penjamin
function setGuarantor(g) {
  if (form.guarantor === g) return
  form.guarantor     = g
  form.insurer_id    = ''
  form.insuranceName = ''
  form.insuranceNo   = ''
  insuranceSearch.value = ''
}

/* ============================================================
   DOCTOR LIST — jadwal aktif hari ini (dari /jadwal-dokter/aktif-hari-ini)
   ============================================================ */
const doctorList = computed(() =>
  jadwalStore.aktifHariIni.map(s => ({
    id:           s.id,
    name:         s.nama_dokter,
    poliklinik:   s.poliklinik || '—',
    room:         s.room,
    queuePrefix:  s.queue_prefix,
    jam:          s.start_time && s.end_time ? `${s.start_time.slice(0,5)}–${s.end_time.slice(0,5)}` : '',
    label:        `${s.nama_dokter} · ${s.poliklinik || 'Poliklinik'} · ${s.queue_prefix}${s.start_time ? ` · ${s.start_time.slice(0,5)}–${s.end_time.slice(0,5)}` : ''}`,
  })),
)
const selectedSchedule = computed(() =>
  doctorList.value.find(d => d.id === form.doctor_schedule_id) ?? null,
)

/* ============================================================
   PATIENT SEARCH (live, debounced)
   ============================================================ */
const searchResults  = ref([])
const searchLoading  = ref(false)
const showSearchDrop = ref(false)
let   _searchTimer   = null

async function searchPatient() {
  const key = form.searchKey.trim()
  if (!key) { searchResults.value = []; showSearchDrop.value = false; return }
  searchLoading.value  = true
  showSearchDrop.value = true
  try {
    searchResults.value = await admisiStore.cariPasien(key)
  } catch (e) {
    toast('e', e.message)
  } finally {
    searchLoading.value = false
  }
}

function onSearchInput() {
  clearTimeout(_searchTimer)
  const key = form.searchKey.trim()
  if (key.length >= 1) {
    _searchTimer = setTimeout(searchPatient, 300)
  } else {
    searchResults.value = []
    showSearchDrop.value = false
  }
}

function selectPatient(pt) {
  Object.assign(form, {
    found:         true,
    patientId:     pt.id,
    noRm:          pt.no_rm,
    nik:           pt.nik,
    name:          pt.name,
    sex:           pt.gender,
    birthDate:     pt.date_of_birth,
    age:           calcAge(pt.date_of_birth),
    phone:         pt.phone        ?? '',
    province:      pt.province     ?? '',
    regency:       '',
    district:      '',
    addressDetail: pt.address      ?? '',
    guarantor:     pt.bpjs_number  ? 'BPJS' : 'UMUM',
    bpjsNo:        pt.bpjs_number  ?? '',
    insurer_id:    '',
    insuranceName: '',
    insuranceNo:   '',
  })
  insuranceSearch.value = ''
  showSearchDrop.value  = false
  toast('s', `Data pasien ${pt.name} ditemukan`)
}

function onSearchBlur() { setTimeout(() => { showSearchDrop.value = false }, 200) }

/* ============================================================
   REGISTRATION WIZARD (3 steps)
   ============================================================ */
const wizardOpen  = ref(false)
const wizardStep  = ref(1)
const wizardSteps = [
  { n:1, label:'Data Pasien', sub:'Identitas & alamat' },
  { n:2, label:'Penjamin',    sub:'BPJS / Umum / Asuransi' },
  { n:3, label:'Konfirmasi',  sub:'Tinjau & cetak antrean' },
]

const blankForm = () => ({
  patientMode:   'existing',
  searchKey:     '',
  found:         false,
  patientId:     '',
  noRm:          '',
  nik:           '',
  name:          '',
  sex:           'L',
  birthDate:     '',
  age:           '',
  phone:         '',
  // Wilayah (3 level, simpan nama via WilayahPicker)
  province:      '',
  regency:       '',
  district:      '',
  addressDetail: '',
  classification: 'Baru',
  guarantor:     'BPJS',
  bpjsNo:        '',
  sepType:       'rujukan',
  referralNo:    '',
  controlNo:     '',
  bookingCode:   '',
  insurer_id:    '',
  insuranceName: '',
  insuranceNo:   '',
  doctor_schedule_id: '',
})
const form = reactive(blankForm())

const canProceedStep1 = computed(() => {
  const base = form.patientMode === 'existing'
    ? form.found && form.name
    : form.name && form.nik && form.birthDate
  return base && form.province
})

const canProceedStep2 = computed(() => {
  const pd = !!form.doctor_schedule_id
  if (form.guarantor === 'UMUM') return pd
  if (['ASURANSI','PERUSAHAAN','SOSIAL'].includes(form.guarantor)) return !!form.insurer_id && pd
  if (form.guarantor === 'BPJS') {
    if (form.sepType === 'rujukan') return !!form.bpjsNo && !!form.referralNo && pd
    if (form.sepType === 'kontrol') return !!form.bpjsNo && !!form.controlNo && pd
    return !!form.bpjsNo && !!form.bookingCode && pd
  }
  return pd
})

function openWizard() {
  walkInVisitId.value = null
  walkInQueueNo.value = ''
  Object.assign(form, blankForm())
  insuranceSearch.value = ''
  searchResults.value   = []
  wizardStep.value = 1
  wizardOpen.value = true
}
function closeWizard() {
  wizardOpen.value = false
  walkInVisitId.value = null
  walkInQueueNo.value = ''
}

function nextStep() {
  if (wizardStep.value === 1 && !canProceedStep1.value) { toast('w', 'Lengkapi data pasien & provinsi terlebih dahulu'); return }
  if (wizardStep.value === 2 && !canProceedStep2.value) { toast('w', 'Lengkapi data penjamin & pilih dokter tujuan'); return }
  if (wizardStep.value < 3) wizardStep.value++
}
function prevStep() { if (wizardStep.value > 1) wizardStep.value-- }

function setPatientMode(mode) {
  Object.assign(form, blankForm())
  form.patientMode      = mode
  insuranceSearch.value = ''
  searchResults.value   = []
}

function guarantorLabel(g) {
  return { BPJS:'BPJS Kesehatan', UMUM:'Umum / Mandiri', ASURANSI:'Asuransi Swasta', PERUSAHAAN:'Perusahaan / Rekanan', SOSIAL:'Sosial / Gratis' }[g] ?? g
}

const submitting = ref(false)
async function submitRegistration() {
  submitting.value = true
  try {
    const addressParts = [form.addressDetail, form.district, form.regency].filter(Boolean)
    const payload = {
      classification:     form.classification,
      guarantor_type:     form.guarantor,
      doctor_schedule_id: form.doctor_schedule_id,
    }

    if (form.guarantor === 'BPJS') {
      payload.bpjs_booking_code = form.sepType === 'jkn'     ? form.bookingCode : null
      payload.bpjs_referral_no  = form.sepType === 'rujukan' ? form.referralNo  : null
      payload.bpjs_control_no   = form.sepType === 'kontrol' ? form.controlNo   : null
    }
    if (['ASURANSI','PERUSAHAAN','SOSIAL'].includes(form.guarantor)) {
      payload.insurer_id = form.insurer_id
    }

    if (form.patientId) {
      payload.patient_id = form.patientId
    } else {
      Object.assign(payload, {
        nik:           form.nik,
        name:          form.name,
        gender:        form.sex,
        date_of_birth: form.birthDate,
        phone:         form.phone    || null,
        address:       addressParts.join(', ') || null,
        province:      form.province || null,
        bpjs_number:   form.bpjsNo   || null,
      })
    }

    // Branch: walk-in merge vs registrasi baru
    let visit, queueNo
    if (walkInVisitId.value) {
      visit = await admisiStore.daftarkanWalkIn(walkInVisitId.value, payload)
      queueNo = walkInQueueNo.value
    } else {
      visit = await admisiStore.daftarKunjungan(payload)
      queueNo = visit?.queues?.[0]?.queue_number ?? '—'
    }

    // Capture nama+dokter untuk tiket sebelum closeWizard reset form
    const ticketData = {
      queueNo,
      patientName: form.name,
      doctor:      selectedSchedule.value?.name ?? '',
      poliklinik:  selectedSchedule.value?.poliklinik ?? '',
      room:        selectedSchedule.value?.room ?? '',
    }

    const action = walkInVisitId.value ? 'didaftarkan dari Anjungan' : 'terdaftar'
    const msgs = {
      BPJS:     `SEP dalam proses · ${form.name} ${action} · Antrean ${queueNo}`,
      ASURANSI: `${form.name} ${action} (${form.insuranceName}) · Antrean ${queueNo}`,
    }
    toast('s', msgs[form.guarantor] ?? `${form.name} ${action} · Antrean ${queueNo}`)
    closeWizard()

    // Cetak tiket antrean ke thermal printer
    nextTick(() => printAdmisiTicket(ticketData))

    // Refresh antrian + kunjungan setelah pendaftaran
    await Promise.allSettled([admisiStore.fetchAntrian(), admisiStore.fetchDashboard()])
    admisiStore.fetchVisits({ tanggal: new Date().toISOString().split('T')[0] })
  } catch (e) {
    const firstErr = e.errors ? Object.values(e.errors)[0]?.[0] : null
    toast('e', firstErr ?? e.message ?? 'Pendaftaran gagal')
  } finally {
    submitting.value = false
  }
}

/* ============================================================
   PRINT THERMAL TIKET ANTREAN (80mm, sama style dgn AnjunganView)
   ============================================================ */
function printAdmisiTicket({ queueNo, patientName, doctor, poliklinik, room }) {
  if (!queueNo || queueNo === '—') return
  const stationDest = poliklinik
    ? `Poli ${escHtml(poliklinik)}${room ? ' · Ruang ' + escHtml(room) : ''}`
    : 'Triase / Refraksionis'
  const ticketHtml = `
    <html><head><title>Antrean ${escHtml(queueNo)}</title>
    <style>
      @page { size: 80mm auto; margin: 0; }
      * { margin:0; padding:0; box-sizing:border-box; font-family:'Helvetica Neue',Arial,sans-serif; color:#000; }
      body { width:80mm; padding:4mm 0; }
      .h { text-align:center; padding:2mm 4mm; font-size:13pt; font-weight:700; border-bottom:1px dashed #000; }
      .sub { text-align:center; font-size:8pt; padding:2mm 4mm 1mm; color:#000; letter-spacing:0.05em; text-transform:uppercase; }
      .num { text-align:center; font-size:56pt; font-weight:700; padding:2mm 0; line-height:1; }
      .sep { border-top:1px dashed #000; margin:2mm 4mm; }
      .b { text-align:center; padding:2mm 4mm; font-size:10pt; line-height:1.5; }
      .b strong { font-size:11pt; }
      .ft { text-align:center; padding:2mm 4mm 0; font-size:8pt; color:#000; }
    </style></head><body>
      <div class="h">Klinik Mata Arunika</div>
      <div class="sub">Tiket Antrean</div>
      <div class="num">${escHtml(queueNo)}</div>
      <div class="sep"></div>
      <div class="b">
        ${patientName ? `<div style="margin-bottom:2mm">${escHtml(patientName)}</div>` : ''}
        Menuju <strong>${stationDest}</strong>
        ${doctor ? `<div style="margin-top:2mm">${escHtml(doctor)}</div>` : ''}
      </div>
      <div class="ft">Simpan tiket ini sampai dipanggil</div>
    </body></html>`
  const w = window.open('', '_blank', 'width=320,height=480')
  if (!w) {
    toast('w', 'Popup diblokir browser — izinkan popup untuk cetak tiket')
    return
  }
  w.document.write(ticketHtml)
  w.document.close()
  w.focus()
  w.onload = () => { try { w.print() } catch {} }
  // Fallback kalau onload tidak fire
  setTimeout(() => { try { w.print() } catch {} }, 400)
}

function onBirthChange() { form.age = calcAge(form.birthDate) }

/* ============================================================
   DETAIL MODAL + CETAK LABEL
   ============================================================ */
const detailOpen    = ref(false)
const detailPatient = ref(null)

function openDetail(p)  { detailPatient.value = p; detailOpen.value = true }
function closeDetail()  { detailOpen.value = false }

/* ============================================================
   REKAM MEDIS — open RM list modal for a patient
   ============================================================ */
const rmOpen    = ref(false)
const rmPatient = ref(null)

const rmDocs = [
  { code:'RM-0.1', name:'General Consent',         category:'Administrasi' },
  { code:'RM-1.1', name:'Rekam Medis Rawat Jalan', category:'Klinis' },
  { code:'RM-2.1', name:'Asesmen Perawat',         category:'Klinis' },
  { code:'RM-3.1', name:'Pemeriksaan Refraksi',    category:'Klinis' },
  { code:'RM-4.1', name:'Pemeriksaan Dokter Sp.M', category:'Klinis' },
  { code:'RM-5.1', name:'Resep Obat',              category:'Farmasi' },
  { code:'RM-6.1', name:'Surat Kontrol',           category:'Administrasi' },
]

function openRekamMedis(p) { rmPatient.value = p; rmOpen.value = true }
function closeRekamMedis() { rmOpen.value = false }
function gotoRekamMedis(p) {
  if (!p?.patientId) { toast('w', 'Pasien belum memiliki ID — coba reload data'); return }
  router.push({ name: 'rekam-medis', query: { patient: p.patientId } })
}

/* ============================================================
   GENERAL CONSENT — quick open RM-0.1
   ============================================================ */
const gcOpen    = ref(false)
const gcPatient = ref(null)
const gcSigning = ref(false)

function openGeneralConsent(p) {
  if (p.gcSigned) { toast('i', `General Consent sudah ditandatangani oleh ${p.name}`); return }
  gcPatient.value = p
  gcOpen.value    = true
}
function closeGeneralConsent() { gcOpen.value = false }
async function signGeneralConsent() {
  if (!gcPatient.value) return
  gcSigning.value = true
  try {
    // TODO: wire to backend endpoint once available
    gcPatient.value.gcSigned = true
    toast('s', `General Consent ditandatangani oleh ${gcPatient.value.name}`)
    gcOpen.value = false
  } finally {
    gcSigning.value = false
  }
}

function printLabel(p) {
  const t = p ?? detailPatient.value
  if (!t) return
  const labelHtml = `
    <html><head><title>Label ${escHtml(t.name)}</title>
    <style>
      @page { size: 58mm 40mm; margin: 0; }
      * { margin:0; padding:0; box-sizing:border-box; font-family:'Courier New',monospace; }
      body { width:58mm; padding:3mm; }
      .nm { font-size:12px; font-weight:bold; }
      .rw { font-size:10px; margin-top:2px; }
      .bc { margin-top:4px; font-size:20px; letter-spacing:2px; text-align:center; }
      .qn { text-align:center; font-size:14px; font-weight:bold; margin-top:2px; }
      hr { border:none; border-top:1px dashed #000; margin:3px 0; }
    </style></head><body>
      <div class="nm">${escHtml(t.name)}</div>
      <div class="rw">No. RM : ${escHtml(t.noRm)}</div>
      <div class="rw">Lahir&nbsp;&nbsp;: ${escHtml(t.birthDate ?? t.age ?? '-')}</div>
      <div class="rw">JK&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: ${t.sex === 'L' ? 'Laki-laki' : 'Perempuan'}</div>
      <hr/>
      <div class="bc">*${escHtml(t.noRm)}*</div>
      <div class="qn">Antrean ${escHtml(t.queueNo ?? '-')}</div>
    </body></html>`
  const w = window.open('', '_blank', 'width=320,height=260')
  if (w) {
    w.document.write(labelHtml)
    w.document.close()
    w.focus()
    setTimeout(() => { w.print() }, 300)
    toast('i', `Label pasien ${t.name} dikirim ke printer`)
  } else {
    toast('e', 'Popup diblokir browser — izinkan popup untuk cetak label')
  }
}

/* ============================================================
   KEYBOARD: ESC closes the topmost open modal
   ============================================================ */
function onKeydown(e) {
  if (e.key !== 'Escape') return
  if (gcOpen.value)       { gcOpen.value = false;       return }
  if (rmOpen.value)       { rmOpen.value = false;       return }
  if (editOpen.value)     { closeEditPasien();          return }
  if (detailOpen.value)   { detailOpen.value = false;   return }
  if (wizardOpen.value)   { closeWizard();              return }
}

/* ============================================================
   LIFECYCLE — parallel fetch on mount
   ============================================================ */
onMounted(async () => {
  updateDate()
  dateTimer = setInterval(updateDate, 1000)
  window.addEventListener('keydown', onKeydown)

  const today = new Date().toISOString().split('T')[0]
  await Promise.allSettled([
    admisiStore.fetchDashboard(),
    admisiStore.fetchAntrian(),
    admisiStore.fetchVisits({ tanggal: today }),
    jadwalStore.fetchAktifHariIni(),
    admisiStore.fetchInsurers(),
  ])

  admisiStore.connectWs()
})

onUnmounted(() => {
  clearInterval(dateTimer)
  clearTimeout(_searchTimer)
  window.removeEventListener('keydown', onKeydown)
  admisiStore.disconnectWs()
})
</script>

<template>
  <div class="admisi">
    <!-- ===================== TOOLBAR ===================== -->
    <div class="toolbar">
      <div class="toolbar-info">
        <h2 class="toolbar-title">Admisi &amp; Pendaftaran</h2>
        <p class="toolbar-sub">
          {{ dateStr }}
          <span class="dot-sep">·</span>
          <span class="clock">{{ clockStr }}</span>
          <span class="dot-sep">·</span>
          {{ admisiStore.stats.total }} pasien terdaftar
        </p>
      </div>
      <button class="btn btn-primary btn-lg" @click="openWizard">
        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Daftarkan Pasien
      </button>
    </div>

    <!-- ===================== DASHBOARD STATS ===================== -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon" style="background: var(--gl)">
          <svg style="stroke: var(--ga)" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
        </div>
        <div>
          <div class="stat-val">{{ vpTotal }}</div>
          <div class="stat-lbl">Total Kunjungan</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background: #dbeafe">
          <svg style="stroke: #1d4ed8" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
        </div>
        <div>
          <div class="stat-val" style="color: #1d4ed8">{{ vpBpjs }}</div>
          <div class="stat-lbl">BPJS / JKN</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background: #fdf4ff">
          <svg style="stroke: #7e22ce" viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/></svg>
        </div>
        <div>
          <div class="stat-val" style="color: #7e22ce">{{ vpAsuransi }}</div>
          <div class="stat-lbl">Asuransi / Lain</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background: #fce7f3">
          <svg style="stroke: #be185d" viewBox="0 0 24 24"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>
        </div>
        <div>
          <div class="stat-val" style="color: #be185d">{{ vpBedah }}</div>
          <div class="stat-lbl">Bedah</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background: var(--sb)">
          <svg style="stroke: var(--st)" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
        </div>
        <div>
          <div class="stat-val" style="color: var(--st)">{{ stats.sepCount }}</div>
          <div class="stat-lbl">SEP BPJS Terbit</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background: #fee2e2">
          <svg style="stroke: #b91c1c" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div>
          <div class="stat-val" style="color: #b91c1c">{{ stats.cancel }}</div>
          <div class="stat-lbl">Batal</div>
        </div>
      </div>
    </div>

    <!-- Loading / error indicator for visits fetch -->
    <div v-if="admisiStore.visitsLoading" class="vp-loading-bar"></div>
    <div v-if="admisiStore.visitsError" class="vp-error-bar">{{ admisiStore.visitsError }}</div>

    <!-- ===================== MAIN: TENGAH + KANAN ===================== -->
    <div class="main-grid">
      <div class="center-col">
        <!-- CALLABLE QUEUE -->
        <div class="card call-card">
          <div class="card-head">
            <div>
              <div class="card-head-title">
                <svg viewBox="0 0 24 24"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/><path d="M19.07 4.93a10 10 0 010 14.14"/></svg>
                Siap Dipanggil ke Loket Admisi
              </div>
              <div class="card-head-sub">
                <span v-if="admisiStore.antrianLoading" class="spin-xs"></span>
                <span v-else>{{ callableQueue.length }} pasien menunggu panggilan</span>
              </div>
            </div>
            <span class="pill-live"><span class="live-dot"></span>ANTREAN AKTIF</span>
          </div>

          <div class="call-list">
            <div v-for="p in callableQueue" :key="p.id" class="call-row" :class="{ 'walk-in': p.walkIn }">
              <div class="call-qno">{{ p.queueNo }}</div>
              <div class="call-info">
                <template v-if="p.walkIn">
                  <div class="call-name walkin-name">Belum Terdaftar</div>
                  <div class="call-meta walkin-meta">
                    <svg viewBox="0 0 24 24" width="13" height="13" style="vertical-align:-2px; margin-right:4px"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="10" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                    Pasien dari Anjungan Mandiri — perlu didaftarkan
                  </div>
                </template>
                <template v-else>
                  <div class="call-name">{{ p.name }}</div>
                  <div class="call-meta">
                    {{ p.noRm }} <span class="dot-sep">·</span>
                    {{ p.age }} th <span class="dot-sep">·</span>
                    {{ p.sex === 'L' ? 'Laki-laki' : 'Perempuan' }}
                  </div>
                </template>
              </div>
              <span :class="['ptype-tag', p.walkIn ? 'pt-walkin' : ptypeClass(p.guarantor)]">
                {{ p.walkIn ? 'WALK-IN' : p.guarantor }}
              </span>
              <div class="call-station">
                <div class="call-station-lbl">Tujuan</div>
                <div class="call-station-val">{{ p.station }}</div>
              </div>
              <div class="call-actions">
                <button v-if="!p.walkIn" class="btn btn-secondary btn-icon" title="Lihat detail pasien" aria-label="Lihat detail pasien" @click="openDetail(p)">
                  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/></svg>
                </button>
                <button v-if="!p.walkIn" class="btn btn-secondary btn-icon" title="Edit data pasien" aria-label="Edit data pasien" @click="openEditPasien(p)">
                  <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 113 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                </button>
                <button v-if="!p.walkIn" class="btn btn-secondary btn-icon" title="Cetak label pasien" aria-label="Cetak label pasien" @click="printLabel(p)">
                  <svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="6" rx="1"/><path d="M6 8h12v6a2 2 0 01-2 2H8a2 2 0 01-2-2V8z"/><path d="M8 16v4h8v-4"/></svg>
                </button>
                <button
                  v-if="p.walkIn"
                  class="btn btn-secondary"
                  :disabled="p.status !== 'CALLED'"
                  :title="p.status === 'CALLED' ? 'Daftarkan pasien walk-in' : 'Panggil pasien terlebih dahulu'"
                  @click="daftarkanWalkIn(p)"
                >
                  <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                  Daftarkan
                </button>
                <button
                  class="btn btn-primary call-btn"
                  :title="p.status === 'CALLED' ? 'Panggil ulang pasien' : 'Panggil pasien'"
                  @click="callPatient(p)"
                >
                  <svg viewBox="0 0 24 24"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>
                  {{ p.status === 'CALLED' ? 'Panggil Ulang' : 'Panggil' }}
                </button>
              </div>
            </div>

            <div v-if="callableQueue.length === 0" class="empty-state">
              <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
              <div class="empty-title">Tidak ada antrean menunggu</div>
              <div class="empty-sub">Semua pasien sudah dilayani atau dalam proses</div>
            </div>
          </div>
        </div>

        <!-- DETAIL TABLE — collapsible -->
        <div class="card">
          <div class="card-head clickable" @click="tableExpanded = !tableExpanded">
            <div>
              <div class="card-head-title">
                <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/></svg>
                Seluruh Kunjungan Hari Ini
              </div>
              <div class="card-head-sub">{{ filteredQueue.length }} data · klik untuk {{ tableExpanded ? 'sembunyikan' : 'tampilkan' }}</div>
            </div>
            <button class="collapse-btn" :class="{ open: tableExpanded }" @click.stop="tableExpanded = !tableExpanded">
              <span>{{ tableExpanded ? 'Sembunyikan' : 'Tampilkan' }}</span>
              <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
          </div>

          <transition name="collapse">
            <div v-show="tableExpanded">
              <div class="table-toolbar">
                <select v-model="filterGuarantor" class="form-select compact" aria-label="Filter penjamin">
                  <option value="all">Semua Penjamin</option>
                  <option value="BPJS">BPJS</option>
                  <option value="UMUM">Umum</option>
                  <option value="ASURANSI">Asuransi</option>
                  <option value="PERUSAHAAN">Perusahaan</option>
                  <option value="SOSIAL">Sosial</option>
                </select>
                <select v-model="filterStation" class="form-select compact" aria-label="Filter stasiun">
                  <option v-for="s in stationOptions" :key="s.key" :value="s.key">{{ s.label }}</option>
                </select>
                <div class="input-wrap">
                  <div class="input-pfx"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></div>
                  <input v-model="tableSearch" class="form-input compact" placeholder="Cari nama / No. RM..." />
                </div>
              </div>
              <div class="table-wrap table-scroll">
                <table class="queue-table">
                  <thead>
                    <tr>
                      <th>No</th>
                      <th>No. Antrean</th>
                      <th>No. RM</th>
                      <th>Nama Pasien</th>
                      <th>Stasiun</th>
                      <th>Dokter</th>
                      <th>Tipe</th>
                      <th>Status</th>
                      <th>Tanggal</th>
                      <th>Jam</th>
                      <th>Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(p, i) in filteredQueue" :key="p.id" :class="{ 'walk-in-row': p.walkIn }">
                      <td class="td-rownum">{{ i + 1 }}</td>
                      <td><span class="q-no">{{ p.queueNo }}</span></td>
                      <td class="muted">{{ p.noRm }}</td>
                      <td>
                        <div class="td-name" :class="{ 'walkin-name': p.walkIn }">{{ p.name }}</div>
                        <div class="td-meta">
                          <template v-if="p.walkIn">Dari Anjungan Mandiri — belum didaftarkan</template>
                          <template v-else>{{ p.age }} th · {{ p.sex === 'L' ? 'Laki-laki' : 'Perempuan' }}</template>
                        </div>
                      </td>
                      <td class="td-poli">{{ p.station }}</td>
                      <td class="td-doctor">{{ p.doctor ?? '—' }}</td>
                      <td>
                        <span :class="['ptype-tag', p.walkIn ? 'pt-walkin' : ptypeClass(p.guarantor)]">
                          {{ p.walkIn ? 'WALK-IN' : p.guarantor }}
                        </span>
                      </td>
                      <td>
                        <span
                          :class="[
                            'status-pill',
                            p.ui === 'waiting' ? 'sp-wait' :
                            p.ui === 'called'  ? 'sp-called' :
                            p.ui === 'triage'  ? 'sp-triage' :
                            p.ui === 'doctor'  ? 'sp-doctor' :
                            p.ui === 'done'    ? 'sp-done' : 'sp-cancel',
                          ]"
                        >
                          {{ statusLabel(p.ui) }}
                        </span>
                      </td>
                      <td class="td-date">{{ p.arrivedDate }}</td>
                      <td class="td-time">{{ p.arrivedAt }}</td>
                      <td>
                        <div class="action-row">
                          <button class="btn btn-sm btn-secondary btn-icon" title="Detail lengkap pasien (NIK, alamat, dll)" aria-label="Detail lengkap pasien" @click="openDetail(p)">
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/></svg>
                          </button>
                          <button class="btn btn-sm btn-secondary btn-icon" title="Lihat Rekam Medis" aria-label="Lihat Rekam Medis" @click="openRekamMedis(p)">
                            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
                          </button>
                          <button
                            class="btn btn-sm btn-icon btn-gc-open"
                            disabled
                            title="General Consent — menunggu modul Form Rekam Medis"
                            aria-label="General Consent (segera hadir)"
                          >
                            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 17l2 2 4-4"/></svg>
                          </button>
                          <button class="btn btn-sm btn-secondary btn-icon" title="Cetak label pasien" aria-label="Cetak label pasien" @click="printLabel(p)">
                            <svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="6" rx="1"/><path d="M6 8h12v6a2 2 0 01-2 2H8a2 2 0 01-2-2V8z"/><path d="M8 16v4h8v-4"/></svg>
                          </button>
                          <button
                            class="btn btn-sm btn-icon btn-danger"
                            :disabled="p.station === 'SELESAI' || p.status === 'CANCELLED'"
                            :title="p.station === 'SELESAI' ? 'Kunjungan sudah selesai — tidak bisa dibatalkan' : 'Batalkan kunjungan'"
                            aria-label="Batalkan kunjungan"
                            @click="confirmCancelKunjungan(p)"
                          >
                            <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                          </button>
                          <button
                            class="btn btn-sm btn-primary call-btn"
                            :disabled="p.status === 'COMPLETED' || p.status === 'CANCELLED' || p.status === 'IN_PROGRESS'"
                            :title="p.status === 'CALLED' ? 'Panggil ulang pasien' : 'Panggil pasien'"
                            @click="callPatient(p)"
                          >
                            <svg viewBox="0 0 24 24"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>
                            {{ p.status === 'CALLED' ? 'Panggil Ulang' : 'Panggil' }}
                          </button>
                        </div>
                      </td>
                    </tr>
                    <tr v-if="filteredQueue.length === 0">
                      <td colspan="9" class="empty-row">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                        <span>Tidak ada data</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </transition>
        </div>
      </div>

      <!-- ---------- KANAN: PANEL ---------- -->
       
      <div class="right-col">
        <div class="card">
          <div class="card-head">
            <div class="card-head-title">
              <svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
              Aksi Cepat
            </div>
          </div>
          <div class="card-body actions-stack">
            <button class="btn btn-secondary btn-full" disabled title="Menunggu bridging BPJS VClaim">
              <svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              Cek Status BPJS
              <span class="badge-soon" style="margin-left:auto">Segera Hadir</span>
            </button>
            <button class="btn btn-secondary btn-full muted-btn" disabled title="Menunggu integrasi layar TV ruang tunggu">
              <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="13" rx="2"/><path d="M16 21H8M12 17v4"/></svg>
              Sinkron ke Layar TV
              <span class="badge-soon" style="margin-left:auto">Segera Hadir</span>
            </button>
          </div>
        </div>

        <div class="card">
          <div class="card-head">
            <div class="card-head-title">
              <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
              Status BPJS &amp; Integrasi
            </div>
          </div>
          <div class="card-body stack-gap">
            <div v-for="svc in bpjsStatusList" :key="svc.name" class="svc-row">
              <span class="svc-name">{{ svc.name }}</span>
              <span class="svc-state">
                <span class="svc-dot" :class="{ ok: svc.ok, down: !svc.ok }"></span>
                <span :class="['svc-label', svc.ok ? 'ok' : 'down']">{{ svc.ok ? 'Online' : 'Offline' }}</span>
              </span>
            </div>
            <div class="divider"></div>
            <div class="kv-row">
              <span class="kv-key">SEP hari ini</span>
              <span class="kv-val success">{{ stats.sepCount }} terbit</span>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-head">
            <div class="card-head-title">
              <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
              Antrean Real-time
            </div>
            <span class="pill-live"><span class="live-dot"></span>LIVE</span>
          </div>
          <div class="card-body live-list">
            <div v-for="p in mappedAntrian.slice(0, 7)" :key="p.id" class="live-row">
              <span class="live-num">{{ p.queueNo }}</span>
              <div class="live-info">
                <div class="live-name">{{ p.name }}</div>
                <div class="live-poli">{{ p.station }}</div>
              </div>
              <span
                :class="[
                  'status-pill mini',
                  uiStatus(p) === 'waiting' ? 'sp-wait' :
                  uiStatus(p) === 'called'  ? 'sp-called' :
                  uiStatus(p) === 'triage'  ? 'sp-triage' :
                  uiStatus(p) === 'doctor'  ? 'sp-doctor' : 'sp-done',
                ]"
              >
                {{ statusLabel(uiStatus(p)) }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- ===================== WIZARD MODAL: DAFTARKAN PASIEN ===================== -->
    <transition name="modal-fade">
      <div v-if="wizardOpen" class="modal-backdrop" @click.self="closeWizard">
        <div class="modal-shell">
          <div class="modal-head">
            <div>
              <div class="modal-title">
                {{ walkInVisitId ? 'Daftarkan Pasien Walk-In' : 'Pendaftaran Pasien' }}
              </div>
              <div class="modal-sub">
                <template v-if="walkInVisitId">
                  Mendaftarkan antrean
                  <strong style="color: #c08a1a">{{ walkInQueueNo }}</strong>
                  dari Anjungan Mandiri
                </template>
                <template v-else>Klinik Utama Mata · FKRTL</template>
              </div>
            </div>
            <button class="modal-x" aria-label="Tutup pendaftaran" @click="closeWizard">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>

          <div v-if="walkInVisitId" class="walkin-banner">
            <svg viewBox="0 0 24 24" width="18" height="18"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="10" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
            <span>
              Antrean <strong>{{ walkInQueueNo }}</strong> sudah diambil dari kiosk.
              Lengkapi data pasien — sistem akan menggabungkan ke kunjungan yang sama.
            </span>
          </div>

          <div class="stepper">
            <div
              v-for="s in wizardSteps"
              :key="s.n"
              :class="['step', wizardStep === s.n ? 'active' : '', wizardStep > s.n ? 'done' : '']"
            >
              <div class="step-circle">
                <svg v-if="wizardStep > s.n" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                <span v-else>{{ s.n }}</span>
              </div>
              <div class="step-text">
                <div class="step-label">{{ s.label }}</div>
                <div class="step-sub">{{ s.sub }}</div>
              </div>
              <div v-if="s.n < 3" class="step-line"></div>
            </div>
          </div>

          <div class="modal-body">
            <!-- ===== STEP 1: DATA PASIEN ===== -->
            <div v-if="wizardStep === 1" class="form-grid">
              <div class="seg-toggle full">
                <button :class="['seg', form.patientMode === 'existing' ? 'seg-on' : '']" @click="setPatientMode('existing')">
                  <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  Pasien Lama
                </button>
                <button :class="['seg', form.patientMode === 'new' ? 'seg-on' : '']" @click="setPatientMode('new')">
                  <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                  Pasien Baru
                </button>
              </div>

              <template v-if="form.patientMode === 'existing'">
                <div class="field full">
                  <label class="field-lbl">Cari berdasarkan NIK atau No. RM</label>
                  <div class="search-inline">
                    <input
                      v-model="form.searchKey"
                      class="form-input"
                      placeholder="Nama, NIK, atau No. RM pasien"
                      @keyup.enter="searchPatient"
                      @input="onSearchInput"
                      @blur="onSearchBlur"
                    />
                    <button class="btn btn-primary" :disabled="searchLoading" @click="searchPatient">
                      <svg v-if="!searchLoading" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                      <span v-else class="spin-xs"></span>
                      Cari
                    </button>
                    <transition name="modal-fade">
                      <div v-if="showSearchDrop && form.searchKey.trim()" class="combo-dropdown">
                        <div v-if="searchLoading && !searchResults.length" class="combo-empty">
                          <span class="spin-xs"></span> Mencari pasien…
                        </div>
                        <div
                          v-for="pt in searchResults"
                          :key="pt.id"
                          class="combo-item preview"
                          @mousedown="selectPatient(pt)"
                        >
                          <div class="combo-avatar">
                            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                          </div>
                          <div class="combo-info">
                            <div class="combo-name">{{ pt.name }}</div>
                            <div class="combo-meta">
                              <span class="combo-rm">RM {{ pt.no_rm }}</span>
                              <span class="combo-nik" v-if="pt.nik">· NIK {{ pt.nik }}</span>
                            </div>
                            <div class="combo-addr" v-if="pt.address">
                              <svg viewBox="0 0 24 24" width="10" height="10"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                              {{ pt.address }}
                            </div>
                          </div>
                        </div>
                        <div v-if="!searchLoading && !searchResults.length" class="combo-empty">
                          Tidak ada pasien yang cocok
                        </div>
                      </div>
                    </transition>
                  </div>
                  <div class="hint">Ketik 1 huruf saja — pencarian otomatis berdasarkan nama, NIK, atau No. RM</div>
                </div>

                <div v-if="form.found" class="found-banner full">
                  <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                  Data ditemukan — periksa kembali sebelum lanjut
                </div>
              </template>

              <div class="section-label full">Identitas</div>
              <div class="field" :class="{ full: form.patientMode === 'new' }">
                <label class="field-lbl">Nama Lengkap</label>
                <input v-model="form.name" class="form-input" :readonly="form.patientMode === 'existing'" placeholder="Nama sesuai KTP" />
              </div>
              <div class="field">
                <label class="field-lbl">NIK</label>
                <input v-model="form.nik" class="form-input" :readonly="form.patientMode === 'existing'" placeholder="16 digit NIK" />
              </div>
              <div class="field">
                <label class="field-lbl">No. Rekam Medis</label>
                <input v-model="form.noRm" class="form-input" :readonly="form.patientMode === 'existing'" placeholder="Otomatis jika pasien baru" />
              </div>
              <div class="field">
                <label class="field-lbl">Jenis Kelamin</label>
                <select v-model="form.sex" class="form-select" :disabled="form.patientMode === 'existing'">
                  <option value="L">Laki-laki</option>
                  <option value="P">Perempuan</option>
                </select>
              </div>
              <div class="field">
                <label class="field-lbl">Tanggal Lahir</label>
                <input v-model="form.birthDate" type="date" class="form-input" :readonly="form.patientMode === 'existing'" @change="onBirthChange" />
              </div>
              <div class="field">
                <label class="field-lbl">Usia</label>
                <input :value="form.age ? form.age + ' tahun' : ''" class="form-input" readonly placeholder="Otomatis" />
              </div>
              <div class="field full">
                <label class="field-lbl">No. Telepon Pasien</label>
                <input v-model="form.phone" class="form-input" :readonly="form.patientMode === 'existing'" placeholder="08xx-xxxx-xxxx" />
              </div>

              <div class="section-label full">Alamat <span class="lbl-note">(sesuai data NIK)</span></div>
              <div class="field full">
                <WilayahPicker
                  v-model:province="form.province"
                  v-model:regency="form.regency"
                  v-model:district="form.district"
                  :disabled="form.patientMode === 'existing'"
                />
              </div>
              <div class="field full">
                <label class="field-lbl">Detail Alamat (Jalan, No., RT/RW)</label>
                <input v-model="form.addressDetail" class="form-input" :readonly="form.patientMode === 'existing'" placeholder="Jl. ... No. ..., RT 000/RW 000" />
              </div>

              <div class="section-label full">Informasi Kunjungan</div>
              <div class="field full">
                <label class="field-lbl">Klasifikasi Kunjungan</label>
                <div class="classif-badges">
                  <button
                    v-for="c in ['Baru', 'Pre-Op', 'Post-Op', 'Kontrol']"
                    :key="c"
                    type="button"
                    :class="['classif-badge', form.classification === c ? 'classif-on' : '']"
                    @click="form.classification = c"
                  >{{ c }}</button>
                </div>
                <div class="hint">Pilih sesuai tujuan kunjungan pasien saat ini</div>
              </div>
            </div>

            <!-- ===== STEP 2: PENJAMIN ===== -->
            <div v-if="wizardStep === 2" class="form-grid">
              <div class="guarantor-grid full">
                <button type="button" :class="['guarantor-opt', form.guarantor === 'BPJS' ? 'g-on' : '']" @click="setGuarantor('BPJS')">
                  <svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                  <span>BPJS</span>
                </button>
                <button type="button" :class="['guarantor-opt', form.guarantor === 'UMUM' ? 'g-on' : '']" @click="setGuarantor('UMUM')">
                  <svg viewBox="0 0 24 24"><line x1="12" y1="2" x2="12" y2="22"/><line x1="2" y1="12" x2="22" y2="12"/></svg>
                  <span>Umum</span>
                </button>
                <button type="button" :class="['guarantor-opt', form.guarantor === 'ASURANSI' ? 'g-on' : '']" @click="setGuarantor('ASURANSI')">
                  <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/></svg>
                  <span>Asuransi</span>
                </button>
                <button type="button" :class="['guarantor-opt', form.guarantor === 'PERUSAHAAN' ? 'g-on' : '']" @click="setGuarantor('PERUSAHAAN')">
                  <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="1"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>
                  <span>Perusahaan</span>
                </button>
                <button type="button" :class="['guarantor-opt', form.guarantor === 'SOSIAL' ? 'g-on' : '']" @click="setGuarantor('SOSIAL')">
                  <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                  <span>Sosial</span>
                </button>
              </div>

              <!-- BPJS -->
              <template v-if="form.guarantor === 'BPJS'">
                <div class="field full">
                  <label class="field-lbl">No. Kartu BPJS</label>
                  <input v-model="form.bpjsNo" class="form-input" placeholder="13 digit nomor kartu" />
                </div>
                <div class="seg-toggle full sub">
                  <button :class="['seg', form.sepType === 'rujukan' ? 'seg-on' : '']" @click="form.sepType = 'rujukan'">
                    Rujukan FKTP
                  </button>
                  <button :class="['seg', form.sepType === 'kontrol' ? 'seg-on' : '']" @click="form.sepType = 'kontrol'">
                    Surat Kontrol
                  </button>
                  <button :class="['seg', form.sepType === 'jkn' ? 'seg-on' : '']" @click="form.sepType = 'jkn'">
                    Booking JKN
                  </button>
                </div>
                <div v-if="form.sepType === 'rujukan'" class="field full">
                  <label class="field-lbl">No. Surat Rujukan FKTP</label>
                  <input v-model="form.referralNo" class="form-input" placeholder="Nomor rujukan dari Faskes 1" />
                  <div class="hint">Wajib untuk kunjungan pertama pasien BPJS ke FKRTL</div>
                </div>
                <div v-else-if="form.sepType === 'kontrol'" class="field full">
                  <label class="field-lbl">No. Surat Kontrol (SC)</label>
                  <input v-model="form.controlNo" class="form-input" placeholder="Nomor surat kontrol sebelumnya" />
                  <div class="hint">Diterbitkan pada kunjungan terakhir — lihat <code>bpjs_control_letters</code></div>
                </div>
                <div v-else class="field full">
                  <label class="field-lbl">Kode Booking JKN Mobile</label>
                  <input v-model="form.bookingCode" class="form-input" placeholder="Kode booking dari aplikasi JKN Mobile" />
                  <div class="hint">Kode dari aplikasi Mobile JKN BPJS Kesehatan (Antrean Online)</div>
                </div>
                <div class="info-box full">
                  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="16" x2="12" y2="12"/><circle cx="12" cy="8" r=".6" fill="currentColor"/></svg>
                  <span>SEP diterbitkan otomatis via <strong>VClaim</strong> setelah konfirmasi. Pastikan status kepesertaan aktif.</span>
                </div>
              </template>

              <!-- ASURANSI -->
              <template v-else-if="form.guarantor === 'ASURANSI'">
                <div class="field full">
                  <label class="field-lbl">Nama Perusahaan Asuransi</label>
                  <div class="combo-wrap">
                    <div class="input-wrap-block">
                      <input
                        v-model="insuranceSearch"
                        class="form-input"
                        placeholder="Ketik untuk cari asuransi..."
                        @focus="insuranceDropdownOpen = true"
                        @input="insuranceDropdownOpen = true"
                        @blur="onInsuranceBlur"
                      />
                      <svg class="combo-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                    </div>
                    <transition name="modal-fade">
                      <div v-if="insuranceDropdownOpen" class="combo-dropdown">
                        <div
                          v-for="ins in filteredInsurers"
                          :key="ins.id"
                          class="combo-item"
                          :class="{ active: form.insurer_id === ins.id }"
                          @mousedown="selectInsurer(ins)"
                        >
                          <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/></svg>
                          {{ ins.name }}
                        </div>
                        <div v-if="filteredInsurers.length === 0" class="combo-empty">
                          Tidak ada penjamin tipe "{{ form.guarantor }}" — tambah via Tarif & Paket → Metode Bayar
                        </div>
                      </div>
                    </transition>
                  </div>
                  <div class="hint">Pilih dari daftar atau ketik untuk mencari</div>
                </div>
                <div class="field full">
                  <label class="field-lbl">No. Polis / Kartu Peserta</label>
                  <input v-model="form.insuranceNo" class="form-input" placeholder="Nomor polis / peserta asuransi" />
                </div>
                <div class="info-box full">
                  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="16" x2="12" y2="12"/><circle cx="12" cy="8" r=".6" fill="currentColor"/></svg>
                  <span>Verifikasi eligibilitas asuransi dilakukan manual / via portal asuransi terkait.</span>
                </div>
              </template>

              <!-- PERUSAHAAN / SOSIAL — pakai dropdown insurer (master/penjamin) -->
              <template v-else-if="['PERUSAHAAN','SOSIAL'].includes(form.guarantor)">
                <div class="field full">
                  <label class="field-lbl">{{ form.guarantor === 'PERUSAHAAN' ? 'Perusahaan / Rekanan' : 'Lembaga / Program Sosial' }}</label>
                  <div class="combo-wrap">
                    <div class="input-wrap-block">
                      <input
                        v-model="insuranceSearch"
                        class="form-input"
                        :placeholder="form.guarantor === 'PERUSAHAAN' ? 'Ketik untuk cari rekanan...' : 'Ketik untuk cari lembaga sosial...'"
                        @focus="insuranceDropdownOpen = true"
                        @input="insuranceDropdownOpen = true"
                        @blur="onInsuranceBlur"
                      />
                      <svg class="combo-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                    </div>
                    <transition name="modal-fade">
                      <div v-if="insuranceDropdownOpen" class="combo-dropdown">
                        <div
                          v-for="ins in filteredInsurers"
                          :key="ins.id"
                          class="combo-item"
                          :class="{ active: form.insurer_id === ins.id }"
                          @mousedown="selectInsurer(ins)"
                        >
                          <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/></svg>
                          {{ ins.name }}
                        </div>
                        <div v-if="filteredInsurers.length === 0" class="combo-empty">
                          Tidak ada penjamin tipe "{{ form.guarantor }}" — tambah via Tarif & Paket → Metode Bayar
                        </div>
                      </div>
                    </transition>
                  </div>
                </div>
                <div class="info-box full">
                  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="16" x2="12" y2="12"/><circle cx="12" cy="8" r=".6" fill="currentColor"/></svg>
                  <span v-if="form.guarantor === 'PERUSAHAAN'">Tarif mengikuti perjanjian kerjasama. Daftar rekanan dikelola di Master Penjamin.</span>
                  <span v-else>Pasien sosial — tidak ada biaya layanan. Daftar lembaga dikelola di Master Penjamin.</span>
                </div>
              </template>

              <!-- UMUM fallback -->
              <template v-else>
                <div class="info-box full">
                  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="16" x2="12" y2="12"/><circle cx="12" cy="8" r=".6" fill="currentColor"/></svg>
                  <span>Pasien umum — tidak memerlukan SEP. Pembayaran mandiri di kasir sesuai tarif UMUM.</span>
                </div>
              </template>

              <!-- COB — placeholder (tunggu bridging) -->
              <template v-if="form.guarantor !== 'BPJS' && form.guarantor !== 'UMUM'">
                <div class="divider full"></div>
                <div class="field full">
                  <label class="cob-toggle-row" style="opacity: 0.55; cursor: not-allowed">
                    <input type="checkbox" disabled class="cob-check" />
                    <span class="field-lbl" style="margin:0">Tambahkan Penjamin Kedua (COB)</span>
                    <span class="badge-soon" style="margin-left:auto">Segera Hadir</span>
                  </label>
                  <div class="hint">Koordinasi Manfaat — menunggu wiring backend</div>
                </div>
              </template>

              <div class="divider full"></div>
              <div class="section-label full">Tujuan Layanan</div>
              <div class="field full">
                <label class="field-lbl">Dokter Tujuan <span style="color:#ef4444">*</span></label>
                <select v-model="form.doctor_schedule_id" class="form-select" required>
                  <option value="" disabled>{{ doctorList.length ? 'Pilih dokter (jadwal aktif hari ini)' : 'Tidak ada dokter aktif hari ini' }}</option>
                  <option v-for="d in doctorList" :key="d.id" :value="d.id">{{ d.label }}</option>
                </select>
                <div v-if="selectedSchedule" class="field-hint">
                  Antrian: <strong>{{ selectedSchedule.queuePrefix }}-XXX</strong> · Poli {{ selectedSchedule.poliklinik }}<span v-if="selectedSchedule.room"> · Ruang {{ selectedSchedule.room }}</span>
                </div>
              </div>
              <!-- Diagnosis Awal BPJS — Segera Hadir (tunggu bridging VClaim) -->
              <div v-if="form.guarantor === 'BPJS'" class="field full">
                <label class="field-lbl">Diagnosis Awal (ICD-10) <span class="badge-soon">Segera Hadir</span></label>
                <input class="form-input" disabled placeholder="Akan terisi otomatis saat SEP diterbitkan via VClaim" />
                <div class="hint">Diagnosis untuk SEP akan diisi otomatis setelah bridging BPJS VClaim aktif</div>
              </div>
            </div>

            <!-- ===== STEP 3: KONFIRMASI ===== -->
            <div v-if="wizardStep === 3" class="confirm-wrap">
              <div class="confirm-card">
                <div class="confirm-sec-title">Data Pasien</div>
                <div class="confirm-grid">
                  <div class="cf"><span class="cf-k">Nama</span><span class="cf-v">{{ form.name || '—' }}</span></div>
                  <div class="cf"><span class="cf-k">No. RM</span><span class="cf-v">{{ form.noRm || 'Baru (auto)' }}</span></div>
                  <div class="cf"><span class="cf-k">NIK</span><span class="cf-v">{{ form.nik || '—' }}</span></div>
                  <div class="cf"><span class="cf-k">Jenis Kelamin</span><span class="cf-v">{{ form.sex === 'L' ? 'Laki-laki' : 'Perempuan' }}</span></div>
                  <div class="cf"><span class="cf-k">Tanggal Lahir</span><span class="cf-v">{{ form.birthDate || '—' }}</span></div>
                  <div class="cf"><span class="cf-k">Usia</span><span class="cf-v">{{ form.age ? form.age + ' th' : '—' }}</span></div>
                  <div class="cf"><span class="cf-k">Telp. Pasien</span><span class="cf-v">{{ form.phone || '—' }}</span></div>
                  <div class="cf full"><span class="cf-k">Alamat</span><span class="cf-v">{{ [form.addressDetail, form.district, form.regency, form.province].filter(Boolean).join(', ') || '—' }}</span></div>
                </div>
              </div>

              <div class="confirm-card">
                <div class="confirm-sec-title">Penjamin &amp; Layanan</div>
                <div class="confirm-grid">
                  <div class="cf">
                    <span class="cf-k">Penjamin</span>
                    <span class="cf-v">
                      <span :class="['ptype-tag', ptypeClass(form.guarantor)]">{{ guarantorLabel(form.guarantor) }}</span>
                    </span>
                  </div>
                  <div v-if="form.guarantor === 'BPJS'" class="cf"><span class="cf-k">No. BPJS</span><span class="cf-v">{{ form.bpjsNo || '—' }}</span></div>
                  <div v-if="form.guarantor === 'BPJS'" class="cf"><span class="cf-k">Tipe SEP</span><span class="cf-v">{{ { rujukan: 'Rujukan FKTP', kontrol: 'Surat Kontrol', jkn: 'Booking JKN' }[form.sepType] }}</span></div>
                  <div v-if="form.guarantor === 'BPJS' && form.sepType === 'rujukan'" class="cf"><span class="cf-k">No. Rujukan</span><span class="cf-v">{{ form.referralNo || '—' }}</span></div>
                  <div v-if="form.guarantor === 'BPJS' && form.sepType === 'kontrol'" class="cf"><span class="cf-k">No. Surat Kontrol</span><span class="cf-v">{{ form.controlNo || '—' }}</span></div>
                  <div v-if="form.guarantor === 'BPJS' && form.sepType === 'jkn'" class="cf"><span class="cf-k">Kode Booking JKN</span><span class="cf-v">{{ form.bookingCode || '—' }}</span></div>
                  <div v-if="form.guarantor === 'ASURANSI'" class="cf"><span class="cf-k">Asuransi</span><span class="cf-v">{{ form.insuranceName || '—' }}</span></div>
                  <div v-if="form.guarantor === 'ASURANSI'" class="cf"><span class="cf-k">No. Polis</span><span class="cf-v">{{ form.insuranceNo || '—' }}</span></div>
                  <div v-if="['PERUSAHAAN','SOSIAL'].includes(form.guarantor)" class="cf"><span class="cf-k">{{ form.guarantor === 'PERUSAHAAN' ? 'Rekanan' : 'Lembaga Sosial' }}</span><span class="cf-v">{{ form.insuranceName || '—' }}</span></div>
                  <div class="cf"><span class="cf-k">Klasifikasi</span><span class="cf-v"><span :class="['classif-badge classif-on-sm', 'cls-' + form.classification.replace('-','').toLowerCase()]">{{ form.classification }}</span></span></div>
                  <div class="cf"><span class="cf-k">Dokter</span><span class="cf-v">{{ selectedSchedule?.name || '—' }}</span></div>
                  <div class="cf"><span class="cf-k">Poliklinik</span><span class="cf-v">{{ selectedSchedule?.poliklinik || '—' }}</span></div>
                  <div class="cf"><span class="cf-k">Ruangan</span><span class="cf-v">{{ selectedSchedule?.room ? `Ruang ${selectedSchedule.room} (Antrian ${selectedSchedule.queuePrefix}-XXX)` : '—' }}</span></div>
                </div>
              </div>

              <div class="info-box accent">
                <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                <span v-if="form.guarantor === 'BPJS'">Klik <strong>Terbitkan SEP &amp; Cetak Antrean</strong> untuk memproses ke VClaim.</span>
                <span v-else-if="form.guarantor === 'ASURANSI'">Klik <strong>Daftarkan &amp; Cetak Antrean</strong> untuk menyelesaikan pendaftaran pasien asuransi.</span>
                <span v-else>Klik <strong>Daftarkan &amp; Cetak Antrean</strong> untuk menyelesaikan pendaftaran pasien umum.</span>
              </div>
            </div>
          </div>

          <div class="modal-foot">
            <button v-if="wizardStep > 1" class="btn btn-secondary" @click="prevStep">
              <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
              Kembali
            </button>
            <span class="foot-spacer"></span>
            <button class="btn btn-secondary" @click="closeWizard">Batal</button>
            <button v-if="wizardStep < 3" class="btn btn-primary" @click="nextStep">
              Lanjut
              <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
            <button v-else class="btn btn-success" :disabled="submitting" @click="submitRegistration">
              <span v-if="submitting" class="spin-xs"></span>
              <svg v-else viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="6" rx="1"/><path d="M6 8h12v6a2 2 0 01-2 2H8a2 2 0 01-2-2V8z"/><path d="M8 16v4h8v-4"/></svg>
              {{ submitting ? 'Mendaftarkan...' : form.guarantor === 'BPJS' ? 'Terbitkan SEP & Cetak Antrean' : 'Daftarkan & Cetak Antrean' }}
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- ===================== EDIT PASIEN MODAL ===================== -->
    <transition name="modal-fade">
      <div v-if="editOpen" class="modal-backdrop" @click.self="closeEditPasien">
        <div class="modal-shell modal-sm">
          <div class="modal-head">
            <div>
              <div class="modal-title">Edit Data Pasien</div>
              <div class="modal-sub">Perbarui identitas & kontak pasien</div>
            </div>
            <button class="modal-x" aria-label="Tutup edit pasien" @click="closeEditPasien">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <div class="modal-body">
            <div class="form-grid">
              <div class="field full">
                <label class="field-lbl">Nama Lengkap</label>
                <input v-model="editForm.name" class="form-input" placeholder="Nama sesuai KTP" />
              </div>
              <div class="field">
                <label class="field-lbl">NIK</label>
                <input v-model="editForm.nik" class="form-input" readonly maxlength="16" />
                <div class="hint">NIK tidak bisa diubah dari sini</div>
              </div>
              <div class="field">
                <label class="field-lbl">Jenis Kelamin</label>
                <select v-model="editForm.gender" class="form-select">
                  <option value="L">Laki-laki</option>
                  <option value="P">Perempuan</option>
                </select>
              </div>
              <div class="field">
                <label class="field-lbl">Tanggal Lahir</label>
                <input v-model="editForm.date_of_birth" type="date" class="form-input" />
              </div>
              <div class="field">
                <label class="field-lbl">No. Telepon</label>
                <input v-model="editForm.phone" class="form-input" placeholder="08xx..." maxlength="20" />
              </div>
              <div class="field full">
                <label class="field-lbl">Alamat</label>
                <textarea v-model="editForm.address" class="form-input" rows="2" placeholder="Alamat lengkap"></textarea>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <button class="btn btn-secondary" :disabled="editSaving" @click="closeEditPasien">Batal</button>
            <button class="btn btn-primary" :disabled="editSaving || !editForm.name" @click="submitEditPasien">
              <span v-if="editSaving" class="spin-xs"></span>
              <svg v-else viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
              Simpan Perubahan
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- ===================== DETAIL MODAL + CETAK LABEL ===================== -->
    <transition name="modal-fade">
      <div v-if="detailOpen && detailPatient" class="modal-backdrop" @click.self="closeDetail">
        <div class="modal-shell modal-sm">
          <div class="modal-head">
            <div>
              <div class="modal-title">Detail Pasien</div>
              <div class="modal-sub">{{ detailPatient.noRm }} · Antrean {{ detailPatient.queueNo }}</div>
            </div>
            <button class="modal-x" aria-label="Tutup detail pasien" @click="closeDetail">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>

          <div class="modal-body">
            <div class="detail-hero">
              <div class="detail-avatar">{{ detailPatient.name.charAt(0) }}</div>
              <div>
                <div class="detail-name">{{ detailPatient.name }}</div>
                <div class="detail-sub">
                  {{ detailPatient.age }} th · {{ detailPatient.sex === 'L' ? 'Laki-laki' : 'Perempuan' }}
                </div>
              </div>
              <span :class="['ptype-tag', ptypeClass(detailPatient.guarantor)]" style="margin-left:auto">
                {{ detailPatient.guarantor }}
              </span>
            </div>

            <div class="detail-grid">
              <div class="cf"><span class="cf-k">No. Rekam Medis</span><span class="cf-v">{{ detailPatient.noRm }}</span></div>
              <div class="cf"><span class="cf-k">NIK</span><span class="cf-v">{{ detailPatient.nik }}</span></div>
              <div class="cf"><span class="cf-k">No. Antrean</span><span class="cf-v">{{ detailPatient.queueNo }}</span></div>
              <div class="cf"><span class="cf-k">Tanggal Lahir</span><span class="cf-v">{{ detailPatient.birthDate ?? '—' }}</span></div>
              <div class="cf"><span class="cf-k">No. Telepon</span><span class="cf-v">{{ detailPatient.phone }}</span></div>
              <div class="cf"><span class="cf-k">Klasifikasi</span><span class="cf-v">{{ detailPatient.classification }}</span></div>
              <div class="cf"><span class="cf-k">Stasiun Saat Ini</span><span class="cf-v">{{ detailPatient.station }}</span></div>
              <div class="cf"><span class="cf-k">Dokter</span><span class="cf-v">{{ detailPatient.doctor ?? '—' }}</span></div>
              <div class="cf"><span class="cf-k">Status</span><span class="cf-v">{{ statusLabel(uiStatus(detailPatient)) }}</span></div>
              <div class="cf"><span class="cf-k">Jam Tiba</span><span class="cf-v">{{ detailPatient.arrivedAt }}</span></div>
              <div class="cf full"><span class="cf-k">Alamat</span><span class="cf-v">{{ detailPatient.address }}</span></div>
              <div class="cf full"><span class="cf-k">General Consent</span>
                <span class="cf-v">
                  <span v-if="detailPatient.gcSigned" class="gc-state gc-ok">Sudah ditandatangani</span>
                  <span v-else class="gc-state gc-pending">Belum ditandatangani</span>
                </span>
              </div>
            </div>
          </div>

          <div class="modal-foot">
            <button class="btn btn-secondary" @click="closeDetail">Tutup</button>
            <span class="foot-spacer"></span>
            <button class="btn btn-secondary" @click="printLabel(detailPatient)">
              <svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="6" rx="1"/><path d="M6 8h12v6a2 2 0 01-2 2H8a2 2 0 01-2-2V8z"/><path d="M8 16v4h8v-4"/></svg>
              Cetak Label
            </button>
            <button class="btn btn-secondary" @click="openRekamMedis(detailPatient); closeDetail()">
              <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              Rekam Medis
            </button>
            <button class="btn btn-primary" @click="callPatient(detailPatient); closeDetail()">
              <svg viewBox="0 0 24 24"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>
              Panggil Pasien
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- ===================== REKAM MEDIS MODAL ===================== -->
    <transition name="modal-fade">
      <div v-if="rmOpen && rmPatient" class="modal-backdrop" @click.self="closeRekamMedis">
        <div class="modal-shell modal-sm">
          <div class="modal-head">
            <div>
              <div class="modal-title">Rekam Medis</div>
              <div class="modal-sub">{{ rmPatient.name }} · {{ rmPatient.noRm }}</div>
            </div>
            <button class="modal-x" aria-label="Tutup rekam medis" @click="closeRekamMedis">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <div class="modal-body">
            <div class="rm-docs">
              <div v-for="doc in rmDocs" :key="doc.code" class="rm-doc">
                <div class="rm-doc-icon">
                  <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <div class="rm-doc-info">
                  <div class="rm-doc-name">{{ doc.name }}</div>
                  <div class="rm-doc-meta">{{ doc.code }} · {{ doc.category }}</div>
                </div>
                <span v-if="doc.code === 'RM-0.1' && rmPatient.gcSigned" class="rm-doc-tag rm-signed">Signed</span>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <button class="btn btn-secondary" @click="closeRekamMedis">Tutup</button>
            <span class="foot-spacer"></span>
            <button class="btn btn-primary" @click="gotoRekamMedis(rmPatient); closeRekamMedis()">
              <svg viewBox="0 0 24 24"><path d="M5 12h14"/><polyline points="12 5 19 12 12 19"/></svg>
              Buka Modul RME
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- ===================== GENERAL CONSENT MODAL ===================== -->
    <transition name="modal-fade">
      <div v-if="gcOpen && gcPatient" class="modal-backdrop" @click.self="closeGeneralConsent">
        <div class="modal-shell modal-sm">
          <div class="modal-head">
            <div>
              <div class="modal-title">General Consent (RM-0.1)</div>
              <div class="modal-sub">{{ gcPatient.name }} · {{ gcPatient.noRm }}</div>
            </div>
            <button class="modal-x" aria-label="Tutup general consent" @click="closeGeneralConsent">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <div class="modal-body">
            <div class="info-box">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="16" x2="12" y2="12"/><circle cx="12" cy="8" r=".6" fill="currentColor"/></svg>
              <span>Persetujuan umum mencakup persetujuan tindakan medis, pelepasan informasi, dan privasi data pasien.</span>
            </div>
            <p class="gc-text">
              Saya, <strong>{{ gcPatient.name }}</strong> (NIK: {{ gcPatient.nik }}),
              dengan ini menyetujui pelayanan kesehatan di Klinik Mata Arunika sesuai standar yang berlaku,
              termasuk pemeriksaan, pengobatan, dan penggunaan data rekam medis untuk kepentingan pelayanan.
            </p>
          </div>
          <div class="modal-foot">
            <button class="btn btn-secondary" @click="closeGeneralConsent">Batal</button>
            <span class="foot-spacer"></span>
            <button class="btn btn-success" :disabled="gcSigning" @click="signGeneralConsent">
              <span v-if="gcSigning" class="spin-xs"></span>
              <svg v-else viewBox="0 0 24 24"><path d="M3 17v3h3l11-11-3-3L3 17z"/></svg>
              Tandatangani &amp; Simpan
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- ===================== TOASTS ===================== -->
    <div class="toast-wrap">
      <div v-for="t in toasts" :key="t.id" :class="['toast', 'toast-' + t.type]">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" v-html="t.icon"></svg>
        <span>{{ t.msg }}</span>
        <button class="toast-close" aria-label="Tutup notifikasi" @click="removeToast(t.id)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.admisi { display: flex; flex-direction: column; gap: 1.25rem; }

/* TOOLBAR */
.toolbar { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; }
.toolbar-title { font-family: 'DM Serif Display', serif; font-size: 22px; color: var(--gd); line-height: 1.1; font-weight: 400; }
.toolbar-sub { font-size: 12px; color: var(--tu); margin-top: 4px; }
.dot-sep { margin: 0 6px; color: var(--th); }
.clock { font-variant-numeric: tabular-nums; font-weight: 600; color: var(--tm); }

/* STATS */
.stats-row { display: grid; grid-template-columns: repeat(6, 1fr); gap: 0.75rem; }
.stat-card { background: var(--bc); border-radius: 12px; padding: 0.9rem 1rem; border: 1px solid var(--gb); display: flex; align-items: center; gap: 10px; }
.stat-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-icon svg { width: 18px; height: 18px; fill: none; stroke-width: 2; stroke-linecap: round; }
.stat-val { font-size: 22px; font-weight: 700; color: var(--td); line-height: 1; }
.stat-lbl { font-size: 10px; color: var(--tu); margin-top: 2px; letter-spacing: 0.02em; }

/* MAIN GRID */
.main-grid { display: grid; grid-template-columns: 1fr 340px; gap: 1rem; align-items: start; }
.center-col { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }
.right-col { display: flex; flex-direction: column; gap: 1rem; }

/* CARD */
.card { background: var(--bc); border-radius: 14px; border: 1px solid var(--gb); overflow: hidden; }
.card-head { padding: 0.9rem 1.25rem; border-bottom: 1px solid var(--gb); display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.card-head.clickable { cursor: pointer; user-select: none; transition: background 0.15s; }
.card-head.clickable:hover { background: var(--bi); }
.card-head-title { font-size: 13px; font-weight: 600; color: var(--td); display: flex; align-items: center; gap: 6px; }
.card-head-title svg { width: 15px; height: 15px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.card-head-sub { font-size: 11px; color: var(--tu); margin-top: 3px; }
.card-body { padding: 1.25rem; }

/* COLLAPSE BUTTON */
.collapse-btn {
  display: inline-flex; align-items: center; gap: 6px;
  background: transparent; border: 1.5px solid var(--gb);
  border-radius: 8px; padding: 6px 12px;
  font-family: 'DM Sans', sans-serif; font-size: 12px; font-weight: 600;
  color: var(--tm); cursor: pointer; transition: all 0.15s;
}
.collapse-btn:hover { border-color: var(--ga); color: var(--gd); }
.collapse-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; transition: transform 0.25s; }
.collapse-btn.open svg { transform: rotate(180deg); }
.collapse-enter-active, .collapse-leave-active { transition: all 0.25s ease; overflow: hidden; }
.collapse-enter-from, .collapse-leave-to { opacity: 0; max-height: 0; }
.collapse-enter-to, .collapse-leave-from { opacity: 1; max-height: 2000px; }

/* TABLE TOOLBAR */
.table-toolbar { display: flex; gap: 0.5rem; align-items: center; padding: 0.85rem 1.25rem; border-bottom: 1px solid var(--gb); flex-wrap: wrap; }

/* CALLABLE QUEUE */
.call-card { border-color: var(--ga); }
.call-list { display: flex; flex-direction: column; }
.call-row { display: flex; align-items: center; gap: 14px; padding: 14px 1.25rem; border-bottom: 1px solid rgba(0,0,0,0.04); transition: background 0.15s; }
.call-row:last-child { border-bottom: none; }
.call-row:hover { background: var(--bi); }
.call-qno { font-family: 'DM Serif Display', serif; font-size: 24px; color: var(--ga); min-width: 64px; letter-spacing: 0.02em; }
.call-info { flex: 1; min-width: 0; }
.call-name { font-size: 14px; font-weight: 600; color: var(--td); }
.call-meta { font-size: 11px; color: var(--tu); margin-top: 2px; }
.call-station { text-align: right; min-width: 90px; }
.call-station-lbl { font-size: 9px; color: var(--th); text-transform: uppercase; letter-spacing: 0.05em; }
.call-station-val { font-size: 12px; font-weight: 600; color: var(--tm); margin-top: 2px; }
.call-actions { display: flex; gap: 6px; }
.call-btn {
  min-width: 100px;
  position: relative;
  overflow: hidden;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1),
              box-shadow 0.15s ease, background 0.15s ease;
  box-shadow: 0 2px 0 rgba(0, 0, 0, 0.18), 0 4px 10px rgba(31, 125, 74, 0.18);
}
.call-btn:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 3px 0 rgba(0, 0, 0, 0.20), 0 6px 14px rgba(31, 125, 74, 0.28);
}
.call-btn:active:not(:disabled) {
  transform: translateY(1px) scale(0.97);
  box-shadow: 0 1px 0 rgba(0, 0, 0, 0.20), 0 1px 4px rgba(31, 125, 74, 0.30);
  animation: callPulse 0.45s ease-out;
}
/* ripple/pulse effect saat pasien dipanggil */
@keyframes callPulse {
  0%   { box-shadow: 0 1px 0 rgba(0,0,0,0.20), 0 0 0 0 rgba(31,125,74,0.55); }
  60%  { box-shadow: 0 1px 0 rgba(0,0,0,0.20), 0 0 0 14px rgba(31,125,74,0); }
  100% { box-shadow: 0 1px 0 rgba(0,0,0,0.20), 0 0 0 0 rgba(31,125,74,0); }
}
.call-btn:disabled {
  box-shadow: none;
  opacity: 0.5;
  cursor: not-allowed;
}

/* EMPTY STATE */
.empty-state { text-align: center; padding: 3rem 1rem; }
.empty-state svg { width: 44px; height: 44px; fill: none; stroke: var(--sbd); stroke-width: 1.5; stroke-linecap: round; display: block; margin: 0 auto 0.75rem; }
.empty-title { font-size: 14px; font-weight: 600; color: var(--tm); }
.empty-sub { font-size: 12px; color: var(--tu); margin-top: 3px; }

/* BUTTONS */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 0 16px; height: 36px; border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.15s; border: 1.5px solid transparent; }
.btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover { background: var(--gm); }
.btn-success { background: var(--ga); color: #fff; border-color: var(--ga); }
.btn-success:hover { background: var(--gm); }
.btn-secondary { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-secondary:hover { border-color: var(--ga); color: var(--gd); background: var(--gl); }
.btn-danger { background: #fff1f1; color: #b91c1c; border-color: #fecaca; }
.btn-danger:hover:not(:disabled) { background: #fee2e2; border-color: #f87171; color: #991b1b; }
.btn-danger:disabled { opacity: .35; cursor: not-allowed; background: transparent; }
.btn-sm { height: 30px; padding: 0 11px; font-size: 11.5px; }
.btn-lg { height: 40px; padding: 0 20px; font-size: 14px; }
.btn-full { width: 100%; }
.btn-icon { padding: 0 9px; }
.btn-icon svg { margin: 0; }

/* Disabled state — generic */
.btn:disabled { opacity: 0.55; cursor: not-allowed; }
.btn:disabled:hover { background: inherit; color: inherit; border-color: inherit; }

/* Badge "Segera Hadir" — pakai warna kuning konsisten dgn AnjunganView */
.badge-soon {
  display: inline-flex;
  align-items: center;
  font-size: 9.5px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 20px;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  background: rgba(251, 191, 36, 0.16);
  color: #b45309;
  border: 1px solid rgba(251, 191, 36, 0.4);
  white-space: nowrap;
}

/* General Consent button variants */
.btn-gc-open   { background: #fffbeb; color: #b45309; border-color: #fde68a; }
.btn-gc-open:hover:not(:disabled) { background: #fde68a; color: #92400e; }
.btn-gc-signed { background: var(--gl); color: var(--gd); border-color: rgba(31,125,74,.3); opacity: .6; cursor: not-allowed; }
.btn-gc-signed:disabled { cursor: not-allowed; }

/* General Consent state pills (detail modal) */
.gc-state { display: inline-flex; align-items: center; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; border: 1px solid; }
.gc-ok      { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.gc-pending { background: #fffbeb; color: #b45309; border-color: #fde68a; }

/* Rekam Medis modal — doc list */
.rm-docs { display: flex; flex-direction: column; gap: 6px; }
.rm-doc  { display: flex; align-items: center; gap: 10px; border: 1px solid var(--gb); border-radius: 9px; padding: .6rem .85rem; background: var(--bc); }
.rm-doc-icon { width: 30px; height: 30px; border-radius: 7px; background: var(--bs); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.rm-doc-icon svg { width: 15px; height: 15px; fill: none; stroke: var(--ga); stroke-width: 1.5; stroke-linecap: round; }
.rm-doc-info { flex: 1; min-width: 0; }
.rm-doc-name { font-size: 12px; font-weight: 500; color: var(--td); }
.rm-doc-meta { font-size: 10px; color: var(--tu); }
.rm-doc-tag  { font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 5px; white-space: nowrap; }
.rm-signed   { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }

/* General Consent body text */
.gc-text { font-size: 12.5px; line-height: 1.5; color: var(--tm); margin-top: 0.6rem; }
.muted-btn { font-size: 12px; color: var(--tu); }

/* TABLE */
.table-wrap { overflow-x: auto; }
.table-scroll { max-height: 460px; overflow-y: auto; }
.table-scroll thead th { position: sticky; top: 0; background: var(--bc); z-index: 1; box-shadow: 0 1px 0 var(--gb); }
.queue-table { width: 100%; border-collapse: collapse; }
.queue-table th { font-size: 10px; font-weight: 600; color: var(--tu); letter-spacing: 0.06em; text-transform: uppercase; padding: 10px 14px; border-bottom: 1px solid var(--gb); text-align: left; white-space: nowrap; }
.queue-table td { padding: 11px 14px; border-bottom: 1px solid rgba(0,0,0,0.04); font-size: 12.5px; color: var(--td); vertical-align: middle; }
.queue-table tr:last-child td { border-bottom: none; }
.queue-table tr:hover td { background: var(--bi); }
.q-no { font-weight: 700; color: var(--ga); font-size: 14px; letter-spacing: 0.03em; }
.muted { font-size: 11.5px; color: var(--tu); }
.td-name { font-weight: 500; color: var(--td); }
.td-meta { font-size: 10.5px; color: var(--tu); margin-top: 2px; }
.td-poli { font-size: 12px; }
.td-doctor { font-size: 12px; color: var(--tm); }
.td-time { font-size: 12px; color: var(--tu); font-variant-numeric: tabular-nums; }
.td-date { font-size: 12px; color: var(--tu); font-variant-numeric: tabular-nums; white-space: nowrap; }
.td-rownum { font-size: 12px; color: var(--tm); font-variant-numeric: tabular-nums; text-align: center; width: 36px; }
.action-row { display: flex; gap: 4px; }
.empty-row { text-align: center !important; padding: 2.5rem !important; color: var(--th); font-size: 13px; }
.empty-row svg { width: 36px; height: 36px; fill: none; stroke: var(--gb); stroke-width: 1.5; stroke-linecap: round; display: block; margin: 0 auto 0.75rem; }

/* STATUS PILLS / TAGS */
.status-pill { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 600; padding: 3px 9px; border-radius: 20px; white-space: nowrap; border: 1px solid; }
.status-pill.mini { font-size: 9px; padding: 1px 7px; }
.sp-wait { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
.sp-called { background: #fff4d6; color: #8a5a00; border-color: #f0b429; }
.sp-triage { background: #ede9fe; color: #5b21b6; border-color: #c4b5fd; }
.sp-doctor { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
.sp-done { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.sp-cancel { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.ptype-tag { font-size: 9.5px; font-weight: 600; padding: 2px 7px; border-radius: 4px; }
.pt-bpjs { background: #dbeafe; color: #1e40af; }
.pt-umum { background: var(--gl); color: var(--ga); }
.pt-asuransi { background: #fef3c7; color: #92400e; }
.pt-walkin { background: #fff4d6; color: #92651b; border: 1px dashed #d4a73a; }

/* Banner di wizard saat mendaftarkan pasien walk-in dari kiosk */
.walkin-banner {
  display: flex; align-items: center; gap: 10px;
  margin: 0 1.5rem 0.75rem;
  padding: 10px 14px;
  background: linear-gradient(90deg, rgba(212,167,58,0.14), rgba(212,167,58,0.04));
  border: 1px solid rgba(212,167,58,0.45);
  border-left: 3px solid #d4a73a;
  border-radius: 8px;
  color: #92651b;
  font-size: 13px;
  line-height: 1.5;
}
.walkin-banner strong { color: #6e4a0f; }

/* WALK-IN row (dari kiosk anjungan, belum teridentifikasi) */
.call-row.walk-in { background: linear-gradient(90deg, rgba(212,167,58,0.08), transparent 55%); border-left: 3px solid #d4a73a; }
.call-row.walk-in .call-qno { color: #c08a1a; }
.walkin-name { color: var(--tu) !important; font-style: italic; font-weight: 500 !important; }
.walkin-meta { color: #92651b !important; display: inline-flex; align-items: center; }

/* PANEL KANAN */
.actions-stack { display: flex; flex-direction: column; gap: 0.5rem; }
.divider { height: 1px; background: var(--gb); margin: 0.25rem 0; }
.stack-gap { display: flex; flex-direction: column; gap: 0.7rem; }
.svc-row { display: flex; align-items: center; justify-content: space-between; font-size: 12px; }
.svc-name { color: var(--tm); }
.svc-state { display: flex; align-items: center; gap: 6px; }
.svc-dot { width: 7px; height: 7px; border-radius: 50%; }
.svc-dot.ok { background: var(--st); animation: blink 2s infinite; }
.svc-dot.down { background: var(--et); }
.svc-label { font-size: 10.5px; font-weight: 600; }
.svc-label.ok { color: var(--st); }
.svc-label.down { color: var(--et); }
.kv-row { display: flex; align-items: center; justify-content: space-between; font-size: 11.5px; }
.kv-key { color: var(--tu); }
.kv-val { font-weight: 600; }
.kv-val.warning { color: var(--wt); }
.kv-val.success { color: var(--ga); }
.pill-live { display: inline-flex; align-items: center; gap: 5px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); font-size: 10px; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
.live-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--st); animation: blink 1.5s infinite; }
.live-list { padding: 0.75rem; display: flex; flex-direction: column; gap: 5px; max-height: 280px; overflow-y: auto; }
.live-row { display: flex; align-items: center; gap: 8px; padding: 7px 9px; border-radius: 9px; background: var(--bi); }
.live-num { font-weight: 700; font-size: 13.5px; color: var(--ga); min-width: 44px; letter-spacing: 0.03em; }
.live-info { flex: 1; min-width: 0; }
.live-name { font-size: 11.5px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.live-poli { font-size: 10px; color: var(--tu); }

/* FORM CONTROLS */
.form-select, .form-input { background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; color: var(--td); outline: none; transition: border-color 0.15s, box-shadow 0.15s, background 0.15s; padding: 9px 11px; width: 100%; }
.field-hint { font-size: 11.5px; color: var(--tm); margin-top: 5px; padding: 6px 10px; background: rgba(31,125,74,0.06); border-radius: 7px; border-left: 2px solid var(--ga); }
.field-hint strong { color: var(--td); font-weight: 600; }
.form-select.compact, .form-input.compact { height: 34px; font-size: 12px; padding: 0 10px; width: auto; }
.form-select.compact { width: 150px; }
.form-input.compact { padding-left: 32px; width: 200px; }
.form-select:focus, .form-input:focus { border-color: var(--ga); background: #fff; box-shadow: 0 0 0 3px rgba(31,125,74,0.09); }
.form-input[readonly], .form-select:disabled { background: var(--bi); color: var(--tm); cursor: default; }
.input-wrap { position: relative; display: flex; align-items: center; width: 200px; }
.input-pfx { position: absolute; left: 10px; display: flex; align-items: center; pointer-events: none; }
.input-pfx svg { width: 14px; height: 14px; fill: none; stroke: var(--th); stroke-width: 2; stroke-linecap: round; }

/* COMBOBOX ASURANSI */
.combo-wrap { position: relative; }
.input-wrap-block { position: relative; }
.combo-caret { position: absolute; right: 11px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; fill: none; stroke: var(--tu); stroke-width: 2; stroke-linecap: round; pointer-events: none; }
.combo-dropdown { position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: var(--bc); border: 1.5px solid var(--gb); border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); max-height: 320px; overflow-y: auto; z-index: 50; padding: 4px; }
.combo-item { display: flex; align-items: center; gap: 8px; padding: 9px 11px; font-size: 12.5px; color: var(--td); border-radius: 7px; cursor: pointer; transition: background 0.12s; }
.combo-item svg { width: 13px; height: 13px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.combo-item:hover { background: var(--bi); }
.combo-item.active { background: var(--gl); color: var(--gd); font-weight: 600; }

/* Preview hasil pencarian pasien (Nama - Alamat - RM) */
.combo-item.preview { align-items: flex-start; gap: 10px; padding: 10px 12px; }
.combo-avatar { width: 32px; height: 32px; flex-shrink: 0; border-radius: 50%; background: var(--gl); display: flex; align-items: center; justify-content: center; }
.combo-avatar svg { width: 16px; height: 16px; stroke: var(--ga); }
.combo-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.combo-name { font-size: 13px; font-weight: 600; color: var(--td); display: flex; align-items: center; gap: 6px; }
.combo-tag { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 4px; letter-spacing: 0.04em; }
.combo-tag.tag-bpjs { background: #dbeafe; color: #1e40af; }
.combo-tag.tag-umum { background: var(--gl); color: var(--ga); }
.combo-meta { font-size: 11px; color: var(--tm); font-variant-numeric: tabular-nums; letter-spacing: 0.02em; }
.combo-rm { font-weight: 600; }
.combo-nik { color: var(--tu); margin-left: 4px; }
.combo-addr { font-size: 11px; color: var(--tu); display: flex; align-items: center; gap: 4px; line-height: 1.35; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.combo-addr svg { width: 10px; height: 10px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.combo-empty { padding: 14px 12px; font-size: 12px; color: var(--tu); text-align: center; display: flex; align-items: center; justify-content: center; gap: 8px; }
.combo-empty { padding: 12px; text-align: center; font-size: 12px; color: var(--tu); }

/* MODAL */
.modal-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,0.55); backdrop-filter: blur(3px); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 1.5rem; }
.modal-shell { background: var(--bc); border-radius: 18px; width: 100%; max-width: 760px; max-height: 92vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 24px 64px rgba(0,0,0,0.28); }
.modal-shell.modal-sm { max-width: 480px; }
.modal-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--gb); }
.modal-title { font-family: 'DM Serif Display', serif; font-size: 19px; color: var(--gd); }
.modal-sub { font-size: 11.5px; color: var(--tu); margin-top: 3px; }
.modal-x { background: none; border: none; cursor: pointer; color: var(--tu); padding: 4px; border-radius: 7px; transition: all 0.15s; }
.modal-x:hover { background: var(--bi); color: var(--td); }
.modal-x svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

/* STEPPER */
.stepper { display: flex; align-items: center; padding: 1.1rem 1.5rem; background: var(--bi); border-bottom: 1px solid var(--gb); }
.step { display: flex; align-items: center; flex: 1; }
.step:last-child { flex: 0; }
.step-circle { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; background: var(--bc); border: 2px solid var(--gb); color: var(--tu); flex-shrink: 0; transition: all 0.2s; }
.step-circle svg { width: 15px; height: 15px; fill: none; stroke: #fff; stroke-width: 3; stroke-linecap: round; }
.step.active .step-circle { background: var(--gd); border-color: var(--gd); color: #fff; }
.step.done .step-circle { background: var(--ga); border-color: var(--ga); }
.step-text { margin-left: 10px; }
.step-label { font-size: 12.5px; font-weight: 600; color: var(--tu); }
.step-sub { font-size: 10px; color: var(--th); margin-top: 1px; }
.step.active .step-label { color: var(--gd); }
.step.done .step-label { color: var(--td); }
.step-line { flex: 1; height: 2px; background: var(--gb); margin: 0 14px; }
.step.done .step-line { background: var(--ga); }

/* MODAL BODY / FORM GRID */
.modal-body { padding: 1.5rem; overflow-y: auto; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem 1.1rem; }
.field { display: flex; flex-direction: column; gap: 5px; }
.field.full, .full { grid-column: 1 / -1; }
.field-lbl { font-size: 11.5px; font-weight: 600; color: var(--tm); }
.section-label { font-size: 11px; font-weight: 700; color: var(--gd); text-transform: uppercase; letter-spacing: 0.05em; padding-top: 0.4rem; border-top: 1px solid var(--gb); margin-top: 0.2rem; }
.section-label:first-child { border-top: none; padding-top: 0; margin-top: 0; }
.lbl-note { font-weight: 500; color: var(--tu); text-transform: none; letter-spacing: 0; font-size: 10px; }
.hint { font-size: 10.5px; color: var(--tu); margin-top: 2px; }
.hint code { background: var(--bi); padding: 1px 5px; border-radius: 4px; font-size: 10px; color: var(--ga); font-weight: 600; }

/* SEGMENT TOGGLE */
.seg-toggle { display: flex; gap: 6px; background: var(--bi); padding: 4px; border-radius: 11px; }
.seg-toggle.sub { background: transparent; padding: 0; gap: 8px; }
.seg { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; height: 38px; border-radius: 8px; border: 1.5px solid transparent; background: transparent; font-family: 'DM Sans', sans-serif; font-size: 12.5px; font-weight: 600; color: var(--tu); cursor: pointer; transition: all 0.15s; }
.seg svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.seg:hover { color: var(--td); }
.seg-toggle.sub .seg { background: var(--bi); border-color: var(--gb); }
.seg-on { background: var(--bc); color: var(--gd); box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.seg-toggle.sub .seg-on { background: var(--gl); border-color: var(--ga); color: var(--gd); }

/* BANNERS / INFO */
.found-banner { display: flex; align-items: center; gap: 8px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 9px; padding: 10px 13px; font-size: 12px; font-weight: 500; }
.found-banner svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.info-box { display: flex; align-items: flex-start; gap: 9px; background: var(--ib); color: var(--it); border: 1px solid var(--ibd); border-radius: 9px; padding: 11px 13px; font-size: 12px; line-height: 1.5; }
.info-box.accent { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.info-box svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; margin-top: 1px; }
.search-inline { display: flex; gap: 8px; position: relative; }
.search-inline .form-input { flex: 1; }
.search-inline .btn { flex-shrink: 0; }
.search-inline .combo-dropdown { position: absolute; top: 100%; left: 0; right: 0; margin-top: 4px; }

/* CONFIRM */
.confirm-wrap { display: flex; flex-direction: column; gap: 1rem; }
.confirm-card { border: 1px solid var(--gb); border-radius: 11px; padding: 1rem 1.1rem; }
.confirm-sec-title { font-size: 12px; font-weight: 700; color: var(--gd); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.85rem; }
.confirm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.7rem 1.1rem; }
.cf { display: flex; flex-direction: column; gap: 2px; }
.cf.full { grid-column: 1 / -1; }
.cf-k { font-size: 10.5px; color: var(--tu); text-transform: uppercase; letter-spacing: 0.03em; }
.cf-v { font-size: 13px; font-weight: 500; color: var(--td); }

/* DETAIL MODAL */
.detail-hero { display: flex; align-items: center; gap: 14px; padding-bottom: 1.1rem; border-bottom: 1px solid var(--gb); margin-bottom: 1.1rem; }
.detail-avatar { width: 46px; height: 46px; border-radius: 12px; background: var(--gl); color: var(--gd); display: flex; align-items: center; justify-content: center; font-family: 'DM Serif Display', serif; font-size: 20px; flex-shrink: 0; }
.detail-name { font-size: 16px; font-weight: 600; color: var(--td); }
.detail-sub { font-size: 12px; color: var(--tu); margin-top: 2px; }
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem 1.1rem; }

/* MODAL FOOTER */
.modal-foot { display: flex; align-items: center; gap: 0.6rem; padding: 1.1rem 1.5rem; border-top: 1px solid var(--gb); background: var(--bi); }
.foot-spacer { flex: 1; }

/* MODAL TRANSITION */
.modal-fade-enter-active, .modal-fade-leave-active { transition: opacity 0.2s ease; }
.modal-fade-enter-from, .modal-fade-leave-to { opacity: 0; }
.modal-fade-enter-active .modal-shell { animation: modalPop 0.25s ease; }
@keyframes modalPop { from { opacity: 0; transform: scale(0.96) translateY(8px); } to { opacity: 1; transform: scale(1) translateY(0); } }

/* TOAST */
.toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 1100; display: flex; flex-direction: column; gap: 6px; pointer-events: none; }
.toast { display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-radius: 11px; font-size: 12.5px; font-weight: 500; border: 1px solid; pointer-events: all; min-width: 260px; max-width: 360px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); animation: slideInRight 0.3s ease; }
.toast svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; flex-shrink: 0; }
.toast-close { margin-left: auto; flex-shrink: 0; background: none; border: none; cursor: pointer; opacity: 0.5; color: inherit; padding: 0 2px; }
.toast-close:hover { opacity: 1; }
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }

@keyframes slideInRight { from { opacity: 0; transform: translateX(16px); } to { opacity: 1; transform: translateX(0); } }
@keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }

/* RESPONSIVE */
@media (max-width: 1100px) {
  .stats-row { grid-template-columns: repeat(3, 1fr); }
  .main-grid { grid-template-columns: 1fr; }
  .right-col { flex-direction: row; flex-wrap: wrap; }
  .right-col .card { flex: 1; min-width: 260px; }
}
@media (max-width: 640px) {
  .stats-row { grid-template-columns: repeat(2, 1fr); }
  .form-grid, .confirm-grid, .detail-grid { grid-template-columns: 1fr; }
  .call-row { flex-wrap: wrap; }
  .call-station { display: none; }
  .guarantor-grid { grid-template-columns: repeat(3, 1fr) !important; }
}

/* PTYPE TAGS — extended */
.pt-perusahaan { background: #f3e8ff; color: #6b21a8; }
.pt-sosial { background: #fce7f3; color: #9d174d; }

/* GUARANTOR GRID (5-option) */
.guarantor-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 6px;
  grid-column: 1 / -1;
}
.guarantor-opt {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 5px; padding: 10px 6px; border-radius: 10px;
  border: 1.5px solid var(--gb); background: var(--bs);
  font-family: 'DM Sans', sans-serif; font-size: 11.5px; font-weight: 600;
  color: var(--tu); cursor: pointer; transition: all 0.15s;
}
.guarantor-opt:hover { border-color: var(--ga); color: var(--gd); background: var(--gl); }
.guarantor-opt svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.g-on { background: var(--gl); border-color: var(--ga); color: var(--gd); box-shadow: 0 1px 4px rgba(0,0,0,0.08); }

/* CLASSIFICATION BADGE PICKER */
.classif-badges { display: flex; gap: 6px; flex-wrap: wrap; }
.classif-badge {
  padding: 5px 14px; border-radius: 20px; font-size: 12px; font-weight: 600;
  border: 1.5px solid var(--gb); background: var(--bs); color: var(--tu);
  cursor: pointer; transition: all 0.15s; font-family: 'DM Sans', sans-serif;
}
.classif-badge:hover { border-color: var(--ga); color: var(--gd); }
.classif-on { background: var(--gl); border-color: var(--ga); color: var(--gd); }
.classif-on-sm { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; background: var(--gl); border: 1px solid var(--ga); color: var(--gd); }

/* COB TOGGLE */
.cob-toggle-row { display: flex; align-items: center; gap: 8px; cursor: pointer; }
.cob-check { width: 15px; height: 15px; accent-color: var(--ga); cursor: pointer; flex-shrink: 0; }


/* LOADING / ERROR BARS */
.vp-loading-bar { height: 3px; background: linear-gradient(90deg, var(--ga), #60a5fa, var(--ga)); background-size: 200% 100%; animation: shimmer 1.4s infinite; }
.vp-error-bar { padding: .35rem 1rem; font-size: 11px; color: #b91c1c; background: #fef2f2; border-top: 1px solid #fecaca; }

/* SPINNER */
.spin-xs {
  display: inline-block; width: 12px; height: 12px;
  border: 2px solid rgba(0,0,0,.12); border-top-color: var(--ga);
  border-radius: 50%; animation: spin .6s linear infinite; vertical-align: middle;
}
@keyframes spin { to { transform: rotate(360deg); } }
@keyframes shimmer { 0%,100%{background-position:200% 0} 50%{background-position:0 0} }
</style>
