<script setup>
/**
 * Rekap Kunjungan BPJS — screening pra-klaim.
 * Daftar SEMUA kunjungan pasien BPJS per tanggal (atau rentang), berpaginasi
 * server-side, diurut No SEP menaik. Tab jenis: Semua / RAJAL / RANAP (bisa
 * tunggal atau bedah). Tabel ramping: No SEP · No Kartu · MR · Nama · Jenis/Bedah ·
 * Tgl SEP · Kelengkapan (manual) · KET (free text) · DPJP. Status kelengkapan &
 * KET diisi inline oleh petugas; aksi berkas (Dokumen/Penunjang/SEP/Kwitansi) +
 * Diagnosa/No Rujukan ada di panel detail (klik baris). Bisa diekspor ke Excel.
 * Akses: permission bpjs.read/write (role verifikator).
 */
import { ref, computed, onMounted } from 'vue'
import JSZip from 'jszip'
import api from '@/services/api'
import Pager from '@/components/common/Pager.vue'
import { localDateStr } from '@/stores/admisiStore'

// Tanggal LOKAL (WIB), bukan UTC. `toISOString().slice(0,10)` menggeser ke
// kemarin pada 00:00–07:00 WIB → default rekap salah hari bagi verifikator pagi.
const today = localDateStr()

// ── Tab utama: screening klaim vs History (arsip kwitansi/resume) ───────────
const mainTab = ref('rekap')           // 'rekap' | 'history'
function setMainTab(t) { if (mainTab.value === t) return; mainTab.value = t }

// ── Filter & data ──────────────────────────────────────────────────────────
const jenisTab  = ref('')              // '' = Semua | 'RAJAL' | 'RANAP'
const rekapMode = ref('single')        // 'single' | 'range'
const rekapDate = ref(today)
const rekapFrom = ref(today)
const rekapTo   = ref(today)
const rekapSearch = ref('')

const rekapRows = ref([])
const rekapPage = ref(1)
const rekapPerPage = ref(50)
const rekapLast = ref(1)
const rekapTotal = ref(0)
const rekapLoading = ref(false)
const rekapExporting = ref(false)
const rekapSyncing = ref(false)
const savingId = ref(null)             // visit_id yang sedang simpan kelengkapan/KET

// ── Upload ───────────────────────────────────────────────────────────────────
const rekapUploadingId = ref(null)
const rekapFileInput = ref(null)
const rekapUploadTarget = ref(null)    // { visitId, category }

// ── Panel detail ───────────────────────────────────────────────────────────────
const EMPTY_BERKAS = () => ({ documents: [], penunjang: [], manual: [], checklist: { required: [], ready: false, missing: [] } })
const detailOpen = ref(false)
const detailRow = ref(null)
const detailBerkas = ref(EMPTY_BERKAS())
const detailLoading = ref(false)
const printingDocId = ref(null)
const correctionBusy = ref(false)

function dateParams() {
  const p = {}
  if (rekapMode.value === 'single') {
    if (rekapDate.value) p.tanggal = rekapDate.value
  } else {
    if (rekapFrom.value) p.tanggal_from = rekapFrom.value
    if (rekapTo.value) p.tanggal_to = rekapTo.value
  }
  if (rekapSearch.value.trim()) p.search = rekapSearch.value.trim()
  if (jenisTab.value) p.jenis = jenisTab.value
  return p
}

async function fetchRekap() {
  rekapLoading.value = true
  try {
    const params = { ...dateParams(), per_page: rekapPerPage.value, page: rekapPage.value }
    const { data } = await api.get('/klaim/rekap', { params })
    const p = data.data ?? {}
    rekapRows.value = p.data ?? []
    rekapLast.value = p.last_page ?? 1
    rekapTotal.value = p.total ?? 0
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal memuat rekap kunjungan')
    rekapRows.value = []
  } finally {
    rekapLoading.value = false
  }
}

function onRekapFilterChange() { rekapPage.value = 1; fetchRekap() }
function onRekapPage(n) { rekapPage.value = n; fetchRekap() }
function setTab(t) { if (jenisTab.value === t) return; jenisTab.value = t; onRekapFilterChange() }

// ── Kelengkapan & KET (inline, manual) ──────────────────────────────────────────
// lengkap: true=Lengkap, false=Belum Lengkap, null=Belum dicek.
async function saveKelengkapan(row, payload) {
  savingId.value = row.visit_id
  try {
    const { data } = await api.post(`/klaim/rekap/${row.visit_id}/kelengkapan`, payload)
    const r = data.data ?? {}
    row.berkas_lengkap = r.berkas_lengkap
    row.keterangan = r.keterangan
    if (detailRow.value?.visit_id === row.visit_id) {
      detailRow.value.berkas_lengkap = r.berkas_lengkap
      detailRow.value.keterangan = r.keterangan
    }
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal menyimpan status')
    await fetchRekap()
  } finally {
    savingId.value = null
  }
}

function onKelengkapanChange(row, e) {
  const v = e.target.value           // '', 'lengkap', 'belum'
  const lengkap = v === '' ? null : v === 'lengkap'
  saveKelengkapan(row, { lengkap, keterangan: row.keterangan ?? null })
}

function onKetChange(row, e) {
  const ket = e.target.value.trim()
  if ((row.keterangan ?? '') === ket) return
  const lengkap = row.berkas_lengkap == null ? null : !!row.berkas_lengkap
  saveKelengkapan(row, { lengkap, keterangan: ket || null })
}

function kelengkapanVal(row) {
  if (row.berkas_lengkap === null || row.berkas_lengkap === undefined) return ''
  return row.berkas_lengkap ? 'lengkap' : 'belum'
}

// ── Panel detail (berkas live: dokumen RM + penunjang + manual + checklist) ──────
const detailDocuments = computed(() => detailBerkas.value.documents ?? [])
const detailPenunjang = computed(() => detailBerkas.value.penunjang ?? [])
const detailManual = computed(() => detailBerkas.value.manual ?? [])
const manualPenunjang = computed(() => detailManual.value.filter((a) => a.category === 'PENUNJANG'))
const manualOther = computed(() => detailManual.value.filter((a) => a.category !== 'PENUNJANG'))
const checklist = computed(() => detailBerkas.value.checklist ?? { required: [], ready: false, missing: [] })

// Tiap baris checklist + dokumen RM terkait (bila sudah ada, by template_code) →
// tombol Lihat inline. Item tanpa dokumen (mis. PENUNJANG / belum dibuat) → doc null.
const checklistRows = computed(() =>
  (checklist.value.required ?? []).map((req) => ({
    ...req,
    doc: detailDocuments.value.find((d) => d.template_code === req.key) || null,
  }))
)

// "Dokumen Rekam Medis" = kelengkapan otomatis dalam satu daftar: dokumen WAJIB
// (dengan status TTD/ada) lebih dulu, lalu dokumen RM lain yang ada di luar wajib.
const claimDocList = computed(() => {
  const reqKeys = new Set((checklist.value.required ?? []).map((r) => r.key))
  const required = checklistRows.value.map((req) => ({
    key: 'req-' + req.key,
    label: req.label,
    required: true,
    present: req.present,
    signed: req.signed,
    doc: req.doc,
  }))
  const extras = detailDocuments.value
    .filter((d) => !reqKeys.has(d.template_code))
    .map((d) => ({
      key: 'doc-' + d.id,
      label: d.type_label,
      required: false,
      present: true,
      signed: d.signed,
      doc: d,
    }))
  return [...required, ...extras]
})

async function openDetail(row) {
  detailRow.value = row
  detailOpen.value = true
  await loadBerkas()
}

function closeDetail() {
  detailOpen.value = false
  detailRow.value = null
  detailBerkas.value = EMPTY_BERKAS()
}

async function loadBerkas() {
  if (!detailRow.value) return
  detailLoading.value = true
  try {
    const { data } = await api.get(`/klaim/rekap/${detailRow.value.visit_id}/berkas`)
    detailBerkas.value = data.data ?? EMPTY_BERKAS()
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal memuat berkas')
    detailBerkas.value = EMPTY_BERKAS()
  } finally {
    detailLoading.value = false
  }
}

function openFile(att) {
  if (att.file_url || att.attachment_url) window.open(att.file_url || att.attachment_url, '_blank')
}

// Label + warna chip status dokumen RM.
function docChipCls(doc) {
  if (doc.signed) return 'dc-signed'
  return doc.status === 'DRAFT' ? 'dc-draft' : 'dc-pending'
}

// Buka HTML di jendela (dipakai kwitansi & dokumen RM). doPrint=true → langsung
// munculkan dialog cetak (perilaku tombol "Cetak"); false → preview saja, user
// bisa cetak manual via Ctrl+P (tombol "Preview").
function printHtml(html, title, doPrint = true) {
  const w = window.open('', '_blank', 'width=900,height=1000')
  if (!w) { toast('w', 'Popup diblokir browser — izinkan popup'); return }
  w.document.open()
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${title}</title>
<style>@page{size:A4;margin:14mm}body{margin:0;font-family:Arial,sans-serif}</style></head><body>${html}</body></html>`)
  w.document.close()
  w.focus()
  if (doPrint) setTimeout(() => { try { w.print() } catch (_) { /* user bisa Ctrl+P */ } }, 350)
}

// Buka dokumen Form Registry (resume/laporan operasi) → cetak HTML (snapshot bila
// sudah final, fallback render preview untuk draft). Tanpa PDF backend.
async function openDocument(doc, doPrint = false) {
  printingDocId.value = doc.id
  try {
    let html = (await api.get(`/rekam-medis/document/${doc.id}/render`)).data?.data?.rendered_html
    if (!html && doc.template_code && detailRow.value) {
      html = (await api.get(`/rekam-medis/form/${doc.template_code}/render`, { params: { visit_id: detailRow.value.visit_id } })).data?.data?.html
    }
    if (!html) { toast('w', 'Dokumen belum dapat ditampilkan'); return }
    printHtml(html, doc.type_label || 'Dokumen', doPrint)
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal membuka dokumen')
  } finally {
    printingDocId.value = null
  }
}

// Minta dokter mengoreksi diagnosa/dokumen (grouper mismatch).
async function requestCorrection() {
  if (!detailRow.value) return
  const catatan = window.prompt('Catatan koreksi untuk dokter (opsional):', detailRow.value.keterangan || '')
  if (catatan === null) return
  correctionBusy.value = true
  try {
    const { data } = await api.post(`/klaim/rekap/${detailRow.value.visit_id}/minta-koreksi`, { catatan })
    const notified = data.data?.notified
    toast(notified ? 's' : 'i', notified ? 'Permintaan koreksi dikirim ke dokter' : 'Dicatat (dokter tak punya akun untuk notifikasi)')
    if (data.data?.keterangan != null) detailRow.value.keterangan = data.data.keterangan
    await fetchRekap()
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal mengirim permintaan koreksi')
  } finally {
    correctionBusy.value = false
  }
}

async function deleteAtt(att) {
  if (!confirm(`Hapus berkas "${att.file_name}"?`)) return
  try {
    await api.delete(`/klaim/rekap/${detailRow.value.visit_id}/lampiran/${att.id}`)
    toast('s', 'Berkas dihapus')
    await loadBerkas()
    await fetchRekap()
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal menghapus berkas')
  }
}

// ── Upload ───────────────────────────────────────────────────────────────────
function pickUpload(row, category) {
  rekapUploadTarget.value = { visitId: row.visit_id, category }
  rekapFileInput.value?.click()
}

async function onRekapFileChange(e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  const t = rekapUploadTarget.value
  if (!file || !t) return
  if (file.size > 10 * 1024 * 1024) { toast('w', 'Ukuran berkas maksimal 10 MB'); return }

  rekapUploadingId.value = t.visitId
  try {
    const form = new FormData()
    form.append('file', file)
    form.append('category', t.category)
    await api.post(`/klaim/rekap/${t.visitId}/lampiran`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    toast('s', 'Berkas berhasil diunggah')
    await fetchRekap()
    if (detailOpen.value && detailRow.value?.visit_id === t.visitId) await loadBerkas()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal mengunggah berkas')
  } finally {
    rekapUploadingId.value = null
    rekapUploadTarget.value = null
  }
}

// ── Cetak / Lihat SEP ──────────────────────────────────────────────────────────
const rekapPrintingSep = ref(null)
async function printSep(row) {
  if (!row.no_sep) { toast('w', 'Kunjungan ini belum punya SEP'); return }
  rekapPrintingSep.value = row.visit_id
  // Pakai endpoint HTML (bukan PDF blob): blade lembar SEP punya @page 13x21 cm +
  // auto-print, dan Chrome MENGHORMATI ukuran @page itu. Viewer PDF mengabaikan
  // media size → selalu jatuh ke A4. Buka via blob-URL (navigasi nyata) agar event
  // `load` blade (window.print) jalan — pola yang sama & teruji di AdmisiView.
  try {
    const res = await api.get(`/admisi/bpjs/cetak-sep-html/${row.visit_id}`, { responseType: 'text' })
    const url = URL.createObjectURL(new Blob([res.data], { type: 'text/html' }))
    const w = window.open(url, '_blank')
    if (!w) toast('w', 'Popup diblokir browser — izinkan popup untuk mencetak SEP')
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal mencetak SEP')
  } finally {
    rekapPrintingSep.value = null
  }
}

// ── Kwitansi ───────────────────────────────────────────────────────────────────
async function openKwitansi(row, doPrint = false) {
  if (!row.has_invoice) { toast('w', 'Belum ada kwitansi/tagihan untuk kunjungan ini'); return }
  try {
    const { data } = await api.get(`/rekam-medis/kunjungan/${row.visit_id}/kwitansi`)
    const html = data.data?.rendered_html
    if (!html) { toast('w', 'Kwitansi belum tersaji'); return }
    printHtml(html, 'Kwitansi', doPrint)
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal membuka kwitansi')
  }
}

// ── Export Excel ───────────────────────────────────────────────────────────────
function triggerDownload(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

// ── Sinkron SEP dari BPJS ────────────────────────────────────────────────────
// Banyak SEP diterbitkan langsung di portal VClaim → kolom No SEP "–". Tarik
// daftar SEP terbit (Monitoring Kunjungan) utk tanggal/rentang aktif lalu tautkan
// ke kunjungan via No.Kartu + tanggal.
async function syncSep() {
  rekapSyncing.value = true
  try {
    const params = {}
    if (rekapMode.value === 'single') {
      if (rekapDate.value) params.tanggal = rekapDate.value
    } else {
      if (rekapFrom.value) params.tanggal_from = rekapFrom.value
      if (rekapTo.value) params.tanggal_to = rekapTo.value
    }
    if (jenisTab.value) params.jenis = jenisTab.value
    const { data } = await api.post('/klaim/rekap/sinkron-sep', params)
    const r = data.data ?? {}
    const extra = r.unmatched ? ` · ${r.unmatched} tak cocok` : ''
    toast(r.linked ? 's' : 'i', `${r.linked || 0} SEP ditautkan${extra}`)
    await fetchRekap()
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal menyinkronkan SEP')
  } finally {
    rekapSyncing.value = false
  }
}

// ── Kirim ke Klaim (kunjungan → KlaimView) ───────────────────────────────────
// Kunjungan baru muncul di KlaimView setelah ada baris klaim. Tombol ini menyalin
// SEP + diagnosis dari kunjungan → bpjs_claims (butuh No.SEP + diagnosis utama Dokter).
const kirimBusy = ref(null)         // visit_id yang sedang dikirim
const kirimMassalBusy = ref(false)
async function kirimKlaim(row) {
  kirimBusy.value = row.visit_id
  try {
    await api.post(`/klaim/rekap/${row.visit_id}/kirim-klaim`)
    // Reflektif lokal: tandai terkirim & bersihkan jejak "dikembalikan".
    row.klaim_sent_at = new Date().toISOString()
    row.klaim_returned_at = null
    row.klaim_return_note = null
    if (detailRow.value?.visit_id === row.visit_id) {
      detailRow.value.klaim_sent_at = row.klaim_sent_at
      detailRow.value.klaim_returned_at = null
      detailRow.value.klaim_return_note = null
    }
    toast('s', 'Kunjungan dikirim ke daftar klaim')
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal mengirim ke klaim')
  } finally {
    kirimBusy.value = null
  }
}

async function kirimKlaimMassal() {
  if (!confirm('Kirim semua kunjungan yang SIAP (ada SEP + diagnosis utama) pada periode ini ke daftar klaim?')) return
  kirimMassalBusy.value = true
  try {
    const params = {}
    if (rekapMode.value === 'single') {
      if (rekapDate.value) params.tanggal = rekapDate.value
    } else {
      if (rekapFrom.value) params.tanggal_from = rekapFrom.value
      if (rekapTo.value) params.tanggal_to = rekapTo.value
    }
    if (jenisTab.value) params.jenis = jenisTab.value
    const { data } = await api.post('/klaim/rekap/kirim-klaim-massal', params)
    const r = data.data ?? {}
    const extra = [r.skipped ? `${r.skipped} dilewati (belum siap)` : '', r.failed ? `${r.failed} gagal` : ''].filter(Boolean).join(' · ')
    toast(r.sent ? 's' : 'i', `${r.sent || 0} kunjungan dikirim ke klaim${extra ? ' · ' + extra : ''}`)
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal mengirim massal ke klaim')
  } finally {
    kirimMassalBusy.value = false
  }
}

async function exportRekap() {
  rekapExporting.value = true
  try {
    const res = await api.get('/klaim/rekap/export', { params: dateParams(), responseType: 'blob' })
    const tag = rekapMode.value === 'single'
      ? rekapDate.value.replace(/-/g, '')
      : `${rekapFrom.value}_${rekapTo.value}`.replace(/-/g, '')
    triggerDownload(res.data, `rekap-kunjungan-bpjs-${tag}.xlsx`)
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal mengekspor')
  } finally {
    rekapExporting.value = false
  }
}

// ── History: unduh ZIP + preview resume per baris ───────────────────────────
const zipKwitansiBusy = ref(false)
const zipResumeBusy = ref(false)
const zipProgress = ref({ done: 0, total: 0 })   // progres unduh ZIP per-potongan
const resumeBusyId = ref(null)

// Banyaknya kunjungan per request render ZIP. Kecil supaya tiap request selesai
// jauh di bawah batas timeout proxy/Cloudflare (~100 dtk) walau hari sangat sibuk.
const ZIP_CHUNK = 8
const zipBtnLabel = computed(() => zipProgress.value.total
  ? `Menyiapkan… ${zipProgress.value.done}/${zipProgress.value.total}`
  : 'Menyiapkan…')

// Error muncul sbg blob (responseType blob) → coba baca pesan JSON di dalamnya.
async function blobErrMsg(e) {
  try {
    const txt = await e.response?.data?.text?.()
    if (txt) return JSON.parse(txt)?.message
  } catch (_) { /* abaikan */ }
  return e.response?.data?.message
}

// Unduh ZIP kwitansi/resume. Render PDF massal dalam SATU request bisa tembus
// timeout proxy (Cloudflare 524) pada hari sibuk → di sini dipecah: ambil daftar
// kunjungan (manifest, tanpa render), render per-potongan kecil, lalu gabungkan
// semua ZIP potongan jadi SATU file via JSZip. Hasil & UX identik (1 file).
async function downloadZip(kind) {
  const busy = kind === 'kwitansi' ? zipKwitansiBusy : zipResumeBusy
  busy.value = true
  zipProgress.value = { done: 0, total: 0 }
  try {
    // 1) Manifest: daftar visit_id periode aktif (cepat, tanpa render PDF).
    const { data } = await api.get('/klaim/rekap/bundle-manifest', { params: dateParams() })
    const ids = (data.data ?? []).map((v) => v.visit_id).filter(Boolean)
    if (!ids.length) { toast('w', 'Tidak ada kunjungan BPJS pada periode ini.'); return }

    // 2) Pecah jadi potongan kecil, render tiap potongan, gabung ke 1 ZIP.
    const url = kind === 'kwitansi' ? '/klaim/rekap/zip-kwitansi' : '/klaim/rekap/zip-resume'
    const master = new JSZip()
    const used = new Set()
    const chunks = []
    for (let i = 0; i < ids.length; i += ZIP_CHUNK) chunks.push(ids.slice(i, i + ZIP_CHUNK))
    zipProgress.value.total = chunks.length

    let lastErr = null
    for (const chunk of chunks) {
      try {
        const res = await api.get(url, { params: { ...dateParams(), ids: chunk }, responseType: 'blob' })
        const part = await JSZip.loadAsync(res.data)
        const entries = Object.values(part.files).filter((f) => !f.dir)
        for (const f of entries) {
          let name = f.name
          if (used.has(name)) {                     // hindari bentrok antar-potongan
            const dot = name.lastIndexOf('.')
            const base = dot > 0 ? name.slice(0, dot) : name
            const ext = dot > 0 ? name.slice(dot) : ''
            let n = 2
            while (used.has(`${base}-${n}${ext}`)) n++
            name = `${base}-${n}${ext}`
          }
          used.add(name)
          master.file(name, await f.async('uint8array'))
        }
      } catch (e) {
        // 404 = potongan tanpa berkas (mis. semua resume belum ada) → lewati, bukan fatal.
        if (e.response?.status !== 404) lastErr = e
      } finally {
        zipProgress.value.done++
      }
    }

    if (!used.size) {
      toast('w', (lastErr && await blobErrMsg(lastErr))
        ?? (kind === 'kwitansi' ? 'Tidak ada kwitansi yang bisa diunduh pada periode ini.'
                                : 'Tidak ada resume/laporan operasi yang bisa diunduh pada periode ini.'))
      return
    }

    // 3) Rakit & unduh satu file ZIP.
    const tag = rekapMode.value === 'single'
      ? rekapDate.value.replace(/-/g, '')
      : `${rekapFrom.value}_${rekapTo.value}`.replace(/-/g, '')
    const blob = await master.generateAsync({ type: 'blob' })
    triggerDownload(blob, `${kind}-bpjs-${tag}.zip`)
    if (lastErr) toast('w', 'Sebagian dokumen gagal diunduh; file ZIP berisi yang berhasil saja.')
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal mengunduh ZIP')
  } finally {
    busy.value = false
    zipProgress.value = { done: 0, total: 0 }
  }
}

// Preview resume medis 1 pasien (form RESUME_MEDIS) di jendela cetak.
async function openResumeRow(row) {
  resumeBusyId.value = row.visit_id
  try {
    const { data } = await api.get('/rekam-medis/form/RESUME_MEDIS/render', { params: { visit_id: row.visit_id } })
    const html = data?.data?.html
    if (!html) { toast('w', 'Resume belum tersedia'); return }
    printHtml(html, 'Resume Medis', false)
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal membuka resume')
  } finally {
    resumeBusyId.value = null
  }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmtDateTime(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

// Badge kolom Jenis/Bedah: bedah → tipe operasi (KATARAK…); selain itu → jenis.
function badge(r) {
  if (r.is_bedah) return { cls: 'b-bedah', text: r.bedah_label || 'Bedah' }
  const map = { RAJAL: { cls: 'b-rajal', text: 'Rawat Jalan' }, RANAP: { cls: 'b-ranap', text: 'Rawat Inap' }, IGD: { cls: 'b-igd', text: 'Gawat Darurat' } }
  return map[r.jenis_kode] || { cls: 'b-rajal', text: r.jenis || '—' }
}

// ── Toast ─────────────────────────────────────────────────────────────────────
const toasts = ref([])
let tid = 0
function toast(type, msg) {
  const id = ++tid
  toasts.value.push({ id, type, msg })
  setTimeout(() => (toasts.value = toasts.value.filter((t) => t.id !== id)), 3500)
}

onMounted(fetchRekap)
</script>

<template>
  <div class="rk-page">
    <header class="rk-head">
      <div>
        <h1>Rekap Kunjungan BPJS</h1>
        <p class="rk-sub">Screening kelengkapan berkas kunjungan pasien BPJS sebelum klaim.</p>
      </div>
    </header>

    <!-- Tab utama: Screening Klaim vs History -->
    <div class="rk-maintabs">
      <button :class="['rk-mtab', mainTab === 'rekap' ? 'a' : '']" @click="setMainTab('rekap')">Screening Klaim</button>
      <button :class="['rk-mtab', mainTab === 'history' ? 'a' : '']" @click="setMainTab('history')">History</button>
    </div>

    <!-- Tab jenis pelayanan -->
    <div class="rk-tabs">
      <button :class="['rk-tab', jenisTab === '' ? 'a' : '']" @click="setTab('')">Semua</button>
      <button :class="['rk-tab', jenisTab === 'RAJAL' ? 'a' : '']" @click="setTab('RAJAL')">Rawat Jalan</button>
      <button :class="['rk-tab', jenisTab === 'RANAP' ? 'a' : '']" @click="setTab('RANAP')">Rawat Inap</button>
    </div>

    <!-- Toolbar -->
    <div class="rk-toolbar">
      <div class="rk-mode">
        <button :class="['rk-seg', rekapMode === 'single' ? 'a' : '']" @click="rekapMode = 'single'; onRekapFilterChange()">1 Tanggal</button>
        <button :class="['rk-seg', rekapMode === 'range' ? 'a' : '']" @click="rekapMode = 'range'; onRekapFilterChange()">Rentang</button>
      </div>

      <template v-if="rekapMode === 'single'">
        <input type="date" v-model="rekapDate" @change="onRekapFilterChange" />
      </template>
      <template v-else>
        <input type="date" v-model="rekapFrom" @change="onRekapFilterChange" />
        <span class="rk-dash">s/d</span>
        <input type="date" v-model="rekapTo" @change="onRekapFilterChange" />
      </template>

      <input
        type="search"
        v-model="rekapSearch"
        placeholder="Cari nama / No SEP / No kartu BPJS"
        class="rk-search"
        @keyup.enter="onRekapFilterChange"
      />
      <button class="rk-btn" @click="onRekapFilterChange">Cari</button>

      <div class="rk-spacer" />
      <span class="rk-count">{{ rekapTotal }} kunjungan</span>
      <select class="rk-pp" v-model.number="rekapPerPage" @change="onRekapFilterChange" title="Baris per halaman">
        <option :value="25">25 / hal</option>
        <option :value="50">50 / hal</option>
        <option :value="100">100 / hal</option>
        <option :value="200">200 / hal</option>
      </select>
      <button v-if="mainTab === 'rekap'" class="rk-btn rk-btn-sync" :disabled="rekapSyncing" title="Tarik SEP yang terbit di portal VClaim lalu tautkan ke kunjungan" @click="syncSep">
        {{ rekapSyncing ? 'Menyinkron…' : 'Sinkron SEP' }}
      </button>
      <button v-if="mainTab === 'rekap'" class="rk-btn rk-btn-kirim" :disabled="kirimMassalBusy" title="Kirim semua kunjungan siap (SEP + diagnosis) ke daftar klaim" @click="kirimKlaimMassal">
        {{ kirimMassalBusy ? 'Mengirim…' : 'Kirim, Berkas Siap di Klaim' }}
      </button>
      <button v-if="mainTab === 'history'" class="rk-btn rk-btn-kirim" :disabled="zipKwitansiBusy || zipResumeBusy" title="Unduh semua kwitansi (PDF) pada periode ini sebagai ZIP" @click="downloadZip('kwitansi')">
        {{ zipKwitansiBusy ? zipBtnLabel : 'ZIP Kwitansi' }}
      </button>
      <button v-if="mainTab === 'history'" class="rk-btn rk-btn-sync" :disabled="zipKwitansiBusy || zipResumeBusy" title="Unduh semua resume medis + laporan operasi (PDF) pada periode ini sebagai ZIP" @click="downloadZip('resume')">
        {{ zipResumeBusy ? zipBtnLabel : 'ZIP Resume' }}
      </button>
      <button class="rk-btn rk-btn-export" :disabled="rekapExporting" @click="exportRekap">
        {{ rekapExporting ? 'Mengekspor…' : 'Export Excel' }}
      </button>
    </div>

    <!-- input file tersembunyi utk upload -->
    <input ref="rekapFileInput" type="file" accept=".pdf,image/*" hidden @change="onRekapFileChange" />

    <!-- Tabel -->
    <div class="rk-table-wrap">
      <table v-if="mainTab === 'rekap'" class="rk-table">
        <thead>
          <tr>
            <th class="c-no">No.</th>
            <th class="c-sep">No SEP</th>
            <th class="c-kartu">No Kartu BPJS</th>
            <th class="c-mr">MR</th>
            <th>Nama Peserta</th>
            <th class="c-jenis">Jenis / Bedah</th>
            <th class="c-tgl">Tgl SEP</th>
            <th class="c-kel">Kelengkapan</th>
            <th class="c-ket">Keterangan</th>
            <th>DPJP</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="rekapLoading"><td colspan="10" class="rk-empty">Memuat…</td></tr>
          <tr v-else-if="!rekapRows.length"><td colspan="10" class="rk-empty">Tidak ada kunjungan BPJS pada periode ini.</td></tr>
          <tr v-for="(r, i) in rekapRows" :key="r.visit_id" class="rk-row" @click="openDetail(r)">
            <td class="rk-no">{{ (rekapPage - 1) * rekapPerPage + i + 1 }}</td>
            <td class="rk-sep">{{ r.no_sep || '—' }}</td>
            <td>{{ r.bpjs_number || '—' }}</td>
            <td>{{ r.no_rm || '—' }}</td>
            <td>
              <div class="rk-nama">{{ r.nama || '-' }}</div>
              <small class="rk-rm">{{ r.tgl_lahir || '—' }} · {{ r.gender || '—' }}</small>
            </td>
            <td><span class="rk-badge" :class="badge(r).cls">{{ badge(r).text }}</span></td>
            <td>{{ r.tgl_sep || '—' }}</td>
            <td class="c-kel" @click.stop>
              <select
                class="rk-kel-sel"
                :class="kelengkapanVal(r)"
                :value="kelengkapanVal(r)"
                :disabled="savingId === r.visit_id"
                @change="onKelengkapanChange(r, $event)"
              >
                <option value="">Belum dicek</option>
                <option value="lengkap">Lengkap</option>
                <option value="belum">Belum Lengkap</option>
              </select>
              <span
                class="rk-claimchip"
                :class="r.claim_ready ? 'ok' : 'no'"
                :title="r.claim_ready ? 'Dokumen wajib sudah TTD' : 'Dokumen wajib belum lengkap/TTD'"
              >{{ r.claim_ready ? '✓ Siap klaim' : `Berkas ${r.docs_signed_count}/${r.docs_required_count}` }}</span>
            </td>
            <td class="c-ket" @click.stop>
              <span v-if="r.klaim_returned_at" class="rk-return-badge" :title="r.klaim_return_note || 'Dikembalikan dari Klaim'">
                ↩ Dikembalikan dari Klaim
              </span>
              <span v-else-if="r.klaim_sent_at" class="rk-sent-badge" title="Sudah dikirim ke daftar klaim">✓ Terkirim ke klaim</span>
              <div v-if="r.klaim_returned_at && r.klaim_return_note" class="rk-return-note">{{ r.klaim_return_note }}</div>
              <input
                class="rk-ket-inp"
                type="text"
                :value="r.keterangan || ''"
                placeholder="—"
                :disabled="savingId === r.visit_id"
                @change="onKetChange(r, $event)"
                @keyup.enter="$event.target.blur()"
              />
            </td>
            <td>{{ r.dpjp || '-' }}</td>
          </tr>
        </tbody>
      </table>

      <!-- Tabel History: arsip kwitansi & resume per pasien -->
      <table v-else class="rk-table">
        <thead>
          <tr>
            <th class="c-no">No.</th>
            <th class="c-sep">No SEP</th>
            <th class="c-mr">MR</th>
            <th>Nama Peserta</th>
            <th class="c-jenis">Jenis / Bedah</th>
            <th class="c-hist">Kwitansi</th>
            <th class="c-hist">Resume</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="rekapLoading"><td colspan="7" class="rk-empty">Memuat…</td></tr>
          <tr v-else-if="!rekapRows.length"><td colspan="7" class="rk-empty">Tidak ada kunjungan BPJS pada periode ini.</td></tr>
          <tr v-for="(r, i) in rekapRows" :key="r.visit_id" class="rk-row" @click="openDetail(r)">
            <td class="rk-no">{{ (rekapPage - 1) * rekapPerPage + i + 1 }}</td>
            <td class="rk-sep">{{ r.no_sep || '—' }}</td>
            <td>{{ r.no_rm || '—' }}</td>
            <td>
              <div class="rk-nama">{{ r.nama || '-' }}</div>
              <small class="rk-rm">{{ r.bpjs_number || '—' }}</small>
            </td>
            <td><span class="rk-badge" :class="badge(r).cls">{{ badge(r).text }}</span></td>
            <td class="c-hist" @click.stop>
              <div class="rk-hist-cell">
                <span class="rk-claimchip" :class="r.is_paid ? 'ok' : 'no'">{{ r.has_invoice ? (r.is_paid ? 'Lunas' : 'Belum lunas') : 'Tidak ada' }}</span>
                <button class="rk-chip" :disabled="!r.has_invoice" @click="openKwitansi(r, false)">Lihat</button>
              </div>
            </td>
            <td class="c-hist" @click.stop>
              <div class="rk-hist-cell">
                <span class="rk-claimchip" :class="r.resume_signed ? 'ok' : 'no'">{{ r.has_resume ? (r.resume_signed ? 'TTD' : 'Draf') : 'Belum ada' }}</span>
                <button class="rk-chip" :disabled="resumeBusyId === r.visit_id || !r.has_resume" @click="openResumeRow(r)">
                  {{ resumeBusyId === r.visit_id ? '…' : 'Lihat' }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Pager v-model:page="rekapPage" :last-page="rekapLast" :total="rekapTotal" @change="onRekapPage" />

    <!-- Panel detail (berkas, diagnosa, SEP, kwitansi) -->
    <div v-if="detailOpen" class="rk-panel-bg" @click.self="closeDetail">
      <aside class="rk-panel">
        <div class="rk-panel-head">
          <div>
            <h3>{{ detailRow?.nama }}</h3>
            <div class="rk-panel-sub">RM {{ detailRow?.no_rm || '-' }} · SEP {{ detailRow?.no_sep || '-' }}</div>
          </div>
          <button class="rk-x" @click="closeDetail">✕</button>
        </div>

        <div class="rk-panel-body">
          <!-- Banner: dikembalikan dari Klaim (beserta pesan) -->
          <div v-if="detailRow?.klaim_returned_at" class="rk-return-banner">
            <strong>↩ Dikembalikan dari Klaim</strong>
            <p v-if="detailRow?.klaim_return_note">{{ detailRow.klaim_return_note }}</p>
            <small>Lengkapi/koreksi lalu kirim ulang ke klaim.</small>
          </div>

          <!-- Info klaim -->
          <div class="rk-info">
            <div><span class="rk-lbl">Jenis</span><span class="rk-badge" :class="badge(detailRow).cls">{{ badge(detailRow).text }}</span></div>
            <div><span class="rk-lbl">Tgl SEP</span><span>{{ detailRow?.tgl_sep || '—' }}</span></div>
            <div><span class="rk-lbl">Kelas</span><span>{{ detailRow?.kelas || '—' }}</span></div>
            <div><span class="rk-lbl">DPJP</span><span>{{ detailRow?.dpjp || '—' }}</span></div>
            <div class="rk-info-wide"><span class="rk-lbl">Diagnosa</span><span>{{ detailRow?.diagnosa || '—' }}</span></div>
            <div class="rk-info-wide"><span class="rk-lbl">No Rujukan</span><span>{{ detailRow?.no_rujukan || '—' }}</span></div>
          </div>

          <!-- Aksi cetak -->
          <div class="rk-actions">
            <button class="rk-btn" :disabled="rekapPrintingSep === detailRow?.visit_id || !detailRow?.no_sep" @click="printSep(detailRow)">
              {{ rekapPrintingSep === detailRow?.visit_id ? 'Menyiapkan…' : 'Cetak SEP' }}
            </button>
            <button
              class="rk-btn"
              :disabled="!detailRow?.has_invoice"
              :title="detailRow?.has_invoice ? (detailRow?.is_paid ? 'Lunas' : 'Belum lunas') : 'Belum ada tagihan'"
              @click="openKwitansi(detailRow, false)"
            >
              {{ detailRow?.has_invoice ? 'Preview Kwitansi' : 'Kwitansi (—)' }}
            </button>
            <button class="rk-btn" :disabled="!detailRow?.has_invoice" @click="openKwitansi(detailRow, true)">
              Cetak Kwitansi
            </button>
            <button
              class="rk-btn rk-btn-kirim"
              :disabled="kirimBusy === detailRow?.visit_id || !detailRow?.no_sep"
              :title="!detailRow?.no_sep ? 'Belum ada SEP' : (detailRow?.klaim_sent_at ? 'Sudah terkirim — klik untuk kirim ulang data' : 'Kirim kunjungan ini ke daftar klaim (KlaimView)')"
              @click="kirimKlaim(detailRow)"
            >
              {{ kirimBusy === detailRow?.visit_id ? 'Mengirim…' : (detailRow?.klaim_sent_at ? '✓ Terkirim — Kirim Ulang' : 'Kirim, Berkas Siap di Klaim') }}
            </button>
          </div>

          <!-- Dokumen Rekam Medis = kelengkapan otomatis (satu fungsi) -->
          <div class="rk-docsec rk-checklist" :class="checklist.ready ? 'ready' : 'notready'">
            <div class="rk-docsec-head">
              <h4>
                <span class="rk-cl-badge" :class="checklist.ready ? 'ok' : 'no'">
                  {{ checklist.ready ? '✓ Siap Klaim' : 'Belum Siap' }}
                </span>
                Dokumen Rekam Medis
              </h4>
              <button class="rk-chip rk-chip-warn" :disabled="correctionBusy" @click="requestCorrection">
                {{ correctionBusy ? '…' : 'Minta Koreksi' }}
              </button>
            </div>
            <p v-if="detailLoading" class="rk-empty-sm">Memuat…</p>
            <ul v-else class="rk-doc-list">
              <li v-for="d in claimDocList" :key="d.key" :class="d.signed ? 'done' : (d.present ? 'partial' : 'miss')">
                <div class="rk-doc-info">
                  <span class="rk-doc-name">
                    <span class="rk-cl-mark">{{ d.signed ? '✓' : (d.present ? '◐' : '✗') }}</span>
                    {{ d.label }}
                    <span v-if="d.required" class="rk-dchip dc-req">wajib</span>
                    <span v-if="d.present" class="rk-dchip" :class="docChipCls(d.doc)">{{ d.doc.status_label }}</span>
                    <span v-if="d.doc && d.doc.coding_synced === false" class="rk-dchip dc-warn">koding berubah</span>
                  </span>
                  <small v-if="d.doc?.signed_at">TTD {{ fmtDateTime(d.doc.signed_at) }}<span v-if="d.doc.revision"> · revisi {{ d.doc.revision }}</span></small>
                  <small v-else>{{ d.signed ? 'sudah TTD' : (d.present ? 'belum TTD' : 'belum ada') }}</small>
                </div>
                <div v-if="d.present" class="rk-doc-act">
                  <button class="rk-chip" :disabled="printingDocId === d.doc.id" @click="openDocument(d.doc, false)">
                    {{ printingDocId === d.doc.id ? '…' : 'Preview' }}
                  </button>
                  <button class="rk-chip" :disabled="printingDocId === d.doc.id" title="Buka & cetak" @click="openDocument(d.doc, true)">
                    Cetak
                  </button>
                </div>
              </li>
            </ul>
          </div>

          <!-- Hasil Penunjang (terstruktur + upload manual) -->
          <div class="rk-docsec">
            <div class="rk-docsec-head">
              <h4>Hasil Penunjang</h4>
              <button class="rk-chip rk-chip-up" :disabled="rekapUploadingId === detailRow?.visit_id" @click="pickUpload(detailRow, 'PENUNJANG')">
                {{ rekapUploadingId === detailRow?.visit_id ? '…' : '+ Upload' }}
              </button>
            </div>
            <p v-if="detailLoading" class="rk-empty-sm">Memuat…</p>
            <p v-else-if="!detailPenunjang.length && !manualPenunjang.length" class="rk-empty-sm">Belum ada hasil penunjang.</p>
            <ul v-else class="rk-doc-list">
              <li v-for="p in detailPenunjang" :key="p.order_id">
                <div class="rk-doc-info">
                  <span class="rk-doc-name">{{ p.test_name }}<span v-if="p.eye_side"> · {{ p.eye_side }}</span>
                    <span class="rk-dchip" :class="['COMPLETED','REVIEWED','APPROVED'].includes(p.status) ? 'dc-signed' : 'dc-pending'">{{ p.status }}</span>
                  </span>
                  <small>{{ p.summary || p.examiner || '—' }}</small>
                </div>
                <div class="rk-doc-act">
                  <button class="rk-chip" :disabled="!p.attachment_url" @click="openFile(p)">Lihat</button>
                </div>
              </li>
              <li v-for="a in manualPenunjang" :key="a.id">
                <div class="rk-doc-info">
                  <span class="rk-doc-name">{{ a.title || a.file_name }} <span class="rk-dchip dc-manual">upload</span></span>
                  <small>{{ fmtDateTime(a.at) }} · {{ a.by || '—' }}</small>
                </div>
                <div class="rk-doc-act">
                  <button class="rk-chip" @click="openFile(a)">Buka</button>
                  <button class="rk-chip rk-chip-del" @click="deleteAtt(a)">Hapus</button>
                </div>
              </li>
            </ul>
          </div>

          <!-- Berkas Tambahan (upload manual: SEP, surat, dll) -->
          <div class="rk-docsec">
            <div class="rk-docsec-head">
              <h4>Berkas Tambahan</h4>
              <button class="rk-chip rk-chip-up" :disabled="rekapUploadingId === detailRow?.visit_id" @click="pickUpload(detailRow, 'LAINNYA')">
                {{ rekapUploadingId === detailRow?.visit_id ? '…' : '+ Upload' }}
              </button>
            </div>
            <p v-if="detailLoading" class="rk-empty-sm">Memuat…</p>
            <p v-else-if="!manualOther.length" class="rk-empty-sm">Belum ada berkas tambahan.</p>
            <ul v-else class="rk-doc-list">
              <li v-for="a in manualOther" :key="a.id">
                <div class="rk-doc-info">
                  <span class="rk-doc-name">{{ a.title || a.file_name }}</span>
                  <small>{{ fmtDateTime(a.at) }} · {{ a.by || '—' }}</small>
                </div>
                <div class="rk-doc-act">
                  <button class="rk-chip" @click="openFile(a)">Buka</button>
                  <button class="rk-chip rk-chip-del" @click="deleteAtt(a)">Hapus</button>
                </div>
              </li>
            </ul>
          </div>
        </div>
      </aside>
    </div>

    <!-- Toast -->
    <div class="toast-wrap" role="status" aria-live="polite" aria-atomic="false" aria-label="Notifikasi sistem">
      <div v-for="t in toasts" :key="t.id" :class="['toast', `toast-${t.type}`]">{{ t.msg }}</div>
    </div>
  </div>
</template>

<style scoped>
.rk-page { padding: 18px 22px; max-width: 1320px; margin: 0 auto; }
.rk-head { margin-bottom: 12px; }
.rk-head h1 { font-size: 19px; font-weight: 700; color: var(--td); margin: 0; }
.rk-sub { font-size: 12.5px; color: var(--tu); margin: 4px 0 0; }

/* Tab utama (Screening / History) */
.rk-maintabs { display: inline-flex; gap: 4px; margin-bottom: 12px; padding: 3px; border: 1px solid var(--gb); border-radius: 10px; background: var(--gl); }
.rk-mtab { padding: 7px 18px; border: 0; background: none; cursor: pointer; font-size: 13px; font-weight: 600; color: var(--tu); border-radius: 7px; }
.rk-mtab.a { background: var(--bc); color: var(--ga); box-shadow: 0 1px 3px rgba(0,0,0,0.08); }

/* Tabs */
.rk-tabs { display: flex; gap: 6px; margin-bottom: 12px; border-bottom: 1px solid var(--gb); }
.rk-tab { padding: 8px 16px; border: 0; background: none; cursor: pointer; font-size: 13px; color: var(--tu); border-bottom: 2px solid transparent; margin-bottom: -1px; }
.rk-tab.a { color: var(--ga); font-weight: 700; border-bottom-color: var(--ga); }

.rk-toolbar { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.rk-mode { display: inline-flex; border: 1px solid var(--gb); border-radius: 9px; overflow: hidden; }
.rk-seg { padding: 7px 14px; border: 0; background: var(--bc); cursor: pointer; font-size: 12.5px; color: var(--tu); }
.rk-seg.a { background: var(--ga); color: #fff; font-weight: 600; }
.rk-dash { color: var(--tu); font-size: 12px; }
.rk-toolbar input[type="date"], .rk-search {
  height: 36px; padding: 0 10px; border: 1px solid var(--gb); border-radius: 8px;
  background: var(--bc); color: var(--td); font-size: 12.5px;
}
.rk-search { min-width: 230px; flex: 0 1 270px; }
.rk-btn {
  height: 36px; padding: 0 16px; border: 1px solid var(--gb); border-radius: 8px;
  background: var(--bc); color: var(--td); cursor: pointer; font-size: 12.5px; font-weight: 600;
}
.rk-btn:hover:not(:disabled) { background: var(--gl); }
.rk-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.rk-btn-export { background: var(--ga); color: #fff; border-color: var(--ga); }
.rk-btn-export:disabled { opacity: 0.6; cursor: not-allowed; }
.rk-btn-sync { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.rk-btn-sync:disabled { opacity: 0.6; cursor: not-allowed; }
.rk-btn-kirim { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.rk-btn-kirim:disabled { opacity: 0.6; cursor: not-allowed; }
.rk-spacer { flex: 1 1 auto; }
.rk-count { font-size: 12px; color: var(--tu); }
.rk-pp { height: 36px; padding: 0 8px; border: 1px solid var(--gb); border-radius: 8px; background: var(--bc); color: var(--td); font-size: 12px; cursor: pointer; }

.rk-table-wrap { border: 1px solid var(--gb); border-radius: 12px; overflow: hidden; background: var(--bc); }
.rk-table { width: 100%; border-collapse: collapse; font-size: 12.5px; table-layout: fixed; }
.rk-table th, .rk-table td { padding: 9px 11px; text-align: left; border-bottom: 1px solid var(--gb); vertical-align: middle; }
.rk-table th { background: var(--gl); color: var(--tu); font-weight: 600; font-size: 11.5px; }
.rk-table tr:last-child td { border-bottom: 0; }
.rk-table td { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.c-no { width: 40px; }
.c-sep { width: 152px; }
.c-kartu { width: 108px; }
.c-mr { width: 78px; }
.c-jenis { width: 116px; }
.c-tgl { width: 92px; }
.c-kel { width: 130px; }
.c-ket { width: 150px; }
.c-hist { width: 150px; }
.rk-hist-cell { display: flex; align-items: center; gap: 7px; }
.rk-no { color: var(--tu); text-align: center; font-variant-numeric: tabular-nums; }
.rk-sep { font-family: ui-monospace, monospace; font-size: 11.5px; }
.rk-nama { font-weight: 600; color: var(--td); white-space: normal; }
.rk-rm { color: var(--tu); font-size: 11px; display: block; }
.rk-row { cursor: pointer; }
.rk-row:hover td { background: var(--gl); }
.rk-empty { text-align: center; color: var(--tu); padding: 26px 12px !important; }

/* Badge jenis/bedah */
.rk-badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.b-bedah { background: #fce7f3; color: #be185d; }
.b-rajal { background: #ffedd5; color: #c2410c; }
.b-ranap { background: #dbeafe; color: #1d4ed8; }
.b-igd   { background: #fee2e2; color: #b91c1c; }

/* Kelengkapan inline */
.rk-kel-sel { width: 100%; height: 30px; padding: 0 6px; border: 1px solid var(--gb); border-radius: 7px; background: var(--bc); color: var(--td); font-size: 11.5px; font-weight: 600; cursor: pointer; }
.rk-kel-sel.lengkap { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.rk-kel-sel.belum   { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.rk-ket-inp { width: 100%; height: 30px; padding: 0 8px; border: 1px solid var(--gb); border-radius: 7px; background: var(--bc); color: var(--td); font-size: 12px; }
.rk-ket-inp:focus { outline: none; border-color: var(--ga); }
.rk-return-badge { display: inline-block; margin-bottom: 4px; padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 700; white-space: nowrap; background: #fee2e2; color: #991b1b; }
.rk-sent-badge { display: inline-block; margin-bottom: 4px; padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 700; white-space: nowrap; background: #dcfce7; color: #166534; }
.rk-return-note { font-size: 11px; color: #991b1b; margin-bottom: 4px; line-height: 1.25; }
.rk-return-banner { background: #fef2f2; border: 1px solid #fecaca; border-radius: 9px; padding: 10px 12px; margin-bottom: 12px; }
.rk-return-banner strong { color: #991b1b; font-size: 13px; }
.rk-return-banner p { margin: 4px 0 2px; color: #7f1d1d; font-size: 12.5px; }
.rk-return-banner small { color: #b45309; font-size: 11px; }

/* Panel detail (slide-over) */
.rk-panel-bg { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; justify-content: flex-end; z-index: 1000; }
.rk-panel { background: var(--bc); width: 100%; max-width: 480px; height: 100%; display: flex; flex-direction: column; box-shadow: -8px 0 30px rgba(0,0,0,0.2); }
.rk-panel-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 16px 18px; border-bottom: 1px solid var(--gb); }
.rk-panel-head h3 { margin: 0; font-size: 15px; font-weight: 700; color: var(--td); }
.rk-panel-sub { font-size: 12px; color: var(--tu); margin-top: 3px; }
.rk-x { border: 0; background: none; cursor: pointer; font-size: 16px; color: var(--tu); }
.rk-panel-body { padding: 16px 18px; overflow-y: auto; flex: 1 1 auto; display: flex; flex-direction: column; gap: 16px; }

.rk-info { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 14px; }
.rk-info > div { display: flex; flex-direction: column; gap: 2px; font-size: 12.5px; color: var(--td); }
.rk-info-wide { grid-column: 1 / -1; }
.rk-lbl { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--tu); }

.rk-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.rk-actions .rk-btn { flex: 1 1 120px; white-space: nowrap; padding: 0 10px; }

.rk-docsec { border: 1px solid var(--gb); border-radius: 10px; padding: 12px; }
.rk-docsec-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.rk-docsec-head h4 { margin: 0; font-size: 13px; font-weight: 700; color: var(--td); }
.rk-empty-sm { color: var(--tu); font-size: 12px; margin: 4px 0; }
.rk-doc-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 7px; }
.rk-doc-list li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 7px 9px; border: 1px solid var(--gb); border-radius: 8px; }
.rk-doc-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.rk-doc-name { font-weight: 600; font-size: 12px; color: var(--td); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rk-doc-info small { color: var(--tu); font-size: 10.5px; }
.rk-doc-act { display: flex; gap: 5px; flex-shrink: 0; }

.rk-chip {
  padding: 5px 9px; border: 1px solid var(--gb); border-radius: 7px; background: var(--bc);
  cursor: pointer; font-size: 11.5px; color: var(--td); white-space: nowrap;
}
.rk-chip:hover:not(:disabled) { background: var(--gl); border-color: var(--ga); }
.rk-chip:disabled { opacity: 0.45; cursor: not-allowed; }
.rk-chip-up { color: var(--ga); border-color: var(--ga); }
.rk-chip-del { color: var(--et); border-color: var(--ebd); }
.rk-chip-warn { color: var(--wt); border-color: var(--wbd); background: var(--wb); }

/* Chip status siap-klaim di kolom tabel */
.rk-claimchip { display: inline-block; margin-top: 4px; padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 700; white-space: nowrap; }
.rk-claimchip.ok { background: var(--sb); color: var(--st); }
.rk-claimchip.no { background: var(--wb); color: var(--wt); }
.rk-dchip.dc-req { background: #eff6ff; color: #1d4ed8; }
.rk-cl-mark { font-weight: 800; margin-right: 4px; }
.rk-doc-list li.miss .rk-cl-mark { color: #dc2626; }
.rk-doc-list li.partial .rk-cl-mark { color: #b45309; }
.rk-doc-list li.done .rk-cl-mark { color: #16a34a; }

/* Kotak checklist kelengkapan klaim (panel detail) */
.rk-checklist { border: 1px solid var(--gb); border-radius: 10px; padding: 12px; }
.rk-checklist.ready { border-color: var(--sbd); background: color-mix(in srgb, var(--sb) 35%, transparent); }
.rk-checklist.notready { border-color: var(--wbd); background: color-mix(in srgb, var(--wb) 35%, transparent); }
.rk-cl-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.rk-cl-title { display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 700; color: var(--td); }
.rk-cl-badge { padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.rk-cl-badge.ok { background: var(--sb); color: var(--st); }
.rk-cl-badge.no { background: var(--wb); color: var(--wt); }
.rk-cl-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.rk-cl-list li { display: flex; align-items: center; gap: 7px; font-size: 12px; color: var(--td); }
.rk-cl-list li small { color: var(--tu); font-size: 10.5px; margin-left: auto; }
.rk-cl-view { padding: 2px 9px; font-size: 10.5px; flex-shrink: 0; }
.rk-cl-mark { width: 16px; text-align: center; font-weight: 700; }
.rk-cl-list li.done .rk-cl-mark { color: var(--st); }
.rk-cl-list li.partial .rk-cl-mark { color: var(--wt); }
.rk-cl-list li.miss .rk-cl-mark { color: var(--et); }

/* Chip status dokumen RM dalam daftar */
.rk-dchip { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 700; margin-left: 6px; vertical-align: middle; }
.dc-signed { background: var(--sb); color: var(--st); }
.dc-draft { background: var(--gl); color: var(--tu); }
.dc-pending { background: var(--ib); color: var(--it); }
.dc-warn { background: var(--eb); color: var(--et); }
.dc-manual { background: var(--gl); color: var(--tu); font-weight: 600; }

/* Toast */
.toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 9999; display: flex; flex-direction: column; gap: 6px; pointer-events: none; }
.toast { padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 14px rgba(0,0,0,0.1); min-width: 240px; }
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }
</style>
