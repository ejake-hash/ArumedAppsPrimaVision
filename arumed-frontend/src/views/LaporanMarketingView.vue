<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { marketingApi, masterApi } from '@/services/api'
import KwitansiPrintDoc from '@/components/common/KwitansiPrintDoc.vue'

// ─── Tab utama: Notifikasi (baru) + tipe layanan (IGD sengaja tidak ada) ───────
const MAIN_TABS = [
  { val: 'NOTIF', label: 'Notifikasi' },
  { val: 'RJ',    label: 'Rawat Jalan' },
  { val: 'RI',    label: 'Rawat Inap' },
  { val: 'BEDAH', label: 'Bedah' },
]
const activeTab = ref('NOTIF')
const serviceType = ref('RJ') // tab layanan aktif (RJ/RI/BEDAH) saat bukan di Notifikasi

// ─── Sub-filter jenis notifikasi (digerakkan oleh KPI card) ───────────────────
const notifFilter = ref('all')

// ─── State Notifikasi ─────────────────────────────────────────────────────────
const notifRows = ref([])
const notifCounts = ref({ kontrol: 0, tindakan: 0, ulang_tahun: 0, nyeri: 0, total: 0 })
const notifLoading = ref(false)
// Ceklis "selesai" SEMENTARA (in-memory) — keyed by ref_id, reset saat reload/pindah halaman.
const doneMap = ref({})

// Badge warna per jenis notifikasi.
const TYPE_BADGE = {
  kontrol:     { label: 'Kontrol',  cls: 'b-kontrol' },
  tindakan:    { label: 'Tindakan', cls: 'b-tindakan' },
  ulang_tahun: { label: 'Ultah',    cls: 'b-ultah' },
  nyeri:       { label: 'Nyeri',    cls: 'b-nyeri' },
}

const filteredNotif = computed(() => {
  const list = notifFilter.value === 'all'
    ? notifRows.value
    : notifRows.value.filter(r => r.type === notifFilter.value)
  return list
})
const doneCount = computed(() => Object.values(doneMap.value).filter(Boolean).length)

// ─── KPI cards Notifikasi (juga berfungsi sebagai filter cepat) ───────────────
const notifCards = computed(() => [
  { key: 'all',         label: 'Total Pengingat', n: notifCounts.value.total,       tone: 'navy'   },
  { key: 'kontrol',     label: 'Follow-up Kontrol', n: notifCounts.value.kontrol,   tone: 'sky'    },
  { key: 'tindakan',    label: 'Tindakan Terjadwal', n: notifCounts.value.tindakan, tone: 'purple' },
  { key: 'ulang_tahun', label: 'Ulang Tahun', n: notifCounts.value.ulang_tahun,     tone: 'pink'   },
  { key: 'nyeri',       label: 'Follow-up Nyeri', n: notifCounts.value.nyeri,       tone: 'orange' },
])

// ─── Pencarian layanan (by nama / NIK) — client-side, instan ─────────────────
const searchQuery = ref('')
const filteredRows = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return rows.value
  // NIK dibandingkan hanya digit-nya supaya spasi/tanda baca tidak mengganggu.
  const qDigits = q.replace(/\D/g, '')
  return rows.value.filter((r) => {
    const nama = (r.nama || '').toLowerCase()
    const nik = String(r.nik || '')
    return nama.includes(q) || (qDigits && nik.includes(qDigits))
  })
})

// ─── KPI cards layanan (RJ/RI/Bedah) — ringkas hasil yang tampil ─────────────
const serviceCards = computed(() => {
  const list = filteredRows.value
  const total = list.length
  let bpjs = 0, umum = 0
  for (const r of list) {
    const p = (r.penjamin || '').toUpperCase()
    if (p.includes('BPJS')) bpjs++
    else if (p) umum++
  }
  return [
    { label: 'Total Pasien', n: total, tone: 'navy' },
    { label: 'Penjamin BPJS', n: bpjs, tone: 'sky' },
    { label: 'Umum / Lainnya', n: umum, tone: 'purple' },
  ]
})

// ─── Filter periode (default: awal bulan → hari ini) ──────────────────────────
function isoToday() {
  const d = new Date()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${m}-${day}`
}
function isoMonthStart() {
  const d = new Date()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  return `${d.getFullYear()}-${m}-01`
}
const from = ref(isoMonthStart())
const to = ref(isoToday())

// ─── State ────────────────────────────────────────────────────────────────────
const rows = ref([])
const loading = ref(false)
const openFmt = ref(false)

// ─── Toast ────────────────────────────────────────────────────────────────────
const toastMsg = ref('')
const toastType = ref('s')
let toastTimer = null
function toast(type, msg) {
  toastType.value = type
  toastMsg.value = msg
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toastMsg.value = '' }, 3500)
}

// ─── Fetch daftar pasien per layanan (RJ/RI/Bedah) ────────────────────────────
async function load() {
  if (from.value && to.value && to.value < from.value) {
    toast('e', 'Tanggal "sampai" tidak boleh sebelum "dari".')
    return
  }
  loading.value = true
  try {
    const params = { service_type: serviceType.value, from: from.value, to: to.value }
    const res = await marketingApi.list(params)
    rows.value = res.data?.data?.rows || []
  } catch (e) {
    toast('e', e?.response?.data?.message || 'Gagal memuat data.')
    rows.value = []
  } finally {
    loading.value = false
  }
}

// ─── Fetch notifikasi ─────────────────────────────────────────────────────────
async function loadNotif() {
  notifLoading.value = true
  try {
    const res = await marketingApi.notifications()
    const data = res.data?.data || {}
    notifRows.value = data.rows || []
    notifCounts.value = data.counts || { kontrol: 0, tindakan: 0, ulang_tahun: 0, nyeri: 0, total: 0 }
    doneMap.value = {} // reset ceklis sementara setiap muat ulang
  } catch (e) {
    toast('e', e?.response?.data?.message || 'Gagal memuat notifikasi.')
    notifRows.value = []
    notifCounts.value = { kontrol: 0, tindakan: 0, ulang_tahun: 0, nyeri: 0, total: 0 }
  } finally {
    notifLoading.value = false
  }
}

async function selectTab(tab) {
  if (activeTab.value === tab) return
  activeTab.value = tab
  searchQuery.value = '' // mulai bersih tiap pindah tab
  if (tab === 'NOTIF') {
    if (!notifRows.value.length) await loadNotif()
  } else {
    serviceType.value = tab
    await load()
  }
}

function toggleDone(refId) {
  doneMap.value = { ...doneMap.value, [refId]: !doneMap.value[refId] }
}

function applyFilter() {
  load()
}

// ─── Export (CSV / Excel) ─────────────────────────────────────────────────────
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

async function exportData(format = 'csv') {
  openFmt.value = false
  try {
    const params = { service_type: serviceType.value, from: from.value, to: to.value }
    const res = await marketingApi.csvExport(params, format === 'xlsx' ? 'xlsx' : undefined)
    triggerDownload(res.data, `laporan-marketing-${serviceType.value.toLowerCase()}.${format}`)
  } catch (e) {
    toast('e', 'Gagal mengekspor data.')
  }
}

function closeFmt() { openFmt.value = false }

// ─── Cetak Kwitansi pasien (identik dgn KasirView) ────────────────────────────
// Master kategori tagihan utk urutan grouping rincian (sama dgn Kasir).
const billingCategories = ref([])
async function fetchBillingCategories() {
  try {
    const { data } = await masterApi.kategoriTagihan.list({ active: 1 })
    billingCategories.value = Array.isArray(data.data) ? data.data : (data.data?.data ?? [])
  } catch (e) {
    billingCategories.value = [] // non-fatal; fallback urutan default
  }
}

const printData = ref(null)
const printing = ref(false)

// Lihat & cetak kwitansi pasien dari baris layanan. Kwitansi hanya terbit bila
// invoice sudah LUNAS; selain itu pasien masih di Kasir → tampilkan notif.
async function cetakKwitansi(row) {
  if (!row?.invoice_id || !row?.invoice_paid) {
    toast('w', 'Kwitansi belum terbit, Pasien Sedang di Kasir')
    return
  }
  if (printing.value) return
  printing.value = true
  try {
    const res = await marketingApi.kwitansi(row.invoice_id)
    printData.value = res.data?.data || null
    await nextTick()
    setTimeout(() => window.print(), 80)
  } catch (e) {
    toast(
      e?.response?.status === 422 ? 'w' : 'e',
      e?.response?.data?.message || 'Gagal menyiapkan dokumen kwitansi.',
    )
  } finally {
    printing.value = false
  }
}

// Default tab = Notifikasi → muat notifikasi + master kategori (utk cetak) saat awal.
onMounted(() => {
  loadNotif()
  fetchBillingCategories()
})
</script>

<template>
  <div class="lm-page" @click="closeFmt">
    <header class="lm-head">
      <div class="lm-head-title">
        <span class="lm-head-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 11-5.8-1.6"/>
          </svg>
        </span>
        <div>
          <h1>Laporan Marketing</h1>
          <p class="sub" v-if="activeTab === 'NOTIF'">Pengingat siap-hubungi: follow-up kontrol, tindakan terjadwal, ulang tahun &amp; nyeri pasca-tindakan.</p>
          <p class="sub" v-else>Daftar pasien siap-olah untuk campaign (follow-up kontrol &amp; reaktivasi).</p>
        </div>
      </div>
      <!-- Export hanya pada tab layanan (Notifikasi tidak diekspor). -->
      <div class="fmt-wrap" @click.stop v-if="activeTab !== 'NOTIF'">
        <button class="btn-soft accent" title="Ekspor daftar — pilih format" @click="openFmt = !openFmt">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
          </svg>
          Export
          <svg class="caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div v-if="openFmt" class="fmt-menu">
          <button @click="exportData('csv')">CSV (.csv)</button>
          <button @click="exportData('xlsx')">Excel (.xlsx)</button>
        </div>
      </div>
    </header>

    <!-- Tab utama: Notifikasi + tipe layanan -->
    <div class="tabs">
      <button
        v-for="t in MAIN_TABS"
        :key="t.val"
        class="tab"
        :class="{ active: activeTab === t.val }"
        @click="selectTab(t.val)"
      >
        {{ t.label }}
        <span v-if="t.val === 'NOTIF' && notifCounts.total" class="tab-badge">{{ notifCounts.total }}</span>
      </button>
    </div>

    <!-- ════════════════ TAB NOTIFIKASI ════════════════ -->
    <template v-if="activeTab === 'NOTIF'">
      <!-- KPI cards — sekaligus filter cepat jenis notifikasi -->
      <div class="stat-grid">
        <button
          v-for="c in notifCards"
          :key="c.key"
          type="button"
          class="stat-card"
          :class="['tone-' + c.tone, { active: notifFilter === c.key }]"
          @click="notifFilter = c.key"
        >
          <span class="stat-icon">
            <svg v-if="c.key === 'all'" viewBox="0 0 24 24"><path d="M18 8a6 6 0 00-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 01-3.4 0"/></svg>
            <svg v-else-if="c.key === 'kontrol'" viewBox="0 0 24 24"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 106 5.3L3 8"/><path d="M12 7v5l4 2"/></svg>
            <svg v-else-if="c.key === 'tindakan'" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
            <svg v-else-if="c.key === 'ulang_tahun'" viewBox="0 0 24 24"><path d="M20 21v-8H4v8M2 21h20M7 8v3M12 8v3M17 8v3"/><path d="M12 8a2 2 0 002-2c0-1.5-2-4-2-4s-2 2.5-2 4a2 2 0 002 2z"/></svg>
            <svg v-else viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 000-7.78z"/></svg>
          </span>
          <span class="stat-body">
            <span class="stat-val">{{ c.n }}</span>
            <span class="stat-lbl">{{ c.label }}</span>
          </span>
        </button>
      </div>

      <!-- Toolbar ringkas -->
      <div class="filter-bar">
        <button class="btn-soft" @click="loadNotif" :disabled="notifLoading">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1015.5-6.4L21 8"/><path d="M21 3v5h-5"/></svg>
          Muat ulang
        </button>
        <span class="count" v-if="!notifLoading">{{ filteredNotif.length }} notifikasi · {{ doneCount }} selesai</span>
      </div>

      <div class="table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th style="width:48px">No</th>
              <th style="width:64px; text-align:center">Selesai</th>
              <th>Jenis</th>
              <th>Nama</th>
              <th style="width:70px">Usia</th>
              <th>No. HP</th>
              <th style="width:120px">Tanggal</th>
              <th>Keterangan</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="notifLoading">
              <td colspan="8" class="empty">Memuat…</td>
            </tr>
            <tr v-else-if="!filteredNotif.length">
              <td colspan="8" class="empty">Tidak ada notifikasi.</td>
            </tr>
            <tr
              v-else
              v-for="r in filteredNotif"
              :key="r.ref_id"
              :class="{ 'row-done': doneMap[r.ref_id] }"
            >
              <td>{{ r.no }}</td>
              <td style="text-align:center">
                <input
                  type="checkbox"
                  class="chk"
                  :checked="!!doneMap[r.ref_id]"
                  @change="toggleDone(r.ref_id)"
                  title="Tandai sudah dihubungi/selesai"
                />
              </td>
              <td>
                <span class="badge" :class="(TYPE_BADGE[r.type] || {}).cls">
                  {{ (TYPE_BADGE[r.type] || {}).label || r.type_label }}
                </span>
              </td>
              <td class="strong">{{ r.nama }}</td>
              <td>{{ r.usia != null ? r.usia + ' th' : '-' }}</td>
              <td>{{ r.no_hp || '-' }}</td>
              <td>{{ r.tgl || '-' }}</td>
              <td>{{ r.keterangan || '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ════════════════ TAB LAYANAN (RJ/RI/Bedah) ════════════════ -->
    <template v-else>
      <!-- KPI ringkasan periode -->
      <div class="stat-grid stat-grid-3" v-if="!loading && rows.length">
        <div
          v-for="c in serviceCards"
          :key="c.label"
          class="stat-card"
          :class="'tone-' + c.tone"
        >
          <span class="stat-icon">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
          </span>
          <span class="stat-body">
            <span class="stat-val">{{ c.n }}</span>
            <span class="stat-lbl">{{ c.label }}</span>
          </span>
        </div>
      </div>

      <!-- Filter periode -->
      <div class="filter-bar">
        <label>Dari
          <input type="date" v-model="from" />
        </label>
        <label>Sampai
          <input type="date" v-model="to" />
        </label>
        <button class="btn-soft accent" @click="applyFilter" :disabled="loading">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
          Terapkan
        </button>
        <div class="search-box">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
          <input
            type="search"
            v-model="searchQuery"
            placeholder="Cari nama / NIK…"
            aria-label="Cari pasien berdasarkan nama atau NIK"
          />
          <button v-if="searchQuery" class="search-clear" title="Hapus pencarian" @click="searchQuery = ''">×</button>
        </div>
        <span class="count" v-if="!loading">
          {{ filteredRows.length }} pasien<span v-if="searchQuery"> · dari {{ rows.length }}</span>
        </span>
      </div>

      <!-- Tabel -->
      <div class="table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th style="width:48px">No</th>
              <th>Nama</th>
              <th style="width:70px">Usia</th>
              <th>No. HP</th>
              <th>Penjamin</th>
              <th>Dokter/DPJP</th>
              <th>Diagnosa</th>
              <th>Kategori Bedah</th>
              <th>Tgl Kontrol</th>
              <th style="width:120px; text-align:center">Kwitansi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="10" class="empty">Memuat…</td>
            </tr>
            <tr v-else-if="!rows.length">
              <td colspan="10" class="empty">Tidak ada data pada periode ini.</td>
            </tr>
            <tr v-else-if="!filteredRows.length">
              <td colspan="10" class="empty">Tidak ada pasien cocok dengan "{{ searchQuery }}".</td>
            </tr>
            <tr v-else v-for="r in filteredRows" :key="r.no">
              <td>{{ r.no }}</td>
              <td class="strong">{{ r.nama }}</td>
              <td>{{ r.usia != null ? r.usia + ' th' : '-' }}</td>
              <td>{{ r.no_hp || '-' }}</td>
              <td>{{ r.penjamin || '-' }}</td>
              <td>{{ r.dokter || '-' }}</td>
              <td>{{ r.diagnosa || '-' }}</td>
              <td>{{ r.kategori_bedah || '-' }}</td>
              <td>{{ r.tgl_kontrol || '-' }}</td>
              <td style="text-align:center">
                <button
                  class="btn-kwitansi"
                  :class="{ off: !r.invoice_paid }"
                  :disabled="printing"
                  :title="r.invoice_paid ? 'Lihat & cetak kwitansi' : 'Kwitansi belum terbit — pasien masih di Kasir'"
                  @click="cetakKwitansi(r)"
                >
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                  Kwitansi
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <transition name="toast">
      <div v-if="toastMsg" class="toast" :class="toastType">{{ toastMsg }}</div>
    </transition>

    <!-- Dokumen cetak kwitansi — IDENTIK dgn Kasir (komponen bersama, Teleport ke body). -->
    <KwitansiPrintDoc :data="printData" :categories="billingCategories" />
  </div>
</template>

<style scoped>
.lm-page { padding: 20px 24px; }

/* Header */
.lm-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
.lm-head-title { display: flex; align-items: center; gap: 13px; }
.lm-head-icon {
  width: 44px; height: 44px; flex-shrink: 0; border-radius: 13px;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, var(--gd), var(--ga)); color: #fff;
  box-shadow: 0 6px 16px rgba(31,170,224,.28);
}
.lm-head-icon svg { width: 23px; height: 23px; }
.lm-head h1 { font-size: 1.35rem; font-weight: 700; color: var(--gd); margin: 0; font-family: var(--font-display); }
.lm-head .sub { margin: 3px 0 0; color: var(--tu); font-size: 0.84rem; max-width: 640px; }

/* Tabs underline */
.tabs { display: flex; gap: 4px; border-bottom: 2px solid var(--gb); margin-bottom: 18px; }
.tab {
  padding: 9px 18px; background: none; border: none; cursor: pointer;
  font-size: 0.9rem; font-weight: 600; color: var(--tu); border-bottom: 2px solid transparent;
  margin-bottom: -2px; transition: color .15s, border-color .15s;
}
.tab:hover { color: var(--gd); }
.tab.active { color: var(--gd); border-bottom-color: var(--ga); }
.tab-badge {
  display: inline-block; margin-left: 7px; min-width: 18px; padding: 1px 6px;
  background: var(--ga); color: #fff; border-radius: 999px; font-size: 0.7rem; font-weight: 700; line-height: 1.4;
}

/* ── KPI stat cards ────────────────────────────────────────────────── */
.stat-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: 12px; margin-bottom: 18px;
}
.stat-grid-3 { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
.stat-card {
  display: flex; align-items: center; gap: 12px; text-align: left;
  background: var(--bc); border: 1px solid var(--gb); border-radius: 14px; padding: 14px 16px;
  cursor: default; transition: box-shadow .15s, border-color .15s, transform .12s;
  position: relative; overflow: hidden;
}
button.stat-card { cursor: pointer; font-family: inherit; }
button.stat-card:hover { box-shadow: 0 6px 18px rgba(14,58,102,.10); transform: translateY(-1px); }
.stat-card.active { border-color: var(--ga); box-shadow: 0 0 0 2px rgba(31,170,224,.25); }
.stat-icon {
  width: 42px; height: 42px; flex-shrink: 0; border-radius: 11px;
  display: flex; align-items: center; justify-content: center;
}
.stat-icon svg { width: 20px; height: 20px; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.stat-body { display: flex; flex-direction: column; line-height: 1.1; min-width: 0; }
.stat-val { font-size: 1.55rem; font-weight: 800; color: var(--td); font-family: var(--font-display); }
.stat-lbl { font-size: 0.76rem; color: var(--tu); font-weight: 600; margin-top: 3px; }

/* tones — icon tint per category */
.tone-navy   .stat-icon { background: var(--gl);  color: var(--gd); }
.tone-sky    .stat-icon { background: #e0f2fe; color: #0369a1; }
.tone-purple .stat-icon { background: #ede9fe; color: #6d28d9; }
.tone-pink   .stat-icon { background: #fce7f3; color: #be185d; }
.tone-orange .stat-icon { background: #ffedd5; color: #c2410c; }

/* Badge jenis notifikasi */
.badge {
  display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; white-space: nowrap;
}
.b-kontrol  { background: #e0f2fe; color: #0369a1; }
.b-tindakan { background: #ede9fe; color: #6d28d9; }
.b-ultah    { background: #fce7f3; color: #be185d; }
.b-nyeri    { background: #ffedd5; color: #c2410c; }

/* Checkbox selesai */
.chk { width: 17px; height: 17px; cursor: pointer; accent-color: var(--st); }
.row-done td { color: var(--th) !important; }
.row-done .strong { text-decoration: line-through; }

/* Filter bar */
.filter-bar { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; flex-wrap: wrap; }
.filter-bar label { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; color: var(--tm); font-weight: 600; }
.filter-bar input[type="date"] {
  border: 1px solid var(--gb); border-radius: 8px; padding: 6px 9px; font-size: 0.85rem; color: var(--td); background: var(--bc);
}
.filter-bar input[type="date"]:focus { outline: none; border-color: var(--ga); box-shadow: 0 0 0 2px rgba(31,170,224,.15); }
.count { font-size: 0.82rem; color: var(--tu); font-weight: 600; }

/* Search by nama / NIK */
.search-box { position: relative; display: inline-flex; align-items: center; }
.search-box > svg {
  position: absolute; left: 9px; width: 14px; height: 14px; color: var(--tu); pointer-events: none;
}
.search-box input {
  border: 1px solid var(--gb); border-radius: 8px; padding: 6px 26px 6px 28px;
  font-size: 0.85rem; color: var(--td); background: var(--bc); min-width: 200px;
}
.search-box input:focus { outline: none; border-color: var(--ga); box-shadow: 0 0 0 2px rgba(31,170,224,.15); }
.search-box input::-webkit-search-cancel-button { display: none; }
.search-clear {
  position: absolute; right: 6px; width: 18px; height: 18px; line-height: 1; padding: 0;
  border: none; background: var(--bs); color: var(--tm); border-radius: 50%; cursor: pointer;
  font-size: 14px; display: flex; align-items: center; justify-content: center;
}
.search-clear:hover { background: var(--gb); color: var(--gd); }

/* Buttons */
.btn-soft {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 14px; border: 1px solid var(--gb); border-radius: 9px; background: var(--bc);
  font-size: 0.85rem; font-weight: 600; color: var(--tm); cursor: pointer; transition: background .15s, border-color .15s;
}
.btn-soft:hover:not(:disabled) { background: var(--bs); border-color: var(--ga); }
.btn-soft:disabled { opacity: .55; cursor: not-allowed; }
.btn-soft.accent { background: var(--gd); border-color: var(--gd); color: #fff; }
.btn-soft.accent:hover:not(:disabled) { background: var(--gm); border-color: var(--gm); }
.btn-soft svg { width: 16px; height: 16px; }
.btn-soft .caret { width: 13px; height: 13px; }

/* Tombol cetak kwitansi per-baris */
.btn-kwitansi {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 5px 11px; border: 1px solid var(--gb); border-radius: 8px; background: var(--bc);
  font-size: 0.78rem; font-weight: 600; color: var(--gd); cursor: pointer; white-space: nowrap;
  transition: background .15s, border-color .15s, color .15s;
}
.btn-kwitansi:hover:not(:disabled) { background: var(--gl); border-color: var(--ga); }
.btn-kwitansi:disabled { opacity: .55; cursor: not-allowed; }
.btn-kwitansi svg { width: 14px; height: 14px; }
/* Belum LUNAS — tetap bisa diklik (memunculkan notif), tampil meredup. */
.btn-kwitansi.off { color: var(--tu); border-style: dashed; }

/* Export dropdown */
.fmt-wrap { position: relative; }
.fmt-menu {
  position: absolute; right: 0; top: calc(100% + 6px); z-index: 20;
  background: var(--bc); border: 1px solid var(--gb); border-radius: 10px; box-shadow: 0 8px 24px rgba(15,23,42,.12);
  min-width: 150px; overflow: hidden;
}
.fmt-menu button {
  display: block; width: 100%; text-align: left; padding: 9px 14px; background: none; border: none;
  font-size: 0.85rem; color: var(--tm); cursor: pointer;
}
.fmt-menu button:hover { background: var(--bs); }

/* Table */
.table-wrap { border: 1px solid var(--gb); border-radius: 14px; overflow: auto; background: var(--bc); box-shadow: 0 1px 3px rgba(14,58,102,.05); }
.po-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.po-table thead th {
  background: var(--bs); text-align: left; padding: 11px 13px; font-weight: 700; color: var(--gd);
  border-bottom: 1px solid var(--gb); white-space: nowrap; position: sticky; top: 0;
  font-size: 0.78rem; letter-spacing: .01em;
}
.po-table tbody td { padding: 10px 13px; border-bottom: 1px solid var(--bi); color: var(--td); vertical-align: top; }
.po-table tbody tr:last-child td { border-bottom: none; }
.po-table tbody tr:hover { background: var(--gl); }
.po-table .strong { font-weight: 600; }
.po-table .empty { text-align: center; color: var(--th); padding: 32px 12px; }

/* Toast */
.toast {
  position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
  padding: 11px 20px; border-radius: 9px; color: #fff; font-size: 0.88rem; font-weight: 600;
  box-shadow: 0 8px 24px rgba(15,23,42,.2); z-index: 100;
}
.toast.s { background: var(--st); }
.toast.e { background: var(--et); }
.toast.w { background: #b45309; }
.toast-enter-active, .toast-leave-active { transition: opacity .25s, transform .25s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translate(-50%, 10px); }
</style>
