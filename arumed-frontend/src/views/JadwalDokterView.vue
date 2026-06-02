<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useJadwalDokterStore } from '@/stores/jadwalDokterStore'
import { useAuthStore } from '@/stores/authStore'
import { jadwalDokterApi } from '@/services/api'
import BpjsMappingModal from '@/components/jadwal-dokter/BpjsMappingModal.vue'

const store = useJadwalDokterStore()
const auth = useAuthStore()

// Pemetaan BPJS (poli/DPJP + sinkron jadwal) — config-level.
const showBpjsMapping = ref(false)
const canBpjsMapping = computed(() => auth.can?.('integrasi.read') || auth.isSuperadmin)

const HARI = [
  { val: 1, label: 'Senin' },
  { val: 2, label: 'Selasa' },
  { val: 3, label: 'Rabu' },
  { val: 4, label: 'Kamis' },
  { val: 5, label: 'Jumat' },
  { val: 6, label: 'Sabtu' },
  { val: 7, label: 'Minggu' },
]

// ─── Toast ───────────────────────────────────────────────────────────────────
const toastMsg  = ref('')
const toastType = ref('s')
let toastTimer  = null
function toast(type, msg) {
  toastType.value = type
  toastMsg.value  = msg
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toastMsg.value = '' }, 3500)
}

// ─── View state ──────────────────────────────────────────────────────────────
// Tampilkan jadwal dalam dua mode:
// 'grid'  = satu baris per dokter, kolom = hari Senin–Minggu
// 'list'  = semua jadwal dalam tabel datar
const viewMode = ref('grid')

const SERVICE_TABS = [
  { val: 'BPJS',      label: 'BPJS' },
  { val: 'EKSEKUTIF', label: 'Eksekutif' },
]

// ─── Tab jenis layanan ─────────────────────────────────────────────────────────
async function selectService(type) {
  if (store.serviceType === type) return
  await store.setServiceType(type)
}

// ─── Selector minggu ───────────────────────────────────────────────────────────
function fmtTanggal(d) {
  if (!d) return '—'
  const [y, m, day] = d.split('-')
  const bulan = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des']
  return `${parseInt(day)} ${bulan[parseInt(m) - 1]}`
}
function weekLabel(w) {
  return `${fmtTanggal(w.week_start)} – ${fmtTanggal(w.week_end)}${w.is_current ? ' (minggu ini)' : ''}`
}
async function onWeekChange(e) {
  await store.setWeek(e.target.value)
}

// ─── Salin ke minggu depan ──────────────────────────────────────────────────────
const copying = ref(false)
async function doCopyNextWeek() {
  copying.value = true
  try {
    const res = await store.copyToNextWeek()
    toast('s', res?.message ?? 'Jadwal disalin ke minggu depan')
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal menyalin jadwal')
  } finally {
    copying.value = false
  }
}

// ─── Menu format (CSV / Excel) ───────────────────────────────────────────────────
const openMenu = ref(null) // 'template' | 'export' | null
function toggleMenu(which) { openMenu.value = openMenu.value === which ? null : which }
function closeMenu() { openMenu.value = null }

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

// ─── Download template (CSV / Excel) ───────────────────────────────────────────────
async function downloadTemplate(format = 'csv') {
  closeMenu()
  try {
    const res = await jadwalDokterApi.csvTemplate(format === 'xlsx' ? 'xlsx' : undefined)
    triggerDownload(res.data, `template-jadwal-dokter.${format}`)
  } catch (e) {
    toast('e', 'Gagal mengunduh template')
  }
}

// ─── Export jadwal minggu aktif (CSV / Excel) ──────────────────────────────────────
async function exportJadwal(format = 'csv') {
  closeMenu()
  try {
    const params = {}
    if (store.weekStart) params.week_start = store.weekStart
    if (store.serviceType) params.service_type = store.serviceType
    const res = await jadwalDokterApi.csvExport(params, format === 'xlsx' ? 'xlsx' : undefined)
    triggerDownload(res.data, `jadwal-dokter-${store.weekStart || 'minggu-ini'}.${format}`)
  } catch (e) {
    toast('e', 'Gagal mengekspor jadwal')
  }
}

// ─── Import CSV ──────────────────────────────────────────────────────────────────
const fileInput   = ref(null)
const importing   = ref(false)
const importResult = ref(null) // { imported, skipped, errors, target_week }

function triggerImport() {
  fileInput.value?.click()
}
async function onFileChosen(e) {
  const file = e.target.files?.[0]
  if (!file) return
  importing.value = true
  importResult.value = null
  try {
    const res = await store.importCsv(file)
    importResult.value = res?.data ?? null
    toast(res?.errors?.length ? 'e' : 's', res?.message ?? 'Import selesai')
  } catch (err) {
    toast('e', err.response?.data?.message ?? 'Gagal mengimpor jadwal')
  } finally {
    importing.value = false
    e.target.value = '' // reset agar file sama bisa dipilih lagi
  }
}

// ─── Modal ───────────────────────────────────────────────────────────────────
const showModal   = ref(false)
const modalMode   = ref('add')   // 'add' | 'edit'
const saving      = ref(false)

const emptyForm = () => ({
  id:           null,
  employee_id:  '',
  day_of_week:  1,
  start_time:   '08:00',
  end_time:     '12:00',
  room:         '',
  poliklinik:   '',
  poli_code:    '',
  service_type: store.serviceType, // ikut tab aktif
  is_active:    true,
})
const form = ref(emptyForm())

function openAdd(employeeId = '', dayOfWeek = 1) {
  form.value = { ...emptyForm(), employee_id: employeeId, day_of_week: dayOfWeek }
  modalMode.value = 'add'
  showModal.value  = true
}

function openEdit(jadwal, employeeId) {
  form.value = {
    id:           jadwal.id,
    employee_id:  employeeId,
    day_of_week:  jadwal.day_of_week,
    start_time:   jadwal.start_time,
    end_time:     jadwal.end_time,
    room:         jadwal.room ?? '',
    poliklinik:   jadwal.poliklinik ?? '',
    poli_code:    jadwal.poli_code ?? '',
    service_type: jadwal.service_type ?? 'BPJS',
    is_active:    jadwal.is_active,
  }
  modalMode.value = 'edit'
  showModal.value  = true
}

async function saveForm() {
  if (!form.value.employee_id) { toast('e', 'Pilih dokter terlebih dahulu'); return }
  if (!form.value.start_time || !form.value.end_time) { toast('e', 'Jam praktik wajib diisi'); return }
  if (form.value.end_time <= form.value.start_time) { toast('e', 'Jam selesai harus lebih besar dari jam mulai'); return }

  saving.value = true
  try {
    const payload = {
      employee_id:  form.value.employee_id,
      day_of_week:  Number(form.value.day_of_week),
      start_time:   form.value.start_time,
      end_time:     form.value.end_time,
      room:         form.value.room || null,
      poliklinik:   form.value.poliklinik || null,
      poli_code:    form.value.poli_code || null,
      service_type: form.value.service_type,
      week_start:   store.weekStart, // simpan ke minggu yang sedang ditampilkan
      is_active:    form.value.is_active,
    }

    if (modalMode.value === 'edit') {
      await store.updateJadwal(form.value.id, payload)
      toast('s', 'Jadwal berhasil diperbarui')
    } else {
      await store.createJadwal(payload)
      toast('s', 'Jadwal berhasil ditambahkan')
    }
    showModal.value = false
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal menyimpan jadwal')
  } finally {
    saving.value = false
  }
}

// ─── Delete ──────────────────────────────────────────────────────────────────
const confirmId   = ref(null)
const confirmName = ref('')
const deleting    = ref(false)

function openConfirmDelete(jadwal, namaDokter) {
  confirmId.value   = jadwal.id
  confirmName.value = `${namaDokter} — ${hariLabel(jadwal.day_of_week)}`
}

async function doDelete() {
  if (!confirmId.value) return
  deleting.value = true
  try {
    await store.deleteJadwal(confirmId.value)
    toast('s', 'Jadwal dihapus')
    confirmId.value = null
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal menghapus jadwal')
  } finally {
    deleting.value = false
  }
}

// ─── Toggle aktif ────────────────────────────────────────────────────────────
const toggling = ref(null)
async function handleToggle(jadwalId) {
  toggling.value = jadwalId
  try {
    const updated = await store.toggleAktif(jadwalId)
    toast('s', updated.is_active ? 'Jadwal diaktifkan' : 'Jadwal dinonaktifkan')
  } catch (e) {
    toast('e', e.response?.data?.message ?? 'Gagal mengubah status')
  } finally {
    toggling.value = null
  }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function hariLabel(val) {
  return HARI.find((h) => h.val === val)?.label ?? `Hari ${val}`
}

// Untuk mode grid: cari jadwal dokter di hari tertentu
function getJadwalHari(empData, dayVal) {
  return empData.jadwal?.find((j) => j.day_of_week === dayVal) ?? null
}

// Semua jadwal dalam satu array datar untuk mode list
const allJadwal = computed(() => {
  const rows = []
  store.daftarDokter.forEach((emp) => {
    emp.jadwal?.forEach((j) => {
      rows.push({ ...j, nama_dokter: emp.nama_dokter, employee_id: emp.employee_id })
    })
  })
  return rows.sort((a, b) => a.day_of_week - b.day_of_week || a.nama_dokter.localeCompare(b.nama_dokter))
})

// Daftar unik nama dokter (dari store) untuk modal dropdown
const dokterOptions = computed(() =>
  store.daftarDokter.map((e) => ({ id: e.employee_id, label: e.nama_dokter }))
)

// Preview prefix antrian live di modal = {poli_code|D}{room}
const prefixPreview = computed(() => {
  const code = (form.value.poli_code || 'D').toUpperCase()
  return form.value.room ? `${code}${form.value.room}` : code
})

// Label minggu yang sedang aktif (untuk konteks modal)
const activeWeekLabel = computed(() => {
  const w = store.availableWeeks.find((x) => x.week_start === store.weekStart)
  return w ? weekLabel(w) : (store.weekStart ?? 'minggu ini')
})

onMounted(async () => {
  document.addEventListener('click', closeMenu)
  await store.fetchAvailableWeeks() // set weekStart default = minggu ini
  await store.fetchAll()
})
onUnmounted(() => document.removeEventListener('click', closeMenu))
</script>

<template>
  <div class="jd-page">

    <!-- ── HEADER ─────────────────────────────────────────────────────── -->
    <div class="jd-header">
      <div class="jd-header-left">
        <div class="jd-title-row">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="3" y="4" width="18" height="18" rx="2"/>
            <line x1="16" y1="2" x2="16" y2="6"/>
            <line x1="8" y1="2" x2="8" y2="6"/>
            <line x1="3" y1="10" x2="21" y2="10"/>
            <line x1="8" y1="14" x2="8" y2="14.01"/>
            <line x1="12" y1="14" x2="12" y2="14.01"/>
            <line x1="16" y1="14" x2="16" y2="14.01"/>
          </svg>
          <h1 class="jd-title">Jadwal Dokter</h1>
        </div>
        <p class="jd-subtitle">Kelola jadwal praktik dokter — Senin hingga Minggu</p>
      </div>
      <div class="jd-header-right">
        <!-- Toggle view mode -->
        <div class="view-toggle">
          <button :class="['vt-btn', { active: viewMode === 'grid' }]" @click="viewMode = 'grid'" title="Tampilan Grid">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
              <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>
          </button>
          <button :class="['vt-btn', { active: viewMode === 'list' }]" @click="viewMode = 'list'" title="Tampilan List">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/>
              <line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
          </button>
        </div>
        <button class="btn-primary" @click="openAdd()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          Tambah Jadwal
        </button>
      </div>
    </div>

    <!-- ── TOOLBAR: tab layanan + minggu + aksi CSV ─────────────────────── -->
    <div class="jd-toolbar">
      <!-- Tab jenis layanan -->
      <div class="svc-tabs">
        <button
          v-for="t in SERVICE_TABS"
          :key="t.val"
          :class="['svc-tab', { active: store.serviceType === t.val }, t.val.toLowerCase()]"
          @click="selectService(t.val)"
        >{{ t.label }}</button>
      </div>

      <!-- Selector minggu -->
      <div class="week-picker">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/>
          <line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
        <select :value="store.weekStart" @change="onWeekChange" class="week-select">
          <option v-for="w in store.availableWeeks" :key="w.week_start" :value="w.week_start">
            {{ weekLabel(w) }}
          </option>
        </select>
      </div>

      <div class="toolbar-spacer"></div>

      <!-- Aksi CSV & salin -->
      <button class="btn-soft" :disabled="copying" @click="doCopyNextWeek" title="Salin jadwal minggu ini ke minggu depan">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
        </svg>
        {{ copying ? 'Menyalin...' : 'Salin ke Minggu Depan' }}
      </button>
      <!-- Template (CSV / Excel) -->
      <div class="fmt-wrap">
        <button class="btn-soft" title="Unduh template — pilih format" @click.stop="toggleMenu('template')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
          </svg>
          Template
          <svg class="caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div v-if="openMenu === 'template'" class="fmt-menu">
          <button @click="downloadTemplate('csv')">CSV (.csv)</button>
          <button @click="downloadTemplate('xlsx')">Excel (.xlsx)</button>
        </div>
      </div>

      <!-- Export jadwal (CSV / Excel) -->
      <div class="fmt-wrap">
        <button class="btn-soft" title="Ekspor jadwal minggu aktif — pilih format" @click.stop="toggleMenu('export')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
          </svg>
          Export
          <svg class="caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div v-if="openMenu === 'export'" class="fmt-menu">
          <button @click="exportJadwal('csv')">CSV (.csv)</button>
          <button @click="exportJadwal('xlsx')">Excel (.xlsx)</button>
        </div>
      </div>

      <button class="btn-soft accent" :disabled="importing" @click="triggerImport" title="Impor jadwal dari CSV atau Excel">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        {{ importing ? 'Mengimpor...' : 'Import' }}
      </button>
      <input ref="fileInput" type="file" accept=".csv,.xlsx,.xls,.ods,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="hidden-file" @change="onFileChosen" />

      <button v-if="canBpjsMapping" class="btn-soft" @click="showBpjsMapping = true" title="Pemetaan poli & DPJP ke kode BPJS + sinkron jadwal">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
        </svg>
        Pemetaan BPJS
      </button>
    </div>

    <!-- ── LOADING / ERROR ───────────────────────────────────────────── -->
    <div v-if="store.loading" class="jd-loading">
      <svg class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 12a9 9 0 11-6.219-8.56"/>
      </svg>
      Memuat jadwal dokter...
    </div>
    <div v-else-if="store.error" class="jd-error">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      {{ store.error }}
    </div>

    <!-- ── EMPTY STATE ───────────────────────────────────────────────── -->
    <div v-else-if="store.daftarDokter.length === 0" class="jd-empty">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
        <rect x="3" y="4" width="18" height="18" rx="2"/>
        <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
        <line x1="3" y1="10" x2="21" y2="10"/>
      </svg>
      <p>Belum ada dokter terdaftar</p>
      <span class="jd-empty-hint">Tambahkan dokter di Master Data → Pegawai terlebih dahulu.</span>
    </div>

    <!-- ── MODE GRID ─────────────────────────────────────────────────── -->
    <template v-else-if="viewMode === 'grid'">
      <div class="grid-wrap">
        <table class="grid-table">
          <thead>
            <tr>
              <th class="col-dokter">Dokter</th>
              <th v-for="h in HARI" :key="h.val" class="col-hari">{{ h.label }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="emp in store.daftarDokter" :key="emp.employee_id">
              <td class="col-dokter">
                <div class="emp-name">{{ emp.nama_dokter }}</div>
              </td>
              <td v-for="h in HARI" :key="h.val" class="col-cell">
                <div v-if="getJadwalHari(emp, h.val)" class="cell-filled">
                  <div class="cell-jam">
                    {{ getJadwalHari(emp, h.val).start_time }} – {{ getJadwalHari(emp, h.val).end_time }}
                  </div>
                  <div v-if="getJadwalHari(emp, h.val).poliklinik" class="cell-poli">
                    {{ getJadwalHari(emp, h.val).poliklinik }}
                  </div>
                  <div v-if="getJadwalHari(emp, h.val).room" class="cell-room">
                    Ruang {{ getJadwalHari(emp, h.val).room }}
                    <span class="cell-prefix">({{ getJadwalHari(emp, h.val).queue_prefix }})</span>
                  </div>
                  <!-- Status toggle -->
                  <div class="cell-actions">
                    <button
                      :class="['cell-toggle', getJadwalHari(emp, h.val).is_active ? 'aktif' : 'nonaktif']"
                      :disabled="toggling === getJadwalHari(emp, h.val).id"
                      @click="handleToggle(getJadwalHari(emp, h.val).id)"
                      :title="getJadwalHari(emp, h.val).is_active ? 'Klik untuk nonaktifkan' : 'Klik untuk aktifkan'"
                    >
                      {{ getJadwalHari(emp, h.val).is_active ? 'Aktif' : 'Nonaktif' }}
                    </button>
                    <button class="cell-edit" @click="openEdit(getJadwalHari(emp, h.val), emp.employee_id)" title="Edit">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                      </svg>
                    </button>
                    <button class="cell-del" @click="openConfirmDelete(getJadwalHari(emp, h.val), emp.nama_dokter)" title="Hapus">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
                        <path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/>
                      </svg>
                    </button>
                  </div>
                </div>
                <!-- Empty cell — klik untuk tambah jadwal hari ini -->
                <button v-else class="cell-add" @click="openAdd(emp.employee_id, h.val)" title="Tambah jadwal hari ini">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                  </svg>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── MODE LIST ─────────────────────────────────────────────────── -->
    <template v-else>
      <div class="list-wrap">
        <table class="list-table">
          <thead>
            <tr>
              <th>Dokter</th>
              <th>Jenis</th>
              <th>Hari</th>
              <th>Jam Praktik</th>
              <th>Poliklinik</th>
              <th>Ruangan</th>
              <th>Prefix Antrian</th>
              <th>Status</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="allJadwal.length === 0">
              <td colspan="9" class="list-empty">Belum ada jadwal {{ store.serviceType === 'BPJS' ? 'BPJS' : 'Eksekutif' }} di minggu ini</td>
            </tr>
            <tr v-for="j in allJadwal" :key="j.id">
              <td class="td-dokter">{{ j.nama_dokter }}</td>
              <td><span :class="['svc-chip', (j.service_type || 'BPJS').toLowerCase()]">{{ j.service_type === 'EKSEKUTIF' ? 'Eksekutif' : 'BPJS' }}</span></td>
              <td><span class="hari-chip">{{ hariLabel(j.day_of_week) }}</span></td>
              <td class="td-jam">{{ j.start_time }} – {{ j.end_time }}</td>
              <td>{{ j.poliklinik ?? '—' }}</td>
              <td>{{ j.room ? `Ruang ${j.room}` : '—' }}</td>
              <td><span class="prefix-chip">{{ j.queue_prefix }}</span></td>
              <td>
                <button
                  :class="['status-toggle', j.is_active ? 'aktif' : 'nonaktif']"
                  :disabled="toggling === j.id"
                  @click="handleToggle(j.id)"
                >
                  {{ j.is_active ? 'Aktif' : 'Nonaktif' }}
                </button>
              </td>
              <td>
                <div class="td-actions">
                  <button class="icon-btn" @click="openEdit(j, j.employee_id)" title="Edit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                      <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                      <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                  </button>
                  <button class="icon-btn danger" @click="openConfirmDelete(j, j.nama_dokter)" title="Hapus">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                      <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
                      <path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/>
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── MODAL TAMBAH / EDIT ───────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
        <div class="modal-box">
          <div class="modal-head">
            <span>{{ modalMode === 'add' ? 'Tambah Jadwal Dokter' : 'Edit Jadwal Dokter' }}</span>
            <button class="modal-close" @click="showModal = false">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>

          <div class="modal-body">
            <!-- Konteks minggu -->
            <div class="modal-week-note">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
              </svg>
              Jadwal untuk minggu <strong>{{ activeWeekLabel }}</strong>
            </div>

            <!-- Jenis Layanan -->
            <div class="field">
              <label>Jenis Layanan <span class="req">*</span></label>
              <div class="svc-seg">
                <button
                  v-for="t in SERVICE_TABS"
                  :key="t.val"
                  type="button"
                  :class="['svc-seg-btn', { active: form.service_type === t.val }, t.val.toLowerCase()]"
                  @click="form.service_type = t.val"
                >{{ t.label }}</button>
              </div>
            </div>

            <!-- Dokter -->
            <div class="field">
              <label>Dokter <span class="req">*</span></label>
              <select v-model="form.employee_id" :disabled="modalMode === 'edit'" class="inp">
                <option value="">— Pilih dokter —</option>
                <option v-for="d in dokterOptions" :key="d.id" :value="d.id">{{ d.label }}</option>
              </select>
              <p v-if="dokterOptions.length === 0" class="field-note">
                Dokter belum ada. Tambahkan di menu Master Data → Pegawai terlebih dahulu.
              </p>
            </div>

            <!-- Hari -->
            <div class="field">
              <label>Hari <span class="req">*</span></label>
              <div class="hari-pills">
                <button
                  v-for="h in HARI"
                  :key="h.val"
                  :class="['hari-pill', { active: form.day_of_week === h.val }]"
                  type="button"
                  @click="form.day_of_week = h.val"
                >{{ h.label }}</button>
              </div>
            </div>

            <!-- Jam Praktik -->
            <div class="field-row">
              <div class="field">
                <label>Jam Mulai <span class="req">*</span></label>
                <input v-model="form.start_time" type="time" class="inp" />
              </div>
              <div class="field">
                <label>Jam Selesai <span class="req">*</span></label>
                <input v-model="form.end_time" type="time" class="inp" />
              </div>
            </div>

            <!-- Poliklinik (kode + nama) & Ruangan -->
            <div class="field-row-3">
              <div class="field">
                <label>Kode Poli</label>
                <input v-model="form.poli_code" type="text" class="inp" placeholder="cth: GLA" maxlength="10"
                       @input="form.poli_code = form.poli_code.toUpperCase()" />
              </div>
              <div class="field">
                <label>Nama Poliklinik</label>
                <input v-model="form.poliklinik" type="text" class="inp" placeholder="cth: Poliklinik Glaukoma" maxlength="100" />
              </div>
              <div class="field">
                <label>Ruangan</label>
                <input v-model="form.room" type="text" class="inp" placeholder="cth: 1" maxlength="50" />
              </div>
            </div>
            <p class="field-note prefix-note">
              Prefix antrian: <strong>{{ prefixPreview }}</strong> → {{ prefixPreview }}-001, {{ prefixPreview }}-002, …
              <span class="prefix-hint">Nama poliklinik tetap tampil ke pasien; kode hanya untuk nomor antrian.</span>
            </p>

            <!-- Status -->
            <div class="field">
              <label class="toggle-label-wrap">
                <span>Status Aktif</span>
                <button
                  type="button"
                  :class="['toggle-switch', { on: form.is_active }]"
                  @click="form.is_active = !form.is_active"
                >
                  <span class="toggle-knob"></span>
                </button>
                <span class="toggle-txt">{{ form.is_active ? 'Aktif' : 'Nonaktif' }}</span>
              </label>
            </div>
          </div>

          <div class="modal-foot">
            <button class="btn-ghost" @click="showModal = false">Batal</button>
            <button class="btn-primary" :disabled="saving" @click="saveForm">
              <svg v-if="saving" class="spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 12a9 9 0 11-6.219-8.56"/>
              </svg>
              {{ saving ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ── MODAL KONFIRMASI HAPUS ────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="confirmId" class="modal-overlay" @click.self="confirmId = null">
        <div class="modal-box sm">
          <div class="confirm-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round">
              <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
              <path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/>
            </svg>
          </div>
          <h3 class="confirm-title">Hapus Jadwal?</h3>
          <p class="confirm-sub">{{ confirmName }}</p>
          <div class="confirm-actions">
            <button class="btn-ghost" @click="confirmId = null">Batal</button>
            <button class="btn-danger" :disabled="deleting" @click="doDelete">
              {{ deleting ? 'Menghapus...' : 'Ya, Hapus' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ── MODAL HASIL IMPORT ────────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="importResult" class="modal-overlay" @click.self="importResult = null">
        <div class="modal-box">
          <div class="modal-head">
            <span>Hasil Import Jadwal</span>
            <button class="modal-close" @click="importResult = null">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>
          <div class="modal-body">
            <div class="import-summary">
              <div class="imp-stat ok">
                <span class="imp-num">{{ importResult.imported }}</span>
                <span class="imp-lbl">Berhasil masuk</span>
              </div>
              <div class="imp-stat skip">
                <span class="imp-num">{{ importResult.skipped }}</span>
                <span class="imp-lbl">Dilewati</span>
              </div>
              <div class="imp-stat err" v-if="importResult.errors?.length">
                <span class="imp-num">{{ importResult.errors.length }}</span>
                <span class="imp-lbl">Bermasalah</span>
              </div>
            </div>
            <p class="import-target">Minggu tujuan: <strong>{{ importResult.target_week }}</strong></p>

            <div v-if="importResult.errors?.length" class="import-errors">
              <p class="import-errors-title">Baris yang dilewati karena bermasalah:</p>
              <ul>
                <li v-for="(err, i) in importResult.errors" :key="i">{{ err }}</li>
              </ul>
            </div>
          </div>
          <div class="modal-foot">
            <button class="btn-primary" @click="importResult = null">Tutup</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ── TOAST ─────────────────────────────────────────────────────── -->
    <Teleport to="body">
      <Transition name="toast-fade">
        <div v-if="toastMsg" :class="['toast', toastType]">
          <svg v-if="toastType==='s'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
          <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          {{ toastMsg }}
        </div>
      </Transition>
    </Teleport>

    <!-- ── PEMETAAN BPJS (poli/DPJP + sinkron jadwal) ─────────────────── -->
    <BpjsMappingModal v-if="showBpjsMapping" :week-start="store.weekStart" @close="showBpjsMapping = false" />

  </div>
</template>

<style scoped>
/* ─── LAYOUT ──────────────────────────────────────────────────────────────── */
.jd-page {
  padding: 1.75rem 2rem;
  max-width: 1400px;
  margin: 0 auto;
  font-family: 'Inter', sans-serif;
  color: var(--text, #1a2e1a);
}

/* ─── HEADER ────────────────────────────────────────────────────────────────*/
.jd-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 1.5rem;
  gap: 1rem;
  flex-wrap: wrap;
}
.jd-header-right { display: flex; align-items: center; gap: .75rem; }
.jd-title-row {
  display: flex; align-items: center; gap: 10px;
}
.jd-title-row svg { width: 22px; height: 22px; stroke: var(--lm, var(--lm)); }
.jd-title { font-size: 1.35rem; font-weight: 700; color: var(--gd, #0a2e22); margin: 0; }
.jd-subtitle { font-size: .8rem; color: #6b7a6b; margin: .2rem 0 0; }

/* ─── BUTTONS ───────────────────────────────────────────────────────────────*/
.btn-primary {
  display: flex; align-items: center; gap: 6px;
  background: var(--lm, var(--lm)); color: #fff;
  border: none; border-radius: 9px; padding: 8px 16px;
  font-size: 13px; font-weight: 600; cursor: pointer;
  font-family: 'Inter', sans-serif; transition: opacity .15s, transform .15s;
}
.btn-primary:hover { opacity: .88; transform: translateY(-1px); }
.btn-primary:disabled { opacity: .45; cursor: not-allowed; transform: none; }
.btn-primary svg { width: 14px; height: 14px; }
.btn-primary.sm { padding: 7px 14px; font-size: 12px; }
.btn-ghost {
  background: #f3f4f2; color: #444; border: 1px solid #dde; border-radius: 9px;
  padding: 8px 16px; font-size: 13px; font-weight: 500; cursor: pointer;
  font-family: 'Inter', sans-serif; transition: background .15s;
}
.btn-ghost:hover { background: #e8e9e7; }
.btn-danger {
  background: #ef4444; color: #fff; border: none; border-radius: 9px;
  padding: 8px 18px; font-size: 13px; font-weight: 600; cursor: pointer;
  font-family: 'Inter', sans-serif; transition: opacity .15s;
}
.btn-danger:hover { opacity: .85; }
.btn-danger:disabled { opacity: .45; cursor: not-allowed; }

/* ─── VIEW TOGGLE ───────────────────────────────────────────────────────────*/
.view-toggle { display: flex; border: 1px solid #dde; border-radius: 9px; overflow: hidden; }
.vt-btn {
  width: 36px; height: 34px; display: flex; align-items: center; justify-content: center;
  background: transparent; border: none; cursor: pointer; color: #aab;
  transition: background .15s, color .15s;
}
.vt-btn svg { width: 15px; height: 15px; }
.vt-btn:hover { background: #f3f4f2; color: #555; }
.vt-btn.active { background: var(--lm, var(--lm)); color: #fff; }

/* ─── STATES ────────────────────────────────────────────────────────────────*/
.jd-loading, .jd-error {
  display: flex; align-items: center; gap: 10px;
  padding: 2rem; justify-content: center;
  color: #777; font-size: 14px;
}
.jd-loading svg, .jd-error svg { width: 18px; height: 18px; }
.jd-error { color: #ef4444; }
.jd-empty {
  display: flex; flex-direction: column; align-items: center; gap: 1rem;
  padding: 4rem; color: #aab; text-align: center;
}
.jd-empty svg { width: 52px; height: 52px; stroke: #ccd; }
.jd-empty p { font-size: 14px; margin: 0; }

/* ─── GRID TABLE ────────────────────────────────────────────────────────────*/
.grid-wrap { overflow-x: auto; border-radius: 14px; border: 1px solid #e8ece8; }
.grid-table {
  width: 100%; border-collapse: collapse;
  font-size: 12.5px; min-width: 900px;
}
.grid-table thead tr {
  background: #f6f9f4;
  border-bottom: 1px solid #e8ece8;
}
.grid-table th {
  padding: 10px 12px; text-align: left;
  font-size: 11px; font-weight: 600; color: #6b7a6b;
  letter-spacing: .06em; text-transform: uppercase;
}
.col-dokter { width: 160px; min-width: 140px; }
.col-hari { min-width: 110px; }
.grid-table tbody tr { border-bottom: 1px solid #f0f2ef; }
.grid-table tbody tr:last-child { border-bottom: none; }
.grid-table tbody tr:hover { background: #fafcf9; }
.col-dokter.grid-table td { padding: 10px 12px; }
.grid-table td { padding: 6px 8px; vertical-align: top; }

.emp-name { font-weight: 600; color: #1a2e1a; padding: 8px 4px; font-size: 13px; }

.col-cell { padding: 6px !important; }

.cell-filled {
  background: #f6fdf0;
  border: 1px solid #d4edb8;
  border-radius: 8px;
  padding: 6px 8px;
  display: flex; flex-direction: column; gap: 2px;
}
.cell-jam { font-size: 11.5px; font-weight: 600; color: #2d5a1a; }
.cell-poli { font-size: 10.5px; color: #5a7a4a; }
.cell-room { font-size: 10.5px; color: #6b7a6b; }
.cell-prefix { font-weight: 700; color: var(--lm, var(--lm)); font-size: 10px; }
.cell-actions {
  display: flex; gap: 3px; margin-top: 4px; align-items: center; flex-wrap: wrap;
}
.cell-toggle {
  font-size: 9.5px; font-weight: 700; padding: 2px 6px;
  border-radius: 10px; border: none; cursor: pointer;
  font-family: 'Inter', sans-serif; transition: opacity .15s;
}
.cell-toggle.aktif { background: rgba(56,189,248,.2); color: #2d7a1a; }
.cell-toggle.nonaktif { background: #f3f4f2; color: #9a9b9a; }
.cell-toggle:disabled { opacity: .5; cursor: not-allowed; }
.cell-edit, .cell-del {
  width: 22px; height: 22px; border-radius: 5px;
  border: 1px solid transparent; background: transparent;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; transition: all .15s;
}
.cell-edit svg, .cell-del svg { width: 11px; height: 11px; }
.cell-edit { color: #7a8a7a; }
.cell-edit:hover { background: #e8ece8; color: #1a2e1a; border-color: #dde; }
.cell-del { color: #c0a0a0; }
.cell-del:hover { background: #fef2f2; color: #ef4444; border-color: #fca5a5; }
.cell-add {
  width: 100%; min-height: 52px; border-radius: 8px;
  border: 1.5px dashed #dde; background: transparent;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; color: #ccd; transition: all .15s;
}
.cell-add svg { width: 16px; height: 16px; }
.cell-add:hover { border-color: var(--lm, var(--lm)); color: var(--lm, var(--lm)); background: #f6fdf0; }

/* ─── LIST TABLE ────────────────────────────────────────────────────────────*/
.list-wrap { overflow-x: auto; border-radius: 14px; border: 1px solid #e8ece8; }
.list-table {
  width: 100%; border-collapse: collapse; font-size: 13px; min-width: 700px;
}
.list-table thead tr { background: #f6f9f4; border-bottom: 1px solid #e8ece8; }
.list-table th {
  padding: 10px 14px; text-align: left;
  font-size: 11px; font-weight: 600; color: #6b7a6b;
  letter-spacing: .06em; text-transform: uppercase; white-space: nowrap;
}
.list-table tbody tr { border-bottom: 1px solid #f0f2ef; }
.list-table tbody tr:last-child { border-bottom: none; }
.list-table tbody tr:hover { background: #fafcf9; }
.list-table td { padding: 10px 14px; }
.list-empty { text-align: center; color: #aab; padding: 2rem !important; }
.td-dokter { font-weight: 600; color: #1a2e1a; }
.td-jam { font-variant-numeric: tabular-nums; color: #3a5a3a; font-weight: 500; }
.hari-chip {
  display: inline-block; padding: 2px 8px;
  background: #eef5e8; color: #3a6a1a; border-radius: 6px;
  font-size: 11.5px; font-weight: 600;
}
.prefix-chip {
  display: inline-block; padding: 2px 8px;
  background: rgba(56,189,248,.15); color: #2d6a10; border-radius: 6px;
  font-size: 12px; font-weight: 700;
}
.status-toggle {
  font-size: 11px; font-weight: 600; padding: 3px 10px;
  border-radius: 10px; border: none; cursor: pointer;
  font-family: 'Inter', sans-serif; transition: opacity .15s;
}
.status-toggle.aktif { background: rgba(56,189,248,.2); color: #2d7a1a; }
.status-toggle.nonaktif { background: #f3f4f2; color: #9a9b9a; }
.status-toggle:disabled { opacity: .5; cursor: not-allowed; }
.td-actions { display: flex; gap: 4px; }
.icon-btn {
  width: 30px; height: 30px; border-radius: 7px;
  border: 1px solid #e8ece8; background: transparent;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; color: #7a8a7a; transition: all .15s;
}
.icon-btn svg { width: 13px; height: 13px; }
.icon-btn:hover { background: #f3f4f2; color: #1a2e1a; }
.icon-btn.danger:hover { background: #fef2f2; color: #ef4444; border-color: #fca5a5; }

/* ─── MODAL ─────────────────────────────────────────────────────────────────*/
.modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,.45);
  display: flex; align-items: center; justify-content: center;
  z-index: 500; backdrop-filter: blur(3px);
}
.modal-box {
  background: #fff; border-radius: 18px;
  width: min(520px, 94vw); max-height: 90vh;
  display: flex; flex-direction: column;
  box-shadow: 0 20px 60px rgba(0,0,0,.15);
  overflow: hidden;
}
.modal-box.sm { width: min(360px, 94vw); }
.modal-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1.1rem 1.5rem; border-bottom: 1px solid #f0f2ef;
  font-size: 15px; font-weight: 600; color: #1a2e1a;
}
.modal-close {
  width: 32px; height: 32px; border-radius: 8px;
  border: 1px solid #e8ece8; background: transparent;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; color: #9a9b9a; transition: all .15s;
}
.modal-close svg { width: 14px; height: 14px; }
.modal-close:hover { background: #fef2f2; color: #ef4444; border-color: #fca5a5; }
.modal-body { padding: 1.25rem 1.5rem; overflow-y: auto; display: flex; flex-direction: column; gap: 1rem; }
.modal-foot {
  padding: 1rem 1.5rem; border-top: 1px solid #f0f2ef;
  display: flex; justify-content: flex-end; gap: .75rem;
}

/* ─── FORM FIELDS ───────────────────────────────────────────────────────────*/
.field { display: flex; flex-direction: column; gap: 5px; }
.field label { font-size: 12px; font-weight: 600; color: #5a6a5a; }
.req { color: #ef4444; }
.field-note { font-size: 11px; color: #7a8a7a; margin: 2px 0 0; }
.field-note strong { color: var(--lm, var(--lm)); }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
.inp {
  border: 1.5px solid #e0e4df; border-radius: 9px;
  padding: 8px 12px; font-size: 13px; color: #1a2e1a;
  font-family: 'Inter', sans-serif; outline: none;
  transition: border-color .2s, box-shadow .2s;
  background: #fff;
}
.inp:focus { border-color: var(--lm, var(--lm)); box-shadow: 0 0 0 3px rgba(56,189,248,.12); }
.inp:disabled { background: #f6f9f4; color: #9a9b9a; cursor: not-allowed; }
select.inp { cursor: pointer; }

/* ─── HARI PILLS ────────────────────────────────────────────────────────────*/
.hari-pills { display: flex; flex-wrap: wrap; gap: 6px; }
.hari-pill {
  padding: 5px 12px; border-radius: 8px;
  border: 1.5px solid #e0e4df; background: transparent;
  font-size: 12px; font-weight: 500; color: #6b7a6b;
  cursor: pointer; font-family: 'Inter', sans-serif;
  transition: all .15s;
}
.hari-pill:hover { border-color: var(--lm, var(--lm)); color: #2d5a1a; }
.hari-pill.active { background: var(--lm, var(--lm)); border-color: var(--lm, var(--lm)); color: #fff; font-weight: 700; }

/* ─── TOGGLE SWITCH ─────────────────────────────────────────────────────────*/
.toggle-label-wrap { display: flex; align-items: center; gap: 10px; cursor: pointer; }
.toggle-switch {
  width: 40px; height: 22px; border-radius: 11px;
  background: #dde; border: none; position: relative;
  cursor: pointer; transition: background .2s; flex-shrink: 0;
}
.toggle-switch.on { background: var(--lm, var(--lm)); }
.toggle-knob {
  position: absolute; width: 16px; height: 16px; border-radius: 50%;
  background: #fff; top: 3px; left: 3px; transition: left .2s;
  pointer-events: none;
}
.toggle-switch.on .toggle-knob { left: 21px; }
.toggle-txt { font-size: 13px; color: #5a6a5a; }

/* ─── CONFIRM ───────────────────────────────────────────────────────────────*/
.confirm-icon {
  width: 52px; height: 52px; border-radius: 50%;
  background: #fef2f2; display: flex; align-items: center; justify-content: center;
  margin: 1.5rem auto .75rem;
}
.confirm-icon svg { width: 22px; height: 22px; }
.confirm-title { text-align: center; font-size: 16px; font-weight: 700; color: #1a2e1a; margin: 0 0 .4rem; }
.confirm-sub { text-align: center; font-size: 13px; color: #6b7a6b; margin: 0 1.5rem 1.5rem; }
.confirm-actions { display: flex; justify-content: center; gap: .75rem; padding: 0 1.5rem 1.5rem; }

/* ─── TOAST ─────────────────────────────────────────────────────────────────*/
.toast {
  position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 9999;
  display: flex; align-items: center; gap: 8px;
  padding: 10px 18px; border-radius: 12px;
  font-size: 13.5px; font-weight: 500;
  box-shadow: 0 8px 24px rgba(0,0,0,.12);
  font-family: 'Inter', sans-serif;
}
.toast svg { width: 15px; height: 15px; flex-shrink: 0; }
.toast.s { background: #1a2e1a; color: #b8e68a; }
.toast.s svg { stroke: var(--lm); }
.toast.e { background: #2a1414; color: #fca5a5; }
.toast.e svg { stroke: #ef4444; }
.toast-fade-enter-active, .toast-fade-leave-active { transition: all .25s ease; }
.toast-fade-enter-from, .toast-fade-leave-to { opacity: 0; transform: translateY(8px); }

/* ─── SPIN ──────────────────────────────────────────────────────────────────*/
.spin { animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ─── TOOLBAR (tab layanan + minggu + CSV) ──────────────────────────────────*/
.jd-toolbar {
  display: flex; align-items: center; gap: .75rem;
  margin-bottom: 1.25rem; flex-wrap: wrap;
}
.toolbar-spacer { flex: 1 1 auto; }

/* Tab jenis layanan */
.svc-tabs { display: flex; gap: 4px; background: #f1f3f0; padding: 4px; border-radius: 11px; }
.svc-tab {
  padding: 7px 18px; border: none; background: transparent; cursor: pointer;
  font-size: 13px; font-weight: 600; color: #6b7a6b; border-radius: 8px;
  font-family: 'Inter', sans-serif; transition: all .15s;
}
.svc-tab:hover { color: #1a2e1a; }
.svc-tab.active.bpjs { background: var(--lm); color: #fff; box-shadow: 0 2px 6px rgba(56,189,248,.35); }
.svc-tab.active.eksekutif { background: #1763d4; color: #fff; box-shadow: 0 2px 6px rgba(23,99,212,.3); }

/* Selector minggu */
.week-picker {
  display: flex; align-items: center; gap: 8px;
  border: 1.5px solid #e0e4df; border-radius: 10px; padding: 0 12px; height: 38px; background: #fff;
}
.week-picker svg { width: 15px; height: 15px; stroke: var(--lm); flex-shrink: 0; }
.week-select {
  border: none; outline: none; background: transparent; cursor: pointer;
  font-size: 13px; font-weight: 600; color: #1a2e1a; font-family: 'Inter', sans-serif;
  padding-right: 4px;
}

/* Tombol soft (CSV & salin) */
.btn-soft {
  display: flex; align-items: center; gap: 6px;
  background: #fff; color: #2d4a2d; border: 1.5px solid #dde4da; border-radius: 9px;
  padding: 8px 14px; font-size: 12.5px; font-weight: 600; cursor: pointer;
  font-family: 'Inter', sans-serif; transition: all .15s;
}
.btn-soft svg { width: 14px; height: 14px; }
.btn-soft:hover { border-color: var(--lm); background: #f6fdf0; color: #1a2e1a; }
.btn-soft:disabled { opacity: .5; cursor: not-allowed; }
.btn-soft.accent { background: #1763d4; color: #fff; border-color: #1763d4; }
.btn-soft.accent:hover { background: #1255bb; border-color: #1255bb; color: #fff; }
.hidden-file { display: none; }

/* Dropdown pilih format (CSV / Excel) */
.fmt-wrap { position: relative; }
.fmt-wrap .caret { width: 12px; height: 12px; margin-left: -1px; }
.fmt-menu {
  position: absolute; top: calc(100% + 4px); right: 0; z-index: 50;
  background: #fff; border: 1px solid #dde4da; border-radius: 9px;
  box-shadow: 0 8px 24px rgba(0,0,0,.1); overflow: hidden; min-width: 130px;
}
.fmt-menu button {
  display: block; width: 100%; text-align: left; padding: 8px 14px;
  border: none; background: transparent; cursor: pointer;
  font-size: 12.5px; font-weight: 500; color: #2d4a2d; font-family: 'Inter', sans-serif;
}
.fmt-menu button:hover { background: #f6fdf0; color: #1a2e1a; }

/* Chip jenis di tabel list */
.svc-chip {
  display: inline-block; padding: 2px 9px; border-radius: 6px;
  font-size: 11px; font-weight: 700;
}
.svc-chip.bpjs { background: rgba(56,189,248,.18); color: #2d6a10; }
.svc-chip.eksekutif { background: rgba(23,99,212,.14); color: #1255bb; }

.jd-empty-hint { font-size: 12.5px; color: #9aa; }

/* ─── MODAL: segmented layanan + konteks minggu + 3-kolom ───────────────────*/
.modal-week-note {
  display: flex; align-items: center; gap: 8px;
  background: #f6f9f4; border: 1px solid #e3ebdd; border-radius: 9px;
  padding: 8px 12px; font-size: 12.5px; color: #3a5a3a;
}
.modal-week-note svg { width: 15px; height: 15px; stroke: var(--lm); flex-shrink: 0; }
.modal-week-note strong { color: #1a2e1a; }

.svc-seg { display: flex; gap: 6px; }
.svc-seg-btn {
  flex: 1; padding: 9px 0; border: 1.5px solid #e0e4df; background: #fff; cursor: pointer;
  font-size: 13px; font-weight: 600; color: #6b7a6b; border-radius: 9px;
  font-family: 'Inter', sans-serif; transition: all .15s;
}
.svc-seg-btn:hover { border-color: #cdd6c7; }
.svc-seg-btn.active.bpjs { background: var(--lm); border-color: var(--lm); color: #fff; }
.svc-seg-btn.active.eksekutif { background: #1763d4; border-color: #1763d4; color: #fff; }

.field-row-3 { display: grid; grid-template-columns: .7fr 1.6fr .7fr; gap: .75rem; }
.prefix-note { margin-top: -2px; }
.prefix-note strong { color: #1763d4; font-weight: 700; }
.prefix-hint { display: block; color: #9aa; margin-top: 2px; }

/* ─── MODAL HASIL IMPORT ────────────────────────────────────────────────────*/
.import-summary { display: flex; gap: .75rem; }
.imp-stat {
  flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px;
  padding: 14px 8px; border-radius: 12px; border: 1px solid #eee;
}
.imp-stat .imp-num { font-size: 24px; font-weight: 800; line-height: 1; }
.imp-stat .imp-lbl { font-size: 11px; font-weight: 600; color: #777; }
.imp-stat.ok { background: #f6fdf0; border-color: #d4edb8; }
.imp-stat.ok .imp-num { color: #2d7a1a; }
.imp-stat.skip { background: #f7f8f6; border-color: #e6e8e4; }
.imp-stat.skip .imp-num { color: #8a9a8a; }
.imp-stat.err { background: #fef4f4; border-color: #fcd5d5; }
.imp-stat.err .imp-num { color: #ef4444; }
.import-target { font-size: 12.5px; color: #5a6a5a; margin: .25rem 0 0; }
.import-target strong { color: #1a2e1a; }
.import-errors {
  margin-top: .5rem; background: #fffafa; border: 1px solid #fce0e0; border-radius: 10px;
  padding: 10px 12px; max-height: 200px; overflow-y: auto;
}
.import-errors-title { font-size: 12px; font-weight: 700; color: #c0392b; margin: 0 0 6px; }
.import-errors ul { margin: 0; padding-left: 18px; }
.import-errors li { font-size: 12px; color: #7a5a5a; margin-bottom: 3px; }
</style>
