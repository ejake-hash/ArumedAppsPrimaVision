<script setup>
/**
 * Fasilitas & Ruang — menu master di bawah Form Rekam Medis.
 * Berisi: Ruang Operasi (OK), Ruangan & Tempat Tidur (Rawat Inap).
 * Tarif Kamar dipindah ke menu Tarif & Paket → Tarif Kamar (RoomTarifPanel).
 *
 * Ruang Operasi disimpan di clinic_profiles.operating_rooms (JSON) — view ini
 * load & patch field itu secara mandiri (tidak mengganggu form Profil Institusi).
 * Room/Bed = tabel terpisah, dikelola oleh komponen RoomBedManager.
 */
import { ref, onMounted } from 'vue'
import { masterApi } from '@/services/api'
import RoomBedManager from '@/components/master-data/RoomBedManager.vue'

const operatingRooms = ref([])
const newOk = ref('')
const loading = ref(false)
const savingOk = ref(false)
const toast = ref(null)

function notify(msg, ok = true) {
  toast.value = { msg, ok }
  setTimeout(() => (toast.value = null), 3000)
}

async function loadProfil() {
  loading.value = true
  try {
    const res = await masterApi.profilKlinik.show()
    operatingRooms.value = res.data?.data?.operating_rooms ?? []
  } catch {
    operatingRooms.value = []
  } finally {
    loading.value = false
  }
}
onMounted(loadProfil)

function addOk() {
  const name = newOk.value.trim()
  if (!name) return
  if (operatingRooms.value.length >= 20) { notify('Maksimum 20 ruang OK', false); return }
  if (operatingRooms.value.some((r) => r.toLowerCase() === name.toLowerCase())) {
    notify(`"${name}" sudah ada`, false); return
  }
  operatingRooms.value.push(name)
  newOk.value = ''
}

function removeOk(i) {
  operatingRooms.value.splice(i, 1)
}

async function saveOk() {
  savingOk.value = true
  try {
    await masterApi.profilKlinik.update({ operating_rooms: operatingRooms.value })
    notify('Ruang operasi disimpan')
  } catch (e) {
    notify(e.response?.data?.message ?? 'Gagal menyimpan ruang operasi', false)
  } finally {
    savingOk.value = false
  }
}
</script>

<template>
  <div class="rf-wrap">
    <div class="rf-head">
      <h2>Fasilitas &amp; Ruang</h2>
      <p>Kelola ruang operasi, ruangan rawat inap beserta tempat tidur, dan tarif kamar.</p>
    </div>

    <!-- ─── Ruang Operasi (OK) ─── -->
    <section class="pk-section">
      <header>
        <h3>Ruang Operasi (OK)</h3>
        <p class="pk-sub">Daftar ruang OK yang tersedia di institusi. Dipakai di modul Bedah saat menentukan ruang operasi pasien.</p>
      </header>

      <p v-if="loading" class="rf-muted">Memuat…</p>

      <div class="rf-chips">
        <div v-for="(room, i) in operatingRooms" :key="i" class="rf-chip">
          <span>{{ room }}</span>
          <button type="button" class="rf-chip-del" @click="removeOk(i)" aria-label="Hapus ruang">×</button>
        </div>
        <div v-if="!operatingRooms.length && !loading" class="rf-empty">Belum ada ruang OK. Tambahkan minimal 1 ruang.</div>
      </div>

      <div class="rf-add">
        <label class="rf-field">
          <span class="rf-flabel">Nama Ruangan</span>
          <input v-model="newOk" type="text" placeholder="mis. OK 1, OK Phaco, OK Laser"
                 maxlength="50" @keydown.enter.prevent="addOk" />
        </label>
        <button type="button" class="pk-btn-secondary rf-btn" @click="addOk" :disabled="!newOk.trim()">+ Tambah</button>
        <button type="button" class="pk-btn-primary rf-btn" @click="saveOk" :disabled="savingOk">
          {{ savingOk ? 'Menyimpan…' : 'Simpan Ruang OK' }}
        </button>
      </div>
    </section>

    <!-- ─── Ruangan & Tempat Tidur (komponen mandiri) ─── -->
    <RoomBedManager />

    <Teleport to="body">
      <div v-if="toast" class="rf-toast" :class="{ err: !toast.ok }">{{ toast.msg }}</div>
    </Teleport>
  </div>
</template>

<style scoped>
.rf-wrap { display: flex; flex-direction: column; gap: 1.2rem; }
.rf-head h2 { font-family: 'Space Grotesk', serif; font-size: 20px; color: var(--td, #111); margin: 0; }
.rf-head p { font-size: 13px; color: var(--tm, #6b7280); margin: 4px 0 0; }
.pk-section { background: var(--bs, #fff); border: 1px solid var(--gb, #e5e7eb); border-radius: 12px; padding: 1.1rem 1.2rem; display: flex; flex-direction: column; gap: 0.9rem; }
.pk-section header h3 { margin: 0; font-size: 15px; color: var(--td, #111); }
.pk-sub { font-size: 12px; color: var(--tm, #6b7280); margin: 4px 0 0; }
.rf-muted { color: var(--tu, #9ca3af); font-size: 13px; }
.rf-chips { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.rf-chip { display: inline-flex; align-items: center; gap: 0.4rem; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 999px; padding: 0.3rem 0.8rem; font-size: 13px; color: #1e3a8a; }
.rf-chip-del { background: none; border: none; color: #ef4444; cursor: pointer; font-size: 15px; line-height: 1; }
.rf-empty { font-size: 13px; color: var(--tu, #9ca3af); font-style: italic; }
.rf-add { display: flex; gap: 0.7rem; align-items: flex-end; flex-wrap: wrap; }
.rf-field { display: flex; flex-direction: column; gap: 0.2rem; }
.rf-flabel { font-size: 11px; font-weight: 600; color: var(--tm, #6b7280); }
.rf-field input { padding: 0.45rem 0.6rem; border: 1px solid var(--gb, #d1d5db); border-radius: 8px; font-size: 13px; min-width: 280px; }
.pk-btn-primary { background: #1763d4; color: #fff !important; border: 1px solid #1763d4; border-radius: 8px; padding: 0.5rem 1rem; cursor: pointer; font-size: 13px; }
.pk-btn-secondary { background: #fff; color: #000; border: 1px solid var(--gb, #d1d5db); border-radius: 8px; padding: 0.5rem 1rem; cursor: pointer; font-size: 13px; }
.pk-btn-primary:disabled, .pk-btn-secondary:disabled { opacity: 0.6; cursor: not-allowed; }
.rf-toast { position: fixed; bottom: 1.5rem; right: 1.5rem; background: #16a34a; color: #fff; padding: 0.7rem 1.2rem; border-radius: 8px; z-index: 9300; font-size: 14px; }
.rf-toast.err { background: #ef4444; }
</style>
