<script setup>
import { ref, onMounted, computed } from 'vue'
import { igdApi, admisiApi, masterApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'
import WilayahPicker from '@/components/master-data/WilayahPicker.vue'

const auth = useAuthStore()

// Cek peserta BPJS (saat penjamin BPJS) — hasil ditampilkan di form pasien baru.
const bpjsCheck = ref(null)   // { ok, nama, noKartu, kelas, status } | null
const bpjsChecking = ref(false)
const bpjsMode = ref('nokartu') // 'nokartu' | 'nik' — cara cek peserta

// SEP IGD (BPJS gawat darurat) — terbit setelah dokter isi diagnosa awal.
const sepInfo = ref(null)        // { is_bpjs, has_sep, no_sep, bpjs_number }
const showSepModal = ref(false)
const sepForm = ref({ diag_awal: '', diag_label: '' })
const icdResults = ref([])
const icdSearch = ref('')
const sepBusy = ref(false)

// ---------------------------------------------------------------------------
// STATE
// ---------------------------------------------------------------------------
const board = ref([])          // papan IGD (urut prioritas)
const busy = ref(false)
const toast = ref(null)

const detail = ref(null)       // pasien IGD terpilih (panel kanan)
const charges = ref([])
const runningBill = ref({ total: 0, billed: 0 })

// Modal register
const showRegister = ref(false)
const regTab = ref('cari')     // 'cari' (pasien lama) | 'baru' (pasien baru)
const regForm = ref(emptyRegForm())
const newForm = ref(emptyNewForm())
const patientResults = ref([])
const patientSearch = ref('')

// Modal triase
const showTriase = ref(false)
const triaseForm = ref({})

// Modal disposisi
const showDisposisi = ref(false)
const dispForm = ref({ disposition: 'PULANG', notes: '' })

// CPPT (panel detail)
const cpptList = ref([])
const cpptForm = ref(emptyCpptForm())
const cpptEditId = ref(null)

// Tindakan / obat picker
const tindakanList = ref([])
const obatList = ref([])
const obatSearch = ref('')
const selTindakan = ref('')
const selObat = ref('')

const TRIAGE_COLORS = [
  { code: 'MERAH',  label: 'Merah — Resusitasi', hex: '#dc2626' },
  { code: 'KUNING', label: 'Kuning — Gawat',     hex: '#d97706' },
  { code: 'HIJAU',  label: 'Hijau — Non-gawat',  hex: '#16a34a' },
  { code: 'HITAM',  label: 'Hitam — DOA',        hex: '#111827' },
]

function emptyRegForm() {
  return {
    patient_id: '', patient_name: '', guarantor_type: 'UMUM', insurer_id: '',
    chief_complaint: '', triage_color: '',
  }
}

function emptyNewForm() {
  return {
    name: '', gender: 'L', dob_display: '', identity_type: 'KTP', nik: '',
    phone: '', province: '', address: '',
    guarantor_type: 'UMUM', bpjs_number: '',
    chief_complaint: '', triage_color: '',
  }
}

function emptyCpptForm() {
  return {
    soap_s: '', soap_o: '', soap_a: '', soap_p: '', instruksi: '',
    td_sistol: null, td_diastol: null, nadi: null, suhu: null, respirasi: null, spo2: null,
  }
}

function notify(msg, ok = true) {
  toast.value = { msg, ok }
  setTimeout(() => (toast.value = null), 3500)
}

function colorHex(code) {
  return TRIAGE_COLORS.find(c => c.code === code)?.hex || '#94a3b8'
}

const stats = computed(() => {
  const c = { total: board.value.length, merah: 0, kuning: 0, hijau: 0, belum: 0 }
  for (const r of board.value) {
    if (r.triase_color === 'MERAH') c.merah++
    else if (r.triase_color === 'KUNING') c.kuning++
    else if (r.triase_color === 'HIJAU') c.hijau++
    else if (!r.triase_color) c.belum++
  }
  return c
})

// ---------------------------------------------------------------------------
// LOAD
// ---------------------------------------------------------------------------
async function loadBoard() {
  busy.value = true
  try {
    const { data } = await igdApi.board()
    board.value = data.data || []
  } catch (e) {
    notify(errMsg(e, 'Gagal memuat papan IGD'), false)
  } finally {
    busy.value = false
  }
}

async function openDetail(visitId) {
  try {
    const { data } = await igdApi.detail(visitId)
    detail.value = data.data.visit
    charges.value = data.data.charges || []
    runningBill.value = data.data.running_bill || { total: 0, billed: 0 }
    cpptEditId.value = null
    cpptForm.value = emptyCpptForm()
    // muat picker tindakan & obat + CPPT + info SEP
    await Promise.all([loadPickers(visitId), loadCppt(visitId), loadSep(visitId)])
  } catch (e) {
    notify(errMsg(e, 'Gagal memuat detail pasien'), false)
  }
}

async function loadSep(visitId) {
  try {
    const { data } = await igdApi.sepInfo(visitId)
    sepInfo.value = data.data
  } catch { sepInfo.value = null }
}

async function loadCppt(visitId) {
  try {
    const { data } = await igdApi.cpptList(visitId)
    cpptList.value = data.data || []
  } catch { cpptList.value = [] }
}

async function loadPickers(visitId) {
  try {
    const [t, o] = await Promise.all([
      igdApi.tarifTindakan(visitId),
      igdApi.daftarObat(visitId, ''),
    ])
    tindakanList.value = t.data.data || []
    obatList.value = o.data.data || []
  } catch { /* non-fatal */ }
}

async function searchObat() {
  if (!detail.value) return
  try {
    const { data } = await igdApi.daftarObat(detail.value.id, obatSearch.value)
    obatList.value = data.data || []
  } catch { /* ignore */ }
}

// ---------------------------------------------------------------------------
// REGISTER (walk-in)
// ---------------------------------------------------------------------------
function openRegister() {
  regTab.value = 'cari'
  regForm.value = emptyRegForm()
  newForm.value = emptyNewForm()
  patientResults.value = []
  patientSearch.value = ''
  bpjsCheck.value = null
  bpjsMode.value = 'nokartu'
  showRegister.value = true
}

async function cariPasien() {
  if (!patientSearch.value || patientSearch.value.length < 2) return
  try {
    const { data } = await admisiApi.cariPasien(patientSearch.value)
    patientResults.value = data.data || []
  } catch (e) {
    notify(errMsg(e, 'Pencarian pasien gagal'), false)
  }
}

function pickPasien(p) {
  regForm.value.patient_id = p.id
  regForm.value.patient_name = `${p.name} (${p.no_rm})`
  patientResults.value = []
}

async function submitRegister() {
  if (!regForm.value.patient_id) return notify('Pilih pasien dulu', false)
  busy.value = true
  try {
    const payload = {
      patient_id: regForm.value.patient_id,
      guarantor_type: regForm.value.guarantor_type,
      insurer_id: regForm.value.insurer_id || null,
      chief_complaint: regForm.value.chief_complaint || null,
      triage_color: regForm.value.triage_color || null,
    }
    await igdApi.register(payload)
    showRegister.value = false
    notify('Pasien IGD terdaftar')
    await loadBoard()
  } catch (e) {
    notify(errMsg(e, 'Pendaftaran gagal'), false)
  } finally {
    busy.value = false
  }
}

// Format input tgl lahir DD/MM/YYYY otomatis saat mengetik (sisipkan '/').
function onDobInput(e) {
  let v = (e.target.value || '').replace(/\D/g, '').slice(0, 8)
  if (v.length >= 5) v = v.slice(0, 2) + '/' + v.slice(2, 4) + '/' + v.slice(4)
  else if (v.length >= 3) v = v.slice(0, 2) + '/' + v.slice(2)
  newForm.value.dob_display = v
}

// Konversi DD/MM/YYYY → ISO YYYY-MM-DD (untuk backend). Null bila tak valid.
function dobToIso(display) {
  const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec((display || '').trim())
  if (!m) return null
  const [, dd, mm, yyyy] = m
  const d = Number(dd), mo = Number(mm), y = Number(yyyy)
  if (mo < 1 || mo > 12 || d < 1 || d > 31 || y < 1900) return null
  const iso = `${yyyy}-${mm}-${dd}`
  // Validasi tanggal kalender ASLI (mis. 31/02 di-rollover JS jadi 2 Mar → tolak).
  // Parse sbg UTC ('Z') supaya getUTC* cocok dgn input tanpa geser timezone.
  const parsed = new Date(iso + 'T00:00:00Z')
  if (Number.isNaN(parsed.getTime())) return null
  if (parsed.getUTCFullYear() !== y || parsed.getUTCMonth() + 1 !== mo || parsed.getUTCDate() !== d) return null
  if (parsed.getTime() > Date.now()) return null
  return iso
}

// Cek peserta BPJS via VClaim (saat penjamin BPJS). Validasi kartu aktif +
// tampilkan nama/kelas. SEP TIDAK dibuat di sini (diterbitkan terpisah).
async function cekPesertaBpjs() {
  const f = newForm.value
  bpjsCheck.value = null
  const noKartu = (f.bpjs_number || '').trim()
  const nik = (f.nik || '').trim()

  // Honor mode yang dipilih petugas (No.Kartu vs NIK).
  let payload
  if (bpjsMode.value === 'nik') {
    if (nik.length !== 16) return notify('NIK harus 16 digit', false)
    payload = { nik }
  } else {
    if (!noKartu) return notify('Isi No. Kartu BPJS dulu', false)
    payload = { bpjs_number: noKartu }
  }

  bpjsChecking.value = true
  try {
    // Endpoint terima { nik } ATAU { bpjs_number } → respons VClaim mentah.
    const { data } = await admisiApi.cekPeserta(payload)
    // Struktur VClaim: response.peserta.{nama, noKartu, hakKelas, statusPeserta}.
    const root = data.data?.response ?? data.data ?? {}
    const p = root.peserta ?? root
    const nama = p.nama
    const noKa = p.noKartu
    const kelas = p.hakKelas?.keterangan || p.kelasTanggungan?.keterangan
    const statusKartu = p.statusPeserta?.keterangan
    if (nama) {
      bpjsCheck.value = { ok: true, nama, noKartu: noKa, kelas, status: statusKartu }
      // Cek via NIK → auto-isi no kartu ke form (utk SEP nanti).
      if (noKa) f.bpjs_number = noKa
      // Cek via No.Kartu → auto-isi NIK bila peserta punya & form kosong.
      if (!f.nik && p.nik) f.nik = p.nik
      notify('Peserta BPJS aktif: ' + nama)
    } else {
      bpjsCheck.value = { ok: false, status: data.message || 'Peserta tidak ditemukan' }
      notify('Peserta BPJS tidak ditemukan / tidak aktif', false)
    }
  } catch (e) {
    bpjsCheck.value = { ok: false, status: errMsg(e, 'Cek peserta gagal') }
    notify(errMsg(e, 'Cek peserta BPJS gagal'), false)
  } finally {
    bpjsChecking.value = false
  }
}

async function submitRegisterNew() {
  const f = newForm.value
  if (!f.name || !f.gender) {
    return notify('Nama dan jenis kelamin wajib diisi', false)
  }
  const iso = dobToIso(f.dob_display)
  if (!iso) {
    return notify('Tanggal lahir wajib & format DD/MM/YYYY (mis. 14/11/1998)', false)
  }
  busy.value = true
  try {
    await igdApi.registerNew({
      name: f.name, gender: f.gender, date_of_birth: iso,
      identity_type: f.identity_type, nik: f.nik || null,
      phone: f.phone || null, province: f.province || null, address: f.address || null,
      guarantor_type: f.guarantor_type,
      bpjs_number: f.guarantor_type === 'BPJS' ? (f.bpjs_number || null) : null,
      chief_complaint: f.chief_complaint || null,
      triage_color: f.triage_color || null,
    })
    showRegister.value = false
    notify('Pasien baru terdaftar di IGD')
    await loadBoard()
  } catch (e) {
    notify(errMsg(e, 'Pendaftaran pasien baru gagal'), false)
  } finally {
    busy.value = false
  }
}

// ---------------------------------------------------------------------------
// CPPT
// ---------------------------------------------------------------------------
function startEditCppt(e) {
  cpptEditId.value = e.id
  cpptForm.value = {
    soap_s: e.soap_s || '', soap_o: e.soap_o || '', soap_a: e.soap_a || '',
    soap_p: e.soap_p || '', instruksi: e.instruksi || '',
    td_sistol: e.td_sistol, td_diastol: e.td_diastol, nadi: e.nadi,
    suhu: e.suhu, respirasi: e.respirasi, spo2: e.spo2,
  }
}

function cancelEditCppt() {
  cpptEditId.value = null
  cpptForm.value = emptyCpptForm()
}

async function submitCppt() {
  if (!detail.value) return
  const f = cpptForm.value
  if (!f.soap_s && !f.soap_o && !f.soap_a && !f.soap_p && !f.instruksi) {
    return notify('Isi minimal salah satu SOAP/instruksi', false)
  }
  busy.value = true
  try {
    const payload = {
      soap_s: f.soap_s || null, soap_o: f.soap_o || null, soap_a: f.soap_a || null,
      soap_p: f.soap_p || null, instruksi: f.instruksi || null,
      td_sistol: numOrNull(f.td_sistol), td_diastol: numOrNull(f.td_diastol), nadi: numOrNull(f.nadi),
      suhu: numOrNull(f.suhu), respirasi: numOrNull(f.respirasi), spo2: numOrNull(f.spo2),
    }
    if (cpptEditId.value) {
      await igdApi.updateCppt(cpptEditId.value, payload)
      notify('CPPT diperbarui')
    } else {
      await igdApi.addCppt(detail.value.id, payload)
      notify('CPPT dicatat')
    }
    cancelEditCppt()
    await loadCppt(detail.value.id)
  } catch (e) {
    notify(errMsg(e, 'Gagal menyimpan CPPT'), false)
  } finally {
    busy.value = false
  }
}

async function verifyCppt(entryId) {
  try {
    await igdApi.verifyCppt(entryId)
    notify('CPPT diverifikasi')
    if (detail.value) await loadCppt(detail.value.id)
  } catch (e) {
    notify(errMsg(e, 'Verifikasi gagal'), false)
  }
}

// ---------------------------------------------------------------------------
// SEP IGD (BPJS gawat darurat) — terbit setelah diagnosa awal
// ---------------------------------------------------------------------------
function openSepModal() {
  sepForm.value = { diag_awal: '', diag_label: '' }
  icdResults.value = []
  icdSearch.value = ''
  showSepModal.value = true
}

async function searchIcd() {
  if (!icdSearch.value || icdSearch.value.length < 2) { icdResults.value = []; return }
  try {
    const { data } = await masterApi.icd10.list({ search: icdSearch.value, per_page: 15 })
    icdResults.value = data.data?.data ?? data.data ?? []
  } catch { icdResults.value = [] }
}

function pickIcd(icd) {
  sepForm.value.diag_awal = icd.code
  sepForm.value.diag_label = `${icd.code} — ${icd.name || icd.description || ''}`
  icdResults.value = []
  icdSearch.value = ''
}

async function submitSep() {
  if (!detail.value) return
  if (!sepForm.value.diag_awal) return notify('Pilih diagnosa awal (ICD-10) dulu', false)
  sepBusy.value = true
  try {
    const { data } = await igdApi.generateSep(detail.value.id, { diag_awal: sepForm.value.diag_awal })
    const noSep = data.data?.response?.sep?.noSep || data.data?.noSep
    showSepModal.value = false
    notify(noSep ? `SEP terbit: ${noSep}` : 'SEP IGD diterbitkan')
    await loadSep(detail.value.id)
  } catch (e) {
    notify(errMsg(e, 'Gagal menerbitkan SEP'), false)
  } finally {
    sepBusy.value = false
  }
}

// ---------------------------------------------------------------------------
// TRIASE
// ---------------------------------------------------------------------------
function openTriase(row) {
  triaseForm.value = {
    visit_id: row.visit_id,
    name: row.name,
    triage_color: row.triase_color || '',
    triage_level: row.triase_level || '',
    chief_complaint: row.chief_complaint || '',
    td_sistol: null, td_diastol: null, nadi: null, suhu: null,
    respirasi: null, spo2: null, gcs_e: null, gcs_v: null, gcs_m: null,
  }
  showTriase.value = true
}

// v-model.number pada input yang dikosongkan menghasilkan '' (bukan null), yang
// menggagalkan cast decimal di backend. Bersihkan: kosong/NaN → null.
function numOrNull(v) {
  if (v === '' || v === null || v === undefined) return null
  const n = Number(v)
  return Number.isNaN(n) ? null : n
}

async function submitTriase() {
  if (!triaseForm.value.triage_color) return notify('Pilih warna triase', false)
  busy.value = true
  try {
    const f = triaseForm.value
    await igdApi.triase(f.visit_id, {
      triage_color: f.triage_color,
      triage_level: f.triage_level || null,
      chief_complaint: f.chief_complaint || null,
      td_sistol: numOrNull(f.td_sistol), td_diastol: numOrNull(f.td_diastol), nadi: numOrNull(f.nadi),
      suhu: numOrNull(f.suhu), respirasi: numOrNull(f.respirasi), spo2: numOrNull(f.spo2),
      gcs_e: numOrNull(f.gcs_e), gcs_v: numOrNull(f.gcs_v), gcs_m: numOrNull(f.gcs_m),
    })
    showTriase.value = false
    notify('Triase disimpan')
    await loadBoard()
    if (detail.value?.id === f.visit_id) await openDetail(f.visit_id)
  } catch (e) {
    notify(errMsg(e, 'Gagal menyimpan triase'), false)
  } finally {
    busy.value = false
  }
}

// ---------------------------------------------------------------------------
// TINDAKAN / OBAT
// ---------------------------------------------------------------------------
async function addTindakan() {
  if (!selTindakan.value || !detail.value) return
  try {
    await igdApi.addTindakan(detail.value.id, { procedure_id: selTindakan.value, quantity: 1 })
    selTindakan.value = ''
    notify('Tindakan dicatat')
    await openDetail(detail.value.id)
  } catch (e) {
    notify(errMsg(e, 'Gagal mencatat tindakan'), false)
  }
}

async function addObat() {
  if (!selObat.value || !detail.value) return
  try {
    await igdApi.addObat(detail.value.id, { medication_id: selObat.value, quantity: 1 })
    selObat.value = ''
    notify('Obat dicatat')
    await openDetail(detail.value.id)
  } catch (e) {
    notify(errMsg(e, 'Gagal mencatat obat'), false)
  }
}

async function deleteCharge(chargeId) {
  if (!detail.value) return
  try {
    await igdApi.deleteCharge(detail.value.id, chargeId)
    notify('Biaya dihapus')
    await openDetail(detail.value.id)
  } catch (e) {
    notify(errMsg(e, 'Gagal menghapus biaya'), false)
  }
}

// ---------------------------------------------------------------------------
// DISPOSISI
// ---------------------------------------------------------------------------
function openDisposisi() {
  dispForm.value = { disposition: 'PULANG', notes: '' }
  showDisposisi.value = true
}

const DISPOSITIONS = [
  { code: 'PULANG',    label: 'Pulang → Kasir',        hint: 'Lanjut bayar lalu farmasi.' },
  { code: 'RANAP',     label: 'Rawat Inap → Menunggu Kamar', hint: 'Petugas RANAP admit bed. SEP inap diurus admin.' },
  { code: 'RUJUK',     label: 'Rujuk → Kasir',         hint: 'Dirujuk ke faskes lain, selesaikan biaya dulu.' },
  { code: 'MENINGGAL', label: 'Meninggal (DOA)',       hint: 'Tutup kunjungan, tanpa kasir.' },
]

async function submitDisposisi() {
  if (!detail.value) return
  busy.value = true
  try {
    await igdApi.disposisi(detail.value.id, {
      disposition: dispForm.value.disposition,
      notes: dispForm.value.notes || null,
    })
    showDisposisi.value = false
    notify(`Disposisi ${dispForm.value.disposition} diproses`)
    detail.value = null
    await loadBoard()
  } catch (e) {
    notify(errMsg(e, 'Disposisi gagal'), false)
  } finally {
    busy.value = false
  }
}

function errMsg(e, fallback) {
  return e?.response?.data?.message || fallback
}

function fmtRp(n) {
  return 'Rp ' + Number(n || 0).toLocaleString('id-ID')
}

onMounted(loadBoard)
</script>

<template>
  <div class="igd-view">
    <!-- HEADER -->
    <div class="page-head">
      <div>
        <h1>IGD — Gawat Darurat</h1>
        <p class="sub">Papan triase berlevel (gawat didahulukan), pendaftaran walk-in, tindakan &amp; disposisi.</p>
      </div>
      <div style="display:flex; gap:8px;">
        <button v-if="auth.can('igd.write')" class="btn btn-primary btn-press" @click="openRegister">
          <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Daftar Pasien IGD
        </button>
        <button class="btn btn-secondary btn-press" @click="loadBoard">
          <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
          Muat ulang
        </button>
      </div>
    </div>

    <!-- STAT CARDS -->
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:#eef2ff;"><svg style="stroke:#4f46e5" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div>
        <div><div class="stat-val">{{ stats.total }}</div><div class="stat-lbl">Pasien aktif</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fee2e2;"><span class="dot" style="background:#dc2626"></span></div>
        <div><div class="stat-val">{{ stats.merah }}</div><div class="stat-lbl">Merah</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fef3c7;"><span class="dot" style="background:#d97706"></span></div>
        <div><div class="stat-val">{{ stats.kuning }}</div><div class="stat-lbl">Kuning</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#dcfce7;"><span class="dot" style="background:#16a34a"></span></div>
        <div><div class="stat-val">{{ stats.hijau }}</div><div class="stat-lbl">Hijau</div></div>
      </div>
      <div class="stat-card" :class="{ 'alert-card': stats.belum }">
        <div class="stat-icon" style="background:#f1f5f9;"><span class="dot" style="background:#94a3b8"></span></div>
        <div><div class="stat-val">{{ stats.belum }}</div><div class="stat-lbl">Belum ditriase</div></div>
      </div>
    </div>

    <div class="igd-grid">
      <!-- PAPAN (kiri) -->
      <div class="panel">
        <div class="panel-head">Papan IGD <span class="muted">(urut prioritas triase)</span></div>
        <div v-if="!board.length" class="empty">Tidak ada pasien IGD aktif.</div>
        <div v-else class="board-list">
          <button
            v-for="row in board" :key="row.queue_id"
            class="board-row" :class="{ active: detail && detail.id === row.visit_id }"
            @click="openDetail(row.visit_id)"
          >
            <span class="tri-bar" :style="{ background: colorHex(row.triase_color) }"></span>
            <div class="br-main">
              <div class="br-top">
                <span class="br-no">{{ row.queue_number }}</span>
                <span class="br-name">{{ row.name }}</span>
                <span v-if="row.triase_color" class="tri-pill" :style="{ background: colorHex(row.triase_color) }">{{ row.triase_color }}</span>
                <span v-else class="tri-pill tri-belum">BELUM TRIASE</span>
              </div>
              <div class="br-sub">
                <span>{{ row.no_rm }}</span>
                <span v-if="row.chief_complaint">· {{ row.chief_complaint }}</span>
                <span class="br-guar">{{ row.guarantor_type }}</span>
              </div>
            </div>
            <button v-if="auth.can('igd.write')" class="btn-mini" @click.stop="openTriase(row)">Triase</button>
          </button>
        </div>
      </div>

      <!-- DETAIL (kanan) -->
      <div class="panel">
        <div v-if="!detail" class="empty det-empty">Pilih pasien di papan untuk melihat detail &amp; tindakan.</div>
        <template v-else>
          <div class="panel-head det-head">
            <div>
              <strong>{{ detail.patient?.name }}</strong>
              <span class="muted"> · {{ detail.patient?.no_rm }}</span>
            </div>
            <button v-if="auth.can('igd.write')" class="btn btn-primary btn-press btn-sm" @click="openDisposisi">Disposisi</button>
          </div>

          <div class="det-meta">
            <div><span class="lbl">Penjamin</span> {{ detail.guarantor_type }}</div>
            <div><span class="lbl">Triase</span>
              <span v-if="detail.triase_color" class="tri-pill" :style="{ background: colorHex(detail.triase_color) }">{{ detail.triase_color }}</span>
              <span v-else>—</span>
            </div>
            <div><span class="lbl">Datang</span> {{ detail.igd_arrival_at ? new Date(detail.igd_arrival_at).toLocaleString('id-ID') : '—' }}</div>
          </div>

          <!-- SEP BPJS (gawat darurat) -->
          <div v-if="sepInfo && sepInfo.is_bpjs" class="sep-banner" :class="sepInfo.has_sep ? 'ok' : 'pending'">
            <template v-if="sepInfo.has_sep">
              <span>✓ SEP terbit: <strong>{{ sepInfo.no_sep }}</strong></span>
            </template>
            <template v-else>
              <span>SEP belum terbit (gawat darurat — perlu diagnosa awal)</span>
              <button v-if="auth.can('igd.write')" class="btn btn-primary btn-press btn-sm" @click="openSepModal">Terbitkan SEP</button>
            </template>
          </div>

          <!-- TINDAKAN -->
          <div v-if="auth.can('igd.write')" class="det-section">
            <div class="ds-title">Tambah Tindakan</div>
            <div class="picker-row">
              <select v-model="selTindakan">
                <option value="">— pilih tindakan —</option>
                <option v-for="t in tindakanList" :key="t.id" :value="t.id">{{ t.name }} · {{ fmtRp(t.price) }}</option>
              </select>
              <button class="btn btn-primary btn-press btn-sm" @click="addTindakan">+ Tindakan</button>
            </div>
          </div>

          <!-- OBAT -->
          <div v-if="auth.can('igd.write')" class="det-section">
            <div class="ds-title">Tambah Obat</div>
            <div class="picker-row">
              <input v-model="obatSearch" placeholder="cari obat…" @input="searchObat" />
            </div>
            <div class="picker-row">
              <select v-model="selObat">
                <option value="">— pilih obat —</option>
                <option v-for="o in obatList" :key="o.id" :value="o.id">{{ o.name }} · {{ fmtRp(o.price) }}</option>
              </select>
              <button class="btn btn-primary btn-press btn-sm" @click="addObat">+ Obat</button>
            </div>
          </div>

          <!-- RUNNING BILL -->
          <div class="det-section">
            <div class="ds-title">Rincian Biaya <span class="muted">({{ fmtRp(runningBill.total) }})</span></div>
            <table class="charge-tbl">
              <thead><tr><th>Deskripsi</th><th>Qty</th><th>Total</th><th></th></tr></thead>
              <tbody>
                <tr v-if="!charges.length"><td colspan="4" class="muted" style="text-align:center;">Belum ada biaya.</td></tr>
                <tr v-for="c in charges" :key="c.id">
                  <td>{{ c.description }}</td>
                  <td>{{ c.quantity }}</td>
                  <td>{{ fmtRp(c.total_price) }}</td>
                  <td>
                    <button v-if="auth.can('igd.write') && !c.is_billed" class="btn-del" @click="deleteCharge(c.id)" title="Hapus">×</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- CPPT (terintegrasi, masuk RME lintas-episode) -->
          <div class="det-section">
            <div class="ds-title">CPPT — Catatan Perkembangan <span class="muted">({{ cpptList.length }} entri)</span></div>

            <!-- form tambah/edit -->
            <div v-if="auth.can('igd.write')" class="cppt-form">
              <div class="cppt-soap-grid">
                <input v-model="cpptForm.soap_s" placeholder="S — Subjektif (keluhan)" />
                <input v-model="cpptForm.soap_o" placeholder="O — Objektif (pemeriksaan)" />
                <input v-model="cpptForm.soap_a" placeholder="A — Asesmen (diagnosis)" />
                <input v-model="cpptForm.soap_p" placeholder="P — Plan (rencana)" />
              </div>
              <input v-model="cpptForm.instruksi" placeholder="Instruksi (opsional)" style="margin-top:5px;" />
              <div class="cppt-vital-grid">
                <input v-model.number="cpptForm.td_sistol" type="number" placeholder="TD Sis" />
                <input v-model.number="cpptForm.td_diastol" type="number" placeholder="TD Dia" />
                <input v-model.number="cpptForm.nadi" type="number" placeholder="Nadi" />
                <input v-model.number="cpptForm.suhu" type="number" step="0.1" placeholder="Suhu" />
                <input v-model.number="cpptForm.spo2" type="number" placeholder="SpO₂" />
              </div>
              <div style="display:flex; gap:6px; margin-top:6px;">
                <button class="btn btn-primary btn-press btn-sm" @click="submitCppt">{{ cpptEditId ? 'Simpan Perubahan' : '+ Tambah CPPT' }}</button>
                <button v-if="cpptEditId" class="btn btn-ghost btn-press btn-sm" @click="cancelEditCppt">Batal</button>
              </div>
            </div>

            <!-- timeline riwayat -->
            <div v-if="!cpptList.length" class="muted" style="text-align:center; padding:.6rem;">Belum ada CPPT.</div>
            <div v-for="e in cpptList" :key="e.id" class="cppt-item">
              <div class="cppt-item-head">
                <b>{{ e.by || '—' }}</b>
                <span class="muted">{{ e.by_profession }} · {{ e.ppa_role }}</span>
                <span class="muted" style="margin-left:auto;">{{ e.at ? new Date(e.at).toLocaleString('id-ID') : '' }}</span>
              </div>
              <div class="cppt-item-soap">
                <div v-if="e.soap_s"><span class="soap-l">S</span>{{ e.soap_s }}</div>
                <div v-if="e.soap_o"><span class="soap-l">O</span>{{ e.soap_o }}</div>
                <div v-if="e.soap_a"><span class="soap-l">A</span>{{ e.soap_a }}</div>
                <div v-if="e.soap_p"><span class="soap-l">P</span>{{ e.soap_p }}</div>
                <div v-if="e.instruksi"><span class="soap-l">I</span>{{ e.instruksi }}</div>
              </div>
              <div class="cppt-item-foot">
                <span v-if="e.verified_by" class="cppt-verified">✓ Diverifikasi: {{ e.verified_by }}</span>
                <template v-if="auth.can('igd.write')">
                  <button v-if="!e.verified_by" class="cppt-act" @click="verifyCppt(e.id)">Verifikasi DPJP</button>
                  <button class="cppt-act" @click="startEditCppt(e)">Edit</button>
                </template>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- MODAL: REGISTER -->
    <div v-if="showRegister" class="modal-bg" @click.self="showRegister = false">
      <div class="modal">
        <div class="modal-head">Daftar Pasien IGD</div>

        <!-- Tab switcher: pasien lama vs pasien baru -->
        <div class="reg-tabs">
          <button class="reg-tab" :class="{ sel: regTab==='cari' }" @click="regTab='cari'">Cari Pasien</button>
          <button class="reg-tab" :class="{ sel: regTab==='baru' }" @click="regTab='baru'">Pasien Baru</button>
        </div>

        <!-- TAB: CARI PASIEN (existing) -->
        <div v-if="regTab==='cari'" class="modal-body">
          <div class="field">
            <label>Cari Pasien (nama / No. RM) <span class="req">*</span></label>
            <input v-model="patientSearch" placeholder="ketik min. 2 huruf…" @input="cariPasien" />
            <div v-if="patientResults.length" class="search-results">
              <button v-for="p in patientResults" :key="p.id" class="sr-item" @click="pickPasien(p)">
                {{ p.name }} <span class="muted">· {{ p.no_rm }}</span>
              </button>
            </div>
          </div>
          <div v-if="regForm.patient_id" class="picked">Dipilih: <strong>{{ regForm.patient_name }}</strong></div>

          <div class="field">
            <label>Penjamin <span class="req">*</span></label>
            <select v-model="regForm.guarantor_type">
              <option value="UMUM">Umum</option>
              <option value="BPJS">BPJS</option>
              <option value="ASURANSI">Asuransi / TPA</option>
              <option value="PERUSAHAAN">Perusahaan</option>
            </select>
          </div>

          <div class="field">
            <label>Keluhan Utama</label>
            <input v-model="regForm.chief_complaint" placeholder="mis. mata terkena cairan kimia" />
          </div>

          <div class="field">
            <label>Triase Awal (opsional)</label>
            <div class="tri-choices">
              <button
                v-for="c in TRIAGE_COLORS" :key="c.code"
                class="tri-choice" :class="{ sel: regForm.triage_color === c.code }"
                :style="{ '--c': c.hex }"
                @click="regForm.triage_color = regForm.triage_color === c.code ? '' : c.code"
              >{{ c.code }}</button>
            </div>
          </div>
        </div>

        <!-- TAB: PASIEN BARU -->
        <div v-else class="modal-body">
          <p class="muted" style="margin:0 0 .7rem;">Pasien gawat darurat belum terdaftar. Untuk pasien tak dikenal, pilih identitas "Tanpa Identitas".</p>
          <div class="field">
            <label>Nama Pasien <span class="req">*</span></label>
            <input v-model="newForm.name" placeholder="Nama lengkap / 'Tn. X' bila tak dikenal" />
          </div>
          <div class="grid2">
            <div class="field">
              <label>Jenis Kelamin <span class="req">*</span></label>
              <select v-model="newForm.gender"><option value="L">Laki-laki</option><option value="P">Perempuan</option></select>
            </div>
            <div class="field">
              <label>Tanggal Lahir <span class="req">*</span></label>
              <input :value="newForm.dob_display" @input="onDobInput" inputmode="numeric"
                     maxlength="10" placeholder="DD/MM/YYYY (mis. 14/11/1998)" />
            </div>
          </div>
          <div class="grid2">
            <div class="field">
              <label>Jenis Identitas</label>
              <select v-model="newForm.identity_type">
                <option value="KTP">KTP</option>
                <option value="PASPOR">Paspor</option>
                <option value="SIM">SIM</option>
                <option value="KIA">KIA</option>
                <option value="TANPA_IDENTITAS">Tanpa Identitas</option>
                <option value="LAINNYA">Lainnya</option>
              </select>
            </div>
            <div class="field">
              <label>NIK / No. Identitas</label>
              <input v-model="newForm.nik" placeholder="opsional saat darurat" />
            </div>
          </div>
          <div class="field"><label>No. HP</label><input v-model="newForm.phone" /></div>

          <!-- Provinsi (API wilayah) sebelum alamat -->
          <div class="field">
            <label>Provinsi</label>
            <WilayahPicker
              v-model:province="newForm.province"
              :show-district="false"
            />
          </div>
          <div class="field"><label>Alamat</label><input v-model="newForm.address" /></div>

          <div class="field">
            <label>Penjamin <span class="req">*</span></label>
            <select v-model="newForm.guarantor_type" @change="bpjsCheck = null">
              <option value="UMUM">Umum</option>
              <option value="BPJS">BPJS</option>
              <option value="ASURANSI">Asuransi / TPA</option>
              <option value="PERUSAHAAN">Perusahaan</option>
            </select>
          </div>

          <!-- BPJS: cek peserta VClaim by No.Kartu / NIK (SEP terbit terpisah) -->
          <div v-if="newForm.guarantor_type === 'BPJS'" class="bpjs-box">
            <div class="bpjs-mode">
              <label><input type="radio" value="nokartu" v-model="bpjsMode" /> No. Kartu BPJS</label>
              <label><input type="radio" value="nik" v-model="bpjsMode" /> NIK</label>
            </div>
            <div class="field" style="margin-bottom:6px;">
              <div v-if="bpjsMode === 'nokartu'" style="display:flex; gap:6px;">
                <input v-model="newForm.bpjs_number" inputmode="numeric" placeholder="No. Kartu BPJS (13 digit)" style="flex:1;" />
                <button class="btn btn-secondary btn-press btn-sm" :disabled="bpjsChecking" @click="cekPesertaBpjs">
                  {{ bpjsChecking ? 'Cek…' : 'Cek' }}
                </button>
              </div>
              <div v-else style="display:flex; gap:6px;">
                <input v-model="newForm.nik" inputmode="numeric" maxlength="16" placeholder="NIK (16 digit)" style="flex:1;" />
                <button class="btn btn-secondary btn-press btn-sm" :disabled="bpjsChecking" @click="cekPesertaBpjs">
                  {{ bpjsChecking ? 'Cek…' : 'Cek' }}
                </button>
              </div>
              <p class="muted" style="margin:4px 0 0; font-size:10px;">Pasien tak bawa kartu? Cek via NIK (KTP).</p>
            </div>
            <div v-if="bpjsCheck" class="bpjs-result" :class="bpjsCheck.ok ? 'ok' : 'err'">
              <template v-if="bpjsCheck.ok">
                ✓ <strong>{{ bpjsCheck.nama }}</strong>
                <span v-if="bpjsCheck.noKartu"> · Kartu: {{ bpjsCheck.noKartu }}</span>
                · Kelas: {{ bpjsCheck.kelas || '—' }}
                <span v-if="bpjsCheck.status"> · {{ bpjsCheck.status }}</span>
              </template>
              <template v-else>✕ {{ bpjsCheck.status }}</template>
            </div>
            <p class="muted" style="margin:6px 0 0; font-size:10.5px;">
              SEP gawat darurat diterbitkan terpisah setelah dokter mengisi diagnosa awal (bukan saat pendaftaran).
            </p>
          </div>

          <div class="field">
            <label>Keluhan Utama</label>
            <input v-model="newForm.chief_complaint" placeholder="mis. trauma mata" />
          </div>
          <div class="field">
            <label>Triase Awal (opsional)</label>
            <div class="tri-choices">
              <button
                v-for="c in TRIAGE_COLORS" :key="c.code"
                class="tri-choice" :class="{ sel: newForm.triage_color === c.code }"
                :style="{ '--c': c.hex }"
                @click="newForm.triage_color = newForm.triage_color === c.code ? '' : c.code"
              >{{ c.code }}</button>
            </div>
          </div>
        </div>

        <div class="modal-actions">
          <button class="btn btn-ghost btn-press" @click="showRegister = false">Batal</button>
          <button v-if="regTab==='cari'" class="btn btn-primary btn-press" :disabled="busy || !regForm.patient_id" @click="submitRegister">Daftarkan</button>
          <button v-else class="btn btn-primary btn-press" :disabled="busy || !newForm.name || !newForm.dob_display" @click="submitRegisterNew">Daftar Pasien Baru</button>
        </div>
      </div>
    </div>

    <!-- MODAL: TRIASE -->
    <div v-if="showTriase" class="modal-bg" @click.self="showTriase = false">
      <div class="modal">
        <div class="modal-head">Triase — {{ triaseForm.name }}</div>
        <div class="modal-body">
          <div class="field">
            <label>Warna Triase <span class="req">*</span></label>
            <div class="tri-choices">
              <button
                v-for="c in TRIAGE_COLORS" :key="c.code"
                class="tri-choice" :class="{ sel: triaseForm.triage_color === c.code }"
                :style="{ '--c': c.hex }"
                @click="triaseForm.triage_color = c.code"
              >{{ c.code }}</button>
            </div>
          </div>
          <div class="field">
            <label>Keluhan Utama</label>
            <input v-model="triaseForm.chief_complaint" />
          </div>
          <div class="grid3">
            <div class="field"><label>TD Sistol</label><input v-model.number="triaseForm.td_sistol" type="number" /></div>
            <div class="field"><label>TD Diastol</label><input v-model.number="triaseForm.td_diastol" type="number" /></div>
            <div class="field"><label>Nadi</label><input v-model.number="triaseForm.nadi" type="number" /></div>
            <div class="field"><label>Suhu</label><input v-model.number="triaseForm.suhu" type="number" step="0.1" /></div>
            <div class="field"><label>Respirasi</label><input v-model.number="triaseForm.respirasi" type="number" /></div>
            <div class="field"><label>SpO₂</label><input v-model.number="triaseForm.spo2" type="number" /></div>
            <div class="field"><label>GCS E</label><input v-model.number="triaseForm.gcs_e" type="number" /></div>
            <div class="field"><label>GCS V</label><input v-model.number="triaseForm.gcs_v" type="number" /></div>
            <div class="field"><label>GCS M</label><input v-model.number="triaseForm.gcs_m" type="number" /></div>
          </div>
        </div>
        <div class="modal-actions">
          <button class="btn btn-ghost btn-press" @click="showTriase = false">Batal</button>
          <button class="btn btn-primary btn-press" :disabled="busy || !triaseForm.triage_color" @click="submitTriase">Simpan Triase</button>
        </div>
      </div>
    </div>

    <!-- MODAL: DISPOSISI -->
    <div v-if="showDisposisi" class="modal-bg" @click.self="showDisposisi = false">
      <div class="modal">
        <div class="modal-head">Disposisi IGD</div>
        <div class="modal-body">
          <div class="disp-choices">
            <button
              v-for="d in DISPOSITIONS" :key="d.code"
              class="disp-choice" :class="{ sel: dispForm.disposition === d.code }"
              @click="dispForm.disposition = d.code"
            >
              <strong>{{ d.label }}</strong>
              <span class="disp-hint">{{ d.hint }}</span>
            </button>
          </div>
          <div class="field">
            <label>Catatan</label>
            <textarea v-model="dispForm.notes" rows="2" placeholder="resume singkat / alasan rujuk…"></textarea>
          </div>
        </div>
        <div class="modal-actions">
          <button class="btn btn-ghost btn-press" @click="showDisposisi = false">Batal</button>
          <button class="btn btn-primary btn-press" :disabled="busy" @click="submitDisposisi">Proses Disposisi</button>
        </div>
      </div>
    </div>

    <!-- MODAL: TERBITKAN SEP IGD -->
    <div v-if="showSepModal" class="modal-bg" @click.self="showSepModal = false">
      <div class="modal">
        <div class="modal-head">Terbitkan SEP Gawat Darurat (IGD)</div>
        <div class="modal-body">
          <p class="muted" style="margin:0 0 .7rem;">
            SEP IGD = jenis pelayanan rawat jalan, tanpa rujukan FKTP (gawat darurat).
            Diagnosa awal (ICD-10) wajib.
          </p>
          <div class="field">
            <label>Diagnosa Awal (ICD-10) <span class="req">*</span></label>
            <input v-model="icdSearch" placeholder="cari diagnosa / kode ICD-10…" @input="searchIcd" />
            <div v-if="icdResults.length" class="search-results">
              <button v-for="d in icdResults" :key="d.code" class="sr-item" @click="pickIcd(d)">
                <strong>{{ d.code }}</strong> · {{ d.name || d.description }}
              </button>
            </div>
            <div v-if="sepForm.diag_label" class="picked" style="margin-top:6px;">Dipilih: <strong>{{ sepForm.diag_label }}</strong></div>
          </div>
          <p class="muted" style="font-size:10.5px; margin:0;">
            SEP diterbitkan langsung ke VClaim BPJS. Pastikan peserta sudah dicek &amp; aktif.
          </p>
        </div>
        <div class="modal-actions">
          <button class="btn btn-ghost btn-press" @click="showSepModal = false">Batal</button>
          <button class="btn btn-primary btn-press" :disabled="sepBusy || !sepForm.diag_awal" @click="submitSep">
            {{ sepBusy ? 'Menerbitkan…' : 'Terbitkan SEP' }}
          </button>
        </div>
      </div>
    </div>

    <!-- TOAST -->
    <div v-if="toast" class="toast" :class="{ err: !toast.ok }">{{ toast.msg }}</div>
  </div>
</template>

<style scoped>
.igd-view { padding: 0; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1rem; }
.page-head h1 { font-family: var(--font-display); font-size: 22px; margin: 0; color: var(--td); }
.page-head .sub { font-size: 12px; color: var(--tu); margin: 4px 0 0; }

.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 0.6rem; margin-bottom: 1rem; }
.stat-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 11px; padding: 0.7rem; display: flex; align-items: center; gap: 9px; }
.stat-card.alert-card { border-color: #f59e0b; }
.stat-icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-icon svg { width: 16px; height: 16px; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.stat-icon .dot { width: 12px; height: 12px; border-radius: 50%; }
.stat-val { font-size: 18px; font-weight: 700; color: var(--td); font-family: var(--font-mono); }
.stat-lbl { font-size: 10.5px; color: var(--tu); }

.igd-grid { display: grid; grid-template-columns: 1.3fr 1fr; gap: 1rem; align-items: start; }
.panel { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }
.panel-head { padding: 0.7rem 0.9rem; font-weight: 600; font-size: 13px; color: var(--td); border-bottom: 1px solid var(--gb); }
.panel-head .muted { font-weight: 400; }
.muted { color: var(--tu); font-size: 11.5px; }
.empty { padding: 2rem 1rem; text-align: center; color: var(--tu); font-size: 12.5px; }
.det-empty { padding: 3rem 1rem; }

.board-list { display: flex; flex-direction: column; }
.board-row { display: flex; align-items: center; gap: 0; padding: 0; background: transparent; border: none; border-bottom: 1px solid var(--gb); cursor: pointer; text-align: left; width: 100%; }
.board-row:hover { background: var(--gl); }
.board-row.active { background: var(--gl); }
.tri-bar { width: 5px; align-self: stretch; flex-shrink: 0; }
.br-main { flex: 1; padding: 0.6rem 0.8rem; min-width: 0; }
.br-top { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
.br-no { font-family: var(--font-mono); font-size: 12px; font-weight: 700; color: var(--gd); }
.br-name { font-size: 13px; font-weight: 600; color: var(--td); }
.br-sub { font-size: 11px; color: var(--tu); margin-top: 2px; display: flex; gap: 5px; flex-wrap: wrap; }
.br-guar { margin-left: auto; }
.tri-pill { color: #fff; font-size: 9.5px; font-weight: 700; padding: 1px 7px; border-radius: 20px; letter-spacing: .3px; }
.tri-pill.tri-belum { background: #94a3b8 !important; }
.btn-mini { margin: 0 0.7rem; padding: 3px 10px; font-size: 11px; border: 1px solid var(--gb); background: var(--bc); border-radius: 6px; cursor: pointer; color: var(--tm); flex-shrink: 0; }
.btn-mini:hover { border-color: var(--ga); color: var(--td); }

.det-head { display: flex; justify-content: space-between; align-items: center; }
.det-meta { display: flex; gap: 1.2rem; padding: 0.7rem 0.9rem; font-size: 12px; color: var(--td); flex-wrap: wrap; border-bottom: 1px solid var(--gb); }
.det-meta .lbl { color: var(--tu); font-size: 10.5px; display: block; }
.sep-banner { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 7px 0.9rem; font-size: 11.5px; border-bottom: 1px solid var(--gb); }
.sep-banner.ok { background: #dcfce7; color: #15803d; }
.sep-banner.pending { background: #fef3c7; color: #92400e; }
.det-section { padding: 0.7rem 0.9rem; border-bottom: 1px solid var(--gb); }
.ds-title { font-size: 12px; font-weight: 600; color: var(--td); margin-bottom: 6px; }
.picker-row { display: flex; gap: 6px; margin-bottom: 6px; }
.picker-row select, .picker-row input { flex: 1; min-width: 0; }

.charge-tbl { width: 100%; border-collapse: collapse; font-size: 11.5px; }
.charge-tbl th { text-align: left; color: var(--tu); font-weight: 600; padding: 3px 4px; border-bottom: 1px solid var(--gb); }
.charge-tbl td { padding: 4px; border-bottom: 1px solid var(--gl); color: var(--td); }
.btn-del { background: none; border: none; color: #dc2626; font-size: 16px; cursor: pointer; line-height: 1; }

select, input, textarea { width: 100%; padding: 6px 9px; border: 1px solid var(--gb); border-radius: 7px; font-family: inherit; font-size: 12px; background: var(--bc); color: var(--td); box-sizing: border-box; }
select:focus, input:focus, textarea:focus { outline: none; border-color: var(--ga); }

.btn { display: inline-flex; align-items: center; gap: 6px; padding: 0 12px; height: 32px; border-radius: 7px; font-family: inherit; font-size: 12px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; }
.btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.btn-sm { height: 28px; font-size: 11.5px; padding: 0 10px; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover:not(:disabled) { background: var(--gm); }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-secondary { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-secondary:hover { border-color: var(--ga); color: var(--td); background: var(--gl); }
.btn-ghost { background: var(--bc); border: 1px solid var(--gb); border-radius: 7px; padding: 0 12px; height: 32px; cursor: pointer; color: var(--tm); font-family: inherit; font-size: 12px; }
.btn-press:active { transform: translateY(1px); }

.modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; z-index: 9100; padding: 1rem; }
.modal { background: var(--bc); border-radius: 12px; width: 460px; max-width: 94vw; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
.modal-head { padding: 0.9rem 1.1rem; font-weight: 600; font-size: 14px; color: var(--td); border-bottom: 1px solid var(--gb); }
.modal-body { padding: 1rem 1.1rem; }
.modal-actions { display: flex; justify-content: flex-end; gap: 8px; padding: 0.8rem 1.1rem; border-top: 1px solid var(--gb); }
.field { margin-bottom: 0.8rem; }
.field label { display: block; font-size: 11.5px; color: var(--tu); margin-bottom: 4px; }
.field .req { color: #dc2626; }
.grid3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; }
.grid3 .field { margin-bottom: 0.4rem; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }

/* BPJS cek peserta */
.bpjs-box { background: var(--gl); border: 1px solid var(--gb); border-radius: 8px; padding: 9px; margin-bottom: 0.8rem; }
.bpjs-mode { display: flex; gap: 14px; margin-bottom: 7px; font-size: 11.5px; color: var(--td); }
.bpjs-mode label { display: flex; align-items: center; gap: 4px; cursor: pointer; }
.bpjs-mode input { width: auto; margin: 0; }
.bpjs-result { font-size: 11.5px; padding: 6px 9px; border-radius: 6px; margin-top: 2px; }
.bpjs-result.ok { background: #dcfce7; color: #15803d; }
.bpjs-result.err { background: #fee2e2; color: #b91c1c; }

/* register tabs */
.reg-tabs { display: flex; gap: 4px; padding: 0 1.1rem; border-bottom: 1px solid var(--gb); }
.reg-tab { padding: 8px 14px; border: none; background: none; cursor: pointer; font-size: 12px; font-weight: 600; color: var(--tu); border-bottom: 2px solid transparent; margin-bottom: -1px; }
.reg-tab.sel { color: var(--gd); border-bottom-color: var(--gd); }

/* CPPT */
.cppt-form { background: var(--gl); border-radius: 8px; padding: 8px; margin-bottom: 10px; }
.cppt-soap-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 5px; }
.cppt-vital-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 4px; margin-top: 5px; }
.cppt-item { border: 1px solid var(--gb); border-radius: 8px; padding: 7px 9px; margin-bottom: 6px; }
.cppt-item-head { display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: var(--td); margin-bottom: 4px; flex-wrap: wrap; }
.cppt-item-soap { font-size: 11.5px; line-height: 1.5; color: var(--td); }
.cppt-item-soap .soap-l { display: inline-block; min-width: 18px; font-weight: 700; color: var(--gd); }
.cppt-item-foot { display: flex; gap: 8px; align-items: center; margin-top: 5px; }
.cppt-verified { font-size: 10.5px; color: #15803d; font-weight: 600; }
.cppt-act { font-size: 10.5px; border: 1px solid var(--gb); background: var(--bc); border-radius: 5px; padding: 2px 8px; cursor: pointer; color: var(--tm); }
.cppt-act:hover { border-color: var(--ga); color: var(--td); }

.search-results { border: 1px solid var(--gb); border-radius: 7px; margin-top: 4px; max-height: 180px; overflow-y: auto; }
.sr-item { display: block; width: 100%; text-align: left; padding: 7px 10px; border: none; border-bottom: 1px solid var(--gl); background: var(--bc); cursor: pointer; font-size: 12px; color: var(--td); }
.sr-item:hover { background: var(--gl); }
.picked { font-size: 12px; color: var(--td); margin-bottom: 0.8rem; padding: 6px 9px; background: var(--gl); border-radius: 7px; }

.tri-choices { display: flex; gap: 6px; flex-wrap: wrap; }
.tri-choice { flex: 1; min-width: 70px; padding: 7px 4px; border: 2px solid var(--gb); border-radius: 7px; background: var(--bc); cursor: pointer; font-size: 11px; font-weight: 700; color: var(--tm); }
.tri-choice.sel { border-color: var(--c); background: var(--c); color: #fff; }

.disp-choices { display: flex; flex-direction: column; gap: 7px; margin-bottom: 0.8rem; }
.disp-choice { display: flex; flex-direction: column; text-align: left; padding: 9px 11px; border: 2px solid var(--gb); border-radius: 9px; background: var(--bc); cursor: pointer; }
.disp-choice.sel { border-color: var(--gd); background: var(--gl); }
.disp-choice strong { font-size: 12.5px; color: var(--td); }
.disp-hint { font-size: 10.5px; color: var(--tu); margin-top: 2px; }

.toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: var(--st, #16a34a); color: #fff; padding: .75rem 1.25rem; border-radius: 9px; z-index: 9200; font-size: 12.5px; box-shadow: 0 8px 24px rgba(0,0,0,.18); }
.toast.err { background: var(--et, #dc2626); }

@media (max-width: 900px) { .igd-grid { grid-template-columns: 1fr; } }
</style>
