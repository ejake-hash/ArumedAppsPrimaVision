<script setup>
import { ref, computed, watch } from 'vue'
import api from '@/services/api'
import PatientAvatar from '@/components/common/PatientAvatar.vue'

// ─── HELPERS ──────────────────────────────────────────────────────────────────
const BLN = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des']
function fmtTgl(d) {
  if (!d) return '–'
  const [y, m, dd] = d.slice(0, 10).split('-')
  return `${dd} ${BLN[+m - 1]} ${y}`
}
function fmtTglPendek(d) {
  if (!d) return '–'
  const [y, m, dd] = d.slice(0, 10).split('-')
  return `${dd}/${m}/${y.slice(2)}`
}
function hitungUsia(dob) {
  if (!dob) return '–'
  const b = new Date(dob), n = new Date()
  let a = n.getFullYear() - b.getFullYear()
  if (n < new Date(n.getFullYear(), b.getMonth(), b.getDate())) a--
  return a
}
function classColor(c) {
  return ({ Baru:'var(--lm)', Kontrol:'#1d4ed8', 'Pre-Op':'#b45309', 'Post-Op':'#15803d' })[c] ?? '#6b7280'
}
function guarantorCls(g) {
  return ({ BPJS:'bpjs', UMUM:'umum', ASURANSI:'asn', PERUSAHAAN:'asn', SOSIAL:'asn' })[g] ?? 'umum'
}
function val(v) { return (v === null || v === undefined || v === '') ? '–' : v }

// ─── TOAST ────────────────────────────────────────────────────────────────────
const toasts = ref([])
let _tid = 0
function toast(type, msg) {
  const id = ++_tid
  toasts.value.push({ id, type, msg })
  setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id) }, 3200)
}

// ─── PATIENT STATE ──────────────────────────────────────────────────────────
// Dideklarasi lebih awal karena dipakai searchPlaceholder (computed) di bawah.
const patient = ref(null)

// ─── SEARCH ──────────────────────────────────────────────────────────────────
const searchMode     = ref('nama')
const searchQuery    = ref('')
const searchResults  = ref([])
const searching      = ref(false)
const showSearchDrop = ref(false)

const searchPlaceholder = computed(() => {
  const base = { nama:'Cari nama pasien...', rm:'Cari No. Rekam Medis...', nik:'Cari NIK (16 digit)...' }[searchMode.value]
  // Saat sudah ada pasien terpilih, ajak user ketik utk ganti pasien lain.
  return patient.value ? `Ketik untuk ganti pasien — ${base}` : base
})

let _debounce = null
let _searchSeq = 0   // guard race condition: hanya respons terbaru yang dipakai
watch(searchQuery, (q) => {
  clearTimeout(_debounce)
  if (!q.trim()) { _searchSeq++; searchResults.value = []; searching.value = false; return }
  // Begitu user mengetik, pastikan dropdown terbuka — walau pasien sudah
  // terpilih (memungkinkan ganti pasien kapan saja tanpa klik "Ganti Pasien").
  showSearchDrop.value = true
  _debounce = setTimeout(doSearch, 320)
})

async function doSearch() {
  const q = searchQuery.value.trim()
  if (!q) return
  const seq = ++_searchSeq
  searching.value = true
  try {
    const { data } = await api.get('/rekam-medis/pasien', { params: { keyword: q, mode: searchMode.value } })
    if (seq !== _searchSeq) return   // sudah ada pencarian lebih baru; abaikan hasil usang
    searchResults.value = data.data ?? []
  } catch {
    if (seq === _searchSeq) searchResults.value = []
  } finally {
    if (seq === _searchSeq) searching.value = false
  }
}

function setSearchMode(m) {
  clearTimeout(_debounce)
  _searchSeq++                 // batalkan respons in-flight dari mode lama
  searchMode.value = m
  searchQuery.value = ''
  searchResults.value = []
  searching.value = false
}
function hideDropLater() { setTimeout(() => { showSearchDrop.value = false }, 200) }

// ─── MENU (master-detail) ──────────────────────────────────────────────────────
const MENUS = [
  { key: 'ringkasan', label: 'Ringkasan' },
  { key: 'kunjungan', label: 'Kunjungan' },
  { key: 'refraksi',  label: 'Refraksi' },
  { key: 'penunjang', label: 'Penunjang' },
  { key: 'obat',      label: 'Obat' },
  { key: 'bedah',     label: 'Bedah' },
  { key: 'diagnosis', label: 'Diagnosis' },
  { key: 'dokumen',   label: 'Dokumen' },
]
const activeMenu = ref('ringkasan')

// Cache + loading per menu agar lazy-load & tidak fetch ulang.
const cache    = ref({})   // { [menu]: data }
const loading  = ref({})   // { [menu]: bool }
const errors   = ref({})   // { [menu]: msg }
const expanded = ref(null) // visit_id / order_id baris yang sedang dibuka

const ENDPOINT = {
  ringkasan: (id) => `/rekam-medis/pasien/${id}/ringkasan`,
  kunjungan: (id) => `/rekam-medis/pasien/${id}/kunjungan`,
  refraksi:  (id) => `/rekam-medis/pasien/${id}/refraksi`,
  penunjang: (id) => `/rekam-medis/pasien/${id}/penunjang`,
  obat:      (id) => `/rekam-medis/pasien/${id}/obat`,
  bedah:     (id) => `/rekam-medis/pasien/${id}/bedah`,
  diagnosis: (id) => `/rekam-medis/pasien/${id}/diagnosis`,
  dokumen:   (id) => `/rekam-medis/pasien/${id}/dokumen`,
}

async function loadMenu(menu, force = false) {
  if (!patient.value) return
  if (!force && cache.value[menu] !== undefined) return
  loading.value[menu] = true
  errors.value[menu]  = null
  try {
    const { data } = await api.get(ENDPOINT[menu](patient.value.id))
    // dokumen pakai paginator → data.data.data; sisanya array di data.data
    let payload = data.data
    if (menu === 'dokumen' && payload && Array.isArray(payload.data)) payload = payload.data
    cache.value[menu] = payload ?? (menu === 'ringkasan' ? {} : [])
  } catch (err) {
    errors.value[menu] = err.response?.data?.message ?? 'Gagal memuat data'
    cache.value[menu]  = menu === 'ringkasan' ? {} : []
  } finally {
    loading.value[menu] = false
  }
}

function selectMenu(menu) {
  activeMenu.value = menu
  expanded.value = null
  loadMenu(menu)
}

function toggleRow(id) { expanded.value = expanded.value === id ? null : id }

async function pickPatient(p) {
  patient.value        = p
  cache.value          = {}
  loading.value        = {}
  errors.value         = {}
  expanded.value       = null
  activeMenu.value     = 'ringkasan'
  showSearchDrop.value = false
  searchQuery.value    = ''
  searchResults.value  = []
  _searchSeq++              // batalkan respons pencarian in-flight
  await loadMenu('ringkasan')
}

function clearPatient() {
  patient.value = null
  cache.value = {}
  searchQuery.value = ''
}

// Convenience getters
const cur        = computed(() => cache.value[activeMenu.value])
const curLoading = computed(() => !!loading.value[activeMenu.value])
const curError   = computed(() => errors.value[activeMenu.value])
const ringkasan  = computed(() => cache.value.ringkasan ?? {})

// Header stats (dari ringkasan, fallback aman)
const headStats = computed(() => ringkasan.value.counts ?? { total_visits: 0, total_surgery: 0, with_diagnosis: 0 })

// ─── VISUS/TIO TREND ARROW ──────────────────────────────────────────────────
function trendVisus(curStr, prevStr) {
  // visus "6/9" → makin kecil penyebut = makin baik. Bandingkan denominator.
  const d = (s) => { const m = String(s ?? '').match(/\/(\d+)/); return m ? +m[1] : null }
  const a = d(curStr), b = d(prevStr)
  if (a == null || b == null) return ''
  if (a < b) return 'up'      // membaik
  if (a > b) return 'down'    // memburuk
  return 'flat'
}
function trendTio(c, p) {
  if (c == null || p == null) return ''
  const a = +c, b = +p
  if (Math.abs(a - b) < 0.5) return 'flat'
  return a > b ? 'down' : 'up' // TIO naik = perhatian (down=merah)
}

// ─── PENUNJANG (modal lihat hasil) ───────────────────────────────────────────
const selPj = ref(null)  // baris penunjang yang sedang dilihat
function openPenunjang(row) { selPj.value = row }
function isImageAttachment(url) {
  return !!url && /\.(png|jpe?g|gif|webp|bmp|svg)(\?.*)?$/i.test(url)
}
// expertise_data jsonb bebas bentuk → nilai bisa array/objek bersarang.
// {{ v2 }} polos akan merender "[object Object]"; rapikan jadi teks terbaca.
function fmtExpVal(v) {
  if (v === null || v === undefined || v === '') return '–'
  if (typeof v === 'object') {
    try { return JSON.stringify(v) } catch { return String(v) }
  }
  return v
}

// ─── DOCUMENTS ───────────────────────────────────────────────────────────────
const selDoc = ref(null)
// Dokumen RM punya 2 jalur lifecycle: legacy RekamMedisService (FINAL) &
// Form Registry (FINALIZED). Keduanya = "sudah final / boleh cetak & addendum".
function isDocFinal(s) { return s === 'FINAL' || s === 'FINALIZED' }
function docStatusLabel(s) {
  return ({ FINAL:'Final', FINALIZED:'Final', WAITING_SIGNATURE:'Menunggu TTD', PENDING_SIGNATURE:'Menunggu TTD', RENDERED:'Tersaji', DRAFT:'Draft', REJECTED:'Ditolak', VOID:'Void' })[s] ?? s
}
function docStatusCls(s) {
  return ({ FINAL:'final', FINALIZED:'final', WAITING_SIGNATURE:'waiting', PENDING_SIGNATURE:'waiting', RENDERED:'waiting', DRAFT:'draft', REJECTED:'rejected', VOID:'void' })[s] ?? 'draft'
}
const docsFinalCount = computed(() => (cache.value.dokumen ?? []).filter(d => isDocFinal(d.status)).length)

// Cetak dokumen: ambil snapshot HTML final via Axios (auth-aware lewat
// interceptor) lalu cetak di window baru — SAMA spt FormRMRenderer.printIt.
// JANGAN window.open(URL API) langsung: navigasi browser tak bawa Bearer (401)
// dan endpoint /cetak balas JSON (utk Puppeteer), bukan PDF.
const printing = ref(false)
async function printDoc(doc) {
  if (printing.value) return
  printing.value = true
  try {
    const { data } = await api.get(`/rekam-medis/document/${doc.id}/render`)
    const html = data.data?.rendered_html
    if (!html) { toast('w', 'Dokumen belum punya isi tersaji untuk dicetak'); return }
    const title = `${doc.document_type?.code ?? 'Dokumen'} — ${patient.value?.nama ?? ''}`
    const w = window.open('', '_blank', 'width=900,height=1200')
    if (!w) { toast('w', 'Popup diblokir browser — izinkan popup untuk mencetak'); return }
    w.document.open()
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${title}</title>
<style>@page { size: A4; margin: 1.5cm; } body { font-family: Arial, sans-serif; }</style>
</head><body>${html}</body></html>`)
    w.document.close()
    w.focus()
    setTimeout(() => w.print(), 200)
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal memuat dokumen untuk dicetak')
  } finally {
    printing.value = false
  }
}

// ─── ADDENDUM (per-dokumen, Form Registry) ───────────────────────────────────
const addendumModal = ref(null)  // doc object
const addAlasan     = ref('')
const addIsi        = ref('')
const savingAdd     = ref(false)

function openAddendum(doc) {
  addendumModal.value = doc
  addAlasan.value = ''
  addIsi.value = ''
}
async function saveAddendum() {
  if (!addAlasan.value.trim() || !addIsi.value.trim()) {
    toast('w', 'Alasan dan isi koreksi wajib diisi'); return
  }
  savingAdd.value = true
  try {
    await api.post(`/rekam-medis/document/${addendumModal.value.id}/addendum`, {
      alasan: addAlasan.value.trim(),
      isi_koreksi: addIsi.value.trim(),
    })
    toast('s', 'Addendum dicatat (menunggu TTD lanjutan)')
    addendumModal.value = null
  } catch (e) {
    toast('w', e.response?.data?.message ?? 'Gagal menyimpan addendum')
  } finally {
    savingAdd.value = false
  }
}

// ─── AUDIT TRAIL DRAWER ──────────────────────────────────────────────────────
const auditDoc     = ref(null)
const auditLogs    = ref([])
const auditLoading = ref(false)
async function openAudit(doc) {
  auditDoc.value = doc
  auditLogs.value = []
  auditLoading.value = true
  try {
    const { data } = await api.get(`/rekam-medis/document/${doc.id}/audit-log`)
    auditLogs.value = data.data ?? []
  } catch {
    auditLogs.value = []
  } finally {
    auditLoading.value = false
  }
}

// ─── MISC ────────────────────────────────────────────────────────────────────
async function copyRm() {
  const rm = patient.value?.no_rm
  if (!rm) return
  try {
    if (!navigator.clipboard) throw new Error('clipboard tidak tersedia')
    await navigator.clipboard.writeText(rm)
    toast('s', `No. RM ${rm} disalin`)
  } catch {
    toast('e', 'Gagal menyalin No. RM (clipboard tidak tersedia)')
  }
}
async function printResume() {
  // Pastikan data kunjungan tersedia untuk lampiran resume.
  if (cache.value.kunjungan === undefined) await loadMenu('kunjungan')
  if (errors.value.kunjungan) {
    toast('e', 'Gagal memuat riwayat kunjungan untuk resume')
    return
  }
  document.body.classList.remove('print-op')   // mode resume penuh
  window.print()
}

// ─── CETAK RESUME MEDIS RAWAT JALAN (RM 1.7/RMRJ/22) per kunjungan ──────────────
// Buka window A4 mandiri (tak terpengaruh CSS halaman) berisi layout formulir resmi.
const cetakingResume = ref(false)
async function cetakResumeMedis(visitId) {
  if (cetakingResume.value) return
  cetakingResume.value = true
  try {
    const { data } = await api.get(`/rekam-medis/kunjungan/${visitId}/resume-medis`)
    const d = data.data ?? data
    const html = d.rendered_html
    if (!html) { toast('w', 'Resume medis belum tersaji untuk dicetak'); return }

    const w = window.open('', '_blank', 'width=900,height=1000')
    if (!w) { toast('w', 'Popup diblokir browser — izinkan popup untuk mencetak'); return }
    w.document.open()
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"/>
<title>Resume Medis Rawat Jalan — ${(patient.value?.nama ?? '').replace(/[<>&]/g, '')}</title>
<style>@page { size: A4; margin: 14mm; } body { margin: 0; }</style>
</head><body>${html}</body></html>`)
    w.document.close()
    // Cetak SETELAH konten ter-render (onload inline kadang menembak window kosong).
    w.focus()
    setTimeout(() => { try { w.print() } catch (_) { /* user bisa Ctrl+P manual */ } }, 350)
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal memuat resume medis untuk dicetak')
  } finally {
    cetakingResume.value = false
  }
}

// ─── CETAK LAPORAN OPERASI (A4, dengan IOL traceability) ───────────────────────
const printOp = ref(null)   // baris bedah yang sedang dicetak

function cetakLaporanOperasi(b) {
  printOp.value = b
  // Kelas pada <body> agar @media print hanya menampilkan kartu laporan operasi
  // (menyembunyikan resume penuh & UI lain).
  document.body.classList.add('print-op')

  // Lepas kelas via event 'afterprint' (BUKAN timer tetap): kalau dialog cetak
  // lambat dibuka/ditutup, timer 300ms bisa mencopot kelas saat dialog masih
  // terbuka → yang tercetak malah resume. afterprint pasti setelah dialog selesai.
  const cleanup = () => {
    document.body.classList.remove('print-op')
    window.removeEventListener('afterprint', cleanup)
  }
  window.addEventListener('afterprint', cleanup)

  // Tunggu render container cetak sebelum panggil print.
  setTimeout(() => window.print(), 60)
}
</script>

<template>
  <div class="rme-page">

    <!-- ─── SEARCH BAR ─── -->
    <div class="rme-searchbar">
      <div class="rsb-inner">
        <div class="rsb-mode">
          <button v-for="m in [{v:'nama',l:'Nama'},{v:'rm',l:'No. RM'},{v:'nik',l:'NIK'}]" :key="m.v"
            :class="['rsb-mode-btn', searchMode===m.v?'a':'']" @click="setSearchMode(m.v)">{{ m.l }}</button>
        </div>
        <div class="rsb-input-wrap">
          <svg class="rsb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
          <input v-model="searchQuery" class="rsb-input" :placeholder="searchPlaceholder"
            @focus="showSearchDrop=true" @blur="hideDropLater" />
          <div v-if="searching" class="rsb-spinner"></div>
          <button v-if="patient" class="rsb-clear" @click="clearPatient">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            Ganti Pasien
          </button>
        </div>

        <!-- Dropdown — tetap aktif walau sudah ada pasien terpilih, agar bisa
             ganti/cari pasien lain tanpa harus klik "Ganti Pasien" dulu. -->
        <div v-if="showSearchDrop && searchQuery.trim()" class="rsb-dropdown">
          <template v-if="searching">
            <div v-for="i in 3" :key="i" class="rsb-item rsb-sk">
              <div class="rsi-bar" style="background:#e2e5ea"></div>
              <div style="flex:1">
                <div class="sk-line w50 mb4" style="height:9px"></div>
                <div class="sk-line w70 mb4" style="height:9px"></div>
                <div class="sk-line w40" style="height:9px"></div>
              </div>
            </div>
          </template>
          <template v-else>
            <div v-for="p in searchResults" :key="p.id" class="rsb-item" @mousedown.prevent="pickPatient(p)">
              <div :class="['rsi-bar', guarantorCls(p.last_guarantor_type)]"></div>
              <PatientAvatar :name="p.nama" :src="p.photo_url" :size="34" radius="50%" :zoomable="false" />
              <div class="rsi-body">
                <div class="rsi-top">
                  <span class="rsi-rm">{{ p.no_rm }}</span>
                  <span class="rsi-cnt">{{ p.visit_count ?? 0 }} kunjungan</span>
                </div>
                <div class="rsi-name">{{ p.nama }}</div>
                <div class="rsi-meta">{{ hitungUsia(p.date_of_birth) }} th · {{ p.gender === 'L' ? 'Laki-laki' : 'Perempuan' }} · {{ p.last_guarantor_type ?? 'UMUM' }}</div>
              </div>
            </div>
            <div v-if="!searchResults.length" class="rsb-empty">Tidak ada pasien yang cocok</div>
          </template>
        </div>
      </div>
    </div>

    <!-- EMPTY STATE -->
    <div v-if="!patient" class="empty">
      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
      <p>Cari dan pilih pasien dari kolom pencarian di atas<br/>untuk melihat Rekam Medis Elektronik</p>
    </div>

    <template v-if="patient">
      <!-- PATIENT PROFILE BAR -->
      <div class="ptb">
        <PatientAvatar :name="patient.nama" :src="patient.photo_url" :size="44" radius="50%" />
        <div class="pti">
          <div class="ptn">{{ patient.nama }}</div>
          <div class="ptm">{{ hitungUsia(patient.date_of_birth) }} th · {{ patient.gender === 'L' ? 'Laki-laki' : 'Perempuan' }} · {{ patient.address }}</div>
          <div class="ptags">
            <span :class="['ptg', `ptg-${guarantorCls(patient.last_guarantor_type)}`]">{{ patient.last_guarantor_type ?? 'UMUM' }}</span>
            <span v-if="patient.allergy" class="ptg ptg-r">⚠ Alergi: {{ patient.allergy }}</span>
            <button class="copy-rm-btn" @click="copyRm" title="Salin nomor RM">
              <svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
              {{ patient.no_rm }}
            </button>
          </div>
        </div>
        <div class="pt-stats">
          <div class="pt-stat"><div class="pt-stat-v">{{ headStats.total_visits }}</div><div class="pt-stat-l">Kunjungan</div></div>
          <div class="pt-stat"><div class="pt-stat-v">{{ headStats.total_surgery }}</div><div class="pt-stat-l">Operasi</div></div>
          <div class="pt-stat"><div class="pt-stat-v">{{ headStats.with_diagnosis }}</div><div class="pt-stat-l">Diagnosis</div></div>
        </div>
        <button class="print-btn" @click="printResume">
          <svg viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
          Cetak Resume
        </button>
      </div>

      <!-- ─── MASTER-DETAIL ─── -->
      <div class="rme-md">
        <!-- LEFT MENU -->
        <nav class="rme-nav">
          <button v-for="m in MENUS" :key="m.key"
            :class="['rnav-item', activeMenu===m.key?'a':'']" @click="selectMenu(m.key)">
            <span>{{ m.label }}</span>
            <span v-if="m.key==='dokumen' && cache.dokumen" class="rnav-ct">{{ docsFinalCount }}/{{ cache.dokumen.length }}</span>
          </button>
        </nav>

        <!-- RIGHT CONTENT -->
        <section class="rme-data">
          <!-- Loading -->
          <div v-if="curLoading" class="data-load">
            <div v-for="i in 4" :key="i" class="sk-line w90 mb6" style="height:34px"></div>
          </div>

          <!-- Error -->
          <div v-else-if="curError" class="error-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span>{{ curError }}</span>
            <button class="retry-btn" @click="loadMenu(activeMenu, true)">Coba Lagi</button>
          </div>

          <template v-else>
            <!-- ════ RINGKASAN ════ -->
            <div v-if="activeMenu==='ringkasan'" class="ringkasan">
              <div class="rk-grid">
                <!-- Alergi & catatan -->
                <div class="rk-card alert" v-if="ringkasan.patient?.allergy || ringkasan.allergy_latest_assessment">
                  <div class="rk-card-t">⚠ Alergi &amp; Catatan Penting</div>
                  <div class="rk-alert-body">
                    <div v-if="ringkasan.patient?.allergy"><strong>Riwayat:</strong> {{ ringkasan.patient.allergy }}</div>
                    <div v-if="ringkasan.allergy_latest_assessment"><strong>Asesmen terakhir:</strong> {{ ringkasan.allergy_latest_assessment }}</div>
                    <div v-if="ringkasan.patient?.blood_type"><strong>Gol. Darah:</strong> {{ ringkasan.patient.blood_type }}</div>
                  </div>
                </div>

                <!-- Visus & TIO -->
                <div class="rk-card" v-if="ringkasan.visus_tio">
                  <div class="rk-card-t">Visus &amp; TIO Terakhir <span class="rk-date">{{ fmtTgl(ringkasan.visus_tio.date) }}</span></div>
                  <table class="mini-eye">
                    <thead><tr><th></th><th>OD</th><th>OS</th></tr></thead>
                    <tbody>
                      <tr>
                        <td>Visus</td>
                        <td>{{ val(ringkasan.visus_tio.visus_od) }} <i v-if="ringkasan.visus_tio.prev" :class="['tr', trendVisus(ringkasan.visus_tio.visus_od, ringkasan.visus_tio.prev.visus_od)]"></i></td>
                        <td>{{ val(ringkasan.visus_tio.visus_os) }} <i v-if="ringkasan.visus_tio.prev" :class="['tr', trendVisus(ringkasan.visus_tio.visus_os, ringkasan.visus_tio.prev.visus_os)]"></i></td>
                      </tr>
                      <tr>
                        <td>TIO</td>
                        <td>{{ val(ringkasan.visus_tio.tio_od) }} <span v-if="ringkasan.visus_tio.tio_od" class="unit">mmHg</span> <i v-if="ringkasan.visus_tio.prev" :class="['tr', trendTio(ringkasan.visus_tio.tio_od, ringkasan.visus_tio.prev.tio_od)]"></i></td>
                        <td>{{ val(ringkasan.visus_tio.tio_os) }} <span v-if="ringkasan.visus_tio.tio_os" class="unit">mmHg</span> <i v-if="ringkasan.visus_tio.prev" :class="['tr', trendTio(ringkasan.visus_tio.tio_os, ringkasan.visus_tio.prev.tio_os)]"></i></td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <!-- Kunjungan terakhir -->
                <div class="rk-card" v-if="ringkasan.last_visit">
                  <div class="rk-card-t">Kunjungan Terakhir</div>
                  <div class="rk-last">
                    <div class="rk-last-row"><span>Tanggal</span><b>{{ fmtTgl(ringkasan.last_visit.date) }}</b></div>
                    <div class="rk-last-row"><span>Klasifikasi</span><b :style="{color:classColor(ringkasan.last_visit.classification)}">{{ val(ringkasan.last_visit.classification) }}</b></div>
                    <div class="rk-last-row"><span>Dokter</span><b>{{ val(ringkasan.last_visit.doctor) }}</b></div>
                    <div class="rk-last-row" v-if="ringkasan.last_visit.poli"><span>Poli</span><b>{{ ringkasan.last_visit.poli }}</b></div>
                    <div class="rk-last-row" v-if="ringkasan.last_visit.planning"><span>Rencana</span><b>{{ ringkasan.last_visit.planning }}</b></div>
                    <div class="rk-last-row" v-if="ringkasan.last_visit.follow_up_date"><span>Kontrol</span><b>{{ fmtTgl(ringkasan.last_visit.follow_up_date) }}</b></div>
                  </div>
                </div>

                <!-- Problem list -->
                <div class="rk-card span2">
                  <div class="rk-card-t">Daftar Masalah (Problem List)</div>
                  <div v-if="(ringkasan.problem_list ?? []).length" class="pl-list">
                    <div v-for="p in ringkasan.problem_list" :key="p.kode" class="pl-item">
                      <span class="pl-kode">{{ p.kode }}</span>
                      <span class="pl-nama">{{ val(p.nama) }}</span>
                      <span class="pl-meta">{{ p.count }}× · sejak {{ fmtTglPendek(p.first_date) }}</span>
                    </div>
                  </div>
                  <div v-else class="empty-mini">Belum ada diagnosis tercatat</div>
                </div>
              </div>
            </div>

            <!-- ════ KUNJUNGAN ════ -->
            <div v-else-if="activeMenu==='kunjungan'" class="tbl-wrap">
              <div v-if="!cur.length" class="empty-mini">Belum ada kunjungan</div>
              <table v-else class="rme-table">
                <thead><tr>
                  <th>Tanggal</th><th>Klasifikasi</th><th>Dokter / Poli</th><th>Diagnosis Utama</th><th>Penjamin</th><th></th><th></th>
                </tr></thead>
                <tbody>
                  <template v-for="v in cur" :key="v.visit_id">
                    <tr class="row-click" @click="toggleRow(v.visit_id)">
                      <td class="nowrap">{{ fmtTgl(v.visit_date) }}</td>
                      <td><span class="cls-badge" :style="{background:classColor(v.classification)+'1f',color:classColor(v.classification)}">{{ val(v.classification) }}</span></td>
                      <td><div class="cell-2">{{ val(v.doctor_name) }}<small v-if="v.poli_name">{{ v.poli_name }}</small></div></td>
                      <td><span v-if="v.diagnosis_utama" class="dx-inline"><b>{{ v.diagnosis_utama }}</b> {{ v.diagnosis_utama_nama }}</span><span v-else>–</span></td>
                      <td><span :class="['g-pill', guarantorCls(v.guarantor_type)]">{{ val(v.guarantor_type) }}</span></td>
                      <td class="badges">
                        <span v-if="v.is_finalized" class="b-mini final" title="Terfinalisasi">✓</span>
                        <span v-if="v.penunjang_count" class="b-mini pj">{{ v.penunjang_count }} PJ</span>
                        <span v-if="v.no_sep" class="b-mini sep">SEP</span>
                      </td>
                      <td class="chev">{{ expanded===v.visit_id ? '▲' : '▼' }}</td>
                    </tr>
                    <tr v-if="expanded===v.visit_id" class="row-detail">
                      <td colspan="7">
                        <div class="det-grid">
                          <div class="det-box"><div class="det-t">Keluhan Utama</div><div>{{ val(v.detail?.keluhan) }}</div></div>
                          <div class="det-box">
                            <div class="det-t">Tanda Vital</div>
                            <div class="vt-row" v-if="v.detail?.ttv">
                              <span>TD {{ val(v.detail.ttv.td) }}</span><span>Nadi {{ val(v.detail.ttv.nadi) }}</span>
                              <span>SpO₂ {{ val(v.detail.ttv.spo2) }}%</span><span>Suhu {{ val(v.detail.ttv.suhu) }}°</span>
                              <span>RR {{ val(v.detail.ttv.respirasi) }}</span><span>KGD {{ val(v.detail.ttv.kgd) }}</span>
                            </div>
                            <div v-else>–</div>
                          </div>
                          <div class="det-box span2" v-if="v.detail?.soap">
                            <div class="det-t">SOAP</div>
                            <div class="soap-mini"><b>S</b> {{ val(v.detail.soap.s) }}</div>
                            <div class="soap-mini"><b>O</b> {{ val(v.detail.soap.o) }}</div>
                            <div class="soap-mini"><b>A</b> {{ val(v.detail.soap.a) }}</div>
                            <div class="soap-mini"><b>P</b> {{ val(v.detail.soap.p) }}</div>
                          </div>
                          <div class="det-box" v-if="v.detail?.planning || v.detail?.follow_up_date">
                            <div class="det-t">Rencana</div>
                            <div>{{ val(v.detail.planning) }}</div>
                            <div v-if="v.detail.follow_up_date"><small>Kontrol: {{ fmtTgl(v.detail.follow_up_date) }}</small></div>
                          </div>
                          <div class="det-box span2 det-actions">
                            <button class="rm17-btn" :disabled="cetakingResume" @click.stop="cetakResumeMedis(v.visit_id)">
                              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                              Cetak Resume Medis (RM 1.7)
                            </button>
                            <small v-if="!v.is_finalized" class="rm17-hint">Belum difinalisasi — isi resume mungkin kosong.</small>
                          </div>
                        </div>
                      </td>
                    </tr>
                  </template>
                </tbody>
              </table>
            </div>

            <!-- ════ REFRAKSI ════ -->
            <div v-else-if="activeMenu==='refraksi'" class="tbl-wrap">
              <div v-if="!cur.length" class="empty-mini">Belum ada data refraksi</div>
              <table v-else class="rme-table">
                <thead><tr>
                  <th>Tanggal</th><th>Visus OD</th><th>Visus OS</th><th>Rx OD</th><th>Rx OS</th><th>TIO OD/OS</th><th>PD</th><th></th>
                </tr></thead>
                <tbody>
                  <template v-for="r in cur" :key="r.visit_id">
                    <tr class="row-click" @click="toggleRow(r.visit_id)">
                      <td class="nowrap">{{ fmtTgl(r.visit_date) }}</td>
                      <td>{{ val(r.visus_od) }}</td><td>{{ val(r.visus_os) }}</td>
                      <td class="mono">{{ val(r.rx_od) }}</td><td class="mono">{{ val(r.rx_os) }}</td>
                      <td>{{ val(r.tio_od) }}/{{ val(r.tio_os) }}</td>
                      <td>{{ val(r.pd) }}</td>
                      <td class="chev">{{ expanded===r.visit_id ? '▲' : '▼' }}</td>
                    </tr>
                    <tr v-if="expanded===r.visit_id" class="row-detail">
                      <td colspan="8">
                        <div class="det-grid">
                          <div class="det-box"><div class="det-t">Visus Awal / Pinhole</div><div>OD {{ val(r.detail?.visus_awal_od) }} / PH {{ val(r.detail?.pinhole_od) }}</div><div>OS {{ val(r.detail?.visus_awal_os) }} / PH {{ val(r.detail?.pinhole_os) }}</div></div>
                          <div class="det-box"><div class="det-t">Autoref</div><div class="mono">OD {{ val(r.detail?.autoref_od) }}</div><div class="mono">OS {{ val(r.detail?.autoref_os) }}</div></div>
                          <div class="det-box"><div class="det-t">Refraksi Subjektif</div><div class="mono">OD {{ val(r.detail?.subjektif_od) }}</div><div class="mono">OS {{ val(r.detail?.subjektif_os) }}</div></div>
                          <div class="det-box"><div class="det-t">Keratometri</div><div class="mono">OD {{ val(r.detail?.keratometri_od) }}</div><div class="mono">OS {{ val(r.detail?.keratometri_os) }}</div></div>
                          <div class="det-box"><div class="det-t">Kacamata Lama</div><div class="mono">OD {{ val(r.detail?.old_glasses_od) }}</div><div class="mono">OS {{ val(r.detail?.old_glasses_os) }}</div></div>
                          <div class="det-box"><div class="det-t">Resep Kacamata</div><div>{{ val(r.detail?.glasses_type) }} · {{ val(r.detail?.lens_material) }} · {{ val(r.detail?.coating) }}</div><div v-if="r.detail?.iop_method"><small>TIO: {{ r.detail.iop_method }}</small></div></div>
                          <div class="det-box span2" v-if="r.detail?.clinical_notes"><div class="det-t">Catatan Klinis</div><div>{{ r.detail.clinical_notes }}</div></div>
                        </div>
                      </td>
                    </tr>
                  </template>
                </tbody>
              </table>
            </div>

            <!-- ════ PENUNJANG ════ -->
            <div v-else-if="activeMenu==='penunjang'" class="tbl-wrap">
              <div v-if="!cur.length" class="empty-mini">Belum ada hasil penunjang</div>
              <table v-else class="rme-table">
                <thead><tr>
                  <th>Tanggal</th><th>Jenis</th><th>Mata</th><th>Ringkasan Hasil</th><th>Status</th><th>Pemeriksa</th><th></th><th class="ta-r">Aksi</th><th></th>
                </tr></thead>
                <tbody>
                  <template v-for="p in cur" :key="p.order_id">
                    <tr class="row-click" @click="toggleRow(p.order_id)">
                      <td class="nowrap">{{ fmtTgl(p.visit_date) }}</td>
                      <td><b>{{ val(p.test_name) }}</b></td>
                      <td>{{ p.eye_side ? p.eye_side.toUpperCase() : '–' }}</td>
                      <td class="trunc">{{ val(p.summary) }}</td>
                      <td><span :class="['st-pill', (p.status||'').toLowerCase()]">{{ val(p.status) }}</span></td>
                      <td>{{ val(p.examiner) }}</td>
                      <td><a v-if="p.attachment_url" :href="p.attachment_url" target="_blank" class="att-link" @click.stop>📎</a></td>
                      <td class="ta-r"><button class="dbtn" @click.stop="openPenunjang(p)">Lihat Hasil</button></td>
                      <td class="chev">{{ expanded===p.order_id ? '▲' : '▼' }}</td>
                    </tr>
                    <tr v-if="expanded===p.order_id" class="row-detail">
                      <td colspan="9">
                        <div class="det-box">
                          <div class="det-t">Detail Hasil</div>
                          <div v-if="p.detail?.expertise_data" class="kv-grid">
                            <div v-for="(v2,k) in p.detail.expertise_data" :key="k" class="kv">
                              <span class="kv-k">{{ k }}</span><span class="kv-v">{{ fmtExpVal(v2) }}</span>
                            </div>
                          </div>
                          <div v-else>–</div>
                          <div v-if="p.detail?.notes" class="det-note">Catatan: {{ p.detail.notes }}</div>
                          <div v-if="p.detail?.reviewer" class="det-note">Diverifikasi: {{ p.detail.reviewer }} · {{ fmtTglPendek(p.detail.reviewed_at) }}</div>
                        </div>
                      </td>
                    </tr>
                  </template>
                </tbody>
              </table>
            </div>

            <!-- ════ OBAT ════ -->
            <div v-else-if="activeMenu==='obat'" class="tbl-wrap">
              <div v-if="!cur.length" class="empty-mini">Belum ada riwayat obat</div>
              <table v-else class="rme-table">
                <thead><tr><th>Tanggal</th><th>Penulis Resep</th><th>Jumlah Item</th><th>Ringkasan</th><th></th></tr></thead>
                <tbody>
                  <template v-for="o in cur" :key="o.visit_id">
                    <tr class="row-click" @click="toggleRow(o.visit_id)">
                      <td class="nowrap">{{ fmtTgl(o.visit_date) }}</td>
                      <td>{{ val(o.prescriber) }}</td>
                      <td>{{ o.item_count }} obat</td>
                      <td class="trunc">{{ (o.items ?? []).map(i => i.nama).join(', ') }}</td>
                      <td class="chev">{{ expanded===o.visit_id ? '▲' : '▼' }}</td>
                    </tr>
                    <tr v-if="expanded===o.visit_id" class="row-detail">
                      <td colspan="5">
                        <table class="sub-table">
                          <thead><tr><th>Obat</th><th>Qty</th><th>Dosis</th><th>Aturan Pakai</th><th>Catatan</th></tr></thead>
                          <tbody>
                            <tr v-for="(it,i) in (o.items ?? [])" :key="i">
                              <td><b>{{ it.nama }}</b></td>
                              <td>{{ val(it.quantity) }} {{ it.unit ?? '' }}</td>
                              <td>{{ val(it.dosage) }}</td>
                              <td>{{ val(it.instructions) }}</td>
                              <td>{{ val(it.notes) }}</td>
                            </tr>
                          </tbody>
                        </table>
                      </td>
                    </tr>
                  </template>
                </tbody>
              </table>
            </div>

            <!-- ════ BEDAH ════ -->
            <div v-else-if="activeMenu==='bedah'" class="tbl-wrap">
              <div v-if="!cur.length" class="empty-mini">Belum ada riwayat operasi</div>
              <table v-else class="rme-table">
                <thead><tr><th>Tanggal</th><th>Prosedur</th><th>Jam</th><th>IOL</th><th>Komplikasi</th><th></th></tr></thead>
                <tbody>
                  <template v-for="b in cur" :key="b.visit_id">
                    <tr class="row-click" @click="toggleRow(b.visit_id)">
                      <td class="nowrap">{{ fmtTgl(b.visit_date) }}</td>
                      <td>{{ (b.procedures ?? []).length ? b.procedures.join(', ') : '–' }}</td>
                      <td class="nowrap">{{ val(b.time_in) }}–{{ val(b.time_out) }}</td>
                      <td class="trunc">{{ (b.iol_used ?? []).length ? b.iol_used.join(', ') : '–' }}</td>
                      <td><span v-if="b.has_complication" class="st-pill rejected">Ada</span><span v-else class="st-pill final">Tidak</span></td>
                      <td class="chev">{{ expanded===b.visit_id ? '▲' : '▼' }}</td>
                    </tr>
                    <tr v-if="expanded===b.visit_id" class="row-detail">
                      <td colspan="6">
                        <div class="det-grid">
                          <div class="det-box span2" v-if="b.detail?.operation_notes"><div class="det-t">Laporan Operasi</div><div>{{ b.detail.operation_notes }}</div></div>
                          <div class="det-box" v-if="b.detail?.complication_detail"><div class="det-t">Detail Komplikasi</div><div>{{ b.detail.complication_detail }}</div></div>
                          <div class="det-box" v-if="b.detail?.post_op_instructions"><div class="det-t">Instruksi Pasca-Op</div><div>{{ b.detail.post_op_instructions }}</div></div>
                          <div class="det-box" v-if="b.detail?.followup_date"><div class="det-t">Kontrol</div><div>{{ fmtTgl(b.detail.followup_date) }}</div></div>

                          <!-- IOL Details — traceability implan (serial/lot/gtin) -->
                          <div class="det-box span2" v-if="(b.iol_details ?? []).length">
                            <div class="det-t">Lensa Intraokular (IOL) yang Ditanam</div>
                            <table class="iol-det-tbl">
                              <thead><tr><th>Mata</th><th>Merk / Model</th><th>Power</th><th>Lot</th><th>Serial</th><th>GTIN</th><th>Kadaluwarsa</th></tr></thead>
                              <tbody>
                                <tr v-for="(u, i) in b.iol_details" :key="i">
                                  <td><strong>{{ u.eye_side }}</strong></td>
                                  <td>{{ [u.brand, u.model].filter(Boolean).join(' ') || '–' }}</td>
                                  <td>{{ u.power != null ? `+${u.power} D` : '–' }}</td>
                                  <td>{{ u.lot_number || '–' }}</td>
                                  <td>{{ u.serial_number || '–' }}</td>
                                  <td class="mono">{{ u.gtin || '–' }}</td>
                                  <td>{{ u.expiry_date ? fmtTgl(u.expiry_date) : '–' }}</td>
                                </tr>
                              </tbody>
                            </table>
                          </div>

                          <div class="det-box span2" style="display:flex;justify-content:flex-end">
                            <button class="rme-print-op" @click="cetakLaporanOperasi(b)">🖨 Cetak Laporan Operasi</button>
                          </div>

                          <div class="det-box" v-if="!b.detail?.operation_notes && !b.detail?.complication_detail && !b.detail?.post_op_instructions && !b.detail?.followup_date && !(b.iol_details ?? []).length"><div>Tidak ada catatan tambahan</div></div>
                        </div>
                      </td>
                    </tr>
                  </template>
                </tbody>
              </table>
            </div>

            <!-- ════ DIAGNOSIS ════ -->
            <div v-else-if="activeMenu==='diagnosis'" class="tbl-wrap">
              <div v-if="!cur.length" class="empty-mini">Belum ada diagnosis</div>
              <table v-else class="rme-table">
                <thead><tr><th>Tanggal</th><th>ICD-10 Utama</th><th>Sekunder</th><th>Tindakan (ICD-9)</th><th>Rencana</th></tr></thead>
                <tbody>
                  <tr v-for="d in cur" :key="d.visit_id">
                    <td class="nowrap">{{ fmtTgl(d.visit_date) }}</td>
                    <td><span v-if="d.utama" class="dx-inline"><b>{{ d.utama.kode }}</b> {{ d.utama.nama }}</span><span v-else>–</span></td>
                    <td>
                      <div v-for="s in (d.sekunder ?? [])" :key="s.kode" class="dx-sub"><b>{{ s.kode }}</b> {{ s.nama }}</div>
                      <span v-if="!(d.sekunder ?? []).length">–</span>
                    </td>
                    <td>
                      <div v-for="t in (d.tindakan ?? [])" :key="t.kode" class="dx-sub"><b>{{ t.kode }}</b> {{ t.nama }}</div>
                      <span v-if="!(d.tindakan ?? []).length">–</span>
                    </td>
                    <td>{{ val(d.planning) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- ════ DOKUMEN ════ -->
            <div v-else-if="activeMenu==='dokumen'" class="tbl-wrap">
              <div v-if="!cur.length" class="empty-mini">Belum ada dokumen untuk pasien ini</div>
              <table v-else class="rme-table">
                <thead><tr><th>Tanggal</th><th>Dokumen</th><th>No.</th><th>Stasiun</th><th>Status</th><th class="ta-r">Aksi</th></tr></thead>
                <tbody>
                  <tr v-for="doc in cur" :key="doc.id">
                    <td class="nowrap">{{ fmtTgl(doc.visit?.visit_date ?? doc.created_at) }}</td>
                    <td><div class="cell-2"><b>{{ doc.document_type?.name }}</b><small>{{ doc.document_type?.code }}</small></div></td>
                    <td class="mono">{{ val(doc.document_number) }}</td>
                    <td>{{ val(doc.created_by_station) }}</td>
                    <td><span :class="['st-pill', docStatusCls(doc.status)]">{{ docStatusLabel(doc.status) }}</span></td>
                    <td class="ta-r doc-acts">
                      <button class="dbtn" @click="selDoc=doc">Lihat</button>
                      <button v-if="isDocFinal(doc.status)" class="dbtn" @click="printDoc(doc)">Print</button>
                      <button v-if="isDocFinal(doc.status)" class="dbtn" @click="openAddendum(doc)">Addendum</button>
                      <button class="dbtn ghost" @click="openAudit(doc)">Audit</button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </section>
      </div>

      <!-- PRINT RESUME (hidden, shown on print) -->
      <div class="resume-print">
        <div class="rp-header"><strong>RUMAH SAKIT MATA</strong><br/>Resume Medis Rawat Jalan · PMK No. 24/2022</div>
        <h2>RESUME REKAM MEDIS</h2>
        <table class="rp-table">
          <tr><td>Nama</td><td>{{ patient.nama }}</td><td>No. RM</td><td>{{ patient.no_rm }}</td></tr>
          <tr><td>Tgl. Lahir</td><td>{{ fmtTgl(patient.date_of_birth) }}</td><td>NIK</td><td>{{ patient.nik }}</td></tr>
          <tr><td>Gender</td><td>{{ patient.gender === 'L' ? 'Laki-laki' : 'Perempuan' }}</td><td>Penjamin</td><td>{{ patient.last_guarantor_type }}</td></tr>
          <tr><td>Alamat</td><td colspan="3">{{ patient.address }}</td></tr>
          <tr v-if="patient.allergy"><td>Alergi</td><td colspan="3">{{ patient.allergy }}</td></tr>
        </table>
        <h3>Riwayat Kunjungan</h3>
        <table class="rp-grid">
          <thead><tr><th>Tanggal</th><th>Klasifikasi</th><th>Dokter</th><th>Diagnosis</th></tr></thead>
          <tbody>
            <tr v-for="v in (cache.kunjungan ?? [])" :key="v.visit_id">
              <td>{{ fmtTgl(v.visit_date) }}</td><td>{{ v.classification }}</td><td>{{ v.doctor_name }}</td>
              <td>{{ v.diagnosis_utama }} {{ v.diagnosis_utama_nama }}</td>
            </tr>
          </tbody>
        </table>
        <div class="rp-footer">Dicetak: {{ new Date().toLocaleDateString('id-ID') }} · Arumed Apps</div>
      </div>
    </template>

    <!-- ─── PENUNJANG MODAL (lihat hasil) ─── -->
    <div v-if="selPj" class="ov" @click.self="selPj=null">
      <div class="mbx mbx-lg">
        <div class="mh">
          <div>
            <div class="mht">{{ selPj.test_name }}<span v-if="selPj.eye_side"> · {{ selPj.eye_side.toUpperCase() }}</span></div>
            <div class="msub">{{ fmtTgl(selPj.visit_date) }} · Status: {{ val(selPj.status) }}</div>
          </div>
          <div style="display:flex;gap:.4rem">
            <a v-if="selPj.attachment_url" :href="selPj.attachment_url" target="_blank" class="mbtn ghost">Buka Lampiran ↗</a>
            <button class="mcl" @click="selPj=null">✕</button>
          </div>
        </div>
        <div class="mb">
          <div class="doc-patient-bar">Pasien: <strong>{{ patient.nama }}</strong> · {{ patient.no_rm }}</div>

          <!-- Lampiran (gambar inline / file lain link) -->
          <div class="pj-sec-t">Lampiran Hasil</div>
          <div v-if="selPj.attachment_url" class="pj-attach">
            <a v-if="isImageAttachment(selPj.attachment_url)" :href="selPj.attachment_url" target="_blank">
              <img :src="selPj.attachment_url" alt="Lampiran hasil penunjang" class="pj-img" />
            </a>
            <a v-else :href="selPj.attachment_url" target="_blank" class="pj-file">
              <span class="pj-file-ic">📄</span>
              <span>Buka berkas hasil (PDF/dokumen) di tab baru</span>
            </a>
          </div>
          <div v-else class="empty-mini" style="padding:1.2rem">Tidak ada lampiran terunggah</div>

          <!-- Detail expertise -->
          <div class="pj-sec-t">Detail Pemeriksaan</div>
          <div v-if="selPj.detail?.expertise_data" class="kv-grid pj-kv">
            <div v-for="(v2,k) in selPj.detail.expertise_data" :key="k" class="kv">
              <span class="kv-k">{{ k }}</span><span class="kv-v">{{ fmtExpVal(v2) }}</span>
            </div>
          </div>
          <div v-else class="empty-mini" style="padding:1rem">Belum ada data hasil</div>
          <div v-if="selPj.detail?.notes" class="det-note">Catatan: {{ selPj.detail.notes }}</div>

          <!-- Meta verifikasi -->
          <div class="pj-meta">
            <div><span class="pj-meta-k">Pemeriksa</span> {{ val(selPj.examiner) }}</div>
            <div v-if="selPj.detail?.reviewer"><span class="pj-meta-k">Diverifikasi</span> {{ selPj.detail.reviewer }} · {{ fmtTglPendek(selPj.detail.reviewed_at) }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── DOCUMENT MODAL ─── -->
    <div v-if="selDoc" class="ov" @click.self="selDoc=null">
      <div class="mbx">
        <div class="mh">
          <div>
            <div class="mht">{{ selDoc.document_type?.code }} — {{ selDoc.document_type?.name }}</div>
            <div class="msub">{{ selDoc.created_by_station }} · Status: {{ docStatusLabel(selDoc.status) }}</div>
          </div>
          <div style="display:flex;gap:.4rem">
            <button v-if="isDocFinal(selDoc.status)" class="mbtn" @click="printDoc(selDoc)">Print</button>
            <button class="mcl" @click="selDoc=null">✕</button>
          </div>
        </div>
        <div class="mb">
          <div class="doc-patient-bar">Pasien: <strong>{{ patient.nama }}</strong> · {{ patient.no_rm }}
            <span v-if="selDoc.visit?.visit_date"> · {{ fmtTgl(selDoc.visit.visit_date) }}</span></div>
          <div :class="['doc-status-block', docStatusCls(selDoc.status)]">
            <div class="dsb-title">{{ docStatusLabel(selDoc.status) }}</div>
            <div class="dsb-desc">
              <template v-if="isDocFinal(selDoc.status)">Dokumen final<span v-if="selDoc.document_number"> · No: {{ selDoc.document_number }}</span>.<span v-if="selDoc.printed_count"> Dicetak {{ selDoc.printed_count }}×.</span></template>
              <template v-else-if="docStatusCls(selDoc.status)==='waiting'">Menunggu tanda tangan. Akses dari stasiun <strong>{{ selDoc.created_by_station }}</strong>.</template>
              <template v-else-if="selDoc.status==='REJECTED'">Dokumen ditolak. Perbaiki via stasiun <strong>{{ selDoc.created_by_station }}</strong>.</template>
              <template v-else>Draft. Isi &amp; submit via stasiun <strong>{{ selDoc.created_by_station }}</strong>.</template>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── ADDENDUM MODAL ─── -->
    <div v-if="addendumModal" class="ov" @click.self="addendumModal=null">
      <div class="mbx">
        <div class="mh">
          <div><div class="mht">Addendum Dokumen</div><div class="msub">{{ addendumModal.document_type?.name }} · {{ addendumModal.document_number }}</div></div>
          <button class="mcl" @click="addendumModal=null">✕</button>
        </div>
        <div class="mb">
          <p class="add-hint">Addendum adalah koreksi/tambahan resmi pada dokumen yang sudah final — data lama tidak dihapus. Perlu ditandatangani ulang.</p>
          <label class="fld-l">Alasan koreksi</label>
          <input v-model="addAlasan" class="fld-i" placeholder="mis. salah ketik dosis" />
          <label class="fld-l">Isi koreksi</label>
          <textarea v-model="addIsi" class="fld-t" rows="4" placeholder="Tuliskan koreksi atau tambahan…"></textarea>
          <div class="mh-acts">
            <button class="mbtn ghost" @click="addendumModal=null">Batal</button>
            <button class="mbtn primary" :disabled="savingAdd" @click="saveAddendum">{{ savingAdd ? 'Menyimpan…' : 'Simpan Addendum' }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── AUDIT DRAWER ─── -->
    <div v-if="auditDoc" class="ov" @click.self="auditDoc=null">
      <div class="drawer">
        <div class="mh">
          <div><div class="mht">Jejak Audit</div><div class="msub">{{ auditDoc.document_type?.name }}</div></div>
          <button class="mcl" @click="auditDoc=null">✕</button>
        </div>
        <div class="mb">
          <div v-if="auditLoading" class="empty-mini">Memuat…</div>
          <div v-else-if="!auditLogs.length" class="empty-mini">Belum ada jejak audit</div>
          <div v-else class="audit-tl">
            <div v-for="(l,i) in auditLogs" :key="i" class="audit-item">
              <div class="audit-dot"></div>
              <div class="audit-body">
                <div class="audit-act">{{ l.event ?? l.action ?? l.description ?? 'Aktivitas' }}</div>
                <div class="audit-meta">{{ l.user?.name ?? l.created_by ?? '–' }} · {{ l.created_at ? fmtTglPendek(l.created_at) : '' }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── CETAK LAPORAN OPERASI (A4) — hanya tampil saat print-op ─── -->
    <Teleport to="body">
      <div v-if="printOp" class="op-print">
        <div class="op-title">LAPORAN OPERASI</div>
        <table class="op-meta">
          <tr><td class="k">Nama Pasien</td><td>: {{ val(patient?.nama) }}</td><td class="k">No. RM</td><td>: {{ val(patient?.no_rm) }}</td></tr>
          <tr><td class="k">Tanggal Operasi</td><td>: {{ fmtTgl(printOp.visit_date) }}</td><td class="k">Jam</td><td>: {{ val(printOp.time_in) }}–{{ val(printOp.time_out) }}</td></tr>
          <tr><td class="k">Prosedur</td><td colspan="3">: {{ (printOp.procedures ?? []).length ? printOp.procedures.join(', ') : '–' }}</td></tr>
        </table>

        <div class="op-sec">Laporan Tindakan</div>
        <div class="op-notes">{{ printOp.detail?.operation_notes || '–' }}</div>

        <div class="op-sec">Lensa Intraokular (IOL) yang Ditanam</div>
        <table v-if="(printOp.iol_details ?? []).length" class="op-iol">
          <thead><tr><th>Mata</th><th>Merk / Model</th><th>Power</th><th>Lot</th><th>Serial</th><th>GTIN</th><th>Kadaluwarsa</th></tr></thead>
          <tbody>
            <tr v-for="(u, i) in printOp.iol_details" :key="i">
              <td><strong>{{ u.eye_side }}</strong></td>
              <td>{{ [u.brand, u.model].filter(Boolean).join(' ') || '–' }}</td>
              <td>{{ u.power != null ? `+${u.power} D` : '–' }}</td>
              <td>{{ u.lot_number || '–' }}</td>
              <td>{{ u.serial_number || '–' }}</td>
              <td>{{ u.gtin || '–' }}</td>
              <td>{{ u.expiry_date ? fmtTgl(u.expiry_date) : '–' }}</td>
            </tr>
          </tbody>
        </table>
        <div v-else class="op-muted">Tidak ada IOL yang ditanam pada operasi ini.</div>

        <div class="op-row2">
          <div><div class="op-sec">Komplikasi</div><div class="op-notes">{{ printOp.has_complication ? (printOp.detail?.complication_detail || 'Ada') : 'Tidak ada' }}</div></div>
          <div><div class="op-sec">Instruksi Pasca-Op</div><div class="op-notes">{{ printOp.detail?.post_op_instructions || '–' }}</div><div v-if="printOp.detail?.followup_date" class="op-fu">Kontrol: {{ fmtTgl(printOp.detail.followup_date) }}</div></div>
        </div>

        <div class="op-sign">
          <div>
            <div>Dokter Operator,</div>
            <div class="op-sign-space"></div>
            <div class="op-sign-name">( ………………………… )</div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ─── TOAST ─── -->
    <div class="toast-wrap">
      <div v-for="t in toasts" :key="t.id" :class="['toast', 'toast-'+t.type]">{{ t.msg }}</div>
    </div>
  </div>
</template>

<style scoped>
/* ─── PAGE ─── */
.rme-page { display: flex; flex-direction: column; gap: .75rem; color: #000; }

/* ─── SEARCH BAR ─── */
.rme-searchbar { background: #fff; border: 1px solid #e2e5ea; border-radius: 12px; padding: .65rem .9rem; }
.rsb-inner { position: relative; }
.rsb-mode { display: flex; background: #f1f3f6; border-radius: 8px; padding: 2px; gap: 2px; margin-bottom: .5rem; max-width: 240px; }
.rsb-mode-btn { flex: 1; height: 26px; border: none; border-radius: 6px; background: transparent; color: #6b7280; font-size: 11px; font-weight: 600; cursor: pointer; }
.rsb-mode-btn.a { background: #fff; color: #1763d4; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.rsb-input-wrap { display: flex; align-items: center; gap: .5rem; }
.rsb-icon { width: 14px; height: 14px; flex-shrink: 0; color: #9ca3af; }
.rsb-input { flex: 1; height: 36px; font-size: 13px; border: 1.5px solid #e2e5ea; border-radius: 8px; padding: 0 10px; background: #f8f9fb; outline: none; color: #000; }
.rsb-input:focus { border-color: #1763d4; background: #fff; }
.rsb-spinner { width: 14px; height: 14px; border: 2px solid #e2e5ea; border-top-color: #1763d4; border-radius: 50%; animation: spin .6s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.rsb-clear { display: inline-flex; align-items: center; gap: 5px; padding: 0 12px; height: 36px; border: 1.5px solid #e2e5ea; border-radius: 8px; background: #f8f9fb; color: #4b5563; font-size: 11.5px; font-weight: 600; cursor: pointer; }
.rsb-clear:hover { border-color: #dc2626; color: #dc2626; }
.rsb-clear svg { width: 12px; height: 12px; }
.rsb-dropdown { position: absolute; top: calc(100% + 6px); left: 0; right: 0; background: #fff; border: 1px solid #1763d4; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 100; max-height: 320px; overflow-y: auto; }
.rsb-item { display: flex; align-items: center; gap: 8px; padding: 8px 12px; cursor: pointer; }
.rsb-item:hover { background: #eef4ff; }
.rsb-sk { pointer-events: none; }
.rsi-bar { width: 3px; height: 40px; border-radius: 2px; flex-shrink: 0; }
.rsi-bar.bpjs { background: #3b82f6; } .rsi-bar.umum { background: var(--lm); } .rsi-bar.asn { background: #a855f7; }
.rsi-body { flex: 1; min-width: 0; }
.rsi-top { display: flex; align-items: center; gap: 6px; }
.rsi-rm { font-size: 10.5px; font-weight: 700; color: #1763d4; font-family: monospace; }
.rsi-cnt { font-size: 9px; color: #6b7280; background: #f1f3f6; padding: 1px 6px; border-radius: 10px; }
.rsi-name { font-size: 13px; font-weight: 600; color: #000; }
.rsi-meta { font-size: 10px; color: #6b7280; }
.rsb-empty { padding: 1rem; text-align: center; font-size: 11.5px; color: #9ca3af; }

/* ─── EMPTY ─── */
.empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1rem; text-align: center; padding: 4rem 2rem; background: #fff; border: 1px solid #e2e5ea; border-radius: 12px; }
.empty svg { width: 64px; height: 64px; fill: none; stroke: #cbd2dc; stroke-width: 1.5; stroke-linecap: round; }
.empty p { font-size: 13.5px; line-height: 1.7; color: #6b7280; }

/* ─── PATIENT BAR ─── */
.ptb { display: flex; align-items: center; gap: .85rem; padding: .8rem 1rem; background: #fff; border-radius: 12px; border: 1px solid #e2e5ea; }
.pti { flex: 1; min-width: 0; }
.ptn { font-size: 18px; font-weight: 700; line-height: 1.1; color: #000; }
.ptm { font-size: 11px; color: #6b7280; margin-top: 3px; }
.ptags { display: flex; gap: 5px; margin-top: 6px; flex-wrap: wrap; align-items: center; }
.ptg { font-size: 9.5px; font-weight: 700; padding: 3px 8px; border-radius: 4px; }
.ptg-bpjs { background: #dbeafe; color: #1e40af; } .ptg-umum { background: #ecfccb; color: #4d7c0f; } .ptg-asn { background: #f3e8ff; color: #7e22ce; }
.ptg-r { background: #fee2e2; color: #b91c1c; }
.copy-rm-btn { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border: 1.5px solid #e2e5ea; border-radius: 6px; background: #f8f9fb; color: #1763d4; font-size: 10px; font-weight: 700; font-family: monospace; cursor: pointer; }
.copy-rm-btn:hover { border-color: #1763d4; background: #eef4ff; }
.copy-rm-btn svg { width: 11px; height: 11px; fill: none; stroke: currentColor; stroke-width: 2; }
.pt-stats { display: flex; gap: 1.2rem; border-left: 1px solid #e2e5ea; padding-left: 1.2rem; }
.pt-stat { text-align: center; }
.pt-stat-v { font-size: 17px; font-weight: 700; line-height: 1; color: #000; }
.pt-stat-l { font-size: 9px; color: #6b7280; margin-top: 3px; }
.print-btn { display: inline-flex; align-items: center; gap: 5px; padding: 0 14px; height: 36px; border: 1.5px solid #1763d4; border-radius: 9px; background: #1763d4; color: #fff !important; font-size: 11.5px; font-weight: 700; cursor: pointer; }
.print-btn:hover { background: #1257bd; }
.print-btn svg { width: 13px; height: 13px; fill: none; stroke: #fff; stroke-width: 2; }

/* ─── MASTER-DETAIL ─── */
.rme-md { display: flex; gap: .75rem; align-items: flex-start; }
.rme-nav { width: 168px; flex-shrink: 0; background: #fff; border: 1px solid #e2e5ea; border-radius: 12px; padding: 6px; display: flex; flex-direction: column; gap: 2px; position: sticky; top: .5rem; }
.rnav-item { display: flex; align-items: center; gap: 9px; padding: 10px 13px; border: none; border-radius: 8px; background: transparent; color: #374151; font-size: 12.5px; font-weight: 600; cursor: pointer; text-align: left; transition: background .12s; }
.rnav-item:hover { background: #f1f3f6; }
.rnav-item.a { background: #1763d4; color: #fff !important; }
.rnav-ct { margin-left: auto; font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 10px; background: rgba(0,0,0,.08); }
.rnav-item.a .rnav-ct { background: rgba(255,255,255,.25); color: #fff; }

.rme-data { flex: 1; min-width: 0; background: #fff; border: 1px solid #e2e5ea; border-radius: 12px; padding: 1rem; min-height: 340px; }
.data-load { padding: .5rem; }

/* ─── TABLE ─── */
.tbl-wrap { overflow-x: auto; }
.rme-table { width: 100%; border-collapse: collapse; font-size: 12px; color: #000; }
.rme-table thead th { text-align: left; font-size: 10.5px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .03em; padding: 8px 10px; border-bottom: 2px solid #eceef2; white-space: nowrap; }
.rme-table tbody td { padding: 9px 10px; border-bottom: 1px solid #f1f3f6; vertical-align: top; }
.row-click { cursor: pointer; }
.row-click:hover td { background: #f8fafe; }
.row-detail td { background: #f8fafc; padding: .75rem 1rem !important; }
.nowrap { white-space: nowrap; }
.mono { font-family: monospace; font-size: 11.5px; }
.ta-r { text-align: right; }
.chev { color: #9ca3af; font-size: 9px; text-align: center; width: 28px; }
.trunc { max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cell-2 { display: flex; flex-direction: column; line-height: 1.3; }
.cell-2 small { color: #6b7280; font-size: 10px; }
.cls-badge { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 5px; white-space: nowrap; }
.g-pill { font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 4px; }
.g-pill.bpjs { background: #dbeafe; color: #1e40af; } .g-pill.umum { background: #ecfccb; color: #4d7c0f; } .g-pill.asn { background: #f3e8ff; color: #7e22ce; }
.dx-inline b { color: #1763d4; }
.dx-sub { font-size: 11px; line-height: 1.5; } .dx-sub b { color: #1763d4; }
.badges { white-space: nowrap; }
.b-mini { display: inline-block; font-size: 9px; font-weight: 700; padding: 2px 6px; border-radius: 4px; margin-right: 3px; }
.b-mini.final { background: #dcfce7; color: #15803d; } .b-mini.pj { background: #fef3c7; color: #92400e; } .b-mini.sep { background: #e0e7ff; color: #3730a3; }
.st-pill { font-size: 9.5px; font-weight: 700; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
.st-pill.final, .st-pill.completed, .st-pill.approved, .st-pill.reviewed { background: #dcfce7; color: #15803d; }
.st-pill.waiting, .st-pill.pending { background: #fef3c7; color: #92400e; }
.st-pill.draft { background: #f1f3f6; color: #4b5563; }
.st-pill.rejected, .st-pill.void { background: #fee2e2; color: #b91c1c; }
.att-link { font-size: 15px; text-decoration: none; }

/* ─── DETAIL EXPAND ─── */
.det-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
.det-box { background: #fff; border: 1px solid #eceef2; border-radius: 8px; padding: .55rem .7rem; font-size: 11.5px; line-height: 1.5; color: #000; }
.det-box.span2 { grid-column: 1 / -1; }
.det-t { font-size: 10px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 4px; }
.det-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; background: #f8fafc; }
.rm17-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; font-size: 12px; font-weight: 600; color: #fff; background: #0E3A66; border: none; border-radius: 7px; cursor: pointer; }
.rm17-btn:hover { filter: brightness(1.08); }
.rm17-btn:disabled { opacity: .6; cursor: default; }
.rm17-hint { color: #b45309; font-size: 11px; }
.det-note { margin-top: 5px; color: #6b7280; font-size: 10.5px; }
.vt-row { display: flex; flex-wrap: wrap; gap: .4rem .8rem; }
.vt-row span { background: #f1f3f6; padding: 1px 7px; border-radius: 5px; font-size: 10.5px; }
.soap-mini { margin-bottom: 2px; } .soap-mini b { color: #1763d4; margin-right: 5px; }
.kv-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 3px 1rem; }
.kv { display: flex; gap: 6px; font-size: 11px; }
.kv-k { color: #6b7280; text-transform: capitalize; min-width: 90px; }
.kv-v { font-weight: 600; }
.sub-table { width: 100%; border-collapse: collapse; font-size: 11.5px; background: #fff; border-radius: 8px; overflow: hidden; color: #000; }
.sub-table th { text-align: left; font-size: 10px; font-weight: 700; color: #6b7280; padding: 6px 9px; background: #f1f3f6; }
.sub-table td { padding: 6px 9px; border-bottom: 1px solid #f1f3f6; }

/* ─── RINGKASAN ─── */
.rk-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .75rem; }
.rk-card { background: #f8fafc; border: 1px solid #eceef2; border-radius: 10px; padding: .85rem 1rem; color: #000; }
.rk-card.span2 { grid-column: 1 / -1; }
.rk-card.alert { background: #fff7ed; border-color: #fed7aa; }
.rk-card-t { font-size: 11px; font-weight: 700; color: #374151; margin-bottom: .6rem; display: flex; justify-content: space-between; align-items: center; }
.rk-date { font-size: 10px; font-weight: 600; color: #6b7280; }
.rk-alert-body { font-size: 12px; line-height: 1.7; }
.mini-eye { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.mini-eye th { font-size: 10px; color: #6b7280; padding: 3px 6px; text-align: center; }
.mini-eye td { padding: 5px 6px; text-align: center; font-weight: 600; }
.mini-eye td:first-child { text-align: left; color: #6b7280; font-weight: 500; }
.unit { font-size: 9px; color: #9ca3af; font-weight: 400; }
.tr { display: inline-block; width: 0; height: 0; margin-left: 2px; }
.tr.up { border-left: 4px solid transparent; border-right: 4px solid transparent; border-bottom: 6px solid #15803d; }
.tr.down { border-left: 4px solid transparent; border-right: 4px solid transparent; border-top: 6px solid #dc2626; }
.tr.flat { width: 7px; height: 0; border-top: 2px solid #9ca3af; }
.rk-last-row { display: flex; justify-content: space-between; font-size: 12px; padding: 3px 0; border-bottom: 1px dashed #eceef2; }
.rk-last-row span { color: #6b7280; }
.pl-list { display: flex; flex-direction: column; gap: 4px; }
.pl-item { display: flex; align-items: center; gap: 10px; font-size: 12px; padding: 5px 8px; background: #fff; border-radius: 6px; border: 1px solid #eceef2; }
.pl-kode { font-family: monospace; font-weight: 700; color: #1763d4; min-width: 60px; }
.pl-nama { flex: 1; }
.pl-meta { font-size: 10px; color: #9ca3af; }

/* ─── STATES ─── */
.empty-mini { text-align: center; padding: 2.5rem 1rem; font-size: 12.5px; color: #9ca3af; }
.error-state { display: flex; align-items: center; gap: .65rem; padding: 1rem 1.25rem; background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; font-size: 12.5px; color: #b91c1c; }
.error-state svg { width: 16px; height: 16px; flex-shrink: 0; } .error-state span { flex: 1; }
.retry-btn { padding: 5px 13px; border: 1.5px solid #b91c1c; border-radius: 7px; background: #fff; color: #b91c1c; font-size: 11px; font-weight: 700; cursor: pointer; }
.retry-btn:hover { background: #b91c1c; color: #fff; }
.sk-line { background: #eceef2; border-radius: 6px; animation: shimmer 1.4s ease infinite; }
.sk-line.w40 { width: 40%; } .sk-line.w50 { width: 50%; } .sk-line.w70 { width: 70%; } .sk-line.w90 { width: 90%; }
.mb4 { margin-bottom: 4px; } .mb6 { margin-bottom: 6px; }
@keyframes shimmer { 0%,100%{opacity:.5} 50%{opacity:1} }

/* ─── DOC ACTIONS ─── */
.doc-acts { white-space: nowrap; }
.dbtn { padding: 4px 11px; border: 1.5px solid #1763d4; border-radius: 6px; background: #1763d4; color: #fff !important; font-size: 10.5px; font-weight: 700; cursor: pointer; margin-left: 4px; }
.dbtn:hover { background: #1257bd; }
.dbtn.ghost { background: #fff; color: #1763d4 !important; }
.dbtn.ghost:hover { background: #eef4ff; }

/* ─── MODAL / OVERLAY ─── */
.ov { position: fixed; inset: 0; background: rgba(15,23,42,.5); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 1rem; }
.mbx { background: #fff; border-radius: 14px; width: 100%; max-width: 480px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; }
.mbx-lg { max-width: 620px; }
/* ─── PENUNJANG MODAL ─── */
.pj-sec-t { font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .03em; margin: 1rem 0 .5rem; }
.pj-sec-t:first-of-type { margin-top: .4rem; }
.pj-attach { text-align: center; }
.pj-img { max-width: 100%; max-height: 340px; border-radius: 10px; border: 1px solid #e2e5ea; cursor: zoom-in; }
.pj-file { display: inline-flex; align-items: center; gap: 9px; padding: .7rem 1rem; border: 1.5px solid #1763d4; border-radius: 10px; background: #eef4ff; color: #1763d4; font-size: 12.5px; font-weight: 600; text-decoration: none; }
.pj-file:hover { background: #dbe8ff; }
.pj-file-ic { font-size: 18px; }
.pj-kv { background: #f8fafc; border: 1px solid #eceef2; border-radius: 8px; padding: .7rem .85rem; }
.pj-meta { margin-top: 1rem; padding-top: .75rem; border-top: 1px dashed #e2e5ea; font-size: 11.5px; color: #374151; display: flex; flex-direction: column; gap: 4px; }
.pj-meta-k { display: inline-block; min-width: 90px; color: #6b7280; font-weight: 600; }
.drawer { background: #fff; border-radius: 14px; width: 100%; max-width: 420px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; margin-left: auto; }
.mh { display: flex; justify-content: space-between; align-items: flex-start; padding: 1rem 1.2rem; border-bottom: 1px solid #eceef2; }
.mht { font-size: 14px; font-weight: 700; color: #000; }
.msub { font-size: 11px; color: #6b7280; margin-top: 2px; }
.mcl { width: 30px; height: 30px; border: none; background: #f1f3f6; border-radius: 8px; cursor: pointer; font-size: 14px; color: #4b5563; }
.mcl:hover { background: #e5e7eb; }
.mb { padding: 1.2rem; overflow-y: auto; color: #000; }
.mbtn { padding: 8px 16px; border: 1.5px solid #1763d4; border-radius: 8px; background: #1763d4; color: #fff !important; font-size: 12px; font-weight: 700; cursor: pointer; }
.mbtn:hover { background: #1257bd; }
.mbtn.ghost { background: #fff; color: #1763d4 !important; }
.mbtn.primary:disabled { opacity: .6; cursor: default; }
.mh-acts { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1rem; }
.doc-patient-bar { font-size: 12px; padding: .6rem .8rem; background: #f8fafc; border-radius: 8px; margin-bottom: .8rem; }
.doc-status-block { padding: .8rem 1rem; border-radius: 10px; }
.doc-status-block.final { background: #dcfce7; } .doc-status-block.waiting { background: #fef3c7; }
.doc-status-block.draft { background: #f1f3f6; } .doc-status-block.rejected, .doc-status-block.void { background: #fee2e2; }
.dsb-title { font-size: 13px; font-weight: 700; }
.dsb-desc { font-size: 11.5px; margin-top: 4px; line-height: 1.5; }
.add-hint { font-size: 11.5px; color: #92400e; line-height: 1.6; margin-bottom: 1rem; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; padding: .6rem .8rem; }
.fld-l { display: block; font-size: 11px; font-weight: 700; color: #374151; margin-bottom: 4px; margin-top: .7rem; }
.fld-i, .fld-t { width: 100%; border: 1.5px solid #e2e5ea; border-radius: 8px; padding: 8px 10px; font-size: 12.5px; color: #000; outline: none; font-family: inherit; box-sizing: border-box; }
.fld-i:focus, .fld-t:focus { border-color: #1763d4; }

/* ─── AUDIT ─── */
.audit-tl { display: flex; flex-direction: column; gap: 0; }
.audit-item { display: flex; gap: 10px; }
.audit-dot { width: 9px; height: 9px; border-radius: 50%; background: #1763d4; margin-top: 4px; flex-shrink: 0; position: relative; }
.audit-item:not(:last-child) .audit-dot::after { content: ''; position: absolute; left: 50%; top: 12px; transform: translateX(-50%); width: 2px; height: calc(100% + 12px); background: #e2e5ea; }
.audit-body { padding-bottom: 1rem; }
.audit-act { font-size: 12px; font-weight: 600; color: #000; }
.audit-meta { font-size: 10.5px; color: #6b7280; margin-top: 1px; }

/* ─── TOAST ─── */
.toast-wrap { position: fixed; bottom: 1.2rem; right: 1.2rem; display: flex; flex-direction: column; gap: .5rem; z-index: 2000; }
.toast { padding: 10px 16px; border-radius: 9px; font-size: 12.5px; font-weight: 600; color: #fff !important; box-shadow: 0 4px 14px rgba(0,0,0,.15); animation: slideup .25s ease; }
.toast-s { background: #15803d; } .toast-w { background: #b45309; } .toast-e { background: #dc2626; } .toast-i { background: #1763d4; }
.toast-w { color: #fff !important; }
@keyframes slideup { from { transform: translateY(10px); opacity: 0; } }

/* ─── Tabel IOL Details di baris bedah (layar) ─── */
.iol-det-tbl { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 12px; }
.iol-det-tbl th, .iol-det-tbl td { border: 1px solid var(--gb); padding: 4px 8px; text-align: left; }
.iol-det-tbl th { background: var(--bs); color: var(--tm); font-weight: 600; font-size: 11px; }
.iol-det-tbl .mono { font-family: 'JetBrains Mono', monospace; font-size: 11px; }
.rme-print-op { background: var(--gd); color: #fff; border: none; border-radius: 7px; padding: 7px 14px; font-size: 12.5px; font-weight: 600; cursor: pointer; }
.rme-print-op:hover { background: var(--gm); }

/* ─── PRINT ─── */
.resume-print, .op-print { display: none; }
@media print {
  /* reset base.css agar tidak blank (lihat memori print A4) */
  :global(html), :global(body) { min-width: 0 !important; width: auto !important; height: auto !important; min-height: 0 !important; background: #fff !important; }
  :global(#app) { display: none !important; }
  @page { size: A4; margin: 0; }

  /* Resume penuh (default). Disembunyikan saat mode cetak laporan operasi. */
  .resume-print { display: block; position: absolute; top: 0; left: 0; width: 100%; padding: 1.5cm; color: #000; font-size: 12px; }
  :global(body.print-op) .resume-print { display: none !important; }
  .rp-header { text-align: center; font-size: 11px; line-height: 1.5; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 12px; }
  .resume-print h2 { text-align: center; font-size: 15px; margin: 12px 0; }
  .resume-print h3 { font-size: 13px; margin: 14px 0 6px; }
  .rp-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
  .rp-table td { border: 1px solid #000; padding: 4px 8px; font-size: 11px; }
  .rp-table td:nth-child(odd) { background: #f0f0f0; font-weight: 700; width: 14%; }
  .rp-grid { width: 100%; border-collapse: collapse; }
  .rp-grid th, .rp-grid td { border: 1px solid #000; padding: 4px 8px; font-size: 11px; text-align: left; }
  .rp-grid th { background: #f0f0f0; }
  .rp-footer { margin-top: 20px; font-size: 10px; text-align: right; color: #555; }

  /* Laporan Operasi (hanya saat body.print-op). */
  :global(body.print-op) .op-print { display: block !important; position: absolute; top: 0; left: 0; width: 100%; padding: 1.5cm; color: #000; font-size: 12px; }
  .op-title { text-align: center; font-size: 16px; font-weight: 700; margin-bottom: 14px; letter-spacing: 0.5px; }
  .op-meta { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
  .op-meta td { padding: 3px 4px; font-size: 11.5px; vertical-align: top; }
  .op-meta td.k { font-weight: 700; width: 16%; }
  .op-sec { font-weight: 700; font-size: 12.5px; border-bottom: 1px solid #000; padding-bottom: 3px; margin: 14px 0 6px; }
  .op-notes { font-size: 11.5px; white-space: pre-wrap; line-height: 1.5; min-height: 18px; }
  .op-iol { width: 100%; border-collapse: collapse; margin-top: 4px; }
  .op-iol th, .op-iol td { border: 1px solid #000; padding: 4px 6px; font-size: 10.5px; text-align: left; }
  .op-iol th { background: #f0f0f0; }
  .op-muted { font-size: 11px; color: #555; font-style: italic; }
  .op-row2 { display: flex; gap: 24px; margin-top: 4px; }
  .op-row2 > div { flex: 1; }
  .op-fu { font-size: 11px; margin-top: 4px; }
  .op-sign { margin-top: 36px; display: flex; justify-content: flex-end; }
  .op-sign { text-align: center; font-size: 11.5px; }
  .op-sign-space { height: 56px; }
  .op-sign-name { border-top: 1px solid #000; padding-top: 3px; }
}
</style>
