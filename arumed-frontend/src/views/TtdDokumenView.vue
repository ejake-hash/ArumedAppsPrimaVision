<script setup>
/**
 * TtdDokumenView — halaman antrian TTD dokumen untuk dokter yang login.
 *
 * Backend (GET /rekam-medis/ttd-queue) kini mengembalikan PAGINATOR terfilter
 * di SQL (skalabel 100+/hari): tiap item sudah FLAT (1 baris = 1 dokumen yang
 * menunggu TTD dokter ini), dengan meta pagination. Search & status difilter di
 * server (debounce).
 *
 * Filter server: dokumen status DRAFT/RENDERED/PENDING_SIGNATURE yang punya field
 * signature_canvas signer_type='doctor' dan belum di-TTD oleh dokter ini.
 *
 * Alur baru: centang dokumen siap → "Telaah & Tandatangani (N)" → telaah
 * berurutan (wajib buka semua) → 1× PIN → stempel + QR (BulkTtdReviewModal).
 * Single-sign (1 baris) kini juga via PIN, bukan goresan canvas.
 *
 * UI mengikuti gaya Bridging · Satu Sehat (kartu rounded, tombol .btn, header
 * tabel uppercase, palet Prima Navy + Vision Sky).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { formTemplateApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'
import LayoutEditor from '@/components/master/form-template/LayoutEditor.vue'
import BulkTtdReviewModal from '@/components/forms/BulkTtdReviewModal.vue'

// Dokter anestesi melihat antrean TTD-nya sendiri (backend resolve signer_type
// dari role). Judul disesuaikan agar jelas konteks penandatangan.
const auth = useAuthStore()
const isAnestesi = computed(() => auth.roleName === 'dokter_anestesi')

// Tab aktif: 'queue' (antrian belum-TTD) | 'signed' (sudah-TTD hari ini).
const activeTab = ref('queue')

const rows    = ref([])
const loading = ref(false)
const error   = ref('')

// Pagination server-side (tab Antrian)
const perPage = ref(10)
const page    = ref(1)
const meta    = ref({ total: 0, last_page: 1, current_page: 1 })

// Tab "Ditandatangani hari ini" — state terpisah (rows/pagination sendiri).
const signedRows    = ref([])
const signedLoading = ref(false)
const signedError   = ref('')
const signedPage    = ref(1)
const signedMeta    = ref({ total: 0, last_page: 1, current_page: 1 })

// Filter server-side
const search       = ref('')
const statusFilter = ref('ALL') // ALL | DRAFT | PENDING (RENDERED+PENDING_SIGNATURE)

// Filter TANGGAL KUNJUNGAN (hanya tab Antrian — tab "ditandatangani hari ini"
// memang sudah ber-scope hari ini). Default kosong = semua tanggal (perilaku lama).
// Mode 'single' = 1 tanggal (from=to); 'range' = rentang from..to.
const dateMode   = ref('single')
const dateSingle = ref('')
const dateFrom   = ref('')
const dateTo     = ref('')
const hasDateFilter = computed(() =>
  dateMode.value === 'single' ? !!dateSingle.value : (!!dateFrom.value || !!dateTo.value),
)
// Param backend (date_from/date_to). Undefined bila tak diisi → tak ikut dikirim.
function dateParams() {
  if (dateMode.value === 'single') {
    const d = dateSingle.value || undefined
    return { date_from: d, date_to: d }
  }
  return { date_from: dateFrom.value || undefined, date_to: dateTo.value || undefined }
}

// Seleksi baris (centang). Simpan OBJEK dokumen (bukan sekadar id) supaya
// seleksi BERTAHAN lintas-halaman — bulk modal butuh data lengkap tiap doc
// (template_code, visit_id, patient) walau dokumen sudah tak di halaman aktif.
const selectedMap = ref(new Map()) // id → row

// Preview single-doc + edit DRAFT + PIN
const modalOpen      = ref(false)
const currentDoc     = ref(null)
const previewHtml    = ref('')
const previewLoading = ref(false)
const editMode   = ref(false)
const editHtml   = ref('')
const editSaving = ref(false)
const isDraft    = computed(() => currentDoc.value?.status === 'DRAFT')

// PIN single-sign
const pinModalOpen = ref(false)
const pinValue     = ref('')
const pinError     = ref('')
const pinBusy      = ref(false)

// Revisi (koreksi post-FINALIZED) — dari modal "Lihat" dokumen yang sudah TTD.
// Pola: generate dokumen VERSI BARU (otomatis terkoreksi dari data terkini) →
// TTD ulang; versi lama jadi riwayat (SUPERSEDED). Bukan edit isi / catatan.
const revisiOpen   = ref(false)
const revisiAlasan = ref('')
const revisiBusy   = ref(false)
const revisiError  = ref('')

// Bulk review modal
const bulkOpen = ref(false)
const ttdCount = ref(0)          // total dokumen menunggu TTD (kartu + badge)
const signedTodayCount = ref(0)  // total ditandatangani hari ini (kartu)

// RENDERED + PENDING_SIGNATURE → satu label "Menunggu TTD". DRAFT terpisah
// sebagai sinyal isi belum final.
const STATUS_META = {
  DRAFT:             { label: 'Draf',         cls: 'st-draft'   },
  RENDERED:          { label: 'Menunggu TTD', cls: 'st-pending' },
  PENDING_SIGNATURE: { label: 'Menunggu TTD', cls: 'st-pending' },
}
function statusMeta(s) {
  return STATUS_META[s] ?? { label: s, cls: 'st-default' }
}

function rowDate(r) { return r.visit_date ?? r.created_at ?? null }
function patientName(r) { return r.patient?.name ?? '—' }
function patientRm(r)   { return r.patient?.no_rm ?? null }
function patientGender(r) { return r.patient?.gender ?? null }

// Pagination tab-aware: tab Antrian pakai meta/page, tab Signed pakai signedMeta/signedPage.
const isSignedTab = computed(() => activeTab.value === 'signed')
const curMeta = computed(() => (isSignedTab.value ? signedMeta.value : meta.value))
const curPage = computed(() => (isSignedTab.value ? signedPage.value : page.value))
const totalPages = computed(() => Math.max(1, curMeta.value.last_page ?? 1))
const total      = computed(() => curMeta.value.total ?? 0)
const rangeStart = computed(() => (total.value === 0 ? 0 : (curPage.value - 1) * perPage.value + 1))
const rangeEnd   = computed(() => Math.min(curPage.value * perPage.value, total.value))
const hasActiveFilter = computed(() =>
  search.value.trim()
  || (!isSignedTab.value && statusFilter.value !== 'ALL')
  || (!isSignedTab.value && hasDateFilter.value),
)

// Halaman yang ditampilkan di pager (jendela ringkas di sekitar halaman aktif).
const pageWindow = computed(() => {
  const last = totalPages.value
  const cur = curPage.value
  const span = 2
  let from = Math.max(1, cur - span)
  let to = Math.min(last, cur + span)
  if (cur <= span) to = Math.min(last, 1 + span * 2)
  if (cur > last - span) from = Math.max(1, last - span * 2)
  const out = []
  for (let i = from; i <= to; i++) out.push(i)
  return out
})

// ── Seleksi ──────────────────────────────────────────────────────────
function isSelected(id) { return selectedMap.value.has(id) }
function toggleSelect(row) {
  const m = new Map(selectedMap.value)
  m.has(row.id) ? m.delete(row.id) : m.set(row.id, row)
  selectedMap.value = m
}
const allPageSelected = computed(() =>
  rows.value.length > 0 && rows.value.every((r) => selectedMap.value.has(r.id)),
)
function toggleSelectPage() {
  const m = new Map(selectedMap.value)
  if (allPageSelected.value) {
    rows.value.forEach((r) => m.delete(r.id))
  } else {
    rows.value.forEach((r) => m.set(r.id, r))
  }
  selectedMap.value = m
}
function clearSelection() { selectedMap.value = new Map() }
const selectedCount = computed(() => selectedMap.value.size)
// Dokumen terpilih DI SELURUH HALAMAN (bukan cuma halaman aktif) — modal bulk
// menandatangani persis sebanyak yang tertera di "{{ selectedCount }} dipilih".
const selectedDocs = computed(() => Array.from(selectedMap.value.values()))

// ── Filter & pagination control ──────────────────────────────────────
// Loader & page-ref aktif menyesuaikan tab — satu set kontrol pager melayani keduanya.
function loadActive() { return isSignedTab.value ? loadSigned() : load() }
function setPage(n) { if (isSignedTab.value) signedPage.value = n; else page.value = n }
function resetPage() { setPage(1) }
function clearFilters() {
  search.value = ''
  statusFilter.value = 'ALL'
  dateSingle.value = ''
  dateFrom.value = ''
  dateTo.value = ''
  resetPage()
  loadActive()
}

// Ubah tanggal/rentang kunjungan → reset halaman & muat ulang antrian.
// (Filter tanggal hanya tab Antrian, jadi selalu load().)
function onDateChange() { resetPage(); load() }
function setDateMode(m) {
  if (dateMode.value === m) return
  dateMode.value = m
  // Reload hanya bila sudah ada nilai tanggal aktif (hindari query sia-sia).
  if (hasDateFilter.value) { resetPage(); load() }
}
function goPrev() { if (curPage.value > 1) { setPage(curPage.value - 1); loadActive() } }
function goNext() { if (curPage.value < totalPages.value) { setPage(curPage.value + 1); loadActive() } }
function goPage(n) { if (n >= 1 && n <= totalPages.value) { setPage(n); loadActive() } }
function changePerPage() { resetPage(); loadActive() }

// Pindah tab: reset halaman, muat data tab tujuan jika belum ada.
function switchTab(tab) {
  if (activeTab.value === tab) return
  activeTab.value = tab
  if (tab === 'signed') {
    if (signedRows.value.length === 0) { signedPage.value = 1; loadSigned() }
  } else {
    load()
  }
}

// Map filter UI → param status backend (whitelist 3 status).
function statusParam() {
  if (statusFilter.value === 'DRAFT') return 'DRAFT'
  if (statusFilter.value === 'PENDING') return 'PENDING_SIGNATURE'
  return undefined
}

let searchTimer = null
function onSearchInput() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { resetPage(); loadActive() }, 350)
}
function onStatusChange() { resetPage(); load() }

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await formTemplateApi.ttdQueue({
      page: page.value,
      per_page: perPage.value,
      search: search.value.trim() || undefined,
      status: statusParam(),
      ...dateParams(),
    })
    // ok() membungkus paginator: data.data = {data:[...rows], total, last_page, current_page,...}
    const p = data.data ?? {}
    rows.value = p.data ?? []
    meta.value = {
      total: p.total ?? 0,
      last_page: p.last_page ?? 1,
      current_page: p.current_page ?? 1,
    }
    if (page.value > totalPages.value) { page.value = 1 }
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Gagal memuat antrian.'
  } finally {
    loading.value = false
  }
}

async function loadSigned() {
  signedLoading.value = true
  signedError.value = ''
  try {
    const { data } = await formTemplateApi.ttdSignedToday({
      page: signedPage.value,
      per_page: perPage.value,
      search: search.value.trim() || undefined,
    })
    const p = data.data ?? {}
    signedRows.value = p.data ?? []
    signedMeta.value = {
      total: p.total ?? 0,
      last_page: p.last_page ?? 1,
      current_page: p.current_page ?? 1,
    }
    if (signedPage.value > (signedMeta.value.last_page ?? 1)) { signedPage.value = 1 }
  } catch (e) {
    signedError.value = e.response?.data?.message ?? 'Gagal memuat riwayat tanda tangan.'
  } finally {
    signedLoading.value = false
  }
}

async function refreshCount() {
  try {
    const { data } = await formTemplateApi.ttdCount()
    ttdCount.value = data.data?.count ?? 0
    signedTodayCount.value = data.data?.signed_today ?? 0
  } catch (_) { /* abaikan — badge opsional */ }
}

// Preview read-only (dokumen dibuka dari tab "sudah ditandatangani").
const previewReadonly = ref(false)

// ── Single-doc modal (preview + edit + sign PIN) ─────────────────────
async function openTtd(doc, readonly = false) {
  currentDoc.value = doc
  previewReadonly.value = readonly
  modalOpen.value = true
  previewHtml.value = ''
  previewLoading.value = true
  try {
    const { data } = await formTemplateApi.snapshot(doc.id)
    previewHtml.value = data.data?.rendered_html ?? ''
    if (!previewHtml.value && doc.template_code && doc.visit_id) {
      const r = await formTemplateApi.renderForm(doc.template_code, doc.visit_id)
      previewHtml.value = r.data.data?.html ?? ''
    }
  } catch (e) {
    previewHtml.value = '<p style="color:#b42323">(Gagal render preview)</p>'
  } finally {
    previewLoading.value = false
  }
}

function closeModal() {
  modalOpen.value = false
  currentDoc.value = null
  previewReadonly.value = false
  previewHtml.value = ''
  editMode.value = false
  editHtml.value = ''
  pinModalOpen.value = false
  pinValue.value = ''
  pinError.value = ''
  revisiOpen.value = false
  revisiAlasan.value = ''
  revisiError.value = ''
}

function startEdit() {
  editHtml.value = previewHtml.value
  editMode.value = true
}
function cancelEdit() {
  editMode.value = false
  editHtml.value = ''
}
async function saveEdit() {
  if (!currentDoc.value) return
  editSaving.value = true
  error.value = ''
  try {
    const { data } = await formTemplateApi.saveDraftContent(currentDoc.value.id, editHtml.value)
    previewHtml.value = data.data?.rendered_html ?? editHtml.value
    editMode.value = false
  } catch (e) {
    error.value = 'Gagal menyimpan isi: ' + (e.response?.data?.message ?? e.message)
  } finally {
    editSaving.value = false
  }
}

// Single-sign via PIN → stempel + QR (konsisten dgn bulk).
function openPin() {
  pinValue.value = ''
  pinError.value = ''
  pinModalOpen.value = true
}
async function submitPin() {
  if (!currentDoc.value) return
  const pin = pinValue.value.trim()
  if (!/^\d{4,6}$/.test(pin)) { pinError.value = 'PIN harus 4–6 digit angka.'; return }
  pinBusy.value = true
  pinError.value = ''
  try {
    // Reuse endpoint bulk untuk 1 dokumen → otomatis capture PIN + finalize +
    // stempel + QR. Hasil dipetakan ke pesan ramah.
    const { data } = await formTemplateApi.bulkSign([currentDoc.value.id], pin)
    const res = data.data ?? {}
    if ((res.failed ?? []).length) {
      pinError.value = res.failed[0]?.error ?? 'Gagal menandatangani.'
      return
    }
    pinModalOpen.value = false
    closeModal()
    signedRows.value = [] // invalidasi tab signed → refetch saat dibuka
    await Promise.all([load(), refreshCount()])
  } catch (e) {
    pinError.value = e.response?.status === 401
      ? 'PIN tidak sesuai.'
      : (e.response?.data?.message ?? 'Gagal menandatangani.')
  } finally {
    pinBusy.value = false
  }
}

// ── Revisi (koreksi post-FINALIZED via generate ulang + TTD ulang) ────
// Buat dokumen versi baru (otomatis terkoreksi dari data terkini), versi lama
// jadi riwayat (SUPERSEDED). Dokumen baru masuk antrian TTD → dokter TTD ulang.
function openRevisi() {
  revisiAlasan.value = ''
  revisiError.value = ''
  revisiOpen.value = true
}
async function submitRevisi() {
  if (!currentDoc.value) return
  const alasan = revisiAlasan.value.trim()
  if (!alasan) { revisiError.value = 'Alasan revisi wajib diisi.'; return }
  revisiBusy.value = true
  revisiError.value = ''
  try {
    await formTemplateApi.reviseDocument(currentDoc.value.id, { alasan })
    revisiOpen.value = false
    closeModal()
    signedRows.value = [] // invalidasi tab signed (dokumen lama jadi SUPERSEDED)
    activeTab.value = 'queue'
    await Promise.all([load(), refreshCount()]) // versi baru muncul di Antrian
  } catch (e) {
    revisiError.value = e.response?.data?.message ?? 'Gagal membuat revisi.'
  } finally {
    revisiBusy.value = false
  }
}

// ── Bulk ─────────────────────────────────────────────────────────────
function openBulk() {
  if (selectedCount.value === 0) return
  bulkOpen.value = true
}
async function onBulkDone(payload) {
  // payload.signedIds = id yang sukses (FINALIZED). Buang dari seleksi & rows
  // (optimistic) lalu refresh count + reload halaman.
  const done = new Set(payload?.signedIds ?? [])
  if (done.size) {
    const m = new Map(selectedMap.value)
    done.forEach((id) => m.delete(id))
    selectedMap.value = m
  }
  bulkOpen.value = false
  signedRows.value = [] // invalidasi tab signed → refetch saat dibuka
  await Promise.all([load(), refreshCount()])
}

function formatDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  // Paksa zona WIB agar konsisten lintas mesin.
  return d.toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'Asia/Jakarta' })
}

function formatTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta' })
}

onMounted(() => { load(); refreshCount() })
</script>

<template>
  <div class="ttd">
    <!-- Banner -->
    <section class="banner">
      <div class="b-body">
        <h1>{{ isAnestesi ? 'Tanda Tangan Dokumen — Dokter Anestesi' : 'Tanda Tangan Dokumen' }}</h1>
        <p class="b-sub">Telaah, tandatangani, dan pantau dokumen rekam medis Anda.</p>
      </div>
      <button class="lnk" :disabled="loading || signedLoading" @click="loadActive">
        {{ (loading || signedLoading) ? 'Memuat…' : 'Muat ulang' }}
      </button>
    </section>

    <!-- Kartu statistik -->
    <section class="stats">
      <div class="stat-card stat-pending" :class="{ active: activeTab === 'queue' }" @click="switchTab('queue')">
        <div class="stat-ico" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/></svg>
        </div>
        <div class="stat-body">
          <span class="stat-num">{{ ttdCount }}</span>
          <span class="stat-lbl">Menunggu tanda tangan{{ isAnestesi ? ' anestesi' : '' }}</span>
        </div>
      </div>
      <div class="stat-card stat-done" :class="{ active: activeTab === 'signed' }" @click="switchTab('signed')">
        <div class="stat-ico" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <div class="stat-body">
          <span class="stat-num">{{ signedTodayCount }}</span>
          <span class="stat-lbl">Ditandatangani hari ini</span>
        </div>
      </div>
    </section>

    <!-- Tabs -->
    <div class="tabs" role="tablist">
      <button
        class="tab" :class="{ on: activeTab === 'queue' }"
        role="tab" :aria-selected="activeTab === 'queue'"
        @click="switchTab('queue')"
      >
        Antrian
        <span v-if="ttdCount > 0" class="tab-badge">{{ ttdCount }}</span>
      </button>
      <button
        class="tab" :class="{ on: activeTab === 'signed' }"
        role="tab" :aria-selected="activeTab === 'signed'"
        @click="switchTab('signed')"
      >
        Ditandatangani hari ini
        <span v-if="signedTodayCount > 0" class="tab-badge tab-badge-done">{{ signedTodayCount }}</span>
      </button>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <input
        v-model="search"
        @input="onSearchInput"
        type="text"
        :placeholder="isSignedTab ? 'Cari pasien, No.RM, atau jenis dokumen' : 'Cari pasien, No.RM, atau jenis dokumen'"
        class="search-inp"
      />
      <select v-if="!isSignedTab" v-model="statusFilter" @change="onStatusChange" class="sel">
        <option value="ALL">Semua status</option>
        <option value="PENDING">Menunggu TTD</option>
        <option value="DRAFT">Draf</option>
      </select>

      <!-- Filter tanggal KUNJUNGAN (1 tanggal / rentang) — hanya tab Antrian -->
      <div v-if="!isSignedTab" class="dtfilter" title="Saring berdasarkan tanggal kunjungan">
        <div class="dt-seg">
          <button type="button" :class="['dt-seg-btn', { on: dateMode === 'single' }]" @click="setDateMode('single')">1 Tanggal</button>
          <button type="button" :class="['dt-seg-btn', { on: dateMode === 'range' }]" @click="setDateMode('range')">Rentang</button>
        </div>
        <template v-if="dateMode === 'single'">
          <input type="date" class="dt-input" v-model="dateSingle" @change="onDateChange" />
        </template>
        <template v-else>
          <input type="date" class="dt-input" v-model="dateFrom" @change="onDateChange" />
          <span class="dt-dash">s/d</span>
          <input type="date" class="dt-input" v-model="dateTo" @change="onDateChange" />
        </template>
      </div>

      <button v-if="hasActiveFilter" class="lnk" @click="clearFilters">Reset</button>
    </div>

    <!-- Bar aksi massal (hanya tab Antrian) -->
    <div v-if="!isSignedTab && selectedCount > 0" class="bulkbar">
      <span class="bulk-info">{{ selectedCount }} dipilih</span>
      <button class="btn" @click="openBulk">Telaah &amp; tandatangani</button>
      <button class="lnk" @click="clearSelection">Batal</button>
    </div>

    <!-- Tabel: ANTRIAN -->
    <div v-if="!isSignedTab" class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:36px" class="ta-center">
              <input type="checkbox" class="chk" :checked="allPageSelected" @change="toggleSelectPage" title="Pilih semua" />
            </th>
            <th>Pasien</th>
            <th>Review Dokter</th>
            <th>Jenis Dokumen</th>
            <th style="width:110px">Tanggal</th>
            <th style="width:120px">Status</th>
            <th style="width:60px" class="ta-right"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading && rows.length === 0">
            <td colspan="7" class="cell-state">Memuat antrian…</td>
          </tr>
          <tr v-else-if="error">
            <td colspan="7" class="cell-state err">{{ error }}</td>
          </tr>
          <tr v-else-if="total === 0">
            <td colspan="7" class="cell-state">
              {{ hasActiveFilter ? 'Tidak ada dokumen yang cocok.' : 'Antrian tanda tangan kosong.' }}
            </td>
          </tr>
          <tr v-for="r in rows" :key="r.id" :class="{ 'row-sel': isSelected(r.id) }">
            <td class="ta-center">
              <input type="checkbox" class="chk" :checked="isSelected(r.id)" @change="toggleSelect(r)" />
            </td>
            <td>
              <div class="patient">
                <span class="p-name">{{ patientName(r) }}</span>
                <span class="rm">No.RM {{ patientRm(r) ?? '—' }} · {{ patientGender(r) || '—' }}</span>
              </div>
            </td>
            <td class="muted">{{ r.review_doctor ?? '—' }}</td>
            <td>{{ r.template_name ?? r.template_code }}</td>
            <td class="muted td-date">{{ formatDate(rowDate(r)) }}</td>
            <td>
              <span class="st" :class="statusMeta(r.status).cls">{{ statusMeta(r.status).label }}</span>
            </td>
            <td class="ta-right">
              <button class="lnk" @click="openTtd(r)">Buka</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Tabel: DITANDATANGANI HARI INI -->
    <div v-else class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Pasien</th>
            <th>Jenis Dokumen</th>
            <th style="width:120px">Waktu TTD</th>
            <th style="width:120px">Status</th>
            <th style="width:60px" class="ta-right"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="signedLoading && signedRows.length === 0">
            <td colspan="5" class="cell-state">Memuat riwayat…</td>
          </tr>
          <tr v-else-if="signedError">
            <td colspan="5" class="cell-state err">{{ signedError }}</td>
          </tr>
          <tr v-else-if="total === 0">
            <td colspan="5" class="cell-state">
              {{ hasActiveFilter ? 'Tidak ada dokumen yang cocok.' : 'Belum ada dokumen yang ditandatangani hari ini.' }}
            </td>
          </tr>
          <tr v-for="r in signedRows" :key="r.signature_id">
            <td>
              <div class="patient">
                <span class="p-name">{{ patientName(r) }}</span>
                <span class="rm">No.RM {{ patientRm(r) ?? '—' }} · {{ patientGender(r) || '—' }}</span>
              </div>
            </td>
            <td>{{ r.template_name ?? r.template_code }}</td>
            <td class="muted td-date">{{ formatTime(r.signed_at) }} WIB</td>
            <td><span class="st st-signed">✓ Ditandatangani</span></td>
            <td class="ta-right">
              <button class="lnk" @click="openTtd(r, true)">Lihat</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <footer v-if="total > 0" class="foot">
      <span class="foot-info">{{ rangeStart }}–{{ rangeEnd }} dari {{ total }}</span>
      <div class="pager">
        <button class="page-btn" @click="goPrev" :disabled="curPage <= 1">‹</button>
        <button
          v-for="n in pageWindow"
          :key="n"
          class="page-num"
          :class="{ on: n === curPage }"
          @click="goPage(n)"
        >{{ n }}</button>
        <button class="page-btn" @click="goNext" :disabled="curPage >= totalPages">›</button>
      </div>
    </footer>

    <!-- Preview modal -->
    <Teleport to="body">
      <div v-if="modalOpen && currentDoc" class="pv-overlay" @click.self="closeModal">
        <div class="pv-modal">
          <header class="pv-head">
            <div>
              <h3>{{ currentDoc.template_name ?? currentDoc.template_code }}</h3>
              <p class="pv-sub">{{ patientName(currentDoc) }} · {{ statusMeta(currentDoc.status).label }}</p>
            </div>
            <button class="pv-close" @click="closeModal" title="Tutup">×</button>
          </header>
          <div class="pv-body">
            <div v-if="previewLoading" class="cell-state">Memuat preview…</div>
            <template v-else>
              <div v-if="!editMode" v-html="previewHtml"></div>
              <div v-else class="edit-wrap">
                <p class="edit-hint">
                  Edit isi dokumen (DRAFT). Perubahan menimpa isi dokumen ini dan
                  lepas dari data rekam medis.
                </p>
                <LayoutEditor v-model="editHtml" />
              </div>
            </template>
          </div>
          <footer class="pv-foot">
            <template v-if="previewReadonly">
              <span class="pv-signed">✓ Sudah ditandatangani</span>
              <button class="btn btn-ghost" @click="openRevisi">Revisi &amp; TTD Ulang</button>
              <button class="btn" @click="closeModal">Tutup</button>
            </template>
            <template v-else-if="editMode">
              <button class="lnk" @click="cancelEdit" :disabled="editSaving">Batal</button>
              <button class="btn" @click="saveEdit" :disabled="editSaving">
                {{ editSaving ? 'Menyimpan…' : 'Simpan' }}
              </button>
            </template>
            <template v-else>
              <button v-if="isDraft" class="btn btn-ghost" @click="startEdit">Edit isi</button>
              <button class="btn btn-blue" @click="openPin">Tanda tangani</button>
            </template>
          </footer>
        </div>
      </div>
    </Teleport>

    <!-- PIN single-sign -->
    <Teleport to="body">
      <div v-if="pinModalOpen" class="pin-overlay" @click.self="pinModalOpen = false">
        <div class="pin-modal">
          <h4 class="pin-title">Tanda Tangan Elektronik</h4>
          <p class="pin-sub">{{ currentDoc?.template_name ?? currentDoc?.template_code }}</p>
          <p class="pin-hint">Masukkan PIN tanda tangan Anda untuk membubuhkan stempel digital + QR.</p>
          <input
            v-model="pinValue"
            type="password"
            inputmode="numeric"
            maxlength="6"
            class="pin-input"
            placeholder="••••••"
            autocomplete="off"
            @keyup.enter="submitPin()"
          />
          <div v-if="pinError" class="pin-err">{{ pinError }}</div>
          <div class="pin-actions">
            <button type="button" class="lnk" :disabled="pinBusy" @click="pinModalOpen = false">Batal</button>
            <button type="button" class="btn" :disabled="pinBusy" @click="submitPin()">
              {{ pinBusy ? 'Memproses…' : 'Tanda Tangani' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Revisi (koreksi post-FINALIZED via generate ulang + TTD ulang) -->
    <Teleport to="body">
      <div v-if="revisiOpen" class="pin-overlay" @click.self="revisiOpen = false">
        <div class="add-modal">
          <h4 class="pin-title">Revisi &amp; Tanda Tangan Ulang</h4>
          <p class="pin-sub">{{ currentDoc?.template_name ?? currentDoc?.template_code }}</p>
          <p class="add-hint">
            Sistem akan membuat <strong>dokumen versi baru</strong> yang otomatis
            terkoreksi dari data terkini. Tanda tangan lama dibatalkan dan dokumen
            baru masuk antrian untuk <strong>ditandatangani ulang</strong>. Versi lama
            tetap disimpan sebagai riwayat.
          </p>
          <label class="add-label">Alasan revisi</label>
          <input v-model="revisiAlasan" type="text" class="add-input" placeholder="mis. koreksi diagnosa / data pemeriksaan" @keyup.enter="submitRevisi" />
          <div v-if="revisiError" class="pin-err">{{ revisiError }}</div>
          <div class="pin-actions">
            <button type="button" class="lnk" :disabled="revisiBusy" @click="revisiOpen = false">Batal</button>
            <button type="button" class="btn" :disabled="revisiBusy" @click="submitRevisi">
              {{ revisiBusy ? 'Memproses…' : 'Buat Versi Baru' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Bulk telaah + tandatangani -->
    <BulkTtdReviewModal
      v-if="bulkOpen"
      :documents="selectedDocs"
      @close="bulkOpen = false"
      @done="onBulkDone"
    />
  </div>
</template>

<style scoped>
/* Palet Prima Vision: navy #0E3A66 + cyan #1FAAE0 (lihat reference design tokens). */
.ttd {
  --ink: #1f2937; --muted: #6b7280; --faint: #9ca3af;
  --line: #e5e7eb; --accent: #0e3a66; --cyan: #1faae0; --danger: #b42323;
  --done: #0f9d6b;
  display: flex; flex-direction: column; gap: 1rem;
  color: var(--ink); font-size: 13px;
}

/* ── HEADER ───────────────────────────────────────────────────────── */
.banner { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; }

/* ─── Tablet/HP: header & bulk-bar menumpuk; tabel sudah scroll via .tbl-wrap ── */
@media (max-width: 768px) {
  .banner { flex-direction: column; align-items: flex-start; }
  .bulkbar { flex-wrap: wrap; gap: 0.5rem; }
  .stats { grid-template-columns: 1fr; }
}
.b-body h1 { margin: 0; font-size: 20px; font-weight: 600; letter-spacing: -0.01em; }
.b-sub { margin: 4px 0 0; font-size: 13px; color: var(--muted); }

/* ── KARTU STATISTIK (ramping) ────────────────────────────────────── */
.stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.6rem; }
.stat-card {
  display: flex; align-items: center; gap: 0.6rem; padding: 0.6rem 0.8rem;
  border: 1px solid var(--line); border-radius: 8px; background: #fff;
  cursor: pointer; transition: border-color .15s, background .15s;
}
.stat-card:hover { background: #fafbfc; }
.stat-card.active { border-color: var(--accent); }
.stat-ico {
  flex: none; width: 28px; height: 28px; border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
}
.stat-ico svg { width: 16px; height: 16px; }
.stat-pending .stat-ico { background: rgba(31,170,224,.12); color: var(--cyan); }
.stat-done .stat-ico    { background: rgba(15,157,107,.12); color: var(--done); }
.stat-body { display: flex; align-items: baseline; gap: 0.4rem; }
.stat-num { font-size: 17px; font-weight: 600; letter-spacing: -0.01em; color: var(--accent); }
.stat-done .stat-num { color: var(--done); }
.stat-lbl { font-size: 12px; color: var(--muted); }

/* ── TABS ─────────────────────────────────────────────────────────── */
.tabs { display: flex; gap: 0.25rem; border-bottom: 1px solid var(--line); }
.tab {
  position: relative; border: 0; background: none; cursor: pointer;
  padding: 0.6rem 0.9rem; font-size: 13px; font-weight: 500; color: var(--muted);
  display: inline-flex; align-items: center; gap: 0.45rem; border-bottom: 2px solid transparent;
  margin-bottom: -1px;
}
.tab:hover { color: var(--ink); }
.tab.on { color: var(--accent); border-bottom-color: var(--accent); }
.tab-badge {
  min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px;
  background: var(--cyan); color: #fff; font-size: 11px; font-weight: 600;
  display: inline-flex; align-items: center; justify-content: center;
}
.tab-badge-done { background: var(--done); }

/* ── BUTTONS / LINKS ──────────────────────────────────────────────── */
.btn {
  padding: 7px 14px; border: 1px solid var(--accent); border-radius: 7px;
  font-size: 13px; font-weight: 500; background: var(--accent); color: #fff;
  cursor: pointer; white-space: nowrap; transition: opacity .15s;
}
.btn:hover:not(:disabled) { opacity: 0.88; }
.btn:disabled { opacity: 0.45; cursor: not-allowed; }
/* Tombol aksi utama (Tanda tangani): biru cerah, kontras tinggi. */
.btn-blue { background: var(--cyan); border-color: var(--cyan); color: #fff; }
.btn-blue:hover:not(:disabled) { background: #1893c2; border-color: #1893c2; opacity: 1; }
/* Tombol sekunder (Edit isi): outline biru di atas putih — tetap terlihat. */
.btn-ghost { background: #fff; color: var(--accent); border-color: var(--accent); }
.btn-ghost:hover:not(:disabled) { background: #f2f6fb; opacity: 1; }
.lnk {
  border: 0; background: none; padding: 4px 2px; cursor: pointer;
  font-size: 13px; color: var(--accent); font-weight: 500;
}
.lnk:hover:not(:disabled) { text-decoration: underline; }
.lnk:disabled { opacity: 0.45; cursor: not-allowed; }

/* ── TOOLBAR ─────────────────────────────────────────────────────── */
.toolbar { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.search-inp {
  flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid var(--line);
  border-radius: 7px; font-size: 13px; color: var(--ink); background: #fff;
}
.search-inp::placeholder { color: var(--faint); }
.search-inp:focus { outline: none; border-color: var(--accent); }
.sel {
  padding: 8px 10px; border: 1px solid var(--line); border-radius: 7px;
  font-size: 13px; background: #fff; cursor: pointer; color: var(--ink);
}
.sel:focus { outline: none; border-color: var(--accent); }

/* ── FILTER TANGGAL KUNJUNGAN ─────────────────────────────────────── */
.dtfilter { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
.dt-seg { display: inline-flex; border: 1px solid var(--line); border-radius: 7px; overflow: hidden; }
.dt-seg-btn {
  border: 0; background: #fff; cursor: pointer; padding: 7px 10px;
  font-size: 12.5px; color: var(--muted); white-space: nowrap;
}
.dt-seg-btn + .dt-seg-btn { border-left: 1px solid var(--line); }
.dt-seg-btn:hover { background: #f2f6fb; }
.dt-seg-btn.on { background: var(--accent); color: #fff; }
.dt-input {
  padding: 7px 9px; border: 1px solid var(--line); border-radius: 7px;
  font-size: 13px; color: var(--ink); background: #fff; font-family: inherit;
}
.dt-input:focus { outline: none; border-color: var(--accent); }
.dt-dash { font-size: 12.5px; color: var(--faint); }

/* ── BULK BAR ─────────────────────────────────────────────────────── */
.bulkbar {
  display: flex; align-items: center; gap: 1rem; padding: 0.6rem 0;
  border-top: 1px solid var(--line); border-bottom: 1px solid var(--line);
}
.bulk-info { font-size: 13px; color: var(--muted); margin-right: auto; }

/* ── TABLE ───────────────────────────────────────────────────────── */
.chk { width: 15px; height: 15px; accent-color: var(--accent); cursor: pointer; }
.ta-center { text-align: center; }
.ta-right { text-align: right; }
.tbl-wrap { overflow-x: auto; }
.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.tbl thead th {
  text-align: left; font-weight: 500; color: var(--muted); font-size: 12px;
  padding: 0 0.75rem 0.6rem; border-bottom: 1px solid var(--line); white-space: nowrap;
}
.tbl tbody td { padding: 0.7rem 0.75rem; border-bottom: 1px solid #f1f2f4; vertical-align: middle; }
.tbl tbody tr:hover { background: #fafafa; }
.row-sel { background: #f5f8fb !important; }
.muted { color: var(--muted); }
.td-date { font-variant-numeric: tabular-nums; }

.patient { display: flex; flex-direction: column; gap: 1px; }
.p-name { font-weight: 500; }
.rm { font-size: 11.5px; color: var(--faint); }

.st { font-size: 12px; }
.st-pending { color: var(--ink); }
.st-draft { color: var(--faint); }
.st-default { color: var(--muted); }
.st-signed { color: var(--done); font-weight: 500; }

.cell-state { text-align: center; padding: 3rem 1rem; color: var(--faint); }
.err { color: var(--danger); }

/* ── FOOTER / PAGER ──────────────────────────────────────────────── */
.foot { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.6rem; }
.foot-info { font-size: 12.5px; color: var(--faint); }
.pager { display: flex; align-items: center; gap: 0.25rem; }
.page-btn, .page-num {
  min-width: 30px; height: 30px; padding: 0 8px; border: 1px solid transparent; border-radius: 6px;
  background: none; cursor: pointer; font-size: 13px; color: var(--ink);
  display: inline-flex; align-items: center; justify-content: center;
}
.page-btn:hover:not(:disabled), .page-num:hover { background: #f1f2f4; }
.page-btn:disabled { opacity: 0.3; cursor: not-allowed; }
.page-num.on { background: var(--accent); color: #fff; }

/* ── MODAL (preview) ─────────────────────────────────────────────── */
.pv-overlay {
  /* Modal di-Teleport ke <body> (di luar .ttd) → CSS variables tak ikut
     inherit. Deklarasikan ulang di sini supaya var(--cyan)/var(--accent) pada
     tombol footer tetap resolve (kalau tidak, tombol jadi putih tak terlihat). */
  --ink: #1f2937; --muted: #6b7280; --faint: #9ca3af;
  --line: #e5e7eb; --accent: #0e3a66; --cyan: #1faae0; --danger: #b42323;
  --done: #0f9d6b;
  position: fixed; inset: 0; background: rgba(0,0,0,.35);
  display: flex; align-items: center; justify-content: center; z-index: 1200; padding: 1rem;
}
.pv-modal { width: min(820px, 96vw); max-height: 90vh; background: #fff; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; }
.pv-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 1rem 1.4rem; border-bottom: 1px solid var(--line); }
.pv-head h3 { margin: 0; font-size: 15px; font-weight: 600; }
.pv-sub { margin: 3px 0 0; font-size: 12.5px; color: var(--muted); }
.pv-close { width: 28px; height: 28px; border-radius: 6px; border: 0; background: none; cursor: pointer; color: var(--faint); font-size: 22px; line-height: 1; }
.pv-close:hover { background: #f1f2f4; color: var(--ink); }
/* overflow auto (2 sumbu): dokumen RM dirancang lebar A4 → di HP bisa digeser,
   bukan terpotong. */
.pv-body { padding: 1.5rem 2rem; overflow: auto; flex: 1; }
.pv-foot { padding: 0.9rem 1.4rem; border-top: 1px solid var(--line); display: flex; justify-content: flex-end; align-items: center; gap: 1rem; }
.pv-signed { margin-right: auto; font-size: 12.5px; font-weight: 500; color: var(--done); }

.edit-wrap { display: flex; flex-direction: column; gap: 0.75rem; }
.edit-hint { margin: 0; font-size: 12px; color: var(--muted); }

/* ── PIN MODAL ───────────────────────────────────────────────────── */
.pin-overlay {
  /* Sama seperti .pv-overlay: ter-Teleport ke <body>, perlu var sendiri. */
  --ink: #1f2937; --muted: #6b7280; --faint: #9ca3af;
  --line: #e5e7eb; --accent: #0e3a66; --cyan: #1faae0; --danger: #b42323;
  --done: #0f9d6b;
  position: fixed; inset: 0; background: rgba(0,0,0,.35);
  display: flex; align-items: center; justify-content: center; z-index: 1300; padding: 1rem;
}
.pin-modal { width: min(340px, 94vw); background: #fff; border-radius: 12px; padding: 1.6rem; text-align: center; }
/* Addendum: form kiri-rata (beda dari PIN yang center). */
.add-modal { width: min(440px, 95vw); background: #fff; border-radius: 12px; padding: 1.5rem; text-align: left; }
.add-hint { margin: 6px 0 14px; font-size: 12px; color: var(--muted); line-height: 1.45; }
.add-label { display: block; font-size: 12px; font-weight: 600; color: var(--ink); margin: 8px 0 4px; }
.add-input { width: 100%; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; font-size: 13px; color: var(--ink); box-sizing: border-box; font-family: inherit; }
.add-input:focus { outline: none; border-color: var(--accent); }
.add-area { resize: vertical; min-height: 80px; }
.pin-title { margin: 0 0 4px; font-size: 15px; font-weight: 600; }
.pin-sub { margin: 0 0 8px; font-size: 12.5px; color: var(--muted); }
.pin-hint { margin: 0 0 16px; font-size: 12.5px; color: var(--muted); }
.pin-input {
  width: 100%; padding: 12px; border: 1px solid var(--line); border-radius: 8px;
  font-size: 22px; letter-spacing: 8px; text-align: center; box-sizing: border-box; color: var(--ink);
}
.pin-input:focus { outline: none; border-color: var(--accent); }
.pin-err { margin: 10px 0 0; font-size: 12.5px; color: var(--danger); }
.pin-actions { display: flex; justify-content: center; align-items: center; gap: 1rem; margin-top: 1.2rem; }

/* ─── HP sempit (iPhone standar / Galaxy Fold): rapikan modal preview + PIN ─── */
@media (max-width: 480px) {
  .pv-modal { width: 96vw; max-height: 94vh; }
  .pv-head { padding: 0.85rem 1rem; }
  .pv-body { padding: 1rem; }
  .pv-foot { padding: 0.75rem 1rem; gap: 0.6rem; }
  .pin-modal { padding: 1.25rem; }
}
</style>
