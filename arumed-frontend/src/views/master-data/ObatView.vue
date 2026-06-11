<script setup>
import { ref, reactive } from 'vue'
import CrudResourceView from './_CrudResourceView.vue'
import { integrasiApi } from '@/services/api'

const FORM_SEDIAAN = [
  { value: 'TABLET',     label: 'Tablet' },
  { value: 'KAPSUL',     label: 'Kapsul' },
  { value: 'SIRUP',      label: 'Sirup' },
  { value: 'TETES_MATA', label: 'Tetes Mata' },
  { value: 'SALEP_MATA', label: 'Salep Mata' },
  { value: 'INJEKSI',    label: 'Injeksi' },
  { value: 'LAIN',       label: 'Lain' },
]

const GOLONGAN = [
  { value: 'BEBAS',          label: 'Bebas' },
  { value: 'BEBAS_TERBATAS', label: 'Bebas Terbatas' },
  { value: 'KERAS',          label: 'Keras' },
  { value: 'NARKOTIKA',      label: 'Narkotika' },
  { value: 'PSIKOTROPIKA',   label: 'Psikotropika' },
]

const config = {
  resourceKey: 'obat',
  title: 'Obat',
  description: 'Master daftar obat — formularium, golongan BPOM, form sediaan. Harga & pos kwitansi (Obat Tindakan/Pulang/Injeksi) diatur di Buku Tarif, bukan di sini.',
  searchPlaceholder: 'Cari kode/nama/generic/komposisi/pabrik…',
  extraSearchParam: 'search',
  csvShowTemplate: true,
  csvAllowExcel: true,
  defaults: {
    name: '', kfa_code: '', generic_name: '', composition: '', manufacturer: '',
    formularium: 'FORNAS', form_sediaan: '', golongan: '',
    unit_besar: '', unit_kecil: '', konversi: null,
    min_stock: 0,
    expiry_date: '', batch_number: '', description: '',
    is_active: true,
  },
  columns: [
    { key: 'name',         label: 'Nama Obat' },
    { key: 'kfa_code',     label: 'KFA',          width: '100px' },
    { key: 'form_sediaan', label: 'Sediaan',      width: '110px' },
    { key: 'golongan',     label: 'Golongan',     width: '110px' },
    { key: 'formularium',  label: 'Formularium',  width: '120px' },
    { key: '_satuan',      label: 'Satuan',       width: '160px' },
    { key: 'is_active',    label: 'Status',       width: '90px',  align: 'center' },
  ],
  fields: [
    { key: 'name',         label: 'Nama Obat',           type: 'text',     required: true, cols: 1 },
    { key: 'kfa_code',     label: 'Kode KFA (Satu Sehat)', type: 'text',   cols: 1, placeholder: 'kosong jika belum tahu', hint: 'Untuk bridging Satu Sehat. Klik "Cari KFA" untuk mencari otomatis.', action: { label: 'Cari KFA' } },
    { key: 'generic_name', label: 'Nama Generik',        type: 'text',     cols: 1 },
    { key: 'manufacturer', label: 'Pabrik',              type: 'text',     cols: 1 },
    { key: 'composition',  label: 'Komposisi / Kandungan', type: 'textarea', cols: 2, rows: 2, placeholder: 'mis. Tobramycin 0.3% + Dexamethasone 0.1%' },
    { key: 'formularium',  label: 'Formularium',         type: 'select',   required: true, cols: 1, options: [
      { value: 'FORNAS',              label: 'FORNAS' },
      { value: 'NON-FORNAS',          label: 'NON-FORNAS' },
      { value: 'FORMULARIUM GENERIK', label: 'FORMULARIUM GENERIK' },
      { value: 'BRANDED',             label: 'BRANDED' },
    ]},
    { key: 'form_sediaan', label: 'Form Sediaan',        type: 'select',   cols: 1, options: [{ value: '', label: '—' }, ...FORM_SEDIAAN] },
    { key: 'golongan',     label: 'Golongan BPOM',       type: 'select',   cols: 1, options: [{ value: '', label: '—' }, ...GOLONGAN] },
    { key: 'unit_besar',   label: 'Satuan Besar',        type: 'text',     cols: 1, placeholder: 'box / dus / botol' },
    { key: 'unit_kecil',   label: 'Satuan Kecil',        type: 'text',     cols: 1, placeholder: 'tablet / kapsul / ml' },
    { key: 'konversi',     label: 'Konversi',            type: 'number',   min: 1, cols: 1, placeholder: '100', hint: '1 satuan besar = ? satuan kecil' },
    { key: 'min_stock',    label: 'Stok Minimum',        type: 'number',   min: 0, cols: 1, hint: 'Threshold alert stok rendah' },
    { key: 'expiry_date',  label: 'Tanggal Kadaluwarsa', type: 'date',     cols: 1 },
    { key: 'batch_number', label: 'No. Batch',           type: 'text',     cols: 1 },
    { key: 'description',  label: 'Deskripsi/Catatan',   type: 'textarea', cols: 2, rows: 2 },
    { key: 'is_active',    label: 'Aktif',               type: 'checkbox', cols: 1 },
  ],
  editFields: null,
  deleteLabel: (r) => `${r?.code} · ${r?.name}`,
}

config.editFields = config.fields

const filters = ref({ golongan: '', form_sediaan: '', active: '', low_stock: false })

function applyFilter(updateFn, key, value) {
  filters.value[key] = value
  updateFn(key, value === '' || value === false ? null : value)
}

function toggleLowStock(updateFn) {
  filters.value.low_stock = !filters.value.low_stock
  updateFn('low_stock', filters.value.low_stock ? 1 : null)
}

function labelForm(v) { return FORM_SEDIAAN.find((f) => f.value === v)?.label ?? v }
function labelGolongan(v) { return GOLONGAN.find((g) => g.value === v)?.label ?? v }

// ── Cari KFA (Satu Sehat) ────────────────────────────────────────────────────
const crv = ref(null) // ref ke CrudResourceView (untuk setModalField)
const kfa = reactive({ open: false, q: '', loading: false, rows: [], error: '' })

function openKfa({ form }) {
  kfa.open = true
  kfa.error = ''
  kfa.rows = []
  // Prefill keyword dari nama obat yang sedang diisi.
  kfa.q = form?.value?.name || ''
  if (kfa.q) searchKfa()
}

async function searchKfa() {
  if (!kfa.q.trim()) return
  kfa.loading = true; kfa.error = ''; kfa.rows = []
  try {
    const res = await integrasiApi.satusehatKfaSearch({ keyword: kfa.q.trim() })
    const d = res.data?.data ?? {}
    if (!d.success) { kfa.error = '⚠ Integrasi Satu Sehat belum aktif atau pencarian gagal.'; return }
    kfa.rows = d.items ?? []
    if (!kfa.rows.length) kfa.error = 'Tidak ada hasil untuk kata kunci tersebut.'
  } catch (e) {
    kfa.error = (e.response?.status === 503 ? '⚠ Integrasi Satu Sehat belum aktif. ' : '') + (e.response?.data?.message ?? 'Pencarian KFA gagal.')
  } finally {
    kfa.loading = false
  }
}

function pickKfa(item) {
  crv.value?.setModalField('kfa_code', item.kfa_code)
  kfa.open = false
}
</script>

<template>
  <CrudResourceView ref="crv" :config="config" @field-action="openKfa">
    <template #filters="slotProps">
      <span class="crv-filter-label">Golongan:</span>
      <select class="crv-filter-select" v-model="filters.golongan" @change="slotProps.updateFilter('golongan', filters.golongan || null)">
        <option value="">Semua</option>
        <option v-for="g in GOLONGAN" :key="g.value" :value="g.value">{{ g.label }}</option>
      </select>

      <span class="crv-filter-label" style="margin-left:.6rem">Sediaan:</span>
      <select class="crv-filter-select" v-model="filters.form_sediaan" @change="slotProps.updateFilter('form_sediaan', filters.form_sediaan || null)">
        <option value="">Semua</option>
        <option v-for="f in FORM_SEDIAAN" :key="f.value" :value="f.value">{{ f.label }}</option>
      </select>

      <span class="crv-filter-label" style="margin-left:.6rem">Status:</span>
      <button class="crv-chip" :class="{ active: filters.active === '' }" @click="applyFilter(slotProps.updateFilter, 'active', '')">Semua</button>
      <button class="crv-chip" :class="{ active: filters.active === 1 }" @click="applyFilter(slotProps.updateFilter, 'active', 1)">Aktif</button>
      <button class="crv-chip" :class="{ active: filters.active === 0 }" @click="applyFilter(slotProps.updateFilter, 'active', 0)">Nonaktif</button>

      <button class="crv-chip" :class="{ active: filters.low_stock }" style="margin-left:.6rem" @click="toggleLowStock(slotProps.updateFilter)" title="Stok minimum">
        Stok Rendah
      </button>
    </template>

    <template #cell-form_sediaan="{ value }">
      <span v-if="value" class="cell-tag">{{ labelForm(value) }}</span>
      <span v-else>—</span>
    </template>
    <template #cell-golongan="{ value }">
      <span v-if="value" class="cell-tag" :data-gol="value">{{ labelGolongan(value) }}</span>
      <span v-else>—</span>
    </template>
    <template #cell-_satuan="{ row }">
      <span v-if="row.unit_kecil || row.unit_besar" class="cell-satuan">
        <strong>{{ row.unit_kecil || '—' }}</strong>
        <span v-if="row.unit_besar" class="cell-satuan-sub">
          / {{ row.unit_besar }}<span v-if="row.konversi"> ({{ row.konversi }})</span>
        </span>
      </span>
      <span v-else>—</span>
    </template>
    <template #cell-kfa_code="{ value }">
      <code v-if="value" class="cell-kfa">{{ value }}</code>
      <span v-else class="cell-kfa-empty">—</span>
    </template>
  </CrudResourceView>

  <!-- Modal Cari KFA (Satu Sehat) -->
  <Teleport to="body">
    <div v-if="kfa.open" class="kfa-overlay" @click.self="kfa.open = false">
      <div class="kfa-box">
        <div class="kfa-head">
          <span>Cari Kode KFA — Satu Sehat</span>
          <button class="kfa-close" @click="kfa.open = false">✕</button>
        </div>
        <div class="kfa-body">
          <div class="kfa-search">
            <input v-model="kfa.q" placeholder="nama obat / nama dagang…" @keyup.enter="searchKfa" autofocus />
            <button class="kfa-btn primary" :disabled="kfa.loading" @click="searchKfa">{{ kfa.loading ? '…' : 'Cari' }}</button>
          </div>
          <p v-if="kfa.error" class="kfa-error">{{ kfa.error }}</p>
          <table v-if="kfa.rows.length" class="kfa-tbl">
            <thead><tr><th>Kode KFA</th><th>Nama</th><th>Sediaan</th><th></th></tr></thead>
            <tbody>
              <tr v-for="(it, i) in kfa.rows" :key="i">
                <td><code>{{ it.kfa_code }}</code></td>
                <td>
                  <div class="kfa-name">{{ it.nama_dagang || it.name }}</div>
                  <div v-if="it.nama_dagang" class="kfa-sub">{{ it.name }}</div>
                </td>
                <td>{{ it.dosage_form || '—' }}</td>
                <td><button class="kfa-btn" @click="pickKfa(it)">Pilih</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.cell-tag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 500; background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.cell-tag[data-gol="NARKOTIKA"]    { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.cell-tag[data-gol="PSIKOTROPIKA"] { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
.cell-tag[data-gol="KERAS"]        { background: var(--pb); color: var(--pt); border-color: var(--pbd); }
.cell-tag[data-gol="BEBAS_TERBATAS"] { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.cell-tag[data-gol="BEBAS"]        { background: var(--sb); color: var(--st); border-color: var(--sbd); }

.cell-satuan { font-size: 12px; color: var(--td); }
.cell-satuan strong { font-weight: 500; }
.cell-satuan-sub { color: var(--tu); font-size: 11px; margin-left: 2px; }

.cell-kfa { font-family: 'JetBrains Mono', monospace; font-size: 11px; background: var(--sb); color: var(--st); padding: 2px 6px; border-radius: 5px; }
.cell-kfa-empty { color: var(--tu); }

/* Modal Cari KFA */
/* z-index HARUS di atas MasterFormModal (.mfm-overlay z-index:9000) karena modal
   KFA dibuka DARI ATAS form modal — kalau lebih rendah, KFA muncul di belakang. */
.kfa-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.45); display: flex; align-items: center; justify-content: center; z-index: 9500; padding: 1rem; }
.kfa-box { background: #fff; border-radius: 14px; width: 640px; max-width: 100%; max-height: 86vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 50px rgba(15,23,42,0.25); }
.kfa-head { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid var(--gb); font-weight: 600; color: var(--td); font-size: 15px; }
.kfa-close { border: none; background: transparent; font-size: 16px; cursor: pointer; color: var(--tm); }
.kfa-body { padding: 16px 18px; overflow-y: auto; }
.kfa-search { display: flex; gap: 0.5rem; margin-bottom: 0.8rem; }
.kfa-search input { flex: 1; padding: 7px 10px; border: 1px solid var(--gb); border-radius: 7px; font-size: 13px; color: #000; }
.kfa-search input:focus { outline: none; border-color: #1763d4; }
.kfa-error { font-size: 12.5px; color: #92400e; margin: 0 0 0.6rem; }
.kfa-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.kfa-tbl th { text-align: left; padding: 7px 8px; background: var(--bs); color: var(--tm); font-size: 11px; text-transform: uppercase; }
.kfa-tbl td { padding: 7px 8px; border-top: 1px solid var(--gb); color: #000; vertical-align: top; }
.kfa-tbl code { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: #1763d4; }
.kfa-name { font-weight: 500; }
.kfa-sub { font-size: 11px; color: var(--tm); margin-top: 2px; }
.kfa-btn { padding: 5px 11px; border: 1px solid var(--gb); border-radius: 6px; font-size: 12px; font-weight: 600; background: #fff; color: #000; cursor: pointer; }
.kfa-btn:disabled { opacity: 0.55; cursor: not-allowed; }
.kfa-btn.primary { background: #1763d4; color: #fff; border-color: #1763d4; }
</style>
