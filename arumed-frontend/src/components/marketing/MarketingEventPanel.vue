<script setup>
/**
 * Program Marketing & Event (#5) — CRUD kegiatan + daftar peserta yang ditarik dari
 * Google Sheet (anyone-with-link). Sync peserta harian via cron; tombol sync manual
 * (marketing.write). Pilih event untuk lihat pesertanya.
 */
import { ref, computed, onMounted } from 'vue'
import { marketingApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'

const auth = useAuthStore()
const canWrite = computed(() => auth.can('marketing.write'))

const events = ref([])
const loading = ref(false)
const selected = ref(null)
const participants = ref([])
const partLoading = ref(false)
const syncing = ref(false)

const msg = ref('')
const msgType = ref('s')
let msgTimer = null
function toast(type, m) { msgType.value = type; msg.value = m; clearTimeout(msgTimer); msgTimer = setTimeout(() => (msg.value = ''), 3500) }

async function load() {
  loading.value = true
  try {
    const res = await marketingApi.events.list({ per_page: 200 })
    const data = res.data?.data
    events.value = data?.data ?? (Array.isArray(data) ? data : [])
  } catch {
    events.value = []
  } finally {
    loading.value = false
  }
}

async function selectEvent(ev) {
  selected.value = ev
  partLoading.value = true
  participants.value = []
  try {
    const res = await marketingApi.events.participants(ev.id)
    const data = res.data?.data
    participants.value = data?.data ?? (Array.isArray(data) ? data : [])
  } catch {
    participants.value = []
  } finally {
    partLoading.value = false
  }
}

async function syncParticipants(ev) {
  if (syncing.value) return
  syncing.value = true
  try {
    const res = await marketingApi.events.sync(ev.id)
    const r = res.data?.data || {}
    toast(r.ok ? 's' : 'e', r.ok ? `Sinkron: ${r.fetched} baris, ${r.inserted} baru.` : (r.message || 'Sheet belum dapat diakses.'))
    if (selected.value?.id === ev.id) await selectEvent(ev)
    await load()
  } catch (e) {
    toast('e', e?.response?.data?.message || 'Gagal sinkron.')
  } finally {
    syncing.value = false
  }
}

// ─── Modal CRUD ───
const modal = ref({ open: false, editingId: null, submitting: false })
const blank = () => ({ name: '', event_date: '', location: '', description: '', participant_sheet_url: '', participant_gid: '', is_active: true })
const form = ref(blank())

function openCreate() { modal.value = { open: true, editingId: null, submitting: false }; form.value = blank() }
function openEdit(ev) {
  modal.value = { open: true, editingId: ev.id, submitting: false }
  form.value = {
    name: ev.name || '', event_date: ev.event_date || '', location: ev.location || '',
    description: ev.description || '', participant_sheet_url: ev.participant_sheet_url || '',
    participant_gid: ev.participant_gid || '', is_active: ev.is_active !== false,
  }
}
function closeModal() { modal.value.open = false }

async function submit() {
  if (!form.value.name.trim()) { toast('e', 'Nama kegiatan wajib diisi.'); return }
  modal.value.submitting = true
  try {
    if (modal.value.editingId) await marketingApi.events.update(modal.value.editingId, form.value)
    else await marketingApi.events.create(form.value)
    toast('s', 'Tersimpan.')
    closeModal()
    await load()
  } catch (e) {
    toast('e', e?.response?.data?.message || 'Gagal menyimpan.')
  } finally {
    modal.value.submitting = false
  }
}

async function remove(ev) {
  if (!confirm(`Hapus event "${ev.name}" beserta data pesertanya?`)) return
  try {
    await marketingApi.events.remove(ev.id)
    if (selected.value?.id === ev.id) { selected.value = null; participants.value = [] }
    toast('s', 'Dihapus.')
    await load()
  } catch (e) {
    toast('e', e?.response?.data?.message || 'Gagal menghapus.')
  }
}

const partKeys = computed(() => {
  const first = participants.value.find(p => p.payload)
  return first ? Object.keys(first.payload).slice(0, 6) : []
})

onMounted(load)
</script>

<template>
  <div>
    <div class="bar">
      <h3 class="title">Program Marketing & Event</h3>
      <span class="count">{{ events.length }} kegiatan</span>
      <span class="spacer"></span>
      <button v-if="canWrite" class="btn-soft accent" @click="openCreate">+ Tambah Event</button>
    </div>

    <div class="split">
      <!-- Daftar event -->
      <div class="table-wrap">
        <table class="po-table">
          <thead>
            <tr><th>Kegiatan</th><th>Tanggal</th><th class="num">Peserta</th><th v-if="canWrite" style="width:140px">Aksi</th></tr>
          </thead>
          <tbody>
            <tr v-if="loading"><td :colspan="canWrite ? 4 : 3" class="empty">Memuat…</td></tr>
            <tr v-else-if="!events.length"><td :colspan="canWrite ? 4 : 3" class="empty">Belum ada event.</td></tr>
            <tr v-for="ev in events" :key="ev.id" :class="{ sel: selected?.id === ev.id }" @click="selectEvent(ev)">
              <td class="strong">{{ ev.name }}<span v-if="ev.location" class="link-tag">{{ ev.location }}</span></td>
              <td>{{ ev.event_date || '-' }}</td>
              <td class="num">{{ ev.participants_count ?? 0 }}</td>
              <td v-if="canWrite" @click.stop>
                <button class="mini" @click="openEdit(ev)">Edit</button>
                <button class="mini" v-if="ev.participant_sheet_url" @click="syncParticipants(ev)" :disabled="syncing">Sync</button>
                <button class="mini danger" @click="remove(ev)">Hapus</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Peserta event terpilih -->
      <div class="table-wrap">
        <div class="part-head">
          <span>{{ selected ? `Peserta — ${selected.name}` : 'Pilih event untuk lihat peserta' }}</span>
          <span v-if="selected && selected.participants_synced_at" class="sync-at">sinkron: {{ selected.participants_synced_at }}</span>
        </div>
        <table class="po-table">
          <thead>
            <tr><th>Nama</th><th>No. HP</th><th v-for="k in partKeys" :key="k">{{ k }}</th></tr>
          </thead>
          <tbody>
            <tr v-if="partLoading"><td :colspan="2 + partKeys.length" class="empty">Memuat…</td></tr>
            <tr v-else-if="!selected"><td :colspan="2 + partKeys.length" class="empty">—</td></tr>
            <tr v-else-if="!participants.length"><td :colspan="2 + partKeys.length" class="empty">Belum ada peserta. {{ selected.participant_sheet_url ? 'Klik Sync untuk tarik dari Sheet.' : 'Event belum punya URL Sheet peserta.' }}</td></tr>
            <tr v-for="p in participants" :key="p.id">
              <td class="strong">{{ p.name || '-' }}</td>
              <td>{{ p.phone || '-' }}</td>
              <td v-for="k in partKeys" :key="k">{{ (p.payload && p.payload[k]) || '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="modal.open" class="modal-overlay" @click.self="closeModal">
      <div class="modal">
        <h3>{{ modal.editingId ? 'Edit Event' : 'Tambah Event' }}</h3>
        <div class="form-grid">
          <label class="full">Nama Kegiatan *
            <input type="text" v-model="form.name" />
          </label>
          <label>Tanggal
            <input type="date" v-model="form.event_date" />
          </label>
          <label>Lokasi
            <input type="text" v-model="form.location" />
          </label>
          <label class="full">Deskripsi
            <textarea v-model="form.description" rows="2"></textarea>
          </label>
          <label class="full">URL Google Sheet Peserta (anyone-with-link → Viewer)
            <input type="url" v-model="form.participant_sheet_url" placeholder="https://docs.google.com/spreadsheets/d/…" />
          </label>
          <label>GID Tab (opsional)
            <input type="text" v-model="form.participant_gid" placeholder="0" />
          </label>
          <label class="chk-line">
            <input type="checkbox" v-model="form.is_active" /> Aktif
          </label>
        </div>
        <div class="modal-actions">
          <button class="btn-soft" @click="closeModal">Batal</button>
          <button class="btn-soft accent" @click="submit" :disabled="modal.submitting">{{ modal.submitting ? 'Menyimpan…' : 'Simpan' }}</button>
        </div>
      </div>
    </div>

    <transition name="toast">
      <div v-if="msg" class="toast" :class="msgType">{{ msg }}</div>
    </transition>
  </div>
</template>

<style scoped>
.bar { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
.title { font-size: 1rem; font-weight: 700; color: var(--gd); margin: 0; }
.spacer { flex: 1; }
.count { font-size: 0.82rem; color: var(--tu); font-weight: 600; }
.btn-soft { padding: 7px 14px; border: 1px solid var(--gb); border-radius: 9px; background: var(--bc); font-size: 0.85rem; font-weight: 600; color: var(--tm); cursor: pointer; }
.btn-soft.accent { background: var(--gd); border-color: var(--gd); color: #fff; }
.btn-soft:disabled { opacity: .55; cursor: not-allowed; }

.split { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 900px) { .split { grid-template-columns: 1fr; } }

.table-wrap { border: 1px solid var(--gb); border-radius: 14px; overflow: auto; background: var(--bc); }
.part-head { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: var(--bs); border-bottom: 1px solid var(--gb); font-size: 0.82rem; font-weight: 700; color: var(--gd); }
.sync-at { font-size: 0.72rem; color: var(--tu); font-weight: 500; }
.po-table { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
.po-table thead th { background: var(--bs); text-align: left; padding: 10px 12px; font-weight: 700; color: var(--gd); border-bottom: 1px solid var(--gb); font-size: 0.76rem; white-space: nowrap; }
.po-table tbody td { padding: 9px 12px; border-bottom: 1px solid var(--bi); color: var(--td); vertical-align: top; }
.po-table tbody tr { cursor: pointer; }
.po-table tbody tr.sel { background: var(--gl); }
.po-table tbody tr:hover { background: var(--gl); }
.po-table .num { text-align: right; font-variant-numeric: tabular-nums; }
.po-table thead th.num { text-align: right; }
.po-table .strong { font-weight: 600; }
.link-tag { display: block; font-size: 0.72rem; color: var(--tu); font-weight: 500; }
.empty { text-align: center; color: var(--th); padding: 28px 12px; }

.mini { padding: 3px 9px; border: 1px solid var(--gb); border-radius: 7px; background: var(--bc); font-size: 0.76rem; font-weight: 600; color: var(--gd); cursor: pointer; margin-right: 5px; }
.mini.danger { color: #b91c1c; }
.mini:disabled { opacity: .5; cursor: not-allowed; }
.mini:hover:not(:disabled) { background: var(--bs); }

.modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.45); display: flex; align-items: center; justify-content: center; z-index: 200; padding: 20px; }
.modal { background: var(--bc); border-radius: 16px; padding: 22px; width: 100%; max-width: 600px; max-height: 90vh; overflow: auto; box-shadow: 0 20px 50px rgba(15,23,42,.25); }
.modal h3 { font-size: 1.05rem; font-weight: 700; color: var(--gd); margin: 0 0 16px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-grid label { display: flex; flex-direction: column; gap: 4px; font-size: 0.8rem; font-weight: 600; color: var(--tm); }
.form-grid label.full { grid-column: 1 / -1; }
.form-grid input, .form-grid textarea { border: 1px solid var(--gb); border-radius: 8px; padding: 7px 10px; font-size: 0.85rem; color: var(--td); background: var(--bc); font-family: inherit; }
.chk-line { flex-direction: row !important; align-items: center; gap: 7px; }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }

.toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); padding: 11px 20px; border-radius: 9px; color: #fff; font-size: 0.88rem; font-weight: 600; box-shadow: 0 8px 24px rgba(15,23,42,.2); z-index: 300; }
.toast.s { background: var(--st); }
.toast.e { background: var(--et); }
.toast-enter-active, .toast-leave-active { transition: opacity .25s, transform .25s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translate(-50%, 10px); }
</style>
