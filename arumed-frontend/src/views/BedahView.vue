<script setup>
import { ref, computed, watch, onUnmounted } from 'vue'

// ── Mock Data ──────────────────────────────────────────────────────────────────
const patients = ref([
  {
    id: 1, qNum: 'OK-001', name: 'Hendra Wijaya', rm: 'RM-2025-0005', nik: '3672010807680001',
    age: 57, gender: 'L', ptype: 'bpjs', bpjsNo: '000987654', sepNo: '09019R000123',
    classification: 'Pre-Op',
    ruang: 'OK 1', prosedur: 'Phacoemulsifikasi OD', icdProsedur: '13.41',
    dpjp: 'dr. Andi Wijaya, Sp.M', diagnosa: 'H25.9 — Katarak senilis OD',
    status: 'BERLANGSUNG', scheduledTime: '07:30', isPhaco: true,
    timIn: new Date(Date.now() - 28 * 60000),
    checklist: { identitas: true, consent: true, lokasi: true, pupil: true, alergi: true },
    tim: { operator: 'dr. Andi Wijaya, Sp.M', asisten1: 'dr. Rina Kusuma, Sp.M', asisten2: '', scrubNurse: 'Dewi Sari, S.Kep', circNurse: 'Rina Wati, Amd.Kep', anestesi: 'dr. Haris, Sp.An' },
    iolRencana: { merk: 'Alcon AcrySof', power: '+21.0', series: 'SN60WF', tipe: 'Monofocal' },
    paketBedah: { kode: 'PKB-001', nama: 'Fakoemulsifikasi (Phaco) + IOL Standar' },
    bhp: [
      { item: 'BSS 500ml', jumlah: 1, satuan: 'Botol' },
      { item: 'Viscoelastic (OVD)', jumlah: 1, satuan: 'Syringe' },
      { item: 'Keratome 2.75mm', jumlah: 1, satuan: 'Pcs' },
      { item: 'Spons Microsponge', jumlah: 4, satuan: 'Pcs' },
      { item: 'Cannula I/A', jumlah: 1, satuan: 'Pcs' },
    ],
    iolDipasang: { merk: 'Alcon AcrySof', power: '+21.0', series: 'SN60WF-L0001', tipe: 'Monofocal' },
    catatanIntra: 'Insisi kornea 2.75mm. Capsulorhexis baik. Hidrodiseksi lancar. Fakoemulsifikasi nukleus grade III. IOL in-the-bag, centred.',
    anestesi: 'Topikal',
    teknikOp: 'Phacoemulsifikasi teknik stop-and-chop dengan insisi kornea 2.75mm. Implantasi IOL lipat in-the-bag.',
    temuanIntra: 'Katarak nukleus grade III, kapsul posterior intak, zonula baik.',
    komplikasi: false, komplikasiTipe: '', komplikasiNote: '',
    diagnosaPasca: 'H25.9 — Katarak senilis OD',
    laporanFinalized: false,
    obatPasca: [
      { nama: 'Ciprofloxacin 0.3% ED', dosis: '1 tetes', freq: '4×/hari', dur: '7 hari', rute: 'Tetes OD' },
      { nama: 'Dexamethasone 0.1% ED', dosis: '1 tetes', freq: '4×/hari', dur: '14 hari', rute: 'Tetes OD' },
    ],
    instruksi: [true, true, true, true, true, true],
    resepSent: false, bhpSent: false,
    bhpLog: [],
    visusOD: '1/60', visusOS: '6/6', iopOD: '19', iopOS: '14',
  },
  {
    id: 2, qNum: 'OK-002', name: 'Siti Rahayu', rm: 'RM-2025-0012', nik: '3271010101900001',
    age: 43, gender: 'P', ptype: 'bpjs', bpjsNo: '000124567', sepNo: '09019R000128',
    classification: 'Pre-Op',
    ruang: 'OK 2', prosedur: 'Eksisi Pterygium + Konjungtiva Graft OD', icdProsedur: '11.39',
    dpjp: 'dr. Rina Kusuma, Sp.M', diagnosa: 'H11.0 — Pterigium OD Grade 3',
    status: 'MENUNGGU', scheduledTime: '09:00', isPhaco: false,
    timIn: null,
    checklist: { identitas: false, consent: false, lokasi: false, pupil: false, alergi: false },
    tim: { operator: 'dr. Rina Kusuma, Sp.M', asisten1: '', asisten2: '', scrubNurse: '', circNurse: '', anestesi: '' },
    iolRencana: { merk: '', power: '', series: '', tipe: '' },
    paketBedah: { kode: 'PKB-003', nama: 'Pterigium Eksisi + Konjungtiva Graft' },
    bhp: [
      { item: 'Spons Microsponge', jumlah: 4, satuan: 'Pcs' },
      { item: 'Silk Suture 8-0', jumlah: 2, satuan: 'Pcs' },
      { item: 'Kapas Steril', jumlah: 4, satuan: 'Lembar' },
    ],
    iolDipasang: { merk: '', power: '', series: '', tipe: '' },
    catatanIntra: '', anestesi: 'Lokal',
    teknikOp: '', temuanIntra: '', komplikasi: false, komplikasiTipe: '', komplikasiNote: '',
    diagnosaPasca: 'H11.0 — Pterigium OD Grade 3',
    laporanFinalized: false, obatPasca: [], instruksi: [false, false, false, false, false, false],
    resepSent: false, bhpSent: false, bhpLog: [],
    visusOD: '6/9', visusOS: '6/6', iopOD: '14', iopOS: '13',
  },
  {
    id: 3, qNum: 'OK-003', name: 'Budi Santoso', rm: 'RM-2025-0008', nik: '3271020202850002',
    age: 40, gender: 'L', ptype: 'umum', bpjsNo: '', sepNo: '',
    classification: 'Pre-Op',
    ruang: 'OK 1', prosedur: 'Trabekulektomi OS', icdProsedur: '12.64',
    dpjp: 'dr. Andi Wijaya, Sp.M', diagnosa: 'H40.1 — Glaukoma sudut terbuka OS',
    status: 'MENUNGGU', scheduledTime: '10:30', isPhaco: false,
    timIn: null,
    checklist: { identitas: false, consent: false, lokasi: false, pupil: false, alergi: false },
    tim: { operator: 'dr. Andi Wijaya, Sp.M', asisten1: '', asisten2: '', scrubNurse: '', circNurse: '', anestesi: '' },
    iolRencana: { merk: '', power: '', series: '', tipe: '' },
    paketBedah: { kode: 'PKB-004', nama: 'Trabekulektomi (Bedah Glaukoma)' },
    bhp: [
      { item: 'Vicryl 7-0', jumlah: 2, satuan: 'Pcs' },
      { item: 'Spons Microsponge', jumlah: 4, satuan: 'Pcs' },
      { item: 'BSS 500ml', jumlah: 1, satuan: 'Botol' },
      { item: 'Kapas Steril', jumlah: 4, satuan: 'Lembar' },
    ],
    iolDipasang: { merk: '', power: '', series: '', tipe: '' },
    catatanIntra: '', anestesi: 'Sub-Tenon',
    teknikOp: '', temuanIntra: '', komplikasi: false, komplikasiTipe: '', komplikasiNote: '',
    diagnosaPasca: 'H40.1 — Glaukoma sudut terbuka OS',
    laporanFinalized: false, obatPasca: [], instruksi: [false, false, false, false, false, false],
    resepSent: false, bhpSent: false, bhpLog: [],
    visusOD: '6/6', visusOS: '1/60', iopOD: '16', iopOS: '32',
  },
  {
    id: 4, qNum: 'OK-004', name: 'Dewi Lestari', rm: 'RM-2025-0004', nik: '3271030303900003',
    age: 35, gender: 'P', ptype: 'asn', bpjsNo: '', sepNo: 'ASN-2025-042',
    classification: 'Pre-Op',
    ruang: 'OK 2', prosedur: 'Vitrektomi Posterior OD', icdProsedur: '14.74',
    dpjp: 'dr. Andi Wijaya, Sp.M', diagnosa: 'H33.0 — Ablasio Retina OD',
    status: 'MENUNGGU', scheduledTime: '13:00', isPhaco: false,
    timIn: null,
    checklist: { identitas: true, consent: true, lokasi: false, pupil: false, alergi: false },
    tim: { operator: 'dr. Andi Wijaya, Sp.M', asisten1: 'dr. Rina Kusuma, Sp.M', asisten2: '', scrubNurse: 'Dewi Sari, S.Kep', circNurse: '', anestesi: 'dr. Haris, Sp.An' },
    iolRencana: { merk: '', power: '', series: '', tipe: '' },
    paketBedah: { kode: 'PKB-005', nama: 'Vitrektomi Posterior (Pars Plana)' },
    bhp: [
      { item: 'BSS 500ml', jumlah: 2, satuan: 'Botol' },
      { item: 'Vicryl 7-0', jumlah: 2, satuan: 'Pcs' },
      { item: 'Spons Microsponge', jumlah: 4, satuan: 'Pcs' },
    ],
    iolDipasang: { merk: '', power: '', series: '', tipe: '' },
    catatanIntra: '', anestesi: 'Umum',
    teknikOp: '', temuanIntra: '', komplikasi: false, komplikasiTipe: '', komplikasiNote: '',
    diagnosaPasca: 'H33.0 — Ablasio Retina OD',
    laporanFinalized: false, obatPasca: [], instruksi: [false, false, false, false, false, false],
    resepSent: false, bhpSent: false, bhpLog: [],
    visusOD: '1/300', visusOS: '6/6', iopOD: '12', iopOS: '15',
  },
  {
    id: 5, qNum: 'OK-005', name: 'Ahmad Fauzi', rm: 'RM-2025-0003', nik: '3271040404780004',
    age: 50, gender: 'L', ptype: 'bpjs', bpjsNo: '000111222', sepNo: '09019R000120',
    classification: 'Post-Op',
    ruang: 'OK 1', prosedur: 'Phacoemulsifikasi OS', icdProsedur: '13.41',
    dpjp: 'dr. Rina Kusuma, Sp.M', diagnosa: 'H25.9 — Katarak senilis OS',
    status: 'SELESAI', scheduledTime: '07:00', isPhaco: true,
    timIn: new Date(Date.now() - 3 * 3600000),
    checklist: { identitas: true, consent: true, lokasi: true, pupil: true, alergi: true },
    tim: { operator: 'dr. Rina Kusuma, Sp.M', asisten1: 'dr. Andi Wijaya, Sp.M', asisten2: '', scrubNurse: 'Dewi Sari, S.Kep', circNurse: 'Rina Wati, Amd.Kep', anestesi: '' },
    iolRencana: { merk: 'Bausch+Lomb enVista', power: '+20.0', series: 'MX60', tipe: 'Monofocal' },
    paketBedah: { kode: 'PKB-001', nama: 'Fakoemulsifikasi (Phaco) + IOL Standar' },
    bhp: [
      { item: 'BSS 500ml', jumlah: 1, satuan: 'Botol' },
      { item: 'Viscoelastic (OVD)', jumlah: 1, satuan: 'Syringe' },
      { item: 'Keratome 2.75mm', jumlah: 1, satuan: 'Pcs' },
      { item: 'Spons Microsponge', jumlah: 4, satuan: 'Pcs' },
      { item: 'Cannula I/A', jumlah: 1, satuan: 'Pcs' },
    ],
    iolDipasang: { merk: 'Bausch+Lomb enVista', power: '+20.0', series: 'MX60-L0088', tipe: 'Monofocal' },
    catatanIntra: 'Operasi berjalan lancar tanpa komplikasi. IOL in-the-bag.',
    anestesi: 'Topikal',
    teknikOp: 'Phacoemulsifikasi dengan IOL lipat Bausch+Lomb enVista.',
    temuanIntra: 'Katarak nukleus grade II, zonula stabil.',
    komplikasi: false, komplikasiTipe: '', komplikasiNote: '',
    diagnosaPasca: 'H25.9 — Katarak senilis OS, status post Phaco+IOL',
    laporanFinalized: true,
    obatPasca: [
      { nama: 'Ciprofloxacin 0.3% ED', dosis: '1 tetes', freq: '4×/hari', dur: '7 hari', rute: 'Tetes OS' },
      { nama: 'Prednisolone 1% ED', dosis: '1 tetes', freq: '6×/hari', dur: '14 hari', rute: 'Tetes OS' },
    ],
    instruksi: [true, true, true, true, true, true],
    resepSent: true, bhpSent: true,
    bhpLog: [{ at: '07:58', items: 'BSS 500ml ×1, Viscoelastic ×1', by: 'Dewi Sari, S.Kep' }],
    visusOD: '6/6', visusOS: '2/60', iopOD: '13', iopOS: '17',
  },
])

// ── Employee & Package Data ────────────────────────────────────────────────────
const employees = [
  { id: 1,  name: 'dr. Andi Wijaya, Sp.M',    role: 'Dokter Spesialis Mata'    },
  { id: 2,  name: 'dr. Rina Kusuma, Sp.M',     role: 'Dokter Spesialis Mata'    },
  { id: 3,  name: 'dr. Haris, Sp.An',          role: 'Dokter Spesialis Anestesi'},
  { id: 4,  name: 'dr. Yusuf Pratama, Sp.M',   role: 'Dokter Spesialis Mata'    },
  { id: 5,  name: 'Dewi Sari, S.Kep',          role: 'Perawat Bedah'            },
  { id: 6,  name: 'Rina Wati, Amd.Kep',        role: 'Perawat Bedah'            },
  { id: 7,  name: 'Slamet Riyadi, S.Kep',      role: 'Perawat Bedah'            },
  { id: 8,  name: 'Nurul Hidayah, Amd.Kep',    role: 'Perawat Bedah'            },
  { id: 9,  name: 'Bambang Sutrisno, Amd.Kep', role: 'Perawat Bedah'            },
  { id: 10, name: 'dr. Mega Puspita, Sp.An',   role: 'Dokter Spesialis Anestesi'},
]

// ── UI State ───────────────────────────────────────────────────────────────────
const qPrimaryFilter   = ref('waiting')   // 'waiting' | 'done'
const qSecondaryFilter = ref('semua')      // 'semua' | 'bpjs' | 'umum'
const qSearch = ref('')
const selP = ref(null)
const tab = ref('prabedah')
const showMulaiModal = ref(false)
const showFinalModal = ref(false)
const mulaiStep = ref(1)
const timDropdownOpen = ref({ operator: false, asisten1: false, asisten2: false, scrubNurse: false, circNurse: false, anestesi: false })
const timSearch = ref({ operator: '', asisten1: '', asisten2: '', scrubNurse: '', circNurse: '', anestesi: '' })
const pendingCallIds = ref([])
const toasts = ref([])
let toastId = 0

// ── Timer ──────────────────────────────────────────────────────────────────────
const timerTick = ref(0)
let timerInterval = null

function startTimerInterval() {
  if (timerInterval) return
  timerInterval = setInterval(() => { timerTick.value++ }, 1000)
}
function stopTimerInterval() {
  if (timerInterval) { clearInterval(timerInterval); timerInterval = null }
}

onUnmounted(stopTimerInterval)

watch(selP, (p) => {
  if (p && !p.tim.operator && p.dpjp) p.tim.operator = p.dpjp
  if (p && p.status === 'BERLANGSUNG' && p.timIn && !p.timOut) {
    startTimerInterval()
  } else {
    stopTimerInterval()
  }
})

const timerDisplay = computed(() => {
  if (!selP.value?.timIn) return '--:--:--'
  const end = selP.value.timOut ? new Date(selP.value.timOut) : new Date()
  const diff = Math.floor((end - new Date(selP.value.timIn)) / 1000)
  timerTick.value // reactive dependency
  const h = Math.floor(diff / 3600).toString().padStart(2, '0')
  const m = Math.floor((diff % 3600) / 60).toString().padStart(2, '0')
  const s = (diff % 60).toString().padStart(2, '0')
  return `${h}:${m}:${s}`
})

const timInDisplay = computed(() => {
  if (!selP.value?.timIn) return '--:--'
  return new Date(selP.value.timIn).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
})
const timOutDisplay = computed(() => {
  if (!selP.value?.timOut) return '--:--'
  return new Date(selP.value.timOut).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
})

// ── Computed ───────────────────────────────────────────────────────────────────
const filtQ = computed(() => {
  let list = patients.value
  if (qPrimaryFilter.value === 'waiting') {
    list = list.filter(p => p.status !== 'SELESAI')
  } else {
    list = list.filter(p => p.status === 'SELESAI')
  }
  if (qSecondaryFilter.value === 'bpjs') {
    list = list.filter(p => p.ptype === 'bpjs')
  } else if (qSecondaryFilter.value === 'umum') {
    list = list.filter(p => p.ptype !== 'bpjs')
  }
  if (qSearch.value) {
    const s = qSearch.value.toLowerCase()
    list = list.filter(p => p.name.toLowerCase().includes(s) || p.qNum.toLowerCase().includes(s) || p.rm.toLowerCase().includes(s))
  }
  return list
})

const cMenunggu = computed(() => patients.value.filter(p => p.status === 'MENUNGGU').length)
const cBerlangsung = computed(() => patients.value.filter(p => p.status === 'BERLANGSUNG').length)
const cSelesai = computed(() => patients.value.filter(p => p.status === 'SELESAI').length)
const belumDipanggilCount = computed(() => patients.value.filter(p => p.status !== 'SELESAI').length)

const classColor = { Baru: 'cls-baru', 'Pre-Op': 'cls-preop', 'Post-Op': 'cls-postop', Kontrol: 'cls-kontrol' }
function clsCls(c) { return classColor[c] ?? 'cls-baru' }

const checklistAllDone = computed(() => {
  if (!selP.value) return false
  return Object.values(selP.value.checklist).every(Boolean)
})

// ── Actions ────────────────────────────────────────────────────────────────────
function toast(type, msg) {
  const id = ++toastId
  toasts.value.push({ id, type, msg })
  setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id) }, 3500)
}

function pickPt(p) {
  if (selP.value?.id === p.id) return
  selP.value = p
  tab.value = 'prabedah'
  if (p.status === 'BERLANGSUNG' && p.timIn && !p.timOut) startTimerInterval()
  else stopTimerInterval()
  toast('i', `Membuka data bedah — ${p.name}`)
}

function callPt(p, e) {
  e.stopPropagation()
  if (pendingCallIds.value.includes(p.id)) return
  const isRecall = p.status !== 'MENUNGGU'
  pendingCallIds.value.push(p.id)
  toast('i', `${isRecall ? 'Memanggil ulang' : 'Memanggil'} ${p.qNum} — ${p.name} ke ${p.ruang}`)
  setTimeout(() => { pendingCallIds.value = pendingCallIds.value.filter(id => id !== p.id) }, 600)
}

function skipPt(p, e) {
  e.stopPropagation()
  const arr = patients.value
  const idx = arr.findIndex(x => x.id === p.id)
  if (idx === -1) return
  if (idx >= arr.length - 1) {
    toast('w', `${p.name} sudah di posisi paling bawah`)
    return
  }
  const next = arr[idx + 1]
  arr.splice(idx, 2, next, p)
  toast('w', `${p.name} (${p.qNum}) diturunkan 1 posisi`)
}

function fmtTime(d) {
  if (!d) return '—'
  return new Date(d).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
}

function doMulaiOperasi() {
  if (!selP.value) return
  selP.value.status = 'BERLANGSUNG'
  selP.value.timIn = new Date()
  selP.value.timOut = null
  tab.value = 'intraop'
  showMulaiModal.value = false
  startTimerInterval()
  toast('s', 'Operasi dimulai — Timer Time In berjalan')
}

function doTimeOut() {
  if (!selP.value) return
  selP.value.timOut = new Date()
  stopTimerInterval()
  toast('s', `Time Out: ${timOutDisplay.value} — Durasi ${timerDisplay.value}`)
}

// BHP
const newBhp = ref({ item: '', jumlah: 1, satuan: 'Pcs' })
const bhpOptions = ['BSS 500ml', 'Viscoelastic (OVD)', 'Spatula Sinskey', 'Spons Microsponge', 'Kapas Steril', 'Silk Suture 8-0', 'Vicryl 7-0', 'Cannula I/A', 'Keratome 2.75mm', 'Cystitome', 'Tampon Lensa']
function addBhp() {
  if (!selP.value || !newBhp.value.item) { toast('w', 'Pilih item BHP'); return }
  selP.value.bhp.push({ ...newBhp.value })
  newBhp.value = { item: '', jumlah: 1, satuan: 'Pcs' }
  toast('s', 'BHP ditambahkan')
}
function removeBhp(idx) { selP.value?.bhp.splice(idx, 1) }

// Obat Pasca Bedah
const newObat = ref({ nama: '', dosis: '1 tetes', freq: '4×/hari', dur: '7 hari', rute: 'Tetes OD' })
const quickObat = ['Ciprofloxacin 0.3% ED', 'Dexamethasone 0.1% ED', 'Ketorolac 0.5% ED', 'Prednisolone 1% ED', 'Tobramycin 0.3% ED', 'Timolol 0.5% ED']
function addObat() {
  if (!selP.value || !newObat.value.nama.trim()) { toast('w', 'Nama obat wajib'); return }
  selP.value.obatPasca.push({ ...newObat.value })
  newObat.value = { nama: '', dosis: '1 tetes', freq: '4×/hari', dur: '7 hari', rute: 'Tetes OD' }
  toast('s', 'Obat pasca bedah ditambahkan')
}
function removeObat(idx) { selP.value?.obatPasca.splice(idx, 1) }
function quickAddObat(nama) { newObat.value.nama = nama }

function kirimResep() {
  if (!selP.value || !selP.value.obatPasca.length) { toast('w', 'Belum ada obat ditambahkan'); return }
  selP.value.resepSent = true
  toast('s', 'Resep pasca bedah terkirim ke Farmasi')
}

function doFinalisasi() {
  if (!selP.value) return
  selP.value.laporanFinalized = true
  selP.value.status = 'SELESAI'
  selP.value.timOut = selP.value.timOut || new Date()
  stopTimerInterval()
  showFinalModal.value = false
  toast('s', 'Laporan operasi difinalisasi — status SELESAI')
}

const instruksiList = [
  'Tidak menggosok atau mengucek mata',
  'Teteskan obat sesuai jadwal yang diberikan',
  'Hindari paparan debu, asap, dan air kotor',
  'Kontrol ulang ke klinik sesuai jadwal',
  'Tidak berenang selama 4 minggu',
  'Segera kembali jika: nyeri hebat, penglihatan turun mendadak, atau mata merah',
]

// ── Tim Bedah Combobox ─────────────────────────────────────────────────────────
function filteredEmployees(key) {
  const q = timSearch.value[key].toLowerCase()
  return q ? employees.filter(e => e.name.toLowerCase().includes(q)) : employees
}
function pickEmployee(key, emp) {
  selP.value.tim[key] = emp.name
  timSearch.value[key] = ''
  timDropdownOpen.value[key] = false
}
function openTimDropdown(key) {
  Object.keys(timDropdownOpen.value).forEach(k => { if (k !== key) timDropdownOpen.value[k] = false })
  timDropdownOpen.value[key] = true
}

// ── Mulai Operasi 2-Step Modal ─────────────────────────────────────────────────
function openMulaiModal() { mulaiStep.value = 1; showMulaiModal.value = true }
function mulaiNext() { mulaiStep.value = 2 }
function mulaiBack() { mulaiStep.value = 1 }

</script>

<template>
  <div class="bd-wrap">
    <!-- ── LEFT PANEL ────────────────────────────────────────────── -->
    <aside class="bd-qpanel">
      <div class="bd-qhd">
        <div class="bd-qhd-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"/>
          </svg>
          <span>Antrean Bedah</span>
          <span class="pill-live"><span class="live-dot"></span>LIVE</span>
        </div>
        <div class="bd-stats">
          <div class="bd-stat bd-stat-w">
            <span class="bd-stat-n">{{ cMenunggu }}</span>
            <span class="bd-stat-l">Menunggu</span>
          </div>
          <div class="bd-stat bd-stat-b">
            <span class="bd-stat-n">{{ cBerlangsung }}</span>
            <span class="bd-stat-l">Berlangsung</span>
          </div>
          <div class="bd-stat bd-stat-s">
            <span class="bd-stat-n">{{ cSelesai }}</span>
            <span class="bd-stat-l">Selesai</span>
          </div>
        </div>
      </div>

      <div class="bd-qbody">
        <!-- Primary filter -->
        <div class="primary-filter" role="group" aria-label="Filter utama antrean">
          <button :class="['pf-btn', qPrimaryFilter === 'waiting' ? 'a' : '']" @click="qPrimaryFilter = 'waiting'">
            Belum Dipanggil <span v-if="belumDipanggilCount" class="pf-ct">{{ belumDipanggilCount }}</span>
          </button>
          <button :class="['pf-btn', qPrimaryFilter === 'done' ? 'a' : '']" @click="qPrimaryFilter = 'done'">
            Selesai <span v-if="cSelesai" class="pf-ct">{{ cSelesai }}</span>
          </button>
        </div>

        <!-- Secondary filter -->
        <div class="ptype-tabs" role="group" aria-label="Filter jenis penjamin">
          <button :class="['ptype-tab', qSecondaryFilter === 'semua' ? 'a' : '']" @click="qSecondaryFilter = 'semua'">Semua</button>
          <button :class="['ptype-tab ptype-bpjs', qSecondaryFilter === 'bpjs'  ? 'a' : '']" @click="qSecondaryFilter = 'bpjs'">BPJS</button>
          <button :class="['ptype-tab ptype-umum', qSecondaryFilter === 'umum'  ? 'a' : '']" @click="qSecondaryFilter = 'umum'">Umum/Asuransi</button>
        </div>

        <div class="q-search-wrap">
          <input v-model="qSearch" class="q-search" placeholder="Cari nama / nomor OK / RM…" />
        </div>

        <div v-if="!filtQ.length" class="empty-section">Tidak ada pasien dalam filter ini</div>

        <div v-else role="list" aria-label="Daftar antrean bedah">
          <div
            v-for="p in filtQ" :key="p.id"
            role="listitem"
            :class="['q-item',
              selP?.id === p.id ? 'active' : '',
              p.status === 'SELESAI' ? 'done' : '',
              p.status === 'BERLANGSUNG' ? 'live' : '',
            ]"
            tabindex="0"
            @click="pickPt(p)"
            @keydown.enter="pickPt(p)"
          >
            <div class="qi-left">
              <div class="q-num">{{ p.qNum }}</div>
              <span :class="['pill', `pill-${p.status.toLowerCase().replace('berlangsung','proses')}`]">
                <span v-if="p.status === 'BERLANGSUNG'" class="live-dot sm"></span>
                {{ p.status === 'MENUNGGU' ? 'Menunggu' : p.status === 'BERLANGSUNG' ? 'Proses' : 'Selesai' }}
              </span>
            </div>

            <div class="q-info">
              <div class="q-name">{{ p.name }}</div>
              <div class="q-meta">{{ p.age }} th · {{ p.gender }} · {{ p.rm }}</div>
              <div class="q-prosedur">{{ p.prosedur }}</div>
              <div class="q-tags">
                <span :class="['pill', p.ptype === 'bpjs' ? 'pill-bpjs' : 'pill-umum']">
                  {{ p.ptype.toUpperCase() }}
                </span>
                <span v-if="p.classification" :class="['pill', clsCls(p.classification)]">{{ p.classification }}</span>
                <span class="pill pill-ruang">{{ p.ruang }}</span>
                <span class="pill pill-time">{{ p.scheduledTime }}</span>
              </div>
              <div v-if="p.status !== 'SELESAI'" class="q-actions" @click.stop>
                <button
                  :class="['q-act-btn', 'call', p.status !== 'MENUNGGU' ? 'recall' : '']"
                  :disabled="pendingCallIds.includes(p.id)"
                  @click="callPt(p, $event)"
                >
                  <svg v-if="p.status === 'MENUNGGU'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 014.69 12a19.79 19.79 0 01-3.07-8.67A2 2 0 013.6 1.27h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L7.91 8.91a16 16 0 006.18 6.18l.96-.96a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                  <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
                  {{ p.status === 'MENUNGGU' ? 'Panggil' : 'Panggil Ulang' }}
                </button>
                <button class="q-act-btn skip" @click="skipPt(p, $event)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="7 13 12 18 17 13"/><polyline points="7 6 12 11 17 6"/></svg>
                  Lewati
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </aside>

    <!-- ── RIGHT PANEL ───────────────────────────────────────────── -->
    <main class="bd-main">
      <!-- Empty state -->
      <div v-if="!selP" class="bd-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"/><circle cx="12" cy="14" r="3"/></svg>
        <h3>Pilih Pasien Bedah</h3>
        <p>Klik pasien di panel kiri untuk membuka data bedah</p>
      </div>

      <template v-else>
        <!-- Patient Banner -->
        <div class="bd-banner">
          <div class="bd-banner-left">
            <div class="bd-banner-name">{{ selP.name }}</div>
            <div class="bd-banner-meta">
              {{ selP.rm }} &middot; {{ selP.age }} thn &middot; {{ selP.gender === 'L' ? 'Laki-laki' : 'Perempuan' }}
            </div>
            <div class="bd-banner-prosedur">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 19 7-7 3 3-7 7-3-3z"/><path d="m18 13-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="m2 2 7.586 7.586"/><circle cx="11" cy="11" r="2"/></svg>
              {{ selP.prosedur }}
              <span class="bd-icd">{{ selP.icdProsedur }}</span>
            </div>
            <div class="bd-banner-dpjp">DPJP: {{ selP.dpjp }}</div>
          </div>
          <div class="bd-banner-right">
            <span :class="['bd-sbadge-lg', `bd-sbadge-${selP.status.toLowerCase().replace('berlangsung','live')}`]">
              <span v-if="selP.status === 'BERLANGSUNG'" class="bd-pulse"></span>
              {{ selP.status }}
            </span>
            <span :class="['bd-ptype', `bd-ptype-${selP.ptype}`]">{{ selP.ptype.toUpperCase() }}</span>
            <div class="bd-ruang-badge">{{ selP.ruang }}</div>
          </div>
        </div>

        <!-- Tabs -->
        <div class="bd-tabs">
          <button v-for="t in [
            { key: 'prabedah', label: 'Pra-Bedah' },
            { key: 'intraop', label: 'Intraoperatif' },
            { key: 'laporan', label: 'Laporan Operasi' },
            { key: 'pascabedah', label: 'Pasca-Bedah' },
          ]" :key="t.key"
            :class="['bd-tab', tab === t.key && 'bd-tab-a']"
            @click="tab = t.key"
          >{{ t.label }}</button>
        </div>

        <div class="bd-tabcont">
          <!-- ── TAB 1: Pra-Bedah ──────────────────────────────── -->
          <div v-if="tab === 'prabedah'" class="bd-prabedah">
            <div class="bd-2col">
              <!-- Ruang OK + Tim Bedah -->
              <div class="bd-card">
                <div class="bd-card-hd">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                  Ruang Operasi & Jadwal
                </div>
                <div class="bd-card-bd">
                  <div class="bd-field-row">
                    <label class="bd-label">Ruang OK</label>
                    <div class="bd-radios">
                      <label v-for="r in ['OK 1', 'OK 2', 'OK 3']" :key="r" class="bd-radio-lbl">
                        <input type="radio" :value="r" v-model="selP.ruang" :disabled="selP.laporanFinalized" />
                        {{ r }}
                      </label>
                    </div>
                  </div>
                  <div class="bd-field-row">
                    <label class="bd-label">Jadwal Operasi</label>
                    <span class="bd-val">{{ selP.scheduledTime }} WIB</span>
                  </div>
                  <div class="bd-field-row">
                    <label class="bd-label">Diagnosa</label>
                    <span class="bd-val bd-dx">{{ selP.diagnosa }}</span>
                  </div>
                </div>
              </div>

              <!-- Checklist Pra-Bedah -->
              <div class="bd-card">
                <div class="bd-card-hd">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                  Checklist Pra-Bedah
                  <span :class="['bd-chk-badge', checklistAllDone ? 'bd-chk-ok' : 'bd-chk-no']">
                    {{ checklistAllDone ? 'Lengkap' : `${Object.values(selP.checklist).filter(Boolean).length}/5` }}
                  </span>
                </div>
                <div class="bd-card-bd">
                  <label v-for="(item, key) in {
                    identitas: 'Identitas pasien terverifikasi (gelang, KTP)',
                    consent: 'Informed consent ditandatangani',
                    lokasi: 'Lokasi operasi ditandai',
                    pupil: 'Pupil dilebarkan (bila diperlukan)',
                    alergi: 'Alergi dikonfirmasi & didokumentasikan',
                  }" :key="key" class="bd-chk-item">
                    <input type="checkbox" v-model="selP.checklist[key]" :disabled="selP.laporanFinalized" />
                    <span :class="selP.checklist[key] && 'bd-chk-done'">{{ item }}</span>
                  </label>
                </div>
              </div>
            </div>

            <!-- Tim Bedah -->
            <div class="bd-card bd-card-full">
              <div class="bd-card-hd">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Tim Bedah
              </div>
              <div class="bd-card-bd">
                <div class="bd-tim-grid">
                  <div v-for="(label, key) in { operator: 'Operator (DPJP)', asisten1: 'Asisten 1', asisten2: 'Asisten 2', scrubNurse: 'Scrub Nurse', circNurse: 'Circulating Nurse', anestesi: 'Anestesiologis' }" :key="key" class="bd-tim-field">
                    <label class="bd-label">{{ label }}</label>
                    <div class="bd-combo-wrap">
                      <input
                        class="bd-input bd-combo-input"
                        :value="selP.tim[key]"
                        :placeholder="`Cari atau ketik ${label}…`"
                        :disabled="selP.laporanFinalized"
                        @input="e => { selP.tim[key] = e.target.value; timSearch[key] = e.target.value }"
                        @focus="openTimDropdown(key)"
                        @blur="() => setTimeout(() => timDropdownOpen[key] = false, 150)"
                      />
                      <svg v-if="!selP.laporanFinalized" class="bd-combo-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                      <div v-if="timDropdownOpen[key] && !selP.laporanFinalized" class="bd-combo-dropdown">
                        <div v-for="emp in filteredEmployees(key)" :key="emp.id" class="bd-combo-option" @mousedown.prevent="pickEmployee(key, emp)">
                          <span class="bd-combo-name">{{ emp.name }}</span>
                          <span class="bd-combo-role">{{ emp.role }}</span>
                        </div>
                        <div v-if="!filteredEmployees(key).length" class="bd-combo-empty">Tidak ada hasil</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- IOL Rencana (Phaco only) -->
            <div v-if="selP.isPhaco" class="bd-card bd-card-full">
              <div class="bd-card-hd">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                IOL yang Direncanakan
              </div>
              <div class="bd-card-bd">
                <div class="bd-iol-grid">
                  <div class="bd-iol-field">
                    <label class="bd-label">Merk / Nama</label>
                    <input class="bd-input" v-model="selP.iolRencana.merk" placeholder="e.g. Alcon AcrySof" :disabled="selP.laporanFinalized" />
                  </div>
                  <div class="bd-iol-field">
                    <label class="bd-label">Power (Dioptri)</label>
                    <input class="bd-input" v-model="selP.iolRencana.power" placeholder="e.g. +21.0" :disabled="selP.laporanFinalized" />
                  </div>
                  <div class="bd-iol-field">
                    <label class="bd-label">Series / Model</label>
                    <input class="bd-input" v-model="selP.iolRencana.series" placeholder="e.g. SN60WF" :disabled="selP.laporanFinalized" />
                  </div>
                  <div class="bd-iol-field">
                    <label class="bd-label">Tipe</label>
                    <select class="bd-select" v-model="selP.iolRencana.tipe" :disabled="selP.laporanFinalized">
                      <option>Monofocal</option>
                      <option>Multifocal</option>
                      <option>Toric</option>
                      <option>Extended Depth of Focus</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>

            <!-- Mulai Operasi Button -->
            <div v-if="selP.status === 'MENUNGGU'" class="bd-mulai-wrap">
              <button
                :class="['bd-btn-mulai', !checklistAllDone && 'bd-btn-disabled']"
                :disabled="!checklistAllDone"
                @click="openMulaiModal"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                Mulai Operasi
              </button>
              <span v-if="!checklistAllDone" class="bd-mulai-hint">Lengkapi checklist pra-bedah terlebih dahulu</span>
            </div>
            <div v-else-if="selP.status === 'BERLANGSUNG'" class="bd-status-info bd-status-live">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              Operasi sedang berlangsung — lihat tab Intraoperatif
            </div>
            <div v-else class="bd-status-info bd-status-done">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              Operasi selesai — Laporan difinalisasi
            </div>
          </div>

          <!-- ── TAB 2: Intraoperatif ──────────────────────────── -->
          <div v-else-if="tab === 'intraop'" class="bd-intraop">
            <!-- Timer Card -->
            <div class="bd-timer-card">
              <div class="bd-timer-hd">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Waktu Operasi
              </div>
              <div class="bd-timer-body">
                <div class="bd-timein-out">
                  <div class="bd-to-block">
                    <span class="bd-to-label">Time In</span>
                    <span class="bd-to-val">{{ timInDisplay }}</span>
                  </div>
                  <div class="bd-timer-display">
                    <span v-if="selP.status === 'BERLANGSUNG' && !selP.timOut" class="bd-pulse-dot"></span>
                    {{ timerDisplay }}
                  </div>
                  <div class="bd-to-block">
                    <span class="bd-to-label">Time Out</span>
                    <span class="bd-to-val">{{ timOutDisplay }}</span>
                  </div>
                </div>
                <div class="bd-timer-actions">
                  <button
                    v-if="selP.status === 'BERLANGSUNG' && !selP.timOut"
                    class="bd-btn-timeout"
                    @click="doTimeOut"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="4" height="16" x="6" y="4"/><rect width="4" height="16" x="14" y="4"/></svg>
                    Time Out
                  </button>
                  <span v-else-if="selP.timOut" class="bd-timer-done-badge">Operasi Selesai · {{ timerDisplay }}</span>
                  <span v-else class="bd-timer-hint">Tekan "Mulai Operasi" di tab Pra-Bedah</span>
                </div>
              </div>
            </div>

            <div class="bd-2col">
              <!-- BHP Terpakai (default dari paket pemeriksaan dokter) -->
              <div class="bd-card">
                <div class="bd-card-hd">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                  BHP Terpakai
                  <span v-if="selP.paketBedah" class="bd-paket-source-pill">
                    Paket Dokter: {{ selP.paketBedah.kode }} · {{ selP.paketBedah.nama }}
                  </span>
                </div>
                <div class="bd-card-bd">
                  <table class="bd-tbl" v-if="selP.bhp.length">
                    <thead><tr><th>No</th><th>Item</th><th>Jml</th><th>Satuan</th><th></th></tr></thead>
                    <tbody>
                      <tr v-for="(b, i) in selP.bhp" :key="i">
                        <td>{{ i + 1 }}</td>
                        <td>{{ b.item }}</td>
                        <td>{{ b.jumlah }}</td>
                        <td>{{ b.satuan }}</td>
                        <td><button class="bd-del" @click="removeBhp(i)" :disabled="selP.laporanFinalized">✕</button></td>
                      </tr>
                    </tbody>
                  </table>
                  <div v-else class="bd-tbl-empty">Belum ada BHP dicatat</div>

                  <div v-if="!selP.laporanFinalized" class="bd-bhp-add">
                    <select class="bd-select bd-select-sm" v-model="newBhp.item">
                      <option value="">-- Pilih Item --</option>
                      <option v-for="opt in bhpOptions" :key="opt">{{ opt }}</option>
                    </select>
                    <input type="number" class="bd-input bd-input-sm" v-model.number="newBhp.jumlah" min="1" style="width:60px" />
                    <select class="bd-select bd-select-sm" v-model="newBhp.satuan">
                      <option>Pcs</option><option>Botol</option><option>Syringe</option><option>Lembar</option><option>Set</option>
                    </select>
                    <button class="bd-btn-add" @click="addBhp">+ Tambah</button>
                  </div>
                </div>
              </div>

              <!-- IOL Dipasang (Phaco) -->
              <div class="bd-card">
                <div class="bd-card-hd">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                  {{ selP.isPhaco ? 'IOL Dipasang' : 'Anestesi & Catatan' }}
                </div>
                <div class="bd-card-bd">
                  <div v-if="selP.isPhaco" class="bd-iol-grid">
                    <div class="bd-iol-field">
                      <label class="bd-label">Merk / Nama</label>
                      <input class="bd-input" v-model="selP.iolDipasang.merk" :disabled="selP.laporanFinalized" />
                    </div>
                    <div class="bd-iol-field">
                      <label class="bd-label">Power (D)</label>
                      <input class="bd-input" v-model="selP.iolDipasang.power" :disabled="selP.laporanFinalized" />
                    </div>
                    <div class="bd-iol-field">
                      <label class="bd-label">Series / Lot No.</label>
                      <input class="bd-input" v-model="selP.iolDipasang.series" :disabled="selP.laporanFinalized" />
                    </div>
                    <div class="bd-iol-field">
                      <label class="bd-label">Tipe</label>
                      <select class="bd-select" v-model="selP.iolDipasang.tipe" :disabled="selP.laporanFinalized">
                        <option>Monofocal</option><option>Multifocal</option><option>Toric</option><option>Extended Depth of Focus</option>
                      </select>
                    </div>
                  </div>
                  <div class="bd-iol-field" style="margin-top:8px">
                    <label class="bd-label">Jenis Anestesi</label>
                    <select class="bd-select" v-model="selP.anestesi" :disabled="selP.laporanFinalized">
                      <option>Topikal</option><option>Lokal</option><option>Sub-Tenon</option><option>Umum</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>

            <!-- Catatan Intraoperatif -->
            <div class="bd-card bd-card-full">
              <div class="bd-card-hd">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                Catatan Intraoperatif
              </div>
              <div class="bd-card-bd">
                <textarea
                  class="bd-textarea"
                  v-model="selP.catatanIntra"
                  rows="5"
                  placeholder="Catatan jalannya operasi, teknik khusus, temuan…"
                  :disabled="selP.laporanFinalized"
                ></textarea>
              </div>
            </div>
          </div>

          <!-- ── TAB 3: Laporan Operasi ────────────────────────── -->
          <div v-else-if="tab === 'laporan'" class="bd-laporan">
            <div v-if="selP.laporanFinalized" class="bd-finalized-banner">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              Laporan difinalisasi — data dikunci (read-only)
            </div>

            <div class="bd-2col">
              <div class="bd-card">
                <div class="bd-card-hd">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                  Identitas Operasi
                </div>
                <div class="bd-card-bd">
                  <div class="bd-field-row"><label class="bd-label">Tanggal Operasi</label><span class="bd-val">{{ new Date().toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) }}</span></div>
                  <div class="bd-field-row"><label class="bd-label">Time In</label><span class="bd-val">{{ timInDisplay }} WIB</span></div>
                  <div class="bd-field-row"><label class="bd-label">Time Out</label><span class="bd-val">{{ timOutDisplay }} WIB</span></div>
                  <div class="bd-field-row"><label class="bd-label">Durasi</label><span class="bd-val">{{ timerDisplay }}</span></div>
                  <div class="bd-field-row"><label class="bd-label">Operator</label><span class="bd-val">{{ selP.tim.operator || selP.dpjp }}</span></div>
                  <div class="bd-field-row"><label class="bd-label">Asisten</label><span class="bd-val">{{ [selP.tim.asisten1, selP.tim.asisten2].filter(Boolean).join(', ') || '—' }}</span></div>
                  <div class="bd-field-row"><label class="bd-label">Anestesi</label><span class="bd-val">{{ selP.anestesi }}</span></div>
                </div>
              </div>

              <div class="bd-card">
                <div class="bd-card-hd">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                  Diagnosis
                </div>
                <div class="bd-card-bd">
                  <div class="bd-iol-field">
                    <label class="bd-label">Diagnosis Pra-Bedah</label>
                    <div class="bd-dx-chip">{{ selP.diagnosa }}</div>
                  </div>
                  <div class="bd-iol-field" style="margin-top:12px">
                    <label class="bd-label">Diagnosis Pasca-Bedah</label>
                    <input class="bd-input" v-model="selP.diagnosaPasca" :disabled="selP.laporanFinalized" />
                  </div>
                </div>
              </div>
            </div>

            <div class="bd-card bd-card-full">
              <div class="bd-card-hd">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Laporan Tindakan
              </div>
              <div class="bd-card-bd">
                <div class="bd-iol-field">
                  <label class="bd-label">Teknik Operasi</label>
                  <textarea class="bd-textarea" v-model="selP.teknikOp" rows="3" :disabled="selP.laporanFinalized" placeholder="Deskripsikan teknik yang digunakan…"></textarea>
                </div>
                <div class="bd-iol-field" style="margin-top:12px">
                  <label class="bd-label">Temuan Intraoperatif</label>
                  <textarea class="bd-textarea" v-model="selP.temuanIntra" rows="3" :disabled="selP.laporanFinalized" placeholder="Temuan selama operasi…"></textarea>
                </div>
                <div class="bd-iol-field" style="margin-top:12px">
                  <label class="bd-label">Komplikasi</label>
                  <label class="bd-chk-item" style="margin-bottom:8px">
                    <input type="checkbox" v-model="selP.komplikasi" :disabled="selP.laporanFinalized" />
                    <span>Ada komplikasi intraoperatif</span>
                  </label>
                  <div v-if="selP.komplikasi" class="bd-komplikasi">
                    <select class="bd-select" v-model="selP.komplikasiTipe" :disabled="selP.laporanFinalized">
                      <option value="">-- Pilih Tipe --</option>
                      <option>Ruptur kapsul posterior</option>
                      <option>Vitreous loss</option>
                      <option>Pendarahan suprakoroid</option>
                      <option>Edema kornea</option>
                      <option>Lain-lain</option>
                    </select>
                    <textarea class="bd-textarea" v-model="selP.komplikasiNote" rows="2" :disabled="selP.laporanFinalized" placeholder="Keterangan komplikasi dan tindakan yang diambil…"></textarea>
                  </div>
                  <span v-else class="bd-no-komplikasi">Tidak ada komplikasi</span>
                </div>
              </div>
            </div>

            <div v-if="selP.isPhaco && selP.iolDipasang.merk" class="bd-card bd-card-full">
              <div class="bd-card-hd">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                IOL Terpasang
              </div>
              <div class="bd-card-bd">
                <div class="bd-iol-summary">
                  <div><span class="bd-label">Merk</span><span>{{ selP.iolDipasang.merk }}</span></div>
                  <div><span class="bd-label">Power</span><span>{{ selP.iolDipasang.power }} D</span></div>
                  <div><span class="bd-label">Series/Lot</span><span>{{ selP.iolDipasang.series }}</span></div>
                  <div><span class="bd-label">Tipe</span><span>{{ selP.iolDipasang.tipe }}</span></div>
                </div>
              </div>
            </div>

            <div class="bd-laporan-actions">
              <button class="bd-btn-print" @click="toast('i', 'Mencetak laporan operasi…')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                Cetak Laporan
              </button>
              <button
                v-if="!selP.laporanFinalized"
                class="bd-btn-finalisasi"
                :disabled="!selP.timIn"
                @click="showFinalModal = true"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Finalisasi Laporan
              </button>
              <span v-else class="bd-finalized-tag">Difinalisasi</span>
            </div>
          </div>

          <!-- ── TAB 4: Pasca-Bedah ────────────────────────────── -->
          <div v-else-if="tab === 'pascabedah'" class="bd-pascabedah">
            <div class="bd-2col">
              <!-- Instruksi Post-Op -->
              <div class="bd-card">
                <div class="bd-card-hd">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                  Instruksi Pasca Operasi
                </div>
                <div class="bd-card-bd">
                  <label v-for="(inst, i) in instruksiList" :key="i" class="bd-chk-item">
                    <input type="checkbox" v-model="selP.instruksi[i]" />
                    <span :class="selP.instruksi[i] && 'bd-chk-done'">{{ inst }}</span>
                  </label>
                </div>
              </div>

              <!-- Obat Pasca Bedah -->
              <div class="bd-card">
                <div class="bd-card-hd">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/></svg>
                  Obat Pasca Bedah
                  <span v-if="selP.resepSent" class="bd-sent-badge">Terkirim ke Farmasi</span>
                </div>
                <div class="bd-card-bd">
                  <div class="bd-quick-obat">
                    <span class="bd-label" style="margin-right:8px">Cepat:</span>
                    <button v-for="o in quickObat" :key="o" class="bd-quick-btn" @click="quickAddObat(o)">{{ o.split(' ')[0] }}</button>
                  </div>
                  <table class="bd-tbl bd-tbl-sm" v-if="selP.obatPasca.length" style="margin-top:8px">
                    <thead><tr><th>Nama Obat</th><th>Dosis</th><th>Frek.</th><th>Durasi</th><th>Rute</th><th></th></tr></thead>
                    <tbody>
                      <tr v-for="(o, i) in selP.obatPasca" :key="i">
                        <td>{{ o.nama }}</td><td>{{ o.dosis }}</td><td>{{ o.freq }}</td><td>{{ o.dur }}</td><td>{{ o.rute }}</td>
                        <td><button class="bd-del" @click="removeObat(i)" :disabled="selP.resepSent">✕</button></td>
                      </tr>
                    </tbody>
                  </table>
                  <div v-else class="bd-tbl-empty">Belum ada obat ditambahkan</div>

                  <div v-if="!selP.resepSent" class="bd-obat-form">
                    <input class="bd-input" v-model="newObat.nama" placeholder="Nama obat…" style="flex:2" />
                    <input class="bd-input bd-input-sm" v-model="newObat.dosis" placeholder="Dosis" style="flex:1" />
                    <select class="bd-select bd-select-sm" v-model="newObat.freq">
                      <option>1×/hari</option><option>2×/hari</option><option>3×/hari</option><option>4×/hari</option><option>6×/hari</option>
                    </select>
                    <select class="bd-select bd-select-sm" v-model="newObat.dur">
                      <option>3 hari</option><option>5 hari</option><option>7 hari</option><option>10 hari</option><option>14 hari</option><option>1 bulan</option>
                    </select>
                    <input class="bd-input bd-input-sm" v-model="newObat.rute" placeholder="Rute" />
                    <button class="bd-btn-add" @click="addObat">+ Tambah</button>
                  </div>

                  <button
                    v-if="!selP.resepSent && selP.obatPasca.length"
                    class="bd-btn-kirim"
                    @click="kirimResep"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    Kirim ke Farmasi
                  </button>
                </div>
              </div>
            </div>
          </div>

        </div>
      </template>
    </main>

    <!-- ── MODAL: Mulai Operasi ──────────────────────────────────── -->
    <div v-if="showMulaiModal" class="bd-overlay" @click.self="showMulaiModal = false">
      <div class="bd-modal bd-modal-wide">
        <!-- Step 1: Patient detail confirmation -->
        <template v-if="mulaiStep === 1">
          <div class="bd-modal-icon bd-modal-icon-warn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          </div>
          <h3>Konfirmasi Data Pasien</h3>
          <p class="bd-modal-sub">Pastikan data berikut benar sebelum memulai operasi</p>
          <div class="bd-modal-detail-grid">
            <div class="bd-mfield"><span class="bd-mlabel">Nama Pasien</span><span class="bd-mval">{{ selP?.name }}</span></div>
            <div class="bd-mfield"><span class="bd-mlabel">Diagnosa</span><span class="bd-mval">{{ selP?.diagnosa }}</span></div>
            <div class="bd-mfield"><span class="bd-mlabel">Nama Operasi</span><span class="bd-mval">{{ selP?.prosedur }}</span></div>
            <div class="bd-mfield">
              <span class="bd-mlabel">Visus Pre-op</span>
              <span class="bd-mval">OD: {{ selP?.visusOD || '—' }} / OS: {{ selP?.visusOS || '—' }}</span>
            </div>
            <div class="bd-mfield">
              <span class="bd-mlabel">IOP Pre-op</span>
              <span class="bd-mval">OD: {{ selP?.iopOD || '—' }} mmHg / OS: {{ selP?.iopOS || '—' }} mmHg</span>
            </div>
            <div class="bd-mfield">
              <span class="bd-mlabel">IOL Direncanakan</span>
              <span class="bd-mval" v-if="selP?.isPhaco">{{ selP?.iolRencana?.merk || '—' }} {{ selP?.iolRencana?.power ? `· ${selP.iolRencana.power} D` : '' }}</span>
              <span class="bd-mval bd-mval-na" v-else>Tidak ada (bukan prosedur Phaco)</span>
            </div>
          </div>
          <div class="bd-modal-actions">
            <button class="bd-btn-sec" @click="showMulaiModal = false">Batal</button>
            <button class="bd-btn-mulai-confirm" @click="mulaiNext">Lanjut →</button>
          </div>
        </template>
        <!-- Step 2: Final confirmation -->
        <template v-else>
          <div class="bd-modal-icon bd-modal-icon-warn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
          </div>
          <h3>Mulai Operasi?</h3>
          <p>Timer Time In akan dimulai sekarang untuk <strong>{{ selP?.name }}</strong>.<br/>Pastikan semua persiapan pra-bedah sudah selesai.</p>
          <div class="bd-modal-actions">
            <button class="bd-btn-sec" @click="mulaiBack">← Kembali</button>
            <button class="bd-btn-mulai-confirm" @click="doMulaiOperasi">Ya, Mulai Operasi</button>
          </div>
        </template>
      </div>
    </div>

    <!-- ── MODAL: Finalisasi ─────────────────────────────────────── -->
    <div v-if="showFinalModal" class="bd-overlay" @click.self="showFinalModal = false">
      <div class="bd-modal">
        <div class="bd-modal-icon bd-modal-icon-green">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <h3>Finalisasi Laporan Operasi?</h3>
        <p>Laporan akan dikunci dan tidak dapat diubah. Status pasien berubah menjadi <strong>SELESAI</strong>.</p>
        <div class="bd-modal-actions">
          <button class="bd-btn-sec" @click="showFinalModal = false">Batal</button>
          <button class="bd-btn-finalisasi-confirm" @click="doFinalisasi">Finalisasi</button>
        </div>
      </div>
    </div>

    <!-- ── TOASTS ──────────────────────────────────────────────────── -->
    <div class="bd-toast-wrap">
      <div v-for="t in toasts" :key="t.id" :class="['bd-toast', `bd-toast-${t.type}`]">
        {{ t.msg }}
      </div>
    </div>
  </div>
</template>

<style scoped>
/* ── Layout ─────────────────────────────────────────────────────── */
.bd-wrap { display: flex; height: 100%; background: var(--bg); overflow: hidden; }

/* ── Left Panel ─────────────────────────────────────────────────── */
.bd-qpanel {
  width: 280px; flex-shrink: 0; background: var(--bc);
  border-right: 1px solid var(--gb); display: flex; flex-direction: column;
  overflow: hidden;
}
.bd-qhd { padding: 16px 16px 0; flex-shrink: 0; }
.bd-qhd-title { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 14px; color: var(--gd); margin-bottom: 12px; }
.bd-qhd-title svg { width: 18px; height: 18px; color: var(--ga); }

.bd-stats { display: flex; gap: 6px; margin-bottom: 12px; }
.bd-stat { flex: 1; border-radius: 8px; padding: 8px 4px; text-align: center; }
.bd-stat-w { background: var(--wb); }
.bd-stat-b { background: var(--ib); }
.bd-stat-s { background: var(--sb); }
.bd-stat-n { display: block; font-size: 20px; font-weight: 800; line-height: 1; }
.bd-stat-w .bd-stat-n { color: var(--wt); }
.bd-stat-b .bd-stat-n { color: var(--it); }
.bd-stat-s .bd-stat-n { color: var(--st); }
.bd-stat-l { font-size: 10px; color: var(--tu); margin-top: 2px; display: block; }

/* ── Queue body ─────────────────────────────────────────────────── */
.bd-qbody { flex: 1; overflow-y: auto; padding: 10px 12px 12px; display: flex; flex-direction: column; gap: 0; }

.pill-live { display: inline-flex; align-items: center; gap: 5px; font-size: 9.5px; font-weight: 700; padding: 2px 8px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 20px; letter-spacing: 0.05em; margin-left: auto; }
.live-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--st); animation: bd-blink 1.5s infinite; flex-shrink: 0; }
.live-dot.sm { width: 5px; height: 5px; background: currentColor; }

/* Primary filter */
.primary-filter { display: flex; gap: 4px; margin-bottom: 0.5rem; }
.pf-btn { flex: 1; height: 32px; font-size: 11.5px; font-weight: 500; border: 1.5px solid var(--gb); border-radius: 8px; background: var(--bs); color: var(--tm); cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .13s; display: flex; align-items: center; justify-content: center; gap: 5px; }
.pf-btn:hover { border-color: var(--ga); color: var(--ga); }
.pf-btn.a { background: var(--gd); color: #fff; border-color: var(--gd); }
.pf-ct { font-size: 9px; font-weight: 700; padding: 0 5px; border-radius: 10px; background: rgba(255,255,255,.25); }

/* Secondary filter */
.ptype-tabs { display: flex; gap: 3px; margin-bottom: 0.55rem; }
.ptype-tab { flex: 1; padding: 5px 4px; font-size: 10px; font-weight: 600; border: 1.5px solid var(--gb); border-radius: 7px; background: var(--bs); color: var(--tu); cursor: pointer; font-family: 'DM Sans',sans-serif; text-align: center; transition: all .13s; white-space: nowrap; }
.ptype-tab:hover { border-color: var(--ga); color: var(--ga); }
.ptype-tab.a { color: #fff; font-weight: 700; }
.ptype-bpjs.a { background: #1d4ed8; border-color: #1d4ed8; }
.ptype-umum.a { background: var(--ga); border-color: var(--ga); }

/* Search */
.q-search-wrap { margin-bottom: 0.5rem; }
.q-search { width: 100%; height: 30px; font-size: 11.5px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 10px; background: var(--bs); font-family: 'DM Sans', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.q-search:focus { border-color: var(--ga); background: #fff; }

.empty-section { text-align: center; padding: 0.75rem 1rem; font-size: 11px; color: var(--th); background: var(--bi); border-radius: 7px; border: 1px dashed var(--gb); }

/* Queue item (PerawatView style) */
.q-item { display: flex; gap: 8px; padding: 8px 10px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; margin-bottom: 5px; cursor: pointer; transition: all 0.14s; width: 100%; text-align: left; font-family: 'DM Sans', sans-serif; flex-wrap: wrap; }
.q-item:hover { border-color: var(--lm); background: var(--gl); }
.q-item.active { border-color: var(--ga); background: var(--gl); }
.q-item.done { opacity: .55; }
.q-item.live { border-left: 3px solid var(--it); }
.q-item:focus-visible { outline: 2px solid var(--ga); outline-offset: 2px; }
.qi-left { display: flex; flex-direction: column; gap: 4px; min-width: 64px; }
.q-num { font-weight: 700; font-size: 13.5px; color: var(--ga); letter-spacing: 0.03em; font-family: 'DM Mono', monospace; }
.q-info { flex: 1; min-width: 0; }
.q-name { font-size: 12.5px; font-weight: 600; color: var(--td); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.q-meta { font-size: 10px; color: var(--tu); margin-top: 2px; }
.q-prosedur { font-size: 11px; color: var(--gm); margin-top: 3px; font-weight: 500; }
.q-tags { display: flex; gap: 3px; margin-top: 4px; flex-wrap: wrap; }

.q-actions { display: flex; gap: 4px; margin-top: 5px; padding-top: 5px; border-top: 1px dashed var(--gb); width: 100%; }
.q-act-btn { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; font-size: 10px; font-weight: 600; border-radius: 5px; border: 1px solid; cursor: pointer; font-family: 'DM Sans',sans-serif; transition: background .12s, color .12s, border-color .12s; background: none; user-select: none; }
.q-act-btn svg { width: 10px; height: 10px; }
.q-act-btn.call { color: var(--ga); border-color: var(--ga); background: var(--gl); }
.q-act-btn.call:hover { background: var(--ga); color: #fff; }
.q-act-btn.call.recall { color: #b45309; border-color: #fbbf24; background: #fef3c7; }
.q-act-btn.call.recall:hover { background: #f59e0b; color: #fff; border-color: #f59e0b; }
.q-act-btn.skip { color: var(--tu); border-color: var(--gb); }
.q-act-btn.skip:hover { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.q-act-btn:disabled { opacity: .55; cursor: wait; }

/* Pills */
.pill { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px; }
.pill-menunggu  { background: #fef3c7; color: #92400e; }
.pill-proses    { background: #dbeafe; color: #1e40af; }
.pill-selesai   { background: var(--sb); color: var(--st); }
.pill-bpjs      { background: #dbeafe; color: #1e40af; }
.pill-umum      { background: var(--gl); color: var(--ga); }
.pill-ruang     { background: var(--bc); color: var(--gm); border: 1px solid var(--gb); }
.pill-time      { background: var(--bi); color: var(--tu); font-variant-numeric: tabular-nums; }

/* Classification */
.cls-baru    { background: #dbeafe; color: #1e40af; }
.cls-preop   { background: #fef3c7; color: #92400e; }
.cls-postop  { background: var(--sb); color: var(--st); }
.cls-kontrol { background: #f3e8ff; color: #7e22ce; }

/* Legacy ptype (used in banner) */
.bd-ptype { font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; }
.bd-ptype-bpjs { background: var(--ib); color: var(--it); }
.bd-ptype-umum { background: var(--sb); color: var(--st); }
.bd-ptype-asn { background: var(--pb); color: var(--pt); }

/* ── Right Panel ────────────────────────────────────────────────── */
.bd-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }

.bd-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; color: var(--th); }
.bd-empty svg { width: 72px; height: 72px; }
.bd-empty h3 { font-size: 16px; color: var(--tm); margin: 0; }
.bd-empty p { font-size: 13px; margin: 0; }

/* Banner */
.bd-banner {
  display: flex; justify-content: space-between; align-items: flex-start;
  padding: 14px 20px; background: var(--gd); color: #fff; flex-shrink: 0;
}
.bd-banner-name { font-size: 18px; font-weight: 800; font-family: 'DM Serif Display', serif; }
.bd-banner-meta { font-size: 12px; opacity: .75; margin: 2px 0; }
.bd-banner-prosedur { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; margin-top: 4px; }
.bd-banner-prosedur svg { width: 14px; height: 14px; opacity: .8; }
.bd-icd { font-size: 10px; background: rgba(255,255,255,.15); padding: 1px 6px; border-radius: 4px; font-family: monospace; }
.bd-banner-dpjp { font-size: 11px; opacity: .7; margin-top: 4px; }
.bd-banner-right { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
.bd-sbadge-lg { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 12px; display: flex; align-items: center; gap: 5px; }
.bd-sbadge-lg.bd-sbadge-menunggu { background: var(--wb); color: var(--wt); }
.bd-sbadge-lg.bd-sbadge-live { background: var(--ib); color: var(--it); }
.bd-sbadge-lg.bd-sbadge-selesai { background: var(--sb); color: var(--st); }
.bd-ruang-badge { font-size: 11px; font-weight: 700; background: rgba(255,255,255,.15); padding: 3px 10px; border-radius: 8px; }
.bd-pulse { width: 7px; height: 7px; border-radius: 50%; background: currentColor; animation: bd-blink 1s infinite; display: inline-block; }
@keyframes bd-blink { 0%,100%{opacity:1} 50%{opacity:.2} }

/* Tabs */
.bd-tabs { display: flex; background: var(--bc); border-bottom: 1px solid var(--gb); flex-shrink: 0; padding: 0 16px; gap: 2px; }
.bd-tab { padding: 10px 14px; font-size: 12px; font-weight: 600; color: var(--tu); background: none; border: none; border-bottom: 2px solid transparent; cursor: pointer; transition: all .15s; white-space: nowrap; }
.bd-tab:hover { color: var(--gm); }
.bd-tab-a { color: var(--gm); border-bottom-color: var(--ga); }

.bd-tabcont { flex: 1; overflow-y: auto; padding: 20px; }

/* Cards */
.bd-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }
.bd-card-full { margin-top: 16px; }
.bd-card-hd { display: flex; align-items: center; gap: 8px; padding: 12px 16px; font-size: 13px; font-weight: 700; color: var(--gd); border-bottom: 1px solid var(--gb); background: var(--bs); }
.bd-card-hd svg { width: 16px; height: 16px; color: var(--ga); flex-shrink: 0; }
.bd-card-bd { padding: 16px; }

.bd-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

/* Form elements */
.bd-label { font-size: 11px; font-weight: 600; color: var(--tu); display: block; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .4px; }
.bd-input { width: 100%; padding: 8px 10px; border: 1px solid var(--gb); border-radius: 8px; font-size: 13px; background: var(--bs); color: var(--td); outline: none; box-sizing: border-box; transition: border-color .15s; }
.bd-input:focus { border-color: var(--ga); }
.bd-input:disabled { opacity: .6; background: var(--bg); }
.bd-input-sm { padding: 6px 8px; font-size: 12px; }
.bd-select { width: 100%; padding: 8px 10px; border: 1px solid var(--gb); border-radius: 8px; font-size: 13px; background: var(--bs); color: var(--td); outline: none; box-sizing: border-box; }
.bd-select:disabled { opacity: .6; }
.bd-select-sm { padding: 6px 8px; font-size: 12px; }
.bd-textarea { width: 100%; padding: 10px; border: 1px solid var(--gb); border-radius: 8px; font-size: 13px; background: var(--bs); color: var(--td); outline: none; resize: vertical; box-sizing: border-box; font-family: inherit; }
.bd-textarea:focus { border-color: var(--ga); }
.bd-textarea:disabled { opacity: .6; background: var(--bg); }
.bd-val { font-size: 13px; color: var(--td); }
.bd-dx { font-weight: 600; color: var(--gm); }
.bd-field-row { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
.bd-field-row .bd-label { margin: 0; width: 140px; flex-shrink: 0; }

.bd-radios { display: flex; gap: 12px; }
.bd-radio-lbl { display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer; color: var(--td); }

/* Checklist */
.bd-chk-item { display: flex; align-items: flex-start; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--bg); cursor: pointer; font-size: 13px; color: var(--tm); }
.bd-chk-item:last-child { border-bottom: none; }
.bd-chk-item input { margin-top: 2px; accent-color: var(--ga); }
.bd-chk-done { text-decoration: line-through; color: var(--th); }
.bd-chk-badge { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px; margin-left: auto; }
.bd-chk-ok { background: var(--sb); color: var(--st); }
.bd-chk-no { background: var(--wb); color: var(--wt); }

/* Tim Bedah */
.bd-tim-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
.bd-tim-field { display: flex; flex-direction: column; gap: 4px; }

/* Tim Bedah Combobox */
.bd-combo-wrap { position: relative; }
.bd-combo-input { padding-right: 28px; }
.bd-combo-chevron { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: var(--tu); pointer-events: none; }
.bd-combo-dropdown { position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: var(--bc); border: 1px solid var(--ga); border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 50; max-height: 200px; overflow-y: auto; }
.bd-combo-option { display: flex; flex-direction: column; gap: 2px; padding: 8px 12px; cursor: pointer; transition: background .1s; }
.bd-combo-option:hover { background: var(--gl); }
.bd-combo-name { font-size: 13px; font-weight: 600; color: var(--td); }
.bd-combo-role { font-size: 10px; color: var(--tu); }
.bd-combo-empty { padding: 12px; text-align: center; font-size: 12px; color: var(--th); font-style: italic; }

/* Paket source pill (di header BHP Terpakai) */
.bd-paket-source-pill { margin-left: auto; font-size: 10.5px; font-weight: 600; padding: 3px 9px; background: var(--gl); border: 1px solid var(--ga); color: var(--gm); border-radius: 20px; }

/* IOL */
.bd-iol-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.bd-iol-field { display: flex; flex-direction: column; gap: 4px; }
.bd-iol-summary { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.bd-iol-summary div { display: flex; flex-direction: column; gap: 3px; font-size: 13px; color: var(--td); }
.bd-iol-summary .bd-label { font-size: 10px; }

/* Pra-Bedah actions */
.bd-mulai-wrap { margin-top: 20px; display: flex; align-items: center; gap: 12px; }
.bd-btn-mulai {
  display: flex; align-items: center; gap: 8px; padding: 12px 28px;
  background: var(--ga); color: #fff; border: none; border-radius: 10px;
  font-size: 14px; font-weight: 700; cursor: pointer; transition: all .15s;
}
.bd-btn-mulai svg { width: 16px; height: 16px; }
.bd-btn-mulai:hover:not(:disabled) { background: var(--gm); }
.bd-btn-disabled { opacity: .45; cursor: not-allowed; }
.bd-mulai-hint { font-size: 12px; color: var(--tu); }
.bd-status-info { display: flex; align-items: center; gap: 8px; padding: 12px 16px; border-radius: 10px; font-size: 13px; font-weight: 600; margin-top: 20px; }
.bd-status-info svg { width: 16px; height: 16px; }
.bd-status-live { background: var(--ib); color: var(--it); }
.bd-status-done { background: var(--sb); color: var(--st); }

/* Timer */
.bd-timer-card { background: var(--gd); border-radius: 14px; padding: 20px 24px; margin-bottom: 20px; }
.bd-timer-hd { display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,.7); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 16px; }
.bd-timer-hd svg { width: 16px; height: 16px; }
.bd-timer-body { display: flex; flex-direction: column; align-items: center; gap: 12px; }
.bd-timein-out { display: flex; align-items: center; gap: 24px; }
.bd-to-block { display: flex; flex-direction: column; align-items: center; gap: 4px; }
.bd-to-label { font-size: 10px; font-weight: 600; color: rgba(255,255,255,.6); text-transform: uppercase; letter-spacing: .5px; }
.bd-to-val { font-size: 18px; font-weight: 700; color: #fff; font-family: 'DM Mono', monospace; }
.bd-timer-display {
  font-size: 52px; font-weight: 900; color: #fff; font-family: 'DM Mono', monospace;
  letter-spacing: 2px; display: flex; align-items: center; gap: 10px;
  text-shadow: 0 0 30px rgba(138,191,68,.4);
}
.bd-pulse-dot { width: 10px; height: 10px; border-radius: 50%; background: #8abf44; animation: bd-blink 1s infinite; }
.bd-timer-actions { margin-top: 4px; }
.bd-btn-timeout {
  display: flex; align-items: center; gap: 8px; padding: 10px 24px;
  background: var(--et); color: #fff; border: none; border-radius: 10px;
  font-size: 13px; font-weight: 700; cursor: pointer; transition: background .15s;
}
.bd-btn-timeout svg { width: 14px; height: 14px; }
.bd-btn-timeout:hover { background: #b91c1c; }
.bd-timer-done-badge { background: var(--sb); color: var(--st); padding: 8px 16px; border-radius: 10px; font-size: 13px; font-weight: 700; }
.bd-timer-hint { color: rgba(255,255,255,.5); font-size: 12px; }

/* BHP / Tables */
.bd-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
.bd-tbl th { background: var(--bg); padding: 7px 10px; text-align: left; font-weight: 700; color: var(--tu); font-size: 11px; text-transform: uppercase; }
.bd-tbl td { padding: 8px 10px; border-top: 1px solid var(--bg); color: var(--td); }
.bd-tbl-sm th, .bd-tbl-sm td { padding: 6px 8px; }
.bd-tbl-empty { padding: 20px; text-align: center; color: var(--th); font-size: 12px; }
.bd-del { background: none; border: none; color: var(--et); cursor: pointer; font-size: 13px; padding: 2px 6px; border-radius: 4px; }
.bd-del:hover:not(:disabled) { background: var(--eb); }
.bd-del:disabled { opacity: .4; cursor: not-allowed; }

.bd-bhp-add { display: flex; gap: 6px; align-items: center; margin-top: 10px; flex-wrap: wrap; }
.bd-btn-add { padding: 7px 14px; background: var(--gl); border: 1px solid var(--ga); color: var(--gm); border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.bd-btn-add:hover { background: var(--ga); color: #fff; }

.bd-obat-form { display: flex; gap: 6px; align-items: center; margin-top: 10px; flex-wrap: wrap; }

/* Quick obat */
.bd-quick-obat { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 6px; }
.bd-quick-btn { font-size: 11px; padding: 3px 8px; background: var(--gl); border: 1px solid var(--gb); color: var(--gm); border-radius: 6px; cursor: pointer; font-weight: 600; }
.bd-quick-btn:hover { background: var(--ga); color: #fff; border-color: var(--ga); }

.bd-btn-kirim {
  display: flex; align-items: center; gap: 8px; padding: 10px 20px;
  background: var(--it); color: #fff; border: none; border-radius: 10px;
  font-size: 13px; font-weight: 700; cursor: pointer; margin-top: 12px;
}
.bd-btn-kirim svg { width: 14px; height: 14px; }
.bd-btn-kirim:hover { background: #1e40af; }

.bd-sent-badge { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 8px; background: var(--sb); color: var(--st); margin-left: auto; }
.bd-sent-badge-no { background: var(--wb); color: var(--wt); }

/* Laporan */
.bd-finalized-banner { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: var(--sb); border: 1px solid var(--sbd); border-radius: 10px; color: var(--st); font-size: 13px; font-weight: 600; margin-bottom: 16px; }
.bd-finalized-banner svg { width: 16px; height: 16px; }
.bd-dx-chip { padding: 8px 12px; background: var(--gl); border-radius: 8px; font-size: 13px; font-weight: 600; color: var(--gm); display: inline-block; }
.bd-no-komplikasi { font-size: 12px; color: var(--st); background: var(--sb); padding: 6px 12px; border-radius: 6px; display: inline-block; }
.bd-komplikasi { display: flex; flex-direction: column; gap: 8px; margin-top: 6px; }
.bd-laporan-actions { display: flex; align-items: center; gap: 12px; margin-top: 20px; }
.bd-btn-print { display: flex; align-items: center; gap: 8px; padding: 10px 20px; background: var(--bs); border: 1px solid var(--gb); color: var(--tm); border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; }
.bd-btn-print svg { width: 14px; height: 14px; }
.bd-btn-print:hover { border-color: var(--ga); color: var(--gm); }
.bd-btn-finalisasi { display: flex; align-items: center; gap: 8px; padding: 10px 20px; background: var(--ga); color: #fff; border: none; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; }
.bd-btn-finalisasi svg { width: 14px; height: 14px; }
.bd-btn-finalisasi:hover:not(:disabled) { background: var(--gm); }
.bd-btn-finalisasi:disabled { opacity: .45; cursor: not-allowed; }
.bd-finalized-tag { font-size: 12px; font-weight: 700; background: var(--sb); color: var(--st); padding: 6px 14px; border-radius: 8px; }

/* Modals */
.bd-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); display: flex; align-items: center; justify-content: center; z-index: 100; }
.bd-modal { background: var(--bc); border-radius: 16px; padding: 32px; max-width: 420px; width: 90%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.2); }
.bd-modal-icon { width: 56px; height: 56px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; }
.bd-modal-icon svg { width: 28px; height: 28px; }
.bd-modal-icon-warn { background: var(--wb); color: var(--wt); }
.bd-modal-icon-green { background: var(--sb); color: var(--st); }
.bd-modal h3 { font-size: 18px; font-weight: 800; color: var(--td); margin: 0 0 8px; }
.bd-modal p { font-size: 13px; color: var(--tm); line-height: 1.6; margin: 0 0 24px; }
.bd-modal-wide { max-width: 520px; }
.bd-modal-sub { font-size: 12px; color: var(--tu); margin: -8px 0 16px; }
.bd-modal-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; text-align: left; background: var(--bg); border-radius: 10px; padding: 14px 16px; margin-bottom: 20px; }
.bd-mfield { display: flex; flex-direction: column; gap: 3px; }
.bd-mlabel { font-size: 10px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: .4px; }
.bd-mval { font-size: 13px; font-weight: 600; color: var(--td); }
.bd-mval-na { color: var(--th); font-weight: 400; font-style: italic; }
.bd-modal-actions { display: flex; gap: 10px; justify-content: center; }
.bd-btn-sec { padding: 10px 20px; background: var(--bg); border: 1px solid var(--gb); color: var(--tm); border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; }
.bd-btn-mulai-confirm { padding: 10px 20px; background: var(--ga); color: #fff; border: none; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; }
.bd-btn-mulai-confirm:hover { background: var(--gm); }
.bd-btn-finalisasi-confirm { padding: 10px 20px; background: var(--ga); color: #fff; border: none; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; }
.bd-btn-finalisasi-confirm:hover { background: var(--gm); }

/* Toasts */
.bd-toast-wrap { position: fixed; bottom: 20px; right: 20px; display: flex; flex-direction: column; gap: 8px; z-index: 200; }
.bd-toast { padding: 10px 16px; border-radius: 10px; font-size: 13px; font-weight: 600; box-shadow: 0 4px 16px rgba(0,0,0,.12); animation: bd-slideIn .2s ease; max-width: 320px; }
.bd-toast-s { background: var(--sb); color: var(--st); border-left: 4px solid var(--st); }
.bd-toast-e { background: var(--eb); color: var(--et); border-left: 4px solid var(--et); }
.bd-toast-w { background: var(--wb); color: var(--wt); border-left: 4px solid var(--wt); }
.bd-toast-i { background: var(--ib); color: var(--it); border-left: 4px solid var(--it); }
@keyframes bd-slideIn { from { transform: translateX(20px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
</style>
