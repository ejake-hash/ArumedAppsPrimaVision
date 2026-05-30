<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useMasterDataStore } from '@/stores/masterDataStore'
import { bedahApi, alatMedisApi, masterApi } from '@/services/api'

const masterStore = useMasterDataStore()

// ── Data ───────────────────────────────────────────────────────────────────────
// Real queue dari backend (/bedah/antrian). UI tabs Pra-Bedah/Intraop/Laporan
// pakai field default kalau real data tidak punya (untuk action operasi detail —
// belum diwire ke backend, scope plan terpisah).
const patients = ref([])
const employees = ref([])
const loadingQueue = ref(false)

// Daftar Ruang OK dari Profil Klinik (settings global).
const operatingRooms = computed(() => masterStore.profilKlinik?.operating_rooms ?? [])

/**
 * Transform queue row dari backend ke shape yg dipakai UI existing (mock-like).
 * Field yg tidak ada di real data diisi default supaya UI tidak crash.
 */
function transformQueueItem(q) {
  const sched = q.surgery_schedule
  const pkg   = sched?.package
  return {
    // ── Real data ──
    id:             q.id,
    qNum:           q.queue_number,
    queueStatus:    q.status,            // WAITING/CALLED/IN_PROGRESS/COMPLETED
    visitId:        q.visit?.id,
    visitType:      q.visit?.visit_type,
    scheduleId:     sched?.id ?? null,
    classification: q.visit?.classification ?? 'Pre-Op',
    rm:             q.patient?.no_rm ?? '—',
    name:           q.patient?.name ?? '—',
    age:            q.patient?.age ?? '—',
    gender:         q.patient?.gender === 'L' ? 'Laki-laki' : (q.patient?.gender === 'P' ? 'Perempuan' : '—'),
    ptype:          q.visit?.guarantor_type === 'BPJS' ? 'bpjs' : 'umum',
    ruang:          sched?.operation_room ?? '—',
    scheduledTime:  sched?.scheduled_time ?? null,   // null = jam belum ditentukan dokter (opsional)
    scheduledDate:  sched?.scheduled_date,
    paketBedah:     pkg ? { kode: (pkg.id || '').slice(0, 6), nama: pkg.name } : null,
    prosedur:       pkg?.name ?? 'Tindakan bedah',

    // ── UI-mock defaults (action operasi belum diwire) ──
    status:         q.status === 'COMPLETED' ? 'SELESAI'
                  : q.status === 'IN_PROGRESS' ? 'BERLANGSUNG'
                  : 'MENUNGGU',
    icdProsedur:    '',
    dpjp:           q.visit?.dpjp ?? '',          // operator utama (lead surgeon / dokter pemeriksa)
    diagnosa:       q.visit?.diagnosa ?? '',      // kode ICD-10 diagnosis utama dari dokter
    diagnosaPasca:  '',
    isPhaco:        false,
    recordId:       null,           // surgery_records.id (diisi saat mulai/timeout/pick)
    timIn:          null,
    timOut:         null,
    checklist:      { identitas: false, consent: false, lokasi: false, pupil: false, alergi: false },
    tim:            { operator: '', asisten1: '', asisten2: '', scrubNurse: '', circNurse: '', anestesi: '' },
    bhp:            [],
    iolRencana:     { merk: '', power: '', series: '', tipe: 'Monofocal' },
    iolDipasang:    { merk: '', power: '', series: '', tipe: 'Monofocal' },
    anestesi:       'Topikal',
    catatanIntra:   '',
    teknikOp:       '',
    temuanIntra:    '',
    komplikasi:     false,
    komplikasiTipe: '',
    komplikasiNote: '',
    instruksi:      Array(6).fill(false),
    obatPasca:      [],
    resepSent:      false,
    laporanFinalized: q.status === 'COMPLETED',
  }
}

async function loadQueue() {
  loadingQueue.value = true
  try {
    const { data } = await bedahApi.antrian()
    const rows = data.data ?? []
    patients.value = rows.map(transformQueueItem)
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal memuat antrian bedah')
  } finally {
    loadingQueue.value = false
  }
}

// Pegawai untuk combobox Tim Bedah (operator/asisten/anestesi/dll)
async function loadEmployees() {
  try {
    const res = await masterApi.pegawai.list({ per_page: 200 })
    const list = res.data?.data?.data ?? res.data?.data ?? []
    employees.value = (Array.isArray(list) ? list : []).map((e) => ({
      id:       e.id,
      name:     e.name,
      role:     e.profession ?? e.user?.role?.name ?? '—',  // label tampil di dropdown
      roleName: (e.user?.role?.name ?? '').toLowerCase(),    // untuk filter per peran
      prof:     (e.profession ?? '').toLowerCase(),
    }))
  } catch (e) {
    employees.value = []
  }
}

onMounted(async () => {
  if (!masterStore.profilKlinik) {
    try { await masterStore.fetchProfilKlinik() } catch {}
  }
  await Promise.all([loadQueue(), loadEmployees()])
  startQueuePolling()
})

// ── UI State ───────────────────────────────────────────────────────────────────
const qPrimaryFilter   = ref('waiting')   // 'waiting' | 'done'
const qSecondaryFilter = ref('semua')      // 'semua' | 'bpjs' | 'umum'
const qSearch = ref('')
const selP = ref(null)
const tab = ref('prabedah')
const showMulaiModal = ref(false)
const showFinalModal = ref(false)
const busyOp = ref(false)          // lock tombol lifecycle (mulai/timeout/finalisasi)
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

// Polling queue 15s — sync antrian baru (mis. pasien baru dikirim ke bedah dari Perawat)
let _queuePollTimer = null
function startQueuePolling() {
  if (_queuePollTimer) return
  _queuePollTimer = setInterval(() => { loadQueue() }, 15000)
}
function stopQueuePolling() {
  if (_queuePollTimer) { clearInterval(_queuePollTimer); _queuePollTimer = null }
}

onUnmounted(() => {
  stopTimerInterval()
  stopQueuePolling()
})

watch(selP, (p) => {
  if (p && !p.tim.operator && p.dpjp) p.tim.operator = p.dpjp
  if (p && p.status === 'BERLANGSUNG' && p.timIn && !p.timOut) {
    startTimerInterval()
  } else {
    stopTimerInterval()
  }
  // Auto-load surgery requests untuk visit ini (jika ada)
  if (p?.visitId) {
    loadSurgeryRequests(p.visitId)
    loadEquipmentUsages(p.visitId)
  } else {
    surgeryReqs.value = []
    equipmentUsages.value = []
  }
})

// Load master equipment sekali di mount (untuk dropdown pilihan)
onMounted(() => {
  loadEquipmentMaster()
})

// ── Surgery Requests (BHP/IOL dari Farmasi) — used_qty per item ───────────────
const surgeryReqs = ref([])
const surgeryReqsLoading = ref(false)
const usedQtyEdits = ref({})  // { bhpRowId: number } — draft edits sebelum save
const adjustingReq = ref(null) // id request yang sedang di-save

async function loadSurgeryRequests(visitId) {
  surgeryReqsLoading.value = true
  try {
    const res = await bedahApi.listRequests({ visit_id: visitId, per_page: 50 })
    const list = res.data?.data?.data ?? res.data?.data ?? []
    surgeryReqs.value = Array.isArray(list) ? list : []
    // Seed draft = used_qty (atau quantity kalau used_qty masih null)
    usedQtyEdits.value = {}
    for (const req of surgeryReqs.value) {
      for (const row of (req.bhp_items ?? [])) {
        usedQtyEdits.value[row.id] = row.used_qty ?? row.quantity ?? 0
      }
    }
  } catch (e) {
    surgeryReqs.value = []
  } finally {
    surgeryReqsLoading.value = false
  }
}

async function saveBhpUsage(req) {
  if (!req?.id) return
  const items = (req.bhp_items ?? []).map((row) => ({
    bhp_item_id: row.bhp_item_id,
    used_qty: Math.max(0, Number(usedQtyEdits.value[row.id] ?? 0)),
  }))
  adjustingReq.value = req.id
  try {
    await bedahApi.adjustBhpUsage(req.id, items)
    toast('s', 'Pemakaian BHP tersimpan')
    if (selP.value?.visitId) await loadSurgeryRequests(selP.value.visitId)
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal menyimpan pemakaian BHP')
  } finally {
    adjustingReq.value = null
  }
}

const receivedSurgeryReqs = computed(() =>
  surgeryReqs.value.filter((r) => r.status === 'RECEIVED' && (r.bhp_items?.length ?? 0) > 0)
)

// ── Medical Equipment Usage (Fase 3) ──────────────────────────────────────
const equipmentList = ref([])  // master alat aktif (sumber pilihan)
const equipmentUsages = ref([])  // log usage utk visit ini
const equipmentLoading = ref(false)
const newEquipmentId = ref('')

async function loadEquipmentMaster() {
  try {
    const res = await alatMedisApi.list({ active: 1, per_page: 100 })
    const list = res.data?.data?.data ?? res.data?.data ?? []
    equipmentList.value = Array.isArray(list) ? list : []
  } catch (e) {
    equipmentList.value = []
  }
}

async function loadEquipmentUsages(visitId) {
  if (!visitId) { equipmentUsages.value = []; return }
  equipmentLoading.value = true
  try {
    const res = await alatMedisApi.usagesByVisit(visitId)
    equipmentUsages.value = Array.isArray(res.data?.data) ? res.data.data : []
  } catch (e) {
    equipmentUsages.value = []
  } finally {
    equipmentLoading.value = false
  }
}

async function addEquipmentUsage() {
  if (!newEquipmentId.value || !selP.value?.visitId) {
    toast('w', 'Pilih alat dulu')
    return
  }
  try {
    await alatMedisApi.recordUsage({
      medical_equipment_id: newEquipmentId.value,
      visit_id: selP.value.visitId,
      surgery_schedule_id: selP.value.scheduleId ?? null,
    })
    toast('s', 'Pemakaian alat dicatat')
    newEquipmentId.value = ''
    await loadEquipmentUsages(selP.value.visitId)
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal mencatat')
  }
}

async function removeEquipmentUsage(usage) {
  if (!confirm(`Hapus catatan pemakaian ${usage.equipment?.name}?`)) return
  try {
    await alatMedisApi.deleteUsage(usage.id)
    toast('s', 'Catatan dihapus')
    if (selP.value?.visitId) await loadEquipmentUsages(selP.value.visitId)
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal menghapus')
  }
}

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

// Jam jadwal operasi (kartu antrean + detail). scheduled_time bisa "HH:mm:ss" atau "HH:mm".
function fmtJamJadwal(t) {
  if (!t) return '—'
  return String(t).slice(0, 5)
}

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

async function pickPt(p) {
  if (selP.value?.id === p.id) return
  selP.value = p
  tab.value = 'prabedah'
  toast('i', `Membuka data bedah — ${p.name}`)

  // Hidrasi laporan dari backend bila operasi sudah dimulai/selesai (mis. setelah
  // reload): recordId/timIn/timOut untuk lifecycle + field klinis utk prefill.
  if (p.scheduleId && p.status !== 'MENUNGGU' && !p.recordId) {
    try {
      const { data } = await bedahApi.showRecord(p.scheduleId)
      const rec = data.data
      if (rec && selP.value?.id === p.id) {
        const s = selP.value
        s.recordId = rec.id
        s.timIn  = rec.time_in  ? new Date(rec.time_in)  : s.timIn
        s.timOut = rec.time_out ? new Date(rec.time_out) : s.timOut
        s.laporanFinalized = !!rec.finalized_at || s.laporanFinalized
        // Prefill field klinis hanya bila belum disentuh user di sesi ini.
        const notes = parseRecordNotes(rec.operation_notes)
        if (!s.teknikOp)     s.teknikOp     = notes.teknikOp
        if (!s.temuanIntra)  s.temuanIntra  = notes.temuanIntra
        if (!s.catatanIntra) s.catatanIntra = notes.catatanIntra
        s.komplikasi = !!rec.has_complication
        if (rec.complication_detail && !s.komplikasiNote) s.komplikasiNote = rec.complication_detail
      }
    } catch { /* record belum ada — abaikan */ }
  }

  if (selP.value?.status === 'BERLANGSUNG' && selP.value?.timIn && !selP.value?.timOut) startTimerInterval()
  else stopTimerInterval()
}

async function callPt(p, e) {
  e.stopPropagation()
  if (pendingCallIds.value.includes(p.id)) return
  const isRecall = p.status !== 'MENUNGGU'
  pendingCallIds.value.push(p.id)
  try {
    await bedahApi.panggilAntrian(p.id)
    toast('s', `${isRecall ? 'Memanggil ulang' : 'Memanggil'} ${p.qNum} — ${p.name} ke ${p.ruang}`)
    await loadQueue()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memanggil pasien')
  } finally {
    pendingCallIds.value = pendingCallIds.value.filter(id => id !== p.id)
  }
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

async function doMulaiOperasi() {
  if (!selP.value) return
  if (!selP.value.scheduleId) {
    toast('w', 'Operasi tanpa jadwal — tidak dapat dimulai dari sini')
    return
  }
  if (busyOp.value) return
  busyOp.value = true
  try {
    // Backend: schedule SCHEDULED→IN_PROGRESS + buat SurgeryRecord (time_in).
    // Guard supply BHP/IOL (belum RECEIVED) di-handle backend (422).
    const { data } = await bedahApi.mulaiOperasi(selP.value.scheduleId)
    selP.value.recordId = data.data?.id ?? null
    selP.value.status = 'BERLANGSUNG'
    selP.value.timIn = data.data?.time_in ? new Date(data.data.time_in) : new Date()
    selP.value.timOut = null
    tab.value = 'intraop'
    showMulaiModal.value = false
    startTimerInterval()
    toast('s', 'Operasi dimulai — Timer Time In berjalan')
    await loadQueue()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memulai operasi')
  } finally {
    busyOp.value = false
  }
}

// Penanda section dalam operation_notes (1 kolom DB) supaya 3 field UI
// (Teknik/Temuan/Catatan) bisa di-round-trip: buildRecordPayload menulis,
// parseRecordNotes membaca balik. Urutan = urutan tampil di UI.
const NOTE_SECTIONS = [
  { key: 'teknikOp',     label: 'Teknik Operasi' },
  { key: 'temuanIntra',  label: 'Temuan Intraoperatif' },
  { key: 'catatanIntra', label: 'Catatan Intraoperatif' },
]

// Payload laporan operasi dari state lokal selP (dipakai Time Out + update record).
function buildRecordPayload() {
  const p = selP.value
  const notes = NOTE_SECTIONS
    .filter(s => (p[s.key] || '').trim())
    .map(s => `[${s.label}]\n${p[s.key].trim()}`)
    .join('\n\n')
  return {
    operation_notes:      notes || null,
    has_complication:     !!p.komplikasi,
    complication_detail:  p.komplikasi ? (p.komplikasiNote || p.komplikasiTipe || null) : null,
    post_op_instructions: null,
    followup_date:        null,
  }
}

// Pecah operation_notes berlabel balik ke 3 field. Tanpa label (data lama / dari
// sumber lain) → seluruh teks masuk Teknik Operasi agar tak hilang.
function parseRecordNotes(text) {
  const out = { teknikOp: '', temuanIntra: '', catatanIntra: '' }
  if (!text) return out
  const labels = NOTE_SECTIONS.map(s => s.label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
  const re = new RegExp(`\\[(${labels.join('|')})\\]\\n?`, 'g')
  if (!re.test(text)) { out.teknikOp = text.trim(); return out }
  // Split sambil pertahankan label penanda lalu pasangkan ke field-nya.
  const parts = text.split(new RegExp(`\\[(${labels.join('|')})\\]\\n?`)).filter(s => s !== undefined)
  for (let i = 1; i < parts.length; i += 2) {
    const sec = NOTE_SECTIONS.find(s => s.label === parts[i])
    if (sec) out[sec.key] = (parts[i + 1] || '').trim()
  }
  return out
}

async function doTimeOut() {
  if (!selP.value) return
  if (!selP.value.scheduleId) {
    toast('w', 'Operasi tanpa jadwal — tidak dapat di-Time Out dari sini')
    return
  }
  if (busyOp.value) return
  busyOp.value = true
  try {
    // Backend: schedule IN_PROGRESS→DONE + isi laporan. TIDAK meneruskan pasien
    // (advance dipindah ke finalisasi). Pasien jalan setelah laporan dikunci.
    const { data } = await bedahApi.selesaiOperasi(selP.value.scheduleId, buildRecordPayload())
    selP.value.recordId = data.data?.id ?? selP.value.recordId
    selP.value.timOut = data.data?.time_out ? new Date(data.data.time_out) : new Date()
    stopTimerInterval()
    toast('s', `Time Out: ${timOutDisplay.value} — kunci laporan untuk meneruskan pasien`)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menyelesaikan operasi')
  } finally {
    busyOp.value = false
  }
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

async function doFinalisasi() {
  if (!selP.value) return
  if (!selP.value.scheduleId) {
    toast('w', 'Operasi tanpa jadwal — tidak dapat difinalisasi dari sini')
    return
  }
  if (busyOp.value) return
  busyOp.value = true
  try {
    // Resolve record.id (dari mulai/timeout, atau ambil ulang via scheduleId).
    let recordId = selP.value.recordId
    if (!recordId) {
      const { data } = await bedahApi.showRecord(selP.value.scheduleId)
      recordId = data.data?.id ?? null
    }
    if (!recordId) {
      toast('w', 'Laporan operasi belum ada — mulai & Time Out operasi dulu')
      return
    }
    // Backend: kunci laporan (finalized_at) + advance antrean ke Farmasi/Kasir.
    await bedahApi.finalizeRecord(recordId)
    selP.value.laporanFinalized = true
    selP.value.status = 'SELESAI'
    selP.value.timOut = selP.value.timOut || new Date()
    stopTimerInterval()
    showFinalModal.value = false
    toast('s', 'Laporan dikunci — pasien diteruskan ke Farmasi/Kasir')
    await loadQueue()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal memfinalisasi laporan')
  } finally {
    busyOp.value = false
  }
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
// Peran yang valid per field Tim Bedah (cocokkan ke users.role.name):
//   Operator (DPJP)  → dokter
//   Asisten 1/2      → perawat
//   Scrub/Circulating Nurse → perawat
//   Anestesiologis   → dokter anestesi (fallback: dokter mana pun, karena belum ada role khusus)
function employeeMatchesRole(e, key) {
  switch (key) {
    case 'operator':
      return e.roleName === 'dokter'
    case 'asisten1':
    case 'asisten2':
    case 'scrubNurse':
    case 'circNurse':
      return e.roleName === 'perawat'
    case 'anestesi':
      // dokter dengan profesi anestesi; kalau tak ada penanda, terima semua dokter
      return e.roleName === 'dokter' && (e.prof.includes('anestesi') || !employees.value.some(x => x.roleName === 'dokter' && x.prof.includes('anestesi')))
    default:
      return true
  }
}
function filteredEmployees(key) {
  const q = timSearch.value[key].toLowerCase()
  return employees.value.filter(e =>
    employeeMatchesRole(e, key) && (!q || e.name.toLowerCase().includes(q))
  )
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
  <div class="bedah">
    <div class="main-grid">

      <!-- ══════════════════ LEFT: QUEUE ══════════════════ -->
      <aside class="col-queue">
        <div class="card">
          <div class="card-head">
            <div>
              <div class="card-head-title">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"/></svg>
                Antrean Bedah
              </div>
              <div class="card-head-sub">{{ patients.length }} pasien hari ini</div>
            </div>
            <span class="pill-live">LIVE</span>
          </div>

          <div class="card-body queue-scroll" role="region" aria-label="Daftar antrean bedah">

            <!-- Stats bar -->
            <div class="stats-bar">
              <div class="stat-item">
                <span class="stat-label">Menunggu</span>
                <b class="stat-num stat-waiting">{{ cMenunggu }}</b>
              </div>
              <div class="stat-divider"></div>
              <div class="stat-item">
                <span class="stat-label">Berlangsung</span>
                <b class="stat-num stat-live">{{ cBerlangsung }}</b>
              </div>
              <div class="stat-divider"></div>
              <div class="stat-item">
                <span class="stat-label">Selesai</span>
                <b class="stat-num stat-done">{{ cSelesai }}</b>
              </div>
            </div>

            <!-- Primary filter -->
            <div class="primary-filter" role="group" aria-label="Filter utama antrean">
              <button
                :class="['pf-btn', qPrimaryFilter === 'waiting' ? 'a' : '']"
                @click="qPrimaryFilter = 'waiting'"
              >
                Belum Dipanggil
                <span v-if="belumDipanggilCount" class="pf-ct">{{ belumDipanggilCount }}</span>
              </button>
              <button
                :class="['pf-btn', qPrimaryFilter === 'done' ? 'a' : '']"
                @click="qPrimaryFilter = 'done'"
              >
                Selesai
                <span v-if="cSelesai" class="pf-ct">{{ cSelesai }}</span>
              </button>
            </div>

            <!-- Secondary filter -->
            <div class="ptype-tabs" role="group" aria-label="Filter jenis penjamin">
              <button :class="['ptype-tab', qSecondaryFilter === 'semua' ? 'a' : '']" @click="qSecondaryFilter = 'semua'">Semua</button>
              <button :class="['ptype-tab ptype-bpjs', qSecondaryFilter === 'bpjs'  ? 'a' : '']" @click="qSecondaryFilter = 'bpjs'">BPJS</button>
              <button :class="['ptype-tab ptype-umum', qSecondaryFilter === 'umum'  ? 'a' : '']" @click="qSecondaryFilter = 'umum'">Umum/Asuransi</button>
            </div>

            <!-- Search -->
            <div class="q-search-wrap">
              <input v-model="qSearch" class="q-search" placeholder="Cari nama / nomor OK / RM…" />
            </div>

            <!-- Empty -->
            <div v-if="!filtQ.length" class="empty-section" aria-live="polite">
              Tidak ada pasien dalam filter ini
            </div>

            <!-- Queue list -->
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
                    <svg v-if="p.status === 'MENUNGGU'" viewBox="0 0 24 24" class="pill-icon"><path d="M5 2h14M5 22h14M6 2v5l4 5-4 5v5M18 2v5l-4 5 4 5v5"/></svg>
                    <svg v-else-if="p.status === 'BERLANGSUNG'" viewBox="0 0 24 24" class="pill-icon"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                    <svg v-else viewBox="0 0 24 24" class="pill-icon"><polyline points="20 6 9 17 4 12"/></svg>
                    {{ p.status === 'MENUNGGU' ? 'Menunggu' : p.status === 'BERLANGSUNG' ? 'Proses' : 'Selesai' }}
                  </span>
                </div>

                <div class="q-info">
                  <div class="q-name">{{ p.name }}</div>
                  <div class="q-meta">{{ p.age }} th · {{ p.gender }} · {{ p.rm }}</div>
                  <div class="q-prosedur">{{ p.prosedur }}</div>
                  <div class="q-tags">
                    <span v-if="p.visitType === 'PREOP_BEDAH'" class="pill pill-preop" title="Preop bedah — bypass dokter">PREOP</span>
                    <span :class="['pill', p.ptype === 'bpjs' ? 'pill-bpjs' : 'pill-umum']">
                      {{ p.ptype.toUpperCase() }}
                    </span>
                    <span v-if="p.classification" :class="['pill', clsCls(p.classification)]">{{ p.classification }}</span>
                    <span class="pill pill-ruang">{{ p.ruang }}</span>
                    <span v-if="p.scheduledTime" class="pill pill-time">{{ fmtJamJadwal(p.scheduledTime) }}</span>
                    <span v-else class="pill pill-time pill-time-na">Jam belum diatur</span>
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
        </div>
      </aside>

      <!-- ══════════════════ RIGHT: WORK AREA ══════════════════ -->
      <section class="col-work">
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
              {{ selP.rm }} &middot; {{ selP.age }} thn &middot; {{ selP.gender }}
            </div>
            <div class="bd-banner-prosedur">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 19 7-7 3 3-7 7-3-3z"/><path d="m18 13-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="m2 2 7.586 7.586"/><circle cx="11" cy="11" r="2"/></svg>
              {{ selP.prosedur }}
              <span class="bd-icd">{{ selP.icdProsedur }}</span>
            </div>
            <div class="bd-banner-dpjp">DPJP: {{ selP.dpjp }}</div>
          </div>
          <div class="bd-banner-right">
            <span v-if="selP.visitType === 'PREOP_BEDAH'" class="pill pill-preop" style="align-self:flex-end">PREOP BEDAH</span>
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
                    <div v-if="operatingRooms.length" class="bd-radios">
                      <label v-for="r in operatingRooms" :key="r" class="bd-radio-lbl">
                        <input type="radio" :value="r" v-model="selP.ruang" :disabled="selP.laporanFinalized" />
                        {{ r }}
                      </label>
                    </div>
                    <span v-else class="bd-val bd-val-na">Belum ada ruang OK — atur di Profil Klinik</span>
                  </div>
                  <div class="bd-field-row">
                    <label class="bd-label">Jadwal Operasi</label>
                    <span v-if="selP.scheduledTime" class="bd-val">{{ fmtJamJadwal(selP.scheduledTime) }} WIB</span>
                    <span v-else class="bd-val bd-val-na">Jam belum ditentukan dokter</span>
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
            <div class="bd-card bd-card-full bd-card-combo">
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
                    :disabled="busyOp"
                    @click="doTimeOut"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="4" height="16" x="6" y="4"/><rect width="4" height="16" x="14" y="4"/></svg>
                    {{ busyOp ? 'Memproses…' : 'Time Out' }}
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
                        <td><button class="bd-del" @click="removeBhp(i)" :disabled="selP.laporanFinalized" aria-label="Hapus BHP" title="Hapus BHP">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button></td>
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

            <!-- BHP dari Farmasi (qty terpakai → masuk billing) -->
            <div v-if="receivedSurgeryReqs.length" class="bd-card bd-card-full">
              <div class="bd-card-hd">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
                Pemakaian BHP (dari Farmasi)
                <span class="bd-paket-source-pill">Akan ditagihkan otomatis di Kasir</span>
              </div>
              <div class="bd-card-bd">
                <div v-for="req in receivedSurgeryReqs" :key="req.id" class="bd-bhp-req">
                  <div class="bd-bhp-req-hd">
                    <strong>Request {{ req.id?.slice(0, 8) }}</strong>
                    <span class="bd-bhp-req-meta">Diterima {{ req.received_at ? new Date(req.received_at).toLocaleString('id-ID') : '—' }}</span>
                  </div>
                  <table class="bd-tbl">
                    <thead>
                      <tr>
                        <th>Item</th>
                        <th>Kategori</th>
                        <th class="num">Diminta</th>
                        <th class="num">Terpakai</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="row in (req.bhp_items ?? [])" :key="row.id">
                        <td>{{ row.bhp_item?.name ?? '—' }}</td>
                        <td>
                          <span v-if="row.bhp_item?.category" class="bd-cat-pill" :data-cat="row.bhp_item.category">
                            {{ row.bhp_item.category }}
                          </span>
                          <span v-else>—</span>
                        </td>
                        <td class="num">{{ row.quantity }}</td>
                        <td class="num">
                          <input
                            type="number"
                            min="0"
                            :max="row.quantity * 2"
                            class="bd-input bd-input-sm"
                            style="width:70px;text-align:right"
                            v-model.number="usedQtyEdits[row.id]"
                            :disabled="selP.laporanFinalized || adjustingReq === req.id"
                          />
                        </td>
                      </tr>
                    </tbody>
                  </table>
                  <div class="bd-bhp-req-foot">
                    <button
                      class="bd-btn-add"
                      :disabled="selP.laporanFinalized || adjustingReq === req.id"
                      @click="saveBhpUsage(req)"
                    >
                      {{ adjustingReq === req.id ? 'Menyimpan…' : 'Simpan pemakaian' }}
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Pemakaian Alat Medis (microscope, Phaco, dll) -->
            <div class="bd-card bd-card-full">
              <div class="bd-card-hd">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="12" rx="2"/><circle cx="9" cy="10" r="2"/><line x1="13" y1="9" x2="19" y2="9"/><line x1="13" y1="13" x2="17" y2="13"/></svg>
                Pemakaian Alat Medis
                <span class="bd-paket-source-pill">Tarif flat per pemakaian</span>
              </div>
              <div class="bd-card-bd">
                <table class="bd-tbl" v-if="equipmentUsages.length">
                  <thead><tr><th>Alat</th><th>Kategori</th><th>Lokasi</th><th>Waktu</th><th></th></tr></thead>
                  <tbody>
                    <tr v-for="u in equipmentUsages" :key="u.id">
                      <td><strong>{{ u.equipment?.name ?? '—' }}</strong> <span v-if="u.equipment?.brand" class="bd-tbl-muted">({{ u.equipment.brand }})</span></td>
                      <td><span v-if="u.equipment?.category" class="bd-cat-pill" :data-cat="u.equipment.category">{{ u.equipment.category }}</span></td>
                      <td>{{ u.equipment?.location ?? '—' }}</td>
                      <td>{{ u.used_at ? new Date(u.used_at).toLocaleString('id-ID') : '—' }}</td>
                      <td>
                        <button class="bd-del" @click="removeEquipmentUsage(u)" :disabled="selP.laporanFinalized" aria-label="Hapus pemakaian alat" title="Hapus pemakaian alat">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
                <div v-else-if="equipmentLoading" class="bd-tbl-empty">Memuat…</div>
                <div v-else class="bd-tbl-empty">Belum ada alat dicatat</div>

                <div v-if="!selP.laporanFinalized" class="bd-bhp-add" style="margin-top:10px">
                  <select class="bd-select bd-select-sm" v-model="newEquipmentId" style="min-width:280px">
                    <option value="">-- Pilih Alat Medis --</option>
                    <option
                      v-for="eq in equipmentList"
                      :key="eq.id"
                      :value="eq.id"
                      :disabled="equipmentUsages.some((u) => u.medical_equipment_id === eq.id)"
                    >
                      {{ eq.code }} · {{ eq.name }}{{ eq.location ? ` (${eq.location})` : '' }}
                    </option>
                  </select>
                  <button class="bd-btn-add" @click="addEquipmentUsage">+ Catat Pemakaian</button>
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
                  <div class="bd-field-row"><label class="bd-label">Operator</label><span class="bd-val">{{ selP.tim.operator || selP.dpjp || '—' }}</span></div>
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
                    <div class="bd-dx-chip" :class="!selP.diagnosa && 'bd-dx-chip-empty'">{{ selP.diagnosa || 'Belum ada diagnosis dari dokter' }}</div>
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
                :disabled="!selP.timOut || busyOp"
                :title="!selP.timOut ? 'Lakukan Time Out dulu di tab Intraoperatif' : ''"
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
                        <td><button class="bd-del" @click="removeObat(i)" :disabled="selP.resepSent" aria-label="Hapus obat" title="Hapus obat">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button></td>
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
      </section>
    </div>

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
        <p>Laporan akan dikunci dan tidak dapat diubah. Pasien diteruskan ke <strong>Farmasi/Kasir</strong>.</p>
        <div class="bd-modal-actions">
          <button class="bd-btn-sec" :disabled="busyOp" @click="showFinalModal = false">Batal</button>
          <button class="bd-btn-finalisasi-confirm" :disabled="busyOp" @click="doFinalisasi">{{ busyOp ? 'Memproses…' : 'Finalisasi' }}</button>
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
/* ── Layout (PerawatView style) ─────────────────────────────────── */
.bedah { padding: 0; }
.main-grid { display: grid; grid-template-columns: 340px 1fr; gap: 1rem; align-items: start; }

.col-queue { min-width: 0; }
.col-work { min-width: 0; display: flex; flex-direction: column; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }

/* Card wrapper */
.card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }
.card-head { padding: 0.85rem 1.1rem; border-bottom: 1px solid var(--gb); display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.card-head-title { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--td); }
.card-head-title svg { width: 14px; height: 14px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.card-head-sub { font-size: 11px; color: var(--tu); margin-top: 3px; }

.queue-scroll { padding: 0.6rem; max-height: calc(100vh - 200px); overflow-y: auto; }

/* Live pill on card-head */
.pill-live { font-size: 9.5px; font-weight: 700; padding: 2px 8px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 20px; letter-spacing: 0.05em; }

/* Stats bar */
.stats-bar { display: flex; align-items: center; background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 8px 12px; margin-bottom: 0.65rem; gap: 0; }
.stat-item { flex: 1; text-align: center; }
.stat-divider { width: 1px; height: 28px; background: var(--gb); flex-shrink: 0; }
.stat-label { display: block; font-size: 9.5px; color: var(--tu); letter-spacing: 0.03em; margin-bottom: 2px; }
.stat-num { display: block; font-size: 17px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; }
.stat-waiting { color: #d97706; }
.stat-live { color: #1e40af; }
.stat-done { color: var(--st); }

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
.qi-left { display: flex; flex-direction: column; gap: 4px; min-width: 56px; }
.q-num { font-weight: 700; font-size: 13.5px; color: var(--ga); letter-spacing: 0.03em; }
.q-info { flex: 1; min-width: 0; }
.q-name { font-size: 12.5px; font-weight: 500; color: var(--td); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.q-meta { font-size: 10px; color: var(--tu); margin-top: 2px; }
.q-prosedur { font-size: 11px; color: var(--gm); margin-top: 3px; font-weight: 500; }
.q-tags { display: flex; gap: 3px; margin-top: 3px; flex-wrap: wrap; }

/* Pill icon (PerawatView style) */
.pill-icon { width: 8px; height: 8px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; flex-shrink: 0; }

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
.pill-preop     { background: #fef3c7; color: #92400e; border: 1px solid #fbbf24; font-weight: 700; }
.pill-ruang     { background: var(--bc); color: var(--gm); border: 1px solid var(--gb); }
.pill-time      { background: var(--bi); color: var(--tu); font-variant-numeric: tabular-nums; }
.pill-time-na   { background: #fff4e5; color: #9a6700; font-style: italic; }

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
.bd-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 60px 20px; color: var(--th); }
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

/* padding-bottom besar: beri ruang dropdown combobox di card terakhir (Tim Bedah) agar tak terpotong */
.bd-tabcont { max-height: calc(100vh - 260px); overflow-y: auto; padding: 20px 20px 220px; }

/* Cards */
.bd-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }
.bd-card-full { margin-top: 16px; }
/* card berisi combobox: jangan clip dropdown absolute (overflow hidden default memotongnya) */
.bd-card-combo, .bd-card-combo .bd-card-bd { overflow: visible; }
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
.bd-val-na { color: var(--th); font-style: italic; }
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

/* BHP dari Farmasi section */
.bd-bhp-req { background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; }
.bd-bhp-req:last-child { margin-bottom: 0; }
.bd-bhp-req-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; font-size: 12.5px; color: var(--td); }
.bd-bhp-req-meta { font-size: 11px; color: var(--tu); font-weight: 400; }
.bd-bhp-req-foot { display: flex; justify-content: flex-end; margin-top: 8px; }
.bd-tbl .num { text-align: right; }
.bd-cat-pill { display: inline-block; padding: 2px 7px; border-radius: 5px; font-size: 10px; font-weight: 600; background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.bd-cat-pill[data-cat="CSSD"]             { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
.bd-cat-pill[data-cat="INSTRUMENT_SET"]   { background: #ede9fe; color: #5b21b6; border-color: #c4b5fd; }
.bd-cat-pill[data-cat="MEDICAL_SUPPLIES"] { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
.bd-cat-pill[data-cat="MEDICAL_BHP"]      { background: #d1fae5; color: #065f46; border-color: #6ee7b7; }
.bd-cat-pill[data-cat="MICROSCOPE"]       { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
.bd-cat-pill[data-cat="PHACO_MACHINE"]    { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
.bd-cat-pill[data-cat="BIOMETRY"]         { background: #ede9fe; color: #5b21b6; border-color: #c4b5fd; }
.bd-cat-pill[data-cat="AUTOREFRACTOR"]    { background: #d1fae5; color: #065f46; border-color: #6ee7b7; }
.bd-tbl-muted { color: var(--tu); font-size: 11px; font-weight: 400; }

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
  text-shadow: 0 0 30px rgba(56,189,248,.4);
}
.bd-pulse-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--lm); animation: bd-blink 1s infinite; }
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
.bd-del { display: inline-flex; align-items: center; justify-content: center; background: none; border: none; color: var(--et); cursor: pointer; padding: 4px; border-radius: 4px; }
.bd-del svg { width: 15px; height: 15px; }
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
.bd-dx-chip-empty { background: #f3f4f6; color: #9ca3af; font-weight: 500; font-style: italic; }
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
