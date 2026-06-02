<script setup>
/**
 * AlatMedisView — master Alat Medis (microscope, Phaco machine, biometri, dll).
 *
 * 2 fungsi utama:
 *  1. CRUD master via CrudResourceView (pakai resourceKey='alatMedis').
 *  2. Modal "Atur Tarif" per item — tarif flat per pemakaian per insurer
 *     (UMUM / BPJS / ASURANSI / PERUSAHAAN / SOSIAL). Tarif 0 atau is_active=false
 *     otomatis di-skip di billing → cocok untuk BPJS yg sudah include INA-CBGs.
 */
import { ref } from 'vue'
import CrudResourceView from '../master-data/_CrudResourceView.vue'
import { alatMedisApi } from '@/services/api'

const CATEGORIES = [
  { value: 'MICROSCOPE',    label: 'Microscope' },
  { value: 'PHACO_MACHINE', label: 'Phaco Machine' },
  { value: 'BIOMETRY',      label: 'Biometri' },
  { value: 'AUTOREFRACTOR', label: 'Autorefractor' },
  { value: 'LAINNYA',       label: 'Lainnya' },
]
const STATUSES = [
  { value: 'ACTIVE',      label: 'Aktif' },
  { value: 'MAINTENANCE', label: 'Maintenance' },
  { value: 'RETIRED',     label: 'Retired' },
]
const CLASSIFICATIONS = ['UMUM', 'BPJS', 'ASURANSI', 'PERUSAHAAN', 'SOSIAL']

const config = {
  resourceKey: 'alatMedis',
  title: 'Alat Medis',
  description: 'Master alat medis reusable (microscope, mesin Phaco, biometri, dll). Tarif flat per pemakaian.',
  searchPlaceholder: 'Cari kode/nama/brand/serial…',
  extraSearchParam: 'search',
  csvAllowExcel: true,
  defaults: {
    name: '', category: 'MICROSCOPE', brand: '', model: '', serial_number: '',
    location: '', status: 'ACTIVE', calibration_due_at: '', purchase_date: '',
    description: '', is_active: true,
  },
  columns: [
    { key: 'code',     label: 'Kode',  width: '110px' },
    { key: 'name',     label: 'Nama' },
    { key: 'category', label: 'Kategori', width: '140px' },
    { key: 'brand',    label: 'Brand',    width: '120px' },
    { key: 'location', label: 'Lokasi',   width: '110px' },
    { key: 'status',   label: 'Status',   width: '120px' },
    { key: '_action',  label: 'Tarif',    width: '110px', align: 'center' },
  ],
  fields: [
    { key: 'name',               label: 'Nama Alat',          type: 'text',   required: true, cols: 2 },
    { key: 'category',           label: 'Kategori',           type: 'select', required: true, cols: 1, options: CATEGORIES },
    { key: 'status',             label: 'Status',             type: 'select', cols: 1, options: STATUSES },
    { key: 'brand',              label: 'Brand',              type: 'text',   cols: 1 },
    { key: 'model',              label: 'Model',              type: 'text',   cols: 1 },
    { key: 'serial_number',      label: 'Serial Number',      type: 'text',   cols: 1 },
    { key: 'location',           label: 'Lokasi',             type: 'text',   cols: 1, placeholder: 'OK-1 / Poli-2' },
    { key: 'calibration_due_at', label: 'Kalibrasi Berikutnya', type: 'date', cols: 1 },
    { key: 'purchase_date',      label: 'Tanggal Pembelian',  type: 'date',   cols: 1 },
    { key: 'description',        label: 'Catatan',            type: 'textarea', cols: 2, rows: 2 },
    { key: 'is_active',          label: 'Aktif',              type: 'checkbox', cols: 1 },
  ],
  editFields: null,
  deleteLabel: (r) => `${r?.code} · ${r?.name}`,
}
config.editFields = config.fields

const filters = ref({ category: '', status: '', active: '' })
function applyFilter(updateFn, key, value) {
  filters.value[key] = value
  updateFn(key, value === '' ? null : value)
}

const labelCat = (v) => CATEGORIES.find((c) => c.value === v)?.label ?? v
const labelStat = (v) => STATUSES.find((s) => s.value === v)?.label ?? v

// ── Modal Tarif ──────────────────────────────────────────────────────────
const tarifOpen = ref(false)
const tarifItem = ref(null)  // current alat medis
const tarifList = ref([])
const tarifLoading = ref(false)
const tarifForm = ref({ classification: 'UMUM', insurer_id: null, price: 0, is_active: true })
const toast = ref(null)
function showToast(type, msg) {
  toast.value = { type, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

async function openTarif(row) {
  tarifItem.value = row
  tarifOpen.value = true
  await loadTarifs()
}
function closeTarif() {
  tarifOpen.value = false
  tarifItem.value = null
  tarifList.value = []
}

async function loadTarifs() {
  if (!tarifItem.value?.id) return
  tarifLoading.value = true
  try {
    const res = await alatMedisApi.listTariffs(tarifItem.value.id)
    tarifList.value = Array.isArray(res.data?.data) ? res.data.data : []
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal memuat tarif')
  } finally {
    tarifLoading.value = false
  }
}

async function saveTarif() {
  if (!tarifItem.value?.id) return
  try {
    await alatMedisApi.upsertTariff(tarifItem.value.id, {
      classification: tarifForm.value.classification,
      insurer_id: tarifForm.value.insurer_id || null,
      price: Number(tarifForm.value.price) || 0,
      is_active: tarifForm.value.is_active,
    })
    showToast('s', 'Tarif disimpan')
    tarifForm.value = { classification: 'UMUM', insurer_id: null, price: 0, is_active: true }
    await loadTarifs()
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menyimpan tarif')
  }
}

async function deleteTarif(t) {
  if (!confirm(`Hapus tarif ${t.classification}?`)) return
  try {
    await alatMedisApi.deleteTariff(t.id)
    showToast('s', 'Tarif dihapus')
    await loadTarifs()
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menghapus')
  }
}

function editTarif(t) {
  tarifForm.value = {
    classification: t.classification,
    insurer_id: t.insurer_id ?? null,
    price: Number(t.price) || 0,
    is_active: t.is_active,
  }
}
</script>

<template>
  <CrudResourceView :config="config">
    <template #filters="{ updateFilter }">
      <span class="crv-filter-label">Kategori:</span>
      <select class="crv-filter-select" v-model="filters.category" @change="applyFilter(updateFilter, 'category', filters.category || null)">
        <option value="">Semua</option>
        <option v-for="c in CATEGORIES" :key="c.value" :value="c.value">{{ c.label }}</option>
      </select>

      <span class="crv-filter-label" style="margin-left:.6rem">Status:</span>
      <select class="crv-filter-select" v-model="filters.status" @change="applyFilter(updateFilter, 'status', filters.status || null)">
        <option value="">Semua</option>
        <option v-for="s in STATUSES" :key="s.value" :value="s.value">{{ s.label }}</option>
      </select>

      <span class="crv-filter-label" style="margin-left:.6rem">Aktif:</span>
      <button class="crv-chip" :class="{ active: filters.active === '' }" @click="applyFilter(updateFilter, 'active', '')">Semua</button>
      <button class="crv-chip" :class="{ active: filters.active === 1 }" @click="applyFilter(updateFilter, 'active', 1)">Aktif</button>
      <button class="crv-chip" :class="{ active: filters.active === 0 }" @click="applyFilter(updateFilter, 'active', 0)">Nonaktif</button>
    </template>

    <template #cell-category="{ value }">
      <span v-if="value" class="cell-tag" :data-cat="value">{{ labelCat(value) }}</span>
      <span v-else>—</span>
    </template>
    <template #cell-status="{ value }">
      <span v-if="value" class="cell-status" :data-stat="value">{{ labelStat(value) }}</span>
      <span v-else>—</span>
    </template>
    <template #cell-_action="{ row }">
      <button class="btn-tarif" @click="openTarif(row)">Atur Tarif</button>
    </template>
  </CrudResourceView>

  <!-- Modal Tarif -->
  <Teleport to="body">
    <div v-if="tarifOpen" class="am-modal-backdrop" @click.self="closeTarif">
      <div class="am-modal">
        <header class="am-modal-hd">
          <div>
            <strong>Atur Tarif Pemakaian</strong>
            <div class="am-modal-sub">{{ tarifItem?.code }} · {{ tarifItem?.name }}</div>
          </div>
          <button class="am-modal-close" @click="closeTarif">✕</button>
        </header>

        <div class="am-modal-body">
          <p class="am-hint">
            Tarif flat per pemakaian per kelas penjamin. Set <code>Rp 0</code> atau nonaktif untuk insurer yg sudah include
            biaya alat di paket (mis. BPJS INA-CBGs).
          </p>

          <table class="am-tbl">
            <thead><tr><th>Klasifikasi</th><th class="num">Harga</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-if="tarifLoading"><td colspan="4" class="am-empty">Memuat…</td></tr>
              <tr v-else-if="!tarifList.length"><td colspan="4" class="am-empty">Belum ada tarif</td></tr>
              <tr v-for="t in tarifList" :key="t.id">
                <td>
                  <code>{{ t.classification }}</code>
                  <span v-if="t.insurer" class="am-insurer">· {{ t.insurer.name }}</span>
                </td>
                <td class="num">Rp {{ Number(t.price).toLocaleString('id-ID') }}</td>
                <td>
                  <span :class="['am-stat-pill', t.is_active ? 'on' : 'off']">
                    {{ t.is_active ? 'Aktif' : 'Nonaktif' }}
                  </span>
                </td>
                <td class="num">
                  <button class="am-edit" @click="editTarif(t)" title="Edit">✎</button>
                  <button class="am-del" @click="deleteTarif(t)" title="Hapus">✕</button>
                </td>
              </tr>
            </tbody>
          </table>

          <div class="am-form">
            <h4>Tambah / Update Tarif</h4>
            <div class="am-form-row">
              <label>
                <span>Klasifikasi</span>
                <select v-model="tarifForm.classification">
                  <option v-for="c in CLASSIFICATIONS" :key="c">{{ c }}</option>
                </select>
              </label>
              <label>
                <span>Harga (Rp)</span>
                <input type="number" min="0" step="1000" v-model.number="tarifForm.price" />
              </label>
              <label class="am-check">
                <input type="checkbox" v-model="tarifForm.is_active" />
                <span>Aktif</span>
              </label>
            </div>
            <button class="am-save" @click="saveTarif">Simpan Tarif</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="toast" class="am-toast" :class="`t-${toast.type}`">{{ toast.msg }}</div>
  </Teleport>
</template>

<style scoped>
.cell-tag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.cell-tag[data-cat="MICROSCOPE"]    { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
.cell-tag[data-cat="PHACO_MACHINE"] { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
.cell-tag[data-cat="BIOMETRY"]      { background: #ede9fe; color: #5b21b6; border-color: #c4b5fd; }
.cell-tag[data-cat="AUTOREFRACTOR"] { background: #d1fae5; color: #065f46; border-color: #6ee7b7; }

.cell-status { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 500; }
.cell-status[data-stat="ACTIVE"]      { background: #d1fae5; color: #065f46; }
.cell-status[data-stat="MAINTENANCE"] { background: #fef3c7; color: #92400e; }
.cell-status[data-stat="RETIRED"]     { background: var(--bs); color: var(--tu); }

.btn-tarif { padding: 4px 10px; font-size: 11.5px; border: 1px solid var(--ga); background: var(--bc); color: var(--ga); border-radius: 6px; cursor: pointer; }
.btn-tarif:hover { background: var(--gl); }

/* Modal */
.am-modal-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.4); z-index: 100; display: flex; align-items: center; justify-content: center; padding: 1rem; }
.am-modal { background: var(--bc); border-radius: 14px; max-width: 640px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(15,23,42,.3); overflow: hidden; }
.am-modal-hd { padding: 14px 18px; border-bottom: 1px solid var(--gb); display: flex; justify-content: space-between; align-items: flex-start; background: var(--bs); }
.am-modal-hd strong { font-size: 15px; color: var(--td); }
.am-modal-sub { font-size: 12px; color: var(--tm); margin-top: 2px; }
.am-modal-close { width: 28px; height: 28px; border-radius: 6px; border: none; background: transparent; color: var(--tm); cursor: pointer; font-size: 14px; }
.am-modal-close:hover { background: var(--gl); }
.am-modal-body { padding: 16px 18px; overflow-y: auto; flex: 1; }
.am-hint { font-size: 11.5px; color: var(--tu); margin: 0 0 10px; padding: 8px 10px; background: var(--bs); border-radius: 6px; border-left: 3px solid var(--ga); }
.am-hint code { background: var(--bc); padding: 1px 4px; border-radius: 3px; font-size: 11px; }

.am-tbl { width: 100%; border-collapse: collapse; font-size: 12.5px; margin-bottom: 16px; }
.am-tbl th, .am-tbl td { padding: 6px 8px; border-bottom: 1px solid var(--gb); text-align: left; }
.am-tbl th { background: var(--bs); font-size: 11px; color: var(--tm); font-weight: 600; }
.am-tbl .num { text-align: right; }
.am-empty { text-align: center; color: var(--tu); padding: 14px 0; }
.am-insurer { font-size: 10.5px; color: var(--tu); margin-left: 4px; }
.am-stat-pill { padding: 2px 7px; border-radius: 5px; font-size: 10.5px; font-weight: 600; }
.am-stat-pill.on  { background: #d1fae5; color: #065f46; }
.am-stat-pill.off { background: var(--bs); color: var(--tu); }
.am-edit, .am-del { width: 24px; height: 24px; border-radius: 5px; border: 1px solid var(--gb); background: var(--bc); cursor: pointer; font-size: 11px; }
.am-edit:hover { background: var(--gl); color: var(--ga); border-color: var(--ga); }
.am-del { color: #b91c1c; margin-left: 4px; }
.am-del:hover { background: #fee2e2; }

.am-form { background: var(--bs); border-radius: 8px; padding: 12px; }
.am-form h4 { font-size: 12.5px; color: var(--td); margin: 0 0 8px; }
.am-form-row { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; margin-bottom: 8px; }
.am-form label { display: flex; flex-direction: column; gap: 3px; font-size: 11.5px; color: var(--tm); flex: 1; min-width: 120px; }
.am-form label span { font-weight: 500; }
.am-form select, .am-form input[type=number] { padding: 5px 8px; font-size: 12.5px; border: 1px solid var(--gb); border-radius: 5px; background: var(--bc); }
.am-check { flex: 0 0 auto !important; flex-direction: row !important; align-items: center; gap: 6px !important; padding-bottom: 6px; }
.am-save { padding: 6px 14px; font-size: 12.5px; background: var(--ga); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500; }
.am-save:hover { filter: brightness(.95); }

.am-toast { position: fixed; bottom: 24px; right: 24px; padding: 10px 16px; border-radius: 8px; font-size: 13px; color: white; box-shadow: 0 6px 20px rgba(15,23,42,.2); z-index: 110; max-width: 320px; }
.am-toast.t-s { background: #15803d; }
.am-toast.t-e { background: #b91c1c; }
</style>
