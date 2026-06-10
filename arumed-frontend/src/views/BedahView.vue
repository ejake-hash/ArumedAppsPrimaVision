<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue'
import { useMasterDataStore } from '@/stores/masterDataStore'
import { useAuthStore } from '@/stores/authStore'
import { bedahApi, masterApi, dokterApi } from '@/services/api'
import ScanBarcodeModal from '@/components/common/ScanBarcodeModal.vue'
import AnesthesiaReportWizard from '@/components/bedah/AnesthesiaReportWizard.vue'
import FormDocsBrowser from '@/components/forms/FormDocsBrowser.vue'

const masterStore = useMasterDataStore()
const auth = useAuthStore()

// Deteksi prosedur vitrektomi (tanpa IOL) → tampilkan section khusus retina di laporan.
const VITREK_RE = /vitrek|vitrec|vitreous|ppv\b|pars plana/i

// ── UI adaptif per-role (editable matrix) ────────────────────────────────────
// Checklist keselamatan: perawat + dokter (permission bedah.checklist).
const canEditChecklist = computed(() => auth.can('bedah.checklist'))
// Laporan operasi / diagnosa / disposisi / resep: dokter (bedah.write).
const canEditReport    = computed(() => auth.can('bedah.write'))
// Wizard + monitoring anestesi: dokter anestesi (anestesi.write).
const canEditAnesthesia = computed(() => auth.can('anestesi.write'))

// Deteksi prosedur Phaco/SICS (IOL) dari nama paket/prosedur → auto-set isPhaco.
// Petugas tetap bisa override manual via checkbox di tab Pra-Bedah.
const IOL_RE = /phaco|katarak|cataract|\biol\b|sics|lensa intraokular/i

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
  // Auto-deteksi prosedur IOL (Phaco/SICS) dari nama paket dokter / prosedur.
  const prosedur = pkg?.name ?? 'Tindakan bedah'
  const isIol    = IOL_RE.test(pkg?.name ?? prosedur)
  return {
    // ── Real data ──
    id:             q.id,
    createdAt:      q.created_at,
    qNum:           q.queue_number,
    queueStatus:    q.status,            // WAITING/CALLED/IN_PROGRESS/COMPLETED
    visitId:        q.visit?.id,
    visitType:      q.visit?.visit_type,
    jenisPelayanan: q.visit?.jenis_pelayanan ?? 'RAJAL',   // RANAP = pasien dari kamar
    scheduleId:     sched?.id ?? null,
    classification: q.visit?.classification ?? 'Pre-Op',
    rm:             q.patient?.no_rm ?? '—',
    name:           q.patient?.name ?? '—',
    age:            q.patient?.age ?? '—',
    gender:         q.patient?.gender === 'L' ? 'Laki-laki' : (q.patient?.gender === 'P' ? 'Perempuan' : '—'),
    ptype:          q.visit?.guarantor_type === 'BPJS' ? 'bpjs' : 'umum',
    ruang:          sched?.operation_room ?? '—',
    _schedRoom:     sched?.operation_room ?? '—',    // baseline ruang dr jadwal (deteksi "disentuh" saat poll)
    scheduledTime:  sched?.scheduled_time ?? null,   // null = jam belum ditentukan dokter (opsional)
    scheduledDate:  sched?.scheduled_date,
    paketBedah:     pkg ? { kode: (pkg.id || '').slice(0, 6), nama: pkg.name } : null,
    prosedur,

    // Visus/IOP pre-op (backend kirim di key top-level `preop` dari RefractionRecord;
    // null = sembunyikan baris di modal Mulai Operasi).
    visusOD:        q.preop?.visus_od ?? null,
    visusOS:        q.preop?.visus_os ?? null,
    iopOD:          q.preop?.iop_od ?? null,
    iopOS:          q.preop?.iop_os ?? null,

    // ── UI-mock defaults (action operasi belum diwire) ──
    status:         q.status === 'COMPLETED' ? 'SELESAI'
                  : q.status === 'IN_PROGRESS' ? 'BERLANGSUNG'
                  : 'MENUNGGU',
    icdProsedur:    '',
    dpjp:           q.visit?.dpjp ?? '',          // operator utama (lead surgeon / dokter pemeriksa)
    diagnosa:       q.visit?.diagnosa ?? '',      // kode ICD-10 diagnosis utama dari dokter
    diagnosaNama:   q.visit?.diagnosa_nama ?? '', // nama/deskripsi ICD-10 (resolusi backend)
    diagnosaPasca:  '',
    isPhaco:        isIol,          // auto-deteksi IOL; petugas dpt override via checkbox Pra-Bedah
    // Pemicu RM 10.1 (Laporan Operasi Vitreo Retina): surgery_type paket = sumber
    // kebenaran; bila belum diset, fallback deteksi nama (VITREK_RE). Override manual
    // via checkbox di tab Laporan (selaras pola isPhaco). Terjaga dari reset polling.
    surgeryType:    pkg?.surgery_type ?? null,
    isVitreoretina: (pkg?.surgery_type === 'VITREORETINA') || (!pkg?.surgery_type && VITREK_RE.test(pkg?.name ?? prosedur)),
    // Pemicu RM 2.3 Catatan Operasi (katarak): surgery_type=KATARAK atau fallback IOL_RE.
    isKatarak:      (pkg?.surgery_type === 'KATARAK') || (!pkg?.surgery_type && IOL_RE.test(pkg?.name ?? prosedur)),
    recordId:       null,           // surgery_records.id (diisi saat mulai/timeout/pick)
    timIn:          null,
    timOut:         null,
    checklist:      { identitas: false, consent: false, lokasi: false, pupil: false, alergi: false },
    // WHO Surgical Safety Checklist 3 fase (Sign In / Time Out / Sign Out) + bypass.
    signIn:         { identitas: false, sisi_mata: '', consent: false, anestesi_siap: false, alergi_dikonfirmasi: false },
    timeOut:        { tim_lengkap: false, identitas_prosedur: false, sisi_mata: false, antibiotik: false, iol_benar: false },
    signOut:        { prosedur_dikonfirmasi: false, hitung_kasa: false, hitung_instrumen: false, spesimen: false, iol_dicatat: false, rencana_pemulihan: false },
    signInSaved:    false,
    timeOutSaved:   false,
    signOutSaved:   false,
    bypass:         {},   // { sign_in?, time_out?, sign_out? } reason tercatat
    // Skor pemulihan Aldrete (PACU) — 5 komponen ×0-2.
    aldrete:        { activity: 2, respiration: 2, circulation: 2, consciousness: 2, spo2: 2 },
    painScore:      0,
    recoverySaved:  false,
    // Detail vitrektomi (hanya bila prosedur vitrektomi).
    vitrek:         { tamponade: 'None', endolaser: false, membrane_peeling: false },
    estimatedBloodLoss: '',
    tim:            { operator: '', asisten1: '', asisten2: '', scrubNurse: '', circNurse: '', anestesi: '' },
    bhp:            [],
    iolRencana:     { merk: '', power: '', series: '', tipe: 'Monofocal' },
    iolDipasang:    { itemId: '', merk: '', power: '', series: '', tipe: 'Monofocal', eyeSide: 'OD', lot: '', serial: '', gtin: '', gs1_barcode: '', expiry: '' },
    iolUsageSaved:  false,          // true setelah storeIolUsage sukses (badge info)
    anestesi:       'Topikal',
    catatanIntra:   '',
    teknikOp:       '',
    temuanIntra:    '',
    komplikasi:     false,
    komplikasiTipe: '',
    komplikasiNote: '',
    // Default disposisi adaptif: pasien dari RANAP → kembali ke kamar (LANJUT_RANAP);
    // pasien rawat jalan/pre-op → PULANG. Opsi lengkap difilter di template.
    postOpDisposition: (q.visit?.jenis_pelayanan ?? 'RAJAL') === 'RANAP' ? 'LANJUT_RANAP' : 'PULANG',
    instruksi:      Array(6).fill(false),
    obatPasca:      [],
    resepSent:      false,
    // operationDone = operasi selesai & pasien diteruskan (sembunyikan tombol Selesai).
    // laporanFinalized = laporan TERKUNCI read-only (hanya dari finalized_at/legacy);
    // "Selesai Operasi" TIDAK mengunci — laporan tetap editable, dikunci saat TTD dokumen.
    operationDone:    q.status === 'COMPLETED',
    laporanFinalized: false,
  }
}

// Sinkron field authoritative dari server ke objek working-state lokal TANPA
// menimpa input lokal (checklist/tim/bhp/iol/anestesi/instruksi/diagnosa pasca/
// komplikasi/teknik/temuan/catatan/recordId/timIn-out/isPhaco). Dipakai utk SEMUA
// baris yg sudah ada di patients.value, bukan hanya selP — biar draft tak hilang
// tiap poll 15s / tiap callPt.
function syncAuthoritativeFields(s, q) {
  const sched = q.surgery_schedule
  s.queueStatus = q.status                       // WAITING/CALLED/IN_PROGRESS/COMPLETED
  // Sekali COMPLETED tetap done (one-way) → sembunyikan tombol "Selesai Operasi"
  // walau penyelesaian dilakukan aktor lain. Tak menyentuh laporanFinalized (lock
  // dari finalized_at saja; alur bedah baru sengaja tak pernah mengunci di sini).
  if (q.status === 'COMPLETED') s.operationDone = true
  // jenis_pelayanan = server-authoritative (bisa berubah saat admit RANAP) → sinkron.
  if (q.visit?.jenis_pelayanan) s.jenisPelayanan = q.visit.jenis_pelayanan
  // Diagnosa (kode + nama) = data dokter, server-authoritative (dokter bisa
  // melengkapi/ubah setelah pasien masuk antrean bedah) → aman disinkron tiap poll.
  if (q.visit?.diagnosa !== undefined) s.diagnosa = q.visit.diagnosa ?? ''
  if (q.visit?.diagnosa_nama !== undefined) s.diagnosaNama = q.visit.diagnosa_nama ?? ''
  // Jangan downgrade status lokal: hanya naikkan dari MENUNGGU mengikuti server.
  if (s.status === 'MENUNGGU') {
    s.status = q.status === 'COMPLETED' ? 'SELESAI'
             : q.status === 'IN_PROGRESS' ? 'BERLANGSUNG'
             : 'MENUNGGU'
  }
  // Jadwal dari dokter (ruang/jam/tanggal): ruang hanya disinkron bila petugas
  // belum menyentuhnya (masih sama dgn jadwal lama atau placeholder '—').
  if (sched?.scheduled_time !== undefined) s.scheduledTime = sched?.scheduled_time ?? null
  if (sched?.scheduled_date !== undefined) s.scheduledDate = sched?.scheduled_date
  const schedRoom = sched?.operation_room ?? '—'
  if (s.ruang === '—' || s.ruang === s._schedRoom) s.ruang = schedRoom
  s._schedRoom = schedRoom
  // Visus/IOP pre-op = data server (RefractionRecord), bukan draft petugas → aman
  // disinkron tiap poll (mis. setelah refraksionis melengkapi).
  if (q.preop) {
    s.visusOD = q.preop.visus_od ?? null
    s.visusOS = q.preop.visus_os ?? null
    s.iopOD   = q.preop.iop_od ?? null
    s.iopOS   = q.preop.iop_os ?? null
  }
  return s
}

async function loadQueue() {
  loadingQueue.value = true
  try {
    const { data } = await bedahApi.antrian()
    const rows = data.data ?? []
    // Pertahankan working-state SEMUA pasien yg sudah ada (bukan cuma selP):
    // polling tidak boleh me-reset checklist/tim/BHP/IOL/recordId/timIn ke default.
    // Bangun Map id→objek lama; row server yg sudah ada → re-use objek lama (hanya
    // sinkron field authoritative); hanya row BENAR-BENAR baru di-transform.
    const prevById = new Map(patients.value.map((p) => [p.id, p]))
    const mapped = rows.map((q) => {
      const old = prevById.get(q.id)
      if (old) return syncAuthoritativeFields(old, q)
      return transformQueueItem(q)
    })
    // Pertahankan urutan tampilan lokal (mis. hasil skipPt) lintas-poll: baris yg
    // sudah tampil ikut urutan lama, baris baru dari server ditambahkan di akhir.
    const prevOrder = patients.value.map((p) => p.id)
    const byId = new Map(mapped.map((p) => [p.id, p]))
    const ordered = []
    for (const id of prevOrder) { if (byId.has(id)) { ordered.push(byId.get(id)); byId.delete(id) } }
    for (const p of byId.values()) ordered.push(p)
    patients.value = ordered
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

// ── Panel antrean collapsible (lega & dinamis, pola DokterView/Refraksionis) ──
const QCKEY = 'bedah.queueCollapsed'
const queueCollapsed = ref(localStorage.getItem(QCKEY) === '1')
function toggleQueue() {
  queueCollapsed.value = !queueCollapsed.value
  localStorage.setItem(QCKEY, queueCollapsed.value ? '1' : '0')
}
// Default ciut di layar sempit bila pengguna belum pernah menyetel preferensi.
if (localStorage.getItem(QCKEY) === null && typeof window !== 'undefined'
    && window.matchMedia('(max-width: 1400px)').matches) {
  queueCollapsed.value = true
}
const showMulaiModal = ref(false)
const showFinalModal = ref(false)
const showRmDocsModal = ref(false)  // dokumen RM (Checklist / Laporan) — dibuka dari tombol pojok tab
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
    loadVisitPackage(p.visitId)
    loadVpPickerOptions(p.visitId)
  } else {
    surgeryReqs.value = []
    visitPackages.value = []
    vpAddPkgId.value = ''
    vpAddTariffId.value = ''
  }
})

// Load master sekali di mount (dropdown pilihan: IOL, obat pasca-bedah)
onMounted(() => {
  loadIolMaster()
  loadObatMaster()
  loadPaketObat()
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

// (Pemakaian Alat Medis dihapus — tarif tidak ditagih dari log pemakaian.)

// ── IOL Master (untuk pilih lensa terpasang dari katalog) ─────────────────────
const iolMaster = ref([])         // master IOL aktif & tersedia
const iolSearch = ref('')          // query filter dropdown master IOL
const savingIol = ref(false)       // guard cegah double simpan IOL terpasang

async function loadIolMaster() {
  try {
    const res = await bedahApi.listIol({ active: 1, per_page: 200 })
    const list = res.data?.data?.data ?? res.data?.data ?? []
    iolMaster.value = Array.isArray(list) ? list : []
  } catch (e) {
    iolMaster.value = []
  }
}

const filteredIol = computed(() => {
  const q = iolSearch.value.trim().toLowerCase()
  if (!q) return iolMaster.value
  return iolMaster.value.filter((it) =>
    `${it.brand ?? ''} ${it.model ?? ''} ${it.manufacturer ?? ''} ${it.serial_number ?? ''}`
      .toLowerCase().includes(q)
  )
})

// Pilih IOL dari master → prefill merk/power/series + simpan iol_item_id ke state.
function pickIolMaster(it) {
  const d = selP.value?.iolDipasang
  if (!d) return
  d.itemId = it.id
  d.merk   = it.brand ?? d.merk
  d.power  = it.power != null ? String(it.power) : d.power
  d.series = it.model ?? d.series
  d.gtin   = it.gtin ?? d.gtin
}

// ── Daftar IOL terpasang (sumber kebenaran = server) ────────────────────────
const iolUsages = ref([])           // [{id, eye_side, brand, model, power, lot_number, serial_number, gtin, ...}]

async function loadIolUsages() {
  const rid = selP.value?.recordId
  if (!rid) { iolUsages.value = []; return }
  try {
    const res = await bedahApi.listIolUsage(rid)
    iolUsages.value = res.data?.data ?? []
    selP.value.iolUsageSaved = iolUsages.value.length > 0
  } catch { iolUsages.value = [] }
}

async function hapusIolUsage(id) {
  if (selP.value?.laporanFinalized) return
  try {
    await bedahApi.deleteIolUsage(id)
    toast('s', 'Catatan IOL dihapus, stok dikembalikan')
    await loadIolUsages()
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal menghapus IOL')
  }
}

// ── Scan barcode IOL (DataMatrix/UDI) saat catat pemakaian ──────────────────
const iolScan = ref({ open: false, busy: false })

function openIolScan() { iolScan.value = { open: true, busy: false } }

async function onIolScanDecoded(rawCode) {
  const d = selP.value?.iolDipasang
  if (!d) return
  iolScan.value.busy = true
  try {
    const res = await bedahApi.scanIol(rawCode)
    const out = res.data?.data ?? {}
    const p = out.parsed ?? {}
    // Simpan string UDI mentah utk jejak audit (dikirim ke BE saat simpan).
    d.gs1_barcode = rawCode
    // Isi serial/lot/gtin/expiry dari hasil parse (lensa fisik yg ditanam).
    if (p.serial_number) d.serial = p.serial_number
    if (p.lot_number)    d.lot = p.lot_number
    if (p.gtin)          d.gtin = p.gtin
    if (p.expiry_date)   d.expiry = p.expiry_date

    if (out.matched && out.iol_item) {
      const m = out.iol_item
      d.itemId = m.id
      d.merk   = m.brand ?? d.merk
      d.power  = m.power != null ? String(m.power) : d.power
      d.series = m.model ?? d.series
      toast('s', `IOL cocok: ${m.brand ?? ''} ${m.model ?? ''}`.trim())
    } else {
      toast('w', `GTIN ${p.gtin ?? '-'} belum terdaftar — pilih dari master atau catat manual.`)
    }
    if (Array.isArray(p.errors) && p.errors.length) toast('w', 'Catatan scan: ' + p.errors.join('; '))
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal memproses barcode')
  } finally {
    iolScan.value.busy = false
    iolScan.value.open = false
  }
}

// Wiring IOL terpasang → backend (surgery_iol_usage). Wajib recordId.
// iol_item_id boleh kosong (lensa non-master) → backend balas warning, tetap simpan.
async function simpanIolTerpasang() {
  if (savingIol.value) return
  const d = selP.value?.iolDipasang
  if (!d) return
  if (!selP.value?.recordId) {
    toast('w', 'Mulai & Time Out operasi dulu sebelum menyimpan IOL')
    return
  }
  if (!d.itemId && !d.merk) {
    toast('w', 'Pilih IOL dari master atau scan/isi data lensa terlebih dahulu')
    return
  }
  savingIol.value = true
  try {
    const res = await bedahApi.storeIolUsage({
      surgery_record_id: selP.value.recordId,
      iol_item_id:       d.itemId || null,
      eye_side:          d.eyeSide || 'OD',
      brand:             d.merk || null,
      model:             d.series || null,
      power:             d.power ? Number(d.power) : null,
      lot_number:        d.lot || null,
      serial_number:     d.serial || null,
      gtin:              d.gtin || null,
      gs1_barcode:       d.gs1_barcode || null,
      expiry_date:       d.expiry || null,
    })
    const warnings = res.data?.data?.warnings ?? []
    selP.value.iolUsageSaved = true
    if (warnings.length) {
      toast('w', 'Tersimpan dengan peringatan: ' + warnings.join(' '))
    } else {
      toast('s', `IOL ${d.eyeSide || 'OD'} tersimpan`)
    }
    await loadIolUsages()
    // Reset PENUH identitas lensa agar tak nempel ke mata berikutnya (risiko salah
    // catat brand/power/model). Pertahankan hanya eyeSide & tipe sebagai preferensi UI.
    d.itemId = ''; d.merk = ''; d.power = ''; d.series = ''
    d.serial = ''; d.lot = ''; d.gtin = ''; d.gs1_barcode = ''; d.expiry = ''
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal menyimpan IOL terpasang')
  } finally {
    savingIol.value = false
  }
}

// ── Obat Farmasi (pilih obat pasca-bedah; SUMBER = inventori unit Farmasi) ─────
// Cari-SERVER ke /bedah/obat (BedahService::getDaftarObat farmasiOnly) — hanya obat
// terdaftar di inventory_stocks lokasi FARMASI (termasuk stok 0). Dulu prefetch 1×
// (cap 100) lalu filter klien → obat ke-101+ tak pernah muncul ("belum tampil semua");
// kini tiap ketik query dikirim ke server → SELURUH obat farmasi terjangkau.
const obatSearchPasca = ref('')     // query obat pasca-bedah
const sendingResep = ref(false)     // guard cegah double kirim resep
const savingInstruksi = ref(false)  // guard cegah double simpan instruksi pasca-op

// Helper cari-server obat farmasi (bedah-scoped, RBAC bedah.read). Server cap 100/hasil cari.
async function searchObatFarmasi(query) {
  try {
    const res = await bedahApi.daftarObat(query || '')
    const list = res.data?.data ?? []
    return Array.isArray(list) ? list : []
  } catch { return [] }
}

const obatPascaResults = ref([])    // hasil cari-server picker obat pasca
let obatPascaTimer = null
async function loadObatMaster() { obatPascaResults.value = await searchObatFarmasi('') }
watch(obatSearchPasca, (q) => {
  clearTimeout(obatPascaTimer)
  obatPascaTimer = setTimeout(async () => { obatPascaResults.value = await searchObatFarmasi(q) }, 300)
})
const filteredObatPasca = computed(() => obatPascaResults.value.slice(0, 50))

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
// Baris antrean dibuat hari ini? (operasi lintas-hari yang masih nyangkut → "Masih Aktif")
function isTodayRow(c) {
  if (!c) return true
  const d = new Date(c), n = new Date()
  return d.getFullYear() === n.getFullYear() && d.getMonth() === n.getMonth() && d.getDate() === n.getDate()
}
const isTodayP = (p) => isTodayRow(p.createdAt)
const isDoneP  = (p) => p.status === 'SELESAI'

const filtQ = computed(() => {
  let list = patients.value
  if (qPrimaryFilter.value === 'active') {
    list = list.filter(p => !isTodayP(p))
  } else if (qPrimaryFilter.value === 'waiting') {
    list = list.filter(p => isTodayP(p) && !isDoneP(p))
  } else {
    list = list.filter(p => isTodayP(p) && isDoneP(p))
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
const cSelesai = computed(() => patients.value.filter(p => isTodayP(p) && isDoneP(p)).length)
const belumDipanggilCount = computed(() => patients.value.filter(p => isTodayP(p) && !isDoneP(p)).length)
const cActive = computed(() => patients.value.filter(p => !isTodayP(p)).length)

const classColor = { Baru: 'cls-baru', 'Pre-Op': 'cls-preop', 'Post-Op': 'cls-postop', Kontrol: 'cls-kontrol' }
function clsCls(c) { return classColor[c] ?? 'cls-baru' }

// Jam jadwal operasi (kartu antrean + detail). scheduled_time bisa "HH:mm:ss" atau "HH:mm".
function fmtJamJadwal(t) {
  if (!t) return '—'
  return String(t).slice(0, 5)
}

// ── Perioperatif: anestesi adaptif + gating WHO ──────────────────────────────
// Bagian anestesi (wizard/monitoring/sub-Sign In) hanya muncul bila operasi
// melibatkan anestesi: jenis = Umum (GA) ATAU slot Anestesiologis terisi.
const hasAnesthesia = computed(() => {
  if (!selP.value) return false
  return selP.value.anestesi === 'Umum' || !!(selP.value.tim?.anestesi || '').trim()
})

// Prosedur vitrektomi → section "Detail Vitrektomi" + simpan vitrectomy_details.
// Pakai SUMBER KEBENARAN yang sama dengan pemicu RM 10.1 (selP.isVitreoretina =
// surgery_type paket, fallback nama). Sebelumnya hanya regex `prosedur` → bila paket
// di-set VITREORETINA tapi namanya tak match regex, form RM 10.1 muncul tapi kolom
// vitrektomi tak tampil & vitrectomy_details dikirim null (detail hilang dari laporan).
const isVitrek = computed(() => !!selP.value?.isVitreoretina)

// Pasien yang SUDAH rawat inap (bedah = sub-aktivitas) → disposisi kembali ke kamar
// (LANJUT_RANAP/HCU), BUKAN Pulang/Rawat-Inap-baru. Dipakai utk dropdown adaptif.
const isFromRanap = computed(() => selP.value?.jenisPelayanan === 'RANAP')

// Sign In lengkap = identitas + sisi mata ditandai + consent + alergi dikonfirmasi
// (+ anestesi siap HANYA bila hasAnesthesia). Topikal cukup tanpa "anestesi_siap".
const signInComplete = computed(() => {
  const s = selP.value?.signIn
  if (!s) return false
  const base = s.identitas && !!s.sisi_mata && s.consent && s.alergi_dikonfirmasi
  return base && (!hasAnesthesia.value || s.anestesi_siap)
})

const timeOutComplete = computed(() => {
  const s = selP.value?.timeOut
  return s ? Object.values(s).every(Boolean) : false
})

const signOutComplete = computed(() => {
  const s = selP.value?.signOut
  return s ? Object.values(s).every(Boolean) : false
})

const aldreteTotal = computed(() => {
  const a = selP.value?.aldrete
  if (!a) return 0
  return ['activity','respiration','circulation','consciousness','spo2']
    .reduce((sum, k) => sum + Math.max(0, Math.min(2, Number(a[k]) || 0)), 0)
})

// Operasi butuh IOL (Phaco) tapi belum dicatat → peringatan (non-blok).
const iolReminderNeeded = computed(() =>
  !!selP.value?.isPhaco && (iolUsages.value?.length ?? 0) === 0)

// ── Actions ────────────────────────────────────────────────────────────────────
function toast(type, msg) {
  const id = ++toastId
  toasts.value.push({ id, type, msg })
  setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id) }, 3500)
}

async function pickPt(p) {
  if (selP.value?.id === p.id) return
  const wasEmpty = !selP.value
  selP.value = p
  tab.value = 'prabedah'
  // Mode fokus: ciutkan antrean saat PERTAMA memilih pasien di layar sempit —
  // hanya bila pengguna belum menyetel preferensi sendiri (localStorage kosong).
  if (wasEmpty && !queueCollapsed.value && localStorage.getItem(QCKEY) === null
      && typeof window !== 'undefined' && window.matchMedia('(max-width: 1500px)').matches) {
    queueCollapsed.value = true
  }
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
        if (rec.post_op_disposition) {
          // Normalisasi ke opsi yang valid bagi jenis pasien: dropdown RANAP hanya
          // punya LANJUT_RANAP/HCU; RAJAL hanya PULANG/RAWAT_INAP. Cegah <select> blank
          // bila kolom berisi nilai dari kategori lain (mis. data lama).
          const ranapVals = ['LANJUT_RANAP', 'HCU']
          const rajalVals = ['PULANG', 'RAWAT_INAP']
          const valid = s.jenisPelayanan === 'RANAP' ? ranapVals : rajalVals
          s.postOpDisposition = valid.includes(rec.post_op_disposition)
            ? rec.post_op_disposition
            : (s.jenisPelayanan === 'RANAP' ? 'LANJUT_RANAP' : 'PULANG')
        }
        // Hidrasi checklist WHO + Aldrete + vitrektomi dari JSONB record.
        hydratePerioperative(rec)
      }
    } catch { /* record belum ada — abaikan */ }
  }

  // Muat daftar IOL terpasang (server = sumber kebenaran) bila record sudah ada.
  if (selP.value?.recordId) loadIolUsages()
  else iolUsages.value = []

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
  // Tetangga berikutnya = baris TERLIHAT (filtQ) di bawahnya, bukan arr[idx+1] di array
  // penuh — saat filter/pencarian aktif, idx+1 bisa baris tersembunyi (swap tak terlihat).
  const vis = filtQ.value
  const vIdx = vis.findIndex(x => x.id === p.id)
  if (vIdx === -1 || vIdx >= vis.length - 1) {
    toast('w', `${p.name} sudah di posisi paling bawah`)
    return
  }
  const nIdx = arr.findIndex(x => x.id === vis[vIdx + 1].id)
  if (nIdx === -1) return
  const tmp = arr[idx]; arr[idx] = arr[nIdx]; arr[nIdx] = tmp
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
    // Record kini ada → persist Tim Bedah pra-bedah (operator/asisten/anestesi) yang
    // sudah diisi (tombol "Simpan Tim Bedah" disabled selama recordId null; hint janjikan
    // tersimpan saat Mulai Operasi) + simpan Sign In (WHO gerbang 1) + muat IOL terpasang.
    if (selP.value.recordId) {
      await saveOperationReport(true)
      if (signInComplete.value && canEditChecklist.value) await saveChecklistPhase('sign_in')
      await loadIolUsages()
    }
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
    post_op_disposition:  p.postOpDisposition || 'PULANG',
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
  // Guard: komplikasi dicentang tapi detail kosong → backend 422 (required_if).
  if (selP.value.komplikasi && !selP.value.komplikasiNote && !selP.value.komplikasiTipe) {
    toast('w', 'Isi tipe atau catatan komplikasi dulu (di tab Laporan) sebelum Time Out')
    return
  }
  busyOp.value = true
  try {
    // Backend: schedule IN_PROGRESS→DONE + isi laporan. TIDAK meneruskan pasien
    // (advance dipindah ke finalisasi). Pasien jalan setelah laporan dikunci.
    const { data } = await bedahApi.selesaiOperasi(selP.value.scheduleId, buildRecordPayload())
    selP.value.recordId = data.data?.id ?? selP.value.recordId
    selP.value.timOut = data.data?.time_out ? new Date(data.data.time_out) : new Date()
    stopTimerInterval()
    if (selP.value.recordId) loadIolUsages()   // record kini ada → muat IOL terpasang
    toast('s', `Time Out: ${timOutDisplay.value} — kunci laporan untuk meneruskan pasien`)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menyelesaikan operasi')
  } finally {
    busyOp.value = false
  }
}

// ── Perioperatif: simpan checklist WHO / laporan / Aldrete ────────────────────
const busyChecklist = ref(false)

// Simpan satu fase Safety Checklist. bypassReason != null → fase dilewati darurat.
async function saveChecklistPhase(phase, bypassReason = null) {
  if (!selP.value?.recordId) {
    toast('w', 'Mulai operasi dulu sebelum mengisi checklist keselamatan')
    return false
  }
  const map = { sign_in: 'signIn', time_out: 'timeOut', sign_out: 'signOut' }
  const stateKey = map[phase]
  busyChecklist.value = true
  try {
    await bedahApi.saveSafetyChecklist(selP.value.recordId, phase, { ...selP.value[stateKey] }, bypassReason)
    selP.value[stateKey + 'Saved'] = true
    if (bypassReason) selP.value.bypass = { ...selP.value.bypass, [phase]: bypassReason }
    toast('s', bypassReason ? 'Fase dilewati (alasan tercatat)' : 'Checklist keselamatan disimpan')
    return true
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menyimpan checklist')
    return false
  } finally {
    busyChecklist.value = false
  }
}

// Lewati fase darurat — minta alasan dulu (prompt sederhana, audit di backend).
async function bypassChecklistPhase(phase) {
  const reason = window.prompt('Alasan melewati fase ini (darurat) — akan tercatat di audit:')
  if (!reason || !reason.trim()) return
  await saveChecklistPhase(phase, reason.trim())
}

// Simpan Laporan Operasi terstruktur (JSONB). Implan auto dari IOL di backend.
// silent=true → dipakai sbg auto-save dari doFinalisasi (toast sukses ditekan,
// hanya error yg ditampilkan). Return true/false agar pemanggil bisa abort.
async function saveOperationReport(silent = false) {
  if (!selP.value?.recordId) { toast('w', 'Laporan belum tersedia'); return false }
  const p = selP.value
  try {
    await bedahApi.saveOperationReport(p.recordId, {
      diagnosis_pre:        p.diagnosa || null,
      diagnosis_post:       p.diagnosaPasca || p.diagnosa || '-',
      procedure_name:       p.prosedur || null,
      operator:             p.tim?.operator || null,
      asisten:              [p.tim?.asisten1, p.tim?.asisten2].filter(Boolean),
      anesthesiologist:     p.tim?.anestesi || null,
      anesthesia_type:      p.anestesi || null,
      findings:             p.temuanIntra || null,
      technique:            p.teknikOp || null,
      notes:                p.catatanIntra || null,
      complication:         { ada: !!p.komplikasi, type: p.komplikasiTipe || null, management: p.komplikasiNote || null },
      estimated_blood_loss: p.estimatedBloodLoss || null,
      vitrectomy_details:   isVitrek.value ? { ...p.vitrek } : null,
      post_op_disposition:  p.postOpDisposition || 'PULANG',
    })
    if (!silent) toast('s', 'Laporan operasi disimpan')
    return true
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menyimpan laporan operasi')
    return false
  }
}

// Serialisasi checkbox Instruksi Pasca-Operasi → teks (1 instruksi per baris).
// Hanya yang dicentang. Kosong → null (storePostOp BE wajib non-kosong, jadi skip).
function buildPostOpInstructions() {
  const checked = instruksiList.filter((_, i) => selP.value?.instruksi?.[i])
  return checked.length ? checked.join('\n') : null
}

// Simpan instruksi pasca-op (checkbox) ke kolom post_op_instructions.
// silent=true utk auto-save dari doFinalisasi. Return true/false.
async function savePostOpInstructions(silent = false) {
  if (!selP.value?.recordId) return false
  const text = buildPostOpInstructions()
  if (!text) return true   // tak ada instruksi dicentang → tak perlu simpan
  if (savingInstruksi.value) return false
  savingInstruksi.value = true
  try {
    await bedahApi.storePostOp(selP.value.recordId, { post_op_instructions: text })
    if (!silent) toast('s', 'Instruksi pasca-operasi disimpan')
    return true
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menyimpan instruksi pasca-operasi')
    return false
  } finally {
    savingInstruksi.value = false
  }
}

// Simpan skor pemulihan Aldrete (total dihitung server).
async function saveRecovery() {
  if (!selP.value?.recordId) { toast('w', 'Laporan belum tersedia'); return }
  const p = selP.value
  try {
    await bedahApi.saveRecoveryAssessment(p.recordId, {
      aldrete: { ...p.aldrete },
      pain_score: Number(p.painScore) || 0,
    })
    p.recoverySaved = true
    toast('s', `Skor pemulihan disimpan (Aldrete ${aldreteTotal.value}/10)`)
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menyimpan skor pemulihan')
  }
}

// Hidrasi state checklist/recovery/vitrek dari record server (dipanggil saat load record).
// Field laporan klinis (anestesi/diagnosa pasca/tim/instruksi) HANYA di-prefill bila
// belum disentuh user di sesi ini (cegah polling/re-pick menimpa draft lokal).
function hydratePerioperative(rec) {
  if (!rec || !selP.value) return
  const s = selP.value
  const sc = rec.safety_checklist || {}
  if (sc.sign_in)  { s.signIn  = { ...s.signIn,  ...sc.sign_in };  s.signInSaved = true }
  if (sc.time_out) { s.timeOut = { ...s.timeOut, ...sc.time_out }; s.timeOutSaved = true }
  if (sc.sign_out) { s.signOut = { ...s.signOut, ...sc.sign_out }; s.signOutSaved = true }
  if (sc.bypass)   s.bypass = { ...sc.bypass }
  const ra = rec.recovery_assessment || {}
  if (ra.aldrete) { s.aldrete = { ...s.aldrete, ...ra.aldrete }; s.recoverySaved = true }
  if (ra.pain_score != null) s.painScore = ra.pain_score
  const or = rec.operation_report || {}
  if (or.vitrectomy_details) s.vitrek = { ...s.vitrek, ...or.vitrectomy_details }
  if (or.estimated_blood_loss) s.estimatedBloodLoss = or.estimated_blood_loss
  // Jenis anestesi & diagnosa pasca-bedah & tim — pulihkan dari laporan tersimpan
  // (cegah default 'Topikal' menyesatkan + hasAnesthesia salah → panel anestesi hilang).
  if (or.anesthesia_type) s.anestesi = or.anesthesia_type
  if (or.diagnosis_post && or.diagnosis_post !== '-' && !s.diagnosaPasca) s.diagnosaPasca = or.diagnosis_post
  if (or.operator && !s.tim.operator) s.tim.operator = or.operator
  if (or.anesthesiologist && !s.tim.anestesi) s.tim.anestesi = or.anesthesiologist
  if (Array.isArray(or.asisten)) {
    if (or.asisten[0] && !s.tim.asisten1) s.tim.asisten1 = or.asisten[0]
    if (or.asisten[1] && !s.tim.asisten2) s.tim.asisten2 = or.asisten[1]
  }
  // Instruksi pasca-op (teks 1-per-baris di kolom) → kembalikan ke checkbox.
  if (rec.post_op_instructions) {
    const saved = String(rec.post_op_instructions).split('\n').map(t => t.trim()).filter(Boolean)
    s.instruksi = instruksiList.map(inst => saved.includes(inst))
  }
}

// ── KOMPONEN PAKET PASIEN (snapshot) — multi-paket (mis. Phaco + TIVA) ───────
const visitPackages = ref([])         // [{ id, package_name, sell_price, total_base_price, discount_amount, items[] }]
const vpProcOptions = ref([])         // {id, name, code} master tindakan (tarif metode bayar)
const vpBhpOptions = ref([])          // {id, name, code} master BHP
const vpBusy = ref(false)
// Form "tambah komponen" per-kartu (key = snapshot id) → {type, itemId, qty}.
const vpAddForm = reactive({})
function vpForm(snapId) {
  if (!vpAddForm[snapId]) vpAddForm[snapId] = { type: 'PROCEDURE', itemId: '', qty: 1 }
  return vpAddForm[snapId]
}
// Pilihan paket utk "Tambah Paket" (semua paket aktif; dokter sudah pilih 1 di planning).
const vpAllPackages = ref([])         // {id, name, code, package_type}
const vpAddPkgId = ref('')
const vpAddTariffId = ref('')   // varian harga terpilih (1 penjamin bisa >1 varian)

const fmtRp2 = (v) => 'Rp ' + Number(v ?? 0).toLocaleString('id-ID')

// Varian harga paket yg dipilih di "Tambah Paket" → dropdown Nama Paket Penjamin.
const vpSelectedVariants = computed(() =>
  vpAllPackages.value.find((p) => p.id === vpAddPkgId.value)?.variants ?? [],
)
watch(vpAddPkgId, () => {
  const v = vpSelectedVariants.value
  vpAddTariffId.value = v.length ? v[0].tariff_id : ''
})

async function loadVisitPackage(visitId) {
  visitPackages.value = []
  if (!visitId) return
  try {
    const { data } = await bedahApi.getVisitPackage(visitId)
    // BE kini balikkan ARRAY paket (multi-paket per visit).
    visitPackages.value = Array.isArray(data.data) ? data.data : (data.data ? [data.data] : [])
  } catch { visitPackages.value = [] }
}
async function loadVpPickerOptions(visitId) {
  // Tindakan: tarif per metode bayar pasien (sama dgn kasir/dokter).
  try {
    const { data } = await dokterApi.tarifTindakan(visitId)
    vpProcOptions.value = (data.data ?? []).map((t) => ({ id: t.id, name: t.name, code: t.code }))
  } catch { vpProcOptions.value = [] }
  // BHP: master item.
  try {
    const { data } = await masterApi.bhp.list({ per_page: 500, active: 1 })
    const rows = data.data?.data ?? data.data ?? []
    vpBhpOptions.value = rows.map((b) => ({ id: b.id, name: b.name, code: b.code }))
  } catch { vpBhpOptions.value = [] }
  // Daftar paket untuk "Tambah Paket" (mis. paket anestesi TIVA di samping Phaco).
  // Pakai master paket-bedah dgn visit_id → dapat resolved_variants per-penjamin.
  try {
    const { data } = await masterApi.paketBedah.list({ per_page: 500, active: 1, visit_id: visitId || undefined })
    const rows = data.data?.data ?? data.data ?? []
    vpAllPackages.value = rows.map((p) => ({
      id: p.id, name: p.name, code: p.code, package_type: p.package_type,
      variants: Array.isArray(p.resolved_variants) ? p.resolved_variants : [],
    }))
  } catch { vpAllPackages.value = [] }
}
// Opsi item utk form-tambah satu kartu (berdasar type form kartu itu).
function vpOptionsFor(snapId) {
  return vpForm(snapId).type === 'BHP' ? vpBhpOptions.value : vpProcOptions.value
}
// Paket yang belum dipakai pasien (sembunyikan yang sudah ter-snapshot).
const vpAvailablePackages = computed(() => {
  const used = new Set(visitPackages.value.map((p) => p.source_surgery_package_id ?? p.id))
  const usedNames = new Set(visitPackages.value.map((p) => p.package_name))
  return vpAllPackages.value.filter((p) => !used.has(p.id) && !usedNames.has(p.name))
})

async function vpAddItem(snap) {
  const visitId = selP.value?.visitId
  const form = vpForm(snap.id)
  if (!visitId || !form.itemId || vpBusy.value) { toast('w', 'Pilih item dulu'); return }
  vpBusy.value = true
  try {
    const { data } = await bedahApi.addVisitPackageItem(visitId, {
      visit_surgery_package_id: snap.id,
      item_type: form.type, item_id: form.itemId, quantity: Math.max(1, form.qty || 1),
    })
    if (Array.isArray(data.data)) visitPackages.value = data.data
    form.itemId = ''; form.qty = 1
    toast('s', 'Komponen paket ditambah')
  } catch (e) { toast('e', e.response?.data?.message ?? 'Gagal tambah') }
  finally { vpBusy.value = false }
}
async function vpUpdateQty(item, qty) {
  if (vpBusy.value) { if (selP.value?.visitId) await loadVisitPackage(selP.value.visitId); return }
  const q = Math.max(1, parseInt(qty) || 1)
  vpBusy.value = true
  try {
    const { data } = await bedahApi.updateVisitPackageItem(item.id, { quantity: q })
    if (Array.isArray(data.data)) visitPackages.value = data.data
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal ubah')
    // Gagal simpan → muat ulang paket agar input qty snap kembali ke nilai server
    // (tanpa ini input tampil nilai yg diketik padahal data tak berubah).
    if (selP.value?.visitId) await loadVisitPackage(selP.value.visitId)
  }
  finally { vpBusy.value = false }
}
async function vpRemoveItem(item) {
  if (vpBusy.value) return
  vpBusy.value = true
  try {
    const { data } = await bedahApi.removeVisitPackageItem(item.id)
    if (Array.isArray(data.data)) visitPackages.value = data.data
    toast('s', 'Komponen dihapus')
  } catch (e) { toast('e', e.response?.data?.message ?? 'Gagal hapus') }
  finally { vpBusy.value = false }
}

// ── Tambah / hapus PAKET (multi-paket: Phaco + TIVA) ──
async function vpAddPackage() {
  const visitId = selP.value?.visitId
  if (!visitId || !vpAddPkgId.value || vpBusy.value) { toast('w', 'Pilih paket dulu'); return }
  vpBusy.value = true
  try {
    const { data } = await bedahApi.addVisitPackage(visitId, vpAddPkgId.value, vpAddTariffId.value || null)
    if (Array.isArray(data.data)) visitPackages.value = data.data
    vpAddPkgId.value = ''
    vpAddTariffId.value = ''
    toast('s', 'Paket ditambahkan')
  } catch (e) { toast('e', e.response?.data?.message ?? 'Gagal tambah paket') }
  finally { vpBusy.value = false }
}
async function vpRemovePackage(snap) {
  if (vpBusy.value) return
  if (!confirm(`Hapus paket "${snap.package_name}" dari pasien ini?`)) return
  vpBusy.value = true
  try {
    const { data } = await bedahApi.removeVisitPackage(snap.id)
    if (Array.isArray(data.data)) visitPackages.value = data.data
    toast('s', 'Paket dihapus')
  } catch (e) { toast('e', e.response?.data?.message ?? 'Gagal hapus paket') }
  finally { vpBusy.value = false }
}

// Obat Pasca Bedah — wajib pilih obat dari master (medication_id) supaya resep
// bisa dikirim ke Farmasi (PrescriptionItem.medication_id NOT NULL).
const newObat = ref({ medication_id: '', nama: '', jumlah: 1, dosis: '1 tetes', freq: '4×/hari', dur: '7 hari', rute: 'Tetes OD' })

// Pilih obat dari master → isi medication_id + nama, tutup dropdown pencarian.
function pickObatMaster(o) {
  newObat.value.medication_id = o.id
  newObat.value.nama = o.name
  obatSearchPasca.value = ''
}

// Parse durasi dropdown ('7 hari'→7, '1 bulan'→30) ke jumlah hari (integer).
function parseDurDays(dur) {
  if (!dur) return null
  const s = String(dur).toLowerCase()
  const n = parseInt(s.replace(/[^\d]/g, ''), 10)
  if (!n) return null
  if (s.includes('bulan')) return n * 30
  if (s.includes('minggu')) return n * 7
  return n   // default: hari
}

function addObat() {
  if (!selP.value) return
  if (!newObat.value.medication_id) { toast('w', 'Pilih obat dari master dulu'); return }
  selP.value.obatPasca.push({ ...newObat.value })
  newObat.value = { medication_id: '', nama: '', jumlah: 1, dosis: '1 tetes', freq: '4×/hari', dur: '7 hari', rute: 'Tetes OD' }
  toast('s', 'Obat pasca bedah ditambahkan')
}
function removeObat(idx) { selP.value?.obatPasca.splice(idx, 1) }

async function kirimResep() {
  if (sendingResep.value) return
  if (!selP.value || !selP.value.obatPasca.length) { toast('w', 'Belum ada obat ditambahkan'); return }
  // Resolve record.id (dari mulai/timeout, atau ambil ulang via scheduleId).
  let recordId = selP.value.recordId
  if (!recordId && selP.value.scheduleId) {
    try {
      const { data } = await bedahApi.showRecord(selP.value.scheduleId)
      recordId = data.data?.id ?? null
      if (recordId) selP.value.recordId = recordId
    } catch { /* record belum ada */ }
  }
  if (!recordId) {
    toast('w', 'Mulai & Time Out operasi dulu sebelum kirim resep')
    return
  }
  // Map ke kontrak PrescriptionItem (skip item tanpa medication_id).
  const items = selP.value.obatPasca
    .filter((o) => o.medication_id)
    .map((o) => ({
      medication_id: o.medication_id,
      quantity:      Math.max(1, Number(o.jumlah) || 1),
      dose:          o.dosis || null,
      frequency:     o.freq || null,
      route:         o.rute || null,
      duration_days: parseDurDays(o.dur),
      notes:         null,
      // Obat dari paket → kandidat terserap ke harga paket (backend set is_bedah
      // bersyarat bila pasien berpaket). Obat manual → bundled=false → tetap ditagih.
      bundled:       !!o.fromPaket,
    }))
  if (!items.length) { toast('w', 'Obat harus dipilih dari master'); return }
  sendingResep.value = true
  try {
    await bedahApi.storeResepPasca(recordId, { items })
    selP.value.resepSent = true
    toast('s', 'Resep pasca-bedah terkirim ke Farmasi')
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal mengirim resep pasca-bedah')
  } finally {
    sendingResep.value = false
  }
}

// ── Paket Obat Pasca-Bedah (template resep rutin) ───────────────────────────
const paketObatList = ref([])          // [{id, name, category, items:[{medication_id, quantity, dose, frequency, route, duration_days, medication}]}]
const paketPickId   = ref('')           // paket dipilih utk "Terapkan"
const showPaketModal = ref(false)
const paketBusy = ref(false)
// Form modal kelola: id null = paket baru.
const paketForm = reactive({ id: null, name: '', category: '', items: [] })
const paketNewItem = reactive({ medication_id: '', nama: '', jumlah: 1, dosis: '1 tetes', freq: '4×/hari', dur: '7 hari', rute: 'Tetes OD' })
const paketMedSearch = ref('')          // autocomplete obat di modal (terpisah dari obatSearchPasca)

// Pasien punya paket bedah aktif → obat paket terserap (tak ditagih terpisah).
const hasVisitPaket = computed(() => (visitPackages.value?.length ?? 0) > 0)

// Autocomplete obat di modal kelola paket — cari-SERVER (sumber sama: inventori Farmasi).
// Dulu filter klien atas 100 baris pertama → obat ke-101+ tak ketemu (terlihat "dari master").
const paketMedResults = ref([])
let paketMedTimer = null
watch(paketMedSearch, (q) => {
  clearTimeout(paketMedTimer)
  paketMedTimer = setTimeout(async () => { paketMedResults.value = await searchObatFarmasi(q) }, 300)
})
const paketMedFiltered = computed(() => paketMedResults.value.slice(0, 50))

async function loadPaketObat() {
  try {
    const res = await bedahApi.paketObat.list('')
    paketObatList.value = res.data?.data ?? []
  } catch { paketObatList.value = [] }
}

// duration_days → label dropdown (kebalikan parseDurDays). 30→'1 bulan', else 'N hari'.
function formatDur(days) {
  const n = Number(days)
  if (!n) return '7 hari'
  if (n % 30 === 0) return `${n / 30} bulan`
  if (n % 7 === 0 && n >= 21) return `${n / 7} minggu`
  return `${n} hari`
}

// Terapkan paket → auto-isi obatPasca (append + dedupe by medication_id), tandai fromPaket.
function applyPaketObat() {
  if (!selP.value) return
  const pkg = paketObatList.value.find((p) => p.id === paketPickId.value)
  if (!pkg) { toast('w', 'Pilih paket obat dulu'); return }
  if (selP.value.resepSent) return
  const existing = new Set(selP.value.obatPasca.map((o) => o.medication_id))
  let added = 0
  for (const it of (pkg.items ?? [])) {
    if (!it.medication_id || existing.has(it.medication_id)) continue
    selP.value.obatPasca.push({
      medication_id: it.medication_id,
      nama:    it.medication?.name ?? '—',
      jumlah:  it.quantity ?? 1,
      dosis:   it.dose ?? '',
      freq:    it.frequency ?? '',
      dur:     formatDur(it.duration_days),
      rute:    it.route ?? '',
      fromPaket: true,
    })
    existing.add(it.medication_id)
    added++
  }
  toast(added ? 's' : 'i', added ? `${added} obat dari paket "${pkg.name}" ditambahkan` : 'Semua obat paket sudah ada di daftar')
  paketPickId.value = ''
}

// ── Modal kelola paket ──
function openPaketModal() {
  resetPaketForm()
  showPaketModal.value = true
  searchObatFarmasi('').then((r) => { paketMedResults.value = r })   // muat awal obat farmasi
}
function resetPaketForm() {
  paketForm.id = null; paketForm.name = ''; paketForm.category = ''; paketForm.items = []
  paketMedSearch.value = ''
  Object.assign(paketNewItem, { medication_id: '', nama: '', jumlah: 1, dosis: '1 tetes', freq: '4×/hari', dur: '7 hari', rute: 'Tetes OD' })
}
function editPaket(pkg) {
  paketForm.id = pkg.id
  paketForm.name = pkg.name
  paketForm.category = pkg.category ?? ''
  paketForm.items = (pkg.items ?? []).map((it) => ({
    medication_id: it.medication_id,
    nama:   it.medication?.name ?? '—',
    jumlah: it.quantity ?? 1,
    dosis:  it.dose ?? '',
    freq:   it.frequency ?? '',
    dur:    formatDur(it.duration_days),
    rute:   it.route ?? '',
  }))
}
function pickPaketMed(o) {
  paketNewItem.medication_id = o.id
  paketNewItem.nama = o.name
  paketMedSearch.value = ''
}
function addPaketItem() {
  if (!paketNewItem.medication_id) { toast('w', 'Pilih obat dari master dulu'); return }
  paketForm.items.push({ ...paketNewItem })
  Object.assign(paketNewItem, { medication_id: '', nama: '', jumlah: 1, dosis: '1 tetes', freq: '4×/hari', dur: '7 hari', rute: 'Tetes OD' })
}
function removePaketItem(i) { paketForm.items.splice(i, 1) }

async function savePaket() {
  if (paketBusy.value) return
  if (!paketForm.name.trim()) { toast('w', 'Nama paket wajib diisi'); return }
  if (!paketForm.items.length) { toast('w', 'Tambahkan minimal 1 obat'); return }
  const payload = {
    name: paketForm.name.trim(),
    category: paketForm.category?.trim() || null,
    items: paketForm.items.map((o) => ({
      medication_id: o.medication_id,
      quantity:      Math.max(1, Number(o.jumlah) || 1),
      dose:          o.dosis || null,
      frequency:     o.freq || null,
      route:         o.rute || null,
      duration_days: parseDurDays(o.dur),
    })),
  }
  paketBusy.value = true
  try {
    if (paketForm.id) await bedahApi.paketObat.update(paketForm.id, payload)
    else await bedahApi.paketObat.create(payload)
    toast('s', paketForm.id ? 'Paket obat diperbarui' : 'Paket obat dibuat')
    await loadPaketObat()
    resetPaketForm()
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal menyimpan paket obat')
  } finally {
    paketBusy.value = false
  }
}

async function deletePaket(pkg) {
  if (!confirm(`Hapus paket obat "${pkg.name}"?`)) return
  paketBusy.value = true
  try {
    await bedahApi.paketObat.remove(pkg.id)
    toast('s', 'Paket obat dihapus')
    if (paketForm.id === pkg.id) resetPaketForm()
    await loadPaketObat()
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal menghapus paket obat')
  } finally {
    paketBusy.value = false
  }
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
    // recordId pasti ada sekarang (mis. di-resolve dr scheduleId) → pastikan state
    // lokal punya recordId agar saveOperationReport/savePostOpInstructions jalan.
    selP.value.recordId = recordId
    // PENTING (anti data-loss): persist laporan operasi + instruksi pasca-op SEBELUM
    // mengunci. Finalize hanya set finalized_at + routing pasien berdasar KOLOM
    // post_op_disposition — jadi field tab Laporan (teknik/temuan/diagnosa pasca/
    // disposisi/EBL/vitrek) & instruksi WAJIB tersimpan dulu, else hilang diam-diam
    // + pasien bisa salah dirutekan. Abort finalize bila save gagal.
    if (canEditReport.value) {
      const okReport = await saveOperationReport(true)
      if (!okReport) {
        toast('w', 'Laporan gagal disimpan — finalisasi dibatalkan. Coba lagi.')
        return
      }
      const okInstr = await savePostOpInstructions(true)
      if (!okInstr) {
        toast('w', 'Instruksi pasca-op gagal disimpan — finalisasi dibatalkan. Coba lagi.')
        return
      }
      // ANTI-BOCOR BILLING: obat pasca-bedah yang sudah diisi TAPI belum dikirim ke
      // Farmasi WAJIB terkirim SEBELUM pasien dirutekan ke Kasir. "Selesai Operasi"
      // mengadvance antrean Bedah→Kasir; bila resep belum ada saat kasir konsolidasi,
      // obat tak masuk tagihan/kwitansi (bocor). Kirim dulu; abort finalisasi bila gagal.
      // HANYA disposisi PULANG yang dirutekan ke Kasir — RANAP/HCU/RAWAT_INAP kembali
      // ke alur rawat inap (ditagih via inpatient_charges saat pulang, bukan resep ini),
      // jadi jangan paksa kirim resep di jalur itu (hindari ubah alur antar-stasiun).
      if (selP.value.postOpDisposition === 'PULANG' && selP.value.obatPasca.length && !selP.value.resepSent) {
        await kirimResep()
        if (!selP.value.resepSent) {
          toast('w', 'Obat pasca-bedah belum terkirim ke Farmasi — finalisasi dibatalkan. Periksa & kirim resep dulu.')
          return
        }
      }
    }
    // Backend: kunci laporan (finalized_at) + advance antrean ke Farmasi/Kasir.
    await bedahApi.finalizeRecord(recordId)
    // Selesai ≠ kunci: pasien diteruskan, tapi laporan TETAP editable (tidak set
    // laporanFinalized). Penguncian final saat dokter TTD dokumen RM di TTD Dokumen.
    selP.value.operationDone = true
    selP.value.status = 'SELESAI'
    selP.value.timOut = selP.value.timOut || new Date()
    stopTimerInterval()
    showFinalModal.value = false
    toast('s', 'Operasi selesai — pasien diteruskan. Laporan masih bisa diperbaiki & ditinjau di TTD Dokumen.')
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

// Komponen skor Aldrete (PACU) — tiap komponen 0-2, total ≥9 layak transfer.
const aldreteComponents = [
  { key: 'activity',      label: 'Aktivitas',     opts: ['Tidak gerak', '2 ekstremitas', '4 ekstremitas'] },
  { key: 'respiration',   label: 'Pernapasan',    opts: ['Apnea', 'Dangkal/sesak', 'Napas dalam/batuk'] },
  { key: 'circulation',   label: 'Sirkulasi (TD)', opts: ['>50% deviasi', '20-50% deviasi', '<20% deviasi'] },
  { key: 'consciousness', label: 'Kesadaran',     opts: ['Tidak respons', 'Bangun saat dipanggil', 'Sadar penuh'] },
  { key: 'spo2',          label: 'Saturasi O₂',   opts: ['<90% dgn O₂', '>90% dgn O₂', '>92% udara bebas'] },
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
  <div :class="['bedah', { 'q-collapsed': queueCollapsed }]">
    <div class="main-grid">

      <!-- ══════════════════ LEFT: QUEUE ══════════════════ -->
      <aside class="col-queue">

        <!-- Rail tipis saat antrean diciutkan — klik untuk buka kembali -->
        <button class="queue-rail" @click="toggleQueue" title="Buka daftar antrean" aria-label="Buka daftar antrean">
          <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
          <span class="queue-rail-count">{{ patients.length }}</span>
          <span class="queue-rail-txt">Antrean</span>
        </button>

        <div class="card">
          <div class="card-head">
            <div>
              <div class="card-head-title">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"/></svg>
                Antrean Bedah
              </div>
              <div class="card-head-sub">{{ patients.length }} pasien hari ini</div>
            </div>
            <div class="head-actions">
              <span class="pill-live">LIVE</span>
              <button class="panel-collapse" @click="toggleQueue" title="Ciutkan antrean" aria-label="Ciutkan antrean">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
              </button>
            </div>
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
              <button
                :class="['pf-btn', qPrimaryFilter === 'active' ? 'a' : '']"
                @click="qPrimaryFilter = 'active'"
                title="Operasi belum selesai dari hari sebelumnya (lintas-hari)"
              >
                Masih Aktif
                <span v-if="cActive" class="pf-ct">{{ cActive }}</span>
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

          <!-- Pemicu Dokumen RM (pojok kanan tab) — kontekstual per tab.
               Pra-Bedah → Checklist Kesiapan; Laporan → 3 form laporan operasi. -->
          <button
            v-if="selP?.visitId && (tab === 'prabedah' || tab === 'laporan')"
            class="bd-tab-docbtn"
            @click="showRmDocsModal = true"
            :title="tab === 'prabedah' ? 'Checklist Kesiapan Bedah' : 'Dokumen Laporan Operasi (RM)'"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            {{ tab === 'prabedah' ? 'Checklist Kesiapan' : 'Dokumen RM' }}
          </button>
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
                    <span v-else class="bd-val bd-val-na">Belum ada ruang OK — atur di Profil Rumah Sakit</span>
                  </div>
                  <div class="bd-field-row">
                    <label class="bd-label">Jadwal Operasi</label>
                    <span v-if="selP.scheduledTime" class="bd-val">{{ fmtJamJadwal(selP.scheduledTime) }} WIB</span>
                    <span v-else class="bd-val bd-val-na">Jam belum ditentukan dokter</span>
                  </div>
                  <div class="bd-field-row">
                    <label class="bd-label">Diagnosa</label>
                    <span class="bd-val bd-dx">{{ selP.diagnosa }}<span v-if="selP.diagnosaNama"> — {{ selP.diagnosaNama }}</span></span>
                  </div>
                </div>
              </div>

              <!-- 🟢 SIGN IN (WHO gerbang 1 — sebelum induksi anestesi) -->
              <div class="bd-card">
                <div class="bd-card-hd">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                  Sign In — Keselamatan Pra-Induksi
                  <span :class="['bd-chk-badge', (signInComplete || selP.signInSaved) ? 'bd-chk-ok' : 'bd-chk-no']">
                    {{ selP.signInSaved ? 'Tersimpan' : (signInComplete ? 'Lengkap' : 'Belum lengkap') }}
                  </span>
                </div>
                <div class="bd-card-bd">
                  <p v-if="!canEditChecklist" class="bd-rolehint">Diisi oleh perawat/dokter.</p>
                  <label class="bd-chk-item">
                    <input type="checkbox" v-model="selP.signIn.identitas" :disabled="selP.laporanFinalized || !canEditChecklist" />
                    <span :class="selP.signIn.identitas && 'bd-chk-done'">Identitas pasien terverifikasi (gelang, KTP)</span>
                  </label>
                  <div class="bd-field-row" style="margin:6px 0">
                    <label class="bd-label">Sisi mata ditandai</label>
                    <div class="bd-radios">
                      <label class="bd-radio-lbl"><input type="radio" value="OD" v-model="selP.signIn.sisi_mata" :disabled="selP.laporanFinalized || !canEditChecklist" /> OD (kanan)</label>
                      <label class="bd-radio-lbl"><input type="radio" value="OS" v-model="selP.signIn.sisi_mata" :disabled="selP.laporanFinalized || !canEditChecklist" /> OS (kiri)</label>
                      <label class="bd-radio-lbl"><input type="radio" value="ODS" v-model="selP.signIn.sisi_mata" :disabled="selP.laporanFinalized || !canEditChecklist" /> ODS</label>
                    </div>
                  </div>
                  <label class="bd-chk-item">
                    <input type="checkbox" v-model="selP.signIn.consent" :disabled="selP.laporanFinalized || !canEditChecklist" />
                    <span :class="selP.signIn.consent && 'bd-chk-done'">Informed consent ditandatangani</span>
                  </label>
                  <!-- Sub-bagian anestesi: hanya bila operasi melibatkan anestesi -->
                  <label v-if="hasAnesthesia" class="bd-chk-item">
                    <input type="checkbox" v-model="selP.signIn.anestesi_siap" :disabled="selP.laporanFinalized || !canEditChecklist" />
                    <span :class="selP.signIn.anestesi_siap && 'bd-chk-done'">Mesin & obat anestesi diperiksa & siap</span>
                  </label>
                  <label class="bd-chk-item">
                    <input type="checkbox" v-model="selP.signIn.alergi_dikonfirmasi" :disabled="selP.laporanFinalized || !canEditChecklist" />
                    <span :class="selP.signIn.alergi_dikonfirmasi && 'bd-chk-done'">Alergi dikonfirmasi & didokumentasikan</span>
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
                <div class="bd-tab-save">
                  <span v-if="!selP.recordId" class="bd-rolehint">Tim tersimpan saat menekan "Mulai Operasi".</span>
                  <button class="bd-btn-add" :disabled="selP.laporanFinalized || !selP.recordId" @click="saveOperationReport(false)">
                    Simpan Tim Bedah
                  </button>
                </div>
              </div>
            </div>

            <!-- Toggle IOL — override auto-deteksi prosedur Phaco/SICS -->
            <div class="bd-card bd-card-full">
              <div class="bd-card-bd">
                <label class="bd-chk-item">
                  <input type="checkbox" v-model="selP.isPhaco" :disabled="selP.laporanFinalized" />
                  <span :class="selP.isPhaco && 'bd-chk-done'">Pasang IOL (Phaco / SICS) — tampilkan kolom lensa intraokular</span>
                </label>
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

            <!-- Checklist Kesiapan Bedah (RM 2.0) dipindah ke modal via tombol pojok tab
                 ("Checklist Kesiapan") agar tidak memakan ruang panel pra-bedah. -->

            <!-- Mulai Operasi Button (gating lunak: Sign In lengkap) -->
            <div v-if="selP.status === 'MENUNGGU'" class="bd-mulai-wrap">
              <button
                :class="['bd-btn-mulai', !signInComplete && 'bd-btn-disabled']"
                :disabled="!signInComplete"
                @click="openMulaiModal"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                Mulai Operasi
              </button>
              <span v-if="!signInComplete" class="bd-mulai-hint">Lengkapi Sign In keselamatan terlebih dahulu</span>
            </div>
            <div v-else-if="selP.status === 'BERLANGSUNG'" class="bd-status-info bd-status-live">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              Operasi sedang berlangsung — lihat tab Intraoperatif
            </div>
            <div v-else class="bd-status-info bd-status-done">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              Operasi selesai — pasien diteruskan
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
                    :disabled="busyOp || (!timeOutComplete && !selP.bypass?.time_out)"
                    @click="doTimeOut"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="4" height="16" x="6" y="4"/><rect width="4" height="16" x="14" y="4"/></svg>
                    {{ busyOp ? 'Memproses…' : 'Selesaikan Operasi (Time Out)' }}
                  </button>
                  <span v-else-if="selP.timOut" class="bd-timer-done-badge">Operasi Selesai · {{ timerDisplay }}</span>
                  <span v-else class="bd-timer-hint">Tekan "Mulai Operasi" di tab Pra-Bedah</span>
                </div>
              </div>
            </div>

            <!-- 🟡 TIME OUT (WHO gerbang 2 — sebelum insisi) -->
            <div v-if="selP.status === 'BERLANGSUNG' && !selP.timOut" class="bd-card bd-card-full bd-timeout-card">
              <div class="bd-card-hd">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
                Time Out — Konfirmasi Tim Sebelum Insisi
                <span :class="['bd-chk-badge', timeOutComplete ? 'bd-chk-ok' : 'bd-chk-no']">
                  {{ timeOutComplete ? 'Lengkap' : (selP.bypass?.time_out ? 'Dilewati' : 'Belum lengkap') }}
                </span>
              </div>
              <div class="bd-card-bd">
                <p v-if="!canEditChecklist" class="bd-rolehint">Dipimpin perawat sirkuler; tim mengonfirmasi bersama.</p>
                <label class="bd-chk-item"><input type="checkbox" v-model="selP.timeOut.tim_lengkap" :disabled="!canEditChecklist" /><span :class="selP.timeOut.tim_lengkap && 'bd-chk-done'">Seluruh tim hadir & menyebut nama + peran</span></label>
                <label class="bd-chk-item"><input type="checkbox" v-model="selP.timeOut.identitas_prosedur" :disabled="!canEditChecklist" /><span :class="selP.timeOut.identitas_prosedur && 'bd-chk-done'">Identitas pasien & prosedur dikonfirmasi lisan</span></label>
                <label class="bd-chk-item"><input type="checkbox" v-model="selP.timeOut.sisi_mata" :disabled="!canEditChecklist" /><span :class="selP.timeOut.sisi_mata && 'bd-chk-done'">Sisi mata yang dioperasi dikonfirmasi ({{ selP.signIn.sisi_mata || '—' }})</span></label>
                <label class="bd-chk-item"><input type="checkbox" v-model="selP.timeOut.antibiotik" :disabled="!canEditChecklist" /><span :class="selP.timeOut.antibiotik && 'bd-chk-done'">Antibiotik profilaksis diberikan / tidak diindikasikan</span></label>
                <label class="bd-chk-item"><input type="checkbox" v-model="selP.timeOut.iol_benar" :disabled="!canEditChecklist" /><span :class="selP.timeOut.iol_benar && 'bd-chk-done'">{{ selP.isPhaco ? 'IOL & power lensa dikonfirmasi benar' : 'Antisipasi kejadian kritis dibahas' }}</span></label>
                <div class="bd-timeout-actions">
                  <button class="bd-btn-add" :disabled="!canEditChecklist || !timeOutComplete || busyChecklist" @click="saveChecklistPhase('time_out')">{{ busyChecklist ? 'Menyimpan…' : 'Simpan Time Out' }}</button>
                  <button class="bd-btn-bypass" :disabled="!canEditChecklist" @click="bypassChecklistPhase('time_out')">Lewati (darurat)</button>
                  <span v-if="selP.bypass?.time_out" class="bd-bypass-note">⚠ Dilewati: {{ selP.bypass.time_out }}</span>
                </div>
              </div>
            </div>

            <!-- Pengingat IOL belum dicatat (non-blok) -->
            <div v-if="selP.status === 'BERLANGSUNG' && iolReminderNeeded" class="bd-iol-reminder">
              ⚠ IOL belum dicatat untuk operasi katarak ini — catat lensa terpasang di bawah sebelum mengunci laporan.
            </div>

            <div class="bd-2col">
              <!-- Komponen Paket Pasien (snapshot) — edit Tindakan & BHP -->
              <div class="bd-card">
                <div class="bd-card-hd">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                  Komponen Paket Pasien
                  <span v-if="visitPackages.length" class="bd-paket-source-pill">{{ visitPackages.length }} paket</span>
                </div>
                <div class="bd-card-bd">
                  <div v-if="!visitPackages.length" class="bd-tbl-empty">Pasien ini belum memakai paket. Komponen aktual ditagih langsung di Kasir.</div>

                  <!-- Satu kartu per paket (mis. Phaco + TIVA) -->
                  <div v-for="snap in visitPackages" :key="snap.id" class="bd-vp-pkg">
                    <div class="bd-vp-pkg-hd">
                      <span class="bd-paket-source-pill" :title="snap.label && snap.label !== snap.package_name ? `Paket: ${snap.package_name}` : null">{{ snap.label || snap.package_name }}</span>
                      <span v-if="snap.label && snap.label !== snap.package_name" class="bd-vp-type-tag" :title="`Nama paket master: ${snap.package_name}`">{{ snap.package_name }}</span>
                      <span v-if="snap.package_type" class="bd-vp-type-tag">{{ snap.package_type }}</span>
                      <button v-if="!selP.laporanFinalized" class="bd-vp-pkg-del" @click="vpRemovePackage(snap)" :disabled="vpBusy" title="Hapus paket ini dari pasien">Hapus paket</button>
                    </div>

                    <!-- Ringkasan harga paket & diskon -->
                    <div class="bd-vp-summary">
                      <span>Harga paket: <b>{{ fmtRp2(snap.sell_price) }}</b></span>
                      <span>Total komponen: <b>{{ fmtRp2(snap.total_base_price) }}</b></span>
                      <span class="bd-vp-disc">Diskon: <b>{{ fmtRp2(snap.discount_amount) }}</b></span>
                    </div>

                    <table class="bd-tbl" v-if="snap.items.length">
                      <thead><tr><th>Jenis</th><th>Item</th><th>Tarif</th><th>Qty</th><th>Subtotal</th><th></th></tr></thead>
                      <tbody>
                        <tr v-for="it in snap.items" :key="it.id">
                          <td><span class="bd-vp-type" :class="it.item_type.toLowerCase()">{{ it.item_type === 'PROCEDURE' ? 'Tindakan' : it.item_type }}</span></td>
                          <td>{{ it.item_name }}</td>
                          <td>{{ fmtRp2(it.unit_price) }}</td>
                          <td>
                            <input v-if="it.editable && !selP.laporanFinalized" type="number" min="1" class="bd-input bd-input-sm" style="width:54px"
                              :value="it.quantity" @change="vpUpdateQty(it, $event.target.value)" :disabled="vpBusy" />
                            <span v-else>{{ it.quantity }}</span>
                          </td>
                          <td>{{ fmtRp2(it.subtotal) }}</td>
                          <td>
                            <button v-if="it.editable && !selP.laporanFinalized" class="bd-del" @click="vpRemoveItem(it)" :disabled="vpBusy" title="Hapus komponen">
                              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                            <span v-else class="bd-vp-lock" title="IOL diatur di panel IOL">—</span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                    <div v-else class="bd-tbl-empty">Belum ada komponen.</div>

                    <div v-if="!selP.laporanFinalized" class="bd-bhp-add">
                      <select class="bd-select bd-select-sm" v-model="vpForm(snap.id).type" style="width:110px">
                        <option value="PROCEDURE">Tindakan</option>
                        <option value="BHP">BHP</option>
                      </select>
                      <select class="bd-select bd-select-sm" v-model="vpForm(snap.id).itemId" style="flex:1">
                        <option value="">-- Pilih {{ vpForm(snap.id).type === 'BHP' ? 'BHP' : 'Tindakan' }} --</option>
                        <option v-for="o in vpOptionsFor(snap.id)" :key="o.id" :value="o.id">{{ o.code ? `[${o.code}] ` : '' }}{{ o.name }}</option>
                      </select>
                      <input type="number" class="bd-input bd-input-sm" v-model.number="vpForm(snap.id).qty" min="1" style="width:54px" />
                      <button class="bd-btn-add" @click="vpAddItem(snap)" :disabled="vpBusy">+ Tambah</button>
                    </div>
                  </div>

                  <p v-if="visitPackages.length" class="bd-vp-hint">Ubah/tambah/kurang Tindakan & BHP tiap paket. Memengaruhi diskon paket di kwitansi; tagihan komponen tetap dari pemakaian aktual.</p>

                  <!-- Tambah paket (mis. paket anestesi TIVA di samping Phaco) -->
                  <div v-if="!selP.laporanFinalized" class="bd-vp-addpkg">
                    <select class="bd-select bd-select-sm" v-model="vpAddPkgId" style="flex:1">
                      <option value="">-- Tambah paket ke pasien --</option>
                      <option v-for="p in vpAvailablePackages" :key="p.id" :value="p.id">{{ p.code ? `[${p.code}] ` : '' }}{{ p.name }}{{ p.package_type ? ` · ${p.package_type}` : '' }}</option>
                    </select>
                    <select v-if="vpSelectedVariants.length > 1" class="bd-select bd-select-sm" v-model="vpAddTariffId" style="flex:1" title="Varian / Nama Paket Penjamin">
                      <option v-for="v in vpSelectedVariants" :key="v.tariff_id" :value="v.tariff_id">{{ v.display_name || 'Varian' }} — {{ fmtRp2(v.sell_price) }}</option>
                    </select>
                    <button class="bd-btn-add" @click="vpAddPackage" :disabled="vpBusy || !vpAddPkgId">+ Tambah Paket</button>
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
                  <template v-if="selP.isPhaco">
                    <!-- Pilih IOL dari master (wajib utk simpan ke backend / billing) -->
                    <div class="bd-iol-field" style="margin-bottom:8px">
                      <label class="bd-label">Pilih IOL dari Master</label>
                      <div class="bd-combo-wrap">
                        <input
                          class="bd-input bd-combo-input"
                          v-model="iolSearch"
                          placeholder="Cari merk / model / serial IOL…"
                          :disabled="selP.laporanFinalized"
                        />
                        <div v-if="iolSearch.trim() && !selP.laporanFinalized" class="bd-combo-dropdown">
                          <div v-for="it in filteredIol" :key="it.id" class="bd-combo-option" @mousedown.prevent="pickIolMaster(it)">
                            <span class="bd-combo-name">{{ it.brand }} {{ it.model }}</span>
                            <span class="bd-combo-role">{{ it.power != null ? `${it.power} D` : '' }}{{ it.serial_number ? ` · ${it.serial_number}` : '' }}</span>
                          </div>
                          <div v-if="!filteredIol.length" class="bd-combo-empty">Tidak ada hasil</div>
                        </div>
                      </div>
                      <span v-if="selP.iolDipasang.itemId" class="bd-paket-source-pill" style="margin-top:6px;display:inline-block">
                        Dipilih dari master · ID {{ String(selP.iolDipasang.itemId).slice(0, 8) }}
                      </span>
                    </div>

                    <!-- Tombol Scan UDI (DataMatrix) → auto-isi serial/lot/expiry + cocokkan master -->
                    <div style="margin:8px 0">
                      <button class="bd-btn-scan" :disabled="selP.laporanFinalized" @click="openIolScan">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><line x1="14" y1="14" x2="14" y2="21"/><line x1="21" y1="14" x2="21" y2="21"/></svg>
                        Scan IOL (UDI)
                      </button>
                      <span class="bd-disp-hint" style="margin-left:8px">Scan label lensa, atau pilih dari master & isi manual.</span>
                    </div>

                    <div class="bd-iol-grid">
                      <div class="bd-iol-field">
                        <label class="bd-label">Merk / Nama</label>
                        <input class="bd-input" v-model="selP.iolDipasang.merk" :disabled="selP.laporanFinalized" />
                      </div>
                      <div class="bd-iol-field">
                        <label class="bd-label">Power (D)</label>
                        <input class="bd-input" v-model="selP.iolDipasang.power" :disabled="selP.laporanFinalized" />
                      </div>
                      <div class="bd-iol-field">
                        <label class="bd-label">Model / Series</label>
                        <input class="bd-input" v-model="selP.iolDipasang.series" :disabled="selP.laporanFinalized" />
                      </div>
                      <div class="bd-iol-field">
                        <label class="bd-label">Mata</label>
                        <select class="bd-select" v-model="selP.iolDipasang.eyeSide" :disabled="selP.laporanFinalized">
                          <option value="OD">OD (Kanan)</option>
                          <option value="OS">OS (Kiri)</option>
                        </select>
                      </div>
                      <div class="bd-iol-field">
                        <label class="bd-label">Lot No.</label>
                        <input class="bd-input" v-model="selP.iolDipasang.lot" placeholder="dari scan / label" :disabled="selP.laporanFinalized" />
                      </div>
                      <div class="bd-iol-field">
                        <label class="bd-label">Serial No.</label>
                        <input class="bd-input" v-model="selP.iolDipasang.serial" placeholder="dari scan / label" :disabled="selP.laporanFinalized" />
                      </div>
                    </div>

                    <div class="bd-iol-field" style="margin-top:8px">
                      <button
                        class="bd-btn-add"
                        :disabled="selP.laporanFinalized || savingIol || !selP.recordId || (!selP.iolDipasang.itemId && !selP.iolDipasang.merk)"
                        @click="simpanIolTerpasang"
                      >
                        {{ savingIol ? 'Menyimpan…' : 'Catat IOL Terpasang' }}
                      </button>
                      <span v-if="!selP.recordId" class="bd-disp-hint" style="margin-left:8px">Mulai & Time Out operasi dulu sebelum mencatat IOL.</span>
                    </div>

                    <!-- Daftar IOL terpasang (sumber: server) -->
                    <div v-if="iolUsages.length" class="bd-iol-list">
                      <table class="bd-tbl">
                        <thead>
                          <tr><th>Mata</th><th>Merk / Model</th><th class="num">Power</th><th>Lot</th><th>Serial</th><th></th></tr>
                        </thead>
                        <tbody>
                          <tr v-for="u in iolUsages" :key="u.id">
                            <td><span class="bd-eye-pill">{{ u.eye_side }}</span></td>
                            <td>{{ [u.brand, u.model].filter(Boolean).join(' ') || '—' }}</td>
                            <td class="num">{{ u.power != null ? `${u.power} D` : '—' }}</td>
                            <td>{{ u.lot_number || '—' }}</td>
                            <td>{{ u.serial_number || '—' }}</td>
                            <td>
                              <button v-if="!selP.laporanFinalized" class="bd-iol-del" title="Hapus" @click="hapusIolUsage(u.id)">✕</button>
                            </td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </template>

                  <div class="bd-iol-field" style="margin-top:8px">
                    <label class="bd-label">Jenis Anestesi</label>
                    <select class="bd-select" v-model="selP.anestesi" :disabled="selP.laporanFinalized">
                      <option>Topikal</option><option>Lokal</option><option>Sub-Tenon</option><option>Umum</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>

            <!-- Anestesi lengkap (wizard RM 5.2 + monitoring durante) — hanya bila operasi
                 pakai anestesi (GA / ada anestesiolog). Wizard mengatur RBAC sendiri
                 (anestesi.read tampil, anestesi.write edit) & memuat panel monitoring di hal 3. -->
            <div v-if="hasAnesthesia && selP.recordId" class="bd-card bd-card-full">
              <div class="bd-card-hd">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M2 12h20"/></svg>
                Laporan & Monitoring Anestesi {{ selP.anestesi }}{{ selP.tim.anestesi ? ' — ' + selP.tim.anestesi : '' }}
              </div>
              <div class="bd-card-bd">
                <p v-if="!canEditAnesthesia" class="bd-rolehint">Laporan & monitoring anestesi diisi oleh dokter anestesi; tim lain hanya melihat.</p>
                <AnesthesiaReportWizard
                  :record-id="selP.recordId"
                  :visit-id="selP.visitId"
                  :disabled="selP.laporanFinalized"
                />
              </div>
            </div>
            <div v-else-if="hasAnesthesia && !selP.recordId" class="bd-card bd-card-full">
              <div class="bd-card-bd">
                <p class="bd-anes-hint">Mulai operasi terlebih dahulu untuk mengisi Laporan & Monitoring Anestesi.</p>
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

            <!-- Kartu "Pemakaian Alat Medis" dihapus: tarif tidak ditagih dari sini
                 (billing alat via Buku Tarif / paket, bukan log pemakaian). -->

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
                <div class="bd-tab-save">
                  <span v-if="!selP.recordId" class="bd-rolehint">Catatan tersimpan setelah operasi dimulai.</span>
                  <button class="bd-btn-add" :disabled="selP.laporanFinalized || !selP.recordId" @click="saveOperationReport(false)">
                    Simpan Catatan
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- ── TAB 3: Laporan Operasi ────────────────────────── -->
          <div v-else-if="tab === 'laporan'" class="bd-laporan">
            <div v-if="selP.laporanFinalized" class="bd-finalized-banner">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              Laporan terkunci (read-only)
            </div>
            <div v-else-if="selP.operationDone" class="bd-status-info bd-status-done" style="margin-bottom:14px">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              Operasi selesai — pasien diteruskan. Laporan masih bisa diperbaiki; ditinjau &amp; dikunci dokter di TTD Dokumen.
            </div>

            <!-- Form Laporan Operasi RM (10.1 / 2.3 / 2.2) dipindah ke modal via tombol
                 pojok tab ("Dokumen RM") agar panel laporan tetap ringkas. -->

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
                    <div class="bd-dx-chip" :class="!selP.diagnosa && 'bd-dx-chip-empty'">{{ selP.diagnosa ? (selP.diagnosa + (selP.diagnosaNama ? ' — ' + selP.diagnosaNama : '')) : 'Belum ada diagnosis dari dokter' }}</div>
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
                <div class="bd-iol-field" style="margin-top:12px">
                  <label class="bd-label">Estimasi Perdarahan (EBL)</label>
                  <input class="bd-input" v-model="selP.estimatedBloodLoss" :disabled="selP.laporanFinalized" placeholder="mis. minimal / 5 cc" />
                </div>

                <!-- Detail vitrektomi — hanya bila prosedur vitrektomi -->
                <div v-if="isVitrek" class="bd-iol-field" style="margin-top:12px">
                  <label class="bd-label">Detail Vitrektomi</label>
                  <div class="bd-iol-grid">
                    <div class="bd-iol-field">
                      <label class="bd-label">Tamponade</label>
                      <select class="bd-select" v-model="selP.vitrek.tamponade" :disabled="selP.laporanFinalized">
                        <option>None</option><option>SF6</option><option>C3F8</option><option>Silicone Oil</option><option>Air</option>
                      </select>
                    </div>
                    <label class="bd-chk-item"><input type="checkbox" v-model="selP.vitrek.endolaser" :disabled="selP.laporanFinalized" /><span>Endolaser</span></label>
                    <label class="bd-chk-item"><input type="checkbox" v-model="selP.vitrek.membrane_peeling" :disabled="selP.laporanFinalized" /><span>Membrane peeling</span></label>
                  </div>
                </div>

              </div>
            </div>

            <!-- Implan IOL terpasang — auto dari iolUsages (sumber kebenaran, masuk laporan PAB) -->
            <div v-if="iolUsages.length" class="bd-card bd-card-full">
              <div class="bd-card-hd">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                Implan IOL Terpasang (otomatis masuk laporan)
              </div>
              <div class="bd-card-bd">
                <table class="bd-tbl">
                  <thead><tr><th>Mata</th><th>Merk / Model</th><th class="num">Power</th><th>Lot</th><th>Serial</th></tr></thead>
                  <tbody>
                    <tr v-for="u in iolUsages" :key="u.id">
                      <td>{{ u.eye_side }}</td>
                      <td>{{ u.brand }} {{ u.model }}</td>
                      <td class="num">{{ u.power != null ? u.power + ' D' : '—' }}</td>
                      <td>{{ u.lot_number || '—' }}</td>
                      <td>{{ u.serial_number || '—' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="bd-laporan-actions">
              <!-- "Cetak Laporan" (stub toast) dihapus — cetak resmi via Dokumen RM
                   (RM 2.2/10.1/2.3 → tab Preview/Cetak) dengan layout berkop. -->
              <button
                v-if="!selP.laporanFinalized"
                class="bd-btn-add"
                :disabled="!selP.recordId || !canEditReport"
                @click="saveOperationReport(false)"
              >
                Simpan Laporan
              </button>
              <span v-if="!selP.operationDone && !selP.laporanFinalized" class="bd-disp-hint" style="margin-top:0">
                Laporan boleh diisi belakangan. Disposisi &amp; tombol <b>Selesai Operasi</b> ada di tab <b>Pasca-Bedah</b>.
              </span>
              <span v-if="selP.operationDone || selP.laporanFinalized" class="bd-finalized-tag">Operasi Selesai</span>
            </div>
          </div>

          <!-- ── TAB 4: Pasca-Bedah ────────────────────────────── -->
          <div v-else-if="tab === 'pascabedah'" class="bd-pascabedah">
            <!-- 🔴 SIGN OUT (WHO gerbang 3 — sebelum keluar OK) -->
            <div class="bd-card bd-card-full bd-signout-card">
              <div class="bd-card-hd">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                Sign Out — Sebelum Pasien Keluar Kamar Operasi
                <span :class="['bd-chk-badge', signOutComplete ? 'bd-chk-ok' : 'bd-chk-no']">
                  {{ signOutComplete ? 'Lengkap' : (selP.bypass?.sign_out ? 'Dilewati' : 'Belum lengkap') }}
                </span>
              </div>
              <div class="bd-card-bd">
                <p v-if="!canEditChecklist" class="bd-rolehint">Dipimpin perawat sirkuler.</p>
                <label class="bd-chk-item"><input type="checkbox" v-model="selP.signOut.prosedur_dikonfirmasi" :disabled="!canEditChecklist" /><span :class="selP.signOut.prosedur_dikonfirmasi && 'bd-chk-done'">Nama prosedur yang dikerjakan dikonfirmasi</span></label>
                <label class="bd-chk-item"><input type="checkbox" v-model="selP.signOut.hitung_kasa" :disabled="!canEditChecklist" /><span :class="selP.signOut.hitung_kasa && 'bd-chk-done'">Hitung kasa lengkap & cocok</span></label>
                <label class="bd-chk-item"><input type="checkbox" v-model="selP.signOut.hitung_instrumen" :disabled="!canEditChecklist" /><span :class="selP.signOut.hitung_instrumen && 'bd-chk-done'">Hitung instrumen & jarum lengkap & cocok</span></label>
                <label class="bd-chk-item"><input type="checkbox" v-model="selP.signOut.spesimen" :disabled="!canEditChecklist" /><span :class="selP.signOut.spesimen && 'bd-chk-done'">Spesimen dilabeli (nama pasien) — atau tidak ada</span></label>
                <label class="bd-chk-item">
                  <input type="checkbox" v-model="selP.signOut.iol_dicatat" :disabled="!canEditChecklist" />
                  <span :class="selP.signOut.iol_dicatat && 'bd-chk-done'">Nomor seri IOL/implan dicatat ({{ iolUsages.length }} lensa) — atau tidak ada implan</span>
                </label>
                <label class="bd-chk-item"><input type="checkbox" v-model="selP.signOut.rencana_pemulihan" :disabled="!canEditChecklist" /><span :class="selP.signOut.rencana_pemulihan && 'bd-chk-done'">Rencana pemulihan & masalah utama disampaikan ke tim PACU</span></label>
                <div v-if="selP.isPhaco && !iolUsages.length" class="bd-iol-reminder" style="margin-top:8px">⚠ Operasi katarak tanpa IOL tercatat — pastikan lensa dicatat di tab Intraoperatif.</div>
                <div class="bd-timeout-actions">
                  <button class="bd-btn-add" :disabled="!canEditChecklist || !signOutComplete || busyChecklist" @click="saveChecklistPhase('sign_out')">{{ busyChecklist ? 'Menyimpan…' : 'Simpan Sign Out' }}</button>
                  <button class="bd-btn-bypass" :disabled="!canEditChecklist" @click="bypassChecklistPhase('sign_out')">Lewati (darurat)</button>
                  <span v-if="selP.bypass?.sign_out" class="bd-bypass-note">⚠ Dilewati: {{ selP.bypass.sign_out }}</span>
                </div>
              </div>
            </div>

            <!-- Skor Pemulihan Aldrete (PACU) -->
            <div class="bd-card bd-card-full">
              <div class="bd-card-hd">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                Skor Pemulihan (Aldrete)
                <span :class="['bd-chk-badge', aldreteTotal >= 9 ? 'bd-chk-ok' : 'bd-chk-no']">{{ aldreteTotal }}/10 {{ aldreteTotal >= 9 ? '· Layak transfer' : '' }}</span>
              </div>
              <div class="bd-card-bd">
                <div class="bd-aldrete-grid">
                  <div v-for="cmp in aldreteComponents" :key="cmp.key" class="bd-iol-field">
                    <label class="bd-label">{{ cmp.label }}</label>
                    <select class="bd-select" v-model.number="selP.aldrete[cmp.key]" :disabled="selP.laporanFinalized">
                      <option :value="0">0 — {{ cmp.opts[0] }}</option>
                      <option :value="1">1 — {{ cmp.opts[1] }}</option>
                      <option :value="2">2 — {{ cmp.opts[2] }}</option>
                    </select>
                  </div>
                  <div class="bd-iol-field">
                    <label class="bd-label">Skala Nyeri (0–10)</label>
                    <input type="number" min="0" max="10" class="bd-input" v-model.number="selP.painScore" :disabled="selP.laporanFinalized" />
                  </div>
                </div>
                <div class="bd-timeout-actions">
                  <button class="bd-btn-add" :disabled="selP.laporanFinalized || !selP.recordId" @click="saveRecovery">Simpan Skor Pemulihan</button>
                  <span v-if="selP.recoverySaved" class="bd-bypass-note" style="color:var(--bd-ok,#16a34a)">✓ Tersimpan</span>
                </div>
              </div>
            </div>

            <div class="bd-2col">
              <!-- Instruksi Post-Op -->
              <div class="bd-card">
                <div class="bd-card-hd">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                  Instruksi Pasca Operasi
                </div>
                <div class="bd-card-bd">
                  <label v-for="(inst, i) in instruksiList" :key="i" class="bd-chk-item">
                    <input type="checkbox" v-model="selP.instruksi[i]" :disabled="selP.laporanFinalized" />
                    <span :class="selP.instruksi[i] && 'bd-chk-done'">{{ inst }}</span>
                  </label>
                  <div v-if="!selP.laporanFinalized" class="bd-timeout-actions">
                    <button class="bd-btn-add" :disabled="!selP.recordId || savingInstruksi" @click="savePostOpInstructions(false)">
                      {{ savingInstruksi ? 'Menyimpan…' : 'Simpan Instruksi' }}
                    </button>
                    <span v-if="!selP.recordId" class="bd-disp-hint">Mulai operasi dulu untuk menyimpan instruksi.</span>
                  </div>
                  <p v-else class="bd-rolehint">Instruksi tersimpan & dikunci bersama laporan.</p>
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
                  <!-- Paket Obat: terapkan template + kelola (gate bedah.write) -->
                  <div v-if="!selP.resepSent && canEditReport" class="bd-paketobat-bar">
                    <select class="bd-select bd-select-sm" v-model="paketPickId" style="flex:1;min-width:160px">
                      <option value="">— Pilih paket obat —</option>
                      <option v-for="p in paketObatList" :key="p.id" :value="p.id">
                        {{ p.name }}{{ p.category ? ` (${p.category})` : '' }} · {{ (p.items?.length ?? 0) }} obat
                      </option>
                    </select>
                    <button class="bd-btn-add" :disabled="!paketPickId" @click="applyPaketObat">Terapkan Paket</button>
                    <button class="bd-btn-bypass" @click="openPaketModal">Kelola Paket</button>
                  </div>
                  <p v-if="!selP.resepSent" class="bd-disp-hint" :class="hasVisitPaket ? 'bd-hint-ok' : ''">
                    <template v-if="hasVisitPaket">Pasien <b>berpaket</b> → obat paket tetap <b>muncul di kwitansi</b> namun <b>dinetralkan diskon paket</b> (tidak menambah total). Obat manual tetap ditagih.</template>
                    <template v-else>Pasien <b>tanpa paket</b> → semua obat ditagih sebagai <b>obat pulang</b> di kwitansi.</template>
                  </p>

                  <table class="bd-tbl bd-tbl-sm" v-if="selP.obatPasca.length">
                    <thead><tr><th>Nama Obat</th><th>Jml</th><th>Dosis</th><th>Frek.</th><th>Durasi</th><th>Rute</th><th></th></tr></thead>
                    <tbody>
                      <tr v-for="(o, i) in selP.obatPasca" :key="`${o.medication_id}-${i}`">
                        <td>{{ o.nama }} <span v-if="o.fromPaket && hasVisitPaket" class="bd-eye-pill" title="Termasuk harga paket">paket</span></td><td>{{ o.jumlah }}</td><td>{{ o.dosis }}</td><td>{{ o.freq }}</td><td>{{ o.dur }}</td><td>{{ o.rute }}</td>
                        <td><button class="bd-del" @click="removeObat(i)" :disabled="selP.resepSent" aria-label="Hapus obat" title="Hapus obat">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button></td>
                      </tr>
                    </tbody>
                  </table>
                  <div v-else class="bd-tbl-empty">Belum ada obat ditambahkan</div>

                  <div v-if="!selP.resepSent" class="bd-obat-form">
                    <!-- Pilih obat dari master (wajib medication_id utk kirim ke Farmasi) -->
                    <div class="bd-combo-wrap" style="flex:2;min-width:200px">
                      <input
                        class="bd-input bd-combo-input"
                        :value="newObat.medication_id ? newObat.nama : obatSearchPasca"
                        placeholder="Cari obat dari farmasi…"
                        @input="e => { obatSearchPasca = e.target.value; newObat.medication_id = ''; newObat.nama = '' }"
                      />
                      <div v-if="obatSearchPasca.trim() && !newObat.medication_id" class="bd-combo-dropdown">
                        <div v-for="o in filteredObatPasca" :key="o.id" class="bd-combo-option" @mousedown.prevent="pickObatMaster(o)">
                          <span class="bd-combo-name">{{ o.name }}<span v-if="o.is_active === false" class="rx-inactive-badge" title="Obat nonaktif">nonaktif</span></span>
                          <span class="bd-combo-role">{{ o.form || o.golongan || '' }}{{ o.unit ? ` · ${o.unit}` : '' }}{{ Number(o.stock) > 0 ? ` · stok ${o.stock}` : ' · stok 0' }}</span>
                        </div>
                        <div v-if="!filteredObatPasca.length" class="bd-combo-empty">Tidak ada hasil</div>
                      </div>
                    </div>
                    <input class="bd-input bd-input-sm" v-model="newObat.dosis" placeholder="Dosis" style="flex:1" />
                    <input type="number" class="bd-input bd-input-sm" v-model.number="newObat.jumlah" min="1" title="Jumlah" placeholder="Jml" style="width:60px" />
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
                    :disabled="sendingResep"
                    @click="kirimResep"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    {{ sendingResep ? 'Mengirim…' : 'Kirim ke Farmasi' }}
                  </button>
                </div>
              </div>
            </div>

            <!-- Disposisi & Selesai Operasi — AKSI TERAKHIR: rutekan pasien SETELAH obat
                 terisi/terkirim (cegah kasir menagih sebelum resep ada → obat bocor). -->
            <div class="bd-card bd-card-full">
              <div class="bd-card-hd">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Disposisi &amp; Selesai Operasi
              </div>
              <div class="bd-card-bd">
                <div v-if="selP.operationDone || selP.laporanFinalized" class="bd-status-info bd-status-done" style="margin-bottom:12px">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                  Operasi selesai — pasien diteruskan. Laporan masih bisa diperbaiki &amp; dikunci dokter di TTD Dokumen.
                </div>
                <div class="bd-iol-field">
                  <label class="bd-label">Disposisi Pasca-Operasi</label>
                  <!-- Adaptif: pasien dari RANAP kembali ke kamar (lanjut rawatan/HCU);
                       pasien rawat jalan/pre-op → Pulang atau Rawat Inap baru. -->
                  <select v-if="isFromRanap" class="bd-select" v-model="selP.postOpDisposition" :disabled="selP.operationDone || selP.laporanFinalized">
                    <option value="LANJUT_RANAP">Kembali ke Rawat Inap (lanjut rawatan di kamar)</option>
                    <option value="HCU">Pindah ke HCU (perawatan intensif)</option>
                  </select>
                  <select v-else class="bd-select" v-model="selP.postOpDisposition" :disabled="selP.operationDone || selP.laporanFinalized">
                    <option value="PULANG">Pulang (lanjut ke Kasir)</option>
                    <option value="RAWAT_INAP">Rawat Inap (ke papan Menunggu Kamar)</option>
                  </select>
                  <span v-if="selP.postOpDisposition === 'RAWAT_INAP'" class="bd-disp-hint">
                    Pasien akan masuk papan "Menunggu Kamar" Rawat Inap setelah Selesai Operasi. Petugas ranap memilih bed.
                  </span>
                  <span v-else-if="selP.postOpDisposition === 'LANJUT_RANAP'" class="bd-disp-hint">
                    Pasien kembali ke kamar rawat inap (bed masih ditahan) untuk melanjutkan rawatan.
                  </span>
                  <span v-else-if="selP.postOpDisposition === 'HCU'" class="bd-disp-hint">
                    Pasien kembali ke alur rawat inap &amp; ditandai butuh HCU. Petugas RANAP memindahkan ke bed HCU (transfer bed).
                  </span>
                </div>
                <div class="bd-laporan-actions">
                  <button
                    v-if="!selP.operationDone && !selP.laporanFinalized"
                    class="bd-btn-finalisasi"
                    :disabled="!selP.timOut || busyOp || !canEditReport"
                    :title="!selP.timOut ? 'Lakukan Time Out dulu di tab Intraoperatif' : ''"
                    @click="showFinalModal = true"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    Selesai Operasi
                  </button>
                  <span v-if="!selP.timOut && !selP.operationDone && !selP.laporanFinalized" class="bd-disp-hint" style="margin-top:0">
                    Lakukan <b>Time Out</b> dulu di tab Intraoperatif sebelum menyelesaikan operasi.
                  </span>
                  <span v-else-if="selP.postOpDisposition === 'PULANG' && selP.obatPasca.length && !selP.resepSent && !selP.operationDone" class="bd-disp-hint" style="margin-top:0">
                    Obat pasca-bedah akan otomatis dikirim ke Farmasi saat Selesai Operasi.
                  </span>
                  <span v-else-if="selP.postOpDisposition !== 'PULANG' && selP.obatPasca.length && !selP.resepSent && !selP.operationDone" class="bd-disp-hint" style="margin-top:0">
                    Pasien kembali ke rawat inap → obat ditagih saat pulang. Klik "Kirim ke Farmasi" di atas bila ingin obat diserahkan dari loket.
                  </span>
                  <span v-if="selP.operationDone || selP.laporanFinalized" class="bd-finalized-tag">Operasi Selesai</span>
                </div>
              </div>
            </div>
          </div>

        </div>
      </template>
      </section>
    </div>

    <!-- ── MODAL: Dokumen RM (Checklist / Laporan Operasi) ───────── -->
    <div v-if="showRmDocsModal && selP" class="bd-overlay" @click.self="showRmDocsModal = false">
      <div class="bd-modal bd-modal-wide bd-modal-docs">
        <div class="bd-docs-hd">
          <h3>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            {{ tab === 'prabedah' ? 'Checklist Kesiapan Bedah' : 'Dokumen Laporan Operasi' }}
          </h3>
          <button class="bd-docs-close" @click="showRmDocsModal = false" aria-label="Tutup">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
        <div class="bd-docs-body">
          <!-- Pra-Bedah → Checklist Kesiapan Bedah (RM 2.0) -->
          <FormDocsBrowser
            v-if="tab === 'prabedah'"
            :visit-id="selP.visitId"
            station="bedah"
            :sections="[{ key: 'checklist_kesiapan', label: 'Checklist Kesiapan Bedah' }]"
          />

          <!-- Laporan → daftar ringkas; user tinggal pilih dokumen yang dipakai.
               Teknik/temuan/identitas operasi terisi otomatis dari data BedahView. -->
          <template v-else>
            <p class="bd-rolehint" style="margin:0 0 10px">Pilih dokumen laporan yang akan diisi. Identitas operasi, teknik &amp; temuan terisi otomatis dari data BedahView.</p>
            <FormDocsBrowser
              :visit-id="selP.visitId"
              station="bedah"
              :show-toolbar="false"
              :sections="[
                { key: 'laporan_pembedahan',   label: 'Laporan Pembedahan (RM 2.2) — semua operasi' },
                { key: 'laporan_vitreoretina', label: 'Laporan Operasi Vitreo Retina (RM 10.1)' },
                { key: 'catatan_operasi',      label: 'Catatan Operasi Katarak (RM 2.3)' },
              ]"
            />
          </template>
        </div>
      </div>
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
            <!-- Visus/IOP hanya tampil bila ada datanya (hindari '—/—' menyesatkan). -->
            <div v-if="selP?.visusOD || selP?.visusOS" class="bd-mfield">
              <span class="bd-mlabel">Visus Pre-op</span>
              <span class="bd-mval">OD: {{ selP?.visusOD || '—' }} / OS: {{ selP?.visusOS || '—' }}</span>
            </div>
            <div v-if="selP?.iopOD || selP?.iopOS" class="bd-mfield">
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
        <h3>Selesaikan Operasi?</h3>
        <p>Pasien diteruskan ke <strong>Farmasi/Kasir</strong> sesuai disposisi (setelah obat pasca-bedah). Laporan <strong>tidak dikunci</strong> — masih bisa diperbaiki, lalu ditinjau &amp; ditandatangani dokter di <strong>TTD Dokumen</strong>.</p>
        <div class="bd-modal-actions">
          <button class="bd-btn-sec" :disabled="busyOp" @click="showFinalModal = false">Batal</button>
          <button class="bd-btn-finalisasi-confirm" :disabled="busyOp" @click="doFinalisasi">{{ busyOp ? 'Memproses…' : 'Selesai Operasi' }}</button>
        </div>
      </div>
    </div>

    <!-- ── MODAL: Kelola Paket Obat Pasca-Bedah ──────────────────────── -->
    <div v-if="showPaketModal" class="bd-overlay" @click.self="showPaketModal = false">
      <div class="bd-modal bd-modal-wide" style="text-align:left;max-width:760px">
        <h3 style="text-align:left">Kelola Paket Obat Pasca-Bedah</h3>
        <p class="bd-rolehint" style="margin-top:-6px">Template resep rutin lintas operasi. Saat diterapkan ke pasien berpaket, obatnya terserap ke harga paket.</p>

        <div class="bd-paket-modal-grid">
          <!-- Daftar paket tersimpan -->
          <div class="bd-paket-list">
            <div class="bd-paket-list-hd">
              <strong>Daftar Paket</strong>
              <button class="bd-btn-add" @click="resetPaketForm">+ Paket Baru</button>
            </div>
            <div v-if="!paketObatList.length" class="bd-tbl-empty">Belum ada paket</div>
            <button
              v-for="p in paketObatList" :key="p.id"
              class="bd-paket-list-item" :class="{ active: paketForm.id === p.id }"
              @click="editPaket(p)"
            >
              <div>
                <div class="bd-paket-list-name">{{ p.name }}</div>
                <div class="bd-paket-list-sub">{{ p.category || 'Umum' }} · {{ (p.items?.length ?? 0) }} obat</div>
              </div>
              <span class="bd-del" @click.stop="deletePaket(p)" title="Hapus paket">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </span>
            </button>
          </div>

          <!-- Editor paket -->
          <div class="bd-paket-editor">
            <div class="bd-iol-grid">
              <div class="bd-iol-field"><label class="bd-label">Nama Paket</label><input class="bd-input" v-model="paketForm.name" placeholder="mis. Pasca Phaco" /></div>
              <div class="bd-iol-field"><label class="bd-label">Kategori (jenis operasi)</label><input class="bd-input" v-model="paketForm.category" placeholder="mis. Katarak (opsional)" /></div>
            </div>

            <table class="bd-tbl bd-tbl-sm" v-if="paketForm.items.length" style="margin-top:10px">
              <thead><tr><th>Obat</th><th>Jml</th><th>Dosis</th><th>Frek.</th><th>Durasi</th><th>Rute</th><th></th></tr></thead>
              <tbody>
                <tr v-for="(o, i) in paketForm.items" :key="`${o.medication_id}-${i}`">
                  <td>{{ o.nama }}</td><td>{{ o.jumlah }}</td><td>{{ o.dosis }}</td><td>{{ o.freq }}</td><td>{{ o.dur }}</td><td>{{ o.rute }}</td>
                  <td><button class="bd-del" @click="removePaketItem(i)" title="Hapus">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button></td>
                </tr>
              </tbody>
            </table>
            <div v-else class="bd-tbl-empty">Belum ada obat di paket ini</div>

            <!-- Tambah obat ke paket (autocomplete master + stok Farmasi) -->
            <div class="bd-obat-form" style="margin-top:8px">
              <div class="bd-combo-wrap" style="flex:2;min-width:200px">
                <input
                  class="bd-input bd-combo-input"
                  :value="paketNewItem.medication_id ? paketNewItem.nama : paketMedSearch"
                  placeholder="Cari obat dari master…"
                  @input="e => { paketMedSearch = e.target.value; paketNewItem.medication_id = ''; paketNewItem.nama = '' }"
                />
                <div v-if="paketMedSearch.trim() && !paketNewItem.medication_id" class="bd-combo-dropdown">
                  <div v-for="o in paketMedFiltered" :key="o.id" class="bd-combo-option" @mousedown.prevent="pickPaketMed(o)">
                    <span class="bd-combo-name">{{ o.name }}</span>
                    <span class="bd-combo-role">{{ o.form || o.golongan || '' }} · stok {{ o.stock ?? 0 }}{{ o.unit ? ` ${o.unit}` : '' }}</span>
                  </div>
                  <div v-if="!paketMedFiltered.length" class="bd-combo-empty">Tidak ada hasil</div>
                </div>
              </div>
              <input class="bd-input bd-input-sm" v-model="paketNewItem.dosis" placeholder="Dosis" style="flex:1" />
              <input type="number" class="bd-input bd-input-sm" v-model.number="paketNewItem.jumlah" min="1" title="Jumlah" placeholder="Jml" style="width:60px" />
              <select class="bd-select bd-select-sm" v-model="paketNewItem.freq">
                <option>1×/hari</option><option>2×/hari</option><option>3×/hari</option><option>4×/hari</option><option>6×/hari</option>
              </select>
              <select class="bd-select bd-select-sm" v-model="paketNewItem.dur">
                <option>3 hari</option><option>5 hari</option><option>7 hari</option><option>10 hari</option><option>14 hari</option><option>1 bulan</option>
              </select>
              <input class="bd-input bd-input-sm" v-model="paketNewItem.rute" placeholder="Rute" />
              <button class="bd-btn-add" @click="addPaketItem">+ Tambah</button>
            </div>

            <div class="bd-modal-actions" style="justify-content:flex-end;margin-top:16px">
              <button class="bd-btn-sec" @click="showPaketModal = false">Tutup</button>
              <button class="bd-btn-finalisasi-confirm" :disabled="paketBusy" @click="savePaket">
                {{ paketBusy ? 'Menyimpan…' : (paketForm.id ? 'Simpan Perubahan' : 'Buat Paket') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── SCAN IOL (DataMatrix/UDI) ───────────────────────────────── -->
    <ScanBarcodeModal
      v-model:open="iolScan.open"
      title="Scan IOL (UDI)"
      hint="Arahkan kamera ke kode DataMatrix di label lensa, atau ketik manual."
      @decoded="onIolScanDecoded"
    />

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
.main-grid { display: grid; grid-template-columns: 340px 1fr; gap: 1rem; align-items: start; transition: grid-template-columns 0.22s ease; }

.col-queue { min-width: 0; }
.col-work { min-width: 0; display: flex; flex-direction: column; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }

/* ── Panel antrean dinamis: ciut → rail tipis ───────────────────── */
.bedah.q-collapsed .main-grid { grid-template-columns: 56px 1fr; }
.queue-rail { display: none; }
.bedah.q-collapsed .col-queue > .card { display: none; }
.bedah.q-collapsed .col-queue .queue-rail {
  display: flex; flex-direction: column; align-items: center; gap: 10px;
  width: 44px; min-height: 132px; margin-top: 0.15rem; padding: 14px 4px;
  background: var(--bc); border: 1px solid var(--gb); border-radius: 12px;
  cursor: pointer; color: var(--tm); transition: all 0.13s;
}
.queue-rail:hover { border-color: var(--ga); color: var(--ga); }
.queue-rail svg { width: 16px; height: 16px; }
.queue-rail-count { font-size: 14px; font-weight: 700; color: var(--ga); font-variant-numeric: tabular-nums; }
.queue-rail-txt { writing-mode: vertical-rl; text-orientation: mixed; font-size: 11px; font-weight: 600; letter-spacing: 0.05em; }

/* Tombol ciutkan di header antrean */
.head-actions { display: flex; align-items: center; gap: 6px; }
.panel-collapse { width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--gb); border-radius: 7px; background: var(--bs); color: var(--tu); cursor: pointer; transition: all 0.13s; flex-shrink: 0; }
.panel-collapse:hover { border-color: var(--ga); color: var(--ga); }
.panel-collapse svg { width: 14px; height: 14px; }

/* Konten kerja dipusatkan agar lega & tak melebar di layar ultra-wide */
.bd-prabedah, .bd-intraop, .bd-laporan, .bd-pascabedah { max-width: 1340px; margin-inline: auto; }

/* Responsif: stack 1 kolom di layar sempit (tanpa scroll horizontal) */
@media (max-width: 1180px) {
  .main-grid, .bedah.q-collapsed .main-grid { grid-template-columns: 1fr; }
  .queue-rail, .panel-collapse { display: none !important; }
  .bedah.q-collapsed .col-queue > .card { display: block; }
  .queue-scroll { max-height: 440px; }
}

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

/* Search */
.q-search-wrap { margin-bottom: 0.5rem; }
.q-search { width: 100%; height: 30px; font-size: 11.5px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 10px; background: var(--bs); font-family: 'Inter', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.q-search:focus { border-color: var(--ga); background: #fff; }

.empty-section { text-align: center; padding: 0.75rem 1rem; font-size: 11px; color: var(--th); background: var(--bi); border-radius: 7px; border: 1px dashed var(--gb); }

/* Queue item (PerawatView style) */
.q-item { display: flex; gap: 8px; padding: 8px 10px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; margin-bottom: 5px; cursor: pointer; transition: all 0.14s; width: 100%; text-align: left; font-family: 'Inter', sans-serif; flex-wrap: wrap; }
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
.q-prosedur { font-size: 11px; color: var(--td); margin-top: 3px; font-weight: 500; }
.q-tags { display: flex; gap: 3px; margin-top: 3px; flex-wrap: wrap; }

/* Pill icon (PerawatView style) */
.pill-icon { width: 8px; height: 8px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; flex-shrink: 0; }

.q-actions { display: flex; gap: 4px; margin-top: 5px; padding-top: 5px; border-top: 1px dashed var(--gb); width: 100%; }
.q-act-btn { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; font-size: 10px; font-weight: 600; border-radius: 5px; border: 1px solid; cursor: pointer; font-family: 'Inter',sans-serif; transition: background .12s, color .12s, border-color .12s; background: none; user-select: none; }
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
.pill-ruang     { background: var(--bc); color: var(--td); border: 1px solid var(--gb); }
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
.bd-banner-name { font-size: 18px; font-weight: 800; font-family: 'Space Grotesk', serif; }
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
.bd-tab:hover { color: var(--td); }
.bd-tab-a { color: var(--td); border-bottom-color: var(--ga); }
/* Tombol Dokumen RM di pojok kanan tab strip */
.bd-tab-docbtn { margin-left: auto; align-self: center; display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; font-size: 12px; font-weight: 700; color: var(--ga); background: color-mix(in srgb, var(--ga) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ga) 35%, transparent); border-radius: 8px; cursor: pointer; transition: all .15s; white-space: nowrap; }
.bd-tab-docbtn:hover { background: color-mix(in srgb, var(--ga) 18%, transparent); border-color: var(--ga); }
.bd-tab-docbtn svg { width: 14px; height: 14px; }
/* Baris simpan per-kartu (anti data-loss): tombol kanan + hint kiri */
.bd-tab-save { display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 12px; }
.bd-tab-save .bd-rolehint { margin: 0; margin-right: auto; }

/* Modal Dokumen RM */
.bd-modal-docs { max-width: 860px; width: 94%; text-align: left; padding: 0; max-height: 88vh; display: flex; flex-direction: column; }
.bd-docs-hd { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 22px; border-bottom: 1px solid var(--gb); flex-shrink: 0; }
.bd-docs-hd h3 { display: flex; align-items: center; gap: 9px; margin: 0; font-size: 16px; font-weight: 800; color: var(--td); }
.bd-docs-hd h3 svg { width: 18px; height: 18px; color: var(--ga); }
.bd-docs-close { display: inline-flex; padding: 6px; color: var(--tu); background: none; border: none; border-radius: 8px; cursor: pointer; transition: all .15s; }
.bd-docs-close:hover { color: var(--td); background: var(--bg); }
.bd-docs-close svg { width: 18px; height: 18px; }
.bd-docs-body { padding: 18px 22px; overflow-y: auto; }
.bd-docs-sec { padding-bottom: 16px; margin-bottom: 16px; border-bottom: 1px dashed var(--gb); }
.bd-docs-sec:last-child { padding-bottom: 0; margin-bottom: 0; border-bottom: none; }
.bd-docs-sec-hd { font-size: 13px; font-weight: 800; color: var(--td); margin-bottom: 8px; }

/* padding-bottom besar: beri ruang dropdown combobox di card terakhir (Tim Bedah) agar tak terpotong */
.bd-tabcont { max-height: calc(100vh - 260px); overflow-y: auto; padding: 20px 20px 220px; }

/* Cards */
.bd-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }
.bd-card-full { margin-top: 16px; }
/* card berisi combobox: jangan clip dropdown absolute (overflow hidden default memotongnya) */
.bd-card-combo, .bd-card-combo .bd-card-bd { overflow: visible; }
.bd-card-hd { display: flex; align-items: center; gap: 8px; padding: 12px 16px; font-size: 13px; font-weight: 700; color: var(--td); border-bottom: 1px solid var(--gb); background: var(--bs); }
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
.bd-dx { font-weight: 600; color: var(--td); }
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
.rx-inactive-badge { display: inline-block; margin-left: 5px; font-size: 9px; font-weight: 700; color: #b45309; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 4px; padding: 0 5px; vertical-align: middle; }
.bd-combo-empty { padding: 12px; text-align: center; font-size: 12px; color: var(--th); font-style: italic; }

/* Komponen Paket Pasien (snapshot) */
.bd-vp-summary { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px; color: var(--tm); padding: 8px 10px; background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; margin-bottom: 6px; }
.bd-vp-summary b { color: var(--td); }
.bd-vp-disc b { color: var(--ga); }
.bd-vp-hint { font-size: 11px; color: var(--tu); margin: 0 0 8px; line-height: 1.45; }
.bd-vp-type { display: inline-block; font-size: 10px; font-weight: 600; padding: 2px 7px; border-radius: 5px; }
.bd-vp-type.procedure { background: var(--ib); color: var(--it); }
.bd-vp-type.bhp { background: var(--wb); color: var(--wt); }
.bd-vp-type.iol { background: var(--gl); color: var(--td); }
.bd-vp-lock { color: var(--tu); }

/* Multi-paket: satu kartu per paket (Phaco + TIVA) */
.bd-vp-pkg { border: 1px solid var(--gb); border-radius: 10px; padding: 10px 12px; margin-bottom: 12px; background: var(--bc); }
.bd-vp-pkg:last-of-type { margin-bottom: 8px; }
.bd-vp-pkg-hd { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.bd-vp-pkg-hd .bd-paket-source-pill { margin-left: 0; }
.bd-vp-type-tag { font-size: 9.5px; font-weight: 700; letter-spacing: .3px; padding: 2px 7px; border-radius: 5px; background: var(--bs); color: var(--tu); border: 1px solid var(--gb); }
.bd-vp-pkg-del { margin-left: auto; font-size: 11px; font-weight: 600; padding: 4px 10px; border: 1px solid var(--eb); color: var(--et); background: transparent; border-radius: 6px; cursor: pointer; }
.bd-vp-pkg-del:hover:not(:disabled) { background: var(--eb); }
.bd-vp-pkg-del:disabled { opacity: .5; cursor: not-allowed; }
.bd-vp-addpkg { display: flex; gap: 8px; align-items: center; margin-top: 4px; padding-top: 10px; border-top: 1px dashed var(--gb); }

/* Paket source pill (di header BHP Terpakai) */
.bd-paket-source-pill { margin-left: auto; font-size: 10.5px; font-weight: 600; padding: 3px 9px; background: var(--gl); border: 1px solid var(--ga); color: var(--td); border-radius: 20px; }

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
.bd-to-val { font-size: 18px; font-weight: 700; color: #fff; font-family: 'JetBrains Mono', monospace; }
.bd-timer-display {
  font-size: 52px; font-weight: 900; color: #fff; font-family: 'JetBrains Mono', monospace;
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
.bd-btn-add { padding: 7px 14px; background: var(--gl); border: 1px solid var(--ga); color: var(--td); border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.bd-btn-add:hover { background: var(--ga); color: #fff; }
.bd-btn-add:disabled { opacity: 0.5; cursor: not-allowed; }
.bd-btn-scan { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; background: var(--gd); border: 1px solid var(--gd); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; }
.bd-btn-scan:hover:not(:disabled) { background: var(--gm); }
.bd-btn-scan:disabled { opacity: 0.5; cursor: not-allowed; }
.bd-btn-scan svg { width: 14px; height: 14px; }
.bd-iol-list { margin-top: 12px; }
.bd-eye-pill { display: inline-block; padding: 1px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; background: var(--gl); color: var(--gd); }
.bd-iol-del { background: var(--eb); border: 1px solid var(--ebd); color: var(--et); border-radius: 6px; width: 22px; height: 22px; font-size: 12px; cursor: pointer; line-height: 1; }
.bd-iol-del:hover { background: var(--et); color: #fff; }
.bd-btn-add:disabled { opacity: .6; cursor: not-allowed; }

.bd-obat-form { display: flex; gap: 6px; align-items: center; margin-top: 10px; flex-wrap: wrap; }

/* Quick obat */
.bd-quick-obat { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 6px; }
.bd-quick-btn { font-size: 11px; padding: 3px 8px; background: var(--gl); border: 1px solid var(--gb); color: var(--td); border-radius: 6px; cursor: pointer; font-weight: 600; }
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

/* Paket Obat Pasca-Bedah */
.bd-paketobat-bar { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
.bd-hint-ok { color: var(--st) !important; }
.bd-paket-modal-grid { display: grid; grid-template-columns: 240px 1fr; gap: 16px; margin-top: 12px; align-items: start; }
@media (max-width: 760px) { .bd-paket-modal-grid { grid-template-columns: 1fr; } }
.bd-paket-list { border: 1px solid var(--gb); border-radius: 10px; padding: 8px; display: flex; flex-direction: column; gap: 4px; max-height: 420px; overflow-y: auto; }
.bd-paket-list-hd { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 4px; }
.bd-paket-list-item { display: flex; align-items: center; justify-content: space-between; gap: 8px; width: 100%; text-align: left; padding: 8px 10px; border: 1px solid var(--gb); border-radius: 8px; background: var(--bs); cursor: pointer; font-family: 'Inter', sans-serif; }
.bd-paket-list-item:hover { background: var(--bc); }
.bd-paket-list-item.active { border-color: var(--ga); background: var(--gl); }
.bd-paket-list-name { font-size: 13px; font-weight: 600; color: var(--td); }
.bd-paket-list-sub { font-size: 11px; color: var(--tm); margin-top: 2px; }
.bd-paket-editor { min-width: 0; }

/* Laporan */
.bd-finalized-banner { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: var(--sb); border: 1px solid var(--sbd); border-radius: 10px; color: var(--st); font-size: 13px; font-weight: 600; margin-bottom: 16px; }
.bd-finalized-banner svg { width: 16px; height: 16px; }
.bd-dx-chip { padding: 8px 12px; background: var(--gl); border-radius: 8px; font-size: 13px; font-weight: 600; color: var(--td); display: inline-block; }
.bd-dx-chip-empty { background: #f3f4f6; color: #9ca3af; font-weight: 500; font-style: italic; }
.bd-no-komplikasi { font-size: 12px; color: var(--st); background: var(--sb); padding: 6px 12px; border-radius: 6px; display: inline-block; }
.bd-disp-hint { display: block; margin-top: 6px; font-size: 12px; color: #1763d4; }
.bd-komplikasi { display: flex; flex-direction: column; gap: 8px; margin-top: 6px; }
/* Perioperatif (WHO checklist + Aldrete) */
.bd-rolehint { font-size: 12px; color: var(--tu); font-style: italic; margin: 0 0 8px; }
.bd-anes-hint { font-size: 12px; color: var(--tm); margin: 0; }
.bd-timeout-card { border-left: 3px solid #eab308; }
.bd-signout-card { border-left: 3px solid #ef4444; }
.bd-timeout-actions { display: flex; align-items: center; gap: 10px; margin-top: 12px; flex-wrap: wrap; }
.bd-btn-bypass { padding: 6px 12px; background: transparent; border: 1px dashed var(--gb); color: var(--tu); border-radius: 8px; font-size: 12px; cursor: pointer; }
.bd-btn-bypass:disabled { opacity: .5; cursor: not-allowed; }
.bd-bypass-note { font-size: 12px; color: #b45309; }
.bd-iol-reminder { background: #fef3c7; border: 1px solid #fcd34d; color: #92400e; font-size: 12px; padding: 8px 12px; border-radius: 8px; margin: 12px 0; }
.bd-aldrete-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
@media (max-width: 720px) { .bd-aldrete-grid { grid-template-columns: 1fr; } }
.bd-laporan-actions { display: flex; align-items: center; gap: 12px; margin-top: 20px; }
.bd-btn-print { display: flex; align-items: center; gap: 8px; padding: 10px 20px; background: var(--bs); border: 1px solid var(--gb); color: var(--tm); border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; }
.bd-btn-print svg { width: 14px; height: 14px; }
.bd-btn-print:hover { border-color: var(--ga); color: var(--td); }
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
