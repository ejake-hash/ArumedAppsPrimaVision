<script setup>
import { ref, computed, onMounted } from 'vue'
import { marketingApi } from '@/services/api'

// ─── Tab utama: Notifikasi (baru) + tipe layanan (IGD sengaja tidak ada) ───────
const MAIN_TABS = [
  { val: 'NOTIF', label: 'Notifikasi' },
  { val: 'RJ',    label: 'Rawat Jalan' },
  { val: 'RI',    label: 'Rawat Inap' },
  { val: 'BEDAH', label: 'Bedah' },
]
const activeTab = ref('NOTIF')
const serviceType = ref('RJ') // tab layanan aktif (RJ/RI/BEDAH) saat bukan di Notifikasi

// ─── Sub-filter jenis notifikasi ──────────────────────────────────────────────
const NOTIF_TYPES = [
  { val: 'all',         label: 'Semua' },
  { val: 'ulang_tahun', label: 'Ulang Tahun' },
  { val: 'kontrol',     label: 'Follow-up Kontrol' },
  { val: 'tindakan',    label: 'Tindakan Terjadwal' },
  { val: 'nyeri',       label: 'Follow-up Nyeri' },
]
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

// Default tab = Notifikasi → muat notifikasi saat awal.
onMounted(loadNotif)
</script>

<template>
  <div class="lm-page" @click="closeFmt">
    <header class="lm-head">
      <div>
        <h1>Laporan Marketing</h1>
        <p class="sub" v-if="activeTab === 'NOTIF'">Pengingat siap-hubungi: follow-up kontrol, tindakan terjadwal, ulang tahun &amp; nyeri pasca-tindakan.</p>
        <p class="sub" v-else>Daftar pasien siap-olah untuk campaign (follow-up kontrol &amp; reaktivasi).</p>
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
      <!-- Sub-filter jenis + ringkasan -->
      <div class="filter-bar">
        <div class="chips">
          <button
            v-for="ft in NOTIF_TYPES"
            :key="ft.val"
            class="chip"
            :class="{ active: notifFilter === ft.val }"
            @click="notifFilter = ft.val"
          >
            {{ ft.label }}
            <span v-if="ft.val !== 'all' && notifCounts[ft.val]" class="chip-n">{{ notifCounts[ft.val] }}</span>
          </button>
        </div>
        <button class="btn-soft" @click="loadNotif" :disabled="notifLoading">Muat ulang</button>
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
      <!-- Filter periode -->
      <div class="filter-bar">
        <label>Dari
          <input type="date" v-model="from" />
        </label>
        <label>Sampai
          <input type="date" v-model="to" />
        </label>
        <button class="btn-soft" @click="applyFilter" :disabled="loading">Terapkan</button>
        <span class="count" v-if="!loading">{{ rows.length }} pasien</span>
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
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="9" class="empty">Memuat…</td>
            </tr>
            <tr v-else-if="!rows.length">
              <td colspan="9" class="empty">Tidak ada data pada periode ini.</td>
            </tr>
            <tr v-else v-for="r in rows" :key="r.no">
              <td>{{ r.no }}</td>
              <td class="strong">{{ r.nama }}</td>
              <td>{{ r.usia != null ? r.usia + ' th' : '-' }}</td>
              <td>{{ r.no_hp || '-' }}</td>
              <td>{{ r.penjamin || '-' }}</td>
              <td>{{ r.dokter || '-' }}</td>
              <td>{{ r.diagnosa || '-' }}</td>
              <td>{{ r.kategori_bedah || '-' }}</td>
              <td>{{ r.tgl_kontrol || '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <transition name="toast">
      <div v-if="toastMsg" class="toast" :class="toastType">{{ toastMsg }}</div>
    </transition>
  </div>
</template>

<style scoped>
.lm-page { padding: 20px 24px; }

.lm-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.lm-head h1 { font-size: 1.4rem; font-weight: 700; color: #0E3A66; margin: 0; }
.lm-head .sub { margin: 4px 0 0; color: #64748b; font-size: 0.85rem; }

/* Tabs underline */
.tabs { display: flex; gap: 4px; border-bottom: 2px solid #e2e8f0; margin-bottom: 16px; }
.tab {
  padding: 9px 18px; background: none; border: none; cursor: pointer;
  font-size: 0.9rem; font-weight: 600; color: #64748b; border-bottom: 2px solid transparent;
  margin-bottom: -2px; transition: color .15s, border-color .15s;
}
.tab:hover { color: #0E3A66; }
.tab.active { color: #0E3A66; border-bottom-color: #1FAAE0; }
.tab-badge {
  display: inline-block; margin-left: 7px; min-width: 18px; padding: 1px 6px;
  background: #1FAAE0; color: #fff; border-radius: 999px; font-size: 0.7rem; font-weight: 700; line-height: 1.4;
}

/* Sub-filter chips (Notifikasi) */
.chips { display: flex; gap: 6px; flex-wrap: wrap; }
.chip {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 5px 12px; border: 1px solid #cbd5e1; border-radius: 999px; background: #fff;
  font-size: 0.8rem; font-weight: 600; color: #475569; cursor: pointer; transition: all .15s;
}
.chip:hover { border-color: #94a3b8; background: #f8fafc; }
.chip.active { background: #0E3A66; border-color: #0E3A66; color: #fff; }
.chip-n {
  min-width: 16px; padding: 0 5px; border-radius: 999px; font-size: 0.68rem; font-weight: 700;
  background: rgba(14,58,102,.12); color: #0E3A66;
}
.chip.active .chip-n { background: rgba(255,255,255,.25); color: #fff; }

/* Badge jenis notifikasi */
.badge {
  display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; white-space: nowrap;
}
.b-kontrol  { background: #e0f2fe; color: #0369a1; }
.b-tindakan { background: #ede9fe; color: #6d28d9; }
.b-ultah    { background: #fce7f3; color: #be185d; }
.b-nyeri    { background: #ffedd5; color: #c2410c; }

/* Checkbox selesai */
.chk { width: 17px; height: 17px; cursor: pointer; accent-color: #16a34a; }
.row-done td { color: #94a3b8 !important; }
.row-done .strong { text-decoration: line-through; }

/* Filter bar */
.filter-bar { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; flex-wrap: wrap; }
.filter-bar label { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; color: #475569; font-weight: 600; }
.filter-bar input[type="date"] {
  border: 1px solid #cbd5e1; border-radius: 7px; padding: 6px 9px; font-size: 0.85rem; color: #1e293b;
}
.filter-bar input[type="date"]:focus { outline: none; border-color: #1FAAE0; box-shadow: 0 0 0 2px rgba(31,170,224,.15); }
.count { font-size: 0.82rem; color: #64748b; }

/* Buttons */
.btn-soft {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 14px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff;
  font-size: 0.85rem; font-weight: 600; color: #334155; cursor: pointer; transition: background .15s, border-color .15s;
}
.btn-soft:hover:not(:disabled) { background: #f1f5f9; border-color: #94a3b8; }
.btn-soft:disabled { opacity: .55; cursor: not-allowed; }
.btn-soft.accent { background: #0E3A66; border-color: #0E3A66; color: #fff; }
.btn-soft.accent:hover:not(:disabled) { background: #0c3155; }
.btn-soft svg { width: 16px; height: 16px; }
.btn-soft .caret { width: 13px; height: 13px; }

/* Export dropdown */
.fmt-wrap { position: relative; }
.fmt-menu {
  position: absolute; right: 0; top: calc(100% + 6px); z-index: 20;
  background: #fff; border: 1px solid #e2e8f0; border-radius: 9px; box-shadow: 0 8px 24px rgba(15,23,42,.12);
  min-width: 150px; overflow: hidden;
}
.fmt-menu button {
  display: block; width: 100%; text-align: left; padding: 9px 14px; background: none; border: none;
  font-size: 0.85rem; color: #334155; cursor: pointer;
}
.fmt-menu button:hover { background: #f1f5f9; }

/* Table */
.table-wrap { border: 1px solid #e2e8f0; border-radius: 10px; overflow: auto; }
.po-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.po-table thead th {
  background: #f8fafc; text-align: left; padding: 10px 12px; font-weight: 700; color: #0E3A66;
  border-bottom: 1px solid #e2e8f0; white-space: nowrap; position: sticky; top: 0;
}
.po-table tbody td { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; color: #1e293b; vertical-align: top; }
.po-table tbody tr:hover { background: #f8fafc; }
.po-table .strong { font-weight: 600; }
.po-table .empty { text-align: center; color: #94a3b8; padding: 28px 12px; }

/* Toast */
.toast {
  position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
  padding: 11px 20px; border-radius: 9px; color: #fff; font-size: 0.88rem; font-weight: 600;
  box-shadow: 0 8px 24px rgba(15,23,42,.2); z-index: 100;
}
.toast.s { background: #16a34a; }
.toast.e { background: #dc2626; }
.toast-enter-active, .toast-leave-active { transition: opacity .25s, transform .25s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translate(-50%, 10px); }
</style>
