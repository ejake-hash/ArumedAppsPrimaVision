<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { usePerawatStore } from '@/stores/perawatStore'
import { useAuthStore } from '@/stores/authStore'
import { perawatApi } from '@/services/api'
import PatientAvatar from '@/components/common/PatientAvatar.vue'
import CpptHistoryCard from '@/components/common/CpptHistoryCard.vue'
import UnitStockActions from '@/components/inventori-farmasi/UnitStockActions.vue'

const store = usePerawatStore()

// ─── Local UI state ─────────────────────────────────────────────────────────
const qPrimaryFilter   = ref('waiting')     // 'waiting' | 'done'
const qSecondaryFilter = ref('semua')        // 'semua' | 'bpjs' | 'umum'
const qSearch          = ref('')
const historyExpanded  = ref(false)
const rekamMedisOpen   = ref(false)
const viewingDokumen   = ref(false)

// ── Layout dinamis: panel collapsible + mode fokus ──────────────────────────
const QKEY = 'perawat.queueCollapsed'
const SKEY = 'perawat.sideCollapsed'
const queueCollapsed = ref(localStorage.getItem(QKEY) === '1')
const sideCollapsed  = ref(localStorage.getItem(SKEY) === '1')
function toggleQueue() { queueCollapsed.value = !queueCollapsed.value; localStorage.setItem(QKEY, queueCollapsed.value ? '1' : '0') }
function toggleSide()  { sideCollapsed.value = !sideCollapsed.value; localStorage.setItem(SKEY, sideCollapsed.value ? '1' : '0') }

// Hasil refraksi (visus/IOP) pasien terpilih — tampil di kartu data pasien bila ada.
const rfx = computed(() => store.selectedQueue?.refraksi ?? null)
const hasRefraksi = computed(() => {
  const r = rfx.value
  return !!r && (r.visus_od || r.visus_os || r.iop_od != null || r.iop_os != null)
})
// Mode fokus: saat pasien dipilih di layar sedang (≤1500px), ciutkan antrean agar form lega.
watch(() => store.selectedQueue, (q, prev) => {
  if (q && !prev && typeof window !== 'undefined' && window.matchMedia('(max-width: 1500px)').matches) {
    queueCollapsed.value = true
    localStorage.setItem(QKEY, '1')
  }
})
const toasts           = ref([])
let   _tid             = 0

// ─── CPPT mode ──────────────────────────────────────────────────────────────
// 'idle'        : tampil data asesmen awal (form locked kalau finalized)
// 'new'         : form unlock untuk tambah CPPT entry baru
// 'edit:<id>'   : form unlock untuk edit CPPT entry yang sudah ada
const cpptMode = ref('idle')
const isCpptMode = computed(() => cpptMode.value !== 'idle')
const editingCpptId = computed(() => {
  if (!cpptMode.value.startsWith('edit:')) return null
  return cpptMode.value.slice(5)
})
// Form locked kalau asesmen sudah finalized DAN kita tidak sedang dalam CPPT mode
const formLocked = computed(() => store.isFinalized && !isCpptMode.value)
// Visit aktif (boleh tambah/edit CPPT)
const visitActive = computed(() =>
  store.selectedQueue?.visit?.current_station !== 'SELESAI',
)

// ─── Assessment form ─────────────────────────────────────────────────────────
const form = ref(emptyForm())
const newAllergy = ref('')
const pendingCallIds = ref([])
const pendingBumpIds = ref([])

function emptyForm() {
  return {
    td_s: '', td_d: '', nadi: '', spo2: '', suhu: '', respirasi: '',
    bb: '', tb: '', pain: 0, kgd: '',
    keluhan: '', rps: '', assessment_notes: '',
    // SOAP CPPT lanjutan (perawat) — O = TTV terstruktur di atas.
    soap_s: '', soap_a: '', soap_p: '',
    allergies: [],
  }
}

// Hydrate form when existing asesmen loads
watch(() => store.asesmen, (a) => {
  if (!a) { Object.assign(form.value, emptyForm()); return }
  form.value = {
    td_s:             fmtNum(a.td_sistol)    ?? '',
    td_d:             fmtNum(a.td_diastol)   ?? '',
    nadi:             fmtNum(a.nadi)         ?? '',
    spo2:             fmtNum(a.spo2)         ?? '',
    suhu:             fmtNum(a.suhu)         ?? '',
    respirasi:        fmtNum(a.respirasi)    ?? '',
    bb:               fmtNum(a.berat_badan)  ?? '',
    tb:               fmtNum(a.tinggi_badan) ?? '',
    pain:             a.pain_scale       ?? 0,
    kgd:              fmtNum(a.kgd)          ?? '',
    keluhan:          a.chief_complaint  ?? '',
    rps:              a.rps              ?? '',
    assessment_notes: a.assessment_notes ?? '',
    allergies: a.allergy_detail
      ? a.allergy_detail.split(',').map((s) => s.trim()).filter(Boolean)
      : [],
  }
})

watch(() => store.selectedQueue, (q) => {
  cpptMode.value = 'idle'
  if (!q) { Object.assign(form.value, emptyForm()); historyExpanded.value = false }
  // Load parallel status (untuk gate tombol "Kirim ke Bedah" pada visit PREOP_BEDAH)
  store.loadParallelStatus(q?.visit?.id)
})

// ─── Preop bedah gates ───────────────────────────────────────────────────────
const isPreopBedah = computed(() =>
  store.selectedQueue?.visit?.visit_type === 'PREOP_BEDAH'
)
// Fase 8B: pre-op yang butuh rawat inap H-1 (inpatient_reason=PRE_OP) → tujuan
// "Menunggu Kamar", bukan langsung ke Bedah. Bedakan agar tombol yang muncul tepat.
const isPreopRanap = computed(() =>
  isPreopBedah.value && store.selectedQueue?.visit?.inpatient_reason === 'PRE_OP'
)
const parallelRefraksiDone = computed(() => !!store.parallelStatus?.refraksi_done)
const canKirimKeBedah = computed(() =>
  isPreopBedah.value && !isPreopRanap.value && store.isFinalized && parallelRefraksiDone.value
)
const canKirimKeRanap = computed(() =>
  isPreopRanap.value && store.isFinalized && parallelRefraksiDone.value
)

async function onKirimKeBedah() {
  try {
    await store.kirimKeBedah()
    toast('s', 'Pasien dikirim ke antrian Bedah')
    // Refresh antrian biar UI sinkron (queue triase jadi COMPLETED, dst.)
    await store.fetchAntrian()
  } catch (e) {
    toast('w', e.message)
  }
}

async function onKirimKeRanap() {
  try {
    await store.kirimKeRanap()
    toast('s', 'Pasien dikirim ke papan Menunggu Kamar (Rawat Inap)')
    await store.fetchAntrian()
  } catch (e) {
    toast('w', e.message)
  }
}

// ─── Jalur B: Kirim ke Dokter (periksa ulang operator sebelum OT) ────────────
// Gate sama dgn Kirim ke Bedah — pilihan rute ditentukan kondisi pasien /
// instruksi dokter, bukan gate berbeda.
const canKirimKeDokter = computed(() => canKirimKeBedah.value)

async function onKirimKeDokter() {
  try {
    await store.kirimKeDokter()
    toast('s', 'Pasien dikirim ke antrean Dokter (operator)')
    await store.fetchAntrian()
  } catch (e) {
    toast('w', e.message)
  }
}

// ─── Instruksi Obat Pre-Op (dokter jaga, stat-dose) ──────────────────────────
// Panel hanya utk visit PREOP_BEDAH. Mode edit hanya saat user login = dokter
// (server tetap otoritatif: endpoint menolak non-dokter via doctor_type).
const auth = useAuthStore()
const preopItems = ref([])   // editable: {medication_id, nama, jumlah, dosis, route, frequency, absorbed}
const preopDirty = ref(false)
watch(() => store.preopResep, (rx) => {
  preopItems.value = (rx?.items ?? []).map((it) => ({
    medication_id: it.medication_id,
    nama:          it.nama,
    jumlah:        it.jumlah ?? 1,
    dosis:         it.dosis ?? '',
    route:         it.route ?? '',
    frequency:     it.frequency ?? 'stat',
    absorbed:      !!it.absorbed,
  }))
  preopDirty.value = false
})
const preopLocked   = computed(() => !!store.preopResep && (store.preopResep.dispensing || store.preopResep.billing_paid))
const preopEditable = computed(() => auth.isDoctor && !preopLocked.value)

// Picker obat (server-search, debounce 300ms — pola loadObat DokterView).
const preopObatSearch  = ref('')
const preopObatResults = ref([])
const preopObatLoading = ref(false)
let preopObatTimer = null
watch(preopObatSearch, (s) => {
  clearTimeout(preopObatTimer)
  const q = (s ?? '').trim()
  if (q.length < 2) { preopObatResults.value = []; return }
  preopObatTimer = setTimeout(async () => {
    preopObatLoading.value = true
    try {
      const { data } = await perawatApi.daftarObat(q)
      preopObatResults.value = (data.data ?? []).slice(0, 20)
    } catch {
      preopObatResults.value = []
    } finally {
      preopObatLoading.value = false
    }
  }, 300)
})
function addPreopObat(m) {
  if (preopItems.value.some((it) => it.medication_id === m.id)) {
    toast('w', 'Obat sudah ada di daftar instruksi')
    return
  }
  preopItems.value.push({
    medication_id: m.id, nama: m.name, jumlah: 1, dosis: '', route: '', frequency: 'stat', absorbed: false,
  })
  preopDirty.value = true
  preopObatSearch.value = ''
  preopObatResults.value = []
}
function removePreopObat(i) {
  preopItems.value.splice(i, 1)
  preopDirty.value = true
}
async function savePreopInstr() {
  try {
    await store.savePreopResep(preopItems.value.map((it) => ({
      medication_id: it.medication_id,
      quantity:      Math.max(1, Math.round(Number(it.jumlah) || 1)),
      dose:          it.dosis || null,
      route:         it.route || null,
      frequency:     it.frequency || 'stat',
      absorbed:      !!it.absorbed,
    })))
    preopDirty.value = false
    toast('s', 'Instruksi obat pre-op disimpan & dikirim ke Farmasi')
  } catch (e) {
    toast('w', e.message)
  }
}

// ─── Computed vitals ─────────────────────────────────────────────────────────
const bmi = computed(() => {
  const bb = parseFloat(form.value.bb), tb = parseFloat(form.value.tb)
  if (!bb || !tb) return ''
  return (bb / Math.pow(tb / 100, 2)).toFixed(1)
})
const bmiLabel = computed(() => {
  if (!bmi.value) return ''
  const v = parseFloat(bmi.value)
  if (v < 18.5) return 'Underweight'
  if (v < 25)   return 'Normal'
  if (v < 30)   return 'Overweight'
  return 'Obesitas'
})

function vitalStatus(val, lo, hi) {
  if (val === '' || val == null) return 'unset'
  const n = Number(val)
  if (n < lo) return 'low'
  if (n > hi) return 'high'
  return 'normal'
}

// Sistolik normal 90–139 (≥140 = hipertensi → warna high). Threshold ini
// disetarakan dengan label hint "90–139 / 60–89" di template agar tak membingungkan.
const tdStatus   = computed(() => vitalStatus(form.value.td_s,   90, 139))
const tdDiaStatus = computed(() => vitalStatus(form.value.td_d,  60, 89))
const nadiStatus = computed(() => vitalStatus(form.value.nadi,   60, 100))
const spo2Status = computed(() => vitalStatus(form.value.spo2,   95, 100))
const suhuStatus = computed(() => vitalStatus(form.value.suhu,   36.0, 37.5))
const kgdStatus  = computed(() => vitalStatus(form.value.kgd,    70, 200))

// ─── Filtered queue ──────────────────────────────────────────────────────────
// Baris antrean dibuat hari ini? (kunjungan lintas-hari yang masih nyangkut → "Masih Aktif")
function isTodayRow(c) {
  if (!c) return true
  const d = new Date(c), n = new Date()
  return d.getFullYear() === n.getFullYear() && d.getMonth() === n.getMonth() && d.getDate() === n.getDate()
}
const isDoneQ = (q) => q.status === 'COMPLETED'

const cWait   = computed(() => store.antrian.filter((q) => isTodayRow(q.created_at) && !isDoneQ(q)).length)
const cDone   = computed(() => store.antrian.filter((q) => isTodayRow(q.created_at) && isDoneQ(q)).length)
const cActive = computed(() => store.antrian.filter((q) => !isTodayRow(q.created_at)).length)
// "Pasien hari ini" / Total = HANYA baris hari ini (lintas-hari "Masih Aktif" tak dihitung).
const cToday  = computed(() => store.antrian.filter((q) => isTodayRow(q.created_at)).length)

const filteredQueue = computed(() => {
  let list = store.antrian

  if (qPrimaryFilter.value === 'active') {
    list = list.filter((q) => !isTodayRow(q.created_at))
  } else if (qPrimaryFilter.value === 'waiting') {
    list = list.filter((q) => isTodayRow(q.created_at) && !isDoneQ(q))
  } else {
    list = list.filter((q) => isTodayRow(q.created_at) && isDoneQ(q))
  }

  if (qSecondaryFilter.value === 'bpjs') {
    list = list.filter((q) => q.visit?.guarantor_type === 'BPJS')
  } else if (qSecondaryFilter.value === 'umum') {
    list = list.filter((q) => q.visit?.guarantor_type !== 'BPJS')
  }

  if (qSearch.value) {
    const s = qSearch.value.toLowerCase()
    list = list.filter(
      (q) =>
        q.patient?.name?.toLowerCase().includes(s) ||
        q.queue_number?.toLowerCase().includes(s) ||
        q.patient?.no_rm?.toLowerCase().includes(s),
    )
  }

  return list
})

// ─── Classification helpers ───────────────────────────────────────────────────
const classColor = { Baru: 'cls-baru', 'Pre-Op': 'cls-preop', 'Post-Op': 'cls-postop', Kontrol: 'cls-kontrol' }
function clsCls(c) { return classColor[c] ?? 'cls-baru' }

// ─── Queue actions ────────────────────────────────────────────────────────────
async function pickPatient(queueItem) {
  if (store.selectedQueue?.id === queueItem.id) return

  try {
    // Panggil first if WAITING (skip untuk COMPLETED — pasien sudah lewat triase)
    if (queueItem.status === 'WAITING') {
      const updated = await store.panggilAntrian(queueItem.id)
      queueItem = updated
    }
    // pickPatient di store handle: load asesmen + CPPT timeline + vital history.
    // CALLED → mulai (status IN_PROGRESS). COMPLETED → cuma load data, tidak transisi.
    await store.pickPatient(queueItem)
  } catch (err) {
    toast('w', err.message)
  }
}

async function callPt(q, e) {
  e.stopPropagation()
  if (pendingCallIds.value.includes(q.id)) return
  if (q.sibling_active) {
    toast('w', `Pasien sedang ditangani di ${q.sibling_station_label} — tidak bisa dipanggil dari sini.`)
    return
  }
  const isRecall = q.status !== 'WAITING'
  pendingCallIds.value.push(q.id)
  try {
    await store.panggilAntrian(q.id)
    toast('i', `${isRecall ? 'Memanggil ulang' : 'Memanggil'} ${q.patient?.name} (${q.queue_number}) ke ruang triase`)
  } catch (err) {
    toast('w', err.message)
  } finally {
    pendingCallIds.value = pendingCallIds.value.filter((id) => id !== q.id)
  }
}

async function skipPt(q, e) {
  e.stopPropagation()
  try {
    await store.lewatiAntrian(q.id)
    if (store.selectedQueue?.id === q.id) store.clearSelected()
    toast('w', `${q.patient?.name} (${q.queue_number}) diturunkan 1 antrean`)
    // Refresh untuk sinkron urutan dari backend (queue_sequence di-tukar di server)
    await store.fetchAntrian()
  } catch (err) {
    toast('w', err.message)
  }
}

// Dahulukan: naikkan pasien ke atas antrean TR (mis. jadwal dokternya hampir habis).
async function prioritizePt(q, e) {
  e.stopPropagation()
  if (pendingBumpIds.value.includes(q.id)) return
  const why = q.schedule_risk?.at_risk ? `\n\nAlasan: jadwal ${q.doctor ?? 'dokter'} ${q.schedule_risk.reason}.` : ''
  if (!confirm(`Dahulukan ${q.patient?.name ?? 'pasien'} (${q.queue_number}) ke atas antrean triase?${why}`)) return
  pendingBumpIds.value.push(q.id)
  try {
    await store.dahulukanAntrian(q.id)
    toast('s', `${q.patient?.name} (${q.queue_number}) didahulukan ke atas antrean`)
  } catch (err) {
    toast('e', err.message)
  } finally {
    pendingBumpIds.value = pendingBumpIds.value.filter((id) => id !== q.id)
  }
}

// ─── Allergy helpers ──────────────────────────────────────────────────────────
function addAllergy() {
  const t = newAllergy.value.trim()
  if (!t) return
  if (!form.value.allergies.includes(t)) form.value.allergies.push(t)
  newAllergy.value = ''
}
function removeAllergy(i) { form.value.allergies.splice(i, 1) }

// ─── Save assessment ──────────────────────────────────────────────────────────
// Wajib: TD (sistolik + diastolik) + Keluhan Utama. KGD & sisa field optional.
async function saveAssessment() {
  // Tidak ada field wajib untuk perawat — semua TTV/keluhan opsional.
  try {
    await store.saveAsesmen({
      td_sistol:        form.value.td_s ? Math.round(Number(form.value.td_s)) : null,
      td_diastol:       form.value.td_d ? Math.round(Number(form.value.td_d)) : null,
      kgd:              form.value.kgd ? Number(form.value.kgd) : null,
      nadi:             form.value.nadi      ? Math.round(Number(form.value.nadi))      : null,
      suhu:             form.value.suhu      ? Number(form.value.suhu)      : null,
      respirasi:        form.value.respirasi ? Math.round(Number(form.value.respirasi)) : null,
      spo2:             form.value.spo2      ? Number(form.value.spo2)      : null,
      pain_scale:       form.value.pain,
      berat_badan:      form.value.bb        ? Number(form.value.bb)        : null,
      tinggi_badan:     form.value.tb        ? Number(form.value.tb)        : null,
      has_allergy:      form.value.allergies.length > 0,
      allergy_detail:   form.value.allergies.length > 0 ? form.value.allergies.join(', ') : null,
      chief_complaint:  form.value.keluhan || null,
      rps:              form.value.rps              || null,
      assessment_notes: form.value.assessment_notes || null,
    })
    toast('s', 'Asesmen tersimpan')
  } catch (err) {
    toast('w', err.message)
  }
}

// Buka kunci (periksa ulang atas permintaan dokter) — form ter-unlock, antrean kembali aktif.
async function onReopen() {
  if (!store.asesmen?.id) return
  if (!confirm('Buka kunci asesmen triase untuk diperiksa ulang?\nAsesmen harus difinalisasi ulang.')) return
  try {
    await store.reopenAsesmen()
    toast('s', 'Asesmen dibuka kembali — silakan revisi lalu finalize lagi.')
  } catch (err) {
    toast('w', err.message || 'Gagal membuka kunci')
  }
}

// Lewati Triase: pasien tidak perlu triase. Asesmen ditandai "dilewati" (tanpa
// data klinis) & antrean tetap maju (gate paralel ke Dokter / Kirim ke Bedah).
async function onSkipTriase() {
  if (!store.selectedQueue?.id) { toast('w', 'Pilih pasien dulu'); return }
  if (!confirm('Lewati triase untuk pasien ini? Asesmen ditandai "tidak diperlukan" dan pasien lanjut ke antrean berikutnya.')) return
  try {
    await store.skipTriase()
    toast('s', 'Triase dilewati — pasien lanjut ke antrean berikutnya')
    store.clearSelected()
  } catch (err) {
    toast('e', err.message || 'Gagal melewati triase')
  }
}

async function sendToDoctor() {
  if (!store.asesmen?.id) { toast('w', 'Simpan asesmen dulu'); return }
  try {
    // Backend balikkan doctor_ticket (D-NNN) bila Refraksionis JUGA sudah finalize —
    // dipakai tombol "Cetak Tiket Dokter". Pasien tetap terpilih agar tombol tampil.
    await store.finalizeAsesmen()
    if (store.doctorTicket) {
      toast('s', `Pasien lengkap (TR) — antrean dokter ${store.doctorTicket.queue_number} dibuat. Cetak tiket pasien.`)
    } else {
      toast('s', 'Asesmen dikunci. Menunggu Refraksionis selesai sebelum tiket dokter bisa dicetak.')
    }
  } catch (err) {
    toast('w', err.message)
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
  if (!t?.queue_number) { toast('w', 'Antrean dokter belum dibuat — tunggu Refraksionis selesai.'); return }
  const patientName = store.selectedQueue?.patient?.name ?? ''
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

// ─── CPPT handlers ───────────────────────────────────────────────────────────

function startNewCppt() {
  if (!visitActive.value) { toast('w', 'Kunjungan sudah selesai'); return }
  cpptMode.value = 'new'
  // Keluhan/RPS/Alergi tidak ikut di CPPT (focus pada observasi ulang).
  // TTV tetap pre-fill dari asesmen awal supaya perawat bisa edit nilai yang berubah saja.
  form.value.assessment_notes = '' // notes CPPT diisi ulang
  form.value.soap_s = ''
  form.value.soap_a = ''
  form.value.soap_p = ''
}

function startEditCppt(entry) {
  if (!visitActive.value) { toast('w', 'Kunjungan sudah selesai'); return }
  cpptMode.value = `edit:${entry.id}`
  // Hydrate form dari entry yang mau di-edit
  form.value = {
    ...form.value,
    td_s:             fmtNum(entry.td_sistol)  ?? '',
    td_d:             fmtNum(entry.td_diastol) ?? '',
    nadi:             fmtNum(entry.nadi)       ?? '',
    suhu:             fmtNum(entry.suhu)       ?? '',
    respirasi:        fmtNum(entry.respirasi)  ?? '',
    spo2:             fmtNum(entry.spo2)       ?? '',
    kgd:              fmtNum(entry.kgd)        ?? '',
    pain:             entry.pain_scale ?? 0,
    assessment_notes: entry.notes      ?? '',
    soap_s:           entry.soap_s     ?? '',
    soap_a:           entry.soap_a     ?? '',
    soap_p:           entry.soap_p     ?? '',
  }
}

function cancelCpptMode() {
  cpptMode.value = 'idle'
  // Re-hydrate form dari asesmen awal (revert perubahan yang belum disubmit)
  if (store.asesmen) {
    const a = store.asesmen
    form.value = {
      td_s:             fmtNum(a.td_sistol)    ?? '',
      td_d:             fmtNum(a.td_diastol)   ?? '',
      nadi:             fmtNum(a.nadi)         ?? '',
      spo2:             fmtNum(a.spo2)         ?? '',
      suhu:             fmtNum(a.suhu)         ?? '',
      respirasi:        fmtNum(a.respirasi)    ?? '',
      bb:               fmtNum(a.berat_badan)  ?? '',
      tb:               fmtNum(a.tinggi_badan) ?? '',
      pain:             a.pain_scale       ?? 0,
      kgd:              fmtNum(a.kgd)          ?? '',
      keluhan:          a.chief_complaint  ?? '',
      rps:              a.rps              ?? '',
      assessment_notes: a.assessment_notes ?? '',
      allergies: a.allergy_detail
        ? a.allergy_detail.split(',').map((s) => s.trim()).filter(Boolean)
        : [],
    }
  }
}

async function saveCppt() {
  const hasNotes = !!form.value.assessment_notes?.trim()
  const hasSoap  = !!(form.value.soap_s?.trim() || form.value.soap_a?.trim() || form.value.soap_p?.trim())
  if (!hasNotes && !hasSoap) {
    toast('w', 'Isi minimal Catatan atau salah satu SOAP (S/A/P)'); return
  }
  const payload = {
    td_sistol:  form.value.td_s      ? Math.round(Number(form.value.td_s))      : null,
    td_diastol: form.value.td_d      ? Math.round(Number(form.value.td_d))      : null,
    nadi:       form.value.nadi      ? Math.round(Number(form.value.nadi))      : null,
    suhu:       form.value.suhu      ? Number(form.value.suhu)      : null,
    respirasi:  form.value.respirasi ? Math.round(Number(form.value.respirasi)) : null,
    spo2:       form.value.spo2      ? Number(form.value.spo2)      : null,
    kgd:        form.value.kgd       ? Number(form.value.kgd)       : null,
    pain_scale: form.value.pain,
    notes:      form.value.assessment_notes?.trim() || null,
    soap_s:     form.value.soap_s?.trim() || null,
    soap_a:     form.value.soap_a?.trim() || null,
    soap_p:     form.value.soap_p?.trim() || null,
  }

  try {
    if (cpptMode.value === 'new') {
      await store.addCpptEntry(payload)
      toast('s', 'CPPT baru ditambahkan')
    } else if (editingCpptId.value) {
      await store.updateCpptEntry(editingCpptId.value, payload)
      toast('s', 'CPPT diperbarui')
    }
    cancelCpptMode()
    cpptCardRef.value?.reload()
  } catch (err) {
    toast('w', err.message)
  }
}

// ─── Tanda tangan CPPT (paraf perawat via PIN) ───────────────────────────────
const cpptCardRef = ref(null)
const signingEntry = ref(null)   // entry yang sedang ditandatangani
const pinValue = ref('')
const pinError = ref('')
const pinBusy  = ref(false)

function openSignCppt(entry) {
  if (!visitActive.value) { toast('w', 'Kunjungan sudah selesai'); return }
  signingEntry.value = entry
  pinValue.value = ''
  pinError.value = ''
}

async function confirmSignPin() {
  const pin = pinValue.value.trim()
  if (!/^\d{4,6}$/.test(pin)) { pinError.value = 'PIN harus 4–6 digit angka.'; return }
  if (!signingEntry.value) return
  pinError.value = ''
  pinBusy.value  = true
  try {
    await store.signCpptEntry(signingEntry.value.id, pin)
    signingEntry.value = null
    toast('s', 'CPPT ditandatangani')
    cpptCardRef.value?.reload()
  } catch (err) {
    pinError.value = err.message ?? 'Gagal menandatangani CPPT'
  } finally {
    pinBusy.value = false
  }
}

// ─── Rekam medis modal ────────────────────────────────────────────────────────
async function openRekamMedis() {
  const pid = store.selectedPatientId
  if (!pid) return
  rekamMedisOpen.value = true
  viewingDokumen.value = false
  store.selectedDokumen = null
  await store.loadRekamMedis(pid)
}

async function viewDokumen(docId) {
  try {
    await store.loadDokumen(docId)
    viewingDokumen.value = true
  } catch (err) {
    toast('w', err.message)
  }
}

// ─── Toast ────────────────────────────────────────────────────────────────────
function toast(type, msg) {
  const id = ++_tid
  toasts.value.push({ id, type, msg })
  setTimeout(() => { toasts.value = toasts.value.filter((t) => t.id !== id) }, 3200)
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function calcAge(dob) {
  if (!dob) return null
  const d = new Date(dob), n = new Date()
  return n.getFullYear() - d.getFullYear()
    - (n < new Date(n.getFullYear(), d.getMonth(), d.getDate()) ? 1 : 0)
}

function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

function fmtTime(d) {
  if (!d) return '—'
  return new Date(d).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
}

function fmtDateTime(d) {
  if (!d) return '—'
  const dt = new Date(d)
  return `${dt.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' })} · ${dt.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}`
}

// Backend mengirim kolom decimal:2 sebagai string "120.00" / "36.50".
// Coerce ke Number agar nol desimal yang tidak perlu hilang: 120.00 → 120, 36.50 → 36.5.
// Null/kosong dikembalikan apa adanya supaya pemanggil tetap bisa pakai `?? '—'`.
function fmtNum(v) {
  if (v === null || v === undefined || v === '') return null
  const n = Number(v)
  return Number.isNaN(n) ? v : n
}

// ─── Lifecycle ────────────────────────────────────────────────────────────────
let _parallelPollTimer = null
function startParallelPolling() {
  stopParallelPolling()
  _parallelPollTimer = setInterval(() => {
    if (isPreopBedah.value && store.isFinalized && !parallelRefraksiDone.value) {
      store.loadParallelStatus(store.selectedQueue?.visit?.id)
    }
  }, 10000)
}
function stopParallelPolling() {
  if (_parallelPollTimer) { clearInterval(_parallelPollTimer); _parallelPollTimer = null }
}

onMounted(async () => {
  await store.fetchAntrian()
  store.connectWs()
  startParallelPolling()
})

onUnmounted(() => {
  stopParallelPolling()
  store.disconnectWs()
  store.clearSelected()
})
</script>

<template>
  <div class="perawat">
    <div :class="['main-grid', { 'q-collapsed': queueCollapsed, 's-collapsed': sideCollapsed }]">

      <!-- ══════════════════ LEFT: QUEUE ══════════════════ -->
      <aside class="col-queue">
        <button class="queue-rail" @click="toggleQueue" title="Buka daftar antrean" aria-label="Buka daftar antrean">
          <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
          <span class="queue-rail-count">{{ cToday }}</span>
          <span class="queue-rail-txt">Antrean</span>
        </button>
        <div class="card queue-card">
          <div class="card-head">
            <div>
              <div class="card-head-title">
                <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Antrean Triase
              </div>
              <div class="card-head-sub">
                {{ cToday }} pasien hari ini
              </div>
            </div>
            <div class="head-actions">
              <UnitStockActions station="TRIASE" compact />
              <span class="pill-live">LIVE</span>
              <button class="panel-collapse" @click="toggleQueue" title="Ciutkan antrean" aria-label="Ciutkan antrean">
                <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
              </button>
            </div>
          </div>

          <div class="card-body queue-scroll" role="region" aria-label="Daftar antrean triase">

            <!-- Stats bar -->
            <div class="stats-bar">
              <div class="stat-item">
                <span class="stat-label">Belum Dipanggil</span>
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
                <b class="stat-num">{{ cToday }}</b>
              </div>
            </div>

            <!-- Primary filter -->
            <div class="primary-filter" role="group" aria-label="Filter utama antrean">
              <button
                :class="['pf-btn', qPrimaryFilter === 'waiting' ? 'a' : '']"
                @click="qPrimaryFilter = 'waiting'"
              >
                Belum Dipanggil
                <span v-if="cWait" class="pf-ct">{{ cWait }}</span>
              </button>
              <button
                :class="['pf-btn', qPrimaryFilter === 'done' ? 'a' : '']"
                @click="qPrimaryFilter = 'done'"
              >
                Selesai
                <span v-if="cDone" class="pf-ct">{{ cDone }}</span>
              </button>
              <button
                :class="['pf-btn', qPrimaryFilter === 'active' ? 'a' : '']"
                @click="qPrimaryFilter = 'active'"
                title="Kunjungan belum selesai dari hari sebelumnya (lintas-hari)"
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
              <input v-model="qSearch" class="q-search" placeholder="Cari nama / no. antrean / RM…" />
            </div>

            <!-- Loading skeleton -->
            <template v-if="store.queueLoading">
              <div v-for="n in 4" :key="n" class="q-skeleton"></div>
            </template>

            <!-- Error -->
            <div v-else-if="store.queueError" class="empty-section err">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              {{ store.queueError }}
              <button class="retry-btn" @click="store.fetchAntrian()">Coba lagi</button>
            </div>

            <!-- Empty -->
            <div v-else-if="!filteredQueue.length" class="empty-section" aria-live="polite">
              Tidak ada pasien dalam filter ini
            </div>

            <!-- Queue list -->
            <div v-else role="list" aria-label="Daftar antrean triase">
              <div
                v-for="q in filteredQueue" :key="q.id"
                role="listitem"
                :class="['q-item',
                  store.selectedQueue?.id === q.id ? 'active' : '',
                  q.status === 'COMPLETED' ? 'done' : '',
                ]"
                tabindex="0"
                @click="pickPatient(q)"
                @keydown.enter="pickPatient(q)"
              >
                <div class="qi-left">
                  <div class="q-num">{{ q.queue_number }}</div>
                  <span :class="['pill', `pill-${q.status.toLowerCase()}`]">
                    <svg v-if="q.status === 'WAITING'"      viewBox="0 0 24 24" class="pill-icon"><path d="M5 2h14M5 22h14M6 2v5l4 5-4 5v5M18 2v5l-4 5 4 5v5"/></svg>
                    <svg v-else-if="q.status === 'CALLED'"  viewBox="0 0 24 24" class="pill-icon"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 014.69 12a19.79 19.79 0 01-3.07-8.67A2 2 0 013.6 1.27h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L7.91 8.91a16 16 0 006.18 6.18l.96-.96a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                    <svg v-else-if="q.status === 'IN_PROGRESS'" viewBox="0 0 24 24" class="pill-icon"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                    <svg v-else viewBox="0 0 24 24" class="pill-icon"><polyline points="20 6 9 17 4 12"/></svg>
                    {{ q.status === 'WAITING' ? 'Menunggu' : q.status === 'CALLED' ? 'Dipanggil' : q.status === 'IN_PROGRESS' ? 'Proses' : 'Selesai' }}
                  </span>
                </div>

                <div class="q-info">
                  <div class="q-name">{{ q.patient?.name ?? '—' }}</div>
                  <div class="q-meta">
                    {{ q.patient?.age ?? '—' }} th
                    · {{ q.patient?.gender === 'L' ? 'L' : 'P' }}
                    · {{ q.visit?.classification ?? '—' }}
                    <span v-if="q.visit?.visit_date" class="q-visit-date" :title="`Tanggal kunjungan: ${fmtDate(q.visit.visit_date)}`">
                      <svg viewBox="0 0 24 24" class="pill-icon"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                      {{ fmtDate(q.visit.visit_date) }}
                    </span>
                  </div>
                  <div v-if="q.doctor" class="q-dpjp" :title="`DPJP tujuan: ${q.doctor}`">
                    <svg viewBox="0 0 24 24" class="q-dpjp-ic" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
                    DPJP: {{ q.doctor }}<span v-if="q.schedule_end"> · s/d {{ q.schedule_end }}</span>
                  </div>
                  <div class="q-tags">
                    <span :class="['pill', q.visit?.guarantor_type === 'BPJS' ? 'pill-bpjs' : 'pill-umum']">
                      {{ q.visit?.guarantor_type === 'BPJS' ? 'BPJS' : q.visit?.guarantor_type ?? 'Umum' }}
                    </span>
                    <span :class="['pill', clsCls(q.visit?.classification)]" v-if="q.visit?.classification">
                      {{ q.visit.classification }}
                    </span>
                    <span v-if="q.visit?.assessment_finalized" class="pill pill-done">
                      <svg viewBox="0 0 24 24" class="pill-icon"><polyline points="20 6 9 17 4 12"/></svg>
                      TTV
                    </span>
                    <span
                      v-if="q.schedule_risk?.at_risk"
                      class="pill pill-risk"
                      :title="`Jadwal ${q.doctor ?? 'dokter'} hampir habis — ${q.schedule_risk.reason}`"
                    >
                      <svg viewBox="0 0 24 24" class="pill-icon"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                      {{ q.schedule_risk.reason }}
                    </span>
                    <span v-if="q.sibling_active" class="pill pill-sibling" :title="`Pasien sedang ditangani di ${q.sibling_station_label}`">⏳ Sedang di {{ q.sibling_station_label }}</span>
                    <span v-else-if="q.sibling_completed" class="pill pill-done" :title="`Sudah selesai diperiksa di ${q.sibling_station_label}`">
                      <svg viewBox="0 0 24 24" class="pill-icon"><polyline points="20 6 9 17 4 12"/></svg>
                      Selesai {{ q.sibling_station_label }}
                    </span>
                  </div>
                  <div v-if="q.status !== 'COMPLETED'" class="q-actions" @click.stop>
                    <button
                      :class="['q-act-btn', 'call', q.status !== 'WAITING' ? 'recall' : '']"
                      :disabled="pendingCallIds.includes(q.id) || q.sibling_active"
                      :title="q.sibling_active ? `Pasien sedang ditangani di ${q.sibling_station_label}` : 'Panggil pasien'"
                      @click="callPt(q, $event)"
                    >
                      <svg v-if="q.status === 'WAITING'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 014.69 12a19.79 19.79 0 01-3.07-8.67A2 2 0 013.6 1.27h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L7.91 8.91a16 16 0 006.18 6.18l.96-.96a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                      <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
                      {{ q.status === 'WAITING' ? 'Panggil' : 'Panggil Ulang' }}
                    </button>
                    <button class="q-act-btn skip" @click="skipPt(q, $event)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="7 13 12 18 17 13"/><polyline points="7 6 12 11 17 6"/></svg>
                      Lewati
                    </button>
                    <button
                      :class="['q-act-btn', 'bump', q.schedule_risk?.at_risk ? 'bump-risk' : '']"
                      :disabled="pendingBumpIds.includes(q.id)"
                      title="Dahulukan: naikkan ke atas antrean triase"
                      @click="prioritizePt(q, $event)"
                    >
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="17 11 12 6 7 11"/><polyline points="17 18 12 13 7 18"/></svg>
                      Dahulukan
                    </button>
                  </div>
                </div>

                <div class="qi-time">{{ fmtTime(q.created_at) }}</div>
              </div>
            </div>

          </div>
        </div>
      </aside>

      <!-- ══════════════════ RIGHT: ASSESSMENT ══════════════════ -->
      <section class="col-form">

        <!-- Empty state -->
        <div v-if="!store.selectedQueue" class="card empty-card">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2L3 7v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-9-5z"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          <p>Pilih pasien dari daftar antrean<br />untuk mulai pengkajian triase</p>
        </div>

        <template v-else>

          <!-- Patient header -->
          <div class="pt-header">
            <PatientAvatar
              :name="store.selectedQueue.patient?.name"
              :src="store.selectedQueue.patient?.photo_url"
              :size="44" radius="50%" style="margin-top:2px"
            />
            <div class="pt-info">
              <div class="pt-name">{{ store.selectedQueue.patient?.name ?? '—' }}</div>
              <div class="pt-meta">
                RM: {{ store.selectedQueue.patient?.no_rm ?? '—' }}
                · {{ store.selectedQueue.patient?.age ?? '—' }} th
                · {{ store.selectedQueue.patient?.gender === 'L' ? 'Laki-laki' : 'Perempuan' }}
              </div>
              <div v-if="store.selectedQueue.doctor" class="pt-dpjp" :title="`DPJP tujuan: ${store.selectedQueue.doctor}`">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
                DPJP: <strong>{{ store.selectedQueue.doctor }}</strong>
                <span v-if="store.selectedQueue.schedule_end"> · s/d {{ store.selectedQueue.schedule_end }}</span>
              </div>
              <div v-if="store.selectedQueue.patient?.address" class="pt-address">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                {{ store.selectedQueue.patient.address }}
                <span v-if="store.selectedQueue.patient.province"> · {{ store.selectedQueue.patient.province }}</span>
              </div>
              <div class="pt-badges">
                <!-- PREOP BEDAH badge (kuning) -->
                <span v-if="isPreopBedah" class="ptg ptg-preop" title="Pasien preop bedah — bypass dokter">
                  <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  PREOP BEDAH
                </span>
                <!-- Classification badge -->
                <span v-if="store.selectedQueue.visit?.classification"
                  :class="['ptg', clsCls(store.selectedQueue.visit.classification)]">
                  {{ store.selectedQueue.visit.classification }}
                </span>
                <!-- Guarantor -->
                <span v-if="store.selectedQueue.visit?.guarantor_type === 'BPJS'" class="ptg ptg-b">
                  BPJS · {{ store.selectedQueue.patient?.bpjs_number ?? store.selectedQueue.visit.no_sep ?? '—' }}
                </span>
                <span v-else-if="store.selectedQueue.visit?.insurer_name" class="ptg ptg-a">
                  {{ store.selectedQueue.visit.insurer_name }}
                </span>
                <span v-else-if="store.selectedQueue.visit?.guarantor_type" class="ptg ptg-u">
                  {{ store.selectedQueue.visit.guarantor_type }}
                </span>
              </div>
            </div>

            <!-- Right side actions + saved vitals -->
            <div class="pt-right">
              <button class="btn btn-secondary btn-sm" @click="openRekamMedis" :disabled="!store.selectedPatientId">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                Rekam Medis
              </button>
              <div v-if="store.asesmen?.is_finalized" class="saved-pill">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                Dikunci
              </div>
              <div v-else-if="store.asesmen?.id" class="saved-pill saved-draft">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 15"/></svg>
                Draft tersimpan
              </div>
            </div>

            <!-- Inline saved vitals summary -->
            <div v-if="store.asesmen" class="pt-vitals" aria-label="Ringkasan tanda vital tersimpan">
              <div class="pvi">TD <b>{{ fmtNum(store.asesmen.td_sistol) ?? '—' }}/{{ fmtNum(store.asesmen.td_diastol) ?? '—' }}</b></div>
              <div class="pvi">N <b>{{ fmtNum(store.asesmen.nadi) ?? '—' }}</b></div>
              <div class="pvi">SpO₂ <b>{{ store.asesmen.spo2 ? fmtNum(store.asesmen.spo2) + '%' : '—' }}</b></div>
              <div v-if="store.asesmen.kgd" class="pvi">KGD <b>{{ fmtNum(store.asesmen.kgd) }}</b></div>
            </div>

            <!-- Hasil Refraksi (visus/IOP) bila refraksionis sudah memeriksa pasien ini -->
            <div v-if="hasRefraksi" class="pt-refraksi" aria-label="Hasil refraksi">
              <span class="pt-rfx-tag">Refraksi</span>
              <div v-if="rfx.visus_od || rfx.visus_os" class="pvi">Visus <b>{{ rfx.visus_od || '—' }} / {{ rfx.visus_os || '—' }}</b></div>
              <div v-if="rfx.iop_od != null || rfx.iop_os != null" class="pvi">TIO <b>{{ rfx.iop_od ?? '—' }} / {{ rfx.iop_os ?? '—' }}</b> <span class="pvi-u">mmHg</span></div>
            </div>
          </div>

          <!-- SOAP / Vital History dropdown -->
          <div v-if="store.vitalHistory.length || store.vitalHistoryLoading" class="history-panel">
            <button class="history-toggle" @click="historyExpanded = !historyExpanded" :aria-expanded="historyExpanded">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 15"/></svg>
              Riwayat Kunjungan
              <span class="history-ct">{{ store.vitalHistory.length }}</span>
              <svg class="chevron" :class="{ up: historyExpanded }" viewBox="0 0 24 24" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>

            <div v-if="historyExpanded" class="history-body">
              <div v-if="store.vitalHistoryLoading" class="history-loading">
                <div class="sp" role="status" aria-label="Memuat riwayat…"></div>
                Memuat riwayat…
              </div>
              <div v-else class="history-list">
                <div v-for="h in store.vitalHistory" :key="h.id" class="history-item">
                  <div class="hi-date">
                    <span class="hi-cls" :class="clsCls(h.visit?.classification)">{{ h.visit?.classification ?? 'Kunjungan' }}</span>
                    {{ fmtDate(h.visit?.visit_date ?? h.created_at) }}
                  </div>
                  <div class="hi-vitals">
                    <span>TD <b>{{ fmtNum(h.td_sistol) }}/{{ fmtNum(h.td_diastol) }}</b></span>
                    <span>N <b>{{ fmtNum(h.nadi) }}</b></span>
                    <span>SpO₂ <b>{{ fmtNum(h.spo2) ?? '—' }}%</b></span>
                    <span>Suhu <b>{{ fmtNum(h.suhu) ?? '—' }}°C</b></span>
                    <span v-if="h.kgd">KGD <b>{{ fmtNum(h.kgd) }}</b></span>
                  </div>
                  <div v-if="h.chief_complaint" class="hi-complaint">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                    {{ h.chief_complaint }}
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Tab section (1 tab saja — Tanda Vital + Keluhan + Alergi tergabung) -->

          <!-- Loading asesmen -->
          <div v-if="store.asesmenLoading" class="card asesmen-loading">
            <div class="sp" role="status" aria-label="Memuat asesmen…"></div>
            Memuat data asesmen…
          </div>

          <!-- TANDA VITAL + KELUHAN + ALERGI (gabungan 1 card) -->
          <div v-else class="card">
            <div class="card-head">
              <div class="card-head-title">
                <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Asesmen Triase
              </div>
            </div>
            <div class="card-body">

              <div class="sec-title">Kardiovaskular</div>
              <div class="form-grid g4">
                <div class="fg span2">
                  <label class="fl" for="td-s">Tekanan Darah <span class="hint">90–139 / 60–89</span></label>
                  <div class="td-row">
                    <input id="td-s" v-model="form.td_s" type="number" :class="['form-input', `vital-${tdStatus}`]" placeholder="Sistolik" :disabled="formLocked" />
                    <span aria-hidden="true">/</span>
                    <input v-model="form.td_d" type="number" :class="['form-input', `vital-${tdDiaStatus}`]" placeholder="Diastolik" aria-label="Diastolik mmHg" :disabled="formLocked" />
                    <span class="unit" aria-hidden="true">mmHg</span>
                  </div>
                </div>
                <div class="fg">
                  <label class="fl" for="inp-nadi">Nadi <span class="hint">60–100</span></label>
                  <input id="inp-nadi" v-model="form.nadi" type="number" :class="['form-input', `vital-${nadiStatus}`]" placeholder="78" :disabled="formLocked" />
                </div>
                <div class="fg">
                  <label class="fl" for="inp-spo2">SpO₂ <span class="hint">95–100 %</span></label>
                  <input id="inp-spo2" v-model="form.spo2" type="number" :class="['form-input', `vital-${spo2Status}`]" placeholder="98" :disabled="formLocked" />
                </div>
              </div>

              <div class="sec-title">Antropometri &amp; Suhu</div>
              <div class="form-grid g4">
                <div class="fg">
                  <label class="fl" for="inp-suhu">Suhu <span class="hint">36.0–37.5 °C</span></label>
                  <input id="inp-suhu" v-model="form.suhu" type="number" step="0.1" :class="['form-input', `vital-${suhuStatus}`]" placeholder="36.5" :disabled="formLocked" />
                </div>
                <div class="fg">
                  <label class="fl" for="inp-resp">Respirasi <span class="hint">12–20 /mnt</span></label>
                  <input id="inp-resp" v-model="form.respirasi" type="number" class="form-input" placeholder="18" :disabled="formLocked" />
                </div>
                <div class="fg">
                  <label class="fl" for="inp-bb">BB <span class="hint">kg</span></label>
                  <input id="inp-bb" v-model="form.bb" type="number" class="form-input" placeholder="65" :disabled="formLocked" />
                </div>
                <div class="fg">
                  <label class="fl" for="inp-tb">TB <span class="hint">cm</span></label>
                  <input id="inp-tb" v-model="form.tb" type="number" class="form-input" placeholder="165" :disabled="formLocked" />
                </div>
                <div class="fg">
                  <label class="fl" for="inp-bmi">BMI</label>
                  <input id="inp-bmi" :value="bmi" class="form-input readonly" readonly placeholder="—" aria-live="polite" />
                  <span v-if="bmi" class="bmi-label">{{ bmiLabel }}</span>
                </div>
              </div>

              <div class="sec-title">Metabolik</div>
              <div class="form-grid g4">
                <div class="fg">
                  <label class="fl" for="inp-kgd">KGD <span class="hint">opsional · 70–200 mg/dL</span></label>
                  <input id="inp-kgd" v-model="form.kgd" type="number" :class="['form-input', `vital-${kgdStatus}`]" placeholder="120" :disabled="formLocked" />
                </div>
                <div class="fg" style="grid-column:span 3">
                  <label class="fl">Status</label>
                  <div class="kgd-info">
                    <span v-if="!form.kgd"                      class="kgd-hint">Isi KGD untuk melihat status</span>
                    <span v-else-if="kgdStatus === 'low'"        class="vital-tag low">Hipoglikemia (&lt;70)</span>
                    <span v-else-if="kgdStatus === 'high'"       class="vital-tag high">Hiperglikemia (&gt;200) — perlu perhatian</span>
                    <span v-else                                 class="vital-tag normal">Kadar gula darah normal</span>
                  </div>
                </div>
              </div>

              <div class="sec-title">Skala Nyeri (NRS 0–10)</div>
              <div class="pain-scale" role="group" aria-label="Skala nyeri NRS 0 sampai 10">
                <button v-for="n in 11" :key="n"
                  :class="['pain-btn', form.pain === n - 1 ? 'a' : '', `pain-${n - 1}`]"
                  :aria-pressed="form.pain === n - 1"
                  :disabled="formLocked"
                  @click="form.pain = n - 1"
                >{{ n - 1 }}</button>
              </div>

              <!-- ─── Keluhan Utama & Anamnesis ─── -->
              <div class="sec-title">Keluhan &amp; Anamnesis</div>
              <div class="stack">
                <div class="fg">
                  <label class="fl" for="inp-keluhan">Keluhan Utama</label>
                  <textarea id="inp-keluhan" v-model="form.keluhan" class="form-input ta" rows="3" placeholder="Keluhan pasien saat datang…" :disabled="formLocked"></textarea>
                </div>
                <div class="fg">
                  <label class="fl" for="inp-rps">Riwayat Penyakit Sekarang (RPS)</label>
                  <textarea id="inp-rps" v-model="form.rps" class="form-input ta" rows="3" placeholder="Onset, durasi, faktor pencetus, terapi sebelumnya…" :disabled="formLocked"></textarea>
                </div>
                <div class="fg">
                  <label class="fl" for="inp-notes">Catatan Tambahan Perawat</label>
                  <textarea id="inp-notes" v-model="form.assessment_notes" class="form-input ta" rows="2" placeholder="Observasi tambahan, kondisi umum pasien…" :disabled="formLocked"></textarea>
                </div>
              </div>

              <!-- ─── SOAP CPPT (hanya saat mode CPPT lanjutan) ─── -->
              <template v-if="isCpptMode">
                <div class="sec-title">SOAP · CPPT Terpadu <span class="soap-opt">opsional bila hanya TTV</span></div>
                <div class="stack soap-prw">
                  <div class="fg">
                    <label class="fl" for="inp-soap-s"><span class="soap-tag s">S</span> Subjektif — keluhan/perkembangan</label>
                    <textarea id="inp-soap-s" v-model="form.soap_s" class="form-input ta" rows="2" placeholder="Keluhan/perkembangan yang dilaporkan pasien saat observasi ini…"></textarea>
                  </div>
                  <div class="fg">
                    <label class="fl"><span class="soap-tag o">O</span> Objektif <em class="soap-opt">otomatis dari Tanda Vital di atas</em></label>
                    <div class="soap-o-derived">TTV (TD/Nadi/Suhu/SpO₂/KGD) yang Anda isi otomatis menjadi <b>Objektif</b> di CPPT.</div>
                  </div>
                  <div class="fg">
                    <label class="fl" for="inp-soap-a"><span class="soap-tag a">A</span> Assessment — kesimpulan keperawatan</label>
                    <textarea id="inp-soap-a" v-model="form.soap_a" class="form-input ta" rows="2" placeholder="Mis. nyeri akut terkontrol; risiko jatuh sedang; hipertensi terkontrol…"></textarea>
                  </div>
                  <div class="fg">
                    <label class="fl" for="inp-soap-p"><span class="soap-tag p">P</span> Planning — rencana/edukasi</label>
                    <textarea id="inp-soap-p" v-model="form.soap_p" class="form-input ta" rows="2" placeholder="Mis. observasi TTV tiap 4 jam; edukasi diet; kolaborasi DPJP…"></textarea>
                  </div>
                </div>
              </template>

              <!-- ─── Skrining Alergi ─── -->
              <div class="sec-title">Skrining Alergi</div>
              <div v-if="store.selectedQueue.patient?.allergy_notes" class="allergy-known">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Alergi tercatat di RM: {{ store.selectedQueue.patient.allergy_notes }}
              </div>
              <label class="fl" for="inp-alergi">Alergi diketahui hari ini</label>
              <div class="allergy-row">
                <span v-for="(a, i) in form.allergies" :key="i" class="allergy-tag">
                  {{ a }}
                  <button @click="removeAllergy(i)" :aria-label="`Hapus alergi ${a}`" :disabled="formLocked">×</button>
                </span>
                <input id="inp-alergi" v-model="newAllergy"
                  class="allergy-input" placeholder="Ketik & Enter (cth: Penisilin, Aspirin)"
                  :disabled="formLocked"
                  @keydown.enter.prevent="addAllergy"
                  @keydown.,.prevent="addAllergy"
                />
              </div>
              <p class="hint">Pisahkan dengan koma atau Enter</p>

              <!-- ─── Tombol Aksi ─── -->
              <div class="action-row">
                <!-- Mode CPPT (new atau edit): Simpan CPPT + Batal -->
                <button
                  v-if="isCpptMode"
                  class="btn btn-primary btn-lg"
                  :disabled="store.cpptSaving"
                  @click="saveCppt"
                >
                  <div v-if="store.cpptSaving" class="sp" role="status"></div>
                  <svg v-else viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                  {{ store.cpptSaving
                     ? 'Menyimpan…'
                     : cpptMode === 'new' ? 'Simpan CPPT Baru' : 'Simpan Perubahan' }}
                </button>
                <button
                  v-if="isCpptMode"
                  class="btn btn-secondary"
                  :disabled="store.cpptSaving"
                  @click="cancelCpptMode"
                >
                  <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  Batal
                </button>

                <!-- Mode normal (belum finalized): Simpan Asesmen -->
                <button
                  v-if="!isCpptMode && !store.isFinalized"
                  class="btn btn-primary btn-lg"
                  :disabled="store.saving"
                  @click="saveAssessment"
                >
                  <div v-if="store.saving" class="sp" role="status" aria-label="Menyimpan…"></div>
                  <svg v-else viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                  {{ store.saving ? 'Menyimpan…' : 'Simpan Asesmen' }}
                </button>

                <!-- Lewati Triase: pasien tidak perlu triase → antrean tetap lanjut -->
                <button
                  v-if="!isCpptMode && !store.isFinalized"
                  class="btn btn-secondary btn-lg"
                  :disabled="store.saving || store.skipping"
                  title="Pasien tidak perlu triase — antrean tetap lanjut ke stasiun berikutnya"
                  @click="onSkipTriase"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg>
                  {{ store.skipping ? 'Melewati…' : 'Tidak Perlu Triase' }}
                </button>

                <!-- Mode idle setelah finalized: Update Asesmen (tambah CPPT) -->
                <button
                  v-if="!isCpptMode && store.isFinalized"
                  class="btn btn-primary btn-lg"
                  :disabled="!visitActive"
                  :title="visitActive ? 'Tambah CPPT baru (observasi ulang)' : 'Kunjungan sudah selesai'"
                  @click="startNewCppt"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  Update Asesmen
                </button>
              </div>

              <!-- Banner mode CPPT -->
              <div v-if="isCpptMode" class="cppt-banner">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><circle cx="12" cy="16" r=".6" fill="currentColor"/></svg>
                <span v-if="cpptMode === 'new'">
                  Mode <strong>Tambah CPPT</strong> — isi observasi terbaru, lalu simpan. Asesmen awal tidak akan berubah.
                </span>
                <span v-else>
                  Mode <strong>Edit CPPT</strong> — koreksi entry CPPT. Jejak edit akan tercatat (waktu &amp; perawat).
                </span>
              </div>
            </div>
          </div>

          <!-- ── Kartu aksi: Kirim ke Dokter (visit REGULAR) ── -->
          <div v-if="!store.asesmenLoading && !isPreopBedah" class="card send-card">
            <div class="card-body send-card-body">
              <div class="send-card-info">
                <div class="send-card-title">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                  Kirim ke Dokter
                </div>
                <div class="send-card-sub">
                  <template v-if="store.isFinalized && store.doctorTicket">Pasien selesai Triase &amp; Refraksionis — antrean dokter <strong>{{ store.doctorTicket.queue_number }}</strong> dibuat. Cetak tiket pasien.</template>
                  <template v-else-if="store.isFinalized">Asesmen dikunci. Menunggu <strong>Refraksionis</strong> selesai sebelum tiket dokter bisa dicetak.</template>
                  <template v-else-if="!store.asesmen?.id">Simpan Tanda Vital terlebih dahulu sebelum mengirim.</template>
                  <template v-else>Klik untuk mengunci asesmen dan masukkan pasien ke antrean dokter.</template>
                </div>
              </div>
              <div class="send-actions">
                <button
                  class="btn btn-success btn-lg send-btn"
                  :disabled="!store.asesmen?.id || store.finalizing || store.isFinalized"
                  @click="sendToDoctor"
                >
                  <div v-if="store.finalizing" class="sp" role="status" aria-label="Mengirim…"></div>
                  <template v-else>
                    Kirim ke Dokter
                    <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                  </template>
                </button>
                <button
                  v-if="store.isFinalized"
                  type="button"
                  class="btn btn-secondary btn-lg send-btn"
                  :disabled="!store.doctorTicket"
                  :title="store.doctorTicket ? `Cetak tiket antrean ${store.doctorTicket.queue_number}` : 'Tombol aktif setelah Refraksionis juga selesai (antrean dokter dibuat)'"
                  @click="printDoctorTicket"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                  Cetak Tiket Dokter
                </button>
                <button
                  v-if="store.isFinalized && !isCpptMode"
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

          <!-- ── Instruksi Obat Pre-Op (dokter jaga, stat-dose — visit PREOP_BEDAH) ── -->
          <div v-if="!store.asesmenLoading && isPreopBedah" class="card preop-rx-card">
            <div class="card-body">
              <div class="preop-rx-head">
                <div class="send-card-title">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.5 20.5L3.5 13.5a5 5 0 117-7l7 7a5 5 0 11-7 7z"/><line x1="7" y1="10" x2="14" y2="17"/></svg>
                  Instruksi Obat Pre-Op
                  <span class="preop-pill">DOKTER JAGA</span>
                  <span v-if="store.preopResep?.sent" class="preop-rx-status" :class="{ ok: store.preopResep?.verified }">
                    {{ store.preopResep?.dispensing ? 'Diserahkan Farmasi' : (store.preopResep?.verified ? 'Terverifikasi Farmasi' : 'Menunggu verifikasi Farmasi') }}
                  </span>
                </div>
                <div class="send-card-sub">
                  <template v-if="preopEditable">
                    Obat dosis-sekali sebelum operasi (mis. obat tensi/gula). Tagihan masuk kwitansi kunjungan ini —
                    default <strong>ditagih di atas harga paket</strong>; centang <em>Terserap</em> bila ikut harga paket.
                  </template>
                  <template v-else-if="preopLocked">
                    Instruksi terkunci ({{ store.preopResep?.billing_paid ? 'pembayaran sudah dikonfirmasi' : 'obat sudah diserahkan Farmasi' }}).
                  </template>
                  <template v-else>
                    Hanya <strong>dokter</strong> (login dokter jaga) yang dapat mengubah instruksi.
                    <template v-if="store.preopResep?.prescriber"> Peresep: <strong>{{ store.preopResep.prescriber }}</strong>.</template>
                  </template>
                </div>
              </div>

              <!-- Picker obat (hanya mode dokter) -->
              <div v-if="preopEditable" class="preop-rx-picker">
                <input
                  v-model="preopObatSearch"
                  class="form-input"
                  type="text"
                  placeholder="Cari obat (min. 2 huruf) — stok Farmasi…"
                />
                <div v-if="preopObatLoading" class="preop-rx-hint">Mencari…</div>
                <ul v-else-if="preopObatResults.length" class="preop-rx-results">
                  <li v-for="m in preopObatResults" :key="m.id">
                    <button type="button" @click="addPreopObat(m)">
                      <strong>{{ m.name }}</strong>
                      <span>{{ m.form_sediaan ?? '' }} · stok {{ m.farmasi_qty ?? 0 }} {{ m.unit ?? '' }}</span>
                    </button>
                  </li>
                </ul>
              </div>

              <!-- Daftar item -->
              <table v-if="preopItems.length" class="preop-rx-table">
                <thead>
                  <tr>
                    <th>Obat</th><th>Qty</th><th>Dosis</th><th>Rute</th>
                    <th v-if="store.preopResep?.can_absorb" title="Terserap ke harga paket — tetap tampil di kwitansi, total = harga paket">Terserap</th>
                    <th v-if="preopEditable"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(it, i) in preopItems" :key="it.medication_id">
                    <td>{{ it.nama }} <span class="preop-rx-stat">stat</span></td>
                    <td><input v-model="it.jumlah" type="number" min="1" class="form-input preop-rx-qty" :disabled="!preopEditable" @input="preopDirty = true" /></td>
                    <td><input v-model="it.dosis" type="text" class="form-input" placeholder="mis. 1 tablet" :disabled="!preopEditable" @input="preopDirty = true" /></td>
                    <td><input v-model="it.route" type="text" class="form-input preop-rx-route" placeholder="oral/IV" :disabled="!preopEditable" @input="preopDirty = true" /></td>
                    <td v-if="store.preopResep?.can_absorb" class="preop-rx-absorb">
                      <input v-model="it.absorbed" type="checkbox" :disabled="!preopEditable" @change="preopDirty = true" />
                    </td>
                    <td v-if="preopEditable">
                      <button type="button" class="preop-rx-del" aria-label="Hapus" @click="removePreopObat(i)">×</button>
                    </td>
                  </tr>
                </tbody>
              </table>
              <div v-else class="preop-rx-hint">Belum ada instruksi obat pre-op.</div>

              <div v-if="preopEditable" class="preop-rx-actions">
                <button
                  class="btn btn-primary"
                  :disabled="store.savingPreopResep || (!preopDirty && store.preopResep?.sent)"
                  @click="savePreopInstr"
                >
                  <div v-if="store.savingPreopResep" class="sp" role="status" aria-label="Menyimpan…"></div>
                  <template v-else>Simpan & Kirim ke Farmasi</template>
                </button>
              </div>
            </div>
          </div>

          <!-- ── Kartu aksi: Kirim ke Bedah (visit PREOP_BEDAH, non-inap) ── -->
          <div v-if="!store.asesmenLoading && isPreopBedah && !isPreopRanap" class="card send-card preop-send-card">
            <div class="card-body send-card-body">
              <div class="send-card-info">
                <div class="send-card-title">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 14H5m14-4H5m14 8H5"/><circle cx="12" cy="6" r="2"/></svg>
                  Kirim ke Bedah
                  <span class="preop-pill">PREOP</span>
                </div>
                <div class="send-card-sub">
                  <template v-if="!store.asesmen?.id">Simpan Tanda Vital terlebih dahulu.</template>
                  <template v-else-if="!store.isFinalized">Finalize asesmen triase dulu (klik <strong>Simpan Asesmen</strong> &amp; pastikan TD + keluhan terisi).</template>
                  <template v-else-if="!parallelRefraksiDone">Asesmen triase dikunci ✓. Menunggu <strong>Refraksionis</strong> menyelesaikan pemeriksaan visus/IOP.</template>
                  <template v-else>Triase &amp; Refraksi selesai ✓. <strong>Kirim ke Bedah</strong> (langsung antre operasi) atau <strong>Kirim ke Dokter</strong> bila operator perlu memeriksa ulang dulu.</template>
                </div>
              </div>
              <div class="send-actions">
                <button
                  class="btn btn-secondary btn-lg send-btn"
                  :disabled="!canKirimKeDokter || store.sendingDokter"
                  :title="canKirimKeDokter ? 'Kirim pasien ke antrean Dokter operator (periksa ulang sebelum operasi)' : 'Tunggu Triase + Refraksi selesai'"
                  @click="onKirimKeDokter"
                >
                  <div v-if="store.sendingDokter" class="sp" role="status" aria-label="Mengirim…"></div>
                  <template v-else>
                    Kirim ke Dokter
                    <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                  </template>
                </button>
                <button
                  class="btn btn-success btn-lg send-btn"
                  :disabled="!canKirimKeBedah || store.sendingBedah"
                  :title="canKirimKeBedah ? 'Kirim pasien ke antrean Bedah' : 'Tunggu Triase + Refraksi selesai'"
                  @click="onKirimKeBedah"
                >
                  <div v-if="store.sendingBedah" class="sp" role="status" aria-label="Mengirim…"></div>
                  <template v-else>
                    Kirim ke Bedah
                    <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                  </template>
                </button>
              </div>
            </div>
          </div>

          <!-- ── Kartu aksi: Kirim ke Rawat Inap (Fase 8B — pre-op inap H-1) ── -->
          <div v-if="!store.asesmenLoading && isPreopRanap" class="card send-card preop-send-card">
            <div class="card-body send-card-body">
              <div class="send-card-info">
                <div class="send-card-title">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 18v-6a2 2 0 012-2h14a2 2 0 012 2v6"/><path d="M3 18h18"/><path d="M7 10V7a1 1 0 011-1h3a1 1 0 011 1v3"/></svg>
                  Kirim ke Rawat Inap
                  <span class="preop-pill">PRE-OP INAP</span>
                </div>
                <div class="send-card-sub">
                  <template v-if="!store.asesmen?.id">Simpan Tanda Vital terlebih dahulu.</template>
                  <template v-else-if="!store.isFinalized">Finalize asesmen triase dulu (klik <strong>Simpan Asesmen</strong> &amp; pastikan TD + keluhan terisi).</template>
                  <template v-else-if="!parallelRefraksiDone">Asesmen triase dikunci ✓. Menunggu <strong>Refraksionis</strong> menyelesaikan pemeriksaan visus/IOP.</template>
                  <template v-else>Triase &amp; Refraksi selesai ✓. Klik <strong>Kirim ke Rawat Inap</strong> — pasien masuk papan <strong>Menunggu Kamar</strong> untuk persiapan pre-op.</template>
                </div>
              </div>
              <div class="send-actions">
                <button
                  class="btn btn-success btn-lg send-btn"
                  :disabled="!canKirimKeRanap || store.sendingRanap"
                  :title="canKirimKeRanap ? 'Kirim pasien ke papan Menunggu Kamar' : 'Tunggu Triase + Refraksi selesai'"
                  @click="onKirimKeRanap"
                >
                  <div v-if="store.sendingRanap" class="sp" role="status" aria-label="Mengirim…"></div>
                  <template v-else>
                    Kirim ke Rawat Inap
                    <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                  </template>
                </button>
              </div>
            </div>
          </div>

        </template>
      </section>

      <!-- ══════════════════ SOAP SIDEBAR ══════════════════ -->
      <aside class="col-soap">
        <button class="side-rail" @click="toggleSide" title="Buka CPPT / SOAP" aria-label="Buka CPPT / SOAP">
          <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
          <span class="side-rail-txt">CPPT / SOAP</span>
        </button>
        <div v-if="!store.selectedQueue" class="soap-ghost"></div>

        <div v-else class="soap-sticky">
          <div class="card soap-card">
            <div class="card-head">
              <div class="card-head-title">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                CPPT / SOAP
              </div>
              <div class="head-actions">
                <span class="soap-count">{{ (store.cpptEntries ?? []).length }} CPPT</span>
                <button class="panel-collapse" @click="toggleSide" title="Ciutkan CPPT / SOAP" aria-label="Ciutkan CPPT / SOAP">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
              </div>
            </div>

            <div class="soap-body">

              <!-- ─── HEADER: Asesmen Awal Triase ─── -->
              <div class="soap-asesmen-awal">
                <div class="cppt-time">
                  <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                  <span class="cppt-time-label">Asesmen Awal Triase</span>
                  <span v-if="store.asesmen?.finalized_at" class="cppt-time-stamp">{{ fmtDateTime(store.asesmen.finalized_at) }}</span>
                  <span v-else-if="store.asesmen?.created_at" class="cppt-time-stamp">{{ fmtDateTime(store.asesmen.created_at) }}</span>
                  <span v-if="store.asesmen?.is_finalized" class="soap-locked">Final</span>
                </div>

                <div v-if="store.asesmen" class="soap-section compact">
                  <div class="soap-label s">S <span>Subjektif</span></div>
                  <div class="soap-row" v-if="store.asesmen.chief_complaint">
                    <span class="soap-key">Keluhan</span>
                    <span class="soap-val">{{ store.asesmen.chief_complaint }}</span>
                  </div>
                  <div class="soap-row" v-if="store.asesmen.rps">
                    <span class="soap-key">RPS</span>
                    <span class="soap-val">{{ store.asesmen.rps }}</span>
                  </div>
                  <div class="soap-row" v-if="store.asesmen.allergy_detail">
                    <span class="soap-key">Alergi</span>
                    <span class="soap-val soap-allergy">⚠ {{ store.asesmen.allergy_detail }}</span>
                  </div>
                </div>

                <div v-if="store.asesmen" class="soap-section compact">
                  <div class="soap-label o">O <span>Objektif</span></div>
                  <div class="soap-vitals">
                    <div class="sv-item">
                      <span class="sv-k">TD</span>
                      <span class="sv-v">{{ fmtNum(store.asesmen.td_sistol) }}/{{ fmtNum(store.asesmen.td_diastol) }}<em> mmHg</em></span>
                    </div>
                    <div class="sv-item" v-if="store.asesmen.kgd">
                      <span class="sv-k">KGD</span>
                      <span class="sv-v">{{ fmtNum(store.asesmen.kgd) }}<em> mg/dL</em></span>
                    </div>
                    <div class="sv-item" v-if="store.asesmen.nadi">
                      <span class="sv-k">Nadi</span>
                      <span class="sv-v">{{ fmtNum(store.asesmen.nadi) }}<em> bpm</em></span>
                    </div>
                    <div class="sv-item" v-if="store.asesmen.spo2">
                      <span class="sv-k">SpO₂</span>
                      <span class="sv-v">{{ fmtNum(store.asesmen.spo2) }}%</span>
                    </div>
                    <div class="sv-item" v-if="store.asesmen.suhu">
                      <span class="sv-k">Suhu</span>
                      <span class="sv-v">{{ fmtNum(store.asesmen.suhu) }}°C</span>
                    </div>
                    <div class="sv-item" v-if="store.asesmen.respirasi">
                      <span class="sv-k">Resp</span>
                      <span class="sv-v">{{ fmtNum(store.asesmen.respirasi) }}<em> /mnt</em></span>
                    </div>
                    <div class="sv-item" v-if="store.asesmen.assessment_notes" style="grid-column: 1/-1">
                      <span class="sv-k">Catatan</span>
                      <span class="sv-v">{{ store.asesmen.assessment_notes }}</span>
                    </div>
                  </div>
                </div>

                <div v-if="!store.asesmen" class="soap-placeholder">Asesmen awal belum tersedia</div>
              </div>

              <!-- ─── Divider ─── -->
              <div v-if="(store.cpptEntries ?? []).length" class="cppt-divider">
                <span>CPPT Lanjutan ({{ (store.cpptEntries ?? []).length }})</span>
              </div>

              <!-- ─── TIMELINE CPPT (descending) ─── -->
              <div v-for="entry in (store.cpptEntries ?? [])" :key="entry.id" class="cppt-entry">
                <div class="cppt-time">
                  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 15"/></svg>
                  <span class="cppt-time-stamp">{{ fmtDateTime(entry.created_at) }}</span>
                  <span class="cppt-by" v-if="entry.created_by?.name">· {{ entry.created_by.name }}</span>
                  <span v-if="entry.signed_at" class="cppt-signed" :title="`Ditandatangani ${fmtDateTime(entry.signed_at)}${entry.signed_by?.name ? ' · ' + entry.signed_by.name : ''}`">✓ Ditandatangani</span>
                  <span v-if="entry.edited_at" class="cppt-edited" :title="`Diedit ${fmtDateTime(entry.edited_at)} oleh ${entry.edited_by?.name ?? '—'}`">Diedit</span>

                  <button
                    v-if="visitActive"
                    class="cppt-edit-btn"
                    :disabled="isCpptMode"
                    :title="isCpptMode ? 'Batalkan mode CPPT dulu' : 'Edit CPPT entry ini'"
                    @click="startEditCppt(entry)"
                  >
                    <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 113 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                    Edit
                  </button>
                  <button
                    v-if="visitActive && !entry.signed_at"
                    class="cppt-sign-btn"
                    :disabled="isCpptMode"
                    title="Tanda tangani CPPT ini (PIN)"
                    @click="openSignCppt(entry)"
                  >
                    <svg viewBox="0 0 24 24"><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg>
                    TTD
                  </button>
                </div>

                <div class="soap-section compact">
                  <div class="soap-vitals">
                    <div class="sv-item" v-if="entry.td_sistol && entry.td_diastol">
                      <span class="sv-k">TD</span>
                      <span class="sv-v">{{ fmtNum(entry.td_sistol) }}/{{ fmtNum(entry.td_diastol) }}<em> mmHg</em></span>
                    </div>
                    <div class="sv-item" v-if="entry.kgd">
                      <span class="sv-k">KGD</span>
                      <span class="sv-v">{{ fmtNum(entry.kgd) }}<em> mg/dL</em></span>
                    </div>
                    <div class="sv-item" v-if="entry.nadi">
                      <span class="sv-k">Nadi</span>
                      <span class="sv-v">{{ fmtNum(entry.nadi) }}<em> bpm</em></span>
                    </div>
                    <div class="sv-item" v-if="entry.spo2">
                      <span class="sv-k">SpO₂</span>
                      <span class="sv-v">{{ fmtNum(entry.spo2) }}%</span>
                    </div>
                    <div class="sv-item" v-if="entry.suhu">
                      <span class="sv-k">Suhu</span>
                      <span class="sv-v">{{ fmtNum(entry.suhu) }}°C</span>
                    </div>
                    <div class="sv-item" v-if="entry.respirasi">
                      <span class="sv-k">Resp</span>
                      <span class="sv-v">{{ fmtNum(entry.respirasi) }}<em> /mnt</em></span>
                    </div>
                  </div>
                  <div v-if="entry.notes" class="cppt-notes">{{ entry.notes }}</div>
                  <div v-if="entry.soap_s" class="cppt-soap"><b class="s">S</b> {{ entry.soap_s }}</div>
                  <div v-if="entry.soap_a" class="cppt-soap"><b class="a">A</b> {{ entry.soap_a }}</div>
                  <div v-if="entry.soap_p" class="cppt-soap"><b class="p">P</b> {{ entry.soap_p }}</div>
                </div>
              </div>

              <!-- ─── Empty state CPPT ─── -->
              <div v-if="store.asesmen && !(store.cpptEntries ?? []).length" class="cppt-empty">
                Belum ada CPPT lanjutan. Klik <strong>Update Asesmen</strong> untuk menambah observasi.
              </div>

            </div>
          </div>

          <!-- Riwayat CPPT/SOAP lintas-episode (semua PPA: Perawat/Refraksionis/Dokter) -->
          <CpptHistoryCard ref="cpptCardRef" :patient-id="store.selectedPatientId" :fetcher="perawatApi.riwayatCppt" style="margin-top:0.75rem" />
        </div>
      </aside>

    </div>

    <!-- ══════════════════ REKAM MEDIS MODAL ══════════════════ -->
    <Teleport to="body">
      <div v-if="rekamMedisOpen" class="modal-overlay" role="dialog" aria-modal="true" aria-label="Rekam Medis Pasien" @click.self="rekamMedisOpen = false">
        <div class="modal">
          <div class="modal-head">
            <div class="modal-title">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              Rekam Medis — {{ store.selectedQueue?.patient?.name }}
            </div>
            <button class="modal-close" @click="rekamMedisOpen = false" aria-label="Tutup">×</button>
          </div>

          <div class="modal-body">
            <!-- Loading -->
            <div v-if="store.rekamMedisLoading" class="modal-loading">
              <div class="sp lg" role="status" aria-label="Memuat rekam medis…"></div>
              Memuat rekam medis…
            </div>

            <!-- Error -->
            <div v-else-if="store.rekamMedisError" class="modal-error">
              {{ store.rekamMedisError }}
            </div>

            <!-- Content: Document detail view -->
            <div v-else-if="viewingDokumen && store.selectedDokumen" class="dokumen-detail">
              <button class="back-btn" @click="viewingDokumen = false">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
                Kembali ke daftar
              </button>
              <div v-if="store.dokumenLoading" class="modal-loading">
                <div class="sp" role="status"></div>Memuat dokumen…
              </div>
              <template v-else>
                <div class="dok-header">
                  <div>
                    <div class="dok-title">{{ store.selectedDokumen.documentType?.name ?? '—' }}</div>
                    <div class="dok-meta">
                      No: {{ store.selectedDokumen.document_number ?? 'Draft' }}
                      · {{ fmtDate(store.selectedDokumen.finalized_at ?? store.selectedDokumen.created_at) }}
                      · Dibuat di: {{ store.selectedDokumen.created_by_station }}
                    </div>
                  </div>
                  <span :class="['status-badge', `sb-${(store.selectedDokumen.status ?? '').toLowerCase()}`]">
                    {{ store.selectedDokumen.status }}
                  </span>
                </div>
                <div v-if="store.selectedDokumen.visit" class="dok-visit-info">
                  Kunjungan: {{ fmtDate(store.selectedDokumen.visit.visit_date) }}
                  · {{ store.selectedDokumen.visit.classification }}
                  · {{ store.selectedDokumen.visit.guarantor_type }}
                </div>
                <div class="dok-signatures" v-if="store.selectedDokumen.signatures?.length">
                  <div class="sec-title">Tanda Tangan</div>
                  <div v-for="(sig, i) in store.selectedDokumen.signatures" :key="i" class="sig-row">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                    {{ sig.role }} — {{ sig.name }} · {{ fmtDate(sig.signed_at) }}
                  </div>
                </div>
                <div v-if="store.selectedDokumen.reject_reason" class="dok-reject">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                  Ditolak: {{ store.selectedDokumen.reject_reason }}
                </div>
              </template>
            </div>

            <!-- Content: Document list -->
            <div v-else class="modal-sections">

              <!-- Documents -->
              <div class="modal-section">
                <div class="ms-title">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  Dokumen Medis
                  <span class="ms-ct">{{ store.rekamMedis.documents?.length ?? 0 }}</span>
                </div>
                <div v-if="!store.rekamMedis.documents?.length" class="ms-empty">Belum ada dokumen</div>
                <div v-else class="doc-list">
                  <div v-for="doc in store.rekamMedis.documents" :key="doc.id" class="doc-item" @click="viewDokumen(doc.id)" tabindex="0" @keydown.enter="viewDokumen(doc.id)">
                    <div class="doc-icon">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div class="doc-info">
                      <div class="doc-name">{{ doc.documentType?.name ?? 'Dokumen' }}</div>
                      <div class="doc-meta">
                        {{ doc.documentType?.code }} · {{ fmtDate(doc.finalized_at ?? doc.created_at) }}
                        <span v-if="doc.visit?.classification"> · {{ doc.visit.classification }}</span>
                      </div>
                    </div>
                    <span :class="['status-badge', `sb-${(doc.status ?? '').toLowerCase()}`]">{{ doc.status }}</span>
                    <svg class="doc-arrow" viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                  </div>
                </div>
              </div>

              <!-- Vital history in modal -->
              <div class="modal-section">
                <div class="ms-title">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                  Riwayat TTV
                  <span class="ms-ct">{{ store.rekamMedis.vital_history?.length ?? 0 }}</span>
                </div>
                <div v-if="!store.rekamMedis.vital_history?.length" class="ms-empty">Belum ada riwayat TTV</div>
                <div v-else class="vh-table">
                  <div class="vh-row vh-header">
                    <span>Tanggal</span><span>Klasifikasi</span><span>TD</span><span>N</span><span>SpO₂</span><span>Suhu</span><span>KGD</span>
                  </div>
                  <div v-for="h in store.rekamMedis.vital_history" :key="h.id" class="vh-row">
                    <span>{{ fmtDate(h.visit?.visit_date ?? h.created_at) }}</span>
                    <span><span :class="['cls-badge', clsCls(h.visit?.classification)]">{{ h.visit?.classification ?? '—' }}</span></span>
                    <span>{{ h.td_sistol }}/{{ h.td_diastol }}</span>
                    <span>{{ h.nadi }}</span>
                    <span>{{ h.spo2 ?? '—' }}%</span>
                    <span>{{ h.suhu ?? '—' }}°C</span>
                    <span>{{ h.kgd ?? '—' }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ══════════════════ MODAL PIN TANDA TANGAN CPPT ══════════════════ -->
    <Teleport to="body">
      <div v-if="signingEntry" class="pin-overlay" role="dialog" aria-modal="true" @click.self="signingEntry = null">
        <div class="pin-modal">
          <h4 class="pin-title">Tanda Tangan CPPT</h4>
          <p class="pin-hint">Masukkan PIN untuk menandatangani entri CPPT ini sebagai <b>{{ store.selectedQueue?.patient?.name }}</b>.</p>
          <input
            v-model="pinValue"
            type="password"
            inputmode="numeric"
            maxlength="6"
            class="pin-input"
            placeholder="••••••"
            autocomplete="off"
            @keyup.enter="confirmSignPin()"
          />
          <div v-if="pinError" class="pin-err">{{ pinError }}</div>
          <div class="pin-actions">
            <button type="button" class="btn btn-secondary btn-sm" :disabled="pinBusy" @click="signingEntry = null">Batal</button>
            <button type="button" class="btn btn-primary btn-sm" :disabled="pinBusy" @click="confirmSignPin()">
              {{ pinBusy ? 'Memproses…' : 'Tanda Tangani' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ══════════════════ TOAST ══════════════════ -->
    <div class="toast-wrap" aria-live="polite" aria-atomic="false" role="status">
      <div v-for="t in toasts" :key="t.id" :class="['toast', `toast-${t.type}`]">{{ t.msg }}</div>
    </div>
  </div>
</template>

<style scoped>
/* ── Layout ──────────────────────────────────────────────────────────────── */
.perawat { padding: 0; }
.main-grid { display: grid; grid-template-columns: 320px minmax(0, 1fr) 300px; gap: 1rem; align-items: start; transition: grid-template-columns .2s ease; }
.main-grid.q-collapsed { grid-template-columns: 52px minmax(0, 1fr) 300px; }
.main-grid.s-collapsed { grid-template-columns: 320px minmax(0, 1fr) 52px; }
.main-grid.q-collapsed.s-collapsed { grid-template-columns: 52px minmax(0, 1fr) 52px; }
/* Form lega: batasi lebar & pusatkan saat ruang berlebih */
.col-form { min-width: 0; }
.col-form > * { max-width: 1040px; margin-inline: auto; }

/* Rail (panel diciutkan) + tombol ciutkan */
.col-queue .queue-rail, .col-soap .side-rail { display: none; }
.main-grid.q-collapsed .col-queue .queue-card { display: none; }
.main-grid.q-collapsed .col-queue .queue-rail { display: flex; }
.main-grid.s-collapsed .col-soap .soap-ghost,
.main-grid.s-collapsed .col-soap .soap-sticky { display: none; }
.main-grid.s-collapsed .col-soap .side-rail { display: flex; }
.queue-rail, .side-rail { position: sticky; top: 0.5rem; width: 52px; min-height: 128px; flex-direction: column; align-items: center; gap: 9px; padding: 13px 4px; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; cursor: pointer; color: var(--tm); font-family: 'Inter', sans-serif; transition: all .13s; }
.queue-rail:hover, .side-rail:hover { border-color: var(--ga); color: var(--ga); }
.queue-rail svg, .side-rail svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.queue-rail-count { font-size: 14px; font-weight: 700; color: var(--ga); font-variant-numeric: tabular-nums; }
.queue-rail-txt, .side-rail-txt { writing-mode: vertical-rl; text-orientation: mixed; font-size: 11px; font-weight: 600; letter-spacing: 0.05em; }
.head-actions { display: flex; align-items: center; gap: 6px; }
.panel-collapse { width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--gb); border-radius: 7px; background: var(--bs); color: var(--tu); cursor: pointer; transition: all .13s; flex-shrink: 0; }
.panel-collapse:hover { border-color: var(--ga); color: var(--ga); }
.panel-collapse svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

/* Responsif: stack 1 kolom di layar sempit (tanpa scroll horizontal) */
@media (max-width: 1180px) {
  .main-grid, .main-grid.q-collapsed, .main-grid.s-collapsed, .main-grid.q-collapsed.s-collapsed { grid-template-columns: 1fr; }
  .col-queue .queue-rail, .col-soap .side-rail, .panel-collapse { display: none !important; }
  .col-queue .queue-card { display: block !important; }
  .main-grid.s-collapsed .col-soap .soap-ghost, .main-grid.s-collapsed .col-soap .soap-sticky { display: block !important; }
  .col-form > * { max-width: none; }
}

/* ── Card ────────────────────────────────────────────────────────────────── */
.card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }
.card-head { padding: 0.85rem 1.1rem; border-bottom: 1px solid var(--gb); display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.card-head-title { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--td); }
.card-head-title svg { width: 14px; height: 14px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.card-head-sub { font-size: 11px; color: var(--tu); margin-top: 3px; }
.card-body { padding: 1rem; }
.card-body.stack { display: flex; flex-direction: column; gap: 0.75rem; }

/* ── Stats bar ───────────────────────────────────────────────────────────── */
.stats-bar { display: flex; align-items: center; background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 8px 12px; margin-bottom: 0.65rem; gap: 0; }
.stat-item { flex: 1; text-align: center; }
.stat-divider { width: 1px; height: 28px; background: var(--gb); flex-shrink: 0; }
.stat-label { display: block; font-size: 9.5px; color: var(--tu); letter-spacing: 0.03em; margin-bottom: 2px; }
.stat-num { display: block; font-size: 17px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; }
.stat-waiting { color: #d97706; }
.stat-done { color: var(--st); }

/* ── Primary filter ──────────────────────────────────────────────────────── */
.primary-filter { display: flex; gap: 4px; margin-bottom: 0.5rem; }
.pf-btn { flex: 1; height: 32px; font-size: 11.5px; font-weight: 500; border: 1.5px solid var(--gb); border-radius: 8px; background: var(--bs); color: var(--tm); cursor: pointer; font-family: 'Inter', sans-serif; transition: all .13s; display: flex; align-items: center; justify-content: center; gap: 5px; }
.pf-btn:hover { border-color: var(--ga); color: var(--ga); }
.pf-btn.a { background: var(--gd); color: #fff; border-color: var(--gd); }
.pf-ct { font-size: 9px; font-weight: 700; padding: 0 5px; border-radius: 10px; background: rgba(255,255,255,.25); }

/* ── Secondary filter ────────────────────────────────────────────────────── */
.ptype-tabs { display: flex; gap: 3px; margin-bottom: 0.55rem; }
.ptype-tab { flex: 1; padding: 5px 4px; font-size: 10px; font-weight: 600; border: 1.5px solid var(--gb); border-radius: 7px; background: var(--bs); color: var(--tu); cursor: pointer; font-family: 'Inter',sans-serif; text-align: center; transition: all .13s; white-space: nowrap; }
.ptype-tab:hover { border-color: var(--ga); color: var(--ga); }
.ptype-tab.a { color: #fff; font-weight: 700; }
.ptype-bpjs.a { background: #1d4ed8; border-color: #1d4ed8; }
.ptype-umum.a { background: var(--ga); border-color: var(--ga); }

/* ── Queue scroll ────────────────────────────────────────────────────────── */
.pill-live { font-size: 9.5px; font-weight: 700; padding: 2px 8px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 20px; letter-spacing: 0.05em; }
.queue-scroll { padding: 0.6rem; max-height: calc(100vh - 200px); overflow-y: auto; }
.q-search-wrap { margin-bottom: 0.5rem; }
.q-search { width: 100%; height: 30px; font-size: 11.5px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 10px; background: var(--bs); font-family: 'Inter', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.q-search:focus { border-color: var(--ga); background: #fff; }

/* ── Skeleton loader ─────────────────────────────────────────────────────── */
.q-skeleton { height: 68px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; margin-bottom: 5px; animation: shimmer 1.2s ease-in-out infinite; }
@keyframes shimmer { 0%,100% { opacity: 1; } 50% { opacity: .4; } }

/* ── Empty / error states ────────────────────────────────────────────────── */
.empty-section { text-align: center; padding: 0.75rem 1rem; font-size: 11px; color: var(--th); background: var(--bi); border-radius: 7px; margin-bottom: 6px; border: 1px dashed var(--gb); }
.empty-section.err { display: flex; flex-direction: column; align-items: center; gap: 4px; color: var(--et); background: var(--eb); border-color: var(--ebd); }
.empty-section.err svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.retry-btn { margin-top: 4px; padding: 2px 10px; font-size: 10px; border: 1px solid var(--et); border-radius: 5px; background: none; color: var(--et); cursor: pointer; font-family: 'Inter',sans-serif; }

/* ── Queue item ──────────────────────────────────────────────────────────── */
.q-item { display: flex; gap: 8px; padding: 8px 10px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; margin-bottom: 5px; cursor: pointer; transition: all 0.14s; width: 100%; text-align: left; font-family: 'Inter', sans-serif; flex-wrap: wrap; }
.q-item:hover { border-color: var(--lm); background: var(--gl); }
.q-item.active { border-color: var(--ga); background: var(--gl); }
.q-item.done { opacity: .55; }
.q-item:focus-visible { outline: 2px solid var(--ga); outline-offset: 2px; }
.qi-left { display: flex; flex-direction: column; gap: 4px; min-width: 56px; }
.q-num { font-weight: 700; font-size: 13.5px; color: var(--ga); letter-spacing: 0.03em; }
.q-info { flex: 1; min-width: 0; }
.q-name { font-size: 12.5px; font-weight: 500; color: var(--td); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.q-meta { font-size: 10px; color: var(--tu); margin-top: 2px; }
.q-visit-date { display: inline-flex; align-items: center; gap: 4px; margin-left: 6px; font-size: 10px; font-weight: 600; color: #0f766e; background: #ccfbf1; padding: 1px 6px; border-radius: 6px; white-space: nowrap; vertical-align: middle; }
.q-visit-date .pill-icon { width: 11px; height: 11px; flex: 0 0 auto; fill: none; stroke: currentColor; stroke-width: 2; }
.q-dpjp { font-size: 10px; color: #0e3a66; font-weight: 600; margin-top: 2px; display: flex; align-items: center; gap: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.q-dpjp-ic { width: 10px; height: 10px; flex-shrink: 0; }
.q-tags { display: flex; gap: 3px; margin-top: 3px; flex-wrap: wrap; }
.qi-time { font-size: 10px; color: var(--tu); font-variant-numeric: tabular-nums; }

.q-actions { display: flex; gap: 4px; margin-top: 5px; padding-top: 5px; border-top: 1px dashed var(--gb); width: 100%; }
.q-act-btn { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; font-size: 10px; font-weight: 600; border-radius: 5px; border: 1px solid; cursor: pointer; font-family: 'Inter',sans-serif; transition: background .12s, color .12s, border-color .12s, transform .07s, box-shadow .07s; background: none; user-select: none; }
.q-act-btn svg { width: 10px; height: 10px; }
.q-act-btn.call { color: var(--ga); border-color: var(--ga); background: var(--gl); }
.q-act-btn.call:hover { background: var(--ga); color: #fff; }
.q-act-btn.call.recall { color: #b45309; border-color: #fbbf24; background: #fef3c7; }
.q-act-btn.call.recall:hover { background: #f59e0b; color: #fff; border-color: #f59e0b; }
.q-act-btn.skip { color: var(--tu); border-color: var(--gb); }
.q-act-btn.skip:hover { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.q-act-btn.bump { color: var(--ga); border-color: var(--gb); }
.q-act-btn.bump:hover { background: var(--ga); color: #fff; border-color: var(--ga); }
.q-act-btn.bump.bump-risk { color: #b45309; border-color: #fbbf24; background: #fef3c7; }
.q-act-btn.bump.bump-risk:hover { background: #f59e0b; color: #fff; border-color: #f59e0b; }
.q-act-btn:disabled { opacity: .5; cursor: not-allowed; }
.q-act-btn:active:not(:disabled) { transform: scale(0.93); box-shadow: inset 0 1px 3px rgba(0,0,0,.12); }
.q-act-btn.call:active:not(:disabled) { background: var(--gd); color: #fff; border-color: var(--gd); }
.q-act-btn.call.recall:active:not(:disabled) { background: #b45309; color: #fff; border-color: #b45309; }
.q-act-btn.skip:active:not(:disabled) { background: var(--wt); color: #fff; border-color: var(--wt); }
.q-act-btn:disabled { opacity: .55; cursor: wait; }

/* ── Pills ───────────────────────────────────────────────────────────────── */
.pill { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px; }
.pill-icon { width: 8px; height: 8px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; flex-shrink: 0; }
.pill-waiting     { background: #fef3c7; color: #92400e; }
.pill-called      { background: #dbeafe; color: #1e40af; }
.pill-in_progress { background: #dbeafe; color: #1e40af; }
.pill-completed   { background: var(--sb); color: var(--st); }
.pill-bpjs  { background: #dbeafe; color: #1e40af; }
.pill-umum  { background: var(--gl); color: var(--ga); }
.pill-done  { background: var(--sb); color: var(--st); }
.pill-risk  { background: #fef3c7; color: #b45309; border: 1px solid #fbbf24; }
.pill-sibling { background: #fef3c7; color: #92400e; }

/* ── Classification colors ───────────────────────────────────────────────── */
.cls-baru    { background: #dbeafe; color: #1e40af; }
.cls-preop   { background: #fef3c7; color: #92400e; }
.cls-postop  { background: var(--sb); color: var(--st); }
.cls-kontrol { background: #f3e8ff; color: #7e22ce; }
.cls-badge   { font-size: 9px; font-weight: 700; padding: 1px 5px; border-radius: 4px; }

/* ── Empty card ──────────────────────────────────────────────────────────── */
.empty-card { min-height: 400px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1rem; padding: 2rem; color: var(--th); text-align: center; }
.empty-card svg { width: 56px; height: 56px; fill: none; stroke: var(--gb); stroke-width: 1.5; stroke-linecap: round; }
.empty-card p { font-size: 13.5px; line-height: 1.7; }

/* ── Patient header ──────────────────────────────────────────────────────── */
.pt-header { display: flex; align-items: flex-start; gap: 0.85rem; padding: 0.85rem 1.1rem; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; margin-bottom: 0.75rem; flex-wrap: wrap; }
.pt-avatar { width: 44px; height: 44px; border-radius: 50%; background: var(--gl); color: var(--ga); font-weight: 700; font-size: 18px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; }
.pt-info { flex: 1; min-width: 0; }
.pt-name { font-family: 'Space Grotesk', serif; font-size: 18px; color: var(--td); font-weight: 400; line-height: 1.1; }
.pt-meta { font-size: 11px; color: var(--tu); margin-top: 3px; }
.pt-address { font-size: 10.5px; color: var(--tu); margin-top: 3px; display: flex; align-items: center; gap: 4px; }
.pt-address svg { width: 10px; height: 10px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.pt-dpjp { font-size: 11px; color: #0e3a66; font-weight: 600; margin-top: 3px; display: flex; align-items: center; gap: 4px; }
.pt-dpjp svg { width: 11px; height: 11px; flex-shrink: 0; }
.pt-badges { display: flex; gap: 4px; margin-top: 5px; flex-wrap: wrap; }
.ptg { font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 4px; }
.ptg-b { background: #dbeafe; color: #1e40af; }
.ptg-a { background: var(--wb); color: var(--wt); }
.ptg-u { background: var(--gl); color: var(--ga); }
.ptg-preop { display: inline-flex; align-items: center; gap: 4px; background: #fef3c7; color: #92400e; border: 1px solid #fbbf24; }
.ptg-preop svg { stroke: currentColor; flex-shrink: 0; }

.pt-right { display: flex; flex-direction: column; gap: 5px; align-items: flex-end; margin-left: auto; }
.pt-vitals { display: flex; gap: 0.85rem; width: 100%; padding-top: 0.6rem; border-top: 1px dashed var(--gb); }
.pvi { font-size: 10.5px; color: var(--tu); }
.pvi b { display: block; font-size: 14px; font-weight: 700; color: var(--td); font-variant-numeric: tabular-nums; }
.pt-refraksi { display: flex; align-items: center; gap: 0.85rem; width: 100%; padding-top: 0.6rem; border-top: 1px dashed var(--gb); }
.pt-rfx-tag { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ga); background: var(--gl); padding: 3px 8px; border-radius: 5px; align-self: center; }
.pvi-u { font-size: 9px; font-weight: 600; color: var(--tu); }

.saved-pill { display: inline-flex; align-items: center; gap: 4px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); font-size: 10px; font-weight: 600; padding: 3px 8px; border-radius: 20px; }
.saved-pill.saved-draft { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.saved-pill svg { width: 10px; height: 10px; }

/* ── Vital history accordion ─────────────────────────────────────────────── */
.history-panel { background: var(--bc); border: 1px solid var(--gb); border-radius: 10px; margin-bottom: 0.75rem; overflow: hidden; }
.history-toggle { width: 100%; display: flex; align-items: center; gap: 6px; padding: 0.65rem 0.9rem; font-size: 12px; font-weight: 600; color: var(--td); background: none; border: none; cursor: pointer; font-family: 'Inter', sans-serif; text-align: left; }
.history-toggle svg { width: 13px; height: 13px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.history-ct { font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 10px; background: var(--gl); color: var(--ga); margin-left: 2px; }
.chevron { width: 13px; height: 13px; fill: none; stroke: var(--tu); stroke-width: 2; margin-left: auto; transition: transform .18s; }
.chevron.up { transform: rotate(180deg); }
.history-loading { display: flex; align-items: center; gap: 8px; padding: 1rem; font-size: 11.5px; color: var(--tu); }
.history-body { border-top: 1px solid var(--gb); }
.history-list { max-height: 220px; overflow-y: auto; }
.history-item { padding: 0.65rem 0.9rem; border-bottom: 1px solid var(--gb); }
.history-item:last-child { border-bottom: none; }
.hi-date { display: flex; align-items: center; gap: 6px; font-size: 10.5px; color: var(--tu); margin-bottom: 4px; }
.hi-cls { font-size: 9px; font-weight: 700; padding: 1px 5px; border-radius: 4px; }
.hi-vitals { display: flex; gap: 10px; flex-wrap: wrap; font-size: 11px; color: var(--tm); }
.hi-vitals b { color: var(--td); }
.hi-complaint { margin-top: 4px; font-size: 10.5px; color: var(--tu); display: flex; align-items: flex-start; gap: 4px; }
.hi-complaint svg { width: 10px; height: 10px; fill: none; stroke: currentColor; stroke-width: 2; flex-shrink: 0; margin-top: 1px; }

/* ── Tabs ────────────────────────────────────────────────────────────────── */
.tabs { display: flex; background: var(--bc); border: 1px solid var(--gb); border-radius: 10px 10px 0 0; border-bottom: none; padding: 0 0.5rem; }
.tab { padding: 0.7rem 0.9rem; font-size: 12px; font-weight: 500; color: var(--tu); background: none; border: none; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: 'Inter', sans-serif; }
.tab.a { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }
.tab:hover { color: var(--td); }
.tabs + .card { border-top-left-radius: 0; border-top-right-radius: 0; }

.asesmen-loading { display: flex; align-items: center; gap: 10px; padding: 1.5rem; font-size: 12px; color: var(--tu); min-height: 120px; justify-content: center; }

/* ── Form ────────────────────────────────────────────────────────────────── */
.sec-title { font-size: 11px; font-weight: 600; color: var(--tm); letter-spacing: 0.06em; text-transform: uppercase; margin: 0.85rem 0 0.55rem; }
.sec-title:first-child { margin-top: 0; }
.form-grid { display: grid; gap: 0.65rem; }
.g2 { grid-template-columns: repeat(2, 1fr); }
.g3 { grid-template-columns: repeat(3, 1fr); }
.g4 { grid-template-columns: repeat(4, 1fr); }
.fg { display: flex; flex-direction: column; gap: 4px; }
.fg.span2 { grid-column: span 2; }
.fl { font-size: 10.5px; font-weight: 600; color: var(--tm); letter-spacing: 0.04em; text-transform: uppercase; display: flex; gap: 5px; align-items: center; }
.fl .hint { font-size: 9.5px; font-weight: 400; color: var(--tu); text-transform: none; letter-spacing: 0; }
.req { color: var(--et); font-size: 11px; }
.form-input { background: var(--bs); border: 1.5px solid var(--gb); border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 13px; padding: 8px 11px; height: 36px; outline: none; color: var(--td); transition: border-color 0.15s, box-shadow 0.15s; }
.form-input.ta { height: auto; resize: vertical; line-height: 1.5; }
.form-input.readonly { background: var(--bi); color: var(--tu); }
.form-input:focus { border-color: var(--ga); background: #fff; box-shadow: 0 0 0 3px rgba(31,125,74,.09); }
.form-input:disabled { opacity: 0.6; cursor: not-allowed; }
.form-input.vital-low    { border-color: var(--it); background: #eff6ff; }
.form-input.vital-high   { border-color: var(--et); background: #fef2f2; }
.form-input.vital-normal { border-color: var(--st); background: #f0fdf4; }
.td-row { display: flex; align-items: center; gap: 6px; }
.td-row span { color: var(--tu); font-weight: 600; }
.unit { font-size: 10px; color: var(--tu); }
.bmi-label { font-size: 10.5px; color: var(--tu); margin-top: 2px; }
.kgd-info { display: flex; align-items: center; height: 36px; }
.kgd-hint { font-size: 11px; color: var(--th); font-style: italic; }
.vital-tag { font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 6px; }
.vital-tag.low    { background: var(--ib); color: var(--it); }
.vital-tag.high   { background: var(--eb); color: var(--et); }
.vital-tag.normal { background: var(--sb); color: var(--st); }

/* ── Pain scale ──────────────────────────────────────────────────────────── */
.pain-scale { display: flex; gap: 4px; }
.pain-btn { flex: 1; height: 44px; border-radius: 7px; border: 1.5px solid var(--gb); background: var(--bs); font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 600; cursor: pointer; color: var(--td); transition: all 0.14s; }
.pain-btn:disabled { opacity: .6; cursor: not-allowed; }
.pain-btn.a.pain-0, .pain-btn.a.pain-1, .pain-btn.a.pain-2 { background: var(--st); color: #fff; border-color: var(--st); }
.pain-btn.a.pain-3, .pain-btn.a.pain-4 { background: #16a34a; color: #fff; border-color: #16a34a; }
.pain-btn.a.pain-5, .pain-btn.a.pain-6 { background: #eab308; color: #fff; border-color: #eab308; }
.pain-btn.a.pain-7, .pain-btn.a.pain-8 { background: #f97316; color: #fff; border-color: #f97316; }
.pain-btn.a.pain-9, .pain-btn.a.pain-10 { background: var(--et); color: #fff; border-color: var(--et); }

/* ── Action row ──────────────────────────────────────────────────────────── */
.action-row { display: flex; gap: 0.5rem; margin-top: 1rem; flex-wrap: wrap; }
.btn { display: inline-flex; align-items: center; gap: 5px; padding: 0 13px; height: 32px; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 12px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; transition: all 0.15s; }
.btn-sm { height: 28px; padding: 0 10px; font-size: 11px; }
.btn-lg { height: 42px; padding: 0 20px; font-size: 13px; font-weight: 600; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover:not(:disabled) { background: var(--gm); }
.btn-primary:disabled { background: var(--th); cursor: not-allowed; }
.btn-success { background: var(--ga); color: #fff; border-color: var(--ga); }
.btn-success:hover:not(:disabled) { background: var(--gm); }
.btn-success:disabled { background: var(--th); cursor: not-allowed; }
.btn-secondary { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-secondary:hover:not(:disabled) { border-color: var(--ga); color: var(--td); background: var(--gl); }
.btn-secondary:disabled { opacity: .5; cursor: not-allowed; }
.btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

/* ── Kartu Kirim ke Dokter (action card sebawah tab) ────────────────────── */
.send-card { margin-top: 0.75rem; border-color: var(--sbd); background: linear-gradient(180deg, var(--sb) 0%, var(--bc) 60%); }
.send-card-body { display: flex; align-items: center; gap: 1rem; padding: 0.9rem 1.1rem; }
.send-card-info { flex: 1; min-width: 0; }
.send-card-title { display: flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 700; color: var(--td); }
.send-card-title svg { width: 15px; height: 15px; fill: none; stroke: var(--ga); stroke-width: 2.5; stroke-linecap: round; }
.send-card-sub { font-size: 11.5px; color: var(--tm); margin-top: 3px; line-height: 1.5; }
.send-btn { flex-shrink: 0; }
.send-actions { display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; }
.send-actions .btn svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.preop-send-card { border-color: #f59e0b; background: linear-gradient(180deg, #fffbeb 0%, #fff 60%); }
.preop-send-card .send-card-title { color: #92400e; }
.preop-send-card .send-card-title svg { stroke: #b45309; }
.preop-pill { display: inline-block; background: #fef3c7; color: #92400e; border: 1px solid #fbbf24; font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 4px; letter-spacing: 0.5px; margin-left: 4px; }

/* ── Instruksi Obat Pre-Op (dokter jaga) ─────────────────────────────────── */
.preop-rx-card { margin-top: 0.75rem; border-left: 3px solid #0d9488; }
.preop-rx-head { margin-bottom: 0.6rem; }
.preop-rx-status { margin-left: 8px; font-size: 10px; font-weight: 600; color: #b45309; background: #fef3c7; border-radius: 999px; padding: 2px 8px; }
.preop-rx-status.ok { color: #15803d; background: #dcfce7; }
.preop-rx-picker { position: relative; margin-bottom: 0.6rem; max-width: 420px; }
.preop-rx-results { position: absolute; z-index: 30; top: 100%; left: 0; right: 0; margin: 2px 0 0; padding: 0; list-style: none; background: #fff; border: 1px solid var(--gb); border-radius: 8px; box-shadow: 0 8px 20px rgba(0,0,0,.08); max-height: 260px; overflow-y: auto; }
.preop-rx-results li button { display: flex; flex-direction: column; gap: 1px; width: 100%; text-align: left; padding: 7px 10px; border: 0; background: transparent; cursor: pointer; font-size: 12.5px; }
.preop-rx-results li button:hover { background: var(--gl); }
.preop-rx-results li button span { font-size: 11px; color: var(--tm); }
.preop-rx-hint { font-size: 12px; color: var(--tm); padding: 4px 0; }
.preop-rx-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.preop-rx-table th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: .4px; color: var(--tm); padding: 4px 6px; border-bottom: 1px solid var(--gb); }
.preop-rx-table td { padding: 4px 6px; border-bottom: 1px solid var(--gl); vertical-align: middle; }
.preop-rx-table .form-input { padding: 4px 6px; font-size: 12.5px; }
.preop-rx-qty { width: 64px; }
.preop-rx-route { width: 90px; }
.preop-rx-stat { font-size: 9.5px; font-weight: 700; color: #0f766e; background: rgba(13,148,136,.1); border-radius: 4px; padding: 1px 5px; margin-left: 4px; }
.preop-rx-absorb { text-align: center; }
.preop-rx-del { border: 0; background: transparent; color: #b91c1c; font-size: 16px; cursor: pointer; line-height: 1; }
.preop-rx-actions { margin-top: 0.6rem; display: flex; justify-content: flex-end; }

/* ── Allergy ─────────────────────────────────────────────────────────────── */
.allergy-known { display: flex; gap: 6px; align-items: flex-start; background: var(--wb); border: 1px solid var(--wbd); border-radius: 8px; padding: 8px 10px; font-size: 11px; color: var(--wt); font-weight: 500; margin-bottom: 0.75rem; }
.allergy-known svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; margin-top: 1px; }
.allergy-row { display: flex; flex-wrap: wrap; gap: 5px; min-height: 38px; padding: 6px 10px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; align-items: center; }
.allergy-tag { display: inline-flex; align-items: center; gap: 4px; background: var(--eb); border: 1px solid var(--ebd); color: var(--et); font-size: 11.5px; font-weight: 500; padding: 3px 9px; border-radius: 20px; }
.allergy-tag button { background: none; border: none; cursor: pointer; color: var(--et); padding: 0; line-height: 1; font-size: 14px; opacity: .6; min-width: 20px; min-height: 20px; }
.allergy-tag button:hover { opacity: 1; }
.allergy-input { border: none; outline: none; background: transparent; font-family: 'Inter', sans-serif; font-size: 12.5px; color: var(--td); min-width: 180px; flex: 1; }
.hint { font-size: 10.5px; color: var(--tu); margin-top: 5px; }

/* ── Spinner ─────────────────────────────────────────────────────────────── */
.sp { width: 14px; height: 14px; border-radius: 50%; border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; animation: spin 0.7s linear infinite; }
.sp.lg { width: 24px; height: 24px; border-width: 3px; border-color: var(--gb); border-top-color: var(--ga); }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Modal ───────────────────────────────────────────────────────────────── */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 200; display: flex; align-items: center; justify-content: center; padding: 1rem; }
.modal { background: var(--bc); border-radius: 14px; width: 100%; max-width: 760px; max-height: 88vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.15); overflow: hidden; }
.modal-head { display: flex; align-items: center; justify-content: space-between; padding: 0.9rem 1.2rem; border-bottom: 1px solid var(--gb); flex-shrink: 0; }
.modal-title { display: flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 700; color: var(--td); }
.modal-title svg { width: 15px; height: 15px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.modal-close { width: 28px; height: 28px; border-radius: 6px; border: 1px solid var(--gb); background: var(--bs); font-size: 16px; cursor: pointer; color: var(--tu); display: flex; align-items: center; justify-content: center; line-height: 1; font-family: 'Inter',sans-serif; }
.modal-close:hover { background: var(--eb); color: var(--et); }
.modal-body { padding: 1rem 1.2rem; overflow-y: auto; flex: 1; }
.modal-loading { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 3rem; font-size: 13px; color: var(--tu); }
.modal-error { padding: 1rem; background: var(--eb); border-radius: 8px; font-size: 12.5px; color: var(--et); }

/* ── Modal sections ──────────────────────────────────────────────────────── */
.modal-sections { display: flex; flex-direction: column; gap: 1.25rem; }
.modal-section { }
.ms-title { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 700; color: var(--td); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 0.6rem; }
.ms-title svg { width: 13px; height: 13px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.ms-ct { font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 10px; background: var(--gl); color: var(--ga); }
.ms-empty { font-size: 11.5px; color: var(--th); font-style: italic; padding: 0.5rem 0; }

/* ── Document list ───────────────────────────────────────────────────────── */
.doc-list { display: flex; flex-direction: column; gap: 5px; }
.doc-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--bs); border: 1.5px solid var(--gb); border-radius: 9px; cursor: pointer; transition: all .13s; }
.doc-item:hover { border-color: var(--ga); background: var(--gl); }
.doc-icon svg { width: 22px; height: 22px; fill: none; stroke: var(--ga); stroke-width: 1.5; stroke-linecap: round; }
.doc-info { flex: 1; }
.doc-name { font-size: 12.5px; font-weight: 500; color: var(--td); }
.doc-meta { font-size: 10.5px; color: var(--tu); margin-top: 2px; }
.doc-arrow { width: 14px; height: 14px; fill: none; stroke: var(--tu); stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }

/* ── Status badges ───────────────────────────────────────────────────────── */
.status-badge { font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 4px; flex-shrink: 0; }
.sb-draft             { background: var(--ib); color: var(--it); }
.sb-waiting_signature { background: #fef3c7; color: #92400e; }
.sb-final             { background: var(--sb); color: var(--st); }
.sb-rejected          { background: var(--eb); color: var(--et); }
.sb-void              { background: var(--bi); color: var(--th); }

/* ── Document detail ─────────────────────────────────────────────────────── */
.back-btn { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--ga); border: none; background: none; cursor: pointer; font-family: 'Inter',sans-serif; margin-bottom: 0.9rem; padding: 0; }
.back-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.dok-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem; margin-bottom: 0.5rem; }
.dok-title { font-size: 15px; font-weight: 700; color: var(--td); }
.dok-meta { font-size: 10.5px; color: var(--tu); margin-top: 3px; }
.dok-visit-info { font-size: 11.5px; color: var(--tu); padding: 6px 10px; background: var(--bs); border-radius: 7px; margin-bottom: 0.75rem; }
.dok-signatures { margin-top: 0.75rem; }
.sig-row { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--td); margin-top: 4px; }
.sig-row svg { width: 12px; height: 12px; fill: none; stroke: var(--st); stroke-width: 2.5; stroke-linecap: round; }
.dok-reject { display: flex; align-items: flex-start; gap: 6px; background: var(--eb); border: 1px solid var(--ebd); border-radius: 8px; padding: 8px 10px; font-size: 12px; color: var(--et); margin-top: 0.75rem; }
.dok-reject svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; flex-shrink: 0; margin-top: 1px; }

/* ── Vital history table ─────────────────────────────────────────────────── */
.vh-table { border: 1px solid var(--gb); border-radius: 8px; overflow: hidden; font-size: 11.5px; }
.vh-row { display: grid; grid-template-columns: 1fr 80px 70px 40px 55px 60px 55px; gap: 0; padding: 6px 10px; border-bottom: 1px solid var(--gb); }
.vh-row:last-child { border-bottom: none; }
.vh-header { background: var(--bs); font-size: 9.5px; font-weight: 600; color: var(--tu); text-transform: uppercase; letter-spacing: .04em; }

/* ── Toast ───────────────────────────────────────────────────────────────── */
.toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 999; display: flex; flex-direction: column; gap: 6px; }
.toast { padding: 9px 13px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 12px rgba(0,0,0,.08); min-width: 230px; max-width: 320px; }
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }

/* ── SOAP Sidebar ─────────────────────────────────────────────────────────── */
.col-soap { min-width: 0; }
.soap-ghost { height: 100%; }
.soap-sticky { position: sticky; top: 1rem; }
.soap-card { overflow: visible; }
.soap-locked { font-size: 9.5px; font-weight: 700; padding: 2px 8px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 20px; }

.soap-body { padding: 0; }
.soap-section { padding: 0.65rem 0.9rem; border-bottom: 1px solid var(--gb); }
.soap-section:last-child { border-bottom: none; }

.soap-label { font-size: 10px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 0.45rem; display: flex; align-items: center; gap: 6px; }
.soap-label span { font-size: 9.5px; font-weight: 500; letter-spacing: 0; text-transform: none; color: var(--tu); }
.soap-label.s { color: #1d4ed8; }
.soap-label.o { color: var(--ga); }
.soap-label.a { color: #7e22ce; }
.soap-label.p { color: #b45309; }

.soap-row { display: flex; flex-direction: column; gap: 2px; margin-bottom: 0.4rem; }
.soap-row:last-child { margin-bottom: 0; }
.soap-key { font-size: 9.5px; font-weight: 600; color: var(--tu); text-transform: uppercase; letter-spacing: 0.04em; }
.soap-val { font-size: 11.5px; color: var(--td); line-height: 1.45; word-break: break-word; }
.soap-val.placeholder { color: var(--th); font-style: italic; }
.soap-allergy { color: var(--et); font-weight: 600; }
.soap-placeholder { font-size: 11px; color: var(--th); font-style: italic; }

.soap-vitals { display: flex; flex-direction: column; gap: 0; }
.sv-item { display: flex; align-items: baseline; justify-content: space-between; padding: 3px 0; border-bottom: 1px dashed var(--gb); }
.sv-item:last-child { border-bottom: none; }
.sv-k { font-size: 9.5px; font-weight: 600; color: var(--tu); text-transform: uppercase; letter-spacing: 0.04em; flex-shrink: 0; }
.sv-v { font-size: 12.5px; font-weight: 600; color: var(--td); font-variant-numeric: tabular-nums; text-align: right; }
.sv-v em { font-style: normal; font-size: 9.5px; font-weight: 400; color: var(--tu); margin-left: 2px; }

.sv-pain { font-size: 12px; font-weight: 700; padding: 1px 8px; border-radius: 20px; }
.p-none   { background: var(--sb); color: var(--st); }
.p-mild   { background: #dcfce7; color: #15803d; }
.p-mod    { background: #fef9c3; color: #a16207; }
.p-severe { background: #ffedd5; color: #c2410c; }
.p-worst  { background: var(--eb); color: var(--et); }

/* ── CPPT Timeline ────────────────────────────────────────────────────────── */
.soap-count { font-size: 9.5px; font-weight: 700; padding: 2px 8px; background: var(--gl); color: var(--ga); border: 1px solid rgba(31,125,74,.3); border-radius: 20px; }
.soap-section.compact { padding: 0.5rem 0.9rem; }
.soap-asesmen-awal { background: var(--bs); border-bottom: 2px solid var(--gb); }

.cppt-time { display: flex; align-items: center; gap: 6px; padding: 0.5rem 0.9rem; font-size: 10.5px; color: var(--tu); border-bottom: 1px solid var(--gb); flex-wrap: wrap; }
.cppt-time svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.cppt-time-label { font-weight: 700; color: var(--td); letter-spacing: 0.03em; }
.cppt-time-stamp { font-variant-numeric: tabular-nums; }
.cppt-by { color: var(--th); }
.cppt-edited { font-size: 9px; font-weight: 700; padding: 1px 7px; border-radius: 20px; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; cursor: help; }
.cppt-edit-btn { margin-left: auto; display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; font-size: 10px; font-weight: 600; background: transparent; border: 1px solid var(--gb); color: var(--tm); border-radius: 6px; cursor: pointer; transition: all 0.15s; }
.cppt-edit-btn svg { width: 10px; height: 10px; }
.cppt-edit-btn:hover:not(:disabled) { border-color: var(--ga); color: var(--td); background: var(--gl); }
.cppt-edit-btn:disabled { opacity: 0.4; cursor: not-allowed; }

.cppt-divider { padding: 0.4rem 0.9rem; font-size: 9.5px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--tu); background: var(--bs); border-bottom: 1px solid var(--gb); }
.cppt-divider span { display: inline-block; }

.cppt-entry { border-bottom: 1px solid var(--gb); }
.cppt-entry:last-child { border-bottom: none; }

.cppt-notes { margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px dashed var(--gb); font-size: 11.5px; line-height: 1.5; color: var(--td); white-space: pre-wrap; word-break: break-word; }

.cppt-empty { padding: 1rem 0.9rem; font-size: 11px; color: var(--tu); text-align: center; line-height: 1.5; }
.cppt-empty strong { color: var(--ga); }

/* ── CPPT mode banner di form ─────────────────────────────────────────────── */
.cppt-banner {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 0.75rem;
  padding: 0.7rem 0.9rem;
  font-size: 12.5px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 9px;
  color: #92400e;
  line-height: 1.5;
}
.cppt-banner svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.cppt-banner strong { font-weight: 700; }

/* ── CPPT: tanda tangan + SOAP display ───────────────────────────────────── */
.cppt-sign-btn { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; font-size: 10px; font-weight: 600; background: transparent; border: 1px solid #a7f3d0; color: #047857; border-radius: 6px; cursor: pointer; transition: all 0.15s; }
.cppt-sign-btn svg { width: 10px; height: 10px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
.cppt-sign-btn:hover:not(:disabled) { background: #ecfdf5; border-color: #047857; }
.cppt-sign-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.cppt-signed { font-size: 9px; font-weight: 700; padding: 1px 7px; border-radius: 20px; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; cursor: help; }
.cppt-soap { margin-top: 0.4rem; font-size: 11.5px; line-height: 1.5; color: var(--td); white-space: pre-wrap; word-break: break-word; }
.cppt-soap b { display: inline-block; width: 14px; font-weight: 800; }
.cppt-soap b.s { color: #1d4ed8; }
.cppt-soap b.a { color: #7e22ce; }
.cppt-soap b.p { color: #b45309; }

/* ── SOAP perawat (form CPPT) ────────────────────────────────────────────── */
.soap-prw .fl { display: flex; align-items: center; gap: 6px; }
.soap-tag { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 5px; font-size: 11px; font-weight: 800; color: #fff; }
.soap-tag.s { background: #1d4ed8; }
.soap-tag.o { background: #64748b; }
.soap-tag.a { background: #7e22ce; }
.soap-tag.p { background: #b45309; }
.soap-opt { font-size: 9.5px; font-weight: 500; font-style: italic; color: var(--tu); text-transform: none; letter-spacing: 0; }
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
