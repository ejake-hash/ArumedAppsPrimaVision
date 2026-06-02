<script setup>
/**
 * Master Room & Bed (Rawat Inap) — disisipkan sebagai section di Profil Klinik.
 * Self-contained: CRUD instan ke tabel rooms/beds via roomApi (BUKAN bagian
 * dari form profil yang di-save sekaligus). Struktur 2 level: Room → Bed.
 * Kelas melekat di Room; admin atur jumlah bed per room.
 */
import { ref, onMounted } from 'vue'
import { roomApi } from '@/services/api'

const rooms = ref([])
const loading = ref(false)
const err = ref(null)
const toast = ref(null)

// Form tambah/edit room.
const editingId = ref(null)
const roomForm = ref({ code: '', name: '', kelas_rawat: '', type: 'KAMAR' })

function notify(msg, ok = true) {
  toast.value = { msg, ok }
  setTimeout(() => (toast.value = null), 3000)
}

async function load() {
  loading.value = true
  err.value = null
  try {
    const res = await roomApi.list()
    rooms.value = res.data?.data ?? []
  } catch (e) {
    err.value = e.response?.data?.message ?? 'Gagal memuat data room'
  } finally {
    loading.value = false
  }
}

onMounted(() => { load() })

function resetForm() {
  editingId.value = null
  roomForm.value = { code: '', name: '', kelas_rawat: '', type: 'KAMAR' }
}

function editRoom(r) {
  editingId.value = r.id
  roomForm.value = { code: r.code, name: r.name, kelas_rawat: r.kelas_rawat, type: r.type }
}

async function saveRoom() {
  if (!roomForm.value.code || !roomForm.value.name || !roomForm.value.kelas_rawat) {
    notify('Kode, nama, & kelas wajib diisi', false); return
  }
  try {
    if (editingId.value) {
      await roomApi.update(editingId.value, roomForm.value)
      notify('Room diperbarui')
    } else {
      await roomApi.store(roomForm.value)
      notify('Room dibuat')
    }
    resetForm()
    await load()
  } catch (e) {
    notify(e.response?.data?.message ?? 'Gagal menyimpan room', false)
  }
}

async function deleteRoom(r) {
  if (!confirm(`Hapus Room ${r.name}? Semua bed di dalamnya ikut terhapus.`)) return
  try {
    await roomApi.destroy(r.id)
    notify('Room dihapus')
    await load()
  } catch (e) {
    notify(e.response?.data?.message ?? 'Gagal menghapus room', false)
  }
}

// ── Bed ───────────────────────────────────────────────────────────────────────
const bedCodeInput = ref({}) // { [roomId]: 'A' }

async function addBed(room) {
  const code = (bedCodeInput.value[room.id] || '').trim()
  if (!code) { notify('Isi kode bed (mis. A, B, 1)', false); return }
  try {
    await roomApi.addBed(room.id, { code })
    bedCodeInput.value[room.id] = ''
    notify(`Bed ${room.code}.${code} ditambahkan`)
    await load()
  } catch (e) {
    notify(e.response?.data?.message ?? 'Gagal menambah bed', false)
  }
}

async function deleteBed(bed) {
  if (!confirm(`Hapus bed ${bed.label}?`)) return
  try {
    await roomApi.destroyBed(bed.id)
    notify('Bed dihapus')
    await load()
  } catch (e) {
    notify(e.response?.data?.message ?? 'Gagal menghapus bed', false)
  }
}

const bedStatusColor = {
  AVAILABLE: '#16a34a', OCCUPIED: '#1763d4', CLEANING: '#d97706',
  MAINTENANCE: '#6b7280', RESERVED: '#7c3aed',
}
</script>

<template>
  <section class="pk-section rb">
    <header>
      <h3>Ruangan &amp; Tempat Tidur (Rawat Inap)</h3>
      <p class="pk-sub">Kelola ruangan rawat inap beserta tempat tidur (bed) di dalamnya. Kelas perawatan melekat pada ruangan; satu ruangan bisa berisi banyak bed (mis. Ruang 305 → bed 305.A, 305.B).</p>
    </header>

    <p v-if="err" class="rb-err">{{ err }}</p>

    <!-- Form tambah/edit room -->
    <div class="rb-form">
      <label class="rb-field">
        <span class="rb-flabel">Kode Ruangan</span>
        <input v-model="roomForm.code" placeholder="mis. 305" maxlength="20" />
      </label>
      <label class="rb-field">
        <span class="rb-flabel">Nama Ruangan</span>
        <input v-model="roomForm.name" placeholder="mis. Ruang 305 / Mawar 1" maxlength="100" />
      </label>
      <label class="rb-field">
        <span class="rb-flabel">Kelas Perawatan</span>
        <input v-model="roomForm.kelas_rawat" placeholder="1 / 2 / 3 / VIP" maxlength="5" />
      </label>
      <label class="rb-field">
        <span class="rb-flabel">Jenis Ruangan</span>
        <select v-model="roomForm.type">
          <option value="KAMAR">Kamar Biasa</option>
          <option value="ICU">ICU</option>
          <option value="ISOLASI">Isolasi</option>
          <option value="HCU">HCU</option>
        </select>
      </label>
      <button type="button" class="pk-btn-primary rb-fbtn" @click="saveRoom">
        {{ editingId ? 'Simpan' : '+ Tambah Ruangan' }}
      </button>
      <button v-if="editingId" type="button" class="pk-btn-secondary rb-fbtn" @click="resetForm">Batal</button>
    </div>

    <p v-if="loading" class="rb-muted">Memuat…</p>

    <!-- Daftar room + bed -->
    <div v-for="room in rooms" :key="room.id" class="rb-room">
      <div class="rb-room-head">
        <div>
          <strong>{{ room.name }}</strong>
          <span class="rb-kelas">Kelas {{ room.kelas_rawat }} · {{ room.type }}</span>
        </div>
        <div class="rb-room-meta">
          <span>{{ room.occupied_count }}/{{ room.beds_count }} terisi</span>
          <button type="button" class="rb-link" @click="editRoom(room)">Edit</button>
          <button type="button" class="rb-link rb-del" @click="deleteRoom(room)">Hapus</button>
        </div>
      </div>

      <div class="rb-beds">
        <span v-for="bed in room.beds" :key="bed.id" class="rb-bed-chip"
              :style="{ borderColor: bedStatusColor[bed.status] }">
          <span class="rb-bed-label">{{ bed.label }}</span>
          <span class="rb-bed-st" :style="{ color: bedStatusColor[bed.status] }">{{ bed.status }}</span>
          <button type="button" class="rb-bed-del" @click="deleteBed(bed)"
                  :disabled="bed.status === 'OCCUPIED'" title="Hapus bed">×</button>
        </span>
        <span v-if="!room.beds?.length" class="rb-muted">Belum ada bed</span>
      </div>

      <div class="rb-bed-add">
        <label class="rb-field">
          <span class="rb-flabel">Nomor Bed</span>
          <input v-model="bedCodeInput[room.id]" placeholder="mis. A, B, atau 1, 2"
                 maxlength="20" @keydown.enter.prevent="addBed(room)" />
        </label>
        <button type="button" class="pk-btn-secondary rb-fbtn" @click="addBed(room)">+ Tambah Bed</button>
        <span class="rb-hint">Label otomatis: {{ room.code }}.{{ (bedCodeInput[room.id] || 'A') }}</span>
      </div>
    </div>

    <p v-if="!loading && !rooms.length" class="rb-muted">Belum ada room. Tambah room pertama di atas.</p>

    <p class="rb-muted rb-tarif-hint">
      Tarif kamar per malam kini dikelola di menu <strong>Tarif &amp; Paket → Tarif Kamar</strong>.
    </p>

    <Teleport to="body">
      <div v-if="toast" class="rb-toast" :class="{ err: !toast.ok }">{{ toast.msg }}</div>
    </Teleport>
  </section>
</template>

<style scoped>
.rb { gap: 0.9rem; }
.rb-err { color: #ef4444; font-size: 13px; }
.rb-muted { color: var(--tu, #9ca3af); font-size: 13px; }
.rb-form { display: flex; flex-wrap: wrap; gap: 0.7rem; align-items: flex-end; }
.rb-field { display: flex; flex-direction: column; gap: 0.2rem; }
.rb-flabel { font-size: 11px; font-weight: 600; color: var(--tm, #6b7280); }
.rb-field input, .rb-field select { padding: 0.45rem 0.6rem; border: 1px solid var(--gb, #d1d5db); border-radius: 8px; font-size: 13px; }
.rb-field input { width: 150px; }
.rb-fbtn { align-self: flex-end; }
.rb-hint { font-size: 11px; color: #1763d4; align-self: center; }
.rb-room { border: 1px solid var(--gb, #e5e7eb); border-radius: 10px; padding: 0.7rem 0.9rem; }
.rb-room-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
.rb-kelas { color: var(--tm, #6b7280); font-size: 12px; margin-left: 0.5rem; }
.rb-room-meta { display: flex; gap: 0.6rem; align-items: center; font-size: 12px; color: #1763d4; }
.rb-link { background: none; border: none; color: #1763d4; cursor: pointer; font-size: 12px; padding: 0; }
.rb-link.rb-del { color: #ef4444; }
.rb-beds { display: flex; flex-wrap: wrap; gap: 0.4rem; margin: 0.6rem 0; }
.rb-bed-chip { display: inline-flex; align-items: center; gap: 0.3rem; border: 2px solid; border-radius: 6px; padding: 0.25rem 0.5rem; font-size: 12px; }
.rb-bed-label { font-weight: 700; color: #000; }
.rb-bed-st { font-size: 10px; font-weight: 600; }
.rb-bed-del { background: none; border: none; color: #ef4444; cursor: pointer; font-size: 14px; line-height: 1; }
.rb-bed-del:disabled { color: #d1d5db; cursor: not-allowed; }
.rb-bed-add { display: flex; gap: 0.4rem; }
.rb-bed-add input { padding: 0.35rem 0.6rem; border: 1px solid var(--gb, #d1d5db); border-radius: 8px; font-size: 13px; width: 160px; }
.rb-tarif-hint { border-top: 1px dashed var(--gb, #e5e7eb); padding-top: 0.9rem; margin-top: 0.6rem; }
.rb-toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: #16a34a; color: #fff; padding: 0.7rem 1.2rem; border-radius: 8px; z-index: 9300; font-size: 14px; }
.rb-toast.err { background: #ef4444; }
</style>
