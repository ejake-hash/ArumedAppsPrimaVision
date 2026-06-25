<script setup>
import { ref, computed, reactive, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useAdmisiStore, localDateStr } from '@/stores/admisiStore'
import { useJadwalDokterStore } from '@/stores/jadwalDokterStore'
import WilayahPicker from '@/components/master-data/WilayahPicker.vue'
import PatientAvatar from '@/components/common/PatientAvatar.vue'
import PhotoCaptureModal from '@/components/common/PhotoCaptureModal.vue'
import SignatureCaptureModal from '@/components/forms/signature/SignatureCaptureModal.vue'
import UnitStockActions from '@/components/inventori-farmasi/UnitStockActions.vue'
import IcareModal from '@/components/bpjs/IcareModal.vue'
import { admisiApi, integrasiApi } from '@/services/api'
import { compressImageToUnder } from '@/utils/imageCompress'

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

// True bila `dt` jatuh pada tanggal hari ini (zona waktu lokal). Dipakai untuk
// "reset harian" tiket walk-in kiosk: setelah ganti hari (lewat 23.59) tiket
// kemarin yang tak pernah didaftarkan otomatis tidak lagi dianggap hari ini.
function isTodayDate(dt) {
  if (!dt) return false
  const d = new Date(dt)
  if (isNaN(d.getTime())) return false
  const n = new Date()
  return d.getFullYear() === n.getFullYear()
      && d.getMonth() === n.getMonth()
      && d.getDate() === n.getDate()
}

function escHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]))
}

/* Konversi ISO (YYYY-MM-DD) → tampilan DD/MM/YYYY untuk input teks tgl lahir. */
function isoToDmy(iso) {
  if (!iso) return ''
  const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})/)
  return m ? `${m[3]}/${m[2]}/${m[1]}` : ''
}

/* Parse DD/MM/YYYY → ISO YYYY-MM-DD. Return '' kalau belum lengkap / tidak valid
   (cek hari & bulan masuk akal + roundtrip tanggal asli, mis. tolak 31/02). */
function dmyToIso(dmy) {
  const m = String(dmy ?? '').match(/^(\d{2})\/(\d{2})\/(\d{4})$/)
  if (!m) return ''
  const [_, dd, mm, yyyy] = m
  const d = Number(dd), mo = Number(mm), y = Number(yyyy)
  if (mo < 1 || mo > 12 || d < 1 || d > 31) return ''
  const dt = new Date(y, mo - 1, d)
  if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) return ''
  return `${yyyy}-${mm}-${dd}`
}

/* Auto-mask input tgl lahir: sisipkan "/" otomatis setelah DD dan MM. */
function maskDmy(raw) {
  const digits = String(raw ?? '').replace(/\D/g, '').slice(0, 8)
  const parts = []
  if (digits.length >= 2) { parts.push(digits.slice(0, 2)); }
  else return digits
  if (digits.length >= 4) { parts.push(digits.slice(2, 4)); }
  else { parts.push(digits.slice(2)); return parts.join('/') }
  parts.push(digits.slice(4))
  return parts.join('/')
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
    familyPhone: p.family_phone        ?? '—',
    email:      p.email                ?? '—',
    birthDate:  p.date_of_birth        ?? null,
    age:        walkIn ? null          : calcAge(p.date_of_birth),
    sex:        p.gender               ?? '—',
    guarantor:  q.visit?.guarantor_type ?? '—',
    station:    q.visit?.current_station ?? '—',
    classification: q.visit?.classification ?? '—',
    internalRefFrom: q.visit?.internal_referral_from_schedule?.poliklinik ?? null,
    status:     q.status,
    doctor:     q.visit?.doctor_schedule?.employee?.name ?? null,
    arrivedAt:  fmtTime(q.created_at),
    arrivedDate: fmtDate(q.visit?.visit_date ?? q.created_at),
    visitId:    q.visit_id,
    patientId:  p.id                   ?? null,
    photo:      walkIn ? null          : (q.visit?.photo_url ?? p.photo_url ?? null),
    noRegistrasi: q.visit?.no_registrasi ?? '—',
    noSep:      q.visit?.no_sep        ?? null,
    controlLetter: q.visit?.bpjs_control_letter_id ?? null,
    insurer:    q.visit?.insurer?.name ?? null,
    insurerId:  q.visit?.insurer_id    ?? '',
    insuranceVerificationStatus: q.visit?.insurance_verification_status ?? null,
    callQueueId: q.id,
    callStatus:  q.status,
    gcSigned:   !!q.visit?.general_consent_signed,
    walkIn,
    // Tiket dibuat hari ini? (pakai tgl kunjungan, fallback created_at queue)
    isToday:    isTodayDate(q.visit?.visit_date ?? q.created_at),
  }
}

/* ============================================================
   VISIT COUNTS (used by dashboard stats row)
   ============================================================ */
// Stat per penjamin = pasien yang SUDAH BAYAR di kasir (invoice PAID) hari ini.
// Sumber: AdmisiService::getDashboard().stat_cards.{bpjs,umum,asuransi}_count
const vpBpjs     = computed(() => admisiStore.stats?.bpjs     ?? 0)
const vpUmum     = computed(() => admisiStore.stats?.umum     ?? 0)
const vpAsuransi = computed(() => admisiStore.stats?.asuransi ?? 0)
// Bedah selesai hari ini — ambil dari backend (Queue BEDAH yg sudah COMPLETED).
// Sumber: AdmisiService::getDashboard().stat_cards.bedah_count
const vpBedah    = computed(() => admisiStore.stats?.bedah ?? 0)
// Rawat Inap — pasien yang sedang dirawat inap (belum dipulangkan).
// Sumber: AdmisiService::getDashboard().stat_cards.ranap_count
const vpRanap    = computed(() => admisiStore.stats?.ranap ?? 0)
// Total Kunjungan = registrasi HARI INI (semua jenis layanan), dari dashboard —
// BUKAN total tabel (visitsMeta.total ikut berubah saat tab/filter diganti,
// mis. tab Masih Aktif membuat card melonjak ke ratusan visit lama).
const vpTotal    = computed(() => admisiStore.stats?.total ?? 0)

/* ============================================================
   DATE / CLOCK
   ============================================================ */
const dateStr  = ref('')
const clockStr = ref('')
const days   = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu']
const months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des']
let dateTimer = null

// Pergantian hari (halaman dibiarkan terbuka melewati tengah malam): polling/WS
// hanya me-refresh antrean + tabel, dashboard tidak — stat card "hari ini" akan
// terus memegang angka kemarin. Deteksi rollover di tick jam dinding, lalu muat
// ulang semuanya dengan tanggal baru.
let lastDayKey = localDateStr()
function updateDate() {
  const n = new Date()
  dateStr.value  = `${days[n.getDay()]}, ${n.getDate()} ${months[n.getMonth()]} ${n.getFullYear()}`
  clockStr.value = n.toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit', second:'2-digit' })

  const key = localDateStr(n)
  if (key !== lastDayKey) {
    lastDayKey = key
    admisiStore.fetchDashboard()
    admisiStore.fetchAntrian()
    admisiStore.fetchVisits({ page: 1 })
  }
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
    SATUSEHAT: 'Satu Sehat (FHIR R4)',
  }
  // LUPIS/iCare disembunyikan dari panel ini.
  const raw = (admisiStore.bpjsStatus ?? []).filter(s => s.system !== 'ICARE')
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

// Antrean yg masih perlu aksi admin: WAITING (panggil) + CALLED (daftarkan/selesai).
// Wajib MASIH di stasiun ADMISI — begitu didaftarkan & pindah ke TRIASE (current_station
// != ADMISI), baris harus lenyap dari "Siap Dipanggil ke Loket Admisi" walau status
// queue lokal sempat basi (mis. update WS lite-payload belum mengganti status).
// Walk-in kiosk (placeholder "Belum Terdaftar") yang tiketnya BUKAN hari ini
// disembunyikan: tiket basi yang tak pernah didaftarkan otomatis hilang setelah
// pergantian hari (reset 23.59). Pasien terdaftar lintas-hari tetap tampil.
const callableQueue = computed(() => mappedAntrian.value.filter(
  p => (p.status === 'WAITING' || p.status === 'CALLED')
    && p.station === 'ADMISI'
    && (!p.walkIn || p.isToday),
))

/* ============================================================
   VISITS (semua kunjungan hari ini — apapun stasiun-nya)
   Source untuk tabel "Seluruh Kunjungan Hari Ini".
   Bersifat union dengan walk-in kiosk yang belum didaftarkan.
   ============================================================ */
function mapVisitRow(v) {
  const p      = v.patient ?? {}
  const walkIn = p.name === 'Belum Terdaftar'
  // Queue ADMISI yang masih bisa dipanggil (untuk tombol Panggil di tabel).
  // Hanya relevan selama pasien masih di stasiun ADMISI.
  const admisiQ = (v.queues ?? []).find(
    q => q.station === 'ADMISI' && (q.status === 'WAITING' || q.status === 'CALLED'),
  )
  // Pasien sedang dilayani bila ada antrean CALLED / IN_PROGRESS di stasiun
  // KLINIS (selain ADMISI — "dipanggil ke loket" bukan pelayanan). Dipakai untuk
  // mengunci tombol Batalkan (cegah admisi membatalkan pasien yang sedang diproses).
  const inService = (v.queues ?? []).some(
    q => q.station !== 'ADMISI' && (q.status === 'CALLED' || q.status === 'IN_PROGRESS'),
  )
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
    familyPhone: p.family_phone ?? '—',
    email:      p.email ?? '—',
    birthDate:  p.date_of_birth ?? null,
    age:        walkIn ? null : calcAge(p.date_of_birth),
    sex:        p.gender ?? '—',
    guarantor:  v.guarantor_type ?? '—',
    station:    v.current_station ?? '—',
    classification: v.classification ?? '—',
    internalRefFrom: v.internal_referral_from_schedule?.poliklinik ?? null,
    doctor:     v.doctor_schedule?.employee?.name ?? null,
    status:     'WAITING',  // sentinel — uiStatus akan derive dari station
    arrivedAt:  fmtTime(v.created_at),
    arrivedDate: fmtDate(v.visit_date ?? v.created_at),
    patientId:  p.id ?? null,
    photo:      walkIn ? null : (v.photo_url ?? p.photo_url ?? null),
    noRegistrasi: v.no_registrasi ?? '—',
    noSep:      v.no_sep ?? null,
    noRujukan:  v.no_rujukan ?? null,
    diagnosaAwal:     v.diagnosa_awal ?? null,
    diagnosaAwalNama: v.diagnosa_awal_nama ?? null,
    controlLetter: v.bpjs_control_letter_id ?? null,
    insurer:    v.insurer?.name ?? null,
    insurerId:  v.insurer_id ?? '',
    insuranceVerificationStatus: v.insurance_verification_status ?? null,
    callQueueId: admisiQ?.id ?? null,
    callStatus:  admisiQ?.status ?? null,
    inService,
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

// Jenis kunjungan (Rawat Jalan / Rawat Inap / IGD) dari kolom kanonik
// `jenis_pelayanan` (RAJAL/RANAP/IGD, default RAJAL). Stasiun RANAP/MENUNGGU_RANAP
// = rawat inap walau jenis_pelayanan masih RAJAL (pasien "menunggu kamar"),
// konsisten dgn AdmisiService::getKunjungan.
const JENIS_KUNJUNGAN_LABEL = { RAJAL: 'Rawat Jalan', RANAP: 'Rawat Inap', IGD: 'IGD' }
function jenisKunjunganCanon(v) {
  const st = v?.current_station
  if (st === 'RANAP' || st === 'MENUNGGU_RANAP') return 'RANAP'
  return String(v?.jenis_pelayanan ?? 'RAJAL').toUpperCase()
}
function jenisKunjunganLabel(code) { return JENIS_KUNJUNGAN_LABEL[code] ?? 'Rawat Jalan' }
function jenisKunjunganClass(code) { return { RAJAL:'care-rajal', RANAP:'care-ranap', IGD:'care-igd' }[code] ?? 'care-rajal' }

/* ============================================================
   FILTERS + TABLE — server-side (filter, search, pagination)
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

// Baris tabel = halaman kunjungan dari server (sudah difilter & dipaginasi).
const filteredQueue = computed(() =>
  mappedVisits.value.map(p => ({ ...p, ui: uiStatus(p) })),
)

// Offset nomor urut global mengikuti halaman aktif.
const rowOffset = computed(() =>
  (admisiStore.visitsMeta.current_page - 1) * admisiStore.visitsPerPage,
)

// Pisah Rawat Jalan (RAJAL/IGD) vs Rawat Inap (RANAP). RANAP long-lived → kalau
// dicampur akan terus menumpuk di list. Default tampil Rawat Jalan.
const careType = ref('RAJAL')   // 'RAJAL' | 'RANAP' | 'AKTIF'
function setCareType(t) {
  if (careType.value === t) return
  careType.value = t
  if (t === 'AKTIF') {
    // Mode "Masih Aktif": semua kunjungan yang belum selesai (current_station != SELESAI)
    // LINTAS-HARI — pakai cabang `unfinished` backend (tanpa filter tanggal). careType
    // di-SEMUA-kan supaya param care_type tak dikirim (RAJAL & RANAP sama-sama ikut).
    admisiStore.visitsFilter.unfinished = true
    admisiStore.visitsFilter.careType = 'SEMUA'
  } else {
    admisiStore.visitsFilter.unfinished = false
    admisiStore.visitsFilter.careType = t
  }
  // Reset filter stasiun saat pindah tab: dropdown stationOptions tak punya entri
  // RANAP/MENUNGGU_RANAP, jadi filter stasiun lama (mis. DOKTER) akan MENGOSONGKAN
  // tab Rawat Inap. Kalau berubah, watcher applyVisitFilters yang melakukan fetch;
  // kalau sudah 'all', fetch langsung.
  if (filterStation.value !== 'all') {
    filterStation.value = 'all'
  } else {
    admisiStore.fetchVisits({ page: 1 })
  }
}
const careTypeLabel = computed(() =>
  careType.value === 'AKTIF' ? 'Belum Selesai'
  : careType.value === 'RANAP' ? 'Rawat Inap'
  : 'Rawat Jalan')
// Judul tabel: mode "Masih Aktif" lintas-hari → jangan klaim "Hari Ini".
const visitTableTitle = computed(() =>
  careType.value === 'AKTIF'
    ? 'Kunjungan Belum Selesai (semua tanggal)'
    : `Kunjungan ${careTypeLabel.value} Hari Ini`)

// Terapkan filter/search ke server, selalu balik ke halaman 1.
function applyVisitFilters() {
  admisiStore.visitsFilter.station   = filterStation.value === 'all' ? 'SEMUA' : filterStation.value
  admisiStore.visitsFilter.guarantor = filterGuarantor.value === 'all' ? '' : filterGuarantor.value
  admisiStore.visitsFilter.search    = tableSearch.value.trim()
  admisiStore.fetchVisits({ page: 1 })
}

function goPage(n) {
  const last = admisiStore.visitsMeta.last_page || 1
  if (n < 1 || n > last || admisiStore.visitsLoading) return
  admisiStore.fetchVisits({ page: n })
}

let _visitSearchTimer = null
watch([filterStation, filterGuarantor], applyVisitFilters)
watch(tableSearch, () => {
  clearTimeout(_visitSearchTimer)
  _visitSearchTimer = setTimeout(applyVisitFilters, 350)
})

/* ============================================================
   TOAST SYSTEM
   ============================================================ */

// i-Care JKN viewer (riwayat pelayanan peserta) — modal consent + iframe.
const icareOpen = ref(false)
const icareVisitId = ref(null)
const icareName = ref('')
function openIcare(p) {
  icareVisitId.value = p.visitId
  icareName.value = p.name ?? ''
  icareOpen.value = true
}

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
  // Pakai id queue ADMISI yang callable (bukan visit id). Fallback ke p.id untuk
  // baris yang memang berasal dari antrean (callQueueId === id).
  const queueId  = p.callQueueId ?? p.id
  const isRecall = (p.callStatus ?? p.status) === 'CALLED'
  try {
    await admisiStore.panggilAntrian(queueId)
    const label = p.walkIn ? `Pasien ${p.queueNo}` : `${p.name} (${p.queueNo})`
    toast('s', `${label} ${isRecall ? 'dipanggil ulang' : 'dipanggil'} ke loket admisi`)
  } catch (e) {
    toast('e', e.message)
  }
}

/* Stasiun "resepsi/skrining" tempat pembatalan masih aman: pasien baru daftar &
   belum benar-benar dilayani. Setelah pindah ke stasiun klinis (Dokter/Farmasi/
   Kasir/Bedah/Ranap) atau sudah dipanggil/diproses, pembatalan dikunci untuk
   mencegah admisi tak sengaja menghapus pasien yang sedang dilayani. */
const RECEPTION_STATIONS = ['ADMISI', 'TRIASE', 'REFRAKSIONIS']
/* Admisi SENGAJA tetap boleh membatalkan kunjungan walau pasien sudah pindah
   stasiun / sedang dilayani — yang dikunci hanya kunjungan yang sudah SELESAI atau
   sudah batal. Proteksi "sedang dilayani" diturunkan jadi PERINGATAN tegas di
   dialog konfirmasi (confirmCancelKunjungan), bukan tombol di-disable. */
function canCancelRow(p) {
  if (!p || p.station === 'SELESAI' || p.ui === 'cancel') return false
  return true
}
function cancelTitle(p) {
  if (p.station === 'SELESAI' || p.ui === 'cancel') return 'Kunjungan sudah selesai/batal — tidak bisa dibatalkan'
  if (p.walkIn) return 'Batalkan tiket walk-in'
  const beyond = !RECEPTION_STATIONS.includes(p.station) || p.inService
  return beyond ? '⚠ Pasien sudah/sedang dilayani — batalkan dengan hati-hati' : 'Batalkan kunjungan'
}

/* Batalkan kunjungan (hapus visit + antrean terkait). Selalu diizinkan untuk
   kunjungan aktif; bila pasien sudah di stasiun klinis / sedang dilayani / punya
   SEP aktif → tampilkan peringatan tegas dulu. SEP TIDAK ikut dibatalkan otomatis. */
async function confirmCancelKunjungan(p) {
  if (!canCancelRow(p)) { toast('w', 'Kunjungan sudah selesai/batal — tidak bisa dibatalkan.'); return }
  const label  = p.walkIn ? `antrean ${p.queueNo}` : `${p.name} (${p.queueNo})`
  const beyond = !p.walkIn && (!RECEPTION_STATIONS.includes(p.station) || p.inService)
  const warns  = []
  if (beyond) warns.push(`⚠ Pasien sudah berada di stasiun ${p.station}${p.inService ? ' dan SEDANG DILAYANI' : ''}. Membatalkan akan menghapus seluruh antrean & kunjungan.`)
  if (p.noSep) warns.push(`⚠ Kunjungan ini punya SEP BPJS aktif (${p.noSep}). SEP TIDAK ikut dibatalkan otomatis — batalkan SEP lebih dulu via Detail Kunjungan agar tidak menggantung di BPJS.`)
  const warnText = warns.length ? `\n\n${warns.join('\n\n')}` : ''
  if (!window.confirm(`Batalkan ${label}?\nKunjungan dan nomor antrean akan dihapus dan tidak bisa dikembalikan.${warnText}`)) return
  try {
    await admisiStore.cancelKunjungan(p.visitId)
    toast('s', `Kunjungan ${label} dibatalkan`)
    // Refresh untuk sinkron dengan server state
    await Promise.allSettled([admisiStore.fetchAntrian(), admisiStore.fetchDashboard()])
    admisiStore.fetchVisits()
  } catch (e) {
    toast('e', e.message)
  }
}

/* Tandai tiket walk-in TIDAK HADIR — pasien ambil tiket di Anjungan Mandiri tapi
   tak muncul saat dipanggil berulang. Keluarkan tiket+kunjungan placeholder agar
   antrean walk-in tidak menumpuk. Mekanisme = cancelKunjungan (backend belum punya
   status NO_SHOW tersendiri); hanya berlaku utk baris walk-in yang belum didaftarkan. */
const noShowingId = ref(null)
async function markWalkInNoShow(p) {
  if (!p?.walkIn || !p.visitId) return
  if (!window.confirm(`Tandai antrean ${p.queueNo} TIDAK HADIR?\n\nTiket walk-in akan dikeluarkan dari antrean. Jika pasien datang kembali, mereka perlu mengambil tiket baru di Anjungan Mandiri.`)) return
  noShowingId.value = p.id
  try {
    await admisiStore.cancelKunjungan(p.visitId)
    toast('s', `Antrean ${p.queueNo} ditandai tidak hadir & dikeluarkan dari antrean`)
    await Promise.allSettled([admisiStore.fetchAntrian(), admisiStore.fetchDashboard()])
    admisiStore.fetchVisits()
  } catch (e) {
    toast('e', e.message)
  } finally {
    noShowingId.value = null
  }
}

/* ============================================================
   EDIT PASIEN — modal kecil di card "Siap Dipanggil"
   ============================================================ */
const editOpen      = ref(false)
const editSaving    = ref(false)
const editPatientId = ref(null)
const editForm      = reactive({
  name: '', nik: '', gender: 'L', date_of_birth: '', phone: '', family_phone: '', email: '', address: '',
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
    family_phone:  p.familyPhone === '—' ? '' : (p.familyPhone ?? ''),
    email:         p.email === '—' ? '' : (p.email ?? ''),
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
      family_phone:  editForm.family_phone || null,
      email:         editForm.email || null,
      address:       editForm.address || null,
    }
    await admisiStore.updatePasien(editPatientId.value, payload)
    toast('s', `Data pasien ${editForm.name} diperbarui`)
    editOpen.value = false
    await Promise.allSettled([admisiStore.fetchAntrian(), admisiStore.fetchVisits()])
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
// Konteks walk-in saat alur "Cari Data Pasien" lewat modal Profil Pasien: tiket kiosk
// disimpan di sini supaya tetap terbawa ke wizard (existing/baru) setelah lihat detail.
// Tidak null = sedang mendaftarkan walk-in via lookup. Dibersihkan saat selesai/batal.
const pendingWalkIn = ref(null)   // { visitId, queueNo } | null

function daftarkanWalkIn(p) {
  // Alur baru: cari & LIHAT detail pasien dulu (modal Profil Pasien). Daftar pasien
  // BARU hanya bila tak ditemukan di database. Tiket walk-in (visit kiosk) dibawa via
  // pendingWalkIn sampai registrasi diselesaikan dari modal Profil / wizard.
  pendingWalkIn.value  = { visitId: p.visitId, queueNo: p.queueNo }
  lookupKey.value      = ''
  lookupResults.value  = []
  lookupDropOpen.value = false
  nextTick(() => lookupInput.value?.focus())
  toast('i', `Cari data pasien untuk antrean ${p.queueNo} — pilih untuk lihat detail, atau daftar baru bila tak ada`)
}

// Batalkan alur walk-in lookup tanpa mendaftarkan apa pun.
function cancelWalkInLookup() {
  pendingWalkIn.value  = null
  lookupKey.value      = ''
  lookupResults.value  = []
  lookupDropOpen.value = false
}

// "Tidak ada di database" → daftar pasien BARU, tetap membawa tiket walk-in.
function daftarBaruWalkIn() {
  const wi = pendingWalkIn.value      // baca dulu — openWizard akan meng-clear pendingWalkIn
  lookupKey.value      = ''
  lookupResults.value  = []
  lookupDropOpen.value = false
  openWizard()                        // reset + buka wizard (juga clear walkInVisitId)
  if (wi) { walkInVisitId.value = wi.visitId; walkInQueueNo.value = wi.queueNo }
  form.patientMode = 'new'            // pasien baru
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

// Detail insurer yang sedang dipilih — dipakai info-box PIC TPA di section ASURANSI.
const selectedInsurerInfo = computed(() =>
  (admisiStore.insurers ?? []).find(i => i.id === form.insurer_id) ?? null,
)

function selectInsurer(ins) {
  form.insurer_id    = ins.id
  form.insuranceName = ins.name
  insuranceSearch.value       = ins.name
  insuranceDropdownOpen.value = false
  // Cegah penjamin utama == penjamin kedua (COB): bersihkan COB bila bentrok
  if (form.cobInsurerId === ins.id) {
    form.cobInsurerId     = ''
    form.cobInsuranceName = ''
    cobSearch.value       = ''
  }
}
function onInsuranceBlur() { setTimeout(() => { insuranceDropdownOpen.value = false }, 150) }

// Reset pilihan insurer saat user ganti tipe penjamin
function setGuarantor(g) {
  if (form.guarantor === g) return
  form.guarantor         = g
  form.insurer_id        = ''
  form.insuranceName     = ''
  form.insuranceNo       = ''
  form.memberName        = ''
  form.memberCardNumber  = ''
  insuranceSearch.value  = ''
  // COB untuk BPJS (INA-CBG + selisih) / ASURANSI / PERUSAHAAN → reset bila pindah ke tipe lain
  if (!['BPJS','ASURANSI','PERUSAHAAN'].includes(g)) resetCob()
}

/* ─── COB (penjamin kedua) — selalu insurer tipe ASURANSI ─── */
const cobSearch         = ref('')
const cobDropdownOpen   = ref(false)

// Daftar insurer penjamin kedua (Asuransi/Perusahaan), kecuali yang sudah dipilih
// sebagai penjamin utama (tidak boleh sama).
const cobInsurers = computed(() => {
  const q = cobSearch.value.trim().toLowerCase()
  return (admisiStore.insurers ?? [])
    .filter(i => ['ASURANSI','PERUSAHAAN'].includes(i.type))
    .filter(i => i.id !== form.insurer_id)
    .filter(i => !q || i.name.toLowerCase().includes(q))
})

function selectCobInsurer(ins) {
  form.cobInsurerId     = ins.id
  form.cobInsuranceName = ins.name
  cobSearch.value       = ins.name
  cobDropdownOpen.value = false
}
function onCobBlur() { setTimeout(() => { cobDropdownOpen.value = false }, 150) }

function resetCob() {
  form.cobEnabled       = false
  form.cobInsurerId     = ''
  form.cobInsuranceName = ''
  form.cobInsuranceNo   = ''
  cobSearch.value       = ''
}

function onCobToggle(e) {
  form.cobEnabled = e.target.checked
  if (!form.cobEnabled) {
    form.cobInsurerId     = ''
    form.cobInsuranceName = ''
    form.cobInsuranceNo   = ''
    cobSearch.value       = ''
  }
}

/* ============================================================
   EDIT PENJAMIN / TIPE KUNJUNGAN (ubah pola bayar pasca-daftar)
   ------------------------------------------------------------
   Aman selama billing belum dikomit (tarif diresolusi LIVE saat kasir).
   Backend (AdmisiService::updateGuarantor) menolak bila tagihan sudah ke
   kasir / invoice final-bayar / SEP BPJS masih aktif.
   ============================================================ */
const penjaminOpen    = ref(false)
const penjaminSaving  = ref(false)
const penjaminVisitId = ref(null)
const penjaminForm = reactive({
  patientName: '', currentLabel: '',
  guarantor: 'UMUM',
  insurer_id: '',
  sepType: 'rujukan', referralNo: '', controlNo: '', bookingCode: '',
  policyNumber: '', memberName: '', memberCardNumber: '',
  cobEnabled: false, cobInsurerId: '', cobNo: '',
})
// Opsi insurer untuk modal (pakai <select>, bukan typeahead) — difilter per tipe.
const penjaminInsurerOptions = computed(() => {
  const wantType = ['ASURANSI', 'PERUSAHAAN', 'SOSIAL'].includes(penjaminForm.guarantor)
    ? penjaminForm.guarantor : null
  return (admisiStore.insurers ?? []).filter(i => !wantType || i.type === wantType)
})
const penjaminCobOptions = computed(() =>
  (admisiStore.insurers ?? [])
    .filter(i => ['ASURANSI', 'PERUSAHAAN'].includes(i.type) && i.id !== penjaminForm.insurer_id),
)

/* Typeahead penjamin utama (modal Ubah Penjamin) — ganti <select> agar bisa dicari. */
const penjaminInsSearch   = ref('')
const penjaminInsOpen     = ref(false)
const penjaminInsFiltered = computed(() => {
  const q = penjaminInsSearch.value.trim().toLowerCase()
  return penjaminInsurerOptions.value.filter(i => !q || i.name.toLowerCase().includes(q))
})
function selectPenjaminInsurer(ins) {
  penjaminForm.insurer_id = ins.id
  penjaminInsSearch.value = ins.name
  penjaminInsOpen.value   = false
  // Cegah penjamin utama == penjamin kedua (COB).
  if (penjaminForm.cobInsurerId === ins.id) {
    penjaminForm.cobInsurerId = ''
    penjaminCobSearch.value   = ''
  }
}
function onPenjaminInsBlur() { setTimeout(() => { penjaminInsOpen.value = false }, 150) }

/* Typeahead penjamin kedua / COB (modal Ubah Penjamin). */
const penjaminCobSearch   = ref('')
const penjaminCobOpen     = ref(false)
const penjaminCobFiltered = computed(() => {
  const q = penjaminCobSearch.value.trim().toLowerCase()
  return penjaminCobOptions.value.filter(i => !q || i.name.toLowerCase().includes(q))
})
function selectPenjaminCob(ins) {
  penjaminForm.cobInsurerId = ins.id
  penjaminCobSearch.value   = ins.name
  penjaminCobOpen.value     = false
}
function onPenjaminCobBlur() { setTimeout(() => { penjaminCobOpen.value = false }, 150) }
function onPenjaminCobToggle(e) {
  penjaminForm.cobEnabled = e.target.checked
  if (!penjaminForm.cobEnabled) {
    penjaminForm.cobInsurerId = ''
    penjaminForm.cobNo        = ''
    penjaminCobSearch.value   = ''
    penjaminCobOpen.value     = false
  }
}

function ensureInsurersLoaded() {
  if (!(admisiStore.insurers ?? []).length) admisiStore.fetchInsurers()
}
function openEditPenjamin(p) {
  if (!p || p.walkIn) { toast('w', 'Pasien walk-in belum terdaftar — daftarkan dulu'); return }
  if (p.station === 'SELESAI') { toast('w', 'Kunjungan sudah selesai — penjamin tidak bisa diubah'); return }
  penjaminVisitId.value = p.visitId
  Object.assign(penjaminForm, {
    patientName:  p.name,
    currentLabel: guarantorLabel(p.guarantor),
    guarantor:    ['UMUM', 'BPJS', 'ASURANSI', 'PERUSAHAAN', 'SOSIAL'].includes(p.guarantor) ? p.guarantor : 'UMUM',
    insurer_id:   p.insurerId ?? '',
    sepType: 'rujukan', referralNo: '', controlNo: '', bookingCode: '',
    policyNumber: '', memberName: '', memberCardNumber: '',
    cobEnabled: false, cobInsurerId: '', cobNo: '',
  })
  // Prefill kotak cari penjamin utama dgn nama insurer saat ini (kalau ada).
  penjaminInsSearch.value = ['ASURANSI', 'PERUSAHAAN', 'SOSIAL'].includes(penjaminForm.guarantor)
    ? (p.insurer ?? '') : ''
  penjaminCobSearch.value = ''
  penjaminInsOpen.value   = false
  penjaminCobOpen.value   = false
  ensureInsurersLoaded()
  penjaminOpen.value = true
}
function closeEditPenjamin() { penjaminOpen.value = false }
function setPenjaminType(g) {
  if (penjaminForm.guarantor === g) return
  penjaminForm.guarantor  = g
  penjaminForm.insurer_id = ''
  penjaminInsSearch.value = ''
  penjaminInsOpen.value   = false
  if (!['BPJS', 'ASURANSI', 'PERUSAHAAN'].includes(g)) {
    penjaminForm.cobEnabled = false
    penjaminForm.cobInsurerId = ''
    penjaminForm.cobNo = ''
    penjaminCobSearch.value = ''
    penjaminCobOpen.value   = false
  }
}
async function submitEditPenjamin() {
  if (!penjaminVisitId.value) return
  const t = penjaminForm.guarantor
  if (['ASURANSI', 'PERUSAHAAN', 'SOSIAL'].includes(t) && !penjaminForm.insurer_id) {
    toast('w', 'Pilih penjamin (insurer) dulu'); return
  }
  penjaminSaving.value = true
  try {
    const payload = { guarantor_type: t }
    if (['ASURANSI', 'PERUSAHAAN', 'SOSIAL'].includes(t)) payload.insurer_id = penjaminForm.insurer_id
    if (t === 'BPJS') {
      payload.bpjs_booking_code = penjaminForm.sepType === 'jkn'     ? (penjaminForm.bookingCode || null) : null
      payload.bpjs_referral_no  = penjaminForm.sepType === 'rujukan' ? (penjaminForm.referralNo  || null) : null
      payload.bpjs_control_no   = penjaminForm.sepType === 'kontrol' ? (penjaminForm.controlNo   || null) : null
    }
    if (['ASURANSI', 'PERUSAHAAN'].includes(t)) {
      payload.policy_number      = penjaminForm.policyNumber     || null
      payload.member_name        = penjaminForm.memberName       || null
      payload.member_card_number = penjaminForm.memberCardNumber || null
    }
    if (['BPJS', 'ASURANSI', 'PERUSAHAAN'].includes(t) && penjaminForm.cobEnabled && penjaminForm.cobInsurerId) {
      const cobIns = (admisiStore.insurers ?? []).find(i => i.id === penjaminForm.cobInsurerId)
      payload.cob = {
        penjamin1_type:       t,
        penjamin1_insurer_id: payload.insurer_id || null,
        penjamin2_type:       cobIns?.type ?? 'ASURANSI',
        penjamin2_insurer_id: penjaminForm.cobInsurerId,
        notes:                penjaminForm.cobNo ? `Polis penjamin 2: ${penjaminForm.cobNo}` : null,
      }
    }
    await admisiStore.updatePenjamin(penjaminVisitId.value, payload)
    toast('s', `Penjamin ${penjaminForm.patientName} diperbarui → ${guarantorLabel(t)}`)
    penjaminOpen.value = false
    await Promise.allSettled([admisiStore.fetchVisits(), admisiStore.fetchDashboard()])
  } catch (e) {
    const firstErr = e.errors ? Object.values(e.errors)[0]?.[0] : null
    toast('e', firstErr ?? e.message ?? 'Gagal mengubah penjamin')
  } finally {
    penjaminSaving.value = false
  }
}

/* ============================================================
   DOCTOR LIST — jadwal aktif hari ini (dari /jadwal-dokter/aktif-hari-ini)
   ============================================================ */
const doctorList = computed(() =>
  jadwalStore.aktifHariIni.map(s => {
    const sisaVals = [s.sisa_jkn, s.sisa_nonjkn].filter(v => v != null)
    const sisaMin  = sisaVals.length ? Math.min(...sisaVals) : null
    const base = `${s.nama_dokter} · ${s.poliklinik || 'Poliklinik'} · ${s.queue_prefix}${s.start_time ? ` · ${s.start_time.slice(0,5)}–${s.end_time.slice(0,5)}` : ''}`
    return {
      id:           s.id,
      name:         s.nama_dokter,
      serviceType:  s.service_type,          // 'BPJS' | 'EKSEKUTIF' — utk filter per penjamin
      poliklinik:   s.poliklinik || '—',
      room:         s.room,
      queuePrefix:  s.queue_prefix,
      jam:          s.start_time && s.end_time ? `${s.start_time.slice(0,5)}–${s.end_time.slice(0,5)}` : '',
      hampirPenuh:  !!s.hampir_penuh,
      sisaJkn:      s.sisa_jkn,
      sisaNonJkn:   s.sisa_nonjkn,
      sisaMin,
      // Peringatan inline di dropdown agar petugas tahu sejak memilih jadwal.
      label:        base + (s.hampir_penuh ? ` — ⚠ Hampir penuh (sisa ${sisaMin ?? '?'})` : ''),
    }
  }),
)
const selectedSchedule = computed(() =>
  doctorList.value.find(d => d.id === form.doctor_schedule_id) ?? null,
)

/* Filter jadwal per jenis penjamin: BPJS → jadwal layanan BPJS; selain BPJS
   (Umum/Asuransi/Perusahaan/Sosial) → jadwal Eksekutif. Jadwal tanpa
   service_type (data lama) tetap ditampilkan agar tak hilang. */
function schedulesForGuarantor(guarantor) {
  const wantExec = guarantor && guarantor !== 'BPJS'
  return doctorList.value.filter(d =>
    !d.serviceType || d.serviceType === (wantExec ? 'EKSEKUTIF' : 'BPJS'),
  )
}
const wizardDoctorList = computed(() => schedulesForGuarantor(form.guarantor))

/* ============================================================
   GANTI DOKTER — koreksi salah-pilih saat pendaftaran.
   Aman & ringan: antrean dokter tak menyimpan doctor_id, cukup
   tukar visits.doctor_schedule_id → pasien re-route otomatis.
   Backend (AdmisiService::gantiDokterKunjungan) menolak bila dokter
   sudah memanggil/memeriksa atau billing sudah dikomit.
   ============================================================ */
const dokterOpen    = ref(false)
const dokterSaving   = ref(false)
const dokterVisitId  = ref(null)
const dokterForm = reactive({ patientName: '', currentDoctor: '', scheduleId: '', guarantor: 'BPJS' })
const editDoctorList = computed(() => schedulesForGuarantor(dokterForm.guarantor))

function openEditDokter(p) {
  if (!p || p.walkIn) { toast('w', 'Pasien walk-in belum terdaftar — daftarkan dulu'); return }
  if (p.station === 'SELESAI') { toast('w', 'Kunjungan sudah selesai — dokter tidak bisa diubah'); return }
  dokterVisitId.value = p.visitId
  Object.assign(dokterForm, {
    patientName:   p.name,
    currentDoctor: p.doctor ?? '—',
    scheduleId:    '',
    guarantor:     p.guarantor === 'BPJS' ? 'BPJS' : 'EKSEKUTIF',
  })
  if (!jadwalStore.aktifHariIni.length) jadwalStore.fetchAktifHariIni()
  dokterOpen.value = true
}
function closeEditDokter() { dokterOpen.value = false }
async function submitEditDokter() {
  if (!dokterVisitId.value) return
  if (!dokterForm.scheduleId) { toast('w', 'Pilih dokter tujuan dulu'); return }
  dokterSaving.value = true
  try {
    const picked = doctorList.value.find(d => d.id === dokterForm.scheduleId)
    await admisiStore.gantiDokter(dokterVisitId.value, dokterForm.scheduleId)
    toast('s', `Dokter ${dokterForm.patientName} diubah → ${picked?.name ?? 'dokter baru'}`)
    dokterOpen.value = false
    await Promise.allSettled([admisiStore.fetchVisits(), admisiStore.fetchDashboard()])
  } catch (e) {
    const firstErr = e.errors ? Object.values(e.errors)[0]?.[0] : null
    toast('e', firstErr ?? e.message ?? 'Gagal mengubah dokter')
  } finally {
    dokterSaving.value = false
  }
}

/* ============================================================
   PATIENT SEARCH (live, debounced)
   ============================================================ */
const searchResults  = ref([])
const searchLoading  = ref(false)
const showSearchDrop = ref(false)
const wizSearchInput = ref(null)   // ref input pencarian di wizard (auto-focus walk-in)
let   _searchTimer   = null
// Info kunjungan aktif pasien terpilih (current_station != SELESAI). Bila ada,
// registrasi baru AKAN ditolak guard backend — tampilkan peringatan lebih awal.
const selectedActiveVisit = ref(null)

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
    identityType:  pt.identity_type ?? 'KTP',
    nik:           pt.nik,
    name:          pt.name,
    sex:           pt.gender,
    birthDate:     pt.date_of_birth,
    age:           calcAge(pt.date_of_birth),
    phone:         pt.phone        ?? '',
    familyPhone:   pt.family_phone ?? '',
    email:         pt.email        ?? '',
    province:      pt.province         ?? '',
    regency:       pt.nama_kab_kota    ?? '',
    district:      pt.nama_kecamatan   ?? '',
    origProvince:  pt.province         ?? '',
    origRegency:   pt.nama_kab_kota    ?? '',
    origDistrict:  pt.nama_kecamatan   ?? '',
    addressDetail: pt.address      ?? '',
    guarantor:     pt.bpjs_number  ? 'BPJS' : 'UMUM',
    bpjsNo:        pt.bpjs_number  ?? '',
    // Reset referensi SEP — jangan bawa nomor rujukan/kontrol pasien sebelumnya.
    sepType:       'rujukan',
    referralNo:    '',
    controlNo:     '',
    insurer_id:    '',
    insuranceName: '',
    insuranceNo:   '',
    photo:         pt.photo_url    ?? null,
  })
  insuranceSearch.value = ''
  showSearchDrop.value  = false
  resetBpjsPull()   // buang daftar rujukan/surat kontrol pasien sebelumnya
  // Reset status wilayah; WilayahPicker akan emit prefill-status saat load ulang.
  wizPrefilled.value = null
  wizWilayahTouched.value = false
  selectedActiveVisit.value = pt.active_visit ?? null
  if (selectedActiveVisit.value) {
    const av  = selectedActiveVisit.value
    const stn = av.current_station ? ` (di stasiun ${av.current_station})` : ''
    const reg = av.no_registrasi ? ` No. ${av.no_registrasi}` : ''
    toast('w', `${pt.name} masih punya kunjungan aktif${reg}${stn} — selesaikan/batalkan dulu sebelum mendaftar baru`)
  } else {
    toast('s', `Data pasien ${pt.name} ditemukan`)
  }
  loadJadwalBedah(pt.id)
  loadFollowupEntitlements(pt.id)
}

function onSearchBlur() { setTimeout(() => { showSearchDrop.value = false }, 200) }

/* ============================================================
   PATIENT LOOKUP (toolbar searchbar → modal Profil Pasien)
   Lihat data pasien yang sudah pernah terdaftar tanpa harus
   mendaftarkan kunjungan baru. Klik hasil → modal 2 tab.
   ============================================================ */
const lookupKey      = ref('')
const lookupResults  = ref([])
const lookupLoading  = ref(false)
const lookupDropOpen  = ref(false)
const lookupInput    = ref(null)   // ref input toolbar (auto-focus saat alur walk-in)
let   _lookupTimer   = null

async function runLookup() {
  const key = lookupKey.value.trim()
  if (!key) { lookupResults.value = []; lookupDropOpen.value = false; return }
  lookupLoading.value  = true
  lookupDropOpen.value = true
  try {
    lookupResults.value = await admisiStore.cariPasien(key)
  } catch (e) {
    toast('e', e.message)
  } finally {
    lookupLoading.value = false
  }
}

function onLookupInput() {
  clearTimeout(_lookupTimer)
  const key = lookupKey.value.trim()
  if (key.length >= 1) {
    _lookupTimer = setTimeout(runLookup, 300)
  } else {
    lookupResults.value  = []
    lookupDropOpen.value = false
  }
}

function onLookupBlur() { setTimeout(() => { lookupDropOpen.value = false }, 200) }

/* Modal Profil Pasien — Tab 1: detail data, Tab 2: riwayat kunjungan (paginated) */
const todayStr       = computed(() => localDateStr())
const profileOpen    = ref(false)
const profileTab     = ref('detail')   // 'detail' | 'history'
const profilePatient = ref(null)
const profileLoading = ref(false)

// Riwayat kunjungan — server-side pagination + filter tanggal
const RIWAYAT_PER_PAGE = 8
const profileVisits   = ref([])
const riwayatDate     = ref('')   // filter tanggal (YYYY-MM-DD)
const riwayatLoading  = ref(false)
const riwayatMeta     = ref({ total: 0, current_page: 1, last_page: 1 })

async function loadRiwayat(page = 1) {
  if (!profilePatient.value?.id) return
  riwayatLoading.value = true
  try {
    const res = await admisiStore.fetchKunjunganPasien(profilePatient.value.id, {
      page,
      per_page: RIWAYAT_PER_PAGE,
      tanggal:  riwayatDate.value || undefined,
    })
    profileVisits.value = res.data.map(v => ({
      id:             v.id,
      visitId:        v.id,
      date:           fmtDate(v.visit_date ?? v.created_at),
      photo:          v.photo_url ?? null,
      classification: v.classification ?? '—',
      careType:       jenisKunjunganCanon(v),
      internalRefFrom: v.internal_referral_from_schedule?.poliklinik ?? null,
      guarantor:      v.guarantor_type ?? '—',
      station:        v.current_station ?? '—',
      doctor:         v.doctor_schedule?.employee?.name ?? '—',
      poliklinik:     v.doctor_schedule?.poliklinik ?? null,
      insurer:        v.insurer?.name ?? null,
      noSep:          v.no_sep ?? null,
      // Field tambahan agar baris ini bisa dibuka di modal Detail Kunjungan
      // (edit/update SEP & Surat Kontrol + diagnosa awal). Pasien dari profilePatient.
      noRegistrasi:   v.no_registrasi ?? '—',
      queueNo:        v.no_antrian ?? '—',
      arrivedDate:    fmtDate(v.visit_date ?? v.created_at),
      noRujukan:        v.no_rujukan ?? null,
      diagnosaAwal:     v.diagnosa_awal ?? null,
      diagnosaAwalNama: v.diagnosa_awal_nama ?? null,
      walkIn:         false,
    }))
    riwayatMeta.value = res.meta
  } catch (e) {
    toast('e', e.message)
  } finally {
    riwayatLoading.value = false
  }
}

async function openProfile(pt) {
  lookupDropOpen.value = false
  lookupKey.value      = ''
  lookupResults.value  = []
  profileTab.value     = 'detail'
  profileEdit.open     = false       // mode baca saat buka profil pasien baru
  profilePatient.value = pt          // tampilkan data ringkas dulu sambil fetch detail
  profileOpen.value    = true
  profileLoading.value = true
  // reset riwayat
  profileVisits.value = []
  riwayatDate.value   = ''
  riwayatMeta.value   = { total: 0, current_page: 1, last_page: 1 }
  // reset dokumen identitas
  identityDocs.value = []
  revokeIdentityUrls()
  try {
    // Detail dari server TIDAK menyertakan info kunjungan aktif → pertahankan
    // active_visit dari hasil pencarian (pt) agar peringatan & notif "kunjungan
    // aktif" tetap muncul saat Daftarkan dari modal Profil (bukan hanya dari
    // pencarian inline wizard). Tanpa ini, selectPatient kehilangan active_visit.
    const detail = await admisiStore.fetchPasienDetail(pt.id)
    profilePatient.value = { ...detail, active_visit: detail.active_visit ?? pt.active_visit ?? null }
    loadRiwayat(1)   // muat riwayat (juga untuk angka badge tab)
    loadIdentityDocs(pt.id)   // muat dokumen identitas (KTP)
  } catch (e) {
    toast('e', e.message)
  } finally {
    profileLoading.value = false
  }
}
// Tutup modal Profil + buang konteks walk-in (alur dibatalkan/dialihkan).
function closeProfile() { pendingWalkIn.value = null; closeProfileSilent() }
// Tutup modal Profil TANPA membuang pendingWalkIn — dipakai saat lanjut ke wizard.
function closeProfileSilent() { profileOpen.value = false; profileEdit.open = false; revokeIdentityUrls() }

/* ─── Dokumen Identitas (KTP) — per-pasien, berkas privat ber-auth ────────── */
const identityDocs      = ref([])        // metadata dari backend
const identityUrls      = reactive({})   // { docId: objectURL } untuk preview/unduh
const identityLoading   = ref(false)
const identityUploading = ref(false)
const identityUploadPct = ref(0)
const identityFileInput = ref(null)
const IDENTITY_MAX_BYTES = 2 * 1024 * 1024  // 2 MB

function revokeIdentityUrls() {
  for (const k of Object.keys(identityUrls)) {
    URL.revokeObjectURL(identityUrls[k])
    delete identityUrls[k]
  }
}

function fmtFileSize(n) {
  if (!n && n !== 0) return ''
  return n >= 1024 * 1024 ? `${(n / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`
}

async function loadIdentityDocs(patientId) {
  revokeIdentityUrls()
  identityDocs.value = []
  if (!patientId) return
  identityLoading.value = true
  try {
    const { data } = await admisiApi.identityDocs(patientId)
    identityDocs.value = data.data ?? []
    // Lazy-fetch berkas (blob) tiap dokumen → object URL untuk preview/unduh.
    // Paralel — berkas independen, tak perlu serial (latensi menumpuk).
    await Promise.allSettled(identityDocs.value.map(async (doc) => {
      try {
        const res = await admisiApi.identityDocFile(patientId, doc.id)
        identityUrls[doc.id] = URL.createObjectURL(res.data)
      } catch { /* lewati berkas yg gagal dimuat */ }
    }))
  } catch (e) {
    toast('e', e.response?.data?.message ?? e.message ?? 'Gagal memuat dokumen identitas')
  } finally {
    identityLoading.value = false
  }
}

function pickIdentityFile() { identityFileInput.value?.click() }

async function onIdentityFileSelected(e) {
  const raw = e.target.files?.[0]
  e.target.value = ''   // reset agar file sama bisa dipilih ulang
  const patientId = profilePatient.value?.id
  if (!raw || !patientId) return

  let file = raw
  if (raw.type?.startsWith('image/')) {
    // Gambar besar dikompres otomatis di browser sampai ≤ 2 MB.
    try {
      file = await compressImageToUnder(raw, { maxBytes: IDENTITY_MAX_BYTES, maxDim: 1600 })
    } catch { toast('e', 'Gagal memproses gambar'); return }
    if (file.size > IDENTITY_MAX_BYTES) {
      toast('w', 'Gambar masih > 2 MB setelah kompres — gunakan file lebih kecil')
      return
    }
  } else if (raw.type === 'application/pdf') {
    // PDF tidak dikompres di browser — tolak bila > 2 MB.
    if (raw.size > IDENTITY_MAX_BYTES) { toast('w', 'PDF maksimal 2 MB — mohon kompres dulu'); return }
  } else {
    toast('w', 'Format harus gambar (JPG/PNG/WebP) atau PDF')
    return
  }

  const fd = new FormData()
  fd.append('file', file)
  fd.append('doc_type', 'KTP')
  identityUploading.value = true
  identityUploadPct.value = 0
  try {
    await admisiApi.uploadIdentityDoc(patientId, fd, (ev) => {
      if (ev.total) identityUploadPct.value = Math.round((ev.loaded / ev.total) * 100)
    })
    toast('s', 'Dokumen KTP tersimpan')
    await loadIdentityDocs(patientId)
  } catch (err) {
    const firstErr = err.response?.data?.errors ? Object.values(err.response.data.errors)[0]?.[0] : null
    toast('e', firstErr ?? err.response?.data?.message ?? 'Gagal mengunggah dokumen')
  } finally {
    identityUploading.value = false
    identityUploadPct.value = 0
  }
}

function viewIdentityDoc(doc) {
  const url = identityUrls[doc.id]
  if (!url) { toast('w', 'Berkas belum siap — muat ulang profil'); return }
  window.open(url, '_blank')
}

function downloadIdentityDoc(doc) {
  const url = identityUrls[doc.id]
  if (!url) { toast('w', 'Berkas belum siap — muat ulang profil'); return }
  const a = document.createElement('a')
  a.href = url
  a.download = doc.file_name || 'ktp'
  document.body.appendChild(a)
  a.click()
  a.remove()
}

async function confirmDeleteIdentityDoc(doc) {
  const patientId = profilePatient.value?.id
  if (!patientId) return
  if (!window.confirm(`Hapus dokumen "${doc.file_name}"? Tindakan ini tidak bisa dibatalkan.`)) return
  try {
    await admisiApi.deleteIdentityDoc(patientId, doc.id)
    toast('s', 'Dokumen identitas dihapus')
    await loadIdentityDocs(patientId)
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal menghapus dokumen')
  }
}

/* Daftarkan kunjungan baru langsung dari modal Profil Pasien (hasil pencarian).
   Buka wizard mode "existing" ter-prefill pasien ini, lalu tutup modal profil. */
function daftarkanDariProfil() {
  const pt = profilePatient.value
  if (!pt?.id) return
  const wi = pendingWalkIn.value   // baca dulu — openWizard akan meng-clear pendingWalkIn
  openWizard()                 // reset wizard + buka modal (juga clear walkInVisitId)
  if (wi) { walkInVisitId.value = wi.visitId; walkInQueueNo.value = wi.queueNo }
  form.patientMode = 'existing'
  selectPatient(pt)            // prefill form dari data pasien + cek kunjungan aktif
  closeProfileSilent()         // tutup modal tanpa membuang konteks walk-in
}

/* ─── Edit/Update data pasien dari tab Detail Pasien ─────────────────── */
const profileEdit = reactive({
  open: false, loading: false, errors: null,
  name: '', nik: '', gender: 'L', date_of_birth: '', phone: '', family_phone: '', email: '',
  address: '', province: '', nama_kab_kota: '', nama_kecamatan: '', blood_type: '',
  // Snapshot wilayah asal (data lama). Dipakai untuk: (1) tampilkan info nilai
  // lama, (2) jangan timpa dgn kosong kalau petugas tak menyentuh WilayahPicker
  // (mis. data migrasi yg ejaannya tak cocok master → dropdown tampil kosong).
  origProvince: '', origKabKota: '', origKecamatan: '',
})
// Status prefill dari WilayahPicker: true=ketemu master, false=tidak cocok,
// null=belum/tak ada data lama. Saat false → tampilkan info nilai lama.
const wilayahPrefilled = ref(null)
// true begitu petugas benar-benar memilih dari dropdown (bukan prefill awal).
// Inilah sinyal "data wilayah sengaja diubah" → boleh menimpa data lama.
const wilayahTouched = ref(false)
const wilayahNeedsRepick = computed(() =>
  !!profileEdit.origProvince && wilayahPrefilled.value === false && !wilayahTouched.value
)
function onWilayahPrefill(ok) { wilayahPrefilled.value = ok }
function onWilayahTouched() { wilayahTouched.value = true }
function startProfileEdit() {
  const p = profilePatient.value || {}
  Object.assign(profileEdit, {
    open: true, loading: false, errors: null,
    name:          p.name ?? '',
    nik:           p.nik ?? '',
    gender:        p.gender ?? 'L',
    date_of_birth: p.date_of_birth ? String(p.date_of_birth).slice(0, 10) : '',
    phone:         p.phone ?? '',
    family_phone:  p.family_phone ?? '',
    email:         p.email ?? '',
    address:       p.address ?? '',
    province:      p.province ?? '',
    nama_kab_kota:  p.nama_kab_kota ?? '',
    nama_kecamatan: p.nama_kecamatan ?? '',
    blood_type:    p.blood_type ?? '',
    origProvince:   p.province ?? '',
    origKabKota:    p.nama_kab_kota ?? '',
    origKecamatan:  p.nama_kecamatan ?? '',
  })
  wilayahPrefilled.value = null   // ditentukan oleh event prefill-status WilayahPicker
  wilayahTouched.value = false    // reset; jadi true saat petugas pilih dropdown
}
function cancelProfileEdit() { profileEdit.open = false; profileEdit.errors = null }

/* Resolve IHS satu pasien (cek NIK ke Satu Sehat) — verifikasi sebelum backfill.
   Tombol di baris "IHS Satu Sehat" tab Detail. */
const resolvingIhs = ref(false)
async function resolveIhsPasien() {
  const p = profilePatient.value
  if (!p?.id) return
  if (!p.nik) { toast('w', 'Pasien belum punya NIK — lengkapi NIK dulu (tombol Edit).'); return }
  resolvingIhs.value = true
  try {
    const res = await admisiStore.resolveIhs(p.id)
    profilePatient.value = { ...p, satusehat_ihs: res.ihs ?? null }
    toast(res.resolved ? 's' : 'w', res.message || (res.resolved ? `IHS ditemukan: ${res.ihs}` : 'NIK tidak ditemukan di Satu Sehat'))
  } catch (e) {
    toast('e', e.message || 'Gagal menghubungi Satu Sehat')
  } finally {
    resolvingIhs.value = false
  }
}

async function saveProfileEdit() {
  const p = profilePatient.value
  if (!p?.id) return
  if (!profileEdit.name.trim()) { toast('w', 'Nama pasien wajib diisi'); return }
  profileEdit.loading = true; profileEdit.errors = null
  try {
    const payload = {
      name:          profileEdit.name.trim(),
      gender:        profileEdit.gender,
      date_of_birth: profileEdit.date_of_birth || null,
      phone:         profileEdit.phone.trim() || null,
      family_phone:  profileEdit.family_phone.trim() || null,
      email:         profileEdit.email.trim() || null,
      address:       profileEdit.address.trim() || null,
      blood_type:    profileEdit.blood_type || null,
    }
    // NIK hanya boleh diperbaiki selama pasien BELUM punya IHS Satu Sehat.
    // Begitu IHS terbit, NIK = identitas terkunci (kirim ulang akan ditolak).
    if (!p.satusehat_ihs) {
      payload.nik = profileEdit.nik.trim() || null
    }
    // Wilayah: hanya timpa kalau petugas benar-benar memilih ulang dari dropdown.
    // Kalau tidak disentuh (mis. data migrasi tak cocok master → dropdown kosong),
    // pertahankan data lama apa adanya supaya tidak hilang tak sengaja.
    if (wilayahTouched.value) {
      payload.province       = profileEdit.province || null
      payload.nama_kab_kota  = profileEdit.nama_kab_kota || null
      payload.nama_kecamatan = profileEdit.nama_kecamatan || null
    } else {
      payload.province       = profileEdit.origProvince || null
      payload.nama_kab_kota  = profileEdit.origKabKota || null
      payload.nama_kecamatan = profileEdit.origKecamatan || null
    }
    const updated = await admisiStore.updatePasien(p.id, payload)
    profilePatient.value = { ...p, ...updated }
    profileEdit.open = false
    toast('s', 'Data pasien diperbarui')
  } catch (e) {
    profileEdit.errors = e.errors || null
    toast('e', e.message || 'Gagal memperbarui data pasien')
  } finally {
    profileEdit.loading = false
  }
}

/* ============================================================
   REGISTRATION WIZARD (3 steps)
   ============================================================ */
const wizardOpen  = ref(false)
const wizardStep  = ref(1)

/* ─── Preop Bedah state (auto-suggest banner) ─── */
const preopSchedules        = ref([])     // jadwal bedah aktif pasien (hari ini + masa depan)
const preopChoice           = ref(null)   // null | 'PREOP' | 'REGULAR'
const selectedPreopSchedule = ref(null)   // schedule yg dipilih saat pilih PREOP

// Hak "konsultasi kontrol gratis pasca-bedah" milik pasien (badge info saat Kontrol).
const followupEntitlements  = ref([])

const todayPreopSchedule = computed(() => preopSchedules.value.find(s => s.is_today) ?? null)
const upcomingPreopSchedule = computed(() => preopSchedules.value.find(s => !s.is_today) ?? null)
const preopBannerType = computed(() => {
  if (todayPreopSchedule.value) return 'today'      // hijau
  if (upcomingPreopSchedule.value) return 'upcoming' // kuning
  return null
})

function formatPreopDate(d) {
  if (!d) return ''
  const dt = new Date(d)
  return dt.toLocaleDateString('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' })
}

function formatAvDate(d) {
  if (!d) return ''
  const dt = new Date(d)
  return dt.toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function resetPreopState() {
  preopSchedules.value = []
  preopChoice.value = null
  selectedPreopSchedule.value = null
  followupEntitlements.value = []
}

async function loadJadwalBedah(patientId) {
  resetPreopState()
  if (!patientId) return
  try {
    preopSchedules.value = await admisiStore.fetchJadwalBedahAktif(patientId)
  } catch (e) {
    // Silent fail — banner hanya hilang, tidak block form
    preopSchedules.value = []
  }
}

// Hak konsultasi kontrol gratis pasca-bedah (badge info). Silent fail — informatif saja.
async function loadFollowupEntitlements(patientId) {
  followupEntitlements.value = []
  if (!patientId) return
  try {
    const { data } = await admisiApi.kontrolGratis(patientId)
    followupEntitlements.value = data?.data ?? data ?? []
  } catch (e) {
    followupEntitlements.value = []
  }
}

function choosePreop(schedule) {
  selectedPreopSchedule.value = schedule
  preopChoice.value = 'PREOP'
  // Auto-set classification ke Pre-Op supaya konsisten
  form.classification = 'Pre-Op'
  toast('s', schedule.is_today
    ? 'Dipilih: Preop Bedah hari ini'
    : 'Dipilih: Preop hari ini — jadwal akan dipindah ke hari ini')
}

function chooseRegular() {
  preopChoice.value = 'REGULAR'
  selectedPreopSchedule.value = null
  // Kembalikan klasifikasi bila sempat diset ke 'Pre-Op' oleh choosePreop —
  // kunjungan reguler tak boleh terdaftar sebagai Pre-Op.
  if (form.classification === 'Pre-Op') form.classification = 'Baru'
}

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
  identityType:  'KTP',
  nik:           '',
  photo:         null,
  name:          '',
  sex:           'L',
  birthDate:     '',
  age:           '',
  phone:         '',
  familyPhone:   '',
  email:         '',
  // Wilayah (3 level, simpan nama via WilayahPicker)
  province:      '',
  regency:       '',
  district:      '',
  // Snapshot wilayah asal pasien lama (regency/district = nama_kab_kota/nama_kecamatan).
  // Dipakai untuk banner "data lama" + jaga agar tak hilang bila tak dipilih ulang.
  origProvince:  '',
  origRegency:   '',
  origDistrict:  '',
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
  // TPA / Asuransi non-BPJS — data kartu fisik (Sprint 4 modul Asuransi)
  memberName:        '',
  memberCardNumber:  '',
  // COB (Coordination of Benefits) — penjamin kedua, selalu tipe ASURANSI.
  // penjamin1 = penjamin utama (form.guarantor), penjamin2 = asuransi di bawah.
  cobEnabled:        false,
  cobInsurerId:      '',
  cobInsuranceName:  '',
  cobInsuranceNo:    '',
  doctor_schedule_id: '',
})
const form = reactive(blankForm())

// Bila penjamin berganti & dokter terpilih tak lagi sesuai layanannya, reset.
// (Harus SETELAH `form` dideklarasikan — getter watch dievaluasi saat setup.)
watch(() => form.guarantor, () => {
  if (form.doctor_schedule_id &&
      !wizardDoctorList.value.some(d => d.id === form.doctor_schedule_id)) {
    form.doctor_schedule_id = ''
  }
})

/* Wilayah di wizard (pasien lama): status prefill & apakah petugas memilih ulang.
   - wizPrefilled: true=provinsi lama cocok master (dropdown terisi → lock),
                   false=tak cocok (tampil banner + boleh pilih ulang), null=n/a.
   - wizWilayahTouched: true begitu petugas benar2 memilih dari dropdown. */
const wizPrefilled = ref(null)
const wizWilayahTouched = ref(false)
function onWizWilayahPrefill(ok) { wizPrefilled.value = ok }
function onWizWilayahTouched() { wizWilayahTouched.value = true }
// Banner "data wilayah lama tak cocok master" — hanya pasien lama & belum dipilih ulang.
const wizWilayahNeedsRepick = computed(() =>
  form.patientMode === 'existing' && !!form.origProvince
  && wizPrefilled.value === false && !wizWilayahTouched.value
)
// Kunci picker kalau data lama sudah cocok master (tak perlu diubah saat daftar).
const wizWilayahLocked = computed(() =>
  form.patientMode === 'existing' && !!form.origProvince && wizPrefilled.value === true
)

/* ─── Cek eligibilitas BPJS (VClaim) — non-blocking, hanya bantu petugas ───
 * Tidak memblok pendaftaran: kalau integrasi belum aktif, tampil pesan jelas. */
const bpjsCheck = reactive({ loading: false, error: '', peserta: null })
async function cekPesertaBpjs() {
  const id = (form.bpjsNo || form.nik || '').trim()
  if (!id) { bpjsCheck.error = 'Isi No. Kartu BPJS atau NIK dulu'; return }
  bpjsCheck.loading = true; bpjsCheck.error = ''; bpjsCheck.peserta = null
  try {
    const type = form.bpjsNo ? 'nokartu' : 'nik'
    const res = await integrasiApi.cekPeserta({ identifier: id, type })
    const b = res.data?.data ?? {}
    if (b.is_success && b.response?.peserta) {
      bpjsCheck.peserta = b.response.peserta
      // Prefill nomor kartu kalau cari via NIK.
      if (!form.bpjsNo && bpjsCheck.peserta.noKartu) form.bpjsNo = bpjsCheck.peserta.noKartu
    } else {
      bpjsCheck.error = b.metaData?.message || 'Peserta tidak ditemukan'
    }
  } catch (e) {
    bpjsCheck.error = (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message || 'Gagal cek peserta')
  } finally {
    bpjsCheck.loading = false
  }
}

const rujukanCheck = reactive({ loading: false, error: '', data: null })
async function cekRujukanBpjs() {
  if (!form.referralNo) { rujukanCheck.error = 'Isi nomor rujukan dulu'; return }
  rujukanCheck.loading = true; rujukanCheck.error = ''; rujukanCheck.data = null
  try {
    const res = await integrasiApi.cekRujukan({ no_rujukan: form.referralNo.trim(), sumber: 'fktp' })
    const b = res.data?.data ?? {}
    if (b.is_success && b.response?.rujukan) {
      rujukanCheck.data = b.response.rujukan
      // VClaim /Rujukan/{no} mengembalikan data peserta lengkap di dalam rujukan
      // (peserta.noKartu, hakKelas, statusPeserta, cob). Auto-isi No. Kartu BPJS
      // + tampilkan status kepesertaan tanpa perlu Cek Peserta terpisah.
      const ps = rujukanCheck.data.peserta
      if (ps?.noKartu) {
        form.bpjsNo = ps.noKartu
        bpjsCheck.peserta = ps
        bpjsCheck.error = ''
      }
    } else {
      rujukanCheck.error = b.metaData?.message || 'Rujukan tidak ditemukan'
    }
  } catch (e) {
    rujukanCheck.error = (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message || 'Gagal cek rujukan')
  } finally {
    rujukanCheck.loading = false
  }
}

/* Cek Surat Kontrol langsung by NOMOR ke BPJS (VClaim /RencanaKontrol/noSuratKontrol).
 * Beda dari "Tarik dari BPJS" (list by kartu) yang TERBATAS bulan ini+depan & filter
 * tgl-rencana → SC dengan tgl rencana di luar jendela / terbit lewat web VClaim tak
 * muncul. Cek by nomor ini tanpa jendela tanggal → selalu menemukan SC yang valid. */
const kontrolCheck = reactive({ loading: false, error: '', data: null })
async function cekSuratKontrol() {
  const no = (form.controlNo || '').trim()
  if (!no) { kontrolCheck.error = 'Isi nomor surat kontrol dulu'; kontrolCheck.data = null; return }
  kontrolCheck.loading = true; kontrolCheck.error = ''; kontrolCheck.data = null
  try {
    const res  = await admisiApi.bpjs.cekSuratKontrol({ no_surat_kontrol: no })
    const env  = res.data?.data ?? {}
    const meta = env.metaData ?? {}
    // /noSuratKontrol → response = objek SC tunggal; jaga-jaga bila berbentuk list.
    const sc = env.response && !Array.isArray(env.response)
      ? env.response
      : (Array.isArray(env.response?.list) ? env.response.list[0] : null)
    const codeOk = String(meta.code ?? '200') === '200'
    if (sc && (sc.noSuratKontrol || codeOk)) {
      kontrolCheck.data = {
        no:     sc.noSuratKontrol || no,
        tgl:    sc.tglRencanaKontrol || '—',
        poli:   sc.namaPoliTujuan || sc.poliTujuan?.nama || (typeof sc.poliTujuan === 'string' ? sc.poliTujuan : '') || '—',
        dokter: sc.namaDokter || sc.namaDokterAju || (typeof sc.dokter === 'string' ? sc.dokter : '') || '—',
      }
      form.controlNo = kontrolCheck.data.no   // normalisasi ke nomor resmi BPJS
      const kartu = sc.noKartu || sc.noKartuPeserta || ''
      if (kartu && !form.bpjsNo) form.bpjsNo = kartu
    } else {
      kontrolCheck.error = meta.message || 'Surat kontrol tidak ditemukan di BPJS'
    }
  } catch (e) {
    kontrolCheck.error = (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message || 'Gagal cek surat kontrol')
  } finally {
    kontrolCheck.loading = false
  }
}

/* ─── Pre-flight kesiapan SEP (langkah Konfirmasi) ─────────────────────────
 * Cek poli/rujukan/diagnosa/kepesertaan SEBELUM daftar, supaya petugas
 * membetulkan masalah di wizard alih-alih dibanjiri notif "asesmen tidak
 * sesuai"/"Diagnosa Awal Tidak Boleh Kosong" saat auto-SEP berjalan.
 * NON-BLOCKING: registrasi tetap boleh lanjut walau belum hijau. */
const preflight = reactive({ loading: false, ran: false, error: '', report: null })
function resetPreflight() { preflight.loading = false; preflight.ran = false; preflight.error = ''; preflight.report = null }
async function jalankanPreflight() {
  // Hanya relevan untuk BPJS dgn dokter tujuan terpilih; PREOP dikecualikan
  // (SEP-nya manual di hari operasi, bukan saat daftar — sama spt auto-SEP).
  if (form.guarantor !== 'BPJS' || !form.doctor_schedule_id || preopChoice.value === 'PREOP') {
    resetPreflight(); return
  }
  preflight.loading = true; preflight.error = ''; preflight.report = null
  try {
    const res = await admisiApi.bpjs.preflightSep({
      doctor_schedule_id: form.doctor_schedule_id,
      sep_type:           form.sepType,
      no_rujukan:         form.sepType === 'rujukan' ? (form.referralNo || '').trim() : null,
      no_surat_kontrol:   form.sepType === 'kontrol' ? (form.controlNo  || '').trim() : null,
      bpjs_number:        (form.bpjsNo || '').trim() || null,
      nik:                (form.nik || '').trim() || null,
    })
    preflight.report = res.data?.data ?? null
  } catch (e) {
    preflight.error = (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message || 'Gagal memeriksa kesiapan SEP')
  } finally {
    preflight.loading = false
    preflight.ran = true
  }
}

/* ─── Tarik rujukan & surat kontrol pasien dari BPJS by No.Kartu/NIK ───────
 * Untuk pasien (kontrol) yang tidak membawa nomor rujukan/surat kontrol —
 * cukup NIK/No.Kartu, sistem ambil dari BPJS lalu petugas pilih. */
const bpjsPull = reactive({ loading: false, error: '', rujukanError: '', kontrolError: '', rujukan: [], kontrol: [], done: false })
function _pullErr(e) { return (e?.response?.status === 503 ? '⚠ ' : '') + (e?.response?.data?.message || 'Gagal memuat dari BPJS') }

function _extractRujukan(rawResult, sumber) {
  const rj = rawResult?.response?.rujukan
  if (!rj) return []
  const arr = Array.isArray(rj) ? rj : [rj]
  return arr.map((r) => ({
    sumber,
    no:     r.noKunjungan || r.noRujukan || '',
    tgl:    r.tglKunjungan || r.tglRujukan || '',
    poli:   r.poliRujukan?.nmPoli || r.poliRujukan?.nama || r.poliTujuan?.nama || '—',
    diag:   r.diagnosa?.nmDiag || r.diagnosa?.nama || '—',
    faskes: r.ppkPelayanan?.nmProvider || r.provPerujuk?.nama || r.faskesPerujuk?.nama || '—',
  })).filter((x) => x.no)
}

function _extractKontrol(rawResult) {
  const list = rawResult?.response?.list ?? rawResult?.response
  if (!list) return []
  const arr = Array.isArray(list) ? list : [list]
  return arr.map((k) => ({
    no:     k.noSuratKontrol || '',
    tgl:    k.tglRencanaKontrol || '',
    // poliTujuan kadang string, kadang objek {nama} — jangan render objek mentah.
    poli:   k.namaPoliTujuan || k.poliTujuan?.nama || (typeof k.poliTujuan === 'string' ? k.poliTujuan : '') || '—',
    dokter: k.namaDokter || k.namaDokterAju || (typeof k.dokter === 'string' ? k.dokter : '') || '—',
    jenis:  k.namaJnsKontrol || k.jnsKontrol || '',
  })).filter((x) => x.no)
}

async function tarikDataBpjs() {
  const id = (form.bpjsNo || form.nik || '').trim()
  if (!id) { bpjsPull.error = 'Isi No. Kartu BPJS atau NIK pasien dulu'; return }
  const payload = form.bpjsNo ? { bpjs_number: form.bpjsNo.trim() } : { nik: form.nik.trim() }
  bpjsPull.loading = true
  bpjsPull.error = ''; bpjsPull.rujukanError = ''; bpjsPull.kontrolError = ''
  bpjsPull.rujukan = []; bpjsPull.kontrol = []; bpjsPull.done = false
  try {
    const [rj, sk] = await Promise.allSettled([
      admisiApi.bpjs.rujukanByKartu(payload),
      admisiApi.bpjs.suratKontrolByKartu(payload),
    ])
    if (rj.status === 'fulfilled') {
      const d = rj.value.data?.data ?? {}
      if (d.no_kartu && !form.bpjsNo) form.bpjsNo = d.no_kartu
      bpjsPull.rujukan = [..._extractRujukan(d.fktp, 'FKTP'), ..._extractRujukan(d.rs, 'FKRTL')]
    } else {
      bpjsPull.rujukanError = _pullErr(rj.reason)
    }
    if (sk.status === 'fulfilled') {
      const d = sk.value.data?.data ?? {}
      if (d.no_kartu && !form.bpjsNo) form.bpjsNo = d.no_kartu
      bpjsPull.kontrol = _extractKontrol(d.result)
    } else {
      bpjsPull.kontrolError = _pullErr(sk.reason)
    }
    // Dua-duanya gagal → tampilkan error utama (mis. integrasi 503 / NIK tak ditemukan).
    if (rj.status === 'rejected' && sk.status === 'rejected') {
      bpjsPull.error = _pullErr(rj.reason)
    }
    bpjsPull.done = true
  } finally {
    bpjsPull.loading = false
  }
}

function pilihRujukanPull(item) {
  form.sepType = 'rujukan'
  form.referralNo = item.no
  toast('s', `Rujukan ${item.no} dipilih`)
}
function pilihKontrolPull(item) {
  form.sepType = 'kontrol'
  form.controlNo = item.no
  toast('s', `Surat kontrol ${item.no} dipilih`)
}
function resetBpjsPull() {
  bpjsPull.loading = false
  bpjsPull.error = ''; bpjsPull.rujukanError = ''; bpjsPull.kontrolError = ''
  bpjsPull.rujukan = []; bpjsPull.kontrol = []; bpjsPull.done = false
  kontrolCheck.loading = false; kontrolCheck.error = ''; kontrolCheck.data = null
}

/* ─── Aksi Cepat: panel mana yang terbuka ('' | 'rujukan' | 'peserta') ─── */
const quickPanel = ref('')   // default: semua tertutup (search bar ter-hide)
function toggleQuickPanel(name) {
  quickPanel.value = quickPanel.value === name ? '' : name
}

/* ─── Panel kanan collapsible (lega & dinamis) — state per-perangkat ─── */
const RIGHT_COLLAPSE_KEY = 'admisi.rightCollapse'
function loadRightCollapse() {
  try {
    const v = JSON.parse(localStorage.getItem(RIGHT_COLLAPSE_KEY) ?? '{}')
    return { aksi: !!v.aksi, status: !!v.status, antrean: !!v.antrean }
  } catch (_) {
    return { aksi: false, status: false, antrean: false }
  }
}
const rightCollapse = reactive(loadRightCollapse())
function toggleRight(name) {
  rightCollapse[name] = !rightCollapse[name]
  try { localStorage.setItem(RIGHT_COLLAPSE_KEY, JSON.stringify(rightCollapse)) } catch (_) { /* storage penuh / private */ }
}

/* ─── Aksi Cepat: Cek No. Rujukan ke VClaim (standalone, mandiri form) ─── */
const rujukanQuick = reactive({ no: '', sumber: 'fktp', loading: false, error: '', data: null })
async function cekRujukanQuick() {
  const no = rujukanQuick.no.trim()
  if (!no) { rujukanQuick.error = 'Isi nomor rujukan dulu'; rujukanQuick.data = null; return }
  rujukanQuick.loading = true; rujukanQuick.error = ''; rujukanQuick.data = null
  try {
    const res = await integrasiApi.cekRujukan({ no_rujukan: no, sumber: rujukanQuick.sumber })
    const b = res.data?.data ?? {}
    if (b.is_success && b.response?.rujukan) {
      rujukanQuick.data = b.response.rujukan
    } else {
      rujukanQuick.error = b.metaData?.message || 'Rujukan tidak ditemukan'
    }
  } catch (e) {
    rujukanQuick.error = (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message || 'Gagal cek rujukan')
  } finally {
    rujukanQuick.loading = false
  }
}
// Pakai No. Rujukan hasil cek → buka wizard pendaftaran BPJS dgn tipe rujukan terisi.
function pakaiRujukanQuick() {
  const no = rujukanQuick.no.trim()
  openWizard()                 // reset form + buka modal
  form.guarantor = 'BPJS'
  form.sepType   = 'rujukan'
  form.referralNo = no
  rujukanCheck.data  = rujukanQuick.data   // tampilkan hasil cek di field rujukan wizard
  rujukanCheck.error = ''
  toast('s', 'No. rujukan disalin ke form pendaftaran')
}

/* ─── Aksi Cepat: Cek Status BPJS (= Cek Peserta VClaim), standalone ──── */
const pesertaQuick = reactive({ id: '', type: 'nik', loading: false, error: '', data: null })
async function cekPesertaQuick() {
  const id = pesertaQuick.id.trim()
  if (!id) { pesertaQuick.error = 'Isi No. Kartu BPJS atau NIK dulu'; pesertaQuick.data = null; return }
  pesertaQuick.loading = true; pesertaQuick.error = ''; pesertaQuick.data = null
  try {
    const res = await integrasiApi.cekPeserta({ identifier: id, type: pesertaQuick.type })
    const b = res.data?.data ?? {}
    if (b.is_success && b.response?.peserta) {
      pesertaQuick.data = b.response.peserta
    } else {
      pesertaQuick.error = b.metaData?.message || 'Peserta tidak ditemukan'
    }
  } catch (e) {
    pesertaQuick.error = (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message || 'Gagal cek status BPJS')
  } finally {
    pesertaQuick.loading = false
  }
}

/* Auto-terbit SEP setelah registrasi BPJS (non-blocking). Bila gagal — poli belum
   dipetakan / rujukan tak ada / BPJS down — registrasi TETAP sukses & petugas tetap
   bisa menerbitkan SEP manual dari Detail Pasien (tombol Terbitkan SEP). Backend
   bpjsGenerateSep me-resolve No. Kartu dari NIK bila kolomnya kosong. */
async function autoTerbitkanSep({ visitId, name }) {
  if (!visitId) return
  try {
    const res = await admisiApi.bpjs.generateSep({ visit_id: visitId })
    const b = res.data?.data ?? {}
    const noSep = b.response?.sep?.noSep
    if (noSep) {
      toast('s', `SEP terbit: ${noSep}${name ? ` · ${name}` : ''}`)
      admisiStore.fetchVisits?.()
    } else {
      toast('w', `SEP belum terbit${name ? ` untuk ${name}` : ''}: ${b.metaData?.message || 'data BPJS belum lengkap'} — terbitkan manual dari Detail Pasien.`)
    }
  } catch (e) {
    const msg = e.response?.data?.message || 'gagal menghubungi BPJS'
    toast('w', `SEP belum terbit${name ? ` untuk ${name}` : ''}: ${msg} — terbitkan manual dari Detail Pasien.`)
  }
}

/* ─── Terbitkan / Batalkan SEP dari panel detail kunjungan ───────────── */
const sepAction = reactive({ loading: false, printing: false, error: '' })
async function terbitkanSep() {
  const row = visitDetailRow.value
  if (!row?.id) return
  sepAction.loading = true
  sepAction.error = ''   // bersihkan error percobaan sebelumnya
  try {
    const res = await admisiApi.bpjs.generateSep({ visit_id: row.id })
    const b = res.data?.data ?? {}
    const noSep = b.response?.sep?.noSep
    if (noSep) {
      row.noSep = noSep
      toast('s', `SEP terbit: ${noSep}`)
      admisiStore.fetchVisits?.()
    } else {
      // Gagal "sukses-tapi-ditolak" (mis. metaData BPJS) — tampilkan di toast DAN
      // inline modal supaya petugas pasti melihat penyebabnya, bukan toast sekejap.
      const msg = b.metaData?.message || res.data?.message || 'Gagal terbitkan SEP'
      sepAction.error = msg
      toast('e', msg)
    }
  } catch (e) {
    // Error HTTP (422 pemetaan poli, 503 VClaim off, dll) — pesan backend yang jelas
    // ditahan di modal sampai percobaan/penutupan berikutnya.
    const msg = (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message || 'Gagal terbitkan SEP')
    sepAction.error = msg
    toast('e', msg)
  } finally {
    sepAction.loading = false
  }
}
async function batalkanSep() {
  const row = visitDetailRow.value
  if (!row?.noSep) return
  if (!confirm(`Batalkan SEP ${row.noSep}?`)) return
  sepAction.loading = true
  try {
    const res = await admisiApi.bpjs.cancelSep({ no_sep: row.noSep, alasan: 'Dibatalkan dari Admisi' })
    const b = res.data?.data ?? {}
    if (b.is_success) {
      toast('s', 'SEP dibatalkan')
      row.noSep = null
      admisiStore.fetchVisits?.()
    } else {
      toast('e', b.metaData?.message || 'Gagal batalkan SEP')
    }
  } catch (e) {
    toast('e', (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message || 'Gagal batalkan SEP'))
  } finally {
    sepAction.loading = false
  }
}

async function cetakSep() {
  const row = visitDetailRow.value
  if (!row?.id || !row?.noSep) return
  sepAction.printing = true
  try {
    // HTML-print: browser yang mencetak → Chrome hormati @page size 13x21 cm,
    // dialog cetak (& preview) otomatis kertas 13x21. Bisa juga "Save as PDF".
    const res = await admisiApi.bpjs.cetakSepHtml(row.id)
    const url = URL.createObjectURL(new Blob([res.data], { type: 'text/html' }))
    const w = window.open(url, '_blank')
    if (!w) toast('w', 'Izinkan pop-up untuk mencetak SEP')
    // Beri waktu tab baru memuat + dialog cetak muncul sebelum URL dilepas.
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch (e) {
    toast('e', e.response?.data?.message || 'Gagal cetak SEP')
  } finally {
    sepAction.printing = false
  }
}

/* ─── Edit SEP (PUT /SEP/2.0/update) dari panel detail — field ringkas ─── */
const sepEdit = reactive({ open: false, loading: false, kls_rawat: '3', diag_awal: '', catatan: '', no_telp: '', katarak: '0' })
function startEditSep() {
  // Prefill seadanya; field SEP detail tak di-cache lokal, dokter isi koreksi.
  Object.assign(sepEdit, { open: true, kls_rawat: '3', diag_awal: '', catatan: '', no_telp: '', katarak: '0' })
}
async function saveEditSep() {
  const row = visitDetailRow.value
  if (!row?.id) return
  sepEdit.loading = true
  try {
    await admisiApi.bpjs.updateSep({
      visit_id: row.id,
      kls_rawat: sepEdit.kls_rawat || null,
      diag_awal: sepEdit.diag_awal || null,
      catatan: sepEdit.catatan || null,
      no_telp: sepEdit.no_telp || null,
      katarak: sepEdit.katarak || '0',
    })
    toast('s', 'SEP diperbarui di BPJS')
    sepEdit.open = false
  } catch (e) {
    toast('e', (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message || 'Gagal update SEP'))
  } finally {
    sepEdit.loading = false
  }
}

/* ─── Diagnosa Awal SEP: tarik dari rujukan / input manual dari panel detail ──
 * BPJS menolak SEP bila diagnosa awal kosong. Diagnosa diisi otomatis dari
 * rujukan FKTP (tombol Tarik / saat terbit SEP), atau diketik manual ICD-10. */
const diagAction = reactive({ loading: false, saving: false, editing: false, kode: '', nama: '' })
function startEditDiagnosa() {
  const row = visitDetailRow.value
  diagAction.editing = true
  diagAction.kode = row?.diagnosaAwal ?? ''
  diagAction.nama = row?.diagnosaAwalNama ?? ''
}
async function tarikDiagnosa() {
  const row = visitDetailRow.value
  if (!row?.id) return
  diagAction.loading = true
  try {
    const res = await admisiApi.bpjs.tarikDiagnosa(row.id)
    const d = res.data?.data ?? {}
    row.diagnosaAwal = d.kode ?? null
    row.diagnosaAwalNama = d.nama ?? null
    // Backend bisa me-resolve & menyimpan No. Rujukan dari BPJS (via No. Kartu)
    // saat visit belum punya — refleksikan ke baris agar title tombol akurat.
    if (d.no_rujukan) row.noRujukan = d.no_rujukan
    toast('s', `Diagnosa: ${d.kode}${d.nama ? ' · ' + d.nama : ''}`)
    admisiStore.fetchVisits?.()
  } catch (e) {
    toast('e', (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message || 'Gagal tarik diagnosa'))
  } finally {
    diagAction.loading = false
  }
}
async function simpanDiagnosa() {
  const row = visitDetailRow.value
  if (!row?.id) return
  const kode = diagAction.kode.trim().toUpperCase()
  if (!kode) { toast('e', 'Isi kode diagnosa (ICD-10) dulu'); return }
  diagAction.saving = true
  try {
    const res = await admisiApi.bpjs.setDiagnosa(row.id, { diag_awal: kode, diag_nama: diagAction.nama.trim() })
    const d = res.data?.data ?? {}
    row.diagnosaAwal = d.kode ?? null
    row.diagnosaAwalNama = d.nama ?? null
    diagAction.editing = false
    toast('s', 'Diagnosa awal disimpan')
    admisiStore.fetchVisits?.()
  } catch (e) {
    toast('e', e.response?.data?.message || 'Gagal simpan diagnosa')
  } finally {
    diagAction.saving = false
  }
}

/* ─── Surat Kontrol BPJS: status + edit tanggal dari panel detail ────── */
const skAction = reactive({ loading: false, data: null, editing: false, newDate: '' })
async function loadSuratKontrolDetail(visitId) {
  skAction.data = null; skAction.editing = false; skAction.newDate = ''
  if (!visitId) return
  try {
    const { data } = await admisiApi.bpjs.getSuratKontrol(visitId)
    skAction.data = data.data ?? null
  } catch { skAction.data = null }
}
function startEditSuratKontrol() {
  skAction.editing = true
  skAction.newDate = skAction.data?.tanggal_rencana_kontrol ?? ''
}
async function saveEditSuratKontrol() {
  if (!skAction.data?.id) return
  if (!skAction.newDate) { toast('e', 'Isi tanggal kontrol baru'); return }
  skAction.loading = true
  try {
    await admisiApi.bpjs.editSuratKontrol({ id: skAction.data.id, tgl_rencana_kontrol: skAction.newDate })
    toast('s', 'Surat Kontrol diperbarui di BPJS')
    skAction.editing = false
    await loadSuratKontrolDetail(visitDetailRow.value?.id)
  } catch (e) {
    toast('e', (e.response?.status === 503 ? '⚠ ' : '') + (e.response?.data?.message || 'Gagal update Surat Kontrol'))
  } finally {
    skAction.loading = false
  }
}

/* Jenis identitas — KTP wajib 16 digit, lainnya nomor bebas, Tanpa Identitas boleh kosong */
const identityTypes = [
  { value: 'KTP',             label: 'KTP (NIK)',        numberLabel: 'NIK',            ph: '16 digit NIK' },
  { value: 'PASPOR',          label: 'Paspor',           numberLabel: 'No. Paspor',     ph: 'Nomor paspor' },
  { value: 'SIM',             label: 'SIM',              numberLabel: 'No. SIM',        ph: 'Nomor SIM' },
  { value: 'KIA',             label: 'KIA (Anak)',       numberLabel: 'No. KIA',        ph: 'Nomor Kartu Identitas Anak' },
  { value: 'TANPA_IDENTITAS', label: 'Tanpa Identitas',  numberLabel: 'No. Identitas',  ph: 'Tidak ada identitas' },
  { value: 'LAINNYA',         label: 'Identitas Lain',   numberLabel: 'No. Identitas',  ph: 'Nomor identitas' },
]
const activeIdentity = computed(() => identityTypes.find(t => t.value === form.identityType) ?? identityTypes[0])
const noIdentity     = computed(() => form.identityType === 'TANPA_IDENTITAS')

function onIdentityTypeChange() {
  if (noIdentity.value) form.nik = ''
}

/* Foto pasien — modal kamera/upload */
const photoModalOpen = ref(false)
function onPhotoCaptured(dataUrl) { form.photo = dataUrl }

/* ============================================================
   GENERAL CONSENT (Form Registry) — pasien baru, opsional
   Diteken pasien/wali di step 3 sebelum cetak antrean. Data
   auto-fill dari form (pasien baru belum punya visit_id/patient_id).
   ============================================================ */
const CONSENT_CODE = 'GENERAL_CONSENT'
const consentModalOpen   = ref(false)      // modal tinjau + alur TTD
const consentHtml        = ref('')          // HTML preview hasil render backend
const consentLoading     = ref(false)
const consentError       = ref('')
const consentSignFields  = ref([])          // [{key,label,signer_type,required}]
const consentSignatures  = ref([])          // [{signer_type, signature_svg, signature_png_base64, external_identity?, audit_log?, biometric_metadata?}]
const sigCaptureOpen     = ref(false)
const sigCaptureType     = ref('patient')   // signer_type yang sedang di-capture
const sigCaptureLabel    = ref('')

// Pasien (signer_type=patient) sudah TTD?
const consentPatientSigned = computed(() =>
  consentSignatures.value.some(s => s.signer_type === 'patient')
)
// Semua signer required sudah TTD?
const consentAllSigned = computed(() => {
  const required = consentSignFields.value.filter(f => f.required).map(f => f.signer_type)
  if (!required.length) return consentPatientSigned.value
  return required.every(st => consentSignatures.value.some(s => s.signer_type === st))
})

function resetConsentState() {
  consentModalOpen.value  = false
  consentHtml.value       = ''
  consentError.value      = ''
  consentSignFields.value = []
  consentSignatures.value = []
  sigCaptureOpen.value    = false
}

// Build payload identitas dari form untuk render preview.
function consentFormPayload() {
  const addressParts = [form.addressDetail, form.district, form.regency].filter(Boolean)
  return {
    template_code: CONSENT_CODE,
    name:          form.name,
    nik:           form.nik || null,
    no_rm:         form.noRm || null,
    gender:        form.sex,
    date_of_birth: form.birthDate || null,
    address:       addressParts.join(', ') || null,
    phone:         form.phone || null,
  }
}

// Re-render preview dengan TTD yang sudah ter-capture (supaya tampak di HTML).
async function refreshConsentPreview() {
  consentLoading.value = true
  consentError.value = ''
  try {
    const payload = consentFormPayload()
    payload.signatures = consentSignatures.value
      .filter(s => s.signature_svg)
      .map(s => ({ signer_type: s.signer_type, signature_svg: s.signature_svg }))
    const { data } = await admisiApi.previewConsent(payload)
    consentHtml.value       = data.data?.html ?? ''
    consentSignFields.value = data.data?.signature_fields ?? []
  } catch (e) {
    consentError.value = e.response?.data?.message ?? 'Gagal memuat dokumen consent.'
  } finally {
    consentLoading.value = false
  }
}

async function openConsentModal() {
  consentModalOpen.value = true
  await refreshConsentPreview()
}

// Buka SignatureCaptureModal untuk signer tertentu.
function startSignature(field) {
  sigCaptureType.value  = field.signer_type
  sigCaptureLabel.value = field.label || ''
  sigCaptureOpen.value  = true
}

// Hasil capture dari SignatureCaptureModal → simpan + re-render preview.
async function onConsentCapture(payload) {
  // Replace signature signer_type yang sama (re-sign).
  consentSignatures.value = consentSignatures.value.filter(s => s.signer_type !== sigCaptureType.value)
  consentSignatures.value.push({
    signer_type:          sigCaptureType.value,
    signature_svg:        payload.signature_svg,
    signature_png_base64: payload.signature_png_base64,
    external_identity:    payload.external_identity || null,
    biometric_metadata:   payload.biometric_metadata || null,
    audit_log:            payload.audit_log || [],
  })
  await refreshConsentPreview()
}

function isSignerSigned(signerType) {
  return consentSignatures.value.some(s => s.signer_type === signerType)
}

const canProceedStep1 = computed(() => {
  const idOk = noIdentity.value || !!form.nik
  if (form.patientMode === 'existing') {
    // Pasien lama: provinsi tidak wajib di sini — data wilayah yang kosong
    // (mis. hasil migrasi) bisa dilengkapi nanti via kartu Detail Pasien.
    // Blokir bila pasien masih punya kunjungan aktif (cegah daftar ganda) —
    // selaras peringatan di selectPatient; backend juga menolaknya.
    return !!(form.found && form.name) && !selectedActiveVisit.value
  }
  // Pasien baru: identitas + tgl lahir + provinsi wajib.
  return !!(form.name && form.birthDate && idOk && form.province)
})

const canProceedStep2 = computed(() => {
  const pd = !!form.doctor_schedule_id
  // COB aktif → penjamin kedua (asuransi) wajib dipilih
  const cobOk = !form.cobEnabled || !!form.cobInsurerId
  if (form.guarantor === 'UMUM') return pd
  if (['ASURANSI','PERUSAHAAN','SOSIAL'].includes(form.guarantor)) return !!form.insurer_id && pd && cobOk
  if (form.guarantor === 'BPJS') {
    if (form.sepType === 'rujukan') return !!form.bpjsNo && !!form.referralNo && pd && cobOk
    if (form.sepType === 'kontrol') return !!form.bpjsNo && !!form.controlNo && pd && cobOk
    return !!form.bpjsNo && !!form.bookingCode && pd && cobOk
  }
  return pd
})

function resetWizWilayah() { wizPrefilled.value = null; wizWilayahTouched.value = false }

function openWizard() {
  walkInVisitId.value = null
  walkInQueueNo.value = ''
  pendingWalkIn.value = null
  Object.assign(form, blankForm())
  insuranceSearch.value = ''
  searchResults.value   = []
  selectedActiveVisit.value = null
  resetWizWilayah()
  wizardStep.value = 1
  wizardOpen.value = true
  resetPreopState()
  resetConsentState()
  resetBpjsPull()
  resetPreflight()
}
function closeWizard() {
  wizardOpen.value = false
  walkInVisitId.value = null
  walkInQueueNo.value = ''
  pendingWalkIn.value = null
  resetPreopState()
  resetConsentState()
}

// Pasien punya kunjungan aktif → registrasi diblok. Tutup wizard & lompat ke daftar
// "Belum Selesai" supaya petugas menyelesaikan/membatalkan kunjungan itu dulu.
function lihatKunjunganAktif() {
  closeWizard()
  setCareType('AKTIF')
  toast('i', 'Menampilkan kunjungan yang belum selesai — selesaikan / batalkan dulu')
}

function nextStep() {
  if (wizardStep.value === 1 && !canProceedStep1.value) {
    toast('w', form.patientMode === 'existing'
      ? (selectedActiveVisit.value
          ? 'Pasien masih punya kunjungan aktif — selesaikan/batalkan dulu'
          : 'Pilih pasien dari hasil pencarian terlebih dahulu')
      : 'Lengkapi data pasien & provinsi terlebih dahulu')
    return
  }
  if (wizardStep.value === 2 && !canProceedStep2.value) { toast('w', 'Lengkapi data penjamin & pilih dokter tujuan'); return }
  if (wizardStep.value < 3) {
    wizardStep.value++
    // Masuk ke Konfirmasi → jalankan pre-flight SEP (BPJS) supaya petugas tahu
    // masalah poli/rujukan/diagnosa sebelum menekan "Daftarkan".
    if (wizardStep.value === 3) jalankanPreflight()
  }
}
function prevStep() { if (wizardStep.value > 1) wizardStep.value-- }

function setPatientMode(mode) {
  Object.assign(form, blankForm())
  form.patientMode      = mode
  insuranceSearch.value = ''
  searchResults.value   = []
  selectedActiveVisit.value = null
  resetWizWilayah()
  resetPreopState()
  resetConsentState()
}

function guarantorLabel(g) {
  return { BPJS:'BPJS Kesehatan', UMUM:'Umum / Mandiri', ASURANSI:'Asuransi Swasta', PERUSAHAAN:'Perusahaan / Rekanan', SOSIAL:'Sosial / Gratis' }[g] ?? g
}

/* Ambil nomor antrean TR (TRIASE/REFRAKSIONIS) dari visit hasil pendaftaran.
   Untuk walk-in, visit.queues juga berisi queue ADMISI lama (A-xxx) yang sudah
   COMPLETED — jadi nomor ADMISI tidak boleh dipakai untuk tiket. */
function pickTrQueueNo(visit) {
  const qs = visit?.queues ?? []
  const tr = qs.find(q => q.station === 'TRIASE' || q.station === 'REFRAKSIONIS')
  return tr?.queue_number ?? qs.find(q => q.station !== 'ADMISI')?.queue_number ?? '—'
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

    // Preop bedah: kalau user pilih PREOP, inject visit_type & schedule
    if (preopChoice.value === 'PREOP' && selectedPreopSchedule.value) {
      payload.visit_type          = 'PREOP_BEDAH'
      payload.surgery_schedule_id = selectedPreopSchedule.value.id
    }

    if (form.guarantor === 'BPJS') {
      payload.bpjs_booking_code = form.sepType === 'jkn'     ? form.bookingCode : null
      payload.bpjs_referral_no  = form.sepType === 'rujukan' ? form.referralNo  : null
      payload.bpjs_control_no   = form.sepType === 'kontrol' ? form.controlNo   : null
    }
    if (['ASURANSI','PERUSAHAAN','SOSIAL'].includes(form.guarantor)) {
      payload.insurer_id = form.insurer_id
    }
    // TPA non-BPJS — data kartu untuk verifikasi eligibility paralel oleh billing
    if (['ASURANSI','PERUSAHAAN'].includes(form.guarantor)) {
      payload.policy_number      = form.insuranceNo      || null
      payload.member_name        = form.memberName       || null
      payload.member_card_number = form.memberCardNumber || null
    }

    // COB — penjamin kedua menanggung selisih. Backend simpan ke visit_cob.
    // penjamin1 = penjamin utama (BPJS/Asuransi/Perusahaan); penjamin2 = Asuransi/Perusahaan.
    if (['BPJS','ASURANSI','PERUSAHAAN'].includes(form.guarantor) && form.cobEnabled && form.cobInsurerId) {
      const cobIns = (admisiStore.insurers ?? []).find(i => i.id === form.cobInsurerId)
      payload.cob = {
        penjamin1_type:       form.guarantor,
        penjamin1_insurer_id: form.insurer_id || null,
        penjamin2_type:       cobIns?.type ?? 'ASURANSI',
        penjamin2_insurer_id: form.cobInsurerId,
        notes:                form.cobInsuranceNo ? `Polis penjamin 2: ${form.cobInsuranceNo}` : null,
      }
    }

    // Foto kunjungan ini — selalu dikirim di payload (baru maupun lama).
    // Backend menyimpannya ke visits.photo_path (per-kunjungan) + patients.photo_path (terbaru).
    payload.photo = form.photo?.startsWith('data:') ? form.photo : null

    if (form.patientId) {
      payload.patient_id = form.patientId
      // Pasien lama: kalau petugas memilih ulang wilayah (data lama tak cocok
      // master), kirim perubahan agar di-replace ke data pasien saat daftar.
      if (wizWilayahTouched.value) {
        payload.update_wilayah = {
          province:       form.province || null,
          nama_kab_kota:  form.regency  || null,
          nama_kecamatan: form.district || null,
        }
      }
    } else {
      Object.assign(payload, {
        identity_type: form.identityType,
        nik:           form.nik      || null,
        name:          form.name,
        gender:        form.sex,
        date_of_birth: form.birthDate,
        phone:         form.phone       || null,
        family_phone:  form.familyPhone || null,
        email:         form.email       || null,
        address:       addressParts.join(', ') || null,
        province:      form.province || null,
        bpjs_number:   form.bpjsNo   || null,
      })
    }

    // General Consent (opsional) — hanya pasien baru & sudah ditandatangani
    // pasien/wali. TTD + jawaban ikut dikirim; backend simpan PatientDocument
    // FINALIZED setelah visit lahir.
    if (form.patientMode === 'new' && consentPatientSigned.value) {
      payload.consent = {
        template_code: CONSENT_CODE,
        signatures: consentSignatures.value.map(s => ({
          signer_type:          s.signer_type,
          signature_svg:        s.signature_svg,
          signature_png_base64: s.signature_png_base64,
          external_identity:    s.external_identity || null,
          biometric_metadata:   s.biometric_metadata || null,
          audit_log:            s.audit_log || [],
        })),
      }
    }

    // Branch: walk-in merge vs registrasi baru.
    // Tiket dicetak dengan nomor TR (Triase & Refraksionis) — tujuan pasien
    // setelah admisi — BUKAN nomor A-xxx dari kiosk. Walk-in maupun daftar
    // langsung sama-sama menuju TR-NNN.
    let visit
    if (walkInVisitId.value) {
      visit = await admisiStore.daftarkanWalkIn(walkInVisitId.value, payload)
    } else {
      visit = await admisiStore.daftarKunjungan(payload)
    }
    const queueNo = pickTrQueueNo(visit)

    // Capture data label pasien (No. RM dari pasien hasil daftar — untuk pasien
    // baru No. RM digenerate backend) sebelum closeWizard mereset form.
    const lp = visit?.patient ?? {}
    const labelData = {
      name:      lp.name ?? form.name,
      noRm:      lp.no_rm ?? form.noRm ?? '-',
      birthDate: lp.date_of_birth ?? form.birthDate,  // mentah (ISO/DMY) — printLabel parse sendiri
      sex:       lp.gender ?? form.sex,
      nik:       lp.nik ?? form.nik ?? '—',
      queueNo,
    }

    const action = walkInVisitId.value ? 'didaftarkan dari Anjungan' : 'terdaftar'

    // Konteks auto-SEP ditangkap SEBELUM closeWizard mereset form. SEP diterbitkan
    // otomatis untuk BPJS (non-blocking) — lihat autoTerbitkanSep di bawah.
    const sepCtx = {
      visitId:    visit?.id ?? null,
      isBpjs:     form.guarantor === 'BPJS',
      alreadySep: !!visit?.no_sep,
      name:       form.name,
    }
    // PREOP_BEDAH dikecualikan dari auto-SEP: operasinya sering H+1 → SEP harus
    // bertanggal hari operasi, bukan hari daftar. SEP pre-op diterbitkan manual dari
    // Detail Pasien saat hari operasi. (jenis_pelayanan PREOP tetap RAJAL → tglSep=now.)
    const willIssueSep = sepCtx.isBpjs && sepCtx.visitId && !sepCtx.alreadySep
      && preopChoice.value !== 'PREOP'

    // Toast registrasi TIDAK lagi mengklaim "menerbitkan SEP…" — hasil SEP (terbit /
    // belum) dimiliki sepenuhnya oleh autoTerbitkanSep agar tak terbaca "gagal".
    const msgs = {
      BPJS:     `${form.name} ${action} · Antrean ${queueNo}`,
      ASURANSI: `${form.name} ${action} (${form.insuranceName}) · Antrean ${queueNo}`,
    }
    toast('s', msgs[form.guarantor] ?? `${form.name} ${action} · Antrean ${queueNo}`)
    closeWizard()

    // Cetak label pasien (58×40mm) saja saat selesai daftar — tiket antrean tidak
    // ikut tercetak otomatis. Butuh izin popup browser.
    nextTick(() => {
      printLabel(labelData)
    })

    // Auto-terbit SEP (non-blocking) untuk BPJS. Bila gagal — poli belum dipetakan /
    // rujukan tak ada / BPJS down — registrasi TETAP sukses & tiket tetap tercetak;
    // petugas dapat menerbitkan SEP manual dari Detail Pasien. Sengaja TIDAK di-await
    // agar tak menahan refresh/indikator submit.
    if (willIssueSep) autoTerbitkanSep(sepCtx)

    // Refresh antrian + kunjungan setelah pendaftaran
    await Promise.allSettled([admisiStore.fetchAntrian(), admisiStore.fetchDashboard()])
    admisiStore.fetchVisits()
  } catch (e) {
    const firstErr = e.errors ? Object.values(e.errors)[0]?.[0] : null
    toast('e', firstErr ?? e.message ?? 'Pendaftaran gagal')
  } finally {
    submitting.value = false
  }
}

function onBirthChange() { form.age = calcAge(form.birthDate) }

/* ─── Input tgl lahir DD/MM/YYYY ───
   form.birthDate tetap ISO (YYYY-MM-DD) sbg source of truth (backend + calcAge).
   birthDateText = string tampilan DD/MM/YYYY; di-mask saat ketik & dikonversi ke
   ISO begitu lengkap & valid. */
const birthDateText = ref('')

function onBirthTextInput(e) {
  birthDateText.value = maskDmy(e.target.value)
  const iso = dmyToIso(birthDateText.value)
  form.birthDate = iso          // '' bila belum lengkap/invalid → blokir lanjut step
  form.age = iso ? calcAge(iso) : ''
}

// Sinkronkan teks saat birthDate di-set dari luar (prefill pasien lama / reset form).
watch(() => form.birthDate, (iso) => {
  // Hanya sinkronkan bila perubahan datang dari LUAR (prefill/reset), bukan dari
  // user yang sedang mengetik. Kalau teks saat ini sudah mem-parse ke iso yang
  // sama (mis. user backspace tanggal lengkap jadi tak lengkap → iso ''), jangan
  // timpa — kalau ditimpa, seluruh isian teks terhapus, bukan cuma 1 karakter.
  if (dmyToIso(birthDateText.value) === iso) return
  const asText = isoToDmy(iso)
  if (asText !== birthDateText.value) birthDateText.value = asText
}, { immediate: true })

/* ============================================================
   DETAIL KUNJUNGAN MODAL
   Menggantikan modal "Detail Pasien" + "Rekam Medis" lama.
   ============================================================ */
const visitDetailOpen = ref(false)
const visitDetailRow  = ref(null)

function openVisitDetail(p) {
  visitDetailRow.value = p
  visitDetailOpen.value = true
  sepEdit.open = false
  diagAction.editing = false
  sepAction.error = ''
  if (p?.guarantor === 'BPJS' && p?.id) loadSuratKontrolDetail(p.id)
  else { skAction.data = null; skAction.editing = false }
}
function closeVisitDetail() { visitDetailOpen.value = false }

// Klik nama pasien di Detail Kunjungan → buka Profil Pasien (detail + riwayat).
function openPatientFromVisit() {
  const p = visitDetailRow.value
  if (!p?.patientId) { toast('w', 'Pasien walk-in belum terdaftar — tidak ada profil'); return }
  openProfile({ id: p.patientId, name: p.name })
}

// Klik baris riwayat kunjungan (modal Profil Pasien) → buka modal Detail Kunjungan
// untuk kunjungan itu (lihat detail + edit/update SEP & Surat Kontrol).
// Tutup modal Profil dulu supaya tidak ada dua modal-sm bertumpuk.
function openVisitDetailFromRiwayat(v) {
  const pt = profilePatient.value
  const row = {
    ...v,
    name:      pt?.name ?? '—',
    patientId: pt?.id ?? null,
    // Identitas pasien dari profil — agar "Cetak Label" & tampilan detail tidak
    // kosong (baris riwayat tak membawa noRm/nik/sex/birthDate).
    noRm:      pt?.no_rm ?? '—',
    nik:       pt?.nik ?? '—',
    sex:       pt?.gender ?? '—',
    birthDate: pt?.date_of_birth ?? null,
  }
  closeProfile()
  openVisitDetail(row)
}

function gotoRekamMedis(p) {
  if (!p?.patientId) { toast('w', 'Pasien belum memiliki ID — coba reload data'); return }
  router.push({ name: 'rekam-medis', query: { patient: p.patientId } })
}

// Singkatan bulan Indonesia untuk label tgl lahir (mis. "20-Okt-2008").
const ID_MON = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des']

// Parse tgl lahir yang bisa datang sbg ISO (YYYY-MM-DD…) atau DD/MM/YYYY → Date|null.
function parseDob(v) {
  if (!v || v === '—') return null
  const s = String(v)
  const dmy = s.match(/^(\d{2})\/(\d{2})\/(\d{4})$/)
  const d = dmy
    ? new Date(Number(dmy[3]), Number(dmy[2]) - 1, Number(dmy[1]))
    : new Date(s)
  return isNaN(d.getTime()) ? null : d
}

// "20-Okt-2008"
function fmtDobLabel(d) {
  if (!d) return '-'
  return `${String(d.getDate()).padStart(2,'0')}-${ID_MON[d.getMonth()]}-${d.getFullYear()}`
}

// "17 tahun 7 bulan" (umur presisi tahun+bulan; 0 bulan → "17 tahun"; <1 th → "7 bulan")
function fmtUmur(d) {
  if (!d) return '-'
  const now = new Date()
  let y = now.getFullYear() - d.getFullYear()
  let m = now.getMonth() - d.getMonth()
  if (now.getDate() < d.getDate()) m--
  if (m < 0) { y--; m += 12 }
  if (y < 0) return '-'
  if (y === 0) return `${m} bulan`
  return m > 0 ? `${y} tahun ${m} bulan` : `${y} tahun`
}

function printLabel(p) {
  const t = p
  if (!t) return
  const dob = parseDob(t.birthDate)
  // TL/Umur: "20-Okt-2008/17 tahun 7 bulan"; fallback ke umur tahun bila tgl tak ada.
  const tlUmur = dob
    ? `${fmtDobLabel(dob)}/${fmtUmur(dob)}`
    : (t.age ? `${t.age} tahun` : '-')
  const jk = t.sex === 'L' ? 'Laki-Laki' : (t.sex === 'P' ? 'Perempuan' : '-')
  const nik = (t.nik && t.nik !== '—') ? t.nik : '-'
  const labelHtml = `
    <html><head><title>Label ${escHtml(t.name)}</title>
    <style>
      @page { size: 58mm 40mm; margin: 0; }
      * { margin:0; padding:0; box-sizing:border-box; font-family:Arial,Helvetica,sans-serif; color:#000 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
      html, body { background:#fff; }
      body { width:58mm; padding:2mm 2.5mm; color:#000; -webkit-text-stroke:0.15px #000; }
      .nm { font-size:13px; font-weight:bold; line-height:1.12; text-transform:uppercase; }
      .jk { font-size:10px; font-weight:bold; margin-top:1px; }
      .row { font-size:10px; font-weight:bold; margin-top:1px; display:flex; align-items:flex-start; }
      .row .k { flex:0 0 14mm; font-weight:bold; }
      .row .v { font-weight:bold; }
      .bc { margin-top:3px; font-size:20px; font-weight:bold; letter-spacing:2px; text-align:center; font-family:'Courier New',monospace; }
    </style></head><body>
      <div class="nm">${escHtml(t.name)}</div>
      <div class="jk">${jk} - ${escHtml(nik)}</div>
      <div class="row"><span class="k">TL/Umur</span><span class="v">: ${escHtml(tlUmur)}</span></div>
      <div class="row"><span class="k">No. RM</span><span class="v">: ${escHtml(t.noRm)}</span></div>
      <div class="bc">*${escHtml(t.noRm)}*</div>
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
  // Sub-modal yang bisa terbuka DI ATAS wizard harus ditutup lebih dulu — kalau
  // tidak, ESC akan langsung menutup seluruh wizard & membuang data yang diketik.
  if (sigCaptureOpen.value)   { sigCaptureOpen.value = false;   return }
  if (photoModalOpen.value)   { photoModalOpen.value = false;   return }
  if (consentModalOpen.value) { consentModalOpen.value = false; return }
  if (profileOpen.value)     { closeProfile();                return }
  if (penjaminOpen.value)    { closeEditPenjamin();           return }
  if (dokterOpen.value)      { closeEditDokter();             return }
  if (editOpen.value)        { closeEditPasien();             return }
  if (visitDetailOpen.value) { visitDetailOpen.value = false; return }
  if (wizardOpen.value)      { closeWizard();                 return }
}

/* ============================================================
   LIFECYCLE — parallel fetch on mount
   ============================================================ */
onMounted(async () => {
  updateDate()
  dateTimer = setInterval(updateDate, 1000)
  window.addEventListener('keydown', onKeydown)

  await Promise.allSettled([
    admisiStore.fetchDashboard(),
    admisiStore.fetchAntrian(),
    admisiStore.fetchVisits(),   // tanggal lokal diisi fallback store (localDateStr)
    jadwalStore.fetchAktifHariIni(),
    admisiStore.fetchInsurers(),
  ])

  admisiStore.connectWs()
})

onUnmounted(() => {
  clearInterval(dateTimer)
  clearTimeout(_searchTimer)
  clearTimeout(_lookupTimer)
  clearTimeout(_visitSearchTimer)
  window.removeEventListener('keydown', onKeydown)
  admisiStore.disconnectWs()
  revokeIdentityUrls()
})
</script>

<template>
  <div class="admisi">
    <!-- Tombol "Pesan Barang ke Gudang" kecil di topbar (samping Realtime aktif). Admisi = BHP saja. -->
    <Teleport to="#topbar-action-slot">
      <UnitStockActions station="ADMISI" label="Pesan Barang" variant="soft" :item-types="['BHP']" />
    </Teleport>

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
      <div class="toolbar-actions">
        <!-- Searchbar: lihat data pasien yang sudah pernah terdaftar -->
        <div class="lookup" :class="{ 'walkin-mode': pendingWalkIn }">
          <svg class="lookup-icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input
            ref="lookupInput"
            v-model="lookupKey"
            class="lookup-input"
            placeholder="Cari data pasien (nama / NIK / No. RM / Tgl lahir DD MM YYYY atau DDMMYYYY)"
            @input="onLookupInput"
            @keyup.enter="runLookup"
            @focus="lookupKey.trim() && (lookupDropOpen = true)"
            @blur="onLookupBlur"
          />
          <span v-if="lookupLoading" class="spin-xs lookup-spin"></span>
          <transition name="modal-fade">
            <div v-if="lookupDropOpen && lookupKey.trim()" class="combo-dropdown lookup-drop">
              <div v-if="lookupLoading && !lookupResults.length" class="combo-empty">
                <span class="spin-xs"></span> Mencari pasien…
              </div>
              <div
                v-for="pt in lookupResults"
                :key="pt.id"
                class="combo-item preview"
                @mousedown="openProfile(pt)"
              >
                <PatientAvatar :name="pt.name" :src="pt.photo_url" :size="32" :zoomable="false" radius="50%" />
                <div class="combo-info">
                  <div class="combo-name">
                    {{ pt.name }}
                    <span v-if="pt.satusehat_ihs" class="ihs-chip ihs-ok" title="Sudah punya IHS Satu Sehat">IHS ✓</span>
                    <span v-else class="ihs-chip ihs-none" title="Belum punya IHS — akan resolve saat sync">Belum IHS</span>
                  </div>
                  <div class="combo-meta">
                    <span class="combo-rm">RM {{ pt.no_rm }}</span>
                    <span v-if="pt.nik">· NIK {{ pt.nik }}</span>
                    <span v-if="pt.date_of_birth" class="combo-dob">· 🎂 {{ fmtDate(pt.date_of_birth) }} ({{ calcAge(pt.date_of_birth) }} th)</span>
                  </div>
                  <div v-if="pt.address" class="combo-addr">
                    <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    {{ pt.address }}
                  </div>
                </div>
              </div>
              <div v-if="!lookupLoading && !lookupResults.length" class="combo-empty">
                <div>Tidak ada pasien yang cocok</div>
                <button
                  v-if="pendingWalkIn"
                  class="btn btn-sm btn-primary"
                  style="margin-top: 8px"
                  @mousedown.prevent="daftarBaruWalkIn"
                >
                  + Daftar pasien baru
                </button>
              </div>
            </div>
          </transition>

          <!-- Banner alur walk-in: cari & lihat detail dulu, atau daftar baru -->
          <div v-if="pendingWalkIn" class="walkin-lookup-hint">
            <span>
              Mendaftarkan walk-in <strong>{{ pendingWalkIn.queueNo }}</strong> — pilih pasien untuk lihat detail, atau daftar baru bila tak ada.
            </span>
            <span class="wlh-actions">
              <button class="wlh-link wlh-primary" @click="daftarBaruWalkIn">Daftar Pasien Baru</button>
              <button class="wlh-link" @click="cancelWalkInLookup">Batal</button>
            </span>
          </div>
        </div>

        <button class="btn btn-primary btn-lg" @click="openWizard">
          <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Daftarkan Pasien
        </button>
      </div>
    </div>

    <!-- ===================== DASHBOARD STATS ===================== -->
    <div v-if="admisiStore.dashboardError" class="dashboard-err">
      <svg viewBox="0 0 24 24" width="16" height="16"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="13"/><line x1="12" y1="16" x2="12" y2="16.01"/></svg>
      <span>Statistik gagal dimuat: {{ admisiStore.dashboardError }} — angka di kartu mungkin tidak akurat.</span>
      <button class="btn btn-sm btn-secondary" :disabled="admisiStore.dashboardLoading" @click="admisiStore.fetchDashboard()">
        {{ admisiStore.dashboardLoading ? 'Memuat…' : 'Coba lagi' }}
      </button>
    </div>
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
        <div class="stat-icon" style="background: #d1fae5">
          <svg style="stroke: #047857" viewBox="0 0 24 24"><path d="M20 7h-4V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
        </div>
        <div>
          <div class="stat-val" style="color: #047857">{{ vpUmum }}</div>
          <div class="stat-lbl">Umum</div>
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
        <div class="stat-icon" style="background: #e0f2fe">
          <svg style="stroke: #0369a1" viewBox="0 0 24 24"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/></svg>
        </div>
        <div>
          <div class="stat-val" style="color: #0369a1">{{ vpRanap }}</div>
          <div class="stat-lbl">Rawat Inap</div>
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
        <!-- CALLABLE QUEUE — default tersembunyi; muncul dinamis saat ada
             walk-in baru ambil tiket di Anjungan Mandiri (callableQueue terisi). -->
        <transition name="callcard">
        <div v-if="callableQueue.length" class="card call-card">
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
              <span v-if="!p.walkIn && p.noSep" class="sep-badge" :title="`SEP BPJS aktif: ${p.noSep}`">SEP</span>
              <span
                v-if="['ASURANSI','PERUSAHAAN'].includes(p.guarantor) && p.insuranceVerificationStatus && p.insuranceVerificationStatus !== 'NONE'"
                :class="['verif-badge', `vb-${p.insuranceVerificationStatus.toLowerCase()}`]"
                :title="`Status verifikasi asuransi: ${p.insuranceVerificationStatus}`">
                <span v-if="p.insuranceVerificationStatus === 'VERIFIED'">✓</span>
                <span v-else-if="p.insuranceVerificationStatus === 'ISSUE'">✗</span>
                <span v-else>⚠</span>
                {{ p.insuranceVerificationStatus === 'VERIFIED' ? 'Verified' : p.insuranceVerificationStatus === 'ISSUE' ? 'Issue' : 'Pending' }}
              </span>
              <div class="call-station">
                <div class="call-station-lbl">Tujuan</div>
                <div class="call-station-val">{{ p.station }}</div>
              </div>
              <div class="call-actions">
                <button v-if="!p.walkIn" class="btn btn-secondary btn-icon" title="Detail kunjungan" aria-label="Detail kunjungan" @click="openVisitDetail(p)">
                  <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
                </button>
                <button v-if="!p.walkIn" class="btn btn-secondary btn-icon" title="Edit data pasien" aria-label="Edit data pasien" @click="openEditPasien(p)">
                  <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 113 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                </button>
                <button v-if="!p.walkIn" class="btn btn-secondary btn-icon" title="Cetak label pasien" aria-label="Cetak label pasien" @click="printLabel(p)">
                  <svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="6" rx="1"/><path d="M6 8h12v6a2 2 0 01-2 2H8a2 2 0 01-2-2V8z"/><path d="M8 16v4h8v-4"/></svg>
                </button>
                <button v-if="!p.walkIn && p.guarantor === 'BPJS'" class="btn btn-secondary btn-icon" title="Riwayat i-Care JKN (1 tahun, lintas faskes) — perlu persetujuan pasien" aria-label="Riwayat i-Care JKN" @click="openIcare(p)">
                  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
                </button>
                <button
                  v-if="p.walkIn"
                  class="btn btn-secondary btn-danger"
                  :disabled="noShowingId === p.id"
                  title="Pasien tidak hadir saat dipanggil — keluarkan tiket dari antrean"
                  @click="markWalkInNoShow(p)"
                >
                  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                  {{ noShowingId === p.id ? 'Memproses…' : 'Tidak Hadir' }}
                </button>
                <button
                  v-if="p.walkIn"
                  class="btn btn-secondary"
                  :disabled="p.status !== 'CALLED'"
                  :title="p.status === 'CALLED' ? 'Cari data pasien & daftarkan walk-in' : 'Panggil pasien terlebih dahulu'"
                  @click="daftarkanWalkIn(p)"
                >
                  <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  Cari Data Pasien
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

          </div>
        </div>
        </transition>

        <!-- DETAIL TABLE — collapsible -->
        <div class="card">
          <div class="card-head clickable" @click="tableExpanded = !tableExpanded">
            <div>
              <div class="card-head-title">
                <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/></svg>
                {{ visitTableTitle }}
              </div>
              <div class="card-head-sub">{{ admisiStore.visitsMeta.total }} kunjungan · klik untuk {{ tableExpanded ? 'sembunyikan' : 'tampilkan' }}</div>
            </div>
            <button class="collapse-btn" :class="{ open: tableExpanded }" @click.stop="tableExpanded = !tableExpanded">
              <span>{{ tableExpanded ? 'Sembunyikan' : 'Tampilkan' }}</span>
              <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
          </div>

          <transition name="collapse">
            <div v-show="tableExpanded">
              <div class="table-toolbar">
                <!-- Pisah Rawat Jalan vs Rawat Inap (RANAP long-lived) -->
                <div class="care-toggle" role="tablist" aria-label="Jenis perawatan">
                  <button
                    type="button"
                    role="tab"
                    :class="['care-seg', careType === 'RAJAL' ? 'on' : '']"
                    :aria-selected="careType === 'RAJAL'"
                    @click="setCareType('RAJAL')"
                  >
                    Rawat Jalan
                  </button>
                  <button
                    type="button"
                    role="tab"
                    :class="['care-seg', careType === 'RANAP' ? 'on' : '']"
                    :aria-selected="careType === 'RANAP'"
                    @click="setCareType('RANAP')"
                  >
                    Rawat Inap
                  </button>
                  <button
                    type="button"
                    role="tab"
                    :class="['care-seg', careType === 'AKTIF' ? 'on' : '']"
                    :aria-selected="careType === 'AKTIF'"
                    title="Kunjungan belum selesai (lintas-hari) — ekor visit yang nyangkut"
                    @click="setCareType('AKTIF')"
                  >
                    Masih Aktif
                  </button>
                </div>
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
                      <td class="td-rownum">{{ rowOffset + i + 1 }}</td>
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
                        <span v-if="p.noSep" class="sep-badge" :title="`SEP BPJS aktif: ${p.noSep}`">SEP</span>
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
                          <button class="btn btn-sm btn-secondary btn-icon" title="Detail kunjungan" aria-label="Detail kunjungan" @click="openVisitDetail(p)">
                            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
                          </button>
                          <button class="btn btn-sm btn-secondary btn-icon" title="Cetak label pasien" aria-label="Cetak label pasien" @click="printLabel(p)">
                            <svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="6" rx="1"/><path d="M6 8h12v6a2 2 0 01-2 2H8a2 2 0 01-2-2V8z"/><path d="M8 16v4h8v-4"/></svg>
                          </button>
                          <button v-if="!p.walkIn && p.guarantor === 'BPJS'" class="btn btn-sm btn-secondary btn-icon" title="Riwayat i-Care JKN (1 tahun, lintas faskes) — perlu persetujuan pasien" aria-label="Riwayat i-Care JKN" @click="openIcare(p)">
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
                          </button>
                          <button
                            v-if="!p.walkIn"
                            class="btn btn-sm btn-secondary btn-icon"
                            :disabled="p.station === 'SELESAI'"
                            title="Ubah penjamin / pola bayar"
                            aria-label="Ubah penjamin"
                            @click="openEditPenjamin(p)"
                          >
                            <svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                          </button>
                          <button
                            v-if="!p.walkIn"
                            class="btn btn-sm btn-secondary btn-icon"
                            :disabled="p.station === 'SELESAI'"
                            title="Ubah dokter pemeriksa (sebelum dipanggil dokter)"
                            aria-label="Ubah dokter"
                            @click="openEditDokter(p)"
                          >
                            <svg viewBox="0 0 24 24"><path d="M16 4a3 3 0 11-3 3"/><path d="M5 21v-2a4 4 0 014-4h2"/><circle cx="9" cy="7" r="3"/><polyline points="17 13 20 16 17 19"/><path d="M20 16h-6"/></svg>
                          </button>
                          <button
                            class="btn btn-sm btn-icon btn-danger"
                            :disabled="!canCancelRow(p)"
                            :title="cancelTitle(p)"
                            aria-label="Batalkan kunjungan"
                            @click="confirmCancelKunjungan(p)"
                          >
                            <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                          </button>
                          <button
                            class="btn btn-sm btn-primary call-btn"
                            :disabled="!(p.station === 'ADMISI' && p.callQueueId)"
                            :title="p.station !== 'ADMISI'
                              ? 'Pasien sudah pindah stasiun — panggil di stasiun terkait'
                              : (p.callStatus === 'CALLED' ? 'Panggil ulang pasien' : 'Panggil pasien')"
                            @click="callPatient(p)"
                          >
                            <svg viewBox="0 0 24 24"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/></svg>
                            {{ p.callStatus === 'CALLED' ? 'Panggil Ulang' : 'Panggil' }}
                          </button>
                        </div>
                      </td>
                    </tr>
                    <tr v-if="filteredQueue.length === 0">
                      <td colspan="11" class="empty-row">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                        <span>{{ admisiStore.visitsLoading ? 'Memuat data…' : 'Tidak ada data' }}</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <!-- PAGER — server-side pagination -->
              <div v-if="admisiStore.visitsMeta.total > 0" class="table-pager">
                <div class="pager-info">
                  Menampilkan {{ rowOffset + 1 }}–{{ rowOffset + filteredQueue.length }}
                  dari {{ admisiStore.visitsMeta.total }} kunjungan
                </div>
                <div class="pager-ctrl">
                  <button
                    class="btn btn-sm btn-secondary"
                    :disabled="admisiStore.visitsMeta.current_page <= 1 || admisiStore.visitsLoading"
                    @click="goPage(admisiStore.visitsMeta.current_page - 1)"
                  >
                    <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                    Sebelumnya
                  </button>
                  <span class="pager-page">Hal {{ admisiStore.visitsMeta.current_page }} / {{ admisiStore.visitsMeta.last_page }}</span>
                  <button
                    class="btn btn-sm btn-secondary"
                    :disabled="admisiStore.visitsMeta.current_page >= admisiStore.visitsMeta.last_page || admisiStore.visitsLoading"
                    @click="goPage(admisiStore.visitsMeta.current_page + 1)"
                  >
                    Berikutnya
                    <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                  </button>
                </div>
              </div>
            </div>
          </transition>
        </div>
      </div>

      <!-- ---------- KANAN: PANEL ---------- -->
       
      <div class="right-col">
        <div class="card">
          <div class="card-head clickable" @click="toggleRight('aksi')">
            <div class="card-head-title">
              <svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
              Aksi Cepat
            </div>
            <svg class="head-caret" :class="{ open: !rightCollapse.aksi }" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <transition name="collapse">
          <div v-show="!rightCollapse.aksi" class="card-body actions-stack">
            <!-- ===== Cek No. Rujukan ke VClaim (search bar default hidden) ===== -->
            <button
              type="button"
              :class="['btn', 'btn-full', 'qa-trigger', quickPanel === 'rujukan' ? 'qa-trigger-on' : 'btn-secondary']"
              @click="toggleQuickPanel('rujukan')"
            >
              <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
              Cek No. Rujukan
              <svg class="qa-caret" :class="{ open: quickPanel === 'rujukan' }" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
            </button>

            <div v-if="quickPanel === 'rujukan'" class="qa-rujukan">
              <div class="seg-toggle full mini">
                <button type="button" :class="['seg', rujukanQuick.sumber === 'fktp' ? 'seg-on' : '']" @click="rujukanQuick.sumber = 'fktp'">FKTP (Faskes 1)</button>
                <button type="button" :class="['seg', rujukanQuick.sumber === 'rs' ? 'seg-on' : '']" @click="rujukanQuick.sumber = 'rs'">Antar-RS</button>
              </div>
              <div class="inline-check">
                <input
                  v-model="rujukanQuick.no"
                  class="form-input"
                  placeholder="Nomor rujukan…"
                  @keyup.enter="cekRujukanQuick"
                />
                <button type="button" class="btn-check" :disabled="rujukanQuick.loading" @click="cekRujukanQuick">
                  {{ rujukanQuick.loading ? 'Mengecek…' : 'Cek' }}
                </button>
              </div>

              <div v-if="rujukanQuick.error" class="check-msg err">{{ rujukanQuick.error }}</div>
              <div v-else-if="rujukanQuick.data" class="qa-rujukan-res">
                <div class="qa-res-row"><span class="qa-res-k">Peserta</span><span class="qa-res-v">{{ rujukanQuick.data.peserta?.nama ?? '—' }}<template v-if="rujukanQuick.data.peserta?.noKartu"> · {{ rujukanQuick.data.peserta.noKartu }}</template></span></div>
                <div class="qa-res-row"><span class="qa-res-k">Diagnosa</span><span class="qa-res-v">{{ rujukanQuick.data.diagnosa?.nama ?? '—' }}</span></div>
                <div class="qa-res-row"><span class="qa-res-k">Poli Tujuan</span><span class="qa-res-v">{{ rujukanQuick.data.poliRujukan?.nama || rujukanQuick.data.poliRujukan?.kode || '—' }}</span></div>
                <div class="qa-res-row"><span class="qa-res-k">Faskes Perujuk</span><span class="qa-res-v">{{ rujukanQuick.data.provPerujuk?.nama ?? '—' }}</span></div>
                <div class="qa-res-row"><span class="qa-res-k">Tgl Rujukan</span><span class="qa-res-v">{{ rujukanQuick.data.tglKunjungan ?? '—' }}</span></div>
                <button type="button" class="btn btn-primary btn-full qa-use-btn" @click="pakaiRujukanQuick">
                  <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  Daftarkan dengan Rujukan Ini
                </button>
              </div>
            </div>

            <div class="divider"></div>

            <!-- ===== Cek Status BPJS = Cek Peserta VClaim (search bar default hidden) ===== -->
            <button
              type="button"
              :class="['btn', 'btn-full', 'qa-trigger', quickPanel === 'peserta' ? 'qa-trigger-on' : 'btn-secondary']"
              @click="toggleQuickPanel('peserta')"
            >
              <svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              Cek Peserta
              <svg class="qa-caret" :class="{ open: quickPanel === 'peserta' }" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
            </button>

            <div v-if="quickPanel === 'peserta'" class="qa-rujukan">
              <div class="seg-toggle full mini">
                <button type="button" :class="['seg', pesertaQuick.type === 'nik' ? 'seg-on' : '']" @click="pesertaQuick.type = 'nik'">NIK</button>
                <button type="button" :class="['seg', pesertaQuick.type === 'nokartu' ? 'seg-on' : '']" @click="pesertaQuick.type = 'nokartu'">No. Kartu</button>
              </div>
              <div class="inline-check">
                <input
                  v-model="pesertaQuick.id"
                  class="form-input"
                  :placeholder="pesertaQuick.type === 'nik' ? 'Nomor NIK…' : 'Nomor Kartu BPJS…'"
                  @keyup.enter="cekPesertaQuick"
                />
                <button type="button" class="btn-check" :disabled="pesertaQuick.loading" @click="cekPesertaQuick">
                  {{ pesertaQuick.loading ? 'Mengecek…' : 'Cek' }}
                </button>
              </div>

              <div v-if="pesertaQuick.error" class="check-msg err">{{ pesertaQuick.error }}</div>
              <div v-else-if="pesertaQuick.data" class="qa-rujukan-res">
                <div class="qa-res-row"><span class="qa-res-k">Nama</span><span class="qa-res-v">{{ pesertaQuick.data.nama ?? '—' }}</span></div>
                <div class="qa-res-row"><span class="qa-res-k">No. Kartu</span><span class="qa-res-v">{{ pesertaQuick.data.noKartu ?? '—' }}</span></div>
                <div class="qa-res-row"><span class="qa-res-k">Hak Kelas</span><span class="qa-res-v">{{ pesertaQuick.data.hakKelas?.keterangan ?? '—' }}</span></div>
                <div v-if="pesertaQuick.data.provUmum?.nmProvider" class="qa-res-row"><span class="qa-res-k">Faskes Asal</span><span class="qa-res-v">{{ pesertaQuick.data.provUmum.nmProvider }}</span></div>
                <div v-if="pesertaQuick.data.provUKP?.nmProvider" class="qa-res-row"><span class="qa-res-k">Faskes Gigi</span><span class="qa-res-v">{{ pesertaQuick.data.provUKP.nmProvider }}</span></div>
                <div class="qa-res-row">
                  <span class="qa-res-k">Status</span>
                  <span class="qa-res-v" :class="pesertaQuick.data.statusPeserta?.kode === '0' ? 'st-ok' : 'st-warn'">
                    {{ pesertaQuick.data.statusPeserta?.keterangan ?? '—' }}
                  </span>
                </div>
                <div v-if="pesertaQuick.data.cob?.nmAsuransi" class="qa-res-row"><span class="qa-res-k">COB</span><span class="qa-res-v">{{ pesertaQuick.data.cob.nmAsuransi }}</span></div>
                <div v-if="pesertaQuick.data.jenisPeserta?.keterangan" class="qa-res-row"><span class="qa-res-k">Jenis Peserta</span><span class="qa-res-v">{{ pesertaQuick.data.jenisPeserta.keterangan }}</span></div>
              </div>
            </div>
          </div>
          </transition>
        </div>

        <div class="card">
          <div class="card-head clickable" @click="toggleRight('status')">
            <div class="card-head-title">
              <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
              Status BPJS &amp; Integrasi
            </div>
            <svg class="head-caret" :class="{ open: !rightCollapse.status }" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <transition name="collapse">
          <div v-show="!rightCollapse.status" class="card-body stack-gap">
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
          </transition>
        </div>

        <div class="card">
          <div class="card-head clickable" @click="toggleRight('antrean')">
            <div class="card-head-title">
              <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
              Antrean Real-time
            </div>
            <span class="head-right">
              <span class="pill-live"><span class="live-dot"></span>LIVE</span>
              <svg class="head-caret" :class="{ open: !rightCollapse.antrean }" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
            </span>
          </div>
          <transition name="collapse">
          <div v-show="!rightCollapse.antrean" class="card-body live-list">
            <transition-group name="liverow">
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
            </transition-group>
            <div v-if="!mappedAntrian.length" class="live-empty">
              <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
              Belum ada antrean aktif
            </div>
          </div>
          </transition>
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
                <template v-else>Rumah Sakit Mata · FKRTL</template>
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
                      ref="wizSearchInput"
                      v-model="form.searchKey"
                      class="form-input"
                      placeholder="Nama, NIK, No. RM, atau Tgl lahir (DD MM YYYY / DDMMYYYY)"
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
                          <PatientAvatar :name="pt.name" :src="pt.photo_url" :size="32" :zoomable="false" radius="50%" />
                          <div class="combo-info">
                            <div class="combo-name">
                              {{ pt.name }}
                              <span v-if="pt.satusehat_ihs" class="ihs-chip ihs-ok" title="Sudah punya IHS Satu Sehat">IHS ✓</span>
                              <span v-else class="ihs-chip ihs-none" title="Belum punya IHS — akan resolve saat sync">Belum IHS</span>
                              <span v-if="pt.active_visit" class="combo-av-badge" title="Pasien masih punya kunjungan aktif">● kunjungan aktif</span>
                            </div>
                            <div class="combo-meta">
                              <span class="combo-rm">RM {{ pt.no_rm }}</span>
                              <span class="combo-nik" v-if="pt.nik">· NIK {{ pt.nik }}</span>
                              <span class="combo-dob" v-if="pt.date_of_birth">· 🎂 {{ fmtDate(pt.date_of_birth) }} ({{ calcAge(pt.date_of_birth) }} th)</span>
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
                  <div class="hint">Ketik 1 huruf saja — pencarian otomatis berdasarkan nama, NIK, No. RM, atau tanggal lahir (DD/MM/YYYY, DD MM YYYY, atau DDMMYYYY)</div>
                </div>

                <div v-if="form.found && !selectedActiveVisit" class="found-banner full">
                  <svg viewBox="0 0 24 24"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                  Data ditemukan — periksa kembali sebelum lanjut
                </div>

                <!-- Peringatan: pasien masih punya kunjungan aktif → registrasi baru akan ditolak -->
                <div v-if="form.found && selectedActiveVisit" class="active-visit-banner full">
                  <svg viewBox="0 0 24 24" width="20" height="20"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                  <div class="avb-body">
                    <strong>Pasien masih punya kunjungan aktif</strong>
                    <div class="avb-meta">
                      <span v-if="selectedActiveVisit.no_registrasi">No. {{ selectedActiveVisit.no_registrasi }} · </span>
                      Stasiun <b>{{ selectedActiveVisit.current_station }}</b>
                      <span v-if="selectedActiveVisit.visit_date"> · tgl {{ formatAvDate(selectedActiveVisit.visit_date) }}</span>
                    </div>
                    <div class="avb-note">Registrasi baru diblokir. Selesaikan alur kunjungan itu atau batalkan dulu di Daftar Kunjungan.</div>
                    <button type="button" class="avb-action" @click="lihatKunjunganAktif">Lihat Kunjungan Aktif →</button>
                  </div>
                </div>

                <!-- Banner Preop Bedah (auto-suggest) -->
                <div
                  v-if="form.found && preopBannerType"
                  :class="['preop-banner', 'full', preopBannerType === 'today' ? 'preop-today' : 'preop-upcoming']"
                >
                  <div class="preop-banner-head">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M19 14H5m14-4H5m14 8H5"/>
                      <circle cx="12" cy="6" r="2"/>
                    </svg>
                    <strong v-if="preopBannerType === 'today'">Pasien punya jadwal bedah hari ini</strong>
                    <strong v-else>Pasien punya jadwal bedah {{ formatPreopDate(upcomingPreopSchedule?.scheduled_date) }}</strong>
                  </div>

                  <div class="preop-banner-body">
                    <div v-if="preopBannerType === 'today' && todayPreopSchedule">
                      <span class="preop-detail">
                        <strong>Jam:</strong> {{ todayPreopSchedule.scheduled_time ?? '—' }}
                        · <strong>Ruang:</strong> {{ todayPreopSchedule.operation_room ?? '—' }}
                        <template v-if="todayPreopSchedule.surgery_package">
                          · <strong>Paket:</strong> {{ todayPreopSchedule.surgery_package.name }}
                        </template>
                      </span>
                      <div class="preop-question">Daftarkan sebagai <strong>Preop Bedah</strong>?</div>
                    </div>
                    <div v-else-if="upcomingPreopSchedule">
                      <span class="preop-detail">
                        <strong>Jam:</strong> {{ upcomingPreopSchedule.scheduled_time ?? '—' }}
                        · <strong>Ruang:</strong> {{ upcomingPreopSchedule.operation_room ?? '—' }}
                        <template v-if="upcomingPreopSchedule.surgery_package">
                          · <strong>Paket:</strong> {{ upcomingPreopSchedule.surgery_package.name }}
                        </template>
                        <strong v-if="upcomingPreopSchedule.requires_inpatient"> · Rawat Inap</strong>
                      </span>
                      <!-- Fase 8B: pre-op rawat inap → pasien datang H-1, jadwal operasi TIDAK digeser -->
                      <div v-if="upcomingPreopSchedule.requires_inpatient" class="preop-question">
                        Daftarkan <strong>Pre-op Rawat Inap</strong>? <em>Pasien diopname sekarang (H-1); operasi tetap {{ formatPreopDate(upcomingPreopSchedule.scheduled_date) }}.</em>
                      </div>
                      <div v-else class="preop-question">Daftarkan preop hari ini? <em>Jadwal akan dipindah ke hari ini.</em></div>
                    </div>
                  </div>

                  <div class="preop-banner-actions">
                    <button
                      type="button"
                      :class="['btn', 'btn-sm', preopChoice === 'PREOP' ? 'btn-primary' : 'btn-secondary']"
                      @click="choosePreop(preopBannerType === 'today' ? todayPreopSchedule : upcomingPreopSchedule)"
                    >
                      {{ preopBannerType === 'today'
                          ? 'Ya, Preop Bedah'
                          : (upcomingPreopSchedule?.requires_inpatient ? 'Ya, Pre-op Rawat Inap (H-1)' : 'Ya, Preop Hari Ini (geser jadwal)') }}
                    </button>
                    <button
                      type="button"
                      :class="['btn', 'btn-sm', preopChoice === 'REGULAR' ? 'btn-primary' : 'btn-secondary']"
                      @click="chooseRegular"
                    >
                      Tidak, Kunjungan Reguler
                    </button>
                  </div>
                </div>
              </template>

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
                <!-- Hak konsultasi kontrol gratis pasca-bedah (info; tebusan otomatis di Kasir utk UMUM) -->
                <div v-if="followupEntitlements.length" class="followup-badge">
                  🎁 Pasien punya
                  <strong>{{ followupEntitlements.reduce((s, e) => s + (e.remaining || 0), 0) }}× kontrol gratis</strong>
                  <span v-for="e in followupEntitlements" :key="e.id" class="followup-chip">
                    {{ e.procedure_name }}<template v-if="e.package_name"> · {{ e.package_name }}</template><template v-if="e.valid_until"> · s/d {{ e.valid_until }}</template>
                  </span>
                  <div class="hint">Diskon otomatis muncul di Kasir saat menagih konsultasi (penjamin Umum).</div>
                </div>
              </div>

              <div class="section-label full">Identitas</div>
              <div class="field full">
                <label class="field-lbl">Nama Lengkap</label>
                <input v-model="form.name" class="form-input" :readonly="form.patientMode === 'existing'" placeholder="Nama sesuai identitas" />
              </div>
              <div class="field">
                <label class="field-lbl">Jenis Identitas</label>
                <select v-model="form.identityType" class="form-select" :disabled="form.patientMode === 'existing'" @change="onIdentityTypeChange">
                  <option v-for="t in identityTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
              </div>
              <div class="field">
                <label class="field-lbl">
                  {{ activeIdentity.numberLabel }}
                  <span v-if="form.identityType === 'KTP'" style="color:#ef4444">*</span>
                </label>
                <input
                  v-model="form.nik"
                  class="form-input"
                  :readonly="form.patientMode === 'existing' || noIdentity"
                  :placeholder="activeIdentity.ph"
                  :maxlength="form.identityType === 'KTP' ? 16 : 50"
                />
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
                <input
                  :value="birthDateText"
                  type="text"
                  inputmode="numeric"
                  maxlength="10"
                  class="form-input"
                  placeholder="DD/MM/YYYY"
                  :readonly="form.patientMode === 'existing'"
                  @input="onBirthTextInput"
                />
                <div v-if="form.patientMode !== 'existing' && birthDateText.length === 10 && !form.birthDate" class="hint" style="color:#dc2626">Tanggal tidak valid</div>
              </div>
              <div class="field">
                <label class="field-lbl">Usia</label>
                <input :value="form.age ? form.age + ' tahun' : ''" class="form-input" readonly placeholder="Otomatis" />
              </div>
              <div class="field full">
                <label class="field-lbl">No. Telepon Pasien</label>
                <input v-model="form.phone" class="form-input" :readonly="form.patientMode === 'existing'" placeholder="08xx-xxxx-xxxx" />
              </div>
              <div class="field full">
                <label class="field-lbl">No. Telepon Keluarga <span class="field-opt">(kontak darurat / wali — opsional)</span></label>
                <input v-model="form.familyPhone" class="form-input" :readonly="form.patientMode === 'existing'" placeholder="08xx-xxxx-xxxx" />
              </div>
              <div class="field full">
                <label class="field-lbl">Email Pasien <span class="field-opt">(opsional — untuk kirim kwitansi)</span></label>
                <input v-model="form.email" type="email" class="form-input" :readonly="form.patientMode === 'existing'" placeholder="nama@email.com" />
              </div>

              <div v-if="form.patientMode === 'new'" class="field full">
                <label class="field-lbl">Foto Pasien</label>
                <div class="photo-field">
                  <PatientAvatar :name="form.name" :src="form.photo" :size="64" :zoomable="!!form.photo" />
                  <div class="photo-actions">
                    <button type="button" class="btn btn-secondary btn-sm" @click="photoModalOpen = true">
                      <svg viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                      {{ form.photo ? 'Ganti Foto' : 'Ambil Foto' }}
                    </button>
                    <button v-if="form.photo" type="button" class="btn btn-secondary btn-sm" @click="form.photo = null">Hapus</button>
                    <span class="photo-hint">Dari kamera/HP atau unggah file · opsional</span>
                  </div>
                </div>
              </div>

              <div class="section-label full">Alamat <span class="lbl-note">(sesuai data identitas)</span></div>
              <div class="field full">
                <!-- Pasien lama dgn wilayah tak cocok master → tampilkan data lama,
                     boleh dibiarkan atau dipilih ulang. Kalau cocok → picker terkunci. -->
                <div v-if="wizWilayahNeedsRepick" class="wilayah-keep-note">
                  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                  <span>
                    Data wilayah saat ini:
                    <strong>{{ [form.origProvince, form.origRegency, form.origDistrict].filter(Boolean).join(' · ') }}</strong>
                    — tidak cocok dengan master. Biarkan tetap tersimpan, atau pilih ulang dari dropdown di bawah.
                  </span>
                </div>
                <WilayahPicker
                  v-model:province="form.province"
                  v-model:regency="form.regency"
                  v-model:district="form.district"
                  :disabled="wizWilayahLocked"
                  @prefill-status="onWizWilayahPrefill"
                  @update:province="onWizWilayahTouched"
                  @update:regency="onWizWilayahTouched"
                  @update:district="onWizWilayahTouched"
                />
              </div>
              <div class="field full">
                <label class="field-lbl">Detail Alamat (Jalan, No., RT/RW)</label>
                <input v-model="form.addressDetail" class="form-input" :readonly="form.patientMode === 'existing'" placeholder="Jl. ... No. ..., RT 000/RW 000" />
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
                <!-- Tarik rujukan & surat kontrol dari BPJS (pasien kontrol tanpa nomor) -->
                <div class="bpjs-pull full">
                  <div class="bpjs-pull-head">
                    <span class="bpjs-pull-title">
                      <svg viewBox="0 0 24 24"><path d="M21 12a9 9 0 11-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
                      Pasien tidak bawa nomor rujukan / surat kontrol?
                    </span>
                    <button type="button" class="btn-check" :disabled="bpjsPull.loading" @click="tarikDataBpjs">
                      <span v-if="bpjsPull.loading" class="spin-xs"></span>
                      {{ bpjsPull.loading ? 'Menarik…' : 'Tarik dari BPJS' }}
                    </button>
                  </div>
                  <div class="hint">Ambil rujukan &amp; surat kontrol pasien dari BPJS berdasarkan No. Kartu / NIK — pasien tak perlu membawa nomornya.</div>
                  <div v-if="bpjsPull.error" class="check-msg err">{{ bpjsPull.error }}</div>
                  <template v-if="bpjsPull.done && !bpjsPull.error">
                    <div class="pull-group">
                      <div class="pull-group-lbl">Rujukan <span class="pull-count">{{ bpjsPull.rujukan.length }}</span></div>
                      <div v-if="bpjsPull.rujukanError" class="check-msg err">{{ bpjsPull.rujukanError }}</div>
                      <div v-else-if="!bpjsPull.rujukan.length" class="pull-empty">Tidak ada rujukan aktif di BPJS.</div>
                      <button
                        v-for="r in bpjsPull.rujukan" :key="'rj-' + r.sumber + r.no" type="button"
                        :class="['pull-item', form.sepType === 'rujukan' && form.referralNo === r.no ? 'picked' : '']"
                        @click="pilihRujukanPull(r)"
                      >
                        <span class="pull-item-no">{{ r.no }} <span class="pull-tag">{{ r.sumber }}</span></span>
                        <span class="pull-item-meta">{{ r.tgl || '—' }} · {{ r.poli }} · {{ r.diag }}</span>
                      </button>
                    </div>
                    <div class="pull-group">
                      <div class="pull-group-lbl">Surat Kontrol <span class="pull-count">{{ bpjsPull.kontrol.length }}</span></div>
                      <div v-if="bpjsPull.kontrolError" class="check-msg err">{{ bpjsPull.kontrolError }}</div>
                      <div v-else-if="!bpjsPull.kontrol.length" class="pull-empty">Tidak ada surat kontrol terjadwal (bulan ini &amp; bulan depan). Jika pasien membawa nomor SC, pilih tipe <strong>Kontrol</strong> lalu <strong>Cek SK</strong> by nomor.</div>
                      <button
                        v-for="k in bpjsPull.kontrol" :key="'sk-' + k.no" type="button"
                        :class="['pull-item', form.sepType === 'kontrol' && form.controlNo === k.no ? 'picked' : '']"
                        @click="pilihKontrolPull(k)"
                      >
                        <span class="pull-item-no">{{ k.no }}</span>
                        <span class="pull-item-meta">{{ k.tgl || '—' }} · {{ k.poli }} · {{ k.dokter }}</span>
                      </button>
                    </div>
                  </template>
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
                  <div class="inline-check">
                    <input v-model="form.referralNo" class="form-input" placeholder="Nomor rujukan dari Faskes 1" />
                    <button type="button" class="btn-check" :disabled="rujukanCheck.loading" @click="cekRujukanBpjs">
                      {{ rujukanCheck.loading ? 'Mengecek…' : 'Cek Rujukan' }}
                    </button>
                  </div>
                  <div class="hint">Wajib untuk kunjungan pertama pasien BPJS ke FKRTL</div>
                  <div v-if="rujukanCheck.error" class="check-msg err">{{ rujukanCheck.error }}</div>
                  <div v-else-if="rujukanCheck.data" class="check-msg ok">
                    {{ rujukanCheck.data.diagnosa?.nama ?? '—' }} · Poli {{ rujukanCheck.data.poliRujukan?.nama || rujukanCheck.data.poliRujukan?.kode || '—' }}
                    · {{ rujukanCheck.data.provPerujuk?.nama ?? '—' }} · {{ rujukanCheck.data.tglKunjungan ?? '—' }}
                  </div>
                </div>
                <div v-else-if="form.sepType === 'kontrol'" class="field full">
                  <label class="field-lbl">No. Surat Kontrol (SC)</label>
                  <div class="inline-check">
                    <input v-model="form.controlNo" class="form-input" placeholder="Nomor surat kontrol (SC) dari pasien" />
                    <button type="button" class="btn-check" :disabled="kontrolCheck.loading" @click="cekSuratKontrol">
                      {{ kontrolCheck.loading ? 'Mengecek…' : 'Cek SK' }}
                    </button>
                  </div>
                  <div class="hint">Cek langsung ke BPJS dengan nomor SC — tetap berlaku walau terbit lewat web VClaim atau tanggal kontrol di luar bulan ini.</div>
                  <div v-if="kontrolCheck.error" class="check-msg err">{{ kontrolCheck.error }}</div>
                  <div v-else-if="kontrolCheck.data" class="check-msg ok">
                    Poli {{ kontrolCheck.data.poli }} · {{ kontrolCheck.data.dokter }} · Rencana kontrol {{ kontrolCheck.data.tgl }}
                  </div>
                </div>
                <div v-else class="field full">
                  <label class="field-lbl">Kode Booking JKN Mobile</label>
                  <input v-model="form.bookingCode" class="form-input" placeholder="Kode booking dari aplikasi JKN Mobile" />
                  <div class="hint">Kode dari aplikasi Mobile JKN BPJS Kesehatan (Antrean Online)</div>
                </div>
                <div class="field full">
                  <label class="field-lbl">No. Kartu BPJS</label>
                  <div class="inline-check">
                    <input v-model="form.bpjsNo" class="form-input" placeholder="13 digit nomor kartu" />
                    <button type="button" class="btn-check" :disabled="bpjsCheck.loading" @click="cekPesertaBpjs">
                      {{ bpjsCheck.loading ? 'Mengecek…' : 'Cek Peserta' }}
                    </button>
                  </div>
                  <div class="hint">Terisi otomatis setelah <strong>Cek Rujukan</strong> bila peserta ditemukan</div>
                  <div v-if="bpjsCheck.error" class="check-msg err">{{ bpjsCheck.error }}</div>
                  <div v-else-if="bpjsCheck.peserta" class="check-msg ok">
                    <b>{{ bpjsCheck.peserta.nama }}</b> ·
                    {{ bpjsCheck.peserta.hakKelas?.keterangan ?? '—' }} ·
                    <span :class="bpjsCheck.peserta.statusPeserta?.kode === '0' ? 'st-ok' : 'st-warn'">
                      {{ bpjsCheck.peserta.statusPeserta?.keterangan ?? '—' }}
                    </span>
                    <template v-if="bpjsCheck.peserta.cob?.nmAsuransi"> · COB: {{ bpjsCheck.peserta.cob.nmAsuransi }}</template>
                  </div>
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
                <div class="field">
                  <label class="field-lbl">No. Polis / Kartu Peserta</label>
                  <input v-model="form.insuranceNo" class="form-input" placeholder="Nomor polis / peserta asuransi" />
                </div>
                <div class="field">
                  <label class="field-lbl">Nama Peserta (di kartu)</label>
                  <input v-model="form.memberName" class="form-input" placeholder="Sesuai kartu fisik" />
                </div>
                <div class="field full">
                  <label class="field-lbl">Nomor Kartu Fisik (opsional)</label>
                  <input v-model="form.memberCardNumber" class="form-input" placeholder="Jika berbeda dari no. polis" />
                </div>
                <div v-if="selectedInsurerInfo" class="info-box full">
                  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="16" x2="12" y2="12"/><circle cx="12" cy="8" r=".6" fill="currentColor"/></svg>
                  <span>
                    <strong>PIC:</strong>
                    {{ selectedInsurerInfo.pic_name || '—' }}
                    <span v-if="selectedInsurerInfo.pic_phone"> · {{ selectedInsurerInfo.pic_phone }}</span>
                    <span v-if="selectedInsurerInfo.claim_submission_notes">
                      <br><em>{{ selectedInsurerInfo.claim_submission_notes }}</em>
                    </span>
                  </span>
                </div>
                <div class="info-box full">
                  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="16" x2="12" y2="12"/><circle cx="12" cy="8" r=".6" fill="currentColor"/></svg>
                  <span>Verifikasi eligibilitas dilakukan billing secara paralel (cek portal TPA). Status akan tampil sebagai badge di antrean.</span>
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

              <!-- COB — penjamin kedua. Untuk BPJS (INA-CBG + selisih) / ASURANSI / PERUSAHAAN. -->
              <template v-if="['BPJS','ASURANSI','PERUSAHAAN'].includes(form.guarantor)">
                <div class="divider full"></div>
                <div class="field full">
                  <label class="cob-toggle-row">
                    <input type="checkbox" class="cob-check" :checked="form.cobEnabled" @change="onCobToggle" />
                    <span class="field-lbl" style="margin:0">Tambahkan Penjamin Kedua (COB)</span>
                  </label>
                  <div class="hint">Coordination of Benefits — penjamin utama menanggung sesuai haknya (BPJS: INA-CBG), sisa/selisih ditanggung penjamin kedua.</div>
                </div>

                <template v-if="form.cobEnabled">
                  <div class="field full">
                    <label class="field-lbl">Asuransi Penjamin Kedua <span style="color:#ef4444">*</span></label>
                    <div class="combo-wrap">
                      <div class="input-wrap-block">
                        <input
                          v-model="cobSearch"
                          class="form-input"
                          placeholder="Ketik untuk cari asuransi..."
                          @focus="cobDropdownOpen = true"
                          @input="cobDropdownOpen = true"
                          @blur="onCobBlur"
                        />
                        <svg class="combo-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                      </div>
                      <transition name="modal-fade">
                        <div v-if="cobDropdownOpen" class="combo-dropdown">
                          <div
                            v-for="ins in cobInsurers"
                            :key="ins.id"
                            class="combo-item"
                            :class="{ active: form.cobInsurerId === ins.id }"
                            @mousedown="selectCobInsurer(ins)"
                          >
                            <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/></svg>
                            {{ ins.name }}
                          </div>
                          <div v-if="cobInsurers.length === 0" class="combo-empty">
                            Tidak ada asuransi lain — tambah via Tarif &amp; Paket → Metode Bayar
                          </div>
                        </div>
                      </transition>
                    </div>
                    <div class="hint">Penjamin kedua harus bertipe Asuransi dan berbeda dari penjamin utama.</div>
                  </div>
                  <div class="field full">
                    <label class="field-lbl">No. Polis / Kartu Penjamin Kedua (opsional)</label>
                    <input v-model="form.cobInsuranceNo" class="form-input" placeholder="Nomor polis asuransi kedua" />
                  </div>
                </template>
              </template>

              <div class="divider full"></div>
              <div class="section-label full">Tujuan Layanan</div>
              <div class="field full">
                <label class="field-lbl">Dokter Tujuan <span style="color:#ef4444">*</span></label>
                <select v-model="form.doctor_schedule_id" class="form-select" required>
                  <option value="" disabled>{{ wizardDoctorList.length ? 'Pilih dokter (jadwal aktif hari ini)' : (form.guarantor === 'BPJS' ? 'Tidak ada jadwal BPJS aktif hari ini' : 'Tidak ada jadwal Eksekutif aktif hari ini') }}</option>
                  <option v-for="d in wizardDoctorList" :key="d.id" :value="d.id">{{ d.label }}</option>
                </select>
                <div class="field-hint">
                  Menampilkan jadwal <strong>{{ form.guarantor === 'BPJS' ? 'BPJS' : 'Eksekutif' }}</strong> sesuai penjamin.
                </div>
                <div v-if="selectedSchedule" class="field-hint">
                  Antrian: <strong>{{ selectedSchedule.queuePrefix }}-XXX</strong> · Poli {{ selectedSchedule.poliklinik }}<span v-if="selectedSchedule.room"> · Ruang {{ selectedSchedule.room }}</span>
                </div>
                <div v-if="selectedSchedule?.hampirPenuh" class="field-hint" style="color:#b45309;font-weight:600">
                  ⚠ Jadwal hampir penuh — sisa BPJS {{ selectedSchedule.sisaJkn ?? '?' }} · Umum {{ selectedSchedule.sisaNonJkn ?? '?' }}. Pertimbangkan dahulukan triase pasien ini.
                </div>
              </div>
            </div>

            <!-- ===== STEP 3: KONFIRMASI ===== -->
            <div v-if="wizardStep === 3" class="confirm-wrap">
              <div class="confirm-card">
                <div class="confirm-sec-head">
                  <div>
                    <div class="confirm-sec-title">Data Pasien</div>
                    <div v-if="form.patientMode === 'existing'" class="confirm-photo-note">Ambil foto pasien sebelum konfirmasi</div>
                  </div>
                  <div class="confirm-photo">
                    <PatientAvatar :name="form.name" :src="form.photo" :size="52" :zoomable="!!form.photo" />
                    <button v-if="form.patientMode === 'existing'" type="button" class="btn btn-secondary btn-sm" @click="photoModalOpen = true">
                      <svg viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                      {{ form.photo ? 'Ganti Foto' : 'Ambil Foto' }}
                    </button>
                  </div>
                </div>
                <div class="confirm-grid">
                  <div class="cf"><span class="cf-k">Nama</span><span class="cf-v">{{ form.name || '—' }}</span></div>
                  <div class="cf"><span class="cf-k">No. RM</span><span class="cf-v">{{ form.noRm || 'Baru (auto)' }}</span></div>
                  <div class="cf"><span class="cf-k">Jenis Identitas</span><span class="cf-v">{{ activeIdentity.label }}</span></div>
                  <div class="cf"><span class="cf-k">{{ activeIdentity.numberLabel }}</span><span class="cf-v">{{ form.nik || '—' }}</span></div>
                  <div class="cf"><span class="cf-k">Jenis Kelamin</span><span class="cf-v">{{ form.sex === 'L' ? 'Laki-laki' : 'Perempuan' }}</span></div>
                  <div class="cf"><span class="cf-k">Tanggal Lahir</span><span class="cf-v">{{ form.birthDate ? fmtDate(form.birthDate) : '—' }}</span></div>
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
                  <div v-if="form.cobEnabled && form.cobInsurerId" class="cf"><span class="cf-k">Penjamin Kedua (COB)</span><span class="cf-v"><span class="ptype-tag pt-asuransi">{{ form.cobInsuranceName }}</span><span v-if="form.cobInsuranceNo"> · {{ form.cobInsuranceNo }}</span></span></div>
                  <div class="cf"><span class="cf-k">Klasifikasi</span><span class="cf-v"><span :class="['classif-badge classif-on-sm', 'cls-' + form.classification.replace('-','').toLowerCase()]">{{ form.classification }}</span></span></div>
                  <div v-if="preopChoice === 'PREOP' && selectedPreopSchedule" class="cf">
                    <span class="cf-k">Tipe Kunjungan</span>
                    <span class="cf-v">
                      <span class="preop-badge-confirm">PREOP BEDAH</span>
                      &nbsp;{{ selectedPreopSchedule.surgery_package?.name ?? '' }}
                      <span v-if="!selectedPreopSchedule.is_today" class="preop-shift-note">
                        · jadwal dipindah ke hari ini
                      </span>
                    </span>
                  </div>
                  <div class="cf"><span class="cf-k">Dokter</span><span class="cf-v">{{ selectedSchedule?.name || '—' }}</span></div>
                  <div class="cf"><span class="cf-k">Poliklinik</span><span class="cf-v">{{ selectedSchedule?.poliklinik || '—' }}</span></div>
                  <div class="cf"><span class="cf-k">Ruangan</span><span class="cf-v">{{ selectedSchedule?.room ? `Ruang ${selectedSchedule.room} (Antrian ${selectedSchedule.queuePrefix}-XXX)` : '—' }}</span></div>
                </div>
              </div>

              <!-- Pre-flight kesiapan SEP (BPJS, bukan PREOP) — cek poli/rujukan/
                   diagnosa/kepesertaan sebelum daftar agar SEP tak gagal beruntun. -->
              <div v-if="form.guarantor === 'BPJS' && preopChoice !== 'PREOP'" class="pf-card">
                <div class="pf-head">
                  <div class="confirm-sec-title" style="margin:0">Kesiapan SEP BPJS</div>
                  <button type="button" class="pf-recheck" :disabled="preflight.loading" @click="jalankanPreflight">
                    {{ preflight.loading ? 'Memeriksa…' : '↻ Periksa ulang' }}
                  </button>
                </div>

                <div v-if="preflight.loading" class="pf-loading">Memeriksa kesiapan ke BPJS…</div>
                <div v-else-if="preflight.error" class="pf-banner pf-warn">{{ preflight.error }} — penerbitan SEP otomatis tetap dicoba saat daftar.</div>

                <template v-else-if="preflight.report">
                  <div class="pf-banner" :class="preflight.report.ready ? 'pf-ok' : 'pf-block'">
                    <strong>{{ preflight.report.ready ? '✓ Data SEP siap' : '⚠ Ada yang perlu dibetulkan' }}</strong>
                    <span v-if="!preflight.report.ready"> — perbaiki dulu agar SEP tidak ditolak BPJS (boleh tetap daftar, SEP bisa diterbitkan manual nanti).</span>
                  </div>

                  <ul v-if="preflight.report.issues?.length" class="pf-list pf-list-block">
                    <li v-for="(it, i) in preflight.report.issues" :key="'i'+i">{{ it }}</li>
                  </ul>
                  <ul v-if="preflight.report.warnings?.length" class="pf-list pf-list-warn">
                    <li v-for="(w, i) in preflight.report.warnings" :key="'w'+i">{{ w }}</li>
                  </ul>

                  <div class="pf-grid">
                    <div class="cf"><span class="cf-k">Kepesertaan</span><span class="cf-v">
                      <template v-if="preflight.report.peserta">
                        <span :class="preflight.report.peserta.aktif ? 'pf-pill pf-pill-ok' : 'pf-pill pf-pill-bad'">{{ preflight.report.peserta.status }}</span>
                        <span v-if="preflight.report.peserta.hakKelas"> · {{ preflight.report.peserta.hakKelas }}</span>
                      </template>
                      <span v-else>—</span>
                    </span></div>
                    <div class="cf"><span class="cf-k">Poli dokter</span><span class="cf-v">
                      {{ preflight.report.poli?.nama || '—' }}
                      <span v-if="preflight.report.poli && !preflight.report.poli.mapped" class="pf-pill pf-pill-bad">belum dipetakan</span>
                    </span></div>
                    <div v-if="preflight.report.rujukan" class="cf"><span class="cf-k">Poli rujukan</span><span class="cf-v">
                      {{ preflight.report.rujukan.poliNama }}
                      <span v-if="preflight.report.poli?.cocokRujukan === true" class="pf-pill pf-pill-ok">cocok</span>
                      <span v-else-if="preflight.report.poli?.cocokRujukan === false" class="pf-pill pf-pill-bad">tidak cocok</span>
                    </span></div>
                    <div class="cf"><span class="cf-k">Diagnosa awal</span><span class="cf-v">
                      <template v-if="preflight.report.diagnosa?.ada">{{ preflight.report.diagnosa.kode }} — {{ preflight.report.diagnosa.nama }}</template>
                      <span v-else class="pf-pill pf-pill-bad">belum ada</span>
                    </span></div>
                  </div>
                </template>
              </div>

              <!-- General Consent (pasien baru, opsional) -->
              <div v-if="form.patientMode === 'new'" class="gc-card">
                <div class="gc-card-icon">
                  <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
                </div>
                <div class="gc-card-main">
                  <div class="gc-card-title">Persetujuan Umum (General Consent)</div>
                  <div class="gc-card-sub">Pasien / wali menandatangani sebelum cetak antrean. <em>Opsional — boleh dilewati.</em></div>
                  <div class="gc-badge" :class="consentPatientSigned ? 'gc-badge-ok' : 'gc-badge-warn'">
                    <template v-if="consentPatientSigned">✓ Sudah ditandatangani{{ consentAllSigned ? '' : ' (saksi belum)' }}</template>
                    <template v-else>⚠ Belum ditandatangani</template>
                  </div>
                </div>
                <button type="button" class="gc-card-btn" @click="openConsentModal">
                  {{ consentPatientSigned ? 'Tinjau Ulang' : 'Tinjau & Tanda Tangani' }}
                </button>
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

    <!-- ===================== GENERAL CONSENT MODAL (tinjau + TTD) ===================== -->
    <Teleport to="body">
      <div v-if="consentModalOpen" class="gc-overlay" @click.self="consentModalOpen = false">
        <div class="gc-modal">
          <header class="gc-modal-head">
            <div>
              <h3>Persetujuan Umum (General Consent)</h3>
              <p class="gc-modal-sub">Tinjau isi dokumen, lalu minta pasien / wali menandatangani.</p>
            </div>
            <button class="gc-modal-x" aria-label="Tutup" @click="consentModalOpen = false">×</button>
          </header>

          <div class="gc-modal-body">
            <div v-if="consentLoading" class="gc-modal-loading">Memuat dokumen…</div>
            <div v-else-if="consentError" class="gc-modal-error">{{ consentError }}</div>
            <div v-else class="gc-doc" v-html="consentHtml"></div>
          </div>

          <footer class="gc-modal-foot">
            <div class="gc-sign-actions">
              <button
                v-for="f in consentSignFields" :key="f.signer_type"
                type="button"
                class="gc-sign-btn"
                :class="{ 'gc-sign-btn-done': isSignerSigned(f.signer_type) }"
                @click="startSignature(f)"
              >
                <span class="gc-sign-check" v-if="isSignerSigned(f.signer_type)">✓</span>
                {{ isSignerSigned(f.signer_type) ? 'Ulangi ' : '' }}{{ f.label || f.signer_type }}
              </button>
            </div>
            <button class="gc-done-btn" @click="consentModalOpen = false">Selesai</button>
          </footer>
        </div>
      </div>
    </Teleport>

    <!-- Capture TTD (reuse komponen Form Registry) -->
    <SignatureCaptureModal
      v-model="sigCaptureOpen"
      :signer-type="sigCaptureType"
      :signer-label="sigCaptureLabel"
      document-name="Persetujuan Umum (General Consent)"
      :ask-external-identity="sigCaptureType === 'witness' || sigCaptureType === 'guardian'"
      @capture="onConsentCapture"
    />

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
              <div class="field">
                <label class="field-lbl">No. Telepon Keluarga</label>
                <input v-model="editForm.family_phone" class="form-input" placeholder="08xx... (wali / darurat)" maxlength="20" />
              </div>
              <div class="field">
                <label class="field-lbl">Email</label>
                <input v-model="editForm.email" type="email" class="form-input" placeholder="nama@email.com" maxlength="255" />
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

    <!-- ===================== EDIT PENJAMIN MODAL ===================== -->
    <transition name="modal-fade">
      <div v-if="penjaminOpen" class="modal-backdrop" @click.self="closeEditPenjamin">
        <div class="modal-shell modal-sm">
          <div class="modal-head">
            <div>
              <div class="modal-title">Ubah Penjamin / Pola Bayar</div>
              <div class="modal-sub">{{ penjaminForm.patientName }} · sekarang: {{ penjaminForm.currentLabel }}</div>
            </div>
            <button class="modal-x" aria-label="Tutup" @click="closeEditPenjamin">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <div class="modal-body">
            <div class="info-box">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="16" x2="12" y2="12"/><circle cx="12" cy="8" r=".6" fill="currentColor"/></svg>
              <span>Mengubah penjamin akan mengubah pola bayar (tarif diresolusi ulang di Kasir). Tidak bisa diubah bila tagihan sudah dikirim ke kasir atau SEP BPJS masih aktif.</span>
            </div>

            <div class="field full">
              <label class="field-lbl">Tipe Penjamin</label>
              <div class="classif-badges">
                <button
                  v-for="g in ['UMUM', 'BPJS', 'ASURANSI', 'PERUSAHAAN', 'SOSIAL']"
                  :key="g"
                  type="button"
                  :class="['classif-badge', penjaminForm.guarantor === g ? 'classif-on' : '']"
                  @click="setPenjaminType(g)"
                >{{ guarantorLabel(g) }}</button>
              </div>
            </div>

            <!-- Insurer (ASURANSI / PERUSAHAAN / SOSIAL) — typeahead cari nama -->
            <div v-if="['ASURANSI','PERUSAHAAN','SOSIAL'].includes(penjaminForm.guarantor)" class="field full">
              <label class="field-lbl">Penjamin ({{ guarantorLabel(penjaminForm.guarantor) }})</label>
              <div class="combo-wrap">
                <div class="input-wrap-block">
                  <input
                    v-model="penjaminInsSearch"
                    class="form-input"
                    placeholder="Ketik untuk cari penjamin..."
                    @focus="penjaminInsOpen = true"
                    @input="penjaminInsOpen = true"
                    @blur="onPenjaminInsBlur"
                  />
                  <svg class="combo-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
                <transition name="modal-fade">
                  <div v-if="penjaminInsOpen" class="combo-dropdown">
                    <div
                      v-for="ins in penjaminInsFiltered"
                      :key="ins.id"
                      class="combo-item"
                      :class="{ active: penjaminForm.insurer_id === ins.id }"
                      @mousedown="selectPenjaminInsurer(ins)"
                    >
                      <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/></svg>
                      {{ ins.name }}
                    </div>
                    <div v-if="penjaminInsFiltered.length === 0" class="combo-empty">
                      Tidak ada penjamin tipe "{{ guarantorLabel(penjaminForm.guarantor) }}"
                    </div>
                  </div>
                </transition>
              </div>
            </div>

            <!-- Data kartu TPA (ASURANSI / PERUSAHAAN) -->
            <template v-if="['ASURANSI','PERUSAHAAN'].includes(penjaminForm.guarantor)">
              <div class="field full">
                <label class="field-lbl">No. Polis / Kartu <span class="field-opt">(opsional)</span></label>
                <input v-model="penjaminForm.policyNumber" class="form-input" placeholder="Nomor polis / kartu peserta" />
              </div>
              <div class="field full">
                <label class="field-lbl">Nama Peserta <span class="field-opt">(opsional)</span></label>
                <input v-model="penjaminForm.memberName" class="form-input" placeholder="Nama sesuai kartu" />
              </div>
            </template>

            <!-- BPJS: dasar SEP -->
            <template v-if="penjaminForm.guarantor === 'BPJS'">
              <div class="field full">
                <label class="field-lbl">Dasar SEP</label>
                <select v-model="penjaminForm.sepType" class="form-select">
                  <option value="rujukan">Rujukan</option>
                  <option value="kontrol">Surat Kontrol</option>
                  <option value="jkn">Booking (Antrean JKN)</option>
                </select>
              </div>
              <div v-if="penjaminForm.sepType === 'rujukan'" class="field full">
                <label class="field-lbl">No. Rujukan <span class="field-opt">(opsional)</span></label>
                <input v-model="penjaminForm.referralNo" class="form-input" placeholder="Nomor rujukan BPJS" />
              </div>
              <div v-else-if="penjaminForm.sepType === 'kontrol'" class="field full">
                <label class="field-lbl">No. Surat Kontrol <span class="field-opt">(opsional)</span></label>
                <input v-model="penjaminForm.controlNo" class="form-input" placeholder="Nomor surat kontrol" />
              </div>
              <div v-else class="field full">
                <label class="field-lbl">Kode Booking <span class="field-opt">(opsional)</span></label>
                <input v-model="penjaminForm.bookingCode" class="form-input" placeholder="Kode booking antrean JKN" />
              </div>
              <div class="hint">SEP diterbitkan terpisah di panel Detail Kunjungan / BPJS setelah penjamin disetel ke BPJS.</div>
            </template>

            <!-- COB (penjamin kedua) -->
            <div v-if="['BPJS','ASURANSI','PERUSAHAAN'].includes(penjaminForm.guarantor)" class="field full">
              <label class="cob-toggle-row">
                <input type="checkbox" class="cob-check" :checked="penjaminForm.cobEnabled" @change="onPenjaminCobToggle" />
                <span class="field-lbl" style="margin:0">COB — penjamin kedua menanggung selisih</span>
              </label>
              <template v-if="penjaminForm.cobEnabled">
                <div class="combo-wrap" style="margin-top:8px">
                  <div class="input-wrap-block">
                    <input
                      v-model="penjaminCobSearch"
                      class="form-input"
                      placeholder="Ketik untuk cari penjamin kedua..."
                      @focus="penjaminCobOpen = true"
                      @input="penjaminCobOpen = true"
                      @blur="onPenjaminCobBlur"
                    />
                    <svg class="combo-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                  </div>
                  <transition name="modal-fade">
                    <div v-if="penjaminCobOpen" class="combo-dropdown">
                      <div
                        v-for="ins in penjaminCobFiltered"
                        :key="ins.id"
                        class="combo-item"
                        :class="{ active: penjaminForm.cobInsurerId === ins.id }"
                        @mousedown="selectPenjaminCob(ins)"
                      >
                        <svg viewBox="0 0 24 24"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/></svg>
                        {{ ins.name }}
                      </div>
                      <div v-if="penjaminCobFiltered.length === 0" class="combo-empty">
                        Tidak ada penjamin lain (harus tipe Asuransi/Perusahaan & berbeda dari penjamin utama)
                      </div>
                    </div>
                  </transition>
                </div>
                <input v-model="penjaminForm.cobNo" class="form-input" style="margin-top:8px" placeholder="No. polis penjamin 2 (opsional)" />
              </template>
            </div>
          </div>
          <div class="modal-foot">
            <button class="btn btn-secondary" :disabled="penjaminSaving" @click="closeEditPenjamin">Batal</button>
            <button class="btn btn-primary" :disabled="penjaminSaving" @click="submitEditPenjamin">
              <span v-if="penjaminSaving" class="spin-xs"></span>
              <svg v-else viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
              Simpan Penjamin
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- ===================== GANTI DOKTER MODAL ===================== -->
    <transition name="modal-fade">
      <div v-if="dokterOpen" class="modal-backdrop" @click.self="closeEditDokter">
        <div class="modal-shell modal-sm">
          <div class="modal-head">
            <div>
              <div class="modal-title">Ubah Dokter Pemeriksa</div>
              <div class="modal-sub">{{ dokterForm.patientName }} · sekarang: {{ dokterForm.currentDoctor }}</div>
            </div>
            <button class="modal-x" aria-label="Tutup" @click="closeEditDokter">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <div class="modal-body">
            <div class="info-box">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="16" x2="12" y2="12"/><circle cx="12" cy="8" r=".6" fill="currentColor"/></svg>
              <span>Untuk koreksi salah-pilih dokter saat pendaftaran. Pasien otomatis pindah ke antrean dokter baru. Hanya bisa selama dokter belum memanggil/memeriksa pasien.</span>
            </div>

            <div class="field full">
              <label class="field-lbl">Dokter Tujuan (jadwal aktif hari ini)</label>
              <select v-model="dokterForm.scheduleId" class="form-select">
                <option value="" disabled>{{ editDoctorList.length ? '— Pilih dokter —' : (dokterForm.guarantor === 'BPJS' ? 'Tidak ada jadwal BPJS aktif' : 'Tidak ada jadwal Eksekutif aktif') }}</option>
                <option v-for="d in editDoctorList" :key="d.id" :value="d.id">{{ d.label }}</option>
              </select>
            </div>
          </div>
          <div class="modal-foot">
            <button class="btn btn-secondary" :disabled="dokterSaving" @click="closeEditDokter">Batal</button>
            <button class="btn btn-primary" :disabled="dokterSaving || !dokterForm.scheduleId" @click="submitEditDokter">
              <span v-if="dokterSaving" class="spin-xs"></span>
              <svg v-else viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
              Simpan Dokter
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- ===================== DETAIL KUNJUNGAN MODAL ===================== -->
    <transition name="modal-fade">
      <div v-if="visitDetailOpen && visitDetailRow" class="modal-backdrop" @click.self="closeVisitDetail">
        <div class="modal-shell modal-sm">
          <div class="modal-head">
            <div>
              <div class="modal-title">Detail Kunjungan</div>
              <div class="modal-sub">{{ visitDetailRow.noRegistrasi }} · {{ visitDetailRow.arrivedDate }}</div>
            </div>
            <button class="modal-x" aria-label="Tutup detail kunjungan" @click="closeVisitDetail">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>

          <div class="modal-body">
            <div class="detail-grid">
              <div class="cf"><span class="cf-k">No. Registrasi / Pendaftaran</span><span class="cf-v">{{ visitDetailRow.noRegistrasi }}</span></div>
              <div class="cf"><span class="cf-k">No. Antrean</span><span class="cf-v">{{ visitDetailRow.queueNo }}</span></div>
              <div class="cf"><span class="cf-k">Dokter / DPJP</span><span class="cf-v">{{ visitDetailRow.doctor ?? '—' }}</span></div>
              <div class="cf"><span class="cf-k">Metode Pembayaran</span>
                <span class="cf-v">
                  <span :class="['ptype-tag', visitDetailRow.walkIn ? 'pt-walkin' : ptypeClass(visitDetailRow.guarantor)]">
                    {{ visitDetailRow.walkIn ? 'WALK-IN' : visitDetailRow.guarantor }}
                  </span>
                  <span v-if="visitDetailRow.insurer" class="vd-insurer"> · {{ visitDetailRow.insurer }}</span>
                </span>
              </div>
              <div class="cf full">
                <span class="cf-k">Nama Pasien</span>
                <span class="cf-v">
                  <button
                    class="vd-name-link"
                    :disabled="!visitDetailRow.patientId"
                    :title="visitDetailRow.patientId ? 'Lihat detail & riwayat pasien' : 'Pasien walk-in belum terdaftar'"
                    @click="openPatientFromVisit"
                  >
                    {{ visitDetailRow.name }}
                    <svg v-if="visitDetailRow.patientId" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  </button>
                </span>
              </div>
            </div>

            <!-- Banner: kunjungan ini hasil rujukan internal antar-poli -->
            <div v-if="visitDetailRow.classification === 'Rujukan Internal'" class="vd-rujuk-banner">
              ↪ Kunjungan ini <b>rujukan internal</b>{{ visitDetailRow.internalRefFrom ? ' dari ' + visitDetailRow.internalRefFrom : '' }}.
            </div>

            <!-- SEP — hanya untuk pasien BPJS -->
            <div v-if="visitDetailRow.guarantor === 'BPJS'" class="vd-section">
              <div class="vd-section-row">
                <div>
                  <div class="vd-section-title">SEP (Surat Eligibilitas Peserta)</div>
                  <div class="vd-section-val">{{ visitDetailRow.noSep ?? 'Belum terbit' }}</div>
                </div>
                <span v-if="visitDetailRow.noSep" class="badge-soon" style="background:#dcfce7;color:#166534">Terbit</span>
              </div>

              <!-- Diagnosa Awal (wajib BPJS) — auto dari rujukan FKTP / isi manual.
                   SEP ditolak ("Diagnosa Awal Tidak Boleh Kosong") bila ini kosong. -->
              <div class="vd-diag">
                <div class="vd-diag-k">Diagnosa Awal</div>
                <div class="vd-diag-v" :class="{ empty: !visitDetailRow.diagnosaAwal }">
                  <template v-if="visitDetailRow.diagnosaAwal">
                    <b>{{ visitDetailRow.diagnosaAwal }}</b><span v-if="visitDetailRow.diagnosaAwalNama"> · {{ visitDetailRow.diagnosaAwalNama }}</span>
                  </template>
                  <template v-else>Belum ada — SEP akan ditolak BPJS</template>
                </div>
                <div v-if="!visitDetailRow.noSep && !diagAction.editing" class="vd-actions vd-diag-actions">
                  <button
                    class="btn btn-sm btn-secondary"
                    :disabled="diagAction.loading"
                    :title="visitDetailRow.noRujukan ? 'Tarik diagnosa dari rujukan FKTP BPJS' : 'Tarik rujukan & diagnosa pasien dari BPJS via No. Kartu'"
                    @click="tarikDiagnosa"
                  >
                    {{ diagAction.loading ? 'Menarik…' : 'Tarik dari BPJS' }}
                  </button>
                  <button class="btn btn-sm btn-secondary" @click="startEditDiagnosa">Isi manual</button>
                </div>

                <!-- Input manual / override diagnosa -->
                <div v-if="diagAction.editing && !visitDetailRow.noSep" class="vd-diag-edit">
                  <div class="vd-sep-grid">
                    <div class="fg-sm">
                      <label>Kode ICD-10</label>
                      <input v-model="diagAction.kode" class="form-input" placeholder="mis. H25.9" />
                    </div>
                    <div class="fg-sm fg-wide">
                      <label>Nama Diagnosa (opsional)</label>
                      <input v-model="diagAction.nama" class="form-input" placeholder="mis. Katarak senilis" />
                    </div>
                  </div>
                  <div class="vd-actions">
                    <button class="btn btn-sm btn-primary" :disabled="diagAction.saving" @click="simpanDiagnosa">
                      {{ diagAction.saving ? 'Menyimpan…' : 'Simpan Diagnosa' }}
                    </button>
                    <button class="btn btn-sm btn-secondary" @click="diagAction.editing = false">Batal</button>
                  </div>
                </div>
              </div>

              <div class="vd-actions">
                <button v-if="!visitDetailRow.noSep" class="btn btn-sm btn-primary" :disabled="sepAction.loading" @click="terbitkanSep">
                  <svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="6" rx="1"/><path d="M6 8h12v6a2 2 0 01-2 2H8a2 2 0 01-2-2V8z"/><path d="M8 16v4h8v-4"/></svg>
                  {{ sepAction.loading ? 'Menerbitkan…' : 'Terbitkan SEP' }}
                </button>
                <template v-else>
                  <button class="btn btn-sm btn-secondary" :disabled="sepAction.printing" @click="cetakSep">
                    <svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="6" rx="1"/><path d="M6 8h12v6a2 2 0 01-2 2H8a2 2 0 01-2-2V8z"/><path d="M8 16v4h8v-4"/></svg>
                    {{ sepAction.printing ? 'Menyiapkan…' : 'Cetak SEP' }}
                  </button>
                  <button class="btn btn-sm btn-primary" :disabled="sepEdit.loading" @click="startEditSep">
                    <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4z"/></svg>
                    Edit SEP
                  </button>
                  <button class="btn btn-sm btn-danger" :disabled="sepAction.loading" @click="batalkanSep">
                    <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                    {{ sepAction.loading ? 'Membatalkan…' : 'Batalkan SEP' }}
                  </button>
                </template>
              </div>

              <!-- Pesan gagal Terbitkan SEP — menetap di modal (toast mudah terlewat).
                   Contoh: poli belum dipetakan ke BPJS, VClaim off, diagnosa kosong. -->
              <div v-if="sepAction.error && !visitDetailRow.noSep" class="sep-error-inline">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><circle cx="12" cy="16" r=".6" fill="currentColor"/></svg>
                <span>{{ sepAction.error }}</span>
              </div>

              <!-- Form Edit SEP (PUT /SEP/2.0/update) — field ringkas -->
              <div v-if="sepEdit.open" class="vd-sep-edit">
                <div class="vd-sep-grid">
                  <div class="fg-sm">
                    <label>Kelas Rawat</label>
                    <select v-model="sepEdit.kls_rawat" class="form-input">
                      <option value="1">Kelas 1</option>
                      <option value="2">Kelas 2</option>
                      <option value="3">Kelas 3</option>
                    </select>
                  </div>
                  <div class="fg-sm">
                    <label>Diagnosa Awal (ICD-10)</label>
                    <input v-model="sepEdit.diag_awal" class="form-input" placeholder="mis. H25.9" />
                  </div>
                  <div class="fg-sm">
                    <label>No. Telp</label>
                    <input v-model="sepEdit.no_telp" class="form-input" placeholder="08…" />
                  </div>
                  <div class="fg-sm">
                    <label>Flag Katarak</label>
                    <select v-model="sepEdit.katarak" class="form-input">
                      <option value="0">Tidak</option>
                      <option value="1">Ya</option>
                    </select>
                  </div>
                  <div class="fg-sm fg-wide">
                    <label>Catatan</label>
                    <input v-model="sepEdit.catatan" class="form-input" placeholder="Catatan SEP…" />
                  </div>
                </div>
                <div class="vd-actions">
                  <button class="btn btn-sm btn-primary" :disabled="sepEdit.loading" @click="saveEditSep">
                    {{ sepEdit.loading ? 'Menyimpan…' : 'Simpan ke BPJS' }}
                  </button>
                  <button class="btn btn-sm btn-secondary" :disabled="sepEdit.loading" @click="sepEdit.open = false">Batal</button>
                </div>
                <div class="hint">Poli &amp; DPJP tetap dari pemetaan Jadwal Dokter (tak diubah di sini).</div>
              </div>

              <div v-else class="hint">SEP otomatis memakai pemetaan poli &amp; DPJP dari Jadwal Dokter. Aktif setelah bridging VClaim dinyalakan.</div>
            </div>

            <!-- SKDP / Surat Kontrol — hanya pasien BPJS -->
            <div v-if="visitDetailRow.guarantor === 'BPJS'" class="vd-section">
              <div class="vd-section-row">
                <div>
                  <div class="vd-section-title">SKDP / Surat Kontrol</div>
                  <div class="vd-section-val">{{ skAction.data?.no_surat_kontrol ?? 'Belum ada' }}</div>
                  <div v-if="skAction.data?.tanggal_rencana_kontrol" class="hint">
                    Kontrol: {{ skAction.data.tanggal_rencana_kontrol }}
                  </div>
                </div>
                <span v-if="skAction.data?.status === 'SUCCESS'" class="badge-soon" style="background:#dcfce7;color:#166534">Terbit</span>
                <span v-else-if="skAction.data?.status === 'DRAFT'" class="badge-soon" style="background:#fef9c3;color:#854d0e">Draft</span>
                <span v-else-if="skAction.data?.status === 'FAILED'" class="badge-soon" style="background:#fee2e2;color:#991b1b">Gagal</span>
              </div>

              <!-- SUCCESS → boleh edit tanggal kontrol (PUT VClaim) -->
              <template v-if="skAction.data?.status === 'SUCCESS'">
                <div v-if="!skAction.editing" class="vd-actions">
                  <button class="btn btn-sm btn-primary" @click="startEditSuratKontrol">
                    <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4z"/></svg>
                    Edit Tanggal Kontrol
                  </button>
                </div>
                <div v-else class="vd-edit-sk">
                  <input type="date" v-model="skAction.newDate" class="form-input" style="max-width:200px" />
                  <button class="btn btn-sm btn-primary" :disabled="skAction.loading" @click="saveEditSuratKontrol">
                    {{ skAction.loading ? 'Menyimpan…' : 'Simpan ke BPJS' }}
                  </button>
                  <button class="btn btn-sm btn-secondary" :disabled="skAction.loading" @click="skAction.editing = false">Batal</button>
                </div>
                <div class="hint">Mengubah tanggal kontrol di VClaim (PUT RencanaKontrol/v2/Update).</div>
              </template>

              <!-- DRAFT → diterbitkan otomatis oleh dokter saat finalisasi -->
              <div v-else-if="skAction.data?.status === 'DRAFT'" class="hint">
                Draft dibuat dokter. Akan terbit otomatis ke VClaim saat dokter finalisasi pemeriksaan.
              </div>
              <!-- FAILED → bisa diterbitkan ulang dari Bridging -->
              <div v-else-if="skAction.data?.status === 'FAILED'" class="hint">
                Penerbitan gagal — coba terbitkan ulang dari menu Bridging.
              </div>
              <div v-else class="hint">
                Belum ada Surat Kontrol. Dibuat saat dokter menentukan tanggal kontrol (planning Pulang).
              </div>
            </div>

          </div>

          <div class="modal-foot">
            <button class="btn btn-secondary" @click="closeVisitDetail">Tutup</button>
            <span class="foot-spacer"></span>
            <button class="btn btn-secondary" @click="printLabel(visitDetailRow)">
              <svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="6" rx="1"/><path d="M6 8h12v6a2 2 0 01-2 2H8a2 2 0 01-2-2V8z"/><path d="M8 16v4h8v-4"/></svg>
              Cetak Label
            </button>
            <button class="btn btn-primary" :disabled="!visitDetailRow.patientId" @click="gotoRekamMedis(visitDetailRow); closeVisitDetail()">
              <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              Buka Rekam Medis
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- ===================== PROFIL PASIEN MODAL (2 TAB) ===================== -->
    <transition name="modal-fade">
      <div v-if="profileOpen && profilePatient" class="modal-backdrop" @click.self="closeProfile">
        <div class="modal-shell modal-sm">
          <div class="modal-head">
            <div>
              <div class="modal-title">Profil Pasien</div>
              <div class="modal-sub">{{ profilePatient.no_rm ?? '—' }} · {{ profilePatient.name }}</div>
            </div>
            <button class="modal-x" aria-label="Tutup profil pasien" @click="closeProfile">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>

          <!-- Tabs -->
          <div class="profile-tabs">
            <button :class="['profile-tab', { active: profileTab === 'detail' }]" @click="profileTab = 'detail'">
              Detail Pasien
            </button>
            <button :class="['profile-tab', { active: profileTab === 'history' }]" @click="profileTab = 'history'">
              Riwayat Kunjungan
              <span v-if="riwayatMeta.total" class="profile-tab-count">{{ riwayatMeta.total }}</span>
            </button>
          </div>

          <div class="modal-body">
            <div v-if="profileLoading" class="profile-loading">
              <span class="spin-xs"></span> Memuat data pasien…
            </div>

            <!-- TAB 1: Detail data pasien -->
            <template v-else-if="profileTab === 'detail'">
              <div class="detail-hero">
                <PatientAvatar :name="profilePatient.name" :src="profilePatient.photo_url" :size="46" />
                <div>
                  <div class="detail-name">{{ profilePatient.name }}</div>
                  <div class="detail-sub">
                    {{ calcAge(profilePatient.date_of_birth) || '—' }} th ·
                    {{ profilePatient.gender === 'L' ? 'Laki-laki' : 'Perempuan' }}
                  </div>
                </div>
                <div class="detail-hero-right">
                  <span :class="['ptype-tag', profilePatient.bpjs_number ? 'pt-bpjs' : 'pt-umum']">
                    {{ profilePatient.bpjs_number ? 'BPJS' : 'UMUM' }}
                  </span>
                  <button v-if="!profileEdit.open" class="btn btn-secondary btn-sm" @click="startProfileEdit">
                    <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4z"/></svg>
                    Edit
                  </button>
                </div>
              </div>

              <!-- MODE BACA -->
              <template v-if="!profileEdit.open">
                <div class="detail-grid">
                  <div class="cf"><span class="cf-k">No. Rekam Medis</span><span class="cf-v">{{ profilePatient.no_rm ?? '—' }}</span></div>
                  <!-- NIK + status IHS Satu Sehat + tombol Resolve = 1 kesatuan.
                       IHS di-resolve PURELY dari NIK (GET Patient?identifier=nik) —
                       provinsi/alamat tidak berpengaruh; gagal resolve = NIK salah/
                       belum terdaftar di Kemenkes. -->
                  <div class="cf full">
                    <span class="cf-k">NIK &amp; IHS Satu Sehat</span>
                    <span class="cf-v ihs-cf-v">
                      <span class="ihs-nik">{{ profilePatient.nik || 'NIK belum diisi' }}</span>
                      <span v-if="profilePatient.satusehat_ihs" class="ihs-badge ihs-ok" title="Pasien sudah punya IHS Satu Sehat">
                        <svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>
                        IHS · {{ profilePatient.satusehat_ihs }}
                      </span>
                      <span v-else class="ihs-badge ihs-none" title="Belum punya IHS — di-resolve dari NIK saat sync/backfill">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Belum punya IHS
                      </span>
                      <button
                        v-if="!profilePatient.satusehat_ihs"
                        class="btn btn-secondary btn-sm ihs-resolve-btn"
                        :disabled="resolvingIhs || !profilePatient.nik"
                        :title="profilePatient.nik ? 'Cek NIK ke Satu Sehat sekarang (verifikasi sebelum backfill)' : 'Lengkapi NIK dulu (tombol Edit)'"
                        @click="resolveIhsPasien"
                      >
                        <span v-if="resolvingIhs" class="spin-xs"></span>
                        <svg v-else viewBox="0 0 24 24"><path d="M21 12a9 9 0 11-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
                        {{ resolvingIhs ? 'Mengecek…' : 'Resolve IHS' }}
                      </button>
                    </span>
                    <span v-if="!profilePatient.satusehat_ihs" class="ihs-help">
                      IHS dicari dari NIK ke Satu Sehat. Bila gagal, periksa kembali NIK (provinsi tidak memengaruhi) — perbaiki via tombol Edit.
                    </span>
                  </div>
                  <div class="cf"><span class="cf-k">Tanggal Lahir</span><span class="cf-v">{{ fmtDate(profilePatient.date_of_birth) }}</span></div>
                  <div class="cf"><span class="cf-k">Jenis Kelamin</span><span class="cf-v">{{ profilePatient.gender === 'L' ? 'Laki-laki' : 'Perempuan' }}</span></div>
                  <div class="cf"><span class="cf-k">No. Telepon</span><span class="cf-v">{{ profilePatient.phone || '—' }}</span></div>
                  <div class="cf"><span class="cf-k">No. Telp. Keluarga</span><span class="cf-v">{{ profilePatient.family_phone || '—' }}</span></div>
                  <div class="cf"><span class="cf-k">Golongan Darah</span><span class="cf-v">{{ profilePatient.blood_type || '—' }}</span></div>
                  <div class="cf"><span class="cf-k">Provinsi</span><span class="cf-v">{{ profilePatient.province || '—' }}</span></div>
                  <div class="cf"><span class="cf-k">Kabupaten / Kota</span><span class="cf-v">{{ profilePatient.nama_kab_kota || '—' }}</span></div>
                  <div class="cf"><span class="cf-k">Kecamatan</span><span class="cf-v">{{ profilePatient.nama_kecamatan || '—' }}</span></div>
                  <div class="cf full"><span class="cf-k">Alamat</span><span class="cf-v">{{ profilePatient.address || '—' }}</span></div>
                </div>

                <!-- Dokumen Identitas (KTP) — per-pasien, berkas privat -->
                <div class="id-docs">
                  <div class="id-docs-head">
                    <span class="id-docs-title">
                      <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M14 10h4M14 14h2"/></svg>
                      Dokumen Identitas (KTP)
                    </span>
                    <button class="btn btn-sm btn-secondary" :disabled="identityUploading" @click="pickIdentityFile">
                      <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                      {{ identityUploading ? `Mengunggah… ${identityUploadPct}%` : 'Unggah' }}
                    </button>
                    <input ref="identityFileInput" type="file" accept="image/*,application/pdf" class="id-hidden-file" @change="onIdentityFileSelected" />
                  </div>

                  <div v-if="identityLoading" class="profile-loading"><span class="spin-xs"></span> Memuat dokumen…</div>
                  <div v-else-if="!identityDocs.length" class="id-docs-empty">
                    Belum ada dokumen identitas. Unggah foto/scan KTP (gambar atau PDF, maks 2 MB — gambar besar dikompres otomatis).
                  </div>
                  <div v-else class="id-docs-list">
                    <div v-for="doc in identityDocs" :key="doc.id" class="id-doc">
                      <img v-if="!doc.is_pdf && identityUrls[doc.id]" :src="identityUrls[doc.id]" alt="KTP" class="id-doc-thumb" @click="viewIdentityDoc(doc)" />
                      <div v-else class="id-doc-pdf">PDF</div>
                      <div class="id-doc-meta">
                        <div class="id-doc-name">{{ doc.file_name }}</div>
                        <div class="id-doc-sub">{{ doc.doc_type }} · {{ fmtFileSize(doc.file_size) }}</div>
                      </div>
                      <button class="id-act" title="Lihat" @click="viewIdentityDoc(doc)"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                      <button class="id-act" title="Unduh" @click="downloadIdentityDoc(doc)"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></button>
                      <button class="id-act danger" title="Hapus" @click="confirmDeleteIdentityDoc(doc)"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg></button>
                    </div>
                  </div>
                </div>
              </template>

              <!-- MODE EDIT -->
              <template v-else>
                <div class="detail-edit-grid">
                  <div class="cf"><span class="cf-k">No. Rekam Medis</span><span class="cf-v locked">{{ profilePatient.no_rm ?? '—' }}</span></div>

                  <!-- NIK terkunci begitu IHS terbit; selama belum punya IHS, boleh diperbaiki
                       supaya bisa di-resolve saat sync Satu Sehat. -->
                  <div v-if="profilePatient.satusehat_ihs" class="cf">
                    <span class="cf-k">NIK</span>
                    <span class="cf-v locked">{{ profilePatient.nik || '—' }} <span class="ihs-lock-note">· terkunci (sudah punya IHS)</span></span>
                  </div>
                  <div v-else class="fg-sm">
                    <label>NIK <span class="lbl-note">(perbaiki bila salah — agar IHS bisa resolve saat sync)</span></label>
                    <input v-model="profileEdit.nik" class="form-input" inputmode="numeric" maxlength="16" placeholder="16 digit NIK KTP" />
                    <div v-if="profileEdit.errors?.nik" class="fld-err">{{ profileEdit.errors.nik[0] }}</div>
                  </div>

                  <div class="fg-sm fg-wide">
                    <label>Nama Lengkap <span class="req">*</span></label>
                    <input v-model="profileEdit.name" class="form-input" placeholder="Nama pasien" />
                    <div v-if="profileEdit.errors?.name" class="fld-err">{{ profileEdit.errors.name[0] }}</div>
                  </div>

                  <div class="fg-sm">
                    <label>Tanggal Lahir</label>
                    <input type="date" v-model="profileEdit.date_of_birth" class="form-input" :max="todayStr" />
                    <div v-if="profileEdit.errors?.date_of_birth" class="fld-err">{{ profileEdit.errors.date_of_birth[0] }}</div>
                  </div>
                  <div class="fg-sm">
                    <label>Jenis Kelamin</label>
                    <select v-model="profileEdit.gender" class="form-input">
                      <option value="L">Laki-laki</option>
                      <option value="P">Perempuan</option>
                    </select>
                  </div>

                  <div class="fg-sm">
                    <label>No. Telepon</label>
                    <input v-model="profileEdit.phone" class="form-input" placeholder="08xx-xxxx-xxxx" />
                    <div v-if="profileEdit.errors?.phone" class="fld-err">{{ profileEdit.errors.phone[0] }}</div>
                  </div>
                  <div class="fg-sm">
                    <label>No. Telepon Keluarga</label>
                    <input v-model="profileEdit.family_phone" class="form-input" placeholder="08xx... (wali / darurat)" />
                    <div v-if="profileEdit.errors?.family_phone" class="fld-err">{{ profileEdit.errors.family_phone[0] }}</div>
                  </div>
                  <div class="fg-sm">
                    <label>Email</label>
                    <input v-model="profileEdit.email" type="email" class="form-input" placeholder="nama@email.com" />
                    <div v-if="profileEdit.errors?.email" class="fld-err">{{ profileEdit.errors.email[0] }}</div>
                  </div>
                  <div class="fg-sm">
                    <label>Golongan Darah</label>
                    <select v-model="profileEdit.blood_type" class="form-input">
                      <option value="">—</option>
                      <option value="A">A</option>
                      <option value="B">B</option>
                      <option value="AB">AB</option>
                      <option value="O">O</option>
                    </select>
                  </div>

                  <div class="fg-sm fg-wide">
                    <label>Wilayah <span class="lbl-note">(Provinsi · Kab/Kota · Kecamatan)</span></label>
                    <!-- Data lama tak cocok master → tampilkan nilai sekarang supaya
                         tidak hilang; petugas boleh biarkan atau pilih ulang. -->
                    <div v-if="wilayahNeedsRepick" class="wilayah-keep-note">
                      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                      <span>
                        Data wilayah saat ini:
                        <strong>{{ [profileEdit.origProvince, profileEdit.origKabKota, profileEdit.origKecamatan].filter(Boolean).join(' · ') }}</strong>
                        — tidak cocok dengan master. Biarkan tetap tersimpan, atau pilih ulang dari dropdown di bawah.
                      </span>
                    </div>
                    <WilayahPicker
                      v-model:province="profileEdit.province"
                      v-model:regency="profileEdit.nama_kab_kota"
                      v-model:district="profileEdit.nama_kecamatan"
                      @prefill-status="onWilayahPrefill"
                      @update:province="onWilayahTouched"
                      @update:regency="onWilayahTouched"
                      @update:district="onWilayahTouched"
                    />
                    <div v-if="profileEdit.errors?.province" class="fld-err">{{ profileEdit.errors.province[0] }}</div>
                  </div>

                  <div class="fg-sm fg-wide">
                    <label>Alamat</label>
                    <textarea v-model="profileEdit.address" class="form-input" rows="2" placeholder="Alamat lengkap"></textarea>
                    <div v-if="profileEdit.errors?.address" class="fld-err">{{ profileEdit.errors.address[0] }}</div>
                  </div>
                </div>
                <div class="detail-edit-bar">
                  <button class="btn btn-secondary btn-sm" :disabled="profileEdit.loading" @click="cancelProfileEdit">Batal</button>
                  <button class="btn btn-primary btn-sm" :disabled="profileEdit.loading" @click="saveProfileEdit">
                    {{ profileEdit.loading ? 'Menyimpan…' : 'Simpan Perubahan' }}
                  </button>
                </div>
              </template>
            </template>

            <!-- TAB 2: Riwayat kunjungan — paginated + filter tanggal -->
            <template v-else>
              <div class="riwayat-toolbar">
                <label class="riwayat-date-lbl">Cari tanggal kunjungan</label>
                <input type="date" v-model="riwayatDate" class="form-input compact" @change="loadRiwayat(1)" />
                <button v-if="riwayatDate" class="btn btn-sm btn-secondary" @click="riwayatDate = ''; loadRiwayat(1)">Reset</button>
              </div>

              <div v-if="riwayatLoading" class="profile-loading">
                <span class="spin-xs"></span> Memuat riwayat…
              </div>
              <div v-else-if="!profileVisits.length" class="profile-empty">
                <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/></svg>
                {{ riwayatDate ? 'Tidak ada kunjungan pada tanggal itu' : 'Belum ada riwayat kunjungan' }}
              </div>
              <template v-else>
                <div class="visit-history">
                  <button
                    v-for="(v, i) in profileVisits"
                    :key="v.id"
                    type="button"
                    class="vh-row vh-row-btn"
                    title="Lihat detail kunjungan (edit/update SEP & Surat Kontrol)"
                    @click="openVisitDetailFromRiwayat(v)"
                  >
                    <div class="vh-num">{{ (riwayatMeta.current_page - 1) * RIWAYAT_PER_PAGE + i + 1 }}</div>
                    <PatientAvatar :name="profilePatient.name" :src="v.photo" :size="48" radius="10px" />
                    <div class="vh-body">
                      <div class="vh-top">
                        <span class="vh-date-inline">{{ v.date }}</span>
                        <span class="vh-class">{{ v.classification }}</span>
                        <span :class="['ptype-tag', ptypeClass(v.guarantor)]">{{ v.guarantor }}</span>
                        <span :class="['care-tag', jenisKunjunganClass(v.careType)]">{{ jenisKunjunganLabel(v.careType) }}</span>
                        <span class="vh-station">{{ v.station }}</span>
                      </div>
                      <div class="vh-meta">
                        <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span>{{ v.doctor }}</span>
                        <span v-if="v.poliklinik">· {{ v.poliklinik }}</span>
                      </div>
                      <div v-if="v.insurer || v.noSep" class="vh-meta vh-sub">
                        <span v-if="v.insurer">{{ v.insurer }}</span>
                        <span v-if="v.noSep">· SEP {{ v.noSep }}</span>
                      </div>
                    </div>
                    <svg class="vh-chevron" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                  </button>
                </div>

                <div v-if="riwayatMeta.last_page > 1" class="table-pager">
                  <div class="pager-info">Hal {{ riwayatMeta.current_page }} / {{ riwayatMeta.last_page }} · {{ riwayatMeta.total }} kunjungan</div>
                  <div class="pager-ctrl">
                    <button class="btn btn-sm btn-secondary" :disabled="riwayatMeta.current_page <= 1 || riwayatLoading" @click="loadRiwayat(riwayatMeta.current_page - 1)">
                      <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                      Sebelumnya
                    </button>
                    <button class="btn btn-sm btn-secondary" :disabled="riwayatMeta.current_page >= riwayatMeta.last_page || riwayatLoading" @click="loadRiwayat(riwayatMeta.current_page + 1)">
                      Berikutnya
                      <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                  </div>
                </div>
              </template>
            </template>
          </div>

          <div class="modal-foot">
            <button class="btn btn-secondary" @click="closeProfile">Tutup</button>
            <span class="foot-spacer"></span>
            <button
              class="btn btn-primary"
              :disabled="!profilePatient.id"
              @click="daftarkanDariProfil()"
            >
              <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              {{ pendingWalkIn ? `Daftarkan Walk-In ${pendingWalkIn.queueNo}` : 'Daftarkan Pasien' }}
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- ===================== FOTO PASIEN (kamera/upload) ===================== -->
    <PhotoCaptureModal v-model:open="photoModalOpen" :patient-name="form.name" @captured="onPhotoCaptured" />

    <!-- ===================== i-Care JKN (consent + iframe) ===================== -->
    <IcareModal
      v-model:open="icareOpen"
      :patient-name="icareName"
      :loader="() => admisiApi.bpjs.icareRiwayat(icareVisitId)"
    />

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
.toolbar-title { font-family: 'Space Grotesk', serif; font-size: 22px; color: var(--td); line-height: 1.1; font-weight: 400; }
.toolbar-sub { font-size: 12px; color: var(--tu); margin-top: 4px; }
.toolbar-actions { display: flex; align-items: center; gap: 0.6rem; flex-shrink: 0; }

/* Searchbar lihat data pasien (di kiri tombol Daftarkan) */
.lookup { position: relative; display: flex; align-items: center; }
.lookup-icon { position: absolute; left: 11px; width: 15px; height: 15px; fill: none; stroke: var(--tu); stroke-width: 2; stroke-linecap: round; pointer-events: none; }
.lookup-input {
  width: 280px; height: 40px; padding: 0 32px 0 34px;
  border: 1.5px solid var(--gb); border-radius: 10px;
  background: var(--bc); font-size: 13px; color: var(--td);
  transition: border-color 0.15s, box-shadow 0.15s;
}
.lookup-input::placeholder { color: var(--tu); }
.lookup-input:focus { outline: none; border-color: var(--ga); box-shadow: 0 0 0 3px var(--gl); }
.lookup-spin { position: absolute; right: 11px; }
.lookup-drop { min-width: 320px; }
@media (max-width: 720px) { .lookup-input { width: 180px; } }
/* Alur walk-in lewat lookup: tandai input + banner petunjuk */
.lookup.walkin-mode .lookup-input { border-color: #c08a1a; box-shadow: 0 0 0 3px #fdf3dd; }
.walkin-lookup-hint {
  position: absolute; top: calc(100% + 6px); left: 0; right: 0; z-index: 30;
  display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px;
  padding: 8px 12px; border-radius: 10px;
  background: #fdf6e3; border: 1px solid #f0d9a0; color: #8a6310;
  font-size: 12px; line-height: 1.4; box-shadow: 0 6px 18px rgba(0,0,0,0.08);
}
.walkin-lookup-hint .wlh-actions { margin-left: auto; display: flex; gap: 8px; white-space: nowrap; }
.wlh-link { background: none; border: none; padding: 2px 4px; font-size: 12px; font-weight: 600; color: #8a6310; cursor: pointer; text-decoration: underline; }
.wlh-link.wlh-primary { color: var(--ga); }
.wlh-link:hover { opacity: 0.8; }
.dot-sep { margin: 0 6px; color: var(--th); }
.clock { font-variant-numeric: tabular-nums; font-weight: 600; color: var(--tm); }

/* STATS — kartu lega, sedikit lebih bernapas (selaras gaya Refraksionis/Dokter) */
.stats-row { display: grid; grid-template-columns: repeat(8, 1fr); gap: 0.65rem; }
.stat-card { background: var(--bc); border-radius: 12px; padding: 0.8rem 0.85rem; border: 1px solid var(--gb); display: flex; align-items: center; gap: 11px; min-width: 0; transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s; }
.stat-card:hover { border-color: var(--ga); box-shadow: 0 4px 14px rgba(0,0,0,0.05); transform: translateY(-1px); }
.stat-icon { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-icon svg { width: 18px; height: 18px; fill: none; stroke-width: 2; stroke-linecap: round; }
.stat-val { font-size: 21px; font-weight: 700; color: var(--td); line-height: 1; }
.stat-lbl { font-size: 10px; color: var(--tu); margin-top: 3px; letter-spacing: 0.01em; white-space: nowrap; }

/* MAIN GRID */
.main-grid { display: grid; grid-template-columns: 1fr 340px; gap: 1.25rem; align-items: start; }
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

/* Caret lipat header panel kanan (Aksi Cepat / Status BPJS / Antrean) */
.head-right { display: inline-flex; align-items: center; gap: 8px; }
.head-caret { width: 16px; height: 16px; fill: none; stroke: var(--tu); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; transform: rotate(-90deg); transition: transform 0.2s ease; flex-shrink: 0; }
.head-caret.open { transform: rotate(0deg); }

/* COLLAPSE BUTTON */
.collapse-btn {
  display: inline-flex; align-items: center; gap: 6px;
  background: transparent; border: 1.5px solid var(--gb);
  border-radius: 8px; padding: 6px 12px;
  font-family: 'Inter', sans-serif; font-size: 12px; font-weight: 600;
  color: var(--tm); cursor: pointer; transition: all 0.15s;
}
.collapse-btn:hover { border-color: var(--ga); color: var(--td); }
.collapse-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; transition: transform 0.25s; }
.collapse-btn.open svg { transform: rotate(180deg); }
.collapse-enter-active, .collapse-leave-active { transition: all 0.25s ease; overflow: hidden; }
.collapse-enter-from, .collapse-leave-to { opacity: 0; max-height: 0; }
.collapse-enter-to, .collapse-leave-from { opacity: 1; max-height: 2000px; }

/* TABLE TOOLBAR */
.table-toolbar { display: flex; gap: 0.5rem; align-items: center; padding: 0.85rem 1.25rem; border-bottom: 1px solid var(--gb); flex-wrap: wrap; }
/* Checkbox COB di modal Edit Penjamin */
.cob-check { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: var(--td); cursor: pointer; }
.cob-check input { width: 15px; height: 15px; cursor: pointer; }
/* Toggle Rawat Jalan vs Rawat Inap (segmented) */
.care-toggle { display: inline-flex; padding: 2px; border: 1px solid var(--gb); border-radius: 8px; background: var(--bi); gap: 2px; }
.care-seg { padding: 0.32rem 0.75rem; border: none; border-radius: 6px; background: transparent; color: var(--tm); font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.15s; white-space: nowrap; }
.care-seg:hover { color: #1763d4; }
.care-seg.on { background: #1763d4; color: #fff !important; box-shadow: 0 1px 2px rgba(23,99,212,0.3); }

/* CALLABLE QUEUE */
/* Kartu walk-in: aksen menonjol karena kemunculannya = ada aksi yang perlu
   ditangani petugas (pasien baru ambil tiket di Anjungan Mandiri). */
.call-card { border-color: var(--ga); box-shadow: 0 0 0 1px var(--gl), 0 10px 30px rgba(43,124,176,0.10); }
/* Transisi muncul/hilang dinamis (slide + fade) saat callableQueue berubah. */
.callcard-enter-active { transition: opacity 0.35s ease, transform 0.35s cubic-bezier(0.22,1,0.36,1); }
.callcard-leave-active { transition: opacity 0.22s ease, transform 0.22s ease; }
.callcard-enter-from { opacity: 0; transform: translateY(-12px) scale(0.99); }
.callcard-leave-to   { opacity: 0; transform: translateY(-8px) scale(0.99); }
.call-list { display: flex; flex-direction: column; }
.call-row { display: flex; align-items: center; gap: 14px; padding: 14px 1.25rem; border-bottom: 1px solid rgba(0,0,0,0.04); transition: background 0.15s; }
.call-row:last-child { border-bottom: none; }
.call-row:hover { background: var(--bi); }
.call-qno { font-family: 'Space Grotesk', serif; font-size: 24px; color: var(--ga); min-width: 64px; letter-spacing: 0.02em; }
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
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 0 16px; height: 36px; border-radius: 9px; font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.15s; border: 1.5px solid transparent; }
.btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover { background: var(--gm); }
.btn-success { background: var(--ga); color: #fff; border-color: var(--ga); }
.btn-success:hover { background: var(--gm); }
.btn-secondary { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-secondary:hover { border-color: var(--ga); color: var(--td); background: var(--gl); }
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
.btn-gc-signed { background: var(--gl); color: var(--td); border-color: rgba(31,125,74,.3); opacity: .6; cursor: not-allowed; }
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

/* Pager tabel kunjungan (server-side) */
.table-pager { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 0.7rem 1rem; border-top: 1px solid var(--gb); flex-wrap: wrap; }
.pager-info { font-size: 12px; color: var(--tu); font-variant-numeric: tabular-nums; }
.pager-ctrl { display: flex; align-items: center; gap: 8px; }
.pager-page { font-size: 12px; font-weight: 600; color: var(--td); font-variant-numeric: tabular-nums; min-width: 64px; text-align: center; }
.table-pager .btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
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

/* Jenis kunjungan: Rawat Jalan / Rawat Inap / IGD */
.care-tag { font-size: 9.5px; font-weight: 600; padding: 2px 7px; border-radius: 4px; white-space: nowrap; }
.care-rajal { background: #dcfce7; color: #166534; }
.care-ranap { background: #e0e7ff; color: #3730a3; }
.care-igd { background: #fee2e2; color: #991b1b; }

/* Verifikasi asuransi badge (Sprint 4 modul Asuransi/TPA) */
.verif-badge { display: inline-flex; align-items: center; gap: 3px; font-size: 9.5px; font-weight: 600; padding: 2px 7px; border-radius: 12px; margin-left: 4px; }
.verif-badge.vb-pending  { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
.verif-badge.vb-verified { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
.verif-badge.vb-issue    { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.sep-badge { display: inline-flex; align-items: center; font-size: 9.5px; font-weight: 700; letter-spacing: .03em; padding: 2px 7px; border-radius: 12px; margin-left: 4px; background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
.dashboard-err { display: flex; align-items: center; gap: 8px; margin-bottom: 0.65rem; padding: 8px 12px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px; color: #9a3412; font-size: 13px; }
.dashboard-err svg { stroke: #ea580c; fill: none; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.dashboard-err span { flex: 1; }

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
/* Baris antrean masuk/keluar dinamis saat queue berubah (realtime). */
.liverow-enter-active { transition: opacity 0.3s ease, transform 0.3s cubic-bezier(0.22,1,0.36,1); }
.liverow-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; position: absolute; }
.liverow-enter-from { opacity: 0; transform: translateX(14px); }
.liverow-leave-to   { opacity: 0; transform: translateX(-14px); }
.liverow-move { transition: transform 0.3s ease; }
.live-empty { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 18px 8px; color: var(--tu); font-size: 11.5px; text-align: center; }
.live-empty svg { width: 22px; height: 22px; fill: none; stroke: var(--gb); stroke-width: 2; stroke-linecap: round; }

/* FORM CONTROLS */
.form-select, .form-input { background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; font-family: 'Inter', sans-serif; font-size: 13px; color: var(--td); outline: none; transition: border-color 0.15s, box-shadow 0.15s, background 0.15s; padding: 9px 11px; width: 100%; }
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
.combo-item.active { background: var(--gl); color: var(--td); font-weight: 600; }

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
.modal-title { font-family: 'Space Grotesk', serif; font-size: 19px; color: var(--td); }
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
.step.active .step-label { color: var(--td); }
.step.done .step-label { color: var(--td); }
.step-line { flex: 1; height: 2px; background: var(--gb); margin: 0 14px; }
.step.done .step-line { background: var(--ga); }

/* MODAL BODY / FORM GRID */
.modal-body { padding: 1.5rem; overflow-y: auto; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem 1.1rem; }
.field { display: flex; flex-direction: column; gap: 5px; }
.field.full, .full { grid-column: 1 / -1; }
.field-lbl { font-size: 11.5px; font-weight: 600; color: var(--tm); }
.field-opt { font-weight: 400; color: var(--th); font-size: 10px; }

/* Foto pasien — field di wizard Step 1 */
.photo-field { display: flex; align-items: center; gap: 14px; }
.photo-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.photo-hint { font-size: 11px; color: var(--tu); flex-basis: 100%; }

/* Header section konfirmasi dengan avatar */
.confirm-sec-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.confirm-photo { display: flex; align-items: center; gap: 10px; }
.confirm-photo-note { font-size: 11px; color: var(--ga); margin-top: 3px; font-weight: 500; }

/* Detail Kunjungan — nama pasien klik + section SEP/SKDP */
.vd-name-link { display: inline-flex; align-items: center; gap: 6px; background: none; border: none; padding: 0; cursor: pointer; font-size: 13px; font-weight: 600; color: var(--ga); }
.vd-name-link:hover:not(:disabled) { text-decoration: underline; }
.vd-name-link:disabled { color: var(--td); cursor: default; font-weight: 500; }
.vd-name-link svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.vd-insurer { font-size: 12px; color: var(--tu); }
.vd-section { margin-top: 1rem; padding: 0.85rem 1rem; border: 1px solid var(--gb); border-radius: 12px; background: var(--bi); }
.vd-rujuk-banner { margin-top: 1rem; padding: 0.6rem 0.85rem; border-radius: 9px; background: #cffafe; border: 1px solid #a5f3fc; color: #0e7490; font-size: 12.5px; }
.vd-rujuk-banner b { color: #0e7490; }
.vd-section-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.vd-section-title { font-size: 11px; font-weight: 700; color: var(--tm); text-transform: uppercase; letter-spacing: 0.03em; }
.vd-section-val { font-size: 14px; font-weight: 600; color: var(--td); margin-top: 3px; font-variant-numeric: tabular-nums; }
.vd-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.vd-edit-sk { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 10px; }
.vd-diag { margin-top: 10px; padding: 0.6rem 0.7rem; border: 1px solid var(--gb); border-radius: 9px; background: var(--bs); }
.vd-diag-k { font-size: 11px; font-weight: 700; color: var(--tm); text-transform: uppercase; letter-spacing: 0.03em; }
.vd-diag-v { font-size: 13.5px; font-weight: 600; color: var(--td); margin-top: 2px; }
.vd-diag-v.empty { color: #b45309; font-weight: 600; }
.vd-diag-actions { margin-top: 8px; }
.vd-diag-edit { margin-top: 10px; }
.vd-sep-edit { margin-top: 10px; padding: 0.7rem; border: 1px dashed var(--gb); border-radius: 9px; background: var(--bs); }
.vd-sep-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.fg-sm { display: flex; flex-direction: column; gap: 3px; }
.fg-sm label { font-size: 10.5px; font-weight: 600; color: var(--td); }
.fg-sm.fg-wide { grid-column: 1 / -1; }
.section-label { font-size: 11px; font-weight: 700; color: var(--td); text-transform: uppercase; letter-spacing: 0.05em; padding-top: 0.4rem; border-top: 1px solid var(--gb); margin-top: 0.2rem; }
.section-label:first-child { border-top: none; padding-top: 0; margin-top: 0; }
.lbl-note { font-weight: 500; color: var(--tu); text-transform: none; letter-spacing: 0; font-size: 10px; }
.hint { font-size: 10.5px; color: var(--tu); margin-top: 2px; }
.hint code { background: var(--bi); padding: 1px 5px; border-radius: 4px; font-size: 10px; color: var(--ga); font-weight: 600; }

/* Cek eligibilitas BPJS inline (Cek Peserta / Cek Rujukan) */
.inline-check { display: flex; gap: 6px; align-items: stretch; }
.inline-check .form-input { flex: 1; }
.btn-check { padding: 0 12px; border-radius: 7px; border: 1px solid #1763d4; background: #fff; color: #1763d4; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.btn-check:disabled { opacity: 0.55; cursor: not-allowed; }
.check-msg { font-size: 11.5px; margin-top: 4px; padding: 5px 8px; border-radius: 6px; line-height: 1.4; }
.check-msg.err { background: #fef3c7; color: #92400e; }
.check-msg.ok { background: #dcfce7; color: #166534; }
.check-msg .st-ok { color: #166534; font-weight: 600; }
.check-msg .st-warn { color: #991b1b; font-weight: 600; }

/* Tarik data BPJS (rujukan + surat kontrol by kartu/NIK) */
.bpjs-pull { border: 1px dashed #93c5fd; background: #f0f7ff; border-radius: 9px; padding: 10px 12px; margin-bottom: 10px; }
.bpjs-pull-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.bpjs-pull-title { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: #1763d4; }
.bpjs-pull-title svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
.pull-group { margin-top: 8px; }
.pull-group-lbl { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--tu); margin-bottom: 4px; }
.pull-count { display: inline-block; min-width: 16px; text-align: center; background: #1763d4; color: #fff; border-radius: 999px; padding: 0 5px; font-size: 9.5px; }
.pull-empty { font-size: 11px; color: var(--tu); padding: 4px 2px; }
.pull-item { display: flex; flex-direction: column; gap: 1px; width: 100%; text-align: left; border: 1px solid #d6e4f5; background: #fff; border-radius: 7px; padding: 6px 9px; margin-bottom: 5px; cursor: pointer; transition: border-color .12s, background .12s; }
.pull-item:hover { border-color: #1763d4; background: #f7fbff; }
.pull-item.picked { border-color: #1763d4; background: #e8f1ff; box-shadow: inset 0 0 0 1px #1763d4; }
.pull-item-no { font-size: 12.5px; font-weight: 600; color: var(--td); }
.pull-tag { display: inline-block; margin-left: 4px; font-size: 9px; font-weight: 700; color: #1763d4; background: #dbeafe; border-radius: 4px; padding: 0 5px; vertical-align: middle; }
.pull-item-meta { font-size: 10.5px; color: var(--tu); }

/* Aksi Cepat — trigger button (search bar default hidden) */
.qa-trigger { justify-content: flex-start; gap: 8px; }
.qa-trigger .qa-caret { margin-left: auto; width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; transition: transform 0.18s; }
.qa-trigger .qa-caret.open { transform: rotate(180deg); }
.qa-trigger-on { background: var(--gl); color: var(--td); border: 1px solid var(--ga); }

/* Aksi Cepat — panel cek (rujukan / status BPJS) */
.qa-rujukan { display: flex; flex-direction: column; gap: 8px; padding: 2px 2px 6px; }
.qa-lbl { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 700; color: var(--tm); text-transform: uppercase; letter-spacing: 0.03em; }
.qa-lbl svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.seg-toggle.mini { padding: 3px; gap: 4px; }
.seg-toggle.mini .seg { height: 30px; font-size: 11.5px; }
.qa-rujukan-res { display: flex; flex-direction: column; gap: 5px; padding: 9px 11px; border: 1px solid #bbf7d0; background: #f0fdf4; border-radius: 9px; }
.qa-res-row { display: flex; gap: 8px; font-size: 11.5px; line-height: 1.35; }
.qa-res-k { flex-shrink: 0; width: 92px; color: var(--tu); font-weight: 600; }
.qa-res-v { color: var(--td); font-weight: 500; word-break: break-word; }
.qa-res-v.st-ok { color: #166534; font-weight: 700; }
.qa-res-v.st-warn { color: #991b1b; font-weight: 700; }
.qa-use-btn { margin-top: 4px; }

/* SEGMENT TOGGLE */
.seg-toggle { display: flex; gap: 6px; background: var(--bi); padding: 4px; border-radius: 11px; }
.seg-toggle.sub { background: transparent; padding: 0; gap: 8px; }
.seg { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; height: 38px; border-radius: 8px; border: 1.5px solid transparent; background: transparent; font-family: 'Inter', sans-serif; font-size: 12.5px; font-weight: 600; color: var(--tu); cursor: pointer; transition: all 0.15s; }
.seg svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.seg:hover { color: var(--td); }
.seg-toggle.sub .seg { background: var(--bi); border-color: var(--gb); }
.seg-on { background: var(--bc); color: var(--td); box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.seg-toggle.sub .seg-on { background: var(--gl); border-color: var(--ga); color: var(--td); }

/* BANNERS / INFO */
.found-banner { display: flex; align-items: center; gap: 8px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 9px; padding: 10px 13px; font-size: 12px; font-weight: 500; }
.found-banner svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }

/* Banner kunjungan aktif — merah peringatan (registrasi akan ditolak) */
.active-visit-banner { display: flex; align-items: flex-start; gap: 10px; background: #fef2f2; border: 1.5px solid #ef4444; border-radius: 10px; padding: 11px 14px; color: #000; }
.active-visit-banner svg { fill: none; stroke: #dc2626; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; margin-top: 1px; }
.active-visit-banner .avb-body { display: flex; flex-direction: column; gap: 3px; }
.active-visit-banner strong { font-size: 13px; color: #991b1b; }
.active-visit-banner .avb-meta { font-size: 12px; color: #000; }
.active-visit-banner .avb-meta b { color: #991b1b; }
.active-visit-banner .avb-note { font-size: 11.5px; color: #7f1d1d; }
.active-visit-banner .avb-action { align-self: flex-start; margin-top: 6px; padding: 5px 12px; font-size: 11.5px; font-weight: 600; color: #fff; background: #dc2626; border: none; border-radius: 7px; cursor: pointer; }
.active-visit-banner .avb-action:hover { background: #b91c1c; }
/* badge kecil di dropdown hasil cari */
.combo-av-badge { display: inline-block; margin-left: 6px; font-size: 10px; font-weight: 600; color: #b91c1c; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 999px; padding: 1px 7px; vertical-align: middle; }

/* Status IHS Satu Sehat — chip kecil di hasil cari + badge di profil */
.ihs-chip { display: inline-block; margin-left: 4px; font-size: 9.5px; font-weight: 700; border-radius: 999px; padding: 1px 7px; vertical-align: middle; }
.ihs-chip.ihs-ok   { color: #047857; background: #d1fae5; border: 1px solid #6ee7b7; }
.ihs-chip.ihs-none { color: #b45309; background: #fef3c7; border: 1px solid #fcd34d; }
.ihs-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 600; border-radius: 6px; padding: 3px 9px; }
.ihs-badge svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
.ihs-badge.ihs-ok   { color: #047857; background: #d1fae5; border: 1px solid #6ee7b7; }
.ihs-badge.ihs-none { color: #b45309; background: #fef3c7; border: 1px solid #fcd34d; }
.ihs-lock-note { font-size: 10px; font-weight: 500; color: var(--tu); }
.ihs-cf-v { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ihs-resolve-btn { white-space: nowrap; }
.ihs-resolve-btn svg { width: 13px; height: 13px; }
.ihs-nik { font-size: 13px; font-weight: 600; color: var(--td); letter-spacing: 0.02em; }
.ihs-help { font-size: 10.5px; font-weight: 500; color: var(--tu); margin-top: 4px; line-height: 1.4; }

/* Banner Preop Bedah — hijau (hari ini) / kuning (hari lain) */
.preop-banner { display: flex; flex-direction: column; gap: 8px; border-radius: 10px; padding: 12px 14px; font-size: 12.5px; border: 1.5px solid; }
.preop-banner.preop-today { background: #ecfdf5; color: #065f46; border-color: #10b981; }
.preop-banner.preop-upcoming { background: #fffbeb; color: #92400e; border-color: #f59e0b; }
.preop-banner-head { display: flex; align-items: center; gap: 8px; font-size: 13.5px; }
.preop-banner-head svg { flex-shrink: 0; }
.preop-banner-body { line-height: 1.6; }
.preop-detail { font-size: 12px; }
.preop-question { margin-top: 4px; font-weight: 600; }
.preop-question em { font-weight: 500; font-style: italic; opacity: 0.85; }
.preop-banner-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 4px; }
.preop-badge-confirm { display: inline-block; padding: 2px 8px; border-radius: 6px; background: #fef3c7; color: #92400e; font-weight: 700; font-size: 10.5px; letter-spacing: 0.5px; border: 1px solid #fbbf24; }
.preop-shift-note { font-style: italic; opacity: 0.75; font-size: 11px; }
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
.confirm-sec-title { font-size: 12px; font-weight: 700; color: var(--td); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.85rem; }
.confirm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.7rem 1.1rem; }
.cf { display: flex; flex-direction: column; gap: 2px; }
.cf.full { grid-column: 1 / -1; }
.cf-k { font-size: 10.5px; color: var(--tu); text-transform: uppercase; letter-spacing: 0.03em; }
.cf-v { font-size: 13px; font-weight: 500; color: var(--td); }

/* PRE-FLIGHT KESIAPAN SEP (langkah Konfirmasi) */
.pf-card { border: 1px solid var(--gb); border-radius: 11px; padding: 1rem 1.1rem; }
.pf-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.7rem; }
.pf-recheck { font-size: 11.5px; font-weight: 600; color: var(--td); background: #f1f5f9; border: 1px solid var(--gb); border-radius: 7px; padding: 4px 10px; cursor: pointer; }
.pf-recheck:disabled { opacity: 0.55; cursor: default; }
.pf-loading { font-size: 12.5px; color: var(--tu); padding: 0.4rem 0; }
.pf-banner { font-size: 12.5px; border-radius: 8px; padding: 0.55rem 0.75rem; margin-bottom: 0.6rem; }
.pf-banner.pf-ok    { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.pf-banner.pf-block { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.pf-banner.pf-warn  { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.pf-list { margin: 0 0 0.6rem; padding-left: 1.1rem; font-size: 12.5px; line-height: 1.5; }
.pf-list-block li { color: #991b1b; }
.pf-list-warn li { color: #92400e; }
.pf-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.7rem 1.1rem; }
.pf-pill { display: inline-block; font-size: 10.5px; font-weight: 700; border-radius: 6px; padding: 1px 7px; margin-left: 4px; }
.pf-pill-ok  { background: #d1fae5; color: #065f46; }
.pf-pill-bad { background: #fee2e2; color: #991b1b; }

/* DETAIL MODAL */
.detail-hero { display: flex; align-items: center; gap: 14px; padding-bottom: 1.1rem; border-bottom: 1px solid var(--gb); margin-bottom: 1.1rem; }
/* Badge penjamin + tombol Edit di ujung kanan hero, sejajar nama */
.detail-hero-right { display: flex; align-items: center; gap: 10px; margin-left: auto; }
.detail-avatar { width: 46px; height: 46px; border-radius: 12px; background: var(--gl); color: var(--td); display: flex; align-items: center; justify-content: center; font-family: 'Space Grotesk', serif; font-size: 20px; flex-shrink: 0; }
.detail-name { font-size: 16px; font-weight: 600; color: var(--td); }
.detail-sub { font-size: 12px; color: var(--tu); margin-top: 2px; }

/* Profil Pasien — tabs */
.profile-tabs { display: flex; gap: 4px; padding: 0 1.5rem; border-bottom: 1px solid var(--gb); }
.profile-tab {
  position: relative; background: none; border: none; cursor: pointer;
  padding: 12px 4px; margin-right: 18px; font-size: 13px; font-weight: 500;
  color: var(--tu); display: inline-flex; align-items: center; gap: 6px;
  transition: color 0.15s;
}
.profile-tab:hover { color: var(--td); }
.profile-tab.active { color: var(--td); font-weight: 600; }
.profile-tab.active::after { content: ''; position: absolute; left: 0; right: 0; bottom: -1px; height: 2px; background: var(--ga); border-radius: 2px; }
.profile-tab-count { font-size: 10px; font-weight: 600; padding: 1px 6px; border-radius: 10px; background: var(--gl); color: var(--ga); }
.profile-loading { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 2rem; font-size: 13px; color: var(--tu); }
.profile-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 2.5rem 1rem; font-size: 13px; color: var(--tu); }
.profile-empty svg { width: 34px; height: 34px; fill: none; stroke: var(--gb); stroke-width: 1.6; stroke-linecap: round; }

/* Profil Pasien — riwayat kunjungan */
.riwayat-toolbar { display: flex; align-items: center; gap: 8px; margin-bottom: 0.9rem; flex-wrap: wrap; }
.riwayat-date-lbl { font-size: 11.5px; font-weight: 600; color: var(--tm); }
.riwayat-toolbar .form-input.compact { width: auto; }
.visit-history { display: flex; flex-direction: column; gap: 8px; }
.vh-row { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1px solid var(--gb); border-radius: 12px; background: var(--bc); transition: border-color 0.15s; }
.vh-row:hover { border-color: var(--ga); }
/* Baris riwayat sebagai tombol → buka Detail Kunjungan */
.vh-row-btn { width: 100%; text-align: left; cursor: pointer; font: inherit; }
.vh-row-btn:hover { border-color: var(--ga); background: var(--bi); }
.vh-row-btn:active { transform: translateY(1px); }
.vh-chevron { flex-shrink: 0; width: 16px; height: 16px; fill: none; stroke: var(--gb); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; transition: stroke 0.15s, transform 0.15s; }
.vh-row-btn:hover .vh-chevron { stroke: var(--ga); transform: translateX(2px); }
.vh-num { flex-shrink: 0; width: 20px; font-size: 12px; font-weight: 700; color: var(--tu); font-variant-numeric: tabular-nums; text-align: right; }
.vh-date, .vh-date-inline { font-size: 12px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; }
.vh-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 5px; }
.vh-top { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
.vh-class { font-size: 12.5px; font-weight: 600; color: var(--td); }
.vh-station { font-size: 10px; font-weight: 600; padding: 2px 7px; border-radius: 4px; background: var(--bi); color: var(--tm); }
.vh-meta { display: flex; align-items: center; gap: 5px; font-size: 11.5px; color: var(--tu); }
.vh-meta svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.vh-meta.vh-sub { font-variant-numeric: tabular-nums; }
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.9rem 1.1rem; }

/* Detail Pasien — edit/update */
.detail-edit-bar { display: flex; justify-content: flex-end; gap: 8px; margin-top: 1.1rem; padding-top: 0.9rem; border-top: 1px solid var(--gb); }
.detail-edit-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.7rem 1rem; }
.detail-edit-grid .fg-sm label { text-transform: uppercase; letter-spacing: 0.03em; color: var(--tm); }
.detail-edit-grid .form-input { width: 100%; }
.detail-edit-grid textarea.form-input { resize: vertical; min-height: 44px; }
.cf-v.locked { color: var(--tu); font-weight: 500; }
.req { color: #dc2626; font-weight: 700; }
.fld-err { font-size: 10.5px; color: #dc2626; margin-top: 2px; }
/* Catatan "data wilayah lama tak cocok master" di mode edit detail pasien */
.wilayah-keep-note { display: flex; gap: 8px; align-items: flex-start; padding: 8px 10px; margin-bottom: 8px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; font-size: 11.5px; color: #92400e; line-height: 1.45; }
.wilayah-keep-note svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; fill: none; stroke: #d97706; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.wilayah-keep-note strong { color: #78350f; }
@media (max-width: 560px) { .detail-edit-grid { grid-template-columns: 1fr; } }

/* MODAL FOOTER */
.modal-foot { display: flex; align-items: center; gap: 0.6rem; padding: 1.1rem 1.5rem; border-top: 1px solid var(--gb); background: var(--bi); }
.foot-spacer { flex: 1; }

/* MODAL TRANSITION */
.modal-fade-enter-active, .modal-fade-leave-active { transition: opacity 0.2s ease; }
.modal-fade-enter-from, .modal-fade-leave-to { opacity: 0; }
.modal-fade-enter-active .modal-shell { animation: modalPop 0.25s ease; }
@keyframes modalPop { from { opacity: 0; transform: scale(0.96) translateY(8px); } to { opacity: 1; transform: scale(1) translateY(0); } }

/* TOAST */
.sep-error-inline { display: flex; align-items: flex-start; gap: 0.5rem; margin-top: 0.6rem; padding: 0.6rem 0.75rem; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #b91c1c; font-size: 12px; line-height: 1.45; }
.sep-error-inline svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; fill: none; stroke: currentColor; stroke-width: 2; }
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
  .form-grid, .confirm-grid, .detail-grid, .pf-grid { grid-template-columns: 1fr; }
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
  font-family: 'Inter', sans-serif; font-size: 11.5px; font-weight: 600;
  color: var(--tu); cursor: pointer; transition: all 0.15s;
}
.guarantor-opt:hover { border-color: var(--ga); color: var(--td); background: var(--gl); }
.guarantor-opt svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.g-on { background: var(--gl); border-color: var(--ga); color: var(--td); box-shadow: 0 1px 4px rgba(0,0,0,0.08); }

/* CLASSIFICATION BADGE PICKER */
.classif-badges { display: flex; gap: 6px; flex-wrap: wrap; }
.classif-badge {
  padding: 5px 14px; border-radius: 20px; font-size: 12px; font-weight: 600;
  border: 1.5px solid var(--gb); background: var(--bs); color: var(--tu);
  cursor: pointer; transition: all 0.15s; font-family: 'Inter', sans-serif;
}
.classif-badge:hover { border-color: var(--ga); color: var(--td); }
.classif-on { background: var(--gl); border-color: var(--ga); color: var(--td); }
.classif-on-sm { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; background: var(--gl); border: 1px solid var(--ga); color: var(--td); }

/* Badge hak konsultasi kontrol gratis pasca-bedah */
.followup-badge { margin-top: 8px; padding: 8px 12px; border-radius: 10px; background: var(--gl); border: 1px solid var(--ga); font-size: 12px; color: var(--td); display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.followup-chip { display: inline-block; padding: 2px 8px; border-radius: 12px; background: var(--bs); border: 1px solid var(--gb); font-size: 11px; color: var(--tu); }
.followup-badge .hint { flex-basis: 100%; margin-top: 0; }

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

/* ============================================================
   GENERAL CONSENT — card di step 3 + modal tinjau/TTD
   ============================================================ */
.gc-card {
  display: flex; align-items: center; gap: 14px;
  background: #f4f8ff; border: 1px solid #c7dbff; border-radius: 10px;
  padding: 14px 16px; margin-bottom: 12px;
}
.gc-card-icon {
  flex: 0 0 auto; width: 40px; height: 40px; border-radius: 9px;
  background: #1763d4; display: flex; align-items: center; justify-content: center;
}
.gc-card-icon svg { width: 22px; height: 22px; stroke: #fff; fill: none; stroke-width: 2; }
.gc-card-main { flex: 1 1 auto; min-width: 0; }
.gc-card-title { font-weight: 700; font-size: 14px; color: #000; }
.gc-card-sub   { font-size: 12px; color: #333; margin: 2px 0 6px; }
.gc-card-sub em { color: #555; font-style: italic; }
.gc-badge {
  display: inline-block; font-size: 11.5px; font-weight: 700;
  padding: 3px 9px; border-radius: 999px;
}
.gc-badge-ok   { background: #e3f6e8; color: #11703a; border: 1px solid #9ad9b1; }
.gc-badge-warn { background: #fff4e0; color: #95620a; border: 1px solid #f0cf9a; }
.gc-card-btn {
  flex: 0 0 auto; background: #1763d4; color: #fff !important; border: 0;
  padding: 9px 16px; border-radius: 8px; font-weight: 700; font-size: 13px;
  cursor: pointer; transition: filter .12s, transform .08s;
}
.gc-card-btn:hover  { filter: brightness(1.08); }
.gc-card-btn:active { transform: translateY(1px); }

.gc-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,.55);
  display: flex; align-items: center; justify-content: center; z-index: 1050;
}
.gc-modal {
  width: min(760px, 96vw); max-height: 92vh; background: #fff;
  border-radius: 12px; display: flex; flex-direction: column; overflow: hidden;
}
.gc-modal-head {
  display: flex; justify-content: space-between; align-items: flex-start;
  padding: 16px 20px; border-bottom: 1px solid #e4e8ee;
}
.gc-modal-head h3 { margin: 0; font-size: 17px; color: #000; }
.gc-modal-sub { margin: 4px 0 0; font-size: 12.5px; color: #555; }
.gc-modal-x { background: 0; border: 0; font-size: 24px; line-height: 1; cursor: pointer; color: #777; }
.gc-modal-body { flex: 1 1 auto; overflow-y: auto; padding: 18px 20px; background: #f6f7f9; }
.gc-modal-loading, .gc-modal-error { text-align: center; color: #555; padding: 40px 0; font-size: 13.5px; }
.gc-modal-error { color: #b3261e; }
.gc-doc {
  background: #fff; border: 1px solid #e0e0e0; border-radius: 6px;
  padding: 8px 14px; color: #000; font-size: 13px;
}
.gc-doc :deep(table) { width: 100%; }
.gc-modal-foot {
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  padding: 14px 20px; border-top: 1px solid #e4e8ee;
}
.gc-sign-actions { display: flex; gap: 8px; flex-wrap: wrap; flex: 1 1 auto; }
.gc-sign-btn {
  display: inline-flex; align-items: center; gap: 5px;
  background: #fff; border: 1.5px solid #1763d4; color: #1763d4;
  padding: 8px 14px; border-radius: 8px; font-weight: 700; font-size: 12.5px; cursor: pointer;
}
.gc-sign-btn:hover { background: #eef4ff; }
.gc-sign-btn-done { background: #e3f6e8; border-color: #2c9c5a; color: #11703a; }
.gc-sign-check { font-weight: 800; }
.gc-done-btn {
  background: #1763d4; color: #fff !important; border: 0; padding: 9px 20px;
  border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer;
}
.gc-done-btn:hover { filter: brightness(1.08); }
</style>

<style scoped>
/* ─── Dokumen Identitas (KTP) di modal Profil Pasien ─── */
.id-docs { margin-top: 1rem; border-top: 1px solid #e5e7eb; padding-top: .85rem; }
.id-docs-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin-bottom: .6rem; }
.id-docs-title { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: var(--td); }
.id-docs-title svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.id-hidden-file { display: none; }
.id-docs-empty { font-size: 11.5px; color: var(--tu); padding: .4rem 0; line-height: 1.5; }
.id-docs-list { display: flex; flex-direction: column; gap: .5rem; }
.id-doc { display: flex; align-items: center; gap: .6rem; padding: .45rem .55rem; border: 1px solid #e5e7eb; border-radius: 10px; }
.id-doc-thumb { width: 46px; height: 32px; object-fit: cover; border-radius: 6px; cursor: pointer; flex-shrink: 0; }
.id-doc-pdf { width: 46px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; background: rgba(220,38,38,.08); color: #dc2626; font-size: 9px; font-weight: 700; flex-shrink: 0; }
.id-doc-meta { flex: 1; min-width: 0; }
.id-doc-name { font-size: 11.5px; font-weight: 600; color: var(--td); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.id-doc-sub { font-size: 10px; color: var(--tu); }
.id-act { width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #e5e7eb; background: #fff; border-radius: 7px; cursor: pointer; color: var(--td); flex-shrink: 0; }
.id-act:hover { background: #f3f4f6; }
.id-act svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.id-act.danger { color: #dc2626; }
.id-act.danger:hover { background: rgba(220,38,38,.08); }
</style>
