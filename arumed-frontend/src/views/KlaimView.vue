<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue'
import api, { integrasiApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'
import Pager from '@/components/common/Pager.vue'
import { buildVisitBundle, buildBatchZip, downloadPdf, downloadBlob, safeFilename } from '@/utils/claimBundle'

const auth = useAuthStore()

// ── State (live, dari backend) ─────────────────────────────────────────────────
const claims      = ref([])      // ringkasan list (dari GET /klaim)
const loadingList = ref(false)
const loadingDetail = ref(false)
const actionBusy  = ref(false)   // guard aksi (review/verifikasi/dll)

// ── Status koneksi E-Klaim INA-CBG (statcard) ──────────────────────────────────
const eklaim = ref({ up: false, checked: false, checking: false, message: '' })
const eklaimBusy = ref('')       // method E-Klaim yang sedang berjalan (new/set-data/grouper/final/status/reedit)

async function checkEklaim(manual = false) {
  if (eklaim.value.checking) return
  eklaim.value.checking = true
  try {
    const { data } = await integrasiApi.testKoneksi('INACBGS')
    const r = data?.data ?? {}
    eklaim.value.up = !!r.success
    eklaim.value.message = r.message ?? ''
    eklaim.value.checked = true
    if (manual) toast(r.success ? 's' : 'w', r.message ?? (r.success ? 'E-Klaim terhubung' : 'E-Klaim tidak terhubung'))
  } catch (e) {
    eklaim.value.up = false
    eklaim.value.checked = true
    eklaim.value.message = e.response?.data?.message ?? 'Tes koneksi gagal'
    if (manual) toast('w', eklaim.value.message)
  } finally {
    eklaim.value.checking = false
  }
}

// ── Aksi E-Klaim INA-CBG (WS) — new → set-data → grouper → final ────────────────
async function runEklaim(method, { confirmMsg = null, successMsg, body = null } = {}) {
  if (!selected.value || eklaimBusy.value) return
  if (confirmMsg && !confirm(confirmMsg)) return
  eklaimBusy.value = method
  const id = selected.value.id
  const url = {
    new:      `/klaim/${id}/eklaim/new`,
    'set-data': `/klaim/${id}/eklaim/set-data`,
    grouper:  `/klaim/${id}/eklaim/grouper`,
    final:    `/klaim/${id}/eklaim/final`,
    status:   `/klaim/${id}/eklaim/status`,
    reedit:   `/klaim/${id}/eklaim/reedit`,
    'kirim-individual': `/klaim/${id}/eklaim/kirim-individual`,
  }[method]
  try {
    const { data } = method === 'status' ? await api.get(url) : await api.post(url, body ?? {})
    await refreshSelected()
    const srvMsg = data?.data?.message || data?.message
    toast('s', successMsg ?? srvMsg ?? 'Berhasil')
    // Sukses call WS = koneksi hidup → perbarui statcard tanpa tes ulang.
    eklaim.value.up = true; eklaim.value.checked = true
  } catch (e) {
    toast('w', e.response?.data?.message ?? `Gagal ${method} E-Klaim`)
  } finally {
    eklaimBusy.value = ''
  }
}

// ── K1 Special CMG (top-up) — pilih opsi dari grouper Stage 1, terapkan Stage 2 ──
const specialCmgPick = ref('')
async function applySpecialCmg() {
  if (!specialCmgPick.value) { toast('w', 'Pilih special CMG dulu'); return }
  await runEklaim('grouper', { body: { stage: 2, special_cmg: specialCmgPick.value }, successMsg: 'Special CMG diterapkan' })
}

// ── K2 — kirim kolektif (rentang tgl) + upload berkas ke DC BPJS ────────────────
const kolektif = reactive({ open: false, start_dt: '', stop_dt: '', jenis_rawat: 2, running: false })
async function kirimKolektif() {
  if (!kolektif.start_dt || !kolektif.stop_dt) { toast('w', 'Isi rentang tanggal'); return }
  kolektif.running = true
  try {
    const { data } = await api.post('/klaim/eklaim/kirim-kolektif', {
      start_dt: kolektif.start_dt, stop_dt: kolektif.stop_dt, jenis_rawat: kolektif.jenis_rawat,
    })
    toast('s', data?.message ?? 'Klaim kolektif terkirim')
    kolektif.open = false
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Kirim kolektif gagal')
  } finally {
    kolektif.running = false
  }
}
// Lampiran → DC BPJS: lihat uploadBerkasDc() di area Rekap (konteks manifest visit).
const uploadingDc = ref('')

// ── K3 — status verifikasi + dispute/pending + rekonsiliasi pembayaran ──────────
async function refreshVerif() {
  if (!selected.value) return
  try {
    const { data } = await api.post(`/klaim/${selected.value.id}/verif-status`)
    await refreshSelected()
    toast('s', data?.message ?? 'Status verifikasi diperbarui')
  } catch (e) { toast('w', e.response?.data?.message ?? 'Gagal tarik status') }
}
const disputeForm = reactive({ jenis_dispute: '', dispute_state: '', bahv_no: '', pending_note: '' })
async function saveDispute() {
  try {
    await api.put(`/klaim/${selected.value.id}/dispute`, { ...disputeForm })
    await refreshSelected(); toast('s', 'Status dispute/pending tersimpan')
  } catch (e) { toast('w', e.response?.data?.message ?? 'Gagal simpan dispute') }
}
const paymentForm = reactive({ nominal_disetujui: '', paid_at: '', berita_acara_bayar_ref: '' })
async function savePayment() {
  try {
    await api.put(`/klaim/${selected.value.id}/pembayaran`, { ...paymentForm })
    await refreshSelected(); toast('s', 'Pembayaran tercatat')
  } catch (e) { toast('w', e.response?.data?.message ?? 'Gagal simpan pembayaran') }
}

// Bentuk objek yang dipakai template (dipertahankan agar template stabil).
// Mapper dari response backend → bentuk ini.
function mapListItem(c) {
  return {
    id: c.id,
    no_sep: c.no_sep,
    no_kartu_bpjs: c.visit?.patient?.bpjs_number ?? '—',
    nik: c.patient_nik,
    nama_pasien: c.visit?.patient?.name ?? '—',
    tanggal_pelayanan: c.visit?.visit_date ?? c.created_at?.slice(0, 10),
    jenis_pelayanan: (c.visit?.jenis_pelayanan === 'RANAP') ? 'Rawat Inap' : 'Rawat Jalan',
    dpjp: c.visit?.doctor_examination?.doctor?.name ?? '—',
    diagnosis_utama: { kode: c.diagnosis_utama ?? '—', label: '' },
    inacbgs_tarif: Number(c.inacbgs_tarif ?? 0),
    status: c.status,
    bpjs_status: c.bpjs_status,
    assigned_to: c.assigned_to ? { id: c.assigned_to.id, name: c.assigned_to.name } : null,
  }
}

// Mapper detail (GET /klaim/{id}) → objek `selected` lengkap.
function mapDetail(c) {
  return {
    id: c.id,
    visit_id: c.visit_id,
    no_sep: c.no_sep,
    no_kartu_bpjs: c.visit?.patient?.bpjs_number ?? '—',
    nik: c.patient_nik,
    nama_pasien: c.visit?.patient?.name ?? '—',
    tanggal_pelayanan: c.visit?.visit_date ?? c.created_at?.slice(0, 10),
    jenis_pelayanan: (c.visit?.jenis_pelayanan === 'RANAP') ? 'Rawat Inap' : 'Rawat Jalan',
    dpjp: c.visit?.doctor_examination?.doctor?.name ?? '—',
    diagnosis_utama: c.diagnosis_utama_obj ?? { kode: c.diagnosis_utama ?? '—', label: '' },
    diagnosis_sekunder: c.diagnosis_sekunder_obj ?? [],
    diagnosis_text: c.diagnosis_text ?? '',
    tindakan: c.tindakan_obj ?? [],
    inacbgs_kode: c.inacbgs_kode ?? '',
    inacbgs_tarif: Number(c.inacbgs_tarif ?? 0),
    harga_kasir: Number(c.total_billing ?? 0),   // total tagihan riil RS
    has_lupis: !!c.lupis_data,
    resubmission_count: c.resubmission_count ?? 0,
    rejection_reason: c.rejection_reason ?? null,
    assigned_to: c.assigned_to ? { id: c.assigned_to.id, name: c.assigned_to.name } : null,
    dokumen_pendukung: c.dokumen_pendukung ?? [],   // PatientDocument visit klaim
    lembar_klaim: c.lembar_klaim ?? { exists: false, status: null, signed: false, coding_synced: false, document_id: null, signed_at: null },
    status: c.status,
    bpjs_status: c.bpjs_status,
    klaim_sent_at: c.klaim_sent_at ?? null,       // penanda pipeline berkas (kirim ke klaim)
    klaim_returned_at: c.klaim_returned_at ?? null,
    bpjs_response: c.bpjs_response ?? null,   // snapshot status DC (kemenkes/bpjs)
    // K1 Special CMG (top-up)
    special_cmg_options: c.special_cmg_options ?? [],
    special_cmg: c.special_cmg ?? null,
    tarif_top_up: Number(c.tarif_top_up ?? 0),
    total_cost_weight: c.total_cost_weight ?? null,
    // K2 status pengiriman DC
    kemkes_dc_status: c.kemkes_dc_status ?? null,
    bpjs_dc_status: c.bpjs_dc_status ?? null,
    // K3 verifikasi / dispute / pembayaran
    verif_status_name: c.verif_status_name ?? null,
    verif_status_code: c.verif_status_code ?? null,
    jenis_dispute: c.jenis_dispute ?? null,
    dispute_state: c.dispute_state ?? null,
    bahv_no: c.bahv_no ?? null,
    pending_note: c.pending_note ?? null,
    nominal_disetujui: c.nominal_disetujui != null ? Number(c.nominal_disetujui) : null,
    nominal_diajukan: c.nominal_diajukan != null ? Number(c.nominal_diajukan) : null,
    paid_at: c.paid_at ?? null,
    berita_acara_bayar_ref: c.berita_acara_bayar_ref ?? null,
    verified_by: deriveVerifier(c),
    verified_at: deriveVerifiedAt(c),
    verification_notes: deriveRejectNote(c) ?? '',
    // Checklist = state UI lokal (tidak dipersist). Default DITURUNKAN dari data nyata.
    checklist: {
      sep: !!c.no_sep,
      resume_medis: (c.dokumen_pendukung ?? []).some((d) => d.kode === 'RM-2.3'),
      diagnosis_utama: !!c.diagnosis_utama,
      kode_tindakan: (c.procedure_codes ?? []).length > 0,
      dokumen_pendukung: (c.dokumen_pendukung ?? []).length > 0,
    },
    audit_trail: (c.audit_logs ?? []).map((l) => ({
      action: l.action,
      by: l.performed_by?.name ?? 'Sistem',
      at: l.created_at,
      note: l.notes ?? '',
      old_status: l.old_status,
      new_status: l.new_status,
    })),
  }
}

function deriveVerifier(c) {
  const v = (c.audit_logs ?? []).find((l) => l.action === 'VERIFIKASI' || l.new_status === 'VERIFIED')
  return v?.performed_by?.name ?? null
}
function deriveVerifiedAt(c) {
  const v = (c.audit_logs ?? []).find((l) => l.action === 'VERIFIKASI' || l.new_status === 'VERIFIED')
  return v?.created_at ?? null
}
function deriveRejectNote(c) {
  const r = (c.audit_logs ?? []).find((l) => l.action === 'REJECT' || l.new_status === 'DITOLAK')
  return r?.notes ?? null
}

async function fetchClaims() {
  loadingList.value = true
  try {
    const params = {}
    if (statusFilter.value !== 'SEMUA') params.status = statusFilter.value
    if (searchQuery.value) params.search = searchQuery.value
    if (dateFrom.value) params.tanggal_from = dateFrom.value
    if (dateTo.value) params.tanggal_to = dateTo.value
    if (jenisFilter.value !== 'SEMUA') params.jenis_pelayanan = jenisFilter.value
    params.per_page = 100
    const { data } = await api.get('/klaim', { params })
    const rows = data.data?.data ?? data.data ?? []
    claims.value = rows.map(mapListItem)
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal memuat daftar klaim')
    claims.value = []
  } finally {
    loadingList.value = false
  }
}

// ── Auto-refresh (polling) — supaya perubahan antar anggota tim terlihat ───────
const POLL_MS = 12000
let _poll = null
function startPolling() {
  stopPolling()
  _poll = setInterval(() => {
    // Jangan ganggu saat ada modal aktif / sedang aksi.
    if (anyModalOpen.value || actionBusy.value || submitting.value) return
    fetchClaims()
    fetchAllForStats()
    if (selected.value) refreshSelectedSilent()
  }, POLL_MS)
}
function stopPolling() { if (_poll) { clearInterval(_poll); _poll = null } }

// refresh detail tanpa toast/spinner (untuk polling).
// Pertahankan centang checklist manual verifikator bila status tak berubah.
async function refreshSelectedSilent() {
  if (!selected.value) return
  const prevId = selected.value.id
  const prevStatus = selected.value.status
  const prevChecklist = { ...selected.value.checklist }
  try {
    const { data } = await api.get(`/klaim/${prevId}`)
    // Klaim lain mungkin sudah dipilih saat request berjalan — abaikan respons basi.
    if (!selected.value || selected.value.id !== prevId) return
    const mapped = mapDetail(data.data)
    // Status sama → jangan timpa centang manual yang sudah dilakukan verifikator.
    if (mapped.status === prevStatus) mapped.checklist = prevChecklist
    selected.value = mapped
  } catch { /* diam */ }
}

onMounted(() => { fetchClaims(); fetchAllForStats(); startPolling(); checkEklaim() })
onUnmounted(stopPolling)

// ── Filters ───────────────────────────────────────────────────────────────────
// #1 Default tanggal: dari tgl 1 bulan berjalan s/d hari ini.
function firstDayOfMonth() {
  const n = new Date()
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}-01`
}
function todayStr() {
  const n = new Date()
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}-${String(n.getDate()).padStart(2, '0')}`
}

const statusFilter = ref('SEMUA')
const searchQuery = ref('')
const dateFrom = ref(firstDayOfMonth())   // default: awal bulan
const dateTo = ref(todayStr())            // default: hari ini
const jenisFilter = ref('SEMUA')          // SEMUA | RAJAL | RANAP
const statusTabs = ['SEMUA', 'DRAFT', 'REVIEW', 'VERIFIED', 'SUBMITTED', 'SELESAI', 'DIKEMBALIKAN', 'DITOLAK_BPJS']
const jenisTabs = [{ v: 'SEMUA', l: 'Semua' }, { v: 'RAJAL', l: 'Rawat Jalan' }, { v: 'RANAP', l: 'Rawat Inap' }]

// Daftar sudah difilter server-side (fetchClaims kirim status/search/tanggal).
const filteredClaims = computed(() => claims.value)

// Re-fetch saat filter berubah (search di-debounce).
let _searchDebounce = null
watch(searchQuery, () => {
  clearTimeout(_searchDebounce)
  _searchDebounce = setTimeout(fetchClaims, 350)
})
// clearBulk saat filter berubah: pilihan massal tak boleh bertahan lintas tab/rentang
// (status/visibilitas baris berubah → seleksi lama jadi basi).
watch([statusFilter], () => { clearBulk(); fetchClaims() })
watch([dateFrom, dateTo, jenisFilter], () => { clearBulk(); fetchClaims(); fetchAllForStats() })

// Snapshot untuk stat cards & badge per-status. Mengikuti scope jenis+tanggal
// (TANPA status filter) agar stat card sesuai data yang sedang ditampilkan.
const allClaims = ref([])
async function fetchAllForStats() {
  try {
    const params = { per_page: STAT_CAP }
    if (jenisFilter.value !== 'SEMUA') params.jenis_pelayanan = jenisFilter.value
    if (dateFrom.value) params.tanggal_from = dateFrom.value
    if (dateTo.value) params.tanggal_to = dateTo.value
    const { data } = await api.get('/klaim', { params })
    const rows = data.data?.data ?? data.data ?? []
    allClaims.value = rows.map(mapListItem)
  } catch { /* diam — stats opsional */ }
}

const REJECTED_STATUSES = ['DIKEMBALIKAN', 'DITOLAK_BPJS', 'DITOLAK']
// Batas tarik snapshot stat. Bila tercapai, hitungan kartu mungkin kurang dari
// total sebenarnya → tandai via `statsCapped` agar tak menyesatkan.
const STAT_CAP = 1000
const stats = computed(() => ({
  total: allClaims.value.length,
  menunggu: allClaims.value.filter((c) => c.status === 'DRAFT' || c.status === 'REVIEW').length,
  siapSubmit: allClaims.value.filter((c) => c.status === 'VERIFIED').length,
  ditolak: allClaims.value.filter((c) => REJECTED_STATUSES.includes(c.status) || c.bpjs_status === 'DITOLAK').length,
}))
function statusCount(s) { return allClaims.value.filter((c) => c.status === s).length }
const statsCapped = computed(() => allClaims.value.length >= STAT_CAP)

// Label periode aktif untuk header stat — stat MENGIKUTI filter tanggal (created_at
// klaim), bukan selalu "bulan ini". Tampilkan rentang nyata agar tidak menyesatkan.
const statPeriodLabel = computed(() => {
  const f = dateFrom.value, t = dateTo.value
  if (f && t) return `${fmtTglDMY(f)} – ${fmtTglDMY(t)}`
  if (f) return `sejak ${fmtTglDMY(f)}`
  if (t) return `s/d ${fmtTglDMY(t)}`
  return 'semua tanggal'
})

const dateFilterActive = computed(() => !!(dateFrom.value || dateTo.value))

// ── Aging (umur klaim) — batas pengajuan BPJS umumnya akhir bulan berikutnya ───
function claimAgeDays(c) {
  if (!c.tanggal_pelayanan) return null
  const start = new Date(c.tanggal_pelayanan)
  return Math.floor((Date.now() - start.getTime()) / 86400000)
}
// Diagnosis utama terisi? (draft auto dari kasir bisa kosong bila dokter belum isi)
function hasDiagnosis(c) {
  const k = c?.diagnosis_utama?.kode
  return !!k && k !== '—'
}
function ageClass(c) {
  const d = claimAgeDays(c)
  if (d == null) return ''
  // Klaim belum SELESAI yang sudah tua = perhatian.
  if (['SELESAI'].includes(c.status)) return ''
  if (d >= 30) return 'age-danger'
  if (d >= 14) return 'age-warn'
  return ''
}

// ── Page tab (Klaim | Monitoring) ──────────────────────────────────────────────
const pageTab = ref('klaim') // 'klaim' | 'monitoring'

// ── Selection ─────────────────────────────────────────────────────────────────
const selected = ref(null)
const activeDetailTab = ref('data')

let _selectSeq = 0
async function selectClaim(c) {
  const seq = ++_selectSeq
  activeDetailTab.value = 'data'
  koreksiNote.value = ''
  loadingDetail.value = true
  selected.value = { ...c, audit_trail: [], diagnosis_sekunder: [], tindakan: [], checklist: {}, dokumen_pendukung: [] }
  try {
    const { data } = await api.get(`/klaim/${c.id}`)
    if (seq !== _selectSeq) return // user sudah memilih klaim lain — abaikan respons basi
    selected.value = mapDetail(data.data)
    fetchAttachments() // muat lampiran klaim (non-blocking)
  } catch (e) {
    if (seq !== _selectSeq) return
    toast('w', e.response?.data?.message ?? 'Gagal memuat detail klaim')
    selected.value = null
  } finally {
    if (seq === _selectSeq) loadingDetail.value = false
  }
}
async function refreshSelected() {
  if (!selected.value) return
  const id = selected.value.id
  // JANGAN naikkan _selectSeq di sini — itu milik selectClaim; menaikkannya bisa
  // membatalkan selectClaim yang sedang berjalan (spinner nyangkut). Cukup cek id.
  try {
    const { data } = await api.get(`/klaim/${id}`)
    // Jangan timpa panel bila user sudah memilih klaim lain selama await.
    if (selected.value?.id !== id) return
    selected.value = mapDetail(data.data)
    await fetchClaims()
  } catch {
    // Aksi utama (POST) sering SUDAH sukses di server; kegagalan refresh tak boleh
    // memunculkan toast "gagal" yang menyesatkan di pemanggil. Diamkan — polling 12 dtk
    // akan menyegarkan. (Beda dgn dulu: throw di sini bikin runGrouping/verifikasi dkk
    // jatuh ke catch & menampilkan "gagal" padahal sukses.)
    fetchClaims().catch(() => {})
  }
}
function handleListKeydown(e, c) {
  if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectClaim(c) }
}

// ── #3 Assignment (tandai dikerjakan oleh akun login) ──────────────────────────
const assigning = ref(false)
const myId = computed(() => auth.user?.id ?? null)
const myName = computed(() => auth.employeeName || auth.user?.name || 'Saya')

// Assign satu klaim (detail) ke diri sendiri, atau lepas bila sudah milik sendiri.
async function toggleAssignSelf() {
  if (!selected.value || assigning.value) return
  assigning.value = true
  const mine = selected.value.assigned_to?.id === myId.value
  try {
    await api.put(`/klaim/${selected.value.id}/assign`, { assigned_to_id: mine ? null : myId.value })
    await refreshSelected()
    toast(mine ? 'i' : 's', mine ? 'Penanda dilepas' : `Ditandai dikerjakan oleh ${myName.value}`)
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal menandai klaim')
  } finally {
    assigning.value = false
  }
}

// ── Checklist ─────────────────────────────────────────────────────────────────
const checklistDefs = [
  { key: 'sep',               label: 'Nomor SEP tersedia dan valid' },
  { key: 'resume_medis',      label: 'Resume Medis tersedia dan lengkap' },
  { key: 'diagnosis_utama',   label: 'Diagnosis Utama (ICD-10) sesuai ketentuan' },
  { key: 'kode_tindakan',     label: 'Kode Tindakan (ICD-9 CM) sesuai prosedur' },
  { key: 'dokumen_pendukung', label: 'Dokumen Pendukung telah dilampirkan' },
]

const checklistComplete = computed(() => {
  if (!selected.value) return false
  return checklistDefs.every((item) => selected.value.checklist[item.key])
})

// Ringkasan data nyata per item checklist — ditampilkan inline di sebelah centang
// agar verifikator memutuskan sambil melihat datanya (tak perlu pindah tab/modal).
// { text, ok } — ok=false menandai data belum ada (perlu dilengkapi sebelum centang).
const checklistSummary = computed(() => {
  const s = selected.value
  if (!s) return {}
  const dxUtama = s.diagnosis_utama?.kode && s.diagnosis_utama.kode !== '—' ? s.diagnosis_utama : null
  const tindakan = s.tindakan ?? []
  const dokCount = (s.dokumen_pendukung ?? []).length
  return {
    sep: { ok: !!s.no_sep && s.no_sep !== '—', text: s.no_sep && s.no_sep !== '—' ? s.no_sep : 'SEP belum ada' },
    resume_medis: {
      ok: (s.dokumen_pendukung ?? []).some((d) => d.kode === 'RM-2.3'),
      text: (s.dokumen_pendukung ?? []).some((d) => d.kode === 'RM-2.3') ? 'Resume medis tersedia' : 'Resume medis belum final',
    },
    diagnosis_utama: {
      ok: !!dxUtama,
      text: dxUtama ? `${dxUtama.kode}${dxUtama.label ? ' · ' + dxUtama.label : ''}` : 'Diagnosis utama belum diisi',
      pill: dxUtama?.kode,
    },
    kode_tindakan: {
      ok: tindakan.length > 0,
      text: tindakan.length ? tindakan.map((t) => t.kode).join(', ') : 'Belum ada kode tindakan (ICD-9)',
      pills: tindakan.map((t) => t.kode),
    },
    dokumen_pendukung: {
      ok: dokCount > 0,
      text: dokCount ? `${dokCount} dokumen: ${(s.dokumen_pendukung ?? []).map((d) => d.kode).join(' · ')}` : 'Belum ada dokumen pendukung',
    },
  }
})

// ── Correction note ───────────────────────────────────────────────────────────
const koreksiNote = ref('')

// ── Submit Modal ──────────────────────────────────────────────────────────────
const showSubmitModal = ref(false)
const submitting = ref(false)

// ── Item Modals ────────────────────────────────────────────────────────────────
const showResumeMedisModal  = ref(false)
const showDiagnosisModal    = ref(false)
const showTindakanModal     = ref(false)
const showDokumenModal      = ref(false)
const anyModalOpen = computed(() =>
  showSubmitModal.value || showResumeMedisModal.value || showDiagnosisModal.value ||
  showTindakanModal.value || showDokumenModal.value)

// ── Bulk selection (untuk aksi massal) ─────────────────────────────────────────
const bulkSelected = ref(new Set())
const bulkBusy = ref(false)
function toggleBulk(id) {
  const s = new Set(bulkSelected.value)
  s.has(id) ? s.delete(id) : s.add(id)
  bulkSelected.value = s
}
function clearBulk() { bulkSelected.value = new Set() }
const bulkCount = computed(() => bulkSelected.value.size)
// Aksi massal hanya untuk klaim sejenis: status sama di antara yang dipilih.
const bulkActionable = computed(() => {
  if (!bulkCount.value) return null
  const picked = claims.value.filter((c) => bulkSelected.value.has(c.id))
  const statuses = new Set(picked.map((c) => c.status))
  if (statuses.size !== 1) return null
  const st = [...statuses][0]
  if (st === 'DRAFT') return { action: 'review', label: 'Mulai Review', endpoint: (id) => api.put(`/klaim/${id}/review`) }
  if (st === 'VERIFIED') return { action: 'submit', label: 'Finalisasi Klaim', endpoint: (id) => api.post(`/klaim/${id}/submit`) }
  return null
})
async function runBulk() {
  const act = bulkActionable.value
  if (!act || bulkBusy.value) return
  bulkBusy.value = true
  // Hanya klaim yang MASIH tampil di list & berstatus sama (basis bulkActionable),
  // BUKAN seluruh bulkSelected — cegah aksi ke klaim yang status/visibilitasnya sudah
  // berubah (mis. setelah ganti tab status tapi Set belum dibersihkan).
  const ids = claims.value.filter((c) => bulkSelected.value.has(c.id)).map((c) => c.id)
  let ok = 0, fail = 0
  for (const id of ids) {
    try { await act.endpoint(id); ok++ } catch { fail++ }
  }
  await fetchClaims(); await fetchAllForStats()
  clearBulk()
  bulkBusy.value = false
  toast(fail ? 'w' : 's', `Aksi massal: ${ok} berhasil${fail ? `, ${fail} gagal` : ''}`)
}

// #3 Bulk: tandai klaim terpilih dikerjakan oleh akun login (anti double-work).
async function bulkAssignSelf() {
  if (!bulkCount.value || bulkBusy.value) return
  bulkBusy.value = true
  const ids = [...bulkSelected.value]
  let ok = 0, fail = 0
  for (const id of ids) {
    try { await api.put(`/klaim/${id}/assign`, { assigned_to_id: myId.value }); ok++ } catch { fail++ }
  }
  await fetchClaims(); await fetchAllForStats()
  if (selected.value && ids.includes(selected.value.id)) await refreshSelected()
  clearBulk()
  bulkBusy.value = false
  toast(fail ? 'w' : 's', `${ok} klaim ditandai dikerjakan oleh ${myName.value}${fail ? `, ${fail} gagal` : ''}`)
}

// ── Edit Koding (diagnosis/tindakan) — ambil dari master ICD ───────────────────
const editUtama     = ref({ kode: '', label: '' })
const editSekunder  = ref([])     // [{kode,label}]
const editTindakanList = ref([])  // [{kode,label}]
const savingCoding  = ref(false)

// ICD search (live ke /klaim/icd-search). target: 'utama' | 'sekunder:i' | 'tindakan:i'
const icdSearchTarget = ref(null)
const icdSearchType   = ref('icd10')
const icdQuery        = ref('')
const icdResults      = ref([])
const icdSearching    = ref(false)
let _icdDebounce = null

function openCodingEditor() {
  if (!selected.value) return
  editUtama.value      = { ...selected.value.diagnosis_utama }
  editSekunder.value   = selected.value.diagnosis_sekunder.map((d) => ({ ...d }))
  editTindakanList.value = selected.value.tindakan.map((t) => ({ ...t }))
  icdSearchTarget.value = null
  icdQuery.value = ''
  icdResults.value = []
  showDiagnosisModal.value = true
}

function startIcdSearch(target, type) {
  icdSearchTarget.value = target
  icdSearchType.value = type
  icdQuery.value = ''
  icdResults.value = []
}

watch(icdQuery, (q) => {
  clearTimeout(_icdDebounce)
  if (!q || q.trim().length < 2) { icdResults.value = []; return }
  _icdDebounce = setTimeout(doIcdSearch, 300)
})

async function doIcdSearch() {
  icdSearching.value = true
  try {
    const { data } = await api.get('/klaim/icd-search', { params: { type: icdSearchType.value, q: icdQuery.value.trim() } })
    icdResults.value = data.data ?? []
  } catch {
    icdResults.value = []
  } finally {
    icdSearching.value = false
  }
}

function pickIcd(item) {
  const t = icdSearchTarget.value
  if (t === 'utama') {
    editUtama.value = { ...item }
  } else if (t?.startsWith('sekunder:')) {
    const i = +t.split(':')[1]
    editSekunder.value[i] = { ...item }
  } else if (t?.startsWith('tindakan:')) {
    const i = +t.split(':')[1]
    editTindakanList.value[i] = { ...item }
  }
  icdSearchTarget.value = null
  icdQuery.value = ''
  icdResults.value = []
}

function addSekunderRow() { editSekunder.value.push({ kode: '', label: '' }); startIcdSearch(`sekunder:${editSekunder.value.length - 1}`, 'icd10') }
function removeSekunderRow(i) { editSekunder.value.splice(i, 1); if (icdSearchTarget.value === `sekunder:${i}`) icdSearchTarget.value = null }
function addTindakanRow() { editTindakanList.value.push({ kode: '', label: '' }); startIcdSearch(`tindakan:${editTindakanList.value.length - 1}`, 'icd9') }
function removeTindakanRow(i) { editTindakanList.value.splice(i, 1); if (icdSearchTarget.value === `tindakan:${i}`) icdSearchTarget.value = null }

async function saveCoding() {
  if (!selected.value || savingCoding.value) return
  if (!editUtama.value.kode) { toast('w', 'Diagnosis utama wajib diisi'); return }
  savingCoding.value = true
  try {
    await api.put(`/klaim/${selected.value.id}/diagnosis`, {
      diagnosis_utama: editUtama.value.kode,
      diagnosis_sekunder: editSekunder.value.filter((d) => d.kode).map((d) => d.kode),
      procedure_codes: editTindakanList.value.filter((t) => t.kode).map((t) => t.kode),
    })
    await refreshSelected()
    showDiagnosisModal.value = false
    toast('s', 'Koding klaim diperbarui. Jalankan ulang Grouping INA-CBGs.')
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal menyimpan koding')
  } finally {
    savingCoding.value = false
  }
}

// ── Grouper & Kasir ───────────────────────────────────────────────────────────
const showKasirModal  = ref(false)
const grouping        = ref(false)
const generatingLupis = ref(false)

// ── DIVA (Digital Validation) ────────────────────────────────────────────────
// BPJS tak menyediakan API DIVA — validasi resmi (Sesuai/Tidak Sesuai) dilakukan
// di portal VClaim (unggah txt e-klaim). Yang app ini bisa: PRA-VALIDASI lokal
// kesiapan koding dari data klaim yang ada, agar minim "Tidak Sesuai" saat di portal.
const DIVA_MONITORING_URL = 'https://vclaim.bpjs-kesehatan.go.id/VClaim/Diva/MonitoringDiva'
const VCLAIM_PORTAL_URL = 'https://vclaim.bpjs-kesehatan.go.id/VClaim/'
function openExt(url) { window.open(url, '_blank', 'noopener') }

// DIVA — pra-cek kesiapan KODING (yang divalidasi BPJS dari txt).
const divaCodingChecks = computed(() => {
  const s = selected.value
  if (!s) return []
  const dxOk = !!s.diagnosis_utama?.kode && s.diagnosis_utama.kode !== '—'
  return [
    { ok: dxOk, req: true, label: 'Diagnosis utama (ICD-10)', hint: 'Wajib & valid.' },
    { ok: !!s.inacbgs_kode, req: true, label: 'Grouping INA-CBG', hint: 'Jalankan Grouping → dapat kode CBG.' },
    { ok: Number(s.inacbgs_tarif) > 0, req: true, label: 'Tarif INA-CBG > 0', hint: 'Tarif 0 → koding belum lengkap.' },
    { ok: (s.tindakan?.length ?? 0) > 0, req: false, label: `Prosedur/tindakan (ICD-9): ${s.tindakan?.length ?? 0}`, hint: 'Lengkapi bila ada tindakan.' },
    { ok: (s.diagnosis_sekunder?.length ?? 0) > 0, req: false, label: `Diagnosis sekunder: ${s.diagnosis_sekunder?.length ?? 0}`, hint: 'Komorbid/komplikasi mempengaruhi CBG.' },
  ]
})
const divaReady = computed(() => divaCodingChecks.value.filter((c) => c.req).every((c) => c.ok))

// VEDIKA — kesiapan BERKAS (dokumen) untuk dirakit jadi 1 PDF.
const vedikaChecks = computed(() => {
  const s = selected.value
  if (!s) return []
  return [
    { ok: !!s.no_sep, req: true, label: 'SEP', hint: 'Surat Eligibilitas Peserta.' },
    { ok: !!s.lembar_klaim?.signed, req: true, label: 'Resume medis ber-TTD', hint: 'Bukti pelayanan ditandatangani dokter.' },
    { ok: (s.dokumen_pendukung?.length ?? 0) > 0, req: false, label: `Dokumen pendukung: ${s.dokumen_pendukung?.length ?? 0}`, hint: 'Resume/laporan operasi/checklist.' },
  ]
})
const vedikaReady = computed(() => vedikaChecks.value.filter((c) => c.req).every((c) => c.ok))
const klaimSiap = computed(() => divaReady.value && vedikaReady.value)

// Rakit 1 PDF berkas klaim per pasien (urutan Vedika) → unduh.
const bundling = ref(false)
async function bundleBerkas() {
  if (!selected.value || bundling.value) return
  bundling.value = true
  try {
    const { bytes, skipped, pages } = await buildVisitBundle(selected.value.visit_id)
    if (!pages) { toast('w', 'Tak ada berkas yang bisa dirakit'); return }
    const tag = selected.value.no_sep || selected.value.nik || selected.value.visit_id
    downloadPdf(bytes, safeFilename(`KLAIM_${tag}_${selected.value.nama_pasien || ''}`) + '.pdf')
    toast(skipped.length ? 'i' : 's',
      skipped.length
        ? `Berkas dirakit (${pages} hlm) — ${skipped.length} dilewati: ${skipped.slice(0, 4).join(', ')}${skipped.length > 4 ? '…' : ''}`
        : `Berkas klaim dirakit (${pages} hlm)`)
  } catch (e) {
    toast('w', e.message || 'Gagal merakit berkas klaim')
  } finally {
    bundling.value = false
  }
}

// ── TAB UTAMA "DIVA & Berkas" — list pasien + export berkas Vedika ─────────────
const berkasFrom = ref(firstDayOfMonth())
const berkasTo = ref(todayStr())
const berkasJenis = ref('')        // '' | RAJAL | RANAP
const berkasRows = ref([])
const berkasPage = ref(1)
const berkasPerPage = ref(50)
const berkasLast = ref(1)
const berkasTotal = ref(0)
const berkasLoading = ref(false)
const unduhId = ref(null)          // visit_id sedang diunduh
const batchBusy = ref(false)
const batchProgress = ref({ done: 0, total: 0, label: '' })

function berkasParams(page) {
  const p = { per_page: berkasPerPage.value, page: page || berkasPage.value }
  if (berkasFrom.value) p.tanggal_from = berkasFrom.value
  if (berkasTo.value) p.tanggal_to = berkasTo.value
  if (berkasJenis.value) p.jenis = berkasJenis.value
  p.only_sent = 1   // hanya kunjungan yang sudah "Kirim ke Klaim" dari Rekap
  return p
}

async function fetchBerkasList() {
  berkasLoading.value = true
  try {
    const { data } = await api.get('/klaim/rekap', { params: berkasParams() })
    const pg = data.data ?? {}
    berkasRows.value = pg.data ?? []
    berkasLast.value = pg.last_page ?? 1
    berkasTotal.value = pg.total ?? 0
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal memuat daftar kunjungan')
    berkasRows.value = []
  } finally {
    berkasLoading.value = false
  }
}
function onBerkasPage(n) { berkasPage.value = n; fetchBerkasList() }
function onBerkasFilter() { berkasPage.value = 1; fetchBerkasList() }

// Kembalikan kunjungan dari workspace Berkas Klaim ke Rekap Kunjungan BPJS + pesan.
// Hanya untuk klaim yang sudah "dikirim ke klaim" (klaim_sent_at) & belum dikirim
// ke BPJS — mengosongkan klaim_sent_at → kunjungan keluar dari pipeline klaim.
const returningRekap = ref(false)
const canReturnToRekap = computed(() =>
  !!selected.value?.klaim_sent_at &&
  ['DRAFT', 'REVIEW', 'VERIFIED', 'DIKEMBALIKAN'].includes(selected.value?.status))

async function kembalikanSelectedKeRekap() {
  if (!selected.value || returningRekap.value) return
  const s = selected.value
  const note = window.prompt(
    `Kembalikan kunjungan "${s.nama_pasien || ''}" ke Rekap Kunjungan BPJS.\n\nTulis pesan/alasan (kekurangan berkas, koding, dll):`, '')
  if (note === null) return   // batal
  if (!note.trim() && !confirm('Pesan kosong. Tetap kembalikan tanpa pesan?')) return
  returningRekap.value = true
  try {
    await api.post(`/klaim/rekap/${s.visit_id}/kembalikan`, { catatan: note.trim() || null })
    toast('s', 'Dikembalikan ke Rekap Kunjungan BPJS')
    selected.value = null   // keluar dari pipeline klaim → tutup panel detail
    await fetchClaims(); await fetchAllForStats()
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal mengembalikan ke Rekap')
  } finally {
    returningRekap.value = false
  }
}

async function unduhBerkas(r) {
  unduhId.value = r.visit_id
  try {
    const { bytes, skipped, pages } = await buildVisitBundle(r.visit_id)
    if (!pages) { toast('w', 'Tak ada berkas yang bisa dirakit'); return }
    downloadPdf(bytes, safeFilename(`KLAIM_${r.no_sep || r.no_rm || r.visit_id}_${r.nama || ''}`) + '.pdf')
    toast(skipped.length ? 'i' : 's', skipped.length ? `Berkas dirakit (${pages} hlm) — ${skipped.length} dilewati` : `Berkas dirakit (${pages} hlm)`)
  } catch (e) {
    toast('w', e.message || 'Gagal merakit berkas')
  } finally {
    unduhId.value = null
  }
}

// ── Preview isi berkas per pasien (expand baris di tab DIVA & Berkas) ───────────
const expandedBerkas = ref(null)        // visit_id yang sedang dibentangkan
const berkasManifest = ref({})          // cache: visit_id → manifest (/klaim/rekap/{id}/berkas)
const manifestLoading = ref(null)       // visit_id yang manifest-nya sedang dimuat
const previewing = ref('')              // key berkas yang sedang dibuka

async function toggleBerkasDetail(r) {
  if (expandedBerkas.value === r.visit_id) { expandedBerkas.value = null; return }
  expandedBerkas.value = r.visit_id
  if (!berkasManifest.value[r.visit_id]) {
    manifestLoading.value = r.visit_id
    try {
      const { data } = await api.get(`/klaim/rekap/${r.visit_id}/berkas`)
      berkasManifest.value = { ...berkasManifest.value, [r.visit_id]: data.data ?? {} }
    } catch (e) {
      toast('w', e.response?.data?.message ?? 'Gagal memuat daftar berkas')
      expandedBerkas.value = null
    } finally {
      manifestLoading.value = null
    }
  }
}

// K2 — unggah lampiran ke DC BPJS (konteks Rekap) lalu refresh manifest visit.
async function uploadBerkasDc(att, visitId) {
  if (!att?.id || uploadingDc.value) return
  uploadingDc.value = att.id
  try {
    const { data } = await api.post(`/klaim/lampiran/${att.id}/upload-dc`)
    toast('s', data?.message ?? 'Berkas terunggah ke BPJS')
    const { data: m } = await api.get(`/klaim/rekap/${visitId}/berkas`)
    berkasManifest.value = { ...berkasManifest.value, [visitId]: m.data ?? {} }
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Upload ke BPJS gagal')
  } finally {
    uploadingDc.value = ''
  }
}

// Buka satu berkas di tab baru. PDF dari API (auth-aware via interceptor) ditarik
// sbg blob; URL storage publik (penunjang/lampiran) dibuka langsung.
async function previewPdf(key, url, { direct = false, html = false } = {}) {
  if (!url || previewing.value) return
  if (direct) { window.open(url, '_blank', 'noopener'); return }
  previewing.value = key
  try {
    // SEP dirender HTML (bukan PDF): Chrome menghormati @page 13x21 cm saat cetak &
    // tampil seukuran lembar aslinya (tak melebar A4 seperti viewer PDF).
    const { data } = await api.get(url, { responseType: html ? 'text' : 'blob' })
    const blob = new Blob([data], { type: html ? 'text/html' : 'application/pdf' })
    const u = URL.createObjectURL(blob)
    const w = window.open(u, '_blank')
    if (!w) { html ? toast('w', 'Izinkan popup untuk membuka SEP') : downloadBlob(blob, 'berkas.pdf') }
    setTimeout(() => URL.revokeObjectURL(u), 60000)
  } catch (e) {
    let msg = 'Gagal membuka berkas'
    try { const t = await e.response?.data?.text?.(); if (t) msg = JSON.parse(t)?.message || msg } catch { /* ignore */ }
    toast('w', msg)
  } finally {
    previewing.value = ''
  }
}

async function exportBatch() {
  if (!confirm(`Export berkas SEMUA pasien (${berkasTotal.value}) pada periode ini ke ZIP? Untuk periode besar bisa memakan waktu.`)) return
  batchBusy.value = true
  batchProgress.value = { done: 0, total: 0, label: 'menyiapkan…' }
  try {
    // Kumpulkan semua kunjungan se-periode (lintas halaman).
    const all = []
    let page = 1
    let last = 1
    do {
      const { data } = await api.get('/klaim/rekap', { params: { ...berkasParams(page), per_page: 100 } })
      const pg = data.data ?? {}
      all.push(...(pg.data ?? []))
      last = pg.last_page ?? 1
      page++
    } while (page <= last)

    const targets = all.filter((v) => v.no_sep)   // berkas tak berarti tanpa SEP
    if (!targets.length) { toast('w', 'Tak ada kunjungan ber-SEP untuk diekspor'); return }

    // Cap memori: seluruh PDF ditahan di RAM sebelum ZIP digenerate → batch terlalu
    // besar bisa membuat tab crash. Hentikan & minta perkecil periode (bukan truncate senyap).
    const BATCH_CAP = 400
    if (targets.length > BATCH_CAP) {
      toast('w', `Terlalu banyak (${targets.length} pasien). Persempit periode — maksimal ${BATCH_CAP} per ZIP agar tak membebani memori browser.`)
      return
    }

    batchProgress.value = { done: 0, total: targets.length, label: '' }
    const { blob, ok, failed, skippedFiles } = await buildBatchZip(targets, (done, total, label) => {
      batchProgress.value = { done, total, label }
    })
    const tag = `${berkasFrom.value}_${berkasTo.value}`.replace(/-/g, '')
    downloadBlob(blob, `BerkasKlaim_${berkasJenis.value || 'SEMUA'}_${tag}.zip`)
    toast(failed ? 'i' : 's', `ZIP siap: ${ok} pasien${failed ? ` · ${failed} gagal` : ''}${skippedFiles ? ` · ${skippedFiles} berkas dilewati` : ''}`)
  } catch (e) {
    toast('w', e.message || 'Gagal export batch')
  } finally {
    batchBusy.value = false
  }
}

// Jalankan grouping INA-CBGs resmi = rantai E-Klaim new → set-data → grouper
// (sesuai catatan UI "Gunakan tombol Jalankan Grouping"). Endpoint /grouping lama
// = prepareClaimData (siapkan data, BUKAN grouper) → tak set inacbgs_kode; dipakai
// terpisah oleh "Kirim ke Klaim" di Rekap. Berhenti & laporkan di langkah yg gagal.
async function runGrouping() {
  if (!selected.value || grouping.value) return
  grouping.value = true
  const id = selected.value.id
  const steps = [
    { m: 'new',      url: `/klaim/${id}/eklaim/new` },
    { m: 'set-data', url: `/klaim/${id}/eklaim/set-data` },
    { m: 'grouper',  url: `/klaim/${id}/eklaim/grouper` },
  ]
  try {
    for (const s of steps) {
      eklaimBusy.value = s.m
      await api.post(s.url)
    }
    await refreshSelected()
    eklaim.value.up = true; eklaim.value.checked = true
    toast('s', selected.value.inacbgs_kode
      ? `Grouping INA-CBGs selesai → ${selected.value.inacbgs_kode} (${fmtRp(selected.value.inacbgs_tarif)})`
      : 'Grouping selesai')
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Grouping gagal')
  } finally {
    eklaimBusy.value = ''
    grouping.value = false
  }
}

// Generate LUPIS via backend (POST /klaim/{id}/lupis).
async function generateLupis() {
  if (!selected.value || generatingLupis.value) return
  generatingLupis.value = true
  try {
    await api.post(`/klaim/${selected.value.id}/lupis`)
    await refreshSelected()
    toast('s', 'Data LUPIS berhasil di-generate')
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal generate LUPIS')
  } finally {
    generatingLupis.value = false
  }
}

// ── Pasca-final: Kirim Klaim Online (DC Kemenkes/BPJS), sinkron status, cetak ───
const dcBusy    = ref(false)
const cetakBusy = ref(false)
// Status pengiriman DC dibaca dari snapshot bpjs_response klaim (diisi syncDc/kirim).
const dcInfo = computed(() => selected.value?.bpjs_response ?? null)
const dcTerkirim = computed(() => {
  const d = dcInfo.value
  return !!d && (d.kemenkes_dc_status_cd === 'sent' || d.bpjs_dc_status_cd === 'sent')
})
const dcStatusLabel = computed(() => {
  const d = dcInfo.value
  if (!d || (!d.kemenkes_dc_status_cd && !d.bpjs_dc_status_cd)) return ''
  return dcTerkirim.value ? 'DC Kemenkes: Terkirim' : 'DC Kemenkes: Belum terkirim'
})

// Kirim Klaim Online ke Pusat Data Kemenkes/BPJS (klaim harus sudah final).
async function kirimOnline() {
  if (!selected.value || eklaimBusy.value) return
  if (!confirm('Kirim klaim final ini secara online ke Pusat Data Kemenkes/BPJS?')) return
  eklaimBusy.value = 'kirim-online'
  try {
    const { data } = await api.post(`/klaim/${selected.value.id}/eklaim/kirim-online`)
    await refreshSelected()
    toast('s', data?.message || (data?.data?.terkirim ? 'Klaim terkirim ke DC Kemenkes' : 'Klaim dikirim online'))
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal mengirim klaim online')
  } finally {
    eklaimBusy.value = ''
  }
}

// Sinkron status pengiriman DC (read-only, get_claim_data).
async function syncDc() {
  if (!selected.value || dcBusy.value) return
  dcBusy.value = true
  try {
    const { data } = await api.get(`/klaim/${selected.value.id}/eklaim/sync-dc`)
    await refreshSelected()
    toast(data?.data?.terkirim ? 's' : 'i', data?.message || 'Status DC disinkronkan')
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal sinkron status DC')
  } finally {
    dcBusy.value = false
  }
}

// Cetak Berkas Klaim Individual Pasien (replika E-Klaim) → buka PDF di tab baru.
async function cetakKlaim() {
  if (!selected.value || cetakBusy.value) return
  cetakBusy.value = true
  try {
    const { data } = await api.get(`/klaim/${selected.value.id}/cetak`, { responseType: 'blob' })
    const blob = new Blob([data], { type: 'application/pdf' })
    const url = URL.createObjectURL(blob)
    const w = window.open(url, '_blank')
    if (!w) downloadBlob(blob, `BERKAS-KLAIM-${selected.value.no_sep || selected.value.id}.pdf`)
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch (e) {
    let msg = 'Gagal mencetak berkas klaim'
    try { const t = await e.response?.data?.text?.(); if (t) msg = JSON.parse(t)?.message || msg } catch { /* ignore */ }
    toast('w', msg)
  } finally {
    cetakBusy.value = false
  }
}

function openResumeMedis() { showDokumenModal.value = true }
function openTindakan() { showTindakanModal.value = true }

// Buka dokumen pendukung. Ambil snapshot HTML via Axios (auth-aware lewat
// interceptor) lalu cetak/lihat di window baru. JANGAN window.open(URL API)
// langsung: navigasi browser tak bawa Bearer (401) & endpoint /cetak balas
// JSON (utk Puppeteer), bukan PDF. Pola sama spt FormRMRenderer/RekamMedisView.
const openingDoc = ref(false)
async function openDocument(doc) {
  if (openingDoc.value) return
  openingDoc.value = true
  try {
    const { data } = await api.get(`/rekam-medis/document/${doc.id}/render`)
    const html = data.data?.rendered_html
    if (!html) { toast('w', `${doc.nama} belum punya isi tersaji untuk dibuka`); return }
    const w = window.open('', '_blank', 'width=900,height=1200')
    if (!w) { toast('w', 'Popup diblokir browser — izinkan popup untuk membuka dokumen'); return }
    w.document.open()
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${doc.nama ?? 'Dokumen'}</title>
<style>@page { size: A4; margin: 1.5cm; } body { font-family: Arial, sans-serif; }</style>
</head><body>${html}</body></html>`)
    w.document.close()
    w.focus()
  } catch (e) {
    toast('w', e.response?.data?.message ?? `Gagal membuka ${doc.nama}`)
  } finally {
    openingDoc.value = false
  }
}

// ── Lampiran berkas klaim (upload PDF/gambar: resume RJ, hasil penunjang) ───────
const attachments    = ref([])
const loadingAtt     = ref(false)
const uploadingAtt   = ref(false)
const attCategory    = ref('PENUNJANG')
const attFileInput   = ref(null)
const ATT_CATEGORIES = [
  { key: 'RESUME',    label: 'Resume Medis' },
  { key: 'PENUNJANG', label: 'Hasil Penunjang' },
  { key: 'SEP',       label: 'SEP' },
  { key: 'SURAT',     label: 'Surat/Rujukan' },
  { key: 'LAINNYA',   label: 'Lainnya' },
]
const attCanEdit = computed(() => selected.value && !['SUBMITTED', 'SELESAI'].includes(selected.value.status))

// ── Resume Medis (bukti klaim) — di-TTD dokter saat pelayanan ──────────────────
// Tak ada "lembar klaim" terpisah: klaim memakai RESUME MEDIS ber-TTD milik
// kunjungan. Bila isi tak sesuai, dokter merevisi resume medisnya sendiri (di RME).
const lembarState = computed(() => {
  const lk = selected.value?.lembar_klaim
  if (!lk?.exists) return { cls: 'pend', text: 'Resume medis belum ada' }
  if (lk.signed) return { cls: 'ok', text: '✓ Resume medis ditandatangani dokter' }
  return { cls: 'pend', text: 'Resume medis menunggu TTD dokter' }
})

async function fetchAttachments() {
  if (!selected.value) { attachments.value = []; return }
  const id = selected.value.id
  loadingAtt.value = true
  try {
    const { data } = await api.get(`/klaim/${id}/lampiran`)
    if (selected.value?.id !== id) return // klaim sudah berganti
    attachments.value = data.data ?? []
  } catch {
    attachments.value = []
  } finally {
    loadingAtt.value = false
  }
}

function pickAttFile() { attFileInput.value?.click() }

async function onAttFileChange(e) {
  const file = e.target.files?.[0]
  e.target.value = '' // reset agar file sama bisa dipilih ulang
  if (!file || !selected.value) return
  if (file.size > 10 * 1024 * 1024) { toast('w', 'Ukuran berkas maksimal 10 MB'); return }

  const id = selected.value.id
  uploadingAtt.value = true
  try {
    const form = new FormData()
    form.append('file', file)
    form.append('category', attCategory.value)
    await api.post(`/klaim/${id}/lampiran`, form, { headers: { 'Content-Type': 'multipart/form-data' } })
    toast('s', 'Lampiran berhasil diunggah')
    await fetchAttachments()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal mengunggah lampiran')
  } finally {
    uploadingAtt.value = false
  }
}

async function deleteAttachment(att) {
  if (!selected.value) return
  if (!confirm(`Hapus lampiran "${att.title || att.file_name}"?`)) return
  try {
    await api.delete(`/klaim/${selected.value.id}/lampiran/${att.id}`)
    toast('i', 'Lampiran dihapus')
    await fetchAttachments()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal menghapus lampiran')
  }
}

function fmtFileSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1048576) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1048576).toFixed(1)} MB`
}

// ── Actions (wired ke backend KlaimService) ────────────────────────────────────
async function mulaiReview() {
  if (!selected.value || selected.value.status !== 'DRAFT' || actionBusy.value) return
  actionBusy.value = true
  try {
    await api.put(`/klaim/${selected.value.id}/review`)
    await refreshSelected()
    toast('i', `Klaim ${(selected.value.no_sep?.slice(-6) ?? selected.value.no_sep ?? "")} dalam proses review`)
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal memulai review')
  } finally {
    actionBusy.value = false
  }
}

async function verifikasi() {
  if (!selected.value || selected.value.status !== 'REVIEW' || !checklistComplete.value || actionBusy.value) return
  actionBusy.value = true
  try {
    await api.put(`/klaim/${selected.value.id}/verifikasi`)
    await refreshSelected()
    toast('s', `Klaim ${(selected.value.no_sep?.slice(-6) ?? selected.value.no_sep ?? "")} berhasil diverifikasi`)
  } catch (e) {
    // Pesan backend mis. "Grouping INA-CBGs belum dilakukan" / "LUPIS belum di-generate".
    toast('w', e.response?.data?.message ?? 'Verifikasi gagal')
  } finally {
    actionBusy.value = false
  }
}

async function kembalikanDraft() {
  if (!selected.value || actionBusy.value) return
  if (!koreksiNote.value.trim() || koreksiNote.value.trim().length < 5) {
    toast('w', 'Isi catatan koreksi (min. 5 karakter) sebelum mengembalikan klaim')
    return
  }
  actionBusy.value = true
  try {
    // Backend reject (DITOLAK) dengan alasan; UI menyebutnya "kembalikan untuk perbaikan".
    await api.put(`/klaim/${selected.value.id}/reject`, { alasan: koreksiNote.value.trim() })
    await refreshSelected()
    koreksiNote.value = ''
    toast('w', 'Klaim dikembalikan dengan catatan koreksi')
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal mengembalikan klaim')
  } finally {
    actionBusy.value = false
  }
}

function openSubmitModal() {
  if (!selected.value || selected.value.status !== 'VERIFIED') return
  showSubmitModal.value = true
}
function cancelSubmit() { showSubmitModal.value = false }

async function confirmSubmit() {
  if (!selected.value || submitting.value) return
  submitting.value = true
  try {
    await api.post(`/klaim/${selected.value.id}/submit`)
    await refreshSelected()
    showSubmitModal.value = false
    toast('s', `Klaim ${(selected.value.no_sep?.slice(-6) ?? selected.value.no_sep ?? "")} berhasil dikirim ke BPJS`)
  } catch (e) {
    // mis. 503 VClaim belum aktif.
    toast('w', e.response?.data?.message ?? 'Gagal mengirim ke BPJS')
  } finally {
    submitting.value = false
  }
}

const isRejected = computed(() => selected.value && REJECTED_STATUSES.includes(selected.value.status))
// Koding hanya boleh diedit sebelum dikirim ke BPJS.
const codingEditable = computed(() => selected.value && !['SUBMITTED', 'SELESAI'].includes(selected.value.status))

// Ajukan ulang klaim yang dikembalikan/ditolak → kembali ke DRAFT (resubmission_count++).
async function ajukanUlang() {
  if (!selected.value || !isRejected.value || actionBusy.value) return
  actionBusy.value = true
  try {
    await api.post(`/klaim/${selected.value.id}/resubmit`)
    await refreshSelected()
    toast('s', `Klaim diajukan ulang. Perbaiki data lalu jalankan Grouping → LUPIS → Verifikasi.`)
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal mengajukan ulang klaim')
  } finally {
    actionBusy.value = false
  }
}

// ── #4 Monitoring VClaim (live BPJS, pisah RJ/RI) ──────────────────────────────
const mon = ref({
  jns: '2',          // 2 = Rawat Jalan, 1 = Rawat Inap
  tgl: todayStr(),
  status: '1',       // 1 Proses Verifikasi, 2 Pending, 3 Klaim
  loading: false,
  error: '',
  data: null,
})
async function cekMonitoring() {
  mon.value.loading = true
  mon.value.error = ''
  mon.value.data = null
  try {
    const { data } = await integrasiApi.monitoring('klaim', {
      tgl: mon.value.tgl, jns: mon.value.jns, status: mon.value.status,
    })
    mon.value.data = data.data ?? data
  } catch (e) {
    mon.value.error = e.response?.data?.message ?? 'Gagal memuat monitoring (pastikan VClaim aktif).'
  } finally {
    mon.value.loading = false
  }
}
// Ambil baris tabel dari berbagai bentuk respons VClaim.
function monRows(d) {
  if (!d) return []
  const arr = d.list ?? d.data?.list ?? d.response?.list ?? (Array.isArray(d) ? d : null)
  return Array.isArray(arr) ? arr : []
}
function monCols(rows) {
  if (!rows.length) return []
  return Object.keys(rows[0]).slice(0, 8)
}

// ── #2 Audit riwayat: pilih tanggal + navigasi per-minggu ──────────────────────
// Default: minggu yang memuat HARI INI. auditWeekStart = Senin minggu tsb.
function mondayOf(dateStr) {
  const d = new Date(dateStr)
  const day = (d.getDay() + 6) % 7 // 0 = Senin
  d.setDate(d.getDate() - day)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const auditWeekStart = ref(mondayOf(todayStr()))
function auditWeekShift(deltaWeeks) {
  const d = new Date(auditWeekStart.value)
  d.setDate(d.getDate() + deltaWeeks * 7)
  auditWeekStart.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const auditWeekEnd = computed(() => {
  const d = new Date(auditWeekStart.value)
  d.setDate(d.getDate() + 6)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
})
const auditWeekDays = computed(() => {
  const out = []
  for (let i = 0; i < 7; i++) {
    const d = new Date(auditWeekStart.value)
    d.setDate(d.getDate() + i)
    out.push(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`)
  }
  return out
})
// Default hari aktif = hari yang sama dgn hari ini bila ada di minggu, else Senin.
const auditDay = ref(todayStr())
watch(auditWeekStart, () => {
  // Saat pindah minggu, pilih hari dengan day-of-week sama seperti sebelumnya.
  const prevDow = (new Date(auditDay.value).getDay() + 6) % 7
  auditDay.value = auditWeekDays.value[prevDow] ?? auditWeekStart.value
})
// Audit yang ditampilkan: difilter ke hari terpilih (auditDay).
const auditFiltered = computed(() => {
  if (!selected.value) return []
  return selected.value.audit_trail.filter((l) => (l.at ?? '').slice(0, 10) === auditDay.value)
})

// ── Toast ─────────────────────────────────────────────────────────────────────
const toasts = ref([])
let tid = 0
function toast(type, msg) {
  const id = ++tid
  toasts.value.push({ id, type, msg })
  setTimeout(() => (toasts.value = toasts.value.filter((t) => t.id !== id)), 3500)
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}
function fmtDateTime(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
function fmtRp(v) { return 'Rp ' + (v || 0).toLocaleString('id-ID') }
// Format ISO 'YYYY-MM-DD' (nilai input date native) → 'DD/MM/YYYY' utk tampilan.
// Split string langsung (bukan new Date) agar bebas pergeseran timezone.
function fmtTglDMY(d) {
  if (!d) return '—'
  const m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})/)
  return m ? `${m[3]}/${m[2]}/${m[1]}` : d
}

const statusMeta = {
  DRAFT:        { label: 'Draft',           bg: 'var(--bs)',  color: 'var(--tu)',  border: 'var(--gb)' },
  REVIEW:       { label: 'Dalam Review',    bg: 'var(--ib)',  color: 'var(--it)',  border: 'var(--ibd)' },
  VERIFIED:     { label: 'Terverifikasi',   bg: '#f0fdf4',    color: 'var(--ld)',  border: 'var(--sbd)' },
  SUBMITTED:    { label: 'Terkirim',        bg: 'var(--pb)',  color: 'var(--pt)',  border: 'var(--pbd)' },
  SELESAI:      { label: 'Selesai',         bg: 'var(--sb)',  color: 'var(--st)',  border: 'var(--sbd)' },
  DIKEMBALIKAN: { label: 'Dikembalikan',    bg: 'var(--wb)',  color: 'var(--wt)',  border: 'var(--wbd)' },
  DITOLAK_BPJS: { label: 'Ditolak BPJS',    bg: 'var(--eb)',  color: 'var(--et)',  border: 'var(--ebd)' },
  DITOLAK:      { label: 'Ditolak',         bg: 'var(--eb)',  color: 'var(--et)',  border: 'var(--ebd)' },
}

const bpjsStatusMeta = {
  PENDING:  { label: 'Menunggu Respons', bg: 'var(--wb)', color: 'var(--wt)', border: 'var(--wbd)' },
  PROSES:   { label: 'Diproses BPJS',   bg: 'var(--ib)', color: 'var(--it)', border: 'var(--ibd)' },
  SELESAI:  { label: 'Disetujui BPJS',  bg: 'var(--sb)', color: 'var(--st)', border: 'var(--sbd)' },
  // E-Klaim menulis bpjs_status='FINAL' setelah finalisasi (KlaimService L355).
  FINAL:    { label: 'Final di E-Klaim', bg: 'var(--sb)', color: 'var(--st)', border: 'var(--sbd)' },
  DITOLAK:  { label: 'Ditolak BPJS',    bg: 'var(--eb)', color: 'var(--et)', border: 'var(--ebd)' },
}
// Fallback aman agar banner/badge tak crash bila status di luar daftar di atas.
const FALLBACK_META = { label: '', bg: 'var(--bs)', color: 'var(--tu)', border: 'var(--gb)' }
function statusMetaOf(s) { return statusMeta[s] ?? { ...FALLBACK_META, label: s ?? '—' } }
function bpjsStatusMetaOf(s) { return bpjsStatusMeta[s] ?? { ...FALLBACK_META, label: s ?? '—' } }

const auditActionMeta = {
  PREPARE:        { label: 'Klaim Disiapkan',       color: 'var(--tu)' },
  CREATED:        { label: 'Klaim Dibuat',          color: 'var(--tu)' },
  REVIEW:         { label: 'Masuk Review',          color: 'var(--it)' },
  REVIEWED:       { label: 'Masuk Review',          color: 'var(--it)' },
  GROUPING:       { label: 'Grouping INA-CBGs',     color: 'var(--it)' },
  LUPIS_GENERATED:{ label: 'LUPIS Dibuat',          color: 'var(--it)' },
  VERIFIKASI:     { label: 'Terverifikasi',         color: 'var(--ld)' },
  VERIFIED:       { label: 'Terverifikasi',         color: 'var(--ld)' },
  SUBMIT:         { label: 'Dikirim ke BPJS',       color: 'var(--pt)' },
  SUBMITTED:      { label: 'Dikirim ke BPJS',       color: 'var(--pt)' },
  SELESAI:        { label: 'Klaim Disetujui',       color: 'var(--st)' },
  RETURN_INTERNAL:{ label: 'Dikembalikan (Internal)', color: 'var(--wt)' },
  REJECT:         { label: 'Dikembalikan',          color: 'var(--wt)' },
  REJECT_BPJS:    { label: 'Ditolak BPJS',          color: 'var(--et)' },
  DITOLAK:        { label: 'Klaim Ditolak',         color: 'var(--et)' },
  RESUBMIT:       { label: 'Diajukan Ulang',        color: 'var(--it)' },
  RETURNED:       { label: 'Dikembalikan ke Draft', color: 'var(--wt)' },
}

const statusSteps = ['DRAFT', 'REVIEW', 'VERIFIED', 'SUBMITTED', 'SELESAI']
function stepIndex(status) {
  if (REJECTED_STATUSES.includes(status)) return -1
  return statusSteps.indexOf(status)
}
</script>

<template>
  <div class="klaim">

    <!-- ── PAGE TABS (Klaim | Monitoring) ──────────────────────────────────── -->
    <div class="kl-pagetabs" role="tablist" aria-label="Tab halaman klaim">
      <button :class="['kl-ptab', pageTab === 'klaim' ? 'a' : '']" role="tab" :aria-selected="pageTab === 'klaim'" @click="pageTab = 'klaim'">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
        Berkas Klaim
      </button>
      <button :class="['kl-ptab', pageTab === 'berkas' ? 'a' : '']" role="tab" :aria-selected="pageTab === 'berkas'" @click="pageTab = 'berkas'; fetchBerkasList()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 12l2 2 4-4"/><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>
        DIVA &amp; Berkas
      </button>
      <button :class="['kl-ptab', pageTab === 'monitoring' ? 'a' : '']" role="tab" :aria-selected="pageTab === 'monitoring'" @click="pageTab = 'monitoring'">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
        Monitoring VClaim
      </button>
    </div>

    <!-- ════════════════ TAB: BERKAS KLAIM ════════════════ -->
    <template v-if="pageTab === 'klaim'">
    <!-- ── STAT CARDS ──────────────────────────────────────────────────────── -->
    <div class="kl-stat-caption">
      Ringkasan klaim · periode <strong>{{ statPeriodLabel }}</strong> <span class="kl-stat-sub">(menurut tgl klaim dibuat — ikut filter tanggal di bawah)</span>
      <span v-if="statsCapped" class="kl-stat-capped" title="Jumlah dibatasi — perkecil rentang tanggal untuk angka akurat">· {{ STAT_CAP }}+ (dibatasi)</span>
    </div>
    <div class="stat-row" role="region" :aria-label="`Ringkasan klaim BPJS periode ${statPeriodLabel}`">
      <div class="stat-card" style="border-top: 3px solid var(--ga)">
        <div class="stat-icon" style="background: var(--gl)" aria-hidden="true">
          <svg viewBox="0 0 24 24" stroke="var(--ga)"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
        </div>
        <div>
          <div class="stat-val">{{ stats.total }}</div>
          <div class="stat-lbl">Total Klaim</div>
        </div>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--wt)">
        <div class="stat-icon" style="background: var(--wb)" aria-hidden="true">
          <svg viewBox="0 0 24 24" stroke="var(--wt)"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div>
          <div class="stat-val" style="color: var(--wt)">{{ stats.menunggu }}</div>
          <div class="stat-lbl">Menunggu Verifikasi</div>
        </div>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--st)">
        <div class="stat-icon" style="background: #f0fdf4" aria-hidden="true">
          <svg viewBox="0 0 24 24" stroke="var(--ld)"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div>
          <div class="stat-val" style="color: var(--ld)">{{ stats.siapSubmit }}</div>
          <div class="stat-lbl">Siap Dikirim ke BPJS</div>
        </div>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--et)">
        <div class="stat-icon" style="background: var(--eb)" aria-hidden="true">
          <svg viewBox="0 0 24 24" stroke="var(--et)"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div>
          <div class="stat-val" style="color: var(--et)">{{ stats.ditolak }}</div>
          <div class="stat-lbl">Ditolak BPJS</div>
        </div>
      </div>

      <!-- Status koneksi E-Klaim INA-CBG (klik untuk tes ulang) -->
      <button
        class="stat-card ek-stat"
        :style="`border-top: 3px solid ${eklaim.up ? 'var(--ld)' : (eklaim.checked ? 'var(--et)' : 'var(--bd)')}`"
        :title="eklaim.checking ? 'Mengecek…' : 'Klik untuk tes koneksi E-Klaim'"
        :disabled="eklaim.checking"
        @click="checkEklaim(true)"
      >
        <div class="stat-icon" :style="`background: ${eklaim.up ? '#f0fdf4' : 'var(--eb)'}`" aria-hidden="true">
          <svg v-if="eklaim.checking" class="ek-spin" viewBox="0 0 24 24" stroke="var(--tm)"><path d="M21 12a9 9 0 11-6.22-8.56"/></svg>
          <svg v-else-if="eklaim.up" viewBox="0 0 24 24" stroke="var(--ld)"><path d="M5 12.55a11 11 0 0114.08 0"/><path d="M1.42 9a16 16 0 0121.16 0"/><path d="M8.53 16.11a6 6 0 016.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
          <svg v-else viewBox="0 0 24 24" stroke="var(--et)"><line x1="1" y1="1" x2="23" y2="23"/><path d="M16.72 11.06A10.94 10.94 0 0119 12.55"/><path d="M5 12.55a10.94 10.94 0 015.17-2.39"/><path d="M10.71 5.05A16 16 0 0122.58 9"/><path d="M1.42 9a15.91 15.91 0 014.7-2.88"/><path d="M8.53 16.11a6 6 0 016.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
        </div>
        <div>
          <div class="stat-val ek-val" :style="`color: ${eklaim.up ? 'var(--ld)' : (eklaim.checked ? 'var(--et)' : 'var(--tm)')}`">
            {{ eklaim.checking ? 'Cek…' : (eklaim.up ? 'Hidup' : (eklaim.checked ? 'Mati' : '—')) }}
          </div>
          <div class="stat-lbl">Koneksi E-Klaim</div>
        </div>
      </button>
    </div>

    <!-- ── MAIN GRID ───────────────────────────────────────────────────────── -->
    <div class="kl-grid">

      <!-- LEFT PANEL — Daftar Klaim -->
      <aside class="kl-panel" aria-label="Daftar klaim BPJS">
        <div class="kl-ph">
          <div class="kl-ph-title">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
            Daftar Klaim BPJS
          </div>
          <span class="live-pill" aria-label="Data diperbarui secara real-time">LIVE</span>
        </div>

        <!-- Search -->
        <div class="kl-search">
          <label for="kl-search-input" class="sr-only">Cari pasien, No. SEP, atau No. Kartu</label>
          <div class="kl-search-wrap">
            <svg class="kl-search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input
              id="kl-search-input"
              v-model="searchQuery"
              class="fi kl-fi-search"
              placeholder="Cari nama / No. SEP / No. Kartu..."
              type="search"
              autocomplete="off"
            />
          </div>
        </div>

        <!-- Date filter -->
        <div class="kl-date-row">
          <div class="kl-date-field">
            <label for="date-from" class="fl">Dari</label>
            <input id="date-from" v-model="dateFrom" type="date" class="fi" aria-label="Tanggal mulai filter" />
          </div>
          <div class="kl-date-field">
            <label for="date-to" class="fl">Sampai</label>
            <input id="date-to" v-model="dateTo" type="date" class="fi" aria-label="Tanggal akhir filter" />
          </div>
        </div>

        <!-- RJ/RI toggle -->
        <div class="kl-jenis" role="tablist" aria-label="Filter jenis pelayanan">
          <button
            v-for="j in jenisTabs"
            :key="j.v"
            :class="['kl-jbtn', jenisFilter === j.v ? 'a' : '']"
            role="tab"
            :aria-selected="jenisFilter === j.v"
            @click="jenisFilter = j.v"
          >{{ j.l }}</button>
        </div>

        <!-- Status tabs -->
        <div class="kl-stabs" role="tablist" aria-label="Filter status klaim">
          <button
            v-for="s in statusTabs"
            :key="s"
            :class="['kl-stab', statusFilter === s ? 'a' : '']"
            role="tab"
            :aria-selected="statusFilter === s"
            @click="statusFilter = s"
          >
            {{ s === 'SEMUA' ? 'Semua' : statusMeta[s]?.label ?? s }}
            <span v-if="s !== 'SEMUA'" class="kl-stab-ct">
              {{ statusCount(s) }}
            </span>
          </button>
        </div>

        <!-- Date filter active banner -->
        <div v-if="dateFilterActive" class="kl-date-active-banner">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          <span>{{ fmtTglDMY(dateFrom) }} s/d {{ fmtTglDMY(dateTo) }} · <strong>{{ filteredClaims.length }} klaim</strong></span>
          <button class="kl-date-clear" @click="dateFrom = ''; dateTo = ''">✕ Hapus Filter</button>
        </div>

        <!-- Claim list -->
        <!-- Bulk action bar -->
        <div v-if="bulkCount" class="kl-bulk-bar" role="region" aria-label="Aksi massal klaim">
          <span class="kl-bulk-ct">{{ bulkCount }} dipilih</span>
          <button class="btn btn-info btn-sm" :disabled="bulkBusy" title="Tandai dikerjakan oleh saya" @click="bulkAssignSelf">
            <div v-if="bulkBusy" class="sp" aria-hidden="true"></div>
            Saya kerjakan
          </button>
          <button v-if="bulkActionable" class="btn btn-success btn-sm" :disabled="bulkBusy" @click="runBulk">
            {{ bulkActionable.label }}
          </button>
          <button class="kl-bulk-clear" @click="clearBulk">✕</button>
        </div>

        <div class="kl-list" role="listbox" :aria-label="`${filteredClaims.length} klaim ditampilkan`">
          <div
            v-for="c in filteredClaims"
            :key="c.id"
            :class="['kl-item', selected && selected.id === c.id ? 'ac' : '', bulkSelected.has(c.id) ? 'bulk' : '']"
            :style="{ borderLeft: `3px solid ${(statusMeta[c.status] || {}).color || 'var(--gb)'}` }"
            role="option"
            :aria-selected="selected && selected.id === c.id"
            tabindex="0"
            @click="selectClaim(c)"
            @keydown="handleListKeydown($event, c)"
          >
            <input
              type="checkbox"
              class="kl-item-check"
              :checked="bulkSelected.has(c.id)"
              :aria-label="`Pilih klaim ${c.nama_pasien}`"
              @click.stop="toggleBulk(c.id)"
            />
            <div class="kl-item-left">
              <div class="kl-item-name">
                {{ c.nama_pasien }}
                <span class="kl-ri-tag" :title="c.jenis_pelayanan">{{ c.jenis_pelayanan === 'Rawat Inap' ? 'RI' : 'RJ' }}</span>
              </div>
              <div class="kl-item-sep">{{ c.no_sep }}</div>
              <div class="kl-item-date">
                {{ fmtDate(c.tanggal_pelayanan) }} · {{ c.dpjp.split(',')[0] }}
                <span v-if="ageClass(c)" :class="['kl-age', ageClass(c)]" :title="`Umur klaim ${claimAgeDays(c)} hari`">{{ claimAgeDays(c) }}h</span>
              </div>
              <div v-if="hasDiagnosis(c)" class="kl-item-diag">{{ c.diagnosis_utama.kode }}</div>
              <div v-else class="kl-item-diag kl-need-dx" title="Diagnosis belum diisi dokter — lengkapi via Edit Koding sebelum grouping">⚠ Perlu Diagnosis</div>
              <div v-if="c.assigned_to" class="kl-assigned" :title="`Dikerjakan oleh ${c.assigned_to.name}`">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                {{ c.assigned_to.id === myId ? 'Saya' : c.assigned_to.name.split(' ')[0] }}
              </div>
            </div>
            <div class="kl-item-right">
              <div
                class="kl-badge"
                :style="{ background: (statusMeta[c.status] || {}).bg, color: (statusMeta[c.status] || {}).color, borderColor: (statusMeta[c.status] || {}).border }"
              >{{ (statusMeta[c.status] || {}).label || c.status }}</div>
              <div class="kl-item-tarif">{{ fmtRp(c.inacbgs_tarif) }}</div>
            </div>
          </div>
          <div v-if="!filteredClaims.length" class="kl-empty-list" role="status">
            Tidak ada klaim yang sesuai filter
          </div>
        </div>
      </aside>

      <!-- RIGHT PANEL — Detail -->
      <section class="kl-detail" aria-label="Detail klaim yang dipilih">

        <!-- Empty state -->
        <div v-if="!selected" class="empty-state">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="11" y2="16"/></svg>
          <p>Pilih klaim dari daftar untuk melihat detail, melakukan verifikasi, dan mengirimkan ke BPJS</p>
        </div>

        <template v-else>

          <!-- Patient Banner -->
          <div class="kl-banner" role="region" aria-label="Informasi pasien">
            <div class="kl-av" aria-hidden="true">{{ selected.nama_pasien.charAt(0) }}</div>
            <div class="kl-banner-info">
              <div class="kl-banner-name">{{ selected.nama_pasien }}</div>
              <div class="kl-banner-meta">NIK {{ selected.nik }} · {{ selected.jenis_pelayanan }} · {{ fmtDate(selected.tanggal_pelayanan) }}</div>
              <div class="kl-banner-tags">
                <span class="kl-btag kl-btag-bpjs">BPJS · {{ selected.no_kartu_bpjs }}</span>
                <span class="kl-btag kl-btag-sep">SEP {{ selected.no_sep }}</span>
                <span class="kl-btag" :style="{ background: 'rgba(255,255,255,0.12)', color: '#fff', border: '1px solid rgba(255,255,255,0.2)' }">
                  DPJP: {{ selected.dpjp }}
                </span>
              </div>
            </div>
            <div class="kl-banner-tarif">
              <div class="kl-banner-tarif-v">{{ fmtRp(selected.inacbgs_tarif) }}</div>
              <div class="kl-banner-tarif-l">INA-CBGs Tarif</div>
              <div
                class="kl-banner-status"
                :style="{ background: statusMetaOf(selected.status).bg, color: statusMetaOf(selected.status).color, border: `1px solid ${statusMetaOf(selected.status).border}` }"
              >{{ statusMetaOf(selected.status).label }}</div>
            </div>
          </div>

          <!-- #3 Assignment bar (anti double-work, soft) -->
          <div class="kl-assign-bar" :class="{ other: selected.assigned_to && selected.assigned_to.id !== myId }">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span v-if="!selected.assigned_to" class="kl-assign-txt">Belum ditandai dikerjakan siapa pun.</span>
            <span v-else-if="selected.assigned_to.id === myId" class="kl-assign-txt">Dikerjakan oleh <strong>Anda</strong>.</span>
            <span v-else class="kl-assign-txt">⚠ Sedang dikerjakan oleh <strong>{{ selected.assigned_to.name }}</strong>. Hindari kerja ganda.</span>
            <button class="btn btn-secondary btn-sm" :disabled="assigning" @click="toggleAssignSelf">
              <div v-if="assigning" class="sp" aria-hidden="true"></div>
              {{ selected.assigned_to && selected.assigned_to.id === myId ? 'Lepas' : 'Saya kerjakan' }}
            </button>
          </div>

          <!-- BPJS Response Alert -->
          <div v-if="selected.bpjs_status" class="kl-bpjs-resp"
            :style="{ background: bpjsStatusMetaOf(selected.bpjs_status).bg, borderColor: bpjsStatusMetaOf(selected.bpjs_status).border, color: bpjsStatusMetaOf(selected.bpjs_status).color }"
            role="status"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true" style="width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;flex-shrink:0">
              <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <span>Respons BPJS: <strong>{{ bpjsStatusMetaOf(selected.bpjs_status).label }}</strong></span>
          </div>

          <!-- Inner Tabs -->
          <div class="kl-tabs" role="tablist" aria-label="Tab detail klaim">
            <button :class="['kl-tab', activeDetailTab === 'data' ? 'a' : '']" role="tab" :aria-selected="activeDetailTab === 'data'" @click="activeDetailTab = 'data'">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              Data Klaim
            </button>
            <button :class="['kl-tab', activeDetailTab === 'verify' ? 'a' : '']" role="tab" :aria-selected="activeDetailTab === 'verify'" @click="activeDetailTab = 'verify'">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              Verifikasi & Aksi
            </button>
            <button :class="['kl-tab', activeDetailTab === 'audit' ? 'a' : '']" role="tab" :aria-selected="activeDetailTab === 'audit'" @click="activeDetailTab = 'audit'">
              <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
              Riwayat & Audit
              <span class="kl-tab-ct">{{ selected.audit_trail.length }}</span>
            </button>
          </div>

          <!-- ── TAB: DATA KLAIM ───────────────────────────────────────────── -->
          <div v-if="activeDetailTab === 'data'" class="kl-tab-body" role="tabpanel" aria-label="Data klaim">
            <div class="kl-data-grid">

              <!-- Identitas & SEP -->
              <div class="card kl-dg-identitas">
                <div class="card-head">
                  <div class="card-head-title">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                    Identitas Kepesertaan
                  </div>
                  <span class="priv-badge" aria-label="Data pasien dilindungi sesuai UU PDP">
                    <svg viewBox="0 0 24 24" aria-hidden="true" style="width:11px;height:11px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    Data Terlindungi
                  </span>
                </div>
                <div class="card-body">
                  <div class="kl-info-grid">
                    <div class="kl-info-item">
                      <div class="kl-info-label">No. SEP</div>
                      <div class="kl-info-val mono">{{ selected.no_sep }}</div>
                    </div>
                    <div class="kl-info-item">
                      <div class="kl-info-label">No. Kartu BPJS</div>
                      <div class="kl-info-val mono">{{ selected.no_kartu_bpjs }}</div>
                    </div>
                    <div class="kl-info-item">
                      <div class="kl-info-label">NIK</div>
                      <div class="kl-info-val mono">{{ selected.nik }}</div>
                    </div>
                    <div class="kl-info-item">
                      <div class="kl-info-label">Jenis Pelayanan</div>
                      <div class="kl-info-val">{{ selected.jenis_pelayanan }}</div>
                    </div>
                    <div class="kl-info-item">
                      <div class="kl-info-label">Tanggal Pelayanan</div>
                      <div class="kl-info-val">{{ fmtDate(selected.tanggal_pelayanan) }}</div>
                    </div>
                    <div class="kl-info-item">
                      <div class="kl-info-label">DPJP</div>
                      <div class="kl-info-val">{{ selected.dpjp }}</div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Diagnosis & Tindakan -->
              <div class="card kl-dg-diagnosis">
                <div class="card-head">
                  <div class="card-head-title">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                    Diagnosis & Tindakan
                  </div>
                  <button v-if="codingEditable" class="btn btn-secondary btn-sm" title="Input/ubah koding ICD-10 & ICD-9 klaim" @click="openCodingEditor">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4z"/></svg>
                    {{ (selected.diagnosis_utama && selected.diagnosis_utama.kode && selected.diagnosis_utama.kode !== '—') ? 'Edit Koding' : 'Input Koding' }}
                  </button>
                </div>
                <div class="card-body">
                  <div class="kl-dx-section">
                    <div class="kl-dx-label">Diagnosis Utama (ICD-10)</div>
                    <div v-if="selected.diagnosis_utama && selected.diagnosis_utama.kode && selected.diagnosis_utama.kode !== '—'" class="kl-dx-row">
                      <span class="kl-code-pill kl-code-dx">{{ selected.diagnosis_utama.kode }}</span>
                      <span class="kl-dx-name">{{ selected.diagnosis_utama.label }}</span>
                    </div>
                    <div v-else class="kl-dx-empty">
                      Belum ada diagnosis. Klik <strong>Input Koding</strong> untuk mengisi ICD-10
                      <span v-if="selected.diagnosis_text"> — catatan dokter: "{{ selected.diagnosis_text }}"</span>.
                    </div>
                  </div>
                  <div v-if="selected.diagnosis_sekunder.length" class="kl-dx-section">
                    <div class="kl-dx-label">Diagnosis Sekunder</div>
                    <div v-for="ds in selected.diagnosis_sekunder" :key="ds.kode" class="kl-dx-row">
                      <span class="kl-code-pill kl-code-ds">{{ ds.kode }}</span>
                      <span class="kl-dx-name">{{ ds.label }}</span>
                    </div>
                  </div>
                  <div v-if="selected.diagnosis_text" class="kl-dx-section">
                    <div class="kl-dx-label">Catatan Diagnosa Dokter (naratif)</div>
                    <div class="kl-dx-freetext">{{ selected.diagnosis_text }}</div>
                  </div>
                  <div class="kl-dx-section">
                    <div class="kl-dx-label">Tindakan (ICD-9 CM)</div>
                    <div v-for="t in selected.tindakan" :key="t.kode" class="kl-dx-row">
                      <span class="kl-code-pill kl-code-tn">{{ t.kode }}</span>
                      <span class="kl-dx-name">{{ t.label }}</span>
                    </div>
                    <div v-if="!selected.tindakan.length" class="kl-dx-empty">Belum ada tindakan (opsional) — tambahkan via Input Koding bila perlu.</div>
                  </div>
                </div>
              </div>

              <!-- INA-CBGs -->
              <div class="card kl-dg-inacbgs">
                <div class="card-head">
                  <div class="card-head-title">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 100 7h5a3.5 3.5 0 110 7H6"/></svg>
                    INA-CBGs & Tarif Klaim
                  </div>
                </div>
                <div class="card-body">
                  <div class="kl-cbgs-row">
                    <div class="kl-cbgs-item">
                      <div class="kl-info-label">Kode Grouper INA-CBGs</div>
                      <div class="kl-cbgs-code-row">
                        <span class="kl-cbgs-code">{{ selected.inacbgs_kode || '— belum di-grouping —' }}</span>
                      </div>
                    </div>
                    <div class="kl-cbgs-item">
                      <div class="kl-info-label">Tarif INA-CBGs (dibayar BPJS)</div>
                      <div class="kl-cbgs-tarif">{{ fmtRp(selected.inacbgs_tarif) }}</div>
                    </div>
                    <div class="kl-cbgs-item">
                      <div class="kl-info-label">Total Tagihan Riil (Kasir)</div>
                      <div class="kl-cbgs-tarif" style="color:var(--wt)">{{ fmtRp(selected.harga_kasir) }}</div>
                    </div>
                  </div>

                  <!-- Tarif paket INA-CBGs vs tagihan riil (sesuai Permenkes 3/2023:
                       klaim BPJS = tarif paket CBG, bukan rincian per-item). -->
                  <table class="tbl" aria-label="Perbandingan tarif klaim">
                    <tfoot>
                      <tr class="tbl-total-row">
                        <td class="strong">Tarif Klaim INA-CBGs (paket)</td>
                        <td class="num strong">{{ fmtRp(selected.inacbgs_tarif) }}</td>
                      </tr>
                      <tr class="tbl-kasir-row">
                        <td>Total Tagihan Riil RS</td>
                        <td class="num">{{ fmtRp(selected.harga_kasir) }}</td>
                      </tr>
                      <tr :class="['tbl-selisih-row', selected.harga_kasir > selected.inacbgs_tarif ? 'over' : 'under']">
                        <td>Selisih (Tagihan − INA-CBGs)</td>
                        <td class="num">{{ selected.harga_kasir >= selected.inacbgs_tarif ? '+' : '' }}{{ fmtRp(selected.harga_kasir - selected.inacbgs_tarif) }}</td>
                      </tr>
                    </tfoot>
                  </table>

                  <!-- Aksi grouping & LUPIS (hanya saat klaim belum dikirim) -->
                  <div v-if="!['SUBMITTED','SELESAI'].includes(selected.status)" class="kl-lupis-btn-row">
                    <button class="btn btn-secondary btn-sm" :disabled="grouping" aria-label="Jalankan grouping INA-CBGs" @click="runGrouping">
                      <div v-if="grouping" class="sp" aria-hidden="true"></div>
                      <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>
                      {{ selected.inacbgs_kode ? 'Grouping Ulang' : 'Jalankan Grouping' }}
                    </button>
                    <button class="btn btn-secondary btn-sm" :disabled="generatingLupis || !selected.inacbgs_kode" :title="!selected.inacbgs_kode ? 'Jalankan grouping dulu' : undefined" aria-label="Generate data LUPIS" @click="generateLupis">
                      <div v-if="generatingLupis" class="sp" aria-hidden="true"></div>
                      <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                      {{ selected.has_lupis ? 'LUPIS ✓ (Regenerate)' : 'Generate LUPIS' }}
                    </button>
                  </div>

                  <!-- ── E-Klaim INA-CBG (WS langsung ke aplikasi E-Klaim) ───────── -->
                  <div class="kl-eklaim">
                    <div class="kl-eklaim-head">
                      <span class="kl-eklaim-title">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                        Koneksi E-Klaim INA-CBG
                      </span>
                      <span class="kl-eklaim-dot" :class="eklaim.up ? 'on' : (eklaim.checked ? 'off' : 'unk')">
                        {{ eklaim.checking ? 'cek…' : (eklaim.up ? 'koneksi hidup' : (eklaim.checked ? 'koneksi mati' : '—')) }}
                      </span>
                    </div>

                    <p v-if="!eklaim.up && eklaim.checked" class="kl-eklaim-warn">
                      Koneksi E-Klaim mati. Aktifkan di <strong>Bridging → Konfigurasi</strong> sebelum grouping/mengirim klaim.
                    </p>
                    <p v-else class="kl-eklaim-note">
                      Alur resmi: <strong>Jalankan Grouping</strong> → <strong>Finalisasi</strong> (tab Verifikasi, status jadi <em>Final</em>) → <strong>Kirim Klaim Online</strong> (dorong ke Pusat Data Kemenkes/BPJS, status DC jadi <em>Terkirim</em>). Finalisasi ≠ Terkirim.
                    </p>

                    <div class="kl-eklaim-row sub">
                      <button class="btn btn-ghost btn-sm" :disabled="!!eklaimBusy" @click="runEklaim('status')">
                        <div v-if="eklaimBusy === 'status'" class="sp" aria-hidden="true"></div>
                        Cek Status di E-Klaim
                      </button>
                      <button v-if="['SUBMITTED','SELESAI'].includes(selected.status)" class="btn btn-ghost btn-sm" :disabled="!!eklaimBusy" @click="runEklaim('reedit', { confirmMsg: 'Buka kembali klaim final untuk koreksi? Status klaim akan menjadi DRAFT.', successMsg: 'Klaim dibuka kembali (DRAFT)' })">
                        <div v-if="eklaimBusy === 'reedit'" class="sp" aria-hidden="true"></div>
                        Re-edit (buka klaim final)
                      </button>
                    </div>

                    <!-- K1 — Special CMG (top-up). Muncul bila grouper Stage 1 mengembalikan opsi. -->
                    <div v-if="selected.special_cmg_options?.length" class="kl-eklaim-row sub kl-cmg">
                      <span class="kl-cmg-lbl">Special CMG (top-up):</span>
                      <select v-model="specialCmgPick" class="kl-cmg-sel">
                        <option value="">— pilih —</option>
                        <option v-for="o in selected.special_cmg_options" :key="o.code" :value="o.code">
                          {{ o.code }} — {{ o.description || o.type }}{{ o.tariff ? ' (' + fmtRp(o.tariff) + ')' : '' }}
                        </option>
                      </select>
                      <button class="btn btn-secondary btn-sm" :disabled="!!eklaimBusy || !specialCmgPick" @click="applySpecialCmg">
                        <div v-if="eklaimBusy === 'grouper'" class="sp" aria-hidden="true"></div>
                        Terapkan
                      </button>
                      <span v-if="selected.special_cmg" class="kl-dc-badge ok">Diterapkan: {{ selected.special_cmg }} · top-up {{ fmtRp(selected.tarif_top_up) }}</span>
                    </div>

                    <!-- Pasca-final: Kirim Online (DC Kemenkes/BPJS), sinkron status, cetak berkas -->
                    <div v-if="['SUBMITTED','SELESAI'].includes(selected.status)" class="kl-eklaim-row sub">
                      <button class="btn btn-primary btn-sm" :disabled="!!eklaimBusy || dcBusy" :title="dcTerkirim ? 'Klaim sudah terkirim ke DC Kemenkes' : 'Kirim klaim final ke Pusat Data Kemenkes/BPJS'" @click="kirimOnline">
                        <div v-if="eklaimBusy === 'kirim-online'" class="sp" aria-hidden="true"></div>
                        <svg v-else viewBox="0 0 24 24" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        {{ dcTerkirim ? 'Kirim Ulang Online' : 'Kirim Klaim Online' }}
                      </button>
                      <button class="btn btn-ghost btn-sm" :disabled="!!eklaimBusy" title="Kirim per SEP (send_claim_individual)" @click="runEklaim('kirim-individual', { successMsg: 'Klaim dikirim ke Data Center' })">
                        <div v-if="eklaimBusy === 'kirim-individual'" class="sp" aria-hidden="true"></div>
                        Kirim Individual
                      </button>
                      <button class="btn btn-ghost btn-sm" :disabled="dcBusy" @click="syncDc">
                        <div v-if="dcBusy" class="sp" aria-hidden="true"></div>
                        Sinkron Status DC
                      </button>
                      <button class="btn btn-ghost btn-sm" :disabled="cetakBusy" title="Cetak Berkas Klaim Individual Pasien (replika E-Klaim)" @click="cetakKlaim">
                        <div v-if="cetakBusy" class="sp" aria-hidden="true"></div>
                        <svg v-else viewBox="0 0 24 24" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                        Cetak Berkas Klaim
                      </button>
                      <span v-if="dcStatusLabel" class="kl-dc-badge" :class="dcTerkirim ? 'ok' : 'no'">{{ dcStatusLabel }}</span>
                    </div>

                    <!-- Langkah manual (lanjutan) — untuk troubleshooting bila satu tahap WS perlu diulang sendiri. -->
                    <details v-if="!['SUBMITTED','SELESAI'].includes(selected.status)" class="kl-eklaim-adv">
                      <summary>Langkah manual E-Klaim (lanjutan)</summary>
                      <div class="kl-eklaim-row">
                        <button class="btn btn-secondary btn-sm" :disabled="!!eklaimBusy" @click="runEklaim('new', { successMsg: 'Klaim diregistrasi ke E-Klaim' })">
                          <div v-if="eklaimBusy === 'new'" class="sp" aria-hidden="true"></div>
                          <span v-else class="kl-eklaim-step">1</span> Registrasi (new)
                        </button>
                        <button class="btn btn-secondary btn-sm" :disabled="!!eklaimBusy" @click="runEklaim('set-data', { successMsg: 'Data klaim dikirim ke E-Klaim' })">
                          <div v-if="eklaimBusy === 'set-data'" class="sp" aria-hidden="true"></div>
                          <span v-else class="kl-eklaim-step">2</span> Kirim Data
                        </button>
                        <button class="btn btn-secondary btn-sm" :disabled="!!eklaimBusy" @click="runEklaim('grouper')">
                          <div v-if="eklaimBusy === 'grouper'" class="sp" aria-hidden="true"></div>
                          <span v-else class="kl-eklaim-step">3</span> Grouper
                        </button>
                        <button class="btn btn-primary btn-sm" :disabled="!!eklaimBusy || !selected.inacbgs_kode || !selected.lembar_klaim?.signed" :title="!selected.inacbgs_kode ? 'Jalankan grouper dulu' : (!selected.lembar_klaim?.signed ? 'Resume medis harus ditandatangani dokter dulu' : 'Finalisasi (tidak bisa dibatalkan)')" @click="runEklaim('final', { confirmMsg: 'Finalisasi klaim di E-Klaim? Tindakan ini tidak bisa dibatalkan (hanya bisa dibuka via Re-edit).', successMsg: 'Klaim difinalisasi di E-Klaim' })">
                          <div v-if="eklaimBusy === 'final'" class="sp" aria-hidden="true"></div>
                          <span v-else class="kl-eklaim-step">4</span> Finalisasi
                        </button>
                      </div>
                    </details>
                  </div>
                </div>
              </div>

              <!-- K3 — Verifikasi · Dispute/Pending · Pembayaran -->
              <div class="card kl-k3">
                <div class="card-head">
                  <div class="card-head-title">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                    Verifikasi &amp; Pembayaran
                  </div>
                  <button class="btn btn-ghost btn-sm" @click="refreshVerif" title="Tarik status verifikasi dari E-Klaim (get_claim_status)">Tarik Status</button>
                </div>
                <div class="card-body kl-k3-body">
                  <div class="kl-k3-row">
                    <span class="kl-k3-lbl">Status verifikasi BPJS</span>
                    <span class="kl-k3-val">{{ selected.verif_status_name || '— belum ditarik —' }}</span>
                  </div>

                  <!-- Dispute / Pending (kelola internal) -->
                  <div class="kl-k3-grid">
                    <label>Jenis dispute
                      <select v-model="disputeForm.jenis_dispute">
                        <option value="">—</option>
                        <option value="medis">Medis</option>
                        <option value="koding">Koding</option>
                        <option value="obat">Obat</option>
                        <option value="cob">Koordinasi (COB)</option>
                      </select>
                    </label>
                    <label>Status
                      <select v-model="disputeForm.dispute_state">
                        <option value="">—</option>
                        <option value="PENDING">Pending</option>
                        <option value="DISPUTE">Dispute</option>
                        <option value="SEPAKAT">Sepakat</option>
                      </select>
                    </label>
                    <label>No. BAHV
                      <input v-model="disputeForm.bahv_no" type="text" placeholder="Berita Acara" />
                    </label>
                    <label class="kl-k3-wide">Catatan pending
                      <input v-model="disputeForm.pending_note" type="text" placeholder="Alasan / tindak lanjut" />
                    </label>
                  </div>
                  <div class="kl-k3-cur" v-if="selected.dispute_state">
                    Tersimpan: <b>{{ selected.dispute_state }}</b> ({{ selected.jenis_dispute || '-' }}) {{ selected.bahv_no ? '· BAHV ' + selected.bahv_no : '' }}
                  </div>
                  <button class="btn btn-secondary btn-sm" @click="saveDispute">Simpan Dispute/Pending</button>

                  <!-- Rekonsiliasi pembayaran -->
                  <div class="kl-k3-grid" style="margin-top:.6rem">
                    <label>Nominal disetujui
                      <input v-model="paymentForm.nominal_disetujui" type="number" min="0" placeholder="Rp" />
                    </label>
                    <label>Tgl bayar
                      <input v-model="paymentForm.paid_at" type="date" />
                    </label>
                    <label>Ref. Berita Acara Bayar
                      <input v-model="paymentForm.berita_acara_bayar_ref" type="text" />
                    </label>
                  </div>
                  <div class="kl-k3-cur" v-if="selected.nominal_disetujui != null">
                    Dibayar: <b>{{ fmtRp(selected.nominal_disetujui) }}</b>
                    <span v-if="selected.inacbgs_tarif"> · selisih vs ajuan {{ fmtRp(selected.nominal_disetujui - selected.inacbgs_tarif) }}</span>
                    {{ selected.paid_at ? '· ' + fmtDate(selected.paid_at) : '' }}
                  </div>
                  <button class="btn btn-secondary btn-sm" @click="savePayment">Simpan Pembayaran</button>

                  <!-- K2 — Kirim Kolektif (rentang tanggal) -->
                  <div class="kl-k3-kolektif">
                    <button class="btn btn-ghost btn-sm" @click="kolektif.open = !kolektif.open">Kirim Klaim Kolektif…</button>
                    <div v-if="kolektif.open" class="kl-kolektif-box">
                      <label>Dari <input v-model="kolektif.start_dt" type="date" /></label>
                      <label>Sampai <input v-model="kolektif.stop_dt" type="date" /></label>
                      <label>Jenis
                        <select v-model.number="kolektif.jenis_rawat"><option :value="2">Jalan</option><option :value="1">Inap</option></select>
                      </label>
                      <button class="btn btn-primary btn-sm" :disabled="kolektif.running" @click="kirimKolektif">
                        <div v-if="kolektif.running" class="sp" aria-hidden="true"></div>Kirim
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Dokumen Pendukung -->
              <div class="card kl-dg-dokumen">
                <div class="card-head">
                  <div class="card-head-title">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    Dokumen Pendukung
                  </div>
                  <span class="doc-count-badge">{{ selected.dokumen_pendukung.length }} dokumen</span>
                </div>
                <div class="card-body">
                  <div v-if="selected.dokumen_pendukung.length" class="doc-list">
                    <div v-for="doc in selected.dokumen_pendukung" :key="doc.id" class="doc-row">
                      <div class="doc-icon resume_medis">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                      </div>
                      <div class="doc-info">
                        <div class="doc-name">{{ doc.nama }}</div>
                        <div class="doc-meta">
                          <span class="doc-tipe-badge resume_medis">{{ doc.kode }}</span>
                          <span :class="['doc-status-badge', doc.status === 'FINAL' ? 'ok' : 'pend']">{{ doc.status === 'FINAL' ? 'Final' : doc.status }}</span>
                          <span class="doc-date">{{ fmtDate(doc.tanggal) }}</span>
                        </div>
                      </div>
                      <div class="doc-actions">
                        <button class="doc-btn" title="Lihat dokumen" @click="openDocument(doc)">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                      </div>
                    </div>
                  </div>
                  <div v-else class="doc-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span>Belum ada dokumen pendukung</span>
                  </div>
                  <p v-if="!selected.dokumen_pendukung.length" class="kl-input-hint" style="text-align:center">Dokumen RM (resume, hasil penunjang) akan muncul di sini saat sudah final.</p>
                </div>
              </div>

            </div>
          </div>

          <!-- ── TAB: VERIFIKASI ───────────────────────────────────────────── -->
          <div v-if="activeDetailTab === 'verify'" class="kl-tab-body" role="tabpanel" aria-label="Verifikasi dan aksi klaim">

            <!-- Status Stepper -->
            <div class="card" style="margin-bottom: 0.75rem">
              <div class="card-head">
                <div class="card-head-title">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                  Alur Status Klaim
                </div>
              </div>
              <div class="card-body">
                <div class="kl-stepper" role="list" aria-label="Tahapan status klaim">
                  <div
                    v-for="(step, i) in statusSteps"
                    :key="step"
                    :class="['kl-step', isRejected ? 'reject' : i <= stepIndex(selected.status) ? 'done' : 'pending']"
                    role="listitem"
                    :aria-current="selected.status === step ? 'step' : undefined"
                  >
                    <div class="kl-step-dot" :aria-hidden="true"></div>
                    <div class="kl-step-label">{{ statusMeta[step].label }}</div>
                    <div v-if="i < statusSteps.length - 1" class="kl-step-line" aria-hidden="true"></div>
                  </div>
                  <div v-if="isRejected" class="kl-step reject-final" role="listitem" aria-current="step">
                    <div class="kl-step-dot" aria-hidden="true"></div>
                    <div class="kl-step-label">{{ statusMeta[selected.status]?.label || 'Ditolak' }}</div>
                  </div>
                </div>
                <!-- Sub-langkah E-Klaim: TIDAK mengubah status, tapi prasyarat Verifikasi.
                     Dikerjakan di tab Data Klaim → ditampilkan agar alur terasa lengkap. -->
                <div class="kl-substeps" aria-label="Sub-langkah penyiapan klaim (tab Data Klaim)">
                  <span :class="['kl-substep', selected.inacbgs_kode ? 'ok' : 'todo']">
                    {{ selected.inacbgs_kode ? '✓' : '○' }} Grouping INA-CBGs<template v-if="selected.inacbgs_kode"> · {{ selected.inacbgs_kode }} ({{ fmtRp(selected.inacbgs_tarif) }})</template>
                  </span>
                  <span :class="['kl-substep', selected.has_lupis ? 'ok' : 'todo']">
                    {{ selected.has_lupis ? '✓' : '○' }} LUPIS
                  </span>
                  <span class="kl-substep-note">Dikerjakan di tab Data Klaim — prasyarat sebelum Verifikasi.</span>
                </div>
              </div>
            </div>

            <div class="kl-verify-grid">
              <!-- Checklist -->
              <div class="card">
                <div class="card-head">
                  <div class="card-head-title">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                    Checklist Kelengkapan Berkas
                  </div>
                  <span :class="['kl-cklist-badge', checklistComplete ? 'ok' : 'pend']" :aria-label="checklistComplete ? 'Semua berkas lengkap' : 'Berkas belum lengkap'">
                    {{ checklistComplete ? '✓ Lengkap' : `${Object.values(selected.checklist).filter(Boolean).length}/${checklistDefs.length}` }}
                  </span>
                </div>
                <div class="card-body kl-checklist" role="list" aria-label="Daftar kelengkapan berkas klaim">
                  <div
                    v-for="item in checklistDefs"
                    :key="item.key"
                    class="kl-cklist-item"
                    role="listitem"
                  >
                    <label :for="`ck-${item.key}`" class="kl-cklist-label">
                      <input
                        :id="`ck-${item.key}`"
                        v-model="selected.checklist[item.key]"
                        type="checkbox"
                        class="kl-cklist-check"
                        :disabled="selected.status !== 'REVIEW'"
                        :aria-label="item.label"
                      />
                      <span class="kl-cklist-main">
                        <span :class="['kl-cklist-text', selected.checklist[item.key] ? 'checked' : '']">{{ item.label }}</span>
                        <!-- Ringkasan data nyata (inline) — verifikator melihat apa yang dicentang -->
                        <span
                          v-if="checklistSummary[item.key]"
                          :class="['kl-cklist-data', checklistSummary[item.key].ok ? '' : 'missing']"
                        >
                          <template v-if="checklistSummary[item.key].pill">
                            <span class="kl-code-pill kl-code-dx">{{ checklistSummary[item.key].pill }}</span>
                            <span v-if="selected.diagnosis_utama.label" class="kl-cklist-data-txt">{{ selected.diagnosis_utama.label }}</span>
                          </template>
                          <template v-else-if="checklistSummary[item.key].pills && checklistSummary[item.key].pills.length">
                            <span v-for="code in checklistSummary[item.key].pills" :key="code" class="kl-code-pill kl-code-tn">{{ code }}</span>
                          </template>
                          <template v-else>{{ checklistSummary[item.key].text }}</template>
                        </span>
                      </span>
                    </label>

                    <!-- Action buttons per item -->
                    <div class="kl-cklist-btns">
                      <template v-if="item.key === 'resume_medis'">
                        <button class="cklist-act-btn" title="Lihat Resume Medis" @click="openResumeMedis('view')">
                          <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                        <button class="cklist-act-btn edit" title="Edit Resume Medis" @click="openResumeMedis('edit')">
                          <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                      </template>
                      <template v-else-if="item.key === 'diagnosis_utama' || item.key === 'kode_tindakan'">
                        <button class="cklist-act-btn" title="Lihat Diagnosis & Tindakan" @click="showTindakanModal = true">
                          <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                        <button v-if="codingEditable" class="cklist-act-btn edit" title="Edit Koding (ICD)" @click="openCodingEditor">
                          <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                      </template>
                      <template v-else-if="item.key === 'dokumen_pendukung'">
                        <button class="cklist-act-btn" title="Lihat Dokumen" @click="showDokumenModal = true">
                          <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                      </template>
                    </div>

                    <svg v-if="selected.checklist[item.key]" viewBox="0 0 24 24" class="kl-cklist-ok" aria-label="Terpenuhi">
                      <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <svg v-else viewBox="0 0 24 24" class="kl-cklist-no" aria-label="Belum terpenuhi">
                      <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                    </svg>
                  </div>
                  <p v-if="selected.status === 'REVIEW'" class="kl-cklist-hint" aria-live="polite">
                    Centang semua item untuk mengaktifkan tombol Verifikasi
                  </p>
                </div>
              </div>

              <!-- Actions -->
              <div class="kl-action-col">

                <!-- Catatan koreksi -->
                <div v-if="selected.status === 'REVIEW'" class="card">
                  <div class="card-head">
                    <div class="card-head-title">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                      Catatan Koreksi
                    </div>
                  </div>
                  <div class="card-body">
                    <label for="koreksi-note" class="fl" style="display:block;margin-bottom:4px">Catatan (wajib diisi sebelum kembalikan ke Draft)</label>
                    <textarea
                      id="koreksi-note"
                      v-model="koreksiNote"
                      class="fi kl-textarea"
                      placeholder="Tulis catatan perbaikan untuk diketahui petugas admisi..."
                      :aria-describedby="'koreksi-hint'"
                      rows="3"
                    ></textarea>
                    <p id="koreksi-hint" class="kl-input-hint">Catatan ini akan terekam di audit trail klaim</p>
                  </div>
                </div>

                <!-- Previous notes -->
                <div v-if="selected.verification_notes" class="kl-note-prev" role="note" aria-label="Catatan verifikator sebelumnya">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                  <div>
                    <div class="kl-note-prev-title">Catatan Koreksi Sebelumnya</div>
                    <div class="kl-note-prev-text">{{ selected.verification_notes }}</div>
                  </div>
                </div>

                <!-- Verified info -->
                <div v-if="selected.verified_by" class="kl-verified-info" role="note" aria-label="Informasi verifikasi">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                  <div>
                    <div class="kl-vi-label">Diverifikasi oleh</div>
                    <div class="kl-vi-val">{{ selected.verified_by }}</div>
                    <div class="kl-vi-date">{{ fmtDateTime(selected.verified_at) }}</div>
                  </div>
                </div>

                <!-- Action Buttons -->
                <div class="kl-actions" aria-label="Aksi klaim">

                  <!-- DRAFT -->
                  <template v-if="selected.status === 'DRAFT'">
                    <button class="btn btn-info btn-full btn-lg" aria-label="Mulai proses review klaim ini" @click="mulaiReview">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                      Mulai Review
                    </button>
                    <p class="kl-action-hint">Klaim akan masuk ke tahap review verifikator</p>
                  </template>

                  <!-- REVIEW -->
                  <template v-if="selected.status === 'REVIEW'">
                    <button
                      class="btn btn-success btn-full btn-lg"
                      :disabled="!checklistComplete"
                      :title="!checklistComplete ? 'Lengkapi semua checklist berkas sebelum memverifikasi' : undefined"
                      :aria-disabled="!checklistComplete"
                      :aria-describedby="!checklistComplete ? 'verify-hint' : undefined"
                      aria-label="Verifikasi klaim — semua berkas telah diperiksa"
                      @click="verifikasi"
                    >
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                      Verifikasi Klaim
                    </button>
                    <p v-if="!checklistComplete" id="verify-hint" class="kl-action-warn" aria-live="polite">
                      Centang seluruh checklist berkas terlebih dahulu
                    </p>
                    <div class="kl-sep-divider" aria-hidden="true"></div>
                    <button
                      class="btn btn-danger btn-full"
                      aria-label="Kembalikan klaim ke Draft — wajib isi catatan koreksi"
                      @click="kembalikanDraft"
                    >
                      <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
                      Kembalikan ke Draft
                    </button>
                    <p class="kl-action-hint">Wajib mengisi catatan koreksi di atas</p>
                  </template>

                  <!-- VERIFIED -->
                  <template v-if="selected.status === 'VERIFIED'">
                    <button
                      class="btn btn-submit btn-full btn-lg"
                      aria-label="Finalisasi klaim di E-Klaim — tindakan ini hanya dapat dibuka via Re-edit"
                      @click="openSubmitModal"
                    >
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                      Finalisasi Klaim (E-Klaim)
                    </button>
                    <p class="kl-action-hint">Status klaim menjadi <strong>Final</strong>. Setelah final, gunakan <strong>Kirim Klaim Online</strong> (tab Data) untuk mendorong ke Pusat Data Kemenkes/BPJS.</p>
                  </template>

                  <!-- SUBMITTED -->
                  <template v-if="selected.status === 'SUBMITTED'">
                    <div class="kl-status-info kl-status-info-purple" role="status">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                      <div>
                        <div class="kl-si-title">Klaim Telah Dikirim ke BPJS</div>
                        <div class="kl-si-sub">Menunggu respons dari server BPJS VClaim</div>
                      </div>
                    </div>
                  </template>

                  <!-- SELESAI -->
                  <template v-if="selected.status === 'SELESAI'">
                    <div class="kl-status-info kl-status-info-green" role="status">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                      <div>
                        <div class="kl-si-title">Klaim Disetujui BPJS</div>
                        <div class="kl-si-sub">Klaim telah selesai diproses dan disetujui oleh BPJS</div>
                      </div>
                    </div>
                  </template>

                  <!-- DIKEMBALIKAN (internal) / DITOLAK_BPJS / DITOLAK (lama) -->
                  <template v-if="isRejected">
                    <!-- Dikembalikan verifikator internal (belum dikirim BPJS) -->
                    <div v-if="selected.status === 'DIKEMBALIKAN'" class="kl-status-info" style="background:var(--wb);border:1px solid var(--wbd)" role="alert">
                      <svg viewBox="0 0 24 24" aria-hidden="true" style="stroke:var(--wt)"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                      <div>
                        <div class="kl-si-title" style="color:var(--wt)">Dikembalikan untuk Perbaikan</div>
                        <div class="kl-si-sub">{{ selected.rejection_reason || 'Lihat catatan koreksi di tab Riwayat & Audit' }}</div>
                      </div>
                    </div>
                    <!-- Ditolak / dikembalikan BPJS (setelah submit) -->
                    <div v-else class="kl-status-info kl-status-info-red" role="alert">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                      <div>
                        <div class="kl-si-title">Klaim Ditolak oleh BPJS</div>
                        <div class="kl-si-sub">{{ selected.rejection_reason || 'Lihat alasan penolakan di tab Riwayat & Audit' }}</div>
                      </div>
                    </div>

                    <p v-if="selected.resubmission_count" class="kl-action-hint" style="text-align:center">
                      Sudah diajukan ulang <strong>{{ selected.resubmission_count }}×</strong>
                    </p>

                    <div class="kl-sep-divider" aria-hidden="true"></div>
                    <button class="btn btn-info btn-full btn-lg" :disabled="actionBusy" aria-label="Ajukan ulang klaim setelah diperbaiki" @click="ajukanUlang">
                      <div v-if="actionBusy" class="sp" aria-hidden="true"></div>
                      <svg v-else viewBox="0 0 24 24" aria-hidden="true"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
                      Ajukan Ulang Klaim
                    </button>
                    <p class="kl-action-hint">
                      Klaim kembali ke <strong>Draft</strong>. Perbaiki diagnosis/tindakan (di stasiun Dokter) bila perlu,
                      lalu jalankan ulang <strong>Grouping → LUPIS → Verifikasi</strong> sebelum dikirim ke BPJS.
                    </p>
                  </template>

                  <!-- Kembalikan ke Rekap Kunjungan (lepas dari pipeline klaim, sebelum dikirim BPJS) -->
                  <template v-if="canReturnToRekap">
                    <div class="kl-sep-divider" aria-hidden="true"></div>
                    <button
                      class="btn btn-ghost btn-full"
                      :disabled="returningRekap"
                      aria-label="Kembalikan kunjungan ini ke Rekap Kunjungan BPJS beserta pesan"
                      title="Berkas/koding belum sesuai? Lepaskan dari pipeline klaim dan kembalikan ke Rekap Kunjungan BPJS."
                      @click="kembalikanSelectedKeRekap"
                    >
                      <div v-if="returningRekap" class="sp" aria-hidden="true"></div>
                      <svg v-else viewBox="0 0 24 24" aria-hidden="true"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 00-4-4H4"/></svg>
                      Kembalikan ke Rekap Kunjungan
                    </button>
                    <p class="kl-action-hint">Berkas belum sesuai? Klaim keluar dari daftar ini &amp; muncul lagi di Rekap Kunjungan BPJS untuk diperbaiki.</p>
                  </template>
                </div>
              </div>
            </div>
          </div>

          <!-- ── TAB: AUDIT TRAIL ──────────────────────────────────────────── -->
          <div v-if="activeDetailTab === 'audit'" class="kl-tab-body" role="tabpanel" aria-label="Riwayat dan audit trail klaim">
            <div class="card">
              <div class="card-head">
                <div class="card-head-title">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
                  Riwayat & Audit Trail
                </div>
                <span class="kl-audit-ct">{{ auditFiltered.length }} / {{ selected.audit_trail.length }} entri</span>
              </div>
              <div class="card-body kl-audit-body">
                <!-- #2 Week picker -->
                <div class="kl-week">
                  <button class="kl-week-nav" title="Minggu sebelumnya" @click="auditWeekShift(-1)">‹</button>
                  <span class="kl-week-range">{{ fmtDate(auditWeekStart) }} – {{ fmtDate(auditWeekEnd) }}</span>
                  <button class="kl-week-nav" title="Minggu berikutnya" @click="auditWeekShift(1)">›</button>
                </div>
                <div class="kl-week-days" role="tablist" aria-label="Pilih hari">
                  <button
                    v-for="(d, i) in auditWeekDays"
                    :key="d"
                    :class="['kl-day', auditDay === d ? 'a' : '', d === todayStr() ? 'today' : '']"
                    role="tab"
                    :aria-selected="auditDay === d"
                    @click="auditDay = d"
                  >
                    <span class="kl-day-dow">{{ ['Sen','Sel','Rab','Kam','Jum','Sab','Min'][i] }}</span>
                    <span class="kl-day-num">{{ d.slice(8, 10) }}</span>
                    <span v-if="selected.audit_trail.some(l => (l.at ?? '').slice(0,10) === d)" class="kl-day-dot"></span>
                  </button>
                </div>

                <ol v-if="auditFiltered.length" class="kl-timeline" aria-label="Kronologi perubahan status klaim">
                  <li
                    v-for="(log, i) in [...auditFiltered].reverse()"
                    :key="i"
                    class="kl-tl-item"
                  >
                    <div class="kl-tl-dot" :style="{ background: auditActionMeta[log.action]?.color || 'var(--tu)' }" aria-hidden="true"></div>
                    <div class="kl-tl-content">
                      <div class="kl-tl-action" :style="{ color: auditActionMeta[log.action]?.color || 'var(--tu)' }">
                        {{ auditActionMeta[log.action]?.label || log.action }}
                      </div>
                      <div class="kl-tl-by">oleh <strong>{{ log.by }}</strong></div>
                      <div class="kl-tl-time">{{ fmtDateTime(log.at) }}</div>
                      <div v-if="log.note" class="kl-tl-note">{{ log.note }}</div>
                    </div>
                  </li>
                </ol>
                <div v-else class="kl-audit-empty">Tidak ada aktivitas pada {{ fmtDate(auditDay) }}.</div>
                <p class="kl-audit-footer" aria-label="Catatan privasi">
                  <svg viewBox="0 0 24 24" aria-hidden="true" style="width:12px;height:12px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;display:inline;vertical-align:middle;margin-right:3px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                  Audit trail ini bersifat permanen dan tidak dapat dihapus sesuai ketentuan PMK No. 24 Tahun 2022
                </p>
              </div>
            </div>
          </div>


        </template>
      </section>
    </div>
    </template>

    <!-- ════════════════ TAB: MONITORING VCLAIM ════════════════ -->
    <template v-else-if="pageTab === 'monitoring'">
      <div class="kl-mon">
        <div class="kl-mon-head">
          <h3 class="kl-mon-title">Monitoring Klaim — VClaim BPJS</h3>
          <p class="kl-mon-sub">Data langsung dari server BPJS (butuh integrasi VClaim aktif).</p>
        </div>
        <div class="kl-mon-form">
          <div class="kl-mon-jns" role="tablist" aria-label="Jenis pelayanan">
            <button :class="['kl-jtab', mon.jns === '2' ? 'a' : '']" @click="mon.jns = '2'">Rawat Jalan</button>
            <button :class="['kl-jtab', mon.jns === '1' ? 'a' : '']" @click="mon.jns = '1'">Rawat Inap</button>
          </div>
          <input v-model="mon.tgl" type="date" class="fi" aria-label="Tanggal" />
          <select v-model="mon.status" class="fi" aria-label="Status klaim">
            <option value="1">Proses Verifikasi</option>
            <option value="2">Pending</option>
            <option value="3">Klaim</option>
          </select>
          <button class="btn btn-info btn-sm" :disabled="mon.loading" @click="cekMonitoring">
            <div v-if="mon.loading" class="sp" aria-hidden="true"></div>
            {{ mon.loading ? 'Memuat…' : 'Tampilkan' }}
          </button>
        </div>

        <div v-if="mon.error" class="kl-mon-err" role="alert">{{ mon.error }}</div>
        <template v-else-if="mon.data">
          <div v-if="monRows(mon.data).length" style="overflow-x:auto">
            <div class="kl-mon-count">{{ monRows(mon.data).length }} data — {{ mon.jns === '1' ? 'Rawat Inap' : 'Rawat Jalan' }}</div>
            <table class="tbl kl-mon-tbl">
              <thead><tr><th class="num">No</th><th v-for="col in monCols(monRows(mon.data))" :key="col">{{ col }}</th></tr></thead>
              <tbody>
                <tr v-for="(row, i) in monRows(mon.data)" :key="row.noSep ?? i">
                  <td class="num">{{ i + 1 }}</td>
                  <td v-for="col in monCols(monRows(mon.data))" :key="col" :class="{ mono: /sep|kartu|nik/i.test(col) }">{{ row[col] ?? '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="kl-mon-empty">Tidak ada data monitoring untuk filter ini.</div>
        </template>
        <div v-else class="kl-mon-empty">Pilih jenis pelayanan, tanggal &amp; status, lalu klik <strong>Tampilkan</strong>.</div>
      </div>
    </template>

    <!-- ════════════════ TAB: DIVA & BERKAS (list pasien) ════════════════ -->
    <template v-else-if="pageTab === 'berkas'">
      <div class="kl-berkas">
        <div class="kl-berkas-bar">
          <div class="kl-berkas-filters">
            <label class="kl-bk-date">Dari <input type="date" v-model="berkasFrom" @change="onBerkasFilter" /></label>
            <label class="kl-bk-date">Sampai <input type="date" v-model="berkasTo" @change="onBerkasFilter" /></label>
            <div class="kl-jtabs">
              <button v-for="j in [{v:'',l:'Semua'},{v:'RAJAL',l:'Rawat Jalan'},{v:'RANAP',l:'Rawat Inap'}]" :key="j.v || 'all'"
                :class="['kl-jbtn', berkasJenis === j.v ? 'a' : '']" @click="berkasJenis = j.v; onBerkasFilter()">{{ j.l }}</button>
            </div>
          </div>
          <div class="kl-berkas-actions">
            <span class="kl-berkas-count">{{ berkasTotal }} pasien</span>
            <button class="btn btn-primary btn-sm" :disabled="batchBusy || !berkasRows.length" title="Rakit 1 PDF/pasien se-periode → ZIP (RAJAL/RANAP terpisah)" @click="exportBatch">
              <div v-if="batchBusy" class="sp" aria-hidden="true"></div>
              <span v-else>📦 Export Batch (ZIP)</span>
            </button>
          </div>
        </div>

        <p class="kl-berkas-note">
          Rakit berkas pendukung (SEP + resume + penunjang + kwitansi + lampiran) jadi <strong>1 PDF/pasien</strong> untuk diunggah ke
          <strong>verifikasi digital (Vedika/Vidi)</strong>. <em>File txt untuk DIVA (validasi koding) diekspor dari aplikasi E-Klaim, bukan di sini.</em>
          <button class="kl-link-btn" @click="openExt(DIVA_MONITORING_URL)">Buka Monitoring DIVA ↗</button>
          <br><small class="kl-stat-sub">Tab ini hanya untuk merakit/unduh berkas final. Bila berkas belum sesuai, kembalikan ke Rekap dari tab <strong>Berkas Klaim</strong> (pilih klaim → Verifikasi &amp; Aksi).</small>
        </p>

        <div v-if="batchBusy" class="kl-berkas-progress">
          <div class="sp" aria-hidden="true"></div>
          Merakit {{ batchProgress.done }}/{{ batchProgress.total }} berkas — {{ batchProgress.label }}…
        </div>

        <div class="kl-berkas-table-wrap">
          <table class="kl-berkas-table">
            <thead>
              <tr>
                <th class="c-no">No.</th><th>Pasien</th><th>No. SEP</th><th>Jenis</th><th>Kesiapan Berkas</th><th class="c-act">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="berkasLoading"><td colspan="6" class="kl-berkas-empty">Memuat…</td></tr>
              <tr v-else-if="!berkasRows.length"><td colspan="6" class="kl-berkas-empty">Belum ada kunjungan yang dikirim ke klaim pada periode ini. Kirim dari <strong>Rekap Kunjungan BPJS</strong> dulu.</td></tr>
              <template v-for="(r, i) in berkasRows" :key="r.visit_id">
              <tr>
                <td class="c-no">{{ (berkasPage - 1) * berkasPerPage + i + 1 }}</td>
                <td><div class="kl-bk-nama">{{ r.nama || '—' }}</div><small class="kl-bk-rm">{{ r.no_rm || '—' }}</small></td>
                <td class="mono">{{ r.no_sep || '—' }}</td>
                <td><span class="kl-bk-badge" :class="r.jenis_kode === 'RANAP' ? 'ranap' : 'rajal'">{{ r.jenis || '—' }}</span></td>
                <td>
                  <span class="kl-bk-chip" :class="r.claim_ready ? 'ok' : 'no'">
                    {{ r.claim_ready ? '✓ Siap' : `${r.docs_signed_count}/${r.docs_required_count} dok` }}
                  </span>
                </td>
                <td class="c-act">
                  <button class="btn btn-ghost btn-sm" :title="expandedBerkas === r.visit_id ? 'Sembunyikan isi' : 'Lihat isi berkas pasien ini'" @click="toggleBerkasDetail(r)">
                    <span v-if="manifestLoading === r.visit_id" class="sp" aria-hidden="true"></span>
                    <span v-else>{{ expandedBerkas === r.visit_id ? '▾ Isi' : '▸ Lihat isi' }}</span>
                  </button>
                  <button class="btn btn-secondary btn-sm" :disabled="unduhId === r.visit_id || !r.no_sep" :title="!r.no_sep ? 'Belum ada SEP' : 'Unduh 1 PDF berkas pasien ini'" @click="unduhBerkas(r)">
                    <div v-if="unduhId === r.visit_id" class="sp" aria-hidden="true"></div>
                    <span v-else>Unduh Berkas</span>
                  </button>
                </td>
              </tr>
              <!-- Expand: preview isi tiap berkas pasien -->
              <tr v-if="expandedBerkas === r.visit_id" class="kl-berkas-detail">
                <td colspan="6">
                  <div v-if="manifestLoading === r.visit_id" class="kl-bk-detail-load">Memuat daftar berkas…</div>
                  <ul v-else class="kl-bk-items">
                    <li class="kl-bk-item">
                      <span class="kl-bk-item-name">SEP</span>
                      <button class="btn btn-ghost btn-xs" :disabled="!r.no_sep || previewing === `sep-${r.visit_id}`" @click="previewPdf(`sep-${r.visit_id}`, `/admisi/bpjs/cetak-sep-html/${r.visit_id}?print=0`, { html: true })">{{ previewing === `sep-${r.visit_id}` ? '…' : 'Preview' }}</button>
                    </li>
                    <li v-if="berkasManifest[r.visit_id]?.claim_id && berkasManifest[r.visit_id]?.inacbgs_kode" class="kl-bk-item">
                      <span class="kl-bk-item-name">Lembar INA-CBG <em class="kl-bk-tag ok">{{ berkasManifest[r.visit_id].inacbgs_kode }}</em></span>
                      <button class="btn btn-ghost btn-xs" :disabled="previewing === `cbg-${r.visit_id}`" @click="previewPdf(`cbg-${r.visit_id}`, `/klaim/${berkasManifest[r.visit_id].claim_id}/cetak`)">{{ previewing === `cbg-${r.visit_id}` ? '…' : 'Preview' }}</button>
                    </li>
                    <li v-for="d in berkasManifest[r.visit_id]?.documents || []" :key="d.id" class="kl-bk-item">
                      <span class="kl-bk-item-name">{{ d.type_label }} <em class="kl-bk-tag" :class="d.signed ? 'ok' : 'no'">{{ d.signed ? 'TTD' : (d.status_label || 'draft') }}</em></span>
                      <button class="btn btn-ghost btn-xs" :disabled="!d.signed || previewing === `doc-${d.id}`" :title="d.signed ? '' : 'Belum final/TTD'" @click="previewPdf(`doc-${d.id}`, `/klaim/dokumen/${d.id}/pdf`)">{{ previewing === `doc-${d.id}` ? '…' : 'Preview' }}</button>
                    </li>
                    <li v-for="(p, pi) in berkasManifest[r.visit_id]?.penunjang || []" :key="'p'+pi" class="kl-bk-item">
                      <span class="kl-bk-item-name">{{ p.test_name || 'Penunjang' }}</span>
                      <button class="btn btn-ghost btn-xs" :disabled="!p.attachment_url" :title="p.attachment_url ? '' : 'Tanpa berkas'" @click="previewPdf(`pj-${r.visit_id}-${pi}`, p.attachment_url, { direct: true })">Preview</button>
                    </li>
                    <li class="kl-bk-item">
                      <span class="kl-bk-item-name">Kwitansi</span>
                      <button class="btn btn-ghost btn-xs" :disabled="previewing === `kw-${r.visit_id}`" @click="previewPdf(`kw-${r.visit_id}`, `/klaim/kwitansi/${r.visit_id}/pdf`)">{{ previewing === `kw-${r.visit_id}` ? '…' : 'Preview' }}</button>
                    </li>
                    <li v-for="(a, ai) in berkasManifest[r.visit_id]?.manual || []" :key="'m'+ai" class="kl-bk-item">
                      <span class="kl-bk-item-name">{{ a.title || a.file_name || 'Lampiran' }}</span>
                      <span v-if="a.dc_upload_status" class="kl-bk-chip ok" title="Sudah diunggah ke Data Center BPJS">✓ DC</span>
                      <button class="btn btn-ghost btn-xs" :disabled="!(a.file_url || a.attachment_url)" @click="previewPdf(`mn-${r.visit_id}-${ai}`, a.file_url || a.attachment_url, { direct: true })">Preview</button>
                      <button v-if="a.id && !a.dc_upload_status" class="btn btn-ghost btn-xs" :disabled="uploadingDc === a.id" title="Unggah berkas ke Data Center BPJS (file_upload)" @click="uploadBerkasDc(a, r.visit_id)">
                        {{ uploadingDc === a.id ? '…' : 'Unggah BPJS' }}
                      </button>
                    </li>
                  </ul>
                </td>
              </tr>
              </template>
            </tbody>
          </table>
        </div>
        <Pager v-if="berkasLast > 1" v-model:page="berkasPage" :last-page="berkasLast" :total="berkasTotal" @change="onBerkasPage" />
      </div>
    </template>

    <!-- ── SUBMIT CONFIRMATION MODAL ─────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="showSubmitModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-title" aria-describedby="modal-desc">
        <div class="modal">
          <div class="modal-head">
            <div class="modal-icon-wrap" aria-hidden="true">
              <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </div>
            <h2 id="modal-title" class="modal-title">Konfirmasi Finalisasi Klaim</h2>
          </div>
          <div class="modal-body">
            <p id="modal-desc" class="modal-desc">
              Anda akan <strong>memfinalisasi</strong> klaim berikut di aplikasi E-Klaim INA-CBG:
            </p>
            <div v-if="selected" class="modal-info-box" aria-label="Ringkasan klaim yang akan dikirim">
              <div class="modal-info-row"><span>Pasien</span><strong>{{ selected.nama_pasien }}</strong></div>
              <div class="modal-info-row"><span>No. SEP</span><strong class="mono">{{ selected.no_sep }}</strong></div>
              <div class="modal-info-row"><span>Diagnosis</span><strong>{{ selected.diagnosis_utama.kode }} — {{ selected.diagnosis_utama.label }}</strong></div>
              <div class="modal-info-row"><span>INA-CBGs Tarif</span><strong style="color: var(--ga)">{{ fmtRp(selected.inacbgs_tarif) }}</strong></div>
            </div>
            <div class="modal-warning" role="note" aria-label="Peringatan penting">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
              <span>Status klaim menjadi <strong>Final</strong> (hanya bisa dibuka via Re-edit). Klaim <strong>belum</strong> terkirim ke Kemenkes — lakukan <strong>Kirim Klaim Online</strong> setelah ini.</span>
            </div>
          </div>
          <div class="modal-foot">
            <button class="btn btn-secondary" aria-label="Batalkan pengiriman dan kembali" @click="cancelSubmit" :disabled="submitting">
              Batal
            </button>
            <button class="btn btn-submit" aria-label="Konfirmasi finalisasi klaim di E-Klaim" @click="confirmSubmit" :disabled="submitting" :aria-busy="submitting">
              <div v-if="submitting" class="sp" aria-hidden="true"></div>
              <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
              {{ submitting ? 'Memfinalisasi...' : 'Ya, Finalisasi' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ── MODAL: DIAGNOSIS UTAMA ─────────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="showDiagnosisModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="dx-modal-title">
        <div class="modal modal-md">
          <div class="modal-head">
            <div class="modal-icon-wrap" style="background:var(--eb)" aria-hidden="true">
              <svg viewBox="0 0 24 24" style="stroke:var(--et)"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            </div>
            <h2 id="dx-modal-title" class="modal-title">Edit Koding Klaim (ICD-10 / ICD-9)</h2>
            <button class="modal-close-btn" @click="showDiagnosisModal = false" aria-label="Tutup">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <div class="modal-body" v-if="selected">
            <p class="kl-cod-note">Cari &amp; pilih kode dari master ICD. Menyimpan akan <strong>mereset grouping</strong> — jalankan ulang Grouping setelahnya.</p>

            <!-- Diagnosis Utama -->
            <div class="kl-cod-label">Diagnosis Utama (ICD-10) <span class="kl-cod-req">wajib</span></div>
            <div class="kl-cod-row">
              <span v-if="editUtama.kode" class="kl-code-pill kl-code-dx">{{ editUtama.kode }}</span>
              <span class="kl-dx-name">{{ editUtama.label || 'Belum dipilih' }}</span>
              <button class="btn btn-ghost btn-sm" @click="startIcdSearch('utama', 'icd10')">{{ editUtama.kode ? 'Ganti' : 'Cari' }}</button>
            </div>
            <div v-if="icdSearchTarget === 'utama'" class="kl-cod-search">
              <input v-model="icdQuery" class="fi" placeholder="Ketik nama atau kode ICD-10…" />
              <div class="kl-cod-results">
                <div v-if="icdSearching" class="kl-cod-hint">Mencari…</div>
                <button v-for="r in icdResults" :key="r.kode" type="button" class="kl-cod-item" @click="pickIcd(r)">
                  <span class="kl-code-pill kl-code-dx">{{ r.kode }}</span><span class="kl-cod-itlabel">{{ r.label }}</span>
                </button>
                <div v-if="!icdSearching && icdQuery.trim().length >= 2 && !icdResults.length" class="kl-cod-hint">Tak ada hasil.</div>
              </div>
            </div>

            <!-- Diagnosis Sekunder -->
            <div class="kl-cod-label" style="margin-top:14px">Diagnosis Sekunder (ICD-10)</div>
            <div v-for="(ds, i) in editSekunder" :key="'s' + i">
              <div class="kl-cod-row">
                <span v-if="ds.kode" class="kl-code-pill kl-code-ds">{{ ds.kode }}</span>
                <span class="kl-dx-name">{{ ds.label || 'Belum dipilih' }}</span>
                <button class="btn btn-ghost btn-sm" @click="startIcdSearch('sekunder:' + i, 'icd10')">{{ ds.kode ? 'Ganti' : 'Cari' }}</button>
                <button class="im-del-btn" @click="removeSekunderRow(i)" title="Hapus"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
              </div>
              <div v-if="icdSearchTarget === 'sekunder:' + i" class="kl-cod-search">
                <input v-model="icdQuery" class="fi" placeholder="Ketik nama atau kode ICD-10…" />
                <div class="kl-cod-results">
                  <div v-if="icdSearching" class="kl-cod-hint">Mencari…</div>
                  <button v-for="r in icdResults" :key="r.kode" type="button" class="kl-cod-item" @click="pickIcd(r)">
                    <span class="kl-code-pill kl-code-ds">{{ r.kode }}</span><span class="kl-cod-itlabel">{{ r.label }}</span>
                  </button>
                  <div v-if="!icdSearching && icdQuery.trim().length >= 2 && !icdResults.length" class="kl-cod-hint">Tak ada hasil.</div>
                </div>
              </div>
            </div>
            <button class="btn btn-secondary btn-sm im-add-btn" @click="addSekunderRow">
              <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Tambah Diagnosis Sekunder
            </button>

            <!-- Tindakan ICD-9 -->
            <div class="kl-cod-label" style="margin-top:14px">Tindakan (ICD-9-CM)</div>
            <div v-for="(t, i) in editTindakanList" :key="'t' + i">
              <div class="kl-cod-row">
                <span v-if="t.kode" class="kl-code-pill kl-code-tn">{{ t.kode }}</span>
                <span class="kl-dx-name">{{ t.label || 'Belum dipilih' }}</span>
                <button class="btn btn-ghost btn-sm" @click="startIcdSearch('tindakan:' + i, 'icd9')">{{ t.kode ? 'Ganti' : 'Cari' }}</button>
                <button class="im-del-btn" @click="removeTindakanRow(i)" title="Hapus"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
              </div>
              <div v-if="icdSearchTarget === 'tindakan:' + i" class="kl-cod-search">
                <input v-model="icdQuery" class="fi" placeholder="Ketik nama atau kode ICD-9-CM…" />
                <div class="kl-cod-results">
                  <div v-if="icdSearching" class="kl-cod-hint">Mencari…</div>
                  <button v-for="r in icdResults" :key="r.kode" type="button" class="kl-cod-item" @click="pickIcd(r)">
                    <span class="kl-code-pill kl-code-tn">{{ r.kode }}</span><span class="kl-cod-itlabel">{{ r.label }}</span>
                  </button>
                  <div v-if="!icdSearching && icdQuery.trim().length >= 2 && !icdResults.length" class="kl-cod-hint">Tak ada hasil.</div>
                </div>
              </div>
            </div>
            <button class="btn btn-secondary btn-sm im-add-btn" @click="addTindakanRow">
              <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Tambah Tindakan
            </button>
          </div>
          <div class="modal-foot">
            <button class="btn btn-secondary" @click="showDiagnosisModal = false">Tutup</button>
            <button class="btn btn-success" :disabled="savingCoding || !editUtama.kode" @click="saveCoding">
              <div v-if="savingCoding" class="sp" aria-hidden="true"></div>
              <svg v-else viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Simpan Koding
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ── MODAL: KODE TINDAKAN ───────────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="showTindakanModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="tn-modal-title">
        <div class="modal modal-md">
          <div class="modal-head">
            <div class="modal-icon-wrap" style="background:var(--pb)" aria-hidden="true">
              <svg viewBox="0 0 24 24" style="stroke:var(--pt)"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
            </div>
            <h2 id="tn-modal-title" class="modal-title">Diagnosis &amp; Tindakan</h2>
            <button class="modal-close-btn" @click="showTindakanModal = false" aria-label="Tutup">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <div class="modal-body" v-if="selected">
            <div class="im-section-label">Diagnosis Utama (ICD-10)</div>
            <div v-if="selected.diagnosis_utama?.kode && selected.diagnosis_utama.kode !== '—'" class="kl-dx-row" style="margin-bottom:6px">
              <span class="kl-code-pill kl-code-dx">{{ selected.diagnosis_utama.kode }}</span>
              <span class="kl-dx-name">{{ selected.diagnosis_utama.label }}</span>
            </div>
            <div v-else class="im-empty">Diagnosis utama belum diisi.</div>

            <template v-if="selected.diagnosis_sekunder && selected.diagnosis_sekunder.length">
              <div class="im-section-label" style="margin-top:12px">Diagnosis Sekunder (ICD-10)</div>
              <div v-for="d in selected.diagnosis_sekunder" :key="d.kode" class="kl-dx-row" style="margin-bottom:6px">
                <span class="kl-code-pill kl-code-ds">{{ d.kode }}</span>
                <span class="kl-dx-name">{{ d.label }}</span>
              </div>
            </template>

            <div class="im-section-label" style="margin-top:12px">Tindakan (ICD-9-CM)</div>
            <div v-for="t in selected.tindakan" :key="t.kode" class="kl-dx-row" style="margin-bottom:6px">
              <span class="kl-code-pill kl-code-tn">{{ t.kode }}</span>
              <span class="kl-dx-name">{{ t.label }}</span>
            </div>
            <div v-if="!selected.tindakan.length" class="im-empty">Belum ada kode tindakan.</div>
          </div>
          <div class="modal-foot">
            <button class="btn btn-secondary" @click="showTindakanModal = false">Tutup</button>
            <button v-if="codingEditable" class="btn btn-info" @click="showTindakanModal = false; openCodingEditor()">
              <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              Edit Koding
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ── MODAL: DOKUMEN PENDUKUNG ───────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="showDokumenModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="dok-modal-title">
        <div class="modal modal-md">
          <div class="modal-head">
            <div class="modal-icon-wrap" style="background:var(--gl)" aria-hidden="true">
              <svg viewBox="0 0 24 24" style="stroke:var(--ga)"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <h2 id="dok-modal-title" class="modal-title">Dokumen Pendukung Klaim</h2>
            <button class="modal-close-btn" @click="showDokumenModal = false" aria-label="Tutup">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <div class="modal-body" v-if="selected">
            <div v-if="selected.dokumen_pendukung.length" class="im-doc-list">
              <div v-for="doc in selected.dokumen_pendukung" :key="doc.id" class="im-doc-row">
                <div class="doc-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <div class="im-doc-info">
                  <div class="doc-name">{{ doc.nama }}</div>
                  <div class="doc-meta">
                    <span v-if="doc.kode" class="doc-tipe-badge">{{ doc.kode }}</span>
                    <span class="doc-date">{{ fmtDate(doc.tanggal) }}</span>
                    <span v-if="doc.status" class="doc-size">{{ doc.status }}</span>
                  </div>
                </div>
                <div class="doc-actions">
                  <button class="doc-btn" title="Buka dokumen" :disabled="openingDoc" @click="openDocument(doc)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
              </div>
            </div>
            <div v-else class="im-empty">Belum ada dokumen pendukung pada kunjungan ini.</div>
            <p class="kl-input-hint" style="margin-top:10px">Lampiran tambahan (PDF/gambar: resume RJ, hasil penunjang) diunggah dari tab <strong>Data Klaim</strong> → bagian Lampiran Berkas.</p>
          </div>
          <div class="modal-foot">
            <button class="btn btn-secondary" @click="showDokumenModal = false">Tutup</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ── TOAST NOTIFICATIONS ────────────────────────────────────────────── -->
    <div class="toast-wrap" role="status" aria-live="polite" aria-atomic="false" aria-label="Notifikasi sistem">
      <div v-for="t in toasts" :key="t.id" :class="['toast', `toast-${t.type}`]">{{ t.msg }}</div>
    </div>

  </div>
</template>

<style scoped>
/* ── Layout ─────────────────────────────────────────────────────────────────── */
.klaim { padding: 0; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; }

.kl-stat-caption { font-size: 11.5px; color: var(--tu); margin: 0 2px 6px; }
.kl-stat-caption strong { color: var(--td); }
.kl-stat-sub { color: var(--tm); font-weight: 400; }
.kl-stat-capped { color: var(--wt); font-weight: 600; }
.stat-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.6rem; margin-bottom: 0.85rem; }
@media (max-width: 1100px) { .stat-row { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 720px)  { .stat-row { grid-template-columns: repeat(2, 1fr); } }
.stat-card { background: var(--bc); border: 1px solid var(--gb); border-radius: 11px; padding: 0.75rem; display: flex; align-items: center; gap: 9px; }
.stat-icon { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-icon svg { width: 17px; height: 17px; fill: none; stroke-width: 2; stroke-linecap: round; }
.stat-val { font-size: 18px; font-weight: 700; color: var(--td); line-height: 1; }
.stat-lbl { font-size: 10px; color: var(--tu); margin-top: 2px; }

.kl-grid { display: grid; grid-template-columns: 380px 1fr; gap: 0.85rem; align-items: start; }

/* ── Left Panel ─────────────────────────────────────────────────────────────── */
.kl-panel { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; display: flex; flex-direction: column; overflow: hidden; }
.kl-ph { padding: 0.65rem 0.85rem; border-bottom: 1px solid var(--gb); display: flex; align-items: center; justify-content: space-between; }
.kl-ph-title { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: var(--td); }
.kl-ph-title svg { width: 13px; height: 13px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.live-pill { font-size: 9px; font-weight: 700; padding: 2px 7px; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); border-radius: 20px; }

.kl-search { padding: 0.45rem 0.6rem; border-bottom: 1px solid var(--gb); }
.kl-search-wrap { position: relative; }
.kl-search-icon { position: absolute; left: 9px; top: 50%; transform: translateY(-50%); width: 13px; height: 13px; fill: none; stroke: var(--th); stroke-width: 2; stroke-linecap: round; pointer-events: none; }
.fi { width: 100%; height: 30px; font-size: 11.5px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 10px; background: var(--bs); font-family: 'Inter', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.fi:focus { border-color: var(--ga); background: #fff; box-shadow: 0 0 0 3px rgba(31, 125, 74, 0.09); }
.fi:focus-visible { outline: 2px solid var(--ga); outline-offset: 1px; }
.kl-fi-search { padding-left: 30px; }

.kl-date-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem; padding: 0.45rem 0.6rem; border-bottom: 1px solid var(--gb); }
.kl-date-field { display: flex; flex-direction: column; gap: 3px; }

.fl { font-size: 9.5px; font-weight: 600; color: var(--tu); letter-spacing: 0.05em; text-transform: uppercase; }

.kl-stabs { display: flex; flex-direction: column; border-bottom: 1px solid var(--gb); gap: 0; padding: 0.35rem 0.5rem; gap: 2px; }
.kl-stab { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 5px 8px; font-size: 11px; font-weight: 500; color: var(--tu); cursor: pointer; border: 1.5px solid transparent; background: transparent; border-radius: 6px; font-family: 'Inter', sans-serif; text-align: left; transition: all 0.12s; }
.kl-stab:hover { background: var(--bs); color: var(--td); }
.kl-stab.a { background: var(--gl); color: var(--ga); border-color: var(--gb); font-weight: 600; }
.kl-stab:focus-visible { outline: 2px solid var(--ga); outline-offset: 1px; }
.kl-stab-ct { font-size: 9.5px; background: var(--gb); color: var(--tu); border-radius: 20px; padding: 1px 6px; font-weight: 700; }
.kl-stab.a .kl-stab-ct { background: rgba(31,125,74,0.12); color: var(--ga); }

.kl-list { flex: 1; overflow-y: auto; padding: 0.4rem 0.5rem; max-height: calc(100vh - 380px); display: flex; flex-direction: column; gap: 3px; }
.kl-item { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; padding: 9px 10px; background: var(--bc); border: 1.5px solid var(--gb); border-radius: 8px; cursor: pointer; transition: all 0.12s; }
.kl-item:hover { border-color: var(--lm); background: var(--bs); }
.kl-item.ac { border-color: var(--ga); background: var(--gl); }
.kl-item:focus-visible { outline: 2px solid var(--ga); outline-offset: 1px; border-radius: 8px; }
.kl-item-left { flex: 1; min-width: 0; }
.kl-item-name { font-size: 12px; font-weight: 600; color: var(--td); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kl-item-sep { font-size: 9.5px; color: var(--tu); font-variant-numeric: tabular-nums; margin-top: 1px; }
.kl-item-date { font-size: 9.5px; color: var(--th); margin-top: 1px; }
.kl-item-diag { font-size: 9.5px; color: var(--tu); margin-top: 2px; font-style: italic; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; }
.kl-need-dx { color: #b45309; font-style: normal; font-weight: 600; }
.kl-item-right { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex-shrink: 0; }
.kl-badge { font-size: 9px; font-weight: 700; padding: 2px 7px; border-radius: 20px; border: 1px solid; white-space: nowrap; }
.kl-item-tarif { font-size: 10px; font-weight: 600; color: var(--ga); font-variant-numeric: tabular-nums; }
.kl-item { position: relative; }
.kl-item.bulk { background: var(--ib); }
.kl-item-check { position: absolute; top: 6px; right: 6px; width: 15px; height: 15px; cursor: pointer; accent-color: var(--ga); z-index: 2; }
.kl-age { font-size: 8.5px; font-weight: 700; padding: 0 4px; border-radius: 3px; margin-left: 4px; }
.kl-age.age-warn { background: var(--wb); color: var(--wt); }
.kl-age.age-danger { background: var(--eb); color: var(--et); }
.kl-bulk-bar { display: flex; align-items: center; gap: 8px; padding: 6px 10px; margin: 0 0.5rem 4px; background: var(--ib); border: 1px solid var(--ibd); border-radius: 8px; }
.kl-bulk-ct { font-size: 11px; font-weight: 700; color: var(--it); }
.kl-bulk-warn { font-size: 10px; color: var(--th); flex: 1; }
.kl-bulk-clear { margin-left: auto; width: 22px; height: 22px; border: none; background: transparent; color: var(--th); cursor: pointer; font-size: 13px; border-radius: 5px; }
.kl-bulk-clear:hover { background: var(--bs); color: var(--et); }
.kl-empty-list { text-align: center; padding: 2rem 1rem; font-size: 11px; color: var(--th); }

/* ── Right Panel ────────────────────────────────────────────────────────────── */
.kl-detail { display: flex; flex-direction: column; gap: 0.7rem; }
.empty-state { padding: 5rem 2rem; background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; display: flex; flex-direction: column; align-items: center; gap: 0.85rem; color: var(--th); text-align: center; }
.empty-state svg { width: 58px; height: 58px; fill: none; stroke: var(--gb); stroke-width: 1.5; stroke-linecap: round; }
.empty-state p { font-size: 13px; max-width: 360px; line-height: 1.55; color: var(--tu); }

/* Patient Banner */
.kl-banner { background: linear-gradient(135deg, var(--gm), var(--gd)); color: #fff; padding: 0.9rem 1.1rem; border-radius: 12px; display: flex; align-items: center; gap: 1rem; }
.kl-av { width: 48px; height: 48px; border-radius: 50%; background: rgba(56,189,248,0.2); border: 2px solid rgba(56,189,248,0.3); color: var(--lm); font-size: 20px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-family: 'Space Grotesk', serif; }
.kl-banner-info { flex: 1; min-width: 0; }
.kl-banner-name { font-family: 'Space Grotesk', serif; font-size: 19px; line-height: 1.1; }
.kl-banner-meta { font-size: 11px; color: rgba(255,255,255,0.6); margin-top: 3px; }
.kl-banner-tags { display: flex; gap: 4px; margin-top: 6px; flex-wrap: wrap; }
.kl-btag { font-size: 9px; font-weight: 700; padding: 2px 7px; border-radius: 20px; }
.kl-btag-bpjs { background: rgba(147,197,253,0.2); color: #93c5fd; border: 1px solid rgba(147,197,253,0.25); }
.kl-btag-sep { background: rgba(56,189,248,0.2); color: var(--lm); border: 1px solid rgba(56,189,248,0.25); }
.kl-banner-tarif { text-align: right; flex-shrink: 0; }
.kl-banner-tarif-v { font-size: 20px; font-weight: 700; color: var(--lm); font-variant-numeric: tabular-nums; line-height: 1; }
.kl-banner-tarif-l { font-size: 9.5px; color: rgba(255,255,255,0.45); text-transform: uppercase; letter-spacing: 0.08em; margin-top: 3px; }
.kl-banner-status { margin-top: 6px; display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 9px; border-radius: 20px; }

.kl-bpjs-resp { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border: 1px solid; border-radius: 8px; font-size: 12px; }

/* Inner Tabs */
.kl-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--gb); }
.kl-tab { display: inline-flex; align-items: center; gap: 6px; padding: 0.6rem 1rem; font-size: 12px; font-weight: 500; color: var(--tu); background: none; border: none; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: 'Inter', sans-serif; transition: color 0.12s; }
.kl-tab:hover { color: var(--td); }
.kl-tab.a { color: var(--ga); border-bottom-color: var(--ga); font-weight: 600; }
.kl-tab:focus-visible { outline: 2px solid var(--ga); outline-offset: 2px; }
.kl-tab svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.kl-tab-ct { font-size: 9.5px; background: var(--gb); color: var(--tu); border-radius: 20px; padding: 1px 6px; font-weight: 700; }
.kl-tab.a .kl-tab-ct { background: rgba(31,125,74,0.12); color: var(--ga); }
.kl-tab-ct.ok { background: var(--sb); color: var(--st); }
.kl-tab-ct.no { background: var(--wb); color: var(--wt); }

/* Tab DIVA & Berkas */
.kl-diva { display: flex; flex-direction: column; gap: 14px; }
.kl-diva-sec { display: flex; flex-direction: column; gap: 10px; }
.kl-diva-sec + .kl-diva-sec { border-top: 1px solid var(--gb); padding-top: 14px; }
.kl-diva-banner { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; font-size: 12.5px; font-weight: 600; border: 1px solid; }
.kl-diva-banner.ok { background: color-mix(in srgb, var(--sb) 40%, transparent); border-color: var(--sbd); color: var(--st); }
.kl-diva-banner.no { background: color-mix(in srgb, var(--wb) 40%, transparent); border-color: var(--wbd); color: var(--wt); }
.kl-diva-badge { padding: 2px 9px; border-radius: 999px; background: var(--bc); font-weight: 700; font-size: 11px; white-space: nowrap; }
.kl-diva-note { font-size: 11.5px; color: var(--tu); line-height: 1.5; margin: 0; padding: 10px 12px; background: var(--gl); border-radius: 9px; }
.kl-diva-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.kl-diva-list li { display: flex; gap: 9px; align-items: flex-start; padding: 8px 10px; border: 1px solid var(--gb); border-radius: 9px; }
.kl-diva-mark { width: 18px; text-align: center; font-weight: 800; flex-shrink: 0; }
.kl-diva-list li.ok .kl-diva-mark { color: var(--st); }
.kl-diva-list li.miss .kl-diva-mark { color: var(--et); }
.kl-diva-list li.warn .kl-diva-mark { color: var(--wt); }
.kl-diva-lbl { font-size: 12.5px; font-weight: 600; color: var(--td); display: flex; flex-direction: column; gap: 1px; }
.kl-diva-lbl small { font-weight: 400; color: var(--tu); font-size: 10.5px; }
.kl-diva-req { display: inline-block; margin-left: 6px; font-size: 9.5px; font-weight: 700; color: var(--et); background: var(--eb); padding: 0 6px; border-radius: 999px; vertical-align: middle; }
.kl-diva-cbg { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.kl-diva-cbg > div { display: flex; flex-direction: column; gap: 2px; padding: 8px 10px; border: 1px solid var(--gb); border-radius: 9px; }
.kl-diva-cbg span { font-size: 10px; text-transform: uppercase; letter-spacing: 0.03em; color: var(--tu); }
.kl-diva-cbg strong { font-size: 13px; color: var(--td); }
.kl-diva-cbg .neg strong { color: var(--et); }
.kl-diva-cbg .pos strong { color: var(--st); }
.kl-diva-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.kl-diva-hint { font-size: 11px; color: var(--tu); margin: 0; }

.kl-tab-body { display: flex; flex-direction: column; gap: 0.7rem; }

/* Card */
.card { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; }
.card-head { padding: 0.7rem 1.1rem; border-bottom: 1px solid var(--gb); display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.card-head-title { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--td); }
.card-head-title svg { width: 14px; height: 14px; fill: none; stroke: var(--ga); stroke-width: 2; stroke-linecap: round; }
.card-body { padding: 1rem; }

/* Data Klaim Tab — 2-column grid with explicit placement */
.kl-data-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.7rem; align-items: start; }
.kl-dg-identitas { grid-column: 1 / -1; grid-row: 1; }
.kl-dg-diagnosis  { grid-column: 1;      grid-row: 2; }
.kl-dg-dokumen    { grid-column: 2;      grid-row: 2; }
.kl-dg-inacbgs   { grid-column: 1 / -1; grid-row: 3; }
.kl-dg-lampiran  { grid-column: 1 / -1; grid-row: 4; }

/* Uploader lampiran klaim */
.att-uploader { display: flex; gap: 6px; align-items: center; margin-bottom: 6px; }
.att-cat-select { padding: 6px 8px; border: 1px solid var(--gb); border-radius: 7px; font-size: 12px; color: #000; background: #fff; }
.att-cat-select:focus { outline: none; border-color: #1763d4; }
.doc-icon.penunjang { background: #ecfeff; color: #0e7490; }
.doc-tipe-badge.penunjang { background: #ecfeff; color: #0e7490; }
.doc-btn-del:hover { color: #991b1b; border-color: #fca5a5; }
.priv-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 9.5px; font-weight: 600; color: var(--it); background: var(--ib); border: 1px solid var(--ibd); padding: 2px 8px; border-radius: 20px; }

.kl-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.7rem 1rem; }
.kl-info-item {}
.kl-info-label { font-size: 9.5px; font-weight: 600; color: var(--tu); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 3px; }
.kl-info-val { font-size: 12.5px; color: var(--td); font-weight: 500; }
.kl-info-val.mono { font-variant-numeric: tabular-nums; font-family: 'JetBrains Mono', monospace, 'Inter', sans-serif; }

.kl-dx-section { margin-bottom: 0.75rem; }
.kl-dx-empty { font-size: 12px; color: var(--th); font-style: italic; padding: 4px 0; }

/* Editor koding klaim (modal search-driven) */
.kl-cod-note { font-size: 11.5px; color: var(--tu); background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; padding: 8px 10px; margin: 0 0 12px; }
.kl-cod-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--tu); margin-bottom: 6px; }
.kl-cod-req { color: var(--et); margin-left: 4px; }
.kl-cod-row { display: flex; align-items: center; gap: 8px; padding: 6px 0; }
.kl-cod-row .kl-dx-name { flex: 1; min-width: 0; font-size: 12.5px; color: var(--td); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kl-cod-search { margin: 2px 0 8px; padding: 8px; background: var(--bs); border: 1px solid var(--ga); border-radius: 8px; }
.kl-cod-search .fi { width: 100%; }
.kl-cod-results { margin-top: 6px; max-height: 220px; overflow-y: auto; display: flex; flex-direction: column; gap: 3px; }
.kl-cod-item { display: flex; align-items: center; gap: 8px; width: 100%; text-align: left; padding: 6px 8px; border: 1px solid var(--gb); border-radius: 7px; background: var(--bc); cursor: pointer; font-size: 12px; color: var(--td); }
.kl-cod-item:hover { background: var(--gl); border-color: var(--ga); }
.kl-cod-itlabel { flex: 1; min-width: 0; }
.kl-cod-hint { font-size: 11.5px; color: var(--tu); font-style: italic; padding: 4px; }
.kl-dx-section:last-child { margin-bottom: 0; }
.kl-dx-label { font-size: 9.5px; font-weight: 600; color: var(--tu); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 5px; }
.kl-dx-row { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 4px; }
.kl-code-pill { font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 5px; flex-shrink: 0; font-variant-numeric: tabular-nums; }
.kl-code-dx { background: var(--eb); color: var(--et); border: 1px solid var(--ebd); }
.kl-code-ds { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }
.kl-code-tn { background: var(--pb); color: var(--pt); border: 1px solid var(--pbd); }
.kl-dx-name { font-size: 12px; color: var(--td); line-height: 1.4; }
.kl-dx-freetext {
  font-size: 12px; color: var(--it); line-height: 1.45;
  background: var(--ib); border: 1px solid var(--ibd); border-radius: 7px;
  padding: 7px 10px; white-space: pre-wrap; word-break: break-word;
}

/* Grouper edit inline */
.kl-cbgs-code-row { display: flex; align-items: center; gap: 6px; }
.kl-grouper-edit-btn { margin-top: 2px; }
.kl-grouper-edit-row { display: flex; align-items: center; gap: 4px; margin-top: 4px; }
.kl-grouper-fi { height: 28px; font-size: 11.5px; font-variant-numeric: tabular-nums; width: 110px; flex-shrink: 0; }
.kl-grouper-save,
.kl-grouper-cancel { width: 26px; height: 26px; border-radius: 5px; border: 1.5px solid; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; }
.kl-grouper-save { background: var(--sb); border-color: var(--sbd); color: var(--st); }
.kl-grouper-save:hover { background: var(--ga); border-color: var(--ga); color: #fff; }
.kl-grouper-cancel { background: var(--eb); border-color: var(--ebd); color: var(--et); }
.kl-grouper-cancel:hover { background: var(--et); border-color: var(--et); color: #fff; }
.kl-grouper-save svg, .kl-grouper-cancel svg { width: 12px; height: 12px; }

/* Kasir modal */
.kasir-patient-row { display: flex; align-items: center; gap: 10px; }
.kasir-av { width: 36px; height: 36px; border-radius: 50%; background: var(--gl); border: 1.5px solid var(--ga); color: var(--ga); font-size: 16px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-family: 'Space Grotesk', serif; }
.kasir-pname { font-size: 13px; font-weight: 600; color: var(--td); }
.kasir-pmeta { font-size: 10.5px; color: var(--tu); margin-top: 1px; font-variant-numeric: tabular-nums; }
.kasir-compare-row { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 1rem; }
.kasir-compare-item { display: flex; flex-direction: column; gap: 3px; }
.kasir-compare-item:last-child { text-align: right; }
.kasir-big-num { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
.kasir-green { color: var(--ga); }
.kasir-amber { color: var(--wt); }
.kasir-code-sub { font-size: 10px; color: var(--tu); font-variant-numeric: tabular-nums; }
.kasir-vs { font-size: 11px; font-weight: 700; color: var(--th); text-align: center; }
.kasir-tbl { margin-top: 6px; }
.kasir-note { display: flex; align-items: flex-start; gap: 8px; padding: 9px 12px; border-radius: 8px; font-size: 11.5px; margin-top: 0.75rem; border: 1px solid; }
.kasir-note svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; margin-top: 1px; }
.kasir-note.over { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.kasir-note.under { background: var(--sb); color: var(--st); border-color: var(--sbd); }

.kl-cbgs-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; margin-bottom: 0.85rem; padding-bottom: 0.85rem; border-bottom: 1px solid var(--gb); }
.kl-cbgs-item {}
.kl-cbgs-code { font-size: 18px; font-weight: 700; color: var(--pt); font-variant-numeric: tabular-nums; }
.kl-cbgs-tarif { font-size: 18px; font-weight: 700; color: var(--ga); font-variant-numeric: tabular-nums; }

.tbl { width: 100%; border-collapse: collapse; }
.tbl th { font-size: 10px; font-weight: 600; color: var(--tu); letter-spacing: 0.06em; text-transform: uppercase; padding: 8px 13px; border-bottom: 1px solid var(--gb); text-align: left; }
.tbl td { padding: 8px 13px; border-bottom: 1px solid rgba(0,0,0,0.03); font-size: 12px; color: var(--td); }
.tbl tr:last-child td { border-bottom: none; }
.tbl .num { text-align: right; font-variant-numeric: tabular-nums; }
.tbl .strong { font-weight: 600; }
.tbl tfoot td { border-top: 2px solid var(--gb); background: var(--bi); font-size: 13px; }
.tbl-total-row td { padding-top: 10px; padding-bottom: 10px; }
.tbl-kasir-row td { color: var(--wt); font-weight: 600; font-size: 12px; border-top: 1px dashed var(--wbd); }
.tbl-selisih-row td { font-size: 11px; font-style: italic; }
.tbl-selisih-row.over td { color: var(--et); }
.tbl-selisih-row.under td { color: var(--st); }

/* Date filter active banner */
.kl-date-active-banner { display: flex; align-items: center; gap: 6px; padding: 6px 10px; background: var(--ib); border: 1px solid var(--ibd); border-radius: 7px; font-size: 10.5px; color: var(--it); margin: 0 0.85rem 0.4rem; }
.kl-date-active-banner svg { width: 12px; height: 12px; flex-shrink: 0; }
.kl-date-active-banner span { flex: 1; }
.kl-date-clear { font-size: 10px; font-weight: 700; background: none; border: none; color: var(--it); cursor: pointer; padding: 1px 4px; border-radius: 4px; }
.kl-date-clear:hover { background: rgba(29,78,216,.1); }

/* Dokumen Pendukung */
.doc-count-badge { font-size: 9.5px; font-weight: 700; padding: 2px 8px; border-radius: 10px; background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.doc-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 0.75rem; }
.doc-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; }
.doc-icon { width: 32px; height: 32px; border-radius: 7px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.doc-icon svg { width: 16px; height: 16px; }
.doc-icon.resume_medis { background: var(--ib); stroke: var(--it); }
.doc-icon.penunjang { background: var(--pb); stroke: var(--pt); }
.doc-info { flex: 1; min-width: 0; }
.doc-name { font-size: 12px; font-weight: 500; color: var(--td); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.doc-meta { display: flex; align-items: center; gap: 6px; margin-top: 2px; flex-wrap: wrap; }
.doc-tipe-badge { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 20px; }
.doc-tipe-badge.resume_medis { background: var(--ib); color: var(--it); }
.doc-tipe-badge.penunjang { background: var(--pb); color: var(--pt); }
.doc-size { font-size: 10px; color: var(--tu); }
.doc-date { font-size: 10px; color: var(--tu); }
.doc-actions { display: flex; gap: 4px; flex-shrink: 0; }
.doc-btn { width: 28px; height: 28px; border-radius: 6px; background: var(--bc); border: 1px solid var(--gb); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--tu); transition: all .12s; }
.doc-btn:hover { border-color: var(--ga); color: var(--ga); background: var(--gl); }
.doc-btn svg { width: 13px; height: 13px; }
.doc-empty { display: flex; align-items: center; gap: 8px; padding: 14px; color: var(--th); font-size: 12px; justify-content: center; margin-bottom: 0.6rem; }
.doc-empty svg { width: 24px; height: 24px; }
.doc-upload-btn { width: 100%; justify-content: center; }

.kl-lupis-btn-row { display: flex; gap: 6px; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--gb); }

/* Statcard koneksi E-Klaim (clickable) */
.ek-stat { cursor: pointer; text-align: left; font: inherit; width: 100%; transition: box-shadow 0.15s, transform 0.1s; }
.ek-stat:hover:not(:disabled) { box-shadow: 0 2px 10px rgba(15,23,42,0.08); }
.ek-stat:active:not(:disabled) { transform: translateY(1px); }
.ek-stat:disabled { cursor: progress; }
.ek-val { font-weight: 700; }
.ek-spin { animation: ek-rot 0.9s linear infinite; }
@keyframes ek-rot { to { transform: rotate(360deg); } }

/* Blok aksi E-Klaim di panel detail */
.kl-eklaim { margin-top: 0.7rem; padding-top: 0.7rem; border-top: 1px dashed var(--gb); }
.kl-eklaim-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem; }
.kl-eklaim-title { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--td); }
.kl-eklaim-title svg { width: 15px; height: 15px; fill: none; stroke: var(--ga); stroke-width: 2; }
.kl-eklaim-dot { font-size: 10.5px; font-weight: 600; padding: 2px 8px; border-radius: 12px; white-space: nowrap; }
.kl-eklaim-dot.on  { background: #dcfce7; color: #166534; }
.kl-eklaim-dot.off { background: #fee2e2; color: #991b1b; }
.kl-eklaim-dot.unk { background: #f1f5f9; color: #64748b; }
.kl-eklaim-warn { font-size: 11.5px; color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; border-radius: 7px; padding: 6px 9px; margin: 0 0 0.5rem; }
.kl-eklaim-note { font-size: 11.5px; color: var(--tm); margin: 0 0 0.5rem; line-height: 1.45; }
.kl-eklaim-row { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.kl-eklaim-row.sub { margin-top: 6px; }
.kl-dc-badge { font-size: 10.5px; font-weight: 600; padding: 2px 8px; border-radius: 12px; white-space: nowrap; }
.kl-dc-badge.ok { background: #dcfce7; color: #166534; }
.kl-dc-badge.no { background: #fef3c7; color: #92400e; }
.kl-eklaim-adv { margin-top: 8px; }
.kl-eklaim-adv > summary { font-size: 11px; color: var(--tu); cursor: pointer; user-select: none; padding: 2px 0; }
.kl-eklaim-adv > summary:hover { color: var(--ga); }
.kl-eklaim-adv .kl-eklaim-row { margin-top: 7px; }
.kl-eklaim-step { display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; border-radius: 50%; background: var(--ga); color: #fff; font-size: 10px; font-weight: 700; margin-right: 2px; }
.btn-ghost { background: transparent; color: var(--ga); border-color: transparent; }
.btn-ghost:hover:not(:disabled) { background: var(--gl); }
.btn-ghost:disabled { opacity: 0.5; cursor: not-allowed; }

/* Verifikasi Tab */
.kl-verify-grid { display: grid; grid-template-columns: 1fr 340px; gap: 0.7rem; }
.kl-action-col { display: flex; flex-direction: column; gap: 0.65rem; }

.kl-stepper { display: flex; align-items: flex-start; gap: 0; padding: 0.5rem 0; overflow-x: auto; }
.kl-step { display: flex; flex-direction: column; align-items: center; gap: 5px; flex: 1; position: relative; min-width: 70px; }
.kl-step-dot { width: 20px; height: 20px; border-radius: 50%; border: 2px solid var(--gb); background: var(--bs); flex-shrink: 0; position: relative; z-index: 1; }
.kl-step.done .kl-step-dot { background: var(--ga); border-color: var(--ga); }
.kl-step.reject .kl-step-dot { background: var(--th); border-color: var(--gb); }
.kl-step.reject-final .kl-step-dot { background: var(--et); border-color: var(--ebd); }
.kl-step-label { font-size: 9.5px; font-weight: 600; color: var(--tu); text-align: center; line-height: 1.3; }
.kl-step.done .kl-step-label { color: var(--ga); }
.kl-step.reject-final .kl-step-label { color: var(--et); }
.kl-step-line { position: absolute; top: 10px; left: 60%; width: 80%; height: 2px; background: var(--gb); z-index: 0; }
.kl-step.done .kl-step-line { background: var(--ga); }

.kl-cklist-badge { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px; }
.kl-cklist-badge.ok { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.kl-cklist-badge.pend { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }

.kl-checklist { display: flex; flex-direction: column; gap: 3px; }
.kl-cklist-item { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; padding: 9px 0; border-bottom: 1px solid rgba(0,0,0,0.04); }
.kl-cklist-item:last-of-type { border-bottom: none; }
.kl-cklist-label { display: flex; align-items: flex-start; gap: 9px; cursor: pointer; flex: 1; }
.kl-cklist-check { width: 15px; height: 15px; flex-shrink: 0; accent-color: var(--ga); cursor: pointer; margin-top: 2px; }
.kl-cklist-check:focus-visible { outline: 2px solid var(--ga); outline-offset: 2px; }
.kl-cklist-check:disabled { cursor: not-allowed; opacity: 0.55; }
.kl-cklist-main { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.kl-cklist-text { font-size: 12px; color: var(--tm); line-height: 1.3; font-weight: 600; }
.kl-cklist-text.checked { color: var(--td); }
.kl-cklist-data { display: flex; flex-wrap: wrap; align-items: center; gap: 5px; font-size: 11.5px; color: var(--td); line-height: 1.3; }
.kl-cklist-data.missing { color: var(--et); font-style: italic; }
.kl-cklist-data-txt { color: var(--tm); }
.kl-cklist-ok, .kl-cklist-no { margin-top: 2px; }
.kl-cklist-ok { width: 14px; height: 14px; fill: none; stroke: var(--st); stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.kl-cklist-no { width: 14px; height: 14px; fill: none; stroke: var(--ebd); stroke-width: 2; stroke-linecap: round; flex-shrink: 0; }
.kl-cklist-hint { font-size: 10.5px; color: var(--tu); margin-top: 6px; font-style: italic; }

.kl-textarea { height: auto; resize: vertical; padding: 8px 10px; line-height: 1.5; }
.kl-input-hint { font-size: 10px; color: var(--th); margin-top: 4px; }

.kl-note-prev { display: flex; gap: 9px; align-items: flex-start; padding: 10px 12px; background: var(--wb); border: 1px solid var(--wbd); border-radius: 9px; }
.kl-note-prev svg { width: 14px; height: 14px; fill: none; stroke: var(--wt); stroke-width: 2; stroke-linecap: round; flex-shrink: 0; margin-top: 2px; }
.kl-note-prev-title { font-size: 10px; font-weight: 700; color: var(--wt); margin-bottom: 3px; }
.kl-note-prev-text { font-size: 11.5px; color: var(--wt); line-height: 1.4; }

.kl-verified-info { display: flex; gap: 9px; align-items: flex-start; padding: 10px 12px; background: var(--sb); border: 1px solid var(--sbd); border-radius: 9px; }
.kl-verified-info svg { width: 15px; height: 15px; fill: none; stroke: var(--st); stroke-width: 2; stroke-linecap: round; flex-shrink: 0; margin-top: 2px; }
.kl-vi-label { font-size: 9.5px; font-weight: 600; color: var(--st); text-transform: uppercase; letter-spacing: 0.05em; }
.kl-vi-val { font-size: 12.5px; font-weight: 600; color: var(--st); }
.kl-vi-date { font-size: 10.5px; color: var(--st); opacity: 0.75; }

.kl-actions { display: flex; flex-direction: column; gap: 6px; }
.kl-action-hint { font-size: 10.5px; color: var(--tu); font-style: italic; padding: 0 2px; }
/* Sub-langkah Grouping/LUPIS di bawah stepper (Q4) */
.kl-substeps { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--gb); }
.kl-substep { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--gb); }
.kl-substep.ok { background: #f0fdf4; color: var(--ld); border-color: var(--sbd); }
.kl-substep.todo { background: var(--bs); color: var(--tu); }
.kl-substep-note { font-size: 10.5px; color: var(--tm); font-style: italic; margin-left: auto; }
.kl-action-warn { font-size: 10.5px; color: var(--wt); padding: 0 2px; font-weight: 500; }
.kl-sep-divider { height: 1px; background: var(--gb); margin: 4px 0; }

.kl-status-info { display: flex; align-items: flex-start; gap: 10px; padding: 12px; border-radius: 10px; border: 1px solid; }
.kl-status-info svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; margin-top: 1px; }
.kl-status-info-purple { background: var(--pb); color: var(--pt); border-color: var(--pbd); }
.kl-status-info-green { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.kl-status-info-red { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.kl-si-title { font-size: 13px; font-weight: 700; margin-bottom: 3px; }
.kl-si-sub { font-size: 11.5px; line-height: 1.4; opacity: 0.85; }

/* Audit Trail Tab */
.kl-audit-body { padding-bottom: 0.5rem; }
.kl-audit-ct { font-size: 10.5px; color: var(--tu); background: var(--bs); border: 1px solid var(--gb); padding: 2px 8px; border-radius: 20px; }
.kl-timeline { list-style: none; position: relative; padding-left: 1.75rem; }
.kl-timeline::before { content: ''; position: absolute; left: 7px; top: 0; bottom: 0; width: 2px; background: var(--gb); }
.kl-tl-item { position: relative; padding-bottom: 1.25rem; }
.kl-tl-item:last-child { padding-bottom: 0; }
.kl-tl-dot { position: absolute; left: -1.75rem; top: 2px; width: 14px; height: 14px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 2px var(--gb); z-index: 1; }
.kl-tl-content {}
.kl-tl-action { font-size: 12.5px; font-weight: 700; margin-bottom: 2px; }
.kl-tl-by { font-size: 11px; color: var(--tm); }
.kl-tl-time { font-size: 10.5px; color: var(--th); margin-top: 2px; }
.kl-tl-note { font-size: 11px; color: var(--tu); margin-top: 5px; padding: 6px 9px; background: var(--bs); border: 1px solid var(--gb); border-radius: 6px; line-height: 1.4; }
.kl-audit-footer { font-size: 10.5px; color: var(--th); margin-top: 1.25rem; padding-top: 0.75rem; border-top: 1px solid var(--gb); line-height: 1.4; }

/* Buttons */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 0 14px; height: 36px; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 12.5px; font-weight: 500; cursor: pointer; border: 1.5px solid transparent; transition: all 0.14s; }
.btn-sm { height: 28px; padding: 0 10px; font-size: 11px; }
.btn-lg { height: 42px; padding: 0 18px; font-size: 13.5px; font-weight: 600; }
.btn-full { width: 100%; }
.btn-primary { background: var(--gd); color: #fff; border-color: var(--gd); }
.btn-primary:hover { background: var(--gm); }
.btn-success { background: var(--ga); color: #fff; border-color: var(--ga); }
.btn-success:hover:not(:disabled) { background: var(--gm); }
.btn-success:disabled { background: var(--gb); color: var(--th); cursor: not-allowed; border-color: var(--gb); }
.btn-info { background: var(--it); color: #fff; border-color: var(--it); }
.btn-info:hover { background: #1e40af; }
.btn-danger { background: transparent; color: var(--et); border-color: var(--ebd); }
.btn-danger:hover { background: var(--eb); }
.btn-submit { background: var(--pt); color: #fff; border-color: var(--pt); }
.btn-submit:hover:not(:disabled) { background: #6b21a8; }
.btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-secondary { background: transparent; color: var(--tm); border-color: var(--gb); }
.btn-secondary:hover { border-color: var(--ga); color: var(--td); background: var(--gl); }
.btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.btn:focus-visible { outline: 2px solid var(--ga); outline-offset: 2px; }
.sp { width: 14px; height: 14px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff; animation: spin 0.7s linear infinite; }

/* Modal */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 9000; backdrop-filter: blur(3px); }
.modal { background: var(--bc); border-radius: 16px; width: 440px; max-width: 95vw; border: 1px solid var(--gb); box-shadow: 0 20px 60px rgba(0,0,0,0.18); overflow: hidden; }
.modal-head { padding: 1.4rem 1.5rem 1rem; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--gb); }
.modal-icon-wrap { width: 36px; height: 36px; border-radius: 10px; background: var(--pb); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.modal-icon-wrap svg { width: 17px; height: 17px; fill: none; stroke: var(--pt); stroke-width: 2; stroke-linecap: round; }
.modal-title { font-family: 'Space Grotesk', serif; font-size: 18px; color: var(--td); }
.modal-body { padding: 1.25rem 1.5rem; display: flex; flex-direction: column; gap: 0.9rem; }
.modal-desc { font-size: 13px; color: var(--tm); line-height: 1.5; }
.modal-info-box { background: var(--bs); border: 1px solid var(--gb); border-radius: 10px; padding: 0.85rem 1rem; display: flex; flex-direction: column; gap: 6px; }
.modal-info-row { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; font-size: 12px; color: var(--tm); }
.modal-info-row strong { color: var(--td); text-align: right; }
.mono { font-variant-numeric: tabular-nums; }
.modal-warning { display: flex; gap: 9px; align-items: flex-start; padding: 10px 12px; background: var(--wb); border: 1px solid var(--wbd); border-radius: 9px; font-size: 12px; color: var(--wt); }
.modal-warning svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; flex-shrink: 0; margin-top: 1px; }
.modal-foot { padding: 1rem 1.5rem; border-top: 1px solid var(--gb); display: flex; gap: 8px; justify-content: flex-end; background: var(--bi); }

/* Checklist action buttons */
.kl-cklist-btns { display: flex; gap: 3px; align-items: center; margin-left: auto; margin-right: 6px; flex-shrink: 0; }
.cklist-act-btn { width: 26px; height: 26px; border-radius: 6px; background: var(--bs); border: 1px solid var(--gb); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--tu); transition: all .12s; }
.cklist-act-btn svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.cklist-act-btn:hover { border-color: var(--ga); color: var(--ga); background: var(--gl); }
.cklist-act-btn.edit:hover { border-color: var(--it); color: var(--it); background: var(--ib); }
.cklist-act-btn.tambah:hover { border-color: var(--st); color: var(--st); background: var(--sb); }
.cklist-act-btn:focus-visible { outline: 2px solid var(--ga); outline-offset: 1px; }

/* Item Modal shared */
.modal-md { width: 520px; }
.modal-close-btn { margin-left: auto; width: 28px; height: 28px; border-radius: 6px; border: 1px solid var(--gb); background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--tu); }
.modal-close-btn:hover { background: var(--bs); color: var(--td); }
.modal-close-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.im-section-label { font-size: 9.5px; font-weight: 700; color: var(--tu); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 8px; }
.im-empty { text-align: center; padding: 1.5rem; font-size: 12px; color: var(--th); }
.im-empty-sm { font-size: 11.5px; color: var(--th); font-style: italic; }
.im-divider { height: 1px; background: var(--gb); margin: 0.85rem 0; }
.im-doc-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 0.5rem; }
.im-doc-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; }
.im-doc-info { flex: 1; min-width: 0; }
.im-del-doc-btn:hover { border-color: var(--et); color: var(--et); background: var(--eb); }
.im-edit-row { display: grid; grid-template-columns: 110px 1fr 26px; gap: 6px; margin-bottom: 6px; align-items: center; }
.im-fi-code { font-variant-numeric: tabular-nums; }
.im-fi-label {}
.im-fi-select { height: 30px; font-size: 11.5px; border: 1.5px solid var(--gb); border-radius: 7px; padding: 0 8px; background: var(--bs); font-family: 'Inter', sans-serif; outline: none; color: var(--td); box-sizing: border-box; }
.im-fi-select:focus { border-color: var(--ga); }
.im-del-btn { width: 26px; height: 26px; border-radius: 5px; border: 1px solid var(--ebd); background: var(--eb); cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--et); }
.im-del-btn svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.im-add-btn { margin-top: 4px; }
/* ── Edit Koding ICD ── */
.kl-icd-pick { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
.kl-icd-current { flex: 1; min-width: 0; display: flex; align-items: center; gap: 6px; padding: 6px 9px; border: 1.5px solid var(--gb); border-radius: 7px; background: var(--bs); font-size: 12px; }
.kl-icd-current.empty { color: var(--th); font-style: italic; border-style: dashed; }
.kl-icd-current .kl-dx-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kl-icd-search { margin-top: 8px; padding: 8px; background: var(--bs); border: 1px solid var(--gb); border-radius: 8px; }
.kl-icd-results { margin-top: 6px; max-height: 200px; overflow-y: auto; display: flex; flex-direction: column; gap: 3px; }
.kl-icd-result { display: flex; align-items: center; gap: 7px; padding: 6px 9px; border: 1px solid var(--gb); border-radius: 6px; background: var(--bc); cursor: pointer; text-align: left; font-size: 12px; color: var(--td); }
.kl-icd-result:hover { background: var(--ib); border-color: var(--ibd); }
.kl-icd-result .kl-dx-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kl-icd-hint { padding: 8px; text-align: center; font-size: 11.5px; color: var(--th); }
.doc-status-badge { font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 4px; }
.doc-status-badge.ok { background: var(--sb); color: var(--st); }
.doc-status-badge.pend { background: var(--wb); color: var(--wt); }
.doc-status-badge.warn { background: #fde7c8; color: #92400e; }
.kl-lembar-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 8px; }
.kl-input-hint.warn { color: #b45309; }
.im-upload-area { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 1.25rem; background: var(--bs); border: 2px dashed var(--gb); border-radius: 10px; color: var(--th); font-size: 12px; text-align: center; }
.im-upload-area svg { width: 32px; height: 32px; }
.im-tambah-row { display: grid; grid-template-columns: 140px 1fr auto; gap: 6px; align-items: center; }

/* Toast */
.toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 9999; display: flex; flex-direction: column; gap: 6px; pointer-events: none; }
.toast { padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 14px rgba(0,0,0,0.1); min-width: 240px; }
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }

/* ── Page tabs (Klaim | Monitoring) ── */
.kl-pagetabs { display: flex; gap: 4px; margin-bottom: 0.75rem; border-bottom: 1px solid var(--gb); }
.kl-ptab { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border: none; background: transparent; color: var(--tu); font-size: 12.5px; font-weight: 600; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.kl-ptab svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.kl-ptab.a { color: var(--ga); border-bottom-color: var(--ga); }
.kl-ptab:hover:not(.a) { color: var(--td); }

/* ── RJ/RI toggle ── */
.kl-jenis { display: flex; gap: 3px; padding: 0.4rem 0.6rem 0; }
.kl-jbtn { flex: 1; padding: 5px 8px; border: 1px solid var(--gb); border-radius: 6px; background: var(--bs); color: var(--tu); font-size: 10.5px; font-weight: 600; cursor: pointer; }
.kl-jbtn.a { background: var(--ga); color: #fff !important; border-color: var(--ga); }

/* ── RI tag + assignment in list ── */
.kl-ri-tag { font-size: 8px; font-weight: 700; padding: 1px 4px; border-radius: 3px; background: var(--bs); color: var(--tu); border: 1px solid var(--gb); margin-left: 4px; vertical-align: middle; }
.kl-assigned { display: inline-flex; align-items: center; gap: 3px; font-size: 9px; font-weight: 600; color: var(--it); background: var(--ib); border: 1px solid var(--ibd); padding: 1px 6px; border-radius: 10px; margin-top: 3px; }
.kl-assigned svg { width: 9px; height: 9px; fill: none; stroke: currentColor; stroke-width: 2; }

/* ── Assignment bar (detail) ── */
.kl-assign-bar { display: flex; align-items: center; gap: 8px; padding: 7px 11px; margin-bottom: 0.6rem; background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; font-size: 11.5px; color: var(--td); }
.kl-assign-bar.other { background: var(--wb); border-color: var(--wbd); color: var(--wt); }
.kl-assign-bar svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; flex-shrink: 0; }
.kl-assign-txt { flex: 1; }
.kl-assign-bar .btn { margin-left: auto; }

/* ── Audit week picker ── */
.kl-week { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 8px; }
.kl-week-nav { width: 26px; height: 26px; border: 1px solid var(--gb); border-radius: 6px; background: var(--bc); color: var(--td); font-size: 16px; cursor: pointer; line-height: 1; }
.kl-week-nav:hover { background: var(--bs); border-color: var(--ga); }
.kl-week-range { font-size: 11.5px; font-weight: 600; color: var(--td); min-width: 150px; text-align: center; }
.kl-week-days { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; margin-bottom: 12px; }
.kl-day { position: relative; display: flex; flex-direction: column; align-items: center; gap: 1px; padding: 5px 2px; border: 1px solid var(--gb); border-radius: 7px; background: var(--bc); cursor: pointer; }
.kl-day.a { background: var(--ga); border-color: var(--ga); }
.kl-day.a .kl-day-dow, .kl-day.a .kl-day-num { color: #fff !important; }
.kl-day.today:not(.a) { border-color: var(--ga); }
.kl-day-dow { font-size: 8.5px; color: var(--tu); }
.kl-day-num { font-size: 12px; font-weight: 700; color: var(--td); }
.kl-day-dot { position: absolute; bottom: 2px; width: 4px; height: 4px; border-radius: 50%; background: var(--ga); }
.kl-day.a .kl-day-dot { background: #fff; }
.kl-audit-empty { text-align: center; padding: 1.5rem; font-size: 12px; color: var(--th); }

/* ── Monitoring tab ── */
.kl-mon { background: var(--bc); border: 1px solid var(--gb); border-radius: 12px; padding: 1rem 1.2rem; }
.kl-mon-title { font-size: 14px; font-weight: 700; color: var(--td); margin: 0; }
.kl-mon-sub { font-size: 11px; color: var(--tu); margin: 2px 0 0; }
.kl-mon-form { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0.9rem 0; }
.kl-mon-jns { display: inline-flex; border: 1px solid var(--gb); border-radius: 7px; overflow: hidden; }
.kl-jtab { padding: 6px 12px; border: none; background: var(--bs); color: var(--tu); font-size: 11px; font-weight: 600; cursor: pointer; }
.kl-jtab.a { background: var(--ga); color: #fff !important; }
.kl-mon-form .fi { width: auto; min-width: 130px; }
.kl-mon-err { padding: 0.7rem 1rem; background: var(--eb); border: 1px solid var(--ebd); border-radius: 8px; color: var(--et); font-size: 12px; }
.kl-mon-empty { padding: 2rem; text-align: center; color: var(--th); font-size: 12.5px; }
.kl-mon-count { font-size: 11px; color: var(--tu); margin-bottom: 6px; }
.kl-mon-tbl { width: 100%; font-size: 11.5px; }
.kl-mon-tbl th { background: var(--bs); text-align: left; padding: 6px 8px; font-weight: 700; color: var(--tu); white-space: nowrap; }
.kl-mon-tbl td { padding: 5px 8px; border-bottom: 1px solid var(--gb); }

/* Tab utama DIVA & Berkas (list pasien) */
.kl-berkas { padding: 4px 2px; }
.kl-berkas-bar { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 10px; }
.kl-berkas-filters { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.kl-bk-date { font-size: 11px; color: var(--tu); display: flex; flex-direction: column; gap: 2px; }
.kl-bk-date input { height: 34px; padding: 0 8px; border: 1px solid var(--gb); border-radius: 8px; background: var(--bc); color: var(--td); font-size: 12.5px; }
.kl-berkas-actions { display: flex; align-items: center; gap: 10px; margin-left: auto; }
.kl-berkas-count { font-size: 12px; color: var(--tu); }
.kl-berkas-note { font-size: 11.5px; color: var(--tu); line-height: 1.5; background: var(--bs); border: 1px solid var(--gb); border-radius: 9px; padding: 9px 12px; margin: 0 0 10px; }
/* Preview isi berkas per pasien (expand baris tab DIVA & Berkas) */
.btn-xs { padding: 2px 9px; font-size: 11px; line-height: 1.6; }
.kl-berkas-detail > td { background: var(--bs); padding: 8px 14px 12px; }
.kl-bk-detail-load { font-size: 12px; color: var(--tm); padding: 6px 2px; }
.kl-bk-items { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; }
.kl-bk-item { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 5px 10px; background: var(--cardbg, #fff); border: 1px solid var(--gb); border-radius: 7px; }
.kl-bk-item-name { font-size: 12.5px; color: var(--td); display: flex; align-items: center; gap: 7px; }
.kl-bk-tag { font-style: normal; font-size: 10px; font-weight: 600; padding: 1px 7px; border-radius: 999px; background: var(--bs); color: var(--tu); border: 1px solid var(--gb); }
.kl-bk-tag.ok { background: #f0fdf4; color: var(--ld); border-color: var(--sbd); }
.kl-bk-tag.no { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.kl-link-btn { background: none; border: 0; color: var(--ga); cursor: pointer; font-size: 11.5px; font-weight: 600; padding: 0; margin-left: 4px; }
.kl-berkas-progress { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--it); background: var(--ib); border: 1px solid var(--ibd); border-radius: 8px; padding: 8px 12px; margin-bottom: 10px; }
.kl-berkas-table-wrap { border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; background: var(--bc); }
.kl-berkas-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.kl-berkas-table th, .kl-berkas-table td { padding: 9px 12px; text-align: left; border-bottom: 1px solid var(--gb); vertical-align: middle; }
.kl-berkas-table th { background: var(--gl); color: var(--tu); font-weight: 600; font-size: 11.5px; }
.kl-berkas-table tr:last-child td { border-bottom: 0; }
.kl-berkas-table .c-no { width: 44px; color: var(--tu); text-align: center; }
.kl-berkas-table .c-act { width: 140px; text-align: right; }
.kl-bk-nama { font-weight: 600; color: var(--td); }
.kl-bk-rm { color: var(--tu); font-size: 11px; }
.kl-bk-badge { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.kl-bk-badge.rajal { background: #ffedd5; color: #c2410c; }
.kl-bk-badge.ranap { background: #dbeafe; color: #1d4ed8; }
.kl-bk-chip { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.kl-bk-chip.ok { background: var(--sb); color: var(--st); }
.kl-bk-chip.no { background: var(--wb); color: var(--wt); }
.kl-berkas-empty { text-align: center; color: var(--tu); padding: 26px 12px !important; }

/* ── K1 Special CMG + K3 verifikasi/dispute/pembayaran ─────────────────────── */
.kl-cmg { align-items: center; flex-wrap: wrap; gap: 8px; }
.kl-cmg-lbl { font-size: 12px; font-weight: 700; color: var(--tu); }
.kl-cmg-sel { padding: 5px 8px; border: 1px solid var(--bd); border-radius: 7px; font-size: 12px; max-width: 320px; }
.kl-k3-body { display: flex; flex-direction: column; gap: .55rem; }
.kl-k3-row { display: flex; justify-content: space-between; gap: 10px; font-size: 13px; }
.kl-k3-lbl { color: var(--tu); } .kl-k3-val { font-weight: 700; }
.kl-k3-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.kl-k3-grid label { display: flex; flex-direction: column; gap: 3px; font-size: 11.5px; font-weight: 600; color: var(--tu); }
.kl-k3-grid input, .kl-k3-grid select { padding: 6px 8px; border: 1px solid var(--bd); border-radius: 7px; font-size: 12.5px; font-weight: 400; color: var(--tx); }
.kl-k3-wide { grid-column: 1 / -1; }
.kl-k3-cur { font-size: 12px; color: var(--tx); background: var(--bs); border-radius: 7px; padding: 6px 9px; }
.kl-k3-kolektif { border-top: 1px dashed var(--bd); padding-top: .55rem; margin-top: .2rem; }
.kl-kolektif-box { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; margin-top: 8px; }
.kl-kolektif-box label { display: flex; flex-direction: column; gap: 3px; font-size: 11.5px; font-weight: 600; color: var(--tu); }
.kl-kolektif-box input, .kl-kolektif-box select { padding: 6px 8px; border: 1px solid var(--bd); border-radius: 7px; font-size: 12.5px; }
</style>
