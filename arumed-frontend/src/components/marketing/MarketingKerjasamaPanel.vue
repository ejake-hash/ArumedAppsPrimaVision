<script setup>
/**
 * Monitoring Kerjasama (#4) — CRUD PKS asuransi/perusahaan (tgl mulai, adendum, akhir).
 * Status AKTIF/AKAN_BERAKHIR/BERAKHIR dihitung backend dari pks_end_date.
 * Aksi tulis hanya tampil bila auth.can('marketing.write').
 */
import { ref, computed, onMounted } from 'vue'
import { marketingApi, masterApi } from '@/services/api'
import { useAuthStore } from '@/stores/authStore'

const auth = useAuthStore()
const canWrite = computed(() => auth.can('marketing.write'))

const TYPES = ['ASURANSI', 'PERUSAHAAN', 'TPA', 'LAINNYA']
const STATUS_BADGE = {
  AKTIF:         { label: 'Aktif',          cls: 'b-ok' },
  AKAN_BERAKHIR: { label: 'Akan berakhir',  cls: 'b-warn' },
  BERAKHIR:      { label: 'Berakhir',       cls: 'b-bad' },
  TANPA_AKHIR:   { label: 'Tanpa akhir',    cls: 'b-mute' },
}

const rows = ref([])
const loading = ref(false)
const search = ref('')
const penjaminList = ref([])

const msg = ref('')
const msgType = ref('s')
let msgTimer = null
function toast(type, m) { msgType.value = type; msg.value = m; clearTimeout(msgTimer); msgTimer = setTimeout(() => (msg.value = ''), 3500) }

async function load() {
  loading.value = true
  try {
    const res = await marketingApi.kerjasama.list({ search: search.value, per_page: 200 })
    const data = res.data?.data
    rows.value = data?.data ?? (Array.isArray(data) ? data : [])
  } catch {
    rows.value = []
  } finally {
    loading.value = false
  }
}

async function loadPenjamin() {
  try {
    const { data } = await masterApi.penjamin.list({ per_page: 200 })
    const r = data.data?.data ?? data.data ?? []
    penjaminList.value = Array.isArray(r) ? r : (r.data ?? [])
  } catch { penjaminList.value = [] }
}

// ─── Modal ───
const modal = ref({ open: false, editingId: null, submitting: false })
const blank = () => ({
  insurer_id: '', partner_name: '', partner_type: 'ASURANSI', pks_number: '',
  pks_start_date: '', addendum_date: '', pks_end_date: '', pic_name: '', pic_phone: '', notes: '', is_active: true,
})
const form = ref(blank())

function openCreate() { modal.value = { open: true, editingId: null, submitting: false }; form.value = blank() }
function openEdit(row) {
  modal.value = { open: true, editingId: row.id, submitting: false }
  form.value = {
    insurer_id: row.insurer_id || '', partner_name: row.partner_name || '', partner_type: row.partner_type || 'ASURANSI',
    pks_number: row.pks_number || '', pks_start_date: row.pks_start_date || '', addendum_date: row.addendum_date || '',
    pks_end_date: row.pks_end_date || '', pic_name: row.pic_name || '', pic_phone: row.pic_phone || '',
    notes: row.notes || '', is_active: row.is_active !== false,
  }
}
function closeModal() { modal.value.open = false }

// Saat pilih penjamin dari master, isi otomatis nama bila kosong.
function onPenjaminChange() {
  const p = penjaminList.value.find(x => x.id === form.value.insurer_id)
  if (p && !form.value.partner_name) form.value.partner_name = p.name
}

async function submit() {
  if (!form.value.partner_name.trim()) { toast('e', 'Nama mitra wajib diisi.'); return }
  modal.value.submitting = true
  try {
    const body = { ...form.value, insurer_id: form.value.insurer_id || null }
    if (modal.value.editingId) await marketingApi.kerjasama.update(modal.value.editingId, body)
    else await marketingApi.kerjasama.create(body)
    toast('s', 'Tersimpan.')
    closeModal()
    await load()
  } catch (e) {
    toast('e', e?.response?.data?.message || 'Gagal menyimpan.')
  } finally {
    modal.value.submitting = false
  }
}

async function remove(row) {
  if (!confirm(`Hapus kerjasama "${row.partner_name}"?`)) return
  try {
    await marketingApi.kerjasama.remove(row.id)
    toast('s', 'Dihapus.')
    await load()
  } catch (e) {
    toast('e', e?.response?.data?.message || 'Gagal menghapus.')
  }
}

onMounted(() => { load(); loadPenjamin() })
</script>

<template>
  <div>
    <div class="bar">
      <div class="search-box">
        <input type="search" v-model="search" placeholder="Cari mitra / no. PKS…" @keyup.enter="load" />
      </div>
      <button class="btn-soft" @click="load" :disabled="loading">Cari</button>
      <span class="count">{{ rows.length }} kerjasama</span>
      <span class="spacer"></span>
      <button v-if="canWrite" class="btn-soft accent" @click="openCreate">+ Tambah Kerjasama</button>
    </div>

    <div class="table-wrap">
      <table class="po-table">
        <thead>
          <tr>
            <th>Mitra</th><th>Jenis</th><th>No. PKS</th><th>Mulai</th><th>Adendum</th><th>Akhir</th><th>Status</th>
            <th v-if="canWrite" style="width:120px">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading"><td :colspan="canWrite ? 8 : 7" class="empty">Memuat…</td></tr>
          <tr v-else-if="!rows.length"><td :colspan="canWrite ? 8 : 7" class="empty">Belum ada data kerjasama.</td></tr>
          <tr v-for="r in rows" :key="r.id">
            <td class="strong">{{ r.partner_name }}<span v-if="r.insurer" class="link-tag">↳ {{ r.insurer.name }}</span></td>
            <td>{{ r.partner_type }}</td>
            <td>{{ r.pks_number || '-' }}</td>
            <td>{{ r.pks_start_date || '-' }}</td>
            <td>{{ r.addendum_date || '-' }}</td>
            <td>{{ r.pks_end_date || '-' }}</td>
            <td><span class="badge" :class="(STATUS_BADGE[r.status] || {}).cls">{{ (STATUS_BADGE[r.status] || {}).label || r.status }}</span></td>
            <td v-if="canWrite">
              <button class="mini" @click="openEdit(r)">Edit</button>
              <button class="mini danger" @click="remove(r)">Hapus</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal add/edit -->
    <div v-if="modal.open" class="modal-overlay" @click.self="closeModal">
      <div class="modal">
        <h3>{{ modal.editingId ? 'Edit Kerjasama' : 'Tambah Kerjasama' }}</h3>
        <div class="form-grid">
          <label class="full">Tautkan ke Master Asuransi/TPA (opsional)
            <select v-model="form.insurer_id" @change="onPenjaminChange">
              <option value="">— Tidak ditautkan —</option>
              <option v-for="p in penjaminList" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </label>
          <label class="full">Nama Mitra *
            <input type="text" v-model="form.partner_name" placeholder="Nama asuransi / perusahaan" />
          </label>
          <label>Jenis
            <select v-model="form.partner_type">
              <option v-for="t in TYPES" :key="t" :value="t">{{ t }}</option>
            </select>
          </label>
          <label>No. PKS
            <input type="text" v-model="form.pks_number" />
          </label>
          <label>Tgl Mulai PKS
            <input type="date" v-model="form.pks_start_date" />
          </label>
          <label>Tgl Adendum
            <input type="date" v-model="form.addendum_date" />
          </label>
          <label>Tgl Akhir Kerjasama
            <input type="date" v-model="form.pks_end_date" />
          </label>
          <label>PIC
            <input type="text" v-model="form.pic_name" />
          </label>
          <label>No. HP PIC
            <input type="text" v-model="form.pic_phone" />
          </label>
          <label class="full">Catatan
            <textarea v-model="form.notes" rows="2"></textarea>
          </label>
          <label class="chk-line full">
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
.spacer { flex: 1; }
.search-box input { border: 1px solid var(--gb); border-radius: 8px; padding: 7px 12px; font-size: 0.85rem; min-width: 240px; background: var(--bc); color: var(--td); }
.count { font-size: 0.82rem; color: var(--tu); font-weight: 600; }
.btn-soft { padding: 7px 14px; border: 1px solid var(--gb); border-radius: 9px; background: var(--bc); font-size: 0.85rem; font-weight: 600; color: var(--tm); cursor: pointer; }
.btn-soft.accent { background: var(--gd); border-color: var(--gd); color: #fff; }
.btn-soft:disabled { opacity: .55; cursor: not-allowed; }

.table-wrap { border: 1px solid var(--gb); border-radius: 14px; overflow: auto; background: var(--bc); }
.po-table { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
.po-table thead th { background: var(--bs); text-align: left; padding: 10px 12px; font-weight: 700; color: var(--gd); border-bottom: 1px solid var(--gb); font-size: 0.76rem; white-space: nowrap; }
.po-table tbody td { padding: 9px 12px; border-bottom: 1px solid var(--bi); color: var(--td); vertical-align: top; }
.po-table .strong { font-weight: 600; }
.link-tag { display: block; font-size: 0.72rem; color: var(--tu); font-weight: 500; }
.empty { text-align: center; color: var(--th); padding: 28px 12px; }

.badge { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
.b-ok { background: #dcfce7; color: #166534; }
.b-warn { background: #ffedd5; color: #c2410c; }
.b-bad { background: #fee2e2; color: #b91c1c; }
.b-mute { background: var(--bs); color: var(--tu); }

.mini { padding: 3px 9px; border: 1px solid var(--gb); border-radius: 7px; background: var(--bc); font-size: 0.76rem; font-weight: 600; color: var(--gd); cursor: pointer; margin-right: 5px; }
.mini.danger { color: #b91c1c; }
.mini:hover { background: var(--bs); }

.modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.45); display: flex; align-items: center; justify-content: center; z-index: 200; padding: 20px; }
.modal { background: var(--bc); border-radius: 16px; padding: 22px; width: 100%; max-width: 620px; max-height: 90vh; overflow: auto; box-shadow: 0 20px 50px rgba(15,23,42,.25); }
.modal h3 { font-size: 1.05rem; font-weight: 700; color: var(--gd); margin: 0 0 16px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-grid label { display: flex; flex-direction: column; gap: 4px; font-size: 0.8rem; font-weight: 600; color: var(--tm); }
.form-grid label.full { grid-column: 1 / -1; }
.form-grid input, .form-grid select, .form-grid textarea { border: 1px solid var(--gb); border-radius: 8px; padding: 7px 10px; font-size: 0.85rem; color: var(--td); background: var(--bc); font-family: inherit; }
.chk-line { flex-direction: row !important; align-items: center; gap: 7px; }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }

.toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); padding: 11px 20px; border-radius: 9px; color: #fff; font-size: 0.88rem; font-weight: 600; box-shadow: 0 8px 24px rgba(15,23,42,.2); z-index: 300; }
.toast.s { background: var(--st); }
.toast.e { background: var(--et); }
.toast-enter-active, .toast-leave-active { transition: opacity .25s, transform .25s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translate(-50%, 10px); }
</style>
