<script setup>
/**
 * Rekap Kunjungan BPJS — screening pra-klaim.
 * Daftar SEMUA kunjungan pasien BPJS per tanggal (atau rentang), berpaginasi
 * server-side. Tiap baris: Nama · No SEP · No Kartu BPJS · DPJP · Diagnosa ·
 * Dokumen Pendukung · Hasil Penunjang · Kwitansi. Dokumen & Penunjang punya
 * aksi Lihat (modal buka/hapus PDF) + Upload. Bisa diekspor ke Excel.
 * Akses: permission bpjs.read/write (role verifikator).
 */
import { ref, onMounted } from 'vue'
import api from '@/services/api'
import Pager from '@/components/common/Pager.vue'

const today = new Date().toISOString().slice(0, 10)

// ── Filter & data ──────────────────────────────────────────────────────────
const rekapMode = ref('single')        // 'single' | 'range'
const rekapDate = ref(today)
const rekapFrom = ref(today)
const rekapTo   = ref(today)
const rekapSearch = ref('')

const rekapRows = ref([])
const rekapPage = ref(1)
const rekapPerPage = ref(50)           // baris/halaman (tanpa batas total, paginasi)
const rekapLast = ref(1)
const rekapTotal = ref(0)
const rekapLoading = ref(false)
const rekapExporting = ref(false)

// ── Upload ───────────────────────────────────────────────────────────────────
const rekapUploadingId = ref(null)     // visit_id yang sedang upload
const rekapFileInput = ref(null)
const rekapUploadTarget = ref(null)    // { visitId, category }

// ── Modal daftar berkas ───────────────────────────────────────────────────────
const docModalOpen = ref(false)
const docModalRow = ref(null)
const docModalCat = ref('OTHER')       // 'PENUNJANG' | 'OTHER'
const docModalList = ref([])
const docModalLoading = ref(false)

function dateParams() {
  const p = {}
  if (rekapMode.value === 'single') {
    if (rekapDate.value) p.tanggal = rekapDate.value
  } else {
    if (rekapFrom.value) p.tanggal_from = rekapFrom.value
    if (rekapTo.value) p.tanggal_to = rekapTo.value
  }
  if (rekapSearch.value.trim()) p.search = rekapSearch.value.trim()
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

// ── Modal daftar berkas (Lihat) ────────────────────────────────────────────────
function filterByCat(list, cat) {
  return (list ?? []).filter((a) =>
    cat === 'PENUNJANG' ? a.category === 'PENUNJANG' : a.category !== 'PENUNJANG')
}

async function openDocsModal(row, cat) {
  docModalRow.value = row
  docModalCat.value = cat
  docModalOpen.value = true
  await loadDocsModal()
}

async function loadDocsModal() {
  if (!docModalRow.value) return
  docModalLoading.value = true
  try {
    const { data } = await api.get(`/klaim/rekap/${docModalRow.value.visit_id}/lampiran`)
    docModalList.value = filterByCat(data.data, docModalCat.value)
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal memuat berkas')
    docModalList.value = []
  } finally {
    docModalLoading.value = false
  }
}

function closeDocsModal() {
  docModalOpen.value = false
  docModalRow.value = null
  docModalList.value = []
}

function openFile(att) {
  if (att.file_url) window.open(att.file_url, '_blank')
}

async function deleteAtt(att) {
  if (!confirm(`Hapus berkas "${att.file_name}"?`)) return
  try {
    await api.delete(`/klaim/rekap/${docModalRow.value.visit_id}/lampiran/${att.id}`)
    toast('s', 'Berkas dihapus')
    await loadDocsModal()
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
    if (docModalOpen.value && docModalRow.value?.visit_id === t.visitId) await loadDocsModal()
  } catch (err) {
    toast('w', err.response?.data?.message ?? 'Gagal mengunggah berkas')
  } finally {
    rekapUploadingId.value = null
    rekapUploadTarget.value = null
  }
}

// ── Cetak / Lihat SEP ──────────────────────────────────────────────────────────
// Ambil PDF SEP via Axios (bawa Bearer) lalu buka di tab baru.
const rekapPrintingSep = ref(null)   // visit_id yang sedang dicetak
async function printSep(row) {
  if (!row.no_sep) { toast('w', 'Kunjungan ini belum punya SEP'); return }
  rekapPrintingSep.value = row.visit_id
  try {
    const res = await api.get(`/admisi/bpjs/cetak-sep/${row.visit_id}`, { responseType: 'blob' })
    const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))
    window.open(url, '_blank')
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal mencetak SEP')
  } finally {
    rekapPrintingSep.value = null
  }
}

// ── Kwitansi ───────────────────────────────────────────────────────────────────
// Ambil HTML kwitansi via Axios (bawa Bearer) lalu cetak di window baru.
async function openKwitansi(row) {
  if (!row.has_invoice) { toast('w', 'Belum ada kwitansi/tagihan untuk kunjungan ini'); return }
  try {
    const { data } = await api.get(`/rekam-medis/kunjungan/${row.visit_id}/kwitansi`)
    const html = data.data?.rendered_html
    if (!html) { toast('w', 'Kwitansi belum tersaji'); return }
    const w = window.open('', '_blank', 'width=900,height=1000')
    if (!w) { toast('w', 'Popup diblokir browser — izinkan popup'); return }
    w.document.open()
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Kwitansi</title>
<style>@page{size:A4;margin:14mm}body{margin:0;font-family:Arial,sans-serif}</style></head><body>${html}</body></html>`)
    w.document.close()
    w.focus()
    setTimeout(() => { try { w.print() } catch (_) { /* user bisa Ctrl+P */ } }, 350)
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

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmtDateTime(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
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
        <p class="rk-sub">Screening data kunjungan pasien BPJS untuk kebutuhan klaim.</p>
      </div>
    </header>

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
      <button class="rk-btn rk-btn-export" :disabled="rekapExporting" @click="exportRekap">
        {{ rekapExporting ? 'Mengekspor…' : 'Export Excel' }}
      </button>
    </div>

    <!-- input file tersembunyi utk upload -->
    <input ref="rekapFileInput" type="file" accept=".pdf,image/*" hidden @change="onRekapFileChange" />

    <!-- Tabel -->
    <div class="rk-table-wrap">
      <table class="rk-table">
        <thead>
          <tr>
            <th>No.</th>
            <th>Peserta</th>
            <th>No SEP</th>
            <th>Tgl SEP / Jenis / Kelas</th>
            <th>DPJP</th>
            <th>Diagnosa</th>
            <th>No Rujukan</th>
            <th>Dokumen Pendukung</th>
            <th>Hasil Penunjang</th>
            <th>Kwitansi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="rekapLoading"><td colspan="10" class="rk-empty">Memuat…</td></tr>
          <tr v-else-if="!rekapRows.length"><td colspan="10" class="rk-empty">Tidak ada kunjungan BPJS pada periode ini.</td></tr>
          <tr v-for="(r, i) in rekapRows" :key="r.visit_id">
            <td class="rk-no">{{ (rekapPage - 1) * rekapPerPage + i + 1 }}</td>
            <td>
              <div class="rk-nama">{{ r.nama || '-' }}</div>
              <small class="rk-rm">RM {{ r.no_rm || '-' }}</small>
              <small class="rk-rm">{{ r.tgl_lahir || '—' }} · {{ r.gender || '—' }}</small>
              <small class="rk-rm">Kartu: {{ r.bpjs_number || '-' }}</small>
            </td>
            <td>
              <button v-if="r.no_sep" class="rk-chip rk-chip-sep" :disabled="rekapPrintingSep === r.visit_id" @click="printSep(r)" title="Lihat / cetak SEP">
                {{ rekapPrintingSep === r.visit_id ? '…' : r.no_sep }}
              </button>
              <span v-else class="rk-muted">-</span>
            </td>
            <td>
              <div>{{ r.tgl_sep || '—' }}</div>
              <small class="rk-rm">{{ r.jenis || '—' }}<span v-if="r.kelas"> · {{ r.kelas }}</span></small>
            </td>
            <td>{{ r.dpjp || '-' }}</td>
            <td class="rk-diag">{{ r.diagnosa || '-' }}</td>
            <td>{{ r.no_rujukan || '-' }}</td>
            <td>
              <div class="rk-doc-cell">
                <button class="rk-chip" :disabled="!r.dokpendukung_count" @click="openDocsModal(r, 'OTHER')">
                  Lihat ({{ r.dokpendukung_count }})
                </button>
                <button class="rk-chip rk-chip-up" :disabled="rekapUploadingId === r.visit_id" @click="pickUpload(r, 'LAINNYA')">
                  {{ rekapUploadingId === r.visit_id ? '…' : 'Upload' }}
                </button>
              </div>
            </td>
            <td>
              <div class="rk-doc-cell">
                <button class="rk-chip" :disabled="!r.penunjang_count" @click="openDocsModal(r, 'PENUNJANG')">
                  Lihat ({{ r.penunjang_count }})
                </button>
                <button class="rk-chip rk-chip-up" :disabled="rekapUploadingId === r.visit_id" @click="pickUpload(r, 'PENUNJANG')">
                  {{ rekapUploadingId === r.visit_id ? '…' : 'Upload' }}
                </button>
              </div>
            </td>
            <td>
              <button v-if="r.has_invoice" class="rk-chip rk-chip-kw" :class="{ unpaid: !r.is_paid }" @click="openKwitansi(r)">
                {{ r.is_paid ? 'Kwitansi' : 'Belum Lunas' }}
              </button>
              <span v-else class="rk-muted">-</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Pager v-model:page="rekapPage" :last-page="rekapLast" :total="rekapTotal" @change="onRekapPage" />

    <!-- Modal daftar berkas -->
    <div v-if="docModalOpen" class="rk-modal-bg" @click.self="closeDocsModal">
      <div class="rk-modal">
        <div class="rk-modal-head">
          <h3>{{ docModalCat === 'PENUNJANG' ? 'Hasil Penunjang' : 'Dokumen Pendukung' }}</h3>
          <button class="rk-x" @click="closeDocsModal">✕</button>
        </div>
        <div class="rk-modal-sub">{{ docModalRow?.nama }} · SEP {{ docModalRow?.no_sep || '-' }}</div>

        <div class="rk-modal-body">
          <p v-if="docModalLoading" class="rk-empty">Memuat…</p>
          <p v-else-if="!docModalList.length" class="rk-empty">Belum ada berkas.</p>
          <ul v-else class="rk-doc-list">
            <li v-for="a in docModalList" :key="a.id">
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

        <div class="rk-modal-foot">
          <button class="rk-btn rk-btn-export" @click="pickUpload(docModalRow, docModalCat === 'PENUNJANG' ? 'PENUNJANG' : 'LAINNYA')">
            Upload berkas baru
          </button>
        </div>
      </div>
    </div>

    <!-- Toast -->
    <div class="toast-wrap" role="status" aria-live="polite" aria-atomic="false" aria-label="Notifikasi sistem">
      <div v-for="t in toasts" :key="t.id" :class="['toast', `toast-${t.type}`]">{{ t.msg }}</div>
    </div>
  </div>
</template>

<style scoped>
.rk-page { padding: 18px 22px; max-width: 1320px; margin: 0 auto; }
.rk-head { margin-bottom: 14px; }
.rk-head h1 { font-size: 19px; font-weight: 700; color: var(--td); margin: 0; }
.rk-sub { font-size: 12.5px; color: var(--tu); margin: 4px 0 0; }

.rk-toolbar { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.rk-mode { display: inline-flex; border: 1px solid var(--gb); border-radius: 9px; overflow: hidden; }
.rk-seg { padding: 7px 14px; border: 0; background: var(--bc); cursor: pointer; font-size: 12.5px; color: var(--tu); }
.rk-seg.a { background: var(--ga); color: #fff; font-weight: 600; }
.rk-dash { color: var(--tu); font-size: 12px; }
.rk-toolbar input[type="date"], .rk-search {
  height: 36px; padding: 0 10px; border: 1px solid var(--gb); border-radius: 8px;
  background: var(--bc); color: var(--td); font-size: 12.5px;
}
.rk-search { min-width: 250px; flex: 0 1 280px; }
.rk-btn {
  height: 36px; padding: 0 16px; border: 1px solid var(--gb); border-radius: 8px;
  background: var(--bc); color: var(--td); cursor: pointer; font-size: 12.5px; font-weight: 600;
}
.rk-btn:hover:not(:disabled) { background: var(--gl); }
.rk-btn-export { background: var(--ga); color: #fff; border-color: var(--ga); }
.rk-btn-export:disabled { opacity: 0.6; cursor: not-allowed; }
.rk-spacer { flex: 1 1 auto; }
.rk-count { font-size: 12px; color: var(--tu); }
.rk-pp { height: 36px; padding: 0 8px; border: 1px solid var(--gb); border-radius: 8px; background: var(--bc); color: var(--td); font-size: 12px; cursor: pointer; }

.rk-table-wrap { border: 1px solid var(--gb); border-radius: 12px; overflow-x: auto; background: var(--bc); }
.rk-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.rk-table th, .rk-table td { padding: 9px 12px; text-align: left; border-bottom: 1px solid var(--gb); vertical-align: top; white-space: nowrap; }
.rk-table th { background: var(--gl); color: var(--tu); font-weight: 600; position: sticky; top: 0; }
.rk-table tr:last-child td { border-bottom: 0; }
.rk-no { color: var(--tu); text-align: center; font-variant-numeric: tabular-nums; }
.rk-nama { font-weight: 600; color: var(--td); }
.rk-rm { color: var(--tu); font-size: 11px; display: block; }
.rk-chip-sep { color: var(--ga); border-color: var(--ga); font-weight: 600; font-family: inherit; }
.rk-diag { white-space: normal; max-width: 240px; }
.rk-empty { text-align: center; color: var(--tu); padding: 26px 12px !important; }
.rk-muted { color: var(--tu); }

.rk-doc-cell { display: flex; gap: 4px; }
.rk-chip {
  padding: 5px 9px; border: 1px solid var(--gb); border-radius: 7px; background: var(--bc);
  cursor: pointer; font-size: 11.5px; color: var(--td); white-space: nowrap;
}
.rk-chip:hover:not(:disabled) { background: var(--gl); border-color: var(--ga); }
.rk-chip:disabled { opacity: 0.45; cursor: not-allowed; }
.rk-chip-up { color: var(--ga); border-color: var(--ga); }
.rk-chip-del { color: var(--et); border-color: var(--ebd); }
.rk-chip-kw { color: var(--ga); border-color: var(--ga); font-weight: 600; }
.rk-chip-kw.unpaid { color: var(--wt); border-color: var(--wbd); background: var(--wb); }

/* Modal */
.rk-modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 16px; }
.rk-modal { background: var(--bc); border-radius: 14px; width: 100%; max-width: 540px; max-height: 84vh; display: flex; flex-direction: column; box-shadow: 0 12px 40px rgba(0,0,0,0.25); }
.rk-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px 6px; }
.rk-modal-head h3 { margin: 0; font-size: 15px; font-weight: 700; color: var(--td); }
.rk-x { border: 0; background: none; cursor: pointer; font-size: 16px; color: var(--tu); }
.rk-modal-sub { padding: 0 18px 10px; font-size: 12px; color: var(--tu); border-bottom: 1px solid var(--gb); }
.rk-modal-body { padding: 12px 18px; overflow-y: auto; flex: 1 1 auto; }
.rk-doc-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.rk-doc-list li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 10px; border: 1px solid var(--gb); border-radius: 9px; }
.rk-doc-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.rk-doc-name { font-weight: 600; font-size: 12.5px; color: var(--td); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rk-doc-info small { color: var(--tu); font-size: 11px; }
.rk-doc-act { display: flex; gap: 5px; flex-shrink: 0; }
.rk-modal-foot { padding: 10px 18px 16px; border-top: 1px solid var(--gb); }

/* Toast */
.toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 9999; display: flex; flex-direction: column; gap: 6px; pointer-events: none; }
.toast { padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 14px rgba(0,0,0,0.1); min-width: 240px; }
.toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.toast-i { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }
</style>
