<script setup>
import { ref, computed, onMounted } from 'vue'
import { useJadwalDokterStore } from '@/stores/jadwalDokterStore'
import { masterApi } from '@/services/api'

const store = useJadwalDokterStore()

const HARI = [
  { val: 1, label: 'Senin' },
  { val: 2, label: 'Selasa' },
  { val: 3, label: 'Rabu' },
  { val: 4, label: 'Kamis' },
  { val: 5, label: 'Jumat' },
  { val: 6, label: 'Sabtu' },
  { val: 7, label: 'Minggu' },
]

// ─── Daftar dokter (employees role=Dokter) dari master/pegawai ───────────────
const employees = ref([])
async function fetchEmployees() {
  try {
    const res = await masterApi.penjamin?.() // fallback — gunakan endpoint master/pegawai
    // Coba endpoint khusus dokter
  } catch {}
  // Untuk sekarang, daftar dokter diambil dari store.daftarDokter (sudah include employee_id + nama)
}

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
    is_active:    jadwal.is_active,
  }
  modalMode.value = 'edit'
  showModal.value  = true
}

async function saveForm() {
  if (!form.value.employee_id) { toast('e', 'Pilih dokter terlebih dahulu'); return }
  if (!form.value.start_time || !form.value.end_time) { toast('e', 'Jam praktik wajib diisi'); return }

  saving.value = true
  try {
    const payload = {
      employee_id: form.value.employee_id,
      day_of_week: Number(form.value.day_of_week),
      start_time:  form.value.start_time,
      end_time:    form.value.end_time,
      room:        form.value.room || null,
      poliklinik:  form.value.poliklinik || null,
      is_active:   form.value.is_active,
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

onMounted(() => store.fetchAll())
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
      <p>Belum ada jadwal dokter</p>
      <button class="btn-primary sm" @click="openAdd()">Tambah Jadwal Pertama</button>
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
              <td colspan="8" class="list-empty">Belum ada jadwal</td>
            </tr>
            <tr v-for="j in allJadwal" :key="j.id">
              <td class="td-dokter">{{ j.nama_dokter }}</td>
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

            <!-- Poliklinik & Ruangan -->
            <div class="field-row">
              <div class="field">
                <label>Poliklinik</label>
                <input v-model="form.poliklinik" type="text" class="inp" placeholder="cth: Mata" maxlength="100" />
              </div>
              <div class="field">
                <label>Ruangan</label>
                <input v-model="form.room" type="text" class="inp" placeholder="cth: 1" maxlength="50" />
                <p v-if="form.room" class="field-note">
                  Prefix antrian: <strong>D{{ form.room }}</strong> → D{{ form.room }}-001, D{{ form.room }}-002, ...
                </p>
              </div>
            </div>

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

  </div>
</template>

<style scoped>
/* ─── LAYOUT ──────────────────────────────────────────────────────────────── */
.jd-page {
  padding: 1.75rem 2rem;
  max-width: 1400px;
  margin: 0 auto;
  font-family: 'DM Sans', sans-serif;
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
.jd-title-row svg { width: 22px; height: 22px; stroke: var(--lm, #8abf44); }
.jd-title { font-size: 1.35rem; font-weight: 700; color: var(--gd, #0a2e22); margin: 0; }
.jd-subtitle { font-size: .8rem; color: #6b7a6b; margin: .2rem 0 0; }

/* ─── BUTTONS ───────────────────────────────────────────────────────────────*/
.btn-primary {
  display: flex; align-items: center; gap: 6px;
  background: var(--lm, #8abf44); color: #fff;
  border: none; border-radius: 9px; padding: 8px 16px;
  font-size: 13px; font-weight: 600; cursor: pointer;
  font-family: 'DM Sans', sans-serif; transition: opacity .15s, transform .15s;
}
.btn-primary:hover { opacity: .88; transform: translateY(-1px); }
.btn-primary:disabled { opacity: .45; cursor: not-allowed; transform: none; }
.btn-primary svg { width: 14px; height: 14px; }
.btn-primary.sm { padding: 7px 14px; font-size: 12px; }
.btn-ghost {
  background: #f3f4f2; color: #444; border: 1px solid #dde; border-radius: 9px;
  padding: 8px 16px; font-size: 13px; font-weight: 500; cursor: pointer;
  font-family: 'DM Sans', sans-serif; transition: background .15s;
}
.btn-ghost:hover { background: #e8e9e7; }
.btn-danger {
  background: #ef4444; color: #fff; border: none; border-radius: 9px;
  padding: 8px 18px; font-size: 13px; font-weight: 600; cursor: pointer;
  font-family: 'DM Sans', sans-serif; transition: opacity .15s;
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
.vt-btn.active { background: var(--lm, #8abf44); color: #fff; }

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
.cell-prefix { font-weight: 700; color: var(--lm, #8abf44); font-size: 10px; }
.cell-actions {
  display: flex; gap: 3px; margin-top: 4px; align-items: center; flex-wrap: wrap;
}
.cell-toggle {
  font-size: 9.5px; font-weight: 700; padding: 2px 6px;
  border-radius: 10px; border: none; cursor: pointer;
  font-family: 'DM Sans', sans-serif; transition: opacity .15s;
}
.cell-toggle.aktif { background: rgba(138,191,68,.2); color: #2d7a1a; }
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
.cell-add:hover { border-color: var(--lm, #8abf44); color: var(--lm, #8abf44); background: #f6fdf0; }

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
  background: rgba(138,191,68,.15); color: #2d6a10; border-radius: 6px;
  font-size: 12px; font-weight: 700;
}
.status-toggle {
  font-size: 11px; font-weight: 600; padding: 3px 10px;
  border-radius: 10px; border: none; cursor: pointer;
  font-family: 'DM Sans', sans-serif; transition: opacity .15s;
}
.status-toggle.aktif { background: rgba(138,191,68,.2); color: #2d7a1a; }
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
.field-note strong { color: var(--lm, #8abf44); }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
.inp {
  border: 1.5px solid #e0e4df; border-radius: 9px;
  padding: 8px 12px; font-size: 13px; color: #1a2e1a;
  font-family: 'DM Sans', sans-serif; outline: none;
  transition: border-color .2s, box-shadow .2s;
  background: #fff;
}
.inp:focus { border-color: var(--lm, #8abf44); box-shadow: 0 0 0 3px rgba(138,191,68,.12); }
.inp:disabled { background: #f6f9f4; color: #9a9b9a; cursor: not-allowed; }
select.inp { cursor: pointer; }

/* ─── HARI PILLS ────────────────────────────────────────────────────────────*/
.hari-pills { display: flex; flex-wrap: wrap; gap: 6px; }
.hari-pill {
  padding: 5px 12px; border-radius: 8px;
  border: 1.5px solid #e0e4df; background: transparent;
  font-size: 12px; font-weight: 500; color: #6b7a6b;
  cursor: pointer; font-family: 'DM Sans', sans-serif;
  transition: all .15s;
}
.hari-pill:hover { border-color: var(--lm, #8abf44); color: #2d5a1a; }
.hari-pill.active { background: var(--lm, #8abf44); border-color: var(--lm, #8abf44); color: #fff; font-weight: 700; }

/* ─── TOGGLE SWITCH ─────────────────────────────────────────────────────────*/
.toggle-label-wrap { display: flex; align-items: center; gap: 10px; cursor: pointer; }
.toggle-switch {
  width: 40px; height: 22px; border-radius: 11px;
  background: #dde; border: none; position: relative;
  cursor: pointer; transition: background .2s; flex-shrink: 0;
}
.toggle-switch.on { background: var(--lm, #8abf44); }
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
  font-family: 'DM Sans', sans-serif;
}
.toast svg { width: 15px; height: 15px; flex-shrink: 0; }
.toast.s { background: #1a2e1a; color: #b8e68a; }
.toast.s svg { stroke: #8abf44; }
.toast.e { background: #2a1414; color: #fca5a5; }
.toast.e svg { stroke: #ef4444; }
.toast-fade-enter-active, .toast-fade-leave-active { transition: all .25s ease; }
.toast-fade-enter-from, .toast-fade-leave-to { opacity: 0; transform: translateY(8px); }

/* ─── SPIN ──────────────────────────────────────────────────────────────────*/
.spin { animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
