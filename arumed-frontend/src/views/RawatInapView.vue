<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRawatInapStore } from '@/stores/rawatInapStore'
import { useAuthStore } from '@/stores/authStore'
import { ranapApi, tarifPaketApi, formTemplateApi } from '@/services/api'
import UnitStockActions from '@/components/inventori-farmasi/UnitStockActions.vue'
import FormDocsBrowser from '@/components/forms/FormDocsBrowser.vue'
import FormRMRenderer from '@/components/forms/FormRMRenderer.vue'

const store = useRawatInapStore()
const auth = useAuthStore()
const tab = ref('board') // 'board' | 'menunggu' | 'aktif'
const busy = ref(false)
const toast = ref(null)

function notify(msg, ok = true) {
  toast.value = { msg, ok }
  setTimeout(() => (toast.value = null), 3500)
}

const allAvailableBeds = computed(() => {
  const out = []
  for (const room of store.bedBoard) {
    for (const bed of room.beds) {
      if (bed.status === 'AVAILABLE') {
        out.push({ id: bed.id, label: bed.label, room: room.name, kelas: room.kelas_rawat })
      }
    }
  }
  return out
})

// Statistik untuk stat card (kanan atas).
const stats = computed(() => {
  let totalBed = 0, occupied = 0
  for (const room of store.bedBoard) {
    totalBed += room.total
    occupied += room.occupied
  }
  return {
    aktif: store.aktif.length,
    menunggu: store.menungguKamar.length,
    occupied,
    available: totalBed - occupied,
  }
})

async function refreshAll() {
  await Promise.all([store.fetchBedBoard(), store.fetchMenungguKamar(), store.fetchAktif()])
}
onMounted(refreshAll)

// ── ADMIT ───────────────────────────────────────────────────────────────────
const showAdmit = ref(false)
const admitVisit = ref(null)
const admitForm = ref({ bed_id: '', kelas_hak: '', admission_at: '' })

function openAdmit(p) {
  admitVisit.value = p
  admitForm.value = { bed_id: '', kelas_hak: '', admission_at: '' }
  showAdmit.value = true
}
async function submitAdmit() {
  if (!admitForm.value.bed_id || !admitForm.value.kelas_hak) { notify('Pilih bed & kelas hak', false); return }
  const bed = allAvailableBeds.value.find((b) => b.id === admitForm.value.bed_id)
  if (bed && String(bed.kelas) !== String(admitForm.value.kelas_hak)) {
    if (!confirm(`Titip kelas: bed ${bed.label} (Kelas ${bed.kelas}), hak pasien Kelas ${admitForm.value.kelas_hak}. Tarif tetap kelas hak. Lanjut?`)) return
  }
  busy.value = true
  try {
    const payload = { bed_id: admitForm.value.bed_id, kelas_hak: admitForm.value.kelas_hak }
    if (admitForm.value.admission_at) payload.admission_at = admitForm.value.admission_at
    await store.admit(admitVisit.value.visit_id, payload)
    notify('Pasien dirawat inap'); showAdmit.value = false
  } catch { notify(store.error || 'Gagal admit', false) } finally { busy.value = false }
}

// ── TRANSFER (modal) ──────────────────────────────────────────────────────────
const showTransfer = ref(false)
const transferPt = ref(null)
const transferForm = ref({ bed_id: '', reason: 'TRANSFER' })

function openTransfer(p) {
  transferPt.value = p
  transferForm.value = { bed_id: '', reason: 'TRANSFER' }
  showTransfer.value = true
}
async function submitTransfer() {
  if (!transferForm.value.bed_id) { notify('Pilih bed tujuan', false); return }
  busy.value = true
  try {
    await store.transfer(transferPt.value.visit_id, { ...transferForm.value })
    notify('Pasien dipindahkan'); showTransfer.value = false
  } catch { notify(store.error || 'Gagal pindah', false) } finally { busy.value = false }
}

// ── DISCHARGE (modal) ─────────────────────────────────────────────────────────
const showDischarge = ref(false)
const dischargePt = ref(null)
const dischargeForm = ref({ discharge_type: 'PULANG_SEHAT', summary: '', follow_up_date: '', follow_up_reason: '', spri_tgl_rencana: '' })

// Obat pulang (opsional): ditagih via inpatient_charges + diteruskan ke Farmasi.
const obatPulangList = ref([])           // [{ medication_id, name, quantity, dose, frequency, route, duration_days }]
const obatPulangSearch = ref('')
const obatPulangOptions = ref([])        // hasil pencarian obat + harga

async function openDischarge(p) {
  dischargePt.value = p
  dischargeForm.value = { discharge_type: 'PULANG_SEHAT', summary: '', follow_up_date: '', follow_up_reason: '', spri_tgl_rencana: '' }
  obatPulangList.value = []
  obatPulangSearch.value = ''
  obatPulangOptions.value = []
  showDischarge.value = true
  // Prefetch obat awal (tanpa keyword) supaya dropdown tidak kosong.
  try {
    const o = await ranapApi.daftarObat(p.visit_id, '')
    obatPulangOptions.value = o.data?.data ?? []
  } catch { obatPulangOptions.value = [] }
}

async function searchObatPulang() {
  if (!dischargePt.value) return
  try {
    const o = await ranapApi.daftarObat(dischargePt.value.visit_id, obatPulangSearch.value)
    obatPulangOptions.value = o.data?.data ?? []
  } catch { obatPulangOptions.value = [] }
}

function addObatPulang(med) {
  if (!med) return
  if (obatPulangList.value.some((x) => x.medication_id === med.id)) {
    notify('Obat sudah ada di daftar', false); return
  }
  obatPulangList.value.push({
    medication_id: med.id,
    name: med.name + (med.unit ? ` (${med.unit})` : ''),
    price: med.price ?? 0,
    quantity: 1,
    dose: '', frequency: '', route: '', duration_days: null,
  })
}

function removeObatPulang(i) {
  obatPulangList.value.splice(i, 1)
}

async function submitDischarge() {
  busy.value = true
  try {
    const payload = { ...dischargeForm.value }
    if (obatPulangList.value.length) {
      payload.obat_pulang = obatPulangList.value.map((o) => ({
        medication_id: o.medication_id,
        quantity: o.quantity || 1,
        dose: o.dose || null,
        frequency: o.frequency || null,
        route: o.route || null,
        duration_days: o.duration_days || null,
      }))
    }
    const dischargedVisitId = dischargePt.value.visit_id
    await store.discharge(dischargedVisitId, payload)
    let msg = 'Pasien dipulangkan → kasir'
    if (obatPulangList.value.length) msg += ' (obat pulang → farmasi)'
    if (dischargeForm.value.spri_tgl_rencana) msg += ' (SPRI diproses)'
    notify(msg); showDischarge.value = false
    // Auto-buka Resume Medis Rawat Inap (RM 3.5) agar DPJP melengkapi & TTD saat pulang.
    openResumeRanap(dischargedVisitId)
  } catch { notify(store.error || 'Gagal discharge', false) } finally { busy.value = false }
}

// ── Resume Medis RI (RM 3.5) auto-buka saat discharge — meniru openResumeRM DokterView ──
const showResumeRanap = ref(false)
const resumeRanapTpl  = ref(null)
const resumeRanapVisitId = ref(null)
async function openResumeRanap(visitId) {
  try {
    const { data } = await formTemplateApi.forms({ station: 'ranap', section: 'ringkasan_pulang', visit_id: visitId })
    const list = data.data ?? []
    // Auto-buka HANYA template Resume RI yang tepat — JANGAN fallback list[0]
    // (bisa membuka form lain yang kebetulan di section sama). Bila tak ada,
    // diam (resume bisa diisi manual lewat tab Dokumen RM).
    const tpl = list.find((f) => f.code === 'RESUME_MEDIS_RANAP') ?? null
    if (!tpl) return
    resumeRanapTpl.value = tpl
    resumeRanapVisitId.value = visitId
    showResumeRanap.value = true
  } catch (_) { /* resume best-effort — bisa diisi manual lewat tab Dokumen RM */ }
}
function closeResumeRanap() {
  showResumeRanap.value = false
  resumeRanapTpl.value = null
  resumeRanapVisitId.value = null
}

// ── SEP (modal view + update) ───────────────────────────────────────────────────
const showSep = ref(false)
const sepPt = ref(null)
const sepData = ref(null)
const sepForm = ref({ kls_rawat: '', catatan: '', diag_awal: '', no_telp: '', katarak: '0' })
async function openSep(p) {
  sepPt.value = p; sepData.value = null; showSep.value = true
  sepForm.value = { kls_rawat: p.kelas_rawat_hak || '', catatan: '', diag_awal: '', no_telp: '', katarak: '0' }
  sepTglPulang.value = (p.discharge_at || '').slice(0, 10)
  try {
    const r = await ranapApi.getSep(p.visit_id)
    sepData.value = r.data?.data ?? null
  } catch (e) { notify(e.response?.data?.message ?? 'Gagal muat SEP', false); showSep.value = false }
}
async function submitSep() {
  busy.value = true
  try {
    await ranapApi.updateSep(sepPt.value.visit_id, { ...sepForm.value })
    notify('SEP diperbarui'); showSep.value = false
  } catch (e) { notify(e.response?.data?.message ?? 'Gagal update SEP', false) } finally { busy.value = false }
}
// Lapor/ulang tgl pulang SEP ke BPJS (saat laporan otomatis discharge gagal, atau koreksi tgl).
const sepTglPulang = ref('')
async function submitTglPulang() {
  busy.value = true
  try {
    const payload = sepTglPulang.value ? { tgl_pulang: sepTglPulang.value } : {}
    await ranapApi.updateTglPulang(sepPt.value.visit_id, payload)
    notify('Tanggal pulang dilaporkan ke BPJS')
  } catch (e) { notify(e.response?.data?.message ?? 'Gagal lapor tgl pulang', false) } finally { busy.value = false }
}

// ── HISTORY (tab) ─────────────────────────────────────────────────────────────
const histList = ref([])
const histFrom = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10))
const histTo = ref(new Date().toISOString().slice(0, 10))
async function loadHistory() {
  try {
    const r = await ranapApi.history(histFrom.value, histTo.value)
    histList.value = r.data?.data ?? []
  } catch (e) { notify(e.response?.data?.message ?? 'Gagal muat riwayat', false) }
}
async function issueSpri(row) {
  const tgl = prompt('Tanggal rencana kontrol/SPRI (YYYY-MM-DD):', histTo.value)
  if (!tgl) return
  try {
    await ranapApi.createSpri(row.visit_id, { tgl_rencana: tgl })
    notify('SPRI diterbitkan'); await loadHistory()
  } catch (e) { notify(e.response?.data?.message ?? 'Gagal terbitkan SPRI', false) }
}
async function editSpri(row) {
  if (!row.spri?.id) return
  const tgl = prompt('Ubah tanggal SPRI (YYYY-MM-DD):', row.spri.tgl_rencana || histTo.value)
  if (!tgl) return
  try {
    await ranapApi.updateSpri(row.spri.id, { tgl_rencana: tgl })
    notify('SPRI diperbarui'); await loadHistory()
  } catch (e) { notify(e.response?.data?.message ?? 'Gagal update SPRI', false) }
}
async function removeSpri(row) {
  if (!row.spri?.id || !confirm('Hapus SPRI ini? (hanya yang belum terbit)')) return
  try {
    await ranapApi.deleteSpri(row.spri.id)
    notify('SPRI dihapus'); await loadHistory()
  } catch (e) { notify(e.response?.data?.message ?? 'Gagal hapus SPRI', false) }
}

// ── KIRIM BEDAH ───────────────────────────────────────────────────────────────
const showKirimBedah = ref(false)
const kirimBedahPt = ref(null)
const kirimBedahForm = ref({ surgery_package_id: '', scheduled_date: '' })
const paketBedahOptions = ref([])
// Cari paket bedah (client-side; daftar paket aktif dari master Tarif & Paket).
const paketSearch = ref('')
const filteredPaketBedah = computed(() => {
  const q = paketSearch.value.trim().toLowerCase()
  if (!q) return paketBedahOptions.value
  return paketBedahOptions.value.filter((p) =>
    `${p.name ?? ''} ${p.code ?? ''}`.toLowerCase().includes(q))
})

async function doKirimBedah(p) {
  kirimBedahPt.value = p
  kirimBedahForm.value = { surgery_package_id: '', scheduled_date: '' }
  showKirimBedah.value = true
  // Muat daftar paket bedah untuk opsi auto-jadwal (boleh dikosongkan).
  if (!paketBedahOptions.value.length) {
    try {
      const res = await tarifPaketApi.paket.list({ active: 1 })
      paketBedahOptions.value = res.data?.data ?? []
    } catch { paketBedahOptions.value = [] }
  }
}

async function confirmKirimBedah () {
  const p = kirimBedahPt.value
  const payload = {}
  if (kirimBedahForm.value.surgery_package_id) {
    payload.surgery_package_id = kirimBedahForm.value.surgery_package_id
    if (kirimBedahForm.value.scheduled_date) payload.scheduled_date = kirimBedahForm.value.scheduled_date
  }
  try {
    await store.kirimBedah(p.visit_id, payload)
    showKirimBedah.value = false
    notify('Dikirim ke bedah (bed ditahan)')
  } catch { notify(store.error || 'Gagal kirim bedah', false) }
}

// ── BED CLEANING → AVAILABLE ──────────────────────────────────────────────────
async function markBedAvailable(bed) {
  if (!confirm(`Tandai bed ${bed.label} selesai dibersihkan (siap dipakai)?`)) return
  try {
    await ranapApi.markBedAvailable(bed.id)
    notify(`Bed ${bed.label} siap dipakai`)
    await store.fetchBedBoard()
  } catch (e) { notify(e.response?.data?.message ?? 'Gagal update bed', false) }
}

// ── DETAIL + tindakan/obat (panel inline 2-kolom di tab "Pasien Aktif") ───────
const detailTab = ref('cppt') // 'cppt' | 'biaya' (Hasil Eksternal → modal kecil di CPPT)
const detailVisitId = ref(null)
// Pasien terpilih (dari store.aktif) — untuk tombol aksi Pindah/Bedah/Pulang.
const selectedPt = computed(() => store.aktif.find((p) => p.visit_id === detailVisitId.value) || null)
// Dokumen RM — tombol di header kartu → modal kecil (pola IgdView "Pengkajian RM 3.7").
const showDocModal = ref(false)
function openDocModal() { if (detailVisitId.value) showDocModal.value = true }

// ── Hasil eksternal (Fase 8C) — modal kecil dibuka dari header CPPT ────────────
const docList = ref([])
const docForm = ref({ category: 'LAB', title: '', file: null })
const docFileInput = ref(null)
const showExtDocs = ref(false)   // modal kecil "Hasil Eksternal"

function openExtDocs() {
  showExtDocs.value = true
  loadDocuments()
}

async function loadDocuments() {
  if (!detailVisitId.value) return
  try {
    const r = await ranapApi.documents(detailVisitId.value)
    docList.value = r.data?.data ?? []
  } catch { docList.value = [] }
}
function onDocFile(e) {
  docForm.value.file = e.target.files?.[0] ?? null
}
async function submitDocument() {
  if (!docForm.value.file) { notify('Pilih berkas dulu', false); return }
  busy.value = true
  try {
    const fd = new FormData()
    fd.append('category', docForm.value.category)
    if (docForm.value.title) fd.append('title', docForm.value.title)
    fd.append('file', docForm.value.file)
    await ranapApi.uploadDocument(detailVisitId.value, fd)
    notify('Hasil eksternal diunggah')
    docForm.value = { category: 'LAB', title: '', file: null }
    if (docFileInput.value) docFileInput.value.value = ''
    await loadDocuments()
  } catch (e) { notify(e.response?.data?.message ?? 'Gagal unggah', false) } finally { busy.value = false }
}
async function removeDocument(d) {
  if (!confirm(`Hapus dokumen "${d.title}"?`)) return
  try {
    await ranapApi.deleteDocument(detailVisitId.value, d.id)
    notify('Dokumen dihapus'); await loadDocuments()
  } catch (e) { notify(e.response?.data?.message ?? 'Gagal hapus', false) }
}
const tindakanList = ref([])
const obatList = ref([])
const pickTindakan = ref({ procedure_id: '', quantity: 1 })
const obatSearch = ref('')
// Picker tindakan (typeahead, gaya DokterView: input + dropdown hasil → klik pilih).
// Dropdown DIBATASI 50 item agar tidak merender ratusan node (299 tindakan) →
// cegah jank/freeze. Ketik untuk mempersempit.
const PICK_LIMIT = 50
const tindakanSearch = ref('')
const tindakanComboOpen = ref(false)
const filteredTindakanAll = computed(() => {
  const q = tindakanSearch.value.trim().toLowerCase()
  if (!q) return tindakanList.value
  return tindakanList.value.filter((t) => String(t.name ?? '').toLowerCase().includes(q))
})
const filteredTindakan = computed(() => filteredTindakanAll.value.slice(0, PICK_LIMIT))
const tindakanOverflow = computed(() => Math.max(0, filteredTindakanAll.value.length - PICK_LIMIT))
// Dropdown obat juga dibatasi 50 (obatList server max 100).
const obatDropList = computed(() => obatList.value.slice(0, PICK_LIMIT))
const obatOverflow = computed(() => Math.max(0, obatList.value.length - PICK_LIMIT))
const selectedTindakan = computed(() => tindakanList.value.find((t) => t.id === pickTindakan.value.procedure_id) || null)
function pickTindakanItem(t) { pickTindakan.value.procedure_id = t.id; tindakanSearch.value = ''; tindakanComboOpen.value = false }
function clearTindakan() { pickTindakan.value.procedure_id = '' }
function closeTindakanComboSoon() { setTimeout(() => { tindakanComboOpen.value = false }, 150) }

// Opsi aturan pakai (selaras resep DokterView) untuk permintaan obat Farmasi.
const SIGNA_OPTS = ['1×/hari', '2×/hari', '3×/hari', '4×/hari', '6×/hari', 'tiap 4 jam', 'tiap 6 jam', 'tiap 8 jam', 'tiap jam', 'bila perlu (prn)', 'sebelum tidur', '1 tetes tiap 1 jam']
const DURASI_OPTS = ['3 hari', '5 hari', '7 hari', '10 hari', '14 hari', '21 hari', '28 hari', '30 hari']
const ROUTE_OPTS = ['Oral', 'Tetes Mata (OD)', 'Tetes Mata (OS)', 'Tetes Mata (ODS)', 'Salep Mata', 'IV', 'IM', 'SC', 'Topikal', 'Sublingual', 'Rektal', 'Inhalasi']

// Picker obat Permintaan Farmasi (typeahead; obatList di-search server via searchObat).
const obatComboOpen = ref(false)
const reqPickObat = ref(null)   // simpan objek obat terpilih (chip + fallback nama saat add)
function pickReqObat(o) { reqPick.value.medication_id = o.id; reqPickObat.value = o; obatComboOpen.value = false }
function clearReqObat() { reqPick.value = { medication_id: '', quantity: 1, dose: '', frequency: '2×/hari', route: 'Oral', duration: '', instructions: '' }; reqPickObat.value = null }
function closeObatComboSoon() { setTimeout(() => { obatComboOpen.value = false }, 150) }

// CPPT terintegrasi multi-PPA (SOAP + TTV opsional)
const cpptList = ref([])
const emptyCppt = () => ({
  soap_s: '', soap_o: '', soap_a: '', soap_p: '', instruksi: '',
  td_sistol: null, td_diastol: null, nadi: null, suhu: null, respirasi: null, spo2: null, kgd: null, pain_scale: null,
  visus_od: '', visus_os: '', iop_od: null, iop_os: null, iop_method: '',
})
const cpptForm = ref(emptyCppt())
const cpptEditId = ref(null) // id entri yang sedang diedit (null = tambah baru)
const cpptExpanded = ref({}) // { [entryId]: true } — entri panjang yang di-expand

// Peran PPA dari profesi user login (mirror Employee::resolvePpaRole di backend).
function resolvePpa(profession) {
  const p = (profession || '').toLowerCase()
  if (/dokter|dpjp/.test(p)) return 'DOKTER'
  if (/perawat|bidan|nurse/.test(p)) return 'PERAWAT'
  if (/apoteker|farmasi/.test(p)) return 'APOTEKER'
  if (/gizi|dietisien|nutrisionis/.test(p)) return 'GIZI'
  if (/fisio|rehab|terapis/.test(p)) return 'FISIOTERAPIS'
  return 'LAINNYA'
}
const myPpa = computed(() => resolvePpa(auth.user?.employee?.profession))
const isDokter = computed(() => myPpa.value === 'DOKTER')

const PPA_LABEL = { DOKTER: 'Dokter', PERAWAT: 'Perawat', APOTEKER: 'Apoteker', GIZI: 'Ahli Gizi', FISIOTERAPIS: 'Fisioterapis', LAINNYA: 'PPA Lain' }

async function openDetail(visitId) {
  detailVisitId.value = visitId
  detailTab.value = 'cppt'
  tab.value = 'aktif'   // panel detail inline ada di tab "Pasien Aktif"
  // Bersihkan list pasien sebelumnya agar tak bocor bila fetch gagal.
  tindakanList.value = []
  obatList.value = []
  cpptList.value = []
  permintaanList.value = []
  reqCart.value = []
  docList.value = []
  await store.fetchDetail(visitId)
  try {
    const [t, o, c, p, d] = await Promise.all([
      ranapApi.tarifTindakan(visitId),
      ranapApi.daftarObat(visitId, ''),
      ranapApi.cpptList(visitId),
      ranapApi.permintaanObatList(visitId),
      ranapApi.documents(visitId),   // hitung untuk badge chip "Hasil Eksternal (N)"
    ])
    tindakanList.value = t.data?.data ?? []
    obatList.value = o.data?.data ?? []
    cpptList.value = c.data?.data ?? []
    permintaanList.value = p.data?.data ?? []
    docList.value = d.data?.data ?? []
  } catch { /* abaikan */ }
}

async function reloadCppt() {
  const c = await ranapApi.cpptList(detailVisitId.value)
  cpptList.value = c.data?.data ?? []
}

async function submitCppt() {
  const f = cpptForm.value
  const hasNarrative = [f.soap_s, f.soap_o, f.soap_a, f.soap_p, f.instruksi].some(v => v?.trim())
  if (!hasNarrative) { notify('Isi CPPT (SOAP atau instruksi) wajib diisi', false); return }
  try {
    if (cpptEditId.value) {
      await ranapApi.updateCppt(cpptEditId.value, { ...f })
      notify('CPPT diperbarui')
    } else {
      await ranapApi.addCppt(detailVisitId.value, { ...f })
      notify('CPPT dicatat')
    }
    cpptForm.value = emptyCppt()
    cpptEditId.value = null
    await reloadCppt()
  } catch (e) { notify(e.response?.data?.message ?? 'Gagal simpan CPPT', false) }
}

function editCppt(e) {
  cpptEditId.value = e.id
  cpptForm.value = {
    soap_s: e.soap_s ?? '', soap_o: e.soap_o ?? '', soap_a: e.soap_a ?? '', soap_p: e.soap_p ?? '',
    instruksi: e.instruksi ?? '',
    td_sistol: e.td_sistol, td_diastol: e.td_diastol, nadi: e.nadi, suhu: e.suhu,
    respirasi: e.respirasi, spo2: e.spo2, kgd: e.kgd, pain_scale: e.pain_scale,
    visus_od: e.visus_od ?? '', visus_os: e.visus_os ?? '', iop_od: e.iop_od, iop_os: e.iop_os, iop_method: e.iop_method ?? '',
  }
}

function toggleExpand(id) { cpptExpanded.value = { ...cpptExpanded.value, [id]: !cpptExpanded.value[id] } }

// Heuristik "panjang": total teks SOAP+instruksi+notes melebihi ambang.
function isLongCppt(e) {
  const len = [e.soap_s, e.soap_o, e.soap_a, e.soap_p, e.instruksi, e.notes]
    .filter(Boolean).join(' ').length
  return len > 360
}

function cancelEditCppt() {
  cpptEditId.value = null
  cpptForm.value = emptyCppt()
}

async function verifyCppt(e) {
  try {
    await ranapApi.verifyCppt(e.id)
    notify('CPPT diverifikasi DPJP')
    await reloadCppt()
  } catch (err) { notify(err.response?.data?.message ?? 'Gagal verifikasi', false) }
}

async function searchObat() {
  try {
    const o = await ranapApi.daftarObat(detailVisitId.value, obatSearch.value)
    obatList.value = o.data?.data ?? []
  } catch { /* */ }
}

async function submitTindakan() {
  if (!pickTindakan.value.procedure_id) { notify('Pilih tindakan', false); return }
  try {
    await store.addTindakan(detailVisitId.value, { ...pickTindakan.value })
    pickTindakan.value = { procedure_id: '', quantity: 1 }
    notify('Tindakan dicatat')
  } catch { notify(store.error || 'Gagal catat tindakan', false) }
}
async function removeCharge(c) {
  if (c.is_billed) { notify('Biaya sudah masuk invoice', false); return }
  if (!confirm(`Hapus "${c.description}"?`)) return
  try { await store.deleteCharge(detailVisitId.value, c.id); notify('Biaya dihapus') }
  catch { notify(store.error || 'Gagal hapus', false) }
}

// ── Permintaan obat ke Farmasi (dispensing rawat inap → serah ke ruangan) ──────
// Beda dari "+ Obat (biaya langsung)": obat ini benar-benar diminta ke Farmasi,
// dipotong stok & ditagih saat Farmasi menyerahkan ke ruangan (bukan saat diminta).
const permintaanList = ref([])
const reqPick = ref({ medication_id: '', quantity: 1, dose: '', frequency: '2×/hari', route: 'Oral', duration: '', instructions: '' })
const reqCart = ref([])

async function loadPermintaan() {
  if (!detailVisitId.value) return
  try {
    const r = await ranapApi.permintaanObatList(detailVisitId.value)
    permintaanList.value = r.data?.data ?? []
  } catch { permintaanList.value = [] }
}

function addToReqCart() {
  const f = reqPick.value
  if (!f.medication_id) { notify('Pilih obat dulu', false); return }
  if (!Number.isFinite(Number(f.quantity)) || Number(f.quantity) < 1) { notify('Jumlah minimal 1', false); return }
  if (reqCart.value.some((x) => x.medication_id === f.medication_id)) { notify('Obat sudah ada di daftar', false); return }
  const med = obatList.value.find((o) => o.id === f.medication_id) ?? reqPickObat.value
  reqCart.value.push({
    medication_id: f.medication_id,
    name: med?.name ?? '',
    unit: med?.unit ?? '',
    quantity: Number(f.quantity),
    dose: f.dose || null,
    frequency: f.frequency || null,
    route: f.route || null,
    duration: f.duration || null,
    instructions: f.instructions || null,
  })
  reqPick.value = { medication_id: '', quantity: 1, dose: '', frequency: '2×/hari', route: 'Oral', duration: '', instructions: '' }
  reqPickObat.value = null
}
function removeFromReqCart(i) { reqCart.value.splice(i, 1) }

async function submitPermintaan() {
  if (!reqCart.value.length) { notify('Tambahkan obat ke daftar dulu', false); return }
  busy.value = true
  try {
    await ranapApi.createPermintaanObat(detailVisitId.value, {
      items: reqCart.value.map((x) => ({
        medication_id: x.medication_id,
        quantity:      x.quantity,
        dose:          x.dose,
        frequency:     x.frequency,
        route:         x.route,
        // Durasi digabung ke instructions (backend simpan dose/frequency/route/instructions).
        instructions:  [x.duration ? `Selama ${x.duration}` : '', x.instructions || ''].filter(Boolean).join(' · ') || null,
      })),
    })
    notify('Permintaan obat dikirim ke Farmasi')
    reqCart.value = []
    await loadPermintaan()
  } catch (e) {
    notify(e.response?.data?.message ?? 'Gagal mengirim permintaan', false)
  } finally { busy.value = false }
}

// Rangkai aturan pakai terstruktur jadi satu teks (signa · rute · durasi · catatan).
function reqAturanText(x) {
  return [x.frequency, x.route, x.duration ? `selama ${x.duration}` : '', x.instructions].filter(Boolean).join(' · ')
}
// Obat yang sudah diminta ke Farmasi TAPI belum diserahkan → baris "menunggu
// Farmasi" di Rincian Biaya (biaya baru tercatat saat Farmasi menyerahkan).
const pendingFarmasiItems = computed(() => {
  const out = []
  for (const p of permintaanList.value) {
    if (['DISPENSED', 'CANCELLED'].includes(p.status)) continue
    for (const it of (p.items || [])) {
      out.push({ id: `${p.id}-${it.id}`, name: it.medication?.name ?? '—', quantity: it.quantity, status: p.status })
    }
  }
  return out
})
function reqStatusLabel(s) {
  return { SUBMITTED: 'Diminta', DISPENSING: 'Disiapkan', DISPENSED: 'Diserahkan', CANCELLED: 'Dibatalkan' }[s] ?? s
}
function reqStatusPill(s) {
  return { DISPENSED: 'pill-success', DISPENSING: 'pill-info', SUBMITTED: 'pill-warning', CANCELLED: 'pill-danger' }[s] ?? 'pill-gray'
}

const bedStatusColor = { AVAILABLE: '#16a34a', OCCUPIED: '#1763d4', CLEANING: '#d97706', MAINTENANCE: '#6b7280', RESERVED: '#7c3aed' }
function fmt(dt) { return dt ? new Date(dt).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' }) : '—' }
function rupiah(n) { return 'Rp ' + Number(n || 0).toLocaleString('id-ID') }
const statusPill = (s) => ({
  APPROVED: 'pill-success', SUCCESS: 'pill-success', VERIFIED: 'pill-success',
  REJECTED: 'pill-danger', FAILED: 'pill-danger',
  PENDING: 'pill-warning', DRAFT: 'pill-warning', NONE: 'pill-gray',
}[s] ?? 'pill-gray')
</script>

<template>
  <div class="asuransi-view">
    <!-- HEADER + SUMMARY -->
    <div class="page-head">
      <div>
        <h1>Rawat Inap</h1>
        <p class="sub">Papan kamar, admit/transfer/pulang, CPPT &amp; biaya pasien inap.</p>
      </div>
      <div class="ph-actions">
        <UnitStockActions station="RANAP" />
        <button class="btn btn-secondary" @click="refreshAll">↻ Muat ulang</button>
      </div>
    </div>

    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background: var(--ib)">
          <svg viewBox="0 0 24 24" stroke="var(--it)"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/></svg>
        </div>
        <div><div class="stat-val">{{ stats.aktif }}</div><div class="stat-lbl">Pasien Dirawat</div></div>
      </div>
      <div :class="['stat-card', stats.menunggu ? 'alert-card' : '']">
        <div class="stat-icon" style="background: var(--wb)">
          <svg viewBox="0 0 24 24" stroke="var(--wt)"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div><div class="stat-val" :style="{ color: stats.menunggu ? 'var(--wt)' : '' }">{{ stats.menunggu }}</div><div class="stat-lbl">Menunggu Kamar</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background: var(--sb)">
          <svg viewBox="0 0 24 24" stroke="var(--st)"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div><div class="stat-val" style="color: var(--st)">{{ stats.available }}</div><div class="stat-lbl">Bed Kosong</div></div>
      </div>
    </div>

    <!-- TABS -->
    <div class="nav-tabs">
      <button :class="['nt', tab === 'board' ? 'a' : '']" @click="tab = 'board'">
        <svg viewBox="0 0 24 24"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/></svg>
        Papan Room
      </button>
      <button :class="['nt', tab === 'menunggu' ? 'a' : '']" @click="tab = 'menunggu'">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Menunggu Kamar
        <span v-if="store.menungguKamar.length" class="ntbg alert">{{ store.menungguKamar.length }}</span>
      </button>
      <button :class="['nt', tab === 'aktif' ? 'a' : '']" @click="tab = 'aktif'">
        <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Pasien Aktif
        <span v-if="store.aktif.length" class="ntbg">{{ store.aktif.length }}</span>
      </button>
      <button :class="['nt', tab === 'history' ? 'a' : '']" @click="tab = 'history'; loadHistory()">
        <svg viewBox="0 0 24 24"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 106 5.3L3 8"/><path d="M12 7v5l4 2"/></svg>
        Riwayat
      </button>
    </div>

    <!-- PAPAN ROOM -->
    <section v-if="tab === 'board'" class="tab-pane board">
      <p v-if="store.loading" class="po-state">Memuat…</p>
      <div v-for="room in store.bedBoard" :key="room.id" class="room-card">
        <div class="room-head">
          <strong>{{ room.name }}</strong>
          <span class="kelas">Kelas {{ room.kelas_rawat }} · {{ room.type }}</span>
          <span class="occ">{{ room.occupied }}/{{ room.total }} terisi</span>
        </div>
        <div class="beds">
          <div v-for="bed in room.beds" :key="bed.id" class="bed" :style="{ borderColor: bedStatusColor[bed.status] }">
            <div class="bed-label">{{ bed.label }}</div>
            <div class="bed-status" :style="{ color: bedStatusColor[bed.status] }">{{ bed.status }}</div>
            <div v-if="bed.patient" class="bed-pt" @click="openDetail(bed.patient.visit_id)">
              {{ bed.patient.name }}<br><small>RM {{ bed.patient.no_rm }} · Kls {{ bed.patient.kelas_rawat_hak }}</small>
            </div>
            <button v-if="bed.status === 'CLEANING'" class="bed-clean-btn" @click="markBedAvailable(bed)">
              ✓ Selesai bersih
            </button>
          </div>
          <p v-if="!room.beds.length" class="muted">Belum ada bed</p>
        </div>
      </div>
      <p v-if="!store.loading && !store.bedBoard.length" class="muted">
        Belum ada room. Tambah di Master Data → Fasilitas &amp; Ruang.
      </p>
    </section>

    <!-- MENUNGGU KAMAR -->
    <section v-if="tab === 'menunggu'" class="tab-pane">
      <div class="po-table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th style="width:50px">No.</th><th>Pasien</th><th>No. RM</th>
              <th>Penjamin</th><th>Sejak</th><th class="c">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!store.menungguKamar.length"><td colspan="6" class="po-state">Tidak ada pasien menunggu kamar</td></tr>
            <tr v-for="(p, i) in store.menungguKamar" :key="p.visit_id">
              <td class="muted">{{ i + 1 }}</td>
              <td>{{ p.name }}</td>
              <td class="muted">{{ p.no_rm }}</td>
              <td><span class="pill pill-gray">{{ p.guarantor_type }}</span></td>
              <td class="muted">{{ fmt(p.since) }}</td>
              <td class="c"><button class="btn btn-sm btn-primary" @click="openAdmit(p)">Admit / Pilih Bed</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- PASIEN AKTIF — panel inline 2-kolom (list kiri + detail kanan) dirender
         di bawah pada blok .ri-split (menggantikan modal Detail Pasien lama). -->

    <!-- RIWAYAT PASIEN PULANG -->
    <section v-if="tab === 'history'" class="tab-pane">
      <div class="filter-bar">
        <input type="date" v-model="histFrom" class="filt" title="Dari tanggal" />
        <input type="date" v-model="histTo" class="filt" title="Sampai tanggal" />
        <button class="btn btn-primary" @click="loadHistory">Tampilkan</button>
      </div>
      <div class="po-table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th style="width:50px">No.</th><th>Pasien</th><th>RM</th><th>Penjamin</th>
              <th>Masuk</th><th>Pulang</th><th>Jenis</th><th>Kontrol</th><th>SPRI</th><th class="c">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!histList.length"><td colspan="10" class="po-state">Tidak ada pasien pulang pada rentang ini</td></tr>
            <tr v-for="(h, i) in histList" :key="h.visit_id">
              <td class="muted">{{ i + 1 }}</td>
              <td>{{ h.name }}</td>
              <td class="muted">{{ h.no_rm }}</td>
              <td>{{ h.guarantor_type }}</td>
              <td class="muted">{{ fmt(h.admission_at) }}</td>
              <td class="muted">{{ fmt(h.discharge_at) }}</td>
              <td>{{ h.discharge_type }}</td>
              <td class="muted">{{ h.follow_up_date || '—' }}</td>
              <td>
                <span v-if="h.spri" class="pill" :class="statusPill(h.spri.status === 'SUCCESS' ? 'APPROVED' : h.spri.status === 'FAILED' ? 'REJECTED' : 'PENDING')">
                  {{ h.spri.status }}<template v-if="h.spri.no_spri"> · {{ h.spri.no_spri }}</template>
                </span>
                <span v-else class="muted">—</span>
              </td>
              <td class="c">
                <div class="action-row" v-if="h.guarantor_type === 'BPJS' && h.no_sep">
                  <button class="btn btn-sm btn-info" @click="openSep(h)">SEP</button>
                  <button v-if="!h.spri || h.spri.status === 'FAILED'" class="btn btn-sm btn-secondary" @click="issueSpri(h)">+ SPRI</button>
                  <button v-if="h.spri && h.spri.status === 'SUCCESS'" class="btn btn-sm btn-secondary" @click="editSpri(h)">Edit SPRI</button>
                  <button v-if="h.spri && h.spri.status !== 'SUCCESS'" class="btn btn-sm btn-warning" @click="removeSpri(h)">Hapus</button>
                </div>
                <span v-else class="muted">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- MODAL SEP -->
    <div v-if="showSep" class="modal-bg" @click.self="showSep = false">
      <div class="modal">
        <h3>SEP — {{ sepPt?.name }}</h3>
        <template v-if="sepData">
          <p class="muted2">No. SEP: <strong>{{ sepData.no_sep }}</strong> · No. Kartu: {{ sepData.bpjs_number || '—' }}</p>
          <p class="muted2">Kelas hak: {{ sepData.kelas_rawat_hak }} · Masuk {{ fmt(sepData.admission_at) }}</p>
          <hr />
          <h4>Update SEP</h4>
          <label>Kelas rawat<input v-model="sepForm.kls_rawat" placeholder="mis. 2" /></label>
          <label>Diagnosa awal<input v-model="sepForm.diag_awal" placeholder="kode/teks diagnosa" /></label>
          <label>Catatan<textarea v-model="sepForm.catatan" rows="2"></textarea></label>
          <label>No. telp<input v-model="sepForm.no_telp" /></label>
          <label>Katarak
            <select v-model="sepForm.katarak"><option value="0">Tidak</option><option value="1">Ya</option></select>
          </label>
          <div class="modal-actions">
            <button @click="showSep = false">Tutup</button>
            <button class="btn-primary" :disabled="busy" @click="submitSep">{{ busy ? '…' : 'Simpan ke VClaim' }}</button>
          </div>
          <hr />
          <h4>Tanggal Pulang (BPJS)</h4>
          <p class="muted2" v-if="sepData.discharge_at">Pasien dipulangkan {{ fmt(sepData.discharge_at) }}. Laporkan/koreksi tanggal pulang ke SEP bila pelaporan otomatis gagal.</p>
          <p class="muted2" v-else>Pasien belum dipulangkan — lapor tgl pulang tersedia setelah pemulangan.</p>
          <label>Tgl pulang<input v-model="sepTglPulang" type="date" :disabled="!sepData.discharge_at" /></label>
          <div class="modal-actions">
            <button class="btn-warning" :disabled="busy || !sepData.discharge_at" @click="submitTglPulang">{{ busy ? '…' : 'Lapor Tgl Pulang ke BPJS' }}</button>
          </div>
        </template>
        <p v-else class="muted">Memuat SEP…</p>
      </div>
    </div>

    <!-- MODAL KIRIM BEDAH -->
    <div v-if="showKirimBedah" class="modal-bg" @click.self="showKirimBedah = false">
      <div class="modal">
        <h3>Kirim ke Bedah — {{ kirimBedahPt?.name }}</h3>
        <p class="muted2">Bed {{ kirimBedahPt?.bed }} ditahan selama operasi. Setelah selesai, pasien kembali ke baris rawat inap.</p>

        <!-- Fase 8C: pasien pre-op sudah punya jadwal operasi (dari planning dokter) →
             pakai jadwal itu, tak perlu input paket ulang. -->
        <div v-if="kirimBedahPt?.has_surgery_schedule" class="ri-preop-note">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          <span>Pasien sudah punya <strong>jadwal operasi</strong> dari perencanaan dokter. Sistem akan memakai jadwal itu — tidak perlu pilih paket lagi.</span>
        </div>

        <template v-else>
          <h4>Jadwal Operasi (opsional)</h4>
          <label>Paket bedah
            <input v-model="paketSearch" placeholder="cari paket bedah…" class="add-search" />
            <select v-model="kirimBedahForm.surgery_package_id">
              <option value="">— Tanpa jadwal (langsung antrean bedah) —</option>
              <option v-for="pk in filteredPaketBedah" :key="pk.id" :value="pk.id">{{ pk.name }}{{ pk.code ? ' · ' + pk.code : '' }}</option>
            </select>
          </label>
          <label v-if="kirimBedahForm.surgery_package_id">Tgl rencana
            <input v-model="kirimBedahForm.scheduled_date" type="date" />
          </label>
          <p class="hint">Pilih paket untuk membuat jadwal otomatis → pasien tampil di papan Bedah Terjadwal. Dikosongkan = pasien langsung masuk antrean bedah.</p>
        </template>
        <div class="modal-actions">
          <button @click="showKirimBedah = false">Batal</button>
          <button class="btn-primary" @click="confirmKirimBedah">Kirim ke Bedah</button>
        </div>
      </div>
    </div>

    <!-- MODAL ADMIT -->
    <div v-if="showAdmit" class="modal-bg" @click.self="showAdmit = false">
      <div class="modal">
        <h3>Admit: {{ admitVisit?.name }}</h3>
        <label>Bed tujuan
          <select v-model="admitForm.bed_id">
            <option value="">— pilih bed kosong —</option>
            <option v-for="b in allAvailableBeds" :key="b.id" :value="b.id">{{ b.label }} ({{ b.room }}, Kelas {{ b.kelas }})</option>
          </select>
        </label>
        <label>Kelas hak (penjamin)<input v-model="admitForm.kelas_hak" placeholder="mis. 2 / 1 / VIP" /></label>
        <label>Tgl masuk (opsional)<input v-model="admitForm.admission_at" type="datetime-local" /></label>
        <div class="modal-actions">
          <button @click="showAdmit = false">Batal</button>
          <button class="btn-primary" :disabled="busy" @click="submitAdmit">{{ busy ? '…' : 'Admit' }}</button>
        </div>
      </div>
    </div>

    <!-- MODAL TRANSFER -->
    <div v-if="showTransfer" class="modal-bg" @click.self="showTransfer = false">
      <div class="modal">
        <h3>Pindah Kamar: {{ transferPt?.name }}</h3>
        <label>Bed tujuan
          <select v-model="transferForm.bed_id">
            <option value="">— pilih bed kosong —</option>
            <option v-for="b in allAvailableBeds" :key="b.id" :value="b.id">{{ b.label }} ({{ b.room }}, Kelas {{ b.kelas }})</option>
          </select>
        </label>
        <label>Alasan pindah
          <select v-model="transferForm.reason">
            <option value="TRANSFER">Pindah biasa (kelas sama, tarif tetap)</option>
            <option value="TITIP_KELAS">Titip kelas (kamar hak penuh, tarif tetap kelas hak)</option>
            <option value="UPGRADE_KELAS">Naik kelas (atas permintaan — tarif ikut naik)</option>
            <option value="DOWNGRADE_KELAS">Turun kelas (tarif ikut turun)</option>
          </select>
        </label>
        <div class="modal-actions">
          <button @click="showTransfer = false">Batal</button>
          <button class="btn-primary" :disabled="busy" @click="submitTransfer">{{ busy ? '…' : 'Pindahkan' }}</button>
        </div>
      </div>
    </div>

    <!-- MODAL DISCHARGE -->
    <div v-if="showDischarge" class="modal-bg" @click.self="showDischarge = false">
      <div class="modal">
        <h3>Pulangkan: {{ dischargePt?.name }}</h3>
        <p class="hint">Room charge akan digenerate (LOS × tarif kelas hak) lalu pasien diteruskan ke Kasir.</p>
        <label>Jenis pulang
          <select v-model="dischargeForm.discharge_type">
            <option value="PULANG_SEHAT">Pulang sehat</option>
            <option value="RUJUK">Rujuk</option>
            <option value="APS">Atas permintaan sendiri (APS)</option>
            <option value="MENINGGAL">Meninggal</option>
          </select>
        </label>
        <label>Resume pulang (opsional)<textarea v-model="dischargeForm.summary" rows="3"></textarea></label>

        <!-- Rencana kontrol (SEMUA penjamin) -->
        <fieldset class="discharge-section">
          <legend>Rencana Kontrol</legend>
          <label>Tgl kontrol<input v-model="dischargeForm.follow_up_date" type="date" /></label>
          <label>Catatan kontrol<input v-model="dischargeForm.follow_up_reason" placeholder="mis. kontrol jahitan / evaluasi pasca-op" /></label>
        </fieldset>

        <!-- SPRI (BPJS saja) -->
        <fieldset v-if="dischargePt?.guarantor_type === 'BPJS'" class="discharge-section spri-section">
          <legend>SPRI (BPJS)</legend>
          <p class="muted2">Surat Perintah Rawat Inap akan diterbitkan ke VClaim (non-blocking; bila gagal bisa diulang dari tab Riwayat).</p>
          <label>Tgl rencana SPRI<input v-model="dischargeForm.spri_tgl_rencana" type="date" /></label>
        </fieldset>

        <!-- Obat pulang (opsional) → tagih + teruskan ke Farmasi -->
        <fieldset class="discharge-section obat-section">
          <legend>Obat Pulang</legend>
          <p class="muted2">Obat akan ditagih di kwitansi rawat inap lalu diteruskan ke Farmasi untuk diserahkan (stok dipotong). Kosongkan bila tidak ada.</p>
          <div class="add-row">
            <input v-model="obatPulangSearch" placeholder="cari obat…" @input="searchObatPulang" class="add-search" style="flex:1" />
          </div>
          <select @change="addObatPulang(obatPulangOptions.find(o => String(o.id) === $event.target.value)); $event.target.value=''">
            <option value="">— pilih obat untuk ditambahkan —</option>
            <option v-for="o in obatPulangOptions" :key="o.id" :value="o.id">{{ o.name }} — {{ rupiah(o.price) }}</option>
          </select>

          <table v-if="obatPulangList.length" class="obat-pulang-tbl">
            <thead><tr><th>Obat</th><th>Qty</th><th>Aturan pakai</th><th></th></tr></thead>
            <tbody>
              <tr v-for="(o, i) in obatPulangList" :key="o.medication_id">
                <td>{{ o.name }}</td>
                <td><input v-model.number="o.quantity" type="number" min="1" style="width:60px" /></td>
                <td>
                  <input v-model="o.dose" placeholder="dosis (mis. 1 tab)" class="op-rule" />
                  <input v-model="o.frequency" placeholder="frekuensi (mis. 3×/hari)" class="op-rule" />
                </td>
                <td><button class="del-x" @click="removeObatPulang(i)" title="Hapus">×</button></td>
              </tr>
            </tbody>
          </table>
          <p v-else class="muted">Belum ada obat pulang.</p>
        </fieldset>

        <div class="modal-actions">
          <button @click="showDischarge = false">Batal</button>
          <button class="btn-primary" :disabled="busy" @click="submitDischarge">{{ busy ? '…' : 'Pulangkan' }}</button>
        </div>
      </div>
    </div>

    <!-- PASIEN AKTIF: panel inline 2-kolom (list pasien kiri + detail kanan) -->
    <section v-if="tab === 'aktif'" class="tab-pane">
      <div class="ri-split">
        <!-- KIRI: daftar pasien aktif -->
        <aside class="ri-list">
          <div v-if="!store.aktif.length" class="ri-list-empty">Tidak ada pasien rawat inap aktif.</div>
          <button
            v-for="p in store.aktif" :key="p.visit_id"
            type="button" class="ri-pcard" :class="{ sel: detailVisitId === p.visit_id }"
            @click="openDetail(p.visit_id)"
          >
            <div class="ri-pcard-top">
              <strong class="ri-pcard-name">{{ p.name }}</strong>
              <span v-if="p.inpatient_reason === 'PRE_OP'" class="ri-badge ri-preop" title="Pre-op rawat inap — menunggu operasi terjadwal">PRE-OP</span>
              <span v-else-if="p.inpatient_reason === 'OBSERVASI'" class="ri-badge ri-obs" title="Observasi/pemeriksaan">OBS</span>
            </div>
            <div class="ri-pcard-sub">RM {{ p.no_rm }} · {{ p.room }}/{{ p.bed }} · Kls {{ p.kelas_rawat_hak }}</div>
            <div class="ri-pcard-since">Masuk {{ fmt(p.admission_at) }}</div>
          </button>
        </aside>

        <!-- KANAN: detail pasien terpilih -->
        <div class="ri-detail">
          <div v-if="!detailVisitId" class="ri-detail-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/></svg>
            <p>Pilih pasien di kiri untuk melihat detail, CPPT &amp; biaya.</p>
          </div>
          <template v-else-if="store.detail">
          <div class="det-head">
            <div class="det-id">
              <strong>{{ store.detail.visit?.patient?.name }}</strong>
              <span class="det-rm">RM {{ store.detail.visit?.patient?.no_rm }}</span>
            </div>
            <div class="det-actions">
              <button class="btn btn-sm btn-secondary" @click="openDocModal">Dokumen RM</button>
              <button v-if="selectedPt" class="btn btn-sm btn-secondary" @click="openTransfer(selectedPt)">Pindah</button>
              <button v-if="selectedPt?.guarantor_type === 'BPJS' && selectedPt?.no_sep" class="btn btn-sm btn-info" @click="openSep(selectedPt)">SEP</button>
              <button v-if="selectedPt" class="btn btn-sm btn-secondary" @click="doKirimBedah(selectedPt)">→ Bedah</button>
              <button v-if="selectedPt" class="btn btn-sm btn-primary" @click="openDischarge(selectedPt)">Pulang</button>
            </div>
          </div>
          <div class="det-meta">
            <span><i>Room/Bed</i> {{ store.detail.visit?.room?.name }} / {{ store.detail.visit?.bed?.label }}</span>
            <span><i>Kelas hak</i> {{ store.detail.visit?.kelas_rawat_hak }}</span>
            <span><i>Masuk</i> {{ fmt(store.detail.visit?.admission_at) }}</span>
            <span v-if="store.detail.visit?.dpjp?.name"><i>DPJP</i> {{ store.detail.visit?.dpjp?.name }}</span>
          </div>

          <!-- Tab dalam detail (Hasil Eksternal kini chip→modal kecil di header CPPT) -->
          <nav class="dtabs">
            <button :class="{ on: detailTab === 'cppt' }" @click="detailTab = 'cppt'">CPPT &amp; Observasi</button>
            <button :class="{ on: detailTab === 'biaya' }" @click="detailTab = 'biaya'">Tindakan, Obat &amp; Biaya</button>
          </nav>

          <!-- TAB CPPT — terintegrasi multi-PPA (SOAP) -->
          <div v-show="detailTab === 'cppt'">
            <div class="cppt-form">
              <h4>
                {{ cpptEditId ? 'Edit Catatan (CPPT)' : '+ Catatan Perkembangan Terintegrasi (CPPT)' }}
                <span class="ppa-badge" :class="'ppa-' + myPpa">{{ PPA_LABEL[myPpa] }}</span>
                <button type="button" class="ext-chip" @click="openExtDocs" title="Hasil lab/radiologi/EKG dari pihak ketiga">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                  Hasil Eksternal<span v-if="docList.length" class="ext-count">{{ docList.length }}</span>
                </button>
              </h4>
              <div class="soap-grid">
                <label class="soap-cell"><span class="soap-tag">S</span>
                  <textarea v-model="cpptForm.soap_s" rows="2" placeholder="Subjektif — keluhan/anamnesis"></textarea></label>
                <label class="soap-cell"><span class="soap-tag">O</span>
                  <textarea v-model="cpptForm.soap_o" rows="2" placeholder="Objektif — pemeriksaan/hasil"></textarea></label>
                <label class="soap-cell"><span class="soap-tag">A</span>
                  <textarea v-model="cpptForm.soap_a" rows="2" placeholder="Assessment — diagnosis/penilaian"></textarea></label>
                <label class="soap-cell"><span class="soap-tag">P</span>
                  <textarea v-model="cpptForm.soap_p" rows="2" placeholder="Plan — rencana asuhan"></textarea></label>
              </div>
              <label class="instruksi-label">Instruksi PPA
                <textarea v-model="cpptForm.instruksi" rows="2" placeholder="Instruksi (obat/tindakan/observasi)…"></textarea>
              </label>
              <details class="ttv-collapse">
                <summary>Tanda Vital & Status Mata (opsional)</summary>
                <div class="ttv-row">
                  <label>TD <input v-model.number="cpptForm.td_sistol" type="number" placeholder="120" /></label>
                  <span class="ttv-sep">/</span>
                  <label><input v-model.number="cpptForm.td_diastol" type="number" placeholder="80" /> mmHg</label>
                  <label>Nadi <input v-model.number="cpptForm.nadi" type="number" placeholder="80" /></label>
                  <label>Suhu <input v-model.number="cpptForm.suhu" type="number" step="0.1" placeholder="36.5" /></label>
                  <label>RR <input v-model.number="cpptForm.respirasi" type="number" placeholder="20" /></label>
                  <label>SpO₂ <input v-model.number="cpptForm.spo2" type="number" placeholder="98" /></label>
                  <label>KGD <input v-model.number="cpptForm.kgd" type="number" placeholder="110" /></label>
                  <label>Nyeri <input v-model.number="cpptForm.pain_scale" type="number" min="0" max="10" placeholder="0" /></label>
                </div>
                <!-- Refraksi ringkas (RS Mata): visus + TIO/tonometri -->
                <div class="refraksi-row">
                  <span class="refraksi-head">Status Mata</span>
                  <label>Visus OD <input v-model="cpptForm.visus_od" type="text" placeholder="6/6" /></label>
                  <label>Visus OS <input v-model="cpptForm.visus_os" type="text" placeholder="6/6" /></label>
                  <label>TIO OD <input v-model.number="cpptForm.iop_od" type="number" step="0.1" placeholder="15" /> mmHg</label>
                  <label>TIO OS <input v-model.number="cpptForm.iop_os" type="number" step="0.1" placeholder="15" /> mmHg</label>
                  <label>Metode
                    <select v-model="cpptForm.iop_method">
                      <option value="">—</option>
                      <option value="NCT">NCT</option>
                      <option value="Goldmann">Goldmann (GAT)</option>
                      <option value="Schiotz">Schiotz</option>
                      <option value="Palpasi">Palpasi</option>
                    </select>
                  </label>
                </div>
              </details>
              <div class="cppt-actions">
                <button class="btn-primary btn-press btn-add" @click="submitCppt">{{ cpptEditId ? 'Simpan Perubahan' : '+ Tambah CPPT' }}</button>
                <button v-if="cpptEditId" class="btn-ghost btn-press" @click="cancelEditCppt">Batal</button>
              </div>
            </div>

            <div class="cppt-timeline">
              <div v-for="e in cpptList" :key="e.id" class="cppt-item" :class="'ppa-edge-' + (e.ppa_role || 'LAINNYA')">
                <div class="cppt-meta">
                  <span class="ppa-badge" :class="'ppa-' + (e.ppa_role || 'LAINNYA')">{{ PPA_LABEL[e.ppa_role] || 'PPA Lain' }}</span>
                  <strong>{{ e.by || '—' }}</strong>
                  <span class="cppt-time">{{ fmt(e.at) }}</span>
                  <span v-if="e.edited_at" class="cppt-edited">(diedit)</span>
                </div>
                <div v-if="e.td_sistol || e.nadi || e.suhu || e.spo2 || e.kgd || e.pain_scale != null" class="cppt-ttv">
                  <span v-if="e.td_sistol">TD {{ e.td_sistol }}/{{ e.td_diastol }}</span>
                  <span v-if="e.nadi">N {{ e.nadi }}</span>
                  <span v-if="e.suhu">S {{ e.suhu }}°C</span>
                  <span v-if="e.respirasi">RR {{ e.respirasi }}</span>
                  <span v-if="e.spo2">SpO₂ {{ e.spo2 }}%</span>
                  <span v-if="e.kgd">KGD {{ e.kgd }}</span>
                  <span v-if="e.pain_scale != null">Nyeri {{ e.pain_scale }}/10</span>
                </div>
                <div v-if="e.visus_od || e.visus_os || e.iop_od || e.iop_os" class="cppt-mata">
                  <span v-if="e.visus_od || e.visus_os">👁 Visus OD {{ e.visus_od || '—' }} · OS {{ e.visus_os || '—' }}</span>
                  <span v-if="e.iop_od || e.iop_os">TIO OD {{ e.iop_od || '—' }} · OS {{ e.iop_os || '—' }} mmHg<template v-if="e.iop_method"> ({{ e.iop_method }})</template></span>
                </div>
                <div class="cppt-soap" :class="{ clamped: isLongCppt(e) && !cpptExpanded[e.id] }">
                  <p v-if="e.soap_s"><b>S:</b> {{ e.soap_s }}</p>
                  <p v-if="e.soap_o"><b>O:</b> {{ e.soap_o }}</p>
                  <p v-if="e.soap_a"><b>A:</b> {{ e.soap_a }}</p>
                  <p v-if="e.soap_p"><b>P:</b> {{ e.soap_p }}</p>
                  <p v-if="e.instruksi" class="cppt-instruksi"><b>Instruksi:</b> {{ e.instruksi }}</p>
                  <p v-if="e.notes" class="cppt-notes-legacy">{{ e.notes }}</p>
                </div>
                <button v-if="isLongCppt(e)" class="btn-link cppt-toggle" @click="toggleExpand(e.id)">
                  {{ cpptExpanded[e.id] ? '▲ Lebih sedikit' : '▼ Selengkapnya' }}
                </button>
                <div class="cppt-foot">
                  <span v-if="e.verified_at" class="cppt-verified">✓ Diverifikasi DPJP {{ e.verified_by }} · {{ fmt(e.verified_at) }}</span>
                  <span v-else class="cppt-unverified">Belum diverifikasi DPJP</span>
                  <span class="cppt-foot-actions">
                    <button class="btn-link" @click="editCppt(e)">Edit</button>
                    <button v-if="isDokter && !e.verified_at" class="btn-link btn-verify" @click="verifyCppt(e)">Verifikasi</button>
                  </span>
                </div>
              </div>
              <p v-if="!cpptList.length" class="muted">Belum ada catatan CPPT.</p>
            </div>
          </div>

          <!-- TAB BIAYA -->
          <div v-show="detailTab === 'biaya'">
            <div class="add-box">
              <h4>+ Tindakan</h4>
              <!-- Terpilih → chip -->
              <div v-if="selectedTindakan" class="picked-chip">
                <span class="picked-name">{{ selectedTindakan.name }} — {{ rupiah(selectedTindakan.price) }}</span>
                <button class="picked-x" @click="clearTindakan" title="Ganti tindakan">×</button>
              </div>
              <!-- Belum → typeahead picker (input + dropdown hasil) -->
              <div v-else class="combo">
                <input
                  v-model="tindakanSearch" class="add-search"
                  placeholder="Ketik nama tindakan, atau klik untuk lihat semua…"
                  @focus="tindakanComboOpen = true" @blur="closeTindakanComboSoon"
                />
                <div v-if="tindakanComboOpen && filteredTindakan.length" class="combo-drop">
                  <div v-for="t in filteredTindakan" :key="t.id" class="combo-item" @mousedown.prevent="pickTindakanItem(t)">
                    <span class="combo-name">{{ t.name }}</span>
                    <span class="combo-price">{{ rupiah(t.price) }}</span>
                  </div>
                  <div v-if="tindakanOverflow" class="combo-more">+{{ tindakanOverflow }} lainnya — ketik untuk mempersempit</div>
                </div>
                <div v-else-if="tindakanComboOpen && tindakanSearch.trim()" class="combo-empty">Tindakan tidak ditemukan</div>
              </div>
              <div class="add-row add-row-end">
                <button class="btn-primary btn-press btn-add" :disabled="!selectedTindakan" @click="submitTindakan">+ Tambah</button>
              </div>
              <p class="req-hint">Harga mengikuti Buku Tarif sesuai penjamin pasien (qty 1).</p>
            </div>

            <!-- Permintaan obat ke Farmasi (dispensing ke ruangan) -->
            <div class="req-obat">
              <h4>Permintaan Obat ke Farmasi</h4>
              <p class="req-hint">Perawat minta obat ke Farmasi → Farmasi siapkan &amp; antar ke ruangan. <strong>Stok Farmasi dipotong</strong> &amp; biaya tercatat saat diserahkan (bukan saat diminta).</p>
              <div class="add-box">
                <!-- Terpilih → chip -->
                <div v-if="reqPickObat" class="picked-chip">
                  <span class="picked-name">{{ reqPickObat.name }}{{ reqPickObat.is_active === false ? ' · (nonaktif)' : '' }}</span>
                  <button class="picked-x" @click="clearReqObat" title="Ganti obat">×</button>
                </div>
                <!-- Belum → typeahead picker (server search via searchObat) -->
                <div v-else class="combo">
                  <input
                    v-model="obatSearch" class="add-search"
                    placeholder="Ketik nama / kode obat, atau klik untuk lihat semua…"
                    @input="searchObat" @focus="obatComboOpen = true" @blur="closeObatComboSoon"
                  />
                  <div v-if="obatComboOpen && obatDropList.length" class="combo-drop">
                    <div v-for="o in obatDropList" :key="o.id" class="combo-item" @mousedown.prevent="pickReqObat(o)">
                      <span class="combo-name">{{ o.name }}<span v-if="o.is_active === false" class="combo-inactive">nonaktif</span></span>
                      <span class="combo-price">{{ o.unit || '' }}</span>
                    </div>
                    <div v-if="obatOverflow" class="combo-more">+{{ obatOverflow }} lainnya — ketik untuk mempersempit</div>
                  </div>
                  <div v-else-if="obatComboOpen && obatSearch.trim()" class="combo-empty">Obat tidak ditemukan</div>
                </div>
                <div class="req-fields">
                  <input v-model.number="reqPick.quantity" type="number" min="1" placeholder="Qty" style="width:58px" title="Jumlah" />
                  <input v-model="reqPick.dose" placeholder="dosis (cth: 1 tablet)" style="min-width:120px" title="Dosis" />
                  <input v-model="reqPick.frequency" list="ranapSignaOpts" placeholder="frekuensi" style="min-width:104px" autocomplete="off" title="Signa / Frekuensi" />
                  <datalist id="ranapSignaOpts"><option v-for="s in SIGNA_OPTS" :key="s" :value="s" /></datalist>
                  <select v-model="reqPick.route" style="min-width:96px" title="Rute pemberian">
                    <option value="">— rute —</option>
                    <option v-for="r in ROUTE_OPTS" :key="r" :value="r">{{ r }}</option>
                  </select>
                  <select v-model="reqPick.duration" style="min-width:88px" title="Durasi">
                    <option value="">— durasi —</option>
                    <option v-for="d in DURASI_OPTS" :key="d" :value="d">{{ d }}</option>
                  </select>
                  <input v-model="reqPick.instructions" placeholder="catatan (opsional)" style="min-width:110px" title="Catatan" />
                  <button class="btn-primary btn-press btn-add" :disabled="!reqPickObat" @click="addToReqCart">+ Tambah</button>
                </div>
              </div>

              <table v-if="reqCart.length" class="bill">
                <thead><tr><th>Obat</th><th>Qty</th><th>Dosis</th><th>Aturan Pakai</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="(x, i) in reqCart" :key="x.medication_id">
                    <td>{{ x.name }}</td><td>{{ x.quantity }} {{ x.unit }}</td>
                    <td>{{ x.dose || '—' }}</td>
                    <td>{{ reqAturanText(x) || '—' }}</td>
                    <td><button class="del-x" @click="removeFromReqCart(i)" title="Hapus">×</button></td>
                  </tr>
                </tbody>
              </table>
              <div class="req-actions">
                <button class="btn-primary btn-press" :disabled="busy || !reqCart.length" @click="submitPermintaan">Kirim ke Farmasi</button>
              </div>

              <h4 style="margin-top:1rem">Riwayat Permintaan</h4>
              <table class="bill">
                <thead><tr><th>Waktu</th><th>Obat</th><th>Status</th><th>Diserahkan oleh</th></tr></thead>
                <tbody>
                  <tr v-for="p in permintaanList" :key="p.id">
                    <td>{{ fmt(p.created_at) }}</td>
                    <td>{{ (p.items || []).map(it => `${it.medication?.name ?? '—'} ×${it.quantity}`).join(', ') }}</td>
                    <td><span class="pill" :class="reqStatusPill(p.status)">{{ reqStatusLabel(p.status) }}</span></td>
                    <td>{{ p.dispensed_by?.name || '—' }}</td>
                  </tr>
                  <tr v-if="!permintaanList.length"><td colspan="4" class="muted">Belum ada permintaan obat</td></tr>
                </tbody>
              </table>
            </div>

            <h4>Rincian Biaya Berjalan</h4>
            <table class="bill">
              <thead><tr><th>Tgl</th><th>Jenis</th><th>Deskripsi</th><th>Qty</th><th>Harga</th><th>Total</th><th></th></tr></thead>
              <tbody>
                <!-- Provisional: obat diminta ke Farmasi, belum diserahkan (belum ditagih) -->
                <tr v-for="x in pendingFarmasiItems" :key="x.id" class="row-pending">
                  <td>—</td>
                  <td><span class="pill pill-warning">Menunggu Farmasi</span></td>
                  <td>{{ x.name }} <small class="muted">({{ reqStatusLabel(x.status) }})</small></td>
                  <td>{{ x.quantity }}</td>
                  <td colspan="2" class="muted">biaya saat diserahkan</td>
                  <td></td>
                </tr>
                <tr v-for="c in store.detail.charges" :key="c.id">
                  <td>{{ fmt(c.created_at || c.charge_date) }}</td><td>{{ c.charge_type }}</td><td>{{ c.description }}</td>
                  <td>{{ c.quantity }}</td><td>{{ rupiah(c.unit_price) }}</td><td>{{ rupiah(c.total_price) }}</td>
                  <td><button v-if="!c.is_billed" class="del-x" @click="removeCharge(c)" title="Hapus">×</button></td>
                </tr>
                <tr v-if="!store.detail.charges?.length && !pendingFarmasiItems.length"><td colspan="7" class="muted">Belum ada biaya</td></tr>
              </tbody>
            </table>
            <p class="total">Total berjalan: <strong>{{ rupiah(store.detail.running_bill?.total) }}</strong></p>
          </div>

          </template>
        </div><!-- /.ri-detail -->
      </div><!-- /.ri-split -->
    </section>

    <!-- MODAL DOKUMEN RM (Form Registry) — tombol "Dokumen RM" di header kartu pasien -->
    <div v-if="showDocModal && detailVisitId" class="modal-bg modal-bg-docrm" @click.self="showDocModal = false">
      <div class="modal wide">
        <h3>Dokumen Rekam Medis — {{ store.detail?.visit?.patient?.name }}</h3>
        <p class="muted2">Pengkajian awal medis &amp; keperawatan (≤24 jam masuk), keselamatan, edukasi, rekonsiliasi, transfer, dan resume medis (saat pulang). Diisi via UI → dokumen resmi ber-TTD.</p>
        <FormDocsBrowser
          station="ranap"
          :visit-id="detailVisitId"
          :patient-id="store.detail?.visit?.patient?.id || null"
          :sections="[
            { key: 'pengantar_dirawat', label: 'Surat Pengantar Dirawat' },
            { key: 'pengkajian_awal',   label: 'Pengkajian Awal Medis' },
            { key: 'asuhan_keperawatan', label: 'Asesmen Awal Keperawatan' },
            { key: 'keselamatan',       label: 'Keselamatan (Risiko Jatuh)' },
            { key: 'edukasi',           label: 'Edukasi Terintegrasi' },
            { key: 'obat',              label: 'Rekonsiliasi Obat' },
            { key: 'transfer',          label: 'Transfer Pasien' },
            { key: 'ringkasan_pulang',  label: 'Resume Medis (Pulang)' },
          ]"
        />
        <div class="modal-actions"><button @click="showDocModal = false">Tutup</button></div>
      </div>
    </div>

    <!-- MODAL HASIL EKSTERNAL (kecil) — dibuka dari chip di header CPPT -->
    <div v-if="showExtDocs && detailVisitId" class="modal-bg" @click.self="showExtDocs = false">
      <div class="modal">
        <h3>Hasil Eksternal — {{ store.detail?.visit?.patient?.name }}</h3>
        <p class="req-hint">Lampirkan hasil lab/radiologi/EKG dari pihak ketiga (PDF/JPG/PNG, maks 10&nbsp;MB).</p>
        <div class="doc-upload">
          <select v-model="docForm.category" class="doc-cat">
            <option value="LAB">Lab</option>
            <option value="RADIOLOGI">Radiologi</option>
            <option value="EKG">EKG</option>
            <option value="LAINNYA">Lainnya</option>
          </select>
          <input v-model="docForm.title" class="doc-title" placeholder="Judul (opsional, default = nama berkas)" />
          <input ref="docFileInput" type="file" class="doc-file" accept=".pdf,.jpg,.jpeg,.png" @change="onDocFile" />
          <button class="btn-primary btn-press btn-add" :disabled="busy" @click="submitDocument">{{ busy ? '…' : 'Unggah' }}</button>
        </div>

        <table class="bill">
          <thead><tr><th>Waktu</th><th>Kategori</th><th>Judul</th><th>Oleh</th><th>Berkas</th><th></th></tr></thead>
          <tbody>
            <tr v-for="d in docList" :key="d.id">
              <td>{{ fmt(d.at) }}</td>
              <td><span class="pill pill-gray">{{ d.category }}</span></td>
              <td>{{ d.title }}</td>
              <td>{{ d.by || '—' }}</td>
              <td><a v-if="d.file_url" :href="d.file_url" target="_blank" rel="noopener">Lihat</a><span v-else class="muted">—</span></td>
              <td><button class="del-x" @click="removeDocument(d)" title="Hapus">×</button></td>
            </tr>
            <tr v-if="!docList.length"><td colspan="6" class="muted">Belum ada hasil eksternal.</td></tr>
          </tbody>
        </table>
        <div class="modal-actions"><button @click="showExtDocs = false">Tutup</button></div>
      </div>
    </div>

    <!-- Resume Medis RI auto-buka saat discharge (FormRMRenderer meng-Teleport modalnya
         sendiri; kartu pemicu disembunyikan karena dibuka via autoOpen). -->
    <div v-if="showResumeRanap && resumeRanapTpl" style="display:none">
      <FormRMRenderer
        :template="resumeRanapTpl"
        :visit-id="resumeRanapVisitId"
        :auto-open="true"
        @close="closeResumeRanap"
      />
    </div>

    <div v-if="toast" class="toast" :class="{ err: !toast.ok }">{{ toast.msg }}</div>
  </div>
</template>

<style scoped>
/* ===== Selaras AsuransiView (tokens.css) ===== */
.asuransi-view { padding: 1rem 1.25rem; font-family: var(--font-sans); }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1rem; gap: 1rem; }
.ph-actions { display: flex; align-items: center; gap: 8px; }
.page-head h1 { font-family: var(--font-display); font-size: 22px; margin: 0; color: var(--td); }
.page-head .sub { font-size: 12px; color: var(--tu); margin: 4px 0 0; }

.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.6rem; margin-bottom: 1rem; }
.stat-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 11px; padding: 0.75rem; display: flex; align-items: center; gap: 9px; }
.stat-card.alert-card { border-color: var(--wbd); }
.stat-icon { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-icon svg { width: 16px; height: 16px; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.stat-val { font-size: 18px; font-weight: 700; color: var(--td); font-family: var(--font-mono); }
.stat-lbl { font-size: 10.5px; color: var(--tu); }

.nav-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--gb); padding: 0 4px; margin-bottom: 1rem; flex-wrap: wrap; }
.nt { padding: 0.6rem 1rem; font-size: 12px; font-weight: 500; color: var(--tu); background: none; border: none; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: var(--font-sans); display: inline-flex; align-items: center; gap: 6px; }
.nt:hover { color: var(--td); }
.nt.a { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }
.nt svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.ntbg { font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 20px; background: var(--gl); color: var(--ga); border: 1px solid var(--ga); }
.ntbg.alert { background: var(--eb); color: var(--et); border: 1px solid var(--ebd); }

.tab-pane { display: flex; flex-direction: column; gap: 0.85rem; }

.filter-bar { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
.filt { height: 32px; padding: 0 10px; border-radius: 7px; border: 1px solid var(--gb); background: var(--bc); font-size: 12px; font-family: inherit; color: var(--td); }
.filt:focus { outline: none; border-color: var(--ga); }

/* Tabel (po-table) */
.po-table-wrap { background: var(--bc); border: 1px solid var(--gb); border-radius: 8px; overflow-x: auto; }
.po-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.po-table th, .po-table td { padding: 9px 12px; text-align: left; border-bottom: 1px solid var(--gb); color: var(--td); }
.po-table th { background: var(--bs); font-weight: 600; color: var(--tm); font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; white-space: nowrap; }
.po-table td.r, .po-table th.r { text-align: right; font-variant-numeric: tabular-nums; }
.po-table td.c, .po-table th.c { text-align: center; }
.po-table td.muted, .po-table .muted { color: var(--tu); }
.po-table tbody tr:hover { background: var(--bs); }
.po-state { text-align: center; padding: 24px; color: var(--tu); font-size: 12.5px; }
/* Nama pasien di tabel: tampil seperti teks tabel biasa (hitam, font & berat
   seragam dgn sel lain) tapi tetap bisa diklik. */
.lnk { color: var(--td); font-weight: inherit; font-size: inherit; font-family: inherit; text-decoration: none; cursor: pointer; }
.lnk:hover { text-decoration: underline; }
.action-row { display: inline-flex; gap: 4px; flex-wrap: wrap; justify-content: center; }

.pill { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.pill-gray { background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.pill-info { background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.pill-success { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.pill-danger { background: var(--eb); color: var(--et); border: 1px solid var(--ebd); }
.pill-warning { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }

/* Tombol */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 0 12px; height: 32px; border-radius: 7px; font-family: inherit; font-size: 12px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; }
.btn-sm { height: 26px; padding: 0 10px; font-size: 11px; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover:not(:disabled) { background: var(--gm); }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-info { background: var(--it); color: #fff; border-color: var(--it); }
.btn-warning { background: var(--lm); color: var(--td); border-color: var(--lm); }
.btn-secondary { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-secondary:hover { border-color: var(--ga); color: var(--td); background: var(--gl); }

.hint { font-size: 11px; color: var(--tu); padding-left: 2px; }
.muted { color: var(--tu); }
.muted2 { color: var(--tu); font-size: .85rem; }

/* Papan Room (cards) */
.board { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 0.85rem; }
.room-card { border: 1px solid var(--gb); border-radius: 11px; padding: .75rem; background: var(--bc); }
.room-head { display: flex; flex-wrap: wrap; gap: .5rem; align-items: baseline; border-bottom: 1px solid var(--gb); padding-bottom: .5rem; }
.room-head strong { color: var(--td); }
.room-head .kelas { color: var(--tu); font-size: .8rem; }
.room-head .occ { margin-left: auto; font-size: .8rem; color: var(--ga); font-weight: 600; }
.beds { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .5rem; }
.bed { border: 2px solid; border-radius: 8px; padding: .4rem; min-width: 120px; font-size: .8rem; }
.bed-label { font-weight: 700; color: var(--td); }
.bed-status { font-size: .7rem; font-weight: 600; }
.bed-pt { margin-top: .3rem; cursor: pointer; color: var(--ga); font-weight: 600; }
.bed-clean-btn { margin-top: .4rem; width: 100%; background: var(--wt); color: #fff; border: none; border-radius: 5px; padding: .25rem; font-size: .72rem; cursor: pointer; }

/* Fase 8 — badge pre-op/observasi */
.ri-badge { display: inline-block; font-size: .62rem; font-weight: 700; padding: .08rem .35rem; border-radius: 7px; margin-left: .35rem; vertical-align: middle; letter-spacing: .3px; }
.ri-preop { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }
.ri-obs   { background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.ri-preop-note { display: flex; align-items: flex-start; gap: .55rem; background: var(--gl); border: 1px solid var(--ibd); color: var(--it); border-radius: 9px; padding: .6rem .75rem; margin: .6rem 0; font-size: .82rem; }
.ri-preop-note svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: .1rem; }

/* ===== MODAL (gaya AsuransiView: header gradient navy) ===== */
.modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; z-index: 9100; padding: 1rem; }
/* Modal Dokumen RM = picker; editor FormRMRenderer (Teleport ke body, z-index 1000)
   harus muncul DI ATAS picker. Picker lain di app pakai z 100–300, jadi turunkan
   picker ini ke bawah 1000 agar editor tak tertutup (akar bug "form tak muncul"). */
.modal-bg-docrm { z-index: 900; }
.modal { background: var(--bc); border-radius: 12px; width: 460px; max-width: 94vw; max-height: 90vh; overflow-y: auto; padding: 0 1.2rem 1.2rem; }
.modal.wide { width: 860px; }
/* Header full-bleed gradient navy (negative margin menutup padding samping .modal) */
.modal h3 { margin: 0 -1.2rem 0.9rem; padding: 0.9rem 1.2rem; background: linear-gradient(135deg, var(--gm), var(--gd)); color: #fff; font-family: var(--font-display); font-size: 16px; position: sticky; top: 0; z-index: 2; }
.modal h4 { color: var(--gd); margin: .8rem 0 .4rem; font-size: .9rem; }
.modal label { display: block; margin: .6rem 0; color: var(--td); font-size: 12.5px; }
.modal input, .modal select, .modal textarea { width: 100%; padding: 7px 10px; border: 1px solid var(--gb); border-radius: 6px; margin-top: .2rem; font-size: 12.5px; font-family: inherit; background: var(--bc); color: var(--td); box-sizing: border-box; }
.modal input:focus, .modal select:focus, .modal textarea:focus { outline: none; border-color: var(--ga); }
.modal-actions { display: flex; justify-content: flex-end; gap: .5rem; padding: 0.75rem 1.2rem; border-top: 1px solid var(--gb); background: var(--bs); margin: 1rem -1.2rem -1.2rem; }
.modal-actions button { padding: 0 14px; height: 32px; border: 1.5px solid var(--gb); border-radius: 7px; background: transparent; cursor: pointer; color: var(--tm); font-family: inherit; font-size: 12px; }
.modal-actions button:hover { border-color: var(--ga); color: var(--td); background: var(--gl); }
.modal-actions .btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.modal-actions .btn-primary:hover:not(:disabled) { background: var(--gm); }
.modal-actions .btn-primary:disabled { opacity: .5; cursor: not-allowed; }

/* Discharge sections */
.discharge-section { border: 1px solid var(--gb); border-radius: 8px; padding: .5rem .7rem; margin: .6rem 0; }
.discharge-section legend { font-size: .8rem; font-weight: 700; color: var(--gd); padding: 0 .3rem; }
.spri-section legend { color: var(--pt); }
.obat-section legend { color: var(--st); }
.obat-pulang-tbl { width: 100%; border-collapse: collapse; margin-top: .5rem; font-size: .82rem; }
.obat-pulang-tbl th, .obat-pulang-tbl td { padding: .3rem .4rem; border-bottom: 1px solid var(--gb); text-align: left; vertical-align: top; color: var(--td); }
.obat-pulang-tbl th { color: var(--tm); font-weight: 600; }
.op-rule { width: 100%; box-sizing: border-box; padding: .25rem .4rem; border: 1px solid var(--gb); border-radius: 5px; margin-bottom: .25rem; font-size: .78rem; }
.op-rule-row { display: flex; gap: .3rem; margin-bottom: .25rem; }
.op-total-label { text-align: right; font-weight: 600; color: var(--tm); }
.op-total-val { font-weight: 700; color: var(--st); }

/* Hasil eksternal upload */
.doc-upload { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin: .6rem 0; }
.doc-cat { width: auto; min-width: 110px; }
.doc-title { flex: 1; min-width: 160px; }
.doc-file { flex: 1; min-width: 180px; }

/* Tindakan/obat picker + bill */
.add-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin: .5rem 0 1rem; }
.add-box { border: 1px solid var(--gb); border-radius: 8px; padding: .6rem; }
/* Input yang dulu mewarisi gaya global .modal — kini panel detail inline, beri gaya sendiri. */
.add-search { width: 100%; box-sizing: border-box; padding: 7px 10px; border: 1px solid var(--gb); border-radius: 6px; font: inherit; font-size: 12.5px; background: var(--bc); color: var(--td); margin-bottom: .4rem; }
.add-search:focus { outline: none; border-color: var(--ga); }
.req-fields select { height: 30px; padding: 0 6px; border: 1px solid var(--gb); border-radius: 6px; font-family: inherit; font-size: 12px; background: var(--bc); color: var(--td); }
.doc-cat, .doc-title, .doc-file { box-sizing: border-box; padding: 7px 10px; border: 1px solid var(--gb); border-radius: 6px; font: inherit; font-size: 12.5px; background: var(--bc); color: var(--td); }
.add-row { display: flex; align-items: center; gap: .5rem; margin-top: .4rem; }
.add-row-end { justify-content: flex-end; }
.row-pending td { background: #fffaf0; color: var(--td); }
.add-price { flex: 1; font-size: .85rem; color: var(--ga); font-weight: 600; }
.add-box h4 { color: var(--gd); margin: 0 0 .4rem; font-size: .9rem; }
/* Typeahead picker (gaya DokterView): input + dropdown hasil + chip terpilih */
.combo { position: relative; }
.combo-drop {
  position: absolute; z-index: 30; left: 0; right: 0; top: calc(100% + 2px);
  max-height: 240px; overflow-y: auto; background: var(--bc);
  border: 1px solid var(--gb); border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,.12);
}
.combo-item {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  padding: 7px 10px; cursor: pointer; font-size: 12.5px; color: var(--td);
  border-bottom: 1px solid var(--bs);
}
.combo-item:last-child { border-bottom: 0; }
.combo-item:hover { background: var(--gl); }
.combo-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.combo-inactive { margin-left: 6px; font-size: 9.5px; color: #b46f00; background: #fff1e6; padding: 0 6px; border-radius: 999px; }
.combo-price { flex-shrink: 0; font-size: 11.5px; color: var(--ga); font-weight: 600; }
.combo-empty { margin-top: 4px; padding: 8px 10px; font-size: 12px; color: var(--tu); background: var(--bs); border-radius: 6px; }
.combo-more { padding: 6px 10px; font-size: 11px; color: var(--tu); background: var(--bs); text-align: center; position: sticky; bottom: 0; }
.picked-chip {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  padding: 7px 10px; background: var(--gl); border: 1px solid var(--ga); border-radius: 7px;
}
.picked-name { font-size: 12.5px; font-weight: 600; color: var(--gd); }
.picked-x { border: 0; background: transparent; cursor: pointer; font-size: 18px; line-height: 1; color: var(--tm); padding: 0 4px; }
.picked-x:hover { color: var(--rd, #c0392b); }
.req-obat { border-top: 1px dashed var(--gb); margin-top: .85rem; padding-top: .85rem; }
.req-obat > h4 { color: var(--gd); margin: 0 0 .3rem; font-size: .92rem; }
.req-hint { font-size: 11px; color: var(--tu); margin: .2rem 0 .55rem; }
.req-fields { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-top: .45rem; }
.req-fields input { height: 30px; padding: 0 8px; border: 1px solid var(--gb); border-radius: 6px; font-size: 12px; font-family: inherit; color: var(--td); background: var(--bc); flex: 1; min-width: 90px; }
.req-fields input:focus { outline: none; border-color: var(--ga); }
.req-actions { margin-top: .5rem; }
.bill { width: 100%; border-collapse: collapse; margin: .5rem 0; }
.bill th, .bill td { border-bottom: 1px solid var(--gb); padding: .4rem; font-size: .8rem; color: var(--td); text-align: left; }
.bill th { background: var(--bs); color: var(--tm); font-weight: 600; text-transform: uppercase; font-size: 10.5px; letter-spacing: .03em; }
.del-x { background: none; border: none; color: var(--et); cursor: pointer; font-size: 1rem; }
.total { text-align: right; color: var(--td); }
.btn-add { margin-left: auto; }
.btn-press { transition: transform .08s ease; }
.btn-press:active { transform: translateY(1px) scale(.98); }

/* Tab dalam modal detail */
.dtabs { display: flex; gap: .4rem; margin: .2rem 0 .8rem; border-bottom: 1px solid var(--gb); flex-wrap: wrap; }
.dtabs button { padding: .45rem .9rem; border: none; background: none; cursor: pointer; color: var(--tu); border-bottom: 2px solid transparent; font-size: .9rem; }
.dtabs button.on { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }

/* ── Pasien Aktif: panel inline 2-kolom (gaya IgdView, lega tapi padat) ──────── */
.ri-split { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 1rem; align-items: start; }
.ri-list { display: flex; flex-direction: column; gap: 8px; max-height: calc(100vh - 230px); overflow-y: auto; padding-right: 2px; position: sticky; top: 0; }
.ri-list-empty { padding: 1.2rem .6rem; text-align: center; color: var(--tu); font-size: 12.5px; }
.ri-pcard { width: 100%; text-align: left; background: var(--bc); border: 1px solid var(--gb); border-left: 3px solid var(--gb); border-radius: 10px; padding: .6rem .7rem; cursor: pointer; font-family: inherit; transition: border-color .14s, background .14s, box-shadow .14s; }
.ri-pcard:hover { border-color: #b8c1cf; background: var(--bg); }
.ri-pcard.sel { border-color: var(--ga); border-left-color: var(--ga); background: var(--gl); box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.ri-pcard-top { display: flex; align-items: center; gap: 6px; }
.ri-pcard-name { font-size: 13.5px; color: var(--td); font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ri-pcard-sub { font-size: 11.5px; color: var(--tm); margin-top: 2px; }
.ri-pcard-since { font-size: 10.5px; color: var(--tu); margin-top: 1px; }

.ri-detail { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; min-height: 240px; padding: 0 1rem 1rem; }
.ri-detail-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .6rem; min-height: 240px; color: var(--tu); text-align: center; }
.ri-detail-empty svg { width: 40px; height: 40px; opacity: .5; }
.ri-detail-empty p { font-size: 12.5px; margin: 0; }
.det-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; padding: .85rem 0 .7rem; border-bottom: 1px solid var(--gb); position: sticky; top: 0; background: var(--bc); z-index: 2; }
.det-id strong { font-family: var(--font-display); font-size: 16px; color: var(--td); }
.det-rm { margin-left: 8px; font-size: 12px; color: var(--tm); }
.det-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.det-meta { display: flex; gap: 1.2rem; flex-wrap: wrap; padding: .6rem 0; font-size: 12px; color: var(--td); border-bottom: 1px solid var(--gb); }
.det-meta i { color: var(--tu); font-style: normal; margin-right: 4px; }

@media (max-width: 900px) {
  .ri-split { grid-template-columns: 1fr; }
  .ri-list { max-height: none; position: static; flex-direction: row; flex-wrap: wrap; }
  .ri-pcard { flex: 1 1 220px; }
}

/* CPPT */
.cppt-form { border: 1px solid var(--gb); border-radius: 8px; padding: .7rem; margin-bottom: 1rem; background: var(--bs); }
.cppt-form h4 { display: flex; align-items: center; gap: .5rem; margin: 0 0 .6rem; color: var(--td); }
/* Chip "Hasil Eksternal" di header composer CPPT → buka modal kecil */
.ext-chip { margin-left: auto; display: inline-flex; align-items: center; gap: 5px; height: 26px; padding: 0 10px; border: 1px solid var(--gb); border-radius: 999px; background: var(--bc); color: var(--tm); font-family: inherit; font-size: 11.5px; font-weight: 500; cursor: pointer; transition: border-color .14s, color .14s, background .14s; }
.ext-chip:hover { border-color: var(--ga); color: var(--gd); background: var(--gl); }
.ext-chip svg { width: 13px; height: 13px; }
.ext-count { display: inline-flex; align-items: center; justify-content: center; min-width: 17px; height: 17px; padding: 0 4px; border-radius: 999px; background: var(--ga); color: #fff; font-size: 10px; font-weight: 700; }
.ttv-row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-bottom: .5rem; }
.ttv-row label { display: inline-flex; align-items: center; gap: .25rem; margin: 0; font-size: .8rem; color: var(--td); }
.ttv-row input { width: 56px; padding: .25rem; margin: 0; border: 1px solid var(--gb); border-radius: 4px; }
.ttv-sep { color: var(--tu); }
.cppt-form textarea { width: 100%; border: 1px solid var(--gb); border-radius: 5px; padding: .4rem; color: var(--td); }
.soap-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin-bottom: .5rem; }

.soap-cell { display: flex; gap: .4rem; align-items: flex-start; margin: 0; }
/* Label S/O/A/P: huruf polos hitam, tanpa kotak/background. */
.soap-tag { flex: 0 0 24px; height: 24px; line-height: 24px; text-align: center; font-weight: 700; color: var(--td); background: none; font-size: .9rem; }
.soap-cell textarea { flex: 1; }
.instruksi-label { display: block; font-size: .8rem; color: var(--tm); margin-bottom: .5rem; }
.ttv-collapse { margin-bottom: .6rem; }
.ttv-collapse summary { cursor: pointer; font-size: .8rem; color: var(--ga); }
.cppt-actions { display: flex; gap: .5rem; }
.btn-ghost { background: var(--bc); border: 1px solid var(--gb); border-radius: 5px; padding: .4rem .8rem; cursor: pointer; color: var(--tm); }
.btn-ghost:hover { background: var(--gl); border-color: var(--ga); color: var(--td); }
.cppt-timeline { display: flex; flex-direction: column; gap: .5rem; }
.cppt-item { border-left: 4px solid var(--ga); background: var(--bs); padding: .5rem .7rem; border-radius: 0 6px 6px 0; }
.cppt-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; font-size: .78rem; color: var(--tm); }
.cppt-meta strong { color: var(--td); }
.cppt-time { color: var(--tu); }
.cppt-edited { color: var(--wt); font-style: italic; }
.cppt-ttv { display: flex; flex-wrap: wrap; gap: .6rem; font-size: .78rem; color: var(--ga); margin: .25rem 0; }
.cppt-mata { display: flex; flex-wrap: wrap; gap: .8rem; font-size: .78rem; color: var(--pt); margin: .15rem 0 .3rem; font-weight: 600; }
.cppt-soap { font-size: .85rem; color: var(--td); position: relative; }
.cppt-soap p { margin: .15rem 0; white-space: pre-wrap; }
.cppt-soap p b { color: var(--td); }   /* label S/O/A/P hitam */
.cppt-soap.clamped { max-height: 7.5em; overflow: hidden; }
.cppt-soap.clamped::after { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 2.2em; background: linear-gradient(to bottom, rgba(249,250,252,0), var(--bs)); }
.cppt-toggle { margin-top: .25rem; }
.refraksi-row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-top: .5rem; padding-top: .5rem; border-top: 1px dashed var(--gb); }
.refraksi-head { font-size: .76rem; font-weight: 700; color: var(--pt); }
.refraksi-row label { display: inline-flex; align-items: center; gap: .25rem; margin: 0; font-size: .8rem; color: var(--td); }
.refraksi-row input { width: 60px; padding: .25rem; margin: 0; border: 1px solid var(--gb); border-radius: 4px; }
.refraksi-row select { padding: .25rem; border: 1px solid var(--gb); border-radius: 4px; color: var(--td); }
.cppt-instruksi { color: var(--wt); }
.cppt-notes-legacy { color: var(--tm); font-style: italic; }
.cppt-foot { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-top: .4rem; font-size: .76rem; }
.cppt-verified { color: var(--st); font-weight: 600; }
.cppt-unverified { color: var(--tu); }
.cppt-foot-actions { margin-left: auto; display: flex; gap: .6rem; }
.btn-link { background: none; border: none; padding: 0; color: var(--ga); cursor: pointer; font-size: .76rem; text-decoration: underline; }
.btn-verify { color: var(--st); font-weight: 600; }
/* Badge peran PPA */
.ppa-badge { font-size: .68rem; font-weight: 700; padding: .1rem .45rem; border-radius: 10px; color: #fff; white-space: nowrap; }
.ppa-DOKTER { background: var(--gd); }
.ppa-PERAWAT { background: #0891b2; }
.ppa-APOTEKER { background: var(--pt); }
.ppa-GIZI { background: var(--st); }
.ppa-FISIOTERAPIS { background: #db2777; }
.ppa-LAINNYA { background: var(--tu); }
.ppa-edge-DOKTER { border-left-color: var(--gd); }
.ppa-edge-PERAWAT { border-left-color: #0891b2; }
.ppa-edge-APOTEKER { border-left-color: var(--pt); }
.ppa-edge-GIZI { border-left-color: var(--st); }
.ppa-edge-FISIOTERAPIS { border-left-color: #db2777; }
.ppa-edge-LAINNYA { border-left-color: var(--tu); }
.toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: var(--st); color: #fff; padding: .75rem 1.25rem; border-radius: 9px; z-index: 9200; box-shadow: 0 8px 24px rgba(0,0,0,.18); }
.toast.err { background: var(--et); }
</style>
