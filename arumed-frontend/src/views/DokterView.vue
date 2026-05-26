<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useDokterStore } from '@/stores/dokterStore'
import { useJadwalDokterStore } from '@/stores/jadwalDokterStore'
import { useAuthStore } from '@/stores/auth'

const store      = useDokterStore()
const jadwalStore = useJadwalDokterStore()
const auth       = useAuthStore()

// ─── Status Saya Hari Ini (Jadwal Dokter quick panel) ───────────────────────
const myEmployeeId = computed(() => auth.user?.employee?.id ?? null)
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
  if (s === 'COMPLETED') return 'done'
  return 'skip'
}
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
    qNum:    q.queue_number,
    name:    p.name ?? '—',
    rm:      p.no_rm ?? '—',
    nik:     p.nik ?? '—',
    age:     p.age ?? calcAge(p.date_of_birth) ?? '—',
    gender:  p.gender ?? '—',
    address: p.address ?? '',
    classification: v.classification ?? '',
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
      bcva_od: refr?.visus_akhir_od ?? '—',
      bcva_os: refr?.visus_akhir_os ?? '—',
      iop_od:  refr?.iop_od ?? '—',
      iop_os:  refr?.iop_os ?? '—',
      rx_od:   fmtRx(refr?.refraksi_subjektif_od_sph, refr?.refraksi_subjektif_od_cyl, refr?.refraksi_subjektif_od_axis),
      rx_os:   fmtRx(refr?.refraksi_subjektif_os_sph, refr?.refraksi_subjektif_os_cyl, refr?.refraksi_subjektif_os_axis),
      note:    refr?.clinical_notes ?? '',
    },
    // List berikut belum di-fetch dari API saat antrian list (perlu separate call ke
    // /dokter/kunjungan/{visitId}). Default kosong supaya template tidak crash.
    history:     [],
    penunjang:   [],
    soapHistory: [],
    _raw: q,
  }
}

const patients = computed(() => store.antrian.map(mapPatient))
const selP     = computed(() => store.selectedQueue ? mapPatient(store.selectedQueue) : null)
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
  else if (ptypeFilter.value === 'Umum')     list = list.filter((p) => p.ptype === 'umum')
  else if (ptypeFilter.value === 'Asuransi') list = list.filter((p) => p.ptype === 'asn')
  if (qSearch.value) {
    const s = qSearch.value.toLowerCase()
    list = list.filter((p) => p.name.toLowerCase().includes(s) || p.qNum.toLowerCase().includes(s))
  }
  return list
})

const cWait = computed(() => patients.value.filter((p) => p.status === 'waiting' || p.status === 'progress').length)
const cDone = computed(() => patients.value.filter((p) => p.status === 'done').length)
const bpjsCount = computed(() => patients.value.filter((p) => p.ptype === 'bpjs' && p.status !== 'done' && p.status !== 'skip').length)
const umumCount = computed(() => patients.value.filter((p) => p.ptype === 'umum' && p.status !== 'done' && p.status !== 'skip').length)
const asnCount  = computed(() => patients.value.filter((p) => p.ptype === 'asn'  && p.status !== 'done' && p.status !== 'skip').length)

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
  dw.value = null
  finalized.value = false
  resetExam()
  resetSoap()
  tindakanList.value = []
  tindakanSearch.value = ''
  showTarifList.value = false
  rxList.value = []
  diagnosisUtama.value = null
  diagnosisSekunder.value = []
  icd9List.value = []
  planning.value = ''
  tanggalKontrol.value = ''
  surgeryPkg.value = ''
  surgeryDate.value = ''
  rujukFaskes.value = ''
  rujukAlasan.value = ''
  dxSearch.value = ''
  dxSearchSek.value = ''
  icd9Search.value = ''
  signed.value = false
  signTimestamp.value = null
  pinInput.value = ''
  pinError.value = ''
  showPinForm.value = false
  penunjangOrders.value = []
  showPenunjangModal.value = false
  selectedHasil.value = null
  showHasilModal.value = false
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

function skipPt(p) {
  // Client-side reorder — turunkan 1 posisi (konsisten dengan PerawatView/RefraksionisView)
  const arr = store.antrian
  const idx = arr.findIndex((x) => x.id === p.id)
  if (idx === -1) return
  if (idx >= arr.length - 1) {
    toast('w', `${p.name} sudah di posisi paling bawah`)
    return
  }
  const next = arr[idx + 1]
  arr.splice(idx, 2, next, arr[idx])
  if (store.selectedQueue?.id === p.id) store.clearSelected()
  toast('w', `${p.qNum} diturunkan 1 posisi`)
}

// ─── Lifecycle ──────────────────────────────────────────────────────────────
onMounted(async () => {
  await store.fetchAntrian()
  store.startPolling()
  // Load jadwal dokter agar "Status Saya Hari Ini" terisi
  jadwalStore.fetchAll()
})

onUnmounted(() => {
  store.stopPolling()
  store.clearSelected()
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

// ── PENUNJANG ────────────────────────────────────────────────────────────────
const penunjangTypes = [
  { id: 'OCT',   name: 'OCT Macula / Saraf',        category: 'Imaging' },
  { id: 'FP',    name: 'Foto Fundus',                category: 'Imaging' },
  { id: 'VF',    name: 'Visual Field Test',          category: 'Fungsional' },
  { id: 'USG',   name: 'USG B-Scan',                 category: 'Imaging' },
  { id: 'TOPOG', name: 'Topografi Kornea',           category: 'Imaging' },
  { id: 'FFA',   name: 'Fluorescein Angiografi',     category: 'Vaskular' },
  { id: 'ORA',   name: 'ORA Biomekanikal',           category: 'Biomekanikal' },
  { id: 'GDX',   name: 'GDx Fiber Analysis',         category: 'Glaukoma' },
]
const penunjangOrders = ref([])
const showPenunjangModal = ref(false)
const selectedHasil = ref(null)
const showHasilModal = ref(false)

function orderPenunjang(t) {
  if (penunjangOrders.value.find((x) => x.id === t.id)) { toast('w', `${t.name} sudah dipesan`); return }
  penunjangOrders.value.push({ ...t, status: 'ordered' })
  toast('s', `${t.name} dipesan`)
}
function removePenunjang(id) { penunjangOrders.value = penunjangOrders.value.filter((x) => x.id !== id) }
function viewHasil(h) { selectedHasil.value = h; showHasilModal.value = true }

function segClass(val) {
  if (val === 'Normal') return 'seg-ok'
  if (val === 'Tidak Normal') return 'seg-warn'
  if (val === 'Tidak Dapat Dinilai') return 'seg-muted'
  return ''
}

// ── TAB 3: TINDAKAN (Master Tarif Tindakan) ─────────────────────────────────

const masterTarif = [
  { kode: 'JM-001', nama: 'Konsultasi Sp. Mata',               kategori: 'Jasa Medis', tarifUmum: 150000,  tarifBpjs: 55000,   satuan: 'kunjungan' },
  { kode: 'JM-002', nama: 'Konsultasi Sp. Mata Sub-spesialis', kategori: 'Jasa Medis', tarifUmum: 200000,  tarifBpjs: 80000,   satuan: 'kunjungan' },
  { kode: 'JM-003', nama: 'Konsultasi Follow-up / Kontrol',    kategori: 'Jasa Medis', tarifUmum: 100000,  tarifBpjs: 35000,   satuan: 'kunjungan' },
  { kode: 'TD-001', nama: 'Tonometri NCT',                     kategori: 'Tindakan',   tarifUmum: 75000,   tarifBpjs: 30000,   satuan: 'tindakan'  },
  { kode: 'TD-002', nama: 'Funduskopi Direk / Indirek',        kategori: 'Tindakan',   tarifUmum: 150000,  tarifBpjs: 60000,   satuan: 'tindakan'  },
  { kode: 'TD-003', nama: 'Pterigium Eksisi + MMC',            kategori: 'Tindakan',   tarifUmum: 2500000, tarifBpjs: 1200000, satuan: 'tindakan'  },
  { kode: 'TD-004', nama: 'Fakoemulsifikasi + IOL Standar',    kategori: 'Tindakan',   tarifUmum: 5800000, tarifBpjs: 3200000, satuan: 'tindakan'  },
  { kode: 'TD-005', nama: 'Injeksi Intravitreal Anti-VEGF',    kategori: 'Tindakan',   tarifUmum: 3500000, tarifBpjs: 2200000, satuan: 'tindakan'  },
  { kode: 'TD-006', nama: 'Laser Argon / YAG',                kategori: 'Tindakan',   tarifUmum: 1800000, tarifBpjs: 900000,  satuan: 'tindakan'  },
]

const tindakanSearch = ref('')
const tindakanList = ref([]) // { ...tarif, qty: number }
const showTarifList = ref(false)

const filteredTarif = computed(() => {
  if (!tindakanSearch.value) return masterTarif
  const s = tindakanSearch.value.toLowerCase()
  return masterTarif.filter((t) => t.nama.toLowerCase().includes(s) || t.kode.toLowerCase().includes(s))
})

const tindakanSubtotal = computed(() =>
  tindakanList.value.reduce((sum, t) => {
    const rate = selP.value?.ptype === 'bpjs' ? t.tarifBpjs : t.tarifUmum
    return sum + rate * t.qty
  }, 0)
)

function fmtRp(v) { return 'Rp ' + Number(v).toLocaleString('id-ID') }

function addTindakan(t) {
  const existing = tindakanList.value.find((x) => x.kode === t.kode)
  if (existing) { existing.qty++; toast('s', `Qty ${t.nama} +1`); return }
  tindakanList.value.push({ ...t, qty: 1 })
  toast('s', `${t.nama} ditambahkan`)
}
function removeTindakan(kode) {
  tindakanList.value = tindakanList.value.filter((t) => t.kode !== kode)
}

// ── TAB 3: E-RESEP ──────────────────────────────────────────────────────────

const rxList = ref([])
const drugDB = [
  { name: 'Ciprofloxacin 0.3% ED', form: 'Tetes', stock: 24 },
  { name: 'Dexamethasone 0.1% ED', form: 'Tetes', stock: 18 },
  { name: 'Timolol 0.5% ED', form: 'Tetes', stock: 15 },
  { name: 'Latanoprost 0.005% ED', form: 'Tetes', stock: 8 },
  { name: 'Artificial Tears HEC', form: 'Tetes', stock: 32 },
]
const newRx = ref({ name: '', jumlah: '1 tetes', signa: '2×/hari', dur: '7 hari', posisi: 'Tetes ODS' })
function addRx() {
  if (!newRx.value.name.trim()) { toast('w', 'Nama obat wajib'); return }
  rxList.value.push({ ...newRx.value })
  newRx.value = { name: '', jumlah: '1 tetes', signa: '2×/hari', dur: '7 hari', posisi: 'Tetes ODS' }
  toast('s', 'Obat ditambahkan ke resep')
}
function removeRx(idx) { rxList.value.splice(idx, 1) }

// ── TAB 4: SOAP ─────────────────────────────────────────────────────────────

const soap = ref({ S: '', O: '', A: '', P: '' })
function resetSoap() { soap.value = { S: '', O: '', A: '', P: '' } }
function autoO() {
  if (!selP.value) return
  const { nd, rd } = selP.value
  soap.value.O =
    `TD: ${nd.td_s}/${nd.td_d}, N: ${nd.nadi}, SpO₂: ${nd.spo2}%, T: ${nd.suhu}°C\n` +
    `Visus: UCVA OD ${rd.ucva_od} OS ${rd.ucva_os} | BCVA OD ${rd.bcva_od} OS ${rd.bcva_os}\n` +
    `IOP: OD ${rd.iop_od} OS ${rd.iop_os} mmHg\n` +
    `Rx: OD ${rd.rx_od} | OS ${rd.rx_os}`
  toast('s', 'Data objektif dimuat dari triase & RO')
}

// ── TAB 4: DIAGNOSIS ICD-10 ─────────────────────────────────────────────────

const diagnosisUtama = ref(null)
const diagnosisSekunder = ref([])
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
const surgeryPkg = ref('')
const surgeryDate = ref('')
const rujukFaskes = ref('')
const rujukAlasan = ref('')
const surgeryPackages = [
  { id: 'sp1', name: 'Paket Fako + IOL Monofocal' },
  { id: 'sp2', name: 'Paket Fako + IOL Multifocal' },
  { id: 'sp3', name: 'Paket Pterigium Eksisi + MMC' },
  { id: 'sp4', name: 'Trabekulektomi Glaukoma' },
]

// ── TANDA TANGAN DIGITAL ────────────────────────────────────────────────────

const DOCTOR_PIN = '1234'
const signed = ref(false)
const signTimestamp = ref(null)
const pinInput = ref('')
const pinError = ref('')
const showPinForm = ref(false)
function doSign() {
  if (pinInput.value.length < 4) { pinError.value = 'PIN minimal 4 digit'; return }
  if (pinInput.value !== DOCTOR_PIN) { pinError.value = 'PIN salah, coba lagi'; pinInput.value = ''; return }
  pinError.value = ''; pinInput.value = ''
  showPinForm.value = false
  signed.value = true
  signTimestamp.value = new Date().toLocaleString('id-ID')
  toast('s', 'Dokumen ditandatangani secara digital')
}

const isLocked = computed(() => signed.value)
function undoSign() {
  signed.value = false
  signTimestamp.value = null
  pinInput.value = ''
  pinError.value = ''
  showPinForm.value = false
  toast('w', 'Tanda tangan dihapus — halaman kembali terbuka')
}

// ── FINALISASI ───────────────────────────────────────────────────────────────

async function doFinalize() {
  if (!diagnosisUtama.value) { toast('e', 'Wajib isi diagnosa utama ICD-10'); return }
  if (!soap.value.A) { toast('e', 'Wajib isi Assessment pada SOAP'); return }
  if (!planning.value) { toast('e', 'Wajib pilih planning (Pulang / Bedah / Rujuk)'); return }
  if (!signed.value) { toast('e', 'Wajib tandatangani dokumen terlebih dahulu'); return }
  if (!store.selectedQueue) { toast('e', 'Tidak ada antrian aktif'); return }

  finalizing.value = true
  try {
    // Catatan: penyimpanan tab2/tab4/tindakan/resep masih perlu di-wire endpoint
    // masing-masing. Untuk saat ini hanya advance queue ke station berikutnya
    // (PENUNJANG / BEDAH / KASIR — diputuskan backend per resolveNextStation).
    await store.selesaiAntrian(store.selectedQueue.id)
    finalized.value = true
    qFilter.value = 'done'
    toast('s', 'RME difinalisasi — pasien dikirim ke station berikutnya')
    store.clearSelected()
  } catch (err) {
    toast('e', err.message ?? 'Gagal menyelesaikan RME')
  } finally {
    finalizing.value = false
  }
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

      <div class="card-body queue-scroll">

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
          <button :class="['ptype-tab ptype-umum', ptypeFilter === 'Umum' ? 'a' : '']" @click="ptypeFilter = 'Umum'">
            Umum<span v-if="umumCount" class="ptype-ct">{{ umumCount }}</span>
          </button>
          <button :class="['ptype-tab ptype-asur', ptypeFilter === 'Asuransi' ? 'a' : '']" @click="ptypeFilter = 'Asuransi'">
            Asuransi<span v-if="asnCount" class="ptype-ct">{{ asnCount }}</span>
          </button>
        </div>

        <!-- Search -->
        <div class="q-search-wrap">
          <input v-model="qSearch" class="q-search" placeholder="Cari nama / no. antrean…" />
        </div>

        <div v-if="!filtQ.length" class="empty-section">Tidak ada pasien dalam filter ini</div>

        <template v-else>
          <div
            v-for="p in filtQ" :key="p.id"
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
              <span class="qi-time">{{ p.time }}</span>
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
              <div v-if="p.status !== 'done' && p.status !== 'skip'" class="q-actions" @click.stop>
                <button class="q-act-btn call" @click.stop="callPt(p)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>
                  Panggil
                </button>
                <button class="q-act-btn skip" @click.stop="skipPt(p)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg>
                  Lewati
                </button>
              </div>
            </div>
          </div>
        </template>

      </div>
      </div><!-- end qp-card -->
    </aside>

    <!-- RIGHT: RME AREA -->
    <section class="rme">
      <div class="rme-card">

      <div v-if="!selP" class="empty">
        <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        <p>Pilih atau panggil pasien dari antrean<br />untuk membuka Rekam Medis Elektronik</p>
      </div>

      <template v-if="selP">
        <!-- PATIENT BAR -->
        <div class="ptb">
          <div class="ptav">{{ selP.name.charAt(0) }}</div>
          <div class="pti">
            <div class="ptn">{{ selP.name }}</div>
            <div class="ptm">RM: {{ selP.rm }} · NIK: {{ selP.nik }} · {{ selP.age }} th · {{ selP.gender === 'L' ? 'Laki-laki' : 'Perempuan' }}</div>
            <div v-if="selP.address" class="pt-address">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
              {{ selP.address }}
            </div>
            <div class="ptags">
              <span v-if="selP.classification" :class="['ptg', clsCls(selP.classification)]">{{ selP.classification }}</span>
              <span v-if="selP.ptype === 'bpjs'" class="ptg ptg-b">BPJS · SEP {{ selP.sepNo }}</span>
              <span v-if="selP.allergies.length" class="ptg ptg-a">⚠ Alergi: {{ selP.allergies.join(', ') }}</span>
            </div>
          </div>
          <div class="vit">
            <div class="vdv"></div>
            <div class="vi">
              <div :class="['viv', Number(selP.nd.td_s) >= 140 ? 'w' : '']">{{ selP.nd.td_s }}/{{ selP.nd.td_d }}</div>
              <div class="vil">TD</div>
            </div>
            <div class="vi"><div class="viv">{{ selP.nd.nadi }}</div><div class="vil">Nadi</div></div>
            <div class="vi">
              <div :class="['viv', selP.nd.kgd > 200 ? 'w' : selP.nd.kgd < 70 ? 'lo' : '']">{{ selP.nd.kgd }}</div>
              <div class="vil">KGD</div>
            </div>
            <div class="vdv"></div>
            <div class="vi">
              <div :class="['viv', selP.rd.iop_od >= 22 || selP.rd.iop_os >= 22 ? 'w' : '']">{{ selP.rd.iop_od }}/{{ selP.rd.iop_os }}</div>
              <div class="vil">IOP</div>
            </div>
            <div class="vi"><div class="viv small">{{ selP.rd.ucva_od }}/{{ selP.rd.ucva_os }}</div><div class="vil">UCVA</div></div>
            <div class="vi"><div class="viv small">{{ selP.rd.bcva_od }}/{{ selP.rd.bcva_os }}</div><div class="vil">BCVA</div></div>
            <div class="vdv"></div>
          </div>
          <div class="dbtns">
            <button :class="['db', 'db-n', dw === 'nurse' ? 'act' : '']" @click="dw = dw === 'nurse' ? null : 'nurse'">▼ Triase</button>
            <button :class="['db', 'db-r', dw === 'ro' ? 'act' : '']" @click="dw = dw === 'ro' ? null : 'ro'">▼ RO</button>
            <button :class="['db', 'db-h', dw === 'hist' ? 'act' : '']" @click="dw = dw === 'hist' ? null : 'hist'">▼ Riwayat</button>
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
                <div class="dwr2"><span class="dwl">TD</span><span :class="['dwv', selP.nd.td_s >= 140 ? 'w' : 'ok']">{{ selP.nd.td_s }}/{{ selP.nd.td_d }} mmHg</span></div>
                <div class="dwr2"><span class="dwl">Nadi</span><span class="dwv">{{ selP.nd.nadi }} bpm</span></div>
                <div class="dwr2"><span class="dwl">SpO₂</span><span :class="['dwv', selP.nd.spo2 < 95 ? 'hi' : 'ok']">{{ selP.nd.spo2 }}%</span></div>
                <div class="dwr2"><span class="dwl">Suhu</span><span class="dwv">{{ selP.nd.suhu }}°C</span></div>
                <div class="dwr2"><span class="dwl">Nyeri</span><span class="dwv">{{ selP.nd.pain }}/10</span></div>
                <div class="dwr2"><span class="dwl">KGD</span><span :class="['dwv', selP.nd.kgd > 200 ? 'hi' : selP.nd.kgd > 140 ? 'w' : 'ok']">{{ selP.nd.kgd }} mg/dL</span></div>
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
                <div class="roi"><div class="roi-l">IOP OD</div><div class="roi-v" :class="{ warn: selP.rd.iop_od >= 22 }">{{ selP.rd.iop_od }} mmHg</div></div>
                <div class="roi"><div class="roi-l">IOP OS</div><div class="roi-v" :class="{ warn: selP.rd.iop_os >= 22 }">{{ selP.rd.iop_os }} mmHg</div></div>
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
                <div class="g4">
                  <div class="fg">
                    <label class="fl">TD (mmHg)</label>
                    <div :class="['ro-field', selP.nd.td_s >= 140 ? 'warn' : '']">{{ selP.nd.td_s }}/{{ selP.nd.td_d }}</div>
                  </div>
                  <div class="fg">
                    <label class="fl">Nadi (bpm)</label>
                    <div class="ro-field">{{ selP.nd.nadi }}</div>
                  </div>
                  <div class="fg">
                    <label class="fl">SpO₂ (%)</label>
                    <div :class="['ro-field', selP.nd.spo2 < 95 ? 'warn' : '']">{{ selP.nd.spo2 }}</div>
                  </div>
                  <div class="fg">
                    <label class="fl">Suhu (°C)</label>
                    <div class="ro-field">{{ selP.nd.suhu }}</div>
                  </div>
                </div>
                <div class="g3" style="margin-top:0.5rem">
                  <div class="fg">
                    <label class="fl">KGD (mg/dL)</label>
                    <div :class="['ro-field', selP.nd.kgd > 200 ? 'warn' : '']">{{ selP.nd.kgd }}</div>
                  </div>
                  <div class="fg">
                    <label class="fl">Skala Nyeri</label>
                    <div class="ro-field">{{ selP.nd.pain }}/10</div>
                  </div>
                  <div class="fg">
                    <label class="fl">Keluhan Utama</label>
                    <div class="ro-field left">{{ selP.nd.keluhan }}</div>
                  </div>
                </div>
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
                <div class="odos">
                  <div class="eyec od">
                    <div class="eyeh"><span class="elbl el-od">OD</span><span class="esub">Mata Kanan</span></div>
                    <div class="ef"><label>UCVA</label><div class="ro-field strong">{{ selP.rd.ucva_od }}</div></div>
                    <div class="ef"><label>BCVA</label><div class="ro-field strong success">{{ selP.rd.bcva_od }}</div></div>
                    <div class="ef"><label>IOP</label><div class="ro-field strong" :class="{ warn: selP.rd.iop_od >= 22 }">{{ selP.rd.iop_od }} mmHg</div></div>
                    <div class="divider"></div>
                    <div class="rx-line"><b>Rx RO:</b> {{ selP.rd.rx_od }}</div>
                  </div>
                  <div class="eyec os">
                    <div class="eyeh"><span class="elbl el-os">OS</span><span class="esub">Mata Kiri</span></div>
                    <div class="ef"><label>UCVA</label><div class="ro-field strong">{{ selP.rd.ucva_os }}</div></div>
                    <div class="ef"><label>BCVA</label><div class="ro-field strong success">{{ selP.rd.bcva_os }}</div></div>
                    <div class="ef"><label>IOP</label><div class="ro-field strong" :class="{ warn: selP.rd.iop_os >= 22 }">{{ selP.rd.iop_os }} mmHg</div></div>
                    <div class="divider"></div>
                    <div class="rx-line"><b>Rx RO:</b> {{ selP.rd.rx_os }}</div>
                  </div>
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

              <!-- Pemeriksaan Penunjang -->
              <div class="card">
                <div class="ch">
                  <div class="cht">
                    <svg viewBox="0 0 24 24"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2v-4M9 21H5a2 2 0 01-2-2v-4m0 0h18"/></svg>
                    Pemeriksaan Penunjang
                    <span v-if="penunjangOrders.length" class="rmt-count">{{ penunjangOrders.length }}</span>
                  </div>
                  <button class="btn btn-sm btn-primary" @click="showPenunjangModal = true">
                    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Order Penunjang
                  </button>
                </div>

                <!-- Order list -->
                <div v-if="penunjangOrders.length" class="penunjang-list">
                  <div v-for="o in penunjangOrders" :key="o.id" class="penunjang-item">
                    <svg viewBox="0 0 24 24" class="penunjang-item-icon"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2v-4M9 21H5a2 2 0 01-2-2v-4m0 0h18"/></svg>
                    <div class="penunjang-item-info">
                      <div class="penunjang-item-name">{{ o.name }}</div>
                      <div class="penunjang-item-cat">{{ o.category }}</div>
                    </div>
                    <span class="penunjang-item-status ordered">Dipesan</span>
                    <button class="penunjang-item-del" @click="removePenunjang(o.id)" title="Batalkan">×</button>
                  </div>
                </div>
                <div v-else class="penunjang-empty">
                  Belum ada pemeriksaan penunjang dipesan
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
                    Riwayat SOAP
                  </div>
                  <span class="card-counter">{{ selP.soapHistory?.length ?? 0 }} kunjungan</span>
                </div>
                <div v-if="!selP.soapHistory?.length" class="penunjang-empty">
                  Belum ada riwayat SOAP
                </div>
                <div v-else class="soap-history-list">
                  <div
                    v-for="h in [...(selP.soapHistory ?? [])].reverse()"
                    :key="h.date"
                    class="soap-history-item"
                  >
                    <div class="soap-history-date">{{ h.date }}</div>
                    <div class="soap-history-row"><span class="soap-mini-key s">S</span><span class="soap-history-val">{{ h.S }}</span></div>
                    <div class="soap-history-row"><span class="soap-mini-key o">O</span><span class="soap-history-val">{{ h.O }}</span></div>
                    <div class="soap-history-row"><span class="soap-mini-key a">A</span><span class="soap-history-val soap-a">{{ h.A }}</span></div>
                    <div class="soap-history-row"><span class="soap-mini-key p">P</span><span class="soap-history-val">{{ h.P }}</span></div>
                  </div>
                </div>
                <div class="soap-mini-footer">
                  <button class="btn btn-sm btn-secondary" style="width:100%;justify-content:center" @click="tab = 'soap'">
                    Tulis SOAP Kunjungan Ini →
                  </button>
                </div>
              </div>

              <!-- Hasil Penunjang card -->
              <div class="card">
                <div class="ch">
                  <div class="cht">
                    <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    Hasil Penunjang
                  </div>
                  <span class="card-counter">{{ selP.penunjang?.length ?? 0 }} hasil</span>
                </div>

                <template v-if="selP.penunjang?.length">
                  <div class="hasil-list">
                    <div v-for="h in selP.penunjang" :key="h.name" class="hasil-item">
                      <div class="hasil-meta">
                        <span class="hasil-name">{{ h.name }}</span>
                        <div class="hasil-meta-right">
                          <span class="hasil-date">{{ h.date }}</span>
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

                <template v-if="penunjangOrders.length">
                  <div class="hasil-pending-title">Dipesan hari ini</div>
                  <div class="hasil-list">
                    <div v-for="o in penunjangOrders" :key="o.id" class="hasil-item pending">
                      <div class="hasil-meta">
                        <span class="hasil-name">{{ o.name }}</span>
                        <span class="hasil-pending-badge">Menunggu hasil</span>
                      </div>
                    </div>
                  </div>
                </template>

                <div v-if="!selP.penunjang?.length && !penunjangOrders.length" class="penunjang-empty">
                  Belum ada hasil penunjang
                </div>
              </div>

            </div>
          </div>

          <!-- ═══ TAB 3: TINDAKAN & RESEP ════════════════════════════════════ -->
          <div v-if="tab === 'tindakan'" :class="['af', isLocked ? 'pane-locked' : '']">

            <!-- Tindakan dari Master Tarif -->
            <div class="card">
              <div class="ch">
                <div class="cht">
                  <svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                  Tindakan Medis
                </div>
                <div style="display:flex;align-items:center;gap:0.5rem">
                  <span v-if="selP?.ptype === 'bpjs'" class="tarif-type-badge bpjs">Tarif BPJS</span>
                  <span v-else class="tarif-type-badge umum">Tarif Umum</span>
                  <span class="card-counter">{{ tindakanList.length }} item</span>
                </div>
              </div>
              <div class="cb">
                <!-- Toggle tambah tindakan -->
                <div class="tarif-toggle-row">
                  <button :class="['tarif-toggle-btn', showTarifList ? 'open' : '']" @click="showTarifList = !showTarifList">
                    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Tambah Tindakan
                    <svg class="chevron-sm" :class="{ up: showTarifList }" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                  </button>
                  <span v-if="tindakanList.length" class="tarif-sel-count">{{ tindakanList.length }} dipilih</span>
                </div>

                <!-- Collapsible tarif list -->
                <div v-if="showTarifList" class="tarif-list-panel">
                  <input v-model="tindakanSearch" class="form-input" style="margin-bottom:0.5rem" placeholder="Cari tindakan..." />
                  <div class="tarif-list">
                    <div
                      v-for="t in filteredTarif" :key="t.kode"
                      :class="['tarif-list-item', tindakanList.find(x=>x.kode===t.kode) ? 'in-list' : '']"
                      @click="addTindakan(t)"
                    >
                      <span class="tarif-kode">{{ t.kode }}</span>
                      <span :class="['tarif-kat', t.kategori === 'Jasa Medis' ? 'jm' : 'td']">{{ t.kategori }}</span>
                      <span class="tarif-list-name">{{ t.nama }}</span>
                      <span class="tarif-list-price">
                        {{ selP?.ptype === 'bpjs' ? fmtRp(t.tarifBpjs) : fmtRp(t.tarifUmum) }}
                        <em>/ {{ t.satuan }}</em>
                      </span>
                      <svg v-if="tindakanList.find(x=>x.kode===t.kode)" class="tarif-list-icon check" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                      <svg v-else class="tarif-list-icon add" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </div>
                    <div v-if="!filteredTarif.length" class="tarif-empty">Tidak ditemukan</div>
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
                  <div v-for="t in tindakanList" :key="t.kode" class="tindakan-row">
                    <div class="tindakan-info">
                      <span class="tarif-kode">{{ t.kode }}</span>
                      <span class="tindakan-nama">{{ t.nama }}</span>
                    </div>
                    <div class="tindakan-rate">
                      {{ selP?.ptype === 'bpjs' ? fmtRp(t.tarifBpjs) : fmtRp(t.tarifUmum) }}
                    </div>
                    <div class="tindakan-qty">
                      <button class="qty-btn" @click="t.qty > 1 && t.qty--">−</button>
                      <span class="qty-val">{{ t.qty }}</span>
                      <button class="qty-btn" @click="t.qty++">+</button>
                    </div>
                    <div class="tindakan-subtotal">
                      {{ fmtRp((selP?.ptype === 'bpjs' ? t.tarifBpjs : t.tarifUmum) * t.qty) }}
                    </div>
                    <button class="dx-remove" @click="removeTindakan(t.kode)">
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
                <!-- Header kolom -->
                <div class="rx-form-header">
                  <span class="rx-h-name">Nama Obat</span>
                  <span class="rx-h-qty">Jumlah</span>
                  <span class="rx-h-sig">Signa</span>
                  <span class="rx-h-dur">Durasi</span>
                  <span class="rx-h-pos">Posisi</span>
                  <span style="width:80px"></span>
                </div>
                <!-- Input row -->
                <div class="rx-add-row">
                  <input v-model="newRx.name" class="form-input rx-f-name" placeholder="Nama obat…" list="drug-list" />
                  <datalist id="drug-list">
                    <option v-for="d in drugDB" :key="d.name" :value="d.name">{{ d.form }} · stok {{ d.stock }}</option>
                  </datalist>
                  <input v-model="newRx.jumlah" class="form-input rx-f-qty" placeholder="1 tetes" />
                  <input v-model="newRx.signa"  class="form-input rx-f-sig" placeholder="2×/hari" />
                  <input v-model="newRx.dur"    class="form-input rx-f-dur" placeholder="7 hari" />
                  <input v-model="newRx.posisi" class="form-input rx-f-pos" placeholder="Tetes ODS" />
                  <button class="btn btn-success rx-f-btn" @click="addRx">
                    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Tambah
                  </button>
                </div>
                <!-- Obat list -->
                <div v-if="rxList.length" class="rx-list">
                  <div v-for="(r, i) in rxList" :key="i" class="rx-row">
                    <div class="rx-icon">
                      <svg viewBox="0 0 24 24"><path d="M10 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z"/></svg>
                    </div>
                    <div class="rx-body">
                      <div class="rx-name">{{ r.name }}</div>
                      <div class="rx-meta">{{ r.jumlah }} · {{ r.signa }} · {{ r.dur }} · {{ r.posisi }}</div>
                    </div>
                    <button class="dx-remove" @click="removeRx(i)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                  </div>
                </div>
                <div v-else class="dx-empty">Belum ada obat dalam resep</div>
              </div>
            </div>

            <button class="btn btn-primary" @click="tab = 'soap'">
              Lanjut ke SOAP &amp; Diagnosis
              <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
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
            <div :class="isLocked ? 'pane-locked' : ''">

            <!-- SOAP -->
            <div class="card">
              <div class="ch">
                <div class="cht">
                  <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>
                  SOAP
                </div>
                <button class="btn btn-sm btn-secondary" @click="autoO">
                  <svg viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                  Auto-isi O dari triase
                </button>
              </div>
              <div class="cb stack">
                <div class="soap-row">
                  <div class="soap-letter s">S</div>
                  <div class="fg" style="flex:1">
                    <label class="fl">Subjektif — keluhan pasien</label>
                    <textarea v-model="soap.S" class="form-textarea" rows="3"
                      placeholder="Keluhan pasien, lama keluhan, onset..."></textarea>
                  </div>
                </div>
                <div class="soap-row">
                  <div class="soap-letter o">O</div>
                  <div class="fg" style="flex:1">
                    <label class="fl">Objektif — hasil pemeriksaan</label>
                    <textarea v-model="soap.O" class="form-textarea" rows="5"
                      placeholder="Visus, IOP, Rx, segmen anterior/posterior..."></textarea>
                  </div>
                </div>
                <div class="soap-row">
                  <div class="soap-letter a">A</div>
                  <div class="fg" style="flex:1">
                    <label class="fl">Assessment — kesimpulan klinis <span class="req">*</span></label>
                    <textarea v-model="soap.A" class="form-textarea" rows="2"
                      placeholder="Diferensial diagnosis, kesimpulan..."></textarea>
                  </div>
                </div>
                <div class="soap-row">
                  <div class="soap-letter p">P</div>
                  <div class="fg" style="flex:1">
                    <label class="fl">Planning — rencana tindakan</label>
                    <textarea v-model="soap.P" class="form-textarea" rows="2"
                      placeholder="Terapi, edukasi, follow-up, rujukan..."></textarea>
                  </div>
                </div>
              </div>
            </div>

            <!-- ICD-10 & ICD-9 side by side -->
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

            </div>

            <!-- Planning -->
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
                      <div class="plan-title">Pulang Berobat Jalan</div>
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
                      <div class="plan-title">Jadwalkan Bedah</div>
                      <div class="plan-sub">Antrean kamar operasi</div>
                    </div>
                    <div class="plan-check">
                      <svg v-if="planning === 'BEDAH'" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                    </div>
                  </div>
                  <!-- Rujuk -->
                  <div :class="['plan-opt', planning === 'RUJUK' ? 'selected' : '']" @click="planning = 'RUJUK'">
                    <div class="plan-icon rujuk">
                      <svg viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </div>
                    <div class="plan-body">
                      <div class="plan-title">Rujuk</div>
                      <div class="plan-sub">Ke RS / Faskes lain</div>
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
                </div>

                <!-- Bedah: Paket + Tanggal -->
                <div v-if="planning === 'BEDAH'" class="surgery-fields">
                  <div class="g2">
                    <div class="fg">
                      <label class="fl">Paket Bedah</label>
                      <select v-model="surgeryPkg" class="form-select">
                        <option value="">— Pilih paket bedah —</option>
                        <option v-for="p in surgeryPackages" :key="p.id" :value="p.id">{{ p.name }}</option>
                      </select>
                    </div>
                    <div class="fg">
                      <label class="fl">Tanggal Bedah</label>
                      <input type="date" v-model="surgeryDate" class="form-input" />
                    </div>
                  </div>
                </div>

                <!-- Rujuk: Faskes + Alasan -->
                <div v-if="planning === 'RUJUK'" class="plan-fields">
                  <div class="g2">
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

            </div><!-- end pane-locked -->

            <!-- Tanda Tangan Digital -->
            <div class="card">
              <div class="ch">
                <div class="cht">
                  <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                  Tanda Tangan Digital
                </div>
                <div style="display:flex;align-items:center;gap:6px">
                  <span v-if="signed" class="savedb">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Ditandatangani
                  </span>
                  <button v-if="signed && !finalized" class="btn btn-sm btn-secondary undo-sig-btn" @click="undoSign">
                    <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
                    Hapus Tanda Tangan
                  </button>
                </div>
              </div>
              <div class="cb">

                <!-- Belum TTD -->
                <div v-if="!signed">
                  <div class="sig-action-wrap">
                    <div class="sig-doctor-info">
                      <div class="sig-avatar">
                        <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                      </div>
                      <div>
                        <div class="sig-name">dr. Andika P, Sp.M</div>
                        <div class="sig-role">Dokter Spesialis Mata — SIP: 1234/IBI/2024</div>
                      </div>
                    </div>
                    <!-- Step 1: tombol tanda tangan -->
                    <button v-if="!showPinForm" class="btn btn-primary" @click="showPinForm = true; pinInput = ''; pinError = ''">
                      <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                      Tanda Tangan
                    </button>
                  </div>

                  <!-- Step 2: PIN inline muncul -->
                  <div v-if="showPinForm" class="sig-pin-inline">
                    <input
                      v-model="pinInput"
                      type="password"
                      :class="['form-input sig-pin-input', { 'pin-error': pinError }]"
                      placeholder="Masukkan PIN"
                      maxlength="6"
                      @keydown.enter="doSign"
                    />
                    <button class="btn btn-primary" @click="doSign">
                      <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                      Tanda Tangan
                    </button>
                    <button class="btn btn-secondary" @click="showPinForm = false; pinInput = ''; pinError = ''">Batal</button>
                  </div>
                  <div v-if="showPinForm" class="sig-pin-feedback">
                    <span v-if="pinError" class="sig-pin-error">
                      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                      {{ pinError }}
                    </span>
                    <span v-else class="sig-pin-hint">PIN login dokter Anda (demo: 1234)</span>
                  </div>
                </div>

                <!-- Sudah TTD -->
                <div v-else class="sig-done">
                  <div class="sig-avatar sig-av-success">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                  </div>
                  <div>
                    <div class="sig-name">dr. Andika P, Sp.M</div>
                    <div class="sig-ts">Ditandatangani pada {{ signTimestamp }}</div>
                  </div>
                </div>

              </div>
            </div>

            <!-- Finalisasi section — selalu accessible -->
            <div class="fin-section">
              <div v-if="!finalized" :class="['fin-card', !signed ? 'fin-disabled' : '']">
                <div class="fin-info">
                  <svg viewBox="0 0 24 24">
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
                      {{ signed ? 'Tanda tangan dokter terkonfirmasi — klik Finalisasi untuk kirim FHIR R4' : 'Lengkapi Dx · Assessment · Planning · Tanda Tangan terlebih dahulu' }}
                    </div>
                  </div>
                </div>
                <button class="btn btn-primary btn-lg" :disabled="finalizing || !signed" @click="doFinalize">
                  <div v-if="finalizing" class="sp"></div>
                  <svg v-else viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                  {{ finalizing ? 'Memproses & mengirim FHIR...' : 'Finalisasi & Kirim ke Satu Sehat' }}
                </button>
              </div>
              <div v-else class="fin-success">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                RME telah difinalisasi &amp; terkirim ke Satu Sehat
              </div>
            </div>

          </div>
        </div>
      </template>

      </div><!-- end rme-card -->
    </section>

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
                :class="['penunjang-modal-chip', penunjangOrders.find(x => x.id === t.id) ? 'ordered' : '']"
                @click="orderPenunjang(t)"
              >
                <div class="penunjang-modal-chip-name">{{ t.name }}</div>
                <div class="penunjang-modal-chip-cat">{{ t.category }}</div>
                <div v-if="penunjangOrders.find(x => x.id === t.id)" class="penunjang-modal-chip-check">
                  <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                  Dipesan
                </div>
              </div>
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
            <button class="btn btn-primary" :disabled="!penunjangOrders.length" @click="showPenunjangModal = false">
              Konfirmasi {{ penunjangOrders.length }} Pemeriksaan
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
            </div>
            <div class="hasil-modal-label">Hasil Pemeriksaan</div>
            <div class="hasil-modal-result">{{ selectedHasil.result }}</div>
          </div>
          <div class="modal-box-foot">
            <button class="btn btn-secondary" @click="showHasilModal = false">Tutup</button>
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
.pf-btn { flex: 1; height: 32px; font-size: 11.5px; font-weight: 500; border: 1.5px solid var(--gb); border-radius: 8px; background: var(--bs); color: var(--tm); cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .13s; display: flex; align-items: center; justify-content: center; gap: 5px; }
.pf-btn:hover { border-color: var(--ga); color: var(--ga); }
.pf-btn.a { background: var(--gd); color: #fff; border-color: var(--gd); }
.pf-ct { font-size: 9px; font-weight: 700; padding: 0 5px; border-radius: 10px; background: rgba(255,255,255,.25); }

.ptype-tabs { display: flex; gap: 3px; }
.ptype-tab { flex: 1; padding: 5px 3px; font-size: 9.5px; font-weight: 600; border: 1.5px solid var(--gb); border-radius: 7px; background: var(--bs); color: var(--tu); cursor: pointer; font-family: 'DM Sans', sans-serif; text-align: center; transition: all .13s; white-space: nowrap; }
.ptype-tab:hover { border-color: var(--ga); color: var(--ga); }
.ptype-tab.a { color: #fff; font-weight: 700; }
.ptype-bpjs.a { background: #1d4ed8; border-color: #1d4ed8; }
.ptype-umum.a { background: var(--ga); border-color: var(--ga); }
.ptype-asur.a { background: var(--pt); border-color: var(--pt); }

.q-search-wrap { }
.q-search { width: 100%; height: 30px; font-size: 11.5px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 10px; background: var(--bs); font-family: 'DM Sans', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.q-search:focus { border-color: var(--ga); background: #fff; }

.empty-section { text-align: center; padding: 0.75rem 1rem; font-size: 11px; color: var(--th); background: var(--bi); border-radius: 7px; border: 1px dashed var(--gb); }

.q-item { display: flex; gap: 8px; padding: 8px 10px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; margin-bottom: 5px; cursor: pointer; transition: all 0.14s; font-family: 'DM Sans', sans-serif; }
.q-item:hover { border-color: var(--lm); background: var(--gl); }
.q-item.active { border-color: var(--ga); background: var(--gl); }
.q-item.done { opacity: .55; }
.q-item:focus-visible { outline: 2px solid var(--ga); outline-offset: 2px; }
.qi-left { display: flex; flex-direction: column; gap: 4px; min-width: 52px; }
.q-num { font-weight: 700; font-size: 13px; color: var(--ga); letter-spacing: 0.03em; }
.qi-time { font-size: 9px; color: var(--tu); font-variant-numeric: tabular-nums; }
.q-info { flex: 1; min-width: 0; }
.q-name { font-size: 12px; font-weight: 500; color: var(--td); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.q-meta { font-size: 9.5px; color: var(--tu); margin-top: 2px; }
.q-tags { display: flex; gap: 3px; margin-top: 3px; flex-wrap: wrap; }

.pill { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px; }
.pill-icon { width: 8px; height: 8px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; flex-shrink: 0; }
.pill-waiting     { background: #fef3c7; color: #92400e; }
.pill-in_progress { background: #dbeafe; color: #1e40af; }
.pill-completed   { background: var(--sb); color: var(--st); }
.pill-bpjs   { background: #dbeafe; color: #1e40af; }
.pill-umum   { background: var(--gl); color: var(--ga); }
.pill-asn    { background: var(--pb); color: var(--pt); }
.pill-done   { background: var(--sb); color: var(--st); }
.pill-ro     { background: var(--ib); color: var(--it); }
.pill-allergy { background: var(--eb); color: var(--et); }

.q-actions { display: flex; gap: 4px; margin-top: 5px; padding-top: 5px; border-top: 1px dashed var(--gb); width: 100%; }
.q-act-btn { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; font-size: 10px; font-weight: 600; border-radius: 5px; border: 1px solid; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .12s; background: none; }
.q-act-btn svg { width: 10px; height: 10px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.q-act-btn.call { color: var(--ga); border-color: var(--ga); background: var(--gl); }
.q-act-btn.call:hover { background: var(--ga); color: #fff; }
.q-act-btn.skip { color: var(--tu); border-color: var(--gb); }
.q-act-btn.skip:hover { background: var(--wb); color: var(--wt); border-color: var(--wbd); }

/* RME */
.rme { flex: 1; display: flex; flex-direction: column; min-width: 0; margin: 0.75rem 0.75rem 0.75rem 0; }
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
.ptn { font-family: 'DM Serif Display', serif; font-size: 18px; color: var(--gd); line-height: 1.1; font-weight: 400; }
.ptm { font-size: 11px; color: var(--tu); margin-top: 3px; }
.pt-address { font-size: 10.5px; color: var(--tu); margin-top: 3px; display: flex; align-items: center; gap: 4px; }
.pt-address svg { width: 10px; height: 10px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.ptags { display: flex; gap: 4px; margin-top: 5px; flex-wrap: wrap; }
.ptg { font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 4px; letter-spacing: 0.02em; }
.ptg-b { background: #dbeafe; color: #1e40af; }
.ptg-a { background: var(--eb); color: var(--et); }
.cls-baru    { background: #dbeafe; color: #1e40af; }
.cls-preop   { background: #fef3c7; color: #92400e; }
.cls-postop  { background: var(--sb); color: var(--st); }
.cls-kontrol { background: #f3e8ff; color: #7e22ce; }
.vit { display: flex; align-items: center; gap: 0.6rem; padding: 0 0.8rem; align-self: center; }
.vdv { width: 1px; height: 38px; background: var(--gb); }
.vi { display: flex; flex-direction: column; align-items: center; gap: 2px; min-width: 50px; }
.viv { font-size: 14.5px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; line-height: 1; }
.viv.small { font-size: 11.5px; }
.viv.w { color: var(--wt); }
.viv.lo { color: var(--it); }
.vil { font-size: 9px; color: var(--tu); letter-spacing: 0.05em; text-transform: uppercase; }
.dbtns { display: flex; gap: 4px; flex-shrink: 0; }
.db {
  padding: 5px 10px; font-size: 10.5px; font-weight: 600; border-radius: 7px;
  border: 1.5px solid var(--gb); background: var(--bc); cursor: pointer; color: var(--tu);
  transition: all 0.15s; font-family: 'DM Sans', sans-serif;
}
.db:hover { color: var(--td); }
.db.act { background: var(--gd); color: #fff; border-color: var(--gd); }

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
.dwr2 { display: flex; align-items: center; justify-content: space-between; font-size: 11px; }
.dwl { color: var(--tu); }
.dwv { font-weight: 600; font-variant-numeric: tabular-nums; }
.dwv.ok { color: var(--st); }
.dwv.w { color: var(--wt); }
.dwv.hi { color: var(--et); }
.dw-keluhan { margin-top: 0.5rem; font-size: 11px; color: var(--tm); }
.dw-keluhan b { color: var(--td); }
.rog { display: grid; grid-template-columns: repeat(2,1fr); gap: 0.35rem 1rem; margin-bottom: 0.5rem; }
.roi { display: flex; align-items: center; justify-content: space-between; }
.roi-l { font-size: 10px; color: var(--tu); text-transform: uppercase; letter-spacing: 0.05em; }
.roi-v { font-size: 12px; font-weight: 600; color: var(--td); }
.roi-v.warn { color: var(--wt); }
.rx-line { font-size: 11px; color: var(--td); margin-top: 3px; }
.rx-line b { color: var(--tm); font-weight: 600; }
.ro-note {
  background: var(--ib); border: 1px solid var(--ibd); border-radius: 7px;
  padding: 0.45rem 0.65rem; font-size: 11px; color: var(--it); margin-top: 0.5rem;
}
.histi { padding: 0.45rem 0.55rem; border: 1px solid var(--gb); border-radius: 7px; background: var(--bs); margin-bottom: 4px; }
.hd { font-size: 10.5px; font-weight: 600; color: var(--gd); }
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
  font-family: 'DM Sans', sans-serif; position: relative;
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

.penunjang-modal-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 1rem; }
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
.hasil-name { font-size: 11.5px; font-weight: 600; color: var(--td); }
.hasil-date { font-size: 9.5px; color: var(--tu); flex-shrink: 0; }
.hasil-result { font-size: 11px; color: var(--tm); line-height: 1.45; }
.hasil-pending-badge { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 4px; background: var(--ib); color: var(--it); flex-shrink: 0; }
.hasil-pending-title { font-size: 9.5px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: 0.06em; padding: 0.4rem 0.85rem 0; border-top: 1px dashed var(--gb); }

/* Lihat button */
.btn-lihat { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; font-size: 10px; font-weight: 600; border-radius: 5px; border: 1px solid var(--ga); background: var(--gl); color: var(--ga); cursor: pointer; font-family: 'DM Sans',sans-serif; transition: all .12s; flex-shrink: 0; }
.btn-lihat:hover { background: var(--ga); color: #fff; }
.btn-lihat svg { width: 9px; height: 9px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.hasil-meta-right { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }

/* Modal hasil detail */
.hasil-modal-date { display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: var(--tu); margin-bottom: 0.9rem; }
.hasil-modal-date svg { width: 13px; height: 13px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.hasil-modal-label { font-size: 10px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.45rem; }
.hasil-modal-result { background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 0.85rem 1rem; font-size: 13px; color: var(--td); line-height: 1.65; }

/* CONTENT */
.rmc { flex: 1; overflow-y: auto; padding: 1rem 1.25rem; background: var(--bg); }
.rmc::-webkit-scrollbar { width: 4px; }
.rmc::-webkit-scrollbar-thumb { background: var(--gb); border-radius: 2px; }
.af { display: flex; flex-direction: column; gap: 1rem; }

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
  font-family: 'DM Sans', sans-serif;
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
.seg-label { font-size: 12px; font-weight: 500; color: var(--td); }

/* SOAP */
.soap-row { display: flex; gap: 0.75rem; align-items: flex-start; }
.soap-letter {
  width: 28px; height: 28px; border-radius: 7px; flex-shrink: 0; margin-top: 18px;
  font-size: 13px; font-weight: 800; display: flex; align-items: center; justify-content: center;
}
.soap-letter.s { background: #dbeafe; color: #1e40af; }
.soap-letter.o { background: var(--sb); color: var(--st); }
.soap-letter.a { background: var(--wb); color: var(--wt); }
.soap-letter.p { background: var(--pb); color: var(--pt); }

/* ── ICD side-by-side grid ───────────────────────────────────────────────── */
.dx-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

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
.dx-name { flex: 1; font-size: 12px; color: var(--td); }
.dx-remove { background: none; border: none; cursor: pointer; color: var(--tu); padding: 2px; display: flex; }
.dx-remove svg { width: 13px; height: 13px; }
.dx-remove:hover { color: var(--et); }
.dx-empty { text-align: center; padding: 1rem; font-size: 11.5px; color: var(--th); }
.dx-empty-sm { font-size: 11px; color: var(--th); font-style: italic; padding: 0.35rem 0; }
.icd9-badge { font-size: 8px; font-weight: 700; padding: 1px 5px; border-radius: 3px; background: var(--pb); color: var(--pt); letter-spacing: 0.04em; }

/* TINDAKAN / RX */
.rx-list { display: flex; flex-direction: column; gap: 5px; }
.rx-row { display: flex; align-items: center; gap: 10px; padding: 9px 11px; background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; }
.rx-icon { flex-shrink: 0; }
.rx-icon svg { width: 16px; height: 16px; fill: none; stroke: var(--ga); stroke-width: 1.5; stroke-linecap: round; }
.rx-body { flex: 1; min-width: 0; }
.rx-name { font-size: 12.5px; font-weight: 500; color: var(--td); }
.rx-meta { font-size: 10.5px; color: var(--tu); margin-top: 1px; }

/* PLANNING */
.plan-opts { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.65rem; margin-bottom: 0.75rem; }
.plan-opt { display: flex; align-items: center; gap: 0.75rem; padding: 0.85rem 0.9rem; border: 2px solid var(--gb); border-radius: 10px; background: var(--bs); cursor: pointer; transition: all 0.15s; }
.plan-opt:hover { border-color: var(--ga); background: var(--gl); }
.plan-opt.selected { border-color: var(--ga); background: var(--gl); }
.plan-icon { width: 34px; height: 34px; border-radius: 8px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
.plan-icon.pulang { background: var(--sb); }
.plan-icon.bedah { background: var(--wb); }
.plan-icon.rujuk { background: var(--ib); }
.plan-icon svg { width: 16px; height: 16px; fill: none; stroke-width: 2; stroke-linecap: round; }
.plan-icon.pulang svg { stroke: var(--st); }
.plan-icon.bedah svg { stroke: var(--wt); }
.plan-icon.rujuk svg { stroke: var(--it); }
.plan-body { flex: 1; min-width: 0; }
.plan-title { font-size: 12px; font-weight: 600; color: var(--td); }
.plan-sub { font-size: 9.5px; color: var(--tu); margin-top: 2px; }
.plan-check { width: 18px; flex-shrink: 0; }
.plan-check svg { width: 18px; height: 18px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.surgery-fields, .plan-fields { background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 0.85rem 1rem; margin-top: 0; }

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
.sig-name { font-size: 13px; font-weight: 600; color: var(--gd); }
.sig-ts { font-size: 10.5px; color: var(--tu); margin-top: 2px; }

/* ── Tarif collapsible list ──────────────────────────────────────────────── */
.tarif-toggle-row { display: flex; align-items: center; gap: 0.65rem; margin-bottom: 0.55rem; }
.tarif-toggle-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border: 1.5px solid var(--gb); border-radius: 8px; background: var(--bs); font-size: 12.5px; font-weight: 500; color: var(--tm); cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all .13s; }
.tarif-toggle-btn:hover { border-color: var(--ga); color: var(--gd); background: var(--gl); }
.tarif-toggle-btn.open { border-color: var(--ga); background: var(--gl); color: var(--gd); }
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
.tarif-list-price { font-size: 11.5px; font-weight: 600; color: var(--gd); white-space: nowrap; font-variant-numeric: tabular-nums; }
.tarif-list-price em { font-style: normal; font-size: 9.5px; color: var(--tu); font-weight: 400; margin-left: 2px; }
.tarif-list-icon { width: 14px; height: 14px; flex-shrink: 0; fill: none; stroke-width: 2; stroke-linecap: round; }
.tarif-list-icon.add { stroke: var(--ga); }
.tarif-list-icon.check { stroke: var(--st); stroke-width: 2.5; }

/* ── E-Resep single row ──────────────────────────────────────────────────── */
.rx-form-header { display: flex; gap: 6px; align-items: center; margin-bottom: 4px; padding: 0 2px; }
.rx-form-header span { font-size: 9.5px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: 0.04em; }
.rx-h-name { flex: 2.5; min-width: 0; }
.rx-h-qty  { flex: 0.9; min-width: 0; }
.rx-h-sig  { flex: 1.1; min-width: 0; }
.rx-h-dur  { flex: 1;   min-width: 0; }
.rx-h-pos  { flex: 1.1; min-width: 0; }

.rx-add-row { display: flex; gap: 6px; align-items: center; margin-bottom: 0.75rem; }
.rx-f-name { flex: 2.5; min-width: 0; }
.rx-f-qty  { flex: 0.9; min-width: 0; }
.rx-f-sig  { flex: 1.1; min-width: 0; }
.rx-f-dur  { flex: 1;   min-width: 0; }
.rx-f-pos  { flex: 1.1; min-width: 0; }
.rx-f-btn  { flex-shrink: 0; white-space: nowrap; height: 34px; padding: 0 12px; font-size: 12px; }

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
  color: #92400e; cursor: pointer; font-family: 'DM Sans', sans-serif;
  white-space: nowrap;
}
.lock-notice-undo:hover { background: #fde68a; }

/* ── Finalisasi inline section ───────────────────────────────────────────── */
.fin-section { margin-top: 0; }
.fin-card {
  display: flex; align-items: center; gap: 1rem;
  padding: 1rem 1.25rem; background: var(--gl);
  border: 2px solid rgba(31,125,74,.2); border-radius: 12px;
}
.fin-card.fin-disabled { background: var(--bs); border-color: var(--gb); opacity: .8; }
.fin-info { display: flex; align-items: center; gap: 0.75rem; flex: 1; min-width: 0; }
.fin-info svg { width: 20px; height: 20px; fill: none; stroke: var(--tu); stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.fin-card:not(.fin-disabled) .fin-info svg { stroke: var(--ga); }
.fin-title { font-size: 13px; font-weight: 700; color: var(--td); }
.fin-title.fin-ready { color: var(--gd); }
.fin-sub { font-size: 11px; color: var(--tu); margin-top: 2px; }
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
.fab-btn { display: inline-flex; align-items: center; gap: 7px; background: var(--gd); color: #fff; border: none; border-radius: 10px; padding: 0 20px; height: 44px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: 'DM Sans', sans-serif; box-shadow: 0 4px 18px rgba(31,125,74,.38); transition: all .15s; white-space: nowrap; }
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
  font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500;
  cursor: pointer; transition: all 0.15s; border: 1.5px solid transparent;
}
.btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover:not(:disabled) { background: var(--gm); }
.btn-primary:disabled { background: var(--th); cursor: not-allowed; }
.btn-success { background: var(--ga); color: #fff; border-color: var(--ga); }
.btn-success:hover { background: var(--gm); }
.btn-secondary { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-secondary:hover { border-color: var(--ga); color: var(--gd); background: var(--gl); }
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
.tarif-type-badge.umum { background: var(--gl); color: var(--gd); }

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
.tarif-kat.td { background: var(--gl); color: var(--gd); border: 1px solid rgba(31,125,74,0.2); }
.tarif-nama { font-size: 11px; font-weight: 500; color: var(--td); margin-bottom: 5px; line-height: 1.35; }
.tarif-harga { font-size: 11.5px; font-weight: 700; color: var(--gd); }
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
  font-size: 12px; font-weight: 600; color: var(--gd);
}
.tindakan-total-val { font-size: 15px; font-weight: 700; font-variant-numeric: tabular-nums; }
</style>
