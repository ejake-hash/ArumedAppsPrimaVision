<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRawatInapStore } from '@/stores/rawatInapStore'
import { useAuthStore } from '@/stores/authStore'
import { roomApi, ranapApi, tarifPaketApi } from '@/services/api'

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
    await store.discharge(dischargePt.value.visit_id, payload)
    let msg = 'Pasien dipulangkan → kasir'
    if (obatPulangList.value.length) msg += ' (obat pulang → farmasi)'
    if (dischargeForm.value.spri_tgl_rencana) msg += ' (SPRI diproses)'
    notify(msg); showDischarge.value = false
  } catch { notify(store.error || 'Gagal discharge', false) } finally { busy.value = false }
}

// ── SEP (modal view + update) ───────────────────────────────────────────────────
const showSep = ref(false)
const sepPt = ref(null)
const sepData = ref(null)
const sepForm = ref({ kls_rawat: '', catatan: '', diag_awal: '', no_telp: '', katarak: '0' })
async function openSep(p) {
  sepPt.value = p; sepData.value = null; showSep.value = true
  sepForm.value = { kls_rawat: p.kelas_rawat_hak || '', catatan: '', diag_awal: '', no_telp: '', katarak: '0' }
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

async function doKirimBedah(p) {
  kirimBedahPt.value = p
  kirimBedahForm.value = { surgery_package_id: '', scheduled_date: '' }
  showKirimBedah.value = true
  // Muat daftar paket bedah untuk opsi auto-jadwal (boleh dikosongkan).
  if (!paketBedahOptions.value.length) {
    try {
      const res = await tarifPaketApi.paketBedah.list({ active: 1 })
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
    await roomApi.updateBed(bed.id, { status: 'AVAILABLE' })
    notify(`Bed ${bed.label} siap dipakai`)
    await store.fetchBedBoard()
  } catch (e) { notify(e.response?.data?.message ?? 'Gagal update bed', false) }
}

// ── DETAIL + tindakan/obat ────────────────────────────────────────────────────
const showDetail = ref(false)
const detailTab = ref('cppt') // 'cppt' | 'biaya' | 'dokumen'
const detailVisitId = ref(null)

// ── Hasil eksternal (Fase 8C) ─────────────────────────────────────────────────
const docList = ref([])
const docForm = ref({ category: 'LAB', title: '', file: null })
const docFileInput = ref(null)

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
const pickObat = ref({ medication_id: '', quantity: 1 })
const obatSearch = ref('')

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
  showDetail.value = true
  await store.fetchDetail(visitId)
  try {
    const [t, o, c] = await Promise.all([
      ranapApi.tarifTindakan(visitId),
      ranapApi.daftarObat(visitId, ''),
      ranapApi.cpptList(visitId),
    ])
    tindakanList.value = t.data?.data ?? []
    obatList.value = o.data?.data ?? []
    cpptList.value = c.data?.data ?? []
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

const selectedTindakanPrice = computed(() =>
  tindakanList.value.find((t) => t.id === pickTindakan.value.procedure_id)?.price ?? 0)
const selectedObatPrice = computed(() =>
  obatList.value.find((o) => o.id === pickObat.value.medication_id)?.price ?? 0)

async function submitTindakan() {
  if (!pickTindakan.value.procedure_id) { notify('Pilih tindakan', false); return }
  try {
    await store.addTindakan(detailVisitId.value, { ...pickTindakan.value })
    pickTindakan.value = { procedure_id: '', quantity: 1 }
    notify('Tindakan dicatat')
  } catch { notify(store.error || 'Gagal catat tindakan', false) }
}
async function submitObat() {
  if (!pickObat.value.medication_id) { notify('Pilih obat', false); return }
  try {
    await store.addObat(detailVisitId.value, { ...pickObat.value })
    pickObat.value = { medication_id: '', quantity: 1 }
    notify('Obat dicatat')
  } catch { notify(store.error || 'Gagal catat obat', false) }
}
async function removeCharge(c) {
  if (c.is_billed) { notify('Biaya sudah masuk invoice', false); return }
  if (!confirm(`Hapus "${c.description}"?`)) return
  try { await store.deleteCharge(detailVisitId.value, c.id); notify('Biaya dihapus') }
  catch { notify(store.error || 'Gagal hapus', false) }
}

const bedStatusColor = { AVAILABLE: '#16a34a', OCCUPIED: '#1763d4', CLEANING: '#d97706', MAINTENANCE: '#6b7280', RESERVED: '#7c3aed' }
function fmt(dt) { return dt ? new Date(dt).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' }) : '—' }
function rupiah(n) { return 'Rp ' + Number(n || 0).toLocaleString('id-ID') }
</script>

<template>
  <div class="ranap">
    <header class="head">
      <div class="head-left">
        <h2>Rawat Inap</h2>
        <button class="btn-refresh" @click="refreshAll">↻ Muat ulang</button>
      </div>
      <div class="stat-cards">
        <div class="stat-card">
          <div class="stat-num">{{ stats.aktif }}</div>
          <div class="stat-label">Pasien Dirawat</div>
        </div>
        <div class="stat-card warn">
          <div class="stat-num">{{ stats.menunggu }}</div>
          <div class="stat-label">Menunggu Kamar</div>
        </div>
        <div class="stat-card ok">
          <div class="stat-num">{{ stats.available }}</div>
          <div class="stat-label">Bed Kosong</div>
        </div>
      </div>
    </header>

    <nav class="tabs">
      <button :class="{ on: tab === 'board' }" @click="tab = 'board'">Papan Room</button>
      <button :class="{ on: tab === 'menunggu' }" @click="tab = 'menunggu'">
        Menunggu Kamar <span v-if="store.menungguKamar.length" class="badge">{{ store.menungguKamar.length }}</span>
      </button>
      <button :class="{ on: tab === 'aktif' }" @click="tab = 'aktif'">
        Pasien Aktif <span v-if="store.aktif.length" class="badge">{{ store.aktif.length }}</span>
      </button>
      <button :class="{ on: tab === 'history' }" @click="tab = 'history'; loadHistory()">Riwayat</button>
    </nav>

    <!-- PAPAN ROOM -->
    <section v-if="tab === 'board'" class="board">
      <p v-if="store.loading">Memuat…</p>
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
    <section v-if="tab === 'menunggu'" class="list">
      <table>
        <thead><tr><th class="col-no">No</th><th>Pasien</th><th>No. RM</th><th>Penjamin</th><th>Sejak</th><th></th></tr></thead>
        <tbody>
          <tr v-for="(p, i) in store.menungguKamar" :key="p.visit_id">
            <td class="col-no">{{ i + 1 }}</td>
            <td>{{ p.name }}</td><td>{{ p.no_rm }}</td><td>{{ p.guarantor_type }}</td><td>{{ fmt(p.since) }}</td>
            <td><button class="btn-primary btn-press" @click="openAdmit(p)">Admit / Pilih Bed</button></td>
          </tr>
          <tr v-if="!store.menungguKamar.length"><td colspan="6" class="muted">Tidak ada pasien menunggu kamar</td></tr>
        </tbody>
      </table>
    </section>

    <!-- PASIEN AKTIF -->
    <section v-if="tab === 'aktif'" class="list">
      <table>
        <thead><tr><th class="col-no">No</th><th>Pasien</th><th>RM</th><th>Room/Bed</th><th>Kls Hak</th><th>Masuk</th><th>Aksi</th></tr></thead>
        <tbody>
          <tr v-for="(p, i) in store.aktif" :key="p.visit_id">
            <td class="col-no">{{ i + 1 }}</td>
            <td>
              <a href="#" @click.prevent="openDetail(p.visit_id)">{{ p.name }}</a>
              <span v-if="p.inpatient_reason === 'PRE_OP'" class="ri-badge ri-preop" title="Pre-op rawat inap — menunggu operasi terjadwal">PRE-OP</span>
              <span v-else-if="p.inpatient_reason === 'OBSERVASI'" class="ri-badge ri-obs" title="Rawat inap observasi/pemeriksaan">OBSERVASI</span>
            </td>
            <td>{{ p.no_rm }}</td><td>{{ p.room }} / {{ p.bed }}</td><td>{{ p.kelas_rawat_hak }}</td><td>{{ fmt(p.admission_at) }}</td>
            <td class="actions">
              <button class="btn-press" @click="openDetail(p.visit_id)">Detail</button>
              <button class="btn-press" @click="openTransfer(p)">Pindah</button>
              <button v-if="p.guarantor_type === 'BPJS' && p.no_sep" class="btn-press btn-sep" @click="openSep(p)">SEP</button>
              <button class="btn-press" @click="doKirimBedah(p)">→ Bedah</button>
              <button class="btn-primary btn-press" @click="openDischarge(p)">Pulang</button>
            </td>
          </tr>
          <tr v-if="!store.aktif.length"><td colspan="7" class="muted">Tidak ada pasien rawat inap aktif</td></tr>
        </tbody>
      </table>
    </section>

    <!-- RIWAYAT PASIEN PULANG -->
    <section v-if="tab === 'history'" class="list">
      <div class="hist-filter">
        <label>Dari <input v-model="histFrom" type="date" /></label>
        <label>Sampai <input v-model="histTo" type="date" /></label>
        <button class="btn-primary btn-press" @click="loadHistory">Tampilkan</button>
      </div>
      <table>
        <thead><tr><th class="col-no">No</th><th>Pasien</th><th>RM</th><th>Penjamin</th><th>Masuk</th><th>Pulang</th><th>Jenis</th><th>Kontrol</th><th>SPRI</th><th>Aksi</th></tr></thead>
        <tbody>
          <tr v-for="(h, i) in histList" :key="h.visit_id">
            <td class="col-no">{{ i + 1 }}</td>
            <td>{{ h.name }}</td>
            <td>{{ h.no_rm }}</td>
            <td>{{ h.guarantor_type }}</td>
            <td>{{ fmt(h.admission_at) }}</td>
            <td>{{ fmt(h.discharge_at) }}</td>
            <td>{{ h.discharge_type }}</td>
            <td>{{ h.follow_up_date || '—' }}</td>
            <td>
              <span v-if="h.spri" class="spri-badge" :class="'spri-' + h.spri.status">
                {{ h.spri.status }}<template v-if="h.spri.no_spri"> · {{ h.spri.no_spri }}</template>
              </span>
              <span v-else class="muted2">—</span>
            </td>
            <td class="actions">
              <template v-if="h.guarantor_type === 'BPJS' && h.no_sep">
                <button class="btn-press btn-sep" @click="openSep(h)">SEP</button>
                <button v-if="!h.spri || h.spri.status === 'FAILED'" class="btn-press" @click="issueSpri(h)">+ SPRI</button>
                <button v-if="h.spri && h.spri.status === 'SUCCESS'" class="btn-press" @click="editSpri(h)">Edit SPRI</button>
                <button v-if="h.spri && h.spri.status !== 'SUCCESS'" class="btn-press btn-del" @click="removeSpri(h)">Hapus</button>
              </template>
              <span v-else class="muted2">—</span>
            </td>
          </tr>
          <tr v-if="!histList.length"><td colspan="10" class="muted">Tidak ada pasien pulang pada rentang ini</td></tr>
        </tbody>
      </table>
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
            <select v-model="kirimBedahForm.surgery_package_id">
              <option value="">— Tanpa jadwal (langsung antrean bedah) —</option>
              <option v-for="pk in paketBedahOptions" :key="pk.id" :value="pk.id">{{ pk.name }}</option>
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
          <select @change="addObatPulang(obatPulangOptions.find(o => o.id === $event.target.value)); $event.target.value=''">
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

    <!-- MODAL DETAIL + tindakan/obat + running bill -->
    <div v-if="showDetail" class="modal-bg" @click.self="showDetail = false">
      <div class="modal wide">
        <h3>Detail Pasien Inap</h3>
        <template v-if="store.detail">
          <p><strong>{{ store.detail.visit?.patient?.name }}</strong> — RM {{ store.detail.visit?.patient?.no_rm }}</p>
          <p class="muted2">Room {{ store.detail.visit?.room?.name }} / {{ store.detail.visit?.bed?.label }} ·
             Kelas hak {{ store.detail.visit?.kelas_rawat_hak }} · Masuk {{ fmt(store.detail.visit?.admission_at) }}</p>

          <!-- Tab dalam detail -->
          <nav class="dtabs">
            <button :class="{ on: detailTab === 'cppt' }" @click="detailTab = 'cppt'">CPPT &amp; Observasi</button>
            <button :class="{ on: detailTab === 'biaya' }" @click="detailTab = 'biaya'">Tindakan, Obat &amp; Biaya</button>
            <button :class="{ on: detailTab === 'dokumen' }" @click="detailTab = 'dokumen'; loadDocuments()">Hasil Eksternal</button>
          </nav>

          <!-- TAB CPPT — terintegrasi multi-PPA (SOAP) -->
          <div v-show="detailTab === 'cppt'">
            <div class="cppt-form">
              <h4>
                {{ cpptEditId ? 'Edit Catatan (CPPT)' : '+ Catatan Perkembangan Terintegrasi (CPPT)' }}
                <span class="ppa-badge" :class="'ppa-' + myPpa">{{ PPA_LABEL[myPpa] }}</span>
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
            <div class="add-grid">
              <div class="add-box">
                <h4>+ Tindakan</h4>
                <select v-model="pickTindakan.procedure_id">
                  <option value="">— pilih tindakan —</option>
                  <option v-for="t in tindakanList" :key="t.id" :value="t.id">{{ t.name }} — {{ rupiah(t.price) }}</option>
                </select>
                <div class="add-row">
                  <input v-model.number="pickTindakan.quantity" type="number" min="1" style="width:70px" />
                  <span class="add-price">{{ rupiah(selectedTindakanPrice * pickTindakan.quantity) }}</span>
                  <button class="btn-primary btn-press btn-add" @click="submitTindakan">+ Tambah</button>
                </div>
              </div>
              <div class="add-box">
                <h4>+ Obat</h4>
                <input v-model="obatSearch" placeholder="cari obat…" @input="searchObat" class="add-search" />
                <select v-model="pickObat.medication_id">
                  <option value="">— pilih obat —</option>
                  <option v-for="o in obatList" :key="o.id" :value="o.id">{{ o.name }} — {{ rupiah(o.price) }}</option>
                </select>
                <div class="add-row">
                  <input v-model.number="pickObat.quantity" type="number" min="1" style="width:70px" />
                  <span class="add-price">{{ rupiah(selectedObatPrice * pickObat.quantity) }}</span>
                  <button class="btn-primary btn-press btn-add" @click="submitObat">+ Tambah</button>
                </div>
              </div>
            </div>

            <h4>Rincian Biaya Berjalan</h4>
            <table class="bill">
              <thead><tr><th>Tgl</th><th>Jenis</th><th>Deskripsi</th><th>Qty</th><th>Harga</th><th>Total</th><th></th></tr></thead>
              <tbody>
                <tr v-for="c in store.detail.charges" :key="c.id">
                  <td>{{ c.charge_date }}</td><td>{{ c.charge_type }}</td><td>{{ c.description }}</td>
                  <td>{{ c.quantity }}</td><td>{{ rupiah(c.unit_price) }}</td><td>{{ rupiah(c.total_price) }}</td>
                  <td><button v-if="!c.is_billed" class="del-x" @click="removeCharge(c)" title="Hapus">×</button></td>
                </tr>
                <tr v-if="!store.detail.charges?.length"><td colspan="7" class="muted">Belum ada biaya</td></tr>
              </tbody>
            </table>
            <p class="total">Total berjalan: <strong>{{ rupiah(store.detail.running_bill?.total) }}</strong></p>
          </div>
        </template>
        <div class="modal-actions"><button @click="showDetail = false">Tutup</button></div>
      </div>
    </div>

    <div v-if="toast" class="toast" :class="{ err: !toast.ok }">{{ toast.msg }}</div>
  </div>
</template>

<style scoped>
.ranap { padding: 1rem; }
.head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; }
.head-left { display: flex; align-items: center; gap: .8rem; }
.head h2 { color: #1763d4; margin: 0; }
.btn-refresh { background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 6px; padding: .4rem .8rem; cursor: pointer; }

/* Stat cards (kanan atas) */
.stat-cards { display: flex; gap: .75rem; }
.stat-card { background: #fff; border: 1px solid #e5e7eb; border-left: 4px solid #1763d4; border-radius: 8px; padding: .5rem .9rem; min-width: 92px; text-align: center; }
.stat-card.warn { border-left-color: #d97706; }
.stat-card.ok { border-left-color: #16a34a; }
.stat-num { font-size: 1.5rem; font-weight: 700; color: #1763d4; line-height: 1.1; }
.stat-card.warn .stat-num { color: #d97706; }
.stat-card.ok .stat-num { color: #16a34a; }
.stat-label { font-size: .72rem; color: #6b7280; margin-top: .1rem; }

/* Kolom nomor urut */
.col-no { width: 44px; text-align: center; color: #6b7280; }

/* Button press effect */
.btn-press { transition: transform .08s ease, box-shadow .08s ease; }
.btn-press:active { transform: translateY(1px) scale(.97); box-shadow: inset 0 2px 4px rgba(0,0,0,.15); }
.btn-add { margin-left: auto; }
.tabs { display: flex; gap: .5rem; margin: 1rem 0; }
.tabs button { padding: .5rem 1rem; border: 1px solid #d1d5db; background: #fff; border-radius: 6px; cursor: pointer; color: #000; }
.tabs button.on { background: #1763d4; color: #fff !important; border-color: #1763d4; }
.badge { background: #ef4444; color: #fff; border-radius: 10px; padding: 0 .4rem; font-size: .75rem; margin-left: .3rem; }
.board { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
.room-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: .75rem; background: #fff; }
.room-head { display: flex; flex-wrap: wrap; gap: .5rem; align-items: baseline; border-bottom: 1px solid #eee; padding-bottom: .5rem; }
.room-head .kelas { color: #6b7280; font-size: .8rem; }
.room-head .occ { margin-left: auto; font-size: .8rem; color: #1763d4; }
.beds { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .5rem; }
.bed { border: 2px solid; border-radius: 6px; padding: .4rem; min-width: 120px; font-size: .8rem; }
.bed-label { font-weight: 700; color: #000; }
.bed-status { font-size: .7rem; font-weight: 600; }
.bed-pt { margin-top: .3rem; cursor: pointer; color: #1763d4; }
.bed-clean-btn { margin-top: .4rem; width: 100%; background: #d97706; color: #fff !important; border: none; border-radius: 4px; padding: .25rem; font-size: .72rem; cursor: pointer; }
.list table { width: 100%; border-collapse: collapse; background: #fff; }
.list th, .list td { border: 1px solid #e5e7eb; padding: .5rem; text-align: left; color: #000; }
.list th { background: #f3f4f6; }
.actions { display: flex; gap: .3rem; flex-wrap: wrap; }
.actions button, .list button { padding: .3rem .6rem; border: 1px solid #d1d5db; border-radius: 5px; background: #fff; cursor: pointer; color: #000; }
.btn-primary { background: #1763d4 !important; color: #fff !important; border-color: #1763d4 !important; }
.btn-sep { background: #7c3aed !important; color: #fff !important; border-color: #7c3aed !important; }
.btn-del { background: #ef4444 !important; color: #fff !important; border-color: #ef4444 !important; }
/* Riwayat */
.hist-filter { display: flex; flex-wrap: wrap; align-items: flex-end; gap: .6rem; margin-bottom: .8rem; }
.hist-filter label { display: flex; flex-direction: column; font-size: .8rem; color: #374151; gap: .2rem; }
.hist-filter input { padding: .35rem; border: 1px solid #d1d5db; border-radius: 5px; }
.spri-badge { font-size: .7rem; font-weight: 700; padding: .1rem .4rem; border-radius: 8px; color: #fff; }
.spri-SUCCESS { background: #16a34a; }
.spri-DRAFT { background: #d97706; }
.spri-FAILED { background: #ef4444; }
/* Discharge sections */
.discharge-section { border: 1px solid #e5e7eb; border-radius: 8px; padding: .5rem .7rem; margin: .6rem 0; }
.discharge-section legend { font-size: .8rem; font-weight: 700; color: #1763d4; padding: 0 .3rem; }
.spri-section legend { color: #7c3aed; }
.obat-section legend { color: #047857; }
.obat-pulang-tbl { width: 100%; border-collapse: collapse; margin-top: .5rem; font-size: .82rem; }
.obat-pulang-tbl th, .obat-pulang-tbl td { padding: .3rem .4rem; border-bottom: 1px solid #eef2f7; text-align: left; vertical-align: top; color: #000; }
.obat-pulang-tbl th { color: #64748b; font-weight: 600; }
.op-rule { width: 100%; box-sizing: border-box; padding: .25rem .4rem; border: 1px solid #cbd5e1; border-radius: 5px; margin-bottom: .25rem; font-size: .78rem; }
/* Fase 8 — badge pre-op/observasi + note jadwal + upload hasil eksternal */
.ri-badge { display: inline-block; font-size: .62rem; font-weight: 700; padding: .08rem .35rem; border-radius: 7px; margin-left: .35rem; vertical-align: middle; letter-spacing: .3px; }
.ri-preop { background: #fef3c7; color: #92400e; border: 1px solid #fbbf24; }
.ri-obs   { background: #e0f2fe; color: #075985; border: 1px solid #7dd3fc; }
.ri-preop-note { display: flex; align-items: flex-start; gap: .55rem; background: #f0fdfa; border: 1px solid #99f6e4; color: #0f766e; border-radius: 9px; padding: .6rem .75rem; margin: .6rem 0; font-size: .82rem; }
.ri-preop-note svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: .1rem; }
.doc-upload { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin: .6rem 0; }
.doc-cat { width: auto; min-width: 110px; }
.doc-title { flex: 1; min-width: 160px; }
.doc-file { flex: 1; min-width: 180px; }
.muted { color: #9ca3af; text-align: center; }
.muted2 { color: #6b7280; font-size: .85rem; }
.hint { color: #1763d4; font-size: .8rem; }
.modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,.4); display: flex; align-items: center; justify-content: center; z-index: 9100; }
.modal { background: #fff; border-radius: 10px; padding: 1.5rem; width: 440px; max-width: 92vw; max-height: 90vh; overflow-y: auto; }
.modal.wide { width: 820px; }
.modal h3 { color: #1763d4; margin-top: 0; }
.modal h4 { color: #1763d4; margin: .8rem 0 .4rem; font-size: .9rem; }
.modal label { display: block; margin: .6rem 0; color: #000; }
.modal input, .modal select, .modal textarea { width: 100%; padding: .4rem; border: 1px solid #d1d5db; border-radius: 5px; margin-top: .2rem; }
.modal-actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1rem; }
.modal-actions button { padding: .5rem 1rem; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; cursor: pointer; color: #000; }
.add-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin: .5rem 0 1rem; }
.add-box { border: 1px solid #e5e7eb; border-radius: 8px; padding: .6rem; }
.add-search { margin-bottom: .4rem; }
.add-row { display: flex; align-items: center; gap: .5rem; margin-top: .4rem; }
.add-price { flex: 1; font-size: .85rem; color: #1763d4; font-weight: 600; }
.bill { width: 100%; border-collapse: collapse; margin: .5rem 0; }
.bill th, .bill td { border: 1px solid #e5e7eb; padding: .35rem; font-size: .8rem; color: #000; }
.bill th { background: #f3f4f6; }
.del-x { background: none; border: none; color: #ef4444; cursor: pointer; font-size: 1rem; }
.total { text-align: right; color: #000; }

/* Tab dalam modal detail */
.dtabs { display: flex; gap: .4rem; margin: .8rem 0; border-bottom: 1px solid #e5e7eb; }
.dtabs button { padding: .45rem .9rem; border: none; background: none; cursor: pointer; color: #6b7280; border-bottom: 2px solid transparent; font-size: .9rem; }
.dtabs button.on { color: #1763d4; border-bottom-color: #1763d4; font-weight: 600; }

/* CPPT */
.cppt-form { border: 1px solid #e5e7eb; border-radius: 8px; padding: .7rem; margin-bottom: 1rem; }
.cppt-form h4 { display: flex; align-items: center; gap: .5rem; margin: 0 0 .6rem; color: #000; }
.ttv-row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-bottom: .5rem; }
.ttv-row label { display: inline-flex; align-items: center; gap: .25rem; margin: 0; font-size: .8rem; color: #000; }
.ttv-row input { width: 56px; padding: .25rem; margin: 0; border: 1px solid #d1d5db; border-radius: 4px; }
.ttv-sep { color: #6b7280; }
.cppt-form textarea { width: 100%; border: 1px solid #d1d5db; border-radius: 5px; padding: .4rem; color: #000; }
/* SOAP grid */
.soap-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin-bottom: .5rem; }
.soap-cell { display: flex; gap: .4rem; align-items: flex-start; margin: 0; }
.soap-tag { flex: 0 0 24px; height: 24px; line-height: 24px; text-align: center; font-weight: 700; color: #fff; background: #1763d4; border-radius: 5px; font-size: .82rem; }
.soap-cell textarea { flex: 1; }
.instruksi-label { display: block; font-size: .8rem; color: #374151; margin-bottom: .5rem; }
.ttv-collapse { margin-bottom: .6rem; }
.ttv-collapse summary { cursor: pointer; font-size: .8rem; color: #1763d4; }
.cppt-actions { display: flex; gap: .5rem; }
.btn-ghost { background: #fff; border: 1px solid #d1d5db; border-radius: 5px; padding: .4rem .8rem; cursor: pointer; color: #374151; }
.cppt-timeline { display: flex; flex-direction: column; gap: .5rem; }
.cppt-item { border-left: 4px solid #1763d4; background: #f8fafc; padding: .5rem .7rem; border-radius: 0 6px 6px 0; }
.cppt-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; font-size: .78rem; color: #475569; }
.cppt-meta strong { color: #000; }
.cppt-time { color: #6b7280; }
.cppt-edited { color: #d97706; font-style: italic; }
.cppt-ttv { display: flex; flex-wrap: wrap; gap: .6rem; font-size: .78rem; color: #1763d4; margin: .25rem 0; }
.cppt-mata { display: flex; flex-wrap: wrap; gap: .8rem; font-size: .78rem; color: #7c3aed; margin: .15rem 0 .3rem; font-weight: 600; }
.cppt-soap { font-size: .85rem; color: #000; position: relative; }
.cppt-soap p { margin: .15rem 0; white-space: pre-wrap; }
/* CPPT panjang: clamp ~5 baris lalu fade, expand via tombol */
.cppt-soap.clamped { max-height: 7.5em; overflow: hidden; }
.cppt-soap.clamped::after { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 2.2em; background: linear-gradient(to bottom, rgba(248,250,252,0), #f8fafc); }
.cppt-toggle { margin-top: .25rem; }
/* Refraksi ringkas di form */
.refraksi-row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-top: .5rem; padding-top: .5rem; border-top: 1px dashed #e5e7eb; }
.refraksi-head { font-size: .76rem; font-weight: 700; color: #7c3aed; }
.refraksi-row label { display: inline-flex; align-items: center; gap: .25rem; margin: 0; font-size: .8rem; color: #000; }
.refraksi-row input { width: 60px; padding: .25rem; margin: 0; border: 1px solid #d1d5db; border-radius: 4px; }
.refraksi-row select { padding: .25rem; border: 1px solid #d1d5db; border-radius: 4px; color: #000; }
.cppt-instruksi { color: #b45309; }
.cppt-notes-legacy { color: #374151; font-style: italic; }
.cppt-foot { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-top: .4rem; font-size: .76rem; }
.cppt-verified { color: #16a34a; font-weight: 600; }
.cppt-unverified { color: #9ca3af; }
.cppt-foot-actions { margin-left: auto; display: flex; gap: .6rem; }
.btn-link { background: none; border: none; padding: 0; color: #1763d4; cursor: pointer; font-size: .76rem; text-decoration: underline; }
.btn-verify { color: #16a34a; font-weight: 600; }
/* Badge peran PPA */
.ppa-badge { font-size: .68rem; font-weight: 700; padding: .1rem .45rem; border-radius: 10px; color: #fff; white-space: nowrap; }
.ppa-DOKTER { background: #1763d4; }
.ppa-PERAWAT { background: #0891b2; }
.ppa-APOTEKER { background: #7c3aed; }
.ppa-GIZI { background: #16a34a; }
.ppa-FISIOTERAPIS { background: #db2777; }
.ppa-LAINNYA { background: #6b7280; }
.ppa-edge-DOKTER { border-left-color: #1763d4; }
.ppa-edge-PERAWAT { border-left-color: #0891b2; }
.ppa-edge-APOTEKER { border-left-color: #7c3aed; }
.ppa-edge-GIZI { border-left-color: #16a34a; }
.ppa-edge-FISIOTERAPIS { border-left-color: #db2777; }
.ppa-edge-LAINNYA { border-left-color: #6b7280; }
.toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: #16a34a; color: #fff; padding: .75rem 1.25rem; border-radius: 8px; z-index: 9200; }
.toast.err { background: #ef4444; }
</style>
